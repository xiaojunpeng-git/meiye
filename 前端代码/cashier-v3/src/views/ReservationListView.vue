<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import TablePagination from '@/components/common/TablePagination.vue'
import UnifiedQueryToolbar from '@/components/query/UnifiedQueryToolbar.vue'
import ReservationEditorOverlay from '@/components/reservation/ReservationEditorOverlay.vue'
import ReservationDetailOverlay from '@/components/reservation/ReservationDetailOverlay.vue'
import {
  createCashierV3CommandId,
  getCashierV3PublicVersion,
  openCashierV3QueryEntitySelector,
  requestCashierV3Action,
  useCashierV3State
} from '@/services/cashierV3Bridge'

const state = useCashierV3State()
const viewMode = ref('calendar')
const calendarResourceMode = ref('staff')
const activeQuickKey = ref('today')
const isEditorOpen = ref(false)
const isEditorLoading = ref(false)
const editorLoadMessage = ref('')
const isEditorSubmitting = ref(false)
const editorDraft = ref({})
const preparedEditorConfig = ref(null)
const editorSubmitCommandId = ref(null)
const editorPreparationRequestId = ref(null)
const editorSession = ref(null)
const editorSubmissionStatus = ref('')
const editorSubmissionResult = ref({})
const isDetailOpen = ref(false)
const reservationDetail = ref({})
const reservationDetailSummary = ref({})
const isDetailLoading = ref(false)
const reservationDetailLoadStatus = ref('idle')
const reservationDetailLoadError = ref('')
const reservationDetailLoadErrorCode = ref('')
const activeDetailReservationId = ref('')
const suppressedDetailReservationId = ref('')
const reservationActionLocks = ref({})
const reservationActionIds = ref({})
let reservationDetailRequestSequence = 0
let resolvePendingMemberSelection = null

const reservation = computed(() => state.reservation || {})
const records = computed(() => Array.isArray(reservation.value.records) ? reservation.value.records : [])
const total = computed(() => Math.max(Number(reservation.value.total) || 0, records.value.length))
const page = computed(() => Math.max(1, Number(reservation.value.page) || 1))
const pageSize = computed(() => Math.max(1, Number(reservation.value.pageSize) || 20))
const quickCounts = computed(() => reservation.value.quickCounts || {})
// 编辑器准备接口是独立投影，不替换整份预约根状态。优先保留本次已通过
// session／请求号校验的准备结果；没有本地准备结果时才兼容根状态中的编辑器。
const editorConfig = computed(() => preparedEditorConfig.value || reservation.value.editor || {})
const editorCatalogOptions = computed(() => Array.isArray(editorConfig.value.catalogOptions) ? editorConfig.value.catalogOptions : [])
const editorCraftsmenOptions = computed(() => Array.isArray(editorConfig.value.craftsmenOptions) ? editorConfig.value.craftsmenOptions : [])
const editorRooms = computed(() => Array.isArray(editorConfig.value.rooms) ? editorConfig.value.rooms : [])
const reservationStatusOptions = computed(() => {
  if (!Array.isArray(reservation.value.statusOptions)) return []
  return reservation.value.statusOptions
    .map((option) => {
      if (typeof option === 'string' && option.trim()) return { value: option.trim(), normal: false }
      if (!option || typeof option !== 'object') return null
      const value = option.value ?? option.code ?? option.status
      if (value === undefined || value === null || String(value).trim() === '') return null
      return {
        ...option,
        value,
        label: option.label || option.name || String(value),
        normal: option.normal === true || option.isNormal === true
      }
    })
    .filter(Boolean)
})
const editorSubmission = computed(() => {
  const suppliedSubmission = editorConfig.value.submission && typeof editorConfig.value.submission === 'object'
    ? editorConfig.value.submission
    : {}
  const backend = submissionBelongsToCurrentEditor(suppliedSubmission) ? suppliedSubmission : {}
  const merged = { ...backend, ...editorSubmissionResult.value }
  return {
    ...merged,
    status: editorSubmissionStatus.value || normalizeSubmissionStatus(merged.status),
    originalIdempotencyKey: editorSubmitCommandId.value
      || merged.originalIdempotencyKey
      || '',
    idempotencyKey: editorSubmitCommandId.value
      || merged.originalIdempotencyKey
      || ''
  }
})
const calendar = computed(() => reservation.value.calendar || { resources: [], blocks: [] })
const calendarResources = computed(() => (calendar.value.resources || []).filter((resource) => {
  if (calendarResourceMode.value === 'staff') return resource.type === 'staff' || resource.type === 'unassigned_staff'
  return resource.type === 'room' || resource.type === 'unassigned_room'
}))
const calendarBlocks = computed(() => Array.isArray(calendar.value.blocks) ? calendar.value.blocks : [])
const calendarStyle = computed(() => ({
  '--reservation-resource-count': Math.max(calendarResources.value.length, 1)
}))

const queryFields = computed(() => [
  { key: 'reservation_no', label: '预约记录号', defaultVisible: true },
  { key: 'appointment_time', label: '预约时间', defaultVisible: true, defaultQuick: true, type: 'date' },
  { key: 'member_name', label: '会员姓名', defaultVisible: true, defaultQuick: true },
  { key: 'phone', label: '手机号', defaultVisible: false },
  { key: 'project', label: '预约项目', defaultVisible: true },
  { key: 'project_source', label: '项目来源', defaultVisible: true },
  { key: 'craftsman', label: '预约手艺人', defaultVisible: true, type: 'person' },
  { key: 'room', label: '房间', defaultVisible: true },
  {
    key: 'status',
    label: '预约状态',
    defaultVisible: true,
    type: 'enum',
    options: reservationStatusOptions.value.map((option) => ({ value: option.value, label: option.label }))
  },
  { key: 'remark', label: '备注', defaultVisible: false },
  { key: 'source', label: '预约来源', defaultVisible: false },
  { key: 'creator', label: '创建人', defaultVisible: false, type: 'person' }
])

function normalizeQuerySettings(settings) {
  if (!settings || typeof settings !== 'object') return null
  return {
    ...settings,
    visibleFields: Array.isArray(settings.visibleFields) ? [...settings.visibleFields] : settings.visibleFields
  }
}

const appliedQuerySettings = ref(null)
const reservationQuerySnapshot = ref({
  keyword: '',
  dataScope: 'normal',
  businessStatus: '',
  topFilters: [],
  querySettings: {
    sorts: [],
    filters: [],
    filterRelation: 'all'
  },
  quickFilter: activeQuickKey.value
})
const visibleReservationFields = computed(() => {
  const settings = appliedQuerySettings.value || reservation.value.querySettings || {}
  const configuredKeys = Array.isArray(settings.visibleFields)
    ? settings.visibleFields
    : queryFields.value.filter((field) => field.defaultVisible !== false).map((field) => field.key)
  const fieldMap = new Map(queryFields.value.map((field) => [field.key, field]))
  const usedKeys = new Set()

  return configuredKeys
    .filter((key) => {
      if (!fieldMap.has(key) || usedKeys.has(key)) return false
      usedKeys.add(key)
      return true
    })
    .map((key) => fieldMap.get(key))
})

watch(
  () => reservation.value.querySettings,
  (settings) => {
    applyQuerySettings(settings)
  },
  { deep: true, immediate: true }
)

watch(
  () => editorConfig.value.submission,
  (submission) => {
    if (!isEditorOpen.value || !submission || typeof submission !== 'object' || !submissionBelongsToCurrentEditor(submission)) return
    const status = normalizeSubmissionStatus(submission.status)
    if (status === 'editing') return
    editorSubmissionResult.value = { ...editorSubmissionResult.value, ...submission }
    editorSubmissionStatus.value = status
    if (status === 'succeeded') closeReservationEditor()
  },
  { deep: true }
)

