import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createInventoryApi, createPlatformInventoryApi } from '../../../前端代码/inventory-vue3/src/services/inventoryApi.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const modal = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue'), 'utf8')
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
check('request dialog omits scan entry and cost estimates that do not belong to a request', modal.includes("v-if=\"['inbound', 'outbound'].includes(pageKey)\"")
  && modal.includes("if (props.pageKey === 'request') return [...product, '当前库存', '申请数量']")
  && !modal.includes("pageKey === 'request' ? '预计金额由服务端按仓库成本快照计算'"))
check('request date uses one native calendar control', modal.includes('<input v-model="requestDate" type="date" />')
  && !modal.includes('<CalendarDays :size="16" /><input v-model="requestDate" type="date" />'))
check('other inventory documents reuse the request-style inline document header layout',
  modal.includes("<section v-if=\"pageKey === 'inbound' || pageKey === 'outbound'\" class=\"form-grid\">")
  && modal.includes('<label class="form-grid__inline form-grid__full"><span class="field-label">备注</span><input v-if="pageKey === \'inbound\'"')
  && modal.includes('<section v-else-if="pageKey === \'count\'" class="form-grid form-grid--single"><label class="form-grid__inline form-grid__full">')
  && modal.includes('<span class="field-label">调出方<i>*</i></span>')
  && modal.includes('<span class="field-label">调入方<i>*</i></span>')
  && modal.includes('<span class="field-label">领用人</span>'))
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
check('platform store filters reuse the searchable store selector', app.includes('<InventoryStoreSelector v-model="platformStoreSelection"')
  && app.includes("{ key: '0', name: '全部授权门店', type: 'ALL' }"))
check('stock search preserves the toolbar keyword and inbound rows expose outbound trace', app.includes("typeof query?.keyword === 'string' ? query.keyword.trim()")
  && app.includes('openInboundOutboundDetails')
  && app.includes('>出库明细</button>'))

console.log(`REQUEST_DIALOG_CONTRACT_RESULT passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
