<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useModalFocusTrap } from '@/composables/useModalFocusTrap'

/**
 * 预约详情展示壳。
 *
 * detail 必须是后端按当前账号数据权限返回的预约详情快照；本组件不改变预约、房间、
 * 服务、核销或订单状态，也不在前端计算金额、时长、房态或可执行动作。
 *
 * 建议 detail 至少包含：
 * {
 *   reservationNo, statusLabel, member,
 *   projects: [{ role, source, name, appliedDurationMinutes, appliedDurationLabel, durationDescription }],
 *   appointmentStartAt, appointmentEndAt, estimatedStartAt, estimatedEndAt,
 *   actualStartAt, actualEndAt, plannedCraftsmen, actualCraftsmen,
 *   room, roomChanges, relatedRecords, timeline,
 *   actions: [{ code, label, disabled, disabledReason }]
 * }
 *
 * onAction 接收 { action, actionCode, reservation, reservationId }；调用方负责请求后端、
 * 刷新详情、处理并发版本冲突及页面跳转。后端没有返回的动作不会在此组件显示。
 */
const props = defineProps({
  detail: {
    type: Object,
    default: () => ({})
  },
  summary: {
    type: Object,
    default: () => ({})
  },
  isLoading: {
    type: Boolean,
    default: false
  },
  loadStatus: {
    type: String,
    default: ''
  },
  loadError: {
    type: String,
    default: ''
  },
  loadErrorCode: {
    type: String,
    default: ''
  },
  onReload: {
    type: Function,
    default: null
  },
  onAction: {
    type: Function,
    default: null
  }
})

const emit = defineEmits(['close'])

const activeActionKey = ref('')
const actionError = ref('')
const actionErrorCode = ref('')
const rejectReason = ref('')
const isRejectReasonOpen = ref(false)
const rejectReasonError = ref('')
const clockNow = ref(Date.now())
const detailDialogRef = ref(null)
let clockTimer = null

const reservation = computed(() => (props.detail && typeof props.detail === 'object' ? props.detail : {}))
const summary = computed(() => (props.summary && typeof props.summary === 'object' ? props.summary : {}))
const effectiveLoadStatus = computed(() => {
  if (props.loadStatus) return props.loadStatus
  if (props.isLoading) return 'loading'
  return Object.keys(reservation.value).length ? 'ready' : 'idle'
})
const isDetailReady = computed(() => effectiveLoadStatus.value === 'ready')
const isDetailLoading = computed(() => effectiveLoadStatus.value === 'loading')
const isDetailError = computed(() => effectiveLoadStatus.value === 'error')
const loadErrorNeedsAnnouncement = computed(() => String(props.loadErrorCode || '').startsWith('RESERVATION_DETAIL_'))
const actionErrorNeedsAnnouncement = computed(() => String(actionErrorCode.value || '').startsWith('RESERVATION_'))
const member = computed(() => {
  const source = firstObject(reservation.value, ['member', 'memberInfo', 'customer'])
  return {
    id: firstValue(source, ['id', 'memberId', 'uid', 'userId']) || firstValue(reservation.value, ['memberId', 'member_id', 'uid', 'userId', 'customerId']),
    name: firstValue(source, ['name', 'memberName', 'customerName']) || firstValue(reservation.value, ['memberName', 'customerName']) || '—',
    phone: firstValue(source, ['phone', 'mobile', 'memberPhone']) || firstValue(reservation.value, ['memberPhone', 'phone', 'mobile']),
    memberNo: firstValue(source, ['memberNo', 'memberCode', 'code']) || firstValue(reservation.value, ['memberNo', 'memberCode']),
    status: firstValue(source, ['statusLabel', 'statusName', 'status']) || firstValue(reservation.value, ['memberStatusLabel', 'memberStatus'])
  }
})

const projectLines = computed(() => readList(reservation.value, ['projects', 'projectLines', 'appointmentProjects', 'serviceProjects']))
const plannedCraftsmen = computed(() => readList(reservation.value, ['plannedCraftsmen', 'craftsmen', 'scheduledCraftsmen', 'appointmentCraftsmen']))
const actualCraftsmen = computed(() => readList(reservation.value, ['actualCraftsmen', 'serviceCraftsmen', 'completedCraftsmen']))
const room = computed(() => firstObject(reservation.value, ['room', 'plannedRoom', 'appointmentRoom']))
const roomChanges = computed(() => readList(reservation.value, ['roomChanges', 'roomHistory', 'roomChangeRecords']))
const timeline = computed(() => readList(reservation.value, ['timeline', 'operationTimeline', 'activityTimeline', 'operationLogs']))

const timeRows = computed(() => [
  {
    label: '预约时间',
    value: timeRange(reservation.value, ['appointmentStartAt', 'reservationStartAt', 'scheduledStartAt', 'appointmentTime'], ['appointmentEndAt', 'reservationEndAt', 'scheduledEndAt'])
  },
  {
    label: '预计服务时间',
    value: timeRange(reservation.value, ['estimatedStartAt', 'expectedStartAt'], ['estimatedEndAt', 'expectedEndAt'])
  },
  {
    label: '实际服务时间',
    value: timeRange(reservation.value, ['actualStartAt', 'serviceStartedAt'], ['actualEndAt', 'serviceEndedAt'])
  }
])

const relatedGroups = computed(() => {
  const related = firstObject(reservation.value, ['relatedRecords', 'relations', 'linkedRecords'])
  return [
    {
      key: 'hang',
      label: '自动挂单',
      records: firstRelatedList(related, reservation.value, ['hangOrders', 'autoHangOrders', 'hangOrder', 'autoHangOrder'])
    },
    {
      key: 'service',
      label: '服务单',
      records: firstRelatedList(related, reservation.value, ['serviceOrders', 'serviceOrder', 'serviceSessions', 'serviceSession'])
    },
    {
      key: 'writeoff',
      label: '核销记录',
      records: firstRelatedList(related, reservation.value, ['writeoffs', 'writeoffRecords', 'writeoff'])
    },
    {
      key: 'sale',
      label: '销售订单',
      records: firstRelatedList(related, reservation.value, ['salesOrders', 'salesOrder', 'orders', 'order'])
    }
  ]
})

const RESERVATION_ACTION_ALIASES = {
  'start-service': 'start-reservation-service',
  'end-service': 'end-reservation-service',
  'delete-reservation': 'cancel-reservation'
}

const RESERVATION_DETAIL_ACTIONS = new Set([
  'edit-reservation',
  'confirm-reservation',
  'reject-reservation',
  'start-reservation-service',
  'end-reservation-service',
  'cancel-reservation',
  'go-to-cashier'
])

