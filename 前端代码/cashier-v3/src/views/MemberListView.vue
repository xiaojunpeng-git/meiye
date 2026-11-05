<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import TablePagination from '@/components/common/TablePagination.vue'
import MemberCreatorPanel from '@/components/member/MemberCreatorPanel.vue'
import MemberReportQueryToolbar from '@/components/query/MemberReportQueryToolbar.vue'
import { useUnifiedQueryPage } from '@/composables/useUnifiedQueryPage'
import {
  canUseCashierV3Operation,
  formatMoney,
  openCashierV3QueryEntitySelector,
  requestCashierV3Action,
  useCashierV3State
} from '@/services/cashierV3Bridge'
import {
  cloneUnifiedQuerySnapshot,
  extractUnifiedQueryMemberProjection,
  unwrapUnifiedQueryEnvelope,
  unifiedQueryActionMessage,
  unifiedQueryActionSucceeded
} from '@/services/unifiedQueryContract'
import { readStoreV3SessionToken } from '@/services/storeV3SessionToken'
import { memberLoginAdapter } from '@/services/memberLoginApi'
// 只允许宿主注入本模块的已认证适配器；门店入口继续使用原会话和命令网关。
const props = defineProps({ platformAdapter: { type: Object, default: null } })
const loginAdapter = memberLoginAdapter(Boolean(props.platformAdapter))

const state = props.platformAdapter?.state || useCashierV3State()
// 门店列表复用现有欠款开关；平台适配器继续沿用独立字段配置，不改写历史欠款或保存偏好。
const debtPaymentEnabled = computed(() => Boolean(props.platformAdapter) || state.cashier?.debtPaymentEnabled !== false)
const canCreateMember = computed(() => props.platformAdapter ? props.platformAdapter.permissions.create === true : canUseCashierV3Operation('cashier.v3.member.create'))
const canEditMember = computed(() => props.platformAdapter ? props.platformAdapter.permissions.edit === true : canUseCashierV3Operation('cashier.v3.member.edit'))
const selectedMemberIds = ref([])
const isMemberCreatorOpen = ref(false)
const editingMember = ref(null)
const memberEditOpenError = ref('')
const deletingMember = ref(null)
const restoringMember = ref(null)
const memberRestoreError = ref('')
const isMemberMutationSaving = ref(false)
const memberEditConflictMessage = ref('')
const memberSaveFeedback = ref(null)
const memberSaveFeedbackButton = ref(null)

const EMPTY_MEMBER_QUERY_PROJECTION = Object.freeze({
  records: [],
  total: 0,
  page: 1,
  pageSize: 20,
  summaries: {},
  groups: [],
  querySettings: {},
  dataAsOf: ''
})
const memberQueryProjection = ref(null)
const lastSuccessfulQuery = ref(null)
const isQueryLoading = ref(false)
const queryError = ref('')
const memberCenter = computed(() => ({
  ...(state.memberCenter || {}),
  // 没有本次统一查询 projection 时，必须覆盖旧 memberCenter 的列表、总数和聚合，
  // 不能把失败请求悄悄回退为之前账号／门店的旧列表。
  ...(memberQueryProjection.value || EMPTY_MEMBER_QUERY_PROJECTION)
}))
const records = computed(() => Array.isArray(memberCenter.value.records) ? memberCenter.value.records : [])
const statusOptions = computed(() => Array.isArray(memberCenter.value.statusOptions) ? memberCenter.value.statusOptions : [])
const creatorSchema = ref(props.platformAdapter?.creatorSchema || {})
const hasSuccessfulQuery = computed(() => Boolean(lastSuccessfulQuery.value) && Boolean(memberQueryProjection.value) && !queryError.value)
const canBatchOperate = computed(() => hasSuccessfulQuery.value && !isQueryLoading.value && Boolean(memberCenter.value.canBatchOperate))
const isAllSelected = computed(() => records.value.length > 0 && selectedMemberIds.value.length === records.value.length)
const total = computed(() => Math.max(Number(memberCenter.value.total) || 0, records.value.length))
const page = computed(() => Math.max(1, Number(memberCenter.value.page) || 1))
const pageSize = computed(() => Math.max(1, Number(memberCenter.value.pageSize) || 20))

