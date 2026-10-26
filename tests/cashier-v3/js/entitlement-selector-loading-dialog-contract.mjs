import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const read = (path) => fs.readFileSync(new URL(path, root), 'utf8')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const selector = read('前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue')
const styles = read('前端代码/cashier-v3/src/styles/base.css')

// The loading dialog follows the existing read-only query, not a separate timer.
assert.match(shell, /@click="openCashierEntitlementSelector"[\s\S]*?>使用权益<\/button>/)
assert.match(workbench, /function handleOpenEntitlementSelector\(\) \{\s*return openEntitlementSelector\(\)/)
assert.match(workbench, /entitlementSelectorLoadState\.value = 'loading'[\s\S]*isEntitlementSelectorOpen\.value = true[\s\S]*await requestAction\('open-add-card-service-project'/)
assert.match(workbench, /entitlementSelectorLoadState\.value = 'ready'/)
assert.match(workbench, /entitlementSelectorLoadState\.value = 'error'/)
assert.match(workbench, /:load-state="entitlementSelectorLoadState"/)
assert.match(selector, /v-if="!isLoading"[^>]*cashier-entitlement-selector-panel__return-button/)
assert.match(selector, /<Teleport to="body">\s*<div v-if="isLoading"[^>]*role="dialog"[^>]*aria-modal="true"/)
assert.match(selector, /const isLoading = computed\(\(\) => props\.loadState === 'loading'\)/)
assert.match(styles, /\.cashier-entitlement-loading \{[\s\S]*position: fixed;[\s\S]*z-index: 1750;/)

console.log('ENTITLEMENT_SELECTOR_LOADING_DIALOG_CONTRACT passed=10 failed=0')
