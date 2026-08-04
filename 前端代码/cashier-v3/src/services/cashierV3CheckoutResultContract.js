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
  if (!originalIdempotencyKey || !checkoutRequestId || !stateContextId) return null

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
    if (phase !== 'succeeded'
      || !request
      || nonEmpty(request.requestId) !== checkoutRequestId
      || nonEmpty(request.requestStatus) !== 'succeeded'
      || positiveVersion(request.requestVersion) === null
      || !order
      || !nonEmpty(order.orderId)
      || !nonEmpty(order.orderNo)
      || nonEmpty(order.orderStatus) !== 'settled'
      || positiveVersion(order.orderVersion) === null) {
      return null
    }
    return {
      ...base,
      status: 'succeeded',
      checkoutRequest: { ...request },
      salesOrder: { ...order }
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
