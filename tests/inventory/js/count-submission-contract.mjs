import assert from 'node:assert/strict'
import {
  countedRows,
  invalidCountCatalogMessage,
  invalidCountCatalogRowIndexes,
  missingCountSurplusMessage,
  reindexCountValidationRowsAfterRemoval,
  submittedCountRowIndex,
  validateCountSubmissionRows,
} from '../../../前端代码/inventory-vue3/src/utils/countSubmission.js'

const completeBatch = { surplus_batch_no: '000', surplus_unit_cost: '0', surplus_manufactured_date: '2026-10-01', surplus_expire_date: '2028-10-01' }
const rows = [
  { sku_id: 1, book_quantity: '0', counted_quantity: '100', ...completeBatch },
  { sku_id: 2, book_quantity: '0', counted_quantity: '100', ...completeBatch },
  { sku_id: 3, book_quantity: '0', counted_quantity: '0' },
  { sku_id: 4, book_quantity: '8.00', counted_quantity: '8', ...completeBatch },
  { sku_id: 5, book_quantity: '7', counted_quantity: '' },
  { sku_id: 6, book_quantity: '7', counted_quantity: '0' },
  { sku_id: 7, book_quantity: '0', counted_quantity: 'invalid' },
]

// 等于旧账面的实盘也必须提交，空白未盘不提交；非法非空输入必须留给校验。
assert.deepEqual(countedRows(rows).map((row) => row.sku_id), [1, 2, 3, 4, 6, 7])
assert.deepEqual(countedRows(rows.slice(2, 5)).map((row) => row.sku_id), [3, 4])
assert.equal(validateCountSubmissionRows(rows).rowIndex, 6)
assert.equal(validateCountSubmissionRows(rows.slice(0, 6)).valid, true)
// An earlier uncounted draft row does not occupy a position in the server's submitted lines.
const staleDraftRows = [
  { product_id: 1, product_name: '未盘商品', counted_quantity: '' },
  { product_id: 2, product_name: '正常商品', counted_quantity: '0' },
  { product_id: 3, product_name: '待补批次商品', book_quantity: '2', counted_quantity: '2' },
]
assert.equal(submittedCountRowIndex(staleDraftRows, 2), 2)
assert.equal(submittedCountRowIndex(staleDraftRows, 0), -1)
assert.equal(submittedCountRowIndex(staleDraftRows, 3), -1)
assert.equal(missingCountSurplusMessage(staleDraftRows, 2), '第 3 行「待补批次商品」的盘盈批次资料不完整，请核对批号、单价、生产日期和到期日后再完成盘点。')
// 新批次行可重复选择商品，但正式提交必须填写不同批号。
const batchRows = [{ sku_id: 1, product_name: '精华', count_mode: 'BATCH', surplus_batch_no: 'A', counted_quantity: '0' },
  { sku_id: 1, product_name: '精华', count_mode: 'BATCH', surplus_batch_no: 'B', counted_quantity: '2' }]
assert.equal(validateCountSubmissionRows(batchRows).valid, true)
assert.equal(validateCountSubmissionRows([...batchRows, { ...batchRows[0] }]).rowIndex, 2)
assert.equal(validateCountSubmissionRows([{ ...batchRows[0], surplus_batch_no: '' }]).rowIndex, 0)

// 正数总量行不按旧账面区分盈亏；零实盘免填，批号 000 和零单价均有效。
const validationRows = [
  { product_id: 1, product_name: '无差异', book_quantity: '2', counted_quantity: '2' },
  { product_id: 2, product_name: '盘亏商品', book_quantity: '2', counted_quantity: '1', ...completeBatch },
  { product_id: 3, product_name: '爱尔菲娜防晒霜', book_quantity: '0', counted_quantity: '3', surplus_batch_no: '31', surplus_unit_cost: '228', surplus_manufactured_date: '2025-04-27', surplus_expire_date: '' },
]
assert.deepEqual(validateCountSubmissionRows(validationRows), {
  valid: false,
  rowIndex: 0,
  message: '第 1 行「无差异」未填写批次号、单价、生产日期、到期日，暂时无法完成盘点。请补充后再试。',
})
assert.deepEqual(validateCountSubmissionRows([{ ...validationRows[0], ...completeBatch }, validationRows[1], validationRows[2]]), {
  valid: false,
  rowIndex: 2,
  message: '第 3 行「爱尔菲娜防晒霜」未填写到期日，暂时无法完成盘点。请补充后再试。',
})
assert.equal(validateCountSubmissionRows([{ ...validationRows[0], ...completeBatch }, validationRows[1], { ...validationRows[2], surplus_expire_date: '2028-04-28' }]).valid, true)
assert.equal(validateCountSubmissionRows([{ product_id: 4, product_name: '零实盘', book_quantity: '10', counted_quantity: '0' }]).valid, true)
assert.equal(validateCountSubmissionRows([{ product_id: 5, product_name: '未盘', book_quantity: '10', counted_quantity: '' }]).valid, true)
assert.equal(validateCountSubmissionRows([{ product_id: 6, product_name: '旧账面较高', book_quantity: '10', counted_quantity: '1' }]).rowIndex, 0)

// 服务端一次返回所有失效目录身份；前端必须按草稿行序定位、显示名称并高亮全部问题行。
const catalogRows = [
  { product_id: 601746, sku_id: 583692, sku_unique: '480f9808', product_name: '芦荟保湿洗面奶' },
  { product_id: 601838, sku_id: 583781, sku_unique: 'valid', product_name: '甘菊滋养水' },
  { product_id: 200737, sku_id: 199035, sku_unique: '64190303', product_name: '菁纯霜单支' },
]
const invalidCatalogIndexes = invalidCountCatalogRowIndexes(catalogRows, [
  { product_id: 200737, sku_id: 199035, sku_unique: '64190303' },
  { product_id: 601746, sku_id: 583692, sku_unique: '480f9808' },
])
assert.deepEqual(invalidCatalogIndexes, [0, 2])
assert.equal(
  invalidCountCatalogMessage(catalogRows, invalidCatalogIndexes),
  '第 1 行「芦荟保湿洗面奶」、第 3 行「菁纯霜单支」已从当前门店商品目录删除，暂时无法完成盘点。请先恢复商品，或删除这些盘点行后再提交。',
)

// 删除问题行后必须移除对应高亮；删除前置普通行时，后续问题行索引只前移不能串到相邻商品。
assert.deepEqual(reindexCountValidationRowsAfterRemoval([0, 2], 0), [1])
assert.deepEqual(reindexCountValidationRowsAfterRemoval([2], 2), [])
assert.deepEqual(reindexCountValidationRowsAfterRemoval([1, 3], 0), [0, 2])
console.log('COUNT_SUBMISSION_CONTRACT_OK')
