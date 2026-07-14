<template>
  <div>
    <div class="store-filter mb-10">
      <span class="store-filter-label">选择门店：</span>
      <Select
        v-model="storeId"
        clearable
        filterable
        class="store-filter-select"
        @on-change="reload"
      >
        <Option :value="0">全部门店</Option>
        <Option v-for="item in storeList" :key="item.id" :value="item.id">{{ item.name }}</Option>
      </Select>
    </div>
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
    <debt-repay
      v-model="repayVisible"
      :row="repayRow"
      :store-name="repayStoreName"
      @pay="onRepayPay"
    />
  </div>
</template>

<script>
import { debtUserListApi, debtRepayListApi, debtFilterStoresApi } from '@/api/debt';
import debtRepay from '@/components/debtRepay';
import util from '@/libs/util';

export default {
  name: 'userDebtRecord',
  components: { debtRepay },
  props: {
    uid: { type: Number, default: 0 },
    storeName: { type: String, default: '' },
    mode: {
      type: String,
      default: 'debt',
      validator: (val) => ['debt', 'repay'].includes(val),
    },
  },
  data() {
    return {
      loading: false,
      storeId: 0,
      storeList: [],
      list: [],
      total: 0,
      page: 1,
      limit: 20,
      columns: [],
      repayVisible: false,
      repayRow: {},
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
  computed: {
    repayStoreName() {
      return this.storeName || util.cookies.get('pageTitle') || '';
    },
  },
  methods: {
    loadStoreList() {
      if (!this.uid) return;
      debtFilterStoresApi(this.uid).then((res) => {
        this.storeList = res.data || [];
      }).catch(() => {
        this.storeList = [];
      });
    },
    reload(force) {
      this.page = 1;
      if (force) {
        this.list = [];
        this.total = 0;
      }
      this.loadStoreList();
      this.load(force);
    },
    canRepay(row) {
      return this.mode === 'debt'
        && Number(row.status) === 0
        && Number(row.pending_debt || 0) > 0;
    },
    openRepay(row) {
      const pendingItems = (row.items || []).filter((item) => Number(item.pending_debt || 0) > 0);
      const debtItemId = pendingItems.length === 1
        ? Number(pendingItems[0].id || 0)
        : Number(row.debt_item_id || 0);
      const targetItem = pendingItems.length === 1 ? pendingItems[0] : null;
      const pendingDebt = targetItem
        ? Number(targetItem.pending_debt || 0)
        : Number(row.pending_debt || 0);
      this.repayRow = {
        debt_id: Number(row.debt_id || row.id || 0),
        debt_item_id: debtItemId,
        pending_debt: pendingDebt,
        order_sn: row.order_sn || '',
        original_source: Number(row.original_source || 0),
        staff_name: row.staff_name || '',
        product_id: Number((targetItem && targetItem.product_id) || row.product_id || 0),
        cart_id: Number((targetItem && targetItem.cart_id) || 0),
      };
      this.repayVisible = true;
    },
    onRepayPay(data) {
      this.$emit('repay', {
        ...data,
        original_source: data.original_source || this.repayRow.original_source || 0,
      });
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
        store_id: this.storeId || '',
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
.mb-10 { margin-bottom: 10px; }
.mt-10 { margin-top: 10px; }
.store-filter {
  display: flex;
  align-items: center;
}
.store-filter-label {
  flex-shrink: 0;
  white-space: nowrap;
  margin-right: 10px;
  color: #515a6e;
  font-size: 14px;
  line-height: 32px;
}
.store-filter-select {
  width: 220px;
}
.sub { color: #909399; font-size: 12px; }
.green { color: #19be6b; }
.pink { color: #ff4d7a; }
.repay-link { color: #6c5ce7; }
</style>
