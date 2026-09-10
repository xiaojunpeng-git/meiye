<script setup>
import { computed, ref, watch } from 'vue'
import Info from '@lucide/vue/dist/esm/icons/info.mjs'
import {
  formatMoney,
  openCashierV3QueryEntitySelector,
  requestCashierV3Action,
  useCashierV3State
} from '@/services/cashierV3Bridge'

// 数值、趋势点、指标口径和可见范围均由后端事实／聚合接口返回。
// 本页仅处理筛选交互与图表的视觉缩放，不在浏览器计算任何经营指标。
const state = useCashierV3State()

const METRIC_ORDER = [
  'sales_amount',
  'cash_performance',
  'actual_performance',
  'balance_deduction',
  'recharge_amount',
  'debt_amount',
  'service_count',
  'consumption_performance',
  'labor_performance'
]

const DEFAULT_METRIC_CODE = 'cash_performance'

const METRIC_LABELS = {
  sales_amount: '销售额',
  cash_performance: '现金业绩',
  actual_performance: '实际业绩',
  balance_deduction: '余额扣款',
  recharge_amount: '充值',
  debt_amount: '欠款',
  service_count: '服务次数',
  consumption_performance: '消耗业绩',
  labor_performance: '劳动业绩'
}

const dashboardSnapshot = ref(null)
const detail = ref(null)
const descriptionMetricCode = ref('')
const dashboard = computed(() => dashboardSnapshot.value || state.businessDashboard || {})
const scope = computed(() => dashboard.value.scope || {})
const trend = computed(() => dashboard.value.trend || {})
const ranking = computed(() => dashboard.value.ranking || {})
const isPlatform = computed(() => dashboard.value.mode === 'platform')
const dashboardTitle = computed(() => isPlatform.value ? '运营概况' : '运营概况')
const rankingTitle = computed(() => ranking.value.dimension === 'store' ? '门店排行' : '员工排行')
const rankEntityLabel = computed(() => ranking.value.dimension === 'store' ? '门店' : '员工')

const localScope = ref({
  start: '',
  end: '',
  organization: null,
  store: null
})

watch(
  scope,
  (nextScope = {}) => {
    const dateRange = nextScope.dateRange || {}
    localScope.value = {
      start: dateRange.start || nextScope.startDate || '',
      end: dateRange.end || nextScope.endDate || '',
      organization: nextScope.organization || null,
      store: nextScope.store || null
    }
  },
  { deep: true, immediate: true }
)

function metricCode(metric = {}) {
  const source = metric || {}
  return source.metricCode || source.metric_code || source.code || ''
}

function isSupportedMetricCode(code) {
  return METRIC_ORDER.includes(code)
}

function normalizeMetricCode(code) {
  return isSupportedMetricCode(code) ? code : DEFAULT_METRIC_CODE
}

function rankingOptionMetricCode(option = {}) {
  return option.value || metricCode(option)
}

const cards = computed(() => {
  const cardsByMetric = new Map()
  const dashboardCards = Array.isArray(dashboard.value.cards) ? dashboard.value.cards : []
  dashboardCards.forEach((card) => {
    const code = metricCode(card)
    if (isSupportedMetricCode(code) && !cardsByMetric.has(code)) cardsByMetric.set(code, card)
  })
  return METRIC_ORDER.map((code) => cardsByMetric.get(code)).filter(Boolean)
})

