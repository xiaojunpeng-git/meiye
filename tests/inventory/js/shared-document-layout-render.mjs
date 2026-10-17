import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { runInNewContext } from 'node:vm'
import { groupedDetailColumns, groupedDetailKey, groupedLineCells } from '../../../前端代码/inventory-vue3/src/groupedDocumentRows.js'

const require = createRequire(new URL('../../../前端代码/inventory-vue3/package.json', import.meta.url))
const Vue = require('vue')
const { compile } = require('@vue/compiler-dom')
const { renderToString } = require('@vue/server-renderer')
const source = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
const template = source.match(/<table v-else class="inventory-grouped-table"[\s\S]*?<\/table>/)?.[0].replace(' v-else', '')
assert.ok(template)
const render = runInNewContext(`(function () { ${compile(template, { mode: 'function', prefixIdentifiers: true }).code} })()`, { Vue })

// Compile the actual shared template, not a replacement fixture component.
async function html(active, mode, status = 'ready', rows = true, capabilities = false) {
  const row = { id: 'document-1', can_edit: capabilities, can_delete: capabilities,
    can_dispatch: capabilities, can_receive: capabilities, can_void: capabilities, can_transfer: capabilities }
  const key = groupedDetailKey(row)
  return renderToString(Vue.createSSRApp({ render, setup: () => ({
    active, mode, groupedDetailColumns, groupedDetailKey, groupedLineCells,
    transferStatusDisplay: (detail) => detail?.status_name || '-',
    selectedTransferIds: [], transferBatchPreparing: false, transferActionSubmitting: false,
    currentPage: { title: '入库管理', columns: ['单号'] },
    listLoading: status === 'list-loading', currentRows: rows ? [['document-1']] : [], sourceRows: [row],
    groupedDetails: { [key]: { status, message: 'scope-denied', detail: {}, lines: status === 'empty' ? [] : [{ id: 1, product_name: 'shared-product', product_name_snapshot: 'shared-product', sku_name: 'default', quantity: '2', stock_unit: 'box', unit_cost_cents: null, cost_amount_cents: null }] } },
  }) }))
}
for (const page of Object.keys(groupedDetailColumns)) {
  assert.equal(await html(page, 'platform'), await html(page, 'store'), `${page}: identical read-only markup`)
  const rendered = await html(page, 'platform')
  assert.equal((rendered.match(/<th>/g) || []).length, groupedDetailColumns[page].length)
  assert.ok(rendered.includes('shared-product') && rendered.includes('inventory-group-row'))
  assert.doesNotMatch(rendered, /¥0|>作废<|>编辑<|>发货<|>收货</)
  assert.ok((await html(page, 'platform', 'loading')).includes('正在读取明细'))
  assert.ok((await html(page, 'store', 'error')).includes('scope-denied'))
  assert.ok((await html(page, 'platform', 'empty')).includes('该单据没有商品明细'))
  assert.ok((await html(page, 'store', 'ready', false)).includes('暂无符合条件'))
  assert.ok((await html(page, 'platform', 'list-loading')).includes('正在读取单据数据'))
}
const transfer = await html('transfer', 'platform', 'ready', true, true)
for (const action of ['编辑', '删除', '发货', '收货']) assert.ok(transfer.includes(`>${action}<`))
assert.ok((await html('request', 'platform', 'ready', true, true)).includes('>转调拨<'))
assert.ok((await html('request', 'store', 'ready', true, true)).includes('>转调拨<'))
console.log('SHARED_DOCUMENT_REAL_RENDER_OK: 5 pages, identical rows, cost redaction, capabilities and async states')
