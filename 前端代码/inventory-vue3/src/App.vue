<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  AlertTriangle, ArrowDownToLine, ArrowUpFromLine,
  Boxes, ChartNoAxesCombined, ChevronRight, ClipboardCheck,
  Clock3, FileClock, FileSpreadsheet, FlaskConical, LayoutDashboard,
  Package2, Plus, QrCode, RotateCcw, Search, Trash2, Truck,
  SlidersHorizontal, Warehouse, X
} from '@lucide/vue'
import InventoryBusinessModal from './components/InventoryBusinessModal.vue'
import InventoryTransferDraftComposer from './components/InventoryTransferDraftComposer.vue'
import InventoryOutboundExport from './components/InventoryOutboundExport.vue'
import InventoryOperationalUnifiedQueryToolbar from './components/InventoryOperationalUnifiedQueryToolbar.vue'
import InventoryRecipeModal from './components/InventoryRecipeModal.vue'
import InventoryRemarkModal from './components/InventoryRemarkModal.vue'
import InventoryStoreSelector from './components/InventoryStoreSelector.vue'
import InventoryOrganizationStorePicker from './components/InventoryOrganizationStorePicker.vue'
import { formatBatchNumber } from './batchNumberDisplay'
import { formatIntegerQuantity } from './quantityFormat'
import { groupedDetailColumns, groupedDetailKey, groupedDisplayMoney, groupedLineCells } from './groupedDocumentRows'
import { createDocumentDetailReader } from './documentDetailReader'
import { preventInventoryDateKeyboardInput, preventInventoryDateTextTransfer } from './inventoryDatePickerOnly'
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
// 受理部门是总部供货单的责任路由；筛选必须先锁定总部供货，不能替代仓库筛选。
const platformRequestSupplyPartySelection = ref('ALL:0')
const warehouseChoice = ref('')
const warehouseSettingsStatus = ref('')
const warehouseChoiceKind = ref('')
const legacyRequestSupplyRow = ref(null)
const platformTransferFromStoreId = ref(0)
const platformTransferToStoreId = ref(0)
const platformTransferStatus = ref('')
// 调拨写入口只消费服务端能力；菜单可读不代表允许新建或发货。
const platformTransferCanCreate = ref(false)
// 通用仓库管理与各单据添加功能分开；导入仍使用原有管理能力，不能由添加节点推导。
const platformCanManageInventory = ref(false)
const platformCanManageWarehouses = ref(false)
// 后端按已有添加/编辑功能分别计算新建能力，不从菜单可见或任一写权限猜测。
const platformCreatePermissions = ref({})
const platformCanCreateCurrent = computed(() => !platformReadScopeMode.value && (active.value === 'warehouse' ? platformCanManageWarehouses.value : platformCanManageInventory.value || platformCreatePermissions.value[active.value] === true))
const platformTransferFromParty = ref('ALL:0')
const platformTransferToParty = ref('ALL:0')
const platformStockSubject = ref('HQ')
const platformHqLocationId = ref(0)
// The operational document lists use a warehouse descriptor so the filter
// can distinguish the headquarters warehouse from a store warehouse. Keep the
// stock page's numeric selector separate because it drives the stock query
// subject directly.
const platformListWarehouseSelection = ref('HQ:0')
// 组织只负责定位单店；0 始终表示既有的平台端总部查询。
const platformOrganizationPages = new Set(['inbound', 'outbound', 'request', 'transfer', 'usage'])
const platformOrganizationSelection = ref('HQ:0')
const platformOrganizationTree = ref([])
const platformOrganizationLoading = ref(false)
const platformSelectionType = computed(() => platformOrganizationSelection.value.split(':')[0])
const platformSelectionId = computed(() => Number(platformOrganizationSelection.value.split(':')[1]) || 0)
const platformReadScopeMode = computed(() => mode.value === 'platform' && platformOrganizationPages.has(active.value) && platformSelectionType.value !== 'HQ')
const platformLocations = ref([])
// 库存查询专用目录可覆盖全部有效门店；业务单据继续使用原组织授权目录。
const platformStockStoreLocations = ref([])
// 接收目录与调出库存权限分离；仅提供名称筛选，不扩大单据数据范围。
const platformTransferTargetOptions = ref([])
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
const storeOperationalStatus = ref('')
const storeTransferUnreceivedOnly = ref(false)
// 在途调拨是当前待收货清单，不能被历史调拨日期挡住；其他状态仍沿用已选周期。
const filtersBusinessDatePeriod = (page = active.value) => usesBusinessDatePeriod(page)
  && !(page === 'transfer' && (mode.value === 'store'
    ? storeOperationalStatus.value === '在途'
    : platformTransferStatus.value === 'DISPATCHED'))

// 平台将已发货未签收的调拨称为“未收货”；状态编码仍由服务端决定。
const transferStatusDisplay = (row) => mode.value === 'platform' && (row?.document_status === 'DISPATCHED' || ['DISPATCHED', '在途'].includes(row?.status_name))
  ? '未收货'
  : inventoryStatusLabel(row?.status_name)

// 快捷入口只改变当前列表筛选，不放宽服务端按岗位和仓库计算的数据权限。
async function showAllUnreceivedTransfers() {
  keyword.value = ''
  platformTransferFromParty.value = 'ALL:0'
  platformTransferToParty.value = 'ALL:0'
  platformTransferStatus.value = 'DISPATCHED'
  await loadCurrentList()
}
// 门店“未到货”仅查已发货且当前门店可收货的单据；再次点击恢复普通列表筛选。
async function showStoreUnreceivedTransfers() {
  storeTransferUnreceivedOnly.value = !storeTransferUnreceivedOnly.value
  storeOperationalStatus.value = storeTransferUnreceivedOnly.value ? '在途' : ''
  if (storeTransferUnreceivedOnly.value) keyword.value = ''
  await queryStoreOperationalPage()
}
const outboundTypeFilter = ref('')
const outboundFilterTypes = ['过期退货', '试用出库', '报废出库', '其他出库', '对内出库', '对外出库', '手工出库', '预售领用出库']
const outboundExecutedQuery = ref(null)
const recipeStatus = ref('')
const recipeKeyword = ref('')
const editor = ref('')
const remarkEditor = ref(null)
const remarkSaving = ref(false)
const remarkError = ref('')
const statusFocus = ref('')
const remoteRows = ref([])
const sourceRows = ref([])
const remoteTotal = ref(0)
// 门店单据列表按已确认的费用查询样式每页 100 张；总数取服务端，商品明细不参与分页。
const storeOperationalPage = ref(1)
const storeOperationalPageSize = 100
const storeOperationalPages = computed(() => Math.max(1, Math.ceil(Number(remoteTotal.value) / storeOperationalPageSize)))
const storeOperationalExecutedQuery = ref(null)
const storeListTableScroll = ref(null)
// 平台各普通列表共用一套 100 条分页；最终请求快照保证翻页不混入尚未提交的筛选或仓库变更。
const platformListPage = ref(1)
const platformListPageSize = 100
const platformListPages = computed(() => Math.max(1, Math.ceil(Number(remoteTotal.value) / platformListPageSize)))
const platformListExecutedRequest = ref(null)
const stockSummary = ref({ available_quantity: '0', inventory_amount_cents: null })
const stockTableLayout = ref({ fixed: false, summaryRow: false })
const listLoading = ref(false)
const listError = ref('')
const transferSavedNotice = ref('')
const transferListRefreshFailed = ref(false)
const transferRefreshAfterSave = ref(false)
const transferListRefreshReason = ref('')
const listDateRange = ref(null)
const groupedListPages = new Set(Object.keys(groupedDetailColumns))
const groupedDetails = ref({})
let groupedDetailVersion = 0
let groupedListVersion = 0
const batchAnalysis = ref({ list: [], expiry_buckets: [], age_buckets: [], query_cutoff_date: '' })
const analysisLoading = ref(false)
const analysisError = ref('')
const movementStatistics = ref({ list: [], count: 0 })
const movementStatisticsLoading = ref(false)
const movementStatisticsError = ref('')
const stockExecutedQuery = ref({})
const stockPage = ref(1)
// 两端库存查询按商品汇总行分页；金额与数量合计仍取服务端完整筛选范围。
const stockPageSize = 100
const stockPages = computed(() => Math.max(1, Math.ceil(Number(remoteTotal.value) / stockPageSize)))
const dashboard = ref({ stock_amount_cents: null, stock_product_count: 0, expiring_90_batch_count: 0, expiry_risk_buckets: [], count_pending_count: 0, request_pending_count: 0 })
const dashboardLoading = ref(false)
const dashboardWarehouseId = ref(0)
const dashboardError = ref('')
const selectedDetail = ref(null)
const statisticsQueryToolbar = ref(null)
const statisticsInitialQuery = ref({})
const transferActionConfirmation = ref(null)
const transferActionSubmitting = ref(false)
const transferBatchPreparing = ref(false)
const transferBatchError = ref('')
const selectedTransferIds = ref([])
const selectableTransferRows = computed(() => active.value === 'transfer'
  ? sourceRows.value.filter((row) => row.can_delete || row.can_dispatch || row.can_withdraw)
  : [])
const selectedTransferRows = computed(() => selectableTransferRows.value.filter((row) => selectedTransferIds.value.includes(Number(row.id))))
// "未收货" means stock was already dispatched; explain why another dispatch is unavailable.
const selectedTransferAlreadyDispatched = computed(() => selectedTransferRows.value.some((row) =>
  row.document_status === 'DISPATCHED' || (mode.value === 'platform' && ['DISPATCHED', '在途'].includes(row.status_name))))
const transferBatchSourceKey = (row) => `${row.from_party_type}:${Number(row.from_party_id)}:${Number(row.from_location_id || 0)}`

/** A batch uses one source and one legal lifecycle transition; the server repeats these checks under document locks. */
function canOperateTransferBatch(action) {
  const rows = selectedTransferRows.value
  const capability = { DELETE_DRAFT: 'can_delete', DISPATCH: 'can_dispatch', WITHDRAW: 'can_withdraw' }[action]
  return rows.length >= 2 && rows.every((row) => row[capability] && transferBatchSourceKey(row) === transferBatchSourceKey(rows[0]))
}

/** Explain a blocked command from authoritative status/capability fields; the server still validates every write. */
function transferBatchUnavailableReason(action) {
  const rows = selectedTransferRows.value
  if (!rows.length) return '请先勾选调拨单。'
  if (action === 'WITHDRAW') {
    if (rows.some((row) => row.document_status === 'DRAFT')) return '所选包含未发货的草稿，尚未扣减库存，无发货可撤回。'
    if (rows.some((row) => row.document_status === 'RECEIVED')) return '所选包含已收货的调拨单，不能撤回发货。'
  }
  if (action === 'DELETE_DRAFT') {
    if (rows.some((row) => Number(row.withdrawal_count) > 0)) return '所选包含曾发货并撤回的草稿，已有库存流水，不能删除；请逐张作废。'
    if (rows.some((row) => row.document_status === 'DISPATCHED')) return '所选包含已发货的调拨单，已有库存流水，不能删除。'
    if (rows.some((row) => row.document_status === 'RECEIVED')) return '所选包含已收货的调拨单，不能删除。'
  }
  if (action === 'DISPATCH' && selectedTransferAlreadyDispatched.value) return '所选包含已发货的调拨单，无需重复发货。'
  if (rows.length < 2) return '批量操作请至少选择两张调拨单。'
  if (rows.some((row) => transferBatchSourceKey(row) !== transferBatchSourceKey(rows[0]))) return '批量操作只能选择同一调出方的调拨单。'
  if (!canOperateTransferBatch(action)) return '所选调拨单的状态或操作权限不一致，请刷新后重新选择。'
  return ''
}

function toggleTransferSelection(row) {
  const id = Number(row.id)
  selectedTransferIds.value = selectedTransferIds.value.includes(id)
    ? selectedTransferIds.value.filter((selected) => selected !== id)
    : [...selectedTransferIds.value, id]
  transferBatchError.value = ''
}

