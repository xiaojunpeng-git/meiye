<template>
  <Modal v-model="visible" title="转移所属会员" :width="680" :mask-closable="false" class-name="store-member-transfer-modal">
    <div class="member-transfer-body">
      <Alert show-icon>仅变更会员资料的所属门店，余额、卡项、订单、业绩、专属服务人及门店服务关系保持原状。</Alert>
      <Alert v-if="error" type="error" show-icon>{{ error }}</Alert>
      <Spin v-if="loading" fix />
      <template v-if="batch">
        <p><strong>{{ batch.source_store_name }}</strong> → <strong>{{ batch.target_store_name }}</strong></p>
        <p>批次：{{ batch.batch_no }}</p>
        <p>原因：{{ batch.reason }}</p>
        <p>状态：{{ statusLabel(batch.status) }}</p>
        <Progress :percent="progress" :status="batch.status === 'blocked' ? 'wrong' : 'normal'" />
        <p>共 {{ batch.total_count }} 人；成功 {{ batch.success_count }} 人，跳过 {{ batch.skipped_count }} 人，失败 {{ batch.failed_count }} 人。</p>
        <Alert v-if="batch.last_error" type="warning" show-icon>{{ batch.last_error }}</Alert>
        <Alert v-if="batch.status === 'completed_with_exceptions'" type="warning" show-icon>本批次已处理完毕，存在跳过或失败会员，请核对下方原因。</Alert>
        <template v-if="Number(batch.exception_total) > 0">
          <Table size="small" :height="220" :columns="exceptionColumns" :data="batch.exceptions" />
          <div class="member-transfer-pagination">
            <span>共 {{ batch.exception_total }} 条 · 每页20条 · 第 {{ batch.exception_page }} / {{ exceptionPages }} 页</span>
            <Button size="small" :disabled="Number(batch.exception_page) <= 1" @click="loadStatus(Number(batch.exception_page) - 1)">上一页</Button>
            <Button size="small" :disabled="Number(batch.exception_page) >= exceptionPages" @click="loadStatus(Number(batch.exception_page) + 1)">下一页</Button>
          </div>
        </template>
        <p class="member-transfer-hint">刷新或关闭窗口不影响后台任务；再次打开此门店的转移窗口可查看原批次。</p>
      </template>
      <Form v-else :label-width="96">
        <FormItem label="原门店">{{ source.name }}</FormItem>
        <FormItem label="所属会员">{{ count }} 人（含停用会员，不含已注销、已删除会员）</FormItem>
        <FormItem label="目标门店" required>
          <Select v-model="targetStoreId" filterable :disabled="submitting" placeholder="请选择其他可用门店">
            <Option v-for="store in stores" :key="store.id" :value="Number(store.id)">{{ store.name }}</Option>
          </Select>
        </FormItem>
        <FormItem label="转移原因" required>
          <Input v-model.trim="reason" type="textarea" :maxlength="195" :rows="3" :disabled="submitting" placeholder="请输入转移原因（2-195字）" />
        </FormItem>
        <p class="member-transfer-hint">转移范围为确认时该门店的全部所属会员，不受门店列表分页影响；确认后归属已变化的会员会跳过。</p>
      </Form>
    </div>
    <div slot="footer">
      <Button @click="visible = false">关闭</Button>
      <template v-if="batch">
        <Button v-if="canResume" type="primary" :loading="submitting" @click="resume">继续执行</Button>
        <Button v-if="isFinished" @click="newTransfer">发起新转移</Button>
      </template>
      <template v-else>
        <Button :disabled="submitting || loading" @click="loadPreview(true)">刷新人数</Button>
        <Button type="primary" :loading="submitting" :disabled="loading || count === 0 || !targetStoreId || reason.length < 2" @click="confirmTransfer">确认转移</Button>
      </template>
    </div>
  </Modal>
</template>

<script>
import { previewMemberStoreTransfer, createMemberStoreTransfer, memberStoreTransferStatus, resumeMemberStoreTransfer } from '@/api/memberStoreTransfer';