function normalizeActionDefinition(action) {
  if (!action || typeof action !== 'object') return null
  const rawCode = firstValue(action, ['code', 'action', 'key'])
  const key = RESERVATION_ACTION_ALIASES[rawCode] || rawCode
  if (!RESERVATION_DETAIL_ACTIONS.has(key)) return null
  return {
    key,
    label: firstValue(action, ['label', 'name', 'title']) || actionLabel(key),
    raw: { ...action, code: key },
    disabled: action.disabled === true || action.enabled === false,
    disabledReason: firstValue(action, ['disabledReason', 'reason']),
    primary: action.primary === true || ['confirm-reservation', 'start-reservation-service', 'end-reservation-service'].includes(key),
    danger: action.danger === true || ['reject-reservation', 'cancel-reservation'].includes(key)
  }
}

function actionLabel(key) {
  return {
    'edit-reservation': '编辑',
    'confirm-reservation': '确认预约',
    'reject-reservation': '拒绝',
    'start-reservation-service': '开始服务',
    'end-reservation-service': '结束服务',
    'cancel-reservation': '取消预约',
    'go-to-cashier': '去开单'
  }[key] || '操作'
}

const availableActions = computed(() => {
  const supplied = readList(reservation.value, ['actions', 'availableActions'])
    .map(normalizeActionDefinition)
    .filter(Boolean)
  if (supplied.length) return supplied

  const phase = reservationPhase.value.phase
  if (phase === 'pending') {
    return [
      { key: 'reject-reservation', label: '拒绝', raw: { code: 'reject-reservation' }, disabled: false, disabledReason: '', danger: true },
      { key: 'confirm-reservation', label: '确认预约', raw: { code: 'confirm-reservation' }, disabled: false, disabledReason: '', primary: true }
    ]
  }
  if (phase === 'unstarted') {
    return [
      { key: 'edit-reservation', label: '编辑', raw: { code: 'edit-reservation' }, disabled: false, disabledReason: '' },
      { key: 'cancel-reservation', label: '删除', raw: { code: 'cancel-reservation' }, disabled: false, disabledReason: '', danger: true },
      {
        key: 'start-service',
        label: '开始服务',
        raw: { code: 'start-service' },
        disabled: false,
        disabledReason: '',
        primary: true
      },
      {
        key: 'go-to-cashier',
        label: '去开单',
        raw: { code: 'go-to-cashier' },
        disabled: false,
        disabledReason: ''
      }
    ]
  }
  if (phase === 'serving') {
    return [{ key: 'end-service', label: '结束服务', raw: { code: 'end-service' }, disabled: false, disabledReason: '', primary: true }]
  }
  return []
})

const reservationPhase = computed(() => normalizeReservationStatus(firstValue(reservation.value, ['status', 'statusLabel', 'statusName'])))
const reservationStatus = computed(() => (isDetailReady.value ? reservationPhase.value.label : ''))
const reservationStatusHint = computed(() => ({
  pending: '会员提交的预约正在等待门店确认；确认或拒绝会同步到商家端。',
  unstarted: '预约已确认，可由门店端或商家端开始服务。',
  serving: '正在服务，倒计时以实际开始时间和项目总时长为准。',
  rejected: '预约已拒绝，卡项次数占用应已释放。',
  ended: '该预约已结束，不再提供服务操作。'
}[reservationPhase.value.phase] || ''))
const reservationNo = computed(() => (
  firstValue(reservation.value, ['reservationNo', 'appointmentNo', 'no', 'code'])
  || firstValue(summary.value, ['reservationNo', 'appointmentNo', 'no', 'code'])
  || '预约详情'
))
const roomName = computed(() => firstValue(room.value, ['name', 'roomName', 'label']) || firstValue(reservation.value, ['roomName']) || '待分配房间')
const roomStatus = computed(() => firstValue(room.value, ['statusLabel', 'statusName', 'status']) || firstValue(reservation.value, ['roomStatusLabel', 'roomStatus']))
const reservationSourceLabel = computed(() => {
  const source = String(firstValue(reservation.value, ['source', 'sourceType', 'reservationSource']) || '').toUpperCase()
  if (source === 'MEMBER') return '会员端'
  if (source === 'STORE') return '门店端'
  return firstValue(reservation.value, ['sourceLabel']) || '—'
})
const entitlementOccupation = computed(() => firstObject(reservation.value, ['entitlementOccupation', 'benefitOccupation', 'cardOccupation']))
const entitlementOccupationLabel = computed(() => {
  const occupation = entitlementOccupation.value
  const label = firstValue(occupation, ['statusLabel', 'label'])
  if (label) return label
  const status = String(firstValue(occupation, ['status', 'state']) || '').toUpperCase()
  if (['OCCUPIED', 'HELD', 'ACTIVE'].includes(status)) return '已占用'
  if (['CONSUMED', 'WRITTEN_OFF', 'COMPLETED'].includes(status)) return '已转消费'
  if (['RELEASED', 'CANCELLED', 'REJECTED'].includes(status)) return '已释放'
  return '无卡项占用'
})

function parseServerTimestamp(value) {
  if (!value) return null
  const normalized = typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(value)
    ? value.replace(' ', 'T')
    : value
  const timestamp = new Date(normalized).getTime()
  return Number.isFinite(timestamp) ? timestamp : null
}

const serviceExpectedEndTimestamp = computed(() => {
  const explicit = firstValue(reservation.value, ['serviceExpectedEndAt', 'countdownEndsAt', 'expectedServiceEndAt'])
    || firstValue(firstObject(reservation.value, ['serviceCountdown', 'countdown']), ['endsAt', 'expectedEndAt'])
  const explicitTimestamp = parseServerTimestamp(explicit)
  if (explicitTimestamp !== null) return explicitTimestamp

  const actualStartTimestamp = parseServerTimestamp(firstValue(reservation.value, ['actualStartAt', 'serviceStartedAt']))
  const durationMinutes = Number(firstValue(reservation.value, ['totalServiceDurationMinutes', 'serviceDurationMinutes', 'plannedDurationMinutes']))
  if (actualStartTimestamp === null || !Number.isFinite(durationMinutes) || durationMinutes <= 0) return null
  return actualStartTimestamp + durationMinutes * 60 * 1000
})

function formatClockDuration(totalSeconds) {
  const safeSeconds = Math.max(0, Math.floor(Math.abs(totalSeconds)))
  const hours = Math.floor(safeSeconds / 3600)
  const minutes = Math.floor((safeSeconds % 3600) / 60)
  const seconds = safeSeconds % 60
  return [hours, minutes, seconds].map((value) => String(value).padStart(2, '0')).join(':')
}

