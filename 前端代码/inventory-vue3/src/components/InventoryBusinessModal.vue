<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { AlertTriangle, Barcode, FileSpreadsheet, Plus, QrCode, Search, Trash2, X } from '@lucide/vue'
import { inventoryApi, platformInventoryApi } from '../services/inventoryApi'
import InventoryProductSelector from './InventoryProductSelector.vue'
import InventoryStoreSelector from './InventoryStoreSelector.vue'
import { inventoryStatusLabel } from '../statusLabels'
import { exportCountXlsx, parseCountXlsx, matchCountProduct } from './countWorksheet'
import { countedRows, invalidCountCatalogMessage, invalidCountCatalogRowIndexes, missingCountSurplusMessage, reindexCountValidationRowsAfterRemoval, submittedCountRowIndex, sumCountBookQuantities, validateCountSubmissionRows } from '../utils/countSubmission'
import { validateInboundDates } from '../inboundDateValidation'
import { formatBatchNumber } from '../batchNumberDisplay.js'

const props = defineProps({
  pageKey: { type: String, default: '' },
  modalKind: { type: String, default: '' },
  scopeName: { type: String, default: '' },
  defaultLocationName: { type: String, default: '' },
  warehouseOptions: { type: Array, default: () => [] },
  warehouseSelection: { type: String, default: '' },
  hqLocationId: { type: Number, default: 0 },
  usageStoreId: { type: Number, default: 0 },
  defaultBusinessDate: { type: String, default: '' },
  detail: { type: Object, default: null },
  mode: { type: String, default: 'store' }
})

const emit = defineEmits(['close', 'saved', 'edit-count-draft'])

// 业务日期必须按系统的中国业务时区生成；不能依赖浏览器进程时区或 UTC ISO 日期，
// 否则容器浏览器会默认到前一天，导致新单立即被列表的“今日”条件过滤。
function localBusinessDate() {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit'
  }).formatToParts(new Date())
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return `${values.year}-${values.month}-${values.day}`
}

function initialBusinessDate() {
  return /^\d{4}-\d{2}-\d{2}$/.test(props.defaultBusinessDate) ? props.defaultBusinessDate : localBusinessDate()
}

const inboundType = ref('采购入库')
const outboundType = ref('过期退货')
const inboundTypes = ['初始入库', '采购入库', '退货入库', '其他入库']
const outboundTypes = ['过期退货', '试用出库', '报废出库', '其他出库', '对内出库', '对外出库']
const selectedRows = ref([])
// 盘点单标题只反映当前已加载明细的账面快照，增删、清空和恢复草稿后由响应式明细同步更新。
const countBookQuantityTotal = computed(() => sumCountBookQuantities(selectedRows.value))
const selectedLineIndexes = ref([])
const bulkEditorVisible = ref(false)
const bulkValues = ref({})
const catalogError = ref('')
const productSelectorVisible = ref(false)
const countFileInput = ref(null)
const countLoading = ref(false)
const countProgress = ref('')
const countValidationRowIndexes = ref([])
const countValidationKind = ref('')
const lineTableBody = ref(null)
const scannerVisible = ref(false)
const scannerCode = ref('')
const scannerLoading = ref(false)
const inboundDate = ref(initialBusinessDate())
const inboundRemark = ref('')
const outboundDate = ref(initialBusinessDate())
const outboundRemark = ref('')
// Optional recipient is a document snapshot, not a member/staff identifier.
const outboundRecipient = ref('')
const inboundAttempt = ref(null)
const outboundAttempt = ref(null)
const countRemark = ref('')
const countDraftId = ref(0)
const countDraftVersion = ref(0)
const countDraftSavedSnapshot = ref('')
const requestDate = ref(initialBusinessDate())
const requestRemark = ref('')
const requesterName = ref('')
const requestPartyOptions = ref([])
const requestPartySelection = ref('HQ:0')
const requesterOptions = ref([])
const requesterSelection = ref('')
const supplyParties = ref([])
const supplyPartySelection = ref('')
const supplyPartiesLoading = ref(false)
const transferDate = ref(initialBusinessDate())
const transferRemark = ref('')
// The request shortcut selects the supplier as transfer source; no stock data is copied from the list.
const transferSourceSelection = ref(props.detail?.transfer_request_id ? `${props.detail.transfer_source_type}:${Number(props.detail.transfer_source_type === 'HQ' ? props.detail.transfer_source_location_id : props.detail.transfer_source_id)}` : ('HQ:' + Number(props.hqLocationId)))
const transferCounterparties = ref([])
const transferTargetSelection = ref('')
const incomingRequests = ref([])
const requestDocumentId = ref(Number(props.modalKind === 'transfer-edit' ? props.detail?.request_document_id || 0 : 0))
let transferRequestSeedPending = Number(props.detail?.transfer_request_id || 0) > 0
const transferStaffOptions = ref([])
const transferStaffId = ref(0)
const usageDate = ref(initialBusinessDate())
const usageProjectId = ref('')
const usageProjectName = ref('')
const usageProjectKeyword = ref('')
const usageProjects = ref([])
const usageProjectsLoading = ref(false)
const usageProjectsError = ref('')
let usageProjectRequestSerial = 0
const usageRemark = ref('')
const documentWarehouseSelection = ref(`HQ:${Number(props.hqLocationId) || 0}`)
const usageWarehouseSelection = ref(props.mode === 'platform' ? props.warehouseSelection : `STORE:${Number(props.usageStoreId) || 0}`)
const warehouseStoreId = ref(Number(props.detail?.id || props.hqLocationId || 0))
const warehouseStatus = ref(String(props.detail?.location_status || 'ACTIVE'))
const warehouseCreateAttempt = ref({ content: '', key: '' })
const warehouseName = ref(String(props.detail?.location_name || ''))
const submitting = ref(false)
const submitError = ref('')
const importFile = ref(null)
const importFileName = ref('')
const importBusy = ref(false)
const importMessage = ref('')
const platformImportStoreIds = ref([])
const platformImportStorePickerVisible = ref(false)
const requestEditIdempotencyKey = ref('')

const isDetail = computed(() => props.modalKind.endsWith('-detail'))
const isInboundOutboundDetail = computed(() => props.modalKind === 'inbound-outbound-detail')
const isRequestEdit = computed(() => props.modalKind === 'request-edit')
const isTransferEdit = computed(() => props.modalKind === 'transfer-edit')
const isCountEdit = computed(() => props.modalKind === 'count-edit')
const isCountDraftDetail = computed(() => props.modalKind === 'count-draft-detail')
const isImport = computed(() => props.modalKind === 'import')
const isPlatformHeadquarters = computed(() => props.mode === 'platform')
 // HQ描述符携带实际仓库ID；门店描述符保留门店ID，两种身份不能混用。
 const commandHqLocationId = computed(() => {
   const selection = props.pageKey === 'request' ? requestPartySelection.value
     : props.pageKey === 'transfer' ? transferSourceSelection.value
       : ['inbound', 'outbound'].includes(props.pageKey) ? documentWarehouseSelection.value
         : props.pageKey === 'usage' && usageWarehouseSelection.value.startsWith('HQ:') ? usageWarehouseSelection.value : ''
   const [type, id] = String(selection).split(':')
   return type === 'HQ' && Number(id) > 0 ? Number(id) : Number(props.hqLocationId)
 })
 const warehouseSelection = (type, partyId, locationId) => type + ':' + (type === 'HQ' ? Number(locationId) : Number(partyId))

const platformImportStores = computed(() => {
  const hq = props.warehouseOptions.filter((location) => location.location_status !== 'INACTIVE' && String(location?.location_type || '') === 'HQ' && Number(location?.id || 0) > 0).map((location) => ({ ...location, import_key: `HQ:${Number(location.id)}`, import_name: location.location_name || '总部仓', import_type: '总部仓' }))
  const stores = props.warehouseOptions.filter((location) => String(location?.location_type || '') === 'STORE' && Number(location?.store_id || 0) > 0).map((location) => ({ ...location, import_key: `STORE:${Number(location.store_id)}`, import_name: location.store_name || location.store_name_snapshot || `门店${location.store_id}`, import_type: '门店' }))
  return [...hq, ...stores].filter((item, index, list) => list.findIndex((candidate) => candidate.import_key === item.import_key) === index)
})
const documentWarehouseOptions = computed(() => platformImportStores.value.filter((item) => item.import_type === '总部仓').map((item) => ({ key: item.import_key, name: item.import_name, type: 'HQ' })))
const usageWarehouseOptions = computed(() => platformImportStores.value.filter((item) => item.location_status === 'ACTIVE').map((item) => ({ key: item.import_key, name: item.import_name, type: item.import_key.startsWith('HQ:') ? 'HQ' : 'STORE' })))
const usageWarehouseScope = computed(() => {
  const [type, rawId] = String(usageWarehouseSelection.value || '').split(':', 2)
  return type === 'HQ' ? { hq_location_id: Number(rawId) || 0 } : { store_id: Number(rawId) || 0 }
})
const catalogApi = computed(() => isPlatformHeadquarters.value ? platformInventoryApi : inventoryApi)
const catalogQuery = computed(() => {
  // 盘点的账面数要与确认命令锁定的当前库存一致；普通选品仍沿用原目录口径。
  if (!isPlatformHeadquarters.value) return props.pageKey === 'count' ? { for_count: 1, include_count_batches: 1 } : {}
  // 请货明细仍按“请货方”目录保存，保证后端可校验请求商品；
  // “当前库存”则必须来自供货方库存主体，不能误展示请货方库存。
  const selection = props.pageKey === 'request'
    ? requestPartySelection.value
    : props.pageKey === 'usage' ? usageWarehouseSelection.value
      : ['inbound', 'outbound'].includes(props.pageKey) ? documentWarehouseSelection.value : (transferSourceSelection.value || 'HQ:0')
  const [sourcePartyType, rawSourceId] = String(selection).split(':', 2)
  const availabilitySelection = props.pageKey === 'request'
    ? (supplyPartySelection.value || selection)
    : selection
  const [availabilityPartyType, rawAvailabilityId] = String(availabilitySelection).split(':', 2)
  return {
    hq_location_id: Number(commandHqLocationId.value),
    source_party_type: sourcePartyType,
    source_store_id: sourcePartyType === 'HQ' ? 0 : Number(rawSourceId || 0),
    availability_party_type: availabilityPartyType,
    availability_store_id: availabilityPartyType === 'HQ' ? 0 : Number(rawAvailabilityId || 0), availability_location_id: selectedSupplyParty().locationId || Number(commandHqLocationId.value),
    ...(props.pageKey === 'count' ? { include_count_batches: 1 } : {})
  }
})
const warehouseStoreOptions = computed(() => {
  const seen = new Set()
  return props.warehouseOptions.filter((location) => {
    const storeId = Number(location?.owner_id || 0)
    if (location.location_type !== 'HQ' || storeId <= 0 || seen.has(storeId)) return false
    seen.add(storeId)
    return true
  })
})
const title = computed(() => {
  if (props.modalKind === 'warehouse-edit') return '仓库设置'
  if (isImport.value) return '库存导入'
  if (isInboundOutboundDetail.value) return '入库批次出库明细'
  if (isDetail.value) return `${pageLabel.value}详情`
  if (isCountEdit.value) return '编辑盘点草稿'
  if (isRequestEdit.value) return '编辑请货单'
  if (isTransferEdit.value) return '编辑调拨草稿'
  return {
    inbound: '添加入库单', outbound: '添加出库单', count: '添加盘点单',
    request: '新建请货单', transfer: '新建跨门店调拨', usage: '院装耗材领用', warehouse: '新建仓库', recipe: '新建配方'
  }[props.pageKey] || '库存业务详情'
})
const pageLabel = computed(() => ({ inbound: '入库单', outbound: '出库单', count: '盘点单', request: '请货单', transfer: '调拨单', warehouse: '仓库', recipe: '项目配方', stock: '库存明细', usage: '院装管理', import: '导入记录' }[props.pageKey] || '库存业务'))
const selectedType = computed(() => props.pageKey === 'inbound' ? inboundType.value : outboundType.value)
const lineEditorKeys = ['inbound', 'outbound', 'count', 'request', 'transfer', 'usage']
const primarySubmitDisabled = computed(() => {
  // 统一移动提交入口时保留各业务原有门禁，按钮位置不得放宽数据完整性校验。
  if (submitting.value) return true
  if (lineEditorKeys.includes(props.pageKey) && !selectedRows.value.length) return true
  if (isPlatformHeadquarters.value && props.pageKey === 'usage' && !usageWarehouseOptions.value.some((item) => item.key === usageWarehouseSelection.value)) return true
  if (isPlatformHeadquarters.value && ['inbound', 'outbound'].includes(props.pageKey) && !documentWarehouseOptions.value.some((item) => item.key === documentWarehouseSelection.value)) return true
  // 院装领用可以不关联核销项目；作废走独立命令，不复用领用表单。
  if (props.pageKey === 'request' && (!supplyPartySelection.value || !requesterName.value.trim() || selectedSupplyParty().locationId <= 0)) return true
  if (props.pageKey === 'transfer' && (!transferTargetSelection.value || (isPlatformHeadquarters.value && !transferStaffOptions.value.length))) return true
  return props.pageKey === 'warehouse' && (Number(warehouseStoreId.value) <= 0 || !warehouseName.value.trim())
})
const primarySubmitLabel = computed(() => {
  if (submitting.value) return '提交中'
  if (props.pageKey === 'request') return isRequestEdit.value ? '保存修改' : '确认申请'
  if (props.pageKey === 'transfer') return isTransferEdit.value ? '保存修改' : '保存调拨草稿'
  if (props.pageKey === 'usage') return '确认领用'
  if (props.pageKey === 'warehouse') return props.modalKind === 'warehouse-edit' ? '保存设置' : '创建仓库'
  return '保存'
})
const requestDetailLines = computed(() => Array.isArray(props.detail?.lines) ? props.detail.lines : [])
const transferDetailLines = computed(() => Array.isArray(props.detail?.lines) ? props.detail.lines : [])
const modalScopeName = computed(() => {
  if (props.pageKey === 'warehouse') return props.warehouseOptions.find(item => Number(item.id) === Number(warehouseStoreId.value))?.organization_name_snapshot || '总部仓'
  if (props.pageKey === 'request' && isPlatformHeadquarters.value) return requestPartyOptions.value.find(item => item.key === requestPartySelection.value)?.name || props.scopeName
  if (props.pageKey !== 'transfer' || !isPlatformHeadquarters.value) return props.scopeName
  return transferSourceOptions.value.find((option) => String(option.key) === String(transferSourceSelection.value))?.name || props.scopeName
})
const transferSourceOptions = computed(() => {
  if (!isPlatformHeadquarters.value) return []
  const stores = props.warehouseOptions
    .filter((location) => Number(location?.store_id || 0) > 0)
    .map((location) => ({ key: `STORE:${Number(location.store_id)}`, type: 'STORE', id: Number(location.store_id), name: String(location.store_name_snapshot || location.store_name || `门店${location.store_id}`) }))
  return [...props.warehouseOptions.filter((item) => item.location_type === 'HQ' && item.location_status !== 'INACTIVE').map((item) => ({ key: 'HQ:' + Number(item.id), type: 'HQ', id: 0, name: item.location_name })), ...stores.filter((item, index, list) => list.findIndex((candidate) => candidate.key === item.key) === index)]
})
const transferTargetOptions = computed(() => transferCounterparties.value
  .map((item) => ({ key: warehouseSelection(String(item?.party_type || 'STORE').toUpperCase(), item.id || item.store_id, item.location_id), type: String(item?.party_type || 'STORE').toUpperCase(), id: Number(item.id || item.store_id || 0), name: String(item.name || item.store_name || '') })))

function formatQuantity(units, scale = 0) {
  const divisor = 10 ** Number(scale || 0)
  const value = Number(units || 0) / divisor
  return Number.isInteger(value) ? String(value) : value.toFixed(Number(scale || 0)).replace(/0+$/, '').replace(/\.$/, '')
}

function allocationStatus(allocation) {
  if (Number(allocation?.received_at || 0) > 0) return '已收货'
  if (Number(allocation?.dispatched_at || 0) > 0) return '在途'
  return '待发货'
}

