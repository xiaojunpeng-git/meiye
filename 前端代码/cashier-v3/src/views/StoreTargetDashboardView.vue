<script setup>
import { computed, onMounted, ref } from 'vue'
import CircleCheck from '@lucide/vue/dist/esm/icons/circle-check.mjs'
import Goal from '@lucide/vue/dist/esm/icons/goal.mjs'
import Hourglass from '@lucide/vue/dist/esm/icons/hourglass.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import TrendingUp from '@lucide/vue/dist/esm/icons/trending-up.mjs'
import { formatMoney, requestCashierV3Action } from '@/services/cashierV3Bridge'
import { installCashierV3HttpAdapter } from '@/services/cashierV3HttpAdapter'

const today = () => {
  const date = new Date()
  const pad = (value) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}
const monthStart = () => `${today().slice(0, 7)}-01`
const dashboard = ref(null)
const loading = ref(false)
const errorMessage = ref('')
const period = ref('month')
const customStart = ref(monthStart())
const customEnd = ref(today())

const periodOptions = [
  { key: 'today', label: '今日' },
  { key: 'month', label: '本月' },
  { key: 'year', label: '本年' },
  { key: 'custom', label: '自定义' }
]

function rangePayload() {
  const end = today()
  if (period.value === 'today') return { startDate: end, endDate: end }
  if (period.value === 'year') return { startDate: `${end.slice(0, 4)}-01-01`, endDate: end }
  if (period.value === 'custom') return { startDate: customStart.value, endDate: customEnd.value }
  return { startDate: `${end.slice(0, 7)}-01`, endDate: end }
}

function extractDashboard(response) {
  const data = response?.data || response || {}
  return data.storeTargetDashboard
    || data.store_target_dashboard
    || data.targetDashboard
    || data.data?.storeTargetDashboard
    || data.data?.store_target_dashboard
    || data.data?.targetDashboard
    || data.data?.target_dashboard
    || data.data
    || {}
}

async function loadDashboard() {
  loading.value = true
  errorMessage.value = ''
  try {
    // 目标页可能在登录后被热更新单独挂载；确保它仍使用当前门店同源
    // V3 网关，而不是把 pendingIntegration 当成空数据展示。
    if (typeof window !== 'undefined' && !window.__CASHIER_V3_ADAPTER__) installCashierV3HttpAdapter()
    const response = await requestCashierV3Action('query-store-target-dashboard', { ...rangePayload(), period: period.value, silent: true })
    const status = String(response?.result?.status || '').toLowerCase()
    if (status && !['success', 'succeeded'].includes(status)) throw new Error(response?.result?.message || '目标数据加载失败。')
    if (response?.pendingIntegration) throw new Error('目标数据接口尚未连接，请刷新当前门店页面后重试。')
    const nextDashboard = extractDashboard(response)
    if (!nextDashboard?.availability || !nextDashboard?.scope) {
      const responseResult = response?.result || response?.data?.result || {}
      const responseMessage = responseResult?.message || response?.msg || response?.message || response?.data?.msg || response?.data?.message
      const responseCode = responseResult?.code || response?.code || response?.status
      throw new Error(`目标接口未返回看板（${responseCode || 'UNKNOWN'}${responseMessage ? `：${responseMessage}` : ''}），请刷新当前门店页面后重试。`)
    }
    dashboard.value = nextDashboard
  } catch (error) {
    dashboard.value = null
    errorMessage.value = error?.message || '目标数据加载失败，请刷新后重试。'
  } finally {
    loading.value = false
  }
}

function changePeriod(next) {
  period.value = next
  if (next !== 'custom') loadDashboard()
}

function money(cents) {
  const value = Number(cents)
  return Number.isFinite(value) ? formatMoney(value / 100) : '—'
}

function number(value) {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric.toLocaleString('zh-CN') : '—'
}

function rate(value) {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? `${numeric.toFixed(1).replace(/\.0$/, '')}%` : '—'
}

const goalCards = computed(() => ['month', 'year'].map((key) => {
  const goal = dashboard.value?.goals?.[key] || {}
  return {
    key,
    title: key === 'month' ? '本月目标进度' : '本年目标进度',
    metrics: [
      { label: key === 'month' ? '本月目标' : '年度目标', value: money(goal.target_amount_cents), icon: Goal, tone: 'goal' },
      { label: '累计完成', value: money(goal.actual_performance_cents), icon: CircleCheck, tone: 'complete' },
      { label: '达成率', value: rate(goal.achievement_rate), icon: TrendingUp, tone: 'rate' },
      { label: '剩余目标', value: money(goal.remaining_amount_cents), icon: Hourglass, tone: 'remaining' }
    ]
  }
}))

const cashRanking = computed(() => dashboard.value?.cash_ranking || dashboard.value?.cashRanking || dashboard.value?.rankings?.cash || {})
const consumptionRanking = computed(() => dashboard.value?.consumption_ranking || dashboard.value?.consumptionRanking || dashboard.value?.rankings?.consumption || {})
const cashRows = computed(() => Array.isArray(cashRanking.value.records) ? cashRanking.value.records : [])
const consumptionRows = computed(() => Array.isArray(consumptionRanking.value.records) ? consumptionRanking.value.records : [])
const scopeLabel = computed(() => dashboard.value?.scope?.label || dashboard.value?.scope?.scope_label || dashboard.value?.scope?.storeName || '当前门店')

