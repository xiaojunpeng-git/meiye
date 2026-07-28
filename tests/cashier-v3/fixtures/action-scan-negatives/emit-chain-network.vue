<script setup>
const emit = defineEmits(['request'])
function relay(action) {
  emit('request', { action })
}
function sink(action) {
  requestCashierV3Action(action, {})
}
import { requestCashierV3Action } from '@/services/cashierV3Bridge'
</script>
<template>
  <Child @request="(e) => sink(e.action)" />
</template>
