<script setup>
import { saveReportWorkbook } from '@/services/reportWorkbookDownload'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { UnifiedReportQueryToolbar, UnifiedQueryDateRange, cloneUnifiedQuerySnapshot, formatReportMoney } from '@mohe/unified-query-vue3'
import OrganizationStoreScopePicker from '@/components/OrganizationStoreScopePicker.vue'
import TablePagination from '@/components/common/TablePagination.vue'
import { loadBasicReport, loadBasicReportScope, downloadBasicReportXlsx } from '@/services/basicReportApi'
import { useCashierV3State } from '@/services/cashierV3Bridge'

const props = defineProps({ platform: Boolean })
const emit = defineEmits(['modal-change'])
const code = 'basic_store_reconciliation'
const state = useCashierV3State()
const route = useRoute()
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
// 对账默认核对上一个完整营业日；按上海日历日计算，避免客户端时区及月初跨月造成偏差。
const yesterday = new Date(Date.parse(`${today}T00:00:00Z`) - 86400000).toISOString().slice(0, 10)
const dates = ref({ start_date: yesterday, end_date: yesterday })
const month = ref(yesterday.slice(0, 7))
const storeIds = ref([])
const storeScope = ref({ tree: [], allowed_store_ids: [] })
const label = ref('全部门店')
const data = ref({ rows: [], queryFields: [], pagination: { total: 0, page: 1 }, summaries: {} })
const executed = ref(null)
const loading = ref(false)
const exporting = ref(false)
const error = ref('')
const guide = ref(false)
const detail = ref(null)
const detailLoading = ref(false)
const detailError = ref('')
const detailExporting = ref(false)
const detailsScroll = ref(null)
const resultsScroll = ref(null)
const fields = computed(() => data.value.queryFields.filter(field => field.defaultVisible))
const singleStore = computed(() => props.platform ? storeIds.value.length === 1 : true)
const filterFields = [{ key: 'payment_name', label: '支付方式', type: 'text', defaultVisible: true, defaultQuick: true }]
let request = 0
let detailRequest = 0
let controller
let detailController
let returnFocus

/** 草稿中的门店只控制查询控件；列表、下钻和导出始终复用已执行条件。 */
function chooseStore(value) {
  storeIds.value = [...value.storeIds]
  label.value = value.storeIds.length ? value.label : '全部门店'
}
function queryPayload(next = {}) {
  const id = singleStore.value ? Number(storeIds.value[0] || state.currentStore?.id || state.currentStore?.storeId || 0) : 0
  return { ...next, pageCode: code, ...dates.value, page: 1, limit: 20,
    reconciliation_mode: id ? 'days' : 'stores', reconciliation_store_id: id,
    ...(id ? { month: month.value } : {}) }
}
async function run(payload = queryPayload(), ids = storeIds.value) {
  const ticket = ++request
  controller?.abort(); detailController?.abort(); detailRequest++; detail.value = null
  controller = new AbortController(); loading.value = true; error.value = ''
  const frozen = cloneUnifiedQuerySnapshot(payload)
  const authorizedSelection = [...ids]
  try {
    const result = await loadBasicReport(props.platform, frozen, authorizedSelection, controller.signal)
    if (ticket !== request) return
    const last = Math.max(1, Math.ceil(result.pagination.total / 20))
    if (result.pagination.page > last) { await run({ ...frozen, page: last }, authorizedSelection); return }
    // 初次门店会话尚未加载工作台资料时，以后端已认证的唯一门店初始化逐日模式。
    if (!props.platform && frozen.reconciliation_mode === 'stores' && result.store_options.length === 1) {
      storeIds.value = [Number(result.store_options[0].id)]
      await run(queryPayload(), storeIds.value); return
    }
    data.value = result
    executed.value = { query: frozen, storeIds: authorizedSelection, fields: result.queryFields.map(field => ({ ...field })), fingerprint: result.receipt_fingerprint }
    await nextTick(); resultsScroll.value?.scrollTo({ top: 0 })
  } catch (e) {
    if (ticket === request && e.name !== 'AbortError') {
      error.value = e.message; data.value = { rows: [], queryFields: [], pagination: { total: 0, page: 1 }, summaries: {} }; executed.value = null
    }
  } finally { if (ticket === request) loading.value = false }
}
function submit(next) { void run(queryPayload(next)) }
function changePage(page) { if (executed.value) void run({ ...executed.value.query, page }, executed.value.storeIds) }