const MEMBER_QUERY_PAGE_CODE = props.platformAdapter ? 'platform_member_list' : 'member_list'
const baseQueryFields = [
  { key: 'member_name', label: '会员姓名', defaultVisible: true, defaultQuick: true, recordKey: 'name', display: 'member-link' },
  { key: 'phone', label: '完整手机号', defaultVisible: true, defaultQuick: true, recordKey: 'phone' },
  { key: 'member_no', label: '会员编号', defaultVisible: true, recordKey: 'memberNo', display: 'member-link' },
  { key: 'member_status', label: '会员状态', defaultVisible: true, type: 'enum', recordKey: 'status', display: 'status' },
  { key: 'member_level', label: '会员等级', defaultVisible: true, recordKey: 'level' },
  { key: 'member_tag', label: '会员标签', defaultVisible: true, recordKey: 'tags', display: 'tag-list' },
  { key: 'store', label: '归属门店', defaultVisible: true, type: 'store', recordKey: 'storeName' },
  { key: 'exclusive_service_staff', label: '专属服务人', defaultVisible: true, type: 'person', recordKey: 'exclusiveServiceStaff', emptyText: '待分配' },
  { key: 'account_balance', label: '账户余额', defaultVisible: true, type: 'number', recordKey: 'accountBalance', display: 'money' },
  { key: 'active_card_count', label: '有效卡项数量', defaultVisible: true, type: 'number', recordKey: 'activeCardCount', display: 'count' },
  { key: 'remaining_project_times', label: '剩余项目次数', defaultVisible: true, type: 'number', recordKey: 'remainingProjectTimes', display: 'count' },
  { key: 'remaining_project_amount', label: '剩余项目金额', defaultVisible: true, type: 'number', recordKey: 'remainingProjectAmount', display: 'money' },
  { key: 'debt_amount', label: '欠款金额', defaultVisible: true, type: 'number', recordKey: 'debtAmount', display: 'debt-money' },
  { key: 'total_consumption_amount', label: '总消费金额', defaultVisible: true, type: 'number', recordKey: 'totalConsumptionAmount', display: 'money' },
  { key: 'visit_count', label: '到店次数', defaultVisible: true, type: 'number', recordKey: 'visitCount', display: 'count' },
  { key: 'latest_purchase_date', label: '最近购买日期', defaultVisible: true, type: 'date', recordKey: 'latestPurchaseDate' },
  { key: 'last_service_staff', label: '上次服务人员', defaultVisible: true, type: 'person', recordKey: 'lastServiceStaff' },
  { key: 'latest_visit_date', label: '最近到店日期', defaultVisible: true, type: 'date', recordKey: 'latestVisitDate' },
  { key: 'created_at', label: '建档时间', defaultVisible: true, type: 'date', recordKey: 'createdAt' },
  { key: 'wechat_binding', label: '微信绑定信息', defaultVisible: true, recordKey: 'wechatBinding' }
]

const unifiedQuery = useUnifiedQueryPage({
  pageCode: MEMBER_QUERY_PAGE_CODE,
  pageName: '会员列表',
  baseFields: baseQueryFields,
  requestAction
})
const queryCapability = unifiedQuery.capability
const queryFields = unifiedQuery.fields
const effectiveQuerySettings = computed(() => {
  if (queryCapability.value?.enabled && queryCapability.value?.querySettings) {
    return queryCapability.value.querySettings
  }
  return memberCenter.value.querySettings || {}
})
const queryDataAsOf = computed(() => memberCenter.value.dataAsOf
  || memberCenter.value.data_as_of
  || queryCapability.value?.dataAsOf
  || '')
const defaultVisibleFieldKeys = computed(() => queryFields.value.filter((field) => field.defaultVisible !== false).map((field) => field.key))
const configuredVisibleFieldKeys = ref([...defaultVisibleFieldKeys.value])
const queryFieldMap = computed(() => new Map(queryFields.value.map((field) => [field.key, field])))
const visibleFields = computed(() => {
  // 仅调整列表展示，不删除标签元数据或改写账号已保存的筛选/导出配置。
  // 两列都获准显示时，会员编号紧邻建档时间；不擅自补回被配置隐藏的列。
  const keys = configuredVisibleFieldKeys.value.filter((key) => key !== 'member_tag'
    && (key !== 'debt_amount' || debtPaymentEnabled.value))
  if (keys.includes('member_no') && keys.includes('created_at')) {
    keys.splice(keys.indexOf('member_no'), 1)
    keys.splice(keys.indexOf('created_at'), 0, 'member_no')
  }
  // 新增绑定列只呈现服务端授权的字段，固定紧邻建档时间右侧。
  if (keys.includes('wechat_binding') && keys.includes('created_at')) {
    keys.splice(keys.indexOf('wechat_binding'), 1)
    keys.splice(keys.indexOf('created_at') + 1, 0, 'wechat_binding')
  }
  return keys.map((key) => queryFieldMap.value.get(key)).filter(Boolean)
})
const summaryEntries = computed(() => {
  const source = memberCenter.value.summaries
  if (!source || typeof source !== 'object' || Array.isArray(source)) return []
  return Object.entries(source).map(([key, value]) => {
    const [fieldKey, operation = ''] = key.split(':')
    const field = queryFieldMap.value.get(fieldKey)
    const operationLabels = { sum: '合计', average: '平均', avg: '平均', minimum: '最小', min: '最小', maximum: '最大', max: '最大', count: '数量' }
    const money = field && ['amount', 'money', 'currency'].includes(String(field.queryType || field.type).toLowerCase())
    return {
      key,
      label: `${field?.label || fieldKey}${operationLabels[operation] ? ` ${operationLabels[operation]}` : ''}`,
      value: money && value !== null && value !== '' ? formatMoney(value) : (value ?? '—')
    }
  })
})
const groupRows = computed(() => (Array.isArray(memberCenter.value.groups) ? memberCenter.value.groups : []).slice(0, 20))
const hasQueryInsights = computed(() => hasSuccessfulQuery.value && !isQueryLoading.value && (summaryEntries.value.length > 0 || groupRows.value.length > 0))

function normalizeVisibleFieldKeys(settings) {
  if (!Array.isArray(settings?.visibleFields)) return [...defaultVisibleFieldKeys.value]
  const uniqueKeys = new Set()
  return settings.visibleFields.filter((key) => {
    if (!queryFieldMap.value.has(key) || uniqueKeys.has(key)) return false
    uniqueKeys.add(key)
    return true
  })
}

function applyQuerySettings(settings) {
  configuredVisibleFieldKeys.value = normalizeVisibleFieldKeys(settings)
}

watch(
  () => [effectiveQuerySettings.value, queryFields.value.map((field) => field.key).join('|')],
  ([settings]) => applyQuerySettings(settings),
  { deep: true, immediate: true }
)