function toggleAllVisibleTransfers() {
  const ids = selectableTransferRows.value.map((row) => Number(row.id))
  selectedTransferIds.value = ids.every((id) => selectedTransferIds.value.includes(id)) ? [] : ids
  transferBatchError.value = ''
}
const recipeActionConfirmation = ref(null)
const recipeActionSubmitting = ref(false)
const countDraftDeleteConfirmation = ref(null)
const countDraftDeleteBusy = ref(false)
const countDraftDeleteError = ref('')
const deferredPageKeys = new Set()
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
  ['product_name', '商品名称', 'text'], ['sku_name', '规格', 'text'], ['product_code', '商品编码', 'text'],
  ['barcode', '商品条码', 'text'], ['brand_name', '品牌', 'text'], ['category_name', '商品类别', 'text'],
  ['stock_unit', '单位', 'text'], ['batch_no', '批次号', 'text'], ['quality_status', '库存状态', 'text'],
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
  // 入出库列表不展示商品摘要；商品仍保留在权威单据明细中供“查看”追溯。
  inbound: { pageCode: 'inventory_inbound', fields: [['order_sn', '入库单号'], ['business_date', '入库日期', 'date'], ['order_type_name', '入库类型'], ['location_name', '入库仓/门店'], ['detail_count', '入库项数', 'integer'], ['status_name', '状态'], ['operation_at', '操作日期', 'datetime'], ['operator_name', '操作人']] },
  outbound: { pageCode: 'inventory_outbound', fields: [['order_sn', '出库单号'], ['business_date', '出库日期', 'date'], ['order_type_name', '出库类型'], ['store_name', '出库门店'], ['detail_count', '出库项数', 'integer'], ['status_name', '状态'], ['operation_at', '操作日期', 'datetime'], ['operator_name', '操作人']] },
  // 门店名称由服务端按单据所属仓的门店归属返回，不以当前页面门店或仓库名替代。
  count: { pageCode: 'inventory_count', fields: [['order_sn', '盘点单号'], ['store_name', '门店名称'], ['detail_count', '差异项', 'integer'], ['count_date', '盘点日期', 'date'], ['status_name', '状态'], ['operation_at', '操作日期', 'datetime'], ['operator_name', '操作人']] },
  request: { pageCode: 'inventory_request', fields: [['order_sn', '请货单号'], ['business_date', '请货日期', 'date'], ['request_party_name', '请货方'], ['supply_party_name', '供货方'], ['detail_count', '商品项数', 'integer'], ['status_name', '状态'], ['operation_at', '操作日期', 'datetime'], ['operator_name', '操作人']] },
  transfer: { pageCode: 'inventory_transfer', fields: [['order_sn', '调拨单号'], ['business_date', '调拨日期', 'date'], ['from_party_name', '调出方'], ['to_party_name', '调入方'], ['detail_count', '商品项数', 'integer'], ['status_name', '状态'], ['operation_at', '操作日期', 'datetime'], ['operator_name', '操作人']] },
  usage: { pageCode: 'inventory_salon_usage', fields: [['order_sn', '业务单号'], ['business_date', '领退日期', 'date'], ['operation_name', '领退类型'], ['project_name_snapshot', '关联项目'], ['detail_count', '耗材项数', 'integer'], ['remark', '备注'], ['status_name', '状态'], ['operation_at', '操作日期', 'datetime'], ['operator_name', '操作人']] },
  import: { pageCode: 'inventory_import', fields: [['source_file_name', '文件名称'], ['direction_name', '业务类型'], ['business_type', '出入库类型'], ['total_count', '数据量', 'integer'], ['success_count', '成功行数', 'integer'], ['failure_count', '失败行数', 'integer'], ['document_no', '正式单号'], ['status_name', '状态'], ['operation_at', '操作日期', 'datetime'], ['operator_name', '操作人']] },
}
const operationalQueryPage = computed(() => operationalQueryPages[active.value] || null)
const storeListPage = computed(() => mode.value === 'store' && (Boolean(operationalQueryPage.value) || active.value === 'stock'))
const inventoryListPage = computed(() => storeListPage.value || (mode.value === 'platform' && !['overview', 'statistics'].includes(active.value)))
const inventoryListCurrentPage = computed(() => active.value === 'stock' ? stockPage.value : mode.value === 'store' ? storeOperationalPage.value : platformListPage.value)
const inventoryListTotalPages = computed(() => active.value === 'stock' ? stockPages.value : mode.value === 'store' ? storeOperationalPages.value : platformListPages.value)
const inventoryListUnit = computed(() => ['stock', 'import', 'recipe', 'warehouse'].includes(active.value) ? '条' : '张')
const operationalQueryFields = computed(() => (operationalQueryPage.value?.fields || []).map(([key, label, type = 'text']) => ({ key, label, type, defaultVisible: true })))
const storeOperationalStatusOptions = computed(() => ({
  inbound: ['已完成', '已作废'],
  outbound: ['已完成', '已作废'],
  count: ['草稿', '已确认', '已取消'],
  request: ['申请中', '部分履约', '已完成', '已取消', '已终止剩余请货', '已作废'],
  transfer: ['草稿', '在途', '已收货', '已取消', '已作废'],
  usage: ['已完成', '已作废'],
  import: ['成功', '失败', '处理中']
}[active.value] || []))
const statisticsQueryPages = {
  inbound: { pageCode: 'inventory_statistics_inbound', fields: [['business_date', '业务日期', 'date'], ['source_type_name', '业务类型'], ['product_name', '商品名称'], ['sku_name', '规格'], ['stock_unit', '单位'], ['document_count', '单据数', 'integer'], ['movement_count', '流水数', 'integer'], ['quantity', '数量', 'decimal']] },
  outbound: { pageCode: 'inventory_statistics_outbound', fields: [['business_date', '业务日期', 'date'], ['source_type_name', '业务类型'], ['product_name', '商品名称'], ['sku_name', '规格'], ['stock_unit', '单位'], ['document_count', '单据数', 'integer'], ['movement_count', '流水数', 'integer'], ['quantity', '数量', 'decimal']] },
  expiry: { pageCode: 'inventory_statistics_expiry', fields: [['product_name', '商品名称'], ['sku_name', '规格'], ['stock_unit', '单位'], ['batch_no', '批次号'], ['batch_balance_quantity', '当前剩余库存', 'decimal'], ['expire_date', '到期日', 'date'], ['remaining_shelf_life_days', '距到期日天数', 'integer'], ['remaining_shelf_life_band', '保质期分档'], ['received_date', '正式入库日期', 'date']] },
  age: { pageCode: 'inventory_statistics_age', fields: [['product_name', '商品名称'], ['sku_name', '规格'], ['stock_unit', '单位'], ['batch_no', '批次号'], ['received_date', '正式入库日期', 'date'], ['inventory_age_days', '库龄天数', 'integer'], ['inventory_age_band', '库龄分档'], ['expire_date', '到期日', 'date']] },
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
    ? { subject: platformStockSubject.value, hq_location_id: platformStockSubject.value === 'HQ' ? Number(platformHqLocationId.value) : 0, ...(platformStockSubject.value === 'STORE' && Number(platformStoreId.value) > 0 ? { storeId: Number(platformStoreId.value) } : {}) }
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
    // Both hosts use the same stock quantity presets; warehouse scope is still enforced by the server.
    ...(field.key === 'available_quantity'
      ? { label: '库存数量', quickRangePresets: true }
      : {}),
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
  { key: 'recipe', label: '项目配方', icon: FlaskConical, action: '新建配方', platformOnly: true }
]

const pages = {
  inbound: {
    title: '入库管理',
    crumb: '库存管理 / 入库管理',
    filters: ['入库类型', '入库单号', '入库日期', '创建时间'],
    action: '新建入库',
    columns: ['入库单号', '入库日期', '入库类型', '入库仓/门店', '商品项数', '入库金额', '状态', '操作日期', '操作人']
  },
  outbound: {
    title: '出库管理',
    crumb: '库存管理 / 出库管理',
    filters: ['出库类型', '出库单号', '出库日期', '创建时间'],
    action: '新建出库',
    columns: ['出库单号', '出库日期', '出库类型', ...(mode.value === 'store' ? ['出库对象', '出库门店'] : ['出库仓/门店']), '商品项数', '成本金额', '状态', '操作日期', '操作人']
  },
  stock: {
    title: '库存查询',
    crumb: '库存管理 / 库存查询',
    filters: ['商品/条码', '库存区间', '院装产品', '隐藏零库存'],
    columns: [...(mode.value === 'platform' ? ['仓库', '批次号'] : []), '商品', '规格', '单位', '可用库存', '库存金额']
  },
  count: {
    title: '库存盘点',
    crumb: '库存管理 / 库存盘点',
    filters: ['盘点状态', '盘点单号', '盘点日期', '创建时间'],
    action: '新建盘点单',
    columns: ['盘点单号', mode.value === 'platform' ? '仓库/门店' : '门店名称', '盘点范围', '差异项', '盈亏金额', '盘点日期', '状态', '操作日期', '操作人']
  },
  request: {
    title: '请货管理',
    crumb: '库存管理 / 请货管理',
    filters: ['请货状态'],
    action: '新建请货',
    columns: ['请货单号', '请货日期', '请货方', '供货方', '商品项数', '状态', '操作日期', '操作人']
  },
  transfer: {
    title: '调拨管理',
    crumb: '库存管理 / 调拨管理',
    filters: ['调拨状态', '调拨单号', '调出方', '调拨时间'],
    action: '新建调拨',
    columns: ['调拨单号', '调拨日期', '调出方', '调入方', '商品项数', '状态', '操作日期', '操作人']
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
    columns: ['业务单号', '领退日期', '领退类型', '领用仓库', '关联项目', '耗材项数', '备注', '状态', '操作日期', '操作人']
  },
  import: {
    title: '导入记录',
    crumb: '库存管理 / 导入记录',
    filters: ['业务类型', '导入状态', '导入时间'],
    columns: ['文件名称', '业务类型', '出入库类型', '数据量', '成功行数', '失败行数', '正式单号', '状态', '操作日期', '操作人']
  }
}
const recognizedPageKeys = new Set(['overview', 'statistics', ...Object.keys(pages)])

