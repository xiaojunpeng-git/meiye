import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'

const root = process.cwd()
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')

const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const selector = read('前端代码/cashier-v3/src/components/cashier/CashierCouponSelectorOverlay.vue')
const manifest = read('前端代码/cashier-v3/src/services/cashierV3ActionManifest.js')
const bridge = read('前端代码/cashier-v3/src/services/cashierV3Bridge.js')

const checks = [
  ['本地草稿优惠券使用只读选择投影', workbench.includes("localDraft ? 'open-local-line-coupon' : 'open-line-coupon'") && workbench.includes('reservedCouponIds')],
  ['普通销售行与升级目标销售行共用优惠券入口', workbench.includes('@click="openCartLineCoupon(line)"') && !workbench.includes('cardOperationUpgradeBinding(line) && openCartLineCoupon')],
  ['卡内权益行不能使用优惠券', workbench.includes('if (isEntitlementLine(line)) return') && workbench.includes(':disabled="isEntitlementLine(line)"')],
  ['选择器只接收后端 couponSelector', workbench.includes('responseDataBlock(result).couponSelector')],
  ['应用优惠券写入本地草稿队列', workbench.includes("saveLineCoupon('apply-line-coupon'") && workbench.includes('mutateCashierDraft(action, line')],
  ['移除优惠券写入本地草稿队列', workbench.includes("saveLineCoupon('remove-line-coupon')")],
  ['写命令已登记在前端动作清单', manifest.includes("'apply-line-coupon': FEATURE_CASHIER") && manifest.includes("'remove-line-coupon': FEATURE_CASHIER")],
  ['写命令属于收银工作台上下文', bridge.includes("'apply-line-coupon'") && bridge.includes("'remove-line-coupon'")],
  ['选择器展示券名和后端优惠金额', selector.includes("coupon.name || '优惠券'") && selector.includes('coupon.discountAmountCents')],
  ['选择器支持不使用优惠券', selector.includes("emit('remove')") && selector.includes('不使用优惠券')],
  ['选择器没有客户端计算升级补价', !selector.includes('cardOperationUpgrade') && !selector.includes('targetAmount')],
  ['优惠券仍使用现有立即结账流程', !selector.includes('CashierCheckoutOverlay') && !selector.includes('prepare-checkout')]
]

let failed = 0
for (const [label, passed] of checks) {
  if (passed) {
    console.log(`PASS ${label}`)
  } else {
    failed += 1
    console.error(`FAIL ${label}`)
  }
}

if (failed) process.exit(1)
console.log(`R15 line coupon frontend contract passed (${checks.length} checks).`)
