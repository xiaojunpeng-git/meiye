<script setup>
// 只承载购物车行操作的呈现；数量、备注和删除仍由父级原有命令处理。
// 合计由父级按草稿行口径传入；权益分摊尾差不能用展示单价反推。
defineProps({
  line: { type: Object, required: true },
  entitlement: { type: Boolean, default: false },
  upgradeBound: { type: Boolean, default: false },
  showQuantity: { type: Boolean, default: true },
  formattedTotal: { type: String, default: '' }
})

defineEmits(['step', 'quantity-change', 'focus', 'remark', 'remove'])
</script>

<template>
  <div class="cart-line__right-controls" :class="{ 'cart-line__right-controls--with-total': showQuantity && Number(line.quantity || 1) > 1 }">
    <div v-if="showQuantity" class="cart-line__quantity quantity-stepper quantity-stepper--editable" aria-label="数量操作">
      <button
        type="button"
        aria-label="减少数量"
        :disabled="upgradeBound || Number(line.quantity || 1) <= 1"
        @click="$emit('step', -1)"
      >−</button>
      <input
        :value="line.quantity || 1"
        type="number"
        min="1"
        inputmode="numeric"
        :disabled="upgradeBound"
        :aria-label="entitlement ? '本次使用次数' : '商品数量'"
        @focus="$emit('focus')"
        @change="$emit('quantity-change', $event)"
      >
      <button
        type="button"
        aria-label="增加数量"
        :disabled="upgradeBound"
        @click="$emit('step', 1)"
      >+</button>
    </div>
    <span v-if="showQuantity && Number(line.quantity || 1) > 1" class="cart-line__quantity-total" :title="`合计 ${formattedTotal}`">合计 {{ formattedTotal }}</span>
    <button
      type="button"
      class="cart-line__detail-remark"
      :aria-label="`${line.name || '当前明细'}明细备注`"
      :title="String(line.detailRemark || '').trim() || '明细备注'"
      @click="$emit('remark')"
    >
      <template v-if="String(line.detailRemark || '').trim()">备注：<span class="cart-line__detail-remark-value">{{ String(line.detailRemark).trim() }}</span></template>
      <template v-else>明细备注</template>
    </button>
    <button
      type="button"
      class="cart-line__delete"
      :aria-label="`删除${line.name}`"
      title="删除"
      @click.stop="$emit('remove')"
    >删除</button>
  </div>
</template>
