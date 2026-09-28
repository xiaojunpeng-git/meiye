import assert from 'node:assert/strict'
import { changedCountRows } from '../../../前端代码/inventory-vue3/src/utils/countSubmission.js'

const rows = [
  { sku_id: 1, book_quantity: '0', counted_quantity: '100' },
  { sku_id: 2, book_quantity: '0', counted_quantity: '100' },
  { sku_id: 3, book_quantity: '0', counted_quantity: '0' },
  { sku_id: 4, book_quantity: '8.00', counted_quantity: '8' },
  { sku_id: 5, book_quantity: '7', counted_quantity: '' },
  { sku_id: 6, book_quantity: '7', counted_quantity: '0' },
  { sku_id: 7, book_quantity: '0', counted_quantity: 'invalid' },
]

// 大批量加载只是草稿；确认请求只保留库存有差异的行，不误丢非法非空输入。
assert.deepEqual(changedCountRows(rows).map((row) => row.sku_id), [1, 2, 6, 7])
assert.deepEqual(changedCountRows(rows.slice(2, 5)), [])
console.log('COUNT_SUBMISSION_CONTRACT_OK')
