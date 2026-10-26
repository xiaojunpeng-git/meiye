<script setup>
import { computed, ref, watch } from 'vue'
import { formatMoney } from '@/services/cashierV3Bridge'
import { allowanceAmountCents, validAllowanceCents } from '@/services/couponAllowance'

const props = defineProps({
  selector: { type: Object, required: true },
  saving: { type: Boolean, default: false }
})
const emit = defineEmits(['close', 'apply', 'remove'])

const selectedCouponId = ref('')
const selectedCouponIds = ref([])
const selectionError = ref('')
const amountCoupon = ref(null)
const amountInput = ref('')
const amountError = ref('')

watch(
  () => props.selector.selectedCouponId,
  (value) => { selectedCouponId.value = String(value || '') },
  { immediate: true }
)
watch(() => props.selector.selectedCouponIds, (ids) => {
  selectedCouponIds.value = Array.isArray(ids) ? ids.map(String) : []
}, { immediate: true })

const coupons = computed(() => Array.isArray(props.selector.coupons) ? props.selector.coupons : [])
const belowMinimumCoupons = computed(() => Array.isArray(props.selector.belowMinimumCoupons) ? props.selector.belowMinimumCoupons : [])
const hasSelectedCoupon = computed(() => Boolean(String(props.selector.selectedCouponId || ''))
  || (Array.isArray(props.selector.selectedCouponIds) && props.selector.selectedCouponIds.length > 0))
const confirmRemovesCoupon = computed(() => hasSelectedCoupon.value
  && !selectedCouponId.value && !selectedCouponIds.value.length)
const selectedMode = computed(() => selectedCouponIds.value.length ? 1
  : (selectedCouponId.value ? 0 : props.selector.selectedMode))
const orderLimit = computed(() => Number(coupons.value.find((coupon) =>
  couponId(coupon) === selectedCouponIds.value[0])?.orderMaxCount || 0))
// Zero remaining line capacity is a real boundary, not a missing value that
// may fall back to the original line amount.
const lineCapacityCents = computed(() => Number(props.selector.lineCapacityCents
  ?? props.selector.lineAmountCents ?? 0))
const selectedAmountCents = computed(() => selectedCouponIds.value.reduce((sum, id) => {
  const coupon = coupons.value.find((item) => couponId(item) === id)
  return sum + Number(coupon?.discountAmountCents || 0)
}, 0))
const canConfirmSelection = computed(() => confirmRemovesCoupon.value || (selectedCouponIds.value.length
  ? selectedCouponIds.value.every((id) => coupons.value.some((coupon) => couponId(coupon) === id))
    && selectedCouponIds.value.length + Number(props.selector.selectedCountOtherLines || 0) <= orderLimit.value
    && selectedAmountCents.value <= lineCapacityCents.value
  : coupons.value.some((coupon) => couponId(coupon) === selectedCouponId.value)))

function couponId(coupon = {}) {
  return String(coupon.couponId || '')
}

function moneyFromCents(value) {
  const cents = Number(value || 0)
  return `¥${(Number.isFinite(cents) ? cents / 100 : 0).toFixed(2)}`
}

function roundedMoneyFromCents(value) {
  const cents = Number(value || 0)
  return formatMoney(Math.round((Number.isFinite(cents) ? cents : 0) / 100))
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
  // Only the server's usable set can be submitted; threshold hints are display-only.
  if (!canConfirmSelection.value || props.saving) return
  // Clicking an already selected row clears the local choice; confirming it
  // must use the same removal command as the existing footer action.
  if (confirmRemovesCoupon.value) {
    emit('remove')
    return
  }
  const coupon = coupons.value.find((item) => couponId(item) === selectedCouponId.value)
  if (Number(coupon?.couponType) === 3) {
    openAmount(coupon)
    return
  }
  emit('apply', selectedCouponIds.value.length ? selectedCouponIds.value : selectedCouponId.value)
}

// Opening/editing the input never consumes allowance. Cancel leaves the cart unchanged.
function openAmount(coupon) {
  amountCoupon.value = coupon
  amountInput.value = couponId(coupon) === String(props.selector.selectedCouponId || '')
    ? String(Number(props.selector.selectedAmountCents || 0) / 100 || '') : ''
  amountError.value = ''
}

function confirmAmount() {
  if (props.saving) return
  const amount = allowanceAmountCents(amountInput.value, amountCoupon.value, lineCapacityCents.value)
  if (!validAllowanceCents(amount, amountCoupon.value, lineCapacityCents.value)) {
    amountError.value = '请输入正整数元，且不超过券剩余额度和本条明细可抵用金额。'
    return
  }
  emit('apply', { couponId: couponId(amountCoupon.value), amountCents: amount })
}

