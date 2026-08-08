import { computed, ref } from 'vue'
import {
  buildUnifiedQueryExportPayload,
  emptyUnifiedQueryCapability,
  extractUnifiedQueryExportTask,
  normalizeUnifiedQueryCapability,
  presentUnifiedQueryFields,
  unifiedQueryActionIdempotencyKey,
  unifiedQueryActionMessage,
  unifiedQueryActionStatus,
  unifiedQueryActionSucceeded
} from '../contracts/unifiedQueryContract.js'

export function useUnifiedQueryPage({ pageCode, pageName, baseFields, requestAction }) {
  const capability = ref(emptyUnifiedQueryCapability(pageCode))
  const isLoading = ref(false)
  const loadError = ref('')
  let loadSequence = 0

  const fields = computed(() => presentUnifiedQueryFields(
    typeof baseFields === 'function' ? baseFields() : baseFields,
    capability.value
  ))
  const canUseCommands = computed(() => capability.value.enabled === true && Boolean(capability.value.commandContext))

  async function load({ silent = true } = {}) {
    const sequence = ++loadSequence
    isLoading.value = true
    loadError.value = ''
    try {
      const result = await requestAction('query-unified-query-capabilities', { pageCode, silent })
      if (sequence !== loadSequence) return capability.value
      const normalized = normalizeUnifiedQueryCapability(result, pageCode)
      const requiredFields = (typeof baseFields === 'function' ? baseFields() : baseFields) || []
      if (normalized.enabled) {
        const allowedKeys = new Set(normalized.fields.map((field) => field.key))
        const missingKeys = requiredFields
          .filter((field) => field.permissionFiltered !== true)
          .map((field) => field.key)
          .filter((key) => !allowedKeys.has(key))
        if (missingKeys.length) {
          console.warn('[unified-query-schema-mismatch]', pageCode, missingKeys)
          Object.assign(normalized, {
            enabled: false,
            reason: '服务端字段白名单与当前页面不一致，增强能力已关闭。',
            fields: [],
            customFields: [],
            fieldAliases: {},
            commandContext: null
          })
        }
      }
      capability.value = normalized
      loadError.value = normalized.enabled ? '' : normalized.reason
      return normalized
    } catch (error) {
      if (sequence !== loadSequence) return capability.value
      capability.value = { ...emptyUnifiedQueryCapability(pageCode), loaded: true, reason: error?.message || '统一查询能力加载失败。' }
      loadError.value = capability.value.reason
      return capability.value
    } finally {
      if (sequence === loadSequence) isLoading.value = false
    }
  }

  function failClosed(message = '当前页面尚未开放此操作。') {
    return {
      result: { status: 'failed', code: 'UNIFIED_QUERY_CAPABILITY_DISABLED', message }
    }
  }

  async function runCommand(action, payload = {}) {
    if (!canUseCommands.value) return failClosed('当前页面缺少可验证的查询配置版本，请刷新后重试。')
    const { commandIdempotencyKey, ...commandPayload } = payload
    const result = await requestAction(action, {
      pageCode,
      ...commandPayload,
      ...(commandIdempotencyKey ? { idempotencyKey: commandIdempotencyKey } : {}),
      commandContexts: [capability.value.commandContext]
    })
    if (unifiedQueryActionSucceeded(result)) await load({ silent: true })
    return result
  }

  function saveFieldAliases(aliases, options = {}) {
    if (capability.value.permissions.renameFields !== true || capability.value.fieldAliasVersion <= 0) {
      return failClosed('字段显示名称版本尚未加载，请刷新后重试。')
    }
    return runCommand('save-unified-query-field-aliases', {
      aliases,
      expectedAliasVersion: capability.value.fieldAliasVersion,
      commandIdempotencyKey: options.idempotencyKey,
      scope: 'current_account_current_page',
      schemaVersion: capability.value.schemaVersion
    })
  }

  function saveCustomField(field) {
    const isExisting = Boolean(field?.fieldKey || field?.field_key || field?.stableKey || field?.id || field?.fieldId || field?.field_id)
    const permission = isExisting
      ? capability.value.permissions.editCustomField
      : capability.value.permissions.createCustomField
    if (permission !== true) return failClosed(isExisting ? '当前账号不能编辑此字段。' : '当前账号不能创建自定义字段。')
    if (field?.visibility === 'shared' && capability.value.permissions.shareCustomField !== true) {
      return failClosed('当前账号不能发布共享字段。')
    }
    const { commandIdempotencyKey, ...fieldPayload } = field || {}
    const fieldKey = fieldPayload.fieldKey || fieldPayload.field_key || fieldPayload.stableKey || fieldPayload.id || ''
    delete fieldPayload.id
    delete fieldPayload.stableKey
    delete fieldPayload.field_id
    return runCommand('save-unified-query-custom-field', {
      ...fieldPayload,
      ...(fieldKey ? { fieldKey } : {}),
      commandIdempotencyKey,
      schemaVersion: capability.value.schemaVersion
    })
  }

  function changeCustomFieldStatus(payload) {
    if (capability.value.permissions.changeCustomFieldStatus !== true) return failClosed('当前账号不能停用或恢复此字段。')
    const { fieldId, id, ...commandPayload } = payload || {}
    const fieldKey = commandPayload.fieldKey || commandPayload.field_key || fieldId || id || ''
    return runCommand('change-unified-query-custom-field-status', {
      ...commandPayload,
      fieldKey
    })
  }

  function archiveCustomField(payload) {
    if (capability.value.permissions.archiveCustomField !== true) return failClosed('当前账号不能删除此字段。')
    const { fieldId, id, ...commandPayload } = payload || {}
    const fieldKey = commandPayload.fieldKey || commandPayload.field_key || fieldId || id || ''
    return runCommand('archive-unified-query-custom-field', {
      ...commandPayload,
      fieldKey
    })
  }

  function upgradeSavedQueryFieldReference(payload) {
    const source = payload && typeof payload === 'object' ? payload : {}
    const fieldKey = String(source.fieldKey || source.field_key || '').trim()
    if (!/^cf_[a-f0-9]{20,40}$/.test(fieldKey)) {
      return failClosed('要升级的字段引用无效，请刷新后重试。')
    }
    // 只提交稳定字段标识；目标版本、表达式和设置均由服务端在已锁定的账号页面
    // 偏好中解析，浏览器绝不提交或决定版本号。
    return runCommand('upgrade-unified-query-field-reference', {
      fieldKey,
      ...(source.commandIdempotencyKey ? { commandIdempotencyKey: source.commandIdempotencyKey } : {})
    })
  }

  async function createExport(payload) {
    if (capability.value.exportCapability.enabled !== true) return failClosed('当前账号不能导出此页面。')
    const exportPayload = buildUnifiedQueryExportPayload(payload?.query, payload?.configuration, capability.value)
    const result = await runCommand('create-unified-query-export', {
      ...exportPayload,
      format: 'xlsx',
      schemaVersion: capability.value.schemaVersion
    })
    return {
      raw: result,
      status: unifiedQueryActionStatus(result),
      message: unifiedQueryActionMessage(result),
      idempotencyKey: unifiedQueryActionIdempotencyKey(result),
      task: extractUnifiedQueryExportTask(result)
    }
  }

  async function queryExportTask(reference) {
    const taskId = typeof reference === 'object' ? reference?.taskId || reference?.id : reference
    const originalIdempotencyKey = typeof reference === 'object' ? reference?.originalIdempotencyKey : ''
    if ((!taskId && !originalIdempotencyKey) || capability.value.enabled !== true) return failClosed('导出任务标识无效。')
    const result = await requestAction('query-unified-query-export-task', {
      pageCode,
      ...(taskId ? { taskId } : {}),
      ...(originalIdempotencyKey ? { originalIdempotencyKey } : {}),
      silent: true
    })
    return {
      raw: result,
      status: unifiedQueryActionStatus(result),
      message: unifiedQueryActionMessage(result),
      idempotencyKey: unifiedQueryActionIdempotencyKey(result) || originalIdempotencyKey,
      task: extractUnifiedQueryExportTask(result)
    }
  }

  function reset() {
    loadSequence += 1
    capability.value = emptyUnifiedQueryCapability(pageCode)
    loadError.value = ''
    isLoading.value = false
  }

  return {
    pageCode,
    pageName,
    capability,
    fields,
    isLoading,
    loadError,
    canUseCommands,
    load,
    reset,
    saveFieldAliases,
    saveCustomField,
    changeCustomFieldStatus,
    archiveCustomField,
    upgradeSavedQueryFieldReference,
    createExport,
    queryExportTask
  }
}