function applyQuerySettings(settings) {
  const normalizedSettings = normalizeQuerySettings(settings)
  appliedQuerySettings.value = normalizedSettings
  if (!normalizedSettings) return
  reservationQuerySnapshot.value = normalizeReservationQuery({
    ...reservationQuerySnapshot.value,
    querySettings: {
      sorts: normalizedSettings.sorts,
      filters: normalizedSettings.filters,
      filterRelation: normalizedSettings.filterRelation
    }
  })
}

function normalizeReservationQuery(query = {}, quickFilter = activeQuickKey.value) {
  const source = query && typeof query === 'object' ? query : {}
  const querySettings = source.querySettings && typeof source.querySettings === 'object'
    ? source.querySettings
    : {}
  return {
    keyword: typeof source.keyword === 'string' ? source.keyword : '',
    dataScope: source.dataScope === 'all' ? 'all' : 'normal',
    businessStatus: source.businessStatus === undefined || source.businessStatus === null ? '' : source.businessStatus,
    topFilters: Array.isArray(source.topFilters) ? source.topFilters.map((filter) => ({ ...filter })) : [],
    querySettings: {
      sorts: Array.isArray(querySettings.sorts) ? querySettings.sorts.map((sort) => ({ ...sort })) : [],
      filters: Array.isArray(querySettings.filters) ? querySettings.filters.map((filter) => ({ ...filter })) : [],
      filterRelation: querySettings.filterRelation === 'any' ? 'any' : 'all'
    },
    quickFilter: typeof quickFilter === 'string' && quickFilter ? quickFilter : 'today',
    page: Math.max(1, Number(source.page) || 1),
    pageSize: Math.max(1, Number(source.pageSize) || pageSize.value)
  }
}

function queryReservations(query = reservationQuerySnapshot.value, resetPage = true) {
  const normalized = normalizeReservationQuery(query, activeQuickKey.value)
  normalized.page = resetPage ? 1 : normalized.page
  reservationQuerySnapshot.value = normalized
  return requestAction('query-reservations', normalized)
}

function changeReservationCalendarDate(direction) {
  return requestAction('change-reservation-calendar-date', {
    ...normalizeReservationQuery(reservationQuerySnapshot.value, activeQuickKey.value),
    calendarDate: String(calendar.value.date || ''),
    direction
  })
}

function reservationFieldValue(record, fieldKey) {
  const values = {
    appointment_time: record.appointmentTime,
    member_name: record.memberName,
    phone: record.phone,
    project: record.projectSummary || record.project,
    project_source: record.projectSource,
    craftsman: record.craftsmanSummary || '待分配手艺人',
    room: record.roomName || '待分配房间',
    remark: record.remark,
    source: record.source,
    creator: record.creator
  }
  return values[fieldKey] || record[fieldKey] || '—'
}

const quickFilters = computed(() => [
  { key: 'today', label: '今日预约', count: quickCounts.value.today, active: activeQuickKey.value === 'today' },
  { key: 'pending', label: '待确认', count: quickCounts.value.pending, active: activeQuickKey.value === 'pending' },
  { key: 'serving', label: '进行中', count: quickCounts.value.serving, active: activeQuickKey.value === 'serving' },
  { key: 'all', label: '全部', active: activeQuickKey.value === 'all' }
])

const timeSlots = computed(() => {
  if (Array.isArray(calendar.value.timeSlots) && calendar.value.timeSlots.length) {
    return calendar.value.timeSlots
  }
  return []
})

const RESERVATION_ACTION_ALIASES = {
  'start-service': 'start-reservation-service',
  'end-service': 'prepare-reservation-service-completion',
  'go-checkout': 'open-reservation-checkout',
  'mark-no-show': 'mark-reservation-no-show'
}

const ALLOWED_RESERVATION_RECORD_ACTIONS = new Set([
  'open-reservation-detail',
  'confirm-reservation',
  'start-reservation-service',
  'prepare-reservation-service-completion',
  'open-reservation-checkout',
  'cancel-reservation',
  'reject-reservation',
  'mark-reservation-no-show'
])

function normalizedReservationAction(action) {
  const value = String(action || '')
  const normalized = RESERVATION_ACTION_ALIASES[value] || value
  return ALLOWED_RESERVATION_RECORD_ACTIONS.has(normalized) ? normalized : ''
}

function positivePublicReservationVersion(reservationId) {
  if (!reservationId) return null
  const version = Number(getCashierV3PublicVersion('reservation', reservationId))
  return Number.isSafeInteger(version) && version > 0 ? version : null
}

function hasPublicReservationVersion(recordOrId) {
  const reservationId = typeof recordOrId === 'object'
    ? recordOrId?.id || recordOrId?.reservationId || recordOrId?.appointmentId
    : recordOrId
  return positivePublicReservationVersion(reservationId) !== null
}

const canStartServiceFromDetail = computed(() => (
  reservationDetailLoadStatus.value === 'ready'
  && hasPublicReservationVersion(activeDetailReservationId.value)
))

function primaryAction(record) {
  const configured = record?.primaryAction
    || (Array.isArray(record?.actions) ? record.actions.find((action) => action?.primary === true) : null)
  const action = normalizedReservationAction(configured?.action || configured?.code)
  if (action === 'start-reservation-service' && !hasPublicReservationVersion(record)) {
    return {
      label: '加载后开始',
      action: 'open-reservation-detail',
      recoveryFor: action,
      disabledReason: '预约数据尚未同步，请先加载完整预约详情。'
    }
  }
  if (action && configured.enabled !== false) {
    return {
      ...configured,
      action,
      label: configured.label || '继续处理'
    }
  }
  return { label: '查看详情', action: 'open-reservation-detail' }
}

function statusClass(status) {
  if (status === '待确认') return 'reservation-status--pending'
  if (status === '服务中') return 'reservation-status--serving'
  if (status === '待结账') return 'reservation-status--checkout'
  return 'reservation-status--scheduled'
}

function blocksForResource(resourceId, time) {
  const slotStart = clockMinutes(time)
  if (slotStart === null) return []
  const slotEnd = slotStart + 30
  return calendarBlocks.value.filter((block) => {
    const blockStart = clockMinutes(block.start)
    return block.resourceId === resourceId
      && blockStart !== null
      && blockStart >= slotStart
      && blockStart < slotEnd
  })
}

function clockMinutes(value) {
  const matched = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(String(value || ''))
  return matched ? (Number(matched[1]) * 60) + Number(matched[2]) : null
}

function reservationPayload(recordOrId) {
  const record = typeof recordOrId === 'object'
    ? recordOrId
    : records.value.find((item) => item.id === recordOrId)
  const reservationId = typeof recordOrId === 'object'
    ? recordOrId.id || recordOrId.reservationId || recordOrId.appointmentId
    : recordOrId
  const reservationVersion = positivePublicReservationVersion(reservationId)
  return {
    reservationId,
    // 业务写命令只认与当前 stateContextId 绑定的公共版本仓；页面行字段不能补造版本。
    recordVersion: reservationVersion,
    reservationVersion,
    // 服务确认／去结账同时携带预约与本次服务单上下文；两个版本不可混用。
    serviceOrderId: record?.serviceOrderId || record?.serviceSessionId || record?.serviceOrder?.id || record?.serviceSession?.id || null,
    serviceOrderVersion: record?.serviceOrderRevision
      ?? record?.serviceOrderVersion
      ?? record?.serviceOrder?.revision
      ?? record?.serviceSession?.revision
      ?? null
  }
}

