<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'

const props = defineProps({
  line: { type: Object, required: true },
  projectItems: { type: Array, default: () => [] }
})
const emit = defineEmits(['close'])
const closeButton = ref(null)

// 购物车本次配置优先；若行已由服务端重建，则读取同一行保存的购卡快照。
// 弹窗只展示已确认内容，不回读商品现价，也不修改结账快照。
const configuration = computed(() => props.line.localCustomCardConfiguration || props.line.customCardConfiguration || {})
const purchase = computed(() => props.line.cardPurchaseSnapshot || props.line.authoritySnapshot?.cardPurchase || {})
const projects = computed(() => {
  const configured = configuration.value.components
  const components = Array.isArray(configured) && configured.length ? configured : purchase.value.components || []
  return components.map((component, index) => {
    const skuId = Number(component.skuId || 0)
    const catalogItem = props.projectItems.find((item) => Number(item.id) === skuId)
    return {
      key: `${skuId}-${index}`,
      name: component.nameSnapshot || component.name || catalogItem?.name || `项目 SKU ${skuId}`,
      times: Number(component.times ?? component.writeTimes ?? 0),
      amount: component.amount !== undefined
        ? Number(component.amount)
        : Math.round(Number(component.configuredAmountCents || 0) / 100)
    }
  })
})
const totalAmount = computed(() => projects.value.reduce((total, project) => total + project.amount, 0))
const validityEnd = computed(() => {
  if (configuration.value.validityEnd) return String(configuration.value.validityEnd)
  const seconds = Number(purchase.value.validity?.writeEnd || 0)
  if (!seconds) return '—'
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit'
  }).format(new Date(seconds * 1000))
})
const activateOnPurchase = computed(() =>
  configuration.value.activateOnPurchase ?? purchase.value.activateOnPurchase ?? false
)
const yuan = (amount) => `¥${new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 0 }).format(amount)}`

onMounted(() => nextTick(() => closeButton.value?.focus()))
</script>

<template>
  <Teleport to="body">
    <div class="custom-card-cart-detail__backdrop" @click.self="emit('close')">
      <section class="custom-card-cart-detail" role="dialog" aria-modal="true" aria-labelledby="custom-card-cart-detail-title" @keydown.esc.stop="emit('close')">
        <header>
          <div>
            <small>本次购买 · 定制卡</small>
            <h2 id="custom-card-cart-detail-title">{{ line.name }}</h2>
          </div>
          <button ref="closeButton" type="button" class="custom-card-cart-detail__close" aria-label="关闭定制卡详情" @click="emit('close')">×</button>
        </header>
        <dl class="custom-card-cart-detail__summary">
          <div><dt>有效期至</dt><dd>{{ validityEnd }}</dd></div>
          <div><dt>开卡方式</dt><dd>{{ activateOnPurchase ? '购卡后立即开卡' : '暂不开卡' }}</dd></div>
          <div><dt>卡内项目</dt><dd>{{ projects.length }} 项</dd></div>
          <div><dt>设定金额合计</dt><dd>{{ yuan(totalAmount) }}</dd></div>
        </dl>
        <div class="custom-card-cart-detail__projects">
          <table v-if="projects.length">
            <thead><tr><th scope="col">项目</th><th scope="col">次数</th><th scope="col">设定金额</th></tr></thead>
            <tbody>
              <tr v-for="project in projects" :key="project.key">
                <td>{{ project.name }}</td><td>{{ project.times }}</td><td>{{ yuan(project.amount) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else>当前购物车未返回卡内项目明细。</p>
        </div>
        <footer><button type="button" class="custom-card-cart-detail__done" @click="emit('close')">关闭</button></footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.custom-card-cart-detail__backdrop { position: fixed; z-index: 1500; inset: 0; display: grid; place-items: center; padding: 20px; background: rgba(20, 30, 48, .5); }
.custom-card-cart-detail { box-sizing: border-box; width: min(560px, 100%); max-height: min(720px, calc(100dvh - 40px)); overflow: auto; border-radius: 10px; background: #fff; box-shadow: 0 20px 55px rgba(20, 30, 48, .25); }
.custom-card-cart-detail header { display: flex; align-items: start; justify-content: space-between; gap: 16px; padding: 20px 24px 14px; border-bottom: 1px solid #e8edf4; }
.custom-card-cart-detail header small { color: #7b8797; font-size: 12px; }
.custom-card-cart-detail h2 { margin: 4px 0 0; font-size: 20px; overflow-wrap: anywhere; }
.custom-card-cart-detail__close { border: 0; background: transparent; color: #6b7788; font-size: 28px; line-height: 1; cursor: pointer; }
.custom-card-cart-detail__summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin: 0; padding: 18px 24px; }
.custom-card-cart-detail__summary > div { min-width: 0; padding: 10px 12px; border-radius: 6px; background: #f5f8fc; }
.custom-card-cart-detail__summary dt { color: #748196; font-size: 12px; }
.custom-card-cart-detail__summary dd { margin: 6px 0 0; color: #26354b; font-size: 14px; font-weight: 700; overflow-wrap: anywhere; }
.custom-card-cart-detail__projects { max-height: 300px; overflow: auto; padding: 0 24px; }
.custom-card-cart-detail__projects table { width: 100%; border-collapse: collapse; text-align: left; }
.custom-card-cart-detail__projects th, .custom-card-cart-detail__projects td { padding: 11px 8px; border-bottom: 1px solid #e9edf2; font-size: 13px; }
.custom-card-cart-detail__projects th { color: #66758a; background: #f8fafc; }
.custom-card-cart-detail__projects th:nth-child(n+2), .custom-card-cart-detail__projects td:nth-child(n+2) { text-align: right; white-space: nowrap; }
.custom-card-cart-detail__projects p { color: #7b8797; font-size: 13px; }
.custom-card-cart-detail footer { display: flex; justify-content: flex-end; padding: 16px 24px 20px; }
.custom-card-cart-detail__done { min-height: 34px; padding: 0 20px; border: 0; border-radius: 6px; background: #1769cf; color: #fff; cursor: pointer; }
@media (max-width: 480px) { .custom-card-cart-detail__summary { grid-template-columns: 1fr; } .custom-card-cart-detail header, .custom-card-cart-detail__summary, .custom-card-cart-detail__projects, .custom-card-cart-detail footer { padding-left: 16px; padding-right: 16px; } }
</style>
