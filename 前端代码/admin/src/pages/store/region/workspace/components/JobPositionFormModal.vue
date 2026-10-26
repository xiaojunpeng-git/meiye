<template>
  <div v-if="visible" class="modal-layer open" @click.self="close">
    <div class="modal-dialog jp-workbench" role="dialog" aria-modal="true" @click.stop>
      <div class="modal-head">
        <h2>{{ form.id ? '编辑岗位策略' : '新建岗位策略' }}</h2>
        <button class="icon-button" type="button" @click="close" aria-label="关闭"><span aria-hidden="true">×</span></button>
      </div>

      <div class="jp-workbench-body">
        <aside class="jp-side">
          <div class="jp-side-title">岗位设置</div>

          <label class="jp-field">
            <span>岗位名称</span>
            <input v-model.trim="form.name" type="text" placeholder="如：店长 / 前台 / 总部运营" :disabled="submitting" />
          </label>

          <label class="jp-field">
            <span>岗位说明</span>
            <textarea v-model.trim="form.remark" rows="2" placeholder="可选，简要说明岗位职责" :disabled="submitting" />
          </label>

          <div class="jp-field jp-field-switch">
            <span>启用状态</span>
            <label
              class="jp-switch"
              :class="{ 'is-on': Number(form.status) === 1, 'is-disabled': submitting }"
            >
              <input
                class="jp-switch-input"
                type="checkbox"
                :checked="Number(form.status) === 1"
                :disabled="submitting"
                @change="form.status = $event.target.checked ? 1 : 0"
              />
              <span class="jp-switch-track" aria-hidden="true"><span class="jp-switch-thumb" /></span>
              <span class="jp-switch-text">{{ Number(form.status) === 1 ? '启用' : '停用' }}</span>
            </label>
          </div>

          <div class="jp-field jp-field-switch">
            <span>门店可用</span>
            <label
              class="jp-switch"
              :class="{ 'is-on': Number(form.allow_store_select) === 1, 'is-disabled': submitting }"
            >
              <input
                class="jp-switch-input"
                type="checkbox"
                :checked="Number(form.allow_store_select) === 1"
                :disabled="submitting"
                @change="form.allow_store_select = $event.target.checked ? 1 : 0"
              />
              <span class="jp-switch-track" aria-hidden="true"><span class="jp-switch-thumb" /></span>
              <span class="jp-switch-text">{{ Number(form.allow_store_select) === 1 ? '可用' : '不可用' }}</span>
            </label>
          </div>

          <div class="jp-field jp-field-switch">
            <span>业绩独立核算</span>
            <label class="jp-switch" :class="{ 'is-on': Number(form.performance_independent) === 1, 'is-disabled': submitting }">
              <input class="jp-switch-input" type="checkbox" :checked="Number(form.performance_independent) === 1" :disabled="submitting" @change="form.performance_independent = $event.target.checked ? 1 : 0" />
              <span class="jp-switch-track" aria-hidden="true"><span class="jp-switch-thumb" /></span>
              <span class="jp-switch-text">{{ Number(form.performance_independent) === 1 ? '独立' : '不独立' }}</span>
            </label>
          </div>

          <div class="jp-tip-card">
            <p>岗位决定员工可以操作哪些功能；入口关闭则该端不可用。</p>
            <p class="jp-tip-sub">门店可用仅控制门店选岗；人员数据权限决定员工可以看到哪些数据。</p>
            <p class="jp-tip-sub">业绩独立核算用于收银人员分配：独立岗位单独按 100% 核算，不参与普通岗位均分。</p>
          </div>

          <div class="jp-summary">
            <div class="jp-summary-title">三端权限摘要</div>
            <ul>
              <li>
                <span>平台后台</span>
                <b>{{ Number(form.use_platform) === 1 ? ((ruleIds.platform || []).length + ' 项') : '入口关' }}</b>
              </li>
              <li>
                <span>门店端</span>
                <b>{{ Number(form.use_store) === 1 ? ((ruleIds.store_v3 || []).length + ' 项') : '入口关' }}</b>
              </li>
              <li>
                <span>商家端</span>
                <b>{{ Number(form.use_mobile) === 1 ? ((ruleIds.mobile || []).length + ' 项') : '入口关' }}</b>
              </li>
            </ul>
          </div>
        </aside>

        <section class="jp-main">
          <div class="jp-main-title">功能权限</div>
          <FourChannelPermissionEditor
            ref="editor"
            class="jp-main-editor"
            :menus="menus"
            :value="ruleIds"
            :entries="entryValue"
            :disabled="submitting"
            :include-platform="true"
            @change="onRulesChange"
          />
        </section>
      </div>

      <div class="modal-footer jp-footer">
        <button class="button secondary" type="button" :disabled="submitting" @click="close">取消</button>
        <button class="button primary" type="button" :disabled="submitting" @click="submit">{{ submitting ? '保存中…' : '保存岗位' }}</button>
      </div>
    </div>
  </div>