const serviceClock = computed(() => {
  if (reservationPhase.value.phase !== 'serving' || serviceExpectedEndTimestamp.value === null) return null
  const remainingSeconds = Math.floor((serviceExpectedEndTimestamp.value - clockNow.value) / 1000)
  const overtime = remainingSeconds < 0
  return {
    overtime,
    label: overtime ? `已超时 ${formatClockDuration(remainingSeconds)}` : `剩余 ${formatClockDuration(remainingSeconds)}`
  }
})

function firstValue(source, keys) {
  if (!source || typeof source !== 'object') return ''
  for (const key of keys) {
    const value = source[key]
    if (value !== undefined && value !== null && value !== '') return value
  }
  return ''
}

function firstObject(source, keys) {
  if (!source || typeof source !== 'object') return {}
  for (const key of keys) {
    const value = source[key]
    if (value && typeof value === 'object' && !Array.isArray(value)) return value
  }
  return {}
}

function readList(source, keys) {
  if (!source || typeof source !== 'object') return []
  for (const key of keys) {
    const value = source[key]
    if (Array.isArray(value)) return value
    if (value && typeof value === 'object') return [value]
  }
  return []
}

function firstRelatedList(related, root, keys) {
  const fromRelated = readList(related, keys)
  return fromRelated.length ? fromRelated : readList(root, keys)
}

function plainText(value, fallback = '—') {
  if (value === undefined || value === null || value === '') return fallback
  return String(value)
}

function timeRange(source, startKeys, endKeys) {
  const start = firstValue(source, startKeys)
  const end = firstValue(source, endKeys)
  if (start && end) return `${start} 至 ${end}`
  return start || end || '—'
}

function roleText(line) {
  const role = firstValue(line, ['role', 'projectRole', 'roleLabel'])
  if (line?.isMain || role === 'main' || role === '主项目') return '主项目'
  return '明细项目'
}

function sourceText(line) {
  const source = firstValue(line, ['sourceLabel', 'source', 'projectSource', 'benefitSource'])
  if (source === 'card' || source === '卡内' || source === '卡内项目') return '卡内'
  if (source === 'unpaid' || source === '未购' || source === '未购项目') return '未购'
  return plainText(source, '待后端确认')
}

function projectName(line) {
  return plainText(firstValue(line, ['name', 'projectName', 'productName', 'label']))
}

function projectKey(line, index) {
  return firstValue(line, ['id', 'projectLineId', 'reservationProjectId', 'projectId']) || `${projectName(line)}-${index}`
}

function processingStatus(line) {
  return plainText(firstValue(line, ['processingStatus', 'resultStatus']), 'unknown')
}

function processingStatusLabel(line) {
  return plainText(firstValue(line, ['processingStatusLabel', 'resultStatusLabel']), '处理状态待核对')
}

function processingDescription(line) {
  return plainText(firstValue(line, ['processingDescription', 'resultDescription']), '当前记录没有完整的项目处理结果，请人工核对。')
}

function positiveNumber(value) {
  const number = Number(value)
  return Number.isFinite(number) && number > 0 ? number : null
}

function durationValue(line) {
  const appliedLabel = firstValue(line, ['appliedDurationLabel', 'durationLabel'])
    || firstValue(line?.duration, ['appliedLabel', 'label'])
  if (appliedLabel) return plainText(appliedLabel)

  const appliedMinutes = positiveNumber(firstValue(line, ['appliedDurationMinutes', 'durationMinutes']) || firstValue(line?.duration, ['appliedMinutes', 'minutes']))
  if (appliedMinutes) return `${appliedMinutes}分钟`

  const isMain = roleText(line) === '主项目'
  const configuredMinutes = positiveNumber(isMain
    ? firstValue(line, ['projectServiceDuration', 'mainProjectDuration']) || firstValue(line?.duration, ['projectServiceDuration'])
    : firstValue(line, ['addonServiceDuration', 'detailProjectDuration']) || firstValue(line?.duration, ['addonServiceDuration']))
  if (configuredMinutes) return `${configuredMinutes}分钟（商品配置）`

  // 0 代表未单独配置，绝不能显示成 0 分钟。
  return '继承／默认时长'
}

function durationDescription(line) {
  const description = firstValue(line, ['durationDescription', 'durationNote', 'durationRemark'])
    || firstValue(line?.duration, ['description', 'note'])
  return plainText(description, '未单独配置时长时，按平台继承或系统默认时长执行。')
}

function craftsmanName(craftsman) {
  return plainText(firstValue(craftsman, ['name', 'staffName', 'employeeName', 'label']))
}

function craftsmanMeta(craftsman) {
  const role = firstValue(craftsman, ['roleLabel', 'role', 'assignmentRole'])
  const status = firstValue(craftsman, ['statusLabel', 'status'])
  return [role, status].filter(Boolean).join(' · ')
}

function roomChangeKey(change, index) {
  return firstValue(change, ['id', 'changeId', 'operationId']) || `${roomChangeText(change)}-${index}`
}

function roomChangeText(change) {
  const from = firstValue(change, ['fromRoomName', 'previousRoomName', 'fromName']) || '待分配房间'
  const to = firstValue(change, ['toRoomName', 'currentRoomName', 'roomName', 'toName']) || '待分配房间'
  return `${from} → ${to}`
}

function roomChangeMeta(change) {
  return [
    firstValue(change, ['changedAt', 'occurredAt', 'operationTime', 'time']),
    firstValue(change, ['operatorName', 'operator', 'staffName'])
  ].filter(Boolean).join(' · ')
}

function relationKey(record, index) {
  return firstValue(record, ['id', 'recordId', 'orderId', 'writeoffId', 'no', 'orderNo', 'serviceNo', 'hangNo']) || `relation-${index}`
}

function relationNo(record) {
  return plainText(firstValue(record, ['no', 'orderNo', 'serviceNo', 'hangNo', 'writeoffNo', 'recordNo', 'code', 'name']))
}

function relationMeta(record) {
  return [
    firstValue(record, ['statusLabel', 'statusName', 'status']),
    firstValue(record, ['createdAt', 'occurredAt', 'businessDate', 'time'])
  ].filter(Boolean).join(' · ')
}

function timelineKey(item, index) {
  return firstValue(item, ['id', 'operationId', 'eventId', 'logId']) || `${timelineTitle(item)}-${index}`
}

function timelineTitle(item) {
  return plainText(firstValue(item, ['title', 'eventLabel', 'actionLabel', 'operationName', 'name']), '状态更新')
}

function timelineDescription(item) {
  return firstValue(item, ['description', 'content', 'remark', 'note'])
}

function timelineMeta(item) {
  return [
    firstValue(item, ['occurredAt', 'operationTime', 'time', 'createdAt']),
    firstValue(item, ['operatorName', 'operator', 'staffName'])
  ].filter(Boolean).join(' · ')
}

