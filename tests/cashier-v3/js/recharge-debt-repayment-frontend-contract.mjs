import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const overlay = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/member/MemberDebtOverlay.vue'), 'utf8')
const recharge = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/member/RechargeOverlay.vue'), 'utf8')
const memberSummary = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/common/MemberSummaryCard.vue'), 'utf8')
const shell = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/layouts/CashierShell.vue'), 'utf8')
const bridge = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/services/cashierV3Bridge.js'), 'utf8')
const manifest = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/services/cashierV3ActionManifest.js'), 'utf8')

for (const needle of ['selectedDebtIsRecharge', 'paymentLines', 'salespersonAllocations', '添加收款方式', '选择销售人', 'rechargeDebt']) {
  if (!overlay.includes(needle)) throw new Error(`overlay missing ${needle}`)
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
  if (source.includes('Math.trunc(')) {
    throw new Error('充值与补交不得把小数金额静默截断为整数')
  }
}
if (!shell.includes("requestCashierV3Action('submit-recharge-debt-repayment'")) {
  throw new Error('shell does not submit recharge repayment through the dedicated action')
}
if (!memberSummary.includes("const emit = defineEmits(['select', 'open-detail', 'open-debt', 'go-writeoff'])")
  || !memberSummary.includes("@click.stop=\"openMemberDebt(member)\"")
  || !memberSummary.includes("emit('open-debt', member?.id || member?.memberId || member?.uid || null)")) {
  throw new Error('member debt entry does not emit an explicit, isolated debt event')
}
if (!bridge.includes("action === 'submit-recharge-debt-repayment'")) {
  throw new Error('bridge does not derive member and balance contexts for recharge repayment')
}
if (!bridge.includes("|| action === 'submit-recharge-debt-repayment'")) {
  throw new Error('bridge does not derive the cashier workspace context for recharge repayment')
}
if (!manifest.includes("'submit-recharge': FEATURE_MEMBER") || !manifest.includes("'submit-recharge-debt-repayment': FEATURE_MEMBER")) {
  throw new Error('recharge commands are not registered in the frontend action manifest')
}
if (!overlay.includes('<section v-if="selectedDebt" class="member-debt-overlay__recharge-salespeople"')
  || overlay.includes('<section v-if="selectedDebt && selectedDebtIsRecharge" class="member-debt-overlay__recharge-salespeople"')) {
  throw new Error('销售订单欠款与充值欠款都必须显示同一个销售人选择入口')
}
if (!overlay.includes('salespersonAllocations: salespersonAllocations.value.map((item) => ({')) {
  throw new Error('所有补交类型都必须提交本次操作选择的销售人比例')
}
console.log('RECHARGE_DEBT_REPAYMENT_FRONTEND_CONTRACT_OK')
