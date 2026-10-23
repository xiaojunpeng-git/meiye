<template>
  <main class="growth-page">
    <header class="growth-head">
      <div>
        <h1>课程与知识</h1>
        <p>把专业知识变成美容师随时可学的好内容。</p>
      </div>
      <button class="growth-primary" data-operation="growth.content.create" @click="create">
        ＋ 新建内容
      </button>
    </header>
    <p v-if="error" role="alert" class="growth-error">{{ error }}</p>
    <p v-if="notice" role="status" class="growth-success">{{ notice }}</p>
    <form class="growth-toolbar" @submit.prevent="load(1)">
      <input v-model="keyword" placeholder="搜索课程或知识名称" aria-label="搜索课程" /><button>查询</button>
    </form>
    <section class="growth-results">
      <div class="growth-scroll">
        <table>
          <thead>
            <tr>
              <th>名称</th>
              <th>形式</th>
              <th>分类</th>
              <th>状态</th>
              <th>更新时间</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in list.rows" :key="row.id">
              <td>{{ row.title || '未命名草稿' }}</td>
              <td>{{ row.kind === 'knowledge' ? '知识' : '课程' }}</td>
              <td>{{ row.category || '—' }}</td>
              <td>
                <span class="growth-badge">{{ stateLabel(row.state) }}</span>
              </td>
              <td>{{ time(row.updated_at) }}</td>
              <td>
                <button @click="edit(row.id)">编辑 / 发布</button
                ><button v-if="row.current_publication_id && row.state === 'PUBLISHED'" @click="assign(row)">
                  分配学习</button
                ><button v-if="row.state !== 'STOPPED'" class="growth-danger" @click="stop(row)">停用</button>
              </td>
            </tr>
            <tr v-if="!list.rows.length">
              <td colspan="6" class="growth-empty">还没有内容，先创建一门课程吧。</td>
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
    <div v-if="draft" class="growth-modal" @click.self="close">
      <section class="growth-dialog" role="dialog" aria-modal="true" aria-labelledby="growth-editor-title">
        <header class="growth-dialog-head">
          <h2 id="growth-editor-title">{{ draft.id ? '编辑内容' : '新建内容' }}</h2>
          <button aria-label="关闭编辑" :disabled="busy" @click="close">✕</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="editorError" class="growth-error" role="alert">{{ editorError }}</p>
          <label
            >使用规则模板（可选）<select v-model="selectedTemplate" @change="applyTemplate">
              <option value="">使用当前配置</option>
              <option v-for="t in ruleTemplates" :key="t.key" :value="t.key">
                {{ t.title }} · {{ t.purpose === 'knowledge' ? '知识' : '课程' }}
              </option>
            </select></label
          >
          <p>
            {{
              draft.rule_template && draft.rule_template.title
                ? '来源：' + draft.rule_template.title + '，可继续按本次内容调整'
                : '来源：企业默认 / 本次填写'
            }}
          </p>
          <div class="growth-form-grid">
            <div>
              <label for="growth-title">名称</label
              ><input
                id="growth-title"
                v-model="draft.title"
                maxlength="180"
                placeholder="例如：面部护理服务标准"
              />
            </div>
            <div>
              <label for="growth-kind">内容用途</label
              ><select id="growth-kind" v-model="draft.kind">
                <option value="course">课程学习</option>
                <option value="knowledge">知识查阅</option>
              </select>
            </div>
            <div>
              <label for="growth-category">分类</label
              ><input
                id="growth-category"
                v-model="draft.category"
                maxlength="100"
                placeholder="例如：专业技能"
              />
            </div>
            <div>
              <label for="growth-confirmer">发布确认</label
              ><select id="growth-confirmer" v-model.number="draft.confirmer_employee_id">
                <option :value="0">直接发布</option>
                <option v-for="p in employees" :key="p.id" :value="p.id">由 {{ p.name }} 确认</option>
              </select>
            </div>
          </div>
          <template v-if="draft.kind === 'knowledge'"
            ><label>知识查阅范围</label
            ><select v-if="draft.audience" v-model="draft.audience.mode">
              <option value="current">发布者当前任职门店 / 组织</option>
              <option value="personal">仅发布者本人</option>
              <option value="all">全员（需超级管理员）</option>
            </select></template
          >
          <div class="growth-form-grid">
            <label>讲师 / 内容提供者<input v-model="draft.lecturer" maxlength="100" /></label
            ><label
              >预计学习分钟<input v-model.number="draft.estimated_minutes" type="number" min="0" max="100000"
            /></label>
          </div>
          <label
            >课程封面（可选）<input
              type="file"
              accept=".jpg,.jpeg,.png,.webp"
              :disabled="busy"
              @change="uploadAttachment('cover', $event)"
          /></label>
          <p v-if="draft.cover_asset_id">
            已选封面 #{{ draft.cover_asset_id }}
            <button @click="preview({ asset_id: draft.cover_asset_id, type: 'image' })">预览封面</button
            ><button @click="draft.cover_asset_id = 0">移除封面</button>
          </p>
          <label
            >补充资料（可选，最多30份）<input
              type="file"
              accept=".pdf,.doc,.docx,.ppt,.pptx,.mp4,.webm,.jpg,.jpeg,.png,.webp"
              :disabled="busy"
              @change="uploadAttachment('material', $event)"
          /></label>
          <p v-for="(a, ai) in draft.materials" :key="a.id">
            {{ a.original_name }}
            <button
              @click="
                preview({
                  asset_id: a.id,
                  type: ['ppt', 'pptx', 'doc', 'docx', 'pdf'].includes(a.format)
                    ? 'pdf'
                    : ['mp4', 'webm'].includes(a.format)
                    ? 'video'
                    : 'image'
                })
              "
            >
              预览资料</button
            ><button @click="removeMaterial(ai)">移除资料</button>
          </p>
          <label for="growth-description">简介</label
          ><textarea
            id="growth-description"
            v-model="draft.description"
            rows="2"
            placeholder="学习后能掌握什么？"
          ></textarea>
          <h3 style="margin-top: 22px">章节内容</h3>
          <p>PPT、Word会转成课程内可读预览；同一课程可混合视频、文档和图文。</p>
          <section v-for="(ch, i) in draft.chapters" :key="ch.key" class="growth-chapter">
            <div class="growth-actions">
              <strong>第 {{ i + 1 }} 章</strong>
              <div>
                <button :disabled="i === 0 || busy" @click="move(i, -1)">上移</button
                ><button :disabled="i === draft.chapters.length - 1 || busy" @click="move(i, 1)">下移</button
                ><button class="growth-danger" :disabled="busy" @click="draft.chapters.splice(i, 1)">
                  移除
                </button>
              </div>
            </div>
            <div class="growth-form-grid">
              <div>
                <label :for="'chapter-title-' + i">章节名称</label
                ><input :id="'chapter-title-' + i" v-model="ch.title" />
              </div>
              <div>
                <label :for="'chapter-type-' + i">内容形式</label
                ><select :id="'chapter-type-' + i" v-model="ch.type" @change="changeType(ch)">
                  <option value="text">长图文 / 正文</option>
                  <option value="ppt">PPT课件</option>
                  <option value="word">Word文档</option>
                  <option value="video">视频</option>
                  <option value="image">长图 / 图片</option>
                  <option value="pdf">PDF</option>
                </select>
              </div>
            </div>
            <div v-if="ch.type === 'text'">
              <label :for="'chapter-body-' + i">正文</label
              ><textarea
                :id="'chapter-body-' + i"
                v-model="ch.body"
                rows="6"
                placeholder="按段落输入课程正文"
              ></textarea>
            </div>
            <div v-else>
              <label :for="'chapter-file-' + i">上传文件（最多50MB）</label
              ><input
                :id="'chapter-file-' + i"
                type="file"
                :accept="accept(ch.type)"
                :disabled="busy"
                @change="upload(ch, $event)"
              />
              <p v-if="ch.asset">{{ ch.asset.original_name }} · {{ assetLabel(ch.asset) }}</p>
              <button v-if="ch.asset && ch.asset.state === 'READY'" @click="preview(ch)">打开预览</button
              ><button v-if="ch.asset && ch.asset.state === 'FAILED'" :disabled="busy" @click="retry(ch)">
                重试转换
              </button>
            </div>
            <div class="growth-form-grid">
              <label><input v-model="ch.required" type="checkbox" />本章必学</label>
              <div v-if="ch.type === 'video'">
                <label>完成方式</label
                ><select v-model="ch.completion">
                  <option value="confirm">确认学习</option>
                  <option value="coverage">达到有效观看覆盖</option></select
                ><label v-if="ch.completion === 'coverage'"
                  >覆盖比例（%）<input v-model.number="ch.coverage_percent" type="number" min="1" max="100"
                /></label>
              </div>
            </div>
            <template v-if="ch.type === 'video' && ch.completion === 'coverage'"
              ><label><input type="checkbox" v-model="ch.foreground_required" />只计前台有效播放</label
              ><label><input type="checkbox" v-model="ch.allow_speed" />允许倍速计入有效覆盖</label>
              <div v-if="ch.allow_speed" class="growth-actions">
                <label v-for="rate in [0.5, 0.8, 1, 1.25, 1.5]" :key="rate"
                  ><input type="checkbox" :value="rate" v-model="ch.allowed_rates" />{{ rate }}倍</label
                >
              </div>
              <label>拖动定位策略</label
              ><select v-model="ch.seek_policy">
                <option value="allow">允许定位，跳过部分不计有效覆盖</option>
                <option value="watched_only">只允许定位到已观看位置附近</option></select
              ><label><input type="checkbox" v-model="ch.single_session" />仅保留一个正式学习会话</label
              ><label><input type="checkbox" v-model="ch.idle_prompt" />长时间未响应时暂停并提醒</label
              ><label v-if="ch.idle_prompt"
                >等待时间（秒）<input type="number" v-model.number="ch.idle_seconds" min="60" max="3600"
              /></label>
              <div v-for="(seg, si) in ch.critical_segments" :key="si" class="growth-actions">
                <label>关键片段起始秒<input type="number" v-model.number="seg.start" min="0" /></label
                ><label>结束秒<input type="number" v-model.number="seg.end" min="1" /></label
                ><button @click="ch.critical_segments.splice(si, 1)">移除片段</button>
              </div>
              <button @click="ch.critical_segments.push({ start: 0, end: 10 })">
                添加必看关键片段
              </button></template
            >
            <h3>随堂互动（可选）</h3>
            <section v-for="(quiz, qi) in ch.quizzes" :key="quiz.key" class="growth-chapter">
              <label>统一题库题目</label
              ><select v-model.number="quiz.question_id">
                <option :value="0">请选择客观题</option>
                <option v-for="q in questionOptions" :key="q.id" :value="Number(q.id)">
                  {{ q.stem }}
                </option></select
              ><label
                >出现位置（{{
                  ch.type === 'video' ? '秒' : ['ppt', 'word', 'pdf'].includes(ch.type) ? '页后' : '段落后'
                }}；0为开始）</label
              ><input type="number" v-model.number="quiz.position" min="0" /><label
                ><input type="checkbox" v-model="quiz.required" />本题必答</label
              ><label>答错处理</label
              ><select v-model="quiz.wrong_policy">
                <option value="retry">答对后继续</option>
                <option value="review">回看指定位置后重答</option>
                <option value="continue">记录本次答案后继续</option></select
              ><label v-if="quiz.wrong_policy === 'review'">回看位置</label
              ><input
                v-if="quiz.wrong_policy === 'review'"
                type="number"
                v-model.number="quiz.review_position"
                min="0"
              /><label>解析展示</label
              ><select v-model="quiz.analysis_timing">
                <option value="after_answer">作答后展示</option>
                <option value="after_pass">答对后展示</option></select
              ><button @click="ch.quizzes.splice(qi, 1)">移除本题</button>
            </section>
            <button @click="addQuiz(ch)">添加随堂题</button>
          </section>
          <button style="margin-top: 14px" :disabled="busy" @click="addChapter">＋ 添加章节</button>
          <section v-if="draft.course_rules" class="growth-chapter">
            <h3>课后要求（未选择即不启用）</h3>
            <label>关联练习 / 考试</label
            ><select v-model.number="draft.course_rules.post_exam_id">
              <option :value="0">不需要课后检验</option>
              <option v-for="e in examOptions" :key="e.id" :value="Number(e.id)">
                {{ e.title }}
              </option></select
            ><label v-if="Number(draft.course_rules.post_exam_id)"
              ><input
                type="checkbox"
                v-model="draft.course_rules.require_exam_passed"
              />必须通过才完成课程（取消则成绩公布即可）</label
            ><label>关联实操 / 带教活动</label
            ><select v-model.number="draft.course_rules.post_activity_id">
              <option :value="0">不需要后续活动</option>
              <option v-for="a in activityOptions" :key="a.id" :value="Number(a.id)">
                {{ a.title }}
              </option></select
            ><label
              ><input
                type="checkbox"
                v-model="draft.course_rules.allow_result_reference"
              />允许引用同一发布内容的有效学习结果</label
            ><label v-if="draft.course_rules.allow_result_reference"
              >已有结果有效天数（0不设天数限制）<input
                type="number"
                v-model.number="draft.course_rules.reference_days"
                min="0"
                max="3650"
            /></label>
          </section>
          <details class="growth-chapter">
            <summary>本次奖励与提醒（可选调整）</summary>
            <label
              >奖励规则<select v-model="draft.reward_selection.mode">
                <option value="default">沿用企业已启用规则</option>
                <option value="selected">只用下方所选（不选即无奖励）</option>
              </select></label
            >
            <div v-if="draft.reward_selection.mode === 'selected'" class="growth-form-grid">
              <label
                >积分<select multiple v-model="draft.reward_selection.point_rule_ids">
                  <option v-for="r in pointOptions" :key="r.id" :value="Number(r.id)">{{ r.title }}</option>
                </select></label
              ><label
                >证书<select multiple v-model="draft.reward_selection.certificate_rule_ids">
                  <option v-for="r in certificateOptions" :key="r.id" :value="Number(r.id)">
                    {{ r.title }}
                  </option>
                </select></label
              >
            </div>
            <label
              ><input
                type="checkbox"
                v-model="overrideReminders"
                @change="changeReminderMode"
              />本课单独安排提醒</label
            >
            <div v-if="overrideReminders" class="growth-actions">
              <label v-for="(name, key) in reminderNames" :key="key"
                ><input type="checkbox" v-model="draft.reminder_overrides[key]" />{{ name }}</label
              >
            </div>
          </details>
          <section v-if="publications.length" style="margin-top: 18px">
            <h3>发布记录</h3>
            <p v-for="p in publications" :key="p.id">
              {{ time(p.created_at) }} · {{ stateLabel(p.state) }}
              <button v-if="p.state === 'PENDING'" @click="confirm(p)">负责人确认</button>
            </p>
          </section>
        </div>
        <footer class="growth-dialog-foot">
          <span v-if="busy">正在处理，请稍候…</span
          ><button :disabled="busy" data-operation="growth.content.save" @click="save(false)">保存草稿</button
          ><button
            :disabled="busy"
            class="growth-primary"
            data-operation="growth.content.publish"
            @click="reviewPublication"
          >
            预览确认并发布
          </button>
        </footer>
      </section>
    </div>
    <div v-if="publicationPreview" class="growth-modal">
      <section class="growth-dialog" role="dialog" aria-modal="true" aria-label="发布内容核对">
        <header class="growth-dialog-head">
          <h2>发布前核对</h2>
          <button :disabled="busy" @click="publicationPreview = null">返回修改</button>
        </header>
        <div class="growth-dialog-body">
          <h2>{{ publicationPreview.title || '未填写名称' }}</h2>
          <p>
            {{ publicationPreview.category }} · {{ publicationPreview.lecturer || '未填写讲师' }} · 预计
            {{ publicationPreview.estimated_minutes || 0 }} 分钟
          </p>
          <p>{{ publicationPreview.description }}</p>
          <section v-for="(ch, i) in publicationPreview.chapters" :key="ch.key" class="growth-chapter">
            <h3>第 {{ i + 1 }} 章 · {{ ch.title }}</h3>
            <p>
              {{ ch.required ? '必学' : '选学' }} ·
              {{ ch.type === 'text' ? '图文正文' : ch.type.toUpperCase() }}
            </p>
            <p v-if="ch.type === 'text'" style="white-space: pre-wrap">{{ ch.body }}</p>
            <button v-else-if="ch.asset_id" @click="preview(ch)">查看本章实际资源</button>
            <p v-if="ch.completion === 'coverage'">
              有效观看 {{ ch.coverage_percent }}% · 必看片段 {{ ch.critical_segments.length }} 个
            </p>
            <p>随堂题 {{ ch.quizzes.length }} 道</p>
          </section>
          <p>
            补充资料 {{ publicationPreview.material_asset_ids.length }} 份 ·
            {{ publicationPreview.confirmer_employee_id ? '提交指定负责人确认后生效' : '确认后直接发布' }}
          </p>
          <p
            v-if="
              publicationPreview.course_rules.post_exam_id || publicationPreview.course_rules.post_activity_id
            "
          >
            本课包含课后检验，满足关联要求后才完成整课。
          </p>
          <p v-if="editorError" class="growth-error">{{ editorError }}</p>
        </div>
        <footer class="growth-dialog-foot">
          <button :disabled="busy" @click="publicationPreview = null">返回修改</button
          ><button class="growth-primary" :disabled="busy" @click="save(true)">确认本次内容并发布</button>
        </footer>
      </section>
    </div>
    <div v-if="previewUrl" class="growth-modal">
      <section class="growth-dialog">
        <header class="growth-dialog-head">
          <h2>课程预览</h2>
          <button @click="closePreview">关闭预览</button>
        </header>
        <iframe
          v-if="previewType === 'pdf'"
          :src="previewUrl"
          title="文档预览"
          style="height: 70vh; border: 0"
        ></iframe
        ><video
          v-else-if="previewType === 'video'"
          :src="previewUrl"
          controls
          style="max-height: 70vh"
        ></video
        ><img v-else :src="previewUrl" alt="课程图片" style="max-height: 70vh; object-fit: contain" />
      </section>
    </div>
  </main>
