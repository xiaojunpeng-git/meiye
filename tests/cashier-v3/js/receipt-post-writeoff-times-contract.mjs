import assert from 'node:assert/strict'
import { buildSalesOrderReceiptHtml, salesOrderReceiptFromCheckout } from '../../../前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js'

// Receipt projection is pure: the submitted 9-count line must never be mutated.
const line = Object.freeze({ id: 'browser-line', lineRole: 'entitlement_service', name: '东方熏蒸',
  entitlementInstanceType: 'card_holder', entitlementInstanceId: 2077055,
  entitlementSourceDetailId: 4485821, quantity: 1, remainingTimes: 9, availableTimes: 9 })
const checkout = { lines: [line] }
const row = { lineId: 'persisted-line', entitlementInstanceType: 'card_holder',
  entitlementInstanceId: 2077055, entitlementSourceDetailId: 4485821, remainingTimesAfter: 8 }
const submission = { requestStatus: 'succeeded', entitlementCompletion: {
  persistenceStatus: 'persisted', receiptEntitlements: [row]
} }
let passed = 0
function check(name, run) { run(); passed++; console.log(`PASS ${name}`) }
const project = (response = submission, source = checkout) => salesOrderReceiptFromCheckout(source, response)

check('9 before / 1 used prints authoritative 8 after without changing the input', () => {
  const receipt = project()
  assert.equal(receipt.items[0].remainingTimesAfter, 8)
  assert.equal(line.remainingTimes, 9)
  assert.match(buildSalesOrderReceiptHtml(receipt), /东方熏蒸<\/td><td class="right">1<\/td><td class="right">8<\/td>/)
})
check('zero after is preserved', () => {
  const zero = { ...submission, entitlementCompletion: { ...submission.entitlementCompletion,
    receiptEntitlements: [{ ...row, remainingTimesAfter: 0 }] } }
  assert.equal(project(zero).items[0].remainingTimesAfter, 0)
})
check('frozen receipt prints the same value repeatedly', () => {
  assert.deepEqual(project(), project())
  assert.equal(project(submission, { lines: [{ ...line, remainingTimes: 6 }] }).items[0].remainingTimesAfter, 8)
})
check('same project name in a different card cannot borrow the count', () => {
  assert.equal(project(submission, { lines: [{ ...line, entitlementInstanceId: 999 }] }).items[0].remainingTimesAfter, null)
})
check('multiple rows from one physical source share the final count', () => {
  assert.deepEqual(project(submission, { lines: [line, { ...line, id: 'second' }] }).items.map(x => x.remainingTimesAfter), [8, 8])
})
check('result recovery uses the same frozen completion values', () => {
  const queried = { checkoutRequest: { requestStatus: 'succeeded' }, entitlementCompletion: {
    receiptStatus: 'completed', receiptEntitlements: [row]
  } }
  assert.equal(project(queried).items[0].remainingTimesAfter, 8)
})
check('old receipts and failed outcomes never fall back to pre-checkout counts', () => {
  for (const response of [null, {}, { ...submission, requestStatus: 'failed' },
    { requestStatus: 'succeeded', entitlementCompletion: { persistenceStatus: 'persisted' } }]) {
    const receipt = project(response)
    assert.equal(receipt.items[0].remainingTimesAfter, null)
    assert.match(buildSalesOrderReceiptHtml(receipt), /东方熏蒸<\/td><td class="right">1<\/td><td class="right">—<\/td>/)
  }
})
check('negative and malformed counts are not displayed as balances', () => {
  for (const value of [-1, '8', 1.5, null]) {
    assert.equal(project({ ...submission, entitlementCompletion: { ...submission.entitlementCompletion,
      receiptEntitlements: [{ ...row, remainingTimesAfter: value }] } }).items[0].remainingTimesAfter, null)
  }
})
check('explicit after field outranks a legacy before alias', () => {
  assert.match(buildSalesOrderReceiptHtml({ items: [{ ...line, remainingTimesAfter: 8 }] }), /class="right">8<\/td>/)
})
check('sales and payment rows remain unchanged', () => {
  const sale = { lineRole: 'sale', name: 'product', quantity: 2, payableAmount: 100 }
  assert.deepEqual(project(submission, { lines: [sale, line] }).items[0], sale)
})
console.log(`ASSERT_PASSED=${passed}`)
