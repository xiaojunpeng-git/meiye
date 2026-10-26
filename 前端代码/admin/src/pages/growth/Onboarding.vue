<template>
  <main class="growth-page">
    <header class="growth-head">
      <div>
        <h1>入职自动培养</h1>
        <p>只匹配规则保存后的首次正式任职。老员工请从培养计划明确补发。</p>
      </div>
      <div class="growth-actions">
        <button @click="$router.push('/admin/growth/plans')">培养计划</button
        ><button class="growth-primary" @click="open()">新增规则</button>
      </div>
    </header>
    <p v-if="error" class="growth-error">{{ error }}</p>
    <p v-if="notice" class="growth-success">{{ notice }}</p>
    <nav class="growth-actions" style="justify-content: flex-start">
      <button @click="tab = 'rules'">自动派发规则</button
      ><button @click="tab = 'dispatches'">派发与失败记录</button>
    </nav>
    <section class="growth-results">
      <div class="growth-scroll">
        <table v-if="tab === 'rules'">
          <thead>
            <tr>
              <th>规则</th>
              <th>范围</th>
              <th>截止期限</th>
              <th>状态</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rules" :key="r.id">
              <td>{{ r.title }}</td>
              <td>
                {{ scopeName('organizations', r.organization_id) }} / {{ scopeName('stores', r.store_id) }} /
                {{ scopeName('positions', r.position_id) }}
              </td>
              <td>派发后 {{ r.relative_days }} 天</td>
              <td>{{ Number(r.enabled) ? '启用' : '停用' }}</td>
              <td><button @click="open(r)">编辑</button></td>
            </tr>
          </tbody>
        </table>
        <table v-else>
          <thead>
            <tr>
              <th>员工</th>
              <th>正式来源</th>
              <th>状态</th>
              <th>培养计划</th>
              <th>失败原因</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in dispatches" :key="r.id">
              <td>{{ person(r.employee_id) }}</td>
              <td>{{ r.source_event_key }}</td>
              <td>
                {{
                  r.state === 'DONE' ? '已派发' : r.state === 'SCHEDULED' ? '等待任职生效' : '等待修复后重试'
                }}
              </td>
              <td>{{ r.plan_id || '—' }}</td>
              <td>{{ r.failure_reason || '—' }}</td>
            </tr>
            <tr v-if="!dispatches.length">
              <td colspan="5" class="growth-empty">尚无规则保存后的新入职记录</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :page-data="pagination[tab]" @change="changePage" />
    </section>
    <div v-if="form" class="growth-modal">
      <section class="growth-dialog">
        <header class="growth-dialog-head">
          <h2>自动派发规则</h2>
          <button @click="form = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="dialogError" class="growth-error">{{ dialogError }}</p>
          <label>规则名称</label><input v-model="form.title" /><label
            ><input v-model="form.enabled" type="checkbox" />启用本规则</label
          ><label v-for="(name, type) in labels" :key="type"
            >{{ name
            }}<select v-model.number="form[fields[type]]">
              <option :value="0">当前授权范围内全部</option>
              <option v-for="r in catalog[type]" :key="r.id" :value="Number(r.id)">{{ r.name }}</option>
            </select></label
          ><label>已发布培养地图</label
          ><select v-model.number="form.map_release_id">
            <option :value="0">请选择</option>
            <option v-for="m in maps" :key="m.id" :value="Number(m.release_id)">{{ m.title }}</option></select
          ><label>负责人</label
          ><select v-model.number="form.responsible_employee_id">
            <option :value="0">请选择</option>
            <option v-for="p in people" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select
          ><label>派发后期限（天）</label
          ><input v-model.number="form.relative_days" type="number" min="1" max="3650" />
          <p>保存后从当前任职水位开始，不追溯补发老员工；停用不撤销已有任务。</p>
        </div>
        <footer class="growth-dialog-foot">
          <button @click="form = null">取消</button
          ><button
            class="growth-primary"
            :disabled="busy"
            data-operation="growth.onboarding.save"
            @click="save"
          >
            保存规则
          </button>
        </footer>
      </section>
    </div>
  </main>
</template>
<script>
import Pager from './Pager.vue';
import { growthRead, growthWrite } from '@/api/growth';
/** 自动规则绑定已发布地图及真实组织岗位，后台只消费正式任职事实。 */
export default {
  name: 'GrowthOnboarding',
  components: { Pager },
  data: () => ({
    tab: 'rules',
    pagination: {},
    pageQuery: { rules_page: 1, dispatches_page: 1 },
    rules: [],
    dispatches: [],
    catalog: { organizations: [], stores: [], positions: [] },
    people: [],
    maps: [],
    form: null,
    error: '',
    dialogError: '',
    notice: '',
    busy: false,
    labels: { organizations: '组织', stores: '门店', positions: '岗位' },
    fields: { organizations: 'organization_id', stores: 'store_id', positions: 'position_id' }
  }),
  created() {
    this.load();
  },
  methods: {
    changePage(page) {
      this.$set(this.pageQuery, this.tab + '_page', page);
      this.load();
    },
    scopeName(t, id) {
      if (!Number(id)) return '全部';
      const r = this.catalog[t].find((x) => Number(x.id) === Number(id));
      return r ? r.name : '#' + id;
    },
    person(id) {
      const p = this.people.find((x) => Number(x.id) === Number(id));
      return p ? p.name : '#' + id;
    },
    async load() {
      try {
        const [r, c, p] = await Promise.all([
          growthRead('onboarding', this.pageQuery),
          growthRead('catalog', { menu: 'plans' }),
          growthRead('plans')
        ]);
        this.pagination = r.data.pagination;
        this.rules = r.data.rules;
        this.dispatches = r.data.dispatches;
        this.catalog = r.data;
        this.people = c.data.employees;
        this.maps = p.data.map_options;
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    open(r) {
      this.dialogError = '';
      this.form = r
        ? { ...r, enabled: !!Number(r.enabled) }
        : {
            title: '',
            enabled: false,
            organization_id: 0,
            store_id: 0,
            position_id: 0,
            map_release_id: 0,
            responsible_employee_id: 0,
            relative_days: 30
          };
    },
    async save() {
      this.busy = true;
      this.dialogError = '';
      try {
        this.notice = (await growthWrite('onboarding/save', this.form)).data.message;
        this.form = null;
        await this.load();
      } catch (e) {
        this.dialogError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    }
  }
};
</script>
<style src="./growth.css"></style>
