<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  AlertTriangle, ArrowDownToLine, ArrowLeftRight, ArrowUpFromLine, Barcode,
  Bell, Boxes, Building2, ChartNoAxesCombined, ChevronRight, ClipboardCheck,
  Clock3, FileClock, FileSpreadsheet, FlaskConical, LayoutDashboard, Menu,
  Package2, PanelLeftClose, PanelLeftOpen, Plus, QrCode, Search,
  SlidersHorizontal, Store, Warehouse, X
} from '@lucide/vue'
import InventoryBusinessModal from './components/InventoryBusinessModal.vue'
import { inventoryApi, platformInventoryApi } from './services/inventoryApi'
import { UnifiedQueryToolbar, useUnifiedQueryPage } from '@mohe/unified-query-vue3'
import '@mohe/unified-query-vue3/styles.css'

const initialQuery = typeof window === 'undefined' ? new URLSearchParams() : new URLSearchParams(window.location.search)
const mode = ref(initialQuery.get('source') === 'platform' ? 'platform' : 'store')
const platformLocationId = ref(0)
const platformLocations = ref([])
const storeLocations = ref([])
const active = ref('overview')
const activeStatisticsTab = ref('inbound')
const analysisCutoffDate = ref(new Date().toISOString().slice(0, 10))
const statisticsFromDate = ref(`${new Date().toISOString().slice(0, 8)}01`)
const statisticsToDate = ref(new Date().toISOString().slice(0, 10))
const sidebarCollapsed = ref(false)
const sidebarOpen = ref(false)
const keyword = ref('')
const drawer = ref('')
const statusFocus = ref('')
const remoteRows = ref([])
const remoteTotal = ref(0)
const listLoading = ref(false)
const listError = ref('')
const batchAnalysis = ref({ list: [], expiry_buckets: [], age_buckets: [], query_cutoff_date: '' })
const analysisLoading = ref(false)
const analysisError = ref('')
const movementStatistics = ref({ list: [], count: 0, from: '', to: '' })
const movementStatisticsLoading = ref(false)
const movementStatisticsError = ref('')
const stockExecutedQuery = ref({})
const isStoreEntry = typeof window !== 'undefined'
  && new URLSearchParams(window.location.search).get('source') === 'store'

const stockQueryBaseFields = [
  ['organization_name', '组织', 'text'], ['location_name', '仓库', 'text'], ['store_name', '门店', 'text'],
  ['product_name', '商品名称', 'text'], ['sku_name', '商品规格', 'text'], ['product_code', '商品编码', 'text'],
  ['barcode', '商品条码', 'text'], ['brand_name', '品牌', 'text'], ['category_name', '商品类别', 'text'],
  ['stock_unit', '库存单位', 'text'], ['batch_no', '批次号', 'text'], ['quality_status', '库存状态', 'text'],
  ['received_date', '正式入库日期', 'date'], ['manufactured_date', '生产日期', 'date'], ['expire_date', '到期日', 'date'],
  ['batch_balance_quantity', '批次结存数量', 'decimal'], ['batch_unit_cost', '批次单位成本', 'amount'],
  ['source_order_no', '来源入库单', 'text'], ['data_quality', '数据质量', 'text']
].map(([key, label, type]) => ({ key, label, type, defaultVisible: true }))

async function stockQueryRequestAction(action, payload = {}) {
  const client = mode.value === 'platform' ? platformInventoryApi : inventoryApi
  if (!client) throw new Error('库存查询服务尚未初始化。')
  // 平台选择仓库只是一项受服务端校验的范围收窄参数；门店端永远不发送它。
  const warehouseScope = mode.value === 'platform' && Number(platformLocationId.value) > 0
    ? { warehouseId: Number(platformLocationId.value) }
    : {}
  if (action === 'query-unified-query-capabilities') {
    const data = await client.unifiedQueryCapabilities(warehouseScope)
    return { result: { status: 'success' }, data }
  }
  if (action === 'query-unified-query-export-task') {
    const data = await client.unifiedQueryExportTask(payload.taskId)
    return { result: { status: 'success' }, data }
  }
  return client.unifiedQueryCommand({ action, ...payload, ...warehouseScope })
}

const stockUnifiedQuery = useUnifiedQueryPage({
  pageCode: 'inventory_batch_stock',
  pageName: '库存查询',
  baseFields: stockQueryBaseFields,
  requestAction: stockQueryRequestAction
})
const stockQueryCapability = stockUnifiedQuery.capability
const stockQueryFields = stockUnifiedQuery.fields
const stockQuerySettings = computed(() => stockQueryCapability.value?.querySettings || {})

async function saveStockQuerySettings(settings, options = {}) {
  const result = await stockQueryRequestAction('save-unified-query-settings', {
    pageCode: 'inventory_batch_stock',
    settings,
    ...(options.idempotencyKey ? { idempotencyKey: options.idempotencyKey } : {})
  })
  if (result?.result?.status === 'success') await stockUnifiedQuery.load({ silent: true })
  return result
}