/** 下钻只提交后端声明的列键和稳定支付代码，不从门店标题、单元格文字或数组位置推导。 */
async function openDetail(row, field, page = 1) {
  if (!executed.value || !field.drilldown || loading.value) return
  if (page === 1) returnFocus = document.activeElement
  const ticket = ++detailRequest
  detailController?.abort(); detailController = new AbortController()
  detailLoading.value = true; detailError.value = ''
  const snapshot = cloneUnifiedQuerySnapshot(executed.value)
  const selection = { method_code: row.method_code, column_key: field.drilldown.column_key }
  detail.value = { row, field, snapshot, selection, rows: [], queryFields: [], summaries: {}, pagination: { total: 0, page } }
  try {
    const result = await loadBasicReport(props.platform, { ...snapshot.query, detail: selection, detail_page: page, expected_fingerprint: snapshot.fingerprint }, snapshot.storeIds, detailController.signal)
    if (ticket !== detailRequest) return
    detail.value = { ...result, row, field, snapshot, selection }
    await nextTick(); detailsScroll.value?.scrollTo({ top: 0 })
    if (page === 1) document.querySelector('.store-reconciliation__dialog button')?.focus()
  } catch (e) { if (ticket === detailRequest && e.name !== 'AbortError') detailError.value = e.message }
  finally { if (ticket === detailRequest) detailLoading.value = false }
}
/** 跳转携带后端声明的日期/渠道/门店条件，订单中心重新核实来源身份；刷单仍查看登记明细。 */
async function openCell(row, field) {
  if (!props.platform || String(row.method_code).startsWith('brush_')) { await openDetail(row, field); return }
  if (!executed.value || loading.value) return
  const snapshot = cloneUnifiedQuerySnapshot(executed.value)
  const selection = { method_code: row.method_code, column_key: field.drilldown.column_key }
  detailLoading.value = true
  error.value = ''
  try {
    const result = await loadBasicReport(true, {
      ...snapshot.query, detail: selection, detail_page: 1, expected_fingerprint: snapshot.fingerprint
    }, snapshot.storeIds)
    const documentNos = result.navigation_document_nos || []
    if (result.navigation_error) throw new Error(result.navigation_error)
    // 请求中的单元格条件与响应中的完整导航条件分开命名，避免块级遮蔽在请求前触发暂时性死区。
    const navigationSelection = result.navigation_selection
    if (!navigationSelection || !Number.isInteger(navigationSelection.storeId) || !navigationSelection.methodCode || !navigationSelection.from || !navigationSelection.to) throw new Error('对账定位条件缺失，请刷新后重试。')
    if (!Array.isArray(documentNos) || documentNos.some(no => typeof no !== 'string')) throw new Error('对账原单身份无效，请刷新后重试。')
    if (!documentNos.length) throw new Error('这格没有可定位的订单，请刷新对账单后重试。')
    if (documentNos.length > 100) throw new Error('本格涉及超过 100 张订单，请缩小对账日期范围后再跳转。')
    const token = crypto.randomUUID()
    const returnState = {
      query: snapshot.query, storeIds: snapshot.storeIds, label: label.value,
      dates: { ...dates.value }, month: month.value,
      scrollTop: resultsScroll.value?.scrollTop || 0, scrollLeft: resultsScroll.value?.scrollLeft || 0
    }
    sessionStorage.setItem(`reconciliation-navigation:${token}`, JSON.stringify({
      returnState, documentNos, selection: navigationSelection,
      storeId: Number(result.detail_context?.store_id || 0), storeLabel: result.detail_context?.store_name || '',
      paymentName: row.payment_name
    }))
    const parentOrigin = document.referrer ? new URL(document.referrer).origin : window.location.origin
    window.parent.postMessage({ type: 'mohe:reconciliation-open-orders', token }, parentOrigin)
  } catch (e) { error.value = e.message || '对账订单定位失败，请重试。' }
  finally { detailLoading.value = false }
}
function closeDetail() {
  detailRequest++; detailController?.abort(); detail.value = null; detailLoading.value = false
  void nextTick(() => returnFocus?.focus())
}
/** 下载只使用后端完整导出集；当前未提交草稿、页码与浏览器显示金额均不参与计算。 */
async function exportData(isDetail = false) {
  const source = isDetail ? detail.value?.snapshot : executed.value
  if (!source || loading.value || (isDetail ? detailExporting.value : exporting.value)) return
  const targetFields = (isDetail ? detail.value.queryFields : source.fields).filter(field => field.defaultVisible)
  const payload = { ...cloneUnifiedQuerySnapshot(source.query), expected_fingerprint: source.fingerprint, export: { scope: 'query', fields: targetFields.map(field => field.key) } }
  if (isDetail) payload.detail = { ...detail.value.selection }
  if (isDetail) detailExporting.value = true
  else exporting.value = true
  try {
    // 主表和下钻明细均使用同一已执行快照，文件由统一后端生成。
    const blob = await downloadBasicReportXlsx(props.platform, payload, [...source.storeIds])
    saveReportWorkbook(blob, isDetail ? '门店对账单明细.xlsx' : '门店对账单.xlsx')
  } catch (e) { if (isDetail) detailError.value = e.message; else error.value = e.message }
  finally { if (isDetail) detailExporting.value = false; else exporting.value = false }
}
function style(field, columns) {
  return { minWidth: field.width + 'px', width: field.width + 'px', ...(field.fixed === 'left' ? {
    position: 'sticky', left: columns.slice(0, columns.indexOf(field)).filter(item => item.fixed === 'left').reduce((sum, item) => sum + item.width, 0) + 'px'
  } : {}) }
}
/** 对账页面仅去掉币符，整数舍入仍复用统一报表格式化；计算、筛选及XLSX保留原值。 */
function money(value) { return formatReportMoney(value).replace(/^¥/, '') }
function value(row, field) { return field.money ? money(row[field.key]) : (row[field.key] ?? '-') }
function trap(event) {
  if (event.key !== 'Tab') return
  const buttons = [...event.currentTarget.querySelectorAll('button:not(:disabled)')]
  if (event.shiftKey && document.activeElement === buttons[0]) { event.preventDefault(); buttons.at(-1)?.focus() }
  else if (!event.shiftKey && document.activeElement === buttons.at(-1)) { event.preventDefault(); buttons[0]?.focus() }
}
onMounted(async () => {
  try {
    if (props.platform) storeScope.value = await loadBasicReportScope(code)
    const token = typeof route.query.reconciliation_return === 'string' ? route.query.reconciliation_return : ''
    let saved = null
    if (/^[0-9a-f-]{36}$/i.test(token)) {
      try { saved = JSON.parse(sessionStorage.getItem(`reconciliation-navigation:${token}`) || 'null')?.returnState }
      catch (_) { saved = null }
    }
    if (saved?.query && Array.isArray(saved.storeIds)) {
      dates.value = saved.dates || dates.value
      month.value = saved.month || month.value
      storeIds.value = saved.storeIds
      label.value = saved.label || label.value
      await run(saved.query, saved.storeIds)
      await nextTick()
      if (resultsScroll.value) resultsScroll.value.scrollTo({ top: saved.scrollTop || 0, left: saved.scrollLeft || 0 })
    } else await run()
  } catch (e) { error.value = e.message }
})
// 模态框在body中展示，宿主及表格同时停止键盘交互；关闭时恢复原按钮焦点。
watch(() => Boolean(detail.value || guide.value), async open => {
  emit('modal-change', open)
  if (open) { returnFocus = document.activeElement; await nextTick(); document.querySelector('.store-reconciliation__dialog button')?.focus() }
  else { await nextTick(); returnFocus?.focus() }
})
onBeforeUnmount(() => { request++; detailRequest++; controller?.abort(); detailController?.abort(); emit('modal-change', false) })
</script>

