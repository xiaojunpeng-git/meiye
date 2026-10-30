function readPlatformAdminToken() {
  try {
    // 平台主账号与区域／代理账号分别使用两个同源 Cookie。嵌入的
    // 订单中心必须沿用当前平台会话，但不得退回门店收银会话。
    for (const name of ['admin-token', 'admin-agent_token']) {
      const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
      const matched = document.cookie.match(new RegExp(`(?:^|;\\s*)${escaped}=([^;]*)`))
      const token = matched ? decodeURIComponent(matched[1]).trim() : ''
      if (token) return token
    }
    return ''
  } catch (_) {
    return ''
  }
}

/**
 * 平台订单中心的组织树只读接口。选择结果随后仍由订单查询接口在后端
 * 与当前管理员的订单数据权限求交集，树只用于缩小查询范围。
 */
export async function queryPlatformOrderCenterScope() {
  const endpoint = new URL('/adminapi/store/order-center/scope', window.location.origin)
  const token = readPlatformAdminToken()
  const response = await fetch(endpoint.href, {
    method: 'GET',
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { Accept: 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) }
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || Number(payload.status || 200) !== 200) {
    throw new Error(String(payload?.msg || payload?.message || '组织权限范围读取失败，请稍后重试。'))
  }
  return payload.data || payload
}