function rowValue(row, ...keys) {
  for (const key of keys) if (row?.[key] !== undefined && row?.[key] !== null) return row[key]
  return 0
}

function rankClass(index) {
  return `store-target-rank-badge--${index + 1}`
}

onMounted(loadDashboard)
</script>

<template>
  <section class="store-target-page" aria-label="门店目标">
    <section class="store-target-filter" aria-label="目标查询条件">
      <span class="store-target-filter__scope">数据范围：{{ scopeLabel }}</span>
      <div class="store-target-filter__periods" role="group" aria-label="统计周期">
        <button v-for="option in periodOptions" :key="option.key" type="button" :class="{ 'is-active': period === option.key }" @click="changePeriod(option.key)">{{ option.label }}</button>
      </div>
      <label v-if="period === 'custom'">开始日期<input v-model="customStart" type="date" /></label>
      <label v-if="period === 'custom'">结束日期<input v-model="customEnd" type="date" /></label>
      <button v-if="period === 'custom'" type="button" class="button button--primary" @click="loadDashboard">查询</button>
      <button type="button" class="button button--secondary store-target-filter__refresh" :disabled="loading" @click="loadDashboard"><RefreshCw :size="16" aria-hidden="true" />刷新</button>
    </section>

    <p v-if="loading" class="store-target-state">正在加载目标数据…</p>
    <p v-else-if="errorMessage" class="store-target-state store-target-state--error" role="alert">{{ errorMessage }}</p>
    <template v-else>
      <section class="store-target-goals" aria-label="月度与年度目标进度">
        <article v-for="card in goalCards" :key="card.key" class="store-target-goal-card">
          <header><div><h3>{{ card.title }}</h3><span>累计至所选截止日</span></div><span class="store-target-goal-card__readonly">只读</span></header>
          <div class="store-target-goal-card__body">
            <article v-for="metric in card.metrics" :key="metric.label" :class="`store-target-metric store-target-metric--${metric.tone}`"><component :is="metric.icon" :size="17" aria-hidden="true" /><span>{{ metric.label }}</span><strong>{{ metric.value }}</strong></article>
          </div>
        </article>
      </section>

      <section class="store-target-rankings" aria-label="员工业绩排行">
        <article class="store-target-ranking"><header><div><h3>员工现金业绩排行</h3><span>按现金业绩降序 · {{ scopeLabel }}</span></div></header><div class="store-target-table-wrap"><table><thead><tr><th aria-label="排名">排名</th><th>员工</th><th>现金业绩</th><th>销售数量</th><th>成交人数</th></tr></thead><tbody><tr v-for="(row, index) in cashRows" :key="row.employee_id || row.staff_id || row.name"><td><span class="store-target-rank-badge" :class="rankClass(index)">{{ index + 1 }}</span></td><td>{{ row.employee || row.employee_name || row.staff_name || row.name || '—' }}</td><td>{{ money(rowValue(row, 'cash_performance_cents', 'cash_amount_cents')) }}</td><td>{{ number(rowValue(row, 'sales_quantity', 'sales_count', 'quantity')) }}</td><td>{{ number(rowValue(row, 'customer_count', 'deal_people', '成交人数')) }}</td></tr></tbody></table><p v-if="!cashRows.length" class="store-target-empty">暂无现金业绩排行数据。</p></div><details class="store-target-explanation"><summary>列名取值来源</summary><p>{{ dashboard?.source_explanation?.cash_ranking || '现金业绩排行来自统一销售业绩分摊事实；销售数量来自有效销售明细，成交人数按顾客去重。' }}</p></details></article>
        <article class="store-target-ranking"><header><div><h3>员工消耗业绩排行</h3><span>按消耗业绩降序 · {{ scopeLabel }}</span></div></header><div class="store-target-table-wrap"><table><thead><tr><th aria-label="排名">排名</th><th>员工</th><th>消耗业绩</th><th>手工</th><th>项目数</th><th>服务人次</th><th>服务人数</th></tr></thead><tbody><tr v-for="(row, index) in consumptionRows" :key="row.employee_id || row.staff_id || row.name"><td><span class="store-target-rank-badge" :class="rankClass(index)">{{ index + 1 }}</span></td><td>{{ row.employee || row.employee_name || row.staff_name || row.name || '—' }}</td><td>{{ money(rowValue(row, 'consumption_performance_cents', 'consumption_amount_cents')) }}</td><td>{{ money(rowValue(row, 'labor_cents', 'labor_performance_cents', 'labor_amount_cents')) }}</td><td>{{ number(rowValue(row, 'project_count', 'projects')) }}</td><td>{{ number(rowValue(row, 'service_count', 'service_visits')) }}</td><td>{{ number(rowValue(row, 'customer_count', 'service_people', 'service_people_count', 'people')) }}</td></tr></tbody></table><p v-if="!consumptionRows.length" class="store-target-empty">暂无消耗业绩排行数据。</p></div><details class="store-target-explanation"><summary>列名取值来源</summary><p>{{ dashboard?.source_explanation?.consumption_ranking || '消耗业绩排行来自统一消耗与手工事实；项目数使用员工分配快照，服务人次来自已完成服务事实，服务人数按顾客去重。' }}</p></details></article>
      </section>
    </template>
  </section>
