<template>
  <main class="job-position-page">
    <header class="job-position-page__header">
      <div>
        <p class="eyebrow">组织架构</p>
        <h1>岗位策略</h1>
        <p class="subtitle">统一配置员工岗位可使用的功能，人员绑定岗位后自动获得对应权限。</p>
      </div>
      <button class="button primary" type="button" :disabled="!canWrite || loading" @click="openCreate">新建岗位</button>
    </header>

    <section class="job-position-page__card">
      <div class="job-position-page__toolbar">
        <div class="input-shell">
          <span class="search-icon">⌕</span>
          <input v-model.trim="keyword" type="search" placeholder="搜索岗位名称" @keyup.enter="loadList" />
        </div>
        <select v-model="status" aria-label="岗位启用状态" @change="loadList">
          <option value="1">启用</option>
          <option value="">全部</option>
        </select>
        <button class="button secondary" type="button" @click="loadList">查询</button>
      </div>

      <div v-if="error" class="job-position-page__notice error">{{ error }} <button type="button" @click="loadList">重试</button></div>
      <div v-else-if="loading" class="job-position-page__empty">正在加载岗位策略…</div>
      <div v-else class="job-position-page__table-wrap">
        <table class="job-position-page__table">
          <thead><tr><th>岗位名称</th><th>状态</th><th>门店可用</th><th>适用端</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="row in list" :key="row.id">
              <td><strong>{{ row.name }}</strong><small>{{ row.remark || '未填写岗位说明' }}</small></td>
              <td><button class="jp-switch" :class="{ on: Number(row.status) === 1, disabled: !canWrite || saving }" type="button" :disabled="!canWrite || saving" @click="toggleStatus(row)"><span></span>{{ Number(row.status) === 1 ? '启用' : '停用' }}</button></td>
              <td><button class="jp-switch" :class="{ on: Number(row.allow_store_select) === 1, disabled: !canWrite || saving || Number(row.status) !== 1 }" type="button" :disabled="!canWrite || saving || Number(row.status) !== 1" @click="toggleStoreSelect(row)"><span></span>{{ Number(row.allow_store_select) === 1 ? '可用' : '不可用' }}</button></td>
              <td>{{ channels(row) }}</td>
              <td><button class="link-button" type="button" @click="openEdit(row)">编辑</button></td>
            </tr>
          </tbody>
        </table>
        <div v-if="!list.length" class="job-position-page__empty">暂无岗位策略</div>
      </div>
    </section>

    <JobPositionFormModal ref="form" v-model="formOpen" :position-id="selectedId" :submitting="saving" @save="save" />
    <div v-if="confirm.open" class="confirm-mask" @click.self="resolveConfirm(false)">
      <div class="confirm-dialog"><h2>确认操作</h2><p>{{ confirm.text }}</p><footer><button class="button secondary" type="button" @click="resolveConfirm(false)">取消</button><button class="button primary" type="button" @click="resolveConfirm(true)">确定</button></footer></div>
    </div>
    <div v-if="toast" class="toast">{{ toast }}</div>
  </main>
</template>

<script>
import JobPositionFormModal from '../workspace/components/JobPositionFormModal.vue';
import {
  getJobPositions,
  saveJobPosition,
  getJobPositionDetail,
  getOrganizationWriteStatus
} from '@/api/store';
import { resolveOrgWriteToken } from '@/api/orgWriteHelpers';

const READONLY_TIP = '当前为组织架构只读阶段，写入未开放';
const UNKNOWN_WRITE_TIP = '提交结果未知，请确认是否已生效后再操作';

