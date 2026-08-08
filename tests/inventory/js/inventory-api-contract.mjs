import { createInventoryApi, createPlatformInventoryApi } from '../../../前端代码/inventory-vue3/src/services/inventoryApi.js'

let passed = 0
let failed = 0
function check(name, condition) {
  if (condition) passed += 1
  else failed += 1
  console.log(`${condition ? 'PASS' : 'FAIL'} ${name}`)
}

const calls = []
const api = createInventoryApi({
  prefix: '/storeapi/product/inventory',
  windowRef: { localStorage: { getItem: () => 'store-token' } },
  fetchImpl: async (url, options) => {
    calls.push({ url, options })
    return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: { count: 1, list: [] } }) }
  }
})

await api.list('inbound', { keyword: '690123', stock_time: '' })
check('inbound list maps to the V3 batch-fact endpoint with its fixed query kind', calls[0].url === '/storeapi/product/inventory/v3/movement?kind=inbound&keyword=690123')
check('inventory API uses the current store credential only on same-origin request', calls[0].options.headers['Authori-zation'] === 'Bearer store-token')
check('inventory list requests do not cache stale stock data', calls[0].options.cache === 'no-store')

await api.crossTransferCounterparties()
check('cross-store transfer counterparties are read through the authenticated V3 endpoint', calls[1].url === '/storeapi/product/inventory/v3/cross-transfer/counterparties')

await api.batchAnalysis({ query_cutoff_date: '2026-07-29' })
check('batch analysis sends the selected cutoff date to the server authority', calls[2].url === '/storeapi/product/inventory/v3/batch-analysis?query_cutoff_date=2026-07-29')

await api.dashboard()
check('inventory dashboard reads the server-owned summary rather than a browser fixture', calls[3].url === '/storeapi/product/inventory/v3/dashboard')

await api.list('productSummary', { keyword: '精华', page: 2, limit: 20 })
check('product-level inventory summary uses the dedicated server aggregation endpoint', calls[4].url === '/storeapi/product/inventory/v3/product-summary?keyword=%E7%B2%BE%E5%8D%8E&page=2&limit=20')

await api.productDetail(80559)
check('inventory product detail is fetched from its protected batch-fact endpoint', calls[5].url === '/storeapi/product/inventory/v3/product-summary/80559/detail')

await api.movementStatistics({ kind: 'outbound', from: '2026-07-01', to: '2026-07-30' })
check('inbound and outbound statistics read the V3 immutable batch-fact summary endpoint', calls[6].url === '/storeapi/product/inventory/v3/movement-statistics?kind=outbound&from=2026-07-01&to=2026-07-30')

await api.searchCatalog({ keyword: '精华', category_id: 12, page: 2, limit: 12 })
check('inventory product selector sends only catalog filters and pagination to the store-scoped V3 catalog', calls[7].url === '/storeapi/product/inventory/v3/catalog?keyword=%E7%B2%BE%E5%8D%8E&category_id=12&page=2&limit=12')

await api.unifiedBatchStock({ keyword: '690123', queryCutoffDate: '2026-07-30' })
check('store batch inventory reads from the unified-query provider without client scope fields', calls[8].url === '/storeapi/product/inventory/v3/unified-query/batch-stock?keyword=690123&queryCutoffDate=2026-07-30')

await api.unifiedBatchStock({ filters: [{ field: 'batch_no', operator: 'contains', value: 'B-01' }], sorts: [{ field: 'expire_date', direction: 'asc' }] })
check('inventory UQ sends structured filters and sorting as JSON rather than browser object strings', calls[9].url.includes('filters=%5B%7B%22field%22%3A%22batch_no%22')
  && calls[9].url.includes('sorts=%5B%7B%22field%22%3A%22expire_date%22'))

await api.unifiedQueryCapabilities()
check('inventory UQ capability is loaded through its store-scoped authority endpoint', calls[10].url === '/storeapi/product/inventory/v3/unified-query/capabilities' && calls[10].options.method === 'GET')

