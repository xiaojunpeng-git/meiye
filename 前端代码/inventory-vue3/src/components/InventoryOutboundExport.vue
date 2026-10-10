<script setup>
import { computed, onMounted, ref } from 'vue'
import { FileSpreadsheet } from '@lucide/vue'
import { UnifiedQueryExportDrawer, useUnifiedQueryPage } from '@mohe/unified-query-vue3'

const props = defineProps({ query: Object, count: Number, disabled: Boolean, requestAction: Function, download: Function })
const busy = ref(false)
const task = ref(null)
const error = ref('')
const frozenQuery = ref(null)
// Inventory commands nest their receipt data under result; keep the shared task parser contract local.
async function requestAction(...args) {
  const response = await props.requestAction(...args)
  return response?.result?.data ? { ...response, data: response.result.data } : response
}
const queryPage = useUnifiedQueryPage({ pageCode: 'inventory_outbound', pageName: '出库管理', baseFields: [], requestAction })
const capability = queryPage.capability
// Store exports use store identity; warehouse remains available only to legacy frozen plans.
const fields = computed(() => queryPage.fields.value.filter(field => field.key !== 'location_name'))
onMounted(() => queryPage.load())

/** Retrying from the task drawer keeps the original successful query and full-result scope. */
function createExport(configuration) {
  return queryPage.createExport({ query: frozenQuery.value, configuration: { ...configuration, scope: 'query', includeSummary: false } })
}

/** Download with the host's existing token transport, never put credentials in a link. */
async function downloadFile({ task: exportTask, fileName }) {
  const blob = await props.download(exportTask.id)
  if (!blob?.size) throw new Error('导出文件为空，请重试。')
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = fileName
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/** Freeze the successful list query; never export unsent edits or only the visible page. */
async function start() {
  if (busy.value || props.disabled || !props.query) return
  busy.value = true
  error.value = ''
  try {
    frozenQuery.value = JSON.parse(JSON.stringify(props.query))
    const result = await createExport({ scope: 'query', fields: fields.value.filter(f => f.capabilities?.export === true && !f.hidden && !['product_summary', 'detail_count', 'cost_amount_cents'].includes(f.key)).map(f => f.key), includeSummary: false, fileName: '出库管理.xlsx' })
    if (result.status === 'result_unknown' && result.idempotencyKey) {
      task.value = { status: 'result_unknown', originalIdempotencyKey: result.idempotencyKey, fileName: '出库管理.xlsx' }
    } else if (result.status === 'success' && result.task?.id) task.value = result.task
    else error.value = result.message || '导出任务创建失败。'
  } catch (e) { error.value = e.message || '导出失败。' }
  finally { busy.value = false }
}
</script>

<template>
  <button class="secondary-button" :disabled="disabled || busy || !query || capability.exportCapability?.enabled !== true" @click="start"><FileSpreadsheet :size="16" />{{ busy ? '导出中' : '导出 Excel' }}</button>
  <span v-if="error" role="alert" class="inventory-load-error">{{ error }}</span>
  <UnifiedQueryExportDrawer v-if="task" :initial-task="task" :fields="fields.filter(f => f.capabilities?.export && !['product_summary', 'detail_count', 'cost_amount_cents'].includes(f.key))" page-name="出库管理" :result-count="count" :export-capability="{ ...capability.exportCapability, allowCurrentPage: false, allowSummary: false }" :on-create="createExport" :on-query-task="queryPage.queryExportTask" :on-download="downloadFile" @close="task = null" />
</template>
