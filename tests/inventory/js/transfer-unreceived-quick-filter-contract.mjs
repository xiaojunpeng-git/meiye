import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const root = new URL('../../../', import.meta.url)
const app = readFileSync(new URL('前端代码/inventory-vue3/src/App.vue', root), 'utf8')
const modal = readFileSync(new URL('前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', root), 'utf8')

// 快捷入口必须复用平台的 DISPATCHED 查询契约，不得把“全部”扩展成越权仓库范围。
const quickFilter = app.match(/async function showAllUnreceivedTransfers\(\) \{([\s\S]*?)\n\}/)?.[1] || ''
assert.match(quickFilter, /keyword\.value = ''/)
assert.match(quickFilter, /platformTransferFromParty\.value = 'ALL:0'/)
assert.match(quickFilter, /platformTransferToParty\.value = 'ALL:0'/)
assert.match(quickFilter, /platformTransferStatus\.value = 'DISPATCHED'/)
assert.match(quickFilter, /await loadCurrentList\(\)/)
assert.match(app, /placeholder="全部调入方"\s*\/><\/label><button class="secondary-button"[^>]*@click="showAllUnreceivedTransfers">未收货<\/button>/)
assert.match(app, /<option value="DISPATCHED">未收货<\/option>/)
assert.match(app, /platformTransferStatus\.value === 'DISPATCHED'/)
assert.match(app, /mode === 'platform' && active === 'transfer' && cellIndex === 5 \? transferStatusDisplay\(/)
assert.match(modal, /props\.pageKey === 'transfer' && isPlatformHeadquarters\.value/)

// 门店快捷入口必须从同一库存源码构建，且只提交后端权威的收货权限过滤。
const storeQuickFilter = app.match(/async function showStoreUnreceivedTransfers\(\) \{([\s\S]*?)\n\}/)?.[1] || ''
assert.match(storeQuickFilter, /storeTransferUnreceivedOnly\.value = !storeTransferUnreceivedOnly\.value/)
assert.match(storeQuickFilter, /storeOperationalStatus\.value = storeTransferUnreceivedOnly\.value \? '在途' : ''/)
assert.match(storeQuickFilter, /await queryStoreOperationalPage\(\)/)
assert.match(app, /active === 'transfer'[^>]*@click="showStoreUnreceivedTransfers">未到货<\/button>/)
assert.match(app, /field: 'can_receive', operator: 'eq', value: true/)
assert.match(app, /if \(active\.value === 'transfer'\) storeTransferUnreceivedOnly\.value = false/)

console.log('TRANSFER_UNRECEIVED_QUICK_FILTER_CONTRACT_OK')
