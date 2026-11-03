<script setup>
import { saveReportWorkbook } from '@/services/reportWorkbookDownload'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { UnifiedReportQueryToolbar, UnifiedQueryDateRange, cloneUnifiedQuerySnapshot, formatReportMoney } from '@mohe/unified-query-vue3'
import '@mohe/unified-query-vue3/styles.css'
import BookOpen from '@lucide/vue/dist/esm/icons/book-open.mjs'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'
import OrganizationStoreScopePicker from '@/components/OrganizationStoreScopePicker.vue'
import TablePagination from '@/components/common/TablePagination.vue'
import StoreReconciliationReport from '@/components/reports/StoreReconciliationReport.vue'
import { useCashierV3State } from '@/services/cashierV3Bridge'
import { loadBasicReportCatalog, loadBasicReportScope, loadBasicReport, downloadBasicReportXlsx, saveBasicRegistration, deleteBasicRegistration, saveBasicSalary, freezeBasicSalary } from '@/services/basicReportApi'

const route = useRoute()
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
const asyncTask = ref(null)
const exporting = ref(false)
const fieldGuide = ref(false)
const commissionProof = ref(null)
const resultsScroll = ref(null)
const editor = ref(null)
const saving = ref(false)
const saveError = ref('')
const deleteDialog = ref(null)
const deleting = ref(false)
const deleteError = ref('')
const salaryEditing = ref(false)
const salaryDraft = ref(null)
const salarySaving = ref(false)
const freezeDialog = ref(null)
const salaryReport = computed(() => active.value?.code === 'basic_salary_realtime')
const reconciliationReport = computed(() => active.value?.code === 'basic_store_reconciliation')
const reconciliationModal = ref(false)
const todayParts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date())
const today = ['year', 'month', 'day'].map(type => todayParts.find(part => part.type === type).value).join('-')
const dates = ref({ start_date: today.slice(0, 7) + '-01', end_date: today, as_of_date: today })
const salaryMonth = ref(today.slice(0, 7))
let requestId = 0
let controller
let returnFocus
let asyncPollTimer
const active = computed(() => catalog.value.find(report => report.code === route.params.report))
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

// 跨店历史结算默认核对本年，与原报表查询区间一致；切换其他表仍回到月初。
function resetReportDates(code) {
  salaryMonth.value = today.slice(0, 7)
  dates.value = { start_date: code === 'basic_cross_store_settlement' ? today.slice(0, 4) + '-01-01' : today.slice(0, 7) + '-01', end_date: today, as_of_date: today }
}

