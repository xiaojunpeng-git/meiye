/**
 * 调店／换账号唯一生产入口（services 层）。
 *
 * Vue 页面不得自行 beginCashierV3ContextSwitch 或用 requestMeta 兜底；
 * 必须调用本模块的 onCashierStoreOrAccountChanged → requestCashierV3ContextSwitch。
 * Codex 负责的 .vue 页面接入时只改调用点到本函数。
 * 调用点清单见 cashierV3ContextSwitchCallSites.js（C1 只提接口，不改 .vue）。
 */
import {
  applyCashierV3LoginFeatures,
  requestCashierV3Action,
  requestCashierV3ContextSwitch
} from './cashierV3Bridge'
export { CASHIER_V3_CONTEXT_SWITCH_CALL_SITES } from './cashierV3ContextSwitchCallSites'

/**
 * @param {object} payload
 * @param {'store'|'account'|'session'} [payload.reason]
 * @param {number} [payload.storeId]
 * @param {number} [payload.operatorId]
 * @param {boolean} [payload.silent]
 */
export async function onCashierStoreOrAccountChanged(payload = {}) {
  const reason = String(payload.reason || 'store')
  return requestCashierV3ContextSwitch({
    ...payload,
    reason,
    action: payload.action || 'open-cashier-workbench'
  })
}

/**
 * 真实生产 bootstrap：main／router／工作台生命周期调用点。
 * 初始根状态与同快照 versions 必须原子装仓。
 */
export async function bootstrapCashierV3Workbench(payload = {}) {
  const adapter = typeof window !== 'undefined' ? window.__CASHIER_V3_ADAPTER__ : null
  if (adapter && typeof adapter.bootstrap === 'function') {
    const result = await requestCashierV3ContextSwitch({
      ...payload,
      reason: payload.reason || 'session',
      action: 'open-cashier-workbench',
      silent: payload.silent === true
    })
    applyBootstrapFeatureSnapshot(result)
    return result
  }
  // 无 adapter.bootstrap 时走投影动作（仍经 Dispatcher 合同）；不得伪造根
  const result = await requestCashierV3Action('open-cashier-workbench', {
    ...payload,
    silent: payload.silent === true
  })
  applyBootstrapFeatureSnapshot(result)
  return result
}

function applyBootstrapFeatureSnapshot(result) {
  const envelope = result?.data?.result && typeof result.data.result === 'object'
    ? result.data
    : result
  const features = envelope?.data?.bootstrap?.features
  if (Array.isArray(features)) applyCashierV3LoginFeatures(features)
}

function browserSessionValue(browserWindow, key) {
  try {
    return String(browserWindow?.localStorage?.getItem?.(key) || '')
  } catch {
    return ''
  }
}

function browserCookieToken(browserWindow) {
  const cookie = String(browserWindow?.document?.cookie || '')
  for (const part of cookie.split(';')) {
    const separator = part.indexOf('=')
    if (separator < 0) continue
    const name = part.slice(0, separator).trim()
    if (!['cashier_token', 'token'].includes(name)) continue
    return part.slice(separator + 1).trim()
  }
  return ''
}

function browserSessionMarker(browserWindow) {
  return JSON.stringify([
    browserCookieToken(browserWindow) || browserSessionValue(browserWindow, 'token'),
    browserSessionValue(browserWindow, 'store_id')
  ])
}

/**
 * Detects an account/store change made by another tab. Values are used only as
 * change markers; the backend session remains the authority for the new scope.
 */
export function observeCashierV3BrowserSession(options = {}) {
  const browserWindow = options.window || (typeof window !== 'undefined' ? window : null)
  if (!browserWindow?.addEventListener || !browserWindow?.removeEventListener) return () => {}

  const onContextChanged = typeof options.onContextChanged === 'function'
    ? options.onContextChanged
    : onCashierStoreOrAccountChanged
  let currentMarker = browserSessionMarker(browserWindow)
  let timer = null
  let disposed = false
  let pendingReason = 'session'

  const scheduleCheck = (reason) => {
    pendingReason = reason || pendingReason
    if (timer !== null) browserWindow.clearTimeout(timer)
    timer = browserWindow.setTimeout(async () => {
      timer = null
      if (disposed) return
      const nextMarker = browserSessionMarker(browserWindow)
      if (nextMarker === currentMarker) return
      try {
        const result = await onContextChanged({ reason: pendingReason, silent: true })
        const response = result?.data?.result && typeof result.data.result === 'object' ? result.data : result
        const status = String(response?.result?.status || '').toLowerCase()
        if (['success', 'succeeded'].includes(status)) currentMarker = nextMarker
      } catch {
        // Keep the previous marker so the next visible/storage check retries safely.
      }
    }, 30)
  }
  const handleStorage = (event) => {
    if (event?.key !== null && !['token', 'store_id'].includes(String(event?.key || ''))) return
    scheduleCheck(event?.key === 'store_id' ? 'store' : 'account')
  }
  const handleVisibility = () => {
    if (browserWindow.document?.visibilityState === 'hidden') return
    scheduleCheck('session')
  }

  browserWindow.addEventListener('storage', handleStorage)
  browserWindow.document?.addEventListener?.('visibilitychange', handleVisibility)
  return () => {
    disposed = true
    if (timer !== null) browserWindow.clearTimeout(timer)
    browserWindow.removeEventListener('storage', handleStorage)
    browserWindow.document?.removeEventListener?.('visibilitychange', handleVisibility)
  }
}

export { requestCashierV3ContextSwitch, requestCashierV3Action }
