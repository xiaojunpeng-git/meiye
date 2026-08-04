import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')

const moduleSource = read('后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php')
const policySource = read('后端代码/app/services/cashier/v3/registry/CashierV3ContextPolicyRegistry.php')
const normalizerSource = read('后端代码/app/services/cashier/v3/CashierV3RequestNormalizer.php')
const versionProviderSource = read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutRequestVersionProvider.php')
const paymentDraftSource = read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPaymentDraftServices.php')
const projectionSource = read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php')
const overlaySource = read('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue')
const workbenchSource = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')

let passed = 0
let failed = 0
function check(name, condition) {
  if (condition) {
    passed += 1
    process.stdout.write(`PASS: ${name}\n`)
    return
  }
  failed += 1
  process.stdout.write(`FAIL: ${name}\n`)
}

const paymentActions = ['add-payment-method', 'update-payment-line', 'remove-payment-line']
const paymentHandlerStart = moduleSource.indexOf("foreach (['add-payment-method', 'update-payment-line', 'remove-payment-line'] as $action)")
const paymentHandlerEnd = moduleSource.indexOf("if ($dispatcher->policies()->has('add-checkout-entitlement-lines')", paymentHandlerStart)
const paymentHandlerBlock = moduleSource.slice(paymentHandlerStart, paymentHandlerEnd)
for (const action of paymentActions) {
  check(`cashier module registers ${action}`,
    paymentHandlerStart >= 0
    && paymentHandlerBlock.includes(`'${action}'`)
    && paymentHandlerBlock.includes('$handlers->registerCommand($action')
    && paymentHandlerBlock.includes('$paymentDrafts->mutateInTx($action, $scope)'))
  check(`global context policy uniquely covers ${action}`,
    policySource.split(`'${action}'`).length - 1 === 1)
}
check('production cashier module explicitly installs the three payment draft policies',
  moduleSource.includes('function registerPaymentDraftPolicies(')
  && moduleSource.includes('self::registerPaymentDraftPolicies($dispatcher);')
  && moduleSource.includes("[$dispatcher->policies(), 'resolveCheckoutFollowUpBranch']"))
check('payment handler business number uses the canonical service response identity',
  moduleSource.includes("'business_no' => (string)$edited['checkoutRequestId']")
  && paymentDraftSource.includes("'checkoutRequestId' => (string)$kernel['requestId']")
  && paymentDraftSource.includes("'checkoutRequestVersion' => (int)$kernel['requestVersion']"))

check('request normalizer freezes common checkout identity fields',
  ['checkoutRequestId', 'checkoutRequestVersion', 'preparationRequestId', 'preparationToken']
    .every((field) => normalizerSource.includes(`'${field}'`)))
check('request normalizer freezes action-specific payment fields',
  ['paymentMethodId', 'paymentLineId', 'amount', 'externalTransactionNo', 'remark']
    .every((field) => normalizerSource.includes(`'${field}'`))
  && normalizerSource.includes('if ($actual !== $allowed)'))

const bumpStart = versionProviderSource.indexOf('public function bumpVersionWithDataScope(')
const bumpEnd = versionProviderSource.indexOf('\n    private function ', bumpStart)
const bumpBody = versionProviderSource.slice(bumpStart, bumpEnd)
check('checkout request version provider observes domain CAS without a second UPDATE',
  bumpStart >= 0
  && bumpBody.includes('lockAndReadVersionWithDataScope(')
  && bumpBody.includes('return $current;')
  && !bumpBody.includes('->update('))

check('checkout overlay keeps step navigation local',
  overlaySource.includes('const localStep = ref(')
  && overlaySource.includes('localStep.value = editableSteps.value[currentIndex - 1].number')
  && overlaySource.includes('localStep.value = nextStep.number'))
check('checkout overlay supports local combination mode and three draft commands',
  overlaySource.includes('const combinationMode = ref(')
  && overlaySource.includes('function toggleCombinationMode()')
  && overlaySource.includes("request('add-payment-method'")
  && overlaySource.includes("request('update-payment-line'")
  && overlaySource.includes("request('remove-payment-line'"))
check('checkout projection exposes an applied balance as a distinct cancellable receipt line',
  projectionSource.includes("'kind' => 'balance_deduction'")
  && projectionSource.includes("'removalAction' => 'remove-balance-payment'")
  && projectionSource.includes("'selectedAmount' => self::money(")
  && projectionSource.includes("self::safeAdd($selectedPayment, $balance, 'selected_collection_total')"))
check('checkout overlay cancels a balance receipt line without treating it as a bookkeeping payment',
  overlaySource.includes("line.kind === 'balance_deduction'")
  && overlaySource.includes("line.removalAction === 'remove-balance-payment'")
  && overlaySource.includes("hasBalancePayment ? 'remove-balance-payment' : 'open-balance-payment'"))
check('checkout overlay only submits whole-yuan bookkeeping payment amounts',
  overlaySource.includes('function normalizeWholeYuanInput(value)')
  && overlaySource.includes("raw.match(/^\\d+/)?.[0] || ''")
  && overlaySource.includes("if (!/^[1-9]\\d*$/.test(normalizedAmount))"))

const checkoutActionStart = workbenchSource.indexOf('async function requestCheckoutAction({ action, payload })')
const checkoutActionEnd = workbenchSource.indexOf('async function openCheckoutFromService(', checkoutActionStart)
const checkoutActionBlock = workbenchSource.slice(checkoutActionStart, checkoutActionEnd)
check('workbench sends the latest projected preparation token for every checkout command',
  checkoutActionStart >= 0
  && checkoutActionEnd > checkoutActionStart
  && checkoutActionBlock.includes("preparationToken: String(checkoutPreparationToken(checkout.value) || '')")
  && !checkoutActionBlock.includes('preparationToken: session.preparationToken,'))

process.stdout.write(`RESULT: ${passed} passed, ${failed} failed\n`)
process.exit(failed === 0 ? 0 : 1)