function statusClass(status) {
  if (status === '正常') return 'member-status--normal'
  if (status === '已停用') return 'member-status--disabled'
  return 'member-status--cancelled'
}

function displayCellValue(record, field) {
  const displayValues = record.queryDisplayValues || record.query_display_values || record.customFieldDisplayValues || record.custom_field_display_values
  if (displayValues && Object.prototype.hasOwnProperty.call(displayValues, field.key)) return displayValues[field.key]
  const value = recordFieldValue(record, field)
  if (field.display === 'money' || field.display === 'debt-money') return formatMoney(value)
  if (field.custom === true && ['amount', 'money', 'currency'].includes(field.type) && value !== null && value !== '') return formatMoney(value)
  if (field.display === 'count') return value ?? 0
  if (field.display === 'tag-list') return Array.isArray(value) ? (value.join('、') || '—') : (value || '—')
  return value === undefined || value === null || value === '' ? (field.emptyText || '—') : value
}

function recordFieldValue(record, field) {
  const values = record.queryFieldValues || record.query_field_values || record.customFieldValues || record.custom_field_values
  if (values && Object.prototype.hasOwnProperty.call(values, field.key)) return values[field.key]
  if (field.recordKey && Object.prototype.hasOwnProperty.call(record, field.recordKey)) return record[field.recordKey]
  return record[field.key]
}

function cellClass(record, field) {
  const value = recordFieldValue(record, field)
  return {
    'member-list-table__money': field.display === 'money' || field.display === 'debt-money' || (field.custom === true && ['amount', 'money', 'currency'].includes(field.type)),
    'member-list-table__money--debt': field.display === 'debt-money' && value > 0
  }
}

function toggleMember(memberId) {
  const index = selectedMemberIds.value.indexOf(memberId)
  if (index === -1) selectedMemberIds.value.push(memberId)
  else selectedMemberIds.value.splice(index, 1)
}

function toggleAll() {
  selectedMemberIds.value = isAllSelected.value ? [] : records.value.map(memberRecordId).filter(Boolean)
}

function memberRecordId(record) {
  return record?.id ?? record?.memberId ?? record?.member_id ?? null
}

function groupValueLabel(values) {
  if (!values || typeof values !== 'object') return '未分组'
  return Object.entries(values).map(([fieldKey, value]) => `${queryFieldMap.value.get(fieldKey)?.label || fieldKey}：${value ?? '—'}`).join('；')
}

async function requestAction(action, payload = {}) {
  if (props.platformAdapter) return props.platformAdapter.request(action, payload)
  return requestCashierV3Action(action, payload)
}

// 平台余额保存后回读同一查询页，不重置筛选，也不由客户端重算账户金额。
defineExpose({ refresh: () => queryMembers({}, false) })

function openMemberCreator() {
  if (!canCreateMember.value) return
  memberSaveFeedback.value = null
  isMemberCreatorOpen.value = true
}

// 保存反馈来自共用建档表单；仅会员列表入口弹窗，避免改变收银选会员流程。
function showMemberSaveFeedback({ kind = 'error', message = '' } = {}) {
  memberSaveFeedback.value = {
    kind,
    title: kind === 'success' ? '保存成功' : kind === 'warning' ? '操作提示' : '保存失败',
    message
  }
  nextTick(() => memberSaveFeedbackButton.value?.focus())
}

function dismissMemberSaveFeedback() {
  memberSaveFeedback.value = null
  // 校验错误仍标在原字段上；关闭弹窗后把焦点带回待修正字段。
  nextTick(() => document.querySelector('.member-mutation-modal__card--creator [aria-invalid="true"]')?.focus())
}

function closeMemberCreator() {
  isMemberCreatorOpen.value = false
}

function closeMemberMutation() {
  if (isMemberMutationSaving.value) return
  isMemberCreatorOpen.value = false
  editingMember.value = null
  memberEditConflictMessage.value = ''
}

async function createMemberFromList(payload = {}) {
  if (!canCreateMember.value) return { result: { status: 'failed', message: '当前账号没有新增会员权限。' } }
  // 头像文件不进入 JSON 命令；会员归属门店和组织继续由后端当前会话强制决定。
  const { avatarFile: _avatarFile, ...serializablePayload } = payload && typeof payload === 'object'
    ? payload
    : {}
  return requestAction('create-member', serializablePayload)
}

async function submitMemberMutation(payload = {}) {
  if (editingMember.value && !canEditMember.value) return { result: { status: 'failed', message: '当前账号没有编辑会员权限。' } }
  if (!editingMember.value) return createMemberFromList(payload)
  const { avatarFile: _avatarFile, profileMode: _profileMode, ...serializablePayload } = payload && typeof payload === 'object'
    ? payload
    : {}
  return requestAction('update-member', {
    memberId: editingMember.value.memberId,
    ...serializablePayload,
    commandContexts: memberCommandContexts(editingMember.value.memberId)
  })
}

async function selectMemberCreatorServicePerson({ selectedRecords = [] } = {}) {
  if (props.platformAdapter) return props.platformAdapter.selectPeople(selectedRecords)
  const selection = await openCashierV3QueryEntitySelector({
    entityType: 'person',
    title: '选择专属服务人',
    description: '只显示当前门店有效任职的人员。',
    multiple: true,
    selectedRecords: Array.isArray(selectedRecords) ? selectedRecords : [],
    scope: 'member_exclusive_service_staff',
    selectionContext: {
      storeScope: 'current_store',
      memberCreate: true
    }
  })
  return selection?.selected || null
}