function normalizeReservationStatus(rawStatus) {
  const raw = String(rawStatus || '').trim()
  const normalized = raw.toUpperCase().replace(/[\s-]/g, '_')
  if (raw === '待确认' || normalized === 'PENDING_CONFIRMATION') {
    return { label: '待确认', phase: 'pending' }
  }
  if (
    ['未开始', '待服务', '已预约'].includes(raw)
    || ['UNSTARTED', 'SCHEDULED', 'CONFIRMED'].includes(normalized)
  ) return { label: '待服务', phase: 'unstarted' }
  if (
    ['服务中', '进行中'].includes(raw)
    || ['SERVING', 'IN_SERVICE', 'SERVICE_IN_PROGRESS'].includes(normalized)
  ) return { label: '服务中', phase: 'serving' }
  if (raw === '已拒绝' || normalized === 'REJECTED') return { label: '已拒绝', phase: 'rejected' }
  if (raw === '已取消' || ['CANCELLED', 'CANCELED'].includes(normalized)) return { label: '已取消', phase: 'ended' }
  return { label: '已结束', phase: 'ended' }
}

function actionResultBlock(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.result && typeof response.result === 'object' ? response.result : response || {}
}

function actionResultStatus(result) {
  return String(actionResultBlock(result)?.status || '').toLowerCase()
}

function actionResultCode(result) {
  return String(actionResultBlock(result)?.code || '')
}

function actionResultMessage(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.feedback?.message
    || actionResultBlock(result)?.message
    || response?.message
    || ''
}

function isActionResultError(result) {
  return result === false
    || result?.success === false
    || result?.ok === false
    || ['failed', 'conflict', 'result_unknown'].includes(actionResultStatus(result))
}

function operatorActionError(result) {
  const status = actionResultStatus(result)
  const code = actionResultCode(result)
  if (code === 'INVALID_COMMAND_CONTEXT' || code === 'RESERVATION_PUBLIC_VERSION_NOT_READY') {
    return '预约数据已经更新或尚未同步，本次操作没有提交。请重新加载详情后再试。'
  }
  if (status === 'result_unknown' || code === 'COMMAND_RESULT_UNKNOWN') {
    return '操作结果正在确认，请勿重复提交。请返回预约列表查看最新状态。'
  }
  if (status === 'conflict') {
    return '这条预约已被其他人员更新，本次操作未完成。请重新加载详情后再确认。'
  }
  if (code.startsWith('ENVELOPE_')) {
    return '系统没有采用本次返回结果。请勿重复操作，先返回列表查看最新状态并重新加载详情。'
  }
  return actionResultMessage(result) || '操作未完成，请重新加载预约详情后再试。'
}

async function executeAction(action, extraPayload = {}) {
  if (!props.onAction || action.disabled || activeActionKey.value) return

  activeActionKey.value = action.key
  actionError.value = ''
  actionErrorCode.value = ''
  try {
    const result = await props.onAction({
      action: action.raw,
      actionCode: action.key,
      reservation: reservation.value,
      reservationId: firstValue(reservation.value, ['id', 'reservationId', 'appointmentId']),
      ...extraPayload
    })

    if (isActionResultError(result)) {
      actionError.value = operatorActionError(result)
      actionErrorCode.value = actionResultCode(result)
    }
    return result
  } catch (error) {
    actionError.value = '操作请求中断，当前结果无法确认。请勿重复提交，先返回列表查看最新状态。'
    actionErrorCode.value = 'RESERVATION_ACTION_INTERRUPTED'
    return { result: { status: 'failed', code: 'RESERVATION_ACTION_INTERRUPTED', message: error?.message || '操作请求中断。' } }
  } finally {
    activeActionKey.value = ''
  }
}

async function requestReload() {
  if (!props.onReload || activeActionKey.value) return
  activeActionKey.value = 'reload-detail'
  actionError.value = ''
  actionErrorCode.value = ''
  try {
    await props.onReload()
  } catch {
    // 调用方会把加载中断写入明确的 error 状态；这里不再叠加第二条提示。
  } finally {
    activeActionKey.value = ''
  }
}

async function triggerAction(action) {
  if (action.key === 'reject-reservation') {
    rejectReason.value = ''
    rejectReasonError.value = ''
    isRejectReasonOpen.value = true
    return
  }
  await executeAction(action)
}

async function submitRejection() {
  const reason = rejectReason.value.trim()
  if (!reason) {
    rejectReasonError.value = '请填写拒绝原因。'
    return
  }
  const action = availableActions.value.find((item) => item.key === 'reject-reservation')
  if (!action) return
  rejectReasonError.value = ''
  const result = await executeAction(action, { reason })
  if (!isActionResultError(result)) {
    isRejectReasonOpen.value = false
    rejectReason.value = ''
  }
}

function closeRejection() {
  if (activeActionKey.value) return
  isRejectReasonOpen.value = false
  rejectReason.value = ''
  rejectReasonError.value = ''
}

function requestClose() {
  if (activeActionKey.value) return
  if (isRejectReasonOpen.value) {
    closeRejection()
    return
  }
  emit('close')
}

onMounted(() => {
  clockTimer = window.setInterval(() => {
    clockNow.value = Date.now()
  }, 1000)
})

onBeforeUnmount(() => {
  if (clockTimer !== null) window.clearInterval(clockTimer)
  clockTimer = null
})

useModalFocusTrap({
  containerRef: detailDialogRef,
  canClose: () => activeActionKey.value === '',
  onClose: requestClose
})
</script>

