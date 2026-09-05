<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import UnifiedQueryCustomFieldDrawer from './UnifiedQueryCustomFieldDrawer.vue'
import UnifiedQueryExportDrawer from './UnifiedQueryExportDrawer.vue'
import UnifiedQueryFieldRenameDrawer from './UnifiedQueryFieldRenameDrawer.vue'
import UnifiedQuerySettingsDrawer from './UnifiedQuerySettingsDrawer.vue'
import {
  cloneUnifiedQuerySnapshot,
  normalizeUnifiedQuerySettings,
  unifiedQueryActionSucceeded
} from '../contracts/unifiedQueryContract.js'

const props = defineProps({
  searchPlaceholder: {
    type: String,
    default: '请输入查询内容'
  },
  settingsButtonLabel: {
    type: String,
    default: '设置'
  },
  showSettingsButton: {
    type: Boolean,
    default: true
  },
  // 订单中心的导出属于查询结果操作，放在“设置”之后；其他已接入页面保持原位置，
  // 避免一次调整影响无关页面。
  exportButtonAfterSettings: {
    type: Boolean,
    default: false
  },
  // 订单中心只需要导出当前查询结果，不展示范围、字段或文件名配置表单。
  // 仍复用同一异步任务和下载结果面板，保证权限、查询快照和 Excel 生成口径不分叉。
  directQueryExport: {
    type: Boolean,
    default: false
  },
  showKeywordSearch: {
    type: Boolean,
    default: true
  },
  compactKeywordSearch: {
    type: Boolean,
    default: false
  },
  // Some dense operational pages need their status shortcuts and their
  // configured quick fields beside the primary query controls. Keep the
  // default two-row presentation for all existing pages.
  inlineQuickControls: {
    type: Boolean,
    default: false
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
  },
  onDownloadExport: {
    type: Function,
    default: null
  }
})

const emit = defineEmits(['query', 'quick-filter', 'save-settings', 'settings-applied', 'field-aliases-saved', 'custom-field-saved'])

const keyword = ref('')
const dataScope = ref('normal')
const businessStatus = ref('')
const isSettingsOpen = ref(false)
const quickRangeError = ref('')

function openSettings() {
  isSettingsOpen.value = true
}

defineExpose({ openSettings })
const isCustomFieldOpen = ref(false)
const isFieldRenameOpen = ref(false)
const isExportOpen = ref(false)
const exportTaskReference = ref(null)
const isDirectExporting = ref(false)
const exportError = ref('')
const quickFieldValues = reactive({})
const quickFieldRanges = reactive({})

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
  field.quickFilterHidden !== true
  && (!capabilityMatchesPage.value || field.capabilities?.quickFilter === true)
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
  && hasCommandContext.value)
const canRenameFields = computed(() => capabilityMatchesPage.value
  && hasCommandContext.value
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
  if (fieldSelector(field)) return 'eq'
  const type = String(field.type || 'text').toLowerCase()
  return type === 'text' ? 'contains' : 'eq'
}

const LEGACY_ENTITY_LABELS = Object.freeze({
  person: '选择人员',
  store: '选择门店',
  organization: '选择组织'
})

function fieldSelector(field) {
  if (!field || typeof field !== 'object') return null
  const source = field.selector && typeof field.selector === 'object' ? field.selector : {}
  const kind = String(source.kind || '').trim().toLowerCase()
  if (/^[a-z][a-z0-9_]{1,63}$/.test(kind)) {
    return {
      ...source,
      kind,
      label: String(source.label || '').trim() || `选择${field.label}`
    }
  }
  const legacyKind = String(field.type || '').trim().toLowerCase()
  return LEGACY_ENTITY_LABELS[legacyKind]
    ? { kind: legacyKind, label: LEGACY_ENTITY_LABELS[legacyKind] }
    : null
}

function isEntityField(field) {
  return Boolean(fieldSelector(field))
}

function isFixedStoreField(field) {
  return Boolean(props.lockStoreSelector && fieldSelector(field)?.kind === 'store' && fixedCurrentStore.value.id)
}

function entityFieldLabel(field) {
  return fieldSelector(field)?.label || `选择${field?.label || '记录'}`
}

function entityValueId(field, value) {
  if (!value || typeof value !== 'object') return value
  const idKey = String(fieldSelector(field)?.idKey || '').trim()
  return (idKey ? value[idKey] : null) ?? value.id
}

