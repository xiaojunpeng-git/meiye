import { readStoreV3SessionToken } from './storeV3SessionToken.js'

function requestToken() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID()
  const bytes = window.crypto?.getRandomValues?.(new Uint8Array(16))
  if (!bytes) throw new Error('当前浏览器无法生成安全请求标识。')
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const value = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${value.slice(0, 8)}-${value.slice(8, 12)}-${value.slice(12, 16)}-${value.slice(16, 20)}-${value.slice(20)}`
}

async function request(path, options = {}) {
  const url = new URL(path, window.location.origin)
  if (url.origin !== window.location.origin) throw new Error('房间设置接口必须与当前门店端同源。')
  if (options.query) {
    Object.entries(options.query).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value))
    })
  }
  const token = readStoreV3SessionToken()
  const response = await fetch(url.href, {
    method: options.method || 'GET',
    credentials: 'omit',
    cache: 'no-store',
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}),
      ...(options.requestToken ? { 'X-Request-Token': options.requestToken } : {})
    },
    ...(options.body ? { body: JSON.stringify(options.body) } : {})
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || Number(payload.status || 200) !== 200) {
    throw new Error(String(payload?.msg || payload?.message || '房间设置请求失败。'))
  }
  return payload.data || {}
}

export function queryRoomSettings(query = {}) {
  return request('/storeapi/room-settings', { query })
}

export function createRoomSetting(name) {
  const token = requestToken()
  return request('/storeapi/room-settings', { method: 'POST', requestToken: token, body: { name, request_token: token } })
}

export function updateRoomSetting(id, name) {
  const token = requestToken()
  return request(`/storeapi/room-settings/${encodeURIComponent(id)}`, { method: 'PUT', requestToken: token, body: { name, request_token: token } })
}

export function setRoomSettingEnabled(id, enabled) {
  const token = requestToken()
  return request(`/storeapi/room-settings/${encodeURIComponent(id)}/status`, { method: 'POST', requestToken: token, body: { enabled: enabled ? 1 : 0, request_token: token } })
}

export function sortRoomSettings(roomIds) {
  const token = requestToken()
  return request('/storeapi/room-settings/sort', { method: 'POST', requestToken: token, body: { room_ids: roomIds, request_token: token } })
}
