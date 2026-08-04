<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  session: { type: Object, default: () => ({}) },
  submitting: { type: Boolean, default: false }
})
const emit = defineEmits(['close', 'submit'])

const activeKind = ref('project')
const selected = ref([])
const reason = ref('')
const validityEnd = ref('')
const validationMessage = ref('')

const member = computed(() => props.session.member || {})
// Direct-gift commands and their resource versions are keyed by the member's
// authoritative user id. Display-oriented member ids must never win here.
const memberId = computed(() => member.value.uid || member.value.memberId || member.value.id || '')
const memberName = computed(() => member.value.name || member.value.realName || member.value.real_name || member.value.nickname || '会员')
const handlingStore = computed(() => props.session.store || {})
const handlingStoreId = computed(() => handlingStore.value.id || handlingStore.value.storeId || '')
const handlingStoreName = computed(() => handlingStore.value.name || handlingStore.value.storeName || '当前门店')
const catalogItems = computed(() => Array.isArray(props.session.catalogItems) ? props.session.catalogItems : [])
const coupons = computed(() => Array.isArray(props.session.coupons) ? props.session.coupons : [])
const items = computed(() => {
  const productItems = catalogItems.value
    .filter((item) => !item.disabled && (Number(item.productType) === 6 || Number(item.productType) === 0))
    .map((item) => ({
      key: `catalog:${item.catalogItemId || item.skuId || item.id}`,
      kind: Number(item.productType) === 6 ? 'project' : 'product',
      catalogItemId: String(item.catalogItemId || item.skuId || item.id),
      name: item.name || '未命名品项',
      specification: item.specification || ''
    }))
  const couponItems = coupons.value.map((item) => ({
    key: `coupon:${item.id}`,
    kind: 'coupon',
    couponIssueId: String(item.id),
    name: item.name || item.title || '未命名优惠券',
    specification: item.applicableStoreLabel || item.description || ''
  }))
  return [...productItems, ...couponItems]
})
const visibleItems = computed(() => items.value.filter((item) => item.kind === activeKind.value))
// "已选 N 项" describes selected gift contents, not the editable quantity
// total. Keeping it structural also prevents malformed input from rendering
// a meaningless NaN before the submission validator explains the issue.
const selectedCount = computed(() => selected.value.length)

watch(() => props.session, () => {
  activeKind.value = 'project'
  selected.value = []
  reason.value = ''
  validityEnd.value = ''
  validationMessage.value = ''
}, { immediate: true, deep: true })

function selectedItem(item) {
  return selected.value.find((entry) => entry.key === item.key) || null
}

function toggle(item) {
  const existing = selectedItem(item)
  if (existing) {
    selected.value = selected.value.filter((entry) => entry.key !== item.key)
    return
  }
  selected.value.push({ ...item, quantity: 1 })
}

function isPositiveInteger(value) {
  return /^(?:[1-9]\d{0,2})$/.test(String(value ?? '').trim())
}

function submit() {
  if (!memberId.value) validationMessage.value = '会员信息已失效，请重新选择会员。'
  else if (!selected.value.length) validationMessage.value = '请至少选择一项赠送内容。'
  else if (selected.value.some((item) => !isPositiveInteger(item.quantity))) validationMessage.value = '每项赠送数量必须是 1 到 999 的整数。'
  else if (!reason.value.trim()) validationMessage.value = '请填写赠送原因。'
  else if (reason.value.trim().length > 120) validationMessage.value = '赠送原因不能超过 120 个字符。'
  else if (validityEnd.value && !/^\d{4}-\d{2}-\d{2}$/.test(validityEnd.value)) validationMessage.value = '有效期格式无效。'
  else validationMessage.value = ''
  if (validationMessage.value) return
  emit('submit', {
    memberId: memberId.value,
    selectorEntry: 'cashier',
    handlingStoreId: handlingStoreId.value,
    reason: reason.value.trim(),
    validityEnd: validityEnd.value || '',
    items: selected.value.map((item) => ({
      kind: item.kind,
      catalogItemId: item.catalogItemId,
      couponIssueId: item.couponIssueId,
      quantity: Number(item.quantity)
    }))
  })
}
</script>

