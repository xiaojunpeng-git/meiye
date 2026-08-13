<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import { requestCashierV3Action } from '@/services/cashierV3Bridge'
import {
  exportStoreBusinessReport,
  queryStoreBusinessReport,
  queryStoreBusinessReportCatalog
  , saveStoreBusinessReportAnnotation
} from '@/services/storeBusinessReportApi'

const COVERAGE_START = '2026-08-10'
const DEFAULT_LIMIT = 20
// 第一阶段固定为七张业务报表。经营看板是数据入口，不属于本目录；
// 报表结果、金额和筛选能力全部由统一查询服务返回，浏览器不参与计算。
const REPORT_TABS = Object.freeze([
  { code: 'partner_item_summary', name: '合作方品项汇总' },
  { code: 'partner_item_detail', name: '合作方品项明细' },
  { code: 'member_consumption_detail', name: '会员消费明细' },
  { code: 'store_item_analysis', name: '门店品项分析' },
  { code: 'store_craftsman_consumption', name: '门店手艺人消耗' },
  { code: 'store_salesperson_performance', name: '门店销售人业绩' },
  { code: 'market_performance', name: '市场业绩' }
])
const PERSON_FILTERS = Object.freeze([
  ['salesperson_id', '销售人编号'],
  ['sales_manager_id', '销售经理编号'],
  ['guide_id', '导购编号'],
  ['craftsman_id', '手艺人编号']
])

const route = useRoute()
const router = useRouter()
const catalog = ref([])
const activeReport = ref('partner_item_summary')
const result = ref({})
const loading = ref(false)
const exporting = ref(false)
const errorMessage = ref('')
const startDate = ref(COVERAGE_START)
const endDate = ref(today())
const categoryId = ref('')
const categoryPath = ref('')
const productType = ref('')
const partnerName = ref('')
const personFilters = ref({})
const isAdvancedFiltersOpen = ref(false)
const personnelPicker = ref({
  open: false,
  role: '',
  label: '',
  scope: '',
  keyword: '',
  loading: false,
  records: [],
  error: ''
})
const page = ref(1)
const editing = ref(null)
const editValue = ref('')
const editField = ref('remark')
const savingEdit = ref(false)

const serverCatalogByCode = computed(() => new Map(
  catalog.value.filter((item) => item && item.code).map((item) => [item.code, item])
))
const reportTabs = computed(() => REPORT_TABS.map((tab) => ({
  ...tab,
  ...(serverCatalogByCode.value.get(tab.code) || {})
})))
const columns = computed(() => Array.isArray(result.value.columns) ? result.value.columns : [])
// 列和列分组由统一报表服务返回。支付方式、合作方、门店、康美单和系统
// 操作等动态列只负责展示，不在浏览器端重新计算或拼接金额。
const columnGroups = computed(() => {
  const rawGroups = result.value.column_groups || result.value.columnGroups
  if (Array.isArray(rawGroups) && rawGroups.length) {
    const byKey = new Map(columns.value.map((column) => [String(column.key), column]))
    const groupLabelByKey = new Map()
    rawGroups.forEach((group) => {
      const keys = Array.isArray(group?.column_keys)
        ? group.column_keys
        : Array.isArray(group?.columnKeys)
          ? group.columnKeys
          : Array.isArray(group?.columns)
            ? group.columns.map((column) => typeof column === 'string' ? column : column?.key)
            : []
      const label = String(group?.label || group?.name || '')
      keys.forEach((key) => { if (byKey.has(String(key))) groupLabelByKey.set(String(key), label) })
    })
    const groups = []
    columns.value.forEach((column) => {
      const label = groupLabelByKey.get(String(column.key)) || ''
      const current = groups[groups.length - 1]
      if (!current || current.label !== label) groups.push({ label, columns: [] })
      groups[groups.length - 1].columns.push(column)
    })
    if (groups.length) return groups
  }
  const groups = []
  let groupedCount = 0
  let current = null
  columns.value.forEach((column) => {
    const label = String(column?.group_label || column?.groupLabel || column?.group || '').trim()
    if (!label) {
      current = null
      return
    }
    groupedCount += 1
    if (!current || current.label !== label) {
      current = { label, columns: [] }
      groups.push(current)
    }
    current.columns.push(column)
  })
  return groupedCount === columns.value.length && groups.length ? groups : []
})
const hasGroupedColumns = computed(() => columnGroups.value.length > 0)
const records = computed(() => Array.isArray(result.value.records) ? result.value.records : [])
const pendingMetrics = computed(() => Array.isArray(result.value.pending_metrics) ? result.value.pending_metrics : [])
const total = computed(() => Number(result.value.total || 0))
const pageSize = computed(() => Number(result.value.page_size || DEFAULT_LIMIT))
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))
const canPageBack = computed(() => page.value > 1 && !loading.value)
const canPageForward = computed(() => page.value < pageCount.value && !loading.value)
const currentReportName = computed(() => reportTabs.value.find((item) => item.code === activeReport.value)?.name || '门店运营报表')
const currentReportDescription = computed(() => reportTabs.value.find((item) => item.code === activeReport.value)?.description || '')

