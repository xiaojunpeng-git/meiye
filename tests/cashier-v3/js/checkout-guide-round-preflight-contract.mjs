import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8')
const overlay = read('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')

let passed = 0
let failed = 0
function check(name, condition) {
  console.log(`${condition ? 'PASS' : 'FAIL'} ${name}`)
  if (condition) passed += 1
  else failed += 1
}

const nextStep = overlay.match(/async function goNext\(\) \{([\s\S]*?)\n}\n\nfunction returnToPaymentEdit/)?.[1] || ''

check('the order-to-payment button advances without a guide-round preflight',
  nextStep.includes('localStep.value = nextStep.number')
    && !nextStep.includes('validate-guide-round-before-payment')
    && !nextStep.includes('isCheckingGuideRound.value = true')
)
check('guide-round preflight commands are absent from browser-owned checkout',
  !workbench.includes("'validate-guide-round-before-payment'")
    && !workbench.includes('validateCashierGuideRound({')
)
check('returning to the cashier clears every unfinished checkout session',
  !workbench.includes('persistedEditingCheckoutRecoveryKey')
    && !workbench.includes('resumePersistedCheckout')
    && workbench.includes('本地结账快照未找到')
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