await api.unifiedQueryCommand({ action: 'save-unified-query-settings', pageCode: 'inventory_batch_stock', settings: {} })
const uqCommand = JSON.parse(calls[11].options.body)
check('inventory UQ commands use one POST authority endpoint with a generated idempotency key', calls[11].url === '/storeapi/product/inventory/v3/unified-query/commands'
  && calls[11].options.method === 'POST'
  && uqCommand.action === 'save-unified-query-settings'
  && /^inventory-uq-/.test(uqCommand.idempotencyKey))

await api.unifiedQueryExportTask('UQ-INV-001')
check('inventory UQ export polling is limited to the current task number', calls[12].url === '/storeapi/product/inventory/v3/unified-query/export-task/UQ-INV-001')

await api.createInbound({ idempotency_key: 'test-inbound-key', business_date: '2026-07-30', remark: '', lines: [] })
check('manual inbound uses the real product inventory API prefix', calls[13].url === '/storeapi/product/inventory/v3/inbound' && calls[13].options.method === 'POST')

await api.createOutbound({ idempotency_key: 'test-outbound-key', business_date: '2026-07-30', remark: '', lines: [] })
check('manual outbound uses the batch authority API instead of the legacy stock endpoint', calls[14].url === '/storeapi/product/inventory/v3/outbound' && calls[14].options.method === 'POST')

await api.confirmCount({ idempotency_key: 'test-count-key', business_date: '2026-07-30', remark: '', lines: [] })
check('stock count confirmation uses the batch authority API', calls[15].url === '/storeapi/product/inventory/v3/count/confirm' && calls[15].options.method === 'POST')

await api.applyRequest({ idempotency_key: 'test-request-key', business_date: '2026-07-30', remark: '', lines: [] })
check('stock request uses the V3 request authority instead of the legacy draft endpoint', calls[16].url === '/storeapi/product/inventory/v3/request/apply' && calls[16].options.method === 'POST')

await api.cancelRequest(18)
check('stock request cancellation targets the V3 request authority', calls[17].url === '/storeapi/product/inventory/v3/request/cancel/18' && calls[17].options.method === 'POST')

await api.crossTransferIncomingRequests()
await api.createCrossTransfer({ idempotency_key: 'test-transfer-key', business_date: '2026-07-30', remark: '', target_store_id: 2, request_document_id: 0, lines: [] })
await api.dispatchCrossTransfer(28)
await api.receiveCrossTransfer(28)
await api.cancelCrossTransfer(29)
await api.crossTransferDetail(28)
check('cross-store transfer reads only incoming requests explicitly addressed to the current supplier', calls[18].url === '/storeapi/product/inventory/v3/cross-transfer/incoming-requests')
check('cross-store transfer creates a draft against a receiving store instead of an internal warehouse', calls[19].url === '/storeapi/product/inventory/v3/cross-transfer' && calls[19].options.method === 'POST' && JSON.parse(calls[19].options.body).target_store_id === 2)
check('cross-store dispatch, receipt, cancellation, and detail use separate authority endpoints', calls[20].url === '/storeapi/product/inventory/v3/cross-transfer/28/dispatch' && calls[21].url === '/storeapi/product/inventory/v3/cross-transfer/28/receive' && calls[22].url === '/storeapi/product/inventory/v3/cross-transfer/29/cancel' && calls[23].url === '/storeapi/product/inventory/v3/cross-transfer/28/detail')

await api.issueSalonUsage({ idempotency_key: 'test-salon-issue', business_date: '2026-07-30', project_id: 1, project_name: '测试项目', remark: '', lines: [] })
check('salon consumable issue uses the V3 batch authority', calls[24].url === '/storeapi/product/inventory/v3/salon-usage/issue' && calls[24].options.method === 'POST')

await api.returnSalonUsage({ idempotency_key: 'test-salon-return', business_date: '2026-07-30', project_id: 1, project_name: '测试项目', remark: '', return_location_id: 2, lines: [] })
check('salon consumable return uses the V3 batch authority', calls[25].url === '/storeapi/product/inventory/v3/salon-usage/return' && calls[25].options.method === 'POST')

