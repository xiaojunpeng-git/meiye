<template>
  <main class="ai-feedback-page">
    <header class="heading"><h1>魔核 AI 回答评价与问题采集</h1><button @click="load">刷新</button></header>
    <form class="filters" @submit.prevent="search">
      <label>评价<select v-model="filters.rating"><option value="">全部</option><option value="useful">有用</option><option value="not_useful">没用</option></select></label>
      <label>问题关键词<input v-model.trim="filters.keyword" maxlength="100" placeholder="搜索问题"></label>
      <button type="submit">查询</button><button type="button" @click="reset">重置</button>
    </form>
    <p v-if="error" role="alert" class="error">{{ error }}</p>
    <section class="results">
      <div class="scroll"><table><thead><tr><th>评价时间</th><th>评价</th><th>账号</th><th>问题</th><th>回答摘要</th><th>操作</th></tr></thead>
        <tbody><tr v-for="row in rows" :key="row.id"><td>{{ time(row.updated_at) }}</td><td>{{ rating(row.rating) }}</td><td>{{ terminal(row.terminal) }} #{{ row.account_id }}</td><td class="excerpt">{{ row.question }}</td><td class="excerpt">{{ row.answer_summary }}</td><td><button @click="open(row.id)">查看</button></td></tr>
          <tr v-if="!loading && rows.length === 0"><td colspan="6">暂无采集记录</td></tr></tbody></table></div>
      <footer class="pager"><span>共 {{ total }} 条 · 每页 20 条 · 第 {{ page }} / {{ pages }} 页</span><button :disabled="loading || page <= 1" @click="go(page - 1)">上一页</button><button :disabled="loading || page >= pages" @click="go(page + 1)">下一页</button></footer>
    </section>
    <div v-if="detail" class="overlay" @click.self="detail = null"><section class="detail"><header><h2>问答详情</h2><button @click="detail = null">关闭</button></header>
      <p>评价：{{ rating(detail.rating) }} · 账号：{{ terminal(detail.terminal) }} #{{ detail.account_id }} · 评价时间：{{ time(detail.updated_at) }}</p>
      <p>回答状态：{{ detail.run_status }} <span v-if="detail.run_reason">· {{ detail.run_reason }}</span> · 模型配置版本：{{ detail.model_version }}</p>
      <h3>完整问题</h3><pre>{{ detail.question }}</pre><h3>回答</h3><pre>{{ detail.answer_summary }}</pre>
      <details><summary>完整结构化答案</summary><pre>{{ JSON.stringify(detail.answer, null, 2) }}</pre></details>
    </section></div>
  </main>
</template>
<script>
import request from '@/api/moheAi';
// This page reads only explicit feedback snapshots; it never re-runs a business query.
export default {
  name: 'MoheAiFeedback',
  data() { return { filters: { rating: '', keyword: '' }, rows: [], total: 0, page: 1, pages: 1, loading: false, error: '', detail: null }; },
  mounted() { this.load(); },
  methods: {
    async load() {
      this.loading = true; this.error = '';
      try {
        const result = await request('POST', '/feedback/search', { page: this.page, rating: this.filters.rating, keyword: this.filters.keyword });
        this.rows = result.rows || []; this.total = result.total || 0; this.page = result.page || 1; this.pages = result.pages || 1;
      } catch (error) { this.error = error.message || '采集记录读取失败'; }
      finally { this.loading = false; }
    },
    search() { this.page = 1; this.load(); },
    reset() { this.filters = { rating: '', keyword: '' }; this.page = 1; this.load(); },
    go(page) { this.page = page; this.load(); },
    async open(id) { this.error = ''; try { this.detail = await request('GET', '/feedback/' + id); } catch (error) { this.error = error.message || '详情读取失败'; } },
    rating(value) { return value === 'useful' ? '有用' : '没用'; },
    terminal(value) { return { platform: '平台', store: '门店', merchant: '商家端' }[value] || value; },
    time(value) { return new Date(Number(value)).toLocaleString('zh-CN', { hour12: false }); }
  }
};
</script>
<style scoped>
.ai-feedback-page{height:100%;display:flex;flex-direction:column;gap:16px;padding:20px;box-sizing:border-box;background:#f6f8fb;color:#263449}.heading,.filters,.pager,.detail header{display:flex;align-items:center;gap:12px}.heading{justify-content:space-between}.heading h1{margin:0;font-size:22px}.filters{flex-wrap:wrap;background:#fff;padding:14px;border-radius:10px}.filters label{display:flex;align-items:center;gap:8px}.filters input,.filters select{padding:7px;border:1px solid #d8e0e9;border-radius:6px}.ai-feedback-page button{border:1px solid #d8e0e9;border-radius:6px;background:#fff;padding:7px 12px;cursor:pointer}.ai-feedback-page button:disabled{opacity:.45;cursor:default}.results{min-height:0;flex:1;display:flex;flex-direction:column;background:#fff;border-radius:10px;overflow:hidden}.scroll{min-height:0;flex:1;overflow:auto}table{width:100%;border-collapse:collapse;min-width:950px}th,td{text-align:left;padding:12px;border-bottom:1px solid #edf0f4;vertical-align:top}th{position:sticky;top:0;background:#fff}.excerpt{max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pager{justify-content:flex-end;padding:12px;border-top:1px solid #edf0f4}.error{color:#b42318}.overlay{position:fixed;inset:0;z-index:1000;background:#0007;display:flex;align-items:center;justify-content:center;padding:20px}.detail{background:#fff;border-radius:12px;padding:22px;width:min(820px,100%);max-height:90vh;overflow:auto}.detail header{justify-content:space-between}.detail h2{margin:0}.detail pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f6f8fb;padding:12px;border-radius:8px}
</style>
