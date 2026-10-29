<template>
  <div class="member-survey-page">
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="toolbar">
        <h2>会员调查</h2>
        <Button type="primary" @click="activeTab === 'tasks' ? newTask() : newQuestionnaire()">{{ activeTab === 'tasks' ? '创建调查任务' : '创建问卷' }}</Button>
      </div>
      <Tabs v-model="activeTab">
        <TabPane label="调查任务" name="tasks">
          <p class="section-hint">发布后可生成二维码；设置目标会员条件时，仅发布名单内的会员可扫码答卷。未设置条件的任务向所有会员开放。题目和奖励在发布时固定。</p>
          <Table :height="tableHeight" :columns="columns" :data="tasks" :loading="loading" border />
          <span class="paging-label">共 {{ total }} 条 · 每页 20 条 · 第 {{ page }} / {{ Math.max(1, Math.ceil(total / 20)) }} 页</span><Page class="pager" :total="total" :current="page" :page-size="20" @on-change="changePage" />
        </TabPane>
        <TabPane label="问卷管理" name="questionnaires">
          <p class="section-hint">问卷可独立编辑并用于多个任务。修改后供新发布的任务使用；已发布任务保留原题目。</p>
          <Table :height="tableHeight" :columns="questionnaireColumns" :data="questionnaires" :loading="questionnaireLoading" border />
          <span class="paging-label">共 {{ questionnaireTotal }} 条 · 每页 20 条 · 第 {{ questionnairePage }} / {{ Math.max(1, Math.ceil(questionnaireTotal / 20)) }} 页</span><Page class="pager" :total="questionnaireTotal" :current="questionnairePage" :page-size="20" @on-change="changeQuestionnairePage" />
        </TabPane>
      </Tabs>
    </Card>

    <Modal v-model="editorOpen" :title="form.id ? '编辑调查任务' : '创建调查任务'" width="740" :footer-hide="true" :mask-closable="false">
      <div class="editor" ref="taskFormArea">
        <Form :label-width="110">
          <FormItem label="任务名称" data-required-key="title"><Input v-model.trim="form.title" maxlength="150" placeholder="例如：新店选址意向调查" /></FormItem>
          <FormItem label="任务说明"><Input v-model.trim="form.description" type="textarea" :rows="2" maxlength="500" placeholder="向会员说明本次调查目的" /></FormItem>
          <FormItem label="选择问卷" data-required-key="questionnaire">
            <Select v-model="form.questionnaire_id" filterable remote :remote-method="loadQuestionnaireOptions" placeholder="先到问卷管理创建问卷" @on-change="changeQuestionnaire">
              <Option v-for="item in questionnaireOptions" :key="item.id" :value="Number(item.id)">{{ item.title }}（第 {{ item.revision }} 版）</Option>
            </Select>
            <p v-if="selectedQuestionnaire" class="hint">{{ selectedQuestionnaire.description || '无问卷说明' }} · {{ selectedQuestionnaire.questions.length }} 题。发布时采用问卷的最新版本。</p>
          </FormItem>
          <FormItem label="截止日期"><DatePicker type="date" format="yyyy-MM-dd" :value="form.deadline" @on-change="form.deadline = $event" placeholder="不设置则持续至手动停止" /></FormItem>
        </Form>
        <div class="actions">
          <Button :disabled="!form.questionnaire_id" @click="showQuestionPreview(form.questionnaire_id)">预览所选问卷</Button>
          <Button type="primary" :loading="saving" @click="saveTask">{{ form.id ? '保存任务' : '创建任务' }}</Button>
        </div>
      </div>
    </Modal>

    <Modal v-model="targetsOpen" :title="`添加目标会员 · ${targetTaskTitle}`" width="880" :footer-hide="true" :mask-closable="false">
      <div class="editor">
        <p class="section-hint">筛选会员与手动指定会员会合并为目标名单。发布时重新计算并固定名单，名单外会员扫码不能答卷；不设置条件则公开扫码。</p>
        <Divider>条件筛选</Divider>
        <Row :gutter="16">
          <Col span="12"><Checkbox v-model="targetFilters.cash_enabled">按现金业绩筛选</Checkbox></Col>
          <Col span="12"><Checkbox v-model="targetFilters.visits_enabled">按到店次数筛选</Checkbox></Col>
        </Row>
        <Row :gutter="16" class="filter-row" v-if="targetFilters.cash_enabled">
          <Col span="12"><InputNumber :min="0" v-model="targetFilters.cash_min" placeholder="最低现金业绩" style="width:100%" /></Col>
          <Col span="12"><InputNumber :min="0" v-model="targetFilters.cash_max" placeholder="最高现金业绩，可不填" style="width:100%" /></Col>
        </Row>
        <Row :gutter="16" class="filter-row" v-if="targetFilters.visits_enabled">
          <Col span="12"><InputNumber :min="0" :precision="0" v-model="targetFilters.visits_min" placeholder="最少到店次数" style="width:100%" /></Col>
          <Col span="12"><InputNumber :min="0" :precision="0" v-model="targetFilters.visits_max" placeholder="最多到店次数，可不填" style="width:100%" /></Col>
        </Row>
        <Row :gutter="16" class="filter-row">
          <Col span="12"><span>统计开始：</span><DatePicker type="date" format="yyyy-MM-dd" :value="targetFilters.start_date" @on-change="targetFilters.start_date = $event" placeholder="不限起始日期" /></Col>
          <Col span="12"><span>统计结束：</span><DatePicker type="date" format="yyyy-MM-dd" :value="targetFilters.end_date" @on-change="targetFilters.end_date = $event" placeholder="默认发布当天" /></Col>
        </Row>
        <p class="hint">现金业绩取门店运营看板中可归属会员的有效现金订单；到店次数按核销记录计数，范围为全公司门店。</p>
        <Divider>手动指定会员</Divider>
        <div class="member-selection-head">
          <span>已添加 {{ selectedMembers.length }} 人</span>
          <Button @click="openMemberPicker">按姓名或手机号查找会员</Button>
        </div>
        <Table :columns="selectedMemberColumns" :data="selectedMembers" max-height="240" border />
        <p v-if="!selectedMembers.length" class="hint">暂无手动指定会员。也可以仅使用上方条件筛选。</p>
        <div class="member-actions">
          <span v-if="previewData" class="hint">预览结果：{{ previewData.count }} 人</span>
          <Button @click="showAudience" :loading="previewLoading">预览目标名单</Button>
        </div>
        <div class="actions">
          <Button type="primary" :loading="targetSaving" @click="saveTargets">保存目标会员</Button>
        </div>
      </div>
    </Modal>

    <Modal v-model="questionnaireEditorOpen" :title="questionForm.id ? '编辑问卷' : '创建问卷'" width="760" :footer-hide="true" :mask-closable="false">
      <div class="editor" ref="questionFormArea">
        <Form :label-width="90">
          <FormItem label="问卷名称" data-required-key="title"><Input v-model.trim="questionForm.title" maxlength="150" placeholder="例如：新店选址意向问卷" /></FormItem>
          <FormItem label="问卷说明"><Input v-model.trim="questionForm.description" type="textarea" :rows="2" maxlength="500" placeholder="向会员展示的问卷说明" /></FormItem>
        </Form>
        <Divider>问卷题目</Divider>
        <div v-for="(q, index) in questionForm.questions" :key="index" class="question-editor" :data-required-key="'question-' + index">
          <div class="question-head"><b>第 {{ index + 1 }} 题</b><Button type="text" size="small" @click="questionForm.questions.splice(index, 1)">删除</Button></div>
          <Input v-model.trim="q.title" maxlength="200" placeholder="请输入题目" />
          <div class="question-settings">
            <Select v-model="q.type" style="width:120px"><Option value="single">单选</Option><Option value="multiple">多选</Option><Option value="text">文字问答</Option></Select>
            <Checkbox v-model="q.required">必答</Checkbox>
          </div>
          <Input v-if="q.type !== 'text'" v-model="q.optionsText" type="textarea" :rows="3" placeholder="每行一个选项，至少两项" />
        </div>
        <Button data-required-key="questions" icon="md-add" :disabled="questionForm.questions.length >= 30" @click="addQuestion">添加题目</Button>
        <p class="hint">已发布任务保留发布时的题目和统计；修改问卷只影响之后发布的任务。</p>
        <div class="actions"><Button @click="showQuestionPreview()">预览问卷</Button><Button type="primary" :loading="questionnaireSaving" @click="saveQuestionnaire">保存问卷</Button></div>
      </div>
    </Modal>

    <Modal v-model="audienceOpen" title="目标会员预览" width="760" :footer-hide="true">
      <p>共 {{ previewData ? previewData.count : 0 }} 人；发布时会重新计算并固定名单。</p>
      <Table :columns="audienceColumns" :data="previewData ? previewData.list.slice(0, 100) : []" max-height="480" border />
      <p v-if="previewData && previewData.count > 100" class="hint">这里只展示前 100 人。</p>
    </Modal>

    <Modal v-model="memberPickerOpen" title="查找并指定会员" width="650" :footer-hide="true">
      <div class="member-search"><Input v-model.trim="memberKeyword" search enter-button="搜索" placeholder="输入姓名或手机号（至少 2 个字）" @on-search="searchMembers" @on-enter="searchMembers" /></div>
      <Table :columns="memberColumns" :data="memberResults" :loading="memberLoading" border />
      <p class="hint">最多显示前 20 人；添加后可在目标会员表格中核对或移除。</p>
    </Modal>

    <Modal v-model="questionPreviewOpen" title="问卷预览" width="650" :footer-hide="true">
      <h3>{{ previewQuestionnaire.title || '未命名问卷' }}</h3><p>{{ previewQuestionnaire.description }}</p>
      <div v-for="(q, index) in previewQuestionnaire.questions" :key="index" class="preview-question">
        <b>{{ index + 1 }}. {{ q.title || '未填写题目' }} <span v-if="q.required" class="required">*</span></b>
        <p v-if="q.type === 'text'">文字问答</p>
        <p v-else v-for="(option, i) in questionOptions(q)" :key="i">{{ q.type === 'single' ? '○' : '□' }} {{ option }}</p>
      </div>
    </Modal>

    <Modal v-model="resultsOpen" title="调查结果" width="880" :footer-hide="true">
      <div v-if="resultData">
        <h3>{{ resultData.task.title }}</h3>
        <p>第 {{ resultData.task.send_round }} 轮 · 提醒名单 {{ resultData.target_count }} 人 · 本轮已填写 {{ resultData.answer_count }} 人 · 名单完成率 {{ resultData.answer_rate }}% · 累计回答 {{ resultData.total_answer_count }} 份</p>
        <div v-for="(stat, index) in resultData.stats" :key="index" class="preview-question">
          <b>{{ stat.title }}</b>
          <p v-if="stat.type === 'text'">文字回答见下方明细</p>
          <p v-else v-for="(option, key) in stat.options" :key="key">{{ option.label }}：{{ option.count }} 人</p>
        </div>
        <Divider>会员回答明细</Divider>
        <Table :columns="answerColumns" :data="resultData.list" border />
        <Page class="pager" :total="resultData.total_answer_count" :current="resultPage" :page-size="20" @on-change="loadResults" />
      </div>
    </Modal>

    <Modal v-model="analysisOpen" title="问卷分析" width="900" :footer-hide="true" :mask-closable="false">
      <Spin v-if="analysisLoading" fix />
      <div v-if="analysisData">
        <h3>{{ analysisData.questionnaire.title }}</h3>
        <p class="hint">已发布任务 {{ analysisData.task_count }} 个 · 收到回答 {{ analysisData.answer_count }} 份</p>
        <template v-if="analysisData.groups.length">
          <div class="analysis-version">
            <span>发布题目：</span>
            <Select v-model="analysisGroupKey" style="width:320px">
              <Option v-for="(group, index) in analysisData.groups" :key="group.key" :value="group.key">发布题目组 {{ index + 1 }} · {{ group.task_count }} 个任务</Option>
            </Select>
          </div>
          <p class="hint">问卷修改后，不同的发布题目分别统计，避免选项混算。</p>
          <div v-if="selectedAnalysisGroup">
            <p class="analysis-summary">提醒 {{ selectedAnalysisGroup.target_count }} 人次 · 已填写 {{ selectedAnalysisGroup.answer_count }} 份</p>
            <p class="hint">关联任务：{{ selectedAnalysisGroup.tasks.map(task => task.title).join('、') }}</p>
            <div v-for="(stat, index) in selectedAnalysisGroup.stats" :key="stat.id" class="analysis-question">
              <div class="analysis-question-head"><b>第 {{ index + 1 }} 题 · {{ stat.title }}</b><Tag>{{ questionTypeName(stat.type) }}</Tag></div>
              <p class="hint">有效回答 {{ stat.answered_count }} 份<span v-if="stat.skipped_count"> · 未答 {{ stat.skipped_count }} 份</span></p>
              <template v-if="stat.type === 'text'">
                <Button v-if="stat.answered_count" type="text" @click="showTextAnswers(stat.id, 1)">查看文字回答（{{ stat.answered_count }}）</Button>
                <p v-else>暂无文字回答</p>
              </template>
              <template v-else>
                <div v-for="option in stat.options" :key="option.id" class="analysis-option">
                  <div class="analysis-option-label"><span>{{ option.label }}</span><span>{{ option.count }} 人 · {{ optionPercent(option.count, stat.answered_count) }}%</span></div>
                  <Progress :percent="optionPercent(option.count, stat.answered_count)" :show-info="false" />
                </div>
                <p v-if="stat.type === 'multiple'" class="hint">多选题按回答本题的人数计算比例，各选项合计可能超过 100%。</p>
              </template>
            </div>
          </div>
        </template>
        <p v-else class="analysis-empty">暂无已发布任务。创建任务并发布后，可在这里查看每道题的回答情况。</p>
      </div>
    </Modal>

    <Modal v-model="analysisTextOpen" :title="analysisTextData ? analysisTextData.question_title : '文字回答'" width="850" :footer-hide="true">
      <Table :columns="analysisTextColumns" :data="analysisTextData ? analysisTextData.list : []" :loading="analysisTextLoading" border />
      <Page v-if="analysisTextData" class="pager" :total="analysisTextData.total" :current="analysisTextPage" :page-size="20" @on-change="showTextAnswers(analysisTextQuestionId, $event)" />
    </Modal>
    <member-survey-reward :visible="giftOpen" :survey-id="giftTaskId" :questionnaire-id="giftQuestionnaireId" :readonly="giftReadOnly" :inherited="giftInherited"
      @close="giftOpen = false" @saved="giftQuestionnaireId ? loadQuestionnaires() : loadTasks()" />
    <Modal v-model="qrOpen" title="调查任务二维码" :footer-hide="true" width="450">
      <p>微信扫码打开会员小程序。已设置目标条件的任务仅允许发布名单内会员答卷；完成前须授权手机号，无所属门店时须先选择门店。</p>
      <img v-if="qrUrl" :src="qrUrl" alt="调查任务小程序码" style="display:block;margin:20px auto;width:260px;height:260px" />
      <p v-if="qrEnvironment">{{ qrEnvironment === 'trial' ? '体验版' : qrEnvironment === 'develop' ? '开发版' : '正式版' }}会员小程序</p>
      <Button v-if="qrUrl" @click="downloadQr">下载二维码</Button>
      <p v-if="qrError" role="status" aria-live="polite">{{ qrError }}</p>
    </Modal>
  </div>
