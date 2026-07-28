<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import UnifiedQueryCustomFieldDrawer from '@/components/query/UnifiedQueryCustomFieldDrawer.vue'
import UnifiedQueryExportDrawer from '@/components/query/UnifiedQueryExportDrawer.vue'
import UnifiedQueryFieldRenameDrawer from '@/components/query/UnifiedQueryFieldRenameDrawer.vue'
import UnifiedQuerySettingsDrawer from '@/components/query/UnifiedQuerySettingsDrawer.vue'
import { openCashierV3QueryEntitySelector } from '@/services/cashierV3Bridge'
import {
  cloneUnifiedQuerySnapshot,
  normalizeUnifiedQuerySettings,
  unifiedQueryActionSucceeded
} from '@/services/unifiedQueryContract'

const props = defineProps({
  searchPlaceholder: {
    type: String,
    default: '请输入查询内容'
  },
  settingsButtonLabel: {
    type: String,
    default: '设置'
  },
  quickFilters: {
    type: Array,
    default: () => []
  },
  statusOptions: {
    type: Array,
    default: () => []
  },
  fields: {
    type: Array,
    default: () => []
  },
  defaultSortField: {
    type: String,
    default: '业务时间'
  },
  maxQuickFields: {
    type: Number,
    default: 8
  },
  onSaveSettings: {
    type: Function,
    default: null
  },
  onSelectEntity: {
    type: Function,
    default: null
  },
  lockStoreSelector: {
    type: Boolean,
    default: false
  },
  currentStore: {
    type: Object,
    default: () => ({})
  },
  settings: {
    type: Object,
    default: () => ({})
  },
  pageCode: {
    type: String,
    default: ''
  },
  pageName: {
    type: String,
    default: '当前查询页面'
  },
  resultCount: {
    type: Number,
    default: 0
  },
  pageSize: {
    type: Number,
    default: 20
  },
  currentPageCount: {
    type: Number,
    default: -1
  },
  currentPage: {
    type: Number,
    default: 1
  },
  dataAsOf: {
    type: String,
    default: ''
  },
  // 只有页面在服务端成功返回 projection 后才会传入已执行快照；输入控件本身不是快照。
  executedQuery: {
    type: Object,
    default: null
  },
  isQueryLoading: {
    type: Boolean,
    default: false
  },
  stateContextKey: {
    type: [String, Number],
    default: ''
  },
  queryCapability: {
    type: Object,
    default: () => ({})
  },
  onSaveFieldAliases: {
    type: Function,
    default: null
  },
  onSaveCustomField: {
    type: Function,
    default: null
  },
  onChangeCustomFieldStatus: {
    type: Function,
    default: null
  },
  onArchiveCustomField: {
    type: Function,
    default: null
  },
  onUpgradeSavedQueryFieldReference: {
    type: Function,
    default: null
  },
  onCreateExport: {
    type: Function,
    default: null
  },
  onQueryExportTask: {
    type: Function,
    default: null
  }
})

const emit = defineEmits(['query', 'quick-filter', 'save-settings', 'settings-applied', 'field-aliases-saved', 'custom-field-saved'])

const keyword = ref('')
const dataScope = ref('normal')
const businessStatus = ref('')
const isSettingsOpen = ref(false)
const isCustomFieldOpen = ref(false)
const isFieldRenameOpen = ref(false)
const isExportOpen = ref(false)
const exportTaskReference = ref(null)
const quickFieldValues = reactive({})

function normalizeSettings(settings = {}) {
  const normalized = normalizeUnifiedQuerySettings(settings)
  return {
    ...normalized,
    hasQuickFields: Boolean(
      Array.isArray(settings?.quickFields)
      || Array.isArray(settings?.quick_fields)
      || Array.isArray(settings?.settings?.quickFields)
      || Array.isArray(settings?.settings?.quick_fields)
    ),
    schemaVersion: normalized.schemaVersion || 1
  }
}

const activeSettings = ref(normalizeSettings(props.settings))
const fieldMap = computed(() => new Map(props.fields.filter((field) => !field.hidden && field.key !== 'id').map((field) => [field.key, field])))
const quickFieldMap = computed(() => new Map([...fieldMap.value].filter(([, field]) => (
  !capabilityMatchesPage.value || field.capabilities?.quickFilter === true
))))
const defaultQuickFieldKeys = computed(() => props.fields
  .filter((field) => !field.hidden
    && field.key !== 'id'
    && field.defaultQuick
    && (!capabilityMatchesPage.value || field.capabilities?.quickFilter === true))
  .map((field) => field.key)
  .slice(0, props.maxQuickFields))