function detailStatus(detail, fallback = '-') {
  return inventoryStatusLabel(detail?.status_name || detail?.document_status, fallback)
}

// 盘点金额由服务端已结算库存流水返回分值；这里只按统一页面口径取整展示，不能在浏览器用数量重算。
function countAmount(cents) {
  if (cents === null || cents === undefined || cents === '') return '-'
  const value = Number(cents)
  if (!Number.isFinite(value)) return '-'
  const yuan = Math.sign(value) * Math.round(Math.abs(value) / 100)
  return `${yuan < 0 ? '-' : ''}¥${Math.abs(yuan).toLocaleString('zh-CN')}`
}

const productRows = computed(() => {
  // 单位来自商品 SKU 的库存单位快照，紧跟规格展示；禁止用数量字段或商品名称在前端推断。
  if (['inbound', 'outbound'].includes(props.pageKey)) {
    return selectedRows.value.map((row) => props.pageKey === 'inbound'
      ? [row.product_id, row.product_name, row.sku_name, row.stock_unit || '-', row.batch_no, row.manufactured_date, row.expire_date, row.quantity, row.unit_cost, row.detail_remark, '']
      : [row.product_id, row.product_name, row.sku_name, row.stock_unit || '-', row.available_quantity ?? '-', '系统优先扣减最早到期的可用批次', '提交后返回', row.quantity, '系统计算', row.detail_remark, '系统计算'])
  }
  if (props.pageKey === 'count') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.stock_unit || '-', row.barcode, row.book_quantity, row.counted_quantity, Number(row.counted_quantity || 0) - Number(row.book_quantity || 0), row.surplus_batch_no, row.surplus_unit_cost, row.surplus_manufactured_date, row.surplus_expire_date])
  if (props.pageKey === 'request') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.stock_unit || '-', row.available_quantity || '-', row.quantity, row.detail_remark])
  if (props.pageKey === 'transfer') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.stock_unit || '-', '发货时优先扣减最早到期的可用批次', '收货时入账', row.quantity, row.detail_remark])
  if (props.pageKey === 'usage') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.stock_unit || '-', row.available_quantity ?? '-', row.quantity, '系统优先扣减最早到期的可用批次', row.detail_remark])
  return []
})

const columns = computed(() => {
  const product = ['商品ID', '商品名称', '规格', '单位']
  const batch = ['批次号', '生产日期', '到期日']
  if (props.pageKey === 'count') return [...product, '商品条码', '账面库存', '实盘库存', '库存盈亏', '盘盈批次号', '盘盈单价', '生产日期', '到期日']
  if (props.pageKey === 'request') return [...product, '当前库存', '申请数量', '明细备注']
  if (props.pageKey === 'transfer') return [...product, '发货批次', '收货状态', '调拨数量', '明细备注']
  // 总库存与业务录入数量分列；禁止用领用/出库数量或单个批次替代总库存。
  if (props.pageKey === 'usage') return [...product, '库存数量', '领用数量', '成本单价', '明细备注']
  if (props.pageKey === 'outbound') return [...product, '库存数量', '扣减批次', '到期日', '出库数量', '成本单价', '明细备注', '成本金额']
  return [...product, ...batch, '入库数量', '入库单价', '明细备注', '入库金额']
})

const bulkFields = computed(() => ({
  inbound: [
    { key: 'batch_no', label: '批次号', type: 'text' }, { key: 'manufactured_date', label: '生产日期', type: 'date' },
    { key: 'expire_date', label: '到期日', type: 'date' }, { key: 'quantity', label: '入库数量', type: 'text' }, { key: 'unit_cost', label: '入库单价', type: 'text' }
  ],
  outbound: [{ key: 'quantity', label: '出库数量', type: 'text' }],
  count: [
    { key: 'counted_quantity', label: '实盘数量', type: 'text' }, { key: 'surplus_batch_no', label: '盘盈批次号', type: 'text' },
    { key: 'surplus_unit_cost', label: '盘盈单价', type: 'text' }, { key: 'surplus_manufactured_date', label: '生产日期', type: 'date' }, { key: 'surplus_expire_date', label: '到期日', type: 'date' }
  ],
  request: [{ key: 'quantity', label: '申请数量', type: 'text' }],
  transfer: [{ key: 'quantity', label: '调拨数量', type: 'text' }],
  usage: [{ key: 'quantity', label: '领用数量', type: 'text' }]
}[props.pageKey] || []))
const allLinesSelected = computed(() => selectedRows.value.length > 0 && selectedLineIndexes.value.length === selectedRows.value.length)

function toggleAllLines() {
  selectedLineIndexes.value = allLinesSelected.value ? [] : selectedRows.value.map((_, index) => index)
}

function removeSelectedRows() {
  if (!selectedLineIndexes.value.length) return
  selectedRows.value = selectedRows.value.filter((_, index) => !selectedLineIndexes.value.includes(index))
  selectedLineIndexes.value = []
}

function applyBulkValues() {
  if (!selectedLineIndexes.value.length) return
  const values = bulkValues.value
  selectedRows.value.forEach((row, index) => {
    if (!selectedLineIndexes.value.includes(index)) return
    bulkFields.value.forEach((field) => {
      // 同一单可混合两种商品；批量填写不得给免录行补造日期或覆盖历史批次身份。
      if (Number(row.no_expiry_management) === 1 && ['batch_no', 'manufactured_date', 'expire_date', 'surplus_batch_no', 'surplus_manufactured_date', 'surplus_expire_date'].includes(field.key)) return
      const value = values[field.key]
      if (value !== '' && value !== undefined && value !== null) {
        row[field.key] = value
        if (field.key === 'manufactured_date' || field.key === 'expire_date') row[`${field.key}_bad_input`] = false
      }
    })
  })
  bulkEditorVisible.value = false
  bulkValues.value = {}
}

function close() { emit('close') }

const importDirection = computed(() => props.pageKey === 'outbound' ? 'outbound' : 'inbound')

function saveBlob(blob, fileName) {
  const url = window.URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = fileName
  anchor.style.display = 'none'
  document.body.appendChild(anchor)
  anchor.click()
  document.body.removeChild(anchor)
  window.URL.revokeObjectURL(url)
}

async function downloadImportTemplate() {
  importBusy.value = true
  importMessage.value = ''
  submitError.value = ''
  try {
    const templateStoreId = isPlatformHeadquarters.value ? Number(platformImportStoreIds.value[0] || 0) : 0
    if (isPlatformHeadquarters.value && !platformImportStoreIds.value.length) throw new Error('请先选择至少一个库存仓，再下载模板。')
    const api = isPlatformHeadquarters.value ? platformInventoryApi : inventoryApi
    const blob = await api.downloadImportTemplate(importDirection.value, templateStoreId, platformImportStoreIds.value)
    saveBlob(blob, importDirection.value === 'inbound' ? 'V3入库导入模板.xlsx' : 'V3出库导入模板.xlsx')
    importMessage.value = '模板已下载。请按模板填写后上传。'
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : '模板下载失败。'
  } finally {
    importBusy.value = false
  }
}

function selectImportFile(event) {
  acceptImportFile(event?.target?.files?.[0] || null)
}

function acceptImportFile(file) {
  if (!file) return
  if (!/\.xlsx$/i.test(String(file.name || ''))) {
    importFile.value = null
    importFileName.value = ''
    submitError.value = '只支持 xlsx 文件。'
    return
  }
  importFile.value = file
  importFileName.value = file?.name || ''
  importMessage.value = ''
  submitError.value = ''
}

function dropImportFile(event) {
  acceptImportFile(event?.dataTransfer?.files?.[0] || null)
}

