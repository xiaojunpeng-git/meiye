<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import BookOpen from '@lucide/vue/dist/esm/icons/book-open.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import ChevronDown from '@lucide/vue/dist/esm/icons/chevron-down.mjs'
import ChevronUp from '@lucide/vue/dist/esm/icons/chevron-up.mjs'
import ChevronRight from '@lucide/vue/dist/esm/icons/chevron-right.mjs'
import Network from '@lucide/vue/dist/esm/icons/network.mjs'
import { requestCashierV3Action } from '@/services/cashierV3Bridge'
import {
  PHASE_FOUR_ANNUAL_REPORT_CODES,
  PHASE_FOUR_CROSS_END_REPORT_TABS,
  PHASE_FOUR_REPORT_TABS
} from '@/constants/phaseFourReports'
import { PHASE_SIX_REPORTS, PHASE_SIX_REPORT_CODES, PHASE_SIX_CROSS_END_REPORT_TABS } from '@/constants/phaseSixReports'
import {
  exportStoreBusinessReport,
  queryStoreBusinessReport,
  queryStoreBusinessReportCatalog,
  queryStoreBusinessReportScope,
  queryStoreBusinessReportPersonnel,
  queryStoreBusinessReportCategories,
  saveStoreBusinessReportAnnotation,
  STORE_BUSINESS_REPORT_RUNTIME
} from '@/services/storeBusinessReportApi'

const LEGACY_COVERAGE_START = '2026-08-10'
const DEFAULT_LIMIT = 20
// 门店运营报表目录。经营看板是数据入口，不属于本目录；
// 报表结果、金额和筛选能力全部由统一查询服务返回，浏览器不参与计算。
const REPORT_TABS = Object.freeze([
  { code: 'partner_item_summary', name: '合作方品项汇总' },
  { code: 'partner_item_detail', name: '合作方品项明细' },
  { code: 'member_consumption_detail', name: '会员消费明细' },
  { code: 'store_item_analysis', name: '门店品项分析' },
  { code: 'store_craftsman_consumption', name: '门店手艺人消耗' },
  { code: 'store_salesperson_performance', name: '门店销售人业绩' },
  { code: 'market_performance', name: '市场业绩表' },
  { code: 'market_detail', name: '市场明细表' },
  { code: 'member_visit_analysis', name: '会员进店分析表' },
  { code: 'member_visit_annual_summary', name: '会员进店年度汇总表' },
  { code: 'field_acquisition_detail', name: '地推拓客明细表' },
  { code: 'field_acquisition_summary', name: '地推拓客汇总表' },
  { code: 'cross_industry_customer_summary', name: '异业收客汇总表' },
  { code: 'cross_industry_customer_detail', name: '异业收客明细表' },
  { code: 'new_customer_analysis_summary', name: '新客汇总表' },
  { code: 'new_customer_analysis', name: '新客明细表' },
  { code: 'salesperson_large_order_statistics', name: '销售人生美大单统计表' },
  { code: 'store_refund_ledger', name: '院店退款台账' },
  { code: 'phase_six_other_multi_payment', name: '其他多收款业绩表' },
  { code: 'phase_six_salary_summary', name: '员工薪资汇总月报表' },
  { code: 'phase_six_salary_detail', name: '员工薪资明细月报表' },
  // The confirmed cross-end report is the only fourth-stage report exposed
  // through the store Data menu; the remaining 26 stay platform-only.
  { code: 'six_dimension_analysis', name: '六维数据分析表' }
])
const SIX_DIMENSION_REPORT_TABS = Object.freeze([
  { code: 'six_dimension_item_deal_analysis', name: '品项成交分析表' },
  { code: 'six_dimension_cash_consumption_analysis', name: '现金消费分析表' },
  { code: 'six_dimension_consumption_refund_detail', name: '消耗及退款明细' },
  { code: 'six_dimension_performance_deal', name: '业绩成交表' },
  { code: 'six_dimension_performance_distribution', name: '业绩分布表' },
  { code: 'six_dimension_performance_market_distribution', name: '业绩市场分布表' }
])
const PHASE_SIX_REPORT_TABS = PHASE_SIX_REPORTS
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
const startDate = ref(LEGACY_COVERAGE_START)
const endDate = ref(today() < LEGACY_COVERAGE_START ? LEGACY_COVERAGE_START : today())
const selectedMonth = ref(today().slice(0, 7))
const selectedYear = ref(today().slice(0, 4))
const categoryId = ref('')
const categoryPath = ref('')
const productType = ref('')
const partnerName = ref('')
const personFilters = ref({})
const dynamicFilters = ref({})
const categoryOptions = ref([])
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
const expandedScopeKeys = ref(new Set())
const page = ref(1)
const tabsElement = ref(null)
const tabsExpanded = ref(false)
const tabsOverflow = ref(false)
const editingRow = ref(null)
const editDraft = ref({})
const savingEdit = ref(false)
const PARTNER_MANUAL_FIELDS = Object.freeze([
  { key: 'medical_elevation', label: '私美复诊' },
  { key: 'medical_followup', label: '私美类型' },
  { key: 'expert_name', label: '专家姓名' }
])
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
  receipt_total: '本行销售明细分摊到的所有成功记账收款方式金额合计；未成功的收款不计入。',
  partner_performance: '本条成交各合作方分类分成业绩的合计；每个分类均按成交时的现金业绩乘以当时设置的合作方默认比例计算。',
  actual_cash_performance: '本行销售明细分摊到的成功记账收款金额，扣除本条成交的合作方业绩后得到的金额。',
  experience_cash: '体验项目默认取本行销售明细分摊到的成功记账收款金额；运营人员手动保存后以保存金额为准。',
  experience_payment_method: '体验项目默认显示本行销售明细实际分摊到的记账收款方式；运营人员手动保存后以保存内容为准。',
  employee_name: '业绩或手工费实际分配到的员工。',
  total_consume: '所选期间内，该手艺人每日劳动业绩的合计。',
  total_labor: '所选期间内，该手艺人每日手工费的合计。',
  total_performance: '所选期间内，该销售人每日销售业绩的合计。',
  today_cash_performance: '所选日期范围内，非体验项目结账成功后形成的现金业绩。',
  cumulative_cash_performance: '当前与“当天现金业绩”使用相同统计范围和金额。',
  order_no_snapshot: '结账成功后生成的业务单据编号。',
  dimension: '成交时选择并保存的来源渠道。',
  walk_in: '运营人员对该笔业务手动登记的进店次数；未登记时为 0。',
  visits: '该会员在当前查询日期内已完成护理的次数。',
  effective_people: '同一门店、同一来源下，当前查询日期内累计现金业绩达到门槛的不同会员数；A 类来源为 1000 元，其他来源为 500 元。',
  amount: '本行成交明细分摊到的成功记账收款金额。',
  registered_date: '该笔业务归入报表的经营日期。',
  reviewer: '该笔业务的审核人员；未审核时显示“-”。',
  reviewed_at: '该笔业务的审核时间；未审核时显示“-”。',
  creator_name: '完成这笔业务制单的人员。',
  created_at: '这笔业务实际制单的时间。',
  total_visits: '该会员在当前统计年度内完成护理的总次数。',
  annual_cash: '该会员在当前统计年度内分摊到的所有成功记账收款金额。',
  source: '该笔首次疗程卡成交时选择并保存的来源渠道。',
  row_label: '统计行对应的门店或汇总名称。',
  year: '当前报表按此自然年度统计。',
  card_sale_date: '该会员首次办理疗程卡的经营日期。',
  first_visit_date: '该会员首次完成护理的经营日期；尚未护理时显示“-”。',
  annual_total: '该会员在首次办卡后首个自然年度内分摊到的成功记账收款金额。',
  first_visit_at: '该会员第一次完成护理的实际时间。',
  visit_over_one_hour: '运营人员对该会员首次到店后停留满一小时的手动登记；未登记时为 0。',
  fourth_and_above: '该会员在当前范围内完成的第四次及之后护理次数。',
  cash_1: '该会员第一次成交分摊到的成功记账收款金额。',
  cash_2: '该会员第二次成交分摊到的成功记账收款金额。',
  cash_3: '该会员第三次成交分摊到的成功记账收款金额。',
  customer_acquired_at: '运营人员登记该会员由异业渠道收客的日期。',
  partner_store_name: '运营人员登记的异业合作门店名称。',
  remaining_service_count: '截至查询日，该会员已生效卡项中尚可使用的服务次数。',
  remaining_service_amount: '截至查询日，尚未使用服务按原成交分摊金额计算的金额。',
  first_500: '该会员首次成交分摊到的成功记账收款达到 500 元时显示对应金额，否则为 0。',
  reached_2400: '该会员累计成功记账收款达到 2400 元时显示对应累计金额，否则为 0。',
  full_payment: '该笔业务结账成功且没有产生欠款时，分摊到本销售明细的成功记账收款金额。',
  reward: '异业收客已达到奖励条件后，按已确认规则记录的奖励金额。',
  customer: '该笔业务发生时关联的会员姓名。',
  age: '会员资料中已登记的年龄；未登记时留空。',
  care_project: '该笔成交关联的护理项目或商品名称。',
  craftsman: '完成该笔护理并形成劳动业绩的手艺人。',
  experience_card_amount: '该笔体验卡或项目成交时记入的成交金额。',
  care_duration: '运营人员手动填写的该次护理时长。',
  guide_effective_count: '该笔业务已确认并生效的导购有效人次。',
  guide_performance_round: '该笔业务中导购参与的轮次；没有导购时留空。',
  deposit_payment: '该笔业务产生欠款时，在本次结账成功记入本销售明细的收款金额。',
  cleared_payment: '该笔欠款后续补交成功时，按原销售明细分摊回来的收款金额。',
  system_name: '当前报表所属系统名称。',
  member: '该行统计对应的会员姓名。',
  daily_cash: '销售人在当日分配到的生美成交成功记账收款金额。',
  cumulative_cash: '同一会员、同一销售人截至当日累计分配到的生美成交成功记账收款金额。',
  share_30000_before: '该会员生美累计金额达到 3 万前的分成前金额；尚未形成权威分成计划时留空。',
  share_30000_after: '该会员生美累计金额达到 3 万后的分成后金额；尚未形成权威分成计划时留空。',
  share_50000_before: '该会员生美累计金额达到 5 万前的分成前金额；尚未形成权威分成计划时留空。',
  market: '该笔退款发生时所属的市场或业务区域。',
  refund_date: '退款申请提交的日期。',
  original_sale_date: '被退款原销售明细的成交日期。',
  card_name: '被退款的卡项或商品名称。',
  refund_items: '本次退款涉及的项目或商品。',
  refund_amount: '退款成功后实际退回的金额。',
  refund_remark: '本次退款登记的原因或备注。'
})

