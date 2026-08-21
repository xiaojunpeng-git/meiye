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

  function setRechargeCheckout(response, { allowTerminalOutcome = true } = {}) {
    const projection = rechargeCheckoutProjection(response)
    if (projection) {
      rechargeCheckout.value = projection
      // Recharge draft mutations advance recharge_checkout_request independently
      // of the root workbench. Keep the bridge's public version in sync so the
      // next serialized edit is sent with the version returned by the server.
      mergeCashierV3PublicVersions([{
        kind: 'recharge_checkout_request',
        id: projection.rechargeCheckoutRequestId,
        version: Number(projection.checkoutRequestVersion)
      }], unref(stateContextId), { requestStateContextId: unref(stateContextId) })
    } else {
      // A command can reach the server but lose its HTTP response (timeout,
      // worker restart, or a proxy disconnect). Keep the same request visible
      // as an outcome instead of leaving the overlay's local submit lock on an
      // editing snapshot forever. The result-query action can then resolve the
      // original request without creating a second recharge.
      const status = String(resultStatus(response) || '').toLowerCase()
      if (allowTerminalOutcome
        && ['result_unknown', 'pending_confirmation', 'processing', 'failed'].includes(status)
        && rechargeCheckout.value) {
        const envelope = cashierV3ResponseEnvelope(response)
        const result = envelope?.result && typeof envelope.result === 'object' ? envelope.result : {}
        rechargeCheckout.value = {
          ...rechargeCheckout.value,
          status,
          failureReason: String(result.message || envelope?.message || ''),
          processingLong: status === 'processing' || status === 'pending_confirmation'
        }
      }
    }
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
    // The root cashier projection can be refreshed while the recharge overlay
    // is still open.  The recharge projection already carries the authoritative
    // member/request identity, so do not lose the active session merely because
    // the live toolbar member has temporarily become empty.
    const memberId = unref(member)?.id
      || unref(currentMemberId)
      || checkout?.member?.id
      || checkout?.memberId
    if (!checkout?.rechargeCheckoutRequestId || !memberId) {
      return { result: { status: 'failed', code: 'RECHARGE_CHECKOUT_SESSION_EXPIRED', message: '充值结账现场已失效，请重新进入。' } }
    }
    if (action === 'open-checkout-source-selector') return openSourceSelector?.('recharge')
    const actionMap = {
      'add-payment-method': 'add-recharge-checkout-payment-method',
      'update-payment-line': 'update-recharge-checkout-payment-line',
      'remove-payment-line': 'remove-recharge-checkout-payment-line',
      'submit-checkout': 'submit-recharge-checkout',
      'update-checkout-business-source': 'update-recharge-checkout-business-source',
      'update-recharge-business-date': 'update-recharge-checkout-business-date',
      'query-checkout-result': 'reload-recharge-checkout'
    }
    const targetAction = actionMap[action]
    if (!targetAction) return { result: { status: 'failed', code: 'RECHARGE_CHECKOUT_ACTION_NOT_ALLOWED', message: '该充值结账操作尚未开放。' } }
    const draftAction = ['add-payment-method', 'update-payment-line', 'remove-payment-line', 'update-checkout-business-source'].includes(action)
    const requestPayload = {
      ...payload,
      memberId,
      rechargeCheckoutRequestId: checkout.rechargeCheckoutRequestId,
      rechargeCheckoutRequestVersion: checkout.checkoutRequestVersion,
      silent: draftAction
    }
    if (action === 'submit-checkout' || action === 'retry-checkout') {
      const orderLines = Array.isArray(checkout.orderLines) ? checkout.orderLines : []
      const paymentLines = Array.isArray(checkout.payment?.selectedLines)
        ? checkout.payment.selectedLines
        : []
      requestPayload.commandContexts = Array.isArray(checkout.commandContexts)
        ? clonePlain(checkout.commandContexts)
        : []
      requestPayload.checkoutSnapshot = {
        contractVersion: checkout.contractVersion || 'cashier-v3-recharge-checkout-v1',
        businessType: 'recharge',
        memberId: Number(memberId),
        member: clonePlain(checkout.member || { id: Number(memberId) }),
        rechargeCheckoutRequestId: checkout.rechargeCheckoutRequestId,
        checkoutRequestId: checkout.checkoutRequestId || checkout.rechargeCheckoutRequestId,
        checkoutRequestVersion: Number(checkout.checkoutRequestVersion || 0),
        businessDate: checkout.businessDate || '',
        businessDateReason: checkout.businessDateReason || '',
        source: {
          primarySourceId: Number(checkout.primarySourceId || 0),
          secondarySourceId: Number(checkout.secondarySourceId || 0),
          sourceSelectionVersion: Number(checkout.sourceSelectionVersion || 0),
          sourceLabel: checkout.sourceLabel || ''
        },
        orderLines: clonePlain(orderLines),
        paymentLines: clonePlain(paymentLines),
        lines: [
          ...clonePlain(orderLines),
          ...paymentLines.map((line) => ({
            ...clonePlain(line),
            lineRole: 'payment'
          }))
        ],
        summary: clonePlain(checkout.summary || {})
      }
    }
    const response = await requestCashierV3Action(targetAction, requestPayload)
    setRechargeCheckout(response, { allowTerminalOutcome: !draftAction })
    if (typeof window !== 'undefined'
      && draftAction) {
      const envelope = cashierV3ResponseEnvelope(response)
      window.dispatchEvent(new CustomEvent('cashier-v3:checkout-draft-mutation-result', {
        detail: {
          action,
          payload: clonePlain(payload),
          status: resultStatus(response),
          message: envelope?.result?.message || response?.result?.message || ''
        }
      }))
    }
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

  async function enqueueRechargeCheckoutAction(event = {}) {
    let result
    if (String(event?.action || '') === 'query-checkout-result') {
      result = await requestRechargeCheckoutAction(event)
      event?.resolve?.(result)
      return result
    }
    rechargeCheckoutMutationTail = rechargeCheckoutMutationTail
      .catch(() => undefined)
      .then(() => requestRechargeCheckoutMutationWithSingleConflictReplay(event))
    result = await rechargeCheckoutMutationTail
    event?.resolve?.(result)
    return result
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
