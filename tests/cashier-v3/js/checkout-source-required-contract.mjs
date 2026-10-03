import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')
const overlay = read('前端代码/cashier-v3/src/components/cashier/CheckoutBusinessSourceOverlay.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
assert.ok(overlay.includes('!primaryId') && overlay.includes("validationMessage.value = '请选择一级来源。'"), '未选择一级来源时不能确认')
assert.ok(workbench.includes('cashier-v3:checkout-business-source-cancelled') && workbench.includes('detail: { kind }'), '取消来源会发出取消事件')
assert.ok(shell.includes('handleCheckoutBusinessSourceCancelled') && shell.includes("applyLocalCashierCustomerSelection({ customerMode: 'guest' })"), '销售来源取消回到游客')
const checkoutEntry = workbench.slice(
  workbench.indexOf('async function openCheckout()'),
  workbench.indexOf('function guardedOpenCheckout')
)

assert.match(
  checkoutEntry,
  /Number\(localCheckoutBusinessSource\.value\.primarySourceId \|\| 0\) <= 0[\s\S]*CASHIER_BUSINESS_SOURCE_REQUIRED[\s\S]*reportCheckoutEntryFailure/,
  '未选择顶部客户来源时必须提示并阻止进入结账'
)
assert.ok(
  checkoutEntry.indexOf('CASHIER_BUSINESS_SOURCE_REQUIRED') < checkoutEntry.indexOf('localCheckoutPreviewSnapshot()'),
  '来源校验必须先于结账快照创建'
)
console.log('cashier checkout source required contract: PASS')