<template>
  <div class="store-reconciliation" :inert="detail || guide ? '' : null">
    <p v-if="error" class="store-reconciliation__error" role="alert">{{ error }}</p>
    <UnifiedReportQueryToolbar :fields="filterFields" :page-code="code" :is-query-loading="loading" :executed-query="executed?.query" @query="submit">
      <template #leading-controls>
        <OrganizationStoreScopePicker v-if="platform" v-model="storeIds" :tree="storeScope.tree" :allowed-store-ids="storeScope.allowed_store_ids" :label="label" @change="chooseStore" />
        <label v-if="singleStore" class="store-reconciliation__month">时间选择：<input v-model="month" type="month" :max="today.slice(0, 7)" aria-label="对账月份"></label>
        <span v-else class="store-reconciliation__period"><UnifiedQueryDateRange :model-value="{ min: dates.start_date, max: dates.end_date }" :allow-clear="false" :max-date="today" label="下单时间" @change="dates = { start_date: $event.min, end_date: $event.max }" /></span>
      </template>
      <template #primary-actions>
        <button type="button" class="button button--secondary" @click="guide = true">列名取值来源</button>
        <button type="button" class="button button--secondary" :disabled="loading || !executed" @click="run(executed.query, executed.storeIds)">刷新</button>
        <button type="button" class="button button--secondary" :disabled="loading || exporting || !executed" @click="exportData(false)">导出</button>
      </template>
    </UnifiedReportQueryToolbar>
    <section class="store-reconciliation__results" :aria-busy="loading">
      <div ref="resultsScroll" class="store-reconciliation__scroll">
        <table><thead><tr><th v-for="field in fields" :key="field.key" :style="style(field, fields)" :class="{ fixed: field.fixed === 'left' }">{{ field.label }}</th></tr></thead>
          <tbody><tr v-for="row in data.rows" :key="row.row_id"><td v-for="field in fields" :key="field.key" :style="style(field, fields)" :class="{ fixed: field.fixed === 'left', money: field.money }"><button v-if="field.drilldown" type="button" class="store-reconciliation__cell" :disabled="loading || detailLoading" :aria-label="row.payment_name + '，' + field.label + (platform && !String(row.method_code).startsWith('brush_') ? '，查看对应订单' : '，查看明细')" @click="openCell(row, field)">{{ value(row, field) }}</button><template v-else>{{ value(row, field) }}</template></td></tr></tbody>
        </table>
        <p v-if="!data.rows.length" class="store-reconciliation__empty">{{ loading ? '正在查询…' : error ? '查询未完成' : '暂无符合条件的记录' }}</p>
      </div>
      <TablePagination :total="data.pagination.total" :page="data.pagination.page" :page-size="20" :page-size-options="[20]" show-page-count @change="changePage($event.page)" />
    </section>
  </div>
  <Teleport to="body">
    <div v-if="detail" class="store-reconciliation__overlay" @keydown="trap" @keydown.esc="closeDetail">
      <section class="store-reconciliation__dialog" role="dialog" aria-modal="true" aria-labelledby="reconciliation-detail-title">
        <header><h2 id="reconciliation-detail-title">{{ detail.detail_context?.payment_name || detail.row.payment_name }} · {{ detail.detail_context?.column_label || detail.field.label }}</h2><button type="button" class="button button--secondary" @click="closeDetail">关闭</button></header>
        <p v-if="detailError" class="store-reconciliation__error" role="alert">{{ detailError }}</p>
        <div class="store-reconciliation__detail-actions"><span v-if="detail.detail_context">{{ detail.detail_context.start_date }} 至 {{ detail.detail_context.end_date }}　合计：{{ money(detail.summaries['amount:sum']) }}</span><button type="button" class="button button--secondary" :disabled="detailLoading || detailExporting || !detail.queryFields.length" @click="exportData(true)">导出明细</button></div>
        <div ref="detailsScroll" class="store-reconciliation__scroll"><table><thead><tr><th v-for="field in detail.queryFields" :key="field.key">{{ field.label }}</th></tr></thead><tbody><tr v-for="row in detail.rows" :key="row.row_id"><td v-for="field in detail.queryFields" :key="field.key" :class="{ money: field.money }">{{ value(row, field) }}</td></tr></tbody></table><p v-if="!detail.rows.length" class="store-reconciliation__empty">{{ detailLoading ? '正在查询…' : detailError ? '查询未完成' : '暂无符合条件的记录' }}</p></div>
        <TablePagination :total="detail.pagination.total" :page="detail.pagination.page" :page-size="20" :page-size-options="[20]" show-page-count @change="openDetail(detail.row, detail.field, $event.page)" />
      </section>
    </div>
    <div v-if="guide" class="store-reconciliation__overlay" @keydown="trap" @keydown.esc="guide = false"><section class="store-reconciliation__dialog store-reconciliation__guide" role="dialog" aria-modal="true" aria-label="列名取值来源"><header><h2>列名取值来源</h2><button type="button" class="button button--secondary" @click="guide = false">关闭</button></header><dl><template v-for="field in fields" :key="field.key"><dt>{{ field.label }}</dt><dd>{{ data.field_sources?.[field.key] }}</dd></template></dl></section></div>
  </Teleport>
