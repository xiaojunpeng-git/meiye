<template>
  <section class="platform-member-frame">
    <iframe ref="frame" :src="frameUrl" title="用户列表" />
    <Modal v-model="transferOpen" title="会员调店" :mask-closable="false" :closable="!saving" :width="560">
      <Form ref="transferForm" :model="form" :rules="rules" :label-width="96">
        <FormItem label="会员">{{ form.member_name }}</FormItem>
        <FormItem label="当前门店">{{ form.current_store_name || '未设置' }}</FormItem>
        <FormItem label="目标门店" prop="store_id"><Select v-model="form.store_id" filterable :loading="loading" :disabled="saving" placeholder="请选择目标门店"><Option v-for="store in stores" :key="store.id" :value="Number(store.id)" :disabled="Number(store.id) === form.current_store_id">{{ store.name }}</Option></Select></FormItem>
        <FormItem label="调店原因" prop="reason"><Input v-model="form.reason" type="textarea" :rows="4" :maxlength="200" show-word-limit :disabled="saving" placeholder="请输入调店原因" /></FormItem>
      </Form>
      <template #footer><Button :disabled="saving" @click="transferOpen = false">取消</Button><Button type="primary" :loading="saving" @click="saveTransfer">确认调店</Button></template>
    </Modal>
  </section>
</template>

<script>
import { memberTransferStoreOptionsApi, memberTransferStoreApi } from '@/api/user';

// 平台壳复用Vue3会员模块；调店保留原接口、原因与所属门店变更边界。
export default {
  name: 'UserList',
  data () {
    return { transferOpen: false, saving: false, loading: false, stores: [], form: { uid: 0, member_name: '', current_store_id: 0, current_store_name: '', store_id: '', reason: '' }, rules: { store_id: [{ required: true, type: 'number', message: '请选择目标门店', trigger: 'change' }], reason: [{ required: true, message: '请输入调店原因', trigger: 'blur' }] } };
  },
  computed: {
    frameUrl () {
      const origin = String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || window.location.origin).replace(/\/$/, '');
      return `${origin}/view_cashier_v3/?platformHostOrigin=${encodeURIComponent(window.location.origin)}#/platform/member-list`;
    }
  },
  mounted () { window.addEventListener('message', this.receiveMemberAction); },
  beforeDestroy () { window.removeEventListener('message', this.receiveMemberAction); },
  methods: {
    async receiveMemberAction (event) {
      // 不信任其他标签/弹窗消息；不通过消息传送任何登录凭证。
      if (event.origin !== new URL(this.frameUrl).origin || event.source !== this.$refs.frame.contentWindow || (!event.data || event.data.type) !== 'platform-member:transfer') return;
      const row = event.data.member || {};
      if (!Number.isInteger(Number(row.uid)) || Number(row.uid) <= 0) return;
      this.form = { uid: Number(row.uid), member_name: String(row.nickname || ''), current_store_id: Number(row.belong_store_id || 0), current_store_name: String(row.belong_store_name || ''), store_id: '', reason: '' };
      this.transferOpen = true; this.loading = true;
      try { const response = await memberTransferStoreOptionsApi(); this.stores = Array.isArray(response.data) ? response.data : []; }
      catch (error) { this.$Message.error(error.msg || '门店列表加载失败'); }
      finally { this.loading = false; }
    },
    saveTransfer () {
      this.$refs.transferForm.validate(async valid => {
        if (!valid || this.saving) return;
        if (Number(this.form.store_id) === this.form.current_store_id) return this.$Message.warning('目标门店不能与当前门店相同');
        this.saving = true;
        try {
          const response = await memberTransferStoreApi(this.form.uid, { store_id: Number(this.form.store_id), reason: this.form.reason.trim() });
          this.$Message.success(response.msg || '调店成功'); this.transferOpen = false;
          this.$refs.frame.contentWindow.postMessage({ type: 'platform-member:refresh' }, new URL(this.frameUrl).origin);
        } catch (error) { this.$Message.error(error.msg || '调店失败'); }
        finally { this.saving = false; }
      });
    }
  }
};
</script>

<style scoped>
.platform-member-frame{height:calc(100vh - 118px);min-height:500px;background:#f5f7fa}.platform-member-frame>iframe{display:block;border:0;width:100%;height:100%;background:#f5f7fa}
</style>
