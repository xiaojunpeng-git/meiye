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
import { readStoreV3SessionToken } from './storeV3SessionToken.js'
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
  const bootstrap = envelope?.data?.bootstrap
  const features = bootstrap?.features
  if (Array.isArray(features)) {
    applyCashierV3LoginFeatures(features, {
      visibleFeatures: features,
      operationFeatures: Array.isArray(bootstrap?.operationFeatures) ? bootstrap.operationFeatures : [],
      readOnly: bootstrap?.readOnly === true,
      sessionMode: bootstrap?.sessionMode
    })
  }
}

export function hasCashierV3Session(browserWindow = typeof window !== 'undefined' ? window : null) {
  return Boolean(readStoreV3SessionToken(browserWindow))
}

export { requestCashierV3ContextSwitch, requestCashierV3Action }
