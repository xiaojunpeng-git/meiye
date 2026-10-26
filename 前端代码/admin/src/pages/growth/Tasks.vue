<template>
  <main class="growth-page">
    <header class="growth-head">
      <div>
        <h1>学习任务</h1>
        <p>分配清楚、要求明确，实时了解每个人的学习进展。</p>
      </div>
      <button class="growth-primary" data-operation="growth.task.assign" @click="openAssign">
        ＋ 分配学习
      </button>
    </header>
    <p v-if="error" class="growth-error" role="alert">{{ error }}</p>
    <p v-if="notice" class="growth-success" role="status">{{ notice }}</p>
    <form class="growth-toolbar" @submit.prevent="load(1)">
      <input v-model="keyword" placeholder="搜索任务名称" aria-label="搜索任务" /><select
        v-model="state"
        style="width: 180px"
      >
        <option value="">全部状态</option>
        <option v-for="(label, key) in states" :key="key" :value="key">{{ label }}</option></select
      ><button>查询</button>
    </form>
    <section class="growth-results">
      <div class="growth-scroll">
        <table>
          <thead>
            <tr>
              <th>任务</th>
              <th>员工</th>
              <th>执行状态</th>
              <th>结果</th>
              <th>截止时间</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in list.rows" :key="r.id">
              <td>{{ r.title }}</td>
              <td>{{ r.employee_name }}</td>
              <td>
                <span class="growth-badge">{{ states[r.state] || r.state }}</span>
                <span v-if="r.overdue" style="color: #ac6543">已逾期</span>
              </td>
              <td>{{ conclusions[r.conclusion] || r.conclusion }}</td>
              <td>{{ time(r.due_at) }}</td>
              <td>
                <button @click="view(r)">查看</button
                ><button
                  v-if="!['COMPLETED', 'CANCELLED'].includes(r.state)"
                  @click="action(r, r.state === 'PAUSED' ? 'resume' : 'pause')"
                >
                  {{ r.state === 'PAUSED' ? '恢复' : '暂停' }}</button
                ><button v-if="!['COMPLETED', 'CANCELLED'].includes(r.state)" @click="extend(r)">延期</button
                ><button v-if="r.state!=='CANCELLED'" @click="openHandover(r)">
                  交接处理人</button
                ><button
                  v-if="!['COMPLETED', 'CANCELLED'].includes(r.state)"
                  class="growth-danger"
                  @click="action(r, 'cancel')"
                >
                  取消
                </button>
              </td>
            </tr>
            <tr v-if="!list.rows.length">
              <td colspan="6" class="growth-empty">当前没有符合条件的任务。</td>
            </tr>
          </tbody>
        </table>
      </div>
      <footer class="growth-pager">
        <span>共 {{ list.total }} 条 · 每页 20 条 · 第 {{ list.page }} / {{ list.total_pages }} 页</span
        ><button :disabled="list.page <= 1" @click="load(list.page - 1)">上一页</button
        ><button :disabled="list.page >= list.total_pages" @click="load(list.page + 1)">下一页</button>
      </footer>
    </section>
    <div v-if="assignForm" class="growth-modal">
      <section class="growth-dialog" role="dialog" aria-modal="true" aria-labelledby="growth-assign-title">
        <header class="growth-dialog-head">
          <h2 id="growth-assign-title">分配学习</h2>
          <button :disabled="busy" @click="assignForm = null">✕</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="formError" class="growth-error" role="alert">{{ formError }}</p>
          <label for="assign-course">已发布课程 / 知识</label
          ><select id="assign-course" v-model.number="assignForm.publication_id">
            <option :value="0">请选择</option>
            <option v-for="c in courses" :key="c.id" :value="c.current_publication_id">
              {{ c.title }}
            </option></select
          ><label for="assign-name">任务名称</label
          ><input id="assign-name" v-model="assignForm.title" placeholder="不填则使用课程名称" /><label
            >接收员工（{{ assignForm.employee_ids.length }} 人）</label
          >
          <div class="growth-persons">
            <label v-for="p in employees" :key="p.id"
              ><input v-model="assignForm.employee_ids" type="checkbox" :value="Number(p.id)" />{{
                p.name
              }}</label
            >
          </div>
          <div class="growth-form-grid">
            <div>
              <label for="assign-start">开始时间（可不填）</label
              ><input id="assign-start" v-model="starts" type="datetime-local" />
            </div>
            <div>
              <label for="assign-due">截止时间（可不填）</label
              ><input id="assign-due" v-model="due" type="datetime-local" />
            </div>
          </div>
          <div class="growth-form-grid"><label>负责人（可选）<select v-model.number="assignForm.handler_employee_id"><option :value="0">未指定</option><option v-for="p in employees" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select></label><label>分配后期限天数（0使用固定日期）<input type="number" v-model.number="assignForm.relative_days" min="0" max="3650" /></label></div><label><input type="checkbox" v-model="assignForm.required" />本项必做</label><label>任务说明<textarea v-model="assignForm.description" rows="3"></textarea></label>
          <label><input v-model="assignForm.allow_late" type="checkbox" />逾期后仍允许完成</label>
          <p>确认名单和期限后，将课程本次已发布内容分配给以上员工。</p>
        </div>
        <footer class="growth-dialog-foot">
          <button :disabled="busy" @click="assignForm = null">取消</button
          ><button class="growth-primary" :disabled="busy" @click="submitAssign">
            确认分配 {{ assignForm.employee_ids.length }} 人
          </button>
        </footer>
      </section>
    </div>
    <div v-if="handlerForm" class="growth-modal">
      <section class="growth-dialog">
        <header class="growth-dialog-head">
          <h2>交接待办处理职责</h2>
          <button @click="handlerForm = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p>{{ handlerForm.title }}</p>
          <p>任务执行员工保持原身份；仅交接点评、阅卷、鉴定或复查等处理职责。</p>
          <label>接收处理人</label
          ><select v-model.number="handlerForm.handler_employee_id">
            <option :value="0">请选择</option>
            <option v-for="p in employees" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select
          ><label>交接原因</label><textarea v-model="handlerForm.reason" rows="3"></textarea>
          <p v-if="formError" class="growth-error">{{ formError }}</p>
        </div>
        <footer class="growth-dialog-foot">
          <button
            class="growth-primary"
            :disabled="busy"
            data-operation="growth.task.transfer"
            @click="saveHandover"
          >
            确认交接
          </button>
        </footer>
      </section>
    </div>
    <div v-if="detail" class="growth-modal">
      <section class="growth-dialog" role="dialog" aria-modal="true">
        <header class="growth-dialog-head">
          <h2>{{ detail.title }}</h2>
          <button @click="detail = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <h3>{{ detail.employee_name }} · {{ states[detail.state] }}</h3>
          <p>结果：{{ conclusions[detail.conclusion] || detail.conclusion }}</p>
          <p>分配于 {{ time(detail.assigned_at) }} · 完成于 {{ time(detail.completed_at) }}</p>
          <button v-if="sourceRoute" @click="$router.push(sourceRoute)">查看原业务记录</button><p v-if="detail.rules.description">{{ detail.rules.description }}</p>
          <h3>学习章节</h3>
          <p v-for="ch in detail.content ? detail.content.chapters : []" :key="ch.key">
            {{ ch.title }} · {{ isLearned(ch.key) ? '已完成' : '未完成' }}
          </p>
          <h3 v-if="detail.dependencies.length">后续检验与实操</h3>
          <p v-for="d in detail.dependencies" :key="'dep' + d.id">
            {{ d.title }} · {{ d.satisfied ? '已满足' : states[d.state] || d.state }}
          </p>
          <h3>处理历史</h3>
          <p v-for="r in detail.history" :key="'history' + r.id">{{ r.reason }} · {{ time(r.created_at) }}</p>
        </div>
      </section>
    </div>
  </main>
