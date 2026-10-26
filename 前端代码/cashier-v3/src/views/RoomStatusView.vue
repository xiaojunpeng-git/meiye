<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { createCashierV3CommandId, formatMoney, requestCashierV3Action, useCashierV3State } from '@/services/cashierV3Bridge'
import RoomDetailOverlay from '@/components/room/RoomDetailOverlay.vue'
import UnassignedRoomListOverlay from '@/components/room/UnassignedRoomListOverlay.vue'

const state = useCashierV3State()
const router = useRouter()
const roomState = computed(() => state.room || {})
const categories = computed(() => Array.isArray(roomState.value.categories) ? roomState.value.categories : [])
const pendingAssignments = computed(() => Array.isArray(roomState.value.pendingAssignments) ? roomState.value.pendingAssignments : [])
const unassignedRoomList = computed(() => {
  const list = roomState.value.unassignedList
  if (list && typeof list === 'object') return list
  return {
    records: pendingAssignments.value,
    total: pendingAssignments.value.length,
    page: 1,
    pageSize: 20,
    refreshedAt: roomState.value.refreshedAt || '',
    staleMessage: roomState.value.staleMessage || ''
  }
})
const pendingAssignmentCount = computed(() => {
  const value = Number(roomState.value.pendingAssignmentCount ?? unassignedRoomList.value.total)
  return Number.isInteger(value) && value >= 0 ? value : unassignedRoomList.value.records?.length || 0
})
const pollingIntervalSeconds = computed(() => Math.max(Number(roomState.value.pollingIntervalSeconds) || 10, 10))
const isDetailOpen = ref(false)
const roomDetailFallback = ref({})
const activeRoomDetailId = ref(null)
const isDetailLoading = ref(false)
const roomActionLocks = ref({})
const roomActionIds = ref({})
const isUnassignedRoomListOpen = ref(false)
const isUnassignedRoomListLoading = ref(false)
const activeUnassignedRecordKey = ref('')
const unassignedRoomListError = ref('')
const allowedRoomStatuses = new Set(['空闲', '服务中', '待结账'])
const allowedRoomServiceActions = new Set([
  'prepare-room-service-completion',
  'prepare-room-assignment',
  'open-room-service-checkout',
  'open-room-service-session'
])
const roomDetail = computed(() => {
  const backendDetail = roomState.value.detail
  const backendId = backendDetail?.id || backendDetail?.roomId
  if (backendDetail && (!activeRoomDetailId.value || String(backendId) === String(activeRoomDetailId.value))) return backendDetail
  return roomDetailFallback.value || {}
})
let pollingTimerId = null

const isRoomProjectionUnavailable = computed(() => {
  const projection = roomState.value
  const loadStatus = String(projection.loadStatus || projection.loadingStatus || '').trim().toLowerCase()
  return projection.isLoading === true
    || projection.loading === true
    || projection.hasError === true
    || Boolean(projection.error || projection.errorMessage)
    || ['loading', 'pending', 'failed', 'error', 'exception'].includes(loadStatus)
})

function normalizedRoomStatus(room = {}) {
  if (isRoomProjectionUnavailable.value) return ''
  const status = String(
    room.statusLabel
      || room.statusName
      || room.status
      || room.roomStatusLabel
      || room.roomStatus
      || ''
  ).trim()
  return allowedRoomStatuses.has(status) ? status : ''
}

function roomStatusLabel(room = {}) {
  return normalizedRoomStatus(room) || '状态待刷新'
}

function statusClass(room = {}) {
  const status = normalizedRoomStatus(room)
  if (status === '空闲') return 'room-card--idle'
  if (status === '服务中') return 'room-card--serving'
  if (status === '待结账') return 'room-card--pending-checkout'
  return ''
}

function nextReservationStatus(room = {}) {
  const status = String(room.nextReservationStatus || room.nextReservation?.status || '').trim().toLowerCase()
  if (room.reservationDue === true || ['due', 'waiting_to_start', '预约待开始'].includes(status)) return 'due'
  if (room.reservationConflict === true || ['conflict', 'overlap', '临近冲突'].includes(status)) return 'conflict'
  return ''
}

function nextReservationText(room = {}) {
  if (typeof room.nextReservation === 'string') return room.nextReservation
  if (room.nextReservation && typeof room.nextReservation === 'object') {
    return room.nextReservation.displayText || room.nextReservation.summary || room.nextReservation.label || ''
  }
  return ''
}

