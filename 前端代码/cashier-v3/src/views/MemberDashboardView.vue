<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BookOpen from '@lucide/vue/dist/esm/icons/book-open.mjs'
import Funnel from '@lucide/vue/dist/esm/icons/funnel.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import Users from '@lucide/vue/dist/esm/icons/users.mjs'
import HeartPulse from '@lucide/vue/dist/esm/icons/heart-pulse.mjs'
import CircleDollarSign from '@lucide/vue/dist/esm/icons/circle-dollar-sign.mjs'
import Tags from '@lucide/vue/dist/esm/icons/tags.mjs'
import { queryMemberDashboard, queryMemberDashboardScope } from '@/services/memberDashboardApi'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const errorMessage = ref('')
const dashboard = ref(null)
const period = ref('today')
const customStart = ref(today())
const customEnd = ref(today())
const rankModes = ref({ consumption: 'projects', consumptionAmount: 'projects' })
const categoryId = ref('')
const scopePicker = ref({ open: false, loading: false, tree: [], selectedStoreIds: [], label: '当前权限范围', selectedOrganizationKey: '', selectedOrganizationName: '', stores: [] })
const expandedScopeKeys = ref(new Set())
const showFieldGuide = ref(false)
const showTierGuide = ref(false)
const previewMode = computed(() => String(route.query.preview || '') === '1')

