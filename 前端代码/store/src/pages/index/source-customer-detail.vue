<template>
  <div class="detail-page">
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="head">
        <Button type="text" icon="ios-arrow-back" @click="goBack">返回经营看板</Button>
        <div class="title">经营明细 · {{ metricName }}</div>
        <div class="meta">
          <span>统计时间：{{ queryData || '-' }}</span>
          <span class="ml">合计：{{ total }} 人</span>
        </div>
      </div>
      <Alert type="info" show-icon class="ivu-mt">
        合计人数与销售数据表散客/新客口径一致；下列为同条件订单明细，不是伪装订单业绩页。
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
          :total="listTotal"
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
import { businessDashboardSourceCustomerDetail } from '@/api/index';

export default {
  name: 'store_business_source_customer_detail',
  data() {
    return {
      loading: false,
      list: [],
      total: 0,
      listTotal: 0,
      page: 1,
      limit: 20,
      queryData: '',
      metric: 'casual_customer_count',
      columns: [
        { title: '订单号', key: 'order_id', minWidth: 160 },
        { title: '客户', key: 'real_name', minWidth: 100 },
        { title: '手机号', key: 'phone', minWidth: 120 },
        { title: '门店', key: 'store_name', minWidth: 120 },
        { title: '服务对象', key: 'service_object', minWidth: 90 },
        { title: '下单时间', key: 'add_time_text', minWidth: 160 },
        { title: '实付', key: 'pay_price', width: 90 },
      ],
    };
  },
  computed: {
    metricName() {
      return this.metric === 'new_customer_count' ? '新客数量' : '散客数量';
    },
  },
  created() {
    this.applyQuery();
    this.loadList();
  },
  methods: {
    applyQuery() {
      const q = this.$route.query || {};
      this.queryData = q.data || '';
      this.metric = q.metric === 'new_customer_count' ? 'new_customer_count' : 'casual_customer_count';
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
      businessDashboardSourceCustomerDetail(this.params())
        .then((res) => {
          this.list = (res.data && res.data.list) || [];
          this.total = Number((res.data && res.data.count) || 0);
          this.listTotal = Number((res.data && res.data.list_count) || this.list.length);
        })
        .catch((err) => {
          this.list = [];
          this.total = 0;
          this.listTotal = 0;
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
.page-wrap { margin-top: 16px; text-align: right; }
</style>
