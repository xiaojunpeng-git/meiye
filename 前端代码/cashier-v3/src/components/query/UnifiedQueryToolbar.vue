<script setup>
import { UnifiedQueryToolbar as SharedUnifiedQueryToolbar } from '@mohe/unified-query-vue3'
import '@mohe/unified-query-vue3/styles.css'
import { openCashierV3QueryEntitySelector } from '@/services/cashierV3Bridge'
import { useCashierV3State } from '@/services/cashierV3Bridge'
import { computed, useAttrs } from 'vue'
import { queryPreferenceKey } from '@mohe/unified-query-vue3'

const queryState = useCashierV3State()
const queryAttrs = useAttrs()
// 不使用会话 token 作存储键；账号、门店、页面分别隔离本地偏好。
const localSettingsKey = computed(() => queryPreferenceKey(queryState.operator?.account, queryState.currentStore?.id, queryAttrs['page-code'] || queryAttrs.pageCode))

defineOptions({ inheritAttrs: false })

const props = defineProps({
  inlineQuickControls: {
    type: Boolean,
    default: false
  },
  compactKeywordSearch: {
    type: Boolean,
    default: false
  },
  showDataScope: {
    type: Boolean,
    default: true
  },
  showSettingsButton: {
    type: Boolean,
    default: true
  },
  onSelectEntity: {
    type: Function,
    default: null
  }
})
function selectEntity(payload) {
  if (props.onSelectEntity) return props.onSelectEntity(payload)
  const selector = payload?.field?.selector
  return openCashierV3QueryEntitySelector({
    ...payload,
    // top_filter/组合筛选是组件位置，不是后端人员分配场景。
    scope: 'query_filter',
    entityType: selector?.kind || payload?.field?.type,
    title: selector?.label || (payload?.field?.label ? `选择${payload.field.label}` : '')
  })
}
</script>

<template>
  <SharedUnifiedQueryToolbar
    v-bind="$attrs"
    :local-settings-key="localSettingsKey"
    :inline-quick-controls="inlineQuickControls"
    :compact-keyword-search="compactKeywordSearch"
    :show-data-scope="showDataScope"
    :show-settings-button="showSettingsButton"
    :on-select-entity="selectEntity"
  >
    <template #leading-controls>
      <slot name="leading-controls" />
    </template>
    <template #primary-actions>
      <slot name="primary-actions" />
    </template>
  </SharedUnifiedQueryToolbar>
</template>
