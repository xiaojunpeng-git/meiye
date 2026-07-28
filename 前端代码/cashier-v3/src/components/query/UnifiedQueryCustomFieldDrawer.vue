<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import AlertTriangle from '@lucide/vue/dist/esm/icons/triangle-alert.mjs'
import Archive from '@lucide/vue/dist/esm/icons/archive.mjs'
import Calculator from '@lucide/vue/dist/esm/icons/calculator.mjs'
import ChevronRight from '@lucide/vue/dist/esm/icons/chevron-right.mjs'
import CircleUserRound from '@lucide/vue/dist/esm/icons/circle-user-round.mjs'
import Copy from '@lucide/vue/dist/esm/icons/copy.mjs'
import History from '@lucide/vue/dist/esm/icons/clock-arrow-left.mjs'
import Plus from '@lucide/vue/dist/esm/icons/plus.mjs'
import Save from '@lucide/vue/dist/esm/icons/save.mjs'
import Share2 from '@lucide/vue/dist/esm/icons/share-2.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'
import {
  buildCustomFieldExpression,
  normalizeCustomFieldExpressionForEditor,
  unwrapUnifiedQueryEnvelope,
  unifiedQueryActionIdempotencyKey,
  unifiedQueryActionMessage,
  unifiedQueryActionStatus
} from '@/services/unifiedQueryContract'

const props = defineProps({
  fields: { type: Array, default: () => [] },
  customFields: { type: Array, default: () => [] },
  initialFieldId: { type: String, default: '' },
  previewMode: { type: Boolean, default: false },
  pageName: { type: String, default: '当前查询页面' },
  permissions: { type: Object, default: () => ({}) },
  onSave: { type: Function, default: null },
  onStatusChange: { type: Function, default: null },
  onArchive: { type: Function, default: null }
})

const emit = defineEmits(['close', 'save', 'status-change', 'archive'])
const activeScope = ref('all')
const searchKeyword = ref('')
const selectedId = ref(props.initialFieldId || props.customFields[0]?.id || '')
const activeTab = ref('rule')
const isCreating = ref(false)
const toast = ref('')
const isSaving = ref(false)
const isChangingStatus = ref(false)
const archiveConfirmation = ref(false)
const pendingSaveIdempotencyKey = ref('')
const pendingStatusIdempotencyKey = ref('')
const pendingArchiveIdempotencyKey = ref('')
const ruleLoadError = ref('')

const RULE_KINDS = Object.freeze([
  { key: 'calculation', label: '基础计算' },
  { key: 'comparison', label: '比较判断' },
  { key: 'between', label: '区间判断' },
  { key: 'conditional', label: '如果／否则' },
  { key: 'null_check', label: '空值判断' },
  { key: 'aggregate', label: '受控聚合' },
  { key: 'bucket', label: '多档分组' }
])
const COMPARISON_OPERATORS = Object.freeze([
  { key: 'equal', label: '等于' },
  { key: 'not_equal', label: '不等于' },
  { key: 'greater_than', label: '大于' },
  { key: 'greater_or_equal', label: '大于等于' },
  { key: 'less_than', label: '小于' },
  { key: 'less_or_equal', label: '小于等于' }
])
const AGGREGATE_OPERATORS = Object.freeze([
  { key: 'sum', label: '求和' },
  { key: 'average', label: '平均值' },
  { key: 'minimum', label: '最小值' },
  { key: 'maximum', label: '最大值' },
  { key: 'count', label: '计数' }
])

function defaultExpressionDraft() {
  return {
    ruleKind: 'calculation',
    leftMode: 'field',
    leftField: '',
    leftValueType: 'decimal',
    operator: 'multiply',
    rightMode: 'field',
    rightField: '',
    constantValue: '',
    constantType: 'decimal',
    lowerValue: '',
    upperValue: '',
    conditionField: '',
    conditionOperator: 'greater_or_equal',
    conditionRightMode: 'constant',
    conditionRightField: '',
    conditionValue: '',
    conditionUpperValue: '',
    conditionValueType: 'decimal',
    trueValue: '是',
    falseValue: '否',
    bucketSource: 'field',
    buckets: [{ upperBound: '', result: '' }],
    bucketFallback: '',
    nullMode: 'empty',
    fallbackValue: ''
  }
}

const draft = reactive({
  name: '',
  returnType: 'amount',
  visibility: 'personal',
  shareScope: 'store',
  ...defaultExpressionDraft()
})

const visibleCustomFields = computed(() => props.customFields.filter((field) => {
  const matchesScope = activeScope.value === 'all' || field.visibility === activeScope.value
  const keyword = searchKeyword.value.trim().toLowerCase()
  const matchesKeyword = !keyword || String(field.name || '').toLowerCase().includes(keyword)
  return matchesScope && matchesKeyword
}))
const customFieldId = (field) => field?.id || field?.fieldId || field?.field_id || field?.key || ''
const selectedField = computed(() => props.customFields.find((field) => String(customFieldId(field)) === String(selectedId.value)) || null)
const fieldOptions = computed(() => props.fields.filter((field) => (
  !field.hidden
  && field.key !== 'id'
  && field.custom !== true
  && String(field.key) !== String(selectedField.value?.key || '')
  && (props.previewMode || field.capabilities?.customInput === true)
)))
const numericFieldOptions = computed(() => fieldOptions.value.filter((field) => isNumericType(fieldType(field.key))))
const dateFieldOptions = computed(() => fieldOptions.value.filter((field) => isDateType(fieldType(field.key))))
const orderedFieldOptions = computed(() => fieldOptions.value.filter((field) => (
  isNumericType(fieldType(field.key)) || isDateType(fieldType(field.key))
)))
const canCreate = computed(() => props.previewMode || props.permissions.createCustomField === true)
const hasSelectedVersion = computed(() => Number(selectedField.value?.version) > 0)
const canEdit = computed(() => props.previewMode || (
  props.permissions.editCustomField === true
  && selectedField.value?.canEdit !== false
  && hasSelectedVersion.value
))
const canSave = computed(() => (isCreating.value ? canCreate.value : canEdit.value) && !ruleLoadError.value)
const canShare = computed(() => props.previewMode || props.permissions.shareCustomField === true)
const canChangeStatus = computed(() => props.previewMode || (
  props.permissions.changeCustomFieldStatus === true
  && selectedField.value?.canChangeStatus !== false
  && selectedField.value?.status !== 'invalid'
  && hasSelectedVersion.value
))
const canArchive = computed(() => props.previewMode || (
  props.permissions.archiveCustomField === true
  && selectedField.value?.canArchive !== false
  && hasSelectedVersion.value
))
const formDisabled = computed(() => !canSave.value
  || Boolean(pendingSaveIdempotencyKey.value)
  || Boolean(pendingStatusIdempotencyKey.value)
  || Boolean(pendingArchiveIdempotencyKey.value))
const hasPendingCommand = computed(() => isSaving.value
  || isChangingStatus.value
  || Boolean(pendingSaveIdempotencyKey.value)
  || Boolean(pendingStatusIdempotencyKey.value)
  || Boolean(pendingArchiveIdempotencyKey.value))
const historyRows = computed(() => Array.isArray(selectedField.value?.history) ? selectedField.value.history : [])
const supportsNullHandling = computed(() => draft.ruleKind === 'calculation' || draft.ruleKind === 'bucket')
const returnTypeLocked = computed(() => draft.ruleKind !== 'conditional')
const activeCalculationFields = computed(() => draft.operator === 'date_diff' ? dateFieldOptions.value : numericFieldOptions.value)
const activeAggregateFields = computed(() => {
  if (['sum', 'average'].includes(draft.operator)) return numericFieldOptions.value
  if (['minimum', 'maximum'].includes(draft.operator)) return orderedFieldOptions.value
  return fieldOptions.value
})
const sampleResult = computed(() => {
  if (draft.ruleKind === 'comparison') return '根据比较结果显示“是／否”'
  if (draft.ruleKind === 'between') return '位于上下限之间时显示“是”'
  if (draft.ruleKind === 'conditional') return `条件成立显示“${draft.trueValue || '结果 A'}”，否则显示“${draft.falseValue || '结果 B'}”`
  if (draft.ruleKind === 'null_check') return '字段为空时显示“是”'
  if (draft.ruleKind === 'aggregate') return '按当前查询范围计算，与列表、合计和导出口径一致'
  if (draft.ruleKind === 'bucket') return '依次匹配“≤ 上界”，超过最后一档使用其他结果'
  if (draft.operator === 'multiply') return '12 × 86.50 = 1,038.00'
  if (draft.operator === 'date_diff') return '查询截止日期与日期字段相差的天数'
  if (draft.operator === 'divide') return '1,038 ÷ 12 = 86.50'
  return '1,038.00'
})

