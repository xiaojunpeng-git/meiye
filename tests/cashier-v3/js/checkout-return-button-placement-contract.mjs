import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

// 两类结账的退出按钮都应在底栏，且保留原关闭守卫与各自的返回目的地。
const overlay = readFileSync(new URL('../../../前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue', import.meta.url), 'utf8')
const header = overlay.split('<header class="checkout-overlay__header">')[1]?.split('</header>')[0] || ''
const footer = overlay.split('<footer class="checkout-overlay__footer">')[1]?.split('</footer>')[0] || ''
const returnButton = footer.match(/<button\s+v-if="isDebtRepayment \|\| !isSucceeded"[\s\S]*?<\/button>/)?.[0] || ''

assert.ok(!header.includes('<button'))
assert.ok(returnButton.includes(':disabled="!canCloseOverlay"') && returnButton.includes('@click="requestClose"'))
assert.ok(returnButton.includes("isDebtRepayment ? '返回欠款明细' : '返回收银'"))
assert.ok(footer.indexOf(returnButton) < footer.indexOf('>上一步</button>'))
assert.ok(footer.includes('@click="finishCheckoutAndReturn"'))
console.log('CHECKOUT_RETURN_BUTTON_PLACEMENT=PASS')