function roomWarningText(room = {}) {
  return room.conflictMessage || room.warningMessage || room.nextReservation?.warningMessage || ''
}

function isReservationRoomService(room = {}) {
  return room.serviceSource === 'reservation'
}

function reservationServiceTitle(room = {}) {
  const count = Math.max(1, Number(room.activeReservationServices?.length) || 1)
  const member = room.memberName || '会员'
  return count > 1 ? `${member}等 ${count} 笔预约服务中` : `${member}服务中`
}

async function requestAction(action, payload = {}) {
  return requestCashierV3Action(action, payload)
}

function roomById(roomId) {
  for (const category of categories.value) {
    const room = (category.rooms || []).find((item) => String(item.id || item.roomId) === String(roomId))
    if (room) return room
  }
  return null
}

function roomForAction(roomId, fallbackStatus = '') {
  const detail = roomDetail.value
  const detailId = detail?.id || detail?.roomId
  const hasMatchingDetail = detailId && String(detailId) === String(roomId)
  const hasExplicitDetailStatus = hasMatchingDetail && [
    'statusLabel',
    'statusName',
    'status',
    'roomStatusLabel',
    'roomStatus'
  ].some((key) => Object.prototype.hasOwnProperty.call(detail, key))
  if (hasExplicitDetailStatus) return detail
  return roomById(roomId) || { status: fallbackStatus }
}

function showRoomDetail(payload = {}) {
  const detail = payload?.detail || payload
  const room = detail.room || (detail.roomId ? roomById(detail.roomId) : detail)
  const roomId = detail.roomId || room?.id || room?.roomId
  if (!roomId || !normalizedRoomStatus(room || {})) return null
  const backendDetail = roomState.value.detail
  const backendDetailId = backendDetail?.id || backendDetail?.roomId
  roomDetailFallback.value = backendDetail && String(backendDetailId) === String(roomId) ? backendDetail : room || {}
  activeRoomDetailId.value = roomId
  isDetailOpen.value = true
  return { room: room || roomById(roomId), roomId }
}

async function openRoom(room) {
  const current = showRoomDetail({ room })
  if (!current) return { success: false, message: '未找到房间。' }
  isDetailLoading.value = true
  try {
    return await requestAction('open-room-detail', roomPayload(current.room || { id: current.roomId }))
  } finally {
    isDetailLoading.value = false
  }
}

function roomPayload(room) {
  return {
    roomId: room.id || room.roomId,
    roomStatus: normalizedRoomStatus(room),
    roomVersion: room.revision ?? room.roomVersion ?? room.roomRevision ?? null,
    serviceOrderId: room.serviceOrderId || room.serviceSessionId || room.serviceOrder?.id || null,
    serviceOrderVersion: room.serviceOrderRevision ?? room.serviceOrderVersion ?? room.serviceOrder?.revision ?? null,
    reservationId: room.reservationId || room.reservation?.id || null,
    reservationVersion: room.reservationRevision ?? room.reservationVersion ?? room.reservation?.revision ?? null
  }
}

async function openCashierForIdleRoom(room) {
  const payload = roomPayload(room)
  if (payload.roomStatus !== '空闲') {
    return { result: { status: 'conflict', code: 'RESOURCE_VERSION_CONFLICT', message: '房间状态已经变化，请刷新后重试。' } }
  }
  if (!Number.isSafeInteger(Number(payload.roomVersion)) || Number(payload.roomVersion) <= 0) {
    return requestAction('refresh-room-status')
  }
  const action = 'prepare-empty-room-cashier'
  const actionKey = roomActionKey(action, payload)
  if (roomActionLocks.value[actionKey]) return null
  roomActionLocks.value = { ...roomActionLocks.value, [actionKey]: true }
  try {
    return await requestAction(action, {
      roomId: payload.roomId,
      roomVersion: Number(payload.roomVersion),
      roomOpenIntentId: createCashierV3CommandId('ROOM_CASHIER_INTENT')
    })
  } finally {
    roomActionLocks.value = { ...roomActionLocks.value, [actionKey]: false }
  }
}