function chooseCoupon(coupon) {
  if (props.saving) return
  selectionError.value = ''
  const id = couponId(coupon)
  if (Number(coupon.useMode) !== 1) {
    if (Number(coupon.couponType) === 3) {
      if (selectedMode.value === 1) {
        selectionError.value = '整单券和明细券不能混选，请先取消已选整单券。'
        return
      }
      openAmount(coupon)
      return
    }
    if (selectedCouponId.value === id) {
      selectedCouponId.value = ''
      return
    }
    if (selectedMode.value === 1) {
      selectionError.value = '整单券和明细券不能混选，请先取消已选整单券。'
      return
    }
    selectedCouponId.value = id
    selectedCouponIds.value = []
    return
  }
  const current = selectedCouponIds.value
  // A selected row always remains clickable so the operator can free a slot
  // even when the order has reached its coupon limit.
  if (current.includes(id)) {
    selectedCouponIds.value = current.filter((item) => item !== id)
    return
  }
  if (selectedMode.value === 0) {
    selectionError.value = '整单券和明细券不能混选，请先取消已选明细券。'
    return
  }
  if (props.selector.selectedIssueId && Number(coupon.issueId) !== Number(props.selector.selectedIssueId)) {
    selectionError.value = '同一订单只能使用同一发券规则的整单券。'
    return
  }
  const first = coupons.value.find((item) => couponId(item) === current[0])
  if (first && Number(first.issueId) !== Number(coupon.issueId)) {
    selectionError.value = '同一订单只能使用同一发券规则的整单券。'
    return
  }
  const limit = Number(coupon.orderMaxCount || 0)
  if (current.length + Number(props.selector.selectedCountOtherLines || 0) >= limit) {
    selectionError.value = `本单最多可选 ${limit} 张优惠券，继续选择会超过可选数量。请先取消一张。`
    return
  }
  if (selectedAmountCents.value + Number(coupon.discountAmountCents || 0)
    > lineCapacityCents.value) {
    selectionError.value = '本条明细可结算金额不足，无法再选择这张券。'
    return
  }
  selectedCouponIds.value = [...current, id]
  selectedCouponId.value = ''
}
</script>

<template>
  <div class="cashier-coupon-overlay" role="dialog" aria-modal="true" aria-label="选择优惠券" @click.self="emit('close')">
    <section v-if="!amountCoupon" class="cashier-coupon-panel">
      <header>
        <div>
          <strong>选择优惠券</strong>
          <span>{{ selector.lineName || '当前商品' }} · 金额 {{ moneyFromCents(selector.lineAmountCents) }}</span>
        </div>
        <button type="button" aria-label="关闭" title="关闭" :disabled="saving" @click="emit('close')">×</button>
      </header>

      <div v-if="coupons.length || belowMinimumCoupons.length" class="cashier-coupon-list" aria-label="会员优惠券">
        <p v-if="selectionError" class="cashier-coupon-list__error" role="alert">{{ selectionError }}</p>
        <button
          v-for="coupon in coupons"
          :key="couponId(coupon)"
          type="button"
          class="cashier-coupon-item"
          :class="{ 'is-selected': selectedCouponId === couponId(coupon) || selectedCouponIds.includes(couponId(coupon)) }"
          :aria-pressed="selectedCouponId === couponId(coupon) || selectedCouponIds.includes(couponId(coupon))"
          :disabled="saving"
          @click="chooseCoupon(coupon)"
        >
          <span class="cashier-coupon-item__amount">{{ Number(coupon.couponType) === 3 ? '剩余' : '优惠' }} {{ Number(coupon.couponType) === 3 ? roundedMoneyFromCents(coupon.remainingAmountCents) : moneyFromCents(coupon.discountAmountCents) }}</span>
          <span class="cashier-coupon-item__content">
            <strong>{{ coupon.name || '优惠券' }}</strong>
            <small>
              <template v-if="Number(coupon.useMode) === 1">整单门槛 · 本单最多 {{ coupon.orderMaxCount }} 张 · </template>
              <template v-else-if="Number(coupon.useMinAmountCents || 0) > 0">满 {{ moneyFromCents(coupon.useMinAmountCents) }} 可用 · </template>
              {{ expiryText(coupon.expiresAt) }}
            </small>
          </span>
        </button>
        <template v-if="belowMinimumCoupons.length">
          <h3 class="cashier-coupon-list__heading">未达使用门槛</h3>
          <div
            v-for="coupon in belowMinimumCoupons"
            :key="couponId(coupon)"
            class="cashier-coupon-item is-unavailable"
          >
            <span class="cashier-coupon-item__amount">暂不可用</span>
            <span class="cashier-coupon-item__content">
              <strong>{{ coupon.name || '优惠券' }}</strong>
              <small>满 {{ roundedMoneyFromCents(coupon.useMinAmountCents) }} 可用 · 还差 {{ roundedMoneyFromCents(coupon.shortfallCents) }} · {{ expiryText(coupon.expiresAt) }}</small>
            </span>
          </div>
        </template>
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
          <button type="button" class="button button--primary" :disabled="saving || !canConfirmSelection" @click="confirmSelection">
            {{ saving ? '保存中…' : (confirmRemovesCoupon ? '确认不使用' : '确认使用') }}
          </button>
        </div>
      </footer>
    </section>
    <form v-else class="cashier-coupon-panel" aria-label="本次抵用金额" @submit.prevent="confirmAmount">
      <header><strong>本次抵用金额</strong></header>
      <div class="cashier-coupon-amount">
        <strong>{{ amountCoupon.name }}</strong>
        <p>券剩余 {{ roundedMoneyFromCents(amountCoupon.remainingAmountCents) }}</p>
        <p>本条明细最多可抵用 {{ roundedMoneyFromCents(Math.min(amountCoupon.discountAmountCents, lineCapacityCents)) }}</p>
        <label for="coupon-allowance-amount">抵用金额（元）</label>
        <input id="coupon-allowance-amount" v-model="amountInput" type="number" inputmode="numeric" min="1" step="1" :disabled="saving" autofocus />
        <p v-if="amountError" class="cashier-coupon-list__error" role="alert">{{ amountError }}</p>
      </div>
      <footer><span></span><div>
        <button type="button" class="button button--secondary" :disabled="saving" @click="amountCoupon = null">取消</button>
        <button type="submit" class="button button--primary" :disabled="saving">{{ saving ? '保存中…' : '确认抵用' }}</button>
      </div></footer>
    </form>
  </div>
