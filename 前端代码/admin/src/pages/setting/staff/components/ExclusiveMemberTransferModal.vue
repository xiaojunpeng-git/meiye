<template>
  <Modal :value="value" title="转移专属会员" width="640" :mask-closable="false" :closable="!submitting" @on-cancel="close">
    <Spin v-if="loading" fix />
    <Form :label-width="100" @submit.native.prevent>
      <FormItem label="转出员工：">{{ preview.source_name || staffRow.staff_name || '-' }}</FormItem>
      <FormItem label="所属门店：">{{ preview.store_name || staffRow.store_name || '-' }}</FormItem>
      <FormItem label="专属会员：">{{ preview.count || 0 }} 人</FormItem>
      <FormItem label="接收员工：">
        <Select v-model="recipientIds" multiple filterable transfer :disabled="loading || submitting || !preview.count"
          placeholder="请选择接收员工" @on-change="selectionChanged">
          <Option v-for="person in preview.recipients" :key="person.staff_id" :value="person.staff_id">{{ person.staff_name }}</Option>
        </Select>
      </FormItem>
    </Form>
    <Alert v-if="error" type="error" show-icon>{{ error }}</Alert>
    <div slot="footer">
      <Button :disabled="submitting" @click="close">取消</Button>
      <Button v-if="error" :disabled="submitting" @click="load">刷新</Button>
      <Button type="primary" :loading="submitting" :disabled="loading || !preview.count || !recipientIds.length || !!error"
        @click="submit">确认转移</Button>
    </div>
  </Modal>
</template>

<script>
import { prepareExclusiveMemberTransfer, transferExclusiveMembers } from '@/api/setting';

// 一个确认版本使用一个令牌；网络失败重试不生成第二次业务操作。
function requestToken() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : ((r & 3) | 8)).toString(16);
  });
}

export default {
  name: 'ExclusiveMemberTransferModal',
  props: { value: Boolean, staffRow: { type: Object, default: () => ({}) } },
  data() {
    return { loading: false, submitting: false, error: '', preview: { recipients: [] }, recipientIds: [], token: '', sequence: 0 };
  },
  watch: {
    value(open) { if (open) this.load(); else this.sequence += 1; },
  },
  methods: {
    // 窗口切换时使旧请求失效，避免把上一员工的范围写到新窗口。
    async load() {
      const sequence = ++this.sequence;
      this.loading = true;
      this.error = '';
      this.preview = { recipients: [] };
      this.recipientIds = [];
      this.token = requestToken();
      try {
        const response = await prepareExclusiveMemberTransfer(this.staffRow.id);
        if (sequence === this.sequence && this.value) this.preview = response.data;
      } catch (err) {
        if (sequence === this.sequence) this.error = err.msg || '读取转移范围失败';
      } finally {
        if (sequence === this.sequence) this.loading = false;
      }
    },
    selectionChanged() { this.token = requestToken(); },
    async submit() {
      if (this.loading || this.submitting || this.error || !this.preview.count || !this.recipientIds.length) return;
      this.submitting = true;
      try {
        const response = await transferExclusiveMembers(this.staffRow.id, {
          recipient_ids: [...this.recipientIds], snapshot: this.preview.snapshot, request_token: this.token,
        });
        this.$Message.success(`${response.msg || '转移成功'}（${response.data.count}人）`);
        this.$emit('success');
        this.$emit('input', false);
      } catch (err) {
        this.error = err.msg || '转移失败，请刷新后重试';
      } finally {
        this.submitting = false;
      }
    },
    close() { if (!this.submitting) this.$emit('input', false); },
  },
};
</script>
