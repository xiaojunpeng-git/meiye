import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { runInNewContext } from 'node:vm'

const require = createRequire(new URL('../../../前端代码/inventory-vue3/package.json', import.meta.url))
const Vue = require('vue')
const { compile } = require('@vue/compiler-dom')
const { renderToString } = require('@vue/server-renderer')
const source = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
const section = source.match(/<section class="line-section"><header><div><h3>出入库流水[\s\S]*?<\/section>/)?.[0]
assert.ok(section, 'render the actual movement section')
assert.doesNotMatch(source, /function movementTypeName\(/, 'retire the replaced client type dictionary')
const render = runInNewContext(`(function () { ${compile(section, { mode: 'function', prefixIdentifiers: true }).code} })()`, { Vue })
const formatQuantitySource = source.match(/function formatQuantity\(units, scale = 0\) \{[\s\S]*?\n\}/)?.[0]
assert.ok(formatQuantitySource)
const formatQuantity = runInNewContext(`${formatQuantitySource}; formatQuantity`)
const movement = {
  id: 1, order_sn: 'XS26100900006', source_type: 'cashier_sale', source_id: 'CSO-internal',
  source_detail_id: 'CSL-internal', source_type_name: '收银结账',
  source_detail_name: '第2行：玻尿酸蚕丝水润面膜（预售）',
  quantity_units: 3, quantity_scale: 0, direction: -1, stock_unit: '盒',
  unit_cost_cents: null, cost_amount_cents: null, reversal_of: 7,
}
// Technical IDs remain in the API for traceability but must not become visible cells.
async function renderDetail(movements) {
  return renderToString(Vue.createSSRApp({ setup: () => ({ detail: { movements, product: { stock_unit: '盒' } }, formatQuantity }), render }))
}
const html = await renderDetail([movement])
for (const value of ['XS26100900006', '收银结账', '第2行：玻尿酸蚕丝水润面膜（预售）', '冲销流水 #7']) assert.ok(html.includes(value))
assert.doesNotMatch(html, /CSO-internal|CSL-internal|库存调整|¥0/)
assert.equal((html.match(/<td>/g) || []).length, 14, 'columns stay stable')
assert.ok((await renderDetail([])).includes('暂无出入库流水'))
const legacy = await renderDetail([{ ...movement, order_sn: '历史业务单据', source_type_name: undefined, source_detail_name: undefined }])
assert.ok(legacy.includes('其他库存业务') && legacy.includes('历史业务明细'))
assert.doesNotMatch(legacy, /CSO-internal|CSL-internal/)
console.log('movement business display real template rendering: PASS')
