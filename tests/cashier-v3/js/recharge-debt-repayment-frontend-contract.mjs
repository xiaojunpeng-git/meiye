import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const overlay = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/member/MemberDebtOverlay.vue'), 'utf8')
const memberSummary = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/common/MemberSummaryCard.vue'), 'utf8')
const shell = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/layouts/CashierShell.vue'), 'utf8')
const bridge = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/services/cashierV3Bridge.js'), 'utf8')
const manifest = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/services/cashierV3ActionManifest.js'), 'utf8')

for (const needle of ['selectedDebtIsRecharge', 'paymentLines', 'salespersonAllocations', '添加收款方式', '添加销售人', 'rechargeDebt']) {
  if (!overlay.includes(needle)) throw new Error(`overlay missing ${needle}`)
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
console.log('RECHARGE_DEBT_REPAYMENT_FRONTEND_CONTRACT_OK')
