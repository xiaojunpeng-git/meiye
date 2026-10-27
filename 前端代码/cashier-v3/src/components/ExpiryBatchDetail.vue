<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { formatMoney } from '@/services/cashierV3Bridge'
import { queryProductDashboard } from '@/services/productDashboardApi'
const props = defineProps({ params: { type: Object, required: true }, storeIds: { type: Array, default: () => [] } })
const emit = defineEmits(['close'])
const data = ref(null), loading = ref(false), error = ref(''), closeButton = ref(null)
let requestId = 0, previousFocus = null
// 只提交服务端下钻身份与已提交范围；每次翻页仍由后端重新强制权限并返回完整合计。
async function load(page = 1) {
  const id = ++requestId
  loading.value = true; error.value = ''
  try {
    const result = await queryProductDashboard({ ...props.params, store_ids: props.storeIds.join(','), page })
    if (id === requestId) data.value = result
  } catch (e) { if (id === requestId) { data.value = null; error.value = e.message || '批次明细读取失败' } }
  finally { if (id === requestId) loading.value = false }
}
function money(cents) { return cents === null || cents === undefined ? '-' : formatMoney(Math.round(Number(cents) / 100)) }
function onKey(event) {
  if (event.key === 'Escape') emit('close')
  // 弹窗键盘循环，关闭后恢复触发按钮，避免焦点落到遮罩后的看板。
  if (event.key === 'Tab') {
    const elements = [...event.currentTarget.querySelectorAll('button:not(:disabled)')]
    const first = elements[0], last = elements[elements.length - 1]
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
  }
}
onMounted(() => { previousFocus = document.activeElement; closeButton.value?.focus(); load() })
onBeforeUnmount(() => { ++requestId; previousFocus?.focus() })
</script>
<template>
  <div class="expiry-detail-backdrop" @click.self="emit('close')">
    <section class="expiry-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="expiry-detail-title" @keydown="onKey">
      <header><h2 id="expiry-detail-title">批次库存明细</h2><button ref="closeButton" type="button" aria-label="关闭批次明细" @click="emit('close')">关闭</button></header>
      <p v-if="error" class="expiry-detail-error" role="alert">{{ error }}<button @click="load()">重试</button></p>
      <p v-if="loading" role="status">正在读取批次明细...</p>
      <template v-if="data">
        <div class="expiry-detail-summary"><strong>{{ data.batch.product_name }}</strong><span>门店：{{ data.store_name || '未记录门店名称' }}</span><span>批次：{{ data.batch.batch_no }}</span><span>到期日：{{ data.batch.expire_date || '未知' }}</span><span>当前库存：{{ data.batch.quantity }}</span><span>库存成本：{{ money(data.batch.inventory_cost_cents) }}</span><span>累计入库 {{ data.inbound_quantity }} − 累计出库 {{ data.outbound_quantity }} = {{ data.batch.quantity }}</span></div>
        <p class="expiry-detail-note">截至 {{ data.cutoff_date }} 的已结算流水；包含作废与冲销。查询时间：{{ data.data_as_of }}</p>
        <div class="expiry-detail-scroll" :aria-busy="loading"><table><thead><tr><th v-for="column in data.columns" :key="column.key">{{ column.label }}</th></tr></thead><tbody><tr v-for="row in data.rows" :key="row.id"><td v-for="column in data.columns" :key="column.key">{{ row[column.key] }}</td></tr><tr v-if="!data.rows.length"><td :colspan="data.columns.length">暂无库存流水</td></tr></tbody></table></div>
        <footer><span>共 {{ data.total }} 条 · 每页20条 · 第 {{ data.page }} / {{ Math.max(1, Math.ceil(data.total / 20)) }} 页</span><button :disabled="loading || data.page <= 1" @click="load(data.page - 1)">上一页</button><button :disabled="loading || data.page * 20 >= data.total" @click="load(data.page + 1)">下一页</button></footer>
      </template>
    </section>
  </div>
</template>
<style scoped>
.expiry-detail-backdrop{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px;background:#15243966}.expiry-detail-dialog{display:flex;flex-direction:column;width:min(1080px,100%);max-height:90vh;min-height:240px;border-radius:10px;background:#fff;box-shadow:0 18px 60px #12263c33;overflow:hidden;color:#304760}.expiry-detail-dialog header,.expiry-detail-dialog footer{display:flex;align-items:center;gap:10px;padding:14px 18px;flex-shrink:0}.expiry-detail-dialog header{justify-content:space-between;border-bottom:1px solid #e2e9f1}.expiry-detail-dialog h2{font-size:18px}.expiry-detail-dialog button{border:1px solid #dbe3ee;border-radius:5px;background:#fff;color:#426078;padding:6px 10px;cursor:pointer}.expiry-detail-dialog button:disabled{opacity:.5;cursor:default}.expiry-detail-summary{display:flex;flex-wrap:wrap;gap:10px 22px;padding:16px 18px;font-size:13px}.expiry-detail-summary strong{width:100%;font-size:16px}.expiry-detail-note{padding:0 18px 12px;color:#718398;font-size:12px}.expiry-detail-scroll{overflow:auto;min-height:0;flex:1;margin:0 18px}.expiry-detail-scroll table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:13px}.expiry-detail-scroll th{position:sticky;top:0;background:#f1f6fc;z-index:1}.expiry-detail-scroll th,.expiry-detail-scroll td{padding:11px 12px;text-align:left;border-bottom:1px solid #e9eef5}.expiry-detail-dialog footer{justify-content:flex-end;border-top:1px solid #e2e9f1;font-size:12px}.expiry-detail-error{padding:15px;color:#b33d36}.expiry-detail-error button{margin-left:12px}@media(max-width:600px){.expiry-detail-backdrop{padding:8px}.expiry-detail-dialog footer{flex-wrap:wrap}}
</style>
