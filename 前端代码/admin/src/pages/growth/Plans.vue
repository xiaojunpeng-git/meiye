<template>
  <main class="growth-page">
    <header class="growth-head">
      <div>
        <button @click="$router.push('/admin/growth/onboarding')">入职自动培养</button>
        <h1>培养计划</h1>
        <p>把新人入职、岗位提升拆成清楚可执行的成长阶段。</p>
      </div>
      <div class="growth-actions">
        <button @click="editMap()">＋ 培养地图</button
        ><button class="growth-primary" @click="newPlan">发布计划</button>
      </div>
    </header>
    <p v-if="error" class="growth-error" role="alert">{{ error }}</p>
    <p v-if="notice" class="growth-success" role="status">{{ notice }}</p>
    <nav class="growth-actions" style="justify-content: flex-start">
      <button :class="tab === 'plans' ? 'growth-primary' : ''" @click="tab = 'plans'">已发布计划</button
      ><button :class="tab === 'maps' ? 'growth-primary' : ''" @click="tab = 'maps'">培养地图</button>
    </nav>
    <section class="growth-results">
      <div class="growth-scroll">
        <table v-if="tab === 'plans'">
          <thead>
            <tr>
              <th>计划</th>
              <th>学员</th>
              <th>任务进度</th>
              <th>安排</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in plans" :key="p.id">
              <td>{{ p.title }}</td>
              <td>{{ p.employee_count }} 人</td>
              <td>{{ p.completed_tasks }} / {{ p.task_count }} 项</td>
              <td>{{ Number(p.sequential) ? '按阶段推进' : '自由完成' }}</td>
              <td>
                <button @click="openPeople(p, 'enroll')">补发学员</button
                ><button @click="openPeople(p, 'withdraw')">退出学员</button>
              </td>
            </tr>
            <tr v-if="!plans.length">
              <td colspan="5" class="growth-empty">先配置培养地图，再分配给学员。</td>
            </tr>
          </tbody>
        </table>
        <table v-else>
          <thead>
            <tr>
              <th>地图名称</th>
              <th>阶段</th>
              <th>顺序</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in maps" :key="m.id">
              <td>{{ m.title }}</td>
              <td>{{ m.draft.stages.length }} 个阶段</td>
              <td>{{ m.draft.sequential ? '按阶段' : '自由完成' }}</td>
              <td><button @click="editMap(m)">编辑并重新发布</button></td>
            </tr>
            <tr v-if="!maps.length">
              <td colspan="4" class="growth-empty">暂无培养地图</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :page-data="pagination[tab]" :busy="busy" @change="changePage" />
    </section>
    <div v-if="form" class="growth-modal">
      <section class="growth-dialog" role="dialog" aria-modal="true">
        <header class="growth-dialog-head">
          <h2>
            {{
              mode === 'map'
                ? '培养地图'
                : mode === 'plan'
                ? '发布培养计划'
                : peopleAction === 'enroll'
                ? '补发学员'
                : '退出学员'
            }}
          </h2>
          <button :disabled="busy" @click="form = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="formError" class="growth-error">{{ formError }}</p>
          <template v-if="mode === 'map'"
            ><label>地图名称</label><input v-model="form.title" /><label>用途说明</label
            ><textarea v-model="form.description" rows="2"></textarea
            ><label
              ><input v-model="form.sequential" type="checkbox" />完成前一阶段必做项，才能开始下一阶段</label
            >
            <section v-for="(stage, i) in form.stages" :key="i" class="growth-chapter">
              <div class="growth-actions">
                <strong>第 {{ i + 1 }} 阶段</strong
                ><button @click="form.stages.splice(i, 1)">移除阶段</button>
              </div>
              <label>阶段名称</label><input v-model="stage.title" />
              <div v-for="(a, j) in stage.activities" :key="j" class="growth-chapter">
                <label>活动名称</label><input v-model="a.title" />
                <div class="growth-form-grid">
                  <div>
                    <label>活动类型</label
                    ><select v-model="a.kind" @change="a.source_id = 0">
                      <option value="course">课程 / 知识</option>
                      <option value="exam">考试</option>
                      <option value="practice">练习</option>
                      <option value="assignment">作业与打卡</option>
                      <option value="evaluation">实操鉴定</option>
                      <option value="coaching">师傅带教</option>
                      <option value="offline">线下活动</option>
                    </select>
                  </div>
                  <div>
                    <label>关联已发布内容</label
                    ><select v-model.number="a.source_id">
                      <option :value="0">请选择</option>
                      <option v-for="source in sources(a.kind)" :key="source.id" :value="Number(source.id)">
                        {{ source.title }}
                      </option>
                    </select>
                  </div>
                </div>
                <div class="growth-actions">
                  <label><input v-model="a.required" type="checkbox" />本活动必做</label
                  ><button @click="stage.activities.splice(j, 1)">移除活动</button>
                </div>
              </div>
              <button style="margin-top: 12px" @click="stage.activities.push(activity())">＋ 添加活动</button>
            </section>
            <button
              style="margin-top: 14px"
              @click="form.stages.push({ title: '', activities: [activity()] })"
            >
              ＋ 添加阶段
            </button></template
          >
          <template v-else
            ><template v-if="mode === 'plan'"
              ><label>计划名称</label><input v-model="form.title" /><label>培养地图</label
              ><select v-model.number="form.map_release_id">
                <option :value="0">请选择</option>
                <option v-for="m in mapOptions" :key="m.id" :value="Number(m.release_id)">
                  {{ m.title }}
                </option></select
              ><label>负责人</label
              ><select v-model.number="form.responsible_employee_id">
                <option :value="0">请选择</option>
                <option v-for="e in employees" :key="e.id" :value="Number(e.id)">{{ e.name }}</option></select
              ><label>统一带教师傅（可不选）</label
              ><select v-model.number="form.mentor_employee_id">
                <option :value="0">暂不安排</option>
                <option v-for="e in employees" :key="e.id" :value="Number(e.id)">{{ e.name }}</option>
              </select>
              <div class="growth-form-grid">
                <div><label>开始时间</label><input v-model="starts" type="datetime-local" /></div>
                <div>
                  <label>截止时间</label><input v-model="due" type="datetime-local" />
                </div></div></template
            ><label>学员名单（{{ form.employee_ids.length }}人）</label>
            <div class="growth-persons">
              <label v-for="e in employees" :key="e.id"
                ><input v-model="form.employee_ids" type="checkbox" :value="Number(e.id)" />{{
                  e.name
                }}</label
              >
            </div>
            <label v-if="mode === 'people'">处理原因</label
            ><textarea v-if="mode === 'people'" v-model="form.reason" rows="3"></textarea>
          </template>
        </div>
        <footer class="growth-dialog-foot">
          <button :disabled="busy" @click="form = null">取消</button
          ><button class="growth-primary" :disabled="busy" @click="submit">
            {{ busy ? '处理中…' : mode === 'people' ? '确认处理' : '预览名单与要求后确认发布' }}
          </button>
        </footer>
      </section>
    </div>
  </main>