const visibleMenus = computed(() => menuItems.filter((item) => {
  if (deferredPageKeys.has(item.key)) return false
  if (item.platformOnly) return mode.value === 'platform'
  return true
}))
const platformHqLocations = computed(() => platformLocations.value.filter((location) => String(location?.location_type || '') === 'HQ'))
const platformUsesHqSubject = computed(() => mode.value === 'platform' && ['inbound', 'outbound', 'count', 'request', 'transfer'].includes(active.value))
const platformActionRequiresHqLocation = computed(() => mode.value === 'platform' && ['inbound', 'outbound', 'count', 'request', 'transfer'].includes(active.value))
const platformUsageStoreId = computed(() => {
  const [type, rawId] = String(platformListWarehouseSelection.value || '').split(':', 2)
  return type === 'STORE' ? Number(rawId) || 0 : 0
})
const platformUsageRequiresWarehouse = computed(() => mode.value === 'platform' && active.value === 'usage')
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
  { key: '0', name: '全部门店', type: 'ALL' },
  ...platformStockStoreLocations.value.map((location) => ({
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
const platformHqListWarehouseOptions = computed(() => platformListWarehouseOptions.value.filter(option => option.type === 'HQ'))
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
  ...platformHqLocations.value.map((item) => ({ key: `HQ:${item.id}`, name: item.location_name, type: 'HQ' })),
  ...platformStoreOptions.value.map((location) => ({
    key: `STORE:${Number(location.store_id)}`,
    name: location.store_name_snapshot || location.store_name || `门店${location.store_id}`,
    type: 'STORE'
  }))
])
const platformRequestSupplyPartySelectorOptions = computed(() => [
  { key: 'ALL:0', name: '全部供货方', type: 'ALL' },
  ...platformRequestPartySelectorOptions.value
])

const platformWarehouseSelectorOptions = computed(() => [
  ...platformHqLocations.value.map((item) => ({ key: `HQ:${item.id}`, name: item.location_name, type: 'HQ' })),
  ...platformStoreOptions.value.map((location) => ({ key: `STORE:${Number(location.store_id)}`, name: location.store_name_snapshot || location.store_name || `门店${location.store_id}`, type: 'STORE' }))
])
const currentPage = computed(() => pages[active.value] || null)
const platformSelectedScopeName = computed(() => {
  if (!platformReadScopeMode.value) return ''
  const find = nodes => {
    for (const node of nodes || []) {
      if (`${node.node_type === 'org' ? 'ORG' : 'STORE'}:${node.id}` === platformOrganizationSelection.value) return node.name || node.title || ''
      const nested = find(node.children)
      if (nested) return nested
    }
    return ''
  }
  return find(platformOrganizationTree.value) || '所选范围'
})
const scopeName = computed(() => {
  if (mode.value === 'store') {
    const current = storeLocations.value.find((item) => Number(item.is_default) === 1) || storeLocations.value[0]
    return text(current?.store_name_snapshot || current?.store_name || current?.location_name, '当前门店')
  }
  if (platformReadScopeMode.value) return platformSelectedScopeName.value
  if (platformUsesHqSubject.value) {
    const headquarters = platformHqLocations.value.find((item) => Number(item.id) === Number(platformHqLocationId.value))
    return headquarters?.location_name || headquarters?.organization_name_snapshot || '总部仓'
  }
  if (active.value === 'usage') {
    return platformListWarehouseOptions.value.find((item) => item.key === platformListWarehouseSelection.value)?.name || '请选择领用仓库'
  }
  const locations = active.value === 'stock' ? platformStockStoreLocations.value : platformLocations.value
  const current = locations.find((item) => Number(item.store_id) === Number(platformStoreId.value))
  return current?.store_name_snapshot || current?.store_name || (active.value === 'stock' ? '全部门店' : '全部授权门店')
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
const stockTableFixed = computed(() => active.value === 'stock' && stockTableLayout.value.fixed === true)
const stockSummaryRow = computed(() => [
  ...(mode.value === 'platform' ? ['合计', '-'] : []),
  '合计',
  '-',
  '-',
  formatIntegerQuantity(stockSummary.value.available_quantity),
  centsMoney(stockSummary.value.inventory_amount_cents),
])
const analysisBuckets = computed(() => activeStatisticsTab.value === 'expiry'
  ? batchAnalysis.value.expiry_buckets
  : batchAnalysis.value.age_buckets)
const analysisRows = computed(() => batchAnalysis.value.list.map((row) => activeStatisticsTab.value === 'expiry'
  // AUTO 批号是免录商品的内部成本层身份，只在读模型中留空；原始值继续用于追溯和库存扣减。
  ? [text(row.product_name), text(row.sku_name), text(row.stock_unit), formatBatchNumber(row.batch_no), text(row.batch_balance_quantity), text(row.expire_date), text(row.remaining_shelf_life_days), text(row.remaining_shelf_life_band), row.inventory_amount === null ? '-' : money(row.inventory_amount)]
  : [text(row.product_name), text(row.sku_name), text(row.stock_unit), formatBatchNumber(row.batch_no), text(row.received_date), text(row.inventory_age_days), text(row.inventory_age_band), row.inventory_amount === null ? '-' : money(row.inventory_amount)]))
const movementStatisticRows = computed(() => movementStatistics.value.list.map((row) => [
  text(row.source_type_name), text(row.product_name), text(row.sku_name), text(row.stock_unit), text(row.document_count, '0'),
  text(row.movement_count, '0'), text(row.quantity, '0'), centsMoney(row.cost_amount_cents)
]))
const dashboardCards = computed(() => [
  { key: 'transit', label: '调拨在途', value: String(dashboard.value.in_transit_count || 0), note: centsMoney(dashboard.value.in_transit_amount_cents), tone: 'blue', target: 'transfer', focus: '在途' },
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
    transferSavedNotice.value = ''
    transferListRefreshFailed.value = false
    transferRefreshAfterSave.value = false
    transferListRefreshReason.value = ''
    return
  }
  active.value = key
  platformOrganizationSelection.value = 'HQ:0'
  if (mode.value === 'platform' && platformOrganizationPages.has(key)) platformListWarehouseSelection.value = `HQ:${Number(platformHqLocationId.value) || 0}`
  if (mode.value === 'platform' && platformOrganizationPages.has(key)) loadPlatformOrganizationScope(key)
  if (key !== 'transfer') {
    transferSavedNotice.value = ''
    transferListRefreshFailed.value = false
    transferRefreshAfterSave.value = false
    transferListRefreshReason.value = ''
  }
  // 单据明细只属于当前页面和查询；离开页面即丢弃旧响应。
  groupedListVersion += 1
  resetGroupedDetails()
  if (usesBusinessDatePeriod(key)) businessDateRange.value = todayRange()
  if (key === 'count') countDateRange.value = todayRange()
  if (key === 'statistics') statisticsDateRange.value = statisticsMonthRange()
  listDateRange.value = null
  statusFocus.value = options.focus || ''
  storeOperationalStatus.value = ''
  storeTransferUnreceivedOnly.value = false
  if (mode.value === 'store' && operationalQueryPages[key]) {
    storeOperationalPage.value = 1
    storeOperationalExecutedQuery.value = null
  }
  if (mode.value === 'platform') {
    platformListPage.value = 1
    platformListExecutedRequest.value = null
  }
  outboundTypeFilter.value = ''
  outboundExecutedQuery.value = null
  if (key === 'stock') {
    stockPage.value = 1
    stockExecutedQuery.value = {}
  }
  if (options.tab) activeStatisticsTab.value = options.tab
  if (key === 'statistics') {
    statisticsInitialQuery.value = options.query && typeof options.query === 'object' ? options.query : {}
    statisticsExecutedQuery.value = statisticsInitialQuery.value
  }
  sidebarOpen.value = false
  editor.value = ''
  if (key !== 'statistics' && key !== 'overview') {
    // 仓库选择已由列表查询校验；菜单切换不能调用废弃的领用仓初始化逻辑。
    // 在同一应用内切到调拨页也需加载接收目录，不依赖宿主重建 iframe。
    if (mode.value === 'platform' && key === 'transfer') loadPlatformTransferTargets()
    if (key === 'stock') stockUnifiedQuery.load()
    loadCurrentList()
  } else if (key === 'statistics' && mode.value === 'platform') {
    queryStatisticsPage(statisticsInitialQuery.value)
  } else if (key === 'overview') {
    // 概览在实际进入时加载，不让调拨等独立菜单依赖首页权限。
    loadDashboard()
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
    // 查看与编辑是两个明确操作；查看草稿不能隐式进入可修改的盘点表单。
    if (Number(row?.draft_id || 0) > 0) openCountDraftDetail(row)
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
  // 新建盘点多仓先确认；已有单据从行身份恢复原仓，不能携带明细换仓。
  if (mode.value === 'platform' && kind === 'count') {
    const options = platformHqLocations.value.filter((item) => item.location_status !== 'INACTIVE')
    if (!options.length) { listError.value = '当前岗位没有可操作的总部仓。'; return }
    if (options.length > 1) { warehouseChoice.value = ''; warehouseChoiceKind.value = kind; return }
    platformHqLocationId.value = Number(options[0].id)
  }
  if (kind !== 'import' && !isReadOnlyDetail && platformActionRequiresHqLocation.value && Number(platformHqLocationId.value) <= 0) {
    listError.value = '当前平台范围内没有可操作的总部仓。'
    return
  }
  selectedDetail.value = row
  editor.value = kind
}

async function enterChosenWarehouse() {
  const location = platformHqLocations.value.find((item) => Number(item.id) === Number(warehouseChoice.value) && item.location_status !== 'INACTIVE')
  if (!location || !warehouseChoiceKind.value) return
  if (warehouseChoiceKind.value === 'request-supply') {
    try { await platformInventoryApi.bindRequestSupplyWarehouse(legacyRequestSupplyRow.value.id, { hq_location_id: Number(location.id) }); warehouseChoiceKind.value = ''; await loadCurrentList() }
    catch (error) { listError.value = error instanceof Error ? error.message : '供货仓确认失败。' }
    return
  }
  platformHqLocationId.value = Number(location.id)
  selectedDetail.value = null
  editor.value = warehouseChoiceKind.value
  warehouseChoiceKind.value = ''
}

async function queryWarehouseSettings() {
  platformListPage.value = 1
  await loadCurrentList()
}

function openCurrentQuerySettings() {
  // 门店库存查询已移除设置入口；统计页仍保留原有标题区入口。
  if (active.value === 'statistics') statisticsQueryToolbar.value?.openSettings()
}

/** Carry only the request identity; both terminals reload the server-authorized direction and remaining lines. */
function openRequestTransfer(row) {
  if (!row?.can_transfer) return
  selectPage('transfer')
  if (row.supply_party_type === 'HQ' && Number(row.supply_location_id) > 0) platformHqLocationId.value = Number(row.supply_location_id)
  openEditor('transfer', { transfer_request_id: Number(row.id), transfer_source_type: row.supply_party_type, transfer_source_id: Number(row.supply_party_id || 0), transfer_source_location_id: Number(row.supply_location_id || 0) })
}

async function openTransferDetail(row) {
  try {
    selectedDetail.value = await currentDocumentDetailReader()('transfer', row)
    editor.value = 'transfer-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '调拨详情读取失败。'
  }
}

/** 两端读取同一草稿快照与版本令牌，授权只由后端能力决定。 */
async function openTransferEditor(row) {
  if (!row?.can_edit) return
  try {
    const detail = mode.value === 'platform'
      ? await platformInventoryApi.hqCrossTransferDetail(row.id, { hq_location_id: Number(row.from_party_type === 'HQ' ? row.from_location_id : row.to_party_type === 'HQ' ? row.to_location_id : platformHqLocationId.value) })
      : await inventoryApi.crossTransferDetail(row.id)
    if (!detail?.can_edit || !detail?.edit_token) throw new Error('该调拨单已不再是可编辑草稿，请刷新列表。')
    selectedDetail.value = detail
    editor.value = 'transfer-edit'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '调拨草稿读取失败。'
  }
}

async function openInboundDetail(row) {
  try {
    selectedDetail.value = await currentDocumentDetailReader()('inbound', row)
    editor.value = 'inbound-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '入库详情读取失败。'
  }
}

async function openInboundOutboundDetails(row) {
  try {
    selectedDetail.value = await currentDocumentDetailReader()('inboundTrace', row)
    editor.value = 'inbound-outbound-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '出库明细读取失败。'
  }
}

async function openOutboundDetail(row) {
  try {
    selectedDetail.value = await currentDocumentDetailReader()('outbound', row)
    editor.value = 'outbound-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '出库详情读取失败。'
  }
}

function resetGroupedDetails() {
  groupedDetailVersion += 1
  groupedDetails.value = {}
}

/** 查看弹窗与列表展开使用同一权限适配器；不维护两套详情 API 选择逻辑。 */
function currentDocumentDetailReader() {
  return createDocumentDetailReader({
    mode: mode.value, hqLocationId: platformHqLocationId.value, storeId: platformUsageStoreId.value,
    warehouseSelection: platformListWarehouseSelection.value,
    organizationReadMode: platformReadScopeMode.value,
    organizationStoreId: platformReadScopeMode.value && platformSelectionType.value === 'STORE' ? platformSelectionId.value : 0,
    storeApi: inventoryApi, platformApi: platformInventoryApi,
  })
}

/** Capture the detail's warehouse context so a later filter change cannot redirect a note command. */
function remarkScope(page, row) {
  if (mode.value !== 'platform') return {}
  if (page === 'usage') return Number(row.store_id) > 0
    ? { store_id: Number(row.store_id) }
    : { hq_location_id: Number(row.location_id) || 0 }
  if (page === 'inbound' || page === 'outbound') {
    const [subject, storeId] = String(platformListWarehouseSelection.value).split(':')
    return subject === 'STORE'
      ? { subject: 'STORE', storeId: Number(storeId) || 0, locationId: Number(row.location_id) || 0 }
      : { hq_location_id: Number(row.location_id) || Number(platformHqLocationId.value) || 0 }
  }
  return { hq_location_id: Number(platformHqLocationId.value) || 0 }
}

async function openRemarkEditor(page, row) {
  if (platformReadScopeMode.value) return
  remarkError.value = ''
  try {
    const scope = remarkScope(page, row)
    const id = String(row.source_id || row.id)
    const detail = await (mode.value === 'platform' ? platformInventoryApi : inventoryApi).documentRemarkDetail(page, id, scope)
    if (!detail?.remark_editable) throw new Error('该单据当前不可单独编辑备注。')
    remarkEditor.value = { page, row, id, scope, detail }
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '备注读取失败。'
  }
}

async function saveRemark(payload) {
  if (!remarkEditor.value || remarkSaving.value) return
  remarkSaving.value = true
  remarkError.value = ''
  try {
    const { id, scope } = remarkEditor.value
    await (mode.value === 'platform' ? platformInventoryApi : inventoryApi).saveDocumentRemark(id, payload, scope)
    remarkEditor.value = null
    await loadCurrentList()
  } catch (error) {
    remarkError.value = error instanceof Error ? error.message : '备注保存失败。'
  } finally {
    remarkSaving.value = false
  }
}

/** 两端只展开本次列表返回的单据；原权限接口、有限并发及版本保护阻止越权和旧结果串页。 */
async function loadGroupedDetails(page, rows) {
  const version = ++groupedDetailVersion
  const pending = rows.map((row) => ({ row, key: groupedDetailKey(row) }))
  groupedDetails.value = Object.fromEntries(pending.map(({ key }) => [key, { status: 'loading' }]))
  const readDetail = currentDocumentDetailReader()
  let next = 0
  const worker = async () => {
    while (next < pending.length && version === groupedDetailVersion) {
      const { row, key } = pending[next++]
      try {
        const detail = await readDetail(page, row)
        if (version === groupedDetailVersion) {
          groupedDetails.value = { ...groupedDetails.value, [key]: { status: 'ready', detail, lines: Array.isArray(detail?.lines) ? detail.lines : [] } }
          // The usage header remark column must follow the same current projection as expanded lines.
          if (page === 'usage' && active.value === page) {
            sourceRows.value = sourceRows.value.map((item) => groupedDetailKey(item) === key ? { ...item, remark: detail?.document?.remark || '' } : item)
            remoteRows.value = sourceRows.value.map((item) => mapApiRow(page, item))
          }
        }
      } catch (error) {
        if (version === groupedDetailVersion) {
          groupedDetails.value = { ...groupedDetails.value, [key]: { status: 'error', message: error instanceof Error ? error.message : '单据明细读取失败。' } }
        }
      }
    }
  }
  await Promise.all(Array.from({ length: Math.min(4, pending.length) }, () => worker()))
}

async function openRequestDetail(row) {
  try {
    selectedDetail.value = await currentDocumentDetailReader()('request', row)
    editor.value = 'request-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '请货详情读取失败。'
  }
}

async function openCountDetail(row) {
  if (mode.value === 'platform' && Number(row.location_id) > 0) platformHqLocationId.value = Number(row.location_id)
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
  if (mode.value === 'platform' && Number(row.location_id) > 0) platformHqLocationId.value = Number(row.location_id)
  try {
    // A draft ID is not a settled count document ID; read the creator-scoped draft endpoint.
    selectedDetail.value = await readCountDraft(row.draft_id)
    editor.value = 'count-edit'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '盘点草稿读取失败。'
  }
}

async function openCountDraftDetail(row) {
  if (mode.value === 'platform' && Number(row.location_id) > 0) platformHqLocationId.value = Number(row.location_id)
  try {
    // 只读草稿仍使用创建人范围的服务端详情，不自动加载商品目录或变动库存。
    selectedDetail.value = await readCountDraft(row.draft_id)
    editor.value = 'count-draft-detail'
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '盘点草稿读取失败。'
  }
}

/** 草稿按当前登录终端及总部仓读取，不拿草稿ID冒充已确认单据。 */
function readCountDraft(id) {
  return mode.value === 'platform'
    ? platformInventoryApi.hqCountDraftDetail(id, { hq_location_id: Number(platformHqLocationId.value) })
    : inventoryApi.countDraftDetail(id)
}

/** 删除前先读创建人可见的最新草稿版本，防止旧列表操作误删并发修改后的内容。 */
async function requestDeleteCountDraft(row) {
  if (active.value !== 'count' || Number(row?.draft_id || 0) <= 0 || countDraftDeleteBusy.value) return
  countDraftDeleteBusy.value = true
  countDraftDeleteError.value = ''
  try {
    if (mode.value === 'platform' && Number(row.location_id) > 0) platformHqLocationId.value = Number(row.location_id)
    const detail = await readCountDraft(row.draft_id)
    countDraftDeleteConfirmation.value = { id: Number(detail.draft_id), version: Number(detail.version) }
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '读取盘点草稿失败。'
  } finally {
    countDraftDeleteBusy.value = false
  }
}

/** 确认后仅调用草稿物理删除接口；已确认盘点单不提供此命令。 */
async function confirmDeleteCountDraft() {
  const target = countDraftDeleteConfirmation.value
  if (!target || countDraftDeleteBusy.value) return
  countDraftDeleteBusy.value = true
  countDraftDeleteError.value = ''
  try {
    if (mode.value === 'platform') await platformInventoryApi.deleteHqCountDraft(target.id, target.version, platformHqLocationId.value)
    else await inventoryApi.deleteCountDraft(target.id, target.version)
    countDraftDeleteConfirmation.value = null
    await loadCurrentList()
  } catch (error) {
    countDraftDeleteError.value = error instanceof Error ? error.message : '删除盘点草稿失败。'
  } finally {
    countDraftDeleteBusy.value = false
  }
}

async function openUsageDetail(row) {
  try {
    selectedDetail.value = await currentDocumentDetailReader()('usage', row)
    editor.value = 'usage-detail'
  } catch (error) { listError.value = error instanceof Error ? error.message : '院装详情读取失败。' }
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

/** Batch confirmation freezes each selected draft version; the server validates all documents again under locks. */
async function openBulkTransferAction(action) {
  if (transferBatchPreparing.value || transferActionSubmitting.value) return
  const unavailableReason = transferBatchUnavailableReason(action)
  if (unavailableReason) { transferBatchError.value = unavailableReason; return }
  const rows = [...selectedTransferRows.value]
  const listVersion = groupedListVersion
  transferBatchPreparing.value = true
  transferBatchError.value = ''
  try {
    const documents = []
    for (let offset = 0; offset < rows.length; offset += 8) {
      const chunk = rows.slice(offset, offset + 8)
      const prepared = await Promise.all(chunk.map(async (row) => {
        if (action === 'WITHDRAW') return { id: Number(row.id), expected_withdrawal_count: Number(row.withdrawal_count) }
        const detail = mode.value === 'platform'
          ? await platformInventoryApi.hqCrossTransferDetail(row.id, { hq_location_id: Number(row.from_party_type === 'HQ' ? row.from_location_id : platformHqLocationId.value) })
          : await inventoryApi.crossTransferDetail(row.id)
        if (!detail?.can_edit || (action === 'DELETE_DRAFT' && !detail?.can_delete) || !detail?.edit_token) {
          throw new Error(`单据 ${row.order_sn || row.id} 已变化，请刷新后重新选择。`)
        }
        return { id: Number(row.id), expected_edit_token: detail.edit_token }
      }))
      documents.push(...prepared)
    }
    if (listVersion !== groupedListVersion || active.value !== 'transfer') return
    const labels = { DELETE_DRAFT: '批量删除草稿', DISPATCH: '批量发货', WITHDRAW: '批量撤回发货' }
    transferActionConfirmation.value = {
      batchAction: action, rows, documents, label: labels[action], error: '',
      idempotencyKey: action === 'WITHDRAW' ? inventoryCommandIdempotencyKey() : ''
    }
  } catch (error) {
    transferBatchError.value = error instanceof Error ? error.message : '批量操作状态读取失败。'
  } finally {
    transferBatchPreparing.value = false
  }
}

// Confirm against a fresh draft token so a tab cannot delete a subsequently edited or dispatched transfer.
async function requestDeleteTransferDraft(row) {
  if (!row?.can_delete) return
  try {
    const detail = mode.value === 'platform'
      ? await platformInventoryApi.hqCrossTransferDetail(row.id, { hq_location_id: Number(row.from_party_type === 'HQ' ? row.from_location_id : row.to_party_type === 'HQ' ? row.to_location_id : platformHqLocationId.value) })
      : await inventoryApi.crossTransferDetail(row.id)
    if (!detail?.can_delete || !detail?.edit_token) throw new Error('该调拨单已不再是可删除草稿，请刷新列表。')
    transferActionConfirmation.value = {
      action: 'deleteDraft', row, documentType: 'transfer', label: '删除调拨草稿',
      expectedEditToken: detail.edit_token, requiresReason: false, reason: '', error: ''
    }
  } catch (error) {
    listError.value = error instanceof Error ? error.message : '调拨草稿状态读取失败。'
  }
}

function runDocumentAction(action, row, documentType = active.value) {
  const labels = {
    dispatch: '发货', receive: '确认收货', withdraw: '撤回发货',
    void: `作废${{ inbound: '入库单', outbound: '出库单', transfer: '调拨单', usage: '院装领用单' }[documentType] || '单据'}`,
    voidRequest: '作废请货单'
  }
  const requiresReason = ['void', 'voidRequest'].includes(action)
  // 撤回与作废使用稳定命令键；同一弹窗重试不能重复回补库存或重复记审计。
  transferActionConfirmation.value = {
    action,
    row,
    documentType,
    label: labels[action] || '执行该操作',
    requiresReason,
    reason: '',
    idempotencyKey: requiresReason || action === 'withdraw' ? inventoryCommandIdempotencyKey() : ''
  }
}

function cancelTransferAction() {
  if (transferActionSubmitting.value) return
  transferActionConfirmation.value = null
}

async function confirmTransferAction() {
  const confirmation = transferActionConfirmation.value
  if (!confirmation || transferActionSubmitting.value) return
  if (confirmation.batchAction) {
    transferActionSubmitting.value = true
    try {
      const payload = {
        action: confirmation.batchAction,
        documents: confirmation.documents,
        idempotency_key: confirmation.idempotencyKey
      }
      if (mode.value === 'platform') {
        payload.hq_location_id = Number(confirmation.rows[0].from_party_type === 'HQ'
          ? confirmation.rows[0].from_location_id : platformHqLocationId.value)
        await platformInventoryApi.operateHqCrossTransferBatch(payload)
      } else {
        await inventoryApi.operateCrossTransferBatch(payload)
      }
      selectedTransferIds.value = []
      transferActionConfirmation.value = null
      await loadCurrentList()
    } catch (error) {
      confirmation.error = error instanceof Error ? error.message : '批量操作未完成，所有单据均保持原状。'
    } finally {
      transferActionSubmitting.value = false
    }
    return
  }
  const { action, row, documentType } = confirmation
  const reason = String(confirmation.reason || '').trim()
  if (confirmation.requiresReason && reason.length < 2) return
  transferActionSubmitting.value = true
  let succeeded = false
  try {
    const command = { idempotency_key: confirmation.idempotencyKey, reason }
    const sourceId = row.source_id || row.id
    if (mode.value === 'platform') {
      const payload = { hq_location_id: Number(action === 'receive' && row.to_party_type === 'HQ' ? row.to_location_id : row.from_party_type === 'HQ' ? row.from_location_id : row.location_id || platformHqLocationId.value) }
      if (action === 'dispatch') await platformInventoryApi.dispatchHqCrossTransfer(row.id, payload)
      else if (action === 'receive') await platformInventoryApi.receiveHqCrossTransfer(row.id, payload)
      else if (action === 'withdraw') await platformInventoryApi.withdrawHqCrossTransfer(row.id, { ...payload, idempotency_key: confirmation.idempotencyKey })
      else if (action === 'deleteDraft') await platformInventoryApi.deleteHqCrossTransferDraft(row.id, { ...payload, expected_edit_token: confirmation.expectedEditToken })
      else if (action === 'void' && documentType === 'transfer') await platformInventoryApi.voidWithdrawnHqCrossTransfer(row.id, { ...payload, ...command })
      else if (action === 'void' && documentType === 'inbound') await platformInventoryApi.voidHqInbound(sourceId, { ...payload, ...command })
      else if (action === 'void' && documentType === 'outbound') await platformInventoryApi.voidHqOutbound(sourceId, { ...payload, ...command })
      else if (action === 'void' && documentType === 'usage') await platformInventoryApi.voidSalonUsage(row.id, { ...(Number(row.store_id) > 0 ? { store_id: Number(row.store_id) } : { hq_location_id: Number(row.location_id) }), ...command })
      else if (action === 'voidRequest') await platformInventoryApi.voidHqRequest(row.id, { ...payload, ...command })
    } else if (action === 'dispatch') await inventoryApi.dispatchCrossTransfer(row.id)
    else if (action === 'receive') await inventoryApi.receiveCrossTransfer(row.id)
    else if (action === 'withdraw') await inventoryApi.withdrawCrossTransfer(row.id, { idempotency_key: confirmation.idempotencyKey })
    else if (action === 'deleteDraft') await inventoryApi.deleteCrossTransferDraft(row.id, { expected_edit_token: confirmation.expectedEditToken })
    else if (action === 'void' && documentType === 'transfer') await inventoryApi.voidWithdrawnCrossTransfer(row.id, command)
    else if (action === 'void' && documentType === 'inbound') await inventoryApi.voidInbound(sourceId, command)
    else if (action === 'void' && documentType === 'outbound') await inventoryApi.voidOutbound(sourceId, command)
    else if (action === 'void' && documentType === 'usage') await inventoryApi.voidSalonUsage(row.id, command)
    else if (action === 'voidRequest') await inventoryApi.voidRequest(row.id, command)
    await loadCurrentList()
    succeeded = true
  } catch (error) {
    const message = error instanceof Error ? error.message : '调拨操作未完成。'
    if (action === 'deleteDraft') confirmation.error = message
    else listError.value = message
  } finally {
    transferActionSubmitting.value = false
    if (succeeded || action !== 'deleteDraft') transferActionConfirmation.value = null
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

function openGroupedRowDetail(page, row) {
  if (page === 'inbound') return openInboundDetail(row)
  if (page === 'outbound') return openOutboundDetail(row)
  if (page === 'request') return openRequestDetail(row)
  if (page === 'transfer') return openTransferDetail(row)
  return openUsageDetail(row)
}

function operationTime(value) {
  const seconds = Number(value)
  return Number.isFinite(seconds) && seconds > 0 ? new Date(seconds * 1000).toLocaleString() : '-'
}

function mapApiRow(page, row) {
  switch (page) {
    // 入出库分组行与商品明细共用整数元展示口径，权威成本仍以分为单位保存和计算。
    case 'inbound': return [text(row.order_sn), text(row.business_date), text(row.order_type_name), text(platformReadScopeMode.value ? row.store_name || row.location_name : row.location_name), `${text(row.detail_count, '0')} 项`, groupedDisplayMoney(row.cost_amount_cents), inventoryStatusLabel(row.status_name, '已完成'), operationTime(row.operation_at), text(row.operator_name)]
    // Store headers show the saved optional recipient before the owning store; HQ columns stay unchanged.
    case 'outbound': return [text(row.order_sn), text(row.business_date), text(row.order_type_name), ...(mode.value === 'store' ? [text(row.outbound_recipient), text(row.store_name)] : [text(platformReadScopeMode.value ? row.store_name || row.location_name : row.location_name)]), `${text(row.detail_count, '0')} 项`, groupedDisplayMoney(row.cost_amount_cents), inventoryStatusLabel(row.status_name, '已完成'), operationTime(row.operation_at), text(row.operator_name)]
    case 'stock': return row.available_quantity !== undefined
      ? [...(mode.value === 'platform' ? [text(row.location_name), formatBatchNumber(row.batch_no)] : []), text(row.product_name), text(row.sku_name), text(row.stock_unit), text(row.available_quantity), row.inventory_amount_cents !== undefined ? centsMoney(row.inventory_amount_cents) : money(row.inventory_amount)]
      : [...(mode.value === 'platform' ? [text(row.location_name), formatBatchNumber(row.batch_no)] : []), text(row.product_name), text(row.sku_name), text(row.stock_unit), text(row.batch_balance_quantity), row.inventory_amount === null ? '-' : money(row.inventory_amount)]
    // 只展示该行的权威名称；缺失时留空占位，避免将仓库或当前筛选范围冒充单据门店。
    case 'count': return [text(row.order_sn), text(mode.value === 'platform' ? row.location_name || row.store_name : row.store_name), text(row.count_type || '全盘'), text(row.detail_count), centsMoney(row.change_amount_cents), text(row.count_date), countStatusName(row.status_name), operationTime(row.operation_at), text(row.operator_name)]
    case 'request': return [text(row.order_sn), text(row.request_date || row.business_date), text(row.request_party_name || row.request_store_name), text(row.supply_party_name || row.supply_store_name), text(row.detail_count), inventoryStatusLabel(row.status_name), operationTime(row.operation_at), text(row.operator_name || row.operator_name_snapshot)]
    case 'transfer': return [text(row.order_sn), text(row.business_date), text(row.from_party_name || row.from_store_name), text(row.to_party_name || row.to_store_name), text(row.detail_count), transferStatusDisplay(row), operationTime(row.operation_at), text(row.operator_name)]
    // 项目为空是合法的院装领退单据；列表须明确显示未关联，不能误认为缺失数据。
    case 'usage': return [text(row.usage_no), text(row.business_date), text(row.operation_type === 'RETURN' ? '退回' : '领用'), text(platformReadScopeMode.value ? row.store_name || row.location_name : row.location_name), text(row.project_name_snapshot, '未关联项目'), `${text(row.detail_count, '0')} 项`, text(row.remark), inventoryStatusLabel(row.document_status, '已完成'), operationTime(row.operation_at), text(row.operator_name)]
    case 'import': return [text(row.source_file_name), row.direction === 'inbound' ? '入库导入' : '出库导入', text(row.business_type), text(row.total_count, '0'), text(row.success_count, '0'), text(row.failure_count, '0'), text(row.document_no), text(row.status === 'SUCCEEDED' ? '成功' : row.status === 'FAILED' ? '失败' : '处理中'), operationTime(row.operation_at || row.updated_at), text(row.operator_name)]
    case 'recipe': return [text(row.id), text(row.project_name), text(row.consumable_count, '0'), text(row.version), inventoryStatusLabel(row.status_name), text(row.update_time)]
    case 'warehouse': return [text(row.location_code), text(row.location_name), row.location_type === 'HQ' ? '总部仓' : '门店仓', text(row.store_name_snapshot || row.organization_name_snapshot), Number(row.is_default) === 1 ? '是' : '否', row.location_status === 'ACTIVE' ? '启用' : '停用']
    default: return []
  }
}

/** Status can no longer be inferred from the last cell because audit columns follow it. */
function isOperationalStatusCell(page, cellIndex) {
  return { inbound: 6, outbound: mode.value === 'store' ? 7 : 6, count: 6, request: 5, transfer: 5, usage: 7, import: 7 }[page] === cellIndex
}

/** The period label names the business event filtered by the backend. */
function businessDatePeriodLabel(page) {
  return { inbound: '入库日期周期', outbound: '出库日期周期', request: '请货日期周期', transfer: '调拨日期周期', usage: '领退日期周期', import: '导入日期周期' }[page] || '业务日期周期'
}

function countStatusName(status) {
  // Keep the explicit count labels here for the existing contract; all other
  // inventory document statuses share the central human-readable projection.
  return { DRAFT: '草稿', CONFIRMED: '已确认', CANCELLED: '已取消' }[String(status || '')] || inventoryStatusLabel(status)
}

/** 盘点列表显示中文状态，但统一查询读取的是权威单据状态码；避免筛选条件永远不命中。 */
function operationalStatusFilterValue() {
  if (active.value !== 'count') return storeOperationalStatus.value
  return { 草稿: 'DRAFT', 已确认: 'CONFIRMED', 已取消: 'CANCELLED' }[storeOperationalStatus.value] || ''
}

/** 草稿没有完成日期；只在选择草稿时清空周期，退出草稿筛选恢复当天默认值。 */
function onStoreOperationalStatusChanged() {
  if (active.value === 'transfer') storeTransferUnreceivedOnly.value = false
  if (active.value === 'count') {
    countDateRange.value = storeOperationalStatus.value === '草稿' ? { min: '', max: '' } : todayRange()
  }
  queryStoreOperationalPage()
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

/** 平台分页请求保留原业务入口，仓库、组织与写能力仍由各入口在后端核验。 */
function fetchPlatformListPage(request) {
  const { kind, payload } = request
  if (kind === 'unified') return platformInventoryApi.unifiedOperationalQuery(payload)
  if (kind === 'hq-inbound') return platformInventoryApi.listHqInbound(payload)
  if (kind === 'hq-outbound') return platformInventoryApi.listHqOutbound(payload)
  if (kind === 'hq-transfer') return platformInventoryApi.listHqCrossTransfers(payload)
  if (kind === 'warehouse') return platformInventoryApi.list('warehouseSettings', payload)
  return platformInventoryApi.list(kind, payload)
}

async function loadCurrentList(unifiedQuery = null, { reuseStoreOperationalQuery = false, reusePlatformListQuery = false } = {}) {
  if (mode.value === 'platform' && active.value === 'transfer') platformTransferCanCreate.value = false
  if (active.value === 'transfer') {
    selectedTransferIds.value = []
    transferBatchError.value = ''
  }
  if (active.value === 'overview' || active.value === 'statistics') return
  // 分组呈现按单据类型选择，不按终端分支；平台与门店共用下面唯一的表格模板。
  const groupedPage = groupedListPages.has(active.value) ? active.value : ''
  const groupedRequest = groupedPage ? ++groupedListVersion : 0
  listLoading.value = true
  listError.value = ''
  if (mode.value === 'platform' && active.value === 'usage' && !reusePlatformListQuery && !platformReadScopeMode.value && !platformListWarehouseOptions.value.some((item) => item.key === platformListWarehouseSelection.value)) {
    sourceRows.value = []
    remoteRows.value = []
    remoteTotal.value = 0
    listLoading.value = false
    listError.value = '当前岗位没有可查询的领用仓库。'
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
    }
    if (mode.value === 'platform' && active.value !== 'stock') {
      query.page = reusePlatformListQuery ? platformListPage.value : 1
      query.limit = platformListPageSize
    }
    if (mode.value === 'platform' && active.value === 'stock') {
      query.subject = platformStockSubject.value
      if (platformStockSubject.value === 'HQ') query.hq_location_id = Number(platformHqLocationId.value)
      if (platformStockSubject.value === 'STORE' && Number(platformStoreId.value) > 0) query.storeId = Number(platformStoreId.value)
    }
    if (active.value === 'stock') {
      query.page = stockPage.value
      query.limit = stockPageSize
    }
    if (mode.value === 'store' && operationalQueryPage.value) {
      query.page = Math.max(1, Number(query.page) || storeOperationalPage.value)
      query.limit = storeOperationalPageSize
    }
    if (mode.value === 'platform' && active.value === 'import') {
      const [type, id] = platformListWarehouseSelection.value.split(':')
      if (type === 'HQ') query.hq_location_id = Number(id)
      else query.store_id = Number(id)
    }
    if (mode.value === 'platform' && active.value === 'request') {
      const [partyType, partyId] = String(platformRequestPartySelection.value || 'ALL:0').split(':')
      if (partyType === 'HQ' || partyType === 'STORE') {
        query.request_party_type = partyType
        query.request_party_id = partyType === 'HQ' ? 0 : Number(partyId) || 0
        if (partyType === 'HQ') query.request_location_id = Number(partyId)
      }
      const [supplyType, supplyId] = String(platformRequestSupplyPartySelection.value || 'ALL:0').split(':')
      if (supplyType === 'HQ' || supplyType === 'STORE') {
        query.supply_party_type = supplyType
        query.supply_party_id = supplyType === 'HQ' ? 0 : Number(supplyId) || 0
        if (supplyType === 'HQ') query.supply_location_id = Number(supplyId)
      }
      if (platformRequestStatus.value) query.status = platformRequestStatus.value
    }
    if (mode.value === 'platform' && active.value === 'transfer') {
      const [fromType, fromId] = String(platformTransferFromParty.value || 'ALL:0').split(':')
      const [toType, toId] = String(platformTransferToParty.value || 'ALL:0').split(':')
      if (fromType === 'HQ' || fromType === 'STORE') { query.from_party_type = fromType; query.from_party_id = fromType === 'HQ' ? 0 : Number(fromId) || 0; if (fromType === 'HQ') query.from_location_id = Number(fromId) }
      if (toType === 'HQ' || toType === 'STORE') { query.to_party_type = toType; query.to_party_id = toType === 'HQ' ? 0 : Number(toId) || 0; if (toType === 'HQ') query.to_location_id = Number(toId) }
      if (platformTransferStatus.value) query.status = platformTransferStatus.value
    }
    const businessPeriod = businessDateRange.value
    if (filtersBusinessDatePeriod() && mode.value === 'platform' && businessPeriod.min && businessPeriod.max) {
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
            ...(filtersBusinessDatePeriod() && businessPeriod.min && businessPeriod.max
              ? [{ field: 'business_date', operator: 'between', value: [businessPeriod.min, businessPeriod.max] }]
              : [])
          ] }
      : { hq_location_id: Number(listWarehouseId) || Number(platformHqLocationId.value), ...query }
    const selectedStoreQuery = platformReadScopeMode.value ? {
      keyword: query.keyword, pageCode: active.value === 'usage' ? 'inventory_salon_usage' : `inventory_${active.value}`,
      subject: 'STORE', ...(platformSelectionType.value === 'ORG' ? { organizationId: platformSelectionId.value } : { storeId: platformSelectionId.value }),
      page: Number(query.page) || 1, limit: platformListPageSize,
      topFilterConditions: [
        ...(Array.isArray(unifiedOperationalQuery.topFilterConditions) ? unifiedOperationalQuery.topFilterConditions : []),
        ...(filtersBusinessDatePeriod() && businessPeriod.min && businessPeriod.max
          ? [{ field: 'business_date', operator: 'between', value: [businessPeriod.min, businessPeriod.max] }] : [])
      ]
    } : null
    // One payload drives both the document list and the frozen export, including permission-neutral filters.
    const storeDocumentQuery = {
      ...query, pageCode: operationalQueryPage.value?.pageCode, dataScope: 'normal',
      topFilterConditions: [
        ...(Array.isArray(query.topFilterConditions) ? query.topFilterConditions : []),
        ...(operationalStatusFilterValue() ? [{ field: 'status_name', operator: 'eq', value: operationalStatusFilterValue() }] : []),
        ...(active.value === 'transfer' && mode.value === 'store' && storeTransferUnreceivedOnly.value
          ? [{ field: 'can_receive', operator: 'eq', value: true }] : []),
        ...(active.value === 'outbound' && outboundTypeFilter.value ? [{ field: 'order_type_name', operator: 'eq', value: outboundTypeFilter.value }] : []),
        ...countDateConditions,
        ...(filtersBusinessDatePeriod() && businessPeriod.min && businessPeriod.max
          ? [{ field: 'business_date', operator: 'between', value: [businessPeriod.min, businessPeriod.max] }] : [])
      ]
    }
    // 所有门店单据翻页重放已提交筛选；输入框尚未查询的变更不能悄悄改变下一页或导出。
    const requestedStoreDocumentQuery = mode.value === 'store' && operationalQueryPage.value && reuseStoreOperationalQuery && storeOperationalExecutedQuery.value
      ? { ...storeOperationalExecutedQuery.value, page: query.page, limit: storeOperationalPageSize }
      : storeDocumentQuery
    const currentPlatformRequest = mode.value === 'platform' && active.value !== 'stock'
      ? selectedStoreQuery ? { kind: 'unified', payload: selectedStoreQuery, readOnlyStore: true }
        : ['inbound', 'outbound'].includes(active.value) ? { kind: listWarehouseType === 'STORE' ? 'unified' : `hq-${active.value}`, payload: listWarehouseQuery }
          : active.value === 'count' ? { kind: 'unified', payload: platformCountQuery }
            : active.value === 'transfer' ? { kind: 'hq-transfer', payload: { hq_location_id: Number(platformHqLocationId.value), ...query } }
              : active.value === 'request' ? { kind: 'request', payload: { hq_location_id: Number(platformHqLocationId.value), ...query } }
                : active.value === 'usage' ? { kind: 'usage', payload: { ...query, ...(listWarehouseType === 'HQ' ? { hq_location_id: Number(listWarehouseId) } : { store_id: Number(listWarehouseId) }) } }
                  : active.value === 'warehouse' ? { kind: 'warehouse', payload: { keyword: query.keyword || '', status: warehouseSettingsStatus.value, page: query.page, limit: platformListPageSize } }
                    : { kind: active.value, payload: query }
      : null
    // 查询快照包含最终后端入口及仓库范围；仅翻页时覆盖页码，避免表单草稿改变已提交结果。
    const requestedPlatformRequest = currentPlatformRequest && reusePlatformListQuery && platformListExecutedRequest.value
      ? { ...platformListExecutedRequest.value, payload: { ...platformListExecutedRequest.value.payload, page: query.page, limit: platformListPageSize } }
      : currentPlatformRequest
    const response = requestedPlatformRequest
      ? await fetchPlatformListPage(requestedPlatformRequest)
      : operationalQueryPage.value && mode.value === 'store'
      ? await inventoryApi.unifiedOperationalQuery(requestedStoreDocumentQuery)
      : active.value === 'stock' && hasExplicitQuery
      ? await client.unifiedBatchStock(query)
      : active.value === 'stock'
      ? mode.value === 'store'
        ? await client.list('productSummary', { keyword: query.keyword || keyword.value.trim(), page: stockPage.value, limit: stockPageSize })
        : platformStockSubject.value === 'HQ' && Number(platformHqLocationId.value) > 0
          ? await platformInventoryApi.hqProductSummary({ hq_location_id: Number(platformHqLocationId.value), keyword: query.keyword || keyword.value.trim(), page: stockPage.value, limit: stockPageSize })
          : await client.unifiedBatchStock(query)
      : await client.list(active.value, query)
    const list = Array.isArray(response?.records) ? response.records : (Array.isArray(response?.list) ? response.list : (Array.isArray(response?.items) ? response.items : (Array.isArray(response) ? response : [])))
    if (groupedRequest && groupedRequest !== groupedListVersion) return
    if (active.value === 'outbound' && mode.value === 'store') outboundExecutedQuery.value = JSON.parse(JSON.stringify(requestedStoreDocumentQuery))
    // 平台门店仓目前仅有读接口；不能把门店投影的 can_void 当作总部冲销权限。
    const displayRows = requestedPlatformRequest?.readOnlyStore
      ? list.map(row => ({ ...row, can_edit: false, can_delete: false, can_cancel: false, can_terminate: false, can_transfer: false, can_bind_supply: false, can_dispatch: false, can_withdraw: false, can_receive: false, can_reverse: false, can_void: false }))
      : active.value === 'stock' && mode.value === 'store' ? summarizeStockRows(list)
      : requestedPlatformRequest?.kind === 'unified' && ['inbound', 'outbound'].includes(active.value)
        ? list.map(row => ({ ...row, can_void: false })) : list
    if (mode.value === 'platform' && active.value === 'transfer') platformTransferCanCreate.value = response?.can_create === true
    // 越界页先回到最后一页，再更新界面，避免短暂混入另一页或另一门店的行。
    if (requestedPlatformRequest) {
      const total = Number(response?.total ?? response?.count ?? list.length)
      const lastPage = Math.max(1, Math.ceil(total / platformListPageSize))
      if (requestedPlatformRequest.payload.page > lastPage) {
        platformListExecutedRequest.value = JSON.parse(JSON.stringify(requestedPlatformRequest))
        platformListPage.value = lastPage
        await loadCurrentList({ page: lastPage }, { reusePlatformListQuery: true })
        return
      }
      platformListPage.value = requestedPlatformRequest.payload.page
      platformListExecutedRequest.value = JSON.parse(JSON.stringify(requestedPlatformRequest))
      if (storeListTableScroll.value) storeListTableScroll.value.scrollTop = 0
    }
    if (mode.value === 'store' && operationalQueryPage.value) {
      const total = Number(response?.total ?? response?.count ?? list.length)
      const lastPage = Math.max(1, Math.ceil(total / storeOperationalPageSize))
      if (requestedStoreDocumentQuery.page > lastPage) {
        // 单据变化使末页缩小时，固定原查询条件回到有效末页，避免显示空白或串入其他筛选。
        storeOperationalExecutedQuery.value = JSON.parse(JSON.stringify(requestedStoreDocumentQuery))
        await loadCurrentList({ page: lastPage }, { reuseStoreOperationalQuery: true })
        return
      }
      storeOperationalPage.value = requestedStoreDocumentQuery.page
      storeOperationalExecutedQuery.value = JSON.parse(JSON.stringify(requestedStoreDocumentQuery))
      if (storeListTableScroll.value) storeListTableScroll.value.scrollTop = 0
    }
    sourceRows.value = active.value === 'warehouse' ? displayRows.filter((row) => row.location_type === 'HQ') : displayRows
    remoteRows.value = sourceRows.value.map((row) => mapApiRow(active.value, row))
    // Both hosts display the permission-scoped server total, never just the visible page size.
    remoteTotal.value = Number(response?.total ?? response?.count ?? list.length)
    if (active.value === 'stock' && stockPage.value > stockPages.value) {
      stockPage.value = stockPages.value
      await loadCurrentList(hasExplicitQuery ? { ...unifiedQuery, page: stockPage.value, limit: stockPageSize } : null)
      return
    }
    // 库存商品切页和重新筛选后从表头开始看，滚动位置不沿用上一页。
    if (active.value === 'stock' && storeListTableScroll.value) storeListTableScroll.value.scrollTop = 0
    if (groupedRequest) {
      resetGroupedDetails()
      loadGroupedDetails(groupedPage, displayRows)
    }
    if (mode.value === 'platform' && active.value === 'transfer') {
      transferListRefreshFailed.value = false
      transferRefreshAfterSave.value = false
      transferListRefreshReason.value = ''
    }
    if (active.value === 'stock') {
      // The server total covers the complete filtered result and already applies
      // the same permission scope as the list; never recompute it from browser rows.
      stockSummary.value = response?.stockSummary && typeof response.stockSummary === 'object'
        ? response.stockSummary
        : { available_quantity: '0', inventory_amount_cents: null }
      stockTableLayout.value = response?.tableLayout && typeof response.tableLayout === 'object'
        ? response.tableLayout
        : { fixed: false, summaryRow: false }
    }
    listDateRange.value = active.value === 'usage' && response?.from && response?.to
      ? { from: response.from, to: response.to }
      : null
    if (active.value === 'stock' && unifiedQuery && typeof unifiedQuery === 'object') {
      stockExecutedQuery.value = JSON.parse(JSON.stringify(unifiedQuery))
    }
  } catch (error) {
    if (groupedRequest && groupedRequest !== groupedListVersion) return
    // A confirmed batch command must not be presented as a failed transfer because its follow-up read failed.
    if (mode.value === 'platform' && active.value === 'transfer' && transferRefreshAfterSave.value) {
      transferListRefreshFailed.value = true
      transferListRefreshReason.value = error instanceof Error ? error.message : '库存列表加载失败。'
      listError.value = ''
      return
    }
    sourceRows.value = []
    remoteRows.value = []
    remoteTotal.value = 0
    if (mode.value === 'store' && operationalQueryPage.value) storeOperationalPage.value = 1
    if (mode.value === 'platform' && active.value !== 'stock') platformListPage.value = 1
    stockSummary.value = { available_quantity: '0', inventory_amount_cents: null }
    stockTableLayout.value = { fixed: false, summaryRow: false }
    listError.value = error instanceof Error ? error.message : '库存数据加载失败。'
  } finally {
    if (!groupedRequest || groupedRequest === groupedListVersion) listLoading.value = false
  }
}

async function queryBatchStock(query) {
  const keywordFilters = Array.isArray(query?.keywordFilters) ? query.keywordFilters : []
  const firstKeyword = keywordFilters.map((item) => item?.value).find((value) => typeof value === 'string')
  keyword.value = typeof query?.keyword === 'string' ? query.keyword.trim() : (firstKeyword || '')
  stockPage.value = 1
  await loadCurrentList({ ...(query && typeof query === 'object' ? query : {}), keyword: keyword.value })
}

/** A page turn keeps the submitted filters and the host-authorized warehouse scope. */
async function turnStockPage(delta) {
  const nextPage = stockPage.value + delta
  if (listLoading.value || nextPage < 1 || nextPage > stockPages.value) return
  stockPage.value = nextPage
  const executed = stockExecutedQuery.value
  await loadCurrentList(Object.keys(executed).length ? { ...executed, page: nextPage, limit: stockPageSize } : null)
}

/** 平台单据、仓库、配方共用分页；只从固定后端请求快照替换页码。 */
async function turnPlatformListPage(delta) {
  const next = platformListPage.value + delta
  if (!platformListExecutedRequest.value || listLoading.value || next < 1 || next > platformListPages.value) return
  platformListPage.value = next
  await loadCurrentList({ page: next }, { reusePlatformListQuery: true })
}

/** 两端普通列表只改页码；单据沿用固定请求快照，库存商品沿用已提交的汇总查询。 */
async function turnInventoryListPage(delta) {
  if (!inventoryListPage.value || listLoading.value) return
  if (active.value === 'stock') return turnStockPage(delta)
  if (mode.value === 'platform') return turnPlatformListPage(delta)
  const nextPage = storeOperationalPage.value + delta
  if (!storeOperationalExecutedQuery.value || nextPage < 1 || nextPage > storeOperationalPages.value) return
  await loadCurrentList({ page: nextPage }, { reuseStoreOperationalQuery: true })
}

async function queryStoreOperationalPage() {
  storeOperationalPage.value = 1
  await loadCurrentList({
    keyword: keyword.value.trim(),
    page: 1,
    limit: storeOperationalPageSize
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
  // 平台嵌入页只请求本页依赖；调拨查询不应顺带读取未授权的首页指标。
  if (mode.value === 'platform' && active.value !== 'overview') return
  const client = mode.value === 'platform' ? platformInventoryApi : inventoryApi
  if (!client) return
  dashboardLoading.value = true
  dashboardError.value = ''
  try { dashboard.value = await client.dashboard(mode.value === 'platform' ? { hq_location_id: Number(dashboardWarehouseId.value) } : {}) } catch (error) { dashboardError.value = error instanceof Error ? error.message : '库存首页读取失败。' } finally { dashboardLoading.value = false }
}

async function openStockDetail(row) {
  if (!row?.product_id || !inventoryApi) return
  listError.value = ''
  try {
    selectedDetail.value = mode.value === 'platform'
      ? platformStockSubject.value === 'HQ'
        ? await platformInventoryApi.hqProductDetail(row.product_id, { hq_location_id: Number(row.location_id || platformHqLocationId.value) })
        : await platformInventoryApi.storeProductDetail(row.product_id, row.location_id)
      : await inventoryApi.productDetail(row.product_id)
    openEditor('stock-detail', selectedDetail.value)
  } catch (error) { listError.value = error instanceof Error ? error.message : '库存详情读取失败。' }
}

async function loadPlatformLocations() {
  if (mode.value !== 'platform') return
  try {
    const response = await platformInventoryApi.list('platformLocations', { include_inactive: 1 })
    platformCanManageInventory.value = response?.can_manage === true
    platformCanManageWarehouses.value = response?.can_manage_warehouses === true
    platformCreatePermissions.value = response?.can_create || {}
    platformLocations.value = Array.isArray(response?.list) ? response.list : []
    platformStockStoreLocations.value = Array.isArray(response?.stock_store_locations) ? response.stock_store_locations : []
    if (platformStoreId.value > 0 && !platformStockStoreLocations.value.some((item) => Number(item.store_id) === Number(platformStoreId.value))) platformStoreId.value = 0
    if (platformHqLocationId.value > 0 && !platformHqLocations.value.some((item) => Number(item.id) === Number(platformHqLocationId.value))) platformHqLocationId.value = 0
    if (platformHqLocationId.value <= 0 && platformHqLocations.value.length >= 1) platformHqLocationId.value = Number((platformHqLocations.value.find((item) => item.location_status === 'ACTIVE') || platformHqLocations.value[0])?.id || 0)
    const selected = String(platformListWarehouseSelection.value || '')
    if (!platformListWarehouseOptions.value.some((item) => item.key === selected)) {
      platformListWarehouseSelection.value = platformHqLocations.value.length
        ? `HQ:${Number(platformHqLocationId.value)}` : 'HQ:0'
    }
    const [selectionType, selectionId] = String(platformListWarehouseSelection.value || 'HQ:0').split(':')
    if (selectionType === 'HQ' && Number(selectionId) > 0) platformHqLocationId.value = Number(selectionId)
    await loadPlatformTransferTargets()
  } catch (error) {
    platformLocations.value = []
    platformStockStoreLocations.value = []
    platformCanManageInventory.value = false
    platformCreatePermissions.value = {}
    listError.value = error instanceof Error ? error.message : '平台仓库范围读取失败。'
  }
}

/** 页面授权决定组织目录；选择组织或单店只扩大读取，不授予库存写入。 */
async function loadPlatformOrganizationScope(page = active.value) {
  if (mode.value !== 'platform' || !platformOrganizationPages.has(page)) return
  platformOrganizationLoading.value = true
  try {
    const pageCode = page === 'usage' ? 'inventory_salon_usage' : `inventory_${page}`
    const response = await platformInventoryApi.list('organizationScope', { pageCode })
    if (active.value === page) platformOrganizationTree.value = Array.isArray(response?.tree) ? response.tree : []
  } catch (error) {
    if (active.value === page) listError.value = error instanceof Error ? error.message : '组织目录读取失败。'
  } finally { platformOrganizationLoading.value = false }
}

/** 更换只读主体时丢弃原列表与明细，防止跨范围旧结果短暂混显。 */
function onPlatformOrganizationStoreChanged(selection) {
  platformOrganizationSelection.value = selection
  platformListPage.value = 1
  platformListExecutedRequest.value = null
  groupedListVersion += 1
  resetGroupedDetails()
  sourceRows.value = []
  remoteRows.value = []
  remoteTotal.value = 0
  loadCurrentList()
}

/** 列表接收筛选复用表单的全有效门店目录；调出方仍使用授权仓库目录。 */
async function loadPlatformTransferTargets() {
  platformTransferTargetOptions.value = []
  if (mode.value !== 'platform' || active.value !== 'transfer' || Number(platformHqLocationId.value) <= 0) return
  try {
    const response = await platformInventoryApi.hqCrossTransferCounterparties({ hq_location_id: Number(platformHqLocationId.value), source_party_type: 'HQ', source_store_id: 0 })
    platformTransferTargetOptions.value = [
      { key: 'ALL:0', name: '全部调入方', type: 'ALL' },
      ...platformHqLocations.value.map((item) => ({ key: `HQ:${item.id}`, name: item.location_name, type: 'HQ' })),
      ...(response?.list || []).filter((party) => party.party_type !== 'HQ').map((party) => ({ key: `${party.party_type}:${Number(party.id)}`, name: party.name, type: party.party_type }))
    ]
  } catch (error) { listError.value = error instanceof Error ? error.message : '调入方目录读取失败。' }
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
    stockPage.value = 1
    stockExecutedQuery.value = {}
    stockUnifiedQuery.reset()
    stockUnifiedQuery.load()
    loadCurrentList()
  }
}

function onPlatformStockSubjectChanged() {
  if (platformStockSubject.value === 'HQ') platformStoreId.value = 0
  stockPage.value = 1
  stockExecutedQuery.value = {}
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

function onModalSaved(result = {}) {
  editor.value = ''
  if (mode.value === 'platform' && active.value === 'transfer' && result?.transferSourceKey) {
    const [sourceType, sourceId] = String(result.transferSourceKey).split(':', 2)
    // A new transfer is visible only in its source scope and resulting lifecycle filter.
    if (sourceType === 'HQ' && Number(sourceId) > 0) platformHqLocationId.value = Number(sourceId)
    platformTransferFromParty.value = result.transferSourceKey
    platformTransferToParty.value = 'ALL:0'
    platformTransferStatus.value = result.transferStatus === 'DISPATCHED' ? 'DISPATCHED' : 'DRAFT'
    keyword.value = ''
    const savedKind = result.transferStatus === 'DISPATCHED' ? '已发货调拨单' : '调拨草稿'
    const transferNumbers = Array.isArray(result.transferNumbers) ? result.transferNumbers.filter(Boolean) : []
    transferSavedNotice.value = Number(result.transferSourceCount) > 1
      ? `已生成 ${Number(result.transferDraftCount)} 张${savedKind}，涉及 ${Number(result.transferSourceCount)} 个调出方；当前显示第一个调出方，其余可切换调出方查看。`
      : `已生成 ${Number(result.transferDraftCount)} 张${savedKind}${transferNumbers.length === 1 ? `（${transferNumbers[0]}）` : ''}。`
    transferListRefreshFailed.value = false
    transferRefreshAfterSave.value = true
    transferListRefreshReason.value = ''
  }
  // 新单可能在弹窗内换仓；刷新列表前对准实际落单仓，避免误以为保存失败。
  if (mode.value === 'platform' && platformListWarehouseOptions.value.some((item) => item.key === result?.warehouseSelection)) {
    platformListWarehouseSelection.value = result.warehouseSelection
  }
  // 保存后按单据业务日期回显；请货和调拨编辑都可能把单据移出原查询日期。
  const savedPeriodDate = String(result?.businessDate || '')
  if (usesBusinessDatePeriod() && /^\d{4}-\d{2}-\d{2}$/.test(savedPeriodDate)) {
    businessDateRange.value = { min: savedPeriodDate, max: savedPeriodDate }
  }
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
  if (mode.value === 'platform') await loadPlatformOrganizationScope()
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
  <div
    class="inventory-shell"
    :class="{ 'inventory-shell--collapsed': sidebarCollapsed, 'inventory-shell--store-entry': isEmbeddedEntry, 'inventory-shell--store-mode': mode === 'store', 'inventory-shell--inventory-list-page': inventoryListPage }"
    @keydown.capture="preventInventoryDateKeyboardInput"
    @beforeinput.capture="preventInventoryDateTextTransfer"
    @paste.capture="preventInventoryDateTextTransfer"
    @drop.capture="preventInventoryDateTextTransfer"
  >
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
        <section v-if="warehouseChoiceKind" class="transfer-confirmation-layer" role="dialog" aria-modal="true" aria-label="选择盘点仓库">
          <button class="transfer-confirmation-layer__backdrop" aria-label="取消选仓" @click="warehouseChoiceKind = ''"></button>
          <div class="transfer-confirmation-dialog inventory-warehouse-choice"><h2>{{ warehouseChoiceKind === 'request-supply' ? '确认供货仓库' : '选择盘点仓库' }}</h2><label>仓库<select v-model="warehouseChoice" aria-label="盘点仓库"><option value="" disabled>请选择仓库</option><option v-for="location in platformHqLocations.filter(item => item.location_status !== 'INACTIVE')" :key="location.id" :value="String(location.id)">{{ location.location_name }}</option></select></label><footer><button class="secondary-button" @click="warehouseChoiceKind = ''">取消</button><button class="primary-button" :disabled="!warehouseChoice" @click="enterChosenWarehouse">{{ warehouseChoiceKind === 'request-supply' ? '确认供货仓' : '进入盘点' }}</button></footer></div>
        </section>
        <section v-if="transferActionConfirmation" class="transfer-confirmation-layer" role="dialog" aria-modal="true" :aria-label="`确认${transferActionConfirmation.label}`">
          <button class="transfer-confirmation-layer__backdrop" aria-label="关闭确认窗口" :disabled="transferActionSubmitting" @click="cancelTransferAction"></button>
          <div class="transfer-confirmation-dialog">
            <h2>确认{{ transferActionConfirmation.label }}</h2>
            <template v-if="transferActionConfirmation.batchAction">
              <p v-if="transferActionConfirmation.batchAction === 'DELETE_DRAFT'">以下 {{ transferActionConfirmation.rows.length }} 张草稿及商品明细将永久删除，无法恢复。</p>
              <p v-else-if="transferActionConfirmation.batchAction === 'DISPATCH'">以下 {{ transferActionConfirmation.rows.length }} 张调拨单将发货并扣减调出方库存。</p>
              <p v-else>以下 {{ transferActionConfirmation.rows.length }} 张未收货单据将撤回；原批次库存恢复，发货和撤回流水保留。</p>
              <div class="transfer-batch-documents" aria-label="待处理调拨单"><span v-for="row in transferActionConfirmation.rows" :key="row.id">{{ row.order_sn || row.id }} · {{ row.to_party_name || '调入方' }}</span></div>
              <p>任一单据校验失败时，本批全部不生效。</p>
            </template>
            <p v-else-if="transferActionConfirmation.action === 'deleteDraft'">单据 {{ transferActionConfirmation.row.order_sn || transferActionConfirmation.row.id }} 的草稿及商品明细将永久删除，无法恢复。已发货或已有库存流水、请货履约的调拨单不能删除。</p>
            <p v-else-if="transferActionConfirmation.action === 'withdraw'">单据 {{ transferActionConfirmation.row.order_sn || transferActionConfirmation.row.id }} 尚未收货。撤回后调出方原批次库存会恢复，单据变为草稿；原发货和撤回流水会保留。</p>
            <p v-else>单据 {{ transferActionConfirmation.row.order_sn || transferActionConfirmation.row.document_no || transferActionConfirmation.row.id }} 将执行{{ transferActionConfirmation.label }}。原单和原始库存流水会保留。</p>
            <p v-if="transferActionConfirmation.error" class="inventory-load-error" role="alert">{{ transferActionConfirmation.error }}</p>
            <label v-if="transferActionConfirmation.requiresReason" class="transfer-confirmation-dialog__reason">
              操作原因
              <textarea v-model="transferActionConfirmation.reason" maxlength="500" rows="3" placeholder="请填写至少 2 个字的原因"></textarea>
            </label>
            <footer>
              <button class="secondary-button" :disabled="transferActionSubmitting" @click="cancelTransferAction">取消</button>
              <button class="primary-button" :disabled="transferActionSubmitting || (transferActionConfirmation.requiresReason && String(transferActionConfirmation.reason || '').trim().length < 2)" @click="confirmTransferAction">{{ transferActionSubmitting ? '处理中...' : transferActionConfirmation.batchAction === 'DELETE_DRAFT' || transferActionConfirmation.action === 'deleteDraft' ? '确认永久删除' : ['void', 'voidRequest'].includes(transferActionConfirmation.action) ? '作废' : '确认' }}</button>
            </footer>
          </div>
        </section>
        <section v-if="countDraftDeleteConfirmation" class="transfer-confirmation-layer" role="dialog" aria-modal="true" aria-label="确认删除盘点草稿">
          <button class="transfer-confirmation-layer__backdrop" aria-label="关闭确认窗口" :disabled="countDraftDeleteBusy" @click="countDraftDeleteConfirmation = null"></button>
          <div class="transfer-confirmation-dialog">
            <h2>确认删除草稿-{{ countDraftDeleteConfirmation.id }}？</h2>
            <p>服务器中保存的草稿和全部盘点商品数据将被永久删除，无法恢复；已确认的盘点单和库存不会受到影响。</p>
            <p v-if="countDraftDeleteError" class="inventory-load-error" role="alert">{{ countDraftDeleteError }}</p>
            <footer>
              <button class="secondary-button" :disabled="countDraftDeleteBusy" @click="countDraftDeleteConfirmation = null">取消</button>
              <button class="primary-button" :disabled="countDraftDeleteBusy" @click="confirmDeleteCountDraft">{{ countDraftDeleteBusy ? '删除中...' : '确认永久删除' }}</button>
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
        <InventoryRemarkModal v-if="remarkEditor" :page="remarkEditor.page" :detail="remarkEditor.detail" :saving="remarkSaving" :error="remarkError" @close="remarkEditor = null" @save="saveRemark" />
        <InventoryRecipeModal v-if="editor && active === 'recipe'" :recipe-id="Number(selectedDetail?.id || 0)" @close="editor = ''; selectedDetail = null" @saved="onModalSaved" />
        <InventoryTransferDraftComposer v-else-if="editor && active === 'transfer' && (editor === 'transfer-import' || (editor === 'transfer' && !selectedDetail?.transfer_request_id))" :mode="mode" :modal-kind="editor" :scope-name="scopeName" :warehouse-options="platformLocations" :hq-location-id="platformHqLocationId" :default-business-date="todayDate()" @close="editor = ''; selectedDetail = null" @saved="onModalSaved" />
        <InventoryBusinessModal v-else-if="editor" :page-key="active" :modal-kind="editor" :detail="selectedDetail" :scope-name="scopeName" :default-location-name="defaultLocationName" :warehouse-options="platformLocations" :warehouse-selection="platformListWarehouseSelection" :hq-location-id="platformHqLocationId" :usage-store-id="platformUsageStoreId" :default-business-date="todayDate()" :mode="mode" @close="editor = ''; selectedDetail = null" @saved="onModalSaved" @edit-count-draft="editor = 'count-edit'" />
        <template v-else-if="active === 'overview'">
          <div class="page-heading page-heading--dashboard">
            <div><p>库存管理 / 首页</p><h1>库存首页</h1><span>{{ mode === 'platform' ? '总部仓' : scopeName }}的库存、临期、盘点与待办集中处理</span></div>
            <div class="heading-actions"><label v-if="mode === 'platform'">仓库<select v-model.number="dashboardWarehouseId" @change="loadDashboard"><option :value="0">全部授权总部仓</option><option v-for="location in platformHqLocations.filter(item => item.location_status !== 'INACTIVE')" :key="location.id" :value="Number(location.id)">{{ location.location_name }}</option></select></label><button class="secondary-button" @click="selectPage('statistics', { tab: 'expiry' })">查看库存分析</button></div>
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
                <div class="table-scroll statistics-table"><table><thead><tr><th>商品</th><th>规格</th><th>单位</th><th>批次</th><th v-if="activeStatisticsTab === 'expiry'">当前剩余库存</th><th>{{ activeStatisticsTab === 'expiry' ? '到期日' : '正式入库日' }}</th><th>{{ activeStatisticsTab === 'expiry' ? '距到期日天数' : '库龄天数' }}</th><th>分档</th><th>库存金额</th></tr></thead><tbody><tr v-if="!analysisRows.length"><td :colspan="activeStatisticsTab === 'expiry' ? 9 : 8" class="table-empty">暂无符合条件的批次</td></tr><tr v-for="(row, index) in analysisRows" :key="index"><td v-for="cell in row" :key="cell">{{ cell }}</td></tr></tbody></table></div>
              </template>
            </template>
            <template v-else-if="['inbound', 'outbound'].includes(activeStatisticsTab)">
              <div v-if="movementStatisticsLoading" class="statistics-empty"><ChartNoAxesCombined :size="28" /><strong>正在读取批次事实统计</strong></div>
              <div v-else-if="movementStatisticsError" class="statistics-empty"><AlertTriangle :size="28" /><strong>{{ movementStatisticsError }}</strong></div>
              <template v-else>
                <div class="table-scroll statistics-table"><table><thead><tr><th>业务类型</th><th>商品</th><th>规格</th><th>单位</th><th>单据数</th><th>批次流水数</th><th>数量</th><th>成本金额</th></tr></thead><tbody><tr v-if="!movementStatisticRows.length"><td colspan="8" class="table-empty">统计期间暂无{{ activeStatisticsTab === 'inbound' ? '入库' : '出库' }}流水</td></tr><tr v-for="(row, index) in movementStatisticRows" :key="index"><td v-for="cell in row" :key="cell">{{ cell }}</td></tr></tbody></table></div>
              </template>
            </template>
          </section>
        </template>

        <template v-else>
          <!-- 门店库存列表共用本轮确认的简洁查询布局；平台端保留原页面入口。 -->
          <div class="page-heading"><div><p>{{ currentPage.crumb }}</p><h1 v-if="!inventoryListPage">{{ currentPage.title }}</h1><span v-if="statusFocus" class="focus-hint"><Clock3 :size="14" />当前从首页进入：{{ statusFocus }}</span></div><div class="heading-actions"><button v-if="currentPage.action && !inventoryListPage" class="primary-button" :disabled="platformReadScopeMode || (platformActionRequiresHqLocation && active !== 'count' && !platformHqLocationId) || (platformUsageRequiresWarehouse && !platformListWarehouseOptions.length) || (mode === 'platform' && active !== 'recipe' && !platformCanCreateCurrent) || (mode === 'platform' && active === 'transfer' && !platformTransferCanCreate)" @click="active === 'recipe' ? openRecipeEditor() : openEditor()"><Plus :size="17" />{{ currentPage.action }}</button><!-- 两端普通库存列表按本轮确认移除右上角查询设置。 --></div></div>
          <section v-if="active === 'stock'" class="inventory-unified-query" aria-label="库存统一查询">
            <!-- The stock query keeps one toolbar layout in both hosts; platform-only warehouse scope stays visible. -->
            <UnifiedQueryToolbar
              settings-button-label="查询设置"
              :show-keyword-search="false"
              :show-data-scope="false"
              :show-settings-button="false"
              :query-actions-after-quick-fields="true"
              :single-row-quick-query="true"
              :fields="visibleStockQueryFields"
              page-code="inventory_batch_stock"
              page-name="库存查询"
              :result-count="remoteTotal"
              :page-size="stockPageSize"
              :current-page="stockPage"
              :current-page-count="remoteRows.length"
              :data-as-of="stockQueryCapability.dataAsOf"
              :query-capability="stockQueryCapability"
              :executed-query="stockExecutedQuery"
              :is-query-loading="listLoading"
              :state-context-key="mode === 'platform' ? `inventory-platform-batch-stock-${platformStockSubject}-${platformStockSubject === 'HQ' ? platformHqLocationId || 'all' : platformStoreId || 'all'}` : 'inventory-store-batch-stock'"
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
                  <label v-if="platformStockSubject === 'HQ'">总部仓<select v-model.number="platformHqLocationId" @change="onPlatformLocationChanged"><option :value="0">全部授权总部仓</option><option v-for="location in platformHqLocations" :key="location.id" :value="Number(location.id)">{{ location.location_name }}</option></select></label><label v-if="platformStockSubject === 'STORE'">库存仓
                    <InventoryStoreSelector v-model="platformStoreSelection" :options="platformStoreSelectorOptions" placeholder="选择门店" />
                  </label>
                </div>
              </template>
            </UnifiedQueryToolbar>
            <p v-if="stockQueryLoadError" class="inventory-load-error">{{ stockQueryLoadError }}</p>
          </section>
          <section v-if="operationalQueryPage && mode === 'store'" class="filter-card filter-card--store-list">
            <div class="filter-search"><Search :size="17" /><input v-model="keyword" :placeholder="`搜索${currentPage.title}记录`" @keyup.enter="queryStoreOperationalPage" /></div>
            <label v-if="active === 'outbound'">出库类型<select v-model="outboundTypeFilter" aria-label="出库类型"><option value="">全部</option><option v-for="type in outboundFilterTypes" :key="type" :value="type">{{ type }}</option></select></label>
            <label>状态<select v-model="storeOperationalStatus" @change="onStoreOperationalStatusChanged"><option value="">全部</option><option v-for="status in storeOperationalStatusOptions" :key="status" :value="status">{{ status }}</option></select></label>
            <button v-if="active === 'transfer'" class="secondary-button" :class="{ 'secondary-button--active': storeTransferUnreceivedOnly }" type="button" :aria-pressed="storeTransferUnreceivedOnly" :disabled="listLoading" @click="showStoreUnreceivedTransfers">未到货</button>
            <div v-if="filtersBusinessDatePeriod()" class="inventory-period-filter"><UnifiedQueryDateRange :model-value="businessDateRange" :label="businessDatePeriodLabel(active)" @change="applyBusinessDateRange" /></div>
            <div v-if="active === 'count'" class="count-period-filter"><UnifiedQueryDateRange :model-value="countDateRange" label="盘点日期周期" @change="applyCountDateRange" /></div>
            <button class="primary-button" :disabled="listLoading" @click="queryStoreOperationalPage">{{ listLoading ? '查询中' : '查询' }}</button>
            <InventoryOutboundExport v-if="active === 'outbound'" :query="outboundExecutedQuery" :count="remoteTotal" :disabled="listLoading || Boolean(listError)" :request-action="inventoryUnifiedQueryRequestAction" :download="inventoryApi.downloadOutboundExport" />
            <div v-if="currentPage.action || ['inbound', 'outbound'].includes(active)" class="inventory-list-query-actions">
              <button v-if="['inbound', 'outbound', 'transfer'].includes(active)" type="button" class="secondary-button" @click="openEditor(active === 'transfer' ? 'transfer-import' : 'import')"><FileSpreadsheet :size="17" />导入</button>
              <button v-if="currentPage.action" type="button" class="primary-button" @click="openEditor()"><Plus :size="17" />{{ currentPage.action }}</button>
            </div>
          </section>
          <section v-else-if="active === 'recipe'" class="filter-card filter-card--inventory-list">
            <label>配方状态<select v-model="recipeStatus" @change="loadCurrentList"><option value="">全部</option><option value="1">启用中</option><option value="0">已停用</option></select></label>
            <div class="filter-search"><Search :size="17" /><input v-model="recipeKeyword" placeholder="搜索项目名称" @keyup.enter="active === 'warehouse' ? queryWarehouseSettings() : loadCurrentList()" /></div>
            <button class="primary-button" :disabled="listLoading" @click="active === 'warehouse' ? queryWarehouseSettings() : loadCurrentList()">{{ listLoading ? '查询中' : '查询' }}</button>
            <button class="secondary-button" :disabled="listLoading" @click="recipeStatus = ''; recipeKeyword = ''; loadCurrentList()">重置</button>
            <div class="inventory-list-query-actions"><button class="primary-button" @click="openRecipeEditor()"><Plus :size="17" />{{ currentPage.action }}</button></div>
          </section>
          <section v-else-if="!(active === 'stock')" class="filter-card filter-card--inventory-list" :class="{ 'filter-card--platform-transfer': mode === 'platform' && active === 'transfer' }">
            <div class="filter-search"><Search :size="17" /><input v-model="keyword" :placeholder="active === 'transfer' ? '搜索单号、备注或商品名称' : `搜索${currentPage.title}记录`" @keyup.enter="loadCurrentList" /></div>
            <label v-if="mode === 'platform' && platformOrganizationPages.has(active)">组织 / 门店<InventoryOrganizationStorePicker :model-value="platformOrganizationSelection" :tree="platformOrganizationTree" :loading="platformOrganizationLoading" @update:model-value="onPlatformOrganizationStoreChanged" /></label>
            <template v-if="mode === 'platform' && active === 'request' && !platformReadScopeMode"><label>请货方<InventoryStoreSelector v-model="platformRequestPartySelection" :options="platformRequestPartySelectorOptions" placeholder="全部请货方" /></label><label>供货方<InventoryStoreSelector v-model="platformRequestSupplyPartySelection" :options="platformRequestSupplyPartySelectorOptions" placeholder="全部供货方" /></label><label>请货状态<select v-model="platformRequestStatus"><option value="">全部</option><option value="APPLIED">申请中</option><option value="PARTIAL">部分调拨</option><option value="DONE">已完成</option><option value="CANCELLED">已取消</option><option value="TERMINATED">已终止</option><option value="VOIDED">已作废</option></select></label></template>
            <template v-else-if="mode === 'platform' && active === 'transfer' && !platformReadScopeMode"><label>调出方<InventoryStoreSelector v-model="platformTransferFromParty" :options="platformWarehouseSelectorOptions" placeholder="全部调出方" /></label><label>调入方<InventoryStoreSelector v-model="platformTransferToParty" :options="platformTransferTargetOptions" searchable placeholder="全部调入方" /></label><button class="secondary-button" type="button" :disabled="listLoading" @click="showAllUnreceivedTransfers">未收货</button><label>调拨状态<select v-model="platformTransferStatus"><option value="">全部</option><option value="DRAFT">草稿</option><option value="DISPATCHED">未收货</option><option value="RECEIVED">已收货</option><option value="CANCELLED">已取消</option><option value="REVERSED">已作废</option></select></label></template>
            <label v-else-if="active === 'warehouse'">仓库状态<select v-model="warehouseSettingsStatus" @change="queryWarehouseSettings"><option value="">全部</option><option value="ACTIVE">启用</option><option value="INACTIVE">停用</option></select></label>
            <label v-else-if="mode === 'platform' && !platformReadScopeMode">库存仓<InventoryStoreSelector v-model="platformListWarehouseSelection" :options="platformOrganizationPages.has(active) ? platformHqListWarehouseOptions : platformListWarehouseOptions" placeholder="选择库存仓" :presentation="['inbound', 'outbound', 'count', 'usage'].includes(active) ? 'dropdown' : 'modal'" /></label>
            <label v-if="!(['inbound', 'outbound', 'warehouse'].includes(active) || (mode === 'platform' && ['request', 'transfer'].includes(active)))" v-for="filter in currentPage.filters.slice(0, 2)" :key="filter">{{ filter }}<select><option>全部</option></select></label>
            <div v-if="filtersBusinessDatePeriod()" class="inventory-period-filter"><UnifiedQueryDateRange :model-value="businessDateRange" :label="businessDatePeriodLabel(active)" @change="applyBusinessDateRange" /></div>
            <div v-if="active === 'count'" class="count-period-filter"><UnifiedQueryDateRange :model-value="countDateRange" label="盘点日期周期" @change="applyCountDateRange" /></div>
            <button class="primary-button" :disabled="listLoading || (!platformReadScopeMode && ((platformFilterRequiresHqLocation && !platformHqLocationId) || (platformUsageRequiresWarehouse && !platformListWarehouseOptions.length)))" @click="loadCurrentList">{{ listLoading ? '查询中' : '查询' }}</button>
            <div v-if="mode === 'platform' && (currentPage.action || ['inbound', 'outbound'].includes(active))" class="inventory-list-query-actions">
              <button v-if="!platformReadScopeMode && ['inbound', 'outbound', 'transfer'].includes(active)" class="secondary-button" :disabled="active === 'transfer' ? !platformCanCreateCurrent || !platformTransferCanCreate || !platformHqLocationId : !platformCanManageInventory" @click="openEditor(active === 'transfer' ? 'transfer-import' : 'import')"><FileSpreadsheet :size="17" />导入</button>
              <button v-if="currentPage.action" class="primary-button" :disabled="platformReadScopeMode || (platformActionRequiresHqLocation && active !== 'count' && !platformHqLocationId) || (platformUsageRequiresWarehouse && !platformListWarehouseOptions.length) || !platformCanCreateCurrent || (active === 'transfer' && !platformTransferCanCreate)" @click="openEditor()"><Plus :size="17" />{{ currentPage.action }}</button>
            </div>
          </section>
            <p v-if="active === 'transfer' && transferSavedNotice" class="inventory-save-notice" role="status">{{ transferSavedNotice }}</p>
            <p v-if="active === 'transfer' && transferListRefreshFailed" class="inventory-load-error" role="alert">单据已保存，但列表未刷新：{{ transferListRefreshReason }} 下方仍是上次列表的 {{ remoteTotal }} 条记录，不能继续操作。</p>
            <div v-if="active === 'transfer' && !platformReadScopeMode" class="transfer-batch-toolbar">
              <label><input type="checkbox" :checked="selectableTransferRows.length > 0 && selectedTransferRows.length === selectableTransferRows.length" :disabled="transferListRefreshFailed || !selectableTransferRows.length || transferBatchPreparing || transferActionSubmitting" @change="toggleAllVisibleTransfers" />全选当前列表</label>
              <span>已选 {{ selectedTransferRows.length }} 张</span>
              <button class="secondary-button" :disabled="!selectedTransferRows.length || transferBatchPreparing || transferActionSubmitting" @click="openBulkTransferAction('DELETE_DRAFT')"><Trash2 :size="14" />删除</button>
              <button class="secondary-button" :disabled="!selectedTransferRows.length || transferBatchPreparing || transferActionSubmitting" @click="openBulkTransferAction('DISPATCH')"><Truck :size="14" />发货</button>
              <button class="secondary-button" :disabled="!selectedTransferRows.length || transferBatchPreparing || transferActionSubmitting" @click="openBulkTransferAction('WITHDRAW')"><RotateCcw :size="14" />撤回</button>
              <span v-if="transferBatchPreparing">正在核对单据...</span>
              <span v-else-if="selectedTransferAlreadyDispatched && !transferBatchError" class="transfer-batch-toolbar__notice" role="status">所选包含已发货的调拨单，无需重复发货</span>
              <span v-else-if="selectedTransferRows.length >= 2 && !['DELETE_DRAFT', 'DISPATCH', 'WITHDRAW'].some(canOperateTransferBatch)">请只选择同一调出方、同一操作状态的单据</span>
              <span v-if="transferBatchError" class="transfer-batch-toolbar__error" role="alert">{{ transferBatchError }}</span>
            </div>
            <div :inert="transferListRefreshFailed ? true : null" :class="{ 'inventory-list-result': inventoryListPage }">
<section class="content-card table-card" :class="{ 'table-card--stock-scroll': stockTableFixed || active === 'warehouse', 'table-card--grouped-scroll': groupedListPages.has(active), 'table-card--inventory-list-page': inventoryListPage }"><header class="table-meta"><span>共 {{ remoteTotal }} 条记录</span><span v-if="listDateRange" class="table-meta__note">业务日期：{{ listDateRange.from }} 至 {{ listDateRange.to }}</span><div v-if="!(mode === 'store' && active === 'outbound')"><span v-if="active !== 'stock' && active !== 'request'" class="table-meta__note">导出将在对应权威导出任务接入后开放</span></div></header><p v-if="listError" class="inventory-load-error">{{ listError }}</p><div v-else ref="storeListTableScroll" class="table-scroll"><table v-if="!groupedListPages.has(active)"><thead><tr><th v-for="column in currentPage.columns" :key="column">{{ column }}</th><th>操作</th></tr></thead><tbody><tr v-if="active === 'stock' && stockTableLayout.summaryRow === true && !listLoading && currentRows.length" class="table-summary-row"><td v-for="(cell, cellIndex) in stockSummaryRow" :key="`stock-summary-${cellIndex}`">{{ cell }}</td><td>-</td></tr><tr v-if="listLoading"><td :colspan="currentPage.columns.length + 1" class="table-empty">正在读取库存数据...</td></tr><tr v-else-if="!currentRows.length"><td :colspan="currentPage.columns.length + 1" class="table-empty">暂无符合条件的记录</td></tr><tr v-else v-for="(row, index) in currentRows" :key="index"><td v-for="(cell, cellIndex) in row" :key="cellIndex"><span v-if="isOperationalStatusCell(active, cellIndex)" class="state-tag">{{ cell }}</span><template v-else>{{ cell }}</template></td><td><template v-if="active === 'warehouse'"><button class="text-button" :disabled="!platformCanManageWarehouses" @click="openEditor('warehouse-edit', sourceRows[index])">设置</button></template><template v-else-if="active === 'recipe'"><button class="text-button" @click="openRecipeEditor(sourceRows[index])">编辑</button><button class="text-button" @click="toggleRecipeStatus(sourceRows[index])">{{ Number(sourceRows[index].status) === 1 ? '停用' : '启用' }}</button><button class="text-button text-button--danger" @click="deleteRecipe(sourceRows[index])">删除</button></template><template v-else><button class="text-button" @click="active === 'stock' ? openStockDetail(sourceRows[index]) : active === 'import' ? openImportDetail(sourceRows[index]) : openEditor(`${active}-detail`, sourceRows[index])">{{ active === 'stock' ? '查看进出记录' : '查看' }}</button><button v-if="active === 'count' && Number(sourceRows[index]?.draft_id || 0) > 0" class="text-button text-button--danger" :disabled="countDraftDeleteBusy" @click="requestDeleteCountDraft(sourceRows[index])">删除</button></template></td></tr>
            </tbody></table>
            <!-- 两端共用单据单行信息带和商品表头；仅能力按钮及仓库上下文来自各自权威接口。 -->
            <table v-else class="inventory-grouped-table" :class="`inventory-grouped-table--${active}`" :aria-label="`${currentPage.title}明细列表`">
              <thead><tr><th v-for="column in groupedDetailColumns[active]" :key="column">{{ column }}</th></tr></thead>
              <tbody>
                <tr v-if="listLoading"><td :colspan="groupedDetailColumns[active].length" class="table-empty">正在读取单据数据...</td></tr>
                <tr v-else-if="!currentRows.length"><td :colspan="groupedDetailColumns[active].length" class="table-empty">暂无符合条件的记录</td></tr>
                <template v-else v-for="(row, index) in currentRows" :key="groupedDetailKey(sourceRows[index])">
                  <tr class="inventory-group-row"><td :colspan="groupedDetailColumns[active].length"><div class="inventory-group-line">
                    <label v-if="active === 'transfer' && (sourceRows[index].can_delete || sourceRows[index].can_dispatch || sourceRows[index].can_withdraw)" class="inventory-group-select"><input type="checkbox" :checked="selectedTransferIds.includes(Number(sourceRows[index].id))" :disabled="transferBatchPreparing || transferActionSubmitting" :aria-label="`选择调拨单 ${sourceRows[index].order_sn || sourceRows[index].id}`" @change="toggleTransferSelection(sourceRows[index])" /></label>
                    <span v-for="(cell, cellIndex) in row" :key="cellIndex"><strong>{{ currentPage.columns[cellIndex] }}：</strong><b>{{ cell }}</b></span>
                    <span class="inventory-group-line__actions">
                      <button class="text-button" @click="openGroupedRowDetail(active, sourceRows[index])">查看</button>
                      <button v-if="!platformReadScopeMode && groupedDetails[groupedDetailKey(sourceRows[index])]?.detail?.remark_editable" class="text-button" @click="openRemarkEditor(active, sourceRows[index])">编辑备注</button>
                      <button v-if="active === 'inbound'" class="text-button" @click="openInboundOutboundDetails(sourceRows[index])">出库明细</button>
                      <button v-if="active === 'request' && mode === 'platform' && sourceRows[index].can_bind_supply" class="text-button" @click="legacyRequestSupplyRow = sourceRows[index]; warehouseChoice = ''; warehouseChoiceKind = 'request-supply'">确认供货仓</button>
                      <button v-if="active === 'request' && sourceRows[index].can_transfer" class="text-button" @click="openRequestTransfer(sourceRows[index])">转调拨</button>
                      <button v-if="active === 'request' && sourceRows[index].can_edit" class="text-button" @click="openRequestEditor(sourceRows[index])">编辑</button>
                      <button v-if="active === 'transfer' && sourceRows[index].can_edit" class="text-button" @click="openTransferEditor(sourceRows[index])">编辑</button>
                      <button v-if="active === 'transfer' && sourceRows[index].can_delete" class="text-button text-button--danger" @click="requestDeleteTransferDraft(sourceRows[index])">删除</button>
                      <button v-if="active === 'transfer' && sourceRows[index].can_dispatch" class="text-button" @click="runTransferAction('dispatch', sourceRows[index])">发货</button>
                      <button v-if="active === 'transfer' && sourceRows[index].can_receive" class="text-button" @click="runTransferAction('receive', sourceRows[index])">收货</button>
                      <button v-if="active === 'transfer' && sourceRows[index].can_withdraw" class="text-button" @click="runTransferAction('withdraw', sourceRows[index])">撤回</button>
                      <button v-if="active === 'transfer' && sourceRows[index].can_reverse" class="text-button text-button--danger" @click="runDocumentAction('void', sourceRows[index], 'transfer')">作废</button>
                      <button v-if="sourceRows[index].can_void && active !== 'transfer'" class="text-button" @click="runDocumentAction(active === 'request' ? 'voidRequest' : 'void', sourceRows[index], active)">作废</button>
                    </span>
                  </div></td></tr>
                  <tr v-if="!groupedDetails[groupedDetailKey(sourceRows[index])] || groupedDetails[groupedDetailKey(sourceRows[index])].status === 'loading'" class="inventory-detail-message-row"><td :colspan="groupedDetailColumns[active].length">正在读取明细...</td></tr>
                  <tr v-else-if="groupedDetails[groupedDetailKey(sourceRows[index])].status === 'error'" class="inventory-detail-message-row inventory-detail-message-row--error"><td :colspan="groupedDetailColumns[active].length">{{ groupedDetails[groupedDetailKey(sourceRows[index])].message }}</td></tr>
                  <tr v-else-if="!groupedDetails[groupedDetailKey(sourceRows[index])].lines.length" class="inventory-detail-message-row"><td :colspan="groupedDetailColumns[active].length">该单据没有商品明细</td></tr>
                  <tr v-else v-for="(line, lineIndex) in groupedDetails[groupedDetailKey(sourceRows[index])].lines" :key="line.id || line.line_id || lineIndex" class="inventory-detail-line"><td v-for="(cell, cellIndex) in groupedLineCells(active, line, groupedDetails[groupedDetailKey(sourceRows[index])].detail)" :key="cellIndex">{{ mode === 'platform' && active === 'transfer' && cellIndex === 5 ? transferStatusDisplay(groupedDetails[groupedDetailKey(sourceRows[index])].detail) : cell }}</td></tr>
                </template>
              </tbody>
            </table>
          </div><footer v-if="inventoryListPage" class="inventory-warehouse-pagination inventory-list-pagination"><span>共 {{ remoteTotal }} {{ inventoryListUnit }} · 每页 100 {{ inventoryListUnit }} · 第 {{ inventoryListCurrentPage }} / {{ inventoryListTotalPages }} 页</span><button class="secondary-button" :disabled="listLoading || inventoryListCurrentPage <= 1" @click="turnInventoryListPage(-1)">上一页</button><span class="inventory-list-pagination__current">{{ inventoryListCurrentPage }}</span><button class="secondary-button" :disabled="listLoading || inventoryListCurrentPage >= inventoryListTotalPages" @click="turnInventoryListPage(1)">下一页</button></footer></section>
            </div>
        </template>
      </section>
    </main>

  </div>
</template>
