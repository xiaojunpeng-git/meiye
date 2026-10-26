import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { runInNewContext } from 'node:vm'
import { validateInboundDates } from '../../../前端代码/inventory-vue3/src/inboundDateValidation.js'
import { validateCountSubmissionRows } from '../../../前端代码/inventory-vue3/src/utils/countSubmission.js'

const require = createRequire(new URL('../../../前端代码/inventory-vue3/package.json', import.meta.url))
const Vue = require('vue')
const { compile } = require('@vue/compiler-dom')
const { renderToString } = require('@vue/server-renderer')
const modal = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
const selector = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryProductSelector.vue', import.meta.url), 'utf8')
const template = modal.match(/<div class="modal-table-scroll"><table><thead><tr><th><input type="checkbox"[\s\S]*?<\/table><\/div>/)?.[0]
assert.ok(template)
const render = runInNewContext(`(function () { ${compile(template, { mode: 'function', prefixIdentifiers: true }).code} })()`, { Vue })
const sku = { product_id: 1, sku_id: 10, sku_unique: 'test1234', product_name: 'TEST-product', sku_name: 'default', stock_unit: 'box',
  available_quantity: '12.5', no_expiry_management: 1, count_batches: [{ batch_no: 'internal-a', quantity: '4.5' }, { batch_no: 'internal-b', quantity: '8' }] }
const script = modal.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
function editor(pageKey, mode = 'store') {
  const captured = []
  const api = { searchCatalog: async () => ({ list: [sku], total: 1 }),
    createInbound: async (payload) => captured.push(payload), createHqInbound: async (payload) => captured.push(payload) }
  const props = { pageKey, mode, modalKind: 'create', detail: null, warehouseOptions: [], defaultBusinessDate: '2026-10-11', hqLocationId: 1 }
  const state = runInNewContext(`(function () { ${script}\nreturn { selectedRows, columns, productRows, acceptCatalogRows, loadAllCountProducts, loadCurrentInventoryCountRows,
    selectedLineIndexes, bulkFields, bulkValues, applyBulkValues, submitInbound, countDraftContent, allLinesSelected, countValidationRowIndexes, updateInboundDate, removeCatalogRow, toggleAllLines } })()`, {
    ...Vue, watch: () => {}, defineProps: () => props, defineEmits: () => () => {}, inventoryApi: api, platformInventoryApi: api,
    validateInboundDates, Intl, crypto: { randomUUID: () => 'test-uuid-1234' }
  })
  return { state, props, captured }
}
for (const mode of ['store', 'platform']) {
  for (const pageKey of ['outbound', 'usage']) {
    const { state } = editor(pageKey, mode)
    await state.acceptCatalogRows([sku])
    assert.equal(state.columns.value[4], '库存数量')
    assert.equal(state.productRows.value[0][4], '12.5')
    const html = await renderToString(Vue.createSSRApp({ render, setup: () => ({ ...state, pageKey }) }))
    assert.ok(html.includes('<th>单位</th><th>库存数量</th>'))
    assert.ok(html.includes('12.5'))
    assert.equal((html.match(/class="table-input"/g) || []).length, 1, 'quantity remains editable at the shifted column')
  }
  const { state, captured } = editor('inbound', mode)
  await state.acceptCatalogRows([sku, { ...sku, product_id: 2, sku_id: 20, no_expiry_management: 0 }])
  let html = await renderToString(Vue.createSSRApp({ render, setup: () => ({ ...state, pageKey: 'inbound' }) }))
  assert.equal((html.match(/type="date"/g) || []).length, 2, 'only managed product retains date inputs in a mixed document')
  state.selectedRows.value.splice(1)
  state.selectedRows.value[0].manufactured_date = 'stale-value'
  await state.submitInbound()
  assert.equal(captured.length, 1)
  assert.equal(captured[0].lines[0].batch_no, '')
  assert.equal(captured[0].lines[0].manufactured_date, '')
  assert.equal(captured[0].lines[0].expire_date, '')
  const count = editor('count', mode).state
  await count.acceptCatalogRows([sku])
  assert.equal(count.selectedRows.value.length, 1)
  assert.equal(count.selectedRows.value[0].count_mode, 'SKU')
  assert.equal(count.selectedRows.value[0].book_quantity, '12.5')
  await count.loadAllCountProducts()
  assert.equal(count.selectedRows.value.length, 1, 'all products does not expand internal cost layers for exempt SKU')
  await count.loadCurrentInventoryCountRows(true)
  assert.equal(count.selectedRows.value[0].counted_quantity, '0')
  assert.equal(count.selectedRows.value[0].book_quantity, '12.5')
  html = await renderToString(Vue.createSSRApp({ render, setup: () => ({ ...count, pageKey: 'count' }) }))
  assert.equal((html.match(/type="date"/g) || []).length, 0)
  sku.count_requires_batch_identity = true
  await count.loadCurrentInventoryCountRows(true)
  assert.equal(count.selectedRows.value.length, 2, 'retired catalog keeps existing cost-layer identities')
  assert.equal(count.selectedRows.value[0].count_mode, 'BATCH')
  html = await renderToString(Vue.createSSRApp({ render, setup: () => ({ ...count, pageKey: 'count' }) }))
  assert.equal((html.match(/type="date"/g) || []).length, 0, 'preserving real batch identity does not require date entry')
  delete sku.count_requires_batch_identity
}
assert.ok(selector.includes('<th>规格</th><th>库存单位</th><th>库存数量</th>'))
assert.ok(!selector.includes('<th>商品编码</th>') && !selector.includes('<th>条码</th>'))
assert.ok(selector.includes("row.available_quantity ?? '-'"))
assert.equal(validateInboundDates([{ no_expiry_management: 1 }]).valid, true)
assert.equal(validateInboundDates([{ no_expiry_management: 0 }]).valid, false)
const gain = { ...sku, counted_quantity: '15', book_quantity: '12.5', surplus_unit_cost: '2.50' }
assert.equal(validateCountSubmissionRows([gain]).valid, true)
assert.equal(validateCountSubmissionRows([{ ...gain, no_expiry_management: 0 }]).valid, false)
assert.equal(validateCountSubmissionRows([{ ...gain, surplus_unit_cost: '' }]).valid, false)

// Execute the actual Vue2 serializer so a working switch cannot silently disappear from the save payload.
const admin = readFileSync(new URL('../../../前端代码/admin/src/pages/product/productAdd/index.vue', import.meta.url), 'utf8')
const summarize = admin.match(/summarizeData\(\) \{([\s\S]*?)\n    \},\n    validateCardRuleSettings/)[1]
const serialize = runInNewContext(`(function () { ${summarize} })`)
const context = { $refs: { productBaseSet: { formValidate: { store_label_id: [] } }, marketingSet: { formValidate: { label_id: [], recommend_list: [] } },
  otherSet: { formValidate: {} }, cardFaceSet: { getFormData: () => ({}) } }, $route: { query: {} },
  formData: { product_type: 0, no_expiry_management: 1 }, manyFormValidate: [], attrs: [], $set: (target, key, value) => { target[key] = value }, genCreateRequestKey: () => 'test' }
assert.equal(serialize.call(context).no_expiry_management, 1)
context.formData.no_expiry_management = 0
assert.equal(serialize.call(context).no_expiry_management, 0)
console.log('NO_EXPIRY_SHARED_EDITOR_OK: actual Vue2 save, dual-mode rendered columns, mixed dates, count loading, cost validation and submit payload')
