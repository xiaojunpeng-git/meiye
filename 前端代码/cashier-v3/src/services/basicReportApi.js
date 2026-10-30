import { readStoreV3SessionToken } from './storeV3SessionToken'

/** 两端复用报表接口契约；只传查询和表单，不传身份、权限或数据库配置。 */
async function request(platform, action, { query, pageCode, storeIds = [], body, signal } = {}) {
  const base = platform ? '/adminapi/report/basic/' : '/cashierapi/v3/report/basic/'
  const url = new URL(base + action, window.location.origin)
  if (query) url.searchParams.set('query', JSON.stringify(query))
  if (pageCode) url.searchParams.set('pageCode', pageCode)
  if (platform && storeIds.length) url.searchParams.set('store_ids', storeIds.join(','))
  const cookie = document.cookie.match(/(?:^|;\s*)admin-token=([^;]*)/)
  const token = platform ? (cookie ? decodeURIComponent(cookie[1]) : '') : readStoreV3SessionToken()
  const response = await fetch(url, {
    method: body ? 'POST' : 'GET', credentials: platform ? 'same-origin' : 'omit', cache: 'no-store', signal,
    headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) },
    ...(body ? { body: JSON.stringify(body) } : {})
  })
  const value = await response.json().catch(() => null)
  if (!response.ok || !value || Number(value.status || 200) !== 200) throw new Error(value?.msg || '基础报表操作失败，请重试。')
  return value.data ?? value
}

export const loadBasicReportCatalog = (platform, signal) => request(platform, 'catalog', { signal })
/** 平台基础报表的可选门店范围与查询共用菜单授权；门店端始终固定当前登录门店。 */
export const loadBasicReportScope = (pageCode, signal) => request(true, 'scope', { pageCode, signal })
export const loadBasicReport = (platform, query, storeIds, signal) => request(platform, 'query', { query, storeIds, signal })
export const saveBasicRegistration = (platform, body) => request(platform, 'save', { body })

/** 工资人工补充独立保存，不复用刷单写入口；权限与冻结最终由后端校验。 */
export const saveBasicSalary = (platform, body) => request(platform, 'salary/save', { body })
export const freezeBasicSalary = body => request(true, 'salary/freeze', { body })

/** CSV始终取后端统一查询完整导出集；避免券码等人工文本被表格软件当公式执行。 */
export function registrationCsv(fields, rows, summaries, sources, includeSummary = true, rowSources = [], summaryCards = []) {
  const cell = (value) => {
    const text = value == null ? '' : String(value)
    const safe = typeof value === 'string' && /^[\s]*[=+\-@]/.test(text) ? '\t' + text : text
    return '"' + safe.replaceAll('"', '""') + '"'
  }
  const lines = [fields.map(field => field.label), ...rows.map(row => fields.map(field => row[field.key] ?? ''))]
  // 对账矩阵每个支付方式已有横向合计；不再叠加把人工刷单混入实际收款的纵向总额。
  if (includeSummary) lines.push(fields.map((field, i) => i === 0 ? '合计' : (summaries[`${field.key}:sum`] ?? '-')))
  // 表上方汇总独立于表内合计，仍来自同一后端完整筛选集。
  if (summaryCards.length) lines.push([], ['当前查询汇总'], ...summaryCards.map(card => [card.label, card.value ?? '-']))
  lines.push([], ['列名取值来源'])
  fields.forEach(field => lines.push([field.label, sources[field.key] || '该条业务记录的稳定身份。']))
  // 指标纵行的业务含义随同一后端查询导出，避免门店列只有通用说明。
  rowSources.forEach(source => lines.push([source.label, source.description]))
  return '\ufeff' + lines.map(row => row.map(cell).join(',')).join('\r\n')
}
