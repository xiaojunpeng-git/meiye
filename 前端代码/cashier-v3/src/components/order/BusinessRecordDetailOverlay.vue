<script setup>
import { computed, ref } from 'vue'
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
  },
  lifecycleActions: { type: Array, default: () => [] },
  onLifecycleAction: { type: Function, default: null },
  fieldActionKeys: { type: Array, default: () => [] }
})

const emit = defineEmits(['close', 'field-action'])

const activeForm = ref('')
const cashRefundAmount = ref('')
const principalAmount = ref('0')
const bonusAmount = ref('0')
const reason = ref('')
const pending = ref(false)
const actionError = ref('')

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

function validMoney(value, allowZero = false) {
  const raw = String(value ?? '').trim()
  if (!/^\d+(\.\d{1,2})?$/.test(raw)) return false
  const amount = Number(raw)
  return Number.isFinite(amount) && (allowZero ? amount >= 0 : amount > 0)
}

async function submitLifecycleAction() {
  if (!props.onLifecycleAction || pending.value) return
  actionError.value = ''
  if (!reason.value.trim()) {
    actionError.value = activeForm.value === 'refund' ? '请填写退款原因。' : '请填写作废原因。'
    return
  }
  const payload = {
    action: activeForm.value === 'refund' ? 'refund-recharge-order'
      : activeForm.value === 'supplement-void' ? 'void-order-center-supplement'
        : activeForm.value === 'gift-void' ? 'void-order-center-gift' : 'void-recharge-order',
    reason: reason.value.trim()
  }
  if (activeForm.value === 'refund') {
    if (!validMoney(cashRefundAmount.value, true) || !validMoney(principalAmount.value, true) || !validMoney(bonusAmount.value, true)
      || Number(cashRefundAmount.value) + Number(principalAmount.value) + Number(bonusAmount.value) <= 0) {
      actionError.value = '实际退款、扣回本金和扣回赠金至少需要填写一项。'
      return
    }
    Object.assign(payload, { cashRefundAmount: cashRefundAmount.value, principalRefundAmount: principalAmount.value, bonusRefundAmount: bonusAmount.value })
  }
  pending.value = true
  try {
    const response = await props.onLifecycleAction(payload)
    const envelope = response?.data?.result ? response.data : response
    const status = String(envelope?.result?.status || envelope?.status || '')
    if (!['success', 'succeeded'].includes(status)) {
      actionError.value = envelope?.result?.message || envelope?.message || '本次操作没有成功，订单状态未改变。'
      return
    }
    activeForm.value = ''
    reason.value = ''
  } catch (error) {
    actionError.value = error?.message || '本次操作没有成功，订单状态未改变。'
  } finally {
    pending.value = false
  }
}
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
            <dd>
              <button v-if="fieldActionKeys.includes(row.key)" type="button" class="business-record-detail__field-action" @click="emit('field-action', { key: row.key, record })">{{ row.displayValue }}</button>
              <template v-else>{{ row.displayValue }}</template>
            </dd>
          </div>
        </dl>
        <section v-if="activeForm" class="business-record-detail__lifecycle">
          <h3>{{ activeForm === 'refund' ? '充值退款并作废' : activeForm === 'supplement-void' ? '作废补交记录' : activeForm === 'gift-void' ? '作废赠送记录' : '作废充值订单' }}</h3>
          <template v-if="activeForm === 'refund'">
            <label>实际退款金额<input v-model.trim="cashRefundAmount" inputmode="decimal" placeholder="实际退给客户的金额" /></label>
            <div class="business-record-detail__amount-row">
              <label>扣回本金<input v-model.trim="principalAmount" inputmode="decimal" /></label>
              <label>扣回赠金<input v-model.trim="bonusAmount" inputmode="decimal" /></label>
            </div>
          </template>
          <small v-if="activeForm === 'refund'">退款成功后订单只保留查看；已发生的欠款、赠品和权益不会回退。</small>
          <label>{{ activeForm === 'refund' ? '退款原因' : '作废原因' }}<textarea v-model.trim="reason" maxlength="255" rows="3" /></label>
          <p v-if="actionError" role="alert">{{ actionError }}</p>
          <div><button type="button" class="button button--text" :disabled="pending" @click="activeForm = ''">取消</button><button type="button" class="button button--primary" :disabled="pending" @click="submitLifecycleAction">确认</button></div>
        </section>
      </main>

      <footer class="business-record-detail__footer">
        <button v-if="lifecycleActions.includes('refund')" type="button" class="button button--secondary" :disabled="pending" @click="activeForm = 'refund'">退款并作废</button>
        <button v-if="lifecycleActions.includes('void')" type="button" class="button button--secondary" :disabled="pending" @click="activeForm = 'void'">作废</button>
        <button v-if="lifecycleActions.includes('supplement-void')" type="button" class="button button--secondary" :disabled="pending" @click="activeForm = 'supplement-void'">作废</button>
        <button v-if="lifecycleActions.includes('gift-void')" type="button" class="button button--secondary" :disabled="pending" @click="activeForm = 'gift-void'">作废</button>
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

.business-record-detail__lifecycle { display:grid; gap:12px; margin-top:18px; padding:16px; border:1px solid #dfe5ec; border-radius:6px; background:#fff; }
.business-record-detail__lifecycle h3, .business-record-detail__lifecycle p { margin:0; }
.business-record-detail__lifecycle label { display:grid; gap:6px; color:#475467; font-size:13px; }
.business-record-detail__lifecycle input, .business-record-detail__lifecycle textarea { width:100%; box-sizing:border-box; padding:9px 10px; border:1px solid #cfd7e3; border-radius:5px; font:inherit; }
.business-record-detail__lifecycle > div:last-child { display:flex; justify-content:flex-end; gap:10px; }
.business-record-detail__lifecycle p { color:#b42318; }
.business-record-detail__amount-row { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }

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

.business-record-detail__field-action {
  max-width: 100%;
  padding: 0;
  border: 0;
  background: transparent;
  color: #175cd3;
  font: inherit;
  text-align: left;
  text-decoration: underline;
  cursor: pointer;
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
