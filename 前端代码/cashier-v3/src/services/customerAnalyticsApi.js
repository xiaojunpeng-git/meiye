function readPlatformAdminToken() {
  try {
    const matched = document.cookie.match(/(?:^|;\s*)admin-token=([^;]*)/)
    return matched ? decodeURIComponent(matched[1]).trim() : ''
  } catch (_) { return '' }
}

// All-store unconsumed snapshots reconcile a large legacy entitlement set;
// allow the authorized backend query to finish instead of failing at 25s.
const CUSTOMER_ANALYTICS_TIMEOUT_MS = 120000

async function request(path, query = {}, options = {}) {
  const url = new URL(path, window.location.origin)
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value))
  })
  const token = readPlatformAdminToken()
  const timeoutMs = Number.isFinite(Number(options.timeoutMs)) && Number(options.timeoutMs) > 0
    ? Number(options.timeoutMs) : 0
  const controller = timeoutMs > 0 && typeof AbortController === 'function' ? new AbortController() : null
  const timeout = controller ? window.setTimeout(() => controller.abort(), timeoutMs) : null
  try {
    const response = await fetch(url.href, {
      method: 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) },
      ...(controller ? { signal: controller.signal } : {})
    })
    const payload = await response.json().catch(() => null)
    if (!response.ok || !payload || Number(payload.status || 200) !== 200) {
      throw new Error(String(payload?.msg || payload?.message || '客户分析请求失败，请稍后重试。'))
    }
    return payload.data || payload
  } catch (error) {
    if (error?.name === 'AbortError') throw new Error('客户分析请求超时，数据量较大，请缩小门店或日期范围后重试。')
    throw error instanceof Error ? error : new Error('客户分析请求未完成，请稍后重试。')
  } finally {
    if (timeout) window.clearTimeout(timeout)
  }
}

export function queryCustomerAnalytics(filters = {}) {
  return request('/adminapi/report/customer-analytics', filters, { timeoutMs: CUSTOMER_ANALYTICS_TIMEOUT_MS })
}

export async function exportCustomerAnalytics(filters = {}) {
  const url = new URL('/adminapi/report/customer-analytics/export', window.location.origin)
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value))
  })
  const token = readPlatformAdminToken()
  const response = await fetch(url.href, {
    method: 'GET', credentials: 'same-origin', cache: 'no-store',
    headers: { Accept: 'text/csv,application/octet-stream', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) }
  })
  if (!response.ok) throw new Error('客户分析导出失败，请稍后重试。')
  const blob = await response.blob()
  const disposition = response.headers.get('content-disposition') || ''
  const filename = decodeURIComponent(disposition.match(/filename\*?=(?:UTF-8''|\"?)([^;\"]+)/i)?.[1] || '客户分析.csv')
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = filename
  link.click()
  setTimeout(() => URL.revokeObjectURL(link.href), 0)
  return { filename }
}

export function queryCustomerAnalyticsScope() {
  return request('/adminapi/report/unified/scope')
}
