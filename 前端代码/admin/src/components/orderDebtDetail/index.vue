<template>
  <Modal
    v-model="visible"
    title="欠款明细"
    width="720"
    footer-hide
    class-name="order-debt-detail-modal"
  >
    <div v-if="loading" class="text-center p-30">加载中...</div>
    <template v-else-if="detail">
      <div class="debt-summary mb-16">
        <span>欠款单号：{{ detail.debt_no }}</span>
        <span class="ml-20">交易单号：{{ detail.order_sn }}</span>
        <span class="ml-20">状态：{{ detail.status_label }}</span>
      </div>
      <div class="debt-amount-row mb-16">
        <span>欠款总额 <b>¥{{ formatMoney(detail.total_debt) }}</b></span>
        <span class="ml-20 green">已还款 ¥{{ formatMoney(detail.repaid_debt) }}</span>
        <span class="ml-20 pink">待还款 ¥{{ formatMoney(detail.pending_debt) }}</span>
      </div>
      <Table :columns="columns" :data="detail.items || []" size="small">
        <template slot-scope="{ row }" slot="debt">
          <span>¥{{ formatMoney(row.debt_amount) }}</span>
        </template>
        <template slot-scope="{ row }" slot="repaid">
          <span class="green">¥{{ formatMoney(row.repaid_debt) }}</span>
        </template>
        <template slot-scope="{ row }" slot="pending">
          <span class="pink">¥{{ formatMoney(row.pending_debt) }}</span>
        </template>
      </Table>
    </template>
    <div v-else class="text-center p-30 text-muted">暂无欠款数据</div>
  </Modal>
</template>

<script>
import { debtOrderDetailApi } from '@/api/debt';

export default {
  name: 'orderDebtDetail',
  data() {
    return {
      visible: false,
      loading: false,
      detail: null,
      columns: [
        { title: '商品名称', key: 'product_name', minWidth: 180 },
        { title: '数量', key: 'cart_num', width: 70 },
        { title: '欠款金额', slot: 'debt', width: 100 },
        { title: '已还款', slot: 'repaid', width: 100 },
        { title: '待还款', slot: 'pending', width: 100 },
      ],
    };
  },
  methods: {
    formatMoney(val) {
      return Number(val || 0).toFixed(2);
    },
    open(orderRow) {
      const orderId = Number(orderRow.id || orderRow.oid || 0);
      if (!orderId) {
        this.$Message.warning('订单信息不完整');
        return;
      }
      this.visible = true;
      this.loading = true;
      this.detail = null;
      debtOrderDetailApi(orderId)
        .then((res) => {
          this.detail = res.data || null;
        })
        .catch((err) => {
          this.$Message.error(err.msg || '加载失败');
          this.visible = false;
        })
        .finally(() => {
          this.loading = false;
        });
    },
  },
};
</script>

<style scoped>
.debt-summary {
  font-size: 13px;
  color: #606266;
}
.debt-amount-row {
  font-size: 14px;
}
.green { color: #19be6b; }
.pink { color: #ff4d7a; }
.text-muted { color: #909399; }
</style>
