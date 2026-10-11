import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { resolve } from 'node:path'
import vm from 'node:vm'

const root = resolve(fileURLToPath(new URL('../../..', import.meta.url)))
const shell = readFileSync(resolve(root, '前端代码/cashier-v3/src/layouts/CashierShell.vue'), 'utf8')
const gate = shell.match(/<div v-else-if="([^"]+)" class="cashier-inventory-group">/)?.[1]

assert.ok(gate, 'inventory parent navigation gate is missing')
assert.match(shell, /const visibleInventoryFeatureItems = computed\(\(\) => inventoryFeatureItems\.filter\(\(entry\) => canUseFeature\(entry\.featureCode\)\)\)/)
assert.match(shell, /function openInventoryWorkspace\(entry = visibleInventoryFeatureItems\.value\[0\]\) \{\s*if \(!entry \|\| !canUseFeature\(entry\.featureCode\)\) return/)

// Parent visibility follows granted inventory leaves, even when cashier access is absent.
const item = { key: 'inventory', submenu: [{ key: 'stock' }] }
assert.ok(vm.runInNewContext(gate, { item, visibleInventoryFeatureItems: [{ key: 'stock' }] }))
assert.equal(vm.runInNewContext(gate, { item, visibleInventoryFeatureItems: [] }), 0)
assert.equal(vm.runInNewContext(gate, { item: { key: 'hang', submenu: item.submenu }, visibleInventoryFeatureItems: [{ key: 'stock' }] }), false)

console.log('INVENTORY_NAVIGATION_PERMISSION_CONTRACT_OK')
