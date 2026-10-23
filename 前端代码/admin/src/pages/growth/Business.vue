<template>
  <main class="growth-page">
    <header class="growth-head">
      <h1>经营观察与复盘</h1>
      <button @click="$router.push('/admin/growth/overview')">成长工作台</button>
    </header>
    <p v-if="error" class="growth-error">{{ error }}</p>
    <p v-if="notice" class="growth-success">{{ notice }}</p>
    <section class="growth-business-scroll">
      <section class="growth-panel">
        <h2>经营指标联看</h2>
        <p>只读现有经营事实，员工与门店使用各自的计入口径。</p>
        <div class="growth-form-grid">
          <div>
            <label>观察对象</label
            ><select v-model="objectType" @change="objectId = 0">
              <option value="employee">员工</option>
              <option value="store">门店</option>
            </select>
          </div>
          <div>
            <label>{{ objectType === 'employee' ? '员工' : '门店' }}</label
            ><select v-model.number="objectId">
              <option :value="0">请选择</option>
              <option v-for="p in objects" :key="p.id" :value="Number(p.id)">{{ p.name }}</option>
            </select>
          </div>
          <div><label>统计起始</label><input v-model="start" type="date" /></div>
          <div><label>统计截止</label><input v-model="end" type="date" /></div>
        </div>
        <button class="growth-primary" style="margin-top: 18px" :disabled="busy" @click="observe">
          查看经营指标
        </button>
        <p v-if="business && !business.enabled" style="margin-top: 18px">
          当前以独立成长模式运行；经营指标未启用，可到成长设置开启联看。
        </p>
        <p v-if="business && business.unavailable_reason">{{ business.unavailable_reason }}</p>
        <div v-if="business && business.enabled" class="growth-business-cards">
          <button v-for="m in business.metrics" :key="m.code" @click="metric = m">
            <span>{{ m.title }}</span
            ><strong
              >{{ m.display_value }} <small>{{ m.unit }}</small></strong
            >
            <p>查看日明细与取值来源 →</p>
          </button>
        </div>
        <p v-if="business">{{ business.notice }}</p>
      </section>
      <section class="growth-panel">
        <div class="growth-actions">
          <h2>持续复盘</h2>
          <button @click="newReview">新建复盘</button>
        </div>
        <article v-for="r in reviews" :key="r.id" class="growth-chapter">
          <div class="growth-actions">
            <h3>{{ r.title }}</h3>
            <button @click="editReview(r)">查看 / 编辑</button>
          </div>
          <p>
            基准 {{ r.baseline_start }} 至 {{ r.baseline_end }} · 后续 {{ r.followup_start }} 至
            {{ r.followup_end }}
          </p>
          <p>{{ r.judgment }}</p>
          <p>下一步：{{ r.next_steps }}</p>
          <div class="growth-business-comparison">
            <div>
              <strong>基准周期</strong>
              <p v-for="m in r.snapshot.baseline.metrics" :key="m.code">
                {{ m.title }} {{ m.display_value }} {{ m.unit }}
              </p>
            </div>
            <div>
              <strong>后续周期</strong>
              <p v-for="m in r.snapshot.followup.metrics" :key="m.code">
                {{ m.title }} {{ m.display_value }} {{ m.unit }}
              </p>
            </div>
          </div>
          <p>{{ r.snapshot.notice }}</p>
        </article>
        <p v-if="!reviews.length">暂无复盘记录。可以记录背景、管理判断与后续行动。</p>
        <footer class="growth-pager">
          <span>共 {{ total }} 条 · 每页 20 条 · 第 {{ page }} / {{ pages }} 页</span
          ><button
            :disabled="page <= 1"
            @click="
              page--;
              loadReviews();
            "
          >
            上一页</button
          ><button
            :disabled="page >= pages"
            @click="
              page++;
              loadReviews();
            "
          >
            下一页
          </button>
        </footer>
      </section>
    </section>
    <div v-if="metric" class="growth-modal">
      <section class="growth-dialog">
        <header class="growth-dialog-head">
          <h2>{{ metric.title }} · 日明细</h2>
          <button @click="metric = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p>{{ metric.value_source }}</p>
          <table>
            <thead>
              <tr>
                <th>业务日期</th>
                <th>{{ metric.title }}（{{ metric.unit }}）</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in metric.daily" :key="d.business_date">
                <td>{{ d.business_date }}</td>
                <td>{{ d.display_value }}</td>
              </tr>
              <tr v-if="!metric.daily.length">
                <td colspan="2">该周期无有效事实</td>
              </tr>
            </tbody>
          </table>
          <p>当前金额页面按整数元展示，来源事实保持原精度。</p>
        </div>
      </section>
    </div>
    <div v-if="form" class="growth-modal">
      <section class="growth-dialog">
        <header class="growth-dialog-head">
          <h2>成长复盘</h2>
          <button @click="form = null">关闭</button>
        </header>
        <div class="growth-dialog-body">
          <p v-if="dialogError" class="growth-error">{{ dialogError }}</p>
          <label>复盘标题</label><input v-model="form.title" />
          <div class="growth-form-grid">
            <div>
              <label>对象类型</label
              ><select v-model="form.object_type" @change="form.object_id = 0;form.metrics=[]">
                <option value="employee">员工</option>
                <option value="store">门店</option>
              </select>
            </div>
            <div>
              <label>关注对象</label
              ><select v-model.number="form.object_id">
                <option :value="0">请选择</option>
                <option
                  v-for="p in form.object_type === 'employee' ? catalog.employees : catalog.stores"
                  :key="p.id"
                  :value="Number(p.id)"
                >
                  {{ p.name }}
                </option>
              </select>
            </div>
            <div v-for="(title, key) in dateLabels" :key="key">
              <label>{{ title }}</label
              ><input v-model="form[key]" type="date" />
            </div>
          </div>
          <label>关注指标（未选择时只记录复盘文字）</label><div class="growth-actions"><label v-for="(name,key) in reviewMetrics" :key="key"><input type="checkbox" :value="key" v-model="form.metrics" />{{ name }}</label></div><div class="growth-form-grid"><label>关联培养计划<select v-model.number="form.plan_id"><option :value="0">不关联</option><option v-for="r in references.plans" :key="r.id" :value="Number(r.id)">{{ r.title }}</option></select></label><label>关联问题<select v-model.number="form.issue_id"><option :value="0">不关联</option><option v-for="r in references.issues" :key="r.id" :value="Number(r.id)">{{ r.store_name }} · {{ r.description }}</option></select></label><label>后续行动任务<select v-model.number="form.followup_task_id"><option :value="0">暂不指定</option><option v-for="r in references.tasks" :key="r.id" :value="Number(r.id)">{{ r.employee_name }} · {{ r.title }}</option></select></label></div>
          <label>背景说明（调店、在岗、排班、活动、服务机会等）</label
          ><textarea v-model="form.background" rows="3"></textarea><label>管理者判断</label
          ><textarea v-model="form.judgment" rows="3"></textarea><label>下一步行动与负责人 / 期限</label
          ><textarea v-model="form.next_steps" rows="3"></textarea>
          <p>保存时按原统一口径读取两个周期的经营指标；不启用联看时保留独立成长复盘。</p>
        </div>
        <footer class="growth-dialog-foot">
          <button @click="form = null">取消</button
          ><button
            class="growth-primary"
            :disabled="busy"
            data-operation="growth.review.save"
            @click="saveReview"
          >
            保存复盘
          </button>
        </footer>
      </section>
    </div>
  </main>