function selectMemberReferrer({ selectedRecord = null, excludeMemberId = 0 } = {}) {
  if (props.platformAdapter) return props.platformAdapter.selectReferrer(selectedRecord, excludeMemberId)
  if (typeof window === 'undefined') return Promise.resolve(null)
  return new Promise((resolve) => {
    const cleanup = () => {
      window.removeEventListener('cashier-v3:member-selector-selected', handler)
      window.removeEventListener('cashier-v3:member-selector-closed', closedHandler)
    }
    const handler = (event) => {
      const detail = event?.detail || {}
      if (detail.context !== 'member-referrer') return
      cleanup()
      const memberId = Number(detail.record?.memberId || detail.record?.uid || detail.record?.id || 0)
      resolve(memberId > 0 && memberId !== Number(excludeMemberId) ? detail.record : null)
    }
    const closedHandler = (event) => {
      if ((event?.detail || {}).context !== 'member-referrer') return
      cleanup()
      resolve(null)
    }
    window.addEventListener('cashier-v3:member-selector-selected', handler)
    window.addEventListener('cashier-v3:member-selector-closed', closedHandler)
    window.dispatchEvent(new CustomEvent('cashier-v3:open-member-selector', {
      detail: {
        context: 'member-referrer',
        initialView: 'selector',
        requireMember: false,
        selectedRecord,
        excludeMemberId
      }
    }))
  })
}

async function handleMemberCreated({ member = null, payload = {} } = {}) {
  closeMemberCreator()
  // 新建会员一定归属当前门店；用唯一手机号刷新默认列表，避免此前的筛选条件
  // 把已成功建档的记录隐藏掉。
  const keyword = String(member?.phone || payload?.phone || '').trim()
  await queryMembers({
    ...initialQueryPayload(queryCapability.value),
    ...(keyword ? { keyword } : {})
  }, true)
}

async function handleMemberMutationSaved({ member = null, payload = {} } = {}) {
  if (editingMember.value) {
    const memberId = editingMember.value.memberId
    closeMemberMutation()
    await requestAction('open-member-editor', { memberId })
    await queryMembers({}, false)
    return
  }
  await handleMemberCreated({ member, payload })
}

let memberQuerySequence = 0

function clearMemberQueryResult(message = '') {
  memberQueryProjection.value = null
  lastSuccessfulQuery.value = null
  selectedMemberIds.value = []
  queryError.value = message
  memberEditOpenError.value = ''
}

async function queryMembers(query = {}, resetPage = true) {
  const sequence = ++memberQuerySequence
  const stateContextId = state.stateContextId
  selectedMemberIds.value = []
  isQueryLoading.value = true
  queryError.value = ''
  try {
    // 初始化和重试共用同一条能力读取路径；失败须显示真实错误，不能伪装成空列表。
    // 仅在服务端重新授权字段后查询，绝不以本地字段白名单降级绕过权限。
    if (queryCapability.value.enabled !== true) {
      const capability = await unifiedQuery.load({ silent: true })
      if (sequence !== memberQuerySequence || stateContextId !== state.stateContextId) return
      if (capability?.enabled !== true) {
        clearMemberQueryResult(capability?.reason || '会员筛选字段加载失败，请重试。')
        return
      }
    }
    const requestedLimit = Math.max(1, Number(query.limit || query.pageSize) || pageSize.value)
    const nextQuery = {
      // 分页只能继承已经成功执行的查询；草稿控件或一次失败请求都不能成为导出／分页基线。
      ...cloneUnifiedQuerySnapshot(lastSuccessfulQuery.value || initialQueryPayload(queryCapability.value)),
      ...cloneUnifiedQuerySnapshot(query),
      page: resetPage ? 1 : Math.max(1, Number(query.page) || page.value),
      limit: requestedLimit
    }
    delete nextQuery.pageSize
    const result = await requestAction('query-members', nextQuery)
    if (sequence !== memberQuerySequence || stateContextId !== state.stateContextId) return result
    const projection = extractUnifiedQueryMemberProjection(result)
    if (!projection) {
      clearMemberQueryResult(unifiedQueryActionSucceeded(result)
        ? '查询结果格式不完整，请刷新后重试。'
        : unifiedQueryActionMessage(result, '会员查询失败，请稍后重试。'))
      return result
    }
    memberQueryProjection.value = projection
    // 只有服务端成功返回完整 projection 后，才把本次条件升级为已执行快照。
    lastSuccessfulQuery.value = cloneUnifiedQuerySnapshot(nextQuery)
    queryError.value = ''
    return result
  } catch (error) {
    if (sequence === memberQuerySequence && stateContextId === state.stateContextId) {
      clearMemberQueryResult(error?.message || '会员查询失败，请稍后重试。')
    }
    return {
      result: {
        status: 'failed',
        code: 'MEMBER_QUERY_REQUEST_FAILED',
        message: error?.message || '会员查询失败，请稍后重试。'
      }
    }
  } finally {
    if (sequence === memberQuerySequence && stateContextId === state.stateContextId) isQueryLoading.value = false
  }
}

function changeMemberPage(pagination) {
  if (isQueryLoading.value || !lastSuccessfulQuery.value) return
  queryMembers(pagination, false)
}

