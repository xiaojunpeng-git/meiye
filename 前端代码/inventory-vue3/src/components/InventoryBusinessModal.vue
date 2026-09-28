<script setup>
import { computed, ref, watch } from 'vue'
import { AlertTriangle, Barcode, FileSpreadsheet, Plus, QrCode, Search, Trash2, X } from '@lucide/vue'
import { inventoryApi, platformInventoryApi } from '../services/inventoryApi'
import InventoryProductSelector from './InventoryProductSelector.vue'
import InventoryStoreSelector from './InventoryStoreSelector.vue'
import { inventoryStatusLabel } from '../statusLabels'
import { exportCountCsv, parseCountCsv } from './countWorksheet'
import { changedCountRows } from '../utils/countSubmission'

const props = defineProps({
  pageKey: { type: String, default: '' },
  modalKind: { type: String, default: '' },
  scopeName: { type: String, default: '' },
  defaultLocationName: { type: String, default: '' },
  warehouseOptions: { type: Array, default: () => [] },
  hqLocationId: { type: Number, default: 0 },
  usageStoreId: { type: Number, default: 0 },
  detail: { type: Object, default: null },
  mode: { type: String, default: 'store' }
})

const emit = defineEmits(['close', 'saved', 'start-usage-return'])

const inboundType = ref('采购入库')
const outboundType = ref('过期退货')
const inboundTypes = ['初始入库', '采购入库', '退货入库', '其他入库']
const outboundTypes = ['过期退货', '试用出库', '报废出库', '其他出库']
const selectedRows = ref([])
const selectedLineIndexes = ref([])
const bulkEditorVisible = ref(false)
const bulkValues = ref({})
const catalogError = ref('')
const productSelectorVisible = ref(false)
const countFileInput = ref(null)
const countLoading = ref(false)
const countProgress = ref('')
const scannerVisible = ref(false)
const scannerCode = ref('')
const scannerLoading = ref(false)
const inboundDate = ref(new Date().toISOString().slice(0, 10))
const inboundRemark = ref('')
const outboundDate = ref(new Date().toISOString().slice(0, 10))
const outboundRemark = ref('')
const countRemark = ref('')
const requestDate = ref(new Date().toISOString().slice(0, 10))
const requestRemark = ref('')
const requesterName = ref('')
const requestPartyOptions = ref([])
const requestPartySelection = ref('HQ:0')
const requesterOptions = ref([])
const requesterSelection = ref('')
const supplyParties = ref([])
const supplyPartySelection = ref('')
const supplyPartiesLoading = ref(false)
const transferDate = ref(new Date().toISOString().slice(0, 10))
const transferRemark = ref('')
const transferSourceSelection = ref('HQ:0')
const transferCounterparties = ref([])
const transferTargetSelection = ref('')
const incomingRequests = ref([])
const requestDocumentId = ref(0)
const transferStaffOptions = ref([])
const transferStaffId = ref(0)
const usageDate = ref(new Date().toISOString().slice(0, 10))
const usageProjectId = ref('')
const usageProjectName = ref('')
const usageProjectKeyword = ref('')
const usageProjects = ref([])
const usageProjectsLoading = ref(false)
const usageProjectsError = ref('')
let usageProjectRequestSerial = 0
const usageRemark = ref('')
const warehouseStoreId = ref(0)
const warehouseName = ref('')
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
const isUsageReturn = computed(() => props.modalKind === 'usage-return')
const isImport = computed(() => props.modalKind === 'import')
const isPlatformHeadquarters = computed(() => props.mode === 'platform')
const platformImportStores = computed(() => {
  const hq = props.warehouseOptions.filter((location) => String(location?.location_type || '') === 'HQ' && Number(location?.id || 0) > 0).map((location) => ({ ...location, import_key: `HQ:${Number(location.id)}`, import_name: location.location_name || '总部仓', import_type: '总部仓' }))
  const stores = props.warehouseOptions.filter((location) => String(location?.location_type || '') === 'STORE' && Number(location?.store_id || 0) > 0).map((location) => ({ ...location, import_key: `STORE:${Number(location.store_id)}`, import_name: location.store_name || location.store_name_snapshot || `门店${location.store_id}`, import_type: '门店' }))
  return [...hq, ...stores].filter((item, index, list) => list.findIndex((candidate) => candidate.import_key === item.import_key) === index)
})
const catalogApi = computed(() => isPlatformHeadquarters.value ? platformInventoryApi : inventoryApi)
const catalogQuery = computed(() => {
  // 盘点的账面数要与确认命令锁定的当前库存一致；普通选品仍沿用原目录口径。
  if (!isPlatformHeadquarters.value) return props.pageKey === 'count' ? { for_count: 1 } : {}
  // 请货明细仍按“请货方”目录保存，保证后端可校验请求商品；
  // “当前库存”则必须来自供货方库存主体，不能误展示请货方库存。
  const selection = props.pageKey === 'request'
    ? requestPartySelection.value
    : props.pageKey === 'usage' ? `STORE:${Number(props.usageStoreId) || 0}` : (transferSourceSelection.value || 'HQ:0')
  const [sourcePartyType, rawSourceId] = String(selection).split(':', 2)
  const availabilitySelection = props.pageKey === 'request'
    ? (supplyPartySelection.value || selection)
    : selection
  const [availabilityPartyType, rawAvailabilityId] = String(availabilitySelection).split(':', 2)
  return {
    hq_location_id: Number(props.hqLocationId),
    source_party_type: sourcePartyType,
    source_store_id: Number(rawSourceId || 0),
    availability_party_type: availabilityPartyType,
    availability_store_id: Number(rawAvailabilityId || 0)
  }
})
const warehouseStoreOptions = computed(() => {
  const seen = new Set()
  return props.warehouseOptions.filter((location) => {
    const storeId = Number(location?.store_id || 0)
    if (storeId <= 0 || seen.has(storeId)) return false
    seen.add(storeId)
    return true
  })
})
const title = computed(() => {
  if (isImport.value) return '库存导入'
  if (isInboundOutboundDetail.value) return '入库批次出库明细'
  if (isDetail.value) return `${pageLabel.value}详情`
  if (isUsageReturn.value) return '院装耗材退回'
  if (isRequestEdit.value) return '编辑请货单'
  return {
    inbound: '添加入库单', outbound: '添加出库单', count: '添加盘点单',
    request: '新建请货单', transfer: '新建跨门店调拨', usage: '院装耗材领用', warehouse: '新建仓库', recipe: '新建配方'
  }[props.pageKey] || '库存业务详情'
})
const pageLabel = computed(() => ({ inbound: '入库单', outbound: '出库单', count: '盘点单', request: '请货单', transfer: '调拨单', warehouse: '仓库', recipe: '项目配方', stock: '库存明细', usage: '院装管理', import: '导入记录' }[props.pageKey] || '库存业务'))
const selectedType = computed(() => props.pageKey === 'inbound' ? inboundType.value : outboundType.value)
const requestDetailLines = computed(() => Array.isArray(props.detail?.lines) ? props.detail.lines : [])
const transferDetailLines = computed(() => Array.isArray(props.detail?.lines) ? props.detail.lines : [])
const modalScopeName = computed(() => {
  if (props.pageKey !== 'transfer' || !isPlatformHeadquarters.value) return props.scopeName
  return transferSourceOptions.value.find((option) => String(option.key) === String(transferSourceSelection.value))?.name || props.scopeName
})
const transferSourceOptions = computed(() => {
  if (!isPlatformHeadquarters.value) return []
  const stores = props.warehouseOptions
    .filter((location) => Number(location?.store_id || 0) > 0)
    .map((location) => ({ key: `STORE:${Number(location.store_id)}`, type: 'STORE', id: Number(location.store_id), name: String(location.store_name_snapshot || location.store_name || `门店${location.store_id}`) }))
  return [{ key: 'HQ:0', type: 'HQ', id: 0, name: '总部仓' }, ...stores.filter((item, index, list) => list.findIndex((candidate) => candidate.key === item.key) === index)]
})
const transferTargetOptions = computed(() => transferCounterparties.value
  .map((item) => ({ key: `${String(item?.party_type || 'STORE').toUpperCase()}:${Number(item.id || item.store_id || 0)}`, type: String(item?.party_type || 'STORE').toUpperCase(), id: Number(item.id || item.store_id || 0), name: String(item.name || item.store_name || '') })))

function formatQuantity(units, scale = 0) {
  const divisor = 10 ** Number(scale || 0)
  const value = Number(units || 0) / divisor
  return Number.isInteger(value) ? String(value) : value.toFixed(Number(scale || 0)).replace(/0+$/, '').replace(/\.$/, '')
}

function movementTypeName(sourceType) {
  return {
    manual_inbound: '手工入库',
    manual_inbound_reversal: '手工入库作废',
    manual_outbound: '手工出库',
    manual_outbound_reversal: '手工出库作废',
    cross_transfer_in: '调拨入库',
    cross_transfer_out: '调拨出库',
    cross_transfer_in_reversal: '调拨入库冲销',
    cross_transfer_out_reversal: '调拨出库冲销',
    stock_count_gain: '盘盈入库',
    stock_count_loss: '盘亏出库',
    salon_usage_out: '院装领用出库',
    salon_usage_return: '院装退回入库',
    presale_claim_outbound: '预售领用出库',
    presale_claim_void: '预售领用作废退库'
  }[String(sourceType || '')] || '库存调整'
}

function allocationStatus(allocation) {
  if (Number(allocation?.received_at || 0) > 0) return '已收货'
  if (Number(allocation?.dispatched_at || 0) > 0) return '在途'
  return '待发货'
}

function detailStatus(detail, fallback = '-') {
  return inventoryStatusLabel(detail?.status_name || detail?.document_status, fallback)
}

