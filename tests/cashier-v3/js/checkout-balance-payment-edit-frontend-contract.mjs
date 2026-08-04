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
  'payment amount input keeps an explicit local edit state',
  overlay.includes('const paymentLineAmountDrafts = ref({})')
    && overlay.includes('function updatePaymentLineAmountDraft(line, event)')
    && overlay.includes('function paymentLineInputAmount(line = {})')
)
ok(
  'local whole-yuan edits immediately drive the payment summary preview',
  overlay.includes('const displayedPaymentSummary = computed(() =>')
    && overlay.includes('selectedAmount: selectedAmount + selectedDelta')
    && overlay.includes('remainingAmount: Math.max(0, previewRemaining)')
    && overlay.includes('displayedPaymentSummary.selectedAmount')
)
ok(
  'payment amount is a whole-yuan field and saves only on blur or Enter',
  overlay.includes('type="text"')
    && overlay.includes('inputmode="numeric"')
    && overlay.includes('pattern="[0-9]*"')
    && overlay.includes('@blur="savePaymentLineAmount(line, paymentLineInputAmount(line))"')
    && overlay.includes('@keydown.enter.prevent="savePaymentLineAmount(line, paymentLineInputAmount(line))"')
    && !overlay.includes('@change="savePaymentLineAmount(line, $event.target.value)"')
)
ok(
  'a balance edit remains the dedicated balance draft command',
  overlay.includes("request('update-balance-payment', { amount: normalizedAmount })")
    && overlay.includes("if (line.kind === 'balance_deduction')")
)
ok(
  'checkout cannot advance while an amount draft has not received its authoritative result',
  overlay.includes('const hasPendingPaymentLineAmountDraft = computed(() =>')
    && overlay.includes('if (currentStep.value === 2 && hasPendingPaymentLineAmountDraft.value) return')
    && overlay.includes('(currentStep === 2 && hasPendingPaymentLineAmountDraft)')
)
ok(
  'the serialized parent queue reports draft failures so the field rolls back to authority',
  workbench.includes("new CustomEvent('cashier-v3:checkout-draft-mutation-result'")
    && overlay.includes("window.addEventListener('cashier-v3:checkout-draft-mutation-result', handleCheckoutDraftMutationResult)")
    && overlay.includes("window.removeEventListener('cashier-v3:checkout-draft-mutation-result', handleCheckoutDraftMutationResult)")
    && overlay.includes("setPaymentLineAmountError(key, detail.message || '收款金额没有保存，已恢复原金额。')")
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
