const EMPTY_PERMISSIONS = Object.freeze({
  createCustomField: false,
  editCustomField: false,
  shareCustomField: false,
  changeCustomFieldStatus: false,
  archiveCustomField: false,
  renameFields: false,
  export: false
})

const EMPTY_EXPORT_CAPABILITY = Object.freeze({
  enabled: false,
  allowCurrentQuery: false,
  allowCurrentPage: false,
  allowSummary: false,
  format: ''
})

export function emptyUnifiedQueryCapability(pageCode = '') {
  return {
    pageCode,
    pageName: '',
    loaded: false,
    enabled: false,
    reason: '',
    schemaVersion: 0,
    fields: [],
    customFields: [],
    fieldAliases: {},
    fieldAliasVersion: 0,
    permissions: { ...EMPTY_PERMISSIONS },
    exportCapability: { ...EMPTY_EXPORT_CAPABILITY },
    commandContext: null,
    dataAsOf: '',
    queryCutoffDate: '',
    querySettings: {},
    customFieldVersions: {},
    invalidReferences: []
  }
}

function isRecord(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function cloneUnifiedQueryValue(value) {
  if (Array.isArray(value)) return value.map(cloneUnifiedQueryValue)
  if (!isRecord(value)) return value
  return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, cloneUnifiedQueryValue(item)]))
}

// 查询条件是纯 JSON 合同。深拷贝后才可作为“已经执行”的快照传递给导出，
// 避免用户随后编辑输入控件时意外改写已执行查询的嵌套筛选、排序或字段版本。
export function cloneUnifiedQuerySnapshot(query) {
  return isRecord(query) ? cloneUnifiedQueryValue(query) : {}
}

function firstRecord(...values) {
  return values.find(isRecord) || {}
}

function firstString(...values) {
  const value = values.find((item) => typeof item === 'string' && item.trim())
  return value ? value.trim() : ''
}

function strictFlag(source, ...keys) {
  return keys.some((key) => source?.[key] === true)
}

export function unwrapUnifiedQueryEnvelope(raw) {
  if (!isRecord(raw)) return {}
  if (isRecord(raw.result)) return raw
  if (isRecord(raw.data) && isRecord(raw.data.result)) return raw.data
  return raw
}

export function unifiedQueryActionStatus(raw) {
  const envelope = unwrapUnifiedQueryEnvelope(raw)
  return firstString(envelope.result?.status, envelope.status)
}

export function unifiedQueryActionMessage(raw, fallback = '') {
  const envelope = unwrapUnifiedQueryEnvelope(raw)
  return firstString(envelope.result?.message, envelope.message, fallback)
}

export function unifiedQueryActionSucceeded(raw) {
  return unifiedQueryActionStatus(raw) === 'success'
}

export function unifiedQueryActionIdempotencyKey(raw) {
  const envelope = unwrapUnifiedQueryEnvelope(raw)
  return firstString(
    envelope.boundIdempotencyKey,
    envelope.bound_idempotency_key,
    envelope.idempotencyKey,
    envelope.idempotency_key,
    envelope.result?.boundIdempotencyKey,
    envelope.result?.bound_idempotency_key,
    envelope.result?.idempotencyKey,
    envelope.result?.idempotency_key
  )
}

function projectionCandidate(envelope) {
  const data = isRecord(envelope.data) ? envelope.data : {}
  return firstRecord(
    data.unifiedQueryCapability,
    data.unified_query_capability,
    data.unifiedQuery,
    data.unified_query,
    data.capability,
    envelope.unifiedQueryCapability,
    envelope.unified_query_capability,
    envelope.capability,
    data
  )
}

function normalizeCommandContext(source, envelope) {
  const explicit = firstRecord(
    source.commandContext,
    source.command_context,
    source.resourceContext,
    source.resource_context
  )
  const versions = Array.isArray(envelope.versions) ? envelope.versions : []
  const versionRow = versions.find((row) => {
    const kind = firstString(row?.kind, row?.resourceKind, row?.resource_kind)
    return kind === 'query_preference'
  })
  const context = Object.keys(explicit).length ? explicit : (versionRow || {})
  const kind = firstString(context.kind, context.resourceKind, context.resource_kind)
  const id = context.id ?? context.resourceId ?? context.resource_id ?? null
  if (kind !== 'query_preference' || id === null || id === '') return null
  return { kind, id }
}

function normalizePermissions(source = {}) {
  // 个人能力是否开放由服务端统一口径决定；共享发布等范围能力仍须逐项
  // fail-closed，浏览器不能把缺失或 false 的服务端权限自动放大。
  return {
    createCustomField: strictFlag(source, 'createCustomField', 'create_custom_field', 'create'),
    editCustomField: strictFlag(source, 'editCustomField', 'edit_custom_field', 'edit'),
    shareCustomField: strictFlag(source, 'shareCustomField', 'share_custom_field', 'share'),
    changeCustomFieldStatus: strictFlag(
      source,
      'changeCustomFieldStatus',
      'change_custom_field_status',
      'deactivate'
    ),
    archiveCustomField: strictFlag(source, 'archiveCustomField', 'archive_custom_field', 'archive'),
    renameFields: strictFlag(source, 'renameFields', 'rename_fields'),
    export: strictFlag(source, 'export')
  }
}

function normalizeAliases(source) {
  const aliases = firstRecord(source.fieldAliases, source.field_aliases, source.aliases)
  const result = {}
  for (const [key, value] of Object.entries(aliases)) {
    const normalizedKey = String(key || '').trim()
    const normalizedValue = typeof value === 'string' ? value.trim() : ''
    if (normalizedKey && normalizedValue) result[normalizedKey] = normalizedValue
  }
  return result
}

