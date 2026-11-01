import assert from 'node:assert/strict'
import { test } from 'node:test'
import { availableRequestCopySelection, canReopenRequestForScope, requestReopenDraft } from '../../../前端代码/inventory-vue3/src/requestReopenDraft.js'

test('不可选的原请货方和供货方留空，不自动替换为其他主体', () => {
  const options = [{ key: 'HQ:8' }, { key: 'STORE:23' }]
  assert.equal(availableRequestCopySelection('HQ:5', options), '')
  assert.equal(availableRequestCopySelection('STORE:23', options), 'STORE:23')
})

const source = () => ({
  document: {
    id: 99, request_no: 'QH99', document_status: 'VOIDED', business_date: '2026-10-01',
    request_party_type: 'STORE', request_party_id: 23, supply_party_type: 'HQ',
    supply_location_id: 5, requester_name_snapshot: '原请货人', remark: '请补货'
  },
  lines: [{ id: 8, product_id: 12, sku_id: 13, sku_unique: 'sku-13', quantity: '2.5',
    product_name_snapshot: '产品甲', sku_name_snapshot: '大规格', detail_remark: '原明细备注',
    fulfilled_transfer_id: 42, reference_unit_cost_cents: 8800 }]
})

test('作废单只复制业务初值，新单不继承原单身份、日期、人员和履约信息', () => {
  const original = source()
  const draft = requestReopenDraft(original)
  assert.equal(draft.requestParty, 'STORE:23')
  assert.equal(draft.supplyParty, 'HQ:5')
  assert.equal(draft.remark, '请补货')
  assert.deepEqual(draft.lines.map(({ product_id, sku_id, sku_unique, quantity, detail_remark }) =>
    ({ product_id, sku_id, sku_unique, quantity, detail_remark })),
  [{ product_id: 12, sku_id: 13, sku_unique: 'sku-13', quantity: '2.5', detail_remark: '原明细备注' }])
  for (const field of ['id', 'request_no', 'business_date', 'requester_name_snapshot', 'fulfilled_transfer_id', 'reference_unit_cost_cents']) {
    assert.equal(Object.hasOwn(draft, field), false)
    assert.equal(Object.hasOwn(draft.lines[0], field), false)
  }
  draft.lines[0].quantity = '4'
  assert.equal(original.lines[0].quantity, '2.5')
})

test('正常单也可复制，失效商品不回填但其他有效明细保留', () => {
  const normal = source()
  normal.document.document_status = 'APPLIED'
  assert.equal(requestReopenDraft(normal).lines[0].quantity, '2.5')
  const invalid = source()
  invalid.lines[0].sku_id = 0
  invalid.lines.push({ ...source().lines[0], id: 9, product_name_snapshot: '仍有效商品' })
  const copied = requestReopenDraft(invalid)
  assert.equal(copied.lines.length, 1)
  assert.equal(copied.lines[0].product_name, '仍有效商品')
  assert.equal(requestReopenDraft({ ...source(), lines: [] }).lines.length, 0)
})

test('总部请货和门店供货沿用原主体，不继承旧履约字段', () => {
  const original = source()
  Object.assign(original.document, { request_party_type: 'HQ', request_party_id: 0, location_id: 7,
    supply_party_type: 'STORE', supply_party_id: 23, supply_location_id: 9 })
  const draft = requestReopenDraft(original)
  assert.equal(draft.requestParty, 'HQ:7')
  assert.equal(draft.supplyParty, 'STORE:23')
  assert.equal('fulfilled_transfer_id' in draft.lines[0], false)
})

test('门店只可复制本店请货仓单据，供货给他店的单据不能复制', () => {
  const locations = [{ id: 301, is_default: 1 }]
  assert.equal(canReopenRequestForScope({ id: 1, status_name: '已作废', location_id: 301 }, 'store', locations), true)
  assert.equal(canReopenRequestForScope({ id: 2, status_name: '已作废', location_id: 302 }, 'store', locations), false)
  assert.equal(canReopenRequestForScope({ id: 3, status_name: '申请中', location_id: 301 }, 'store', locations), true)
  assert.equal(canReopenRequestForScope({ id: 4, status_name: '已作废', location_id: 302 }, 'platform', locations), true)
})
