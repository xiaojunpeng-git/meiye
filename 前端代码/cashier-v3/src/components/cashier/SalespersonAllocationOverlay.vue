<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({ records: { type: Array, default: () => [] } })
const emit = defineEmits(['close', 'confirm'])
const allocations = ref([])

watch(() => props.records, (records) => {
  allocations.value = (Array.isArray(records) ? records : []).map((record) => ({
    staffId: record.staffId || record.id,
    name: record.name || record.staffName || '',
    allocationWeight: Number(record.allocationWeight || 0)
  }))
}, { immediate: true, deep: true })

const total = computed(() => allocations.value.reduce((sum, record) => sum + Number(record.allocationWeight || 0), 0))
const valid = computed(() => allocations.value.length > 0 && total.value === 100 && allocations.value.every((record) => (
  Number.isInteger(Number(record.allocationWeight)) && Number(record.allocationWeight) > 0 && Number(record.allocationWeight) <= 100
)))

function confirm() {
  if (!valid.value) return
  emit('confirm', allocations.value.map((record) => ({
    staffId: record.staffId,
    allocationWeight: Number(record.allocationWeight)
  })))
}
</script>

<template>
  <div class="salesperson-allocation-overlay" role="dialog" aria-modal="true" aria-label="销售业绩分配">
    <section class="salesperson-allocation-panel">
      <header>
        <div><strong>销售业绩分配</strong><span>分配比例合计必须为 100%</span></div>
        <button type="button" aria-label="关闭" title="关闭" @click="emit('close')">×</button>
      </header>
      <div class="salesperson-allocation-list">
        <label v-for="record in allocations" :key="record.staffId" class="salesperson-allocation-row">
          <span>{{ record.name }}</span>
          <input v-model.number="record.allocationWeight" type="number" min="1" max="100" step="1" inputmode="numeric" aria-label="业绩分配比例">
          <b>%</b>
        </label>
      </div>
      <p :class="{ 'is-invalid': total !== 100 }">已分配 {{ total }}%</p>
      <footer>
        <button type="button" class="button button--secondary" @click="emit('close')">取消</button>
        <button type="button" class="button button--primary" :disabled="!valid" @click="confirm">保存分配</button>
      </footer>
    </section>
  </div>
</template>
