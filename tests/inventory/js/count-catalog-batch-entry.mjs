import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const source = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
const section = (start, end) => source.slice(source.indexOf(start), source.indexOf(end))
const picker = section('async function acceptCatalogRows(', '/** 只下载当前表格行的 Excel')
const rows = section('function countDraftRow(', '/** 按已授权仓当前批次填草稿')
const loadAll = section('async function loadAllCountProducts(', 'async function importCountRows(')
const selectedRows = { value: [] }, catalogError = { value: '' }, countProgress = { value: '' }, countLoading = { value: false }
const productSelectorVisible = { value: true }
const props = { pageKey: 'count' }
const catalog = [
  { product_id: 1, sku_id: 101, product_name: '双批次商品', available_quantity: '5', count_batches: [
    { batch_no: 'A', quantity: '2', unit_cost: '12.50', manufactured_date: '2026-01-01', expire_date: '2027-01-01' },
    { batch_no: 'B', quantity: '3', unit_cost: '13.00', manufactured_date: '2026-02-01', expire_date: '2027-02-01' }
  ] },
  { product_id: 2, sku_id: 102, product_name: '无库存商品', available_quantity: '0', count_batches: [] }
]
const run = new Function('props', 'selectedRows', 'catalogError', 'countProgress', 'countLoading', 'productSelectorVisible', 'allCountCatalog',
  `${rows}\n${picker}\n${loadAll}\nreturn { acceptCatalogRows, loadAllCountProducts }`)(
  props, selectedRows, catalogError, countProgress, countLoading, productSelectorVisible, async (includeBatches) => {
    assert.equal(includeBatches, true)
    return catalog
  }
)

await run.loadAllCountProducts()
assert.equal(selectedRows.value.length, 3)
assert.deepEqual(selectedRows.value.slice(0, 2).map((row) => [row.count_mode, row.surplus_batch_no, row.book_quantity, row.surplus_unit_cost, row.surplus_manufactured_date, row.surplus_expire_date]), [
  ['BATCH', 'A', '2', '12.50', '2026-01-01', '2027-01-01'],
  ['BATCH', 'B', '3', '13.00', '2026-02-01', '2027-02-01']
])
assert.equal(selectedRows.value[2].surplus_batch_no, '')
selectedRows.value[0].counted_quantity = '1'
await run.loadAllCountProducts()
assert.equal(selectedRows.value.length, 3)
assert.equal(selectedRows.value[0].counted_quantity, '1')

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
console.log('COUNT_CATALOG_BATCH_ENTRY_OK')
