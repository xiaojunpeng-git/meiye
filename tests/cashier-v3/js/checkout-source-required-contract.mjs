import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.cwd())
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')
const overlay = read('前端代码/cashier-v3/src/components/cashier/CheckoutBusinessSourceOverlay.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')

const checks = [
  ['未选择一级来源时不能确认', overlay.includes('!primaryId') && overlay.includes("validationMessage.value = '请选择一级来源。'")],
  ['取消来源会发出取消事件', workbench.includes("cashier-v3:checkout-business-source-cancelled") && workbench.includes("detail: { kind }")],
  ['销售来源取消回到游客', shell.includes('handleCheckoutBusinessSourceCancelled') && shell.includes("applyLocalCashierCustomerSelection({ customerMode: 'guest' })")]
]

for (const [label, ok] of checks) {
  if (!ok) throw new Error(`contract failed: ${label}`)
}
console.log('checkout source required contract ok')
