import assert from 'node:assert/strict'
import { Blob } from 'node:buffer'
import { readFile } from 'node:fs/promises'
import { createInventoryApi } from '../../../前端代码/inventory-vue3/src/services/inventoryApi.js'

// Exercise authenticated binary transport; JSON auth errors must never become spreadsheet files.
let call
const api = createInventoryApi({
  windowRef: { localStorage: { getItem: () => 'local-test-token' } },
  fetchImpl: async (url, options) => {
    call = { url, options }
    return { ok: true, headers: { get: () => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }, blob: async () => new Blob(['PK-test']) }
  }
})
assert.equal((await api.downloadOutboundExport('uqe_test')).size, 7)
assert.equal(call.url, '/storeapi/product/inventory/v3/unified-query/export-task/uqe_test/download')
assert.equal(call.options.headers['Authori-zation'], 'Bearer local-test-token')
assert.equal(call.options.cache, 'no-store')
const denied = createInventoryApi({ windowRef: {}, fetchImpl: async () => ({ ok: true, headers: { get: () => 'application/json' }, text: async () => JSON.stringify({ status: 403, msg: '无权限' }) }) })
await assert.rejects(() => denied.downloadOutboundExport('uqe_test'), /无权限/)
const component = await readFile(new URL('../../../前端代码/inventory-vue3/src/components/InventoryOutboundExport.vue', import.meta.url), 'utf8')
assert.match(component, /frozenQuery\.value = JSON\.parse\(JSON\.stringify\(props\.query\)\)/)
assert.match(component, /scope: 'query', includeSummary: false/)
assert.match(component, /response\?\.result\?\.data/)
assert.match(component, /if \(!blob\?\.size\)/)
assert.match(component, /field.key !== 'location_name'/)
const app = await readFile(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
// Execute the real row mapper with display-only dependencies to protect header alignment and blanks.
const mapRow = new Function('mode', 'text', 'groupedDisplayMoney', 'inventoryStatusLabel', 'operationTime',
  app.slice(app.indexOf('function mapApiRow('), app.indexOf('function isOperationalStatusCell(')) + '; return mapApiRow;')
const display = value => value === undefined || value === null || value === '' ? '-' : String(value)
const sample = { order_sn: 'CK-test', business_date: '2026-10-10', order_type_name: '对内出库', outbound_recipient: '测试部门', store_name: '测试门店', location_name: '默认门店仓' }
const storeMapper = mapRow({ value: 'store' }, display, display, display, display)
assert.deepEqual(storeMapper('outbound', sample).slice(3, 5), ['测试部门', '测试门店'])
assert.equal(storeMapper('outbound', { ...sample, outbound_recipient: '' })[3], '-')
const hqMapper = mapRow({ value: 'platform' }, display, display, display, display)
assert.equal(hqMapper('outbound', sample)[3], '默认门店仓')
assert.equal(hqMapper('outbound', sample).length, 9)
assert.match(app, /\['出库对象', '出库门店'\]/)
assert.match(app, /\['store_name', '出库门店'\]/)
console.log('PASS outbound export: authenticated binary, auth denial, frozen full query, receipt adapter, empty-file guard')
