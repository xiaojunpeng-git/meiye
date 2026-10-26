<script setup>
import { QueryEntitySelectorOverlay as SharedQueryEntitySelectorOverlay } from '@mohe/unified-query-vue3'

defineOptions({ inheritAttrs: false })
</script>

<template>
  <SharedQueryEntitySelectorOverlay v-bind="$attrs" />
</template>