function approvedReservationActionPayload(actionDefinition, record) {
  const base = reservationPayload(record)
  const supplied = {
    ...(actionDefinition?.payload && typeof actionDefinition.payload === 'object' ? actionDefinition.payload : {}),
    ...(actionDefinition && typeof actionDefinition === 'object' ? actionDefinition : {})
  }
  const suppliedReservationId = firstDefined(supplied, ['reservationId', 'appointmentId'])
  if (
    suppliedReservationId !== undefined
    && String(suppliedReservationId) !== String(base.reservationId)
  ) {
    return { valid: false, message: '预约动作指向了其他预约，已拒绝执行。' }
  }

  const suppliedServiceOrderId = firstDefined(supplied, ['serviceOrderId', 'serviceSessionId'])
  if (
    suppliedServiceOrderId !== undefined
    && (!base.serviceOrderId || String(suppliedServiceOrderId) !== String(base.serviceOrderId))
  ) {
    return { valid: false, message: '预约动作指向了其他服务单，已拒绝执行。' }
  }

  const approved = {}
  for (const key of ['preparationToken', 'snapshotToken', 'actionToken', 'checkoutRequestId', 'checkoutRequestVersion']) {
    if (supplied[key] !== undefined) approved[key] = supplied[key]
  }
  if (Array.isArray(supplied.commandContexts)) {
    const contexts = supplied.commandContexts.map((context) => ({ ...context }))
    const mismatched = contexts.some((context) => (
      (context.kind === 'reservation' && String(context.id) !== String(base.reservationId))
      || (context.kind === 'service_order' && (!base.serviceOrderId || String(context.id) !== String(base.serviceOrderId)))
    ))
    if (mismatched) return { valid: false, message: '预约动作的对象版本与当前记录不一致，已拒绝执行。' }
    approved.commandContexts = contexts
  }
  return { valid: true, payload: { ...base, ...approved } }
}

function reservationActionKey(action, payload = {}) {
  return `${action}:${payload.reservationId || 'no-reservation'}:${payload.reservationVersion ?? payload.recordVersion ?? 'no-version'}`
}

function isReservationActionPending(action, record) {
  return reservationActionLocks.value[reservationActionKey(action, reservationPayload(record))] === true
}

async function requestReservationRecordAction(action, payload = {}) {
  if (!ALLOWED_RESERVATION_RECORD_ACTIONS.has(action)) {
    return { result: { status: 'failed', code: 'RESERVATION_ACTION_NOT_ALLOWED', message: '该预约操作未进入前端允许清单。' } }
  }
  const actionKey = reservationActionKey(action, payload)
  if (reservationActionLocks.value[actionKey]) {
    return { result: { status: 'failed', code: 'RESERVATION_ACTION_PENDING', message: '该预约正在处理中，请勿重复操作。' } }
  }

  if (action === 'prepare-reservation-service-completion' && !payload.serviceOrderId) {
    return { result: { status: 'failed', code: 'RESERVATION_SERVICE_CONTEXT_MISSING', message: '未找到本次服务单，请刷新预约后重试。' } }
  }

  const idempotencyKey = reservationActionIds.value[actionKey] || createCashierV3CommandId('RESERVATION_ACTION')
  reservationActionIds.value = { ...reservationActionIds.value, [actionKey]: idempotencyKey }
  const requestPayload = { ...payload, idempotencyKey }
  if (action === 'prepare-reservation-service-completion') {
    requestPayload.preparationRequestId = idempotencyKey
    window.dispatchEvent(new CustomEvent('cashier-v3:register-service-completion-request', {
      detail: { preparationRequestId: idempotencyKey, serviceOrderId: payload.serviceOrderId }
    }))
  }

  reservationActionLocks.value = { ...reservationActionLocks.value, [actionKey]: true }
  try {
    const result = await requestAction(action, requestPayload)
    if (['failed', 'conflict'].includes(resultStatus(result))) {
      reservationActionIds.value = { ...reservationActionIds.value, [actionKey]: null }
    }
    return result
  } finally {
    reservationActionLocks.value = { ...reservationActionLocks.value, [actionKey]: false }
  }
}

async function requestReservationPrimaryAction(record) {
  const primary = primaryAction(record)
  if (primary.action === 'open-reservation-detail') return openReservationDetail(reservationPayload(record))
  const approved = approvedReservationActionPayload(primary, record)
  if (!approved.valid) {
    return { result: { status: 'failed', code: 'RESERVATION_ACTION_CONTRACT_INVALID', message: approved.message } }
  }
  return requestReservationRecordAction(primary.action, approved.payload)
}

function blockStyle(block, index = 0, count = 1) {
  const startMinutes = clockMinutes(block.start) ?? 0
  const endMinutes = clockMinutes(block.end) ?? startMinutes
  const slotCount = Math.max(1, Math.ceil((endMinutes - startMinutes) / 30))
  const offsetRatio = (startMinutes % 30) / 30
  const safeCount = Math.max(1, Number(count) || 1)
  const safeIndex = Math.min(Math.max(0, Number(index) || 0), safeCount - 1)
  return {
    height: `calc((var(--reservation-slot-height) * ${slotCount}) - 8px)`,
    top: `calc(4px + (var(--reservation-slot-height) * ${offsetRatio}))`,
    right: 'auto',
    left: `calc(5px + ((100% - 10px) * ${safeIndex} / ${safeCount}))`,
    width: `calc(((100% - 10px) / ${safeCount}) - ${safeCount > 1 ? 3 : 0}px)`
  }
}

async function requestAction(action, payload = {}) {
  return requestCashierV3Action(action, payload)
}

function resultStatus(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.result?.status || response?.status || ''
}

function resultBlock(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.result && typeof response.result === 'object' ? response.result : response || {}
}

function resultCode(result) {
  return String(resultBlock(result)?.code || '')
}

function resultMessage(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.feedback?.message
    || resultBlock(result)?.message
    || response?.message
    || ''
}

function detailBelongsToReservation(detail, reservationId) {
  if (!detail || !reservationId) return false
  return String(detail.id || detail.reservationId || detail.appointmentId || '') === String(reservationId)
}

function hasOwnDetailField(source, key) {
  return Boolean(source && typeof source === 'object' && Object.prototype.hasOwnProperty.call(source, key))
}

function positiveDetailVersion(detail) {
  const version = Number(detail?.revision ?? detail?.recordVersion ?? detail?.reservationVersion)
  return Number.isSafeInteger(version) && version > 0 ? version : null
}

function hasCompleteReservationDetail(detail, reservationId) {
  if (!detailBelongsToReservation(detail, reservationId)) return false
  if (detail.detailReady !== true || positiveDetailVersion(detail) === null) return false
  if (!detail.member || typeof detail.member !== 'object' || Array.isArray(detail.member)) return false
  if (!Array.isArray(detail.projects) || !detail.projects.length) return false
  if (!detail.projects.some((line) => line?.isMain === true || ['main', '主项目'].includes(line?.role))) return false
  for (const key of ['plannedCraftsmen', 'actualCraftsmen', 'roomChanges', 'timeline', 'actions']) {
    if (!Array.isArray(detail[key])) return false
  }
  if (!hasOwnDetailField(detail, 'room') || (detail.room !== null && (typeof detail.room !== 'object' || Array.isArray(detail.room)))) return false
  for (const key of ['appointmentStartAt', 'appointmentEndAt', 'estimatedStartAt', 'estimatedEndAt', 'actualStartAt', 'actualEndAt']) {
    if (!hasOwnDetailField(detail, key)) return false
  }
  const related = detail.relatedRecords
  if (!related || typeof related !== 'object' || Array.isArray(related)) return false
  return ['hangOrders', 'serviceOrders', 'writeoffs', 'salesOrders'].every((key) => Array.isArray(related[key]))
}

