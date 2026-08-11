<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import {
  exportStoreBusinessReport,
  queryStoreBusinessReport,
  queryStoreBusinessReportCatalog
} from '@/services/storeBusinessReportApi'

const COVERAGE_START = '2026-08-10'
const DEFAULT_LIMIT = 20
const customerSegments = [
  ['all', '全部顾客'], ['pre_sale', '售前（新客）'], ['post_sale', '售后（老客）'],
  ['pending_conversion', '订单待转换'], ['guest', '嘉宾'], ['active', '活客'],
  ['effective', '有效顾客'], ['sleeping', '睡眠顾客']
]

const catalog = ref([])
const activeReport = ref('overview')
const result = ref({})
const loading = ref(false)
const exporting = ref(false)
const errorMessage = ref('')
const startDate = ref(COVERAGE_START)
const endDate = ref(today())
const dataset = ref('sale')
const metric = ref('cash_performance')
const customerSegment = ref('all')
const consumptionMetric = ref('cash')
const sleepMonths = ref(3)
const reportYear = ref(Number(today().slice(0, 4)))
const page = ref(1)

const columns = computed(() => Array.isArray(result.value.columns) ? result.value.columns : [])
const records = computed(() => Array.isArray(result.value.records) ? result.value.records : [])
const cards = computed(() => Array.isArray(result.value.cards) ? result.value.cards : [])
const trend = computed(() => result.value.trend && typeof result.value.trend === 'object' ? result.value.trend : {})
const trendPoints = computed(() => Array.isArray(trend.value.points) ? trend.value.points : [])
const ranking = computed(() => Array.isArray(result.value.ranking) ? result.value.ranking : [])
const pendingMetrics = computed(() => Array.isArray(result.value.pending_metrics) ? result.value.pending_metrics : [])
const total = computed(() => Number(result.value.total || 0))
const pageSize = computed(() => Number(result.value.page_size || DEFAULT_LIMIT))
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))
const canPageBack = computed(() => page.value > 1 && !loading.value)
const canPageForward = computed(() => page.value < pageCount.value && !loading.value)
const currentReportName = computed(() => catalog.value.find((item) => item.code === activeReport.value)?.name || '经营报表')

function today() {
  const date = new Date()
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 10)
}

function reportParams(overrides = {}) {
  return {
    report: activeReport.value,
    start_date: startDate.value,
    end_date: endDate.value,
    dataset: dataset.value,
    metric: metric.value,
    customer_segment: customerSegment.value,
    consumption_metric: consumptionMetric.value,
    sleep_months: sleepMonths.value,
    year: reportYear.value,
    page: page.value,
    limit: DEFAULT_LIMIT,
    ...overrides
  }
}

async function loadReport() {
  if (!activeReport.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    result.value = await queryStoreBusinessReport(reportParams())
  } catch (error) {
    result.value = {}
    errorMessage.value = error?.message || '经营报表读取失败，请稍后重试。'
  } finally {
    loading.value = false
  }
}

function selectReport(code) {
  if (code === activeReport.value) return
  activeReport.value = code
  page.value = 1
}

function query() {
  page.value = 1
  return loadReport()
}

function previousPage() {
  if (!canPageBack.value) return
  page.value -= 1
  loadReport()
}

function nextPage() {
  if (!canPageForward.value) return
  page.value += 1
  loadReport()
}

