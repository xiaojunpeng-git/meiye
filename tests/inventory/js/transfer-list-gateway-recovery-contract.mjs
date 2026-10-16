import assert from 'node:assert/strict'
import { createPlatformInventoryApi } from '../../../前端代码/inventory-vue3/src/services/inventoryApi.js'

const recoveredCalls = []
const recoveredApi = createPlatformInventoryApi({
  fetchImpl: async (url, options) => {
    recoveredCalls.push({ url, options })
    if (recoveredCalls.length < 3) {
      return { ok: false, status: recoveredCalls.length === 1 ? 502 : 503, text: async () => '<html>gateway unavailable</html>' }
    }
    return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: { total: 1, list: [{ transfer_no: 'DB2610160136' }] } }) }
  }
})
const recovered = await recoveredApi.listHqCrossTransfers({ status: 'DISPATCHED' })
assert.equal(recoveredCalls.length, 3)
assert.ok(recoveredCalls.every((call) => call.options.method === 'GET' && call.url.endsWith('/v3/hq/cross-transfer?status=DISPATCHED')))
assert.equal(recovered.list[0].transfer_no, 'DB2610160136')

let failedReads = 0
const unavailableApi = createPlatformInventoryApi({
  fetchImpl: async () => {
    failedReads += 1
    return { ok: false, status: 502, text: async () => '<html>gateway unavailable</html>' }
  }
})
await assert.rejects(unavailableApi.listHqCrossTransfers(), /库存服务暂时不可用，请点击查询重试。/)
assert.equal(failedReads, 3)

let rejectedReads = 0
const rejectedApi = createPlatformInventoryApi({
  fetchImpl: async () => {
    rejectedReads += 1
    return { ok: false, status: 403, text: async () => JSON.stringify({ status: 403, msg: '无调拨查询权限' }) }
  }
})
await assert.rejects(rejectedApi.listHqCrossTransfers(), /无调拨查询权限/)
assert.equal(rejectedReads, 1)

let commandCalls = 0
const ambiguousCommandApi = createPlatformInventoryApi({
  fetchImpl: async () => {
    commandCalls += 1
    return { ok: false, status: 502, text: async () => '<html>gateway unavailable</html>' }
  }
})
await assert.rejects(ambiguousCommandApi.createHqCrossTransferBatch({ idempotency_key: 'transfer-batch-1-contract', drafts: [] }), /调拨结果尚未确认，请先查询调拨单状态，勿重复保存。/)
assert.equal(commandCalls, 1)

let interruptedCommandCalls = 0
const interruptedCommandApi = createPlatformInventoryApi({
  fetchImpl: async () => {
    interruptedCommandCalls += 1
    throw new TypeError('connection closed')
  }
})
await assert.rejects(interruptedCommandApi.createHqCrossTransferBatch({ idempotency_key: 'transfer-batch-1-contract', drafts: [] }), /调拨结果尚未确认，请先查询调拨单状态，勿重复保存。/)
assert.equal(interruptedCommandCalls, 1)

console.log('PASS transfer list retries gateway reads and never replays a transfer command')