function normalizeField(raw, custom = false) {
  if (!isRecord(raw)) return null
  const key = firstString(raw.key, raw.fieldKey, raw.field_key, raw.stableKey, raw.stable_key, raw.code)
  const label = firstString(raw.systemLabel, raw.system_label, raw.originalLabel, raw.original_label, raw.label, raw.name)
  if (!key || !label) return null
  const capabilities = firstRecord(raw.capabilities, raw.usage)
  const allowedOperations = new Set([
    ...(Array.isArray(raw.allowedOperations) ? raw.allowedOperations : []),
    ...(Array.isArray(raw.allowed_operations) ? raw.allowed_operations : []),
    ...(Array.isArray(capabilities.allowedOperations) ? capabilities.allowedOperations : []),
    ...(Array.isArray(capabilities.allowed_operations) ? capabilities.allowed_operations : [])
  ].map((item) => String(item || '').trim().toLowerCase()).filter(Boolean))
  const allows = (...operations) => operations.some((operation) => allowedOperations.has(operation))
  return {
    ...raw,
    key,
    label,
    originalLabel: label,
    name: firstString(raw.name, raw.label, label),
    type: firstString(raw.type, raw.returnType, raw.return_type) || 'text',
    returnType: firstString(raw.returnType, raw.return_type, raw.type) || 'text',
    custom: custom || raw.custom === true || raw.source === 'custom',
    hidden: raw.hidden === true || raw.status === 'archived',
    status: firstString(raw.status) || 'active',
    version: Number.isSafeInteger(Number(raw.version)) && Number(raw.version) > 0 ? Number(raw.version) : 0,
    capabilities: {
      list: strictFlag(capabilities, 'list', 'visible', 'display') || raw.listable === true || allows('list', 'display'),
      quickFilter: strictFlag(capabilities, 'quickFilter', 'quick_filter', 'topFilter', 'top_filter') || raw.quickFilterable === true || allows('quick_filter', 'top_filter'),
      filter: strictFlag(capabilities, 'filter', 'filterable') || raw.filterable === true || allows('filter'),
      sort: strictFlag(capabilities, 'sort', 'sortable') || raw.sortable === true || allows('sort'),
      group: strictFlag(capabilities, 'group', 'groupable') || raw.groupable === true || allows('group'),
      summary: strictFlag(capabilities, 'summary', 'summarizable') || raw.summarizable === true || allows('summary', 'aggregate'),
      export: strictFlag(capabilities, 'export', 'exportable') || raw.exportable === true || allows('export'),
      customInput: strictFlag(capabilities, 'customInput', 'custom_input', 'customFieldInput', 'custom_field_input') || raw.customFieldInput === true || allows('custom_input', 'custom_field_input')
    }
  }
}

function normalizeFields(source) {
  const rawFields = Array.isArray(source.fields)
    ? source.fields
    : Array.isArray(source.whitelistFields)
      ? source.whitelistFields
      : Array.isArray(source.whitelist_fields)
        ? source.whitelist_fields
        : []
  const rawCustomFields = Array.isArray(source.customFields)
    ? source.customFields
    : Array.isArray(source.custom_fields)
      ? source.custom_fields
      : []
  const byKey = new Map()
  rawFields.map((field) => normalizeField(field, false)).filter(Boolean).forEach((field) => byKey.set(field.key, field))
  const customFields = rawCustomFields.map((field) => normalizeField(field, true)).filter(Boolean)
  customFields.forEach((field) => {
    if (!byKey.has(field.key)) byKey.set(field.key, field)
  })
  return { fields: [...byKey.values()], customFields }
}

function normalizeExportCapability(source, permissions) {
  const capability = firstRecord(source.exportCapability, source.export_capability, source.export)
  const formats = Array.isArray(capability.formats) ? capability.formats.map((item) => String(item).toLowerCase()) : []
  const format = firstString(capability.format).toLowerCase()
  const supportsXlsx = format === 'xlsx' || formats.includes('xlsx')
  // 导出是否受页面能力支持控制，不再与细粒度角色权限绑定。
  const enabled = capability.enabled === true && supportsXlsx
  return {
    enabled,
    allowCurrentQuery: enabled && strictFlag(capability, 'allowCurrentQuery', 'allow_current_query', 'currentQuery', 'current_query'),
    allowCurrentPage: enabled && strictFlag(capability, 'allowCurrentPage', 'allow_current_page', 'currentPage', 'current_page'),
    allowSummary: enabled && strictFlag(capability, 'allowSummary', 'allow_summary', 'summary'),
    format: enabled ? 'xlsx' : ''
  }
}

function normalizeSchemaVersion(value) {
  if (typeof value === 'string' && value.trim()) return value.trim()
  if (Number.isSafeInteger(value) && value > 0) return value
  return ''
}

function normalizePositiveInteger(value) {
  const normalized = Number(value)
  return Number.isSafeInteger(normalized) && normalized > 0 ? normalized : 0
}

function normalizeDate(value) {
  const match = String(value || '').match(/^(\d{4}-\d{2}-\d{2})/)
  return match ? match[1] : ''
}

const FILTER_OPERATOR_TO_UI = Object.freeze({
  equal: 'eq',
  not_equal: 'neq',
  greater_than: 'gt',
  greater_or_equal: 'gte',
  less_than: 'lt',
  less_or_equal: 'lte'
})

function normalizeStringList(value) {
  if (!Array.isArray(value)) return []
  const seen = new Set()
  return value.map((item) => String(item || '').trim()).filter((item) => {
    if (!item || seen.has(item)) return false
    seen.add(item)
    return true
  })
}

