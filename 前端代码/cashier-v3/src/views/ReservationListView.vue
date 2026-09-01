<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
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
const router = useRouter()
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
const editorConfirmationMode = ref(false)
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
  const seen = new Set()
  return reservation.value.statusOptions
    .map((option) => {
      if (typeof option === 'string' && option.trim()) {
        const normalized = normalizeReservationStatus(option.trim())
        return { value: normalized.value, label: normalized.label, normal: false }
      }
      if (!option || typeof option !== 'object') return null
      const value = option.value ?? option.code ?? option.status
      if (value === undefined || value === null || String(value).trim() === '') return null
      const normalized = normalizeReservationStatus(value || option.label || option.name)
      return {
        ...option,
        value: normalized.value,
        label: normalized.label,
        normal: option.normal === true || option.isNormal === true
      }
    })
    .filter((option) => {
      if (!option || seen.has(option.value)) return false
      seen.add(option.value)
      return true
    })
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
    editorSubmissionResult.value = status === 'failed'
      ? {
          ...editorSubmissionResult.value,
          ...submission,
          status: 'failed',
          canClose: true,
          canRetry: true,
          message: submission.message || submission.failureReason || '保存未完成，请重试。'
        }
      : { ...editorSubmissionResult.value, ...submission }
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
  if (fieldKey === 'status') return reservationStatusLabel(record)
  return values[fieldKey] || record[fieldKey] || '—'
}