function detailSummaryFromRecord(record, reservationId) {
  return {
    id: reservationId,
    reservationNo: record?.reservationNo || record?.appointmentNo || record?.no || '',
    memberName: record?.memberName || '',
    status: record?.status || record?.statusLabel || ''
  }
}

function setReservationDetailLoadError(message, code = '') {
  reservationDetail.value = {}
  reservationDetailLoadStatus.value = 'error'
  reservationDetailLoadError.value = message
  reservationDetailLoadErrorCode.value = code
  isDetailLoading.value = false
}

function operatorDetailLoadError(result) {
  const code = resultCode(result)
  if (code.startsWith('ENVELOPE_')) {
    return '预约详情返回数据没有通过安全校验，本次未提交任何预约或服务操作。请重新加载详情。'
  }
  if (code.includes('CONTEXT') || code.includes('VERSION') || code.includes('STATE_REVISION')) {
    return '账号、门店或预约数据已经更新，本次详情未采用。请重新加载详情。'
  }
  return resultMessage(result) || '预约详情暂时无法加载，本次未提交任何预约或服务操作。请重新加载详情。'
}

function activateReservationDetail(reservationId) {
  const backendDetail = reservation.value.detail
  if (!hasCompleteReservationDetail(backendDetail, reservationId)) return false
  reservationDetail.value = backendDetail
  reservationDetailLoadStatus.value = 'ready'
  reservationDetailLoadError.value = ''
  reservationDetailLoadErrorCode.value = ''
  isDetailLoading.value = false
  return true
}

function beginReservationDetailLoad(reservationId, record = null) {
  suppressedDetailReservationId.value = ''
  activeDetailReservationId.value = String(reservationId)
  reservationDetailSummary.value = detailSummaryFromRecord(record, reservationId)
  reservationDetail.value = {}
  reservationDetailLoadStatus.value = 'loading'
  reservationDetailLoadError.value = ''
  reservationDetailLoadErrorCode.value = ''
  isDetailLoading.value = true
  isDetailOpen.value = true
}

function showReservationDetail(payload = {}) {
  const detail = payload?.detail || payload || {}
  const reservationId = detail.reservationId || detail.appointmentId || detail.id
  const record = records.value.find((item) => String(item.id) === String(reservationId)) || null
  if (!reservationId) return null
  if (String(suppressedDetailReservationId.value) === String(reservationId)) {
    suppressedDetailReservationId.value = ''
    return null
  }
  if (
    isDetailOpen.value
    && activeDetailReservationId.value
    && String(activeDetailReservationId.value) !== String(reservationId)
  ) return null

  if (!isDetailOpen.value || String(activeDetailReservationId.value) !== String(reservationId)) {
    beginReservationDetailLoad(reservationId, record)
  }
  if (!activateReservationDetail(reservationId)) {
    setReservationDetailLoadError(
      '系统没有返回与本次预约匹配的完整详情。为避免把未加载的数据显示成“暂无”，请重新加载详情。',
      'RESERVATION_DETAIL_INCOMPLETE'
    )
  }
  return { record, reservationId }
}

async function openReservationDetail(payload = {}) {
  const detail = payload?.detail || payload || {}
  const reservationId = detail.reservationId || detail.appointmentId || detail.id
  const record = records.value.find((item) => String(item.id) === String(reservationId)) || null
  if (!reservationId) return { success: false, message: '未找到预约记录。' }

  const requestSequence = ++reservationDetailRequestSequence
  beginReservationDetailLoad(reservationId, record)
  try {
    const result = await requestAction('open-reservation-detail', reservationPayload(record || reservationId))
    if (requestSequence !== reservationDetailRequestSequence || String(activeDetailReservationId.value) !== String(reservationId)) {
      return result
    }
    if (['failed', 'conflict', 'result_unknown'].includes(resultStatus(result))) {
      setReservationDetailLoadError(operatorDetailLoadError(result), resultCode(result))
      return result
    }
    if (!activateReservationDetail(reservationId)) {
      setReservationDetailLoadError(
        '系统没有返回与本次预约匹配的完整详情。为避免把未加载的数据显示成“暂无”，请重新加载详情。',
        'RESERVATION_DETAIL_INCOMPLETE'
      )
    }
    return result
  } catch (error) {
    if (requestSequence === reservationDetailRequestSequence) {
      setReservationDetailLoadError('预约详情加载中断，本次未提交任何预约或服务操作。请重新加载详情。', 'RESERVATION_DETAIL_LOAD_INTERRUPTED')
    }
    return {
      result: {
        status: 'failed',
        code: 'RESERVATION_DETAIL_LOAD_INTERRUPTED',
        message: error?.message || '预约详情加载中断。'
      }
    }
  } finally {
    if (requestSequence === reservationDetailRequestSequence && reservationDetailLoadStatus.value === 'loading') {
      setReservationDetailLoadError('预约详情暂时无法加载，请重新加载详情。', 'RESERVATION_DETAIL_NOT_READY')
    }
  }
}

function reloadReservationDetail() {
  if (!activeDetailReservationId.value) return Promise.resolve({ success: false, message: '未找到需要重新加载的预约。' })
  return openReservationDetail({ reservationId: activeDetailReservationId.value })
}

function closeReservationDetail() {
  if (reservationDetailLoadStatus.value === 'loading' && activeDetailReservationId.value) {
    suppressedDetailReservationId.value = String(activeDetailReservationId.value)
  }
  reservationDetailRequestSequence += 1
  isDetailOpen.value = false
  reservationDetail.value = {}
  reservationDetailSummary.value = {}
  activeDetailReservationId.value = ''
  reservationDetailLoadStatus.value = 'idle'
  reservationDetailLoadError.value = ''
  reservationDetailLoadErrorCode.value = ''
  isDetailLoading.value = false
}

function handleOpenReservationDetail(event) {
  showReservationDetail(event)
}

async function handleReservationDetailAction(payload = {}) {
  if (payload.actionCode === 'edit-reservation') {
    const result = await openReservationEditor({
      reservationId: payload.reservationId,
      reservation: payload.reservation
    })
    if (result?.success !== false) closeReservationDetail()
    return result
  }
  const action = normalizedReservationAction(payload.actionCode || payload.action?.code || payload.action?.action)
  if (!action) return { success: false, message: '该预约操作暂未接入。' }
  if (action === 'start-reservation-service' && !hasPublicReservationVersion(payload.reservationId)) {
    return {
      result: {
        status: 'failed',
        code: 'RESERVATION_PUBLIC_VERSION_NOT_READY',
        message: '预约数据尚未同步，本次开始服务没有提交。请重新加载预约详情后再试。'
      }
    }
  }
  const record = payload.reservation || records.value.find((item) => String(item.id) === String(payload.reservationId))
  const approved = approvedReservationActionPayload(payload.action, record || payload.reservationId)
  if (!approved.valid) {
    return { result: { status: 'failed', code: 'RESERVATION_ACTION_CONTRACT_INVALID', message: approved.message } }
  }
  if (['cancel-reservation', 'reject-reservation', 'mark-reservation-no-show'].includes(action)) {
    approved.payload.reason = typeof payload.reason === 'string' ? payload.reason.trim() : ''
  }
  const result = await requestReservationRecordAction(action, approved.payload)
  if (
    resultStatus(result) === 'success'
    && action === 'start-reservation-service'
    && String(activeDetailReservationId.value) === String(payload.reservationId)
  ) {
    // 开始服务会推进预约版本并把详情动作切换为“结束服务”；只采用同一新根中的完整详情。
    if (!activateReservationDetail(payload.reservationId)) {
      setReservationDetailLoadError(
        '服务已开始，但最新预约详情尚未完整加载。请返回列表查看服务状态。',
        'RESERVATION_DETAIL_REFRESH_REQUIRED'
      )
    }
  }
  return result
}

