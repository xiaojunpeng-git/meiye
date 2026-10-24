import { cloneUnifiedQuerySnapshot, normalizeUnifiedQueryFilter } from './unifiedQueryContract.js'

const NUMERIC_TYPES = new Set(['number', 'integer', 'decimal'])
const hasValue = (value) => value !== '' && value !== null && value !== undefined
const QUERY_KEYS = new Set(['pageCode', 'limit', 'keyword', 'dataScope', 'businessStatus', 'topFilters', 'sorts', 'groupBy', 'summaries', 'visibleFields', 'fieldVersions', 'queryCutoffDate', 'start_date', 'end_date', 'as_of_date', 'store_ids'])

// 后端元数据是筛选字段的唯一白名单；标题仅用于展示，不参与字段身份或查询推断。
export function reportFilterFields(fields = []) {
  const seen = new Set()
  return fields.filter((field) => {
    if (!field || typeof field.key !== 'string' || !field.key || seen.has(field.key)) return false
    if (field.defaultQuick !== true && field.filter?.enabled !== true) return false
    seen.add(field.key)
    return true
  })
}

// filter.type 可显式指定控件类型；否则沿用统一查询字段的数据类型。
export function reportFilterType(field) {
  const type = field.filter?.type || field.type
  if (NUMERIC_TYPES.has(type)) return 'number'
  return ['enum', 'date', 'month', 'datetime-local'].includes(type) ? type : 'text'
}

// 保留 0、false 等合法枚举值，禁止用显示标签替代后端 option.value。
export function reportFilterOptions(field) {
  const options = field.filter?.options || field.options || []
  return options.flatMap((option) => {
    if (option !== null && typeof option === 'object') {
      return hasValue(option.value) ? [{ value: option.value, label: option.label ?? String(option.value) }] : []
    }
    return hasValue(option) ? [{ value: option, label: String(option) }] : []
  })
}

function supportsFilter(field, filter) {
  return reportFilterType(field) === 'number'
    ? ['eq', 'gte', 'lte', 'between'].includes(filter.operator)
    : filter.operator === (reportFilterType(field) === 'text' ? 'contains' : 'eq')
}

// 外部查询复用统一 parser 的字段别名、操作符和 between 格式；恢复时不修改原快照。
export function reportFilterDraft(fields, query = {}) {
  const available = reportFilterFields(fields)
  const draft = Object.fromEntries(available.map((field) => [field.key, reportFilterType(field) === 'number' ? { min: '', max: '' } : '']))
  for (const raw of Array.isArray(query?.filters) ? query.filters : []) {
    const filter = normalizeUnifiedQueryFilter(raw)
    const field = available.find((item) => item.key === filter?.field)
    if (!field || !supportsFilter(field, filter)) continue
    // datetime-local 只在控件使用 T；后端事实与筛选仍用同一秒精度时间表达。
    if (reportFilterType(field) !== 'number') draft[field.key] = reportFilterType(field) === 'datetime-local'
      ? String(filter.value || '').replace(' ', 'T') : filter.value
    else {
      const range = draft[field.key]
      if (['gte', 'eq', 'between'].includes(filter.operator)) range.min = filter.value
      if (['lte', 'eq'].includes(filter.operator)) range.max = filter.value
      if (filter.operator === 'between') range.max = filter.valueTo
    }
  }
  return draft
}

// 工具栏只生成服务端筛选，不处理结果行或经营指标；上下界以原业务单位提交。
// 未提供控件的外部条件原样保留，避免打开查询栏时悄悄扩大下钻/权限内筛选范围。
export function buildReportFilterQuery(fields, draft, executedQuery = {}, pageCode = '') {
  const available = reportFilterFields(fields)
  const snapshot = cloneUnifiedQuerySnapshot(executedQuery)
  const filters = (Array.isArray(snapshot.filters) ? snapshot.filters : []).filter((raw) => {
    const filter = normalizeUnifiedQueryFilter(raw)
    const field = available.find((item) => item.key === filter?.field)
    return !field || !supportsFilter(field, filter)
  })
  for (const field of available) {
    const value = draft[field.key]
    if (reportFilterType(field) === 'number') {
      const range = value || {}
      const bounds = [range.min, range.max].filter(hasValue)
      if (bounds.some((bound) => !Number.isFinite(Number(bound)))) throw new Error(`${field.label || field.key}请输入有效数值`)
      if (hasValue(range.min) && hasValue(range.max) && Number(range.min) > Number(range.max)) {
        throw new Error(`${field.label || field.key}的最小值不能大于最大值`)
      }
      if (hasValue(range.min)) filters.push({ field: field.key, operator: 'gte', value: range.min })
      if (hasValue(range.max)) filters.push({ field: field.key, operator: 'lte', value: range.max })
    } else if (hasValue(value) && (typeof value !== 'string' || value.trim())) {
      const input = typeof value === 'string' ? value.trim() : value
      const datetime = reportFilterType(field) === 'datetime-local' ? input.replace('T', ' ') : null
      filters.push({ field: field.key, operator: reportFilterType(field) === 'text' ? 'contains' : 'eq',
        value: datetime === null ? input : datetime.length === 16 ? `${datetime}:00` : datetime })
    }
  }
  return {
    // 查询仅继承已知查询上下文；export/任务/下载信息不能随下一次普通查询重放。
    ...Object.fromEntries(Object.entries(snapshot).filter(([key]) => QUERY_KEYS.has(key))),
    ...(pageCode ? { pageCode } : {}),
    page: 1,
    limit: snapshot.limit || 20,
    filters,
    filterRelation: 'all'
  }
}
