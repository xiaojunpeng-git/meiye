// 会员预选与收银人员弹窗共用同一默认分配规则；这里只填草稿，最终事实由后端结账决定。
function groupKey(record) {
  const explicit = String(record.allocationGroupKey || '').trim()
  if (explicit.startsWith('independent:')) return explicit
  if (!record.performanceIndependent) return 'normal'
  return `independent:${Number(record.positionId || 0) || record.staffId || record.id}`
}

function groups(records, predicate = () => true) {
  const result = new Map()
  records.filter((record) => record.selected && predicate(record)).forEach((record) => {
    const key = groupKey(record)
    if (!result.has(key)) result.set(key, [])
    result.get(key).push(record)
  })
  return result
}

function split(total, records) {
  if (!records.length) return
  const base = Math.floor(total / records.length)
  let remainder = total - base * records.length
  records.forEach((record) => {
    record.performance = base + (remainder > 0 ? 1 : 0)
    remainder = Math.max(0, remainder - 1)
  })
}

export function defaultCraftsmanWeights(records) {
  groups(records, (record) => record.craftsmanPerformanceType !== 'labor').forEach((group) => split(100, group))
}

export function defaultSalespersonWeights(records) {
  groups(records).forEach((group) => {
    const ratio = Math.max(0, Math.min(100, Math.trunc(Number(group[0].partnerDefaultRatio || 0))))
    const partners = group.filter((record) => String(record.employeeTypeCode || '').toLowerCase() === 'partner')
    const others = group.filter((record) => String(record.employeeTypeCode || '').toLowerCase() !== 'partner')
    if (ratio <= 0 || !partners.length || !others.length) split(100, group)
    else {
      split(ratio, partners)
      split(100 - ratio, others)
    }
    group.forEach((record) => { record.performanceTouched = false })
  })
}

export function defaultProjectCounts(records, total, selectionChanged = false) {
  const tenths = Math.max(0, Math.round(Number(total || 0) * 10))
  const isPreviousDefault = (recordsInGroup, scale) => {
    const units = Math.max(0, Math.round(Number(total || 0) * scale))
    const base = Math.floor(units / recordsInGroup.length)
    const remainder = units - base * recordsInGroup.length
    return recordsInGroup.every((record, index) => {
      const expected = base + (index >= recordsInGroup.length - remainder ? 1 : 0)
      return Math.round(Number(record.projectCountText ?? record.projectCount ?? 0) * scale) === expected
    })
  }
  groups(records, (record) => record.role === 'craftsmen').forEach((group) => {
    const existing = group.filter((record) => record.projectCountTouched)
    // 选人时草稿里的数值无法可靠区分旧自动值和手填值；只保护本次
    // 明确手改的组。首次打开仍用旧默认值识别，避免覆盖历史回显。
    if (selectionChanged
      ? group.some((record) => record.projectCountManuallyEdited)
      : existing.length && !isPreviousDefault(existing, 10) && !isPreviousDefault(existing, 1000000)) return
    const base = Math.floor(tenths / group.length)
    const remainder = tenths - base * group.length
    group.forEach((record, index) => {
      const value = base + (index >= group.length - remainder ? 1 : 0)
      record.projectCountText = String(value / 10)
    })
  })
}