/** 在途旧响应不能覆盖新的组织筛选、页码或已退出的报表。 */
async function run(next = query.value) {
  if (!active.value?.available) return
  // 动态门店/逐日列由专用展示组件消费同一后端契约，避免宿主重复发起通用表查询。
  if (reconciliationReport.value) return
  const id = ++requestId
  clearTimeout(asyncPollTimer)
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
    // 后台生成期间不展示旧查询的行或伪合计；轮询只复用同一认证任务号。
    if (result.async_report?.pending) {
      asyncTask.value = result.async_report
      query.value.unconsumed_task_id = result.async_report.task_id
      data.value = { rows: [], pagination: { total: 0, page: 1 }, summaries: {} }
      executedQuery.value = null
      fields.value = result.queryFields || fields.value
      asyncPollTimer = setTimeout(() => { if (id === requestId) void run({ ...query.value }) }, 2500)
      return
    }
    asyncTask.value = null
    const lastPage = Math.max(1, Math.ceil(result.pagination.total / 20))
    if (result.pagination.page > lastPage) { await run({ ...query.value, page: lastPage }); return }
    salaryDraft.value = null
    data.value = result
    fields.value = result.queryFields
    executedQuery.value = cloneUnifiedQuerySnapshot(query.value)
    await nextTick()
    if (id === requestId && resultsScroll.value) resultsScroll.value.scrollTop = 0
  } catch (e) {
    if (id === requestId && e.name !== 'AbortError') {
      error.value = e.message
      asyncTask.value = null
      data.value = { rows: [], pagination: { total: 0, page: 1 }, summaries: {} }
      executedQuery.value = null
    }
  } finally { if (id === requestId) loading.value = false }
}
/** 日期是聚合前的事实范围，翻页和导出复用已执行范围，不读取未提交的日期草稿。 */
function submitQuery(next) {
  const fresh = { ...next, page: 1 }
  delete fresh.unconsumed_task_id
  if (active.value?.code === 'basic_brush_registration') { void run(fresh); return }
  const range = { ...dates.value }
  if (['realtime_month', 'month'].includes(active.value?.time_mode)) {
    if (!/^\d{4}-\d{2}$/.test(salaryMonth.value)) { error.value = '请选择工资月份'; return }
    const [year, month] = salaryMonth.value.split('-').map(Number)
    range.start_date = salaryMonth.value + '-01'
    range.end_date = [salaryMonth.value, new Date(Date.UTC(year, month, 0)).getUTCDate()].join('-')
    if (range.end_date > today) range.end_date = today
    range.as_of_date = range.end_date
  }
  void run({ ...fresh, ...range })
}
function applyPeriod(value) {
  dates.value.start_date = value.min
  dates.value.end_date = value.max > today ? today : value.max
}
function selectScope(value) {
  stores.value = value.storeIds
  // 010 平台菜单授权的空选择代表全部门店，不能继续显示旧的数据权限文案。
  scopeLabel.value = !value.storeIds.length && scope.value.authorization_mode === 'all' ? '全部门店' : value.label
  const fresh = { ...query.value, page: 1 }
  delete fresh.unconsumed_task_id
  void run(fresh)
}
/** 刷新显式创建新快照；翻页与导出继续使用已完成的同一任务。 */
function refreshReport() {
  const fresh = { ...query.value, page: 1 }
  delete fresh.unconsumed_task_id
  void run(fresh)
}
/** 每张表独立取后端门店树；切回全部时与该表的读授权保持一致。 */
async function refreshScope(code) {
  scope.value = await loadBasicReportScope(code)
  if (!stores.value.length) scopeLabel.value = scope.value.authorization_mode === 'all' ? '全部门店' : '当前权限范围'
}
/** 同员工跨店按服务端row_id隔离草稿；保留原始输入，不从格式化金额回填。 */
function editSalary(row) {
  error.value = ''
  const values = Object.fromEntries(fields.value.filter(field => field.editable).map(field => [field.key, String(row.manual_values?.[field.key] ?? '')]))
  salaryDraft.value = { row_key: row.row_id, month: row.month, store_id: row.store_id, employee_id: row.employee_id,
    expected_version: row.manual_version, idempotency_key: crypto.randomUUID(), values, original: { ...values } }
}
async function saveSalary() {
  if (!salaryDraft.value || salarySaving.value) return
  const { original, ...body } = salaryDraft.value
  body.values = Object.fromEntries(Object.entries(body.values).filter(([key, value]) => value !== original[key]))
  if (!Object.keys(body.values).length) { salaryDraft.value = null; return }
  salarySaving.value = true; error.value = ''
  try {
    await saveBasicSalary(platform.value, body)
    salaryDraft.value = null
    await run({ ...executedQuery.value })
  } catch (e) { error.value = e.message }
  finally { salarySaving.value = false }
}
/** 冻结使用已执行月份，不能把未查询的月份草稿写成另一月份的全店锁。 */
function requestSalaryFreeze() {
  freezeDialog.value = { month: data.value.salary_state.month, frozen: !data.value.salary_state.frozen,
    expected_version: data.value.salary_state.version, idempotency_key: crypto.randomUUID() }
}
async function saveSalaryFreeze() {
  if (salarySaving.value || !freezeDialog.value) return
  salarySaving.value = true; error.value = ''
  try {
    await freezeBasicSalary({ ...freezeDialog.value })
    freezeDialog.value = null; salaryEditing.value = false
    await run({ ...executedQuery.value })
  } catch (e) { error.value = e.message }
  finally { salarySaving.value = false }
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
  // 人工刷单登记只补充报表记录，输入精度与服务端的分精度校验一致。
  if (!/^\d{1,9}(?:\.\d{1,2})?$/.test(editor.value.amount)) { saveError.value = '登记金额须为非负数，最多保留两位小数'; return }
  saving.value = true; saveError.value = ''
  try {
    await saveBasicRegistration(platform.value, { ...editor.value })
    editor.value = null
    await run({ ...query.value, page: 1 })
  } catch (e) { saveError.value = e.message }
  finally { saving.value = false }
}
/** 用户确认后才删除；沿用已查询行的版本和内容证明，结果从后端重新读取。 */
function requestDelete(row) {
  deleteError.value = ''
  deleteDialog.value = {
    id: row.registration_id, store_id: row.store_id, expected_version: row.record_version,
    expected_source_hash: row.source_hash, idempotency_key: 'basic-registration-delete:' + crypto.randomUUID(),
    store_name: row.store_name, coupon_code: row.coupon_code
  }
}
async function confirmDelete() {
  if (!deleteDialog.value || deleting.value) return
  deleting.value = true; deleteError.value = ''
  try {
    const { store_name, coupon_code, ...body } = deleteDialog.value
    await deleteBasicRegistration(platform.value, body)
    deleteDialog.value = null
    await run({ ...executedQuery.value })
  } catch (e) { deleteError.value = e.message }
  finally { deleting.value = false }
}
async function exportRows() {
  if (!executedQuery.value || exporting.value || loading.value) return
  exporting.value = true
  const frozen = cloneUnifiedQuerySnapshot(executedQuery.value)
  const selectedStores = [...stores.value]
  const selectedFields = visibleFields.value.map(field => ({ ...field }))
  try {
    const exportQuery = { ...frozen, export: { scope: 'query', fields: selectedFields.map(field => field.key) } }
    // 后端校验完整查询快照、所选列和权限后生成同一 XLSX，浏览器只保存文件。
    const blob = await downloadBasicReportXlsx(platform.value, exportQuery, selectedStores)
    saveReportWorkbook(blob, (active.value?.name || '基础报表') + '.xlsx')
  } catch (e) { error.value = e.message }
  finally { exporting.value = false }
}
function display(row, field) {
  if (row[field.key] == null) return field.key === 'registered_date' ? '未填写' : '-'
  // 刷单登记沿用已上线的两位金额展示。
  if (active.value?.code === 'basic_brush_registration' && field.key === 'amount') return formatReportMoney(row[field.key], 2)
  // 007 两项对账金额显示分、合计显示整数元；导出仍使用后端分精度原值。
  if (active.value?.code === 'basic_cross_store_settlement' && field.money && !['settlement_total', 'total_amount'].includes(field.key)) return Number(row[field.key]).toFixed(2)
  if (active.value?.code === 'basic_cross_store_settlement' && ['settlement_total', 'total_amount'].includes(field.key)) return String(Math.trunc(Number(row[field.key])))
  // 矩阵单位由后端逐行声明，同一门店列可以分别显示金额、客数与比例。
  const unit = field.value_unit === 'row' ? row.value_unit : field.value_unit
  if (unit === 'percent_integer') return String(row[field.key]) + '%'
  if (unit === 'money_yuan' || field.money || field.key === 'amount') return formatReportMoney(row[field.key])
  if (field.key === 'registered_date' && !row[field.key]) return '未填写'
  if (field.type === 'decimal') return formatReportCount(row[field.key], field)
  if (['project_count','xiangmushu',...(salaryReport.value ? ['haoqingshu','zhidingke'] : [])].includes(field.key) && row[field.key] != null) return String(Number(row[field.key]))
  return row[field.key] ?? '-'
}
// 仅按后端列元数据限制页面精度；结果和导出继续使用未格式化的原始数量。
function formatReportCount(value, field) {
  const precision = field?.display_precision
  const minimum = field?.display_min_precision
  const number = Number(value)
  if (Number.isInteger(precision) && precision >= 0 && precision <= 6 && Number.isFinite(number)) {
    const minimumFractionDigits = Number.isInteger(minimum) && minimum >= 0 && minimum <= precision ? minimum : 0
    return new Intl.NumberFormat('zh-CN', { useGrouping: false, minimumFractionDigits, maximumFractionDigits: precision }).format(number)
  }
  const text = String(value)
  return /^-?\d+\.\d+$/.test(text) ? text.replace(/0+$/, '').replace(/\.$/, '') : text
}
function summary(field, index) {
  if (!executedQuery.value) return index === 0 ? '合计' : '-'
  const amount = data.value.summaries[`${field.key}:sum`]
  if (amount != null && active.value?.code === 'basic_brush_registration' && field.key === 'amount') return formatReportMoney(amount, 2)
  if (amount != null && active.value?.code === 'basic_cross_store_settlement' && field.money && !['settlement_total', 'total_amount'].includes(field.key)) return Number(amount).toFixed(2)
  if (amount != null && active.value?.code === 'basic_cross_store_settlement' && ['settlement_total', 'total_amount'].includes(field.key)) return String(Math.trunc(Number(amount)))
  // 合计与明细使用同一数量显示口径；金额仍使用统一金额格式化函数。
  if (amount != null) return field.money || field.key === 'amount' ? formatReportMoney(amount) : field.type === 'decimal' ? formatReportCount(amount, field) : amount
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
watch(() => Boolean(editor.value || fieldGuide.value || commissionProof.value || freezeDialog.value), async open => {
  if (open) {
    returnFocus = document.activeElement
    await nextTick()
    document.querySelector('.basic-report-dialog button')?.focus()
  } else { await nextTick(); returnFocus?.focus() }
})
watch(() => route.params.report, async () => {
  const routeRequest = ++requestId
  clearTimeout(asyncPollTimer); asyncTask.value = null
  controller?.abort(); salaryDraft.value = null; salaryEditing.value = false; freezeDialog.value = null; editor.value = null; commissionProof.value = null; executedQuery.value = null
  // 切换报表立即清掉上一张表的行、合计和编辑资格，不能把旧数据短暂展示在新表名下。
  data.value = { rows: [], pagination: { total: 0, page: 1 }, summaries: {} }
  loading.value = false; error.value = ''
  query.value = { page: 1, limit: 20 }; fields.value = active.value?.queryFields || []
  resetReportDates(active.value?.code)
  if (active.value?.available) {
    try {
      if (platform.value) await refreshScope(active.value.code)
      if (routeRequest === requestId) void run()
    } catch (e) { if (routeRequest === requestId) error.value = e.message }
  }
})
onMounted(async () => {
  // 只在新报表路由内取消收银桌面画布的最小宽度；离开后恢复，不整改其他页面。
  document.documentElement.classList.add('basic-reports-document')
  try {
    catalog.value = await loadBasicReportCatalog(platform.value)
    resetReportDates(active.value?.code)
    fields.value = active.value?.queryFields || []
    if (platform.value && active.value) await refreshScope(active.value.code)
    if (route.params.report && !active.value) throw new Error('未配置该基础报表')
    if (active.value?.available) await run()
  } catch (e) { error.value = e.message }
})
onBeforeUnmount(() => { requestId++; clearTimeout(asyncPollTimer); controller?.abort(); document.documentElement.classList.remove('basic-reports-document') })
</script>

<template>
  <main class="basic-reports" :inert="editor || fieldGuide || commissionProof || freezeDialog || reconciliationModal ? '' : null">
    <header class="basic-reports__header">
      <div><h1>{{ active?.name || '报表' }}</h1></div>
      <button v-if="active?.available && data.can_edit" type="button" class="button button--primary" @click="edit()">新增登记</button>
    </header>
    <p v-if="error" class="basic-reports__error" role="alert">{{ error }}</p>
    <p v-else-if="data.complete === false" class="basic-reports__error" role="status">部分计算条件缺失，待核对项目显示为“-”，不计入合计。{{ (data.data_gaps || []).map(gap => gap.label).filter(Boolean).join('；') }}</p>
    <p v-if="asyncTask" class="basic-reports__snapshot" role="status" aria-live="polite">正在后台生成完整报表：已处理 {{ asyncTask.completed_stores }} / {{ asyncTask.total_stores }} 家门店。完成后才显示结果和合计。</p>
    <p v-if="data.unconsumed_task_id && data.data_as_of" class="basic-reports__snapshot" role="status">本次结果的数据时点：{{ new Date(data.data_as_of * 1000).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai' }) }}（北京时间）。点击刷新可重新生成。</p>
    <p v-if="data.coverage_start && data.range?.start_date < data.coverage_start" class="basic-reports__error" role="status">本表仅包含 {{ data.coverage_start }} 起纳入统一数据底层的业务，更早历史尚未纳入本表。</p>
    <p v-if="salaryReport && data.realtime_start_date" class="basic-reports__snapshot" role="status">{{ data.source_kind === 'saved_salary_snapshot' ? data.coverage : data.realtime_start_date + ' 起按业务发生日期实时统计；此前月份读取历史保存工资。' }}</p>
    <p v-if="active?.code === 'basic_cross_store_settlement' && data.source_kind === 'sealed_legacy_settlement_snapshot'" class="basic-reports__snapshot" role="status">{{ data.coverage }}</p>
    <!-- 两端都从各自可配置菜单直达单表；旧目录地址只引导回菜单，不再维护第二套卡片入口。 -->
    <p v-if="!route.params.report && !error" class="basic-reports__empty" role="status">请从报表菜单选择需要查看的报表。</p>
    <StoreReconciliationReport v-else-if="active?.available && reconciliationReport" :platform="platform" @modal-change="reconciliationModal = $event" />
    <template v-else-if="active?.available">
      <UnifiedReportQueryToolbar :fields="fields" :page-code="active.code"
        :primary-date-keys="active.code === 'basic_brush_registration' ? ['registered_date'] : []"
        :is-query-loading="loading || salarySaving" :executed-query="executedQuery" @query="submitQuery">
        <template #leading-controls>
          <OrganizationStoreScopePicker v-if="platform" v-model="stores" :tree="scope.tree" :allowed-store-ids="scope.allowed_store_ids" :label="scopeLabel" @change="selectScope" />
          <label v-if="['realtime_month', 'month'].includes(active.time_mode)" class="basic-reports__date">时间选择：<input v-model="salaryMonth" type="month" :max="today.slice(0, 7)" :aria-label="salaryReport ? '工资月份' : '报表月份'"></label>
          <span v-else-if="!['current', 'registration_date'].includes(active.time_mode)" class="basic-reports__period"><UnifiedQueryDateRange :model-value="{ min: dates.start_date, max: dates.end_date }" :allow-clear="false" label="报表查询周期" @change="applyPeriod" /></span>
        </template>
        <template #primary-actions>
          <button v-if="salaryReport && data.salary_can_edit" type="button" class="button button--secondary" :disabled="loading || salarySaving" @click="salaryEditing = !salaryEditing; salaryDraft = null">{{ salaryEditing ? '取消编辑' : '编辑' }}</button>
          <button v-if="salaryReport && data.salary_can_freeze" type="button" class="button button--secondary" :disabled="loading || salarySaving || !executedQuery" @click="requestSalaryFreeze">{{ !data.salary_readonly && data.salary_state?.frozen ? '取消冻结' : '冻结' }}</button>
          <button type="button" class="basic-reports__icon" title="列名取值来源" aria-label="列名取值来源" @click="fieldGuide = true"><BookOpen :size="17" /></button>
          <button type="button" class="basic-reports__icon" title="刷新" aria-label="刷新" :disabled="loading || !!asyncTask" @click="refreshReport"><RefreshCw :size="17" /></button>
          <button type="button" class="basic-reports__icon" title="导出" aria-label="导出" :disabled="!executedQuery || exporting || loading" @click="exportRows"><Download :size="17" /></button>
        </template>
      </UnifiedReportQueryToolbar>
      <p v-if="salaryReport" class="basic-reports__salary-summary"><span>应发工资：{{ formatReportMoney(data.summaries['yingfagongzi:sum']) }}</span><span>实发工资：{{ formatReportMoney(data.summaries['shifagongzi:sum']) }}</span><span v-if="!data.salary_readonly && data.salary_state?.frozen">{{ data.salary_state.month }} 已冻结门店人工项编辑，实时计算继续更新。</span></p>
      <div v-if="data.summary_cards?.length" class="basic-reports__metric-summary" aria-label="当前查询汇总"><span v-for="card in data.summary_cards" :key="card.key">{{ card.label }}：<strong>{{ card.value == null ? '-' : card.money ? formatReportMoney(card.value) : card.value }}</strong></span></div>
      <section class="basic-reports__results" :aria-busy="loading || !!asyncTask">
        <div ref="resultsScroll" class="basic-reports__scroll">
          <table><thead><tr><th v-for="field in visibleFields" :key="field.key" :style="columnStyle(field)" :class="{ 'is-fixed': field.fixed === 'left' }">{{ field.label }}</th><th v-if="data.can_edit || (salaryEditing && data.salary_can_edit)" :class="{ 'basic-reports__row-actions': salaryReport }">操作</th></tr>
            <tr v-if="!asyncTask && data.table_layout?.summary_position !== 'none'" class="basic-reports__total"><th v-for="(field, index) in visibleFields" :key="field.key" :style="columnStyle(field)" :class="{ 'is-fixed': field.fixed === 'left' }">{{ summary(field, index) }}</th><th v-if="data.can_edit || (salaryEditing && data.salary_can_edit)" :class="{ 'basic-reports__row-actions': salaryReport }">-</th></tr></thead>
            <tbody><tr v-for="row in data.rows" :key="row.row_id || row.registration_id"><td v-for="field in visibleFields" :key="field.key" :style="columnStyle(field)" :class="{ 'is-fixed': field.fixed === 'left', 'is-money': field.money }"><input v-if="salaryDraft?.row_key === row.row_id && field.editable" v-model="salaryDraft.values[field.key]" :aria-label="row.employee_name + field.label" :disabled="salarySaving" :maxlength="field.key === 'beizhu' ? 500 : 10" class="basic-reports__salary-input"><button v-else-if="['work_get','labor_commission'].includes(field.key) && row.commission_details?.length" type="button" class="basic-reports__edit" :aria-label="row.employee_name + '劳动提成计算依据'" @click="commissionProof = row">{{ display(row, field) }}</button><template v-else>{{ display(row, field) }}</template></td><td v-if="data.can_edit"><button type="button" class="basic-reports__edit" @click="edit(row)">编辑</button><button type="button" class="basic-reports__edit" :aria-label="'删除登记 ' + row.registration_id" @click="requestDelete(row)">删除</button></td><td v-if="salaryEditing && data.salary_can_edit" class="basic-reports__row-actions"><template v-if="salaryDraft?.row_key === row.row_id"><button type="button" :disabled="salarySaving || loading" @click="saveSalary">保存</button><button type="button" :disabled="salarySaving" @click="salaryDraft = null">取消</button></template><button v-else type="button" :disabled="salarySaving || !!salaryDraft" @click="editSalary(row)">编辑</button></td></tr></tbody>
          </table>
          <p v-if="!data.rows.length" class="basic-reports__empty">{{ asyncTask ? '正在后台生成完整报表…' : loading ? '正在查询…' : error ? '查询未完成' : '暂无符合条件的记录' }}</p>
        </div>
        <TablePagination v-if="!asyncTask" :total="data.pagination.total" :page="data.pagination.page" :page-size="20" :page-size-options="[20]" show-page-count @change="run({ ...query, page: $event.page })" />
      </section>
    </template>
    <Teleport to="body">
      <div v-if="deleteDialog" class="basic-report-dialog" role="dialog" aria-modal="true" aria-labelledby="registration-delete-title" @keydown="trapFocus" @keydown.esc="!deleting && (deleteDialog = null)"><section><header><h2 id="registration-delete-title">确认删除刷单登记</h2><button type="button" class="basic-reports__icon" aria-label="关闭" :disabled="deleting" @click="deleteDialog = null"><X :size="18" /></button></header><p>确认删除 {{ deleteDialog.store_name }}的登记 {{ deleteDialog.coupon_code || deleteDialog.id }}？删除后查询、合计和导出均不再包含这条记录。</p><p v-if="deleteError" class="basic-reports__error" role="alert">{{ deleteError }}</p><footer><button type="button" class="button button--secondary" :disabled="deleting" @click="deleteDialog = null">取消</button><button type="button" class="button button--primary" :disabled="deleting" @click="confirmDelete">{{ deleting ? '删除中…' : '确认删除' }}</button></footer></section></div>
      <div v-if="freezeDialog" class="basic-report-dialog" role="dialog" aria-modal="true" aria-labelledby="salary-freeze-title" @keydown="trapFocus" @keydown.esc="!salarySaving && (freezeDialog = null)"><section><p v-if="error" class="basic-reports__error" role="alert">{{ error }}</p><h2 id="salary-freeze-title">{{ freezeDialog.frozen ? '冻结' : '取消冻结' }} {{ freezeDialog.month }}</h2><p>{{ freezeDialog.frozen ? '冻结后，全部门店不可编辑当月人工项，平台仍可编辑。实时业绩和工资计算继续更新。' : '取消冻结后，已获编辑权限的门店可继续编辑当月人工项。' }}</p><footer><button type="button" class="button button--secondary" :disabled="salarySaving" @click="freezeDialog = null">取消</button><button type="button" class="button button--primary" :disabled="salarySaving" @click="saveSalaryFreeze">确认</button></footer></section></div>
      <div v-if="editor" class="basic-report-dialog" role="dialog" aria-modal="true" aria-labelledby="registration-title" @keydown="trapFocus" @keydown.esc="!saving && (editor = null)">
        <form @submit.prevent="save"><header><h2 id="registration-title">{{ editor.id ? '编辑登记' : '新增登记' }}</h2><button type="button" class="basic-reports__icon" aria-label="关闭" :disabled="saving" @click="editor = null"><X :size="18" /></button></header>
          <p v-if="saveError" class="basic-reports__error" role="alert">{{ saveError }}</p>
          <div class="basic-report-dialog__fields">
            <label v-if="platform">门店<select v-model.number="editor.store_id" :disabled="!!editor.id"><option :value="0" disabled>请选择门店</option><option v-for="store in storeOptions" :key="store.id" :value="store.id">{{ store.name }}</option></select></label>
            <label>平台<select v-model="editor.platform"><option>抖音</option><option>大众</option></select></label>
            <label>核销日期<input v-model="editor.registered_date" type="date"></label>
            <label>券码<input v-model="editor.coupon_code" maxlength="128"></label>
            <label>金额<input v-model="editor.amount" inputmode="decimal" maxlength="12"></label>
            <label>类型<select v-model="editor.registration_type"><option>门店</option><option>探店</option></select></label>
          </div><footer><button type="button" class="button button--secondary" :disabled="saving" @click="editor = null">取消</button><button class="button button--primary" :disabled="saving">{{ saving ? '保存中…' : '保存' }}</button></footer>
        </form>
      </div>
      <!-- 依据由后端随授权行返回；弹窗不读取另一份数据或重新计算提成。 -->
      <div v-if="commissionProof" class="basic-report-dialog" role="dialog" aria-modal="true" aria-label="劳动提成计算依据" @keydown="trapFocus" @keydown.esc="commissionProof = null"><section><header><h2>{{ commissionProof.employee_name }} · 劳动提成计算依据</h2><button type="button" class="basic-reports__icon" aria-label="关闭" @click="commissionProof = null"><X :size="18" /></button></header><p>按员工、门店和自然月计算，当前为预计结果。</p><dl><template v-for="proof in commissionProof.commission_details" :key="proof.fact_id"><dt>{{ proof.business_date }} · {{ proof.project_name || '待核对项目' }}</dt><dd v-if="proof.amount_cents != null">月净销售业绩 {{ proof.display_threshold }}；劳动业绩 {{ proof.display_basis }} × {{ proof.rate_percent }}%；预计提成 {{ proof.display_commission }}</dd><dd v-else>{{ proof.message }}</dd></template></dl></section></div>
      <div v-if="fieldGuide" class="basic-report-dialog" role="dialog" aria-modal="true" aria-label="列名取值来源" @keydown="trapFocus" @keydown.esc="fieldGuide = false"><section><header><h2>列名取值来源</h2><button type="button" class="basic-reports__icon" aria-label="关闭" @click="fieldGuide = false"><X :size="18" /></button></header><dl><template v-for="field in visibleFields" :key="field.key"><dt>{{ field.label }}</dt><dd>{{ data.field_sources?.[field.key] || '该条业务记录的稳定身份。' }}</dd></template><template v-for="source in data.row_sources || []" :key="source.row_id"><dt>{{ source.label }}</dt><dd>{{ source.description }}</dd></template></dl></section></div>
    </Teleport>
  </main>
</template>

<style scoped>
/* 工资编辑只作用于当前行；右侧保存按钮常驻宽表，避免填写后无法找到保存。 */
.basic-reports__row-actions{position:sticky;right:0;min-width:110px;background:white;z-index:2;white-space:nowrap}.basic-reports__row-actions button{margin:0 4px;color:#1675ce;background:white;border:1px solid #dce4ec;border-radius:4px;padding:5px 8px}.basic-reports__salary-input{width:100%;min-width:90px;box-sizing:border-box;border:1px solid #abc8e5;border-radius:4px;padding:6px;color:inherit;font:inherit}.basic-reports__salary-summary{display:flex;gap:28px;flex:none;margin:0;padding:0 4px 8px;color:#54647a}

:global(html.basic-reports-document),:global(html.basic-reports-document body),:global(html.basic-reports-document #app){min-width:0}
/* 21 张基础报表共用同一主筛选行；日期控件固定宽度，窄屏只横向滚动该行。 */
.basic-reports :deep(.unified-report-query-toolbar__primary){flex-wrap:nowrap;overflow-x:auto;overflow-y:hidden;align-items:center}
.basic-reports :deep(.unified-report-query-toolbar__leading){flex-wrap:nowrap;max-width:none;flex:none}
.basic-reports :deep(.unified-report-query-toolbar__actions){flex-wrap:nowrap;width:auto;max-width:none;flex:none;margin-left:auto}
.basic-reports__period{display:block;flex:none;width:326px}
/* Keep record totals reachable on this report even when the shell hides compact pagination summaries. */
.basic-reports :deep(.table-pagination){flex:none;flex-wrap:wrap}
.basic-reports :deep(.table-pagination__summary){display:block;white-space:normal}
.basic-reports__date{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}.basic-reports__date input{min-width:0;max-width:148px;min-height:32px;border:1px solid #dce4ec;border-radius:4px;padding:3px 6px;color:inherit;background:#fff;font:inherit}.basic-reports td.is-money{color:#1f5f8b}
.basic-reports{height:100%;min-height:0;display:flex;flex-direction:column;background:#fff;padding:12px 16px;gap:12px;color:#35475e;font-size:13px;overflow:auto;letter-spacing:0}
.basic-reports__header,.basic-reports__header>div{display:flex;align-items:center;gap:10px}.basic-reports__header{justify-content:space-between;flex:none}.basic-reports h1{font-size:18px;margin:0}
.basic-reports__icon{display:inline-grid;place-items:center;width:32px;height:32px;flex:none;border:1px solid #dce4ec;border-radius:4px;background:#fff;color:#49617d;cursor:pointer}.basic-reports__icon:disabled{opacity:.5;cursor:default}.basic-reports__results{display:flex;flex-direction:column;flex:1;min-height:180px;overflow:hidden;border:1px solid #e0e7ee}.basic-reports__scroll{flex:1;min-height:0;overflow:auto;position:relative}.basic-reports table{min-width:800px;width:100%;border-collapse:separate;border-spacing:0}.basic-reports th,.basic-reports td{padding:12px;text-align:left;white-space:nowrap;border-bottom:1px solid #e7edf3;height:42px;box-sizing:border-box}.basic-reports th{position:sticky;top:0;background:#f2f6fb;color:#53667f;z-index:2}.basic-reports .basic-reports__total th{top:42px;background:#eaf3fc;color:#1f5f8b}.basic-reports td.is-fixed{z-index:1;background:#fff}.basic-reports th.is-fixed{z-index:3}.basic-reports__empty{text-align:center;padding:40px 12px;color:#8491a2}.basic-reports__error{color:#ba2929;margin:0;line-height:1.6}.basic-reports__edit{border:0;background:none;color:#1675d4;cursor:pointer}
.basic-reports__metric-summary{display:flex;flex-wrap:wrap;gap:8px 24px;flex:none;color:#54647a}.basic-reports__metric-summary strong{font-weight:500;color:#1f5f8b}
.basic-reports__snapshot{margin:0;color:#687b92;font-size:12px;line-height:1.4}
.basic-report-dialog{position:fixed;inset:0;z-index:2000;background:#17253e66;display:grid;place-items:center;padding:16px;color:#35475e;font-size:13px}.basic-report-dialog>form,.basic-report-dialog>section{width:min(580px,100%);max-height:calc(100dvh - 32px);overflow:auto;background:#fff;border-radius:6px;padding:20px;box-sizing:border-box}.basic-report-dialog header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.basic-report-dialog h2{font-size:16px;margin:0}.basic-report-dialog__fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.basic-report-dialog label{display:flex;flex-direction:column;gap:7px;min-width:0}.basic-report-dialog input,.basic-report-dialog select{width:100%;min-width:0;box-sizing:border-box;min-height:34px;border:1px solid #dce4ec;border-radius:4px;padding:5px 8px;background:#fff;color:inherit;font:inherit}.basic-report-dialog footer{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}.basic-report-dialog dt{font-weight:600;margin-top:15px}.basic-report-dialog dd{margin:6px 0;line-height:1.7;color:#687b92}
@media(max-width:600px){.basic-reports{padding:10px}.basic-report-dialog__fields{grid-template-columns:minmax(0,1fr)}}
</style>
