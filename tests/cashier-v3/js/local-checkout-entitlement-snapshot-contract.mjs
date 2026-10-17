import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const workbench = fs.readFileSync(
  new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root),
  'utf8'
)
const checkoutEntry = workbench.match(
  /async function openCheckout\(\) \{[\s\S]*?\n}\n\nfunction guardedOpenCheckout/
)?.[0] || ''
const entitlementGuard = workbench.match(
  /function localCheckoutEntitlementAvailabilityFailure\(snapshot = \{\}\) \{[\s\S]*?\n}\n\nasync function setLineQuantity/
)?.[0] || ''

assert.match(entitlementGuard, /const usageBySource = new Map\(\)/)
assert.match(entitlementGuard, /if \(!isEntitlementLine\(line\)\) continue/)
assert.match(entitlementGuard, /CHECKOUT_ENTITLEMENT_TIMES_EXCEEDED/)
assert.match(entitlementGuard, /source\.quantity > source\.availableTimes/)
assert.doesNotMatch(entitlementGuard, /requestAction\(|requestCashierV3Action\(|fetch\(/)
const guardSource = entitlementGuard.replace(/\n\nasync function setLineQuantity$/, '')
const guard = new Function('isEntitlementLine', `return (${guardSource})`)(
  (line) => line.lineRole === 'entitlement_service'
)
const benefit = (id, quantity, availableTimes, overrides = {}) => ({
  id,
  lineRole: 'entitlement_service',
  entitlementInstanceType: 'card_holder',
  entitlementInstanceId: 'holder-1',
  entitlementSourceDetailId: 'detail-1',
  projectId: 'project-1',
  fullCardNo: 'Z003S6Z',
  name: '腿部放松',
  quantity,
  availableTimes,
  ...overrides
})
assert.equal(guard({ lines: [benefit('a', 1, 1)] }), null)
assert.deepEqual(guard({ lines: [benefit('a', 5, 1), benefit('b', 7, 1)] }), {
  code: 'CHECKOUT_ENTITLEMENT_TIMES_EXCEEDED',
  message: '卡号 Z003S6Z 的项目「腿部放松」当前剩余可用 1 次，本次已选择 12 次，超出 11 次，请调整后再结账。'
})
assert.equal(guard({ lines: [
  benefit('a', 1, 1),
  benefit('b', 12, 12, { entitlementSourceDetailId: 'detail-2' })
] }), null)
// 任选次数卡的不同项目共享同一张卡的 2 次；两项可用，第三项必须阻止。
const shared = (id, detailId, groupNo = 0, overrides = {}) => benefit(id, 1, 2, {
  entitlementSourceDetailId: detailId,
  projectId: `project-${detailId}`,
  amountCalculationVersion: 'shared-card-purchase-amount-whole-yuan-floor-final-remainder-v1',
  displaySnapshot: { combinationGroupNo: groupNo },
  ...overrides
})
assert.equal(guard({ lines: [shared('a', 'detail-1'), shared('b', 'detail-2')] }), null)
assert.deepEqual(guard({ lines: [
  shared('a', 'detail-1'), shared('b', 'detail-2'), shared('c', 'detail-3')
] }), {
  code: 'CHECKOUT_ENTITLEMENT_TIMES_EXCEEDED',
  message: '卡号 Z003S6Z 的共享项目当前剩余可用 2 次，本次已选择 3 次，超出 1 次，请调整后再结账。'
})
assert.match(guard({ lines: [
  shared('a', 'detail-1'),
  shared('b', 'detail-2', 0, { availableTimes: 1 })
] })?.message || '', /共享项目当前剩余可用 1 次，本次已选择 2 次/)
// 组合卡各组独立，替换生成的独立权益也不占原共享池。
assert.equal(guard({ lines: [shared('a', 'detail-1', 1), shared('b', 'detail-2', 1), shared('c', 'detail-3', 2)] }), null)
assert.equal(guard({ lines: [
  shared('a', 'detail-1'), shared('b', 'detail-2'),
  benefit('c', 1, 1, { entitlementSourceDetailId: 'replacement', projectId: 'replacement' })
] }), null)
assert.match(guard({ lines: [benefit('gift', 2, 1, {
  entitlementInstanceType: 'independent_gift', fullCardNo: '',
  sourceCodeSnapshot: 'GIFT-01'
})] }).message, /赠送记录 GIFT-01 的项目「腿部放松」/)
assert.match(checkoutEntry, /const preview = localCheckoutPreviewSnapshot\(\)/)
assert.match(checkoutEntry, /const entitlementFailure = localCheckoutEntitlementAvailabilityFailure\(preview\)/)
assert.match(checkoutEntry, /localCheckoutPreview\.value = preview/)
assert.doesNotMatch(checkoutEntry, /prepare-checkout|synchronizeLocalCashierDraft|requestAction\(/)

console.log('LOCAL_CHECKOUT_ENTITLEMENT_SNAPSHOT_CONTRACT=PASS')