function normalizeValueType(value) {
  const normalized = String(value || '').trim().toLowerCase()
  const aliases = {
    number: 'decimal', money: 'amount', bool: 'boolean', string: 'text',
    store: 'text', status: 'text', tag: 'text', member: 'text', staff: 'text'
  }
  return aliases[normalized] || normalized || 'text'
}

function fieldType(fieldKey) {
  const field = fieldOptions.value.find((item) => item.key === fieldKey)
  return normalizeValueType(field?.queryType || field?.returnType || field?.return_type || field?.type)
}

function isNumericType(type) {
  return ['integer', 'decimal', 'amount'].includes(normalizeValueType(type))
}

function isDateType(type) {
  return ['date', 'datetime'].includes(normalizeValueType(type))
}

function compatibleFieldOptions(fieldKey, options = fieldOptions.value) {
  const sourceType = fieldType(fieldKey)
  return options.filter((field) => {
    const candidateType = fieldType(field.key)
    return sourceType === candidateType || (isNumericType(sourceType) && isNumericType(candidateType))
  })
}

function ensureField(current, options) {
  return options.some((field) => field.key === current) ? current : (options[0]?.key || '')
}

function syncDraftTypes() {
  draft.leftValueType = fieldType(draft.leftField)
  draft.constantType = draft.leftValueType
  draft.conditionValueType = fieldType(draft.conditionField)
}

function inferReturnType() {
  if (['comparison', 'between', 'null_check'].includes(draft.ruleKind)) return 'boolean'
  if (draft.ruleKind === 'bucket') return 'text'
  if (draft.ruleKind === 'aggregate') {
    if (draft.operator === 'count') return 'integer'
    const sourceType = fieldType(draft.leftField)
    if (draft.operator === 'average' && sourceType !== 'amount') return 'decimal'
    return sourceType || 'decimal'
  }
  if (draft.ruleKind !== 'calculation') return draft.returnType || 'text'
  if (draft.operator === 'date_diff') return 'integer'
  const leftType = fieldType(draft.leftField)
  const rightType = draft.rightMode === 'field' ? fieldType(draft.rightField) : draft.constantType
  if (draft.operator === 'divide') return leftType === 'amount' ? 'amount' : 'decimal'
  if (leftType === 'amount' || rightType === 'amount') return 'amount'
  return leftType === 'integer' && rightType === 'integer' ? 'integer' : 'decimal'
}

function refreshInferredReturnType() {
  syncDraftTypes()
  if (returnTypeLocked.value) draft.returnType = inferReturnType()
}

function applyEditableRule(editable) {
  const defaults = defaultExpressionDraft()
  for (const key of Object.keys(defaults)) {
    if (key === 'buckets') {
      draft.buckets = Array.isArray(editable.buckets) && editable.buckets.length
        ? editable.buckets.map((bucket) => ({ upperBound: bucket.upperBound ?? '', result: bucket.result ?? '' }))
        : defaults.buckets
      continue
    }
    draft[key] = editable[key] ?? defaults[key]
  }
  refreshInferredReturnType()
}

function loadField(field) {
  if (!field) return
  draft.name = field.name || ''
  draft.returnType = field.returnType || field.return_type || field.type || 'amount'
  draft.visibility = field.visibility || 'personal'
  const shareScope = field.shareScope || field.share_scope || 'store'
  draft.shareScope = shareScope === 'merchant' ? 'tenant' : shareScope
  const editable = normalizeCustomFieldExpressionForEditor(field)
  ruleLoadError.value = editable.loaded ? '' : editable.reason
  applyEditableRule(editable.loaded ? editable : defaultExpressionDraft())
  if (editable.loaded) draft.returnType = editable.returnType || field.returnType || field.return_type || field.type || draft.returnType
}

watch(selectedField, (field) => {
  if (!field) return
  isCreating.value = false
  activeTab.value = 'rule'
  archiveConfirmation.value = false
  pendingSaveIdempotencyKey.value = ''
  pendingStatusIdempotencyKey.value = ''
  pendingArchiveIdempotencyKey.value = ''
  toast.value = ''
  loadField(field)
}, { immediate: true, deep: true })

watch(() => props.customFields, (fields) => {
  if (selectedId.value && fields.some((field) => String(customFieldId(field)) === String(selectedId.value))) return
  if (!fields.length && canCreate.value) {
    createField()
    return
  }
  if (!isCreating.value) selectedId.value = customFieldId(fields[0])
}, { deep: true, immediate: true })

function selectField(field) {
  if (hasPendingCommand.value) {
    toast.value = '当前操作结果尚未确认，请先按原请求重试。'
    return
  }
  selectedId.value = customFieldId(field)
}

function createField() {
  if (!canCreate.value || hasPendingCommand.value) return
  isCreating.value = true
  selectedId.value = ''
  activeTab.value = 'rule'
  draft.name = ''
  draft.returnType = 'amount'
  draft.visibility = 'personal'
  draft.shareScope = 'store'
  applyEditableRule(defaultExpressionDraft())
  draft.leftField = numericFieldOptions.value[0]?.key || ''
  draft.rightField = numericFieldOptions.value[1]?.key || numericFieldOptions.value[0]?.key || ''
  refreshInferredReturnType()
  ruleLoadError.value = ''
  pendingSaveIdempotencyKey.value = ''
  pendingStatusIdempotencyKey.value = ''
  pendingArchiveIdempotencyKey.value = ''
}

function changeRuleKind(event) {
  const kind = event.target.value
  const defaults = defaultExpressionDraft()
  applyEditableRule({ ...defaults, ruleKind: kind })
  if (kind === 'calculation') {
    draft.leftField = numericFieldOptions.value[0]?.key || ''
    draft.rightField = numericFieldOptions.value[1]?.key || numericFieldOptions.value[0]?.key || ''
  } else if (kind === 'comparison') {
    draft.operator = 'equal'
    draft.leftField = fieldOptions.value[0]?.key || ''
    draft.rightField = compatibleFieldOptions(draft.leftField)[1]?.key || compatibleFieldOptions(draft.leftField)[0]?.key || ''
  } else if (kind === 'between') {
    draft.leftField = orderedFieldOptions.value[0]?.key || ''
  } else if (kind === 'conditional') {
    draft.conditionField = fieldOptions.value[0]?.key || ''
    draft.conditionRightField = compatibleFieldOptions(draft.conditionField)[0]?.key || ''
    draft.returnType = 'text'
  } else if (kind === 'null_check') {
    draft.leftField = fieldOptions.value[0]?.key || ''
  } else if (kind === 'aggregate') {
    draft.operator = 'sum'
    draft.leftField = numericFieldOptions.value[0]?.key || ''
  } else if (kind === 'bucket') {
    draft.leftField = orderedFieldOptions.value[0]?.key || ''
    draft.returnType = 'text'
  }
  refreshInferredReturnType()
}

