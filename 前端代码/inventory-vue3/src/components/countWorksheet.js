// 盘点工作簿保留可见列；按批次行用隐藏的粒度列防止往返后被误当成商品总量。
export const COUNT_COLUMNS = ['商品ID', '商品名称', '规格', '单位', '商品条码', '账面库存', '实盘库存', '库存盈亏', '盘盈批次号', '盘盈单价', '生产日期', '到期日']
const SHEET_NAME = '盘点资料'
const NUMERIC_COLUMNS = new Set([5, 6, 7, 9])

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
  const hasBatchMode = rows.some((row) => row[12] === 'BATCH')
  if (hasBatchMode) { sheet.getCell('M1').value = '盘点粒度'; sheet.getColumn(13).hidden = true }
  for (const row of rows) {
    const excelRow = sheet.addRow(row.map((value, index) => {
      const text = String(value ?? '')
      if (!NUMERIC_COLUMNS.has(index) || !text.trim()) return text
      const number = Number(text)
      return Number.isFinite(number) ? number : text
    }))
    // 盈亏公式只供表格参考；导入及完成盘点均以实盘数为准，不校验导出账面快照。
    const book = Number(row[5]); const counted = Number(row[6])
    if (Number.isFinite(book) && Number.isFinite(counted)) {
      excelRow.getCell(8).value = { formula: `G${excelRow.number}-F${excelRow.number}`, result: quantityDifference(book, counted) }
    }
  }
  sheet.autoFilter = { from: 'A1', to: 'L1' }
  sheet.columns.forEach((column, index) => { column.width = [14, 28, 18, 12, 24, 15, 15, 15, 20, 16, 17, 17, 12][index] })
  sheet.getRow(1).font = { bold: true, color: { argb: 'FFFFFFFF' } }
  sheet.getRow(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF254679' } }
  for (const index of NUMERIC_COLUMNS) sheet.getColumn(index + 1).numFmt = index === 9 ? '0.00' : '0.####'
  // Excel 会把用户填入空白批次的前导零抹掉、把 ISO 日期改作日期序列；
  // 导出时预先把这些可编辑列设为文本，避免往返导入后静默改变批次身份或日期。
  for (const index of [0, 1, 2, 3, 4, 8, 10, 11]) sheet.getColumn(index + 1).numFmt = '@'
  // 库存数量和单价写真实数值；商品/规格/条码/批次及 ISO 日期保持文本原貌。
  return new Uint8Array(await workbook.xlsx.writeBuffer())
}

