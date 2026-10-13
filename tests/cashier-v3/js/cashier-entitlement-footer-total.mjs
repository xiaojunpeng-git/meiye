import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const view = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root), 'utf8')
const source = view.match(/const entitlementTotalCents = computed\(\(\) => ([\s\S]*?)\)\nconst entitlementSelector/)
assert.ok(source, '底栏权益合计应由当前购物车权益行计算')
assert.match(view, /权益合计<strong>\{\{ formatMoney\(Math\.round\(entitlementTotalCents \/ 100\)\) \}\}<\/strong>/)

// 执行页面的实际合计表达式：权益行金额已经是整行分摊值，不能再乘数量或混入销售。
const calculate = new Function('lines', 'isEntitlementLine', 'cartLineTotalAmountCents',
  `const cartLines = { value: lines }; return ${source[1]}`)
const isEntitlementLine = (line) => line.lineRole === 'entitlement_service'
const lineCents = (line) => Math.round(Number(line.actualAmount) * 100)
assert.equal(calculate([], isEntitlementLine, lineCents), 0)
assert.equal(calculate([{ lineRole: 'sale', actualAmount: '6980.00' }], isEntitlementLine, lineCents), 0)
assert.equal(calculate([
  { lineRole: 'sale', actualAmount: '6980.00' },
  { lineRole: 'entitlement_service', actualAmount: '122.51', quantity: 2 },
  { lineRole: 'entitlement_service', actualAmount: '77.50', quantity: 1 }
], isEntitlementLine, lineCents), 20001)
console.log('CASHIER_ENTITLEMENT_FOOTER_TOTAL_PASS')
