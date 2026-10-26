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

const openCheckout = workbench.match(/async function openCheckout\([^)]*\) \{([\s\S]*?)\n}\n\nfunction guardedOpenCheckout/)?.[1] || ''
const finalize = workbench.match(/async function finalizeLocalCheckoutPreview\([^)]*\) \{([\s\S]*?)\n}\n\nfunction localCheckoutPaymentAmount/)?.[1] || ''
const localAction = workbench.match(/if \(localCheckoutPreview\.value\?\.localDraftPreview === true\) \{([\s\S]*?)\n  }\n  const result = \{/)?.[1] || ''

ok('failed checkout remains an explicit failure with a return action',
  overlay.includes('返回收银')
  && !workbench.includes("status === 'failed' && code === 'CLIENT_REQUEST_FAILED'")
)
ok('opening checkout only creates a browser preview',
  openCheckout.includes('const preview = localCheckoutPreviewSnapshot()')
  && openCheckout.includes('localCheckoutPreview.value = preview')
  && openCheckout.includes('isCheckoutOpen.value = true')
  && !/prepare-checkout|prepare-checkout-submission|checkoutRequestVersion|preparationToken/.test(openCheckout)
)
ok('the final button sends one complete snapshot',
  finalize.includes("action: 'submit-checkout'")
  && finalize.includes('checkoutSnapshot')
  && !/prepare-checkout|prepare-checkout-submission/.test(finalize)
)
ok('payment edits stay in the browser until final confirmation',
  localAction.includes("action === 'add-payment-method'")
  && localAction.includes("action === 'update-payment-line'")
  && localAction.includes("action === 'update-checkout-business-source'")
  && localAction.includes("action === 'update-checkout-sales-date'")
  && !/requestAction\(['"](?:prepare-checkout|prepare-checkout-submission)/.test(localAction)
)
ok('guide-round preflight is absent before final confirmation',
  !workbench.includes("action === 'validate-guide-round-before-payment'")
  && !workbench.includes('validateCashierGuideRound({')
)
ok('legacy checkout preparation actions are absent from the frontend',
  !workbench.includes("requestAction('prepare-checkout'")
  && !workbench.includes("requestAction('prepare-checkout-submission'")
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
