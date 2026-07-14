<template>
  <Modal
    v-model="visible"
    title="欠款提醒"
    width="900"
    :mask-closable="false"
    class-name="debt-reminder-modal"
    @on-cancel="handleCancel"
  >
    <div class="debt-reminder-head" v-if="summary">
      客户<span class="name">{{ userName }}</span>，待还款总额
      <span class="amount">¥ {{ Number(summary.total_pending || 0).toFixed(2) }}</span>
    </div>
    <Table :columns="columns" :data="list" :loading="loading" size="small">
      <template slot-scope="{ row }" slot="product">
        <span>{{ row.product_name }}</span>
        <Tag v-if="row.product_type === 4 || row.product_type === 5" color="default" class="ml-6">充值卡</Tag>
      </template>
      <template slot-scope="{ row }" slot="pending">
        <span class="pending-amount">¥{{ Number(row.pending_debt || 0).toFixed(2) }}</span>
      </template>
      <template slot-scope="{ row }" slot="action">
        <a @click="$emit('repay', row)">还款</a>
      </template>
    </Table>
    <div class="acea-row row-right page mt-10" v-if="total > limit">
      <Page
        :total="total"
        :current.sync="page"
        :page-size="limit"
        show-total
        @on-change="loadList"
      />
    </div>
    <div slot="footer">
      <Button @click="handleCancel">取消</Button>
      <Button type="primary" @click="$emit('records')">欠款记录</Button>
    </div>
  </Modal>
</template>

<script>
import { debtReminderApi } from '@/api/debt';

export default {
  name: 'debtReminder',
  props: {
    value: { type: Boolean, default: false },
    uid: { type: Number, default: 0 },
    userName: { type: String, default: '' },
  },
  data() {
    return {
      loading: false,
      list: [],
      total: 0,
      page: 1,
      limit: 5,
      summary: null,
      columns: [
        { title: '订单号', key: 'order_sn', minWidth: 180 },
        { title: '欠款时间', key: 'add_time_label', minWidth: 140 },
        { title: '欠款门店', key: 'store_name', minWidth: 140 },
        { title: '欠款商品', slot: 'product', minWidth: 160 },
        { title: '欠款金额', slot: 'pending', minWidth: 100 },
        { title: '操作', slot: 'action', width: 80 },
      ],
    };
  },
  computed: {
    visible: {
      get() { return this.value; },
      set(v) { this.$emit('input', v); },
    },
  },
  watch: {
    value(val) {
      if (val && this.uid) {
        this.page = 1;
        this.loadList();
      }
    },
  },
  methods: {
    loadList() {
      if (!this.uid) return;
      this.loading = true;
      debtReminderApi({ uid: this.uid, page: this.page, limit: this.limit })
        .then((res) => {
          const data = res.data || {};
          this.list = data.list || [];
          this.total = data.count || 0;
          this.summary = { total_pending: data.total_pending || 0 };
        })
        .catch((err) => {
          this.$Message.error(err.msg || '加载失败');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.visible = false;
    },
  },
};
</script>

<style scoped>
.debt-reminder-head {
  margin-bottom: 16px;
  font-size: 14px;
  color: #303133;
}
.debt-reminder-head .name {
  margin: 0 4px;
  font-weight: 600;
}
.debt-reminder-head .amount {
  color: #ff4d7a;
  font-weight: 600;
}
.pending-amount {
  color: #ff4d7a;
}
</style>