function normalizeFieldVersionMap(value) {
  if (!isRecord(value)) return {}
  const versions = {}
  for (const [fieldKey, version] of Object.entries(value)) {
    const key = String(fieldKey || '').trim()
    const normalized = normalizePositiveInteger(version)
    if (key && normalized) versions[key] = normalized
  }
  return versions
}

export function normalizeUnifiedQueryFilter(raw) {
  if (!isRecord(raw)) return null
  const field = firstString(raw.field, raw.fieldKey, raw.field_key)
  if (!field) return null
  const backendOperator = firstString(raw.operator).toLowerCase()
  const operator = FILTER_OPERATOR_TO_UI[backendOperator] || backendOperator || 'eq'
  const sourceValue = raw.value
  const range = operator === 'between' && Array.isArray(sourceValue) ? sourceValue : null
  return {
    field,
    operator,
    value: range ? (range[0] ?? '') : (sourceValue ?? ''),
    ...(operator === 'between' ? { valueTo: range ? (range[1] ?? '') : (raw.valueTo ?? raw.value_to ?? '') } : {})
  }
}

function normalizeInvalidReferences(...sources) {
  const seen = new Set()
  return sources.filter(Array.isArray).flat().map((row) => {
    if (!isRecord(row)) return null
    const fieldKey = firstString(row.fieldKey, row.field_key, row.key)
    if (!fieldKey) return null
    const normalized = {
      fieldKey,
      version: normalizePositiveInteger(row.version ?? row.fieldVersion ?? row.field_version),
      status: firstString(row.status) || 'invalid',
      reason: firstString(row.reason, row.invalidReason, row.invalid_reason) || '字段引用已失效，请移除、恢复或升级后再保存。'
    }
    const identity = `${normalized.fieldKey}:${normalized.version}:${normalized.status}`
    if (seen.has(identity)) return null
    seen.add(identity)
    return normalized
  }).filter(Boolean)
}

export function normalizeUnifiedQuerySettings(raw = {}) {
  const source = isRecord(raw) ? raw : {}
  const preference = firstRecord(source.querySettings, source.query_settings, source.preference, source)
  const settings = firstRecord(preference.settings, preference.querySettings, preference.query_settings, preference)
  const sorts = Array.isArray(settings.sorts) ? settings.sorts.map((sort) => {
    if (!isRecord(sort)) return null
    const field = firstString(sort.field, sort.fieldKey, sort.field_key)
    if (!field) return null
    return { field, direction: String(sort.direction || '').toLowerCase() === 'asc' ? 'asc' : 'desc' }
  }).filter(Boolean) : []
  const summaries = Array.isArray(settings.summaries) ? settings.summaries.map((summary) => {
    if (!isRecord(summary)) return null
    const field = firstString(summary.field, summary.fieldKey, summary.field_key)
    if (!field) return null
    const aggregation = firstString(summary.aggregation, summary.operator)
    const aliases = { average: 'avg', minimum: 'min', maximum: 'max' }
    return { field, aggregation: aliases[aggregation] || aggregation || 'count' }
  }).filter(Boolean) : []
  return {
    visibleFields: normalizeStringList(settings.visibleFields ?? settings.visible_fields),
    quickFields: normalizeStringList(settings.quickFields ?? settings.quick_fields),
    sorts,
    filters: (Array.isArray(settings.filters) ? settings.filters : []).map(normalizeUnifiedQueryFilter).filter(Boolean),
    filterRelation: firstString(settings.filterRelation, settings.filter_relation) === 'any' ? 'any' : 'all',
    groupBy: normalizeStringList(settings.groupBy ?? settings.group_by ?? settings.groups),
    summaries,
    queryCutoffDate: normalizeDate(settings.queryCutoffDate ?? settings.query_cutoff_date),
    schemaVersion: settings.schemaVersion ?? settings.schema_version ?? '',
    settingsVersion: normalizePositiveInteger(preference.settingsVersion ?? preference.settings_version),
    customFieldVersions: normalizeFieldVersionMap(preference.customFieldVersions ?? preference.custom_field_versions),
    invalidReferences: normalizeInvalidReferences(
      preference.invalidReferences,
      preference.invalid_references,
      source.invalidReferences,
      source.invalid_references
    )
  }
}

function expressionNodeKey(node) {
  return firstString(node?.fieldKey, node?.field_key, node?.key)
}

const CUSTOM_FIELD_COMPARISON_OPERATORS = Object.freeze([
  'equal', 'not_equal', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal'
])
const CUSTOM_FIELD_AGGREGATE_OPERATORS = Object.freeze([
  'sum', 'average', 'minimum', 'maximum', 'count'
])

function canonicalValueType(value) {
  const normalized = String(value || '').trim().toLowerCase()
  const aliases = { number: 'decimal', money: 'amount', bool: 'boolean', string: 'text' }
  return aliases[normalized] || normalized || 'text'
}

function literalNode(value, valueType = 'text') {
  const type = canonicalValueType(valueType)
  let normalized = value
  if (type === 'boolean') normalized = value === true || value === 1 || value === '1' || value === 'true'
  return { type: 'literal', value_type: type, value: normalized }
}

function fieldNode(fieldKey) {
  return { type: 'field', key: String(fieldKey || '').trim() }
}

function cutoffNode() {
  return { type: 'context', key: 'query_cutoff_date' }
}

