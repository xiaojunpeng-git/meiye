<template>
  <main class="growth-page">
    <header class="growth-head">
      <div>
        <h1>练习与考试</h1>
        <p>从专业知识到能力检验，每份答卷都有据可查。</p>
      </div>
      <div class="growth-actions">
        <button @click="newQuestion">＋ 录入题目</button><button @click="newPaper">组卷发布</button
        ><button class="growth-primary" @click="newExam">发布练考</button>
      </div>
    </header>
    <p v-if="error" class="growth-error" role="alert">{{ error }}</p>
    <p v-if="notice" class="growth-success" role="status">{{ notice }}</p>
    <nav class="growth-actions" style="justify-content: flex-start">
      <button
        v-for="(label, key) in tabs"
        :key="key"
        :class="tab === key ? 'growth-primary' : ''"
        @click="tab = key"
      >
        {{ label }}
      </button>
    </nav>
    <section class="growth-results">
      <div class="growth-scroll">
        <table v-if="tab === 'feedback'">
          <thead>
            <tr>
              <th>题目</th>
              <th>反馈内容</th>
              <th>状态</th>
              <th>处理结论</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in feedback" :key="f.id">
              <td style="white-space: normal; max-width: 300px">{{ f.stem }}</td>
              <td style="white-space: normal; max-width: 360px">{{ f.description }}</td>
              <td>{{ f.state === 'OPEN' ? '待核查' : '已处理' }}</td>
              <td>{{ f.resolution || '—' }}</td>
              <td><button v-if="f.state === 'OPEN'" @click="resolveFeedback(f)">记录核查结论</button></td>
            </tr>
            <tr v-if="!feedback.length">
              <td colspan="5" class="growth-empty">暂无题目反馈</td>
            </tr>
          </tbody>
        </table>
        <table v-if="tab === 'questions'">
          <thead>
            <tr>
              <th>选择</th>
              <th>题干</th>
              <th>题型</th>
              <th>分值</th>
              <th>分类</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="q in bank.rows" :key="q.id">
              <td><input v-model="selected" type="checkbox" :value="Number(q.id)" /></td>
              <td style="max-width: 540px; white-space: normal">{{ q.stem }}</td>
              <td>{{ types[q.type] }}</td>
              <td>{{ q.score }}</td>
              <td>{{ q.category || '—' }}</td>
              <td><button @click="editQuestion(q)">编辑</button></td>
            </tr>
            <tr v-if="!bank.rows.length">
              <td colspan="6" class="growth-empty">先录入专业知识题目，再创建试卷。</td>
            </tr>
          </tbody>
        </table>
        <table v-if="tab === 'papers'">
          <thead>
            <tr>
              <th>试卷</th>
              <th>题量</th>
              <th>组卷方式</th>
              <th>状态</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in bank.papers" :key="p.id">
              <td>{{ p.title }}</td>
              <td>{{ p.draft.mode === 'random' ? p.draft.draw_count : p.draft.question_ids.length }}</td>
              <td>{{ p.draft.mode === 'random' ? '随机抽题' : '固定试卷' }}</td>
              <td>已发布</td>
            </tr>
            <tr v-if="!bank.papers.length">
              <td colspan="4" class="growth-empty">暂无试卷</td>
            </tr>
          </tbody>
        </table>
        <table v-if="tab === 'exams'">
          <thead>
            <tr>
              <th>名称</th>
              <th>用途</th>
              <th>通过线</th>
              <th>允许次数</th>
              <th>答题时长</th>
              <th>成绩规则</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in exams" :key="e.id">
              <td>{{ e.title }}</td>
              <td>{{ e.kind === 'practice' ? '练习' : '考试' }}</td>
              <td>{{ e.pass_score }} 分</td>
              <td>{{ e.max_attempts }}</td>
              <td>
                {{ Number(e.duration_seconds) > 0 ? Number(e.duration_seconds) / 60 + '分钟' : '不限时' }}
              </td>
              <td>最高有效成绩</td>
            </tr>
            <tr v-if="!exams.length">
              <td colspan="6" class="growth-empty">暂无练考任务</td>
            </tr>
          </tbody>
        </table>
        <table v-if="tab === 'results'">
          <thead>
            <tr>
              <th>员工</th>
              <th>考试</th>
              <th>次数</th>
              <th>状态</th>
              <th>成绩</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in attempts" :key="a.id">
              <td>{{ a.employee_name }}</td>
              <td>{{ a.title }}</td>
              <td>第 {{ a.attempt_no }} 次</td>
              <td>{{ states[a.state] }}</td>
              <td>{{ a.state === 'PUBLISHED' ? a.score + ' / ' + a.max_score : '—' }}</td>
              <td>
                <button @click="review(a.id)">
                  {{ ['GRADING', 'HELD'].includes(a.state) ? '阅卷 / 公布' : '查看' }}
                </button>
              </td>
            </tr>
            <tr v-if="!attempts.length">
              <td colspan="6" class="growth-empty">等待员工开始练考</td>
            </tr>
          </tbody>
        </table>
      </div>
      <footer v-if="tab === 'questions'" class="growth-pager">
        <button @click="downloadTemplate">下载导入模板</button
        ><label style="margin: 0"
          >导入题库 <input type="file" accept=".xlsx,.csv" @change="importFile" /></label
        ><span>共 {{ bank.total }} 条 · 每页 20 条 · 第 {{ bank.page }} / {{ bank.total_pages }} 页</span
        ><button :disabled="bank.page <= 1" @click="load(bank.page - 1)">上一页</button
        ><button :disabled="bank.page >= bank.total_pages" @click="load(bank.page + 1)">下一页</button>
      </footer>
      <Pager v-if="tab !== 'questions'" :page-data="pagination[tab]" :busy="busy" @change="changePage" />
    </section>
    <div v-if="form" class="growth-modal">
      <section class="growth-dialog" role="dialog" aria-modal="true">
        <header class="growth-dialog-head">
          <h2>
            {{ formType === 'question' ? '题目内容' : formType === 'paper' ? '发布试卷' : '发布练习 / 考试' }}
          </h2>
          <button :disabled="busy" @click="form = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="formError" class="growth-error" role="alert">{{ formError }}</p>
          <template v-if="formType === 'question'"
            ><label>题型</label
            ><select v-model="form.type">
              <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option></select
            ><label>题干</label><textarea v-model="form.stem" rows="3"></textarea
            ><template v-if="!['text', 'boolean'].includes(form.type)"
              ><label>选项（每行一个，最多10项）</label
              ><textarea v-model="optionsText" rows="4"></textarea></template
            ><label v-if="form.type !== 'text'">{{
              form.type === 'order'
                ? '正确答案顺序（按先后填写全部选项序号，例如3,1,2）'
                : '正确答案（选项序号，从1开始；多选用逗号分开）'
            }}</label
            ><input
              v-if="form.type !== 'text'"
              v-model="answerText"
              placeholder="例如：1,3；判断题1为正确，2为错误"
            /><label v-if="form.type === 'text'">简答评分依据</label
            ><textarea v-if="form.type === 'text'" v-model="form.basis" rows="3"></textarea>
            <div class="growth-form-grid">
              <div>
                <label>分值</label><input v-model.number="form.score" type="number" min="1" max="1000" />
              </div>
              <div><label>分类</label><input v-model="form.category" /></div>
            </div>
            <label>解析</label><textarea v-model="form.explanation" rows="2"></textarea><label>状态</label
            ><select v-model="form.state">
              <option value="ACTIVE">启用</option>
              <option value="STOPPED">停用</option>
            </select></template
          >
          <template v-if="formType === 'paper'"
            ><label>试卷名称</label><input v-model="form.title" />
            <p>已在题库选择 {{ selected.length }} 道题。可关闭窗口后跨页继续勾选。</p>
            <label>组卷方式</label
            ><select v-model="form.mode">
              <option value="fixed">固定题目</option>
              <option value="random">从所选题池随机抽取</option></select
            ><label v-if="form.mode === 'random'">每次抽取题数</label
            ><input
              v-if="form.mode === 'random'"
              v-model.number="form.draw_count"
              type="number"
              min="1"
              :max="selected.length"
            />
            <template v-if="form.mode==='fixed'"><h3>题序与本卷分值</h3><article v-for="(id,index) in selected" :key="id" class="growth-chapter"><p>{{ index+1 }} · {{ questionCache[id]?questionCache[id].stem:'题目 #'+id }}</p><div class="growth-actions"><button :disabled="index===0" @click="moveQuestion(index,-1)">上移</button><button :disabled="index===selected.length-1" @click="moveQuestion(index,1)">下移</button></div><label>本卷分值（留空沿用题库）<input v-model.number="form.question_scores[id]" type="number" min="1" max="10000" /></label></article></template>
            <template v-if="form.mode==='random'"><h3>按分类与题型分别抽题（可选）</h3><p>从已勾选题池中匹配规则。分类留空表示全部分类；规则不能重叠，未匹配题目不入卷。配置后总题数取各组之和。</p><article v-for="(r,index) in form.draw_rules" :key="index" class="growth-chapter"><label>题型<select v-model="r.type"><option value="">全部题型</option><option v-for="(name,type) in types" :key="type" :value="type">{{ name }}</option></select></label><label>知识分类<input v-model="r.category" placeholder="与题库知识分类一致" /></label><label>抽取数量<input v-model.number="r.count" type="number" min="1" max="200" /></label><label>本组每题分值<input v-model.number="r.score" type="number" min="1" max="10000" /></label><button @click="form.draw_rules.splice(index,1)">移除本组</button></article><button @click="form.draw_rules.push({type:'',category:'',count:1,score:10})">添加抽题规则</button></template>
            <p style="margin-top: 18px">
              多选判分：全对得分，错选、漏选不得分。顺序题：按正确顺序选齐全部选项才得分。题目在员工开始时固定，重进不重抽。
            </p></template
          >
          <template v-if="formType === 'exam'"
            ><label>名称</label><input v-model="form.title" />
            <div class="growth-form-grid">
              <div>
                <label>用途</label
                ><select v-model="form.kind">
                  <option value="exam">正式考试</option>
                  <option value="practice">练习</option>
                </select>
              </div>
              <div>
                <label>试卷</label
                ><select v-model.number="form.paper_release_id">
                  <option :value="0">请选择</option>
                  <option v-for="p in bank.paper_options" :key="p.id" :value="Number(p.release_id)">
                    {{ p.title }}
                  </option>
                </select>
              </div>
            </div>
            <label>接收员工</label>
            <div class="growth-persons">
              <label v-for="p in employees" :key="p.id"
                ><input v-model="form.employee_ids" type="checkbox" :value="Number(p.id)" />{{
                  p.name
                }}</label
              >
            </div>
            <div class="growth-form-grid">
              <div>
                <label>允许次数</label
                ><input v-model.number="form.max_attempts" type="number" min="1" max="20" />
              </div>
              <div>
                <label>及格线（分）</label><input v-model.number="form.pass_score" type="number" min="0" />
              </div>
              <div>
                <label>答题时长（分钟，0为不限时）</label
                ><input v-model.number="form.duration_minutes" type="number" min="0" max="1440" />
              </div>
              <div>
                <label>阅卷 / 公布负责人</label
                ><select v-model.number="form.grader_employee_id">
                  <option :value="0">无人工环节</option>
                  <option v-for="p in employees" :key="p.id" :value="Number(p.id)">{{ p.name }}</option>
                </select>
              </div>
              <div><label>开放时间（可不填）</label><input v-model="starts" type="datetime-local" /></div>
              <div><label>截止时间（可不填）</label><input v-model="ends" type="datetime-local" /></div>
            </div>
            <label>本次积分与证书<select v-model="form.reward_selection.mode"><option value="default">使用企业已启用奖励规则</option><option value="selected">明确选择（都不选则不发奖励）</option></select></label><div v-if="form.reward_selection.mode==='selected'"><label v-for="r in pointRules" :key="'point'+r.id"><input type="checkbox" :value="Number(r.id)" v-model="form.reward_selection.point_rule_ids" />积分 · {{ r.title }}</label><label v-for="r in certificateRules" :key="'cert'+r.id"><input type="checkbox" :value="Number(r.id)" v-model="form.reward_selection.certificate_rule_ids" />证书 · {{ r.title }}</label></div>
            <label>关键必过题（可选，须属于所选试卷）<select multiple v-model="form.key_question_ids" size="4"><option v-for="q in questionOptions" :key="q.id" :value="Number(q.id)">{{ q.stem }}</option></select></label><label v-if="form.kind==='practice'">练习解析时机<select v-model="form.practice_analysis_timing"><option value="after_submit">交卷公布后</option><option value="immediate">每题主动查看即时解析</option></select></label>
            <label><input v-model="form.required_pass" type="checkbox" />达到通过要求才完成任务</label
            ><label><input v-model="form.auto_publish" type="checkbox" />自动评分 / 阅卷后自动公布</label
            ><label><input v-model="form.show_analysis" type="checkbox" />成绩公布后可看答案与解析</label
            ><label>考试须知</label><textarea v-model="form.instructions" rows="3"></textarea>
            <p>补考保留每次答卷，采用最高有效成绩；发布时固定本次名单、试卷与规则。</p></template
          >
        </div>
        <footer class="growth-dialog-foot">
          <button :disabled="busy" @click="form = null">取消</button
          ><button class="growth-primary" :disabled="busy" @click="submit">
            {{ busy ? '处理中…' : formType === 'question' ? '保存题目' : '确认发布' }}
          </button>
        </footer>
      </section>
    </div>
    <div v-if="answerSheet" class="growth-modal">
      <section class="growth-dialog" role="dialog" aria-modal="true">
        <header class="growth-dialog-head">
          <h2>{{ answerSheet.title }} · 答卷</h2>
          <button @click="answerSheet = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="formError" class="growth-error">{{ formError }}</p>
          <p>
            {{ states[answerSheet.state] }} ·
            {{
              answerSheet.score === undefined
                ? '成绩未形成'
                : answerSheet.score + ' / ' + answerSheet.max_score
            }}
          </p>
          <section v-for="q in answerSheet.questions" :key="q.id" class="growth-chapter">
            <h3>{{ q.stem }}（{{ q.score }}分）</h3>
            <p>作答：{{ formatAnswer(q, answerSheet.answers[q.id]) }}</p>
            <p v-if="q.basis">评分依据：{{ q.basis }}</p>
            <div v-if="q.type === 'text'">
              <label>本题得分</label
              ><input v-model.number="grades[q.id].score" type="number" min="0" :max="q.score" /><label
                >评分说明</label
              ><textarea v-model="grades[q.id].note" rows="2"></textarea>
            </div>
            <p v-else>正确答案：{{ formatAnswer(q, q.answer) }}</p>
          </section>
          <label v-if="answerSheet.state === 'PUBLISHED'">成绩更正原因</label
          ><textarea v-if="answerSheet.state === 'PUBLISHED'" v-model="correctionReason" rows="2"></textarea>
        </div>
        <footer class="growth-dialog-foot">
          <button class="growth-primary" :disabled="busy" @click="grade">
            {{ answerSheet.state === 'PUBLISHED' ? '确认成绩更正' : '确认阅卷并公布' }}
          </button>
        </footer>
      </section>
    </div>
  </main>
