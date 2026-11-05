<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { formatMoney } from '@/services/cashierV3Bridge'
import { queryProductDashboard } from '@/services/productDashboardApi'
import ExpiryBatchDetail from '@/components/ExpiryBatchDetail.vue'

const props = defineProps({ params: { type: Object, required: true }, storeIds: { type: Array, default: () => [] } })
const emit = defineEmits(['close'])
const data = ref(null)
const loading = ref(false)
const error = ref('')
const closeButton = ref(null)
const batchParams = ref(null)
let requestId = 0
let previousFocus = null

/** Each page carries the server-declared bucket identity and committed store scope; the server enforces permissions again. */
async function load(page = 1) {
  const id = ++requestId
  loading.value = true
  error.value = ''
  try {
    const result = await queryProductDashboard({ ...props.params, store_ids: props.storeIds.join(','), page })
    if (id === requestId) data.value = result
  } catch (cause) {
    if (id === requestId) { data.value = null; error.value = cause.message || '临期批次明细读取失败' }
  } finally {
    if (id === requestId) loading.value = false
  }
}

function display(row, key) {
  const value = row[key]
  if (key === 'inventory_cost_cents') return value === null || value === undefined ? '-' : formatMoney(Math.round(Number(value) / 100))
  if (key === 'days') return value === null || value === undefined ? '-' : `${value}天`
  return value === null || value === undefined || value === '' ? '-' : value
}

/** Escape closes the topmost detail; keyboard focus returns to its opener. */
function onKey(event) {
  if (batchParams.value) return
  if (event.key === 'Escape') emit('close')
  if (event.key !== 'Tab') return
  const buttons = [...event.currentTarget.querySelectorAll('button:not(:disabled)')]
  const first = buttons[0], last = buttons[buttons.length - 1]
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}

onMounted(() => { previousFocus = document.activeElement; closeButton.value?.focus(); load() })
onBeforeUnmount(() => { ++requestId; previousFocus?.focus() })
</script>

<template>
  <div class="expiry-bucket-backdrop" @click.self="emit('close')">
    <section class="expiry-bucket-dialog" role="dialog" aria-modal="true" aria-labelledby="expiry-bucket-title" @keydown="onKey">
      <header><h2 id="expiry-bucket-title">{{ data?.title || '临期产品' }}明细</h2><button ref="closeButton" type="button" @click="emit('close')">关闭</button></header>
      <p v-if="error" class="expiry-bucket-error" role="alert">{{ error }} <button type="button" @click="load()">重试</button></p>
      <p v-if="loading" class="expiry-bucket-state" role="status">正在读取临期批次明细...</p>
      <template v-if="data">
        <p class="expiry-bucket-summary" role="status">当前库存 {{ data.quantity }} · 共 {{ data.total }} 个批次 · 截至 {{ data.cutoff_date }} · 查询时间 {{ data.data_as_of }}</p>
        <div class="expiry-bucket-scroll" :aria-busy="loading">
          <table><thead><tr><th v-for="column in data.columns" :key="column.key">{{ column.label }}</th><th>操作</th></tr></thead>
            <tbody><tr v-for="row in data.rows" :key="`${row.drilldown.batch_store_id}-${row.drilldown.batch_id}`"><td v-for="column in data.columns" :key="column.key">{{ display(row, column.key) }}</td><td><button type="button" @click="batchParams = row.drilldown">查看批次流水</button></td></tr>
              <tr v-if="!data.rows.length"><td :colspan="data.columns.length + 1">该档暂无批次</td></tr></tbody></table>
        </div>
        <footer><span>共 {{ data.total }} 条 · 每页 {{ data.page_size }} 条 · 第 {{ data.page }} / {{ Math.max(1, Math.ceil(data.total / data.page_size)) }} 页</span><button type="button" :disabled="loading || data.page <= 1" @click="load(data.page - 1)">上一页</button><button type="button" :disabled="loading || data.page * data.page_size >= data.total" @click="load(data.page + 1)">下一页</button></footer>
      </template>
    </section>
    <ExpiryBatchDetail v-if="batchParams" :params="batchParams" :store-ids="storeIds" @close="batchParams = null" />
  </div>
</template>

<style scoped>
.expiry-bucket-backdrop{position:fixed;inset:0;z-index:900;display:flex;align-items:center;justify-content:center;padding:20px;background:#15243966}
.expiry-bucket-dialog{display:flex;flex-direction:column;width:min(1180px,100%);max-height:90vh;min-height:240px;border-radius:10px;background:#fff;box-shadow:0 18px 60px #12263c33;overflow:hidden;color:#304760}
.expiry-bucket-dialog header,.expiry-bucket-dialog footer{display:flex;align-items:center;gap:10px;padding:14px 18px;flex-shrink:0}
.expiry-bucket-dialog header{justify-content:space-between;border-bottom:1px solid #e2e9f1}
.expiry-bucket-dialog h2{margin:0;font-size:18px}
.expiry-bucket-dialog button{border:1px solid #dbe3ee;border-radius:5px;background:#fff;color:#1769aa;padding:6px 10px;cursor:pointer}
.expiry-bucket-dialog button:disabled{opacity:.5;cursor:default}
.expiry-bucket-summary,.expiry-bucket-state{padding:12px 18px;color:#53677d;font-size:13px}
.expiry-bucket-scroll{overflow:auto;min-height:0;flex:1;margin:0 18px}
.expiry-bucket-scroll table{width:100%;min-width:900px;border-collapse:collapse;white-space:nowrap;font-size:13px}
.expiry-bucket-scroll th{position:sticky;top:0;background:#f1f6fc;z-index:1}
.expiry-bucket-scroll th,.expiry-bucket-scroll td{padding:11px 12px;text-align:left;border-bottom:1px solid #e9eef5}
.expiry-bucket-dialog footer{justify-content:flex-end;border-top:1px solid #e2e9f1;font-size:12px}
.expiry-bucket-error{padding:15px;color:#b33d36}
@media(max-width:600px){.expiry-bucket-backdrop{padding:8px}.expiry-bucket-dialog footer{flex-wrap:wrap}}
</style>