const menuItems = [
  { key: 'overview', label: '库存首页', icon: LayoutDashboard },
  { key: 'inbound', label: '入库管理', icon: ArrowDownToLine, action: '新建入库' },
  { key: 'outbound', label: '出库管理', icon: ArrowUpFromLine, action: '新建出库' },
  { key: 'stock', label: '库存查询', icon: Boxes },
  { key: 'count', label: '库存盘点', icon: ClipboardCheck, action: '新建盘点单' },
  { key: 'movement', label: '出入库记录', icon: FileSpreadsheet },
  { key: 'statistics', label: '库存统计', icon: ChartNoAxesCombined },
  { key: 'request', label: '请货管理', icon: FileClock, action: '新建请货' },
  { key: 'transfer', label: '调拨管理', icon: ArrowLeftRight, action: '新建调拨' },
  { key: 'usage', label: '院装管理', icon: Warehouse },
  { key: 'import', label: '导入记录', icon: FileSpreadsheet },
  { key: 'warehouse', label: '仓库设置', icon: Building2, platformOnly: true },
  { key: 'recipe', label: '项目配方', icon: FlaskConical, action: '新建配方', platformOnly: true }
]

const pages = {
  inbound: {
    title: '入库管理',
    crumb: '库存管理 / 入库管理',
    filters: ['入库类型', '入库单号', '入库日期', '创建时间'],
    action: '新建入库',
    columns: ['入库单号', '入库类型', '入库仓/门店', '供应方', '入库数量', '入库金额', '状态']
  },
  outbound: {
    title: '出库管理',
    crumb: '库存管理 / 出库管理',
    filters: ['出库类型', '出库单号', '出库日期', '创建时间'],
    action: '新建出库',
    columns: ['出库单号', '出库类型', '出库仓/门店', '去向/原因', '出库数量', '成本金额', '状态']
  },
  stock: {
    title: '库存查询',
    crumb: '库存管理 / 库存查询',
    filters: ['商品/条码', '库存区间', '院装产品', '隐藏零库存'],
    columns: ['商品', '规格', '库存仓库', '可用库存', '库存平均单价', '库存金额']
  },
  movement: {
    title: '出入库记录',
    crumb: '库存管理 / 出入库记录',
    filters: ['业务类型', '商品/条码', '业务日期', '单据号'],
    columns: ['业务时间', '业务单号', '业务类型', '商品', '数量变化', '库存主体', '操作人']
  },
  count: {
    title: '库存盘点',
    crumb: '库存管理 / 库存盘点',
    filters: ['盘点状态', '盘点单号', '盘点日期', '创建时间'],
    action: '新建盘点单',
    columns: ['盘点单号', '盘点主体', '盘点范围', '差异项', '盈亏金额', '状态', '盘点人']
  },
  request: {
    title: '请货管理',
    crumb: '库存管理 / 请货管理',
    filters: ['请货状态', '请货单号', '请货方', '申请时间'],
    action: '新建请货',
    columns: ['请货单号', '请货方', '供货方', '商品项数', '预计金额', '状态', '申请时间']
  },
  transfer: {
    title: '调拨管理',
    crumb: '库存管理 / 调拨管理',
    filters: ['调拨状态', '调拨单号', '调出方', '调拨时间'],
    action: '新建调拨',
    columns: ['调拨单号', '调出方', '调入方', '商品项数', '调拨金额', '状态', '发起时间']
  },
  recipe: {
    title: '项目配方',
    crumb: '库存管理 / 项目配方',
    filters: ['项目名称', '配方状态'],
    columns: ['项目名称', '耗材种类', '配方摘要', '单次预计成本', '不足策略', '状态', '版本']
  },
  warehouse: {
    title: '仓库设置',
    crumb: '库存管理 / 仓库设置',
    filters: ['仓库类型', '所属组织', '仓库状态'],
    action: '新建仓库',
    columns: ['仓库编码', '仓库名称', '仓库类型', '所属门店/组织', '默认仓库', '状态']
  },
  usage: {
    title: '院装管理',
    crumb: '库存管理 / 院装管理',
    filters: ['领退类型', '核销单号', '项目名称', '业务日期'],
    action: '院装耗材领用',
    columns: ['业务单号', '耗材', '关联项目', '数量', '实际耗材金额', '类型', '状态']
  },
  import: {
    title: '导入记录',
    crumb: '库存管理 / 导入记录',
    filters: ['业务类型', '导入状态', '导入时间'],
    columns: ['文件名称', '业务类型', '库存主体', '数据量', '结果', '状态', '导入时间']
  }
}