</template>
<script>
import Pager from './Pager.vue';
import { growthRead, growthWrite } from '@/api/growth';
import * as XLSX from 'xlsx';
/** 题库、试卷、练考和成绩各有原记录；平台不自行计算权威得分。 */
export default {
  name: 'GrowthExams',
  components: { Pager },
  data: () => ({
    pagination: {},
    pageQuery: { papers_page: 1, exams_page: 1, results_page: 1, feedback_page: 1 },
    tab: 'questions',
    tabs: {
      questions: '题库',
      papers: '试卷',
      exams: '练习与考试',
      results: '答卷与成绩',
      feedback: '题目反馈'
    },
    types: { single: '单选', multiple: '多选', boolean: '判断', order: '顺序题', text: '简答' },
    states: { ANSWERING: '作答中', GRADING: '待阅卷', HELD: '待公布', PUBLISHED: '已公布' },
    bank: { rows: [], papers: [], total: 0, page: 1, total_pages: 1 },
    feedback: [],
    selected: [], questionCache:{}, questionOptions:[],pointRules:[],certificateRules:[],
    exams: [],
    attempts: [],
    employees: [],
    error: '',
    notice: '',
    form: null,
    formType: '',
    formError: '',
    busy: false,
    optionsText: '',
    answerText: '',
    starts: '',
    ends: '',
    answerSheet: null,
    grades: {},
    correctionReason: ''
  }),
  created() {
    this.load(1);
    if(this.$route.query.attempt_id)this.review(Number(this.$route.query.attempt_id));
  },
  methods: {
    async resolveFeedback(f) {
      const resolution = window.prompt('填写题目核查结论；更正题库或成绩请从原业务另行处理。');
      if (!resolution) return;
      try {
        this.notice = (await growthWrite('question/feedback/resolve', { id: f.id, resolution })).data.message;
        await this.load(this.bank.page);
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    downloadTemplate() {
      const csv =
        '\uFEFF题型,题干,选项,答案,分值,评分依据,解析,知识分类\n顺序题,按正确先后完成准备,核对需求|准备用品|清洁双手,"3,1,2",10,,按标准操作顺序选择全部选项,服务规范\n多选,服务前应做哪些准备,核对需求|清洁双手|跳过询问,"1,2",10,,两个选项均需选对,服务规范\n';
      const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = '题库导入模板.csv';
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 10000);
    },
    changePage(page) {
      this.$set(this.pageQuery, this.tab + '_page', page);
      this.load(this.bank.page);
    },
    async load(page) {
      try {
        const [bank, exams, catalog, feedback] = await Promise.all([
          growthRead('questions', { page, ...this.pageQuery }),
          growthRead('exams', this.pageQuery),
          growthRead('catalog', { menu: 'exams' }),
          growthRead('question/feedback', { page: this.pageQuery.feedback_page })
        ]);
        this.feedback = feedback.data.rows;
        this.pagination = {
          ...exams.data.pagination,
          papers: bank.data.papers_pagination,
          feedback: feedback.data.pagination
        };
        this.bank = bank.data; bank.data.rows.forEach(q=>this.$set(this.questionCache,q.id,q));this.questionOptions=catalog.data.question_options;this.pointRules=catalog.data.point_rule_options;this.certificateRules=catalog.data.certificate_rule_options;
        this.exams = exams.data.exams;
        this.attempts = exams.data.attempts;
        this.employees = catalog.data.employees;
        this.error = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    newQuestion() {
      this.formType = 'question';
      this.formError = '';
      this.form = {
        id: 0,
        type: 'single',
        stem: '',
        score: 10,
        category: '',
        basis: '',
        explanation: '',
        state: 'ACTIVE'
      };
      this.optionsText = '';
      this.answerText = '';
    },
    editQuestion(q) {
      this.formType = 'question';
      this.form = { ...q };
      this.formError = '';
      this.optionsText = q.options.join('\n');
      this.answerText = q.answer.map((i) => Number(i) + 1).join(',');
    },
    // 顺序和单题分值归本次试卷，题库原记录保持独立。
    moveQuestion(index,direction){const target=index+direction;if(target<0||target>=this.selected.length)return;const next=[...this.selected];[next[index],next[target]]=[next[target],next[index]];this.selected=next;},
    newPaper() {
      this.formType = 'paper';
      this.formError = '';
      this.form = { title: '', mode: 'fixed', draw_count: this.selected.length, question_scores:{}, draw_rules:[] };
    },
    newExam() {
      this.formType = 'exam';
      this.formError = '';
      this.starts = '';
      this.ends = '';
      this.form = {
        title: '',
        kind: 'exam',
        paper_release_id: 0,
        employee_ids: [],
        max_attempts: 2,
        pass_score: 60,
        duration_minutes: 0,
        grader_employee_id: 0,
        auto_publish: true,
        show_analysis: false,
        required_pass: true,key_question_ids:[],practice_analysis_timing:'after_submit',reward_selection:{mode:'default',point_rule_ids:[],certificate_rule_ids:[]},
        instructions: ''
      };
    },
    async submit() {
      this.busy = true;
      this.formError = '';
      try {
        let payload = { ...this.form };
        let path = '';
        if (this.formType === 'question') {
          payload.options = this.optionsText.split('\n').filter((x) => x.trim());
          payload.answer = this.answerText
            .split(/[,，]/)
            .filter((x) => x.trim())
            .map((x) => Number(x.trim()) - 1);
          path = 'question/save';
        } else if (this.formType === 'paper') {
          payload.question_ids = this.selected;
          path = 'paper/publish';
        } else {
          payload.starts_at = this.starts ? Math.floor(new Date(this.starts).getTime() / 1000) : 0;
          payload.ends_at = this.ends ? Math.floor(new Date(this.ends).getTime() / 1000) : 0;
          path = 'exam/create';
        }
        const r = await growthWrite(path, payload);
        this.notice = r.data.message;
        this.form = null;
        await this.load(this.bank.page);
      } catch (e) {
        this.formError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    async importFile(event) {
      const file = event.target.files[0];
      if (!file) return;
      try {
        const workbook = XLSX.read(await file.arrayBuffer(), { type: 'array' });
        const lines = XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], { defval: '' });
        const mapping = {
          单选: 'single',
          多选: 'multiple',
          判断: 'boolean',
          顺序: 'order',
          顺序题: 'order',
          简答: 'text'
        };
        const rows = lines.map((r) => ({
          type: mapping[r['题型']] || r.type,
          stem: r['题干'] || r.stem,
          options: String(r['选项'] || r.options || '').split('|'),
          answer: String(r['答案'] || r.answer || '')
            .split(/[,，]/)
            .filter(Boolean)
            .map((x) => Number(x) - 1),
          basis: r['评分依据'] || r.basis || '',
          explanation: r['解析'] || '',
          category: r['知识分类'] || '',
          score: Number(r['分值'] || 1)
        }));
        const r = await growthWrite('question/import', { rows });
        this.notice = `导入成功 ${r.data.success_count} 题，失败 ${r.data.failure_count} 题${
          r.data.errors.length
            ? '；' + r.data.errors.map((e) => '第' + e.row + '行：' + e.reason).join('；')
            : ''
        }`;
        await this.load(1);
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async review(id) {
      try {
        this.answerSheet = (await growthRead('attempt', { id })).data;
        this.grades = {};
        this.answerSheet.questions.forEach((q) => {
          this.$set(this.grades, q.id, {
            score:
              this.answerSheet.grades && this.answerSheet.grades[q.id]
                ? this.answerSheet.grades[q.id].score
                : 0,
            note: ''
          });
        });
        this.formError = '';
        this.correctionReason = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async grade() {
      this.busy = true;
      try {
        await growthWrite(this.answerSheet.state === 'PUBLISHED' ? 'attempt/correct' : 'attempt/grade', {
          attempt_id: this.answerSheet.id,
          grades: this.grades,
          reason: this.correctionReason
        });
        this.answerSheet = null;
        this.notice = '成绩已确认';
        await this.load(this.bank.page);
      } catch (e) {
        this.formError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    formatAnswer(q, value) {
      return q.type === 'text'
        ? String(value || '未作答')
        : Array.isArray(value) && value.length
        ? value.map((i) => q.options[i]).join(q.type === 'order' ? ' → ' : '、')
        : '未作答';
    }
  }
};
</script>
<style src="./growth.css"></style>
