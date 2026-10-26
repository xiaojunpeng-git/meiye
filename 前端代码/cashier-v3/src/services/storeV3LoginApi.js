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

async function post(path, body = {}, token = '', skipToken = false) {
  const authorizationToken = skipToken ? '' : String(token || currentStoreV3Token()).trim()
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

/** 平台身份桥只传一次性票据，不携带当前页签可能残留的门店令牌。 */
export function consumePlatformStoreEntry(ticket) {
  return post('/cashierapi/v3/platform-entry/consume', { ticket: String(ticket || '') }, '', true)
}

/** 列表与登录选店共用服务端候选口径；前端只展示，不扩张授权范围。 */
export async function listSwitchableStoreV3Stores() {
  const token = currentStoreV3Token()
  const response = await fetch(endpoint('/cashierapi/v3/session/stores'), {
    credentials: 'omit',
    headers: { Accept: 'application/json', 'Authori-zation': `Bearer ${token}` }
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok || Number(data.status || 200) !== 200) {
    throw new Error(String(data.msg || data.message || '可切换门店读取失败。'))
  }
  return data.data || data
}

/** target_store_id 不作业务数据范围；服务端按当前账号重新验证再签发新会话。 */
export function switchStoreV3(targetStoreId) {
  return post('/cashierapi/v3/session/switch-store', { target_store_id: Number(targetStoreId || 0) })
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

/** 切店完成时可指定旧令牌撤销；普通退出仍使用当前令牌。 */
export function logoutStoreV3(token = '') {
  return post('/cashierapi/v3/session/logout', {}, token)
}
