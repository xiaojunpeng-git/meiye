import { readStoreV3SessionToken } from './storeV3SessionToken.js'

export const STORE_BUSINESS_REPORT_RUNTIME = Object.freeze({
  STORE: 'store',
  PLATFORM: 'platform'
})

function isPlatformRuntime(runtime) {
  return runtime === STORE_BUSINESS_REPORT_RUNTIME.PLATFORM
}

function readPlatformAdminToken() {
  try {
    const matched = document.cookie.match(/(?:^|;\s*)token=([^;]*)/)
    return matched ? decodeURIComponent(matched[1]).trim() : ''
  } catch (_) {
    return ''
  }
}

function endpoint(runtime, suffix) {
  return isPlatformRuntime(runtime)
    ? `/adminapi/report/${suffix}`
    : `/cashierapi/v3/report/${suffix}`
}

async function request(runtime, path, query = {}) {
  const url = new URL(path, window.location.origin)
  if (url.origin !== window.location.origin) throw new Error('经营报表接口必须与当前页面同源。')
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value))
  })
  const platform = isPlatformRuntime(runtime)
  const token = platform ? readPlatformAdminToken() : readStoreV3SessionToken()
  const response = await fetch(url.href, {
    method: 'GET', credentials: platform ? 'same-origin' : 'omit', cache: 'no-store',
    headers: { Accept: 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) }
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || Number(payload.status || 200) !== 200) {
    throw new Error(String(payload?.msg || payload?.message || '经营报表读取失败，请稍后重试。'))
  }
  return payload.data || payload
}

export const queryStoreBusinessReportCatalog = (runtime = STORE_BUSINESS_REPORT_RUNTIME.STORE) => request(runtime, endpoint(runtime, 'unified/catalog'))
export const queryStoreBusinessReportScope = (runtime = STORE_BUSINESS_REPORT_RUNTIME.STORE) => request(runtime, endpoint(runtime, 'unified/scope'))
export const queryStoreBusinessReport = (query = {}, runtime = STORE_BUSINESS_REPORT_RUNTIME.STORE) => request(runtime, endpoint(runtime, 'unified/query'), query)
export const exportStoreBusinessReport = (query = {}, runtime = STORE_BUSINESS_REPORT_RUNTIME.STORE) => request(runtime, endpoint(runtime, 'unified/export'), query)
export const queryStoreBusinessReportPersonnel = (query = {}, runtime = STORE_BUSINESS_REPORT_RUNTIME.STORE) => {
  if (isPlatformRuntime(runtime)) return request(runtime, endpoint(runtime, 'unified/personnel'), query)
  throw new Error('当前门店端人员选择器应由收银会话服务处理。')
}
export const listStoreBusinessReportAnnotations = (query = {}, runtime = STORE_BUSINESS_REPORT_RUNTIME.STORE) => request(runtime, endpoint(runtime, 'operations/annotations'), query)

async function write(runtime, path, body = {}) {
  const platform = isPlatformRuntime(runtime)
  const token = platform ? readPlatformAdminToken() : readStoreV3SessionToken()
  const response = await fetch(path, {
    method: 'POST', credentials: platform ? 'same-origin' : 'omit', cache: 'no-store',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) },
    body: JSON.stringify(body)
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || Number(payload.status || 200) !== 200) throw new Error(String(payload?.msg || '保存报表补充内容失败。'))
  return payload.data || payload
}
export const saveStoreBusinessReportAnnotation = (body = {}, runtime = STORE_BUSINESS_REPORT_RUNTIME.STORE) => write(runtime, endpoint(runtime, 'operations/annotation'), body)
