import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const source = readFileSync(new URL('../../../前端代码/admin/src/store/modules/admin/modules/menu.js', import.meta.url), 'utf8')
const route = readFileSync(new URL('../../../前端代码/admin/src/router/modules/stockManage.js', import.meta.url), 'utf8')
const migration = readFileSync(new URL('../../../后端代码/database/upgrades/2026-10-13-库存仓库设置入口恢复/01-正式升级.sql', import.meta.url), 'utf8')

// 导航只恢复服务端已授权的菜单节点，不在前端合成入口或放宽路由权限。
assert.doesNotMatch(source, /hideWarehouseSettingsMenu/)
assert.match(source, /normalizeProductBusinessConfigMenu\(/)
assert.match(route, /inventory-v3-platform-warehouse-settings/)
assert.match(route, /inventory-v3-platform-warehouse-manage/)
assert.match(migration, /SET `is_show` = 1/)
assert.match(migration, /AND `unique_auth` = 'inventory-v3-platform-warehouse-settings'/)
assert.match(migration, /AND `menu_path` = '\/admin\/stock\/warehouse'/)
assert.doesNotMatch(migration, /system_role|INSERT INTO|DELETE FROM/i)

console.log('warehouse-settings-entry-contract: PASS')
