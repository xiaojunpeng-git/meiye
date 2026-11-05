import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { ordinaryCardRows, applyOrdinaryCardRows, validateOrdinaryCardSale } from '../../../前端代码/cashier-v3/src/services/ordinaryCardSaleAdjustment.js'

// Real PHP proof -> browser editor -> real PHP final validation, with no HTTP or DB writes.
const php = process.env.PHP_BINARY || 'php'
const test = fileURLToPath(new URL('../php/r47-ordinary-card-sale-amount.php', import.meta.url))
const fixture = JSON.parse(execFileSync(php, [test], { encoding: 'utf8', env: { ...process.env, R47_MODE: 'export' } }))
const clone = value => JSON.parse(JSON.stringify(value))
const item = { price: 2980, ordinaryCardSaleEditable: true,
  cardPreview: { components: clone(fixture.purchase.components) }, cardPurchaseSnapshot: fixture.purchase }
const original = clone(item)
assert.equal(applyOrdinaryCardRows({...item, price:'2980.00'}, ordinaryCardRows(item)).price, 2980)
const rows = ordinaryCardRows(item)
assert.equal(Object.hasOwn(rows[0], 'amount'), false)
assert.equal(rows[0].times, '10')
const same = applyOrdinaryCardRows(item, rows)
assert.equal(same.price, 2980)
assert.equal(same.cardPurchaseSnapshot.components[0].configuredAmountCents, 298000)
assert.equal(applyOrdinaryCardRows(same, ordinaryCardRows(same)).price, 2980)
const increased = applyOrdinaryCardRows(item, rows, '3080')
assert.equal(increased.price, 3080)
assert.equal(applyOrdinaryCardRows(increased, ordinaryCardRows(increased)).price, 3080)
rows[0].times = '12'
const times = applyOrdinaryCardRows(item, rows)
assert.equal(times.price, 2980)
assert.equal(times.cardPurchaseSnapshot.components[0].writeTimes, 12)
assert.deepEqual(item, original)
assert.deepEqual(same.cardPurchaseSnapshot.ordinarySaleBaseline, item.cardPurchaseSnapshot.ordinarySaleBaseline)
for (const [field, value] of [['amount', '3080'], ['times', '9'], ['times', '10.5']]) {
  const bad = ordinaryCardRows(item); bad[0][field] = value
  assert.throws(() => applyOrdinaryCardRows(item, bad))
}
validateOrdinaryCardSale(increased.cardPurchaseSnapshot, 616000, 2)
assert.throws(() => validateOrdinaryCardSale(times.cardPurchaseSnapshot, 595900, 2))
const legacy = { ...item, cardPurchaseSnapshot: fixture.legacyPurchase }
assert.equal(ordinaryCardRows(legacy)[0].minimumCents, 0)
const legacyRows = ordinaryCardRows(legacy)
const legacyEdit = applyOrdinaryCardRows(legacy, legacyRows, '3080')
assert.equal(legacyEdit.price, 3080)
const cases = [
  { name: 'unchanged save', purchase: same.cardPurchaseSnapshot, increase: 0, amountCents: 298000 },
  { name: 'added 100 yuan', purchase: increased.cardPurchaseSnapshot, increase: 0, amountCents: 308000 },
  { name: 'times-only', purchase: times.cardPurchaseSnapshot, increase: 0, amountCents: 298000 },
  { name: 'two cards and coupon', purchase: increased.cardPurchaseSnapshot, increase: 0, quantity: 2, amountCents: 606000, couponCents: 10000 },
  { name: 'legacy hang', purchase: legacyEdit.cardPurchaseSnapshot, increase: 0, amountCents: 308000 }
]
for (const invalidPrice of ['2979', '2980.01', '-1', '', '1000000000']) {
  assert.throws(() => applyOrdinaryCardRows(item, rows, invalidPrice))
}
assert.equal(increased.cardPurchaseSnapshot.components[0].configuredAmountCents, 298000)
// Persisted pre-change hangs keep their accepted weights, even though the new UI cannot edit them.
const oldHang = clone(increased); oldHang.cardPurchaseSnapshot.components[0].configuredAmountCents = 308000
assert.equal(applyOrdinaryCardRows(oldHang, ordinaryCardRows(oldHang)).price, 3080)
assert.throws(() => applyOrdinaryCardRows(oldHang, ordinaryCardRows(oldHang), '3079'))
process.stdout.write(execFileSync(php, [test], { encoding: 'utf8', input: JSON.stringify(cases), env: { ...process.env, R47_MODE: 'verify' } }))
console.log('PASS R47 editor initialization, repeated save, immutable proof, integer floors and PHP/JS parity')