function initialQueryPayload(capability) {
  const settings = capability?.querySettings || {}
  const currentVersions = Object.fromEntries((capability?.fields || [])
    .filter((field) => field?.custom === true && Number(field.version) > 0)
    .map((field) => [field.key, Number(field.version)]))
  return {
    pageCode: MEMBER_QUERY_PAGE_CODE,
    page: 1,
    limit: pageSize.value,
    keyword: '',
    dataScope: 'normal',
    businessStatus: '',
    topFilters: [],
    sorts: Array.isArray(settings.sorts) ? settings.sorts : [],
    filters: Array.isArray(settings.filters) ? settings.filters : [],
    filterRelation: settings.filterRelation === 'any' ? 'any' : 'all',
    groupBy: Array.isArray(settings.groupBy) ? settings.groupBy : [],
    summaries: Array.isArray(settings.summaries) ? settings.summaries : [],
    visibleFields: Array.isArray(settings.visibleFields) ? settings.visibleFields : [],
    fieldVersions: {
      ...currentVersions,
      ...(settings.customFieldVersions || {})
    },
    ...(capability?.queryCutoffDate ? { queryCutoffDate: capability.queryCutoffDate } : {})
  }
}

watch(
  () => state.stateContextId,
  async (stateContextId) => {
    memberQuerySequence += 1
    clearMemberQueryResult()
    isQueryLoading.value = false
    unifiedQuery.reset()
    if (!stateContextId) return
    await queryMembers({}, true)
  },
  { immediate: true }
)

function openMemberDetail(record) {
  if (props.platformAdapter) return props.platformAdapter.openDetail(record)
  const memberId = memberRecordId(record)
  window.dispatchEvent(new CustomEvent('cashier-v3:open-member-detail', {
    detail: { memberId, record }
  }))
  // The shell handles this event and performs the sole authorized detail read.
}

function resultData(response) {
  const envelope = unwrapUnifiedQueryEnvelope(response)
  if (envelope?.data && typeof envelope.data === 'object') return envelope.data
  if (envelope?.result?.data && typeof envelope.result.data === 'object') return envelope.result.data
  return {}
}

function memberCommandContexts(memberId) {
  return [
    { kind: 'cashier_workspace', id: state.workspace?.id },
    { kind: 'member', id: memberId }
  ]
}

function actionResultCode(response) {
  const envelope = response?.result && typeof response.result === 'object'
    ? response
    : response?.data?.result && typeof response.data.result === 'object'
      ? response.data
      : response
  return String(envelope?.result?.code || envelope?.code || '').toUpperCase()
}

function isMemberEditConflict(response) {
  const code = actionResultCode(response)
  return code.includes('CONFLICT') || code.includes('VERSION') || code.includes('CONTEXT')
}

function editableMemberDraft(member) {
  return {
    name: String(member?.name || '').trim(),
    sex: Number(member?.sex) || 0,
    birthday: member?.birthday || '',
    address: String(member?.address || '').trim(),
    note: String(member?.note || '').trim()
  }
}

// 保留已上线的读取失败提示，避免点击编辑后无反馈。
async function openMemberEditor(record) {
  memberEditOpenError.value = ''
  const memberId = memberRecordId(record)
  try {
    const response = await requestAction('open-member-editor', { memberId })
    if (!unifiedQueryActionSucceeded(response)) {
      memberEditOpenError.value = unifiedQueryActionMessage(response, '会员资料读取失败，请重试。')
      return response
    }
    const data = resultData(response)
    if (data.creatorSchema && typeof data.creatorSchema === 'object') creatorSchema.value = data.creatorSchema
    const member = data.member
    if (member && member.memberId) {
      memberSaveFeedback.value = null
      memberEditConflictMessage.value = ''
      editingMember.value = { ...member }
    } else memberEditOpenError.value = '会员资料读取失败，请重试。'
    return response
  } catch (error) {
    memberEditOpenError.value = error?.message || '会员资料读取失败，请重试。'
    return { result: { status: 'failed', message: memberEditOpenError.value } }
  }
}

async function saveMemberEdit() {
  const member = editingMember.value
  if (!member || isMemberMutationSaving.value) return
  const draft = editableMemberDraft(member)
  isMemberMutationSaving.value = true
  try {
    const response = await requestAction('update-member', {
      memberId: member.memberId,
      name: member.name,
      sex: Number(member.sex) || 0,
      birthday: member.birthday || '',
      address: member.address || '',
      note: member.note || '',
      commandContexts: memberCommandContexts(member.memberId)
    })
    if (unifiedQueryActionSucceeded(response)) {
      // Save completion only returns a compact list row. Re-read the editor
      // projection so the next open always starts from the authoritative row.
      await requestAction('open-member-editor', { memberId: member.memberId })
      editingMember.value = null
      memberEditConflictMessage.value = ''
      await queryMembers({}, false)
    } else if (isMemberEditConflict(response)) {
      // A stale member version must never discard what the operator typed.
      // Refresh the authoritative base and layer this unsaved draft over it.
      const refreshed = await requestAction('open-member-editor', { memberId: member.memberId })
      const latest = resultData(refreshed).member
      if (unifiedQueryActionSucceeded(refreshed) && latest?.memberId) {
        editingMember.value = { ...latest, ...draft }
        memberEditConflictMessage.value = '会员资料已被更新，已加载最新资料并保留本次输入，请核对后再次保存。'
      }
    }
  } finally {
    isMemberMutationSaving.value = false
  }
}

