<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  AlertTriangle, ArrowDownToLine, ArrowUpFromLine,
  Boxes, ChartNoAxesCombined, ChevronRight, ClipboardCheck,
  Clock3, FileClock, FileSpreadsheet, FlaskConical, LayoutDashboard,
  Package2, Plus, QrCode, Search,
  SlidersHorizontal, Warehouse, X
} from '@lucide/vue'
import InventoryBusinessModal from './components/InventoryBusinessModal.vue'
import InventoryOperationalUnifiedQueryToolbar from './components/InventoryOperationalUnifiedQueryToolbar.vue'
import InventoryRecipeModal from './components/InventoryRecipeModal.vue'
import InventoryStoreSelector from './components/InventoryStoreSelector.vue'
import { inventoryStatusLabel } from './statusLabels'
import { inventoryApi, inventoryCommandIdempotencyKey, platformInventoryApi, setInventoryEmbeddedSessionToken, clearInventoryEmbeddedSessionToken } from './services/inventoryApi'
import { UnifiedQueryDateRange, UnifiedQueryToolbar, useUnifiedQueryPage } from '@mohe/unified-query-vue3'
import '@mohe/unified-query-vue3/styles.css'

const props = defineProps({
  // The inventory workbench is one Vue 3 module. Hosts may render it directly
  // instead of creating a second document through an iframe.
  entryMode: { type: String, default: '' },
  entryPage: { type: String, default: '' },
  embedded: { type: Boolean, default: false },
  sessionToken: { type: String, default: '' }
})
const initialQuery = typeof window === 'undefined' ? new URLSearchParams() : new URLSearchParams(window.location.search)
const mode = ref(props.entryMode === 'platform' || (!props.entryMode && initialQuery.get('source') === 'platform') ? 'platform' : 'store')
const platformStoreId = ref(0)
const platformListStoreId = ref(0)
const platformRequestStatus = ref('')
const platformRequestPartySelection = ref('ALL:0')
const platformTransferFromStoreId = ref(0)
const platformTransferToStoreId = ref(0)
const platformTransferStatus = ref('')
const platformTransferFromParty = ref('ALL:0')
const platformTransferToParty = ref('ALL:0')
const platformStockSubject = ref('HQ')
const platformHqLocationId = ref(0)
// The operational document lists use a warehouse descriptor so the filter
// can distinguish the headquarters warehouse from a store warehouse. Keep the
// stock page's numeric selector separate because it drives the stock query
// subject directly.
const platformListWarehouseSelection = ref('HQ:0')
const platformLocations = ref([])
const storeLocations = ref([])
const platformListWarehouseSelectionReady = ref(false)
const active = ref(String(props.entryPage || initialQuery.get('page') || 'overview'))
const activeStatisticsTab = ref('inbound')
const sidebarCollapsed = ref(false)
const sidebarOpen = ref(false)
const keyword = ref('')
const todayDate = () => {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
// 库存单据周期只决定查询日期，不改变单据业务时间；默认按门店本地当天筛选。
const todayRange = () => ({ min: todayDate(), max: todayDate() })
// 流水统计默认看本月业务归属日；快照类统计不套用业务日期周期。
const statisticsMonthRange = () => {
  const now = new Date()
  const month = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  return { min: `${month}-01`, max: `${month}-${String(new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate()).padStart(2, '0')}` }
}
const businessDateRange = ref(todayRange())
// 草稿尚无完成日期；清空盘点周期仍可重新查找并编辑历史草稿。
const countDateRange = ref(todayRange())
const statisticsDateRange = ref(statisticsMonthRange())
const statisticsExecutedQuery = ref({})
const businessDatePages = new Set(['request', 'transfer', 'inbound', 'outbound', 'usage', 'import'])
const usesBusinessDatePeriod = (page = active.value) => businessDatePages.has(page) && (page !== 'import' || mode.value === 'store')
const storeOperationalDataScope = ref('normal')
const storeOperationalStatus = ref('')
const recipeStatus = ref('')
const recipeKeyword = ref('')
const editor = ref('')
const statusFocus = ref('')
const remoteRows = ref([])
const sourceRows = ref([])
const remoteTotal = ref(0)
const listLoading = ref(false)
const listError = ref('')
const listDateRange = ref(null)
const batchAnalysis = ref({ list: [], expiry_buckets: [], age_buckets: [], query_cutoff_date: '' })
const analysisLoading = ref(false)
const analysisError = ref('')
const movementStatistics = ref({ list: [], count: 0 })
const movementStatisticsLoading = ref(false)
const movementStatisticsError = ref('')
const stockExecutedQuery = ref({})
const dashboard = ref({ stock_amount_cents: null, stock_product_count: 0, expiring_90_batch_count: 0, expiry_risk_buckets: [], count_pending_count: 0, request_pending_count: 0 })
const dashboardLoading = ref(false)
const dashboardError = ref('')
const selectedDetail = ref(null)
const stockQueryToolbar = ref(null)
const statisticsQueryToolbar = ref(null)
const statisticsInitialQuery = ref({})
const transferActionConfirmation = ref(null)
const transferActionSubmitting = ref(false)
const recipeActionConfirmation = ref(null)
const recipeActionSubmitting = ref(false)
const deferredPageKeys = new Set(['warehouse'])
// Platform inventory owns the headquarters subject plus cross-subject transfer.
// Store-originated commands keep their authority at the authenticated store.
const isEmbeddedEntry = computed(() => props.embedded || (typeof window !== 'undefined'
  && ['store', 'platform'].includes(new URLSearchParams(window.location.search).get('source'))))
let embeddedSessionReady = null

watch(() => props.sessionToken, (token) => {
  const value = String(token || '').trim()
  if (value) setInventoryEmbeddedSessionToken(value)
  else clearInventoryEmbeddedSessionToken()
}, { immediate: true })

function receiveEmbeddedSession(event) {
  if (event?.data?.type !== 'cashier-v3:inventory-session') return
  const expectedOrigin = new URLSearchParams(window.location.search).get('parent_origin')
  if (!expectedOrigin || event.origin !== expectedOrigin) return
  const wasWaiting = typeof embeddedSessionReady === 'function'
  setInventoryEmbeddedSessionToken(event.data.token)
  embeddedSessionReady?.()
  embeddedSessionReady = null
  if (!wasWaiting) {
    if (mode.value === 'platform') loadPlatformLocations()
    else loadStoreSessionContext()
    loadDashboard()
    if (active.value !== 'overview' && active.value !== 'statistics') loadCurrentList()
  }
}

function clearHostSession() {
  clearInventoryEmbeddedSessionToken()
}

function requestEmbeddedSession() {
  if (window.parent === window) return Promise.resolve()
  const parentOrigin = new URLSearchParams(window.location.search).get('parent_origin')
  if (!parentOrigin) return Promise.resolve()
  return new Promise((resolve) => {
    const timeout = window.setTimeout(() => {
      if (embeddedSessionReady === complete) embeddedSessionReady = null
      resolve(false)
    }, 5000)
    const complete = () => {
      window.clearTimeout(timeout)
      resolve(true)
    }
    embeddedSessionReady = complete
    window.parent.postMessage({ type: 'cashier-v3:inventory-session-request' }, parentOrigin)
  })
}

const storeStockQueryBaseFields = [
  ['product_name', '商品名称', 'text'], ['sku_name', '商品规格', 'text'], ['product_code', '商品编码', 'text'],
  ['barcode', '商品条码', 'text'], ['brand_name', '品牌', 'text'], ['category_name', '商品类别', 'text'],
  ['stock_unit', '库存单位', 'text'], ['batch_no', '批次号', 'text'], ['quality_status', '库存状态', 'text'],
  ['received_date', '正式入库日期', 'date'], ['manufactured_date', '生产日期', 'date'], ['expire_date', '到期日', 'date'],
  ['available_quantity', '数量范围', 'decimal'], ['batch_balance_quantity', '批次结存数量', 'decimal'], ['batch_unit_cost', '批次单位成本', 'amount', true],
  ['source_order_no', '来源入库单', 'text'], ['data_quality', '数据质量', 'text']
].map(([key, label, type, permissionFiltered = false]) => ({
  key,
  label,
  type,
  defaultVisible: true,
  defaultQuick: ['product_name', 'available_quantity'].includes(key),
  quickRange: key === 'available_quantity',
  permissionFiltered
}))

const platformStockQueryBaseFields = [
  { key: 'store_name', label: '门店', type: 'text', defaultVisible: true }, ...storeStockQueryBaseFields
]

const operationalQueryPages = {
  inbound: { pageCode: 'inventory_inbound', fields: [['order_sn', '入库单号'], ['order_type_name', '入库类型'], ['location_name', '入库仓/门店'], ['product_summary', '商品摘要'], ['detail_count', '入库项数', 'integer'], ['status_name', '状态'], ['business_date', '业务日期', 'date']] },
  outbound: { pageCode: 'inventory_outbound', fields: [['order_sn', '出库单号'], ['order_type_name', '出库类型'], ['location_name', '出库仓/门店'], ['product_summary', '商品摘要'], ['detail_count', '出库项数', 'integer'], ['operation_at', '操作时间', 'datetime'], ['status_name', '状态'], ['business_date', '业务日期', 'date']] },
  count: { pageCode: 'inventory_count', fields: [['order_sn', '盘点单号'], ['location_name', '盘点主体'], ['detail_count', '差异项', 'integer'], ['operation_at', '操作时间', 'datetime'], ['status_name', '状态'], ['count_date', '盘点日期', 'date']] },
  request: { pageCode: 'inventory_request', fields: [['order_sn', '请货单号'], ['request_party_name', '请货方'], ['supply_party_name', '供货方'], ['detail_count', '商品项数', 'integer'], ['operation_at', '操作时间', 'datetime'], ['status_name', '状态'], ['business_date', '申请日期', 'date']] },
  transfer: { pageCode: 'inventory_transfer', fields: [['order_sn', '调拨单号'], ['from_party_name', '调出方'], ['to_party_name', '调入方'], ['detail_count', '商品项数', 'integer'], ['operation_at', '操作时间', 'datetime'], ['status_name', '状态'], ['business_date', '发起日期', 'date']] },
  usage: { pageCode: 'inventory_salon_usage', fields: [['order_sn', '业务单号'], ['operation_name', '领退类型'], ['project_name_snapshot', '关联项目'], ['detail_count', '耗材项数', 'integer'], ['operation_at', '操作时间', 'datetime'], ['status_name', '状态'], ['business_date', '业务日期', 'date'], ['remark', '备注']] },
  import: { pageCode: 'inventory_import', fields: [['source_file_name', '文件名称'], ['direction_name', '业务类型'], ['business_type', '出入库类型'], ['total_count', '数据量', 'integer'], ['success_count', '成功行数', 'integer'], ['failure_count', '失败行数', 'integer'], ['document_no', '正式单号'], ['status_name', '状态'], ['updated_at_display', '导入时间', 'datetime']] },
}
const operationalQueryPage = computed(() => operationalQueryPages[active.value] || null)
const operationalQueryFields = computed(() => (operationalQueryPage.value?.fields || []).map(([key, label, type = 'text']) => ({ key, label, type, defaultVisible: true })))
const storeOperationalStatusOptions = computed(() => ({
  inbound: ['已完成', '已作废'],
  outbound: ['已完成', '已作废'],
  count: ['已确认', '已取消'],
  request: ['申请中', '部分履约', '已完成', '已取消', '已终止剩余请货'],
  transfer: ['草稿', '在途', '已收货', '已取消', '已作废'],
  usage: ['已完成', '已作废'],
  import: ['成功', '失败', '处理中']
}[active.value] || []))
const statisticsQueryPages = {
  inbound: { pageCode: 'inventory_statistics_inbound', fields: [['business_date', '业务日期', 'date'], ['source_type_name', '业务类型'], ['product_name', '商品名称'], ['sku_name', '商品规格'], ['stock_unit', '库存单位'], ['document_count', '单据数', 'integer'], ['movement_count', '流水数', 'integer'], ['quantity', '数量', 'decimal']] },
  outbound: { pageCode: 'inventory_statistics_outbound', fields: [['business_date', '业务日期', 'date'], ['source_type_name', '业务类型'], ['product_name', '商品名称'], ['sku_name', '商品规格'], ['stock_unit', '库存单位'], ['document_count', '单据数', 'integer'], ['movement_count', '流水数', 'integer'], ['quantity', '数量', 'decimal']] },
  expiry: { pageCode: 'inventory_statistics_expiry', fields: [['product_name', '商品名称'], ['sku_name', '商品规格'], ['batch_no', '批次号'], ['batch_balance_quantity', '当前剩余库存', 'decimal'], ['expire_date', '到期日', 'date'], ['remaining_shelf_life_days', '距到期日天数', 'integer'], ['remaining_shelf_life_band', '保质期分档'], ['received_date', '正式入库日期', 'date']] },
  age: { pageCode: 'inventory_statistics_age', fields: [['product_name', '商品名称'], ['sku_name', '商品规格'], ['batch_no', '批次号'], ['received_date', '正式入库日期', 'date'], ['inventory_age_days', '库龄天数', 'integer'], ['inventory_age_band', '库龄分档'], ['expire_date', '到期日', 'date']] },
}
const statisticsQueryPage = computed(() => active.value === 'statistics' ? statisticsQueryPages[activeStatisticsTab.value] || null : null)
const statisticsQueryFields = computed(() => (statisticsQueryPage.value?.fields || []).map(([key, label, type = 'text']) => ({
  key, label, type, defaultVisible: key !== 'business_date',
  // 入出库统计只保留一处业务日期入口，统一查询栏直接提交周期条件。
  ...(key === 'business_date' ? { defaultQuick: true, quickDateRange: true } : {})
})))

async function stockQueryRequestAction(action, payload = {}) {
  const client = mode.value === 'platform' ? platformInventoryApi : inventoryApi
  if (!client) throw new Error('库存查询服务尚未初始化。')
  // 平台端只允许按已授权门店收窄，实际库存仓由服务端从门店范围计算。
  const storeScope = mode.value === 'platform'
    ? { subject: platformStockSubject.value, ...(platformStockSubject.value === 'STORE' && Number(platformStoreId.value) > 0 ? { storeId: Number(platformStoreId.value) } : {}) }
    : {}
  if (action === 'query-unified-query-capabilities') {
    const data = await client.unifiedQueryCapabilities(storeScope)
    return { result: { status: 'success' }, data }
  }
  if (action === 'query-unified-query-export-task') {
    const data = await client.unifiedQueryExportTask(payload.taskId)
    return { result: { status: 'success' }, data }
  }
  return client.unifiedQueryCommand({ action, ...payload, ...storeScope })
}

async function inventoryUnifiedQueryRequestAction(action, payload = {}) {
  const pageCode = String(payload.pageCode || operationalQueryPage.value?.pageCode || statisticsQueryPage.value?.pageCode || '')
  if (mode.value !== 'store' || !pageCode) throw new Error('当前入口尚未开放库存统一查询。')
  if (action === 'query-unified-query-capabilities') {
    const data = await inventoryApi.unifiedQueryCapabilities({ pageCode })
    return { result: { status: 'success' }, data }
  }
  if (action === 'query-unified-query-export-task') {
    const data = await inventoryApi.unifiedQueryExportTask(payload.taskId, { pageCode })
    return { result: { status: 'success' }, data }
  }
  return inventoryApi.unifiedQueryCommand({ action, ...payload, pageCode })
}

const stockUnifiedQuery = useUnifiedQueryPage({
  pageCode: 'inventory_batch_stock',
  pageName: '库存查询',
  baseFields: mode.value === 'platform' ? platformStockQueryBaseFields : storeStockQueryBaseFields,
  requestAction: stockQueryRequestAction
})
const stockQueryCapability = stockUnifiedQuery.capability
const stockQueryFields = stockUnifiedQuery.fields
const stockQueryLoadError = computed(() => String(stockUnifiedQuery.loadError.value || '').trim())
const visibleStockQueryFields = computed(() => {
  const fields = mode.value === 'platform'
    ? stockQueryFields.value.filter((field) => !['organization_name', 'location_name', 'store_name'].includes(field.key))
    : stockQueryFields.value
  return fields.map((field) => ({
    ...field,
    defaultQuick: ['product_name', 'available_quantity'].includes(field.key),
    quickRange: field.key === 'available_quantity'
  }))
})
const stockQuerySettings = computed(() => {
  const settings = stockQueryCapability.value?.querySettings || {}
  const quickFields = Array.isArray(settings.quickFields) ? settings.quickFields : []
  const legacyDefaults = ['product_name', 'sku_name', 'barcode', 'batch_no', 'quality_status', 'data_quality']
  const legacyScopeFields = new Set(['organization_name', 'location_name', 'store_name'])
  const comparableQuickFields = quickFields.filter((key) => !legacyScopeFields.has(key))
  const usesLegacyDefaults = quickFields.every((key) => legacyScopeFields.has(key) || legacyDefaults.includes(key))
    && comparableQuickFields.length === legacyDefaults.length
    && legacyDefaults.every((key, index) => comparableQuickFields[index] === key)
  return usesLegacyDefaults
    ? { ...settings, quickFields: ['product_name', 'available_quantity'] }
    : settings
})

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
  { key: 'statistics', label: '库存统计', icon: ChartNoAxesCombined },
  { key: 'request', label: '请货管理', icon: FileClock, action: '新建请货' },
  { key: 'transfer', label: '调拨管理', icon: ArrowUpFromLine, action: '新建调拨' },
  { key: 'usage', label: '院装管理', icon: Warehouse },
  { key: 'import', label: '导入记录', icon: FileSpreadsheet },
  // Non-default warehouse setup is intentionally deferred for this rollout.
  { key: 'recipe', label: '项目配方', icon: FlaskConical, action: '新建配方', platformOnly: true }
]

