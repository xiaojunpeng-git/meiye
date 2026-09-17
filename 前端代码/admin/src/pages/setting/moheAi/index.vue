<template>
  <main class="ai-management">
    <header class="heading"><div><h1>魔核 AI</h1><p>业务能力注册与执行编排 · 仅平台 admin 维护</p></div><button :disabled="busy" @click="reload">刷新</button></header>
    <p v-if="error" role="alert" class="error">{{ error }}</p>
    <p v-if="notice" role="status" class="notice">{{ notice }}</p>
    <div v-if="loading" class="box">正在读取管理配置…</div>
    <template v-else-if="state && document">
      <section v-if="state.source_changed" class="source-warning" role="alert">
        <div><strong>底层能力已升级</strong><p>已发布配置仍绑定旧能力版本，新任务暂不执行。请先同步到最新能力，再校验并发布。系统只保留可编辑的场景、引导和预算设置，不会从旧版带入指标公式或执行代码。</p></div>
        <button class="primary" :disabled="busy || dirty" @click="rebaseSource">同步最新能力</button>
      </section>
      <div class="version-bar">已发布：{{ activeVersion }} <span>草稿修订：{{ state.revision }}</span><span>{{ dirty ? '有未保存修改' : '已与服务器同步' }}</span></div>
      <nav aria-label="魔核 AI 管理模块"><button v-for="item in tabs" :key="item.key" :class="{ selected: tab === item.key }" @click="tab = item.key">{{ item.label }}</button></nav>

      <section v-if="tab === 'config'" class="box">
        <h2>基础配置</h2><p class="muted">使用客户自己的 SiliconFlow 账号，费用由客户承担。密钥保存后不回显，留空表示保持不变。</p>
        <form v-if="config" @submit.prevent="saveConfig">
          <label class="check"><input v-model="config.enabled" type="checkbox">启用魔核 AI</label>
          <label>模型名称<input v-model.trim="config.model" aria-label="模型名称" required maxlength="180"></label>
          <label>API Key<input v-model="apiKey" type="password" autocomplete="new-password" aria-label="API Key" :placeholder="config.has_api_key ? '已保存密钥，留空保持不变' : '请输入客户 API Key'"></label>
          <label class="check"><input v-model="config.external_processing_authorized" type="checkbox">已获得客户对 SiliconFlow 外部处理的授权</label>
          <label class="check"><input :checked="config.external_scope_version === 'sanitized-question-v1'" :disabled="!config.external_scope_supported || !config.external_processing_authorized" type="checkbox" @change="config.external_scope_version = $event.target.checked ? 'sanitized-question-v1' : ''">另行授权发送最小脱敏当前问题及必要能力摘要</label>
          <p class="muted">此项不沿用旧授权。仅发送到本客户配置的 SiliconFlow；不发送人员或门店名称、联系方式、内部编号、经营数字或历史回答。无法可靠脱敏时转本地选择引导。取消上方外部处理授权会同时撤销此项。</p>
          <p v-if="!config.external_scope_supported" class="muted">当前实例尚未升级授权存储，不能启用新外发范围；原有配置仍可保存。</p>
          <div class="actions"><button class="primary" :disabled="busy" type="submit">保存基础配置</button><button type="button" :disabled="busy" @click="checkConnection">测试连接（可能消耗客户额度）</button></div>
        </form>
        <div v-if="config && config.runtime_status" class="subbox"><h3>近 24 小时运行概况</h3><div class="metrics"><span v-for="item in runtimeCounts" :key="item.label">{{ item.label }}：{{ item.value }}</span></div><p class="muted">运行数量不能证明模型连接、账号余额或服务可用。{{ monitorText }}</p></div>
        <div v-if="config && config.runtime_status" class="subbox"><h3>完整答案等待</h3><div class="metrics"><span>异步浏览器完整答案样本：{{ answerTiming.count }}</span><span>中位数：{{ formatMs(answerTiming.p50_ms) }}</span><span>P95：{{ formatMs(answerTiming.p95_ms) }}</span><span>异步完整答复占已结束异步任务：{{ completionRate }}</span><span>异步运行版本组：{{ asyncProfileCount }}</span></div><p class="muted">{{ answerTimingText }}</p><details v-if="latencySegments.length"><summary>服务端分段耗时（近 24 小时聚合）</summary><div class="metrics detail-metrics"><span v-for="segment in latencySegments" :key="segment.code">{{ segment.label }}：{{ segment.count }} 次，P50 {{ formatMs(segment.p50_ms) }}，P95 {{ formatMs(segment.p95_ms) }}</span></div><p class="muted">服务端分段不包含浏览器轮询与首屏绘制；完整答案等待以“浏览器完整答案”单独统计。</p></details><details v-if="modelStages.length"><summary>模型阶段耗时（近 24 小时聚合）</summary><div class="metrics detail-metrics"><span v-for="stage in modelStages" :key="stage.code">{{ stage.code }}：{{ stage.count }} 次，P50 {{ formatMs(stage.p50_ms) }}，P95 {{ formatMs(stage.p95_ms) }}</span></div></details><details v-if="technicalReasons.length"><summary>技术失败原因（近 24 小时聚合）</summary><div class="metrics detail-metrics"><span v-for="reason in technicalReasons" :key="reason.code">{{ reason.code }}：{{ reason.count }}</span></div></details><details v-if="diagnosticPredicates.length"><summary>模型协议诊断（近 24 小时聚合）</summary><div class="metrics detail-metrics"><span v-for="diagnostic in diagnosticPredicates" :key="diagnostic.code">{{ diagnostic.code }}：{{ diagnostic.count }}</span></div></details><p v-if="technicalReasons.length || diagnosticPredicates.length" class="muted">仅显示服务端生命周期与协议代码，不保存问题、人员、指标或经营结果。</p></div>
      </section>

      <section v-if="tab === 'catalog'" class="box">
        <h2>能力目录</h2><p class="muted">目录来自已登记的后端能力。管理配置不能新增指标公式、SQL、DAO 或扩大数据权限。</p>
        <p class="muted">下方场景是可复用的引导与执行模板，不是用户问题白名单。实际可查询对象、指标及组合由统一底层合同与当前数据权限决定；排行流程可复用于已授权的门店或人员数据。</p>
        <ai-analysis-inventory v-if="catalog.analysis_inventory" :inventory="catalog.analysis_inventory" />
        <h3>一级 · 业务 Skill</h3><div v-for="(scene, code) in catalog.scenes" :key="code" class="subbox"><strong>{{ scene.label }}</strong><p class="muted">业务语义以已发布的 Skill 原文为准；可执行范围仍由统一指标合同和当前权限决定。</p><code>{{ code }}</code><details v-if="scene.skill_code"><summary>查看 Skill 详情 · {{ scene.skill_code }}</summary><ai-skill-detail :scene-code="code" :catalog="catalog" :published="state.active_document" :version="activeVersion" /></details></div>
        <h3>二级 · Skill 与工作流</h3><div v-for="(flow, code) in catalog.workflows" :key="code" class="subbox"><strong>{{ workflowLabel(code) }}</strong><p>Skill：{{ workflowSkill(flow) }} · {{ workflowStatus(code) }}</p><code>{{ code }}</code><p>支持形态：{{ flow.shape || flow.query_shape || '按已登记契约' }}</p></div>
        <h3>三级 · 只读 Tool 与执行节点</h3><div v-for="(tool, code) in catalog.tools" :key="code" class="subbox"><strong>{{ tool.label || code }}</strong><p>{{ tool.description || '只读能力，参数与权限由后端校验' }}</p><code>{{ code }}</code></div>
      </section>

      <section v-if="tab === 'scenes'" class="box">
        <h2>场景与引导</h2><p class="muted">业务语义来自版本化 Skill 原文，不在这里维护第二份场景说明或问法列表。这里仅可调整显示名称和通用引导策略。</p>
        <fieldset v-for="(scene, code) in document.scenes" :key="code"><legend>{{ scene.label }}</legend><label>显示名称<input v-model="scene.label" maxlength="80" @input="changed"></label></fieldset>
        <label>最多引导轮数<select v-model.number="document.guidance.max_rounds" aria-label="最多引导轮数" @change="changed"><option :value="3">3 轮</option><option :value="4">4 轮</option><option :value="5">5 轮</option></select></label>
        <h3>分步引导顺序与提示语</h3><p class="muted">只对尚未明确的条件提问；日期起止始终一起选择，不因顺序调整重复提问。</p>
        <div v-for="(slot, index) in document.guidance.slot_order" :key="slot" class="subbox"><div class="row"><strong>{{ index + 1 }}. {{ slotLabel(slot) }}</strong><span class="actions"><button :disabled="index === 0" @click="moveSlot(index, -1)">上移</button><button :disabled="index === document.guidance.slot_order.length - 1" @click="moveSlot(index, 1)">下移</button></span></div><label>提示语<input v-model="document.guidance.prompts[slot]" maxlength="240" @input="changed"></label></div>
        <button class="primary" :disabled="busy || !dirty" @click="saveDraft">保存草稿</button>
      </section>

      <section v-if="tab === 'workflows'" class="box">
        <h2>工作流编排</h2><p class="muted">配置已登记流程的启停、导出与执行预算。证据校验和渲染顺序固定，不允许跳过安全节点或添加任意代码。</p>
        <fieldset v-for="(flow, code) in document.workflows" :key="code"><legend>{{ workflowLabel(code) }}</legend><div class="actions"><label class="check"><input v-model="flow.enabled" type="checkbox" @change="changed">允许执行</label><label class="check"><input v-model="flow.allow_export" type="checkbox" :disabled="!defaults.workflows[code].allow_export" @change="changed">允许 Excel</label></div>
          <ol class="flow" aria-label="固定执行流程"><li v-for="node in workflowNodes(code)" :key="node.id"><strong>{{ nodeLabel(node.id) }}</strong><small>{{ node.id }}</small></li></ol>
          <label>完整路径预算（毫秒）<input v-model.number="flow.max_path_ms" type="number" min="100" :max="defaults.workflows[code].max_path_ms" @input="changed"></label>
          <div class="node-grid"><label v-for="(timeout, node) in flow.node_timeouts" :key="node">{{ nodeLabel(node) }}预算（毫秒）<input v-model.number="flow.node_timeouts[node]" type="number" min="100" :max="defaults.workflows[code].node_timeouts[node]" @input="changed"></label></div>
          <p class="muted">仅可收紧源码登记预算；完整路径预算须覆盖全部节点。条件不足走引导，证据不足或超时停止，文件失败依既有规则处理。</p>
        </fieldset><button class="primary" :disabled="busy || !dirty" @click="saveDraft">保存草稿</button>
      </section>

      <section v-if="tab === 'trial'" class="box"><h2>试问验证</h2><p class="muted">草稿预览只检查意图与编译，不调用模型、不查询经营数据；真实试问使用已发布版本，并沿用当前 admin 的报表数据权限。</p><label>测试问题<textarea v-model="question" maxlength="2000" placeholder="例如：这个月收了多少钱"></textarea></label><button :disabled="busy || dirty || !question.trim()" @click="previewDraft">草稿意图预览</button><p v-if="dirty" class="muted">请先保存草稿再预览。</p><div v-if="preview" class="subbox"><h3>草稿预览结果</h3><pre>{{ printable(preview) }}</pre></div><ai-trial :question="question" /></section>

      <section v-if="tab === 'versions'" class="box"><h2>版本管理</h2><p class="muted">发布仅影响之后创建的任务，已运行任务使用创建时的固定版本。回滚生成新的发布版本，不修改历史。</p><div class="actions"><button :disabled="busy || !dirty" @click="saveDraft">保存草稿</button><button :disabled="busy || dirty" @click="validateDraft">校验草稿</button><button class="primary" :disabled="busy || dirty || !validated" @click="publishDraft">发布草稿</button><button :disabled="busy || dirty || activeVersion === 'source'" @click="rollback('source')">恢复源码默认配置</button></div><p role="status">{{ validated ? '当前草稿已校验通过，可以发布。' : '发布前请保存并校验当前草稿。' }}</p><h3>与已发布版本的变更</h3><ul v-if="diffs.length"><li v-for="line in diffs" :key="line">{{ line }}</li></ul><p v-else>暂无配置差异。</p><div class="table-scroll"><table><thead><tr><th>版本</th><th>操作</th><th>时间</th><th>操作入口</th></tr></thead><tbody><tr v-for="version in state.versions" :key="version.version"><td>{{ version.version }}{{ version.version === activeVersion ? '（当前）' : '' }}</td><td>{{ version.action === 'rollback' ? '回滚' : '发布' }}</td><td>{{ formatTime(version.created_at) }}</td><td><button :disabled="busy || dirty || version.version === activeVersion" @click="rollback(version.version)">回滚到此版本</button></td></tr></tbody></table></div></section>
    </template>
    <ai-confirm ref="confirmation" />
  </main>
