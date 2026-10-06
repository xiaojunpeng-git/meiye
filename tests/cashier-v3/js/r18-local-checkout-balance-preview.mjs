import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { checkoutBalancePreview } from '../../../前端代码/cashier-v3/src/services/cashierV3CheckoutBalancePreview.js'

const member = (id, accountBalance) => ({ id: String(id), accountBalance })
assert.deepEqual(checkoutBalancePreview(member(7, '6980.00'), 7), {
  balanceAvailable: true, availableBalance: 6980
})
assert.deepEqual(checkoutBalancePreview(member(7, '12.34'), 7), {
  balanceAvailable: true, availableBalance: 12.34
})
for (const [summary, checkoutMemberId] of [
  [member(7, '6980.00'), 8],
  [member(7, '0.00'), 7],
  [member(7, '-1.00'), 7],
  [member(7, 'not-a-balance'), 7],
  [null, 0]
]) {
  assert.deepEqual(checkoutBalancePreview(summary, checkoutMemberId), {
    balanceAvailable: false, availableBalance: 0
  })
}

const workbench = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
assert.match(workbench, /memberId: checkoutMemberId,[\s\S]*?\.\.\.checkoutBalancePreview\(member\.value, checkoutMemberId\)/)
assert.doesNotMatch(workbench, /availableBalance: Number\(checkoutProjection\.payment\?\.availableBalance/)
console.log('PASS R18 member-bound local checkout balance preview')
