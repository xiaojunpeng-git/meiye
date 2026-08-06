<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import AlertCircle from '@lucide/vue/dist/esm/icons/circle-alert.mjs'
import ChartNoAxesCombined from '@lucide/vue/dist/esm/icons/chart-no-axes-combined.mjs'
import ClipboardCheck from '@lucide/vue/dist/esm/icons/clipboard-check.mjs'
import MessageSquareText from '@lucide/vue/dist/esm/icons/message-square-text.mjs'
import Plus from '@lucide/vue/dist/esm/icons/plus.mjs'
import Search from '@lucide/vue/dist/esm/icons/search.mjs'
import Settings2 from '@lucide/vue/dist/esm/icons/settings-2.mjs'
import Users from '@lucide/vue/dist/esm/icons/users.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'
import CareCommandDrawer from '@/components/care/CareCommandDrawer.vue'
import CareMetricDetailDrawer from '@/components/care/CareMetricDetailDrawer.vue'
import CareRecordDetailDrawer from '@/components/care/CareRecordDetailDrawer.vue'
import CareTaskDetailDrawer from '@/components/care/CareTaskDetailDrawer.vue'
import { requestCustomerCareAction } from '@/services/customerCareApi'
// Preview data is intentionally loaded below only for an explicit local
// preview request. Keeping it out of the module graph lets production builds
// work from a clean checkout where dev fixtures are not present.
const CUSTOMER_CARE_CONTRACT_VERSION = 'customer-care.v1'
const previewRequested = typeof window !== 'undefined'
  && import.meta.env.DEV
  && new Set(['127.0.0.1', 'localhost', '::1']).has(window.location.hostname)
  && new URLSearchParams(window.location.search).get('preview') === '1'
const previewModulePath = '/src/dev/customerCarePreviewData.js'

const cloneCustomerCarePreview = (value) => JSON.parse(JSON.stringify(value))

const tabs = [
  { key: 'tasks', label: '跟进任务', icon: ClipboardCheck },
  { key: 'customers', label: '客户视图', icon: Users },
  { key: 'records', label: '客情记录', icon: MessageSquareText },
  { key: 'statistics', label: '跟进统计', icon: ChartNoAxesCombined },
  {
    key: 'settings',
    label: '回访设置（待开发）',
    icon: Settings2,
    disabled: true,
    disabledReason: '服务完成后的自动回访规则尚未接入，当前不可使用。'
  }
]
const taskBuckets = [
  { key: 'today', label: '今日待跟进' },
  { key: 'overdue', label: '已逾期' },
  { key: 'future', label: '未来待跟进' },
  { key: 'completed', label: '已完成' },
  { key: 'all', label: '全部' }
]

const isPreview = ref(false)
const activeTab = ref('tasks')
const activeTaskScope = ref('')
const activeTaskBucket = ref('')
const taskKeyword = ref('')
const taskStatus = ref('')
const taskPlannedFrom = ref('')
const taskPlannedTo = ref('')
const customerKeyword = ref('')
const customerMemberId = ref('')
const recordKeyword = ref('')
const recordFollowedFrom = ref('')
const recordFollowedTo = ref('')
const recordStream = ref('human')
const recordDataScope = ref('normal')
const projection = ref(null)
const displayedTasks = ref([])
const displayedCustomers = ref([])
const displayedRecords = ref([])
const loadState = ref('idle')
const pageError = ref('')
const feedback = ref(null)
const selectedTaskId = ref('')
const selectedRecordId = ref('')
const selectedTaskDetail = ref(null)
const selectedRecordDetail = ref(null)
const statisticsDetail = ref(null)
const statisticsDetailLoading = ref(false)
const statisticsDetailError = ref('')
const pendingAction = ref('')
const commandMode = ref('')
const commandSubject = ref({})
const commandMember = ref({})
const commandOrigin = ref({})
const commandError = ref('')
const isCommandSubmitting = ref(false)
const isCreatingTask = ref(false)
const createTaskError = ref('')
const createTaskDraft = reactive({ memberId: '', memberName: '', taskTypeCode: 'DAILY_FOLLOWUP', plannedDate: '', plannedTime: '09:00', ownerId: '', content: '' })
let previewSequence = 10
let localIdempotencySequence = 0
let commandIdempotencyKey = ''
let careQuerySequence = 0
const directActionIdempotencyKeys = new Map()

const care = computed(() => projection.value || {})
const taskView = computed(() => care.value.taskView || {})
const customerView = computed(() => care.value.customerView || {})
const recordView = computed(() => care.value.recordView || {})
const statistics = computed(() => care.value.statistics || {})
const settings = computed(() => care.value.settings || {})
const permissions = computed(() => care.value.permissions || {})
const selectedTask = computed(() => {
  if (selectedTaskDetail.value) return selectedTaskDetail.value
  const tasks = Array.isArray(taskView.value.records) ? taskView.value.records : []
  return tasks.find((task) => String(task.taskId) === String(selectedTaskId.value)) || null
})
const isTaskDetailOpen = computed(() => Boolean(selectedTask.value))
const selectedRecord = computed(() => {
  if (selectedRecordDetail.value) return selectedRecordDetail.value
  const records = Array.isArray(displayedRecords.value) ? displayedRecords.value : []
  return records.find((record) => String(record.recordId) === String(selectedRecordId.value)) || null
})
const isRecordDetailOpen = computed(() => Boolean(selectedRecord.value))
const commandTask = computed(() => commandSubject.value.taskId ? commandSubject.value : (selectedTask.value || {}))
const commandPreparation = computed(() => {
  if (commandMode.value === 'reassign-care-task') return care.value.preparations?.reassignment || {}
  return care.value.preparations?.completion || {}
})
const bucketCounts = computed(() => taskView.value.bucketCounts || {})
const statusOptions = computed(() => Array.isArray(taskView.value.statusOptions) ? taskView.value.statusOptions : [])
const isEmpty = computed(() => loadState.value !== 'loading' && !projection.value)

function exactCustomerCareProjection(response) {
  const candidate = response?.projection?.customerCare
  if (!candidate || typeof candidate !== 'object' || Array.isArray(candidate)) return null
  if (candidate.contractVersion !== CUSTOMER_CARE_CONTRACT_VERSION) return null
  return candidate
}

function resultSucceeded(response) {
  return response?.result?.status === 'success' || response?.result?.status === 'succeeded'
}

function resultMessage(response, fallback) {
  return response?.result?.message || fallback
}

function resultCode(response) {
  return String(response?.result?.code || '')
}

function isProjectionRefreshRequired(response) {
  return resultCode(response) === 'CARE_PROJECTION_REFRESH_REQUIRED'
    && Boolean(response?.result?.detail?.commandReceipt?.operationKey)
}

function newIdempotencyKey() {
  return globalThis.crypto?.randomUUID?.()
    || `care-local-${++localIdempotencySequence}-${Math.random().toString(16).slice(2)}`
}

function commandIdempotencyKeyForCurrentDialog() {
  if (!commandIdempotencyKey) commandIdempotencyKey = newIdempotencyKey()
  return commandIdempotencyKey
}

function directActionIdentity(action, subject = {}) {
  return [action, subject.taskId || subject.recordId || subject.exceptionId || '', subject.taskVersion || subject.recordVersion || ''].join(':')
}

function directActionIdempotencyKey(action, subject = {}) {
  const identity = directActionIdentity(action, subject)
  if (!directActionIdempotencyKeys.has(identity)) {
    directActionIdempotencyKeys.set(identity, newIdempotencyKey())
  }
  return directActionIdempotencyKeys.get(identity)
}

function clearDirectActionIdempotencyKey(action, subject = {}) {
  directActionIdempotencyKeys.delete(directActionIdentity(action, subject))
}

function clearDisplayedRecords() {
  displayedTasks.value = []
  displayedCustomers.value = []
  displayedRecords.value = []
}

function acceptCareProjection(nextProjection, { applyPreviewFilters = false } = {}) {
  projection.value = nextProjection
  if (applyPreviewFilters) {
    applyPreviewQuery()
    return
  }
  displayedTasks.value = Array.isArray(nextProjection.taskView?.records) ? nextProjection.taskView.records : []
  displayedCustomers.value = Array.isArray(nextProjection.customerView?.records) ? nextProjection.customerView.records : []
  displayedRecords.value = Array.isArray(nextProjection.recordView?.records) ? nextProjection.recordView.records : []
  syncServerQueryControls(nextProjection)
  refreshSelectedDetails(nextProjection)
}

function syncServerQueryControls(nextProjection) {
  const taskQuery = nextProjection.taskView?.appliedQuery || {}
  const customerQuery = nextProjection.customerView?.appliedQuery || {}
  const recordQuery = nextProjection.recordView?.appliedQuery || {}
  if (taskQuery.scope) activeTaskScope.value = taskQuery.scope
  if (taskQuery.bucket) activeTaskBucket.value = taskQuery.bucket
  if (taskQuery.plannedFrom) taskPlannedFrom.value = taskQuery.plannedFrom
  if (taskQuery.plannedTo) taskPlannedTo.value = taskQuery.plannedTo
  if (recordQuery.followedFrom) recordFollowedFrom.value = recordQuery.followedFrom
  if (recordQuery.followedTo) recordFollowedTo.value = recordQuery.followedTo
  customerMemberId.value = validCustomerMemberId(customerQuery.memberId)
}

