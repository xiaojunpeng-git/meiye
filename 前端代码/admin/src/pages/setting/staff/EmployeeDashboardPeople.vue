<template>
  <section class="dashboard-people">
    <header>
      <div><h3>人员管理 · {{ descriptor.label || '员工看板明细' }}</h3><p>{{ filters.start_date }} 至 {{ filters.end_date }} · 岗位：{{ filters.role || '全部岗位' }} · 保留看板查询的组织范围</p></div>
      <Button @click="returnToDashboard">返回员工看板</Button>
    </header>
    <div class="people-query"><Input v-model="keyword" placeholder="员工姓名或员工ID" clearable @on-enter="search" /><Button type="primary" @click="search">查询</Button><Button :loading="exporting" @click="exportRows">导出</Button></div>
    <p class="people-note">按员工去重；年龄、工龄与看板使用同一档案和截止日。期间流失保留离职记录，可能包含已重新入职的人员。</p>
    <p v-if="error" role="status" class="people-error">{{ error }}</p>
    <div class="people-table"><Table :columns="columns" :data="rows" :loading="loading" border no-data-text="当前条件暂无人员" /></div>
    <footer><span>共 {{ total }} 条 · 每页 20 条 · 第 {{ page }} / {{ pages }} 页</span><Button :disabled="loading || page <= 1" @click="load(page - 1)">上一页</Button><Button :disabled="loading || page >= pages" @click="load(page + 1)">下一页</Button></footer>
  </section>
</template>
<script>
import request from '@/plugins/request';
import exportExcel from '@/utils/newToExcel.js';
/** 人员管理中的看板下钻视图：所有筛选、分页和导出均由同一后端人群服务执行。 */
export default {
  name: 'EmployeeDashboardPeople',
  data () { return { keyword: '', submittedKeyword: '', rows: [], columns: [], total: 0, page: 1, loading: false, exporting: false, error: '', sequence: 0 } },
  computed: {
    descriptor () { try { return JSON.parse(String(this.$route.query.employee_drill || '{}')) } catch (_) { return {} } },
    filters () { return this.descriptor.filters || {} },
    pages () { return Math.max(1, Math.ceil(this.total / 20)) }
  },
  watch: { '$route.query.employee_drill': { immediate: true, handler () { this.keyword = ''; this.submittedKeyword = ''; this.load(1) } } },
  beforeDestroy () { this.sequence++ },
  methods: {
    /** 查询仅传白名单条件；URL中即使伪造组织或岗位，也必须通过后端实时权限裁剪。 */
    params (page, limit) {
      const params = { page, limit, keyword: this.submittedKeyword };
      ['start_date', 'end_date', 'store_ids', 'role', 'kind', 'bucket'].forEach(k => { if (this.filters[k] !== undefined) params[k] = this.filters[k] });
      return params;
    },
    fetchRows (page, limit, fixed = this.params(page, limit)) { return request({ url: 'report/employee-dashboard/people', method: 'get', params: { ...fixed, page, limit } }) },
    search () { this.submittedKeyword = this.keyword.trim(); this.load(1) },
    async load (page) {
      const sequence = ++this.sequence; this.loading = true; this.error = '';
      try {
        const response = await this.fetchRows(page, 20); if (sequence !== this.sequence) return;
        const data = response.data; this.rows = data.list || []; this.total = data.count; this.page = data.page;
        this.columns = (data.columns || []).map(c => ({ ...c, minWidth: c.key === 'store_names' || c.key === 'position_names' ? 180 : 110, tooltip: true }));
      } catch (e) { if (sequence !== this.sequence) return; this.rows = []; this.total = 0; this.page = 1; this.error = e.msg || e.message || '读取人员明细失败'; }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    returnToDashboard () { this.$router.push({ path: '/admin/report/employee-dashboard', query: this.descriptor.returnFilters || {} }) },
    async exportRows () {
      this.exporting = true;
      try {
        const fixed = this.params(1, 200); const all = []; let columns = []; let page = 1; let count = 0;
        do { const { data } = await this.fetchRows(page, 200, fixed); if (!columns.length) columns = data.columns || []; all.push(...data.list); count = data.count; if (!data.list.length) break; page++; } while (all.length < count);
        exportExcel(columns.map(c => c.title), columns.map(c => c.key), '员工看板人员明细', all);
      } catch (e) { this.$Message.error(e.msg || e.message || '导出失败') }
      finally { this.exporting = false }
    }
  }
};
</script>
<style scoped>
.dashboard-people{height:calc(100vh - 145px);min-height:420px;display:flex;flex-direction:column;padding:16px;background:white}.dashboard-people header{display:flex;align-items:center;justify-content:space-between;gap:12px}.dashboard-people h3{font-size:16px;margin:0}.dashboard-people p{font-size:12px;color:#718096;margin:8px 0}.people-query{display:flex;gap:8px;margin:8px 0}.people-query .ivu-input-wrapper{width:260px}.people-table{flex:1;min-height:0;overflow:auto}.dashboard-people footer{display:flex;align-items:center;justify-content:flex-end;gap:10px;border-top:1px solid #e8eaec;padding-top:12px;background:#fff}.people-error{color:#bb534b!important}
/* 报表结果在独立容器滚动，表头与底部分页保持可见。 */
.people-table ::v-deep .ivu-table-header{position:sticky;top:0;z-index:2}
</style>
