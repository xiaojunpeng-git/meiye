<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import BookOpen from '@lucide/vue/dist/esm/icons/book-open.mjs'
import Network from '@lucide/vue/dist/esm/icons/network.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import { requestCashierV3Action } from '@/services/cashierV3Bridge'
import {
  exportStoreBusinessReport,
  queryStoreBusinessReport,
  queryStoreBusinessReportCatalog,
  queryStoreBusinessReportScope,
  queryStoreBusinessReportPersonnel,
  saveStoreBusinessReportAnnotation,
  STORE_BUSINESS_REPORT_RUNTIME
} from '@/services/storeBusinessReportApi'

const COVERAGE_START = '2026-08-10'
const DEFAULT_LIMIT = 20
// 第一阶段门店运营报表目录。经营看板是数据入口，不属于本目录；
// 报表结果、金额和筛选能力全部由统一查询服务返回，浏览器不参与计算。
const REPORT_TABS = Object.freeze([
  { code: 'partner_item_summary', name: '合作方品项汇总' },
  { code: 'partner_item_detail', name: '合作方品项明细' },
  { code: 'member_consumption_detail', name: '会员消费明细' },
  { code: 'store_item_analysis', name: '门店品项分析' },
  { code: 'store_craftsman_consumption', name: '门店手艺人消耗' },
  { code: 'store_salesperson_performance', name: '门店销售人业绩' }
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
const scopePicker = ref({ open: false, loading: false, tree: [], allowedStoreIds: [], selectedStoreIds: [], label: '当前权限范围', selectedOrganizationKey: '', selectedOrganizationName: '', stores: [] })
const page = ref(1)
const editingPartnerRow = ref(null)
const partnerEditDraft = ref({ medical_elevation: '', medical_followup: '', expert_name: '' })
const editingMemberRow = ref(null)
const memberEditDraft = ref({ experience_cash: '', experience_payment_method: '' })
const savingEdit = ref(false)
const PARTNER_MANUAL_FIELDS = Object.freeze([
  { key: 'medical_elevation', label: '私美复诊' },
  { key: 'medical_followup', label: '私美类型' },
  { key: 'expert_name', label: '专家姓名' }
])
const partnerManualFieldKeys = PARTNER_MANUAL_FIELDS.map((field) => field.key)
const isFieldGuideOpen = ref(false)

const FIELD_LOGIC = Object.freeze({
  month: '按成交日期归入的自然月份。',
  division_name: '该笔成交发生时所属的分公司。后续组织调整不会改变历史显示。',
  store_summary: '同一月份、同一分类下的成交门店。',
  store_name: '该笔业务发生的门店。',
  store_name_snapshot: '该笔业务发生的门店。后续门店改名不会改变历史显示。',
  performance_type: '成交项目在发生时所属分类的第一层分类。',
  category: '成交项目在发生时所属的完整分类路径。',
  experience_count: '体验项目的成交数量合计；一位会员购买多份时按实际份数计算。',
  member_count: '在当前汇总范围内成交过的会员人数，同一会员重复成交只算一人。',
  consumption_amount: '项目完成服务后形成的消耗业绩金额。',
  labor_amount: '项目完成服务后分配给手艺人的劳动业绩金额。',
  sale_amount: '结账成功后，本条成交项目实际记入的成交金额。',
  member_name_snapshot: '该笔业务发生时的会员姓名。',
  member_phone: '该笔业务对应会员的手机号码。',
  business_date: '本笔业务归属的经营日期。',
  experience_project: '该成交项目是否被标记为体验项目。',
  medical_elevation: '由运营人员手动填写并保存的私美复诊信息。',
  medical_followup: '由运营人员手动填写并保存的私美类型。',
  deal_headcount: '当前成交明细是否关联会员：关联会员记为 1，未关联会员记为 0。',
  deal_project: '本条成交明细的项目或商品名称。',
  consumption: '本条成交明细完成服务后形成的消耗业绩；与“消耗金额”显示同一金额。',
  labor_fee: '本条服务分配给手艺人的手工费；与劳动业绩分别保存，金额可以不同。',
  quantity: '本条成交明细的实际成交数量。',
  deal_amount: '本条成交明细在结账成功后记入的成交金额。',
  expert_name: '由运营人员手动填写并保存的专家姓名。',
  partner_label: '该项目成交时关联的合作方。',
  remark: '该笔业务的备注；没有备注时留空。',
  consume_type: '正常成交显示“正常”；退款、作废或取消的业务显示对应类型。',
  member_name: '该笔消费对应的会员姓名。',
  consumption_detail: '本条消费明细中的项目或商品名称。',
  sales_manager_name: '本单已确认的销售经理；多人时并列显示。',
  guide_round_no: '本单选择导购后的最早导购轮次；没有导购时留空。',
  guide_names: '本单已确认的导购人员；多人时并列显示。',
  salesperson_names: '本条成交分配到的销售人员。',
  member_source: '本次成交时记录的会员来源。',
  receipt_total: '本单选择“其他收款”并成功记账的金额；当前不汇总其他支付方式。',
  partner_performance: '本条成交中分配给合作方销售人的现金业绩合计。',
  actual_cash_performance: '本单“其他收款”的成功记账金额，扣除分配给合作方销售人的业绩后得到的金额。',
  experience_cash: '体验项目默认取本单“其他收款”的成功记账金额；运营人员手动保存后以保存金额为准。',
  experience_payment_method: '体验项目默认显示本单实际使用的记账收款方式；运营人员手动保存后以保存内容为准。',
  employee_name: '业绩或手工费实际分配到的员工。',
  total_consume: '所选期间内，该手艺人每日劳动业绩的合计。',
  total_labor: '所选期间内，该手艺人每日手工费的合计。',
  total_performance: '所选期间内，该销售人每日销售业绩的合计。',
  today_cash_performance: '所选日期范围内，非体验项目结账成功后形成的现金业绩。',
  cumulative_cash_performance: '当前与“当天现金业绩”使用相同统计范围和金额。'
})

function fieldLogic(column) {
  const key = String(column?.key || '')
  const label = String(column?.label || '该字段')
  if (FIELD_LOGIC[key]) return FIELD_LOGIC[key]
  if (key.startsWith('payment_')) return `本单使用“${label}”且成功记账的金额；未使用或未成功记账时显示 0。`
  if (key.startsWith('partner_')) return `本条成交中分配给合作方销售人的现金业绩，且仅统计“${label.replace(/分成业绩$/, '')}”对应的分类或来源。`
  if (/^day_\d+_consume$/.test(key)) return `${label.replace('消耗', '')}该手艺人按项目分配到的劳动业绩合计。`
  if (/^day_\d+_labor$/.test(key)) return `${label.replace('手工', '')}该手艺人按项目分配到的手工费合计；手工费和劳动业绩可以不同。`
  if (/^day_\d+_performance$/.test(key)) return `${label.replace('业绩', '')}该销售人按成交项目分配到的销售业绩合计。`
  if (key.endsWith('_cash_performance') || key.endsWith('_partner_performance') || key.endsWith('_consume_performance')) return `“${label}”当前尚未形成独立归集金额，因此统一显示 0；这不表示相关业务没有发生。`
  return `“${label}”按当前查询条件展示本表对应的业务结果。`
}

const currentFieldExplanations = computed(() => columns.value.map((column) => ({
  key: String(column?.key || ''),
  label: String(column?.label || '未命名字段'),
  logic: fieldLogic(column)
})))
const scopeTreeOptions = computed(() => {
  const output = []
  const walk = (nodes, depth = 0, parentKey = '') => (Array.isArray(nodes) ? nodes : []).forEach((node, index) => {
    const storeId = Number(node?.store_id || (node?.node_type === 'store' ? node?.id : 0))
    const key = `${parentKey}/${node?.node_type || 'org'}-${node?.id || node?.org_id || index}`
    output.push({ node, depth, key, storeId })
    walk(node?.children, depth + 1, key)
  })
  walk(scopePicker.value.tree)
  return output
})

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
const isPlatformReport = computed(() => route.meta.platformReport === true)
const reportRuntime = computed(() => isPlatformReport.value ? STORE_BUSINESS_REPORT_RUNTIME.PLATFORM : STORE_BUSINESS_REPORT_RUNTIME.STORE)
const reportRouteName = computed(() => isPlatformReport.value ? 'cashier-v3-platform-store-business-reports' : 'cashier-v3-store-business-reports')

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
    ...(scopePicker.value.selectedStoreIds.length ? { store_ids: scopePicker.value.selectedStoreIds.join(',') } : {}),
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
    result.value = await queryStoreBusinessReport(reportParams(), reportRuntime.value)
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
  router.push({ name: reportRouteName.value, params: { report: code } })
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
    const exported = await exportStoreBusinessReport(reportParams({ page: 1, limit: 100 }), reportRuntime.value)
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

function loadReportScope() {
  scopePicker.value = { ...scopePicker.value, loading: true }
  return queryStoreBusinessReportScope(reportRuntime.value).then((data) => {
    const allowed = Array.isArray(data?.allowed_store_ids) ? data.allowed_store_ids.map(Number).filter(Boolean) : []
    scopePicker.value = { ...scopePicker.value, loading: false, tree: data?.tree || [], allowedStoreIds: allowed, selectedStoreIds: [], label: '当前权限范围', selectedOrganizationKey: '', selectedOrganizationName: '', stores: [] }
  }).catch((error) => {
    scopePicker.value = { ...scopePicker.value, loading: false, tree: [], allowedStoreIds: [], selectedStoreIds: [], selectedOrganizationKey: '', selectedOrganizationName: '', stores: [] }
    errorMessage.value = error?.message || '权限范围读取失败，请稍后重试。'
  })
}

function scopeNodeStoreIds(node) {
  const ids = []
  const walk = (item) => {
    const storeId = Number(item?.store_id || (item?.node_type === 'store' ? item?.id : 0))
    if (storeId > 0) ids.push(storeId)
    ;(Array.isArray(item?.children) ? item.children : []).forEach(walk)
  }
  walk(node)
  return [...new Set(ids)]
}

function scopeNodeStores(node) {
  const stores = []
  const walk = (item) => {
    const storeId = Number(item?.store_id || (item?.node_type === 'store' ? item?.id : 0))
    if (storeId > 0) stores.push({ id: storeId, name: item?.title || item?.name || `门店${storeId}` })
    ;(Array.isArray(item?.children) ? item.children : []).forEach(walk)
  }
  walk(node)
  return stores.filter((store, index, all) => all.findIndex((item) => item.id === store.id) === index)
}

function selectScopeOrganization(option) {
  const ids = scopeNodeStoreIds(option.node)
  if (!ids.length) return
  const name = option.node?.title || option.node?.name || '已选组织'
  scopePicker.value = {
    ...scopePicker.value,
    selectedStoreIds: ids,
    label: name,
    selectedOrganizationKey: option.key,
    selectedOrganizationName: name,
    stores: scopeNodeStores(option.node),
    open: true
  }
  page.value = 1
  loadReport()
}

function selectScopeNode(option) {
  if (option.storeId > 0) {
    selectScopeStore({ id: option.storeId, name: option.node?.title || option.node?.name || `门店${option.storeId}` })
    return
  }
  selectScopeOrganization(option)
}

function selectScopeStore(store) {
  const storeId = Number(store?.id)
  if (!storeId) return
  scopePicker.value = { ...scopePicker.value, selectedStoreIds: [storeId], label: store?.name || `门店${storeId}`, open: false }
  page.value = 1
  loadReport()
}

function chooseAllScope() {
  scopePicker.value = { ...scopePicker.value, selectedStoreIds: [], label: '当前权限范围', selectedOrganizationKey: '', selectedOrganizationName: '', stores: [], open: false }
  page.value = 1
  loadReport()
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
    let payload
    if (isPlatformReport.value) {
      payload = await queryStoreBusinessReportPersonnel({
        role: picker.role,
        keyword,
        store_ids: scopePicker.value.selectedStoreIds.join(','),
        page: 1,
        limit: 20
      }, reportRuntime.value)
    } else {
      const response = await requestCashierV3Action('query-query-entities', {
        entityType: 'person',
        selectorEntry: 'cashier',
        selectorContext: { scope: picker.scope },
        keyword,
        page: 1,
        pageSize: 20,
        silent: true
      })
      payload = response?.data?.data || response?.data || {}
    }
    const normalized = payload || {}
    const records = Array.isArray(normalized.records) ? normalized.records : []
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

const editableReport = computed(() => ['partner_item_detail', 'member_consumption_detail'].includes(activeReport.value))
function rowKey(row) {
  return String(row?.source_line_id || row?.order_id || row?.order_no_snapshot || '')
}

function annotationStoreScope(row) {
  if (!isPlatformReport.value) return {}
  const storeId = Number(row?.store_id || 0)
  if (storeId <= 0) throw new Error('当前报表行缺少门店归属，无法保存补充字段。')
  return { store_id: storeId }
}

function beginPartnerEdit(row) {
  editingPartnerRow.value = row
  partnerEditDraft.value = Object.fromEntries(PARTNER_MANUAL_FIELDS.map(({ key }) => [key, String(row?.[key] ?? '')]))
}

function cancelPartnerEdit() {
  editingPartnerRow.value = null
  partnerEditDraft.value = { medical_elevation: '', medical_followup: '', expert_name: '' }
}

async function savePartnerEdit() {
  const row = editingPartnerRow.value
  if (!row) return
  const changedFields = PARTNER_MANUAL_FIELDS.filter(({ key }) => String(row[key] ?? '') !== String(partnerEditDraft.value[key] ?? '').trim())
  if (!changedFields.length) {
    cancelPartnerEdit()
    return
  }
  savingEdit.value = true
  try {
    for (const { key } of changedFields) {
      const value = String(partnerEditDraft.value[key] ?? '').trim()
      const response = await saveStoreBusinessReportAnnotation({
        ...annotationStoreScope(row),
        report_code: 'partner_item_detail',
        subject_type: 'report_row',
        subject_key: rowKey(row),
        field_key: key,
        field_value: value,
        expected_version: Number(row[`${key}_version`] || 0),
        idempotency_key: `ui-partner-${rowKey(row)}-${key}-${Date.now()}-${Math.random().toString(16).slice(2)}`
      }, reportRuntime.value)
      row[key] = value
      row[`${key}_version`] = Number(response?.version || 1)
    }
    cancelPartnerEdit()
  } catch (error) {
    errorMessage.value = error?.message || '保存报表补充内容失败。'
  } finally { savingEdit.value = false }
}

function beginMemberEdit(row) {
  editingMemberRow.value = row
  memberEditDraft.value = {
    experience_cash: String(row?.experience_cash ?? ''),
    experience_payment_method: String(row?.experience_payment_method ?? '')
  }
}

function cancelMemberEdit() {
  editingMemberRow.value = null
  memberEditDraft.value = { experience_cash: '', experience_payment_method: '' }
}

async function saveMemberEdit() {
  const row = editingMemberRow.value
  if (!row) return
  savingEdit.value = true
  try {
    for (const field of ['experience_cash', 'experience_payment_method']) {
      const response = await saveStoreBusinessReportAnnotation({
        ...annotationStoreScope(row),
        report_code: 'member_consumption_detail',
        subject_type: 'report_row',
        subject_key: rowKey(row),
        field_key: field,
        field_value: String(memberEditDraft.value[field] ?? '').trim(),
        expected_version: Number(row[`${field}_version`] || 0),
        idempotency_key: `ui-member-${rowKey(row)}-${field}-${Date.now()}-${Math.random().toString(16).slice(2)}`
      }, reportRuntime.value)
      row[field] = String(memberEditDraft.value[field] ?? '').trim()
      row[`${field}_version`] = Number(response?.version || 1)
    }
    cancelMemberEdit()
  } catch (error) {
    errorMessage.value = error?.message || '保存体验现金业绩失败。'
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
    const [response] = await Promise.all([queryStoreBusinessReportCatalog(reportRuntime.value), loadReportScope()])
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
        <button type="button" class="button button--secondary" @click="isFieldGuideOpen = true">
          <BookOpen :size="15" aria-hidden="true" /> 列名取值来源
        </button>
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
      <div class="store-business-report__scope-picker">
        <button type="button" class="store-business-report__scope-trigger" :disabled="scopePicker.loading" @click="scopePicker.open = !scopePicker.open">
          <Network :size="16" aria-hidden="true" /> {{ scopePicker.loading ? '读取权限范围' : scopePicker.label }}
        </button>
        <section v-if="scopePicker.open" class="store-business-report__scope-panel" aria-label="组织和门店权限范围">
          <header>组织 / 门店</header>
          <div class="store-business-report__scope-panel-body">
            <div class="store-business-report__scope-tree" aria-label="组织树">
              <p v-if="scopePicker.loading" class="store-business-report__scope-empty">正在读取组织范围。</p>
              <template v-else>
                <button
                  v-for="option in scopeTreeOptions"
                  :key="option.key"
                  type="button"
                  class="store-business-report__scope-option"
                  :class="{ 'store-business-report__scope-option--selected': scopePicker.selectedOrganizationKey === option.key, 'store-business-report__scope-option--store': option.storeId > 0 }"
                  :style="{ paddingLeft: `${10 + option.depth * 18}px` }"
                  @click="selectScopeNode(option)"
                >{{ option.node?.title || option.node?.name || option.node?.label || '-' }}</button>
              </template>
            </div>
            <div class="store-business-report__scope-stores" aria-label="可选门店">
              <p class="store-business-report__scope-stores-title">{{ scopePicker.selectedOrganizationName || '选择组织后查看组织及下级门店' }}</p>
              <div v-if="scopePicker.stores.length" class="store-business-report__scope-store-list">
                <button v-for="store in scopePicker.stores" :key="store.id" type="button" :class="{ 'is-active': scopePicker.selectedStoreIds.length === 1 && scopePicker.selectedStoreIds[0] === Number(store.id) }" @click="selectScopeStore(store)">{{ store.name }}</button>
              </div>
              <p v-else class="store-business-report__scope-empty">该组织及下级暂无门店</p>
            </div>
          </div>
          <footer><button type="button" @click="chooseAllScope">当前权限范围</button><span>选择组织查询其全部下级门店；选择门店仅查询该门店。</span></footer>
        </section>
      </div>
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
            <tr v-for="(row, rowIndex) in records" :key="row.source_line_id || `${row.order_id || ''}:${rowIndex}`">
              <td v-for="column in columns" :key="column.key">
                <template v-if="activeReport === 'partner_item_detail' && editingPartnerRow === row && partnerManualFieldKeys.includes(column.key)">
                  <input v-model="partnerEditDraft[column.key]" :aria-label="column.label" class="store-business-report__inline-input" />
                </template>
                <template v-else-if="activeReport === 'member_consumption_detail' && editingMemberRow === row && ['experience_cash', 'experience_payment_method'].includes(column.key)">
                  <input v-model="memberEditDraft[column.key]" :aria-label="column.label" class="store-business-report__inline-input" :inputmode="column.key === 'experience_cash' ? 'decimal' : 'text'" />
                </template>
                <template v-else>{{ formatCell(row[column.key]) }}</template>
              </td>
              <td v-if="editableReport">
                <template v-if="activeReport === 'partner_item_detail'">
                  <button v-if="editingPartnerRow !== row" type="button" class="button button--text" @click="beginPartnerEdit(row)">编辑</button>
                  <template v-else>
                    <button type="button" class="button button--text" :disabled="savingEdit" @click="savePartnerEdit">{{ savingEdit ? '保存中' : '保存' }}</button>
                    <button type="button" class="button button--text" :disabled="savingEdit" @click="cancelPartnerEdit">取消</button>
                  </template>
                </template>
                <template v-else-if="activeReport === 'member_consumption_detail'">
                  <button v-if="editingMemberRow !== row" type="button" class="button button--text" @click="beginMemberEdit(row)">编辑</button>
                  <template v-else>
                    <button type="button" class="button button--text" :disabled="savingEdit" @click="saveMemberEdit">{{ savingEdit ? '保存中' : '保存' }}</button>
                    <button type="button" class="button button--text" :disabled="savingEdit" @click="cancelMemberEdit">取消</button>
                  </template>
                </template>
              </td>
            </tr>
            <tr v-if="!loading && !records.length"><td :colspan="columns.length" class="store-business-report__empty-cell">当前条件下暂无数据。</td></tr>
          </tbody>
        </table>
        <p v-else class="store-business-report__empty">{{ loading ? '正在读取报表数据。' : '当前报表暂无可展示的明细。' }}</p>
      </div>
      <footer v-if="total > pageSize" class="store-business-report__pagination">
        <span>共 {{ total }} 条，第 {{ page }} / {{ pageCount }} 页</span>
        <button type="button" :disabled="!canPageBack" @click="previousPage">上一页</button>
        <button type="button" :disabled="!canPageForward" @click="nextPage">下一页</button>
      </footer>
    </section>
    <div v-if="isFieldGuideOpen" class="store-business-report__modal" role="dialog" aria-modal="true" aria-label="列名取值来源">
      <section class="store-business-report__modal-card store-business-report__field-guide">
        <header>
          <div><strong>{{ currentReportName }}列名取值来源</strong><p>以下说明只解释当前表的显示口径。</p></div>
          <button type="button" class="store-business-report__modal-close" aria-label="关闭" @click="isFieldGuideOpen = false">×</button>
        </header>
        <div v-if="currentFieldExplanations.length" class="store-business-report__field-guide-list">
          <article v-for="field in currentFieldExplanations" :key="field.key" class="store-business-report__field-guide-item">
            <strong>{{ field.label }}</strong>
            <p>{{ field.logic }}</p>
          </article>
        </div>
        <p v-else class="store-business-report__empty">请先完成查询后查看列名取值来源。</p>
      </section>
    </div>
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
.store-business-report__scope-picker { position: relative; flex: none; }
.store-business-report__scope-trigger { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; border: 1px solid #dcdee2; border-radius: 4px; padding: 6px 11px; background: #fff; color: #515a6e; font: inherit; cursor: pointer; }
.store-business-report__scope-trigger:hover { border-color: #57a3f3; color: #2d8cf0; }
.store-business-report__scope-trigger:disabled { cursor: wait; opacity: .65; }
.store-business-report__scope-panel { position: absolute; z-index: 30; top: calc(100% + 6px); left: 0; width: min(480px, calc(100vw - 48px)); border: 1px solid #dcdee2; border-radius: 4px; background: #fff; box-shadow: 0 2px 12px rgba(0, 0, 0, .14); overflow: hidden; }
.store-business-report__scope-panel header { padding: 12px 14px 8px; border-bottom: 1px solid #edf0f5; color: #17233d; font-weight: 600; }
.store-business-report__scope-panel-body { display: flex; min-height: 230px; }
.store-business-report__scope-tree { position: relative; flex: 1; max-height: 260px; overflow: auto; padding: 7px 8px; border-right: 1px solid #edf0f5; }
.store-business-report__scope-option { display: block; width: 100%; min-height: 31px; border: 0; border-radius: 3px; padding-right: 8px; background: #fff; color: #515a6e; text-align: left; font: inherit; font-size: 13px; cursor: pointer; }
.store-business-report__scope-option:hover, .store-business-report__scope-option--selected { background: #edf5ff; color: #2d8cf0; }
.store-business-report__scope-option--store { color: #657386; }
.store-business-report__scope-stores { width: 205px; max-height: 260px; overflow: auto; padding: 10px 12px; }
.store-business-report__scope-stores-title { min-height: 18px; margin: 0 0 8px; color: #666; font-size: 12px; line-height: 18px; }
.store-business-report__scope-store-list button { display: block; width: 100%; min-height: 31px; border: 0; border-radius: 3px; padding: 5px 8px; background: transparent; color: #515a6e; text-align: left; font: inherit; font-size: 13px; cursor: pointer; }
.store-business-report__scope-store-list button:hover, .store-business-report__scope-store-list button.is-active { background: #edf5ff; color: #2d8cf0; }
.store-business-report__scope-empty { margin: 0; padding: 8px 2px; color: #bbb; font-size: 12px; line-height: 1.55; }
.store-business-report__scope-panel footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 14px; border-top: 1px solid #edf0f5; color: #999; font-size: 12px; line-height: 1.45; }
.store-business-report__scope-panel footer button { flex: none; border: 0; padding: 0; background: transparent; color: #2d8cf0; font: inherit; font-size: 12px; cursor: pointer; }
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
.store-business-report__inline-input { min-width: 120px; min-height: 30px; box-sizing: border-box; border: 1px solid #b8c7d9; border-radius: 5px; padding: 5px 7px; font: inherit; color: #303946; }
.store-business-report__field-guide { width: min(720px, calc(100vw - 32px)); max-height: min(760px, calc(100vh - 32px)); }
.store-business-report__field-guide header { align-items: flex-start; }
.store-business-report__field-guide header p { margin: 4px 0 0; color: #7a8699; font-size: 12px; font-weight: 400; }
.store-business-report__field-guide-list { display: grid; gap: 0; overflow: auto; padding: 2px 18px 16px; }
.store-business-report__field-guide-item { display: grid; grid-template-columns: minmax(130px, 30%) 1fr; gap: 16px; border-bottom: 1px solid #edf1f5; padding: 12px 0; }
.store-business-report__field-guide-item strong { color: #26364a; font-size: 13px; }
.store-business-report__field-guide-item p { color: #536174; font-size: 13px; line-height: 1.6; }
.store-business-report__empty, .store-business-report__empty-cell { min-height: 130px; color: #8290a2; font-size: 13px; text-align: center; }
.store-business-report__empty { display: grid; place-items: center; }
.store-business-report__empty-cell { padding: 40px; }
.store-business-report__pagination { display: flex; justify-content: flex-end; align-items: center; gap: 8px; padding: 12px 16px; color: #708096; font-size: 12px; }
.store-business-report__pagination button { min-height: 30px; border: 1px solid #d7e0eb; border-radius: 5px; padding: 4px 9px; background: #fff; color: #445365; cursor: pointer; }
.store-business-report__pagination button:disabled { cursor: not-allowed; opacity: .45; }
@media (max-width: 1100px) { .store-business-report__metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 760px) { .store-business-report { padding: 12px; } .store-business-report__header { display: grid; } .store-business-report__actions { flex-wrap: wrap; } .store-business-report__metrics, .store-business-report__overview-grid { grid-template-columns: 1fr; } .store-business-report__filters label { flex: 1 1 160px; } .store-business-report__field-guide-item { grid-template-columns: 1fr; gap: 5px; } }
</style>