function fieldLogic(column) {
  const key = String(column?.key || '')
  const label = String(column?.label || '该字段')
  const serverLogic = String(column?.source_explanation || column?.logic || column?.explanation || column?.source_description || '').trim()
  if (serverLogic) return serverLogic
  if (FIELD_LOGIC[key]) return FIELD_LOGIC[key]
  if (key.startsWith('payment_')) return `本单使用“${label}”且成功记账的金额；未使用或未成功记账时显示 0。`
  if (key.startsWith('partner_category_')) return `普通商品和项目按自身成交分类归集；卡项按卡内项目的成交分类归集，卡内有多个分类时按各项目配置金额分摊。该分类的现金业绩乘以成交时设置的合作方默认比例，得到“${label}”；后续修改分类或比例不会改写已成交数据。`
  if (/^channel_\d+_effective$/.test(key)) return '同一门店、同一渠道中，在当前日期内累计现金业绩达到有效门槛的不同会员人数：A 类渠道为 1000 元，其他渠道为 500 元。点击后仅显示这些会员的成交明细。'
  if (/^day_\d+_consume$/.test(key)) return `${label.replace('消耗', '')}该手艺人按项目分配到的劳动业绩合计。`
  if (/^day_\d+_labor$/.test(key)) return `${label.replace('手工', '')}该手艺人按项目分配到的手工费合计；手工费和劳动业绩可以不同。`
  if (/^day_\d+_performance$/.test(key)) return `${label.replace('业绩', '')}该销售人按成交项目分配到的销售业绩合计。`
  if (/^month_\d+_count$/.test(key)) return `该会员在${label}对应自然月内形成的导购有效人次。`
  if (/^month_\d+_(full|deposit|cleared)$/.test(key)) return `该会员在${label.replace(/全款|定金|清款/, '')}对应自然月内的${label}，按销售明细分摊后的成功记账收款金额统计。`
  if (/^month_\d+$/.test(key)) return `该会员在${label}对应自然月内分摊到的成功记账收款金额。`
  if (/^store_\d+$/.test(key)) return `该门店在当前统计年度内完成护理的次数。`
  if (/^share_50000_after_\d+$/.test(key)) return `${label}对应的兑现阶段；尚未形成权威分成计划时留空。`
  if (key.endsWith('_cash_performance') || key.endsWith('_partner_performance') || key.endsWith('_consume_performance')) return `“${label}”当前尚未形成独立归集金额，因此统一显示 0；这不表示相关业务没有发生。`
  return `“${label}”按当前查询条件展示本表对应的业务结果。`
}

function columnDisplayLabel(column) {
  const labels = declaredHeaderPath(column).filter(Boolean)
  return labels.join('/') || '未命名字段'
}