function roomActionKey(action, payload = {}) {
  const scope = payload.assignmentScope || 'no-scope'
  const subjectId = scope === 'reservation_plan'
    ? payload.reservationId || 'no-reservation'
    : payload.serviceOrderId || 'no-service'
  const subjectVersion = scope === 'reservation_plan'
    ? payload.reservationVersion
    : payload.serviceOrderVersion
  return `${action}:${scope}:${payload.assignmentMode || 'no-mode'}:${subjectId}:${payload.roomId || 'no-room'}:${subjectVersion ?? payload.roomVersion ?? 'no-version'}`
}

function unassignedRecordKey(record = {}) {
  return record.recordKey
    || `${record.recordType || 'record'}:${record.serviceOrder?.id || record.reservation?.id || record.id || 'unknown'}`
}

function normalizedAssignmentScope(value) {
  return ['reservation_plan', 'active_service'].includes(value) ? value : ''
}

function normalizedAssignmentMode(value) {
  const raw = typeof value === 'string'
    ? value
    : value?.mode || value?.assignmentMode || value?.assignment_mode
  if (['assign', 'change', 'remove'].includes(raw)) return raw
  if (['assign-room', 'change-room', 'remove-room'].includes(raw)) return raw.replace('-room', '')
  return ''
}

function unassignedRecordPayload(record = {}, assignmentScope = '') {
  const reservation = record.reservation && typeof record.reservation === 'object' ? record.reservation : {}
  const serviceOrder = record.serviceOrder && typeof record.serviceOrder === 'object' ? record.serviceOrder : {}
  return {
    recordKey: unassignedRecordKey(record),
    recordType: record.recordType,
    assignmentScope: normalizedAssignmentScope(assignmentScope || record.assignmentScope),
    reservationId: reservation.id || record.reservationId || null,
    reservationVersion: reservation.revision ?? record.reservationVersion ?? null,
    serviceOrderId: serviceOrder.id || record.serviceOrderId || record.serviceSessionId || null,
    serviceOrderVersion: serviceOrder.revision ?? record.serviceOrderVersion ?? record.serviceOrderRevision ?? null,
    roomId: null,
    roomVersion: null
  }
}

function responseStatus(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.result?.status || response?.status || ''
}

function responseMessage(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.result?.message || response?.message || ''
}

async function queryUnassignedRoomList({ page = 1, pageSize = 20 } = {}) {
  if (isUnassignedRoomListLoading.value) return
  isUnassignedRoomListLoading.value = true
  unassignedRoomListError.value = ''
  try {
    const result = await requestAction('open-unassigned-room-list', {
      page,
      pageSize,
      silent: true
    })
    if (['failed', 'conflict'].includes(responseStatus(result))) {
      unassignedRoomListError.value = responseMessage(result) || '待分配房间列表刷新失败，请稍后重试。'
    }
    return result
  } finally {
    isUnassignedRoomListLoading.value = false
  }
}

function openUnassignedRoomList() {
  isUnassignedRoomListOpen.value = true
  queryUnassignedRoomList({
    page: unassignedRoomList.value.page || 1,
    pageSize: unassignedRoomList.value.pageSize || 20
  })
}

async function viewUnassignedRecord({ record, action } = {}) {
  const actionCode = action?.code
  if (!record || !['open-reservation-detail', 'open-room-service-session'].includes(actionCode)) return
  const recordKey = unassignedRecordKey(record)
  if (activeUnassignedRecordKey.value) return
  activeUnassignedRecordKey.value = recordKey
  unassignedRoomListError.value = ''
  try {
    const result = await requestAction(actionCode, unassignedRecordPayload(record))
    if (['failed', 'conflict'].includes(responseStatus(result))) {
      unassignedRoomListError.value = responseMessage(result) || '暂时无法查看该记录。'
    }
    return result
  } finally {
    activeUnassignedRecordKey.value = ''
  }
}

