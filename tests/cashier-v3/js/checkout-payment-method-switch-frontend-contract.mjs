import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const overlay = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue'),
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
  'ordinary collection switches an existing balance or bookkeeping payment before adding the new method',
  addMethodBlock.includes('const isCombinedCollection = combinationMode.value || selectedPaymentLines.value.length > 1')
    && addMethodBlock.includes("request('remove-balance-payment')")
    && addMethodBlock.includes("request('remove-payment-line', { paymentLineId: line.id })")
    && addMethodBlock.lastIndexOf("request('add-payment-method', { paymentMethodId: method.id })")
      > addMethodBlock.indexOf("request('remove-balance-payment')")
)
ok(
  'combined collection retains existing payment lines and does not silently replace them',
  addMethodBlock.includes('if (!isCombinedCollection)')
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
