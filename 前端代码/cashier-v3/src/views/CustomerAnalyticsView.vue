<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Activity from '@lucide/vue/dist/esm/icons/activity.mjs'
import BookOpen from '@lucide/vue/dist/esm/icons/book-open.mjs'
import CalendarDays from '@lucide/vue/dist/esm/icons/calendar-days.mjs'
import ChevronDown from '@lucide/vue/dist/esm/icons/chevron-down.mjs'
import ChevronRight from '@lucide/vue/dist/esm/icons/chevron-right.mjs'
import CircleDollarSign from '@lucide/vue/dist/esm/icons/circle-dollar-sign.mjs'
import Crown from '@lucide/vue/dist/esm/icons/crown.mjs'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import HeartPulse from '@lucide/vue/dist/esm/icons/heart-pulse.mjs'
import Moon from '@lucide/vue/dist/esm/icons/moon.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import Search from '@lucide/vue/dist/esm/icons/search.mjs'
import SlidersHorizontal from '@lucide/vue/dist/esm/icons/sliders-horizontal.mjs'
import Store from '@lucide/vue/dist/esm/icons/store.mjs'
import Tags from '@lucide/vue/dist/esm/icons/tags.mjs'
import TrendingUp from '@lucide/vue/dist/esm/icons/trending-up.mjs'
import TriangleAlert from '@lucide/vue/dist/esm/icons/triangle-alert.mjs'
import UserPlus from '@lucide/vue/dist/esm/icons/user-plus.mjs'
import Users from '@lucide/vue/dist/esm/icons/users.mjs'
import WalletCards from '@lucide/vue/dist/esm/icons/wallet-cards.mjs'
import { customerAnalyticsPreviewData, customerAnalyticsScopePreview } from '@/dev/customerAnalyticsPreviewData'
import { queryCustomerAnalytics, queryCustomerAnalyticsScope } from '@/services/customerAnalyticsApi'

const groups = [
  { label: '客户概况', items: [{ key: 'overview', label: '客户概况', icon: Users }] },
  { label: '客户来源分析', items: [{ key: 'source', label: '客户来源分析', icon: UserPlus }] },
  { label: '客户状态分析', items: [{ key: 'visits', label: '到店数据分析', icon: Store }, { key: 'health', label: '门店健康数据分析', icon: HeartPulse }] },
  { label: '客户消费分析', items: [{ key: 'tiers', label: '消费分级分析', icon: WalletCards }, { key: 'cash', label: '现金业绩分析', icon: CircleDollarSign }, { key: 'returns', label: '退货业绩分析', icon: TriangleAlert }] },
  { label: '客户品相分析', items: [{ key: 'appearance', label: '客户品相分析', icon: Tags }] },
  { label: '客户未耗分析', items: [{ key: 'unconsumed', label: '客户未耗分析', icon: Activity }] }
]

const route = useRoute()
const router = useRouter()
// 平台端通过 iframe 承载 Vue3 页面，导航统一由 18081 外层菜单提供；
// 直接打开 18091 预览时仍保留页面内导航。
const isEmbedded = typeof window !== 'undefined' && (window.self !== window.top || String(route.query?.embedded || '') === '1')
const reportToKey = Object.freeze({ overview: 'overview', 'source-analysis': 'source', 'visit-analysis': 'visits', 'store-health': 'health', 'consumption-tier': 'tiers', 'cash-performance': 'cash', 'refund-performance': 'returns', 'item-analysis': 'appearance', 'unconsumed-analysis': 'unconsumed' })
const keyToReport = Object.freeze(Object.fromEntries(Object.entries(reportToKey).map(([report, key]) => [key, report])))
const active = ref(reportToKey[String(route.params.report || 'overview')] || 'overview')
function defaultPeriodForReport(report) {
  return String(report || '') === 'source-analysis' ? 'month' : 'year'
}
watch(() => route.params.report, (value) => {
  const next = reportToKey[String(value || 'overview')]
  if (next && next !== active.value) {
    active.value = next
    period.value = defaultPeriodForReport(value)
  }
})
const period = ref(defaultPeriodForReport(route.params.report))
function localDateString(date = new Date()) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
const today = localDateString()
const customStart = ref(`${today.slice(0, 4)}-01-01`)
const customEnd = ref(today)
const region = ref('全部区域')
const store = ref('全部门店')
const demoState = ref('normal')
const loading = ref(false)
const notice = ref('正在读取客户分析数据…')
const scopeOpen = ref(false)
const selectedOrg = ref(customerAnalyticsScopePreview.organization)
const selectedStores = ref([])
const consumptionMode = ref('cash')
const rankingPageSize = 10
const appearanceCashPage = ref(1)
const appearanceConsumptionPage = ref(1)
const trendSeed = ref(0)
const data = reactive(JSON.parse(JSON.stringify(customerAnalyticsPreviewData)))
const dataSource = ref('接口数据')
const sourceExplanations = ref([])
const sourcePanelOpen = ref(false)
const backendMeta = reactive({ dataAsOf: '', metricVersion: '', aggregationCaughtUp: true, pendingMetrics: [] })

function clearReportData() {
  data.overview.cards = (data.overview.cards || []).map((card) => ({ ...card, value: null, delta: '-', unit: card.unit || '人' }))
  data.overview.trend = []; data.overview.age = []; data.overview.structure = []
  data.source.channels = []; data.source.tabs = []
  data.visits.cards = []; data.visits.frequency = []; data.visits.recency = []
  data.health = []; data.tiers = []; data.cash.branches = []
  data.returns.cards = []; data.returns.branches = []; data.returns.managers = []; data.returns.stores = []; data.returns.itemProportions = []; data.returns.rows = []
  data.appearance.items = []; data.appearance.consumptionItems = []
  data.unconsumed.cards = []; data.unconsumed.age = []; data.unconsumed.items = []; data.unconsumed.itemProportions = []; data.unconsumed.topItems = []; data.unconsumed.stores = []; data.unconsumed.branches = []
}

clearReportData()

// The shared cashier shell keeps a desktop min-width for legacy screens.
// Scope a temporary root override to this prototype so narrow layouts can
// use the responsive rules below without changing the global shell.
function setResponsiveRoot(enabled) {
  const root = document.documentElement
  const body = document.body
  const app = document.getElementById('app')
  if (enabled) {
    root.classList.add('customer-analytics-document')
    body.classList.add('customer-analytics-document')
    root.style.setProperty('min-width', '0', 'important')
    root.style.setProperty('width', '100%', 'important')
    body.style.setProperty('min-width', '0', 'important')
    body.style.setProperty('width', '100%', 'important')
    app?.style.setProperty('width', '100%', 'important')
  } else {
    root.classList.remove('customer-analytics-document')
    body.classList.remove('customer-analytics-document')
    root.style.removeProperty('min-width')
    root.style.removeProperty('width')
    body.style.removeProperty('min-width')
    body.style.removeProperty('width')
    app?.style.removeProperty('width')
  }
}

onMounted(() => { setResponsiveRoot(true); loadReport('查询'); queryCustomerAnalyticsScope().catch(() => {}) })
onBeforeUnmount(() => setResponsiveRoot(false))

const activeTitle = computed(() => groups.flatMap((group) => group.items).find((item) => item.key === active.value)?.label || '客户概况')
const currentPeriodLabel = computed(() => ({ month: '本月', year: '本年', custom: '自定义' }[period.value] || '本年'))
const scopeLabel = computed(() => selectedStores.value.length ? `${selectedOrg.value} / ${selectedStores.value.join('、')}` : `${selectedOrg.value} / 全部门店`)
const maxAge = computed(() => Math.max(...data.overview.age.map((item) => item.value), 1))
const maxVisit = computed(() => Math.max(...data.visits.frequency.map((item) => item.value), 1))
const maxRecency = computed(() => Math.max(...data.visits.recency.map((item) => item.value), 1))
const maxChannel = computed(() => Math.max(...data.source.channels.map((item) => item.people), 1))
const maxBranch = computed(() => Math.max(...data.cash.branches.map((item) => item[5]), 1))
const maxReturn = computed(() => Math.max(...data.returns.branches.map((item) => item[1]), 1))
const maxReturnManager = computed(() => Math.max(...(data.returns.managers || []).map((item) => item[1]), 1))
const maxReturnStore = computed(() => Math.max(...(data.returns.stores || []).map((item) => item[1]), 1))
const maxUnconsumedItem = computed(() => Math.max(...data.unconsumed.items.map((item) => item[1]), 1))
const maxStore = computed(() => Math.max(...data.unconsumed.stores.map((item) => item[1]), 1))
const maxUnconsumedAge = computed(() => Math.max(...data.unconsumed.age.map((item) => item[1]), 1))
const maxUnconsumedBranchAmount = computed(() => Math.max(...(data.unconsumed.branches || []).map((item) => item[1]), 1))
const maxUnconsumedBranchCount = computed(() => Math.max(...(data.unconsumed.branches || []).map((item) => item[2]), 1))
const cashCards = computed(() => {
  const rows = data.cash.branches || []
  const fresh = rows.reduce((sum, row) => sum + Number(row[2] || 0), 0)
  const old = rows.reduce((sum, row) => sum + Number(row[1] || 0), 0)
  const amount = rows.reduce((sum, row) => sum + Number(row[5] || 0), 0)
  return { fresh, old, amount, freshRate: ratioValue(fresh, fresh + old), oldRate: ratioValue(old, fresh + old), average: fresh + old ? Math.round(amount / (fresh + old)) : null }
})
const tierSummary = computed(() => {
  const rows = data.tiers || []
  const people = rows.reduce((sum, row) => sum + Number(row[1] || 0), 0)
  const amount = rows.reduce((sum, row) => sum + Number(row[3] || 0), 0)
  return { people, amount, average: people ? Math.round(amount / people) : null }
})
const healthSummary = computed(() => {
  const rows = data.health || []
  if (!rows.length) return { stores: null, active: null, consumers: null, rate: null, postSale: null, output: null, visits: null, consumption: null, avgConsumption: null, avgAmount: null }
  const latest = rows[rows.length - 1]
  return { stores: latest[1], active: latest[2], consumers: latest[3], rate: latest[4], postSale: latest[5], output: latest[6], visits: latest[7], consumption: latest[8], avgConsumption: latest[9], avgAmount: latest[10] }
})
const appearanceTotal = computed(() => (data.appearance.items || []).reduce((sum, row) => sum + Number(row[1] || 0), 0))
const appearanceCashPages = computed(() => Math.max(1, Math.ceil((data.appearance.items || []).length / rankingPageSize)))
const appearanceConsumptionPages = computed(() => Math.max(1, Math.ceil((data.appearance.consumptionItems || []).length / rankingPageSize)))
const appearanceCashRows = computed(() => (data.appearance.items || []).slice((appearanceCashPage.value - 1) * rankingPageSize, appearanceCashPage.value * rankingPageSize))
const appearanceConsumptionRows = computed(() => (data.appearance.consumptionItems || []).slice((appearanceConsumptionPage.value - 1) * rankingPageSize, appearanceConsumptionPage.value * rankingPageSize))