function validCustomerMemberId(value) {
  const memberId = String(value ?? '').trim()
  return /^[1-9][0-9]*$/.test(memberId) ? memberId : ''
}

function refreshSelectedDetails(nextProjection) {
  const detail = nextProjection.statistics?.detail || {}
  const taskCandidates = [
    ...(nextProjection.taskView?.records || []),
    ...(detail.entityType === 'task' ? detail.records || [] : [])
  ]
  const recordCandidates = [
    ...(nextProjection.recordView?.records || []),
    ...(detail.entityType === 'record' ? detail.records || [] : [])
  ]
  if (selectedTaskId.value) {
    const task = taskCandidates.find((item) => String(item.taskId) === String(selectedTaskId.value))
    if (task) selectedTaskDetail.value = task
  }
  if (selectedRecordId.value) {
    const record = recordCandidates.find((item) => String(item.recordId) === String(selectedRecordId.value))
    if (record) selectedRecordDetail.value = record
  }
}

function statisticsDetailIdentity(detail = {}) {
  if (!detail?.metric) return ''
  return [detail.metric, String(detail.staffId || '')].join(':')
}

function hasMatchingStatisticsDetail(nextProjection, identity) {
  return Boolean(identity) && statisticsDetailIdentity(nextProjection.statistics?.detail) === identity
}

async function refreshOpenStatisticsDetail() {
  const openedDetail = statisticsDetail.value
  const identity = statisticsDetailIdentity(openedDetail)
  if (!identity || isPreview.value) return true

  statisticsDetailLoading.value = true
  statisticsDetailError.value = ''
  try {
    const response = await requestCareAction('query-care-workbench', {
      view: 'statistics',
      query: {
        metric: openedDetail.metric,
        ...(openedDetail.staffId ? { staffId: String(openedDetail.staffId) } : {})
      }
    })
    const nextProjection = exactCustomerCareProjection(response)
    if (!resultSucceeded(response) || !nextProjection || !hasMatchingStatisticsDetail(nextProjection, identity)) return false
    acceptCareProjection(nextProjection)
    statisticsDetail.value = nextProjection.statistics.detail
    return true
  } catch (_) {
    return false
  } finally {
    statisticsDetailLoading.value = false
  }
}

async function refreshStatisticsDetailAfterMutation(nextProjection) {
  const identity = statisticsDetailIdentity(statisticsDetail.value)
  if (!identity) return true
  if (hasMatchingStatisticsDetail(nextProjection, identity)) {
    statisticsDetail.value = nextProjection.statistics.detail
    statisticsDetailError.value = ''
    return true
  }
  const refreshed = await refreshOpenStatisticsDetail()
  if (!refreshed) closeStatisticsDetail()
  return refreshed
}

function successMessageWithStatisticsState(message, statisticsRefreshed) {
  return statisticsRefreshed
    ? message
    : `${message} 统计明细未能刷新，已关闭旧明细；请重新打开后查看最新状态。`
}

function failCareProjection(message) {
  projection.value = null
  clearDisplayedRecords()
  pageError.value = message
  loadState.value = 'failed'
}

async function requestCareAction(action, payload = {}) {
  if (isPreview.value) return applyPreviewAction(action, payload)
  const isQuery = action === 'query-care-workbench'
  const suppliedIdempotencyKey = String(payload.idempotencyKey || '').trim()
  const idempotencyKey = isQuery ? '' : (suppliedIdempotencyKey || newIdempotencyKey())
  return requestCustomerCareAction(action, { ...payload, ...(idempotencyKey ? { idempotencyKey } : {}) })
}

async function loadCareProjection() {
  if (isPreview.value) {
    applyPreviewQuery()
    loadState.value = 'ready'
    return
  }
  const sequence = ++careQuerySequence
  const hasProjection = Boolean(projection.value)
  loadState.value = 'loading'
  pageError.value = ''
  try {
    const response = await requestCareAction('query-care-workbench', {
      view: activeTab.value,
      query: currentQueryPayload()
    })
    const nextProjection = exactCustomerCareProjection(response)
    if (sequence !== careQuerySequence) return
    if (!resultSucceeded(response) || !nextProjection) {
      const message = resultMessage(response, '客情服务尚未返回可用数据。')
      if (hasProjection) {
        loadState.value = 'ready'
        showFeedback('error', message)
      } else failCareProjection(message)
      return
    }
    acceptCareProjection(nextProjection)
    // A successful retry supersedes any earlier transient-query feedback.
    feedback.value = null
    loadState.value = 'ready'
  } catch (error) {
    if (sequence !== careQuerySequence) return
    const message = error?.message || '客情服务暂时不可用，请稍后重试。'
    if (hasProjection) {
      loadState.value = 'ready'
      showFeedback('error', message)
    } else failCareProjection(message)
  }
}

function currentQueryPayload(view = activeTab.value) {
  if (view === 'tasks') {
    const query = {
      keyword: taskKeyword.value.trim(),
      status: taskStatus.value,
      plannedFrom: taskPlannedFrom.value,
      plannedTo: taskPlannedTo.value
    }
    if (activeTaskScope.value) query.scope = activeTaskScope.value
    if (activeTaskBucket.value) query.bucket = activeTaskBucket.value
    return query
  }
  if (view === 'customers') {
    const memberId = validCustomerMemberId(customerMemberId.value)
    return {
      keyword: customerKeyword.value.trim(),
      ...(memberId ? { memberId } : {})
    }
  }
  if (view === 'records') {
    return {
      keyword: recordKeyword.value.trim(),
      stream: recordStream.value,
      dataScope: recordDataScope.value,
      followedFrom: recordFollowedFrom.value,
      followedTo: recordFollowedTo.value
    }
  }
  if (view === 'statistics' && statisticsDetail.value?.metric) {
    return {
      metric: statisticsDetail.value.metric,
      ...(statisticsDetail.value.staffId ? { staffId: String(statisticsDetail.value.staffId) } : {})
    }
  }
  return {}
}

function workbenchRequestForAction(action) {
  if (action === 'complete-care-task') {
    const origin = commandOrigin.value
    if (origin.tab === 'customers') {
      return {
        view: 'customers',
        query: {
          ...currentQueryPayload('customers'),
          ...(origin.memberId ? { memberId: origin.memberId } : {})
        }
      }
    }
  }
  return { view: activeTab.value, query: currentQueryPayload(activeTab.value) }
}

async function reconcileCommandProjection(workbenchRequest) {
  if (isPreview.value) {
    applyPreviewQuery()
    return true
  }
  try {
    const response = await requestCareAction('query-care-workbench', workbenchRequest)
    const nextProjection = exactCustomerCareProjection(response)
    if (!resultSucceeded(response) || !nextProjection) return false
    acceptCareProjection(nextProjection)
    loadState.value = 'ready'
    pageError.value = ''
    return true
  } catch (_) {
    return false
  }
}

function showReloadableFeedback(message) {
  feedback.value = { kind: 'error', message, reloadable: true }
}

async function reloadFromFeedback() {
  feedback.value = null
  await loadCareProjection()
}

function closeStaleCommandSurfaces() {
  commandMode.value = ''
  commandSubject.value = {}
  commandMember.value = {}
  commandOrigin.value = {}
  commandError.value = ''
  commandIdempotencyKey = ''
  closeTask()
  closeRecord()
}

async function recoverAppliedCommand(response, workbenchRequest, successMessage) {
  const refreshed = await reconcileCommandProjection(workbenchRequest)
  if (refreshed) {
    const statisticsRefreshed = await refreshOpenStatisticsDetail()
    if (!statisticsRefreshed) closeStatisticsDetail()
    activeTab.value = workbenchRequest.view
    closeStaleCommandSurfaces()
    showFeedback('success', successMessageWithStatisticsState(`${successMessage} 页面已刷新。`, statisticsRefreshed))
    return true
  }
  activeTab.value = workbenchRequest.view
  closeStaleCommandSurfaces()
  showReloadableFeedback(`${successMessage}，但页面暂时无法刷新。已关闭旧详情，请重新加载后继续操作。`)
  return false
}

async function recoverVersionConflict(response, workbenchRequest, { keepDialog = false } = {}) {
  const refreshed = await reconcileCommandProjection(workbenchRequest)
  const latestTask = response?.result?.latestTask
  if (latestTask?.taskId) {
    selectedTaskId.value = latestTask.taskId
    selectedTaskDetail.value = latestTask
    if (keepDialog) commandSubject.value = latestTask
  }
  if (refreshed) {
    const statisticsRefreshed = await refreshOpenStatisticsDetail()
    if (!statisticsRefreshed) closeStatisticsDetail()
    activeTab.value = workbenchRequest.view
    if (!keepDialog) {
      const statusLabel = latestTask?.statusLabel || '最新状态'
      showFeedback('success', successMessageWithStatisticsState(`该任务已更新为${statusLabel}，已为你刷新。`, statisticsRefreshed))
    }
    return true
  }
  activeTab.value = workbenchRequest.view
  closeStaleCommandSurfaces()
  showReloadableFeedback('任务状态已更新，但页面暂时无法刷新。已关闭旧详情，请重新加载后继续操作。')
  return false
}

