<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  selector: { type: Object, required: true },
  saving: { type: Boolean, default: false }
})
const emit = defineEmits(['close', 'apply', 'remove'])

const selectedCouponId = ref('')

watch(
  () => props.selector.selectedCouponId,
  (value) => { selectedCouponId.value = String(value || '') },
  { immediate: true }
)

const coupons = computed(() => Array.isArray(props.selector.coupons) ? props.selector.coupons : [])
const hasSelectedCoupon = computed(() => Boolean(String(props.selector.selectedCouponId || '')))

function couponId(coupon = {}) {
  return String(coupon.couponId || '')
}

function moneyFromCents(value) {
  const cents = Number(value || 0)
  return `¥${(Number.isFinite(cents) ? cents / 100 : 0).toFixed(2)}`
}

function expiryText(value) {
  const raw = String(value || '').trim()
  if (!raw || raw === '0') return '长期有效'
  const date = /^\d+$/.test(raw)
    ? new Date(Number(raw) * 1000)
    : new Date(raw)
  if (Number.isNaN(date.getTime())) return '有效期待核对'
  return `有效期至 ${date.toLocaleDateString('zh-CN', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  })}`
}

function confirmSelection() {
  if (!selectedCouponId.value || props.saving) return
  emit('apply', selectedCouponId.value)
}
</script>

<template>
  <div class="cashier-coupon-overlay" role="dialog" aria-modal="true" aria-label="选择优惠券" @click.self="emit('close')">
    <section class="cashier-coupon-panel">
      <header>
        <div>
          <strong>选择优惠券</strong>
          <span>{{ selector.lineName || '当前商品' }} · 金额 {{ moneyFromCents(selector.lineAmountCents) }}</span>
        </div>
        <button type="button" aria-label="关闭" title="关闭" :disabled="saving" @click="emit('close')">×</button>
      </header>

      <div v-if="coupons.length" class="cashier-coupon-list" role="radiogroup" aria-label="可用优惠券">
        <label
          v-for="coupon in coupons"
          :key="couponId(coupon)"
          class="cashier-coupon-item"
          :class="{ 'is-selected': selectedCouponId === couponId(coupon) }"
        >
          <input v-model="selectedCouponId" type="radio" name="cashier-line-coupon" :value="couponId(coupon)" :disabled="saving">
          <span class="cashier-coupon-item__amount">优惠 {{ moneyFromCents(coupon.discountAmountCents) }}</span>
          <span class="cashier-coupon-item__content">
            <strong>{{ coupon.name || '优惠券' }}</strong>
            <small>
              <template v-if="Number(coupon.useMinAmountCents || 0) > 0">满 {{ moneyFromCents(coupon.useMinAmountCents) }} 可用 · </template>
              {{ expiryText(coupon.expiresAt) }}
            </small>
          </span>
        </label>
      </div>
      <div v-else class="cashier-coupon-empty">
        <strong>暂无可用优惠券</strong>
        <span>当前会员没有适用于该商品的优惠券。</span>
      </div>

      <footer>
        <button v-if="hasSelectedCoupon" type="button" class="button button--secondary" :disabled="saving" @click="emit('remove')">不使用优惠券</button>
        <span v-else></span>
        <div>
          <button type="button" class="button button--secondary" :disabled="saving" @click="emit('close')">取消</button>
          <button type="button" class="button button--primary" :disabled="saving || !selectedCouponId" @click="confirmSelection">
            {{ saving ? '保存中…' : '确认使用' }}
          </button>
        </div>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.cashier-coupon-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgb(18 25 29 / 42%);
}

.cashier-coupon-panel {
  width: min(620px, 100%);
  max-height: min(680px, calc(100vh - 48px));
  display: grid;
  grid-template-rows: auto minmax(120px, 1fr) auto;
  overflow: hidden;
  border: 1px solid #dfe5e7;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 18px 52px rgb(22 35 42 / 18%);
}

.cashier-coupon-panel > header,
.cashier-coupon-panel > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 20px;
}

.cashier-coupon-panel > header { border-bottom: 1px solid #e8edef; }
.cashier-coupon-panel > footer { border-top: 1px solid #e8edef; }
.cashier-coupon-panel > footer > div { display: flex; gap: 10px; }
.cashier-coupon-panel > header > div { display: grid; gap: 4px; }
.cashier-coupon-panel > header strong { color: #25333a; font-size: 18px; }
.cashier-coupon-panel > header span { color: #738087; font-size: 13px; }
.cashier-coupon-panel > header button {
  width: 36px;
  height: 36px;
  border: 0;
  background: transparent;
  color: #66757c;
  font-size: 24px;
  cursor: pointer;
}

.cashier-coupon-list {
  display: grid;
  align-content: start;
  gap: 10px;
  overflow: auto;
  padding: 18px 20px;
}

.cashier-coupon-item {
  min-height: 76px;
  display: grid;
  grid-template-columns: auto 116px minmax(0, 1fr);
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border: 1px solid #dfe5e7;
  border-radius: 6px;
  cursor: pointer;
}

.cashier-coupon-item.is-selected { border-color: #a9709d; background: #fbf7fa; }
.cashier-coupon-item__amount { color: #a04f83; font-weight: 700; }
.cashier-coupon-item__content { min-width: 0; display: grid; gap: 5px; }
.cashier-coupon-item__content strong { overflow-wrap: anywhere; color: #25333a; }
.cashier-coupon-item__content small { color: #738087; }
.cashier-coupon-empty { display: grid; place-content: center; gap: 8px; min-height: 180px; text-align: center; color: #738087; }
.cashier-coupon-empty strong { color: #394950; }

@media (max-width: 640px) {
  .cashier-coupon-overlay { align-items: end; padding: 12px; }
  .cashier-coupon-panel { max-height: calc(100vh - 24px); }
  .cashier-coupon-item { grid-template-columns: auto minmax(0, 1fr); }
  .cashier-coupon-item__amount { grid-column: 2; }
  .cashier-coupon-item__content { grid-column: 2; }
}
</style>
