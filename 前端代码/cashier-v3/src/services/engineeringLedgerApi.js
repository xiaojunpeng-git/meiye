import { readStoreV3SessionToken } from './storeV3SessionToken.js'

function readAdminToken() {
  try {
    const match = document.cookie.match(/(?:^|;\s*)admin-token=([^;]*)/)
    return match ? decodeURIComponent(match[1]).trim() : ''
  } catch (_) {
    return ''
  }
}

function endpoint(mode, path = '') {
  const prefix = mode === 'platform' ? '/adminapi/report' : '/cashierapi/v3'
  return new URL(`${prefix}/engineering-ledger${path}`, window.location.origin)
}

function headers(mode, extra = {}) {
  const token = mode === 'platform' ? readAdminToken() : readStoreV3SessionToken()
  return { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}`, 'Authori-zation': `Bearer ${token}` } : {}), ...extra }
}

async function request(mode, path, options = {}) {
  const response = await fetch(endpoint(mode, path), {
    credentials: 'same-origin', cache: 'no-store', ...options,
    headers: headers(mode, options.headers || {})
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || (payload.status !== undefined && Number(payload.status) !== 200)) {
    throw new Error(String(payload?.msg || payload?.message || '工程管理请求失败。'))
  }
  return payload.data || payload
}

export async function queryEngineeringLedger(mode = 'store', filters = {}) {
  const url = endpoint(mode, '/list')
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, Array.isArray(value) ? value.join(',') : String(value))
  })
  return request(mode, `/list${url.search}`, { method: 'GET' })
}

export async function queryEngineeringScope(mode = 'store') {
  if (mode !== 'platform') return { tree: [], allowed_store_ids: [] }
  const response = await fetch(new URL('/adminapi/report/unified/scope', window.location.origin), { credentials: 'same-origin', cache: 'no-store', headers: headers(mode) })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || (payload.status !== undefined && Number(payload.status) !== 200)) throw new Error(String(payload?.msg || payload?.message || '组织权限范围加载失败。'))
  return payload.data || payload
}

export async function saveEngineeringLedger(mode, type, record, options = {}) {
  return request(mode, '/save', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ type, ...record, request_token: options.idempotencyKey || `engineering-${type}-${Date.now()}`, version: options.expectedVersion ?? record?.version ?? 0 })
  })
}

export async function voidEngineeringLedger(mode, type, id, version) {
  return request(mode, `/void/${encodeURIComponent(id)}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type, version })
  })
}

export async function exportEngineeringLedger(mode, filters = {}) {
  const url = endpoint(mode, '/export')
  Object.entries(filters).forEach(([key, value]) => { if (value !== '' && value != null) url.searchParams.set(key, Array.isArray(value) ? value.join(',') : String(value)) })
  const response = await fetch(url.href, { credentials: 'same-origin', cache: 'no-store', headers: headers(mode) })
  if (!response.ok) throw new Error('工程管理导出失败。')
  return response.blob()
}
