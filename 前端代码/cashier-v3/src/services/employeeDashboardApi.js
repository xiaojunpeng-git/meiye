function readPlatformAdminToken() {
  try {
    const matched = document.cookie.match(/(?:^|;\s*)admin-token=([^;]*)/)
    return matched ? decodeURIComponent(matched[1]).trim() : ''
  } catch (_) {
    return ''
  }
}

async function request(path, query = {}) {
  const url = new URL(path, window.location.origin)
  Object.entries(query).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, String(value))
  })
  const token = readPlatformAdminToken()
  const response = await fetch(url.href, {
    credentials: 'same-origin',
    cache: 'no-store',
    headers: {
      Accept: 'application/json',
      ...(token ? { 'Authori-zation': `Bearer ${token}` } : {})
    }
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || Number(payload.status || 200) !== 200) {
    const error = new Error(String(payload?.msg || payload?.message || '员工看板请求失败。'))
    error.code = String(payload?.data?.code || payload?.code || '')
    error.status = response.status
    throw error
  }
  return payload.data || payload
}

export function queryEmployeeDashboard(filters = {}) {
  return request('/adminapi/report/employee-dashboard', filters)
}

export function queryEmployeeDashboardScope() {
  return request('/adminapi/report/unified/scope')
}
