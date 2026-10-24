<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import { UnifiedReportQueryToolbar, UnifiedQueryDateRange, UnifiedQueryExportDrawer, cloneUnifiedQuerySnapshot, unifiedQueryActionSucceeded } from '@mohe/unified-query-vue3'
import { buildOrderReportQuery, orderReportDate, orderReportDraftSnapshot, orderReportFields } from '../../services/orderCenterReportFilters.js'

// 门店订单各页签共用统计筛选，页面身份与字段由宿主提供；不合并各业务的查询或导出权限。
const props = defineProps({
  pageCode: { type: String, default: 'order_center_all' }, pageName: { type: String, default: '全部订单' },
  sourceOptions: { type: Array, default: null },
  fields: { type: Array, default: () => [] }, executedQuery: { type: Object, default: null },
  isQueryLoading: Boolean, pageSize: { type: Number, default: 20 },
  queryCapability: { type: Object, default: () => ({}) }, settings: { type: Object, default: () => ({}) },
  resultCount: { type: Number, default: 0 }, currentPageCount: { type: Number, default: 0 }, dataAsOf: { type: String, default: '' },
  onCreateExport: Function, onQueryExportTask: Function, onDownloadExport: Function
})
const emit = defineEmits(['query'])
const toolbar = ref(null)
const today = new Date().toLocaleDateString('sv-SE')
const date = ref(orderReportDate({}, today))
const scope = ref('normal')
const original = ref({})
const cleared = ref(false)
const fields = computed(() => orderReportFields(props.fields, props.sourceOptions))
const panelQuery = computed(() => orderReportDraftSnapshot(props.executedQuery, fields.value, props.pageCode))
watch([() => props.pageCode, () => props.executedQuery], ([pageCode, query], previous = []) => {
  // 页签切换只恢复本页已执行条件；原页的日期、草稿和权限上下文不能带入新页。
  if (pageCode !== previous[0]) {
    original.value = {}
    date.value = orderReportDate({}, today)
    scope.value = 'normal'
    cleared.value = false
  }
  // 失败禁用导出但不抹掉正在编辑的草稿；只有成功响应才更新执行基线。
  if (query == null || (query.pageCode && query.pageCode !== pageCode)) return
  original.value = cloneUnifiedQuerySnapshot(query)
  date.value = orderReportDate(query, today)
  scope.value = query.dataScope === 'all' ? 'all' : 'normal'
  cleared.value = false
}, { immediate: true, deep: true })
function query(panel) {
  emit('query', buildOrderReportQuery(original.value, panel, date.value, scope.value, props.pageSize, cleared.value, fields.value, props.pageCode))
}
async function changeScope(value) {
  if (props.isQueryLoading || scope.value === value) return
  scope.value = value
  await nextTick()
  toolbar.value?.submitQuery()
}

const exporting = ref(false)
const exportOpen = ref(false)
const exportTask = ref(null)
const exportError = ref('')
// 关闭旧页任务，避免跨业务页继续展示或回查上一页的导出。
watch(() => props.pageCode, () => { exportOpen.value = false; exportTask.value = null; exportError.value = '' })
const exportFields = computed(() => props.fields.filter(field => !field.hidden && field.key !== 'id' && field.capabilities?.export === true))
const canExport = computed(() => {
  const capability = props.queryCapability
  return capability.enabled === true && capability.pageCode === props.pageCode
    && capability.commandContext?.kind && capability.commandContext?.id
    && props.executedQuery && (!props.executedQuery.pageCode || props.executedQuery.pageCode === props.pageCode)
    && Object.keys(props.executedQuery).length && !props.isQueryLoading
    && capability.exportCapability?.enabled === true
    && (capability.exportCapability.allowCurrentQuery === true || capability.exportCapability.allowCurrentPage === true)
    && exportFields.value.length > 0
})
function createExport(configuration) {
  // 未查询的日期、范围和更多筛选草稿不得混入导出。
  if (!canExport.value || !props.onCreateExport) return { status: 'failed', message: '请先完成一次成功查询。' }
  return props.onCreateExport({ query: cloneUnifiedQuerySnapshot(props.executedQuery), configuration })
}
async function startExport() {
  if (!canExport.value || exporting.value) return
  exporting.value = true
  exportError.value = ''
  try {
    const allowed = new Set(exportFields.value.map(field => field.key))
    const selected = (props.settings.visibleFields || []).filter(key => allowed.has(key))
    const fileName = `${props.pageName}_${today.replaceAll('-', '')}.xlsx`
    const result = await createExport({ scope: 'query', fields: selected.length ? selected : [...allowed], includeSummary: false, fileName })
    // 超时只能回查原任务，不能重复创建；复用统一抽屉的幂等恢复与下载逻辑。
    if (result?.status === 'result_unknown' && result.idempotencyKey) {
      exportTask.value = { id: null, status: 'result_unknown', fileName, rowCount: props.resultCount, originalIdempotencyKey: result.idempotencyKey }
    } else if ((result?.status === 'success' || unifiedQueryActionSucceeded(result?.raw || result)) && result?.task?.id) {
      exportTask.value = result.task
    } else throw new Error(result?.message || '导出任务创建失败，请重试。')
    exportOpen.value = true
  } catch (error) { exportError.value = error.message }
  finally { exporting.value = false }
}
</script>

<template>
  <UnifiedReportQueryToolbar ref="toolbar" :fields="fields" :page-code="pageCode" :executed-query="panelQuery" :is-query-loading="isQueryLoading" @query="query" @clear="cleared = true">
    <template #leading-controls>
      <UnifiedQueryDateRange v-model="date" :allow-clear="false" />
    </template>
    <template #primary-actions>
      <div class="order-report-scope" role="group" aria-label="订单范围">
        <button v-for="option in [{ value: 'normal', label: '正常' }, { value: 'all', label: '全部' }]" :key="option.value" type="button" :aria-pressed="scope === option.value" :disabled="isQueryLoading" @click="changeScope(option.value)">{{ option.label }}</button>
      </div>
      <slot name="primary-actions" />
      <button v-if="canExport" type="button" class="button button--primary" :disabled="exporting" @click="startExport"><Download :size="14" />{{ exporting ? '导出中' : '导出' }}</button>
    </template>
  </UnifiedReportQueryToolbar>
  <p v-if="exportError" role="alert">{{ exportError }}</p>
  <UnifiedQueryExportDrawer v-if="exportOpen && canExport" :fields="exportFields" :visible-field-keys="settings.visibleFields || []" :page-name="pageName" :result-count="resultCount" :page-size="pageSize" :current-page-count="currentPageCount" :data-as-of="dataAsOf || queryCapability.dataAsOf" :export-capability="queryCapability.exportCapability" :initial-task="exportTask" :on-create="createExport" :on-query-task="onQueryExportTask" :on-download="onDownloadExport" @close="exportOpen = false" @task-change="exportTask = $event" />
</template>

<style scoped>
/* 查询栏展开占用自身高度，结果区继续滚动，不能被订单页的弹性布局压缩。 */
.unified-report-query-toolbar { flex: none; }
.order-report-scope { display: inline-flex; flex: none; min-height: 34px; border: 1px solid #d8e0ea; border-radius: 6px; overflow: hidden; }
.order-report-scope button { width: 68px; border: 0; background: #f2f5f9; color: #475569; cursor: pointer; font: inherit; }
.order-report-scope button[aria-pressed="true"] { background: #086ad8; color: white; }
.order-report-scope button:disabled { cursor: wait; }
</style>