function applyPreviewQuery() {
  const allTasks = Array.isArray(taskView.value.records) ? taskView.value.records : []
  const keyword = taskKeyword.value.trim().toLowerCase()
  displayedTasks.value = allTasks.filter((task) => {
    if (task.isDeleted) return false
    if (!Array.isArray(task.listScopes) || !task.listScopes.includes(activeTaskScope.value)) return false
    if (activeTaskBucket.value !== 'all' && task.bucket !== activeTaskBucket.value) return false
    if (taskStatus.value && task.status !== taskStatus.value) return false
    if (!keyword) return true
    const searchable = [task.taskId, task.member?.name, task.member?.phone, task.typeLabel, task.owner?.name, task.relatedBusiness?.label].join(' ').toLowerCase()
    return searchable.includes(keyword)
  })

  const customerNeedle = customerKeyword.value.trim().toLowerCase()
  const allCustomers = Array.isArray(customerView.value.records) ? customerView.value.records : []
  displayedCustomers.value = allCustomers.filter((customer) => !customerNeedle
    || [customer.name, customer.phone, customer.exclusiveServiceName].join(' ').toLowerCase().includes(customerNeedle))

  const recordNeedle = recordKeyword.value.trim().toLowerCase()
  const allRecords = Array.isArray(recordView.value.records) ? recordView.value.records : []
  displayedRecords.value = allRecords.filter((record) => {
    if (record.stream !== recordStream.value) return false
    if (recordDataScope.value === 'normal' && record.status === 'VOIDED') return false
    if (!recordNeedle) return true
    return [record.memberName, record.typeLabel, record.content, record.actualFollowerName, record.appointmentNo].join(' ').toLowerCase().includes(recordNeedle)
  })
}

async function switchTab(tabKey) {
  activeTab.value = tabKey
  pageError.value = ''
  if (isPreview.value) applyPreviewQuery()
  else await loadCareProjection()
}

async function selectTaskScope(scope) {
  activeTaskScope.value = scope
  selectedTaskId.value = ''
  await loadCareProjection()
}

async function selectTaskBucket(bucket) {
  activeTaskBucket.value = bucket
  selectedTaskId.value = ''
  await loadCareProjection()
}

async function clearCustomerMemberFilter() {
  customerMemberId.value = ''
  await loadCareProjection()
}

function statusTone(status) {
  return `care-status--${String(status || 'unknown').toLowerCase()}`
}

function openTask(task) {
  if (!task?.taskId) return
  selectedTaskId.value = task.taskId
  selectedTaskDetail.value = task
}

function closeTask() {
  selectedTaskId.value = ''
  selectedTaskDetail.value = null
}

function openRecord(record) {
  if (!record?.recordId) return
  selectedRecordId.value = record.recordId
  selectedRecordDetail.value = record
}

function closeRecord() {
  selectedRecordId.value = ''
  selectedRecordDetail.value = null
}

async function openTaskByReference(nextTask) {
  if (!nextTask?.taskId || nextTask.canOpen === false) return
  const known = [...(taskView.value.records || []), ...(statisticsDetail.value?.records || [])]
    .find((task) => String(task.taskId) === String(nextTask.taskId))
  if (known) {
    openTask(known)
    return
  }
  try {
    const response = await requestCareAction('query-care-workbench', {
      view: 'tasks',
      query: {
        scope: permissions.value.canViewAllTasks ? 'all' : 'my',
        bucket: 'all',
        keyword: nextTask.taskNo || String(nextTask.taskId),
        status: ''
      }
    })
    const nextProjection = exactCustomerCareProjection(response)
    const task = nextProjection?.taskView?.records?.find((item) => String(item.taskId) === String(nextTask.taskId))
    if (!resultSucceeded(response) || !nextProjection || !task) {
      showFeedback('error', resultMessage(response, '当前任务已更新或无权查看，请刷新后再试。'))
      return
    }
    acceptCareProjection(nextProjection)
    openTask(task)
  } catch (error) {
    showFeedback('error', error?.message || '任务详情暂时无法读取，请稍后重试。')
  }
}

async function openStatisticsDetail(metric, staffId = '') {
  if (isPreview.value) {
    feedback.value = { type: 'error', message: '本机演示数据不提供统计明细，请在正式客情入口查看。' }
    return
  }
  statisticsDetailLoading.value = true
  statisticsDetailError.value = ''
  try {
    const response = await requestCareAction('query-care-workbench', {
      view: 'statistics',
      query: { metric, ...(staffId ? { staffId: String(staffId) } : {}) }
    })
    const nextProjection = exactCustomerCareProjection(response)
    const detail = nextProjection?.statistics?.detail
    if (!resultSucceeded(response) || !nextProjection || !detail || detail.metric !== metric) {
      statisticsDetailError.value = resultMessage(response, '统计明细暂时无法读取。')
      return
    }
    acceptCareProjection(nextProjection)
    statisticsDetail.value = detail
  } catch (error) {
    statisticsDetailError.value = error?.message || '统计明细暂时无法读取。'
  } finally {
    statisticsDetailLoading.value = false
  }
}

async function loadMoreStatisticsDetail() {
  const detail = statisticsDetail.value
  if (!detail?.hasMore || !detail.paginationCursor || statisticsDetailLoading.value) return
  statisticsDetailLoading.value = true
  statisticsDetailError.value = ''
  try {
    const response = await requestCareAction('query-care-workbench', {
      view: 'statistics',
      query: {
        metric: detail.metric,
        ...(detail.staffId ? { staffId: detail.staffId } : {}),
        cursor: detail.paginationCursor
      }
    })
    const nextProjection = exactCustomerCareProjection(response)
    const nextDetail = nextProjection?.statistics?.detail
    if (!resultSucceeded(response) || !nextProjection || !nextDetail || nextDetail.metric !== detail.metric) {
      statisticsDetailError.value = resultMessage(response, '更多统计明细暂时无法读取。')
      return
    }
    acceptCareProjection(nextProjection)
    statisticsDetail.value = { ...nextDetail, records: [...(detail.records || []), ...(nextDetail.records || [])] }
  } catch (error) {
    statisticsDetailError.value = error?.message || '更多统计明细暂时无法读取。'
  } finally {
    statisticsDetailLoading.value = false
  }
}

function closeStatisticsDetail() {
  if (statisticsDetailLoading.value) return
  statisticsDetail.value = null
  statisticsDetailError.value = ''
}

function openCommand(mode, subject = {}) {
  commandMode.value = mode
  commandSubject.value = subject
  commandMember.value = mode === 'create-care-record' ? {} : commandMember.value
  commandOrigin.value = {
    tab: activeTab.value,
    memberId: activeTab.value === 'customers'
      ? String(customerMemberId.value || subject?.member?.memberId || subject?.memberId || '')
      : ''
  }
  commandError.value = ''
  commandIdempotencyKey = newIdempotencyKey()
}

function closeCommand() {
  if (isCommandSubmitting.value) return
  commandMode.value = ''
  commandSubject.value = {}
  commandMember.value = {}
  commandOrigin.value = {}
  commandError.value = ''
  commandIdempotencyKey = ''
}

function openCareRecordMemberSelector() {
  window.dispatchEvent(new CustomEvent('cashier-v3:open-member-selector', {
    detail: { context: 'customer-care-record', selectorContext: 'customer-care', requireMember: true }
  }))
}

function openCreateTaskPage() {
  Object.assign(createTaskDraft, { memberId: '', memberName: '', taskTypeCode: 'DAILY_FOLLOWUP', plannedDate: '', plannedTime: '09:00', ownerId: '', content: '' })
  createTaskError.value = ''
  commandIdempotencyKey = newIdempotencyKey()
  isCreatingTask.value = true
}

function closeCreateTaskPage() {
  if (isCommandSubmitting.value) return
  isCreatingTask.value = false
  createTaskError.value = ''
  commandIdempotencyKey = ''
}

function openCareMemberSelector() {
  window.dispatchEvent(new CustomEvent('cashier-v3:open-member-selector', {
    detail: { context: 'customer-care', selectorContext: 'customer-care', requireMember: true }
  }))
}

function careMemberSelected(event) {
  const record = event?.detail?.record || {}
  const member = {
    memberId: String(record.id || record.memberId || record.uid || record.userId || ''),
    memberName: String(record.name || record.realName || record.real_name || record.nickname || '')
  }
  if (event?.detail?.context === 'customer-care') {
    createTaskDraft.memberId = member.memberId
    createTaskDraft.memberName = member.memberName
    return
  }
  if (event?.detail?.context === 'customer-care-record') commandMember.value = member
}