</template>

<script>
import FourChannelPermissionEditor from './FourChannelPermissionEditor.vue';
import {
  getJobPositionMenus,
  getJobPositionDetail,
} from '@/api/store';

export default {
  name: 'JobPositionFormModal',
  components: { FourChannelPermissionEditor },
  props: {
    value: { type: Boolean, default: false },
    positionId: { type: Number, default: 0 },
    submitting: { type: Boolean, default: false },
  },
  data() {
    return {
      menus: {},
      ruleIds: { platform: [], store_v3: [], mobile: [] },
      form: {
        id: 0,
        name: '',
        remark: '',
        status: 1,
        allow_store_select: 0,
        performance_independent: 0,
        is_store_manager: 0,
        use_platform: 0,
        use_store: 0,
        use_mobile: 0,
      },
    };
  },
  computed: {
    visible: {
      get() { return this.value; },
      set(v) { this.$emit('input', v); },
    },
    entryValue() {
      return {
        platform: Number(this.form.use_platform) === 1 ? 1 : 0,
        store_v3: Number(this.form.use_store) === 1 ? 1 : 0,
        mobile: Number(this.form.use_mobile) === 1 ? 1 : 0,
      };
    },
  },
  watch: {
    value(v) {
      if (v) this.open();
    },
  },
  methods: {
    close() {
      this.visible = false;
    },
    open() {
      this.form = {
        id: Number(this.positionId) || 0,
        name: '',
        remark: '',
        status: 1,
        allow_store_select: 0,
        performance_independent: 0,
        is_store_manager: 0,
        use_platform: 0,
        use_store: 0,
        use_mobile: 0,
      };
      this.ruleIds = { platform: [], store_v3: [], mobile: [] };
      const detail = this.form.id > 0 ? this.loadDetail(this.form.id) : Promise.resolve();
      const menus = this.loadMenus();
      Promise.all([menus, detail])
        .catch((err) => {
          this.$Message && this.$Message.error((err && err.msg) || '加载失败');
        });
    },
    loadMenus() {
      return getJobPositionMenus().then((res) => {
        this.menus = (res && res.data) || {};
      });
    },
    loadDetail(id) {
      return getJobPositionDetail(id).then((res) => {
        const d = (res && res.data) || {};
        const p = d.position || {};
        this.form = {
          id: Number(p.id) || 0,
          name: p.name || '',
          remark: p.remark || '',
          status: Number(p.status) === 1 ? 1 : 0,
          allow_store_select: Number(p.allow_store_select) === 1 ? 1 : 0,
          performance_independent: Number(p.performance_independent) === 1 ? 1 : 0,
          is_store_manager: Number(p.is_store_manager) === 1 ? 1 : 0,
          use_platform: Number(p.use_platform) === 1 ? 1 : 0,
          use_store: Number(p.use_store) === 1 ? 1 : 0,
          use_mobile: Number(p.use_mobile) === 1 ? 1 : 0,
        };
        this.ruleIds = {
          platform: Number(this.form.use_platform) === 1 ? (d.platform_rules_ids || []).map(Number) : [],
          store_v3: Number(this.form.use_store) === 1 ? (d.store_v3_rules_ids || []).map(Number) : [],
          mobile: Number(this.form.use_mobile) === 1 ? (d.mobile_rules_ids || []).map(Number) : [],
        };
      });
    },
    onRulesChange(rules, entries) {
      this.ruleIds = rules || this.ruleIds;
      if (entries) {
        this.form.use_platform = Number(entries.use_platform) === 1 ? 1 : 0;
        this.form.use_store = Number(entries.use_store) === 1 ? 1 : 0;
        this.form.use_mobile = Number(entries.use_mobile) === 1 ? 1 : 0;
      }
    },
    submit() {
      if (!String(this.form.name || '').trim()) {
        this.$Message && this.$Message.required
          ? this.$Message.required('岗位名称未填写')
          : this.$Message.error('请填写岗位名称');
        return;
      }
      const rules = (this.$refs.editor && this.$refs.editor.getRules)
        ? this.$refs.editor.getRules()
        : this.ruleIds;
      const entries = (this.$refs.editor && this.$refs.editor.getEntries)
        ? this.$refs.editor.getEntries()
        : {
          use_platform: Number(this.form.use_platform) === 1 ? 1 : 0,
          use_store: Number(this.form.use_store) === 1 ? 1 : 0,
          use_mobile: Number(this.form.use_mobile) === 1 ? 1 : 0,
        };
      if (!entries.use_platform && !entries.use_store && !entries.use_mobile) {
        this.$Message.error('请至少开启一端入口');
        return;
      }
      this.$emit('save', {
        id: Number(this.form.id) || 0,
        name: String(this.form.name || '').trim(),
        remark: this.form.remark || '',
        status: Number(this.form.status) === 1 ? 1 : 0,
        allow_store_select: Number(this.form.allow_store_select) === 1 ? 1 : 0,
        performance_independent: Number(this.form.performance_independent) === 1 ? 1 : 0,
        is_store_manager: Number(this.form.is_store_manager) === 1 ? 1 : 0,
        use_platform: Number(entries.use_platform) === 1 ? 1 : 0,
        use_store: Number(entries.use_store) === 1 ? 1 : 0,
        use_mobile: Number(entries.use_mobile) === 1 ? 1 : 0,
        platform_rules: Number(entries.use_platform) === 1 ? (rules.platform || []) : [],
        store_v3_rules: Number(entries.use_store) === 1 ? (rules.store_v3 || []) : [],
        mobile_rules: Number(entries.use_mobile) === 1 ? (rules.mobile || []) : [],
      });
    },
    /** 供父组件在保存成功后强制关闭 */
    forceClose() {
      this.visible = false;
    },
    reloadDetail() {
      if (this.form.id) return this.loadDetail(this.form.id);
      return Promise.resolve();
    },
  },
};
</script>

