import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { requestOrderVoidReceipt } from '../../../前端代码/cashier-v3/src/services/orderVoidReceiptRecovery.js'
import { entitlementCheckoutRequestId } from '../../../前端代码/cashier-v3/src/services/cashierV3OrderProjectionContract.js'

// 一条权益服务也按所属结账组提交；异常或冲突身份不得退化为单条作废。
assert.equal(entitlementCheckoutRequestId({ entitlementOnly: true, checkoutRequestId: 'CKR-one', id: 'service:CKR-one', items: [{ serviceFactId: 1 }] }), 'CKR-one')
assert.equal(entitlementCheckoutRequestId({ entitlementOnly: true, id: 'service:CKR-old', items: [{ serviceFactId: 1 }] }), 'CKR-old')
assert.equal(entitlementCheckoutRequestId({ entitlementOnly: true, checkoutRequestId: 'CKR-one', id: 'service:CKR-other' }), '')
assert.equal(entitlementCheckoutRequestId({ entitlementOnly: true, id: 'service:bad:identity' }), '')
assert.equal(entitlementCheckoutRequestId({ entitlementOnly: false, id: 'service:CKR-one' }), '')

// 模拟提交已成功但传输断开，验证恢复始终沿用同一个命令对象和幂等键。
const payload = { command: { idempotencyKey: 'same-key' }, checkoutRequestId: 'CKR-test', orderId: 'service:CKR-test' }
const success = { result: { status: 'success' }, replay: true }
for (const first of ['throw', 'invalid', 'success']) {
  const calls = []
  const adapter = { request: async (action, sent) => {
    calls.push(sent)
    assert.equal(action, 'void-service-record')
    if (calls.length === 1 && first === 'throw') throw new Error('connection lost after commit')
    if (calls.length === 1 && first === 'invalid') return {}
    return success
  } }
  assert.equal(await requestOrderVoidReceipt(adapter, 'void-service-record', payload, value => value?.result?.status === 'success'), success)
  assert.equal(calls.length, first === 'success' ? 1 : 2)
  assert.ok(calls.every(value => value === payload))
}
let attempts = 0
await assert.rejects(requestOrderVoidReceipt({ request: async () => { attempts++; throw new Error('offline') } }, 'void-service-record', payload, () => false), /offline/)
assert.equal(attempts, 2)
console.log('PASS: same-command receipt recovery; valid response no retry; bounded failure')
const view = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue', import.meta.url), 'utf8')
assert.doesNotMatch(view, />修改手艺人<|打印服务小票|printServiceRecord|serviceRecordPrintKey/)
assert.match(view, /openRecordDetail\(record\)">详情/)
assert.match(view, /'生成中…' : '打印'/)
assert.match(view, /activeTabKey === 'service' \? '详情' : '查看详情'/)
assert.match(view, /requestAction\('void-service-record', \{[\s\S]*?checkoutRequestId,[\s\S]*?orderId: `service:\$\{checkoutRequestId\}`,[\s\S]*?reason,[\s\S]*?idempotencyKey/)
console.log('PASS: service action removal, short labels, checkout-group-only payload')