const visibleMenus = computed(() => menuItems.filter((item) => {
  if (item.platformOnly) return mode.value === 'platform'
  return true
}))
const currentPage = computed(() => pages[active.value] || null)
const scopeName = computed(() => {
  if (mode.value === 'store') {
    const current = storeLocations.value.find((item) => Number(item.is_default) === 1) || storeLocations.value[0]
    return text(current?.store_name_snapshot || current?.store_name || current?.location_name, '当前门店')
  }
  const current = platformLocations.value.find((item) => Number(item.id) === Number(platformLocationId.value))
  return current ? current.location_name : '全部授权仓库'
})
const defaultLocationName = computed(() => {
  const current = storeLocations.value.find((item) => Number(item.is_default) === 1) || storeLocations.value[0]
  return text(current?.location_name, scopeName.value)
})
const currentRows = computed(() => {
  const rows = remoteRows.value
  const query = keyword.value.trim()
  return query ? rows.filter((row) => row.join(' ').includes(query)) : rows
})
const analysisBuckets = computed(() => activeStatisticsTab.value === 'expiry'
  ? batchAnalysis.value.expiry_buckets
  : batchAnalysis.value.age_buckets)
const analysisRows = computed(() => batchAnalysis.value.list.map((row) => activeStatisticsTab.value === 'expiry'
  ? [text(row.product_name), text(row.sku_name), text(row.batch_no), text(row.expire_date), text(row.remaining_shelf_life_days), text(row.remaining_shelf_life_band), row.inventory_amount === null ? '-' : money(row.inventory_amount)]
  : [text(row.product_name), text(row.sku_name), text(row.batch_no), text(row.received_date), text(row.inventory_age_days), text(row.inventory_age_band), row.inventory_amount === null ? '-' : money(row.inventory_amount)]))
const movementStatisticRows = computed(() => movementStatistics.value.list.map((row) => [
  text(row.business_date), text(row.source_type_name), text(row.product_name), text(row.sku_name), text(row.document_count, '0'),
  text(row.movement_count, '0'), `${text(row.quantity, '0')} ${text(row.stock_unit, '')}`.trim(), centsMoney(row.cost_amount_cents)
]))
const drawerTitle = computed(() => {
  if (!drawer.value) return ''
  if (drawer.value === 'import') return '库存导入'
  if (drawer.value.endsWith('-detail')) return `${currentPage.value?.title || ''}详情`
  return currentPage.value?.action || ''
})

const dashboardCards = [
  { key: 'stock', label: '库存总金额', value: '--', note: '按仓库与实际批次成本汇总', tone: 'blue', target: 'stock' },
  { key: 'inbound', label: '待确认入库', value: '--', note: '从入库单据状态读取', tone: 'green', target: 'inbound', focus: '待确认' },
  { key: 'count', label: '盘点差异待处理', value: '--', note: '从盘点单据状态读取', tone: 'orange', target: 'count', focus: '盘点中' },
  { key: 'statistics', label: '90天内临期', value: '--', note: '按批次到期日和查询截止日计算', tone: 'red', target: 'statistics', tab: 'expiry' }
]

const dashboardTasks = [
  { title: '调拨待收货', note: '从调拨单状态读取', count: '—', icon: ArrowLeftRight, tone: 'blue', target: 'transfer', focus: '待收货' },
  { title: '盘点差异待确认', note: '从盘点单与差异事实读取', count: '—', icon: ClipboardCheck, tone: 'orange', target: 'count', focus: '盘点中' },
  { title: '临期批次待处置', note: '从批次到期日和截止日读取', count: '—', icon: AlertTriangle, tone: 'red', target: 'statistics', tab: 'expiry' },
  { title: '请货申请待处理', note: '从请货单状态读取', count: '—', icon: FileClock, tone: 'green', target: 'request', focus: '待处理' }
]

const statisticsTabs = [
  { key: 'inbound', label: '入库统计' },
  { key: 'outbound', label: '出库统计' },
  { key: 'expiry', label: '批次与临期' },
  { key: 'age', label: '产品库龄' },
  { key: 'margin', label: '品项毛利' },
  { key: 'deal', label: '品项成交' }
]

function selectPage(key, options = {}) {
  active.value = key
  statusFocus.value = options.focus || ''
  if (options.tab) activeStatisticsTab.value = options.tab
  sidebarOpen.value = false
  drawer.value = ''
  if (key === 'statistics') loadBatchAnalysis()
  else if (key !== 'overview' && key !== 'import') {
    if (key === 'stock') stockUnifiedQuery.load()
    loadCurrentList()
  }
}

function selectStatisticsTab(key) {
  activeStatisticsTab.value = key
  if (key === 'expiry' || key === 'age') loadBatchAnalysis()
  else if (key === 'inbound' || key === 'outbound') loadMovementStatistics()
}

function openDrawer(kind = active.value) {
  drawer.value = kind
}

function goDashboardTarget(item) {
  selectPage(item.target, { focus: item.focus, tab: item.tab })
}

function applyRequestedPage() {
  if (typeof window === 'undefined') return
  const requestedPage = new URLSearchParams(window.location.search).get('page')
  if (requestedPage && Object.prototype.hasOwnProperty.call(pages, requestedPage)) {
    active.value = requestedPage
  }
}

