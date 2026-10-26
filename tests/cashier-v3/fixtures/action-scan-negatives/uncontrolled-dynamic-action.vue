<script setup>
import { requestCashierV3Action } from '@/services/cashierV3Bridge'

function fire(actionCode) {
  // 未受控动态 action — 负例
  requestCashierV3Action(actionCode, {})
}
</script>
<template><button @click="fire(someVar)">x</button></template>
