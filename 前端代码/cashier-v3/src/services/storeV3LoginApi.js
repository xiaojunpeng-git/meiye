import {
  clearStoreV3SessionToken,
  readStoreV3SessionToken,
  writeStoreV3SessionToken
} from './storeV3SessionToken.js'

function endpoint(path) {
  return new URL(path, window.location.origin).href
}

function currentStoreV3Token() {
  return readStoreV3SessionToken()
}

async function post(path, body = {}, token = '') {
  const authorizationToken = String(token || currentStoreV3Token()).trim()
  const response = await fetch(endpoint(path), {
    method: 'POST',
    credentials: 'omit',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(authorizationToken ? { 'Authori-zation': `Bearer ${authorizationToken}` } : {})
    },
    body: JSON.stringify(body)
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok || Number(data.status || 200) !== 200) {
    throw new Error(String(data.msg || data.message || '登录未完成，请稍后重试。'))
  }
  return data.data || data
}

export function loginStoreV3(payload) {
  return post('/cashierapi/v3/login', payload)
}

export function persistStoreV3Token(token) {
  writeStoreV3SessionToken(token)
}

export function clearStoreV3Token() {
  clearStoreV3SessionToken()
}

export function changeStoreV3Password(account, currentPassword, newPassword) {
  return post('/cashierapi/v3/session/change-password', {
    account: String(account || '').trim(),
    current_password: String(currentPassword || ''),
    new_password: String(newPassword || '')
  })
}

export function logoutStoreV3() {
  return post('/cashierapi/v3/session/logout')
}
