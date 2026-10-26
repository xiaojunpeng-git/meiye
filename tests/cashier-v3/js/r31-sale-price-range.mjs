import fs from 'node:fs'
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
const root = new URL('../../../', import.meta.url)
const read = (name) => fs.readFileSync(new URL(name, root), 'utf8')
const helper = read('前端代码/cashier-v3/src/services/cashierV3SalePriceRange.js')
const { salePriceRangeMessage } = await import(`data:text/javascript;base64,${Buffer.from(helper).toString('base64')}`)
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const body = workbench.slice(workbench.indexOf('async function saveMoreActionEditor()'), workbench.indexOf('\nfunction openSupplementDateEditor()', workbench.indexOf('async function saveMoreActionEditor()')))
for (const name of ['卡项', '产品', '项目']) {
  for (const [amount, expected] of [[19800, true], [40200, true], [0, true], [20000, false], [40000, false], [30000, false]]) {
    const range = { minimumCents: 10000, maximumCents: 20000 }
    const edits = []
    const ref = (value) => ({ value })
    const bindings = {
      moreActionEditor: ref({ type: 'price-change', line: { id: 'local-line', name, quantity: 2, catalogItemId: 1, salePriceRange: range } }),
      isSavingMoreAction: ref(false), moreActionValue: ref(String(amount / 100)), moreActionReason: ref('测试'),
      moreActionValidationMessage: ref(''), catalog: ref({ items: [{ id: 1, salePriceRange: range }] }),
      salePriceRangeMessage, applyLocalCashierRootMutation: (...args) => edits.push(args),
      closeMoreActionEditor: () => edits.push('close'), formatMoney: String,
      window: { dispatchEvent() {} }, CustomEvent: class {}
    }
    // 执行真实保存函数，证明越界提示发生在浏览器草稿变更/关闭弹窗之前。
    const save = Function(...Object.keys(bindings), `${body};return saveMoreActionEditor`)(...Object.values(bindings))
    await save()
    assert.equal(bindings.moreActionValidationMessage.value, expected ? `${name}的售价区间是:最低100元,最高200元` : '')
    assert.equal(edits.length, expected ? 0 : 2)
  }
}
assert.equal(salePriceRangeMessage('产品', 0, 2, { minimumCents: 0, maximumCents: 0 }), '')
assert.equal(salePriceRangeMessage('项目', 19800, 2, { minimumCents: 10000, maximumCents: 0 }), '项目的售价区间是:最低100元,最高不限')
assert.equal(salePriceRangeMessage('卡项', 596000, 2, { minimumCents: 298000, maximumCents: 298000 }), '')
assert.equal(salePriceRangeMessage('卡项', 595800, 2, { minimumCents: 298000, maximumCents: 298000 }), '卡项的售价区间是:最低2980元,最高2980元')
const admin = read('前端代码/admin/src/pages/product/productAdd/index.vue')
assert.equal((admin.match(/label="可售价区间："/g) || []).length, 3)
assert.doesNotMatch(admin, /调价区间/)
const require = createRequire(import.meta.url)
const vue2 = require(fileURLToPath(new URL('前端代码/admin/node_modules/vue-template-compiler', root)))
const parsed = vue2.parseComponent(admin)
assert.ok(parsed.template.content.includes('可售价区间'))
const vue3 = require(fileURLToPath(new URL('前端代码/cashier-v3/node_modules/@vue/compiler-sfc', root)))
const result = vue3.parse(workbench)
assert.equal(result.errors.length, 0)
vue3.compileScript(result.descriptor, { id: 'r31-workbench' })
console.log('PASS three-type actual save function, per-unit boundaries, zero/fixed/unbounded ranges and Vue SFC compile')
