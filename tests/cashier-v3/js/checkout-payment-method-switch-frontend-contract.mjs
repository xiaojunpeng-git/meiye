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

const addMethodBlock = overlay.match(/function addPaymentMethod\(method = \{\}\) \{[\s\S]*?\n\}/)?.[0] || ''

ok(
  'ordinary collection starts without a selected method and adds the chosen method to the browser draft',
  addMethodBlock.includes("request('add-payment-method', { paymentMethodId: method.id })")
    && workbench.includes("action === 'add-payment-method'")
    && workbench.includes('const checkoutLines = Array.isArray(preview.lines) ? preview.lines : []')
    && workbench.includes('localCheckoutPreview.value = preview')
)
ok(
  'chosen collection method receives the snapshot remaining amount and can still be combined',
  workbench.includes('const remainingAmount = Math.max(0, receivable - selectedCollectionAmount - balancePaymentAmount)')
    && workbench.includes("checkoutLines.push({ id: `local-payment-${Date.now()}`, lineRole: 'payment', method: method.id")
    && workbench.includes("preview.lines = checkoutLines.filter((line) => String(line.id) !== String(payload.paymentLineId || ''))")
    && workbench.includes('checkoutSnapshot')
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