const activeMetricCode = computed(() => normalizeMetricCode(
  dashboard.value.selectedMetricCode || dashboard.value.selected_metric_code || trend.value.metricCode || trend.value.metric_code
))
const activeMetric = computed(() => cards.value.find((card) => metricCode(card) === activeMetricCode.value) || null)
const activeMetricName = computed(() => activeMetric.value?.name || activeMetric.value?.label || METRIC_LABELS[activeMetricCode.value])
const trendMetricCode = computed(() => metricCode(trend.value))
const trendPoints = computed(() => {
  if (trendMetricCode.value && !isSupportedMetricCode(trendMetricCode.value)) return []
  return Array.isArray(trend.value.points) ? trend.value.points : []
})
const trendPeak = computed(() => {
  const values = trendPoints.value.map((point) => Number(point.value)).filter(Number.isFinite)
  return Math.max(...values, 0)
})
const rankingColumns = computed(() => Array.isArray(ranking.value.columns) ? ranking.value.columns : [])
const rankingRecords = computed(() => Array.isArray(ranking.value.records) ? ranking.value.records : [])
const rankingSortBy = computed(() => normalizeMetricCode(ranking.value.sortBy || ranking.value.sort_by))
const rankingSortOptions = computed(() => {
  const optionsByMetric = new Map()
  const rawOptions = Array.isArray(ranking.value.sortOptions) ? ranking.value.sortOptions : []
  rawOptions.forEach((option) => {
    const code = rankingOptionMetricCode(option)
    if (!isSupportedMetricCode(code) || optionsByMetric.has(code)) return
    optionsByMetric.set(code, {
      ...option,
      value: code,
      label: option.label || option.name || METRIC_LABELS[code]
    })
  })

  const requiredMetricCodes = [DEFAULT_METRIC_CODE, rankingSortBy.value]
  requiredMetricCodes.forEach((code) => {
    if (!optionsByMetric.has(code)) {
      optionsByMetric.set(code, { value: code, label: METRIC_LABELS[code] })
    }
  })

  return METRIC_ORDER.filter((code) => optionsByMetric.has(code)).map((code) => optionsByMetric.get(code))
})

function displayScopeEntity(entity, fallback) {
  if (!entity) return fallback
  if (typeof entity === 'string') return entity
  return entity.name || entity.label || entity.storeName || entity.organizationName || fallback
}

function scopePayload() {
  return {
    startDate: localScope.value.start,
    endDate: localScope.value.end,
    organizationId: localScope.value.organization?.id || localScope.value.organization?.organizationId || null,
    storeId: localScope.value.store?.id || localScope.value.store?.storeId || null
  }
}

async function applyScope() {
  return applyDashboardResponse(await requestCashierV3Action('query-business-dashboard-summary', { ...scopePayload(), silent: true }))
}

async function selectScopeEntity(entityType) {
  const selected = await openCashierV3QueryEntitySelector({
    entityType,
    title: entityType === 'organization' ? '选择组织范围' : '选择门店范围',
    currentValue: localScope.value[entityType]?.id || null,
    selectionContext: {
      scope: 'business_dashboard_scope',
      dashboardMode: dashboard.value.mode || 'store',
      field: entityType
    }
  })
  if (!selected?.selected || Array.isArray(selected.selected)) return
  localScope.value = {
    ...localScope.value,
    [entityType]: selected.selected
  }
}

function clearScopeEntity(entityType) {
  localScope.value = {
    ...localScope.value,
    [entityType]: null
  }
}

function formatMetricValue(card = {}) {
  const value = card.value ?? card.amount ?? 0
  const unit = card.unit || ''
  if (unit === '元' || card.valueType === 'money' || card.type === 'money') return formatMoney(value)
  return `${value ?? 0}${unit ? ` ${unit}` : ''}`
}

function cardDescription(card = {}) {
  return card.description || card.tooltip?.summary || '查看趋势与明细'
}

function descriptionId(card = {}) {
  const code = metricCode(card) || 'metric'
  return `business-metric-description-${code}`
}

function isDescriptionOpen(card = {}) {
  return descriptionMetricCode.value === metricCode(card)
}

function toggleDescription(card = {}) {
  const code = metricCode(card)
  descriptionMetricCode.value = descriptionMetricCode.value === code ? '' : code
}

async function selectMetric(card) {
  const code = metricCode(card)
  if (!isSupportedMetricCode(code)) return
  return applyDashboardResponse(await requestCashierV3Action('query-business-dashboard-trend', {
    ...scopePayload(),
    metricCode: code,
    silent: true
  }))
}

async function openMetricDetail(card) {
  const code = metricCode(card)
  if (!isSupportedMetricCode(code)) return
  const response = await requestCashierV3Action('open-business-dashboard-detail', {
    ...scopePayload(),
    metricCode: code,
    silent: true
  })
  detail.value = response?.data?.businessDashboard?.detail || response?.data?.data?.businessDashboard?.detail || null
}

async function changeRankingSort(event) {
  return applyDashboardResponse(await requestCashierV3Action('query-business-dashboard-ranking', {
    ...scopePayload(),
    sortBy: normalizeMetricCode(event.target.value),
    sortOrder: ranking.value.sortOrder || 'desc',
    silent: true
  }))
}