const quickFilters = computed(() => [
  { key: 'today', label: '今日预约', count: quickCounts.value.today, active: activeQuickKey.value === 'today' },
  { key: 'pending_confirmation', label: '待确认', count: quickCounts.value.pendingConfirmation ?? quickCounts.value.pending_confirmation, active: activeQuickKey.value === 'pending_confirmation' },
  { key: 'unstarted', label: '未开始', count: quickCounts.value.unstarted, active: activeQuickKey.value === 'unstarted' },
  { key: 'serving', label: '服务中', count: quickCounts.value.serving, active: activeQuickKey.value === 'serving' },
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
  'end-service': 'end-reservation-service',
  'delete-reservation': 'cancel-reservation'
}

const ALLOWED_RESERVATION_RECORD_ACTIONS = new Set([
  'open-reservation-detail',
  'edit-reservation',
  'confirm-reservation',
  'reject-reservation',
  'start-reservation-service',
  'end-reservation-service',
  'cancel-reservation'
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

function normalizeReservationStatus(rawStatus) {
  const raw = String(rawStatus || '').trim()
  const normalized = raw.toUpperCase().replace(/[\s-]/g, '_')
  if (['待确认'].includes(raw) || ['PENDING_CONFIRMATION'].includes(normalized)) {
    return { value: 'PENDING_CONFIRMATION', label: '待确认', phase: 'pending' }
  }
  if (['未开始', '待服务', '已预约'].includes(raw) || ['UNSTARTED', 'SCHEDULED', 'CONFIRMED'].includes(normalized)) {
    return { value: 'UNSTARTED', label: '待服务', phase: 'unstarted' }
  }
  if (['SERVING', 'IN_SERVICE', 'SERVICE_IN_PROGRESS', '服务中', '进行中'].includes(raw) || ['SERVING', 'IN_SERVICE', 'SERVICE_IN_PROGRESS'].includes(normalized)) {
    return { value: 'SERVING', label: '服务中', phase: 'serving' }
  }
  if (['REJECTED', '已拒绝'].includes(raw) || normalized === 'REJECTED') {
    return { value: 'REJECTED', label: '已拒绝', phase: 'rejected' }
  }
  if (['CANCELLED', 'CANCELED', '已取消'].includes(raw) || ['CANCELLED', 'CANCELED'].includes(normalized)) {
    return { value: 'CANCELLED', label: '已取消', phase: 'ended' }
  }
  return { value: 'COMPLETED', label: '已结束', phase: 'ended' }
}

function reservationStatus(record) {
  return normalizeReservationStatus(record?.status || record?.statusLabel || record?.statusName)
}

function reservationStatusLabel(record) {
  return reservationStatus(record).label
}

function statusClass(record) {
  return `reservation-status--${reservationStatus(record).phase}`
}

function recordActions(record) {
  const phase = reservationStatus(record).phase
  if (phase === 'pending') {
    // 拒绝必须填写原因，统一进详情完成确认，避免列表快捷操作绕过必填条件。
    return [{ action: 'open-reservation-detail', label: '处理确认', primary: true }]
  }
  if (phase === 'unstarted') {
    return [
      { action: 'edit-reservation', label: '编辑' },
      { action: 'cancel-reservation', label: '删除' },
      { action: 'start-reservation-service', label: '开始服务', primary: true }
    ]
  }
  if (phase === 'serving') return [{ action: 'end-reservation-service', label: '结束服务', primary: true }]
  return []
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

  const idempotencyKey = reservationActionIds.value[actionKey] || createCashierV3CommandId('RESERVATION_ACTION')
  reservationActionIds.value = { ...reservationActionIds.value, [actionKey]: idempotencyKey }
  const requestPayload = { ...payload, idempotencyKey }

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

async function requestReservationRecordButton(record, action) {
  if (action === 'open-reservation-detail') {
    return openReservationDetail(reservationPayload(record))
  }
  if (action === 'edit-reservation') {
    return openReservationEditor({ reservationId: reservationPayload(record).reservationId, reservation: record })
  }
  const approved = approvedReservationActionPayload({ action }, record)
  if (!approved.valid) {
    return { result: { status: 'failed', code: 'RESERVATION_ACTION_CONTRACT_INVALID', message: approved.message } }
  }
  return requestReservationRecordAction(action, approved.payload)
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
  // 预约保存是普通资料落单。页面自行处理保存成功或失败，不让通用结账
  // 回执恢复弹窗打断操作人员。
  const isReservationSave = ['create-reservation', 'update-reservation'].includes(action)
  return requestCashierV3Action(action, isReservationSave ? { ...payload, silent: true } : payload)
}

function resultStatus(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.result?.status || response?.status || ''
}

function resultSucceeded(result) {
  return ['success', 'succeeded'].includes(String(resultStatus(result)).toLowerCase())
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

function resultData(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.data && typeof response.data === 'object' ? response.data : {}
}

function reservationDetailFromResult(result) {
  const detail = resultData(result)?.reservation?.detail
  return detail && typeof detail === 'object' && !Array.isArray(detail) ? detail : null
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

function activateReservationDetail(reservationId, responseDetail = null) {
  // 预约详情是独立详情投影；列表根投影会刻意把 detail 置空，不能只从根状态读取。
  const backendDetail = responseDetail || reservation.value.detail
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
    if (!activateReservationDetail(reservationId, reservationDetailFromResult(result))) {
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
  const payload = event?.detail || event || {}
  const reservationId = payload.reservationId || payload.appointmentId || payload.id
  if (!reservationId) return
  openReservationDetail({ reservationId })
}

async function handleReservationDetailAction(payload = {}) {
  if (payload.actionCode === 'go-to-cashier') {
    const reservation = payload.reservation && typeof payload.reservation === 'object' ? payload.reservation : {}
    const member = reservation.member && typeof reservation.member === 'object'
      ? reservation.member
      : reservation.memberInfo && typeof reservation.memberInfo === 'object'
        ? reservation.memberInfo
        : reservation.customer && typeof reservation.customer === 'object'
          ? reservation.customer
          : {}
    const memberId = firstDefined(member, ['id', 'memberId', 'uid', 'userId'])
      ?? firstDefined(reservation, ['memberId', 'member_id', 'uid', 'userId', 'customerId'])
    if (!memberId) {
      return { result: { status: 'failed', code: 'RESERVATION_MEMBER_MISSING', message: '该预约没有可用的会员资料，暂不能去开单。' } }
    }
    closeReservationDetail()
    await router.push({ name: 'cashier-v3-cashier' })
    window.dispatchEvent(new CustomEvent('cashier-v3:open-cashier-member', {
      detail: {
        memberId: String(memberId),
        member: { ...member, id: member.id || member.memberId || member.uid || member.userId || memberId }
      }
    }))
    return { result: { status: 'success', message: '已进入收银并打开会员来源选择。' } }
  }
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
  if (action === 'confirm-reservation') {
    const result = await openReservationEditor({
      reservationId: payload.reservationId,
      reservation: payload.reservation,
      confirmationMode: true
    })
    if (result?.success !== false) closeReservationDetail()
    return result
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
    resultSucceeded(result)
    && ['confirm-reservation', 'start-reservation-service', 'end-reservation-service'].includes(action)
    && String(activeDetailReservationId.value) === String(payload.reservationId)
  ) {
    // 服务状态已变更后重新读取该预约的完整详情，保留详情窗并切换可用操作。
    await openReservationDetail({ reservationId: payload.reservationId })
  }
  if (resultSucceeded(result) && ['reject-reservation', 'cancel-reservation'].includes(action)) closeReservationDetail()
  if (resultSucceeded(result)) await queryReservations(reservationQuerySnapshot.value, false)
  return result
}

async function selectQuickFilter(payload = {}) {
  // UnifiedQueryToolbar 会把点击当刻的完整查询状态一起交回；兼容旧事件形状，
  // 也不能让快捷筛选覆盖此前已经提交的关键词、范围或组合筛选。
  const filter = payload.filter && typeof payload.filter === 'object' ? payload.filter : payload
  const selectedKey = typeof filter?.key === 'string' && filter.key ? filter.key : activeQuickKey.value
  const nextQuickKey = selectedKey === 'pending' ? 'pending_confirmation' : selectedKey
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
  editorConfirmationMode.value = detail.confirmationMode === true
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
  if (['pending', 'result_pending', 'pending_confirmation', 'confirming', '结果确认中', '待确认', 'result_unknown', 'unknown', '结果未知', '未知'].includes(status)) return 'failed'
  if (['failed', 'failure', 'error', 'conflict', '失败', '冲突'].includes(status)) return 'failed'
  if (['succeeded', 'success', 'completed', '已完成', '成功'].includes(status)) return 'succeeded'
  if (!status || status === 'editing') return 'editing'
  return 'failed'
}

async function selectEditorMember() {
  if (resolvePendingMemberSelection) return null
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

async function refreshReservationProjectCatalog({ memberId } = {}) {
  const normalizedMemberId = Number(memberId)
  if (!Number.isSafeInteger(normalizedMemberId) || normalizedMemberId <= 0) {
    return { catalogOptions: [] }
  }
  const result = await requestAction('query-reservation-project-catalog', { memberId: normalizedMemberId })
  if (!['success', 'succeeded'].includes(actionStatus(result))) {
    return { catalogOptions: [] }
  }
  const envelope = result?.data && typeof result.data === 'object' ? result.data : result || {}
  const catalogOptions = envelope?.data?.reservationProjectCatalog?.catalogOptions
    || envelope?.reservationProjectCatalog?.catalogOptions
    || envelope?.data?.editor?.catalogOptions
    || []
  return { catalogOptions: Array.isArray(catalogOptions) ? catalogOptions : [] }
}

async function submitReservationEditor(draft) {
  const session = editorSession.value
  if (!session) return { success: false, status: 'failed', message: '保存未完成，请重试。' }
  const normalizedDraft = normalizeEditorDraft(draft)
  const currentStatus = normalizeSubmissionStatus(editorSubmission.value.status)
  const canRetry = currentStatus === 'failed' && editorSubmission.value.canRetry === true
  if (isEditorSubmitting.value || (currentStatus !== 'editing' && !canRetry)) {
    return { success: false, status: 'failed', message: '保存未完成，请重试。' }
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
    const action = editorConfirmationMode.value ? 'confirm-reservation' : (isExistingReservation ? 'update-reservation' : 'create-reservation')
    const result = await requestAction(action, {
      reservationId: session.reservationId,
      recordVersion: session.recordVersion,
      reservationVersion: session.recordVersion,
      // 门店端新建单由后端直接进入 UNSTARTED；会员端 MEMBER 单才进入待确认。
      source: isExistingReservation ? undefined : 'STORE',
      reservation: isExistingReservation ? normalizedDraft : { ...normalizedDraft, source: 'STORE' },
      idempotencyKey: commandId
    })
    if (!editorSession.value || editorSession.value.commandId !== commandId) {
      return { success: false, status: 'superseded', message: '该预约提交结果已被较新的编辑会话取代。' }
    }
    const response = actionResponse(result)
    const status = normalizeSubmissionStatus(response.status)
    if (status === 'succeeded') {
      editorSubmissionResult.value = { ...response }
      editorSubmissionStatus.value = status
      return result
    }
    editorSubmissionResult.value = {
      ...response,
      status: 'failed',
      canClose: true,
      canRetry: true,
      // 预约是普通单据保存。保留服务端已说明的原因，不能用统一文案把
      // 可修复的字段或接口错误吞掉。
      message: actionMessage(result) || response.message || '保存未完成，请重试。'
    }
    editorSubmissionStatus.value = 'failed'
    return result
  } catch (error) {
    editorSubmissionStatus.value = 'failed'
    editorSubmissionResult.value = {
      status: 'failed',
      canClose: true,
      canRetry: true,
      message: error?.message || '保存未完成，请重试。'
    }
    return { result: { status: 'failed', message: error?.message || '保存未完成，请重试。' } }
  } finally {
    isEditorSubmitting.value = false
  }
}

function resumeReservationEditing() {
  if (editorSubmissionStatus.value !== 'failed') return
  const session = editorSession.value
  if (session) editorSession.value = Object.freeze({ ...session, commandId: null })
  editorSubmitCommandId.value = null
  editorSubmissionStatus.value = ''
  editorSubmissionResult.value = {}
}

function handleEditorSubmitted(result) {
  if (normalizeSubmissionStatus(actionResponse(result).status) !== 'succeeded') return
  closeReservationEditor()
  queryReservations(reservationQuerySnapshot.value, false)
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
  editorConfirmationMode.value = false
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
        :show-keyword-search="false"
        :inline-quick-controls="true"
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
              <span v-else-if="field.key === 'status'" class="reservation-status" :class="statusClass(record)">{{ reservationStatusLabel(record) }}</span>
              <template v-else>{{ reservationFieldValue(record, field.key) }}</template>
            </td>
            <td>
              <div class="reservation-row-actions">
                <button
                  v-for="action in recordActions(record)"
                  :key="action.action"
                  type="button"
                  class="button reservation-row-action"
                  :class="{
                    'reservation-row-action--primary': action.primary
                  }"
                  :disabled="isReservationActionPending(action.action, record)"
                  :aria-label="`${action.label}，预约 ${record.reservationNo || record.id}，${record.memberName || '未命名会员'}`"
                  @click="requestReservationRecordButton(record, action.action)"
                >{{ isReservationActionPending(action.action, record) ? '正在处理…' : action.label }}</button>
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
              :class="`reservation-calendar-block--${reservationStatus(block).phase}`"
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
      :on-refresh-catalog="refreshReservationProjectCatalog"
      :on-recalculate="recalculateReservationPlan"
      :on-submit="submitReservationEditor"
      :confirmation-mode="editorConfirmationMode"
      @close="closeReservationEditor"
      @resume-editing="resumeReservationEditing"
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
      :on-reload="reloadReservationDetail"
      :on-action="handleReservationDetailAction"
      @close="closeReservationDetail"
    />
  </section>
</template>
