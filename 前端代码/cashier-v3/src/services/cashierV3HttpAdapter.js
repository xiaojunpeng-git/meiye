import { readStoreV3SessionToken } from './storeV3SessionToken.js'

const DEFAULT_ACTION_ENDPOINT = '/cashierapi/v3/workbenches/actions'
const CASHIER_SOURCE = 'f76d38d0ee4f854f'
const DEFAULT_TIMEOUT_MS = 30000

function resolveSameOriginEndpoint(endpoint, origin) {
  const configured = String(endpoint || DEFAULT_ACTION_ENDPOINT).trim()
  if (!origin) return configured || DEFAULT_ACTION_ENDPOINT
  const resolved = new URL(configured || DEFAULT_ACTION_ENDPOINT, origin)
  if (resolved.origin !== origin) {
    throw new Error('收银接口必须与当前工作台同源，已拒绝发送登录信息。')
  }
  return resolved.href
}

function safeResponseMessage(body, fallback) {
  const message = body && typeof body === 'object'
    ? String(body.msg || body.message || '').trim()
    : ''
  return message || fallback
}

function hasStructuredV3Failure(body) {
  const envelope = body?.result && typeof body.result === 'object'
    ? body
    : body?.data && typeof body.data === 'object'
      ? body.data
      : null
  const status = String(envelope?.result?.status || envelope?.status || '').trim()
  return Boolean(
    envelope
      && ['failed', 'conflict'].includes(status)
      && String(envelope?.result?.code || envelope?.code || '').trim()
      && String(envelope?.result?.message || envelope?.message || '').trim()
  )
}

async function parseJsonResponse(response) {
  const text = await response.text()
  if (!text) throw new Error('收银服务返回了空响应，请稍后重试。')
  try {
    return JSON.parse(text)
  } catch (_) {
    throw new Error('收银服务返回的数据格式无效，请稍后重试。')
  }
}

export function createCashierV3HttpAdapter(options = {}) {
  const browserWindow = options.windowRef || (typeof window !== 'undefined' ? window : null)
  const fetchImpl = options.fetchImpl || browserWindow?.fetch?.bind(browserWindow) || globalThis.fetch?.bind(globalThis)
  if (typeof fetchImpl !== 'function') throw new Error('当前浏览器不支持收银网络请求。')

  const origin = options.origin || browserWindow?.location?.origin || ''
  const endpoint = resolveSameOriginEndpoint(
    options.endpoint || import.meta.env?.VITE_CASHIER_V3_ACTION_ENDPOINT,
    origin
  )
  const timeoutMs = Number.isSafeInteger(Number(options.timeoutMs)) && Number(options.timeoutMs) >= 0
    ? Number(options.timeoutMs)
    : DEFAULT_TIMEOUT_MS

  async function post(payload) {
    const token = options.storage
      ? String(options.storage.getItem?.('cashier-v3:session-token') || '').trim()
      : readStoreV3SessionToken(browserWindow)
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Source': CASHIER_SOURCE,
      ...(token ? { 'Authori-zation': `Bearer ${token}` } : {})
    }
    const controller = timeoutMs > 0 && typeof AbortController === 'function'
      ? new AbortController()
      : null
    const timeout = controller
      ? setTimeout(() => controller.abort(), timeoutMs)
      : null

    try {
      const response = await fetchImpl(endpoint, {
        method: 'POST',
        credentials: 'omit',
        cache: 'no-store',
        headers,
        body: JSON.stringify(payload),
        ...(controller ? { signal: controller.signal } : {})
      })
      const body = await parseJsonResponse(response)
      if (!response.ok) {
        // Some reverse proxies preserve the V3 failure envelope while using a
        // non-2xx HTTP status. Keep that deterministic result intact; the
        // bridge can bind it to the command and show the real failure instead
        // of converting it into an unknown payment outcome.
        if (hasStructuredV3Failure(body)) return body
        throw new Error(safeResponseMessage(body, `收银服务请求失败（HTTP ${response.status}）。`))
      }
      const applicationStatus = typeof body?.status === 'number'
        || /^\d+$/.test(String(body?.status || ''))
        ? Number(body.status)
        : null
      if (applicationStatus !== null && applicationStatus !== 200) {
        if (hasStructuredV3Failure(body)) return body
        throw new Error(safeResponseMessage(body, '收银服务拒绝了本次请求。'))
      }
      return body
    } catch (error) {
      if (error?.name === 'AbortError') {
        throw new Error('收银服务响应超时；如已提交收款，请查询原请求结果，禁止重复收款。')
      }
      throw error instanceof Error ? error : new Error('收银服务请求未完成，请稍后重试。')
    } finally {
      if (timeout) clearTimeout(timeout)
    }
  }

  return Object.freeze({
    async request(action, payload = {}) {
      const canonicalAction = String(action || '').trim()
      const payloadAction = String(payload?.action || '').trim()
      if (!canonicalAction || (payloadAction && payloadAction !== canonicalAction)) {
        throw new Error('收银操作与请求内容不一致，已拒绝发送。')
      }
      return post({ ...payload, action: canonicalAction })
    },
    async bootstrap(payload = {}) {
      const action = String(payload?.action || 'open-cashier-workbench').trim()
      if (action !== 'open-cashier-workbench') {
        throw new Error('工作台初始化操作无效，已拒绝发送。')
      }
      return post({ ...payload, action: 'open-cashier-workbench' })
    }
  })
}

export function installCashierV3HttpAdapter(options = {}) {
  if (typeof window === 'undefined') return null
  if (window.__CASHIER_V3_ADAPTER__ && options.replace !== true) {
    return window.__CASHIER_V3_ADAPTER__
  }
  const adapter = createCashierV3HttpAdapter(options)
  window.__CASHIER_V3_ADAPTER__ = adapter
  return adapter
}
