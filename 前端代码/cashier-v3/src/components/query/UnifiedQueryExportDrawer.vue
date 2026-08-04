<script setup>
import { UnifiedQueryExportDrawer as SharedUnifiedQueryExportDrawer } from '@mohe/unified-query-vue3'

defineOptions({ inheritAttrs: false })
</script>

<template>
  <SharedUnifiedQueryExportDrawer v-bind="$attrs" />
</template>