async function selectQuickFilter(payload = {}) {
  // UnifiedQueryToolbar 会把点击当刻的完整查询状态一起交回；兼容旧事件形状，
  // 也不能让快捷筛选覆盖此前已经提交的关键词、范围或组合筛选。
  const filter = payload.filter && typeof payload.filter === 'object' ? payload.filter : payload
  const nextQuickKey = typeof filter?.key === 'string' && filter.key ? filter.key : activeQuickKey.value
  const suppliedQuery = payload.query && typeof payload.query === 'object'
    ? payload.query
    : reservationQuerySnapshot.value
  activeQuickKey.value = nextQuickKey
  await queryReservations(suppliedQuery)
}

function firstDefined(source, keys) {
  if (!source || typeof source !== 'object') return undefined
  for (const key of keys) {
    const value = source[key]
    if (value !== undefined && value !== null && String(value).trim() !== '') return value
  }
  return undefined
}

function reservationIdentity(source) {
  return firstDefined(source, ['id', 'reservationId', 'appointmentId'])
}

function reservationRecordVersion(source) {
  return firstDefined(source, ['revision', 'recordVersion', 'reservationVersion'])
}

function aliasesConflict(source, keys) {
  if (!source || typeof source !== 'object') return false
  const supplied = keys
    .filter((key) => source[key] !== undefined && source[key] !== null && String(source[key]).trim() !== '')
    .map((key) => String(source[key]))
  return new Set(supplied).size > 1
}

function cloneDraft(value = {}) {
  return JSON.parse(JSON.stringify(value && typeof value === 'object' ? value : {}))
}

function normalizeEditorDraft(value = {}) {
  const draft = cloneDraft(value)
  const identity = reservationIdentity(draft)
  const version = reservationRecordVersion(draft)
  if (identity !== undefined) {
    draft.id = identity
    draft.reservationId = identity
  }
  if (version !== undefined) {
    draft.revision = version
    draft.recordVersion = version
  }
  return draft
}

function currentStateContextId() {
  return String(state.stateContextId || '')
}

function editorPreparationToken(config = {}, draft = {}) {
  return config.preparationToken || config.editorToken || draft.preparationToken || ''
}

function editorCommandContexts(config = {}, draft = {}) {
  const contexts = Array.isArray(config.commandContexts)
    ? config.commandContexts
    : Array.isArray(draft.commandContexts)
      ? draft.commandContexts
      : []
  return contexts.map((context) => ({ ...context }))
}

function submissionBelongsToCurrentEditor(submission = {}) {
  const session = editorSession.value
  if (!session || !submission || typeof submission !== 'object') return false
  if (submission.stateContextId && String(submission.stateContextId) !== String(session.stateContextId)) return false
  if (
    submission.preparationRequestId
    && String(submission.preparationRequestId) !== String(session.preparationRequestId)
  ) return false
  const submittedReservationId = reservationIdentity(submission)
  if (
    submittedReservationId !== undefined
    && session.reservationId !== null
    && String(submittedReservationId) !== String(session.reservationId)
  ) return false
  const submittedCommandId = submission.originalIdempotencyKey || submission.idempotencyKey || submission.commandId
  if (submittedCommandId && session.commandId && String(submittedCommandId) !== String(session.commandId)) return false

  // 全局 submission 必须至少能用准备请求或原命令键关联到当前编辑会话。
  return Boolean(
    (submission.preparationRequestId && String(submission.preparationRequestId) === String(session.preparationRequestId))
    || (submittedCommandId && session.commandId && String(submittedCommandId) === String(session.commandId))
  )
}

function hasOwn(record, key) {
  return Object.prototype.hasOwnProperty.call(record, key)
}

function isCompleteReservationEditorDraft(config, draft, reservationId, preparationRequestId) {
  if (!config || typeof config !== 'object' || !draft || typeof draft !== 'object') return false
  if (config.preparationReady !== true && config.draftReady !== true) return false
  if (String(config.preparationRequestId || '') !== String(preparationRequestId)) return false
  if (!editorPreparationToken(config, draft)) return false
  if (!currentStateContextId()) return false
  if (aliasesConflict(draft, ['id', 'reservationId', 'appointmentId'])) return false
  if (aliasesConflict(draft, ['revision', 'recordVersion', 'reservationVersion'])) return false
  if (!Array.isArray(config.catalogOptions) || !Array.isArray(config.craftsmenOptions) || !Array.isArray(config.rooms)) return false
  if (!Array.isArray(draft.projects) && !Array.isArray(draft.projectLines)) return false
  if (!Array.isArray(draft.craftsmen)) return false
  if (!hasOwn(draft, 'member') && !hasOwn(draft, 'memberId')) return false
  if (!hasOwn(draft, 'appointmentTime') && !hasOwn(draft, 'appointmentStartAt')) return false
  if (!hasOwn(draft, 'room') && !hasOwn(draft, 'roomId')) return false
  if (!hasOwn(draft, 'remark')) return false
  if (reservationId) {
    if (String(reservationIdentity(draft) || '') !== String(reservationId)) return false
    if (reservationRecordVersion(draft) === undefined) return false
  }
  return true
}

function activatePreparedReservationEditor(reservationId, preparationRequestId, preparedEditor = null) {
  if (
    !editorSession.value
    || String(editorSession.value.preparationRequestId) !== String(preparationRequestId)
    || String(editorPreparationRequestId.value) !== String(preparationRequestId)
  ) {
    return { success: false, status: 'superseded', message: '该预约编辑准备结果已失效。' }
  }
  if (editorSession.value.stateContextId !== currentStateContextId()) {
    return { success: false, status: 'superseded', message: '账号或门店已经切换，该预约编辑结果已失效。' }
  }

  const config = preparedEditor && typeof preparedEditor === 'object'
    ? preparedEditor
    : (reservation.value.editor || {})
  const draft = config.draft
  if (!isCompleteReservationEditorDraft(config, draft, reservationId, preparationRequestId)) {
    return { success: false, status: 'blocked', message: '预约编辑内容尚未完整加载，请刷新后重试。' }
  }

  const normalizedDraft = normalizeEditorDraft(draft)
  const preparedReservationId = reservationIdentity(normalizedDraft) ?? null
  const preparedRecordVersion = reservationRecordVersion(normalizedDraft) ?? null
  editorSession.value = Object.freeze({
    stateContextId: currentStateContextId(),
    preparationRequestId,
    preparationToken: editorPreparationToken(config, normalizedDraft),
    reservationId: preparedReservationId,
    recordVersion: preparedRecordVersion,
    commandContexts: editorCommandContexts(config, normalizedDraft),
    commandId: null
  })
  preparedEditorConfig.value = config
  editorDraft.value = normalizedDraft
  editorSubmitCommandId.value = null
  editorSubmissionStatus.value = ''
  editorSubmissionResult.value = {}
  editorLoadMessage.value = ''
  isEditorOpen.value = true
  return { success: true }
}

