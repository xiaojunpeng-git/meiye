import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { runInNewContext } from 'node:vm'
import { formatBatchNumber } from '../../../前端代码/inventory-vue3/src/batchNumberDisplay.js'
import { groupedLineCells } from '../../../前端代码/inventory-vue3/src/groupedDocumentRows.js'

// 覆盖后端实际生成格式和近似真实批号；显示为空不能改变原始成本层身份。
const internalNumbers = ['AUTO-IN-', 'AUTO-COUNT-'].map((prefix) => prefix + 'a46c6cc93095c2048eea6ad2bbb2911db7e8154857a164a3')
for (const value of [null, undefined, '', ...internalNumbers]) assert.equal(formatBatchNumber(value), '')
for (const value of ['LOT-20261011', 'AUTO-IN-manual', 'AUTO-COUNT-20261011', 'AUTO-IN-' + 'a'.repeat(47), 'AUTO-IN-' + 'a'.repeat(49)]) {
  assert.equal(formatBatchNumber(value), value)
}
for (const page of ['inbound', 'outbound']) {
  for (const batchNumber of [...internalNumbers, '', 'LOT-20261011']) {
    const line = Object.freeze({ batch_no: batchNumber, quantity: '5', unit_cost_cents: 500, cost_amount_cents: 2500 })
    const cells = groupedLineCells(page, line, {})
    assert.equal(cells[2], formatBatchNumber(batchNumber))
    assert.equal(line.batch_no, batchNumber)
    assert.equal(cells[5], '5')
    assert.equal(cells[8], '¥25')
  }
}

// 编译真实共用详情表，验证平台/门店共用的七处读模型均不再输出内部编号。
const require = createRequire(new URL('../../../前端代码/inventory-vue3/package.json', import.meta.url))
const Vue = require('vue')
const { compile } = require('@vue/compiler-dom')
const { renderToString } = require('@vue/server-renderer')
const modal = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
// 库存主表与两张批次统计表也必须复用同一显示规则，避免详情已隐藏但列表泄露内部编号。
assert.match(app, /import \{ formatBatchNumber \} from '\.\/batchNumberDisplay'/)
assert.equal((app.match(/formatBatchNumber\(row\.batch_no\)/g) || []).length, 4, 'stock current/legacy and expiry/age rows')
assert.ok(!/case 'stock':[\s\S]{0,900}text\(row\.batch_no\)/.test(app))
const tables = [...modal.matchAll(/<table>[\s\S]*?<\/table>/g)].map((match) => match[0]).filter((table) => table.includes('formatBatchNumber('))
assert.equal(tables.length, 7, 'in/out details, linked outbound, count/draft, stock batches and movements')
assert.ok(!/\{\{ (?:line\.(?:surplus_)?batch_no|batch\.batch_no|movement\.batch_no)(?: \|\| '-')? \}\}/.test(modal))
for (const batchNumber of [...internalNumbers, '', 'LOT-20261011']) {
  const line = Object.freeze({ id: 1, batch_id: 1, movement_fact_id: 1, batch_no: batchNumber, surplus_batch_no: batchNumber,
    quantity: '5', available_quantity: '5', quantity_units: 5, quantity_scale: 0, stock_unit: '件',
    cost_amount_cents: 2500, unit_cost_cents: 500 })
  const detail = Object.freeze({ lines: [line], batches: [line], movements: [line], product: { stock_unit: '件' } })
  for (const template of tables) {
    const render = runInNewContext(`(function () { ${compile(template, { mode: 'function', prefixIdentifiers: true }).code} })()`, { Vue })
    const html = await renderToString(Vue.createSSRApp({ render, setup: () => ({ detail, formatBatchNumber,
      formatQuantity: (units) => String(units), countAmount: () => '', emit: () => {} }) }))
    assert.ok(!/AUTO-(IN|COUNT)-/.test(html))
    if (batchNumber === 'LOT-20261011') assert.ok(html.includes('<td>LOT-20261011</td>'))
    else assert.ok(html.includes('<td></td>'), 'missing batch is genuinely blank, not a dash')
  }
  assert.equal(line.batch_no, batchNumber)
  assert.equal(line.surplus_batch_no, batchNumber)
}
console.log('BATCH_NUMBER_DISPLAY_OK: exact internal format, blank/real batches, grouped in/out and seven rendered detail tables; raw identities unchanged')