const pages = {
  inbound: {
    title: '入库管理',
    crumb: '库存管理 / 入库管理',
    filters: ['入库类型', '入库单号', '入库日期', '创建时间'],
    action: '新建入库',
    columns: ['入库单号', '入库类型', '商品摘要', '商品项数', '入库金额', '状态', '入库时间']
  },
  outbound: {
    title: '出库管理',
    crumb: '库存管理 / 出库管理',
    filters: ['出库类型', '出库单号', '出库日期', '创建时间'],
    action: '新建出库',
    columns: ['出库单号', '出库类型', '出库仓/门店', '去向/原因', '出库数量', '成本金额', '操作时间', '状态']
  },
  stock: {
    title: '库存查询',
    crumb: '库存管理 / 库存查询',
    filters: ['商品/条码', '库存区间', '院装产品', '隐藏零库存'],
    columns: ['商品', '商品规格', '可用库存', '库存金额']
  },
  count: {
    title: '库存盘点',
    crumb: '库存管理 / 库存盘点',
    filters: ['盘点状态', '盘点单号', '盘点日期', '创建时间'],
    action: '新建盘点单',
    columns: ['盘点单号', '盘点主体', '盘点范围', '差异项', '盈亏金额', '盘点日期', '操作时间', '状态']
  },
  request: {
    title: '请货管理',
    crumb: '库存管理 / 请货管理',
    filters: ['请货状态'],
    action: '新建请货',
    columns: ['请货单号', '请货方', '供货方', '商品项数', '操作时间', '状态']
  },
  transfer: {
    title: '调拨管理',
    crumb: '库存管理 / 调拨管理',
    filters: ['调拨状态', '调拨单号', '调出方', '调拨时间'],
    action: '新建调拨',
    columns: ['调拨单号', '调出方', '调入方', '商品项数', '操作时间', '状态']
  },
  recipe: {
    title: '项目配方',
    crumb: '库存管理 / 项目配方',
    filters: ['配方状态'],
    action: '新建配方',
    columns: ['ID', '项目', '耗材项数', '版本', '状态', '更新时间']
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
    filters: [],
    action: '院装耗材领用',
    columns: ['业务单号', '耗材', '关联项目', '耗材项数', '实际耗材金额', '类型', '操作时间', '状态']
  },
  import: {
    title: '导入记录',
    crumb: '库存管理 / 导入记录',
    filters: ['业务类型', '导入状态', '导入时间'],
    columns: ['文件名称', '业务类型', '库存主体', '数据量', '结果', '状态', '导入时间']
  }
}
const recognizedPageKeys = new Set(['overview', 'statistics', ...Object.keys(pages)])

