<script setup>
import { UnifiedQueryFieldRenameDrawer as SharedUnifiedQueryFieldRenameDrawer } from '@mohe/unified-query-vue3'

defineOptions({ inheritAttrs: false })
</script>

<template>
  <SharedUnifiedQueryFieldRenameDrawer v-bind="$attrs" />
</template>