const activeQuickFieldKeys = computed(() => {
  const configured = activeSettings.value.quickFields.filter((key) => quickFieldMap.value.has(key)).slice(0, props.maxQuickFields)
  return activeSettings.value.hasQuickFields ? configured : defaultQuickFieldKeys.value
})
const activeQuickFields = computed(() => activeQuickFieldKeys.value.map((key) => quickFieldMap.value.get(key)).filter(Boolean))
const capabilityMatchesPage = computed(() => Boolean(
  props.pageCode
  && props.queryCapability?.enabled === true
  && props.queryCapability?.pageCode === props.pageCode
))
const hasCommandContext = computed(() => Boolean(props.queryCapability?.commandContext?.kind && props.queryCapability?.commandContext?.id))
const canManageCustomFields = computed(() => capabilityMatchesPage.value
  && hasCommandContext.value
  && ['createCustomField', 'editCustomField', 'changeCustomFieldStatus', 'archiveCustomField']
    .some((key) => props.queryCapability?.permissions?.[key] === true))
const canRenameFields = computed(() => capabilityMatchesPage.value
  && hasCommandContext.value
  && props.queryCapability?.permissions?.renameFields === true
  && Number(props.queryCapability?.fieldAliasVersion) > 0)
const exportFields = computed(() => props.fields.filter((field) => (
  !field.hidden
  && field.key !== 'id'
  && field.capabilities?.export === true
)))
const hasExecutedQuerySnapshot = computed(() => Boolean(
  props.executedQuery
  && typeof props.executedQuery === 'object'
  && !Array.isArray(props.executedQuery)
  && Object.keys(props.executedQuery).length
))
const canExport = computed(() => capabilityMatchesPage.value
  && hasCommandContext.value
  && hasExecutedQuerySnapshot.value
  && props.isQueryLoading !== true
  && props.queryCapability?.permissions?.export === true
  && props.queryCapability?.exportCapability?.enabled === true
  && (props.queryCapability?.exportCapability?.allowCurrentQuery === true || props.queryCapability?.exportCapability?.allowCurrentPage === true)
  && exportFields.value.length > 0)
const originalFields = computed(() => props.fields.map((field) => ({
  ...field,
  label: field.originalLabel || field.label
})))
const fixedCurrentStore = computed(() => {
  const source = props.currentStore && typeof props.currentStore === 'object' ? props.currentStore : {}
  return {
    id: source.id || source.storeId || null,
    label: source.name || source.storeName || '当前门店'
  }
})

const normalizedStatusOptions = computed(() => props.statusOptions.map((option) => {
  if (typeof option === 'string') {
    return { value: option, label: option, normal: true }
  }
  return {
    value: option.value ?? option.label,
    label: option.label ?? option.value,
    normal: option.normal !== false && option.isNormal !== false
  }
}))

const availableStatusOptions = computed(() => (dataScope.value === 'all'
  ? normalizedStatusOptions.value
  : normalizedStatusOptions.value.filter((option) => option.normal)))

watch(dataScope, () => {
  if (dataScope.value === 'normal') {
    businessStatus.value = ''
    return
  }
  if (!availableStatusOptions.value.some((option) => option.value === businessStatus.value)) {
    businessStatus.value = ''
  }
})

watch(
  () => props.settings,
  (settings) => {
    activeSettings.value = normalizeSettings(settings)
  },
  { deep: true }
)

watch(
  () => props.stateContextKey,
  () => {
    // 导出任务属于账号／门店上下文，切换后不得继续轮询、展示或下载旧上下文的任务。
    isExportOpen.value = false
    exportTaskReference.value = null
  }
)

function topFieldType(field) {
  const type = String(field.type || '').toLowerCase()
  if (['number', 'amount', 'money', 'currency', 'count', 'times', 'integer', 'decimal'].includes(type)) return 'number'
  if (['date', 'datetime', 'date_time'].includes(type)) return type === 'date' ? 'date' : 'datetime-local'
  return 'text'
}

function topFieldPlaceholder(field) {
  const inputType = topFieldType(field)
  if (inputType === 'number') return `输入${field.label}`
  if (inputType === 'date' || inputType === 'datetime-local') return `选择${field.label}`
  return `请输入${field.label}`
}

function topFieldOperator(field) {
  const type = String(field.type || 'text').toLowerCase()
  return type === 'text' ? 'contains' : 'eq'
}

function isEntityField(field) {
  return ['person', 'store', 'organization'].includes(field.type)
}

