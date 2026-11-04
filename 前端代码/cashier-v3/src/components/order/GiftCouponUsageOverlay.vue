<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import TablePagination from '@/components/common/TablePagination.vue'
import { formatMoney } from '@/services/cashierV3Bridge'

// 优惠券逐张实例、项目逐笔核销共用弹窗壳和分页；列只呈现后端授权的事实结果。
const props = defineProps({ recordId: { type: String, required: true }, usageType: { type: String, default: 'coupon' }, loadUsage: { type: Function, required: true } })
const emit = defineEmits(['close'])
const rows = ref([])
const total = ref(0)
const page = ref(1)
const giftRecordNo = ref('')
const projectName = ref('')
const issuedQuantity = ref(0)
const usedQuantity = ref(0)
const remainingQuantity = ref(0)
const unrecordedQuantity = ref(0)
const usageKnown = ref(true)
const availabilityStatus = ref('')
const loading = ref(false)
const error = ref('')
const closeButton = ref(null)
let sequence = 0
let previousFocus = null

// Each open/page has a unique read token. A late response from a closed or different gift must never replace this dialog.
async function readPage(nextPage = 1) {
  const token = ++sequence
  const recordId = props.recordId
  loading.value = true
  error.value = ''
  rows.value = []
  try {
    const response = await props.loadUsage({ recordId, page: nextPage })
    if (token !== sequence || recordId !== props.recordId) return
    const status = response?.result?.status || response?.data?.result?.status
    const detailKey = props.usageType === 'project' ? 'giftProjectUsage' : 'giftCouponUsage'
    const detail = response?.data?.[detailKey] || response?.data?.data?.[detailKey]
    if (!['success', 'succeeded'].includes(status) || !detail || detail.recordId !== recordId) {
      throw new Error(response?.result?.message || '使用情况读取失败，请重试。')
    }
    rows.value = Array.isArray(detail.records) ? detail.records : []
    total.value = Number(detail.total) || 0
    page.value = Number(detail.page) || 1
    giftRecordNo.value = detail.giftRecordNo || ''
    projectName.value = detail.projectName || ''
    issuedQuantity.value = Number(detail.issuedQuantity) || 0
    usedQuantity.value = detail.usedQuantity == null ? null : Number(detail.usedQuantity)
    remainingQuantity.value = Number(detail.remainingQuantity) || 0
    unrecordedQuantity.value = Number(detail.unrecordedQuantity) || 0
    usageKnown.value = detail.usageKnown !== false
    availabilityStatus.value = detail.availabilityStatus || ''
  } catch (failure) {
    if (token === sequence) error.value = failure?.message || '使用情况读取失败，请重试。'
  } finally {
    if (token === sequence) loading.value = false
  }
}
function onKeydown(event) {
  if (event.key === 'Escape') emit('close')
}
watch(() => [props.recordId, props.usageType], () => {
  total.value = 0
  page.value = 1
  giftRecordNo.value = ''
  projectName.value = ''
  unrecordedQuantity.value = 0
  readPage(1)
}, { immediate: true })
onMounted(async () => {
  previousFocus = document.activeElement
  document.addEventListener('keydown', onKeydown)
  await nextTick()
  closeButton.value?.focus()
})
onBeforeUnmount(() => {
  sequence++
  document.removeEventListener('keydown', onKeydown)
  if (previousFocus?.isConnected) previousFocus.focus()
})
</script>

