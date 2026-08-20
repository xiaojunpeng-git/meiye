<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import BookOpen from '@lucide/vue/dist/esm/icons/book-open.mjs'
import Boxes from '@lucide/vue/dist/esm/icons/boxes.mjs'
import CircleDollarSign from '@lucide/vue/dist/esm/icons/circle-dollar-sign.mjs'
import Package from '@lucide/vue/dist/esm/icons/package.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import Search from '@lucide/vue/dist/esm/icons/search.mjs'
import TriangleAlert from '@lucide/vue/dist/esm/icons/triangle-alert.mjs'
import OrganizationStoreScopePicker from '@/components/OrganizationStoreScopePicker.vue'
import { queryProductDashboard, queryProductDashboardScope } from '@/services/productDashboardApi'

const period = ref('month')
const customStart = ref('2026-08-01')
const customEnd = ref('2026-08-20')
const categoryId = ref('all')
const productType = ref('all')
const loading = ref(false)
const refreshedAt = ref('2026-08-20 15:30')
const queryMessage = ref('正在连接统一商品看板服务...')
const categoryRankType = ref({})
const scopePicker = ref({
  loading: false,
  label: '当前权限范围',
  selectedStoreIds: [],
  allowedStoreIds: [],
  tree: []
})

const legacyPrototypeFixtures = Object.freeze([
  {
    id: 'garden', name: '花园', income: '¥96,800', cost: '¥38,420', profit: '¥58,380', rate: '60.3%',
    projects: [
      ['水光焕肤管理', '¥28,600', '¥8,580', '¥20,020', '70.0%'],
      ['花园焕亮护理', '¥21,900', '¥8,760', '¥13,140', '60.0%'],
      ['舒缓修护管理', '¥17,600', '¥7,040', '¥10,560', '60.0%'],
      ['屏障修护护理', '¥14,800', '¥5,920', '¥8,880', '60.0%'],
      ['深层清洁管理', '¥13,900', '¥8,120', '¥5,780', '41.6%']
    ],
    products: [
      ['花园修护套盒', '¥19,680', '¥7,020', '¥12,660', '64.3%'],
      ['盈润焕亮精华', '¥14,800', '¥5,476', '¥9,324', '63.0%'],
      ['舒缓保湿面膜', '¥11,260', '¥4,842', '¥6,418', '57.0%'],
      ['净润洁面乳', '¥9,600', '¥4,416', '¥5,184', '54.0%'],
      ['密集修护霜', '¥8,180', '¥4,050', '¥4,130', '50.5%']
    ]
  },
  {
    id: 'beauty', name: '生美', income: '¥73,420', cost: '¥31,580', profit: '¥41,840', rate: '57.0%',
    projects: [
      ['面部紧致管理', '¥22,500', '¥9,000', '¥13,500', '60.0%'],
      ['肩颈舒缓护理', '¥18,920', '¥7,568', '¥11,352', '60.0%'],
      ['眼部焕活管理', '¥13,600', '¥6,120', '¥7,480', '55.0%'],
      ['身体循环管理', '¥10,400', '¥5,096', '¥5,304', '51.0%'],
      ['肌底补水护理', '¥8,000', '¥3,796', '¥4,204', '52.6%']
    ],
    products: [
      ['紧致赋活组合', '¥15,920', '¥6,208', '¥9,712', '61.0%'],
      ['身体护理精油', '¥12,600', '¥5,796', '¥6,804', '54.0%'],
      ['眼部修护精华', '¥10,200', '¥4,488', '¥5,712', '56.0%'],
      ['胶原保湿乳', '¥8,600', '¥3,956', '¥4,644', '54.0%'],
      ['焕活冻干粉', '¥6,480', '¥3,110', '¥3,370', '52.0%']
    ]
  },
  {
    id: 'private', name: '私域', income: '¥52,460', cost: '¥22,130', profit: '¥30,330', rate: '57.8%',
    projects: [
      ['私域定制护理', '¥18,800', '¥7,520', '¥11,280', '60.0%'],
      ['居家焕肤指导', '¥12,960', '¥5,573', '¥7,387', '57.0%'],
      ['专属修护管理', '¥9,800', '¥4,410', '¥5,390', '55.0%'],
      ['会员定制护理', '¥6,900', '¥3,450', '¥3,450', '50.0%'],
      ['季节舒缓管理', '¥4,000', '¥2,048', '¥1,952', '48.8%']
    ],
    products: [
      ['私域焕肤礼盒', '¥11,800', '¥4,366', '¥7,434', '63.0%'],
      ['修护安瓶组合', '¥9,600', '¥4,128', '¥5,472', '57.0%'],
      ['会员专享面膜', '¥7,260', '¥3,339', '¥3,921', '54.0%'],
      ['居家护理套组', '¥5,480', '¥2,740', '¥2,740', '50.0%'],
      ['舒缓喷雾', '¥4,320', '¥2,074', '¥2,246', '52.0%']
    ]
  }
])