async function openReservationEditor(payload = {}) {
  const detail = payload?.detail || payload
  const record = detail.reservation
    || (detail.reservationId ? records.value.find((item) => item.id === detail.reservationId) : null)
  const reservationId = detail.reservationId || reservationIdentity(record) || null
  const recordVersion = reservationRecordVersion(record)
  const preparationRequestId = createCashierV3CommandId('RESERVATION_EDITOR')
  editorPreparationRequestId.value = preparationRequestId
  preparedEditorConfig.value = null
  editorSession.value = Object.freeze({
    stateContextId: currentStateContextId(),
    preparationRequestId,
    preparationToken: '',
    reservationId,
    recordVersion: recordVersion ?? null,
    commandContexts: [],
    commandId: null
  })
  editorLoadMessage.value = ''
  isEditorLoading.value = true

  try {
    const result = await requestAction('open-reservation-editor', {
      mode: reservationId ? 'edit' : 'create',
      reservationId,
      recordVersion,
      preparationRequestId
    })
    if (editorPreparationRequestId.value !== preparationRequestId) {
      return { success: false, status: 'superseded', message: '较新的预约编辑请求已经发起。' }
    }
    if (['failed', 'conflict'].includes(actionStatus(result))) {
      editorLoadMessage.value = actionMessage(result) || '暂时无法加载预约编辑内容。'
      return { success: false, status: actionStatus(result), message: editorLoadMessage.value }
    }

    const preparedEditor = preparedReservationEditorFrom(result)
    const activation = activatePreparedReservationEditor(reservationId, preparationRequestId, preparedEditor)
    if (!activation.success) editorLoadMessage.value = activation.message
    return activation
  } catch (error) {
    if (editorPreparationRequestId.value === preparationRequestId) {
      editorLoadMessage.value = error?.message || '暂时无法加载预约编辑内容。'
    }
    return { success: false, status: 'failed', message: editorLoadMessage.value }
  } finally {
    if (editorPreparationRequestId.value === preparationRequestId) {
      isEditorLoading.value = false
    }
  }
}

function handleReservationEditorPrepared(event) {
  const detail = event?.detail || {}
  const config = reservation.value.editor || {}
  const preparationRequestId = detail.preparationRequestId || config.preparationRequestId
  if (
    !preparationRequestId
    || !editorSession.value
    || String(preparationRequestId) !== String(editorSession.value.preparationRequestId)
  ) return
  const reservationId = editorSession.value.reservationId
  const activation = activatePreparedReservationEditor(reservationId, preparationRequestId)
  if (!activation.success && activation.status !== 'superseded') {
    editorLoadMessage.value = activation.message
  }
}

function actionStatus(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.result?.status || response?.status || ''
}

function actionMessage(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.result?.message || response?.message || ''
}

function actionResponse(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.result && typeof response.result === 'object' ? response.result : response || {}
}

// HTTP adapter returns { status, data: <V3 envelope> }, while the preview
// adapter returns the envelope directly. Keep the editor payload lookup at
// this one boundary so a valid response is not shown as an incomplete editor.
function preparedReservationEditorFrom(result) {
  // 本地 HTTP 适配器会保留传输层 data，再保留 V3 信封 data，最后才是
  // 领域投影 data.editor。旧实现只检查固定两层，导致后端已返回的项目、
  // 手艺人与房间候选被当作空编辑器。只沿标准 data 信封逐层解包，避免
  // 误把其他 UI 字段当成预约编辑器。
  let candidate = result
  for (let depth = 0; candidate && depth < 6; depth += 1) {
    if (candidate.editor && typeof candidate.editor === 'object') return candidate.editor
    candidate = candidate.data && typeof candidate.data === 'object' ? candidate.data : null
  }
  return null
}

function normalizeSubmissionStatus(value) {
  const status = String(value || '').trim().toLowerCase()
  if (['processing', 'submitting', '处理中', '提交中'].includes(status)) return 'processing'
  if (['pending', 'result_pending', 'pending_confirmation', 'confirming', '结果确认中', '待确认'].includes(status)) {
    return 'pending_confirmation'
  }
  if (['result_unknown', 'unknown', '结果未知', '未知'].includes(status)) return 'result_unknown'
  if (['failed', 'failure', 'error', 'conflict', '失败', '冲突'].includes(status)) return 'failed'
  if (['succeeded', 'success', 'completed', '已完成', '成功'].includes(status)) return 'succeeded'
  if (!status || status === 'editing') return 'editing'
  return 'result_unknown'
}

async function selectEditorMember() {
  if (resolvePendingMemberSelection) return null
  // 先完成只读的编辑器准备请求，再打开全局会员选择器。此前二者并行，
  // 操作人员极快选择会员时，较晚返回的准备响应会与选择命令交错，造成
  // “响应绑定不一致”的误提示；准备失败时也不应打开一个不可用的选择器。
  try {
    const result = await requestAction('open-reservation-member-selector', {
      reservationId: reservationIdentity(editorDraft.value),
      recordVersion: reservationRecordVersion(editorDraft.value),
      preparationRequestId: editorSession.value?.preparationRequestId
    })
    if (['failed', 'conflict', 'result_unknown'].includes(actionStatus(result))) return null
  } catch (error) {
    return null
  }
  return new Promise((resolve) => {
    resolvePendingMemberSelection = resolve
    window.dispatchEvent(new CustomEvent('cashier-v3:open-member-selector', {
      detail: { context: 'reservation' }
    }))
  })
}

async function selectEditorCraftsmen(payload = {}) {
  const result = await openCashierV3QueryEntitySelector({
    entityType: 'person',
    title: '选择手艺人',
    description: '仅显示当前门店可预约的在职手艺人；最终可约时段与冲突由保存时再次确认。',
    multiple: true,
    selectedRecords: Array.isArray(payload.craftsmen) ? payload.craftsmen : [],
    selectionContext: {
      scope: 'reservation_craftsmen',
      reservationId: reservationIdentity(editorDraft.value) || null,
      serviceStartAt: editorDraft.value.appointmentTime || editorDraft.value.appointmentStartAt || null
    }
  })
  if (!Array.isArray(result?.selected)) return null
  return { craftsmen: result.selected }
}

async function recalculateReservationPlan({ draft, changeReason, recalculationRequestId } = {}) {
  const session = editorSession.value
  if (
    !session
    || session.stateContextId !== currentStateContextId()
    || !recalculationRequestId
  ) {
    return { success: false, message: '当前预约编辑会话已失效，请重新打开后再操作。' }
  }
  const result = await requestAction('recalculate-reservation-plan', {
    reservationId: session.reservationId,
    recordVersion: session.recordVersion,
    preparationRequestId: session.preparationRequestId,
    preparationToken: session.preparationToken,
    recalculationRequestId,
    changeReason,
    reservation: normalizeEditorDraft(draft)
  })
  if (
    !editorSession.value
    || editorSession.value.preparationRequestId !== session.preparationRequestId
    || editorSession.value.stateContextId !== currentStateContextId()
  ) {
    return { success: false, message: '该计算结果已被较新的预约编辑会话取代。' }
  }
  if (!['success', 'succeeded'].includes(actionStatus(result))) {
    return { success: false, message: actionMessage(result) || '系统未能完成预约时长与冲突检查。' }
  }

  const envelope = result?.data && typeof result.data === 'object' ? result.data : result || {}
  const calculation = envelope?.data?.reservationPlan
    || envelope?.data?.calculation
    || envelope?.reservationPlan
    || envelope?.calculation
    || reservation.value.editor?.recalculation
    || {}
  const calculatedDraft = calculation.draft
    || calculation.reservationDraft
    || (
      reservation.value.editor?.draft?.scheduleCalculationReady === true
        ? reservation.value.editor.draft
        : null
    )
  const responseRequestId = calculation.recalculationRequestId
    || calculatedDraft?.scheduleRecalculationRequestId
    || calculatedDraft?.recalculationRequestId
  if (
    !calculatedDraft
    || String(responseRequestId || '') !== String(recalculationRequestId)
    || calculatedDraft.scheduleCalculationReady !== true
  ) {
    return { success: false, message: '系统未返回与本次修改匹配的完整时长和冲突检查结果。' }
  }
  return { success: true, draft: normalizeEditorDraft(calculatedDraft) }
}

