import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const detail = readFileSync(new URL('../../../前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue', import.meta.url), 'utf8')
const center = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue', import.meta.url), 'utf8')

let passed = 0
function ok(label, check) {
  assert.ok(check, label)
  passed += 1
  process.stdout.write(`[PASS] ${label}\n`)
}

ok('销售退款分别采集实际退款、本金退回、赠金退回和原因',
  detail.includes('实际退款金额')
    && detail.includes('余额本金退回金额')
    && detail.includes('赠金退回金额')
    && detail.includes('退款原因'))

ok('实际退款金额保持退款统计口径，本金和赠金仅作为账户冲销字段',
  detail.includes('refundAmount: refundAmount.value')
    && detail.includes('actualRefundAmount: refundAmount.value')
    && detail.includes('balancePrincipalRefundAmount: balancePrincipalRefundAmount.value')
    && detail.includes('balanceGiftRefundAmount: balanceGiftRefundAmount.value')
    && center.includes('actualRefundAmount: payload.actualRefundAmount')
    && center.includes('balancePrincipalRefundAmount: payload.balancePrincipalRefundAmount')
    && center.includes('balanceGiftRefundAmount: payload.balanceGiftRefundAmount'))

ok('实际现金退款允许为零，但三类退款合计必须大于零且原因必填',
  detail.includes('isValidMoney(refundAmount.value, true)')
    && detail.includes('isValidMoney(balancePrincipalRefundAmount.value, true)')
    && detail.includes('isValidMoney(balanceGiftRefundAmount.value, true)')
    && detail.includes('moneyCents(balanceGiftRefundAmount.value) <= 0')
    && detail.includes("if (!refundReason.value.trim())"))

ok('作废只提交可追溯原因且原因必填',
  detail.includes("if (!voidReason.value.trim())")
    && detail.includes("runAction('void-sales-order', { reason: voidReason.value })"))

ok('销售订单只保留一个重开入口并直接加载购物车',
  detail.includes("{ action: 'reopen-sales-order', label: '重开' }")
    && !detail.includes("key: 'reopen', label: '重开'")
    && center.includes("action === 'reopen-sales-order'")
    && center.includes('businessData.orderLifecycle?.cashierDraft'))

ok('明确失败、冲突与结果未知均在订单详情可见',
  detail.includes("['failed', 'conflict', 'result_unknown'].includes(status)")
    && detail.includes('role="alert"'))

ok('命令键只在权威终态释放，结果未知保留原键',
  center.includes("return ['success', 'succeeded', 'failed', 'conflict'].includes(String(status || ''))")
    && center.includes('isTerminalActionStatus(actionStatus(result))')
    && !/isTerminalActionStatus[\s\S]{0,160}result_unknown/.test(center))

process.stdout.write(`ASSERT_PASSED=${passed}\n`)
process.stdout.write('ASSERT_FAILED=0\n')
process.stdout.write('ORDER_LIFECYCLE_FRONTEND=PASS\n')
