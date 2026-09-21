<template>
  <main class="metric-registry">
    <header class="heading">
      <div><h1>指标注册表</h1><p>统一数据底层中已登记的指标合同，以及它们的当前基础查询状态。</p></div>
      <button :disabled="loading" @click="reload">刷新</button>
    </header>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <section class="box">
      <p class="muted">这里展示指标口径、分层、对象和基础可用性。某个具体问题是否可执行，仍由当前权限、对象、日期、条件和查询方式共同决定。</p>
      <div v-if="loading" class="loading">正在读取指标注册表…</div>
      <template v-else-if="registry">
        <div class="meta"><span>登记指标：{{ registry.items.length }} 项</span><span>注册表版本：{{ registry.registry_version }}</span><span>数据覆盖起点：{{ registry.coverage_start }}</span></div>
        <label>筛选指标<input v-model.trim="keyword" type="search" placeholder="按指标名称、代码或口径搜索" aria-label="筛选指标"></label>
        <p class="muted">匹配 {{ items.length }} 项。金额底层以分计算，页面按系统统一规则展示为元。</p>
        <article v-for="item in items" :key="item.metric_code" class="metric">
          <div class="metric-head"><div><h2>{{ item.name }}</h2><code>{{ item.metric_code }}</code></div><div class="badges"><span>{{ metricKindLabel(item.metric_kind) }}</span><span>{{ timeSemanticsLabel(item.time_semantics) }}</span><span :class="item.ai_query_ready ? 'ready' : 'limited'">{{ readinessLabel(item.ai_query_ready) }}</span><span v-if="item.category_supported">支持分类筛选</span></div></div>
          <p>{{ item.summary }}</p>
          <dl>
            <template v-if="item.include"><dt>计入</dt><dd>{{ item.include }}</dd></template>
            <template v-if="item.exclude"><dt>不计入</dt><dd>{{ item.exclude }}</dd></template>
            <template v-if="item.timing"><dt>统计时间</dt><dd>{{ item.timing }}</dd></template>
            <template v-if="item.note"><dt>口径说明</dt><dd>{{ item.note }}</dd></template>
            <dt>支持查询</dt><dd>{{ item.query_shapes.map(shapeLabel).join('、') }}</dd>
            <dt>可分析对象</dt><dd>{{ item.analysis_objects.map(object => object.label).join('、') }}</dd>
            <template v-if="item.overview_sections.length"><dt>概览归属</dt><dd>{{ item.overview_sections.map(overviewLabel).join('、') }}</dd></template>
            <dt>额外条件</dt><dd>{{ item.business_filters.length ? item.business_filters.map(filterLabel).join('、') : '无；仍始终受当前报表数据权限限制' }}</dd>
            <template v-if="!item.ai_query_ready"><dt>暂不可执行原因</dt><dd>{{ item.readiness_reasons.map(readinessReasonLabel).join('、') || '当前能力尚未就绪' }}</dd></template>
            <dt>指标版本</dt><dd><code>{{ item.metric_version }}</code></dd>
          </dl>
        </article>
        <p v-if="items.length === 0" class="empty">没有匹配的已注册指标。</p>
      </template>
    </section>
  </main>
</template>

<script>
import request from '@/api/moheAi';

export default {
  name: 'MoheAiMetricRegistry',
  data() { return { registry: null, loading: true, error: '', keyword: '' }; },
  computed: {
    items() {
      const keyword = this.keyword.toLowerCase();
      return ((this.registry && this.registry.items) || []).filter(item => !keyword || [item.name, item.metric_code, item.summary, item.note, ...item.analysis_objects.map(object => object.label)].join(' ').toLowerCase().includes(keyword));
    }
  },
  created() { this.reload(); },
  methods: {
    async reload() {
      this.loading = true; this.error = '';
      try { this.registry = await request('GET', '/metric-registry'); }
      catch (error) { this.error = error.message || '指标注册表读取失败，请刷新后重试。'; }
      finally { this.loading = false; }
    },
    // These labels describe server-projected metadata only. They never decide
    // a query, formula, permission range, or natural-language interpretation.
    metricKindLabel(value) { return { atomic: '原子指标', derived: '派生指标' }[value] || '未分类指标'; },
    timeSemanticsLabel(value) { return { period_flow: '期间发生量', current_state: '当前状态', as_of_date: '截至查询日状态' }[value] || '时间语义待完善'; },
    readinessLabel(ready) { return ready ? '基础查询已就绪' : '基础查询受限'; },
    readinessReasonLabel(value) { return { HISTORICAL_SERVICE_COVERAGE_INCOMPLETE: '历史服务事实覆盖尚未完整' }[value] || value; },
    overviewLabel(item) { return `${item.section}（${item.object_label}）`; },
    shapeLabel(value) { return { summary: '汇总', trend: '趋势', ranking: '排行', comparison: '对比', threshold_count: '阈值计数', condition_count: '条件计数', condition_list: '条件名单' }[value] || value; },
    filterLabel(value) { return { selection_ref: '已授权人员范围' }[value] || value; }
  }
};
</script>

<style scoped>
.metric-registry{padding:20px;color:#263b50;max-width:1440px;margin:auto}.heading{display:flex;justify-content:space-between;align-items:center;gap:16px}h1{font-size:23px;margin:0}h2{font-size:17px;margin:0}.heading p,.muted{color:#718096;font-size:13px;line-height:1.7}.box{background:#fff;border:1px solid #e1e7ef;border-radius:10px;padding:22px;margin-top:16px}.meta{display:flex;gap:20px;flex-wrap:wrap;margin:18px 0;padding:12px;background:#f5f8fc;color:#526579;font-size:13px}.metric{border:1px solid #e1e7ef;border-radius:8px;padding:16px;margin:12px 0}.metric-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.metric p{line-height:1.7}.badges{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.badges span{font-size:12px;color:#315e9a;background:#eef4fd;padding:4px 8px;border-radius:12px;white-space:nowrap}code{font-size:12px;color:#607184;overflow-wrap:anywhere}dl{display:grid;grid-template-columns:88px minmax(0,1fr);gap:8px 12px;margin:14px 0 0;font-size:14px;line-height:1.65}dt{color:#718096}dd{margin:0}.loading,.empty{padding:24px;color:#718096}label{display:block;max-width:520px;font-size:14px}input{display:block;width:100%;box-sizing:border-box;padding:9px 11px;margin-top:6px;border:1px solid #cbd5e1;border-radius:5px;background:#fff;color:inherit;font:inherit}button{border:1px solid #d5dfeb;border-radius:6px;background:#fff;padding:8px 14px;cursor:pointer;color:#304861;font:inherit}button:disabled{opacity:.45;cursor:not-allowed}.error{background:#fff2ef;color:#9a3412;padding:12px}@media(max-width:700px){.metric-registry{padding:12px}.box{padding:15px}.heading,.metric-head{align-items:flex-start;flex-direction:column}.badges{justify-content:flex-start}dl{grid-template-columns:1fr;gap:2px 0}dd{margin-bottom:8px}}
</style>
