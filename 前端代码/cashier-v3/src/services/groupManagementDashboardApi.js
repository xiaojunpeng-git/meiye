function readPlatformAdminToken() {
  try {
    const matched = document.cookie.match(/(?:^|;\s*)admin-token=([^;]*)/)
    return matched ? decodeURIComponent(matched[1]).trim() : ''
  } catch (_) {
    return ''
  }
}

function requestToken() {
  const uuid = globalThis.crypto?.randomUUID?.()
  return `group-dashboard-${uuid || `${Date.now()}-${Math.random().toString(36).slice(2, 12)}`}`
}

async function request(path, options = {}) {
  const url = new URL(path, window.location.origin)
  if (url.origin !== window.location.origin) throw new Error('集团管理看板接口必须与平台端同源。')
  Object.entries(options.query || {}).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, String(value))
  })
  const token = readPlatformAdminToken()
  const response = await fetch(url.href, {
    method: options.method || 'GET',
    credentials: 'same-origin',
    cache: 'no-store',
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { 'Authori-zation': `Bearer ${token}` } : {})
    },
    ...(options.body ? { body: JSON.stringify(options.body) } : {})
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || Number(payload.status || 200) !== 200) {
    const error = new Error(String(payload?.msg || payload?.message || '集团管理看板请求失败。'))
    error.code = String(payload?.data?.code || payload?.code || '')
    throw error
  }
  return payload.data || payload
}

export function queryGroupManagementDashboard(filters = {}) {
  return request('/adminapi/report/group-dashboard', { query: filters })
}

export function queryGroupManagementDashboardTargets(filters = {}) {
  return request('/adminapi/report/group-dashboard/targets', { query: filters })
}

export function queryGroupManagementDashboardDrilldown(filters = {}) {
  return request('/adminapi/report/group-dashboard/drilldown', { query: filters })
}

export function saveGroupManagementDashboardTarget(input = {}) {
  return request('/adminapi/report/group-dashboard/targets', {
    method: 'POST',
    body: { ...input, idempotency_key: input.idempotency_key || requestToken() }
  })
}
