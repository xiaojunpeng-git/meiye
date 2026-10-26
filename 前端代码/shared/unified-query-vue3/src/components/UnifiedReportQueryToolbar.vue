<script setup>
import { computed, ref, watch } from 'vue'
import { cloneUnifiedQuerySnapshot } from '../contracts/unifiedQueryContract.js'
import { buildReportFilterQuery, reportFilterDraft, reportFilterFields, reportFilterOptions, reportFilterType } from '../contracts/reportFilters.js'

// 报表专用外观独立于旧 UnifiedQueryToolbar，既有消费者不会因新标准改变呈现。
const props = defineProps({
  fields: { type: Array, default: () => [] },
  pageCode: { type: String, default: '' },
  isQueryLoading: { type: Boolean, default: false },
  executedQuery: { type: Object, default: () => ({}) }
})
const emit = defineEmits(['query'])
const expanded = ref(false)
const errorMessage = ref('')
const draft = ref({})
const snapshot = ref({})
const filterFields = computed(() => reportFilterFields(props.fields))

// 切报表先丢弃上一页草稿与快照；仅接收本页外部查询，阻止旧响应串入新页面。
// 字段元数据更新时保留已编辑字段；首次返回元数据时再恢复对应的外部筛选。
// 同页 null 表示宿主禁用失败结果的导出，不是重置命令；重试仍保留草稿和隐藏条件。
watch([() => props.pageCode, () => JSON.stringify(props.executedQuery), filterFields], ([pageCode, queryJson], previous = []) => {
  const pageChanged = pageCode !== previous[0]
  const queryChanged = queryJson !== previous[1]
  const query = props.executedQuery
  if (pageChanged) expanded.value = false
  if (pageChanged || (queryChanged && query != null)) {
    const belongsToPage = !query?.pageCode || query.pageCode === pageCode
    snapshot.value = belongsToPage && (!pageChanged || queryChanged) ? cloneUnifiedQuerySnapshot(query) : {}
    draft.value = reportFilterDraft(props.fields, snapshot.value)
  } else {
    const restored = reportFilterDraft(props.fields, snapshot.value)
    draft.value = Object.fromEntries(filterFields.value.map((field) => {
      const value = draft.value[field.key]
      const sameShape = reportFilterType(field) === 'number' ? value && typeof value === 'object' : typeof value !== 'object'
      return [field.key, Object.hasOwn(draft.value, field.key) && sameShape ? value : restored[field.key]]
    }))
  }
  errorMessage.value = ''
}, { immediate: true, deep: true })

// 清空只影响面板草稿，不动宿主组织/日期，不发请求也不改已执行查询。
function clearFilters() {
  draft.value = reportFilterDraft(props.fields)
  errorMessage.value = ''
}

// 同一入口处理按钮和回车；正在执行时不重复提交，错误范围不能静默丢弃。
function submitQuery() {
  if (props.isQueryLoading) return
  try {
    const query = buildReportFilterQuery(props.fields, draft.value, snapshot.value, props.pageCode)
    errorMessage.value = ''
    emit('query', query)
  } catch (error) {
    errorMessage.value = error.message
  }
}
</script>

<template>
  <section class="unified-report-query-toolbar" aria-label="报表查询条件">
    <div class="unified-report-query-toolbar__primary">
      <div v-if="$slots['leading-controls']" class="unified-report-query-toolbar__leading"><slot name="leading-controls" /></div>
      <button v-if="filterFields.length" type="button" class="button button--secondary unified-report-query-toolbar__toggle" :aria-expanded="expanded" @click="expanded = !expanded">{{ expanded ? '收起筛选' : '更多筛选' }}</button>
      <button type="button" class="button button--primary unified-report-query-toolbar__query" :disabled="isQueryLoading" :aria-busy="isQueryLoading" @click="submitQuery">{{ isQueryLoading ? '查询中' : '查询' }}</button>
      <div v-if="$slots['primary-actions']" class="unified-report-query-toolbar__actions" aria-label="报表操作"><slot name="primary-actions" /></div>
    </div>
    <div v-if="expanded && filterFields.length" class="unified-report-query-toolbar__panel" aria-label="报表高级筛选">
      <div class="unified-report-query-toolbar__fields" @keydown.enter.prevent="submitQuery">
        <label v-for="field in filterFields" :key="field.key" class="unified-report-query-toolbar__field" :class="{ 'unified-report-query-toolbar__field--range': reportFilterType(field) === 'number' }">
          <span>{{ field.label || field.key }}</span>
          <span v-if="reportFilterType(field) === 'number'" class="unified-report-query-toolbar__range">
            <input v-model="draft[field.key].min" type="number" step="any" placeholder="最小值" :aria-label="`${field.label || field.key}最小值`">
            <span>至</span>
            <input v-model="draft[field.key].max" type="number" step="any" placeholder="最大值" :aria-label="`${field.label || field.key}最大值`">
          </span>
          <select v-else-if="reportFilterType(field) === 'enum'" v-model="draft[field.key]" :aria-label="`${field.label || field.key}筛选`">
            <option value="">全部</option>
            <option v-for="(option, index) in reportFilterOptions(field)" :key="index" :value="option.value">{{ option.label }}</option>
          </select>
          <input v-else v-model="draft[field.key]" :type="reportFilterType(field)" :aria-label="`${field.label || field.key}筛选`">
        </label>
      </div>
      <button type="button" class="button button--secondary unified-report-query-toolbar__clear" @click="clearFilters">清空筛选</button>
    </div>
    <p v-if="errorMessage" class="unified-report-query-toolbar__error" role="alert">{{ errorMessage }}</p>
  </section>
</template>

<style scoped>
.unified-report-query-toolbar { min-width: 0; border: 1px solid #dde4ed; border-radius: 8px; background: #fff; color: #252a34; }
.unified-report-query-toolbar__primary { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; min-width: 0; padding: 13px 16px; }
.unified-report-query-toolbar__leading { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; min-width: 0; max-width: 100%; }
.unified-report-query-toolbar__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; min-width: 0; max-width: 100%; margin-left: auto; }
.unified-report-query-toolbar :deep(.button) { display: inline-flex; justify-content: center; align-items: center; flex: none; gap: 6px; min-height: 34px; box-sizing: border-box; font-size: 13px; white-space: nowrap; }
.unified-report-query-toolbar__panel { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; min-height: 0; max-height: min(38vh, 340px); overflow-y: auto; padding: 2px 16px 13px; }
.unified-report-query-toolbar__fields { display: flex; flex-wrap: wrap; align-items: end; gap: 8px; width: 100%; min-width: 0; }
.unified-report-query-toolbar__field { display: grid; flex: 0 1 180px; gap: 5px; min-width: 0; max-width: 100%; color: #687586; font-size: 12px; overflow-wrap: anywhere; }
.unified-report-query-toolbar__field--range { flex-basis: 260px; }
.unified-report-query-toolbar__field input, .unified-report-query-toolbar__field select { width: 100%; min-width: 0; min-height: 34px; box-sizing: border-box; border: 1px solid #d8e0ea; border-radius: 6px; padding: 6px 8px; background: #fff; color: #252a34; font: inherit; }
.unified-report-query-toolbar__range { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 6px; }
.unified-report-query-toolbar__error { margin: 0; padding: 0 16px 13px; color: #b42318; font-size: 13px; }
@media (max-width: 760px) {
  .unified-report-query-toolbar__actions { width: 100%; margin-left: 0; }
  .unified-report-query-toolbar__field { flex: 1 1 160px; }
  .unified-report-query-toolbar__field--range { flex-basis: 260px; }
}
</style>