function today() {
  const date = new Date()
  const pad = (value) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

function rangeForPeriod() {
  const end = today()
  if (period.value === 'today') return { start_date: end, end_date: end }
  if (period.value === 'month') return { start_date: `${end.slice(0, 7)}-01`, end_date: end }
  if (period.value === 'year') return { start_date: `${end.slice(0, 4)}-01-01`, end_date: end }
  return { start_date: customStart.value, end_date: customEnd.value }
}

function money(cents) {
  if (cents === null || cents === undefined || cents === '') return '-'
  const value = Number(cents)
  return Number.isFinite(value) ? `¥${(value / 100).toLocaleString('zh-CN', { maximumFractionDigits: 2 })}` : '-'
}

function number(value) {
  if (value === null || value === undefined || value === '') return '-'
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed.toLocaleString('zh-CN', { maximumFractionDigits: 2 }) : '-'
}

function percent(value) {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? `${parsed.toFixed(1).replace(/\.0$/, '')}%` : '-'
}

function tierRows(people, amounts) {
  const names = ['0-5000', '5000-10000', '10000-30000', '30000-50000', '50000-100000', '100000以上']
  return names.map((name, index) => ({
    name,
    people: people[index],
    amount_cents: amounts[index],
    member_share: [23, 31, 27, 13.5, 4.3, 1.1][index],
    amount_share: [5.9, 16.4, 31.9, 35.3, 19.1, 7][index],
    average_cents: people[index] ? Math.round(amounts[index] / people[index]) : null
  }))
}

function previewDashboard() {
  const categories = [
    ['头疗', 420, 7800000], ['美容', 368, 6350000], ['美体', 292, 4860000],
    ['养生', 264, 3710000], ['美发', 238, 2980000], ['产品', 198, 2900000]
  ].map(([name, people, amount_cents], index) => ({ id: index + 1, name, people, amount_cents, average_cents: Math.round(amount_cents / people), share: [27.3, 22.2, 17, 13, 10.4, 10.1][index] }))
  const projects = ['深层头疗', '面部护理', '肩颈舒缓', '身体调理', '基础剪护'].map((name, index) => ({ name, cash_income_cents: [3280000, 2860000, 2510000, 2240000, 1850000][index], orders: [116, 104, 96, 83, 79][index], people: [98, 87, 82, 72, 68][index], average_cents: [33469, 32874, 30610, 31111, 27206][index] }))
  const products = ['修护精华', '头皮护理套装', '补水面膜', '护发洗护', '身体精油'].map((name, index) => ({ name, cash_income_cents: [1780000, 1540000, 1320000, 1080000, 920000][index], orders: [62, 48, 76, 51, 39][index], people: [58, 43, 64, 45, 34][index], average_cents: [30690, 35814, 20625, 24000, 27059][index] }))
  const consumptionProjects = projects.map((row, index) => ({ name: row.name, consumption_cents: [2640000, 2260000, 1940000, 1680000, 1410000][index], people: [102, 91, 76, 65, 58][index], average_cents: [25882, 24835, 25526, 25846, 24310][index] }))
  const consumptionProducts = products.map((row, index) => ({ name: row.name, consumption_cents: [1460000, 1250000, 1060000, 870000, 740000][index], people: [54, 42, 61, 40, 32][index], average_cents: [27037, 29762, 17377, 21750, 23125][index] }))
  return {
    title: '会员看板', data_as_of: '前端布局预览', scope: { label: '当前权限范围' },
    customer_overview: {
      member: { consuming_members: { value: 2680 }, service_members: { value: 943 }, sleeping_members: { value: 116 } },
      consumption: { people: { value: 1820 }, amount: { value_cents: 28600000 }, average: { value_cents: 15714 } },
      service: { people: { value: 1314 }, amount: { value_cents: 19500000 }, projects: { value: 4820 }, average_amount: { value_cents: 14840 }, average_items: { value: 3.67 }, unit_amount: { value_cents: 4046 } }
    },
    consumption: { people: { value: 1820 }, amount: { value_cents: 28600000 }, average: { value_cents: 15714 }, categories: categories.map((category) => ({ ...category, rankings: { projects, products, consumption_projects: consumptionProjects, consumption_products: consumptionProducts } })), rankings: { projects, products, consumption_projects: consumptionProjects, consumption_products: consumptionProducts } },
    visit_bands: { recency: [30, 60, 90, 180, 360].map((days, index) => ({ label: `${days}天到店`, value: [1320, 1018, 774, 428, 192][index] })), frequency: [1, 2, 3, 4, 5].map((count, index) => ({ label: count === 5 ? '月服务超4次' : `月服务到店${count}次`, value: [782, 504, 326, 188, 96][index] })) },
    tiers: { member: tierRows([420, 566, 492, 246, 78, 18], [1680000, 4680000, 9120000, 10100000, 5450000, 1990000]), new_customer: tierRows([206, 238, 176, 84, 26, 5], [810000, 1940000, 3190000, 3560000, 1780000, 520000]) }
  }
}

const overview = computed(() => dashboard.value?.customer_overview || {})
const consumption = computed(() => dashboard.value?.consumption || {})
const categories = computed(() => Array.isArray(consumption.value.categories) ? consumption.value.categories : [])
const rankings = computed(() => consumption.value.rankings || {})
const visitBands = computed(() => dashboard.value?.visit_bands || {})
const tiers = computed(() => dashboard.value?.tiers || {})
const categoryOptions = computed(() => Array.isArray(dashboard.value?.filter_schema?.categories) ? dashboard.value.filter_schema.categories : [])
const fieldExplanations = computed(() => dashboard.value?.field_explanations || {})
const fieldExplanationLabels = {
  'member.consuming_members': '会员·消费会员数',
  'member.service_members': '会员·服务会员数',
  'member.sleeping_members': '会员·沉睡会员数',
  'consumption.people': '消费·人数',
  'consumption.amount': '消费·金额',
  'consumption.average': '消费·人均',
  'service.people': '服务·人数',
  'service.amount': '服务·金额',
  'service.projects': '服务·项目数',
  'service.average_amount': '服务·人均金额',
  'service.average_items': '服务·人均项',
  'service.unit_amount': '服务·项目单耗',
  'category.people': '分类·消费会员数',
  'category.amount': '分类·消费金额',
  'category.average': '分类·人均消费',
  'category.share': '分类·金额占比',
  'ranking.consumption.cash_income': '消费排行·现金收入',
  'ranking.consumption.orders': '消费排行·成交单数',
  'ranking.consumption.people': '消费排行·购买人头',
  'ranking.consumption.average': '消费排行·人均消费',
  'ranking.consumption_amount.amount': '消耗排行·消耗金额',
  'ranking.consumption_amount.people': '消耗排行·服务人头',
  'ranking.consumption_amount.average': '消耗排行·人均消耗',
  'visit.recency': '到店波段·累计到店顾客数',
  'visit.frequency': '到店波段·月服务频次',
  'tier.people': '消费层级·顾客人数',
  'tier.amount': '消费层级·消费金额',
  'tier.member_share': '消费层级·会员占比',
  'tier.amount_share': '消费层级·金额占比',
  'tier.average': '消费层级·人均消费',
  'tier.new_customer': '新客分层·排除来源 A'
}
function fieldExplanationLabel(key) {
  return fieldExplanationLabels[key] || key
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

function rankRows(key) {
  return rankings.value[key] || []
}

function categoryRankMode(category, type) {
  return rankModes.value[`${category.id}:${type}`] || 'projects'
}

function categoryRankRows(category, type) {
  const mode = categoryRankMode(category, type)
  const rankingKey = type === 'consumption'
    ? mode
    : (mode === 'products' ? 'consumption_products' : 'consumption_projects')
  const rows = category?.rankings?.[rankingKey] || rankRows(rankingKey)
  const topFive = Array.isArray(rows) ? rows.slice(0, 5) : []
  return Array.from({ length: 5 }, (_, index) => topFive[index] || {
    name: `__empty-ranking-${index}`,
    __empty: true
  })
}

function scopeNodeStoreIds(node) {
  const ids = []
  const walk = (item) => {
    const id = Number(item?.store_id || (item?.node_type === 'store' ? item?.id : 0))
    if (id > 0) ids.push(id)
    ;(Array.isArray(item?.children) ? item.children : []).forEach(walk)
  }
  walk(node)
  return [...new Set(ids)]
}

function scopeNodeStores(node) {
  const stores = []
  const walk = (item) => {
    const id = Number(item?.store_id || (item?.node_type === 'store' ? item?.id : 0))
    if (id > 0) stores.push({ id, name: item?.title || item?.name || `门店${id}` })
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
  scopePicker.value = { ...scopePicker.value, selectedStoreIds: ids, label: option.storeId > 0 ? name : name, selectedOrganizationKey: option.key, selectedOrganizationName: name, stores: scopeNodeStores(option.node), open: true }
  load()
}

function selectScopeStore(store) {
  const id = Number(store?.id)
  if (!id) return
  scopePicker.value = { ...scopePicker.value, selectedStoreIds: [id], label: store.name || `门店${id}`, open: false }
  load()
}

function chooseAllScope() {
  scopePicker.value = { ...scopePicker.value, selectedStoreIds: [], label: '当前权限范围', selectedOrganizationKey: '', selectedOrganizationName: '', stores: [], open: false }
  load()
}

function scopeFilters() {
  return {
    ...(scopePicker.value.selectedStoreIds.length ? { store_ids: scopePicker.value.selectedStoreIds.join(',') } : {}),
    ...(categoryId.value ? { category_id: categoryId.value } : {})
  }
}

function setRankMode(key, value) {
  rankModes.value = { ...rankModes.value, [key]: value }
}

function setCategoryRankMode(category, type, value) {
  setRankMode(`${category.id}:${type}`, value)
}

function load() {
  loading.value = true
  errorMessage.value = ''
  if (previewMode.value) {
    dashboard.value = previewDashboard()
    loading.value = false
    return
  }
  queryMemberDashboard({ ...rangeForPeriod(), ...scopeFilters() }).then((payload) => { dashboard.value = payload }).catch((error) => {
    dashboard.value = null
    errorMessage.value = error?.message || '会员看板加载失败。'
  }).finally(() => { loading.value = false })
}

function selectPeriod(value) {
  period.value = value
  if (value !== 'custom') load()
}

function loadScope() {
  if (previewMode.value) return Promise.resolve()
  scopePicker.value = { ...scopePicker.value, loading: true }
  return queryMemberDashboardScope().then((data) => {
    scopePicker.value = { ...scopePicker.value, tree: data?.tree || [], loading: false }
  }).catch((error) => {
    scopePicker.value = { ...scopePicker.value, loading: false }
    errorMessage.value = error?.message || '权限范围读取失败。'
  })
}

function openTierSettings() {
  showTierGuide.value = true
}

function goTierSettings() {
  showTierGuide.value = false
  router.push('/platform/six-dimension/consumption-tiers')
}

onMounted(() => {
  document.documentElement.classList.add('member-dashboard-document')
  loadScope().finally(load)
})

onBeforeUnmount(() => {
  document.documentElement.classList.remove('member-dashboard-document')
})
</script>

<template>
  <main class="member-dashboard" aria-label="会员看板">
    <header class="member-dashboard__header"><h1>{{ dashboard?.title || '会员看板' }}</h1></header>
    <section class="member-dashboard__filters" aria-label="查询条件"><div class="member-dashboard__scope-picker"><button type="button" class="member-dashboard__scope-trigger" :disabled="scopePicker.loading" @click="scopePicker.open = !scopePicker.open"><Funnel :size="15" aria-hidden="true" />{{ scopePicker.loading ? '读取权限范围' : scopePicker.label }}</button><section v-if="scopePicker.open" class="member-dashboard__scope-panel" aria-label="组织和门店权限范围"><header>组织 / 门店</header><div class="member-dashboard__scope-body"><div class="member-dashboard__scope-tree"><div v-for="option in scopeTreeOptions" :key="option.key" class="member-dashboard__scope-row" :style="{ paddingLeft: `${8 + option.depth * 16}px` }"><button v-if="option.hasChildren" type="button" class="member-dashboard__scope-toggle" :aria-expanded="option.isExpanded" @click="toggleScopeNode(option)">{{ option.isExpanded ? '−' : '+' }}</button><span v-else class="member-dashboard__scope-toggle-placeholder"></span><button type="button" class="member-dashboard__scope-option" :class="{ 'is-selected': scopePicker.selectedOrganizationKey === option.key }" @click="selectScopeNode(option)">{{ option.node?.title || option.node?.name || option.node?.label || '-' }}</button></div></div><div class="member-dashboard__scope-stores"><strong>{{ scopePicker.selectedOrganizationName || '选择组织后查看下级门店' }}</strong><button v-for="store in scopePicker.stores" :key="store.id" type="button" :class="{ 'is-active': scopePicker.selectedStoreIds.length === 1 && scopePicker.selectedStoreIds[0] === Number(store.id) }" @click="selectScopeStore(store)">{{ store.name }}</button><span v-if="!scopePicker.stores.length">请选择组织或门店。</span></div></div><footer><button type="button" @click="chooseAllScope">当前权限范围</button><span>组织选择会包含下级门店；门店选择只查询该门店。</span></footer></section></div><nav class="member-dashboard__periods"><button v-for="item in [['today', '今天'], ['month', '本月'], ['year', '全年'], ['custom', '自定义']]" :key="item[0]" type="button" :class="{ 'is-active': period === item[0] }" @click="selectPeriod(item[0])">{{ item[1] }}</button></nav><label v-if="period === 'custom'" class="member-dashboard__date">从 <input v-model="customStart" type="date" /></label><label v-if="period === 'custom'" class="member-dashboard__date">至 <input v-model="customEnd" type="date" /></label><label class="member-dashboard__category-filter">分类 <select v-model="categoryId"><option value="">全部分类</option><option v-for="category in categoryOptions" :key="category.id" :value="String(category.id)">{{ category.name }}</option></select></label><div class="member-dashboard__actions"><button type="button" class="button button--primary" :disabled="loading" @click="load">查询</button><button type="button" class="button button--secondary" :disabled="loading" @click="load"><RefreshCw :size="15" aria-hidden="true" />刷新</button></div></section>
    <p v-if="errorMessage" class="member-dashboard__state member-dashboard__state--error">{{ errorMessage }}</p><p v-else-if="loading" class="member-dashboard__state">正在加载会员看板...</p>
    <template v-if="dashboard">
      <section class="member-dashboard__section"><div class="member-dashboard__section-title"><div><h2>顾客概况</h2><span>人数按会员 ID 去重，服务次数按有效服务事实统计</span></div><button type="button" class="member-dashboard__guide" @click="showFieldGuide = true"><BookOpen :size="15" aria-hidden="true" />字段说明</button></div><div class="member-dashboard__overview-grid"><article class="member-panel member-panel--member"><header><Users :size="18" aria-hidden="true" /><h3>会员</h3></header><div class="member-panel__rows"><div><span>消费</span><strong>{{ number(overview.member?.consuming_members?.value) }}</strong><small>消费会员数，去重</small></div><div><span>服务</span><strong>{{ number(overview.member?.service_members?.value) }}</strong><small>服务会员数，去重</small></div><div><span>沉睡</span><strong>{{ number(overview.member?.sleeping_members?.value) }}</strong><small>沉睡会员数，去重</small></div></div></article><article class="member-panel member-panel--consumption"><header><CircleDollarSign :size="18" aria-hidden="true" /><h3>消费</h3></header><div class="member-panel__rows"><div><span>人数</span><strong>{{ number(overview.consumption?.people?.value) }}</strong><small>非体验标签的人数</small></div><div><span>金额</span><strong>{{ money(overview.consumption?.amount?.value_cents) }}</strong><small>现金业绩</small></div><div><span>人均</span><strong>{{ money(overview.consumption?.average?.value_cents) }}</strong><small>金额 / 人数</small></div></div></article><article class="member-panel member-panel--service"><header><HeartPulse :size="18" aria-hidden="true" /><h3>服务</h3></header><div class="member-panel__service-grid"><div><span>人数</span><strong>{{ number(overview.service?.people?.value) }}</strong><small>服务人数</small></div><div><span>金额</span><strong>{{ money(overview.service?.amount?.value_cents) }}</strong><small>服务耗卡金额</small></div><div><span>项目数</span><strong>{{ number(overview.service?.projects?.value) }}</strong><small>服务项目数</small></div><div><span>人均金额</span><strong>{{ money(overview.service?.average_amount?.value_cents) }}</strong><small>耗卡金额 / 服务人数</small></div><div><span>人均项</span><strong>{{ number(overview.service?.average_items?.value) }}</strong><small>项目数 / 人数</small></div><div><span>项目单耗</span><strong>{{ money(overview.service?.unit_amount?.value_cents) }}</strong><small>耗卡金额 / 项目数</small></div></div></article></div></section>
      <section class="member-dashboard__section"><div class="member-dashboard__section-title"><div><h2>消费顾客分析</h2><span>分类读取当前启用的一级商品分类；每个分类独立展示排行</span></div></div><div class="member-dashboard__summary-row"><div><span>消费顾客数</span><strong>{{ number(consumption.people?.value) }}</strong></div><div><span>消费金额</span><strong>{{ money(consumption.amount?.value_cents) }}</strong></div><div><span>人均消费金额</span><strong>{{ money(consumption.average?.value_cents) }}</strong></div></div><div class="member-dashboard__category-grid" :style="{ '--category-count': Math.max(categories.length, 1) }"><div v-for="category in categories" :key="category.id" class="category-column"><article class="category-panel"><header><Tags :size="16" aria-hidden="true" /><strong>{{ category.name }}</strong></header><div><span>消费会员数</span><b>{{ number(category.people) }}</b></div><div><span>消费金额</span><b>{{ money(category.amount_cents) }}</b></div><div><span>人均消费</span><b>{{ money(category.average_cents) }}</b></div><div><span>金额占比</span><b>{{ percent(category.share) }}</b></div></article><article class="ranking-panel category-ranking-panel"><header><div><h3>消费排行</h3><span>项目 / 产品 · 前五名</span></div><div class="member-dashboard__segmented"><button type="button" :class="{ 'is-active': categoryRankMode(category, 'consumption') === 'projects' }" @click="setCategoryRankMode(category, 'consumption', 'projects')">项目</button><button type="button" :class="{ 'is-active': categoryRankMode(category, 'consumption') === 'products' }" @click="setCategoryRankMode(category, 'consumption', 'products')">产品</button></div></header><div v-for="(row, index) in categoryRankRows(category, 'consumption')" :key="row.name" :class="['ranking-compact__row', row.__empty ? 'ranking-compact__row--empty' : `ranking-compact__row--rank-${index + 1}`]" :aria-hidden="row.__empty ? 'true' : undefined"><em class="ranking-compact__rank">{{ row.__empty ? '' : index + 1 }}</em><strong>{{ row.__empty ? '' : row.name }}</strong><span>{{ row.__empty ? '' : money(row.cash_income_cents) }}</span><small>{{ row.__empty ? '' : `${number(row.orders)}单 · ${number(row.people)}人 · 人均 ${money(row.average_cents)}` }}</small></div></article><article class="ranking-panel category-ranking-panel"><header><div><h3>消耗排行</h3><span>项目 / 产品 · 前五名</span></div><div class="member-dashboard__segmented"><button type="button" :class="{ 'is-active': categoryRankMode(category, 'consumptionAmount') === 'projects' }" @click="setCategoryRankMode(category, 'consumptionAmount', 'projects')">项目</button><button type="button" :class="{ 'is-active': categoryRankMode(category, 'consumptionAmount') === 'products' }" @click="setCategoryRankMode(category, 'consumptionAmount', 'products')">产品</button></div></header><div v-for="(row, index) in categoryRankRows(category, 'consumptionAmount')" :key="row.name" :class="['ranking-compact__row', row.__empty ? 'ranking-compact__row--empty' : `ranking-compact__row--rank-${index + 1}`]" :aria-hidden="row.__empty ? 'true' : undefined"><em class="ranking-compact__rank">{{ row.__empty ? '' : index + 1 }}</em><strong>{{ row.__empty ? '' : row.name }}</strong><span>{{ row.__empty ? '' : money(row.consumption_cents) }}</span><small>{{ row.__empty ? '' : `${number(row.people)}人 · 人均 ${money(row.average_cents)}` }}</small></div></article></div><div v-if="!categories.length" class="member-dashboard__empty">暂无商品分类数据</div></div></section>
      <section class="member-dashboard__section"><div class="member-dashboard__section-title"><div><h2>到店波段</h2><span>累计统计；满足 360 天条件的会员同时计入前序波段</span></div></div><template v-if="(visitBands.recency || []).length || (visitBands.frequency || []).length"><div class="visit-grid"><div v-for="item in visitBands.recency || []" :key="item.label" class="visit-card"><span>{{ item.label }}</span><strong>{{ number(item.value) }}</strong><small>顾客数</small></div></div><div class="visit-grid visit-grid--frequency"><div v-for="item in visitBands.frequency || []" :key="item.label" class="visit-card visit-card--frequency"><span>{{ item.label }}</span><strong>{{ number(item.value) }}</strong><small>顾客数</small></div></div></template><p v-else class="member-dashboard__empty-inline">暂无到店波段数据</p></section>
      <section class="member-dashboard__section"><div class="member-dashboard__section-title"><div><h2>会员消费层级</h2><span>左侧会员分层；右侧新客分层，不统计来源 A</span></div></div><div class="tier-grid"><article v-for="key in ['member', 'new_customer']" :key="key" class="tier-panel"><header><div><h3>{{ key === 'member' ? '会员分层' : '新客分层（不统计来源 A）' }}</h3><span>按现金业绩净额匹配区间</span></div><button type="button" class="member-dashboard__guide" @click="openTierSettings"><BookOpen :size="14" aria-hidden="true" />区间设置</button></header><div v-if="(tiers[key] || []).length" class="tier-table"><div class="tier-table__head"><span>层级</span><span>顾客人数</span><span>消费金额</span><span>会员占比</span><span>金额占比</span><span>人均消费</span></div><div v-for="row in tiers[key] || []" :key="row.name" class="tier-table__row"><strong>{{ row.name }}</strong><span>{{ number(row.people) }}</span><span>{{ money(row.amount_cents) }}</span><span>{{ percent(row.member_share) }}</span><span>{{ percent(row.amount_share) }}</span><span>{{ money(row.average_cents) }}</span></div></div><p v-else class="member-dashboard__empty-inline">暂无消费层级数据</p></article></div></section>
    </template>
    <div v-if="showFieldGuide" class="member-dashboard__modal-backdrop" @click.self="showFieldGuide = false"><section class="member-dashboard__modal" role="dialog" aria-modal="true" aria-label="字段说明"><header><h2>字段说明</h2><button type="button" aria-label="关闭字段说明" @click="showFieldGuide = false">×</button></header><div class="member-dashboard__explanations"><div v-for="(text, key) in fieldExplanations" :key="key"><strong>{{ fieldExplanationLabel(key) }}</strong><span>{{ text }}</span></div></div></section></div>
    <div v-if="showTierGuide" class="member-dashboard__modal-backdrop" @click.self="showTierGuide = false"><section class="member-dashboard__modal" role="dialog" aria-modal="true" aria-label="消费层级说明"><header><h2>消费层级设置</h2><button type="button" aria-label="关闭消费层级说明" @click="showTierGuide = false">×</button></header><p>消费区间复用集团统一消费分级配置，保存、排序、版本冲突和审计沿用既有消费分级接口。</p><p>当前看板只读展示服务端已启用区间；需要修改时请进入“消费分级设置”页面。</p><button type="button" class="button button--primary member-dashboard__tier-settings" @click="goTierSettings">进入消费分级设置</button></section></div>
  </main>
</template>

<style scoped>
.member-dashboard{min-height:100vh;padding:18px;background:#f4f7fa;color:#263b4e}.member-dashboard__header,.member-dashboard__filters,.member-dashboard__section{width:100%;max-width:1680px;margin:0 auto}.member-dashboard__header{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;padding:2px 0 16px}.member-dashboard__eyebrow{color:#3978aa;font-size:12px;font-weight:700;letter-spacing:.08em}.member-dashboard h1{margin:4px 0 6px;color:#1d3347;font-size:27px}.member-dashboard__header p{margin:0;color:#7b8b9b;font-size:12px}.member-dashboard__asof{color:#738698;font-size:12px;white-space:nowrap}.member-dashboard__filters{display:flex;align-items:center;gap:10px;overflow-x:auto;margin-bottom:16px;padding:10px 12px;border:1px solid #dce5ed;border-radius:7px;background:#fff;white-space:nowrap}.member-dashboard__scope,.member-dashboard__date{display:inline-flex;align-items:center;gap:6px;color:#61758a;font-size:12px}.member-dashboard__scope{padding-right:4px;font-weight:700}.member-dashboard__periods{display:flex;gap:5px}.member-dashboard__periods button,.member-dashboard__segmented button{border:1px solid #d7e1ea;background:#fff;color:#687b8e;font-size:12px;cursor:pointer}.member-dashboard__periods button{border-radius:4px;padding:6px 11px}.member-dashboard__periods button.is-active,.member-dashboard__segmented button.is-active{border-color:#3c83b9;background:#edf6fd;color:#206b9e;font-weight:700}.member-dashboard__date input{min-height:28px;border:1px solid #d7e1ea;border-radius:4px;padding:3px 6px;color:#42586c;font-size:12px}.member-dashboard__actions{display:flex;gap:7px;margin-left:auto}.button{display:inline-flex;align-items:center;gap:5px;border:1px solid transparent;border-radius:4px;padding:7px 11px;font-size:12px}.button--primary{background:#2e79ae;color:#fff}.button--secondary{border-color:#d7e1ea;background:#fff;color:#5d7185}.member-dashboard__state{max-width:1680px;margin:0 auto 16px;padding:18px;border:1px solid #dce5ed;border-radius:7px;background:#fff;color:#718597;text-align:center;font-size:13px}.member-dashboard__state--error{border-color:#edc7c7;background:#fff7f7;color:#ae4f4f}.member-dashboard__section{margin-bottom:16px}.member-dashboard__section-title{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:10px}.member-dashboard__section-title h2{margin:0;color:#2b4358;font-size:18px}.member-dashboard__section-title span{display:block;margin-top:3px;color:#8291a0;font-size:11px}.member-dashboard__guide{display:inline-flex;align-items:center;gap:5px;border:0;background:transparent;color:#3178aa;font-size:12px;cursor:pointer}.member-dashboard__overview-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.member-panel,.ranking-panel,.tier-panel{border:1px solid #dce5ed;border-radius:7px;background:#fff}.member-panel{min-width:0;overflow:hidden}.member-panel header,.ranking-panel>header,.tier-panel>header{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:13px 15px;border-bottom:1px solid #e8eef3}.member-panel header{justify-content:flex-start;color:#3276a8}.member-panel h3,.ranking-panel h3,.tier-panel h3{margin:0;color:#2f475b;font-size:15px}.member-panel__rows{display:grid}.member-panel__rows>div{display:grid;grid-template-columns:70px minmax(0,1fr);align-items:center;gap:7px;min-height:68px;padding:9px 15px;border-bottom:1px solid #edf1f4}.member-panel__rows>div:last-child{border-bottom:0}.member-panel__rows span,.member-panel__service-grid span{color:#5f7488;font-size:12px;font-weight:700}.member-panel strong,.member-panel__service-grid strong{color:#2b4358;font-size:20px}.member-panel small,.ranking-panel header span,.tier-panel header span{display:block;color:#8a98a6;font-size:10px}.member-panel__rows small{grid-column:2}.member-panel--member header{color:#3975a5}.member-panel--consumption header{color:#9a6a23}.member-panel--service header{color:#36836b}.member-panel__service-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.member-panel__service-grid>div{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 8px;align-items:center;min-height:68px;padding:9px 12px;border-bottom:1px solid #edf1f4}.member-panel__service-grid>div:nth-child(odd){border-right:1px solid #edf1f4}.member-panel__service-grid small{grid-column:1/-1;color:#8a98a6;font-size:10px}.member-dashboard__summary-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:12px}.member-dashboard__summary-row>div{display:grid;gap:6px;border-left:3px solid #3f82b4;padding:12px 14px;background:#fff}.member-dashboard__summary-row span{color:#6e8192;font-size:12px}.member-dashboard__summary-row strong{color:#2c465c;font-size:20px}.member-dashboard__category-scroll{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px}.category-panel{flex:0 0 calc((100% - 50px)/6);min-width:160px;border:1px solid #dce5ed;border-top:3px solid #4a8b9a;border-radius:6px;padding:12px;background:#fff}.category-panel header{display:flex;align-items:center;gap:6px;margin-bottom:10px;color:#327b85}.category-panel header strong{color:#2e485b;font-size:14px}.category-panel>div{display:flex;justify-content:space-between;gap:8px;padding:5px 0;border-bottom:1px solid #eff3f6;color:#778999;font-size:11px}.category-panel>div:last-child{border-bottom:0}.category-panel b{color:#395268;font-weight:700}.member-dashboard__empty{width:100%;padding:40px;color:#8a99a7;text-align:center;font-size:13px}.member-dashboard__ranking-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px}.ranking-panel{min-width:0;overflow:hidden}.ranking-panel>header{align-items:flex-start}.ranking-panel header>div:first-child{min-width:0}.member-dashboard__segmented{display:flex;flex:none}.member-dashboard__segmented button{padding:5px 8px}.ranking-panel__head,.ranking-panel__row{display:grid;grid-template-columns:24px minmax(110px,1fr) 92px 62px 66px 88px;align-items:center;gap:7px;padding:8px 13px;font-size:11px}.ranking-panel__head{background:#f6f9fb;color:#8393a1}.ranking-panel__head--consumption,.ranking-panel__row--consumption{grid-template-columns:24px minmax(110px,1fr) 96px 70px 88px}.ranking-panel__row{border-top:1px solid #edf1f4;color:#62778a}.ranking-panel__row em{font-style:normal;color:#397cac}.ranking-panel__row strong{overflow:hidden;color:#364e63;text-overflow:ellipsis;white-space:nowrap}.ranking-panel__row span{text-align:right}.visit-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.visit-grid--frequency{margin-top:10px}.visit-card{display:grid;gap:6px;min-height:92px;border-top:3px solid #3f83b6;border-radius:6px;padding:13px;background:#fff}.visit-card span{color:#63788b;font-size:12px}.visit-card strong{color:#2e526c;font-size:22px}.visit-card small{color:#8a98a6;font-size:10px}.visit-card--frequency{border-top-color:#6c8b6a}.tier-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.tier-panel{min-width:0;overflow:hidden}.tier-table{overflow-x:auto}.tier-table__head,.tier-table__row{display:grid;grid-template-columns:minmax(95px,1.2fr) repeat(5,minmax(85px,1fr));align-items:center;gap:8px;padding:9px 13px;font-size:11px}.tier-table__head{background:#f6f9fb;color:#8393a1}.tier-table__row{border-top:1px solid #edf1f4;color:#667a8c}.tier-table__row strong{color:#344d63}.tier-table__row span{text-align:right}.tier-panel>header .member-dashboard__guide{font-size:11px}@media(max-width:1180px){.member-dashboard__overview-grid{grid-template-columns:repeat(3,minmax(260px,1fr))}.member-dashboard__ranking-grid,.tier-grid{grid-template-columns:1fr}.category-panel{flex-basis:calc((100% - 20px)/3)}.member-dashboard{min-width:980px}}@media(max-width:760px){.member-dashboard{padding:12px;min-width:760px}.member-dashboard__header{align-items:flex-start;flex-direction:column}.member-dashboard__overview-grid{grid-template-columns:repeat(3,250px)}.member-dashboard__summary-row{grid-template-columns:repeat(3,220px)}.category-panel{flex-basis:200px}.visit-grid{grid-template-columns:repeat(5,150px)}.tier-grid{grid-template-columns:760px}}
.member-dashboard { height: 100vh; overflow-y: auto; overflow-x: hidden; }
.member-dashboard__category-grid { display: grid; grid-template-columns: repeat(var(--category-count), minmax(250px, 1fr)); gap: 12px; overflow-x: auto; padding-bottom: 4px; }
.category-column { display: grid; min-width: 250px; gap: 10px; align-content: start; }
.category-column .category-panel { min-width: 0; }
.category-ranking-panel { min-width: 0; overflow: hidden; }
.category-ranking-panel > header { align-items: flex-start; }
.ranking-compact__row { display: grid; grid-template-columns: 18px minmax(0, 1fr) auto; gap: 4px 7px; align-items: center; height: 52px; min-height: 52px; box-sizing: border-box; overflow: hidden; padding: 9px 12px; border-top: 1px solid #edf1f4; color: #61768a; font-size: 11px; }
.ranking-compact__row em { color: #397cac; font-style: normal; }
.ranking-compact__rank { display: inline-grid; width: 18px; height: 18px; place-items: center; border-radius: 50%; background: #6e92a7; color: #fff !important; font-size: 10px; font-weight: 700; }
.ranking-compact__row--rank-1 { background: #fff7df; }
.ranking-compact__row--rank-2 { background: transparent; }
.ranking-compact__row--rank-3 { background: transparent; }
.ranking-compact__row--rank-1 .ranking-compact__rank { background: #d49b2c; }
.ranking-compact__row--rank-2 .ranking-compact__rank { background: #b68162; }
.ranking-compact__row--rank-3 .ranking-compact__rank { background: #d6c978; color: #5f562d !important; }
.ranking-compact__row--rank-4 .ranking-compact__rank,
.ranking-compact__row--rank-5 .ranking-compact__rank { background: #a9bbc5; }
.ranking-compact__row strong { overflow: hidden; color: #364e63; text-overflow: ellipsis; white-space: nowrap; }
.ranking-compact__row > span { color: #2f536d; font-weight: 700; text-align: right; }
.ranking-compact__row small { grid-column: 2 / -1; color: #8997a5; font-size: 10px; }
.ranking-compact__row--empty { pointer-events: none; }
@media(max-width:1180px){.member-dashboard__category-grid{grid-template-columns:repeat(var(--category-count),minmax(235px,1fr))}.category-column{min-width:235px}}
html.member-dashboard-document,html.member-dashboard-document body,html.member-dashboard-document #app{min-width:0;width:100%;overflow-x:hidden}
.member-dashboard__category-grid{grid-template-columns:repeat(var(--category-count),minmax(0,1fr));overflow-x:hidden}
.category-column{min-width:0}
.ranking-compact__row{min-width:0}
.ranking-compact__row strong{min-width:0;overflow-wrap:anywhere}
@media(max-width:1180px){.member-dashboard__category-grid{grid-template-columns:repeat(var(--category-count),minmax(0,1fr))}.category-column{min-width:0}.ranking-compact__row{grid-template-columns:14px minmax(0,1fr) auto;padding:8px 6px;font-size:10px}.ranking-compact__row small{font-size:9px}.category-panel{padding:8px}.category-panel header strong{font-size:12px}.category-panel>div{font-size:9px}}
@media(max-width:1180px){.member-dashboard{min-width:0}.member-dashboard__overview-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.member-dashboard__summary-row{grid-template-columns:repeat(3,minmax(0,1fr))}.visit-grid{grid-template-columns:repeat(5,minmax(0,1fr))}.tier-grid{grid-template-columns:minmax(0,1fr)}.category-panel{min-width:0}.tier-table{overflow-x:hidden}.tier-table__head,.tier-table__row{grid-template-columns:minmax(0,1.2fr) repeat(5,minmax(0,1fr));gap:4px;padding-left:7px;padding-right:7px}}
@media(max-width:760px){.member-dashboard{padding:12px}.member-dashboard__overview-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.member-dashboard__summary-row{grid-template-columns:repeat(3,minmax(0,1fr))}.category-column{min-width:0}.visit-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
.member-dashboard__scope-picker{position:relative;flex:none}.member-dashboard__scope-trigger{display:inline-flex;align-items:center;gap:6px;border:1px solid #d7e1ea;border-radius:4px;padding:7px 10px;background:#fff;color:#526b80;font-size:12px;cursor:pointer}.member-dashboard__scope-trigger:disabled{cursor:wait;opacity:.7}.member-dashboard__scope-panel{position:absolute;z-index:20;top:calc(100% + 7px);left:0;width:min(620px,calc(100vw - 28px));border:1px solid #d5e0e8;border-radius:7px;background:#fff;box-shadow:0 12px 32px rgba(33,58,80,.16);white-space:normal}.member-dashboard__scope-panel>header{padding:10px 12px;border-bottom:1px solid #e8eef3;color:#344f65;font-size:12px;font-weight:700}.member-dashboard__scope-body{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);max-height:320px}.member-dashboard__scope-tree,.member-dashboard__scope-stores{min-width:0;overflow:auto;padding:8px}.member-dashboard__scope-tree{border-right:1px solid #edf1f4}.member-dashboard__scope-row{display:flex;align-items:center;gap:4px;min-height:30px}.member-dashboard__scope-toggle,.member-dashboard__scope-toggle-placeholder{width:20px;height:22px;flex:none}.member-dashboard__scope-toggle{border:0;background:transparent;color:#6b8193;cursor:pointer}.member-dashboard__scope-option,.member-dashboard__scope-stores button{display:block;width:100%;border:0;border-radius:4px;padding:7px 8px;background:transparent;color:#536b7e;text-align:left;font-size:12px;cursor:pointer}.member-dashboard__scope-option:hover,.member-dashboard__scope-stores button:hover,.member-dashboard__scope-stores button.is-active{background:#edf6fd;color:#206b9e}.member-dashboard__scope-stores{display:flex;flex-direction:column;gap:5px}.member-dashboard__scope-stores strong{padding:5px 8px;color:#38566d;font-size:12px}.member-dashboard__scope-stores span{padding:8px;color:#92a0ac;font-size:11px}.member-dashboard__scope-panel>footer{display:flex;align-items:center;gap:8px;padding:8px 12px;border-top:1px solid #e8eef3;color:#8595a3;font-size:11px}.member-dashboard__scope-panel>footer button{border:0;background:transparent;color:#2f79aa;cursor:pointer;font-size:11px}.member-dashboard__category-filter{display:inline-flex;align-items:center;gap:6px;color:#61758a;font-size:12px}.member-dashboard__category-filter select{min-height:28px;max-width:170px;border:1px solid #d7e1ea;border-radius:4px;padding:3px 7px;background:#fff;color:#42586c;font-size:12px}.member-dashboard__empty-inline{margin:0;padding:24px;color:#8a99a7;text-align:center;font-size:12px}.member-dashboard__modal-backdrop{position:fixed;z-index:50;inset:0;display:grid;place-items:center;padding:18px;background:rgba(20,39,55,.38)}.member-dashboard__modal{width:min(680px,100%);max-height:min(78vh,720px);overflow:auto;border:1px solid #d7e2ea;border-radius:8px;background:#fff;box-shadow:0 18px 50px rgba(20,39,55,.24);color:#52697c;font-size:12px;line-height:1.7}.member-dashboard__modal>header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid #e8eef3}.member-dashboard__modal h2{margin:0;color:#2e475d;font-size:17px}.member-dashboard__modal>header button{border:0;background:transparent;color:#7890a2;font-size:22px;line-height:1;cursor:pointer}.member-dashboard__modal>p{margin:14px 18px}.member-dashboard__explanations{display:grid;gap:0;padding:8px 18px 16px}.member-dashboard__explanations>div{display:grid;grid-template-columns:minmax(145px,.42fr) minmax(0,1fr);gap:14px;padding:9px 0;border-bottom:1px solid #edf1f4}.member-dashboard__explanations strong{color:#36556c}.member-dashboard__explanations span{color:#607688}.member-dashboard__tier-settings{margin:0 18px 18px}
.member-dashboard__filters{overflow:visible;flex-wrap:wrap;white-space:normal}.tier-table{overflow-x:hidden}
.member-dashboard__header{align-items:center;justify-content:flex-start;min-height:40px;padding:0 0 8px}
.member-dashboard__header h1{margin:0;text-align:left}
main.member-dashboard > section.member-dashboard__section:nth-of-type(2) .member-dashboard__section-title span{display:none}
@media(max-width:760px){.member-dashboard__header{align-items:flex-start;justify-content:flex-start;min-height:36px;padding-bottom:6px}}
.member-dashboard{--metric-amount:#1f5f8b;--metric-count:#2b4358;--metric-activity:#357a72;--metric-average:#3b78a5;--metric-attention:#b7791f}
.member-panel--member .member-panel__rows>div:nth-child(1) strong,.member-panel--member .member-panel__rows>div:nth-child(2) strong{color:var(--metric-count)}
.member-panel--member .member-panel__rows>div:nth-child(3) strong{color:var(--metric-attention)}
.member-panel--consumption .member-panel__rows>div:nth-child(1) strong{color:var(--metric-count)}
.member-panel--consumption .member-panel__rows>div:nth-child(2) strong{color:var(--metric-amount)}
.member-panel--consumption .member-panel__rows>div:nth-child(3) strong{color:var(--metric-average)}
.member-panel--service .member-panel__service-grid>div:nth-child(1) strong{color:var(--metric-count)}
.member-panel--service .member-panel__service-grid>div:nth-child(2) strong,.member-panel--service .member-panel__service-grid>div:nth-child(4) strong,.member-panel--service .member-panel__service-grid>div:nth-child(6) strong{color:var(--metric-amount)}
.member-panel--service .member-panel__service-grid>div:nth-child(3) strong,.member-panel--service .member-panel__service-grid>div:nth-child(5) strong{color:var(--metric-activity)}
.member-dashboard__summary-row>div:nth-child(1) strong{color:var(--metric-count)}
.member-dashboard__summary-row>div:nth-child(2) strong{color:var(--metric-amount)}
.member-dashboard__summary-row>div:nth-child(3) strong{color:var(--metric-average)}
.category-panel>div:nth-child(1) b{color:var(--metric-count)}
.category-panel>div:nth-child(2) b{color:var(--metric-amount)}
.category-panel>div:nth-child(3) b{color:var(--metric-average)}
.category-panel>div:nth-child(4) b{color:var(--metric-activity)}
.visit-card strong{color:var(--metric-count)}
.visit-card--frequency strong{color:var(--metric-activity)}
.tier-table__row span:nth-of-type(1){color:var(--metric-count)}
.tier-table__row span:nth-of-type(2){color:var(--metric-amount)}
.tier-table__row span:nth-of-type(3),.tier-table__row span:nth-of-type(4){color:var(--metric-activity)}
.tier-table__row span:nth-of-type(5){color:var(--metric-average)}
.ranking-compact__row>span{color:var(--metric-amount)}
.member-panel--member{border-top:3px solid #3975a5}
.member-panel--member>header{background:#f4f8fc}
.member-panel--consumption{border-top:3px solid #b7791f}
.member-panel--consumption>header{background:#fffaf0}
.member-panel--service{border-top:3px solid #357a72}
.member-panel--service>header{background:#f2faf7}
.member-dashboard__summary-row>div{border-left-color:#1f5f8b}
.category-panel{border-top-color:#357a72}
.visit-card{border-top-color:#3b78a5}
.visit-card--frequency{border-top-color:#357a72}
.tier-panel:first-child{border-top:3px solid #3b78a5}
.tier-panel:last-child{border-top:3px solid #357a72}
</style>
