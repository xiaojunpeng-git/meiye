<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import { UnifiedReportQueryToolbar, UnifiedQueryExportDrawer, cloneUnifiedQuerySnapshot } from '@mohe/unified-query-vue3'
import { memberReportFields, memberReportDraftSnapshot, buildMemberReportQuery } from '@/services/memberReportFilters'

// Member-only host for the shared statistics filter UI. Do not change any other
// page's toolbar, or build/export a query from unsubmitted panel drafts.
const props = defineProps({
  // 页面身份由宿主明确传入；平台和门店复用同一筛选与导出实现，不能互换查询上下文。
  pageCode: { type: String, default: 'member_list' },
  fields: { type: Array, default: () => [] }, executedQuery: { type: Object, default: null },
  statusOptions: { type: Array, default: () => [] },
  queryCapability: { type: Object, default: () => ({}) }, settings: { type: Object, default: () => ({}) },
  capabilityMessage: { type: String, default: '' },
  isQueryLoading: Boolean, pageSize: { type: Number, default: 20 },
  resultCount: { type: Number, default: 0 }, currentPageCount: { type: Number, default: 0 },
  dataAsOf: { type: String, default: '' }, stateContextKey: { type: [String, Number], default: '' },
  onCreateExport: Function, onQueryExportTask: Function, onDownloadExport: Function
})
const emit = defineEmits(['query'])
const toolbar = ref(null), keyword = ref(''), scope = ref('normal'), original = ref({}), cleared = ref(false)
const businessStatus = ref('')
const statuses = computed(() => props.statusOptions.map(option => typeof option === 'string'
  ? { value: option, label: option } : { value: option.value ?? option.label, label: option.label ?? option.value }))
const exportOpen = ref(false), exportTask = ref(null)
const fields = computed(() => props.queryCapability.enabled === true && props.queryCapability.pageCode === props.pageCode
  ? memberReportFields(props.fields) : [])
const panelQuery = computed(() => memberReportDraftSnapshot(props.executedQuery, props.pageCode))
watch(() => props.executedQuery, query => {
  // Failed reads disable export, but retain the last draft for a safe retry.
  if (query == null || (query.pageCode && query.pageCode !== props.pageCode)) return
  original.value = cloneUnifiedQuerySnapshot(query)
  keyword.value = query.keyword || ''
  scope.value = query.dataScope === 'all' ? 'all' : 'normal'
  businessStatus.value = query.businessStatus || ''
  cleared.value = false
}, { immediate: true, deep: true })
function query(panel) {
  emit('query', buildMemberReportQuery(original.value, panel, keyword.value, scope.value, props.pageSize, cleared.value, businessStatus.value, props.pageCode))
}
async function changeScope(value) {
  if (props.isQueryLoading || scope.value === value) return
  scope.value = value
  // Preserve the original all-data status shortcut; returning to normal must
  // clear an incompatible business status, not leave an invisible restriction.
  if (value === 'normal') businessStatus.value = ''
  await nextTick()
  toolbar.value?.submitQuery()
}
const exportFields = computed(() => props.fields.filter(field => !field.hidden && field.key !== 'id' && field.capabilities?.export === true))
const canExport = computed(() => {
  const capability = props.queryCapability
  return capability.enabled === true && capability.pageCode === props.pageCode
    && capability.commandContext?.kind && capability.commandContext?.id
    && props.executedQuery && (!props.executedQuery.pageCode || props.executedQuery.pageCode === props.pageCode)
    && Object.keys(props.executedQuery).length > 0 && !props.isQueryLoading
    && capability.exportCapability?.enabled === true
    && (capability.exportCapability.allowCurrentQuery === true || capability.exportCapability.allowCurrentPage === true)
    && exportFields.value.length > 0
})
function createExport(configuration) {
  if (!canExport.value || !props.onCreateExport) return { status: 'failed', message: '请先完成一次成功查询。' }
  return props.onCreateExport({ query: cloneUnifiedQuerySnapshot(props.executedQuery), configuration })
}
</script>

<template>
  <UnifiedReportQueryToolbar ref="toolbar" :fields="fields" :page-code="pageCode" :executed-query="panelQuery" :is-query-loading="isQueryLoading" keep-filter-toggle :filter-message="capabilityMessage || (isQueryLoading ? '正在加载筛选字段…' : '暂无可用筛选字段。')" @query="query" @clear="cleared = true">
    <template #leading-controls>
      <label class="member-report-search"><span class="sr-only">综合查询</span><input v-model="keyword" type="search" placeholder="搜索会员姓名、完整手机号或会员姓名首字母" autocomplete="off" @keyup.enter="toolbar?.submitQuery()" /></label>
    </template>
    <template #primary-actions>
      <div class="member-report-scope" role="group" aria-label="数据范围">
        <button v-for="option in [{ value: 'normal', label: '正常数据' }, { value: 'all', label: '全部数据' }]" :key="option.value" type="button" :aria-pressed="scope === option.value" :disabled="isQueryLoading" @click="changeScope(option.value)">{{ option.label }}</button>
      </div>
      <select v-if="scope === 'all' && statuses.length" v-model="businessStatus" class="member-report-status" aria-label="其他状态" :disabled="isQueryLoading" @change="toolbar?.submitQuery()">
        <option value="">其他状态</option><option v-for="option in statuses" :key="option.value" :value="option.value">{{ option.label }}</option>
      </select>
      <button v-if="canExport" type="button" class="button button--secondary" @click="exportOpen = true"><Download :size="16" />导出</button>
      <slot name="primary-actions" />
    </template>
  </UnifiedReportQueryToolbar>
  <UnifiedQueryExportDrawer v-if="exportOpen && canExport" :key="stateContextKey" :fields="exportFields" :visible-field-keys="settings.visibleFields || []" page-name="会员列表" :result-count="resultCount" :page-size="pageSize" :current-page-count="currentPageCount" :data-as-of="dataAsOf || queryCapability.dataAsOf" :export-capability="queryCapability.exportCapability" :initial-task="exportTask" :on-create="createExport" :on-query-task="onQueryExportTask" :on-download="onDownloadExport" @close="exportOpen = false" @task-change="exportTask = $event" />
</template>

<style scoped>
.unified-report-query-toolbar { flex: none; }
.member-report-search { display: flex; min-width: 0; width: 360px; max-width: 100%; }
.member-report-search input { width: 100%; min-width: 0; min-height: 34px; padding: 7px 10px; border: 1px solid #d8e0ea; border-radius: 6px; box-sizing: border-box; font: inherit; }
.member-report-scope { display: inline-flex; flex: none; min-height: 34px; border: 1px solid #d8e0ea; border-radius: 6px; overflow: hidden; }
.member-report-scope button { border: 0; padding: 6px 12px; background: #f2f5f9; color: #475569; cursor: pointer; font: inherit; }
.member-report-scope button[aria-pressed="true"] { background: #086ad8; color: white; }
.member-report-scope button:disabled { cursor: wait; }
.member-report-status { min-height: 34px; border: 1px solid #d8e0ea; border-radius: 6px; padding: 6px 8px; font: inherit; background: white; }
</style>
