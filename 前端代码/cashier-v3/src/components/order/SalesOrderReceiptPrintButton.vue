<script setup>
import { openSalesOrderReceiptPrint } from '@/services/salesOrderReceiptPrint'

const props = defineProps({
  order: {
    type: Object,
    default: () => ({})
  },
  disabled: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['error'])

function printReceipt() {
  const result = openSalesOrderReceiptPrint(props.order)
  if (!result.ok) emit('error', result.message)
}
</script>

<template>
  <button type="button" :disabled="disabled" title="打印销售订单小票" @click="printReceipt">
    <slot>小票打印</slot>
  </button>
</template>
