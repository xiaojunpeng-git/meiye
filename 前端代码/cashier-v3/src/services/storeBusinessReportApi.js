import { readStoreV3SessionToken } from './storeV3SessionToken.js'

async function request(path, query = {}) {
  const url = new URL(path, window.location.origin)
  if (url.origin !== window.location.origin) throw new Error('经营报表接口必须与当前收银台同源。')
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value))
  })
  const token = readStoreV3SessionToken()
  const response = await fetch(url.href, {
    method: 'GET', credentials: 'omit', cache: 'no-store',
    headers: { Accept: 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) }
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || Number(payload.status || 200) !== 200) {
    throw new Error(String(payload?.msg || payload?.message || '经营报表读取失败，请稍后重试。'))
  }
  return payload.data || payload
}

export const queryStoreBusinessReportCatalog = () => request('/cashierapi/v3/report/unified/catalog')
export const queryStoreBusinessReport = (query = {}) => request('/cashierapi/v3/report/unified/query', query)
export const exportStoreBusinessReport = (query = {}) => request('/cashierapi/v3/report/unified/export', query)