</template>

<style scoped>
.cashier-coupon-amount { padding: 20px; overflow: auto; color: #25333a; }
.cashier-coupon-amount p { font-size: 14px; }
.cashier-coupon-amount label { display: block; margin: 18px 0 8px; }
.cashier-coupon-amount input { box-sizing: border-box; width: 100%; min-height: 40px; border: 1px solid #b9c9d6; border-radius: 4px; padding: 8px 12px; font: inherit; }
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

.cashier-coupon-list__heading { margin: 6px 0 0; color: #4b5961; font-size: 13px; }
.cashier-coupon-list__error { position: sticky; top: 0; z-index: 1; margin: 0; padding: 9px 12px; border: 1px solid #f0c9c9; border-radius: 5px; background: #fff5f5; color: #b72f2f; font-size: 13px; }

.cashier-coupon-item {
  min-height: 76px;
  display: grid;
  grid-template-columns: 116px minmax(0, 1fr);
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 12px 14px;
  border: 1px solid #dfe5e7;
  border-radius: 6px;
  background: #fff;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.cashier-coupon-item.is-selected { border-color: #a9709d; background: #fbf7fa; }
.cashier-coupon-item:focus-visible { outline: 2px solid #2b7dd5; outline-offset: 2px; }
.cashier-coupon-item.is-unavailable { background: #f7f8f9; cursor: default; }
.cashier-coupon-item.is-unavailable .cashier-coupon-item__amount { color: #66757c; }
.cashier-coupon-item__amount { color: #a04f83; font-weight: 700; }
.cashier-coupon-item__content { min-width: 0; display: grid; gap: 5px; }
.cashier-coupon-item__content strong { overflow-wrap: anywhere; color: #25333a; }
.cashier-coupon-item__content small { color: #738087; }
.cashier-coupon-empty { display: grid; place-content: center; gap: 8px; min-height: 180px; text-align: center; color: #738087; }
.cashier-coupon-empty strong { color: #394950; }

@media (max-width: 640px) {
  .cashier-coupon-overlay { align-items: end; padding: 12px; }
  .cashier-coupon-panel { max-height: calc(100vh - 24px); }
  .cashier-coupon-item { grid-template-columns: minmax(0, 1fr); }
  .cashier-coupon-item__amount { grid-column: 1; }
  .cashier-coupon-item__content { grid-column: 1; }
}
</style>
