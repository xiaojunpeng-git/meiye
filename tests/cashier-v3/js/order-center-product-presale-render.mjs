import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { runInNewContext } from 'node:vm'

const require = createRequire(new URL('../../../前端代码/cashier-v3/package.json', import.meta.url))
const Vue = require('vue')
const { compile } = require('@vue/compiler-dom')
const { renderToString } = require('@vue/server-renderer')
const view = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue', import.meta.url), 'utf8')
const template = view.match(/<span class="sales-order-query-item-cell__line">[\s\S]*?<\/span>/)?.[0]
assert.ok(template, 'use the real list item template')
const render = runInNewContext(`(function () { ${compile(template, { mode: 'function', prefixIdentifiers: true }).code} })()`, { Vue })

// Render the production template, including sibling rows and existing tags;
// a snapshot flag must never be inferred from a name or truthy string.
async function renderItem(item) {
  return renderToString(Vue.createSSRApp({
    setup: () => ({ item, salesOrderItemCustomerTag: () => '' }), render
  }))
}
const ordinary = { itemType: '商品', businessTag: '购买', name: '玻尿酸蚕丝水润面膜', isPresale: false }
const presale = { ...ordinary, isPresale: true }
const html = await renderItem(presale)
assert.match(html, /__name[^>]*>玻尿酸蚕丝水润面膜<\/strong>[\s\S]*?__service-tag[^>]*>预售<\/small>/)
assert.equal((html.match(/>预售<\/small>/g) || []).length, 1)
for (const item of [ordinary, { ...ordinary, isPresale: undefined }, { ...ordinary, isPresale: '0' }, { ...presale, itemType: '项目' }, { ...presale, itemType: '卡项' }]) {
  assert.doesNotMatch(await renderItem(item), />预售<\/small>/)
}
assert.match(await renderItem({ itemType: '项目', name: '护理', isExperience: true }), />体验<\/small>/)
assert.doesNotMatch(await renderItem(presale), />体验<\/small>/)
// No-outbound is independent of presale in data, but presale takes visual
// precedence so its implied no-outbound choice is not shown twice.
const noOutbound = { ...ordinary, isNoOutbound: true }
assert.match(await renderItem(noOutbound), /__name[^>]*>玻尿酸蚕丝水润面膜<\/strong>[\s\S]*?__service-tag[^>]*>不出库<\/small>/)
assert.doesNotMatch(await renderItem(noOutbound), />预售<\/small>/)
for (const item of [ordinary, { ...noOutbound, isNoOutbound: undefined }, { ...noOutbound, isNoOutbound: '0' }, { ...noOutbound, itemType: '项目' }, { ...noOutbound, itemType: '卡项' }, { ...noOutbound, isPresale: true }]) {
  assert.doesNotMatch(await renderItem(item), />不出库<\/small>/)
}
const siblings = await Promise.all([noOutbound, { ...presale, isNoOutbound: true }, ordinary].map(renderItem))
assert.equal((siblings.join('').match(/>不出库<\/small>/g) || []).length, 1)
assert.equal((siblings.join('').match(/>预售<\/small>/g) || []).length, 1)
// The requested wording is display-only; canonical itemType still drives tags.
for (const itemHtml of siblings) {
  assert.match(itemHtml, /__type[^>]*>产品<\/small>/)
  assert.doesNotMatch(itemHtml, /__type[^>]*>商品<\/small>/)
}
for (const itemType of ['项目', '卡项']) {
  assert.match(await renderItem({ ...ordinary, itemType }), new RegExp(`__type[^>]*>${itemType}</small>`))
}
assert.equal(ordinary.itemType, '商品')
console.log('order center product presale template rendering: PASS')
