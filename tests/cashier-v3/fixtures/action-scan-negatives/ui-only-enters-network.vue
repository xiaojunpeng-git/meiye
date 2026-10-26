<script setup>
import { requestCashierV3Action } from '@/services/cashierV3Bridge'

function goWriteoff() {
  // UI_ONLY go-writeoff 不得入网
  requestCashierV3Action('go-writeoff', {})
}
</script>
<template><button @click="goWriteoff">核销</button></template>
