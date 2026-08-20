function readPlatformAdminToken() {
  try {
    const matched = document.cookie.match(/(?:^|;\s*)admin-token=([^;]*)/)
    return matched ? decodeURIComponent(matched[1]).trim() : ''
  } catch (_) { return '' }
}

async function request(path, filters = {}) {
  const url = new URL(path, window.location.origin)
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, String(value))
  })
  const token = readPlatformAdminToken()
  const response = await fetch(url.href, {
    credentials: 'same-origin', cache: 'no-store',
    headers: { Accept: 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) }
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || Number(payload.status || 200) !== 200) throw new Error(String(payload?.msg || payload?.message || '商品看板请求失败。'))
  return payload.data || payload
}

export function queryProductDashboard(filters = {}) { return request('/adminapi/report/product-dashboard', filters) }
export function queryProductDashboardScope() { return request('/adminapi/report/unified/scope') }
