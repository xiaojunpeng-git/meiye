import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const workbench = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root), 'utf8')
const styles = fs.readFileSync(new URL('前端代码/cashier-v3/src/styles/base.css', root), 'utf8')

assert.match(workbench, /mutateCashierDraft\('update-cashier-line-debt', line, \{\s*debtAmountCents: amountCents/s,
  '欠款必须写入当前选择的购物车行')
assert.match(workbench, /checkoutDebtSummary\(line\)/, '每条购物车行必须显示自己的欠款')
assert.match(workbench, /function canSetCartLineDebt\(line = \{\}\) \{[\s\S]*?currentCustomerMode\.value !== 'guest'[\s\S]*?!isProjectLine\(line\)[\s\S]*?isProductLine\(line\) \|\| line\.kind === '卡项'/,
  '欠款仅对会员购买的产品和卡项开放')
assert.match(workbench, /<div class="cart-line__meta-slot cart-line__meta-slot--debt">\s*<button\s*v-if="canSetCartLineDebt\(line\)"/s,
  '欠款隐藏时必须保留固定位置插槽')
assert.match(workbench, /function handleDebtEditorAmountInput\(event\) \{[\s\S]*?replace\(\/\\D\/g, ''\)/,
  '欠款输入框必须在输入时过滤非数字字符')
assert.match(workbench, /function lineSaleAmountCents\(line = \{\}\) \{[\s\S]*?return localDraftLineTotalAmountCents\(line\)/,
  '欠款上限必须复用结账行合计：本地单价乘数量，已保存行直接使用行合计')
assert.doesNotMatch(workbench, /function confirmCheckoutDebt\(\) \{[\s\S]*?欠款金额必须为整数元。/,
  '确认欠款不再承担输入格式校验')
assert.doesNotMatch(workbench, /debtAmountCents:\s*checkoutDebtAmountCents\.value/,
  '准备结账不得把客户端整单欠款作为权威输入')
assert.match(workbench, /const checkoutDebtAmountCents = computed\(\(\) => cartLines\.value/,
  '结账头欠款只能从购物车行求和')
assert.match(workbench, /return amount > 0\s*\? String\(amount \/ 100\)/,
  '欠款标签必须显示不带货币符号的数值')
assert.match(styles, /\.cart-line__meta-slot--debt \.cart-line__meta-action \{[^}]*width: auto;[^}]*max-width: none;[^}]*overflow: visible;/s,
  '欠款标签必须按内容宽度完整显示')

console.log('CASHIER_LINE_DEBT_FRONTEND_CONTRACT passed=7 failed=0')
