function readPlatformAdminToken() {
  try {
    const matched = document.cookie.match(/(?:^|;\s*)admin-token=([^;]*)/)
    return matched ? decodeURIComponent(matched[1]).trim() : ''
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