function money(cents) {
  if (cents === null || cents === undefined || cents === '') return '-'
  return `¥${Math.round(Number(cents) / 100).toLocaleString('zh-CN')}`
}
function number(value) { return value === null || value === undefined ? '-' : Number(value).toLocaleString('zh-CN') }
function pct(value) { return value === null || value === undefined ? '-' : `${Number(value).toFixed(1).replace(/\.0$/, '')}%` }
function iconFor(card) { return ({ users: Users, heart: HeartPulse, moon: Moon, 'user-plus': UserPlus, crown: Crown }[card.icon] || Activity) }
function apiReportCode() { return `customer_${String(keyToReport[active.value] || 'overview').replaceAll('-', '_')}` }
function reportQuery() {
  const dates = period.value === 'month'
    ? { start_date: `${customEnd.value.slice(0, 8)}01`, end_date: customEnd.value }
    : period.value === 'custom'
      ? { start_date: customStart.value, end_date: customEnd.value }
      : { start_date: `${customEnd.value.slice(0, 4)}-01-01`, end_date: customEnd.value }
  return {
    report: apiReportCode(), ...dates,
    org_id: selectedOrg.value === '总部' ? 0 : undefined,
    store_ids: selectedStores.value.join(','),
    category_path: region.value === '全部区域' ? '' : region.value,
    consumption_metric: consumptionMode.value
  }
}
function applyBackendMeta(payload) {
  backendMeta.dataAsOf = String(payload?.data_as_of || '')
  backendMeta.metricVersion = String(payload?.metric_version || '')
  backendMeta.aggregationCaughtUp = payload?.aggregation_caught_up !== false
  backendMeta.pendingMetrics = Array.isArray(payload?.pending_metrics) ? payload.pending_metrics : []
  sourceExplanations.value = Array.isArray(payload?.source_explanations) ? payload.source_explanations : []
}
function payloadRecords(payload) {
  const rows = payload?.records || payload?.rows || payload?.data?.records || []
  return Array.isArray(rows) ? rows : []
}
function field(row, ...keys) {
  for (const key of keys) {
    if (row && row[key] !== undefined && row[key] !== null && row[key] !== '') return row[key]
  }
  return null
}
function cents(value) {
  if (value === null || value === undefined || value === '' || value === '-') return null
  if (typeof value === 'number') return Math.round(value)
  const raw = String(value).replace(/[¥,\s]/g, '')
  if (!raw || !/^-?\d+(?:\.\d+)?$/.test(raw)) return null
  return Math.round(Number(raw) * 100)
}
function ratioValue(numerator, denominator) {
  const n = Number(numerator); const d = Number(denominator)
  return d > 0 ? Math.round((n / d) * 1000) / 10 : null
}
function numericOrNull(value) {
  if (value === null || value === undefined || value === '' || value === '-') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}
