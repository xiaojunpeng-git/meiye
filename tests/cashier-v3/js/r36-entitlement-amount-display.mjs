import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const selector = fs.readFileSync(new URL('前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue', root), 'utf8')
const bridge = fs.readFileSync(new URL('前端代码/cashier-v3/src/services/cashierV3Bridge.js', root), 'utf8')
// 执行实际展示函数，核对取整与缺失值；次数继续走原格式化，不混入金额口径。
const moneySource = bridge.match(/export function formatMoney\(value\) \{[\s\S]*?\n\}/)[0].replace('export ', '')
const amountSource = selector.match(/function displayAmount\(value\) \{[\s\S]*?\n\}/)[0]
const numberSource = selector.match(/function displayNumber\(value\) \{[\s\S]*?\n\}/)[0]
const { displayAmount, displayNumber } = new Function(`${moneySource}\n${amountSource}\n${numberSource}\nreturn { displayAmount, displayNumber }`)()
for (const [value, expected] of [[256.6, '257'], [1981.6, '1,982'], [256.49, '256'], [256.5, '257'], [0, '0'], ['2180', '2,180'], [null, '—'], [undefined, '—'], ['', '—'], ['invalid', '—']]) {
  assert.equal(displayAmount(value), expected)
}
assert.equal(displayNumber(1.5), '1.5')
assert.ok(selector.includes('displayAmount(source.remainingAmount)'))
assert.ok(selector.includes('displayAmount(source.outstandingDebtAmount)'))
assert.ok(selector.includes("isTimeCardSource(source) ? '—' : displayAmount(project.remainingAmount)"))
console.log('R36_ENTITLEMENT_AMOUNT_DISPLAY_PASS')