const legacyInventoryFixture = Object.freeze([
  { id: 'skin', name: '护肤品', amount: '¥128,460', rows: [['花园修护套盒', '184', '¥34,960'], ['盈润焕亮精华', '162', '¥26,244'], ['舒缓保湿面膜', '420', '¥18,900'], ['密集修护霜', '168', '¥15,288'], ['净润洁面乳', '210', '¥12,600'], ['焕亮精华水', '136', '¥8,568'], ['温和卸妆乳', '99', '¥5,940'], ['修护精华液', '52', '¥3,900'], ['清透防晒乳', '70', '¥1,960'], ['焕亮眼霜', '34', '¥100']] },
  { id: 'beauty-device', name: '仪器', amount: '¥76,800', rows: [['纳米导入仪', '8', '¥28,800'], ['冷热喷仪', '12', '¥18,000'], ['射频美容仪', '6', '¥15,600'], ['皮肤检测仪', '5', '¥7,500'], ['超声波导入仪', '4', '¥4,800'], ['蒸脸仪', '9', '¥1,800'], ['美容放大镜', '8', '¥192'], ['消毒柜', '2', '¥72'], ['护理推车', '3', '¥27'], ['工具收纳架', '1', '¥9']] },
  { id: 'wash', name: '洗护', amount: '¥45,620', rows: [['头皮净化套装', '120', '¥13,200'], ['滋养洗发露', '238', '¥11,900'], ['丝滑护发素', '215', '¥9,675'], ['香氛沐浴露', '160', '¥6,400'], ['精油护发膜', '84', '¥2,940'], ['头皮舒缓喷雾', '68', '¥1,224'], ['旅行装洗护组', '42', '¥168'], ['干发喷雾', '31', '¥62'], ['香氛身体乳', '20', '¥40'], ['按摩梳', '11', '¥11']] }
])

const legacyExpiryCardFixture = Object.freeze([
  { key: 'expired', label: '已过期', value: '18', tone: 'expired' },
  { key: 'within-30', label: '30天内临期', value: '46', tone: 'urgent' },
  { key: 'within-60', label: '31至60天', value: '72', tone: 'warm' },
  { key: 'within-90', label: '61至90天', value: '116', tone: 'watch' },
  { key: 'over-90', label: '90天以上', value: '1,284', tone: 'normal' },
  { key: 'unknown', label: '到期日未知', value: '12', tone: 'unknown' }
])

const legacyExpiryRowFixture = Object.freeze([
  ['密集修护霜（50g）', '护肤品', 'HG240115', '18', '2026-08-14', '已过期', '已过期', '¥1,638'],
  ['舒缓保湿面膜（5片）', '护肤品', 'HG240203', '46', '2026-08-28', '8天', '30天内临期', '¥2,070'],
  ['滋养洗发露（500ml）', '洗护', 'XH240312', '72', '2026-09-26', '37天', '31至60天', '¥3,600'],
  ['冷热喷仪', '仪器', 'YQ240018', '6', '2026-10-31', '72天', '61至90天', '¥9,000']
])

const categoryOptions = ref([])
const overviewCards = ref([])
const profitCategories = ref([])
const inventoryCategories = ref([])
const expiryCards = ref([])
const expiryRows = ref([])
let queuedQuery = false
let refreshTimer = null
const AUTO_REFRESH_INTERVAL = 15 * 60 * 1000

