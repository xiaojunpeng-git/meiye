import { readStoreV3SessionToken } from './storeV3SessionToken.js'

export async function loadCheckoutBusinessCatalog() {
  const url = new URL('/cashierapi/v3/business-config/checkout-catalog', window.location.origin)
  if (url.origin !== window.location.origin) throw new Error('业务来源接口必须与当前收银台同源。')
  const token = readStoreV3SessionToken()
  const response = await fetch(url.href, {
    method: 'GET',
    credentials: 'omit',
    cache: 'no-store',
    headers: {
      Accept: 'application/json',
      ...(token ? { 'Authori-zation': `Bearer ${token}` } : {})
    }
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || Number(payload.status || 200) !== 200) {
    throw new Error(String(payload?.msg || payload?.message || '业务来源加载失败，请稍后重试。'))
  }
  const data = payload.data || payload
  return {
    sources: Array.isArray(data.sources) ? data.sources : [],
    accountingMethods: Array.isArray(data.accountingMethods) ? data.accountingMethods : [],
    configVersion: Number(data.configVersion || 0)
  }
}
