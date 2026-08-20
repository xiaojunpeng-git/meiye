<template>
  <div class="presale-claim-page">
    <Card :bordered="false" dis-hover :padding="0" class="ivu-mt">
      <div class="new_card_pd">
        <Form inline :label-width="76" @submit.native.prevent>
          <FormItem label="组织 / 门店：">
            <OrganizationStoreScopePicker
              ref="scopePicker"
              v-model="filters.store_ids"
              :load-scope="loadPresaleClaimScope"
              @change="onScopeChange"
            />
          </FormItem>
          <FormItem label="状态：">
            <Select v-model="filters.status" clearable class="filter-select" placeholder="全部状态" @on-change="search">
              <Option value="AVAILABLE">可领用</Option>
              <Option value="FULLY_CLAIMED">已全部领用</Option>
              <Option value="CLOSED">已关闭</Option>
            </Select>
          </FormItem>
          <FormItem label="预售查询：">
            <Input v-model.trim="filters.keyword" class="filter-keyword" placeholder="订单、会员、手机或商品" @on-enter="search" />
          </FormItem>
          <FormItem :label-width="0">
            <Button type="primary" @click="search">查询 <span class="enter-key">↵</span></Button>
            <Button class="ml14" @click="reset">重置</Button>
          </FormItem>
        </Form>
      </div>
    </Card>

    <Card :bordered="false" dis-hover class="ivu-mt">
      <Table :columns="columns" :data="rows" :loading="loading" no-data-text="暂无预售商品">
        <template slot-scope="{ row }" slot="member">
          <div>{{ row.member_name || '游客' }}</div>
          <div class="cell-subtle">{{ row.member_phone || '-' }}</div>
        </template>
        <template slot-scope="{ row }" slot="quantity">
          <span>{{ row.quantity }}</span>
        </template>
        <template slot-scope="{ row }" slot="claimed">
          <span>{{ row.claimed_quantity }}</span>
        </template>
        <template slot-scope="{ row }" slot="unclaimed">
          <span>{{ row.unclaimed_quantity }}</span>
        </template>
        <template slot-scope="{ row }" slot="status">
          <Tag :color="statusColor(row.claim_status)">{{ statusLabel(row.claim_status) }}</Tag>
        </template>
        <template slot-scope="{ row }" slot="action">
          <a v-if="row.can_claim" @click="openClaim(row)">领用</a>
          <span v-else class="disabled-action">不可领用</span>
          <a class="action-gap" @click="openDetail(row)">领用明细</a>
        </template>
      </Table>
      <div class="page-wrap">
        <Page :total="total" :current="filters.page" :page-size="filters.limit" show-total show-elevator @on-change="changePage" />
      </div>
    </Card>

    <Modal v-model="claimModalOpen" title="预售商品领用" :mask-closable="false" @on-cancel="closeClaim">
      <Form v-if="activeLine" :label-width="90">
        <FormItem label="预售订单：">{{ activeLine.presale_order_no }}</FormItem>
        <FormItem label="所属门店：">{{ activeLine.store_name }}</FormItem>
        <FormItem label="商品：">{{ activeLine.product_name }}</FormItem>
        <FormItem label="未领用数量：">{{ activeLine.unclaimed_quantity }}</FormItem>
        <FormItem label="本次领用：" required>
          <InputNumber v-model="claimForm.quantity" :min="1" :max="Number(activeLine.unclaimed_quantity || 1)" :precision="0" />
        </FormItem>
        <FormItem label="领用日期：" required>
          <DatePicker v-model="claimForm.business_date" type="date" format="yyyy-MM-dd" placeholder="选择领用日期" />
        </FormItem>
      </Form>
      <div slot="footer">
        <Button @click="closeClaim">取消</Button>
        <Button type="primary" :loading="claimSubmitting" @click="submitClaim">确认领用</Button>
      </div>
    </Modal>

    <Modal v-model="detailModalOpen" title="领用明细" width="820" :mask-closable="false" @on-cancel="closeDetail">
      <div v-if="detailLoading" class="detail-loading">加载中...</div>
      <template v-else>
        <Alert v-if="detail.claimable" type="info" show-icon>
          {{ detail.claimable.product_name }}：预售 {{ detail.claimable.quantity }}，已领用 {{ detail.claimable.claimed_quantity }}，未领用 {{ detail.claimable.unclaimed_quantity }}
        </Alert>
        <Table :columns="detailColumns" :data="detail.claims || []" no-data-text="暂无领用记录">
          <template slot-scope="{ row }" slot="detail_status">
            <Tag :color="claimStatusColor(row.status)">{{ claimStatusLabel(row.status) }}</Tag>
          </template>
          <template slot-scope="{ row }" slot="occurred">
            {{ formatTime(row.occurred_at) }}
          </template>
          <template slot-scope="{ row }" slot="voided">
            <span v-if="row.voided_at">{{ formatTime(row.voided_at) }}</span>
            <span v-else>-</span>
          </template>
          <template slot-scope="{ row }" slot="detail_action">
            <a v-if="row.status === 'SETTLED'" @click="openVoid(row)">作废领用</a>
            <span v-else class="disabled-action">已作废</span>
          </template>
        </Table>
      </template>
      <div slot="footer"><Button @click="closeDetail">关闭</Button></div>
    </Modal>

    <Modal v-model="voidModalOpen" title="作废领用" :mask-closable="false" @on-cancel="closeVoid">
      <Form :label-width="86">
        <FormItem label="领用单号：">{{ activeClaim && activeClaim.claim_no }}</FormItem>
        <FormItem label="作废原因：" required>
          <Input v-model.trim="voidForm.reason" type="textarea" :rows="3" :maxlength="500" placeholder="请填写作废原因" />
        </FormItem>
      </Form>
      <div slot="footer">
        <Button @click="closeVoid">取消</Button>
        <Button type="error" :loading="voidSubmitting" @click="submitVoid">确认作废</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import OrganizationStoreScopePicker from '@/components/organization/OrganizationStoreScopePicker.vue';
