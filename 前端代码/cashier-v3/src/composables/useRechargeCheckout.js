import { ref, unref } from 'vue'
import { createCashierV3CommandId, requestCashierV3Action, mergeCashierV3PublicVersions } from '@/services/cashierV3Bridge'
import { cashierV3ResponseEnvelope } from '@/services/cashierV3CheckoutResultContract'

function resultStatus(result) {
  const nested = result?.data && typeof result.data === 'object' ? result.data : null
  const response = result?.result && typeof result.result === 'object'
    ? result
    : nested?.result && typeof nested.result === 'object'
      ? nested
      : nested && typeof nested.status === 'string'
        ? nested
        : result
  return response?.result?.status || response?.status || ''
}

function clonePlain(value = {}) {
  return JSON.parse(JSON.stringify(value && typeof value === 'object' ? value : {}))
}

/**
 * Owns the recharge editing Checkout protocol. The workbench supplies only
 * the current member/context and the shared business-source selector callback;
 * all recharge mutations remain serialized and versioned in this boundary.
 */
export function useRechargeCheckout({ member, currentMemberId, stateContextId, openSourceSelector }) {
  const rechargeCheckout = ref(null)
  let rechargeCheckoutMutationTail = Promise.resolve()

  function rechargeCheckoutProjection(response) {
    const direct = response?.data?.rechargeCheckout
    const nested = response?.result?.data?.rechargeCheckout
    const legacy = response?.data?.data?.rechargeCheckout
    const projection = direct || nested || legacy
    return projection && typeof projection === 'object' ? clonePlain(projection) : null
  }

  function setRechargeCheckout(response) {
    const projection = rechargeCheckoutProjection(response)
    if (projection) rechargeCheckout.value = projection
    return projection
  }

  function acceptPreparedCheckout(response) {
    if (!['success', 'succeeded'].includes(resultStatus(response))) return null
    const projection = setRechargeCheckout(response)
    if (!projection) return null
    mergeCashierV3PublicVersions([{
      kind: 'recharge_checkout_request',
      id: projection.rechargeCheckoutRequestId,
      version: Number(projection.checkoutRequestVersion)
    }], unref(stateContextId), { requestStateContextId: unref(stateContextId) })
    return projection
  }

  function closeRechargeCheckout() {
    rechargeCheckout.value = null
  }

  async function requestRechargeCheckoutAction({ action, payload = {} }) {
    const checkout = rechargeCheckout.value
    const memberId = unref(member)?.id || unref(currentMemberId)
    if (!checkout?.rechargeCheckoutRequestId || !memberId) {
      return { result: { status: 'failed', code: 'RECHARGE_CHECKOUT_SESSION_EXPIRED', message: '充值结账现场已失效，请重新进入。' } }
    }
    if (action === 'open-checkout-source-selector') return openSourceSelector?.('recharge')
    const actionMap = {
      'add-payment-method': 'add-recharge-checkout-payment-method',
      'update-payment-line': 'update-recharge-checkout-payment-line',
      'remove-payment-line': 'remove-recharge-checkout-payment-line',
      'submit-checkout': 'submit-recharge-checkout',
      'update-checkout-business-source': 'update-recharge-checkout-business-source'
    }
    const targetAction = actionMap[action]
    if (!targetAction) return { result: { status: 'failed', code: 'RECHARGE_CHECKOUT_ACTION_NOT_ALLOWED', message: '该充值结账操作尚未开放。' } }
    const response = await requestCashierV3Action(targetAction, {
      ...payload,
      memberId,
      rechargeCheckoutRequestId: checkout.rechargeCheckoutRequestId,
      rechargeCheckoutRequestVersion: checkout.checkoutRequestVersion
    })
    setRechargeCheckout(response)
    return response
  }

  function rechargeCheckoutDraftConflict(result) {
    if (resultStatus(result) !== 'conflict') return false
    const envelope = cashierV3ResponseEnvelope(result)
    return String(envelope?.result?.code || envelope?.code || '') === 'RESOURCE_VERSION_CONFLICT'
  }

  async function requestRechargeCheckoutMutationWithSingleConflictReplay(event = {}) {
    const action = String(event?.action || '')
    const first = await requestRechargeCheckoutAction(event)
    if (!['add-payment-method', 'update-payment-line', 'remove-payment-line', 'update-checkout-business-source'].includes(action)
      || !rechargeCheckoutDraftConflict(first)) return first
    const current = rechargeCheckout.value
    const requestId = String(current?.rechargeCheckoutRequestId || '')
    const memberId = Number(unref(member)?.id || unref(currentMemberId) || 0)
    if (!requestId || memberId <= 0) return first
    const refreshed = await requestCashierV3Action('reload-recharge-checkout', {
      requestId,
      memberId,
      idempotencyKey: createCashierV3CommandId()
    })
    const projection = rechargeCheckoutProjection(refreshed)
    if (!['success', 'succeeded'].includes(resultStatus(refreshed)) || !projection) return first
    rechargeCheckout.value = projection
    return requestRechargeCheckoutAction(event)
  }

  function enqueueRechargeCheckoutAction(event) {
    rechargeCheckoutMutationTail = rechargeCheckoutMutationTail
      .catch(() => undefined)
      .then(() => requestRechargeCheckoutMutationWithSingleConflictReplay(event))
    return rechargeCheckoutMutationTail
  }

  return {
    rechargeCheckout,
    rechargeCheckoutProjection,
    acceptPreparedCheckout,
    closeRechargeCheckout,
    requestRechargeCheckoutAction,
    rechargeCheckoutDraftConflict,
    requestRechargeCheckoutMutationWithSingleConflictReplay,
    enqueueRechargeCheckoutAction
  }
}
