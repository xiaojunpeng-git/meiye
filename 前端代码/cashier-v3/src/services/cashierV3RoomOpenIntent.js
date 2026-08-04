export const CASHIER_ROOM_OPEN_INTENT_SOURCE = 'room_status_idle'
export const CASHIER_ROOM_OPEN_INTENT_CONTRACT_VERSION = 'cashier-v3-empty-room-hang-preparation-v1'

function scalar(value) {
  return Array.isArray(value) ? value[0] : value
}

function identifier(value, maxLength = 128) {
  const normalized = String(scalar(value) ?? '').trim()
  if (!normalized || normalized.length > maxLength || !/^[A-Za-z0-9:_-]+$/.test(normalized)) return ''
  return normalized
}

function positiveVersion(value) {
  const normalized = Number(scalar(value))
  return Number.isSafeInteger(normalized) && normalized > 0 ? normalized : null
}

export function roomOpenIntentFromRouteQuery(query = {}) {
  if (String(scalar(query.roomOpenIntent) ?? '') !== '1') return null
  const source = String(scalar(query.roomOpenIntentSource) ?? '').trim()
  const intentId = identifier(query.roomOpenIntentId)
  const roomId = identifier(query.preferredRoomId, 64)
  const roomName = String(scalar(query.preferredRoomName) ?? '').trim().slice(0, 120)
  const roomVersion = positiveVersion(query.preferredRoomVersion)
  const roomTimeSlotId = identifier(query.preferredRoomTimeSlotId)
  const roomTimeSlotVersion = positiveVersion(query.preferredRoomTimeSlotVersion)
  if (source !== CASHIER_ROOM_OPEN_INTENT_SOURCE
    || !intentId
    || !roomId
    || !roomName
    || roomVersion === null
    || !roomTimeSlotId
    || roomTimeSlotVersion === null) {
    return null
  }
  return Object.freeze({
    contractVersion: CASHIER_ROOM_OPEN_INTENT_CONTRACT_VERSION,
    source,
    intentId,
    roomId,
    roomName,
    roomVersion,
    roomTimeSlotId,
    roomTimeSlotVersion
  })
}

export function roomOpenIntentHangPayload(intent) {
  if (!intent || intent.source !== CASHIER_ROOM_OPEN_INTENT_SOURCE) return {}
  return {
    roomOpenIntentSource: intent.source,
    roomOpenIntentId: intent.intentId,
    preferredRoomId: intent.roomId,
    preferredRoomVersion: intent.roomVersion,
    preferredRoomTimeSlotId: intent.roomTimeSlotId,
    preferredRoomTimeSlotVersion: intent.roomTimeSlotVersion
  }
}