async function assignUnassignedRecord({ record, action } = {}) {
  if (!record || action?.code !== 'prepare-room-assignment') return
  const recordAssignmentScope = normalizedAssignmentScope(record.assignmentScope)
  const actionAssignmentScope = normalizedAssignmentScope(action.assignmentScope)
  const actionAssignmentMode = normalizedAssignmentMode(action.mode || action.assignmentMode || action.assignment_mode)
  if (!recordAssignmentScope || !actionAssignmentScope || recordAssignmentScope !== actionAssignmentScope) {
    unassignedRoomListError.value = '该记录与动作的房间安排范围不一致，暂不能操作。'
    return
  }
  if (actionAssignmentMode !== 'assign') {
    unassignedRoomListError.value = '该记录的房间安排方式不完整或不正确，暂不能操作。'
    return
  }
  const assignmentScope = recordAssignmentScope
  const payload = {
    ...unassignedRecordPayload(record, assignmentScope),
    assignmentMode: actionAssignmentMode
  }
  if (!assignmentScope) {
    unassignedRoomListError.value = '该记录尚未明确是预约计划房间还是服务实际房间，暂不能操作。'
    return
  }
  if (assignmentScope === 'reservation_plan' && !payload.reservationId) {
    unassignedRoomListError.value = '该记录尚未返回预约信息，暂不能安排计划房间。'
    return
  }
  if (assignmentScope === 'active_service' && !payload.serviceOrderId) {
    unassignedRoomListError.value = '该记录尚未返回本次服务单，暂不能分配实际房间。'
    return
  }
  const recordKey = unassignedRecordKey(record)
  if (activeUnassignedRecordKey.value) return
  activeUnassignedRecordKey.value = recordKey
  unassignedRoomListError.value = ''
  try {
    const result = await requestRoomServiceAction('prepare-room-assignment', payload)
    if (['failed', 'conflict'].includes(responseStatus(result))) {
      unassignedRoomListError.value = responseMessage(result) || '房间候选加载失败，请刷新后重试。'
    }
    return result
  } finally {
    activeUnassignedRecordKey.value = ''
  }
}

function isRoomActionPending(action, room) {
  return roomActionLocks.value[roomActionKey(action, roomPayload(room))] === true
}

async function requestRoomServiceAction(action, payload = {}) {
  if (!allowedRoomServiceActions.has(action)) {
    return { result: { status: 'failed', code: 'ROOM_ACTION_NOT_ALLOWED', message: '该房间操作未进入前端允许清单。' } }
  }
  const requiredRoomStatus = action === 'prepare-room-service-completion'
    ? '服务中'
    : action === 'open-room-service-checkout'
      ? '待结账'
      : ''
  if (requiredRoomStatus) {
    const currentRoomStatus = normalizedRoomStatus(roomForAction(payload.roomId, payload.roomStatus))
    if (currentRoomStatus !== requiredRoomStatus) {
      return {
        success: false,
        result: {
          status: 'failed',
          code: 'ROOM_STATUS_REFRESH_REQUIRED',
          message: '房间状态未明确或已经变化，请刷新房态后再操作。'
        }
      }
    }
  }
  const isPreparationAction = ['prepare-room-service-completion', 'prepare-room-assignment'].includes(action)
  const isRoomAssignmentPreparation = action === 'prepare-room-assignment'
  const assignmentScope = isRoomAssignmentPreparation ? normalizedAssignmentScope(payload.assignmentScope) : ''
  const assignmentMode = isRoomAssignmentPreparation
    ? normalizedAssignmentMode(payload.assignmentMode || payload.mode)
    : ''
  if (isRoomAssignmentPreparation) {
    const hasSubject = assignmentScope === 'reservation_plan'
      ? Boolean(payload.reservationId)
      : assignmentScope === 'active_service'
        ? Boolean(payload.serviceOrderId)
        : false
    if (!hasSubject) {
      return { result: { status: 'failed', code: 'ROOM_ASSIGNMENT_SUBJECT_CONTEXT_MISSING', message: '未找到需要安排房间的预约或服务，请刷新房态后重试。' } }
    }
    if (!assignmentMode) {
      return { result: { status: 'failed', code: 'ROOM_ASSIGNMENT_MODE_MISSING', message: '房间安排方式未由后端明确返回，暂不能操作。' } }
    }
  }
  const normalizedPayload = isRoomAssignmentPreparation
    ? { ...payload, assignmentScope, assignmentMode }
    : payload
  const actionKey = roomActionKey(action, normalizedPayload)
  if (roomActionLocks.value[actionKey]) {
    return { result: { status: 'failed', code: 'ROOM_ACTION_PENDING', message: '该房间正在处理中，请勿重复操作。' } }
  }

  // 准备动作每次打开都代表新的用户意图，必须使用新的请求号；
  // 只有最终保存动作才复用稳定幂等键。
  const idempotencyKey = isPreparationAction
    ? createCashierV3CommandId('ROOM_ACTION')
    : (roomActionIds.value[actionKey] || createCashierV3CommandId('ROOM_ACTION'))
  if (!isPreparationAction) {
    roomActionIds.value = { ...roomActionIds.value, [actionKey]: idempotencyKey }
  }
  const requestPayload = { ...normalizedPayload, idempotencyKey }
  if (action === 'prepare-room-service-completion') {
    if (!normalizedPayload.serviceOrderId) {
      return { result: { status: 'failed', code: 'ROOM_SERVICE_CONTEXT_MISSING', message: '未找到本次服务单，请刷新房态后重试。' } }
    }
    const preparationRequestId = createCashierV3CommandId('SERVICE_PREPARE')
    requestPayload.preparationRequestId = preparationRequestId
    window.dispatchEvent(new CustomEvent('cashier-v3:register-service-completion-request', {
      detail: { preparationRequestId, serviceOrderId: normalizedPayload.serviceOrderId }
    }))
  }
  if (action === 'prepare-room-assignment') {
    const roomAssignmentPreparationId = createCashierV3CommandId('ROOM_PREPARE')
    requestPayload.preparationRequestId = roomAssignmentPreparationId
    requestPayload.roomAssignmentPreparationId = roomAssignmentPreparationId
    window.dispatchEvent(new CustomEvent('cashier-v3:register-room-assignment-request', {
      detail: {
        roomAssignmentPreparationId,
        preparationRequestId: roomAssignmentPreparationId,
        assignmentScope,
        assignmentMode,
        reservationId: normalizedPayload.reservationId || null,
        reservationVersion: normalizedPayload.reservationVersion ?? null,
        serviceOrderId: normalizedPayload.serviceOrderId,
        serviceOrderVersion: normalizedPayload.serviceOrderVersion ?? null,
        roomId: normalizedPayload.roomId || null
      }
    }))
  }

  roomActionLocks.value = { ...roomActionLocks.value, [actionKey]: true }
  try {
    const result = await requestAction(action, requestPayload)
    const response = result?.data && typeof result.data === 'object' ? result.data : result
    const status = response?.result?.status || response?.status || ''
    if (!isPreparationAction && ['failed', 'conflict'].includes(status)) {
      roomActionIds.value = { ...roomActionIds.value, [actionKey]: null }
    }
    return result
  } finally {
    roomActionLocks.value = { ...roomActionLocks.value, [actionKey]: false }
  }
}

