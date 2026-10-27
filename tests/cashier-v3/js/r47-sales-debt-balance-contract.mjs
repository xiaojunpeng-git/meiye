import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

// Keep the approved UI on the existing balance controls; no second repayment draft or cash-like tender.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const workbench = readFileSync(path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'), 'utf8')
const overlay = readFileSync(path.join(root, '前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue'), 'utf8')
const query = readFileSync(path.join(root, '后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php'), 'utf8')
const prepared = workbench.slice(workbench.indexOf('async function openPreparedCheckout'))
assert.match(prepared, /intent\.rechargeDebt === true\s*\? \{ balanceAvailable: false, availableBalance: 0 \}\s*: checkoutBalancePreview\(member\.value, memberId\)/)
assert.match(workbench, /action === 'update-balance-payment'/)
assert.match(workbench, /Number\.isSafeInteger\(amount\)/)
assert.match(workbench, /payment: \{ \.\.\.currentCheckoutSnapshot\.value\.payment, resultLines: clonePlain\(repaid\.paymentResultLines\) \}/)
assert.match(overlay, /使用余额支付/)
assert.match(query, /'member_balance' => '余额支付'/)
assert.match(query, /if \(\$method === 'member_balance'\) \$label \.= ' ¥'/)
assert.match(query, /repayment_id,payment_line_no,payment_method,amount_cents/)
console.log('R47_SALES_DEBT_BALANCE_UI_CONTRACT=PASS checks=8')