</template>

<script>
import request from '@/api/moheAi';
import AiTrial from './trial.vue';
import AiConfirm from './confirm.vue';
import AiSkillDetail from './skillDetail.vue';
import AiAnalysisInventory from './analysisInventory.vue';
const copy = value => JSON.parse(JSON.stringify(value));
export default {
  name: 'MoheAiManagement', components: { AiTrial, AiConfirm, AiSkillDetail, AiAnalysisInventory },
  data() { return { tab: 'config', tabs: [{ key: 'config', label: '基础配置' }, { key: 'catalog', label: '能力目录' }, { key: 'scenes', label: '场景与引导' }, { key: 'workflows', label: '工作流编排' }, { key: 'trial', label: '试问验证' }, { key: 'versions', label: '版本管理' }], state: null, document: null, config: null, apiKey: '', loading: true, busy: false, dirty: false, validated: false, error: '', notice: '', question: '', preview: null }; },
  computed: {
    catalog() { return this.state.catalog || {}; }, defaults() { return this.catalog.defaults || { workflows: {} }; },
    activeVersion() { return typeof this.state.active_version === 'object' ? this.state.active_version.version : this.state.active_version; },
    runtimeCounts() { const values = this.config.runtime_status; return [['success', '完整成功'], ['partial', '数据成功、文件失败'], ['technical', '技术失败'], ['active', '处理中']].filter(([key]) => Number.isInteger(values[key])).map(([key, label]) => ({ label, value: values[key] })); },
    monitorText() { const m = this.config.runtime_status.monitoring; return m && m.status === 'ok' ? '已登记监控项当前未触发告警。' : '运行健康尚未确认或存在告警，请检查服务状态。'; },
    answerTiming() { const status = this.config.runtime_status || {}; const timing = status.latency_cohorts && status.latency_cohorts.async && status.latency_cohorts.async.browser_observed; return timing && Number.isInteger(timing.count) ? timing : { count: 0, p50_ms: null, p95_ms: null }; },
    asyncProfileCount() { const counts = (this.config.runtime_status || {}).comparison_profile_counts || {}; return Number.isInteger(counts.async) ? counts.async : 0; },
    latencySegments() { const segments = ((this.config.runtime_status || {}).segments || {}); const labels = { acceptance: '接纳', queue: '排队', model: '模型调用', reader: '统一查询', delivery: '服务端交付', answer: '服务端完整答复' }; return Object.keys(labels).map(code => ({ code, label: labels[code], ...(segments[code] || {}) })).filter(item => Number.isInteger(item.count) && item.count > 0); },
    modelStages() { const stages = ((this.config.runtime_status || {}).model_stages || {}); return Object.keys(stages).map(code => ({ code, ...stages[code] })).filter(item => Number.isInteger(item.count) && item.count > 0); },
    technicalReasons() { const reasons = ((this.config.runtime_status || {}).technical_reasons || {}); return Object.keys(reasons).map(code => ({ code, count: reasons[code] })).filter(item => Number.isInteger(item.count) && item.count > 0); },
    diagnosticPredicates() { const diagnostics = ((this.config.runtime_status || {}).diagnostic_predicates || {}); return Object.keys(diagnostics).map(code => ({ code, count: diagnostics[code] })).filter(item => Number.isInteger(item.count) && item.count > 0); },
    completionRate() { const status = this.config.runtime_status || {}; const outcomes = (status.outcome_cohorts && status.outcome_cohorts.async) || {}; const completed = Number.isInteger(outcomes.success) ? outcomes.success : 0; const total = ['success', 'partial', 'technical', 'neutral', 'capacity', 'export', 'export_unknown', 'security'].reduce((sum, key) => sum + (Number.isInteger(outcomes[key]) ? outcomes[key] : 0), 0); return total ? `${Math.round(completed * 10000 / total) / 100}%（${completed}/${total}）` : '暂无已结束异步样本'; },
    answerTimingText() { const timing = this.answerTiming; if (!timing.count) return '尚未收到浏览器完整答案耗时，不能据此判断问答是否变快。'; if (this.asyncProfileCount > 1) return `当前异步样本跨 ${this.asyncProfileCount} 个运行版本组，不能混合比较提速；请在同一模型、能力和预算版本下重新积累样本。`; if (timing.count < 20) return `当前仅 ${timing.count} 个异步浏览器样本，先持续积累；不能据此宣称中位数、P95 或提速比例。`; return '该数据包含排队、模型、查询、轮询与页面交付；请结合固定场景和成功率比较优化前后结果。'; },
    diffs() { const active = this.state.active_document || (this.state.active_version && this.state.active_version.document); if (!active) return ['当前发布配置尚未读取，暂不能对比。']; const out = []; const walk = (a, b, path) => { if (JSON.stringify(a) === JSON.stringify(b)) return; if (a && b && !Array.isArray(a) && typeof a === 'object' && typeof b === 'object') Object.keys(b).forEach(k => walk(a[k], b[k], path ? path + ' / ' + k : k)); else out.push(path + '：' + JSON.stringify(a) + ' → ' + JSON.stringify(b)); }; walk(active, this.document, ''); return out; }
  },
  created() { this.reload(); },
  async beforeRouteLeave(to, from, next) { if (this.dirty && !await this.confirmAction('草稿尚未保存，离开将丢失本页修改，是否继续？', '离开管理页面')) return next(false); next(); },
  beforeDestroy() { this.apiKey = ''; },
  deactivated() { this.apiKey = ''; },
  methods: {
    async perform(action) { if (this.busy) return; this.busy = true; this.error = ''; this.notice = ''; try { return await action(); } catch (e) { this.error = e.message || '操作未完成，请重试。'; } finally { this.busy = false; } },
    hydrate(value) { this.state = { ...(this.state || {}), ...value }; this.document = copy(value.draft.document || value.draft); this.dirty = false; this.validated = false; this.preview = null; },
    confirmAction(message, title) { return this.$refs.confirmation ? this.$refs.confirmation.open(message, title) : Promise.resolve(false); },
    async reload() { if (this.dirty && !await this.confirmAction('刷新会丢失未保存修改，是否继续？', '刷新管理配置')) return; await this.perform(async () => { this.loading = true; try { const state = await request('GET', '/management'); this.hydrate(state); this.config = await request('GET', '/config'); } finally { this.loading = false; } }); },
    changed() { this.dirty = true; this.validated = false; this.preview = null; },
    moveSlot(index, delta) { const order = this.document.guidance.slot_order; const next = order.slice(); [next[index], next[index + delta]] = [next[index + delta], next[index]]; this.document.guidance.slot_order = next; this.changed(); },
    slotLabel(code) { return { metric_code: '指标意图', start_date: '查询时间', compare_start: '对比时间', rank_direction: '排行方向', rank_limit: '排行数量' }[code] || code; },
    nodeLabel(code) { return { query: '统一查询', catalog: '指标说明', evidence: '证据校验', render: '确定性渲染', export: 'Excel 导出' }[code] || code; },
    workflowLabel(code) { return { wf_performance_summary: '业绩汇总', wf_performance_trend: '业绩趋势', wf_performance_ranking: '表现排行（按底层对象能力）', wf_performance_comparison: '两期对比', wf_metric_definition: '指标解释' }[code] || code; },
    workflowNodes(code) { return (this.catalog.workflows[code] || {}).nodes || []; },
    workflowSkill(flow) { const scene = this.catalog.scenes && this.catalog.scenes[flow.scene]; return scene ? scene.skill_code : '未绑定业务 Skill'; },
    workflowStatus(code) { return this.document.workflows[code].enabled ? '草稿中启用' : '草稿中停用'; },
    printable(value) { return JSON.stringify(value, null, 2); }, formatTime(value) { return value ? new Date(Number(value) * 1000).toLocaleString() : '—'; }, formatMs(value) { return Number.isInteger(value) ? `${(value / 1000).toFixed(1)} 秒` : '—'; },
    saveDraft() { return this.perform(async () => { this.hydrate(await request('PUT', '/management/draft', { expected_revision: this.state.revision, document: this.document })); this.notice = '草稿已保存，尚未发布。'; }); },
    validateDraft() { return this.perform(async () => { const result = await request('POST', '/management/validate', { expected_revision: this.state.revision }); if (result.valid !== true) throw new Error('尚未取得草稿校验通过结果，请重新校验。'); if (result.draft) this.hydrate(result); this.validated = true; this.notice = '草稿校验通过。'; }); },
    async publishDraft() { if (!await this.confirmAction('发布后，新任务将使用当前草稿；在途任务不受影响。确认发布？', '发布草稿')) return; return this.perform(async () => { this.hydrate(await request('POST', '/management/publish', { expected_revision: this.state.revision })); this.notice = '版本已发布。'; }); },
    async rollback(version) { if (!await this.confirmAction('确认恢复到所选版本的配置？系统将生成新的发布版本。', '回滚配置')) return; return this.perform(async () => { this.hydrate(await request('POST', '/management/rollback', { expected_revision: this.state.revision, target_version: version })); this.notice = '已回滚并生成新版本。'; }); },
    previewDraft() { return this.perform(async () => { this.preview = await request('POST', '/management/preview', { expected_revision: this.state.revision, question: this.question.trim() }); }); },
    async rebaseSource() { if (!await this.confirmAction('将现有可编辑配置合并到最新底层能力。同步后仍需校验并发布，确认继续？', '同步底层能力')) return; return this.perform(async () => { this.hydrate(await request('POST', '/management/rebase', { expected_revision: this.state.revision })); this.notice = '已同步最新能力，请到版本管理校验并发布。'; }); },
    saveConfig() { return this.perform(async () => { try { const payload = { version: this.config.version, enabled: this.config.enabled, model: this.config.model, external_processing_authorized: this.config.external_processing_authorized, api_key: this.apiKey }; if (this.config.external_scope_supported) payload.external_scope_version = this.config.external_processing_authorized ? this.config.external_scope_version : ''; await request('PUT', '/config', payload); this.config = await request('GET', '/config'); this.notice = '基础配置已保存。'; } finally { this.apiKey = ''; } }); },
    async checkConnection() { if (!await this.confirmAction('此测试可能消耗客户 SiliconFlow 额度，只测试连接，不携带经营数据。是否继续？', '测试模型连接')) return; return this.perform(async () => { const result = await request('POST', '/config/check', { confirm_cost: true }); this.notice = result.message || '测试完成，请查看配置状态。'; }); }
  }
};
</script>