async function changeRankingDirection(direction) {
  if (direction === ranking.value.sortOrder) return
  return applyDashboardResponse(await requestCashierV3Action('query-business-dashboard-ranking', {
    ...scopePayload(),
    sortBy: rankingSortBy.value,
    sortOrder: direction,
    silent: true
  }))
}

async function openRankingDetail(record) {
  return requestCashierV3Action('open-business-dashboard-detail', {
    ...scopePayload(),
    metricCode: rankingSortBy.value,
    rankingDimension: ranking.value.dimension,
    rankingEntityId: record.id || record.entityId || record.storeId || record.staffId || null
  })
}

function applyDashboardResponse(response) {
  const next = response?.data?.businessDashboard || response?.data?.data?.businessDashboard
  if (next && typeof next === 'object') dashboardSnapshot.value = next
  return response
}

function trendBarStyle(point) {
  const value = Number(point.value)
  const ratio = trendPeak.value > 0 && Number.isFinite(value) ? Math.max(4, (value / trendPeak.value) * 100) : 4
  return { height: `${ratio}%` }
}

function formatTrendValue(point) {
  const unit = activeMetric.value?.unit || point.unit || ''
  if (unit === '元' || activeMetric.value?.valueType === 'money') return formatMoney(point.value)
  return `${point.value ?? 0}${unit ? ` ${unit}` : ''}`
}

function rankingCellValue(record, column) {
  const value = record[column.key]
  if (column.type === 'money' || column.valueType === 'money') return formatMoney(value)
  if (value === null || value === undefined || value === '') return '—'
  return value
}

function aggregationLabel() {
  if (dashboard.value.aggregationCaughtUp === true) return '数据已更新'
  if (dashboard.value.aggregationCaughtUp === false) return '数据更新中'
  return '等待数据状态'
}
</script>

