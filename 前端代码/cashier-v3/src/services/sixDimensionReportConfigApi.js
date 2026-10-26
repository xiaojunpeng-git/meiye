function readPlatformAdminToken() {
  try {
    const matched = document.cookie.match(/(?:^|;\s*)admin-token=([^;]*)/)
    return matched ? decodeURIComponent(matched[1]).trim() : ''
  } catch (_) {
    return ''
  }
}

function requestToken() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID()
  const bytes = window.crypto?.getRandomValues?.(new Uint8Array(16))
  if (!bytes) throw new Error('当前浏览器无法生成安全请求标识。')
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

async function request(path, options = {}) {
  const url = new URL(path, window.location.origin)
  if (url.origin !== window.location.origin) throw new Error('消费分级配置接口必须与平台端同源。')
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
    throw new Error(String(payload?.msg || payload?.message || '消费分级配置请求失败。'))
  }
  return payload.data || payload
}

export function queryConsumptionTiers() {
  return request('/adminapi/report/six-dimension/consumption-tiers')
}

export function saveConsumptionTier(tier) {
  const idempotencyKey = requestToken()
  return request('/adminapi/report/six-dimension/consumption-tiers', {
    method: 'POST',
    body: { ...tier, idempotency_key: idempotencyKey }
  })
}

export async function sortConsumptionTiers(tiers) {
  return request('/adminapi/report/six-dimension/consumption-tiers/sort', {
    method: 'POST',
    body: {
      tiers: tiers.map((tier) => ({ tier_code: tier.tier_code, expected_version: tier.version })),
      idempotency_key: requestToken()
    }
  })
}