function editableOperand(node) {
  if (node?.type === 'field' && expressionNodeKey(node)) {
    return { loaded: true, mode: 'field', field: expressionNodeKey(node), value: '', valueType: '' }
  }
  if (node?.type === 'context' && expressionNodeKey(node) === 'query_cutoff_date') {
    return { loaded: true, mode: 'query_cutoff', field: '', value: '', valueType: 'date' }
  }
  if (node?.type === 'literal') {
    const declaredType = firstString(node.value_type, node.valueType)
    return {
      loaded: true,
      mode: 'constant',
      field: '',
      value: node.value ?? '',
      valueType: canonicalValueType(declaredType || inferLiteralValueType(node.value))
    }
  }
  return { loaded: false }
}

function inferLiteralValueType(value) {
  if (typeof value === 'boolean') return 'boolean'
  const text = String(value ?? '').trim()
  if (/^-?(?:0|[1-9]\d*)$/.test(text)) return 'integer'
  if (/^-?(?:0|[1-9]\d*)\.\d+$/.test(text)) return 'decimal'
  if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return 'date'
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text)) return 'datetime'
  return 'text'
}

function isLiteralZero(node) {
  return node?.type === 'literal' && Number(node.value) === 0
}

function unwrapZeroCoalesce(node) {
  if (node?.type !== 'operator' || node.operator !== 'coalesce' || !Array.isArray(node.args) || node.args.length !== 2) return null
  return isLiteralZero(node.args[1]) ? node.args[0] : null
}

export function normalizeCustomFieldExpressionForEditor(field) {
  const source = isRecord(field) ? field : {}
  let expression = firstRecord(source.expression, source.rule)
  if (!Object.keys(expression).length) return { loaded: false, reason: '字段规则详情未加载，请刷新后重试。' }

  let nullMode = firstString(source.nullMode, source.null_mode) || 'empty'
  let fallbackValue = source.nullFallback ?? source.null_fallback ?? ''
  let returnType = firstString(source.returnType, source.return_type, source.type) || 'amount'
  let hasFallbackWrapper = false
  if (!expression.type && expression.leftField && expression.operator) {
    const rightMode = expression.rightMode === 'constant'
      ? 'constant'
      : ['query_cutoff', 'cutoff_date'].includes(expression.rightMode)
        ? 'query_cutoff'
        : 'field'
    const right = rightMode === 'constant'
      ? { type: 'literal', value: expression.constantValue ?? '', value_type: expression.constantType }
      : rightMode === 'query_cutoff'
        ? { type: 'context', key: 'query_cutoff_date' }
        : { type: 'field', key: expression.rightField }
    return normalizeEditableBinary(
      expression.operator,
      { type: 'field', key: expression.leftField },
      right,
      expression.nullMode || nullMode,
      expression.fallbackValue ?? fallbackValue,
      expression.returnType || returnType
    )
  }
  if (expression.type === 'binary') {
    const operator = firstString(expression.operator)
    const left = expression.left
    const right = expression.right
    if (expression.nullMode) nullMode = expression.nullMode
    if (expression.returnType) returnType = expression.returnType
    return normalizeEditableBinary(operator, left, right, nullMode, fallbackValue, returnType)
  }

  if (expression.type !== 'operator' || !Array.isArray(expression.args)) {
    return { loaded: false, reason: '该字段使用了当前编辑器暂不支持的规则，请使用兼容编辑器修改。' }
  }
  if (expression._return_type) returnType = expression._return_type
  if (expression.operator === 'coalesce' && expression.args.length === 2 && expression.args[1]?.type === 'literal') {
    hasFallbackWrapper = true
    nullMode = 'fallback'
    fallbackValue = expression.args[1].value ?? ''
    expression = expression.args[0]
  }
  const normalized = normalizeControlledTemplate(expression, returnType)
  if (!normalized.loaded) return normalized
  return {
    ...normalized,
    nullMode: hasFallbackWrapper
      ? 'fallback'
      : (normalized.nullMode || (['empty', 'zero'].includes(nullMode) ? nullMode : 'empty')),
    fallbackValue,
    returnType
  }
}

