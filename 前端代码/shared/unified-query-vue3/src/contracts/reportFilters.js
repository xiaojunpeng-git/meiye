import { parseDateKey } from './dateRange.js'
import { cloneUnifiedQuerySnapshot, normalizeUnifiedQueryFilter } from './unifiedQueryContract.js'

const NUMERIC_TYPES = new Set(['number', 'integer', 'decimal'])
const hasValue = (value) => value !== '' && value !== null && value !== undefined
const QUERY_KEYS = new Set(['pageCode', 'memberAnalysis', 'limit', 'keyword', 'dataScope', 'businessStatus', 'topFilters', 'sorts', 'groupBy', 'summaries', 'visibleFields', 'fieldVersions', 'queryCutoffDate', 'start_date', 'end_date', 'as_of_date', 'store_ids'])

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

// Date-range behavior is explicitly opted into by field metadata. Existing
// report single-date controls retain their equality semantics unchanged.
export function reportFilterIsRange(field) {
  return reportFilterType(field) === 'number' || (reportFilterType(field) === 'date' && field.filter?.range === true)
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

// 周期控件仅接管可无损表达的整天边界；已有精确到时分秒的条件继续保留，避免扩大查询。
function supportsFilter(field, filter) {
  if (field.filter?.dateTime === true) {
    const isDay = value => Boolean(parseDateKey(String(value || '')))
    const isBoundary = (value, end) => isDay(value) || new RegExp(`^\\d{4}-\\d{2}-\\d{2}[ T]${end ? '23:59:59' : '00:00:00'}$`).test(String(value || ''))
    if (filter.operator === 'eq') return isDay(filter.value)
    if (filter.operator === 'gte') return isBoundary(filter.value, false)
    if (filter.operator === 'lte') return isBoundary(filter.value, true)
    if (filter.operator === 'between') return isBoundary(filter.value, false) && isBoundary(filter.valueTo, true)
    return false
  }
  return reportFilterIsRange(field)
    ? ['eq', 'gte', 'lte', 'between'].includes(filter.operator)
    : filter.operator === (reportFilterType(field) === 'text' ? 'contains' : 'eq')
}

// 外部查询复用统一 parser 的字段别名、操作符和 between 格式；恢复时不修改原快照。
export function reportFilterDraft(fields, query = {}) {
  const available = reportFilterFields(fields)
  const draft = Object.fromEntries(available.map((field) => [field.key, reportFilterIsRange(field) ? { min: '', max: '' } : '']))
  for (const raw of Array.isArray(query?.filters) ? query.filters : []) {
    const filter = normalizeUnifiedQueryFilter(raw)
    const field = available.find((item) => item.key === filter?.field)
    if (!field || !supportsFilter(field, filter)) continue
    // datetime-local 只在控件使用 T；后端事实与筛选仍用同一秒精度时间表达。
    if (!reportFilterIsRange(field)) draft[field.key] = reportFilterType(field) === 'datetime-local'
      ? String(filter.value || '').replace(' ', 'T') : filter.value
    else {
      const range = draft[field.key]
      if (['gte', 'eq', 'between'].includes(filter.operator)) range.min = field.filter?.dateTime ? String(filter.value).slice(0, 10) : filter.value
      if (['lte', 'eq'].includes(filter.operator)) range.max = field.filter?.dateTime ? String(filter.value).slice(0, 10) : filter.value
      if (filter.operator === 'between') range.max = field.filter?.dateTime ? String(filter.valueTo).slice(0, 10) : filter.valueTo
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
    if (reportFilterIsRange(field)) {
      const range = value || {}
      const bounds = [range.min, range.max].filter(hasValue)
      const date = reportFilterType(field) === 'date'
      if (date ? bounds.some(bound => !parseDateKey(String(bound))) : bounds.some(bound => !Number.isFinite(Number(bound)))) {
        throw new Error(`${field.label || field.key}请输入有效${date ? '日期' : '数值'}`)
      }
      if (hasValue(range.min) && hasValue(range.max) && (date ? String(range.min) > String(range.max) : Number(range.min) > Number(range.max))) {
        throw new Error(`${field.label || field.key}的${date ? '开始日期不能晚于结束日期' : '最小值不能大于最大值'}`)
      }
      // datetime 字段按业务本地日历日提交完整起止时间，结束日的晚间记录也必须命中。
      const bound = (value, end) => field.filter?.dateTime === true ? `${value} ${end ? '23:59:59' : '00:00:00'}` : value
      if (hasValue(range.min)) filters.push({ field: field.key, operator: 'gte', value: bound(range.min, false) })
      if (hasValue(range.max)) filters.push({ field: field.key, operator: 'lte', value: bound(range.max, true) })
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
