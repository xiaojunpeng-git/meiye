<template>
  <main class="growth-page">
    <header class="growth-head">
      <div>
        <button @click="$router.push('/admin/growth/help')">人工答疑</button
        ><button @click="$router.push('/admin/growth/capabilities')">岗位能力与师傅</button>
        <h1>带教与实操</h1>
        <p>有人带、有人评，把专业真正练到手上。</p>
      </div>
      <div class="growth-actions">
        <button @click="standardOpen = true">检查标准</button
        ><button class="growth-primary" @click="create">＋ 安排活动</button>
      </div>
    </header>
    <p v-if="error" class="growth-error">{{ error }}</p>
    <p v-if="notice" class="growth-success">{{ notice }}</p>
    <nav class="growth-actions" style="justify-content: flex-start">
      <button :class="tab === 'pending' ? 'growth-primary' : ''" @click="tab = 'pending'">待处理事项</button
      ><button :class="tab === 'activities' ? 'growth-primary' : ''" @click="tab = 'activities'">
        活动安排
      </button>
    </nav>
    <section class="growth-results">
      <div class="growth-scroll">
        <table v-if="tab === 'pending'">
          <thead>
            <tr>
              <th>任务</th>
              <th>学员</th>
              <th>类型</th>
              <th>状态</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in pending" :key="t.id">
              <td>{{ t.title }}</td>
              <td>{{ t.employee_name }}</td>
              <td>{{ kinds[t.kind] }}</td>
              <td>{{ states[t.state] || t.state }}</td>
              <td><button @click="open(t)">查看 / 处理</button></td>
            </tr>
            <tr v-if="!pending.length">
              <td colspan="5" class="growth-empty">当前没有待处理的实操与带教任务。</td>
            </tr>
          </tbody>
        </table>
        <table v-else>
          <thead>
            <tr>
              <th>活动</th>
              <th>类型</th>
              <th>说明</th>
              <th>截止</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in activities" :key="a.id">
              <td>{{ a.title }}</td>
              <td>{{ kinds[a.kind] }}</td>
              <td style="max-width: 400px; white-space: normal">{{ a.description }}</td>
              <td>{{ time(a.due_at) }}</td>
            </tr>
            <tr v-if="!activities.length">
              <td colspan="4" class="growth-empty">暂无活动安排</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :page-data="pagination[tab]" :busy="busy" @change="changePage" />
    </section>
    <StandardEditor
      v-if="standardOpen"
      purpose="evaluation"
      menu="practice"
      @close="standardOpen = false"
      @saved="
        standardOpen = false;
        load();
      "
    />
    <div v-if="form" class="growth-modal">
      <section class="growth-dialog" role="dialog" aria-modal="true">
        <header class="growth-dialog-head">
          <h2>安排成长活动</h2>
          <button :disabled="busy" @click="form = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="formError" class="growth-error">{{ formError }}</p>
          <label>活动类型</label
          ><select v-model="form.kind">
            <option v-for="(label, key) in kinds" :key="key" :value="key">{{ label }}</option></select
          ><label>活动名称</label><input v-model="form.title" /><label>任务说明 / 标准要求</label
          ><textarea v-model="form.description" rows="3"></textarea><label>学员</label>
          <div class="growth-persons">
            <label v-for="p in employees" :key="p.id"
              ><input v-model="form.employee_ids" type="checkbox" :value="Number(p.id)" />{{ p.name }}</label
            >
          </div>
          <label>师傅 / 点评 / 鉴定负责人</label
          ><select v-model.number="form.handler_employee_id">
            <option :value="0">请选择</option>
            <option v-for="p in employees" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select
          ><label v-if="form.kind === 'evaluation'">岗位能力项（可选）</label
          ><select v-if="form.kind === 'evaluation'" v-model.number="form.capability_id">
            <option :value="0">本次单独鉴定，不关联岗位能力</option>
            <option v-for="cap in capabilities" :key="cap.id" :value="Number(cap.id)">
              {{ cap.title }}
            </option></select
          ><label v-if="form.kind === 'evaluation'">鉴定标准</label
          ><select v-if="form.kind === 'evaluation'" v-model.number="form.standard_release_id">
            <option :value="0">请选择</option>
            <option
              v-for="s in standards.filter((s) => s.purpose === 'evaluation')"
              :key="s.id"
              :value="Number(s.release_id)"
            >
              {{ s.title }}
            </option>
          </select>
          <div class="growth-form-grid">
            <div><label>提交截止时间（可不填）</label><input v-model="due" type="datetime-local" /></div>
            <div v-if="form.kind === 'evaluation'">
              <label>有效天数（0为不设到期）</label
              ><input v-model.number="form.valid_days" type="number" min="0" />
            </div>
            <div v-if="form.kind === 'assignment'">
              <label>要求提交次数</label
              ><input v-model.number="form.required_submissions" type="number" min="1" max="100" />
            </div>
            <div v-if="['assignment', 'evaluation'].includes(form.kind)">
              <label>允许补交 / 复鉴次数</label
              ><input v-model.number="form.max_resubmissions" type="number" min="0" max="20" />
            </div>
          </div>
          <label><input v-model="form.evidence_required" type="checkbox" />必须提供图片或视频证据</label
          ><label v-if="form.kind === 'assignment'"
            ><input v-model="form.requires_confirmation" type="checkbox" />提交后需要师傅点评确认</label
          ><template v-if="form.kind === 'offline'"
            ><label>活动地点</label><input v-model="form.location" /><label>活动时间</label
            ><input v-model="eventAt" type="datetime-local"
          /></template>
        </div>
        <footer class="growth-dialog-foot">
          <button class="growth-primary" :disabled="busy" @click="submit">
            确认分配 {{ form.employee_ids.length }} 人
          </button>
        </footer>
      </section>
    </div>
    <div v-if="work" class="growth-modal">
      <section class="growth-dialog" role="dialog" aria-modal="true">
        <header class="growth-dialog-head">
          <h2>{{ work.task.employee_name }} · {{ work.task.title }}</h2>
          <button :disabled="busy" @click="work = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="formError" class="growth-error">{{ formError }}</p>
          <p>{{ work.activity.description }}</p>
          <p v-if="!work.can_handle">当前仅可查看，正式处理须由指派负责人执行。</p>
          <section v-for="s in work.submissions" :key="s.id" class="growth-chapter">
            <h3>第 {{ s.attempt_no }} 次提交 · {{ submissionStates[s.state] }}</h3>
            <p class="growth-detail">{{ parse(s.snapshot_json).body }}</p>
            <button v-for="asset in parse(s.snapshot_json).asset_ids" :key="asset" @click="preview(asset)">
              查看证据
            </button>
            <p v-if="s.review_json">点评：{{ parse(s.review_json).note }}</p>
          </section>
          <article v-for="e in work.evaluations" :key="'evaluation'+e.id" class="growth-chapter"><h3>实操鉴定 · {{ e.conclusion==='QUALIFIED'?'已达标':'需补练' }}</h3><p>{{ time(e.occurred_at) }} · {{ e.score==null?'逐项通过制':e.score+' 分' }}</p><p>{{ parse(e.result_json).note }}</p><p v-for="(r,key) in parse(e.result_json).items" :key="key">{{ itemName(key) }} · {{ r.not_applicable?'不适用：'+r.reason:r.note }}<button v-for="asset in r.asset_ids || []" :key="asset" @click="preview(asset)">查看评价证据</button></p></article><article v-for="r in work.coaching" :key="'coaching'+r.id" class="growth-chapter"><h3>正式带教记录 · {{ time(r.occurred_at) }}</h3><p>{{ parse(r.snapshot_json).body }}</p><p>发现问题：{{ parse(r.snapshot_json).problems || '未记录' }}</p><p>点评：{{ parse(r.snapshot_json).comment || '未记录' }}</p><p>下一步：{{ parse(r.snapshot_json).next_steps || '按安排继续练习' }}</p><button v-for="asset in parse(r.snapshot_json).asset_ids" :key="asset" @click="preview(asset)">查看带教证据</button></article><article v-for="r in work.attendance || []" :key="'attendance'+r.id" class="growth-chapter"><h3>{{ Number(r.attended)?'已实际到场':'未到场' }} · {{ time(r.occurred_at) }}</h3><p>{{ parse(r.evidence_json).body }}</p><button v-for="asset in parse(r.evidence_json).asset_ids || []" :key="asset" @click="preview(asset)">查看活动证据</button></article>
          <template
            v-if="
              work.can_handle &&
              work.task.state === 'PENDING' &&
              ['assignment', 'evaluation'].includes(work.activity.kind)
            "
            ><ReviewItems
              v-if="work.standard"
              :standard="work.standard"
              v-model="items"
              menu="practice"
              :task-id="Number(work.task.id)"
            /><label>点评 / 评价说明</label><textarea v-model="reviewNote" rows="3"></textarea
            ><label v-if="work.activity.kind === 'assignment'">处理结论</label
            ><select v-if="work.activity.kind === 'assignment'" v-model="accepted">
              <option :value="true">确认完成本次提交</option>
              <option :value="false">退回补交</option>
            </select></template
          >
          <template v-if="work.can_handle && ['NOT_STARTED','IN_PROGRESS'].includes(work.task.state) && ['coaching', 'offline'].includes(work.activity.kind)"
            ><label>实际发生时间（留空按当前时间）</label><input type="datetime-local" v-model="actualAt" /><label>实际带教 / 活动说明</label><textarea v-model="reviewNote" rows="4"></textarea
            ><template v-if="work.activity.kind==='coaching'"><label>发现问题</label><textarea v-model="problems" rows="2"></textarea><label>师傅点评</label><textarea v-model="mentorComment" rows="2"></textarea><label>下一步建议</label><textarea v-model="nextSteps" rows="2"></textarea></template><label v-if="work.activity.kind === 'offline'"
              ><input v-model="attended" type="checkbox" />已实际到场</label
            ><label>证据</label
            ><EvidenceInput v-model="assetIds" menu="practice" :task-id="Number(work.task.id)"
          /></template>
        </div>
        <footer class="growth-dialog-foot">
          <button
            v-if="
              work.can_handle &&
              (work.task.state === 'PENDING' || (['NOT_STARTED','IN_PROGRESS'].includes(work.task.state) && ['coaching','offline'].includes(work.activity.kind)))
            "
            class="growth-primary"
            :disabled="busy"
            @click="confirmWork"
          >
            确认本次处理</button
          ><button @click="work = null">关闭</button>
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
/** 指派关系决定处理人；作业内容、补交记录和正式鉴定结论分别留痕。 */
export default {
  name: 'GrowthPractice',
  components: { Pager, StandardEditor, ReviewItems, EvidenceInput },
  data: () => ({
    pagination: {},
    pageQuery: { pending_page: 1, activities_page: 1 },
    activities: [],
    pending: [],
    standards: [],
    capabilities: [],
    employees: [],
    kinds: { assignment: '作业与打卡', evaluation: '实操鉴定', coaching: '师傅带教', offline: '线下培训' },
    states: {
      NOT_STARTED: '未开始',
      IN_PROGRESS: '进行中',
      PENDING: '待处理',
      RETURNED: '待补交',
      PAUSED: '已暂停'
    },
    submissionStates: { PENDING: '待点评', ACCEPTED: '已确认', RETURNED: '已退回' },
    tab: 'pending',
    error: '',
    notice: '',
    formError: '',
    busy: false,
    standardOpen: false,
    form: null,
    due: '',
    eventAt: '',
    work: null,
    items: {},
    reviewNote: '',actualAt:'',problems:'',mentorComment:'',nextSteps:'',
    accepted: true,
    attended: true,
    assetIds: []
  }),
  created() {
    this.load();
    if(this.$route.query.task_id)this.open({id:Number(this.$route.query.task_id)});
  },
  methods: {
    itemName(key){const r=this.work.standard && this.work.standard.items.find(r=>r.key===key);return r?r.title:key;},
    changePage(page) {
      this.$set(this.pageQuery, this.tab + '_page', page);
      this.load();
    },
    async load() {
      try {
        const [r, c] = await Promise.all([
          growthRead('practice', this.pageQuery),
          growthRead('catalog', { menu: 'practice' })
        ]);
        this.pagination = r.data.pagination;
        this.activities = r.data.activities;
        this.pending = r.data.pending;
        this.standards = r.data.standards;
        this.capabilities = c.data.capability_options || [];
        this.employees = c.data.employees;
        this.error = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    create() {
      this.formError = '';
      this.due = '';
      this.eventAt = '';
      this.form = {
        kind: 'assignment',
        title: '',
        description: '',
        employee_ids: [],
        handler_employee_id: 0,
        standard_release_id: 0,
        capability_id: 0,
        evidence_required: false,
        requires_confirmation: true,
        required_submissions: 1,
        max_resubmissions: 2,
        valid_days: 0,
        location: ''
      };
    },
    async submit() {
      this.busy = true;
      try {
        const r = await growthWrite('activity/create', {
          ...this.form,
          due_at: this.due ? Math.floor(new Date(this.due).getTime() / 1000) : 0,
          event_at: this.eventAt ? Math.floor(new Date(this.eventAt).getTime() / 1000) : 0
        });
        this.notice = r.data.message;
        this.form = null;
        await this.load();
      } catch (e) {
        this.formError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    async open(t) {
      try {
        this.work = (await growthRead('work', { task_id: t.id })).data;
        this.items = {};
        if (this.work.standard)
          this.work.standard.items.forEach((i) =>
            this.$set(this.items, i.key, {
              passed: null,
              score: null,
              note: '',
              not_applicable: false,
              reason: '',
              asset_ids: []
            })
          );
        this.reviewNote = '';this.actualAt='';this.problems='';this.mentorComment='';this.nextSteps='';
        this.assetIds = [];
        this.formError = '';
        this.accepted = true;
        this.attended = true;
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async confirmWork() {
      this.busy = true;
      this.formError = '';
      try {
        let path = '';
        let data = {};
        if (['coaching', 'offline'].includes(this.work.activity.kind)) {
          path = 'activity/confirm';
          data = {
            task_id: this.work.task.id,
            body: this.reviewNote,problems:this.problems,comment:this.mentorComment,next_steps:this.nextSteps,occurred_at:this.actualAt?Math.floor(new Date(this.actualAt).getTime()/1000):Math.floor(Date.now()/1000),
            attended: this.attended,
            asset_ids: this.assetIds
          };
        } else {
          const pending = this.work.submissions.find((s) => s.state === 'PENDING');
          if (!pending) throw new Error('没有等待处理的提交');
          path = 'work/review';
          data = {
            submission_id: pending.id,
            note: this.reviewNote,
            accepted: this.accepted,
            items: this.items
          };
        }
        const r = await growthWrite(path, data);
        this.notice = r.data.message;
        this.work = null;
        await this.load();
      } catch (e) {
        this.formError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    parse(text) {
      try {
        return JSON.parse(text || '{}');
      } catch (e) {
        return {};
      }
    },
    time(t) {
      return t ? new Date(Number(t) * 1000).toLocaleString('zh-CN') : '不限期';
    },
    async preview(id) {
      try {
        const url = await growthPreview(id, 'practice', Number(this.work.task.id));
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
