<template>
  <main class="growth-page">
    <header class="growth-head">
      <div>
        <h1>岗位能力与带教师傅</h1>
        <p>复用现有岗位和员工，能力达标只来自正式实操评价。</p>
      </div>
      <div class="growth-actions">
        <button @click="$router.push('/admin/growth/practice')">带教与实操</button
        ><button @click="standardOpen = true">新增鉴定标准</button
        ><button class="growth-primary" @click="open()">新增岗位能力</button>
      </div>
    </header>
    <p v-if="error" class="growth-error">{{ error }}</p>
    <p v-if="notice" class="growth-success">{{ notice }}</p>
    <section class="growth-results">
      <div class="growth-scroll">
        <table>
          <thead>
            <tr>
              <th>岗位</th>
              <th>能力项</th>
              <th>标准说明</th>
              <th>证据要求</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.id">
              <td>{{ position(r.position_id) }}</td>
              <td>{{ r.title }}</td>
              <td style="white-space: normal">{{ r.description }}</td>
              <td>{{ Number(r.evidence_required) ? '需要有效证据' : '按检查项要求' }}</td>
              <td><button @click="open(r)">编辑</button></td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="5" class="growth-empty">尚未配置岗位能力</td>
            </tr>
          </tbody>
        </table>
        <section class="growth-panel" style="margin: 20px">
          <h2>师傅 / 内部讲师擅长内容</h2>
          <article v-for="m in mentors" :key="m.employee_id" class="growth-chapter">
            <div class="growth-actions">
              <strong>{{ employee(m.employee_id) }}</strong
              ><button @click="editMentor(m)">编辑擅长内容</button>
            </div>
            <p>{{ m.expertise }}</p>
          </article>
          <button style="margin-top: 16px" @click="editMentor()">配置现有员工为带教师傅</button>
        </section>
      </div>
      <Pager :page-data="pagination" :busy="busy" @change="load" />
    </section>
    <div v-if="form" class="growth-modal">
      <section class="growth-dialog">
        <header class="growth-dialog-head">
          <h2>{{ mode === 'mentor' ? '师傅擅长内容' : '岗位能力' }}</h2>
          <button @click="form = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="dialogError" class="growth-error">{{ dialogError }}</p>
          <template v-if="mode === 'capability'"
            ><label>现有岗位</label
            ><select v-model.number="form.position_id">
              <option :value="0">请选择</option>
              <option v-for="p in positions" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select
            ><label>能力名称</label><input v-model="form.title" /><label>标准说明</label
            ><textarea v-model="form.description" rows="4"></textarea><label>实操鉴定标准</label
            ><select v-model.number="form.standard_release_id">
              <option :value="0">按活动选择</option>
              <option v-for="s in standards" :key="s.id" :value="Number(s.release_id)">
                {{ s.title }}
              </option></select
            ><label
              ><input v-model="form.evidence_required" type="checkbox" />必须提供能力证据</label
            ></template
          ><template v-else
            ><label>现有员工</label
            ><select v-model.number="form.employee_id">
              <option :value="0">请选择</option>
              <option v-for="p in employees" :key="p.id" :value="Number(p.id)">{{ p.name }}</option></select
            ><label>擅长内容</label><textarea v-model="form.expertise" rows="4"></textarea
            ><label><input v-model="form.enabled" type="checkbox" />启用师傅安排提示</label>
            <p>配置不会授予全员档案或经营数据权限；仍只处理实际指派且在授权范围的学员。</p></template
          >
        </div>
        <footer class="growth-dialog-foot">
          <button @click="form = null">取消</button
          ><button
            class="growth-primary"
            :disabled="busy"
            data-operation="growth.capability.save"
            @click="save"
          >
            保存
          </button>
        </footer>
      </section>
    </div>
    <StandardEditor
      v-if="standardOpen"
      purpose="evaluation"
      menu="practice"
      @close="standardOpen = false"
      @saved="
        standardOpen = false;
        load(pagination.page);
      "
    />
  </main>
</template>
<script>
import { growthRead, growthWrite } from '@/api/growth';
import Pager from './Pager.vue';
import StandardEditor from './StandardEditor.vue';
/** 能力配置只引用现有岗位和检查标准；师傅资料是员工能力说明。 */
export default {
  name: 'GrowthCapabilities',
  components: { Pager, StandardEditor },
  data: () => ({
    rows: [],
    positions: [],
    standards: [],
    mentors: [],
    employees: [],
    pagination: null,
    form: null,
    mode: 'capability',
    busy: false,
    standardOpen: false,
    error: '',
    notice: '',
    dialogError: ''
  }),
  created() {
    this.load(1);
  },
  methods: {
    position(id) {
      const p = this.positions.find((x) => Number(x.id) === Number(id));
      return p ? p.name : '#' + id;
    },
    employee(id) {
      const p = this.employees.find((x) => Number(x.id) === Number(id));
      return p ? p.name : '#' + id;
    },
    async load(page) {
      try {
        const r = (await growthRead('capabilities', { page })).data;
        Object.assign(this, r);
        this.pagination = r.pagination;
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    open(r) {
      this.mode = 'capability';
      this.dialogError = '';
      this.form = r
        ? { ...r, evidence_required: !!Number(r.evidence_required) }
        : { position_id: 0, title: '', description: '', standard_release_id: 0, evidence_required: false };
    },
    editMentor(m) {
      this.mode = 'mentor';
      this.dialogError = '';
      this.form = m ? { ...m, enabled: true } : { employee_id: 0, expertise: '', enabled: true };
    },
    async save() {
      this.busy = true;
      this.dialogError = '';
      try {
        this.notice = (
          await growthWrite(this.mode === 'mentor' ? 'mentor/save' : 'capability/save', this.form)
        ).data.message;
        this.form = null;
        await this.load(this.pagination.page);
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
