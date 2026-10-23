<template>
  <main class="growth-page">
    <header class="growth-head">
      <div>
        <h1>巡检与整改</h1>
        <p>发现问题，明确责任，用复查把现场改善落到实处。</p>
      </div>
      <div class="growth-actions">
        <button @click="standardOpen = true">巡检标准</button
        ><button class="growth-primary" @click="create">＋ 安排巡检</button>
      </div>
    </header>
    <p v-if="error" class="growth-error">{{ error }}</p>
    <p v-if="notice" class="growth-success">{{ notice }}</p>
    <nav class="growth-actions" style="justify-content: flex-start">
      <button :class="tab === 'issues' ? 'growth-primary' : ''" @click="tab = 'issues'">整改问题</button
      ><button :class="tab === 'inspections' ? 'growth-primary' : ''" @click="tab = 'inspections'">
        巡检任务
      </button>
    </nav>
    <section class="growth-results">
      <div class="growth-scroll">
        <table v-if="tab === 'issues'">
          <thead>
            <tr>
              <th>问题</th>
              <th>门店</th>
              <th>责任人</th>
              <th>状态</th>
              <th>期限</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in issues" :key="r.id">
              <td style="white-space: normal; max-width: 380px">{{ r.description }}</td>
              <td>{{ r.store_name }}</td>
              <td>{{ person(r.responsible_employee_id) }}</td>
              <td>
                {{ issueStates[r.state] }}<span v-if="isOverdue(r)" style="color: #a96442"> · 逾期</span>
              </td>
              <td>{{ time(r.due_at) }}</td>
              <td>
                <button @click="openIssue(r.id)">查看 / 处理</button
                ><button v-if="r.state === 'OPEN'" @click="issueAction(r, 'extend')">延期</button
                ><button v-if="r.state === 'OPEN'" @click="issueAction(r, 'transfer')">转交</button
                ><button v-if="!['CLOSED', 'CANCELLED'].includes(r.state)" @click="issueAction(r, 'cancel')">
                  撤销
                </button>
              </td>
            </tr>
            <tr v-if="!issues.length">
              <td colspan="6" class="growth-empty">暂无整改问题，继续保持现场标准。</td>
            </tr>
          </tbody>
        </table>
        <table v-else>
          <thead>
            <tr>
              <th>巡检</th>
              <th>门店</th>
              <th>执行人</th>
              <th>状态</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in inspections" :key="r.id">
              <td>{{ r.title }}</td>
              <td>{{ r.store_name }}</td>
              <td>{{ person(r.executor_employee_id) }}</td>
              <td>{{ inspectionStates[r.state] }}</td>
              <td>
                <button @click="openInspection(r.id)">查看 / 检查</button
                ><button
                  v-if="['ASSIGNED', 'PENDING_REVIEW'].includes(r.state)"
                  @click="
                    transferForm = {
                      mode: 'inspection',
                      inspection_id: r.id,
                      responsible_employee_id: 0,
                      reason: ''
                    }
                  "
                >
                  交接处理人
                </button>
              </td>
            </tr>
            <tr v-if="!inspections.length">
              <td colspan="5" class="growth-empty">暂无巡检安排</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :page-data="pagination[tab]" :busy="busy" @change="changePage" />
    </section>
    <div v-if="trainForm" class="growth-modal">
      <section class="growth-dialog">
        <header class="growth-dialog-head">
          <h2>安排问题补训</h2>
          <button @click="trainForm = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="formError" class="growth-error">{{ formError }}</p>
          <label>补训员工</label
          ><select v-model.number="trainForm.employee_id">
            <option v-for="p in employees" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select
          ><label>补训方式</label
          ><select v-model="trainForm.kind" @change="trainForm.source_id = 0">
            <option value="course">课程学习</option>
            <option value="assignment">作业练习</option>
            <option value="coaching">师傅带教</option>
            <option value="evaluation">实操鉴定</option></select
          ><label>引用已发布内容</label
          ><select v-model.number="trainForm.source_id">
            <option :value="0">请选择</option>
            <option v-for="r in trainingSources()" :key="r.id" :value="Number(r.id)">
              {{ r.title }}
            </option></select
          ><label>补训原因与要求</label><textarea v-model="trainForm.reason" rows="3"></textarea
          ><label>完成期限（可不设）</label><input v-model="trainForm.due" type="datetime-local" />
          <p>课程或活动沿用原规则与指派负责人，原问题必须另行复查关闭。</p>
        </div>
        <footer class="growth-dialog-foot">
          <button class="growth-primary" :disabled="busy" @click="saveTraining">确认安排补训</button>
        </footer>
      </section>
    </div>
    <div v-if="transferForm" class="growth-modal">
      <section class="growth-dialog">
        <header class="growth-dialog-head">
          <h2>{{ transferForm.mode === 'inspection' ? '交接巡检待办职责' : '转交整改责任人' }}</h2>
          <button @click="transferForm = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <label>新的责任人</label
          ><select v-model.number="transferForm.responsible_employee_id">
            <option :value="0">请选择</option>
            <option v-for="p in employees" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select
          ><label>转交原因</label><textarea v-model="transferForm.reason" rows="3"></textarea>
          <p v-if="formError" class="growth-error">{{ formError }}</p>
        </div>
        <footer class="growth-dialog-foot">
          <button class="growth-primary" :disabled="busy" @click="saveTransfer">确认转交</button>
        </footer>
      </section>
    </div>
    <StandardEditor
      v-if="standardOpen"
      purpose="inspection"
      menu="inspection"
      @close="standardOpen = false"
      @saved="
        standardOpen = false;
        load();
      "
    />
    <div v-if="form" class="growth-modal">
      <section class="growth-dialog" role="dialog" aria-modal="true">
        <header class="growth-dialog-head">
          <h2>安排巡检</h2>
          <button :disabled="busy" @click="form = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="formError" class="growth-error">{{ formError }}</p>
          <label>任务名称</label><input v-model="form.title" /><label>门店</label
          ><select v-model.number="form.store_id">
            <option :value="0">请选择</option>
            <option v-for="s in stores" :key="s.id" :value="Number(s.id)">{{ s.name }}</option></select
          ><label>巡检标准</label
          ><select v-model.number="form.standard_release_id">
            <option :value="0">请选择</option>
            <option v-for="s in standards" :key="s.id" :value="Number(s.release_id)">
              {{ s.title }}
            </option></select
          ><label>执行人</label
          ><select v-model.number="form.executor_employee_id">
            <option :value="0">请选择</option>
            <option v-for="e in employees" :key="e.id" :value="Number(e.id)">{{ e.name }}</option></select
          ><label>复核人（不需要复核可不选）</label
          ><select v-model.number="form.reviewer_employee_id">
            <option :value="0">无需额外复核</option>
            <option v-for="e in employees" :key="e.id" :value="Number(e.id)">{{ e.name }}</option></select
          ><label>巡检期限</label><input v-model="due" type="datetime-local" />
        </div>
        <footer class="growth-dialog-foot">
          <button class="growth-primary" :disabled="busy" @click="submitCreate">确认安排</button>
        </footer>
      </section>
    </div>
    <div v-if="inspection" class="growth-modal">
      <section class="growth-dialog" role="dialog" aria-modal="true">
        <header class="growth-dialog-head">
          <h2>{{ inspection.inspection.store_name }} · {{ inspection.inspection.title }}</h2>
          <button :disabled="busy" @click="inspection = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="formError" class="growth-error">{{ formError }}</p>
          <template
            v-if="
              inspection.inspection.state === 'ASSIGNED' &&
              Number(inspection.inspection.executor_employee_id) === actor
            "
            ><ReviewItems :standard="inspection.standard" v-model="items" menu="inspection" />
            <h3 style="margin-top: 20px">需要整改的问题</h3>
            <section v-for="item in inspection.standard.items" :key="item.key" class="growth-chapter">
              <label
                ><input v-model="problems[item.key].enabled" type="checkbox" />{{
                  item.title
                }}
                需要安排整改</label
              >
              <div v-if="problems[item.key].enabled">
                <label>问题说明</label><textarea v-model="problems[item.key].description" rows="2"></textarea
                ><label>整改责任人</label
                ><select v-model.number="problems[item.key].responsible_employee_id">
                  <option :value="0">请选择</option>
                  <option v-for="e in employees" :key="e.id" :value="Number(e.id)">
                    {{ e.name }}
                  </option></select
                ><label>复查人</label
                ><select v-model.number="problems[item.key].reviewer_employee_id">
                  <option :value="0">请选择</option>
                  <option v-for="e in employees" :key="e.id" :value="Number(e.id)">
                    {{ e.name }}
                  </option></select
                ><label>整改期限</label><input v-model="problems[item.key].due" type="datetime-local" />
              </div></section></template
          ><template v-else
            ><p>{{ inspectionStates[inspection.inspection.state] }}</p>
            <p v-if="inspection.inspection.result_json">
              检查结果：{{ parse(inspection.inspection.result_json).passed ? '达到标准' : '仍有改善项' }}
            </p>
            <section v-for="item in inspection.standard.items" :key="item.key" class="growth-chapter">
              <h3>{{ item.title }}</h3>
              <p>{{ resultNote(item.key) }}</p>
            </section>
            <p v-if="inspection.inspection.review_note">复核说明：{{ inspection.inspection.review_note }}</p>
            <label
              v-if="
                inspection.inspection.state === 'PENDING_REVIEW' &&
                Number(inspection.inspection.reviewer_employee_id) === actor
              "
              >复核依据</label
            ><textarea
              v-if="
                inspection.inspection.state === 'PENDING_REVIEW' &&
                Number(inspection.inspection.reviewer_employee_id) === actor
              "
              v-model="reviewNote"
              rows="3"
            ></textarea>
          </template>
        </div>
        <footer class="growth-dialog-foot">
          <button
            v-if="
              inspection.inspection.state === 'ASSIGNED' &&
              Number(inspection.inspection.executor_employee_id) === actor
            "
            class="growth-primary"
            :disabled="busy"
            @click="submitInspection"
          >
            确认提交巡检</button
          ><button
            v-if="
              inspection.inspection.state === 'PENDING_REVIEW' &&
              Number(inspection.inspection.reviewer_employee_id) === actor
            "
            class="growth-primary"
            :disabled="busy"
            @click="confirmInspection"
          >
            确认复核
          </button>
        </footer>
      </section>
    </div>
    <div v-if="issue" class="growth-modal">
      <section class="growth-dialog" role="dialog" aria-modal="true">
        <header class="growth-dialog-head">
          <h2>{{ issue.issue.store_name }} · {{ issue.issue.item_title }}</h2>
          <button :disabled="busy" @click="issue = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="formError" class="growth-error">{{ formError }}</p>
          <p class="growth-detail">{{ issue.issue.description }}</p>
          <button data-operation="growth.issue.training" @click="openTraining">按需安排补训</button>
          <div v-for="t in issue.trainings" :key="'training' + t.id" class="growth-chapter">
            <p>
              关联补训：{{ t.title }} · {{ t.employee_name }} ·
              {{ t.state === 'COMPLETED' ? '已完成' : '待完成' }}
            </p>
            <p>{{ t.reason }}。补训完成不代替问题复查。</p>
          </div>
          <p>
            {{ issueStates[issue.issue.state] }} · 责任人 {{ person(issue.issue.responsible_employee_id) }} ·
            复查人 {{ person(issue.issue.reviewer_employee_id) }}
          </p>
          <section v-for="r in issue.rectifications" :key="r.id" class="growth-chapter">
            <h3>整改提交 · {{ time(r.submitted_at) }}</h3>
            <p class="growth-detail">{{ parse(r.snapshot_json).body }}</p>
            <button v-for="asset in parse(r.snapshot_json).asset_ids" :key="asset" @click="preview(asset)">
              查看新证据
            </button>
          </section>
          <section v-for="r in issue.reviews" :key="r.id" class="growth-chapter">
            <h3>复查：{{ Number(r.passed) ? '通过' : '退回' }}</h3>
            <p>{{ r.note }}</p>
          </section>
          <template
            v-if="issue.issue.state === 'OPEN' && Number(issue.issue.responsible_employee_id) === actor"
            ><label>整改说明</label><textarea v-model="body" rows="4"></textarea><label>新的整改证据</label
            ><EvidenceInput
              v-model="assetIds"
              menu="inspection"
              :task-id="Number(issue.issue.task_id)" /></template
          ><template
            v-if="
              issue.issue.state === 'PENDING_REVIEW' && Number(issue.issue.reviewer_employee_id) === actor
            "
            ><label>复查结论</label
            ><select v-model="passed">
              <option :value="true">通过并关闭</option>
              <option :value="false">退回继续整改</option></select
            ><label>复查依据 / 退回原因</label><textarea v-model="reviewNote" rows="3"></textarea
            ><EvidenceInput v-model="assetIds" menu="inspection" :task-id="Number(issue.issue.task_id)"
          /></template>
        </div>
        <footer class="growth-dialog-foot">
          <button
            v-if="issue.issue.state === 'OPEN' && Number(issue.issue.responsible_employee_id) === actor"
            class="growth-primary"
            :disabled="busy"
            @click="submitRectify"
          >
            提交整改，等待复查</button
          ><button
            v-if="
              issue.issue.state === 'PENDING_REVIEW' && Number(issue.issue.reviewer_employee_id) === actor
            "
            class="growth-primary"
            :disabled="busy"
            @click="reviewIssue"
          >
            确认复查结果
          </button>
        </footer>
      </section>
    </div>
  </main>
