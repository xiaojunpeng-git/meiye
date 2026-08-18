import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const overlay = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue'),
  'utf8'
)
const workbench = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'),
  'utf8'
)

let passed = 0
let failed = 0
function ok(name, condition) {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
    return
  }
  failed += 1
  console.log(`FAIL ${name}`)
}

ok(
  'recovered checkout key is accepted only in canonical CHECKOUT UUID form',
  overlay.includes('const recoveredCheckoutIdempotencyKey = computed(() => {')
    && overlay.includes('/^CHECKOUT-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(key)')
)
ok(
  'final submit reuses a recovered key before generating a new checkout identity',
  /submitCommandId\.value = recoveredCheckoutIdempotencyKey\.value\s*\|\| createCashierV3CommandId\('CHECKOUT'\)/.test(overlay)
)
ok(
  'editing checkout retains new final-key generation fallback',
  overlay.includes("|| createCashierV3CommandId('CHECKOUT')")
)
ok(
  'recovered prepared checkout submits directly without replaying its preparation receipt',
  workbench.includes("String(checkout.value?.requestStatus || '') === 'ready_for_submit'")
    && workbench.includes("String(checkout.value?.originalIdempotencyKey || '') === submissionKey")
    && /if \(recoveredPreparedCheckout\) \{[\s\S]*?requestAction\(effectiveAction, approvedPayload\)[\s\S]*?\} else \{\s*const prepareKey/.test(workbench)
)
ok(
  'successful checkout opens the authoritative sales-order detail before navigating',
  /if \(action === 'view-sales-order'(?: \|\| action === 'print-sales-order-receipt')?\) \{[\s\S]*?requestAction\('open-sales-order-detail', \{ orderId: salesOrderId \}\)[\s\S]*?if \(action === 'view-sales-order'\) \{[\s\S]*?router\.push\(\{ name: 'cashier-v3-order-center' \}\)[\s\S]*?cashier-v3:open-sales-order-detail/.test(workbench)
    && !/requestAction\('view-sales-order'/.test(workbench)
)
ok(
  'only a final member-balance version conflict starts automatic draft recovery',
  workbench.includes("action === 'submit-checkout'")
    && workbench.includes("String(conflict.reason || '') === 'member_balance_version_conflict'")
    && workbench.includes("Number(checkout.value?.balancePaymentAmount || 0) > 0")
)
ok(
  'automatic balance recovery is once per final checkout version and returns to payment edit',
  workbench.includes('const automaticBalanceConflictRecoveries = new Set()')
    && workbench.includes("automaticBalanceConflictRecoveries.add(recoveryKey)")
    && /requestCheckoutAction\(\{\s*action: 'return-to-payment-edit'/.test(workbench)
    && workbench.includes("cashier-v3:checkout-returned-to-payment-edit")
)
ok(
  'recovery does not invoke another final checkout command',
  !/action: 'return-to-payment-edit'[\s\S]{0,600}action: 'submit-checkout'/.test(workbench)
)
ok(
  'final preparation sends only workspace and checkout request contexts',
  workbench.includes('const preparationContexts = checkoutSubmissionCommandContexts(current.commandContexts)')
    && /requestAction\('prepare-checkout-submission',[\s\S]{0,220}commandContexts: preparationContexts/.test(workbench)
)
ok(
  'every persisted checkout follow-up filters out the server-built resource plan',
  /if \(checkoutRequestActions\.has\(action\) && !isRechargeDebtRepaymentCheckout\.value\) \{[\s\S]{0,500}const checkoutContexts = checkoutSubmissionCommandContexts\(current\.commandContexts\)[\s\S]{0,800}approvedPayload\.commandContexts = checkoutContexts/.test(workbench)
    && workbench.includes("'go-to-writeoff-after-checkout'")
    && workbench.includes("'finish-checkout-and-return'")
)
ok(
  'returning to payment edit always resets the overlay to payment step two',
  /function returnToPaymentEdit\(\) \{[\s\S]{0,260}localStep\.value = 2/.test(overlay)
    && overlay.includes("cashier-v3:checkout-returned-to-payment-edit")
    && /function handleCheckoutReturnedToPaymentEdit\(\) \{[\s\S]{0,160}localStep\.value = 2/.test(overlay)
)
ok(
  'initial root hydration reopens only the same persisted editing checkout request',
  workbench.includes('const persistedEditingCheckoutRecoveryKey = computed(() => {')
    && workbench.includes("snapshot.resumeOnLoad !== true || String(snapshot.requestStatus || '') !== 'editing'")
    && /watch\(\s*persistedEditingCheckoutRecoveryKey,[\s\S]{0,260}resumePersistedCheckout\(\)/.test(workbench)
)
ok(
  'editing balance drafts restore at confirmation only after their authoritative payment is fully balanced',
  workbench.includes('const isEditingPaymentDraft = String(snapshot.requestStatus || \'\') === \'editing\'')
    && workbench.includes('Number(paymentSummary.selectedAmount || 0) > 0')
    && workbench.includes('const isFullyPaid = Number(paymentSummary.remainingAmount) === 0')
    && workbench.includes('checkoutRecoveryActiveStep.value = isFullyPaid')
    && workbench.includes('? 3')
)
ok(
  'a persisted balance deduction line is accepted without external-payment fields',
  (() => {
    const paymentLineContract = workbench.match(/function isAuthoritativeCheckoutPaymentLine\([\s\S]*?\n}\n\nfunction checkoutRequestIdentity/)?.[0] || ''
    return paymentLineContract.includes("line.kind === 'balance_deduction'")
      && paymentLineContract.includes("line.id === 'balance-deduction'")
      && paymentLineContract.includes("line.editAction === 'update-balance-payment'")
      && paymentLineContract.includes("line.removalAction === 'remove-balance-payment'")
      && paymentLineContract.includes("return hasOwn(line, 'externalTransactionNo') && hasOwn(line, 'remark')")
  })()
)
ok(
  'persisted draft recovery has no prepare or final submit command',
  (() => {
    const restoreBlock = workbench.match(/function resumePersistedCheckout\(\)[\s\S]*?\n}\n\n\/\*\*/)?.[0] || ''
    return !restoreBlock.includes("requestAction('prepare-checkout'")
      && !restoreBlock.includes("requestAction('submit-checkout'")
      && !restoreBlock.includes("requestCheckoutAction({ action: 'submit-checkout'")
  })()
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
