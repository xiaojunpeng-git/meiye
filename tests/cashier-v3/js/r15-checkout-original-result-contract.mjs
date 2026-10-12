import assert from 'node:assert/strict'
import fs from 'node:fs'
import { authoritativeCheckoutResult } from '../../../前端代码/cashier-v3/src/services/cashierV3CheckoutResultContract.js'

const root = new URL('../../../', import.meta.url)
const workbench = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root), 'utf8')
const overlay = fs.readFileSync(new URL('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue', root), 'utf8')
const key = 'CHECKOUT-00000000-0000-4000-8000-000000000001'
const requestId = `CKR-${'a'.repeat(40)}`
const receiptId = `ECR-${'b'.repeat(40)}`
const correlationId = 'CORR-test'
const expected = { originalIdempotencyKey: key, checkoutRequestId: '', stateContextId: 'ctx-1' }
const envelope = (dto) => ({
  correlationId,
  boundAction: 'query-checkout-result',
  boundCanonical: 'query-checkout-result',
  boundOriginalIdempotencyKey: key,
  boundCorrelationId: correlationId,
  stateContextId: 'ctx-1',
  data: { checkoutResult: dto }
})
const successfulService = {
  contractVersion: 'cashier-v3-checkout-result-v1',
  originalIdempotencyKey: key,
  status: 'success',
  phase: 'succeeded',
  composition: 'entitlement_only',
  checkoutRequest: { requestId, requestVersion: 1, requestStatus: 'succeeded' },
  salesOrder: null,
  entitlementCompletion: { receiptId, receiptStatus: 'completed', planFingerprint: 'c'.repeat(64) }
}

assert.equal(authoritativeCheckoutResult(envelope(successfulService), expected)?.entitlementCompletion?.receiptId, receiptId)
assert.equal(authoritativeCheckoutResult(envelope({ ...successfulService, status: 'result_unknown', phase: 'pending', checkoutRequest: null, entitlementCompletion: null }), expected)?.status, 'result_unknown')
assert.equal(authoritativeCheckoutResult(envelope({ ...successfulService, status: 'failed', phase: 'failed', checkoutRequest: null, entitlementCompletion: null }), expected)?.status, 'failed')
assert.equal(authoritativeCheckoutResult(envelope(successfulService), { ...expected, originalIdempotencyKey: 'CHECKOUT-other' }), null)
assert.equal(authoritativeCheckoutResult({ ...envelope(successfulService), boundCorrelationId: 'CORR-other' }, expected), null)
assert.equal(authoritativeCheckoutResult(envelope({ ...successfulService, entitlementCompletion: null }), expected), null)
assert.equal(authoritativeCheckoutResult(envelope(successfulService), { ...expected, checkoutRequestId: `CKR-${'d'.repeat(40)}` }), null)

const localQuery = workbench.slice(workbench.indexOf("if (action === 'query-checkout-result'\n    && localCheckoutPreview.value?.localDraftPreview"), workbench.indexOf('// Local preview submissions have no checkout session'))
assert.match(localQuery, /requestAction\('query-checkout-result', \{[\s\S]*originalIdempotencyKey,[\s\S]*queryOnly: true/)
assert.doesNotMatch(localQuery, /requestAction\('submit-checkout'/)
assert.match(overlay, /const submissionSubject = computed\([\s\S]*'服务'/)
assert.match(overlay, /props\.checkout\.localDraftPreview === true \|\| isDebtRepayment/)

console.log('R15_CHECKOUT_ORIGINAL_RESULT_CONTRACT=PASS')