async function submitCreateTask() {
  if (!createTaskDraft.memberId || !createTaskDraft.plannedDate || !createTaskDraft.plannedTime || !createTaskDraft.ownerId || isCommandSubmitting.value) return
  isCommandSubmitting.value = true
  createTaskError.value = ''
  const showCreatedTaskRequest = { view: 'tasks', query: currentQueryPayload() }
  try {
    const response = await requestCareAction('create-care-task', {
      ...createTaskDraft,
      plannedAt: `${createTaskDraft.plannedDate}T${createTaskDraft.plannedTime}`,
      workbenchRequest: showCreatedTaskRequest,
      idempotencyKey: commandIdempotencyKeyForCurrentDialog()
    })
    if (isProjectionRefreshRequired(response)) {
      await recoverAppliedCommand(response, showCreatedTaskRequest, '跟进任务已创建')
      isCreatingTask.value = false
      commandIdempotencyKey = ''
      return
    }
    if (!resultSucceeded(response)) {
      createTaskError.value = resultMessage(response, '新增跟进任务失败，请检查后重试。')
      return
    }
    const nextProjection = exactCustomerCareProjection(response)
    if (!nextProjection) {
      createTaskError.value = '新增结果缺少完整客情数据，请刷新页面确认实际状态。'
      return
    }
    activeTab.value = 'tasks'
    acceptCareProjection(nextProjection)
    isCreatingTask.value = false
    commandIdempotencyKey = ''
    showFeedback('success', resultMessage(response, '跟进任务已创建。'))
  } catch (error) {
    createTaskError.value = error?.message || '新增跟进任务失败，请检查网络后重试。'
  } finally {
    isCommandSubmitting.value = false
  }
}

async function handleTaskAction(action) {
  if (!action || action.enabled === false) return
  if (['complete-care-task', 'reassign-care-task', 'void-care-task', 'delete-care-task'].includes(action.code)) {
    openCommand(action.code, selectedTask.value || {})
    return
  }
  await runDirectAction(action.code, selectedTask.value || {})
}

async function handleSubjectAction(action, subject) {
  if (!action || action.enabled === false) return
  if (['void-care-record', 'save-care-followup-rule'].includes(action.code)) {
    openCommand(action.code, subject)
    return
  }
  await runDirectAction(action.code, subject)
}

async function runDirectAction(action, subject = {}) {
  pendingAction.value = action
  const workbenchRequest = workbenchRequestForAction(action)
  const idempotencyKey = directActionIdempotencyKey(action, subject)
  try {
    const response = await requestCareAction(action, {
      taskId: subject.taskId,
      expectedVersion: subject.taskVersion,
      exceptionId: subject.exceptionId,
      workbenchRequest,
      idempotencyKey
    })
    if (isProjectionRefreshRequired(response)) {
      clearDirectActionIdempotencyKey(action, subject)
      await recoverAppliedCommand(response, workbenchRequest, '操作已完成')
      return
    }
    if (!resultSucceeded(response)) {
      if (resultCode(response) === 'CARE_VERSION_CONFLICT') {
        clearDirectActionIdempotencyKey(action, subject)
        await recoverVersionConflict(response, workbenchRequest)
        return
      }
      clearDirectActionIdempotencyKey(action, subject)
      showFeedback('error', resultMessage(response, '本次客情操作未成功。'))
      return
    }
    const nextProjection = exactCustomerCareProjection(response)
    if (!nextProjection) {
      showFeedback('error', '操作结果缺少完整客情数据，请刷新页面确认实际状态。')
      return
    }
    acceptCareProjection(nextProjection, { applyPreviewFilters: isPreview.value })
    const statisticsRefreshed = await refreshStatisticsDetailAfterMutation(nextProjection)
    clearDirectActionIdempotencyKey(action, subject)
    showFeedback('success', successMessageWithStatisticsState(resultMessage(response, '操作已完成。'), statisticsRefreshed))
  } catch (error) {
    showFeedback('error', error?.message || '本次客情操作未成功。')
  } finally {
    pendingAction.value = ''
  }
}

async function submitCommand(payload) {
  isCommandSubmitting.value = true
  commandError.value = ''
  const completedTask = commandMode.value === 'complete-care-task'
  const workbenchRequest = workbenchRequestForAction(commandMode.value)
  try {
    const response = await requestCareAction(commandMode.value, {
      ...payload,
      workbenchRequest,
      idempotencyKey: commandIdempotencyKeyForCurrentDialog()
    })
    if (isProjectionRefreshRequired(response)) {
      commandIdempotencyKey = ''
      await recoverAppliedCommand(response, workbenchRequest, completedTask ? '跟进结果已提交' : '操作已完成')
      return
    }
    if (!resultSucceeded(response)) {
      const code = response?.result?.code || ''
      if (code === 'CARE_VERSION_CONFLICT') {
        commandIdempotencyKey = ''
        const refreshed = await recoverVersionConflict(response, workbenchRequest, { keepDialog: true })
        if (refreshed) commandError.value = '任务已更新，已为你刷新。请核对最新状态后再次提交。'
        return
      }
      commandError.value = resultMessage(response, '本次客情操作未成功，当前输入已保留。')
      return
    }
    const nextProjection = exactCustomerCareProjection(response)
    if (!nextProjection) {
      commandError.value = '操作结果缺少完整客情数据，当前输入已保留；请刷新页面确认实际状态。'
      return
    }
    acceptCareProjection(nextProjection, { applyPreviewFilters: isPreview.value })
    const statisticsRefreshed = await refreshStatisticsDetailAfterMutation(nextProjection)
    const successMessage = resultMessage(response, '操作已完成。')
    commandMode.value = ''
    commandSubject.value = {}
    commandMember.value = {}
    commandOrigin.value = {}
    commandIdempotencyKey = ''
    if (selectedTaskId.value && !selectedTask.value) selectedTaskId.value = ''
    if (completedTask) {
      activeTab.value = workbenchRequest.view
      closeTask()
      showFeedback('success', successMessageWithStatisticsState(
        workbenchRequest.view === 'customers' ? `${successMessage} 已刷新该会员客情。` : successMessage,
        statisticsRefreshed
      ))
    } else showFeedback('success', successMessageWithStatisticsState(successMessage, statisticsRefreshed))
  } catch (error) {
    commandError.value = `${error?.message || '本次客情操作未成功。'} 当前输入已保留。`
  } finally {
    isCommandSubmitting.value = false
  }
}

function showFeedback(kind, message) {
  feedback.value = { kind, message }
}

function previewResult(message) {
  return {
    result: { status: 'success', code: '', message },
    projection: { customerCare: cloneCustomerCarePreview(projection.value) }
  }
}

