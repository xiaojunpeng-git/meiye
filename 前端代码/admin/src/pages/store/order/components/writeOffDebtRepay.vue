<template>
  <Modal
    v-model="visible"
    title="欠款补交"
    width="720"
    :mask-closable="false"
    @on-cancel="handleClose"
  >
    <Table :columns="columns" :data="items" :loading="loading" border />
    <div slot="footer">
      <Button @click="handleClose">关闭</Button>
    </div>
  </Modal>
</template>

<script>
import { debtOrderRepayItemsApi, debtRepayPayApi } from '@/api/debt';

export default {
  name: 'writeOffDebtRepay',
  data() {
    return {
      visible: false,
      loading: false,
      orderId: 0,
      debtId: 0,
      items: [],
      columns: [
        { title: '项目', key: 'product_name', minWidth: 180 },
        { title: '欠款金额', key: 'debt_amount', width: 100, render: (h, { row }) => h('span', `¥${Number(row.debt_amount || 0).toFixed(2)}`) },
        { title: '待还', key: 'pending_debt', width: 100, render: (h, { row }) => h('span', { style: { color: '#ed4014' } }, `¥${Number(row.pending_debt || 0).toFixed(2)}`) },
        {
          title: '操作',
          key: 'action',
          width: 120,
          align: 'center',
          render: (h, { row }) => h('Button', {
            props: { type: 'primary', size: 'small' },
            on: { click: () => this.repayItem(row) },
          }, '补交'),
        },
      ],
    };
  },
  methods: {
    open(orderId) {
      this.orderId = Number(orderId || 0);
      this.visible = true;
      this.loadItems();
    },
    loadItems() {
      if (!this.orderId) return;
      this.loading = true;
      debtOrderRepayItemsApi(this.orderId).then((res) => {
        this.debtId = res.data.debt_id || 0;
        this.items = res.data.items || [];
      }).catch((err) => {
        this.$Message.error(err.msg || '加载欠款失败');
      }).finally(() => {
        this.loading = false;
      });
    },
    repayItem(row) {
      const amount = Number(row.pending_debt || 0);
      if (amount <= 0) return;
      this.$Modal.confirm({
        title: '确认补交',
        content: `确认现金补交「${row.product_name}」¥${amount.toFixed(2)}？补交后可恢复对应核销次数。`,
        onOk: () => debtRepayPayApi({
          debt_id: this.debtId,
          debt_item_id: row.debt_item_id || row.id,
          repay_amount: amount,
          pay_type: 'cash',
        }).then((res) => {
          this.$Message.success(res.msg || '补交成功');
          this.loadItems();
          this.$emit('repaid');
        }).catch((err) => {
          this.$Message.error(err.msg || '补交失败');
        }),
      });
    },
    handleClose() {
      this.visible = false;
    },
  },
};
</script>
