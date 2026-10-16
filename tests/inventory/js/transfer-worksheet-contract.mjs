import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { exportTransferTemplateXlsx, parseTransferXlsx, TRANSFER_COLUMNS } from '../../../前端代码/inventory-vue3/src/components/transferWorksheet.js'

const inventoryRequire = createRequire(new URL('../../../前端代码/inventory-vue3/package.json', import.meta.url))
const ExcelJS = inventoryRequire('exceljs')

// Workbook identity is a text code; neither formulas nor invalid quantities may reach the draft API.
const workbook = new ExcelJS.Workbook()
await workbook.xlsx.load(await exportTransferTemplateXlsx())
const sheet = workbook.worksheets[0]
assert.deepEqual(sheet.getRow(1).values.slice(1), TRANSFER_COLUMNS)
assert.equal(sheet.getColumn(1).numFmt, '@')
sheet.addRow(['0000123', 2.5, '箱装'])
sheet.addRow(['X-02', '0.125', ''])
assert.deepEqual(await parseTransferXlsx(await workbook.xlsx.writeBuffer()), [
  { rowNumber: 2, code: '0000123', quantity: '2.5', detailRemark: '箱装' },
  { rowNumber: 3, code: 'X-02', quantity: '0.125', detailRemark: '' }
])

sheet.getCell('B2').value = { formula: '1+1', result: 2 }
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /公式/)
sheet.getCell('B2').value = 2.5
sheet.getCell('B3').value = '0.12345'
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /最多四位小数/)
sheet.getCell('B3').value = -1
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /大于零/)
sheet.getCell('B3').value = 1
sheet.getCell('A3').value = '0000123'
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /重复/)
sheet.getCell('A3').value = 'X-02'
sheet.getCell('C3').value = '备'.repeat(501)
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /500/)
sheet.getCell('C3').value = ''
sheet.getCell('A3').value = 123
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /必须为文本/)
sheet.getCell('A3').value = 'X-02'
sheet.getCell('A1').value = '商品名称'
await assert.rejects(parseTransferXlsx(await workbook.xlsx.writeBuffer()), /表头/)
await assert.rejects(parseTransferXlsx(new Uint8Array([1, 2, 3])), /xlsx/)

const oversized = new ExcelJS.Workbook()
const many = oversized.addWorksheet('调拨商品')
many.addRow(TRANSFER_COLUMNS)
for (let index = 0; index < 101; index++) many.addRow([`CODE-${index}`, 1, ''])
await assert.rejects(parseTransferXlsx(await oversized.xlsx.writeBuffer()), /最多导入 100/)

// The import action must use the existing draft endpoint, not the inbound/outbound import endpoint.
const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
const modal = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../../后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php', import.meta.url), 'utf8')
assert.match(app, /active === 'transfer'.*openEditor\('transfer-import'\)/)
assert.match(modal, /request_document_id: isTransferImport\.value \? 0/)
assert.match(modal, /await platformInventoryApi\.createHqCrossTransfer\(payload\)/)
assert.match(modal, /await inventoryApi\.createCrossTransfer\(commonPayload\)/)
assert.match(modal, /transferImportIdempotencyKey/)
assert.match(modal, /if \(transferImportReady\.value && transferSourceSelection\.value === source.*\) await submitTransfer\(\)/)
assert.match(modal, /v-show="!isTransferImport \|\| !!submitError"/)
assert.match(service, /'document_status' => 'DRAFT'/)
console.log('TRANSFER_WORKSHEET_CONTRACT_OK')
