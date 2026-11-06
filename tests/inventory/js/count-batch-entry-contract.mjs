import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const modal = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
// 普通单据重新选中已有 SKU 时只回填本次明确输入的数量，保留原明细。
const row = { sku_id: 12, quantity: '3', detail_remark: '原备注' }
const selectedRows = { value: [row] }
const acceptRows = new Function('props', 'selectedRows', 'catalogError', 'productSelectorVisible',
  `${modal.slice(modal.indexOf('async function acceptCatalogRows('), modal.indexOf('/** 只下载当前表格行的 Excel'))}\nreturn acceptCatalogRows`
)({ pageKey: 'request' }, selectedRows, { value: '' }, { value: true })
await acceptRows([{ sku_id: 12 }])
assert.equal(row.quantity, '3')
await acceptRows([{ sku_id: 12 }], new Map([[12, '5']]))
assert.deepEqual(selectedRows.value, [{ sku_id: 12, quantity: '5', detail_remark: '原备注' }])
assert.match(modal, /:selected-rows="pageKey === 'count' \? \[\] : selectedRows"/)
assert.match(modal, /@click="importCurrentInventory">导入库存/)
assert.match(modal, /@click="clearCountRows">清空/)
assert.match(modal, /include_count_batches: 1/)
assert.match(modal, /count_mode: 'BATCH', book_quantity: String\(batch.quantity\), counted_quantity: String\(batch.quantity\)/)
assert.match(modal, /function countBatchRow\(sku, batch\)/)
assert.match(modal, /surplus_unit_cost: batch.unit_cost == null \? '' : String\(batch.unit_cost\)/)
assert.match(modal, /replaceCountRows\(additions\)/)
console.log('COUNT_BATCH_ENTRY_CONTRACT_OK')
