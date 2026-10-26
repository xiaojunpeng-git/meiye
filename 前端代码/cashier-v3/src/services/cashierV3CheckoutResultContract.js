const CHECKOUT_RESULT_CONTRACT = 'cashier-v3-checkout-result-v1'
const CHECKOUT_RESULT_ACTION = 'query-checkout-result'

function isRecord(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function nonEmpty(value) {
  return String(value ?? '').trim()
}

function positiveVersion(value) {
  const version = Number(value)
  return Number.isSafeInteger(version) && version > 0 ? version : null
}

export function cashierV3ResponseEnvelope(rawResult) {
  if (!isRecord(rawResult)) return {}
  if (isRecord(rawResult.result)) return rawResult
  return isRecord(rawResult.data) && isRecord(rawResult.data.result) ? rawResult.data : rawResult
}

export function cashierV3ResponseRequiresRefresh(rawResult) {
  const envelope = cashierV3ResponseEnvelope(rawResult)
  return envelope.requiresRefresh === true || rawResult?.requiresRefresh === true
}

export function authoritativeCheckoutResult(rawResult, expected = {}) {
  const envelope = cashierV3ResponseEnvelope(rawResult)
  const originalIdempotencyKey = nonEmpty(expected.originalIdempotencyKey)
  const checkoutRequestId = nonEmpty(expected.checkoutRequestId)
  const stateContextId = nonEmpty(expected.stateContextId)
  // Browser-only checkout previews have no server request ID until a receipt
  // exists. Bind them to the original command and response correlation first.
  if (!originalIdempotencyKey || !stateContextId) return null

  const correlationId = nonEmpty(envelope.correlationId)
  if (nonEmpty(envelope.boundAction) !== CHECKOUT_RESULT_ACTION
    || nonEmpty(envelope.boundCanonical) !== CHECKOUT_RESULT_ACTION
    || nonEmpty(envelope.boundOriginalIdempotencyKey) !== originalIdempotencyKey
    || nonEmpty(envelope.stateContextId) !== stateContextId
    || !correlationId
    || nonEmpty(envelope.boundCorrelationId) !== correlationId) {
    return null
  }

  const dto = isRecord(envelope.data?.checkoutResult) ? envelope.data.checkoutResult : null
  if (!dto
    || nonEmpty(dto.contractVersion) !== CHECKOUT_RESULT_CONTRACT
    || nonEmpty(dto.originalIdempotencyKey) !== originalIdempotencyKey) {
    return null
  }

  const status = nonEmpty(dto.status).toLowerCase()
  const phase = nonEmpty(dto.phase).toLowerCase()
  const base = {
    status,
    phase,
    code: nonEmpty(dto.code),
    message: nonEmpty(dto.message),
    originalIdempotencyKey
  }

  if (['success', 'succeeded'].includes(status)) {
    const request = isRecord(dto.checkoutRequest) ? dto.checkoutRequest : null
    const order = isRecord(dto.salesOrder) ? dto.salesOrder : null
    const entitlement = isRecord(dto.entitlementCompletion) ? dto.entitlementCompletion : null
    const composition = nonEmpty(dto.composition)
    if (phase !== 'succeeded'
      || !request
      || !/^CKR-[0-9a-f]{40}$/.test(nonEmpty(request.requestId))
      || (checkoutRequestId && nonEmpty(request.requestId) !== checkoutRequestId)
      || nonEmpty(request.requestStatus) !== 'succeeded'
      || positiveVersion(request.requestVersion) === null
      || !['sale_only', 'mixed', 'entitlement_only'].includes(composition)
      || (composition !== 'entitlement_only' && (!order
        || !nonEmpty(order.orderId) || !nonEmpty(order.orderNo)
        || nonEmpty(order.orderStatus) !== 'settled'
        || positiveVersion(order.orderVersion) === null))
      || (composition === 'entitlement_only' && order)
      || (composition !== 'sale_only' && (!entitlement
        || !/^ECR-[0-9a-f]{40}$/.test(nonEmpty(entitlement.receiptId))
        || nonEmpty(entitlement.receiptStatus) !== 'completed'))
      || (composition === 'sale_only' && entitlement)) {
      return null
    }
    return {
      ...base,
      status: 'succeeded',
      checkoutRequest: { ...request },
      salesOrder: order ? { ...order } : null,
      entitlementCompletion: entitlement ? { ...entitlement } : null
    }
  }

  if (status === 'result_unknown' && phase && phase !== 'succeeded') {
    return { ...base, status: 'result_unknown', checkoutRequest: null, salesOrder: null }
  }
  if (status === 'failed' && phase === 'failed') {
    return { ...base, status: 'failed', checkoutRequest: null, salesOrder: null }
  }
  return null
}