function text(value, fallback = '-') {
  if (value === null || value === undefined || value === '') return fallback
  return String(value)
}

function money(value) {
  if (value === null || value === undefined || value === '') return '-'
  const number = Number(value)
  return Number.isFinite(number) ? `¥${number.toFixed(2)}` : '-'
}

function centsMoney(value) {
  if (value === null || value === undefined || value === '') return '-'
  const cents = Number(value)
  return Number.isFinite(cents) ? money(cents / 100) : '-'
}

function mapApiRow(page, row) {
  switch (page) {
    case 'inbound': return [text(row.order_sn), text(row.order_type_name), text(row.location_name), text(row.product_summary), `${text(row.detail_count, '0')} 项`, centsMoney(row.cost_amount_cents), text(row.status_name || '已完成')]
    case 'outbound': return [text(row.order_sn), text(row.order_type_name), text(row.location_name), text(row.product_summary), `${text(row.detail_count, '0')} 项`, centsMoney(row.cost_amount_cents), text(row.status_name || '已完成')]
    case 'stock': return [text(row.product_name), text(row.sku_name), text(row.location_name), text(row.batch_balance_quantity), row.batch_unit_cost === null ? '-' : money(row.batch_unit_cost), row.inventory_amount === null ? '-' : money(row.inventory_amount)]
    case 'movement': return [text(row.business_date), text(row.order_sn), text(row.movement_type_name), `${text(row.product_name)} / ${text(row.sku_name)}`, text(row.quantity_display), text(row.store_name || row.store_name_snapshot || row.location_name || scopeName.value), text(row.operator_name || row.admin_name || row.staff_name, '—')]
    case 'count': return [text(row.order_sn), text(row.store_name_label || row.store_name || row.store_name_snapshot || row.location_name || scopeName.value), text(row.count_type || '全盘'), text(row.detail_count), money(row.change_amount), text(row.status_name), text(row.admin_name)]
    case 'request': return [text(row.order_sn), text(row.request_party_name || row.request_store_name), text(row.supply_party_name || row.supply_store_name), text(row.detail_count), centsMoney(row.estimated_amount_cents), text(row.status_name), text(row.request_date || row.add_time)]
    case 'transfer': return [text(row.order_sn), text(row.from_party_name || row.from_store_name), text(row.to_party_name || row.to_store_name), text(row.detail_count), centsMoney(row.transfer_amount_cents), text(row.status_name), text(row.transfer_date || row.add_time)]
    case 'usage': return [text(row.usage_no), '耗材明细见单据', text(row.project_name_snapshot), '-', '-', text(row.operation_type === 'RETURN' ? '退回' : '领用'), text(row.document_status || '已完成')]
    case 'recipe': return [text(row.project_name), text(row.consumable_count), '权威配方明细', '-', '由批次成本计算', text(row.status_name), text(row.version)]
    case 'warehouse': return [text(row.location_code), text(row.location_name), text(row.location_type), text(row.store_name_snapshot || row.organization_name_snapshot), Number(row.is_default) === 1 ? '是' : '否', text(row.location_status || '启用')]
    default: return []
  }
}

async function loadCurrentList(unifiedQuery = null) {
  if (active.value === 'overview' || active.value === 'statistics' || active.value === 'import') return
  listLoading.value = true
  listError.value = ''
  try {
    if (mode.value === 'platform' && !['stock', 'recipe', 'warehouse'].includes(active.value)) {
      throw new Error('平台端该页面将在门店端全部验收后按同一批次事实接口接入；当前不会回退到旧库存台账。')
    }
    const client = mode.value === 'platform' ? platformInventoryApi : inventoryApi
    const query = unifiedQuery && typeof unifiedQuery === 'object'
      ? { ...unifiedQuery }
      : {
          keyword: keyword.value.trim()
        }
    if (mode.value === 'platform' && active.value === 'stock' && Number(platformLocationId.value) > 0) {
      query.warehouseId = Number(platformLocationId.value)
    }
    const response = active.value === 'stock'
      ? await client.unifiedBatchStock(query)
      : await client.list(active.value, query)
    const list = Array.isArray(response?.records) ? response.records : (Array.isArray(response?.list) ? response.list : (Array.isArray(response?.items) ? response.items : (Array.isArray(response) ? response : [])))
    remoteRows.value = list.map((row) => mapApiRow(active.value, row))
    remoteTotal.value = Number(response?.total ?? response?.count ?? list.length)
    if (active.value === 'stock' && unifiedQuery && typeof unifiedQuery === 'object') {
      stockExecutedQuery.value = JSON.parse(JSON.stringify(unifiedQuery))
    }
  } catch (error) {
    remoteRows.value = []
    remoteTotal.value = 0
    listError.value = error instanceof Error ? error.message : '库存数据加载失败。'
  } finally {
    listLoading.value = false
  }
}

async function queryBatchStock(query) {
  await loadCurrentList(query)
}

