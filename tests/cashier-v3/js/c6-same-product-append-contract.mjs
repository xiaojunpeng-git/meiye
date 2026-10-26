import assert from 'node:assert/strict'
import fs from 'node:fs'

const view = fs.readFileSync(
  new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url),
  'utf8'
)
const bridge = fs.readFileSync(
  new URL('../../../前端代码/cashier-v3/src/services/cashierV3Bridge.js', import.meta.url),
  'utf8'
)
const workspace = fs.readFileSync(
  new URL('../../../后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php', import.meta.url),
  'utf8'
)
const catalog = fs.readFileSync(
  new URL('../../../后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php', import.meta.url),
  'utf8'
)

assert.match(view, /async function appendCatalogItemToDraft\(item\)/)
assert.match(view, /appendLocalCashierDraftOperation\(\{ action: 'choose-catalog-item', localLineId, itemId, catalogKind \}/)
assert.match(view, /async function synchronizeLocalCashierDraft\(\{ deferProjection = false \} = \{\}\)[\s\S]*?requestAction\(action, \{[\s\S]*?itemId: Number\(operation\.itemId \|\| 0\)/)
assert.match(view, /createCashierV3CommandId\('CASHIER_MORE'\)/)
assert.doesNotMatch(view, /CASHIER_FINAL_DRAFT/)
assert.match(view, /function consumeSynchronizedLocalCashierOperation\(\)/)
assert.match(view, /finally \{[\s\S]*?restoreLocalCashierDraftAfterSyncFailure\(visibleDraft\)/)
assert.match(view, /function localCashierDraftSyncFailureAt\(\)[\s\S]*?import\.meta\.env\.DEV/)
assert.match(view, /query\.get\('test'\) !== 'local-draft-sync-failure'/)
assert.match(view, /let localCashierDraftSyncFailureConsumed = false/)
assert.match(view, /localCashierDraftSyncFailureConsumed\s*\|\|[\s\S]*?localCashierDraftSyncFailureConsumed = true/)
assert.match(view, /localCashierDraftSyncFailure\(operationIndex \+ 1, action\)[\s\S]*?if \(injectedFailure\) return injectedFailure/)
assert.match(view, /async function openCheckout\([^)]*\)[\s\S]*?await synchronizeLocalCashierDraft\(\)/)
assert.match(view, /async function openHangOrder\(\)[\s\S]*?await synchronizeLocalCashierDraft\(\)/)
assert.match(bridge, /idempotencyKey \|\| createCashierV3CommandId\(\)/)
assert.match(catalog, /'line_key' => 'sale:' \. substr\(hash\('sha256', \$idempotencyKey\)/)
assert.match(workspace, /每次商品点击追加一条独立 sale 行；同一商品不会在草稿层合并/)
assert.match(workspace, /\(string\)\(\$row\['line_key'\] \?\? ''\) === \(string\)\(\$line\['line_key'\] \?\? ''\)/)

console.log('C6 same-product append contract: PASS')