function today() {
  const date = new Date()
  const pad = (value) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

function rangeForPeriod() {
  const end = today()
  if (period.value === 'today') return { start_date: end, end_date: end }
  if (period.value === 'year') return { start_date: `${end.slice(0, 4)}-01-01`, end_date: end }
  if (period.value === 'custom') return { start_date: customStart.value, end_date: customEnd.value }
  return { start_date: `${end.slice(0, 7)}-01`, end_date: end }
}

function money(value) {
  if (value === null || value === undefined || value === '') return '-'
  return `¥${(Number(value) / 100).toLocaleString('zh-CN', { maximumFractionDigits: 2 })}`
}

function signedMoney(value) {
  if (value === null || value === undefined) return '-'
  return `${Number(value) >= 0 ? '+' : ''}${money(value)}`
}

function percent(value) {
  if (value === null || value === undefined) return '-'
  return `${Number(value).toFixed(1).replace(/\.0$/, '')}%`
}

function mapMetric(label, tone, metric) {
  const current = Number(metric?.value_cents || 0)
  const yoy = Number(metric?.yoy?.value_cents || 0)
  const mom = Number(metric?.mom?.value_cents || 0)
  const max = Math.max(current, yoy, mom, 1)
  return {
    label, tone, value: money(metric?.value_cents),
    yoyAmount: signedMoney(metric?.yoy?.delta_cents), yoyRate: percent(metric?.yoy?.rate),
    momAmount: signedMoney(metric?.mom?.delta_cents), momRate: percent(metric?.mom?.rate),
    bars: [{ label: '本期', amount: money(metric?.value_cents), value: current / max * 100 }, { label: '同比', amount: money(metric?.yoy?.value_cents), value: yoy / max * 100 }, { label: '环比', amount: money(metric?.mom?.value_cents), value: mom / max * 100 }]
  }
}

function applyDashboard(data) {
  const summary = data?.summary || {}
  overviewCards.value = [mapMetric('销售业绩', 'sales', summary.sales), mapMetric('消耗业绩', 'consumption', summary.consumption), mapMetric('品项毛利', 'profit', summary.gross_profit)]
  categoryOptions.value = [{ value: 'all', label: '全部商品' }, ...((data?.filter_schema?.categories || []).map((item) => ({ value: String(item.id), label: item.name })))]
  profitCategories.value = (data?.profit_categories || []).map((item) => ({
    id: String(item.category_id), name: item.name, income: money(item.income_cents), cost: money(item.cost_cents), profit: money(item.gross_profit_cents), rate: percent(item.gross_margin_rate),
    projects: (item.project_rankings || []).map((row) => [row.name, money(row.revenue_cents), money(row.cost_cents), money(row.gross_profit_cents), percent(row.gross_margin_rate)]),
    products: (item.product_rankings || []).map((row) => [row.name, money(row.revenue_cents), money(row.cost_cents), money(row.gross_profit_cents), percent(row.gross_margin_rate)])
  }))
  inventoryCategories.value = (data?.product_inventory_categories || []).map((item) => ({ id: String(item.category_id), name: item.name, amount: money(item.inventory_cost_cents), rows: (item.rows || []).map((row) => [row.product_name, row.available_quantity, money(row.inventory_cost_cents)]) }))
  expiryCards.value = (data?.expiry_alerts?.buckets || []).map((item) => ({ key: item.key, label: item.label, value: Number(item.quantity ?? item.count ?? 0).toLocaleString('zh-CN'), tone: item.key === 'OVERDUE' ? 'expired' : item.key === 'WITHIN_30' ? 'urgent' : item.key === 'DAYS_31_60' ? 'warm' : item.key === 'DAYS_61_90' ? 'watch' : item.key === 'SAFE_OVER_90' ? 'normal' : 'unknown' }))
  expiryRows.value = (data?.expiry_alerts?.rows || []).map((row) => [row.product_name, row.category_name, row.batch_no, row.current_quantity, row.expire_date || '-', row.days === null ? '-' : `${row.days}天`, row.status, money(row.inventory_cost_cents)])
  refreshedAt.value = data?.data_as_of || today()
}

async function loadScope() {
  try {
    const data = await queryProductDashboardScope()
    scopePicker.value = { ...scopePicker.value, tree: data.tree || [], allowedStoreIds: data.allowed_store_ids || [], loading: false }
  } catch (error) {
    scopePicker.value = { ...scopePicker.value, loading: false }
    queryMessage.value = error.message || '权限范围读取失败。'
  }
}

async function loadDashboard(action = '查询') {
  if (loading.value) {
    queuedQuery = true
    return
  }
  loading.value = true
  queryMessage.value = `${action}中，正在读取商品看板...`
  try {
    const data = await queryProductDashboard({ ...rangeForPeriod(), category_id: categoryId.value === 'all' ? 0 : categoryId.value, product_type: productType.value, store_ids: scopePicker.value.selectedStoreIds.join(',') })
    applyDashboard(data)
    queryMessage.value = action === '刷新' ? '已刷新商品看板。' : '已按当前筛选更新商品看板。'
  } catch (error) {
    overviewCards.value = []; profitCategories.value = []; inventoryCategories.value = []; expiryCards.value = []; expiryRows.value = []
    queryMessage.value = error.message || '商品看板请求失败。'
  } finally {
    loading.value = false
    if (queuedQuery) {
      queuedQuery = false
      loadDashboard('查询')
    }
  }
}

function setProductDashboardDocumentClass(enabled) {
  if (typeof document === 'undefined') return
  document.documentElement.classList.toggle('product-dashboard-document', enabled)
}

onMounted(() => {
  setProductDashboardDocumentClass(true)
  loadScope()
  loadDashboard('查询')
  refreshTimer = window.setInterval(() => loadDashboard('刷新'), AUTO_REFRESH_INTERVAL)
})
onUnmounted(() => {
  setProductDashboardDocumentClass(false)
  if (refreshTimer !== null) window.clearInterval(refreshTimer)
  refreshTimer = null
})

const profitCategoryCards = computed(() => categoryId.value === 'all'
  ? profitCategories.value
  : profitCategories.value.filter((category) => category.id === categoryId.value))

const inventoryCategoryCards = computed(() => categoryId.value === 'all'
  ? inventoryCategories.value
  : inventoryCategories.value.filter((category) => category.id === categoryId.value))

function rowsFor(category) {
  return categoryRankType.value[category.id] === 'product' ? category.products : category.projects
}

function setRankType(categoryIdValue, type) {
  categoryRankType.value = { ...categoryRankType.value, [categoryIdValue]: type }
}

function changeScope({ storeIds, label }) {
  scopePicker.value = { ...scopePicker.value, selectedStoreIds: storeIds, label }
  queryMessage.value = `已选择：${label}。点击查询刷新数据。`
}

function selectPeriod(value) {
  period.value = value
  if (value === 'custom') {
    queryMessage.value = '请选择自定义日期范围，再点击查询应用筛选。'
    return
  }
  // 预设日期是完整筛选条件，切换后立即读取对应区间，避免只改变按钮选中态。
  loadDashboard('查询')
}

function runQuery(action = '查询') {
  loadDashboard(action)
}
</script>

<template>
  <main class="product-dashboard" aria-label="商品看板">
    <header class="product-dashboard__header"><h1>商品看板</h1></header>

    <section class="product-dashboard__filters" aria-label="商品看板查询条件">
      <div class="product-dashboard__filter-main">
        <div class="product-dashboard__filter-row">
          <span class="product-dashboard__filter-label">日期范围</span>
          <nav class="product-dashboard__periods" aria-label="日期范围">
            <button v-for="item in [['today', '今天'], ['month', '本月'], ['year', '本年'], ['custom', '自定义']]" :key="item[0]" type="button" :class="{ 'is-active': period === item[0] }" @click="selectPeriod(item[0])">{{ item[1] }}</button>
          </nav>
          <label v-if="period === 'custom'" class="product-dashboard__date">从 <input v-model="customStart" type="date" /></label>
          <label v-if="period === 'custom'" class="product-dashboard__date">至 <input v-model="customEnd" type="date" /></label>
        </div>
        <div class="product-dashboard__filter-row">
          <span class="product-dashboard__filter-label">当前权限范围</span>
          <OrganizationStoreScopePicker v-model="scopePicker.selectedStoreIds" :tree="scopePicker.tree" :allowed-store-ids="scopePicker.allowedStoreIds" :label="scopePicker.label" :loading="scopePicker.loading" @change="changeScope" />
        </div>
        <div class="product-dashboard__filter-row">
          <label class="product-dashboard__select-label">商品分类 <select v-model="categoryId"><option v-for="item in categoryOptions" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
          <label class="product-dashboard__select-label">商品类型 <select v-model="productType"><option value="all">全部</option><option value="project">项目</option><option value="product">产品</option></select></label>
        </div>
      </div>
      <div class="product-dashboard__actions">
        <button type="button" class="product-dashboard__button product-dashboard__button--primary" :disabled="loading" @click="runQuery('查询')"><Search :size="16" aria-hidden="true" />查询</button>
        <button type="button" class="product-dashboard__button" :disabled="loading" @click="runQuery('刷新')"><RefreshCw :size="16" :class="{ 'is-spinning': loading }" aria-hidden="true" />刷新</button>
      </div>
    </section>

    <p class="product-dashboard__feedback" aria-live="polite">{{ queryMessage }} <span>数据截至：{{ refreshedAt }}</span></p>

    <section class="product-dashboard__summary" aria-label="商品经营概览">
      <article v-for="card in overviewCards" :key="card.label" class="product-dashboard__summary-card" :class="`product-dashboard__summary-card--${card.tone}`">
        <div class="product-dashboard__summary-card-content">
          <div class="product-dashboard__summary-metric"><span>{{ card.label }}</span><strong>{{ card.value }}</strong></div>
          <div class="product-dashboard__summary-bars" :aria-label="`${card.label}本期同比环比柱状图`">
            <div v-for="bar in card.bars" :key="bar.label" class="product-dashboard__summary-bar-item"><b>{{ bar.amount }}</b><div class="product-dashboard__summary-bar-track"><i :style="{ height: `${bar.value}%` }"></i></div><span>{{ bar.label }}</span></div>
          </div>
        </div>
      </article>
    </section>

    <section class="product-dashboard__section product-dashboard__section--profit" aria-labelledby="profit-title">
      <header class="product-dashboard__section-header"><div><span class="product-dashboard__section-kicker"><CircleDollarSign :size="16" aria-hidden="true" />经营分析</span><h2 id="profit-title">品项毛利</h2></div><button type="button" class="product-dashboard__guide"><BookOpen :size="15" aria-hidden="true" />列名取值来源</button></header>
      <div v-if="loading" class="product-dashboard__loading">正在加载品项毛利数据...</div>
      <p v-else-if="!profitCategoryCards.length" class="product-dashboard__empty">当前商品分类暂无品项毛利数据。</p>
      <div v-else class="product-dashboard__profit-grid">
        <article v-for="category in profitCategoryCards" :key="category.id" class="profit-category-card">
          <header><div><span>商品分类</span><h3>{{ category.name }}</h3></div><span class="profit-category-card__rate">毛利率 {{ category.rate }}</span></header>
          <dl class="profit-category-card__metrics"><div><dt>分类收入</dt><dd>{{ category.income }}</dd></div><div><dt>分类成本</dt><dd>{{ category.cost }}</dd></div><div><dt>分类品项毛利</dt><dd>{{ category.profit }}</dd></div></dl>
          <div class="profit-category-card__rank-header"><strong>毛利排行 · 前5名</strong><div role="tablist" class="profit-category-card__tabs"><button type="button" :class="{ 'is-active': (categoryRankType[category.id] || 'project') === 'project' }" @click="setRankType(category.id, 'project')">项目</button><button type="button" :class="{ 'is-active': categoryRankType[category.id] === 'product' }" @click="setRankType(category.id, 'product')">产品</button></div></div>
          <div class="profit-category-card__table" role="table" :aria-label="`${category.name}毛利排行`"><div class="profit-category-card__table-head" role="row"><span>品项</span><span>收入</span><span>成本</span><span>毛利</span><span>毛利率</span></div><div v-for="(row, index) in rowsFor(category)" :key="row[0]" class="profit-category-card__rank-row" :class="`rank-row--${index + 1}`" role="row"><em>{{ index + 1 }}</em><span :title="row[0]">{{ row[0] }}</span><b>{{ row[1] }}</b><b>{{ row[2] }}</b><b>{{ row[3] }}</b><b>{{ row[4] }}</b></div></div>
        </article>
      </div>
    </section>

    <section class="product-dashboard__section product-dashboard__section--inventory" aria-labelledby="inventory-title">
      <header class="product-dashboard__section-header"><div><span class="product-dashboard__section-kicker"><Boxes :size="16" aria-hidden="true" />库存快照</span><h2 id="inventory-title">产品库存成本</h2></div><button type="button" class="product-dashboard__guide"><BookOpen :size="15" aria-hidden="true" />列名取值来源</button></header>
      <div v-if="loading" class="product-dashboard__loading">正在加载产品库存成本数据...</div>
      <p v-else-if="!inventoryCategoryCards.length" class="product-dashboard__empty">当前商品分类没有产品库存。</p>
      <div v-else class="product-dashboard__inventory-grid">
        <article v-for="category in inventoryCategoryCards" :key="category.id" class="inventory-category-card"><header><div><span>产品分类</span><h3>{{ category.name }}</h3></div><strong>{{ category.amount }}</strong></header><div class="inventory-category-card__rank-title"><Package :size="15" aria-hidden="true" />库存成本排行 · 前10名</div><div class="inventory-category-card__table"><div class="inventory-category-card__table-head"><span>品名</span><span>库存数量</span><span>库存成本</span></div><div v-for="(row, index) in category.rows" :key="row[0]" class="inventory-category-card__row" :class="`inventory-rank-row--${index + 1}`"><em>{{ index + 1 }}</em><span :title="row[0]">{{ row[0] }}</span><b>{{ row[1] }}</b><b>{{ row[2] }}</b></div></div></article>
      </div>
    </section>

    <section class="product-dashboard__section product-dashboard__section--expiry" aria-labelledby="expiry-title">
      <header class="product-dashboard__section-header"><div><span class="product-dashboard__section-kicker"><TriangleAlert :size="16" aria-hidden="true" />库存预警</span><h2 id="expiry-title">临期产品预警</h2></div><button type="button" class="product-dashboard__guide"><BookOpen :size="15" aria-hidden="true" />列名取值来源</button></header>
      <div v-if="loading" class="product-dashboard__loading">正在加载临期产品预警数据...</div>
      <template v-else><div class="product-dashboard__expiry-summary"><article v-for="item in expiryCards" :key="item.key" :class="`expiry-summary-card expiry-summary-card--${item.tone}`"><span>{{ item.label }}</span><strong>{{ item.value }}</strong><small>当前库存数量</small></article></div><p v-if="!expiryRows.length" class="product-dashboard__empty">当前筛选范围没有临期或到期日未知的产品批次。</p><div v-else class="product-dashboard__table-wrap"><table><thead><tr><th>品名</th><th>商品分类</th><th>批次号</th><th>当前库存数量</th><th>到期日</th><th>剩余天数</th><th>状态</th><th>库存成本</th></tr></thead><tbody><tr v-for="row in expiryRows" :key="row[2]"><td>{{ row[0] }}</td><td>{{ row[1] }}</td><td>{{ row[2] }}</td><td>{{ row[3] }}</td><td>{{ row[4] }}</td><td>{{ row[5] }}</td><td><span class="expiry-status" :class="{ 'expiry-status--expired': row[6] === '已过期', 'expiry-status--urgent': row[6] === '30天内临期' }">{{ row[6] }}</span></td><td>{{ row[7] }}</td></tr></tbody></table></div></template>
    </section>
  </main>
</template>

<style scoped>
:global(html.product-dashboard-document),:global(html.product-dashboard-document body),:global(html.product-dashboard-document #app){min-width:0;width:100%;max-width:100%;overflow-x:hidden}
.product-dashboard{height:100vh;min-height:100vh;overflow:auto;padding:12px;background:#f4f6f8;color:#26364a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.product-dashboard *{box-sizing:border-box}.product-dashboard h1,.product-dashboard h2,.product-dashboard h3,.product-dashboard p,.product-dashboard dl,.product-dashboard dt,.product-dashboard dd{margin:0}.product-dashboard__header,.product-dashboard__filters,.product-dashboard__summary,.product-dashboard__section,.product-dashboard__feedback{width:100%;max-width:1600px;margin-right:auto;margin-left:auto}.product-dashboard__header{display:flex;align-items:center;min-height:40px;margin-bottom:8px}.product-dashboard h1{color:#1f2d3d;font-size:23px;line-height:1.3}.product-dashboard__filters{display:flex;align-items:center;justify-content:space-between;gap:16px;border:1px solid #dde4ed;border-radius:7px;padding:12px 14px;background:#fff;box-shadow:0 3px 9px rgba(26,47,71,.04)}.product-dashboard__filter-main{display:flex;flex-wrap:wrap;align-items:center;gap:9px 20px}.product-dashboard__filter-row{display:flex;align-items:center;gap:8px;min-height:34px}.product-dashboard__filter-label{color:#4c6076;font-size:13px;font-weight:600}.product-dashboard__periods{display:flex;overflow:hidden;border:1px solid #d8e0ea;border-radius:5px}.product-dashboard__periods button{min-width:48px;min-height:32px;border:0;border-right:1px solid #e4e9ef;background:#fff;color:#66778b;font:inherit;font-size:12px;cursor:pointer}.product-dashboard__periods button:last-child{border-right:0}.product-dashboard__periods button.is-active{background:#e9f4ff;color:#1769aa;font-weight:700}.product-dashboard__date,.product-dashboard__select-label{display:flex;align-items:center;gap:6px;color:#66778b;font-size:12px}.product-dashboard__date input,.product-dashboard__select-label select{min-height:32px;border:1px solid #d8e0ea;border-radius:5px;padding:4px 8px;background:#fff;color:#405166;font:inherit;font-size:13px}.product-dashboard__actions{display:flex;flex:none;gap:8px}.product-dashboard__button{display:inline-flex;align-items:center;justify-content:center;gap:5px;min-height:34px;border:1px solid #d6e0eb;border-radius:5px;padding:6px 11px;background:#fff;color:#426078;font:inherit;font-size:13px;cursor:pointer}.product-dashboard__button--primary{border-color:#1769aa;background:#1769aa;color:#fff}.product-dashboard__button:disabled{cursor:wait;opacity:.65}.is-spinning{animation:product-dashboard-spin .75s linear infinite}@keyframes product-dashboard-spin{to{transform:rotate(360deg)}}.product-dashboard__feedback{display:flex;justify-content:space-between;gap:12px;padding:8px 2px 12px;color:#718096;font-size:12px}.product-dashboard__feedback span{white-space:nowrap}.product-dashboard__summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:22px}.product-dashboard__summary-card{min-height:145px;border:1px solid #dde5ed;border-top:3px solid #1f5f8b;border-radius:7px;padding:15px 16px;background:#fff}.product-dashboard__summary-card--consumption{border-top-color:#b7791f}.product-dashboard__summary-card--profit{border-top-color:#357a72}.product-dashboard__summary-card>span{color:#53667b;font-size:14px;font-weight:700}.product-dashboard__summary-card>strong{display:block;margin:9px 0 13px;color:#1f5f8b;font-size:24px;line-height:1}.product-dashboard__summary-card--consumption>strong{color:#b7791f}.product-dashboard__summary-card--profit>strong{color:#357a72}.product-dashboard__summary-card dl{display:grid;grid-template-columns:1fr 1fr;gap:10px}.product-dashboard__summary-card dt{color:#7d8c9d;font-size:11px}.product-dashboard__summary-card dd{margin-top:3px;color:#526b80;font-size:13px;font-weight:600}.product-dashboard__section{margin-bottom:26px}.product-dashboard__section-header{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:10px}.product-dashboard__section-kicker{display:inline-flex;align-items:center;gap:5px;color:#718398;font-size:12px}.product-dashboard__section-kicker svg{color:#1f5f8b}.product-dashboard__section--inventory .product-dashboard__section-kicker svg{color:#357a72}.product-dashboard__section--expiry .product-dashboard__section-kicker svg{color:#b7791f}.product-dashboard h2{margin-top:2px;color:#26394d;font-size:18px;line-height:1.3}.product-dashboard__guide{display:inline-flex;align-items:center;gap:5px;border:0;background:transparent;color:#1769aa;font:inherit;font-size:12px;cursor:pointer}.product-dashboard__loading,.product-dashboard__empty{min-height:96px;border:1px solid #dfe7ee;border-radius:7px;padding:32px 18px;background:#fff;color:#7d8d9e;font-size:13px;text-align:center}.product-dashboard__profit-grid,.product-dashboard__inventory-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.profit-category-card,.inventory-category-card{overflow:hidden;border:1px solid #dce5ed;border-radius:7px;background:#fff}.profit-category-card{border-top:3px solid #1f5f8b}.profit-category-card>header,.inventory-category-card>header{display:flex;align-items:start;justify-content:space-between;gap:12px;padding:15px 15px 12px}.profit-category-card>header span,.inventory-category-card>header span{color:#7b8b9d;font-size:11px}.profit-category-card h3,.inventory-category-card h3{margin-top:3px;color:#2d4258;font-size:16px}.profit-category-card__rate{border-radius:4px;padding:4px 6px;background:#edf7f5;color:#357a72!important;font-size:11px!important;font-weight:700}.profit-category-card__metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;padding:0 15px 13px}.profit-category-card__metrics div{min-width:0}.profit-category-card__metrics dt{color:#8090a1;font-size:11px}.profit-category-card__metrics dd{overflow:hidden;margin-top:4px;color:#1f5f8b;text-overflow:ellipsis;white-space:nowrap;font-size:14px;font-weight:700}.profit-category-card__metrics div:nth-child(2) dd{color:#6f7f8f}.profit-category-card__metrics div:nth-child(3) dd{color:#357a72}.profit-category-card__rank-header{display:flex;align-items:center;justify-content:space-between;gap:7px;border-top:1px solid #edf1f5;padding:9px 12px}.profit-category-card__rank-header strong{color:#40576b;font-size:12px}.profit-category-card__tabs{display:flex;border:1px solid #dde6ee;border-radius:4px;padding:2px}.profit-category-card__tabs button{border:0;border-radius:3px;padding:3px 7px;background:transparent;color:#718198;font:inherit;font-size:11px;cursor:pointer}.profit-category-card__tabs button.is-active{background:#e9f4ff;color:#1769aa;font-weight:700}.profit-category-card__table{border-top:1px solid #edf1f5}.profit-category-card__table-head,.profit-category-card__rank-row{display:grid;grid-template-columns:minmax(88px,1.8fr) minmax(52px,.9fr) minmax(52px,.9fr) minmax(52px,.9fr) minmax(40px,.65fr);align-items:center;gap:5px;padding:8px 10px 8px 35px}.profit-category-card__table-head{background:#f8fafc;color:#8190a0;font-size:10px}.profit-category-card__rank-row{position:relative;min-height:34px;border-top:1px solid #f0f3f6;color:#53667a;font-size:11px}.profit-category-card__rank-row>em{position:absolute;left:10px;display:grid;width:18px;height:18px;place-items:center;border-radius:50%;background:#edf4fa;color:#4d7699;font-size:10px;font-style:normal}.profit-category-card__rank-row>span{overflow:hidden;color:#40576b;text-overflow:ellipsis;white-space:nowrap;font-weight:600}.profit-category-card__rank-row>b{overflow:hidden;color:#1f5f8b;text-align:right;text-overflow:ellipsis;white-space:nowrap;font-size:10px}.profit-category-card__rank-row>b:nth-last-child(2){color:#357a72}.profit-category-card__rank-row>b:last-child{color:#63778d}.profit-category-card__rank-row.rank-row--1{background:#fff7df}.profit-category-card__rank-row.rank-row--1>em{background:#d49b2c;color:#fff}.profit-category-card__rank-row.rank-row--2>em{background:#b68162;color:#fff}.profit-category-card__rank-row.rank-row--3>em{background:#d6c978;color:#5f562d}.profit-category-card__rank-row.rank-row--4>em,.profit-category-card__rank-row.rank-row--5>em{background:#a9bbc5;color:#fff}.inventory-category-card{border-top:3px solid #357a72}.inventory-category-card>header{border-bottom:1px solid #edf1f5}.inventory-category-card>header>strong{color:#1f5f8b;font-size:18px;white-space:nowrap}.inventory-category-card__rank-title{display:flex;align-items:center;gap:5px;padding:10px 14px;color:#40576b;font-size:12px;font-weight:700}.inventory-category-card__rank-title svg{color:#357a72}.inventory-category-card__table{border-top:1px solid #edf1f5}.inventory-category-card__table-head,.inventory-category-card__row{display:grid;grid-template-columns:minmax(0,1fr) 68px 78px;align-items:center;gap:8px;padding:8px 12px 8px 37px}.inventory-category-card__table-head{background:#f8fafc;color:#8190a0;font-size:10px}.inventory-category-card__row{position:relative;min-height:34px;border-top:1px solid #f0f3f6;color:#53667a;font-size:11px}.inventory-category-card__row em{position:absolute;left:12px;color:#8a9bad;font-size:10px;font-style:normal}.inventory-category-card__row span{overflow:hidden;color:#40576b;text-overflow:ellipsis;white-space:nowrap;font-weight:600}.inventory-category-card__row b{color:#2b4358;text-align:right;font-size:10px}.inventory-category-card__row b:last-child{color:#1f5f8b}.product-dashboard__expiry-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:12px}.expiry-summary-card{min-height:89px;border:1px solid #dce5ed;border-top:3px solid #8a9bab;border-radius:6px;padding:11px 12px;background:#fff}.expiry-summary-card span{color:#61758a;font-size:12px}.expiry-summary-card strong{display:block;margin:5px 0 2px;color:#2b4358;font-size:21px}.expiry-summary-card small{color:#8a99a8;font-size:10px}.expiry-summary-card--expired{border-top-color:#b95a4c}.expiry-summary-card--expired strong{color:#b95a4c}.expiry-summary-card--urgent{border-top-color:#c88727}.expiry-summary-card--urgent strong{color:#b7791f}.expiry-summary-card--warm{border-top-color:#d4a34b}.expiry-summary-card--watch{border-top-color:#5c8fba}.expiry-summary-card--normal{border-top-color:#357a72}.product-dashboard__table-wrap{overflow:auto;border:1px solid #dce5ed;border-radius:7px;background:#fff}.product-dashboard table{width:100%;min-width:900px;border-collapse:collapse}.product-dashboard th{padding:11px 14px;background:#f7f9fb;color:#718397;text-align:left;font-size:11px;font-weight:700}.product-dashboard td{border-top:1px solid #edf1f5;padding:11px 14px;color:#50667a;font-size:12px;white-space:nowrap}.product-dashboard td:first-child{color:#2e475e;font-weight:600}.product-dashboard td:last-child{color:#1f5f8b;font-weight:700}.expiry-status{display:inline-flex;border-radius:4px;padding:3px 6px;background:#edf4fa;color:#527591;font-size:11px}.expiry-status--expired{background:#fff0ed;color:#b45431}.expiry-status--urgent{background:#fff8e6;color:#a66c12}@media(max-width:1180px){.product-dashboard__filters{align-items:flex-start;flex-direction:column}.product-dashboard__actions{align-self:flex-end}.product-dashboard__profit-grid,.product-dashboard__inventory-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.product-dashboard__expiry-summary{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.product-dashboard{padding:12px}.product-dashboard__filter-main{display:grid;width:100%;gap:10px}.product-dashboard__filter-row{flex-wrap:wrap}.product-dashboard__actions{align-self:stretch}.product-dashboard__button{flex:1}.product-dashboard__feedback{align-items:flex-start;flex-direction:column}.product-dashboard__summary,.product-dashboard__profit-grid,.product-dashboard__inventory-grid{grid-template-columns:1fr}.product-dashboard__expiry-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.profit-category-card__table-head,.profit-category-card__rank-row{grid-template-columns:minmax(84px,1.7fr) minmax(48px,.8fr) minmax(48px,.8fr) minmax(48px,.8fr) minmax(36px,.6fr);gap:3px;padding-right:7px;padding-left:32px}.profit-category-card__metrics{gap:5px;padding-right:11px;padding-left:11px}.profit-category-card__metrics dd{font-size:12px}.product-dashboard__section-header{align-items:flex-end}.product-dashboard__guide{font-size:11px}}
/* Product-dashboard prototype additions: trend sparklines and consistent rank colors. */
.product-dashboard__summary-card-content{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);align-items:center;gap:14px;height:100%}
.product-dashboard__summary-metric{min-width:0}
.product-dashboard__summary-metric>span{display:block;color:#53667b;font-size:14px;font-weight:700}
.product-dashboard__summary-metric>strong{display:block;margin-top:9px;overflow:hidden;color:#1f5f8b;text-overflow:ellipsis;white-space:nowrap;font-size:24px;line-height:1;font-weight:700}
.product-dashboard__summary-card--consumption .product-dashboard__summary-metric>strong{color:#b7791f}
.product-dashboard__summary-card--profit .product-dashboard__summary-metric>strong{color:#357a72}
.product-dashboard__summary-bars{display:flex;align-items:flex-end;gap:12px;height:86px;margin:0;padding:0 2px;border-bottom:1px solid #e4eaf0}
.product-dashboard__summary-bar-item{display:flex;flex:1;align-items:center;flex-direction:column;gap:3px;min-width:0;height:100%;color:#8291a1;font-size:10px}
.product-dashboard__summary-bar-item>b{overflow:hidden;max-width:100%;color:#526b80;text-overflow:ellipsis;white-space:nowrap;font-size:10px;font-weight:700}
.product-dashboard__summary-bar-track{display:flex;align-items:flex-end;justify-content:center;width:18px;flex:1;min-height:30px}
.product-dashboard__summary-bar-track i{display:block;width:100%;min-height:3px;border-radius:3px 3px 0 0;background:#4d86b3}
.product-dashboard__summary-bar-item:nth-child(1) .product-dashboard__summary-bar-track i{background:#1f5f8b}
.product-dashboard__summary-bar-item:nth-child(2) .product-dashboard__summary-bar-track i{background:#c4872a}
.product-dashboard__summary-bar-item:nth-child(3) .product-dashboard__summary-bar-track i{background:#429187}
.product-dashboard__summary-card>strong{margin-bottom:8px}
.product-dashboard__summary-trends{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 9px}
.product-dashboard__summary-trend{display:flex;align-items:center;gap:5px;min-width:0;color:#8291a1;font-size:10px}
.product-dashboard__summary-trend svg{display:block;width:76px;height:22px;min-width:0;overflow:visible}
.product-dashboard__summary-trend polyline{fill:none;stroke:#4d86b3;stroke-linecap:round;stroke-linejoin:round;stroke-width:2}
.product-dashboard__summary-card--consumption .product-dashboard__summary-trend polyline{stroke:#c4872a}
.product-dashboard__summary-card--profit .product-dashboard__summary-trend polyline{stroke:#429187}
.product-dashboard__summary-card dl{grid-template-columns:1fr 1fr;gap:7px 10px}
.product-dashboard__summary-card dd{font-size:12px}
.inventory-category-card__table{max-height:380px;overflow-y:auto}
.inventory-category-card .inventory-rank-row--1{background:#fff7df}
.inventory-category-card .inventory-rank-row--1>em{background:#d49b2c;color:#fff}
.inventory-category-card .inventory-rank-row--2>em{background:#b68162;color:#fff}
.inventory-category-card .inventory-rank-row--3>em{background:#d6c978;color:#5f562d}
.inventory-category-card .inventory-rank-row--4>em,.inventory-category-card .inventory-rank-row--5>em{background:#a9bbc5;color:#fff}
</style>
