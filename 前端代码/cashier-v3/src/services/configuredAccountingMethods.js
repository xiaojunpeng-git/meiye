/** 新实际收款方式仅按本站配置启用；名称不决定是否计现金业绩。 */
export function configuredActualAccountingMethods(catalog = {}) {
  const rows = Array.isArray(catalog.accountingMethods) ? catalog.accountingMethods : []
  return ['bank_transfer', 'other_income'].flatMap((code) => {
    const row = rows.find((item) => item?.code === code && Number(item.status) === 1)
    return row ? [{ id: code, name: String(row.displayName || row.defaultName || (code === 'bank_transfer' ? '银行转账' : '其他收入')),
      canAdd: true, cashPerformanceEligible: true }] : []
  })
}

/** 目录控制可选入口；停用只影响新结算，不删除或改写历史结算记录。 */
export function filterConfiguredAccountingMethods(methods, catalog = null) {
  if (!catalog) return methods.filter((method) => method.id !== 'other_collection')
  const enabled = new Map((catalog.accountingMethods || []).filter((row) => Number(row.status) === 1)
    .map((row) => [row.code, row.displayName || row.defaultName]))
  return methods.filter((method) => enabled.has(method.id))
    .map((method) => ({ ...method, name: String(enabled.get(method.id) || method.name) }))
}
