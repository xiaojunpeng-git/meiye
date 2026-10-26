/** 仅用于报表展示：正负金额对称四舍五入为整元，不用于筛选、计算、保存或导出。 */
export function formatReportMoney(value) {
  if (value == null || value === '') return '-'
  const amount = Number(value)
  if (!Number.isFinite(amount)) return '-'
  const whole = Math.sign(amount) * Math.round(Math.abs(amount)) || 0
  return `¥${whole.toLocaleString('zh-CN', { maximumFractionDigits: 0 })}`
}