</template>

<script>
import { surveyList, surveyRead, surveyCreate, surveyUpdate, surveyTargets, surveyUpdateTargets, surveyPreview, surveySearchMembers, surveyPublish, surveyStop, surveyResend, surveyResults, surveyExportData, questionnaireList, questionnaireRead, questionnaireCreate, questionnaireUpdate, questionnaireAnalysis, questionnaireTextAnswers, questionnaireExportData } from '@/api/memberSurvey';
import { guideRequired, clearRequiredGuidance } from '@/utils/requiredGuidance';
import { surveyQr, surveyRetryRewards, questionnaireOptions } from '@/api/memberSurvey';
import MemberSurveyReward from '@/components/memberSurveyReward';

const emptyForm = () => ({
  id: 0, title: '', description: '', deadline: '', questionnaire_id: 0
});
const emptyFilters = () => ({ cash_enabled: false, visits_enabled: false, cash_min: null, cash_max: null,
  visits_min: null, visits_max: null, start_date: '', end_date: '', uids: [] });
const emptyQuestionnaire = () => ({
  id: 0, title: '', description: '',
  questions: [{ title: '您希望我们在哪里开店？', type: 'single', required: true, optionsText: '市中心\n您所在的区域\n其他区域' }]
});

export default {
  name: 'MemberSurvey',
  components: { MemberSurveyReward },
  data() {
    return {
      qrOpen: false, qrUrl: '', qrError: '', qrEpoch: 0, qrEnvironment: '',
      activeTab: 'tasks', tasks: [], total: 0, page: 1, loading: false, saving: false,
      questionnaireOptions: [], questionnaireTotal: 0, questionnairePage: 1, tableHeight: Math.max(220, window.innerHeight - 340), questionnaires: [], questionnaireLoading: false, questionnaireSaving: false, exportingQuestionnaireId: 0,
      exportingTaskId: 0,
      questionForm: emptyQuestionnaire(), selectedQuestionnaire: null, previewQuestionnaire: emptyQuestionnaire(), questionnaireEditorOpen: false,
      editorOpen: false, targetsOpen: false, audienceOpen: false, memberPickerOpen: false, questionPreviewOpen: false, resultsOpen: false,
      giftOpen: false, giftTaskId: 0, giftQuestionnaireId: 0, giftReadOnly: false, giftInherited: false,
      previewLoading: false, previewData: null, resultData: null, resultId: 0, resultPage: 1,
      analysisOpen: false, analysisLoading: false, analysisData: null, analysisId: 0, analysisGroupKey: '',
      analysisTextOpen: false, analysisTextLoading: false, analysisTextData: null, analysisTextPage: 1, analysisTextQuestionId: '',
      form: emptyForm(), targetTaskId: 0, targetTaskTitle: '', targetFilters: emptyFilters(), targetSaving: false,
      selectedMembers: [], memberKeyword: '', memberResults: [], memberLoading: false,
      columns: [
        { title: '任务', key: 'title', minWidth: 210 },
        { title: '问卷', key: 'questionnaire_title', minWidth: 180 },
        { title: '状态', render: (h, { row }) => h('span', row.status === 'published' && row.deadline && row.deadline < Date.now() / 1000 ? '已截止' : ({ draft: '草稿', published: '进行中', stopped: '已停止' }[row.status] || row.status)) },
        { title: '目标会员', width: 110, render: (h, { row }) => h('span', row.status === 'draft'
          ? (row.targets_configured ? '待发布' : '公开扫码') : (row.targets_configured ? String(row.target_count) : '公开扫码')) },
        { title: '已填写', key: 'answer_count', width: 100 },
        { title: '截止', width: 120, render: (h, { row }) => h('span', row.deadline ? this.dateText(row.deadline) : '无') },
        { title: '操作', width: 640, render: (h, { row }) => h('div', [
          row.status === 'published' ? h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.showQr(row) } }, '二维码') : null,
          row.status !== 'draft' ? h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.retryRewards(row) } }, '重试待发奖励') : null,
          row.status === 'draft' ? h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.editTask(row.id) } }, '编辑') : null,
          row.status === 'draft' ? h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.openTargets(row) } }, '添加目标会员') : null,
          h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.openGiftConfig(row) } },
            `${row.status === 'draft' ? '赠送配置' : '查看赠送'}${row.gift_count ? '（' + row.gift_count + '）' : ''}`),
          row.status === 'draft' ? h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.publishTask(row) } }, '发布') : null,
          row.status === 'published' ? h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.stopTask(row.id) } }, '停止') : null,
          h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.loadResults(1, row.id) } }, '查看结果'),
          // 独立的任务导出只下载该任务，不改动查看结果、发送及赠送配置行为。
          h('Button', { props: { type: 'text', size: 'small', loading: this.exportingTaskId === Number(row.id), disabled: Boolean(this.exportingTaskId) },
            on: { click: () => this.exportTask(row) } }, '导出结果'),
          row.status === 'published' && (!row.deadline || row.deadline >= Date.now() / 1000)
            ? h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.resendTask(row) } }, '发送') : null
        ]) }
      ],
      questionnaireColumns: [
        { title: '问卷', key: 'title', minWidth: 220 },
        { title: '说明', key: 'description', minWidth: 220 },
        { title: '题数', key: 'question_count', width: 85 },
        { title: '版本', width: 85, render: (h, { row }) => h('span', `第 ${row.revision} 版`) },
        { title: '关联任务', key: 'task_count', width: 100 },
        { title: '操作', width: 385, render: (h, { row }) => h('div', { style: { whiteSpace: 'nowrap' } }, [
          h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.editQuestionnaire(row.id) } }, '编辑'),
          h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.showQuestionPreview(row.id) } }, '预览'),
          h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.openQuestionnaireAnalysis(row.id) } }, '分析'),
          h('Button', { props: { type: 'text', size: 'small', loading: this.exportingQuestionnaireId === Number(row.id), disabled: Boolean(this.exportingQuestionnaireId) },
            on: { click: () => this.exportQuestionnaire(row) } }, '导出Excel'),
          h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.openQuestionnaireGiftConfig(row) } },
            `赠送设置${row.gift_count ? '（' + row.gift_count + '）' : ''}`)
        ]) }
      ],
      audienceColumns: [
        { title: '会员姓名', key: 'display_name' }, { title: '手机号', key: 'phone' },
        { title: '现金业绩', render: (h, { row }) => h('span', this.targetFilters.cash_enabled ? row.cash_amount : '未统计') },
        { title: '到店次数', render: (h, { row }) => h('span', this.targetFilters.visits_enabled ? String(row.visit_count) : '未统计') },
        { title: '来源', render: (h, { row }) => h('span', row.source === 'manual' ? '指定' : '筛选') }
      ],
      memberColumns: [
        { title: '会员姓名', key: 'display_name' }, { title: '手机号', key: 'phone' },
        { title: '操作', width: 95, render: (h, { row }) => h('Button', { props: { type: 'text', size: 'small', disabled: this.selectedMembers.some(m => Number(m.uid) === Number(row.uid)) },
          on: { click: () => this.addMember(row) } }, this.selectedMembers.some(m => Number(m.uid) === Number(row.uid)) ? '已添加' : '添加') }
      ],
      selectedMemberColumns: [
        { title: '会员姓名', key: 'display_name' }, { title: '手机号', key: 'phone' },
        { title: '操作', width: 95, render: (h, { row }) => h('Button', { props: { type: 'text', size: 'small' },
          on: { click: () => this.removeMember(row.uid) } }, '移除') }
      ],
      answerColumns: [
        { title: '会员姓名', key: 'display_name' }, { title: '手机号', key: 'phone' },
        { title: '轮次', width: 70, render: (h, { row }) => h('span', String(row.send_round || 1)) },
        { title: '提交时间', width: 165, render: (h, { row }) => h('span', this.timeText(row.submitted_at)) },
        { title: '回答', width: 95, render: (h, { row }) => h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.showAnswer(row) } }, '查看') }
      ],
      analysisTextColumns: [
        { title: '会员姓名', key: 'display_name', width: 125 }, { title: '手机号', key: 'phone', width: 135 },
        { title: '关联任务', key: 'task_title', width: 180 }, { title: '回答内容', key: 'answer', minWidth: 220 },
        { title: '提交时间', width: 165, render: (h, { row }) => h('span', this.timeText(row.submitted_at)) }
      ]
    };
  },
  computed: {
    selectedAnalysisGroup() {
      return this.analysisData ? this.analysisData.groups.find(group => group.key === this.analysisGroupKey) : null;
    }
  },
  created() { this.loadTasks(); this.loadQuestionnaires(); },
  beforeDestroy() { clearRequiredGuidance(); window.removeEventListener('resize', this.resizeTable); },
  mounted() { window.addEventListener('resize', this.resizeTable); },
  methods: {
    resizeTable() { this.tableHeight = Math.max(220, window.innerHeight - 340); },
    changeQuestionnairePage(page) { this.questionnairePage = page; this.loadQuestionnaires(); },
    async loadQuestionnaireOptions(keyword = '') { const { data } = await questionnaireOptions(keyword); this.questionnaireOptions = data || []; },
    async showQr(row) {
      // 旧请求不能覆盖后来打开的另一任务二维码。
      const epoch = ++this.qrEpoch;
      this.qrOpen = true; this.qrUrl = ''; this.qrError = ''; this.qrEnvironment = '';
      try { const { data } = await surveyQr(row.id); if (epoch !== this.qrEpoch || !this.qrOpen) return; if(data.kind!=='miniprogram'||!/^data:image\/(png|jpeg);base64,/.test(data.image||''))throw new Error('未取得有效小程序码');this.qrUrl=data.image;this.qrEnvironment=data.env_version; } catch (e) { if (epoch === this.qrEpoch) { this.qrUrl = ''; this.qrError = e.msg || e.message || '二维码生成失败'; } }
    },
    // 下载微信返回的小程序码原图，不在浏览器用普通网址重绘。
    downloadQr() { if(!this.qrUrl)return;const link = document.createElement('a'); link.download = '调查任务小程序码'+(this.qrUrl.startsWith('data:image/jpeg;')?'.jpg':'.png'); link.href = this.qrUrl; link.click(); },
    async retryRewards(row) { const { data } = await surveyRetryRewards(row.id); this.$Message.info('本次处理 ' + data.processed + ' 条，未成功的奖励仍保留待发'); },
    dateText(ts) {
      const date = new Date(Number(ts) * 1000);
      return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    },
    timeText(ts) { return new Date(Number(ts) * 1000).toLocaleString('zh-CN'); },
    splitOptions(text) { return (text || '').split('\n').map(s => s.trim()).filter(Boolean); },
    questionOptions(q) { return q.optionsText !== undefined ? this.splitOptions(q.optionsText) : (q.options || []).map(o => o.label); },
    questionTypeName(type) { return { single: '单选', multiple: '多选', text: '文字问答' }[type] || type; },
    optionPercent(count, total) { return total ? Math.round(Number(count) * 1000 / Number(total)) / 10 : 0; },
    changePage(page) { this.page = page; this.loadTasks(); },
    async loadTasks() {
      this.loading = true;
      try { const res = await surveyList({ page: this.page, limit: 20 }); this.tasks = res.data.list || []; this.total = res.data.count || 0; }
      finally { this.loading = false; }
    },
    async loadQuestionnaires() {
      this.questionnaireLoading = true;
      try { const res = await questionnaireList({ page: this.questionnairePage, limit: 20 }); this.questionnaires = res.data.list || []; this.questionnaireTotal = res.data.count || 0; await this.loadQuestionnaireOptions(); }
      finally { this.questionnaireLoading = false; }
    },
    newTask() {
      if (!this.questionnaires.length) { this.activeTab = 'questionnaires'; this.$Message.info('请先创建问卷'); return; }
      this.form = emptyForm(); this.selectedQuestionnaire = null; this.editorOpen = true;
    },
    async editTask(id) {
      const res = await surveyRead(id); const item = res.data;
      if (!item.questionnaire_id) { this.$Message.warning('这是旧版任务，原题目已固定；请创建新任务并选择问卷'); return; }
      this.form = { id: item.id, title: item.title, description: item.description,
        deadline: item.deadline ? this.dateText(item.deadline) : '', questionnaire_id: Number(item.questionnaire_id) };
      await this.changeQuestionnaire(this.form.questionnaire_id); this.editorOpen = true;
    },
    async changeQuestionnaire(id) {
      this.selectedQuestionnaire = id ? (await questionnaireRead(id)).data : null;
      if (this.selectedQuestionnaire && !this.questionnaireOptions.some(q => Number(q.id) === Number(id))) this.questionnaireOptions.push(this.selectedQuestionnaire);
    },
    newQuestionnaire() { this.questionForm = emptyQuestionnaire(); this.questionnaireEditorOpen = true; },
    async editQuestionnaire(id) {
      const res = await questionnaireRead(id);
      this.questionForm = { ...res.data, questions: res.data.questions.map(q => ({ ...q, optionsText: (q.options || []).map(o => o.label).join('\n') })) };
      this.questionnaireEditorOpen = true;
    },
    addQuestion() { this.questionForm.questions.push({ title: '', type: 'single', required: true, optionsText: '' }); },
    questionPayload() {
      return { title: this.questionForm.title, description: this.questionForm.description,
        questions: this.questionForm.questions.map(q => ({ title: q.title, type: q.type, required: q.required,
          options: q.type === 'text' ? [] : this.splitOptions(q.optionsText) })) };
    },
    async saveQuestionnaire() {
      if (!this.questionForm.title.trim()) return guideRequired(this.$refs.questionFormArea, 'title', '请在这里填写问卷名称');
      if (!this.questionForm.questions.length) return guideRequired(this.$refs.questionFormArea, 'questions', '请在这里添加至少一道题目');
      const missing = this.questionForm.questions.findIndex(q => !q.title.trim() || (q.type !== 'text' && this.splitOptions(q.optionsText).length < 2));
      if (missing >= 0) return guideRequired(this.$refs.questionFormArea, (this.questionForm.questions[missing].title.trim() ? 'options-' : 'question-') + missing, this.questionForm.questions[missing].title.trim() ? '请在这里填写至少两个选项，每行一个' : '请在这里填写题目');
      this.questionnaireSaving = true;
      try {
        const payload = this.questionPayload();
        if (this.questionForm.id) await questionnaireUpdate(this.questionForm.id, payload); else await questionnaireCreate(payload);
        this.$Message.success('问卷已保存'); this.questionnaireEditorOpen = false; await this.loadQuestionnaires();
        if (this.form.questionnaire_id) await this.changeQuestionnaire(this.form.questionnaire_id);
      } finally { this.questionnaireSaving = false; }
    },
    payload() {
      return { title: this.form.title, description: this.form.description, deadline: this.form.deadline,
        questionnaire_id: this.form.questionnaire_id };
    },
    async openTargets(row) {
      const res = await surveyTargets(row.id);
      this.targetTaskId = row.id; this.targetTaskTitle = row.title;
      this.targetFilters = { ...emptyFilters(), ...res.data.filters };
      this.selectedMembers = res.data.members || [];
      this.previewData = null; this.memberKeyword = ''; this.memberResults = [];
      this.targetsOpen = true;
    },
    openGiftConfig(row) {
      this.giftTaskId = Number(row.id);
      this.giftQuestionnaireId = 0;
      this.giftReadOnly = row.status !== 'draft';
      this.giftInherited = Boolean(row.gift_inherited);
      this.giftOpen = true;
    },
    openQuestionnaireGiftConfig(row) {
      this.giftQuestionnaireId = Number(row.id);
      this.giftTaskId = 0;
      this.giftReadOnly = false;
      this.giftInherited = false;
      this.giftOpen = true;
    },
    targetPayload() {
      return { ...this.targetFilters, uids: this.selectedMembers.map(m => Number(m.uid)),
        cash_min: this.targetFilters.cash_min == null ? '' : this.targetFilters.cash_min,
        cash_max: this.targetFilters.cash_max == null ? '' : this.targetFilters.cash_max,
        visits_min: this.targetFilters.visits_min == null ? '' : this.targetFilters.visits_min,
        visits_max: this.targetFilters.visits_max == null ? '' : this.targetFilters.visits_max };
    },
    async saveTargets() {
      if (!this.targetFilters.cash_enabled && !this.targetFilters.visits_enabled && !this.selectedMembers.length) {
        return this.$Message.warning('请选择筛选条件或添加会员');
      }
      this.targetSaving = true;
      try {
        await surveyUpdateTargets(this.targetTaskId, this.targetPayload());
        this.$Message.success('目标会员已保存'); this.targetsOpen = false; await this.loadTasks();
      } finally { this.targetSaving = false; }
    },
    async showAudience() {
      this.previewLoading = true;
      try { const res = await surveyPreview(this.targetPayload()); this.previewData = res.data; this.audienceOpen = true; }
      finally { this.previewLoading = false; }
    },
    openMemberPicker() { this.memberKeyword = ''; this.memberResults = []; this.memberPickerOpen = true; },
    async searchMembers() {
      if (this.memberKeyword.length < 2) return this.$Message.warning('请输入至少 2 个字');
      this.memberLoading = true;
      try { const res = await surveySearchMembers(this.memberKeyword); this.memberResults = res.data || []; }
      finally { this.memberLoading = false; }
    },
    addMember(row) {
      if (this.selectedMembers.some(member => Number(member.uid) === Number(row.uid))) return;
      this.selectedMembers.push({ uid: row.uid, display_name: row.display_name, phone: row.phone });
      this.previewData = null;
      this.$Message.success(`已添加 ${row.display_name || row.phone}`);
    },
    removeMember(uid) {
      this.selectedMembers = this.selectedMembers.filter(member => Number(member.uid) !== Number(uid));
      this.previewData = null;
    },
    async showQuestionPreview(id = 0) {
      if (id) this.previewQuestionnaire = (await questionnaireRead(id)).data;
      else this.previewQuestionnaire = this.questionForm;
      this.questionPreviewOpen = true;
    },
    async openQuestionnaireAnalysis(id) {
      this.analysisId = id; this.analysisData = null; this.analysisGroupKey = '';
      this.analysisOpen = true; this.analysisLoading = true;
      try {
        const res = await questionnaireAnalysis(id);
        this.analysisData = res.data;
        this.analysisGroupKey = res.data.groups.length ? res.data.groups[0].key : '';
      } finally { this.analysisLoading = false; }
    },
    /** 下载全轮次两表文件；失败时保留重试入口，不能显示导出成功。 */
    async exportTask(row) {
      if (this.exportingTaskId) return;
      this.exportingTaskId = Number(row.id);
      try {
        const res = await surveyExportData(row.id);
        if (!res || res.status !== 200 || !res.data) throw new Error('导出结果加载失败');
        const { downloadMemberSurveyTaskExcel } = await import('@/utils/memberSurveyExcel');
        downloadMemberSurveyTaskExcel(res.data);
        this.$Message.success('Excel 已导出');
      } catch (error) {
        this.$Message.error('导出结果失败，请重试');
      } finally { this.exportingTaskId = 0; }
    },
    async exportQuestionnaire(row) {
      if (this.exportingQuestionnaireId) return;
      this.exportingQuestionnaireId = Number(row.id);
      try {
        const res = await questionnaireExportData(row.id);
        const { downloadMemberSurveyExcel } = await import('@/utils/memberSurveyExcel');
        downloadMemberSurveyExcel(res.data);
        this.$Message.success('Excel 已导出');
      } finally { this.exportingQuestionnaireId = 0; }
    },
    async showTextAnswers(questionId, page = 1) {
      this.analysisTextQuestionId = questionId; this.analysisTextPage = page;
      this.analysisTextLoading = true;
      try {
        const res = await questionnaireTextAnswers(this.analysisId, { group_key: this.analysisGroupKey, question_id: questionId, page, limit: 20 });
        this.analysisTextData = res.data; this.analysisTextOpen = true;
      } finally { this.analysisTextLoading = false; }
    },
    async saveTask() {
      if (!this.form.title.trim()) return guideRequired(this.$refs.taskFormArea, 'title', '请在这里填写任务名称');
      if (!this.form.questionnaire_id) return guideRequired(this.$refs.taskFormArea, 'questionnaire', '请在这里选择问卷');
      this.saving = true;
      try {
        const payload = this.payload();
        const isNew = !this.form.id;
        if (this.form.id) await surveyUpdate(this.form.id, payload); else await surveyCreate(payload);
        this.$Message.success(isNew ? '任务已创建，可配置奖励与提醒名单后发布' : '任务已保存');
        this.editorOpen = false; await this.loadTasks();
      } finally { this.saving = false; }
    },
    publishTask(row) {
      this.$Modal.confirm({ title: '发布调查', content: '发布后将固定目标名单；设置了目标条件时，只有名单内会员可以扫码答卷。未设置条件则向所有会员开放。题目和奖励也将固定。确认发布？',
        onOk: async () => { const res = await surveyPublish(row.id); this.$Message.success(`已发布，目标 ${res.data.target_count} 人`); this.loadTasks(); } });
    },
    stopTask(id) {
      this.$Modal.confirm({ title: '停止调查', content: '停止后会员不再收到弹窗。确认停止？',
        onOk: async () => { await surveyStop(id); this.$Message.success('已停止'); this.loadTasks(); } });
    },
    resendTask(row) {
      this.$Modal.confirm({ title: '再次发送调查',
        content: `将开启第 ${Number(row.send_round || 1) + 1} 轮，提醒固定的 ${row.target_count} 位目标会员；原二维码仍可填写新一轮。是否再次发奖按发布时的奖励配置执行。确认发送？`,
        onOk: async () => { const res = await surveyResend(row.id); this.$Message.success(`已发送第 ${res.data.send_round} 轮`); this.loadTasks(); } });
    },
    async loadResults(page = 1, id = 0) {
      if (id) this.resultId = id;
      this.resultPage = page;
      const res = await surveyResults(this.resultId, { page, limit: 20 });
      this.resultData = res.data; this.resultsOpen = true;
    },
    showAnswer(row) {
      const lines = this.resultData.task.questions.map(q => {
        const value = row.answers[q.id];
        if (q.type === 'text') return `${q.title}：${value || '未答'}`;
        const ids = q.type === 'multiple' ? (value || []) : [value];
        return `${q.title}：${ids.map(id => (q.options.find(o => o.id === id) || {}).label).filter(Boolean).join('、') || '未答'}`;
      });
      this.$Modal.info({ title: `${row.display_name || row.phone} 的回答`, content: lines.map(line => `<p style="margin:8px 0;white-space:pre-wrap">${this.escapeHtml(line)}</p>`).join(''), width: 600 });
    },
    escapeHtml(text) { return String(text).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  }
};
</script>

