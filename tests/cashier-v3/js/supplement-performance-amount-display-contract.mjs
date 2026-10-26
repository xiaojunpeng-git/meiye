import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { resolve } from 'node:path'

const root = resolve(process.cwd())
const overlay = await readFile(resolve(root, '前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue'), 'utf8')
const service = await readFile(resolve(root, '后端代码/app/services/cashier/v3/order/CashierV3SupplementSalespersonAdjustmentServices.php'), 'utf8')
const rechargeService = await readFile(resolve(root, '后端代码/app/services/cashier/v3/order/CashierV3RechargePersonnelAdjustmentServices.php'), 'utf8')
const orderCenter = await readFile(resolve(root, '前端代码/cashier-v3/src/views/OrderCenterView.vue'), 'utf8')

assert.match(service, /'performanceAmountCents'\s*=>\s*abs\(\(int\)\(\$row\['amount_cents'\]/,
  '补交人员调整入口必须返回已落账业绩金额')
assert.match(service, /'performanceAmountLocked'\s*=>\s*true/,
  '已落账业绩金额必须标识为只读快照，避免被空基数重算')
assert.match(service, /'performanceBaseAmountCents'\s*=>\s*\(int\)\$source\['repaymentAmountCents'\]/,
  '补交编辑器必须返回每个独立核算组共用的补交业绩基数')
assert.match(orderCenter, /:performance-base-amount-cents="recordPersonnelEntry\.performanceBaseAmountCents \|\| 0"/,
  '订单中心必须把补交业绩基数传给人员分配组件')
assert.match(rechargeService, /'performanceAmountCents'\s*=>\s*\$amount/,
  '充值人员调整入口必须返回已落账业绩金额')
assert.match(rechargeService, /'performanceAmountLocked'\s*=>\s*true/,
  '充值已落账业绩金额必须锁定，避免被当前比例或空基数重算')
assert.match(rechargeService, /\$weightsByGroup/,
  '充值销售人调整必须按独立核算组分别校验比例，不能把店长和普通组相加')
assert.match(rechargeService, /manualPerformanceAmount/,
  '充值销售人调整必须接纳手填的业绩金额')
assert.match(rechargeService, /'performanceAmountManual'\s*=>\s*\$manualAmount/,
  '充值销售人调整必须将手填金额保持在当前快照')
assert.match(rechargeService, /allocation_weight_numerator'\] = \$staff \? \$allocationWeight : 0/,
  '充值调整后的事实必须保存比例快照，不能误把业绩金额写作比例')
assert.match(rechargeService, /\$facts = \$this->effectiveForwardFacts\(/,
  '充值调整只允许冲销当前有效快照，不能重复处理已冲销历史')
assert.match(rechargeService, /private function effectiveForwardFacts\(/,
  '充值调整必须显式排除已有冲销关系的历史事实')
assert.match(orderCenter, /selected\.performanceAmountCents[\s\S]*?selected\.amountCents/,
  '订单中心必须把充值或补交的事实金额带入人员分配弹窗')
assert.match(orderCenter, /performanceAmountLocked: Boolean\([\s\S]*?Object\.prototype\.hasOwnProperty\.call\(selected, 'amountCents'\)/,
  '订单中心必须锁定历史事实金额，直至用户主动修改比例或金额')
assert.match(overlay, /&& !record\.performanceAmountLocked\)/,
  '业绩组件不能把已锁定的历史金额重算为 0')
assert.match(overlay, /item\.performanceAmountLocked = false/,
  '修改比例或手工金额后必须解除快照锁定并按新值计算')

console.log('SUPPLEMENT_PERFORMANCE_AMOUNT_DISPLAY_CONTRACT_OK')
