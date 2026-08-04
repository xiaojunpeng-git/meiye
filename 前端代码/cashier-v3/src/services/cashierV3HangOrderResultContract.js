const HANG_ORDER_RESULT_CONTRACT = 'cashier-v3-hang-order-result-v1'
const HANG_ORDER_RESULT_ACTION = 'query-hang-order-result'

function isRecord(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function nonEmpty(value) {
  return String(value ?? '').trim()
}

function positiveInteger(value) {
  const number = Number(value)
  return Number.isSafeInteger(number) && number > 0 ? number : null
}

function responseEnvelope(rawResult) {
  if (!isRecord(rawResult)) return {}
  if (isRecord(rawResult.result)) return rawResult
  return isRecord(rawResult.data) && isRecord(rawResult.data.result) ? rawResult.data : rawResult
}

/**
 * Validates a recovery projection before it can release a blocked hang-order
 * overlay. A successful transport response is never enough on its own.
 */
export function authoritativeHangOrderResult(rawResult, expected = {}) {
  const envelope = responseEnvelope(rawResult)
  const originalIdempotencyKey = nonEmpty(expected.originalIdempotencyKey)
  const stateContextId = nonEmpty(expected.stateContextId)
  if (!originalIdempotencyKey || !stateContextId) return null

  const correlationId = nonEmpty(envelope.correlationId)
  if (nonEmpty(envelope.boundAction) !== HANG_ORDER_RESULT_ACTION
    || nonEmpty(envelope.boundCanonical) !== HANG_ORDER_RESULT_ACTION
    || nonEmpty(envelope.boundOriginalIdempotencyKey) !== originalIdempotencyKey
    || nonEmpty(envelope.stateContextId) !== stateContextId
    || !correlationId
    || nonEmpty(envelope.boundCorrelationId) !== correlationId) {
    return null
  }

  const dto = isRecord(envelope.data?.hangOrderResult) ? envelope.data.hangOrderResult : null
  if (!dto
    || nonEmpty(dto.contractVersion) !== HANG_ORDER_RESULT_CONTRACT
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
    const hangOrder = isRecord(dto.hangOrder) ? dto.hangOrder : null
    const room = isRecord(hangOrder?.room) ? hangOrder.room : null
    const mode = nonEmpty(hangOrder?.hangMode)
    const expectedStatus = mode === 'start_service' ? 'service_in_progress' : 'pending_checkout'
    if (phase !== 'succeeded'
      || !hangOrder
      || !/^HGO[0-9a-f]{40}$/.test(nonEmpty(hangOrder.hangOrderId))
      || !/^HG[0-9]{8}[0-9A-F]{16}$/.test(nonEmpty(hangOrder.hangOrderNo))
      || !['normal', 'start_service'].includes(mode)
      || nonEmpty(hangOrder.hangStatus) !== expectedStatus
      || positiveInteger(hangOrder.hangVersion) === null
      || positiveInteger(hangOrder.lineCount) === null
      || positiveInteger(hangOrder.totalQuantity) === null
      || !room
      || (mode === 'start_service' && (
        positiveInteger(room.roomId) === null
        || !nonEmpty(room.roomTimeSlotId)
        || room.occupied !== true
      ))) {
      return null
    }
    return { ...base, status: 'succeeded', hangOrder: { ...hangOrder, room: { ...room } } }
  }
  if (status === 'result_unknown' && phase && phase !== 'succeeded') {
    return { ...base, status: 'result_unknown', hangOrder: null }
  }
  if (status === 'failed' && phase === 'failed') {
    return { ...base, status: 'failed', hangOrder: null }
  }
  return null
}
