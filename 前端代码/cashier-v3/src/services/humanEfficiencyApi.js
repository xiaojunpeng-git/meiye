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
    const error = new Error(String(payload?.msg || payload?.message || '人效看板请求失败。'))
    error.code = String(payload?.data?.code || payload?.code || '')
    error.status = response.status
    throw error
  }
  return payload.data || payload
}

// 身份、查询范围和所有指标在统一后端计算；前端不能回退到演示经营数值。
export function queryHumanEfficiency(filters = {}) {
  return request('/adminapi/report/human-efficiency', filters)
}
export function queryHumanEfficiencyScope() {
  return request('/adminapi/report/unified/scope')
}
