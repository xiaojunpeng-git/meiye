<template>
  <Modal
    v-model="visible"
    title="员工功能权限"
    width="760"
    :mask-closable="false"
    :closable="!saving"
    @on-cancel="close"
  >
    <div class="permission-modal">
      <p class="permission-modal__staff">{{ staffName || '当前员工' }}</p>
      <p class="permission-modal__hint">岗位权限是默认值，员工权限可单独设置为继承、允许或禁止。保存后下次登录生效。</p>
      <Spin v-if="loading" fix />
      <Alert v-if="error" type="error" show-icon>{{ error }}</Alert>
      <div v-if="!loading && groups.length" class="permission-groups">
        <section v-for="group in groups" :key="group.module" class="permission-group">
          <header class="permission-group__header">
            <strong>{{ group.module || '其他功能' }}</strong>
            <span>{{ group.items.length }} 项功能</span>
          </header>
          <div v-for="item in group.items" :key="item.code" class="permission-row">
            <span>{{ item.label }}</span>
            <Select v-model="item.effect" size="small" style="width: 132px">
              <Option value="inherit">继承岗位</Option>
              <Option value="allow">允许</Option>
              <Option value="deny">禁止</Option>
            </Select>
          </div>
        </section>
      </div>
      <p v-if="!loading && !groups.length && !error" class="permission-modal__empty">暂无可配置功能。</p>
    </div>
    <div slot="footer">
      <Button :disabled="saving" @click="close">取消</Button>
      <Button type="primary" :loading="saving" :disabled="loading || !!error" @click="save">保存权限</Button>
    </div>
  </Modal>
</template>

<script>
import { getStaffFeaturePermissions, saveStaffFeaturePermissions } from '@/api/staff';

export default {
  name: 'StaffFeaturePermissionModal',
  props: {
    value: { type: Boolean, default: false },
    staff: { type: Object, default: () => ({}) },
  },
  data() {
    return {
      visible: false,
      loading: false,
      saving: false,
      error: '',
      version: 0,
      items: [],
    };
  },
  computed: {
    staffId() {
      return Number(this.staff && (this.staff.id || this.staff.staff_id)) || 0;
    },
    staffName() {
      return this.staff && (this.staff.staff_name || this.staff.staffName || this.staff.name);
    },
    groups() {
      const grouped = {};
      (this.items || []).forEach((item) => {
        const moduleName = item.module || '其他功能';
        if (!grouped[moduleName]) grouped[moduleName] = [];
        grouped[moduleName].push(item);
      });
      return Object.keys(grouped).map((module) => ({ module, items: grouped[module] }));
    },
  },
  watch: {
    value: {
      immediate: true,
      handler(value) {
        this.visible = Boolean(value);
        if (value) this.load();
      },
    },
    visible(value) {
      if (value !== this.value) this.$emit('input', value);
    },
  },
  methods: {
    close() {
      if (this.saving) return;
      this.visible = false;
    },
    load() {
      if (!this.staffId) {
        this.error = '该员工没有有效的门店任职，不能编辑门店端权限。';
        this.items = [];
        return;
      }
      this.loading = true;
      this.error = '';
      getStaffFeaturePermissions(this.staffId)
        .then((res) => {
          const data = res.data || {};
          this.version = Number(data.version) || 0;
          this.items = Array.isArray(data.items) ? data.items.map((item) => ({ ...item })) : [];
        })
        .catch((err) => {
          this.items = [];
          this.error = (err && (err.msg || err.message)) || '员工权限加载失败';
        })
        .finally(() => { this.loading = false; });
    },
    save() {
      if (!this.staffId || this.loading || this.saving || this.error) return;
      this.saving = true;
      this.error = '';
      const effects = {};
      this.items.forEach((item) => { effects[item.code] = item.effect; });
      saveStaffFeaturePermissions(this.staffId, { effects, version: this.version })
        .then((res) => {
          this.$Message.success((res && res.msg) || '员工功能权限已保存，下次登录生效');
          this.$emit('success');
          this.visible = false;
        })
        .catch((err) => {
          this.error = (err && (err.msg || err.message)) || '员工权限保存失败';
        })
        .finally(() => { this.saving = false; });
    },
  },
};
</script>

<style scoped>
.permission-modal { position: relative; min-height: 180px; }
.permission-modal__staff { margin: 0 0 4px; color: #17233d; font-size: 16px; font-weight: 600; }
.permission-modal__hint { margin: 0 0 14px; color: #808695; font-size: 12px; line-height: 1.6; }
.permission-groups { display: grid; gap: 12px; max-height: 480px; overflow: auto; padding-right: 4px; }
.permission-group { border: 1px solid #e8eaec; border-radius: 4px; }
.permission-group__header { display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; border-bottom: 1px solid #e8eaec; color: #17233d; }
.permission-group__header span { color: #808695; font-size: 12px; font-weight: 400; }
.permission-row { display: flex; align-items: center; justify-content: space-between; min-height: 40px; padding: 4px 12px; border-bottom: 1px solid #f3f3f3; color: #515a6e; font-size: 13px; }
.permission-row:last-child { border-bottom: 0; }
.permission-modal__empty { color: #808695; }
</style>
