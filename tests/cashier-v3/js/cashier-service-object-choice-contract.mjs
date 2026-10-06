import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

const view = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const choiceSource = view.match(/function cartLineServiceChoice\(line = \{\}\) \{[\s\S]*?\n\}/)?.[0]
assert.ok(choiceSource, '购物车必须从服务对象和计客开关得到唯一显示选项')
const choice = runInNewContext(`${choiceSource}; cartLineServiceChoice`, {
  cartLineFriendCountsAsCustomer: line => line.counts,
  cartLineServiceObject: line => line.serviceObject
})

for (const [serviceObject, counts, expected] of [
  ['self', true, 'self'],
  ['self', false, 'excluded'],
  ['friend', true, 'friend'],
  ['friend', false, 'excluded']
]) {
  assert.equal(choice({ serviceObject, counts }), expected)
}

for (const [value, label] of [
  ['self', '本人'],
  ['friend', '朋友算'],
  ['excluded', '不算人数人次']
]) {
  assert.match(view, new RegExp(`:class="\\{ 'is-active': cartLineServiceChoice\\(line\\) === '${value}' \\}"[\\s\\S]*?>${label}<\\/button>`))
}

const excludeSource = view.match(/function setCartLineExcludedFromCustomerCounts\(line\) \{[\s\S]*?\n\}/)?.[0]
assert.ok(excludeSource)
let selected
const exclude = runInNewContext(`${excludeSource}; setCartLineExcludedFromCustomerCounts`, {
  cartLineServiceObject: line => line.serviceObject,
  setCartLineServiceObject: (...args) => { selected = args }
})
for (const serviceObject of ['self', 'friend']) {
  const line = { serviceObject }
  exclude(line)
  assert.deepEqual(selected, [line, serviceObject, false], '不计客不能改写真实服务对象')
}

assert.match(view, /serviceObject: cartLineServiceObject\(line\)/)
assert.match(view, /friendCountsAsCustomer: cartLineFriendCountsAsCustomer\(line\)/)
console.log('cashier service-object choice contract: PASS')
