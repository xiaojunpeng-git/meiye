import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { exportCountXlsx, parseCountXlsx, COUNT_COLUMNS } from '../../../前端代码/inventory-vue3/src/components/countWorksheet.js'

const inventoryRequire = createRequire(new URL('../../../前端代码/inventory-vue3/package.json', import.meta.url))
const ExcelJS = inventoryRequire('exceljs')

// Excel export contains exactly the visible count columns and never truncates a large draft.
const rows = Array.from({ length: 1001 }, (_, index) => [
  String(index + 1), index === 0 ? '=测试,"商品"' : `商品${index + 1}`, '规格', '0000123',
  '0', '0', '0', '', '', '', ''
])
const exported = await exportCountXlsx(rows)
assert.equal(Buffer.from(exported).subarray(0, 2).toString(), 'PK')
const restored = await parseCountXlsx(exported)
assert.equal((await parseCountXlsx(exported.buffer.slice(exported.byteOffset, exported.byteOffset + exported.byteLength))).length, 1001)
assert.deepEqual(restored, rows)
assert.deepEqual(COUNT_COLUMNS, ['商品ID', '商品名称', '商品规格', '商品条码', '账面库存', '实盘库存', '库存盈亏', '盘盈批次号', '盘盈单价', '生产日期', '到期日'])
assert.equal(restored.length, 1001)
const workbook = new ExcelJS.Workbook()
await workbook.xlsx.load(exported)
const sheet = workbook.worksheets[0]
assert.equal(sheet.getCell('D2').value, '0000123')
assert.equal(sheet.getCell('B2').value, '=测试,"商品"')
assert.equal(sheet.getCell('E2').value, 0)
assert.equal(sheet.getCell('G2').value.formula, 'F2-E2')
assert.equal(sheet.getCell('H2').numFmt, '@')
assert.equal(sheet.getCell('J2').numFmt, '@')
assert.equal(sheet.getCell('K2').numFmt, '@')
sheet.getCell('B2').value = { formula: '1+1', result: 2 }
await assert.rejects(parseCountXlsx(await workbook.xlsx.writeBuffer()), /公式/)
sheet.getCell('B2').value = '商品'
sheet.getCell('A1').value = '内部ID'
await assert.rejects(parseCountXlsx(await workbook.xlsx.writeBuffer()), /表头/)
await assert.rejects(parseCountXlsx(new Uint8Array([1, 2, 3])), /xlsx/)

const editable = new ExcelJS.Workbook()
await editable.xlsx.load(exported)
editable.worksheets[0].getCell('F2').value = 100
editable.worksheets[0].getCell('J2').value = new Date('2026-06-07T00:00:00Z')
const edited = await parseCountXlsx(await editable.xlsx.writeBuffer())
assert.equal(edited[0][5], '100')
assert.equal(edited[0][6], '100')
assert.equal(edited[0][9], '2026-06-07')

// Excel may keep dates typed into text-formatted cells without zero padding. Normalize
// only real calendar dates so the API receives ISO dates and invalid dates cannot pass.
editable.worksheets[0].getCell('J2').value = '2026-2-2'
editable.worksheets[0].getCell('K2').value = '2027-9-9'
const normalizedDates = await parseCountXlsx(await editable.xlsx.writeBuffer())
assert.equal(normalizedDates[0][9], '2026-02-02')
assert.equal(normalizedDates[0][10], '2027-09-09')
editable.worksheets[0].getCell('J2').value = '2026-2-30'
await assert.rejects(parseCountXlsx(await editable.xlsx.writeBuffer()), /日期格式无效/)

const decimal = await parseCountXlsx(await exportCountXlsx([['21', '小数商品', '默认', '0007', '0.3', '0.4', '0.1', '', '', '', '']]))
assert.equal(decimal[0][6], '0.1')

// Excel can coalesce adjacent盈亏公式 into one shared formula; resolved formulas must still
// match each row's F-E expression, while a changed shared formula remains untrusted.
const shared = new ExcelJS.Workbook()
await shared.xlsx.load(await exportCountXlsx([
  ['31', '冰袖', '默认', '', '0', '200', '200', '', '', '', ''],
  ['32', '拖鞋', '默认', '', '0', '200', '200', '', '', '', ''],
]))
shared.worksheets[0].fillFormula('G2:G3', 'F2-E2', [200, 200])
assert.equal((await parseCountXlsx(await shared.xlsx.writeBuffer()))[1][6], '200')
shared.worksheets[0].fillFormula('G2:G3', 'F2+E2', [200, 200])
await assert.rejects(parseCountXlsx(await shared.xlsx.writeBuffer()), /库存盈亏公式已被修改/)
console.log('COUNT_WORKSHEET_CONTRACT_OK')
