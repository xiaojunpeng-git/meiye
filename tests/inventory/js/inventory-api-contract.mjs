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

await api.confirmTransfer(28)
check('transfer confirmation uses the existing idempotent inventory command endpoint', calls[1].url === '/storeapi/product/inventory/transfer/confirm/28' && calls[1].options.method === 'POST')

await api.batchAnalysis({ query_cutoff_date: '2026-07-29' })
check('batch analysis sends the selected cutoff date to the server authority', calls[2].url === '/storeapi/product/inventory/v3/batch-analysis?query_cutoff_date=2026-07-29')

await api.movementStatistics({ kind: 'outbound', from: '2026-07-01', to: '2026-07-30' })
check('inbound and outbound statistics read the V3 immutable batch-fact summary endpoint', calls[3].url === '/storeapi/product/inventory/v3/movement-statistics?kind=outbound&from=2026-07-01&to=2026-07-30')

await api.unifiedBatchStock({ keyword: '690123', queryCutoffDate: '2026-07-30' })
check('store batch inventory reads from the unified-query provider without client scope fields', calls[4].url === '/storeapi/product/inventory/v3/unified-query/batch-stock?keyword=690123&queryCutoffDate=2026-07-30')

await api.unifiedBatchStock({ filters: [{ field: 'batch_no', operator: 'contains', value: 'B-01' }], sorts: [{ field: 'expire_date', direction: 'asc' }] })
check('inventory UQ sends structured filters and sorting as JSON rather than browser object strings', calls[5].url.includes('filters=%5B%7B%22field%22%3A%22batch_no%22')
  && calls[5].url.includes('sorts=%5B%7B%22field%22%3A%22expire_date%22'))

await api.unifiedQueryCapabilities()
check('inventory UQ capability is loaded through its store-scoped authority endpoint', calls[6].url === '/storeapi/product/inventory/v3/unified-query/capabilities' && calls[6].options.method === 'GET')

await api.unifiedQueryCommand({ action: 'save-unified-query-settings', pageCode: 'inventory_batch_stock', settings: {} })
const uqCommand = JSON.parse(calls[7].options.body)
check('inventory UQ commands use one POST authority endpoint with a generated idempotency key', calls[7].url === '/storeapi/product/inventory/v3/unified-query/commands'
  && calls[7].options.method === 'POST'
  && uqCommand.action === 'save-unified-query-settings'
  && /^inventory-uq-/.test(uqCommand.idempotencyKey))

await api.unifiedQueryExportTask('UQ-INV-001')
check('inventory UQ export polling is limited to the current task number', calls[8].url === '/storeapi/product/inventory/v3/unified-query/export-task/UQ-INV-001')

await api.createInbound({ idempotency_key: 'test-inbound-key', business_date: '2026-07-30', remark: '', lines: [] })
check('manual inbound uses the real product inventory API prefix', calls[9].url === '/storeapi/product/inventory/v3/inbound' && calls[9].options.method === 'POST')

await api.createOutbound({ idempotency_key: 'test-outbound-key', business_date: '2026-07-30', remark: '', lines: [] })
check('manual outbound uses the batch authority API instead of the legacy stock endpoint', calls[10].url === '/storeapi/product/inventory/v3/outbound' && calls[10].options.method === 'POST')

await api.confirmCount({ idempotency_key: 'test-count-key', business_date: '2026-07-30', remark: '', lines: [] })
check('stock count confirmation uses the batch authority API', calls[11].url === '/storeapi/product/inventory/v3/count/confirm' && calls[11].options.method === 'POST')

await api.applyRequest({ idempotency_key: 'test-request-key', business_date: '2026-07-30', remark: '', lines: [] })
check('stock request uses the V3 request authority instead of the legacy draft endpoint', calls[12].url === '/storeapi/product/inventory/v3/request/apply' && calls[12].options.method === 'POST')

await api.cancelRequest(18)
check('stock request cancellation targets the V3 request authority', calls[13].url === '/storeapi/product/inventory/v3/request/cancel/18' && calls[13].options.method === 'POST')

await api.createTransfer({ idempotency_key: 'test-transfer-key', business_date: '2026-07-30', remark: '', target_location_id: 2, lines: [] })
check('batch transfer uses the V3 batch authority instead of the legacy transfer endpoint', calls[14].url === '/storeapi/product/inventory/v3/transfer' && calls[14].options.method === 'POST')

await api.issueSalonUsage({ idempotency_key: 'test-salon-issue', business_date: '2026-07-30', project_id: 1, project_name: '测试项目', remark: '', lines: [] })
check('salon consumable issue uses the V3 batch authority', calls[15].url === '/storeapi/product/inventory/v3/salon-usage/issue' && calls[15].options.method === 'POST')

await api.returnSalonUsage({ idempotency_key: 'test-salon-return', business_date: '2026-07-30', project_id: 1, project_name: '测试项目', remark: '', return_location_id: 2, lines: [] })
check('salon consumable return uses the V3 batch authority', calls[16].url === '/storeapi/product/inventory/v3/salon-usage/return' && calls[16].options.method === 'POST')

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
await platformApi.unifiedBatchStock({ keyword: '测试', warehouseId: 31 })
await platformApi.unifiedQueryCapabilities({ warehouseId: 31 })
await platformApi.unifiedQueryCommand({ action: 'save-unified-query-settings', pageCode: 'inventory_batch_stock', warehouseId: 31, settings: {} })
await platformApi.unifiedQueryExportTask('UQ-PLATFORM-001')
await platformApi.createWarehouse({ idempotency_key: 'warehouse-create-test-001', store_id: 31, location_name: '测试调拨仓' })
check('platform warehouse selector uses the admin V3 scope endpoint', platformCalls[0].url === '/adminapi/product/inventory/v3/locations' && platformCalls[0].options.headers['Authori-zation'] === 'Bearer platform-token')
check('platform batch stock sends only the selected location to the admin V3 scope endpoint', platformCalls[1].url === '/adminapi/product/inventory/v3/batch-stock?location_id=31')
check('platform formula entry reuses the existing headquarters recipe authority', platformCalls[2].url === '/adminapi/product/inventory/recipe/list')
check('platform warehouse settings reuse the server-calculated warehouse list without a client scope', platformCalls[3].url === '/adminapi/product/inventory/v3/locations')
check('platform UQ sends a warehouse narrowing key to the platform authority instead of a client location scope', platformCalls[4].url === '/adminapi/product/inventory/v3/unified-query/batch-stock?keyword=%E6%B5%8B%E8%AF%95&warehouseId=31')
check('platform UQ capability is scoped through the platform endpoint', platformCalls[5].url === '/adminapi/product/inventory/v3/unified-query/capabilities?warehouseId=31')
check('platform UQ write command keeps its generated idempotency key and selected warehouse', platformCalls[6].url === '/adminapi/product/inventory/v3/unified-query/commands' && JSON.parse(platformCalls[6].options.body).warehouseId === 31)
check('platform UQ export polling remains in the platform authority', platformCalls[7].url === '/adminapi/product/inventory/v3/unified-query/export-task/UQ-PLATFORM-001')
check('platform warehouse creation uses its own POST authority with no browser-supplied scope dimensions', platformCalls[8].url === '/adminapi/product/inventory/v3/locations'
  && platformCalls[8].options.method === 'POST'
  && JSON.stringify(JSON.parse(platformCalls[8].options.body)) === JSON.stringify({ idempotency_key: 'warehouse-create-test-001', store_id: 31, location_name: '测试调拨仓' }))

console.log(`INVENTORY_API_RESULT passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
