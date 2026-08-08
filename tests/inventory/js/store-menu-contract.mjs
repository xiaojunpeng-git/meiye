import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const shellPath = path.join(root, '前端代码/cashier-v3/src/layouts/CashierShell.vue')
const cashierBridgePath = path.join(root, '前端代码/cashier-v3/src/services/cashierV3Bridge.js')
const inventoryAppPath = path.join(root, '前端代码/inventory-vue3/src/App.vue')
const inventoryModalPath = path.join(root, '前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue')
const inventoryOperationalToolbarPath = path.join(root, '前端代码/inventory-vue3/src/components/InventoryOperationalUnifiedQueryToolbar.vue')
const unifiedQueryToolbarPath = path.join(root, '前端代码/shared/unified-query-vue3/src/components/UnifiedQueryToolbar.vue')
const inventoryBatchContractPath = path.join(root, '后端代码/app/services/product/inventory/query/InventoryBatchStockQueryContract.php')
const source = fs.readFileSync(shellPath, 'utf8')
const cashierBridge = fs.readFileSync(cashierBridgePath, 'utf8')
const inventoryApp = fs.readFileSync(inventoryAppPath, 'utf8')
const inventoryModal = fs.readFileSync(inventoryModalPath, 'utf8')
const inventoryOperationalToolbar = fs.readFileSync(inventoryOperationalToolbarPath, 'utf8')
const unifiedQueryToolbar = fs.readFileSync(unifiedQueryToolbarPath, 'utf8')
const inventoryBatchContract = fs.readFileSync(inventoryBatchContractPath, 'utf8')

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
    && source.includes(":18088/view_inventory_v3/")
    && source.includes("source: 'store'"))
check('embedded inventory always receives the current cashier session after a store switch',
  source.includes("queryParameters.set('parent_origin', window.location.origin)")
    && source.includes("import { readStoreV3SessionToken } from '@/services/storeV3SessionToken'")
    && source.includes('const token = readStoreV3SessionToken()')
    && source.includes("type: 'cashier-v3:inventory-session'")
    && inventoryApp.includes("type: 'cashier-v3:inventory-session-request'"))
check('a late embedded session refreshes store scope dashboard and the active inventory list',
  inventoryApp.includes("const wasWaiting = typeof embeddedSessionReady === 'function'")
    && inventoryApp.includes('if (!wasWaiting)')
    && inventoryApp.includes('else loadStoreSessionContext()')
    && inventoryApp.includes('loadDashboard()')
    && inventoryApp.includes("active.value !== 'overview' && active.value !== 'statistics'"))
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
check('current cashier entries remain individually gated and omit the closed movement entry',
  ['首页', '入库', '出库', '库存', '盘点', '统计', '请货', '调拨', '院装', '导入']
    .every((label) => source.includes(`label: '${label}'`))
    && ['overview', 'inbound', 'outbound', 'stock', 'count', 'statistics', 'request', 'transfer', 'usage', 'import']
      .every((key) => source.includes(`featureCode: 'cashier.v3.inventory.${key}'`))
    && !source.includes("key: 'movement', label: '明细'")
    && !source.includes("label: '项目配方', href: resolveInventoryEntryHref('recipe')")
    && source.includes('const inventoryHomeHref = resolveInventoryEntryHref()')
    && inventoryApp.includes('function applyRequestedPage()')
    && inventoryApp.includes("['store', 'platform'].includes(new URLSearchParams(window.location.search).get('source'))")
    && !inventoryApp.includes("key: 'movement', label:")
    && inventoryApp.includes("key: 'transfer'"))
check('login bootstrap preserves every server-granted inventory permission after reload',
  [
    'cashier.v3.inventory.overview', 'cashier.v3.inventory.inbound', 'cashier.v3.inventory.outbound',
    'cashier.v3.inventory.stock', 'cashier.v3.inventory.count', 'cashier.v3.inventory.movement',
    'cashier.v3.inventory.statistics', 'cashier.v3.inventory.request', 'cashier.v3.inventory.transfer',
    'cashier.v3.inventory.usage', 'cashier.v3.inventory.import'
  ].every((featureCode) => cashierBridge.includes(`'${featureCode}': false`)
    && cashierBridge.includes(`'${featureCode}': true`)))
check('store inventory scope derives its displayed store name from the trusted warehouse response',
  inventoryApp.includes('const storeLocations = ref([])')
    && inventoryApp.includes("inventoryApi.list('storeLocations')")
    && inventoryApp.includes('store_name_snapshot || current?.store_name || current?.location_name')
    && !inventoryApp.includes('锦江门店')
    && !inventoryApp.includes('王晓雯'))
