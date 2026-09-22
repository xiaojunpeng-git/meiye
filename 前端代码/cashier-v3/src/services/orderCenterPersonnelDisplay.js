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

export function serviceCraftsmenPerformanceText(record) {
  const allocations = record?.craftsmenListAllocations || record?.laborPerformanceAllocations
  if (!Array.isArray(allocations) || !allocations.length) return record?.craftsmenSummary || '—'
  return allocations.map((person) => {
    const name = personName(person)
    if (!name) return ''
    const type = person?.isPointCustomer === true ? '点' : person?.isPointCustomer === false ? '轮' : '—'
    const projectCount = person?.projectCount === undefined || person?.projectCount === null || person?.projectCount === ''
      ? '—' : String(person.projectCount)
    return `${name}（${type}、${wholeYuan(person?.amount)}、${wholeYuan(person?.laborFeeAmount)}、${projectCount}）`
  }).filter(Boolean).join('，') || '—'
}
