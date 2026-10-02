import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { exportCountXlsx, parseCountXlsx, matchCountProduct, COUNT_COLUMNS } from '../../../前端代码/inventory-vue3/src/components/countWorksheet.js'

const inventoryRequire = createRequire(new URL('../../../前端代码/inventory-vue3/package.json', import.meta.url))
const ExcelJS = inventoryRequire('exceljs')

// Excel export contains exactly the visible count columns and never truncates a large draft.
const rows = Array.from({ length: 1001 }, (_, index) => [
  String(index + 1), index === 0 ? '=测试,"商品"' : `商品${index + 1}`, '规格', '盒', '0000123',
  '0', '0', '0', '', '', '', ''
])
const exported = await exportCountXlsx(rows)
assert.equal(Buffer.from(exported).subarray(0, 2).toString(), 'PK')
const restored = await parseCountXlsx(exported)
assert.equal((await parseCountXlsx(exported.buffer.slice(exported.byteOffset, exported.byteOffset + exported.byteLength))).length, 1001)
assert.deepEqual(restored, rows)
assert.deepEqual(COUNT_COLUMNS, ['商品ID', '商品名称', '规格', '单位', '商品条码', '账面库存', '实盘库存', '库存盈亏', '盘盈批次号', '盘盈单价', '生产日期', '到期日'])
assert.equal(restored.length, 1001)
// 相同商品的不同批次不能在 Excel 往返中丢失粒度，也不改变旧十二列文件语义。
const batchRows = [[...rows[0].slice(0, 8), '0001', '2.30', '2026-01-01', '2028-01-01', 'BATCH'],
  [...rows[0].slice(0, 8), '0002', '3.40', '2026-02-01', '2028-02-01', 'BATCH']]
assert.deepEqual(await parseCountXlsx(await exportCountXlsx(batchRows)), batchRows.map((row) => row.map((cell, index) => index === 9 ? String(Number(cell)) : cell)))
const workbook = new ExcelJS.Workbook()
await workbook.xlsx.load(exported)
const sheet = workbook.worksheets[0]
assert.equal(sheet.getCell('D2').value, '盒')
assert.equal(sheet.getCell('E2').value, '0000123')
assert.equal(sheet.getCell('B2').value, '=测试,"商品"')
assert.equal(sheet.getCell('F2').value, 0)
assert.equal(sheet.getCell('H2').value.formula, 'G2-F2')
assert.equal(sheet.getCell('I2').numFmt, '@')
assert.equal(sheet.getCell('K2').numFmt, '@')
assert.equal(sheet.getCell('L2').numFmt, '@')
sheet.getCell('B2').value = { formula: '1+1', result: 2 }
assert.equal((await parseCountXlsx(await workbook.xlsx.writeBuffer()))[0][1], '')
sheet.getCell('G2').value = { formula: '1+1', result: 2 }
await assert.rejects(parseCountXlsx(await workbook.xlsx.writeBuffer()), /公式/)
sheet.getCell('G2').value = 0
sheet.getCell('B2').value = '商品'
sheet.getCell('A1').value = '内部ID'
await assert.rejects(parseCountXlsx(await workbook.xlsx.writeBuffer()), /表头/)
await assert.rejects(parseCountXlsx(new Uint8Array([1, 2, 3])), /xlsx/)

const editable = new ExcelJS.Workbook()
await editable.xlsx.load(exported)
editable.worksheets[0].getCell('G2').value = 100
editable.worksheets[0].getCell('K2').value = new Date('2026-06-07T00:00:00Z')
const edited = await parseCountXlsx(await editable.xlsx.writeBuffer())
assert.equal(edited[0][6], '100')
assert.equal(edited[0][7], '100')
assert.equal(edited[0][10], '2026-06-07')

// Excel may keep dates typed into text-formatted cells without zero padding. Normalize
// only real calendar dates so the API receives ISO dates and invalid dates cannot pass.
editable.worksheets[0].getCell('K2').value = '2026-2-2'
editable.worksheets[0].getCell('L2').value = '2027-9-9'
const normalizedDates = await parseCountXlsx(await editable.xlsx.writeBuffer())
assert.equal(normalizedDates[0][10], '2026-02-02')
assert.equal(normalizedDates[0][11], '2027-09-09')
editable.worksheets[0].getCell('K2').value = '2026-2-30'
await assert.rejects(parseCountXlsx(await editable.xlsx.writeBuffer()), /日期格式无效/)

const decimal = await parseCountXlsx(await exportCountXlsx([['21', '小数商品', '默认', '支', '0007', '0.3', '0.4', '0.1', '', '', '', '']]))
assert.equal(decimal[0][7], '0.1')

// 盈亏是参考列，普通/共享/改过的公式均不成为导入门禁，也不作为库存写入数。
const shared = new ExcelJS.Workbook()
await shared.xlsx.load(await exportCountXlsx([
  ['31', '冰袖', '默认', '件', '', '0', '200', '200', '', '', '', ''],
  ['32', '拖鞋', '默认', '件', '', '0', '200', '200', '', '', '', ''],
]))
shared.worksheets[0].fillFormula('H2:H3', 'G2-F2', [200, 200])
assert.equal((await parseCountXlsx(await shared.xlsx.writeBuffer()))[1][7], '200')
shared.worksheets[0].fillFormula('H2:H3', 'G2+F2', [200, 200])
assert.equal((await parseCountXlsx(await shared.xlsx.writeBuffer()))[1][6], '200')

// 自制文件可含说明页、标题行、空行和额外列；只需 ID 与实盘数量，列顺序不固定。
const custom = new ExcelJS.Workbook()
custom.addWorksheet('说明').addRow(['随意说明'])
const customSheet = custom.addWorksheet('我的库存')
customSheet.addRow(['十月盘点'])
customSheet.addRow(['备注', '实盘数量', '产品ID', '生产日期', '批号', '单价', '商品规格', '单位'])
customSheet.addRow(['无需校验', 12, 601720, '2026/2/2', '0001', 2.30, '旧规格', '旧单位'])
customSheet.addRow([])
customSheet.addRow(['其他', 0, 601433])
const customRows = await parseCountXlsx(await custom.xlsx.writeBuffer())
assert.equal(customRows.length, 2)
assert.equal(customRows[0][0], '601720')
assert.equal(customRows[0][6], '12')
assert.equal(customRows[0][8], '0001')
assert.equal(customRows[0][10], '2026-02-02')
assert.equal(customRows[1].sourceRow, 5)
const catalog = [{ product_id: 601720, sku_id: 77, sku_name: '默认', stock_unit: '瓶', product_name: '新名称' }]
assert.equal(matchCountProduct(customRows[0], catalog, 3), catalog[0])
assert.throws(() => matchCountProduct(customRows[1], catalog, 5), /第 5 行商品 ID 601433/)
// 日期在任意物理列都正确解码，不因列重排把 Excel 日期序列当作文本。
customSheet.getCell('D3').value = new Date('2026-06-07T00:00:00Z')
assert.equal((await parseCountXlsx(await custom.xlsx.writeBuffer()))[0][10], '2026-06-07')
customSheet.getCell('D3').value = '2026-2-30'
await assert.rejects(parseCountXlsx(await custom.xlsx.writeBuffer()), /第 3 行日期格式无效/)
customSheet.getCell('D3').value = ''
customSheet.getCell('C5').value = '不存在'
await assert.rejects(parseCountXlsx(await custom.xlsx.writeBuffer()), /第 5 行商品 ID 无效/)
console.log('COUNT_WORKSHEET_CONTRACT_OK')