<template>
  <Teleport to="body">
    <div class="reservation-detail" role="presentation" @click.self="requestClose">
      <section ref="detailDialogRef" class="reservation-detail__dialog" role="dialog" aria-modal="true" aria-labelledby="reservation-detail-title" tabindex="-1">
      <header class="reservation-detail__header">
        <div>
          <div class="reservation-detail__eyebrow">预约详情</div>
          <h2 id="reservation-detail-title">{{ reservationNo }}</h2>
          <div v-if="isDetailReady" class="reservation-detail__status-row">
            <span class="reservation-detail__status">{{ reservationStatus }}</span>
            <span>{{ reservationStatusHint }}</span>
          </div>
          <div v-else class="reservation-detail__status-row">
            <span>{{ isDetailLoading ? '正在加载完整预约详情' : isDetailError ? '完整预约详情未加载' : '预约详情尚未就绪' }}</span>
          </div>
        </div>
        <button type="button" class="reservation-detail__close" :disabled="activeActionKey !== ''" aria-label="关闭预约详情" @click="requestClose">×</button>
      </header>

      <main class="reservation-detail__body" :aria-busy="isDetailLoading">
        <div v-if="isDetailLoading" class="reservation-detail__loading">
          <span class="reservation-detail__loading-dot" />
          正在加载预约详情…
        </div>

        <div
          v-else-if="isDetailError"
          class="reservation-detail__load-error"
          :role="loadErrorNeedsAnnouncement ? 'alert' : 'region'"
          aria-labelledby="reservation-detail-load-error-title"
        >
          <strong id="reservation-detail-load-error-title">预约详情未加载成功</strong>
          <span>{{ loadError || '为避免展示不完整数据，当前详情和写操作已停止。' }}</span>
          <small v-if="loadErrorCode">错误编号：{{ loadErrorCode }}</small>
          <div>
            <button type="button" class="reservation-detail__button reservation-detail__button--primary" :disabled="activeActionKey !== ''" @click="requestReload">
              {{ activeActionKey === 'reload-detail' ? '正在重新加载…' : '重新加载详情' }}
            </button>
            <button type="button" class="reservation-detail__button reservation-detail__button--secondary" :disabled="activeActionKey !== ''" @click="requestClose">返回预约列表</button>
          </div>
        </div>

        <div v-else-if="!isDetailReady" class="reservation-detail__empty">
          <strong>预约详情尚未就绪</strong>
          <span>请返回列表后重新打开该预约。</span>
        </div>

        <template v-else>
          <section class="reservation-detail__section">
            <header class="reservation-detail__section-header">
              <h3>会员</h3>
            </header>
            <div class="reservation-detail__member-card">
              <strong>{{ member.name }}</strong>
              <dl>
                <div><dt>完整手机号</dt><dd>{{ member.phone || '—' }}</dd></div>
                <div><dt>会员编号</dt><dd>{{ member.memberNo || '—' }}</dd></div>
                <div><dt>会员状态</dt><dd>{{ member.status || '—' }}</dd></div>
                <div><dt>预约来源</dt><dd>{{ reservationSourceLabel }}</dd></div>
                <div><dt>卡项次数</dt><dd>{{ entitlementOccupationLabel }}</dd></div>
              </dl>
            </div>
          </section>

          <section class="reservation-detail__section">
            <header class="reservation-detail__section-header">
              <h3>预约项目</h3>
              <span>逐项目显示预约来源、服务结果及权益是否扣除。</span>
            </header>
            <div v-if="projectLines.length" class="reservation-detail__project-list">
              <article v-for="(line, index) in projectLines" :key="projectKey(line, index)" class="reservation-detail__project-row">
                <div class="reservation-detail__project-name">
                  <span class="reservation-detail__tag" :class="{ 'reservation-detail__tag--main': roleText(line) === '主项目' }">{{ roleText(line) }}</span>
                  <strong>{{ projectName(line) }}</strong>
                  <span class="reservation-detail__source">来源：{{ sourceText(line) }}</span>
                </div>
                <dl class="reservation-detail__project-duration">
                  <div><dt>采用时长</dt><dd>{{ durationValue(line) }}</dd></div>
                  <div><dt>时长说明</dt><dd>{{ durationDescription(line) }}</dd></div>
                </dl>
                <div class="reservation-detail__project-result">
                  <span :class="`reservation-detail__result-tag reservation-detail__result-tag--${processingStatus(line)}`">{{ processingStatusLabel(line) }}</span>
                  <small>{{ processingDescription(line) }}</small>
                </div>
              </article>
            </div>
            <div v-else class="reservation-detail__empty-inline">暂无项目明细</div>
          </section>

          <section class="reservation-detail__section reservation-detail__section--two-columns">
            <div>
              <header class="reservation-detail__section-header">
                <h3>服务时间</h3>
              </header>
              <dl class="reservation-detail__info-list">
                <div v-for="row in timeRows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ row.value }}</dd></div>
              </dl>
              <div
                v-if="serviceClock"
                class="reservation-detail__service-clock"
                :class="{ 'reservation-detail__service-clock--overtime': serviceClock.overtime }"
                role="timer"
                aria-live="off"
              >
                <span>{{ serviceClock.overtime ? '服务超时' : '服务倒计时' }}</span>
                <strong>{{ serviceClock.label }}</strong>
                <small>归零后只显示超时，不会自动结束服务。</small>
              </div>
            </div>
            <div>
              <header class="reservation-detail__section-header">
                <h3>房间安排</h3>
              </header>
              <dl class="reservation-detail__info-list">
                <div><dt>预约房间</dt><dd>{{ roomName }}</dd></div>
                <div><dt>房间状态</dt><dd>{{ roomStatus || '—' }}</dd></div>
              </dl>
              <p class="reservation-detail__note">预约未开始时不占用房间；只有开始服务后，房间才进入实际服务状态。</p>
            </div>
          </section>

          <section class="reservation-detail__section reservation-detail__section--two-columns">
            <div>
              <header class="reservation-detail__section-header">
                <h3>计划手艺人</h3>
                <span>预约安排</span>
              </header>
              <ul v-if="plannedCraftsmen.length" class="reservation-detail__staff-list">
                <li v-for="(craftsman, index) in plannedCraftsmen" :key="firstValue(craftsman, ['id', 'staffId', 'employeeId']) || `planned-${index}`">
                  <strong>{{ craftsmanName(craftsman) }}</strong>
                  <span>{{ craftsmanMeta(craftsman) || '预约安排' }}</span>
                </li>
              </ul>
              <div v-else class="reservation-detail__empty-inline">暂未安排手艺人</div>
            </div>
            <div>
              <header class="reservation-detail__section-header">
                <h3>实际服务人员</h3>
                <span>服务确认后记录</span>
              </header>
              <ul v-if="actualCraftsmen.length" class="reservation-detail__staff-list">
                <li v-for="(craftsman, index) in actualCraftsmen" :key="firstValue(craftsman, ['id', 'staffId', 'employeeId']) || `actual-${index}`">
                  <strong>{{ craftsmanName(craftsman) }}</strong>
                  <span>{{ craftsmanMeta(craftsman) || '实际服务人员' }}</span>
                </li>
              </ul>
              <div v-else class="reservation-detail__empty-inline">尚未形成实际服务人员记录</div>
            </div>
          </section>

          <section class="reservation-detail__section">
            <header class="reservation-detail__section-header">
              <h3>房间变更</h3>
              <span>按后端历史记录展示</span>
            </header>
            <ul v-if="roomChanges.length" class="reservation-detail__change-list">
              <li v-for="(change, index) in roomChanges" :key="roomChangeKey(change, index)">
                <strong>{{ roomChangeText(change) }}</strong>
                <span>{{ roomChangeMeta(change) || '—' }}</span>
                <p v-if="firstValue(change, ['reason', 'remark', 'note'])">{{ firstValue(change, ['reason', 'remark', 'note']) }}</p>
              </li>
            </ul>
            <div v-else class="reservation-detail__empty-inline">暂无房间变更</div>
          </section>

          <section class="reservation-detail__section">
            <header class="reservation-detail__section-header">
              <h3>关联业务</h3>
              <span>由后端返回关联关系，不在前端推断。</span>
            </header>
            <div class="reservation-detail__relation-grid">
              <article v-for="group in relatedGroups" :key="group.key" class="reservation-detail__relation-card">
                <h4>{{ group.label }}</h4>
                <ul v-if="group.records.length">
                  <li v-for="(record, index) in group.records" :key="relationKey(record, index)">
                    <strong>{{ relationNo(record) }}</strong>
                    <span>{{ relationMeta(record) || '—' }}</span>
                  </li>
                </ul>
                <span v-else class="reservation-detail__relation-empty">暂无关联</span>
              </article>
            </div>
          </section>

          <section class="reservation-detail__section">
            <header class="reservation-detail__section-header">
              <h3>操作时间线</h3>
            </header>
            <ol v-if="timeline.length" class="reservation-detail__timeline">
              <li v-for="(item, index) in timeline" :key="timelineKey(item, index)">
                <span class="reservation-detail__timeline-marker" />
                <div>
                  <strong>{{ timelineTitle(item) }}</strong>
                  <span>{{ timelineMeta(item) || '—' }}</span>
                  <p v-if="timelineDescription(item)">{{ timelineDescription(item) }}</p>
                </div>
              </li>
            </ol>
            <div v-else class="reservation-detail__empty-inline">暂无操作记录</div>
          </section>
        </template>
      </main>

      <footer class="reservation-detail__footer">
        <template v-if="isDetailReady">
        <div v-if="isRejectReasonOpen" class="reservation-detail__reason-panel">
          <div><strong>拒绝会员预约</strong><span>提交后将释放本单占用的卡项次数。</span></div>
          <label for="reservation-reject-reason"><span>拒绝原因</span>
          <textarea
            id="reservation-reject-reason"
            v-model="rejectReason"
            rows="3"
            maxlength="200"
            placeholder="请填写告知会员的拒绝原因"
            :disabled="activeActionKey !== ''"
            @input="rejectReasonError = ''"
          />
          </label>
          <div class="reservation-detail__reason-actions">
            <button type="button" class="reservation-detail__button reservation-detail__button--secondary" :disabled="activeActionKey !== ''" @click="closeRejection">取消</button>
            <button type="button" class="reservation-detail__button reservation-detail__button--danger" :disabled="activeActionKey !== ''" @click="submitRejection">
              {{ activeActionKey === 'reject-reservation' ? '正在提交…' : '确认拒绝' }}
            </button>
          </div>
          <p v-if="rejectReasonError" role="alert">{{ rejectReasonError }}</p>
        </div>
        <div
          v-if="actionError"
          class="reservation-detail__action-error"
          :role="actionErrorNeedsAnnouncement ? 'alert' : 'region'"
        >
          <span>{{ actionError }}</span>
          <small v-if="actionErrorCode">错误编号：{{ actionErrorCode }}</small>
        </div>
        <div v-if="!isRejectReasonOpen" class="reservation-detail__actions">
          <button type="button" class="reservation-detail__button reservation-detail__button--secondary" :disabled="activeActionKey !== ''" @click="requestClose">关闭</button>
          <template v-for="action in availableActions" :key="action.key">
            <button
              type="button"
              class="reservation-detail__button"
              :class="{
                'reservation-detail__button--primary': action.primary,
                'reservation-detail__button--danger': action.danger
              }"
              :disabled="!onAction || action.disabled || activeActionKey !== ''"
              :title="action.disabledReason || ''"
              @click="triggerAction(action)"
            >
              {{ activeActionKey === action.key ? '处理中…' : action.label }}
            </button>
          </template>
        </div>
        </template>
        <div v-else class="reservation-detail__footer-state">
          <span>{{ isDetailLoading ? '正在读取完整预约信息…' : '当前不可执行预约操作' }}</span>
          <button type="button" class="reservation-detail__button reservation-detail__button--secondary" :disabled="activeActionKey !== ''" @click="requestClose">关闭</button>
        </div>
      </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.reservation-detail {
  position: fixed;
  z-index: 1250;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 28px;
  background: rgb(16 24 40 / 48%);
}

