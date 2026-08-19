<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import BookOpen from '@lucide/vue/dist/esm/icons/book-open.mjs'
import CircleAlert from '@lucide/vue/dist/esm/icons/circle-alert.mjs'
import CircleCheck from '@lucide/vue/dist/esm/icons/circle-check.mjs'
import Expand from '@lucide/vue/dist/esm/icons/expand.mjs'
import Funnel from '@lucide/vue/dist/esm/icons/funnel.mjs'
import Goal from '@lucide/vue/dist/esm/icons/goal.mjs'
import Hourglass from '@lucide/vue/dist/esm/icons/hourglass.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import TrendingDown from '@lucide/vue/dist/esm/icons/trending-down.mjs'
import TrendingUp from '@lucide/vue/dist/esm/icons/trending-up.mjs'
import {
  queryGroupManagementDashboard,
  queryGroupManagementDashboardScope,
  queryGroupManagementDashboardDrilldown,
  queryGroupManagementDashboardTargets,
  saveGroupManagementDashboardTarget
} from '@/services/groupManagementDashboardApi'

const dashboardElement = ref(null)
const DASHBOARD_REFRESH_INTERVAL_MS = 15 * 60 * 1000
const dashboard = ref(null)
const targets = ref(null)
const loading = ref(false)
const targetLoading = ref(false)
const targetSaving = ref(false)
const errorMessage = ref('')
const targetError = ref('')
const period = ref('today')
const selectedCategoryId = ref(0)
const customStart = ref(today())
const customEnd = ref(today())
const fieldGuideOpen = ref(false)
const fieldGuideFocus = ref('')
const targetDrawerOpen = ref(false)
const presentationMode = ref(false)
const categoryRankTypes = ref({})
const targetDrafts = ref({})
const selectedTargetYear = ref(String(new Date().getFullYear()))
const drilldown = ref(null)
const drilldownLoading = ref(false)
const drilldownError = ref('')
const scopePicker = ref({ open: false, loading: false, tree: [], allowedStoreIds: [], selectedStoreIds: [], label: '当前权限范围', selectedOrganizationKey: '', selectedOrganizationName: '', stores: [] })
const expandedScopeKeys = ref(new Set())

