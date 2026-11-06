/** 会员分析条件只有点击查询才生效；日期沿用共享周期组件，金额输入单位为元。
 * 条件名称和标识为双端唯一契约，不能把现金业绩替换成含余额消费的总消费金额。
 */
export const memberAnalysisFields = [
  { key: 'cashPerformance', label: '现金业绩', kind: 'amount' },
  { key: 'consumePeriod', label: '最近有消费', kind: 'period' },
  { key: 'consumeCount', label: '消费次数', kind: 'count' },
  { key: 'visitCount', label: '到店次数', kind: 'count' },
  { key: 'visitPeriod', label: '最近有到店', kind: 'period' },
  { key: 'noVisitPeriod', label: '最近无到店', kind: 'period' },
  { key: 'totalAssets', label: '总资产', kind: 'amount' },
  { key: 'remainingTimes', label: '剩余权益总次数', kind: 'count' },
  { key: 'remainingAmount', label: '剩余权益总金额', kind: 'amount' }
]
export function memberAnalysisDraft(value = {}) {
  return Object.fromEntries(memberAnalysisFields.map(field => [field.key, { min: value?.[field.key]?.min ?? '', max: value?.[field.key]?.max ?? '' }]))
}
export function buildMemberAnalysis(draft) {
  const result = {}
  for (const { key, label, kind } of memberAnalysisFields) {
    const range = draft[key] || {}
    const min = String(range.min ?? '').trim(), max = String(range.max ?? '').trim()
    if (!min && !max) continue
    if (kind === 'period') {
      if (!min || !max || min > max) throw new Error(`${label}请选择完整且有效的周期。`)
    } else {
      const pattern = kind === 'count' ? /^\d+$/ : /^\d+(?:\.\d{1,2})?$/
      if ((min && !pattern.test(min)) || (max && !pattern.test(max))) throw new Error(`${label}请输入非负${kind === 'count' ? '整数' : '金额（最多两位小数）'}。`)
      if ((min && Number(min) > 1000000000) || (max && Number(max) > 1000000000)) throw new Error(`${label}超出允许范围。`)
      if (min && max && Number(min) > Number(max)) throw new Error(`${label}最小值不能大于最大值。`)
    }
    result[key] = { min, max }
  }
  return result
}