const visibleMenus = computed(() => menuItems.filter((item) => {
  if (deferredPageKeys.has(item.key)) return false
  if (item.platformOnly) return mode.value === 'platform'
  return true
}))
const platformHqLocations = computed(() => platformLocations.value.filter((location) => String(location?.location_type || '') === 'HQ'))
const platformUsesHqSubject = computed(() => mode.value === 'platform' && ['inbound', 'outbound', 'count', 'transfer'].includes(active.value))
const platformActionRequiresHqLocation = computed(() => mode.value === 'platform' && ['inbound', 'outbound', 'count', 'request', 'transfer'].includes(active.value))
const platformUsageStoreId = computed(() => {
  const [type, rawId] = String(platformListWarehouseSelection.value || '').split(':', 2)
  return type === 'STORE' ? Number(rawId) || 0 : 0
})
const platformUsageRequiresStore = computed(() => mode.value === 'platform' && active.value === 'usage')
const platformFilterRequiresHqLocation = computed(() => platformActionRequiresHqLocation.value && !(active.value === 'request' && platformStockSubject.value === 'STORE'))
const platformStoreOptions = computed(() => {
  const seen = new Set()
  return platformLocations.value.filter((location) => {
    const storeId = Number(location?.store_id || 0)
    if (storeId <= 0 || seen.has(storeId)) return false
    seen.add(storeId)
    return true
  })
})
const platformStoreSelectorOptions = computed(() => [
  { key: '0', name: '全部授权门店', type: 'ALL' },
  ...platformStoreOptions.value.map((location) => ({
    key: String(Number(location.store_id)),
    name: location.store_name_snapshot || location.store_name || `门店${location.store_id}`,
    type: 'STORE'
  }))
])
const platformListWarehouseOptions = computed(() => [
  ...platformHqLocations.value.map((location) => ({
    key: `HQ:${Number(location.id)}`,
    name: location.location_name || location.organization_name_snapshot || '总部仓',
    type: 'HQ'
  })),
  ...platformStoreOptions.value.map((location) => ({
    key: `STORE:${Number(location.store_id)}`,
    name: location.store_name_snapshot || location.store_name || `门店${location.store_id}`,
    type: 'STORE'
  }))
])
const platformUsageStoreSelectorOptions = computed(() => platformStoreOptions.value.map((location) => ({
  key: `STORE:${Number(location.store_id)}`,
  name: location.store_name_snapshot || location.store_name || `门店${location.store_id}`,
  type: 'STORE'
})))
const platformStoreSelection = computed({
  get: () => String(Number(platformStoreId.value) || 0),
  set: (value) => {
    platformStoreId.value = Number(value) || 0
    onPlatformLocationChanged()
  }
})
const platformListStoreSelection = computed({
  get: () => String(Number(platformListStoreId.value) || 0),
  set: (value) => { platformListStoreId.value = Number(value) || 0 }
})
const platformTransferFromStoreSelection = computed({
  get: () => String(Number(platformTransferFromStoreId.value) || 0),
  set: (value) => { platformTransferFromStoreId.value = Number(value) || 0 }
})
const platformTransferToStoreSelection = computed({
  get: () => String(Number(platformTransferToStoreId.value) || 0),
  set: (value) => { platformTransferToStoreId.value = Number(value) || 0 }
})
const platformRequestPartySelectorOptions = computed(() => [
  { key: 'HQ:0', name: '总部仓', type: 'HQ' },
  ...platformStoreOptions.value.map((location) => ({
    key: `STORE:${Number(location.store_id)}`,
    name: location.store_name_snapshot || location.store_name || `门店${location.store_id}`,
    type: 'STORE'
  }))
])
const platformWarehouseSelectorOptions = computed(() => [
  { key: 'HQ:0', name: '总部仓', type: 'HQ' },
  ...platformStoreOptions.value.map((location) => ({ key: `STORE:${Number(location.store_id)}`, name: location.store_name_snapshot || location.store_name || `门店${location.store_id}`, type: 'STORE' }))
])
const currentPage = computed(() => pages[active.value] || null)
const scopeName = computed(() => {
  if (mode.value === 'store') {
    const current = storeLocations.value.find((item) => Number(item.is_default) === 1) || storeLocations.value[0]
    return text(current?.store_name_snapshot || current?.store_name || current?.location_name, '当前门店')
  }
  if (platformUsesHqSubject.value) {
    const headquarters = platformHqLocations.value.find((item) => Number(item.id) === Number(platformHqLocationId.value))
    return headquarters?.location_name || headquarters?.organization_name_snapshot || '总部仓'
  }
  if (active.value === 'usage' && platformUsageStoreId.value > 0) {
    const current = platformLocations.value.find((item) => Number(item.store_id) === platformUsageStoreId.value)
    return current?.store_name_snapshot || current?.store_name || `门店${platformUsageStoreId.value}`
  }
  const current = platformLocations.value.find((item) => Number(item.store_id) === Number(platformStoreId.value))
  return current?.store_name_snapshot || current?.store_name || '全部授权门店'
})
const defaultLocationName = computed(() => {
  if (platformUsesHqSubject.value) return scopeName.value
  const current = storeLocations.value.find((item) => Number(item.is_default) === 1) || storeLocations.value[0]
  return text(current?.location_name, scopeName.value)
})
const currentRows = computed(() => {
  // Search is executed by the server authority. Keeping row order unchanged
  // preserves the source-row identity used by detail and edit actions.
  return remoteRows.value
})
const analysisBuckets = computed(() => activeStatisticsTab.value === 'expiry'
  ? batchAnalysis.value.expiry_buckets
  : batchAnalysis.value.age_buckets)
const analysisRows = computed(() => batchAnalysis.value.list.map((row) => activeStatisticsTab.value === 'expiry'
  ? [text(row.product_name), text(row.sku_name), text(row.batch_no), text(row.batch_balance_quantity), text(row.expire_date), text(row.remaining_shelf_life_days), text(row.remaining_shelf_life_band), row.inventory_amount === null ? '-' : money(row.inventory_amount)]
  : [text(row.product_name), text(row.sku_name), text(row.batch_no), text(row.received_date), text(row.inventory_age_days), text(row.inventory_age_band), row.inventory_amount === null ? '-' : money(row.inventory_amount)]))
const movementStatisticRows = computed(() => movementStatistics.value.list.map((row) => [
  text(row.source_type_name), text(row.product_name), text(row.sku_name), text(row.document_count, '0'),
  text(row.movement_count, '0'), `${text(row.quantity, '0')} ${text(row.stock_unit, '')}`.trim(), centsMoney(row.cost_amount_cents)
]))
const dashboardCards = computed(() => [
  {
    key: 'stock',
    label: '库存总金额',
    value: dashboard.value.can_view_cost === false ? '--' : centsMoney(dashboard.value.stock_amount_cents),
    note: dashboard.value.can_view_cost === false ? '无查看金额权限' : `共 ${dashboard.value.stock_product_count || 0} 个商品`,
    tone: 'blue',
    target: 'stock'
  },
  { key: 'count', label: '盘点待处理', value: String(dashboard.value.count_pending_count || 0), note: '从盘点单据状态读取', tone: 'orange', target: 'count', focus: '待处理' },
  { key: 'request', label: '请货待处理', value: String(dashboard.value.request_pending_count || 0), note: '从请货单据状态读取', tone: 'green', target: 'request', focus: '待处理' },
  { key: 'statistics', label: '90天内临期', value: String(dashboard.value.expiring_90_batch_count || 0), note: '按批次到期日计算', tone: 'red', target: 'statistics', tab: 'expiry' }
])

const dashboardTasks = computed(() => [
  { title: '盘点待处理', note: '从盘点单与差异事实读取', count: String(dashboard.value.count_pending_count || 0), icon: ClipboardCheck, tone: 'orange', target: 'count', focus: '待处理' },
  { title: '临期批次待处置', note: '从批次到期日和截止日读取', count: String(dashboard.value.expiring_90_batch_count || 0), icon: AlertTriangle, tone: 'red', target: 'statistics', tab: 'expiry' },
  { title: '请货申请待处理', note: '从请货单状态读取', count: String(dashboard.value.request_pending_count || 0), icon: FileClock, tone: 'green', target: 'request', focus: '待处理' }
])
const dashboardExpiryBuckets = computed(() => (Array.isArray(dashboard.value.expiry_risk_buckets) ? dashboard.value.expiry_risk_buckets : [])
  .map((bucket) => ({ code: String(bucket?.code || ''), label: String(bucket?.label || ''), count: Math.max(0, Number(bucket?.count || 0)) }))
  .filter((bucket) => bucket.code && bucket.label))
const dashboardExpiryMaximum = computed(() => Math.max(1, ...dashboardExpiryBuckets.value.map((bucket) => bucket.count)))

const statisticsTabs = [
  { key: 'inbound', label: '入库统计' },
  { key: 'outbound', label: '出库统计' },
  { key: 'expiry', label: '批次与临期' },
  { key: 'age', label: '产品库龄' }
]

function selectPage(key, options = {}) {
  if (key === 'movement') key = 'stock'
  if (deferredPageKeys.has(key)) {
    active.value = 'overview'
    return
  }
  active.value = key
  if (usesBusinessDatePeriod(key)) businessDateRange.value = todayRange()
  if (key === 'count') countDateRange.value = todayRange()
  if (key === 'statistics') statisticsDateRange.value = statisticsMonthRange()
  listDateRange.value = null
  statusFocus.value = options.focus || ''
  storeOperationalStatus.value = ''
  if (options.tab) activeStatisticsTab.value = options.tab
  if (key === 'statistics') {
    statisticsInitialQuery.value = options.query && typeof options.query === 'object' ? options.query : {}
    statisticsExecutedQuery.value = statisticsInitialQuery.value
  }
  sidebarOpen.value = false
  editor.value = ''
  if (key !== 'statistics' && key !== 'overview') {
    if (ensurePlatformUsageStoreSelection()) return
    if (key === 'stock') stockUnifiedQuery.load()
    loadCurrentList()
  } else if (key === 'statistics' && mode.value === 'platform') {
    queryStatisticsPage(statisticsInitialQuery.value)
  }
}

watch(() => props.entryPage, (page) => {
  const nextPage = String(page || '').trim()
  if (!nextPage || nextPage === active.value || !recognizedPageKeys.has(nextPage)) return
  selectPage(nextPage)
})

function selectStatisticsTab(key) {
  activeStatisticsTab.value = key
  statisticsInitialQuery.value = {}
  statisticsExecutedQuery.value = {}
  statisticsDateRange.value = statisticsMonthRange()
  if (mode.value === 'platform') queryStatisticsPage({})
}

function expiryRiskQuery(code) {
  const fieldKey = 'remaining_shelf_life_days'
  const filters = {
    OVERDUE: [{ fieldKey, operator: 'less_than', value: 0 }],
    WITHIN_30: [{ fieldKey, operator: 'between', value: [0, 30] }],
    DAYS_31_60: [{ fieldKey, operator: 'between', value: [31, 60] }],
    DAYS_61_90: [{ fieldKey, operator: 'between', value: [61, 90] }],
    SAFE_OVER_90: [{ fieldKey, operator: 'greater_than', value: 90 }],
    UNKNOWN: [{ fieldKey, operator: 'is_null', value: null }]
  }
  return { filters: filters[code] || [], filterRelation: 'all' }
}

function openExpiryRisk(bucket) {
  selectPage('statistics', { tab: 'expiry', focus: bucket.label, query: expiryRiskQuery(bucket.code) })
}

function expiryRiskPercent(bucket) {
  return bucket.count <= 0 ? 0 : Math.max(6, Math.round((bucket.count / dashboardExpiryMaximum.value) * 100))
}

function expiryRiskTone(code) {
  if (code === 'OVERDUE') return 'risk-bar--danger'
  if (['WITHIN_30', 'DAYS_31_60', 'DAYS_61_90'].includes(code)) return 'risk-bar--warning'
  if (code === 'SAFE_OVER_90') return 'risk-bar--safe'
  return 'risk-bar--unknown'
}