function closeRoomDetail() {
  isDetailOpen.value = false
  roomDetailFallback.value = {}
  activeRoomDetailId.value = null
}

async function openReservationFromRoom(service = {}) {
  const reservationId = service.reservationId || service.id
  if (!reservationId) return
  closeRoomDetail()
  await router.push({ name: 'cashier-v3-reservation' })
  await nextTick()
  window.dispatchEvent(new CustomEvent('cashier-v3:open-reservation-detail', {
    detail: { reservationId }
  }))
}

function resetRoomLocalContext() {
  closeRoomDetail()
  isUnassignedRoomListOpen.value = false
  isUnassignedRoomListLoading.value = false
  activeUnassignedRecordKey.value = ''
  unassignedRoomListError.value = ''
  roomActionLocks.value = {}
  roomActionIds.value = {}
}

async function handleRoomDetailAction(payload = {}) {
  const actionMap = {
    'end-service': 'prepare-room-service-completion',
    'go-checkout': 'open-room-service-checkout',
    'view-service-order': 'open-room-service-session',
    'assign-room': 'prepare-room-assignment',
    'change-room': 'prepare-room-assignment',
    'remove-room': 'prepare-room-assignment'
  }
  const action = actionMap[payload.actionCode] || payload.action?.code || payload.action?.action || payload.actionCode
  if (!allowedRoomServiceActions.has(action)) {
    return { result: { status: 'failed', code: 'ROOM_ACTION_NOT_ALLOWED', message: '该房间操作暂未接入。' } }
  }
  const payloadAssignmentScope = normalizedAssignmentScope(payload.assignmentScope)
  const actionAssignmentScope = normalizedAssignmentScope(payload.action?.assignmentScope || payload.action?.assignment_scope)
  const assignmentScope = payloadAssignmentScope || actionAssignmentScope
  const assignmentMode = normalizedAssignmentMode(
    payload.assignmentMode || payload.mode || payload.action?.assignmentMode || payload.action?.assignment_mode || payload.action?.mode
  )
  if (action === 'prepare-room-assignment' && (!assignmentScope || (payloadAssignmentScope && actionAssignmentScope && payloadAssignmentScope !== actionAssignmentScope))) {
    return {
      result: {
        status: 'failed',
        code: 'ROOM_ASSIGNMENT_SCOPE_INVALID',
        message: '房间安排范围未由后端明确返回或与动作不一致，暂不能操作。'
      }
    }
  }
  if (action === 'prepare-room-assignment' && !assignmentMode) {
    return {
      result: {
        status: 'failed',
        code: 'ROOM_ASSIGNMENT_MODE_MISSING',
        message: '房间安排方式未由后端明确返回，暂不能操作。'
      }
    }
  }
  return requestRoomServiceAction(action, {
    roomId: payload.roomId,
    roomStatus: normalizedRoomStatus(roomForAction(payload.roomId, payload.roomStatus)),
    roomVersion: payload.revision,
    serviceOrderId: payload.serviceOrderId,
    serviceOrderVersion: payload.serviceOrderRevision,
    reservationId: payload.reservationId,
    reservationVersion: payload.reservationRevision,
    assignmentScope: action === 'prepare-room-assignment' ? assignmentScope : undefined,
    assignmentMode: action === 'prepare-room-assignment' ? assignmentMode : undefined
  })
}

