import assert from 'node:assert/strict'
import fs from 'node:fs'

const view = fs.readFileSync(
  new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url),
  'utf8'
)

const localDraft = view.match(/const localCashierDraft = computed\(\(\) => \(([\s\S]*?)\n\)\)/)?.[1] || ''
assert.match(localDraft, /isEntitlementSelectorOpen\.value/)
assert.match(localDraft, /entitlementSelectorDraftCheckpoint\.value\.snapshot/)

const capture = view.match(/function captureLocalCashierDraftForEntitlementSelector\(\) \{([\s\S]*?)\n\}/)?.[1] || ''
assert.doesNotMatch(capture, /localCashierDraftOperations\.value\.length/)
assert.match(capture, /snapshot: clonePlain\(draft\)/)

const scopeWatcher = view.match(/watch\(\n  cashierScopeIdentity,[\s\S]*?\n\)\n\n\/\/ 会员权益选择是只读投影/)
assert.ok(scopeWatcher, 'cashier scope watcher must be present')
assert.match(scopeWatcher[0], /isReadOnlyEntitlementProjectionTransition\(current, previous\)/)
assert.match(scopeWatcher[0], /return/)

const readOnlyTransition = view.match(/function isReadOnlyEntitlementProjectionTransition\([\s\S]*?\n}\nconst localEntitlementSelector/)?.[0] || ''
assert.match(readOnlyTransition, /isOpeningEntitlementSelector\.value/)
assert.match(readOnlyTransition, /previousMemberId !== ''/)
assert.match(readOnlyTransition, /currentMemberId === '' \|\| currentMemberId === previousMemberId/)

const scopeCompatibility = view.match(/function isEntitlementDraftScopeCompatible\([\s\S]*?\n\}/)?.[0] || ''
assert.match(scopeCompatibility, /scope\.stateContextId && expected\[0\]/)
assert.match(scopeCompatibility, /scope\.storeId && expected\[1\]/)
assert.match(scopeCompatibility, /currentMemberId && expected\[4\]/)
assert.doesNotMatch(scopeCompatibility, /scope\.customerMode.*expected\[3\]/)
assert.doesNotMatch(scopeCompatibility, /expected\[2\].*workspaceId.*!==/)

console.log('PASS entitlement selector preserves mixed local cart during delayed projections')
