<script setup>
import { UnifiedQueryCustomFieldDrawer as SharedUnifiedQueryCustomFieldDrawer } from '@mohe/unified-query-vue3'

defineOptions({ inheritAttrs: false })
</script>

<template>
  <SharedUnifiedQueryCustomFieldDrawer v-bind="$attrs" />
</template>