<template>
  <Teleport to="body">
    <section class="direct-gift-overlay" role="presentation" @click.self="!submitting && emit('close')">
      <form class="direct-gift-overlay__dialog" role="dialog" aria-modal="true" aria-label="办理赠送" @submit.prevent="submit">
        <header>
          <div><span>独立赠送</span><h2>{{ memberName }}</h2></div>
          <button type="button" class="button button--secondary" :disabled="submitting" @click="emit('close')">关闭</button>
        </header>
        <p class="direct-gift-overlay__notice">赠送内容不加入销售订单，不产生收款或业绩。</p>
        <p class="direct-gift-overlay__store">办理门店：{{ handlingStoreName }}</p>
        <nav class="direct-gift-overlay__tabs" aria-label="赠送内容类型">
          <button v-for="[kind, label] in [['project', '项目'], ['product', '产品'], ['coupon', '优惠券']]" :key="kind" type="button" :class="{ 'is-active': activeKind === kind }" @click="activeKind = kind">{{ label }}</button>
        </nav>
        <section class="direct-gift-overlay__catalog" aria-label="可赠送内容">
          <p v-if="!visibleItems.length" class="direct-gift-overlay__empty">当前门店没有可赠送的{{ activeKind === 'project' ? '项目' : activeKind === 'product' ? '产品' : '优惠券' }}。</p>
          <button v-for="item in visibleItems" :key="item.key" type="button" class="direct-gift-overlay__item" :class="{ 'is-selected': selectedItem(item) }" @click="toggle(item)">
            <span><strong>{{ item.name }}</strong><small v-if="item.specification">{{ item.specification }}</small></span>
            <span>{{ selectedItem(item) ? '已选择' : '选择' }}</span>
          </button>
        </section>
        <section v-if="selected.length" class="direct-gift-overlay__selected" aria-label="已选赠送内容">
          <label v-for="item in selected" :key="item.key"><span>{{ item.name }}</span><input v-model="item.quantity" inputmode="numeric" autocomplete="off" aria-label="赠送数量"><button type="button" class="button button--text" @click="toggle(item)">移除</button></label>
        </section>
        <section class="direct-gift-overlay__fields">
          <label>赠送原因<textarea v-model="reason" maxlength="120" rows="2" placeholder="请填写赠送原因"></textarea></label>
          <label>有效期（选填）<input v-model="validityEnd" type="date"></label>
        </section>
        <p v-if="validationMessage" class="direct-gift-overlay__error" role="alert">{{ validationMessage }}</p>
        <footer><span>已选 {{ selectedCount }} 项</span><div><button type="button" class="button button--secondary" :disabled="submitting" @click="emit('close')">取消</button><button type="submit" class="button button--primary" :disabled="submitting">{{ submitting ? '正在提交' : '确认赠送' }}</button></div></footer>
      </form>
    </section>
  </Teleport>
</template>

<style scoped>
.direct-gift-overlay { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 24px; background: rgba(21,31,47,.46); }
.direct-gift-overlay__dialog { width: min(680px,100%); max-height: min(760px,calc(100vh - 48px)); overflow: auto; border: 1px solid #dfe5ec; border-radius: 8px; background: #fff; box-shadow: 0 20px 56px rgba(18,34,54,.24); }
.direct-gift-overlay__dialog > header, .direct-gift-overlay__dialog > footer { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 24px; }
.direct-gift-overlay__dialog > header { border-bottom: 1px solid #edf0f4; }.direct-gift-overlay__dialog h2 { margin: 3px 0 0; font-size: 20px; }.direct-gift-overlay__dialog header span { color: #7b8798; font-size: 13px; }
.direct-gift-overlay__notice { margin: 18px 24px 0; color: #5c6a7e; font-size: 13px; }.direct-gift-overlay__tabs { display: flex; gap: 8px; margin: 18px 24px 0; border-bottom: 1px solid #edf0f4; }.direct-gift-overlay__tabs button { padding: 8px 12px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: #5c6a7e; cursor: pointer; }.direct-gift-overlay__tabs button.is-active { border-bottom-color: #2981e5; color: #175fb3; font-weight: 600; }
.direct-gift-overlay__catalog { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 8px; padding: 16px 24px; }.direct-gift-overlay__item { display: flex; min-width: 0; justify-content: space-between; gap: 12px; padding: 11px; border: 1px solid #cfd7e3; border-radius: 6px; background: #fff; color: #3f4c5c; text-align: left; cursor: pointer; }.direct-gift-overlay__item.is-selected { border-color: #2981e5; background: #f3f8ff; }.direct-gift-overlay__item strong, .direct-gift-overlay__item small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.direct-gift-overlay__item small { margin-top: 3px; color: #7b8798; font-size: 12px; }.direct-gift-overlay__empty { grid-column: 1 / -1; margin: 0; color: #7b8798; }
.direct-gift-overlay__selected { display: grid; gap: 8px; margin: 0 24px; padding-top: 14px; border-top: 1px solid #edf0f4; }.direct-gift-overlay__selected label { display: grid; grid-template-columns: minmax(0,1fr) 96px auto; align-items: center; gap: 10px; }.direct-gift-overlay__selected input, .direct-gift-overlay__fields input, .direct-gift-overlay__fields textarea { width: 100%; box-sizing: border-box; border: 1px solid #cfd7e3; border-radius: 5px; padding: 8px 10px; color: #202b3a; }.direct-gift-overlay__fields { display: grid; grid-template-columns: minmax(0,2fr) minmax(160px,1fr); gap: 14px; margin: 20px 24px; }.direct-gift-overlay__fields label { display: grid; gap: 7px; color: #3f4c5c; font-size: 14px; }.direct-gift-overlay__error { margin: 0 24px 16px; color: #c13737; font-size: 13px; }.direct-gift-overlay__dialog > footer { border-top: 1px solid #edf0f4; }.direct-gift-overlay__dialog > footer > span { color: #5c6a7e; font-size: 13px; }.direct-gift-overlay__dialog > footer > div { display: flex; gap: 10px; }
@media (max-width: 720px) { .direct-gift-overlay { padding: 12px; }.direct-gift-overlay__catalog, .direct-gift-overlay__fields { grid-template-columns: 1fr; }.direct-gift-overlay__dialog > header, .direct-gift-overlay__dialog > footer { padding: 16px; }.direct-gift-overlay__catalog, .direct-gift-overlay__tabs, .direct-gift-overlay__notice { margin-left: 16px; margin-right: 16px; }.direct-gift-overlay__selected, .direct-gift-overlay__fields { margin-left: 16px; margin-right: 16px; } }
</style>
