import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const shellPath = path.join(root, '前端代码/cashier-v3/src/layouts/CashierShell.vue')
const inventoryAppPath = path.join(root, '前端代码/inventory-vue3/src/App.vue')
const inventoryModalPath = path.join(root, '前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue')
const source = fs.readFileSync(shellPath, 'utf8')
const inventoryApp = fs.readFileSync(inventoryAppPath, 'utf8')
const inventoryModal = fs.readFileSync(inventoryModalPath, 'utf8')

let passed = 0
let failed = 0
function check(name, condition) {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
    return
  }
  failed += 1
  console.log(`FAIL ${name}`)
}

const inventoryIndex = source.indexOf("key: 'inventory'")
check('inventory menu is present in the shared store navigation', inventoryIndex >= 0)
check('inventory menu uses confirmed label and Vue3 entry',
  source.includes("label: '库存'") && source.includes('submenu: inventoryFeatureItems'))
check('inventory parent follows the cashier workbench gate while subentries have individual permissions',
  source.slice(inventoryIndex, inventoryIndex + 420).includes("featureCode: 'cashier.v3.cashier'")
    && source.includes('const visibleInventoryFeatureItems = computed(() => inventoryFeatureItems.filter((entry) => canUseFeature(entry.featureCode)))')
    && source.includes('v-for="entry in visibleInventoryFeatureItems"'))
check('inventory group is rendered inside the shared store shell navigation',
  source.includes('v-if="canUseFeature(item.featureCode) && item.submenu && visibleInventoryFeatureItems.length"')
    && source.includes('class="cashier-inventory-grid"')
    && source.includes(':href="entry.href"'))
check('inventory entry keeps production same-origin and local Vue3 development routes',
  source.includes('return `/view_inventory_v3/${query}`')
    && source.includes("VITE_INVENTORY_ENTRY_URL")
    && source.includes(":18086/view_inventory_v3/")
    && source.includes("source: 'store'"))
check('inventory menu defaults collapsed and expands a flat function grid when clicked',
  source.includes('const isInventoryMenuExpanded = ref(false)')
    && source.includes('function toggleInventoryMenu()')
    && source.includes('class="cashier-inventory-grid"')
    && source.includes('class="cashier-inventory-toggle"'))
check('inventory opens inside the store work area with the first permitted function selected by default',
  source.includes('function openInventoryWorkspace(entry = visibleInventoryFeatureItems.value[0])')
    && source.includes('activeInventoryFeatureKey.value = entry.key')
    && source.includes('class="cashier-inventory-workspace"')
    && source.includes('@click.prevent="openInventoryWorkspace()"')
    && source.includes('const inventoryEntryHref = computed(() => visibleInventoryFeatureItems.value[0]?.href || inventoryHomeHref)')
    && source.includes(':href="inventoryEntryHref"'))
check('flat inventory entries match the inventory app order and carry individual feature codes',
  ['首页', '入库', '出库', '库存', '盘点', '明细', '统计', '请货', '调拨', '院装', '导入']
    .every((label) => source.includes(`label: '${label}'`))
    && ['overview', 'inbound', 'outbound', 'stock', 'count', 'movement', 'statistics', 'request', 'transfer', 'usage', 'import']
      .every((key) => source.includes(`featureCode: 'cashier.v3.inventory.${key}'`))
    && !source.includes("label: '项目配方', href: resolveInventoryEntryHref('recipe')")
    && source.includes('const inventoryHomeHref = resolveInventoryEntryHref()')
    && inventoryApp.includes('function applyRequestedPage()')
    && inventoryApp.includes("get('source') === 'store'")
    && inventoryApp.includes("key: 'movement'"))
check('store inventory scope derives its displayed store name from the trusted warehouse response',
  inventoryApp.includes('const storeLocations = ref([])')
    && inventoryApp.includes("inventoryApi.list('storeLocations')")
    && inventoryApp.includes('store_name_snapshot || current?.store_name || current?.location_name')
    && !inventoryApp.includes('锦江门店')
    && !inventoryApp.includes('王晓雯'))
check('inventory rows no longer forge a store operator and transfer source shows a warehouse name',
  inventoryApp.includes("text(row.operator_name || row.admin_name || row.staff_name, '—')")
    && inventoryApp.includes('row.store_name_label || row.store_name || row.store_name_snapshot || row.location_name || scopeName.value')
    && inventoryModal.includes("defaultLocationName: { type: String, default: '' }")
    && inventoryModal.includes(':value="defaultLocationName || \'当前门店默认仓\'"'))
check('unimplemented exports are not exposed as clickable actions',
  !inventoryApp.includes('>导出当前报表</button>')
    && !inventoryApp.includes('>导出</button>')
    && inventoryApp.includes('导出将在对应权威导出任务接入后开放'))

console.log(`INVENTORY_STORE_MENU_RESULT passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
