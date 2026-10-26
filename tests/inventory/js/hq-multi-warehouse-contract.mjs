import assert from 'node:assert/strict'
import { createDocumentDetailReader } from '../../../前端代码/inventory-vue3/src/documentDetailReader.js'
import { createPlatformInventoryApi } from '../../../前端代码/inventory-vue3/src/services/inventoryApi.js'

// 验证实际传输契约，不读取页面实现文本；仓库身份不得串成门店身份。
const calls = []
const api = createPlatformInventoryApi({
  fetchImpl: async (url, options) => {
    calls.push({ url, options })
    return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: {} }) }
  }
})
await api.createWarehouse({ idempotency_key: 'r14-create-warehouse', base_location_id: 101, location_name: 'Office' })
assert.equal(calls.at(-1).url, '/adminapi/product/inventory/v3/locations')
assert.equal(JSON.parse(calls.at(-1).options.body).base_location_id, 101)
await api.updateWarehouse(102, { location_name: 'Office', location_status: 'INACTIVE', expected_version: 2 })
assert.equal(calls.at(-1).url, '/adminapi/product/inventory/v3/locations/102')
assert.equal(JSON.parse(calls.at(-1).options.body).expected_version, 2)
await api.applyHqRequest({ hq_location_id: 101, request_party_type: 'HQ', request_party_id: 0, supply_party_type: 'HQ', supply_party_id: 0, supply_location_id: 102 })
assert.equal(JSON.parse(calls.at(-1).options.body).supply_location_id, 102)
assert.equal(JSON.parse(calls.at(-1).options.body).supply_party_id, 0)
await api.createHqCrossTransfer({ hq_location_id: 102, source_party_type: 'HQ', source_store_id: 0, target_party_type: 'HQ', target_store_id: 0, target_location_id: 101 })
assert.equal(JSON.parse(calls.at(-1).options.body).target_location_id, 101)
assert.equal(JSON.parse(calls.at(-1).options.body).target_store_id, 0)
await api.unifiedBatchStock({ subject: 'HQ', hq_location_id: 102, page: 2, limit: 20 })
assert.equal(new URL(calls.at(-1).url, 'http://localhost').searchParams.get('hq_location_id'), '102')
await api.bindRequestSupplyWarehouse(200, { hq_location_id: 102 })
assert.equal(calls.at(-1).url, '/adminapi/product/inventory/v3/hq/request/200/bind-supply-warehouse')
await api.list('warehouseSettings', { page: 2, limit: 20, status: 'INACTIVE' })
assert.equal(new URL(calls.at(-1).url, 'http://localhost').pathname, '/adminapi/product/inventory/v3/warehouse-settings')
assert.equal(new URL(calls.at(-1).url, 'http://localhost').searchParams.get('page'), '2')
// 列表切换筛选后，展开旧行仍必须读取单据自己的仓库。
const readDetail = createDocumentDetailReader({ mode: 'platform', hqLocationId: 101, platformApi: api })
await readDetail('inbound', { id: 1, location_id: 102 })
assert.equal(new URL(calls.at(-1).url, 'http://localhost').searchParams.get('hq_location_id'), '102')
await readDetail('outbound', { id: 2, location_id: 103 })
assert.equal(new URL(calls.at(-1).url, 'http://localhost').searchParams.get('hq_location_id'), '103')
await api.issueSalonUsage({ hq_location_id: 102, project_id: 0, lines: [] })
assert.equal(JSON.parse(calls.at(-1).options.body).hq_location_id, 102)
await api.voidSalonUsage(55, { hq_location_id: 102, idempotency_key: 'r14-usage-void' })
assert.equal(JSON.parse(calls.at(-1).options.body).hq_location_id, 102)
await readDetail('usage', { id: 55, store_id: 0, location_id: 102 })
assert.equal(new URL(calls.at(-1).url, 'http://localhost').searchParams.get('hq_location_id'), '102')
await readDetail('usage', { id: 56, store_id: 80, location_id: 91 })
assert.equal(new URL(calls.at(-1).url, 'http://localhost').searchParams.get('store_id'), '80')
console.log('R14_HQ_TRANSPORT_CONTRACT_RESULT passed=18 failed=0')