function isFixedStoreField(field) {
  return Boolean(props.lockStoreSelector && field?.type === 'store' && fixedCurrentStore.value.id)
}

function entityFieldLabel(field) {
  if (field.type === 'person') return '选择人员'
  if (field.type === 'store') return '选择门店'
  return '选择组织'
}

function displayTopFieldValue(field) {
  if (isFixedStoreField(field)) return fixedCurrentStore.value.label
  const value = quickFieldValues[field.key]
  if (!value) return entityFieldLabel(field)
  return value.label || value.name || value.displayValue || value.id || entityFieldLabel(field)
}

function exportTopFilters() {
  return activeQuickFields.value
    .map((field) => {
      const value = isFixedStoreField(field) ? fixedCurrentStore.value : quickFieldValues[field.key]
      if (value === undefined || value === null || value === '') return null
      return {
        field: field.key,
        operator: topFieldOperator(field),
        value: typeof value === 'object' ? value.id : value
      }
    })
    .filter(Boolean)
}

function currentFieldVersions() {
  const liveVersions = Object.fromEntries(props.fields
    .filter((field) => field.custom === true && Number(field.version) > 0)
    .map((field) => [field.key, Number(field.version)]))
  return {
    ...liveVersions,
    ...(activeSettings.value.customFieldVersions || {})
  }
}

function buildQueryPayload() {
  return {
    ...(props.pageCode ? { pageCode: props.pageCode } : {}),
    page: Math.max(1, Number(props.currentPage) || 1),
    limit: Math.max(1, Number(props.pageSize) || 20),
    ...(capabilityMatchesPage.value && props.queryCapability?.queryCutoffDate
      ? { queryCutoffDate: props.queryCapability.queryCutoffDate }
      : {}),
    keyword: keyword.value,
    dataScope: dataScope.value,
    businessStatus: businessStatus.value,
    topFilters: exportTopFilters(),
    sorts: activeSettings.value.sorts,
    filters: activeSettings.value.filters,
    filterRelation: activeSettings.value.filterRelation,
    groupBy: activeSettings.value.groupBy,
    summaries: activeSettings.value.summaries,
    visibleFields: activeSettings.value.visibleFields,
    fieldVersions: currentFieldVersions()
  }
}

function submitQuery() {
  emit('query', buildQueryPayload())
}

function selectQuickFilter(filter) {
  emit('quick-filter', {
    ...filter,
    query: buildQueryPayload()
  })
}

function selectScope(scope) {
  dataScope.value = scope
  if (scope === 'normal') {
    businessStatus.value = ''
  } else if (!availableStatusOptions.value.some((option) => option.value === businessStatus.value)) {
    businessStatus.value = ''
  }
  submitQuery()
}

async function persistSettings(settings, options = {}) {
  if (!props.onSaveSettings) {
    emit('save-settings', settings)
    return { success: true }
  }
  return props.onSaveSettings(settings, options)
}

async function selectEntity(payload) {
  if (props.onSelectEntity) return props.onSelectEntity(payload)
  return openCashierV3QueryEntitySelector({
    ...payload,
    entityType: payload?.field?.type,
    title: payload?.field?.label ? `选择${payload.field.label}` : ''
  })
}

async function selectTopEntity(field) {
  if (isFixedStoreField(field)) return
  const currentValue = quickFieldValues[field.key]
  const result = await selectEntity({
    scope: 'top_filter',
    field,
    currentValue: typeof currentValue === 'object' ? currentValue.id : currentValue
  })
  const selected = result?.selected || result?.data?.selected
  if (selected) quickFieldValues[field.key] = selected
}

function clearTopField(field) {
  if (isFixedStoreField(field)) return
  quickFieldValues[field.key] = ''
}

function applySettings(settings) {
  activeSettings.value = normalizeSettings(settings)
  activeQuickFields.value.forEach((field) => {
    if (!(field.key in quickFieldValues)) quickFieldValues[field.key] = ''
  })
  emit('settings-applied', { ...activeSettings.value })
  submitQuery()
}

async function saveAliases(aliases, options = {}) {
  if (!canRenameFields.value || !props.onSaveFieldAliases) {
    return { result: { status: 'failed', code: 'FIELD_RENAME_DISABLED', message: '当前账号不能修改字段显示名称。' } }
  }
  const activeKeys = new Set(props.fields.map((field) => field.key))
  const dormantCustomKeys = new Set((props.queryCapability?.customFields || [])
    .filter((field) => field?.key && !activeKeys.has(field.key) && field.status !== 'archived')
    .map((field) => field.key))
  const preservedDormantAliases = Object.fromEntries(Object.entries(props.queryCapability?.fieldAliases || {})
    .filter(([fieldKey]) => dormantCustomKeys.has(fieldKey)))
  const result = await props.onSaveFieldAliases({
    ...preservedDormantAliases,
    ...aliases
  }, options)
  if (unifiedQueryActionSucceeded(result)) emit('field-aliases-saved', aliases)
  return result
}

