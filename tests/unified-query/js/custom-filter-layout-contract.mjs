// 共享查询布局回归：日期的固定宽度不得影响第二行自定义字段；不依赖消费订单数据。
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
const css = readFileSync('前端代码/shared/unified-query-vue3/src/styles/unified-query.css', 'utf8')
const secondary = css.match(/\.unified-query-toolbar \.unified-query-toolbar__secondary \.unified-query-toolbar__top-fields\s*\{([^}]+)\}/)[1]
assert.match(secondary, /display:\s*flex/)
assert.match(secondary, /flex:\s*1 1 100%/)
assert.match(secondary, /flex-flow:\s*row wrap/)
assert.ok(!css.includes('.order-center-query-toolbar.unified-query-toolbar--quick-controls-inline .unified-query-toolbar__top-fields {'))
assert.ok(css.includes('.order-center-query-toolbar.unified-query-toolbar--quick-controls-inline .unified-query-toolbar__primary .unified-query-toolbar__top-fields {'))
console.log('PASS: shared secondary filters fill the row and wrap left-to-right; fixed date width is primary-only')