<style scoped>
.modal-layer {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgba(23, 32, 51, .42);
}
.modal-dialog {
  max-height: calc(100vh - 40px);
  overflow: hidden;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 20px 45px rgba(16, 24, 40, .2);
}
.modal-head,
.modal-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 20px;
  border-bottom: 1px solid #eaecf0;
}
.modal-head h2 { margin: 0; font-size: 18px; }
.modal-footer { justify-content: flex-end; border-top: 1px solid #eaecf0; border-bottom: 0; }
.icon-button { border: 0; color: #667085; background: transparent; cursor: pointer; font-size: 24px; line-height: 1; }
.modal-dialog.jp-workbench,
.jp-workbench {
  width: min(1280px, 90vw) !important;
  height: 88vh !important;
  max-height: 88vh !important;
  display: flex;
  flex-direction: column;
  background: #fff;
  overflow: hidden;
}
.jp-workbench-body {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  overflow: hidden;
}
.jp-side {
  width: 340px;
  flex: 0 0 340px;
  min-width: 0;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: hidden;
  padding: 16px 16px 12px;
  border-right: 1px solid #e8ebf2;
  background: #f8f9fc;
  display: flex;
  flex-direction: column;
  gap: 0;
}
.jp-side-title,
.jp-main-title {
  font-size: 14px;
  font-weight: 650;
  color: #1f2430;
  margin-bottom: 14px;
}
.jp-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 10px;
  font-size: 12px;
  color: #475467;
}
.jp-field > span:first-child {
  font-weight: 560;
  color: #344054;
}
.jp-field input,
.jp-field textarea {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 13px;
  background: #fff;
  color: #1f2430;
}
.jp-field input {
  height: 38px;
}
.jp-field textarea {
  resize: none;
  min-height: 56px;
  line-height: 1.45;
}
.jp-field-switch {
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 38px;
}
.jp-field-switch > span:first-child {
  flex: 0 0 auto;
}
.jp-switch {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  user-select: none;
}
.jp-switch.is-disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
.jp-switch-input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
  pointer-events: none;
}
.jp-switch-track {
  position: relative;
  width: 40px;
  height: 22px;
  flex: 0 0 40px;
  border-radius: 999px;
  background: #c5cad3;
  transition: background 0.18s ease;
}
.jp-switch.is-on .jp-switch-track {
  background: #5b5bd6;
}
.jp-switch-thumb {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(32, 42, 63, 0.28);
  transition: transform 0.18s ease;
}
.jp-switch.is-on .jp-switch-thumb {
  transform: translateX(18px);
}
.jp-switch-text {
  color: #667085;
  font-size: 12px;
  line-height: 1.2;
  white-space: nowrap;
}
.jp-switch.is-on .jp-switch-text {
  color: #1f2430;
  font-weight: 600;
}
.jp-tip-card {
  margin-top: 2px;
  padding: 12px 12px;
  border-radius: 10px;
  background: #eef2ff;
  border: 1px solid #e0e7ff;
  color: #3f4a66;
  font-size: 12px;
  line-height: 1.55;
}
.jp-tip-card p {
  margin: 0;
}
.jp-tip-sub {
  margin-top: 6px !important;
  color: #667085;
}
.jp-summary {
  margin-top: auto;
  padding-top: 14px;
}
.jp-summary-title {
  font-size: 12px;
  font-weight: 650;
  color: #1f2430;
  margin-bottom: 8px;
}
.jp-summary ul {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 1px solid #e6e8ef;
  border-radius: 10px;
  background: #fff;
  overflow: hidden;
}
.jp-summary li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 9px 12px;
  font-size: 12px;
  color: #475467;
  border-bottom: 1px solid #f0f2f7;
}
.jp-summary li:last-child {
  border-bottom: 0;
}
.jp-summary b {
  color: #1f2430;
  font-weight: 650;
  font-variant-numeric: tabular-nums;
}
.jp-main {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  padding: 20px 20px 12px;
  overflow: hidden;
}
.jp-main-editor {
  flex: 1 1 auto;
  min-height: 0;
  min-width: 0;
}
.jp-footer {
  flex: 0 0 auto;
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 12px;
  padding: 14px 20px;
  border-top: 1px solid #eef0f5;
  background: #fff;
}
.jp-footer .button {
  min-width: 96px;
  height: 36px;
  padding: 0 18px;
}
@media (max-width: 1024px) {
  .jp-side {
    width: 300px;
    flex-basis: 300px;
    padding: 16px 14px;
  }
  .jp-main {
    padding: 16px 14px 10px;
  }
}
@media (max-width: 820px) {
  .jp-workbench {
    width: 96vw;
    height: 92vh;
    max-height: 92vh;
  }
  .jp-workbench-body {
    flex-direction: column;
  }
  .jp-side {
    width: 100%;
    flex: 0 0 auto;
    max-height: none;
    border-right: 0;
    border-bottom: 1px solid #e8ebf2;
    overflow: hidden;
  }
  .jp-summary {
    margin-top: 12px;
  }
  .jp-summary ul {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }
  .jp-summary li {
    border-right: 1px solid #f0f2f7;
  }
}
</style>