</template>
<script>
import { growthRead, growthWrite, growthUpload, growthPreview } from '@/api/growth';
const chapter = () => ({
  key: `ch-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
  title: '',
  type: 'text',
  body: '',
  asset_id: 0,
  asset: null,
  required: true,
  completion: 'confirm',
  coverage_percent: 90,
  quizzes: [],
  critical_segments: [],
  foreground_required: true,
  single_session: true,
  seek_policy: 'allow',
  idle_prompt: false,
  idle_seconds: 600,
  allowed_rates: [1, 1.25, 1.5],
  allow_speed: false
});
/** 编辑页只填写业务内容，冻结发布使用点击时整份内容；保存后重新读取当前草稿。 */
export default {
  name: 'GrowthContent',
  data: () => ({
    list: { rows: [], total: 0, page: 1, total_pages: 1 },
    keyword: '',
    error: '',
    notice: '',
    draft: null,
    employees: [],
    selectedTemplate: '',
    ruleTemplates: [],
    pointOptions: [],
    certificateOptions: [],
    overrideReminders: false,
    reminderNames: {
      assignment: '分配提醒',
      result: '结果提醒',
      due: '临期提醒',
      overdue: '逾期提醒',
      pending: '待确认提醒'
    },
    questionOptions: [],
    examOptions: [],
    activityOptions: [],
    publications: [],
    busy: false,
    editorError: '',
    previewUrl: '',
    previewType: '',
    publicationPreview: null
  }),
  created() {
    this.load(1);
    growthRead('catalog')
      .then((r) => {
        this.employees = r.data.employees;
        this.pointOptions = r.data.point_rule_options;
        this.certificateOptions = r.data.certificate_rule_options;
        this.ruleTemplates = JSON.parse(r.data.settings.templates_json || '{}').course_templates || [];
        this.questionOptions = r.data.question_options;
        this.examOptions = r.data.exam_options;
        this.activityOptions = r.data.activity_options;
      })
      .catch((e) => {
        this.error = e.msg || e.message;
      });
  },
  beforeDestroy() {
    this.closePreview();
  },
  methods: {
    // 只在明确选中时把模板带入当前草稿，发布不会重新读取模板替换已核对的内容。
    applyTemplate() {
      const t = this.ruleTemplates.find((r) => r.key === this.selectedTemplate);
      if (!t) return;
      this.draft.kind = t.purpose;
      this.draft.confirmer_employee_id = t.confirmer_employee_id;
      this.draft.rule_template = { key: t.key, title: t.title };
      this.draft.reward_selection = JSON.parse(JSON.stringify(t.reward_selection));
      this.draft.reminder_overrides = { ...t.reminder_overrides };
      this.overrideReminders = Object.keys(t.reminder_overrides).length > 0;
      for (const ch of this.draft.chapters) {
        ch.completion = ch.type === 'video' ? t.completion : 'confirm';
        ch.coverage_percent = t.coverage_percent;
        ch.foreground_required = t.foreground_required;
        ch.allow_speed = t.allow_speed;
        ch.single_session = t.single_session;
      }
    },
    changeReminderMode() {
      this.draft.reminder_overrides = this.overrideReminders
        ? Object.fromEntries(Object.keys(this.reminderNames).map((k) => [k, true]))
        : {};
    },
    addQuiz(ch) {
      ch.quizzes.push({
        key: 'quiz-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6),
        question_id: 0,
        position: 0,
        required: true,
        wrong_policy: 'retry',
        analysis_timing: 'after_answer',
        review_position: 0
      });
    },
    // 发布使用此刻预览的完整内容，后续网络等待不回读其他人的草稿替换它。
    reviewPublication() {
      if (!this.busy) {
        this.editorError = '';
        this.publicationPreview = JSON.parse(JSON.stringify(this.draft));
      }
    },
    async uploadAttachment(kind, event) {
      const file = event.target.files[0];
      if (!file) return;
      if (kind === 'material' && this.draft.material_asset_ids.length >= 30) {
        this.editorError = '最多30份补充资料';
        return;
      }
      this.busy = true;
      this.editorError = '';
      try {
        const a = (await growthUpload(file)).data;
        if (kind === 'cover') this.draft.cover_asset_id = Number(a.id);
        else {
          this.draft.material_asset_ids.push(Number(a.id));
          this.draft.materials.push(a);
        }
      } catch (e) {
        this.editorError = e.msg || e.message;
      } finally {
        this.busy = false;
        event.target.value = '';
      }
    },
    removeMaterial(index) {
      this.draft.material_asset_ids.splice(index, 1);
      this.draft.materials.splice(index, 1);
    },
    async load(page) {
      try {
        const r = await growthRead('contents', { page, keyword: this.keyword });
        this.list = r.data;
        this.error = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    create() {
      this.selectedTemplate = '';
      this.overrideReminders = false;
      this.draft = {
        id: 0,
        title: '',
        kind: 'course',
        reward_selection: { mode: 'default', point_rule_ids: [], certificate_rule_ids: [] },
        reminder_overrides: {},
        rule_template: {},
        audience: { mode: 'current' },
        course_rules: {
          post_exam_id: 0,
          post_activity_id: 0,
          require_exam_passed: true,
          allow_result_reference: false,
          reference_days: 0
        },
        category: '',
        description: '',
        lecturer: '',
        estimated_minutes: 0,
        cover_asset_id: 0,
        material_asset_ids: [],
        materials: [],
        chapters: [chapter()],
        confirmer_employee_id: 0
      };
      this.publications = [];
      this.editorError = '';
    },
    async edit(id) {
      try {
        const r = await growthRead('content', { id });
        this.draft = {
          reward_selection: { mode: 'default', point_rule_ids: [], certificate_rule_ids: [] },
          reminder_overrides: {},
          rule_template: {},
          audience: { mode: 'current' },
          course_rules: {
            post_exam_id: 0,
            post_activity_id: 0,
            require_exam_passed: true,
            allow_result_reference: false,
            reference_days: 0
          },
          lecturer: '',
          estimated_minutes: 0,
          cover_asset_id: 0,
          material_asset_ids: [],
          materials: [],
          ...r.data.draft,
          id
        };
        this.draft.chapters = this.draft.chapters.map((ch) => ({
          quizzes: [],
          critical_segments: [],
          foreground_required: true,
          single_session: true,
          seek_policy: 'allow',
          idle_prompt: false,
          idle_seconds: 600,
          allowed_rates: [1, 1.25, 1.5],
          allow_speed: false,
          ...ch
        }));
        this.selectedTemplate = (this.draft.rule_source && this.draft.rule_source.template_key) || '';
        if (this.selectedTemplate)
          this.draft.rule_template = {
            key: this.selectedTemplate,
            title: this.draft.rule_source.template_title
          };
        this.overrideReminders = Object.keys(this.draft.reminder_overrides).length > 0;
        this.publications = r.data.publications;
        this.editorError = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    close() {
      if (!this.busy) this.draft = null;
    },
    addChapter() {
      this.draft.chapters.push(chapter());
    },
    move(i, delta) {
      const items = this.draft.chapters;
      const value = items.splice(i, 1)[0];
      items.splice(i + delta, 0, value);
    },
    changeType(ch) {
      ch.asset_id = 0;
      ch.asset = null;
      ch.completion = 'confirm';
    },
    accept(type) {
      return {
        ppt: '.ppt,.pptx',
        word: '.doc,.docx',
        video: '.mp4,.webm',
        image: '.jpg,.jpeg,.png,.webp',
        pdf: '.pdf'
      }[type];
    },
    async upload(ch, event) {
      const file = event.target.files[0];
      if (!file) return;
      this.busy = true;
      this.editorError = '';
      try {
        const r = await growthUpload(file);
        ch.asset = r.data;
        ch.asset_id = Number(r.data.id);
      } catch (e) {
        this.editorError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    async retry(ch) {
      this.busy = true;
      try {
        const r = await growthWrite('asset/retry', { id: ch.asset_id });
        ch.asset = r.data;
      } catch (e) {
        this.editorError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    async preview(ch) {
      try {
        this.previewUrl = await growthPreview(ch.asset_id);
        this.previewType = ['ppt', 'word', 'pdf'].includes(ch.type) ? 'pdf' : ch.type;
      } catch (e) {
        this.editorError = e.message;
      }
    },
    closePreview() {
      if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
      this.previewUrl = '';
    },
    async save(publish) {
      if (this.busy) return;
      this.busy = true;
      this.editorError = '';
      try {
        const payload = JSON.parse(JSON.stringify(publish ? this.publicationPreview : this.draft));
        if (!payload.id) {
          const r = await growthWrite('content/save', payload);
          payload.id = r.data.id;
          this.draft.id = payload.id;
        }
        const r = await growthWrite(publish ? 'content/publish' : 'content/save', payload);
        this.notice = r.data.message;
        await this.edit(payload.id);
        await this.load(this.list.page);
        if (publish) {
          this.draft = null;
          this.publicationPreview = null;
        }
      } catch (e) {
        this.editorError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    assign(row) {
      this.$router.push({
        name: 'growth_tasks',
        query: { publication_id: row.current_publication_id, title: row.title }
      });
    },
    async stop(row) {
      const reason = window.prompt(`停用“${row.title}”的原因（原任务仍可学习）`);
      if (!reason) return;
      try {
        await growthWrite('content/stop', { id: row.id, reason });
        await this.load(this.list.page);
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async confirm(p) {
      try {
        await growthWrite('content/confirm', { publication_id: p.id });
        await this.edit(this.draft.id);
        await this.load(this.list.page);
      } catch (e) {
        this.editorError = e.msg || e.message;
      }
    },
    assetLabel(a) {
      return a.state === 'READY'
        ? `可预览${a.page_count ? ' · ' + a.page_count + '页' : ''}`
        : a.state === 'FAILED'
        ? a.error_message
        : '正在转换';
    },
    stateLabel(s) {
      return { DRAFT: '草稿', PENDING: '待负责人确认', PUBLISHED: '已发布', STOPPED: '已停用' }[s] || s;
    },
    time(t) {
      return t ? new Date(Number(t) * 1000).toLocaleString('zh-CN', { hour12: false }) : '—';
    }
  }
};
</script>
<style src="./growth.css"></style>
