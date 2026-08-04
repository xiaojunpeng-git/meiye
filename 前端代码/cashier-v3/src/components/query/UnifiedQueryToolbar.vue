<script setup>
import { UnifiedQueryToolbar as SharedUnifiedQueryToolbar } from '@mohe/unified-query-vue3'
import '@mohe/unified-query-vue3/styles.css'
import { openCashierV3QueryEntitySelector } from '@/services/cashierV3Bridge'

defineOptions({ inheritAttrs: false })

const props = defineProps({
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
    entityType: selector?.kind || payload?.field?.type,
    title: selector?.label || (payload?.field?.label ? `选择${payload.field.label}` : '')
  })
}
</script>

<template>
  <SharedUnifiedQueryToolbar v-bind="$attrs" :on-select-entity="selectEntity">
    <template #primary-actions>
      <slot name="primary-actions" />
    </template>
  </SharedUnifiedQueryToolbar>
</template>
