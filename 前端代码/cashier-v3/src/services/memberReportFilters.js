import { cloneUnifiedQuerySnapshot } from '@mohe/unified-query-vue3'

const list = value => Array.isArray(value) ? value : []

/** Only backend-authorized, active fields become controls. Visible columns and
 * old quick-field preferences cannot grant filtering or change field identity.
 */
export function memberReportFields(fields = []) {
  const seen = new Set()
  return fields.filter(field => {
    if (!field?.key || field.key === 'id' || field.hidden || seen.has(field.key)
      || (field.status && field.status !== 'active') || field.capabilities?.filter !== true) return false
    seen.add(field.key)
    return true
  }).map(field => {
    const type = field.queryType || field.type || 'text'
    const inputType = ['integer', 'decimal', 'amount', 'money', 'number', 'count'].includes(type) ? 'number'
      : ['datetime', 'date_time'].includes(type) ? 'datetime-local' : ['enum', 'date', 'month'].includes(type) ? type : 'text'
    const options = type === 'boolean' ? [{ value: true, label: '是' }, { value: false, label: '否' }]
      : field.filter?.options || field.options || []
    return { ...field, defaultQuick: false, filter: { ...field.filter, enabled: true,
      type: type === 'boolean' ? 'enum' : inputType, range: inputType === 'date', options } }
  })
}

/** Panel values are AND quick conditions. Preserve saved advanced filters and
 * their OR relation separately; opening a panel never changes query semantics.
 */
export function memberReportDraftSnapshot(query, pageCode = 'member_list') {
  if (query == null) return null
  if (query.pageCode && query.pageCode !== pageCode) return { pageCode, filters: [] }
  return { pageCode, limit: query.limit || 20, filters: list(query.topFilters).map(filter => cloneUnifiedQuerySnapshot(filter)) }
}

/** Same query contract for list, pagination and export; only the query button
 * promotes a draft. Clear removes extra conditions, never keyword/scope/rights.
 */
export function buildMemberReportQuery(original, panel, keyword, dataScope, limit, cleared = false, businessStatus = original?.businessStatus || '') {
  const query = cloneUnifiedQuerySnapshot(original)
  return { ...query, pageCode: 'member_list', page: 1, limit,
    keyword: String(keyword || '').trim(), dataScope: dataScope === 'all' ? 'all' : 'normal',
    businessStatus: dataScope === 'all' ? businessStatus : '',
    topFilters: list(panel.filters), filters: cleared ? [] : list(query.filters),
    filterRelation: cleared ? 'all' : (query.filterRelation || 'all') }
}