async function confirmMemberDelete() {
  const member = deletingMember.value
  if (!member || isMemberMutationSaving.value) return
  isMemberMutationSaving.value = true
  try {
    // 编辑成功会重建完整工作台状态，公开版本仓只保留根分区资源。
    // 注销前重新读取目标会员，重新签发该会员的当前版本，不能用列表旧版本写入。
    const prepared = await requestAction('open-member-editor', { memberId: memberRecordId(member) })
    if (!unifiedQueryActionSucceeded(prepared)) return
    const response = await requestAction('deactivate-member', {
      memberId: memberRecordId(member),
      reason: '门店端会员列表注销',
      commandContexts: memberCommandContexts(memberRecordId(member))
    })
    if (unifiedQueryActionSucceeded(response)) {
      deletingMember.value = null
      await queryMembers({}, false)
    }
  } finally {
    isMemberMutationSaving.value = false
  }
}

async function confirmMemberRestore() {
  const member = restoringMember.value
  if (!member || isMemberMutationSaving.value) return
  const memberId = memberRecordId(member)
  memberRestoreError.value = ''
  isMemberMutationSaving.value = true
  try {
    // 注销会员不允许走编辑投影；恢复前独立读取原 UID 的权威版本。
    const prepared = await requestAction('open-member-restore', { memberId })
    if (!unifiedQueryActionSucceeded(prepared)) {
      memberRestoreError.value = unifiedQueryActionMessage(prepared, '会员状态已变化，请刷新后重试。')
      return
    }
    const response = await requestAction('restore-member', {
      memberId,
      commandContexts: memberCommandContexts(memberId)
    })
    if (!unifiedQueryActionSucceeded(response)) {
      memberRestoreError.value = unifiedQueryActionMessage(response, '会员恢复失败，请稍后重试。')
      return
    }
    restoringMember.value = null
    await queryMembers({}, false)
  } catch (_error) {
    memberRestoreError.value = '会员恢复失败，请稍后重试。'
  } finally {
    isMemberMutationSaving.value = false
  }
}

async function openBatchAction() {
  await requestAction('open-member-batch-actions', {
    selectedMemberIds: selectedMemberIds.value
  })
}

