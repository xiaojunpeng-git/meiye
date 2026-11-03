/** 只保存后端已生成的 XLSX；错误 JSON/CSV 不得通过改扩展名伪装成工作簿。 */
export function saveReportWorkbook(blob, filename = '报表.xlsx') {
  if (!(blob instanceof Blob) || blob.size === 0 || !String(blob.type || '').includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
    throw new Error('导出结果不是有效的 XLSX 工作簿，请重试。')
  }
  const safeName = String(filename).replace(/[\\/:*?"<>|\u0000-\u001f]/g, '-')
  if (!/\.xlsx$/i.test(safeName)) throw new Error('报表下载必须使用 XLSX 格式。')
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = safeName
  // 内嵌平台与门店页面都通过真实 DOM 下载链接触发浏览器保存，随后释放临时资源。
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/** 优先读取 UTF-8 扩展文件名，避免标准 ASCII 备用名覆盖中文报表名。 */
export function reportWorkbookFilename(disposition, fallback = '报表.xlsx') {
  const value = String(disposition || '')
  const utf8 = value.match(/filename\*\s*=\s*UTF-8''([^;]+)/i)
  const plain = value.match(/filename\s*=\s*(?:"([^"]*)"|([^;]*))/i)
  try {
    return utf8 ? decodeURIComponent(utf8[1].trim()) : (plain?.[1] || plain?.[2]?.trim() || fallback)
  } catch (_) {
    return fallback
  }
}
