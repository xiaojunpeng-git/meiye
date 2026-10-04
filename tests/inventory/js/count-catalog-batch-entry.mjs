import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { sumCountBookQuantities } from '../../../前端代码/inventory-vue3/src/utils/countSubmission.js'

const source = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
const section = (start, end) => source.slice(source.indexOf(start), source.indexOf(end))
const picker = section('async function acceptCatalogRows(', '/** 只下载当前表格行的 Excel')
const rows = section('function countDraftRow(', '/** 替换本地盘点明细')
const batchActions = section('function replaceCountRows(', 'async function importCountRows(')
const selectedRows = { value: [] }, catalogError = { value: '' }, countProgress = { value: '' }, countLoading = { value: false }
const selectedLineIndexes = { value: [] }, countValidationRowIndexes = { value: [] }, countValidationKind = { value: '' }, bulkEditorVisible = { value: false }
const bulkValues = { value: {} }, submitError = { value: '' }, submitting = { value: false }
const productSelectorVisible = { value: true }
const props = { pageKey: 'count' }
const catalog = [
  { product_id: 1, sku_id: 101, product_name: '双批次商品', available_quantity: '5', count_batches: [
    { batch_no: 'A', quantity: '2', unit_cost: '12.50', manufactured_date: '2026-01-01', expire_date: '2027-01-01' },
    { batch_no: 'B', quantity: '3', unit_cost: '13.00', manufactured_date: '2026-02-01', expire_date: '2027-02-01' }
  ] },
  { product_id: 2, sku_id: 102, product_name: '无库存商品', available_quantity: '0', count_batches: [] }
]
const existingStockCatalog = [catalog[0], { product_id: 3, sku_id: 103, product_name: '已删除但有库存', available_quantity: '50', count_batches: [
  { batch_no: 'H', quantity: '50', unit_cost: null, manufactured_date: '2026-01-01', expire_date: '2027-01-01' }
] }]
let catalogFailure = false, releaseCatalog = null
let existingStockQueryCalls = 0, activeCatalogCalls = 0
const run = new Function('props', 'selectedRows', 'catalogError', 'countProgress', 'countLoading', 'productSelectorVisible', 'allCountCatalog',
  'selectedLineIndexes', 'countValidationRowIndexes', 'countValidationKind', 'bulkEditorVisible', 'bulkValues', 'submitError', 'submitting',
  `${rows}\n${picker}\n${batchActions}\nreturn { acceptCatalogRows, loadAllCountProducts, importCurrentInventory, prepareZeroCountInventory, clearCountRows }`)(
  props, selectedRows, catalogError, countProgress, countLoading, productSelectorVisible, async (includeBatches, forExistingStock) => {
    assert.equal(includeBatches, true)
    if (forExistingStock) existingStockQueryCalls++
    else activeCatalogCalls++
    if (releaseCatalog) await new Promise((resolve) => { releaseCatalog = resolve })
    if (catalogFailure) throw new Error('目录读取失败')
    return forExistingStock ? existingStockCatalog : catalog
  }, selectedLineIndexes, countValidationRowIndexes, countValidationKind, bulkEditorVisible, bulkValues, submitError, submitting
)

await run.loadAllCountProducts()
assert.equal(selectedRows.value.length, 3)
assert.deepEqual(selectedRows.value.slice(0, 2).map((row) => [row.count_mode, row.surplus_batch_no, row.book_quantity, row.surplus_unit_cost, row.surplus_manufactured_date, row.surplus_expire_date]), [
  ['BATCH', 'A', '2', '12.50', '2026-01-01', '2027-01-01'],
  ['BATCH', 'B', '3', '13.00', '2026-02-01', '2027-02-01']
])
assert.equal(selectedRows.value[2].surplus_batch_no, '')
selectedRows.value[0].counted_quantity = '1'
selectedLineIndexes.value = [0]
countValidationRowIndexes.value = [0]
bulkEditorVisible.value = true
bulkValues.value = { counted_quantity: '8' }
submitError.value = '旧校验错误'
await run.loadAllCountProducts()
assert.equal(selectedRows.value.length, 3)
assert.equal(selectedRows.value[0].counted_quantity, '2')
assert.deepEqual(selectedLineIndexes.value, [])
assert.deepEqual(countValidationRowIndexes.value, [])
assert.equal(bulkEditorVisible.value, false)
assert.deepEqual(bulkValues.value, {})
assert.equal(submitError.value, '')

