import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createInventoryApi, createPlatformInventoryApi } from '../../../前端代码/inventory-vue3/src/services/inventoryApi.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const modal = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue'), 'utf8')
const requestController = fs.readFileSync(path.join(root, '后端代码/app/controller/store/product/inventory/InventoryStockRequest.php'), 'utf8')

if (!modal.includes('const commonPayload = { idempotency_key: transferIdempotencyKey()')
  || !modal.includes('await inventoryApi.createCrossTransfer(commonPayload)')
  || !modal.includes('await platformInventoryApi.createHqCrossTransfer({ ...commonPayload, source_party_type: type')) {
  throw new Error('跨店调拨必须区分门店旧契约和平台来源字段')
}
if (modal.includes("{ type: 'HQ', id: 0 }") || modal.includes('source_party_type: source.type')) {
  throw new Error('门店调拨不得伪造或发送平台来源字段')
}
const selector = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/components/InventoryStoreSelector.vue'), 'utf8')
const app = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
let passed = 0
let failed = 0
function check(name, condition) {
  if (condition) passed += 1
  else failed += 1
  console.log(`${condition ? 'PASS' : 'FAIL'} ${name}`)
}

const storeCalls = []
const storeApi = createInventoryApi({
  prefix: '/storeapi/product/inventory',
  fetchImpl: async (url, options) => {
    storeCalls.push({ url, options })
    return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: { list: [] } }) }
  }
})
await storeApi.requestCounterparties()
check('store request supplier directory is separate from transfer counterparties', storeCalls[0].url === '/storeapi/product/inventory/v3/request/counterparties' && storeCalls[0].options.method === 'GET')

const platformCalls = []
const platformApi = createPlatformInventoryApi({
  fetchImpl: async (url, options) => {
    platformCalls.push({ url, options })
    return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: { list: [] } }) }
  }
})
await platformApi.hqRequestCounterparties({ hq_location_id: 128 })
await platformApi.applyHqRequest({ hq_location_id: 128, idempotency_key: 'hq-request-001' })
check('platform request supplier directory is bound to the selected headquarters warehouse', platformCalls[0].url === '/adminapi/product/inventory/v3/hq/request/counterparties?hq_location_id=128')
check('platform request submission uses the dedicated headquarters command and preserves its warehouse boundary', platformCalls[1].url === '/adminapi/product/inventory/v3/hq/request/apply'
  && platformCalls[1].options.method === 'POST'
  && JSON.parse(platformCalls[1].options.body).hq_location_id === 128)
check('platform request page is fixed to the headquarters warehouse subject',
  app.includes("['inbound', 'outbound', 'count', 'request', 'transfer'].includes(active.value)"))

check('request dialog fixes the request party by scope and uses a searchable supplier control', modal.includes('<InventoryStoreSelector v-if="isPlatformHeadquarters" v-model="requestPartySelection"')
  && modal.includes('placeholder="总部仓（可切换门店）"')
  && modal.includes('<InventoryStoreSelector v-model="supplyPartySelection"')
  && modal.includes(":placeholder=\"isPlatformHeadquarters ? '搜索供货门店' : '搜索总部仓或供货门店'\"")
  && modal.includes('item.party_id ?? item.id')
  && !modal.includes('请货门店<input'))
check('request dialog keeps all request labels inline and explains batch deduction in user language', modal.includes('class="form-grid__inline form-grid__full"')
  && modal.includes('系统会优先扣减最早到期的可用批次')
  && !modal.includes('FEFO'))
check('request dialog omits scan entry and cost estimates that do not belong to a request', modal.includes("v-if=\"!isUsageReturn && ['inbound', 'outbound'].includes(pageKey)\"")
  && modal.includes("if (props.pageKey === 'request') return [...product, '当前库存', '申请数量']")
  && !modal.includes("pageKey === 'request' ? '预计金额由服务端按仓库成本快照计算'"))
check('request date uses one native calendar control', modal.includes('<input v-model="requestDate" type="date" />')
  && !modal.includes('<CalendarDays :size="16" /><input v-model="requestDate" type="date" />'))
check('request submission explains when every selected product has zero supplier stock',
  modal.includes('function areAllRequestedProductsOutOfStock()')
  && modal.includes('所选商品在供货方库存均为 0，请更换供货方或选择有库存的商品。')
  && modal.includes('if (areAllRequestedProductsOutOfStock())'))
check('other inventory documents reuse the request-style inline document header layout',
  modal.includes("<section v-if=\"pageKey === 'inbound' || pageKey === 'outbound'\" class=\"form-grid\">")
  && modal.includes('<label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-if="pageKey === \'inbound\'"')
  && modal.includes('<section v-else-if="pageKey === \'count\'" class="form-grid form-grid--single"><label class="form-grid__inline form-grid__full">')
  && modal.includes('<span class="field-label">调出方<i>*</i></span>')
  && modal.includes('<span class="field-label">调入方<i>*</i></span>')
  && modal.includes('<span class="field-label">操作人</span>'))
check('store transfer binds responsibility to the current logged-in operator instead of showing an empty staff selector',
  modal.includes("{{ isPlatformHeadquarters ? '调拨人' : '当前操作人' }}")
  && modal.includes('<select v-if="isPlatformHeadquarters" v-model.number="transferStaffId"')
  && modal.includes('<input v-else value="当前登录人员" disabled />')
  && modal.includes('transfer_staff_id: Number(transferStaffId.value)')
  && modal.includes('await inventoryApi.createCrossTransfer(commonPayload)')
  && modal.includes("isPlatformHeadquarters && !transferStaffOptions.length"))
check('searchable supplier control filters options, opens without triggering a parent reload and does not allow arbitrary typed values', selector.includes('visibleOptions')
  && selector.includes("emit('update:modelValue', String(option.key || ''))")
  && selector.includes('没有可选供货方')
  && selector.includes('function toggle()')
  && selector.includes('store-selector__toggle')
  && selector.includes('store-selector__modal-trigger')
  && !selector.includes("defineEmits(['update:modelValue', 'focus'])")
  && !modal.includes('@focus="loadSupplyParties"'))
check('inbound and outbound scan entry resolves one exact barcode into the item list', modal.includes('async function addScannedProduct()')
  && modal.includes('findCatalogByBarcode')
  && !modal.includes("String(row?.barcode || '').trim() === barcode")
  && modal.includes('@keyup.enter="addScannedProduct"'))
check('requester defaults from the authenticated server session but remains editable without replacing the operator audit',
  modal.includes('async function loadRequester()')
  && modal.includes('requesterSelection.value = String(response?.default_key || requesterOptions.value[0]?.key || \'\')')
  && modal.includes('requesterName.value = String(response?.requester_name || requesterOptions.value[0]?.name || \'\')')
  && modal.includes('select v-model="requesterSelection"')
  && modal.includes('requestRequester()')
  && modal.includes('hqRequestRequester('))
check('store request controller retains the requester name submitted by the dialog',
  (requestController.match(/\['requester_name',''\]/g) || []).length === 2)
check('platform store filters reuse the searchable store selector', app.includes('<InventoryStoreSelector v-model="platformStoreSelection"')
  && app.includes("{ key: '0', name: '全部授权门店', type: 'ALL' }"))
check('stock search preserves the toolbar keyword and inbound rows expose outbound trace', app.includes("typeof query?.keyword === 'string' ? query.keyword.trim()")
  && app.includes('openInboundOutboundDetails')
  && app.includes('>出库明细</button>'))

console.log(`REQUEST_DIALOG_CONTRACT_RESULT passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
