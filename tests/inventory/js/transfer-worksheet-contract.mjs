import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { exportTransferTemplateXlsx, parseTransferXlsx, TRANSFER_COLUMNS } from '../../../前端代码/inventory-vue3/src/components/transferWorksheet.js'
import { applyTransferDetailBatch, planTransferDraftBatch } from '../../../前端代码/inventory-vue3/src/components/transferDraftBatch.js'

const inventoryRequire = createRequire(new URL('../../../前端代码/inventory-vue3/package.json', import.meta.url))
const ExcelJS = inventoryRequire('exceljs')
const workbook = new ExcelJS.Workbook()
await workbook.xlsx.load(await exportTransferTemplateXlsx([{ key: 'HQ:1', name: '总部仓' }, { key: 'STORE:101', name: '门店101' }]))
const sheet = workbook.worksheets[0]
assert.deepEqual(sheet.getRow(1).values.slice(1), TRANSFER_COLUMNS)
assert.equal(sheet.getColumn(1).numFmt, '@')
assert.equal(workbook.worksheets[1].getCell('A2').value, 'HQ:1')
sheet.addRow(['HQ:1', 'STORE:101', '0000123', 2.5, '箱装'])
sheet.addRow(['STORE:101', 'HQ:1', 'X-02', '0.125', ''])
assert.deepEqual(await parseTransferXlsx(await workbook.xlsx.writeBuffer()), [
  { rowNumber: 2, sourceKey: 'HQ:1', targetKey: 'STORE:101', code: '0000123', quantity: '2.5', detailRemark: '箱装' },
  { rowNumber: 3, sourceKey: 'STORE:101', targetKey: 'HQ:1', code: 'X-02', quantity: '0.125', detailRemark: '' }
])
sheet.getCell('D2').value = { formula: '1+1', result: 2 }
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /公式/)
sheet.getCell('D2').value = '0.12345'
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /最多四位小数/)
sheet.getCell('D2').value = '2.5'
sheet.getCell('C3').value = '0000123'
sheet.getCell('A3').value = 'HQ:1'
sheet.getCell('B3').value = 'STORE:101'
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /重复/)
sheet.getCell('A3').value = 'STORE:101'
sheet.getCell('B3').value = 'HQ:1'
sheet.getCell('E3').value = '备'.repeat(501)
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /500/)
sheet.getCell('E3').value = ''
sheet.getCell('C3').value = 123
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /必须为文本/)
sheet.getCell('C3').value = 'X-02'
sheet.getCell('A1').value = '商品名称'
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /表头/)
await assert.rejects(parseTransferXlsx(new Uint8Array([1, 2, 3])), /xlsx/)

const oversized = new ExcelJS.Workbook()
const many = oversized.addWorksheet('调拨商品')
many.addRow(TRANSFER_COLUMNS)
for (let index = 0; index < 501; index++) many.addRow(['HQ:1', `STORE:${index + 1}`, `CODE-${index}`, 1, ''])
await assert.rejects(parseTransferXlsx(await oversized.xlsx.writeBuffer()), /最多导入 500/)

const product = { product_id: 3, sku_id: 7, sku_unique: 'SKU-7' }
const context = { platform: true, storeSourceKey: '', businessDate: '2026-10-16', remark: '', staffId: 0, hqLocationId: 1 }
const row = {
  sourceKey: 'HQ:1', product, quantity: '2', detailRemark: '',
  destinations: Array.from({ length: 102 }, (_, index) => ({ key: `STORE:${index + 1}`, quantity: index === 4 ? '3.5' : '2' }))
}
const drafts = planTransferDraftBatch([row], context)
assert.equal(drafts.length, 102)
assert.deepEqual(drafts.map((draft) => draft.target_store_id), Array.from({ length: 102 }, (_, index) => index + 1))
assert.equal(drafts[4].lines[0].quantity, '3.5')
assert.equal(drafts[5].lines[0].quantity, '2')
assert.ok(drafts.every((draft) => draft.lines.length === 1 && draft.request_document_id === 0 && !('document_status' in draft)))
const grouped = planTransferDraftBatch([
  { ...row, destinations: [{ key: 'STORE:1', quantity: '2' }] },
  { ...row, product: { product_id: 4, sku_id: 8, sku_unique: 'SKU-8' }, destinations: [{ key: 'STORE:1', quantity: '1' }] },
  { ...row, sourceKey: 'STORE:2', destinations: [{ key: 'STORE:3', quantity: '1' }] }
], context)
assert.equal(grouped.length, 2)
assert.equal(grouped[0].lines.length, 2)
assert.equal(grouped[1].source_store_id, 2)
assert.throws(() => planTransferDraftBatch([{ ...row, destinations: [{ key: 'HQ:1', quantity: '1' }] }], context), /相同/)
assert.throws(() => planTransferDraftBatch([{ ...row, destinations: [{ key: 'STORE:1', quantity: '0' }] }], context), /大于零/)
const excessiveRows = Array.from({ length: 201 }, (_, index) => ({ ...row, sourceKey: 'HQ:1', destinations: [{ key: `STORE:${index + 1}`, quantity: '1' }] }))
assert.throws(() => planTransferDraftBatch(excessiveRows, context), /最多生成 200/)

