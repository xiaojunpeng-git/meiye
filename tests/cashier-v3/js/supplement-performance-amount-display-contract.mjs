import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { resolve } from 'node:path'

const root = resolve(process.cwd())
const overlay = await readFile(resolve(root, '前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue'), 'utf8')
const service = await readFile(resolve(root, '后端代码/app/services/cashier/v3/order/CashierV3SupplementSalespersonAdjustmentServices.php'), 'utf8')
const orderCenter = await readFile(resolve(root, '前端代码/cashier-v3/src/views/OrderCenterView.vue'), 'utf8')

assert.match(service, /'performanceAmountCents'\s*=>\s*abs\(\(int\)\(\$row\['amount_cents'\]/,
  '补交人员调整入口必须返回已落账业绩金额')
assert.match(service, /'performanceAmountLocked'\s*=>\s*true/,
  '已落账业绩金额必须标识为只读快照，避免被空基数重算')
assert.match(service, /'performanceBaseAmountCents'\s*=>\s*\(int\)\$source\['repaymentAmountCents'\]/,
  '补交编辑器必须返回每个独立核算组共用的补交业绩基数')
assert.match(orderCenter, /:performance-base-amount-cents="recordPersonnelEntry\.performanceBaseAmountCents \|\| 0"/,
  '订单中心必须把补交业绩基数传给人员分配组件')
assert.match(overlay, /&& !record\.performanceAmountLocked\)/,
  '业绩组件不能把已锁定的历史金额重算为 0')
assert.match(overlay, /item\.performanceAmountLocked = false/,
  '修改比例或手工金额后必须解除快照锁定并按新值计算')

console.log('SUPPLEMENT_PERFORMANCE_AMOUNT_DISPLAY_CONTRACT_OK')
