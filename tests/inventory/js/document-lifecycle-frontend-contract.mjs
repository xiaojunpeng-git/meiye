import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createInventoryApi, createPlatformInventoryApi } from '../../../前端代码/inventory-vue3/src/services/inventoryApi.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const app = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
let failed = 0
function check(name, condition) {
  console.log(`${condition ? 'PASS' : 'FAIL'} ${name}`)
  if (!condition) failed += 1
}

function recordingApi(factory, prefix) {
  const calls = []
  const api = factory({
    prefix,
    windowRef: { localStorage: { getItem: () => 'test-token' } },
    fetchImpl: async (url, options) => {
      calls.push({ url, options })
      return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: {} }) }
    }
  })
  return { api, calls }
}

const store = recordingApi(createInventoryApi, '/storeapi/product/inventory')
const command = { idempotency_key: 'inventory-lifecycle-001', reason: '录入错误' }
await store.api.voidInbound('in-1', command)
await store.api.voidOutbound('out-1', command)
await store.api.cancelRequest(8, command)
await store.api.terminateRequest(9, command)
await store.api.reverseCrossTransfer(10, command)
check('store lifecycle commands use separate protected authority endpoints',
  store.calls.map((call) => call.url).join('|') === [
    '/storeapi/product/inventory/v3/inbound/in-1/void',
    '/storeapi/product/inventory/v3/outbound/out-1/void',
    '/storeapi/product/inventory/v3/request/cancel/8',
    '/storeapi/product/inventory/v3/request/terminate/9',
    '/storeapi/product/inventory/v3/cross-transfer/10/reverse'
  ].join('|') && store.calls.every((call) => JSON.parse(call.options.body).reason === '录入错误'))

const platform = recordingApi(createPlatformInventoryApi, '/adminapi/product/inventory')
const hqCommand = { ...command, hq_location_id: 3 }
await platform.api.voidHqInbound('in-2', hqCommand)
await platform.api.voidHqOutbound('out-2', hqCommand)
await platform.api.cancelHqRequest(11, hqCommand)
await platform.api.terminateHqRequest(12, hqCommand)
await platform.api.reverseHqCrossTransfer(13, hqCommand)
check('platform lifecycle commands preserve the selected HQ location boundary',
  platform.calls.every((call) => JSON.parse(call.options.body).hq_location_id === 3)
  && platform.calls.map((call) => call.url).join('|') === [
    '/adminapi/product/inventory/v3/hq/inbound/in-2/void',
    '/adminapi/product/inventory/v3/hq/outbound/out-2/void',
    '/adminapi/product/inventory/v3/hq/request/11/cancel',
    '/adminapi/product/inventory/v3/hq/request/12/terminate',
    '/adminapi/product/inventory/v3/hq/cross-transfer/13/reverse'
  ].join('|'))

check('list actions are driven by server action flags for all four document kinds',
  app.includes('sourceRows[index].can_void')
  && app.includes('sourceRows[index].can_cancel')
  && app.includes('sourceRows[index].can_terminate')
  && app.includes('sourceRows[index].can_reverse'))
check('destructive lifecycle actions require a reason before confirmation',
  app.includes("['reverse', 'void', 'cancelRequest', 'terminateRequest'].includes(action)")
  && app.includes('placeholder="请填写至少 2 个字的原因"')
  && app.includes('idempotencyKey: requiresReason ? inventoryCommandIdempotencyKey()'))

console.log(`INVENTORY_DOCUMENT_LIFECYCLE_FRONTEND_RESULT failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
