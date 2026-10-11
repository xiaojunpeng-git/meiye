/**
 * 批号栏只显示业务录入的批号；免录商品的 AUTO 成本层编号不是实物批号。
 * 精确匹配后端生成格式，避免误隐藏普通 AUTO 开头的真实批号。
 * 仅供展示，不能回写原始批次字段或用于盘点、扣减、冲销的身份匹配。
 */
export function formatBatchNumber(value) {
  if (value === null || value === undefined || value === '') return ''
  const batchNumber = String(value)
  return /^AUTO-(?:IN|COUNT)-[a-f0-9]{48}$/.test(batchNumber) ? '' : batchNumber
}
