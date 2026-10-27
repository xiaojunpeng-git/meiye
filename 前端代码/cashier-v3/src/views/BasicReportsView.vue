<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { UnifiedReportQueryToolbar, UnifiedQueryDateRange, cloneUnifiedQuerySnapshot, formatReportMoney } from '@mohe/unified-query-vue3'
import '@mohe/unified-query-vue3/styles.css'
import ArrowLeft from '@lucide/vue/dist/esm/icons/arrow-left.mjs'
import BookOpen from '@lucide/vue/dist/esm/icons/book-open.mjs'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'
import OrganizationStoreScopePicker from '@/components/OrganizationStoreScopePicker.vue'
import TablePagination from '@/components/common/TablePagination.vue'
import { queryStoreBusinessReportScope } from '@/services/storeBusinessReportApi'
import { useCashierV3State } from '@/services/cashierV3Bridge'
import { loadBasicReportCatalog, loadBasicReport, saveBasicRegistration, registrationCsv } from '@/services/basicReportApi'

const route = useRoute()
const router = useRouter()
const state = useCashierV3State()
const platform = computed(() => route.meta.platformReport === true)
const catalog = ref([])
const data = ref({ rows: [], pagination: { total: 0, page: 1 }, summaries: {} })
const fields = ref([])
const query = ref({ page: 1, limit: 20 })
const executedQuery = ref(null)
const stores = ref([])
const scope = ref({ tree: [], allowed_store_ids: [] })
const scopeLabel = ref('当前权限范围')
const loading = ref(false)
const error = ref('')
const exporting = ref(false)
const fieldGuide = ref(false)
const resultsScroll = ref(null)
const editor = ref(null)
const saving = ref(false)
const saveError = ref('')
const todayParts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date())
const today = ['year', 'month', 'day'].map(type => todayParts.find(part => part.type === type).value).join('-')
const dates = ref({ start_date: today.slice(0, 7) + '-01', end_date: today, as_of_date: today })
const salaryMonth = ref(today.slice(0, 7))
let requestId = 0
let controller
let returnFocus
const active = computed(() => catalog.value.find(report => report.code === route.params.report))
const groups = computed(() => [...new Set(catalog.value.map(report => report.group))])
const visibleFields = computed(() => {
  return fields.value.filter(field => field.defaultVisible)
})
const storeOptions = computed(() => {
  const byId = new Map()
  const visit = nodes => (nodes || []).forEach(node => {
    const id = Number(node.store_id || (node.node_type === 'store' ? node.id : 0))
    if (id > 0) byId.set(id, node.title || node.name || node.label)
    visit(node.children)
  })
  visit(scope.value.tree)
  return [...byId].map(([id, name]) => ({ id, name }))
})

function openReport(report) {
  if (!report.available) return
  void router.push({ name: route.name, params: { report: report.code } })
}

