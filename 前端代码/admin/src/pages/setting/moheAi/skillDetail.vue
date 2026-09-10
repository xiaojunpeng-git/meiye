<template>
  <section class="skill-detail" :aria-label="scene.skill_code + ' 详情'">
    <h3>{{ scene.label }} · Skill v{{ scene.skill_version }}</h3>
    <p>此 Skill 的业务范围来自后端受版本校验的 SKILL.md；当前发布版本：{{ version }}。未保存或未发布的草稿不在本详情中生效。</p>
    <details v-if="runtimeSkill"><summary>查看运行时 SKILL.md（只读）</summary><pre>{{ runtimeSkill.markdown }}</pre></details>
    <h4>场景与能力边界</h4>
    <p>{{ scene.goal }}</p>
    <p v-if="activeScene">已发布场景说明：{{ activeScene.goal }}</p>
    <p>完成条件：{{ scene.completion }}</p>
    <ul><li v-for="item in scene.counterexamples" :key="item">不支持：{{ item }}</li></ul>
    <h4>意图识别与引导</h4>
    <p>先锁定场景与必要条件，再按已登记能力编译计划；不猜指标、不删除筛选、不扩大权限。指标定义由统一数据底层提供，本页不维护计算公式。</p>
    <p>必须明确：{{ labels(scene.required_facts) }}</p>
    <p>可能需要引导：{{ labels(scene.ambiguities) }}</p>
    <template v-if="guidance">
      <p>当前已发布引导上限：{{ guidance.max_rounds }} 轮。以下为全局引导顺序，仅对本次问题尚未明确的适用条件提问。</p>
      <ol><li v-for="key in guidance.slot_order" :key="key">{{ guidance.prompts[key] }}</li></ol>
    </template>
    <p v-else>尚未读取已发布引导配置，不以草稿替代。</p>
    <h4>关联 Workflow 与执行步骤</h4>
    <article v-for="flow in flows" :key="flow.code">
      <strong>{{ shapeLabel(flow.query_shape) }}</strong> <code>{{ flow.code }}</code>
      <p v-if="policy(flow.code)">已发布：{{ policy(flow.code).enabled ? '启用' : '停用' }}；路径预算 {{ policy(flow.code).max_path_ms }} ms；Excel {{ policy(flow.code).allow_export ? '流程允许（仍须通过权限与运行门禁）' : '不允许' }}</p>
      <p v-else>已发布状态未读取</p>
      <ol><li v-for="node in flow.nodes" :key="node.id">{{ nodeLabel(node.id) }} · 最大访问 {{ node.max_visits }} 次 · {{ timeout(flow.code, node) }} ms</li></ol>
    </article>
    <p>只执行已编译节点。权限、证据或能力校验不通过时停止；超出预算或收到有效取消后不得继续发布。文件生成是受控附加步骤，不因登记了 Tool 就保证本次可导出。</p>
    <h4>关联 Tool</h4>
    <article v-for="tool in tools" :key="tool.code"><strong>{{ tool.code }}</strong><p>输入：{{ tool.input_schema }}；输出：{{ tool.output_schema }}</p><p>权限合同：{{ tool.permission_contract }}</p><p>{{ tool.side_effect === 'read_only' ? '只读查询' : '仅生成文件，不修改业务数据' }}；重试策略：{{ tool.retry }}</p></article>
    <h4>已配置的常见问法</h4>
    <ul v-if="activeScene && activeScene.examples.length"><li v-for="(item, i) in activeScene.examples" :key="i">{{ item }}</li></ul>
    <p v-else>当前发布配置尚未填写常见问法。可在「场景与引导」维护；示例不是新增能力或自动训练。</p>
    <p>验收原则：同一登录人、相同期间和完整条件，与统一查询结果一致；以上展示不代表已完成真实业务验收。</p>
    <details><summary>查看原始注册内容（只读）</summary><pre>{{ raw }}</pre></details>
  </section>
</template>

<script>
export default {
  name: 'AiSkillDetail',
  props: { sceneCode: { type: String, required: true }, catalog: { type: Object, required: true }, published: { type: Object, default: null }, version: { type: String, default: '' } },
  computed: {
    scene() { return (this.catalog.scenes || {})[this.sceneCode] || {}; },
    activeScene() { return this.published && this.published.scenes && this.published.scenes[this.sceneCode]; },
    guidance() { return this.published && this.published.guidance; },
    flows() { return Object.keys(this.catalog.workflows || {}).filter(code => this.catalog.workflows[code].scene === this.sceneCode).map(code => ({ ...this.catalog.workflows[code], code })); },
    actions() { return (this.scene.actions || []).filter(code => this.catalog.actions && this.catalog.actions[code]).map(code => ({ ...this.catalog.actions[code], code })); },
    tools() { const codes = new Set(this.actions.map(action => action.tool)); this.flows.forEach(flow => flow.nodes.forEach(node => { if (node.tool) codes.add(node.tool); })); return Array.from(codes).filter(code => (this.catalog.tools || {})[code]).map(code => ({ ...this.catalog.tools[code], code })); },
    runtimeSkill() { return this.scene.runtime_skill_document || (this.catalog.runtime_skills || []).find(skill => skill.skill_code === this.scene.skill_code) || null; },
    raw() { return JSON.stringify({ scene: this.scene, actions: this.actions, workflows: this.flows, tools: this.tools, export_node: this.tools.some(t => t.code === 'verified_export_create') ? this.catalog.export_node : undefined }, null, 2); }
  },
  methods: {
    policy(code) { return this.published && this.published.workflows && this.published.workflows[code]; },
    timeout(code, node) { const policy = this.policy(code); return policy && policy.node_timeouts ? policy.node_timeouts[node.id] : '已发布值未知；注册上限 ' + node.timeout_ms; },
    shapeLabel(shape) { return { summary: '汇总', trend: '趋势', comparison: '两期对比', ranking: '门店排行' }[shape] || shape; },
    nodeLabel(id) { return { query: '统一查询', evidence: '证据校验', render: '确定性渲染' }[id] || id; },
    labels(values) { const names = { confirmed_metric: '明确指标', complete_period: '完整期间', current_business_scope: '当前数据权限', complete_filters: '完整筛选条件', ranking_contract: '已登记排行规则', performance_metric: '业绩指标', period: '查询期间', comparison_period: '对比期间', evaluation_metric: '评价指标', direction: '排行方向', count: '排行数量' }; return (values || []).map(v => names[v] || v).join('、'); }
  }
};
</script>

<style scoped>
.skill-detail{margin-top:12px;padding:16px;background:#f7f9fc;border-radius:8px;overflow-wrap:anywhere;color:#304861}h3{margin:0 0 12px}h4{margin:20px 0 8px}p,li{line-height:1.8}article{border-left:3px solid #b8cce6;padding:8px 14px;margin:12px 0;background:white}code{font-size:12px}summary{cursor:pointer;padding:10px 0;color:#315e9a}pre{white-space:pre-wrap;max-height:360px;overflow:auto;font-size:12px;background:white;padding:12px}
</style>
