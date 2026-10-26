import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
const modal = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
const draftService = readFileSync(new URL('../../../后端代码/app/services/product/inventory/InventoryStockCountDraftServices.php', import.meta.url), 'utf8')

// 库存列表与业务编辑器的单位必须紧跟规格，并直接读取权威 stock_unit 字段。
assert.ok(app.includes("columns: ['商品', '规格', '单位', '可用库存', '库存金额']"))
assert.ok(app.includes("[text(row.product_name), text(row.sku_name), text(row.stock_unit), text(row.available_quantity)"))
assert.ok(app.includes('<th>商品</th><th>规格</th><th>单位</th><th>批次</th>'))
assert.ok(app.includes('<th>业务类型</th><th>商品</th><th>规格</th><th>单位</th>'))
// 五类日常单据隐藏条码列；盘点仍需条码核对实体商品。
assert.ok(modal.includes("const product = ['商品ID', '商品名称', '规格', '单位']"))
assert.ok(modal.includes("if (props.pageKey === 'count') return [...product, '商品条码'"))
assert.ok(modal.includes("row.sku_name, row.stock_unit || '-', row.barcode, row.book_quantity"))
assert.ok(modal.includes('<th>商品</th><th>规格</th><th>单位</th><th>条码</th>'))
assert.ok(draftService.includes("'stock_unit' => mb_substr(trim((string)(\$line['stock_unit'] ?? '')), 0, 32)"))
assert.ok(!app.includes("['sku_name', '商品规格'"))

console.log('INVENTORY_UNIT_COLUMN_CONTRACT_OK')
