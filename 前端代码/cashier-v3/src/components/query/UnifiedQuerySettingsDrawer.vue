<script setup>
import { UnifiedQuerySettingsDrawer as SharedUnifiedQuerySettingsDrawer } from '@mohe/unified-query-vue3'

defineOptions({ inheritAttrs: false })
</script>

<template>
  <SharedUnifiedQuerySettingsDrawer v-bind="$attrs" />
</template>