function cellText(cell, rowNumber, columnNumber, date1904 = false) {
  const value = cell.value
  if (value == null) return ''
  if (columnNumber >= 11 && columnNumber <= 12 && typeof value === 'number' && Number.isInteger(value)) {
    // ExcelJS serializes a Date written into a text-formatted date column as an Excel
    // day number. Decode only valid date-column serials; other numeric input is not a date.
    const epoch = date1904 ? Date.UTC(1904, 0, 1) : Date.UTC(1899, 11, 30)
    const date = new Date(epoch + value * 86400000)
    if (value >= 1 && date.getUTCFullYear() >= 1900 && date.getUTCFullYear() <= 9999) {
      return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`
    }
  }
  if (value instanceof Date) {
    if (columnNumber < 11 || columnNumber > 12 || Number.isNaN(value.getTime())) {
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

// 按业务列名定位，不依赖导出来源、工作表名或列顺序；未知列只供用户参考。
const COLUMN_ALIASES = [
  ['商品ID', '产品ID'], ['商品名称', '产品名称'], ['规格', '商品规格'], ['单位'], ['商品条码', '条码'],
  ['账面库存', '帐面库存'], ['实盘库存', '实盘数量', '实盘数', '盘点数量'], ['库存盈亏'],
  ['盘盈批次号', '批次号', '批号'], ['盘盈单价', '单价'], ['生产日期'], ['到期日', '到期日期', '有效期'], ['盘点粒度']
]
const headerName = (value) => String(value ?? '').replace(/\s/g, '').toUpperCase()

/** 当前系统一商品一规格，仅按商品 ID 取权限范围内的系统记录，不核对文件描述列。 */
export function matchCountProduct(cells, catalog, rowNumber) {
  const product = catalog instanceof Map ? catalog.get(Number(cells[0])) : catalog.find((item) => Number(item.product_id) === Number(cells[0]))
  if (!product) throw new Error(`第 ${rowNumber} 行商品 ID ${cells[0]} 在当前可盘点商品中不存在，请核对商品 ID。`)
  return product
}

/** 接收用户自制工作簿，统一成现有盘点输入；粒度仍显式声明，不能用批号猜测总量/批次。 */
export async function parseCountXlsx(buffer) {
  const { default: ExcelJS } = await import('exceljs')
  const workbook = new ExcelJS.Workbook()
  try { await workbook.xlsx.load(buffer) }
  catch { throw new Error('盘点文件不是有效的 xlsx 工作簿。') }
  const date1904 = Boolean(workbook.properties.date1904)
  let sheet, headerRow, columns
  // 允许标题、说明页和空行；选择第一个含商品 ID 与实盘数量表头的数据表，不混合多个表。
  for (const candidate of workbook.worksheets) {
    for (let number = 1; number <= candidate.rowCount; number++) {
      const row = candidate.getRow(number)
      const names = Array.from({ length: row.cellCount }, (_, index) => headerName(row.getCell(index + 1).text))
      const mapped = COLUMN_ALIASES.map((aliases) => names.findIndex((name) => aliases.some((alias) => headerName(alias) === name)) + 1)
      if (!mapped[0] || !mapped[6]) continue
      for (const aliases of COLUMN_ALIASES) {
        if (names.filter((name) => aliases.some((alias) => headerName(alias) === name)).length > 1) {
          throw new Error(`第 ${number} 行表头“${aliases[0]}”重复，请保留一列。`)
        }
      }
      sheet = candidate; headerRow = number; columns = mapped
      break
    }
    if (sheet) break
  }
  if (!sheet) throw new Error('请在表头中提供“商品ID”和“实盘库存”（或“实盘数量”）两列，其他列可选。')
  const hasBatchMode = Boolean(columns[12])
  const rows = []
  for (let rowNumber = headerRow + 1; rowNumber <= sheet.rowCount; rowNumber++) {
    const row = sheet.getRow(rowNumber)
    if (!columns.some((column) => column && row.getCell(column).value != null && row.getCell(column).text.trim())) continue
    const values = COUNT_COLUMNS.map((_, index) => {
      if (!columns[index]) return ''
      const cell = row.getCell(columns[index])
      if (index === 7 && cell.formula) {
        // 盈亏完全不作为输入；兼容旧导出往返展示，但不检查用户改过的公式。
        const book = Number(columns[5] ? row.getCell(columns[5]).value : 0); const counted = Number(row.getCell(columns[6]).value)
        return Number.isFinite(book) && Number.isFinite(counted) ? String(quantityDifference(book, counted)) : ''
      }
      // 名称/单位/条码/账面/盈亏不参与身份或库存写入，不因这些参考列的格式阻断导入。
      if ([1, 2, 3, 4, 5, 7].includes(index)) return typeof cell.value === 'object' ? '' : String(cell.value ?? '').trim()
      const text = cellText(cell, rowNumber, index + 1, date1904).trim()
      return index >= 10 ? countDateText(text, rowNumber) : text
    })
    if (!values.some((value) => value !== '')) {
      continue
    }
    if (hasBatchMode) {
      const mode = cellText(row.getCell(columns[12]), rowNumber, 13, date1904).trim().toUpperCase()
      if (!['SKU', 'BATCH', ''].includes(mode)) throw new Error(`第 ${rowNumber} 行盘点粒度无效。`)
      values.push(mode || 'SKU')
    }
    if (!/^\d+$/.test(values[0])) throw new Error(`第 ${rowNumber} 行商品 ID 无效。`)
    // 保留原始 Excel 行号供 UI 指出错误，不改变旧数组契约及导出字段。
    Object.defineProperty(values, 'sourceRow', { value: rowNumber })
    rows.push(values)
  }
  if (!rows.length) throw new Error('盘点文件没有商品记录。')
  return rows
}
