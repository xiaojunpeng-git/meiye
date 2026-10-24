import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const source = readFileSync(new URL('../../../前端代码/cashier-v3/src/services/couponAllowance.js', import.meta.url), 'utf8')
const { allowanceAmountCents, validAllowanceCents } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)
const coupon = { couponType: 3, remainingAmountCents: 100000, discountAmountCents: 30000 }
assert.equal(allowanceAmountCents('88', coupon, 30000), 8800)
assert.equal(allowanceAmountCents('200', coupon, 30000), 20000)
for (const input of ['', '0', '-1', '1.2', '88.00', '1e2', '301', 'Infinity', '200000000000']) {
  assert.equal(allowanceAmountCents(input, coupon, 30000), null, input)
}
assert.equal(validAllowanceCents(8850, coupon, 30000), false)
assert.equal(validAllowanceCents(20000, { ...coupon, remainingAmountCents: 8800 }, 30000), false)
assert.equal(validAllowanceCents(20000, coupon, 10000), false)
assert.equal(validAllowanceCents(8800, { ...coupon, couponType: 1 }, 30000), false)
const workbench = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const quantity = workbench.slice(workbench.indexOf('async function changeLineQuantityWithCoupon'), workbench.indexOf('function entitlementLineMaximum'))
assert.ok(quantity.includes('validAllowanceCents(selectedAmountCents, coupon, selector.lineCapacityCents)'))
assert.ok(quantity.includes('discountAmountCents: reusable ? selectedAmountCents'))
assert.ok(workbench.includes('Number(coupon.couponType) !== 3 || !line.cardOperationUpgrade'))
console.log('R48_ALLOWANCE_JS 19 checks passed')