async function loadPlatformLocations() {
  if (mode.value !== 'platform') return
  try {
    const response = await platformInventoryApi.list('platformLocations')
    platformLocations.value = Array.isArray(response?.list) ? response.list : []
    if (platformLocationId.value > 0 && !platformLocations.value.some((item) => Number(item.id) === Number(platformLocationId.value))) platformLocationId.value = 0
  } catch (error) {
    platformLocations.value = []
    listError.value = error instanceof Error ? error.message : '平台仓库范围读取失败。'
  }
}

async function loadStoreSessionContext() {
  if (mode.value !== 'store') return
  try {
    const response = await inventoryApi.list('storeLocations')
    storeLocations.value = Array.isArray(response?.list) ? response.list : []
  } catch (error) {
    storeLocations.value = []
    listError.value = error instanceof Error ? error.message : '当前门店库存范围读取失败。'
  }
}

function onPlatformLocationChanged() {
  if (mode.value === 'platform' && active.value === 'stock') {
    stockUnifiedQuery.reset()
    stockUnifiedQuery.load()
    loadCurrentList()
  }
}

async function loadBatchAnalysis() {
  if (!['expiry', 'age'].includes(activeStatisticsTab.value)) return
  analysisLoading.value = true
  analysisError.value = ''
  try {
    const response = await inventoryApi.batchAnalysis({ query_cutoff_date: analysisCutoffDate.value })
    batchAnalysis.value = {
      list: Array.isArray(response?.list) ? response.list : [],
      expiry_buckets: Array.isArray(response?.expiry_buckets) ? response.expiry_buckets : [],
      age_buckets: Array.isArray(response?.age_buckets) ? response.age_buckets : [],
      query_cutoff_date: String(response?.query_cutoff_date || '')
    }
  } catch (error) {
    batchAnalysis.value = { list: [], expiry_buckets: [], age_buckets: [], query_cutoff_date: '' }
    analysisError.value = error instanceof Error ? error.message : '批次分析加载失败。'
  } finally {
    analysisLoading.value = false
  }
}

async function loadMovementStatistics() {
  if (!['inbound', 'outbound'].includes(activeStatisticsTab.value)) return
  movementStatisticsLoading.value = true
  movementStatisticsError.value = ''
  try {
    const response = await inventoryApi.movementStatistics({
      kind: activeStatisticsTab.value,
      from: statisticsFromDate.value,
      to: statisticsToDate.value
    })
    movementStatistics.value = {
      list: Array.isArray(response?.list) ? response.list : [],
      count: Number(response?.count || 0),
      from: String(response?.from || statisticsFromDate.value),
      to: String(response?.to || statisticsToDate.value)
    }
  } catch (error) {
    movementStatistics.value = { list: [], count: 0, from: '', to: '' }
    movementStatisticsError.value = error instanceof Error ? error.message : '出入库统计加载失败。'
  } finally {
    movementStatisticsLoading.value = false
  }
}

function onModalSaved() {
  drawer.value = ''
  if (mode.value === 'platform') loadPlatformLocations().then(() => loadCurrentList())
  else loadCurrentList()
}

onMounted(async () => {
  applyRequestedPage()
  if (mode.value === 'platform') await loadPlatformLocations()
  else await loadStoreSessionContext()
  if (active.value === 'statistics') {
    if (['inbound', 'outbound'].includes(activeStatisticsTab.value)) await loadMovementStatistics()
    else await loadBatchAnalysis()
  }
  else {
    if (active.value === 'stock') await stockUnifiedQuery.load()
    await loadCurrentList()
  }
})
</script>

