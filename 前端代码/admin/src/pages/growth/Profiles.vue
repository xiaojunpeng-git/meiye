<template>
  <main class="growth-page">
    <header class="growth-head">
      <div>
        <h1>成长档案与考评</h1>
        <p>每份结果可追溯到原始学习、鉴定与考评记录。</p>
      </div>
      <button @click="tab = tab === 'profile' ? 'appraisal' : 'profile'">
        {{ tab === 'profile' ? '阶段考评' : '员工档案' }}
      </button>
    </header>
    <p v-if="error" class="growth-error">{{ error }}</p>
    <p v-if="notice" class="growth-success">{{ notice }}</p>
    <template v-if="tab === 'profile'"
      ><div class="growth-toolbar">
        <select v-model.number="employee" style="max-width: 320px" @change="loadProfile">
          <option :value="0">选择员工查看档案</option>
          <option v-for="p in profilePeople" :key="p.id" :value="Number(p.id)">
            {{ p.name }}{{ p.historical_only ? '（历史范围）' : '' }}
          </option>
        </select>
        <div class="growth-actions" v-if="profile">
          <button
            data-operation="growth.points.adjust"
            @click="
              dialog = 'points';
              form = { delta: 0, reason: '', basis: '' };
            "
          >
            积分调整</button
          ><button
            data-operation="growth.certificate.register"
            @click="
              dialog = 'certificate';
              form = { title: '', certificate_number: '', basis: '', issued_date: today, expires_date: '', asset_ids: [] };
            "
          >
            登记外部证书
          </button>
        </div>
      </div>
      <section v-if="profile" class="growth-results">
        <div class="growth-scroll" style="padding: 20px">
          <h2>{{ profile.employee.name }} · {{ profile.level ? profile.level.title : '尚无成长等级' }}</h2>
          <p>
            成长积分 {{ profile.point_balance }} · 岗位
            {{ profile.jobs.map((p) => p.position_name).join('、') || '暂无在岗记录' }}
          </p>
          <button @click="openHistory">查看完整成长来源</button>
          <h3>岗位能力</h3>
          <p v-if="!profile.capabilities.length">暂无岗位能力配置</p>
          <div v-for="r in profile.capabilities" :key="r.id" class="growth-chapter">
            {{ r.title }} · {{ state(r.conclusion) }}
          </div>
          <h3>有效证书与历史</h3>
          <p v-if="!profile.certificates.length">暂无证书</p>
          <article v-for="r in profile.certificates" :key="r.id" class="growth-chapter">
            <strong>{{ r.title }} · {{ state(r.state) }}</strong>
            <p>
              {{ r.certificate_number }} · {{ r.origin === 'external' ? '外部登记' : '系统发放' }} ·
              {{ r.expires_at ? date(r.expires_at) + ' 到期' : '长期有效' }}
            </p>
            <p v-if="r.evidence && r.evidence.basis">依据：{{ r.evidence.basis }}</p><button v-for="id in r.evidence && r.evidence.asset_ids || []" :key="'proof'+id" @click="previewCertificate(id)">查看证书附件</button><p v-for="h in r.history || []" :key="'cert-history'+h.id">{{ date(h.created_at) }} · {{ h.reason }}</p>
            <div v-if="r.state !== 'REVOKED'" class="growth-actions">
              <button @click="certificateAction(r, 'renew')">到期复核 / 续期</button
              ><button @click="certificateAction(r, 'revoke')">撤销</button>
            </div>
          </article>
          <h3>积分流水</h3>
          <table>
            <thead>
              <tr>
                <th>时间</th>
                <th>变动</th>
                <th>原因</th>
                <th>来源</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in profile.point_ledger" :key="r.id">
                <td>{{ date(r.occurred_at) }}</td>
                <td>{{ Number(r.delta) > 0 ? '+' : '' }}{{ r.delta }}</td>
                <td>{{ r.reason }}</td>
                <td>{{ r.source_type }} #{{ r.source_id }}</td>
              </tr>
            </tbody>
          </table>
          <p>{{ profile.point_scope_note }}</p>
          <p>此处展示最近100条成长来源；完整历史可按任务分页查询。</p><button @click="$router.push({path:'/admin/growth/tasks',query:{employee_id:employee}})">查看此员工全部任务</button><h3>学习任务</h3>
          <table>
            <thead>
              <tr>
                <th>任务</th>
                <th>状态</th>
                <th>结论</th>
                <th>完成时间</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in profile.tasks" :key="r.id">
                <td>{{ r.title }}</td>
                <td>{{ state(r.state) }}</td>
                <td>{{ state(r.conclusion) }}</td>
                <td>{{ date(r.completed_at) }}</td>
              </tr>
            </tbody>
          </table>
          <h3>考试记录（正式成绩）</h3>
          <table>
            <thead>
              <tr>
                <th>答卷</th>
                <th>次数</th>
                <th>分数</th>
                <th>结论</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in profile.attempts" :key="r.id">
                <td>#{{ r.id }}</td>
                <td>{{ r.attempt_no }}</td>
                <td>{{ r.score }} / {{ r.max_score }}</td>
                <td>{{ Number(r.passed) ? '通过' : '未通过' }}</td>
              </tr>
            </tbody>
          </table>
          <h3>人事任职历史（原系统记录）</h3>
          <article v-for="r in profile.personnel_history" :key="'job' + r.id" class="growth-chapter">
            <strong>{{ r.position_name || '原岗位' }}</strong>
            <p>
              {{ date(r.start_time) }} 至 {{ Number(r.end_time) ? date(r.end_time) : '当前任职' }} ·
              {{ r.reason || '任职生效记录' }}
            </p>
          </article>
          <h3>组织归属历史</h3><p v-for="r in profile.organization_history || []" :key="'organization'+r.id">{{ r.organization_name }} · {{ Number(r.status)===1 && !Number(r.is_del)?'有效归属':'历史归属' }}</p><h3>等级变化</h3>
          <article v-for="r in profile.level_history" :key="'level' + r.id" class="growth-chapter">
            <p>
              {{ r.previous_level_title || '尚无等级' }} → {{ r.level_title || '尚无等级' }} ·
              {{ r.point_balance }} 积分 · {{ r.reason }}
            </p>
          </article>
          <h3>任务处理历史</h3>
          <article v-for="r in profile.task_history" :key="'action' + r.id" class="growth-chapter">
            <p>{{ r.reason }} · {{ date(r.created_at) }}</p>
          </article>
          <h3>阶段考评</h3>
          <article v-for="r in profile.appraisals" :key="'appraisal' + r.id" class="growth-chapter">
            <p>{{ r.title }} · {{ r.result && Number(r.result.passed) ? '通过' : '未通过' }}</p>
            <p>{{ r.result ? r.result.reason : '' }}</p>
          </article>
        </div>
      </section>
      <section v-else class="growth-panel">选择员工后查看真实成长记录。</section></template
    ><template v-else
      ><div class="growth-actions">
        <button
          class="growth-primary"
          @click="
            dialog = 'appraisal';
            form = {
              title: '',
              employee_id: 0,
              handler_employee_id: 0,
              standard_release_id: 0,
              period_start: today,
              period_end: today
            };
          "
        >
          发起阶段考评</button
        ><button @click="standardOpen = true">新增考评标准</button>
      </div>
      <section class="growth-results">
        <div class="growth-scroll">
          <table>
            <thead>
              <tr>
                <th>考评</th>
                <th>员工</th>
                <th>周期</th>
                <th>状态</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in appraisals" :key="r.id">
                <td>{{ r.title }}</td>
                <td>{{ r.employee_name }}</td>
                <td>{{ r.period_start }} 至 {{ r.period_end }}</td>
                <td>{{ state(r.state) }}</td>
                <td><button @click="openAppraisal(r.id)">查看 / 处理</button></td>
              </tr>
              <tr v-if="!appraisals.length">
                <td colspan="5" class="growth-empty">暂无阶段考评</td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pager :page-data="appraisalPagination" @change="changeAppraisalPage" /></section
    ></template>
    <div v-if="dialog" class="growth-modal">
      <section class="growth-dialog">
        <header class="growth-dialog-head">
          <h2>{{ titles[dialog] }}</h2>
          <button @click="dialog = ''">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="dialogError" class="growth-error">{{ dialogError }}</p>
          <template v-if="dialog === 'points'"
            ><label>调整积分（正数增加，负数扣减）</label
            ><input v-model.number="form.delta" type="number" /><label>调整原因</label
            ><textarea v-model="form.reason"></textarea><label>依据</label
            ><textarea v-model="form.basis"></textarea></template
          ><template v-if="dialog === 'certificate'"
            ><label>证书名称</label><input v-model="form.title" /><label>证书编号</label
            ><input v-model="form.certificate_number" /><label>发放日期</label
            ><input v-model="form.issued_date" type="date" /><label>到期日期（留空长期）</label
            ><input v-model="form.expires_date" type="date" /><label>外部来源依据</label
            ><textarea v-model="form.basis"></textarea></template
          ><template v-if="dialog === 'appraisal'"
            ><label>考评名称</label><input v-model="form.title" /><label>被考评员工</label
            ><select v-model.number="form.employee_id">
              <option :value="0">请选择</option>
              <option v-for="p in people" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select
            ><label>考评负责人</label
            ><select v-model.number="form.handler_employee_id">
              <option :value="0">请选择</option>
              <option v-for="p in people" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select
            ><label>考评标准</label
            ><select v-model.number="form.standard_release_id">
              <option :value="0">请选择</option>
              <option v-for="s in standards" :key="s.id" :value="Number(s.release_id)">
                {{ s.title }}
              </option></select
            ><label>周期起始</label><input v-model="form.period_start" type="date" /><label>周期截止</label
            ><input v-model="form.period_end" type="date" /></template
          ><template v-if="dialog === 'review' && detail"
            ><p>{{ detail.appraisal.title }} · {{ detail.appraisal.employee_name }}</p>
            <article v-for="r in detail.results" :key="r.id" class="growth-chapter">
              <strong
                >{{ Number(r.passed) ? '通过' : '未通过' }} ·
                {{ r.score == null ? '逐项通过制' : r.score + ' 分' }}</strong
              >
              <p>
                {{ r.reason }} · {{ date(r.confirmed_at) }}
                {{ r.previous_result_id ? '（更正，原结果 #' + r.previous_result_id + ' 保留）' : '' }}
              </p>
            </article>
            <article v-for="o in detail.objections" :key="'o' + o.id" class="growth-chapter">
              <h3>员工异议 · {{ o.state === 'OPEN' ? '待处理' : '已处理' }}</h3>
              <p>{{ o.body }}</p>
              <p>{{ o.resolution }}</p>
              <button v-if="detail.can_handle && o.state === 'OPEN'" @click="resolve(o)">记录处理结果</button>
            </article>
            <template v-if="detail.can_handle"
              ><ReviewItems :standard="detail.standard" v-model="items" menu="profiles" /><label
                >考评依据 / 更正原因</label
              ><textarea v-model="form.reason"></textarea><label>下一步建议</label
              ><textarea v-model="form.next_steps"></textarea>
            </template>
            <p v-else>由指派考评负责人确认或更正结果。</p></template
          >
        </div>
        <footer class="growth-dialog-foot">
          <button @click="dialog = ''">返回</button
          ><button
            v-if="dialog !== 'review' || detail.can_handle"
            class="growth-primary"
            :disabled="busy"
            data-operation="growth.profile.save"
            @click="save"
          >
            {{ busy ? '保存中…' : '确认保存' }}
          </button>
        </footer>
      </section>
    </div>
    <div v-if="history" class="growth-modal"><section class="growth-dialog" role="dialog" aria-modal="true" aria-label="完整成长历史"><header class="growth-dialog-head"><h2>完整成长来源</h2><button @click="history=null">关闭</button></header><div class="growth-dialog-body"><label>记录类型<select v-model="historyKind" :disabled="historyBusy" @change="loadHistory(1)"><option v-for="(name,key) in history.types" :key="key" :value="key">{{ name }}</option></select></label><p v-if="historyError" class="growth-error">{{ historyError }}</p><article v-for="r in history.rows" :key="r.id" class="growth-chapter"><h3>{{ r.title }}</h3><p>{{ date(r.occurred_at) }} · {{ r.summary }}</p><button v-if="r.task_id" @click="$router.push({path:'/admin/growth/tasks',query:{task_id:r.task_id}})">查看原任务</button></article><p v-if="!history.rows.length">此类型暂无记录</p></div><Pager :page-data="history" :busy="historyBusy" @change="loadHistory" /></section></div>
    <StandardEditor
      v-if="standardOpen"
      purpose="appraisal"
      menu="profiles"
      @close="standardOpen = false"
      @saved="
        standardOpen = false;
        load();
      "
    />
  </main>
