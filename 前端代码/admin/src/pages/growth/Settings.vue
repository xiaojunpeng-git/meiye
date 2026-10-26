<template>
  <main class="growth-page">
    <header class="growth-head">
      <div>
        <h1>成长设置</h1>
        <p>启用员工学习与成长，保持简单的菜单管理。</p>
      </div>
    </header>
    <p v-if="error" class="growth-error" role="alert">{{ error }}</p>
    <p v-if="notice" class="growth-success" role="status">{{ notice }}</p>
    <div class="growth-settings-scroll">
      <section class="growth-panel">
        <h2>模块开关</h2>
        <label><input v-model="enabled" type="checkbox" />启用成长管理</label>
        <p>关闭后保留历史记录，暂停新的成长操作；恢复不自动补跑期间任务。</p>
        <label><input v-model="linked" type="checkbox" />联看现有经营指标</label>
        <p>经营指标保持原系统口径，不对接时独立完成培养与学习。</p>
        <div class="growth-actions" style="margin-top: 22px">
          <button class="growth-primary" :disabled="busy || loading" data-operation="growth.settings.save" @click="save">
            {{ busy ? '保存中…' : '保存设置' }}
          </button>
        </div>
      </section>
      <section class="growth-panel">
        <h2>站内提醒策略</h2>
        <div class="growth-form-grid">
          <label v-for="(name, key) in reminderTypes" :key="key"
            ><input type="checkbox" v-model="reminders[key]" />{{ name }}</label
          >
        </div>
        <div class="growth-form-grid">
          <div>
            <label>提前提醒（小时）</label
            ><input v-model.number="reminders.lead_hours" type="number" min="0" max="720" />
          </div>
          <div>
            <label>同类提醒最小间隔（小时）</label
            ><input v-model.number="reminders.frequency_hours" type="number" min="1" max="168" />
          </div>
          <div>
            <label>截止 / 逾期接收人</label
            ><select v-model="reminders.recipient">
              <option value="executor">任务执行人</option>
              <option value="handler">指定处理人，未指定时执行人</option>
            </select>
          </div>
          <label><input v-model="reminders.summary" type="checkbox" />同类事项汇总提醒</label>
        </div>
        <button
          style="margin-top: 16px"
          class="growth-primary"
          :disabled="busy || loading"
          data-operation="growth.reminders.save"
          @click="save"
        >
          保存提醒策略
        </button>
      </section>
      <RuleTemplates v-if="!loading"
        v-model="courseTemplates"
        :people="people"
        :points="pointOptions"
        :certificates="certificateOptions"
      />
      <button class="growth-primary" :disabled="busy || loading" @click="save">保存规则模板</button>
      <Rewards v-if="enabled && !loading" /><section class="growth-panel" v-if="monitor"><div class="growth-actions"><h2>正式结果联动</h2><button :disabled="busy" @click="loadMonitor">刷新状态</button></div><p v-if="!monitor.ready">升级尚未完成，成长业务暂不处理。</p><template v-else><p>待处理 {{ monitor.pending }} 项 · 处理失败 {{ monitor.failed }} 项</p><p>{{ monitor.notice }}</p><p v-if="!monitor.enabled">模块当前关闭，正式历史保留，后台处理暂停。</p><article v-for="r in monitor.rows" :key="r.id" class="growth-chapter"><strong>{{ eventName(r.event_type) }} · {{ r.delivery_state==='FAILED'?'等待重试':'等待处理' }}</strong><p>来源记录 #{{ r.source_id }} · 已尝试 {{ r.attempts }} 次</p></article><button class="growth-primary" :disabled="busy || !monitor.enabled" @click="retryProcess">重试待处理联动</button></template></section>
    </div>
  </main>
</template>
<script>
import Rewards from './Rewards.vue';
import RuleTemplates from './RuleTemplates.vue';
import { growthRead, growthWrite } from '@/api/growth';
/** 总开关使用独立设置菜单，服务端同时控制API与资源授权。 */
export default {
  name: 'GrowthSettings',
  components: { Rewards, RuleTemplates },
  data: () => ({
    enabled: false,
    loading: true,
    linked: false,
    busy: false,
    error: '',
    notice: '',
    templates: {}, monitor:null,
    courseTemplates: [],
    people: [],
    pointOptions: [],
    certificateOptions: [],
    reminders: {
      assignment: true,
      result: true,
      due: true,
      overdue: true,
      pending: true,
      certificate: true,
      lead_hours: 24,
      frequency_hours: 24,
      recipient: 'executor',
      summary: true
    },
    reminderTypes: {
      assignment: '任务分配',
      result: '结果公布与退回',
      due: '临近截止',
      overdue: '逾期事项',
      pending: '待点评 / 阅卷 / 鉴定 / 复查',
      certificate: '证书到期'
    }
  }),
  async created() {
    try {
      const r = await growthRead('settings');
      this.enabled = !!Number(r.data.enabled);
      this.linked = !!Number(r.data.business_link_enabled);
      try {
        this.templates = JSON.parse(r.data.templates_json || '{}');
      } catch (_e) {
        this.templates = {};
      }
      this.reminders = { ...this.reminders, ...(this.templates.reminders || {}) };
      this.courseTemplates = this.templates.course_templates || [];
      const catalog = (await growthRead('catalog', { menu: 'settings' })).data;
      this.people = catalog.employees;
      this.pointOptions = catalog.point_rule_options;
      this.certificateOptions = catalog.certificate_rule_options;
      this.loading=false;await this.loadMonitor();
    } catch (e) {
      this.error = e.msg || e.message;
    }
  },
  methods: {
    eventName(type){return {TASK_COMPLETED:'任务完成',EXAM_PUBLISHED:'成绩公布',GRADE_CORRECTED:'成绩更正',EVALUATION_CONFIRMED:'实操鉴定',TASK_RESULT_CORRECTED:'正式结果更正',APPRAISAL_PUBLISHED:'考评公布'}[type]||'成长业务记录';},async loadMonitor(){try{this.monitor=(await growthRead('process/status')).data;}catch(e){this.error=e.msg||e.message;}},async retryProcess(){if(this.busy)return;this.busy=true;try{this.monitor=(await growthWrite('process/retry',{})).data;this.notice='已按当前正式状态执行，未完成事项保留等待下一次处理';}catch(e){this.error=e.msg||e.message;}finally{this.busy=false;}},
    async save() {
      if(this.loading||this.busy)return;
      this.busy = true;
      this.error = '';
      this.notice = '';
      try {
        await growthWrite('settings', {
          enabled: this.enabled,
          business_link_enabled: this.linked,
          templates: { ...this.templates, reminders: this.reminders, course_templates: this.courseTemplates }
        });
        this.notice = '设置已保存';
      } catch (e) {
        this.error = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    }
  }
};
</script>
<style src="./growth.css"></style>