<template>
  <div class="inventory-shell" :class="{ 'inventory-shell--collapsed': sidebarCollapsed, 'inventory-shell--store-entry': isStoreEntry }">
    <aside v-if="!isStoreEntry" class="inventory-sidebar" :class="{ 'inventory-sidebar--open': sidebarOpen }">
      <div class="inventory-brand">
        <span class="inventory-brand__mark"><Package2 :size="20" /></span>
        <div class="inventory-brand__copy"><strong>库存管理</strong><span>门店库存工作台</span></div>
      </div>

      <nav class="inventory-nav" aria-label="库存管理菜单">
        <button
          v-for="item in visibleMenus"
          :key="item.key"
          class="inventory-nav__item"
          :class="{ 'inventory-nav__item--active': active === item.key }"
          @click="selectPage(item.key)"
        >
          <component :is="item.icon" :size="18" />
          <span>{{ item.label }}</span>
          <ChevronRight :size="15" class="inventory-nav__chevron" />
        </button>
      </nav>

        <div class="inventory-account">
          <span class="inventory-account__avatar">员</span>
          <div><strong>当前登录员工</strong><span>{{ mode === 'platform' ? '平台库存管理员' : '门店库存员工' }}</span></div>
      </div>
    </aside>

    <main class="inventory-main">
      <header class="inventory-topbar">
        <button v-if="!isStoreEntry" class="topbar-icon" title="展开或收起菜单" @click="sidebarCollapsed = !sidebarCollapsed">
          <PanelLeftOpen v-if="sidebarCollapsed" :size="19" /><PanelLeftClose v-else :size="19" />
        </button>
        <button v-if="!isStoreEntry" class="topbar-icon topbar-menu" title="打开菜单" @click="sidebarOpen = !sidebarOpen"><Menu :size="20" /></button>

        <div class="topbar-scope">
          <component :is="mode === 'platform' ? Building2 : Store" :size="18" />
          <div><span>当前库存范围</span><strong>{{ scopeName }}</strong></div>
        </div>
        <select v-if="mode === 'platform'" v-model.number="platformLocationId" class="scope-select" aria-label="平台库存仓库" @change="onPlatformLocationChanged">
          <option :value="0">全部授权仓库</option>
          <option v-for="location in platformLocations" :key="location.id" :value="Number(location.id)">{{ location.location_name }}{{ location.store_name_snapshot ? ` · ${location.store_name_snapshot}` : '' }}</option>
        </select>

        <button class="notice-button" title="库存待办"><Bell :size="18" /><i>6</i></button>
      </header>

      <section class="inventory-content">
        <template v-if="active === 'overview'">
          <div class="page-heading page-heading--dashboard">
            <div><p>库存管理 / 首页</p><h1>库存首页</h1><span>库存、临期、盘点与待办集中处理</span></div>
            <div class="heading-actions"><button class="secondary-button" @click="selectPage('statistics', { tab: 'expiry' })">查看库存分析</button><button class="primary-button" @click="selectPage('inbound')"><Plus :size="17" />新建入库</button></div>
          </div>

          <div class="dashboard-cards">
            <button v-for="card in dashboardCards" :key="card.key" class="dashboard-card" :class="`dashboard-card--${card.tone}`" @click="goDashboardTarget(card)">
              <span>{{ card.label }}</span><strong>{{ card.value }}</strong><small>{{ card.note }}</small><ChevronRight :size="17" />
            </button>
          </div>

          <div class="dashboard-grid">
            <section class="content-card risk-card">
              <header class="card-heading"><div><h2>批次临期风险</h2><p>按剩余保质期分档，临期不等于残次品</p></div><button class="text-button" @click="selectPage('statistics', { tab: 'expiry' })">查看批次</button></header>
              <div class="risk-bars risk-bars--pending"><span>批次到期日分档将在权威批次查询接口接通后展示。</span></div>
              <footer class="card-footnote"><AlertTriangle :size="15" />到期日未知的批次将单独提示，不纳入“长期安全”区间。</footer>
            </section>

            <section class="content-card task-card">
              <header class="card-heading"><div><h2>待办处理</h2><p>按当前账号和库存范围展示</p></div><span class="count-badge">—</span></header>
              <button v-for="task in dashboardTasks" :key="task.title" class="task-row" @click="goDashboardTarget(task)">
                <span class="task-row__icon" :class="`task-row__icon--${task.tone}`"><component :is="task.icon" :size="17" /></span>
                <span><strong>{{ task.title }}</strong><small>{{ task.note }}</small></span><b>{{ task.count }}</b>
              </button>
            </section>
          </div>

          <section class="content-card process-card">
            <header class="card-heading"><div><h2>库存处理流程</h2><p>首页只负责引导，所有业务仍进入原有库存页面完成</p></div></header>
            <div class="process-flow">
              <button @click="selectPage('inbound')"><span>01</span><strong>入库建批</strong><small>扫码或手工录入</small></button>
              <ChevronRight :size="20" /><button @click="selectPage('stock')"><span>02</span><strong>库存明细</strong><small>仓库与批次</small></button>
              <ChevronRight :size="20" /><button @click="selectPage('outbound')"><span>03</span><strong>出库与处置</strong><small>先到期先出</small></button>
              <ChevronRight :size="20" /><button @click="selectPage('count')"><span>04</span><strong>盘点对账</strong><small>盈亏可追溯</small></button>
              <ChevronRight :size="20" /><button @click="selectPage('statistics')"><span>05</span><strong>库存统计</strong><small>分析与预警</small></button>
            </div>
          </section>
        </template>

        <template v-else-if="active === 'statistics'">
          <div class="page-heading"><div><p>库存管理 / 库存统计</p><h1>库存统计</h1><span>原入库、出库统计与新增批次、库龄、品项经营分析统一放在这里</span></div><span class="page-heading__note">当前统计导出将在对应权威导出任务接入后开放</span></div>
          <div class="statistics-tabs"><button v-for="tab in statisticsTabs" :key="tab.key" :class="{ active: activeStatisticsTab === tab.key }" @click="selectStatisticsTab(tab.key)">{{ tab.label }}</button></div>
          <section class="query-reserved" aria-label="统一查询预留区域"><SlidersHorizontal :size="18" /><span>统一查询框架预留位</span><small>字段设置、自定义字段、组合筛选将在共享框架的固定版本接入后启用。</small><template v-if="['expiry', 'age'].includes(activeStatisticsTab)"><label class="cutoff-filter">查询截止日<input v-model="analysisCutoffDate" type="date" @change="loadBatchAnalysis" /></label></template><template v-else-if="['inbound', 'outbound'].includes(activeStatisticsTab)"><label class="cutoff-filter">开始日期<input v-model="statisticsFromDate" type="date" @change="loadMovementStatistics" /></label><label class="cutoff-filter">结束日期<input v-model="statisticsToDate" type="date" @change="loadMovementStatistics" /></label></template></section>
          <section class="content-card statistics-board">
            <header class="card-heading"><div><h2>{{ statisticsTabs.find((tab) => tab.key === activeStatisticsTab)?.label }}</h2><p>统计结果仅在权威批次、服务和销售事实查询完成后展示</p></div><button class="text-button" @click="activeStatisticsTab === 'inbound' ? selectPage('inbound') : activeStatisticsTab === 'outbound' ? selectPage('outbound') : selectPage('stock')">查看明细</button></header>
            <template v-if="['expiry', 'age'].includes(activeStatisticsTab)">
              <div v-if="analysisLoading" class="statistics-empty"><ChartNoAxesCombined :size="28" /><strong>正在读取批次分析</strong></div>
              <div v-else-if="analysisError" class="statistics-empty"><AlertTriangle :size="28" /><strong>{{ analysisError }}</strong></div>
              <template v-else>
                <div class="statistics-summary"><article v-for="bucket in analysisBuckets" :key="bucket.name"><span>{{ bucket.name }}</span><strong>{{ bucket.batch_count }} 批次</strong><small>{{ bucket.inventory_amount === null ? '成本权限不足' : `库存金额 ${money(bucket.inventory_amount)}` }}</small></article></div>
                <div class="table-scroll statistics-table"><table><thead><tr><th>商品</th><th>规格</th><th>批次</th><th>{{ activeStatisticsTab === 'expiry' ? '到期日' : '正式入库日' }}</th><th>{{ activeStatisticsTab === 'expiry' ? '距到期日天数' : '库龄天数' }}</th><th>分档</th><th>库存金额</th></tr></thead><tbody><tr v-if="!analysisRows.length"><td colspan="7" class="table-empty">暂无符合条件的批次</td></tr><tr v-for="(row, index) in analysisRows" :key="index"><td v-for="cell in row" :key="cell">{{ cell }}</td></tr></tbody></table></div>
              </template>
            </template>
            <template v-else-if="['inbound', 'outbound'].includes(activeStatisticsTab)">
              <div v-if="movementStatisticsLoading" class="statistics-empty"><ChartNoAxesCombined :size="28" /><strong>正在读取批次事实统计</strong></div>
              <div v-else-if="movementStatisticsError" class="statistics-empty"><AlertTriangle :size="28" /><strong>{{ movementStatisticsError }}</strong></div>
              <template v-else>
                <div class="statistics-summary"><article><span>统计期间</span><strong>{{ movementStatistics.from }} 至 {{ movementStatistics.to }}</strong><small>按业务日、商品与 SKU 汇总</small></article><article><span>汇总行数</span><strong>{{ movementStatistics.count }} 行</strong><small>来源：不可变批次流水</small></article></div>
                <div class="table-scroll statistics-table"><table><thead><tr><th>业务日期</th><th>业务类型</th><th>商品</th><th>规格</th><th>单据数</th><th>批次流水数</th><th>数量</th><th>成本金额</th></tr></thead><tbody><tr v-if="!movementStatisticRows.length"><td colspan="8" class="table-empty">统计期间暂无{{ activeStatisticsTab === 'inbound' ? '入库' : '出库' }}流水</td></tr><tr v-for="(row, index) in movementStatisticRows" :key="index"><td v-for="cell in row" :key="cell">{{ cell }}</td></tr></tbody></table></div>
              </template>
            </template>
            <div v-else class="statistics-empty"><ChartNoAxesCombined :size="28" /><strong>正在接入统一事实查询</strong><span>不会用页面示例数据替代库存、成本、库龄或成交分析结果。</span></div>
          </section>
        </template>

        <template v-else>
          <div class="page-heading"><div><p>{{ currentPage.crumb }}</p><h1>{{ currentPage.title }}</h1><span v-if="statusFocus" class="focus-hint"><Clock3 :size="14" />当前从首页进入：{{ statusFocus }}</span></div><button v-if="currentPage.action" class="primary-button" @click="openDrawer()"><Plus :size="17" />{{ currentPage.action }}</button></div>
          <section v-if="active === 'stock'" class="inventory-unified-query" aria-label="库存统一查询">
            <UnifiedQueryToolbar
              search-placeholder="搜索商品名称、规格、编码、条码或批次号"
              settings-button-label="查询设置"
              :fields="stockQueryFields"
              page-code="inventory_batch_stock"
              page-name="库存查询"
              :result-count="remoteTotal"
              :page-size="20"
              :current-page="1"
              :current-page-count="remoteRows.length"
              :data-as-of="stockQueryCapability.dataAsOf"
              :query-capability="stockQueryCapability"
              :executed-query="stockExecutedQuery"
              :is-query-loading="listLoading"
              :state-context-key="mode === 'platform' ? `inventory-platform-batch-stock-${platformLocationId || 'all'}` : 'inventory-store-batch-stock'"
              default-sort-field="到期日"
              :settings="stockQuerySettings"
              @query="queryBatchStock"
              :on-save-settings="saveStockQuerySettings"
              :on-save-field-aliases="stockUnifiedQuery.saveFieldAliases"
              :on-save-custom-field="stockUnifiedQuery.saveCustomField"
              :on-change-custom-field-status="stockUnifiedQuery.changeCustomFieldStatus"
              :on-archive-custom-field="stockUnifiedQuery.archiveCustomField"
              :on-upgrade-saved-query-field-reference="stockUnifiedQuery.upgradeSavedQueryFieldReference"
              :on-create-export="stockUnifiedQuery.createExport"
              :on-query-export-task="stockUnifiedQuery.queryExportTask"
            />
            <p v-if="stockUnifiedQuery.loadError" class="inventory-load-error">{{ stockUnifiedQuery.loadError }}</p>
          </section>
          <section v-if="active === 'stock' && mode === 'platform'" class="filter-card inventory-platform-warehouse-filter">
            <label>库存仓库
              <select v-model.number="platformLocationId" @change="onPlatformLocationChanged">
                <option :value="0">全部授权仓库</option>
                <option v-for="location in platformLocations" :key="location.id" :value="Number(location.id)">{{ location.location_name }}{{ location.store_name_snapshot ? ` · ${location.store_name_snapshot}` : '' }}</option>
              </select>
            </label>
            <small>选择仓库只会缩小当前管理员的数据范围，服务端会再次校验。</small>
          </section>
          <section v-if="active !== 'stock'" class="query-reserved" aria-label="统一查询预留区域"><SlidersHorizontal :size="18" /><span>统一查询框架预留位</span><small>保留原页面筛选字段：{{ currentPage.filters.join('、') }}</small></section>
          <section v-if="!(active === 'stock')" class="filter-card"><div class="filter-search"><Search :size="17" /><input v-model="keyword" :placeholder="`搜索${currentPage.title}记录`" @keyup.enter="loadCurrentList" /></div><label v-if="mode === 'platform'">库存仓库<select v-model.number="platformLocationId" @change="onPlatformLocationChanged"><option :value="0">全部授权仓库</option><option v-for="location in platformLocations" :key="location.id" :value="Number(location.id)">{{ location.location_name }}{{ location.store_name_snapshot ? ` · ${location.store_name_snapshot}` : '' }}</option></select></label><label v-for="filter in currentPage.filters.slice(0, 2)" :key="filter">{{ filter }}<select><option>全部</option></select></label><button class="primary-button" :disabled="listLoading" @click="loadCurrentList">{{ listLoading ? '查询中' : '查询' }}</button><button class="secondary-button" @click="openDrawer()"><Barcode :size="16" />扫码录入</button></section>
          <section class="content-card table-card"><header class="table-meta"><span>共 {{ remoteTotal }} 条记录</span><div><button v-if="['inbound', 'outbound'].includes(active)" class="text-button" @click="openDrawer('import')">导入</button><span v-if="active !== 'stock'" class="table-meta__note">导出将在对应权威导出任务接入后开放</span></div></header><p v-if="listError" class="inventory-load-error">{{ listError }}</p><div v-else class="table-scroll"><table><thead><tr><th v-for="column in currentPage.columns" :key="column">{{ column }}</th><th>操作</th></tr></thead><tbody><tr v-if="listLoading"><td :colspan="currentPage.columns.length + 1" class="table-empty">正在读取库存数据...</td></tr><tr v-else-if="!currentRows.length"><td :colspan="currentPage.columns.length + 1" class="table-empty">暂无符合条件的记录</td></tr><tr v-else v-for="(row, index) in currentRows" :key="index"><td v-for="(cell, cellIndex) in row" :key="cellIndex"><span v-if="cellIndex === row.length - 1" class="state-tag">{{ cell }}</span><template v-else>{{ cell }}</template></td><td><button class="text-button" @click="openDrawer(`${active}-detail`)">查看</button></td></tr></tbody></table></div></section>
        </template>
      </section>
    </main>

    <InventoryBusinessModal :visible="Boolean(drawer)" :page-key="active" :modal-kind="drawer" :scope-name="scopeName" :default-location-name="defaultLocationName" :warehouse-options="platformLocations" :mode="mode" @close="drawer = ''" @saved="onModalSaved" />
  </div>
</template>
