import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const shell = readFileSync(
  new URL('../../../前端代码/cashier-v3/src/layouts/CashierShell.vue', import.meta.url),
  'utf8'
)
const start = shell.indexOf('async function prepareDebtRepayment(payload = {})')
const end = shell.indexOf('\nasync function loadMemberDetailTab', start)
const preparation = shell.slice(start, end)

assert.ok(start >= 0 && end > start, '欠款补交准备函数必须存在且边界完整')
assert.ok(
  !preparation.includes("requestCashierV3Action('open-cashier-workbench'"),
  '欠款补交准备不得重读工作台并让后续命令携带过期 workspace 版本'
)
assert.ok(
  // 来源快照独立复制后仍是浏览器本地交接，奖励随同 businessSource 保留。
  preparation.includes('localRepayment: { ...payload, businessSource: { ...source } }')
    && !preparation.includes("'prepare-recharge-debt-repayment'")
    && !preparation.includes("'prepare-debt-repayment'")
    && !preparation.includes('prepared?.preparationRequestId'),
  '下一步必须只交接本地快照，不得创建服务端草稿或比较旧工作台版本'
)

console.log('DEBT_REPAYMENT_WORKSPACE_VERSION_CONTRACT_OK')
