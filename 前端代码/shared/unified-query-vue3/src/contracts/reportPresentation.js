/** 仅用于报表展示：默认整元；明确要求分位的人工登记传 2，不改变计算、保存或导出原值。 */
export function formatReportMoney(value, fractionDigits = 0) {
  if (value == null || value === '') return '-'
  const amount = Number(value)
  if (!Number.isFinite(amount)) return '-'
  if (fractionDigits === 2) return `¥${amount.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  const whole = Math.sign(amount) * Math.round(Math.abs(amount)) || 0
  return `¥${whole.toLocaleString('zh-CN', { maximumFractionDigits: 0 })}`
}
