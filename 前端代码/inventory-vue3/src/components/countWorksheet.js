// 盘点工作簿只包含当前表格可见列。导入仅恢复前端草稿，正式库存仍由服务端盘点命令确认。
export const COUNT_COLUMNS = ['商品ID', '商品名称', '商品规格', '商品条码', '账面库存', '实盘库存', '库存盈亏', '盘盈批次号', '盘盈单价', '生产日期', '到期日']
const SHEET_NAME = '盘点资料'
const NUMERIC_COLUMNS = new Set([4, 5, 6, 8])

function quantityDifference(book, counted) {
  // 盘点数量最多四位小数；避免二进制浮点尾数使重新导入时误报盈亏不一致。
  return Number((counted - book).toFixed(4))
}

/** 标识与批次作为文本写入，避免 Excel 截断条码前导零或将商品名称当作公式。 */
export async function exportCountXlsx(rows) {
  const { default: ExcelJS } = await import('exceljs')
  const workbook = new ExcelJS.Workbook()
  workbook.calcProperties.fullCalcOnLoad = true
  const sheet = workbook.addWorksheet(SHEET_NAME, { views: [{ state: 'frozen', ySplit: 1 }] })
  sheet.addRow(COUNT_COLUMNS)
  for (const row of rows) {
    const excelRow = sheet.addRow(row.map((value, index) => {
      const text = String(value ?? '')
      if (!NUMERIC_COLUMNS.has(index) || !text.trim()) return text
      const number = Number(text)
      return Number.isFinite(number) ? number : text
    }))
    // 用户只需修改“实盘库存”，Excel 即重算盈亏；导入时服务端口径仍以两列库存数重新核对。
    const book = Number(row[4]); const counted = Number(row[5])
    if (Number.isFinite(book) && Number.isFinite(counted)) {
      excelRow.getCell(7).value = { formula: `F${excelRow.number}-E${excelRow.number}`, result: quantityDifference(book, counted) }
    }
  }
  sheet.autoFilter = { from: 'A1', to: 'K1' }
  sheet.columns.forEach((column, index) => { column.width = [14, 28, 18, 24, 15, 15, 15, 20, 16, 17, 17][index] })
  sheet.getRow(1).font = { bold: true, color: { argb: 'FFFFFFFF' } }
  sheet.getRow(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF254679' } }
  for (const index of NUMERIC_COLUMNS) sheet.getColumn(index + 1).numFmt = index === 8 ? '0.00' : '0.####'
  // Excel 会把用户填入空白批次的前导零抹掉、把 ISO 日期改作日期序列；
  // 导出时预先把这些可编辑列设为文本，避免往返导入后静默改变批次身份或日期。
  for (const index of [0, 1, 2, 3, 7, 9, 10]) sheet.getColumn(index + 1).numFmt = '@'
  // 库存数量和单价写真实数值；商品/规格/条码/批次及 ISO 日期保持文本原貌。
  return new Uint8Array(await workbook.xlsx.writeBuffer())
}

function cellText(cell, rowNumber, columnNumber, date1904 = false) {
  const value = cell.value
  if (value == null) return ''
  if (columnNumber >= 10 && columnNumber <= 11 && typeof value === 'number' && Number.isInteger(value)) {
    // ExcelJS serializes a Date written into a text-formatted date column as an Excel
    // day number. Decode only valid date-column serials; other numeric input is not a date.
    const epoch = date1904 ? Date.UTC(1904, 0, 1) : Date.UTC(1899, 11, 30)
    const date = new Date(epoch + value * 86400000)
    if (value >= 1 && date.getUTCFullYear() >= 1900 && date.getUTCFullYear() <= 9999) {
      return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`
    }
  }
  if (value instanceof Date) {
    if (columnNumber < 10 || columnNumber > 11 || Number.isNaN(value.getTime())) {
      throw new Error(`第 ${rowNumber} 行第 ${columnNumber} 列不是有效文本或数值。`)
    }
    return `${value.getUTCFullYear()}-${String(value.getUTCMonth() + 1).padStart(2, '0')}-${String(value.getUTCDate()).padStart(2, '0')}`
  }
  // 不执行、不采信公式、超链接或富文本缓存值；盘点输入必须是可审计的普通单元格。
  if (typeof value !== 'string' && (typeof value !== 'number' || !Number.isFinite(value))) {
    throw new Error(`第 ${rowNumber} 行第 ${columnNumber} 列含公式或不支持的单元格内容。`)
  }
  return String(value)
}

/** 接受 Excel 手工输入的单数字月/日，统一为接口日期；不存在的日历日期必须拒绝。 */
function countDateText(value, rowNumber) {
  if (!value) return ''
  const match = /^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/.exec(value.trim())
  if (!match) throw new Error(`第 ${rowNumber} 行日期格式无效。`)
  const year = Number(match[1]); const month = Number(match[2]); const day = Number(match[3])
  const date = new Date(Date.UTC(year, month - 1, day))
  if (year < 1900 || date.getUTCFullYear() !== year || date.getUTCMonth() + 1 !== month || date.getUTCDate() !== day) {
    throw new Error(`第 ${rowNumber} 行日期格式无效。`)
  }
  return `${match[1]}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

/** 严格核对同一导出模板的表头和行宽；下游仍逐行比对服务端 SKU 与实时账面数。 */
export async function parseCountXlsx(buffer) {
  const { default: ExcelJS } = await import('exceljs')
  const workbook = new ExcelJS.Workbook()
  try { await workbook.xlsx.load(buffer) }
  catch { throw new Error('盘点文件不是有效的 xlsx 工作簿。') }
  if (workbook.worksheets.length !== 1 || workbook.worksheets[0]?.name !== SHEET_NAME) {
    throw new Error('请选择本页导出的盘点文件，且不要修改工作表。')
  }
  const sheet = workbook.worksheets[0]
  const date1904 = Boolean(workbook.properties.date1904)
  const header = COUNT_COLUMNS.map((_, index) => cellText(sheet.getRow(1).getCell(index + 1), 1, index + 1, date1904))
  if (JSON.stringify(header) !== JSON.stringify(COUNT_COLUMNS)) {
    throw new Error('请选择本页导出的盘点文件，且不要修改表头。')
  }
  const rows = []
  for (let rowNumber = 2; rowNumber <= sheet.rowCount; rowNumber++) {
    const row = sheet.getRow(rowNumber)
    const values = COUNT_COLUMNS.map((_, index) => {
      const cell = row.getCell(index + 1)
      if (index === 6 && cell.value && typeof cell.value === 'object' && cell.formula) {
        // Excel may rewrite identical row formulas as a shared formula; ExcelJS resolves each
        // cell's effective formula here, so only the exported F-E calculation is accepted.
        if (cell.formula !== `F${rowNumber}-E${rowNumber}`) {
          throw new Error(`第 ${rowNumber} 行库存盈亏公式已被修改。`)
        }
        const book = Number(row.getCell(5).value); const counted = Number(row.getCell(6).value)
        return Number.isFinite(book) && Number.isFinite(counted) ? String(quantityDifference(book, counted)) : ''
      }
      const text = cellText(cell, rowNumber, index + 1, date1904)
      return index >= 9 ? countDateText(text, rowNumber) : text
    })
    if (!values.some((value) => value !== '')) {
      if (rowNumber < sheet.rowCount) throw new Error(`第 ${rowNumber} 行为空，请删除空行后重试。`)
      continue
    }
    for (let columnNumber = COUNT_COLUMNS.length + 1; columnNumber <= row.cellCount; columnNumber++) {
      if (cellText(row.getCell(columnNumber), rowNumber, columnNumber, date1904) !== '') {
        throw new Error(`第 ${rowNumber} 行存在模板之外的列。`)
      }
    }
    if (!/^\d+$/.test(values[0])) throw new Error(`第 ${rowNumber} 行商品 ID 无效。`)
    rows.push(values)
  }
  if (!rows.length) throw new Error('盘点文件没有商品记录。')
  return rows
}