<template>
  <div class="gift-coupon-usage-backdrop" @click.self="emit('close')">
    <section class="gift-coupon-usage" role="dialog" aria-modal="true" aria-labelledby="gift-coupon-usage-title">
      <header>
        <div><h2 id="gift-coupon-usage-title">使用情况</h2><p v-if="giftRecordNo">赠送记录：{{ giftRecordNo }}</p></div>
        <button ref="closeButton" type="button" class="button button--secondary" @click="emit('close')">关闭</button>
      </header>
      <div class="gift-coupon-usage__scroll" :aria-busy="loading">
        <p v-if="loading" class="gift-coupon-usage__message" role="status">正在读取使用情况…</p>
        <div v-else-if="error" class="gift-coupon-usage__message" role="alert">
          {{ error }} <button type="button" class="button button--text" @click="readPage(page)">重试</button>
        </div>
        <div v-else-if="props.usageType === 'project'">
          <div class="gift-coupon-usage__summary" role="status">
            <strong>{{ projectName || '赠送项目' }}</strong><span>{{ availabilityStatus }}</span>
            <span>赠送 {{ issuedQuantity }} 次</span><span>已使用 {{ usageKnown ? `${usedQuantity} 次` : '待核对' }}</span><span>当前余次 {{ remainingQuantity }} 次</span>
          </div>
          <p v-if="!usageKnown" class="gift-coupon-usage__message">该历史赠送已作废，原余次不能反推使用次数；逐笔使用记录待核对。</p>
          <p v-if="unrecordedQuantity > 0" class="gift-coupon-usage__message">其中 {{ unrecordedQuantity }} 次只有历史余次记录，缺少逐笔核销资料，使用时间与单号待核对。</p>
          <table>
            <thead><tr><th>使用时间</th><th>服务记录</th><th>核销次数</th><th>已恢复次数</th><th>使用情况</th></tr></thead>
            <tbody>
              <tr v-for="row in rows" :key="row.usageId">
                <td>{{ row.usedAt || '—' }}</td><td>{{ row.serviceRecordNo || '—' }}</td>
                <td>{{ row.quantity }}</td><td>{{ row.restoredQuantity }}</td><td>{{ row.usageStatus }}</td>
              </tr>
              <tr v-if="!rows.length"><td colspan="5">暂无逐笔核销记录。</td></tr>
            </tbody>
          </table>
        </div>
        <table v-else>
          <thead><tr><th>券号</th><th>优惠券</th><th>使用情况</th><th>使用时间</th><th>关联订单</th><th>剩余额度</th></tr></thead>
          <tbody>
            <tr v-for="row in rows" :key="row.couponNo">
              <td>{{ row.couponNo }}</td><td>{{ row.couponName }}</td>
              <td><span>{{ row.usageStatus }}</span><small v-if="row.availabilityStatus !== '有效'">{{ row.availabilityStatus }}</small></td>
              <td>{{ row.usedAt || '—' }}</td><td>{{ row.orderNo || '—' }}</td>
              <td>{{ row.remainingAmount === null || row.remainingAmount === undefined ? '—' : formatMoney(row.remainingAmount) }}</td>
            </tr>
            <tr v-if="!rows.length"><td colspan="6">暂无可查看的优惠券。</td></tr>
          </tbody>
        </table>
      </div>
      <TablePagination :total="total" :page="page" :page-size="20" :page-size-options="[20]" show-page-count
        @change="({ page: nextPage }) => readPage(nextPage)" />
    </section>
  </div>
</template>

<style scoped>
.gift-coupon-usage-backdrop { position: fixed; inset: 0; z-index: 1800; display: flex; align-items: center; justify-content: center; background: rgb(15 23 42 / 38%); padding: 24px; }
.gift-coupon-usage { display: flex; flex-direction: column; width: min(1060px, 100%); max-height: min(760px, 88vh); min-height: 260px; overflow: hidden; background: #fff; border-radius: 10px; color: #24364e; }
header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #e4ebf3; }
h2 { margin: 0; font-size: 17px; } p { margin: 6px 0 0; font-size: 12px; color: #65748b; }
.gift-coupon-usage__scroll { min-height: 0; overflow: auto; flex: 1; }
table { width: 100%; min-width: 850px; border-collapse: collapse; font-size: 13px; }
th { position: sticky; top: 0; background: #f2f6fb; font-weight: 600; white-space: nowrap; }
th, td { text-align: left; padding: 13px 16px; border-bottom: 1px solid #edf1f6; }
td:first-child { white-space: nowrap; } small { display: block; color: #8a6472; margin-top: 4px; }
.gift-coupon-usage__message { padding: 28px 20px; color: #65748b; }
.gift-coupon-usage__summary { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; padding: 15px 20px; border-bottom: 1px solid #edf1f6; }
.gift-coupon-usage__summary strong { margin-right: 8px; }
@media (max-width: 600px) { .gift-coupon-usage-backdrop { padding: 10px; } }
</style>
