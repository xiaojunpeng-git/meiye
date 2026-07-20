<template>
  <div class="detail-page">
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="head">
        <Button type="text" icon="ios-arrow-back" @click="goBack">返回经营看板</Button>
        <div class="title">经营明细 · {{ name || metricName }}</div>
        <div class="meta">
          <span>统计时间：{{ queryData || '-' }}</span>
          <span class="ml total">合计：{{ formatMoney(total) }} 元</span>
        </div>
      </div>
      <Alert type="info" show-icon class="ivu-mt">
        本页为经营看板金额口径适配明细，合计必须与对应卡片一致；不是普通订单列表。
        <span v-if="breakdownText">分项：{{ breakdownText }}</span>
      </Alert>
      <Table
        class="ivu-mt"
        :columns="columns"
        :data="list"
        :loading="loading"
        no-data-text="暂无数据"
      />
      <div class="page-wrap">
        <Page
          :total="listCount"
          :current="page"
          :page-size="limit"
          show-total
          show-elevator
          @on-change="onPage"
        />
      </div>
    </Card>
  </div>
</template>

<script>
import { businessDashboardMoneyDetail } from '@/api/index';

const NAME_MAP = {
  cash_performance: '现金业绩',
  actual_performance: '实际业绩',
  consume_amount: '消耗业绩',
  refund_amount: '退款金额',
  recharge_amount: '储值金额',
  balance_deduction_amount: '余额扣款',
};

export default {
  name: 'store_business_money_detail',
  data() {
    return {
      loading: false,
      list: [],
      total: 0,
      listCount: 0,
      page: 1,
      limit: 20,
      queryData: '',
      metric: 'cash_performance',
      name: '',
      breakdown: {},
      columns: [],
    };
  },
  computed: {
    metricName() {
      return NAME_MAP[this.metric] || this.metric;
    },
    breakdownText() {
      const b = this.breakdown || {};
      const parts = [];
      if (b.valid_cash != null) parts.push(`有效现金 ${this.formatMoney(b.valid_cash)}`);
      if (b.old_cash != null) parts.push(`旧店现金 ${this.formatMoney(b.old_cash)}`);
      if (b.writeoff != null) parts.push(`核销消耗 ${this.formatMoney(b.writeoff)}`);
      if (b.old_consume != null) parts.push(`旧店耗卡 ${this.formatMoney(b.old_consume)}`);
      if (b.fencheng != null) parts.push(`分成 ${this.formatMoney(b.fencheng)}`);
      if (b.actual != null) parts.push(`实际 ${this.formatMoney(b.actual)}`);
      return parts.join('；');
    },
  },
  created() {
    this.applyQuery();
    this.columns = this.buildColumns();
    this.loadList();
  },
  methods: {
    formatMoney(v) {
      return Number(v || 0).toFixed(2);
    },
    applyQuery() {
      const q = this.$route.query || {};
      this.queryData = q.data || '';
      this.metric = q.metric || 'cash_performance';
    },
    buildColumns() {
      if (this.metric === 'actual_performance') {
        return [
          { title: '门店', key: 'store_name', minWidth: 140 },
          { title: '现金', key: 'cash_amount', minWidth: 100 },
          { title: '分成', key: 'fencheng_amount', minWidth: 100 },
          { title: '实际业绩', key: 'amount', minWidth: 110 },
          { title: '说明', key: 'remark', minWidth: 160 },
        ];
      }
      return [
        { title: '类型', key: 'line_type_text', width: 100 },
        { title: '单号/关联', key: 'order_id', minWidth: 140 },
        { title: '客户', key: 'real_name', minWidth: 100 },
        { title: '手机号', key: 'phone', minWidth: 120 },
        { title: '门店', key: 'store_name', minWidth: 120 },
        { title: '金额', key: 'amount', width: 100 },
        { title: '时间', key: 'biz_time_text', minWidth: 160 },
      ];
    },
    params() {
      return {
        data: this.queryData,
        metric: this.metric,
        page: this.page,
        limit: this.limit,
      };
    },
    loadList() {
      this.loading = true;
      businessDashboardMoneyDetail(this.params())
        .then((res) => {
          const d = res.data || {};
          this.list = d.list || [];
          this.total = Number(d.total || 0);
          this.listCount = Number(d.list_count || 0);
          this.name = d.name || '';
          this.breakdown = d.breakdown || {};
        })
        .catch((err) => {
          this.list = [];
          this.total = 0;
          this.listCount = 0;
          this.$Message.error((err && err.msg) || '加载失败');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    onPage(p) {
      this.page = p;
      this.loadList();
    },
    goBack() {
      this.$router.push({
        path: '/store/home/index',
        query: {
          data: this.queryData,
          metric: this.metric,
        },
      }).catch(() => {});
    },
  },
};
</script>

<style scoped lang="less">
.head { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
.title { font-weight: 600; font-size: 16px; }
.meta { color: #808695; margin-left: auto; }
.ml { margin-left: 16px; }
.total { color: #2d8cf0; font-weight: 600; }
.page-wrap { margin-top: 16px; text-align: right; }
</style>