function changeMainOperator() {
  if (draft.ruleKind === 'calculation') {
    const options = activeCalculationFields.value
    draft.leftField = ensureField(draft.leftField, options)
    if (draft.operator === 'date_diff') {
      draft.leftMode = 'query_cutoff'
      draft.rightMode = 'field'
      draft.rightField = ensureField(draft.rightField, dateFieldOptions.value)
      draft.nullMode = draft.nullMode === 'zero' ? 'empty' : draft.nullMode
    } else {
      draft.leftMode = 'field'
      draft.rightMode = draft.rightMode === 'query_cutoff' ? 'field' : draft.rightMode
      draft.rightField = ensureField(draft.rightField, numericFieldOptions.value)
    }
  } else if (draft.ruleKind === 'aggregate') {
    draft.leftField = ensureField(draft.leftField, activeAggregateFields.value)
    draft.nullMode = 'empty'
  }
  refreshInferredReturnType()
}

function onLeftFieldChange() {
  syncDraftTypes()
  if (draft.rightMode === 'query_cutoff' && !canCompareWithQueryCutoff(draft.leftField)) {
    draft.rightMode = 'constant'
  }
  if (draft.rightMode === 'field') {
    const options = compatibleFieldOptions(draft.leftField, draft.operator === 'divide'
      ? numericFieldOptions.value.filter((field) => fieldType(field.key) !== 'amount')
      : fieldOptions.value)
    draft.rightField = ensureField(draft.rightField, options)
  }
  refreshInferredReturnType()
}

function onConditionFieldChange() {
  draft.conditionValueType = fieldType(draft.conditionField)
  draft.conditionRightField = ensureField(draft.conditionRightField, compatibleFieldOptions(draft.conditionField))
  if (!canCompareWithQueryCutoff(draft.conditionField) && draft.conditionRightMode === 'query_cutoff') draft.conditionRightMode = 'constant'
}

function dateOperandValue(side) {
  return draft[`${side}Mode`] === 'query_cutoff' ? '__query_cutoff__' : draft[`${side}Field`]
}

function setDateOperand(side, value) {
  if (value === '__query_cutoff__') {
    draft[`${side}Mode`] = 'query_cutoff'
    draft[`${side}Field`] = ''
  } else {
    draft[`${side}Mode`] = 'field'
    draft[`${side}Field`] = value
  }
  refreshInferredReturnType()
}

function setRightMode(mode, prefix = '') {
  const modeKey = prefix ? `${prefix}RightMode` : 'rightMode'
  const fieldKey = prefix ? `${prefix}RightField` : 'rightField'
  draft[modeKey] = mode
  if (mode === 'field') {
    const sourceField = prefix ? draft.conditionField : draft.leftField
    draft[fieldKey] = ensureField(draft[fieldKey], compatibleFieldOptions(sourceField))
  }
  refreshInferredReturnType()
}

function changeBucketSource() {
  if (draft.bucketSource === 'date_diff') {
    draft.leftMode = 'query_cutoff'
    draft.leftField = ''
    draft.rightMode = 'field'
    draft.rightField = dateFieldOptions.value[0]?.key || ''
  } else {
    draft.leftMode = 'field'
    draft.leftField = orderedFieldOptions.value[0]?.key || ''
    draft.rightMode = 'field'
    draft.rightField = ''
  }
  refreshInferredReturnType()
}

function canCompareWithQueryCutoff(fieldKey) {
  return fieldType(fieldKey) === 'date'
}

function addBucket() {
  if (draft.buckets.length >= 12) return
  draft.buckets.push({ upperBound: '', result: '' })
}

function removeBucket(index) {
  if (draft.buckets.length <= 1) return
  draft.buckets.splice(index, 1)
}

function literalInputType(valueType) {
  if (isNumericType(valueType)) return 'number'
  if (normalizeValueType(valueType) === 'date') return 'date'
  return 'text'
}

function validateLiteral(value, valueType, label) {
  const text = String(value ?? '').trim()
  if (!text) {
    toast.value = `请填写${label}。`
    return false
  }
  const type = normalizeValueType(valueType)
  if (isNumericType(type) && !/^-?(?:0|[1-9]\d{0,17})(?:\.\d{1,8})?$/.test(text)) {
    toast.value = `${label}必须是有效数值，最多 8 位小数。`
    return false
  }
  if (type === 'integer' && text.includes('.')) {
    toast.value = `${label}必须是整数。`
    return false
  }
  if (type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(text)) {
    toast.value = `${label}必须使用 YYYY-MM-DD。`
    return false
  }
  if (type === 'datetime' && !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text)) {
    toast.value = `${label}必须使用 YYYY-MM-DD HH:MM:SS。`
    return false
  }
  if (type === 'boolean' && !['true', 'false', '1', '0'].includes(text.toLowerCase())) {
    toast.value = `${label}必须选择是或否。`
    return false
  }
  if (type === 'text' && (/^[=+\-@]/.test(text) || text.length > 255)) {
    toast.value = `${label}不能是 Excel 公式，且不能超过 255 个字。`
    return false
  }
  return true
}

function validateDateOperands() {
  const sides = ['left', 'right']
  for (const side of sides) {
    if (draft[`${side}Mode`] === 'field') {
      const fieldKey = draft[`${side}Field`]
      if (!fieldKey || !isDateType(fieldType(fieldKey))) {
        toast.value = '日期差两侧只能选择日期字段或查询截止日期。'
        return false
      }
    }
  }
  if (draft.leftMode === 'query_cutoff' && draft.rightMode === 'query_cutoff') {
    toast.value = '日期差不能在两侧同时使用查询截止日期。'
    return false
  }
  return true
}

function validateRightOperand(prefix = '') {
  const rightMode = draft[prefix ? `${prefix}RightMode` : 'rightMode']
  const sourceField = draft[prefix ? 'conditionField' : 'leftField']
  if (rightMode === 'field') {
    const rightField = draft[prefix ? `${prefix}RightField` : 'rightField']
    if (!rightField || !compatibleFieldOptions(sourceField).some((field) => field.key === rightField)) {
      toast.value = '请选择与左侧类型一致的字段。'
      return false
    }
    return true
  }
  if (rightMode === 'query_cutoff') {
    if (!canCompareWithQueryCutoff(sourceField)) {
      toast.value = '只有日期字段可以与查询截止日期直接比较。'
      return false
    }
    return true
  }
  const value = draft[prefix ? `${prefix}Value` : 'constantValue']
  return validateLiteral(value, fieldType(sourceField), '固定值')
}

