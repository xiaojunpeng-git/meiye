<template>
  <section class="growth-panel">
    <div class="growth-actions">
      <h2>课程默认规则模板</h2>
      <button @click="add">添加模板</button>
    </div>
    <p>新建课程可一键带出，修改模板只影响以后选用的内容。具体规则仍可在课程中调整。</p>
    <article v-for="(r, i) in value" :key="r.key" class="growth-chapter">
      <div class="growth-form-grid">
        <label>模板名称<input v-model="r.title" maxlength="180" /></label
        ><label
          >用途<select v-model="r.purpose">
            <option value="course">课程学习</option>
            <option value="knowledge">知识查阅</option>
          </select></label
        ><label
          >视频默认完成方式<select v-model="r.completion">
            <option value="confirm">确认学习</option>
            <option value="coverage">有效观看覆盖</option>
          </select></label
        ><label v-if="r.completion === 'coverage'"
          >观看比例（%）<input v-model.number="r.coverage_percent" type="number" min="1" max="100" /></label
        ><label
          >发布方式<select v-model.number="r.confirmer_employee_id">
            <option :value="0">直接发布</option>
            <option v-for="p in people" :key="p.id" :value="Number(p.id)">由 {{ p.name }} 确认</option>
          </select></label
        >
      </div>
      <div class="growth-actions">
        <label><input type="checkbox" v-model="r.foreground_required" />仅前台有效播放</label
        ><label><input type="checkbox" v-model="r.allow_speed" />允许倍速</label
        ><label><input type="checkbox" v-model="r.single_session" />单一学习会话</label>
      </div>
      <label
        >奖励选择<select v-model="r.reward_selection.mode">
          <option value="default">沿用企业已启用规则</option>
          <option value="selected">只用所选规则（可不选）</option>
        </select></label
      >
      <div v-if="r.reward_selection.mode === 'selected'" class="growth-form-grid">
        <label
          >积分规则<select multiple v-model="r.reward_selection.point_rule_ids">
            <option v-for="p in points" :key="p.id" :value="Number(p.id)">{{ p.title }}</option>
          </select></label
        ><label
          >证书规则<select multiple v-model="r.reward_selection.certificate_rule_ids">
            <option v-for="p in certificates" :key="p.id" :value="Number(p.id)">{{ p.title }}</option>
          </select></label
        >
      </div>
      <label>模板提醒安排</label>
      <div class="growth-actions">
        <label v-for="(name, key) in reminderNames" :key="key"
          ><input type="checkbox" v-model="r.reminder_overrides[key]" />{{ name }}</label
        >
      </div>
      <button
        class="growth-danger"
        @click="
          $emit(
            'input',
            value.filter((_, index) => index !== i)
          )
        "
      >
        移除模板
      </button>
    </article>
    <p v-if="!value.length">未配置模板时，课程使用企业默认值。</p>
  </section>
</template>
<script>
/** 模板只维护初始业务规则，最终生效内容仍由课程发布确认并冻结。 */
export default {
  name: 'GrowthRuleTemplates',
  props: {
    value: { type: Array, default: () => [] },
    people: { type: Array, default: () => [] },
    points: { type: Array, default: () => [] },
    certificates: { type: Array, default: () => [] }
  },
  data: () => ({
    reminderNames: {
      assignment: '分配提醒',
      result: '结果提醒',
      due: '临期提醒',
      overdue: '逾期提醒',
      pending: '待确认提醒'
    }
  }),
  methods: {
    add() {
      this.$emit('input', [
        ...this.value,
        {
          key: 'template-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6),
          title: '',
          purpose: 'course',
          completion: 'confirm',
          coverage_percent: 90,
          foreground_required: true,
          allow_speed: false,
          single_session: true,
          confirmer_employee_id: 0,
          reward_selection: { mode: 'default', point_rule_ids: [], certificate_rule_ids: [] },
          reminder_overrides: { assignment: true, result: true, due: true, overdue: true, pending: true }
        }
      ]);
    }
  }
};
</script>
