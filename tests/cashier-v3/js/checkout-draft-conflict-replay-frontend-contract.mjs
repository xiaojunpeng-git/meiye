import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const workbench = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'),
  'utf8'
)
const shell = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/layouts/CashierShell.vue'),
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

const mutationBlock = workbench.match(/const checkoutDraftMutationActions = new Set\(\[([\s\S]*?)\]\)/)?.[1] || ''
const replayBlock = workbench.match(/async function requestCheckoutDraftMutationWithSingleConflictReplay[\s\S]*?\n}\n\nasync function requestCheckoutAction/)?.[0] || ''

ok(
  'only eventless payment and balance draft edits enter the serial queue',
  [
    'add-payment-method',
    'update-payment-line',
    'remove-payment-line',
    'apply-balance-payment',
    'update-balance-payment',
    'remove-balance-payment'
  ].every((action) => mutationBlock.includes(`'${action}'`))
    && !mutationBlock.includes("'prepare-checkout-submission'")
    && !mutationBlock.includes("'submit-checkout'")
)
ok(
  'a real resource-version conflict reloads the same persisted editing checkout once',
  workbench.includes("String(resultBlock.code || envelope?.code || '') === 'RESOURCE_VERSION_CONFLICT'")
    && workbench.includes("await requestAction('open-cashier-workbench', { silent: true })")
    && workbench.includes("String(latest.requestStatus || latest.status || '') !== 'editing'")
    && (replayBlock.match(/requestCheckoutAction\(event\)/g) || []).length === 2
)
ok(
  'final checkout commands are explicitly excluded from draft conflict replay',
  replayBlock.includes('Do not expand')
    && !replayBlock.includes("requestCheckoutAction({ action: 'submit-checkout'")
    && !replayBlock.includes("requestCheckoutAction({ action: 'prepare-checkout-submission'")
)
ok(
  'draft edits atomically replace only the committed checkout projection before falling back to a full refresh',
  workbench.includes('function applyCheckoutDraftProjection(result)')
    && workbench.includes('checkoutProjection')
    && workbench.includes('checkoutDraftMutationActions.has(action)')
    && workbench.includes('await reloadLatestCheckoutDraftForMutation(session)')
)
ok(
  'an incomplete authoritative cashier draft triggers the existing automatic workbench refresh without a success notification',
  /\['INVALID_COMMAND_CONTEXT', 'RESOURCE_VERSION_NOT_READY', 'COMMAND_CONTEXT_VERSION_REQUIRED', 'CASHIER_DRAFT_INCOMPLETE'\]\.includes\(String\(detail\.code \|\| ''\)\)[\s\S]{0,180}cashier-v3:refresh-workbench[\s\S]{0,80}return/.test(shell)
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