async function submitReservationEditor(draft) {
  const session = editorSession.value
  if (!session || session.stateContextId !== currentStateContextId()) {
    return { success: false, status: 'blocked', message: '账号或门店已经切换，请重新打开预约后再保存。' }
  }
  const normalizedDraft = normalizeEditorDraft(draft)
  const draftReservationId = reservationIdentity(normalizedDraft) ?? null
  const draftRecordVersion = reservationRecordVersion(normalizedDraft) ?? null
  if (
    (session.reservationId !== null && String(draftReservationId) !== String(session.reservationId))
    || (session.reservationId !== null && String(draftRecordVersion) !== String(session.recordVersion))
  ) {
    return { success: false, status: 'blocked', message: '预约对象或版本与当前编辑会话不一致，请重新打开后再保存。' }
  }
  const currentStatus = normalizeSubmissionStatus(editorSubmission.value.status)
  const canRetry = currentStatus === 'failed' && editorSubmission.value.canRetry === true
  if (isEditorSubmitting.value || (currentStatus !== 'editing' && !canRetry)) {
    return { success: false, status: 'blocked', message: '当前预约请求尚未结束，请勿重复提交。' }
  }
  isEditorSubmitting.value = true
  if (!editorSubmitCommandId.value) {
    editorSubmitCommandId.value = createCashierV3CommandId('RESERVATION')
  }
  const commandId = editorSubmitCommandId.value
  editorSession.value = Object.freeze({ ...session, commandId })
  editorSubmissionStatus.value = 'processing'
  editorSubmissionResult.value = {}
  try {
    const isExistingReservation = session.reservationId !== null
    const result = await requestAction(isExistingReservation ? 'update-reservation' : 'create-reservation', {
      reservationId: session.reservationId,
      recordVersion: session.recordVersion,
      reservation: normalizedDraft,
      preparationRequestId: session.preparationRequestId,
      preparationToken: session.preparationToken,
      commandContexts: session.commandContexts,
      idempotencyKey: commandId
    })
    if (!editorSession.value || editorSession.value.commandId !== commandId) {
      return { success: false, status: 'superseded', message: '该预约提交结果已被较新的编辑会话取代。' }
    }
    const response = actionResponse(result)
    const status = normalizeSubmissionStatus(response.status)
    editorSubmissionResult.value = { ...response }
    editorSubmissionStatus.value = status === 'editing' ? 'result_unknown' : status
    return result
  } catch (error) {
    editorSubmissionStatus.value = 'result_unknown'
    editorSubmissionResult.value = {
      message: error?.message || '网络中断，未能确认预约结果。请查询原请求，禁止重新提交。'
    }
    throw error
  } finally {
    isEditorSubmitting.value = false
  }
}

function handleEditorSubmitted(result) {
  if (normalizeSubmissionStatus(actionResponse(result).status) !== 'succeeded') return
  closeReservationEditor()
}

async function queryReservationEditorResult(command = {}) {
  const session = editorSession.value
  const originalIdempotencyKey = command.originalIdempotencyKey
  if (!session || session.stateContextId !== currentStateContextId() || !originalIdempotencyKey) {
    return { success: false, status: 'blocked', message: '未找到原预约请求标识，请勿重新提交。' }
  }
  const { queryAction: ignoredQueryAction, idempotencyKey: ignoredIdempotencyKey, ...payload } = command
  const result = await requestAction('query-reservation-result', {
    ...payload,
    originalIdempotencyKey,
    preparationRequestId: session.preparationRequestId,
    reservationId: session.reservationId,
    recordVersion: session.recordVersion
  })
  if (!editorSession.value || editorSession.value.commandId !== session.commandId) {
    return { success: false, status: 'superseded', message: '该预约查询结果已被较新的编辑会话取代。' }
  }
  const response = actionResponse(result)
  const status = normalizeSubmissionStatus(response.status)
  editorSubmissionResult.value = { ...editorSubmissionResult.value, ...response }
  editorSubmissionStatus.value = status === 'editing' ? 'result_unknown' : status
  if (editorSubmissionStatus.value === 'succeeded') {
    closeReservationEditor()
  }
  return result
}

function closeReservationEditor() {
  // 编辑器关闭即放弃尚未完成的会员选择；否则旧 Promise 会占住下一次
  // “选择会员”操作，使重新打开的预约编辑器看起来没有反应。
  if (resolvePendingMemberSelection) {
    const resolve = resolvePendingMemberSelection
    resolvePendingMemberSelection = null
    resolve(null)
  }
  isEditorOpen.value = false
  isEditorLoading.value = false
  editorLoadMessage.value = ''
  editorDraft.value = {}
  preparedEditorConfig.value = null
  editorSubmitCommandId.value = null
  editorPreparationRequestId.value = null
  editorSession.value = null
  editorSubmissionStatus.value = ''
  editorSubmissionResult.value = {}
}

function resetReservationLocalContext() {
  closeReservationEditor()
  closeReservationDetail()
  reservationActionLocks.value = {}
  reservationActionIds.value = {}
  if (resolvePendingMemberSelection) {
    resolvePendingMemberSelection(null)
    resolvePendingMemberSelection = null
  }
}

function handleMemberSelected(event) {
  const detail = event.detail || {}
  if (detail.context !== 'reservation' || !resolvePendingMemberSelection) return
  const resolve = resolvePendingMemberSelection
  resolvePendingMemberSelection = null
  resolve(detail.record || null)
}

function handleMemberSelectorClosed(event) {
  const detail = event.detail || {}
  if (detail.context !== 'reservation' || !resolvePendingMemberSelection) return
  const resolve = resolvePendingMemberSelection
  resolvePendingMemberSelection = null
  resolve(null)
}

onMounted(() => {
  window.addEventListener('cashier-v3:open-reservation-editor', openReservationEditor)
  window.addEventListener('cashier-v3:reservation-editor-prepared', handleReservationEditorPrepared)
  window.addEventListener('cashier-v3:open-reservation-detail', handleOpenReservationDetail)
  window.addEventListener('cashier-v3:member-selector-selected', handleMemberSelected)
  window.addEventListener('cashier-v3:member-selector-closed', handleMemberSelectorClosed)
  window.addEventListener('cashier-v3:state-context-changing', resetReservationLocalContext)
  window.addEventListener('cashier-v3:state-context-changed', resetReservationLocalContext)
})

onBeforeUnmount(() => {
  window.removeEventListener('cashier-v3:open-reservation-editor', openReservationEditor)
  window.removeEventListener('cashier-v3:reservation-editor-prepared', handleReservationEditorPrepared)
  window.removeEventListener('cashier-v3:open-reservation-detail', handleOpenReservationDetail)
  window.removeEventListener('cashier-v3:member-selector-selected', handleMemberSelected)
  window.removeEventListener('cashier-v3:member-selector-closed', handleMemberSelectorClosed)
  window.removeEventListener('cashier-v3:state-context-changing', resetReservationLocalContext)
  window.removeEventListener('cashier-v3:state-context-changed', resetReservationLocalContext)
  if (resolvePendingMemberSelection) {
    resolvePendingMemberSelection(null)
    resolvePendingMemberSelection = null
  }
})
</script>

