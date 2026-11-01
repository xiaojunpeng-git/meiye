<template>
  <div class="detail-page">
    <div class="head">
      <Button type="text" icon="ios-arrow-back" @click="goBack">返回经营看板</Button>
      <strong>经营明细 · {{ name }}</strong>
      <Button :loading="exporting" :disabled="loading" @click="exportList">导出</Button>
    </div>
    <Alert type="info" show-icon>{{ sourceNotice }}</Alert>
    <div class="meta">统计时间：{{ queryData }} · 合计：{{ formatMoney(total) }} 元</div>
    <div ref="result" class="result">
      <Table :height="tableHeight" :columns="columns" :data="displayRows" :loading="loading" no-data-text="暂无数据" />
    </div>
    <div class="page-wrap">
      <span>共 {{ listCount }} 条 · 每页 {{ limit }} 条 · 第 {{ page }} / {{ pages }} 页</span>
      <Button :disabled="loading || page <= 1" @click="onPage(page - 1)">上一页</Button>
      <Button :disabled="loading || page >= pages" @click="onPage(page + 1)">下一页</Button>
    </div>
  </div>
</template>
<script>
import { businessDashboardMoneyDetail } from '@/api/store';
import Setting from '@/setting';
import formatBusinessMoney from '@/utils/formatBusinessMoney';
import toExcel from '@/utils/Excel';

/** 服务端返回字段、合计及完整导出；界面只格式化整元，不修改事实或累计金额。 */
export default {
  name: 'business_money_detail',
  data() {
    return { loading: false, exporting: false, list: [], total: '0.00', listCount: 0, page: 1, limit: 20,
      queryData: '', orgId: 0, storeId: 0, metric: 'cash_performance', name: '', sourceNotice: '',
      columnMetadata: [], tableHeight: 360, requestId: 0 };
  },
  computed: {
    pages() { return Math.max(1, Math.ceil(this.listCount / this.limit)); },
    displayRows() { return this.columnMetadata.length ? [{ biz_time_text: '合计金额', amount: this.total }, ...this.list] : []; },
    columns() {
      return this.columnMetadata.map(column => ({ ...column,
        render: (h, { row }) => h('span', column.value_type === 'money' ? this.formatMoney(row[column.key]) : (row[column.key] || '—')),
      }));
    },
  },
  created() { this.applyQuery(); this.loadList(); },
  mounted() { this.resize(); window.addEventListener('resize', this.resize); },
  beforeDestroy() { ++this.requestId; window.removeEventListener('resize', this.resize); },
  watch: { '$route.query': function () { this.applyQuery(); this.page = 1; this.loadList(); } },
  methods: {
    formatMoney: formatBusinessMoney,
    resize() { if (this.$refs.result) this.tableHeight = Math.max(140, this.$refs.result.clientHeight); },
    applyQuery() {
      const q = this.$route.query || {};
      this.queryData = q.data || ''; this.orgId = Number(q.org_id || 0);
      this.storeId = Number(q.store_id || 0); this.metric = q.metric || 'cash_performance';
    },
    params() { return { data: this.queryData, org_id: this.orgId, store_id: this.storeId,
      metric: this.metric, page: this.page, limit: this.limit }; },
    async loadList() {
      const request = ++this.requestId;
      this.loading = true;
      try {
        const res = await businessDashboardMoneyDetail(this.params());
        if (request !== this.requestId) return;
        const d = res.data || {};
        this.list = d.list || []; this.total = d.total; this.listCount = Number(d.list_count || 0);
        this.name = d.name || ''; this.sourceNotice = d.source_notice || '';
        this.columnMetadata = d.columns || [];
        if (this.page > this.pages) { this.page = this.pages; this.loadList(); return; }
        this.$nextTick(this.resize);
      } catch (err) {
        if (request !== this.requestId) return;
        this.list = []; this.total = '0.00'; this.listCount = 0;
        this.$Message.error((err && err.msg) || '加载失败');
      } finally { if (request === this.requestId) this.loading = false; }
    },
    async exportList() {
      this.exporting = true;
      // 冻结导出时的条件；不从当前页列表拼出不完整文件，也不受翻页影响。
      const params = { ...this.params(), export: 1 };
      try {
        const res = await businessDashboardMoneyDetail(params); const d = res.data;
        const columns = d.columns;
        const rows = [{ biz_time_text: '合计金额', amount: d.total }, ...d.list];
        toExcel({ th: columns.map(c => c.title), data: rows.map(row => columns.map(c => row[c.key] == null ? '' : row[c.key])),
          fileName: `${d.name}_经营明细`, sheetName: '经营明细' });
      } catch (err) { this.$Message.error((err && err.msg) || '导出失败'); }
      finally { this.exporting = false; }
    },
    onPage(page) { this.page = page; this.loadList(); },
    goBack() {
      this.$router.push({ path: `${Setting.roterPre}/store/statistics`, query: {
        data: this.queryData, org_id: this.orgId || undefined, store_id: this.storeId || undefined, metric: this.metric,
      } }).catch(() => {});
    },
  },
};
</script>
<style scoped lang="less">
.detail-page { height: ~"calc(100vh - 160px)"; min-height: 330px; display: flex; flex-direction: column; padding: 16px; background: white; }
.head { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
.head > button:last-child { margin-left: auto; }
.meta { padding: 8px 0 12px; color: #515a6e; }
.result { flex: 1; min-height: 0; overflow: hidden; }
/* 合计来自服务端，固定在表头下；滚动明细时始终可见。 */
.result /deep/ .ivu-table-body tr:first-child td { position: sticky; top: 0; z-index: 1; background: #eaf2ff; font-weight: 600; }
.page-wrap { flex-shrink: 0; border-top: 1px solid #e8eaec; padding: 12px 0 0; display: flex; justify-content: flex-end; align-items: center; gap: 12px; background: white; }
</style>
