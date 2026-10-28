/**
 * Order-centre personnel cells only format amounts already assigned by the
 * server's immutable performance facts. They never divide an order total or
 * infer missing wages/performance from the item's selling price.
 */
function wholeYuan(value) {
  if (value === undefined || value === null || value === '' || !Number.isFinite(Number(value))) return '—'
  return String(Math.round(Number(value)))
}

function personName(person) {
  return String(person?.name || person?.employeeName || person?.employee_name_snapshot || '').trim()
}

/**
 * 空人员单元格只读取服务端明确的岗位适用性；旧记录缺失契约时保持“—”，
 * 防止前端把“不适用”和“可分配但未分配”混为一谈。
 */
export function personnelEmptyText(item, role) {
  return item?.assignmentAvailability?.[role] === true ? '未分配' : '—'
}

export function salespeoplePerformanceText(people) {
  const records = Array.isArray(people) ? people : []
  return records.map((person) => {
    const name = personName(person)
    if (!name) return ''
    const isPresale = person?.isPreSale === true || person?.isPresale === true
      || String(person?.roleSnapshot || person?.role_snapshot || '').toLowerCase().endsWith(':presale')
    const amount = wholeYuan(person?.salesPerformanceAmount ?? person?.performanceAmount)
    return `${name}（${isPresale ? '售前、' : ''}${amount}）`
  }).filter(Boolean).join('，') || '—'
}

/** 导购轮次是结账时保存的归属事实；缺失轮次不得按订单先后猜测。 */
export function guideRoundText(people) {
  const records = Array.isArray(people) ? people : []
  return records.map((person) => {
    const name = personName(person)
    if (!name) return ''
    const round = Number(person?.guideRoundNo ?? person?.guide_round_no ?? 0)
    return `${name}（${Number.isInteger(round) && round >= 1 && round <= 3 ? `第${round}轮` : '无'}）`
  }).filter(Boolean).join('、') || '—'
}

// 可隐藏手工费这一展示分量；只格式化原事实，不改写输入或人员分配。
export function serviceCraftsmenPerformanceText(record, showLaborFee = true) {
  const allocations = record?.craftsmenListAllocations || record?.laborPerformanceAllocations
  if (!Array.isArray(allocations) || !allocations.length) return record?.craftsmenSummary || '—'
  return allocations.map((person) => {
    const name = personName(person)
    if (!name) return ''
    // 历史未选择点客的记录统一为轮客；不修改其业绩或项目数。
    const type = person?.isPointCustomer === true ? '点' : '轮'
    const projectCount = person?.projectCount === undefined || person?.projectCount === null || person?.projectCount === ''
      ? '—' : String(person.projectCount)
    const parts = [type, wholeYuan(person?.amount)]
    if (showLaborFee) parts.push(wholeYuan(person?.laborFeeAmount))
    parts.push(projectCount)
    return `${name}（${parts.join('、')}）`
  }).filter(Boolean).join('，') || '—'
}