</template>
<script>
import Pager from './Pager.vue';
import EvidenceInput from './EvidenceInput.vue';
import { growthDate, growthRead, growthWrite, growthPreview } from '@/api/growth';
import StandardEditor from './StandardEditor.vue';
import ReviewItems from './ReviewItems.vue';
/** 档案读正式来源；积分调整、证书复核、考评更正均保存独立事实与原因。 */
export default {
  name: 'GrowthProfiles',
  components: { Pager, StandardEditor, ReviewItems, EvidenceInput },
  data: () => ({
    people: [],
    profilePeople: [],
    appraisalPagination: null,
    appraisalPage: 1,
    employee: 0,
    profile: null,
    tab: 'profile',
    appraisals: [],
    standards: [],
    standardOpen: false,
    dialog: '',
    dialogError: '',
    detail: null,history:null,historyKind:'tasks',historyBusy:false,historyError:'',
    items: {},
    form: {},
    busy: false,
    error: '',
    notice: '',
    today: growthDate(),
    titles: {
      points: '积分调整',
      certificate: '外部证书登记',
      appraisal: '发起阶段考评',
      review: '考评结果与异议'
    }
  }),
  created() {
    this.load();
    if(this.$route.query.appraisal_id){this.tab='appraisal';this.openAppraisal(Number(this.$route.query.appraisal_id));}
  },
  methods: {
    openHistory(){this.history={rows:[],types:{tasks:'学习与活动任务'}};this.historyKind='tasks';this.loadHistory(1);},async loadHistory(page){if(this.historyBusy)return;this.historyBusy=true;this.historyError='';try{this.history=(await growthRead('profile/history',{employee_id:this.employee,kind:this.historyKind,page})).data;}catch(e){this.historyError=e.msg||e.message;}finally{this.historyBusy=false;}},
    changeAppraisalPage(page) {
      this.appraisalPage = page;
      this.load();
    },
    state(s) {
      return (
        {
          VALID: '有效',
          EXPIRED: '已到期',
          REVOKED: '已撤销',
          COMPLETED: '已完成',
          CANCELLED: '已作废',
          PAUSED: '已暂停',
          NOT_STARTED: '未开始',
          IN_PROGRESS: '进行中',
          PENDING: '待确认',
          RETURNED: '待补充',
          PUBLISHED: '已公布',
          ASSIGNED: '待考评',
          PASSED: '通过',
          FAILED: '未通过',
          QUALIFIED: '合格',
          NEED_PRACTICE: '待加强',
          NOT_EVALUATED: '未鉴定'
        }[s] ||
        s ||
        '—'
      );
    },
    date(t) {
      return t ? new Date(Number(t) * 1000).toLocaleString('zh-CN') : '—';
    },
    async load() {
      try {
        const [c, a, s, p] = await Promise.all([
          growthRead('catalog', { menu: 'profiles' }),
          growthRead('appraisals', { page: this.appraisalPage }),
          growthRead('appraisal/catalog'),
          growthRead('profile/people')
        ]);
        this.people = c.data.employees;
        this.appraisals = a.data.rows;
        this.appraisalPagination = a.data.pagination;
        this.profilePeople = p.data;
        this.standards = s.data.standards.filter((x) => x.purpose === 'appraisal');
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async loadProfile() {
      if (!this.employee) {
        this.profile = null;
        return;
      }
      try {
        this.profile = (await growthRead('profile', { employee_id: this.employee })).data;
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async openAppraisal(id) {
      this.dialogError = '';
      try {
        this.detail = (await growthRead('appraisal', { id })).data;
        this.items = {};
        this.detail.standard.items.forEach((i) => {
          this.$set(this.items, i.key, {
            passed: null,
            score: null,
            note: '',
            asset_ids: [],
            not_applicable: false,
            reason: ''
          });
        });
        this.form = { id, reason: '', next_steps: '' };
        this.dialog = 'review';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async previewCertificate(id) {try{const url=await growthPreview(id,'profiles');window.open(url,'_blank','noopener');setTimeout(()=>URL.revokeObjectURL(url),60000);}catch(e){this.error=e.message;}},
    async certificateAction(r, action) {
      const reason = window.prompt(action === 'renew' ? '填写复核通过依据' : '填写撤销原因');
      if (!reason) return;
      let expires = 0;
      if (action === 'renew') {
        const d = window.prompt('新的到期日期（YYYY-MM-DD）');
        if (!d) return;
        expires = Date.parse(d + 'T23:59:59') / 1000;
      }
      try {
        await growthWrite('certificate/action', { id: r.id, action, reason, expires_at: expires });
        await this.loadProfile();
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async resolve(o) {
      const resolution = window.prompt('处理依据（如需改分，请先确认更正结果）');
      if (!resolution) return;
      try {
        await growthWrite('objection/resolve', { objection_id: o.id, resolution });
        await this.openAppraisal(o.appraisal_id);
      } catch (e) {
        this.dialogError = e.msg || e.message;
      }
    },
    async save() {
      this.busy = true;
      this.dialogError = '';
      try {
        let path = '',
          body = { ...this.form };
        if (this.dialog === 'points') {
          path = 'points/adjust';
          body.employee_id = this.employee;
        }
        if (this.dialog === 'certificate') {
          path = 'certificate/register';
          body = {
            ...body,
            employee_id: this.employee,
            issued_at: Date.parse(body.issued_date + 'T00:00:00') / 1000,
            expires_at: body.expires_date ? Date.parse(body.expires_date + 'T23:59:59') / 1000 : 0
          };
        }
        if (this.dialog === 'appraisal') path = 'appraisal/create';
        if (this.dialog === 'review') {
          path = 'appraisal/confirm';
          body.items = this.items;
        }
        const r = await growthWrite(path, body);
        this.notice = r.data.message;
        this.dialog = '';
        await this.load();
        await this.loadProfile();
      } catch (e) {
        this.dialogError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    }
  }
};
</script>
<style src="./growth.css"></style>