function newRequestIdempotencyKey() {
  return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? `request-edit-${crypto.randomUUID()}`
    : `request-edit-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

async function hydrateRequestEditor(detail) {
  const document = detail?.document
  if (!document || !isRequestEdit.value) return
  requestDate.value = String(document.business_date || requestDate.value)
  requestRemark.value = String(document.remark || '')
  requesterName.value = String(document.requester_name_snapshot || '')
  requestPartySelection.value = `${String(document.request_party_type || 'STORE')}:${Number(document.request_party_id || document.store_id || 0)}`
  supplyPartySelection.value = warehouseSelection(String(document.supply_party_type || 'HQ'), document.supply_party_id, document.supply_location_id)
  selectedRows.value = (Array.isArray(detail.lines) ? detail.lines : []).map((line) => ({
    product_id: Number(line.product_id), sku_id: Number(line.sku_id), sku_unique: String(line.sku_unique || ''),
    product_name: String(line.product_name_snapshot || ''), sku_name: String(line.sku_name_snapshot || ''),
    stock_unit: String(line.stock_unit_snapshot || ''), barcode: String(line.barcode_snapshot || ''), quantity: String(line.quantity || '1'), detail_remark: String(line.detail_remark || ''), available_quantity: '-', reference_unit_cost: line.reference_unit_cost_cents === null ? '-' : (Number(line.reference_unit_cost_cents) / 100).toFixed(2)
  }))
  requestEditIdempotencyKey.value = newRequestIdempotencyKey()
  await loadSupplyParties()
}

watch(() => props.detail, (detail) => { hydrateRequestEditor(detail) }, { immediate: true })

/** 两端恢复权威草稿快照；源方保持固定，发货时再复核库存和请货余量。 */
function hydrateTransferEditor(detail) {
  if (!isTransferEdit.value || !detail?.can_edit) return
  transferDate.value = String(detail.business_date || transferDate.value)
  transferRemark.value = String(detail.remark || '')
  transferSourceSelection.value = warehouseSelection(String(detail.from_party_type || 'HQ'), detail.from_party_id, detail.from_location_id)
  transferStaffId.value = Number(detail.transfer_staff_id || 0)
  transferTargetSelection.value = warehouseSelection(String(detail.to_party_type || 'STORE'), detail.to_party_id, detail.to_location_id)
  requestDocumentId.value = Number(detail.request_document_id || 0)
  selectedRows.value = (Array.isArray(detail.lines) ? detail.lines : []).map((line) => ({
    product_id: Number(line.from_product_id), sku_id: Number(line.from_sku_id),
    sku_unique: String(line.from_sku_unique || ''), product_name: String(line.product_name_snapshot || ''),
    sku_name: String(line.sku_name_snapshot || ''), stock_unit: String(line.stock_unit_snapshot || ''),
    quantity: formatQuantity(line.requested_quantity_units, line.quantity_scale),
    request_line_id: Number(line.request_line_id || 0), detail_remark: String(line.detail_remark || ''),
  }))
}

watch(() => props.detail, hydrateTransferEditor, { immediate: true })

/** Resume the exact selected SKU set and entered quantities; final confirmation still rechecks live stock. */
watch([() => props.detail, isCountEdit], ([detail]) => {
  if (!isCountEdit.value || !detail?.draft_id) return
  countDraftId.value = Number(detail.draft_id)
  countDraftVersion.value = Number(detail.version)
  inboundDate.value = String(detail.business_date || inboundDate.value)
  countRemark.value = String(detail.remark || '')
  selectedRows.value = (Array.isArray(detail.lines) ? detail.lines : []).map((line) => ({ ...line }))
  countDraftSavedSnapshot.value = JSON.stringify(countDraftContent())
  countProgress.value = `已恢复草稿中的 ${selectedRows.value.length} 行；完成盘点以实盘数为最终库存。`
}, { immediate: true })

async function loadUsageProjects() {
  if (props.pageKey !== 'usage') return
  const requestSerial = ++usageProjectRequestSerial
  usageProjectsLoading.value = true
  usageProjectsError.value = ''
  try {
    const api = isPlatformHeadquarters.value ? platformInventoryApi : inventoryApi
    const response = await api.salonUsageProjects({ keyword: usageProjectKeyword.value.trim(), page: 1, limit: 50, ...(isPlatformHeadquarters.value ? usageWarehouseScope.value : {}) })
    if (requestSerial !== usageProjectRequestSerial) return
    usageProjects.value = Array.isArray(response?.list) ? response.list : []
  } catch (error) {
    if (requestSerial !== usageProjectRequestSerial) return
    usageProjects.value = []
    usageProjectsError.value = error instanceof Error ? error.message : '核销项目读取失败。'
  } finally {
    if (requestSerial === usageProjectRequestSerial) usageProjectsLoading.value = false
  }
}

function selectUsageProject() {
  const selected = usageProjects.value.find((project) => Number(project?.id) === Number(usageProjectId.value))
  usageProjectName.value = selected ? String(selected.name || '') : ''
}

/** 仓库变动会改变可用库存与项目资格；旧仓的已选行不能进入新仓命令。 */
function onDocumentWarehouseChanged() {
  selectedRows.value = []
  selectedLineIndexes.value = []
  productSelectorVisible.value = false
  scannerVisible.value = false
  catalogError.value = ''
}

function onUsageWarehouseChanged() {
  onDocumentWarehouseChanged()
  usageProjectId.value = ''
  usageProjectName.value = ''
  usageProjects.value = []
  loadUsageProjects()
}

watch(() => props.pageKey, (pageKey) => {
  if (pageKey === 'usage') loadUsageProjects()
  if (pageKey === 'transfer') loadTransferContext()
}, { immediate: true })

async function submitImport() {
  if (!importFile.value) {
    submitError.value = '请先选择xlsx文件。'
    return
  }
  importBusy.value = true
  importMessage.value = ''
  submitError.value = ''
  try {
    const uploadApi = isPlatformHeadquarters.value ? platformInventoryApi : inventoryApi
    const upload = await uploadApi.uploadImportFile(importFile.value)
    let result
    if (isPlatformHeadquarters.value) {
      if (!platformImportStoreIds.value.length) throw new Error('请选择至少一个门店。')
      result = await platformInventoryApi.submitImport({ direction: importDirection.value, file: upload.src, real_name: importFileName.value, store_ids: platformImportStoreIds.value })
      importMessage.value = `共 ${result.target_count || 0} 个仓库，成功 ${result.success_count || 0} 个，失败 ${result.failure_count || 0} 个。`
      if (result.failure_count > 0) { submitError.value = (result.targets || []).filter(item => item.status === 'FAILED').map(item => `仓库 ${item.location_id}：${item.message}`).join('；'); return }
    } else {
      result = await inventoryApi.submitImport({ direction: importDirection.value, file: upload.src, real_name: importFileName.value })
      importMessage.value = `导入完成：${result.success_count || 0} 行，业务单号 ${result.document_no || '已生成'}。`
    }
    importFile.value = null
    importFileName.value = ''
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : '导入失败。'
  } finally {
    importBusy.value = false
  }
}

function openCatalogPicker() {
  if (!['inbound', 'outbound', 'count', 'request', 'transfer', 'usage'].includes(props.pageKey)) return
  if (isPlatformHeadquarters.value && Number(commandHqLocationId.value) <= 0) {
    catalogError.value = '请选择可操作的总部仓后再选择商品。'
    return
  }
  catalogError.value = ''
  productSelectorVisible.value = true
}

function openScanner() {
  if (!['inbound', 'outbound'].includes(props.pageKey)) return
  if (isPlatformHeadquarters.value && Number(commandHqLocationId.value) <= 0) {
    catalogError.value = '请选择可操作的总部仓后再扫码添加商品。'
    return
  }
  catalogError.value = ''
  scannerCode.value = ''
  scannerVisible.value = true
}

async function addScannedProduct() {
  const barcode = scannerCode.value.trim()
  if (!barcode || scannerLoading.value) return
  scannerLoading.value = true
  catalogError.value = ''
  try {
    const row = await catalogApi.value.findCatalogByBarcode({ ...catalogQuery.value, barcode })
    await acceptCatalogRows([row])
    scannerVisible.value = false
  } catch (error) {
    catalogError.value = error instanceof Error ? error.message : '扫码添加商品失败。'
  } finally {
    scannerLoading.value = false
  }
}

function inventoryErrorMessage(error, fallback) {
  const message = error instanceof Error ? error.message : ''
  if (message === 'inventory_manual_inbound_dates_required') return '请为每个入库商品填写生产日期和到期日。'
  if (message === 'inventory_manual_inbound_sku_not_found') return '所选商品规格已失效，请重新选择商品。'
  if (message === 'inventory_manual_inbound_operator_scope_denied') return '当前员工无权操作本门店库存。'
  return message || fallback
}

async function acceptCatalogRows(rows) {
  catalogError.value = ''
  const selectedSkuIds = new Set(selectedRows.value.map((item) => Number(item.sku_id)))
  for (const row of rows) {
    if (props.pageKey === 'count' && Number(row.no_expiry_management) !== 1 && Array.isArray(row.count_batches) && row.count_batches.length) {
      // 选品以仓库当前批次为粒度；相同 SKU 的每个批次只补一次，保留已经录入的实盘数。
      if (selectedRows.value.some((item) => Number(item.sku_id) === Number(row.sku_id) && item.count_mode !== 'BATCH')) {
        catalogError.value = `「${row.product_name}」已有按总量盘点的行，请删除该行后再加载库存批次。`
        continue
      }
      const existingBatches = new Set(selectedRows.value.filter((item) => Number(item.sku_id) === Number(row.sku_id) && item.count_mode === 'BATCH').map((item) => String(item.surplus_batch_no)))
      for (const batch of row.count_batches) {
        if (existingBatches.has(String(batch.batch_no))) continue
        selectedRows.value.push(countBatchRow(row, batch))
        existingBatches.add(String(batch.batch_no))
      }
      continue
    }
    if (selectedSkuIds.has(Number(row.sku_id)) && props.pageKey !== 'count') continue
    if (props.pageKey === 'count' && selectedRows.value.some((item) => Number(item.sku_id) === Number(row.sku_id) && item.count_mode !== 'BATCH')) {
      catalogError.value = '该商品已有按总量盘点的明细，请删除该行后再按不同批次添加。'
      continue
    }
    const selected = { ...row, batch_no: '', manufactured_date: '', manufactured_date_bad_input: false, expire_date: '', expire_date_bad_input: false, quantity: '1', unit_cost: '0.00', detail_remark: '', book_quantity: '0', counted_quantity: '0', surplus_batch_no: '', surplus_unit_cost: '', surplus_manufactured_date: '', surplus_expire_date: '', available_quantity: String(row.available_quantity ?? '0'), reference_unit_cost: '-' }
    if (props.pageKey === 'count') {
      // 商品目录已经按当前库存主体返回权威可用数；避免对每个 SKU 再发一次库存查询。
      // 手工重复选择形成独立待填批次，不能复制商品总库存到每个批次。
      // 不管理有效期按规格总量盘点；内部成本层由服务端保留，不要求员工挑选系统批次。
      selected.count_mode = Number(row.no_expiry_management) === 1 ? 'SKU' : 'BATCH'
      selected.book_quantity = selected.count_mode === 'SKU' ? selected.available_quantity : '0'
      selected.counted_quantity = ''
    }
    selectedRows.value.push(selected)
    selectedSkuIds.add(Number(row.sku_id))
  }
  productSelectorVisible.value = false
}

/** 只下载当前表格行的 Excel 工作簿；导入与清零均为草稿操作，不调用库存写接口。 */
async function exportCountRows() {
  if (props.pageKey !== 'count' || !selectedRows.value.length || countLoading.value) return
  countLoading.value = true; catalogError.value = ''
  try {
    // 隐藏粒度列只用于多批次文件往返，屏幕上仍保留产品经理已确认的列布局。
    const exportRows = selectedRows.value.some((row) => row.count_mode === 'BATCH')
      ? productRows.value.map((row, index) => [...row, selectedRows.value[index].count_mode || 'SKU']) : productRows.value
    const bytes = await exportCountXlsx(exportRows)
    saveBlob(new Blob([bytes], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), `盘点资料-${localBusinessDate()}.xlsx`)
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '盘点资料导出失败。' }
  finally { countLoading.value = false }
}

/** 有效目录与现存批次分别读取；现存库存始终由服务端当前门店默认仓限定。 */
async function allCountCatalog(includeBatches = false, forExistingStock = false) {
  const rows = [], seen = new Set()
  let page = 1, total = 0
  do {
    const response = await catalogApi.value.searchCatalog({ ...catalogQuery.value, keyword: '', category_id: 0, page, limit: 50, include_count_batches: includeBatches ? 1 : 0, ...(forExistingStock && !isPlatformHeadquarters.value ? { for_existing_stock: 1 } : {}) })
    const current = Array.isArray(response?.list) ? response.list : []
    total = Number(response?.total || 0)
    if (!current.length && rows.length < total) throw new Error('商品目录未返回完整数据，请稍后重试。')
    for (const item of current) {
      const skuId = Number(item.sku_id)
      if (skuId <= 0 || seen.has(skuId)) continue
      rows.push(item); seen.add(skuId)
    }
    countProgress.value = `已读取 ${Math.min(page * 50, total)} / ${total} 个商品规格`
    page++
  } while ((page - 1) * 50 < total)
  if (rows.length !== total) throw new Error('加载期间商品目录发生变化，请重新加载。')
  return rows
}

function countDraftRow(row) {
  const book = String(row.available_quantity ?? '0')
  return { ...row, batch_no: '', manufactured_date: '', expire_date: '', quantity: '1', unit_cost: '0.00',
    book_quantity: book, counted_quantity: book, surplus_batch_no: '', surplus_unit_cost: '',
    surplus_manufactured_date: '', surplus_expire_date: '', available_quantity: book, reference_unit_cost: '-' }
}

/** 使用当前仓可见批次及其成本权限生成独立盘点行；不从 SKU 汇总数猜测单个批次数据。 */
function countBatchRow(sku, batch) {
  return { ...countDraftRow(sku), count_mode: 'BATCH', book_quantity: String(batch.quantity), counted_quantity: String(batch.quantity),
    surplus_batch_no: String(batch.batch_no), surplus_unit_cost: batch.unit_cost == null ? '' : String(batch.unit_cost),
    surplus_manufactured_date: String(batch.manufactured_date || ''), surplus_expire_date: String(batch.expire_date || '') }
}

/** 替换本地盘点明细时同步清掉旧行索引与校验状态；不触发库存写入或清除已保存草稿。 */
function replaceCountRows(rows) {
  selectedRows.value = rows
  selectedLineIndexes.value = []
  countValidationRowIndexes.value = []
  countValidationKind.value = ''
  bulkEditorVisible.value = false
  bulkValues.value = {}
  submitError.value = ''
  catalogError.value = ''
}

/** 清空只作用于当前编辑列表，不修改系统库存或已保存草稿。 */
function clearCountRows() {
  if (props.pageKey !== 'count' || countLoading.value || submitting.value || !selectedRows.value.length) return
  replaceCountRows([])
  countProgress.value = '当前盘点明细已清空，系统库存尚未修改。'
}

/** 按已授权仓当前批次重新生成盘点列表；清零只预填实盘数，读取失败保留原明细且不写库存。 */
async function loadCurrentInventoryCountRows(zeroCounted = false) {
  if (props.pageKey !== 'count' || countLoading.value || submitting.value) return
  const previousRows = zeroCounted ? selectedRows.value : null
  countLoading.value = true; catalogError.value = ''; countProgress.value = '正在读取现有库存批次…'
  // 一键清零先清空屏幕上的旧行；读取失败再还原，不能让网络错误丢失未保存的填写。
  if (zeroCounted) replaceCountRows([])
  try {
    // 普通导入与清零都按当前仓正数批次读取，不能漏掉已删除但仍有库存的历史商品。
    const catalog = await allCountCatalog(true, true)
    const additions = []
    for (const sku of catalog) {
      // 失效目录仅能按真实批次盘亏；保留身份不意味着让员工补录有效期资料。
      if (Number(sku.no_expiry_management) === 1 && !sku.count_requires_batch_identity) {
        const row = countDraftRow(sku)
        if (zeroCounted) row.counted_quantity = '0'
        additions.push(row)
        // 免录商品虽然不展开批次，也受同一明细上限保护，不能绕过大单限制。
        if (additions.length > 10000) throw new Error('本次库存批次超过 10000 行，请分批盘点；未导入任何行。')
        continue
      }
      const batches = Array.isArray(sku.count_batches) ? sku.count_batches : []
      for (const batch of batches) {
        const row = countBatchRow(sku, batch)
        if (zeroCounted) row.counted_quantity = '0'
        additions.push(row)
        if (additions.length > 10000) throw new Error('本次库存批次超过 10000 行，请分批盘点；未导入任何行。')
      }
    }
    replaceCountRows(additions)
    countProgress.value = zeroCounted
      ? `已加载 ${additions.length} 个库存批次，实盘库存均为 0；原列表已替换，完成盘点前系统库存不变。`
      : `已重新导入 ${additions.length} 个库存批次；原列表已替换，系统库存尚未修改。`
  } catch (error) {
    if (zeroCounted) {
      replaceCountRows(previousRows)
      countProgress.value = '库存读取失败，原盘点明细已恢复，系统库存未修改。'
    }
    catalogError.value = error instanceof Error ? error.message : '导入库存失败。'
  }
  finally { countLoading.value = false }
}

function importCurrentInventory() { return loadCurrentInventoryCountRows() }
function prepareZeroCountInventory() { return loadCurrentInventoryCountRows(true) }

async function loadAllCountProducts() {
  if (props.pageKey !== 'count' || countLoading.value || submitting.value) return
  // 两端共用仓库范围；完整读取后原子替换，失败时不能丢失已有实盘填写。
  countLoading.value = true; catalogError.value = ''; countProgress.value = '正在读取当前库存仓全部库存商品…'
  try {
    const catalog = await allCountCatalog(true)
    // 有库存按真实批次展开，无库存保留待填写行；不混入上一次盘点列表。
    const additions = catalog.flatMap((row) => Number(row.no_expiry_management) !== 1 && Array.isArray(row.count_batches) && row.count_batches.length
        ? row.count_batches.map((batch) => countBatchRow(row, batch)) : [countDraftRow(row)])
    replaceCountRows(additions)
    countProgress.value = `已加载 ${catalog.length} 个有效规格、${additions.length} 行；原列表已替换。`
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '加载全部商品失败。' }
  finally { countLoading.value = false }
}

async function importCountRows(event) {
  const file = event?.target?.files?.[0]
  if (event?.target) event.target.value = ''
  if (!file || props.pageKey !== 'count' || countLoading.value) return
  countLoading.value = true; catalogError.value = ''; countProgress.value = '正在核对盘点文件…'
  try {
    if (!/\.xlsx$/i.test(String(file.name))) throw new Error('请导入 Excel（.xlsx）文件，不要求使用本页导出的模板。')
    const imported = await parseCountXlsx(await file.arrayBuffer())
    const includeBatches = imported.some((cells) => cells[12] === 'BATCH')
    const catalog = await allCountCatalog(includeBatches)
    if (includeBatches && !isPlatformHeadquarters.value) {
      // 既有批次可能属于已删除商品；仅补当前仓正数库存身份，保留有效目录中的零库存商品。
      const known = new Set(catalog.map((item) => Number(item.sku_id)))
      for (const item of await allCountCatalog(true, true)) {
        if (!known.has(Number(item.sku_id))) catalog.push(item)
      }
    }
    // 一商品一规格按 ID 建索引，避免大文件逐行扫描整个目录；身份始终取系统记录。
    const catalogByProduct = new Map()
    for (const item of catalog) if (!catalogByProduct.has(Number(item.product_id))) catalogByProduct.set(Number(item.product_id), item)
    const rowIdentity = (skuId, mode, batchNo) => `${skuId}:${mode}:${mode === 'BATCH' ? batchNo : ''}`
    const existing = new Map(selectedRows.value.map((row) => [rowIdentity(row.sku_id, row.count_mode || 'SKU', row.surplus_batch_no), row]))
    const matched = [], skipped = [], seen = new Set()
    for (let index = 0; index < imported.length; index++) {
      const cells = imported[index]
      const rowNumber = cells.sourceRow || index + 2
      // 未匹配的 ID 只跳过并按文件名称提示；不能按名称猜测替代商品或扩大门店目录权限。
      if (!catalogByProduct.has(Number(cells[0]))) {
        skipped.push(`${cells[1] || `商品 ID ${cells[0]}`}无法导入，请手动录入`)
        continue
      }
      const sku = matchCountProduct(cells, catalogByProduct, rowNumber)
      const mode = cells[12] === 'BATCH' ? 'BATCH' : 'SKU'
      const identity = rowIdentity(sku?.sku_id, mode, cells[8])
      if (seen.has(identity)) throw new Error(`第 ${rowNumber} 行商品 ID ${cells[0]} 的盘点记录重复，请保留一行；不同批次请使用批次盘点粒度。`)
      if (mode === 'BATCH' && !cells[8]) throw new Error(`第 ${rowNumber} 行为批次盘点，请填写批号。`)
      seen.add(identity)
      // 文件账面、盈亏仅为导出时参考，不作为导入门禁；只接收有效实盘数。
      if (!/^\d+(?:\.\d{1,4})?$/.test(cells[6])) throw new Error(`第 ${rowNumber} 行实盘库存数量无效。`)
      if (cells[9] && !/^\d+(?:\.\d{1,2})?$/.test(cells[9])) throw new Error(`第 ${rowNumber} 行盘盈单价无效。`)
      if ([cells[10], cells[11]].some((value) => value && !/^\d{4}-\d{2}-\d{2}$/.test(value))) throw new Error(`第 ${rowNumber} 行日期格式无效。`)
      const batch = (sku.count_batches || []).find((item) => String(item.batch_no) === String(cells[8]))
      const row = { ...(existing.get(identity) || countDraftRow(sku)), no_expiry_management: Number(sku.no_expiry_management || 0), count_mode: mode,
        book_quantity: String(mode === 'BATCH' ? (batch?.quantity ?? 0) : (sku.available_quantity ?? 0)), counted_quantity: cells[6], surplus_batch_no: cells[8],
        surplus_unit_cost: cells[9], surplus_manufactured_date: cells[10], surplus_expire_date: cells[11] }
      matched.push(row)
    }
    // 匹配成功的行全部校验后才一次性更新；数量、日期和重复错误仍阻断，缺失商品不阻断其他行。
    // 不删除当前表格里未出现在有效导入记录中的行，也不写入系统库存。
    const importedIds = new Set(matched.map((row) => rowIdentity(row.sku_id, row.count_mode, row.surplus_batch_no)))
    selectedRows.value = [...selectedRows.value.filter((row) => !importedIds.has(rowIdentity(row.sku_id, row.count_mode || 'SKU', row.surplus_batch_no))), ...matched]
    countProgress.value = `已导入 ${matched.length} 行到盘点单${skipped.length ? `，已跳过 ${skipped.length} 行` : ''}；完成盘点前系统库存不变。`
    catalogError.value = skipped.join('；')
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '盘点资料导入失败。' }
  finally { countLoading.value = false }
}

async function loadSupplyParties() {
  if (props.pageKey !== 'request') return
  if (isPlatformHeadquarters.value && Number(commandHqLocationId.value) <= 0) {
    supplyParties.value = []
    return
  }
  supplyPartiesLoading.value = true
  try {
    const response = isPlatformHeadquarters.value
      ? await platformInventoryApi.hqRequestCounterparties({ hq_location_id: Number(commandHqLocationId.value), request_party_type: String(requestPartySelection.value).split(':')[0], request_party_id: requestPartySelection.value.startsWith('HQ:') ? 0 : Number(String(requestPartySelection.value).split(':')[1] || 0) })
      : await inventoryApi.requestCounterparties()
    const stores = (Array.isArray(response?.list) ? response.list : []).map((item) => {
      const type = String(item.party_type || item.type || 'STORE').toUpperCase()
      const id = Number(item.party_id ?? item.id ?? 0)
      return { key: warehouseSelection(type, id, item.location_id), type, id, locationId: Number(item.location_id), name: String(item.name || '') }
    }).filter((item) => item.name && (item.type === 'HQ' || item.id > 0))
    supplyParties.value = stores
    if (!supplyPartySelection.value || !supplyParties.value.some((item) => item.key === supplyPartySelection.value)) supplyPartySelection.value = supplyParties.value[0]?.key || ''
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '供货方目录读取失败。' } finally { supplyPartiesLoading.value = false }
}

async function loadRequester() {
  if (props.pageKey !== 'request' || isRequestEdit.value) return
  try {
    const response = isPlatformHeadquarters.value
      ? await platformInventoryApi.hqRequestRequester({ hq_location_id: Number(commandHqLocationId.value), request_party_type: String(requestPartySelection.value).split(':')[0], request_party_id: requestPartySelection.value.startsWith('HQ:') ? 0 : Number(String(requestPartySelection.value).split(':')[1] || 0) })
      : await inventoryApi.requestRequester()
    requesterOptions.value = Array.isArray(response?.list) ? response.list : []
    requesterSelection.value = String(response?.default_key || requesterOptions.value[0]?.key || '')
    requesterName.value = String(response?.requester_name || requesterOptions.value[0]?.name || '')
  } catch (error) {
    catalogError.value = error instanceof Error ? error.message : '请货人默认信息读取失败。'
  }
}

watch(() => [props.pageKey, props.mode, props.hqLocationId], () => { if (isPlatformHeadquarters.value) loadRequestParties(); else { loadSupplyParties(); loadRequester() } }, { immediate: true })

async function loadRequestParties() {
  if (props.pageKey !== 'request' || !isPlatformHeadquarters.value || Number(commandHqLocationId.value) <= 0) return
  try {
    const response = await platformInventoryApi.hqRequestParties({ hq_location_id: Number(commandHqLocationId.value) })
    requestPartyOptions.value = Array.isArray(response?.list) ? response.list : []
    requestPartySelection.value = String(response?.default || requestPartyOptions.value[0]?.key || 'HQ:0')
    await onRequestPartyChanged(requestPartySelection.value)
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '请货方目录读取失败。' }
}

async function onRequestPartyChanged(value) {
  requestPartySelection.value = String(value || 'HQ:0')
  supplyPartySelection.value = ''
  requesterSelection.value = ''
  requesterOptions.value = []
  selectedRows.value = []
  await loadSupplyParties()
  await loadRequester()
}

function onSupplyPartyChanged(value) {
  supplyPartySelection.value = String(value || '')
  // 已选商品的库存属于原供货方；切换后要求从新供货方库存重新选择。
  selectedRows.value = []
}

async function loadTransferContext() {
  // Consume the shortcut once: a later manual source change must not restore the old request.
  const initialRequestId = transferRequestSeedPending ? Number(props.detail?.transfer_request_id || 0) : 0
  transferRequestSeedPending = false
  if (initialRequestId > 0) requestDocumentId.value = initialRequestId
  try {
    const [sourceType, rawSourceId] = String(transferSourceSelection.value || 'HQ:0').split(':', 2)
    const sourceQuery = { hq_location_id: Number(commandHqLocationId.value), source_party_type: sourceType, source_store_id: sourceType === 'HQ' ? 0 : Number(rawSourceId || 0) }
    const parties = isPlatformHeadquarters.value
      ? await platformInventoryApi.hqCrossTransferCounterparties(sourceQuery)
      : await inventoryApi.crossTransferCounterparties()
    transferCounterparties.value = Array.isArray(parties?.list) ? parties.list : []
    // 关联请货单只是辅助入口，读取失败不能阻塞手工选择调入方。
    try {
      const requests = isPlatformHeadquarters.value
        ? await platformInventoryApi.hqCrossTransferIncomingRequests(sourceQuery)
        : await inventoryApi.crossTransferIncomingRequests()
      incomingRequests.value = Array.isArray(requests?.list) ? requests.list : []
      // Seed once from the authoritative incoming-request endpoint. An obsolete or inaccessible
      // request must not silently become an unrelated manual transfer.
      if (initialRequestId > 0) {
        if (!incomingRequests.value.some((item) => Number(item.id) === initialRequestId)) {
          catalogError.value = '该请货单已无可调拨商品或不在当前供货权限内，请返回请货列表刷新。'
        } else applyIncomingRequest()
      }
    } catch (error) {
      incomingRequests.value = []
      catalogError.value = error instanceof Error ? `关联请货单读取失败：${error.message}` : '关联请货单读取失败。'
    }
    if (isPlatformHeadquarters.value && !transferSourceSelection.value) transferSourceSelection.value = 'HQ:0'
    await loadTransferStaffs()
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '跨门店调拨对象读取失败。' }
}

async function loadTransferStaffs() {
  const previousStaffId = transferStaffId.value
  transferStaffOptions.value = []
  transferStaffId.value = 0
  if (!isPlatformHeadquarters.value || props.pageKey !== 'transfer') return
  const target = selectedTransferTarget()
  if (!['STORE', 'HQ'].includes(target.type) || target.id < 0) return
  try {
    const [sourcePartyType, rawSourceId] = String(transferSourceSelection.value || 'HQ:0').split(':', 2)
    const response = await platformInventoryApi.hqCrossTransferStaffs({
      hq_location_id: Number(commandHqLocationId.value), source_party_type: sourcePartyType, source_store_id: sourcePartyType === 'HQ' ? 0 : Number(rawSourceId || 0), target_store_id: target.type === 'HQ' ? 0 : target.id, target_location_id: target.type === 'HQ' ? target.locationId : 0
    })
    transferStaffOptions.value = Array.isArray(response?.list) ? response.list : []
    // 编辑优先保留仍有效的记名人员；失效人员必须重新选择。
    transferStaffId.value = transferStaffOptions.value.some((staff) => Number(staff.id) === previousStaffId)
      ? previousStaffId : Number(response?.current_staff_id || 0)
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '调拨人员读取失败。' }
}

function onTransferSourceChanged(value) {
  transferSourceSelection.value = String(value || 'HQ:0')
  requestDocumentId.value = 0
  transferTargetSelection.value = ''
  transferStaffOptions.value = []
  transferStaffId.value = 0
  selectedRows.value = []
  if (props.pageKey === 'transfer') loadTransferContext()
}

function onTransferTargetChanged(value) {
  transferTargetSelection.value = String(value || '')
  loadTransferStaffs()
}

function selectedSupplyParty() {
  const [type, rawId] = String(supplyPartySelection.value || '').split(':', 2)
  return { type, id: type === 'HQ' ? 0 : Number(rawId || 0), locationId: type === 'HQ' ? Number(rawId || 0) : Number(supplyParties.value.find((item) => item.key === supplyPartySelection.value)?.locationId || 0) }
}

function applyIncomingRequest() {
  const request = incomingRequests.value.find((item) => Number(item.id) === Number(requestDocumentId.value))
  if (!request) return
  transferTargetSelection.value = warehouseSelection(String(request.request_party_type || 'STORE').toUpperCase(), request.request_party_id || request.store_id, request.location_id)
  const lines = Array.isArray(request.lines) ? request.lines : []
  selectedRows.value = lines.map((line) => {
    const scale = Number(line.quantity_scale || 0)
    const remainingUnits = Number(line.remaining_quantity_units ?? line.remaining_quantity ?? line.quantity ?? 0)
    const quantity = scale > 0 ? (remainingUnits / (10 ** scale)).toFixed(scale).replace(/0+$/, '').replace(/\.$/, '') : String(remainingUnits)
    return {
      product_id: Number(line.product_id || line.pid), sku_id: Number(line.sku_id || line.sku), sku_unique: String(line.sku_unique || ''),
      product_name: String(line.product_name_snapshot || line.product_name || ''), sku_name: String(line.sku_name_snapshot || line.sku_name || ''),
      stock_unit: String(line.stock_unit_snapshot || line.stock_unit || ''), barcode: String(line.barcode_snapshot || line.barcode || ''), quantity: quantity || '0', detail_remark: String(line.detail_remark || ''), request_line_id: Number(line.id || line.request_line_id || 0),
      remaining_quantity_units: remainingUnits
    }
  }).filter((line) => line.request_line_id > 0 && Number(line.quantity) > 0)
  loadTransferStaffs()
}

function selectedTransferTarget() {
  const [type, rawId] = String(transferTargetSelection.value || '').split(':', 2)
  return { type, id: type === 'HQ' ? 0 : Number(rawId || 0), locationId: type === 'HQ' ? Number(rawId || 0) : Number(supplyParties.value.find((item) => item.key === supplyPartySelection.value)?.locationId || 0) }
}

function countIdempotencyKey() { return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? `count-${crypto.randomUUID()}` : `count-${Date.now()}-${Math.random().toString(36).slice(2)}` }

/** Persist all selected rows as an editable snapshot; this command never touches stock or facts. */
function countDraftContent() {
  return {
    business_date: inboundDate.value, remark: countRemark.value,
    lines: selectedRows.value.map((row) => ({
      product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: String(row.sku_unique || ''),
      product_name: String(row.product_name || ''), sku_name: String(row.sku_name || ''), stock_unit: String(row.stock_unit || ''), barcode: String(row.barcode || ''),
      book_quantity: String(row.book_quantity ?? '0'), counted_quantity: String(row.counted_quantity ?? ''),
      surplus_batch_no: String(row.surplus_batch_no || ''), surplus_unit_cost: String(row.surplus_unit_cost || ''),
      surplus_manufactured_date: String(row.surplus_manufactured_date || ''), surplus_expire_date: String(row.surplus_expire_date || ''),
      ...(row.count_mode === 'BATCH' ? { count_mode: 'BATCH' } : {})
    }))
  }
}

async function persistCountDraft() {
  const content = countDraftContent()
  // 平台总部不能调用门店写接口；保存仍只产生可续编快照，不产生库存变更。
  const payload = { draft_id: countDraftId.value, expected_version: countDraftVersion.value, ...content }
  const result = isPlatformHeadquarters.value
    ? await platformInventoryApi.saveHqCountDraft({ ...payload, hq_location_id: Number(commandHqLocationId.value) })
    : await inventoryApi.saveCountDraft(payload)
  countDraftId.value = Number(result.draft_id)
  countDraftVersion.value = Number(result.version)
  countDraftSavedSnapshot.value = JSON.stringify(content)
  return result
}

async function saveCountDraft() {
  if (props.pageKey !== 'count' || !selectedRows.value.length || countLoading.value || submitting.value) return
  submitting.value = true
  submitError.value = ''
  try {
    await persistCountDraft()
    // 保存只刷新原列表；不自动切换筛选，也不触发加载全部商品。
    emit('saved')
    close()
  } catch (error) { submitError.value = error instanceof Error ? error.message : '盘点草稿保存失败。' }
  finally { submitting.value = false }
}

async function submitCount() {
  if (props.pageKey !== 'count' || countLoading.value || submitting.value) return
  // 等于旧账面也必须提交：期间库存可能变化，是否调整由服务端锁定后决定。
  const physicalRows = countedRows(selectedRows.value)
  if (!physicalRows.length) {
    submitError.value = '请至少填写一项实盘库存。'
    catalogError.value = submitError.value
    return
  }
  // 预计盘盈行预检批次资料，持平与盘亏不强迫补造批次；真实差异仍由服务端结算。
  const validation = validateCountSubmissionRows(selectedRows.value)
  if (!validation.valid) {
    // 明细可能有数百行；错误必须留在操作区并把首条问题行带回视野，避免用户误以为按钮无效。
    countValidationRowIndexes.value = [validation.rowIndex]
    countValidationKind.value = 'local'
    submitError.value = validation.message
    catalogError.value = validation.message
    await nextTick()
    lineTableBody.value?.children?.[validation.rowIndex]?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    return
  }
  submitting.value = true
  submitError.value = ''
  catalogError.value = ''
  countValidationRowIndexes.value = []
  countValidationKind.value = ''
  try {
    // A resumed draft is saved before confirmation so failed stock validation does not lose edits.
    if (countDraftId.value > 0 && JSON.stringify(countDraftContent()) !== countDraftSavedSnapshot.value) await persistCountDraft()
    const payload = {
      idempotency_key: countDraftId.value > 0 ? `count-draft-${countDraftId.value}` : countIdempotencyKey(),
      draft_id: countDraftId.value, draft_version: countDraftVersion.value,
      business_date: inboundDate.value, remark: countRemark.value,
      lines: physicalRows.map((row) => ({
        product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique,
        counted_quantity: row.counted_quantity, surplus_batch_no: row.surplus_batch_no,
        surplus_unit_cost: row.surplus_unit_cost, surplus_manufactured_date: row.surplus_manufactured_date,
        surplus_expire_date: row.surplus_expire_date, expected_book_quantity: String(row.book_quantity),
        ...(row.count_mode === 'BATCH' ? { count_mode: 'BATCH' } : {})
      }))
    }
    if (isPlatformHeadquarters.value) await platformInventoryApi.confirmHqCount({ ...payload, hq_location_id: Number(commandHqLocationId.value) })
    else await inventoryApi.confirmCount(payload)
    emit('saved')
    close()
  } catch (error) {
    const invalidLines = Array.isArray(error?.data?.invalid_lines) ? error.data.invalid_lines : []
    if (error?.code === 'inventory_stock_count_catalog_not_found' && invalidLines.length) {
      // 服务端返回稳定身份，名称仍取草稿快照；目录已删除也不能让错误提示丢失业务上下文。
      countValidationRowIndexes.value = invalidCountCatalogRowIndexes(selectedRows.value, invalidLines)
      countValidationKind.value = 'catalog'
      submitError.value = invalidCountCatalogMessage(selectedRows.value, countValidationRowIndexes.value)
      await nextTick()
      lineTableBody.value?.children?.[countValidationRowIndexes.value[0]]?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    } else if (error?.code === 'inventory_stock_count_surplus_batch_required') {
      // A stale draft may become a gain only after the server locks current stock; its submitted row index is authoritative.
      const rowIndex = submittedCountRowIndex(selectedRows.value, error?.data?.line_index)
      if (rowIndex >= 0) {
        countValidationRowIndexes.value = [rowIndex]
        countValidationKind.value = 'surplus'
        submitError.value = missingCountSurplusMessage(selectedRows.value, rowIndex)
        await nextTick()
        lineTableBody.value?.children?.[rowIndex]?.scrollIntoView({ block: 'center', behavior: 'smooth' })
      } else submitError.value = error instanceof Error ? error.message : '盘点提交失败。'
    } else {
      submitError.value = error instanceof Error ? error.message : '盘点提交失败。'
    }
    // 服务端的并发、批次冲突等错误同样显示在当前操作区，不依赖用户滚回弹窗顶部。
    catalogError.value = submitError.value
  }
  finally { submitting.value = false }
}

function removeCatalogRow(rowIndex) {
  selectedRows.value.splice(rowIndex, 1)
  selectedLineIndexes.value = selectedLineIndexes.value.filter((index) => index !== rowIndex).map((index) => index > rowIndex ? index - 1 : index)
  if (props.pageKey !== 'count' || !countValidationRowIndexes.value.length) return

  // 问题行删除后不能把位置索引遗留给下一件商品；仍有问题行时同步其新行号和提示。
  countValidationRowIndexes.value = reindexCountValidationRowsAfterRemoval(countValidationRowIndexes.value, rowIndex)
  // Only catalog errors retain multiple identities after deletion; local errors are recalculated on the next submit.
  const message = countValidationRowIndexes.value.length && countValidationKind.value === 'catalog'
    ? invalidCountCatalogMessage(selectedRows.value, countValidationRowIndexes.value)
    : countValidationRowIndexes.value.length && countValidationKind.value === 'surplus'
      ? missingCountSurplusMessage(selectedRows.value, countValidationRowIndexes.value[0])
      : ''
  if (!message) { countValidationRowIndexes.value = []; countValidationKind.value = '' }
  submitError.value = message
  catalogError.value = message
}

function inboundIdempotencyKey() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return `inbound-${crypto.randomUUID()}`
  return `inbound-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

function outboundIdempotencyKey() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return `outbound-${crypto.randomUUID()}`
  return `outbound-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

function updateInboundDate(rowIndex, field, event) {
  const row = selectedRows.value[rowIndex]
  const target = event?.currentTarget
  if (!row || !['manufactured_date', 'expire_date'].includes(field)) return
  const value = String(target?.value || '')
  row[field] = value
  // 无效分段日期在浏览器中可能仍可见，但控件 value 为空；必须把该状态带到保存前校验。
  row[`${field}_bad_input`] = !value && Boolean(target?.validity && !target.validity.valid)
}

async function submitInbound() {
  if (props.pageKey !== 'inbound' || !selectedRows.value.length) return
  const dateValidation = validateInboundDates(selectedRows.value)
  if (!dateValidation.valid) {
    submitError.value = dateValidation.message
    return
  }
  submitting.value = true
  submitError.value = ''
  try {
    const payload = {
      idempotency_key: '', business_date: inboundDate.value, remark: inboundRemark.value,
      lines: selectedRows.value.map((row) => ({
        product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique,
        batch_no: Number(row.no_expiry_management) === 1 ? '' : row.batch_no, quantity: row.quantity, unit_cost: row.unit_cost,
        manufactured_date: Number(row.no_expiry_management) === 1 ? '' : row.manufactured_date,
        expire_date: Number(row.no_expiry_management) === 1 ? '' : row.expire_date, detail_remark: String(row.detail_remark || '')
      }))
    }
    // 传输结果不明时同仓同内容复用键；换仓或改明细必须产生新命令。
    const fingerprint = JSON.stringify([Number(commandHqLocationId.value), payload])
    if (inboundAttempt.value?.fingerprint !== fingerprint) inboundAttempt.value = { fingerprint, key: inboundIdempotencyKey() }
    payload.idempotency_key = inboundAttempt.value.key
    if (isPlatformHeadquarters.value) {
      if (Number(commandHqLocationId.value) <= 0) throw new Error('请选择可操作的总部仓。')
      await platformInventoryApi.createHqInbound({ ...payload, hq_location_id: Number(commandHqLocationId.value) })
    } else await inventoryApi.createInbound(payload)
    emit('saved', { businessDate: inboundDate.value, warehouseSelection: isPlatformHeadquarters.value ? documentWarehouseSelection.value : '' })
    close()
  } catch (error) {
    submitError.value = inventoryErrorMessage(error, '入库提交失败。')
  } finally {
    submitting.value = false
  }
}

async function submitOutbound() {
  if (props.pageKey !== 'outbound' || !selectedRows.value.length || submitting.value) return
  submitting.value = true
  submitError.value = ''
  try {
    const payload = {
      idempotency_key: '', business_date: outboundDate.value, remark: outboundRemark.value,
      outbound_type: outboundType.value, outbound_recipient: outboundRecipient.value.trim(),
      lines: selectedRows.value.map((row) => ({
        product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity, detail_remark: String(row.detail_remark || '')
      }))
    }
    // A retry of unchanged content reuses the original key; edits start a new command.
    const fingerprint = JSON.stringify([Number(commandHqLocationId.value), payload])
    if (outboundAttempt.value?.fingerprint !== fingerprint) outboundAttempt.value = { fingerprint, key: outboundIdempotencyKey() }
    payload.idempotency_key = outboundAttempt.value.key
    if (isPlatformHeadquarters.value) {
      if (Number(commandHqLocationId.value) <= 0) throw new Error('请选择可操作的总部仓。')
      await platformInventoryApi.createHqOutbound({ ...payload, hq_location_id: Number(commandHqLocationId.value) })
    } else await inventoryApi.createOutbound(payload)
    emit('saved', { businessDate: outboundDate.value, warehouseSelection: isPlatformHeadquarters.value ? documentWarehouseSelection.value : '' })
    close()
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : '出库提交失败。'
  } finally {
    submitting.value = false
  }
}

function requestIdempotencyKey() {
  return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? `request-${crypto.randomUUID()}` : `request-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

async function submitRequest() {
  const supplier = selectedSupplyParty()
  if (props.pageKey !== 'request' || !selectedRows.value.length || !['HQ', 'STORE'].includes(supplier.type) || (supplier.type === 'STORE' && supplier.id <= 0)) return
  // 请货单只记录需求，不预占也不变动库存；供货方零库存不阻止保存，真正发货时再由服务端校验可用批次。
  submitting.value = true
  submitError.value = ''
  try {
    const requestParty = (() => { const [type, rawId] = String(requestPartySelection.value || 'HQ:0').split(':', 2); return { type, id: type === 'HQ' ? 0 : Number(rawId || 0), locationId: type === 'HQ' ? Number(rawId || 0) : Number(supplyParties.value.find((item) => item.key === supplyPartySelection.value)?.locationId || 0) } })()
    const selectedRequester = requesterOptions.value.find((item) => String(item.key) === String(requesterSelection.value))
    const payload = {
      idempotency_key: isRequestEdit.value ? requestEditIdempotencyKey.value : requestIdempotencyKey(), business_date: requestDate.value, remark: requestRemark.value, requester_name: String(selectedRequester?.name || requesterName.value).trim(), ...(isPlatformHeadquarters.value ? { request_party_type: requestParty.type, request_party_id: requestParty.id } : {}), supply_party_type: supplier.type, supply_party_id: supplier.id, supply_location_id: supplier.locationId,
      lines: selectedRows.value.map((row) => ({ product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity, detail_remark: String(row.detail_remark || '') }))
    }
    if (isPlatformHeadquarters.value) {
      if (Number(commandHqLocationId.value) <= 0) throw new Error('请选择可操作的总部仓。')
      await platformInventoryApi.applyHqRequest({ ...payload, hq_location_id: Number(commandHqLocationId.value) })
    } else if (isRequestEdit.value) await inventoryApi.updateRequest(props.detail?.document?.id, payload)
    else await inventoryApi.applyRequest(payload)
    // 把实际保存的业务日期回传给列表，保证保存后用户立即看到该单据。
    // 返回请货日期给列表恢复筛选；操作日期由服务端记录且只读展示。
    emit('saved', { businessDate: requestDate.value })
    close()
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : '请货申请失败。'
  } finally {
    submitting.value = false
  }
}

function transferIdempotencyKey() { return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? `transfer-${crypto.randomUUID()}` : `transfer-${Date.now()}-${Math.random().toString(36).slice(2)}` }
async function submitTransfer() {
  const target = selectedTransferTarget()
  if (props.pageKey !== 'transfer' || !selectedRows.value.length || !['STORE', 'HQ'].includes(target.type) || (target.type === 'STORE' && target.id <= 0)) return
  submitting.value = true; submitError.value = ''
  try {
    // A failed shortcut lookup must never submit an unrelated manual transfer.
    if (!isTransferEdit.value && Number(requestDocumentId.value) > 0 && !incomingRequests.value.some((item) => Number(item.id) === Number(requestDocumentId.value))) {
      throw new Error('关联请货单已无可调拨商品或不在当前供货权限内，请返回列表刷新。')
    }
    const commonPayload = { idempotency_key: transferIdempotencyKey(), business_date: transferDate.value, remark: transferRemark.value, target_party_type: target.type, target_store_id: target.id, target_location_id: target.type === 'HQ' ? target.locationId : 0, request_document_id: Number(requestDocumentId.value), lines: selectedRows.value.map((row) => ({ product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity, request_line_id: Number(row.request_line_id || 0), detail_remark: String(row.detail_remark || '') })) }
    if (isPlatformHeadquarters.value) {
      if (Number(commandHqLocationId.value) <= 0) throw new Error('请选择可操作的总部仓。')
      const [type, rawId] = String(transferSourceSelection.value || 'HQ:0').split(':', 2)
      const payload = { ...commonPayload, source_party_type: type, source_store_id: type === 'HQ' ? 0 : Number(rawId || 0), transfer_staff_id: Number(transferStaffId.value), hq_location_id: Number(commandHqLocationId.value) }
      if (isTransferEdit.value) await platformInventoryApi.updateHqCrossTransfer(Number(props.detail?.id), { ...payload, expected_edit_token: String(props.detail?.edit_token || '') })
      else await platformInventoryApi.createHqCrossTransfer(payload)
    } else if (isTransferEdit.value) {
      await inventoryApi.updateCrossTransfer(Number(props.detail?.id), { ...commonPayload, expected_edit_token: String(props.detail?.edit_token || '') })
    } else await inventoryApi.createCrossTransfer(commonPayload)
    emit('saved', { businessDate: transferDate.value }); close()
  } catch (error) { submitError.value = error instanceof Error ? error.message : '调拨提交失败。' } finally { submitting.value = false }
}

function usageIdempotencyKey() { return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? `usage-${crypto.randomUUID()}` : `usage-${Date.now()}-${Math.random().toString(36).slice(2)}` }
async function submitUsage() {
  if (props.pageKey !== 'usage' || !selectedRows.value.length) return
  submitting.value = true; submitError.value = ''
  try {
    const api = isPlatformHeadquarters.value ? platformInventoryApi : inventoryApi
    const scope = isPlatformHeadquarters.value ? usageWarehouseScope.value : {}
    await api.issueSalonUsage({ idempotency_key: usageIdempotencyKey(), business_date: usageDate.value, project_id: Number(usageProjectId.value), project_name: usageProjectName.value.trim(), remark: usageRemark.value, ...scope, lines: selectedRows.value.map((row) => ({ product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity, detail_remark: String(row.detail_remark || '') })) })
    emit('saved', { businessDate: usageDate.value, warehouseSelection: isPlatformHeadquarters.value ? usageWarehouseSelection.value : '' }); close()
  } catch (error) { submitError.value = error instanceof Error ? error.message : '院装领用提交失败。' } finally { submitting.value = false }
}

/** 同一建仓内容在网络失败后复用幂等键；改名或切换组织才开始新命令。 */
function warehouseIdempotencyKey() {
  const content = JSON.stringify([Number(warehouseStoreId.value), warehouseName.value.trim()])
  if (warehouseCreateAttempt.value.content === content) return warehouseCreateAttempt.value.key
  const key = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? `warehouse-${crypto.randomUUID()}`
    : `warehouse-${Date.now()}-${Math.random().toString(36).slice(2)}`
  warehouseCreateAttempt.value = { content, key }
  return key
}

async function submitWarehouse() {
  if (props.pageKey !== 'warehouse' || props.mode !== 'platform' || Number(warehouseStoreId.value) <= 0 || !warehouseName.value.trim()) return
  submitting.value = true
  submitError.value = ''
  try {
    if (props.modalKind === 'warehouse-edit') await platformInventoryApi.updateWarehouse(Number(props.detail.id), { location_name: warehouseName.value.trim(), location_status: warehouseStatus.value, expected_version: Number(props.detail.version) })
    else await platformInventoryApi.createWarehouse({
      idempotency_key: warehouseIdempotencyKey(),
      base_location_id: Number(warehouseStoreId.value),
      location_name: warehouseName.value.trim()
    })
    emit('saved')
    close()
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : '仓库创建失败。'
  } finally {
    submitting.value = false
  }
}

/** Dispatch the shared editor action without duplicating business-specific submit conditions in the template. */
function submitPrimaryEditor() {
  if (props.pageKey === 'inbound') return submitInbound()
  if (props.pageKey === 'outbound') return submitOutbound()
  if (props.pageKey === 'request') return submitRequest()
  if (props.pageKey === 'transfer') return submitTransfer()
  if (props.pageKey === 'usage') return submitUsage()
  if (props.pageKey === 'warehouse') return submitWarehouse()
}
</script>

<template>
  <section class="inventory-editor" :aria-label="title">
        <header class="inventory-editor__header">
          <div><p>{{ mode === 'platform' ? '平台端' : '门店端' }} · {{ modalScopeName }}</p><h2>{{ title }}</h2><p v-if="pageKey === 'count' && submitError" class="inventory-editor__submit-error" role="alert">{{ submitError }}</p></div>
          <div class="inventory-editor__header-actions">
            <button class="modal-icon-button" title="返回列表" @click="close"><X :size="20" /></button>
          </div>
        </header>

        <!-- 盘点已有独立的大数据表滚动方案；该标记用于让其他库存业务共享滚动规则而不改变盘点。 -->
        <main class="inventory-modal__body" :class="{ 'inventory-modal__body--count': pageKey === 'count' }">
          <template v-if="isImport">
            <section class="import-workflow">
              <div class="import-modal"><FileSpreadsheet /><div><strong>{{ importDirection === 'inbound' ? '入库 Excel 导入' : '出库 Excel 导入' }}</strong><p>{{ mode === 'platform' ? '先选择库存仓生成模板；上传时按模板中的库存仓标识分别落单。' : '门店端仅能导入当前门店。' }}</p></div><button class="modal-secondary" :disabled="importBusy" @click="downloadImportTemplate">下载模板</button></div>
              <div v-if="mode === 'platform'" class="import-store-picker"><strong>导入库存仓</strong><button type="button" class="modal-secondary" @click="platformImportStorePickerVisible = true">{{ platformImportStoreIds.length ? `已选 ${platformImportStoreIds.length} 个库存仓` : '选择库存仓（组织树）' }}</button></div>
              <div v-if="platformImportStorePickerVisible" class="import-picker-mask" @click.self="platformImportStorePickerVisible = false"><div class="import-picker-dialog"><header><strong>选择库存仓</strong><button type="button" class="modal-icon-button" @click="platformImportStorePickerVisible = false"><X :size="18" /></button></header><div class="import-picker-tree"><div class="import-picker-group"><strong>总部</strong><label v-for="store in platformImportStores.filter((item) => item.import_key.startsWith('HQ:'))" :key="store.import_key"><input v-model="platformImportStoreIds" type="checkbox" :value="store.import_key" />{{ store.import_name }}</label></div><div class="import-picker-group"><strong>门店</strong><label v-for="store in platformImportStores.filter((item) => item.import_key.startsWith('STORE:'))" :key="store.import_key"><input v-model="platformImportStoreIds" type="checkbox" :value="store.import_key" />{{ store.import_name }}</label></div></div><footer><button type="button" class="modal-primary" @click="platformImportStorePickerVisible = false">确定</button></footer></div></div>
              <div class="import-rules"><strong>导入规则</strong><span>仅导入良品数量；残次品数量必须为 0。{{ importDirection === 'inbound' ? '入库还必须填写批次号、生产日期、到期日和入库单价。' : '出库会按实际批次先到期先出。' }}</span><span>同一文件只允许一种业务日期和一种{{ importDirection === 'inbound' ? '入库' : '出库' }}类型，成功后形成一张正式单据。</span></div>
              <label class="import-upload" @dragover.prevent @drop.prevent="dropImportFile"><input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="selectImportFile" /><FileSpreadsheet :size="22" /><strong>{{ importFileName || '选择或拖入 xlsx 文件' }}</strong><small>{{ importFileName ? '文件已选择，提交前会先整表校验。' : '请使用本页下载的模板。' }}</small></label>
              <div class="import-actions"><button class="modal-primary" :disabled="importBusy || !importFile" @click="submitImport">{{ importBusy ? '处理中...' : '上传并导入' }}</button><button class="modal-secondary" :disabled="importBusy" @click="close">返回列表</button></div>
              <p v-if="submitError" class="catalog-error">{{ submitError }}</p><p v-if="importMessage" class="import-success">{{ importMessage }}</p>
            </section>
          </template>

          <template v-else-if="isDetail">
            <template v-if="isInboundOutboundDetail && detail?.document">
              <div class="detail-meta"><span>入库单号：<b>{{ detail.document.order_sn || '历史入库记录' }}</b></span><span>库存仓：{{ detail.document.location_name }}</span><span>后续出库：{{ detail.document.outbound_count || 0 }} 条</span></div>
              <section class="line-section"><header><div><h3>实际出库记录</h3><p>这里只显示该入库批次后续已生效且未被作废的出库记录。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>出库日期</th><th>出库单号</th><th>出库类型</th><th>商品</th><th>规格</th><th>批次号</th><th>出库数量</th><th>单位</th><th>出库时间</th></tr></thead><tbody><tr v-if="!detail.lines?.length"><td colspan="9" class="modal-empty">该入库批次目前没有出库记录</td></tr><tr v-for="line in detail.lines || []" :key="line.movement_fact_id"><td>{{ line.business_date }}</td><td>{{ line.order_sn }}</td><td>{{ line.outbound_type_name }}</td><td>{{ line.product_name }}</td><td>{{ line.sku_name || '默认规格' }}</td><td>{{ formatBatchNumber(line.batch_no) }}</td><td>{{ line.quantity }}</td><td>{{ line.stock_unit }}</td><td>{{ line.operation_at ? new Date(Number(line.operation_at) * 1000).toLocaleString() : '-' }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'inbound' && detail?.document">
              <div class="detail-meta"><span>入库单号：<b>{{ detail.document.order_sn }}</b></span><span>入库日期：{{ detail.document.business_date }}</span><span>入库时间：{{ detail.document.operation_at || detail.document.recorded_at ? new Date(Number(detail.document.operation_at || detail.document.recorded_at) * 1000).toLocaleString() : '-' }}</span><span>入库主体：{{ detail.document.location_name }}</span></div>
              <div class="detail-meta"><span>主项备注：{{ detail.document.remark || '-' }}</span></div>
              <section class="line-section"><header><div><h3>入库商品</h3><p>明细由已结算入库事实生成，不可在详情中修改。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>批次号</th><th>生产日期</th><th>到期日</th><th>入库数量</th><th>库存单位</th><th>入库单价</th><th>明细备注</th><th>入库金额</th></tr></thead><tbody><tr v-if="!detail.lines?.length"><td colspan="10" class="modal-empty">该入库单没有已结算明细</td></tr><tr v-for="(line, index) in detail.lines" :key="index"><td>{{ line.product_name }}</td><td>{{ line.sku_name || '默认规格' }}</td><td>{{ formatBatchNumber(line.batch_no) }}</td><td>{{ line.manufactured_date }}</td><td>{{ line.expire_date }}</td><td>{{ line.quantity }}</td><td>{{ line.stock_unit }}</td><td>{{ line.unit_cost_cents === null ? '-' : `¥${(Number(line.unit_cost_cents) / 100).toFixed(2)}` }}</td><td>{{ line.detail_remark || '-' }}</td><td>{{ line.cost_amount_cents === null ? '-' : `¥${(Number(line.cost_amount_cents) / 100).toFixed(2)}` }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'outbound' && detail?.document">
              <div class="detail-meta"><span>出库类型：{{ detail.document.order_type_name || '手工出库' }}</span><span>出库对象：{{ detail.document.outbound_recipient || '-' }}</span></div>
              <div class="detail-meta"><span>出库单号：<b>{{ detail.document.order_sn }}</b></span><span>出库日期：{{ detail.document.business_date }}</span><span>出库时间：{{ detail.document.recorded_at ? new Date(Number(detail.document.recorded_at) * 1000).toLocaleString() : '-' }}</span><span>出库主体：{{ detail.document.location_name }}</span></div>
              <div class="detail-meta"><span>主项备注：{{ detail.document.remark || '-' }}</span></div>
              <section class="line-section"><header><div><h3>出库商品</h3><p>明细由已结算出库事实生成，不可在详情中修改。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>扣减批次</th><th>生产日期</th><th>到期日</th><th>出库数量</th><th>库存单位</th><th>成本单价</th><th>明细备注</th><th>成本金额</th></tr></thead><tbody><tr v-if="!detail.lines?.length"><td colspan="10" class="modal-empty">该出库单没有已结算明细</td></tr><tr v-for="(line, index) in detail.lines" :key="index"><td>{{ line.product_name }}</td><td>{{ line.sku_name || '默认规格' }}</td><td>{{ formatBatchNumber(line.batch_no) }}</td><td>{{ line.manufactured_date }}</td><td>{{ line.expire_date }}</td><td>{{ line.quantity }}</td><td>{{ line.stock_unit }}</td><td>{{ line.unit_cost_cents === null ? '-' : `¥${(Number(line.unit_cost_cents) / 100).toFixed(2)}` }}</td><td>{{ line.detail_remark || '-' }}</td><td>{{ line.cost_amount_cents === null ? '-' : `¥${(Number(line.cost_amount_cents) / 100).toFixed(2)}` }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'count' && detail?.document">
              <div class="detail-meta"><span>盘点单号：<b>{{ detail.document.order_sn || '-' }}</b></span><span>库存仓：{{ detail.document.location_name || scopeName }}</span><span>盘点日期：{{ detail.document.count_date || '-' }}</span><span>盘点状态：{{ detailStatus(detail.document) }}</span></div>
              <section class="line-section"><header><div><h3>盘点明细</h3><p>{{ detail.document.remark || '盘点数据已确认，明细仅供查看。' }}</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>条码</th><th>账面库存</th><th>实盘库存</th><th>库存盈亏</th><th>库存单位</th><th>盘盈批次号</th><th>盘盈单价</th><th>金额</th><th>生产日期</th><th>到期日</th></tr></thead><tbody><tr v-if="!detail.lines?.length"><td colspan="12" class="modal-empty">该盘点单没有商品明细</td></tr><tr v-for="line in detail.lines || []" :key="line.id"><td>{{ line.product_name }}</td><td>{{ line.sku_name || '默认规格' }}</td><td>{{ line.barcode || '-' }}</td><td>{{ line.book_quantity }}</td><td>{{ line.counted_quantity }}</td><td>{{ line.difference_quantity }}</td><td>{{ line.stock_unit || '-' }}</td><td>{{ formatBatchNumber(line.surplus_batch_no) }}</td><td>{{ line.surplus_unit_cost_cents === null || line.surplus_unit_cost_cents === undefined ? '-' : `¥${(Number(line.surplus_unit_cost_cents) / 100).toFixed(2)}` }}</td><td>{{ countAmount(line.change_amount_cents) }}</td><td>{{ line.surplus_manufactured_date || '-' }}</td><td>{{ line.surplus_expire_date || '-' }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="isCountDraftDetail && detail?.draft_id">
              <div class="detail-meta"><span>草稿编号：<b>草稿-{{ detail.draft_id }}</b></span><span>盘点日期：未完成盘点</span><span>业务日期：{{ detail.business_date || '-' }}</span><span>状态：草稿</span><span>备注：{{ detail.remark || '-' }}</span></div>
              <section class="line-section"><header><div><h3>草稿明细</h3><p>保存的数据仅供查看；点击编辑后才能继续填写或完成盘点。</p></div><button class="modal-primary" @click="emit('edit-count-draft')">编辑草稿</button></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>单位</th><th>条码</th><th>账面库存</th><th>实盘库存</th><th>库存盈亏</th><th>盘盈批次号</th><th>盘盈单价</th><th>生产日期</th><th>到期日</th></tr></thead><tbody><tr v-for="(line, index) in detail.lines || []" :key="`${line.sku_id}-${index}`"><td>{{ line.product_name || '-' }}</td><td>{{ line.sku_name || '默认规格' }}</td><td>{{ line.stock_unit || '-' }}</td><td>{{ line.barcode || '-' }}</td><td>{{ line.book_quantity }}</td><td>{{ line.counted_quantity === '' ? '未填写' : line.counted_quantity }}</td><td>{{ line.counted_quantity === '' ? '-' : Number(line.counted_quantity) - Number(line.book_quantity) }}</td><td>{{ formatBatchNumber(line.surplus_batch_no) }}</td><td>{{ line.surplus_unit_cost || '-' }}</td><td>{{ line.surplus_manufactured_date || '-' }}</td><td>{{ line.surplus_expire_date || '-' }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'usage' && detail?.document">
              <div class="detail-meta"><span>院装单号：<b>{{ detail.document.usage_no }}</b></span><span>领用仓库：{{ detail.document.location_name || '-' }}</span><span>关联项目：{{ detail.document.project_name_snapshot || '未关联项目' }}</span><span>业务日期：{{ detail.document.business_date }}</span><span>类型：{{ detail.document.operation_type === 'RETURN' ? '退回' : '领用' }}</span></div>
              <section class="line-section"><header><div><h3>耗材明细</h3><p>{{ detail.document.remark || '无备注' }}</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>领用/退回数量</th><th>已退回</th><th>可退回</th><th>库存单位</th><th>明细备注</th></tr></thead><tbody><tr v-if="!detail.lines?.length"><td colspan="7" class="modal-empty">该院装单没有耗材明细</td></tr><tr v-for="line in detail.lines" :key="line.line_id"><td>{{ line.product_name }}</td><td>{{ line.sku_name || '默认规格' }}</td><td>{{ line.quantity }}</td><td>{{ line.returned_quantity }}</td><td>{{ line.returnable_quantity }}</td><td>{{ line.stock_unit || '-' }}</td><td>{{ line.detail_remark || '-' }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'import' && detail?.record">
              <div class="detail-meta"><span>文件：<b>{{ detail.record.source_file_name }}</b></span><span>方向：{{ detail.record.direction === 'inbound' ? '入库导入' : '出库导入' }}</span><span>业务单号：{{ detail.record.document_no || '-' }}</span><span>状态：{{ detail.record.status === 'SUCCEEDED' ? '成功' : detail.record.status === 'FAILED' ? '失败' : '处理中' }}</span></div>
              <section class="line-section"><header><div><h3>导入结果</h3><p>成功 {{ detail.record.success_count || 0 }} 行，失败 {{ detail.record.failure_count || 0 }} 行。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>Excel 行号</th><th>错误原因</th></tr></thead><tbody><tr v-if="!detail.errors?.length"><td colspan="2" class="modal-empty">本次导入没有错误行</td></tr><tr v-for="error in detail.errors" :key="error.id"><td>{{ error.excel_row || '-' }}</td><td>{{ error.error_message }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'stock' && detail?.product">
              <div class="detail-meta"><span>商品：<b>{{ detail.product.product_name }}</b></span><span>单位：{{ detail.product.stock_unit }}</span><span>规格：{{ detail.product.sku_name || '默认规格' }}</span><span>总可用库存：<b>{{ detail.total_available_quantity }} {{ detail.product.stock_unit }}</b></span><span>库存对账：{{ detail.reconciliation?.status === 'MATCHED' ? '正常' : '异常' }}</span></div>
              <section class="line-section"><header><div><h3>当前批次库存</h3><p>总可用库存由服务端按当前有效批次余额汇总并完成对账。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>仓库</th><th>批号</th><th>当前剩余库存</th><th>正式入库日</th><th>生产日期</th><th>到期日</th><th>单位成本</th><th>库存金额</th></tr></thead><tbody><tr v-if="!detail.batches?.length"><td colspan="8" class="modal-empty">暂无批次库存</td></tr><tr v-for="batch in detail.batches" :key="batch.batch_id"><td>{{ batch.location_name }}</td><td>{{ formatBatchNumber(batch.batch_no) }}</td><td>{{ batch.available_quantity }} {{ batch.stock_unit }}</td><td>{{ batch.received_date || '-' }}</td><td>{{ batch.manufactured_date || '-' }}</td><td>{{ batch.expire_date || '-' }}</td><td>{{ batch.unit_cost === null ? '-' : `¥${batch.unit_cost}` }}</td><td>{{ batch.inventory_amount_cents === null ? '-' : `¥${(Number(batch.inventory_amount_cents) / 100).toFixed(2)}` }}</td></tr></tbody></table></div></section>
              <!-- 流水标签由后端按已授权业务快照解析；技术来源 ID 仅用于追溯，不作为中文明细展示。 -->
              <section class="line-section"><header><div><h3>出入库流水</h3><p>仅展示当前商品最近 200 条已结算批次事实；冲销流水保留原流水编号。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>业务日期</th><th>单据号</th><th>批号</th><th>生产日期</th><th>到期日</th><th>业务类型</th><th>方向</th><th>数量</th><th>单位</th><th>来源明细</th><th>冲销关系</th><th>单位成本</th><th>发生仓库</th><th>成本金额</th></tr></thead><tbody><tr v-if="!detail.movements?.length"><td colspan="14" class="modal-empty">暂无出入库流水</td></tr><tr v-for="movement in detail.movements" :key="movement.id"><td>{{ movement.business_date }}</td><td>{{ movement.order_sn }}</td><td>{{ formatBatchNumber(movement.batch_no) }}</td><td>{{ movement.manufactured_date || '-' }}</td><td>{{ movement.expire_date || '-' }}</td><td>{{ movement.source_type_name || '其他库存业务' }}</td><td>{{ Number(movement.direction) > 0 ? '入库' : '出库' }}</td><td>{{ formatQuantity(movement.quantity_units, movement.quantity_scale) }}</td><td>{{ movement.stock_unit || detail.product.stock_unit }}</td><td>{{ movement.source_detail_name || '历史业务明细' }}</td><td>{{ Number(movement.reversal_of || 0) > 0 ? `冲销流水 #${movement.reversal_of}` : '-' }}</td><td>{{ movement.unit_cost_cents === null ? '-' : `¥${(Number(movement.unit_cost_cents) / 100).toFixed(2)}` }}</td><td>{{ movement.location_name }}</td><td>{{ movement.cost_amount_cents === null ? '-' : `¥${(Number(movement.cost_amount_cents) / 100).toFixed(2)}` }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'request' && detail?.document">
              <div class="detail-meta"><span>请货单号：<b>{{ detail.document.request_no }}</b></span><span>请货方：{{ detail.document.request_store_name || scopeName }}</span><span>供货方：{{ detail.document.supply_party_name_snapshot || '-' }}</span><span>状态：{{ detailStatus(detail.document) }}</span><span>申请日期：{{ detail.document.business_date }}</span></div>
              <section class="line-section"><header><div><h3>请货商品</h3><p>{{ detail.document.remark || '无备注' }}</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>申请数量</th><th>库存单位</th><th>明细备注</th></tr></thead><tbody><tr v-if="!requestDetailLines.length"><td colspan="5" class="modal-empty">该请货单没有商品明细</td></tr><tr v-for="line in requestDetailLines" :key="line.id"><td>{{ line.product_name_snapshot }}</td><td>{{ line.sku_name_snapshot || '默认规格' }}</td><td>{{ line.quantity }}</td><td>{{ line.stock_unit_snapshot }}</td><td>{{ line.detail_remark || '-' }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'transfer' && detail?.transfer_no">
              <div class="detail-meta"><span>调拨单号：<b>{{ detail.transfer_no }}</b></span><span>调出方：{{ detail.from_party_name_snapshot }}</span><span>调入方：{{ detail.to_party_name_snapshot }}</span><span>状态：{{ detailStatus(detail) }}</span><span>调拨日期：{{ detail.business_date }}</span></div>
              <section class="line-section"><header><div><h3>调拨商品</h3><p>{{ detail.remark || '无备注' }}</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>调拨数量</th><th>库存单位</th><th>批次分配</th><th>状态</th><th>明细备注</th></tr></thead><tbody><tr v-if="!transferDetailLines.length"><td colspan="7" class="modal-empty">该调拨单没有商品明细</td></tr><template v-for="line in transferDetailLines" :key="line.id"><tr><td>{{ line.product_name_snapshot }}</td><td>{{ line.sku_name_snapshot || '默认规格' }}</td><td>{{ formatQuantity(line.requested_quantity_units, line.quantity_scale) }}</td><td>{{ line.stock_unit_snapshot }}</td><td>{{ line.allocations?.length || 0 }} 个批次</td><td>{{ detail.status_name || detail.document_status }}</td><td>{{ line.detail_remark || '-' }}</td></tr><tr v-for="allocation in line.allocations || []" :key="allocation.id" class="allocation-row"><td colspan="2">批次 ID：{{ allocation.from_batch_id }}</td><td>{{ formatQuantity(allocation.quantity_units, allocation.quantity_scale) }}</td><td>单位成本：{{ allocation.unit_cost_cents === null ? '-' : `¥${(Number(allocation.unit_cost_cents) / 100).toFixed(2)}` }}</td><td>目标批次 ID：{{ allocation.to_batch_id || '-' }}</td><td>{{ allocationStatus(allocation) }}</td><td></td></tr></template></tbody></table></div></section>
            </template>
            <div v-else class="detail-meta"><span>此单据详情读取接口尚未接入。</span></div>
          </template>

          <template v-else>
            <section v-if="pageKey === 'inbound' || pageKey === 'outbound'" class="form-grid">
              <div class="form-grid__inline form-grid__full"><span class="field-label">{{ pageKey === 'inbound' ? '入库类型' : '出库类型' }}<i>*</i></span><div><div class="type-radio-group"><button v-for="item in pageKey === 'inbound' ? inboundTypes : outboundTypes" :key="item" :class="{ active: selectedType === item }" @click="pageKey === 'inbound' ? inboundType = item : outboundType = item">{{ item }}</button></div><p class="type-tip">{{ pageKey === 'inbound' ? '调拨入库由调拨确认自动生成，不在此处手工创建。' : '调拨出库由调拨确认自动生成；出库按所选仓库的实际批次扣减。' }}</p></div></div>
              <label class="form-grid__inline"><span class="field-label">{{ pageKey === 'inbound' ? '入库日期' : '出库日期' }}<i>*</i></span><input v-if="pageKey === 'inbound'" v-model="inboundDate" type="date" /><input v-else v-model="outboundDate" type="date" /></label>
              <label v-if="isPlatformHeadquarters" class="form-grid__inline"><span class="field-label">{{ pageKey === 'inbound' ? '入库仓' : '出库仓' }}<i>*</i></span><InventoryStoreSelector v-model="documentWarehouseSelection" :options="documentWarehouseOptions" placeholder="请选择总部仓" @update:modelValue="onDocumentWarehouseChanged" /></label>
              <label v-if="pageKey === 'inbound' && inboundType === '退货入库'" class="form-grid__inline"><span class="field-label">售后单号</span><span class="input-like input-like--action">+ 选择售后单据</span></label>
              <label v-if="pageKey === 'outbound'" class="form-grid__inline"><span class="field-label">出库对象</span><input v-model="outboundRecipient" maxlength="100" placeholder="请输入出库对象" /></label>
              <label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-if="pageKey === 'inbound'" v-model="inboundRemark" placeholder="请输入备注" /><input v-else v-model="outboundRemark" placeholder="请输入备注" /></label>
            </section>

            <section v-else-if="pageKey === 'count'" class="form-grid form-grid--single"><label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-model="countRemark" placeholder="请输入备注" /></label></section>

            <section v-else-if="pageKey === 'request'" class="form-grid">
              <label class="form-grid__inline"><span class="field-label">请货方<i>*</i></span><InventoryStoreSelector v-if="isPlatformHeadquarters" v-model="requestPartySelection" :options="requestPartyOptions" placeholder="总部仓（可切换门店）" @update:modelValue="onRequestPartyChanged" /><input v-else :value="scopeName" disabled /></label>
              <label class="form-grid__inline"><span class="field-label">供货方<i>*</i></span><InventoryStoreSelector v-model="supplyPartySelection" :options="supplyParties" :loading="supplyPartiesLoading" placeholder="请选择供货仓库" @update:modelValue="onSupplyPartyChanged" /></label>
              <label class="form-grid__inline"><span class="field-label">请货日期<i>*</i></span><input v-model="requestDate" type="date" /></label><label class="form-grid__inline"><span class="field-label">请货人<i>*</i></span><select v-model="requesterSelection" @change="requesterName = requesterOptions.find((item) => String(item.key) === String(requesterSelection))?.name || ''"><option value="" disabled>请选择请货人</option><option v-for="person in requesterOptions" :key="person.key" :value="person.key">{{ person.name }}{{ person.type === 'ORG_EMPLOYEE' ? '（组织直属）' : '（门店任职）' }}</option></select></label>
              <label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-model="requestRemark" placeholder="请输入备注" /></label>
            </section>

            <section v-else-if="pageKey === 'transfer'" class="form-grid">
              <label class="form-grid__inline"><span class="field-label">调出方<i>*</i></span><InventoryStoreSelector v-if="isPlatformHeadquarters" v-model="transferSourceSelection" :options="transferSourceOptions" :disabled="isTransferEdit" placeholder="总部仓（可切换门店）" @update:modelValue="onTransferSourceChanged" /><input v-else :value="scopeName" disabled /></label><label class="form-grid__inline"><span class="field-label">调入方<i>*</i></span><InventoryStoreSelector v-model="transferTargetSelection" :options="transferTargetOptions" searchable :disabled="isTransferEdit && requestDocumentId > 0" placeholder="请选择调入方（门店或总部仓）" @update:modelValue="onTransferTargetChanged" /></label>
              <label class="form-grid__inline form-grid__full"><span class="field-label">关联请货单</span><input v-if="isTransferEdit" :value="requestDocumentId > 0 ? `请货单 #${requestDocumentId}` : '不关联请货单'" disabled /><select v-else v-model.number="requestDocumentId" @focus="loadTransferContext" @change="applyIncomingRequest"><option :value="0">不关联请货单</option><option v-for="request in incomingRequests" :key="request.id" :value="Number(request.id)">{{ request.request_no }} · {{ request.request_party_name_snapshot || request.request_store_name || '总部仓' }}</option></select></label>
              <label class="form-grid__inline"><span class="field-label">调拨日期<i>*</i></span><input v-model="transferDate" type="date" /></label><label class="form-grid__inline"><span class="field-label">{{ isPlatformHeadquarters ? '调拨人' : '当前操作人' }}<i v-if="isPlatformHeadquarters">*</i></span><select v-if="isPlatformHeadquarters" v-model.number="transferStaffId" :disabled="!transferTargetSelection || !transferStaffOptions.length"><option v-if="!transferStaffOptions.length" :value="0">{{ transferTargetSelection ? '暂无可选调拨人' : '请先选择调入方' }}</option><option v-for="staff in transferStaffOptions" :key="staff.id" :value="Number(staff.id)">{{ staff.employee_name || staff.staff_name }}（{{ staff.store_name || staff.store_name_snapshot || '平台账号' }}）{{ staff.is_current ? ' · 当前账户' : '' }}</option></select><input v-else value="当前登录人员" disabled /></label>
              <label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-model="transferRemark" placeholder="请输入备注" /></label>
            </section>

            <section v-else-if="pageKey === 'warehouse'" class="form-grid">
              <label>所属总部<i>*</i><input v-if="modalKind === 'warehouse-edit'" :value="detail.organization_name_snapshot" disabled /><select v-else v-model.number="warehouseStoreId"><option :value="0" disabled>请选择所属总部</option><option v-for="location in warehouseStoreOptions" :key="location.owner_id" :value="Number(location.id)">{{ location.organization_name_snapshot || location.location_name }}</option></select></label>
              <label>仓库名称<i>*</i><input v-model="warehouseName" maxlength="100" placeholder="请输入仓库名称" /></label><label v-if="modalKind === 'warehouse-edit'">状态<select v-model="warehouseStatus"><option value="ACTIVE">启用</option><option value="INACTIVE">停用</option></select></label>
            </section>

            <section v-else-if="pageKey === 'usage'" class="form-grid">
              <label class="form-grid__inline form-grid__full"><span class="field-label">核销项目</span><span class="usage-project-picker"><input v-model="usageProjectKeyword" placeholder="搜索项目名称或编码" @input="loadUsageProjects" /><select v-model="usageProjectId" :disabled="usageProjectsLoading" @change="selectUsageProject"><option value="">{{ usageProjectsLoading ? '正在读取项目…' : '不关联核销项目' }}</option><option v-for="project in usageProjects" :key="project.id" :value="String(project.id)">{{ project.name }}{{ project.code ? `（${project.code}）` : '' }}</option></select><small v-if="usageProjectName">已选择：{{ usageProjectName }}</small><small v-else-if="usageProjectsError" class="catalog-error">{{ usageProjectsError }}</small><small v-else-if="!usageProjectsLoading && !usageProjects.length">当前仓库没有可用核销项目，可不关联项目直接领用</small></span></label>
              <label class="form-grid__inline"><span class="field-label">领用日期<i>*</i></span><input v-model="usageDate" type="date" /></label>
              <label v-if="isPlatformHeadquarters" class="form-grid__inline"><span class="field-label">领用仓库<i>*</i></span><InventoryStoreSelector v-model="usageWarehouseSelection" :options="usageWarehouseOptions" placeholder="请选择领用仓库" @update:modelValue="onUsageWarehouseChanged" /></label>
              <label class="form-grid__inline"><span class="field-label">操作人</span><input value="当前登录人员" disabled /></label>
              <label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-model="usageRemark" placeholder="请输入备注" /></label>
            </section>

          </template>

          <!-- 盘点可能一次加载数百个规格；只让明细表滚动，保留表单与操作入口的位置。 -->
          <section v-if="!isImport && !isDetail && pageKey !== 'warehouse'" class="line-section" :class="{ 'line-section--count': pageKey === 'count' }">
            <header>
              <div><h3 v-if="pageKey === 'count'">账面数量合计：{{ countBookQuantityTotal }}</h3><h3 v-else>{{ pageKey === 'request' ? '请货商品' : pageKey === 'transfer' ? '调拨商品' : pageKey === 'inbound' ? '入库商品' : pageKey === 'outbound' ? '出库商品' : '业务明细' }}<i v-if="!isDetail">*</i></h3><p v-if="pageKey === 'inbound'">扫描商品条码可自动添加；批次、生产日期、到期日和入库单价在商品明细中填写。</p><p v-else-if="pageKey === 'outbound'">系统会优先扣减最早到期的可用批次，并自动计算成本。</p></div>
              <div v-if="!isDetail" class="line-actions">
                <template v-if="pageKey === 'count'">
                  <button class="modal-secondary" :disabled="countLoading || submitting" @click="prepareZeroCountInventory">把所有库存改成0</button>
                  <button class="modal-secondary" :disabled="!selectedRows.length || countLoading || submitting" @click="clearCountRows">清空下列记录</button>
                  <button class="modal-secondary" :disabled="countLoading || submitting" @click="importCurrentInventory">导入库存</button>
                  <button class="modal-secondary" :disabled="!selectedRows.length || countLoading" @click="exportCountRows">导出盘点资料</button>
                  <button class="modal-secondary" :disabled="countLoading" @click="countFileInput?.click()">导入盘点</button>
                  <input ref="countFileInput" class="count-file-input" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="importCountRows" />
                  <button class="modal-secondary" :disabled="countLoading || submitting" @click="loadAllCountProducts">{{ countLoading ? '加载中…' : '加载全部商品' }}</button>
                </template>
                <button class="modal-secondary" @click="openCatalogPicker"><Search :size="16" />选择商品</button><button v-if="['inbound', 'outbound'].includes(pageKey)" class="modal-secondary" @click="openScanner"><QrCode :size="16" />扫码添加</button><button class="modal-secondary" :disabled="!selectedLineIndexes.length" @click="bulkEditorVisible = !bulkEditorVisible">批量填写</button><button class="modal-danger" :disabled="!selectedLineIndexes.length" @click="removeSelectedRows"><Trash2 :size="15" />批量删除</button><span class="line-submit-actions"><button class="modal-secondary" :disabled="submitting" @click="close">取消</button><template v-if="pageKey === 'count'"><button class="modal-secondary" :disabled="submitting || countLoading || !selectedRows.length" @click="saveCountDraft">{{ submitting ? '保存中' : '保存草稿' }}</button><button class="modal-primary" :disabled="submitting || countLoading || !selectedRows.length" @click="submitCount">{{ submitting ? '提交中' : '完成盘点' }}</button></template><button v-else class="modal-primary" :disabled="primarySubmitDisabled" @click="submitPrimaryEditor">{{ primarySubmitLabel }}</button></span>
              </div>
            </header>
            <p v-if="catalogError" class="catalog-error">{{ catalogError }}</p><p v-if="pageKey === 'count' && countProgress" class="count-progress">{{ countProgress }}</p>
            <section v-if="scannerVisible" class="scanner-entry"><Barcode :size="17" /><label>扫描条码<input v-model="scannerCode" autofocus placeholder="请扫描或输入商品条码" @keyup.enter="addScannedProduct" /></label><button class="modal-secondary" @click="scannerVisible = false">取消</button><button class="modal-primary" :disabled="!scannerCode.trim() || scannerLoading" @click="addScannedProduct">{{ scannerLoading ? '添加中' : '添加' }}</button></section>
            <section v-if="bulkEditorVisible" class="bulk-editor"><strong>批量填写已选 {{ selectedLineIndexes.length }} 项</strong><label v-for="field in bulkFields" :key="field.key">{{ field.label }}<input v-model="bulkValues[field.key]" :type="field.type" :placeholder="`不填写则不覆盖`" /></label><div><button class="modal-secondary" @click="bulkEditorVisible = false">取消</button><button class="modal-primary" @click="applyBulkValues">应用到已选行</button></div></section>
            <div class="modal-table-scroll"><table><thead><tr><th><input type="checkbox" :checked="allLinesSelected" @change="toggleAllLines" /></th><th v-for="column in columns" :key="column">{{ column }}</th><th>操作</th></tr></thead><tbody ref="lineTableBody"><tr v-if="!productRows.length && ['inbound', 'outbound', 'count', 'request', 'transfer', 'usage'].includes(pageKey)"><td :colspan="columns.length + 2" class="modal-empty">请扫描商品条码或搜索并选择商品</td></tr><tr v-for="(row, rowIndex) in productRows" :key="`${selectedRows[rowIndex]?.sku_id || rowIndex}:${selectedRows[rowIndex]?.sku_unique || ''}:${rowIndex}`" :class="{ 'count-row-invalid': pageKey === 'count' && countValidationRowIndexes.includes(rowIndex) }"><td><input v-model="selectedLineIndexes" type="checkbox" :value="rowIndex" /></td><td v-for="(cell, cellIndex) in row" :key="cellIndex">
              <!-- 免录只隐藏对应行的资料输入；混合单保留列，既有批次身份不在页面清空。 -->
              <template v-if="Number(selectedRows[rowIndex].no_expiry_management) === 1 && ((pageKey === 'inbound' && [4, 5, 6].includes(cellIndex)) || (pageKey === 'count' && [8, 10, 11].includes(cellIndex)))">{{ (pageKey === 'inbound' && cellIndex === 4) || (pageKey === 'count' && cellIndex === 8) ? '' : '-' }}</template>
              <template v-else-if="pageKey === 'inbound' && cellIndex === 4"><input v-model="selectedRows[rowIndex].batch_no" class="table-input" /></template>
              <template v-else-if="pageKey === 'inbound' && cellIndex === 5"><input :value="selectedRows[rowIndex].manufactured_date" class="table-input" type="date" :aria-invalid="selectedRows[rowIndex].manufactured_date_bad_input" @input="updateInboundDate(rowIndex, 'manufactured_date', $event)" /></template>
              <template v-else-if="pageKey === 'inbound' && cellIndex === 6"><input :value="selectedRows[rowIndex].expire_date" class="table-input" type="date" :aria-invalid="selectedRows[rowIndex].expire_date_bad_input" @input="updateInboundDate(rowIndex, 'expire_date', $event)" /></template>
              <template v-else-if="pageKey === 'inbound' && cellIndex === 8"><input v-model="selectedRows[rowIndex].unit_cost" class="table-input" /></template>
              <template v-else-if="(pageKey === 'inbound' && cellIndex === 7) || (pageKey === 'outbound' && cellIndex === 7) || (pageKey === 'request' && cellIndex === 5) || (pageKey === 'transfer' && cellIndex === 6) || (pageKey === 'usage' && cellIndex === 5)"><input v-model="selectedRows[rowIndex].quantity" class="table-input" /></template>
              <template v-else-if="columns[cellIndex] === '明细备注'"><input v-model="selectedRows[rowIndex].detail_remark" class="table-input table-input--remark" maxlength="500" aria-label="明细备注" placeholder="填写备注" /></template>
              <template v-else-if="pageKey === 'count' && cellIndex === 6"><input v-model="selectedRows[rowIndex].counted_quantity" class="table-input" /></template><template v-else-if="pageKey === 'count' && cellIndex === 8"><input v-model="selectedRows[rowIndex].surplus_batch_no" class="table-input" :placeholder="selectedRows[rowIndex].count_mode === 'BATCH' ? '新批次必填' : '盘盈必填'" /></template><template v-else-if="pageKey === 'count' && cellIndex === 9"><input v-model="selectedRows[rowIndex].surplus_unit_cost" class="table-input" :placeholder="selectedRows[rowIndex].count_mode === 'BATCH' ? '新批次必填' : '盘盈必填'" /></template><template v-else-if="pageKey === 'count' && cellIndex === 10"><input v-model="selectedRows[rowIndex].surplus_manufactured_date" class="table-input" type="date" /></template><template v-else-if="pageKey === 'count' && cellIndex === 11"><input v-model="selectedRows[rowIndex].surplus_expire_date" class="table-input" type="date" /></template><template v-else>{{ cell }}</template></td><td><button class="modal-link" @click="removeCatalogRow(rowIndex)">删除</button></td></tr></tbody></table></div>
            <footer v-if="['inbound', 'outbound', 'request', 'transfer'].includes(pageKey)" class="line-total"><span>明细数量：{{ selectedRows.length }} 项</span><strong v-if="pageKey === 'inbound'">入库金额按填写单价计算</strong><strong v-else-if="pageKey === 'outbound'">出库成本由服务端按实际批次计算</strong></footer>
          </section>

          <aside v-if="!isDetail && ['request', 'transfer'].includes(pageKey)" class="modal-notice"><AlertTriangle :size="17" /><div><strong>操作注意事项</strong><p>{{ pageKey === 'request' ? '请货不会变动库存；供货方发货时，系统会优先扣减最早到期的可用批次。收货完成后，已收数量会累计到请货单。' : '保存草稿不变动库存；调出方发货后进入在途，调入方确认收货才增加库存。' }}</p></div></aside>
          <p v-if="submitError && pageKey !== 'count'" class="catalog-error">{{ submitError }}</p>
        </main>

        <footer v-if="isDetail || isImport || pageKey === 'warehouse'" class="inventory-modal__footer">
          <button v-if="isDetail" class="modal-primary" @click="close">关闭</button>
          <button v-else-if="isImport" class="modal-secondary" :disabled="importBusy" @click="close">返回列表</button>
          <template v-else><button class="modal-secondary" :disabled="submitting" @click="close">取消</button><button class="modal-primary" :disabled="primarySubmitDisabled" @click="submitPrimaryEditor">{{ primarySubmitLabel }}</button></template>
        </footer>
  </section>
  <!-- 盘点每次仅选择本次新增批次的商品，不把已有批次回填为全选后再次添加；其他业务维持原选择逻辑。 -->
  <InventoryProductSelector :visible="productSelectorVisible" :selected-rows="pageKey === 'count' ? [] : selectedRows" :catalog-api="catalogApi" :catalog-query="catalogQuery" @close="productSelectorVisible = false" @confirm="acceptCatalogRows" />
</template>

<style scoped>
.table-input--remark { min-width: 160px; width: 100%; }
.count-file-input { display: none; }.count-progress { margin: 0; padding: 8px 14px; color: #42637e; font-size: 12px; background: #f4f9ff; }
/* 大盘点只约束商品明细高度；固定表头与可见滚动轨道让 805 行也能逐行编辑。 */
.line-section--count > .modal-table-scroll { max-height: min(60vh, 600px); overflow-y: scroll; scrollbar-gutter: stable; scrollbar-color: #91aac4 #eef3f8; scrollbar-width: thin; overscroll-behavior: contain; }
.line-section--count > .modal-table-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
.line-section--count > .modal-table-scroll::-webkit-scrollbar-track { background: #eef3f8; }
.line-section--count > .modal-table-scroll::-webkit-scrollbar-thumb { border: 2px solid #eef3f8; border-radius: 8px; background: #91aac4; }
.line-section--count > .modal-table-scroll thead th { position: sticky; top: 0; z-index: 1; }
/* 完成盘点失败时定位首条问题明细；仅强调输入边界，不替用户猜测或补写批次数据。 */
.count-row-invalid > td { background: #fff2f0; }
.count-row-invalid .table-input { border-color: #df5c55; box-shadow: 0 0 0 2px rgba(223, 92, 85, .12); }
/* 非盘点业务和详情可能一次包含大量商品；限制明细区高度，避免表格撑出视口且保留表头与操作入口。 */
.inventory-modal__body:not(.inventory-modal__body--count) .line-section > .modal-table-scroll { max-height: min(48vh, 520px); overflow-y: scroll; scrollbar-gutter: stable; scrollbar-color: #91aac4 #eef3f8; scrollbar-width: thin; overscroll-behavior: contain; }
.inventory-modal__body:not(.inventory-modal__body--count) .line-section > .modal-table-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
.inventory-modal__body:not(.inventory-modal__body--count) .line-section > .modal-table-scroll::-webkit-scrollbar-track { background: #eef3f8; }
.inventory-modal__body:not(.inventory-modal__body--count) .line-section > .modal-table-scroll::-webkit-scrollbar-thumb { border: 2px solid #eef3f8; border-radius: 8px; background: #91aac4; }
.inventory-modal__body:not(.inventory-modal__body--count) .line-section > .modal-table-scroll thead th { position: sticky; top: 0; z-index: 1; }
.inventory-editor__header-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.inventory-editor__submit-error { max-width: 620px; margin: 6px 0 0 !important; color: #c34141 !important; font-size: 12px !important; }
/* 编辑器高度始终受当前视口约束，明细过多时只滚动中间主体，不得将底部提交栏推出可视区。 */
.inventory-editor { display: grid; grid-template-rows: auto minmax(0, 1fr) auto; height: calc(100vh - 112px); min-height: 0; overflow: hidden; border: 1px solid #d8e3ed; border-radius: 8px; background: #f5f7fa; }.inventory-editor__header, .inventory-modal__footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 20px; border-bottom: 1px solid #e0e7ef; background: #fff; }.inventory-editor__header p { margin: 0 0 4px; color: #8a97a5; font-size: 11px; }.inventory-editor__header h2 { margin: 0; color: #253a50; font-size: 18px; }.inventory-modal__footer { justify-content: flex-end; border-top: 1px solid #e0e7ef; border-bottom: 0; }.modal-icon-button { display: inline-grid; width: 34px; height: 34px; place-items: center; padding: 0; border: 1px solid #dce5ee; border-radius: 8px; background: #fff; color: #47627d; }.modal-icon-button:hover { border-color: #91caff; color: #176fd1; }
.inventory-modal__body { overflow: auto; padding: 18px 20px 22px; }.form-section { margin-bottom: 15px; padding: 14px; border: 1px solid #e0e7ef; border-radius: 10px; background: #fff; }.form-section__heading { margin-bottom: 12px; }.form-section__heading h3 { margin: 0; color: #314b65; font-size: 14px; }.form-label { display: block; margin-bottom: 10px; color: #5c7187; font-size: 12px; }.form-section i, .form-grid i, .line-section h3 i { margin-left: 3px; color: #e05252; font-style: normal; }.type-radio-group { display: flex; flex-wrap: wrap; gap: 8px; }.type-radio-group button { min-height: 31px; padding: 0 12px; border: 1px solid #dbe5ee; border-radius: 7px; background: #fff; color: #5b7086; font-size: 12px; }.type-radio-group button.active { border-color: #176fd1; background: #eaf3ff; color: #176fd1; font-weight: 700; }.type-tip { margin: 9px 0 0; color: #8491a0; font-size: 11px; }.form-grid { display: grid; grid-template-columns: 1fr 1fr; align-items: start; gap: 13px 18px; margin-bottom: 15px; padding: 15px; border: 1px solid #e0e7ef; border-radius: 10px; background: #fff; }.form-grid--embedded { margin: 14px 0 0; padding: 14px 0 0; border-width: 1px 0 0; border-radius: 0; }.form-grid--single { grid-template-columns: 1fr; }.form-grid label { display: grid; align-content: start; gap: 6px; color: #60748a; font-size: 12px; }.form-grid__inline { grid-template-columns: max-content minmax(0, 1fr); align-items: center !important; gap: 8px !important; }.field-label { display: inline-flex; align-items: center; gap: 2px; white-space: nowrap; }.form-grid input, .form-grid select, .input-like { width: 100%; height: 34px; padding: 0 9px; border: 1px solid #d8e3ed; border-radius: 8px; outline: 0; background: #fff; color: #40566d; }.input-like { display: inline-flex; align-items: center; gap: 6px; }.input-like--action { border-color: #b8d8f7; background: #f6fbff; color: #176fd1; }.form-grid__full { grid-column: 1 / -1; }
.line-section { overflow: hidden; border: 1px solid #dfe8f0; border-radius: 10px; background: #fff; }.line-section > header { display: flex; align-items: start; justify-content: space-between; gap: 12px; padding: 15px; border-bottom: 1px solid #e8eef4; }.line-section h3 { margin: 0; color: #30465d; font-size: 14px; }.line-section p { margin: 4px 0 0; color: #8795a5; font-size: 11px; }.line-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 7px; }.line-submit-actions { display: inline-flex; gap: 7px; margin-left: 2px; padding-left: 9px; border-left: 1px solid #dce5ee; }.scanner-entry { display: flex; align-items: end; gap: 9px; padding: 12px 15px; border-bottom: 1px solid #e8eef4; background: #f7fbff; color: #176fd1; }.scanner-entry label { display: grid; flex: 1; gap: 4px; color: #60748a; font-size: 11px; }.scanner-entry input { height: 31px; padding: 0 8px; border: 1px solid #bcd6ed; border-radius: 6px; color: #40566d; }.bulk-editor { display: grid; grid-template-columns: repeat(5, minmax(120px, 1fr)) auto; gap: 9px; align-items: end; padding: 12px 15px; border-bottom: 1px solid #e8eef4; background: #f7fbff; }.bulk-editor strong { grid-column: 1 / -1; color: #36526e; font-size: 12px; }.bulk-editor label { display: grid; gap: 5px; color: #60748a; font-size: 11px; }.bulk-editor input { height: 30px; padding: 0 7px; border: 1px solid #bcd6ed; border-radius: 6px; color: #40566d; }.bulk-editor div { display: flex; gap: 7px; }.modal-primary, .modal-secondary, .modal-danger { display: inline-flex; align-items: center; justify-content: center; gap: 5px; min-height: 33px; padding: 0 12px; border-radius: 8px; font-size: 12px; }.modal-primary { border: 1px solid #176fd1; background: #176fd1; color: #fff; }.modal-secondary { border: 1px solid #d5e1ee; background: #fff; color: #47627d; }.modal-danger { border: 1px solid #f0d1d1; background: #fff8f8; color: #ca4c4c; }.line-search { display: flex; align-items: center; gap: 8px; margin: 14px; padding: 0 9px; border: 1px solid #dbe5ee; border-radius: 8px; color: #7f8fa0; background: #fbfdff; }.line-search input { width: 240px; height: 34px; border: 0; outline: 0; background: transparent; }.modal-table-scroll { overflow: auto; }.modal-table-scroll table { width: 100%; min-width: 1180px; border-collapse: collapse; }.modal-table-scroll th { height: 39px; padding: 0 11px; border-bottom: 1px solid #e7edf3; background: #f7f9fc; color: #60748a; font-size: 11px; font-weight: 600; text-align: left; white-space: nowrap; }.modal-table-scroll td { height: 51px; padding: 0 11px; border-bottom: 1px solid #edf1f5; color: #40566d; font-size: 12px; white-space: nowrap; }.table-input { width: 76px; height: 29px; padding: 0 6px; border: 1px solid #bcd6ed; border-radius: 6px; color: #344d68; font-size: 12px; }.table-input[type='date'] { width: 142px; min-width: 142px; }.modal-link { border: 0; background: transparent; color: #d05252; font-size: 12px; }.line-total { display: flex; justify-content: space-between; padding: 12px 15px; background: #fbfdff; color: #7d8c9c; font-size: 12px; }.line-total strong { color: #1f4e80; }.modal-notice { display: flex; gap: 8px; margin-top: 14px; padding: 12px 14px; border: 1px solid #f0ddae; border-radius: 9px; background: #fffbef; color: #a86d12; }.modal-notice div { display: grid; gap: 4px; }.modal-notice strong { font-size: 12px; }.modal-notice p { margin: 0; color: #857040; font-size: 11px; }.detail-meta { display: flex; flex-wrap: wrap; gap: 10px 20px; margin-bottom: 14px; padding: 12px 14px; border: 1px solid #dfe8f0; border-radius: 9px; background: #fff; color: #61758b; font-size: 12px; }.detail-meta b { color: #26835f; }.detail-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 15px; }.detail-summary article { padding: 14px; border: 1px solid #e0e7ef; border-radius: 10px; background: #fff; }.detail-summary span { display: block; color: #8291a2; font-size: 11px; }.detail-summary strong { display: block; margin-top: 7px; color: #284868; font-size: 17px; }.import-workflow { display: grid; gap: 14px; }.import-modal { display: grid; grid-template-columns: 34px minmax(0, 1fr) auto; align-items: center; gap: 12px; padding: 18px; border: 1px solid #cfe1f5; border-radius: 10px; background: #f2f8ff; color: #176fd1; }.import-modal div { display: grid; gap: 4px; }.import-modal strong { color: #304d69; font-size: 14px; }.import-modal p { margin: 0; color: #71849a; font-size: 12px; }.import-rules { display: grid; gap: 5px; padding: 14px 16px; border: 1px solid #f0dfb2; border-radius: 8px; background: #fffbf1; color: #846225; font-size: 12px; line-height: 1.55; }.import-rules strong { color: #76520d; }.import-upload { display: grid; justify-items: center; gap: 7px; padding: 28px 18px; border: 1px dashed #a9c8e3; border-radius: 8px; background: #fafdff; color: #3275ae; cursor: pointer; }.import-upload input { display: none; }.import-upload strong { color: #3a5268; font-size: 13px; }.import-upload small { color: #8292a1; }.import-actions { display: flex; gap: 9px; justify-content: flex-end; }.import-success { margin: 0; color: #26835f; font-size: 13px; }
	.catalog-results { display: grid; gap: 6px; margin: 0 14px 12px; }.catalog-results button { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 11px; border: 1px solid #d8e7f5; border-radius: 7px; background: #f9fcff; color: #2165a5; text-align: left; }.catalog-results button:hover { border-color: #79afe1; background: #f0f8ff; }.catalog-results span { display: grid; gap: 3px; }.catalog-results strong { color: #40566d; font-size: 12px; }.catalog-results small { color: #8293a4; font-size: 11px; }.catalog-error { margin: 0 14px 10px !important; color: #bf5050 !important; }.modal-empty { height: 95px !important; color: #8798a9 !important; text-align: center; }
.usage-project-picker { display: grid; grid-template-columns: minmax(180px, .8fr) minmax(260px, 1.2fr); gap: 8px; }.usage-project-picker small { grid-column: 1 / -1; color: #8293a4; font-size: 11px; }.usage-project-picker .catalog-error { margin: 0 !important; }
.import-store-picker { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; border: 1px solid #d9e6f2; border-radius: 8px; background: #fff; color: #40566d; font-size: 12px; }.import-picker-mask { position: fixed; z-index: 60; inset: 0; display: grid; place-items: center; padding: 20px; background: rgba(21, 39, 58, .35); }.import-picker-dialog { width: min(560px, 100%); max-height: min(680px, 90vh); overflow: hidden; border: 1px solid #d9e4ee; border-radius: 12px; background: #fff; box-shadow: 0 18px 50px rgba(26, 57, 89, .22); }.import-picker-dialog > header, .import-picker-dialog > footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 13px 16px; border-bottom: 1px solid #e7edf3; }.import-picker-dialog > footer { justify-content: flex-end; border-top: 1px solid #e7edf3; border-bottom: 0; }.import-picker-tree { display: grid; gap: 14px; max-height: 480px; overflow: auto; padding: 16px; }.import-picker-group { display: grid; gap: 7px; }.import-picker-group > strong { color: #60748a; font-size: 12px; }.import-picker-group label { display: flex; align-items: center; gap: 8px; min-height: 30px; padding: 0 10px; border-radius: 6px; color: #40566d; font-size: 12px; }.import-picker-group label:hover { background: #f1f7fd; }.import-picker-group input { accent-color: #176fd1; }
@media (max-width: 760px) { .inventory-editor { height: calc(100vh - 94px); }.form-grid { grid-template-columns: 1fr; }.form-grid__full { grid-column: auto; }.line-section > header { flex-direction: column; }.line-actions { justify-content: flex-start; }.detail-summary { grid-template-columns: 1fr; }.import-modal { grid-template-columns: 28px 1fr; }.import-modal button { grid-column: 1 / -1; } }
</style>
