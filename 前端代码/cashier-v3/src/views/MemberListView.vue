<script setup>
import { computed, ref, watch } from 'vue'
import TablePagination from '@/components/common/TablePagination.vue'
import UnifiedQueryToolbar from '@/components/query/UnifiedQueryToolbar.vue'
import { useUnifiedQueryPage } from '@/composables/useUnifiedQueryPage'
import { formatMoney, requestCashierV3Action, useCashierV3State } from '@/services/cashierV3Bridge'
import {
  cloneUnifiedQuerySnapshot,
  extractUnifiedQueryMemberProjection,
  unifiedQueryActionMessage,
  unifiedQueryActionSucceeded
} from '@/services/unifiedQueryContract'

const state = useCashierV3State()
const selectedMemberIds = ref([])

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
const hasSuccessfulQuery = computed(() => Boolean(lastSuccessfulQuery.value) && Boolean(memberQueryProjection.value) && !queryError.value)
const canBatchOperate = computed(() => hasSuccessfulQuery.value && !isQueryLoading.value && Boolean(memberCenter.value.canBatchOperate))
const isAllSelected = computed(() => records.value.length > 0 && selectedMemberIds.value.length === records.value.length)
const total = computed(() => Math.max(Number(memberCenter.value.total) || 0, records.value.length))
const page = computed(() => Math.max(1, Number(memberCenter.value.page) || 1))
const pageSize = computed(() => Math.max(1, Number(memberCenter.value.pageSize) || 20))

const MEMBER_QUERY_PAGE_CODE = 'member_list'
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
  { key: 'created_at', label: '建档时间', defaultVisible: true, type: 'date', recordKey: 'createdAt' }
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
const visibleFields = computed(() => configuredVisibleFieldKeys.value
  .map((key) => queryFieldMap.value.get(key))
  .filter(Boolean))
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
  return requestCashierV3Action(action, payload)
}

async function saveMemberQuerySettings(settings, options = {}) {
  const commandContext = queryCapability.value?.commandContext
  if (!commandContext) {
    return {
      result: {
        status: 'failed',
        code: 'QUERY_PREFERENCE_VERSION_REQUIRED',
        message: '查询设置版本尚未加载，请关闭设置后刷新页面再试。'
      }
    }
  }
  const stateContextId = state.stateContextId
  const result = await requestAction('save-member-query-settings', {
    pageCode: MEMBER_QUERY_PAGE_CODE,
    settings,
    ...(options.idempotencyKey ? { idempotencyKey: options.idempotencyKey } : {}),
    ...(commandContext ? { commandContexts: [commandContext] } : {})
  })
  if (unifiedQueryActionSucceeded(result) && stateContextId === state.stateContextId) {
    await unifiedQuery.load({ silent: true })
  }
  return result
}

let memberQuerySequence = 0

function clearMemberQueryResult(message = '') {
  memberQueryProjection.value = null
  lastSuccessfulQuery.value = null
  selectedMemberIds.value = []
  queryError.value = message
}

async function queryMembers(query = {}, resetPage = true) {
  const sequence = ++memberQuerySequence
  const stateContextId = state.stateContextId
  const requestedLimit = Math.max(1, Number(query.limit || query.pageSize) || pageSize.value)
  const nextQuery = {
    // 分页只能继承已经成功执行的查询；草稿控件或一次失败请求都不能成为导出／分页基线。
    ...cloneUnifiedQuerySnapshot(lastSuccessfulQuery.value),
    ...cloneUnifiedQuerySnapshot(query),
    page: resetPage ? 1 : Math.max(1, Number(query.page) || page.value),
    limit: requestedLimit
  }
  delete nextQuery.pageSize
  selectedMemberIds.value = []
  isQueryLoading.value = true
  queryError.value = ''
  try {
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

let memberBootstrapSequence = 0
watch(
  () => state.stateContextId,
  async (stateContextId) => {
    const bootstrapSequence = ++memberBootstrapSequence
    memberQuerySequence += 1
    clearMemberQueryResult()
    isQueryLoading.value = false
    unifiedQuery.reset()
    if (!stateContextId) return
    const loadedCapability = await unifiedQuery.load({ silent: true })
    if (bootstrapSequence !== memberBootstrapSequence
      || stateContextId !== state.stateContextId
      || loadedCapability?.enabled !== true) return
    await queryMembers(initialQueryPayload(loadedCapability), true)
  },
  { immediate: true }
)

async function openMemberDetail(record) {
  const memberId = memberRecordId(record)
  window.dispatchEvent(new CustomEvent('cashier-v3:open-member-detail', {
    detail: { memberId, record }
  }))
  return requestAction('open-member-detail', {
    memberId,
    recordVersion: record.revision
  })
}

async function openBatchAction() {
  await requestAction('open-member-batch-actions', {
    selectedMemberIds: selectedMemberIds.value
  })
}
</script>

<template>
  <section class="member-list-page" :class="{ 'member-list-page--batch': canBatchOperate, 'member-list-page--insights': hasQueryInsights }" aria-label="会员列表">
    <UnifiedQueryToolbar
      search-placeholder="搜索会员姓名、完整手机号或会员编号"
      settings-button-label="查询设置"
      :status-options="statusOptions"
      :fields="queryFields"
      :page-code="MEMBER_QUERY_PAGE_CODE"
      page-name="会员列表"
      :result-count="total"
      :page-size="pageSize"
      :current-page="page"
      :current-page-count="records.length"
      :data-as-of="queryDataAsOf"
      :query-capability="queryCapability"
      :executed-query="lastSuccessfulQuery"
      :is-query-loading="isQueryLoading"
      :state-context-key="state.stateContextId || ''"
      default-sort-field="建档时间"
      lock-store-selector
      :current-store="state.currentStore"
      :settings="effectiveQuerySettings"
      @query="queryMembers"
      @settings-applied="applyQuerySettings"
      :on-save-settings="saveMemberQuerySettings"
      :on-save-field-aliases="unifiedQuery.saveFieldAliases"
      :on-save-custom-field="unifiedQuery.saveCustomField"
      :on-change-custom-field-status="unifiedQuery.changeCustomFieldStatus"
      :on-archive-custom-field="unifiedQuery.archiveCustomField"
      :on-upgrade-saved-query-field-reference="unifiedQuery.upgradeSavedQueryFieldReference"
      :on-create-export="unifiedQuery.createExport"
      :on-query-export-task="unifiedQuery.queryExportTask"
    />

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
                <button v-if="record.status !== '已注销'" type="button" class="button button--text" @click="requestAction('open-member-editor', { memberId: memberRecordId(record) })">编辑</button>
                <button type="button" class="button button--text" @click="requestAction('open-member-more-actions', { memberId: memberRecordId(record) })">更多</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="!records.length" class="member-list-empty">暂无符合条件的会员，请调整查询条件或新增会员。</div>
    </main>
    <TablePagination :total="total" :page="page" :page-size="pageSize" @change="changeMemberPage" />
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
</style>