</template>

<style scoped>
.store-target-page{display:grid;align-content:start;gap:14px;width:100%;height:100%;min-width:0;min-height:0;box-sizing:border-box;padding:20px;overflow:auto;background:#f5f7fa;color:#263b4e}.store-target-page__header,.store-target-filter,.store-target-goal-card,.store-target-ranking{border:1px solid #dce5ed;border-radius:10px;background:#fff}.store-target-page__header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:20px}.store-target-page__eyebrow{margin-bottom:4px;color:#2878c9;font-size:12px;font-weight:700;letter-spacing:.05em}.store-target-page h2{margin:0;color:#1f2329;font-size:22px}.store-target-page__header p{margin:7px 0 0;color:#697586;font-size:13px}.store-target-filter{display:flex;flex-wrap:wrap;align-items:end;gap:12px;padding:14px 18px;color:#697586;font-size:12px}.store-target-filter__scope{margin-right:auto;padding:9px 11px;border-radius:7px;background:#f4f8fc;color:#526071;font-weight:600}.store-target-filter__periods{display:flex;gap:4px}.store-target-filter__periods button{padding:7px 11px;border:1px solid #d9e1eb;border-radius:6px;background:#fff;color:#697586;font:inherit;cursor:pointer}.store-target-filter__periods button.is-active{border-color:#1677cc;background:#eaf4ff;color:#1677cc;font-weight:700}.store-target-filter label{display:grid;gap:5px}.store-target-filter input{min-height:32px;border:1px solid #d9e1eb;border-radius:6px;padding:5px 8px;color:#303133;font:inherit}.store-target-goals{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.store-target-goal-card{overflow:hidden;border-top:3px solid #266797}.store-target-goal-card header,.store-target-ranking header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:18px;border-bottom:1px solid #e8eef3}.store-target-goal-card h3,.store-target-ranking h3{margin:0;color:#1f364b;font-size:17px}.store-target-goal-card header span,.store-target-ranking header span{display:block;margin-top:5px;color:#8090a1;font-size:12px}.store-target-goal-card__readonly{margin:0!important;color:#2878c9!important;text-decoration:underline}.store-target-goal-card__body{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:18px}.store-target-metric{display:grid;grid-template-columns:24px minmax(0,1fr);gap:5px 8px;align-items:center;min-height:70px;padding:12px;border:1px solid #e3eaf0;border-radius:8px;background:#fbfcfd;color:#2878c9}.store-target-metric span{color:#708195;font-size:12px}.store-target-metric strong{grid-column:2;color:#255f8f;font-size:20px}.store-target-metric--complete{color:#218960}.store-target-metric--rate{color:#7463a1}.store-target-metric--remaining{color:#bc7b12}.store-target-rankings{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.store-target-ranking{min-width:0;overflow:hidden}.store-target-table-wrap{overflow:auto;padding:0 12px 12px}.store-target-ranking table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:13px}.store-target-ranking th,.store-target-ranking td{padding:11px 8px;border-bottom:1px solid #edf1f5;text-align:left}.store-target-ranking th{color:#697586;font-size:12px;font-weight:600;background:#fbfcfd}.store-target-ranking td{color:#364e63}.store-target-ranking td:not(:first-child){text-align:right}.store-target-empty,.store-target-state{display:grid;min-height:110px;place-items:center;margin:0;padding:24px;color:#8a94a3;text-align:center;font-size:13px}.store-target-state{border:1px solid #dce5ed;border-radius:10px;background:#fff}.store-target-state--error{border-color:#edc7c7;background:#fff7f7;color:#ae4f4f}@media(max-width:1000px){.store-target-goals,.store-target-rankings{grid-template-columns:1fr}}@media(max-width:760px){.store-target-page{padding:12px}.store-target-page__header{display:grid}.store-target-goal-card__body{grid-template-columns:1fr}.store-target-filter__scope{flex-basis:100%;margin-right:0}}
.store-target-rank-badge{display:inline-grid;width:24px;height:24px;place-items:center;border-radius:50%;background:#eef3f8;color:#708195;font-size:12px;font-weight:700}.store-target-rank-badge--1{background:#e9b32d;color:#fff}.store-target-rank-badge--2{background:#b77b58;color:#fff}.store-target-rank-badge--3{background:#dfcf73;color:#fff}.store-target-rank-badge--4,.store-target-rank-badge--5{background:#c5d1dc;color:#fff}.store-target-explanation{padding:0 18px 14px;color:#697586;font-size:12px}.store-target-explanation summary{cursor:pointer;color:#2878c9}.store-target-explanation p{margin:8px 0 0;line-height:1.6}
.store-target-ranking th,.store-target-ranking td{text-align:left!important}
</style>