selectedRows.value[0].counted_quantity = '9'
catalogFailure = true
await run.loadAllCountProducts()
assert.equal(selectedRows.value[0].counted_quantity, '9')
assert.match(catalogError.value, /目录读取失败/)
catalogFailure = false

selectedRows.value[0].counted_quantity = '8'
releaseCatalog = () => {}
const zeroLoad = run.prepareZeroCountInventory()
assert.deepEqual(selectedRows.value, [])
assert.equal(countLoading.value, true)
releaseCatalog()
releaseCatalog = null
await zeroLoad
assert.equal(existingStockQueryCalls, 1)
assert.equal(selectedRows.value.length, 3)
assert.deepEqual(selectedRows.value.map((row) => [row.count_mode, row.book_quantity, row.counted_quantity, row.surplus_batch_no]), [
  ['BATCH', '2', '0', 'A'], ['BATCH', '3', '0', 'B'], ['BATCH', '50', '0', 'H']
])
assert.match(countProgress.value, /完成盘点前系统库存不变/)
selectedRows.value[0].counted_quantity = '6'
catalogFailure = true
await run.prepareZeroCountInventory()
assert.equal(selectedRows.value[0].counted_quantity, '6')
assert.match(catalogError.value, /目录读取失败/)
catalogFailure = false

await run.importCurrentInventory()
assert.ok(activeCatalogCalls >= 1)
assert.ok(existingStockQueryCalls >= 2)
assert.equal(selectedRows.value.length, 3)
assert.equal(selectedRows.value[0].counted_quantity, '2')
assert.equal(selectedRows.value[2].book_quantity, '50')
assert.equal(sumCountBookQuantities(selectedRows.value), '55')
selectedRows.value[0].counted_quantity = '7'
await run.importCurrentInventory()
assert.equal(selectedRows.value.length, 3)
assert.equal(selectedRows.value[0].counted_quantity, '2')
catalogFailure = true
selectedRows.value[0].counted_quantity = '6'
await run.importCurrentInventory()
assert.equal(selectedRows.value[0].counted_quantity, '6')
catalogFailure = false

run.clearCountRows()
assert.deepEqual(selectedRows.value, [])
assert.match(countProgress.value, /系统库存尚未修改/)

selectedRows.value = []
await run.acceptCatalogRows([catalog[0]])
assert.equal(selectedRows.value.length, 2)
await run.acceptCatalogRows([catalog[0]])
assert.equal(selectedRows.value.length, 2)
assert.equal(selectedRows.value[1].surplus_batch_no, 'B')

selectedRows.value = [{ ...catalog[0], count_mode: 'SKU', counted_quantity: '4' }]
await run.acceptCatalogRows([catalog[0]])
assert.equal(selectedRows.value.length, 1)
assert.match(catalogError.value, /已有按总量盘点的行/)

// 成本不可见时保持空白；前端不得通过其他库存主体或汇总价推算成本。
selectedRows.value = []
await run.acceptCatalogRows([{ ...catalog[0], count_batches: [{ ...catalog[0].count_batches[0], unit_cost: null }] }])
assert.equal(selectedRows.value[0].surplus_unit_cost, '')

selectedRows.value = []
await run.acceptCatalogRows([catalog[1]])
assert.equal(selectedRows.value.length, 1)
assert.equal(selectedRows.value[0].count_mode, 'BATCH')
assert.equal(selectedRows.value[0].counted_quantity, '')
assert.equal(selectedRows.value[0].surplus_batch_no, '')
assert.match(source, /props.pageKey === 'count' \? \{ for_count: 1, include_count_batches: 1 \} : \{\}/)
assert.match(source, /\.\.\.\(props.pageKey === 'count' \? \{ include_count_batches: 1 \} : \{\}\)/)
assert.match(source, /@click="prepareZeroCountInventory">把所有库存改成0<\/button>[\s\S]*@click="clearCountRows">清空下列记录<\/button>/)
assert.match(source, /allCountCatalog\(true, true\)/)
assert.match(source, /forExistingStock && !isPlatformHeadquarters\.value \? \{ for_existing_stock: 1 \} : \{\}/)
assert.doesNotMatch(source, /@click="zeroVisibleCountRows"|function zeroVisibleCountRows\(/)
console.log('COUNT_CATALOG_BATCH_ENTRY_OK')
