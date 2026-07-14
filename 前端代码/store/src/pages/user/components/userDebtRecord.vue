<template>
  <div>
    <Table :columns="columns" :data="list" :loading="loading" size="small">
      <template slot-scope="{ row }" slot="order">
        <div>{{ row.order_sn }}</div>
        <div class="sub">{{ row.add_time_label }}</div>
      </template>
      <template slot-scope="{ row }" slot="product">
        <span>{{ (row.product_names && row.product_names[0]) || row.product_name || '-' }}</span>
      </template>
      <template slot-scope="{ row }" slot="repaid">
        <span class="green">¥{{ Number(row.repaid_debt || row.repay_amount || 0).toFixed(2) }}</span>
      </template>
      <template slot-scope="{ row }" slot="pending">
        <span class="pink">¥{{ Number(row.pending_debt || 0).toFixed(2) }}</span>
      </template>
      <template slot-scope="{ row }" slot="action">
        <a
          v-if="canRepay(row)"
          class="repay-link"
          @click="openRepay(row)"
        >还款</a>
        <span v-else class="sub">-</span>
      </template>
    </Table>
    <div class="acea-row row-right page mt-10">
      <Page :total="total" :current.sync="page" :page-size="limit" show-total @on-change="load" />
    </div>
    <debt-repay-flow
      ref="debtRepayFlow"
      :repay-modal-z-index="2000"
      @success="onRepaySuccess"
    />
  </div>
</template>

<script>
import { debtUserListApi, debtRepayListApi } from '@/api/debt';
import debtRepayFlow from '@/components/debtRepayFlow';

export default {
  name: 'userDebtRecord',
  components: { debtRepayFlow },
  props: {
    uid: { type: Number, default: 0 },
    mode: {
      type: String,
      default: 'debt',
      validator: (val) => ['debt', 'repay'].includes(val),
    },
  },
  data() {
    return {
      loading: false,
      list: [],
      total: 0,
      page: 1,
      limit: 20,
      columns: [],
    };
  },
  watch: {
    uid(val) {
      if (val) {
        this.reload(true);
      }
    },
    mode() {
      this.reload();
    },
  },
  mounted() {
    if (this.uid) {
      this.reload(true);
    }
  },
  methods: {
    reload(force) {
      this.page = 1;
      if (force) {
        this.list = [];
        this.total = 0;
      }
      this.load(force);
    },
    canRepay(row) {
      return this.mode === 'debt'
        && Number(row.status) === 0
        && Number(row.pending_debt || 0) > 0;
    },
    openRepay(row) {
      if (this.$refs.debtRepayFlow) {
        this.$refs.debtRepayFlow.open({ ...row, uid: this.uid || row.uid });
      }
    },
    onRepaySuccess() {
      this.reload(true);
      this.$emit('repaid');
    },
    setColumns() {
      if (this.mode === 'debt') {
        this.columns = [
          { title: '订单号/欠款时间', slot: 'order', minWidth: 180 },
          { title: '欠款商品', slot: 'product', minWidth: 140 },
          { title: '欠款总额', key: 'total_debt', minWidth: 100, render: (h, { row }) => h('span', `¥${Number(row.total_debt || 0).toFixed(2)}`) },
          { title: '已还款', slot: 'repaid', minWidth: 90 },
          { title: '待还款', slot: 'pending', minWidth: 90 },
          { title: '状态', key: 'status_label', width: 90 },
          { title: '欠款门店', key: 'store_name', minWidth: 120 },
          { title: '收银员', key: 'staff_name', minWidth: 100 },
          { title: '操作', slot: 'action', width: 80, align: 'center' },
        ];
      } else {
        this.columns = [
          { title: '订单号/还款时间', slot: 'order', minWidth: 180 },
          { title: '还款金额', slot: 'repaid', minWidth: 100 },
          { title: '支付方式', key: 'pay_type_label', minWidth: 120 },
          { title: '欠款门店', key: 'debt_store_name', minWidth: 120 },
          { title: '还款门店', key: 'pay_store_name', minWidth: 120 },
          { title: '收银员', key: 'staff_name', minWidth: 100 },
        ];
      }
    },
    load(force) {
      if (!this.uid) return;
      this.setColumns();
      this.loading = true;
      const params = {
        page: this.page,
        limit: this.limit,
      };
      if (force) {
        params._t = Date.now();
      }
      const api = this.mode === 'debt'
        ? debtUserListApi(this.uid, params)
        : debtRepayListApi({ ...params, uid: this.uid });
      api.then((res) => {
        this.list = res.data.list || [];
        this.total = res.data.count || 0;
      }).catch((err) => {
        this.$Message.error(err.msg || '加载失败');
      }).finally(() => {
        this.loading = false;
      });
    },
  },
};
</script>

<style scoped>
.mt-10 { margin-top: 10px; }
.sub { color: #909399; font-size: 12px; }
.green { color: #19be6b; }
.pink { color: #ff4d7a; }
.repay-link { color: #6c5ce7; }
</style>