</template>
<script>
import { growthDate, growthRead, growthWrite } from '@/api/growth';
/** 经营联看只展示服务器已计算读数，复盘文字独立保存且刷新可恢复。 */
export default {
  name: 'GrowthBusiness',
  data: () => ({
    catalog: { employees: [], stores: [] },
    objectType: 'employee',
    objectId: 0,
    start: growthDate().slice(0, 7) + '-01',
    end: growthDate(),
    business: null,
    metric: null, references:{plans:[],issues:[],tasks:[]},
    reviews: [],
    page: 1,
    pages: 1,
    total: 0,
    form: null,
    error: '',
    dialogError: '',
    notice: '',
    busy: false,
    dateLabels: {
      baseline_start: '基准周期起始',
      baseline_end: '基准周期截止',
      followup_start: '后续周期起始',
      followup_end: '后续周期截止'
    }
  }),
  computed: {
    reviewMetrics(){if(!this.form)return {};return this.form.object_type==='employee'?{staff_sales_yeji:'销售业绩',staff_labor_yeji:'劳动业绩',staff_service_num:'服务人次'}:{staff_sales_yeji:'销售业绩',consume_amount:'消耗业绩',store_customer_visit:'客流'};},
    objects() {
      return this.objectType === 'employee' ? this.catalog.employees : this.catalog.stores;
    }
  },
  async created() {
    try {
      this.catalog = (await growthRead('report/filters')).data;
      this.start = this.catalog.date_defaults.start;
      this.end = this.catalog.date_defaults.end;
      this.references=(await growthRead('review/catalog')).data;
      await this.loadReviews();
    } catch (e) {
      this.error = e.msg || e.message;
    }
  },
  methods: {
    async observe() {
      this.busy = true;
      this.error = '';
      try {
        this.business = (
          await growthRead('business', {
            object_type: this.objectType,
            object_id: this.objectId,
            start: this.start,
            end: this.end
          })
        ).data;
      } catch (e) {
        this.error = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    async loadReviews() {
      try {
        const r = (await growthRead('reviews', { page: this.page })).data;
        this.reviews = r.rows;
        this.page = r.page;
        this.pages = r.total_pages;
        this.total = r.total;
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    newReview() {
      const now = new Date();
      const month = new Date(now.getFullYear(), now.getMonth(), 1);
      const beforeEnd = new Date(month.getTime() - 86400000);
      const beforeStart = new Date(beforeEnd.getFullYear(), beforeEnd.getMonth(), 1);
      this.dialogError = '';
      this.form = {
        title: '',
        object_type: this.objectType,
        object_id: this.objectId,
        baseline_start: beforeStart.toLocaleDateString('sv-SE'),
        baseline_end: beforeEnd.toLocaleDateString('sv-SE'),
        followup_start: this.start,
        followup_end: this.end,
        background: '',
        judgment: '',
        next_steps: '',
        metrics: [], plan_id:0, issue_id:0, followup_task_id:0
      };
    },
    editReview(r) {
      this.dialogError = '';
      this.form = { ...r };
    },
    async saveReview() {
      this.busy = true;
      this.dialogError = '';
      try {
        this.notice = (await growthWrite('review/save', this.form)).data.message;
        this.form = null;
        await this.loadReviews();
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
<style scoped>
.growth-business-scroll {
  flex: 1;
  min-height: 0;
  overflow: auto;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.growth-business-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 14px;
  margin: 20px 0;
}
.growth-business-cards button {
  padding: 20px;
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 14px;
  background: #f9fbf6;
}
.growth-business-cards strong {
  font-size: 28px;
}
.growth-business-cards small {
  font-size: 12px;
  font-weight: 400;
  color: #8e9a81;
}
.growth-business-cards p {
  font-size: 12px;
}
.growth-business-comparison {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  padding: 16px 0;
}
</style>