function applyPreviewAction(action, payload) {
  const tasks = projection.value.taskView.records
  const task = tasks.find((item) => String(item.taskId) === String(payload.taskId))
  const records = projection.value.recordView.records
  if (action === 'start-care-task' && task) {
    task.status = 'IN_PROGRESS'
    task.statusLabel = '进行中'
    task.taskVersion += 1
    task.availableActions = [
      { code: 'complete-care-task', label: '完成跟进', tone: 'primary', enabled: true },
      { code: 'void-care-task', label: '作废任务', tone: 'danger', enabled: true }
    ]
    return previewResult('任务已开始。')
  }
  if (action === 'complete-care-task' && task) {
    task.status = 'COMPLETED'
    task.statusLabel = '已完成'
    task.isOverdue = false
    task.bucket = 'completed'
    task.taskVersion += 1
    task.completedAt = '2026-07-29 10:18'
    task.actualFollower = cloneCustomerCarePreview(projection.value.currentEmployee)
    task.latestCare = {
      followedAt: '2026-07-29 10:18',
      methodLabel: projection.value.preparations.completion.methodOptions.find((item) => item.value === payload.methodCode)?.label || payload.methodCode,
      resultLabel: projection.value.preparations.completion.resultOptions.find((item) => item.value === payload.resultCode)?.label || payload.resultCode,
      content: payload.content
    }
    task.availableActions = []
    records.unshift({
      recordId: `CARE-RECORD-PREVIEW-${++previewSequence}`,
      recordVersion: 1,
      stream: 'human',
      typeLabel: task.typeLabel,
      followedAt: task.completedAt,
      methodLabel: task.latestCare.methodLabel,
      memberName: task.member.name,
      content: payload.content,
      resultLabel: task.latestCare.resultLabel,
      actualFollowerName: projection.value.currentEmployee.name,
      creatorName: projection.value.currentEmployee.name,
      storeName: task.store.name,
      status: 'NORMAL',
      statusLabel: '正常',
      availableActions: [{ code: 'void-care-record', label: '作废', tone: 'danger', enabled: true }]
    })
    if (payload.createNextTask) createPreviewTask({
      memberId: task.member.memberId,
      member: task.member,
      plannedAt: payload.nextPlannedAt,
      ownerId: payload.nextOwnerId,
      taskTypeCode: task.typeCode,
      content: '由本次完成跟进创建'
    })
    return previewResult('跟进结果已提交。')
  }
  if (action === 'reassign-care-task' && task) {
    const option = projection.value.preparations.reassignment.assigneeOptions.find((item) => item.value === payload.assigneeId)
    if (!option) return { result: { status: 'failed', code: 'CARE_ASSIGNEE_INVALID', message: '新负责人不在当前门店可选范围内。' } }
    task.owner = { employeeId: option.value, name: option.label }
    task.taskVersion += 1
    task.listScopes = option.value === projection.value.currentEmployee.employeeId ? ['my', 'all'] : ['all']
    task.availableActions = option.value === projection.value.currentEmployee.employeeId
      ? (task.status === 'UNSTARTED'
          ? [{ code: 'start-care-task', label: '开始跟进', tone: 'primary', enabled: true }, { code: 'delete-care-task', label: '删除任务', tone: 'danger', enabled: true }]
          : [{ code: 'complete-care-task', label: '完成跟进', tone: 'primary', enabled: true }, { code: 'void-care-task', label: '作废任务', tone: 'danger', enabled: true }])
      : [{ code: 'reassign-care-task', label: '转派', tone: 'secondary', enabled: true }]
    return previewResult('任务已转派。')
  }
  if (action === 'void-care-task' && task) {
    task.status = 'VOIDED'
    task.statusLabel = '已作废'
    task.isOverdue = false
    task.bucket = 'all'
    task.taskVersion += 1
    task.availableActions = []
    return previewResult('任务已作废。')
  }
  if (action === 'delete-care-task' && task) {
    task.isDeleted = true
    task.taskVersion += 1
    task.availableActions = []
    return previewResult('未开始任务已删除，审计记录继续保留。')
  }
  if (action === 'create-care-task') {
    createPreviewTask(payload)
    return previewResult('跟进任务已创建。')
  }
  if (action === 'create-care-record') {
    records.unshift({
      recordId: `CARE-RECORD-PREVIEW-${++previewSequence}`,
      recordVersion: 1,
      stream: 'human',
      typeLabel: payload.recordTypeCode,
      followedAt: payload.followedAt,
      methodLabel: payload.methodCode,
      memberName: payload.memberId,
      content: payload.content,
      resultLabel: payload.resultCode,
      actualFollowerName: projection.value.currentEmployee.name,
      creatorName: projection.value.currentEmployee.name,
      storeName: projection.value.currentStore.name,
      status: 'NORMAL',
      statusLabel: '正常',
      availableActions: [{ code: 'void-care-record', label: '作废', tone: 'danger', enabled: true }]
    })
    return previewResult('客情记录已提交。')
  }
  if (action === 'void-care-record') {
    const record = records.find((item) => String(item.recordId) === String(payload.recordId))
    if (record) {
      record.status = 'VOIDED'
      record.statusLabel = '已作废'
      record.recordVersion += 1
      record.voidReason = payload.reason
      record.availableActions = []
    }
    return previewResult('客情记录已作废。')
  }
  if (action === 'save-care-followup-rule') {
    const rules = projection.value.settings.rules
    const rule = rules.find((item) => String(item.ruleId) === String(payload.ruleId))
    if (rule) {
      rule.projectName = payload.projectName
      rule.delayLabel = `完成服务后 ${payload.delayDays} 天`
      rule.ownerRuleLabel = { PRIMARY_CRAFTSMAN: '本次服务主要手艺人', EXCLUSIVE_SERVICE: '会员专属服务人', CONFIGURED_STAFF: '指定人员' }[payload.ownerRuleCode] || payload.ownerRuleCode
      rule.enabled = payload.enabled
      rule.statusLabel = payload.enabled ? '启用' : '停用'
      rule.ruleVersion += 1
    }
    return previewResult('回访规则已保存。')
  }
  if (action === 'retry-care-auto-exception') return previewResult('异常已进入重新处理队列。')
  if (action === 'open-reservation-detail') return previewResult('已加载当前权限范围内的预约摘要。')
  return { result: { status: 'failed', code: 'PREVIEW_ACTION_NOT_SUPPORTED', message: '本机演示尚未实现该动作。' } }
}

function createPreviewTask(payload) {
  const ownerOption = projection.value.preparations.completion.assigneeOptions.find((item) => item.value === payload.ownerId)
  const member = payload.member || { memberId: payload.memberId, name: payload.memberId, phone: '', level: '', tags: [] }
  projection.value.taskView.records.unshift({
    taskId: `CARE-TASK-PREVIEW-${++previewSequence}`,
    taskVersion: 1,
    member,
    plannedAt: payload.plannedAt,
    typeCode: payload.taskTypeCode,
    typeLabel: { DAILY_FOLLOWUP: '日常跟进', FOLLOWUP: '回访', INVITATION: '邀约', SERVICE_FEEDBACK: '服务后反馈' }[payload.taskTypeCode] || payload.taskTypeCode,
    sourceCode: 'MANUAL',
    sourceLabel: '手工创建',
    status: 'UNSTARTED',
    statusLabel: '未开始',
    isOverdue: false,
    bucket: 'future',
    listScopes: payload.ownerId === projection.value.currentEmployee.employeeId ? ['my', 'all'] : ['all'],
    owner: { employeeId: payload.ownerId, name: ownerOption?.label || payload.ownerId },
    store: cloneCustomerCarePreview(projection.value.currentStore),
    relatedBusiness: { type: 'member', label: '手工跟进任务', summary: payload.content || '未填写任务说明' },
    latestCare: null,
    availableActions: payload.ownerId === projection.value.currentEmployee.employeeId
      ? [{ code: 'start-care-task', label: '开始跟进', tone: 'primary', enabled: true }, { code: 'delete-care-task', label: '删除任务', tone: 'danger', enabled: true }]
      : [{ code: 'reassign-care-task', label: '转派', tone: 'secondary', enabled: true }],
    appointmentCapability: { enabled: false, disabledReason: '预约服务尚未接入当前客情版本。' }
  })
}

async function initializeCareWorkbench() {
  if (previewRequested) {
    try {
      // Vite must not resolve this optional development-only module at build time.
      const candidate = await import(/* @vite-ignore */ previewModulePath)
      if (typeof candidate.createCustomerCarePreviewProjection === 'function') {
        isPreview.value = true
        projection.value = candidate.createCustomerCarePreviewProjection()
        applyPreviewQuery()
        loadState.value = 'ready'
        return
      }
    } catch (_) {
      // A clean checkout has no preview fixture; continue through the real API.
    }
  }
  await loadCareProjection()
}

onMounted(() => {
  window.addEventListener('cashier-v3:member-selector-selected', careMemberSelected)
  initializeCareWorkbench()
})
onBeforeUnmount(() => window.removeEventListener('cashier-v3:member-selector-selected', careMemberSelected))
</script>

