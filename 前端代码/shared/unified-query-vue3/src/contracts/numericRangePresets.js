// 非负数量字段的快捷模式；只生成统一查询条件，不在客户端过滤或汇总业务数据。
export const numericRangePresets = Object.freeze([
  { value: 'positive', label: '大于0' },
  { value: 'zero', label: '等于0' },
  { value: 'custom', label: '自定义' },
  { value: 'all', label: '全部' }
])

export function numericRangePresetMode(value) {
  return numericRangePresets.some(option => option.value === value) ? value : 'positive'
}

/** 全部包含零库存；显式 >=0 也让领域查询加载零结存批次，而非沿用仅正库存的默认源窗口。 */
export function numericRangePresetFilters(field, mode, range = {}) {
  const selected = numericRangePresetMode(mode)
  if (selected === 'positive') return [{ field, operator: 'gt', value: '0' }]
  if (selected === 'zero') return [{ field, operator: 'eq', value: '0' }]
  if (selected === 'all') return [{ field, operator: 'gte', value: '0' }]
  const filters = ['min', 'max'].flatMap(bound => {
    const value = range[bound]
    return value === undefined || value === null || value === '' ? [] : [{
      field, operator: bound === 'min' ? 'gte' : 'lte', value
    }]
  })
  return filters.length ? filters : [{ field, operator: 'gte', value: '0' }]
}
