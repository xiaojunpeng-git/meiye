import assert from 'node:assert/strict'
import { groupedDetailColumns, groupedDetailKey, groupedDisplayMoney, groupedLineCells } from '../../../前端代码/inventory-vue3/src/groupedDocumentRows.js'

assert.deepEqual(Object.keys(groupedDetailColumns), ['inbound', 'outbound', 'request', 'transfer', 'usage'])
assert.equal(groupedDetailColumns.outbound[7], '成本单价')
for (const columns of Object.values(groupedDetailColumns)) {
  assert.equal(columns.at(-1), '明细备注')
}
assert.equal(groupedDetailKey({ id: 3, source_id: 18 }), '18')
assert.equal(groupedDisplayMoney(1250), '¥13')
assert.equal(groupedDisplayMoney(-1250), '¥-13')
assert.equal(groupedDisplayMoney(null), '-')

const stockLine = {
  product_name: '面膜', sku_name: '默认', batch_no: 'B1', manufactured_date: '2026-01-01',
  expire_date: '2028-01-01', quantity: '2', stock_unit: '盒', unit_cost_cents: 150,
  cost_amount_cents: 300, detail_remark: '仅此明细'
}
for (const page of ['inbound', 'outbound']) {
  const cells = groupedLineCells(page, stockLine, { remark: '单据备注' })
  assert.equal(cells.length, groupedDetailColumns[page].length)
  assert.deepEqual(cells.slice(-3), ['¥2', '¥3', '仅此明细'])
}
const requestCells = groupedLineCells('request', {
  product_name_snapshot: '洗护', sku_name_snapshot: '大瓶', quantity: '5',
  stock_unit_snapshot: '瓶', detail_remark: '请货明细'
}, { document: { remark: '单据备注' } })
assert.deepEqual(requestCells, ['洗护', '大瓶', '5', '瓶', '请货明细'])

const transferCells = groupedLineCells('transfer', {
  product_name_snapshot: '精华', sku_name_snapshot: '默认', requested_quantity_units: 125,
  quantity_scale: 2, stock_unit_snapshot: '瓶', allocations: [{ id: 1 }, { id: 2 }], detail_remark: '调拨明细'
}, { status_name: '在途', remark: '单据备注' })
assert.deepEqual(transferCells, ['精华', '默认', '1.25', '瓶', '2 个批次', '在途', '调拨明细'])

const usageCells = groupedLineCells('usage', {
  product_name: '护理油', sku_name: '', quantity: '3', returned_quantity: '1',
  returnable_quantity: '2', stock_unit: '瓶', detail_remark: '院装明细'
}, { document: { remark: '单据备注' } })
assert.deepEqual(usageCells, ['护理油', '默认规格', '3', '1', '2', '瓶', '院装明细'])

console.log('INVENTORY_GROUPED_DOCUMENT_CONTRACT_OK')
