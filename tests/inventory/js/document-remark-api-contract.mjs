import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createInventoryApi, createPlatformInventoryApi } from '../../../前端代码/inventory-vue3/src/services/inventoryApi.js'

// One transport shape for both hosts; the server, not the browser, decides writable scope.
for (const [host, factory] of [['store', createInventoryApi], ['platform', createPlatformInventoryApi]]) {
  const calls = []
  const api = factory({
    windowRef: { localStorage: { getItem: () => `${host}-token` } },
    fetchImpl: async (url, options) => {
      calls.push({ url, options })
      return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: { remark_version: 2 } }) }
    }
  })
  const prefix = host === 'store' ? '/storeapi/product/inventory' : '/adminapi/product/inventory'
  const scope = host === 'store' ? {} : { hq_location_id: 142 }
  await api.documentRemarkDetail('inbound', 'inbound-1', scope)
  assert.equal(calls[0].url, `${prefix}/v3/document-remarks/inbound-1?${host === 'store' ? '' : 'hq_location_id=142&'}type=inbound`)
  assert.equal(calls[0].options.headers['Authori-zation'], `Bearer ${host}-token`)
  const body = { type: 'inbound', version: 2, remark: 'new', lines: [{ line_id: 3, detail_remark: 'line' }] }
  await api.saveDocumentRemark('inbound-1', body, scope)
  assert.equal(calls[1].url, calls[0].url)
  assert.equal(calls[1].options.method, 'POST')
  assert.deepEqual(JSON.parse(calls[1].options.body), body)
  await api.documentRemarkDetail('inbound', 'import:2026_10.13-01', scope)
  assert.ok(calls[2].url.includes('/v3/document-remarks/import%3A2026_10.13-01?'),
    'manual voucher idempotency keys must be encoded without losing supported punctuation')
}
// The store session middleware injects store_id into POST; only that trusted field may be removed.
const storeController = readFileSync(new URL('../../../后端代码/app/controller/store/product/inventory/InventoryDocumentRemark.php', import.meta.url), 'utf8')
assert.match(storeController, /forceStoreSessionInjectedPostStoreId/)
assert.match(storeController, /unset\(\$input\['store_id'\]\)/)
console.log('DOCUMENT_REMARK_API_CONTRACT_OK')