function today() {
  const date = new Date()
  const pad = (value) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

function monthStart(date) {
  return `${String(date).slice(0, 7)}-01`
}

function yearStart(date) {
  return `${String(date).slice(0, 4)}-01-01`
}

function formatMoney(cents) {
  if (cents === null || cents === undefined || cents === '') return '-'
  const amount = Number(cents)
  if (!Number.isFinite(amount)) return '-'
  return `¥${(amount / 100).toLocaleString('zh-CN', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`
}

function formatRate(rate) {
  const value = Number(rate)
  return Number.isFinite(value) ? `${value.toFixed(1).replace(/\.0$/, '')}%` : '-'
}

function formatTrendValue(cents) {
  const value = Number(cents)
  if (!Number.isFinite(value)) return '-'
  const amount = value / 100
  return Math.abs(amount) >= 100000 ? `${Math.round(amount / 1000)}k` : amount.toLocaleString('zh-CN', { maximumFractionDigits: 0 })
}

function formatDraft(cents) {
  const value = Number(cents)
  return Number.isFinite(value) ? String(value / 100) : ''
}

function parseDraftToCents(value) {
  const text = String(value ?? '').trim()
  // Empty is an explicit dashboard-target clear. Numeric 0 remains a valid
  // zero target and must not be collapsed into the clear command.
  if (text === '') return ''
  if (!/^\d+(?:\.\d{1,2})?$/.test(text)) throw new Error('目标金额只能填写非负数字，最多保留两位小数。')
  return Math.round(Number(text) * 100)
}

function currentQuery() {
  const end = today()
  const range = period.value === 'today'
    ? { start_date: end, end_date: end }
    : period.value === 'month'
      ? { start_date: monthStart(end), end_date: end }
      : period.value === 'year'
        ? { start_date: yearStart(end), end_date: end }
        : { start_date: customStart.value, end_date: customEnd.value }
  return {
    ...range,
    category_id: selectedCategoryId.value || 0,
    ...(scopePicker.value.selectedStoreIds.length ? { store_ids: scopePicker.value.selectedStoreIds.join(',') } : {})
  }
}

const filterSchema = computed(() => Array.isArray(dashboard.value?.filter_schema) ? dashboard.value.filter_schema : [])
const categoryOptions = computed(() => filterSchema.value.find((field) => field.key === 'category_id')?.options || [])
const selectedCategoryLabel = computed(() => categoryOptions.value.find((item) => Number(item.value) === Number(selectedCategoryId.value))?.label || '全部商品')
const scopeLabel = computed(() => scopePicker.value.label || dashboard.value?.scope?.label || dashboard.value?.scope?.scope_label || '当前权限范围')
const cards = computed(() => Array.isArray(dashboard.value?.cards) ? dashboard.value.cards : [])
const categories = computed(() => Array.isArray(dashboard.value?.categories) ? dashboard.value.categories : [])
const categoryCards = computed(() => categories.value.filter((category) => {
  const name = String(category?.name || '').trim()
  return name && name !== '未分类' && category?.is_uncategorized !== true && Number(category?.is_uncategorized) !== 1
}))
const sources = computed(() => Array.isArray(dashboard.value?.sources?.records) ? dashboard.value.sources.records : [])
const alerts = computed(() => Array.isArray(dashboard.value?.alerts?.records) ? dashboard.value.alerts.records : [])
const fieldExplanations = computed(() => {
  const source = dashboard.value || {}
  return Object.fromEntries(Object.entries({
    ...(source.field_explanations || {}),
    trend: source.trend?.source_explanation,
    categories: source.categories?.[0]?.source_explanation,
    sources: source.sources?.source_explanation,
    goals: source.goals?.source_explanation,
    rankings: source.rankings?.source_explanation,
    alerts: source.alerts?.source_explanation
  }).filter(([, value]) => typeof value === 'string' && value.trim() !== ''))
})
const targetRows = computed(() => Array.isArray(targets.value?.stores) ? targets.value.stores : [])
const targetMonths = Object.freeze(Array.from({ length: 12 }, (_, index) => index + 1))

const targetProgressCards = computed(() => ['month', 'year'].map((key) => {
  const goal = dashboard.value?.goals?.[key] || {}
  const label = key === 'month' ? '本月目标进度' : '本年目标进度'
  return {
    key,
    title: goal.title || label,
    subtitle: key === 'month' ? '累计至所选截止日' : '累计至所选截止日',
    rate: goal.achievement_rate,
    metrics: [
      { key: 'target', label: key === 'month' ? '本月目标' : '年度目标', value: formatMoney(goal.target_amount_cents), tone: 'goal', icon: Goal },
      { key: 'actual', label: '累计完成', value: formatMoney(goal.actual_performance_cents), tone: 'complete', icon: CircleCheck },
      { key: 'rate', label: '达成率', value: formatRate(goal.achievement_rate), tone: 'rate', icon: TrendingUp },
      { key: 'remaining', label: '剩余目标', value: formatMoney(goal.remaining_amount_cents), tone: 'remaining', icon: Hourglass }
    ]
  }
}))

const targetRankings = computed(() => ['company', 'city_manager', 'store'].map((key) => ({ key, ...(dashboard.value?.rankings?.[key] || {}), records: dashboard.value?.rankings?.[key]?.records || [] })))

const trendChart = computed(() => {
  const trend = dashboard.value?.trend || {}
  const points = Array.isArray(trend.points) ? trend.points : []
  const max = Math.max(1, ...points.flatMap((point) => [Number(point.actual_performance_cents) || 0, Number(point.consumption_performance_cents) || 0]))
  const width = 720
  const height = 118
  const left = 24
  const top = 13
  const nodes = (key) => points.map((point, index) => {
    const value = Number(point[key]) || 0
    const x = points.length <= 1 ? left + width / 2 : left + (width / (points.length - 1)) * index
    const y = top + height - (value / max) * height
    return { id: `${point.id || index}-${key}`, x: x.toFixed(1), y: y.toFixed(1), value, label: formatTrendValue(value) }
  })
  const actualNodes = nodes('actual_performance_cents')
  const consumptionNodes = nodes('consumption_performance_cents')
  return {
    labels: points.map((point) => point.label || point.id),
    actualNodes,
    consumptionNodes,
    actualPoints: actualNodes.map((point) => `${point.x},${point.y}`).join(' '),
    consumptionPoints: consumptionNodes.map((point) => `${point.x},${point.y}`).join(' '),
    rangeLabel: trend.range_label || trend.source_explanation || ''
  }
})

function metricTone(metric = {}) {
  return {
    cash_performance: 'blue', refund_amount: 'red', actual_performance: 'green',
    consumption_performance: 'yellow', consumption_count: 'slate', consumption_unit_price: 'blue'
  }[metric.metric_code] || 'slate'
}

function metricIcon(metric = {}) {
  return {
    cash_performance: Goal, refund_amount: CircleAlert, actual_performance: TrendingUp,
    consumption_performance: TrendingDown, consumption_count: Funnel, consumption_unit_price: Goal
  }[metric.metric_code] || Goal
}

function categoryTone(index) {
  return ['teal', 'blue', 'rose', 'gold', 'purple', 'slate'][index % 6]
}

function categoryRankings(category) {
  return categoryRankTypes.value[category.category_id] === 'products'
    ? (category.product_rankings || [])
    : (category.project_rankings || [])
}

function categoryRankType(category) {
  return categoryRankTypes.value[category.category_id] || 'projects'
}

const scopeTreeOptions = computed(() => {
  const output = []
  const walk = (nodes, depth = 0, parentKey = '') => (Array.isArray(nodes) ? nodes : []).forEach((node, index) => {
    const storeId = Number(node?.store_id || (node?.node_type === 'store' ? node?.id : 0))
    const key = `${parentKey}/${node?.node_type || 'org'}-${node?.id || node?.org_id || index}`
    const hasChildren = Array.isArray(node?.children) && node.children.length > 0
    output.push({ node, depth, key, storeId, hasChildren, isExpanded: expandedScopeKeys.value.has(key) })
    if (hasChildren && expandedScopeKeys.value.has(key)) walk(node.children, depth + 1, key)
  })
  walk(scopePicker.value.tree)
  return output
})

function scopeNodeStoreIds(node) {
  const ids = []
  const walk = (item) => {
    const id = Number(item?.store_id || (item?.node_type === 'store' ? item?.id : 0))
    if (id > 0) ids.push(id)
    ;(Array.isArray(item?.children) ? item.children : []).forEach(walk)
  }
  walk(node)
  return [...new Set(ids)].filter((id) => scopePicker.value.allowedStoreIds.includes(id))
}

function scopeNodeStores(node) {
  const stores = []
  const walk = (item) => {
    const id = Number(item?.store_id || (item?.node_type === 'store' ? item?.id : 0))
    if (id > 0 && scopePicker.value.allowedStoreIds.includes(id)) stores.push({ id, name: item?.title || item?.name || `门店${id}` })
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

function selectScopeNode(option) {
  const ids = option.storeId > 0 ? [option.storeId] : scopeNodeStoreIds(option.node)
  if (!ids.length) return
  const name = option.node?.title || option.node?.name || '已选组织'
  scopePicker.value = { ...scopePicker.value, selectedStoreIds: ids, label: name, selectedOrganizationKey: option.key, selectedOrganizationName: name, stores: scopeNodeStores(option.node), open: true }
  void loadDashboard()
}

function selectScopeStore(store) {
  const id = Number(store?.id)
  if (!id || !scopePicker.value.allowedStoreIds.includes(id)) return
  scopePicker.value = { ...scopePicker.value, selectedStoreIds: [id], label: store.name || `门店${id}`, open: false }
  void loadDashboard()
}

function chooseAllScope() {
  scopePicker.value = { ...scopePicker.value, selectedStoreIds: [], label: '当前权限范围', selectedOrganizationKey: '', selectedOrganizationName: '', stores: [], open: false }
  void loadDashboard()
}

function setCategoryRankType(category, value) {
  categoryRankTypes.value = { ...categoryRankTypes.value, [category.category_id]: value }
}

function explanationFor(key, fallback = '') {
  return fieldExplanations.value[key] || fallback || ''
}

function openFieldGuide(key = '') {
  fieldGuideFocus.value = key
  fieldGuideOpen.value = true
}

async function showDrilldown(drill, label = '') {
  if (!drill?.metric_code) return
  const scope = dashboard.value?.scope || {}
  drilldown.value = {
    label: label || cards.value.find((item) => item.metric_code === drill.metric_code)?.name || '经营明细',
    conditions: {
      开始日期: scope.range?.start || currentQuery().start_date,
      截止日期: scope.range?.end || currentQuery().end_date,
      商品分类: drill.category_id ? categories.value.find((item) => Number(item.category_id) === Number(drill.category_id))?.name || '-' : selectedCategoryLabel.value
    },
    records: []
  }
  drilldownLoading.value = true
  drilldownError.value = ''
  try {
    const query = { ...currentQuery(), metric_code: drill.metric_code, ...(drill.category_id ? { category_id: drill.category_id } : {}) }
    const result = await queryGroupManagementDashboardDrilldown(query)
    drilldown.value.records = Array.isArray(result.records) ? result.records : []
    drilldown.value.explanation = result.source_explanation || ''
  } catch (error) {
    drilldownError.value = error?.message || '下钻明细加载失败。'
  } finally {
    drilldownLoading.value = false
  }
}

function showConditions(label, conditions = {}, explanation = '') {
  const scope = dashboard.value?.scope || {}
  drilldown.value = {
    label,
    conditions: {
      开始日期: scope.range?.start || currentQuery().start_date,
      截止日期: scope.range?.end || currentQuery().end_date,
      ...conditions
    },
    records: [],
    explanation
  }
  drilldownLoading.value = false
  drilldownError.value = ''
}

async function loadDashboard() {
  loading.value = true
  errorMessage.value = ''
  try {
    dashboard.value = await queryGroupManagementDashboard(currentQuery())
    if (!categoryOptions.value.some((item) => Number(item.value) === Number(selectedCategoryId.value))) selectedCategoryId.value = 0
  } catch (error) {
    dashboard.value = null
    errorMessage.value = error?.message || '集团管理看板加载失败。'
  } finally {
    loading.value = false
  }
}

function loadScope() {
  scopePicker.value = { ...scopePicker.value, loading: true }
  return queryGroupManagementDashboardScope().then((data) => {
    const allowedStoreIds = Array.isArray(data?.allowed_store_ids) ? data.allowed_store_ids.map(Number).filter(Boolean) : []
    scopePicker.value = { ...scopePicker.value, tree: data?.tree || [], allowedStoreIds, loading: false }
  }).catch((error) => {
    scopePicker.value = { ...scopePicker.value, loading: false, tree: [], allowedStoreIds: [] }
    errorMessage.value = error?.message || '权限范围读取失败。'
  })
}

async function selectPeriod(value) {
  period.value = value
  if (value !== 'custom') await loadDashboard()
}

async function toggleFullscreen() {
  if (document.fullscreenElement) {
    await document.exitFullscreen?.()
    presentationMode.value = false
    return
  }
  try {
    await dashboardElement.value?.requestFullscreen?.()
  } catch (_) {
    // 嵌入式浏览器可能禁止全屏；仍保留页面内的全屏展示状态。
  }
  presentationMode.value = true
}

function makeDrafts(payload) {
  const drafts = {}
  ;(payload?.stores || []).forEach((store) => {
    ;(store.months || []).forEach((cell) => { drafts[cell.subject_key] = cell.is_cleared ? '' : formatDraft(cell.target_amount_cents) })
  })
  return drafts
}

async function loadTargets() {
  targetLoading.value = true
  targetError.value = ''
  try {
    targets.value = await queryGroupManagementDashboardTargets({ year: selectedTargetYear.value, ...(scopePicker.value.selectedStoreIds.length ? { store_ids: scopePicker.value.selectedStoreIds.join(',') } : {}) })
    targetDrafts.value = makeDrafts(targets.value)
  } catch (error) {
    targets.value = null
    targetError.value = error?.message || '门店月度目标加载失败。'
  } finally {
    targetLoading.value = false
  }
}

async function openTargetDrawer() {
  targetDrawerOpen.value = true
  await loadTargets()
}

async function saveTargets() {
  if (!targets.value || targetSaving.value) return
  targetSaving.value = true
  targetError.value = ''
  try {
    for (const store of targetRows.value) {
      for (const cell of store.months || []) {
        const nextAmount = parseDraftToCents(targetDrafts.value[cell.subject_key])
        const currentAmount = cell.is_cleared ? '' : Number(cell.target_amount_cents || 0)
        if (nextAmount === currentAmount) continue
        await saveGroupManagementDashboardTarget({
          store_id: store.store_id,
          year: Number(selectedTargetYear.value),
          month: cell.month,
          target_amount_cents: nextAmount,
          expected_version: Number(cell.expected_version || 0),
          subject_key: cell.subject_key
        })
      }
    }
    await Promise.all([loadTargets(), loadDashboard()])
  } catch (error) {
    targetError.value = error?.message || '门店月度目标保存失败，请刷新后重试。'
  } finally {
    targetSaving.value = false
  }
}

let targetRankScrollFrame = null
let dashboardRefreshTimer = null
const targetRankScrollOffsets = new WeakMap()
function autoScrollTargetRankLists() {
  dashboardElement.value?.querySelectorAll('.dashboard-target-rank-list').forEach((list) => {
    if (list.querySelectorAll('button').length <= 8) return
    const maxScrollTop = list.scrollHeight - list.clientHeight
    if (maxScrollTop <= 1) return
    const currentOffset = targetRankScrollOffsets.get(list) ?? list.scrollTop
    const nextOffset = currentOffset >= maxScrollTop - 1 ? 0 : currentOffset + 0.14
    targetRankScrollOffsets.set(list, nextOffset)
    list.scrollTop = Math.floor(nextOffset)
  })
  targetRankScrollFrame = window.requestAnimationFrame(autoScrollTargetRankLists)
}

watch(selectedTargetYear, () => { if (targetDrawerOpen.value) loadTargets() })

function setDashboardDocumentScroll(enabled) {
  document.documentElement.classList.toggle('group-dashboard-document', enabled)
}

function refreshDashboardOnSchedule() {
  if (!loading.value) void loadDashboard()
}

onMounted(() => {
  setDashboardDocumentScroll(true)
  void loadScope().finally(loadDashboard)
  dashboardRefreshTimer = window.setInterval(refreshDashboardOnSchedule, DASHBOARD_REFRESH_INTERVAL_MS)
  targetRankScrollFrame = window.requestAnimationFrame(autoScrollTargetRankLists)
})

onBeforeUnmount(() => {
  setDashboardDocumentScroll(false)
  if (dashboardRefreshTimer) window.clearInterval(dashboardRefreshTimer)
  if (targetRankScrollFrame) window.cancelAnimationFrame(targetRankScrollFrame)
})
</script>

<template>
  <main ref="dashboardElement" class="group-dashboard" :class="{ 'is-presentation': presentationMode }" aria-label="集团管理看板">
    <header class="group-dashboard__header">
      <h1>{{ dashboard?.title || '集团管理看板' }}</h1>
    </header>

    <section class="group-dashboard__filters" aria-label="查询条件">
      <div class="group-dashboard__scope-picker">
        <button type="button" class="dashboard-select group-dashboard__scope-trigger" :disabled="scopePicker.loading" @click="scopePicker.open = !scopePicker.open"><Funnel :size="15" aria-hidden="true" />{{ scopePicker.loading ? '读取权限范围' : scopeLabel }}</button>
        <section v-if="scopePicker.open" class="group-dashboard__scope-panel" aria-label="组织和门店权限范围">
          <header>组织 / 门店</header>
          <div class="group-dashboard__scope-body">
            <div class="group-dashboard__scope-tree" aria-label="组织树">
              <p v-if="!scopeTreeOptions.length" class="group-dashboard__scope-empty">当前账号暂无可选择的组织范围。</p>
              <div v-for="option in scopeTreeOptions" :key="option.key" class="group-dashboard__scope-row" :style="{ paddingLeft: `${8 + option.depth * 16}px` }">
                <button v-if="option.hasChildren" type="button" class="group-dashboard__scope-toggle" :aria-expanded="option.isExpanded" @click="toggleScopeNode(option)">{{ option.isExpanded ? '−' : '+' }}</button><span v-else class="group-dashboard__scope-toggle-placeholder" aria-hidden="true"></span>
                <button type="button" class="group-dashboard__scope-option" :class="{ 'is-selected': scopePicker.selectedOrganizationKey === option.key }" @click="selectScopeNode(option)">{{ option.node?.title || option.node?.name || option.node?.label || '-' }}</button>
              </div>
            </div>
            <div class="group-dashboard__scope-stores" aria-label="可选门店">
              <strong>{{ scopePicker.selectedOrganizationName || '选择组织后查看下级门店' }}</strong>
              <button v-for="store in scopePicker.stores" :key="store.id" type="button" :class="{ 'is-active': scopePicker.selectedStoreIds.length === 1 && scopePicker.selectedStoreIds[0] === Number(store.id) }" @click="selectScopeStore(store)">{{ store.name }}</button>
              <span v-if="!scopePicker.stores.length">请选择组织或门店。</span>
            </div>
          </div>
          <footer><button type="button" @click="chooseAllScope">当前权限范围</button><span>组织选择包含下级门店；门店选择只查询该门店。</span></footer>
        </section>
      </div>
      <nav class="group-dashboard__periods" aria-label="日期范围"><button v-for="item in [['today','今天'], ['month','本月'], ['year','本年'], ['custom','自定义']]" :key="item[0]" type="button" :class="{ 'is-active': period === item[0] }" @click="selectPeriod(item[0])">{{ item[1] }}</button></nav>
      <label v-if="period === 'custom'" class="dashboard-date">从 <input v-model="customStart" type="date" /></label>
      <label v-if="period === 'custom'" class="dashboard-date">至 <input v-model="customEnd" type="date" /></label>
      <label class="dashboard-category-label">商品分类<select v-model.number="selectedCategoryId"><option v-for="item in categoryOptions" :key="item.value" :value="Number(item.value)">{{ item.label }}</option></select></label>
      <div class="group-dashboard__actions"><button type="button" class="button button--primary" :disabled="loading" @click="loadDashboard">查询</button><button type="button" class="button button--secondary" :disabled="loading" @click="loadDashboard"><RefreshCw :size="16" aria-hidden="true" />刷新</button><button type="button" class="button button--secondary" @click="openTargetDrawer"><Goal :size="16" aria-hidden="true" />目标管理</button><button type="button" class="button button--secondary" @click="toggleFullscreen"><Expand :size="16" aria-hidden="true" />{{ presentationMode ? '退出全屏' : '全屏' }}</button></div>
    </section>

    <p v-if="errorMessage" class="dashboard-state dashboard-state--error">{{ errorMessage }}</p><p v-else-if="loading" class="dashboard-state">正在加载集团管理看板...</p>
    <template v-else-if="dashboard">
      <section class="group-dashboard__section"><div class="group-dashboard__section-title"><div><h2>经营业绩</h2><span>按当前筛选范围</span></div><button type="button" class="dashboard-guide" @click="openFieldGuide()"><BookOpen :size="15" aria-hidden="true" />列名取值来源</button></div><div class="group-dashboard__metric-grid"><div v-for="metric in cards" :key="metric.metric_code" class="dashboard-card-wrap"><button type="button" class="dashboard-metric" :class="`dashboard-metric--${metricTone(metric)}`" :disabled="!metric.drilldown" @click="showDrilldown(metric.drilldown, metric.name)"><span class="dashboard-metric__icon"><component :is="metricIcon(metric)" :size="17" aria-hidden="true" /></span><span>{{ metric.name }}</span><strong>{{ metric.value_type === 'money' ? formatMoney(metric.value_cents) : (metric.value ?? '-') }}</strong><small>{{ metric.source_explanation }}</small></button><button type="button" class="dashboard-card-guide" :aria-label="`${metric.name}列名取值来源`" @click="openFieldGuide(metric.metric_code)"><BookOpen :size="14" aria-hidden="true" /></button></div></div></section>

      <section class="group-dashboard__section dashboard-trend-panel"><div class="group-dashboard__section-title"><div><h2>经营业绩趋势</h2><span>{{ trendChart.rangeLabel }}</span></div><div class="dashboard-trend-panel__tools"><span class="dashboard-trend-legend dashboard-trend-legend--actual">实际业绩</span><span class="dashboard-trend-legend dashboard-trend-legend--consumption">消耗业绩</span><button type="button" class="dashboard-guide" @click="openFieldGuide('trend')"><BookOpen :size="15" aria-hidden="true" />列名取值来源</button></div></div><div v-if="trendChart.labels.length" class="dashboard-trend-chart"><svg viewBox="0 0 768 148" role="img" aria-label="实际业绩与消耗业绩趋势图" preserveAspectRatio="none"><line v-for="y in [13, 52, 91, 131]" :key="y" x1="24" x2="744" :y1="y" :y2="y" class="dashboard-trend-chart__grid" /><polyline :points="trendChart.consumptionPoints" class="dashboard-trend-chart__line dashboard-trend-chart__line--consumption" /><polyline :points="trendChart.actualPoints" class="dashboard-trend-chart__line dashboard-trend-chart__line--actual" /><g v-for="point in trendChart.actualNodes" :key="point.id"><circle :cx="point.x" :cy="point.y" r="3.5" class="dashboard-trend-chart__dot dashboard-trend-chart__dot--actual" /><text :x="point.x" :y="Math.max(10, Number(point.y) - 8)" class="dashboard-trend-chart__value dashboard-trend-chart__value--actual">{{ point.label }}</text></g><g v-for="point in trendChart.consumptionNodes" :key="point.id"><circle :cx="point.x" :cy="point.y" r="3.5" class="dashboard-trend-chart__dot dashboard-trend-chart__dot--consumption" /><text :x="point.x" :y="Number(point.y) + 15" class="dashboard-trend-chart__value dashboard-trend-chart__value--consumption">{{ point.label }}</text></g></svg><div class="dashboard-trend-chart__labels" :style="{ '--label-count': trendChart.labels.length }"><span v-for="label in trendChart.labels" :key="label">{{ label }}</span></div></div><p v-else class="dashboard-empty">当前筛选范围暂无趋势数据。</p></section>

      <section class="group-dashboard__section"><div class="group-dashboard__section-title"><div><h2>分类业绩</h2><span>当前启用一级商品分类，默认显示项目业绩前五名</span></div><button type="button" class="dashboard-guide" @click="openFieldGuide('categories')"><BookOpen :size="15" aria-hidden="true" />列名取值来源</button></div><div class="group-dashboard__category-grid"><article v-for="(category, index) in categoryCards" :key="category.category_id" class="dashboard-category-panel" :class="`dashboard-category-panel--${categoryTone(index)}`"><div class="dashboard-card-wrap"><button type="button" class="dashboard-category-card" @click="showDrilldown(category.drilldown, `${category.name}现金业绩`)"><span>{{ category.name }}</span><strong>{{ formatMoney(category.cash_performance_cents) }}</strong><small>分类现金业绩</small><span class="dashboard-category-pie" :class="{ 'is-empty': category.share === null }" :style="{ '--category-progress': category.share || 0 }"><b>{{ formatRate(category.share) }}</b></span></button><button type="button" class="dashboard-card-guide" :aria-label="`${category.name}列名取值来源`" @click="openFieldGuide('categories')"><BookOpen :size="14" aria-hidden="true" /></button></div><div class="dashboard-category-rank__header"><strong>{{ categoryRankType(category) === 'projects' ? '项目业绩排行' : '产品业绩排行' }}</strong><div class="dashboard-category-rank__toggle" role="tablist"><button type="button" :class="{ 'is-active': categoryRankType(category) === 'projects' }" @click="setCategoryRankType(category, 'projects')">项目</button><button type="button" :class="{ 'is-active': categoryRankType(category) === 'products' }" @click="setCategoryRankType(category, 'products')">产品</button></div></div><div v-for="(item, rank) in categoryRankings(category)" :key="`${item.name}-${rank}`" class="dashboard-category-rank__row"><em>{{ rank + 1 }}</em><span>{{ item.name }}</span><b>{{ formatMoney(item.amount_cents) }}</b></div><p v-if="!categoryRankings(category).length" class="dashboard-category-rank__empty">暂无项目或产品排行</p></article></div></section>

      <section class="group-dashboard__section"><div class="group-dashboard__section-title"><div><h2>今日客情</h2><span>按实际启用来源动态展示，只有来源 A 显示活客数</span></div><button type="button" class="dashboard-guide" @click="openFieldGuide('sources')"><BookOpen :size="15" aria-hidden="true" />列名取值来源</button></div><div class="dashboard-source-grid"><button v-for="source in sources" :key="source.source_id || source.name" type="button" class="dashboard-source-card" @click="showConditions('今日客情统计条件', { 来源: source.name }, dashboard?.sources?.source_explanation)"><strong>{{ source.name }}</strong><span class="source-metric source-metric--visits">进店数 <b>{{ source.visit_count ?? '-' }}</b></span><span v-if="String(source.source_code).toUpperCase() === 'A'" class="source-metric source-metric--active">活客数 <b>{{ source.active_customer_count ?? '-' }}</b></span><span class="source-metric source-metric--deals">成交数 <b>{{ source.deal_count ?? '-' }}</b></span><span class="source-metric source-metric--amount">成交金额 <b>{{ formatMoney(source.deal_amount_cents) }}</b></span></button></div><p v-if="!sources.length" class="dashboard-empty">当前没有启用的业务来源。</p></section>

      <section class="group-dashboard__target-progress-grid" aria-label="本月与本年目标进度"><article v-for="card in targetProgressCards" :key="card.key" class="dashboard-target-progress-panel"><header><div><h2>{{ card.title }}</h2><span>{{ card.subtitle }}</span></div><button type="button" class="dashboard-link" @click="openTargetDrawer">管理目标</button></header><div class="dashboard-target-progress-panel__body"><div class="dashboard-target-progress-panel__metrics"><article v-for="metric in card.metrics" :key="metric.key" :class="`goal-summary-card goal-summary-card--${metric.tone}`"><component :is="metric.icon" :size="17" aria-hidden="true" /><span>{{ metric.label }}</span><strong>{{ metric.value }}</strong></article></div><div class="dashboard-target-donut" :style="{ '--target-rate': Math.max(0, Math.min(Number(card.rate) || 0, 100)) }"><div><strong>{{ formatRate(card.rate) }}</strong><span>达成率</span></div></div></div></article></section>

      <section class="group-dashboard__target-rank-grid"><article v-for="rank in targetRankings" :key="rank.key" class="dashboard-panel"><header><div><h2>{{ rank.title || '-' }}</h2><span>目标 / 实际业绩 / 完成率</span></div><button type="button" class="dashboard-link" @click="showConditions(rank.title || '组织目标排行统计条件', {}, dashboard?.rankings?.source_explanation)">查看条件</button></header><div class="dashboard-target-rank-list"><button v-for="(row, index) in rank.records" :key="row.name" type="button" @click="showConditions(rank.title || '组织目标排行统计条件', { 组织: row.name }, dashboard?.rankings?.source_explanation)"><em>{{ index + 1 }}</em><span>{{ row.name }}</span><small>{{ formatMoney(row.target_amount_cents) }} / {{ formatMoney(row.actual_performance_cents) }}</small><strong :class="{ 'is-low': Number(row.achievement_rate) < 80 }">{{ formatRate(row.achievement_rate) }}</strong><i class="dashboard-target-rank__progress" :class="{ 'is-low': Number(row.achievement_rate) < 80 }"><b :style="{ width: `${Math.min(Math.max(Number(row.achievement_rate) || 0, 0), 100)}%` }"></b></i></button><p v-if="!rank.records.length" class="dashboard-empty">暂无排行数据。</p></div></article></section>

    </template>

    <div v-if="drilldown" class="dashboard-modal" role="dialog" aria-modal="true" aria-label="经营明细"><section class="dashboard-modal__card"><header><div><strong>{{ drilldown.label }}明细</strong><p v-for="(value, key) in drilldown.conditions" :key="key">{{ key }}：{{ value }}</p></div><button type="button" aria-label="关闭明细" @click="drilldown = null">×</button></header><p v-if="drilldownLoading" class="dashboard-empty">正在读取明细...</p><p v-else-if="drilldownError" class="dashboard-empty">{{ drilldownError }}</p><div v-else class="dashboard-drilldown-list"><p>{{ drilldown.explanation }}</p><article v-for="(row, index) in drilldown.records" :key="row.fact_id || row.id || index"><strong>{{ row.store_name || row.store_name_snapshot || row.item_name || row.project_name_snapshot || '业务明细' }}</strong><span>{{ row.business_date || '-' }}</span><b>{{ formatMoney(row.amount_cents) }}</b></article><p v-if="!drilldown.records.length" class="dashboard-empty">当前条件没有可解释的明细。</p></div></section></div>
    <div v-if="fieldGuideOpen" class="dashboard-modal" role="dialog" aria-modal="true" aria-label="列名取值来源"><section class="dashboard-modal__card"><header><div><strong>{{ fieldGuideFocus ? '列名取值来源' : '集团管理看板列名取值来源' }}</strong><p>以下业务解释由服务端口径元数据返回。</p></div><button type="button" aria-label="关闭" @click="fieldGuideOpen = false">×</button></header><div class="dashboard-field-guide"><article v-for="([key, value]) in Object.entries(fieldExplanations).filter(([key]) => !fieldGuideFocus || key === fieldGuideFocus)" :key="key"><strong>{{ key }}</strong><p>{{ value }}</p></article><p v-if="!Object.keys(fieldExplanations).length" class="dashboard-empty">暂无列名取值来源。</p></div></section></div>

    <aside v-if="targetDrawerOpen" class="target-drawer" aria-label="目标管理"><div class="target-drawer__backdrop" @click="targetDrawerOpen = false"></div><section class="target-drawer__panel"><header><div><h2>目标管理</h2><p>只用于集团管理看板。门店填写月度目标，分公司、城市经理和集团自动汇总。</p></div><button type="button" aria-label="关闭目标管理" @click="targetDrawerOpen = false">×</button></header><div class="target-drawer__month"><label>目标年度<select v-model="selectedTargetYear"><option v-for="year in [new Date().getFullYear() - 1, new Date().getFullYear(), new Date().getFullYear() + 1]" :key="year" :value="String(year)">{{ year }}</option></select></label><span>{{ targets?.source_explanation || '正在读取门店月度目标。' }}</span></div><div class="target-drawer__content"><section><h3>门店月度目标</h3><p>门店在行、月份在列。保存后刷新可恢复，保存的目标不会影响订单、收款、服务或其他目标功能。</p><p v-if="targetError" class="dashboard-state dashboard-state--error">{{ targetError }}</p><p v-else-if="targetLoading" class="dashboard-state">正在加载门店月度目标...</p><div v-else class="target-monthly-table"><div class="target-monthly-table__header"><span>门店</span><span v-for="month in targetMonths" :key="month">{{ month }} 月</span><span>年度合计</span></div><div v-for="store in targetRows" :key="store.store_id" class="target-monthly-table__row"><strong>{{ store.store_name }}</strong><input v-for="cell in store.months" :key="cell.subject_key" v-model="targetDrafts[cell.subject_key]" type="text" inputmode="decimal" :disabled="targetSaving" :aria-label="`${selectedTargetYear}年${cell.month}月${store.store_name}目标`" /><b>{{ formatMoney(store.annual_target_cents) }}</b></div><p v-if="!targetRows.length" class="dashboard-empty">当前权限范围没有可填写目标的门店。</p></div></section><section><div class="target-drawer__tree-title"><div><h3>组织目标自动汇总</h3><p>上级组织从已保存的门店月度目标自动汇总，只读不可编辑。</p></div></div><p class="target-drawer__feedback">保存后看板会重新查询目标进度、分公司排行、城市经理排行与门店排行。</p></section></div><footer><span>目标只归属集团管理看板。</span><button type="button" class="button button--primary" :disabled="targetSaving || targetLoading" @click="saveTargets">{{ targetSaving ? '保存中...' : '保存目标' }}</button></footer></section></aside>
  </main>
</template>

<style scoped>
.group-dashboard{min-height:100vh;padding:22px;background:#f4f6f8;color:#26364a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.group-dashboard *{box-sizing:border-box}.group-dashboard.is-presentation{position:fixed;z-index:100;inset:0;overflow:auto}.group-dashboard h1,.group-dashboard h2,.group-dashboard h3,.group-dashboard p{margin:0}.group-dashboard__header{display:flex;justify-content:space-between;gap:24px;max-width:1600px;margin:0 auto 14px}.group-dashboard__eyebrow{color:#687a8f;font-size:12px}.group-dashboard h1{margin-top:5px;color:#1f2d3d;font-size:23px}.group-dashboard__header p,.group-dashboard__asof{margin-top:5px;color:#718096;font-size:12px}.group-dashboard__asof{align-self:end;white-space:nowrap}.group-dashboard__filters,.dashboard-panel{max-width:1600px;margin-right:auto;margin-left:auto;border:1px solid #dde4ed;border-radius:8px;background:#fff}.group-dashboard__filters{position:sticky;z-index:12;top:0;display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:16px;padding:12px;box-shadow:0 3px 9px rgba(26,47,71,.04)}.dashboard-select,.dashboard-category-label select{display:inline-flex;min-height:34px;align-items:center;gap:6px;border:1px solid #d8e0ea;border-radius:6px;padding:6px 9px;background:#fff;color:#405166;font:inherit;font-size:13px}.dashboard-select--readonly{cursor:default}.dashboard-category-label{display:flex;align-items:center;gap:6px;color:#66778b;font-size:12px}.group-dashboard__periods{display:inline-flex;overflow:hidden;border:1px solid #d8e0ea;border-radius:6px}.group-dashboard__periods button{min-width:45px;min-height:32px;border:0;border-right:1px solid #e4e9ef;background:#fff;color:#66778b;font:inherit;font-size:12px;cursor:pointer}.group-dashboard__periods button.is-active{background:#e9f4ff;color:#1769aa;font-weight:700}.dashboard-date{display:inline-flex;align-items:center;gap:5px;color:#66778b;font-size:12px}.dashboard-date input{min-height:32px;border:1px solid #d8e0ea;border-radius:5px;padding:4px 7px;font:inherit}.group-dashboard__actions{display:flex;gap:7px;margin-left:auto}.button{display:inline-flex;min-height:34px;align-items:center;gap:5px;border-radius:6px;padding:6px 10px;font:inherit;font-size:13px;cursor:pointer}.button:disabled{cursor:not-allowed;opacity:.6}.button--primary{border:1px solid #1769aa;background:#1769aa;color:#fff}.button--secondary{border:1px solid #d6e0eb;background:#fff;color:#426078}.group-dashboard__section{max-width:1600px;margin:0 auto 16px}.group-dashboard__section-title,.dashboard-panel>header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:9px}.dashboard-panel>header{margin:0;padding:15px 16px;border-bottom:1px solid #e8edf2}.group-dashboard__section-title>div,.dashboard-panel header>div{display:flex;align-items:baseline;gap:9px}.group-dashboard h2{font-size:15px}.group-dashboard__section-title span,.dashboard-panel header span{color:#7c8b9e;font-size:12px}.dashboard-guide,.dashboard-link{display:inline-flex;align-items:center;gap:5px;border:0;padding:0;background:transparent;color:#1769aa;font:inherit;font-size:12px;cursor:pointer}.dashboard-link{text-decoration:underline;text-underline-offset:3px}.group-dashboard__metric-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.dashboard-card-wrap{position:relative;min-width:0}.dashboard-metric{position:relative;display:grid;width:100%;min-height:125px;border:1px solid #dde5ee;border-top:3px solid #689ed0;border-radius:8px;padding:14px;background:#fff;color:#65768a;text-align:left;font:inherit;cursor:pointer}.dashboard-metric strong{display:block;align-self:end;margin-top:8px;color:#21394f;font-size:22px;line-height:1}.dashboard-metric small{display:-webkit-box;overflow:hidden;margin-top:7px;color:#7c8b9e;font-size:11px;line-height:1.35;-webkit-line-clamp:2;-webkit-box-orient:vertical}.dashboard-metric__icon{position:absolute;top:12px;right:12px;color:#6d9fcd}.dashboard-metric--red{border-top-color:#d45555}.dashboard-metric--red .dashboard-metric__icon{color:#d45555}.dashboard-metric--green{border-top-color:#55a97c}.dashboard-metric--green .dashboard-metric__icon{color:#55a97c}.dashboard-metric--yellow{border-top-color:#c79a26}.dashboard-metric--yellow .dashboard-metric__icon{color:#c79a26}.dashboard-metric--slate{border-top-color:#8291a1}.dashboard-card-guide{position:absolute;right:11px;bottom:10px;display:grid;width:21px;height:21px;place-items:center;border:0;border-radius:4px;background:transparent;color:#7890a7;cursor:pointer}.dashboard-trend-panel{border:1px solid #dde4ed;border-radius:8px;padding:16px;background:#fff}.dashboard-trend-panel__tools{display:flex;align-items:center;gap:12px}.dashboard-trend-legend{font-size:11px}.dashboard-trend-legend::before{display:inline-block;width:16px;height:3px;margin-right:5px;background:#3f946a;content:''}.dashboard-trend-legend--consumption::before{background:#d0a526}.dashboard-trend-chart{padding-top:8px}.dashboard-trend-chart svg{display:block;width:100%;height:175px}.dashboard-trend-chart__grid{stroke:#e6edf3;stroke-width:1}.dashboard-trend-chart__line{fill:none;stroke-width:2.5}.dashboard-trend-chart__line--actual{stroke:#3f946a}.dashboard-trend-chart__line--consumption{stroke:#d0a526}.dashboard-trend-chart__dot--actual{fill:#3f946a}.dashboard-trend-chart__dot--consumption{fill:#d0a526}.dashboard-trend-chart__value{font-size:9px;text-anchor:middle}.dashboard-trend-chart__value--actual{fill:#2f7f59}.dashboard-trend-chart__value--consumption{fill:#9d7911}.dashboard-trend-chart__labels{display:grid;grid-template-columns:repeat(var(--label-count),minmax(0,1fr));gap:2px;color:#778799;font-size:10px;text-align:center}.group-dashboard__category-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.dashboard-category-panel{overflow:hidden;border:1px solid #dfe6ed;border-top:3px solid var(--category-color);border-radius:8px;background:#fff}.dashboard-category-panel--teal{--category-color:#4e9b88}.dashboard-category-panel--blue{--category-color:#5b95ca}.dashboard-category-panel--rose{--category-color:#a77c90}.dashboard-category-panel--gold{--category-color:#bd923c}.dashboard-category-panel--purple{--category-color:#7f73a4}.dashboard-category-panel--slate{--category-color:#8291a1}.dashboard-category-card{position:relative;display:grid;width:100%;min-height:98px;border:0;padding:13px;background:#fff;color:#53667a;text-align:left;font:inherit;cursor:pointer}.dashboard-category-card strong{margin-top:6px;color:#2d4357;font-size:18px}.dashboard-category-card small{margin-top:4px;color:#8290a0;font-size:11px}.dashboard-category-pie{position:absolute;top:12px;right:12px;display:grid;width:52px;height:52px;place-items:center;border-radius:50%;background:conic-gradient(var(--category-color) calc(var(--category-progress) * 1%),#e9eff4 0);font-size:10px}.dashboard-category-pie::after{position:absolute;inset:7px;border-radius:50%;background:#fff;content:''}.dashboard-category-pie b{position:relative;z-index:1;color:#40576d}.dashboard-category-pie.is-empty{background:#e9eff4}.dashboard-category-rank__header{display:flex;align-items:center;justify-content:space-between;gap:5px;padding:7px 10px;border-top:1px solid #edf1f5;color:#40576b;font-size:11px}.dashboard-category-rank__toggle{display:flex}.dashboard-category-rank__toggle button{border:0;padding:3px 5px;background:transparent;color:#718198;font:inherit;font-size:10px;cursor:pointer}.dashboard-category-rank__toggle button.is-active{border-radius:3px;background:#ebf4fc;color:#1769aa;font-weight:700}.dashboard-category-rank__row{display:grid;width:100%;grid-template-columns:20px minmax(0,1fr) auto;align-items:center;gap:4px;border:0;border-top:1px solid #f0f3f6;padding:6px 10px;background:#fff;color:#53667a;text-align:left;font:inherit;font-size:11px;cursor:pointer}.dashboard-category-rank__row em{display:grid;width:17px;height:17px;place-items:center;border-radius:50%;background:#edf4fa;color:#4d7699;font-size:9px;font-style:normal}.dashboard-category-rank__row:nth-of-type(1) em{background:#f6dd9d;color:#8d5d05}.dashboard-category-rank__row:nth-of-type(2) em{background:#dceafa;color:#276996}.dashboard-category-rank__row:nth-of-type(3) em{background:#f1dde4;color:#935466}.dashboard-category-rank__row span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.dashboard-category-rank__row b{color:#2d7e58;white-space:nowrap}.dashboard-category-rank__empty,.dashboard-empty,.dashboard-state{padding:13px;color:#7d8d9e;font-size:12px}.dashboard-source-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:10px}.dashboard-source-card{display:grid;gap:7px;min-height:140px;border:1px solid #dfe7ee;border-top:3px solid #5b95ca;border-radius:7px;padding:12px;background:#fff;color:#68798c;text-align:left;font:inherit;cursor:pointer}.dashboard-source-card strong{color:#31495e;font-size:13px}.source-metric{display:flex;justify-content:space-between;gap:5px;font-size:11px}.source-metric b{font-size:12px}.source-metric--visits b{color:#2d78b8}.source-metric--active b{color:#278461}.source-metric--deals b{color:#8066a2}.source-metric--amount b{color:#b87524}.group-dashboard__target-progress-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;max-width:1600px;margin:0 auto 16px}.dashboard-target-progress-panel{overflow:hidden;border:1px solid #dde4ed;border-radius:8px;background:#fff}.dashboard-target-progress-panel>header{display:flex;justify-content:space-between;gap:10px;padding:14px 16px 10px;border-bottom:1px solid #e8edf2}.dashboard-target-progress-panel>header span{color:#7c8b9e;font-size:12px}.dashboard-target-progress-panel__body{display:grid;grid-template-columns:minmax(0,1fr) 106px;align-items:center;gap:14px;padding:13px 16px}.dashboard-target-progress-panel__metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.goal-summary-card{position:relative;min-height:58px;border:1px solid #e3e9ef;border-radius:6px;padding:10px 9px 8px 37px;background:#fbfcfd}.goal-summary-card svg{position:absolute;top:12px;left:11px}.goal-summary-card span{color:#77879a;font-size:11px}.goal-summary-card strong{display:block;margin-top:4px;color:#33495d;font-size:15px}.goal-summary-card--goal svg{color:#2d78b8}.goal-summary-card--complete svg{color:#278461}.goal-summary-card--rate svg{color:#8066a2}.goal-summary-card--remaining svg{color:#b87524}.dashboard-target-donut{position:relative;display:grid;width:102px;height:102px;place-items:center;border-radius:50%;background:conic-gradient(#3f946a calc(var(--target-rate) * 1%),#e8eef3 0)}.dashboard-target-donut::after{position:absolute;inset:10px;border-radius:50%;background:#fff;content:''}.dashboard-target-donut>div{position:relative;z-index:1;display:grid;gap:2px;text-align:center}.dashboard-target-donut strong{color:#2f7f59;font-size:17px}.dashboard-target-donut span{color:#8290a0;font-size:11px}.group-dashboard__target-rank-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;max-width:1600px;margin:0 auto 16px}.group-dashboard__target-rank-grid .dashboard-panel{width:100%}.dashboard-target-rank-list{height:328px;overflow-y:auto;scrollbar-gutter:stable}.dashboard-target-rank-list button{position:relative;display:grid;width:100%;min-height:41px;grid-template-columns:22px minmax(0,1fr) minmax(90px,auto) 46px;align-items:center;gap:7px;border:0;border-bottom:1px solid #edf1f5;padding:11px 14px;background:#fff;color:#5d7083;text-align:left;font:inherit;font-size:12px;cursor:pointer}.dashboard-target-rank-list em{display:grid;width:19px;height:19px;place-items:center;border-radius:50%;background:#edf4fa;color:#4d7699;font-size:10px;font-style:normal}.dashboard-target-rank-list button:nth-child(1) em{background:#f6dd9d;color:#8d5d05}.dashboard-target-rank-list button:nth-child(2) em{background:#dceafa;color:#276996}.dashboard-target-rank-list button:nth-child(3) em{background:#f1dde4;color:#935466}.dashboard-target-rank-list span{overflow:hidden;color:#354c61;text-overflow:ellipsis;white-space:nowrap;font-weight:700}.dashboard-target-rank-list small{color:#8290a0;font-size:10px;text-align:right}.dashboard-target-rank-list strong{color:#398361;text-align:right}.dashboard-target-rank-list strong.is-low{color:#c2673d}.dashboard-target-rank__progress{position:absolute;right:14px;bottom:3px;left:42px;height:3px;overflow:hidden;border-radius:2px;background:#e8eef3}.dashboard-target-rank__progress b{display:block;height:100%;border-radius:inherit;background:#4b9b71}.dashboard-target-rank__progress.is-low b{background:#d08a3d}.dashboard-alert-list button{display:grid;width:100%;grid-template-columns:110px minmax(0,1fr) 112px;align-items:center;gap:8px;border:0;border-bottom:1px solid #edf1f5;padding:12px 16px;background:#fff;color:#68788b;text-align:left;font:inherit;font-size:12px;cursor:pointer}.dashboard-alert-list strong{color:#2d4258}.dashboard-alert-list em{justify-self:end;border-radius:3px;padding:3px 5px;background:#fff1ec;color:#b45431;font-size:11px;font-style:normal}.dashboard-toast{position:fixed;z-index:50;right:22px;bottom:22px;display:flex;flex-wrap:wrap;width:min(460px,calc(100vw - 32px));gap:7px 12px;border:1px solid #a9cdea;border-radius:7px;padding:12px 38px 12px 14px;background:#f5faff;color:#4b647b;box-shadow:0 10px 25px rgba(37,76,112,.16);font-size:12px}.dashboard-toast strong{width:100%;color:#1769aa}.dashboard-toast button{position:absolute;top:7px;right:9px;border:0;background:transparent;color:#5f7489;font-size:20px;cursor:pointer}.dashboard-modal,.target-drawer{position:fixed;z-index:70;inset:0}.dashboard-modal{display:grid;place-items:center;padding:20px;background:rgba(26,43,62,.42)}.dashboard-modal__card{width:min(760px,100%);max-height:min(760px,calc(100vh - 40px));overflow:auto;border-radius:8px;background:#fff;box-shadow:0 18px 48px rgba(24,42,61,.24)}.dashboard-modal__card header,.target-drawer__panel>header{display:flex;justify-content:space-between;gap:12px;padding:17px 20px;border-bottom:1px solid #e5ebf1}.dashboard-modal__card header button,.target-drawer header>button{border:0;background:transparent;color:#67788a;font-size:24px;cursor:pointer}.dashboard-field-guide{padding:4px 20px 14px}.dashboard-field-guide article{display:grid;grid-template-columns:minmax(140px,30%) 1fr;gap:18px;border-bottom:1px solid #edf1f5;padding:12px 0}.dashboard-field-guide strong{color:#2c4258;font-size:13px}.dashboard-field-guide p{color:#596b80;font-size:13px;line-height:1.6}.target-drawer__backdrop{position:absolute;inset:0;background:rgba(26,43,62,.32)}.target-drawer__panel{position:absolute;top:0;right:0;display:flex;width:min(1080px,100%);height:100%;flex-direction:column;background:#f6f8fa;box-shadow:-10px 0 30px rgba(25,44,64,.18)}.target-drawer h2{font-size:18px}.target-drawer header p,.target-drawer__month span,.target-drawer__content p{margin-top:4px;color:#7d8c9d;font-size:12px;line-height:1.45}.target-drawer__month{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 24px;border-bottom:1px solid #dfe7ee;background:#fbfcfd}.target-drawer__month label{display:flex;align-items:center;gap:8px;color:#4e6175;font-size:13px;font-weight:700}.target-drawer select,.target-drawer input{min-height:32px;border:1px solid #d6e0ea;border-radius:5px;padding:5px 8px;background:#fff;color:#334a5f;font:inherit;font-size:13px}.target-drawer__content{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(320px,.75fr);flex:1;min-height:0;overflow:auto}.target-drawer__content>section{min-width:0;padding:19px 24px}.target-drawer__content>section:first-child{overflow:auto}.target-drawer__content>section+section{border-left:1px solid #dfe7ee;background:#fff}.target-drawer h3{font-size:14px}.target-monthly-table{display:grid;min-width:1330px;margin-top:15px;border:1px solid #dfe7ee;border-radius:6px}.target-monthly-table__header,.target-monthly-table__row{display:grid;grid-template-columns:92px repeat(12,minmax(82px,1fr)) 108px;align-items:center;gap:7px;padding:7px 9px}.target-monthly-table__header{background:#f4f8fc;color:#65768a;font-size:11px;font-weight:700}.target-monthly-table__row{border-top:1px solid #edf1f5;color:#53667b;font-size:12px}.target-monthly-table__row input{width:100%;min-width:0}.target-monthly-table__row>b{color:#2f7f59;font-size:12px;text-align:right}.target-drawer__feedback{margin-top:15px!important;border-radius:5px;padding:9px;background:#f4f8fc;color:#526c85!important}.target-drawer footer{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 24px;border-top:1px solid #dfe7ee;background:#fff;color:#7d8c9d;font-size:12px}.dashboard-state{max-width:1600px;margin:0 auto 16px;border:1px solid #dfe7ee;border-radius:7px;background:#fff}.dashboard-state--error{border-color:#edc7c7;background:#fff7f7;color:#ae4f4f}@media(max-width:1180px){.group-dashboard__metric-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.group-dashboard__category-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.group-dashboard__target-rank-grid{grid-template-columns:1fr}}@media(max-width:760px){.group-dashboard{padding:12px}.group-dashboard__header{display:grid;gap:8px}.group-dashboard__asof{white-space:normal}.group-dashboard__filters{position:static}.group-dashboard__actions{width:100%;margin-left:0;flex-wrap:wrap}.group-dashboard__section-title>div{display:grid;gap:3px}.group-dashboard__metric-grid,.group-dashboard__category-grid,.group-dashboard__target-rank-grid{grid-template-columns:1fr}.group-dashboard__target-progress-grid{grid-template-columns:1fr}.dashboard-target-progress-panel__body{grid-template-columns:1fr}.dashboard-target-donut{justify-self:center}.target-drawer__content{grid-template-columns:1fr}.target-drawer__content>section+section{border-top:1px solid #dfe7ee;border-left:0}.target-drawer__month,.target-drawer footer{align-items:flex-start;flex-direction:column}.dashboard-field-guide article{grid-template-columns:1fr;gap:5px}.dashboard-alert-list button{grid-template-columns:82px minmax(0,1fr)}.dashboard-alert-list em{display:none}}
.group-dashboard__category-grid{display:flex;overflow-x:auto;gap:10px;padding-bottom:4px;scrollbar-gutter:stable}
.group-dashboard__category-grid .dashboard-category-panel{flex:0 0 calc((100% - 50px) / 6);min-width:180px}
.group-dashboard{padding:10px}
.group-dashboard__header,.group-dashboard__filters,.group-dashboard__section,.group-dashboard__target-progress-grid,.group-dashboard__target-rank-grid,.dashboard-panel,.dashboard-state{width:100%;max-width:none}
/* Keep dashboard sections on one row; scroll only after cards reach a readable width. */
.group-dashboard__filters{flex-wrap:nowrap;overflow-x:auto;white-space:nowrap}
.group-dashboard__filters>*{flex:0 0 auto}
.group-dashboard__metric-grid,.dashboard-source-grid,.group-dashboard__target-progress-grid,.group-dashboard__target-rank-grid{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:10px;scrollbar-gutter:stable}
.group-dashboard__metric-grid .dashboard-card-wrap{flex:1 1 0;min-width:140px}
.dashboard-source-card{flex:1 1 0;min-width:140px}
.group-dashboard__target-progress-grid{gap:16px}
.dashboard-target-progress-panel{flex:1 1 0;min-width:360px}
.group-dashboard__target-rank-grid{gap:14px}
.group-dashboard__target-rank-grid>.dashboard-panel{flex:1 1 0;min-width:320px}
.group-dashboard__category-grid .dashboard-category-panel{min-width:140px}
.dashboard-metric>span:not(.dashboard-metric__icon),.dashboard-category-card>span:first-child,.dashboard-source-card>strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.group-dashboard__category-grid{overflow-x:hidden}
.group-dashboard__category-grid .dashboard-category-panel{flex:1 1 0;min-width:0}
.group-dashboard__metric-grid,.dashboard-source-grid,.group-dashboard__target-progress-grid,.group-dashboard__target-rank-grid{overflow-x:hidden}
.group-dashboard__metric-grid .dashboard-card-wrap,.dashboard-source-card,.dashboard-target-progress-panel,.group-dashboard__target-rank-grid>.dashboard-panel{min-width:0}
.group-dashboard__header{align-items:center;justify-content:flex-start;min-height:40px;margin-bottom:8px}
.group-dashboard__header h1{margin:0;text-align:left}
@media(max-width:760px){.group-dashboard__header{display:flex;align-items:flex-start;justify-content:flex-start;min-height:36px;margin-bottom:8px}}
.dashboard-category-panel .dashboard-category-rank__row:nth-child(3) { background: #fff7df; }
.dashboard-category-panel .dashboard-category-rank__row:nth-child(3) em { background: #d49b2c; color: #fff; }
.dashboard-category-panel .dashboard-category-rank__row:nth-child(4) em { background: #b68162; color: #fff; }
.dashboard-category-panel .dashboard-category-rank__row:nth-child(5) em { background: #d6c978; color: #5f562d; }
.dashboard-category-panel .dashboard-category-rank__row:nth-child(6) em,
.dashboard-category-panel .dashboard-category-rank__row:nth-child(7) em { background: #a9bbc5; color: #fff; }
.dashboard-category-panel .dashboard-category-rank__row:nth-child(4),
.dashboard-category-panel .dashboard-category-rank__row:nth-child(5),
.dashboard-category-panel .dashboard-category-rank__row:nth-child(6),
.dashboard-category-panel .dashboard-category-rank__row:nth-child(7) { background: transparent; }
.dashboard-target-rank-list button:nth-child(1) { background: #fff7df; }
.dashboard-target-rank-list button:nth-child(1) em { background: #d49b2c; color: #fff; }
.dashboard-target-rank-list button:nth-child(2) em { background: #b68162; color: #fff; }
.dashboard-target-rank-list button:nth-child(3) em { background: #d6c978; color: #5f562d; }
.dashboard-target-rank-list button:nth-child(4) em,
.dashboard-target-rank-list button:nth-child(5) em { background: #a9bbc5; color: #fff; }
.dashboard-target-rank-list button:nth-child(2),
.dashboard-target-rank-list button:nth-child(3),
.dashboard-target-rank-list button:nth-child(4),
.dashboard-target-rank-list button:nth-child(5) { background: transparent; }
.group-dashboard{--metric-amount:#1f5f8b;--metric-count:#2b4358;--metric-activity:#357a72;--metric-average:#3b78a5;--metric-attention:#b7791f}
.dashboard-metric--blue strong,.dashboard-metric--green strong,.dashboard-metric--yellow strong{color:var(--metric-amount)}
.dashboard-metric--red strong{color:var(--metric-attention)}
.dashboard-metric--slate strong{color:var(--metric-activity)}
.dashboard-metric--blue{border-top-color:#1f5f8b}
.dashboard-metric--green{border-top-color:#357a72}
.dashboard-metric--yellow{border-top-color:#b7791f}
.dashboard-metric--red{border-top-color:#b7791f}
.group-dashboard__section:nth-of-type(2) .group-dashboard__section-title h2{color:var(--metric-amount)}
.group-dashboard__section:nth-of-type(2) .dashboard-metric{background:#f4f8fc}
.dashboard-trend-panel{border-top:3px solid var(--metric-amount)}
.dashboard-trend-legend--actual::before{background:var(--metric-amount)}
.dashboard-trend-legend--consumption::before{background:var(--metric-activity)}
.dashboard-trend-chart__line--actual{stroke:var(--metric-amount)}
.dashboard-trend-chart__line--consumption{stroke:var(--metric-activity)}
.dashboard-trend-chart__dot--actual{fill:var(--metric-amount)}
.dashboard-trend-chart__dot--consumption{fill:var(--metric-activity)}
.dashboard-trend-chart__value--actual{fill:var(--metric-amount)}
.dashboard-trend-chart__value--consumption{fill:var(--metric-activity)}
.dashboard-category-panel{border-top-color:var(--metric-activity)}
.dashboard-category-card strong{color:var(--metric-amount)}
.dashboard-category-rank__row b{color:var(--metric-amount)}
.dashboard-source-card{border-top-color:var(--metric-average);background:#f7fbfd}
.source-metric--visits b,.source-metric--deals b{color:var(--metric-count)}
.source-metric--active b{color:var(--metric-activity)}
.source-metric--amount b{color:var(--metric-amount)}
.dashboard-target-progress-panel{border-top:3px solid var(--metric-amount)}
.goal-summary-card strong{color:var(--metric-amount)}
.dashboard-target-donut{background:conic-gradient(var(--metric-activity) calc(var(--target-rate) * 1%),#e8eef3 0)}
.dashboard-target-donut strong{color:var(--metric-activity)}
.group-dashboard__target-rank-grid>.dashboard-panel{border-top:3px solid var(--metric-average)}
.dashboard-target-rank-list small{color:var(--metric-amount)}
.dashboard-target-rank-list strong{color:var(--metric-activity)}
.dashboard-target-rank-list strong.is-low{color:var(--metric-attention)}
.dashboard-alert-list em{background:#fff8e6;color:var(--metric-attention)}
.group-dashboard__scope-picker{position:relative;flex:none}.group-dashboard__scope-trigger{cursor:pointer}.group-dashboard__scope-trigger:hover{border-color:#5b9bd0;color:#1769aa}.group-dashboard__scope-trigger:disabled{cursor:wait;opacity:.7}.group-dashboard__scope-panel{position:absolute;z-index:30;top:calc(100% + 6px);left:0;width:min(560px,calc(100vw - 28px));overflow:hidden;border:1px solid #d8e0ea;border-radius:7px;background:#fff;box-shadow:0 12px 30px rgba(26,47,71,.18);white-space:normal}.group-dashboard__scope-panel>header{padding:11px 13px;border-bottom:1px solid #e8edf2;color:#344f65;font-size:12px;font-weight:700}.group-dashboard__scope-body{display:grid;grid-template-columns:minmax(0,1fr) minmax(190px,.8fr);max-height:320px}.group-dashboard__scope-tree,.group-dashboard__scope-stores{min-width:0;overflow:auto;padding:8px}.group-dashboard__scope-tree{border-right:1px solid #edf1f5}.group-dashboard__scope-row{display:flex;align-items:center;gap:4px;min-height:31px}.group-dashboard__scope-toggle,.group-dashboard__scope-toggle-placeholder{width:20px;height:23px;flex:none}.group-dashboard__scope-toggle{border:0;background:transparent;color:#6c8092;cursor:pointer}.group-dashboard__scope-option,.group-dashboard__scope-stores button{display:block;width:100%;border:0;border-radius:4px;padding:7px 8px;background:transparent;color:#526a7e;text-align:left;font:inherit;font-size:12px;cursor:pointer}.group-dashboard__scope-option:hover,.group-dashboard__scope-stores button:hover,.group-dashboard__scope-stores button.is-active{background:#edf6fd;color:#206b9e}.group-dashboard__scope-stores{display:flex;flex-direction:column;gap:5px}.group-dashboard__scope-stores strong{padding:5px 8px;color:#38566d;font-size:12px}.group-dashboard__scope-stores span,.group-dashboard__scope-empty{padding:8px;color:#8b9aa8;font-size:11px}.group-dashboard__scope-panel>footer{display:flex;align-items:center;gap:8px;padding:9px 13px;border-top:1px solid #e8edf2;color:#8292a0;font-size:11px}.group-dashboard__scope-panel>footer button{border:0;background:transparent;color:#2f79aa;cursor:pointer;font-size:11px}
.group-dashboard{--metric-consumption:#b7791f}.dashboard-trend-legend--consumption::before{background:var(--metric-consumption)}.dashboard-trend-chart__line--consumption{stroke:var(--metric-consumption)}.dashboard-trend-chart__dot--consumption{fill:var(--metric-consumption)}.dashboard-trend-chart__value--consumption{fill:var(--metric-consumption)}
</style>
