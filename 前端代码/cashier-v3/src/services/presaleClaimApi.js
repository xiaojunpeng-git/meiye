import { readStoreV3SessionToken } from './storeV3SessionToken.js'

function requestId(prefix) {
  const uuid = globalThis.crypto?.randomUUID?.()
  return `${prefix}-${uuid || `${Date.now()}-${Math.random().toString(36).slice(2, 12)}`}`
}

async function request(path, options = {}) {
  const url = new URL(path, window.location.origin)
  if (url.origin !== window.location.origin) throw new Error('预售领用接口必须与当前门店端同源。')
  Object.entries(options.query || {}).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, String(value))
  })
  const token = readStoreV3SessionToken()
  const response = await fetch(url.href, {
    method: options.method || 'GET', credentials: 'omit', cache: 'no-store',
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { 'Authori-zation': `Bearer ${token}` } : {})
    },
    ...(options.body ? { body: JSON.stringify(options.body) } : {})
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || Number(payload.status || 200) !== 200) {
    const error = new Error(String(payload?.msg || payload?.message || '预售领用请求未完成。'))
    error.code = String(payload?.data?.code || payload?.code || '')
    throw error
  }
  return payload.data || payload
}

export function listStorePresaleClaims(query = {}) {
  return request('/storeapi/product/inventory/v3/presale-claims', { query })
}

export function readStorePresaleClaim(claimableLineId) {
  return request(`/storeapi/product/inventory/v3/presale-claims/${encodeURIComponent(String(claimableLineId || ''))}`)
}

export function createStorePresaleClaim(input = {}) {
  return request('/storeapi/product/inventory/v3/presale-claims/claim', {
    method: 'POST',
    body: { ...input, idempotency_key: input.idempotency_key || requestId('presale-claim') }
  })
}

export function voidStorePresaleClaim(claimId, input = {}) {
  return request(`/storeapi/product/inventory/v3/presale-claims/${encodeURIComponent(String(claimId || ''))}/void`, {
    method: 'POST',
    body: { ...input, idempotency_key: input.idempotency_key || requestId('presale-claim-void') }
  })
}
