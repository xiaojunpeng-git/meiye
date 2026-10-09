<script setup>
import { numericRangePresets } from '../contracts/numericRangePresets.js'

// 受控数量筛选：模式、上下界均由工具栏持有，重置和查询快照沿用同一个状态源。
defineProps({
  label: { type: String, required: true },
  mode: { type: String, default: 'positive' },
  min: { type: [String, Number], default: '' },
  max: { type: [String, Number], default: '' }
})
defineEmits(['mode', 'bound', 'query'])
</script>

<template>
  <div class="unified-query-number-control">
    <div class="unified-query-number-presets" role="group" :aria-label="label">
      <span class="unified-query-number-presets__thumb" aria-hidden="true" :style="{ transform: `translateX(${numericRangePresets.findIndex(option => option.value === mode) * 100}%)` }" />
      <button v-for="option in numericRangePresets" :key="option.value" type="button" :aria-label="option.label" :aria-pressed="mode === option.value" @click="$emit('mode', option.value)">{{ option.label }}</button>
    </div>
    <div v-if="mode === 'custom'" class="unified-query-top-field__range">
      <input :value="min" type="number" step="any" placeholder="最小值" aria-label="最小值" @input="$emit('bound', 'min', $event.target.value)" @keyup.enter="$emit('query')">
      <span>至</span>
      <input :value="max" type="number" step="any" placeholder="最大值" aria-label="最大值" @input="$emit('bound', 'max', $event.target.value)" @keyup.enter="$emit('query')">
    </div>
  </div>
</template>