function validateDraft() {
  if (ruleLoadError.value) {
    toast.value = ruleLoadError.value
    return false
  }
  if (!draft.name.trim()) {
    toast.value = '请填写字段名称。'
    return false
  }
  if (draft.visibility === 'shared' && !canShare.value) {
    toast.value = '当前账号不能发布共享字段。'
    return false
  }
  refreshInferredReturnType()
  if (draft.ruleKind === 'calculation') {
    if (draft.operator === 'date_diff') {
      if (!validateDateOperands()) return false
    } else {
      if (!draft.leftField || !isNumericType(fieldType(draft.leftField)) || !validateRightOperand()) {
        if (!toast.value) toast.value = '基础计算只能使用数值字段。'
        return false
      }
      if (draft.operator === 'multiply' && draft.rightMode === 'field'
        && fieldType(draft.leftField) === 'amount' && fieldType(draft.rightField) === 'amount') {
        toast.value = '金额不能直接与金额相乘。'
        return false
      }
      if (draft.operator === 'divide') {
        if (draft.rightMode === 'field' && fieldType(draft.rightField) === 'amount') {
          toast.value = '除数不能是金额字段。'
          return false
        }
        if (draft.rightMode === 'constant' && Number(draft.constantValue) === 0) {
          toast.value = '除数不能为 0。'
          return false
        }
      }
    }
  } else if (draft.ruleKind === 'comparison') {
    if (!draft.leftField || !validateRightOperand()) return false
  } else if (draft.ruleKind === 'between') {
    if (!draft.leftField
      || !validateLiteral(draft.lowerValue, fieldType(draft.leftField), '区间下限')
      || !validateLiteral(draft.upperValue, fieldType(draft.leftField), '区间上限')) return false
  } else if (draft.ruleKind === 'conditional') {
    if (!draft.conditionField) {
      toast.value = '请选择条件字段。'
      return false
    }
    if (draft.conditionOperator === 'between') {
      if (!validateLiteral(draft.conditionValue, fieldType(draft.conditionField), '条件下限')
        || !validateLiteral(draft.conditionUpperValue, fieldType(draft.conditionField), '条件上限')) return false
    } else if (draft.conditionOperator !== 'is_null' && !validateRightOperand('condition')) return false
    if (!validateLiteral(draft.trueValue, draft.returnType, '条件成立结果')
      || !validateLiteral(draft.falseValue, draft.returnType, '条件不成立结果')) return false
  } else if (draft.ruleKind === 'null_check') {
    if (!draft.leftField) {
      toast.value = '请选择要判空的字段。'
      return false
    }
  } else if (draft.ruleKind === 'aggregate') {
    if (!draft.leftField || !activeAggregateFields.value.some((field) => field.key === draft.leftField)) {
      toast.value = '请选择当前聚合方式支持的字段。'
      return false
    }
  } else if (draft.ruleKind === 'bucket') {
    if (draft.bucketSource === 'date_diff') {
      if (!validateDateOperands()) return false
    } else if (!draft.leftField || !orderedFieldOptions.value.some((field) => field.key === draft.leftField)) {
      toast.value = '多档分组只支持数值或日期字段。'
      return false
    }
    if (!draft.buckets.length || draft.buckets.length > 12) {
      toast.value = '多档分组只支持 1 到 12 档。'
      return false
    }
    const boundaryType = draft.bucketSource === 'date_diff' ? 'integer' : fieldType(draft.leftField)
    let previous = null
    for (let index = 0; index < draft.buckets.length; index += 1) {
      const bucket = draft.buckets[index]
      if (!validateLiteral(bucket.upperBound, boundaryType, `第 ${index + 1} 档上界`)
        || !validateLiteral(bucket.result, draft.returnType, `第 ${index + 1} 档结果`)) return false
      const comparable = isNumericType(boundaryType) ? Number(bucket.upperBound) : String(bucket.upperBound)
      if (previous !== null && comparable <= previous) {
        toast.value = '每档上界必须严格递增，不能重叠。'
        return false
      }
      previous = comparable
    }
    if (!validateLiteral(draft.bucketFallback, draft.returnType, '其他结果')) return false
  }
  if (supportsNullHandling.value && draft.nullMode === 'fallback'
    && !validateLiteral(draft.fallbackValue, draft.returnType, '空值备用值')) return false
  return true
}

async function saveDraft() {
  if (isSaving.value || !canSave.value || !validateDraft()) return
  toast.value = ''
  const wasCreating = isCreating.value
  const previousKeys = new Set(props.customFields.map((field) => String(customFieldId(field))))
  const payload = {
    fieldKey: isCreating.value ? undefined : selectedField.value?.key || customFieldId(selectedField.value),
    expectedVersion: isCreating.value ? undefined : selectedField.value?.version,
    name: draft.name.trim(),
    returnType: draft.returnType,
    visibility: draft.visibility,
    shareScope: draft.visibility === 'shared' ? draft.shareScope : null,
    nullMode: supportsNullHandling.value ? draft.nullMode : 'empty',
    nullFallback: supportsNullHandling.value && draft.nullMode === 'fallback' ? String(draft.fallbackValue).trim() : null,
    expression: buildCustomFieldExpression(draft),
    commandIdempotencyKey: pendingSaveIdempotencyKey.value
  }
  if (props.previewMode && !props.onSave) {
    toast.value = '界面确认稿未连接真实保存。'
    emit('save', payload)
    return
  }
  isSaving.value = true
  try {
    const result = props.onSave ? await props.onSave(payload) : null
    if (!props.onSave) emit('save', payload)
    const status = unifiedQueryActionStatus(result)
    if (props.onSave && status === 'result_unknown') {
      pendingSaveIdempotencyKey.value = unifiedQueryActionIdempotencyKey(result)
      toast.value = unifiedQueryActionMessage(result, '保存结果未知，请按原请求重试。')
      return
    }
    if (props.onSave && status !== 'success') {
      pendingSaveIdempotencyKey.value = ''
      toast.value = unifiedQueryActionMessage(result, '字段保存失败，请检查后重试。')
      return
    }
    pendingSaveIdempotencyKey.value = ''
    if (wasCreating) {
      const envelope = unwrapUnifiedQueryEnvelope(result)
      const data = envelope?.data && typeof envelope.data === 'object' ? envelope.data : {}
      const returned = data.customField || data.custom_field || data.field || data
      const returnedKey = customFieldId(returned)
      await nextTick()
      const created = props.customFields.find((field) => (
        (returnedKey && String(customFieldId(field)) === String(returnedKey))
        || (!previousKeys.has(String(customFieldId(field))) && field.name === payload.name)
      ))
      isCreating.value = false
      selectedId.value = created ? customFieldId(created) : returnedKey
      if (created) loadField(created)
    }
    toast.value = wasCreating ? '字段已创建。' : '字段新版本已保存。'
  } catch (error) {
    toast.value = error?.message || '字段保存失败，请检查网络后重试。'
  } finally {
    isSaving.value = false
  }
}

function copyField() {
  if (!canCreate.value) return
  draft.name = `${draft.name} - 副本`
  isCreating.value = true
  selectedId.value = ''
  toast.value = ''
  pendingSaveIdempotencyKey.value = ''
}

async function changeStatus() {
  if (!selectedField.value || !canChangeStatus.value || isChangingStatus.value) return
  archiveConfirmation.value = false
  toast.value = ''
  const status = selectedField.value.status === 'active' ? 'inactive' : 'active'
  const payload = {
    fieldKey: selectedField.value?.key || customFieldId(selectedField.value),
    expectedVersion: selectedField.value.version,
    status,
    commandIdempotencyKey: pendingStatusIdempotencyKey.value
  }
  if (props.previewMode && !props.onStatusChange) {
    emit('status-change', payload)
    toast.value = '界面确认稿未连接真实状态变更。'
    return
  }
  isChangingStatus.value = true
  try {
    const result = props.onStatusChange ? await props.onStatusChange(payload) : null
    if (!props.onStatusChange) emit('status-change', payload)
    const resultStatus = unifiedQueryActionStatus(result)
    if (props.onStatusChange && resultStatus === 'result_unknown') {
      pendingStatusIdempotencyKey.value = unifiedQueryActionIdempotencyKey(result)
      toast.value = unifiedQueryActionMessage(result, '状态变更结果未知，请按原请求重试。')
      return
    }
    if (props.onStatusChange && resultStatus !== 'success') {
      pendingStatusIdempotencyKey.value = ''
      toast.value = unifiedQueryActionMessage(result, '字段状态修改失败。')
      return
    }
    pendingStatusIdempotencyKey.value = ''
    toast.value = status === 'active' ? '字段已恢复。' : '字段已停用；已有引用将按服务端结果提示处理。'
  } catch (error) {
    toast.value = error?.message || '字段状态修改失败。'
  } finally {
    isChangingStatus.value = false
  }
}

async function archiveField() {
  if (!selectedField.value || !canArchive.value || isChangingStatus.value) return
  if (!archiveConfirmation.value && !pendingArchiveIdempotencyKey.value) {
    archiveConfirmation.value = true
    toast.value = '再次点击“确认删除”执行归档；存在引用时服务端会拒绝。'
    return
  }
  const payload = {
    fieldKey: selectedField.value?.key || customFieldId(selectedField.value),
    expectedVersion: selectedField.value.version,
    commandIdempotencyKey: pendingArchiveIdempotencyKey.value
  }
  isChangingStatus.value = true
  try {
    const result = props.onArchive ? await props.onArchive(payload) : null
    if (!props.onArchive) emit('archive', payload)
    const resultStatus = unifiedQueryActionStatus(result)
    if (props.onArchive && resultStatus === 'result_unknown') {
      pendingArchiveIdempotencyKey.value = unifiedQueryActionIdempotencyKey(result)
      toast.value = unifiedQueryActionMessage(result, '删除结果未知，请按原请求重试。')
      return
    }
    if (props.onArchive && resultStatus !== 'success') {
      pendingArchiveIdempotencyKey.value = ''
      toast.value = unifiedQueryActionMessage(result, '字段删除失败。')
      return
    }
    pendingArchiveIdempotencyKey.value = ''
    toast.value = '字段已归档。'
    archiveConfirmation.value = false
  } catch (error) {
    toast.value = error?.message || '字段删除失败。'
  } finally {
    isChangingStatus.value = false
  }
}

