// A transfer workbook carries item codes and quantities only; its warehouse, staff and
// document identity are selected in the authenticated editor and checked by the server.
export const TRANSFER_COLUMNS = ['商品条码或编码', '调拨数量', '明细备注']
const MAX_LINES = 100

/** Export a text-formatted code column so Excel does not discard leading zeros. */
export async function exportTransferTemplateXlsx() {
  const { default: ExcelJS } = await import('exceljs')
  const workbook = new ExcelJS.Workbook()
  const sheet = workbook.addWorksheet('调拨商品', { views: [{ state: 'frozen', ySplit: 1 }] })
  sheet.addRow(TRANSFER_COLUMNS)
  sheet.getColumn(1).width = 27
  sheet.getColumn(1).numFmt = '@'
  sheet.getColumn(2).width = 18
  sheet.getColumn(2).numFmt = '0.####'
  sheet.getColumn(3).width = 42
  sheet.getColumn(3).numFmt = '@'
  sheet.autoFilter = { from: 'A1', to: 'C1' }
  sheet.getRow(1).font = { bold: true, color: { argb: 'FFFFFFFF' } }
  sheet.getRow(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF254679' } }
  return new Uint8Array(await workbook.xlsx.writeBuffer())
}

function plainCell(cell, rowNumber, columnName) {
  const value = cell.value
  if (value == null) return ''
  if (typeof value !== 'string' && (typeof value !== 'number' || !Number.isFinite(value))) {
    throw new Error(`第 ${rowNumber} 行“${columnName}”含公式或不支持的单元格内容。`)
  }
  return String(value).trim()
}

/** Validate the entire file before any draft command or catalog lookup is issued. */
export async function parseTransferXlsx(buffer) {
  const { default: ExcelJS } = await import('exceljs')
  const workbook = new ExcelJS.Workbook()
  try { await workbook.xlsx.load(buffer) }
  catch { throw new Error('调拨文件不是有效的 xlsx 工作簿。') }
  const sheet = workbook.worksheets[0]
  if (!sheet) throw new Error('调拨文件没有工作表。')
  const header = sheet.getRow(1)
  if (TRANSFER_COLUMNS.some((name, index) => plainCell(header.getCell(index + 1), 1, name) !== name)
    || header.cellCount !== TRANSFER_COLUMNS.length) {
    throw new Error('请使用调拨模板，表头依次为商品条码或编码、调拨数量、明细备注。')
  }
  const rows = []
  const codes = new Set()
  for (let rowNumber = 2; rowNumber <= sheet.rowCount; rowNumber++) {
    const excelRow = sheet.getRow(rowNumber)
    if (!excelRow.hasValues) continue
    if (excelRow.cellCount > TRANSFER_COLUMNS.length) throw new Error(`第 ${rowNumber} 行有模板外的字段。`)
    // A numeric Excel code may already have lost leading zeros; never match it to another SKU.
    if (typeof excelRow.getCell(1).value === 'number') throw new Error(`第 ${rowNumber} 行商品条码或编码必须为文本，请使用模板保留前导零。`)
    const code = plainCell(excelRow.getCell(1), rowNumber, TRANSFER_COLUMNS[0])
    const quantity = plainCell(excelRow.getCell(2), rowNumber, TRANSFER_COLUMNS[1])
    const detailRemark = plainCell(excelRow.getCell(3), rowNumber, TRANSFER_COLUMNS[2])
    if (!code || code.length > 100) throw new Error(`第 ${rowNumber} 行商品条码或编码无效。`)
    if (!/^\d+(?:\.\d{1,4})?$/.test(quantity) || Number(quantity) <= 0) throw new Error(`第 ${rowNumber} 行调拨数量须为大于零且最多四位小数。`)
    if (detailRemark.length > 500) throw new Error(`第 ${rowNumber} 行明细备注不能超过 500 字。`)
    if (codes.has(code)) throw new Error(`第 ${rowNumber} 行商品条码或编码重复：${code}。`)
    codes.add(code)
    rows.push({ rowNumber, code, quantity, detailRemark })
    if (rows.length > MAX_LINES) throw new Error('一张调拨草稿最多导入 100 项商品。')
  }
  if (!rows.length) throw new Error('调拨文件没有商品记录。')
  return rows
}
