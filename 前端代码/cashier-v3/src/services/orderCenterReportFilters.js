// 订单查询仍走原后端：仅将原快捷条件适配到统计筛选外观，不重算或过滤订单。
export const EMPTY_ORDER_SOURCE = '__MOHE_QUERY_EMPTY__'
const clone = (value) => JSON.parse(JSON.stringify(value || {}))
const filters = (value) => Array.isArray(value) ? value : []

/** 后端可筛选白名单动态驱动控件；不能把默认快捷字段误当作全部字段或权限。 */
export function orderReportFields(fields = [], sourceOptions = null) {
  const seen = new Set()
  return fields.filter(field => {
    if (!field?.key || seen.has(field.key) || field.key === 'business_date' || field.hidden
      || (field.status && field.status !== 'active') || field.capabilities?.filter !== true) return false
    seen.add(field.key)
    return true
  }).map(field => {
    // 查询类型以服务端为准；来源/业务类型沿用既有枚举呈现，但不改变后端稳定 key。
    const type = field.queryType || field.type || 'text'
    let inputType = ['integer', 'decimal', 'amount', 'money', 'number'].includes(type) ? 'number'
      : type === 'datetime' ? 'datetime-local' : ['date', 'month', 'enum'].includes(type) ? type : 'text'
    let options = field.filter?.options || field.options || []
    if (field.type === 'enum' && options.length) inputType = 'enum'
    if (type === 'boolean') { inputType = 'enum'; options = [{ value: true, label: '是' }, { value: false, label: '否' }] }
    if (field.key === 'business_type') { inputType = 'enum'; options = ['消费', '充值', '补交', '退款'] }
    if (field.key === 'source') { inputType = 'enum'; options = [{ value: EMPTY_ORDER_SOURCE, label: '空来源' }, ...(sourceOptions || options)] }
    return { ...field, defaultQuick: false, filter: { ...field.filter, enabled: true, type: inputType, options }, options }
  })
}

/** 日期优先读取宿主别名，兼容仅有业务日期 topFilters 的已执行查询。 */
export function orderReportDate(query = {}, fallback = '') {
  const top = filters(query.topFilters)
  const date = (operator) => top.find(item => item.field === 'business_date' && item.operator === operator)?.value
  return {
    min: query.businessDateFrom || query.business_date_from || query.dateFrom || query.date_from || date('gte') || date('eq') || fallback,
    max: query.businessDateTo || query.business_date_to || query.dateTo || query.date_to || date('lte') || date('eq') || fallback
  }
}

/** 隔离快捷 AND 条件，避免统计控件把原先高级条件的 OR 关系悄悄改为 AND。 */
export function orderReportDraftSnapshot(query, fields = [], pageCode = 'order_center_all') {
  if (query == null) return null
  if (query.pageCode && query.pageCode !== pageCode) return { pageCode, filters: [] }
  const keys = new Set(fields.map(field => field.key))
  return { pageCode, limit: query.limit || 20,
    filters: filters(query.topFilters).filter(item => keys.has(item.field)).map(item =>
      item.field === 'source' && item.operator === 'is_null'
        ? { field: 'source', operator: 'eq', value: EMPTY_ORDER_SOURCE } : clone(item)) }
}

/** 新查询始终回第一页；保留原高级关系、组织约束及稳定排序，清除已撤下的关键词。 */
export function buildOrderReportQuery(original, panel, date, scope, limit, cleared = false, fields = [], pageCode = 'order_center_all') {
  const query = clone(original)
  const keys = new Set(fields.map(field => field.key))
  // 旧别名只用于读取恢复，提交统一使用现有订单接口的驼峰日期字段。
  for (const key of ['date_from', 'date_to', 'business_date_from', 'business_date_to']) delete query[key]
  const top = filters(query.topFilters).filter(item => !keys.has(item.field) && item.field !== 'business_date')
  for (const item of filters(panel.filters)) {
    if (!keys.has(item.field)) continue
    top.push(item.field === 'source' && item.value === EMPTY_ORDER_SOURCE
      ? { field: 'source', operator: 'is_null', value: null } : clone(item))
  }
  if (date.min) top.push({ field: 'business_date', operator: 'gte', value: date.min })
  if (date.max) top.push({ field: 'business_date', operator: 'lte', value: date.max })
  // 清空仅重置高级筛选；权限和组织范围仍由宿主与后端掌握。
  return { ...query, pageCode, page: 1, limit,
    keyword: '', dataScope: scope, businessStatus: scope === 'normal' ? '' : (query.businessStatus || ''), topFilters: top,
    dateFrom: date.min, businessDateFrom: date.min, dateTo: date.max, businessDateTo: date.max,
    filters: cleared ? [] : filters(query.filters), filterRelation: cleared ? 'all' : (query.filterRelation || 'all') }
}
