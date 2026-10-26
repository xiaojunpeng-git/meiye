<template>
  <div class="scheme-report">
    <header><h1>{{ title }}</h1><button type="button" @click="showGuide = true">列名取值来源</button></header>
    <section class="scheme-report__filter">
      <OrganizationStoreScopePicker v-model="storeIds" :load-scope="loadScope" />
      <label>从 <input v-model="startMonth" type="month" /></label><label>至 <input v-model="endMonth" type="month" /></label>
      <label>指标 <select v-model="metricCode"><option value="">全部指标</option><option v-for="metric in catalog.metrics" :key="metric.code" :value="metric.code">{{ metric.name }}</option></select></label>
      <button type="button" :disabled="loading" @click="query">查询</button><button type="button" :disabled="loading || !rows.length" @click="exportRows">导出</button>
    </section>
    <p v-if="error" class="scheme-report__error" role="alert">{{ error }}</p>
    <section v-if="reportCode === 'target_analysis' && result.series && result.series.length" class="scheme-report__analysis">
      <article v-for="series in result.series" :key="series.key" class="scheme-report__series"><h2>{{ series.metric_name }} · {{ scopeLabel(series) }}</h2><p>同指标、同对象的授权门店月度完成情况；其他指标或对象不相加。</p><div class="scheme-report__month-grid"><div v-for="month in series.months" :key="month.month_key" class="scheme-report__month"><strong>{{ month.month_key }}</strong><span>{{ month.store_count }} 店</span><span>目标 {{ formatValue(month.target_value, series.unit_code) }}</span><span>完成 {{ month.actual_value === null ? '—' : formatValue(month.actual_value, series.unit_code) }}</span><b>{{ month.completion_rate === null ? '—' : `${month.completion_rate}%` }}</b><div class="scheme-report__track"><i :style="{width: `${Math.min(100, Math.max(0, Number(month.completion_rate || 0)))}%`}" /></div></div></div></article>
    </section>
    <section class="scheme-report__body">
      <div class="scheme-report__meta"><span>方案 {{ result.scheme_ids && result.scheme_ids.length ? result.scheme_ids.join('、') : '未关联' }} · {{ rows.length }} 条目标</span><span>数据更新时间 {{ result.data_as_of || '—' }}</span></div>
      <div v-if="loading" class="scheme-report__empty">正在查询目标完成情况...</div>
      <div v-else-if="!rows.length" class="scheme-report__empty">当前筛选范围暂无已生效的目标。请在“目标方案”中启用并关联此报表。</div>
      <div v-else class="scheme-report__table-wrap"><table><thead><tr><th v-if="reportCode !== 'target_store'">月份</th><th>门店</th><th v-if="reportCode === 'target_store'">月份</th><th>指标</th><th>目标对象</th><th>目标值</th><th>完成值</th><th>达成率</th><th>状态</th></tr></thead><tbody>
        <tr v-for="row in displayRows" :key="row.line_id"><td v-if="reportCode !== 'target_store'">{{ row.month_key }}</td><td>{{ row.store_name }}</td><td v-if="reportCode === 'target_store'">{{ row.month_key }}</td><td>{{ row.metric_name }}</td><td>{{ scopeLabel(row) }}</td><td>{{ formatValue(row.target_value, row.unit_code) }}</td><td>{{ row.actual_value === null ? '—' : formatValue(row.actual_value, row.unit_code) }}</td><td>{{ row.completion_rate === null ? '—' : `${row.completion_rate}%` }}</td><td>{{ row.coverage === 'covered' ? '已覆盖' : '历史未覆盖' }}</td></tr>
      </tbody></table></div>
    </section>
    <div v-if="showGuide" class="scheme-report__overlay" @click.self="showGuide = false"><section><header><h2>{{ title }}列名取值来源</h2><button type="button" @click="showGuide = false">×</button></header><p>目标值：从此报表引用的生效目标方案，按门店、月份、指标和商品范围读取。</p><p>完成值：业务成功后进入统一数据底层；退款、作废按统一指标口径处理。商品和分类按业务发生时归属统计。</p><p>达成率：完成值除以目标值。目标为零或历史尚未覆盖时显示“—”。</p><p>不同指标、全店、商品分类和单品的目标互不相加。</p></section></div>
  </div>
</template>

<script>
import * as XLSX from 'xlsx';
import OrganizationStoreScopePicker from '@/components/organization/OrganizationStoreScopePicker.vue';
import { schemeCatalog, schemeReport } from '@/api/target';

