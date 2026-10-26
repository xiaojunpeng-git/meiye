import assert from 'node:assert/strict'
import fs from 'node:fs'

const workbench = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const controls = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/components/cashier/CartLineRightControls.vue', import.meta.url), 'utf8')
const css = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/styles/base.css', import.meta.url), 'utf8')

assert.match(workbench, /:formatted-total="formatCartLineMoney\(centsToMoney\(cartLineTotalAmountCents\(line\)\)\)"/, '合计必须区分权益分摊金额和本地销售行金额')
assert.match(workbench, /function cartLineTotalAmountCents\(line = \{\}\) \{[\s\S]*?isEntitlementLine\(line\)[\s\S]*?moneyToCents\(line\.actualAmount \?\? getLineAmount\(line\)\)[\s\S]*?: localDraftLineTotalAmountCents\(line\)/, '权益行不能将已分摊的总额再次乘数量')
assert.match(workbench, /function localDraftLineTotalAmountCents\(line = \{\}, amount = getLineAmount\(line\)\)[\s\S]*?isLocalCashierDraftLine\(line\)[\s\S]*?cents \* Math\.max\(1, Number\(line\.quantity \|\| 1\)\)[\s\S]*?: cents/, '本地行乘数量，已落库和权益分摊行保留权威合计')
assert.match(workbench, /const cartLineMoneyFormatter = new Intl\.NumberFormat\('zh-CN', \{[\s\S]*?useGrouping: false,[\s\S]*?maximumFractionDigits: 0/, '购物车行金额必须为无逗号的整数元')
assert.match(workbench, /\{\{ formatCartLineMoney\(cardOperationUpgradeBinding\(line\) \? line\.originalAmount : getLineAmount\(line\)\) \}\}/, '单价和金额也必须使用无逗号格式')
assert.match(workbench, /class="cart-line__entitlement-amount"[\s\S]*?\{\{ formatCartEntitlementUnitPrice\(line\) \}\}/, '权益左侧必须展示原购买平均单价')
const moneyToCents = new Function(`${workbench.match(/function moneyToCents\(value\) \{[\s\S]*?\n\}/)[0]}; return moneyToCents`)()
const unitPriceSource = workbench.match(/function formatCartEntitlementUnitPrice\(line = \{\}\) \{[\s\S]*?\n\}/)[0]
const formatCartEntitlementUnitPrice = new Function('moneyToCents', 'formatCartLineMoney', `${unitPriceSource}; return formatCartEntitlementUnitPrice`)(moneyToCents, (value) => `¥${value}`)
for (const [purchaseAmount, totalPurchaseTimes, expected] of [
  ['2980.00', 10, '¥298'], ['1000.00', 15, '¥66'], ['6980.00', 30, '¥232'],
  ['199.99', 2, '¥99'], ['0.99', 1, '¥0'], ['', 10, '—'], ['100.00', 0, '—']
]) {
  assert.equal(formatCartEntitlementUnitPrice({ purchaseAmount, totalPurchaseTimes }), expected)
}
assert.equal(formatCartEntitlementUnitPrice({ purchaseAmount: '100.00', totalPurchaseTimes: 10, unlimited: true }), '—')
assert.match(controls, /v-if="showQuantity && Number\(line\.quantity \|\| 1\) > 1" class="cart-line__quantity-total"/, '合计只在数量大于一时显示')
assert.match(controls, /class="cart-line__quantity-total"[\s\S]*?合计 \{\{ formattedTotal \}\}/, '合计必须位于数量右侧并带明确标签')
assert.match(css, /\.cart-line \.cart-line__right-controls \.quantity-stepper--editable button\s*\{[^}]*padding: 0;/, '增减按钮必须移除默认内边距以居中符号')
assert.match(css, /\.cart-line__quantity-total\s*\{[^}]*font-size: 14px;/, '合计使用普通字号')
assert.match(css, /\.cart-line__right-controls--with-total\s*\{[^}]*width: max-content;/, '合计出现时控件组必须向左扩展，不覆盖备注与删除')

console.log('CART_LINE_QUANTITY_TOTAL_CONTRACT=PASS')
