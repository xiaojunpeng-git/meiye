import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const read = (path) => fs.readFileSync(new URL(path, root), 'utf8')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const collectionPlan = read('后端代码/app/services/cashier/v3/settlement/payment/CashierV3PaymentCollectionPlanV1.php')

assert.match(workbench,
  /const zeroReceivableSale = hasSaleLines[\s\S]*moneyToCents\(checkoutSnapshotReceivableAmount\(lines\)\) === 0/)
assert.match(workbench,
  /\.filter\(\(line\) => Number\(line\?\.amount \|\| 0\) > 0 \|\| \([\s\S]*zeroReceivableSale[\s\S]*checkoutLineRole\(line\) === 'payment'[\s\S]*String\(line\?\.method \|\| ''\)\.trim\(\) !== ''/)
assert.match(collectionPlan,
  /if \(\$amount === 0\) \{[\s\S]*\$request\['selected_payment_amount_cents'\] !== 0[\s\S]*\$request\['receivable_amount_cents'\] !== 0[\s\S]*continue;/)

console.log('ZERO_RECEIVABLE_PAYMENT_METHOD_CONTRACT passed=3 failed=0')