.reservation-detail__dialog {
  display: flex;
  width: min(1120px, 100%);
  height: min(820px, calc(100vh - 56px));
  min-height: 0;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid #dfe5ef;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 28px 80px rgb(16 24 40 / 28%);
}

.reservation-detail__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24px;
  padding: 24px 28px 20px;
  border-bottom: 1px solid #eaecf0;
}

.reservation-detail__eyebrow {
  color: #667085;
  font-size: 13px;
  line-height: 20px;
}

.reservation-detail__header h2 {
  margin: 3px 0 8px;
  color: #172033;
  font-size: 22px;
  line-height: 30px;
}

.reservation-detail__status-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 12px;
  color: #667085;
  font-size: 13px;
  line-height: 20px;
}

.reservation-detail__status {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  padding: 0 9px;
  border-radius: 999px;
  background: #eff8ff;
  color: #175cd3;
  font-weight: 600;
}

.reservation-detail__close {
  width: 34px;
  height: 34px;
  flex: 0 0 auto;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #667085;
  cursor: pointer;
  font-size: 28px;
  line-height: 30px;
}

.reservation-detail__close:hover:not(:disabled) {
  background: #f2f4f7;
  color: #344054;
}

.reservation-detail__body {
  min-height: 0;
  flex: 1;
  overflow: auto;
  padding: 24px 28px 30px;
  background: #fcfcfd;
  scrollbar-gutter: stable;
}

.reservation-detail__reason-panel {
  display: grid;
  width: 100%;
  grid-template-columns: minmax(210px, .8fr) minmax(280px, 1.2fr) auto;
  align-items: end;
  gap: 12px;
}

.reservation-detail__reason-panel > div:first-child,
.reservation-detail__reason-panel label {
  display: grid;
  gap: 4px;
}

.reservation-detail__reason-panel strong {
  color: #1d2939;
  font-size: 14px;
}

.reservation-detail__reason-panel span {
  color: #667085;
  font-size: 12px;
  line-height: 18px;
}

.reservation-detail__reason-panel textarea {
  min-height: 58px;
  resize: vertical;
  padding: 9px 10px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  color: #344054;
  font: inherit;
  font-size: 13px;
}