async function saveCustomField(field) {
  if (!canManageCustomFields.value || !props.onSaveCustomField) {
    return { result: { status: 'failed', code: 'CUSTOM_FIELD_DISABLED', message: '当前账号不能保存自定义字段。' } }
  }
  const result = await props.onSaveCustomField(field)
  if (unifiedQueryActionSucceeded(result)) emit('custom-field-saved', field)
  return result
}

function changeCustomFieldStatus(payload) {
  if (!canManageCustomFields.value || !props.onChangeCustomFieldStatus) {
    return { result: { status: 'failed', code: 'CUSTOM_FIELD_STATUS_DISABLED', message: '当前账号不能更改字段状态。' } }
  }
  return props.onChangeCustomFieldStatus(payload)
}

function archiveCustomField(payload) {
  if (!canManageCustomFields.value || !props.onArchiveCustomField) {
    return { result: { status: 'failed', code: 'CUSTOM_FIELD_ARCHIVE_DISABLED', message: '当前账号不能删除此字段。' } }
  }
  return props.onArchiveCustomField(payload)
}

async function upgradeSavedQueryFieldReference(payload) {
  if (!hasCommandContext.value || !props.onUpgradeSavedQueryFieldReference) {
    return { result: { status: 'failed', code: 'REFERENCE_UPGRADE_DISABLED', message: '当前页面不能升级字段版本，请刷新后重试。' } }
  }
  const result = await props.onUpgradeSavedQueryFieldReference(payload)
  if (unifiedQueryActionSucceeded(result)) {
    // composable 的成功路径已刷新 capability；用同一份服务端偏好重建查询，确保
    // 当前列表、分页、合计和下一次导出都切换到刚刚确认的新字段版本。
    await nextTick()
    const refreshed = props.queryCapability?.querySettings
    if (refreshed && typeof refreshed === 'object') activeSettings.value = normalizeSettings(refreshed)
    applySettings(activeSettings.value)
  }
  return result
}

function createExport(configuration) {
  if (!canExport.value || !props.onCreateExport || !hasExecutedQuerySnapshot.value) {
    return { result: { status: 'failed', code: 'EXPORT_SNAPSHOT_REQUIRED', message: '请先完成一次成功查询，再创建导出任务。' } }
  }
  return props.onCreateExport({
    // 导出冻结“最近一次成功执行”的深拷贝，用户尚未点击查询的输入变化绝不能带入任务。
    query: cloneUnifiedQuerySnapshot(props.executedQuery),
    configuration
  })
}
</script>