function applyCustomerPayload(payload) {
  const records = payloadRecords(payload)
  const report = String(payload?.report || apiReportCode()).replace(/^customer_/, '')
  if (!records.length) {
    // Empty is authoritative for the endpoint (not a reason to fabricate zeros).
    clearReportData()
    if (payload && (payload.records || payload.rows || payload.aggregation_caught_up === false)) demoState.value = 'empty'
    return
  }
  appearanceCashPage.value = 1
  appearanceConsumptionPage.value = 1
  const tone = ['blue', 'green', 'amber', 'purple', 'slate', 'red']
  if (report === 'source_analysis') {
    data.source.channels = records.map((row, i) => {
      const people = numericOrNull(field(row, 'member_count', 'people', 'customer_count'))
      const converted = numericOrNull(field(row, 'order_count', 'converted', 'deal_count'))
      const amountValue = field(row, 'cash_amount', 'receipt_amount', 'sale_amount', 'amount')
      return { label: String(field(row, 'channel_name', 'source_name', 'name') || '未分类来源'), people, converted, amount: cents(amountValue), rate: ratioValue(converted, people), tone: tone[i % tone.length] }
    })
  } else if (report === 'cash_performance') {
    data.cash.branches = records.map((row, i) => {
      const old = numericOrNull(field(row, 'old_customer_count', 'old_count'))
      const fresh = numericOrNull(field(row, 'new_customer_count', 'new_count'))
      const total = (old ?? 0) + (fresh ?? 0)
      return [String(field(row, 'company_name', 'branch_name') || '未配置分公司'), old, fresh, ratioValue(old, total), ratioValue(fresh, total), cents(field(row, 'cash_amount', 'sale_amount'))]
    })
  } else if (report === 'refund_performance') {
    const aggregates = records.filter(row => field(row, 'store_name') === null || field(row, 'refund_items') === null)
    const detail = records.filter(row => field(row, 'refund_items') !== null)
    const source = Array.isArray(payload?.aggregate_records) ? payload.aggregate_records : (aggregates.length ? aggregates : records)
    const detailRows = Array.isArray(payload?.detail_records) ? payload.detail_records : (detail.length ? detail : records)
    const totalAmount = source.reduce((sum, row) => sum + (cents(field(row, 'refund_amount', 'amount')) || 0), 0)
    const totalPeople = source.reduce((sum, row) => sum + (numericOrNull(field(row, 'refund_people', 'people')) || 0), 0)
    data.returns.cards = [['退款人数', `${number(totalPeople)}人`, '按退款成功日期', 'blue'], ['退款金额', money(totalAmount), '按退款成功日期', 'red'], ['退款率', String(field(payload?.summary_row || {}, 'refund_rate') || '-'), '退款金额 / 现金业绩', 'amber']]
    data.returns.branches = source.map((row, i) => [String(field(row, 'company_name', 'branch_name') || '未配置分公司'), cents(field(row, 'refund_amount', 'amount')), tone[i % tone.length]])
    data.returns.rows = detailRows.map(row => [String(field(row, 'refund_date', 'date') || '-'), String(field(row, 'company_name') || '-'), String(field(row, 'store_name') || '-'), Number(field(row, 'refund_people', 'people')) || 0, String(field(row, 'refund_items', 'item_name') || '-'), money(cents(field(row, 'refund_amount', 'amount')))])
    const itemRows = Array.isArray(payload?.item_proportions) ? payload.item_proportions : []
    data.returns.itemProportions = itemRows.map((row, i) => [String(field(row, 'item_name', 'name') || '-'), numericOrNull(field(row, 'amount_share', 'share')), `#${['3681b2', '3d9187', 'c88727', '776aa7', '62a8ae', 'bb6b52'][i % 6]}`])
    const managerRows = Array.isArray(payload?.manager_records) ? payload.manager_records : []
    const storeRows = Array.isArray(payload?.store_records) ? payload.store_records : []
    data.returns.managers = managerRows.map((row, i) => [String(field(row, 'manager_name', 'name') || '-'), cents(field(row, 'refund_amount', 'amount')), tone[i % tone.length]])
    data.returns.stores = storeRows.map((row, i) => [String(field(row, 'store_name', 'name') || '-'), cents(field(row, 'refund_amount', 'amount')), tone[i % tone.length]])
  } else if (report === 'item_analysis') {
    data.appearance.items = records.map(row => [String(field(row, 'item_name', 'name') || '未命名品项'), cents(field(row, 'cash_amount', 'amount')), numericOrNull(field(row, 'deal_people', 'people')), cents(field(row, 'average_amount', 'unit_amount')), numericOrNull(String(field(row, 'people_share') ?? '').replace('%', '')), numericOrNull(String(field(row, 'amount_share') ?? '').replace('%', '')), numericOrNull(field(row, 'experience_people', 'experience_count')), numericOrNull(String(field(row, 'deal_rate') ?? '').replace('%', ''))])
    const hasKnown = value => value !== null && value !== undefined && value !== '' && value !== '-'
    const consumptionRecords = Array.isArray(payload?.consumption_records) ? payload.consumption_records : records
    data.appearance.consumptionItems = consumptionRecords
      .filter(row => hasKnown(field(row, 'consumption_amount', 'consume_amount')))
      .map(row => [String(field(row, 'item_name', 'name') || '未命名品项'), cents(field(row, 'consumption_amount', 'consume_amount')), numericOrNull(field(row, 'consumption_people', 'consume_people')), cents(field(row, 'consumption_average_amount', 'consumption_unit_amount')), null, numericOrNull(String(field(row, 'consumption_share', 'consume_share') ?? '').replace('%', ''))])
  } else if (report === 'customer_overview' || report === 'overview') {
    const count = (predicate) => records.filter(predicate).length
    const countKnown = (keys, predicate) => records.some(row => field(row, ...keys) !== null) ? count(predicate) : null
    const activeCount = countKnown(['active', 'lifecycle_label'], row => String(field(row, 'active')).toLowerCase() === '1' || String(field(row, 'active')) === '是' || String(field(row, 'lifecycle_label')).includes('活'))
    const validCount = countKnown(['effective'], row => String(field(row, 'effective')).toLowerCase() === '1' || String(field(row, 'effective')) === '是')
    const sleepingCount = countKnown(['sleeping', 'lifecycle_label'], row => String(field(row, 'sleeping')).toLowerCase() === '1' || String(field(row, 'sleeping')) === '是' || String(field(row, 'lifecycle_label')).includes('睡眠'))
    const newCount = countKnown(['lifecycle_label'], row => String(field(row, 'lifecycle_label')).includes('新'))
    const tenK = countKnown(['annual_30000'], row => String(field(row, 'annual_30000')).toLowerCase() === '1' || String(field(row, 'annual_30000')) === '是')
    const oldCount = newCount === null ? null : Math.max(records.length - newCount, 0)
    data.overview.cards = data.overview.cards.map(card => ({ ...card, value: ({ active: activeCount, valid: validCount, sleeping: sleepingCount, new: newCount, old: oldCount, tenK })[card.key], delta: '-' }))
    const trend = Array.isArray(payload?.trend) ? payload.trend : []
    data.overview.trend = trend.map((row) => {
      const value = numericOrNull(typeof row === 'object' ? field(row, 'value', 'count', 'member_count') : row)
      return value === null ? null : { label: typeof row === 'object' ? String(field(row, 'label', 'month', 'period') || '-') : '-', value }
    }).filter(Boolean)
    const age = Array.isArray(payload?.age) ? payload.age : []
    data.overview.age = age.map((row) => ({ label: String(field(row, 'label', 'age_band', 'name') || '-'), value: numericOrNull(field(row, 'value', 'count', 'member_count')), percent: numericOrNull(field(row, 'percent', 'share')) })).filter((row) => row.value !== null)
  } else if (report === 'visit_analysis') {
    const row = records[records.length - 1] || records[0]
    const get = (...keys) => field(row, ...keys)
    data.visits.cards = [['总活客数', get('active_members', 'total_active_members') ?? '-', '接口返回'], ['当月到店1–2次', get('one_to_two_visits') ?? '-', '接口返回'], ['当月到店3次以上', get('three_plus_visits') ?? '-', '接口返回'], ['常客（90天内）', get('regular_customers') ?? '-', '接口返回'], ['死客（90天以上）', get('inactive_customers') ?? '-', '接口返回'], ['活客率', get('active_rate') ?? '-', '接口返回']]
    const frequency = Array.isArray(payload?.frequency) ? payload.frequency : []
    const recency = Array.isArray(payload?.recency) ? payload.recency : []
    data.visits.frequency = frequency.map((item) => ({ label: String(field(item, 'label', 'band', 'visit_band') || '-'), value: numericOrNull(field(item, 'value', 'count', 'member_count')), percent: numericOrNull(field(item, 'percent', 'share')) })).filter((item) => item.value !== null)
    data.visits.recency = recency.map((item) => ({ label: String(field(item, 'label', 'band', 'recency_band') || '-'), value: numericOrNull(field(item, 'value', 'count', 'member_count')), percent: numericOrNull(field(item, 'percent', 'share')) })).filter((item) => item.value !== null)
  } else if (report === 'store_health') {
    data.health = records.map(row => [field(row, 'month') || '-', numericOrNull(field(row, 'store_count')), numericOrNull(field(row, 'active_members')), numericOrNull(field(row, 'consuming_members')), numericOrNull(String(field(row, 'consumption_rate') ?? '').replace('%', '')), numericOrNull(field(row, 'post_sale_visits')), cents(field(row, 'staff_unit_output')), numericOrNull(field(row, 'monthly_service_visits', 'store_average_visits')), cents(field(row, 'consumption_amount')), cents(field(row, 'staff_consumption_output')), cents(field(row, 'store_average_amount'))])
  } else if (report === 'consumption_tier') {
    data.tiers = records.map(row => [String(field(row, 'consumption_tier', 'tier', 'consumption_level') || '-'), numericOrNull(field(row, 'accumulated_consumers', 'people')), numericOrNull(String(field(row, 'people_share') ?? '').replace('%', '')), cents(field(row, 'category_consumption_amount', 'consumption_amount', 'cash_amount')), numericOrNull(String(field(row, 'category_consumption_share', 'amount_share') ?? '').replace('%', '')), cents(field(row, 'total_consumption_amount', 'average_amount'))])
  } else if (report === 'unconsumed_analysis') {
    const summary = payload?.summary || payload?.summary_row || payload?.data?.summary || {}
    const branches = payload?.branches || payload?.unconsumed_branches || payload?.data?.branches || records.filter(row => field(row, 'company_name', 'branch_name') !== null)
    const items = payload?.items || payload?.unconsumed_items || payload?.data?.items || records.filter(row => field(row, 'item_name') !== null)
    const stores = payload?.stores || payload?.unconsumed_stores || payload?.data?.stores || records.filter(row => field(row, 'store_name') !== null)
    const rowsToArray = (list, nameKeys, amountKeys, countKeys = []) => (Array.isArray(list) ? list : []).map((row) => {
      if (Array.isArray(row)) return row
      return [String(field(row, ...nameKeys) || '-'), cents(field(row, ...amountKeys)), numericOrNull(field(row, ...countKeys))]
    })
    const branchRows = rowsToArray(branches, ['company_name', 'branch_name', 'name'], ['unconsumed_amount', 'amount', 'value'], ['unconsumed_count', 'count', 'people'])
    const itemRows = (Array.isArray(items) ? items : []).map((row) => [
      String(field(row, 'item_name', 'name') || '未命名品项'),
      cents(field(row, 'unconsumed_amount', 'amount', 'value')),
      numericOrNull(field(row, 'amount_share', 'share'))
    ])
    const storeRows = rowsToArray(stores, ['store_name', 'name'], ['unconsumed_amount', 'amount', 'value'], ['unconsumed_count', 'count', 'people'])
    if (branchRows.length) data.unconsumed.branches = branchRows
    if (itemRows.length) {
      data.unconsumed.items = itemRows.map(row => [row[0], row[1]])
      data.unconsumed.topItems = data.unconsumed.items.slice(0, 10)
      const proportionRows = Array.isArray(payload?.item_proportions) ? payload.item_proportions : itemRows.slice(0, 6).map(row => ({ item_name: row[0], unconsumed_amount: row[1], amount_share: row[2] }))
      const pieColors = ['#3681b2', '#3d9187', '#c88727', '#776aa7', '#62a8ae', '#bb6b52']
      data.unconsumed.itemProportions = proportionRows.slice(0, 6).map((row, i) => [String(field(row, 'item_name', 'name') || '-'), numericOrNull(field(row, 'amount_share', 'share')), pieColors[i % pieColors.length], cents(field(row, 'unconsumed_amount', 'amount', 'value'))])
    }
    if (storeRows.length) data.unconsumed.stores = storeRows.map(row => [row[0], row[1]])
    const amount = cents(field(summary, 'unconsumed_amount', 'amount', 'total_amount'))
    const people = numericOrNull(field(summary, 'unconsumed_people', 'people', 'member_count'))
    const rate = numericOrNull(String(field(summary, 'unconsumed_rate', 'rate') ?? '').replace('%', ''))
    data.unconsumed.cards = [['未耗金额', amount === null ? '-' : money(amount), '按接口汇总（展示四舍五入，接口保留分）', 'blue'], ['未耗人数', people === null ? '-' : `${number(people)}人`, '按接口汇总', 'green'], ['未耗率', rate === null ? '-' : pct(rate), '按接口汇总', 'amber']]
    if (!branchRows.length && !itemRows.length && !storeRows.length && !Object.keys(summary).length) demoState.value = 'empty'
  }
}
async function loadReport(action = '查询') {
  if (loading.value) return
  loading.value = true
  notice.value = `${action}中，正在读取${activeTitle.value}...`
  try {
    const payload = await queryCustomerAnalytics(reportQuery())
    applyBackendMeta(payload)
    applyCustomerPayload(payload)
    dataSource.value = '接口数据'
    if (demoState.value !== 'empty') demoState.value = 'normal'
    notice.value = `${action}完成：已返回${activeTitle.value}的权限、字段来源和数据状态。`
  } catch (error) {
    dataSource.value = '接口异常'
    demoState.value = 'error'
    notice.value = `${action}失败：${error?.message || '客户分析接口暂不可用'}。`
  } finally {
    loading.value = false
    trendSeed.value += 1
  }
}
function setModule(key) { active.value = key; period.value = defaultPeriodForReport(keyToReport[key]); const report = keyToReport[key]; if (report && String(route.params.report || '') !== report) router.replace({ path: `/platform/customer-analytics/${report}`, query: route.query }).catch(() => {}); loadReport('切换') }
function chooseStore(value) { store.value = value; selectedStores.value = value === '全部门店' ? [] : [value]; scopeOpen.value = false }
function runQuery(action = '查询') { loadReport(action) }
function changeAppearancePage(type, page) {
  if (type === 'cash') appearanceCashPage.value = Math.min(Math.max(1, page), appearanceCashPages.value)
  else appearanceConsumptionPage.value = Math.min(Math.max(1, page), appearanceConsumptionPages.value)
}
function branchWidth(value) { return `${Math.max(4, Number(value) / maxBranch.value * 100)}%` }
function moneyWidth(value, max) { return `${Math.max(4, Number(value) / max * 100)}%` }
function refundPieStyle(items = []) {
  let cursor = 0
  const stops = items.map((item) => {
    const start = cursor
    cursor += Number(item[1]) || 0
    return `${item[2]} ${start}% ${cursor}%`
  })
  return { background: `conic-gradient(${stops.join(', ')})` }
}
function conversionPieStyle(value, tone = 'blue') {
  const rate = Math.max(0, Math.min(100, Number(value) || 0))
  const colors = { blue: '#3982b5', green: '#3b9186', amber: '#c88727', purple: '#7668a9', slate: '#8697a8', red: '#b95a4c' }
  return { background: `conic-gradient(${colors[tone] || colors.blue} 0 ${rate}%, #e8eff3 ${rate}% 100%)` }
}
function appearanceDonutStyle(items = []) {
  const colors = ['#3681b2', '#3d9187', '#c88727', '#776aa7', '#62a8ae', '#bb6b52']
  let cursor = 0
  const stops = items.slice(0, 6).map((row, index) => {
    const share = Math.max(0, Number(row[5]) || 0)
    const start = cursor
    cursor += share
    return `${colors[index]} ${start}% ${cursor}%`
  })
  if (cursor < 100) stops.push(`#dfe7ec ${cursor}% 100%`)
  return { background: `conic-gradient(${stops.join(', ') || '#dfe7ec 0 100%'})` }
}
function sparkPoints(values) {
  const numericValues = values.map((item) => Number(typeof item === 'object' ? item.value : item) || 0)
  const max = Math.max(...numericValues, 1); const step = 100 / Math.max(numericValues.length - 1, 1)
  return numericValues.map((value, index) => `${(index * step).toFixed(1)},${(74 - (value / max) * 62).toFixed(1)}`).join(' ')
}

function isEmpty() { return demoState.value === 'empty' }
function isError() { return demoState.value === 'error' }
</script>

