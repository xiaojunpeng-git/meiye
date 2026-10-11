import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(fileURLToPath(new URL('.', import.meta.url)), '../../..')
const app = readFileSync(resolve(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
const modal = readFileSync(resolve(root, '前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue'), 'utf8')
const api = readFileSync(resolve(root, '前端代码/inventory-vue3/src/services/inventoryApi.js'), 'utf8')

const checks = [
  // 两端按钮统一消费后端能力，不再用终端类型覆盖功能权限。
  ['grouped and flat lists show edit only on a server-authorized row', () => {
    assert.match(app, /sourceRows\[index\]\.can_edit[^>]+openTransferEditor/)
    assert.match(app, /active === 'transfer' && sourceRows\[index\]\.can_edit/)
    assert.doesNotMatch(app, /mode === 'store' && sourceRows\[index\]\.can_edit/)
  }],
  ['edit opens a fresh authorized detail with an optimistic token', () => {
    assert.match(app, /async function openTransferEditor\(row\)/)
    assert.match(app, /inventoryApi\.crossTransferDetail\(row\.id\)/)
    assert.match(app, /!detail\?\.can_edit \|\| !detail\?\.edit_token/)
  }],
  ['form restores date, target, request and source SKU quantities', () => {
    assert.match(modal, /function hydrateTransferEditor\(detail\)/)
    for (const field of ['business_date', 'to_party_type', 'request_document_id', 'from_product_id', 'from_sku_id', 'requested_quantity_units', 'detail_remark']) assert.ok(modal.includes(field), field)
  }],
  ['linked request cannot be replaced and locks target selection', () => {
    assert.match(modal, /:disabled="isTransferEdit && requestDocumentId > 0"/)
    assert.match(modal, /<input v-if="isTransferEdit" :value="requestDocumentId/)
  }],
  ['edit submits the original detail token to the update route', () => {
    assert.match(modal, /inventoryApi\.updateCrossTransfer\(Number\(props\.detail\?\.id\)/)
    assert.match(modal, /expected_edit_token: String\(props\.detail\?\.edit_token/)
    assert.match(api, /updateCrossTransfer\(id, body\).*v3\/cross-transfer\/\$\{Number\(id\)\}\/update/)
  }],
  ['draft deletion appears after edit and sends a fresh token to the guarded route', () => {
    assert.match(app, /openTransferEditor\(sourceRows\[index\]\)">编辑<\/button><button v-if="active === 'transfer' && sourceRows\[index\]\.can_delete"/)
    assert.match(app, /async function requestDeleteTransferDraft\(row\)/)
    assert.match(app, /deleteCrossTransferDraft\(row\.id, \{ expected_edit_token: confirmation\.expectedEditToken \}\)/)
    assert.match(api, /deleteCrossTransferDraft\(id, body\).*v3\/cross-transfer\/\$\{Number\(id\)\}\/delete-draft/)
  }],
  ['platform draft commands and receiver searches share the existing form', () => {
    assert.match(modal, /updateHqCrossTransfer\(Number\(props\.detail\?\.id\)/)
    assert.match(app, /deleteHqCrossTransferDraft\(row\.id/)
    assert.match(modal, /:options="transferTargetOptions" searchable/)
    assert.match(app, /:options="platformTransferTargetOptions" searchable/)
    assert.match(app, /hqCrossTransferCounterparties/)
  }],
]

let failed = 0
for (const [name, run] of checks) {
  try { run(); process.stdout.write(`PASS ${name}\n`) }
  catch (error) { failed += 1; process.stderr.write(`FAIL ${name}: ${error.message}\n`) }
}
process.stdout.write(`CROSS_TRANSFER_DRAFT_EDIT_JS_RESULT failed=${failed}\n`)
process.exitCode = failed ? 1 : 0