function handleOpenRoomDetail(event) {
  showRoomDetail(event)
}

watch(
  () => [isRoomProjectionUnavailable.value, roomState.value.detail],
  () => {
    if (isDetailOpen.value && !normalizedRoomStatus(roomDetail.value)) closeRoomDetail()
  },
  { deep: true }
)

onMounted(() => {
  window.addEventListener('cashier-v3:open-room-detail', handleOpenRoomDetail)
  window.addEventListener('cashier-v3:state-context-changing', resetRoomLocalContext)
  window.addEventListener('cashier-v3:state-context-changed', resetRoomLocalContext)
  pollingTimerId = window.setInterval(() => {
    requestAction('refresh-room-status', { silent: true, source: 'fallback_poll' })
  }, pollingIntervalSeconds.value * 1000)
})

onBeforeUnmount(() => {
  window.removeEventListener('cashier-v3:open-room-detail', handleOpenRoomDetail)
  window.removeEventListener('cashier-v3:state-context-changing', resetRoomLocalContext)
  window.removeEventListener('cashier-v3:state-context-changed', resetRoomLocalContext)
  if (pollingTimerId) window.clearInterval(pollingTimerId)
})
</script>

<template>
  <section class="room-status-page" aria-label="房间状态图">
    <header class="room-status-page__toolbar">
      <div>
        <h2>当前房态</h2>
        <span>最后更新时间：{{ roomState.refreshedAt || '—' }}</span>
        <span v-if="roomState.staleMessage" class="room-status-page__stale">{{ roomState.staleMessage }}</span>
      </div>
      <div class="room-status-page__actions">
        <button type="button" class="button button--secondary" @click="openUnassignedRoomList">
          待分配房间<span v-if="pendingAssignmentCount">（{{ pendingAssignmentCount }}）</span>
        </button>
        <button type="button" class="button button--secondary" @click="requestAction('refresh-room-status')">刷新</button>
      </div>
    </header>

    <div class="room-status-page__content">
      <section v-for="category in categories" :key="category.id" class="room-category">
        <header class="room-category__header">
          <h3>{{ category.name }}</h3>
          <span>{{ (category.rooms || []).length }} 间</span>
        </header>

        <div class="room-card-grid">
          <article
            v-for="room in category.rooms || []"
            :key="room.id"
            class="room-card"
            :class="[
              statusClass(room),
              { 'room-card--reservation-due': nextReservationStatus(room) === 'due' },
              { 'room-card--reservation-conflict': nextReservationStatus(room) === 'conflict' }
            ]"
          >
            <header class="room-card__header">
              <strong>{{ room.name }}</strong>
              <span>{{ roomStatusLabel(room) }}</span>
            </header>

            <div v-if="normalizedRoomStatus(room) === '空闲'" class="room-card__body">
              <span v-if="nextReservationStatus(room) === 'due'" class="room-card__notice room-card__notice--due">预约待开始</span>
              <span v-else-if="nextReservationStatus(room) === 'conflict'" class="room-card__notice room-card__notice--conflict">临近安排冲突</span>
              <span class="room-card__label">下一场预约</span>
              <strong>{{ nextReservationText(room) || '暂无后续预约' }}</strong>
              <span v-if="roomWarningText(room)" class="room-card__warning">{{ roomWarningText(room) }}</span>
              <div class="room-card__footer-actions">
                <button v-if="nextReservationText(room) && nextReservationText(room) !== '暂无后续预约'" type="button" class="button button--text" @click="requestAction('open-room-next-reservation', roomPayload(room))">查看预约</button>
                <button
                  type="button"
                  class="button button--primary"
                  :disabled="isRoomActionPending('prepare-empty-room-cashier', room)"
                  @click="openCashierForIdleRoom(room)"
                >{{ isRoomActionPending('prepare-empty-room-cashier', room) ? '正在开单…' : '开单' }}</button>
              </div>
            </div>

            <div v-else-if="normalizedRoomStatus(room) === '服务中'" class="room-card__body">
              <template v-if="isReservationRoomService(room)">
                <strong>{{ reservationServiceTitle(room) }}</strong>
                <span>{{ room.reservationNo || '预约服务' }} · {{ room.projectSummary || '未填写项目' }}</span>
                <span>开始服务：{{ room.serviceStartedAt || '—' }}</span>
                <span v-if="Number(room.activeReservationServices?.length) > 1">当前房间有 {{ room.activeReservationServices.length }} 笔预约服务中</span>
              </template>
              <template v-else>
                <strong>{{ room.memberName || '会员服务中' }}</strong>
                <span>{{ room.serviceDuration }}</span>
                <span>主要手艺人：{{ room.primaryCraftsman || '待分配' }}</span>
                <span>待核销项目 {{ room.pendingWriteoffCount || 0 }} 项 · 本次新增 {{ formatMoney(room.newConsumptionAmount) }}</span>
              </template>
              <div class="room-card__footer-actions">
                <button type="button" class="button button--text" @click="openRoom(room)">查看详情</button>
                <button
                  v-if="!isReservationRoomService(room)"
                  type="button"
                  class="button button--secondary"
                  :disabled="isRoomActionPending('prepare-room-service-completion', room)"
                  @click="requestRoomServiceAction('prepare-room-service-completion', roomPayload(room))"
                >{{ isRoomActionPending('prepare-room-service-completion', room) ? '正在准备…' : '结束服务' }}</button>
              </div>
            </div>

            <div v-else-if="normalizedRoomStatus(room) === '待结账'" class="room-card__body">
              <strong>{{ room.memberName || '待结账' }}</strong>
              <span>{{ room.serviceFinishedAt }}</span>
              <span>待结账 {{ formatMoney(room.pendingCheckoutAmount) }}</span>
              <div class="room-card__footer-actions">
                <button type="button" class="button button--text" @click="openRoom(room)">查看详情</button>
                <button
                  type="button"
                  class="button button--primary"
                  :disabled="isRoomActionPending('open-room-service-checkout', room)"
                  @click="requestRoomServiceAction('open-room-service-checkout', roomPayload(room))"
                >{{ isRoomActionPending('open-room-service-checkout', room) ? '正在打开…' : '去结账' }}</button>
              </div>
            </div>

            <div v-else class="room-card__body" role="status">
              <strong>房间状态需要刷新</strong>
              <span>状态加载中、异常或无法识别时，暂不提供结束服务或结账操作。</span>
              <div class="room-card__footer-actions">
                <button type="button" class="button button--secondary" @click="requestAction('refresh-room-status')">刷新房态</button>
              </div>
            </div>
          </article>
        </div>
      </section>

      <div v-if="!categories.length" class="room-status-empty">
        <strong>暂无启用中的房间</strong>
        <span>停用房间不会显示在房态图中。</span>
      </div>
    </div>

    <RoomDetailOverlay
      v-if="isDetailOpen"
      :detail="roomDetail"
      :is-loading="isDetailLoading"
      :on-action="handleRoomDetailAction"
      @close="closeRoomDetail"
      @open-reservation="openReservationFromRoom"
    />

    <UnassignedRoomListOverlay
      v-if="isUnassignedRoomListOpen"
      :list="unassignedRoomList"
      :is-loading="isUnassignedRoomListLoading"
      :active-record-key="activeUnassignedRecordKey"
      :error-message="unassignedRoomListError"
      @close="isUnassignedRoomListOpen = false"
      @query="queryUnassignedRoomList"
      @view="viewUnassignedRecord"
      @assign="assignUnassignedRecord"
    />
  </section>
</template>