<template>
  <section class="care-page" aria-label="客情管理">
    <section v-if="isCreatingTask" class="care-create-page" aria-label="新增跟进任务">
      <header class="care-create-page__header">
        <div><h2>新增跟进任务</h2><p>选择会员、计划时间和本店手艺人负责人后创建正式任务。</p></div>
        <button type="button" class="care-button" :disabled="isCommandSubmitting" @click="closeCreateTaskPage">返回客情</button>
      </header>
      <form class="care-create-page__form" @submit.prevent="submitCreateTask">
        <label class="care-create-page__field"><span>会员姓名<strong>*</strong></span><div class="care-create-page__member"><input :value="createTaskDraft.memberName" readonly placeholder="请选择会员"><button type="button" class="care-button" @click="openCareMemberSelector">选择会员</button></div></label>
        <div class="care-create-page__columns">
          <label class="care-create-page__field"><span>任务类型<strong>*</strong></span><select v-model="createTaskDraft.taskTypeCode"><option value="DAILY_FOLLOWUP">日常跟进</option><option value="FOLLOWUP">回访</option><option value="INVITATION">邀约</option><option value="SERVICE_FEEDBACK">服务后反馈</option></select></label>
          <div class="care-create-page__date-time"><label class="care-create-page__field"><span>计划日期<strong>*</strong></span><input v-model="createTaskDraft.plannedDate" type="date" required></label><label class="care-create-page__field"><span>计划时间<strong>*</strong></span><input v-model="createTaskDraft.plannedTime" type="time" required></label></div>
        </div>
        <label class="care-create-page__field"><span>负责人<strong>*</strong></span><select v-model="createTaskDraft.ownerId" required><option value="">请选择本店手艺人</option><option v-for="option in care.preparations?.completion?.assigneeOptions || []" :key="option.value" :value="option.value" :disabled="option.enabled === false">{{ option.label }}</option></select></label>
        <label class="care-create-page__field"><span>任务说明</span><textarea v-model="createTaskDraft.content" rows="7" maxlength="500" placeholder="选填，记录本次跟进重点" /></label>
        <p v-if="createTaskError" class="care-create-page__error" role="alert">{{ createTaskError }}</p>
        <footer class="care-create-page__footer"><button type="button" class="care-button" :disabled="isCommandSubmitting" @click="closeCreateTaskPage">取消</button><button type="submit" class="care-button care-button--primary" :disabled="!createTaskDraft.memberId || !createTaskDraft.plannedDate || !createTaskDraft.plannedTime || !createTaskDraft.ownerId || isCommandSubmitting">{{ isCommandSubmitting ? '正在创建…' : '创建任务' }}</button></footer>
      </form>
    </section>
    <template v-else>
    <nav class="care-tabs" aria-label="客情管理视图">
      <button v-for="tab in tabs" :key="tab.key" type="button" :class="{ 'care-tabs__button--active': activeTab === tab.key }" :aria-pressed="activeTab === tab.key" :disabled="tab.disabled" :title="tab.disabledReason || ''" @click="switchTab(tab.key)">
        <component :is="tab.icon" :size="16" aria-hidden="true" />
        <span>{{ tab.label }}</span>
      </button>
    </nav>

    <div v-if="isPreview" class="care-preview-banner"><span>本机演示数据</span><p>仅用于确认页面与交互，不代表客情后端、预约或通知已接入。</p></div>
    <div v-if="feedback" class="care-feedback" :class="`care-feedback--${feedback.kind}`" role="status"><span>{{ feedback.message }}</span><span class="care-feedback__actions"><button v-if="feedback.reloadable" type="button" @click="reloadFromFeedback">重新加载</button><button type="button" @click="feedback = null">关闭</button></span></div>

    <div v-if="loadState === 'loading'" class="care-page__state" role="status">正在加载客情数据…</div>
    <div v-else-if="isEmpty" class="care-page__state care-page__state--error" role="alert">
      <AlertCircle :size="24" aria-hidden="true" />
      <strong>客情服务尚未接入</strong>
      <span>{{ pageError || '后端没有返回 customer-care.v1 权威投影，页面已保持空态。' }}</span>
    </div>

    <template v-else>
      <section v-if="activeTab === 'tasks'" class="care-workspace" aria-label="跟进任务">
        <form class="care-query" @submit.prevent="loadCareProjection">
          <div class="care-query__row">
            <label class="care-search"><Search :size="16" aria-hidden="true" /><input v-model="taskKeyword" placeholder="搜索会员、手机号、任务编号或负责人"></label>
            <div class="care-segment" aria-label="任务范围">
              <button type="button" :class="{ 'care-segment__active': activeTaskScope === 'my' }" @click="selectTaskScope('my')">我的任务</button>
              <button v-if="permissions.canViewAllTasks" type="button" :class="{ 'care-segment__active': activeTaskScope === 'all' }" @click="selectTaskScope('all')">全部任务</button>
            </div>
            <select v-model="taskStatus" class="care-select" aria-label="任务状态"><option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select>
            <label class="care-time-range"><span>计划时间</span><input v-model="taskPlannedFrom" type="date" aria-label="计划开始日期" @change="$event.target.blur()"><span>至</span><input v-model="taskPlannedTo" type="date" aria-label="计划结束日期" @change="$event.target.blur()"></label>
            <button type="submit" class="care-button care-button--primary">查询</button>
            <button v-if="permissions.canCreateTask" type="button" class="care-button" @click="openCreateTaskPage"><Plus :size="15" aria-hidden="true" />新增任务</button>
          </div>
          <div class="care-query__row care-query__row--filters">
            <button v-for="bucket in taskBuckets" :key="bucket.key" type="button" class="care-filter" :class="{ 'care-filter--active': activeTaskBucket === bucket.key }" @click="selectTaskBucket(bucket.key)">
              {{ bucket.label }}<span v-if="bucketCounts[bucket.key] !== undefined">{{ bucketCounts[bucket.key] }}</span>
            </button>
            <span class="care-query__as-of">数据时间 {{ care.dataAsOf || '—' }}</span>
          </div>
        </form>

        <main class="care-table-wrap">
          <table class="care-table care-table--tasks">
            <thead><tr><th>计划时间</th><th>会员</th><th>任务</th><th>来源</th><th>当前负责人</th><th>状态</th><th>关联业务</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="task in displayedTasks" :key="task.taskId" @dblclick="openTask(task)">
                <td><strong>{{ task.plannedAt || '—' }}</strong></td>
                <td><strong>{{ task.member?.name || '—' }}</strong><small>{{ task.member?.phone || '—' }}</small></td>
                <td>{{ task.typeLabel || '—' }}<small>{{ task.taskNo || '—' }}</small></td>
                <td>{{ task.sourceLabel || '—' }}</td>
                <td>{{ task.owner?.name || '—' }}</td>
                <td><span class="care-status" :class="statusTone(task.status)">{{ task.statusLabel || task.status }}</span><strong v-if="task.isOverdue" class="care-overdue">已逾期</strong></td>
                <td>{{ task.relatedBusiness?.label || '—' }}</td>
                <td><button type="button" class="care-link" @click="openTask(task)">查看详情</button></td>
              </tr>
            </tbody>
          </table>
          <div v-if="!displayedTasks.length" class="care-empty">当前条件下没有后端返回的跟进任务。</div>
        </main>
      </section>

      <section v-else-if="activeTab === 'customers'" class="care-workspace" aria-label="客户视图">
        <form class="care-query care-query--single" @submit.prevent="loadCareProjection">
          <div class="care-query__row"><label class="care-search"><Search :size="16" aria-hidden="true" /><input v-model="customerKeyword" placeholder="搜索会员姓名、手机号或专属服务人"></label><button class="care-button care-button--primary">查询</button><span v-if="customerMemberId" class="care-query__location">已定位会员 <button type="button" :title="'清除会员定位'" aria-label="清除会员定位" @click="clearCustomerMemberFilter"><X :size="14" aria-hidden="true" /></button></span><span class="care-query__as-of">数据时间 {{ care.dataAsOf || '—' }}</span></div>
        </form>
        <main class="care-table-wrap"><table class="care-table"><thead><tr><th>会员</th><th>归属门店</th><th>专属服务人</th><th>最近服务</th><th>最近跟进</th><th>下一任务</th><th>逾期</th></tr></thead><tbody><tr v-for="customer in displayedCustomers" :key="customer.memberId"><td><strong>{{ customer.name }}</strong><small>{{ customer.phone }}</small></td><td>{{ customer.storeName }}</td><td>{{ customer.exclusiveServiceName }}</td><td>{{ customer.latestService }}</td><td>{{ customer.latestCare }}</td><td><button v-if="customer.nextTask?.canOpen" type="button" class="care-link care-link--task" @click="openTaskByReference(customer.nextTask)">{{ customer.nextTask.label }}<small>{{ customer.nextTask.taskNo }}</small></button><span v-else>{{ customer.nextTask?.label || '暂无待跟进任务' }}</span></td><td><strong v-if="customer.overdueCount" class="care-overdue">{{ customer.overdueCount }} 项</strong><span v-else>—</span></td></tr></tbody></table><div v-if="!displayedCustomers.length" class="care-empty">当前条件下没有后端返回的客户客情摘要。</div></main>
      </section>

      <section v-else-if="activeTab === 'records'" class="care-workspace" aria-label="客情记录">
        <form class="care-query" @submit.prevent="loadCareProjection">
          <div class="care-query__row"><label class="care-search"><Search :size="16" aria-hidden="true" /><input v-model="recordKeyword" placeholder="搜索会员、跟进内容或跟进人"></label><label class="care-time-range"><span>跟进时间</span><input v-model="recordFollowedFrom" type="date" aria-label="跟进开始日期" @change="$event.target.blur()"><span>至</span><input v-model="recordFollowedTo" type="date" aria-label="跟进结束日期" @change="$event.target.blur()"></label><div class="care-segment" aria-label="数据状态"><button type="button" :class="{ 'care-segment__active': recordDataScope === 'normal' }" @click="recordDataScope = 'normal'; loadCareProjection()">正常数据</button><button type="button" :class="{ 'care-segment__active': recordDataScope === 'all' }" @click="recordDataScope = 'all'; loadCareProjection()">全部数据</button></div><button class="care-button care-button--primary">查询</button><button v-if="permissions.canCreateRecord" type="button" class="care-button" @click="openCommand('create-care-record')"><Plus :size="15" aria-hidden="true" />新增记录</button></div>
          <div class="care-query__row care-query__row--filters"><span class="care-query__hint">任务需先开始再完成；手工记录提交后不可编辑。作废仅用于会员、内容或结果录错，原记录和审计会保留。</span><span class="care-query__as-of">数据时间 {{ care.dataAsOf || '—' }}</span></div>
        </form>
        <main class="care-table-wrap">
          <table v-if="recordStream === 'human'" class="care-table"><thead><tr><th>跟进时间</th><th>会员</th><th>记录类型</th><th>方式</th><th>跟进内容</th><th>结果</th><th>实际跟进人</th><th>创建人</th><th>门店</th><th>状态</th><th>操作</th></tr></thead><tbody><tr v-for="record in displayedRecords" :key="record.recordId"><td>{{ record.followedAt }}</td><td><strong>{{ record.memberName }}</strong></td><td>{{ record.typeLabel }}</td><td>{{ record.methodLabel }}</td><td class="care-table__content">{{ record.content }}</td><td>{{ record.resultLabel }}</td><td>{{ record.actualFollowerName }}</td><td>{{ record.creatorName }}</td><td>{{ record.storeName }}</td><td><span class="care-status" :class="statusTone(record.status)">{{ record.statusLabel }}</span><small v-if="record.voidReason">{{ record.voidReason }}</small></td><td><button type="button" class="care-link" @click="openRecord(record)">查看详情</button><button v-for="action in record.availableActions" :key="action.code" type="button" class="care-link care-link--danger" :disabled="action.enabled === false" @click="handleSubjectAction(action, record)">{{ action.label }}</button></td></tr></tbody></table>
          <table v-else class="care-table"><thead><tr><th>动态时间</th><th>会员</th><th>预约记录号</th><th>预约时间</th><th>项目摘要</th><th>门店</th><th>预约状态</th><th>操作</th></tr></thead><tbody><tr v-for="record in displayedRecords" :key="record.recordId"><td>{{ record.occurredAt }}</td><td><strong>{{ record.memberName }}</strong></td><td>{{ record.appointmentNo }}</td><td>{{ record.appointmentTime }}</td><td>{{ record.projectSummary }}</td><td>{{ record.storeName }}</td><td>{{ record.appointmentStatus }}</td><td><button v-for="action in record.availableActions" :key="action.code" type="button" class="care-link" :disabled="action.enabled === false" @click="handleSubjectAction(action, record)">{{ action.label }}</button></td></tr></tbody></table>
          <div v-if="!displayedRecords.length" class="care-empty">当前条件下没有后端返回的{{ recordStream === 'human' ? '人工客情记录' : '预约动态' }}。</div>
        </main>
      </section>

      <section v-else-if="activeTab === 'statistics'" class="care-workspace care-statistics" aria-label="跟进统计">
        <header class="care-section-heading"><div><h2>跟进统计</h2><p>统计值、员工归属和数据新鲜度均由后端返回。</p></div><span>数据时间 {{ care.dataAsOf || '—' }}</span></header>
        <div v-if="permissions.canViewStatistics" class="care-metrics"><section v-for="metric in statistics.metrics || []" :key="metric.code"><span>{{ metric.label }}</span><button type="button" class="care-metric-value" :aria-label="`查看${metric.label}明细`" @click="openStatisticsDetail(metric.code)"><strong>{{ metric.value }}<small>{{ metric.unit }}</small></strong></button><p>{{ metric.userReady ? metric.description : '口径说明待产品确认' }}</p></section></div>
        <main v-if="permissions.canViewStatistics" class="care-table-wrap"><table class="care-table"><thead><tr><th>员工</th><th>当前开放工作量</th><th>按实际跟进人完成</th><th>当前逾期</th></tr></thead><tbody><tr v-for="employee in statistics.employeeRows || []" :key="employee.employeeId"><td><strong>{{ employee.name }}</strong></td><td><button type="button" class="care-statistic-link" :aria-label="`查看${employee.name}当前开放工作量明细`" @click="openStatisticsDetail('employee_current_open_workload', employee.staffId)">{{ employee.currentOpenWorkload }}</button></td><td><button type="button" class="care-statistic-link" :aria-label="`查看${employee.name}按实际跟进人完成明细`" @click="openStatisticsDetail('employee_completed_by_actual_follower', employee.staffId)">{{ employee.completedByActualFollower }}</button></td><td><button type="button" class="care-statistic-link" :class="{ 'care-overdue': employee.currentOverdue > 0 }" :aria-label="`查看${employee.name}当前逾期明细`" @click="openStatisticsDetail('employee_current_overdue', employee.staffId)">{{ employee.currentOverdue }}</button></td></tr></tbody></table></main>
        <div v-else class="care-empty">当前账号没有跟进统计查看权限。</div>
      </section>

      <section v-else class="care-workspace care-settings" aria-label="回访设置">
        <header class="care-section-heading"><div><h2>服务后回访规则</h2><p>首期仅处理真实服务完成后的自动回访。</p></div><span>数据时间 {{ care.dataAsOf || '—' }}</span></header>
        <main class="care-table-wrap"><table class="care-table"><thead><tr><th>项目</th><th>门店</th><th>安排时间</th><th>负责人规则</th><th>状态</th><th>操作</th></tr></thead><tbody><tr v-for="rule in settings.rules || []" :key="rule.ruleId"><td><strong>{{ rule.projectName }}</strong></td><td>{{ rule.storeName }}</td><td>{{ rule.delayLabel }}</td><td>{{ rule.ownerRuleLabel }}</td><td><span class="care-status" :class="rule.enabled ? 'care-status--completed' : 'care-status--voided'">{{ rule.statusLabel }}</span></td><td><button v-for="action in rule.availableActions" :key="action.code" type="button" class="care-link" :disabled="action.enabled === false || !permissions.canManageRules" @click="handleSubjectAction(action, rule)">{{ action.label }}</button></td></tr></tbody></table></main>
        <header class="care-section-heading care-section-heading--exceptions"><div><h2>自动任务异常</h2><p>无法解析有效负责人时保留异常，不静默改派。</p></div></header>
        <main class="care-table-wrap care-table-wrap--exceptions"><table class="care-table"><thead><tr><th>发生时间</th><th>会员</th><th>来源</th><th>异常原因</th><th>状态</th><th>操作</th></tr></thead><tbody><tr v-for="exception in settings.exceptions || []" :key="exception.exceptionId"><td>{{ exception.occurredAt }}</td><td><strong>{{ exception.memberName }}</strong></td><td>{{ exception.sourceLabel }}</td><td class="care-table__content">{{ exception.reason }}</td><td>{{ exception.statusLabel }}</td><td><button v-for="action in exception.availableActions" :key="action.code" type="button" class="care-link" :disabled="action.enabled === false || !permissions.canHandleExceptions" @click="handleSubjectAction(action, exception)">{{ action.label }}</button></td></tr></tbody></table></main>
      </section>
    </template>

    <CareTaskDetailDrawer v-if="isTaskDetailOpen" :task="selectedTask" :pending-action="pendingAction" @close="closeTask" @action="handleTaskAction" />
    <CareRecordDetailDrawer v-if="isRecordDetailOpen" :record="selectedRecord" :pending-action="pendingAction" @close="closeRecord" @action="handleSubjectAction($event, selectedRecord)" />
    <CareMetricDetailDrawer v-if="statisticsDetail" :detail="statisticsDetail" :loading="statisticsDetailLoading" :error="statisticsDetailError" @close="closeStatisticsDetail" @load-more="loadMoreStatisticsDetail" @open-task="openTask" @open-record="openRecord" />
    <CareCommandDrawer v-if="commandMode" :mode="commandMode" :task="commandTask" :subject="commandSubject" :selected-member="commandMember" :preparation="commandPreparation" :is-submitting="isCommandSubmitting" :error="commandError" @close="closeCommand" @request-member-selector="openCareRecordMemberSelector" @submit="submitCommand" />
    </template>
  </section>
