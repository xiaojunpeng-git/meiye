<template>
  <div v-if="visible" class="modal-layer open" @click.self="close">
    <div class="modal-dialog jp-form-dialog" role="dialog" aria-modal="true" @click.stop>
      <div class="modal-head">
        <h2>{{ form.id ? '编辑总部门店角色模板' : '新建总部门店角色模板' }}</h2>
        <button class="icon-button" type="button" @click="close"><span aria-hidden="true">×</span></button>
      </div>
      <div class="modal-body jp-form-body">
        <div class="jp-basic">
          <div class="jp-basic-grid">
            <label class="jp-field">
              <span>模板名称</span>
              <input v-model.trim="form.role_name" type="text" placeholder="如：店长 / 收银员 / 前台" :disabled="submitting" />
            </label>
            <label class="jp-field">
              <span>模板说明</span>
              <input v-model.trim="form.remark" type="text" placeholder="给门店看的说明，可选" :disabled="submitting" />
            </label>
            <label class="jp-field">
              <span>启用状态</span>
              <select v-model.number="form.status" :disabled="submitting">
                <option :value="1">启用</option>
                <option :value="0">停用</option>
              </select>
            </label>
            <label class="jp-field">
              <span>允许门店选择</span>
              <select v-model.number="form.allow_store_select" :disabled="submitting">
                <option :value="1">允许</option>
                <option :value="0">不允许</option>
              </select>
            </label>
          </div>
          <div class="permission-note">
            <strong>说明</strong>
            <span>岗位决定员工能操作哪些功能，人员数据权限决定员工能看到哪些数据。本模板配置门店三端；平台后台权限请到「岗位策略」配置，且不能开放给门店。</span>
          </div>
        </div>
        <div class="jp-editor-wrap">
          <FourChannelPermissionEditor
            ref="editor"
            :menus="menus"
            :value="ruleIds"
            :disabled="submitting"
            :include-platform="true"
            :platform-locked="true"
            :show-entry-switch="false"
            @change="onRulesChange"
          />
        </div>
      </div>
      <div class="modal-footer">
        <button class="button secondary" type="button" :disabled="submitting" @click="close">取消</button>
        <button class="button primary" type="button" :disabled="submitting" @click="submit">{{ submitting ? '保存中…' : '保存' }}</button>
      </div>
    </div>
  </div>
</template>

<script>
import FourChannelPermissionEditor from './FourChannelPermissionEditor.vue';
import {
  getRoleTemplateMenus,
  getRoleTemplateDetail,
  saveRoleTemplate,
} from '@/api/store';
import { resolveOrgWriteToken } from '@/api/orgWriteHelpers';

function newRequestToken() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : ((r & 0x3) | 0x8);
    return v.toString(16);
  });
}

export default {
  name: 'RoleTemplateFormModal',
  components: { FourChannelPermissionEditor },
  props: {
    value: { type: Boolean, default: false },
    templateId: { type: Number, default: 0 },
  },
  data() {
    return {
      submitting: false,
      menus: {},
      ruleIds: { platform: [], store: [], cashier: [], mobile: [] },
      form: {
        id: 0,
        role_name: '',
        remark: '',
        status: 1,
        allow_store_select: 1,
      },
    };
  },
  computed: {
    visible: {
      get() { return this.value; },
      set(v) { this.$emit('input', v); },
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
        id: Number(this.templateId) || 0,
        role_name: '',
        remark: '',
        status: 1,
        allow_store_select: 1,
      };
      this.ruleIds = { platform: [], store: [], cashier: [], mobile: [] };
      this.loadMenus().then(() => {
        if (this.form.id > 0) return this.loadDetail(this.form.id);
        return null;
      }).catch((err) => {
        this.$Message && this.$Message.error((err && err.msg) || '加载失败');
      });
    },
    loadMenus() {
      return getRoleTemplateMenus().then((res) => {
        const data = (res && res.data) || {};
        this.menus = {
          platform_menus: [],
          store_menus: data.store_menus || [],
          cashier_menus: data.cashier_menus || [],
          mobile_menus: data.mall_menus || [],
        };
      });
    },
    loadDetail(id) {
      return getRoleTemplateDetail(id).then((res) => {
        const d = (res && res.data) || {};
        this.form = {
          id: Number(d.id) || 0,
          role_name: d.role_name || '',
          remark: d.remark || '',
          status: Number(d.status) === 1 ? 1 : 0,
          allow_store_select: Number(d.allow_store_select) === 1 ? 1 : 0,
        };
        this.ruleIds = {
          platform: [],
          store: (d.rules_ids || []).map(Number),
          cashier: (d.cashier_rules_ids || []).map(Number),
          mobile: (d.mall_rules_ids || []).map(Number),
        };
      });
    },
    onRulesChange(rules) {
      this.ruleIds = Object.assign({}, this.ruleIds, rules || {});
      this.ruleIds.platform = [];
    },
    submit() {
      if (this.submitting) return;
      if (!this.form.role_name) {
        this.$Message && this.$Message.required
          ? this.$Message.required('模板名称未填写')
          : this.$Message.error('请填写模板名称');
        return;
      }
      const rules = (this.$refs.editor && this.$refs.editor.getRules)
        ? this.$refs.editor.getRules()
        : this.ruleIds;
      const storeRules = rules.store || [];
      const cashierRules = rules.cashier || [];
      const mallRules = rules.mobile || [];
      if (!storeRules.length && !cashierRules.length && !mallRules.length) {
        this.$Message.error('请至少配置门店后台、收银台或商家端其中一端功能权限');
        return;
      }
      const payload = {
        id: Number(this.form.id) || 0,
        role_name: this.form.role_name,
        remark: this.form.remark || '',
        status: Number(this.form.status) === 1 ? 1 : 0,
        allow_store_select: Number(this.form.allow_store_select) === 1 ? 1 : 0,
        rules: storeRules,
        cashier_rules: cashierRules,
        mall_rules: mallRules,
        request_token: newRequestToken(),
      };
      const headers = {
        'X-Request-Token': payload.request_token,
        'Request-Token': payload.request_token,
      };
      this.submitting = true;
      saveRoleTemplate(payload, headers)
        .then((res) => {
          const tokenMeta = resolveOrgWriteToken(res);
          if (tokenMeta && tokenMeta.conflict) {
            this.$Message.error((res && res.msg) || '幂等冲突，请更换令牌后重试');
            return;
          }
          this.$Message.success((res && res.msg) || '保存成功');
          this.$emit('success', (res && res.data) || {});
          this.close();
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '保存失败');
        })
        .finally(() => {
          this.submitting = false;
        });
    },
  },
};
</script>

<style scoped>
.jp-form-dialog {
  width: min(1120px, 96vw);
  max-height: calc(100vh - 32px);
  display: flex;
  flex-direction: column;
  background: #fff;
}
.jp-form-body {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 0;
  overflow: auto;
}
.jp-basic-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.jp-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  color: #475467;
}
.jp-field input,
.jp-field select {
  height: 36px;
  padding: 0 10px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
}
.permission-note {
  margin-top: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  background: #f5f6fa;
  color: #475467;
  font-size: 12px;
  line-height: 1.6;
}
.permission-note strong {
  margin-right: 6px;
  color: #1f2430;
}
.jp-editor-wrap {
  min-height: 360px;
  display: flex;
  flex-direction: column;
}
@media (max-width: 820px) {
  .jp-basic-grid { grid-template-columns: 1fr; }
}
</style>
