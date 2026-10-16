// Party codes are stable identities; display names may repeat or change after a draft is created.
export const TRANSFER_COLUMNS = ['调出方编码', '调入方编码', '商品条码或编码', '调拨数量', '明细备注']
const MAX_LINES = 500

/** Keep party and product codes as text so Excel cannot strip leading zeros. */
export async function exportTransferTemplateXlsx(parties = []) {
  const { default: ExcelJS } = await import('exceljs')
  const workbook = new ExcelJS.Workbook()
  const sheet = workbook.addWorksheet('调拨商品', { views: [{ state: 'frozen', ySplit: 1 }] })
  sheet.addRow(TRANSFER_COLUMNS)
  for (const index of [1, 2, 3]) sheet.getColumn(index).numFmt = '@'
  sheet.getColumn(1).width = 23
  sheet.getColumn(2).width = 23
  sheet.getColumn(3).width = 27
  sheet.getColumn(4).width = 18
  sheet.getColumn(4).numFmt = '0.####'
  sheet.getColumn(5).width = 42
  sheet.autoFilter = { from: 'A1', to: 'E1' }
  sheet.getRow(1).font = { bold: true, color: { argb: 'FFFFFFFF' } }
  sheet.getRow(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF254679' } }
  if (parties.length) {
    const reference = workbook.addWorksheet('调拨方编码')
    reference.addRow(['编码', '名称'])
    reference.getColumn(1).width = 23
    reference.getColumn(2).width = 36
    for (const party of parties) reference.addRow([String(party.key), String(party.name)])
  }
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

/** Validate the whole workbook before any catalog lookup or draft command. */
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
    throw new Error('请使用新版调拨模板，表头须包含调出方、调入方、商品编码、数量和明细备注。')
  }
  const rows = []
  const identities = new Set()
  for (let rowNumber = 2; rowNumber <= sheet.rowCount; rowNumber++) {
    const excelRow = sheet.getRow(rowNumber)
    if (!excelRow.hasValues) continue
    if (excelRow.cellCount > TRANSFER_COLUMNS.length) throw new Error(`第 ${rowNumber} 行有模板外的字段。`)
    for (const index of [1, 2, 3]) {
      if (typeof excelRow.getCell(index).value === 'number') throw new Error(`第 ${rowNumber} 行“${TRANSFER_COLUMNS[index - 1]}”必须为文本。`)
    }
    const [sourceKey, targetKey, code, quantity, detailRemark] = TRANSFER_COLUMNS.map((name, index) => plainCell(excelRow.getCell(index + 1), rowNumber, name))
    if (!targetKey || !/^(?:HQ|STORE):\d+$/.test(targetKey)) throw new Error(`第 ${rowNumber} 行调入方编码无效。`)
    if (sourceKey && !/^(?:HQ|STORE):\d+$/.test(sourceKey)) throw new Error(`第 ${rowNumber} 行调出方编码无效。`)
    if (!code || code.length > 100) throw new Error(`第 ${rowNumber} 行商品条码或编码无效。`)
    if (!/^\d+(?:\.\d{1,4})?$/.test(quantity) || Number(quantity) <= 0) throw new Error(`第 ${rowNumber} 行调拨数量须大于零且最多四位小数。`)
    if (detailRemark.length > 500) throw new Error(`第 ${rowNumber} 行明细备注不能超过 500 字。`)
    const identity = `${sourceKey}\u0000${targetKey}\u0000${code}`
    if (identities.has(identity)) throw new Error(`第 ${rowNumber} 行与前面的调拨明细重复。`)
    identities.add(identity)
    rows.push({ rowNumber, sourceKey, targetKey, code, quantity, detailRemark })
    if (rows.length > MAX_LINES) throw new Error('一批调拨最多导入 500 行商品。')
  }
  if (!rows.length) throw new Error('调拨文件没有商品记录。')
  return rows
}