function openEditor(kind = active.value, row = null) {
  if (deferredPageKeys.has(active.value)) return
  if (kind === 'usage-detail') {
    openUsageDetail(row)
    return
  }
  if (kind === 'count-detail') {
    // The list action reopens a creator-owned draft for editing; confirmed documents remain read-only.
    if (Number(row?.draft_id || 0) > 0) openCountDraft(row)
    else openCountDetail(row)
    return
  }
  if (kind === 'count-edit') {
    openCountDraft(row)
    return
  }
  // Import chooses one or more inventory warehouses inside its own dialog;
  // it must not be blocked merely because the current list has no HQ default.
  const isReadOnlyDetail = String(kind).endsWith('-detail')
  if (kind !== 'import' && !isReadOnlyDetail && platformActionRequiresHqLocation.value && Number(platformHqLocationId.value) <= 0) {
    listError.value = '当前平台范围内没有可操作的总部仓。'
    return
  }
  selectedDetail.value = row
  editor.value = kind
}

function openCurrentQuerySettings() {
  if (active.value === 'stock') stockQueryToolbar.value?.openSettings()
  else if (active.value === 'statistics') statisticsQueryToolbar.value?.openSettings()
}

async function openTransferDetail(row) {
  try {
    selectedDetail.value = mode.value === 'platform'
      ? await platformInventoryApi.hqCrossTransferDetail(row.id, { hq_location_id: Number(platformHqLocationId.value) })
      : await inventoryApi.crossTransferDetail(row.id)
    editor.value = 'transfer-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '调拨详情读取失败。'
  }
}

async function openInboundDetail(row) {
  try {
    selectedDetail.value = mode.value === 'platform'
      ? await platformInventoryApi.hqInboundDetail(row.id, { hq_location_id: Number(platformHqLocationId.value) })
      : await inventoryApi.inboundDetail(row.source_id || row.id)
    editor.value = 'inbound-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '入库详情读取失败。'
  }
}

async function openInboundOutboundDetails(row) {
  try {
    selectedDetail.value = mode.value === 'platform'
      ? await platformInventoryApi.hqInboundOutboundDetails(row.id, { hq_location_id: Number(platformHqLocationId.value) })
      : await inventoryApi.inboundOutboundDetails(row.source_id || row.id)
    editor.value = 'inbound-outbound-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '出库明细读取失败。'
  }
}

async function openOutboundDetail(row) {
  try {
    if (mode.value !== 'platform') {
      selectedDetail.value = await inventoryApi.outboundDetail(row.source_id || row.id)
      editor.value = 'outbound-detail'
      return
    }
    selectedDetail.value = await platformInventoryApi.hqOutboundDetail(row.id, { hq_location_id: Number(platformHqLocationId.value) })
    editor.value = 'outbound-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '出库详情读取失败。'
  }
}

async function openRequestDetail(row) {
  try {
    selectedDetail.value = mode.value === 'platform'
      ? await platformInventoryApi.hqRequestDetail(row.id, { hq_location_id: Number(platformHqLocationId.value) })
      : await inventoryApi.requestDetail(row.id)
    editor.value = 'request-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '请货详情读取失败。'
  }
}

async function openCountDetail(row) {
  try {
    // 列表行只有汇总列；两个终端均须读取已授权的权威盘点单及明细后再展示。
    selectedDetail.value = mode.value === 'platform'
      ? await platformInventoryApi.hqCountDetail(row.id, { hq_location_id: Number(platformHqLocationId.value) })
      : await inventoryApi.countDetail(row.id)
    editor.value = 'count-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '盘点详情读取失败。'
  }
}

async function openCountDraft(row) {
  try {
    // A draft ID is not a settled count document ID; read the creator-scoped draft endpoint.
    selectedDetail.value = await inventoryApi.countDraftDetail(row.draft_id)
    editor.value = 'count-edit'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '盘点草稿读取失败。'
  }
}

async function openUsageDetail(row) {
  try {
    selectedDetail.value = mode.value === 'platform'
      ? await platformInventoryApi.salonUsageDetail(row.id, { store_id: platformUsageStoreId.value })
      : await inventoryApi.salonUsageDetail(row.id)
    editor.value = 'usage-detail'
  } catch (error) { listError.value = error instanceof Error ? error.message : '院装详情读取失败。' }
}

async function openUsageReturn(row) {
  try {
    selectedDetail.value = mode.value === 'platform'
      ? await platformInventoryApi.salonUsageDetail(row.id, { store_id: platformUsageStoreId.value })
      : await inventoryApi.salonUsageDetail(row.id)
    if (!selectedDetail.value?.document?.can_return) throw new Error('该领用单已无可退回数量。')
    editor.value = 'usage-return'
  } catch (error) { listError.value = error instanceof Error ? error.message : '院装退回单读取失败。' }
}

async function openRequestEditor(row) {
  try {
    const detail = await inventoryApi.requestDetail(row.id)
    if (!detail?.can_edit) throw new Error('当前请货单已进入调拨或履约流程，不能再编辑。')
    selectedDetail.value = detail
    editor.value = 'request-edit'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '请货单编辑状态读取失败。'
  }
}

async function openImportDetail(row) {
  try {
    selectedDetail.value = await inventoryApi.importDetail(row.id)
    editor.value = 'import-detail'
  } catch (error) { listError.value = error instanceof Error ? error.message : '导入记录读取失败。' }
}

function runTransferAction(action, row) {
  runDocumentAction(action, row, 'transfer')
}

function runDocumentAction(action, row, documentType = active.value) {
  const labels = {
    dispatch: '发货', receive: '确认收货', cancel: '取消草稿', reverse: '作废调拨单',
    void: `作废${documentType === 'inbound' ? '入库单' : '出库单'}`,
    cancelRequest: '取消请货单', terminateRequest: '终止剩余请货'
  }
  const requiresReason = ['reverse', 'void', 'cancelRequest', 'terminateRequest'].includes(action)
  transferActionConfirmation.value = {
    action,
    row,
    documentType,
    label: labels[action] || '执行该操作',
    requiresReason,
    reason: '',
    idempotencyKey: requiresReason ? inventoryCommandIdempotencyKey() : ''
  }
}

function cancelTransferAction() {
  if (transferActionSubmitting.value) return
  transferActionConfirmation.value = null
}

async function confirmTransferAction() {
  const confirmation = transferActionConfirmation.value
  if (!confirmation || transferActionSubmitting.value) return
  const { action, row, documentType } = confirmation
  const reason = String(confirmation.reason || '').trim()
  if (confirmation.requiresReason && reason.length < 2) return
  transferActionSubmitting.value = true
  try {
    const command = { idempotency_key: confirmation.idempotencyKey, reason }
    const sourceId = row.source_id || row.id
    if (mode.value === 'platform') {
      const payload = { hq_location_id: Number(platformHqLocationId.value) }
      if (action === 'dispatch') await platformInventoryApi.dispatchHqCrossTransfer(row.id, payload)
      else if (action === 'receive') await platformInventoryApi.receiveHqCrossTransfer(row.id, payload)
      else if (action === 'cancel') await platformInventoryApi.cancelHqCrossTransfer(row.id, payload)
      else if (action === 'reverse') await platformInventoryApi.reverseHqCrossTransfer(row.id, { ...payload, ...command })
      else if (action === 'void' && documentType === 'inbound') await platformInventoryApi.voidHqInbound(sourceId, { ...payload, ...command })
      else if (action === 'void' && documentType === 'outbound') await platformInventoryApi.voidHqOutbound(sourceId, { ...payload, ...command })
      else if (action === 'cancelRequest') await platformInventoryApi.cancelHqRequest(row.id, { ...payload, ...command })
      else if (action === 'terminateRequest') await platformInventoryApi.terminateHqRequest(row.id, { ...payload, ...command })
    } else if (action === 'dispatch') await inventoryApi.dispatchCrossTransfer(row.id)
    else if (action === 'receive') await inventoryApi.receiveCrossTransfer(row.id)
    else if (action === 'cancel') await inventoryApi.cancelCrossTransfer(row.id)
    else if (action === 'reverse') await inventoryApi.reverseCrossTransfer(row.id, command)
    else if (action === 'void' && documentType === 'inbound') await inventoryApi.voidInbound(sourceId, command)
    else if (action === 'void' && documentType === 'outbound') await inventoryApi.voidOutbound(sourceId, command)
    else if (action === 'cancelRequest') await inventoryApi.cancelRequest(row.id, command)
    else if (action === 'terminateRequest') await inventoryApi.terminateRequest(row.id, command)
    await loadCurrentList()
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '调拨操作未完成。'
  } finally {
    transferActionSubmitting.value = false
    transferActionConfirmation.value = null
  }
}

function goDashboardTarget(item) {
  selectPage(item.target, { focus: item.focus, tab: item.tab })
}

