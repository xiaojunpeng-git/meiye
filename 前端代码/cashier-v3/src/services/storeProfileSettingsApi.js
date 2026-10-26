import { readStoreV3SessionToken } from './storeV3SessionToken.js'

/** Same-origin, current-session store only; no caller-selected store or platform token. */
async function request(suffix = '', options = {}) {
  const token = readStoreV3SessionToken()
  const response = await fetch(`/cashierapi/v3/management/store-settings${suffix}`, {
    method: options.method || 'GET', credentials: 'omit', cache: 'no-store',
    headers: { Accept: 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}), ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}) },
    ...(options.body ? { body: options.body instanceof FormData ? options.body : JSON.stringify(options.body) } : {})
  })
  const data = await response.json().catch(() => null)
  if (!response.ok || !data || Number(data.status) !== 200) throw new Error(data?.msg || '门店设置请求失败，请稍后重试。')
  return data.data
}

export const queryStoreProfile = () => request()
export const queryStoreProfileCities = (pid = 0) => request(`/cities?pid=${encodeURIComponent(pid)}`)
export const saveStoreProfile = (profile, expectedVersion, requestToken) => request('', { method: 'PUT', body: { profile, expected_version: expectedVersion, request_token: requestToken } })
export function uploadStoreProfileImage(file) {
  const body = new FormData(); body.append('file', file)
  return request('/image', { method: 'POST', body })
}
