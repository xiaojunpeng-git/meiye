import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const workbench = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'),
  'utf8'
)

let failed = 0
function ok(name, condition) {
  console.log(`${condition ? 'PASS' : 'FAIL'} ${name}`)
  if (!condition) failed += 1
}

const draftScope = workbench.match(/const currentCashierDraftScopeKey = computed\(\(\) => ([\s\S]*?)\n\)/)?.[1] || ''
const appendCatalog = workbench.match(/async function appendCatalogItemToDraft\(item\) \{([\s\S]*?)\n}\n\nfunction renderedCashierDraftMatches/)?.[1] || ''
const completeDraft = workbench.match(/function isCompleteCashierDraft\(draft, requestScopeKey\) \{([\s\S]*?)\n}\n\nfunction reportEntitlementContractError/)?.[1] || ''
const closeSucceededCheckout = workbench.match(/async function closeSucceededCheckoutAndRefreshWorkbench\(submissionResponse = \{\}\) \{([\s\S]*?)\n}\n\nfunction closeHangOrderOverlay/)?.[1] || ''

ok(
  'draft snapshots use a stable business scope that excludes workspace revision',
  draftScope.includes('cashierBusinessScopeKey(cashierScopeIdentity.value)')
)
ok(
  'a successful catalog mutation validates and stores the response in the stable draft scope',
  appendCatalog.includes('const requestScopeKey = currentCashierDraftScopeKey.value')
    && appendCatalog.includes('applyCommittedCashierDraft(draft, requestScopeKey)')
)
ok(
  'draft completeness is not invalidated merely because the command advanced the workspace revision',
  completeDraft.includes('requestScopeKey !== currentCashierDraftScopeKey.value')
)
ok(
  'the local cart reads drafts from the stable draft scope',
  workbench.includes('cashierDraftSnapshot.value?.scopeKey === currentCashierDraftScopeKey.value')
)
ok(
  'a settled checkout renders the committed empty draft before the authoritative workbench refresh',
  closeSucceededCheckout.includes('const committedDraft = responseDataBlock(submissionResponse).cashierDraft')
    && closeSucceededCheckout.includes('cashierDraftSnapshot.value = canRenderCommittedDraft')
    && closeSucceededCheckout.includes("requestAction('open-cashier-workbench', { silent: true })")
)

process.exit(failed === 0 ? 0 : 1)
