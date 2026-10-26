<template>
  <div>
    <section v-for="item in standard.items" :key="item.key" class="growth-chapter">
      <h3>{{ item.title }} <span v-if="item.critical" style="color: #aa7342">关键项</span></h3>
      <p>{{ item.description }}</p>
      <label v-if="item.allow_na"
        ><input v-model="value[item.key].not_applicable" type="checkbox" />本次不适用</label
      >
      <div v-if="value[item.key].not_applicable">
        <label>不适用原因</label><textarea v-model="value[item.key].reason" rows="2"></textarea>
      </div>
      <div v-else>
        <label v-if="standard.mode === 'score'">分项得分（满分{{ item.max_score }}）</label
        ><input
          v-if="standard.mode === 'score'"
          v-model.number="value[item.key].score"
          type="number"
          min="0"
          :max="item.max_score"
        /><label v-else>检查结论</label
        ><select v-if="standard.mode !== 'score'" v-model="value[item.key].passed">
          <option :value="null">请选择</option>
          <option :value="true">通过</option>
          <option :value="false">未通过</option></select
        ><label>评价依据</label><textarea v-model="value[item.key].note" rows="2"></textarea
        ><label>{{ item.evidence_required ? '必需证据' : '补充证据（可选）' }}</label
        ><EvidenceInput v-model="value[item.key].asset_ids" :menu="menu" :task-id="taskId" />
      </div>
    </section>
  </div>
</template>
<script>
import EvidenceInput from './EvidenceInput.vue';
/** 逐项录入不计算总分，保存与最终结论统一由后端检查表规则决定。 */
export default {
  name: 'GrowthReviewItems',
  components: { EvidenceInput },
  props: {
    standard: { type: Object, required: true },
    value: { type: Object, required: true },
    menu: { type: String, default: 'practice' },
    taskId: { type: Number, default: 0 }
  }
};
</script>
