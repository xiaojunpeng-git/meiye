const STORE_V3_SESSION_TOKEN_KEY = 'cashier-v3:session-token'

function resolveSessionStorage(windowRef = typeof window !== 'undefined' ? window : null) {
  try {
    return windowRef?.sessionStorage || null
  } catch (_) {
    return null
  }
}

export function readStoreV3SessionToken(windowRef) {
  try {
    return String(resolveSessionStorage(windowRef)?.getItem(STORE_V3_SESSION_TOKEN_KEY) || '').trim()
  } catch (_) {
    return ''
  }
}

export function writeStoreV3SessionToken(token, windowRef) {
  const value = String(token || '').trim()
  if (!value) throw new Error('登录未返回有效会话。')
  const storage = resolveSessionStorage(windowRef)
  if (!storage) throw new Error('当前浏览器无法保存门店端会话，请关闭隐私限制后重试。')
  storage.setItem(STORE_V3_SESSION_TOKEN_KEY, value)
}

export function clearStoreV3SessionToken(windowRef) {
  try {
    resolveSessionStorage(windowRef)?.removeItem(STORE_V3_SESSION_TOKEN_KEY)
  } catch (_) {
    // A failed local cleanup must not turn logout into a failed operation.
  }
}

export { STORE_V3_SESSION_TOKEN_KEY }
