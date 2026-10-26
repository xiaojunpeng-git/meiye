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
  'fractional input is preserved for explicit rejection and is never truncated',
  overlay.includes("return String(value ?? '').trim()")
    && overlay.includes("if (!/^(0|[1-9]\\d*)$/.test(raw)) return null")
    && !overlay.includes("raw.match(/^\\d+/)")
    && !overlay.includes('Math.trunc(amount)')
)
ok(
  'authoritative whole-yuan decimal strings populate the integer-only cashier input',
  overlay.includes('function authoritativeWholeYuanAmount(value)')
    && overlay.includes("raw.match(/^(0|[1-9]\\d*)(?:\\.00)?$/)")
    && overlay.includes('const amount = authoritativeWholeYuanAmount(value)')
    && overlay.includes('const draftAmount = wholeYuanAmount(draft.value)')
)
ok(
  'editable payment rows render the input as the only amount display',
  overlay.includes('<strong v-if="!canEditPaymentLine(line)">{{ formatMoney(line.amount) }}</strong>')
    && !overlay.includes('<strong v-else>{{ formatMoney(line.amount) }}</strong>')
)
ok(
  'a balance edit remains the dedicated balance draft command',
  overlay.includes("request('update-balance-payment', { amount: normalizedAmount })")
    && overlay.includes("if (line.kind === 'balance_deduction')")
)
ok(
  'payment step blocks final confirmation until the browser payment snapshot is balanced',
  overlay.includes('const hasPendingPaymentLineAmountDraft = computed(() =>')
    && overlay.includes('const isPaymentDraftReady = computed(() =>')
    && overlay.includes("const isPaymentAmountBalanced = computed(() => paymentAmountValidation.value.state === 'balanced')")
    && overlay.includes('if (currentStep.value === 2) {')
    && overlay.includes('if (!isPaymentDraftReady.value || !isPaymentAmountBalanced.value)')
    && overlay.includes("showPaymentValidationPrompt(paymentAmountValidation.value.message || '请先完成本次收款金额。')")
    && overlay.includes('[key]: { ...draft, value: normalizedAmount, pending: false }')
    && overlay.includes('(currentStep === 2 && (!isPaymentDraftReady || !isPaymentAmountBalanced))')
)
ok(
  'the serialized parent queue settles successful drafts and rolls failed drafts back to authority',
  workbench.includes("new CustomEvent('cashier-v3:checkout-draft-mutation-result'")
    && overlay.includes("window.addEventListener('cashier-v3:checkout-draft-mutation-result', handleCheckoutDraftMutationResult)")
    && overlay.includes("window.removeEventListener('cashier-v3:checkout-draft-mutation-result', handleCheckoutDraftMutationResult)")
    && overlay.includes("const succeeded = ['success', 'succeeded'].includes(String(detail.status || ''))")
    && overlay.includes('if (succeeded) {')
    && overlay.includes('clearPaymentLineAmountDraft(key)')
    && overlay.includes('clearPaymentLineAmountError(key)')
    && overlay.includes("setPaymentLineAmountError(key, detail.message || '收款金额没有保存，已恢复原金额。')")
)
ok(
  'final confirmation collection is recalculated from the same local payment snapshot',
  workbench.includes('cashPerformanceAmount: 0,')
    && workbench.includes('const checkoutPaymentLines = checkoutSnapshotPaymentLines(preview.lines || [])')
    && workbench.includes(".filter((line) => checkoutLineRole(line) === 'payment')")
    && workbench.includes('preview.cashPerformanceAmount = cashPerformanceAmount')
    && workbench.includes('payment.cashPerformanceAmount = cashPerformanceAmount')
    && overlay.includes('const checkoutCollectionAmount = computed(() => selectedPaymentLines.value.reduce(')
    && overlay.includes('const checkoutBalancePaymentAmount = computed(() => selectedPaymentLines.value.reduce(')
    && overlay.includes('<dt>收款</dt><dd>{{ formatMoney(checkoutCollectionAmount) }}</dd>')
    && !overlay.includes('<dt>现金业绩</dt><dd>{{ formatMoney(checkout.cashPerformanceAmount) }}</dd>')
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
