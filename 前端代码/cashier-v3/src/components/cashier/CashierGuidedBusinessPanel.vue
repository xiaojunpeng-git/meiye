<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  mode: { type: String, required: true },
  catalogItems: { type: Array, default: () => [] },
  submitting: { type: Boolean, default: false }
})
const emit = defineEmits(['close', 'confirm'])

const step = ref(1)
const cardName = ref('')
const expiryDate = ref(new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10))
const openImmediately = ref(true)
const giftScope = ref('当前门店')
const reason = ref('客户关怀')
const activeGiftType = ref('项目')
const selectedGiftIds = ref(['gift-project-1'])
const selectedCustomIds = ref([])
const customError = ref('')
const customKeyword = ref('')

const giftOptions = [
  { id: 'gift-project-1', type: '项目', name: '水光护理', quantity: 1 },
  { id: 'gift-project-2', type: '项目', name: '肩颈舒缓', quantity: 2 },
  { id: 'gift-product-1', type: '产品', name: '修护精华体验装', quantity: 1 },
  { id: 'gift-coupon-1', type: '优惠券', name: '护理抵扣券 100 元', quantity: 1 }
]
const customOptions = ref([])

const isGift = computed(() => props.mode === 'gift')
const title = computed(() => isGift.value ? '办理赠送' : '新建定制卡')
const steps = computed(() => isGift.value
  ? ['选择赠送内容', '设置使用规则', '确认赠送']
  : ['卡片信息', '选择卡内内容', '确认定制卡'])
const visibleGiftOptions = computed(() => giftOptions.filter((item) => item.type === activeGiftType.value))
const selectedGifts = computed(() => giftOptions.filter((item) => selectedGiftIds.value.includes(item.id)))
const selectedCustomItems = computed(() => customOptions.value.filter((item) => selectedCustomIds.value.includes(item.id)))
const customTotal = computed(() => selectedCustomItems.value.reduce((sum, item) => sum + Number(item.amount || 0), 0))
const visibleCustomOptions = computed(() => {
  const normalizedKeyword = customKeyword.value.trim().toLocaleLowerCase()
  if (!normalizedKeyword) return customOptions.value
  return customOptions.value.filter((item) => [item.name, item.specification]
    .filter(Boolean)
    .some((value) => String(value).toLocaleLowerCase().includes(normalizedKeyword)))
})

watch(() => props.catalogItems, (items) => {
  const existing = new Map(customOptions.value.map((item) => [String(item.id), item]))
  customOptions.value = (Array.isArray(items) ? items : [])
    .filter((item) => item?.kind === '项目' && !item.disabled)
    .map((item) => {
      const previous = existing.get(String(item.id))
      return {
        id: item.id,
        name: item.name,
        specification: item.specification || '',
        times: previous?.times || 1,
        amount: previous?.amount ?? Number(item.price || 0)
      }
    })
  selectedCustomIds.value = selectedCustomIds.value.filter((id) => customOptions.value.some((item) => String(item.id) === String(id)))
}, { immediate: true, deep: true })

function toggleGift(id) {
  selectedGiftIds.value = selectedGiftIds.value.includes(id)
    ? selectedGiftIds.value.filter((item) => item !== id)
    : [...selectedGiftIds.value, id]
}

function toggleCustom(id) {
  selectedCustomIds.value = selectedCustomIds.value.includes(id)
    ? selectedCustomIds.value.filter((item) => item !== id)
    : [...selectedCustomIds.value, id]
}

function nextStep() {
  customError.value = ''
  if (step.value < 3) step.value += 1
}

function confirm() {
  if (!isGift.value) {
    if (!cardName.value.trim()) {
      customError.value = '请填写卡片名称'
      return
    }
    if (!selectedCustomItems.value.length) {
      customError.value = '请至少选择一项卡内项目'
      return
    }
    if (!expiryDate.value || new Date(`${expiryDate.value}T23:59:59+08:00`).getTime() <= Date.now()) {
      customError.value = '请选择晚于今天的有效期'
      return
    }
    const components = selectedCustomItems.value.map((item) => ({
      skuId: item.id,
      times: Number(item.times),
      amount: String(Number(item.amount || 0))
    }))
    if (components.some((item) => !Number.isInteger(item.times) || item.times <= 0 || !/^\d+$/.test(item.amount))) {
      customError.value = '每个项目的次数和金额都必须为整数'
      return
    }
    if (customTotal.value <= 0) {
      customError.value = '定制卡合计金额必须大于 0'
      return
    }
    emit('confirm', {
      mode: props.mode,
      title: cardName.value.trim(),
      itemCount: components.length,
      amount: customTotal.value,
      payload: {
        cardName: cardName.value.trim(),
        validityEnd: expiryDate.value,
        activateOnPurchase: openImmediately.value,
        components
      }
    })
    return
  }
  emit('confirm', {
    mode: props.mode,
    title: isGift.value ? '赠送' : cardName.value,
    itemCount: isGift.value ? selectedGifts.value.length : selectedCustomItems.value.length,
    amount: isGift.value ? 0 : customTotal.value
  })
}
</script>