await api.list('import', { direction: 'inbound', page: 1 })
await api.submitImport({ direction: 'inbound', file: '/uploads/inbound.xlsx', real_name: '入库.xlsx' })
await api.importDetail(88)
check('V3 Excel import records and submit command stay on the protected V3 inventory authority', calls[26].url === '/storeapi/product/inventory/v3/import?direction=inbound&page=1'
  && calls[27].url === '/storeapi/product/inventory/v3/import' && calls[27].options.method === 'POST'
  && calls[28].url === '/storeapi/product/inventory/v3/import/88/detail')

const templateApi = createInventoryApi({
  prefix: '/storeapi/product/inventory',
  fetchImpl: async () => ({
    ok: true,
    status: 200,
    headers: { get: (name) => name === 'content-type' ? 'application/json; charset=utf-8' : null },
    text: async () => JSON.stringify({ status: 410000, msg: '请登录' })
  })
})
let templateLoginRejected = false
try {
  await templateApi.downloadImportTemplate('inbound')
} catch (error) {
  templateLoginRejected = error instanceof Error && error.message === '请登录'
}
check('a 200 JSON login envelope is rejected instead of being saved as an xlsx template', templateLoginRejected)

const excelTemplateApi = createInventoryApi({
  prefix: '/storeapi/product/inventory',
  fetchImpl: async () => ({
    ok: true,
    status: 200,
    headers: { get: (name) => name === 'content-type' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : null },
    blob: async () => ({ size: 13, type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  })
})
const excelTemplate = await excelTemplateApi.downloadImportTemplate('inbound')
check('only an Office spreadsheet response is accepted as an import template', excelTemplate.size > 0 && excelTemplate.type.includes('spreadsheetml.sheet'))

const uploadCalls = []
const originalBlob = globalThis.Blob
const originalFormData = globalThis.FormData
class ImportTestBlob { constructor(parts = [], options = {}) { this.parts = parts; this.type = options.type || '' } }
class ImportTestFormData { constructor() { this.entries = [] } append(...entry) { this.entries.push(entry) } }
globalThis.Blob = ImportTestBlob
globalThis.FormData = ImportTestFormData
const uploadApi = createInventoryApi({
  prefix: '/storeapi/product/inventory',
  windowRef: { localStorage: { getItem: () => 'store-token' } },
  fetchImpl: async (url, options) => {
    uploadCalls.push({ url, options })
    return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: { src: '/uploads/inventory-import/202608/test.xlsx' } }) }
  }
})
await uploadApi.uploadImportFile(new ImportTestBlob(['xlsx'], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }))
check('Excel import uploads through the dedicated inventory authority instead of the image attachment endpoint', uploadCalls[0].url === '/storeapi/product/inventory/v3/import/upload'
  && uploadCalls[0].options.method === 'POST' && uploadCalls[0].options.body instanceof FormData)
globalThis.Blob = originalBlob
globalThis.FormData = originalFormData

let rejected = false
const failing = createInventoryApi({
  prefix: '/storeapi/product/inventory',
  fetchImpl: async () => ({ ok: true, status: 200, text: async () => JSON.stringify({ status: 400, msg: '无库存权限' }) })
})
try {
  await failing.list('stock')
} catch (error) {
  rejected = error instanceof Error && error.message === '无库存权限'
}
check('inventory API surfaces server-side scope rejection instead of returning rows', rejected)

let invalidPage = false
try {
  api.list('recipe')
} catch (error) {
  invalidPage = error instanceof Error && error.message.includes('暂不支持列表查询')
}
check('unsupported pages fail closed rather than guessing an endpoint', invalidPage)