function memberExportDownloadFileName(value) {
  const normalized = String(value || '会员列表.xlsx').replace(/[\\\\/:*?"<>|]+/g, '_').trim() || '会员列表.xlsx'
  return /\.xlsx$/i.test(normalized) ? normalized : `${normalized}.xlsx`
}

async function downloadMemberExport({ url, fileName, task } = {}) {
  const endpoint = new URL(String(url || ''), window.location.origin)
  if (endpoint.origin !== window.location.origin) throw new Error('导出文件地址无效，请刷新后重试。')
  const pageCode = String(task?.pageCode || MEMBER_QUERY_PAGE_CODE).trim()
  if (pageCode !== MEMBER_QUERY_PAGE_CODE) throw new Error('导出任务页面信息无效，请重新创建导出任务。')
  endpoint.searchParams.set('pageCode', pageCode)

  // 会员列表不增加单独的导出权限：能执行当前查询即可导出该查询的完整命中结果。
  // 下载接口仍需要当前登录令牌；普通链接缺少令牌会把失败 JSON 误存为 xlsx，因此落盘前还要校验 ZIP 文件头。
  if (props.platformAdapter) endpoint.pathname = endpoint.pathname.replace('/cashierapi/v3/unified-query/exports/', '/adminapi/user/member-center/exports/')
  const token = props.platformAdapter ? props.platformAdapter.token() : readStoreV3SessionToken()
  const response = await fetch(endpoint.href, {
    credentials: 'same-origin',
    cache: 'no-store',
    headers: {
      Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      ...(token ? { Authorization: `Bearer ${token}`, 'Authori-zation': `Bearer ${token}` } : {})
    }
  })
  const blob = await response.blob()
  const type = String(response.headers.get('content-type') || '').toLowerCase()
  if (!response.ok || type.includes('json')) {
    const message = await blob.text().then((text) => {
      const payload = JSON.parse(text)
      return payload?.msg || payload?.message || ''
    }).catch(() => '')
    throw new Error(message || '导出文件下载失败，请稍后重试。')
  }
  const header = new Uint8Array(await blob.slice(0, 4).arrayBuffer())
  if (blob.size < 4 || header[0] !== 0x50 || header[1] !== 0x4b || header[2] !== 0x03 || header[3] !== 0x04) {
    throw new Error('导出文件格式无效，请重新创建导出任务。')
  }

  const objectUrl = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = objectUrl
  anchor.download = memberExportDownloadFileName(fileName)
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
}
</script>

<template>
  <section class="member-list-page" :class="{ 'member-list-page--batch': canBatchOperate, 'member-list-page--insights': hasQueryInsights }" aria-label="会员列表">
    <!-- A context change remounts drafts and export tasks; member/store data
         must never inherit another operator's in-progress filter state. -->
    <MemberReportQueryToolbar
      :page-code="MEMBER_QUERY_PAGE_CODE"
      :key="state.stateContextId || ''"
      :fields="queryFields"
      :status-options="statusOptions"
      :result-count="total"
      :page-size="pageSize"
      :current-page-count="records.length"
      :data-as-of="queryDataAsOf"
      :query-capability="queryCapability"
      :capability-message="unifiedQuery.loadError.value"
      :executed-query="lastSuccessfulQuery"
      :is-query-loading="isQueryLoading"
      :state-context-key="state.stateContextId || ''"
      :settings="effectiveQuerySettings"
      @query="queryMembers"
      :on-create-export="unifiedQuery.createExport"
      :on-query-export-task="unifiedQuery.queryExportTask"
      :on-download-export="downloadMemberExport"
    >
      <template #primary-actions>
        <button v-if="canCreateMember" type="button" class="button button--primary" @click="openMemberCreator()">新增会员</button>
      </template>
    </MemberReportQueryToolbar>

    <p v-if="memberEditOpenError" class="member-list-query-message member-list-query-message--error" role="alert">{{ memberEditOpenError }}</p>
    <p v-if="isQueryLoading" class="member-list-query-message" role="status">正在查询会员数据…</p>
    <p v-else-if="queryError" class="member-list-query-message member-list-query-message--error" role="alert">{{ queryError }}</p>

    <section v-if="hasQueryInsights" class="member-query-insights" aria-label="查询合计与分组">
      <div v-if="summaryEntries.length" class="member-query-insights__summaries">
        <span v-for="summary in summaryEntries" :key="summary.key"><small>{{ summary.label }}</small><strong>{{ summary.value }}</strong></span>
      </div>
      <div v-if="groupRows.length" class="member-query-insights__groups">
        <span v-for="(group, index) in groupRows" :key="index"><strong>{{ groupValueLabel(group.values) }}</strong><small>{{ Number(group.count) || 0 }} 条</small></span>
      </div>
    </section>

    <div v-if="canBatchOperate" class="member-list-selection-bar">
      <span>已勾选 <strong>{{ selectedMemberIds.length }}</strong> 位会员</span>
      <button type="button" class="button button--secondary" :disabled="!selectedMemberIds.length" @click="openBatchAction">批量操作</button>
    </div>

    <main class="member-list-wrap">
      <table class="member-list-table" :style="{ '--member-column-count': visibleFields.length }">
        <thead>
          <tr>
            <th v-if="canBatchOperate" class="member-list-table__check"><input type="checkbox" :checked="isAllSelected" aria-label="选择当前列表全部会员" @change="toggleAll"></th>
            <th v-for="field in visibleFields" :key="field.key">{{ field.label }}</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="record in records" :key="memberRecordId(record)">
            <td v-if="canBatchOperate" class="member-list-table__check"><input type="checkbox" :checked="selectedMemberIds.includes(memberRecordId(record))" :aria-label="`选择${record.name || record.member_name || '会员'}`" @change="toggleMember(memberRecordId(record))"></td>
            <td v-for="field in visibleFields" :key="field.key" :class="cellClass(record, field)">
              <button v-if="field.display === 'member-link'" type="button" class="member-link" @click="openMemberDetail(record)">{{ displayCellValue(record, field) }}</button>
              <span v-else-if="field.display === 'status'" class="member-status" :class="statusClass(displayCellValue(record, field))">{{ displayCellValue(record, field) }}</span>
              <span v-else-if="field.display === 'tag-list'" class="member-tag-list">{{ displayCellValue(record, field) }}</span>
              <template v-else>{{ displayCellValue(record, field) }}</template>
            </td>
            <td>
              <div class="member-row-actions">
                <button type="button" class="button button--text" @click="openMemberDetail(record)">查看</button>
                <button v-if="platformAdapter" type="button" class="button button--text" @click="platformAdapter.transfer(record)">调店</button>
                <button v-if="platformAdapter && record.status !== '已注销'" type="button" class="button button--text" @click="platformAdapter.adjustBalance(record)">修改余额</button>
                <button v-if="canEditMember && record.status !== '已注销'" type="button" class="button button--text" @click="openMemberEditor(record)">编辑</button>
                <template v-if="!platformAdapter || platformAdapter.permissions.deactivate">
                  <button v-if="record.status !== '已注销'" type="button" class="button button--text button--danger" @click="deletingMember = record">注销</button>
                  <button v-else type="button" class="button button--text" @click="restoringMember = record; memberRestoreError = ''">恢复</button>
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="!records.length && !queryError && !isQueryLoading" class="member-list-empty">暂无符合条件的会员，请调整查询条件或新增会员。</div>
    </main>
    <TablePagination :total="total" :page="page" :page-size="pageSize" @change="changeMemberPage" />

    <Teleport to="body">
      <div v-if="isMemberCreatorOpen || editingMember" class="member-mutation-modal" role="dialog" aria-modal="true" :aria-label="editingMember ? '编辑会员' : '新增会员'">
        <div class="member-mutation-modal__backdrop" @click="closeMemberMutation" />
        <section class="member-mutation-modal__card member-mutation-modal__card--creator">
          <header><h2>{{ editingMember ? '编辑会员' : '新增会员' }}</h2><button type="button" class="button button--text" :disabled="isMemberMutationSaving" @click="closeMemberMutation">关闭</button></header>
          <MemberCreatorPanel
            :login-adapter="loginAdapter"
            initial-profile-mode="full"
            lock-profile-mode
            cancel-label="取消"
            submit-label="保存"
            :mode="editingMember ? 'edit' : 'create'"
            :initial-member="editingMember"
            :allow-select-existing="false"
            save-feedback-as-dialog
            :current-store-name="editingMember?.storeName || state.storeName || ''"
            :profile-fields="creatorSchema.profileFields || creatorSchema.fields || []"
            :member-levels="creatorSchema.memberLevels || creatorSchema.levels || []"
            :member-tags="creatorSchema.memberTags || creatorSchema.tags || []"
            :on-submit="submitMemberMutation"
            :on-select-service-person="selectMemberCreatorServicePerson"
            :on-select-referrer="selectMemberReferrer"
            @cancel="closeMemberMutation"
            @created="handleMemberMutationSaved"
            @save-feedback="showMemberSaveFeedback"
          />
        </section>
      </div>
      <div v-if="memberSaveFeedback" class="member-save-feedback" role="presentation">
        <section class="member-save-feedback__dialog" :class="`member-save-feedback__dialog--${memberSaveFeedback.kind}`" role="dialog" aria-modal="true" aria-labelledby="member-save-feedback-title" aria-describedby="member-save-feedback-message">
          <h2 id="member-save-feedback-title">{{ memberSaveFeedback.title }}</h2>
          <p id="member-save-feedback-message">{{ memberSaveFeedback.message }}</p>
          <footer><button ref="memberSaveFeedbackButton" type="button" class="button button--primary" @click="dismissMemberSaveFeedback">确认</button></footer>
        </section>
      </div>
      <div v-if="deletingMember" class="member-mutation-modal" role="dialog" aria-modal="true" aria-label="注销会员">
        <div class="member-mutation-modal__backdrop" @click="!isMemberMutationSaving && (deletingMember = null)" />
        <section class="member-mutation-modal__card member-mutation-modal__card--confirm">
          <h2>注销会员</h2>
          <p>注销 {{ deletingMember.name || '该会员' }} 后不可再办理新业务，但历史订单、卡项、收款和权益记录会完整保留。</p>
          <footer><button type="button" class="button button--secondary" :disabled="isMemberMutationSaving" @click="deletingMember = null">取消</button><button type="button" class="button button--danger" :disabled="isMemberMutationSaving" @click="confirmMemberDelete">{{ isMemberMutationSaving ? '注销中…' : '确认注销' }}</button></footer>
        </section>
      </div>
      <div v-if="restoringMember" class="member-mutation-modal" role="dialog" aria-modal="true" aria-label="恢复会员">
        <div class="member-mutation-modal__backdrop" @click="!isMemberMutationSaving && (restoringMember = null)" />
        <section class="member-mutation-modal__card member-mutation-modal__card--confirm">
          <h2>恢复会员</h2>
          <p>恢复 {{ restoringMember.name || '该会员' }} 的原手机号及会员身份。原会员资料和历史记录不会变更。</p>
          <p v-if="memberRestoreError" class="member-list-query-message member-list-query-message--error" role="alert">{{ memberRestoreError }}</p>
          <footer><button type="button" class="button button--secondary" :disabled="isMemberMutationSaving" @click="restoringMember = null">取消</button><button type="button" class="button button--primary" :disabled="isMemberMutationSaving" @click="confirmMemberRestore">{{ isMemberMutationSaving ? '恢复中…' : '确认恢复' }}</button></footer>
        </section>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.member-list-query-message {
  margin: 0;
  padding: 9px 12px;
  border: 1px solid #b9d4ff;
  border-radius: 8px;
  background: #f7fbff;
  color: #35658f;
  font-size: 13px;
}

.member-list-query-message--error {
  border-color: #ffccc7;
  background: #fff2f0;
  color: #cf1322;
}

.member-mutation-modal { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 20px; }
.member-mutation-modal__backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, .45); }
.member-mutation-modal__card { position: relative; width: min(520px, 100%); display: grid; gap: 14px; padding: 20px; border-radius: 8px; background: #fff; box-shadow: 0 18px 48px rgba(15, 23, 42, .25); }
.member-mutation-modal__card header, .member-mutation-modal__card footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.member-mutation-modal__card h2 { margin: 0; font-size: 18px; }
.member-mutation-modal__card p { margin: 0; color: #4b5563; line-height: 1.65; }
.member-mutation-modal__conflict { padding: 8px 10px; border: 1px solid #ffe1a8; border-radius: 6px; color: #8a5900 !important; background: #fff9ec; font-size: 13px; }
.member-mutation-modal__card label { display: grid; gap: 6px; color: #374151; font-size: 13px; }
.member-mutation-modal__card input, .member-mutation-modal__card select, .member-mutation-modal__card textarea { width: 100%; min-height: 34px; box-sizing: border-box; padding: 7px 9px; border: 1px solid #d1d5db; border-radius: 4px; font: inherit; }
.member-mutation-modal__card textarea { resize: vertical; }
.member-mutation-modal__card--creator { width: min(980px, 100%); max-height: min(820px, calc(100vh - 40px)); overflow: auto; padding: 24px; }
.member-mutation-modal__card--creator > header { padding-bottom: 4px; border-bottom: 1px solid #edf0f5; }
.member-save-feedback { position: fixed; inset: 0; z-index: 1300; display: grid; place-items: center; padding: 24px; background: rgba(20, 31, 48, .36); }
.member-save-feedback__dialog { width: min(460px, 100%); display: grid; gap: 12px; padding: 22px 24px 20px; border: 1px solid #b9d4ff; border-radius: 8px; background: #fff; box-shadow: 0 18px 44px rgba(15, 23, 42, .28); }
.member-save-feedback__dialog--error { border-color: #ffccc7; background: #fffafa; }
.member-save-feedback__dialog--warning { border-color: #ffd591; background: #fffaf0; }
.member-save-feedback__dialog h2 { margin: 0; color: #1f2329; font-size: 14px; }
.member-save-feedback__dialog p { margin: 0; color: #697586; font-size: 13px; line-height: 1.55; }
.member-save-feedback__dialog footer { display: flex; justify-content: flex-end; }
.member-save-feedback__dialog footer .button { min-width: 88px; }
</style>
