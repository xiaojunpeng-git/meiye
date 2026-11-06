import assert from 'node:assert/strict'
import { createInventoryApi, createPlatformInventoryApi } from '../../../前端代码/inventory-vue3/src/services/inventoryApi.js'

const xlsxType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
const cases = [
  {
    createApi: createInventoryApi,
    read: (api, payload) => api.unifiedOperationalQuery(payload),
    kind: 'unified',
    payload: {
      pageCode: 'inventory_inbound', page: 2, limit: 20,
      topFilterConditions: [
        { field: 'recorded_by_me', operator: 'eq', value: true },
        { field: 'business_date', operator: 'between', value: ['2026-11-01', '2026-11-06'] },
      ],
    },
  },
  {
    createApi: createPlatformInventoryApi,
    read: (api, payload) => api.unifiedBatchStock(payload),
    kind: 'batch-stock',
    payload: {
      subject: 'STORE', organizationId: 8, page: 3, limit: 100,
      topFilterConditions: [{ field: 'catalog_product_id', operator: 'in', value: [15, 29] }],
    },
  },
  {
    createApi: createPlatformInventoryApi,
    read: (api, payload) => api.listHqCrossTransfers(payload),
    kind: 'hq-transfer',
    payload: { creator_scope: 'all', hq_location_id: 8, from_location_id: 9, status: 'DISPATCHED', keyword: '原查询' },
  },
]

// Export requests must replay the executed query verbatim in both hosts,
// with the format flag as their only difference.
for (const { createApi, read, kind, payload } of cases) {
  const requests = []
  const api = createApi({
    fetchImpl: async (url, options) => {
      requests.push({ url: new URL(url, 'http://localhost'), options })
      return requests.length === 1
        ? Response.json({ status: 200, data: { list: [] } })
        : new Response(Uint8Array.of(80, 75, 3, 4), { headers: { 'Content-Type': xlsxType } })
    },
  })
  await read(api, payload)
  const result = await api.downloadListExport({ kind, payload })
  assert.equal(result.type, xlsxType)
  const [list, exported] = requests
  assert.equal(exported.url.pathname, list.url.pathname)
  assert.equal(exported.url.searchParams.get('list_export'), 'xlsx')
  exported.url.searchParams.delete('list_export')
  assert.equal(exported.url.search, list.url.search)
  assert.equal(exported.options.credentials, list.options.credentials)
}

const expiredSession = createInventoryApi({
  fetchImpl: async () => Response.json({ status: 401, msg: '请登录后重试。' }),
})
await assert.rejects(expiredSession.downloadListExport({ kind: 'unified', payload: {} }), /请登录后重试/)
console.log('PASS inventory export replays store, platform stock and HQ transfer filters and rejects expired sessions')