function requestToken() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  return `jp-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export default {
  name: 'JobPositionPolicy',
  components: { JobPositionFormModal },
  data() {
    return {
      keyword: '', status: '1', list: [], loading: false, saving: false, error: '', toast: '', toastTimer: null,
      formOpen: false, selectedId: 0,
      writeStatus: { write_enabled: false, can_write: false, reason_text: READONLY_TIP },
      pendingToken: '', pendingFingerprint: '',
      confirm: { open: false, text: '', resolver: null }
    };
  },
  computed: {
    canWrite() { return !!(this.writeStatus && this.writeStatus.can_write); }
  },
  created() {
    this.loadWriteStatus().finally(() => this.loadList());
  },
  beforeDestroy() {
    clearTimeout(this.toastTimer);
  },
  methods: {
    showToast(message) {
      this.toast = String(message || '');
      clearTimeout(this.toastTimer);
      this.toastTimer = setTimeout(() => { this.toast = ''; }, 3000);
    },
    loadWriteStatus() {
      return getOrganizationWriteStatus().then((res) => {
        const d = (res && res.data) || {};
        this.writeStatus = { write_enabled: !!d.write_enabled, can_write: !!d.can_write, reason_text: d.reason_text || READONLY_TIP };
      }).catch(() => { this.writeStatus = { write_enabled: false, can_write: false, reason_text: READONLY_TIP }; });
    },
    loadList() {
      this.loading = true; this.error = '';
      return getJobPositions({ keyword: this.keyword, status: this.status, page: 1, limit: 50 })
        .then((res) => { this.list = (res.data && res.data.list) || []; })
        .catch((err) => { this.list = []; this.error = (err && err.msg) || '岗位策略加载失败'; })
        .finally(() => { this.loading = false; });
    },
    channels(row) {
      const parts = [];
      if (Number(row.use_platform) === 1) parts.push('平台');
      if (Number(row.use_store) === 1) parts.push('门店端');
      if (Number(row.use_mobile) === 1) parts.push('手机');
      return parts.length ? parts.join(' / ') : '—';
    },
    openCreate() { if (!this.canWrite) return this.showToast(this.writeStatus.reason_text || READONLY_TIP); this.selectedId = 0; this.formOpen = true; },
    openEdit(row) { this.selectedId = Number(row.id) || 0; this.formOpen = true; },
    writeHeaders(action, payload) {
      const bound = resolveOrgWriteToken({ token: this.pendingToken, fingerprint: this.pendingFingerprint }, action, payload, requestToken);
      this.pendingToken = bound.token; this.pendingFingerprint = bound.fingerprint;
      return { 'X-Request-Token': bound.token };
    },
    resetToken() { this.pendingToken = ''; this.pendingFingerprint = ''; },
    save(form) {
      if (!this.canWrite) return this.showToast(this.writeStatus.reason_text || READONLY_TIP);
      if (this.saving) return;
      const payload = {
        id: Number(form.id || 0), name: String(form.name || '').trim(), status: Number(form.status) === 1 ? 1 : 0,
        remark: form.remark || '', allow_store_select: Number(form.allow_store_select) === 1 ? 1 : 0,
        is_store_manager: Number(form.is_store_manager) === 1 ? 1 : 0,
        use_platform: Number(form.use_platform) === 1 ? 1 : 0, use_store: Number(form.use_store) === 1 ? 1 : 0,
        use_mobile: Number(form.use_mobile) === 1 ? 1 : 0,
        platform_rules: Number(form.use_platform) === 1 ? (form.platform_rules || []) : [],
        store_v3_rules: Number(form.use_store) === 1 ? (form.store_v3_rules || []) : [],
        mobile_rules: Number(form.use_mobile) === 1 ? (form.mobile_rules || []) : []
      };
      this.resetToken(); const headers = this.writeHeaders('job_position_save', payload); payload.request_token = headers['X-Request-Token'];
      this.saving = true;
      saveJobPosition(payload, headers).then((res) => { this.resetToken(); this.formOpen = false; this.showToast((res && res.msg) || '保存成功'); this.loadList(); })
        .catch((err) => { this.showToast((err && err.msg) || UNKNOWN_WRITE_TIP); }).finally(() => { this.saving = false; });
    },
    ask(text) { return new Promise((resolve) => { this.confirm = { open: true, text, resolver: resolve }; }); },
    resolveConfirm(ok) { const fn = this.confirm.resolver; this.confirm = { open: false, text: '', resolver: null }; if (fn) fn(!!ok); },
    async toggleStatus(row) {
      if (!this.canWrite || this.saving) return this.showToast(this.writeStatus.reason_text || READONLY_TIP);
      const next = Number(row.status) === 1 ? 0 : 1;
      if (!await this.ask(next ? '确认启用该岗位？' : '停用后门店不能再新增选择，已绑定人员不会自动失去权限，是否继续？')) return;
      await this.persistPatch(row, { status: next });
    },
    async toggleStoreSelect(row) {
      if (!this.canWrite || this.saving) return this.showToast(this.writeStatus.reason_text || READONLY_TIP);
      const next = Number(row.allow_store_select) === 1 ? 0 : 1;
      if (!await this.ask(next ? '开启后门店可在新建或编辑本店员工时选择该岗位，是否继续？' : '关闭后门店不能再新增选择该岗位，已绑定人员不会自动失去权限，是否继续？')) return;
      await this.persistPatch(row, { allow_store_select: next });
    },
    async persistPatch(row, patch) {
      const positionId = Number(row.id); const old = { status: row.status, allow_store_select: row.allow_store_select };
      Object.assign(row, patch); this.saving = true;
      try {
        const detailRes = await getJobPositionDetail(positionId); const d = (detailRes && detailRes.data) || {}; const p = Object.assign({}, row, d.position || {}, { id: positionId });
        const payload = {
          id: positionId, name: p.name || '', status: patch.status == null ? Number(p.status) : Number(patch.status), remark: p.remark || '',
          allow_store_select: patch.allow_store_select == null ? Number(p.allow_store_select) : Number(patch.allow_store_select),
          is_store_manager: Number(p.is_store_manager) === 1 ? 1 : 0, use_platform: Number(p.use_platform) === 1 ? 1 : 0,
          use_store: Number(p.use_store) === 1 ? 1 : 0, use_mobile: Number(p.use_mobile) === 1 ? 1 : 0,
          channel_rules: d.channel_rules || {}
        };
        this.resetToken(); const headers = this.writeHeaders('job_position_save', payload); Object.assign(payload, { request_token: headers['X-Request-Token'] });
        const res = await saveJobPosition(payload, headers); this.resetToken(); this.showToast((res && res.msg) || '保存成功'); await this.loadList();
      } catch (err) { Object.assign(row, old); this.showToast((err && err.msg) || UNKNOWN_WRITE_TIP); }
      finally { this.saving = false; }
    }
  }
};
</script>

<style src="../workspace/workspace.css"></style>
<style scoped>
.job-position-page { min-height: 100%; padding: 28px 32px; color: #172033; background: #f6f8fb; }
.job-position-page__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 22px; }
.eyebrow { margin: 0 0 5px; color: #5b5bd6; font-size: 12px; font-weight: 650; }
h1 { margin: 0; font-size: 25px; line-height: 1.3; }
.subtitle { margin: 8px 0 0; color: #667085; font-size: 13px; }
.button { min-height: 38px; padding: 0 15px; border: 1px solid transparent; border-radius: 9px; cursor: pointer; font: inherit; font-weight: 570; }
.button.primary { color: #fff; border-color: #5b5bd6; background: #5b5bd6; }
.button.secondary { color: #344054; border-color: #d0d5dd; background: #fff; }
.job-position-page__card { padding: 20px; border: 1px solid #e4e7ec; border-radius: 14px; background: #fff; box-shadow: 0 8px 24px rgba(16,24,40,.04); }
.job-position-page__toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.input-shell { display: flex; align-items: center; gap: 8px; width: 300px; min-height: 38px; padding: 0 11px; border: 1px solid #d0d5dd; border-radius: 9px; background: #fff; }
.input-shell input { width: 100%; border: 0; outline: 0; font: inherit; }
.search-icon { color: #98a2b3; font-size: 20px; }
select { min-height: 38px; padding: 0 12px; border: 1px solid #d0d5dd; border-radius: 9px; background: #fff; font: inherit; }
.job-position-page__table-wrap { overflow-x: auto; }
.job-position-page__table { width: 100%; min-width: 760px; border-collapse: collapse; }
.job-position-page__table th { padding: 11px 14px; color: #667085; background: #f9fafb; font-size: 12px; text-align: left; }
.job-position-page__table td { padding: 15px 14px; border-bottom: 1px solid #f0f2f5; color: #475467; font-size: 13px; vertical-align: middle; }
.job-position-page__table td:first-child { min-width: 220px; }
.job-position-page__table td strong, .job-position-page__table td small { display: block; }
.job-position-page__table td strong { color: #1d2939; font-weight: 650; }
.job-position-page__table td small { margin-top: 4px; color: #98a2b3; font-size: 12px; }
.jp-switch { display: inline-flex; align-items: center; gap: 8px; border: 0; padding: 0; color: #667085; background: transparent; cursor: pointer; font: inherit; }
.jp-switch span { width: 34px; height: 20px; border-radius: 999px; background: #c5cad3; position: relative; transition: .18s; }
.jp-switch span:after { content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.2); transition: .18s; }
.jp-switch.on { color: #344054; font-weight: 600; }
.jp-switch.on span { background: #5b5bd6; }
.jp-switch.on span:after { transform: translateX(14px); }
.jp-switch.disabled { opacity: .5; cursor: not-allowed; }
.link-button { border: 0; padding: 0; color: #5b5bd6; background: transparent; cursor: pointer; font: inherit; }
.job-position-page__empty { padding: 52px 0; color: #98a2b3; text-align: center; }
.job-position-page__notice { margin-bottom: 14px; padding: 11px 13px; border-radius: 8px; font-size: 13px; }
.job-position-page__notice.error { color: #b42318; background: #fef3f2; }
.job-position-page__notice button { margin-left: 10px; border: 0; color: #5b5bd6; background: transparent; cursor: pointer; }
.confirm-mask { position: fixed; inset: 0; z-index: 1300; display: grid; place-items: center; background: rgba(23,32,51,.42); }
.confirm-dialog { width: min(440px, calc(100vw - 32px)); border-radius: 14px; background: #fff; box-shadow: 0 20px 45px rgba(16,24,40,.2); }
.confirm-dialog h2 { margin: 0; padding: 18px 20px; border-bottom: 1px solid #eaecf0; font-size: 18px; }
.confirm-dialog p { margin: 0; padding: 22px 20px; color: #475467; line-height: 1.6; }
.confirm-dialog footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid #eaecf0; }
.toast { position: fixed; right: 24px; bottom: 24px; z-index: 1400; padding: 12px 16px; border: 1px solid #d0d5dd; border-radius: 9px; color: #344054; background: #fff; box-shadow: 0 8px 24px rgba(16,24,40,.12); }
@media (max-width: 700px) { .job-position-page { padding: 20px 14px; } .job-position-page__header { flex-direction: column; } .job-position-page__toolbar { flex-wrap: wrap; } .input-shell { flex: 1 1 100%; width: auto; } }
</style>
<style>
/* workspace.css scopes its dialog shell under .org-prototype. This page is a
 * standalone route, so provide the shell here; otherwise the dialog exists in
 * the DOM but remains in normal document flow and appears as if the button did
 * nothing. */
.job-position-page .modal-layer {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgba(23, 32, 51, .42);
}
.job-position-page .modal-layer .modal-dialog {
  max-height: calc(100vh - 40px);
  overflow: hidden;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 20px 45px rgba(16, 24, 40, .2);
}
.job-position-page .modal-layer .modal-head,
.job-position-page .modal-layer .modal-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 20px;
  border-bottom: 1px solid #eaecf0;
}
.job-position-page .modal-layer .modal-head h2 { margin: 0; font-size: 18px; }
.job-position-page .modal-layer .modal-footer { justify-content: flex-end; border-top: 1px solid #eaecf0; border-bottom: 0; }
.job-position-page .modal-layer .icon-button { border: 0; color: #667085; background: transparent; cursor: pointer; font-size: 24px; line-height: 1; }
</style>
