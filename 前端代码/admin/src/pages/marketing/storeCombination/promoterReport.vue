<template>
  <main class="promoter-report">
    <header class="report-heading"><div><h1>推广人统计表</h1><p>按已支付拼团订单统计当前有推广资格会员的推广业绩</p></div><button type="button" @click="notesOpen = true">列名取值来源</button></header>
    <section class="report-query" aria-label="推广人统计查询条件">
      <OrganizationStoreScopePicker v-model="draft.store_ids" selection-mode="store_multiple" :load-scope="loadScope" empty-label="全部授权门店" clear-label="全部授权门店" />
      <label>支付日期 <input v-model="draft.start_date" type="date" aria-label="支付开始日期"></label><span>至</span><input v-model="draft.end_date" type="date" aria-label="支付结束日期">
      <div class="activity-picker"><button type="button" @click="activityOpen = !activityOpen">{{ draft.activity_ids.length ? `已选 ${draft.activity_ids.length} 个团` : '全部拼团' }} ▾</button>
        <section v-if="activityOpen" class="activity-menu"><input v-model.trim="activityKeyword" placeholder="搜索拼团名称或编号" aria-label="搜索拼团"><div><label v-for="activity in visibleActivities" :key="activity.id"><input v-model="draft.activity_ids" type="checkbox" :value="activity.id">{{ activity.title }} <small>#{{ activity.id }}</small></label><p v-if="!visibleActivities.length">暂无匹配活动</p></div><button type="button" @click="draft.activity_ids = []">清空选择</button><button type="button" @click="activityOpen = false">完成</button></section>
      </div>
      <input v-model.trim="draft.promoter_keyword" class="member-search" placeholder="会员姓名或手机号" aria-label="搜索推广会员" @keyup.enter="applyQuery">
      <button type="button" class="primary" :disabled="loading" @click="applyQuery">查询</button><button type="button" :disabled="loading" @click="refresh">刷新</button>
      <button type="button" :disabled="exporting || loading" @click="exportCurrent">{{ exporting ? '导出中…' : (tab === 'summary' ? '导出汇总表' : '导出明细表') }}</button>
    </section>
    <nav class="report-tabs" aria-label="报表页签"><button type="button" :class="{ active: tab === 'summary' }" @click="showSummary">统计表</button><button type="button" :class="{ active: tab === 'details' }" @click="showAllDetails">明细表</button><span v-if="drillLabel" class="drill-label">{{ drillLabel }} <button type="button" @click="clearDrill">清除下钻</button></span></nav>
    <p v-if="error" class="report-error" role="alert">{{ error }}</p>
    <section class="report-result" :aria-label="tab === 'summary' ? '推广人统计表' : '推广业绩明细表'">
      <div class="report-scroll"><table><thead><tr><th v-for="column in columns" :key="column.key" :style="{ minWidth: (column.width || 120) + 'px' }">{{ column.title }}</th></tr></thead><tbody>
        <tr v-if="tab === 'summary'" class="total-row"><td v-for="(column, index) in columns" :key="column.key">{{ index === 0 ? '合计' : (column.summable ? display((data.summary || {})[column.key], column.money) : '—') }}</td></tr>
        <tr v-else class="total-row"><td v-for="(column, index) in columns" :key="column.key">{{ index === 0 ? '合计' : (column.summable ? display((data.summary || {})[column.key], column.money) : '—') }}</td></tr>
        <tr v-for="row in data.list || []" :key="tab === 'summary' ? row.promoter_id : row.id"><td v-for="column in columns" :key="column.key">
          <button v-if="tab === 'summary' && column.drill" type="button" class="metric-link" :aria-label="`${row.promoter_name} ${column.title} ${display(row[column.key], column.money)}，查看明细`" @click="drill(row, column)">{{ display(row[column.key], column.money) }}</button>
          <span v-else>{{ display(row[column.key], column.money) }}</span>
        </td></tr>
        <tr v-if="!(data.list || []).length"><td :colspan="columns.length" class="empty">{{ loading ? '正在查询…' : (error ? '查询未完成' : '暂无符合条件的数据') }}</td></tr>
      </tbody></table></div>
      <footer class="report-pagination"><span>共 {{ data.count || 0 }} 条 · 每页 20 条 · 第 {{ data.page || page }} / {{ pageCount }} 页</span><button type="button" :disabled="loading || page <= 1" @click="load(page - 1)">上一页</button><button type="button" :disabled="loading || page >= pageCount" @click="load(page + 1)">下一页</button></footer>
    </section>
    <div v-if="notesOpen" class="notes-backdrop" @click.self="notesOpen = false"><section class="notes-dialog" role="dialog" aria-modal="true" aria-label="列名取值来源"><header><h2>列名取值来源</h2><button type="button" aria-label="关闭" @click="notesOpen = false">×</button></header><ul><li v-for="(note, index) in notes" :key="index">{{ note }}</li></ul></section></div>
  </main>