function normalizeControlledTemplate(expression, returnType) {
  if (expression.type !== 'operator' || !Array.isArray(expression.args)) {
    return { loaded: false, reason: '该字段使用了当前编辑器暂不支持的规则，请使用兼容编辑器修改。' }
  }
  const operator = firstString(expression.operator)
  if (['add', 'subtract', 'multiply', 'divide', 'date_diff_days'].includes(operator) && expression.args.length === 2) {
    const leftWithoutZero = unwrapZeroCoalesce(expression.args[0])
    const rightWithoutZero = unwrapZeroCoalesce(expression.args[1])
    const editable = normalizeEditableBinary(
      operator,
      leftWithoutZero || expression.args[0],
      rightWithoutZero || expression.args[1],
      leftWithoutZero && rightWithoutZero ? 'zero' : 'empty',
      '',
      returnType
    )
    return editable
  }
  if (CUSTOM_FIELD_COMPARISON_OPERATORS.includes(operator) && expression.args.length === 2) {
    const left = editableOperand(expression.args[0])
    const right = editableOperand(expression.args[1])
    if (!left.loaded || left.mode !== 'field' || !right.loaded) {
      return { loaded: false, reason: '当前比较规则使用了不支持的操作数。' }
    }
    return {
      loaded: true,
      ruleKind: 'comparison',
      leftMode: 'field',
      leftField: left.field,
      operator,
      rightMode: right.mode,
      rightField: right.field,
      constantValue: right.value,
      constantType: right.valueType
    }
  }
  if (operator === 'between' && expression.args.length === 3) {
    const value = editableOperand(expression.args[0])
    const lower = editableOperand(expression.args[1])
    const upper = editableOperand(expression.args[2])
    if (!value.loaded || value.mode !== 'field' || lower.mode !== 'constant' || upper.mode !== 'constant') {
      return { loaded: false, reason: '当前区间规则不是字段与固定上下限的受控模板。' }
    }
    return {
      loaded: true,
      ruleKind: 'between',
      leftMode: 'field',
      leftField: value.field,
      lowerValue: lower.value,
      upperValue: upper.value,
      constantType: lower.valueType || upper.valueType
    }
  }
  if (operator === 'is_null' && expression.args.length === 1) {
    const value = editableOperand(expression.args[0])
    if (!value.loaded || value.mode !== 'field') {
      return { loaded: false, reason: '当前判空规则不是字段模板。' }
    }
    return { loaded: true, ruleKind: 'null_check', leftMode: 'field', leftField: value.field, operator: 'is_null' }
  }
  if (CUSTOM_FIELD_AGGREGATE_OPERATORS.includes(operator) && expression.args.length === 1) {
    const value = editableOperand(expression.args[0])
    if (!value.loaded || value.mode !== 'field') {
      return { loaded: false, reason: '当前聚合规则不是单字段受控模板。' }
    }
    return { loaded: true, ruleKind: 'aggregate', leftMode: 'field', leftField: value.field, operator }
  }
  if (operator === 'if' && expression.args.length === 3) {
    const condition = normalizeCondition(expression.args[0])
    const whenTrue = editableOperand(expression.args[1])
    const whenFalse = editableOperand(expression.args[2])
    if (!condition.loaded || whenTrue.mode !== 'constant' || whenFalse.mode !== 'constant') {
      return { loaded: false, reason: '当前如果／否则规则不是受控条件与固定结果模板。' }
    }
    return {
      loaded: true,
      ruleKind: 'conditional',
      ...condition,
      trueValue: whenTrue.value,
      falseValue: whenFalse.value
    }
  }
  if (operator === 'bucket') return normalizeBucketTemplate(expression)
  return { loaded: false, reason: '该字段使用了当前编辑器暂不支持的规则，请使用兼容编辑器修改。' }
}

function normalizeCondition(expression) {
  if (expression?.type !== 'operator' || !Array.isArray(expression.args)) return { loaded: false }
  const operator = firstString(expression.operator)
  if (operator === 'is_null' && expression.args.length === 1) {
    const left = editableOperand(expression.args[0])
    return left.loaded && left.mode === 'field'
      ? { loaded: true, conditionField: left.field, conditionOperator: 'is_null', conditionRightMode: 'constant', conditionValue: '' }
      : { loaded: false }
  }
  if (operator === 'between' && expression.args.length === 3) {
    const left = editableOperand(expression.args[0])
    const lower = editableOperand(expression.args[1])
    const upper = editableOperand(expression.args[2])
    return left.mode === 'field' && lower.mode === 'constant' && upper.mode === 'constant'
      ? {
          loaded: true,
          conditionField: left.field,
          conditionOperator: 'between',
          conditionRightMode: 'constant',
          conditionValue: lower.value,
          conditionUpperValue: upper.value,
          conditionValueType: lower.valueType || upper.valueType
        }
      : { loaded: false }
  }
  if (!CUSTOM_FIELD_COMPARISON_OPERATORS.includes(operator) || expression.args.length !== 2) return { loaded: false }
  const left = editableOperand(expression.args[0])
  const right = editableOperand(expression.args[1])
  return left.mode === 'field' && right.loaded
    ? {
        loaded: true,
        conditionField: left.field,
        conditionOperator: operator,
        conditionRightMode: right.mode,
        conditionRightField: right.field,
        conditionValue: right.value,
        conditionValueType: right.valueType
      }
    : { loaded: false }
}

function normalizeBucketTemplate(expression) {
  const args = expression.args
  if (args.length < 4 || args.length > 26 || args.length % 2 !== 0) {
    return { loaded: false, reason: '分档规则数量不在 1 到 12 档范围内。' }
  }
  const source = normalizeBucketSource(args[0])
  const fallback = editableOperand(args[args.length - 1])
  if (!source.loaded || fallback.mode !== 'constant') {
    return { loaded: false, reason: '当前分档规则不是受控字段／日期差模板。' }
  }
  const buckets = []
  for (let index = 1; index < args.length - 1; index += 2) {
    const upper = editableOperand(args[index])
    const result = editableOperand(args[index + 1])
    if (upper.mode !== 'constant' || result.mode !== 'constant') {
      return { loaded: false, reason: '分档上界和结果必须是固定值。' }
    }
    buckets.push({ upperBound: upper.value, result: result.value })
  }
  return {
    loaded: true,
    ruleKind: 'bucket',
    ...source,
    buckets,
    bucketFallback: fallback.value
  }
}

function normalizeBucketSource(node) {
  const operand = editableOperand(node)
  if (operand.loaded && operand.mode === 'field') {
    return { loaded: true, bucketSource: 'field', leftMode: 'field', leftField: operand.field }
  }
  if (node?.type !== 'operator' || node.operator !== 'date_diff_days' || !Array.isArray(node.args) || node.args.length !== 2) {
    return { loaded: false }
  }
  const left = editableOperand(node.args[0])
  const right = editableOperand(node.args[1])
  if (!left.loaded || !right.loaded || !['field', 'query_cutoff'].includes(left.mode) || !['field', 'query_cutoff'].includes(right.mode)) {
    return { loaded: false }
  }
  return {
    loaded: true,
    bucketSource: 'date_diff',
    leftMode: left.mode,
    leftField: left.field,
    rightMode: right.mode,
    rightField: right.field
  }
}