</template>
<script>
import Pager from './Pager.vue';
import { growthRead, growthWrite, growthPreview } from '@/api/growth';
import StandardEditor from './StandardEditor.vue';
import ReviewItems from './ReviewItems.vue';
import EvidenceInput from './EvidenceInput.vue';
/** 巡检与问题状态分别展示，整改提交后仍须指派人真实复查才能关闭。 */
export default {
  name: 'GrowthInspection',
  components: { Pager, StandardEditor, ReviewItems, EvidenceInput },
  data: () => ({
    pagination: {},
    pageQuery: { inspections_page: 1, issues_page: 1 },
    tab: 'issues',
    inspections: [],
    issues: [],
    stores: [],
    standards: [],
    courses: [],
    activityOptions: [],
    trainForm: null,
    transferForm: null,
    employees: [],
    actor: 0,
    error: '',
    notice: '',
    formError: '',
    busy: false,
    standardOpen: false,
    form: null,
    due: '',
    inspection: null,
    issue: null,
    items: {},
    problems: {},
    body: '',
    reviewNote: '',
    assetIds: [],
    passed: true,
    inspectionStates: {
      ASSIGNED: '待执行',
      SUBMITTED: '已提交',
      PENDING_REVIEW: '待复核',
      REVIEWED: '已复核'
    },
    issueStates: { OPEN: '待整改', PENDING_REVIEW: '待复查', CLOSED: '已关闭', CANCELLED: '已撤销' }
  }),
  created() {
    this.load();
    if(this.$route.query.issue_id)this.openIssue(Number(this.$route.query.issue_id));
  },
  methods: {
    openTraining() {
      this.trainForm = {
        issue_id: this.issue.issue.id,
        kind: 'course',
        source_id: 0,
        employee_id: Number(this.issue.issue.responsible_employee_id),
        reason: '',
        due: ''
      };
      this.formError = '';
    },
    trainingSources() {
      return this.trainForm.kind === 'course'
        ? this.courses
        : this.activityOptions.filter((a) => a.kind === this.trainForm.kind);
    },
    async saveTraining() {
      this.busy = true;
      try {
        this.notice = (
          await growthWrite('issue/train', {
            ...this.trainForm,
            due_at: this.trainForm.due ? Math.floor(new Date(this.trainForm.due).getTime() / 1000) : 0
          })
        ).data.message;
        const id = this.trainForm.issue_id;
        this.trainForm = null;
        await this.openIssue(id);
      } catch (e) {
        this.formError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    async saveTransfer() {
      const inspection = this.transferForm.mode === 'inspection';
      const body = inspection
        ? { ...this.transferForm, employee_id: this.transferForm.responsible_employee_id }
        : this.transferForm;
      if (await this.run(inspection ? 'inspection/handover' : 'issue/action', body)) this.transferForm = null;
    },
    async load() {
      try {
        const [r, c] = await Promise.all([
          growthRead('inspections', this.pageQuery),
          growthRead('catalog', { menu: 'inspection' })
        ]);
        this.pagination = r.data.pagination;
        Object.assign(this, {
          inspections: r.data.inspections,
          issues: r.data.issues,
          stores: r.data.stores,
          standards: r.data.standards,
          employees: c.data.employees,
          actor: Number(c.data.operator_employee_id)
        });
        this.courses = c.data.course_publications;
        this.activityOptions = c.data.activity_options;
        this.error = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    create() {
      this.formError = '';
      this.due = '';
      this.form = {
        title: '',
        store_id: 0,
        standard_release_id: 0,
        executor_employee_id: 0,
        reviewer_employee_id: 0
      };
    },
    async submitCreate() {
      await this.run('inspection/create', {
        ...this.form,
        due_at: this.due ? Math.floor(new Date(this.due).getTime() / 1000) : 0
      });
    },
    async openInspection(id) {
      try {
        this.inspection = (await growthRead('inspection', { id })).data;
        this.items = {};
        this.problems = {};
        this.inspection.standard.items.forEach((i) => {
          this.$set(this.items, i.key, {
            passed: null,
            score: null,
            note: '',
            not_applicable: false,
            reason: '',
            asset_ids: []
          });
          this.$set(this.problems, i.key, {
            enabled: false,
            description: '',
            responsible_employee_id: 0,
            reviewer_employee_id: 0,
            due: ''
          });
        });
        this.formError = '';
        this.reviewNote = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async submitInspection() {
      const problems = this.inspection.standard.items
        .filter((i) => this.problems[i.key].enabled)
        .map((i) => ({
          item_key: i.key,
          ...this.problems[i.key],
          asset_ids: this.items[i.key].asset_ids,
          due_at: this.problems[i.key].due
            ? Math.floor(new Date(this.problems[i.key].due).getTime() / 1000)
            : 0
        }));
      await this.run('inspection/submit', { id: this.inspection.inspection.id, items: this.items, problems });
    },
    async confirmInspection() {
      await this.run('inspection/confirm', { id: this.inspection.inspection.id, note: this.reviewNote });
    },
    async openIssue(id) {
      try {
        this.issue = (await growthRead('issue', { id })).data;
        this.body = '';
        this.reviewNote = '';
        this.assetIds = [];
        this.passed = true;
        this.formError = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async submitRectify() {
      await this.run('issue/rectify', {
        issue_id: this.issue.issue.id,
        body: this.body,
        asset_ids: this.assetIds
      });
    },
    async reviewIssue() {
      await this.run('issue/review', {
        issue_id: this.issue.issue.id,
        passed: this.passed,
        note: this.reviewNote,
        asset_ids: this.assetIds
      });
    },
    async run(path, data) {
      this.busy = true;
      this.formError = '';
      try {
        const r = await growthWrite(path, data);
        this.notice = r.data.message;
        this.form = null;
        this.inspection = null;
        this.issue = null;
        await this.load();
        return true;
      } catch (e) {
        this.formError = e.msg || e.message;
        this.error = this.formError;
        return false;
      } finally {
        this.busy = false;
      }
    },
    async issueAction(row, action) {
      if (action === 'transfer') {
        this.transferForm = { issue_id: row.id, action, reason: '', responsible_employee_id: 0 };
        return;
      }
      const reason = window.prompt('请填写处理原因');
      if (!reason) return;
      let data = { issue_id: row.id, action, reason };
      if (action === 'extend') {
        const date = window.prompt('新期限，例如2026-11-01 18:00');
        if (!date) return;
        data.due_at = Math.floor(new Date(date.replace(' ', 'T')).getTime() / 1000);
      }
      await this.run('issue/action', data);
    },
    person(id) {
      const p = this.employees.find((e) => Number(e.id) === Number(id));
      return p ? p.name : '员工' + id;
    },
    parse(v) {
      try {
        return JSON.parse(v || '{}');
      } catch (e) {
        return {};
      }
    },
    resultNote(key) {
      const r = this.parse(this.inspection.inspection.result_json);
      return r.items && r.items[key] ? r.items[key].note || r.items[key].reason : '未评';
    },
    time(t) {
      return t ? new Date(Number(t) * 1000).toLocaleString('zh-CN') : '不限期';
    },
    isOverdue(r) {
      return !['CLOSED', 'CANCELLED'].includes(r.state) && Number(r.due_at) < Date.now() / 1000;
    },
    async preview(id) {
      try {
        const url = await growthPreview(id, 'inspection', Number(this.issue.issue.task_id));
        window.open(url, '_blank', 'noopener');
        setTimeout(() => URL.revokeObjectURL(url), 60000);
      } catch (e) {
        this.formError = e.msg || e.message;
      }
    }
  }
};
</script>
<style src="./growth.css"></style>