const platformCalls = []
const platformApi = createPlatformInventoryApi({
  windowRef: { localStorage: { getItem: () => 'platform-token' } },
  fetchImpl: async (url, options) => {
    platformCalls.push({ url, options })
    return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: { count: 0, list: [] } }) }
  }
})
await platformApi.list('platformLocations')
await platformApi.list('stock', { location_id: 31 })
await platformApi.list('recipe')
await platformApi.list('warehouseSettings')
await platformApi.unifiedBatchStock({ keyword: '测试', storeId: 31 })
await platformApi.unifiedQueryCapabilities({ storeId: 31 })
await platformApi.unifiedQueryCommand({ action: 'save-unified-query-settings', pageCode: 'inventory_batch_stock', storeId: 31, settings: {} })
await platformApi.unifiedQueryExportTask('UQ-PLATFORM-001')
await platformApi.createWarehouse({ idempotency_key: 'warehouse-create-test-001', store_id: 31, location_name: '测试调拨仓' })
check('platform warehouse selector uses the admin V3 scope endpoint', platformCalls[0].url === '/adminapi/product/inventory/v3/locations' && platformCalls[0].options.headers['Authori-zation'] === 'Bearer platform-token')
check('platform batch stock sends only the selected location to the admin V3 scope endpoint', platformCalls[1].url === '/adminapi/product/inventory/v3/batch-stock?location_id=31')
check('platform formula entry reuses the existing headquarters recipe authority', platformCalls[2].url === '/adminapi/product/inventory/recipe/list')
check('platform warehouse settings reuse the server-calculated warehouse list without a client scope', platformCalls[3].url === '/adminapi/product/inventory/v3/locations')
check('platform UQ narrows by selected store rather than exposing a warehouse filter', platformCalls[4].url === '/adminapi/product/inventory/v3/unified-query/batch-stock?keyword=%E6%B5%8B%E8%AF%95&storeId=31')
check('platform UQ capability is scoped through the selected store endpoint', platformCalls[5].url === '/adminapi/product/inventory/v3/unified-query/capabilities?storeId=31')
check('platform UQ write command keeps its generated idempotency key and selected store', platformCalls[6].url === '/adminapi/product/inventory/v3/unified-query/commands' && JSON.parse(platformCalls[6].options.body).storeId === 31)
check('platform UQ export polling remains in the platform authority', platformCalls[7].url === '/adminapi/product/inventory/v3/unified-query/export-task/UQ-PLATFORM-001')
check('platform warehouse creation uses its own POST authority with no browser-supplied scope dimensions', platformCalls[8].url === '/adminapi/product/inventory/v3/locations'
  && platformCalls[8].options.method === 'POST'
  && JSON.stringify(JSON.parse(platformCalls[8].options.body)) === JSON.stringify({ idempotency_key: 'warehouse-create-test-001', store_id: 31, location_name: '测试调拨仓' }))

await platformApi.createHqInbound({ hq_location_id: 41, idempotency_key: 'hq-inbound-001' })
await platformApi.listHqCrossTransfers({ hq_location_id: 41 })
await platformApi.hqCrossTransferCounterparties({ hq_location_id: 41 })
await platformApi.hqCrossTransferIncomingRequests({ hq_location_id: 41 })
await platformApi.hqCrossTransferDetail(51, { hq_location_id: 41 })
await platformApi.createHqCrossTransfer({ hq_location_id: 41, idempotency_key: 'hq-transfer-001' })
await platformApi.dispatchHqCrossTransfer(51, { hq_location_id: 41 })
await platformApi.receiveHqCrossTransfer(51, { hq_location_id: 41 })
await platformApi.cancelHqCrossTransfer(52, { hq_location_id: 41 })
check('platform HQ inbound uses the dedicated platform-only V3 command', platformCalls[9].url === '/adminapi/product/inventory/v3/hq/inbound' && platformCalls[9].options.method === 'POST')
check('platform HQ transfer reads are scoped by the selected headquarters location', platformCalls[10].url === '/adminapi/product/inventory/v3/hq/cross-transfer?hq_location_id=41'
  && platformCalls[11].url === '/adminapi/product/inventory/v3/hq/cross-transfer/counterparties?hq_location_id=41'
  && platformCalls[12].url === '/adminapi/product/inventory/v3/hq/cross-transfer/incoming-requests?hq_location_id=41'
  && platformCalls[13].url === '/adminapi/product/inventory/v3/hq/cross-transfer/51/detail?hq_location_id=41')
