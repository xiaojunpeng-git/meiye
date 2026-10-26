<template>
  <main class="growth-page">
    <header class="growth-head">
      <div>
        <h1>成长答疑</h1>
        <p>由指派负责人解答，转交与知识整理保留原问题。</p>
      </div>
      <button @click="$router.push('/admin/growth/practice')">返回带教与实操</button>
    </header>
    <p v-if="error" class="growth-error">{{ error }}</p>
    <p v-if="notice" class="growth-success">{{ notice }}</p>
    <section class="growth-results">
      <div class="growth-scroll">
        <article v-for="q in rows" :key="q.id" class="growth-panel" style="margin: 16px">
          <h2>{{ q.body }}</h2>
          <p>
            提问人 {{ person(q.employee_id) }} · 负责人 {{ person(q.handler_employee_id) }} ·
            {{ q.state === 'OPEN' ? '等待答复' : q.state === 'CLOSED' ? '已完成' : '已有回复' }}
          </p>
          <div v-for="r in q.replies" :key="r.id" class="growth-chapter">
            <p>{{ person(r.employee_id) }}：{{ r.body }}</p>
            <p v-if="Number(r.transfer_to)">转交给 {{ person(r.transfer_to) }}</p>
          </div>
          <template v-if="Number(q.handler_employee_id) === operator && q.state !== 'CLOSED'"
            ><label>解答 / 转交说明</label><textarea v-model="answers[q.id]" rows="3"></textarea
            ><label>转交负责人（不转交时留空）</label
            ><select v-model.number="transfers[q.id]">
              <option :value="0">本次直接答复</option>
              <option v-for="p in people" :key="p.id" :value="Number(p.id)">{{ p.name }}</option>
            </select>
            <div class="growth-actions" style="margin-top: 12px">
              <button :disabled="busy" data-operation="growth.help.reply" @click="reply(q, false)">
                保存回复 / 转交</button
              ><button :disabled="busy" data-operation="growth.help.close" @click="reply(q, true)">
                回复并结束答疑
              </button>
            </div></template
          ><button
            style="margin-top: 15px"
            :disabled="busy || Number(q.knowledge_content_id) > 0"
            data-operation="growth.help.promote"
            @click="promote(q)"
          >
            {{ Number(q.knowledge_content_id) > 0 ? '已整理为知识草稿' : '整理为知识草稿' }}
          </button>
        </article>
        <p v-if="!rows.length" class="growth-empty">暂无答疑记录</p>
      </div>
      <footer class="growth-pager">
        <span>共 {{ total }} 条 · 每页 20 条 · 第 {{ page }} / {{ pages }} 页</span
        ><button
          :disabled="page <= 1"
          @click="
            page--;
            load();
          "
        >
          上一页</button
        ><button
          :disabled="page >= pages"
          @click="
            page++;
            load();
          "
        >
          下一页
        </button>
      </footer>
    </section>
  </main>
</template>
<script>
import { growthRead, growthWrite } from '@/api/growth';
/** 负责人处理在服务端核验，菜单内通用动作仅设置操作锚点。 */
export default {
  name: 'GrowthHelp',
  data: () => ({
    rows: [],
    people: [],
    operator: 0,
    page: 1,
    pages: 1,
    total: 0,
    answers: {},
    transfers: {},
    error: '',
    notice: '',
    busy: false
  }),
  created() {
    this.load();
  },
  methods: {
    person(id) {
      const p = this.people.find((x) => Number(x.id) === Number(id));
      return p ? p.name : '#' + id;
    },
    async load() {
      try {
        const r = (await growthRead('help', { page: this.page })).data;
        this.rows = r.rows;
        this.people = r.employees;
        this.operator = Number(r.operator_employee_id);
        this.total = r.total;
        this.page = r.page;
        this.pages = r.total_pages;
        r.rows.forEach((q) => {
          if (!(q.id in this.answers)) this.$set(this.answers, q.id, '');
          if (!(q.id in this.transfers)) this.$set(this.transfers, q.id, 0);
        });
      } catch (e) {
        this.error = e.msg || e.message;
      }
    },
    async reply(q, close) {
      this.busy = true;
      this.error = '';
      try {
        const r = await growthWrite('help/reply', {
          id: q.id,
          body: this.answers[q.id],
          transfer_to: this.transfers[q.id],
          close
        });
        this.notice = r.data.message;
        this.$set(this.answers, q.id, '');
        await this.load();
      } catch (e) {
        this.error = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    },
    async promote(q) {
      const title = window.prompt('知识标题');
      if (!title) return;
      const body = window.prompt(
        '整理后的专业解答',
        q.replies
          .filter((r) => !Number(r.transfer_to))
          .map((r) => r.body)
          .join('\n')
      );
      if (!body) return;
      this.busy = true;
      try {
        this.notice = (
          await growthWrite('help/promote', { question_id: q.id, title, body, category: '人工答疑' })
        ).data.message;
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
<style src="./growth.css"></style>