function applyRequestedPage() {
  const requestedPage = String(props.entryPage || (typeof window === 'undefined' ? '' : new URLSearchParams(window.location.search).get('page')) || '')
  if (requestedPage === 'movement') {
    active.value = 'stock'
    return
  }
  if (requestedPage && recognizedPageKeys.has(requestedPage) && !deferredPageKeys.has(requestedPage)) {
    active.value = requestedPage
  } else if (mode.value === 'platform') active.value = 'overview'
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

function operationTime(value) {
  const seconds = Number(value)
  return Number.isFinite(seconds) && seconds > 0 ? new Date(seconds * 1000).toLocaleString() : '-'
}

function mapApiRow(page, row) {
  switch (page) {
    case 'inbound': return [text(row.order_sn), text(row.order_type_name), text(row.product_summary), `${text(row.detail_count, '0')} 项`, centsMoney(row.cost_amount_cents), inventoryStatusLabel(row.status_name, '已完成'), text(row.add_time ? new Date(Number(row.add_time) * 1000).toLocaleString() : '-')]
    case 'outbound': return [text(row.order_sn), text(row.order_type_name), text(row.location_name), text(row.product_summary), `${text(row.detail_count, '0')} 项`, centsMoney(row.cost_amount_cents), operationTime(row.operation_at), inventoryStatusLabel(row.status_name, '已完成')]
    case 'stock': return row.available_quantity !== undefined
      ? [text(row.product_name), text(row.sku_name), text(row.available_quantity), row.inventory_amount_cents !== undefined ? centsMoney(row.inventory_amount_cents) : money(row.inventory_amount)]
      : [text(row.product_name), text(row.sku_name), text(row.batch_balance_quantity), row.inventory_amount === null ? '-' : money(row.inventory_amount)]
    case 'count': return [text(row.order_sn), text(row.store_name_label || row.store_name || row.store_name_snapshot || row.location_name || scopeName.value), text(row.count_type || '全盘'), text(row.detail_count), centsMoney(row.change_amount_cents), text(row.count_date), operationTime(row.operation_at), countStatusName(row.status_name)]
    case 'request': return [text(row.order_sn), text(row.request_party_name || row.request_store_name), text(row.supply_party_name || row.supply_store_name), text(row.detail_count), operationTime(row.operation_at), inventoryStatusLabel(row.status_name)]
    case 'transfer': return [text(row.order_sn), text(row.from_party_name || row.from_store_name), text(row.to_party_name || row.to_store_name), text(row.detail_count), operationTime(row.operation_at), inventoryStatusLabel(row.status_name)]
    case 'usage': return [text(row.usage_no), '耗材明细见单据', text(row.project_name_snapshot), `${text(row.detail_count, '0')} 项`, '-', text(row.operation_type === 'RETURN' ? '退回' : '领用'), operationTime(row.operation_at), inventoryStatusLabel(row.document_status, '已完成')]
    case 'import': return [text(row.source_file_name), row.direction === 'inbound' ? '入库导入' : '出库导入', scopeName.value, `${text(row.success_count, '0')} / ${text(row.total_count, '0')} 行`, text(row.document_no || row.failure_message), text(row.status === 'SUCCEEDED' ? '成功' : row.status === 'FAILED' ? '失败' : '处理中'), text(row.updated_at ? new Date(Number(row.updated_at) * 1000).toLocaleString() : '-')]
    case 'recipe': return [text(row.id), text(row.project_name), text(row.consumable_count, '0'), text(row.version), inventoryStatusLabel(row.status_name), text(row.update_time)]
    case 'warehouse': return [text(row.location_code), text(row.location_name), text(row.location_type), text(row.store_name_snapshot || row.organization_name_snapshot), Number(row.is_default) === 1 ? '是' : '否', inventoryStatusLabel(row.location_status, '启用')]
    default: return []
  }
}

function countStatusName(status) {
  // Keep the explicit count labels here for the existing contract; all other
  // inventory document statuses share the central human-readable projection.
  return { DRAFT: '草稿', CONFIRMED: '已确认', CANCELLED: '已取消' }[String(status || '')] || inventoryStatusLabel(status)
}

// The query service returns batch facts so filters can remain batch-accurate.
// The list is intentionally summarized here by product/SKU; batch information
// remains available only after the operator opens the product detail.
function summarizeStockRows(rows) {
  const summary = new Map()
  rows.forEach((row) => {
    const productId = Number(row?.product_id || 0)
    const skuId = Number(row?.sku_id || 0)
    const key = `${productId}:${skuId}:${text(row?.sku_unique, '')}`
    const existing = summary.get(key)
    const quantity = Number(row?.batch_balance_quantity ?? row?.available_quantity ?? 0)
    const amount = row?.inventory_amount_cents ?? row?.inventory_amount
    if (existing) {
      existing.available_quantity = Number(existing.available_quantity || 0) + quantity
      const amountKey = Object.prototype.hasOwnProperty.call(existing, 'inventory_amount_cents') ? 'inventory_amount_cents' : 'inventory_amount'
      if (existing[amountKey] !== null && amount !== null && amount !== undefined && amount !== '') existing[amountKey] = Number(existing[amountKey] || 0) + Number(amount)
      else existing[amountKey] = null
      return
    }
    summary.set(key, {
      ...row,
      available_quantity: quantity,
      ...(Object.prototype.hasOwnProperty.call(row || {}, 'inventory_amount_cents')
        ? { inventory_amount_cents: amount === null || amount === undefined || amount === '' ? null : Number(amount) }
        : { inventory_amount: amount === null || amount === undefined || amount === '' ? null : Number(amount) })
    })
  })
  return Array.from(summary.values())
}

function isExplicitListQuery(value) {
  return value !== null
    && typeof value === 'object'
    && !('isTrusted' in value)
    && !('preventDefault' in value)
}

async function loadCurrentList(unifiedQuery = null) {
  if (active.value === 'overview' || active.value === 'statistics') return
  listLoading.value = true
  listError.value = ''
  if (mode.value === 'platform' && active.value === 'usage' && !platformUsageStoreId.value) {
    sourceRows.value = []
    remoteRows.value = []
    remoteTotal.value = 0
    listLoading.value = false
    listError.value = '当前岗位无可操作门店库存仓。'
    return
  }
  try {
    const client = mode.value === 'platform' ? platformInventoryApi : inventoryApi
    const hasExplicitQuery = isExplicitListQuery(unifiedQuery)
    const query = hasExplicitQuery
      ? { ...unifiedQuery }
      : {
          keyword: keyword.value.trim()
        }
    // Keep the visible search box authoritative when the query button is used.
    // Unified-query callbacks may carry a stale payload from the previous run.
    if (!hasExplicitQuery || !Object.prototype.hasOwnProperty.call(query, 'keyword')) query.keyword = keyword.value.trim()
    if (active.value === 'recipe') {
      query.keyword = recipeKeyword.value.trim()
      query.status = recipeStatus.value
      query.page = 1
      query.limit = 100
    }
    if (mode.value === 'platform' && active.value === 'stock') {
      query.subject = platformStockSubject.value
      if (platformStockSubject.value === 'STORE' && Number(platformStoreId.value) > 0) query.storeId = Number(platformStoreId.value)
    }
    if (mode.value === 'platform' && active.value === 'request') {
      const [partyType, partyId] = String(platformRequestPartySelection.value || 'ALL:0').split(':')
      if (partyType === 'HQ' || partyType === 'STORE') {
        query.request_party_type = partyType
        query.request_party_id = Number(partyId) || 0
      }
      if (platformRequestStatus.value) query.status = platformRequestStatus.value
    }
    if (mode.value === 'platform' && active.value === 'transfer') {
      const [fromType, fromId] = String(platformTransferFromParty.value || 'ALL:0').split(':')
      const [toType, toId] = String(platformTransferToParty.value || 'ALL:0').split(':')
      if (fromType === 'HQ' || fromType === 'STORE') { query.from_party_type = fromType; query.from_party_id = Number(fromId) || 0 }
      if (toType === 'HQ' || toType === 'STORE') { query.to_party_type = toType; query.to_party_id = Number(toId) || 0 }
      if (platformTransferStatus.value) query.status = platformTransferStatus.value
    }
    const businessPeriod = businessDateRange.value
    if (usesBusinessDatePeriod() && mode.value === 'platform' && businessPeriod.min && businessPeriod.max) {
      query.business_date_from = businessPeriod.min
      query.business_date_to = businessPeriod.max
      if (active.value === 'usage') {
        query.from = businessPeriod.min
        query.to = businessPeriod.max
        query.start_time = `${businessPeriod.min} 00:00:00`
        query.end_time = `${businessPeriod.max} 23:59:59`
      }
    }
    const [listWarehouseType, listWarehouseId] = String(platformListWarehouseSelection.value || 'HQ:0').split(':')
    // The platform unified-query endpoint accepts only its structured filter
    // contract. Legacy list endpoints consume business_date_from/to directly,
    // so remove those keys when a selected store uses unified query.
    const { business_date_from, business_date_to, ...unifiedOperationalQuery } = query
    // 周期只筛服务端确认日期；清空周期时保留草稿，且平台/门店共用同一过滤契约。
    const countDateConditions = active.value === 'count' && countDateRange.value.min && countDateRange.value.max
      ? [{ field: 'count_date', operator: 'between', value: [countDateRange.value.min, countDateRange.value.max] }]
      : []
    const platformCountQuery = listWarehouseType === 'STORE'
      ? { ...unifiedOperationalQuery, pageCode: 'inventory_count', subject: 'STORE', storeId: Number(listWarehouseId) || 0,
          topFilterConditions: [...(Array.isArray(unifiedOperationalQuery.topFilterConditions) ? unifiedOperationalQuery.topFilterConditions : []), ...countDateConditions] }
      : { ...unifiedOperationalQuery, pageCode: 'inventory_count', subject: 'HQ', hq_location_id: Number(listWarehouseId) || Number(platformHqLocationId.value),
          topFilterConditions: [...(Array.isArray(unifiedOperationalQuery.topFilterConditions) ? unifiedOperationalQuery.topFilterConditions : []), ...countDateConditions] }
    const listWarehouseQuery = listWarehouseType === 'STORE'
      ? { ...unifiedOperationalQuery, pageCode: `inventory_${active.value}`, subject: 'STORE', storeId: Number(listWarehouseId) || 0,
          topFilterConditions: [
            ...(Array.isArray(unifiedOperationalQuery.topFilterConditions) ? unifiedOperationalQuery.topFilterConditions : []),
            ...(usesBusinessDatePeriod() && businessPeriod.min && businessPeriod.max
              ? [{ field: 'business_date', operator: 'between', value: [businessPeriod.min, businessPeriod.max] }]
              : [])
          ] }
      : { hq_location_id: Number(listWarehouseId) || Number(platformHqLocationId.value), ...query }
    const response = mode.value === 'platform' && active.value === 'inbound' && listWarehouseType === 'STORE'
      ? await platformInventoryApi.unifiedOperationalQuery(listWarehouseQuery)
      : mode.value === 'platform' && active.value === 'outbound' && listWarehouseType === 'STORE'
      ? await platformInventoryApi.unifiedOperationalQuery(listWarehouseQuery)
      : mode.value === 'platform' && active.value === 'inbound'
      ? await platformInventoryApi.listHqInbound(listWarehouseQuery)
      : mode.value === 'platform' && active.value === 'outbound'
      ? await platformInventoryApi.listHqOutbound(listWarehouseQuery)
      : mode.value === 'platform' && active.value === 'count'
      ? await platformInventoryApi.unifiedOperationalQuery(platformCountQuery)
      : mode.value === 'platform' && active.value === 'transfer'
      ? await platformInventoryApi.listHqCrossTransfers({ hq_location_id: Number(platformHqLocationId.value), ...query })
      : mode.value === 'platform' && active.value === 'request'
      ? await platformInventoryApi.list('request', { hq_location_id: Number(platformHqLocationId.value), ...query })
      : mode.value === 'platform' && active.value === 'usage'
      ? await platformInventoryApi.list('usage', { ...query, store_id: platformUsageStoreId.value })
      : operationalQueryPage.value && mode.value === 'store'
      ? await inventoryApi.unifiedOperationalQuery({
          ...query,
          pageCode: operationalQueryPage.value.pageCode,
          dataScope: String(query.dataScope || storeOperationalDataScope.value || 'normal'),
          topFilterConditions: [
            ...(Array.isArray(query.topFilterConditions) ? query.topFilterConditions : []),
            ...(storeOperationalStatus.value ? [{ field: 'status_name', operator: 'eq', value: storeOperationalStatus.value }] : []),
            ...countDateConditions,
            ...(usesBusinessDatePeriod() && businessPeriod.min && businessPeriod.max
              ? [{ field: 'business_date', operator: 'between', value: [businessPeriod.min, businessPeriod.max] }]
              : [])
          ]
        })
      : active.value === 'stock' && hasExplicitQuery
      ? await client.unifiedBatchStock(query)
      : active.value === 'stock'
      ? mode.value === 'store'
        ? await client.list('productSummary', { keyword: query.keyword || keyword.value.trim(), page: 1, limit: 100 })
        : platformStockSubject.value === 'HQ'
          ? await platformInventoryApi.hqProductSummary({ hq_location_id: Number(platformHqLocationId.value), keyword: query.keyword || keyword.value.trim(), page: 1, limit: 100 })
          : await client.unifiedBatchStock(query)
      : await client.list(active.value, query)
    const list = Array.isArray(response?.records) ? response.records : (Array.isArray(response?.list) ? response.list : (Array.isArray(response?.items) ? response.items : (Array.isArray(response) ? response : [])))
    const displayRows = active.value === 'stock' ? summarizeStockRows(list) : list
    sourceRows.value = displayRows
    remoteRows.value = displayRows.map((row) => mapApiRow(active.value, row))
    remoteTotal.value = active.value === 'stock' ? displayRows.length : Number(response?.total ?? response?.count ?? list.length)
    listDateRange.value = active.value === 'usage' && response?.from && response?.to
      ? { from: response.from, to: response.to }
      : null
    if (active.value === 'stock' && unifiedQuery && typeof unifiedQuery === 'object') {
      stockExecutedQuery.value = JSON.parse(JSON.stringify(unifiedQuery))
    }
  } catch (error) {
    sourceRows.value = []
    remoteRows.value = []
    remoteTotal.value = 0
    listError.value = error instanceof Error ? error.message : '库存数据加载失败。'
  } finally {
    listLoading.value = false
  }
}

async function queryBatchStock(query) {
  const keywordFilters = Array.isArray(query?.keywordFilters) ? query.keywordFilters : []
  const firstKeyword = keywordFilters.map((item) => item?.value).find((value) => typeof value === 'string')
  keyword.value = typeof query?.keyword === 'string' ? query.keyword.trim() : (firstKeyword || '')
  await loadCurrentList({ ...(query && typeof query === 'object' ? query : {}), keyword: keyword.value })
}

async function queryOperationalPage(query) {
  await loadCurrentList(query && typeof query === 'object' ? query : {})
}

async function queryStoreOperationalPage() {
  await loadCurrentList({
    keyword: keyword.value.trim(),
    dataScope: storeOperationalDataScope.value,
    page: 1,
    limit: 100
  })
}

/** 周期控件确认后才发布区间，并在当前库存功能的服务端筛选条件中生效。 */
function applyBusinessDateRange(value) {
  businessDateRange.value = { min: String(value?.min || ''), max: String(value?.max || '') }
  if (mode.value === 'store') queryStoreOperationalPage()
  else loadCurrentList()
}

/** 周期控件发布完整区间后再执行查询；草稿日期不参与盘点完成日筛选。 */
function applyCountDateRange(value) {
  countDateRange.value = { min: String(value?.min || ''), max: String(value?.max || '') }
  if (mode.value === 'store') queryStoreOperationalPage()
  else loadCurrentList()
}

/** 平台端入出库统计沿用独立周期入口；门店端周期由统一查询栏单独提交。 */
function applyStatisticsDateRange(value) {
  statisticsDateRange.value = { min: String(value?.min || ''), max: String(value?.max || '') }
  queryStatisticsPage(statisticsExecutedQuery.value)
}

async function queryStatisticsPage(query) {
  if (!statisticsQueryPage.value) return
  const isMovement = ['inbound', 'outbound'].includes(activeStatisticsTab.value)
  statisticsExecutedQuery.value = query && typeof query === 'object' ? { ...query } : {}
  if (isMovement) { movementStatisticsLoading.value = true; movementStatisticsError.value = '' }
  else { analysisLoading.value = true; analysisError.value = '' }
  try {
    const platformScope = mode.value === 'platform'
      ? {
          subject: 'HQ',
          ...(Number(platformHqLocationId.value) > 0 ? { hq_location_id: Number(platformHqLocationId.value) } : {})
        }
      : {}
    const client = mode.value === 'platform' ? platformInventoryApi : inventoryApi
    const period = statisticsDateRange.value
    const response = await client.unifiedOperationalQuery({
      ...statisticsExecutedQuery.value,
      ...platformScope,
      pageCode: statisticsQueryPage.value.pageCode,
      // 门店端的业务日期周期已在统一查询 topFilters 中，避免重复日期条件；
      // 平台端仍由本页周期控件收窄同一权威查询。
      topFilterConditions: [
        ...(Array.isArray(statisticsExecutedQuery.value.topFilterConditions) ? statisticsExecutedQuery.value.topFilterConditions : []),
        ...(mode.value === 'platform' && isMovement && period.min && period.max
          ? [{ field: 'business_date', operator: 'between', value: [period.min, period.max] }]
          : [])
      ]
    })
    const records = Array.isArray(response?.records) ? response.records : []
    if (isMovement) {
      movementStatistics.value = { list: records, count: Number(response?.total || 0) }
    } else {
      batchAnalysis.value = { list: records, expiry_buckets: [], age_buckets: [], query_cutoff_date: String(response?.queryCutoffDate || '') }
    }
  } catch (error) {
    if (isMovement) movementStatisticsError.value = error instanceof Error ? error.message : '库存统计查询失败。'
    else analysisError.value = error instanceof Error ? error.message : '库存统计查询失败。'
  } finally {
    if (isMovement) movementStatisticsLoading.value = false
    else analysisLoading.value = false
  }
}

async function loadDashboard() {
  const client = mode.value === 'platform' ? platformInventoryApi : inventoryApi
  if (!client) return
  dashboardLoading.value = true
  dashboardError.value = ''
  try { dashboard.value = await client.dashboard() } catch (error) { dashboardError.value = error instanceof Error ? error.message : '库存首页读取失败。' } finally { dashboardLoading.value = false }
}

async function openStockDetail(row) {
  if (!row?.product_id || !inventoryApi) return
  listError.value = ''
  try {
    selectedDetail.value = mode.value === 'platform' && platformStockSubject.value === 'HQ'
      ? await platformInventoryApi.hqProductDetail(row.product_id, { hq_location_id: Number(platformHqLocationId.value) })
      : await inventoryApi.productDetail(row.product_id)
    openEditor('stock-detail', selectedDetail.value)
  } catch (error) { listError.value = error instanceof Error ? error.message : '库存详情读取失败。' }
}

async function loadPlatformLocations() {
  if (mode.value !== 'platform') return
  try {
    const response = await platformInventoryApi.list('platformLocations')
    platformLocations.value = Array.isArray(response?.list) ? response.list : []
    if (platformStoreId.value > 0 && !platformLocations.value.some((item) => Number(item.store_id) === Number(platformStoreId.value))) platformStoreId.value = 0
    if (platformHqLocationId.value > 0 && !platformHqLocations.value.some((item) => Number(item.id) === Number(platformHqLocationId.value))) platformHqLocationId.value = 0
    if (platformHqLocationId.value <= 0 && (platformHqLocations.value.length === 1 || active.value === 'request')) platformHqLocationId.value = Number(platformHqLocations.value[0]?.id || 0)
    if (ensurePlatformUsageStoreSelection()) return
    const selected = String(platformListWarehouseSelection.value || '')
    if (!platformListWarehouseOptions.value.some((item) => item.key === selected)) {
      platformListWarehouseSelection.value = platformHqLocations.value.length
        ? `HQ:${Number(platformHqLocations.value[0].id)}`
        : (platformStoreOptions.value.length ? `STORE:${Number(platformStoreOptions.value[0].store_id)}` : 'HQ:0')
    }
    const [selectionType, selectionId] = String(platformListWarehouseSelection.value || 'HQ:0').split(':')
    if (selectionType === 'HQ' && Number(selectionId) > 0) platformHqLocationId.value = Number(selectionId)
  } catch (error) {
    platformLocations.value = []
    listError.value = error instanceof Error ? error.message : '平台仓库范围读取失败。'
  }
}

function ensurePlatformUsageStoreSelection() {
  if (mode.value !== 'platform' || active.value !== 'usage') return false
  const selected = String(platformListWarehouseSelection.value || '')
  if (platformUsageStoreSelectorOptions.value.some((item) => item.key === selected)) return false
  const firstStore = platformUsageStoreSelectorOptions.value[0]
  if (!firstStore) return false
  platformListWarehouseSelection.value = firstStore.key
  return true
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

function onPlatformStockSubjectChanged() {
  if (platformStockSubject.value === 'HQ') platformStoreId.value = 0
  stockUnifiedQuery.reset()
  stockUnifiedQuery.load()
  loadCurrentList()
}

function onPlatformHqLocationChanged() {
  if (!platformUsesHqSubject.value) return
  loadCurrentList()
}

watch(platformListWarehouseSelection, (selection) => {
  const [selectionType, selectionId] = String(selection || 'HQ:0').split(':')
  if (selectionType === 'HQ' && Number(selectionId) > 0) platformHqLocationId.value = Number(selectionId)
  if (!platformListWarehouseSelectionReady.value) return
  if (mode.value !== 'platform' || ['stock', 'request', 'transfer'].includes(active.value)) return
  loadCurrentList()
})

function openRecipeEditor(row = null) {
  selectedDetail.value = row
  editor.value = row ? 'recipe-edit' : 'recipe'
}

function toggleRecipeStatus(row) {
  const nextStatus = Number(row?.status) === 1 ? 0 : 1
  const action = nextStatus === 1 ? '启用' : '停用'
  const explanation = nextStatus === 1
    ? '启用后，核销该项目时会按此配方扣减耗材。'
    : '停用后，后续核销不再按此配方扣减耗材，历史记录不受影响。'
  recipeActionConfirmation.value = { type: 'status', row, nextStatus, action, explanation }
}

function deleteRecipe(row) {
  recipeActionConfirmation.value = {
    type: 'delete', row, action: '删除',
    explanation: '删除后不能恢复，已经形成的历史核销记录不受影响。'
  }
}

async function confirmRecipeAction() {
  const confirmation = recipeActionConfirmation.value
  if (!confirmation || recipeActionSubmitting.value) return
  recipeActionSubmitting.value = true
  listError.value = ''
  try {
    if (confirmation.type === 'delete') await platformInventoryApi.deleteRecipe(confirmation.row.id)
    else await platformInventoryApi.setRecipeStatus(confirmation.row.id, confirmation.nextStatus)
    await loadCurrentList()
  } catch (error) {
    listError.value = error instanceof Error ? error.message : `配方${confirmation.action}失败。`
  } finally {
    recipeActionSubmitting.value = false
    recipeActionConfirmation.value = null
  }
}

function onModalSaved() {
  editor.value = ''
  if (mode.value === 'platform') loadPlatformLocations().then(() => loadCurrentList())
  else loadCurrentList()
}

onMounted(async () => {
  window.addEventListener('message', receiveEmbeddedSession)
  window.addEventListener('cashier-v3:state-context-changing', clearHostSession)
  const embeddedSessionReceived = await requestEmbeddedSession()
  // iframe 入口必须先拿到宿主收银台签发的 V3 会话，再访问任何库存接口。
  // 超时继续请求会把“尚未收到 token”误报成登录状态错误，并在每次 HMR
  // 重新加载时污染所有库存页面的错误状态。
  if (window.parent !== window && embeddedSessionReceived !== true) {
    listError.value = '登录状态有误，请重新登录。'
    return
  }
  applyRequestedPage()
  if (mode.value === 'platform') await loadPlatformLocations()
  else await loadStoreSessionContext()
  platformListWarehouseSelectionReady.value = true
  await loadDashboard()
  if (active.value === 'statistics' && mode.value === 'platform') {
    await queryStatisticsPage({})
  } else if (active.value !== 'statistics') {
    if (active.value === 'stock') await stockUnifiedQuery.load()
    await loadCurrentList()
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('message', receiveEmbeddedSession)
  window.removeEventListener('cashier-v3:state-context-changing', clearHostSession)
  embeddedSessionReady = null
})
</script>

<template>
  <div class="inventory-shell" :class="{ 'inventory-shell--collapsed': sidebarCollapsed, 'inventory-shell--store-entry': isEmbeddedEntry }">
    <aside v-if="!isEmbeddedEntry" class="inventory-sidebar" :class="{ 'inventory-sidebar--open': sidebarOpen }">
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
      <section class="inventory-content">
        <section v-if="transferActionConfirmation" class="transfer-confirmation-layer" role="dialog" aria-modal="true" :aria-label="`确认${transferActionConfirmation.label}`">
          <button class="transfer-confirmation-layer__backdrop" aria-label="关闭确认窗口" :disabled="transferActionSubmitting" @click="cancelTransferAction"></button>
          <div class="transfer-confirmation-dialog">
            <h2>确认{{ transferActionConfirmation.label }}</h2>
            <p>单据 {{ transferActionConfirmation.row.order_sn || transferActionConfirmation.row.document_no || transferActionConfirmation.row.id }} 将执行{{ transferActionConfirmation.label }}。原单和原始库存流水会保留。</p>
            <label v-if="transferActionConfirmation.requiresReason" class="transfer-confirmation-dialog__reason">
              操作原因
              <textarea v-model="transferActionConfirmation.reason" maxlength="500" rows="3" placeholder="请填写至少 2 个字的原因"></textarea>
            </label>
            <footer>
              <button class="secondary-button" :disabled="transferActionSubmitting" @click="cancelTransferAction">取消</button>
              <button class="primary-button" :disabled="transferActionSubmitting || (transferActionConfirmation.requiresReason && String(transferActionConfirmation.reason || '').trim().length < 2)" @click="confirmTransferAction">{{ transferActionSubmitting ? '处理中...' : '确认' }}</button>
            </footer>
          </div>
        </section>
        <section v-if="recipeActionConfirmation" class="transfer-confirmation-layer" role="dialog" aria-modal="true" :aria-label="`确认${recipeActionConfirmation.action}配方`">
          <button class="transfer-confirmation-layer__backdrop" aria-label="关闭确认窗口" :disabled="recipeActionSubmitting" @click="recipeActionConfirmation = null"></button>
          <div class="transfer-confirmation-dialog">
            <h2>确认{{ recipeActionConfirmation.action }}配方</h2>
            <p>项目“{{ recipeActionConfirmation.row.project_name || recipeActionConfirmation.row.id }}”将执行{{ recipeActionConfirmation.action }}。{{ recipeActionConfirmation.explanation }}</p>
            <footer>
              <button class="secondary-button" :disabled="recipeActionSubmitting" @click="recipeActionConfirmation = null">取消</button>
              <button class="primary-button" :disabled="recipeActionSubmitting" @click="confirmRecipeAction">{{ recipeActionSubmitting ? '处理中...' : '确认' }}</button>
            </footer>
          </div>
        </section>
        <InventoryRecipeModal v-if="editor && active === 'recipe'" :recipe-id="Number(selectedDetail?.id || 0)" @close="editor = ''; selectedDetail = null" @saved="onModalSaved" />
        <InventoryBusinessModal v-else-if="editor" :page-key="active" :modal-kind="editor" :detail="selectedDetail" :scope-name="scopeName" :default-location-name="defaultLocationName" :warehouse-options="platformLocations" :hq-location-id="platformHqLocationId" :usage-store-id="platformUsageStoreId" :mode="mode" @close="editor = ''; selectedDetail = null" @saved="onModalSaved" @start-usage-return="editor = 'usage-return'" />
        <template v-else-if="active === 'overview'">
          <div class="page-heading page-heading--dashboard">
            <div><p>库存管理 / 首页</p><h1>库存首页</h1><span>{{ mode === 'platform' ? '总部仓' : scopeName }}的库存、临期、盘点与待办集中处理</span></div>
            <div class="heading-actions"><button class="secondary-button" @click="selectPage('statistics', { tab: 'expiry' })">查看库存分析</button></div>
          </div>

          <div class="dashboard-cards">
            <button v-for="card in dashboardCards" :key="card.key" class="dashboard-card" :class="`dashboard-card--${card.tone}`" @click="goDashboardTarget(card)">
              <span>{{ card.label }}</span><strong>{{ card.value }}</strong><small>{{ card.note }}</small><ChevronRight :size="17" />
            </button>
          </div>
          <p v-if="dashboardError" class="inventory-load-error">{{ dashboardError }}</p>

          <div class="dashboard-grid">
            <section class="content-card risk-card">
              <header class="card-heading"><div><h2>批次临期风险</h2><p>按剩余保质期分档，临期不等于残次品</p></div><button class="text-button" @click="selectPage('statistics', { tab: 'expiry' })">查看批次</button></header>
              <div v-if="dashboardLoading" class="risk-bars risk-bars--pending"><span>正在读取批次临期风险...</span></div>
              <div v-else-if="dashboardExpiryBuckets.length" class="risk-bars">
                <button v-for="bucket in dashboardExpiryBuckets" :key="bucket.code" @click="openExpiryRisk(bucket)">
                  <span>{{ bucket.label }}</span><i><b :class="expiryRiskTone(bucket.code)" :style="{ width: `${expiryRiskPercent(bucket)}%` }" /></i><strong>{{ bucket.count }}</strong>
                </button>
              </div>
              <div v-else class="risk-bars risk-bars--pending"><span>暂无批次临期数据</span></div>
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
          <div class="page-heading"><div><p>库存管理 / 库存统计</p><h1>库存统计</h1><span>入库、出库、临期与库龄均按批次事实统一查询</span></div><div class="heading-actions"><span class="page-heading__note">当前统计导出将在对应权威导出任务接入后开放</span><button v-if="mode === 'store' && statisticsQueryPage" class="secondary-button" @click="openCurrentQuerySettings"><SlidersHorizontal :size="16" />查询设置</button></div></div>
          <div class="statistics-tabs"><button v-for="tab in statisticsTabs" :key="tab.key" :class="{ active: activeStatisticsTab === tab.key }" @click="selectStatisticsTab(tab.key)">{{ tab.label }}</button></div>
          <div v-if="mode === 'platform' && ['inbound', 'outbound'].includes(activeStatisticsTab)" class="inventory-period-filter inventory-statistics-period"><UnifiedQueryDateRange :model-value="statisticsDateRange" label="业务日期周期" @change="applyStatisticsDateRange" /></div>
          <component
            :is="InventoryOperationalUnifiedQueryToolbar"
            v-if="statisticsQueryPage && mode === 'store'"
            ref="statisticsQueryToolbar"
            :key="statisticsQueryPage.pageCode"
            :page-code="statisticsQueryPage.pageCode"
            :page-name="statisticsTabs.find((tab) => tab.key === activeStatisticsTab)?.label || '库存统计'"
            :fields="statisticsQueryFields"
            :result-count="['inbound', 'outbound'].includes(activeStatisticsTab) ? movementStatistics.count : batchAnalysis.list.length"
            :current-page-count="['inbound', 'outbound'].includes(activeStatisticsTab) ? movementStatisticRows.length : analysisRows.length"
            :is-query-loading="['inbound', 'outbound'].includes(activeStatisticsTab) ? movementStatisticsLoading : analysisLoading"
            :request-action="inventoryUnifiedQueryRequestAction"
            :initial-query="statisticsInitialQuery"
            :default-quick-date-ranges="{ business_date: statisticsDateRange }"
            @query="queryStatisticsPage"
          />
          <section class="content-card statistics-board">
            <!-- 流水统计直接展示结果表，避免重复标题和说明占用查询空间；批次快照页保留明细入口。 -->
            <header v-if="['expiry', 'age'].includes(activeStatisticsTab)" class="card-heading"><div><h2>{{ statisticsTabs.find((tab) => tab.key === activeStatisticsTab)?.label }}</h2><p>筛选、排序与分页均按当前门店的权威批次事实执行</p></div><button class="text-button" @click="selectPage('stock')">查看明细</button></header>
            <template v-if="['expiry', 'age'].includes(activeStatisticsTab)">
              <div v-if="analysisLoading" class="statistics-empty"><ChartNoAxesCombined :size="28" /><strong>正在读取批次分析</strong></div>
              <div v-else-if="analysisError" class="statistics-empty"><AlertTriangle :size="28" /><strong>{{ analysisError }}</strong></div>
              <template v-else>
                <div class="statistics-summary"><article v-for="bucket in analysisBuckets" :key="bucket.name"><span>{{ bucket.name }}</span><strong>{{ bucket.batch_count }} 批次</strong><small>{{ bucket.inventory_amount === null ? '成本权限不足' : `库存金额 ${money(bucket.inventory_amount)}` }}</small></article></div>
                <div class="table-scroll statistics-table"><table><thead><tr><th>商品</th><th>规格</th><th>批次</th><th v-if="activeStatisticsTab === 'expiry'">当前剩余库存</th><th>{{ activeStatisticsTab === 'expiry' ? '到期日' : '正式入库日' }}</th><th>{{ activeStatisticsTab === 'expiry' ? '距到期日天数' : '库龄天数' }}</th><th>分档</th><th>库存金额</th></tr></thead><tbody><tr v-if="!analysisRows.length"><td :colspan="activeStatisticsTab === 'expiry' ? 8 : 7" class="table-empty">暂无符合条件的批次</td></tr><tr v-for="(row, index) in analysisRows" :key="index"><td v-for="cell in row" :key="cell">{{ cell }}</td></tr></tbody></table></div>
              </template>
            </template>
            <template v-else-if="['inbound', 'outbound'].includes(activeStatisticsTab)">
              <div v-if="movementStatisticsLoading" class="statistics-empty"><ChartNoAxesCombined :size="28" /><strong>正在读取批次事实统计</strong></div>
              <div v-else-if="movementStatisticsError" class="statistics-empty"><AlertTriangle :size="28" /><strong>{{ movementStatisticsError }}</strong></div>
              <template v-else>
                <div class="table-scroll statistics-table"><table><thead><tr><th>业务类型</th><th>商品</th><th>规格</th><th>单据数</th><th>批次流水数</th><th>数量</th><th>成本金额</th></tr></thead><tbody><tr v-if="!movementStatisticRows.length"><td colspan="7" class="table-empty">统计期间暂无{{ activeStatisticsTab === 'inbound' ? '入库' : '出库' }}流水</td></tr><tr v-for="(row, index) in movementStatisticRows" :key="index"><td v-for="cell in row" :key="cell">{{ cell }}</td></tr></tbody></table></div>
              </template>
            </template>
          </section>
        </template>

        <template v-else>
          <div class="page-heading"><div><p>{{ currentPage.crumb }}</p><h1>{{ currentPage.title }}</h1><span v-if="statusFocus" class="focus-hint"><Clock3 :size="14" />当前从首页进入：{{ statusFocus }}</span></div><div class="heading-actions"><button v-if="currentPage.action" class="primary-button" :disabled="(platformActionRequiresHqLocation && !platformHqLocationId) || (platformUsageRequiresStore && !platformUsageStoreId)" @click="active === 'recipe' ? openRecipeEditor() : openEditor()"><Plus :size="17" />{{ currentPage.action }}</button><button v-if="mode === 'store' && operationalQueryPage" class="secondary-button" @click="openCurrentQuerySettings"><SlidersHorizontal :size="16" />查询设置</button></div></div>
          <section v-if="active === 'stock'" class="inventory-unified-query" aria-label="库存统一查询">
            <UnifiedQueryToolbar
              ref="stockQueryToolbar"
              settings-button-label="查询设置"
              :show-keyword-search="false"
              :show-settings-button="true"
              :fields="visibleStockQueryFields"
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
              :state-context-key="mode === 'platform' ? `inventory-platform-batch-stock-${platformStockSubject}-${platformStoreId || 'all'}` : 'inventory-store-batch-stock'"
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
            >
              <template #context-actions>
                <div v-if="mode === 'platform'" class="inventory-query-subject">
                  <label>库存仓
                    <select v-model="platformStockSubject" @change="onPlatformStockSubjectChanged"><option value="HQ">总部仓</option><option value="STORE">门店</option></select>
                  </label>
                  <label v-if="platformStockSubject === 'STORE'">库存仓
                    <InventoryStoreSelector v-model="platformStoreSelection" :options="platformStoreSelectorOptions" placeholder="选择门店" />
                  </label>
                </div>
              </template>
            </UnifiedQueryToolbar>
            <p v-if="stockQueryLoadError" class="inventory-load-error">{{ stockQueryLoadError }}</p>
          </section>
          <section v-if="operationalQueryPage && mode === 'store'" class="filter-card">
            <div class="filter-search"><Search :size="17" /><input v-model="keyword" :placeholder="`搜索${currentPage.title}记录`" @keyup.enter="queryStoreOperationalPage" /></div>
            <label>数据范围<select v-model="storeOperationalDataScope" @change="queryStoreOperationalPage"><option value="normal">正常数据</option><option value="all">全部数据</option></select></label>
            <label>状态<select v-model="storeOperationalStatus" @change="queryStoreOperationalPage"><option value="">全部</option><option v-for="status in storeOperationalStatusOptions" :key="status" :value="status">{{ status }}</option></select></label>
            <div v-if="usesBusinessDatePeriod()" class="inventory-period-filter"><UnifiedQueryDateRange :model-value="businessDateRange" :label="active === 'import' ? '导入日期周期' : '业务日期周期'" @change="applyBusinessDateRange" /></div>
            <div v-if="active === 'count'" class="count-period-filter"><UnifiedQueryDateRange :model-value="countDateRange" label="盘点日期周期" @change="applyCountDateRange" /></div>
            <button class="primary-button" :disabled="listLoading" @click="queryStoreOperationalPage">{{ listLoading ? '查询中' : '查询' }}</button>
          </section>
          <section v-else-if="active === 'recipe'" class="filter-card">
            <label>配方状态<select v-model="recipeStatus" @change="loadCurrentList"><option value="">全部</option><option value="1">启用中</option><option value="0">已停用</option></select></label>
            <div class="filter-search"><Search :size="17" /><input v-model="recipeKeyword" placeholder="搜索项目名称" @keyup.enter="loadCurrentList" /></div>
            <button class="primary-button" :disabled="listLoading" @click="loadCurrentList">{{ listLoading ? '查询中' : '查询' }}</button>
            <button class="secondary-button" :disabled="listLoading" @click="recipeStatus = ''; recipeKeyword = ''; loadCurrentList()">重置</button>
          </section>
          <section v-else-if="!(active === 'stock')" class="filter-card">
            <div class="filter-search"><Search :size="17" /><input v-model="keyword" :placeholder="`搜索${currentPage.title}记录`" @keyup.enter="loadCurrentList" /></div>
            <template v-if="mode === 'platform' && active === 'request'"><label>请货方<InventoryStoreSelector v-model="platformRequestPartySelection" :options="platformRequestPartySelectorOptions" placeholder="全部请货方" /></label><label>请货状态<select v-model="platformRequestStatus"><option value="">全部</option><option value="APPLIED">申请中</option><option value="PARTIAL">部分调拨</option><option value="DONE">已完成</option><option value="CANCELLED">已取消</option><option value="TERMINATED">已终止</option></select></label></template>
            <template v-else-if="mode === 'platform' && active === 'transfer'"><label>调出方<InventoryStoreSelector v-model="platformTransferFromParty" :options="platformWarehouseSelectorOptions" placeholder="全部调出方" /></label><label>调入方<InventoryStoreSelector v-model="platformTransferToParty" :options="platformWarehouseSelectorOptions" placeholder="全部调入方" /></label><label>调拨状态<select v-model="platformTransferStatus"><option value="">全部</option><option value="DRAFT">草稿</option><option value="DISPATCHED">在途</option><option value="RECEIVED">已收货</option><option value="CANCELLED">已取消</option><option value="REVERSED">已作废</option></select></label></template>
            <label v-else-if="mode === 'platform'">{{ active === 'usage' ? '门店' : '库存仓' }}<InventoryStoreSelector v-model="platformListWarehouseSelection" :options="active === 'usage' ? platformUsageStoreSelectorOptions : platformListWarehouseOptions" :placeholder="active === 'usage' ? '选择门店' : '选择门店库存仓'" :presentation="['inbound', 'outbound', 'count', 'usage'].includes(active) ? 'dropdown' : 'modal'" /></label>
            <label v-if="!(['inbound', 'outbound'].includes(active) || (mode === 'platform' && ['request', 'transfer'].includes(active)))" v-for="filter in currentPage.filters.slice(0, 2)" :key="filter">{{ filter }}<select><option>全部</option></select></label>
            <div v-if="usesBusinessDatePeriod()" class="inventory-period-filter"><UnifiedQueryDateRange :model-value="businessDateRange" :label="active === 'import' ? '导入日期周期' : '业务日期周期'" @change="applyBusinessDateRange" /></div>
            <div v-if="active === 'count'" class="count-period-filter"><UnifiedQueryDateRange :model-value="countDateRange" label="盘点日期周期" @change="applyCountDateRange" /></div>
            <button class="primary-button" :disabled="listLoading || (platformFilterRequiresHqLocation && !platformHqLocationId) || (platformUsageRequiresStore && !platformUsageStoreId)" @click="loadCurrentList">{{ listLoading ? '查询中' : '查询' }}</button>
          </section>
          <section class="content-card table-card"><header class="table-meta"><span>共 {{ remoteTotal }} 条记录</span><span v-if="listDateRange" class="table-meta__note">业务日期：{{ listDateRange.from }} 至 {{ listDateRange.to }}</span><div><button v-if="['store', 'platform'].includes(mode) && ['inbound', 'outbound'].includes(active)" class="text-button" @click="openEditor('import')">导入</button><span v-if="active !== 'stock' && active !== 'request'" class="table-meta__note">导出将在对应权威导出任务接入后开放</span></div></header><p v-if="listError" class="inventory-load-error">{{ listError }}</p><div v-else class="table-scroll"><table><thead><tr><th v-for="column in currentPage.columns" :key="column">{{ column }}</th><th>操作</th></tr></thead><tbody><tr v-if="listLoading"><td :colspan="currentPage.columns.length + 1" class="table-empty">正在读取库存数据...</td></tr><tr v-else-if="!currentRows.length"><td :colspan="currentPage.columns.length + 1" class="table-empty">暂无符合条件的记录</td></tr><tr v-else v-for="(row, index) in currentRows" :key="index"><td v-for="(cell, cellIndex) in row" :key="cellIndex"><span v-if="cellIndex === row.length - 2 && active === 'inbound'" class="state-tag">{{ cell }}</span><span v-else-if="cellIndex === row.length - 1 && ['outbound', 'count', 'request', 'transfer', 'usage', 'import'].includes(active)" class="state-tag">{{ cell }}</span><template v-else>{{ cell }}</template></td><td><template v-if="active === 'transfer'"><button class="text-button" @click="openTransferDetail(sourceRows[index])">查看</button><button v-if="sourceRows[index].can_dispatch" class="text-button" @click="runTransferAction('dispatch', sourceRows[index])">发货</button><button v-if="sourceRows[index].can_receive" class="text-button" @click="runTransferAction('receive', sourceRows[index])">收货</button><button v-if="sourceRows[index].can_cancel" class="text-button" @click="runTransferAction('cancel', sourceRows[index])">取消</button><button v-if="sourceRows[index].can_reverse" class="text-button" @click="runTransferAction('reverse', sourceRows[index])">作废</button></template><template v-else-if="active === 'request'"><button class="text-button" @click="openRequestDetail(sourceRows[index])">查看</button><button v-if="sourceRows[index].can_edit" class="text-button" @click="openRequestEditor(sourceRows[index])">编辑</button><button v-if="sourceRows[index].can_cancel" class="text-button" @click="runDocumentAction('cancelRequest', sourceRows[index], 'request')">取消</button><button v-if="sourceRows[index].can_terminate" class="text-button" @click="runDocumentAction('terminateRequest', sourceRows[index], 'request')">终止剩余</button></template><template v-else-if="['inbound', 'outbound'].includes(active)"><button class="text-button" @click="active === 'inbound' ? openInboundDetail(sourceRows[index]) : openOutboundDetail(sourceRows[index])">查看</button><button v-if="active === 'inbound'" class="text-button" @click="openInboundOutboundDetails(sourceRows[index])">出库明细</button><button v-if="sourceRows[index].can_void" class="text-button" @click="runDocumentAction('void', sourceRows[index], active)">作废</button></template><template v-else-if="active === 'recipe'"><button class="text-button" @click="openRecipeEditor(sourceRows[index])">编辑</button><button class="text-button" @click="toggleRecipeStatus(sourceRows[index])">{{ Number(sourceRows[index].status) === 1 ? '停用' : '启用' }}</button><button class="text-button text-button--danger" @click="deleteRecipe(sourceRows[index])">删除</button></template><button v-else class="text-button" @click="active === 'stock' ? openStockDetail(sourceRows[index]) : active === 'import' ? openImportDetail(sourceRows[index]) : openEditor(`${active}-detail`, sourceRows[index])">查看</button></td></tr></tbody></table></div></section>
        </template>
      </section>
    </main>

  </div>
</template>