check('platform HQ transfer commands preserve the headquarters location boundary', platformCalls[14].url === '/adminapi/product/inventory/v3/hq/cross-transfer'
  && JSON.parse(platformCalls[14].options.body).hq_location_id === 41
  && platformCalls[15].url === '/adminapi/product/inventory/v3/hq/cross-transfer/51/dispatch'
  && JSON.parse(platformCalls[15].options.body).hq_location_id === 41
  && platformCalls[16].url === '/adminapi/product/inventory/v3/hq/cross-transfer/51/receive'
  && JSON.parse(platformCalls[16].options.body).hq_location_id === 41
  && platformCalls[17].url === '/adminapi/product/inventory/v3/hq/cross-transfer/52/cancel'
  && JSON.parse(platformCalls[17].options.body).hq_location_id === 41)

await platformApi.searchCatalog({ hq_location_id: 41, keyword: '精华', page: 1, limit: 12 })
await platformApi.listHqInbound({ hq_location_id: 41, keyword: 'RK2608030001' })
check('platform HQ product selector and inbound list stay on the headquarters-only authority', platformCalls[18].url === '/adminapi/product/inventory/v3/hq/catalog?hq_location_id=41&keyword=%E7%B2%BE%E5%8D%8E&page=1&limit=12'
  && platformCalls[19].url === '/adminapi/product/inventory/v3/hq/inbound?hq_location_id=41&keyword=RK2608030001')

await api.findCatalogByBarcode({ barcode: '343437658' })
await api.requestRequester()
await platformApi.findCatalogByBarcode({ hq_location_id: 41, barcode: '343437658' })
await platformApi.hqRequestRequester({ hq_location_id: 41 })
await platformApi.confirmHqCount({ hq_location_id: 41, idempotency_key: 'hq-count-001', business_date: '2026-08-06', remark: '', lines: [] })
await platformApi.hqCountDetail(61, { hq_location_id: 41 })
check('barcode scan uses a dedicated exact SKU authority for both store and headquarters scopes', calls[29].url === '/storeapi/product/inventory/v3/catalog/barcode?barcode=343437658'
  && platformCalls[20].url === '/adminapi/product/inventory/v3/hq/catalog/barcode?hq_location_id=41&barcode=343437658')
check('requester defaults are read from the authenticated scope rather than supplied by the browser', calls[30].url === '/storeapi/product/inventory/v3/request/requester'
  && platformCalls[21].url === '/adminapi/product/inventory/v3/hq/request/requester?hq_location_id=41')
check('platform count confirmation uses a dedicated headquarters command instead of the store session endpoint', platformCalls[22].url === '/adminapi/product/inventory/v3/hq/count/confirm'
  && platformCalls[22].options.method === 'POST'
  && JSON.parse(platformCalls[22].options.body).hq_location_id === 41)
check('platform count detail is read through the selected headquarters authority', platformCalls[23].url === '/adminapi/product/inventory/v3/hq/count/61/detail?hq_location_id=41'
  && platformCalls[23].options.method === 'GET')

const requestEditCalls = []
const requestEditApi = createInventoryApi({
  prefix: '/storeapi/product/inventory',
  fetchImpl: async (url, options) => {
    requestEditCalls.push({ url, options })
    return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: {} }) }
  }
})
await requestEditApi.requestDetail(18)
await requestEditApi.updateRequest(18, { idempotency_key: 'request-edit-001', business_date: '2026-08-03', remark: '', supply_party_type: 'HQ', supply_party_id: 0, lines: [] })
check('request detail and pre-fulfillment edit use the protected V3 request authority', requestEditCalls[0].url === '/storeapi/product/inventory/v3/request/18/detail'
  && requestEditCalls[1].url === '/storeapi/product/inventory/v3/request/18/update'
  && requestEditCalls[1].options.method === 'POST'
  && JSON.parse(requestEditCalls[1].options.body).idempotency_key === 'request-edit-001')

await api.salonUsageProjects({ keyword: '护理', page: 1, limit: 50 })
check('salon usage project selection is read through the current-store scoped V3 endpoint', calls[31].url === '/storeapi/product/inventory/v3/salon-usage/projects?keyword=%E6%8A%A4%E7%90%86&page=1&limit=50'
  && calls[31].options.method === 'GET')

console.log(`INVENTORY_API_RESULT passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