function entityValueLabel(field, value) {
  if (!value || typeof value !== 'object') return ''
  const labelKey = String(fieldSelector(field)?.labelKey || '').trim()
  return (labelKey ? value[labelKey] : '') || value.label || value.name || value.displayValue || ''
}

function displayTopFieldValue(field) {
  if (isFixedStoreField(field)) return fixedCurrentStore.value.label
  const value = quickFieldValues[field.key]
  if (!value) return entityFieldLabel(field)
  return entityValueLabel(field, value) || entityValueId(field, value) || entityFieldLabel(field)
}

function quickRangeValue(field, bound) {
  return quickFieldRanges[field.key]?.[bound] ?? ''
}

function isQuickDateRange(field) {
  return field?.quickDateRange === true && topFieldType(field) === 'date'
}

function localToday() {
  const now = new Date()
  const offset = now.getTimezoneOffset() * 60000
  return new Date(now.getTime() - offset).toISOString().slice(0, 10)
}

function ensureQuickDateRangeDefaults() {
  activeQuickFields.value.forEach((field) => {
    if (!isQuickDateRange(field)) return
    if (!quickFieldRanges[field.key]) quickFieldRanges[field.key] = { min: '', max: '' }
    const today = localToday()
    if (!quickFieldRanges[field.key].min) quickFieldRanges[field.key].min = today
    if (!quickFieldRanges[field.key].max) quickFieldRanges[field.key].max = today
  })
}

function setQuickRangeValue(field, bound, value) {
  if (!quickFieldRanges[field.key]) quickFieldRanges[field.key] = { min: '', max: '' }
  quickFieldRanges[field.key][bound] = value
  quickRangeError.value = ''
}

function validateQuickRanges() {
  for (const field of activeQuickFields.value) {
    if (field.quickRange !== true && !isQuickDateRange(field)) continue
    const range = quickFieldRanges[field.key] || {}
    if (range.min === '' || range.min === undefined || range.min === null
      || range.max === '' || range.max === undefined || range.max === null) continue
    if ((isQuickDateRange(field) && String(range.min) > String(range.max))
      || (field.quickRange === true && Number(range.min) > Number(range.max))) {
      quickRangeError.value = isQuickDateRange(field)
        ? `${field.label}的开始日期不能晚于结束日期。`
        : `${field.label}的最小值不能大于最大值。`
      return false
    }
  }
  quickRangeError.value = ''
  return true
}

function exportTopFilters() {
  return activeQuickFields.value
    .flatMap((field) => {
      if (field.quickRange === true || isQuickDateRange(field)) {
        const range = quickFieldRanges[field.key] || {}
        return [
          range.min === undefined || range.min === null || range.min === '' ? null : {
            field: field.key,
            operator: 'gte',
            value: range.min
          },
          range.max === undefined || range.max === null || range.max === '' ? null : {
            field: field.key,
            operator: 'lte',
            value: range.max
          }
        ].filter(Boolean)
      }
      const value = isFixedStoreField(field) ? fixedCurrentStore.value : quickFieldValues[field.key]
      if (value === undefined || value === null || value === '') return []
      const normalizedValue = entityValueId(field, value)
      if (normalizedValue === undefined || normalizedValue === null || normalizedValue === '') return []
      return [{
        field: field.key,
        operator: topFieldOperator(field),
        value: normalizedValue
      }]
    })
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
  if (!validateQuickRanges()) return
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
  // 范围和业务状态已经由统一查询合同中的 dataScope / businessStatus
  // 完整表达。不能再附带旧列表使用的 status 字段，否则后端的严格
  // 查询形状校验会把这次范围切换判为非法。
  emit('query', buildQueryPayload())
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
  return null
}

async function selectTopEntity(field) {
  if (isFixedStoreField(field)) return
  const currentValue = quickFieldValues[field.key]
  const result = await selectEntity({
    scope: 'top_filter',
    field,
    selectorKind: fieldSelector(field)?.kind || '',
    currentValue: entityValueId(field, currentValue)
  })
  const selected = result?.selected || result?.data?.selected
  if (selected) quickFieldValues[field.key] = selected
}