</template>

<script>
import dayjs from 'dayjs';
import OrganizationStoreScopePicker from '@/components/organization/OrganizationStoreScopePicker.vue';
import formatBusinessMoney from '@/utils/formatBusinessMoney';
import downloadExcel from '@/utils/combinationDashboardExcel';
import { getPromoterOptions, getPromoterSummary, getPromoterDetails, exportPromoterSummary, exportPromoterDetails } from '@/api/combinationPromoterReport';

export default {
  name: 'CombinationPromoterReport', components: { OrganizationStoreScopePicker },
  data() {
    const date = { start_date: dayjs().startOf('month').format('YYYY-MM-DD'), end_date: dayjs().format('YYYY-MM-DD') };
    return { draft: { ...date, store_ids: [], activity_ids: [], promoter_keyword: '' }, applied: { ...date, store_ids: [], activity_ids: [], promoter_keyword: '' },
      tab: 'summary', page: 1, data: {}, summaryData: {}, detailData: {}, detailFilter: {}, loading: false, exporting: false, error: '', notesOpen: false,
      activities: [], activityKeyword: '', activityOpen: false, options: null, requestId: 0, drillLabel: '' };
  },
  computed: {
    columns() { return Array.isArray(this.data.columns) ? this.data.columns : []; },
    notes() { return this.data.source_notes || this.summaryData.source_notes || []; },
    pageCount() { return Math.max(1, Math.ceil(Number(this.data.count || 0) / 20)); },
    visibleActivities() { const keyword = this.activityKeyword.toLowerCase(); return this.activities.filter(item => !keyword || String(item.title).toLowerCase().includes(keyword) || String(item.id).includes(keyword)); }
  },
  mounted() { this.loadOptions(); this.load(1); },
  methods: {
    async loadOptions() {
      try { const response = await getPromoterOptions(); this.options = response.data; this.activities = response.data.activities || []; } catch (error) { this.error = (error && (error.msg || error.message)) || '筛选范围读取失败'; }
    },
    async loadScope() { if (!this.options) await this.loadOptions(); return (this.options && this.options.scope) || { tree: [] }; },
    filters() { return { ...this.applied, activity_ids: this.applied.activity_ids.join(','), store_ids: this.applied.store_ids.join(',') }; },
    detailParams() { return { ...this.filters(), ...this.detailFilter }; },
    // 提交筛选后重置页码与下钻；翻页只改变页码，绝不丢失已提交的条件。
    applyQuery() { this.applied = { ...this.draft, store_ids: [...this.draft.store_ids], activity_ids: [...this.draft.activity_ids] }; this.tab = 'summary'; this.detailFilter = {}; this.drillLabel = ''; this.data = {}; this.load(1); },
    refresh() { this.load(this.page); },
    async load(page) {
      this.page = page; this.loading = true; this.error = ''; const id = ++this.requestId;
      try {
        const response = this.tab === 'summary' ? await getPromoterSummary({ ...this.filters(), page }) : await getPromoterDetails({ ...this.detailParams(), page });
        if (id !== this.requestId) return; this.data = response.data; this.page = response.data.page || page;
        if (this.tab === 'summary') this.summaryData = response.data; else this.detailData = response.data;
      } catch (error) { if (id === this.requestId) this.error = (error && (error.msg || error.message)) || '查询失败'; } finally { if (id === this.requestId) this.loading = false; }
    },
    showSummary() { if (this.tab === 'summary') return; this.tab = 'summary'; this.detailFilter = {}; this.drillLabel = ''; this.data = {}; this.load(1); },
    showAllDetails() { this.tab = 'details'; this.detailFilter = {}; this.drillLabel = ''; this.data = {}; this.load(1); },
    drill(row, column) {
      const map = column.drill;
      this.detailFilter = { dimension: map.dimension, dimension_id: row[map.dimension_id_field], status: map.status, people_only: map.people_only };
      this.drillLabel = `${row.promoter_name} · ${column.title}`; this.tab = 'details'; this.data = {}; this.load(1);
    },
    clearDrill() { this.detailFilter = {}; this.drillLabel = ''; this.data = {}; this.load(1); },
    async exportCurrent() {
      this.exporting = true; this.error = '';
      try {
        if (this.tab === 'summary') {
          const blob = await exportPromoterSummary(this.filters());
          if (blob.type && blob.type.includes('json')) { const failure = JSON.parse(await blob.text()); throw new Error(failure.msg || '导出失败'); }
          const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = '推广人统计汇总.csv'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
        } else { const response = await exportPromoterDetails(this.detailParams()); downloadExcel(response.data); }
      } catch (error) { this.error = (error && (error.msg || error.message)) || '导出失败'; } finally { this.exporting = false; }
    },
    // 服务端以分精度计算，页面沿用统一整元展示；导出保留原始金额字符串。
    display(value, money) { if (value == null || value === '') return '—'; return money ? formatBusinessMoney(value) : String(value); }
  }
};
</script>