<template>
  <section class="unified-query-toolbar" aria-label="查询条件">
    <div class="unified-query-toolbar__primary">
      <label class="search-field">
        <span class="sr-only">综合查询</span>
        <input v-model="keyword" type="search" :placeholder="searchPlaceholder" autocomplete="off" @keyup.enter="submitQuery">
      </label>
      <button type="button" class="button button--primary" @click="submitQuery">查询</button>
      <div class="unified-query-scope" role="group" aria-label="数据范围">
        <span class="unified-query-scope__thumb" :class="{ 'unified-query-scope__thumb--all': dataScope === 'all' }" aria-hidden="true" />
        <button type="button" :aria-pressed="dataScope === 'normal'" :class="{ 'unified-query-scope__active': dataScope === 'normal' }" @click="selectScope('normal')">正常数据</button>
        <button type="button" :aria-pressed="dataScope === 'all'" :class="{ 'unified-query-scope__active': dataScope === 'all' }" @click="selectScope('all')">全部数据</button>
      </div>
      <select v-if="normalizedStatusOptions.length && dataScope === 'all'" v-model="businessStatus" class="unified-query-status" aria-label="其他状态" @change="submitQuery">
        <option value="">其他状态</option>
        <option v-for="option in availableStatusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
      </select>
      <button v-if="canExport" type="button" class="button button--secondary unified-query-export-button" @click="isExportOpen = true"><Download :size="16" />导出</button>
      <button type="button" class="button button--secondary" @click="isSettingsOpen = true">{{ settingsButtonLabel }}</button>
      <slot name="primary-actions" />
    </div>

    <div v-if="quickFilters.length || activeQuickFields.length" class="unified-query-toolbar__secondary">
      <div v-if="quickFilters.length" class="unified-query-toolbar__quick">
        <button
          v-for="filter in quickFilters"
          :key="filter.key"
          type="button"
          class="unified-query-quick-filter"
          :aria-pressed="filter.active === true"
          :class="{ 'unified-query-quick-filter--active': filter.active }"
          @click="selectQuickFilter(filter)"
        >
          {{ filter.label }}<span v-if="filter.count !== undefined">（{{ filter.count }}）</span>
        </button>
      </div>

      <div v-if="activeQuickFields.length" class="unified-query-toolbar__top-fields" aria-label="常用查询字段">
        <label v-for="field in activeQuickFields" :key="field.key" class="unified-query-top-field">
          <span>{{ field.label }}</span>
          <div class="unified-query-top-field__control">
            <button
              v-if="isEntityField(field)"
              type="button"
              class="unified-query-top-field__entity"
              :class="{ 'unified-query-top-field__entity--fixed': isFixedStoreField(field) }"
              :disabled="isFixedStoreField(field)"
              :title="isFixedStoreField(field) ? '门店端默认使用当前门店，无需选择' : ''"
              @click="selectTopEntity(field)"
            >
              {{ displayTopFieldValue(field) }}
            </button>
            <select v-else-if="field.type === 'enum' && field.options?.length" v-model="quickFieldValues[field.key]" @change="submitQuery">
              <option value="">全部</option>
              <option v-for="option in field.options" :key="option.value || option" :value="option.value || option">{{ option.label || option }}</option>
            </select>
            <input
              v-else
              v-model="quickFieldValues[field.key]"
              :type="topFieldType(field)"
              :placeholder="topFieldPlaceholder(field)"
              @keyup.enter="submitQuery"
            >
            <button v-if="quickFieldValues[field.key] && !isFixedStoreField(field)" type="button" class="unified-query-top-field__clear" :aria-label="`清空${field.label}`" @click="clearTopField(field)">×</button>
          </div>
        </label>
      </div>
    </div>

    <UnifiedQuerySettingsDrawer
      v-if="isSettingsOpen"
      :fields="fields"
      :default-sort-field="defaultSortField"
      :max-quick-fields="maxQuickFields"
      :on-save="persistSettings"
      :on-select-entity="selectEntity"
      :lock-store-selector="lockStoreSelector"
      :current-store="currentStore"
      :initial-settings="activeSettings"
      :invalid-references="activeSettings.invalidReferences"
      :on-upgrade-reference="upgradeSavedQueryFieldReference"
      :schema-version="capabilityMatchesPage ? queryCapability.schemaVersion : activeSettings.schemaVersion"
      :enforce-field-capabilities="capabilityMatchesPage"
      :show-custom-fields="canManageCustomFields"
      :show-field-rename="canRenameFields"
      @close="isSettingsOpen = false"
      @applied="applySettings"
      @custom-fields="isCustomFieldOpen = true"
      @field-rename="isFieldRenameOpen = true"
    />

    <UnifiedQueryCustomFieldDrawer
      v-if="isCustomFieldOpen && canManageCustomFields"
      :fields="fields"
      :custom-fields="queryCapability.customFields || []"
      :page-name="pageName"
      :permissions="queryCapability.permissions || {}"
      :on-save="saveCustomField"
      :on-status-change="changeCustomFieldStatus"
      :on-archive="archiveCustomField"
      @close="isCustomFieldOpen = false"
    />

    <UnifiedQueryFieldRenameDrawer
      v-if="isFieldRenameOpen && canRenameFields"
      :fields="originalFields"
      :aliases="queryCapability.fieldAliases || {}"
      :page-name="pageName"
      :on-save="saveAliases"
      @close="isFieldRenameOpen = false"
    />

    <UnifiedQueryExportDrawer
      v-if="isExportOpen && canExport"
      :key="stateContextKey"
      :fields="exportFields"
      :visible-field-keys="activeSettings.visibleFields"
      :page-name="pageName"
      :result-count="resultCount"
      :page-size="pageSize"
      :current-page-count="currentPageCount"
      :data-as-of="dataAsOf || queryCapability.dataAsOf"
      :export-capability="queryCapability.exportCapability"
      :on-create="createExport"
      :on-query-task="onQueryExportTask"
      :initial-task="exportTaskReference"
      @close="isExportOpen = false"
      @task-change="exportTaskReference = $event"
    />
  </section>
</template>

<style scoped>
.unified-query-export-button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
</style>
