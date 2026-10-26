import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
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

const openCheckout = workbench.match(/async function openCheckout\(\) \{([\s\S]*?)\n}\n\nasync function openHangOrder/)?.[1] || ''
const receiptMatcher = workbench.match(/function isCheckoutPreparationReceiptForSnapshot\([\s\S]*?\n}\n\nfunction isCompleteCheckoutPreparation/)?.[0] || ''

ok(
  'a reopened editing checkout validates the response receipt against the current persisted request revision',
  receiptMatcher.includes("receipt.preparationRequestId")
    && receiptMatcher.includes("receipt.checkoutRequestId")
    && receiptMatcher.includes("receipt.checkoutRequestVersion")
    && receiptMatcher.includes('checkoutRequestIdentity(snapshot)')
    && receiptMatcher.includes('checkoutRequestVersion(snapshot)')
)
ok(
  'a reopened editing checkout activates its session with the persisted stable preparation key',
  openCheckout.includes('const sessionPreparationRequestId = reviseEditingCheckout')
    && openCheckout.includes('String(snapshot.preparationRequestId || \'\')')
    && openCheckout.includes('activateCheckoutSession(snapshot, sessionPreparationRequestId)')
    && openCheckout.includes('checkoutPreparationId.value = sessionPreparationRequestId')
)
ok(
  'a new checkout keeps its command id as the session preparation key',
  openCheckout.includes(': preparationRequestId')
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
