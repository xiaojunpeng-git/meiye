/** 更正命令提交来源快照原值；列表的空值占位符不属于数据库内容。 */
export function orderAttributionExpectedValue(record, field, displayedValue) {
  if (field !== 'source') return displayedValue
  if (record.sourceExpectedValue !== undefined && record.sourceExpectedValue !== null) {
    return String(record.sourceExpectedValue)
  }
  // 兼容尚未提供原值字段的旧接口，只还原没有任何来源 ID 的空值占位符。
  if (displayedValue === '—' && Number(record.businessSourcePrimaryId || 0) === 0
    && Number(record.businessSourceSecondaryId || 0) === 0) return ''
  return displayedValue
}