const currentFieldExplanations = computed(() => columns.value.map((column) => ({
  key: String(column?.key || ''),
  label: columnDisplayLabel(column),
  logic: fieldLogic(column)
})))
const scopeTreeOptions = computed(() => {
  const output = []
  const walk = (nodes, depth = 0, parentKey = '') => (Array.isArray(nodes) ? nodes : []).forEach((node, index) => {
    const storeId = Number(node?.store_id || (node?.node_type === 'store' ? node?.id : 0))
    const key = `${parentKey}/${node?.node_type || 'org'}-${node?.id || node?.org_id || index}`
    const hasChildren = Array.isArray(node?.children) && node.children.length > 0
    const isExpanded = expandedScopeKeys.value.has(key)
    output.push({ node, depth, key, storeId, hasChildren, isExpanded })
    if (hasChildren && isExpanded) walk(node.children, depth + 1, key)
  })
  walk(scopePicker.value.tree)
  return output
})
const serverCatalogByCode = computed(() => new Map(
  catalog.value.filter((item) => item && item.code).map((item) => [item.code, item])
))
function requestedReportCode() {
  const fromRoute = String(route.params.report || '').trim()
  if (fromRoute) return fromRoute
  // Some embedded Vue 2 -> Vue 3 iframe transitions retain the hash URL while
  // Vue Router has not hydrated its optional parameter yet. The URL is still
  // the authoritative navigation input, so recover only the declared code.
  const matched = String(window.location.hash || '').match(/\/platform\/reports\/([^/?#]+)/)
  if (!matched) return ''
  try { return decodeURIComponent(matched[1]).trim() } catch (_) { return String(matched[1] || '').trim() }
}
function isPlatformRuntimeRoute() {
  // The platform embeds this shared Vue 3 report page in an iframe. A directly
  // opened cashier URL must remain a store-runtime request, so the store API can
  // reject all platform-only phase-four report codes.
  return (route.meta.platformReport === true || String(route.path || '').startsWith('/platform/'))
    && window.top !== window
}
function isSixDimensionRoute() {
  const requested = requestedReportCode()
  return SIX_DIMENSION_REPORT_TABS.some((tab) => tab.code === requested)
}
function isPhaseFourRoute() {
  const requested = requestedReportCode()
  return PHASE_FOUR_REPORT_TABS.some((tab) => tab.code === requested)
}
function isPhaseSixRoute() {
  return PHASE_SIX_REPORT_CODES.includes(requestedReportCode())
}
const blocksDirectPlatformOnlyReport = computed(() => {
  const requested = requestedReportCode()
  const phaseFourBlocked = PHASE_FOUR_REPORT_TABS.some((tab) => tab.code === requested)
    && !PHASE_FOUR_CROSS_END_REPORT_TABS.some((tab) => tab.code === requested)
  const phaseSixBlocked = PHASE_SIX_REPORT_CODES.includes(requested)
    && !['phase_six_other_multi_payment', 'phase_six_salary_summary', 'phase_six_salary_detail'].includes(requested)
  return !isPlatformRuntimeRoute() && (phaseFourBlocked || phaseSixBlocked)
})
const allowedReportTabs = computed(() => {
  const requested = requestedReportCode()
  if (blocksDirectPlatformOnlyReport.value) return []
  const tabs = isSixDimensionRoute()
    ? SIX_DIMENSION_REPORT_TABS
    : isPhaseFourRoute()
      ? (PHASE_FOUR_CROSS_END_REPORT_TABS.some((tab) => tab.code === requested)
          ? PHASE_FOUR_CROSS_END_REPORT_TABS
          : isPlatformRuntimeRoute() ? PHASE_FOUR_REPORT_TABS.filter((tab) => !PHASE_FOUR_CROSS_END_REPORT_TABS.some((crossEnd) => crossEnd.code === tab.code)) : PHASE_FOUR_CROSS_END_REPORT_TABS)
      : isPhaseSixRoute()
        ? (isPlatformRuntimeRoute() ? PHASE_SIX_REPORT_TABS : PHASE_SIX_CROSS_END_REPORT_TABS)
        : REPORT_TABS
  if (!serverCatalogByCode.value.size) return tabs
  const authorized = tabs.filter((tab) => serverCatalogByCode.value.has(tab.code))
  return authorized.length ? authorized : tabs
})
const reportTabs = computed(() => allowedReportTabs.value.map((tab) => ({
  // 历史目录名称可能仍保留在服务器配置中，门店端以已确认的标准名称为准。
  ...(serverCatalogByCode.value.get(tab.code) || {}),
  ...tab
})))
const isFirstPhaseReport = computed(() => REPORT_TABS.slice(0, 6).some((item) => item.code === activeReport.value))
const isSixDimensionReport = computed(() => SIX_DIMENSION_REPORT_TABS.some((item) => item.code === activeReport.value))
const isPhaseFourReport = computed(() => PHASE_FOUR_REPORT_TABS.some((item) => item.code === activeReport.value))
const isPhaseSixReport = computed(() => PHASE_SIX_REPORT_CODES.includes(activeReport.value))
const usesNaturalMonthFilter = computed(() => ['six_dimension_performance_distribution', 'operations_beauty_item'].includes(activeReport.value))
const columns = computed(() => Array.isArray(result.value.columns) ? result.value.columns : [])
const filterSchema = computed(() => {
  const schema = result.value.filter_schema || result.value.filterSchema
  return (Array.isArray(schema) ? schema : []).filter((field) => {
    const key = String(field?.key || '')
    const type = String(field?.type || '')
    return key
      && !['organization', 'organization_id', 'organization_store_scope', 'store', 'store_id', 'store_ids', 'start_date', 'end_date', 'date_range', 'month'].includes(key)
      && !['organization_store_scope', 'date_range', 'month', 'year'].includes(type)
  })
})
const usesAnnualYearFilter = computed(() => PHASE_FOUR_ANNUAL_REPORT_CODES.includes(activeReport.value)
  || filterSchema.value.some((field) => String(field?.type || '') === 'year'))
const editableFields = computed(() => {
  const declared = result.value.editable_fields || result.value.editableFields
  const entries = Array.isArray(declared) ? declared : []
  const normalized = entries.map((field) => typeof field === 'string'
    ? { key: field, label: columns.value.find((column) => column.key === field)?.label || field, type: 'text' }
    : { ...field, key: String(field?.key || field?.field_key || '') }
  ).filter((field) => field.key)
  if (normalized.length) return normalized
  // 第四阶段在列契约上声明可编辑字段。页面不按列名猜测，而是严格读取
  // manual_input 的字段键、类型和稳定主题类型。
  const fromColumns = columns.value
    .filter((column) => column?.manual_input || column?.manualInput)
    .map((column) => {
      const manual = column.manual_input || column.manualInput || {}
      const valueType = String(manual.value_type || manual.valueType || 'text')
      return {
        key: String(manual.field_key || manual.fieldKey || column.key),
        label: String(column.label || ''),
        type: valueType === 'integer_cents' ? 'money' : valueType,
        integer: valueType === 'integer',
        subject_type: String(manual.subject_type || manual.subjectType || ''),
        subject_key_template: String(manual.subject_key_template || manual.subjectKeyTemplate || '')
      }
    })
    .filter((field) => field.key)
  if (fromColumns.length) return fromColumns
  if (activeReport.value === 'partner_item_detail') return PARTNER_MANUAL_FIELDS
  if (activeReport.value === 'member_consumption_detail') return [
    { key: 'experience_cash', label: '体验现金业绩', type: 'money' },
    { key: 'experience_payment_method', label: '体验收款方式', type: 'text' }
  ]
  return []
})
const editableFieldByKey = computed(() => new Map(editableFields.value.map((field) => [field.key, field])))
const editableReport = computed(() => editableFields.value.length > 0)
const topSummaries = computed(() => {
  const items = result.value.top_summaries || result.value.topSummaries
  return Array.isArray(items) ? items : []
})
const summaryRow = computed(() => {
  const row = result.value.summary_row || result.value.summaryRow
  return row && typeof row === 'object' && !Array.isArray(row) ? row : null
})
const usesFixedTableLayout = computed(() => {
  const layout = result.value.table_layout || result.value.tableLayout || {}
  return activeReport.value === 'market_detail' || layout.fixed === true
})
const tableHeaderHeight = computed(() => `${headerRows.value.length * 40}px`)
const fixedLeftColumns = computed(() => columns.value.filter((column) => String(column?.fixed || '') === 'left'))
const lastFixedLeftColumnKey = computed(() => String(fixedLeftColumns.value.at(-1)?.key || ''))
const RESULT_COLUMN_KEYS = new Set(['actual_cash_performance', 'experience_cash', 'experience_payment_method'])
const MANUAL_COLUMN_KEYS = new Set(['medical_elevation', 'medical_followup', 'expert_name', 'remark', 'complaint_count'])

function tableHeaderTone(column) {
  const key = String(column?.key || '')
  if (key.startsWith('item_analysis_')) {
    if (key.includes('_share_') || key.endsWith('_share')) return 'partner'
    if (key.includes('_actual_')) return 'result'
    if (key.includes('_consume_') || key.endsWith('_consume')) return 'consumption'
    if (key.includes('_cash_') || key.endsWith('_cash')) return 'payment'
  }
  if (key.startsWith('payment_') || key === 'receipt_total') return 'payment'
  if (key.startsWith('partner_category_') || key === 'partner_performance') return 'partner'
  if (RESULT_COLUMN_KEYS.has(key)) return 'result'
  if (MANUAL_COLUMN_KEYS.has(key) || editableFieldByKey.value.has(key)) return 'manual'
  return 'basic'
}

function derivedHeaderGroupLabel(column) {
  return ({
    basic: '基础信息',
    payment: '支付现金业绩方式',
    partner: '合作方分成业绩',
    result: '经营结果',
    consumption: '消耗业绩',
    manual: '手动补充'
  })[tableHeaderTone(column)]
}

function headerColumnClasses(column) {
  const tone = tableHeaderTone(column)
  return [
    'store-business-report__header-column',
    `store-business-report__header-column--${tone}`,
    {
      'store-business-report__header-column--group-end': reportColumnGroupEndKeys.value.has(String(column.key)),
      'store-business-report__column--sticky-left': String(column?.fixed || '') === 'left',
      'store-business-report__column--fixed-last': String(column.key) === lastFixedLeftColumnKey.value
    }
  ]
}

function headerCellClasses(cell) {
  if (cell?.column) return headerColumnClasses(cell.column)
  const tone = String(cell?.tone || tableHeaderTone(cell?.columns?.[0]))
  const allFixedLeft = cell?.columns?.length > 0 && cell.columns.every((column) => String(column?.fixed || '') === 'left')
  const lastKey = String(cell?.columns?.at(-1)?.key || '')
  return [
    'store-business-report__header-group',
    `store-business-report__header-group--${tone}`,
    {
      'store-business-report__column--sticky-left': allFixedLeft,
      'store-business-report__column--fixed-last': allFixedLeft && lastKey === lastFixedLeftColumnKey.value
    }
  ]
}

function headerCellStyle(cell, rowIndex) {
  const style = cell?.column
    ? fixedColumnStyle(cell.column)
    : cell?.columns?.length && cell.columns.every((column) => String(column?.fixed || '') === 'left')
      ? fixedColumnStyle(cell.columns[0])
      : {}
  return { ...style, '--report-header-row-index': String(rowIndex) }
}

function cellClasses(column) {
  return {
    'store-business-report__cell--group-end': reportColumnGroupEndKeys.value.has(String(column.key)),
    'store-business-report__column--sticky-left': String(column?.fixed || '') === 'left',
    'store-business-report__column--fixed-last': String(column.key) === lastFixedLeftColumnKey.value
  }
}

function fixedColumnStyle(column) {
  const declaredWidth = Number(column?.width || column?.fixed_width || column?.fixedWidth || 0)
  if (String(column?.fixed || '') !== 'left') {
    return declaredWidth > 0 ? { minWidth: `${declaredWidth}px`, width: `${declaredWidth}px` } : {}
  }
  const fixedColumns = columns.value.filter((item) => String(item?.fixed || '') === 'left')
  let left = 0
  for (const item of fixedColumns) {
    if (String(item.key) === String(column.key)) break
    left += Number(item.fixed_width || item.fixedWidth || 100)
  }
  const width = Number(column.fixed_width || column.fixedWidth || 100)
  return { left: `${left}px`, minWidth: `${width}px`, width: `${width}px` }
}

// 列和列分组由统一报表服务返回。支付方式、合作方、门店、康美单和系统
// 操作等动态列只负责展示，不在浏览器端重新计算或拼接金额。
const columnGroups = computed(() => {
  const rawGroups = result.value.column_groups || result.value.columnGroups
  if (Array.isArray(rawGroups) && rawGroups.length) {
    const byKey = new Map(columns.value.map((column) => [String(column.key), column]))
    const groupMetaByKey = new Map()
    rawGroups.forEach((group) => {
      const keys = Array.isArray(group?.column_keys)
        ? group.column_keys
        : Array.isArray(group?.columnKeys)
          ? group.columnKeys
          : Array.isArray(group?.columns)
            ? group.columns.map((column) => typeof column === 'string' ? column : column?.key)
            : []
      const meta = {
        label: String(group?.label || group?.name || ''),
        tone: String(group?.tone || ''),
        rowspan: Number(group?.rowspan || group?.row_span || 0),
        colspan: Number(group?.colspan || group?.col_span || 0)
      }
      keys.forEach((key) => { if (byKey.has(String(key))) groupMetaByKey.set(String(key), meta) })
    })
    const groups = []
    columns.value.forEach((column) => {
      const declaredRowspan = Number(column?.header_rowspan || column?.headerRowspan || 0)
      const meta = groupMetaByKey.get(String(column.key)) || (declaredRowspan > 1
        ? { label: String(column.label || ''), tone: String(column?.tone || ''), rowspan: declaredRowspan, colspan: 1 }
        : { label: derivedHeaderGroupLabel(column), tone: '', rowspan: 0, colspan: 0 })
      const label = meta.label
      const current = groups[groups.length - 1]
      if (!current || current.label !== label || current.tone !== meta.tone || current.rowspan !== meta.rowspan || current.colspan !== meta.colspan) {
        groups.push({ label, tone: meta.tone, rowspan: meta.rowspan, colspan: meta.colspan, columns: [] })
      }
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
function declaredHeaderPath(column) {
  const raw = column?.header_path || column?.headerPath || column?.header_levels || column?.headerLevels
  const declared = Array.isArray(raw) ? raw : typeof raw === 'string' ? raw.split('/').map((item) => item.trim()) : []
  const labels = declared.map((item) => String(item?.label || item?.name || item || '').trim()).filter(Boolean)
  if (labels.length) return labels
  const group = String(column?.group_label || column?.groupLabel || column?.group || '').trim()
  return group ? [group, String(column?.label || '')] : [String(column?.label || '')]
}

function headerPathPart(column, depth) {
  const labels = declaredHeaderPath(column)
  const label = String(labels[depth] || '')
  // 叶子单元格用稳定列键区分，即使两个列名相同也不能被浏览器错误合并。
  return { label, identity: depth === labels.length - 1 ? `column:${String(column?.key || '')}` : `group:${label}`, leaf: depth === labels.length - 1 }
}

function sameHeaderPrefix(left, right, depth) {
  for (let index = 0; index <= depth; index += 1) {
    if (headerPathPart(left, index).identity !== headerPathPart(right, index).identity) return false
  }
  return true
}

// 后端可用 header_path/header_levels 声明任意层级表头；旧契约的 group_label
// 自动映射为两层。前端只排版，绝不以列名推断业绩分组或重新计算指标。
function buildHeaderRows(reportColumns) {
  if (!reportColumns.length) return []
  const depth = Math.max(...reportColumns.map((column) => declaredHeaderPath(column).length), 1)
  const rows = Array.from({ length: depth }, () => [])
  for (let level = 0; level < depth; level += 1) {
    let index = 0
    while (index < reportColumns.length) {
      const column = reportColumns[index]
      const path = declaredHeaderPath(column)
      if (level >= path.length) {
        index += 1
        continue
      }
      const part = headerPathPart(column, level)
      let end = index + 1
      while (end < reportColumns.length) {
        const candidate = reportColumns[end]
        const candidatePath = declaredHeaderPath(candidate)
        if (level >= candidatePath.length || headerPathPart(candidate, level).leaf !== part.leaf || !sameHeaderPrefix(column, candidate, level)) break
        end += 1
      }
      const leaf = part.leaf
      rows[level].push({
        label: part.label,
        column: leaf ? column : null,
        columns: reportColumns.slice(index, end),
        tone: String(column?.tone || ''),
        startIndex: index,
        colspan: leaf ? 1 : end - index,
        rowspan: leaf ? depth - level : 1
      })
      index = end
    }
  }
  return rows
}

function explicitHeaderRows(rawRows, reportColumns) {
  if (!Array.isArray(rawRows) || !rawRows.length || !reportColumns.length) return []
  const byKey = new Map(reportColumns.map((column, index) => [String(column?.key || ''), { column, index }]))
  const normalized = rawRows.map((rawRow) => {
    const cells = Array.isArray(rawRow) ? rawRow : Array.isArray(rawRow?.cells) ? rawRow.cells : Array.isArray(rawRow?.columns) ? rawRow.columns : []
    return cells.map((rawCell) => {
      const keys = Array.isArray(rawCell?.column_keys)
        ? rawCell.column_keys
        : Array.isArray(rawCell?.columnKeys)
          ? rawCell.columnKeys
          : rawCell?.column_key || rawCell?.columnKey || rawCell?.key
            ? [rawCell.column_key || rawCell.columnKey || rawCell.key]
            : []
      const resolved = keys.map((key) => byKey.get(String(key))).filter(Boolean)
      if (!resolved.length) return null
      const first = resolved[0]
      const leaf = resolved.length === 1 && Number(rawCell?.colspan || rawCell?.col_span || 1) <= 1
      return {
        label: String(rawCell?.label || rawCell?.name || first.column?.label || ''),
        column: leaf ? first.column : null,
        columns: resolved.map((item) => item.column),
        tone: String(rawCell?.tone || ''),
        startIndex: first.index,
        colspan: Number(rawCell?.colspan || rawCell?.col_span || resolved.length || 1),
        rowspan: Number(rawCell?.rowspan || rawCell?.row_span || 1)
      }
    }).filter(Boolean)
  }).filter((row) => row.length)
  return normalized.length ? normalized : []
}

const headerRows = computed(() => {
  const raw = result.value.header_rows || result.value.headerRows || result.value.header_levels || result.value.headerLevels
  const declared = explicitHeaderRows(raw, columns.value)
  return declared.length ? declared : buildHeaderRows(columns.value)
})
const reportColumnGroupEndKeys = computed(() => {
  const ends = new Set()
  columnGroups.value.forEach((group, index) => {
    const last = group.columns[group.columns.length - 1]
    if (index < columnGroups.value.length - 1 && Number(group?.rowspan) <= 1 && last) ends.add(String(last.key))
  })
  return ends
})
const records = computed(() => Array.isArray(result.value.records) ? result.value.records : [])
const pendingMetrics = computed(() => Array.isArray(result.value.pending_metrics) ? result.value.pending_metrics : [])
const total = computed(() => Number(result.value.total || 0))
const pageSize = computed(() => Number(result.value.page_size || DEFAULT_LIMIT))
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))
const canPageBack = computed(() => page.value > 1 && !loading.value)
const canPageForward = computed(() => page.value < pageCount.value && !loading.value)
const currentReportName = computed(() => reportTabs.value.find((item) => item.code === activeReport.value)?.name || '门店运营报表')
const currentReportDescription = computed(() => reportTabs.value.find((item) => item.code === activeReport.value)?.description || '')
const isPlatformReport = computed(isPlatformRuntimeRoute)
const reportRuntime = computed(() => isPlatformReport.value ? STORE_BUSINESS_REPORT_RUNTIME.PLATFORM : STORE_BUSINESS_REPORT_RUNTIME.STORE)
const reportRouteName = computed(() => isPlatformReport.value ? 'cashier-v3-platform-store-business-reports' : 'cashier-v3-store-business-reports')

function today() {
  const date = new Date()
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 10)
}

function monthBounds(month) {
  const normalized = /^\d{4}-\d{2}$/.test(String(month || '')) ? String(month) : today().slice(0, 7)
  const [year, monthNumber] = normalized.split('-').map(Number)
  const lastDay = new Date(year, monthNumber, 0).getDate()
  return { start: `${normalized}-01`, end: `${normalized}-${String(lastDay).padStart(2, '0')}` }
}

function yearBounds(year) {
  const normalized = /^\d{4}$/.test(String(year || '')) ? String(year) : today().slice(0, 4)
  return { start: `${normalized}-01-01`, end: `${normalized}-12-31` }
}

function reportParams(overrides = {}) {
  const dates = usesAnnualYearFilter.value
    ? yearBounds(selectedYear.value)
    : usesNaturalMonthFilter.value
    ? monthBounds(selectedMonth.value)
    : { start: startDate.value, end: endDate.value }
  return {
    report: activeReport.value,
    start_date: dates.start,
    end_date: dates.end,
    ...(usesAnnualYearFilter.value ? { year: selectedYear.value } : {}),
    ...(usesNaturalMonthFilter.value ? { month: selectedMonth.value } : {}),
    category_id: categoryId.value,
    category_path: categoryPath.value,
    product_type: productType.value,
    partner_name: partnerName.value,
    ...personFilters.value,
    ...dynamicFilters.value,
    ...(scopePicker.value.selectedStoreIds.length ? { store_ids: scopePicker.value.selectedStoreIds.join(',') } : {}),
    page: page.value,
    limit: DEFAULT_LIMIT,
    ...overrides
  }
}

async function loadReport() {
  if (blocksDirectPlatformOnlyReport.value) {
    result.value = {}
    errorMessage.value = '该报表仅支持平台端访问'
    return
  }
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

function updateTabsLayout() {
  const element = tabsElement.value
  if (!element) return
  const buttons = [...element.querySelectorAll('.store-business-report__tab')]
  const firstTop = buttons[0]?.offsetTop
  tabsOverflow.value = buttons.some((button) => button.offsetTop > firstTop)
  const active = buttons.find((button) => button.getAttribute('aria-current') === 'page')
  if (active && firstTop !== undefined && active.offsetTop > firstTop) tabsExpanded.value = true
}

function toggleTabs() {
  tabsExpanded.value = !tabsExpanded.value
}

function query() {
  page.value = 1
  return syncCurrentFiltersToRoute()
}

function currentRouteQuery() {
  const params = reportParams({ page: undefined, limit: undefined })
  const query = {}
  Object.entries(params).forEach(([key, value]) => {
    if (['report', 'page', 'limit'].includes(key) || value === undefined || value === null || value === '') return
    query[key] = String(value)
  })
  if (scopePicker.value.selectedStoreIds.length) query.scope_label = scopePicker.value.label
  return query
}

function syncCurrentFiltersToRoute() {
  const target = {
    name: reportRouteName.value,
    params: { report: activeReport.value },
    query: currentRouteQuery()
  }
  if (router.resolve(target).fullPath === route.fullPath) return loadReport()
  return router.replace(target)
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

function exportHeaderRows(exportColumns) {
  return buildHeaderRows(exportColumns).map((headerRow) => {
    const values = Array.from({ length: exportColumns.length }, () => '')
    headerRow.forEach((cell) => {
      values[cell.startIndex] = cell.label
    })
    return values
  })
}

async function exportReport() {
  exporting.value = true
  errorMessage.value = ''
  try {
    // 导出仍由服务端使用同一权限和固定字段白名单，浏览器只负责下载结果。
    const exported = await exportStoreBusinessReport(reportParams({ page: 1, limit: 100 }), reportRuntime.value)
    const exportColumns = Array.isArray(exported.columns) ? exported.columns : []
    const exportRecords = Array.isArray(exported.records) ? exported.records : []
    const summaryRow = exported.summary_row || exported.summaryRow
    const headers = exportHeaderRows(exportColumns)
    const lines = []
    headers.forEach((header) => lines.push(header.map(csvValue).join(',')))
    if (summaryRow && typeof summaryRow === 'object' && Object.keys(summaryRow).length) {
      lines.push(exportColumns.map((column) => csvValue(summaryRow[column.key] ?? '-')).join(','))
    }
    exportRecords.forEach((row) => lines.push(exportColumns.map((column) => csvValue(row?.[column.key])).join(',')))
    lines.push('')
    lines.push([csvValue('列名取值来源'), csvValue(`口径版本：${exported.metric_version || result.value.metric_version || '-'}`)].join(','))
    exportColumns.forEach((column) => lines.push([csvValue(columnDisplayLabel(column)), csvValue(fieldLogic(column))].join(',')))
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
  const requested = requestedReportCode()
  activeReport.value = allowedReportTabs.value.some((item) => item.code === requested)
    ? requested
    : String(allowedReportTabs.value[0]?.code || '')
  if (isFirstPhaseReport.value && startDate.value < LEGACY_COVERAGE_START) startDate.value = LEGACY_COVERAGE_START
  if (!isFirstPhaseReport.value && !isSixDimensionReport.value && startDate.value === LEGACY_COVERAGE_START) startDate.value = `${today().slice(0, 4)}-01-01`
  if (isSixDimensionReport.value && startDate.value === LEGACY_COVERAGE_START) startDate.value = `${today().slice(0, 4)}-01-01`
  if (usesAnnualYearFilter.value && !/^\d{4}$/.test(selectedYear.value)) selectedYear.value = today().slice(0, 4)
}

function syncFiltersFromRoute() {
  const query = route.query || {}
  if (query.start_date) startDate.value = String(query.start_date)
  if (query.end_date) endDate.value = String(query.end_date)
  if (query.month && /^\d{4}-\d{2}$/.test(String(query.month))) selectedMonth.value = String(query.month)
  else if (usesNaturalMonthFilter.value && query.start_date) selectedMonth.value = String(query.start_date).slice(0, 7)
  if (query.year && /^\d{4}$/.test(String(query.year))) selectedYear.value = String(query.year)
  else if (usesAnnualYearFilter.value && query.start_date) selectedYear.value = String(query.start_date).slice(0, 4)
  const storeIds = String(query.store_ids || '').split(',').map(Number).filter(Boolean)
  if (storeIds.length) {
    scopePicker.value = { ...scopePicker.value, selectedStoreIds: storeIds, label: String(query.scope_label || '下钻范围') }
  }
  const reserved = new Set(['start_date', 'end_date', 'month', 'year', 'store_ids', 'scope_label'])
  dynamicFilters.value = Object.fromEntries(Object.entries(query)
    .filter(([key, value]) => !reserved.has(key) && value !== undefined && value !== '')
    .map(([key, value]) => [key, String(value)]))
}

function clearAdvancedFilters() {
  categoryId.value = ''
  categoryPath.value = ''
  productType.value = ''
  partnerName.value = ''
  personFilters.value = {}
  dynamicFilters.value = {}
}

function loadReportScope() {
  scopePicker.value = { ...scopePicker.value, loading: true }
  return queryStoreBusinessReportScope(reportRuntime.value).then((data) => {
    const allowed = Array.isArray(data?.allowed_store_ids) ? data.allowed_store_ids.map(Number).filter(Boolean) : []
    const requested = String(route.query?.store_ids || '').split(',').map(Number).filter((id) => allowed.includes(id))
    scopePicker.value = {
      ...scopePicker.value,
      loading: false,
      tree: data?.tree || [],
      allowedStoreIds: allowed,
      selectedStoreIds: requested,
      label: requested.length ? String(route.query?.scope_label || '下钻范围') : '当前权限范围',
      selectedOrganizationKey: '',
      selectedOrganizationName: '',
      stores: []
    }
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

function toggleScopeNode(option) {
  if (!option?.hasChildren) return
  const next = new Set(expandedScopeKeys.value)
  if (next.has(option.key)) next.delete(option.key)
  else next.add(option.key)
  expandedScopeKeys.value = next
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
  syncCurrentFiltersToRoute()
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
  syncCurrentFiltersToRoute()
}

function chooseAllScope() {
  scopePicker.value = { ...scopePicker.value, selectedStoreIds: [], label: '当前权限范围', selectedOrganizationKey: '', selectedOrganizationName: '', stores: [], open: false }
  page.value = 1
  syncCurrentFiltersToRoute()
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

function rowKey(row) {
  return String(row?.annotation_subject_key || row?.source_line_id || row?.order_line_id || row?.order_id || row?.order_no_snapshot || '')
}

function annotationStoreScope(row) {
  if (!isPlatformReport.value) return {}
  if (String(row?.annotation_subject_type || '') === 'phase_four_month') return { store_id: 0 }
  const storeId = Number(row?.store_id || 0)
  if (storeId <= 0) throw new Error('当前报表行缺少门店归属，无法保存补充字段。')
  return { store_id: storeId }
}

function beginEdit(row) {
  if (!rowKey(row)) {
    errorMessage.value = '当前报表行缺少明细级唯一标识，无法保存手动字段。'
    return
  }
  editingRow.value = row
  editDraft.value = Object.fromEntries(editableFields.value.map(({ key }) => [key, String(row?.[key] ?? '')]))
}

function cancelEdit() {
  editingRow.value = null
  editDraft.value = {}
}

function manualFieldInputType(field) {
  const type = String(field?.type || field?.input_type || '').toLowerCase()
  if (['number', 'integer', 'money', 'decimal'].includes(type)) return 'number'
  if (['date', 'datetime-local'].includes(type)) return type
  return 'text'
}

function manualFieldInputMode(field) {
  if (field?.integer === true) return 'numeric'
  return ['money', 'decimal', 'number', 'integer'].includes(String(field?.type || field?.input_type || '').toLowerCase()) ? 'decimal' : undefined
}

function manualFieldStep(field) {
  return field?.integer === true || String(field?.type || field?.input_type || '').toLowerCase() === 'integer' ? 1 : 'any'
}

function manualFieldIsSelect(field) {
  return String(field?.type || field?.input_type || '').toLowerCase() === 'select' && Array.isArray(field?.options)
}

function manualFieldValueForSave(field, value) {
  const text = String(value ?? '').trim()
  if (text === '') return ''
  if (String(field?.type || field?.input_type || '').toLowerCase() !== 'money') return text
  if (!/^-?\d+(?:\.\d{1,2})?$/.test(text)) throw new Error('金额最多保留两位小数。')
  const negative = text.startsWith('-')
  const [whole, decimal = ''] = text.replace(/^-/, '').split('.')
  const cents = Number(whole) * 100 + Number(`${decimal}00`.slice(0, 2))
  return String(negative ? -cents : cents)
}

function filterFieldOptions(field) {
  if (String(field?.type || '') === 'category_tree') return categoryOptions.value
  return Array.isArray(field?.options) ? field.options : []
}

async function loadCategoryOptions() {
  if (!isPlatformReport.value || categoryOptions.value.length) return
  const rows = await queryStoreBusinessReportCategories(reportRuntime.value)
  const uniquePaths = new Map()
  ;(Array.isArray(rows) ? rows : []).forEach((row) => {
    const path = String(row?.category_path || row?.category_name || row?.name || '').trim()
    if (path && !uniquePaths.has(path)) uniquePaths.set(path, { value: path, label: path })
  })
  categoryOptions.value = Array.from(uniquePaths.values()).sort((left, right) => left.label.localeCompare(right.label, 'zh-CN'))
}

async function saveEdit() {
  const row = editingRow.value
  if (!row) return
  const changedFields = editableFields.value.filter(({ key }) => String(row[key] ?? '') !== String(editDraft.value[key] ?? '').trim())
  if (!changedFields.length) {
    cancelEdit()
    return
  }
  savingEdit.value = true
  errorMessage.value = ''
  try {
    for (const field of changedFields) {
      const displayValue = String(editDraft.value[field.key] ?? '').trim()
      const value = manualFieldValueForSave(field, displayValue)
      const response = await saveStoreBusinessReportAnnotation({
        ...annotationStoreScope(row),
        report_code: activeReport.value,
        subject_type: String(row?.annotation_subject_type || field?.subject_type || 'report_row'),
        subject_key: rowKey(row),
        source_fact_id: Number(row?.source_fact_id || 0),
        source_order_id: String(row?.source_order_id || ''),
        source_line_id: String(row?.source_line_id || ''),
        field_key: field.key,
        field_value: value,
        expected_version: Number(row[`${field.key}_version`] ?? row?._field_versions?.[field.key] ?? 0),
        idempotency_key: `ui-rpt-${Date.now().toString(36)}-${Math.random().toString(16).slice(2)}-${field.key.slice(0, 24)}`
      }, reportRuntime.value)
      row[field.key] = displayValue
      row[`${field.key}_version`] = Number(response?.version || 1)
      row._field_versions = { ...(row._field_versions || {}), [field.key]: Number(response?.version || 1) }
    }
    cancelEdit()
    await loadReport()
  } catch (error) {
    errorMessage.value = error?.message || '保存报表补充内容失败。'
  } finally { savingEdit.value = false }
}

function drilldownConfig(row, column) {
  const rowConfig = row?._drilldown?.[column.key] || row?._drilldowns?.[column.key]
  const declared = result.value.drilldown || result.value.drilldowns
  const responseConfig = Array.isArray(declared)
    ? declared.find((item) => String(item?.column_key || item?.column || '') === String(column.key) || item?.column_keys?.includes(column.key))
    : declared?.[column.key] || (declared?.column_keys?.includes(column.key) ? declared : null)
  const columnConfig = column?.drilldown || (column?.drilldown_report ? { report: column.drilldown_report } : null)
  const config = rowConfig || columnConfig || responseConfig
  if (!config) return null
  if (typeof config === 'string') return { report: config }
  return config
}

function canDrilldown(row, column) {
  return Boolean(drilldownConfig(row, column)?.report || drilldownConfig(row, column)?.report_code)
}

function openDrilldown(row, column) {
  const config = drilldownConfig(row, column)
  const report = String(config?.report || config?.report_code || '')
  if (!report || !allowedReportTabs.value.some((tab) => tab.code === report)) return
  const params = { ...(config.params || config.query || {}) }
  const mapping = config.param_map || config.paramMap || {}
  Object.entries(mapping).forEach(([target, source]) => { params[target] = row?.[source] ?? '' })
  router.push({
    name: reportRouteName.value,
    params: { report },
    query: {
      start_date: startDate.value,
      end_date: endDate.value,
      ...(scopePicker.value.selectedStoreIds.length ? { store_ids: scopePicker.value.selectedStoreIds.join(','), scope_label: scopePicker.value.label } : {}),
      ...dynamicFilters.value,
      ...params
    }
  })
}

watch(() => route.fullPath, () => {
  syncActiveReportFromRoute()
  syncFiltersFromRoute()
  page.value = 1
  cancelEdit()
  loadReport()
  nextTick(updateTabsLayout)
})

onMounted(async () => {
  syncActiveReportFromRoute()
  syncFiltersFromRoute()
  window.addEventListener('resize', updateTabsLayout)
  try {
    const [response] = await Promise.all([queryStoreBusinessReportCatalog(reportRuntime.value), loadReportScope()])
    catalog.value = Array.isArray(response) ? response : []
    // The platform embeds this view before the hash route's optional parameter
    // is always hydrated. Re-read the route after the catalog is available so
    // the first query cannot fall back to the legacy partner report.
    syncActiveReportFromRoute()
    syncFiltersFromRoute()
    await loadReport()
    if (filterSchema.value.some((field) => String(field?.type || '') === 'category_tree')) {
      await loadCategoryOptions().catch((error) => {
        errorMessage.value = error?.message || '商品分类读取失败，请稍后重试。'
      })
    }
    await nextTick()
    updateTabsLayout()
  } catch (error) {
    errorMessage.value = error?.message || '经营报表目录读取失败，请稍后重试。'
  }
})

onBeforeUnmount(() => window.removeEventListener('resize', updateTabsLayout))
</script>

<template>
  <main class="store-business-report" :class="{ 'store-business-report--market-detail': activeReport === 'market_detail', 'store-business-report--fixed-table': usesFixedTableLayout }" :aria-label="isSixDimensionReport ? '六维数据中心报表' : isPhaseFourReport ? '运营中心数据报表' : isPhaseSixReport ? '其他报表' : '门店运营报表'">
    <div v-if="!isPlatformReport" class="store-business-report__tabs-shell" :class="{ 'store-business-report__tabs-shell--expanded': tabsExpanded }">
      <nav ref="tabsElement" class="store-business-report__tabs" aria-label="门店运营报表功能">
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
      <button v-if="tabsOverflow" type="button" class="store-business-report__tabs-toggle" :aria-expanded="tabsExpanded" @click="toggleTabs">
        <ChevronUp v-if="tabsExpanded" :size="15" aria-hidden="true" />
        <ChevronDown v-else :size="15" aria-hidden="true" />
        {{ tabsExpanded ? '收起' : '展开' }}
      </button>
    </div>

    <section class="store-business-report__filters" aria-label="报表查询条件">
      <div v-if="isPlatformReport" class="store-business-report__scope-picker">
        <button type="button" class="store-business-report__scope-trigger" :disabled="scopePicker.loading" @click="scopePicker.open = !scopePicker.open">
          <Network :size="16" aria-hidden="true" /> {{ scopePicker.loading ? '读取权限范围' : scopePicker.label }}
        </button>
        <section v-if="scopePicker.open" class="store-business-report__scope-panel" aria-label="组织和门店权限范围">
          <header>组织 / 门店</header>
          <div class="store-business-report__scope-panel-body">
            <div class="store-business-report__scope-tree" aria-label="组织树">
              <p v-if="scopePicker.loading" class="store-business-report__scope-empty">正在读取组织范围。</p>
              <template v-else>
                <div
                  v-for="option in scopeTreeOptions"
                  :key="option.key"
                  class="store-business-report__scope-tree-row"
                  :style="{ paddingLeft: `${8 + option.depth * 18}px` }"
                >
                  <button
                    v-if="option.hasChildren"
                    type="button"
                    class="store-business-report__scope-toggle"
                    :aria-label="`${option.isExpanded ? '折叠' : '展开'}${option.node?.title || option.node?.name || '组织'}`"
                    :aria-expanded="option.isExpanded"
                    @click="toggleScopeNode(option)"
                  >
                    <ChevronDown v-if="option.isExpanded" :size="15" aria-hidden="true" />
                    <ChevronRight v-else :size="15" aria-hidden="true" />
                  </button>
                  <span v-else class="store-business-report__scope-toggle-placeholder" aria-hidden="true"></span>
                  <button
                    type="button"
                    class="store-business-report__scope-option"
                    :class="{ 'store-business-report__scope-option--selected': scopePicker.selectedOrganizationKey === option.key, 'store-business-report__scope-option--store': option.storeId > 0 }"
                    @click="selectScopeNode(option)"
                  >{{ option.node?.title || option.node?.name || option.node?.label || '-' }}</button>
                </div>
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
      <label v-if="usesAnnualYearFilter" class="store-business-report__date-field"><span>年份</span><input v-model="selectedYear" type="number" inputmode="numeric" min="2000" :max="today().slice(0, 4)" aria-label="统计年份" /></label>
      <label v-else-if="usesNaturalMonthFilter" class="store-business-report__date-field"><span>月份</span><input v-model="selectedMonth" type="month" aria-label="统计月份" :max="today().slice(0, 7)" /></label>
      <template v-else>
        <label class="store-business-report__date-field"><span>从</span><input v-model="startDate" type="date" aria-label="开始日期" :min="isFirstPhaseReport ? LEGACY_COVERAGE_START : undefined" :max="endDate" /></label>
        <label class="store-business-report__date-field"><span>至</span><input v-model="endDate" type="date" aria-label="结束日期" :min="startDate" :max="today()" /></label>
      </template>
      <label v-for="field in filterSchema" :key="field.key">{{ field.label || field.name || field.key }}
        <select v-if="['select', 'category_tree'].includes(field.type)" v-model="dynamicFilters[field.key]">
          <option value="">{{ field.placeholder || '全部' }}</option>
          <option v-for="option in filterFieldOptions(field)" :key="String(option.value ?? option.code)" :value="String(option.value ?? option.code)">{{ option.label ?? option.name }}</option>
        </select>
        <template v-else-if="field.type === 'money_range'">
          <input v-model.trim="dynamicFilters[field.min_key]" type="number" min="0" inputmode="decimal" placeholder="最低金额" />
          <input v-model.trim="dynamicFilters[field.max_key]" type="number" min="0" inputmode="decimal" placeholder="最高金额" />
        </template>
        <input
          v-else
          v-model.trim="dynamicFilters[field.key]"
          :type="['date', 'number'].includes(field.type) ? field.type : 'text'"
          :inputmode="field.type === 'number' ? 'decimal' : undefined"
          :placeholder="field.placeholder || ''"
        />
      </label>
      <button v-if="isFirstPhaseReport" type="button" class="button button--secondary" @click="isAdvancedFiltersOpen = !isAdvancedFiltersOpen">{{ isAdvancedFiltersOpen ? '收起筛选' : '更多筛选' }}</button>
      <button type="button" class="button button--primary" :disabled="loading" @click="query">{{ loading ? '查询中' : '查询' }}</button>
      <div class="store-business-report__query-actions" aria-label="报表操作">
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
    </section>

    <section v-if="isFirstPhaseReport && isAdvancedFiltersOpen" class="store-business-report__filters store-business-report__filters--advanced" aria-label="报表高级筛选">
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
      <div v-if="topSummaries.length" class="store-business-report__top-summaries" aria-label="报表合计">
        <span v-for="item in topSummaries" :key="item.key || item.label"><strong>{{ item.label }}</strong>{{ formatCell(item.value) }}</span>
      </div>
      <div v-if="pendingMetrics.length" class="store-business-report__pending"><strong>口径待确认</strong><span v-for="item in pendingMetrics" :key="item">{{ item }}</span></div>
      <div class="store-business-report__table-scroll">
        <table v-if="columns.length" :style="{ '--report-table-header-height': tableHeaderHeight }">
          <thead>
            <tr v-for="(headerRow, rowIndex) in headerRows" :key="`header-row-${rowIndex}`">
              <th
                v-for="(cell, cellIndex) in headerRow"
                :key="`header-${rowIndex}-${cellIndex}-${cell.label}`"
                :class="headerCellClasses(cell)"
                :style="headerCellStyle(cell, rowIndex)"
                :colspan="cell.colspan > 1 ? cell.colspan : null"
                :rowspan="cell.rowspan > 1 ? cell.rowspan : null"
              >{{ cell.label }}</th>
              <th v-if="editableReport && rowIndex === 0" :rowspan="headerRows.length" class="store-business-report__header-column store-business-report__header-column--manual" :style="{ '--report-header-row-index': String(rowIndex) }">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="summaryRow" class="store-business-report__summary-row">
              <td v-for="column in columns" :key="column.key" :class="cellClasses(column)" :style="fixedColumnStyle(column)">{{ formatCell(summaryRow[column.key]) }}</td>
              <td v-if="editableReport">-</td>
            </tr>
            <tr v-for="(row, rowIndex) in records" :key="rowKey(row) || `${activeReport}:${rowIndex}`">
              <td v-for="column in columns" :key="column.key" :class="cellClasses(column)" :style="fixedColumnStyle(column)">
                <template v-if="editingRow === row && editableFieldByKey.has(column.key)">
                  <select
                    v-if="manualFieldIsSelect(editableFieldByKey.get(column.key))"
                    v-model="editDraft[column.key]"
                    :aria-label="column.label"
                    class="store-business-report__inline-input"
                  >
                    <option v-for="option in editableFieldByKey.get(column.key).options" :key="String(option.value ?? option.code)" :value="String(option.value ?? option.code)">{{ option.label ?? option.name }}</option>
                  </select>
                  <input
                    v-else
                    v-model="editDraft[column.key]"
                    :aria-label="column.label"
                    class="store-business-report__inline-input"
                    :type="manualFieldInputType(editableFieldByKey.get(column.key))"
                    :inputmode="manualFieldInputMode(editableFieldByKey.get(column.key))"
                    :maxlength="editableFieldByKey.get(column.key)?.max_length"
                    :min="editableFieldByKey.get(column.key)?.min"
                    :max="editableFieldByKey.get(column.key)?.max"
                    :step="manualFieldStep(editableFieldByKey.get(column.key))"
                  />
                </template>
                <button v-else-if="canDrilldown(row, column)" type="button" class="store-business-report__drilldown" @click="openDrilldown(row, column)">{{ formatCell(row[column.key]) }}</button>
                <template v-else>{{ formatCell(row[column.key]) }}</template>
              </td>
              <td v-if="editableReport">
                <button v-if="editingRow !== row" type="button" class="button button--text" @click="beginEdit(row)">编辑</button>
                <template v-else>
                  <button type="button" class="button button--text" :disabled="savingEdit" @click="saveEdit">{{ savingEdit ? '保存中' : '保存' }}</button>
                  <button type="button" class="button button--text" :disabled="savingEdit" @click="cancelEdit">取消</button>
                </template>
              </td>
            </tr>
            <tr v-if="!loading && !records.length"><td :colspan="columns.length + (editableReport ? 1 : 0)" class="store-business-report__empty-cell">当前条件下暂无数据。</td></tr>
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
.store-business-report { display: grid; align-content: start; gap: 14px; min-width: 0; min-height: 0; height: 100%; box-sizing: border-box; padding: 20px; overflow: auto; background: #f5f7fa; color: #252a34; }
.store-business-report--market-detail, .store-business-report--fixed-table { grid-template-rows: auto auto minmax(0, 1fr); align-content: stretch; overflow: hidden; }
.store-business-report h1, .store-business-report h2, .store-business-report p { margin: 0; }
.store-business-report__tabs-shell { display: flex; align-items: flex-start; min-width: 0; border-bottom: 1px solid #d9e1eb; background: #fff; }
.store-business-report__tabs { display: flex; flex: 1; flex-wrap: wrap; min-width: 0; max-height: 43px; gap: 0 2px; overflow: hidden; padding: 0 12px; }
.store-business-report__tabs-shell--expanded .store-business-report__tabs { max-height: 86px; }
.store-business-report__tabs-toggle { display: inline-flex; flex: none; align-items: center; gap: 4px; min-height: 42px; border: 0; border-left: 1px solid #edf1f5; padding: 0 12px; background: #fff; color: #1769aa; font: inherit; font-size: 12px; cursor: pointer; }
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
.store-business-report__scope-tree-row { display: flex; align-items: center; min-height: 31px; }
.store-business-report__scope-toggle, .store-business-report__scope-toggle-placeholder { display: inline-grid; flex: none; width: 22px; height: 31px; place-items: center; }
.store-business-report__scope-toggle { border: 0; border-radius: 3px; padding: 0; background: transparent; color: #657386; cursor: pointer; }
.store-business-report__scope-toggle:hover { background: #edf5ff; color: #2d8cf0; }
.store-business-report__scope-option { display: block; flex: 1; min-width: 0; min-height: 31px; border: 0; border-radius: 3px; padding: 0 8px; background: #fff; color: #515a6e; text-align: left; font: inherit; font-size: 13px; cursor: pointer; }
.store-business-report__scope-option:hover, .store-business-report__scope-option--selected { background: #edf5ff; color: #2d8cf0; }
.store-business-report__scope-option--store { color: #657386; }
.store-business-report__scope-stores { width: 205px; max-height: 260px; overflow: auto; padding: 10px 12px; }
.store-business-report__scope-stores-title { min-height: 18px; margin: 0 0 8px; color: #666; font-size: 12px; line-height: 18px; }
.store-business-report__scope-store-list button { display: block; width: 100%; min-height: 31px; border: 0; border-radius: 3px; padding: 5px 8px; background: transparent; color: #515a6e; text-align: left; font: inherit; font-size: 13px; cursor: pointer; }
.store-business-report__scope-store-list button:hover, .store-business-report__scope-store-list button.is-active { background: #edf5ff; color: #2d8cf0; }
.store-business-report__scope-empty { margin: 0; padding: 8px 2px; color: #bbb; font-size: 12px; line-height: 1.55; }
.store-business-report__scope-panel footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 14px; border-top: 1px solid #edf0f5; color: #999; font-size: 12px; line-height: 1.45; }
.store-business-report__scope-panel footer button { flex: none; border: 0; padding: 0; background: transparent; color: #2d8cf0; font: inherit; font-size: 12px; cursor: pointer; }
.store-business-report__query-actions { display: flex; flex: none; align-items: center; gap: 8px; margin-left: auto; }
.store-business-report__query-actions .button { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; }
.store-business-report__filters--advanced { border-top: 0; border-radius: 0 0 8px 8px; padding-top: 2px; }
.store-business-report__filters label { display: grid; gap: 5px; min-width: 140px; color: #687586; font-size: 12px; }
.store-business-report__filters .store-business-report__date-field { display: inline-flex; min-width: 0; align-items: center; gap: 6px; color: #526174; font-size: 13px; }
.store-business-report__date-field input { width: 116px; }
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
.store-business-report__coverage-notice { border: 1px solid #f0d79e; border-radius: 7px; padding: 10px 14px; background: #fff9ec; color: #815c13; font-size: 13px; }
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
.store-business-report--market-detail .store-business-report__panel--table, .store-business-report--fixed-table .store-business-report__panel--table { display: flex; min-height: 0; flex-direction: column; overflow: hidden; }
.store-business-report__top-summaries { display: flex; flex-wrap: wrap; gap: 12px 28px; border-bottom: 1px solid #e1e8f0; padding: 12px 16px; background: #f8fbff; color: #23364d; font-size: 13px; }
.store-business-report__top-summaries strong { margin-right: 7px; color: #607086; font-weight: 600; }
.store-business-report__pending { display: flex; flex-wrap: wrap; gap: 8px 12px; border-bottom: 1px solid #f2d49a; padding: 10px 16px; background: #fff9ed; color: #9a6509; font-size: 12px; }
.store-business-report__table-scroll { min-width: 0; overflow: auto; }
.store-business-report--market-detail .store-business-report__table-scroll, .store-business-report--fixed-table .store-business-report__table-scroll { min-height: 0; flex: 1; }
.store-business-report table { width: 100%; border-collapse: separate; border-spacing: 0; white-space: nowrap; font-size: 13px; }
.store-business-report th, .store-business-report td { border-bottom: 1px solid #edf1f5; padding: 11px 12px; text-align: left; }
.store-business-report th { background: #fafbfd; color: #667386; font-size: 12px; font-weight: 600; }
.store-business-report__header-group { box-sizing: border-box; height: 40px; border-bottom: 1px solid #dce4ed; padding-top: 10px; padding-bottom: 10px; color: #536274; text-align: center; }
.store-business-report__header-column { box-sizing: border-box; height: 40px; border-top: 1px solid #e3eaf1; padding-top: 10px; padding-bottom: 10px; }
.store-business-report__header-group--basic, .store-business-report__header-column--basic { background: #eef2f7; color: #536274; }
.store-business-report__header-group--payment { background: #d9ecfa; color: #2d668c; }
.store-business-report__header-column--payment { background: #edf7ff; color: #3b7397; }
.store-business-report__header-group--partner { background: #ffe4bd; color: #985c16; }
.store-business-report__header-column--partner { background: #fff3e2; color: #9e651d; }
.store-business-report__header-group--result { background: #dceedd; color: #3d744b; }
.store-business-report__header-column--result { background: #eff8ef; color: #477c53; }
.store-business-report__header-group--consumption { background: #f5dfea; color: #87516f; }
.store-business-report__header-column--consumption { background: #fdf0f7; color: #925b79; }
.store-business-report__header-group--category { background: #e5edf0; color: #526b75; }
.store-business-report__header-group--manual, .store-business-report__header-column--manual { background: #f1eafa; color: #735687; }
.store-business-report__header-group--group-end, .store-business-report__header-column--group-end, .store-business-report__cell--group-end { border-right: 8px solid #f5f7fa; }
.store-business-report td { color: #303946; }
.store-business-report--market-detail table, .store-business-report--fixed-table table { width: max-content; min-width: 100%; }
.store-business-report--market-detail thead th, .store-business-report--fixed-table thead th { position: sticky; top: calc(var(--report-header-row-index, 0) * 40px); z-index: 5; }
.store-business-report--market-detail .store-business-report__summary-row td { position: sticky; top: 40px; z-index: 4; }
.store-business-report--fixed-table .store-business-report__summary-row td { position: sticky; top: var(--report-table-header-height); z-index: 4; }
.store-business-report--market-detail .store-business-report__column--sticky-left, .store-business-report--fixed-table .store-business-report__column--sticky-left { position: sticky; z-index: 2; background: #fff; }
.store-business-report--market-detail thead .store-business-report__column--sticky-left, .store-business-report--fixed-table thead .store-business-report__column--sticky-left { z-index: 8; }
.store-business-report--market-detail .store-business-report__summary-row .store-business-report__column--sticky-left, .store-business-report--fixed-table .store-business-report__summary-row .store-business-report__column--sticky-left { z-index: 7; background: #f2f8ff; }
.store-business-report--market-detail .store-business-report__column--fixed-last, .store-business-report--fixed-table .store-business-report__column--fixed-last { box-shadow: 5px 0 8px -7px rgba(30, 62, 90, .65); }
.store-business-report__drilldown { border: 0; padding: 0; background: transparent; color: #1769aa; font: inherit; text-decoration: underline; text-underline-offset: 3px; cursor: pointer; }
.store-business-report__summary-row td { background: #f2f8ff; border-bottom: 1px solid #b9d8f3; color: #174d73; font-weight: 700; }
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
@media (max-width: 760px) { .store-business-report { padding: 12px; } .store-business-report__query-actions { width: 100%; flex-wrap: wrap; margin-left: 0; } .store-business-report__metrics, .store-business-report__overview-grid { grid-template-columns: 1fr; } .store-business-report__filters label { flex: 1 1 160px; } .store-business-report__field-guide-item { grid-template-columns: 1fr; gap: 5px; } }
</style>