export default {
  name: 'TargetSchemeReport',
  components: { OrganizationStoreScopePicker },
  props: { reportCode: { type: String, required: true }, title: { type: String, required: true }},
  data() { const month = new Date().toISOString().slice(0, 7); return { startMonth: month, endMonth: month, storeIds: [], metricCode: '', catalog: { metrics: [] }, result: {}, rows: [], error: '', loading: false, showGuide: false }; },
  computed: { displayRows() { return this.rows.slice().sort((a, b) => this.reportCode === 'target_store' ? (a.store_name.localeCompare(b.store_name) || a.month_key.localeCompare(b.month_key)) : (a.month_key.localeCompare(b.month_key) || a.store_name.localeCompare(b.store_name))); } },
  async created() { try { const result = await schemeCatalog(); this.catalog = result.data || this.catalog; } catch (error) { this.error = error.message || '指标列表读取失败'; } this.query(); },
  methods: {
    loadScope() { return schemeCatalog(); },
    scopeLabel(row) { return row.scope_type === 'store' ? '全店' : `${row.scope_type === 'category' ? '分类' : '商品'}：${row.scope_name}`; },
    formatValue(value, unit) { return unit === 'fen' ? `¥${Math.round(Number(value) / 100).toLocaleString()}` : Number(value).toLocaleString(); },
    async query() {
      this.error = ''; this.loading = true;
      try { const response = await schemeReport({ report_code: this.reportCode, start_month: this.startMonth, end_month: this.endMonth, store_ids: this.storeIds.join(','), metric_keys: this.metricCode }); this.result = response.data || {}; this.rows = this.result.rows || []; } catch (error) { this.error = error.message || '目标数据查询失败'; this.rows = []; } finally { this.loading = false; }
    },
    exportRows() {
      // 导出直接使用本次后端查询返回的全部行和分精度原值，不再执行客户端业务计算。
      const values = [['月份', '门店', '指标', '目标对象', '目标值（分或个）', '完成值（分或个）', '达成率', '覆盖状态']];
      this.displayRows.forEach(row => values.push([row.month_key, row.store_name, row.metric_name, this.scopeLabel(row), row.target_value, row.actual_value === null ? '' : row.actual_value, row.completion_rate === null ? '' : row.completion_rate, row.coverage]));
      const book = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(book, XLSX.utils.aoa_to_sheet(values), '目标完成'); XLSX.writeFile(book, `${this.title}.xlsx`);
    }
  }
};
</script>

<style scoped>
.scheme-report { padding: 18px; color: #24344b; }
.scheme-report > header { display: flex; align-items: center; justify-content: space-between; }
.scheme-report h1 { font-size: 22px; margin: 0; }
.scheme-report__filter { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; padding: 16px; margin: 14px 0 10px; border-radius: 8px; background: white; }
.scheme-report__filter label { display: flex; align-items: center; gap: 5px; }
.scheme-report input, .scheme-report select { height: 33px; border: 1px solid #d4deea; border-radius: 5px; padding: 4px; }
.scheme-report button { border: 1px solid #cbd9e9; background: #f6faff; color: #1766b1; border-radius: 5px; padding: 7px 12px; cursor: pointer; }
.scheme-report__body { background: white; border-radius: 8px; padding: 14px; }
.scheme-report__analysis { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 12px; margin-bottom: 12px; }
.scheme-report__series { padding: 14px; background: #fff; border: 1px solid #e5ebf3; border-radius: 8px; }
.scheme-report__series h2 { font-size: 16px; margin: 0; }
.scheme-report__series p { color: #8794a5; margin: 6px 0 12px; }
.scheme-report__month-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; }
.scheme-report__month { display: grid; grid-template-columns: 1fr auto; gap: 5px; padding: 10px; background: #f4f8fd; border-radius: 6px; }
.scheme-report__month b { color: #246dba; }
.scheme-report__track { grid-column: 1 / -1; height: 5px; background: #dfe8f5; border-radius: 4px; }
.scheme-report__track i { display: block; height: 100%; background: #317ddd; border-radius: 4px; }
.scheme-report__meta { display: flex; justify-content: space-between; color: #79889a; padding: 3px 0 12px; }
.scheme-report__table-wrap { overflow: auto; max-height: calc(100vh - 255px); }
.scheme-report table { width: 100%; min-width: 890px; border-collapse: collapse; }
.scheme-report th, .scheme-report td { padding: 11px 8px; text-align: left; border-bottom: 1px solid #e7edf4; }
.scheme-report th { position: sticky; top: 0; background: #f2f6fb; color: #51657d; }
.scheme-report__empty { padding: 80px 20px; text-align: center; color: #8a97a8; }
.scheme-report__error { background: #fff2f2; color: #bd403b; padding: 10px; }
.scheme-report__overlay { position: fixed; inset: 0; z-index: 10000; background: rgba(19,36,58,.4); display: grid; place-items: center; }
.scheme-report__overlay section { width: min(540px, 90vw); background: white; border-radius: 9px; padding: 20px; }
.scheme-report__overlay header { display: flex; justify-content: space-between; }
</style>