<template>
  <main class="customer-analytics" aria-labelledby="customer-analytics-title">
    <header class="customer-analytics__hero">
      <div>
        <span class="customer-analytics__eyebrow">客户数据中心 · 统一报表接口</span>
        <h1 id="customer-analytics-title">{{ activeTitle }}</h1>
        <p>统一查看客户结构、来源、到店、消费、退款及未耗情况</p>
      </div>
      <div class="customer-analytics__hero-actions"><button class="icon-button" title="字段取值来源" @click="sourcePanelOpen = !sourcePanelOpen"><BookOpen :size="17" /></button></div>
    </header>

    <section v-if="sourcePanelOpen" class="source-panel" aria-label="列名取值来源">
      <header><strong>列名取值来源</strong><button type="button" @click="sourcePanelOpen = false">收起</button></header>
      <p v-if="!sourceExplanations.length">接口返回后显示本功能每一列的业务取值说明。</p>
      <ul v-else><li v-for="item in sourceExplanations" :key="item.key"><b>{{ item.label }}</b><span>{{ item.source_explanation }}</span></li></ul>
      <footer><span v-if="backendMeta.dataAsOf">数据截至：{{ backendMeta.dataAsOf }}</span><span v-if="backendMeta.metricVersion">指标版本：{{ backendMeta.metricVersion }}</span><span v-if="!backendMeta.aggregationCaughtUp" class="source-panel__warning">以下指标暂未接入完整事实：{{ backendMeta.pendingMetrics.join('、') }}；页面不以 0 代替未知值</span></footer>
    </section>

    <div class="customer-analytics__layout" :class="{ 'customer-analytics__layout--embedded': isEmbedded }">
      <aside v-if="!isEmbedded" class="customer-analytics__sidebar" aria-label="客户分析导航">
        <div class="sidebar-heading"><span>客户分析</span><small>9 个功能</small></div>
        <div v-for="group in groups" :key="group.label" class="nav-group">
          <div class="nav-group__label">{{ group.label }}</div>
          <button v-for="item in group.items" :key="item.key" type="button" class="nav-item" :class="{ 'is-active': active === item.key }" @click="setModule(item.key)">
            <component :is="item.icon" :size="15" /><span>{{ item.label }}</span><ChevronRight v-if="active === item.key" :size="14" />
          </button>
        </div>
      </aside>

      <section class="customer-analytics__content">
        <section class="query-card" aria-label="查询条件">
          <div class="query-card__row query-card__row--top">
            <button type="button" class="scope-trigger" @click="scopeOpen = !scopeOpen"><SlidersHorizontal :size="16" />{{ scopeLabel }}<ChevronDown :size="15" :class="{ 'is-rotated': scopeOpen }" /></button>
            <div class="periods" role="tablist"><button v-for="item in [['month','本月'],['year','本年'],['custom','自定义']]" :key="item[0]" type="button" :class="{ 'is-active': period === item[0] }" @click="period = item[0]">{{ item[1] }}</button></div>
            <label v-if="period === 'custom'" class="date-field"><CalendarDays :size="14" /><input v-model="customStart" type="date" /><span>至</span><input v-model="customEnd" type="date" /></label>
            <label class="select-field"><span>区域</span><select v-model="region"><option>全部区域</option><option>华东区域</option><option>华南区域</option><option>华北区域</option></select></label>
            <label class="select-field"><span>门店</span><select v-model="store"><option>全部门店</option><option>南陵夫子庙店</option><option>淮南商之都店</option><option>北京朝阳店</option><option>广州天河店</option></select></label>
            <div class="query-actions"><button type="button" class="button button--primary" :disabled="loading" @click="runQuery()"><Search :size="15" />查询</button><button type="button" class="button" :disabled="loading" @click="runQuery('刷新')"><RefreshCw :size="15" :class="{ 'is-spinning': loading }" />刷新</button></div>
          </div>
          <div class="query-card__row query-card__row--meta"><span>数据范围：{{ scopeLabel }}</span><span>统计周期：{{ currentPeriodLabel }}</span><span class="meta-status"><i></i>{{ notice }}</span></div>
          <div v-if="scopeOpen" class="scope-panel">
            <header><strong>当前权限范围</strong><button type="button" @click="scopeOpen = false">收起</button></header>
            <div class="scope-panel__body"><div class="scope-tree"><button class="tree-root is-selected" type="button" @click="selectedOrg = '总部'; selectedStores = []; store = '全部门店'">⌄ <Store :size="14" />总部</button><button v-for="node in customerAnalyticsScopePreview.tree[0].children" :key="node.label" type="button" class="tree-node" :class="{ 'is-selected': selectedOrg === node.label }" @click="selectedOrg = node.label; selectedStores = []; store = '全部门店'">› <span>{{ node.label }}</span></button></div><div class="scope-stores"><p>选择门店（可选）</p><button type="button" :class="{ 'is-selected': !selectedStores.length }" @click="chooseStore('全部门店')">全部门店</button><button v-for="node in customerAnalyticsScopePreview.tree[0].children.flatMap((item) => item.stores)" :key="node" type="button" :class="{ 'is-selected': selectedStores.includes(node) }" @click="chooseStore(node)">{{ node }}</button></div></div>
          </div>
        </section>

        <div v-if="loading" class="state-panel state-panel--loading"><RefreshCw :size="20" class="is-spinning" /><strong>正在加载客户分析数据</strong><span>正在读取当前权限范围内的真实数据。</span></div>
        <div v-else-if="isError()" class="state-panel state-panel--error"><TriangleAlert :size="21" /><strong>客户分析请求失败</strong><span>{{ notice }}</span><button type="button" class="button button--primary" @click="runQuery('重试')">重试</button></div>
        <div v-else-if="isEmpty()" class="state-panel state-panel--empty"><Search :size="21" /><strong>当前筛选范围暂无数据</strong><span>请调整日期、组织或门店后重新查询。</span></div>

        <template v-else>
          <section v-if="active === 'overview'" class="module-stack">
            <div class="metric-grid metric-grid--six"><article v-for="card in data.overview.cards" :key="card.key" class="metric-card" :class="`metric-card--${card.tone}`"><div class="metric-card__title"><component :is="iconFor(card)" :size="17" /><span>{{ card.label }}</span></div><strong>{{ number(card.value) }}<small>{{ card.unit }}</small></strong><span class="metric-card__delta" :class="{ 'is-negative': card.delta.startsWith('-') }">{{ card.delta }} 较上期</span></article></div>
            <div class="panel-grid panel-grid--wide"><article class="panel chart-panel"><header class="panel__header"><div><span class="panel__kicker"><TrendingUp :size="15" />趋势分析</span><h2>客户数量趋势</h2></div><span class="panel__hint">接口返回月份</span></header><div v-if="data.overview.trend.length" class="line-chart"><div class="line-chart__grid"><span v-for="item in [100,75,50,25,0]" :key="item">{{ item }}%</span></div><svg viewBox="0 0 100 80" preserveAspectRatio="none" aria-label="客户数量趋势折线图"><polyline :key="trendSeed" :points="sparkPoints(data.overview.trend)" /></svg><div class="line-chart__labels"><span v-for="item in data.overview.trend" :key="item.label">{{ item.label }}</span></div></div><div v-else class="state-panel state-panel--empty"><span>接口暂未返回趋势序列</span></div></article><article class="panel structure-panel"><header class="panel__header"><div><span class="panel__kicker"><Users :size="15" />客户结构</span><h2>客户类型分布</h2></div></header><div class="donut" aria-label="客户类型分布"><div class="donut__hole"><strong>{{ number(data.overview.cards[0]?.value) }}</strong><span>活客</span></div></div><div class="legend-list"><div v-for="item in data.overview.structure" :key="item.label"><i :style="{ background: item.color }"></i><span>{{ item.label }}</span><b>{{ number(item.value) }}</b><em>{{ pct(item.percent) }}</em></div></div></article></div>
            <div class="panel-grid panel-grid--wide"><article class="panel"><header class="panel__header"><div><span class="panel__kicker"><Users :size="15" />客户画像</span><h2>成交客户年龄分布</h2></div><button type="button" class="link-button">列名取值来源</button></header><div v-if="data.overview.age.length" class="horizontal-bars"> <div v-for="item in data.overview.age" :key="item.label" class="horizontal-bar"><span>{{ item.label }}</span><div><i :style="{ width: `${item.value / maxAge * 100}%` }"></i></div><b>{{ number(item.value) }}</b><em>{{ pct(item.percent) }}</em></div></div><div v-else class="state-panel state-panel--empty"><span>接口暂未返回年龄分布</span></div></article><article class="panel panel--callout"><div class="callout-icon"><Crown :size="23" /></div><h3>客户经营提醒</h3><p>指标提醒以接口返回的客户汇总结果为准。</p><button type="button" class="link-button">查看客户明细 <ChevronRight :size="14" /></button></article></div>
          </section>

          <section v-else-if="active === 'source'" class="module-stack">
            <div class="metric-grid metric-grid--three source-card-grid">
              <article v-for="item in data.source.channels" :key="item.label" class="metric-card metric-card--source" :class="`metric-card--${item.tone}`">
                <div class="metric-card__title"><UserPlus :size="17" /><span>{{ item.label }}</span></div>
                <div class="source-card__body"><div class="source-card__conversion"><span>成交率</span><span class="conversion-donut conversion-donut--large" :style="conversionPieStyle(item.rate, item.tone)"><i>{{ pct(item.rate) }}</i></span></div><dl><div><dt>进店数</dt><dd>{{ number(item.people) }}</dd></div><div><dt>成交数</dt><dd>{{ number(item.converted) }}</dd></div><div><dt>成交金额</dt><dd>{{ money(item.amount) }}</dd></div></dl></div>
              </article>
            </div>
            <div class="panel">
              <header class="panel__header"><div><span class="panel__kicker"><TrendingUp :size="15" />来源渠道</span><h2>客户来源排行</h2></div><button type="button" class="link-button">列名取值来源</button></header>
              <div class="channel-list"><div v-for="item in data.source.channels" :key="item.label" class="channel-row"><span class="channel-row__rank">{{ data.source.channels.indexOf(item) + 1 }}</span><span class="channel-row__name">{{ item.label }}</span><div class="channel-row__track"><i :class="`tone-${item.tone}`" :style="{ width: `${item.people / maxChannel * 100}%` }"></i></div><b>{{ number(item.people) }}</b><em>{{ pct(item.rate) }}</em></div></div>
            </div>
          </section>

          <section v-else-if="active === 'visits'" class="module-stack"><div class="metric-grid metric-grid--six"><article v-for="(card, index) in data.visits.cards" :key="card[0]" class="metric-card" :class="`metric-card--${index % 2 ? 'green' : 'blue'}`"><div class="metric-card__title"><Store :size="17" /><span>{{ card[0] }}</span></div><strong>{{ card[1] }}</strong><span class="metric-card__caption">{{ card[2] }}</span></article></div><div class="panel-grid panel-grid--wide"><article class="panel"><header class="panel__header"><div><span class="panel__kicker"><Activity :size="15" />到店频次</span><h2>月服务到店次数</h2></div></header><div class="vertical-bars"><div v-for="item in data.visits.frequency" :key="item.label" class="vertical-bar"><b>{{ number(item.value) }}</b><div><i :style="{ height: `${item.value / maxVisit * 100}%` }"></i></div><span>{{ item.label }}</span></div></div></article><article class="panel"><header class="panel__header"><div><span class="panel__kicker"><CalendarDays :size="15" />到店波段</span><h2>最近一次到店分布</h2></div></header><div class="horizontal-bars horizontal-bars--compact"><div v-for="item in data.visits.recency" :key="item.label" class="horizontal-bar"><span>{{ item.label }}</span><div><i :style="{ width: `${item.value / maxRecency * 100}%` }"></i></div><b>{{ number(item.value) }}</b></div></div></article></div></section>

          <section v-else-if="active === 'health'" class="module-stack"><div class="panel"><header class="panel__header"><div><span class="panel__kicker"><HeartPulse :size="15" />门店运营</span><h2>门店健康数据分析</h2></div><button type="button" class="link-button">列名取值来源</button></header><div class="table-wrap"><table class="data-table data-table--health"><thead><tr><th>月份</th><th>门店数</th><th>活客数</th><th>消费人数</th><th>消费率</th><th>售后人数</th><th>人均产值</th><th>月均服务人次</th><th>生美消耗</th><th>人均消耗</th><th>店均业绩</th></tr></thead><tbody><tr v-for="row in data.health" :key="row[0]"><td>{{ row[0] }}</td><td>{{ number(row[1]) }}</td><td>{{ number(row[2]) }}</td><td>{{ number(row[3]) }}</td><td class="value-green">{{ pct(row[4]) }}</td><td>{{ number(row[5]) }}</td><td>{{ money(row[6]) }}</td><td>{{ number(row[7]) }}</td><td>{{ money(row[8]) }}</td><td>{{ money(row[9]) }}</td><td class="value-blue">{{ money(row[10]) }}</td></tr></tbody><tfoot v-if="data.health.length"><tr><td>最近月份</td><td>{{ number(healthSummary.stores) }}</td><td>{{ number(healthSummary.active) }}</td><td>{{ number(healthSummary.consumers) }}</td><td>{{ pct(healthSummary.rate) }}</td><td>{{ number(healthSummary.postSale) }}</td><td>{{ money(healthSummary.output) }}</td><td>{{ number(healthSummary.visits) }}</td><td>{{ money(healthSummary.consumption) }}</td><td>{{ money(healthSummary.avgConsumption) }}</td><td>{{ money(healthSummary.avgAmount) }}</td></tr></tfoot></table></div></div></section>

          <section v-else-if="active === 'tiers'" class="module-stack"><div class="module-toolbar"><div class="segmented"><button type="button" :class="{ 'is-active': consumptionMode === 'cash' }" @click="consumptionMode = 'cash'; runQuery()">现金业绩</button><button type="button" :class="{ 'is-active': consumptionMode === 'consumption' }" @click="consumptionMode = 'consumption'; runQuery()">消耗业绩</button></div><span>按{{ consumptionMode === 'cash' ? '现金业绩' : '消耗业绩' }}分层 · 当前自然年</span></div><div class="panel"><header class="panel__header"><div><span class="panel__kicker"><WalletCards :size="15" />消费分层</span><h2>客户消费分级分析</h2></div></header><div class="table-wrap"><table class="data-table"><thead><tr><th>消费分级</th><th>消费人数</th><th>人数占比</th><th>消费业绩</th><th>业绩占比</th><th>消费单产</th></tr></thead><tbody><tr v-for="row in data.tiers" :key="row[0]"><td><span class="tier-dot"></span>{{ row[0] }}</td><td>{{ number(row[1]) }}</td><td>{{ pct(row[2]) }}</td><td class="value-blue">{{ money(row[3]) }}</td><td>{{ pct(row[4]) }}</td><td>{{ money(row[5]) }}</td></tr></tbody><tfoot v-if="data.tiers.length"><tr><td>合计</td><td>{{ number(tierSummary.people) }}</td><td>{{ pct(tierSummary.people ? 100 : null) }}</td><td class="value-blue">{{ money(tierSummary.amount) }}</td><td>{{ pct(tierSummary.amount ? 100 : null) }}</td><td>{{ money(tierSummary.average) }}</td></tr></tfoot></table></div></div></section>


          <section v-else-if="active === 'cash'" class="module-stack"><div class="metric-grid metric-grid--four"><article class="metric-card metric-card--blue"><strong>{{ money(cashCards.amount * (cashCards.freshRate || 0) / 100) }}</strong><span>新客 {{ number(cashCards.fresh) }} 人 · {{ pct(cashCards.freshRate) }}</span></article><article class="metric-card metric-card--green"><strong>{{ money(cashCards.amount * (cashCards.oldRate || 0) / 100) }}</strong><span>老客 {{ number(cashCards.old) }} 人 · {{ pct(cashCards.oldRate) }}</span></article><article class="metric-card metric-card--amber"><strong>-</strong><span>接口未返回转化事实</span></article><article class="metric-card metric-card--purple"><strong>{{ money(cashCards.average) }}</strong><span>按接口返回人数</span></article></div><div class="panel"><h2>新老客户明细</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>分公司</th><th>老客数</th><th>新客数</th><th>老客占比</th><th>新客占比</th><th>现金业绩</th></tr></thead><tbody><tr v-for="row in data.cash.branches" :key="row[0]"><td>{{ row[0] }}</td><td>{{ number(row[1]) }}</td><td>{{ number(row[2]) }}</td><td>{{ pct(row[3]) }}</td><td>{{ pct(row[4]) }}</td><td>{{ money(row[5]) }}</td></tr></tbody></table></div></div></section>
         <section v-else-if="active === 'returns'" class="module-stack">
            <div class="metric-grid metric-grid--three">
              <article v-for="card in data.returns.cards.slice(0, 3)" :key="card[0]" class="metric-card" :class="`metric-card--${card[3]}`">
                <div class="metric-card__title"><TriangleAlert :size="17" /><span>{{ card[0] }}</span></div>
                <strong>{{ card[1] }}</strong><span class="metric-card__caption">{{ card[2] }}</span>
              </article>
            </div>

            <div class="panel">
              <header class="panel__header"><div><span class="panel__kicker"><CircleDollarSign :size="15" />退款项目分析</span><h2>退货项目占比</h2></div><span class="panel__hint">按退款金额</span></header>
              <div class="refund-pie-grid">
                <div class="refund-pie" :style="refundPieStyle(data.returns.itemProportions)" aria-label="退货项目占比饼图"><div class="refund-pie__hole"><strong>退款项目</strong><span>金额占比</span></div></div>
                <div class="legend-list legend-list--appearance"><div v-for="item in data.returns.itemProportions" :key="item[0]"><i :style="{ background: item[2] }"></i><span>{{ item[0] }}</span><b>{{ pct(item[1]) }}</b></div></div>
              </div>
            </div>

            <div class="refund-rank-grid">
              <article class="panel">
                <header class="panel__header"><div><span class="panel__kicker"><CircleDollarSign :size="15" />退款排行</span><h2>分公司退款金额</h2></div></header>
                <div class="horizontal-bars horizontal-bars--return horizontal-bars--scroll">
                  <div v-for="row in data.returns.branches" :key="row[0]" class="horizontal-bar"><span>{{ row[0] }}</span><div><i :class="`tone-${row[2]}`" :style="{ width: `${row[1] / maxReturn * 100}%` }"></i></div><b>{{ money(row[1]) }}</b></div>
                </div>
              </article>
              <article class="panel">
                <header class="panel__header"><div><span class="panel__kicker"><Users :size="15" />退款排行</span><h2>经理退款金额</h2></div></header>
                <div class="horizontal-bars horizontal-bars--return horizontal-bars--scroll">
                  <div v-for="row in data.returns.managers" :key="row[0]" class="horizontal-bar"><span>{{ row[0] }}</span><div><i :class="`tone-${row[2]}`" :style="{ width: `${row[1] / maxReturnManager * 100}%` }"></i></div><b>{{ money(row[1]) }}</b></div>
                </div>
              </article>
              <article class="panel">
                <header class="panel__header"><div><span class="panel__kicker"><Store :size="15" />退款排行</span><h2>门店退款金额</h2></div></header>
                <div class="horizontal-bars horizontal-bars--return horizontal-bars--scroll">
                  <div v-for="row in data.returns.stores" :key="row[0]" class="horizontal-bar"><span>{{ row[0] }}</span><div><i :class="`tone-${row[2]}`" :style="{ width: `${row[1] / maxReturnStore * 100}%` }"></i></div><b>{{ money(row[1]) }}</b></div>
                </div>
              </article>
            </div>

            <div class="panel">
              <header class="panel__header"><div><span class="panel__kicker"><TriangleAlert :size="15" />退款明细</span><h2>退款业绩明细</h2></div><button type="button" class="link-button">导出 <Download :size="14" /></button></header>
              <div class="table-wrap"><table class="data-table"><thead><tr><th>退款日期</th><th>分公司</th><th>门店</th><th>退款人数</th><th>退款项目</th><th>退款金额</th></tr></thead><tbody><tr v-for="row in data.returns.rows" :key="`${row[0]}-${row[1]}-${row[4]}`"><td>{{ row[0] }}</td><td>{{ row[1] }}</td><td>{{ row[2] }}</td><td>{{ number(row[3]) }}</td><td>{{ row[4] }}</td><td class="value-red">{{ row[5] }}</td></tr></tbody></table></div>
            </div>

          </section>

          <section v-else-if="active === 'appearance'" class="module-stack">
            <div class="panel-grid panel-grid--wide">
              <article class="panel"><header class="panel__header"><div><span class="panel__kicker"><Tags :size="15" />品项结构</span><h2>客户品项现金业绩占比</h2></div><span class="panel__hint">前6名 + 其他</span></header><div class="appearance-chart"><div class="appearance-donut" :style="appearanceDonutStyle(data.appearance.items)"><div><strong>{{ money(appearanceTotal) }}</strong><span>品项现金业绩</span></div></div><div class="legend-list legend-list--appearance"><div v-for="(row, index) in data.appearance.items.slice(0, 6)" :key="row[0]"><i :class="`legend-color legend-color--${index + 1}`"></i><span>{{ row[0] }}</span><b>{{ pct(row[5]) }}</b></div></div></div></article>
              <article class="panel panel--callout"><div class="callout-icon callout-icon--green"><Tags :size="23" /></div><h3>品项经营提示</h3><p>前六名占比和品项金额均以接口返回的当前筛选结果为准。</p></article>
            </div>

            <div class="panel">
              <header class="panel__header"><div><span class="panel__kicker"><Tags :size="15" />品项明细</span><h2>客户品项现金业绩排行</h2></div><button type="button" class="link-button">列名取值来源</button></header>
              <div class="table-wrap"><table class="data-table"><thead><tr><th>品项名称</th><th>现金业绩</th><th>成交人数</th><th>成交单产</th><th>人头占比</th><th>业绩占比</th><th>体验人数</th><th>成交率</th></tr></thead><tbody><tr v-for="row in appearanceCashRows" :key="row[0]"><td>{{ row[0] }}</td><td class="value-blue">{{ money(row[1]) }}</td><td>{{ number(row[2]) }}</td><td>{{ money(row[3]) }}</td><td>{{ pct(row[4]) }}</td><td>{{ pct(row[5]) }}</td><td>{{ number(row[6]) }}</td><td class="value-green">{{ pct(row[7]) }}</td></tr></tbody></table></div><footer v-if="data.appearance.items.length > rankingPageSize" class="ranking-pagination"><span>共 {{ data.appearance.items.length }} 条</span><button type="button" :disabled="appearanceCashPage <= 1" @click="changeAppearancePage('cash', appearanceCashPage - 1)">上一页</button><span>第 {{ appearanceCashPage }} / {{ appearanceCashPages }} 页</span><button type="button" :disabled="appearanceCashPage >= appearanceCashPages" @click="changeAppearancePage('cash', appearanceCashPage + 1)">下一页</button></footer>
            </div>

            <div class="panel">
              <header class="panel__header"><div><span class="panel__kicker"><TrendingUp :size="15" />品项明细</span><h2>客户品项消耗业绩排行</h2></div><button type="button" class="link-button">列名取值来源</button></header>
              <div class="table-wrap"><table class="data-table"><thead><tr><th>品项名称</th><th>消耗业绩</th><th>消耗人数</th><th>消耗单价</th><th>消耗占比</th></tr></thead><tbody><tr v-for="row in appearanceConsumptionRows" :key="`consumption-${row[0]}`"><td>{{ row[0] }}</td><td class="value-blue">{{ money(row[1]) }}</td><td>{{ number(row[2]) }}</td><td>{{ money(row[3]) }}</td><td class="value-green">{{ pct(row[5]) }}</td></tr></tbody></table></div><footer v-if="data.appearance.consumptionItems.length > rankingPageSize" class="ranking-pagination"><span>共 {{ data.appearance.consumptionItems.length }} 条</span><button type="button" :disabled="appearanceConsumptionPage <= 1" @click="changeAppearancePage('consumption', appearanceConsumptionPage - 1)">上一页</button><span>第 {{ appearanceConsumptionPage }} / {{ appearanceConsumptionPages }} 页</span><button type="button" :disabled="appearanceConsumptionPage >= appearanceConsumptionPages" @click="changeAppearancePage('consumption', appearanceConsumptionPage + 1)">下一页</button></footer>
            </div>
          </section>
          <section v-if="active === 'unconsumed'" class="module-stack">
            <div class="metric-grid metric-grid--three"><article v-for="card in data.unconsumed.cards" :key="card[0]" class="metric-card" :class="`metric-card--${card[3]}`"><div class="metric-card__title"><WalletCards :size="17" /><span>{{ card[0] }}</span></div><strong>{{ card[1] }}</strong><span class="metric-card__caption">{{ card[2] }}</span></article></div>
            <div class="panel-grid panel-grid--wide"><article class="panel"><header class="panel__header"><div><span class="panel__kicker"><Activity :size="15" />分公司未耗数</span><h2>分公司未耗数柱状图</h2></div><span class="panel__hint">金额 / 数量</span></header><div class="unconsumed-company-chart"><div class="unconsumed-company-chart__legend"><span><i class="legend-color legend-color--current"></i>未耗金额</span><span><i class="legend-color legend-color--previous"></i>未耗数量</span></div><div v-for="row in data.unconsumed.branches" :key="row[0]" class="unconsumed-company-row"><strong>{{ row[0] }}</strong><div class="unconsumed-company-row__bars"><div><i class="unconsumed-company-row__amount" :style="{ width: `${row[1] / maxUnconsumedBranchAmount * 100}%` }"></i></div><div><i class="unconsumed-company-row__count" :style="{ width: `${row[2] / maxUnconsumedBranchCount * 100}%` }"></i></div></div><b>{{ money(row[1]) }}</b><em>{{ number(row[2]) }}个</em></div></div></article><article class="panel"><header class="panel__header"><div><span class="panel__kicker"><Tags :size="15" />未耗品项</span><h2>前六名未耗品项分析</h2></div><span class="panel__hint">仅展示前六名</span></header><div class="unconsumed-pie-grid"><div class="unconsumed-pie" :style="refundPieStyle(data.unconsumed.itemProportions)" aria-label="前六名未耗品项占比饼图"><div class="unconsumed-pie__hole"><strong>未耗品项</strong><span>前六名占比</span></div></div><div class="legend-list legend-list--appearance"><div v-for="item in data.unconsumed.itemProportions" :key="item[0]"><i :style="{ background: item[2] }"></i><span>{{ item[0] }}</span><b>{{ money(item[3]) }}</b><em>{{ pct(item[1]) }}</em></div></div></div></article></div>
            <div class="panel-grid panel-grid--wide"><article class="panel"><header class="panel__header"><div><span class="panel__kicker"><Tags :size="15" />未耗品项排行</span><h2>前10名未耗品项</h2></div><button type="button" class="link-button">导出 <Download :size="14" /></button></header><div class="rank-list rank-list--stores"><div v-for="(row, index) in data.unconsumed.topItems" :key="row[0]" class="rank-list__row"><span class="rank-badge" :class="`rank-badge--${index + 1}`">{{ index + 1 }}</span><span>{{ row[0] }}</span><div><i :style="{ width: `${row[1] / data.unconsumed.topItems[0][1] * 100}%` }"></i></div><b>{{ money(row[1]) }}</b></div></div></article><article class="panel"><header class="panel__header"><div><span class="panel__kicker"><Store :size="15" />门店排行</span><h2>前10名未耗门店</h2></div><button type="button" class="link-button">导出 <Download :size="14" /></button></header><div class="rank-list rank-list--stores"><div v-for="(row, index) in data.unconsumed.stores" :key="row[0]" class="rank-list__row"><span class="rank-badge" :class="`rank-badge--${index + 1}`">{{ index + 1 }}</span><span>{{ row[0] }}</span><div><i :style="{ width: `${row[1] / maxStore * 100}%` }"></i></div><b>{{ money(row[1]) }}</b></div></div></article></div>
          </section>
        </template>
      </section>
    </div>
  </main>