function normalizeEditableBinary(operator, left, right, nullMode, fallbackValue, returnType) {
  const operatorAliases = { date_diff_days: 'date_diff' }
  const normalizedOperator = operatorAliases[operator] || operator
  if (!['add', 'subtract', 'multiply', 'divide', 'date_diff'].includes(normalizedOperator)) {
    return { loaded: false, reason: '该字段使用了当前编辑器暂不支持的规则，请使用兼容编辑器修改。' }
  }
  const leftOperand = editableOperand(left)
  const rightOperand = editableOperand(right)
  if (!leftOperand.loaded || !rightOperand.loaded
    || (leftOperand.mode !== 'field' && !(normalizedOperator === 'date_diff' && leftOperand.mode === 'query_cutoff'))) {
    return { loaded: false, reason: '该字段使用了当前编辑器暂不支持的右侧规则。' }
  }
  if (normalizedOperator === 'date_diff'
    && (!['field', 'query_cutoff'].includes(leftOperand.mode)
      || !['field', 'query_cutoff'].includes(rightOperand.mode))) {
    return { loaded: false, reason: '日期差只能使用日期字段或查询截止日期。' }
  }
  if (normalizedOperator !== 'date_diff' && !['field', 'constant'].includes(rightOperand.mode)) {
    return { loaded: false, reason: '基础计算右侧只能使用字段或固定值。' }
  }
  return {
    loaded: true,
    ruleKind: 'calculation',
    leftMode: leftOperand.mode,
    leftField: leftOperand.field,
    operator: normalizedOperator,
    rightMode: rightOperand.mode,
    rightField: rightOperand.field,
    constantValue: rightOperand.value,
    constantType: rightOperand.valueType,
    nullMode: ['empty', 'zero', 'fallback'].includes(nullMode) ? nullMode : 'empty',
    fallbackValue,
    returnType
  }
}

function canonicalExpressionNode(node) {
  if (node?.type === 'field') return { type: 'field', key: expressionNodeKey(node) }
  if (node?.type === 'context') return { type: 'context', key: expressionNodeKey(node) }
  if (node?.type === 'literal') return {
    type: 'literal',
    value_type: firstString(node.valueType, node.value_type) || undefined,
    value: node.value
  }
  return node
}

export function buildCustomFieldExpression(draft) {
  const source = isRecord(draft) ? draft : {}
  const kind = firstString(source.ruleKind) || 'calculation'
  if (kind !== 'calculation') {
    const expression = buildControlledTemplate(source, kind)
    return source.nullMode === 'fallback' && kind === 'bucket'
      ? wrapCustomFieldFallback(expression, source)
      : expression
  }
  const right = source.rightMode === 'query_cutoff'
    ? cutoffNode()
    : source.rightMode === 'constant'
      ? literalNode(String(source.constantValue ?? '').trim(), source.constantType || source.leftValueType || 'decimal')
      : fieldNode(source.rightField)
  const left = source.leftMode === 'query_cutoff' ? cutoffNode() : fieldNode(source.leftField)
  if (source.nullMode !== 'fallback' && source.leftMode !== 'query_cutoff') {
    return {
      type: 'binary',
      operator: source.operator,
      left: { type: 'field', fieldKey: source.leftField },
      right: source.rightMode === 'field' ? { type: 'field', fieldKey: source.rightField } : right,
      nullMode: source.nullMode === 'zero' ? 'zero' : 'empty',
      returnType: source.returnType
    }
  }
  const operator = source.operator === 'date_diff' ? 'date_diff_days' : source.operator
  const calculation = {
    type: 'operator',
    operator,
    args: [canonicalExpressionNode(left), canonicalExpressionNode(right)]
  }
  return source.nullMode === 'fallback'
    ? wrapCustomFieldFallback(calculation, source)
    : { ...calculation, _return_type: canonicalValueType(source.returnType) }
}

function wrapCustomFieldFallback(expression, source) {
  return {
    type: 'operator',
    operator: 'coalesce',
    args: [
      withoutRootReturnType(expression),
      literalNode(String(source.fallbackValue ?? '').trim(), source.returnType)
    ],
    _return_type: canonicalValueType(source.returnType)
  }
}

function withoutRootReturnType(expression) {
  if (!isRecord(expression)) return expression
  const normalized = { ...expression }
  delete normalized._return_type
  return normalized
}

function buildControlledTemplate(source, kind) {
  if (kind === 'comparison') {
    return operatorNode(source.operator, [fieldNode(source.leftField), controlledRightOperand(source)], 'boolean')
  }
  if (kind === 'between') {
    const type = source.constantType || source.leftValueType || 'decimal'
    return operatorNode('between', [
      fieldNode(source.leftField),
      literalNode(source.lowerValue, type),
      literalNode(source.upperValue, type)
    ], 'boolean')
  }
  if (kind === 'null_check') return operatorNode('is_null', [fieldNode(source.leftField)], 'boolean')
  if (kind === 'aggregate') return operatorNode(source.operator, [fieldNode(source.leftField)], source.returnType)
  if (kind === 'conditional') {
    return operatorNode('if', [
      buildConditionNode(source),
      literalNode(source.trueValue, source.returnType),
      literalNode(source.falseValue, source.returnType)
    ], source.returnType)
  }
  if (kind === 'bucket') {
    const value = buildBucketValueNode(source)
    const boundaryType = source.bucketSource === 'date_diff'
      ? 'integer'
      : (source.leftValueType || source.constantType || 'decimal')
    const args = [value]
    for (const bucket of (Array.isArray(source.buckets) ? source.buckets : []).slice(0, 12)) {
      args.push(literalNode(bucket.upperBound, boundaryType))
      args.push(literalNode(bucket.result, source.returnType || 'text'))
    }
    args.push(literalNode(source.bucketFallback, source.returnType || 'text'))
    return operatorNode('bucket', args, source.returnType || 'text')
  }
  return operatorNode('is_null', [fieldNode(source.leftField)], 'boolean')
}

