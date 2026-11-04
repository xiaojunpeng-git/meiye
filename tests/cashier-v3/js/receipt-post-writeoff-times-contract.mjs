import assert from 'node:assert/strict'
import { checkoutReceiptOrderId, readOrderReceipt } from '../../../前端代码/cashier-v3/src/services/orderReceiptQuery.js'
import { buildSalesOrderReceiptHtml } from '../../../前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js'

// 购物车中的28不是打印源；两个入口均直接使用已落账详情的29，不重新提交结账。
const id = 'service:CR-PRINT-44'
const order = Object.freeze({ id, salesOrderNo: 'FW110400001', storeName: '江苏东台吾悦店',
  storeAddress: '门店地址', memberName: '陈彦均', cashierName: '肖先生', businessDate: '2026-11-04',
  items: [{ businessTag: '权益', name: '美肤', quantity: 1, remainingTimesAfter: 29,
    craftsmen: [{ name: '马雨' }] }] })
const response = detail => ({ result: { status: 'success' }, data: { orderCenter: {
  contractVersion: 'cashier-v3.order-center.v3', salesOrderDetail: detail
} } })
let passed = 0
async function check(name, run) { await run(); passed++; console.log('PASS ' + name) }
const submit = { result: { status: 'success' }, data: { checkoutSubmission: {
  requestStatus: 'succeeded', checkoutRequestId: 'CR-PRINT-44', entitlementCompletion: { receiptStatus: 'completed' }
} } }
await check('success and recovery resolve the same service-group identity as all orders', async () => {
  assert.equal(checkoutReceiptOrderId({ status: 'succeeded' }, submit), id)
  const recovered = { result: { status: 'success' }, data: { checkoutResult: {
    checkoutRequest: { requestId: 'CR-PRINT-44', requestStatus: 'succeeded' }, entitlementCompletion: {}
  } } }
  assert.equal(checkoutReceiptOrderId({ status: 'succeeded' }, { data: recovered }), id)
})
await check('ordinary and mixed sales use the committed sale identity', async () => {
  const mixed = { ...submit, data: { checkoutSubmission: {
    ...submit.data.checkoutSubmission, salesOrder: { orderId: 'SO-44' }
  } } }
  assert.equal(checkoutReceiptOrderId({ status: 'succeeded' }, mixed), 'SO-44')
  assert.equal(checkoutReceiptOrderId({ status: 'succeeded', salesOrderId: 'SO-44' }), 'SO-44')
})
await check('both entries print byte-identical documents for all paper sizes without modifying draft', async () => {
  const calls = []
  const request = async (action, payload) => { calls.push({ action, payload }); return response(order) }
  const checkout = { status: 'succeeded', lines: [{ name: '美肤', remainingTimes: 28 }] }
  const before = JSON.stringify(checkout)
  const first = await readOrderReceipt(request, checkoutReceiptOrderId(checkout, submit))
  const second = await readOrderReceipt(request, id)
  assert.deepEqual(calls, [1, 2].map(() => ({ action: 'open-sales-order-detail', payload: { orderId: id } })))
  for (const paperSize of ['58', '76', '80']) {
    const html = buildSalesOrderReceiptHtml(first, { paperSize })
    assert.equal(html, buildSalesOrderReceiptHtml(second, { paperSize }))
    for (const text of ['江苏东台吾悦店', 'FW110400001', '陈彦均', '肖先生', '马雨', '门店地址']) assert(html.includes(text))
    assert.match(html, /美肤<\/td><td class="right">1<\/td><td class="right">29<\/td>/)
  }
  assert.equal(JSON.stringify(checkout), before)
})
await check('zero remaining and original staff come from detail unchanged', async () => {
  const zero = { ...order, items: [{ ...order.items[0], remainingTimesAfter: 0 }] }
  const detail = await readOrderReceipt(async () => ({ data: response(zero) }), id)
  assert.equal(detail, zero)
  assert.match(buildSalesOrderReceiptHtml(detail), /美肤<\/td><td class="right">1<\/td><td class="right">0<\/td>/)
})
await check('permission denial, unknown and mismatched identity never fall back to draft', async () => {
  for (const reply of [response(null), response({ ...order, id: 'another' }),
    { result: { status: 'failed', message: '无权查看' } }, { result: { status: 'result_unknown' } }]) {
    await assert.rejects(readOrderReceipt(async () => reply, id))
  }
  // 即使错误响应带有先前同单投影，仍以此次查询失败为准，不能打印残留详情。
  for (const status of ['failed', 'conflict', 'result_unknown', '']) {
    const stale = { ...response(order), result: { status, message: '本次查询未成功' } }
    await assert.rejects(readOrderReceipt(async () => stale, id), /本次查询未成功/)
    await assert.rejects(readOrderReceipt(async () => ({ data: stale }), id), /本次查询未成功/)
  }
  await assert.rejects(readOrderReceipt(async () => { throw Error('network') }, id), /network/)
})
await check('failure, missing or malformed completion identity issues no query', async () => {
  assert.equal(checkoutReceiptOrderId({ status: 'failed', salesOrderId: 'SO-44' }), '')
  assert.equal(checkoutReceiptOrderId({ status: 'succeeded', lines: [{ name: '美肤' }] }), '')
  assert.equal(checkoutReceiptOrderId({ status: 'succeeded', checkoutRequestId: '../other',
    composition: { hasEntitlement: true } }), '')
  let calls = 0
  await assert.rejects(readOrderReceipt(async () => { calls++; return response(order) }, ''))
  assert.equal(calls, 0)
})
console.log('ASSERT_PASSED=' + passed)
