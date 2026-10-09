import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

// 只检查库存查询挂载点，不改变共享工具栏或其他库存页的设置入口约定。
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const require = createRequire(path.join(root, '前端代码/inventory-vue3/package.json'))
const { parse } = require('@vue/compiler-sfc')
const { baseParse } = require('@vue/compiler-dom')
const source = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
const { descriptor, errors } = parse(source)
assert.equal(errors.length, 0)
const ast = baseParse(descriptor.template.content)
function find(node, predicate) {
  if (predicate(node)) return node
  for (const child of node.children || []) {
    const match = find(child, predicate)
    if (match) return match
  }
}
const stock = find(ast, node => node.type === 1 && node.tag === 'section'
  && node.props.some(prop => prop.type === 7 && prop.name === 'if' && prop.exp?.content === "active === 'stock'"))
assert.ok(stock)
const toolbar = find(stock, node => node.type === 1 && node.tag === 'UnifiedQueryToolbar')
const binding = name => toolbar.props.find(prop => prop.type === 7 && prop.name === 'bind' && prop.arg?.content === name)?.exp?.content
assert.equal(binding('show-settings-button'), "mode === 'platform'")
// 显隐表达式必须只允许平台；门店及缺失模式均不展示，且不删查询和设置保存契约。
const visible = new Function('mode', `return ${binding('show-settings-button')}`)
assert.equal(visible('store'), false)
assert.equal(visible('platform'), true)
assert.equal(visible(undefined), false)
assert.ok(toolbar.props.some(prop => prop.type === 7 && prop.name === 'on' && prop.arg?.content === 'query' && prop.exp?.content === 'queryBatchStock'))
assert.equal(binding('on-save-settings'), 'saveStockQuerySettings')
assert.equal(binding('fields'), 'visibleStockQueryFields')
assert.ok(!source.includes('stockQueryToolbar'))
// 位置调整仅门店库存查询启用，平台及未明确指定模式不受影响。
assert.equal(binding('query-actions-after-quick-fields'), "mode === 'store'")
const relocate = new Function('mode', `return ${binding('query-actions-after-quick-fields')}`)
assert.equal(relocate('store'), true)
assert.equal(relocate('platform'), false)
console.log('STOCK_SETTINGS_ENTRY_CONTRACT_OK checks=13')
