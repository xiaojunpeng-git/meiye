// 本地查询偏好只保存展示/筛选规则，不保存登录凭据，也不替代后端权限。
export function queryPreferenceKey(account, store, page) {
  return account && store && page ? `mohe:query-preferences:${JSON.stringify([String(account), String(store), String(page)])}` : ''
}

export function readQueryPreferences(key, storage = globalThis.localStorage) {
  if (!key) return null
  try {
    const value = JSON.parse(storage.getItem(key))
    return value && typeof value === 'object' && !Array.isArray(value) ? value : null
  } catch { return null }
}

// 写入失败必须由调用方显示错误，不能把未保存伪装成成功。
export function writeQueryPreferences(key, value, storage = globalThis.localStorage) {
  if (!key) throw new Error('账号和门店信息未就绪，无法保存本地查询设置。')
  storage.setItem(key, JSON.stringify(value))
}

// 显式查询覆盖旧条件；操作后的无条件刷新复用最后成功的查询快照。
export function mergeQueryRefresh(previous, incoming = {}) {
  return JSON.parse(JSON.stringify({ ...(previous || {}), ...incoming }))
}
