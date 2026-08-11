import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8')

const overlay = read('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const api = read('前端代码/cashier-v3/src/services/hangDraftApi.js')
const route = read('后端代码/route/cashier-v3.php')
const controller = read('后端代码/app/controller/cashier/v3/HangDraft.php')
const discard = read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutDraftDiscardServices.php')

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

const closeMethod = workbench.match(/async function closeCheckoutOverlay\(options = \{\}\) \{([\s\S]*?)\n}\n\nasync function closeSucceededCheckoutAndRefreshWorkbench/)?.[1] || ''
const closeSucceededMethod = workbench.match(/async function closeSucceededCheckoutAndRefreshWorkbench\(submissionResponse = \{\}\) \{([\s\S]*?)\n}\n\nfunction closeHangOrderOverlay/)?.[1] || ''
const openCheckout = workbench.match(/async function openCheckout\(\) \{([\s\S]*?)\n}\n\nasync function openHangOrder/)?.[1] || ''

ok(
  'a failed checkout with no successful collection always exposes 返回收银',
  overlay.includes('const canCloseOverlay = computed(() => (')
    && overlay.includes('|| (isFailed.value && !isPartialPaymentRecovery.value)')
    && overlay.includes('返回收银')
)
ok(
  'an explicit client or server failure is not disguised as an unknown payment result',
  !workbench.includes("if (status === 'failed' && code === 'CLIENT_REQUEST_FAILED') status = 'result_unknown'")
    && workbench.includes("if (['succeeded', 'success', 'processing', 'pending', 'pending_confirmation'].includes(status))")
)
ok(
  'returning from that failure explicitly requests discard of only the old checkout draft',
  /discardFailedCheckout: isFailed\.value && !isPartialPaymentRecovery\.value/.test(overlay)
    && api.includes("'/cashierapi/v3/cashier-drafts/discard-checkout'")
    && route.includes("Route::post('cashier-drafts/discard-checkout', 'HangDraft/discardCheckout')")
    && controller.includes('public function discardCheckout()')
)
ok(
  'failed-return waits for server discard and a fresh empty-workbench projection before hiding the overlay',
  closeMethod.includes('await discardCashierCheckout(String(state.stateContextId || \'\'))')
    && closeMethod.includes("await requestAction('open-cashier-workbench', { silent: true })")
    && closeMethod.includes("String(checkoutRequestIdentity(checkout.value) || '') !== ''")
    && /isCheckoutOpen\.value = false[\s\S]*?checkoutPreparationId\.value = null[\s\S]*?checkoutSession\.value = null[\s\S]*?checkoutRecoveryActiveStep\.value = null[\s\S]*?checkoutLocalOutcome\.value = \{\}/.test(closeMethod)
)
ok(
  'discard is limited to non-terminal draft statuses and refuses every formal business fact',
  discard.includes("->whereIn('request_status', ['editing', 'ready_for_submit', 'failed'])")
    && discard.includes('ENTITLEMENT_COMPLETION_RECEIPT_TABLE')
    && discard.includes('PAYMENT_FACT_TABLE')
    && discard.includes('BALANCE_FACT_TABLE')
    && discard.includes("'该结账已产生业务事实，不能直接清空，请先核对收款结果。'")
)
ok(
  'discard removes old payment, line, source and resource-plan drafts before deleting the checkout request',
  discard.includes('RESOURCE_PLAN_ROW_TABLE')
    && discard.includes('Db::name(self::LINE_TABLE)->whereIn(\'request_id\', $requestIds)->delete()')
    && discard.includes('Db::name(self::PAYMENT_TABLE)->whereIn(\'request_id\', $requestIds)->delete()')
    && discard.includes('Db::name(self::SOURCE_TABLE)->whereIn(\'request_id\', $requestIds)->delete()')
    && discard.includes('Db::name(self::REQUEST_TABLE)->whereIn(\'request_id\', $requestIds)->delete()')
)
ok(
  'the next confirm creates a new preparation id after the old local identity is cleared',
  openCheckout.includes("if (!checkoutPreparationId.value) checkoutPreparationId.value = createCashierV3CommandId('CHECKOUT_PREPARE')")
    && openCheckout.includes("requestAction('prepare-checkout'")
    && openCheckout.includes("String(checkout.value?.requestStatus || checkout.value?.status || '') === 'editing'")
)
ok(
  'the UAT failure fixture is development-only, enters the real failure result step and does not send a collection command',
  overlay.includes("function useDevelopmentNoPaymentFailureFixture()")
    && overlay.includes('return import.meta.env.DEV')
    && overlay.includes("get('test') === 'checkout-no-payment-failure'")
    && /if \(useDevelopmentNoPaymentFailureFixture\(\)\) \{[\s\S]*?developmentFailureResult\.value = \{[\s\S]*?CHECKOUT_TEST_NO_PAYMENT_FAILURE[\s\S]*?localStep\.value = resultStepNumber\.value[\s\S]*?return[\s\S]*?\}\n\s*isSubmitRequested\.value = true/.test(overlay)
)
ok(
  'successful checkout returns the committed empty workbench draft before the checkout overlay is closed',
  overlay.includes('const succeededSubmissionResponse = ref(null)')
    && overlay.includes('response?.data && typeof response.data === \'object\'')
    && overlay.includes("return String(envelope?.result?.status || envelope?.status || '').toLowerCase()")
    && /function handleSubmissionResponse\(response\) \{[\s\S]*?const status = submissionResponseStatus\(response\)[\s\S]*?\['success', 'succeeded'\]\.includes\(status\)[\s\S]*?succeededSubmissionResponse\.value = response \|\| null/.test(overlay)
    && overlay.includes("emit('completed', succeededSubmissionResponse.value)")
    && closeSucceededMethod.includes('const committedDraft = responseDataBlock(submissionResponse).cashierDraft')
    && closeSucceededMethod.includes('checkoutRequiresRootReload.value = false')
    && closeSucceededMethod.includes('await closeCheckoutOverlay()')
    && closeSucceededMethod.includes('cashierDraftSnapshot.value = canRenderCommittedDraft')
    && closeSucceededMethod.includes("await requestAction('open-cashier-workbench', { silent: true })")
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