<template>
  <section class="reservation-page" :class="{ 'reservation-page--list': viewMode === 'list' }" aria-label="预约列表">
    <header class="reservation-page__toolbar">
      <UnifiedQueryToolbar
        search-placeholder="搜索会员姓名、手机号、预约单号或项目"
        :quick-filters="quickFilters"
        :status-options="reservationStatusOptions"
        :fields="queryFields"
        default-sort-field="预约时间"
        lock-store-selector
        :current-store="state.currentStore"
        :settings="appliedQuerySettings || reservation.querySettings || {}"
        @query="queryReservations"
        @quick-filter="selectQuickFilter"
        :on-save-settings="(settings) => requestAction('save-reservation-query-settings', { settings })"
        @settings-applied="applyQuerySettings"
      >
        <template #primary-actions>
          <div class="reservation-page__view-switch" aria-label="预约视图">
            <button type="button" :aria-pressed="viewMode === 'list'" :class="{ 'reservation-page__view-switch--active': viewMode === 'list' }" @click="viewMode = 'list'">列表</button>
            <button type="button" :aria-pressed="viewMode === 'calendar'" :class="{ 'reservation-page__view-switch--active': viewMode === 'calendar' }" @click="viewMode = 'calendar'">日历</button>
          </div>
        </template>
      </UnifiedQueryToolbar>
      <p v-if="isEditorLoading || editorLoadMessage" class="reservation-editor-load-message" :class="{ 'reservation-editor-load-message--error': editorLoadMessage }">
        {{ editorLoadMessage || '正在加载完整预约编辑内容…' }}
      </p>
    </header>

    <template v-if="viewMode === 'list'">
      <main
        class="reservation-list-wrap"
        role="region"
        aria-label="预约记录表格"
        aria-describedby="reservation-list-scroll-hint"
        tabindex="0"
      >
      <p id="reservation-list-scroll-hint" class="reservation-list-scroll-hint">
        表格可左右滚动查看全部字段；预约记录号和操作列会固定显示。
      </p>
      <table
        class="reservation-list-table"
        :style="{ '--reservation-column-count': visibleReservationFields.length }"
      >
        <caption class="sr-only">预约记录列表。表格可左右滚动查看全部字段，预约记录号和操作列固定显示。</caption>
        <thead>
          <tr>
            <th v-for="field in visibleReservationFields" :key="field.key">{{ field.label }}</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="record in records" :key="record.id">
            <td v-for="field in visibleReservationFields" :key="field.key">
              <button
                v-if="field.key === 'reservation_no'"
                type="button"
                class="reservation-link"
                @click="openReservationDetail(reservationPayload(record))"
              >
                {{ record.reservationNo || '—' }}
              </button>
              <strong v-else-if="field.key === 'member_name'">{{ reservationFieldValue(record, field.key) }}</strong>
              <strong v-else-if="field.key === 'project'">{{ reservationFieldValue(record, field.key) }}</strong>
              <span v-else-if="field.key === 'status'" class="reservation-status" :class="statusClass(record.status)">{{ record.status || '—' }}</span>
              <template v-else>{{ reservationFieldValue(record, field.key) }}</template>
            </td>
            <td>
              <div class="reservation-row-actions">
                <button
                  type="button"
                  class="button reservation-row-action"
                  :class="{ 'reservation-row-action--primary': primaryAction(record).action !== 'open-reservation-detail' }"
                  :disabled="isReservationActionPending(primaryAction(record).action, record)"
                  :title="primaryAction(record).disabledReason || ''"
                  :aria-label="`${primaryAction(record).label}，预约 ${record.reservationNo || record.id}，${record.memberName || '未命名会员'}`"
                  @click="requestReservationPrimaryAction(record)"
                >{{ isReservationActionPending(primaryAction(record).action, record) ? '正在处理…' : primaryAction(record).label }}</button>
                <button
                  type="button"
                  class="button button--text"
                  :aria-label="`更多操作，预约 ${record.reservationNo || record.id}，${record.memberName || '未命名会员'}`"
                  @click="requestAction('open-reservation-more-actions', reservationPayload(record))"
                >更多</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="!records.length" class="reservation-empty">暂无符合条件的预约记录。</div>
      </main>

      <TablePagination
        :total="total"
        :page="page"
        :page-size="pageSize"
        @change="(pagination) => queryReservations({ ...reservationQuerySnapshot, ...pagination }, false)"
      />
    </template>

    <main v-else class="reservation-calendar-wrap">
      <header class="reservation-calendar-toolbar">
        <div class="reservation-calendar-toolbar__date">
          <button type="button" class="button button--secondary" @click="changeReservationCalendarDate(-1)">上一天</button>
          <button type="button" class="button button--secondary" @click="changeReservationCalendarDate(0)">今天</button>
          <button type="button" class="button button--secondary" @click="changeReservationCalendarDate(1)">下一天</button>
          <strong>{{ calendar.date }}</strong>
        </div>
        <div class="reservation-calendar-toolbar__mode">
          <button type="button" :class="{ 'reservation-calendar-toolbar__mode--active': calendarResourceMode === 'staff' }" @click="calendarResourceMode = 'staff'">按手艺人</button>
          <button type="button" :class="{ 'reservation-calendar-toolbar__mode--active': calendarResourceMode === 'room' }" @click="calendarResourceMode = 'room'">按房间</button>
        </div>
      </header>

      <div class="reservation-calendar" :style="calendarStyle">
        <div class="reservation-calendar__header">
          <span>时间</span>
          <span v-for="resource in calendarResources" :key="resource.id">{{ resource.name }}</span>
        </div>
        <div v-for="time in timeSlots" :key="time" class="reservation-calendar__row">
          <span>{{ time }}</span>
          <div v-for="resource in calendarResources" :key="`${time}-${resource.id}`" class="reservation-calendar__cell">
            <button
              v-for="(block, blockIndex) in blocksForResource(resource.id, time)"
              :key="block.id"
              type="button"
              class="reservation-calendar-block"
              :style="blockStyle(block, blockIndex, blocksForResource(resource.id, time).length)"
              @click="openReservationDetail(reservationPayload(block.reservationId || block.id))"
            >
              <strong>{{ block.memberName }}</strong>
              <span>{{ block.start }}–{{ block.end }} · {{ block.projectCount }}项</span>
              <span>{{ block.status }} · {{ block.roomName }}</span>
            </button>
          </div>
        </div>
        <div v-if="!timeSlots.length" class="reservation-empty">营业时段尚未由后端加载，日历不会自行补造默认时间。</div>
      </div>
    </main>

    <ReservationEditorOverlay
      v-if="isEditorOpen"
      v-model="editorDraft"
      :catalog-options="editorCatalogOptions"
      :craftsmen-options="editorCraftsmenOptions"
      :rooms="editorRooms"
      :is-submitting="isEditorSubmitting"
      :submission="editorSubmission"
      :on-select-member="selectEditorMember"
      :on-recalculate="recalculateReservationPlan"
      :on-submit="submitReservationEditor"
      :on-query="queryReservationEditorResult"
      @close="closeReservationEditor"
      @submitted="handleEditorSubmitted"
    />

    <ReservationDetailOverlay
      v-if="isDetailOpen"
      :detail="reservationDetail"
      :summary="reservationDetailSummary"
      :is-loading="isDetailLoading"
      :load-status="reservationDetailLoadStatus"
      :load-error="reservationDetailLoadError"
      :load-error-code="reservationDetailLoadErrorCode"
      :can-start-service="canStartServiceFromDetail"
      :on-reload="reloadReservationDetail"
      :on-action="handleReservationDetailAction"
      @close="closeReservationDetail"
    />
  </section>
</template>