.reservation-detail__reason-panel textarea:focus-visible {
  border-color: #1677cc;
  outline: 3px solid rgb(22 119 204 / 16%);
}

.reservation-detail__reason-panel > p {
  grid-column: 2;
  margin: -6px 0 0;
  color: #b42318;
  font-size: 12px;
}

.reservation-detail__reason-actions {
  display: flex;
  gap: 8px;
}

.reservation-detail__button--danger {
  border-color: #f5b7b1;
  background: #fff1f0;
  color: #b42318;
}

.reservation-detail__section + .reservation-detail__section {
  margin-top: 22px;
}

.reservation-detail__section {
  border: 1px solid #eaecf0;
  border-radius: 12px;
  background: #fff;
}

.reservation-detail__section-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
  padding: 16px 18px;
  border-bottom: 1px solid #f0f2f5;
}

.reservation-detail__section-header h3,
.reservation-detail__relation-card h4 {
  margin: 0;
  color: #1d2939;
  font-size: 15px;
  line-height: 22px;
}

.reservation-detail__section-header span {
  color: #98a2b3;
  font-size: 12px;
  line-height: 18px;
  text-align: right;
}

.reservation-detail__member-card {
  padding: 16px 18px 18px;
}

.reservation-detail__member-card > strong {
  display: block;
  margin-bottom: 12px;
  color: #101828;
  font-size: 16px;
}

.reservation-detail__member-card dl,
.reservation-detail__info-list,
.reservation-detail__project-duration {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin: 0;
}

.reservation-detail__member-card dl > div,
.reservation-detail__info-list > div,
.reservation-detail__project-duration > div {
  min-width: 0;
}

.reservation-detail dt {
  margin-bottom: 4px;
  color: #98a2b3;
  font-size: 12px;
  line-height: 18px;
}

.reservation-detail dd {
  margin: 0;
  overflow-wrap: anywhere;
  color: #344054;
  font-size: 13px;
  line-height: 20px;
}

.reservation-detail__service-clock {
  display: grid;
  gap: 4px;
  margin-top: 14px;
  padding: 13px 14px;
  border: 1px solid #abefc6;
  border-radius: 10px;
  background: #ecfdf3;
  color: #067647;
}

.reservation-detail__service-clock > span,
.reservation-detail__service-clock > small {
  font-size: 12px;
  line-height: 18px;
}

.reservation-detail__service-clock > strong {
  font-variant-numeric: tabular-nums;
  font-size: 22px;
  line-height: 28px;
}

.reservation-detail__service-clock--overtime {
  border-color: #fecdca;
  background: #fef3f2;
  color: #b42318;
}

.reservation-detail__project-list {
  display: grid;
}

.reservation-detail__project-row {
  display: grid;
  grid-template-columns: minmax(210px, .8fr) minmax(280px, 1fr) minmax(230px, .9fr);
  gap: 20px;
  align-items: center;
  padding: 16px 18px;
}

.reservation-detail__project-row + .reservation-detail__project-row {
  border-top: 1px solid #f0f2f5;
}

.reservation-detail__project-name {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.reservation-detail__project-name strong {
  color: #1d2939;
  font-size: 14px;
  line-height: 22px;
}

.reservation-detail__tag {
  display: inline-flex;
  align-items: center;
  min-height: 22px;
  padding: 0 7px;
  border-radius: 5px;
  background: #f2f4f7;
  color: #475467;
  font-size: 12px;
}

.reservation-detail__tag--main {
  background: #ecfdf3;
  color: #027a48;
}

.reservation-detail__source {
  color: #667085;
  font-size: 12px;
  line-height: 18px;
}

.reservation-detail__project-duration {
  grid-template-columns: minmax(130px, .4fr) minmax(220px, 1fr);
}

.reservation-detail__project-result {
  display: grid;
  gap: 6px;
  align-content: center;
}

.reservation-detail__project-result small {
  color: #667085;
  font-size: 12px;
  line-height: 18px;
}

.reservation-detail__result-tag {
  display: inline-flex;
  width: fit-content;
  align-items: center;
  min-height: 24px;
  padding: 0 8px;
  border-radius: 999px;
  background: #f2f4f7;
  color: #475467;
  font-size: 12px;
  font-weight: 600;
}

.reservation-detail__result-tag--entitlement_deducted {
  background: #ecfdf3;
  color: #027a48;
}

.reservation-detail__result-tag--debt_blocked {
  background: #fef3f2;
  color: #b42318;
}

.reservation-detail__result-tag--registration_only,
.reservation-detail__result-tag--pending_entitlement {
  background: #eff8ff;
  color: #175cd3;
}

.reservation-detail__section--two-columns {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.reservation-detail__section--two-columns > div + div {
  border-left: 1px solid #eaecf0;
}

.reservation-detail__info-list {
  grid-template-columns: 1fr;
  gap: 0;
  padding: 4px 18px 16px;
}

.reservation-detail__info-list > div {
  display: grid;
  grid-template-columns: 112px minmax(0, 1fr);
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid #f2f4f7;
}

.reservation-detail__info-list > div:last-child {
  border-bottom: 0;
}

.reservation-detail__info-list dt {
  margin: 0;
}

.reservation-detail__note {
  margin: 0 18px 18px;
  padding: 10px 12px;
  border-radius: 8px;
  background: #f0f9ff;
  color: #175cd3;
  font-size: 12px;
  line-height: 19px;
}

.reservation-detail__staff-list,
.reservation-detail__change-list,
.reservation-detail__relation-card ul,
.reservation-detail__timeline {
  margin: 0;
  padding: 0;
  list-style: none;
}

.reservation-detail__staff-list {
  padding: 8px 18px 16px;
}

.reservation-detail__staff-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  min-height: 42px;
  border-bottom: 1px solid #f2f4f7;
}

.reservation-detail__staff-list li:last-child {
  border-bottom: 0;
}

.reservation-detail__staff-list strong,
.reservation-detail__change-list strong,
.reservation-detail__relation-card strong,
.reservation-detail__timeline strong {
  color: #344054;
  font-size: 13px;
  line-height: 20px;
}

.reservation-detail__staff-list span,
.reservation-detail__change-list span,
.reservation-detail__relation-card span,
.reservation-detail__timeline span {
  color: #98a2b3;
  font-size: 12px;
  line-height: 18px;
}

.reservation-detail__change-list {
  padding: 2px 18px 12px;
}

.reservation-detail__change-list li {
  display: grid;
  grid-template-columns: minmax(180px, .75fr) minmax(160px, .65fr) minmax(0, 1fr);
  gap: 12px;
  align-items: baseline;
  padding: 12px 0;
  border-bottom: 1px solid #f2f4f7;
}

.reservation-detail__change-list li:last-child {
  border-bottom: 0;
}

.reservation-detail__change-list p,
.reservation-detail__timeline p {
  margin: 0;
  color: #667085;
  font-size: 12px;
  line-height: 18px;
}

.reservation-detail__relation-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  padding: 16px 18px 18px;
}

