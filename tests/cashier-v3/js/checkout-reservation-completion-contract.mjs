import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const workbench = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root), 'utf8')
const bridge = fs.readFileSync(new URL('前端代码/cashier-v3/src/services/cashierV3Bridge.js', root), 'utf8')
const manifest = fs.readFileSync(new URL('前端代码/cashier-v3/src/services/cashierV3ActionManifest.js', root), 'utf8')

assert.match(
  workbench,
  /closeSucceededCheckoutAndRefreshWorkbench[\s\S]*?settledCheckoutAuthority[\s\S]*?cashierDraftHasUnresolvedCommand\.value = false[\s\S]*?inspectCheckoutReservationsAfterSuccess\(submissionResponse, settledCheckoutAuthority\)[\s\S]*?open-cashier-workbench/,
  '必须先完成结账成功收口并立即执行预约检查，再静默刷新空工作台'
)
assert.match(
  workbench,
  /settledCheckoutAuthority = Object\.freeze\([\s\S]*?checkoutLocalOutcome\.value\?\.checkoutRequestId[\s\S]*?checkoutLocalOutcome\.value\?\.salesOrderId/,
  '关闭成功结账层前必须保留服务端已投影的结账权威标识'
)
assert.match(
  workbench,
  /responseDataBlock\(submissionResponse\)\.checkoutSubmission[\s\S]*?submission\?\.salesOrder\?\.orderId \|\| settledCheckoutAuthority\?\.salesOrderId[\s\S]*?submission\?\.checkoutRequestId \|\| settledCheckoutAuthority\?\.checkoutRequestId/,
  '预约检查必须兼容原始提交回执和已投影的成功结账标识'
)
assert.match(
  workbench,
  /if \(!salesOrderId && !checkoutRequestId\) return/,
  '纯权益结账没有销售订单时仍必须继续预约检查'
)
assert.match(workbench, /该客户有未结束的预约记录，是否结束？/, '需要展示产品确认的提示语')
assert.match(
  workbench,
  /function keepCheckoutReservationsUnchanged\(\)[\s\S]*?checkoutReservationPrompt\.value = null/,
  '选择否只能关闭提示'
)
assert.doesNotMatch(
  workbench.match(/function keepCheckoutReservationsUnchanged\(\)[\s\S]*?\n\}/)?.[0] || '',
  /requestAction/,
  '选择否不得发送任何写请求'
)
assert.match(
  workbench,
  /completeCheckoutReservations\(\)[\s\S]*?requestAction\('complete-checkout-reservations'[\s\S]*?salesOrderId: prompt\.salesOrderId[\s\S]*?checkoutRequestId: prompt\.checkoutRequestId/,
  '选择是只携带结账回执标识执行服务端批量收口'
)
assert.match(
  workbench,
  /结账已成功，但预约记录未能结束，请重试。/,
  '预约失败提示必须明确原结账已成功'
)
assert.match(bridge, /action === 'complete-checkout-reservations'[\s\S]*?contexts: \[\]/, '浏览器不得决定预约批量写入集合')
assert.match(
  bridge,
  /serverDiscoverableContextAction[\s\S]*?'complete-checkout-reservations'[\s\S]*?!serverDiscoverableContextAction[\s\S]*?hasInvalidWriteContexts/,
  '结账后预约收口必须绕过浏览器空 contexts 门禁，由服务端根据销售订单反查并锁定预约'
)
assert.match(manifest, /'complete-checkout-reservations': FEATURE_CASHIER/, '写动作必须登记收银权限')
assert.match(manifest, /'query-checkout-unfinished-reservations': FEATURE_CASHIER/, '查询动作必须登记收银权限')

console.log('CHECKOUT_RESERVATION_COMPLETION_FRONTEND_CONTRACT=PASS')