check('inventory rows no longer forge a store operator and transfer is explicitly cross-store',
  !inventoryApp.includes('王晓雯')
    && !inventoryApp.includes("text(row.operator_name || row.admin_name || row.staff_name, '—')")
    && inventoryApp.includes('row.store_name_label || row.store_name || row.store_name_snapshot || row.location_name || scopeName.value')
    && inventoryModal.includes('调入门店')
    && inventoryModal.includes('收货时入账')
    && inventoryModal.includes('crossTransferIncomingRequests'))
check('unimplemented exports are not exposed as clickable actions',
  !inventoryApp.includes('>导出当前报表</button>')
    && !inventoryApp.includes('>导出</button>')
    && inventoryApp.includes('导出将在对应权威导出任务接入后开放'))
check('inventory stock settings are owned by the shared toolbar and the stock subject stays in the same query region',
  inventoryApp.includes('<div class="heading-actions"><button v-if="currentPage.action"')
    && !inventoryApp.includes('active === \'stock\' || (mode === \'store\' && operationalQueryPage)')
    && inventoryApp.includes('ref="stockQueryToolbar"')
    && inventoryApp.includes(':show-keyword-search="false"')
    && inventoryApp.includes(':show-settings-button="true"')
    && inventoryApp.includes('<template #context-actions>')
    && inventoryApp.includes('<div v-if="mode === \'platform\'" class="inventory-query-subject">')
    && inventoryOperationalToolbar.includes('defineExpose({ openSettings })')
    && inventoryOperationalToolbar.includes(':show-settings-button="false"')
    && unifiedQueryToolbar.includes('showSettingsButton')
    && unifiedQueryToolbar.includes('showKeywordSearch'))
check('inventory query errors render only real messages and list filters do not expose scan entry',
  inventoryApp.includes("const stockQueryLoadError = computed(() => String(stockUnifiedQuery.loadError.value || '').trim())")
    && inventoryApp.includes('v-if="stockQueryLoadError" class="inventory-load-error"')
    && !inventoryApp.includes('>扫码录入</button>')
    && inventoryModal.includes('>扫码添加</button>')
    && inventoryModal.includes('scannerVisible'))
check('inventory stock defaults to product name and a real numeric quantity range',
  inventoryApp.includes("defaultQuick: ['product_name', 'available_quantity'].includes(key)")
    && inventoryApp.includes("quickRange: key === 'available_quantity'")
    && unifiedQueryToolbar.includes("operator: 'gte'")
    && unifiedQueryToolbar.includes("operator: 'lte'")
    && unifiedQueryToolbar.includes('unified-query-top-field__range')
    && unifiedQueryToolbar.includes('validateQuickRanges()')
    && inventoryBatchContract.includes("self::field('product_name', '商品名称', 'text', true, true)")
    && inventoryBatchContract.includes("self::field('available_quantity', '数量范围', 'decimal', false, true"))
check('permission-filtered inventory cost does not disable safe unified-query capabilities',
  inventoryApp.includes("['batch_unit_cost', '批次单位成本', 'amount', true]")
    && inventoryApp.includes('permissionFiltered = false')
    && fs.readFileSync(path.join(root, '前端代码/shared/unified-query-vue3/src/composables/useUnifiedQueryPage.js'), 'utf8')
      .includes('filter((field) => field.permissionFiltered !== true)'))
check('embedded platform pages hide the duplicate inventory navigation and narrow stock only by store',
  inventoryApp.includes('const isEmbeddedEntry')
    && inventoryApp.includes("['store', 'platform'].includes")
    && inventoryApp.includes('const platformStoreId = ref(0)')
    && inventoryApp.includes("? { storeId: Number(platformStoreId.value) }")
    && !inventoryApp.includes('platformLocationId'))
check('platform HQ operations bind catalog, inbound, and transfer to one selected headquarters location',
  inventoryApp.includes('const platformHqLocationId = ref(0)')
    && inventoryApp.includes('const platformHqLocations = computed')
    && inventoryApp.includes('listHqInbound({ hq_location_id: Number(platformHqLocationId.value)')
    && inventoryApp.includes('listHqCrossTransfers({ hq_location_id: Number(platformHqLocationId.value)')
    && inventoryModal.includes('catalogQuery = computed(() => isPlatformHeadquarters.value')
    && inventoryModal.includes('createHqInbound({ ...payload, hq_location_id: Number(props.hqLocationId) })')
    && inventoryModal.includes('createHqCrossTransfer({ ...payload, hq_location_id: Number(props.hqLocationId) })'))
check('request detail and edit are wired to the V3 authority and import accepts a dropped xlsx file',
  inventoryApp.includes('async function openRequestDetail')
    && inventoryApp.includes('async function openRequestEditor')
    && inventoryModal.includes('request-edit')
    && inventoryModal.includes('dropImportFile')
    && inventoryModal.includes('@drop.prevent="dropImportFile"'))

console.log(`INVENTORY_STORE_MENU_RESULT passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
