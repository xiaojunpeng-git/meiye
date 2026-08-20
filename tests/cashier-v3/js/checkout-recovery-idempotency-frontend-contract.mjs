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
function ok(name, condition) {
  if (condition) passed += 1
  else failed += 1
  console.log(`${condition ? 'PASS' : 'FAIL'} ${name}`)
}

const finalSubmit = workbench.match(/async function finalizeLocalCheckoutPreview\([^)]*\) \{([\s\S]*?)\n}\n\nfunction localCheckoutPaymentAmount/)?.[1] || ''
const snapshotBuilder = workbench.match(/function buildCheckoutSnapshot\([^)]*\) \{([\s\S]*?)\n}\n\nasync function openCheckout/)?.[1] || ''

ok('final idempotency key is generated only for the final command',
  /createCashierV3CommandId\('CHECKOUT'\)/.test(overlay)
  && finalSubmit.includes("action: 'submit-checkout'")
)
ok('final submit carries the complete browser snapshot',
  finalSubmit.includes('checkoutSnapshot')
  && !snapshotBuilder.includes("contractVersion: 'cashier-v3-checkout-snapshot-v1'")
  && !workbench.includes('snapshotContractVersion:')
  && snapshotBuilder.includes('businessDate')
  && snapshotBuilder.includes('source: checkoutSourceSnapshot')
)
ok('snapshot strips generated versions and recovery metadata',
  snapshotBuilder.includes('stripGeneratedMetadata')
  && /version|revision|token|commandcontexts|recoveryready|preparationready|snapshotready/.test(snapshotBuilder)
)
ok('no persisted checkout recovery path remains',
  !workbench.includes('persistedEditingCheckoutRecoveryKey')
  && !workbench.includes('resumePersistedCheckout')
  && !workbench.includes("requestAction('prepare-checkout'")
)
ok('payment, source and date edits do not create server drafts',
  /该收款操作将在确认收款时按最新结账单处理/.test(workbench)
  && !/requestAction\(['"](?:prepare-checkout|prepare-checkout-submission)/.test(workbench)
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
