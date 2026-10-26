import assert from 'node:assert/strict'
import fs from 'node:fs'
import { runInNewContext } from 'node:vm'

const root = new URL('../../../', import.meta.url)
const rechargeSource = fs.readFileSync(new URL('前端代码/cashier-v3/src/composables/useRechargeCheckout.js', root), 'utf8')
const overlaySource = fs.readFileSync(new URL('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue', root), 'utf8')
const requests = []
const runtimeSource = rechargeSource
  .replace(/^import .*\n/gm, '')
  .replace('export function useRechargeCheckout()', 'function useRechargeCheckout()')
  + '\nuseRechargeCheckout'
const useRechargeCheckout = runInNewContext(runtimeSource, {
  ref: (value) => ({ value }),
  createCashierV3CommandId: () => 'test-checkout-id',
  requestCashierV3Action: async (action, payload) => {
    requests.push({ action, payload })
    return { result: { status: 'success' } }
  },
  cashierV3ResponseEnvelope: (response) => response,
  window: { dispatchEvent: () => {} },
  CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail } }
})

// Zero-amount methods are editable UI placeholders, never collection facts.
assert.doesNotMatch(overlaySource, /请为每种已选择的收款方式填写大于 0 的金额/)
assert.match(overlaySource, /isRechargeCheckout\.value\s*\?\s*\/\^\(0\|\[1-9\]\\d\*\)\$\//)
assert.match(overlaySource, /const delta = receivable - selected/)

const zeroCheckout = useRechargeCheckout()
zeroCheckout.acceptLocalRechargeCheckout({
  memberId: 1, rechargeMode: 'custom', principalAmount: 0, bonusAmount: 100,
  debtAmount: 0, rechargeRemark: 'test bonus grant'
}, { member: { id: 1 } })
const added = await zeroCheckout.requestRechargeCheckoutAction({
  action: 'add-payment-method', payload: { paymentMethodId: 'cash' }
})
assert.equal(added.result.status, 'success')
assert.equal(zeroCheckout.rechargeCheckout.value.payment.selectedLines[0].amount, 0)
const zeroLineId = zeroCheckout.rechargeCheckout.value.payment.selectedLines[0].id
const edited = await zeroCheckout.requestRechargeCheckoutAction({
  action: 'update-payment-line', payload: { paymentLineId: zeroLineId, amount: '0' }
})
assert.equal(edited.result.status, 'success')
await zeroCheckout.requestRechargeCheckoutAction({ action: 'submit-checkout' })
assert.equal(requests[0].action, 'submit-recharge-checkout')
assert.deepEqual(Array.from(requests[0].payload.checkoutSnapshot.paymentLines), [])
assert.equal(zeroCheckout.rechargeCheckout.value.payment.selectedLines[0].amount, 0)

const paidCheckout = useRechargeCheckout()
paidCheckout.acceptLocalRechargeCheckout({
  memberId: 1, rechargeMode: 'custom', principalAmount: 100, bonusAmount: 0,
  debtAmount: 0
}, { member: { id: 1 } })
await paidCheckout.requestRechargeCheckoutAction({
  action: 'add-payment-method', payload: { paymentMethodId: 'cash' }
})
assert.equal(paidCheckout.rechargeCheckout.value.payment.selectedLines[1].amount, 0)
const paidLineId = paidCheckout.rechargeCheckout.value.payment.selectedLines[0].id
const rejected = await paidCheckout.requestRechargeCheckoutAction({
  action: 'update-payment-line', payload: { paymentLineId: paidLineId, amount: '-1' }
})
assert.equal(rejected.result.status, 'failed')
await paidCheckout.requestRechargeCheckoutAction({ action: 'submit-checkout' })
assert.deepEqual(Array.from(requests[1].payload.checkoutSnapshot.paymentLines, (line) => ({
  paymentMethod: line.paymentMethod, amount: line.amount
})), [{ paymentMethod: 'unionpay', amount: '100' }])

console.log('RECHARGE_ZERO_PAYMENT_LINE_CONTRACT passed=12 failed=0')