</template>

<style scoped>
:global(html.customer-analytics-document),:global(html.customer-analytics-document body),:global(html.customer-analytics-document #app){min-width:0;width:100%;max-width:100%;overflow-x:hidden}
.customer-analytics{min-height:100vh;padding:18px 20px 42px;background:#f4f7fa;color:#26394d;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC",sans-serif}.customer-analytics *{box-sizing:border-box}.customer-analytics button,.customer-analytics input,.customer-analytics select{font:inherit}.customer-analytics h1,.customer-analytics h2,.customer-analytics h3,.customer-analytics p{margin:0}.customer-analytics__hero,.customer-analytics__layout{width:100%;max-width:1640px;margin:0 auto}.customer-analytics__hero{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:16px}.customer-analytics__eyebrow{display:inline-flex;align-items:center;gap:6px;color:#638099;font-size:12px;font-weight:650}.customer-analytics__hero h1{margin-top:5px;color:#1f3144;font-size:25px;line-height:1.3}.customer-analytics__hero p{margin-top:5px;color:#8495a6;font-size:12px}.customer-analytics__hero-actions{display:flex;align-items:center;gap:9px}.prototype-badge{border:1px solid #b9d7ee;border-radius:20px;padding:5px 10px;background:#eef7ff;color:#1769aa;font-size:11px;font-weight:700}.icon-button{display:grid;width:31px;height:31px;place-items:center;border:1px solid #d7e1ea;border-radius:5px;background:#fff;color:#4e718d;cursor:pointer}.source-panel{width:100%;max-width:1640px;margin:-4px auto 14px;border:1px solid #cadbe8;border-radius:8px;background:#fff;box-shadow:0 8px 20px rgba(29,65,93,.1);color:#536d82}.source-panel header{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;border-bottom:1px solid #edf1f5;font-size:13px}.source-panel header button{border:0;background:transparent;color:#1769aa;font-size:11px;cursor:pointer}.source-panel p{margin:0;padding:13px 14px;color:#8495a3;font-size:11px}.source-panel ul{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px 20px;margin:0;padding:12px 14px;list-style:none}.source-panel li{display:flex;gap:8px;line-height:1.5;font-size:11px}.source-panel li b{min-width:72px;color:#36566e}.source-panel li span{color:#728697}.source-panel footer{display:flex;flex-wrap:wrap;gap:14px;padding:9px 14px;border-top:1px solid #edf1f5;color:#8a9aa7;font-size:10px}.source-panel__warning{color:#b4761f}.customer-analytics__layout{display:grid;grid-template-columns:208px minmax(0,1fr);align-items:start;gap:16px}.customer-analytics__sidebar{position:sticky;top:12px;border:1px solid #dfe7ee;border-radius:8px;padding:15px 10px;background:#fff;box-shadow:0 4px 12px rgba(33,63,90,.035)}.sidebar-heading{display:flex;align-items:baseline;justify-content:space-between;padding:0 8px 12px;border-bottom:1px solid #edf1f5;color:#2d4358;font-size:14px;font-weight:750}.sidebar-heading small{color:#97a6b4;font-size:10px;font-weight:500}.nav-group{padding-top:12px}.nav-group__label{padding:0 8px 5px;color:#9aa8b5;font-size:10px;font-weight:700}.nav-item{display:flex;align-items:center;gap:7px;width:100%;min-height:35px;border:0;border-radius:5px;padding:7px 8px;background:transparent;color:#576e83;text-align:left;font-size:12px;cursor:pointer}.nav-item svg:first-child{color:#7f93a6}.nav-item svg:last-child{margin-left:auto}.nav-item:hover{background:#f1f7fc;color:#1769aa}.nav-item.is-active{background:#e8f4ff;color:#1769aa;font-weight:700}.nav-item.is-active svg:first-child{color:#1769aa}.customer-analytics__content{min-width:0}.query-card{position:relative;border:1px solid #dce6ef;border-radius:8px;background:#fff;box-shadow:0 4px 12px rgba(33,63,90,.035)}.query-card__row{display:flex;align-items:center;gap:9px;padding:11px 13px}.query-card__row--top{flex-wrap:wrap}.query-card__row--meta{justify-content:space-between;border-top:1px solid #edf1f5;color:#7e90a1;font-size:11px}.meta-status{display:inline-flex;align-items:center;gap:5px}.meta-status i{width:6px;height:6px;border-radius:50%;background:#3b9186}.scope-trigger,.button,.periods button,.segmented button{display:inline-flex;align-items:center;justify-content:center;gap:5px;min-height:32px;border:1px solid #d7e1ea;border-radius:5px;padding:5px 10px;background:#fff;color:#4d6880;font-size:12px;cursor:pointer}.scope-trigger{min-width:145px;justify-content:flex-start;color:#2f5878;font-weight:650}.scope-trigger svg:last-child{margin-left:auto;color:#8ba0b0}.is-rotated{transform:rotate(180deg)}.periods{display:flex;overflow:hidden;border:1px solid #d7e1ea;border-radius:5px}.periods button{border:0;border-right:1px solid #e6edf2;border-radius:0;color:#667c90}.periods button:last-child{border-right:0}.periods button.is-active,.segmented button.is-active{background:#e8f4ff;color:#1769aa;font-weight:700}.date-field,.select-field{display:flex;align-items:center;gap:5px;color:#6d8192;font-size:11px}.date-field input,.select-field select{min-height:32px;border:1px solid #d7e1ea;border-radius:5px;padding:4px 7px;background:#fff;color:#3c566e;font-size:12px}.select-field select{min-width:80px}.select-field span{font-weight:600}.select-field--demo{margin-left:auto}.query-actions{display:flex;gap:7px;margin-left:auto}.button--primary{border-color:#1769aa;background:#1769aa;color:#fff}.button:disabled{cursor:wait;opacity:.6}.query-card__row--meta>span:first-child{color:#506b82}.scope-panel{position:absolute;z-index:8;top:57px;left:13px;width:min(600px,calc(100% - 26px));border:1px solid #cadbe8;border-radius:7px;background:#fff;box-shadow:0 14px 32px rgba(29,65,93,.16)}.scope-panel header{display:flex;align-items:center;justify-content:space-between;padding:11px 13px;border-bottom:1px solid #edf1f5;color:#365069;font-size:12px}.scope-panel header button{border:0;background:transparent;color:#1769aa;font-size:11px;cursor:pointer}.scope-panel__body{display:grid;grid-template-columns:1fr 1fr;gap:0;max-height:280px;overflow:auto}.scope-tree,.scope-stores{padding:10px}.scope-stores{border-left:1px solid #edf1f5}.tree-root,.tree-node,.scope-stores button{display:flex;align-items:center;gap:6px;width:100%;border:0;border-radius:4px;padding:8px;background:transparent;color:#536d82;text-align:left;font-size:12px;cursor:pointer}.tree-node{padding-left:22px}.tree-root:hover,.tree-node:hover,.scope-stores button:hover,.tree-root.is-selected,.tree-node.is-selected,.scope-stores button.is-selected{background:#edf6fd;color:#1769aa;font-weight:650}.scope-stores p{margin:0 0 6px;color:#9aa8b5;font-size:11px}.state-panel{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;min-height:240px;margin-top:16px;border:1px solid #dce6ef;border-radius:8px;background:#fff;color:#7b8e9f;text-align:center}.state-panel strong{color:#445d72;font-size:15px}.state-panel span{font-size:12px}.state-panel svg{color:#4d84aa}.state-panel--error svg{color:#ba594c}.state-panel--error .button{margin-top:5px}.module-stack{display:flex;flex-direction:column;gap:15px;margin-top:16px}.metric-grid{display:grid;gap:11px}.metric-grid--six{grid-template-columns:repeat(6,minmax(0,1fr))}.metric-grid--five{grid-template-columns:repeat(5,minmax(0,1fr))}.metric-grid--four{grid-template-columns:repeat(4,minmax(0,1fr))}.metric-grid--three{grid-template-columns:repeat(3,minmax(0,1fr))}.metric-card{min-width:0;border:1px solid #dce6ef;border-top:3px solid #3980b5;border-radius:7px;padding:13px 14px;background:#fff;cursor:default}.metric-card--green{border-top-color:#3d9186}.metric-card--amber{border-top-color:#c88727}.metric-card--purple{border-top-color:#7668a9}.metric-card--red{border-top-color:#b95a4c}.metric-card--source{cursor:pointer}.metric-card--source.is-active{box-shadow:0 0 0 2px rgba(34,126,188,.14)}.metric-card__title{display:flex;align-items:center;gap:6px;color:#5c7286;font-size:12px;font-weight:700}.metric-card__title svg{color:#3980b5}.metric-card--green .metric-card__title svg{color:#3d9186}.metric-card--amber .metric-card__title svg{color:#c88727}.metric-card--purple .metric-card__title svg{color:#7668a9}.metric-card--red .metric-card__title svg{color:#b95a4c}.metric-card strong{display:block;overflow:hidden;margin:10px 0 6px;color:#1f638f;text-overflow:ellipsis;white-space:nowrap;font-size:23px;line-height:1.1}.metric-card--green strong{color:#31776f}.metric-card--amber strong{color:#b2731d}.metric-card--purple strong{color:#6c619b}.metric-card--red strong{color:#ad5045}.metric-card strong small{margin-left:4px;font-size:12px;font-weight:600}.metric-card__delta,.metric-card__caption{display:block;color:#8c9caa;font-size:10px}.metric-card__delta{color:#368a7d}.metric-card__delta.is-negative{color:#ad5045}.metric-card dl{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:8px}.metric-card dt{color:#98a5b0;font-size:10px}.metric-card dd{margin:3px 0 0;color:#49647a;font-size:12px;font-weight:650}.panel-grid{display:grid;gap:15px}.panel-grid--wide{grid-template-columns:minmax(0,1.5fr) minmax(280px,.8fr)}.panel{min-width:0;border:1px solid #dce6ef;border-radius:8px;padding:15px;background:#fff}.panel__header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:13px}.panel__header h2{margin-top:3px;color:#2d4358;font-size:16px}.panel__kicker{display:inline-flex;align-items:center;gap:5px;color:#8091a1;font-size:11px}.panel__kicker svg{color:#347baa}.panel__hint{color:#97a5b1;font-size:11px}.link-button{display:inline-flex;align-items:center;gap:3px;border:0;background:transparent;color:#1769aa;font-size:11px;cursor:pointer}.line-chart{position:relative;height:230px;padding:10px 8px 22px 37px}.line-chart__grid{position:absolute;inset:13px 8px 31px 35px;display:flex;justify-content:space-between;flex-direction:column;color:#a0aeba;font-size:9px}.line-chart__grid span{display:block;border-top:1px dashed #e6edf2;padding-top:2px}.line-chart svg{position:absolute;inset:18px 12px 31px 42px;width:calc(100% - 54px);height:calc(100% - 49px);overflow:visible}.line-chart polyline{fill:none;stroke:#2474a6;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.5}.line-chart__labels{position:absolute;right:9px;bottom:5px;left:42px;display:flex;justify-content:space-between;color:#91a0ad;font-size:9px}.structure-panel{padding-bottom:12px}.donut{width:136px;height:136px;margin:2px auto 12px;border-radius:50%;background:conic-gradient(#3781b7 0 11%,#3b9186 11% 79%,#c88727 79% 83%,#8697a8 83% 100%);display:grid;place-items:center}.donut__hole{display:flex;align-items:center;justify-content:center;flex-direction:column;width:87px;height:87px;border-radius:50%;background:#fff}.donut__hole strong{color:#2c475d;font-size:18px}.donut__hole span{margin-top:2px;color:#90a0ae;font-size:10px}.legend-list{display:flex;flex-direction:column;gap:7px}.legend-list>div{display:grid;grid-template-columns:8px 1fr auto auto;align-items:center;gap:6px;color:#62778b;font-size:11px}.legend-list i{width:8px;height:8px;border-radius:50%}.legend-list b{color:#38566c;font-size:11px}.legend-list em{width:35px;color:#8999a7;font-size:10px;font-style:normal;text-align:right}.horizontal-bars{display:flex;flex-direction:column;gap:13px;padding:7px 6px}.horizontal-bar{display:grid;grid-template-columns:95px minmax(0,1fr) 65px 38px;align-items:center;gap:8px;color:#647b8e;font-size:11px}.horizontal-bar>div{height:9px;overflow:hidden;border-radius:8px;background:#edf2f5}.horizontal-bar>div i{display:block;height:100%;border-radius:8px;background:#4188b5}.horizontal-bar>b{color:#3f5c72;text-align:right;font-size:11px}.horizontal-bar>em{color:#8f9faa;font-size:10px;font-style:normal;text-align:right}.horizontal-bars--compact{gap:15px}.horizontal-bars--compact .horizontal-bar{grid-template-columns:92px minmax(0,1fr) 58px}.horizontal-bars--return .horizontal-bar{grid-template-columns:95px minmax(0,1fr) 78px}.panel--callout{display:flex;align-items:flex-start;justify-content:center;flex-direction:column;min-height:180px}.callout-icon{display:grid;width:43px;height:43px;place-items:center;border-radius:10px;background:#fff2dc;color:#b7791f}.callout-icon--green{background:#e8f6f2;color:#3b9186}.panel--callout h3{margin-top:12px;color:#3e5569;font-size:15px}.panel--callout p{margin-top:7px;color:#81919f;font-size:12px;line-height:1.7}.panel--callout strong{color:#b7791f}.panel--callout .positive{color:#398d7f}.panel--warning .callout-icon{background:#fff0ed;color:#b95a4c}.panel--warning strong{color:#b95a4c}.channel-list{display:flex;flex-direction:column;gap:13px}.channel-row{display:grid;grid-template-columns:22px minmax(80px,100px) minmax(0,1fr) 55px 42px;align-items:center;gap:7px;color:#5e7589;font-size:11px}.channel-row__rank{display:grid;width:20px;height:20px;place-items:center;border-radius:50%;background:#edf3f7;color:#7890a2;font-size:10px}.channel-row__name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.channel-row__track,.branch-row__bar{height:9px;overflow:hidden;border-radius:6px;background:#edf2f5}.channel-row__track i{display:block;height:100%;border-radius:6px;background:#4788b3}.channel-row__track .tone-green{background:#3b9186}.channel-row__track .tone-amber{background:#c88727}.channel-row__track .tone-purple{background:#7668a9}.channel-row__track .tone-slate{background:#8697a8}.channel-row__track .tone-red{background:#b95a4c}.channel-row b,.channel-row em{color:#3f5c72;font-size:11px;text-align:right}.channel-row em{color:#8b9ba8;font-style:normal}.source-summary>strong{display:block;color:#2670a0;font-size:30px}.source-summary>strong small{margin-left:4px;color:#8a9aa7;font-size:12px}.source-summary dl{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:17px 0}.source-summary dt{color:#9aa8b4;font-size:10px}.source-summary dd{margin-top:3px;color:#3f5e74;font-size:12px;font-weight:700}.source-summary p{padding-top:11px;border-top:1px solid #edf1f5;color:#8998a6;font-size:11px;line-height:1.6}.vertical-bars{display:flex;align-items:flex-end;justify-content:space-around;height:220px;padding:12px 15px 0}.vertical-bar{display:flex;align-items:center;flex:1;flex-direction:column;gap:5px;height:100%;color:#7f909f;font-size:10px}.vertical-bar>b{height:15px;color:#47647a;font-size:11px}.vertical-bar>div{display:flex;align-items:flex-end;justify-content:center;width:30px;flex:1;border-bottom:1px solid #dfe8ee}.vertical-bar i{display:block;width:100%;min-height:6px;border-radius:5px 5px 0 0;background:#4187b4}.vertical-bar:nth-child(2n) i{background:#66a2b9}.vertical-bar span{padding-top:4px}.module-toolbar{display:flex;align-items:center;justify-content:space-between;color:#80909d;font-size:11px}.segmented{display:flex;overflow:hidden;border:1px solid #d7e1ea;border-radius:5px}.segmented button{border:0;border-right:1px solid #e5edf2;border-radius:0}.segmented button:last-child{border:0}.table-wrap{overflow:auto;border:1px solid #e1e8ee;border-radius:6px}.data-table{width:100%;min-width:820px;border-collapse:collapse}.data-table th{padding:10px 12px;background:#f7f9fb;color:#8493a0;text-align:left;font-size:10px;font-weight:700;white-space:nowrap}.data-table td{border-top:1px solid #edf1f5;padding:11px 12px;color:#506a7d;font-size:11px;white-space:nowrap}.data-table tbody tr:hover{background:#f9fbfd}.data-table tfoot td{border-top:2px solid #dfe7ee;background:#f8fafc;color:#36536a;font-weight:700}.value-blue{color:#1f6998!important;font-weight:700}.value-green{color:#398b7f!important;font-weight:700}.value-red{color:#b25348!important;font-weight:700}.tier-dot{display:inline-block;width:7px;height:7px;margin-right:6px;border-radius:50%;background:#4187b4}.data-table tbody tr:nth-child(2) .tier-dot{background:#3b9186}.data-table tbody tr:nth-child(3) .tier-dot{background:#c88727}.branch-chart{display:flex;flex-direction:column;gap:15px;padding:4px 4px}.branch-row{display:grid;grid-template-columns:100px minmax(0,1fr) 95px;align-items:center;gap:10px;color:#5c7184;font-size:11px}.branch-row__bar{display:flex}.branch-row__bar i{display:block;height:100%;min-height:9px}.branch-row__old{background:#3982b5}.branch-row__new{background:#d29b4b}.branch-row>b{color:#3f5c72;text-align:right}.status-chip{display:inline-flex;align-items:center;border-radius:4px;padding:4px 8px;background:#eff3f6;color:#61788a;font-size:10px}.status-chip--success{background:#e9f7f1;color:#398472}.status-chip--warning{background:#fff3df;color:#b4761f}.appearance-chart{display:grid;grid-template-columns:180px minmax(0,1fr);align-items:center;gap:18px;min-height:190px}.appearance-donut{width:164px;height:164px;border-radius:50%;background:conic-gradient(#3681b2 0 23%,#3d9187 23% 43%,#c88727 43% 60%,#776aa7 60% 75%,#62a8ae 75% 86%,#bb6b52 86% 95%,#dfe7ec 95%);display:grid;place-items:center}.appearance-donut>div{display:flex;align-items:center;justify-content:center;flex-direction:column;width:104px;height:104px;border-radius:50%;background:#fff;text-align:center}.appearance-donut strong{color:#2d526d;font-size:13px}.appearance-donut span{margin-top:4px;color:#8a9aa7;font-size:10px}.legend-list--appearance{gap:10px}.legend-list--appearance>div{grid-template-columns:9px 1fr auto}.legend-list--appearance em{display:none}.legend-color{display:inline-block;width:9px;height:9px;border-radius:50%;background:#3982b5}.legend-color--1{background:#3681b2}.legend-color--2{background:#3d9187}.legend-color--3{background:#c88727}.legend-color--4{background:#776aa7}.legend-color--5{background:#62a8ae}.legend-color--6{background:#bb6b52}.legend-color--current{background:#3982b5}.legend-color--previous{background:#cdd8df}.rank-list{display:flex;flex-direction:column;gap:9px}.rank-list__row{display:grid;grid-template-columns:22px minmax(100px,1.2fr) minmax(80px,1fr) 86px;align-items:center;gap:8px;color:#5d7488;font-size:11px}.rank-list__row>span:nth-child(2){overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rank-list__row>div{height:8px;overflow:hidden;border-radius:6px;background:#edf2f5}.rank-list__row>div i{display:block;height:100%;border-radius:6px;background:#4c8bad}.rank-list__row>b{color:#3e5c72;text-align:right}.rank-badge{display:grid;width:20px;height:20px;place-items:center;border-radius:50%;background:#e8eff4;color:#6a8092;font-size:10px}.rank-badge--1{background:#d49b2c;color:#fff}.rank-badge--2{background:#b68162;color:#fff}.rank-badge--3{background:#d6c978;color:#5e552d}.comparison-chart{display:flex;align-items:flex-end;justify-content:space-around;height:195px;padding:15px 10px 0;border-bottom:1px solid #dfe8ee}.comparison-chart__legend{position:absolute;display:flex;gap:10px;margin-top:-170px;color:#8493a0;font-size:10px}.comparison-chart__legend span{display:inline-flex;align-items:center;gap:4px}.comparison-column{display:flex;align-items:center;flex:1;flex-direction:column;gap:6px;height:100%;color:#7f909f;font-size:10px}.comparison-column>div{display:flex;align-items:flex-end;justify-content:center;gap:3px;width:42px;flex:1}.comparison-column i{display:block;width:14px;border-radius:4px 4px 0 0}.comparison-column__current{background:#3982b5}.comparison-column__previous{background:#cdd8df}.comparison-column span{padding-bottom:5px}.is-spinning{animation:customer-analytics-spin .75s linear infinite}@keyframes customer-analytics-spin{to{transform:rotate(360deg)}}
@media(max-width:1260px){.metric-grid--six{grid-template-columns:repeat(3,minmax(0,1fr))}.metric-grid--five{grid-template-columns:repeat(3,minmax(0,1fr))}.query-card__row--top .select-field--demo{margin-left:0}.customer-analytics__layout{grid-template-columns:190px minmax(0,1fr)}}
@media(max-width:900px){.customer-analytics{padding:12px}.customer-analytics__layout{display:block}.customer-analytics__sidebar{position:static;display:flex;gap:10px;margin-bottom:12px;overflow:auto;padding:9px}.sidebar-heading,.nav-group__label{display:none}.nav-group{display:flex;gap:5px;padding:0}.nav-item{min-width:max-content}.nav-item svg:last-child{display:none}.panel-grid--wide{grid-template-columns:1fr}.select-field--demo{margin-left:0}.query-card__row--meta{align-items:flex-start;flex-direction:column;gap:4px}}
@media(max-width:620px){.customer-analytics__hero h1{font-size:21px}.metric-grid--six,.metric-grid--five,.metric-grid--four,.metric-grid--three{grid-template-columns:repeat(2,minmax(0,1fr))}.query-card__row--top{align-items:stretch;flex-direction:column}.scope-trigger,.periods,.date-field,.select-field,.query-actions{width:100%}.query-actions .button{flex:1}.appearance-chart{grid-template-columns:1fr}.appearance-donut{margin:auto}.horizontal-bar{grid-template-columns:76px minmax(0,1fr) 52px}.channel-row{grid-template-columns:20px 80px minmax(0,1fr) 45px 35px}.rank-list__row{grid-template-columns:22px minmax(90px,1fr) 90px}.rank-list__row>b{font-size:10px}.rank-list--stores .rank-list__row{grid-template-columns:22px minmax(100px,1fr) 84px}.scope-panel__body{grid-template-columns:1fr}.scope-stores{border-top:1px solid #edf1f5;border-left:0}.customer-analytics__hero-actions{display:none}.source-panel ul{grid-template-columns:1fr}.source-panel li{display:block}.source-panel li b{display:block;margin-bottom:2px}}
.refund-rank-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px}.refund-rank-grid .panel{min-width:0}.horizontal-bars--scroll{max-height:330px;overflow-y:auto;scroll-behavior:smooth;padding-right:9px}.horizontal-bars--scroll::-webkit-scrollbar{width:5px}.horizontal-bars--scroll::-webkit-scrollbar-thumb{border-radius:5px;background:#c7d6e0}.refund-pie-grid,.unconsumed-pie-grid{display:grid;grid-template-columns:220px minmax(0,1fr);align-items:center;gap:20px;min-height:210px}.refund-pie,.unconsumed-pie{width:190px;height:190px;border-radius:50%;display:grid;place-items:center}.refund-pie__hole,.unconsumed-pie__hole{display:flex;align-items:center;justify-content:center;flex-direction:column;width:112px;height:112px;border-radius:50%;background:#fff;box-shadow:0 0 0 1px #edf1f5;color:#6d8293}.refund-pie__hole strong,.unconsumed-pie__hole strong{color:#2d526d;font-size:13px}.refund-pie__hole span,.unconsumed-pie__hole span{margin-top:4px;color:#8a9aa7;font-size:10px}.unconsumed-company-chart{display:flex;flex-direction:column;gap:10px}.unconsumed-company-chart__legend{display:flex;gap:13px;justify-content:flex-end;color:#8493a0;font-size:10px}.unconsumed-company-chart__legend span{display:inline-flex;align-items:center;gap:4px}.unconsumed-company-row{display:grid;grid-template-columns:78px minmax(0,1fr) 72px 50px;align-items:center;gap:7px;color:#5d7488;font-size:10px}.unconsumed-company-row strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}.unconsumed-company-row__bars{display:flex;flex-direction:column;gap:4px}.unconsumed-company-row__bars>div{height:7px;overflow:hidden;border-radius:5px;background:#edf2f5}.unconsumed-company-row__bars i{display:block;height:100%;min-width:4px;border-radius:5px}.unconsumed-company-row__amount{background:#3982b5}.unconsumed-company-row__count{background:#cdd8df}.unconsumed-company-row>b,.unconsumed-company-row>em{text-align:right;font-size:10px}.unconsumed-company-row>b{color:#3f5c72}.unconsumed-company-row>em{color:#8493a0;font-style:normal}@media(max-width:900px){.refund-rank-grid{grid-template-columns:1fr}}@media(max-width:620px){.refund-pie-grid,.unconsumed-pie-grid{grid-template-columns:1fr}.refund-pie,.unconsumed-pie{margin:auto}.unconsumed-company-row{grid-template-columns:70px minmax(0,1fr) 66px 44px}}
.source-card__body{display:grid;grid-template-columns:minmax(92px,1fr) minmax(0,2fr);align-items:center;gap:12px;margin-top:8px}.source-card__conversion{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:6px;min-height:78px;border-right:1px solid #edf1f5;color:#98a5b0;font-size:10px}.source-card__body dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:0}.conversion-rate{display:flex;align-items:center;min-height:30px}.conversion-donut{display:grid;width:29px;height:29px;place-items:center;border-radius:50%}.conversion-donut--large{width:76px;height:76px}.conversion-donut i{display:grid;width:21px;height:21px;place-items:center;border-radius:50%;background:#fff;color:#35627d;font-size:8px;font-style:normal;font-weight:700}.conversion-donut--large i{width:54px;height:54px;font-size:13px}
.source-card__body dl{grid-template-columns:repeat(3,minmax(0,1fr))}.metric-card--slate{border-top-color:#8697a8}.metric-card--slate .metric-card__title svg{color:#8697a8}
.ranking-pagination{display:flex;align-items:center;justify-content:flex-end;gap:9px;padding:10px 12px;border:1px solid #e1e8ee;border-top:0;border-radius:0 0 6px 6px;background:#fbfcfd;color:#6e8092;font-size:12px}.ranking-pagination button{min-height:28px;border:1px solid #d7e0e8;border-radius:4px;padding:4px 9px;background:#fff;color:#52697d;cursor:pointer}.ranking-pagination button:disabled{cursor:not-allowed;opacity:.45}
/* 平台 18081 以 iframe 承载时，导航由外层菜单提供，内容区占满可用宽度。 */
.customer-analytics__layout--embedded { display: block !important; }
/* 统一报表标题区保持紧凑：只保留左对齐的报表名称。 */
.customer-analytics__hero h1 { margin-top: 0; }
</style>