<template>
  <section class="business-dashboard-page" aria-label="运营概况">
    <header class="business-dashboard-page__header">
      <div>
        <div class="business-dashboard-page__eyebrow">门店经营</div>
        <h2>{{ dashboardTitle }}</h2>
      </div>
      <div class="business-dashboard-page__actions">
        <button type="button" class="button button--primary" @click="applyScope">刷新数据</button>
      </div>
    </header>

    <section class="business-dashboard-filter" aria-label="运营概况筛选条件">
      <label>
        <span>开始日期</span>
        <input v-model="localScope.start" type="date">
      </label>
      <label>
        <span>结束日期</span>
        <input v-model="localScope.end" type="date">
      </label>
      <template v-if="isPlatform">
        <label class="business-dashboard-select-field">
          <span>组织</span>
          <button type="button" @click="selectScopeEntity('organization')">{{ displayScopeEntity(localScope.organization, '全部有权组织') }}</button>
          <button v-if="localScope.organization" type="button" class="business-dashboard-clear" aria-label="清空组织" @click="clearScopeEntity('organization')">×</button>
        </label>
        <label class="business-dashboard-select-field">
          <span>门店</span>
          <button type="button" @click="selectScopeEntity('store')">{{ displayScopeEntity(localScope.store, '全部有权门店') }}</button>
          <button v-if="localScope.store" type="button" class="business-dashboard-clear" aria-label="清空门店" @click="clearScopeEntity('store')">×</button>
        </label>
      </template>
      <div v-else class="business-dashboard-filter__fixed-scope">
        <span>统计范围</span>
        <strong>{{ scope.forcedRangeLabel || `当前登录门店：${state.currentStore?.name || state.storeName}` }}</strong>
      </div>
      <button type="button" class="button button--primary business-dashboard-filter__submit" @click="applyScope">查询</button>
    </section>

    <section class="business-dashboard-data-status" aria-label="数据状态">
      <span :class="{ 'business-dashboard-data-status__pill--pending': dashboard.aggregationCaughtUp === false }" class="business-dashboard-data-status__pill">{{ aggregationLabel() }}</span>
      <span>数据更新至：{{ dashboard.dataAsOf || '—' }}</span>
      <span>统计起始：{{ dashboard.coverageStart || '—' }}</span>
    </section>

    <section class="business-dashboard-metrics" aria-label="经营指标">
      <article
        v-for="card in cards"
        :key="metricCode(card) || card.name"
        class="business-metric-card"
        :class="{ 'business-metric-card--active': metricCode(card) === activeMetricCode }"
      >
        <div class="business-metric-card__heading">
          <button type="button" class="business-metric-card__select" @click="selectMetric(card)">
            <span class="business-metric-card__name">{{ card.name || card.label }}</span>
            <strong>{{ formatMetricValue(card) }}</strong>
          </button>
          <button
            type="button"
            class="business-metric-card__info"
            :aria-controls="descriptionId(card)"
            :aria-expanded="isDescriptionOpen(card)"
            :aria-label="`${card.name || card.label}指标说明`"
            @click="toggleDescription(card)"
          >
            <Info :size="16" aria-hidden="true" />
          </button>
          <span v-if="isDescriptionOpen(card)" :id="descriptionId(card)" class="business-metric-card__description-popover" role="tooltip">{{ cardDescription(card) }}</span>
        </div>
        <button type="button" class="business-metric-card__detail" @click="openMetricDetail(card)">查看明细 →</button>
      </article>
      <div v-if="!cards.length" class="business-dashboard-empty">暂无经营数据。</div>
    </section>

    <section class="business-dashboard-panels">
      <article class="business-dashboard-panel business-dashboard-trend">
        <header>
          <div>
            <h3>趋势</h3>
            <span>{{ activeMetricName }}</span>
          </div>
          <button v-if="activeMetric" type="button" class="button button--text" @click="openMetricDetail(activeMetric)">查看全部明细</button>
        </header>
        <div v-if="trend.isLoading" class="business-dashboard-loading">正在加载趋势数据…</div>
        <div v-else-if="trendPoints.length" class="business-trend-chart" role="img" :aria-label="`${activeMetricName}趋势图`">
          <div v-for="point in trendPoints" :key="point.id || point.label" class="business-trend-chart__item" :title="`${point.label}：${formatTrendValue(point)}`">
            <span class="business-trend-chart__value">{{ formatTrendValue(point) }}</span>
            <span class="business-trend-chart__bar-wrap"><i :style="trendBarStyle(point)" /></span>
            <span class="business-trend-chart__label">{{ point.label }}</span>
          </div>
        </div>
        <div v-else class="business-dashboard-empty">暂无 {{ activeMetricName }} 的趋势数据。</div>
      </article>

      <article class="business-dashboard-panel business-dashboard-ranking">
        <header>
          <div>
            <h3>{{ rankingTitle }}</h3>
          </div>
          <div class="business-dashboard-ranking__controls">
            <label>
              <span>排序指标</span>
              <select :value="rankingSortBy" @change="changeRankingSort">
                <option v-for="option in rankingSortOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
            </label>
            <div class="business-dashboard-ranking__direction" role="group" aria-label="排序方向">
              <button type="button" :class="{ active: ranking.sortOrder !== 'asc' }" @click="changeRankingDirection('desc')">降序</button>
              <button type="button" :class="{ active: ranking.sortOrder === 'asc' }" @click="changeRankingDirection('asc')">升序</button>
            </div>
          </div>
        </header>
        <div class="business-dashboard-ranking__table-wrap">
          <table v-if="rankingColumns.length" class="business-dashboard-ranking__table">
            <thead>
              <tr><th v-for="column in rankingColumns" :key="column.key">{{ column.label || column.name }}</th><th>操作</th></tr>
            </thead>
            <tbody>
              <tr v-for="record in rankingRecords" :key="record.id || record.entityId || record.name">
                <td v-for="column in rankingColumns" :key="column.key">{{ rankingCellValue(record, column) }}</td>
                <td><button type="button" class="button button--text" @click="openRankingDetail(record)">查看{{ rankEntityLabel }}明细</button></td>
              </tr>
            </tbody>
          </table>
          <div v-else-if="ranking.isLoading" class="business-dashboard-loading">正在加载{{ rankingTitle }}…</div>
          <div v-else class="business-dashboard-empty">暂无{{ rankingTitle }}数据。</div>
        </div>
      </article>
    </section>

    <section v-if="detail" class="business-dashboard-panel business-dashboard-detail" aria-label="经营明细">
      <header>
        <div><h3>{{ detail.metricName }}明细</h3><span>共 {{ detail.total || 0 }} 条</span></div>
        <button type="button" class="button button--text" @click="detail = null">关闭</button>
      </header>
      <div class="business-dashboard-ranking__table-wrap">
        <table class="business-dashboard-ranking__table">
          <thead><tr><th v-for="column in detail.columns" :key="column.key">{{ column.label }}</th></tr></thead>
          <tbody><tr v-for="record in detail.records" :key="record.id"><td v-for="column in detail.columns" :key="column.key">{{ record[column.key] || '—' }}</td></tr></tbody>
        </table>
      </div>
    </section>
  </section>