const productRows = computed(() => {
  if (['inbound', 'outbound'].includes(props.pageKey)) {
    return selectedRows.value.map((row) => props.pageKey === 'inbound'
      ? [row.product_id, row.product_name, row.sku_name, row.barcode, row.batch_no, row.manufactured_date, row.expire_date, row.quantity, row.unit_cost]
      : [row.product_id, row.product_name, row.sku_name, row.barcode, '系统优先扣减最早到期的可用批次', '提交后返回', row.quantity, '系统计算', '系统计算'])
  }
  if (props.pageKey === 'count') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.barcode, row.book_quantity, row.counted_quantity, Number(row.counted_quantity || 0) - Number(row.book_quantity || 0), row.surplus_batch_no, row.surplus_unit_cost, row.surplus_manufactured_date, row.surplus_expire_date])
  if (props.pageKey === 'request') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.barcode, row.available_quantity || '-', row.quantity])
  if (props.pageKey === 'transfer') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.barcode, '发货时优先扣减最早到期的可用批次', '收货时入账', row.quantity])
  if (props.pageKey === 'usage') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.barcode, row.quantity, '系统优先扣减最早到期的可用批次', ''])
  return []
})

const columns = computed(() => {
  const product = ['商品ID', '商品名称', '商品规格', '商品条码']
  const batch = ['批次号', '生产日期', '到期日']
  if (props.pageKey === 'count') return [...product, '账面库存', '实盘库存', '库存盈亏', '盘盈批次号', '盘盈单价', '生产日期', '到期日']
  if (props.pageKey === 'request') return [...product, '当前库存', '申请数量']
  if (props.pageKey === 'transfer') return [...product, '发货批次', '收货状态', '调拨数量']
  if (props.pageKey === 'usage') return [...product, '领用数量', '实际单位成本']
  if (props.pageKey === 'outbound') return [...product, '扣减批次', '到期日', '出库数量', '实际单位成本', '成本金额']
  return [...product, ...batch, '入库数量', '入库单价', '入库金额']
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
      const value = values[field.key]
      if (value !== '' && value !== undefined && value !== null) row[field.key] = value
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
  supplyPartySelection.value = `${String(document.supply_party_type || 'HQ')}:${Number(document.supply_party_id || 0)}`
  selectedRows.value = (Array.isArray(detail.lines) ? detail.lines : []).map((line) => ({
    product_id: Number(line.product_id), sku_id: Number(line.sku_id), sku_unique: String(line.sku_unique || ''),
    product_name: String(line.product_name_snapshot || ''), sku_name: String(line.sku_name_snapshot || ''),
    barcode: String(line.barcode_snapshot || ''), quantity: String(line.quantity || '1'), available_quantity: '-', reference_unit_cost: line.reference_unit_cost_cents === null ? '-' : (Number(line.reference_unit_cost_cents) / 100).toFixed(2)
  }))
  requestEditIdempotencyKey.value = newRequestIdempotencyKey()
  await loadSupplyParties()
}

watch(() => props.detail, (detail) => { hydrateRequestEditor(detail) }, { immediate: true })

function hydrateUsageReturn(detail) {
  if (!isUsageReturn.value || !detail?.document) return
  usageDate.value = new Date().toISOString().slice(0, 10)
  usageProjectId.value = String(detail.document.project_id || '')
  usageProjectName.value = String(detail.document.project_name_snapshot || '')
  usageRemark.value = ''
  selectedRows.value = (Array.isArray(detail.lines) ? detail.lines : [])
    .filter((line) => Number(line.returnable_quantity || 0) > 0)
    .map((line) => ({
      source_usage_line_id: Number(line.line_id), product_id: Number(line.product_id), sku_id: Number(line.sku_id), sku_unique: String(line.sku_unique || ''),
      product_name: String(line.product_name || ''), sku_name: String(line.sku_name || ''), barcode: String(line.barcode || ''),
      quantity: String(line.returnable_quantity), returnable_quantity: String(line.returnable_quantity)
    }))
}

watch([() => props.detail, isUsageReturn], ([detail]) => { hydrateUsageReturn(detail) }, { immediate: true })

async function loadUsageProjects() {
  if (props.pageKey !== 'usage') return
  const requestSerial = ++usageProjectRequestSerial
  usageProjectsLoading.value = true
  usageProjectsError.value = ''
  try {
    const api = isPlatformHeadquarters.value ? platformInventoryApi : inventoryApi
    const response = await api.salonUsageProjects({ keyword: usageProjectKeyword.value.trim(), page: 1, limit: 50, ...(isPlatformHeadquarters.value ? { store_id: Number(props.usageStoreId) } : {}) })
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
      importMessage.value = `导入完成：${result.target_count || 0} 家门店，共生成 ${result.success_count || 0} 张单据。`
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
  if (isPlatformHeadquarters.value && Number(props.hqLocationId) <= 0) {
    catalogError.value = '请选择可操作的总部仓后再选择商品。'
    return
  }
  catalogError.value = ''
  productSelectorVisible.value = true
}

function openScanner() {
  if (!['inbound', 'outbound'].includes(props.pageKey)) return
  if (isPlatformHeadquarters.value && Number(props.hqLocationId) <= 0) {
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
    if (selectedSkuIds.has(Number(row.sku_id))) continue
    const selected = { ...row, batch_no: '', manufactured_date: '', expire_date: '', quantity: '1', unit_cost: '0.00', book_quantity: '0', counted_quantity: '0', surplus_batch_no: '', surplus_unit_cost: '', surplus_manufactured_date: '', surplus_expire_date: '', available_quantity: String(row.available_quantity ?? '0'), reference_unit_cost: '-' }
    if (props.pageKey === 'count') {
      // 商品目录已经按当前库存主体返回权威可用数；避免对每个 SKU 再发一次库存查询。
      selected.book_quantity = String(row.available_quantity ?? '0')
      selected.counted_quantity = selected.book_quantity
    }
    selectedRows.value.push(selected)
    selectedSkuIds.add(Number(row.sku_id))
  }
  productSelectorVisible.value = false
}

/** 只下载当前表格行；导入与清零均为前端草稿操作，不调用库存写接口。 */
function exportCountRows() {
  if (props.pageKey !== 'count' || !selectedRows.value.length) return
  saveBlob(new Blob([exportCountCsv(productRows.value)], { type: 'text/csv;charset=utf-8' }), `盘点资料-${new Date().toISOString().slice(0, 10)}.csv`)
}

function zeroVisibleCountRows() {
  if (props.pageKey !== 'count' || !selectedRows.value.length) return
  if (!window.confirm(`将当前表格 ${selectedRows.value.length} 行的实盘库存全部填为 0？此时不会修改系统库存，完成盘点后才会入账。`)) return
  selectedRows.value.forEach((row) => { row.counted_quantity = '0' })
}

/** 后端目录本身按门店/库存主体过滤；逐页取完，不按库存数量或前 100 行截断。 */
async function allCountCatalog() {
  const rows = [], seen = new Set()
  let page = 1, total = 0
  do {
    const response = await catalogApi.value.searchCatalog({ ...catalogQuery.value, keyword: '', category_id: 0, page, limit: 50 })
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

async function loadAllCountProducts() {
  if (props.pageKey !== 'count' || countLoading.value) return
  countLoading.value = true; catalogError.value = ''; countProgress.value = '正在读取当前门店全部库存商品…'
  try {
    const catalog = await allCountCatalog()
    const existing = new Set(selectedRows.value.map((row) => Number(row.sku_id)))
    const additions = catalog.filter((row) => !existing.has(Number(row.sku_id))).map(countDraftRow)
    for (const row of additions) selectedRows.value.push(row)
    countProgress.value = `已加载 ${catalog.length} 个有效规格，新增 ${additions.length} 行；原有实盘数保持不变。`
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '加载全部商品失败。' }
  finally { countLoading.value = false }
}

function countRowKey(row) {
  return [String(row.product_id), String(row.product_name), String(row.sku_name || ''), String(row.barcode || '')].join('\u001F')
}

async function importCountRows(event) {
  const file = event?.target?.files?.[0]
  if (event?.target) event.target.value = ''
  if (!file || props.pageKey !== 'count' || countLoading.value) return
  countLoading.value = true; catalogError.value = ''; countProgress.value = '正在核对盘点文件…'
  try {
    if (!/\.csv$/i.test(String(file.name))) throw new Error('请导入本页导出的 CSV 盘点资料。')
    const imported = parseCountCsv(await file.text())
    const catalog = await allCountCatalog()
    const catalogByKey = new Map()
    for (const item of catalog) {
      const key = countRowKey(item)
      if (catalogByKey.has(key)) catalogByKey.set(key, null)
      else catalogByKey.set(key, item)
    }
    const existing = new Map(selectedRows.value.map((row) => [Number(row.sku_id), row]))
    const matched = [], seen = new Set()
    for (let index = 0; index < imported.length; index++) {
      const cells = imported[index]
      const key = [cells[0], cells[1], cells[2], cells[3]].join('\u001F')
      const sku = catalogByKey.get(key)
      if (!sku || seen.has(Number(sku.sku_id))) throw new Error(`第 ${index + 2} 行的商品规格不存在、标识重复或不唯一，请重新导出核对。`)
      seen.add(Number(sku.sku_id))
      if (!/^\d+(?:\.\d{1,4})?$/.test(cells[4]) || !/^\d+(?:\.\d{1,4})?$/.test(cells[5])) throw new Error(`第 ${index + 2} 行库存数量无效。`)
      // 文件的账面值必须与导入时目录一致；过期文件不能覆盖更新后的入出库结果。
      if (Number(cells[4]) !== Number(sku.available_quantity ?? 0)) throw new Error(`第 ${index + 2} 行账面库存已变化，请重新导出盘点资料。`)
      const difference = Number(cells[5]) - Number(cells[4])
      if (!/^-?\d+(?:\.\d{1,4})?$/.test(cells[6]) || Math.abs(Number(cells[6]) - difference) > 0.0001) throw new Error(`第 ${index + 2} 行库存盈亏与实盘数不一致。`)
      if (cells[8] && !/^\d+(?:\.\d{1,2})?$/.test(cells[8])) throw new Error(`第 ${index + 2} 行盘盈单价无效。`)
      if ([cells[9], cells[10]].some((value) => value && !/^\d{4}-\d{2}-\d{2}$/.test(value))) throw new Error(`第 ${index + 2} 行日期格式无效。`)
      const row = { ...(existing.get(Number(sku.sku_id)) || countDraftRow(sku)),
        book_quantity: cells[4], counted_quantity: cells[5], surplus_batch_no: cells[7],
        surplus_unit_cost: cells[8], surplus_manufactured_date: cells[9], surplus_expire_date: cells[10] }
      matched.push(row)
    }
    // 全文件校验成功后才一次性更新表格；不删除当前表格里未出现在文件中的行。
    const importedIds = new Set(matched.map((row) => Number(row.sku_id)))
    selectedRows.value = [...selectedRows.value.filter((row) => !importedIds.has(Number(row.sku_id))), ...matched]
    countProgress.value = `已导入 ${matched.length} 行到盘点单；完成盘点前系统库存不变。`
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '盘点资料导入失败。' }
  finally { countLoading.value = false }
}

async function loadSupplyParties() {
  if (props.pageKey !== 'request') return
  if (isPlatformHeadquarters.value && Number(props.hqLocationId) <= 0) {
    supplyParties.value = []
    return
  }
  supplyPartiesLoading.value = true
  try {
    const response = isPlatformHeadquarters.value
      ? await platformInventoryApi.hqRequestCounterparties({ hq_location_id: Number(props.hqLocationId), request_party_type: String(requestPartySelection.value).split(':')[0], request_party_id: Number(String(requestPartySelection.value).split(':')[1] || 0) })
      : await inventoryApi.requestCounterparties()
    const stores = (Array.isArray(response?.list) ? response.list : []).map((item) => {
      const type = String(item.party_type || item.type || 'STORE').toUpperCase()
      const id = Number(item.party_id ?? item.id ?? 0)
      return { key: `${type}:${id}`, type, id, name: String(item.name || '') }
    }).filter((item) => item.name && (item.type === 'HQ' || item.id > 0))
    supplyParties.value = stores
    if (!supplyPartySelection.value || !supplyParties.value.some((item) => item.key === supplyPartySelection.value)) supplyPartySelection.value = supplyParties.value[0]?.key || ''
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '供货方目录读取失败。' } finally { supplyPartiesLoading.value = false }
}

async function loadRequester() {
  if (props.pageKey !== 'request' || isRequestEdit.value) return
  try {
    const response = isPlatformHeadquarters.value
      ? await platformInventoryApi.hqRequestRequester({ hq_location_id: Number(props.hqLocationId), request_party_type: String(requestPartySelection.value).split(':')[0], request_party_id: Number(String(requestPartySelection.value).split(':')[1] || 0) })
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
  if (props.pageKey !== 'request' || !isPlatformHeadquarters.value || Number(props.hqLocationId) <= 0) return
  try {
    const response = await platformInventoryApi.hqRequestParties({ hq_location_id: Number(props.hqLocationId) })
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
  try {
    const [sourceType, rawSourceId] = String(transferSourceSelection.value || 'HQ:0').split(':', 2)
    const sourceQuery = { hq_location_id: Number(props.hqLocationId), source_party_type: sourceType, source_store_id: Number(rawSourceId || 0) }
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
    } catch (error) {
      incomingRequests.value = []
      catalogError.value = error instanceof Error ? `关联请货单读取失败：${error.message}` : '关联请货单读取失败。'
    }
    if (isPlatformHeadquarters.value && !transferSourceSelection.value) transferSourceSelection.value = 'HQ:0'
    await loadTransferStaffs()
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '跨门店调拨对象读取失败。' }
}

async function loadTransferStaffs() {
  transferStaffOptions.value = []
  transferStaffId.value = 0
  if (!isPlatformHeadquarters.value || props.pageKey !== 'transfer') return
  const target = selectedTransferTarget()
  if (!['STORE', 'HQ'].includes(target.type) || target.id < 0) return
  try {
    const [sourcePartyType, rawSourceId] = String(transferSourceSelection.value || 'HQ:0').split(':', 2)
    const response = await platformInventoryApi.hqCrossTransferStaffs({
      hq_location_id: Number(props.hqLocationId), source_party_type: sourcePartyType, source_store_id: Number(rawSourceId || 0), target_store_id: target.type === 'HQ' ? 0 : target.id
    })
    transferStaffOptions.value = Array.isArray(response?.list) ? response.list : []
    transferStaffId.value = Number(response?.current_staff_id || 0)
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
  return { type, id: Number(rawId || 0) }
}

function applyIncomingRequest() {
  const request = incomingRequests.value.find((item) => Number(item.id) === Number(requestDocumentId.value))
  if (!request) return
  transferTargetSelection.value = `${String(request.request_party_type || 'STORE').toUpperCase()}:${Number(request.request_party_id || request.store_id || 0)}`
  const lines = Array.isArray(request.lines) ? request.lines : []
  selectedRows.value = lines.map((line) => {
    const scale = Number(line.quantity_scale || 0)
    const remainingUnits = Number(line.remaining_quantity_units ?? line.remaining_quantity ?? line.quantity ?? 0)
    const quantity = scale > 0 ? (remainingUnits / (10 ** scale)).toFixed(scale).replace(/0+$/, '').replace(/\.$/, '') : String(remainingUnits)
    return {
      product_id: Number(line.product_id || line.pid), sku_id: Number(line.sku_id || line.sku), sku_unique: String(line.sku_unique || ''),
      product_name: String(line.product_name_snapshot || line.product_name || ''), sku_name: String(line.sku_name_snapshot || line.sku_name || ''),
      barcode: String(line.barcode_snapshot || line.barcode || ''), quantity: quantity || '0', request_line_id: Number(line.id || line.request_line_id || 0),
      remaining_quantity_units: remainingUnits
    }
  }).filter((line) => line.request_line_id > 0 && Number(line.quantity) > 0)
  loadTransferStaffs()
}

function selectedTransferTarget() {
  const [type, rawId] = String(transferTargetSelection.value || '').split(':', 2)
  return { type, id: Number(rawId || 0) }
}

function countIdempotencyKey() { return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? `count-${crypto.randomUUID()}` : `count-${Date.now()}-${Math.random().toString(36).slice(2)}` }
async function submitCount() {
  if (props.pageKey !== 'count' || countLoading.value || submitting.value) return
  // 保留加载全部商品的草稿体验，但不得把未盘或无库存变化的规格写入正式盘点单。
  const changedRows = changedCountRows(selectedRows.value)
  if (!changedRows.length) {
    submitError.value = '请至少填写一项与账面库存不同的实盘库存。'
    return
  }
  submitting.value = true
  submitError.value = ''
  try {
    const payload = {
      idempotency_key: countIdempotencyKey(), business_date: inboundDate.value, remark: countRemark.value,
      lines: changedRows.map((row) => ({
        product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique,
        counted_quantity: row.counted_quantity, surplus_batch_no: row.surplus_batch_no,
        surplus_unit_cost: row.surplus_unit_cost, surplus_manufactured_date: row.surplus_manufactured_date,
        surplus_expire_date: row.surplus_expire_date, expected_book_quantity: String(row.book_quantity)
      }))
    }
    if (isPlatformHeadquarters.value) await platformInventoryApi.confirmHqCount({ ...payload, hq_location_id: Number(props.hqLocationId) })
    else await inventoryApi.confirmCount(payload)
    emit('saved')
    close()
  } catch (error) { submitError.value = error instanceof Error ? error.message : '盘点提交失败。' }
  finally { submitting.value = false }
}

function removeCatalogRow(rowIndex) {
  selectedRows.value.splice(rowIndex, 1)
  selectedLineIndexes.value = selectedLineIndexes.value.filter((index) => index !== rowIndex).map((index) => index > rowIndex ? index - 1 : index)
}

function inboundIdempotencyKey() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return `inbound-${crypto.randomUUID()}`
  return `inbound-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

function outboundIdempotencyKey() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return `outbound-${crypto.randomUUID()}`
  return `outbound-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

async function submitInbound() {
  if (props.pageKey !== 'inbound' || !selectedRows.value.length) return
  submitting.value = true
  submitError.value = ''
  try {
    const payload = {
      idempotency_key: inboundIdempotencyKey(), business_date: inboundDate.value, remark: inboundRemark.value,
      lines: selectedRows.value.map((row) => ({
        product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique,
        batch_no: row.batch_no, quantity: row.quantity, unit_cost: row.unit_cost,
        manufactured_date: row.manufactured_date, expire_date: row.expire_date
      }))
    }
    if (isPlatformHeadquarters.value) {
      if (Number(props.hqLocationId) <= 0) throw new Error('请选择可操作的总部仓。')
      await platformInventoryApi.createHqInbound({ ...payload, hq_location_id: Number(props.hqLocationId) })
    } else await inventoryApi.createInbound(payload)
    emit('saved')
    close()
  } catch (error) {
    submitError.value = inventoryErrorMessage(error, '入库提交失败。')
  } finally {
    submitting.value = false
  }
}

async function submitOutbound() {
  if (props.pageKey !== 'outbound' || !selectedRows.value.length) return
  submitting.value = true
  submitError.value = ''
  try {
    const payload = {
      idempotency_key: outboundIdempotencyKey(), business_date: outboundDate.value, remark: outboundRemark.value,
      lines: selectedRows.value.map((row) => ({
        product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity
      }))
    }
    if (isPlatformHeadquarters.value) {
      if (Number(props.hqLocationId) <= 0) throw new Error('请选择可操作的总部仓。')
      await platformInventoryApi.createHqOutbound({ ...payload, hq_location_id: Number(props.hqLocationId) })
    } else await inventoryApi.createOutbound(payload)
    emit('saved')
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

function requestSupplyQuantity(row) {
  const amount = Number(row?.available_quantity ?? row?.availableQuantity ?? 0)
  return Number.isFinite(amount) ? amount : 0
}

function areAllRequestedProductsOutOfStock() {
  return selectedRows.value.length > 0 && selectedRows.value.every((row) => requestSupplyQuantity(row) <= 0)
}

async function submitRequest() {
  const supplier = selectedSupplyParty()
  if (props.pageKey !== 'request' || !selectedRows.value.length || !['HQ', 'STORE'].includes(supplier.type) || (supplier.type === 'STORE' && supplier.id <= 0)) return
  if (areAllRequestedProductsOutOfStock()) {
    submitError.value = '所选商品在供货方库存均为 0，请更换供货方或选择有库存的商品。'
    return
  }
  submitting.value = true
  submitError.value = ''
  try {
    const requestParty = (() => { const [type, rawId] = String(requestPartySelection.value || 'HQ:0').split(':', 2); return { type, id: Number(rawId || 0) } })()
    const selectedRequester = requesterOptions.value.find((item) => String(item.key) === String(requesterSelection.value))
    const payload = {
      idempotency_key: isRequestEdit.value ? requestEditIdempotencyKey.value : requestIdempotencyKey(), business_date: requestDate.value, remark: requestRemark.value, requester_name: String(selectedRequester?.name || requesterName.value).trim(), ...(isPlatformHeadquarters.value ? { request_party_type: requestParty.type, request_party_id: requestParty.id } : {}), supply_party_type: supplier.type, supply_party_id: supplier.id,
      lines: selectedRows.value.map((row) => ({ product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity }))
    }
    if (isPlatformHeadquarters.value) {
      if (Number(props.hqLocationId) <= 0) throw new Error('请选择可操作的总部仓。')
      await platformInventoryApi.applyHqRequest({ ...payload, hq_location_id: Number(props.hqLocationId) })
    } else if (isRequestEdit.value) await inventoryApi.updateRequest(props.detail?.document?.id, payload)
    else await inventoryApi.applyRequest(payload)
    emit('saved')
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
    const commonPayload = { idempotency_key: transferIdempotencyKey(), business_date: transferDate.value, remark: transferRemark.value, target_party_type: target.type, target_store_id: target.id, request_document_id: Number(requestDocumentId.value), lines: selectedRows.value.map((row) => ({ product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity, request_line_id: Number(row.request_line_id || 0) })) }
    if (isPlatformHeadquarters.value) {
      if (Number(props.hqLocationId) <= 0) throw new Error('请选择可操作的总部仓。')
      const [type, rawId] = String(transferSourceSelection.value || 'HQ:0').split(':', 2)
      await platformInventoryApi.createHqCrossTransfer({ ...commonPayload, source_party_type: type, source_store_id: Number(rawId || 0), transfer_staff_id: Number(transferStaffId.value), hq_location_id: Number(props.hqLocationId) })
    } else await inventoryApi.createCrossTransfer(commonPayload)
    emit('saved'); close()
  } catch (error) { submitError.value = error instanceof Error ? error.message : '调拨提交失败。' } finally { submitting.value = false }
}

function usageIdempotencyKey() { return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? `usage-${crypto.randomUUID()}` : `usage-${Date.now()}-${Math.random().toString(36).slice(2)}` }
async function submitUsage() {
  if (props.pageKey !== 'usage' || !selectedRows.value.length || Number(usageProjectId.value) <= 0 || !usageProjectName.value.trim()) return
  submitting.value = true; submitError.value = ''
  try {
    const api = isPlatformHeadquarters.value ? platformInventoryApi : inventoryApi
    const scope = isPlatformHeadquarters.value ? { store_id: Number(props.usageStoreId) } : {}
    if (isUsageReturn.value) {
      const exceedsReturnableQuantity = selectedRows.value.some((row) => Number(row.quantity) <= 0 || Number(row.quantity) > Number(row.returnable_quantity))
      if (exceedsReturnableQuantity) throw new Error('退回数量不能超过原领用数量。')
      await api.returnSalonUsage({ idempotency_key: usageIdempotencyKey(), business_date: usageDate.value, project_id: Number(usageProjectId.value), project_name: usageProjectName.value.trim(), remark: usageRemark.value, return_location_id: Number(props.detail?.document?.location_id || 0), ...scope, lines: selectedRows.value.map((row) => ({ source_usage_line_id: Number(row.source_usage_line_id), quantity: row.quantity })) })
    } else {
      await api.issueSalonUsage({ idempotency_key: usageIdempotencyKey(), business_date: usageDate.value, project_id: Number(usageProjectId.value), project_name: usageProjectName.value.trim(), remark: usageRemark.value, ...scope, lines: selectedRows.value.map((row) => ({ product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity })) })
    }
    emit('saved'); close()
  } catch (error) { submitError.value = error instanceof Error ? error.message : '院装领用提交失败。' } finally { submitting.value = false }
}

function warehouseIdempotencyKey() {
  return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? `warehouse-${crypto.randomUUID()}`
    : `warehouse-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

async function submitWarehouse() {
  if (props.pageKey !== 'warehouse' || props.mode !== 'platform' || Number(warehouseStoreId.value) <= 0 || !warehouseName.value.trim()) return
  submitting.value = true
  submitError.value = ''
  try {
    await platformInventoryApi.createWarehouse({
      idempotency_key: warehouseIdempotencyKey(),
      store_id: Number(warehouseStoreId.value),
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
</script>

<template>
  <section class="inventory-editor" :aria-label="title">
        <header class="inventory-editor__header">
          <div><p>{{ mode === 'platform' ? '平台端' : '门店端' }} · {{ modalScopeName }}</p><h2>{{ title }}</h2></div>
          <button class="modal-icon-button" title="返回列表" @click="close"><X :size="20" /></button>
        </header>

        <main class="inventory-modal__body">
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
              <section class="line-section"><header><div><h3>实际出库记录</h3><p>这里只显示该入库批次后续已生效且未被作废的出库记录。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>出库日期</th><th>出库单号</th><th>出库类型</th><th>商品</th><th>规格</th><th>批次号</th><th>出库数量</th><th>单位</th><th>出库时间</th></tr></thead><tbody><tr v-if="!detail.lines?.length"><td colspan="9" class="modal-empty">该入库批次目前没有出库记录</td></tr><tr v-for="line in detail.lines || []" :key="line.movement_fact_id"><td>{{ line.business_date }}</td><td>{{ line.order_sn }}</td><td>{{ line.outbound_type_name }}</td><td>{{ line.product_name }}</td><td>{{ line.sku_name || '默认规格' }}</td><td>{{ line.batch_no }}</td><td>{{ line.quantity }}</td><td>{{ line.stock_unit }}</td><td>{{ line.operation_at ? new Date(Number(line.operation_at) * 1000).toLocaleString() : '-' }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'inbound' && detail?.document">
              <div class="detail-meta"><span>入库单号：<b>{{ detail.document.order_sn }}</b></span><span>入库日期：{{ detail.document.business_date }}</span><span>入库时间：{{ detail.document.operation_at || detail.document.recorded_at ? new Date(Number(detail.document.operation_at || detail.document.recorded_at) * 1000).toLocaleString() : '-' }}</span><span>入库主体：{{ detail.document.location_name }}</span></div>
              <section class="line-section"><header><div><h3>入库商品</h3><p>明细由已结算入库事实生成，不可在详情中修改。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>条码</th><th>批次号</th><th>生产日期</th><th>到期日</th><th>入库数量</th><th>库存单位</th><th>入库单价</th><th>入库金额</th></tr></thead><tbody><tr v-if="!detail.lines?.length"><td colspan="10" class="modal-empty">该入库单没有已结算明细</td></tr><tr v-for="(line, index) in detail.lines" :key="index"><td>{{ line.product_name }}</td><td>{{ line.sku_name || '默认规格' }}</td><td>{{ line.barcode || '-' }}</td><td>{{ line.batch_no }}</td><td>{{ line.manufactured_date }}</td><td>{{ line.expire_date }}</td><td>{{ line.quantity }}</td><td>{{ line.stock_unit }}</td><td>{{ line.unit_cost_cents === null ? '-' : `¥${(Number(line.unit_cost_cents) / 100).toFixed(2)}` }}</td><td>{{ line.cost_amount_cents === null ? '-' : `¥${(Number(line.cost_amount_cents) / 100).toFixed(2)}` }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'outbound' && detail?.document">
              <div class="detail-meta"><span>出库单号：<b>{{ detail.document.order_sn }}</b></span><span>出库日期：{{ detail.document.business_date }}</span><span>出库时间：{{ detail.document.recorded_at ? new Date(Number(detail.document.recorded_at) * 1000).toLocaleString() : '-' }}</span><span>出库主体：{{ detail.document.location_name }}</span></div>
              <section class="line-section"><header><div><h3>出库商品</h3><p>明细由已结算出库事实生成，不可在详情中修改。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>条码</th><th>扣减批次</th><th>生产日期</th><th>到期日</th><th>出库数量</th><th>库存单位</th><th>实际单位成本</th><th>成本金额</th></tr></thead><tbody><tr v-if="!detail.lines?.length"><td colspan="10" class="modal-empty">该出库单没有已结算明细</td></tr><tr v-for="(line, index) in detail.lines" :key="index"><td>{{ line.product_name }}</td><td>{{ line.sku_name || '默认规格' }}</td><td>{{ line.barcode || '-' }}</td><td>{{ line.batch_no }}</td><td>{{ line.manufactured_date }}</td><td>{{ line.expire_date }}</td><td>{{ line.quantity }}</td><td>{{ line.stock_unit }}</td><td>{{ line.unit_cost_cents === null ? '-' : `¥${(Number(line.unit_cost_cents) / 100).toFixed(2)}` }}</td><td>{{ line.cost_amount_cents === null ? '-' : `¥${(Number(line.cost_amount_cents) / 100).toFixed(2)}` }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'count' && detail?.document">
              <div class="detail-meta"><span>盘点单号：<b>{{ detail.document.order_sn || '-' }}</b></span><span>库存仓：{{ detail.document.location_name || scopeName }}</span><span>盘点日期：{{ detail.document.business_date || '-' }}</span><span>盘点状态：{{ detailStatus(detail.document) }}</span></div>
              <section class="line-section"><header><div><h3>盘点明细</h3><p>{{ detail.document.remark || '盘点数据已确认，明细仅供查看。' }}</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>条码</th><th>账面库存</th><th>实盘库存</th><th>库存盈亏</th><th>库存单位</th><th>盘盈批次号</th><th>盘盈单价</th><th>生产日期</th><th>到期日</th></tr></thead><tbody><tr v-if="!detail.lines?.length"><td colspan="11" class="modal-empty">该盘点单没有商品明细</td></tr><tr v-for="line in detail.lines || []" :key="line.id"><td>{{ line.product_name }}</td><td>{{ line.sku_name || '默认规格' }}</td><td>{{ line.barcode || '-' }}</td><td>{{ line.book_quantity }}</td><td>{{ line.counted_quantity }}</td><td>{{ line.difference_quantity }}</td><td>{{ line.stock_unit || '-' }}</td><td>{{ line.surplus_batch_no || '-' }}</td><td>{{ line.surplus_unit_cost_cents === null || line.surplus_unit_cost_cents === undefined ? '-' : `¥${(Number(line.surplus_unit_cost_cents) / 100).toFixed(2)}` }}</td><td>{{ line.surplus_manufactured_date || '-' }}</td><td>{{ line.surplus_expire_date || '-' }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'usage' && detail?.document">
              <div class="detail-meta"><span>院装单号：<b>{{ detail.document.usage_no }}</b></span><span>关联项目：{{ detail.document.project_name_snapshot }}</span><span>业务日期：{{ detail.document.business_date }}</span><span>类型：{{ detail.document.operation_type === 'RETURN' ? '退回' : '领用' }}</span></div>
              <section class="line-section"><header><div><h3>耗材明细</h3><p>{{ detail.document.remark || '无备注' }}</p></div><button v-if="detail.document.can_return" class="modal-secondary" @click="emit('start-usage-return')">退回耗材</button></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>批次条码</th><th>领用/退回数量</th><th>已退回</th><th>可退回</th><th>库存单位</th></tr></thead><tbody><tr v-if="!detail.lines?.length"><td colspan="7" class="modal-empty">该院装单没有耗材明细</td></tr><tr v-for="line in detail.lines" :key="line.line_id"><td>{{ line.product_name }}</td><td>{{ line.sku_name || '默认规格' }}</td><td>{{ line.barcode || '-' }}</td><td>{{ line.quantity }}</td><td>{{ line.returned_quantity }}</td><td>{{ line.returnable_quantity }}</td><td>{{ line.stock_unit || '-' }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'import' && detail?.record">
              <div class="detail-meta"><span>文件：<b>{{ detail.record.source_file_name }}</b></span><span>方向：{{ detail.record.direction === 'inbound' ? '入库导入' : '出库导入' }}</span><span>业务单号：{{ detail.record.document_no || '-' }}</span><span>状态：{{ detail.record.status === 'SUCCEEDED' ? '成功' : detail.record.status === 'FAILED' ? '失败' : '处理中' }}</span></div>
              <section class="line-section"><header><div><h3>导入结果</h3><p>成功 {{ detail.record.success_count || 0 }} 行，失败 {{ detail.record.failure_count || 0 }} 行。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>Excel 行号</th><th>错误原因</th></tr></thead><tbody><tr v-if="!detail.errors?.length"><td colspan="2" class="modal-empty">本次导入没有错误行</td></tr><tr v-for="error in detail.errors" :key="error.id"><td>{{ error.excel_row || '-' }}</td><td>{{ error.error_message }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'stock' && detail?.product">
              <div class="detail-meta"><span>商品：<b>{{ detail.product.product_name }}</b></span><span>单位：{{ detail.product.stock_unit }}</span><span>规格：{{ detail.product.sku_name || '默认规格' }}</span><span>总可用库存：<b>{{ detail.total_available_quantity }} {{ detail.product.stock_unit }}</b></span><span>库存对账：{{ detail.reconciliation?.status === 'MATCHED' ? '正常' : '异常' }}</span></div>
              <section class="line-section"><header><div><h3>当前批次库存</h3><p>总可用库存由服务端按当前有效批次余额汇总并完成对账。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>仓库</th><th>批次号</th><th>当前剩余库存</th><th>正式入库日</th><th>到期日</th><th>单位成本</th><th>库存金额</th></tr></thead><tbody><tr v-if="!detail.batches?.length"><td colspan="7" class="modal-empty">暂无批次库存</td></tr><tr v-for="batch in detail.batches" :key="batch.batch_id"><td>{{ batch.location_name }}</td><td>{{ batch.batch_no }}</td><td>{{ batch.available_quantity }} {{ batch.stock_unit }}</td><td>{{ batch.received_date || '-' }}</td><td>{{ batch.expire_date || '-' }}</td><td>{{ batch.unit_cost === null ? '-' : `¥${batch.unit_cost}` }}</td><td>{{ batch.inventory_amount_cents === null ? '-' : `¥${(Number(batch.inventory_amount_cents) / 100).toFixed(2)}` }}</td></tr></tbody></table></div></section>
              <section class="line-section"><header><div><h3>出入库流水</h3><p>仅展示当前商品最近 200 条已结算批次事实；冲销流水保留原流水编号。</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>业务日期</th><th>单据号</th><th>业务类型</th><th>方向</th><th>数量</th><th>单位</th><th>来源明细</th><th>冲销关系</th><th>单位成本</th><th>发生仓库</th><th>成本金额</th></tr></thead><tbody><tr v-if="!detail.movements?.length"><td colspan="11" class="modal-empty">暂无出入库流水</td></tr><tr v-for="movement in detail.movements" :key="movement.id"><td>{{ movement.business_date }}</td><td>{{ movement.order_sn }}</td><td>{{ movementTypeName(movement.source_type) }}</td><td>{{ Number(movement.direction) > 0 ? '入库' : '出库' }}</td><td>{{ formatQuantity(movement.quantity_units, movement.quantity_scale) }}</td><td>{{ movement.stock_unit || detail.product.stock_unit }}</td><td>{{ movement.source_detail_id || '-' }}</td><td>{{ Number(movement.reversal_of || 0) > 0 ? `冲销流水 #${movement.reversal_of}` : '-' }}</td><td>{{ movement.unit_cost_cents === null ? '-' : `¥${(Number(movement.unit_cost_cents) / 100).toFixed(2)}` }}</td><td>{{ movement.location_name }}</td><td>{{ movement.cost_amount_cents === null ? '-' : `¥${(Number(movement.cost_amount_cents) / 100).toFixed(2)}` }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'request' && detail?.document">
              <div class="detail-meta"><span>请货单号：<b>{{ detail.document.request_no }}</b></span><span>请货方：{{ detail.document.request_store_name || scopeName }}</span><span>供货方：{{ detail.document.supply_party_name_snapshot || '-' }}</span><span>状态：{{ detailStatus(detail.document) }}</span><span>申请日期：{{ detail.document.business_date }}</span></div>
              <section class="line-section"><header><div><h3>请货商品</h3><p>{{ detail.document.remark || '无备注' }}</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>条码</th><th>申请数量</th><th>库存单位</th></tr></thead><tbody><tr v-if="!requestDetailLines.length"><td colspan="5" class="modal-empty">该请货单没有商品明细</td></tr><tr v-for="line in requestDetailLines" :key="line.id"><td>{{ line.product_name_snapshot }}</td><td>{{ line.sku_name_snapshot || '默认规格' }}</td><td>{{ line.barcode_snapshot || '-' }}</td><td>{{ line.quantity }}</td><td>{{ line.stock_unit_snapshot }}</td></tr></tbody></table></div></section>
            </template>
            <template v-else-if="pageKey === 'transfer' && detail?.transfer_no">
              <div class="detail-meta"><span>调拨单号：<b>{{ detail.transfer_no }}</b></span><span>调出方：{{ detail.from_party_name_snapshot }}</span><span>调入方：{{ detail.to_party_name_snapshot }}</span><span>状态：{{ detailStatus(detail) }}</span><span>调拨日期：{{ detail.business_date }}</span></div>
              <section class="line-section"><header><div><h3>调拨商品</h3><p>{{ detail.remark || '无备注' }}</p></div></header><div class="modal-table-scroll"><table><thead><tr><th>商品</th><th>规格</th><th>调拨数量</th><th>库存单位</th><th>批次分配</th><th>状态</th></tr></thead><tbody><tr v-if="!transferDetailLines.length"><td colspan="6" class="modal-empty">该调拨单没有商品明细</td></tr><template v-for="line in transferDetailLines" :key="line.id"><tr><td>{{ line.product_name_snapshot }}</td><td>{{ line.sku_name_snapshot || '默认规格' }}</td><td>{{ formatQuantity(line.requested_quantity_units, line.quantity_scale) }}</td><td>{{ line.stock_unit_snapshot }}</td><td>{{ line.allocations?.length || 0 }} 个批次</td><td>{{ detail.status_name || detail.document_status }}</td></tr><tr v-for="allocation in line.allocations || []" :key="allocation.id" class="allocation-row"><td colspan="2">批次 ID：{{ allocation.from_batch_id }}</td><td>{{ formatQuantity(allocation.quantity_units, allocation.quantity_scale) }}</td><td>单位成本：{{ allocation.unit_cost_cents === null ? '-' : `¥${(Number(allocation.unit_cost_cents) / 100).toFixed(2)}` }}</td><td>目标批次 ID：{{ allocation.to_batch_id || '-' }}</td><td>{{ allocationStatus(allocation) }}</td></tr></template></tbody></table></div></section>
            </template>
            <div v-else class="detail-meta"><span>此单据详情读取接口尚未接入。</span></div>
          </template>

          <template v-else>
            <section v-if="pageKey === 'inbound' || pageKey === 'outbound'" class="form-grid">
              <div class="form-grid__inline form-grid__full"><span class="field-label">{{ pageKey === 'inbound' ? '入库类型' : '出库类型' }}<i>*</i></span><div><div class="type-radio-group"><button v-for="item in pageKey === 'inbound' ? inboundTypes : outboundTypes" :key="item" :class="{ active: selectedType === item }" @click="pageKey === 'inbound' ? inboundType = item : outboundType = item">{{ item }}</button></div><p class="type-tip">{{ pageKey === 'inbound' ? '调拨入库由调拨确认自动生成，不在此处手工创建。' : '调拨出库由调拨确认自动生成；出库按所选仓库的实际批次扣减。' }}</p></div></div>
              <label class="form-grid__inline"><span class="field-label">{{ pageKey === 'inbound' ? '入库日期' : '出库日期' }}<i>*</i></span><input v-if="pageKey === 'inbound'" v-model="inboundDate" type="date" /><input v-else v-model="outboundDate" type="date" /></label>
              <label v-if="pageKey === 'inbound' && inboundType === '退货入库'" class="form-grid__inline"><span class="field-label">售后单号</span><span class="input-like input-like--action">+ 选择售后单据</span></label>
              <label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-if="pageKey === 'inbound'" v-model="inboundRemark" placeholder="请输入备注" /><input v-else v-model="outboundRemark" placeholder="请输入备注" /></label>
            </section>

            <section v-else-if="pageKey === 'count'" class="form-grid form-grid--single"><label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-model="countRemark" placeholder="请输入备注" /></label></section>

            <section v-else-if="pageKey === 'request'" class="form-grid">
              <label class="form-grid__inline"><span class="field-label">请货方<i>*</i></span><InventoryStoreSelector v-if="isPlatformHeadquarters" v-model="requestPartySelection" :options="requestPartyOptions" placeholder="总部仓（可切换门店）" @update:modelValue="onRequestPartyChanged" /><input v-else :value="scopeName" disabled /></label>
              <label class="form-grid__inline"><span class="field-label">供货方<i>*</i></span><InventoryStoreSelector v-model="supplyPartySelection" :options="supplyParties" :loading="supplyPartiesLoading" :placeholder="isPlatformHeadquarters ? '搜索供货门店' : '搜索总部仓或供货门店'" @update:modelValue="onSupplyPartyChanged" /></label>
              <label class="form-grid__inline"><span class="field-label">请货日期<i>*</i></span><input v-model="requestDate" type="date" /></label><label class="form-grid__inline"><span class="field-label">请货人<i>*</i></span><select v-model="requesterSelection" @change="requesterName = requesterOptions.find((item) => String(item.key) === String(requesterSelection))?.name || ''"><option value="" disabled>请选择请货人</option><option v-for="person in requesterOptions" :key="person.key" :value="person.key">{{ person.name }}{{ person.type === 'ORG_EMPLOYEE' ? '（组织直属）' : '（门店任职）' }}</option></select></label>
              <label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-model="requestRemark" placeholder="请输入备注" /></label>
            </section>

            <section v-else-if="pageKey === 'transfer'" class="form-grid">
              <label class="form-grid__inline"><span class="field-label">调出方<i>*</i></span><InventoryStoreSelector v-if="isPlatformHeadquarters" v-model="transferSourceSelection" :options="transferSourceOptions" placeholder="总部仓（可切换门店）" @update:modelValue="onTransferSourceChanged" /><input v-else :value="scopeName" disabled /></label><label class="form-grid__inline"><span class="field-label">调入方<i>*</i></span><InventoryStoreSelector v-model="transferTargetSelection" :options="transferTargetOptions" placeholder="请选择调入方（门店或总部仓）" @update:modelValue="onTransferTargetChanged" /></label>
              <label class="form-grid__inline form-grid__full"><span class="field-label">关联请货单</span><select v-model.number="requestDocumentId" @focus="loadTransferContext" @change="applyIncomingRequest"><option :value="0">不关联请货单</option><option v-for="request in incomingRequests" :key="request.id" :value="Number(request.id)">{{ request.request_no }} · {{ request.request_party_name_snapshot || request.request_store_name || '总部仓' }}</option></select></label>
              <label class="form-grid__inline"><span class="field-label">调拨日期<i>*</i></span><input v-model="transferDate" type="date" /></label><label class="form-grid__inline"><span class="field-label">{{ isPlatformHeadquarters ? '调拨人' : '当前操作人' }}<i v-if="isPlatformHeadquarters">*</i></span><select v-if="isPlatformHeadquarters" v-model.number="transferStaffId" :disabled="!transferTargetSelection || !transferStaffOptions.length"><option v-if="!transferStaffOptions.length" :value="0">{{ transferTargetSelection ? '暂无可选调拨人' : '请先选择调入方' }}</option><option v-for="staff in transferStaffOptions" :key="staff.id" :value="Number(staff.id)">{{ staff.employee_name || staff.staff_name }}（{{ staff.store_name || staff.store_name_snapshot || '平台账号' }}）{{ staff.is_current ? ' · 当前账户' : '' }}</option></select><input v-else value="当前登录人员" disabled /></label>
              <label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-model="transferRemark" placeholder="请输入备注" /></label>
            </section>

            <section v-else-if="pageKey === 'warehouse'" class="form-grid">
              <label>所属门店<i>*</i><select v-model.number="warehouseStoreId"><option :value="0" disabled>请选择已有默认仓的门店</option><option v-for="location in warehouseStoreOptions" :key="location.store_id" :value="Number(location.store_id)">{{ location.store_name_snapshot || location.location_name }}</option></select></label>
              <label>仓库名称<i>*</i><input v-model="warehouseName" maxlength="100" placeholder="例如：调拨测试仓" /></label>
              <p class="form-grid__full type-tip">创建的是非默认仓库，不改变现有库存、批次或成本；调拨时只能在同门店仓库之间进行。</p>
            </section>

            <section v-else-if="pageKey === 'usage'" class="form-grid">
              <label v-if="!isUsageReturn" class="form-grid__inline form-grid__full"><span class="field-label">核销项目<i>*</i></span><span class="usage-project-picker"><input v-model="usageProjectKeyword" placeholder="搜索项目名称或编码" @input="loadUsageProjects" /><select v-model="usageProjectId" :disabled="usageProjectsLoading" @change="selectUsageProject"><option value="">{{ usageProjectsLoading ? '正在读取项目…' : '请选择本次核销项目' }}</option><option v-for="project in usageProjects" :key="project.id" :value="String(project.id)">{{ project.name }}{{ project.code ? `（${project.code}）` : '' }}</option></select><small v-if="usageProjectName">已选择：{{ usageProjectName }}</small><small v-else-if="usageProjectsError" class="catalog-error">{{ usageProjectsError }}</small><small v-else-if="!usageProjectsLoading && !usageProjects.length">当前门店没有可用核销项目</small></span></label>
              <label v-else class="form-grid__inline form-grid__full"><span class="field-label">原领用项目</span><input :value="usageProjectName" disabled /></label>
              <label class="form-grid__inline"><span class="field-label">{{ isUsageReturn ? '退回日期' : '领用日期' }}<i>*</i></span><input v-model="usageDate" type="date" /></label><label class="form-grid__inline"><span class="field-label">操作人</span><input value="当前登录人员" disabled /></label>
              <label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-model="usageRemark" placeholder="请输入备注" /></label>
            </section>

          </template>

          <section v-if="!isImport && !isDetail && pageKey !== 'warehouse'" class="line-section">
            <header>
              <div><h3>{{ pageKey === 'count' ? '盘点商品' : pageKey === 'request' ? '请货商品' : pageKey === 'transfer' ? '调拨商品' : pageKey === 'inbound' ? '入库商品' : pageKey === 'outbound' ? '出库商品' : '业务明细' }}<i v-if="!isDetail">*</i></h3><p v-if="pageKey === 'inbound'">扫描商品条码可自动添加；批次、生产日期、到期日和入库单价在商品明细中填写。</p><p v-else-if="pageKey === 'outbound'">系统会优先扣减最早到期的可用批次，并自动计算成本。</p></div>
              <div v-if="!isDetail" class="line-actions"><template v-if="pageKey === 'count' && !isPlatformHeadquarters"><button class="modal-secondary" :disabled="!selectedRows.length || countLoading" @click="exportCountRows">导出盘点资料</button><button class="modal-secondary" :disabled="countLoading" @click="countFileInput?.click()">导入盘点</button><input ref="countFileInput" class="count-file-input" type="file" accept=".csv,text/csv" @change="importCountRows" /><button class="modal-secondary" :disabled="!selectedRows.length || countLoading" @click="zeroVisibleCountRows">库存清零</button><button class="modal-secondary" :disabled="countLoading" @click="loadAllCountProducts">{{ countLoading ? '加载中…' : '加载全部商品' }}</button></template><button v-if="!isUsageReturn" class="modal-secondary" @click="openCatalogPicker"><Search :size="16" />选择商品</button><button v-if="!isUsageReturn && ['inbound', 'outbound'].includes(pageKey)" class="modal-secondary" @click="openScanner"><QrCode :size="16" />扫码添加</button><button class="modal-secondary" :disabled="!selectedLineIndexes.length" @click="bulkEditorVisible = !bulkEditorVisible">批量填写</button><button class="modal-danger" :disabled="!selectedLineIndexes.length" @click="removeSelectedRows"><Trash2 :size="15" />批量删除</button></div>
            </header>
            <p v-if="catalogError" class="catalog-error">{{ catalogError }}</p><p v-if="pageKey === 'count' && countProgress" class="count-progress">{{ countProgress }}</p>
            <section v-if="scannerVisible" class="scanner-entry"><Barcode :size="17" /><label>扫描条码<input v-model="scannerCode" autofocus placeholder="请扫描或输入商品条码" @keyup.enter="addScannedProduct" /></label><button class="modal-secondary" @click="scannerVisible = false">取消</button><button class="modal-primary" :disabled="!scannerCode.trim() || scannerLoading" @click="addScannedProduct">{{ scannerLoading ? '添加中' : '添加' }}</button></section>
            <section v-if="bulkEditorVisible" class="bulk-editor"><strong>批量填写已选 {{ selectedLineIndexes.length }} 项</strong><label v-for="field in bulkFields" :key="field.key">{{ field.label }}<input v-model="bulkValues[field.key]" :type="field.type" :placeholder="`不填写则不覆盖`" /></label><div><button class="modal-secondary" @click="bulkEditorVisible = false">取消</button><button class="modal-primary" @click="applyBulkValues">应用到已选行</button></div></section>
            <div class="modal-table-scroll"><table><thead><tr><th><input type="checkbox" :checked="allLinesSelected" @change="toggleAllLines" /></th><th v-for="column in columns" :key="column">{{ column }}</th><th>操作</th></tr></thead><tbody><tr v-if="!productRows.length && ['inbound', 'outbound', 'count', 'request', 'transfer', 'usage'].includes(pageKey)"><td :colspan="columns.length + 2" class="modal-empty">请扫描商品条码或搜索并选择商品</td></tr><tr v-for="(row, rowIndex) in productRows" :key="rowIndex"><td><input v-model="selectedLineIndexes" type="checkbox" :value="rowIndex" /></td><td v-for="(cell, cellIndex) in row" :key="cellIndex"><template v-if="pageKey === 'inbound' && cellIndex === 4"><input v-model="selectedRows[rowIndex].batch_no" class="table-input" /></template><template v-else-if="pageKey === 'inbound' && cellIndex === 5"><input v-model="selectedRows[rowIndex].manufactured_date" class="table-input" type="date" /></template><template v-else-if="pageKey === 'inbound' && cellIndex === 6"><input v-model="selectedRows[rowIndex].expire_date" class="table-input" type="date" /></template><template v-else-if="pageKey === 'inbound' && cellIndex === 7"><input v-model="selectedRows[rowIndex].quantity" class="table-input" /></template><template v-else-if="pageKey === 'inbound' && cellIndex === 8"><input v-model="selectedRows[rowIndex].unit_cost" class="table-input" /></template><template v-else-if="(pageKey === 'outbound' && cellIndex === 6) || (pageKey === 'request' && cellIndex === 5) || (pageKey === 'transfer' && cellIndex === 6) || (pageKey === 'usage' && cellIndex === 4)"><input v-model="selectedRows[rowIndex].quantity" class="table-input" /></template><template v-else-if="pageKey === 'count' && cellIndex === 5"><input v-model="selectedRows[rowIndex].counted_quantity" class="table-input" /></template><template v-else-if="pageKey === 'count' && cellIndex === 7"><input v-model="selectedRows[rowIndex].surplus_batch_no" class="table-input" placeholder="盘盈必填" /></template><template v-else-if="pageKey === 'count' && cellIndex === 8"><input v-model="selectedRows[rowIndex].surplus_unit_cost" class="table-input" placeholder="盘盈必填" /></template><template v-else-if="pageKey === 'count' && cellIndex === 9"><input v-model="selectedRows[rowIndex].surplus_manufactured_date" class="table-input" type="date" /></template><template v-else-if="pageKey === 'count' && cellIndex === 10"><input v-model="selectedRows[rowIndex].surplus_expire_date" class="table-input" type="date" /></template><template v-else>{{ cell }}</template></td><td><button class="modal-link" @click="removeCatalogRow(rowIndex)">删除</button></td></tr></tbody></table></div>
            <footer v-if="['inbound', 'outbound', 'request', 'transfer'].includes(pageKey)" class="line-total"><span>明细数量：{{ selectedRows.length }} 项</span><strong v-if="pageKey === 'inbound'">入库金额按填写单价计算</strong><strong v-else-if="pageKey === 'outbound'">出库成本由服务端按实际批次计算</strong></footer>
          </section>

          <aside v-if="!isDetail && ['request', 'transfer'].includes(pageKey)" class="modal-notice"><AlertTriangle :size="17" /><div><strong>操作注意事项</strong><p>{{ pageKey === 'request' ? '请货不会变动库存；供货方发货时，系统会优先扣减最早到期的可用批次。收货完成后，已收数量会累计到请货单。' : '保存草稿不变动库存；调出方发货后进入在途，调入方确认收货才增加库存。' }}</p></div></aside>
          <p v-if="submitError" class="catalog-error">{{ submitError }}</p>
        </main>

        <footer class="inventory-modal__footer">
          <button class="modal-secondary" @click="close">取消</button>
          <template v-if="!isDetail && !isImport"><button class="modal-primary" :disabled="submitting || (pageKey === 'count' && countLoading) || (['inbound', 'outbound', 'count', 'request', 'transfer', 'usage'].includes(pageKey) && !selectedRows.length) || (pageKey === 'usage' && (!Number(usageProjectId) || !usageProjectName.trim())) || (pageKey === 'request' && (!supplyPartySelection || !requesterName.trim())) || (pageKey === 'transfer' && (!transferTargetSelection || (isPlatformHeadquarters && !transferStaffOptions.length))) || (pageKey === 'warehouse' && (Number(warehouseStoreId) <= 0 || !warehouseName.trim()))" @click="pageKey === 'inbound' ? submitInbound() : pageKey === 'outbound' ? submitOutbound() : pageKey === 'count' ? submitCount() : pageKey === 'request' ? submitRequest() : pageKey === 'transfer' ? submitTransfer() : pageKey === 'usage' ? submitUsage() : pageKey === 'warehouse' ? submitWarehouse() : null">{{ (['inbound', 'outbound', 'count', 'request', 'transfer', 'usage', 'warehouse'].includes(pageKey) && submitting) ? '提交中' : pageKey === 'count' ? '完成盘点' : pageKey === 'request' ? (isRequestEdit ? '保存修改' : '确认申请') : pageKey === 'transfer' ? '保存调拨草稿' : pageKey === 'usage' ? (isUsageReturn ? '确认退回' : '确认领用') : pageKey === 'warehouse' ? '创建仓库' : '保存' }}</button></template>
          <button v-else-if="isImport" class="modal-secondary" :disabled="importBusy" @click="close">返回列表</button>
          <button v-else class="modal-primary" @click="close">关闭</button>
        </footer>
  </section>
  <InventoryProductSelector :visible="productSelectorVisible" :selected-rows="selectedRows" :catalog-api="catalogApi" :catalog-query="catalogQuery" @close="productSelectorVisible = false" @confirm="acceptCatalogRows" />
</template>

<style scoped>
.count-file-input { display: none; }.count-progress { margin: 0; padding: 8px 14px; color: #42637e; font-size: 12px; background: #f4f9ff; }
.inventory-editor { display: grid; grid-template-rows: auto minmax(0, 1fr) auto; min-height: calc(100vh - 112px); overflow: hidden; border: 1px solid #d8e3ed; border-radius: 8px; background: #f5f7fa; }.inventory-editor__header, .inventory-modal__footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 20px; border-bottom: 1px solid #e0e7ef; background: #fff; }.inventory-editor__header p { margin: 0 0 4px; color: #8a97a5; font-size: 11px; }.inventory-editor__header h2 { margin: 0; color: #253a50; font-size: 18px; }.inventory-modal__footer { justify-content: flex-end; border-top: 1px solid #e0e7ef; border-bottom: 0; }.modal-icon-button { display: inline-grid; width: 34px; height: 34px; place-items: center; padding: 0; border: 1px solid #dce5ee; border-radius: 8px; background: #fff; color: #47627d; }.modal-icon-button:hover { border-color: #91caff; color: #176fd1; }
.inventory-modal__body { overflow: auto; padding: 18px 20px 22px; }.form-section { margin-bottom: 15px; padding: 14px; border: 1px solid #e0e7ef; border-radius: 10px; background: #fff; }.form-section__heading { margin-bottom: 12px; }.form-section__heading h3 { margin: 0; color: #314b65; font-size: 14px; }.form-label { display: block; margin-bottom: 10px; color: #5c7187; font-size: 12px; }.form-section i, .form-grid i, .line-section h3 i { margin-left: 3px; color: #e05252; font-style: normal; }.type-radio-group { display: flex; flex-wrap: wrap; gap: 8px; }.type-radio-group button { min-height: 31px; padding: 0 12px; border: 1px solid #dbe5ee; border-radius: 7px; background: #fff; color: #5b7086; font-size: 12px; }.type-radio-group button.active { border-color: #176fd1; background: #eaf3ff; color: #176fd1; font-weight: 700; }.type-tip { margin: 9px 0 0; color: #8491a0; font-size: 11px; }.form-grid { display: grid; grid-template-columns: 1fr 1fr; align-items: start; gap: 13px 18px; margin-bottom: 15px; padding: 15px; border: 1px solid #e0e7ef; border-radius: 10px; background: #fff; }.form-grid--embedded { margin: 14px 0 0; padding: 14px 0 0; border-width: 1px 0 0; border-radius: 0; }.form-grid--single { grid-template-columns: 1fr; }.form-grid label { display: grid; align-content: start; gap: 6px; color: #60748a; font-size: 12px; }.form-grid__inline { grid-template-columns: max-content minmax(0, 1fr); align-items: center !important; gap: 8px !important; }.field-label { display: inline-flex; align-items: center; gap: 2px; white-space: nowrap; }.form-grid input, .form-grid select, .input-like { width: 100%; height: 34px; padding: 0 9px; border: 1px solid #d8e3ed; border-radius: 8px; outline: 0; background: #fff; color: #40566d; }.input-like { display: inline-flex; align-items: center; gap: 6px; }.input-like--action { border-color: #b8d8f7; background: #f6fbff; color: #176fd1; }.form-grid__full { grid-column: 1 / -1; }
.line-section { overflow: hidden; border: 1px solid #dfe8f0; border-radius: 10px; background: #fff; }.line-section > header { display: flex; align-items: start; justify-content: space-between; gap: 12px; padding: 15px; border-bottom: 1px solid #e8eef4; }.line-section h3 { margin: 0; color: #30465d; font-size: 14px; }.line-section p { margin: 4px 0 0; color: #8795a5; font-size: 11px; }.line-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 7px; }.scanner-entry { display: flex; align-items: end; gap: 9px; padding: 12px 15px; border-bottom: 1px solid #e8eef4; background: #f7fbff; color: #176fd1; }.scanner-entry label { display: grid; flex: 1; gap: 4px; color: #60748a; font-size: 11px; }.scanner-entry input { height: 31px; padding: 0 8px; border: 1px solid #bcd6ed; border-radius: 6px; color: #40566d; }.bulk-editor { display: grid; grid-template-columns: repeat(5, minmax(120px, 1fr)) auto; gap: 9px; align-items: end; padding: 12px 15px; border-bottom: 1px solid #e8eef4; background: #f7fbff; }.bulk-editor strong { grid-column: 1 / -1; color: #36526e; font-size: 12px; }.bulk-editor label { display: grid; gap: 5px; color: #60748a; font-size: 11px; }.bulk-editor input { height: 30px; padding: 0 7px; border: 1px solid #bcd6ed; border-radius: 6px; color: #40566d; }.bulk-editor div { display: flex; gap: 7px; }.modal-primary, .modal-secondary, .modal-danger { display: inline-flex; align-items: center; justify-content: center; gap: 5px; min-height: 33px; padding: 0 12px; border-radius: 8px; font-size: 12px; }.modal-primary { border: 1px solid #176fd1; background: #176fd1; color: #fff; }.modal-secondary { border: 1px solid #d5e1ee; background: #fff; color: #47627d; }.modal-danger { border: 1px solid #f0d1d1; background: #fff8f8; color: #ca4c4c; }.line-search { display: flex; align-items: center; gap: 8px; margin: 14px; padding: 0 9px; border: 1px solid #dbe5ee; border-radius: 8px; color: #7f8fa0; background: #fbfdff; }.line-search input { width: 240px; height: 34px; border: 0; outline: 0; background: transparent; }.modal-table-scroll { overflow: auto; }.modal-table-scroll table { width: 100%; min-width: 1180px; border-collapse: collapse; }.modal-table-scroll th { height: 39px; padding: 0 11px; border-bottom: 1px solid #e7edf3; background: #f7f9fc; color: #60748a; font-size: 11px; font-weight: 600; text-align: left; white-space: nowrap; }.modal-table-scroll td { height: 51px; padding: 0 11px; border-bottom: 1px solid #edf1f5; color: #40566d; font-size: 12px; white-space: nowrap; }.table-input { width: 76px; height: 29px; padding: 0 6px; border: 1px solid #bcd6ed; border-radius: 6px; color: #344d68; font-size: 12px; }.table-input[type='date'] { width: 142px; min-width: 142px; }.modal-link { border: 0; background: transparent; color: #d05252; font-size: 12px; }.line-total { display: flex; justify-content: space-between; padding: 12px 15px; background: #fbfdff; color: #7d8c9c; font-size: 12px; }.line-total strong { color: #1f4e80; }.modal-notice { display: flex; gap: 8px; margin-top: 14px; padding: 12px 14px; border: 1px solid #f0ddae; border-radius: 9px; background: #fffbef; color: #a86d12; }.modal-notice div { display: grid; gap: 4px; }.modal-notice strong { font-size: 12px; }.modal-notice p { margin: 0; color: #857040; font-size: 11px; }.detail-meta { display: flex; flex-wrap: wrap; gap: 10px 20px; margin-bottom: 14px; padding: 12px 14px; border: 1px solid #dfe8f0; border-radius: 9px; background: #fff; color: #61758b; font-size: 12px; }.detail-meta b { color: #26835f; }.detail-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 15px; }.detail-summary article { padding: 14px; border: 1px solid #e0e7ef; border-radius: 10px; background: #fff; }.detail-summary span { display: block; color: #8291a2; font-size: 11px; }.detail-summary strong { display: block; margin-top: 7px; color: #284868; font-size: 17px; }.import-workflow { display: grid; gap: 14px; }.import-modal { display: grid; grid-template-columns: 34px minmax(0, 1fr) auto; align-items: center; gap: 12px; padding: 18px; border: 1px solid #cfe1f5; border-radius: 10px; background: #f2f8ff; color: #176fd1; }.import-modal div { display: grid; gap: 4px; }.import-modal strong { color: #304d69; font-size: 14px; }.import-modal p { margin: 0; color: #71849a; font-size: 12px; }.import-rules { display: grid; gap: 5px; padding: 14px 16px; border: 1px solid #f0dfb2; border-radius: 8px; background: #fffbf1; color: #846225; font-size: 12px; line-height: 1.55; }.import-rules strong { color: #76520d; }.import-upload { display: grid; justify-items: center; gap: 7px; padding: 28px 18px; border: 1px dashed #a9c8e3; border-radius: 8px; background: #fafdff; color: #3275ae; cursor: pointer; }.import-upload input { display: none; }.import-upload strong { color: #3a5268; font-size: 13px; }.import-upload small { color: #8292a1; }.import-actions { display: flex; gap: 9px; justify-content: flex-end; }.import-success { margin: 0; color: #26835f; font-size: 13px; }
	.catalog-results { display: grid; gap: 6px; margin: 0 14px 12px; }.catalog-results button { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 11px; border: 1px solid #d8e7f5; border-radius: 7px; background: #f9fcff; color: #2165a5; text-align: left; }.catalog-results button:hover { border-color: #79afe1; background: #f0f8ff; }.catalog-results span { display: grid; gap: 3px; }.catalog-results strong { color: #40566d; font-size: 12px; }.catalog-results small { color: #8293a4; font-size: 11px; }.catalog-error { margin: 0 14px 10px !important; color: #bf5050 !important; }.modal-empty { height: 95px !important; color: #8798a9 !important; text-align: center; }
.usage-project-picker { display: grid; grid-template-columns: minmax(180px, .8fr) minmax(260px, 1.2fr); gap: 8px; }.usage-project-picker small { grid-column: 1 / -1; color: #8293a4; font-size: 11px; }.usage-project-picker .catalog-error { margin: 0 !important; }
.import-store-picker { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; border: 1px solid #d9e6f2; border-radius: 8px; background: #fff; color: #40566d; font-size: 12px; }.import-picker-mask { position: fixed; z-index: 60; inset: 0; display: grid; place-items: center; padding: 20px; background: rgba(21, 39, 58, .35); }.import-picker-dialog { width: min(560px, 100%); max-height: min(680px, 90vh); overflow: hidden; border: 1px solid #d9e4ee; border-radius: 12px; background: #fff; box-shadow: 0 18px 50px rgba(26, 57, 89, .22); }.import-picker-dialog > header, .import-picker-dialog > footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 13px 16px; border-bottom: 1px solid #e7edf3; }.import-picker-dialog > footer { justify-content: flex-end; border-top: 1px solid #e7edf3; border-bottom: 0; }.import-picker-tree { display: grid; gap: 14px; max-height: 480px; overflow: auto; padding: 16px; }.import-picker-group { display: grid; gap: 7px; }.import-picker-group > strong { color: #60748a; font-size: 12px; }.import-picker-group label { display: flex; align-items: center; gap: 8px; min-height: 30px; padding: 0 10px; border-radius: 6px; color: #40566d; font-size: 12px; }.import-picker-group label:hover { background: #f1f7fd; }.import-picker-group input { accent-color: #176fd1; }
@media (max-width: 760px) { .inventory-editor { min-height: calc(100vh - 94px); }.form-grid { grid-template-columns: 1fr; }.form-grid__full { grid-column: auto; }.line-section > header { flex-direction: column; }.line-actions { justify-content: flex-start; }.detail-summary { grid-template-columns: 1fr; }.import-modal { grid-template-columns: 28px 1fr; }.import-modal button { grid-column: 1 / -1; } }
</style>
