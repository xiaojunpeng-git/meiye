<template>
  <details class="inventory">
    <summary>统一底层能力盘点（只读）</summary>
    <p>直接读取统一指标字典与查询 Provider 合同，不以报表或 Skill 数量界定业务概念。仅有定义不等于可查询；已登记 Provider 也仍需实例就绪、当前权限、完整条件与执行校验。本目录仅供管理员盘点，不会整体发送给模型。</p>
    <label>筛选底层指标<input v-model="keyword" type="search" placeholder="按业务名称或说明查找" aria-label="筛选底层指标"></label>
    <p>匹配 {{ items.length }} 项；目录只读，不会新增公式或自动开放权限。</p>
    <article v-for="item in items" :key="item.metric_code">
      <strong>{{ item.name || item.metric_code }}</strong> <span>{{ statusLabel(item.status) }}</span>
      <p>{{ item.summary }}</p>
      <details><summary>查看底层定义与组合边界</summary>
        <p>指标代码：{{ item.metric_code }}</p>
        <p v-if="item.include">计入：{{ item.include }}</p><p v-if="item.exclude">不计入：{{ item.exclude }}</p><p v-if="item.timing">时间：{{ item.timing }}</p>
        <p v-if="!item.bindings.length">尚未登记本目录可消费的查询合同，不能据此断言数据库没有数据，也不能直接执行查询。</p>
        <p v-for="binding in item.bindings" :key="binding.capability_code">对象：{{ binding.object_kind }}；归属：{{ binding.relation_role }}；操作：{{ binding.operations.join(' / ') }}；业务筛选：{{ binding.filter_keys.length ? binding.filter_keys.join(' / ') : '暂无额外业务筛选' }}；版本：{{ binding.contract_version }}</p>
      </details>
    </article>
  </details>
</template>
<script>
export default {
  name: 'AiAnalysisInventory', props: { inventory: { type: Object, required: true } }, data() { return { keyword: '' }; },
  computed: { items() { const keyword=this.keyword.trim().toLowerCase(); return (this.inventory.items || []).filter(item => !keyword || [item.name,item.metric_code,item.summary].join(' ').toLowerCase().includes(keyword)); } },
  methods: { statusLabel(status) { return { definition_pending:'口径待确认', definition_only:'已有定义 · 查询合同待接入', provider_registered:'已有查询 Provider 合同 · 仍须运行校验' }[status] || '状态未知'; } }
};
</script>
<style scoped>
.inventory{border-top:1px solid #dce5ee;margin-top:24px;padding-top:8px;color:#304861}p{line-height:1.7}label{display:block;margin:12px 0}input{display:block;box-sizing:border-box;width:100%;max-width:480px;padding:10px;border:1px solid #ccd8e5;border-radius:5px}article{padding:14px;border:1px solid #e1e7ef;border-radius:6px;margin:10px 0;overflow-wrap:anywhere}span{font-size:12px;color:#607184;margin-left:12px}summary{cursor:pointer;color:#315e9a}
</style>
