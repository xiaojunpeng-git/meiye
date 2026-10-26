import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const workspace = fs.readFileSync(
  path.join(root, '前端代码/admin/src/pages/store/region/workspace/index.vue'),
  'utf8',
)
const menus = fs.readFileSync(
  path.join(root, '前端代码/admin/src/store/modules/admin/modules/menus.js'),
  'utf8',
)
const menuState = fs.readFileSync(
  path.join(root, '前端代码/admin/src/store/modules/admin/modules/menu.js'),
  'utf8',
)
const menuNormalization = fs.readFileSync(
  path.join(root, '前端代码/admin/src/libs/organizationWorkspaceMenu.js'),
  'utf8',
)

const assertions = [
  ['top-level navigation separates stores and employees', workspace.includes("{ key: 'stores', label: '门店'")
    && workspace.includes("{ key: 'employees', label: '员工'")],
  ['legacy inner store/person segmented control is removed', !workspace.includes('>按门店</button>')
    && !workspace.includes('>按人员</button>')],
  ['store and employee list panes are selected by the top-level tab', workspace.includes("activeTab === 'stores' || activeTab === 'employees'")
    && workspace.includes("activeTab === 'employees' ? '搜索姓名或手机号' : '搜索门店'")],
  ['existing people deep links now open the employee tab', workspace.includes("tab === 'people' || tab === 'employees'")
    && workspace.includes("this.activeTab = 'employees';\n        this.dataView = 'people';")],
  ['store and employee loads retain independent cache markers', workspace.includes('tabLoaded: { stores: false, employees: false, permissions: false, logs: false }')
    && workspace.includes('this.tabLoaded.stores = true;')
    && workspace.includes('this.tabLoaded.employees = true;')],
  ['legacy store and people entries keep original list routes in both menu data flows', menuNormalization.includes('export function normalizeOrganizationWorkspaceMenu(menuData, prefix)')
    && menuNormalization.includes("item.title || item.menu_name")
    && menuNormalization.includes("titleOf(item) === '人员管理'")
    && menuNormalization.includes("titleOf(item) === '门店管理'")
    && menuNormalization.includes("normalized.path = `${prefix}/store/store/index`;")
    && menuNormalization.includes("path.endsWith('/supplier/menu/list')")
    && menuNormalization.includes("path.endsWith('/supplier')")
    && !menuNormalization.includes('normalized.path = `${workspacePath}?tab=stores`;')
    && !menuNormalization.includes('normalized.path = `${workspacePath}?tab=people`;')
    && menuNormalization.includes("normalized.path = `${prefix}/setting/staff/index`;")
    && menuNormalization.includes("normalized.path = `${prefix}/store/system/base`;")
    && menus.includes('normalizeOrganizationWorkspaceMenu(withoutLegacyInventoryMovement(menuList), prefix)')
    && menuState.includes('return normalizeOrganizationWorkspaceMenu(filtered, Setting.roterPre);')],
]

let failed = 0
for (const [name, passed] of assertions) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.stdout.write(`ASSERT_FAILED=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
