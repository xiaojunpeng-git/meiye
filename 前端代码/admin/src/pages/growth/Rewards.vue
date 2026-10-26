<template>
  <section class="growth-panel">
    <h2>积分与证书规则</h2>
    <p>规则仅用于新派发任务；正式结果触发发放，重试不重复奖励。原规则停用后历史任务仍按原口径处理。</p>
    <p v-if="error" class="growth-error">{{ error }}</p>
    <p v-if="notice" class="growth-success">{{ notice }}</p>
    <div class="growth-actions">
      <button @click="open('points')">新增积分规则</button
      ><button @click="open('certificate')">新增证书规则</button>
    </div>
    <div class="growth-table-scroll">
      <table class="growth-table">
        <thead>
          <tr>
            <th>名称</th>
            <th>类型</th>
            <th>来源</th>
            <th>奖励内容</th>
            <th>状态</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rules" :key="r.kind + r.id">
            <td>{{ r.title }}</td>
            <td>{{ r.kind === 'points' ? '积分' : '证书' }}</td>
            <td>{{ events[r.event_type] }}</td>
            <td>
              {{
                r.kind === 'points' ? r.points + ' 积分' : r.valid_days ? r.valid_days + ' 天' : '长期有效'
              }}
            </td>
            <td>{{ r.enabled ? '新任务启用' : '新任务停用' }}</td>
            <td><button data-operation="growth.reward.edit" @click="edit(r)">编辑规则</button></td>
          </tr>
          <tr v-if="!rules.length">
            <td colspan="6">尚未配置奖励规则</td>
          </tr>
        </tbody>
      </table>
    </div>
    <h2>成长等级</h2>
    <p>按员工有效积分确定等级；等级不会修改岗位或薪资。</p>
    <div v-for="(r, i) in levels" :key="i" class="growth-actions">
      <input v-model="r.title" placeholder="等级名称" /><input
        v-model.number="r.threshold"
        type="number"
        min="0"
        placeholder="积分门槛"
      /><button @click="levels.splice(i, 1)">移除</button>
    </div>
    <div class="growth-actions">
      <button @click="levels.push({ title: '', threshold: 0 })">添加等级</button
      ><button
        class="growth-primary"
        :disabled="busy"
        data-operation="growth.levels.save"
        @click="saveLevels"
      >
        保存等级
      </button>
    </div>
    <div v-if="form" class="growth-modal">
      <section class="growth-dialog">
        <div class="growth-dialog-body">
          <h2>{{ form.kind === 'points' ? '积分规则' : '证书规则' }}</h2>
          <label>名称</label><input v-model="form.title" /><label>触发来源</label
          ><select v-model="form.event_type">
            <option v-for="(name, id) in events" :key="id" :value="id">{{ name }}</option></select
          ><label><input v-model="form.enabled" type="checkbox" />新任务启用</label
          ><label><input v-model="form.require_passed" type="checkbox" />仅通过或合格才发放</label
          ><template v-if="form.kind === 'points'"
            ><label>奖励积分（整数）</label><input v-model.number="form.points" type="number" min="1" /><label
              ><input v-model="form.first_only" type="checkbox" />同一来源仅首次奖励</label
            ><label v-if="!form.first_only">同一来源最多奖励次数</label
            ><input
              v-if="!form.first_only"
              v-model.number="form.max_awards"
              type="number"
              min="1"
              max="100" /></template
          ><template v-else
            ><label>有效天数（0 为长期）</label
            ><input v-model.number="form.valid_days" type="number" min="0" /><label>到期复核负责人</label
            ><select v-model.number="form.responsible_employee_id">
              <option :value="0">请选择</option>
              <option v-for="p in employees" :key="p.id" :value="Number(p.id)">{{ p.name }}</option>
            </select></template
          >
          <div class="growth-actions">
            <button @click="form = null">取消</button
            ><button
              class="growth-primary"
              :disabled="busy"
              data-operation="growth.reward.save"
              @click="save"
            >
              保存规则
            </button>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>
<script>
import { growthRead, growthWrite } from '@/api/growth';
/** 设置只管理未来派发口径；编辑新增规则并停用旧规则，已派发任务快照保留。 */
export default {
  name: 'GrowthRewards',
  data: () => ({
    rules: [],
    levels: [],
    employees: [],
    form: null,
    error: '',
    notice: '',
    busy: false,
    events: {
      TASK_COMPLETED: '任务完成',
      EXAM_PUBLISHED: '练考成绩公布',
      EVALUATION_CONFIRMED: '实操鉴定确认'
    }
  }),
  created() {
    this.load();
  },
  methods: {
    async load() {
      try {
        const [r, c] = await Promise.all([
          growthRead('rewards'),
          growthRead('catalog', { menu: 'settings' })
        ]);
        this.rules = [
          ...r.data.point_rules.map((x) => ({ ...x, kind: 'points' })),
          ...r.data.certificate_rules.map((x) => ({ ...x, kind: 'certificate' }))
        ];
        this.levels = r.data.levels.filter((x) => Number(x.enabled)).map((x) => ({ ...x }));
        this.employees = c.data.employees;
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    open(kind) {
      this.form = {
        kind,
        title: '',
        event_type: kind === 'points' ? 'TASK_COMPLETED' : 'EXAM_PUBLISHED',
        enabled: true,
        require_passed: kind === 'certificate',
        points: 10,
        first_only: true,
        max_awards: 1,
        valid_days: 365,
        responsible_employee_id: 0
      };
    },
    edit(r) {
      this.form = {
        ...r,
        enabled: !!Number(r.enabled),
        require_passed: !!Number(r.require_passed),
        first_only: !!Number(r.first_only)
      };
    },
    async save() {
      this.busy = true;
      this.error = '';
      try {
        const r = await growthWrite('reward/rule', this.form);
        this.notice = r.data.message;
        this.form = null;
        await this.load();
      } catch (e) {
        this.error = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    async saveLevels() {
      this.busy = true;
      this.error = '';
      try {
        await growthWrite('reward/levels', { levels: this.levels });
        this.notice = '等级已保存';
        await this.load();
      } catch (e) {
        this.error = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    }
  }
};
</script>
