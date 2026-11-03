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
/** 所有基础报表 XLSX 由同权限后端生成，大范围未消耗仍走已完成任务的流式读取。 */
export async function downloadBasicReportXlsx(platform, query, storeIds = [], signal) {
  const base = platform ? '/adminapi/report/basic/' : '/cashierapi/v3/report/basic/'
  const url = new URL(base + 'export', window.location.origin)
  url.searchParams.set('query', JSON.stringify(query))
  if (platform && storeIds.length) url.searchParams.set('store_ids', storeIds.join(','))
  const cookie = document.cookie.match(/(?:^|;\s*)admin-token=([^;]*)/)
  const token = platform ? (cookie ? decodeURIComponent(cookie[1]) : '') : readStoreV3SessionToken()
  const response = await fetch(url, { credentials: platform ? 'same-origin' : 'omit', cache: 'no-store', signal,
    headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) } })
  if (!response.ok || !response.headers.get('content-type')?.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
    const body = await response.json().catch(() => null)
    throw new Error(body?.msg || '报表 XLSX 导出失败，请重试。')
  }
  return response.blob()
}
export const saveBasicRegistration = (platform, body) => request(platform, 'save', { body })
/** 真删除只发送已查询行的身份、版本及原内容证明；结果仍由共享查询重取。 */
export const deleteBasicRegistration = (platform, body) => request(platform, 'delete', { body })

/** 工资人工补充独立保存，不复用刷单写入口；权限与冻结最终由后端校验。 */
export const saveBasicSalary = (platform, body) => request(platform, 'salary/save', { body })
export const freezeBasicSalary = body => request(true, 'salary/freeze', { body })
