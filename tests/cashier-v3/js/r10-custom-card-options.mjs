import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { createRequire } from 'node:module'

// 执行真实组件 setup 与 Vue 响应式逻辑，不复制日期或筛选实现作断言。
const root = new URL('../../../', import.meta.url)
const require = createRequire(new URL('前端代码/cashier-v3/package.json', root))
const { computed, ref, watch, reactive, nextTick } = require('vue')
const rangeSource = fs.readFileSync(new URL('前端代码/cashier-v3/src/services/cashierV3SalePriceRange.js', root), 'utf8')
const { salePriceRangeMessage } = await import(`data:text/javascript;base64,${Buffer.from(rangeSource).toString('base64')}`)
const source = fs.readFileSync(new URL('前端代码/cashier-v3/src/components/cashier/CashierGuidedBusinessPanel.vue', root), 'utf8')
const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
const props = reactive({ mode: 'custom-card', catalogItems: [
  { id: 1, kind: '项目', name: '销售项目', price: '100', saleStatus: 'selling' },
  { id: 2, kind: '项目', name: '仓库项目', specification: '第二规格', price: '200', saleStatus: 'warehouse', salePriceRange: { minimumCents: 10000, maximumCents: 20000 } },
  { id: 3, kind: '产品', name: '不可混入产品', price: '300', saleStatus: 'selling' }
], submitting: false })
const emitted = []
const feedback = []
// 收银台统一弹窗由壳层监听此事件；验证组件确实发出弹窗信息，且不退回到底部行内报错。
const context = vm.createContext({
  computed, ref, watch, salePriceRangeMessage,
  window: { dispatchEvent: (event) => feedback.push(event) },
  CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail } },
  defineProps: () => props, defineEmits: () => (...args) => emitted.push(args)
})
vm.runInContext(script + '\nthis.test = { defaultCustomCardExpiry, expiryDate, customSaleStatus, customKeyword, visibleCustomOptions, selectedCustomItems, customOptions, selectedCustomIds, cardName, toggleCustom, showSelectedCustomItems, clearSelectedCustomItems, confirm, step, nextStep, selectStep, customError };', context)
const t = context.test
assert.equal(t.defaultCustomCardExpiry(new Date('2026-10-11T00:00:00+08:00')), '2029-10-11')
assert.equal(t.defaultCustomCardExpiry(new Date('2024-02-29T12:00:00+08:00')), '2027-02-28')
assert.equal(t.defaultCustomCardExpiry(new Date('2026-12-31T16:30:00Z')), '2030-01-01')
assert.equal(t.defaultCustomCardExpiry(new Date('2026-01-30T16:30:00Z')), '2029-01-31')
assert.deepEqual(Array.from(t.visibleCustomOptions.value, x => x.id), [1])
t.customSaleStatus.value = 'all'
assert.deepEqual(Array.from(t.visibleCustomOptions.value, x => x.id), [1, 2])
t.toggleCustom(2)
t.customOptions.value.find(x => x.id === 2).times = 4
t.customOptions.value.find(x => x.id === 2).amount = 400
t.customSaleStatus.value = 'selling'
assert.equal(t.selectedCustomItems.value[0].times, 4)
assert.equal(t.selectedCustomItems.value[0].amount, 400)
t.customSaleStatus.value = 'all'
t.customKeyword.value = '第二规格'
assert.deepEqual(Array.from(t.visibleCustomOptions.value, x => x.id), [2])
t.customKeyword.value = '不存在'
assert.equal(t.visibleCustomOptions.value.length, 0)
t.customKeyword.value = ''
t.showSelectedCustomItems.value = true
assert.deepEqual(Array.from(t.visibleCustomOptions.value, x => x.id), [2])
props.catalogItems = [...props.catalogItems]
await nextTick()
assert.equal(t.selectedCustomItems.value[0].times, 4)
assert.equal(t.selectedCustomItems.value[0].amount, 400)
t.cardName.value = '第10轮测试卡'
t.expiryDate.value = '2099-10-11'
t.confirm()
assert.equal(emitted.length, 1)
assert.equal(emitted[0][1].payload.components[0].skuId, 2)
assert.equal(emitted[0][1].payload.components[0].times, 4)
assert.equal(emitted[0][1].payload.components[0].amount, '400')
t.clearSelectedCustomItems()
assert.equal(t.selectedCustomItems.value.length, 0)
assert.equal(t.showSelectedCustomItems.value, false)
// “设置金额”为全部次数的金额；下一步和顶部步骤导航都不能绕开单价区间。
t.toggleCustom(2)
const selected = t.customOptions.value.find(x => x.id === 2)
selected.times = 4
selected.amount = 398
t.step.value = 2
t.nextStep()
assert.equal(t.step.value, 2)
assert.equal(feedback.at(-1).type, 'cashier-v3:ui-result')
assert.equal(feedback.at(-1).detail.status, 'failed')
assert.match(feedback.at(-1).detail.message, /仓库项目.*设置金额398元、次数4.*最低100元,最高200元/)
assert.equal(t.customError.value, '')
selected.amount = 804
t.selectStep(3)
assert.equal(t.step.value, 2)
assert.match(feedback.at(-1).detail.message, /仓库项目.*设置金额804元、次数4.*最低100元,最高200元/)
assert.equal(t.customError.value, '')
selected.amount = 400
const feedbackCount = feedback.length
t.nextStep()
assert.equal(t.step.value, 3)
assert.equal(feedback.length, feedbackCount)
assert.equal(t.customError.value, '')
assert.match(source, /role="radiogroup" aria-label="卡内项目销售状态"/)
// 产品经理补验要求：搜索、状态和已选按钮同排，缩小范围仅限定制卡工具区。
const css = fs.readFileSync(new URL('前端代码/cashier-v3/src/styles/base.css', root), 'utf8')
assert.match(css, /\.cashier-guided-panel__search-row\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\) auto auto;/)
assert.match(css, /\.cashier-guided-panel__selection-actions \.button\s*\{[^}]*font-size:\s*12px;/)
assert.doesNotMatch(css, /\.cashier-guided-panel__selection-actions\s*\{[^}]*grid-column:/)
console.log('R10_CUSTOM_CARD_OPTIONS=PASS: calendar years, Shanghai day, leap/month end, status/search/selected state, payload')
