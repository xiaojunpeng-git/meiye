import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8')

const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const center = read('前端代码/cashier-v3/src/views/ManagementCenterView.vue')

assert.match(shell, /const managementFeatureItems = \[/)
assert.match(shell, /label: '人员管理', to: \{ name: 'cashier-v3-staff-list' \}/)
assert.match(shell, /label: '房间设置', to: \{ name: 'cashier-v3-room-settings' \}/)
assert.match(shell, /cashier-management-grid/)
assert.match(shell, /aria-label="管理功能"/)
assert.doesNotMatch(center, /工程管理/)
assert.doesNotMatch(center, /management-center-tabs/)
assert.match(center, /<StaffListView \/>/)

console.log('MANAGEMENT_NAVIGATION_CONTRACT_OK')
