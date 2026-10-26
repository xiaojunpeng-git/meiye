import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

// 只改变盘点入口：重复选择仅新增本次商品，入库/请货等其他选择逻辑不扩散。
const modal = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
assert.match(modal, /selectedSkuIds\.has\(Number\(row.sku_id\)\) && props.pageKey !== 'count'/)
assert.match(modal, /:selected-rows="pageKey === 'count' \? \[\] : selectedRows"/)
assert.match(modal, /@click="importCurrentInventory">导入库存/)
assert.match(modal, /@click="clearCountRows">清空/)
assert.match(modal, /include_count_batches: 1/)
assert.match(modal, /count_mode: 'BATCH', book_quantity: String\(batch.quantity\), counted_quantity: String\(batch.quantity\)/)
assert.match(modal, /function countBatchRow\(sku, batch\)/)
assert.match(modal, /surplus_unit_cost: batch.unit_cost == null \? '' : String\(batch.unit_cost\)/)
assert.match(modal, /replaceCountRows\(additions\)/)
console.log('COUNT_BATCH_ENTRY_CONTRACT_OK')
