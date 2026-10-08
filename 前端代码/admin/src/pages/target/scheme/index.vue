<template>
  <main class="target-scheme-list">
    <header class="target-scheme-list__header">
      <div><h1>目标方案</h1><p>查看已建方案，进入独立页面新增或编辑月目标。</p></div>
      <button type="button" class="target-scheme-list__primary" @click="create">新增方案</button>
    </header>
    <p v-if="error" role="alert" class="target-scheme-list__error">{{ error }}</p>
    <section class="target-scheme-list__card">
      <table>
        <thead><tr><th>方案名称</th><th>说明</th><th>状态</th><th>目标明细</th><th>版本</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="scheme in schemes" :key="scheme.id">
            <td>{{ scheme.name }}</td><td>{{ scheme.description || '—' }}</td>
            <td><span :class="['status', scheme.status === 'active' ? 'status--active' : '']">{{ scheme.status === 'active' ? '已生效' : '草稿' }}</span></td>
            <td>{{ scheme.line_count }} 条</td><td>{{ scheme.version }}</td>
            <td><button type="button" @click="edit(scheme.id)">{{ scheme.status === 'active' ? '查看' : '编辑' }}</button></td>
          </tr>
          <tr v-if="!schemes.length"><td colspan="6" class="target-scheme-list__empty">暂无目标方案，可点击右上角新增。</td></tr>
        </tbody>
      </table>
    </section>
  </main>
</template>

<script>
import { schemeList } from '@/api/target';

/** 列表只做导航和概览；新增与编辑在独立路由，避免草稿操作混入列表。 */
export default {
  name: 'TargetSchemeListPage',
  data() { return { schemes: [], error: '' }; },
  async created() {
    try { const response = await schemeList(); this.schemes = response.data || []; } catch (error) { this.error = error.msg || error.message || '目标方案加载失败'; }
  },
  methods: {
    create() { this.$router.push({ name: 'target_scheme_create' }); },
    edit(id) { this.$router.push({ name: 'target_scheme_edit', params: { id }}); }
  }
};
</script>

<style scoped>
.target-scheme-list { padding: 20px; color: #24344b; }
.target-scheme-list__header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 18px; }
.target-scheme-list__header h1 { font-size: 22px; margin: 0 0 5px; }
.target-scheme-list__header p { margin: 0; color: #718198; }
.target-scheme-list__card { background: #fff; border: 1px solid #e4eaf2; border-radius: 10px; overflow: auto; }
table { width: 100%; border-collapse: collapse; min-width: 760px; }
th, td { padding: 14px; text-align: left; border-bottom: 1px solid #edf1f5; }
th { background: #f5f8fc; color: #5f7085; }
tr:last-child td { border-bottom: 0; }
.status { display: inline-block; padding: 3px 9px; border-radius: 20px; background: #f0f3f7; }
.status--active { background: #e5f6ee; color: #178551; }
.target-scheme-list__empty { text-align: center; color: #8190a3; }
.target-scheme-list__error { color: #b33a35; background: #fff1ef; padding: 10px; border-radius: 5px; }
button { border: 1px solid #c9d8e9; border-radius: 5px; background: #f7faff; color: #175ba0; cursor: pointer; padding: 7px 12px; }
.target-scheme-list__primary { background: #2377d4; border-color: #2377d4; color: #fff; }
</style>
