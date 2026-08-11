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
    && detail.includes('balancePrincipalRefundAmount: balancePrincipalAmount')
    && detail.includes('balanceGiftRefundAmount: balanceGiftAmount')
    && center.includes('actualRefundAmount: payload.actualRefundAmount')
    && center.includes('balancePrincipalRefundAmount: payload.balancePrincipalRefundAmount')
    && center.includes('balanceGiftRefundAmount: payload.balanceGiftRefundAmount'))

ok('游客退款不展示余额退回字段且强制按零提交',
  detail.includes('const isGuestOrder = computed(')
    && detail.includes('v-if="!isGuestOrder"')
    && detail.includes("const balancePrincipalAmount = isGuestOrder.value ? '0'")
    && detail.includes("const balanceGiftAmount = isGuestOrder.value ? '0'"))

ok('实际现金退款允许为零，但三类退款合计必须大于零且原因必填',
  detail.includes('isValidMoney(refundAmount.value, true)')
    && detail.includes('isValidMoney(balancePrincipalAmount, true)')
    && detail.includes('isValidMoney(balanceGiftAmount, true)')
    && detail.includes('moneyCents(balanceGiftAmount) <= 0')
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

ok('关联业务状态只显示中文，不泄漏 succeeded 等内部状态码',
  detail.includes("const statusLabel = pickValue(record, ['statusLabel', 'status_label'])")
    && detail.includes("succeeded: '已完成'")
    && detail.includes("result_unknown: '结果未知'")
    && detail.includes('test(status)'))

ok('命令键只在权威终态释放，结果未知保留原键',
  center.includes("return ['success', 'succeeded', 'failed', 'conflict'].includes(String(status || ''))")
    && center.includes('isTerminalActionStatus(actionStatus(result))')
    && !/isTerminalActionStatus[\s\S]{0,160}result_unknown/.test(center))

const shell = readFileSync(new URL('../../../前端代码/cashier-v3/src/layouts/CashierShell.vue', import.meta.url), 'utf8')
const workbench = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const bridge = readFileSync(new URL('../../../前端代码/cashier-v3/src/services/cashierV3Bridge.js', import.meta.url), 'utf8')
const debtPreparationStart = shell.indexOf('const prepareAction = payload.rechargeDebt === true')
const debtHandoffStart = shell.indexOf("await requestCashierV3Action('open-cashier-workbench', { silent: true })", debtPreparationStart)
ok('用户确认还款后才从权威工作台草稿接力到收银结账页',
  debtPreparationStart >= 0
    && debtHandoffStart > debtPreparationStart
    && shell.includes('const preparedCheckout = state.cashier?.checkout')
    && shell.includes("preparedCheckout.businessType === 'debt_repayment'")
    && shell.includes("String(preparedCheckout.preparationRequestId || '') === preparationRequestId")
    && shell.indexOf('openDebtRepaymentCheckout(preparationRequestId, payload.debtRecordId)', debtPreparationStart) > debtPreparationStart
    && shell.includes("window.sessionStorage.setItem('cashier-v3:prepared-checkout-handoff'")
    && shell.includes("window.location.hash = '#/cashier'")
    && workbench.includes("function consumePreparedCheckoutHandoff()")
    && workbench.includes("void openPreparedCheckout({ detail: handoff })"))

ok('同一工作台根状态刷新保留已读取的欠款资源版本',
  bridge.includes('function replaceCashierV3PublicVersionStore(stateContextId, versions = null)')
    && bridge.includes('if (currentPublicVersionContextId() !== contextId)')
    && bridge.includes('下一条写命令'))

ok('订单中心欠款管理使用独立顶层页签并可进入已有统一还款流程',
  center.includes("key: 'debt'")
    && center.includes("label: '欠款管理'")
    && center.includes("activeTabKey === 'debt' && record.canRepay")
    && center.includes("cashier-v3:open-member-debt-repayment"))

ok('欠款管理直达入口仅预选欠款，用户下一步后才创建 Checkout 草稿',
  shell.includes('async function handleOpenMemberDebt(event = {})')
    && shell.includes('initialDebtRecordId.value = isSucceededResult(result) ? debtId : \'\'')
    && !/async function handleOpenMemberDebt[\s\S]{0,700}prepareDebtRepayment\(/.test(shell)
    && center.includes("record.sourceType === '销售订单'"))

process.stdout.write(`ASSERT_PASSED=${passed}\n`)
process.stdout.write('ASSERT_FAILED=0\n')
process.stdout.write('ORDER_LIFECYCLE_FRONTEND=PASS\n')
