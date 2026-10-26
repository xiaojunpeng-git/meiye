/** 查询周期只传本地日历日期，不经 UTC 转换，避免时区造成日期前移；不计算业务金额。 */
export function dateKey(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
export function parseDateKey(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return null
  const [year, month, day] = value.split('-').map(Number)
  const date = new Date(year, month - 1, day, 12)
  return dateKey(date) === value ? date : null
}
export const periodOptions = [
  ['today', '今天'], ['yesterday', '昨天'], ['last7', '最近7天'], ['last30', '最近30天'],
  ['lastMonth', '上月'], ['month', '本月'], ['year', '本年']
]
/** 最近 N 天包含今天；自然月/年取完整日历边界，与任意手选起止日使用同一契约。 */
export function periodRange(key, now = new Date()) {
  const y = now.getFullYear(), m = now.getMonth(), d = now.getDate()
  let start = new Date(y, m, d, 12), end = new Date(y, m, d, 12)
  if (key === 'yesterday') start = end = new Date(y, m, d - 1, 12)
  else if (key === 'last7' || key === 'last30') start = new Date(y, m, d - (key === 'last7' ? 6 : 29), 12)
  else if (key === 'lastMonth') { start = new Date(y, m - 1, 1, 12); end = new Date(y, m, 0, 12) }
  else if (key === 'month') { start = new Date(y, m, 1, 12); end = new Date(y, m + 1, 0, 12) }
  else if (key === 'year') { start = new Date(y, 0, 1, 12); end = new Date(y, 11, 31, 12) }
  else if (key !== 'today') throw new Error('Unknown query period')
  return { min: dateKey(start), max: dateKey(end) }
}
/** 六周日历保留前后月日期，允许跨月选区；月份翻页不修改已提交条件。 */
export function monthDays(month) {
  const first = new Date(month.getFullYear(), month.getMonth(), 1, 12)
  return Array.from({ length: 42 }, (_, i) => {
    const day = new Date(first.getFullYear(), first.getMonth(), 1 - first.getDay() + i, 12)
    return { key: dateKey(day), day: day.getDate(), outside: day.getMonth() !== first.getMonth() }
  })
}