</template>
<script>
import Pager from './Pager.vue';
import { growthRead, growthWrite } from '@/api/growth';
/** 地图引用正式内容，计划固定名单；后续补发不重置原学员历史。 */
export default {
  name: 'GrowthPlans',
  components: { Pager },
  data: () => ({
    pagination: {},
    pageQuery: { maps_page: 1, plans_page: 1 },
    maps: [],
    mapOptions: [],
    plans: [],
    employees: [],
    courses: [],
    exams: [],
    activities: [],
    error: '',
    notice: '',
    formError: '',
    busy: false,
    tab: 'plans',
    form: null,
    mode: '',
    peopleAction: '',
    starts: '',
    due: ''
  }),
  created() {
    this.load();
  },
  methods: {
    changePage(page) {
      this.$set(this.pageQuery, this.tab + '_page', page);
      this.load();
    },
    async load() {
      try {
        const [r, c] = await Promise.all([
          growthRead('plans', this.pageQuery),
          growthRead('catalog', { menu: 'plans' })
        ]);
        this.pagination = r.data.pagination;
        this.mapOptions = r.data.map_options;
        this.maps = r.data.maps;
        this.plans = r.data.plans;
        this.employees = c.data.employees;
        this.courses = c.data.course_publications;
        this.exams = c.data.exam_options;
        this.activities = c.data.activity_options;
        this.error = '';
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    activity() {
      return { title: '', kind: 'course', source_id: 0, required: true };
    },
    editMap(row) {
      this.mode = 'map';
      this.formError = '';
      this.form = row
        ? { ...JSON.parse(JSON.stringify(row.draft)), id: row.id }
        : {
            title: '',
            description: '',
            sequential: true,
            stages: [{ title: '', activities: [this.activity()] }]
          };
    },
    newPlan() {
      this.mode = 'plan';
      this.formError = '';
      this.starts = '';
      this.due = '';
      this.form = {
        title: '',
        map_release_id: 0,
        responsible_employee_id: 0,
        mentor_employee_id: 0,
        employee_ids: []
      };
    },
    openPeople(p, action) {
      this.mode = 'people';
      this.peopleAction = action;
      this.formError = '';
      this.form = { plan_id: p.id, action, employee_ids: [], reason: '' };
    },
    sources(kind) {
      return kind === 'course'
        ? this.courses
        : [...this.exams, ...this.activities].filter((e) => e.kind === kind);
    },
    async submit() {
      this.busy = true;
      this.formError = '';
      try {
        let payload = { ...this.form };
        let endpoint =
          this.mode === 'map' ? 'map/publish' : this.mode === 'plan' ? 'plan/publish' : 'plan/people';
        if (this.mode === 'plan') {
          payload.starts_at = this.starts ? Math.floor(new Date(this.starts).getTime() / 1000) : 0;
          payload.due_at = this.due ? Math.floor(new Date(this.due).getTime() / 1000) : 0;
        }
        const r = await growthWrite(endpoint, payload);
        this.notice = r.data.message;
        this.form = null;
        await this.load();
      } catch (e) {
        this.formError = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    }
  }
};
</script>
<style src="./growth.css"></style>
