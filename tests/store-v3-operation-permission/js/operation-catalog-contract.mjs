import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')

const policyTree = read('后端代码/app/services/organization/JobPositionPolicyServices.php')
const resolver = read('后端代码/app/services/cashier/v3/permission/CashierV3FeatureResolver.php')
const override = read('后端代码/app/services/cashier/v3/permission/CashierV3StaffFeatureOverrideServices.php')
const bridge = read('前端代码/cashier-v3/src/services/cashierV3Bridge.js')
const memberView = read('前端代码/cashier-v3/src/views/MemberListView.vue')
const orderView = read('前端代码/cashier-v3/src/views/OrderCenterView.vue')
const orderDetail = read('前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue')
const staffView = read('前端代码/cashier-v3/src/views/StaffListView.vue')
const presaleView = read('前端代码/cashier-v3/src/views/PresaleClaimView.vue')

const operationCodes = [
  'cashier.v3.cashier.recharge',
  'cashier.v3.cashier.gift',
  'cashier.v3.cashier.checkout',
  'cashier.v3.cashier.card.upgrade',
  'cashier.v3.cashier.card.extend',
  'cashier.v3.cashier.card.transfer',
  'cashier.v3.cashier.card.disable',
  'cashier.v3.cashier.card.enable',
  'cashier.v3.cashier.card.project_replace',
  'cashier.v3.cashier.card.project_upgrade',
  'cashier.v3.member.create',
  'cashier.v3.member.edit',
  'cashier.v3.order.staff_adjust',
  'cashier.v3.order.refund',
  'cashier.v3.order.void',
  'cashier.v3.order.reopen',
  'cashier.v3.order.receipt_print',
  'cashier.v3.order.debt_view',
  'cashier.v3.order.service_detail',
  'cashier.v3.order.service_void',
  'cashier.v3.staff.create',
  'cashier.v3.staff.edit',
  'cashier.v3.staff.permission_edit',
  'cashier.v3.staff.export',
  'cashier.v3.inventory.presale_claim.create',
  'cashier.v3.inventory.presale_claim.detail',
  'cashier.v3.inventory.presale_claim.void',
]

const frontendUse = new Map([
  ['cashier.v3.member.create', memberView],
  ['cashier.v3.member.edit', memberView],
  ['cashier.v3.order.staff_adjust', orderDetail],
  ['cashier.v3.order.refund', orderDetail],
  ['cashier.v3.order.void', orderDetail],
  ['cashier.v3.order.reopen', orderDetail],
  ['cashier.v3.order.receipt_print', orderDetail],
  ['cashier.v3.order.debt_view', orderDetail],
  ['cashier.v3.order.service_detail', orderView],
  ['cashier.v3.order.service_void', orderView],
  ['cashier.v3.staff.create', staffView],
  ['cashier.v3.staff.edit', staffView],
  ['cashier.v3.staff.permission_edit', staffView],
  ['cashier.v3.staff.export', staffView],
  ['cashier.v3.inventory.presale_claim.create', presaleView],
  ['cashier.v3.inventory.presale_claim.detail', presaleView],
  ['cashier.v3.inventory.presale_claim.void', presaleView],
])

const checks = []
for (const code of operationCodes) {
  checks.push([`岗位权限树登记 ${code}`, policyTree.includes(`'feature_code' => '${code}'`)]);
  checks.push([`服务端解析器登记 ${code}`, resolver.includes(`'${code}'`)]);
  checks.push([`员工覆盖白名单登记 ${code}`, override.includes(`'${code}'`)]);
  checks.push([`前端登录快照白名单登记 ${code}`, bridge.includes(`'${code}': false`)]);
  if (frontendUse.has(code)) checks.push([`前端按钮使用 ${code}`, frontendUse.get(code).includes(code)]);
}

checks.push(['服务记录作废入口与命令已接入', orderView.includes("cashier.v3.order.service_void")
  && orderView.includes('确认作废')
  && orderView.includes('void-service-record')]);

let failed = 0
for (const [name, passed] of checks) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.stdout.write(`ASSERT_PASSED=${checks.length - failed}\nASSERT_FAILED=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