<style scoped>
.promoter-report{display:flex;flex-direction:column;min-height:calc(100vh - 130px);padding:18px 20px;background:#f5f7fa;color:#25364b}.report-heading,.report-query,.report-tabs,.report-pagination{display:flex;align-items:center;gap:10px}.report-heading{justify-content:space-between;margin-bottom:14px}.report-heading h1{font-size:20px;margin:0}.report-heading p{color:#6d7b8e;margin:3px 0 0}.report-query,.report-tabs,.report-result{background:#fff;border:1px solid #e3e9f1;border-radius:7px}.report-query{padding:12px;flex-wrap:wrap}.report-query input,.activity-picker>button,.report-query>button,.report-heading button,.report-pagination button,.report-tabs button{border:1px solid #dce4ee;border-radius:5px;background:#fff;color:#34465b;padding:7px 11px}.report-query input[type=date]{width:145px}.report-query .member-search{width:180px}.report-query .primary{background:#1674d8;color:#fff;border-color:#1674d8}.activity-picker{position:relative}.activity-menu{position:absolute;z-index:20;top:36px;left:0;width:280px;background:#fff;border:1px solid #dce4ee;box-shadow:0 8px 22px #0002;padding:10px}.activity-menu>input{width:100%}.activity-menu>div{max-height:220px;overflow:auto}.activity-menu label{display:block;padding:5px}.activity-menu small{color:#8290a2}.report-tabs{margin-top:12px;padding:0 10px}.report-tabs>button{border:0;border-radius:0;padding:12px 16px}.report-tabs>button.active{color:#1674d8;border-bottom:2px solid #1674d8}.drill-label{margin-left:auto;color:#64758b}.drill-label button{color:#1674d8}.report-error{color:#c33535;margin:8px 0}.report-result{display:flex;flex-direction:column;flex:1;min-height:0;margin-top:10px}.report-scroll{overflow:auto;flex:1;min-height:0}.report-scroll table{border-collapse:collapse;width:100%;min-width:max-content}.report-scroll th{position:sticky;top:0;z-index:2;background:#edf3f9;color:#4e637d;text-align:left}.report-scroll th,.report-scroll td{padding:10px 12px;border-bottom:1px solid #edf0f5;white-space:nowrap}.report-scroll .total-row{background:#eef6ff;font-weight:bold}.metric-link{padding:0;border:0;background:transparent;color:#1674d8;cursor:pointer}.empty{text-align:center;color:#8793a3;padding:60px!important}.report-pagination{justify-content:flex-end;padding:10px;border-top:1px solid #e4eaf2}.notes-backdrop{position:fixed;inset:0;background:#0006;display:flex;align-items:center;justify-content:center;z-index:3000}.notes-dialog{width:min(680px,90vw);max-height:80vh;overflow:auto;background:#fff;border-radius:8px;padding:18px}.notes-dialog header{display:flex;justify-content:space-between}.notes-dialog h2{font-size:18px}.notes-dialog header button{background:none;border:0;font-size:24px;cursor:pointer}.notes-dialog li{padding:6px 0}
</style>