/** 在途旧响应不能覆盖新的组织筛选、页码或已退出的报表。 */
async function run(next = query.value) {
  if (!active.value?.available) return
  const id = ++requestId
  controller?.abort()
  controller = new AbortController()
  loading.value = true
  error.value = ''
  query.value = { ...(active.value.code === 'basic_brush_registration' ? {} : dates.value), ...next, pageCode: active.value.code, limit: 20 }
  // 实时表不继承之前周期报表的日期草稿或已执行范围，唯一时点始终为当天。
  if (active.value.time_mode === 'current') {
    query.value = { ...query.value, start_date: today, end_date: today, as_of_date: today }
  }
  try {
    const result = await loadBasicReport(platform.value, query.value, stores.value, controller.signal)
    if (id !== requestId) return
    const lastPage = Math.max(1, Math.ceil(result.pagination.total / 20))
    if (result.pagination.page > lastPage) { await run({ ...query.value, page: lastPage }); return }
    data.value = result
    fields.value = result.queryFields
    executedQuery.value = cloneUnifiedQuerySnapshot(query.value)
    await nextTick()
    if (id === requestId && resultsScroll.value) resultsScroll.value.scrollTop = 0
  } catch (e) {
    if (id === requestId && e.name !== 'AbortError') {
      error.value = e.message
      data.value = { rows: [], pagination: { total: 0, page: 1 }, summaries: {} }
      executedQuery.value = null
    }
  } finally { if (id === requestId) loading.value = false }
}
/** 日期是聚合前的事实范围，翻页和导出复用已执行范围，不读取未提交的日期草稿。 */
function submitQuery(next) {
  if (active.value?.code === 'basic_brush_registration') { void run({ ...next, page: 1 }); return }
  const range = { ...dates.value }
  if (active.value?.time_mode === 'realtime_month') {
    if (!/^\d{4}-\d{2}$/.test(salaryMonth.value)) { error.value = '请选择工资月份'; return }
    const [year, month] = salaryMonth.value.split('-').map(Number)
    range.start_date = salaryMonth.value + '-01'
    range.end_date = [salaryMonth.value, new Date(Date.UTC(year, month, 0)).getUTCDate()].join('-')
    if (range.end_date > today) range.end_date = today
    range.as_of_date = range.end_date
  }
  void run({ ...next, ...range, page: 1 })
}
function applyPeriod(value) {
  dates.value.start_date = value.min
  dates.value.end_date = value.max > today ? today : value.max
}
function selectScope(value) {
  stores.value = value.storeIds
  scopeLabel.value = value.label
  void run({ ...query.value, page: 1 })
}
function edit(row) {
  saveError.value = ''
  const defaultStore = platform.value ? (stores.value.length === 1 ? stores.value[0] : 0)
    : Number(state.currentStore?.id || state.currentStore?.storeId || 0)
  editor.value = {
    id: row?.registration_id || 0, store_id: row?.store_id || defaultStore,
    expected_version: row?.record_version || 0, expected_source_hash: row?.source_hash || '',
    idempotency_key: 'basic-registration:' + crypto.randomUUID(),
    platform: row?.platform || '抖音', registered_date: row?.registered_date || '',
    coupon_code: row?.coupon_code || '', amount: row ? String(row.amount) : '0',
    registration_type: row?.registration_type || '门店'
  }
}
async function save() {
  if (saving.value) return
  if (!/^\d{1,9}$/.test(editor.value.amount)) { saveError.value = '登记金额必须为非负整数元'; return }
  saving.value = true; saveError.value = ''
  try {
    await saveBasicRegistration(platform.value, { ...editor.value })
    editor.value = null
    await run({ ...query.value, page: 1 })
  } catch (e) { saveError.value = e.message }
  finally { saving.value = false }
}
async function exportRows() {
  if (!executedQuery.value || exporting.value || loading.value) return
  exporting.value = true
  const frozen = cloneUnifiedQuerySnapshot(executedQuery.value)
  const selectedStores = [...stores.value]
  const selectedFields = visibleFields.value.map(field => ({ ...field }))
  try {
    const result = await loadBasicReport(platform.value, { ...frozen,
      export: { scope: 'query', fields: selectedFields.map(field => field.key) } }, selectedStores)
    const blob = new Blob([registrationCsv(selectedFields, result.exportRows, result.summaries, result.field_sources)], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url; anchor.download = (active.value?.name || '基础报表') + '.csv'; anchor.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (e) { error.value = e.message }
  finally { exporting.value = false }
}
function display(row, field) {
  if (row[field.key] == null) return field.key === 'registered_date' ? '未填写' : '-'
  if (field.money || field.key === 'amount') return formatReportMoney(row[field.key])
  if (field.key === 'registered_date' && !row[field.key]) return '未填写'
  return row[field.key] ?? '-'
}
function summary(field, index) {
  if (!executedQuery.value) return index === 0 ? '合计' : '-'
  const amount = data.value.summaries[`${field.key}:sum`]
  if (amount != null) return field.money || field.key === 'amount' ? formatReportMoney(amount) : amount
  return index === 0 ? '合计' : '-'
}
// 固定列和列宽只服从后端元数据；字段隐藏后重新计算左侧偏移，不按列位置猜业务身份。
function columnStyle(field) {
  const style = { width: field.width + 'px', minWidth: field.width + 'px' }
  if (data.value.table_layout?.fixed && field.fixed === 'left') {
    const preceding = visibleFields.value.slice(0, visibleFields.value.indexOf(field))
    style.position = 'sticky'
    style.left = preceding.filter(item => item.fixed === 'left').reduce((sum, item) => sum + item.width, 0) + 'px'
  }
  return style
}
// 新弹窗只在自身范围内循环焦点；关闭后回到打开它的按钮，不让键盘操作穿透到查询栏。
function trapFocus(event) {
  if (event.key !== 'Tab') return
  const items = [...event.currentTarget.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled)')]
  const first = items[0]; const last = items.at(-1)
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}
watch(() => Boolean(editor.value || fieldGuide.value), async open => {
  if (open) {
    returnFocus = document.activeElement
    await nextTick()
    document.querySelector('.basic-report-dialog button')?.focus()
  } else { await nextTick(); returnFocus?.focus() }
})
watch(() => route.params.report, () => {
  requestId++; controller?.abort(); editor.value = null; executedQuery.value = null
  // 切换报表立即清掉上一张表的行、合计和编辑资格，不能把旧数据短暂展示在新表名下。
  data.value = { rows: [], pagination: { total: 0, page: 1 }, summaries: {} }
  loading.value = false; error.value = ''
  query.value = { page: 1, limit: 20 }; fields.value = active.value?.queryFields || []
  if (active.value?.available) void run()
})
onMounted(async () => {
  // 只在新报表路由内取消收银桌面画布的最小宽度；离开后恢复，不整改其他页面。
  document.documentElement.classList.add('basic-reports-document')
  try {
    catalog.value = await loadBasicReportCatalog(platform.value)
    fields.value = active.value?.queryFields || []
    if (platform.value) scope.value = await queryStoreBusinessReportScope('platform')
    if (route.params.report && !active.value) throw new Error('未配置该基础报表')
    if (active.value?.available) await run()
  } catch (e) { error.value = e.message }
})
onBeforeUnmount(() => { requestId++; controller?.abort(); document.documentElement.classList.remove('basic-reports-document') })
</script>

<template>
  <main class="basic-reports" :inert="editor || fieldGuide ? '' : null">
    <header class="basic-reports__header">
      <div><button v-if="route.params.report" type="button" class="basic-reports__icon" aria-label="返回基础报表" title="返回基础报表" @click="router.push({ name: route.name })"><ArrowLeft :size="18" /></button><h1>{{ active?.name || '基础报表' }}</h1></div>
      <button v-if="active?.available && data.can_edit" type="button" class="button button--primary" @click="edit()">新增登记</button>
    </header>
    <p v-if="error" class="basic-reports__error" role="alert">{{ error }}</p>
    <p v-else-if="data.complete === false" class="basic-reports__error" role="status">部分计算条件缺失，待核对项目显示为“-”，不计入合计。{{ (data.data_gaps || []).map(gap => gap.label).filter(Boolean).join('；') }}</p>
    <p v-if="data.coverage_start && data.range?.start_date < data.coverage_start" class="basic-reports__error" role="status">本表仅包含 {{ data.coverage_start }} 起纳入统一数据底层的业务，更早历史尚未纳入本表。</p>
    <template v-if="!route.params.report">
      <section v-for="group in groups" :key="group" class="basic-reports__group">
        <h2>{{ group }}</h2><div class="basic-reports__directory">
          <button v-for="report in catalog.filter(item => item.group === group)" :key="report.code" type="button" :disabled="!report.available" @click="openReport(report)"><span>{{ report.name }}</span><small v-if="!report.available">开发中</small></button>
        </div>
      </section>
    </template>
    <template v-else-if="active?.available">
      <UnifiedReportQueryToolbar :fields="fields" :page-code="active.code"
        :is-query-loading="loading" :executed-query="executedQuery" @query="submitQuery">
        <template #leading-controls>
          <OrganizationStoreScopePicker v-if="platform" v-model="stores" :tree="scope.tree" :allowed-store-ids="scope.allowed_store_ids" :label="scopeLabel" @change="selectScope" />
          <label v-if="active.time_mode === 'realtime_month'" class="basic-reports__date">月份<input v-model="salaryMonth" type="month" :max="today.slice(0, 7)" aria-label="工资月份"></label>
          <UnifiedQueryDateRange v-else-if="!['current', 'registration_date'].includes(active.time_mode)" :model-value="{ min: dates.start_date, max: dates.end_date }" :allow-clear="false" label="报表查询周期" @change="applyPeriod" />
        </template>
        <template #primary-actions>
          <button type="button" class="basic-reports__icon" title="列名取值来源" aria-label="列名取值来源" @click="fieldGuide = true"><BookOpen :size="17" /></button>
          <button type="button" class="basic-reports__icon" title="刷新" aria-label="刷新" :disabled="loading" @click="run()"><RefreshCw :size="17" /></button>
          <button type="button" class="basic-reports__icon" title="导出" aria-label="导出" :disabled="!executedQuery || exporting || loading" @click="exportRows"><Download :size="17" /></button>
        </template>
      </UnifiedReportQueryToolbar>
      <section class="basic-reports__results" :aria-busy="loading">
        <div ref="resultsScroll" class="basic-reports__scroll">
          <table><thead><tr><th v-for="field in visibleFields" :key="field.key" :style="columnStyle(field)" :class="{ 'is-fixed': field.fixed === 'left' }">{{ field.label }}</th><th v-if="data.can_edit">操作</th></tr>
            <tr class="basic-reports__total"><th v-for="(field, index) in visibleFields" :key="field.key" :style="columnStyle(field)" :class="{ 'is-fixed': field.fixed === 'left' }">{{ summary(field, index) }}</th><th v-if="data.can_edit">-</th></tr></thead>
            <tbody><tr v-for="row in data.rows" :key="row.row_id || row.registration_id"><td v-for="field in visibleFields" :key="field.key" :style="columnStyle(field)" :class="{ 'is-fixed': field.fixed === 'left', 'is-money': field.money }">{{ display(row, field) }}</td><td v-if="data.can_edit"><button type="button" class="basic-reports__edit" @click="edit(row)">编辑</button></td></tr></tbody>
          </table>
          <p v-if="!data.rows.length" class="basic-reports__empty">{{ loading ? '正在查询…' : error ? '查询未完成' : '暂无符合条件的记录' }}</p>
        </div>
        <TablePagination :total="data.pagination.total" :page="data.pagination.page" :page-size="20" :page-size-options="[20]" show-page-count @change="run({ ...query, page: $event.page })" />
      </section>
    </template>
    <Teleport to="body">
      <div v-if="editor" class="basic-report-dialog" role="dialog" aria-modal="true" aria-labelledby="registration-title" @keydown="trapFocus" @keydown.esc="!saving && (editor = null)">
        <form @submit.prevent="save"><header><h2 id="registration-title">{{ editor.id ? '编辑登记' : '新增登记' }}</h2><button type="button" class="basic-reports__icon" aria-label="关闭" :disabled="saving" @click="editor = null"><X :size="18" /></button></header>
          <p v-if="saveError" class="basic-reports__error" role="alert">{{ saveError }}</p>
          <div class="basic-report-dialog__fields">
            <label v-if="platform">门店<select v-model.number="editor.store_id" :disabled="!!editor.id"><option :value="0" disabled>请选择门店</option><option v-for="store in storeOptions" :key="store.id" :value="store.id">{{ store.name }}</option></select></label>
            <label>平台<select v-model="editor.platform"><option>抖音</option><option>大众</option></select></label>
            <label>核销日期<input v-model="editor.registered_date" type="date"></label>
            <label>券码<input v-model="editor.coupon_code" maxlength="128"></label>
            <label>金额<input v-model="editor.amount" inputmode="numeric" maxlength="10"></label>
            <label>类型<select v-model="editor.registration_type"><option>门店</option><option>探店</option></select></label>
          </div><footer><button type="button" class="button button--secondary" :disabled="saving" @click="editor = null">取消</button><button class="button button--primary" :disabled="saving">{{ saving ? '保存中…' : '保存' }}</button></footer>
        </form>
      </div>
      <div v-if="fieldGuide" class="basic-report-dialog" role="dialog" aria-modal="true" aria-label="列名取值来源" @keydown="trapFocus" @keydown.esc="fieldGuide = false"><section><header><h2>列名取值来源</h2><button type="button" class="basic-reports__icon" aria-label="关闭" @click="fieldGuide = false"><X :size="18" /></button></header><dl><template v-for="field in visibleFields" :key="field.key"><dt>{{ field.label }}</dt><dd>{{ data.field_sources?.[field.key] || '该条业务记录的稳定身份。' }}</dd></template></dl></section></div>
    </Teleport>
  </main>
</template>

<style scoped>
:global(html.basic-reports-document),:global(html.basic-reports-document body),:global(html.basic-reports-document #app){min-width:0}
/* Keep record totals reachable on this report even when the shell hides compact pagination summaries. */
.basic-reports :deep(.table-pagination){flex:none;flex-wrap:wrap}
.basic-reports :deep(.table-pagination__summary){display:block;white-space:normal}
.basic-reports__date{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}.basic-reports__date input{min-width:0;max-width:148px;min-height:32px;border:1px solid #dce4ec;border-radius:4px;padding:3px 6px;color:inherit;background:#fff;font:inherit}.basic-reports td.is-money{color:#1f5f8b}
.basic-reports{height:100%;min-height:0;display:flex;flex-direction:column;background:#fff;padding:12px 16px;gap:12px;color:#35475e;font-size:13px;overflow:auto;letter-spacing:0}
.basic-reports__header,.basic-reports__header>div{display:flex;align-items:center;gap:10px}.basic-reports__header{justify-content:space-between;flex:none}.basic-reports h1{font-size:18px;margin:0}.basic-reports h2{font-size:14px;margin:0 0 12px}.basic-reports__group{padding:12px 0;border-top:1px solid #e6ebf1}.basic-reports__directory{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.basic-reports__directory button{display:flex;flex-direction:column;justify-content:center;gap:8px;min-height:74px;padding:14px;border:1px solid #dce5ed;border-radius:6px;background:#fff;text-align:left;font:inherit;color:#245e95;cursor:pointer;overflow-wrap:anywhere}.basic-reports__directory button:disabled{color:#7c8898;cursor:default;background:#fafbfd}.basic-reports__directory small{font-size:11px;color:#8c97a6}
.basic-reports__icon{display:inline-grid;place-items:center;width:32px;height:32px;flex:none;border:1px solid #dce4ec;border-radius:4px;background:#fff;color:#49617d;cursor:pointer}.basic-reports__icon:disabled{opacity:.5;cursor:default}.basic-reports__results{display:flex;flex-direction:column;flex:1;min-height:180px;overflow:hidden;border:1px solid #e0e7ee}.basic-reports__scroll{flex:1;min-height:0;overflow:auto;position:relative}.basic-reports table{min-width:800px;width:100%;border-collapse:separate;border-spacing:0}.basic-reports th,.basic-reports td{padding:12px;text-align:left;white-space:nowrap;border-bottom:1px solid #e7edf3;height:42px;box-sizing:border-box}.basic-reports th{position:sticky;top:0;background:#f2f6fb;color:#53667f;z-index:2}.basic-reports .basic-reports__total th{top:42px;background:#eaf3fc;color:#1f5f8b}.basic-reports td.is-fixed{z-index:1;background:#fff}.basic-reports th.is-fixed{z-index:3}.basic-reports__empty{text-align:center;padding:40px 12px;color:#8491a2}.basic-reports__error{color:#ba2929;margin:0;line-height:1.6}.basic-reports__edit{border:0;background:none;color:#1675d4;cursor:pointer}
.basic-report-dialog{position:fixed;inset:0;z-index:2000;background:#17253e66;display:grid;place-items:center;padding:16px;color:#35475e;font-size:13px}.basic-report-dialog>form,.basic-report-dialog>section{width:min(580px,100%);max-height:calc(100dvh - 32px);overflow:auto;background:#fff;border-radius:6px;padding:20px;box-sizing:border-box}.basic-report-dialog header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.basic-report-dialog h2{font-size:16px;margin:0}.basic-report-dialog__fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.basic-report-dialog label{display:flex;flex-direction:column;gap:7px;min-width:0}.basic-report-dialog input,.basic-report-dialog select{width:100%;min-width:0;box-sizing:border-box;min-height:34px;border:1px solid #dce4ec;border-radius:4px;padding:5px 8px;background:#fff;color:inherit;font:inherit}.basic-report-dialog footer{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}.basic-report-dialog dt{font-weight:600;margin-top:15px}.basic-report-dialog dd{margin:6px 0;line-height:1.7;color:#687b92}
@media(max-width:600px){.basic-reports{padding:10px}.basic-report-dialog__fields{grid-template-columns:minmax(0,1fr)}}
</style>
