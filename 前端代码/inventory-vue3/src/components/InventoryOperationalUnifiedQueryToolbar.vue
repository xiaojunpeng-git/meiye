<script setup>
import { onMounted, ref, unref, watch } from 'vue'
import { UnifiedQueryToolbar, useUnifiedQueryPage } from '@mohe/unified-query-vue3'

const props = defineProps({
  pageCode: { type: String, required: true },
  pageName: { type: String, required: true },
  fields: { type: Array, required: true },
  resultCount: { type: Number, default: 0 },
  currentPageCount: { type: Number, default: 0 },
  isQueryLoading: { type: Boolean, default: false },
  requestAction: { type: Function, required: true },
  initialQuery: { type: Object, default: () => ({}) },
  defaultQuickDateRanges: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['query'])
const toolbar = ref(null)

const unifiedQuery = useUnifiedQueryPage({
  pageCode: props.pageCode,
  pageName: props.pageName,
  baseFields: () => unref(props.fields),
  requestAction: (action, payload) => props.requestAction(action, { ...payload, pageCode: props.pageCode }),
})
const queryFields = unifiedQuery.fields
const queryCapability = unifiedQuery.capability
const queryLoadError = unifiedQuery.loadError

async function saveSettings(settings, options = {}) {
  const result = await props.requestAction('save-unified-query-settings', {
    pageCode: props.pageCode,
    settings,
    ...(options.idempotencyKey ? { idempotencyKey: options.idempotencyKey } : {}),
  })
  if (result?.result?.status === 'success') await unifiedQuery.load({ silent: true })
  return result
}

function query(value) { emit('query', value) }
function openSettings() { toolbar.value?.openSettings() }

defineExpose({ openSettings })

// 首次查询使用页面上实际显示的默认周期，避免结果为全时段而控件显示本月。
function emitInitialQuery() {
  emit('query', { ...toolbar.value?.querySnapshot(), ...props.initialQuery })
}
onMounted(async () => { await unifiedQuery.load(); emitInitialQuery() })
watch(() => props.pageCode, async () => { unifiedQuery.reset(); await unifiedQuery.load(); emitInitialQuery() })
</script>

<template>
  <section class="inventory-unified-query" :class="{ 'inventory-unified-query--statistics': pageCode.startsWith('inventory_statistics_') }" aria-label="统一查询">
    <UnifiedQueryToolbar
      ref="toolbar"
      :search-placeholder="`搜索${pageName}记录`"
      settings-button-label="查询设置"
      :show-settings-button="false"
      :fields="queryFields"
      :page-code="pageCode"
      :page-name="pageName"
      :default-quick-date-ranges="defaultQuickDateRanges"
      :result-count="resultCount"
      :page-size="20"
      :current-page="1"
      :current-page-count="currentPageCount"
      :data-as-of="queryCapability.dataAsOf"
      :query-capability="queryCapability"
      :is-query-loading="isQueryLoading"
      :state-context-key="`inventory-store-${pageCode}`"
      :settings="queryCapability.querySettings || {}"
      @query="query"
      :on-save-settings="saveSettings"
      :on-save-field-aliases="unifiedQuery.saveFieldAliases"
      :on-save-custom-field="unifiedQuery.saveCustomField"
      :on-change-custom-field-status="unifiedQuery.changeCustomFieldStatus"
      :on-archive-custom-field="unifiedQuery.archiveCustomField"
      :on-upgrade-saved-query-field-reference="unifiedQuery.upgradeSavedQueryFieldReference"
      :on-create-export="unifiedQuery.createExport"
      :on-query-export-task="unifiedQuery.queryExportTask"
    />
    <p v-if="queryLoadError" class="inventory-load-error">{{ queryLoadError }}</p>
  </section>
</template>