</template>

<style scoped>
.business-dashboard-page { display: grid; align-content: start; gap: 14px; width: 100%; height: 100%; min-width: 0; min-height: 0; box-sizing: border-box; padding: 20px; overflow-x: hidden; overflow-y: auto; overscroll-behavior: contain; background: #f5f7fa; }
.business-dashboard-page__header, .business-dashboard-filter, .business-dashboard-data-status, .business-dashboard-panel { border: 1px solid #dde4ed; border-radius: 12px; background: #fff; }
.business-dashboard-page__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; padding: 20px; }
.business-dashboard-page__eyebrow { margin-bottom: 4px; color: #2878c9; font-size: 12px; font-weight: 700; letter-spacing: .05em; }
.business-dashboard-page__header h2, .business-dashboard-panel h3 { margin: 0; color: #1f2329; }
.business-dashboard-page__header h2 { font-size: 21px; }
.business-dashboard-page__header p { max-width: 720px; margin: 7px 0 0; color: #697586; font-size: 13px; line-height: 1.65; }
.business-dashboard-page__actions { display: flex; flex: none; gap: 8px; }
.business-dashboard-filter { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; padding: 14px 18px; }
.business-dashboard-filter > label, .business-dashboard-filter__fixed-scope { display: grid; gap: 6px; min-width: 154px; color: #697586; font-size: 12px; }
.business-dashboard-filter input, .business-dashboard-filter select, .business-dashboard-select-field > button:first-of-type { min-height: 34px; box-sizing: border-box; padding: 7px 9px; border: 1px solid #d9e1eb; border-radius: 7px; background: #fff; color: #303133; font: inherit; }
.business-dashboard-select-field { position: relative; }
.business-dashboard-select-field > button:first-of-type { padding-right: 28px; text-align: left; cursor: pointer; }
.business-dashboard-clear { position: absolute; right: 6px; bottom: 7px; border: 0; background: transparent; color: #8a94a3; font-size: 17px; cursor: pointer; }
.business-dashboard-filter__fixed-scope { flex: 1 1 230px; }
.business-dashboard-filter__fixed-scope strong { min-height: 34px; box-sizing: border-box; padding: 9px 11px; border-radius: 7px; background: #f4f8fc; color: #526071; font-size: 13px; font-weight: 600; }
.business-dashboard-filter__submit { min-height: 34px; }
.business-dashboard-data-status { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; padding: 11px 18px; color: #697586; font-size: 12px; }
.business-dashboard-data-status__pill { padding: 4px 8px; border-radius: 999px; background: #eaf8ed; color: #287d3c; font-weight: 600; }
.business-dashboard-data-status__pill--pending { background: #fff7e8; color: #b66a00; }
.business-dashboard-metrics { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
.business-metric-card { position: relative; display: grid; min-height: 150px; align-content: start; gap: 9px; padding: 15px; border: 1px solid #dde4ed; border-radius: 11px; background: #fff; color: inherit; text-align: left; transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
.business-metric-card:hover { border-color: #91caff; box-shadow: 0 7px 17px rgba(22, 119, 204, .08); transform: translateY(-1px); }
.business-metric-card--active { border-color: #1677cc; box-shadow: 0 0 0 2px rgba(22, 119, 204, .11); }
.business-metric-card__heading { position: relative; display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.business-metric-card__select { display: grid; flex: 1; gap: 9px; min-width: 0; padding: 0; border: 0; background: transparent; color: inherit; text-align: left; cursor: pointer; }
.business-metric-card__select:focus-visible, .business-metric-card__info:focus-visible, .business-metric-card__detail:focus-visible { outline: 2px solid #1677cc; outline-offset: 2px; }
.business-metric-card__name { color: #526071; font-size: 13px; font-weight: 600; }
.business-metric-card strong { color: #1f2329; font-size: 24px; line-height: 1.15; }
.business-metric-card__info { display: grid; flex: none; width: 24px; height: 24px; place-items: center; padding: 0; border: 0; border-radius: 4px; background: transparent; color: #6d7c8d; cursor: pointer; }
.business-metric-card__info:hover, .business-metric-card__info[aria-expanded="true"] { color: #1677cc; background: #eaf4ff; }
.business-metric-card__description-popover { position: absolute; z-index: 3; top: 30px; right: 0; width: min(270px, calc(100vw - 72px)); padding: 9px 10px; border: 1px solid #c6d8eb; border-radius: 6px; background: #fff; box-shadow: 0 8px 20px rgba(31, 35, 41, .16); color: #526071; font-size: 12px; line-height: 1.55; }
.business-metric-card__detail { justify-self: start; margin-top: auto; padding: 0; border: 0; background: transparent; color: #1677cc; font-size: 12px; font-weight: 600; cursor: pointer; }
.business-dashboard-panels { display: grid; grid-template-columns: minmax(0, .92fr) minmax(0, 1.08fr); gap: 14px; }
.business-dashboard-panel { display: grid; min-width: 0; min-height: 330px; padding: 18px; }
.business-dashboard-panel > header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
.business-dashboard-panel h3 { font-size: 16px; }
.business-dashboard-panel header span { display: block; margin-top: 5px; color: #7a8696; font-size: 12px; }
.business-trend-chart { display: grid; grid-template-columns: repeat(auto-fit, minmax(42px, 1fr)); gap: 9px; min-height: 242px; align-items: end; padding: 18px 4px 4px; }
.business-trend-chart__item { display: grid; min-width: 0; grid-template-rows: auto 1fr auto; gap: 7px; height: 100%; text-align: center; }
.business-trend-chart__value { overflow: hidden; color: #526071; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.business-trend-chart__bar-wrap { display: flex; align-items: end; min-height: 165px; padding: 0 5px; border-bottom: 1px solid #e7edf4; }
.business-trend-chart__bar-wrap i { display: block; width: 100%; min-height: 6px; border-radius: 5px 5px 1px 1px; background: linear-gradient(180deg, #78b8ee, #1677cc); }
.business-trend-chart__label { overflow: hidden; color: #7a8696; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.business-dashboard-ranking__controls { display: flex; flex-wrap: wrap; justify-content: end; gap: 8px; }
.business-dashboard-ranking__controls label { display: flex; align-items: center; gap: 6px; color: #697586; font-size: 12px; }
.business-dashboard-ranking__controls select { height: 31px; border: 1px solid #d9e1eb; border-radius: 6px; background: #fff; color: #303133; font: inherit; }
.business-dashboard-ranking__direction { display: flex; overflow: hidden; border: 1px solid #d9e1eb; border-radius: 6px; }
.business-dashboard-ranking__direction button { min-width: 46px; padding: 6px 8px; border: 0; border-left: 1px solid #d9e1eb; background: #fff; color: #697586; font-size: 12px; cursor: pointer; }
.business-dashboard-ranking__direction button:first-child { border-left: 0; }
.business-dashboard-ranking__direction button.active { background: #eaf4ff; color: #1677cc; font-weight: 700; }
.business-dashboard-ranking__table-wrap { min-width: 0; margin-top: 18px; overflow: auto; }
.business-dashboard-ranking__table { width: 100%; border-collapse: collapse; color: #303133; font-size: 13px; white-space: nowrap; }
.business-dashboard-ranking__table th, .business-dashboard-ranking__table td { padding: 11px 10px; border-bottom: 1px solid #edf1f5; text-align: left; }
.business-dashboard-ranking__table th { color: #697586; font-size: 12px; font-weight: 600; }
.business-dashboard-loading, .business-dashboard-empty { display: grid; min-height: 150px; align-content: center; justify-content: center; color: #8a94a3; font-size: 13px; text-align: center; }
.business-dashboard-metrics > .business-dashboard-empty { grid-column: 1 / -1; border: 1px dashed #cfd9e4; border-radius: 10px; background: #fff; }
@media (max-width: 1220px) { .business-dashboard-metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); } .business-dashboard-panels { grid-template-columns: 1fr; } }
@media (max-width: 760px) { .business-dashboard-page { padding: 12px; } .business-dashboard-page__header { display: grid; } .business-dashboard-page__actions { flex-wrap: wrap; } .business-dashboard-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } .business-dashboard-panel > header { display: grid; } .business-dashboard-ranking__controls { justify-content: start; } }
</style>
