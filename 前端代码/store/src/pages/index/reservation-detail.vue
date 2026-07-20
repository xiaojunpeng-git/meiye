<template>
  <div class="detail-page">
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="head">
        <Button type="text" icon="ios-arrow-back" @click="goBack">返回经营看板</Button>
        <div class="title">经营明细 · 预约客</div>
        <div class="meta">
          <span>统计时间：{{ queryData || '-' }}</span>
          <span class="ml">合计：{{ total }} 人</span>
        </div>
      </div>
      <Alert type="info" show-icon class="ivu-mt">
        本页按预约客口径服务端分页；合计人数与经营看板「预约客数」卡片一致，不是订单列表。
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
          :total="total"
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
import { businessDashboardReservationDetail } from '@/api/index';

export default {
  name: 'store_business_reservation_detail',
  data() {
    return {
      loading: false,
      list: [],
      total: 0,
      page: 1,
      limit: 20,
      queryData: '',
      columns: [
        { title: '客户', key: 'real_name', minWidth: 120 },
        { title: '手机号', key: 'phone', minWidth: 120 },
        { title: '门店', key: 'store_name', minWidth: 140 },
        { title: '最早预约时间', key: 'earliest_time_text', minWidth: 160 },
        { title: '客户ID', key: 'uid', width: 90 },
      ],
    };
  },
  created() {
    this.applyQuery();
    this.loadList();
  },
  methods: {
    applyQuery() {
      const q = this.$route.query || {};
      this.queryData = q.data || '';
    },
    params() {
      return {
        data: this.queryData,
        page: this.page,
        limit: this.limit,
      };
    },
    loadList() {
      this.loading = true;
      businessDashboardReservationDetail(this.params())
        .then((res) => {
          this.list = (res.data && res.data.list) || [];
          this.total = Number((res.data && res.data.count) || 0);
        })
        .catch((err) => {
          this.list = [];
          this.total = 0;
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
          metric: 'reservation_customer',
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
