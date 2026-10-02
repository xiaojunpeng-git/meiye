import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { parseCountXlsx, matchCountProduct } from '../../../前端代码/inventory-vue3/src/components/countWorksheet.js'

const require = createRequire(new URL('../../../前端代码/inventory-vue3/package.json', import.meta.url))
const ExcelJS = require('exceljs')
const source = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
// 执行真实组件导入方法，模拟只读目录，不调用保存/完成盘点接口或修改真实库存。
const importer = source.slice(source.indexOf('async function importCountRows('), source.indexOf('async function loadSupplyParties('))
const factory = new Function('props', 'countLoading', 'catalogError', 'countProgress', 'selectedRows', 'parseCountXlsx', 'allCountCatalog', 'countDraftRow', 'matchCountProduct', `${importer}; return importCountRows`)
const selectedRows = { value: [{ sku_id: 99, product_id: 99, counted_quantity: '7' }] }
const countLoading = { value: false }, catalogError = { value: '' }, countProgress = { value: '' }
const catalog = [{ product_id: 601720, sku_id: 77, product_name: '系统名称', sku_name: '默认', stock_unit: '瓶', available_quantity: 100, count_batches: [{ batch_no: 'A', quantity: 5 }] }]
const run = factory({ pageKey: 'count' }, countLoading, catalogError, countProgress, selectedRows, parseCountXlsx, async () => catalog, (sku) => ({ ...sku }), matchCountProduct)
async function importRows(rows, batch = false) {
  const workbook = new ExcelJS.Workbook()
  const sheet = workbook.addWorksheet('用户自己的表格')
  sheet.addRow(['标题'])
  sheet.addRow(['商品ID', '实盘数量', '商品名称', '单位', '批号', ...(batch ? ['盘点粒度'] : [])])
  for (const row of rows) sheet.addRow(row)
  const buffer = await workbook.xlsx.writeBuffer()
  const target = { value: 'file', files: [{ name: '自制.xlsx', arrayBuffer: async () => buffer }] }
  await run({ target })
  assert.equal(target.value, '')
  assert.equal(countLoading.value, false)
}
await importRows([[601720, 12, '旧名称', '错误单位']])
assert.equal(catalogError.value, '')
assert.equal(selectedRows.value.length, 2)
assert.equal(selectedRows.value[1].product_name, '系统名称')
assert.equal(selectedRows.value[1].stock_unit, '瓶')
assert.equal(selectedRows.value[1].counted_quantity, '12')
assert.equal(selectedRows.value[1].book_quantity, '100')
await importRows([[601720, 0]])
assert.equal(selectedRows.value.length, 2)
assert.equal(selectedRows.value[1].counted_quantity, '0')
const before = JSON.parse(JSON.stringify(selectedRows.value))
await importRows([[601720, 9], [999999, 4]])
assert.match(catalogError.value, /第 4 行商品 ID 999999/)
assert.deepEqual(selectedRows.value, before)
await importRows([[601720, -1]])
assert.match(catalogError.value, /第 3 行实盘库存数量无效/)
assert.deepEqual(selectedRows.value, before)
await importRows([[601720, 1], [601720, 2]])
assert.match(catalogError.value, /重复/)
assert.deepEqual(selectedRows.value, before)
await importRows([[601720, 4, '', '', 'A', 'BATCH'], [601720, 3, '', '', 'B', 'BATCH']], true)
assert.equal(catalogError.value, '')
assert.equal(selectedRows.value.length, 4)
assert.equal(selectedRows.value[2].book_quantity, '5')
assert.equal(selectedRows.value[3].book_quantity, '0')
assert.doesNotMatch(importer, /countRowKey|catalogByKey|cells\[3\].*stock_unit|cells\[2\]/)
console.log('COUNT_FLEXIBLE_IMPORT_CONTRACT_OK')
