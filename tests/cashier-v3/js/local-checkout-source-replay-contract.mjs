import fs from 'node:fs'
import path from 'node:path'

const workbench = fs.readFileSync(
  path.resolve('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'),
  'utf8'
)

const checks = [
  ['本地预览保存来源字段', /localCheckoutPreviewSnapshot\(\)[\s\S]*primarySourceId:[\s\S]*rewardAmountCents:/],
  ['来源修改只更新浏览器快照', /action === 'update-checkout-business-source'[\s\S]*只更新浏览器内快照/],
  ['业务日期修改只更新浏览器快照', /action === 'update-checkout-sales-date'[\s\S]*preview\.businessDate/],
  ['最终快照同时包含来源与支付明细', /source: checkoutSourceSnapshot\([\s\S]*payment,/],
  ['权益行快照保留卡名卡号项目名', /sourceNameSnapshot:[\s\S]*sourceCodeSnapshot:[\s\S]*projectNameSnapshot:/],
  ['最终只提交一次 submit-checkout', /finalizeLocalCheckoutPreview[\s\S]*action: 'submit-checkout'[\s\S]*checkoutSnapshot/],
  ['普通前端清单存在且可审计', /const checkoutRequestActions = new Set\(\[/]
]

let failed = 0
for (const [label, pattern] of checks) {
  if (!pattern.test(workbench)) {
    failed += 1
    console.error(`FAIL: ${label}`)
  }
}
if (failed) process.exit(1)
const actions = workbench.slice(workbench.indexOf('const checkoutRequestActions = new Set(['), workbench.indexOf('])', workbench.indexOf('const checkoutRequestActions = new Set([')) + 2)
if (/prepare-checkout-submission/.test(actions)) process.exit(1)
console.log(`LOCAL_CHECKOUT_SOURCE_REPLAY_CONTRACT passed=${checks.length} failed=0`)
