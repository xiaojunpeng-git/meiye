<script setup>
import { computed, ref } from 'vue'
import Calculator from '@lucide/vue/dist/esm/icons/calculator.mjs'
import PencilLine from '@lucide/vue/dist/esm/icons/pencil-line.mjs'
import {
  unifiedQueryActionIdempotencyKey,
  unifiedQueryActionMessage,
  unifiedQueryActionStatus
} from '../contracts/unifiedQueryContract.js'

const props = defineProps({
  title: {
    type: String,
    default: '查询设置'
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
  onSave: {
    type: Function,
    default: null
  },
  onUpgradeReference: {
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
  initialSettings: {
    type: Object,
    default: () => ({})
  },
  showCustomFields: {
    type: Boolean,
    default: false
  },
  showFieldRename: {
    type: Boolean,
    default: false
  },
  enforceFieldCapabilities: {
    type: Boolean,
    default: false
  },
  schemaVersion: {
    type: [String, Number],
    default: 1
  },
  invalidReferences: {
    type: Array,
    default: () => []
  }
})

const emit = defineEmits(['applied', 'close', 'save', 'custom-fields', 'field-rename'])

const fieldOptions = computed(() => props.fields.filter((field) => !field.hidden && field.key !== 'id'))
function supports(field, capability) {
  return !props.enforceFieldCapabilities || field?.capabilities?.[capability] === true
}
const visibleFieldOptions = computed(() => fieldOptions.value.filter((field) => supports(field, 'list')))
const quickFieldOptions = computed(() => fieldOptions.value.filter((field) => supports(field, 'quickFilter')))
const sortFieldOptions = computed(() => fieldOptions.value.filter((field) => supports(field, 'sort')))
const filterFieldOptions = computed(() => fieldOptions.value.filter((field) => supports(field, 'filter')))
const groupFieldOptions = computed(() => fieldOptions.value.filter((field) => supports(field, 'group')))
const summaryFieldOptions = computed(() => fieldOptions.value.filter((field) => supports(field, 'summary')))
const defaultVisibleFields = computed(() => visibleFieldOptions.value.filter((field) => field.defaultVisible !== false).map((field) => field.key))
const defaultQuickFields = computed(() => quickFieldOptions.value.filter((field) => field.defaultQuick).map((field) => field.key))
const defaultSortKey = computed(() => sortFieldOptions.value.find((field) => field.key === props.defaultSortField || field.label === props.defaultSortField || field.originalLabel === props.defaultSortField)?.key || sortFieldOptions.value[0]?.key || '')
const fixedCurrentStore = computed(() => {
  const source = props.currentStore && typeof props.currentStore === 'object' ? props.currentStore : {}
  return {
    id: source.id || source.storeId || null,
    label: source.name || source.storeName || '当前门店'
  }
})

const ENTITY_FIELD_TYPES = ['person', 'store', 'organization']
const LEGACY_ENTITY_LABELS = Object.freeze({
  person: '选择人员',
  store: '选择门店',
  organization: '选择组织'
})
const NUMBER_FIELD_TYPES = ['number', 'amount', 'money', 'currency', 'count', 'times', 'integer', 'decimal']
const DATE_FIELD_TYPES = ['date', 'datetime', 'date_time', 'time']
const BOOLEAN_FIELD_TYPES = ['boolean', 'bool', 'switch']

function isUsableField(key, options = fieldOptions.value) {
  return options.some((field) => field.key === key)
}

function initialKeys(key, fallback, max = Infinity, options = fieldOptions.value) {
  if (!Array.isArray(props.initialSettings?.[key])) return [...fallback]
  return props.initialSettings[key].filter((fieldKey) => isUsableField(fieldKey, options)).slice(0, max)
}

function initialSorts() {
  const source = Array.isArray(props.initialSettings?.sorts) ? props.initialSettings.sorts : []
  const used = new Set()
  const valid = source
    .filter((sort) => isUsableField(sort?.field, sortFieldOptions.value) && !used.has(sort.field))
    .map((sort) => {
      used.add(sort.field)
      return { field: sort.field, direction: sort.direction === 'asc' ? 'asc' : 'desc' }
    })
    .slice(0, 3)
  return valid.length ? valid : (defaultSortKey.value ? [{ field: defaultSortKey.value, direction: 'desc' }] : [])
}

function initialFilters() {
  const source = Array.isArray(props.initialSettings?.filters) ? props.initialSettings.filters : []
  return source
    .filter((filter) => isUsableField(filter?.field || filter?.fieldKey || filter?.field_key, filterFieldOptions.value))
    .map((filter, index) => {
      const field = filter.field || filter.fieldKey || filter.field_key
      const type = fieldType(field)
      const operator = normalizeOperator(type, filter.operator)
      const range = operator === 'between' && Array.isArray(filter.value) ? filter.value : null
      const normalized = {
        id: `filter-initial-${index}-${field}`,
        field,
        operator,
        value: normalizeFilterValue(type, operator, range ? range[0] : filter.value),
        valueTo: operator === 'between' ? (range ? range[1] : (filter.valueTo ?? filter.value_to ?? '')) : '',
        displayValue: filter.displayValue || '',
        selectedRecords: initialSelectedRecords(type, operator, filter, field)
      }
      applyFixedStoreFilter(normalized)
      return normalized
    })
}

function initialGroupBy() {
  const source = Array.isArray(props.initialSettings?.groupBy) ? props.initialSettings.groupBy : []
  const used = new Set()
  return source.filter((key) => {
    if (!isUsableField(key, groupFieldOptions.value) || used.has(key)) return false
    used.add(key)
    return true
  }).slice(0, 3)
}

function summaryAggregationOptions(fieldKey) {
  if (fieldType(fieldKey) === 'number') {
    return [
      { value: 'sum', label: '求和' },
      { value: 'avg', label: '平均值' },
      { value: 'min', label: '最小值' },
      { value: 'max', label: '最大值' },
      { value: 'count', label: '计数' }
    ]
  }
  return [{ value: 'count', label: '计数' }]
}

function normalizeSummaryAggregation(fieldKey, aggregation) {
  const options = summaryAggregationOptions(fieldKey)
  return options.some((option) => option.value === aggregation) ? aggregation : options[0].value
}

function initialSummaries() {
  const source = Array.isArray(props.initialSettings?.summaries) ? props.initialSettings.summaries : []
  const used = new Set()
  return source.filter((summary) => {
    if (!isUsableField(summary?.field, summaryFieldOptions.value) || used.has(summary.field)) return false
    used.add(summary.field)
    return true
  }).map((summary) => ({
    field: summary.field,
    aggregation: normalizeSummaryAggregation(summary.field, summary.aggregation)
  })).slice(0, 8)
}

const selectedFields = ref(initialKeys('visibleFields', defaultVisibleFields.value, Infinity, visibleFieldOptions.value))
const quickFields = ref(initialKeys('quickFields', defaultQuickFields.value, props.maxQuickFields, quickFieldOptions.value))
const sorts = ref(initialSorts())
const filters = ref(initialFilters())
const groupBy = ref(initialGroupBy())
const summaries = ref(initialSummaries())
const filterRelation = ref(props.initialSettings?.filterRelation === 'any' ? 'any' : 'all')
const isDirty = ref(false)
const isDiscardConfirmOpen = ref(false)
const isSaving = ref(false)
const saveError = ref('')
const quickFieldError = ref('')
const pendingSaveIdempotencyKey = ref('')
const removedInvalidReferenceKeys = ref([])
const upgradedReferenceKeys = ref([])
const upgradingReferenceKeys = ref([])
const pendingUpgradeIdempotencyKeys = ref({})
const invalidReferences = computed(() => (Array.isArray(props.invalidReferences) ? props.invalidReferences : [])
  .filter((reference) => reference?.fieldKey || reference?.field_key))
const unresolvedInvalidReferences = computed(() => invalidReferences.value.filter((reference) => (
  !removedInvalidReferenceKeys.value.includes(reference.fieldKey || reference.field_key)
  && !upgradedReferenceKeys.value.includes(reference.fieldKey || reference.field_key)
)))
const hasPendingReferenceUpgrade = computed(() => Object.keys(pendingUpgradeIdempotencyKeys.value).length > 0)

function getField(key) {
  return fieldOptions.value.find((field) => field.key === key) || null
}

function fieldLabel(key) {
  return getField(key)?.label || key
}

function rawFieldType(key) {
  return String(getField(key)?.type || 'text').toLowerCase()
}

function fieldSelector(key) {
  const field = getField(key)
  if (!field) return null
  const source = field.selector && typeof field.selector === 'object' ? field.selector : {}
  const kind = String(source.kind || '').trim().toLowerCase()
  if (/^[a-z][a-z0-9_]{1,63}$/.test(kind)) {
    return {
      ...source,
      kind,
      label: String(source.label || '').trim() || `选择${field.label}`
    }
  }
  const legacyKind = rawFieldType(key)
  return LEGACY_ENTITY_LABELS[legacyKind]
    ? { kind: legacyKind, label: LEGACY_ENTITY_LABELS[legacyKind] }
    : null
}

function isEntityType(type) {
  return ENTITY_FIELD_TYPES.includes(type) || String(type || '').startsWith('entity:')
}

function entityKind(type) {
  return String(type || '').startsWith('entity:') ? String(type).slice(7) : String(type || '')
}

function fieldType(key) {
  const rawType = rawFieldType(key)
  const selector = fieldSelector(key)
  if (selector && !ENTITY_FIELD_TYPES.includes(rawType)) return `entity:${selector.kind}`
  if (NUMBER_FIELD_TYPES.includes(rawType)) return 'number'
  if (DATE_FIELD_TYPES.includes(rawType)) return 'date'
  if (BOOLEAN_FIELD_TYPES.includes(rawType)) return 'boolean'
  if (ENTITY_FIELD_TYPES.includes(rawType) || rawType === 'enum') return rawType
  return 'text'
}

function defaultOperator(type) {
  if (type === 'number' || type === 'date') return 'between'
  if (type === 'text') return 'contains'
  return 'eq'
}

function operatorOptionsForType(type) {
  if (type === 'number' || type === 'date') {
    return [
      { value: 'eq', label: '等于' },
      { value: 'neq', label: '不等于' },
      { value: 'gt', label: '大于' },
      { value: 'gte', label: '大于等于' },
      { value: 'lt', label: '小于' },
      { value: 'lte', label: '小于等于' },
      { value: 'between', label: '范围' },
      { value: 'is_null', label: '为空' },
      { value: 'is_not_null', label: '不为空' }
    ]
  }
  if (type === 'text') {
    return [
      { value: 'contains', label: '包含' },
      { value: 'not_contains', label: '不包含' },
      { value: 'eq', label: '等于' },
      { value: 'neq', label: '不等于' },
      { value: 'is_null', label: '为空' },
      { value: 'is_not_null', label: '不为空' }
    ]
  }
  if (type === 'boolean') return [{ value: 'eq', label: '是／否' }, { value: 'is_null', label: '为空' }, { value: 'is_not_null', label: '不为空' }]
  if (isEntityType(type)) {
    return [{ value: 'eq', label: '等于' }, { value: 'in', label: '属于任一项' }, { value: 'is_null', label: '为空' }, { value: 'is_not_null', label: '不为空' }]
  }
  if (type === 'enum') {
    return [
      { value: 'eq', label: '等于' },
      { value: 'neq', label: '不等于' },
      { value: 'in', label: '属于任一项' },
      { value: 'is_null', label: '为空' },
      { value: 'is_not_null', label: '不为空' }
    ]
  }
  return [{ value: 'eq', label: '等于' }]
}

function normalizeOperator(type, operator) {
  const aliases = {
    equal: 'eq',
    not_equal: 'neq',
    greater_than: 'gt',
    greater_or_equal: 'gte',
    less_than: 'lt',
    less_or_equal: 'lte'
  }
  const normalized = aliases[operator] || operator
  return operatorOptionsForType(type).some((option) => option.value === normalized)
    ? normalized
    : defaultOperator(type)
}

function isMultiValueOperator(type, operator) {
  return operator === 'in' && (isEntityType(type) || type === 'enum')
}

function hasValue(value) {
  if (Array.isArray(value)) return value.length > 0
  return value !== undefined && value !== null && value !== ''
}

function normalizeMultipleValues(value) {
  const source = Array.isArray(value) ? value : hasValue(value) ? [value] : []
  const seen = new Set()
  return source.filter((item) => {
    if (!hasValue(item)) return false
    const key = String(item)
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

function normalizeBooleanValue(value) {
  if (value === true || value === 'true' || value === 1 || value === '1') return true
  if (value === false || value === 'false' || value === 0 || value === '0') return false
  return ''
}

function normalizeFilterValue(type, operator, value) {
  if (operator === 'is_null' || operator === 'is_not_null') return null
  if (isMultiValueOperator(type, operator)) return normalizeMultipleValues(value)
  if (type === 'boolean') return normalizeBooleanValue(value)
  if (Array.isArray(value)) return value[0] ?? ''
  return value ?? ''
}

function entityRecordId(record, type, fieldKey = '') {
  if (!record || typeof record !== 'object') return null
  const selector = fieldSelector(fieldKey)
  const idKey = String(selector?.idKey || '').trim()
  const kind = entityKind(type)
  return (idKey ? record[idKey] : null)
    ?? record.id
    ?? record[`${kind}Id`]
    ?? record.staffId
    ?? record.storeId
    ?? record.organizationId
    ?? null
}

function initialSelectedRecords(type, operator, filter, fieldKey = '') {
  if (!isEntityType(type)) return []
  const supplied = Array.isArray(filter?.selectedRecords) ? filter.selectedRecords.filter(Boolean) : []
  const values = isMultiValueOperator(type, operator)
    ? normalizeMultipleValues(filter?.value)
    : normalizeMultipleValues(filter?.value).slice(0, 1)
  const suppliedById = new Map(supplied.map((record) => [String(entityRecordId(record, type, fieldKey)), record]))
  return values.map((value) => suppliedById.get(String(value)) || { id: value })
}

function isFixedStoreField(key) {
  return Boolean(props.lockStoreSelector && entityKind(fieldType(key)) === 'store' && fixedCurrentStore.value.id)
}

function applyFixedStoreFilter(filter) {
  if (!filter || !isFixedStoreField(filter.field)) return false
  filter.value = isMultiValueOperator(fieldType(filter.field), filter.operator)
    ? [fixedCurrentStore.value.id]
    : fixedCurrentStore.value.id
  filter.valueTo = ''
  filter.displayValue = fixedCurrentStore.value.label
  filter.selectedRecords = [{ id: fixedCurrentStore.value.id, label: fixedCurrentStore.value.label }]
  return true
}

function entityLabel(key) {
  return fieldSelector(key)?.label || `选择${fieldLabel(key)}`
}

function toggleVisibleField(key) {
  const index = selectedFields.value.indexOf(key)
  if (index === -1) selectedFields.value.push(key)
  else selectedFields.value.splice(index, 1)
  isDirty.value = true
}

function toggleQuickField(key) {
  const index = quickFields.value.indexOf(key)
  if (index === -1) {
    if (quickFields.value.length >= props.maxQuickFields) {
      quickFieldError.value = `顶部常用查询字段最多显示两行（当前最多 ${props.maxQuickFields} 个）。`
      return
    }
    quickFields.value.push(key)
  } else {
    quickFields.value.splice(index, 1)
  }
  quickFieldError.value = ''
  isDirty.value = true
}

function moveVisibleField(key, direction) {
  const index = selectedFields.value.indexOf(key)
  const nextIndex = index + direction
  if (index < 0 || nextIndex < 0 || nextIndex >= selectedFields.value.length) return
  const [field] = selectedFields.value.splice(index, 1)
  selectedFields.value.splice(nextIndex, 0, field)
  isDirty.value = true
}

function addSort() {
  if (sorts.value.length >= 3) return
  const fallback = sortFieldOptions.value.find((field) => !sorts.value.some((sort) => sort.field === field.key))
  if (!fallback) return
  sorts.value.push({ field: fallback.key, direction: 'desc' })
  isDirty.value = true
}

function updateSort(index, nextField) {
  if (sorts.value.some((sort, sortIndex) => sortIndex !== index && sort.field === nextField)) return
  sorts.value[index].field = nextField
  isDirty.value = true
}

function moveSort(index, direction) {
  const nextIndex = index + direction
  if (nextIndex < 0 || nextIndex >= sorts.value.length) return
  const [sort] = sorts.value.splice(index, 1)
  sorts.value.splice(nextIndex, 0, sort)
  isDirty.value = true
}

function addFilter() {
  const firstField = filterFieldOptions.value[0]
  if (!firstField) return
  const type = fieldType(firstField.key)
  const operator = defaultOperator(type)
  filters.value.push({
    id: `filter-${Date.now()}-${filters.value.length}`,
    field: firstField.key,
    operator,
    value: normalizeFilterValue(type, operator, ''),
    valueTo: '',
    displayValue: '',
    selectedRecords: []
  })
  applyFixedStoreFilter(filters.value[filters.value.length - 1])
  isDirty.value = true
}

function addGroup() {
  if (groupBy.value.length >= 3) return
  const field = groupFieldOptions.value.find((option) => !groupBy.value.includes(option.key))
  if (!field) return
  groupBy.value.push(field.key)
  isDirty.value = true
}

function updateGroup(index, key) {
  if (groupBy.value.some((item, itemIndex) => itemIndex !== index && item === key)) return
  groupBy.value[index] = key
  isDirty.value = true
}

function addSummary() {
  const field = summaryFieldOptions.value.find((option) => !summaries.value.some((summary) => summary.field === option.key))
  if (!field || summaries.value.length >= 8) return
  summaries.value.push({ field: field.key, aggregation: summaryAggregationOptions(field.key)[0].value })
  isDirty.value = true
}

function updateSummaryField(index, key) {
  if (summaries.value.some((summary, summaryIndex) => summaryIndex !== index && summary.field === key)) return
  summaries.value[index].field = key
  summaries.value[index].aggregation = summaryAggregationOptions(key)[0].value
  isDirty.value = true
}

function updateFilterField(filter, nextField) {
  filter.field = nextField
  const type = fieldType(nextField)
  filter.operator = defaultOperator(type)
  filter.value = normalizeFilterValue(type, filter.operator, '')
  filter.valueTo = ''
  filter.displayValue = ''
  filter.selectedRecords = []
  applyFixedStoreFilter(filter)
  isDirty.value = true
}

function operatorOptions(filter) {
  return operatorOptionsForType(fieldType(filter.field))
}

function updateFilterOperator(filter, nextOperator) {
  const type = fieldType(filter.field)
  const operator = normalizeOperator(type, nextOperator)
  const previousValue = filter.value
  filter.operator = operator
  filter.value = normalizeFilterValue(type, operator, previousValue)
  filter.valueTo = operator === 'between' ? filter.valueTo ?? '' : ''
  if (!isEntityFilter(filter)) {
    filter.displayValue = ''
    filter.selectedRecords = []
  } else if (isMultiValueOperator(type, operator)) {
    filter.selectedRecords = selectedEntityRecords(filter)
  } else {
    filter.selectedRecords = selectedEntityRecords(filter).slice(0, 1)
  }
  applyFixedStoreFilter(filter)
  isDirty.value = true
}

function isEntityFilter(filter) {
  return isEntityType(fieldType(filter.field))
}

function isBooleanFilter(filter) {
  return fieldType(filter.field) === 'boolean'
}

function isEnumFilter(filter) {
  return fieldType(filter.field) === 'enum'
}

function isMultiValueFilter(filter) {
  return isMultiValueOperator(fieldType(filter.field), filter.operator)
}

function isRangeFilter(filter) {
  return filter.operator === 'between'
}

function isNullFilter(filter) {
  return filter.operator === 'is_null' || filter.operator === 'is_not_null'
}

function inputType(filter) {
  const type = fieldType(filter.field)
  if (type === 'number') return 'number'
  if (type === 'date') {
    const rawType = rawFieldType(filter.field)
    if (rawType === 'datetime' || rawType === 'date_time') return 'datetime-local'
    if (rawType === 'time') return 'time'
    return 'date'
  }
  return 'text'
}

function rangeStartPlaceholder(filter) {
  const rawType = rawFieldType(filter.field)
  if (rawType === 'datetime' || rawType === 'date_time') return '开始日期时间'
  if (rawType === 'time') return '开始时间'
  return fieldType(filter.field) === 'date' ? '开始日期' : '起始值'
}

function rangeEndPlaceholder(filter) {
  const rawType = rawFieldType(filter.field)
  if (rawType === 'datetime' || rawType === 'date_time') return '结束日期时间'
  if (rawType === 'time') return '结束时间'
  return fieldType(filter.field) === 'date' ? '结束日期' : '结束值'
}

function choiceOptions(filter) {
  const options = Array.isArray(getField(filter.field)?.options) ? getField(filter.field).options : []
  return options.map((option) => {
    if (option && typeof option === 'object') {
      return { value: option.value ?? option.label, label: option.label ?? option.value }
    }
    return { value: option, label: option }
  }).filter((option) => hasValue(option.value))
}

function selectedEntityRecords(filter) {
  const type = fieldType(filter.field)
  if (!isEntityType(type)) return []
  const values = isMultiValueFilter(filter)
    ? normalizeMultipleValues(filter.value)
    : normalizeMultipleValues(filter.value).slice(0, 1)
  const records = Array.isArray(filter.selectedRecords) ? filter.selectedRecords.filter(Boolean) : []
  const recordsById = new Map(records.map((record) => [String(entityRecordId(record, type, filter.field)), record]))
  return values.map((value) => recordsById.get(String(value)) || { id: value })
}

function entityRecordLabel(record, fieldKey = '') {
  const labelKey = String(fieldSelector(fieldKey)?.labelKey || '').trim()
  return (labelKey ? record?.[labelKey] : '')
    || record?.label
    || record?.name
    || record?.displayValue
    || record?.staffName
    || record?.storeName
    || record?.organizationName
    || ''
}

function entitySelectionLabel(filter) {
  if (isFixedStoreField(filter.field)) return fixedCurrentStore.value.label
  const values = isMultiValueFilter(filter)
    ? normalizeMultipleValues(filter.value)
    : normalizeMultipleValues(filter.value).slice(0, 1)
  if (!values.length) return `${entityLabel(filter.field)}${isMultiValueFilter(filter) ? '（可多选）' : ''}`
  const labels = selectedEntityRecords(filter).map((record) => entityRecordLabel(record, filter.field)).filter(Boolean)
  if (isMultiValueFilter(filter)) {
    if (labels.length === 1) return labels[0]
    if (labels.length > 1) return `${labels[0]} 等 ${values.length} 项`
    return `已选择 ${values.length} 项`
  }
  return labels[0] || filter.displayValue || '已选择 1 项'
}

function clearFilterValue(filter) {
  if (isFixedStoreField(filter.field)) return
  filter.value = isMultiValueFilter(filter) ? [] : ''
  filter.valueTo = ''
  filter.displayValue = ''
  filter.selectedRecords = []
  isDirty.value = true
}

async function selectEntity(filter, index) {
  if (applyFixedStoreFilter(filter)) {
    isDirty.value = true
    return
  }
  if (!props.onSelectEntity) return
  try {
    const type = fieldType(filter.field)
    const multiple = isMultiValueFilter(filter)
    const result = await props.onSelectEntity({
      filterIndex: index,
      field: getField(filter.field),
      selectorKind: fieldSelector(filter.field)?.kind || '',
      currentValue: filter.value,
      multiple,
      selectedRecords: selectedEntityRecords(filter),
      scope: 'query_filter',
      selectionContext: {
        scope: 'query_filter',
        filterField: filter.field,
        filterOperator: filter.operator
      }
    })
    const selected = result?.selected || result?.data?.selected
    const records = Array.isArray(selected) ? selected : selected ? [selected] : []
    const selectedWithId = records.filter((record) => hasValue(entityRecordId(record, type, filter.field)))
    if (!selectedWithId.length) return
    const nextRecords = multiple ? selectedWithId : selectedWithId.slice(0, 1)
    const nextValues = nextRecords.map((record) => entityRecordId(record, type, filter.field))
    filter.value = multiple ? normalizeMultipleValues(nextValues) : nextValues[0]
    filter.displayValue = entityRecordLabel(nextRecords[0], filter.field)
    filter.selectedRecords = nextRecords
    isDirty.value = true
  } catch {
    // 选择控件关闭、网络失败等不改变当前未保存配置；由公共选择控件自行反馈失败原因。
  }
}

function requestClose() {
  if (isSaving.value) return
  if (hasPendingReferenceUpgrade.value) {
    saveError.value = '字段升级结果尚未确认，请按原请求重试。'
    return
  }
  if (pendingSaveIdempotencyKey.value) {
    saveError.value = '保存结果尚未确认，请先按原请求重试。'
    return
  }
  if (isDirty.value) {
    isDiscardConfirmOpen.value = true
    return
  }
  emit('close')
}

function restoreDefaults() {
  selectedFields.value = [...defaultVisibleFields.value]
  quickFields.value = [...defaultQuickFields.value]
  sorts.value = defaultSortKey.value ? [{ field: defaultSortKey.value, direction: 'desc' }] : []
  filters.value = []
  groupBy.value = []
  summaries.value = []
  filterRelation.value = 'all'
  quickFieldError.value = ''
  isDirty.value = true
}

function removeInvalidReference(reference) {
  const fieldKey = reference?.fieldKey || reference?.field_key
  if (!fieldKey
    || removedInvalidReferenceKeys.value.includes(fieldKey)
    || pendingUpgradeIdempotencyKeys.value[fieldKey]) return
  removedInvalidReferenceKeys.value.push(fieldKey)
  selectedFields.value = selectedFields.value.filter((key) => key !== fieldKey)
  quickFields.value = quickFields.value.filter((key) => key !== fieldKey)
  sorts.value = sorts.value.filter((sort) => sort.field !== fieldKey)
  filters.value = filters.value.filter((filter) => filter.field !== fieldKey)
  groupBy.value = groupBy.value.filter((key) => key !== fieldKey)
  summaries.value = summaries.value.filter((summary) => summary.field !== fieldKey)
  isDirty.value = true
}

function referenceKey(reference) {
  return String(reference?.fieldKey || reference?.field_key || '').trim()
}

function isUpgradeAvailable(reference) {
  return String(reference?.status || '') === 'upgrade_available'
}

function isUpgradingReference(reference) {
  return upgradingReferenceKeys.value.includes(referenceKey(reference))
}

function hasPendingUpgrade(reference) {
  return Boolean(pendingUpgradeIdempotencyKeys.value[referenceKey(reference)])
}

async function upgradeInvalidReference(reference) {
  const fieldKey = referenceKey(reference)
  if (!fieldKey || !isUpgradeAvailable(reference) || isUpgradingReference(reference)) return
  if (!props.onUpgradeReference) {
    saveError.value = '当前页面暂不能升级字段版本，请刷新后重试。'
    return
  }
  saveError.value = ''
  upgradingReferenceKeys.value.push(fieldKey)
  try {
    const result = await props.onUpgradeReference({
      fieldKey,
      commandIdempotencyKey: pendingUpgradeIdempotencyKeys.value[fieldKey] || ''
    })
    const status = unifiedQueryActionStatus(result)
    if (status === 'result_unknown') {
      pendingUpgradeIdempotencyKeys.value = {
        ...pendingUpgradeIdempotencyKeys.value,
        [fieldKey]: unifiedQueryActionIdempotencyKey(result)
      }
      saveError.value = unifiedQueryActionMessage(result, '升级结果未知，请按原请求重试。')
      return
    }
    if (status !== 'success') {
      const next = { ...pendingUpgradeIdempotencyKeys.value }
      delete next[fieldKey]
      pendingUpgradeIdempotencyKeys.value = next
      saveError.value = unifiedQueryActionMessage(result, '升级失败，请检查后重试。')
      return
    }
    const next = { ...pendingUpgradeIdempotencyKeys.value }
    delete next[fieldKey]
    pendingUpgradeIdempotencyKeys.value = next
    if (!upgradedReferenceKeys.value.includes(fieldKey)) upgradedReferenceKeys.value.push(fieldKey)
    saveError.value = '字段已升级到最新版本，当前查询已刷新。'
  } catch (error) {
    saveError.value = error?.message || '升级失败，请检查网络后重试。'
  } finally {
    upgradingReferenceKeys.value = upgradingReferenceKeys.value.filter((key) => key !== fieldKey)
  }
}

function exportFilters() {
  return filters.value.map((filter) => {
    const fixedStore = isFixedStoreField(filter.field)
    const type = fieldType(filter.field)
    const multiple = isMultiValueFilter(filter)
    return {
      field: filter.field,
      operator: filter.operator,
      value: fixedStore
        ? multiple ? [fixedCurrentStore.value.id] : fixedCurrentStore.value.id
        : normalizeFilterValue(type, filter.operator, filter.value),
      valueTo: filter.operator === 'between' && !fixedStore && hasValue(filter.valueTo) ? filter.valueTo : undefined
    }
  })
}

async function save() {
  if (isSaving.value) return
  if (hasPendingReferenceUpgrade.value) {
    saveError.value = '字段升级结果尚未确认，请按原请求重试。'
    return
  }
  if (unresolvedInvalidReferences.value.length) {
    saveError.value = '请先处理失效字段引用，再保存查询设置。'
    return
  }
  saveError.value = ''
  isSaving.value = true
  const settings = {
    visibleFields: selectedFields.value,
    quickFields: quickFields.value,
    sorts: sorts.value.map((sort) => ({ ...sort })),
    filterRelation: filterRelation.value,
    filters: exportFilters(),
    groupBy: [...groupBy.value],
    summaries: summaries.value.map((summary) => ({ ...summary })),
    schemaVersion: props.schemaVersion
  }
  try {
    const result = props.onSave ? await props.onSave(settings, { idempotencyKey: pendingSaveIdempotencyKey.value }) : null
    const status = unifiedQueryActionStatus(result)
    if (props.onSave && status === 'result_unknown') {
      pendingSaveIdempotencyKey.value = unifiedQueryActionIdempotencyKey(result)
      saveError.value = unifiedQueryActionMessage(result, '保存结果未知，请按原请求重试。')
      return
    }
    if (props.onSave && status !== 'success') {
      pendingSaveIdempotencyKey.value = ''
      saveError.value = unifiedQueryActionMessage(result, '保存失败，请检查后重试。')
      return
    }
    pendingSaveIdempotencyKey.value = ''
    if (!props.onSave) emit('save', settings)
    isDirty.value = false
    emit('applied', settings)
    emit('close')
  } catch (error) {
    saveError.value = error?.message || '保存失败，请检查网络后重试。'
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="query-settings-drawer" role="dialog" aria-modal="true" :aria-label="title">
      <div class="query-settings-drawer__backdrop" @click="requestClose" />
      <section class="query-settings-drawer__panel">
        <header class="query-settings-drawer__header">
          <div>
            <h2>{{ title }}</h2>
          </div>
          <button type="button" class="button button--secondary" :disabled="isSaving || Boolean(pendingSaveIdempotencyKey)" @click="requestClose">关闭</button>
        </header>

        <main class="query-settings-drawer__body" :inert="Boolean(pendingSaveIdempotencyKey)" :aria-disabled="Boolean(pendingSaveIdempotencyKey)">
          <section class="query-settings-fields">
            <div v-if="unresolvedInvalidReferences.length" class="query-settings-invalid-references" role="alert">
              <strong>已保存设置包含待处理字段</strong>
              <div v-for="reference in unresolvedInvalidReferences" :key="reference.fieldKey || reference.field_key">
                <span>{{ reference.reason || reference.invalidReason || '字段已停用、失去权限或存在可用新版本。' }}</span>
                <button
                  v-if="isUpgradeAvailable(reference)"
                  type="button"
                  class="button button--secondary"
                  :disabled="isUpgradingReference(reference)"
                  @click="upgradeInvalidReference(reference)"
                >{{ isUpgradingReference(reference) ? '正在升级…' : (hasPendingUpgrade(reference) ? '按原请求重试' : '升级到最新版本') }}</button>
                <button
                  type="button"
                  class="button button--text"
                  :disabled="hasPendingUpgrade(reference)"
                  @click="removeInvalidReference(reference)"
                >移除此引用</button>
              </div>
            </div>
            <h3>字段</h3>
            <div class="query-settings-fields__list">
              <label v-for="field in visibleFieldOptions" :key="field.key" class="query-settings-field-option">
                <input type="checkbox" :checked="selectedFields.includes(field.key)" @change="toggleVisibleField(field.key)">
                <span>{{ field.label }}</span>
              </label>
            </div>

            <div v-if="selectedFields.length" class="query-settings-selected-order">
              <h3>显示顺序</h3>
              <div v-for="(fieldKey, index) in selectedFields" :key="fieldKey" class="query-settings-selected-order__row">
                <span>{{ index + 1 }}. {{ fieldLabel(fieldKey) }}</span>
                <div>
                  <button type="button" :disabled="index === 0" @click="moveVisibleField(fieldKey, -1)">上移</button>
                  <button type="button" :disabled="index === selectedFields.length - 1" @click="moveVisibleField(fieldKey, 1)">下移</button>
                </div>
              </div>
            </div>

            <h3 class="query-settings-fields__quick-title">顶部查询字段</h3>
            <div class="query-settings-fields__list">
              <label v-for="field in quickFieldOptions" :key="`quick-${field.key}`" class="query-settings-field-option">
                <input type="checkbox" :checked="quickFields.includes(field.key)" @change="toggleQuickField(field.key)">
                <span>{{ field.label }}</span>
              </label>
            </div>
            <p v-if="quickFieldError" class="query-settings-inline-error">{{ quickFieldError }}</p>
          </section>

          <section class="query-settings-rules">
            <div class="query-settings-rule-card">
              <div class="query-settings-rule-card__header">
                <div>
                  <h3>数据排序</h3>
                </div>
                <button type="button" class="button button--text" :disabled="sorts.length >= 3" @click="addSort">新增排序</button>
              </div>
              <div class="query-settings-sort-list">
                <div v-for="(sort, index) in sorts" :key="`${sort.field}-${index}`" class="query-settings-sort-row">
                  <span class="query-settings-order-number">{{ index + 1 }}</span>
                  <select :value="sort.field" @change="updateSort(index, $event.target.value)">
                    <option v-for="field in sortFieldOptions" :key="field.key" :value="field.key" :disabled="sorts.some((item, itemIndex) => itemIndex !== index && item.field === field.key)">{{ field.label }}</option>
                  </select>
                  <select v-model="sort.direction" @change="isDirty = true">
                    <option value="desc">降序</option>
                    <option value="asc">升序</option>
                  </select>
                  <button type="button" class="query-settings-sort-row__move" :disabled="index === 0" @click="moveSort(index, -1)">↑</button>
                  <button type="button" class="query-settings-sort-row__move" :disabled="index === sorts.length - 1" @click="moveSort(index, 1)">↓</button>
                  <button type="button" class="query-settings-sort-row__remove" :disabled="sorts.length === 1" @click="sorts.splice(index, 1); isDirty = true">删除</button>
                </div>
              </div>
            </div>

            <div class="query-settings-rule-card query-settings-rule-card--filters">
              <div class="query-settings-rule-card__header">
                <div>
                  <h3>组合筛选</h3>
                </div>
                <button type="button" class="button button--text" @click="addFilter">新增筛选</button>
              </div>
              <div v-if="filters.length" class="query-settings-filter-relation">
                <span>条件关系</span>
                <button type="button" :class="{ 'query-settings-filter-relation--active': filterRelation === 'all' }" @click="filterRelation = 'all'; isDirty = true">全部满足</button>
                <button type="button" :class="{ 'query-settings-filter-relation--active': filterRelation === 'any' }" @click="filterRelation = 'any'; isDirty = true">满足任一</button>
              </div>
              <div v-if="filters.length" class="query-settings-filter-list">
                <div v-for="(filter, index) in filters" :key="filter.id" class="query-settings-filter-row">
                  <select :value="filter.field" @change="updateFilterField(filter, $event.target.value)">
                    <option v-for="field in filterFieldOptions" :key="field.key" :value="field.key">{{ field.label }}</option>
                  </select>
                  <select :value="filter.operator" @change="updateFilterOperator(filter, $event.target.value)">
                    <option v-for="option in operatorOptions(filter)" :key="option.value" :value="option.value">{{ option.label }}</option>
                  </select>
                  <template v-if="isEntityFilter(filter)">
                    <button
                      type="button"
                      class="query-settings-entity-picker"
                      :class="{
                        'query-settings-entity-picker--fixed': isFixedStoreField(filter.field),
                        'query-settings-entity-picker--multiple': isMultiValueFilter(filter)
                      }"
                      :disabled="isFixedStoreField(filter.field)"
                      :title="isFixedStoreField(filter.field) ? '门店端默认使用当前门店，无需选择' : (isMultiValueFilter(filter) ? '可选择多个项目' : '')"
                      @click="selectEntity(filter, index)"
                    >{{ entitySelectionLabel(filter) }}</button>
                  </template>
                  <select
                    v-else-if="isEnumFilter(filter) && choiceOptions(filter).length && isMultiValueFilter(filter)"
                    v-model="filter.value"
                    multiple
                    class="query-settings-filter-value-select query-settings-filter-value-select--multiple"
                    :aria-label="`${fieldLabel(filter.field)}筛选值（可多选）`"
                    @change="isDirty = true"
                  >
                    <option v-for="option in choiceOptions(filter)" :key="String(option.value)" :value="option.value">{{ option.label }}</option>
                  </select>
                  <select
                    v-else-if="isEnumFilter(filter) && choiceOptions(filter).length"
                    v-model="filter.value"
                    class="query-settings-filter-value-select"
                    :aria-label="`${fieldLabel(filter.field)}筛选值`"
                    @change="isDirty = true"
                  >
                    <option value="">请选择</option>
                    <option v-for="option in choiceOptions(filter)" :key="String(option.value)" :value="option.value">{{ option.label }}</option>
                  </select>
                  <select
                    v-else-if="isBooleanFilter(filter)"
                    v-model="filter.value"
                    class="query-settings-filter-value-select"
                    :aria-label="`${fieldLabel(filter.field)}筛选值`"
                    @change="isDirty = true"
                  >
                    <option value="">请选择</option>
                    <option :value="true">是</option>
                    <option :value="false">否</option>
                  </select>
                  <span v-else-if="isNullFilter(filter)" class="query-settings-filter-no-value">无需填写筛选值</span>
                  <template v-else-if="isRangeFilter(filter)">
                    <input v-model="filter.value" :type="inputType(filter)" :placeholder="rangeStartPlaceholder(filter)" @input="isDirty = true">
                    <input v-model="filter.valueTo" :type="inputType(filter)" :placeholder="rangeEndPlaceholder(filter)" @input="isDirty = true">
                  </template>
                  <input v-else v-model="filter.value" :type="inputType(filter)" placeholder="请输入筛选值" @input="isDirty = true">
                  <button
                    v-if="!isNullFilter(filter) && (hasValue(filter.value) || hasValue(filter.valueTo)) && !isFixedStoreField(filter.field)"
                    type="button"
                    class="query-settings-filter-clear"
                    @click="clearFilterValue(filter)"
                  >清空</button>
                  <button type="button" class="query-settings-sort-row__remove" @click="filters.splice(index, 1); isDirty = true">删除</button>
                </div>
              </div>
              <div v-else class="query-settings-filter-empty">暂未设置组合筛选。</div>
            </div>

            <div class="query-settings-rule-card query-settings-aggregate-card">
              <div class="query-settings-rule-card__header">
                <h3>分组与合计</h3>
                <div class="query-settings-aggregate-actions">
                  <button type="button" class="button button--text" :disabled="groupBy.length >= 3 || groupFieldOptions.length === groupBy.length" @click="addGroup">新增分组</button>
                  <button type="button" class="button button--text" :disabled="summaries.length >= 8 || summaryFieldOptions.length === summaries.length" @click="addSummary">新增合计</button>
                </div>
              </div>
              <div v-if="groupBy.length" class="query-settings-aggregate-list">
                <div v-for="(fieldKey, index) in groupBy" :key="`group-${fieldKey}-${index}`" class="query-settings-group-row">
                  <span>分组 {{ index + 1 }}</span>
                  <select :value="fieldKey" @change="updateGroup(index, $event.target.value)">
                    <option v-for="field in groupFieldOptions" :key="field.key" :value="field.key" :disabled="groupBy.some((item, itemIndex) => itemIndex !== index && item === field.key)">{{ field.label }}</option>
                  </select>
                  <button type="button" class="query-settings-sort-row__remove" @click="groupBy.splice(index, 1); isDirty = true">删除</button>
                </div>
              </div>
              <div v-if="summaries.length" class="query-settings-aggregate-list">
                <div v-for="(summary, index) in summaries" :key="`summary-${summary.field}-${index}`" class="query-settings-summary-row">
                  <span>合计 {{ index + 1 }}</span>
                  <select :value="summary.field" @change="updateSummaryField(index, $event.target.value)">
                    <option v-for="field in summaryFieldOptions" :key="field.key" :value="field.key" :disabled="summaries.some((item, itemIndex) => itemIndex !== index && item.field === field.key)">{{ field.label }}</option>
                  </select>
                  <select v-model="summary.aggregation" @change="isDirty = true">
                    <option v-for="option in summaryAggregationOptions(summary.field)" :key="option.value" :value="option.value">{{ option.label }}</option>
                  </select>
                  <button type="button" class="query-settings-sort-row__remove" @click="summaries.splice(index, 1); isDirty = true">删除</button>
                </div>
              </div>
            </div>
          </section>
        </main>

        <footer class="query-settings-drawer__footer">
          <div v-if="showCustomFields || showFieldRename" class="query-settings-field-tools">
            <button
              v-if="showCustomFields"
              type="button"
              class="button button--secondary query-settings-custom-fields"
              :disabled="isSaving"
              @click="$emit('custom-fields')"
            >
              <Calculator :size="16" aria-hidden="true" />
              <span>自定义字段</span>
            </button>
            <button
              v-if="showFieldRename"
              type="button"
              class="button button--secondary query-settings-field-rename"
              :disabled="isSaving"
              @click="$emit('field-rename')"
            >
              <PencilLine :size="16" aria-hidden="true" />
              <span>字段改名</span>
            </button>
          </div>
          <button type="button" class="button button--text" :disabled="isSaving || Boolean(pendingSaveIdempotencyKey)" @click="restoreDefaults">恢复默认</button>
          <span v-if="saveError" class="query-settings-save-error">{{ saveError }}</span>
          <button type="button" class="button button--secondary" :disabled="isSaving || Boolean(pendingSaveIdempotencyKey)" @click="requestClose">关闭</button>
          <button type="button" class="button button--primary" :disabled="isSaving || unresolvedInvalidReferences.length > 0" @click="save">{{ isSaving ? '正在保存…' : (pendingSaveIdempotencyKey ? '按原请求重试' : '保存') }}</button>
        </footer>
      </section>

      <div v-if="isDiscardConfirmOpen" class="query-settings-discard" role="alertdialog" aria-modal="true" aria-label="放弃未保存修改">
        <div class="query-settings-discard__card">
          <h3>有未保存的修改</h3>
          <p>关闭后，本次未应用、未保存的设置会丢失。</p>
          <div>
            <button type="button" class="button button--secondary" @click="isDiscardConfirmOpen = false">继续编辑</button>
            <button type="button" class="button button--primary" @click="$emit('close')">确认退出</button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.query-settings-drawer {
  position: fixed;
  inset: 0;
  z-index: 2200;
  display: flex;
  justify-content: flex-end;
}

.query-settings-drawer__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 42, .42);
}

.query-settings-drawer__panel {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  width: min(920px, calc(100vw - 48px));
  height: 100vh;
  overflow: hidden;
  background: #fff;
  box-shadow: -12px 0 30px rgba(15, 23, 42, .18);
}

.query-settings-drawer__header,
.query-settings-drawer__footer {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 18px;
  border-color: #e5e7eb;
  background: #fff;
}

.query-settings-drawer__header {
  justify-content: space-between;
  border-bottom: 1px solid #e5e7eb;
}

.query-settings-drawer__header h2,
.query-settings-fields h3,
.query-settings-rule-card h3 {
  margin: 0;
  color: #1f2937;
  letter-spacing: 0;
}

.query-settings-drawer__header h2 {
  font-size: 18px;
}

.query-settings-drawer__body {
  display: grid;
  grid-template-columns: minmax(250px, 32%) minmax(0, 1fr);
  min-height: 0;
}

.query-settings-fields,
.query-settings-rules {
  min-height: 0;
  overflow-y: auto;
  padding: 16px;
}

.query-settings-fields {
  border-right: 1px solid #e5e7eb;
  background: #f8fafc;
}

.query-settings-fields h3,
.query-settings-rule-card h3 {
  font-size: 14px;
}

.query-settings-fields__list {
  display: grid;
  gap: 7px;
  margin-top: 10px;
}

.query-settings-field-option {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 30px;
  color: #374151;
  font-size: 13px;
}

.query-settings-field-option input {
  width: 16px;
  height: 16px;
  flex: none;
}

.query-settings-selected-order {
  margin-top: 18px;
}

.query-settings-selected-order__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-height: 32px;
  border-bottom: 1px solid #e5e7eb;
  color: #4b5563;
  font-size: 12px;
}

.query-settings-selected-order__row > span {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.query-settings-selected-order__row button,
.query-settings-sort-row__move,
.query-settings-sort-row__remove {
  min-height: 26px;
  padding: 0 7px;
  border: 1px solid #d7e0ea;
  border-radius: 6px;
  background: #fff;
  color: #4b5563;
  font-size: 12px;
}

.query-settings-fields__quick-title {
  margin-top: 18px !important;
}

.query-settings-rules {
  display: grid;
  align-content: start;
  gap: 12px;
}

.query-settings-rule-card {
  padding: 14px;
  border: 1px solid #e1e7ef;
  border-radius: 8px;
  background: #fff;
}

.query-settings-rule-card__header,
.query-settings-sort-row,
.query-settings-filter-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.query-settings-rule-card__header {
  justify-content: space-between;
  margin-bottom: 10px;
}

.query-settings-sort-list,
.query-settings-filter-list {
  display: grid;
  gap: 8px;
}

.query-settings-sort-row select,
.query-settings-filter-row select,
.query-settings-filter-row input {
  min-width: 0;
  height: 34px;
  flex: 1;
  padding: 0 9px;
  border: 1px solid #d7e0ea;
  border-radius: 6px;
  background: #fff;
}

.query-settings-order-number {
  width: 22px;
  flex: none;
  color: #6b7280;
  text-align: center;
}

.query-settings-empty,
.query-settings-inline-error,
.query-settings-save-error {
  margin: 8px 0 0;
  color: #6b7280;
  font-size: 12px;
}

.query-settings-inline-error,
.query-settings-save-error {
  color: #d4380d;
}

.query-settings-drawer__footer {
  justify-content: flex-end;
  border-top: 1px solid #e5e7eb;
}

.query-settings-field-tools {
  margin-right: auto;
}

.query-settings-discard {
  position: absolute;
  inset: 0;
  z-index: 3;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgba(15, 23, 42, .42);
}

.query-settings-discard__card {
  width: min(400px, 100%);
  padding: 20px;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 16px 36px rgba(15, 23, 42, .2);
}

.query-settings-discard__card h3,
.query-settings-discard__card p {
  margin-top: 0;
}

.query-settings-discard__card > div {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.query-settings-filter-value-select {
  flex: 1;
  min-width: 0;
}

.query-settings-filter-value-select--multiple {
  min-height: 82px;
  padding-top: 6px;
  padding-bottom: 6px;
}

.query-settings-entity-picker--multiple {
  white-space: normal;
  line-height: 1.35;
}

.query-settings-filter-clear {
  flex: none;
  min-height: 34px;
  border: 0;
  background: transparent;
  color: #697586;
  font-size: 13px;
}

.query-settings-invalid-references {
  display: grid;
  gap: 8px;
  margin-bottom: 16px;
  padding: 11px;
  border: 1px solid #ffd591;
  border-radius: 8px;
  background: #fffbe6;
  color: #874d00;
  font-size: 12px;
}

.query-settings-invalid-references > div {
  display: flex;
  align-items: center;
  gap: 6px;
}

.query-settings-invalid-references span {
  min-width: 0;
  flex: 1;
  line-height: 1.45;
}

.query-settings-filter-no-value {
  display: inline-flex;
  min-height: 34px;
  flex: 1;
  align-items: center;
  padding: 0 10px;
  border: 1px dashed #d5dde7;
  border-radius: 7px;
  color: #87919f;
  font-size: 12px;
}

.query-settings-field-tools {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  margin-right: auto;
}

.query-settings-custom-fields,
.query-settings-field-rename {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}

.query-settings-aggregate-actions,
.query-settings-aggregate-list,
.query-settings-group-row,
.query-settings-summary-row {
  display: flex;
  align-items: center;
}

.query-settings-aggregate-actions {
  gap: 4px;
}

.query-settings-aggregate-list {
  align-items: stretch;
  flex-direction: column;
  gap: 8px;
  margin-top: 10px;
}

.query-settings-group-row,
.query-settings-summary-row {
  gap: 8px;
}

.query-settings-group-row > span,
.query-settings-summary-row > span {
  width: 54px;
  flex: none;
  color: #697586;
  font-size: 12px;
}

.query-settings-group-row select,
.query-settings-summary-row select {
  min-width: 0;
  height: 34px;
  flex: 1;
}

.query-settings-summary-row select:nth-of-type(2) {
  flex: 0 0 112px;
}

@media (max-width: 760px) {
  .query-settings-drawer__panel {
    width: calc(100vw - 20px);
    height: calc(100vh - 20px);
    margin: 10px auto;
    border-radius: 8px;
  }

  .query-settings-drawer__body {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: minmax(190px, 38vh) minmax(0, 1fr);
    overflow: hidden;
  }

  .query-settings-fields {
    border-right: 0;
    border-bottom: 1px solid #e3e8ef;
  }

  .query-settings-rules {
    min-height: 0;
    overflow-y: auto;
  }

  .query-settings-sort-row,
  .query-settings-filter-row {
    flex-wrap: wrap;
  }

  .query-settings-sort-row select,
  .query-settings-filter-row select,
  .query-settings-filter-row input {
    flex: 1 1 120px;
  }

  .query-settings-order-number {
    width: 100%;
    text-align: left;
  }

  .query-settings-drawer__footer {
    flex-wrap: wrap;
    padding: 10px 12px;
  }

  .query-settings-field-tools {
    margin-right: 0;
  }

  .query-settings-save-error {
    order: -1;
    flex-basis: 100%;
  }
}
</style>
