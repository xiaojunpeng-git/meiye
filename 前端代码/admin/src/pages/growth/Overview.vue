<template>
  <main class="growth-page">
    <header class="growth-head">
      <h1>成长工作台</h1>
      <div class="growth-actions">
        <button @click="$router.push('/admin/growth/reports')">成长统计与导出</button
        ><button @click="$router.push('/admin/growth/business')">经营观察与复盘</button>
      </div>
    </header>
    <div class="growth-toolbar growth-overview-filters"><OrganizationStoreScopePicker v-model="storeIds" :load-scope="loadScope" @change="scopeChanged" /><label>员工<select v-model.number="filters.employee_id"><option :value="0">全部</option><option v-for="e in catalog.employees" :key="e.id" :value="Number(e.id)">{{ e.name }}</option></select></label><label>岗位<select v-model.number="filters.position_id"><option :value="0">全部</option><option v-for="p in catalog.positions" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select></label><label>培养计划<select v-model.number="filters.plan_id"><option :value="0">全部</option><option v-for="p in catalog.plans" :key="p.id" :value="Number(p.id)">{{ p.title }}</option></select></label>
      <label>从<input v-model="start" type="date" /></label
      ><label>到<input v-model="end" type="date" /></label
      ><button class="growth-primary" :disabled="busy" @click="load">查询</button>
    </div>
    <p v-if="error" class="growth-error">{{ error }}</p>
    <section v-if="data" class="growth-overview-body">
      <div class="growth-overview-cards">
        <button v-for="m in cards" :key="m.title" @click="report(m.report)">
          <span>{{ m.title }}</span
          ><strong>{{ m.value == null ? '无适用数据' : m.value }}</strong
          ><small>查看对应明细 →</small>
        </button>
      </div>
      <div class="growth-overview-columns">
        <section class="growth-panel">
          <h2>待点评 / 阅卷 / 鉴定</h2>
          <article v-for="t in data.pending" :key="t.id" class="growth-chapter">
            <strong>{{ t.title }}</strong>
            <p>{{ t.employee_name }} · {{ t.state_text }}</p>
            <button @click="report('tasks')">进入任务明细</button>
          </article>
          <p v-if="!data.pending.length">当前范围没有待确认事项</p>
        </section>
        <section class="growth-panel">
          <h2>待整改问题</h2>
          <article v-for="r in data.open_issues" :key="r.id" class="growth-chapter">
            <strong>{{ r.title }}</strong>
            <p>{{ r.store_name }} · {{ r.employee_name }}</p>
            <button @click="report('issues')">查看整改记录</button>
          </article>
          <p v-if="!data.open_issues.length">当前范围没有未处理整改</p>
        </section>
        <section class="growth-panel"><h2>反复出现的问题</h2><article v-for="r in data.repeat_groups" :key="r.latest_issue_id" class="growth-chapter"><strong>{{ r.store_name }} · {{ r.item_title }}</strong><p>当前筛选内出现 {{ r.occurrences }} 次</p><button @click="repeatReport(r)">查看这一组原问题</button></article><p v-if="!data.repeat_groups.length">当前范围未发现反复问题</p></section>
        <section class="growth-panel">
          <h2>最近培养计划</h2>
          <article v-for="p in data.plans" :key="p.id" class="growth-chapter">
            <strong>{{ p.title }}</strong>
            <p>
              {{ p.completed_tasks }} / {{ p.required_tasks }} 必做任务 · {{ p.progress || '无适用任务' }}
            </p>
            <button @click="$router.push('/admin/growth/plans')">进入培养计划</button>
          </article>
          <p v-if="!data.plans.length">尚无培养计划</p>
        </section>
      </div>
      <p>日期按任务分配 / 问题发现时间筛选，结果读取当前正式状态。数据更新 {{ data.data_as_of }}</p>
    </section>
  </main>
</template>
<script>
import OrganizationStoreScopePicker from '@/components/organization/OrganizationStoreScopePicker.vue';
import { growthDate, growthRead } from '@/api/growth';
/** 工作台数值全部来自同一成长统计服务，操作回到原业务。 */
export default {
  name: 'GrowthOverview',components:{OrganizationStoreScopePicker},
  data: () => ({
    start: growthDate().slice(0, 7) + '-01',
    end: growthDate(),
    data: null,storeIds:[],catalog:{employees:[],positions:[],plans:[]},filters:{organization_id:0,store_id:0,employee_id:0,position_id:0,plan_id:0},
    busy: false,
    error: ''
  }),
  computed: {
    cards() {
      if (!this.data) return [];
      const d = this.data;
      return [
        { title: '任务完成率', value: d.tasks.completion_rate, report: 'tasks' },
        { title: '人员全部完成率', value: d.tasks.people_completion_rate, report: 'tasks' },
        { title: '待确认任务', value: d.tasks.pending_tasks, report: 'tasks' },
        { title: '逾期任务', value: d.tasks.overdue_tasks, report: 'tasks' },
        { title: '考试通过率', value: d.exams.pass_rate, report: 'exams' },
        { title: '整改关闭率', value: d.issues.close_rate, report: 'issues' },
        { title: '待补练能力', value: d.capabilities.need_practice_capabilities, report: 'capabilities' },
        { title: '未评价能力', value: d.capabilities.not_evaluated_capabilities, report: 'capabilities' }
      ];
    }
  },
  async created() {
    try {
      const r = (await growthRead('report/filters')).data;
      this.catalog=r;this.start = r.date_defaults.start;
      this.end = r.date_defaults.end;
      await this.load();
    } catch (e) {
      this.error = e.msg || e.message;
    }
  },
  methods: {
    loadScope(){return growthRead('report/filters');},scopeChanged(v){this.filters.organization_id=Number(v.orgId||0);this.filters.store_id=Number(v.storeId||0);},repeatReport(r){this.$router.push({path:'/admin/growth/reports',query:{...this.filters,report:'issues',store_id:r.store_id,standard_id:r.standard_id,item_key:r.item_key,start:this.start,end:this.end}});},
    async load() {
      this.busy = true;
      this.error = '';
      try {
        this.data = (await growthRead('overview', { ...this.filters,store_ids:this.storeIds,start: this.start, end: this.end })).data;
      } catch (e) {
        this.error = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    report(type) {
      this.$router.push({ path: '/admin/growth/reports', query: { ...this.filters, store_ids:this.storeIds,start:this.start,end:this.end,report: type } });
    }
  }
};
</script>
<style src="./growth.css"></style>
<style scoped>
.growth-overview-body {
  flex: 1;
  min-height: 0;
  overflow: auto;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.growth-overview-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
}
.growth-overview-cards button {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  padding: 20px;
  gap: 13px;
  background: #fff;
  border-radius: 12px;
}
.growth-overview-cards strong {
  font-size: 26px;
  color: #337052;
}
.growth-overview-cards small {
  color: #9ba58c;
}
.growth-overview-columns {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 16px;
}
.growth-overview-columns section {
  min-width: 0;
}
.growth-overview-filters{flex-wrap:wrap}.growth-overview-filters label{max-width:170px}
.growth-toolbar label {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0;
}
</style>
