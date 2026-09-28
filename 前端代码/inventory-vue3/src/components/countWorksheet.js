// 盘点文件仅包含当前表格可见列；商品身份由可见的商品 ID、名称、规格、条码组合核对。
// 文件不携带写库指令，导入只恢复前端草稿；最终库存仍由服务端盘点命令确认。
export const COUNT_COLUMNS = ['商品ID', '商品名称', '商品规格', '商品条码', '账面库存', '实盘库存', '库存盈亏', '盘盈批次号', '盘盈单价', '生产日期', '到期日']

function escapeCell(value) {
  const text = String(value ?? '')
  // 避免 Excel 将商品名称等服务端文本解释为公式；导入时还原该转义。
  const safe = /^[=+@]/.test(text) ? `'${text}` : text
  return `"${safe.replaceAll('"', '""')}"`
}

export function exportCountCsv(rows) {
  return '\uFEFF' + [COUNT_COLUMNS, ...rows].map((row) => row.map(escapeCell).join(',')).join('\r\n')
}

export function parseCountCsv(text) {
  const raw = String(text).replace(/^\uFEFF/, '')
  const rows = []
  let row = [], cell = '', quoted = false
  for (let i = 0; i < raw.length; i++) {
    const char = raw[i]
    if (quoted) {
      if (char === '"' && raw[i + 1] === '"') { cell += '"'; i++ }
      else if (char === '"') quoted = false
      else cell += char
    } else if (char === '"' && cell === '') quoted = true
    else if (char === ',') { row.push(cell); cell = '' }
    else if (char === '\n' || char === '\r') {
      if (char === '\r' && raw[i + 1] === '\n') i++
      row.push(cell); rows.push(row); row = []; cell = ''
    } else cell += char
  }
  if (quoted) throw new Error('盘点文件的引号不完整。')
  if (row.length || cell !== '') { row.push(cell); rows.push(row) }
  if (JSON.stringify(rows.shift()) !== JSON.stringify(COUNT_COLUMNS) || !rows.length) {
    throw new Error('请选择本页导出的盘点文件，且不要修改表头。')
  }
  return rows.map((item, index) => {
    if (item.length !== COUNT_COLUMNS.length || !/^\d+$/.test(item[0])) {
      throw new Error(`第 ${index + 2} 行列数或商品 ID 无效。`)
    }
    return item.map((value) => /^'[=+@]/.test(value) ? value.slice(1) : value)
  })
}