function operatorNode(operator, args, returnType) {
  const node = { type: 'operator', operator, args }
  if (returnType) node._return_type = canonicalValueType(returnType)
  return node
}

function controlledRightOperand(source, prefix = '') {
  const mode = source[`${prefix}RightMode`] || source.rightMode
  if (mode === 'field') return fieldNode(source[`${prefix}RightField`] || source.rightField)
  if (mode === 'query_cutoff') return cutoffNode()
  const value = source[`${prefix}Value`] ?? source.constantValue
  const valueType = source[`${prefix}ValueType`] || source.constantType || source.leftValueType || 'decimal'
  return literalNode(value, valueType)
}

function buildConditionNode(source) {
  const operator = source.conditionOperator || 'greater_or_equal'
  const valueType = source.conditionValueType || source.leftValueType || 'decimal'
  if (operator === 'is_null') return operatorNode('is_null', [fieldNode(source.conditionField)])
  if (operator === 'between') {
    return operatorNode('between', [
      fieldNode(source.conditionField),
      literalNode(source.conditionValue, valueType),
      literalNode(source.conditionUpperValue, valueType)
    ])
  }
  return operatorNode(operator, [
    fieldNode(source.conditionField),
    controlledRightOperand(source, 'condition')
  ])
}

function buildBucketValueNode(source) {
  if (source.bucketSource !== 'date_diff') return fieldNode(source.leftField)
  const left = source.leftMode === 'query_cutoff' ? cutoffNode() : fieldNode(source.leftField)
  const right = source.rightMode === 'query_cutoff' ? cutoffNode() : fieldNode(source.rightField)
  return operatorNode('date_diff_days', [left, right])
}

export function normalizeUnifiedQueryCapability(raw, expectedPageCode) {
  const disabled = emptyUnifiedQueryCapability(expectedPageCode)
  disabled.loaded = true
  const envelope = unwrapUnifiedQueryEnvelope(raw)
  if (unifiedQueryActionStatus(envelope) !== 'success') {
    disabled.reason = unifiedQueryActionMessage(envelope, '统一查询能力加载失败。')
    return disabled
  }
  const source = projectionCandidate(envelope)
  const pageCode = firstString(source.pageCode, source.page_code)
  if (!expectedPageCode || pageCode !== expectedPageCode || source.enabled !== true) {
    disabled.reason = firstString(source.disabledReason, source.disabled_reason, '当前页面尚未开放统一查询增强能力。')
    return disabled
  }
  const { fields, customFields } = normalizeFields(source)
  if (!fields.length) {
    disabled.reason = '服务端未返回当前页面字段白名单，增强能力已关闭。'
    return disabled
  }
  const permissions = normalizePermissions(source)
  const candidateContext = normalizeCommandContext(source, envelope)
  const schemaVersion = normalizeSchemaVersion(source.schemaVersion ?? source.schema_version)
  const commandContext = candidateContext && String(candidateContext.id) === pageCode && schemaVersion ? candidateContext : null
  const dataAsOf = firstString(source.dataAsOf, source.data_as_of, envelope.dataAsOf, envelope.data_as_of)
  const querySettings = normalizeUnifiedQuerySettings(
    firstRecord(source.querySettings, source.query_settings)
  )
  return {
    pageCode,
    pageName: firstString(source.pageName, source.page_name),
    loaded: true,
    enabled: true,
    reason: '',
    schemaVersion,
    fields,
    customFields,
    fieldAliases: normalizeAliases(source),
    fieldAliasVersion: normalizePositiveInteger(source.fieldAliasVersion ?? source.field_alias_version ?? source.aliasVersion ?? source.alias_version),
    permissions,
    exportCapability: normalizeExportCapability(source, permissions),
    commandContext,
    dataAsOf,
    queryCutoffDate: normalizeDate(firstString(
      source.queryCutoffDate,
      source.query_cutoff_date,
      querySettings.queryCutoffDate,
      dataAsOf
    )),
    querySettings,
    customFieldVersions: querySettings.customFieldVersions,
    invalidReferences: querySettings.invalidReferences
  }
}

export function buildUnifiedQueryExportPayload(query, configuration, capability) {
  const querySnapshot = cloneUnifiedQuerySnapshot(query)
  const source = isRecord(configuration) ? configuration : {}
  const queryCutoffDate = normalizeDate(firstString(
    querySnapshot.queryCutoffDate,
    querySnapshot.query_cutoff_date,
    capability?.queryCutoffDate,
    capability?.dataAsOf
  ))
  // 截止日期是导出任务的顶层冻结合同；查询执行计划从服务端上下文注入，
  // 不在嵌套 query 中重复携带，避免两份日期产生歧义。
  delete querySnapshot.queryCutoffDate
  delete querySnapshot.query_cutoff_date
  return {
    query: querySnapshot,
    scope: source.scope === 'page' ? 'page' : 'query',
    fields: Array.isArray(source.fields) ? [...source.fields] : [],
    includeSummary: source.includeSummary === true,
    fileName: firstString(source.fileName, source.file_name),
    ...(queryCutoffDate ? { queryCutoffDate } : {})
  }
}

