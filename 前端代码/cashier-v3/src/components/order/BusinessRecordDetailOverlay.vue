<script setup>
import { computed } from 'vue'
import { formatMoney } from '@/services/cashierV3Bridge'

const props = defineProps({
  title: {
    type: String,
    default: '记录详情'
  },
  record: {
    type: Object,
    default: () => ({})
  },
  fields: {
    type: Array,
    default: () => []
  },
  resolveValue: {
    type: Function,
    required: true
  }
})

defineEmits(['close'])

const rows = computed(() => props.fields.map((field) => {
  const value = props.resolveValue(props.record, field.key)
  return {
    ...field,
    value,
    displayValue: field.type === 'money'
      ? formatMoney(value)
      : value === undefined || value === null || value === '' ? '—' : String(value)
  }
}))
</script>

<template>
  <div class="business-record-detail" role="presentation" @click.self="$emit('close')">
    <section class="business-record-detail__dialog" role="dialog" aria-modal="true" :aria-label="title">
      <header class="business-record-detail__header">
        <div>
          <span>订单中心</span>
          <h2>{{ title }}</h2>
        </div>
        <button type="button" class="business-record-detail__close" aria-label="关闭详情" @click="$emit('close')">×</button>
      </header>

      <main class="business-record-detail__body">
        <dl class="business-record-detail__grid">
          <div v-for="row in rows" :key="row.key" :class="{ 'business-record-detail__money': row.type === 'money' }">
            <dt>{{ row.label }}</dt>
            <dd>{{ row.displayValue }}</dd>
          </div>
        </dl>
      </main>

      <footer class="business-record-detail__footer">
        <button type="button" class="button button--primary" @click="$emit('close')">关闭</button>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.business-record-detail {
  position: fixed;
  z-index: 1450;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 28px;
  background: rgba(21, 29, 43, .38);
}

.business-record-detail__dialog {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  width: min(900px, 100%);
  max-height: min(760px, calc(100vh - 56px));
  overflow: hidden;
  border: 1px solid #dfe5ec;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 22px 60px rgba(31, 42, 55, .22);
}

.business-record-detail__header,
.business-record-detail__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 22px;
}

.business-record-detail__header {
  border-bottom: 1px solid #e8edf2;
}

.business-record-detail__header > div {
  display: grid;
  gap: 3px;
}

.business-record-detail__header span {
  color: #8a94a3;
  font-size: 12px;
}

.business-record-detail__header h2 {
  margin: 0;
  color: #20252b;
  font-size: 20px;
  letter-spacing: 0;
}

.business-record-detail__close {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: #f1f3f5;
  color: #596574;
  font-size: 22px;
}

.business-record-detail__body {
  min-height: 0;
  overflow: auto;
  padding: 22px;
  background: #f7f9fb;
}

.business-record-detail__grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  margin: 0;
  overflow: hidden;
  border: 1px solid #e0e6ed;
  border-radius: 6px;
  background: #fff;
}

.business-record-detail__grid > div {
  min-width: 0;
  min-height: 76px;
  padding: 14px 16px;
  border-right: 1px solid #edf0f4;
  border-bottom: 1px solid #edf0f4;
}

.business-record-detail__grid > div:nth-child(3n) {
  border-right: 0;
}

.business-record-detail__grid dt {
  margin-bottom: 7px;
  color: #8a94a3;
  font-size: 12px;
}

.business-record-detail__grid dd {
  margin: 0;
  overflow-wrap: anywhere;
  color: #303640;
  font-size: 14px;
  line-height: 21px;
}

.business-record-detail__money dd {
  color: #1e5ca8;
  font-size: 16px;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
}

.business-record-detail__footer {
  justify-content: flex-end;
  border-top: 1px solid #e8edf2;
}

@media (max-width: 760px) {
  .business-record-detail {
    padding: 12px;
  }

  .business-record-detail__dialog {
    max-height: calc(100vh - 24px);
  }

  .business-record-detail__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .business-record-detail__grid > div:nth-child(3n) {
    border-right: 1px solid #edf0f4;
  }

  .business-record-detail__grid > div:nth-child(2n) {
    border-right: 0;
  }
}
</style>