function csvValue(value) {
  const text = String(value ?? '')
  return /[",\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text
}

async function exportReport() {
  exporting.value = true
  errorMessage.value = ''
  try {
    // 导出仍由服务端使用同一权限和固定字段白名单，浏览器只负责下载结果。
    const exported = await exportStoreBusinessReport(reportParams({ page: 1, limit: 100 }))
    const exportColumns = Array.isArray(exported.columns) ? exported.columns : []
    const exportRecords = Array.isArray(exported.records) ? exported.records : []
    const lines = [
      exportColumns.map((column) => csvValue(column.label)).join(','),
      ...exportRecords.map((row) => exportColumns.map((column) => csvValue(row?.[column.key])).join(','))
    ]
    const url = URL.createObjectURL(new Blob([`\ufeff${lines.join('\n')}`], { type: 'text/csv;charset=utf-8' }))
    const link = document.createElement('a')
    link.href = url
    link.download = String(exported.filename || `${currentReportName.value}.csv`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (error) {
    errorMessage.value = error?.message || '经营报表导出失败，请稍后重试。'
  } finally {
    exporting.value = false
  }
}

function formatCell(value) {
  if (value === null || value === undefined || value === '') return '-'
  if (value === true) return '是'
  if (value === false) return '否'
  return String(value)
}

watch(activeReport, loadReport)

onMounted(async () => {
  try {
    const response = await queryStoreBusinessReportCatalog()
    catalog.value = Array.isArray(response) ? response : []
    activeReport.value = catalog.value[0]?.code || 'overview'
    await loadReport()
  } catch (error) {
    errorMessage.value = error?.message || '经营报表目录读取失败，请稍后重试。'
  }
})
</script>

<template>
  <main class="store-business-report" aria-label="门店经营报表">
    <header class="store-business-report__header">
      <div>
        <p class="store-business-report__eyebrow">数据</p>
        <h1>经营报表</h1>
      </div>
      <div class="store-business-report__actions">
        <button type="button" class="button button--secondary" :disabled="loading" @click="loadReport">
          <RefreshCw :size="15" aria-hidden="true" /> 刷新
        </button>
        <button type="button" class="button button--primary" :disabled="exporting" @click="exportReport">
          <Download :size="15" aria-hidden="true" /> {{ exporting ? '正在导出' : '导出' }}
        </button>
      </div>
    </header>

    <nav class="store-business-report__tabs" aria-label="经营报表功能">
      <button
        v-for="item in catalog"
        :key="item.code"
        type="button"
        class="store-business-report__tab"
        :class="{ 'store-business-report__tab--active': activeReport === item.code }"
        :aria-current="activeReport === item.code ? 'page' : undefined"
        @click="selectReport(item.code)"
      >{{ item.name }}</button>
    </nav>

    <section class="store-business-report__filters" aria-label="报表查询条件">
      <label>开始日期<input v-model="startDate" type="date" :min="COVERAGE_START" :max="endDate" /></label>
      <label>结束日期<input v-model="endDate" type="date" :min="startDate" :max="today()" /></label>
      <label v-if="activeReport === 'overview'">趋势指标
        <select v-model="metric"><option value="cash_performance">现金业绩</option><option value="sales_amount">销售额</option><option value="service_count">服务次数</option><option value="consumption_performance">消耗业绩</option></select>
      </label>
      <label v-if="activeReport === 'sales'">明细类型
        <select v-model="dataset"><option value="sale">销售明细</option><option value="payment">收款明细</option></select>
      </label>
      <template v-if="activeReport === 'customers'">
        <label>顾客范围<select v-model="customerSegment"><option v-for="item in customerSegments" :key="item[0]" :value="item[0]">{{ item[1] }}</option></select></label>
        <label>消费口径<select v-model="consumptionMetric"><option value="cash">现金业绩</option><option value="consume">消耗业绩</option></select></label>
        <label>睡眠阈值<select v-model.number="sleepMonths"><option :value="3">3 个月</option><option :value="6">6 个月</option></select></label>
        <label>自然年<input v-model.number="reportYear" type="number" min="2020" :max="Number(today().slice(0, 4)) + 1" /></label>
      </template>
      <button type="button" class="button button--primary" :disabled="loading" @click="query">{{ loading ? '查询中' : '查询' }}</button>
    </section>

    <p v-if="errorMessage" class="store-business-report__error" role="alert">{{ errorMessage }}</p>

    <template v-if="activeReport === 'overview'">
      <section class="store-business-report__metrics" aria-label="经营总览指标">
        <div v-for="card in cards" :key="card.code" class="store-business-report__metric">
          <span>{{ card.name }}</span><strong>{{ card.value }}<small>{{ card.unit }}</small></strong>
        </div>
      </section>
      <section class="store-business-report__overview-grid">
        <article class="store-business-report__panel">
          <header><h2>日期趋势</h2><span>{{ trend.metric || '-' }}</span></header>
          <div v-if="trendPoints.length" class="store-business-report__simple-table"><div v-for="point in trendPoints" :key="point.business_date"><span>{{ point.business_date }}</span><strong>{{ point.value }}</strong></div></div>
          <p v-else class="store-business-report__empty">当前条件下暂无趋势数据。</p>
        </article>
        <article class="store-business-report__panel">
          <header><h2>手艺人排行</h2><span>劳动业绩</span></header>
          <div v-if="ranking.length" class="store-business-report__simple-table"><div v-for="(row, index) in ranking" :key="`${row.employee_id}-${index}`"><span>{{ index + 1 }}. {{ row.employee_name || '-' }}</span><strong>{{ row.amount }}</strong></div></div>
          <p v-else class="store-business-report__empty">当前条件下暂无排行数据。</p>
        </article>
      </section>
    </template>

    <section v-else class="store-business-report__panel store-business-report__panel--table">
      <div v-if="pendingMetrics.length" class="store-business-report__pending"><strong>口径待确认</strong><span v-for="item in pendingMetrics" :key="item">{{ item }}</span></div>
      <div class="store-business-report__table-scroll">
        <table v-if="columns.length">
          <thead><tr><th v-for="column in columns" :key="column.key">{{ column.label }}</th></tr></thead>
          <tbody>
            <tr v-for="(row, rowIndex) in records" :key="row.id || row.order_id || `${rowIndex}`"><td v-for="column in columns" :key="column.key">{{ formatCell(row[column.key]) }}</td></tr>
            <tr v-if="!loading && !records.length"><td :colspan="columns.length" class="store-business-report__empty-cell">当前条件下暂无数据。</td></tr>
          </tbody>
        </table>
        <p v-else class="store-business-report__empty">{{ loading ? '正在读取报表数据。' : '当前报表暂无可展示的明细。' }}</p>
      </div>
      <footer v-if="total > pageSize" class="store-business-report__pagination">
        <span>共 {{ total }} 条，第 {{ page }} / {{ pageCount }} 页</span>
        <button type="button" :disabled="!canPageBack" @click="previousPage">上一页</button>
        <button type="button" :disabled="!canPageForward" @click="nextPage">下一页</button>
      </footer>
    </section>
  </main>
</template>

<style scoped>
.store-business-report { display: grid; align-content: start; gap: 14px; min-width: 0; height: 100%; box-sizing: border-box; padding: 20px; overflow: auto; background: #f5f7fa; color: #252a34; }
.store-business-report__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; }
.store-business-report__eyebrow { margin: 0 0 4px; color: #1769aa; font-size: 12px; font-weight: 700; letter-spacing: 0; }
.store-business-report h1, .store-business-report h2, .store-business-report p { margin: 0; }
.store-business-report h1 { font-size: 21px; line-height: 1.25; }
.store-business-report__actions { display: flex; flex: none; gap: 8px; }
.store-business-report__actions .button { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; }
.store-business-report__tabs { display: flex; min-height: 43px; gap: 2px; overflow-x: auto; overflow-y: hidden; border-bottom: 1px solid #d9e1eb; background: #fff; padding: 0 12px; }
.store-business-report__tab { flex: none; min-height: 42px; padding: 0 15px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: #697586; font: inherit; font-size: 13px; cursor: pointer; }
.store-business-report__tab:hover { color: #1769aa; background: #f7fbff; }
.store-business-report__tab--active { border-bottom-color: #1769aa; color: #1769aa; font-weight: 700; }
.store-business-report__filters, .store-business-report__panel { border: 1px solid #dde4ed; border-radius: 8px; background: #fff; }
.store-business-report__filters { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; padding: 13px 16px; }
.store-business-report__filters label { display: grid; gap: 5px; min-width: 140px; color: #687586; font-size: 12px; }
.store-business-report__filters input, .store-business-report__filters select { min-height: 34px; box-sizing: border-box; border: 1px solid #d8e0ea; border-radius: 6px; padding: 6px 8px; background: #fff; color: #252a34; font: inherit; }
.store-business-report__filters .button { min-height: 34px; }
.store-business-report__error { border: 1px solid #ffcaca; border-radius: 7px; padding: 10px 14px; background: #fff5f5; color: #b42318; font-size: 13px; }
.store-business-report__metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
.store-business-report__metric { min-height: 94px; box-sizing: border-box; border: 1px solid #dde4ed; border-radius: 8px; padding: 14px; background: #fff; }
.store-business-report__metric span, .store-business-report__metric small { color: #728094; font-size: 12px; }
.store-business-report__metric strong { display: block; margin-top: 9px; color: #1f4f7a; font-size: 24px; line-height: 1; }
.store-business-report__metric small { margin-left: 4px; font-size: 12px; font-weight: 400; }
.store-business-report__overview-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.store-business-report__panel { min-width: 0; padding: 16px; }
.store-business-report__panel header { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
.store-business-report__panel h2 { font-size: 15px; }
.store-business-report__panel header span { color: #738095; font-size: 12px; }
.store-business-report__simple-table { margin-top: 14px; }
.store-business-report__simple-table > div { display: flex; justify-content: space-between; gap: 12px; border-top: 1px solid #edf1f5; padding: 10px 2px; color: #647184; font-size: 13px; }
.store-business-report__simple-table strong { color: #26364a; }
.store-business-report__panel--table { padding: 0; }
.store-business-report__pending { display: flex; flex-wrap: wrap; gap: 8px 12px; border-bottom: 1px solid #f2d49a; padding: 10px 16px; background: #fff9ed; color: #9a6509; font-size: 12px; }
.store-business-report__table-scroll { min-width: 0; overflow: auto; }
.store-business-report table { width: 100%; border-collapse: collapse; white-space: nowrap; font-size: 13px; }
.store-business-report th, .store-business-report td { border-bottom: 1px solid #edf1f5; padding: 11px 12px; text-align: left; }
.store-business-report th { background: #fafbfd; color: #667386; font-size: 12px; font-weight: 600; }
.store-business-report td { color: #303946; }
.store-business-report__empty, .store-business-report__empty-cell { min-height: 130px; color: #8290a2; font-size: 13px; text-align: center; }
.store-business-report__empty { display: grid; place-items: center; }
.store-business-report__empty-cell { padding: 40px; }
.store-business-report__pagination { display: flex; justify-content: flex-end; align-items: center; gap: 8px; padding: 12px 16px; color: #708096; font-size: 12px; }
.store-business-report__pagination button { min-height: 30px; border: 1px solid #d7e0eb; border-radius: 5px; padding: 4px 9px; background: #fff; color: #445365; cursor: pointer; }
.store-business-report__pagination button:disabled { cursor: not-allowed; opacity: .45; }
@media (max-width: 1100px) { .store-business-report__metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 760px) { .store-business-report { padding: 12px; } .store-business-report__header { display: grid; } .store-business-report__actions { flex-wrap: wrap; } .store-business-report__metrics, .store-business-report__overview-grid { grid-template-columns: 1fr; } .store-business-report__filters label { flex: 1 1 160px; } }
</style>
