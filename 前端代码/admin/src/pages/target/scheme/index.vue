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
            <td><span :class="['status', scheme.status === 'active' ? 'status--active' : '', scheme.status === 'void' ? 'status--void' : '']">{{ statusLabel(scheme.status) }}</span></td>
            <td>{{ scheme.line_count }} 条</td><td>{{ scheme.version }}</td>
            <td class="target-scheme-list__actions"><button type="button" @click="edit(scheme.id)">{{ scheme.status === 'draft' ? '编辑' : '查看' }}</button><button v-if="scheme.status !== 'void'" type="button" class="target-scheme-list__danger" @click="openVoid(scheme)">作废</button></td>
          </tr>
          <tr v-if="!schemes.length"><td colspan="6" class="target-scheme-list__empty">暂无目标方案，可点击右上角新增。</td></tr>
        </tbody>
      </table>
    </section>
    <div v-if="voidTarget" class="target-scheme-list__backdrop" @click.self="closeVoid">
      <section class="target-scheme-list__dialog" role="dialog" aria-modal="true" aria-labelledby="target-scheme-void-title">
        <h2 id="target-scheme-void-title">作废目标方案</h2>
        <p>确认作废“{{ voidTarget.name }}”？作废后，引用此方案的报表将不再显示其目标和完成率，直到启用其他方案。方案及明细仍可查看。</p>
        <label for="target-scheme-void-reason">作废原因</label>
        <textarea id="target-scheme-void-reason" v-model.trim="voidReason" maxlength="200" rows="3" placeholder="请填写作废原因"></textarea>
        <p v-if="voidError" role="alert" class="target-scheme-list__error">{{ voidError }}</p>
        <div class="target-scheme-list__dialog-actions"><button type="button" :disabled="busy" @click="closeVoid">取消</button><button type="button" class="target-scheme-list__danger-primary" :disabled="busy || !voidReason" @click="confirmVoid">{{ busy ? '作废中…' : '确认作废' }}</button></div>
      </section>
    </div>
  </main>
</template>

<script>
import { schemeList, schemeVoid } from '@/api/target';

const randomKey = () => (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);

/** 列表展示方案状态与作废入口；目标明细的编辑仍在独立路由。 */
export default {
  name: 'TargetSchemeListPage',
  data() { return { schemes: [], error: '', voidTarget: null, voidReason: '', voidError: '', busy: false }; },
  async created() { await this.loadSchemes(); },
  methods: {
    async loadSchemes() {
      try { const response = await schemeList(); this.schemes = response.data || []; this.error = ''; }
      catch (error) { this.error = error.msg || error.message || '目标方案加载失败'; }
    },
    statusLabel(status) { return status === 'active' ? '已生效' : (status === 'void' ? '已作废' : '草稿'); },
    create() { this.$router.push({ name: 'target_scheme_create' }); },
    edit(id) { this.$router.push({ name: 'target_scheme_edit', params: { id }}); },
    openVoid(scheme) { this.voidTarget = scheme; this.voidReason = ''; this.voidError = ''; },
    closeVoid() { if (!this.busy) { this.voidTarget = null; this.voidReason = ''; this.voidError = ''; } },
    /** 提交当前列表版本，服务端再锁定方案和绑定；失败时保留原因供用户修正或刷新。 */
    async confirmVoid() {
      if (!this.voidTarget || this.busy) return;
      this.busy = true; this.voidError = '';
      try {
        await schemeVoid({ scheme_id: Number(this.voidTarget.id), expected_version: Number(this.voidTarget.version), reason: this.voidReason, idempotency_key: randomKey() });
        this.voidTarget = null; this.voidReason = '';
        await this.loadSchemes();
      } catch (error) { this.voidError = error.msg || error.message || '作废目标方案失败'; }
      finally { this.busy = false; }
    }
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
.status--void { background: #fff0ed; color: #a74839; }
.target-scheme-list__empty { text-align: center; color: #8190a3; }
.target-scheme-list__error { color: #b33a35; background: #fff1ef; padding: 10px; border-radius: 5px; }
.target-scheme-list__actions { white-space: nowrap; }
.target-scheme-list__actions button + button { margin-left: 8px; }
button { border: 1px solid #c9d8e9; border-radius: 5px; background: #f7faff; color: #175ba0; cursor: pointer; padding: 7px 12px; }
.target-scheme-list__danger { color: #b33a35; border-color: #edc5bd; background: #fff8f6; }
.target-scheme-list__backdrop { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 20px; background: rgba(20, 34, 56, .45); }
.target-scheme-list__dialog { box-sizing: border-box; width: min(480px, 100%); padding: 24px; border-radius: 10px; background: #fff; box-shadow: 0 18px 55px rgba(22, 37, 58, .22); }
.target-scheme-list__dialog h2 { margin: 0 0 12px; font-size: 18px; }
.target-scheme-list__dialog p { color: #56677e; line-height: 1.6; }
.target-scheme-list__dialog label { display: block; margin: 16px 0 6px; font-weight: 600; }
.target-scheme-list__dialog textarea { box-sizing: border-box; width: 100%; padding: 9px; border: 1px solid #c9d8e9; border-radius: 5px; resize: vertical; font: inherit; }
.target-scheme-list__dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
.target-scheme-list__danger-primary { background: #b33a35; border-color: #b33a35; color: #fff; }
.target-scheme-list__dialog button:disabled { opacity: .5; cursor: not-allowed; }
.target-scheme-list__primary { background: #2377d4; border-color: #2377d4; color: #fff; }
</style>
