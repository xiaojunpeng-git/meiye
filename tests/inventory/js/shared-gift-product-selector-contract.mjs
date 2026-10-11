import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { runInNewContext } from 'node:vm'
import { reconcileGiftProducts } from '../../../前端代码/cashier-v3/src/components/member/directGiftProducts.js'
import { CASHIER_V3_ACTION_MANIFEST } from '../../../前端代码/cashier-v3/src/services/cashierV3ActionManifest.js'

// Execute actual component scripts; never submit real gifts or mutate a member during this regression.
const require = createRequire(new URL('../../../前端代码/inventory-vue3/package.json', import.meta.url))
const Vue = require('vue')
const read = (path) => readFileSync(new URL(`../../../${path}`, import.meta.url), 'utf8')
const source = read('前端代码/cashier-v3/src/components/member/DirectGiftOverlay.vue')
const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
const emitted = []
const requests = []
const row = { sku_id: 123, product_id: 20, product_name: 'Warehouse product', sku_name: 'default', stock_unit: 'box', available_quantity: '0' }
const state = runInNewContext(`(() => { ${script}; return { selected, catalogOpen, activeKind, reason, openCatalog, acceptProducts, selectedProducts, productCatalogApi, submit, items } })()`, {
  ...Vue, watch: () => {}, reconcileGiftProducts, defineProps: () => ({ session: { member: { uid: 9 }, store: { id: 8 }, catalogItems: [
    { productType: 0, catalogItemId: 100, name: 'Old product' }, { productType: 6, catalogItemId: 200, name: 'Project' }
  ] } }), defineEmits: () => (...args) => emitted.push(args), requestCashierV3Action: async (action, payload) => {
    requests.push({ action, payload }); return { result: { status: 'success' }, data: { list: [row], total: 1 } }
  }
})
const project = { key: 'catalog:200', kind: 'project', catalogItemId: '200', quantity: 3, validityEnd: '2028-01-01' }
state.selected.value = [project]
state.reason.value = 'TEST only'
state.openCatalog('product')
assert.equal(state.activeKind.value, 'product')
assert.equal(state.catalogOpen.value, true)
assert.equal(state.items.value.filter((item) => item.kind === 'product').length, 0, 'no parallel sales-only product picker')
await state.productCatalogApi.searchCatalog({ keyword: 'Warehouse', page: 2, category_id: 6 })
assert.equal(requests[0].action, 'query-gift-products')
assert.equal(requests[0].payload.preserveRootState, true)
assert.equal(requests[0].payload.page, 2)
state.acceptProducts([row])
state.selected.value[1].quantity = '7'
state.selected.value[1].validityEnd = '2029-01-01'
state.openCatalog('product')
state.acceptProducts([row, row])
assert.equal(state.selected.value.length, 2, 'reconfirm deduplicates SKU rather than increasing gifts')
assert.equal(state.selected.value[1].quantity, '7')
assert.equal(state.selected.value[1].validityEnd, '2029-01-01')
assert.equal(state.selected.value[0].quantity, 3)
assert.equal(state.reason.value, 'TEST only')
state.submit()
assert.equal(emitted[0][0], 'submit')
assert.equal(emitted[0][1].items[1].catalogItemId, '123')
assert.equal(emitted[0][1].items[1].quantity, 7)
state.acceptProducts([])
assert.equal(state.selected.value.length, 1, 'clear product selection preserves projects')
assert.equal(reconcileGiftProducts([{ kind: 'coupon', couponIssueId: '5' }], [row]).length, 2)

const selector = read('前端代码/inventory-vue3/src/components/InventoryProductSelector.vue')
const selectorScript = selector.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
const pending = []
const callbacks = []
const selectorProps = { visible: false, selectedRows: [], catalogQuery: {}, catalogApi: { searchCatalog: () => new Promise((resolve) => pending.push(resolve)) } }
const picker = runInNewContext(`(() => { ${selectorScript}; return { rows, loading, keyword, page, selection, loadCatalog, toggleRow, confirm } })()`, {
  ...Vue, defineProps: () => selectorProps, defineEmits: () => () => {}, watch: (_, callback) => callbacks.push(callback)
})
const first = picker.loadCatalog()
const second = picker.loadCatalog()
pending[1]({ list: [row], total: 1 }); await second
pending[0]({ list: [{ sku_id: 999 }], total: 1 }); await first
assert.equal(picker.rows.value[0].sku_id, 123, 'stale request cannot overwrite latest result')
picker.toggleRow(row); picker.toggleRow({ sku_id: 124 })
assert.equal(picker.selection.value.size, 2, 'selection survives pages')
callbacks[0](false)
assert.match(source, /InventoryProductSelector :visible="catalogOpen && activeKind === 'product'"/)
assert.match(source, /:layer-index="1240" :allow-empty="true"/)
assert.match(selector, /<th>规格<\/th><th>库存单位<\/th><th>库存数量<\/th>/)
const modal = read('前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue')
assert.match(modal, /<InventoryProductSelector/)
const pages = read('前端代码/inventory-vue3/src/App.vue')
for (const name of ['inbound', 'outbound', 'count', 'request', 'transfer', 'usage']) assert.ok(pages.includes(name))
assert.equal(CASHIER_V3_ACTION_MANIFEST['query-gift-products'].type, 'projection')
assert.equal(CASHIER_V3_ACTION_MANIFEST['query-gift-products'].permission, 'cashier.v3.cashier.gift')
console.log('SHARED_GIFT_PRODUCT_SELECTOR_OK: seven entries, gift scope, draft reconciliation, empty confirm, pagination and stale-response isolation')
