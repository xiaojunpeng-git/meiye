import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createDocumentDetailReader } from '../../../前端代码/inventory-vue3/src/documentDetailReader.js'

// A shared layout must not become a shared credential: both APIs retain their
// authority, and rejected reads never retry through the other terminal.
const calls = []
const api = prefix => new Proxy({}, { get: (_, name) => async (...args) => {
  calls.push({ prefix, name, args })
  return { lines: [{ id: 1 }] }
} })
const storeApi = api('store')
const platformApi = api('platform')
const pages = ['inbound', 'outbound', 'request', 'transfer', 'usage']
for (const mode of ['store', 'platform']) {
  const read = createDocumentDetailReader({ mode, hqLocationId: 7, storeId: 80, storeApi, platformApi })
  for (const page of pages) {
    assert.deepEqual(await read(page, { id: 3, source_id: 'manual-18' }), { lines: [{ id: 1 }] })
    const call = calls.at(-1)
    assert.equal(call.prefix, mode)
    assert.equal(call.args[0], ['inbound', 'outbound'].includes(page) ? 'manual-18' : 3)
    if (mode === 'store') assert.equal(call.args.length, 1)
    else assert.deepEqual(call.args[1], page === 'usage' ? { store_id: 80 } : { hq_location_id: 7 })
  }
  assert.throws(() => read('warehouse', { id: 1 }), /不支持/)
}
assert.throws(() => createDocumentDetailReader({ mode: 'unknown' }), /不合法/)
const denied = createDocumentDetailReader({ mode: 'platform', hqLocationId: 7, storeApi,
  platformApi: { hqInboundDetail: async () => { throw new Error('forbidden') } } })
const previousCalls = calls.length
await assert.rejects(denied('inbound', { id: 1 }), /forbidden/)
assert.equal(calls.length, previousCalls)
const storeWarehouse = createDocumentDetailReader({ mode: 'platform', warehouseSelection: 'STORE:80', hqLocationId: 7, storeId: 179, storeApi, platformApi })
for (const page of ['inbound', 'outbound', 'inboundTrace']) {
  await storeWarehouse(page, { source_id: 'store-document', location_id: 91 })
  assert.equal(calls.at(-1).prefix, 'platform')
  assert.deepEqual(calls.at(-1).args, ['store-document', { subject: 'STORE', storeId: 80, locationId: 91 }])
}

const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
// The same five pages must use one renderer and one async detail pipeline.
assert.match(app, /const groupedPage = groupedListPages\.has\(active\.value\) \? active\.value : ''/)
assert.doesNotMatch(app, /mode === 'store' && groupedListPages\.has|mode\.value === 'store' && groupedListPages\.has/)
assert.equal((app.match(/class="inventory-grouped-table"/g) || []).length, 1)
assert.match(app, /<table v-if="!groupedListPages\.has\(active\)"/)
assert.match(app, /const readDetail = currentDocumentDetailReader\(\)/)
assert.match(app, /Math\.min\(4, pending\.length\)/)
assert.match(app, /version === groupedDetailVersion/)
assert.match(app, /groupedRequest !== groupedListVersion/)
const grouped = app.slice(app.indexOf('<table v-else class="inventory-grouped-table"'))
for (const action of ['can_edit', 'can_delete', 'can_dispatch', 'can_receive', 'can_void', 'can_transfer']) assert.ok(grouped.includes(action))
const flat = app.slice(app.indexOf('<table v-if="!groupedListPages.has(active)"'), app.indexOf('<table v-else class="inventory-grouped-table"'))
assert.doesNotMatch(flat, /openTransferDetail|openRequestDetail|openInboundDetail|openUsageDetail|openOutboundDetail/)
console.log('SHARED_DOCUMENT_LAYOUT_CONTRACT_OK: both terminals, 5 pages, capability actions, no credential fallback, single renderer')