function today() {
  const date = new Date()
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 10)
}

function reportParams(overrides = {}) {
  return {
    report: activeReport.value,
    start_date: startDate.value,
    end_date: endDate.value,
    category_id: categoryId.value,
    category_path: categoryPath.value,
    product_type: productType.value,
    partner_name: partnerName.value,
    ...personFilters.value,
    page: page.value,
    limit: DEFAULT_LIMIT,
    ...overrides
  }
}

async function loadReport() {
  if (!activeReport.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    result.value = await queryStoreBusinessReport(reportParams())
  } catch (error) {
    result.value = {}
    errorMessage.value = error?.message || '经营报表读取失败，请稍后重试。'
  } finally {
    loading.value = false
  }
}

function selectReport(code) {
  if (code === activeReport.value) return
  page.value = 1
  router.push({ name: 'cashier-v3-store-business-reports', params: { report: code } })
}

function query() {
  page.value = 1
  return loadReport()
}

function previousPage() {
  if (!canPageBack.value) return
  page.value -= 1
  loadReport()
}

function nextPage() {
  if (!canPageForward.value) return
  page.value += 1
  loadReport()
}

function csvValue(value) {
  const text = String(value ?? '')
  return /[",\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text
}

async function exportReport() {
  exporting.value = true
  errorMessage.value = ''
  try {
    // 导出仍由服务端使用同一权限和固定字段白名单，浏览器只负责下载结果。
    const exported = await exportStoreBusinessReport(reportParams({ page: 1, limit: 100 }))
    const exportColumns = Array.isArray(exported.columns) ? exported.columns : []
    const exportRecords = Array.isArray(exported.records) ? exported.records : []
    const lines = [
      exportColumns.map((column) => csvValue(column.label)).join(','),
      ...exportRecords.map((row) => exportColumns.map((column) => csvValue(row?.[column.key])).join(','))
    ]
    const url = URL.createObjectURL(new Blob([`\ufeff${lines.join('\n')}`], { type: 'text/csv;charset=utf-8' }))
    const link = document.createElement('a')
    link.href = url
    link.download = String(exported.filename || `${currentReportName.value}.csv`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (error) {
    errorMessage.value = error?.message || '经营报表导出失败，请稍后重试。'
  } finally {
    exporting.value = false
  }
}

function formatCell(value) {
  if (value === null || value === undefined || value === '') return '-'
  if (value === true) return '是'
  if (value === false) return '否'
  return String(value)
}

function syncActiveReportFromRoute() {
  const requested = String(route.params.report || '').trim()
  activeReport.value = REPORT_TABS.some((item) => item.code === requested)
    ? requested
    : REPORT_TABS[0].code
}

function clearAdvancedFilters() {
  categoryId.value = ''
  categoryPath.value = ''
  productType.value = ''
  partnerName.value = ''
  personFilters.value = {}
}

function openPersonnelPicker(role, label, scope) {
  personnelPicker.value = {
    open: true,
    role,
    label,
    scope,
    keyword: '',
    loading: false,
    records: [],
    error: ''
  }
}

async function searchPersonnel() {
  const picker = personnelPicker.value
  const keyword = String(picker.keyword || '').trim()
  if (keyword.length < 2) {
    personnelPicker.value = { ...picker, records: [], error: '请输入至少 2 个字符后搜索。' }
    return
  }
  personnelPicker.value = { ...picker, loading: true, records: [], error: '' }
  try {
    const response = await requestCashierV3Action('query-query-entities', {
      entityType: 'person',
      selectorEntry: 'cashier',
      selectorContext: { scope: picker.scope },
      keyword,
      page: 1,
      pageSize: 20,
      silent: true
    })
    const payload = response?.data?.data || response?.data || {}
    const records = Array.isArray(payload.records) ? payload.records : []
    personnelPicker.value = { ...personnelPicker.value, loading: false, records }
  } catch (error) {
    personnelPicker.value = {
      ...personnelPicker.value,
      loading: false,
      records: [],
      error: error?.message || '人员搜索失败，请稍后重试。'
    }
  }
}

function choosePersonnel(record) {
  const picker = personnelPicker.value
  const id = String(record?.employeeId || record?.id || '').trim()
  if (!id) return
  personFilters.value = { ...personFilters.value, [picker.role]: id }
  personnelPicker.value = { ...picker, open: false }
}

const editableReport = computed(() => ['partner_item_detail', 'market_performance'].includes(activeReport.value))
const editableFields = computed(() => activeReport.value === 'market_performance'
  ? [{ key: 'remark', label: '备注' }, { key: 'walk_in_manual_count', label: '手动进店人次' }]
  : [{ key: 'remark', label: '备注' }, { key: 'expert_name', label: '专家' }])
function beginEdit(row) {
  if (!editableReport.value) return
  editing.value = row
  editValue.value = ''
  editField.value = editableFields.value[0]?.key || 'remark'
}
async function saveEdit() {
  if (!editing.value || !editValue.value.trim()) return
  savingEdit.value = true
  try {
    await saveStoreBusinessReportAnnotation({
      report_code: activeReport.value,
      subject_type: 'report_row',
      subject_key: String(editing.value.order_no_snapshot || editing.value.source_line_id || `${editing.value.store_id || ''}:${editing.value.business_source_primary_id || ''}`),
      field_key: editField.value,
      field_value: editValue.value.trim(),
      expected_version: 0,
      idempotency_key: `ui-${activeReport.value}-${Date.now()}-${Math.random().toString(16).slice(2)}`
    })
    editing.value = null
    editValue.value = ''
  } catch (error) {
    errorMessage.value = error?.message || '保存报表补充内容失败。'
  } finally { savingEdit.value = false }
}

watch(() => route.params.report, () => {
  syncActiveReportFromRoute()
  page.value = 1
  loadReport()
})

onMounted(async () => {
  syncActiveReportFromRoute()
  try {
    const response = await queryStoreBusinessReportCatalog()
    catalog.value = Array.isArray(response) ? response : []
    await loadReport()
  } catch (error) {
    errorMessage.value = error?.message || '经营报表目录读取失败，请稍后重试。'
  }
})
</script>

<template>
  <main class="store-business-report" aria-label="门店运营报表">
    <header class="store-business-report__header">
      <div>
        <p class="store-business-report__eyebrow">数据</p>
        <h1>门店运营报表</h1>
      </div>
      <div class="store-business-report__actions">
        <button type="button" class="button button--secondary" :disabled="loading" @click="loadReport">
          <RefreshCw :size="15" aria-hidden="true" /> 刷新
        </button>
        <button type="button" class="button button--primary" :disabled="exporting" @click="exportReport">
          <Download :size="15" aria-hidden="true" /> {{ exporting ? '正在导出' : '导出' }}
        </button>
      </div>
    </header>

    <nav class="store-business-report__tabs" aria-label="门店运营报表功能">
      <button
        v-for="item in reportTabs"
        :key="item.code"
        type="button"
        class="store-business-report__tab"
        :class="{ 'store-business-report__tab--active': activeReport === item.code }"
        :aria-current="activeReport === item.code ? 'page' : undefined"
        @click="selectReport(item.code)"
      >{{ item.name }}</button>
    </nav>

    <section class="store-business-report__filters" aria-label="报表查询条件">
      <label>开始日期<input v-model="startDate" type="date" :min="COVERAGE_START" :max="endDate" /></label>
      <label>结束日期<input v-model="endDate" type="date" :min="startDate" :max="today()" /></label>
      <button type="button" class="button button--secondary" @click="isAdvancedFiltersOpen = !isAdvancedFiltersOpen">{{ isAdvancedFiltersOpen ? '收起筛选' : '更多筛选' }}</button>
      <button type="button" class="button button--primary" :disabled="loading" @click="query">{{ loading ? '查询中' : '查询' }}</button>
    </section>

    <section v-if="isAdvancedFiltersOpen" class="store-business-report__filters store-business-report__filters--advanced" aria-label="报表高级筛选">
      <label>商品分类编号<input v-model.trim="categoryId" inputmode="numeric" placeholder="由分类选择器带入" /></label>
      <label>分类路径<input v-model.trim="categoryPath" placeholder="例如：六维 / 自营" /></label>
      <label>商品类型
        <select v-model="productType"><option value="">全部类型</option><option value="card">卡项</option><option value="project">项目</option><option value="product">产品</option></select>
      </label>
      <label>合作方<input v-model.trim="partnerName" placeholder="合作方名称" /></label>
      <label v-for="entry in PERSON_FILTERS" :key="entry[0]">{{ entry[1] }}
        <button
          type="button"
          class="store-business-report__person-picker-trigger"
          @click="openPersonnelPicker(entry[0], entry[1], entry[0] === 'sales_manager_id' ? 'group_sales_managers' : entry[0] === 'guide_id' ? 'group_guides' : 'sales_performance_assignees')"
        >{{ personFilters[entry[0]] || '搜索并选择' }}</button>
      </label>
      <button type="button" class="button button--secondary" @click="clearAdvancedFilters">清空筛选</button>
    </section>

    <p v-if="currentReportDescription" class="store-business-report__description">{{ currentReportDescription }}</p>

    <p v-if="errorMessage" class="store-business-report__error" role="alert">{{ errorMessage }}</p>

    <section class="store-business-report__meta" aria-label="报表数据状态">
      <span>覆盖起始日：{{ result.coverage_start || COVERAGE_START }}</span>
      <span>数据更新至：{{ result.data_as_of || '-' }}</span>
      <span>口径版本：{{ result.metric_version || 'store-unified-report-v1' }}</span>
      <span :class="result.aggregation_caught_up === false ? 'is-pending' : 'is-ready'">聚合：{{ result.aggregation_caught_up === false ? '追赶中' : '已追平' }}</span>
    </section>

    <section class="store-business-report__panel store-business-report__panel--table">
      <div v-if="pendingMetrics.length" class="store-business-report__pending"><strong>口径待确认</strong><span v-for="item in pendingMetrics" :key="item">{{ item }}</span></div>
      <div class="store-business-report__table-scroll">
        <table v-if="columns.length">
          <thead v-if="hasGroupedColumns">
            <tr>
              <th v-for="group in columnGroups" :key="group.label" :colspan="group.columns.length">{{ group.label }}</th>
              <th v-if="editableReport" rowspan="2">操作</th>
            </tr>
            <tr><th v-for="column in columns" :key="column.key">{{ column.label }}</th></tr>
          </thead>
          <thead v-else><tr><th v-for="column in columns" :key="column.key">{{ column.label }}</th><th v-if="editableReport">操作</th></tr></thead>
          <tbody>
            <tr v-for="(row, rowIndex) in records" :key="row.id || row.order_id || `${rowIndex}`"><td v-for="column in columns" :key="column.key">{{ formatCell(row[column.key]) }}</td><td v-if="editableReport"><button type="button" class="button button--text" @click="beginEdit(row)">编辑</button></td></tr>
            <tr v-if="!loading && !records.length"><td :colspan="columns.length" class="store-business-report__empty-cell">当前条件下暂无数据。</td></tr>
          </tbody>
        </table>
        <p v-else class="store-business-report__empty">{{ loading ? '正在读取报表数据。' : '当前报表暂无可展示的明细。' }}</p>
      </div>
      <div v-if="editing" class="store-business-report__editor" aria-label="编辑报表补充字段">
        <strong>编辑补充字段</strong>
        <select v-model="editField"><option v-for="field in editableFields" :key="field.key" :value="field.key">{{ field.label }}</option></select>
        <input v-model="editValue" maxlength="65535" placeholder="请输入内容后保存" />
        <button type="button" class="button button--primary" :disabled="savingEdit || !editValue.trim()" @click="saveEdit">{{ savingEdit ? '保存中' : '保存' }}</button>
        <button type="button" class="button button--secondary" @click="editing = null">取消</button>
      </div>
      <footer v-if="total > pageSize" class="store-business-report__pagination">
        <span>共 {{ total }} 条，第 {{ page }} / {{ pageCount }} 页</span>
        <button type="button" :disabled="!canPageBack" @click="previousPage">上一页</button>
        <button type="button" :disabled="!canPageForward" @click="nextPage">下一页</button>
      </footer>
    </section>
    <div v-if="personnelPicker.open" class="store-business-report__modal" role="dialog" aria-modal="true" :aria-label="personnelPicker.label">
      <section class="store-business-report__modal-card">
        <header>
          <strong>搜索{{ personnelPicker.label }}</strong>
          <button type="button" class="store-business-report__modal-close" aria-label="关闭" @click="personnelPicker.open = false">×</button>
        </header>
        <div class="store-business-report__person-search">
          <input v-model.trim="personnelPicker.keyword" type="search" placeholder="输入姓名、工号后搜索" @keyup.enter="searchPersonnel" />
          <button type="button" class="button button--primary" :disabled="personnelPicker.loading" @click="searchPersonnel">{{ personnelPicker.loading ? '搜索中' : '搜索' }}</button>
        </div>
        <p v-if="personnelPicker.error" class="store-business-report__error" role="alert">{{ personnelPicker.error }}</p>
        <div v-if="!personnelPicker.loading && personnelPicker.records.length" class="store-business-report__person-results">
          <button v-for="record in personnelPicker.records" :key="`${record.employeeId || record.id}-${record.storeId || ''}`" type="button" @click="choosePersonnel(record)">
            <span>{{ record.name || record.staffName || '-' }}</span>
            <small>{{ record.storeName || '集团人员' }} · {{ record.staffNo || record.employeeId || record.id }}</small>
          </button>
        </div>
        <p v-else-if="!personnelPicker.loading && !personnelPicker.error" class="store-business-report__empty">输入关键词后搜索，默认不加载人员。</p>
      </section>
    </div>
  </main>
</template>

<style scoped>
.store-business-report { display: grid; align-content: start; gap: 14px; min-width: 0; height: 100%; box-sizing: border-box; padding: 20px; overflow: auto; background: #f5f7fa; color: #252a34; }
.store-business-report__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; }
.store-business-report__eyebrow { margin: 0 0 4px; color: #1769aa; font-size: 12px; font-weight: 700; letter-spacing: 0; }
.store-business-report h1, .store-business-report h2, .store-business-report p { margin: 0; }
.store-business-report h1 { font-size: 21px; line-height: 1.25; }
.store-business-report__actions { display: flex; flex: none; gap: 8px; }
.store-business-report__actions .button { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; }
.store-business-report__tabs { display: flex; min-height: 43px; gap: 2px; overflow-x: auto; overflow-y: hidden; border-bottom: 1px solid #d9e1eb; background: #fff; padding: 0 12px; }
.store-business-report__tab { flex: none; min-height: 42px; padding: 0 15px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: #697586; font: inherit; font-size: 13px; cursor: pointer; }
.store-business-report__tab:hover { color: #1769aa; background: #f7fbff; }
.store-business-report__tab--active { border-bottom-color: #1769aa; color: #1769aa; font-weight: 700; }
.store-business-report__filters, .store-business-report__panel { border: 1px solid #dde4ed; border-radius: 8px; background: #fff; }
.store-business-report__filters { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; padding: 13px 16px; }
.store-business-report__filters--advanced { border-top: 0; border-radius: 0 0 8px 8px; padding-top: 2px; }
.store-business-report__filters label { display: grid; gap: 5px; min-width: 140px; color: #687586; font-size: 12px; }
.store-business-report__filters input, .store-business-report__filters select { min-height: 34px; box-sizing: border-box; border: 1px solid #d8e0ea; border-radius: 6px; padding: 6px 8px; background: #fff; color: #252a34; font: inherit; }
.store-business-report__person-picker-trigger { min-height: 34px; border: 1px solid #d8e0ea; border-radius: 6px; padding: 6px 8px; background: #fff; color: #1769aa; text-align: left; font: inherit; cursor: pointer; }
.store-business-report__modal { position: fixed; z-index: 100; inset: 0; display: grid; place-items: center; padding: 20px; background: rgba(24, 39, 58, .38); }
.store-business-report__modal-card { width: min(520px, 100%); max-height: min(620px, 90vh); overflow: auto; border-radius: 10px; padding: 18px; background: #fff; box-shadow: 0 16px 50px rgba(24, 39, 58, .2); }
.store-business-report__modal-card header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.store-business-report__modal-close { border: 0; background: transparent; color: #657386; font-size: 24px; cursor: pointer; }
.store-business-report__person-search { display: flex; gap: 8px; }
.store-business-report__person-search input { flex: 1; min-height: 34px; box-sizing: border-box; border: 1px solid #d8e0ea; border-radius: 6px; padding: 6px 8px; font: inherit; }
.store-business-report__person-results { display: grid; gap: 6px; margin-top: 12px; }
.store-business-report__person-results button { display: flex; justify-content: space-between; gap: 12px; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; background: #fff; color: #283548; text-align: left; cursor: pointer; }
.store-business-report__person-results button:hover { border-color: #1769aa; background: #f7fbff; }
.store-business-report__person-results small { color: #7a8798; }
.store-business-report__filters .button { min-height: 34px; }
.store-business-report__error { border: 1px solid #ffcaca; border-radius: 7px; padding: 10px 14px; background: #fff5f5; color: #b42318; font-size: 13px; }
.store-business-report__description { color: #687586; font-size: 12px; }
.store-business-report__meta { display: flex; flex-wrap: wrap; gap: 8px 18px; color: #738095; font-size: 12px; }
.store-business-report__meta .is-pending { color: #9a6509; }
.store-business-report__meta .is-ready { color: #227447; }
.store-business-report__metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
.store-business-report__metric { min-height: 94px; box-sizing: border-box; border: 1px solid #dde4ed; border-radius: 8px; padding: 14px; background: #fff; }
.store-business-report__metric span, .store-business-report__metric small { color: #728094; font-size: 12px; }
.store-business-report__metric strong { display: block; margin-top: 9px; color: #1f4f7a; font-size: 24px; line-height: 1; }
.store-business-report__metric small { margin-left: 4px; font-size: 12px; font-weight: 400; }
.store-business-report__overview-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.store-business-report__panel { min-width: 0; padding: 16px; }
.store-business-report__panel header { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
.store-business-report__panel h2 { font-size: 15px; }
.store-business-report__panel header span { color: #738095; font-size: 12px; }
.store-business-report__simple-table { margin-top: 14px; }
.store-business-report__simple-table > div { display: flex; justify-content: space-between; gap: 12px; border-top: 1px solid #edf1f5; padding: 10px 2px; color: #647184; font-size: 13px; }
.store-business-report__simple-table strong { color: #26364a; }
.store-business-report__panel--table { padding: 0; }
.store-business-report__pending { display: flex; flex-wrap: wrap; gap: 8px 12px; border-bottom: 1px solid #f2d49a; padding: 10px 16px; background: #fff9ed; color: #9a6509; font-size: 12px; }
.store-business-report__table-scroll { min-width: 0; overflow: auto; }
.store-business-report table { width: 100%; border-collapse: collapse; white-space: nowrap; font-size: 13px; }
.store-business-report th, .store-business-report td { border-bottom: 1px solid #edf1f5; padding: 11px 12px; text-align: left; }
.store-business-report th { background: #fafbfd; color: #667386; font-size: 12px; font-weight: 600; }
.store-business-report td { color: #303946; }
.store-business-report__empty, .store-business-report__empty-cell { min-height: 130px; color: #8290a2; font-size: 13px; text-align: center; }
.store-business-report__empty { display: grid; place-items: center; }
.store-business-report__empty-cell { padding: 40px; }
.store-business-report__pagination { display: flex; justify-content: flex-end; align-items: center; gap: 8px; padding: 12px 16px; color: #708096; font-size: 12px; }
.store-business-report__pagination button { min-height: 30px; border: 1px solid #d7e0eb; border-radius: 5px; padding: 4px 9px; background: #fff; color: #445365; cursor: pointer; }
.store-business-report__pagination button:disabled { cursor: not-allowed; opacity: .45; }
@media (max-width: 1100px) { .store-business-report__metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 760px) { .store-business-report { padding: 12px; } .store-business-report__header { display: grid; } .store-business-report__actions { flex-wrap: wrap; } .store-business-report__metrics, .store-business-report__overview-grid { grid-template-columns: 1fr; } .store-business-report__filters label { flex: 1 1 160px; } }
</style>
