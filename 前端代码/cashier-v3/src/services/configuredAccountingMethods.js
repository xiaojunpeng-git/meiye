/** 新实际收款方式仅按本站配置启用；企微与普通微信是不同的收款事实。 */
export function configuredActualAccountingMethods(catalog = {}) {
  const rows = Array.isArray(catalog.accountingMethods) ? catalog.accountingMethods : []
  return ['enterprise_wechat', 'bank_transfer', 'other_income'].flatMap((code) => {
    const row = rows.find((item) => item?.code === code && Number(item.status) === 1)
    const fallback = { enterprise_wechat: '企微收款', bank_transfer: '银行转账', other_income: '其他收入' }
    return row ? [{ id: code, name: String(row.displayName || row.defaultName || fallback[code]),
      canAdd: true, cashPerformanceEligible: true }] : []
  })
}

/** 目录控制可选入口和顺序；停用只影响新结算，不删除或改写历史结算记录。 */
export function filterConfiguredAccountingMethods(methods, catalog = null) {
  if (!catalog) return methods.filter((method) => method.id !== 'other_collection')
  const enabled = new Map((catalog.accountingMethods || []).filter((row) => Number(row.status) === 1)
    .map((row, index) => [row.code, { name: row.displayName || row.defaultName, sort: Number(row.sort), index }]))
  return methods.filter((method) => enabled.has(method.id))
    .map((method) => ({ ...method, name: String(enabled.get(method.id).name || method.name) }))
    .sort((a, b) => {
      const left = enabled.get(a.id)
      const right = enabled.get(b.id)
      return (left.sort - right.sort) || (left.index - right.index)
    })
}