function clearTopField(field) {
  if (isFixedStoreField(field)) return
  if (field.quickRange === true || isQuickDateRange(field)) {
    quickFieldRanges[field.key] = isQuickDateRange(field)
      ? { min: localToday(), max: localToday() }
      : { min: '', max: '' }
    quickRangeError.value = ''
    return
  }
  quickFieldValues[field.key] = ''
}

function applySettings(settings) {
  activeSettings.value = normalizeSettings(settings)
  activeQuickFields.value.forEach((field) => {
    if ((field.quickRange === true || isQuickDateRange(field)) && !(field.key in quickFieldRanges)) {
      quickFieldRanges[field.key] = { min: '', max: '' }
    }
    if (!(field.key in quickFieldValues)) quickFieldValues[field.key] = ''
  })
  ensureQuickDateRangeDefaults()
  emit('settings-applied', { ...activeSettings.value })
  submitQuery()
}

watch(activeQuickFields, () => ensureQuickDateRangeDefaults(), { immediate: true })

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

const directExportFieldKeys = computed(() => {
  const exportable = new Set(exportFields.value.map((field) => field.key))
  const configured = Array.isArray(activeSettings.value.visibleFields)
    ? activeSettings.value.visibleFields.filter((key) => exportable.has(key))
    : []
  return configured.length ? configured : exportFields.value.map((field) => field.key)
})

function directExportFileName() {
  const now = new Date()
  const date = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('')
  return `${props.pageName}_${date}.xlsx`
}

async function startDirectQueryExport() {
  if (isDirectExporting.value || !canExport.value) return
  isDirectExporting.value = true
  exportError.value = ''
  try {
    const result = await createExport({
      scope: 'query',
      fields: directExportFieldKeys.value,
      includeSummary: false,
      fileName: directExportFileName()
    })
    if (result?.status === 'result_unknown' && result?.idempotencyKey) {
      // 创建请求超时不能重复创建第二个文件任务。沿用已有抽屉的幂等回查逻辑，
      // 以原请求键恢复同一个任务。
      exportTaskReference.value = {
        id: null,
        status: 'result_unknown',
        fileName: directExportFileName(),
        rowCount: props.resultCount,
        originalIdempotencyKey: result.idempotencyKey
      }
      isExportOpen.value = true
      return
    }
    const succeeded = result?.status === 'success' || unifiedQueryActionSucceeded(result?.raw || result)
    if (!succeeded || !result?.task?.id) {
      exportError.value = result?.message || '导出任务创建失败，请重试。'
      return
    }
    exportTaskReference.value = result.task
    isExportOpen.value = true
  } catch (error) {
    exportError.value = error?.message || '导出任务创建失败，请重试。'
  } finally {
    isDirectExporting.value = false
  }
}
</script>