const editableRows = [
  { ...row, id: 1, destinations: [{ key: 'STORE:1', quantity: '3.5', custom: true }] },
  { ...row, id: 2, quantity: '4', destinations: [{ key: 'STORE:2', quantity: '4', custom: false }] },
  { ...row, id: 3, sourceKey: 'HQ:2', destinations: [{ key: 'STORE:3', quantity: '2', custom: false }] }
]
const withQuantity = applyTransferDetailBatch(editableRows, [1, 2], 'quantity', '10')
assert.deepEqual(withQuantity.map((item) => item.quantity), ['10', '10', '2'])
assert.deepEqual(withQuantity[0].destinations, [{ key: 'STORE:1', quantity: '10', custom: false }])
assert.deepEqual(editableRows[0].destinations, [{ key: 'STORE:1', quantity: '3.5', custom: true }])
const withTargets = applyTransferDetailBatch(editableRows, [1, 2], 'destinations', [{ key: 'STORE:4' }, { key: 'STORE:5' }])
assert.deepEqual(withTargets[0].destinations.map((item) => item.quantity), ['2', '2'])
assert.deepEqual(withTargets[1].destinations.map((item) => item.quantity), ['4', '4'])
assert.deepEqual(withTargets[2], editableRows[2])
assert.deepEqual(applyTransferDetailBatch(editableRows, [1, 3], 'delete').map((item) => item.id), [2])
assert.throws(() => applyTransferDetailBatch(editableRows, [1, 3], 'destinations', [{ key: 'STORE:4' }]), /同一调出方/)
assert.throws(() => applyTransferDetailBatch(editableRows, [1], 'quantity', '0'), /大于零/)
assert.throws(() => applyTransferDetailBatch(editableRows, [1], 'destinations', [{ key: 'HQ:1' }]), /不能相同/)

const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
const composer = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryTransferDraftComposer.vue', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../../后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php', import.meta.url), 'utf8')
const storeRoute = readFileSync(new URL('../../../后端代码/route/store.php', import.meta.url), 'utf8')
const platformRoute = readFileSync(new URL('../../../后端代码/route/admin.php', import.meta.url), 'utf8')
assert.match(app, /InventoryTransferDraftComposer v-else-if="editor && active === 'transfer'/)
assert.match(composer, /await submitDrafts\(imported\)/)
assert.match(composer, /createHqCrossTransferBatch\(payload\)/)
assert.match(composer, /createCrossTransferBatch\(payload\)/)
assert.match(service, /return Db::transaction\(function \(\) use \(\$input, \$create, \$dispatch, \$dispatchAfterCreate\): array/)
assert.match(service, /\(int\)\$batchKey\[1\] !== count\(\$input\['drafts'\]\)/)
assert.match(composer, /key: `transfer-batch-\$\{drafts.length\}-\$\{nonce\}`/)
assert.match(composer, /批量操作<\/button>/)
assert.match(composer, /生成调拨并发货<\/button><button type="button" :disabled="busy" @click="emit\('close'\)">取消/)
assert.match(composer, /dispatch_after_create: true/)
assert.match(composer, /确认生成并发货/)
assert.match(service, /'dispatch_count' => count\(\$created\)/)
assert.match(service, /\$dispatch\(\(int\)\$document\['transfer_id'\], \$input\['drafts'\]\[\$index\]\)/)
assert.match(composer, /transferSourceKey: platform\.value/)
assert.match(app, /platformTransferStatus\.value = result\.transferStatus === 'DISPATCHED' \? 'DISPATCHED' : 'DRAFT'/)
assert.match(app, /selectedTransferAlreadyDispatched = computed\(/)
assert.match(app, /所选包含已发货的调拨单，无需重复发货/)
assert.match(service, /'document_status' => 'DRAFT'/)
assert.match(storeRoute, /v3\/cross-transfer\/batch/)
assert.match(platformRoute, /v3\/hq\/cross-transfer\/batch/)
console.log('TRANSFER_WORKSHEET_CONTRACT_OK')
