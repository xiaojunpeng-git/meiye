import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const overlay = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/member/MemberDebtOverlay.vue'), 'utf8')
const recharge = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/member/RechargeOverlay.vue'), 'utf8')
const memberSummary = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/common/MemberSummaryCard.vue'), 'utf8')
const shell = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/layouts/CashierShell.vue'), 'utf8')
const workbench = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'), 'utf8')
const bridge = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/services/cashierV3Bridge.js'), 'utf8')
const manifest = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/services/cashierV3ActionManifest.js'), 'utf8')

for (const needle of ['selectedDebtIsRecharge', 'paymentLines', 'salespersonAllocations', '记账收款', '选择销售人', 'rechargeDebt']) {
  if (!overlay.includes(needle)) throw new Error(`overlay missing ${needle}`)
}
if (!overlay.includes('member-debt-overlay__payment-method-card')
  || !overlay.includes('selectedPaymentMethods.has(method.code)')) {
  throw new Error('充值欠款补交必须复用卡片式收款方式并禁用重复方式')
}
if (overlay.includes('<select v-model="line.paymentMethod">')) {
  throw new Error('充值欠款补交不得继续使用旧的行内收款方式下拉框')
}
if (!shell.includes('localRepayment: payload') || !workbench.includes("submit-recharge-debt-repayment")) {
  throw new Error('充值欠款补交必须以本地快照进入确认页并使用专用提交命令')
}
for (const source of [overlay, recharge]) {
  if (!source.includes('PersonnelPerformanceOverlay') || !source.includes(':show-craftsmen="false"') || !source.includes(':show-salespeople="true"')) {
    throw new Error('充值与补交必须复用购物车统一人员选择弹层')
  }
  if (source.includes('openCashierV3QueryEntitySelector') || source.includes('添加销售人') || source.includes('整数业绩金额')) {
    throw new Error('充值与补交不得保留独立销售人选择或手填业绩金额交互')
  }
  if (!source.includes('allocationWeight: Number(item.allocationWeight)')) {
    throw new Error('充值与补交必须提交购物车同口径的整数分配比例')
  }
  if (!source.includes('const envelope = response?.data?.result ? response.data : response')
    || !source.includes('const data = envelope?.data || {}')
    || !source.includes("const status = String(envelope?.result?.status || '')")) {
    throw new Error('充值与补交必须按购物车相同方式解包标准 action 信封')
  }
  if (source.includes('Math.trunc(Number(item.amount')) {
    throw new Error('充值与补交不得把收款小数金额静默截断为整数')
  }
}
if (shell.includes("'prepare-recharge-debt-repayment'") || shell.includes("requestCashierV3Action('submit-recharge-debt-repayment'")) {
  throw new Error('下一步不得创建充值欠款编辑草稿或直接收款')
}
if (!workbench.includes('rechargeDebt: intent.rechargeDebt === true')
  || !workbench.includes('repaymentAmount: amount')) {
  throw new Error('本次充值欠款金额与类型必须由页面唯一快照携带')
}
if (!workbench.includes('isRechargeDebtRepaymentCheckout')
  || !workbench.includes("'submit-checkout': isRechargeDebtRepaymentCheckout.value ? 'submit-recharge-debt-repayment'")) {
  throw new Error('充值欠款补交必须使用专用业务事件提交命令')
}
if (!workbench.includes("requestAction('query-debt-repayment-result'")
  || !workbench.includes('originalIdempotencyKey: commandKey')) {
  throw new Error('结果未知只能查询原确认命令，不能生成新补交请求')
}
if (!memberSummary.includes("const emit = defineEmits(['select', 'open-detail', 'open-debt', 'go-writeoff'])")
  || !memberSummary.includes("@click.stop=\"openMemberDebt(member)\"")
  || !memberSummary.includes("emit('open-debt', member?.id || member?.memberId || member?.uid || null)")) {
  throw new Error('member debt entry does not emit an explicit, isolated debt event')
}
if (!bridge.includes("action === 'submit-recharge-debt-repayment' && isRecord(payload?.checkoutSnapshot)")) {
  throw new Error('充值欠款最终快照不得继承会员余额展示版本')
}
if (!manifest.includes("'submit-recharge': FEATURE_RECHARGE") || !manifest.includes("'submit-recharge-debt-repayment': FEATURE_RECHARGE") || manifest.includes("'prepare-recharge-debt-repayment': FEATURE_RECHARGE")) {
  throw new Error('recharge commands are not registered in the frontend action manifest')
}
if (!overlay.includes('<div class="member-debt-overlay__recharge-salespeople" aria-label="销售人分配">')
  || overlay.includes('v-if="selectedDebt && selectedDebtIsRecharge" class="member-debt-overlay__recharge-salespeople"')) {
  throw new Error('销售订单欠款与充值欠款都必须显示同一个销售人选择入口')
}
if (!overlay.includes('salespersonAllocations: salespersonAllocations.value.map((item) => ({')) {
  throw new Error('所有补交类型都必须提交本次操作选择的销售人比例')
}
console.log('RECHARGE_DEBT_REPAYMENT_FRONTEND_CONTRACT_OK')