<style scoped>
.member-survey-page .toolbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
.member-survey-page .pager { margin-top:18px; text-align:right; }
.member-survey-page .section-hint { color:#808695; margin-bottom:16px; }
.editor { max-height:70vh; overflow:auto; padding-right:8px; }
.editor .filter-row { margin:14px 0; }
.hint { color:#808695; margin:10px 0; }
.member-selection-head { display:flex; align-items:center; justify-content:space-between; margin:0 0 12px; }
.member-actions { display:flex; align-items:center; justify-content:flex-end; gap:12px; margin:16px 0; }
.member-actions .hint { margin:0; }
.member-search { margin-bottom:14px; }
.question-editor { border:1px solid #e8eaec; border-radius:5px; padding:14px; margin-bottom:12px; }
.question-head, .actions { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.question-settings { display:flex; align-items:center; gap:18px; margin:10px 0; }
.actions { justify-content:flex-end; gap:10px; margin-top:20px; }
.preview-question { border-bottom:1px solid #eee; padding:14px 0; }
.preview-question p { margin:8px 0 0 12px; }
.analysis-version { display:flex; align-items:center; gap:10px; margin-top:20px; }
.analysis-summary { margin:18px 0 4px; font-weight:600; }
.analysis-question { border:1px solid #e8eaec; border-radius:5px; padding:16px; margin:16px 0; }
.analysis-question-head, .analysis-option-label { display:flex; justify-content:space-between; align-items:center; gap:12px; }
.analysis-option { margin:12px 0; }
.analysis-option-label { margin-bottom:2px; }
.analysis-empty { padding:28px 0; color:#808695; text-align:center; }
.required { color:#ed4014; }
</style>
<style scoped>.paging-label{display:inline-block;margin-top:14px;color:#65758c;font-size:12px}.member-survey-page .pager{float:right;margin-top:12px}</style>