</template>
<script>
import { growthRead, growthWrite } from '@/api/growth';
/** 名单只取后端授权候选，任务执行状态、结论、逾期分别呈现。 */
export default {
  name: 'GrowthTasks',
  data: () => ({
    list: { rows: [], total: 0, page: 1, total_pages: 1 },
    keyword: '',
    state: '',
    error: '',
    notice: '',
    formError: '',
    busy: false,
    assignForm: null,
    handlerForm: null,
    employees: [],
    courses: [],
    starts: '',
    due: '',
    detail: null,
    states: {
      NOT_STARTED: '未开始',
      IN_PROGRESS: '进行中',
      PENDING: '待处理',
      COMPLETED: '已完成',
      PAUSED: '已暂停',
      CANCELLED: '已取消',
      RETURNED: '待补交'
    },
    conclusions: {
      UNTESTED: '未检验',
      PASSED: '通过',
      FAILED: '未通过',
      QUALIFIED: '达标',
      NEED_PRACTICE: '需补练'
    }
  }),
  computed: { sourceRoute(){const t=this.detail;if(!t)return null;const base='/admin/growth/';let path='',query={};if(['exam','practice'].includes(t.kind)&&t.attempts.length){path='exams';query={attempt_id:t.attempts[0].id};}else if(['assignment','evaluation','coaching','offline'].includes(t.kind)){path='practice';query={task_id:t.id};}else if(t.kind==='appraisal'){path='profiles';query={appraisal_id:t.source_id};}else if(t.kind==='rectification'){path='inspection';query={issue_id:t.source_id};}const allowed=String(localStorage.getItem('unique_auth')||'').split(',');return path&&allowed.includes('admin-growth-'+path)?{path:base+path,query}:null;}},
  async created() {
    await this.load(1);
    if (this.$route.query.publication_id) await this.openAssign();
    if (this.$route.query.task_id) await this.view({ id: Number(this.$route.query.task_id) });
  },
  methods: {
    async openHandover(row) {
      try {
        this.employees = (await growthRead('catalog', { menu: 'tasks' })).data.employees;
        this.handlerForm = {
          task_id: row.id,
          title: row.title,
          action: 'transfer',
          handler_employee_id: 0,
          reason: ''
        };
        this.formError = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async saveHandover() {
      this.busy = true;
      try {
        await growthWrite('task/action', this.handlerForm);
        this.handlerForm = null;
        await this.load(this.list.page);
        this.notice = '处理职责已交接，原执行员工和历史结果保留';
      } catch (e) {
        this.formError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    async load(page) {
      try {
        this.list = (await growthRead('tasks', { page, keyword: this.keyword, state: this.state, employee_id:Number(this.$route.query.employee_id||0) })).data;
        this.error = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async openAssign() {
      try {
        const r = await growthRead('catalog', { menu: 'tasks' });
        this.employees = r.data.employees;
        this.courses = r.data.course_publications.map((c) => ({
          id: c.id,
          current_publication_id: Number(c.id),
          title: c.title
        }));
        this.assignForm = {
          publication_id: Number(this.$route.query.publication_id || 0),
          title: this.$route.query.title || '',
          employee_ids: [],
          allow_late: true, required: true, handler_employee_id: 0, description: '', relative_days: 0
        };
        this.starts = '';
        this.due = '';
        this.formError = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async submitAssign() {
      this.busy = true;
      this.formError = '';
      try {
        const r = await growthWrite('task/assign', {
          ...this.assignForm,
          starts_at: this.starts ? Math.floor(new Date(this.starts).getTime() / 1000) : 0,
          due_at: this.due ? Math.floor(new Date(this.due).getTime() / 1000) : 0
        });
        this.notice = `已向 ${r.data.assigned_count} 名员工分配学习任务`;
        this.assignForm = null;
        await this.load(1);
      } catch (e) {
        this.formError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    async action(row, action, extra = {}) {
      const reason = window.prompt('请填写本次处理原因');
      if (!reason) return;
      try {
        await growthWrite('task/action', { task_id: row.id, action, reason, ...extra });
        await this.load(this.list.page);
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    extend(row) {
      const value = window.prompt('新的截止时间，例如：2026-11-01 18:00');
      if (!value) return;
      const due = Math.floor(new Date(value.replace(' ', 'T')).getTime() / 1000);
      if (!Number.isFinite(due)) {
        this.error = '请输入有效时间';
        return;
      }
      this.action(row, 'extend', { due_at: due });
    },
    async view(row) {
      try {
        this.detail = (await growthRead('task', { id: row.id })).data;
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    isLearned(key) {
      return this.detail.learning.some((r) => r.chapter_key === key && Number(r.confirmed) === 1);
    },
    time(t) {
      return t ? new Date(Number(t) * 1000).toLocaleString('zh-CN', { hour12: false }) : '不限期';
    }
  }
};
</script>
<style src="./growth.css"></style>
