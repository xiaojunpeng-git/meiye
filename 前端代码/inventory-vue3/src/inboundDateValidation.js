function isCalendarDate(value) {
  const matched = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || '').trim())
  if (!matched) return false
  const year = Number(matched[1])
  const month = Number(matched[2])
  const day = Number(matched[3])
  const parsed = new Date(Date.UTC(year, month - 1, day))
  return parsed.getUTCFullYear() === year && parsed.getUTCMonth() === month - 1 && parsed.getUTCDate() === day
}

/**
 * 在提交前校验浏览器日期控件对应的真实模型值。
 * Chromium 可能把“6 月 31 日”等无效分段保留在输入框里，却向 v-model 提供空字符串；
 * bad_input 标记用于区分“用户输入了无效日期”和“用户确实没有填写”。
 */
export function validateInboundDates(rows) {
  for (const [index, row] of (Array.isArray(rows) ? rows : []).entries()) {
    const lineNumber = index + 1
    const manufacturedDate = String(row?.manufactured_date || '').trim()
    const expireDate = String(row?.expire_date || '').trim()
    if (row?.manufactured_date_bad_input || (manufacturedDate && !isCalendarDate(manufacturedDate))) {
      return { valid: false, message: `第 ${lineNumber} 行生产日期不是有效日历日期（例如 6 月没有 31 日），请重新选择。` }
    }
    if (row?.expire_date_bad_input || (expireDate && !isCalendarDate(expireDate))) {
      return { valid: false, message: `第 ${lineNumber} 行到期日不是有效日历日期，请重新选择。` }
    }
    if (!manufacturedDate || !expireDate) {
      return { valid: false, message: `请填写第 ${lineNumber} 行商品的生产日期和到期日。` }
    }
    if (manufacturedDate > expireDate) {
      return { valid: false, message: `第 ${lineNumber} 行到期日不能早于生产日期。` }
    }
  }
  return { valid: true, message: '' }
}