</template>

<style scoped>
.care-page { display:grid; grid-template-rows:auto auto auto minmax(0, 1fr); gap:10px; width:100%; height:100%; min-width:0; min-height:0; padding:14px 16px 16px; color:#303b49; }
.care-create-page { display:grid; grid-template-rows:auto minmax(0, 1fr); width:100%; height:100%; min-height:0; border:1px solid #dce3eb; border-radius:7px; background:#fff; }
.care-create-page__header { display:flex; align-items:center; justify-content:space-between; gap:20px; padding:18px 22px; border-bottom:1px solid #e5eaf0; }
.care-create-page__header h2 { margin:0; color:#263241; font-size:20px; }
.care-create-page__header p { margin:5px 0 0; color:#7b8794; font-size:13px; }
.care-create-page__form { display:grid; align-content:start; gap:18px; max-width:900px; width:100%; padding:24px 28px; overflow:auto; }
.care-create-page__field { display:grid; gap:7px; color:#445061; font-size:13px; font-weight:600; }
.care-create-page__field strong { color:#d92d20; }
.care-create-page__field input, .care-create-page__field select, .care-create-page__field textarea { width:100%; min-height:40px; padding:8px 11px; border:1px solid #cfd8e3; border-radius:6px; outline:none; background:#fff; color:#263241; font-size:13px; font-weight:400; }
.care-create-page__field textarea { resize:vertical; line-height:1.65; }
.care-create-page__member { display:grid; grid-template-columns:minmax(0, 1fr) auto; gap:8px; }
.care-create-page__columns { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.care-create-page__date-time { display:grid; grid-template-columns:1.25fr .75fr; gap:12px; }
.care-create-page__error { margin:0; padding:10px 12px; border:1px solid #ffccc7; border-radius:6px; background:#fff2f0; color:#b42318; font-size:12px; }
.care-create-page__footer { display:flex; justify-content:flex-end; gap:8px; padding-top:6px; }
.care-tabs { display:flex; align-items:center; gap:2px; min-height:45px; padding:0 4px; border-bottom:1px solid #dbe2ea; background:#fff; }
.care-tabs button { display:inline-flex; align-items:center; gap:7px; height:45px; padding:0 16px; border:0; border-bottom:2px solid transparent; background:transparent; color:#647182; font-size:14px; font-weight:600; }
.care-tabs button:hover { color:#176fd1; }
.care-tabs button:disabled { cursor:not-allowed; color:#aab2bd; }
.care-tabs button:disabled:hover { color:#aab2bd; }
.care-tabs .care-tabs__button--active { border-bottom-color:#176fd1; color:#176fd1; }
.care-preview-banner, .care-feedback { display:flex; min-height:36px; align-items:center; gap:10px; padding:7px 11px; border:1px solid #f1d58c; border-radius:6px; background:#fffaf0; color:#7a5a13; font-size:12px; }
.care-preview-banner span { flex:none; padding:2px 6px; border-radius:4px; background:#f1d58c; color:#5d430b; font-weight:700; }
.care-preview-banner p { margin:0; }
.care-feedback { justify-content:space-between; border-color:#b7e0c4; background:#f3fbf5; color:#27633c; }
.care-feedback--error { border-color:#ffccc7; background:#fff2f0; color:#b42318; }
.care-feedback__actions { display:flex; flex:none; align-items:center; gap:8px; }
.care-feedback button { border:0; background:transparent; color:inherit; font-size:12px; }
.care-page__state { display:grid; height:100%; place-content:center; justify-items:center; gap:8px; color:#7b8794; text-align:center; }
.care-page__state strong { color:#354152; font-size:16px; }
.care-page__state span { max-width:520px; font-size:13px; line-height:1.65; }
.care-page__state--error svg { color:#9aa5b1; }
.care-workspace { display:grid; grid-template-rows:auto minmax(0, 1fr); min-width:0; min-height:0; overflow:hidden; border:1px solid #dce3eb; border-radius:7px; background:#fff; }
.care-query { display:grid; gap:8px; padding:11px 12px 10px; border-bottom:1px solid #e5eaf0; background:#fbfcfd; }
.care-query--single { gap:0; }
.care-query__row { display:flex; align-items:center; gap:8px; min-width:0; }
.care-query__row--filters { min-height:31px; }
.care-search { display:flex; flex:1 1 300px; max-width:430px; height:37px; align-items:center; gap:7px; padding:0 10px; border:1px solid #cfd8e3; border-radius:6px; background:#fff; color:#8b96a3; }
.care-search:focus-within { border-color:#4096ff; box-shadow:0 0 0 3px rgba(64, 150, 255, .1); }
.care-search input { min-width:0; width:100%; height:100%; padding:0; border:0; outline:none; background:transparent; color:#303b49; font-size:13px; }
.care-segment { display:inline-flex; flex:none; height:37px; align-items:center; padding:3px; border:1px solid #d8e0e9; border-radius:6px; background:#f2f4f7; }
.care-segment button { height:29px; padding:0 11px; border:0; border-radius:4px; background:transparent; color:#667384; font-size:12px; }
.care-segment .care-segment__active { background:#fff; color:#176fd1; font-weight:700; box-shadow:0 1px 3px rgba(29, 43, 61, .1); }
.care-select { height:37px; padding:0 28px 0 10px; border:1px solid #cfd8e3; border-radius:6px; background:#fff; color:#465364; font-size:13px; }
.care-time-range { display:inline-flex; flex:none; height:37px; align-items:center; gap:6px; color:#657283; font-size:12px; white-space:nowrap; }
.care-time-range input { width:174px; height:37px; padding:0 8px; border:1px solid #cfd8e3; border-radius:6px; background:#fff; color:#465364; font:inherit; }
.care-time-range input:focus { outline:none; border-color:#4096ff; box-shadow:0 0 0 3px rgba(64, 150, 255, .1); }
.care-button { display:inline-flex; flex:none; height:37px; align-items:center; justify-content:center; gap:5px; padding:0 13px; border:1px solid #cfd8e3; border-radius:6px; background:#fff; color:#465364; font-size:13px; font-weight:600; }
.care-button--primary { border-color:#176fd1; background:#176fd1; color:#fff; }
.care-filter { display:inline-flex; height:29px; align-items:center; gap:6px; padding:0 10px; border:1px solid transparent; border-radius:5px; background:transparent; color:#667384; font-size:12px; }
.care-filter span { min-width:18px; height:18px; padding:0 5px; border-radius:9px; background:#eef1f5; color:#788493; line-height:18px; text-align:center; }
.care-filter--active { border-color:#b9d4ff; background:#edf5ff; color:#176fd1; font-weight:700; }
.care-filter--active span { background:#176fd1; color:#fff; }
.care-query__as-of { flex:none; margin-left:auto; color:#8b96a3; font-size:11px; }
.care-query__location { display:inline-flex; flex:none; height:27px; align-items:center; gap:5px; padding:0 5px 0 9px; border:1px solid #b9d4ff; border-radius:5px; background:#edf5ff; color:#176fd1; font-size:12px; }
.care-query__location button { display:grid; width:20px; height:20px; place-items:center; padding:0; border:0; border-radius:4px; background:transparent; color:inherit; }
.care-query__location button:hover { background:#d8eaff; }
.care-query__hint { color:#7b8794; font-size:12px; }
.care-table-wrap { min-width:0; min-height:0; overflow:auto; }
.care-table { width:100%; min-width:980px; border-collapse:collapse; table-layout:auto; }
.care-table th, .care-table td { padding:11px 12px; border-bottom:1px solid #edf0f4; color:#4c5969; font-size:12px; line-height:1.45; text-align:left; vertical-align:middle; white-space:nowrap; }
.care-table th { position:sticky; top:0; z-index:1; background:#f7f9fb; color:#6e7a89; font-weight:600; }
.care-table tbody tr:hover { background:#f8fbff; }
.care-table td > strong { color:#293544; font-size:13px; }
.care-table td > small { display:block; margin-top:3px; color:#8b96a3; font-size:11px; }
.care-table__content { max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap !important; }
.care-status { display:inline-flex; min-height:22px; align-items:center; padding:0 7px; border-radius:4px; background:#edf5ff; color:#176fd1; font-size:11px; font-weight:700; }
.care-status--completed, .care-status--normal { background:#eaf8ef; color:#1c7a43; }
.care-status--voided { background:#f0f2f4; color:#697586; }
.care-status--in_progress { background:#fff5df; color:#8a5a00; }
.care-overdue { display:block; margin-top:3px; color:#a61b29 !important; font-size:11px !important; }
.care-link { padding:2px 0; border:0; background:transparent; color:#176fd1; font-size:12px; font-weight:600; }
.care-link--task { display:inline-grid; gap:2px; text-align:left; }
.care-link--task small { color:#7b8794; font-size:11px; font-weight:400; }
.care-link--danger { color:#b42318; }
.care-link:disabled { color:#aab2bd; }
.care-empty { display:grid; min-height:180px; place-items:center; color:#8b96a3; font-size:13px; }
.care-section-heading { display:flex; min-height:68px; align-items:center; justify-content:space-between; gap:16px; padding:12px 15px; border-bottom:1px solid #e5eaf0; background:#fbfcfd; }
.care-section-heading h2 { margin:0; color:#283545; font-size:15px; }
.care-section-heading p { margin:4px 0 0; color:#7b8794; font-size:12px; }
.care-section-heading > span { color:#8b96a3; font-size:11px; }
.care-statistics { grid-template-rows:auto auto minmax(0, 1fr); }
.care-metrics { display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); border-bottom:1px solid #e5eaf0; }
.care-metrics section { min-width:0; padding:15px; border-right:1px solid #edf0f4; }
.care-metrics section:last-child { border-right:0; }
.care-metrics span { color:#6f7b8a; font-size:12px; }
.care-metrics strong { display:block; margin-top:7px; color:#263241; font-size:23px; line-height:1; }
.care-metrics strong small { margin-left:3px; color:#7b8794; font-size:11px; font-weight:500; }
.care-metrics p { margin:8px 0 0; color:#98a2ad; font-size:11px; }
.care-metric-value, .care-statistic-link { padding:0; border:0; background:transparent; color:inherit; font:inherit; text-align:left; }
.care-metric-value:hover strong, .care-metric-value:focus-visible strong, .care-statistic-link:hover, .care-statistic-link:focus-visible { color:#176fd1; text-decoration:underline; outline:none; }
.care-statistic-link { color:#293544; font-size:13px; font-weight:700; }
.care-settings { grid-template-rows:auto minmax(120px, 1fr) auto minmax(100px, .72fr); }
.care-section-heading--exceptions { min-height:60px; border-top:1px solid #dce3eb; }
.care-table-wrap--exceptions { min-height:100px; }
@media (max-width: 1280px) {
  .care-page { padding-right:12px; padding-left:12px; }
  .care-tabs button { padding-right:10px; padding-left:10px; }
  .care-search { max-width:340px; }
  .care-time-range { flex-wrap:wrap; height:auto; }
  .care-metrics { grid-template-columns:repeat(3, minmax(0, 1fr)); }
  .care-metrics section:nth-child(3) { border-right:0; }
}
@media (max-width: 980px) {
  .care-page { gap:7px; padding:9px; }
  .care-tabs { overflow-x:auto; }
  .care-tabs button { flex:none; }
  .care-query__row { flex-wrap:wrap; }
  .care-query__as-of { margin-left:0; }
  .care-metrics { grid-template-columns:repeat(2, minmax(0, 1fr)); }
}
</style>