/** 只展示后端批次进度；名单、计数、幂等及归属并发校验全部由后端负责。 */
export default {
  name: 'StoreMemberTransferModal',
  data() {
    return {
      visible: false, loading: false, submitting: false, error: '', source: {}, count: 0, stores: [],
      targetStoreId: '', reason: '', requestKey: '', batch: null, timer: null, generation: 0, statusSequence: 0,
      exceptionColumns: [
        { title: '会员ID', key: 'uid', width: 100 },
        { title: '结果', width: 80, render: (h, { row }) => h('span', row.status === 'skipped' ? '已跳过' : '失败') },
        { title: '具体原因', key: 'result_reason', minWidth: 220 }
      ]
    };
  },
  computed: {
    isFinished() { return this.batch && ['completed', 'completed_with_exceptions'].includes(this.batch.status); },
    progress() {
      if (!this.batch || !Number(this.batch.total_count)) return 0;
      return Math.floor((Number(this.batch.success_count) + Number(this.batch.skipped_count) + Number(this.batch.failed_count)) * 100 / Number(this.batch.total_count));
    },
    exceptionPages() { return Math.max(1, Math.ceil(Number(this.batch && this.batch.exception_total) / 20)); },
    canResume() {
      return this.batch && (this.batch.status === 'blocked' || Number(this.batch.failed_count) > 0 ||
        (!this.isFinished && Date.now() / 1000 - Number(this.batch.updated_at) > 60));
    }
  },
  watch: {
    visible(value) { if (!value) { this.stopPolling(); ++this.generation; } }
  },
  beforeDestroy() { this.stopPolling(); ++this.generation; },
  methods: {
    /** 每次新窗口生成独立请求键；网络重试沿用同一键，不能重复创建一批会员。 */
    open(row) {
      this.stopPolling(); ++this.generation;
      this.source = { id: Number(row.id), name: row.name };
      this.targetStoreId = ''; this.reason = ''; this.batch = null; this.error = '';
      this.submitting = false; this.loading = false; this.count = 0; this.stores = [];
      this.requestKey = `store-transfer-${Date.now()}-${Math.random().toString(36).slice(2)}`;
      this.visible = true;
      this.loadPreview(false);
    },
    stopPolling() { if (this.timer) clearTimeout(this.timer); this.timer = null; },
    statusLabel(value) {
      return { queued: '等待后台执行', running: '正在执行', blocked: '执行受阻，可继续', completed: '全部转移成功', completed_with_exceptions: '处理完成，存在异常项' }[value] || value;
    },
    async loadPreview(ignoreLatest) {
      const generation = this.generation;
      this.loading = true; this.error = '';
      try {
        const response = await previewMemberStoreTransfer(this.source.id);
        if (!this.visible || generation !== this.generation) return;
        this.source = response.data.source; this.count = Number(response.data.count); this.stores = response.data.stores;
        if (!ignoreLatest && response.data.batch) { this.batch = response.data.batch; this.schedulePoll(); }
      } catch (error) { if (generation === this.generation) this.error = error.msg || '加载失败，请重试'; } finally { if (generation === this.generation) this.loading = false; }
    },
    confirmTransfer() {
      if (this.submitting || !this.targetStoreId || this.reason.length < 2) return;
      const target = this.stores.find(store => Number(store.id) === Number(this.targetStoreId));
      if (!target) return;
      // 名称作为文本节点渲染，不将门店名称拼成可执行HTML。
      this.$Modal.confirm({
        title: '确认转移所属会员',
        render: h => h('div', [h('p', `将“${this.source.name}”的 ${this.count} 位所属会员转移至“${target.name}”。`), h('p', '仅更改所属门店，其他业务资料保持原状。')]),
        onOk: () => this.submit()
      });
    },
    async submit() {
      const generation = this.generation;
      this.submitting = true; this.error = '';
      try {
        const response = await createMemberStoreTransfer({ source_store_id: this.source.id, target_store_id: Number(this.targetStoreId),
          reason: this.reason, expected_count: this.count, request_key: this.requestKey });
        if (generation !== this.generation || !this.visible) return;
        this.batch = response.data; this.schedulePoll(); this.$emit('changed');
      } catch (error) { if (generation === this.generation) this.error = error.msg || '提交结果未明确，请保留当前窗口并使用同一请求重试'; } finally { if (generation === this.generation) this.submitting = false; }
    },
    /** 轮询只读状态；关闭页面不取消队列任务，旧窗口响应不覆盖新窗口。 */
    schedulePoll() {
      this.stopPolling();
      if (this.visible && this.batch && !this.isFinished && this.batch.status !== 'blocked') this.timer = setTimeout(() => this.loadStatus(Number(this.batch.exception_page) || 1), 1500);
    },
    async loadStatus(page) {
      if (!this.batch) return;
      const sequence = ++this.statusSequence;
      const generation = this.generation; const batchNo = this.batch.batch_no;
      try {
        const response = await memberStoreTransferStatus(batchNo, page);
        if (!this.visible || generation !== this.generation || sequence !== this.statusSequence || !this.batch || this.batch.batch_no !== batchNo) return;
        this.batch = response.data; this.error = ''; this.schedulePoll();
      } catch (error) { if (generation === this.generation) this.error = error.msg || '进度读取失败，可重新打开窗口查看'; }
    },
    async resume() {
      // 恢复操作开始后废弃旧轮询响应，避免旧状态覆盖本次恢复结果。
      this.stopPolling(); ++this.statusSequence;
      this.submitting = true; this.error = '';
      const generation = this.generation;
      try {
        const response = await resumeMemberStoreTransfer(this.batch.batch_no);
        if (!this.visible || generation !== this.generation) return;
        this.batch = response.data; this.schedulePoll();
      } catch (error) { if (generation === this.generation) this.error = error.msg || '继续执行失败，请重试'; } finally { if (generation === this.generation) this.submitting = false; }
    },
    newTransfer() {
      this.batch = null; this.targetStoreId = ''; this.reason = ''; this.error = '';
      this.requestKey = `store-transfer-${Date.now()}-${Math.random().toString(36).slice(2)}`;
      this.loadPreview(true);
    }
  }
};
</script>

<style>
.store-member-transfer-modal .ivu-modal { max-width: calc(100vw - 24px); }
.member-transfer-body { position: relative; max-height: 65vh; overflow: auto; }
.member-transfer-body p { margin-bottom: 10px; overflow-wrap: anywhere; }
.member-transfer-hint { color: #808695; font-size: 12px; }
.member-transfer-pagination { display: flex; justify-content: flex-end; align-items: center; flex-wrap: wrap; gap: 8px; padding: 10px 0; border-top: 1px solid #e8eaec; background: white; }
</style>
