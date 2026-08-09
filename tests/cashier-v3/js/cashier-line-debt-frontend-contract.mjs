import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const workbench = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root), 'utf8')
const styles = fs.readFileSync(new URL('前端代码/cashier-v3/src/styles/base.css', root), 'utf8')

assert.match(workbench, /mutateCashierDraft\('update-cashier-line-debt', line, \{\s*debtAmountCents: amountCents/s,
  '欠款必须写入当前选择的购物车行')
assert.match(workbench, /checkoutDebtSummary\(line\)/, '每条购物车行必须显示自己的欠款')
assert.doesNotMatch(workbench, /debtAmountCents:\s*checkoutDebtAmountCents\.value/,
  '准备结账不得把客户端整单欠款作为权威输入')
assert.match(workbench, /const checkoutDebtAmountCents = computed\(\(\) => cartLines\.value/,
  '结账头欠款只能从购物车行求和')
assert.match(workbench, /return amount > 0\s*\? String\(amount \/ 100\)/,
  '欠款标签必须显示不带货币符号的数值')
assert.match(styles, /\.cart-line__meta-slot--debt \.cart-line__meta-action \{[^}]*width: auto;[^}]*max-width: none;[^}]*overflow: visible;/s,
  '欠款标签必须按内容宽度完整显示')

console.log('CASHIER_LINE_DEBT_FRONTEND_CONTRACT passed=6 failed=0')