import {
  createPresaleClaimApi,
  presaleClaimDetailApi,
  presaleClaimListApi,
  presaleClaimScopeApi,
  voidPresaleClaimApi
} from '@/api/stockManage';

function today() {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

function idempotencyKey(prefix) {
  const uuid = window.crypto && typeof window.crypto.randomUUID === 'function' ? window.crypto.randomUUID() : '';
  return `${prefix}-${uuid || `${Date.now()}-${Math.random().toString(36).slice(2, 12)}`}`;
}

function businessDate(value) {
  if (typeof value === 'string') return value;
  if (!(value instanceof Date) || Number.isNaN(value.getTime())) return '';
  const month = String(value.getMonth() + 1).padStart(2, '0');
  const day = String(value.getDate()).padStart(2, '0');
  return `${value.getFullYear()}-${month}-${day}`;
}

export default {
  name: 'PresaleClaimList',
  components: { OrganizationStoreScopePicker },
  data() {
    return {
      filters: { page: 1, limit: 20, keyword: '', status: '', store_ids: [] },
      rows: [], total: 0, loading: false,
      activeLine: null, claimModalOpen: false, claimSubmitting: false,
      claimForm: { quantity: 1, business_date: today() },
      detailModalOpen: false, detailLoading: false, detail: { claimable: null, claims: [] },
      activeClaim: null, voidModalOpen: false, voidSubmitting: false, voidForm: { reason: '' },
      columns: [
        { title: '预售订单', key: 'presale_order_no', minWidth: 150 },
        { title: '销售日期', key: 'sales_date', width: 105 },
        { title: '组织', key: 'organization_name', minWidth: 120 },
        { title: '门店', key: 'store_name', minWidth: 120 },
        { title: '会员', slot: 'member', minWidth: 130 },
        { title: '商品', key: 'product_name', minWidth: 160 },
        { title: '预售数量', slot: 'quantity', width: 90, align: 'right' },
        { title: '已领用', slot: 'claimed', width: 82, align: 'right' },
        { title: '未领用', slot: 'unclaimed', width: 82, align: 'right' },
        { title: '状态', slot: 'status', width: 105, align: 'center' },
        { title: '操作', slot: 'action', width: 145, fixed: 'right' }
      ],
      detailColumns: [
        { title: '领用出库单', key: 'claim_no', minWidth: 175 },
        { title: '领用数量', key: 'quantity', width: 100, align: 'right' },
        { title: '状态', slot: 'detail_status', width: 95, align: 'center' },
        { title: '操作人', key: 'operator_name', minWidth: 110 },
        { title: '领用时间', slot: 'occurred', minWidth: 160 },
        { title: '作废时间', slot: 'voided', minWidth: 160 },
        { title: '作废原因', key: 'void_reason', minWidth: 150 },
        { title: '操作', slot: 'detail_action', width: 100, fixed: 'right' }
      ]
    };
  },
  created() { this.load(); },
  methods: {
    loadPresaleClaimScope() { return presaleClaimScopeApi(); },
    onScopeChange() { this.search(); },
    statusLabel(status) { return ({ AVAILABLE: '可领用', FULLY_CLAIMED: '已全部领用', CLOSED: '已关闭' })[status] || '-'; },
    statusColor(status) { return ({ AVAILABLE: 'green', FULLY_CLAIMED: 'blue', CLOSED: 'default' })[status] || 'default'; },
    claimStatusLabel(status) { return status === 'SETTLED' ? '已领用' : status === 'VOIDED' ? '已作废' : '-'; },
    claimStatusColor(status) { return status === 'SETTLED' ? 'green' : status === 'VOIDED' ? 'default' : 'default'; },
    formatTime(timestamp) {
      const seconds = Number(timestamp || 0);
      if (!seconds) return '-';
      const date = new Date(seconds * 1000);
      const pad = (value) => String(value).padStart(2, '0');
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    },
    async load() {
      this.loading = true;
      try {
        const result = await presaleClaimListApi(this.filters);
        const data = result.data || result || {};
        this.rows = Array.isArray(data.list) ? data.list : [];
        this.total = Number(data.count || 0);
      } catch (error) {
        this.$Message.error((error && error.msg) || (error && error.message) || '预售领用列表加载失败');
      } finally { this.loading = false; }
    },
    search() { this.filters.page = 1; this.load(); },
    reset() {
      this.filters = { page: 1, limit: 20, keyword: '', status: '', store_ids: [] };
      this.$nextTick(() => this.$refs.scopePicker && this.$refs.scopePicker.reset());
      this.load();
    },
    changePage(page) { this.filters.page = page; this.load(); },
    openClaim(line) {
      this.activeLine = line;
      this.claimForm = { quantity: 1, business_date: today() };
      this.claimModalOpen = true;
    },
    closeClaim() { if (!this.claimSubmitting) this.claimModalOpen = false; },
    async submitClaim() {
      if (!this.activeLine) return;
      const quantity = Number(this.claimForm.quantity || 0);
      if (!Number.isInteger(quantity) || quantity < 1 || quantity > Number(this.activeLine.unclaimed_quantity || 0)) {
        this.$Message.warning('领用数量必须在未领用数量范围内');
        return;
      }
      const date = businessDate(this.claimForm.business_date);
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) { this.$Message.warning('请选择领用日期'); return; }
      this.claimSubmitting = true;
      try {
        await createPresaleClaimApi({
          store_id: this.activeLine.store_id,
          claimable_line_id: this.activeLine.claimable_line_id,
          quantity,
          business_date: date,
          idempotency_key: idempotencyKey('presale-claim')
        });
        this.$Message.success('预售商品已领用并生成出库单');
        this.claimModalOpen = false;
        await this.load();
      } catch (error) {
        this.$Message.error((error && error.msg) || (error && error.message) || '领用失败');
      } finally { this.claimSubmitting = false; }
    },
    async openDetail(line) {
      this.activeLine = line;
      this.detailModalOpen = true;
      this.detailLoading = true;
      this.detail = { claimable: null, claims: [] };
      try {
        const result = await presaleClaimDetailApi(line.claimable_line_id);
        this.detail = result.data || result || { claimable: null, claims: [] };
      } catch (error) {
        this.$Message.error((error && error.msg) || (error && error.message) || '领用明细加载失败');
      } finally { this.detailLoading = false; }
    },
    closeDetail() { this.detailModalOpen = false; },
    openVoid(claim) {
      this.activeClaim = claim;
      this.voidForm = { reason: '' };
      this.voidModalOpen = true;
    },
    closeVoid() { if (!this.voidSubmitting) this.voidModalOpen = false; },
    async submitVoid() {
      const reason = String(this.voidForm.reason || '').trim();
      if (reason.length < 2) { this.$Message.warning('请填写至少两个字的作废原因'); return; }
      if (!this.activeClaim || !this.activeLine) return;
      this.voidSubmitting = true;
      try {
        await voidPresaleClaimApi(this.activeClaim.claim_id, {
          store_id: this.activeLine.store_id,
          reason,
          idempotency_key: idempotencyKey('presale-claim-void')
        });
        this.$Message.success('领用已作废，库存已按原批次退回');
        this.voidModalOpen = false;
        await this.openDetail(this.activeLine);
        await this.load();
      } catch (error) {
        this.$Message.error((error && error.msg) || (error && error.message) || '作废失败');
      } finally { this.voidSubmitting = false; }
    }
  }
};
</script>

<style scoped>
.presale-claim-page { min-width: 1060px; }
.filter-select { width: 140px; }
.filter-keyword { width: 230px; }
.cell-subtle { margin-top: 2px; color: #808695; font-size: 12px; }
.page-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
.action-gap { margin-left: 12px; }
.disabled-action { color: #c5c8ce; cursor: not-allowed; }
.detail-loading { min-height: 140px; padding-top: 48px; text-align: center; color: #808695; }
.ml14 { margin-left: 14px; }
.enter-key { margin-left: 2px; font-weight: 600; }
</style>
