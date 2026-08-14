import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8')
const overlay = read('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const api = read('前端代码/cashier-v3/src/services/hangDraftApi.js')

let passed = 0
let failed = 0
function check(name, condition) {
  console.log(`${condition ? 'PASS' : 'FAIL'} ${name}`)
  if (condition) passed += 1
  else failed += 1
}

const nextStep = overlay.match(/async function goNext\(\) \{([\s\S]*?)\n}\n\nfunction returnToPaymentEdit/)?.[1] || ''
const preflightBranch = workbench.match(/if \(action === 'validate-guide-round-before-payment'\) \{([\s\S]*?)\n  }\n\n  if \(action === 'prepare-service-completion'\)/)?.[1] || ''

check('the order-to-payment button waits for guide-round validation before it changes steps',
  nextStep.includes("await request('validate-guide-round-before-payment')")
    && nextStep.indexOf("await request('validate-guide-round-before-payment')") < nextStep.lastIndexOf('localStep.value = nextStep.number')
    && nextStep.includes('isCheckingGuideRound.value = true')
)
check('the validation call is a direct read-only checkout preflight, not a settlement command',
  workbench.includes("'validate-guide-round-before-payment'")
    && preflightBranch.includes('validateCashierGuideRound({')
    && !preflightBranch.includes("requestAction('submit-checkout'")
)
check('the browser call sends only checkout identity and uses the protected cashier API',
  api.includes('export async function validateCashierGuideRound')
    && api.includes("'/cashierapi/v3/cashier-drafts/validate-guide-round'")
    && api.includes('checkoutRequestVersion')
)
check('returning to the cashier clears every unfinished checkout session',
  workbench.includes('const shouldDiscardUnfinishedCheckout = options?.discardFailedCheckout === true')
    && workbench.includes("['editing', 'ready_for_submit', 'failed', 'processing', 'pending_confirmation', 'result_unknown'].includes(requestStatus)")
    && workbench.includes('await discardCashierCheckout(String(state.stateContextId || \'\'))')
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