<template>
  <section class="cashier-guided-panel" :aria-label="title">
    <header class="cashier-guided-panel__header">
      <div><strong>{{ title }}</strong><span>{{ isGift ? '独立赠送，不加入当前销售订单' : '数量固定为 1' }}</span></div>
      <button type="button" class="button button--secondary" @click="emit('close')">返回收银</button>
    </header>

    <nav class="cashier-guided-panel__steps" aria-label="办理步骤">
      <button v-for="(label, index) in steps" :key="label" type="button" :class="{ 'is-active': step === index + 1, 'is-done': step > index + 1 }" @click="step = index + 1">
        <span>{{ index + 1 }}</span>{{ label }}
      </button>
    </nav>

    <div class="cashier-guided-panel__body">
      <template v-if="isGift">
        <section v-if="step === 1" class="cashier-guided-panel__section">
          <div class="cashier-guided-panel__tabs">
            <button v-for="type in ['项目', '产品', '优惠券']" :key="type" type="button" :class="{ 'is-active': activeGiftType === type }" @click="activeGiftType = type">{{ type }}</button>
          </div>
          <button v-for="item in visibleGiftOptions" :key="item.id" type="button" class="cashier-guided-option" :class="{ 'is-selected': selectedGiftIds.includes(item.id) }" @click="toggleGift(item.id)">
            <span><strong>{{ item.name }}</strong><small>{{ item.type }}</small></span><span>{{ selectedGiftIds.includes(item.id) ? '已选择' : '选择' }}</span>
          </button>
        </section>
        <section v-else-if="step === 2" class="cashier-guided-panel__section cashier-guided-panel__form">
          <label>使用范围<select v-model="giftScope"><option>当前门店</option><option>全部适用门店</option></select></label>
          <label>有效期<input v-model="expiryDate" type="date"></label>
          <label class="cashier-guided-panel__wide">赠送原因<textarea v-model="reason" rows="3" placeholder="请填写赠送原因"></textarea></label>
        </section>
        <section v-else class="cashier-guided-panel__section cashier-guided-summary">
          <div><span>赠送会员</span><strong>当前办理会员</strong></div>
          <div><span>赠送内容</span><strong>{{ selectedGifts.map(item => item.name).join('、') || '未选择' }}</strong></div>
          <div><span>使用范围</span><strong>{{ giftScope }}</strong></div>
          <div><span>有效期</span><strong>{{ expiryDate }}</strong></div>
          <div><span>赠送原因</span><strong>{{ reason }}</strong></div>
        </section>
      </template>

      <template v-else>
        <section v-if="step === 1" class="cashier-guided-panel__section cashier-guided-panel__form">
          <label>卡片名称<input v-model="cardName" type="text"></label>
          <label>有效期<input v-model="expiryDate" type="date"></label>
          <label class="cashier-guided-switch"><input v-model="openImmediately" type="checkbox"><span>购卡后立即开卡</span></label>
        </section>
        <section v-else-if="step === 2" class="cashier-guided-panel__section">
          <label class="cashier-guided-panel__search">
            <span class="sr-only">搜索卡内项目</span>
            <input v-model="customKeyword" type="search" placeholder="搜索项目名称或规格">
          </label>
          <p v-if="!customOptions.length" class="cashier-guided-panel__empty">当前门店没有可配置的在售项目。</p>
          <p v-else-if="!visibleCustomOptions.length" class="cashier-guided-panel__empty">未找到匹配的在售项目。</p>
          <article v-for="item in visibleCustomOptions" :key="item.id" class="cashier-custom-option" :class="{ 'is-selected': selectedCustomIds.includes(item.id) }">
            <label><input type="checkbox" :checked="selectedCustomIds.includes(item.id)" @change="toggleCustom(item.id)"><strong>{{ item.name }}</strong><small v-if="item.specification">{{ item.specification }}</small></label>
            <label>次数<input v-model.number="item.times" type="number" min="1" step="1"></label>
            <label>设置金额<input v-model.number="item.amount" type="number" min="0" step="1" inputmode="numeric"></label>
          </article>
        </section>
        <section v-else class="cashier-guided-panel__section cashier-guided-summary">
          <div><span>卡片名称</span><strong>{{ cardName }}</strong></div>
          <div><span>卡内项目</span><strong>{{ selectedCustomItems.length }} 项</strong></div>
          <div><span>合计金额</span><strong>¥{{ customTotal }}</strong></div>
          <div><span>有效期</span><strong>{{ expiryDate }}</strong></div>
          <div><span>开卡方式</span><strong>{{ openImmediately ? '购卡后立即开卡' : '暂不开卡' }}</strong></div>
        </section>
      </template>
    </div>
    <p v-if="customError" class="cashier-guided-panel__error" role="alert">{{ customError }}</p>

    <footer class="cashier-guided-panel__footer">
      <button type="button" class="button button--secondary" :disabled="step === 1" @click="step -= 1">上一步</button>
      <button v-if="step < 3" type="button" class="button button--primary" @click="nextStep">下一步</button>
      <button v-else type="button" class="button button--primary" :disabled="submitting" @click="confirm">{{ submitting ? '正在保存' : (isGift ? '确认赠送' : '加入购物车') }}</button>
    </footer>
  </section>
</template>
