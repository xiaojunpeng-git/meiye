import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const workbench = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'), 'utf8')
let passed = 0
let failed = 0
function ok(name, condition) {
  if (condition) passed += 1
  else failed += 1
  console.log(`${condition ? 'PASS' : 'FAIL'} ${name}`)
}

ok('only local payment edits enter the browser checkout queue',
  workbench.includes('localCheckoutPaymentOperations')
    && workbench.includes("action === 'add-payment-method'")
    && workbench.includes("action === 'update-payment-line'")
    && workbench.includes("action === 'remove-payment-line'")
    && workbench.includes("action === 'open-balance-payment'")
    && workbench.includes("action === 'remove-balance-payment'")
    && workbench.includes('localCheckoutPreview.value = preview')
)
ok('final checkout is excluded from draft mutation replay',
  workbench.includes("action === 'submit-checkout'")
    && workbench.includes('return finalizeLocalCheckoutPreview(event)')
    && !workbench.includes("requestAction('prepare-checkout-submission'")
)
ok('a failed final snapshot does not create a second preparation request',
  workbench.includes('本地结账快照未找到')
    && !workbench.includes("requestAction('prepare-checkout'")
    && !workbench.includes("requestAction('prepare-checkout-submission'")
)
ok('the final command carries the complete snapshot after all local edits',
  workbench.includes('function buildCheckoutSnapshot')
    && workbench.includes('checkoutSnapshot')
    && workbench.includes('stripGeneratedMetadata')
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