</template>

<style scoped>
.store-reconciliation{display:flex;flex:1;flex-direction:column;min-height:0;gap:10px;color:#2b4358}
.store-reconciliation__month{display:flex;align-items:center;gap:8px;white-space:nowrap}
.store-reconciliation__period{display:block;flex:none;width:326px}
.store-reconciliation :deep(.organization-store-scope-picker__trigger){white-space:nowrap;flex:none}
.store-reconciliation__month input{border:1px solid #d9e3ee;border-radius:5px;padding:6px 8px;background:#fff;color:inherit}
.store-reconciliation__results{display:flex;flex:1;min-height:0;flex-direction:column;border:1px solid #e0e8f1;background:#fff}
.store-reconciliation__scroll{flex:1;min-height:0;overflow:auto;position:relative}
table{border-collapse:separate;border-spacing:0;width:100%;font-size:13px;white-space:nowrap}
th,td{padding:12px;border-bottom:1px solid #e7edf4;text-align:left;background:#fff;height:42px;box-sizing:border-box}
th{position:sticky;top:0;z-index:3;background:#edf3fa;color:#526981;font-weight:600}
.fixed{z-index:2;background:#f8faff}th.fixed{z-index:4;background:#edf3fa}
.money{color:#1f5f8b;font-variant-numeric:tabular-nums}
.store-reconciliation__cell{font:inherit;color:#1675c9;background:transparent;border:0;cursor:pointer;padding:0;text-decoration:none}
.store-reconciliation__cell:focus-visible{outline:2px solid #1675c9;outline-offset:4px}
.store-reconciliation__empty{padding:48px 16px;text-align:center;color:#789}
.store-reconciliation__error{color:#bd3030;margin:0;line-height:1.6}
.store-reconciliation__overlay{position:fixed;inset:0;background:#102a454d;z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px}
.store-reconciliation__dialog{display:flex;flex-direction:column;width:min(1200px,100%);height:min(760px,90vh);min-height:0;background:#fff;border-radius:8px;box-shadow:0 10px 40px #102a4533;overflow:hidden;padding:18px;gap:12px}
.store-reconciliation__dialog header,.store-reconciliation__detail-actions{display:flex;align-items:center;justify-content:space-between;gap:12px}
.store-reconciliation__dialog h2{font-size:17px;margin:0}
.store-reconciliation__guide{max-width:680px;height:auto;max-height:85vh}
.store-reconciliation__guide dl{overflow:auto;margin:0;line-height:1.7}.store-reconciliation__guide dt{font-weight:600;margin-top:10px}.store-reconciliation__guide dd{margin-left:0;color:#526981}
@media(max-width:650px){.store-reconciliation__overlay{padding:8px}.store-reconciliation__dialog{padding:12px;height:94vh}.store-reconciliation__detail-actions{align-items:flex-start}.store-reconciliation__detail-actions span{font-size:12px}.store-reconciliation__month{font-size:12px}}
</style>
