import assert from 'node:assert/strict'
import { test } from 'node:test'
import { copyHeadquartersLocationId, documentCopyLines } from '../../../前端代码/inventory-vue3/src/documentCopyDraft.js'

test('平台复制门店请货单保留总部读取身份，其他总部单据按原仓读取', () => {
  const hqLocations = [8, 9]
  assert.equal(copyHeadquartersLocationId('request', { request_party_type: 'STORE', location_id: 111 }, 8, hqLocations), 8)
  assert.equal(copyHeadquartersLocationId('request', { request_party_type: 'HQ', location_id: 9 }, 8, hqLocations), 9)
  assert.equal(copyHeadquartersLocationId('transfer', { from_party_type: 'HQ', from_location_id: 9 }, 8, hqLocations), 9)
  assert.equal(copyHeadquartersLocationId('transfer', { from_party_type: 'STORE', from_location_id: 111 }, 8, hqLocations), 8)
})

const line = (quantity = '2') => ({ id: 91, product_id: 11, sku_id: 21, sku_unique: 'sku-21', product_name: '商品', sku_name: '规格', quantity, detail_remark: '备注', batch_no: 'B1', unit_cost_cents: 1234, cost_amount_cents: 2468, stock_unit: '瓶', no_expiry_management: 0 })

test('入库复制只带入新建字段，不带入事实编号与成本总额', () => {
  const rows = documentCopyLines('inbound', { lines: [line()] })
  assert.equal(rows[0].batch_no, 'B1')
  assert.equal(rows[0].unit_cost, '12.34')
  assert.equal(rows[0].quantity, '2')
  for (const field of ['id', 'cost_amount_cents', 'source_id', 'business_date']) assert.equal(field in rows[0], false)
})

test('出库与院装按规格合并旧批次事实，不复制批次或原成本', () => {
  for (const kind of ['outbound', 'usage']) {
    const rows = documentCopyLines(kind, { lines: [line('2'), line('3')] })
    assert.equal(rows.length, 1)
    assert.equal(rows[0].quantity, '5')
    assert.equal('batch_no' in rows[0], false)
  }
})

test('调拨复制原申请量，不带请货关联与批次分配', () => {
  const rows = documentCopyLines('transfer', { lines: [{ ...line(), product_id: 99, sku_id: 98, sku_unique: 'target-sku', from_product_id: 11, from_sku_id: 21, from_sku_unique: 'sku-21', requested_quantity_units: 125, quantity_scale: 2, request_line_id: 9, allocations: [{ id: 3 }] }] })
  assert.deepEqual([rows[0].product_id, rows[0].sku_id, rows[0].sku_unique], [11, 21, 'sku-21'])
  assert.equal(rows[0].quantity, '1.25')
  assert.equal('request_line_id' in rows[0], false)
  assert.equal('allocations' in rows[0], false)
})

test('无明细时阻止复制，单行无效时只略过该行且入库成本仍对应原行', () => {
  assert.throws(() => documentCopyLines('outbound', { lines: [] }), /商品明细/)
  assert.deepEqual(documentCopyLines('outbound', { lines: [{ ...line(), sku_id: 0 }] }), [])
  const rows = documentCopyLines('inbound', { lines: [{ ...line(), sku_id: 0 }, { ...line(), sku_id: 22, sku_unique: 'sku-22', unit_cost_cents: 4500, batch_no: 'B2' }] })
  assert.equal(rows.length, 1)
  assert.deepEqual([rows[0].sku_id, rows[0].unit_cost, rows[0].batch_no], [22, '45', 'B2'])
  for (const kind of ['outbound', 'usage', 'transfer']) {
    const valid = kind === 'transfer' ? { ...line(), from_product_id: 11, from_sku_id: 21, from_sku_unique: 'sku-21', requested_quantity_units: 2 } : line()
    const invalid = kind === 'transfer' ? { ...valid, from_sku_id: 0 } : { ...valid, sku_id: 0 }
    assert.equal(documentCopyLines(kind, { lines: [invalid, valid] }).length, 1, `${kind}须保留有效明细`)
  }
})