<style scoped>
.ai-management{padding:20px;color:#263b50;max-width:1440px;margin:auto}.heading,.row,.source-warning{display:flex;justify-content:space-between;align-items:center;gap:16px}h1{font-size:23px;margin:0}h2{font-size:19px;margin:0 0 12px}h3{font-size:15px;margin:16px 0 8px}p{line-height:1.7;margin:8px 0}.heading p,.muted{color:#718096;font-size:13px}.source-warning{background:#fff8e8;border:1px solid #e8c878;border-radius:8px;padding:14px 16px;margin-top:16px}.source-warning p{margin-bottom:0}.box{background:#fff;border:1px solid #e1e7ef;border-radius:10px;padding:22px;margin-top:16px}.subbox,fieldset{border:1px solid #e1e7ef;border-radius:8px;padding:16px;margin:12px 0;min-width:0}legend{padding:0 8px;font-weight:600}.version-bar{display:flex;gap:25px;flex-wrap:wrap;margin:18px 0;color:#607184}nav{display:flex;gap:8px;flex-wrap:wrap}button{border:1px solid #d5dfeb;border-radius:6px;background:#fff;padding:8px 14px;cursor:pointer;color:#304861;font:inherit}button:disabled{opacity:.45;cursor:not-allowed}.selected,.primary{background:#4169a1;color:#fff;border-color:#4169a1}label{display:block;margin:12px 0;max-width:800px;font-size:14px}input:not([type=checkbox]),textarea,select{display:block;width:100%;padding:9px 11px;margin-top:6px;border:1px solid #cbd5e1;border-radius:5px;background:#fff;color:inherit;box-sizing:border-box;font:inherit}textarea{min-height:80px;resize:vertical}.check{display:flex;gap:9px;align-items:center}.actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.metrics{display:flex;gap:22px;flex-wrap:wrap}.error{background:#fff2ef;color:#9a3412;padding:12px}.notice{background:#ecf6f2;color:#28624b;padding:12px}.flow{display:flex;gap:24px;flex-wrap:wrap;list-style:none;padding:10px 0}.flow li{position:relative;padding:12px 18px;background:#f0f4fa;border-radius:6px}.flow li:not(:last-child):after{content:'→';position:absolute;right:-20px;top:17px}.flow small{display:block;color:#718096}.node-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}.table-scroll{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:12px;text-align:left;border-bottom:1px solid #e5eaf0}pre{white-space:pre-wrap;word-break:break-word;max-height:420px;overflow:auto;background:#f6f8fb;padding:12px}code{font-size:12px;color:#718096}@media(max-width:700px){.ai-management{padding:12px}.box{padding:15px}.version-bar{gap:10px}.heading,.source-warning{align-items:flex-start;flex-direction:column}}
</style>