<template>
  <section
    class="unified-query-toolbar"
    :class="{
      'unified-query-toolbar--quick-controls-inline': inlineQuickControls,
      'unified-query-toolbar--compact-keyword-search': compactKeywordSearch
    }"
    aria-label="查询条件"
  >
    <div class="unified-query-toolbar__topline">
      <div class="unified-query-toolbar__primary">
        <div v-if="inlineQuickControls && quickFilters.length" class="unified-query-toolbar__quick">
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
        <div v-if="inlineQuickControls && activeQuickFields.length" class="unified-query-toolbar__top-fields" aria-label="常用查询字段">
          <label v-for="field in activeQuickFields" :key="field.key" class="unified-query-top-field" :class="{ 'unified-query-top-field--label-hidden': field.quickLabelHidden === true }">
            <span v-if="field.quickLabelHidden !== true">{{ field.label }}</span>
            <div class="unified-query-top-field__control">
              <div v-if="isQuickDateRange(field)" class="unified-query-top-field__range unified-query-top-field__range--date">
                <span>从</span>
                <input
                  :value="quickRangeValue(field, 'min')"
                  type="date"
                  aria-label="开始日期"
                  @input="setQuickRangeValue(field, 'min', $event.target.value)"
                  @change="submitQuery"
                >
                <span>至</span>
                <input
                  :value="quickRangeValue(field, 'max')"
                  type="date"
                  aria-label="结束日期"
                  @input="setQuickRangeValue(field, 'max', $event.target.value)"
                  @change="submitQuery"
                >
              </div>
              <div v-else-if="field.quickRange === true" class="unified-query-top-field__range">
                <input
                  :value="quickRangeValue(field, 'min')"
                  type="number"
                  placeholder="最小值"
                  aria-label="最小值"
                  @input="setQuickRangeValue(field, 'min', $event.target.value)"
                  @keyup.enter="submitQuery"
                >
                <span>至</span>
                <input
                  :value="quickRangeValue(field, 'max')"
                  type="number"
                  placeholder="最大值"
                  aria-label="最大值"
                  @input="setQuickRangeValue(field, 'max', $event.target.value)"
                  @keyup.enter="submitQuery"
                >
              </div>
              <button
                v-else-if="isEntityField(field)"
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
              <button v-if="!isQuickDateRange(field) && (field.quickRange === true ? (quickFieldRanges[field.key]?.min || quickFieldRanges[field.key]?.max) : quickFieldValues[field.key]) && !isFixedStoreField(field)" type="button" class="unified-query-top-field__clear" :aria-label="`清空${field.label}`" @click="clearTopField(field)">×</button>
            </div>
          </label>
        </div>
        <label v-if="showKeywordSearch" class="search-field">
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
        <button v-if="canExport && !exportButtonAfterSettings" type="button" class="button button--secondary unified-query-export-button" @click="isExportOpen = true"><Download :size="16" />导出</button>
        <button v-if="showSettingsButton" type="button" class="button button--secondary" @click="openSettings">{{ settingsButtonLabel }}</button>
        <button
          v-if="canExport && exportButtonAfterSettings"
          type="button"
          class="button button--secondary unified-query-export-button"
          :disabled="isDirectExporting"
          @click="directQueryExport ? startDirectQueryExport() : (isExportOpen = true)"
        ><Download :size="16" />{{ isDirectExporting ? '正在创建…' : '导出' }}</button>
        <slot name="primary-actions" />
      </div>
      <div v-if="$slots['context-actions']" class="unified-query-toolbar__context-actions">
        <slot name="context-actions" />
      </div>
    </div>

    <div v-if="!inlineQuickControls && (quickFilters.length || activeQuickFields.length)" class="unified-query-toolbar__secondary">
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
        <label v-for="field in activeQuickFields" :key="field.key" class="unified-query-top-field" :class="{ 'unified-query-top-field--label-hidden': field.quickLabelHidden === true }">
          <span v-if="field.quickLabelHidden !== true">{{ field.label }}</span>
          <div class="unified-query-top-field__control">
            <div v-if="isQuickDateRange(field)" class="unified-query-top-field__range unified-query-top-field__range--date">
              <span>从</span>
              <input
                :value="quickRangeValue(field, 'min')"
                type="date"
                aria-label="开始日期"
                @input="setQuickRangeValue(field, 'min', $event.target.value)"
                @change="submitQuery"
              >
              <span>至</span>
              <input
                :value="quickRangeValue(field, 'max')"
                type="date"
                aria-label="结束日期"
                @input="setQuickRangeValue(field, 'max', $event.target.value)"
                @change="submitQuery"
              >
            </div>
            <div v-else-if="field.quickRange === true" class="unified-query-top-field__range">
              <input
                :value="quickRangeValue(field, 'min')"
                type="number"
                placeholder="最小值"
                aria-label="最小值"
                @input="setQuickRangeValue(field, 'min', $event.target.value)"
                @keyup.enter="submitQuery"
              >
              <span>至</span>
              <input
                :value="quickRangeValue(field, 'max')"
                type="number"
                placeholder="最大值"
                aria-label="最大值"
                @input="setQuickRangeValue(field, 'max', $event.target.value)"
                @keyup.enter="submitQuery"
              >
            </div>
            <button
              v-else-if="isEntityField(field)"
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
            <button v-if="!isQuickDateRange(field) && (field.quickRange === true ? (quickFieldRanges[field.key]?.min || quickFieldRanges[field.key]?.max) : quickFieldValues[field.key]) && !isFixedStoreField(field)" type="button" class="unified-query-top-field__clear" :aria-label="`清空${field.label}`" @click="clearTopField(field)">×</button>
          </div>
        </label>
      </div>
    </div>
    <p v-if="quickRangeError" class="unified-query-toolbar__error" role="alert">{{ quickRangeError }}</p>
    <p v-if="exportError" class="unified-query-toolbar__error" role="alert">{{ exportError }}</p>

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
      :on-download="onDownloadExport"
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
