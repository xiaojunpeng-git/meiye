<template>
  <main class="growth-page">
    <header class="growth-head">
      <h1>成长统计</h1>
      <div class="growth-actions">
        <button @click="$router.push('/admin/growth/overview')">管理工作台</button
        ><button :disabled="busy || !result" data-operation="growth.report.export" @click="exportRows">
          导出当前查询
        </button>
      </div>
    </header>
    <section class="growth-panel growth-report-query">
      <OrganizationStoreScopePicker v-model="storeIds" :load-scope="loadScope" @change="scopeChanged" /><label
        v-if="form.report !== 'capabilities'"
        >从<input v-model="form.start" type="date" /></label
      ><label v-if="form.report !== 'capabilities'">到<input v-model="form.end" type="date" /></label
      ><label
        >报表<select v-model="form.report" @change="form.state = '';form.position_id=0;form.plan_id=0;form.source_id=0">
          <option v-for="(name, id) in catalog.reports" :key="id" :value="id">{{ name }}</option>
        </select></label
      ><label
        >员工<select v-model.number="form.employee_id">
          <option :value="0">全部</option>
          <option v-for="p in catalog.employees" :key="p.id" :value="Number(p.id)">{{ p.name }}</option>
        </select></label
      ><label v-if="['tasks','exams','capabilities'].includes(form.report)">岗位<select v-model.number="form.position_id"><option :value="0">全部</option><option v-for="p in catalog.positions" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select></label><label v-if="['tasks','exams'].includes(form.report)">培养计划<select v-model.number="form.plan_id"><option :value="0">全部</option><option v-for="p in catalog.plans" :key="p.id" :value="Number(p.id)">{{ p.title }}</option></select></label><label v-if="form.report==='exams'">练习 / 考试<select v-model.number="form.source_id"><option :value="0">全部</option><option v-for="p in catalog.exams" :key="p.id" :value="Number(p.id)">{{ p.title }}</option></select></label><label v-if="form.report !== 'points'"
        >状态<select v-model="form.state">
          <option value="">全部</option>
          <option v-for="(name, id) in catalog.states" :key="id" :value="id">{{ name }}</option>
        </select></label
      ><label
        >关键词<input v-model="form.keyword" placeholder="任务或来源名称" @keyup.enter="search"
      /></label>
      <div class="growth-actions">
        <button class="growth-primary" :disabled="busy" @click="search">查询</button
        ><button :disabled="busy" @click="reset">重置</button>
      </div>
      <p>日期含义：{{ catalog.date_meanings[form.report] }}</p>
    </section>
    <p v-if="error" class="growth-error">{{ error }}</p>
    <p v-if="notice" class="growth-success">{{ notice }}</p>
    <div v-if="result" class="growth-report-metrics">
      <article v-for="m in summary" :key="m.key">
        <span>{{ m.title }}</span
        ><strong>{{ m.value == null ? '无适用数据' : m.value }}</strong>
      </article>
    </div>
    <section v-if="result" class="growth-results">
      <div class="growth-scroll">
        <table class="growth-report-table">
          <thead>
            <tr>
              <th v-for="c in result.columns" :key="c.key" :title="c.value_source" :style="columnStyle(c)">
                {{ c.title }}
              </th>
            </tr>
            <tr v-if="result.table_layout.fixed_total" class="growth-total">
              <td v-for="(c, i) in result.columns" :key="c.key" :style="columnStyle(c)">
                {{ i === 0 ? '合计' : c.summable ? result.totals[c.key] : '—' }}
              </td>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in result.records" :key="r.row_key">
              <td v-for="c in result.columns" :key="c.key" :style="columnStyle(c)">
                <button v-if="c.drillable" class="growth-report-link" @click="drill(r)">{{ r[c.key] }}</button
                ><span v-else>{{ r[c.key] == null ? '—' : r[c.key] }}</span>
              </td>
            </tr>
            <tr v-if="!result.records.length">
              <td :colspan="result.columns.length" class="growth-empty">没有符合条件的记录</td>
            </tr>
          </tbody>
        </table>
      </div>
      <footer class="growth-pager">
        <span>共 {{ result.total }} 条 · 每页 20 条 · 第 {{ result.page }} / {{ result.total_pages }} 页</span
        ><button :disabled="result.page <= 1 || busy" @click="load(result.page - 1)">上一页</button
        ><button :disabled="result.page >= result.total_pages || busy" @click="load(result.page + 1)">
          下一页
        </button>
      </footer>
    </section>
    <p v-if="result">{{ result.totals.explanation }} · 数据更新 {{ result.data_as_of }}</p>
    <div v-if="detail" class="growth-modal">
      <section class="growth-dialog">
        <header class="growth-dialog-head">
          <h2>来源明细</h2>
          <button @click="detail = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p>{{ detail.record.title || (detail.record.issue && detail.record.issue.description) }}</p>
          <div v-for="item in detailRows" :key="item.key" class="growth-chapter">
            <strong>{{ item.title }}</strong>
            <p>{{ item.value }}</p>
          </div>
          <button v-if="detail.type === 'task'" @click="openSource">进入原业务处理</button>
        </div>
      </section>
    </div>
  </main>