export function extractUnifiedQueryProjection(raw, preferredKeys = []) {
  const envelope = unwrapUnifiedQueryEnvelope(raw)
  if (unifiedQueryActionStatus(envelope) !== 'success') return null
  const data = isRecord(envelope.data) ? envelope.data : {}
  const aliases = Array.isArray(preferredKeys)
    ? preferredKeys.map((key) => data[String(key || '')]).filter(isRecord)
    : []
  const source = firstRecord(
    ...aliases,
    data.queryResult,
    data.query_result,
    data.unifiedQueryResult,
    data.unified_query_result,
    data
  )
  const pagination = firstRecord(source.pagination, source.pageInfo, source.page_info)
  const records = Array.isArray(source.records)
    ? source.records
    : Array.isArray(source.rows)
      ? source.rows
      : null
  if (!records) return null
  const totalValue = source.total ?? pagination.total ?? records.length
  const pageValue = source.page ?? pagination.page ?? 1
  const pageSizeValue = source.pageSize ?? source.page_size ?? pagination.pageSize ?? pagination.page_size ?? pagination.limit ?? records.length ?? 20
  const total = Number(totalValue)
  const page = Number(pageValue)
  const pageSize = Number(pageSizeValue)
  const statusOptions = Array.isArray(source.statusOptions)
    ? source.statusOptions
    : (Array.isArray(source.status_options) ? source.status_options : null)
  const querySettings = firstRecord(source.querySettings, source.query_settings)
  return {
    records,
    total: Number.isFinite(total) && total >= 0 ? total : records.length,
    page: Number.isSafeInteger(page) && page > 0 ? page : 1,
    pageSize: Number.isSafeInteger(pageSize) && pageSize > 0 ? pageSize : Math.max(1, records.length || 20),
    summaries: firstRecord(source.summaries, source.summary, data.summaries),
    groups: Array.isArray(source.groups) ? source.groups : (Array.isArray(data.groups) ? data.groups : []),
    ...(statusOptions ? { statusOptions } : {}),
    ...(Object.keys(querySettings).length ? { querySettings } : {}),
    invalidReferences: normalizeInvalidReferences(source.invalidReferences, source.invalid_references),
    dataAsOf: firstString(source.dataAsOf, source.data_as_of, data.dataAsOf, data.data_as_of),
    queryCutoffDate: normalizeDate(firstString(source.queryCutoffDate, source.query_cutoff_date))
  }
}

export function presentUnifiedQueryFields(baseFields, capability) {
  const source = Array.isArray(baseFields) ? baseFields : []
  if (capability?.enabled !== true) return source.map((field) => ({ ...field }))
  const backendByKey = new Map(capability.fields.map((field) => [field.key, field]))
  const baseByKey = new Map(source.map((field) => [field.key, field]))
  const aliases = capability.fieldAliases || {}
  const fields = []
  for (const backendField of capability.fields) {
    if (backendField.status !== 'active' || backendField.hidden) continue
    const base = baseByKey.get(backendField.key) || {}
    const label = aliases[backendField.key] || backendField.label
    const isCustom = backendField.custom === true
    fields.push({
      ...base,
      ...backendField,
      key: backendField.key,
      label,
      originalLabel: backendField.originalLabel || base.label || backendField.label,
      custom: isCustom,
      queryType: backendField.type,
      type: isCustom ? backendField.type : (base.type || backendField.type),
      ...(!isCustom && base.recordKey ? { recordKey: base.recordKey } : {}),
      ...(!isCustom && base.display ? { display: base.display } : {}),
      ...(!isCustom && base.emptyText ? { emptyText: base.emptyText } : {})
    })
  }
  // An enabled capability is an allow-list. A client-only field must not leak into
  // filters, sorts or export merely because an older page still knows its key.
  return fields.filter((field) => backendByKey.has(field.key))
}

export function extractUnifiedQueryExportTask(raw) {
  const envelope = unwrapUnifiedQueryEnvelope(raw)
  const data = isRecord(envelope.data) ? envelope.data : {}
  const source = firstRecord(data.exportTask, data.export_task, data.task, envelope.exportTask, envelope.export_task)
  const taskId = source.id ?? source.taskId ?? source.task_id ?? null
  const rawDownloadUrl = firstString(source.downloadUrl, source.download_url)
  const downloadUrl = rawDownloadUrl.startsWith('/') && !rawDownloadUrl.startsWith('//') ? rawDownloadUrl : ''
  const rowCount = source.rowCount ?? source.row_count ?? source.resultCount ?? source.result_count
  const frozenFields = normalizeFrozenExportFields(source.frozenFields ?? source.frozen_fields)
  return {
    id: taskId,
    status: firstString(source.status) || 'queued',
    fileName: firstString(source.fileName, source.file_name),
    downloadUrl,
    failureReason: firstString(source.failureReason, source.failure_reason, source.errorReason, source.error_reason),
    rowCount: Number.isFinite(Number(rowCount)) ? Number(rowCount) : null,
    // 仅接受服务端已经脱敏后的冻结表头；任务结果页不能回退到当前页面字段，
    // 否则字段改名、停用或版本升级后会与实际下载文件发生漂移。
    frozenFields,
    createdAt: firstString(source.createdAt, source.created_at),
    expiresAt: firstString(source.expiresAt, source.expires_at)
  }
}

function normalizeFrozenExportFields(raw) {
  if (!Array.isArray(raw)) return []
  const seen = new Set()
  return raw.map((field) => {
    if (!isRecord(field)) return null
    const key = firstString(field.key, field.fieldKey, field.field_key)
    const label = firstString(field.label, field.name, field.title)
    if (!key || !label || seen.has(key)) return null
    seen.add(key)
    return {
      key,
      label,
      type: firstString(field.type, field.returnType, field.return_type) || 'text',
      version: Math.max(0, Number.isSafeInteger(Number(field.version)) ? Number(field.version) : 0)
    }
  }).filter(Boolean)
}
