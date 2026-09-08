<template>
  <RouterView />
  <MoheAiEntry :key="$route.path" />
</template>
<script setup>
import MoheAiEntry from './components/MoheAiEntry.vue'
</script>