function requestClose() {
  if (hasPendingCommand.value) {
    toast.value = '当前操作结果尚未确认，请先按原请求重试。'
    return
  }
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <div class="custom-field-layer" role="dialog" aria-modal="true" aria-label="自定义字段">
      <div class="custom-field-layer__backdrop" @click="requestClose" />
      <section class="custom-field-panel">
        <header class="custom-field-header">
          <div class="custom-field-header__title">
            <span class="custom-field-header__icon"><Calculator :size="20" /></span>
            <div>
              <h2>自定义字段</h2>
              <p>{{ pageName }}</p>
            </div>
          </div>
          <button type="button" class="icon-button" title="关闭" aria-label="关闭" :disabled="hasPendingCommand" @click="requestClose"><X :size="19" /></button>
        </header>

        <div class="custom-field-body">
          <aside class="custom-field-sidebar">
            <button type="button" class="button button--primary custom-field-create" :disabled="!canCreate || isSaving || isChangingStatus" @click="createField"><Plus :size="16" />新建字段</button>
            <div class="custom-field-scope" role="tablist" aria-label="字段范围">
              <button v-for="scope in [{ key: 'all', label: '全部' }, { key: 'personal', label: '个人' }, { key: 'shared', label: '共享' }]" :key="scope.key" type="button" :class="{ active: activeScope === scope.key }" @click="activeScope = scope.key">{{ scope.label }}</button>
            </div>
            <input v-model="searchKeyword" class="custom-field-search" type="search" placeholder="搜索字段名称">
            <div class="custom-field-list">
              <button
                v-for="field in visibleCustomFields"
                :key="customFieldId(field)"
                type="button"
                class="custom-field-list__item"
                :class="{ active: String(selectedId) === String(customFieldId(field)) && !isCreating, invalid: field.status !== 'active' }"
                @click="selectField(field)"
              >
                <span class="custom-field-list__main">
                  <strong>{{ field.name }}</strong>
                  <small>{{ field.returnTypeLabel || field.return_type_label || field.type || '字段' }} · {{ Number(field.version) > 0 ? `v${field.version}` : '版本未返回' }}</small>
                </span>
                <span class="custom-field-list__meta">
                  <span v-if="field.status !== 'active'" class="custom-field-status custom-field-status--warning">{{ field.statusLabel || field.status_label || (field.status === 'invalid' ? '引用失效' : '已停用') }}</span>
                  <CircleUserRound v-else-if="field.visibility === 'personal'" :size="15" aria-label="个人字段" />
                  <Share2 v-else :size="15" aria-label="共享字段" />
                  <ChevronRight :size="15" />
                </span>
              </button>
              <div v-if="!visibleCustomFields.length" class="custom-field-empty">没有符合条件的字段</div>
            </div>
          </aside>

          <main class="custom-field-editor">
            <div class="custom-field-editor__topline">
              <div>
                <span class="custom-field-eyebrow">{{ isCreating ? '新建字段' : `稳定标识 ${selectedField?.code || ''}` }}</span>
                <h3>{{ isCreating ? '配置新字段' : selectedField?.name }}</h3>
              </div>
            <div v-if="!isCreating" class="custom-field-editor__actions">
                <button v-if="previewMode" type="button" class="button button--secondary" :disabled="!canCreate || isChangingStatus" @click="copyField"><Copy :size="15" />复制</button>
                <button v-if="canChangeStatus" type="button" class="button button--secondary" :disabled="isChangingStatus || Boolean(pendingArchiveIdempotencyKey)" @click="changeStatus"><Archive :size="15" />{{ pendingStatusIdempotencyKey ? '按原请求重试' : (selectedField?.status === 'active' ? '停用' : '恢复') }}</button>
                <button v-if="canArchive" type="button" class="button button--secondary custom-field-archive" :disabled="isChangingStatus || Boolean(pendingStatusIdempotencyKey)" @click="archiveField"><Archive :size="15" />{{ pendingArchiveIdempotencyKey ? '按原请求重试' : (archiveConfirmation ? '确认删除' : '删除') }}</button>
            </div>
          </div>

            <div v-if="ruleLoadError && !isCreating" class="custom-field-warning">
              <AlertTriangle :size="18" />
              <div><strong>规则暂不能编辑</strong><span>{{ ruleLoadError }}</span></div>
            </div>

              <div v-if="selectedField?.status && selectedField.status !== 'active' && !isCreating" class="custom-field-warning">
                <AlertTriangle :size="18" />
              <div><strong>字段当前不可用</strong><span>{{ selectedField.invalidReason || selectedField.invalid_reason || (selectedField.status === 'inactive' ? '字段已停用，不能用于新的查询配置。' : '引用字段已失效，请修改规则。') }}</span></div>
            </div>

            <nav class="custom-field-tabs" aria-label="字段配置">
              <button type="button" :class="{ active: activeTab === 'rule' }" @click="activeTab = 'rule'">计算规则</button>
              <button type="button" :class="{ active: activeTab === 'usage' }" @click="activeTab = 'usage'">使用范围</button>
              <button type="button" :class="{ active: activeTab === 'history' }" @click="activeTab = 'history'">版本记录</button>
            </nav>

            <div v-if="activeTab === 'rule'" class="custom-field-form">
              <div class="custom-field-form__grid">
                <label><span>字段名称</span><input v-model="draft.name" maxlength="32" placeholder="例如：库存金额" :disabled="formDisabled"></label>
                <label>
                  <span>规则模板</span>
                  <select :value="draft.ruleKind" :disabled="formDisabled" @change="changeRuleKind">
                    <option v-for="kind in RULE_KINDS" :key="kind.key" :value="kind.key">{{ kind.label }}</option>
                  </select>
                </label>
              </div>

              <section class="custom-field-rule-card">
                <div class="custom-field-rule-card__header">
                  <div><h4>{{ RULE_KINDS.find((kind) => kind.key === draft.ruleKind)?.label || '结构化规则' }}</h4></div>
                  <span class="custom-field-rule-badge">受控表达式</span>
                </div>

                <div v-if="draft.ruleKind === 'calculation'" class="custom-field-rule-grid">
                  <label>
                    <span>第一项</span>
                    <select
                      v-if="draft.operator === 'date_diff'"
                      :value="dateOperandValue('left')"
                      :disabled="formDisabled"
                      @change="setDateOperand('left', $event.target.value)"
                    >
                      <option value="__query_cutoff__">查询截止日期</option>
                      <option v-for="field in dateFieldOptions" :key="`date-left-${field.key}`" :value="field.key">{{ field.label }}</option>
                    </select>
                    <select v-else v-model="draft.leftField" :disabled="formDisabled" @change="onLeftFieldChange">
                      <option v-for="field in numericFieldOptions" :key="field.key" :value="field.key">{{ field.label }}</option>
                    </select>
                  </label>
                  <label>
                    <span>计算方式</span>
                    <select v-model="draft.operator" :disabled="formDisabled" @change="changeMainOperator">
                      <option value="add">相加</option>
                      <option value="subtract">相减</option>
                      <option value="multiply">相乘</option>
                      <option value="divide">相除</option>
                      <option value="date_diff">相差天数</option>
                    </select>
                  </label>
                  <label v-if="draft.operator === 'date_diff'">
                    <span>第二项</span>
                    <select :value="dateOperandValue('right')" :disabled="formDisabled" @change="setDateOperand('right', $event.target.value)">
                      <option value="__query_cutoff__">查询截止日期</option>
                      <option v-for="field in dateFieldOptions" :key="`date-right-${field.key}`" :value="field.key">{{ field.label }}</option>
                    </select>
                  </label>
                  <template v-else>
                    <label>
                      <span>第二项来源</span>
                      <select :value="draft.rightMode" :disabled="formDisabled" @change="setRightMode($event.target.value)">
                        <option value="field">另一个字段</option>
                        <option value="constant">固定值</option>
                      </select>
                    </label>
                    <label>
                      <span>第二项</span>
                      <select v-if="draft.rightMode === 'field'" v-model="draft.rightField" :disabled="formDisabled">
                        <option v-for="field in compatibleFieldOptions(draft.leftField, numericFieldOptions)" :key="field.key" :value="field.key">{{ field.label }}</option>
                      </select>
                      <input v-else v-model="draft.constantValue" type="number" step="any" placeholder="输入固定数值" :disabled="formDisabled">
                    </label>
                  </template>
                </div>

                <div v-else-if="draft.ruleKind === 'comparison'" class="custom-field-rule-grid">
                  <label><span>比较字段</span><select v-model="draft.leftField" :disabled="formDisabled" @change="onLeftFieldChange"><option v-for="field in fieldOptions" :key="field.key" :value="field.key">{{ field.label }}</option></select></label>
                  <label><span>判断方式</span><select v-model="draft.operator" :disabled="formDisabled"><option v-for="operator in COMPARISON_OPERATORS" :key="operator.key" :value="operator.key">{{ operator.label }}</option></select></label>
                  <label>
                    <span>比较对象</span>
                    <select :value="draft.rightMode" :disabled="formDisabled" @change="setRightMode($event.target.value)">
                      <option value="field">另一个字段</option>
                      <option value="constant">固定值</option>
                      <option v-if="canCompareWithQueryCutoff(draft.leftField)" value="query_cutoff">查询截止日期</option>
                    </select>
                  </label>
                  <label>
                    <span>比较值</span>
                    <select v-if="draft.rightMode === 'field'" v-model="draft.rightField" :disabled="formDisabled"><option v-for="field in compatibleFieldOptions(draft.leftField)" :key="field.key" :value="field.key">{{ field.label }}</option></select>
                    <select v-else-if="draft.rightMode === 'constant' && normalizeValueType(fieldType(draft.leftField)) === 'boolean'" v-model="draft.constantValue" :disabled="formDisabled"><option value="">请选择</option><option value="true">是</option><option value="false">否</option></select>
                    <input v-else-if="draft.rightMode === 'constant'" v-model="draft.constantValue" :type="literalInputType(fieldType(draft.leftField))" step="any" placeholder="输入固定值" :disabled="formDisabled">
                    <span v-else class="custom-field-context-value">查询截止日期</span>
                  </label>
                </div>

                <div v-else-if="draft.ruleKind === 'between'" class="custom-field-rule-grid">
                  <label><span>判断字段</span><select v-model="draft.leftField" :disabled="formDisabled" @change="syncDraftTypes"><option v-for="field in orderedFieldOptions" :key="field.key" :value="field.key">{{ field.label }}</option></select></label>
                  <label><span>区间下限（包含）</span><input v-model="draft.lowerValue" :type="literalInputType(fieldType(draft.leftField))" step="any" placeholder="输入下限" :disabled="formDisabled"></label>
                  <label><span>区间上限（包含）</span><input v-model="draft.upperValue" :type="literalInputType(fieldType(draft.leftField))" step="any" placeholder="输入上限" :disabled="formDisabled"></label>
                </div>

                <div v-else-if="draft.ruleKind === 'conditional'" class="custom-field-conditional">
                  <div class="custom-field-rule-grid">
                    <label><span>条件字段</span><select v-model="draft.conditionField" :disabled="formDisabled" @change="onConditionFieldChange"><option v-for="field in fieldOptions" :key="field.key" :value="field.key">{{ field.label }}</option></select></label>
                    <label><span>条件</span><select v-model="draft.conditionOperator" :disabled="formDisabled"><option v-for="operator in COMPARISON_OPERATORS" :key="operator.key" :value="operator.key">{{ operator.label }}</option><option value="between">位于区间</option><option value="is_null">为空</option></select></label>
                    <template v-if="draft.conditionOperator === 'between'">
                      <label><span>条件下限</span><input v-model="draft.conditionValue" :type="literalInputType(fieldType(draft.conditionField))" step="any" :disabled="formDisabled"></label>
                      <label><span>条件上限</span><input v-model="draft.conditionUpperValue" :type="literalInputType(fieldType(draft.conditionField))" step="any" :disabled="formDisabled"></label>
                    </template>
                    <template v-else-if="draft.conditionOperator !== 'is_null'">
                      <label><span>比较对象</span><select :value="draft.conditionRightMode" :disabled="formDisabled" @change="setRightMode($event.target.value, 'condition')"><option value="field">另一个字段</option><option value="constant">固定值</option><option v-if="canCompareWithQueryCutoff(draft.conditionField)" value="query_cutoff">查询截止日期</option></select></label>
                      <label>
                        <span>比较值</span>
                        <select v-if="draft.conditionRightMode === 'field'" v-model="draft.conditionRightField" :disabled="formDisabled"><option v-for="field in compatibleFieldOptions(draft.conditionField)" :key="field.key" :value="field.key">{{ field.label }}</option></select>
                        <select v-else-if="draft.conditionRightMode === 'constant' && normalizeValueType(fieldType(draft.conditionField)) === 'boolean'" v-model="draft.conditionValue" :disabled="formDisabled"><option value="">请选择</option><option value="true">是</option><option value="false">否</option></select>
                        <input v-else-if="draft.conditionRightMode === 'constant'" v-model="draft.conditionValue" :type="literalInputType(fieldType(draft.conditionField))" step="any" :disabled="formDisabled">
                        <span v-else class="custom-field-context-value">查询截止日期</span>
                      </label>
                    </template>
                  </div>
                  <div class="custom-field-rule-grid custom-field-branch-results">
                    <label><span>条件成立结果</span><select v-if="draft.returnType === 'boolean'" v-model="draft.trueValue" :disabled="formDisabled"><option value="true">是</option><option value="false">否</option></select><input v-else v-model="draft.trueValue" :type="literalInputType(draft.returnType)" step="any" :disabled="formDisabled"></label>
                    <label><span>条件不成立结果</span><select v-if="draft.returnType === 'boolean'" v-model="draft.falseValue" :disabled="formDisabled"><option value="true">是</option><option value="false">否</option></select><input v-else v-model="draft.falseValue" :type="literalInputType(draft.returnType)" step="any" :disabled="formDisabled"></label>
                  </div>
                </div>

                <div v-else-if="draft.ruleKind === 'null_check'" class="custom-field-rule-grid">
                  <label><span>判空字段</span><select v-model="draft.leftField" :disabled="formDisabled"><option v-for="field in fieldOptions" :key="field.key" :value="field.key">{{ field.label }}</option></select></label>
                  <div class="custom-field-rule-note">字段没有值时返回“是”，有值时返回“否”。</div>
                </div>

                <div v-else-if="draft.ruleKind === 'aggregate'" class="custom-field-rule-grid">
                  <label><span>聚合方式</span><select v-model="draft.operator" :disabled="formDisabled" @change="changeMainOperator"><option v-for="operator in AGGREGATE_OPERATORS" :key="operator.key" :value="operator.key">{{ operator.label }}</option></select></label>
                  <label><span>聚合字段</span><select v-model="draft.leftField" :disabled="formDisabled" @change="refreshInferredReturnType"><option v-for="field in activeAggregateFields" :key="field.key" :value="field.key">{{ field.label }}</option></select></label>
                  <div class="custom-field-rule-note">聚合先应用当前账号的数据权限与查询条件，再按统一结果集计算。</div>
                </div>

                <div v-else class="custom-field-bucket">
                  <div class="custom-field-rule-grid">
                    <label><span>分档依据</span><select v-model="draft.bucketSource" :disabled="formDisabled" @change="changeBucketSource"><option value="field">数值或日期字段</option><option value="date_diff">两个日期的相差天数</option></select></label>
                    <label v-if="draft.bucketSource === 'field'"><span>分档字段</span><select v-model="draft.leftField" :disabled="formDisabled" @change="refreshInferredReturnType"><option v-for="field in orderedFieldOptions" :key="field.key" :value="field.key">{{ field.label }}</option></select></label>
                    <template v-else>
                      <label><span>日期第一项</span><select :value="dateOperandValue('left')" :disabled="formDisabled" @change="setDateOperand('left', $event.target.value)"><option value="__query_cutoff__">查询截止日期</option><option v-for="field in dateFieldOptions" :key="`bucket-left-${field.key}`" :value="field.key">{{ field.label }}</option></select></label>
                      <label><span>日期第二项</span><select :value="dateOperandValue('right')" :disabled="formDisabled" @change="setDateOperand('right', $event.target.value)"><option value="__query_cutoff__">查询截止日期</option><option v-for="field in dateFieldOptions" :key="`bucket-right-${field.key}`" :value="field.key">{{ field.label }}</option></select></label>
                    </template>
                  </div>
                  <div class="custom-field-bucket-list">
                    <div v-for="(bucket, index) in draft.buckets" :key="index" class="custom-field-bucket-row">
                      <span>第 {{ index + 1 }} 档</span>
                      <label><span>小于等于</span><input v-model="bucket.upperBound" :type="literalInputType(draft.bucketSource === 'date_diff' ? 'integer' : fieldType(draft.leftField))" step="any" :disabled="formDisabled"></label>
                      <label><span>显示结果</span><input v-model="bucket.result" maxlength="255" :disabled="formDisabled"></label>
                      <button type="button" class="button button--text" :disabled="formDisabled || draft.buckets.length <= 1" @click="removeBucket(index)">移除</button>
                    </div>
                    <button type="button" class="button button--secondary custom-field-add-bucket" :disabled="formDisabled || draft.buckets.length >= 12" @click="addBucket"><Plus :size="15" />新增一档</button>
                    <label class="custom-field-bucket-fallback"><span>超过最后一档时</span><input v-model="draft.bucketFallback" maxlength="255" placeholder="输入其他结果" :disabled="formDisabled"></label>
                  </div>
                </div>
              </section>

              <div class="custom-field-form__grid">
                <label>
                  <span>结果类型</span>
                  <select v-model="draft.returnType" :disabled="formDisabled || returnTypeLocked">
                    <option value="amount">金额</option><option value="decimal">数字</option><option value="integer">整数</option><option value="date">日期</option><option value="text">文本</option><option value="boolean">是／否</option>
                  </select>
                  <small v-if="returnTypeLocked" class="custom-field-help">结果类型由规则自动确定。</small>
                </label>
                <label v-if="supportsNullHandling">
                  <span>空值处理</span>
                  <select v-model="draft.nullMode" :disabled="formDisabled">
                    <option value="empty">结果留空</option>
                    <option v-if="draft.ruleKind === 'calculation' && draft.operator !== 'date_diff'" value="zero">按 0 计算</option>
                    <option value="fallback">使用备用值</option>
                  </select>
                  <input v-if="draft.nullMode === 'fallback'" v-model="draft.fallbackValue" :type="literalInputType(draft.returnType)" step="any" :disabled="formDisabled" placeholder="输入备用值">
                </label>
                <div class="custom-field-sample"><span>示例结果</span><strong>{{ sampleResult }}</strong></div>
              </div>
            </div>

            <div v-else-if="activeTab === 'usage'" class="custom-field-form">
              <div class="custom-field-form__grid">
                <label><span>可见范围</span><div class="custom-field-segmented"><button type="button" :disabled="formDisabled" :class="{ active: draft.visibility === 'personal' }" @click="draft.visibility = 'personal'"><CircleUserRound :size="15" />仅自己</button><button type="button" :disabled="formDisabled || !canShare" :class="{ active: draft.visibility === 'shared' }" @click="draft.visibility = 'shared'"><Share2 :size="15" />共享</button></div></label>
                <label v-if="draft.visibility === 'shared'"><span>共享范围</span><select v-model="draft.shareScope" :disabled="formDisabled || !canShare"><option value="store">当前门店</option><option value="organization">本组织及下级</option><option value="tenant">全商户</option></select></label>
              </div>
              <div class="custom-field-capabilities"><span v-for="item in ['列表显示', '顶部查询', '组合筛选', '排序', '分组／合计', '导出']" :key="item">{{ item }}</span></div>
            </div>

            <div v-else class="custom-field-history">
              <div v-for="version in historyRows" :key="version.version" class="custom-field-history__row"><History :size="17" /><div><strong>{{ Number(version.version) > 0 ? `版本 ${version.version}` : '版本未返回' }}</strong><span>{{ version.note || version.statusLabel || version.status || '字段规则版本' }}</span></div><time>{{ version.date || version.createdAt || version.created_at || '时间未返回' }}<template v-if="version.author || version.createdBy || version.created_by"> · {{ version.author || version.createdBy || version.created_by }}</template></time></div>
              <div v-if="!historyRows.length" class="custom-field-empty">暂无可用的版本记录</div>
            </div>

            <div class="custom-field-editor__footer">
              <span v-if="toast" class="custom-field-toast">{{ toast }}</span>
              <button type="button" class="button button--secondary" :disabled="hasPendingCommand" @click="requestClose">取消</button>
              <button type="button" class="button button--primary" :disabled="!canSave || isSaving || isChangingStatus || Boolean(pendingStatusIdempotencyKey) || Boolean(pendingArchiveIdempotencyKey) || !fieldOptions.length" @click="saveDraft"><Save :size="16" />{{ isSaving ? '正在保存…' : (pendingSaveIdempotencyKey ? '按原请求重试' : '保存字段') }}</button>
            </div>
          </main>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.custom-field-layer{position:fixed;z-index:120;inset:0}.custom-field-layer__backdrop{position:absolute;inset:0;background:rgba(21,32,50,.48)}
.custom-field-panel{position:relative;display:grid;grid-template-rows:auto minmax(0,1fr);width:min(1160px,calc(100vw - 56px));height:min(780px,calc(100vh - 40px));margin:20px auto;overflow:hidden;border:1px solid #dce3eb;border-radius:12px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.28)}
.custom-field-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e2e7ee}.custom-field-header__title{display:flex;align-items:center;gap:11px}.custom-field-header__icon{display:grid;width:38px;height:38px;place-items:center;border-radius:8px;background:#eaf4ff;color:#1677cc}.custom-field-header h2{margin:0;font-size:18px;color:#1f2937}.custom-field-header p{margin:3px 0 0;font-size:12px;color:#8a94a3}.icon-button{display:grid;width:36px;height:36px;place-items:center;border:1px solid #dbe2ea;border-radius:8px;background:#fff;color:#596579;cursor:pointer}
.custom-field-body{display:grid;grid-template-columns:300px minmax(0,1fr);min-height:0}.custom-field-sidebar{display:flex;min-height:0;flex-direction:column;padding:16px;border-right:1px solid #e2e7ee;background:#f7f9fc}.custom-field-create{display:flex;align-items:center;justify-content:center;gap:6px;width:100%}.custom-field-scope{display:grid;grid-template-columns:repeat(3,1fr);margin-top:14px;padding:3px;border:1px solid #dce3eb;border-radius:8px;background:#fff}.custom-field-scope button{height:30px;border:0;border-radius:6px;background:transparent;color:#667085;cursor:pointer}.custom-field-scope button.active{background:#eaf4ff;color:#1677cc;font-weight:600}.custom-field-search{height:36px;margin-top:12px;padding:0 11px;border:1px solid #d7dfe8;border-radius:8px;background:#fff;font-size:13px}.custom-field-list{display:grid;gap:7px;margin-top:12px;overflow:auto}.custom-field-list__item{display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:62px;padding:10px 9px 10px 12px;border:1px solid transparent;border-radius:8px;background:transparent;text-align:left;cursor:pointer}.custom-field-list__item:hover{background:#fff}.custom-field-list__item.active{border-color:#a9cff8;background:#edf6ff;box-shadow:inset 3px 0 #1677cc}.custom-field-list__item.invalid{background:#fffaf1}.custom-field-list__main{display:grid;min-width:0;gap:4px}.custom-field-list__main strong{overflow:hidden;color:#283445;font-size:13px;text-overflow:ellipsis;white-space:nowrap}.custom-field-list__main small{color:#8993a2;font-size:11px}.custom-field-list__meta{display:flex;align-items:center;gap:4px;color:#8993a2}.custom-field-status{padding:2px 5px;border-radius:4px;font-size:10px}.custom-field-status--warning{background:#fff0d6;color:#a35c00}.custom-field-empty{padding:32px 0;text-align:center;color:#9aa3af;font-size:13px}
.custom-field-editor{display:grid;grid-template-rows:auto auto auto minmax(0,1fr) auto;min-width:0;min-height:0;background:#fff}.custom-field-editor__topline{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 22px 12px}.custom-field-editor__topline h3{margin:4px 0 0;color:#1f2937;font-size:19px}.custom-field-eyebrow{color:#8993a2;font-size:11px}.custom-field-editor__actions{display:flex;gap:8px}.custom-field-editor__actions .button,.custom-field-editor__footer .button,.custom-field-rule-tools .button{display:inline-flex;align-items:center;gap:6px}.custom-field-warning{display:flex;align-items:flex-start;gap:9px;margin:0 22px 10px;padding:10px 12px;border:1px solid #ffd89a;border-radius:8px;background:#fff8e8;color:#95520a}.custom-field-warning div{display:grid;gap:2px}.custom-field-warning strong{font-size:13px}.custom-field-warning span{font-size:12px}.custom-field-tabs{display:flex;gap:24px;padding:0 22px;border-bottom:1px solid #e2e7ee}.custom-field-tabs button{height:40px;border:0;border-bottom:2px solid transparent;background:transparent;color:#667085;cursor:pointer}.custom-field-tabs button.active{border-color:#1677cc;color:#1677cc;font-weight:600}.custom-field-form,.custom-field-history{min-height:0;padding:20px 22px;overflow:auto}.custom-field-form{display:grid;align-content:start;gap:18px}.custom-field-form__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.custom-field-form label{display:grid;align-content:start;gap:7px;color:#4c5868;font-size:13px}.custom-field-form input,.custom-field-form select{height:38px;min-width:0;padding:0 11px;border:1px solid #d5dde7;border-radius:8px;background:#fff;color:#273444;font-size:13px}.custom-field-rule-card{padding:16px;border:1px solid #dce3eb;border-radius:8px;background:#fafbfd}.custom-field-rule-card__header{display:flex;align-items:center;justify-content:space-between}.custom-field-rule-card h4{margin:0;color:#273444;font-size:14px}.custom-field-rule-badge{padding:3px 7px;border-radius:5px;background:#edf6ff;color:#1677cc;font-size:11px}.custom-field-expression{display:grid;grid-template-columns:minmax(150px,1fr) 74px minmax(150px,1fr) auto;gap:8px;margin-top:15px}.custom-field-expression .custom-field-operator{text-align:center;font-size:18px}.custom-field-expression-preview{display:flex;align-items:center;gap:10px;margin-top:13px;padding:10px 12px;border:1px dashed #b8c7d9;border-radius:7px;background:#fff;color:#4b5868;font-size:13px}.custom-field-expression-preview span{padding:4px 7px;border-radius:5px;background:#eef2f6}.custom-field-expression-preview strong{color:#1677cc;font-size:17px}.custom-field-rule-tools{display:flex;gap:8px;margin-top:12px}.custom-field-sample{display:grid;align-content:center;gap:5px;padding:10px 13px;border-left:3px solid #2aa36b;background:#f1fbf6}.custom-field-sample span{color:#687385;font-size:12px}.custom-field-sample strong{color:#137448;font-size:16px}.custom-field-segmented{display:grid;grid-template-columns:1fr 1fr;padding:3px;border:1px solid #d5dde7;border-radius:8px}.custom-field-segmented button{display:flex;height:31px;align-items:center;justify-content:center;gap:6px;border:0;border-radius:6px;background:transparent;color:#667085}.custom-field-segmented button.active{background:#eaf4ff;color:#1677cc;font-weight:600}.custom-field-capabilities{display:flex;flex-wrap:wrap;gap:8px}.custom-field-capabilities span{padding:7px 9px;border:1px solid #cbe1f8;border-radius:6px;background:#f3f9ff;color:#2168a7;font-size:12px}.custom-field-history{display:grid;align-content:start;gap:0}.custom-field-history__row{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:10px;padding:14px 4px;border-bottom:1px solid #edf0f4;color:#738095}.custom-field-history__row div{display:grid;gap:3px}.custom-field-history__row strong{color:#2e3a49;font-size:13px}.custom-field-history__row span,.custom-field-history__row time{font-size:12px}.custom-field-editor__footer{display:flex;align-items:center;justify-content:flex-end;gap:9px;padding:13px 22px;border-top:1px solid #e2e7ee}.custom-field-toast{margin-right:auto;color:#a35c00;font-size:12px}
.custom-field-rule-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:15px}.custom-field-rule-note{align-self:end;grid-column:span 2;min-height:38px;padding:10px 12px;border:1px dashed #b8c7d9;border-radius:7px;background:#fff;color:#667085;font-size:12px}.custom-field-conditional,.custom-field-bucket{display:grid;gap:14px}.custom-field-branch-results{padding-top:14px;border-top:1px dashed #d3dce6}.custom-field-bucket-list{display:grid;gap:9px;margin-top:15px}.custom-field-bucket-row{display:grid;grid-template-columns:64px minmax(120px,.8fr) minmax(160px,1fr) auto;align-items:end;gap:9px;padding:10px;border:1px solid #e0e6ed;border-radius:8px;background:#fff}.custom-field-bucket-row>span{align-self:center;color:#6d7887;font-size:12px}.custom-field-bucket-row .button{height:38px}.custom-field-add-bucket{display:inline-flex;width:max-content;align-items:center;gap:5px}.custom-field-bucket-fallback{width:min(420px,100%)}.custom-field-help{color:#8a94a3;font-size:11px}
.custom-field-context-value{display:flex;min-height:38px;align-items:center;padding:0 11px;border:1px solid #d5dde7;border-radius:8px;background:#fff;color:#273444;font-size:13px}.custom-field-archive{color:#b42318}.custom-field-editor button:disabled,.custom-field-form input:disabled,.custom-field-form select:disabled{cursor:not-allowed;opacity:.55}
@media(max-width:900px){.custom-field-panel{width:calc(100vw - 20px);height:calc(100vh - 20px);margin:10px auto}.custom-field-body{grid-template-columns:240px minmax(0,1fr)}.custom-field-expression{grid-template-columns:1fr 64px 1fr}.custom-field-expression>.button{grid-column:1/-1;justify-self:start}.custom-field-form__grid{grid-template-columns:1fr}.custom-field-rule-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.custom-field-bucket-row{grid-template-columns:56px 1fr 1fr auto}}
@media(max-width:680px){.custom-field-body{grid-template-columns:1fr;grid-template-rows:minmax(176px,32vh) minmax(0,1fr)}.custom-field-sidebar{display:flex;border-right:0;border-bottom:1px solid #e2e7ee}.custom-field-panel{width:100vw;height:100vh;margin:0;border-radius:0}.custom-field-editor__topline{align-items:flex-start;padding:14px 16px 10px}.custom-field-editor__actions{display:flex;flex-wrap:wrap;justify-content:flex-end}.custom-field-tabs,.custom-field-form,.custom-field-history{padding-right:16px;padding-left:16px}.custom-field-expression,.custom-field-rule-grid{grid-template-columns:1fr}.custom-field-rule-note{grid-column:auto}.custom-field-bucket-row{grid-template-columns:1fr}.custom-field-operator{width:70px}.custom-field-editor__footer{padding:12px 16px}}
</style>