.reservation-detail__relation-card {
  min-width: 0;
  min-height: 104px;
  padding: 14px;
  border: 1px solid #eaecf0;
  border-radius: 10px;
  background: #fcfcfd;
}

.reservation-detail__relation-card h4 {
  margin-bottom: 10px;
  font-size: 13px;
}

.reservation-detail__relation-card li + li {
  margin-top: 8px;
}

.reservation-detail__relation-card strong,
.reservation-detail__relation-card span {
  display: block;
  overflow-wrap: anywhere;
}

.reservation-detail__relation-empty {
  color: #98a2b3;
}

.reservation-detail__timeline {
  padding: 18px 18px 8px 38px;
}

.reservation-detail__timeline li {
  position: relative;
  min-height: 56px;
  padding: 0 0 20px 20px;
}

.reservation-detail__timeline li::before {
  position: absolute;
  top: 11px;
  bottom: -3px;
  left: 4px;
  width: 1px;
  background: #d0d5dd;
  content: '';
}

.reservation-detail__timeline li:last-child::before {
  display: none;
}

.reservation-detail__timeline-marker {
  position: absolute;
  top: 4px;
  left: -1px;
  width: 11px;
  height: 11px;
  border: 2px solid #fff;
  border-radius: 50%;
  background: #2e90fa;
  box-shadow: 0 0 0 1px #84caff;
}

.reservation-detail__timeline strong,
.reservation-detail__timeline span {
  display: block;
}

.reservation-detail__timeline span {
  margin-top: 2px;
}

.reservation-detail__timeline p {
  margin-top: 4px;
}

.reservation-detail__empty-inline {
  padding: 16px 18px;
  color: #98a2b3;
  font-size: 13px;
  line-height: 20px;
}

.reservation-detail__loading,
.reservation-detail__empty {
  display: grid;
  min-height: 200px;
  place-content: center;
  justify-items: center;
  gap: 10px;
  color: #667085;
  font-size: 14px;
  line-height: 22px;
  text-align: center;
}

.reservation-detail__empty strong {
  color: #344054;
  font-size: 15px;
}

.reservation-detail__load-error {
  display: grid;
  max-width: 640px;
  min-height: 220px;
  place-content: center;
  gap: 10px;
  margin: 0 auto;
  padding: 24px;
  color: #667085;
  font-size: 14px;
  line-height: 22px;
  text-align: center;
}

.reservation-detail__load-error strong {
  color: #b42318;
  font-size: 17px;
}

.reservation-detail__load-error small,
.reservation-detail__action-error small {
  color: #667085;
  font-size: 12px;
}

.reservation-detail__load-error > div {
  display: flex;
  justify-content: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 6px;
}

.reservation-detail__loading-dot {
  width: 22px;
  height: 22px;
  border: 3px solid #d1e9ff;
  border-top-color: #2e90fa;
  border-radius: 50%;
  animation: reservation-detail-spin .85s linear infinite;
}

.reservation-detail__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
  min-height: 74px;
  padding: 14px 28px;
  border-top: 1px solid #eaecf0;
  background: #fff;
}

.reservation-detail__footer-state {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  color: #667085;
  font-size: 13px;
}

.reservation-detail__action-error {
  display: grid;
  gap: 2px;
  max-width: 46%;
  margin: 0;
  color: #b42318;
  font-size: 13px;
  line-height: 20px;
}

.reservation-detail__action-error--recoverable {
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 8px;
}

.reservation-detail__action-error--recoverable button {
  padding: 0;
  border: 0;
  background: transparent;
  color: #175cd3;
  cursor: pointer;
  font: inherit;
  font-weight: 600;
  text-decoration: underline;
}

.reservation-detail__actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;
  margin-left: auto;
}

.reservation-detail__button {
  min-height: 34px;
  padding: 0 13px;
  border: 1px solid #d0d5dd;
  border-radius: 7px;
  background: #fff;
  color: #344054;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  line-height: 20px;
}

.reservation-detail__button:hover:not(:disabled) {
  border-color: #98a2b3;
  background: #f9fafb;
}

.reservation-detail__button--secondary {
  color: #475467;
}

.reservation-detail__button--primary {
  border-color: #1570ef;
  background: #1570ef;
  color: #fff;
}

.reservation-detail__button--primary:hover:not(:disabled) {
  border-color: #175cd3;
  background: #175cd3;
}

.reservation-detail__button:disabled,
.reservation-detail__close:disabled {
  cursor: not-allowed;
  opacity: .55;
}

@keyframes reservation-detail-spin {
  to { transform: rotate(360deg); }
}

@media (max-width: 880px) {
  .reservation-detail {
    padding: 12px;
  }

  .reservation-detail__dialog {
    height: calc(100vh - 24px);
  }

  .reservation-detail__header,
  .reservation-detail__body,
  .reservation-detail__footer {
    padding-right: 18px;
    padding-left: 18px;
  }

  .reservation-detail__section--two-columns,
  .reservation-detail__project-row,
  .reservation-detail__relation-grid {
    grid-template-columns: 1fr;
  }

  .reservation-detail__section--two-columns > div + div {
    border-top: 1px solid #eaecf0;
    border-left: 0;
  }

  .reservation-detail__project-duration,
  .reservation-detail__member-card dl {
    grid-template-columns: 1fr;
  }

  .reservation-detail__change-list li {
    grid-template-columns: 1fr;
    gap: 3px;
  }

  .reservation-detail__reason-panel {
    grid-template-columns: 1fr;
    align-items: stretch;
  }

  .reservation-detail__reason-panel > p {
    grid-column: 1;
  }

  .reservation-detail__reason-actions {
    justify-content: flex-end;
  }
}

@media (max-width: 620px) {
  .reservation-detail__header {
    gap: 12px;
    padding-top: 18px;
    padding-bottom: 16px;
  }

  .reservation-detail__header h2 {
    font-size: 18px;
    line-height: 26px;
  }

  .reservation-detail__section-header {
    align-items: flex-start;
    flex-direction: column;
    gap: 4px;
  }

  .reservation-detail__section-header span {
    text-align: left;
  }

  .reservation-detail__footer {
    align-items: stretch;
    flex-direction: column;
  }

  .reservation-detail__action-error {
    max-width: none;
  }

  .reservation-detail__actions {
    justify-content: flex-start;
    margin-left: 0;
  }
}
</style>
