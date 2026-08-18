import fs from 'node:fs'
import path from 'node:path'

const workbench = fs.readFileSync(
  path.resolve('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'),
  'utf8'
)

const checks = [
  ['本地预览复制来源快照', /sourceEnabled:[\s\S]*primarySourceId:[\s\S]*sourceSelectionVersion:/],
  ['预览来源变更不直接请求服务端', /action === 'update-checkout-business-source'[\s\S]*只更新浏览器内快照/],
  ['最终快照包含来源名称和支付明细', /source: checkoutSourceSnapshot\([\s\S]*payment: clonePlain\(snapshot\.payment/],
  ['最终确认不再回放来源或支付命令', /serverCheckoutReady = true[\s\S]*Do not replay either as a second/]
]

let failed = 0
for (const [label, pattern] of checks) {
  if (!pattern.test(workbench)) {
    failed += 1
    console.error(`FAIL: ${label}`)
  }
}

if (failed) process.exit(1)
console.log(`LOCAL_CHECKOUT_SOURCE_REPLAY_CONTRACT passed=${checks.length} failed=0`)