</template>
<script>
import { growthDate, growthRead, growthWrite } from '@/api/growth';
import OrganizationStoreScopePicker from '@/components/organization/OrganizationStoreScopePicker.vue';
import Vue from 'vue';
import util from '@/libs/util';
const defaults = () => ({
  report: 'tasks',
  start: growthDate().slice(0, 7) + '-01',
  end: growthDate(),
  employee_id: 0, position_id: 0, plan_id: 0, source_id: 0,
  organization_id: 0,
  store_id: 0,
  state: '',
  keyword: ''
});
/** 表格只呈现后端布局、正式合计和精确来源参数；分页与导出保留已提交筛选。 */
export default {
  name: 'GrowthReports',
  components: { OrganizationStoreScopePicker },
  data: () => ({
    form: defaults(),
    submitted: null,
    storeIds: [],
    catalog: { positions:[],plans:[],exams:[],reports: {}, states: {}, employees: [], date_meanings: {} },
    result: null,
    detail: null,
    busy: false,
    error: '',
    notice: ''
  }),
  computed: {
    summary() {
      if (!this.result) return [];
      const labels = {
        assigned_people: '已分配人数',
        required_tasks: '应完成任务',
        completed_tasks: '任务完成数',
        completion_rate: '任务完成率',
        required_people: '应完成人数',
        all_completed_people: '全部完成人数',
        people_completion_rate: '人员全部完成率',
        expected_people: '应考人数',
        passed_people: '通过人数',
        pass_rate: '考试通过率',
        valid_issues: '有效整改问题',repeated_issue_groups:'反复问题组数',repeated_issues:'反复涉及问题数',
        closed_issues: '已关闭问题',
        close_rate: '整改关闭率',
        delta: '有效积分变动',
        pending_tasks: '待确认任务',
        overdue_tasks: '逾期任务',
        cancelled_tasks: '已作废任务',
        expected_capabilities: '应评能力',
        qualified_capabilities: '已达标能力',
        need_practice_capabilities: '待补练能力',
        not_evaluated_capabilities: '未评价能力'
      };
      return Object.keys(this.result.totals)
        .filter((k) => labels[k])
        .map((k) => ({ key: k, title: labels[k], value: this.result.totals[k] }));
    },
    detailRows() {
      if (!this.detail) return [];
      const r = this.detail.record.issue || this.detail.record;
      const names = {
        id: '记录编号',
        employee_name: '员工',
        store_name: '发生门店',
        state: '当前状态',
        conclusion: '正式结论',
        assigned_at: '分配时间',
        completed_at: '完成时间',
        due_at: '截止时间',
        result_type: '结果类型',
        result_id: '正式结果来源',
        description: '问题描述',
        reason: '原因',
        delta: '积分变动',
        source_type: '原业务',
        source_id: '来源编号',
        position_name: '现任岗位',
        evaluation_id: '鉴定记录编号',
        occurred_at: '最近鉴定时间'
      };
      return Object.keys(names)
        .filter((k) => r[k] != null)
        .map((k) => ({
          key: k,
          title: names[k],
          value: k.endsWith('_at')
            ? Number(r[k])
              ? new Date(Number(r[k]) * 1000).toLocaleString('zh-CN')
              : '—'
            : this.catalog.states[r[k]] || r[k]
        }));
    }
  },
  async created() {
    try {
      this.catalog = (await growthRead('report/filters')).data;
      this.form.start = this.catalog.date_defaults.start;
      this.form.end = this.catalog.date_defaults.end;
      if (this.$route.query.report) this.form.report = this.$route.query.report;
      for(const key of ['organization_id','store_id','standard_id','position_id','plan_id','employee_id'])if(this.$route.query[key])this.$set(this.form,key,Number(this.$route.query[key]));for(const key of ['start','end'])if(this.$route.query[key])this.$set(this.form,key,String(this.$route.query[key]));if(this.$route.query.store_ids)this.storeIds=[].concat(this.$route.query.store_ids).map(Number);if(this.$route.query.item_key)this.$set(this.form,'item_key',this.$route.query.item_key);
      this.search();
    } catch (e) {
      this.error = e.msg || e.message;
    }
  },
  methods: {
    loadScope() {
      return growthRead('report/filters');
    },
    scopeChanged(v) {
      this.form.organization_id = Number(v.orgId || 0);
      this.form.store_id = Number(v.storeId || 0);
    },
    columnStyle(c) {
      let left = 0;
      if (c.fixed === 'left') {
        for (const col of this.result.columns) {
          if (col.key === c.key) break;
          if (col.fixed === 'left') left += Number(col.width);
        }
      }
      return {
        minWidth: c.width + 'px',
        width: c.width + 'px',
        position: c.fixed === 'left' ? 'sticky' : undefined,
        left: c.fixed === 'left' ? left + 'px' : undefined,
        zIndex: c.fixed === 'left' ? 2 : undefined,
        backgroundColor: c.fixed === 'left' ? '#fff' : undefined
      };
    },
    search() {
      if(this.busy)return;
      this.submitted = { ...this.form, store_ids: [...this.storeIds] };
      this.load(1);
    },
    reset() {
      this.form = {
        ...defaults(),
        ...(this.catalog.date_defaults && {
          start: this.catalog.date_defaults.start,
          end: this.catalog.date_defaults.end
        })
      };
      this.storeIds = [];
      this.search();
    },
    async load(page) {
      this.busy = true;
      this.error = '';this.result=null;
      try {
        this.result = (await growthRead('report', { ...this.submitted, page })).data;
      } catch (e) {
        this.error = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    async drill(row) {
      if(this.busy)return;
      try {
        this.detail = (await growthRead('report/drill', { ...this.submitted, ...row.drill })).data;
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    openSource() {
      const t = this.detail.record;
      const view = 'tasks';
      this.$router.push({ path: '/admin/growth/' + view, query: { task_id: t.id } });
    },
    async exportRows() {
      this.busy = true;
      this.error = '';
      try {
        const r = (await growthWrite('report/export', this.submitted)).data;
        const token = Vue.prototype.__getToken() || util.cookies.get('token');
        const response = await fetch('/adminapi/growth/report/file?id=' + r.id, {
          headers: { 'Authori-zation': 'Bearer ' + token, 'X-Source': 'f76d38d0ee4f854f' }
        });
        if (!response.ok || (response.headers.get('content-type') || '').includes('json'))
          throw new Error('导出权限或文件状态已变化，请重新查询');
        const url = URL.createObjectURL(await response.blob());
        const a = document.createElement('a');
        a.href = url;
        a.download = '成长统计.csv';
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
        this.notice = '已导出 ' + r.row_count + ' 条，含完整合计与列名取值来源';
      } catch (e) {
        this.error = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    }
  }
};
</script>
<style src="./growth.css"></style>
<style scoped>
.growth-report-query {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: flex-end;
  padding: 14px;
}
.growth-report-query label {
  margin: 0;
  max-width: 170px;
  font-size: 12px;
}
.growth-report-query p {
  width: 100%;
  font-size: 12px;
}
.growth-report-metrics {
  display: flex;
  gap: 10px;
  overflow: auto;
  flex-shrink: 0;
}
.growth-report-metrics article {
  min-width: 125px;
  background: #fff;
  border: 1px solid #e3eae0;
  border-radius: 9px;
  padding: 12px 15px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.growth-report-metrics span {
  color: #8a9780;
  font-size: 12px;
}
.growth-report-metrics strong {
  font-size: 20px;
  color: #3d6d51;
}
.growth-report-table {
  table-layout: fixed;
}
.growth-report-table thead th {
  z-index: 5 !important;
}
.growth-total td {
  position: sticky;
  top: 47px;
  background: #f3f6ed !important;
  z-index: 4;
  font-weight: 600;
}
.growth-page .growth-report-link {
  padding: 0;
  border: 0;
  background: none;
  color: #286f56;
  white-space: normal;
  text-align: left;
  max-width: 260px;
}
</style>
