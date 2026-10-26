<template>
  <div class="form-submit">
    <div class="i-layout-page-header">
      <PageHeader class="product_tabs" hidden-breadcrumb>
        <div slot="title">
          <router-link :to="{ path: `${roterPre}/marketing/store_seckill/index` }">
            <div class="font-sm after-line">
              <span class="iconfont iconfanhui"></span>
              <span class="pl10">返回</span>
            </div>
          </router-link>
          <span v-text="$route.meta.title" class="mr20 ml16"></span>
        </div>
      </PageHeader>
    </div>
    <cards-data :cardLists="cardLists" v-if="cardLists.length >= 0"></cards-data>
    <Card :bordered="false" dis-hover>
      <Tabs v-model="currentTab" @on-click="onClickTab">
        <TabPane v-for="(item, index) in tabs" :label="item.label" :name="item.type" :key="index" />
      </Tabs>
      <Form
          ref="pagination"
          :model="pagination"
          :label-width="labelWidth"
          inline
          :label-position="labelPosition"
          @submit.native.prevent
      >
        <FormItem label="订单状态：" label-for="status" v-if="type == 1">
          <Select v-model="pagination.status" placeholder="请选择订单状态" class="input-add">
            <Option value="">全部</Option>
            <Option value="0">未支付</Option>
            <Option value="1">待发货</Option>
            <Option value="2">待收货</Option>
            <Option value="3">待评价</Option>
            <Option value="4">交易完成</Option>
          </Select>
        </FormItem>
        <FormItem label="搜索：" label-for="title">
          <Input
              class="input-add"
              search
              enter-button
              v-model="pagination.real_name"
              placeholder="请输入用户姓名/手机号/UID"
              @on-search="searchList"
          />
        </FormItem>
      </Form>
      <Table
          :columns="type ? thead2 : thead"
          :data="tbody"
          ref="table"
          :loading="loading"
          highlight-row
          no-userFrom-text="暂无数据"
          no-filtered-userFrom-text="暂无筛选结果"
      >
      </Table>
      <div class="acea-row row-right page">
        <Page
            :total="total"
            :current="pagination.page"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="pagination.limit"
        />
      </div>
    </Card>
  </div>
</template>

<script>
import cardsData from '@/components/cards/cards';
import { getseckillStatistics, getseckillStatisticsPeople, getseckillStatisticsOrder } from '@/api/marketing';
import Setting from "@/setting";
import {mapState} from "vuex";
export default {
  name: 'index',
  components: { cardsData },
  data() {
    return {
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      roterPre: Setting.roterPre,
      id: 0,
      tbody: [],
      total: 0,
      tabs: [
        {
          type: '',
          label: '活动参与人',
        },
        {
          type: '',
          label: '活动订单',
        },
      ],
      currentTab: 0,
      loading: false,
      thead: [
        {
          title: '用户姓名',
          key: 'real_name',
        },
        {
          title: '购买件数',
          key: 'goods_num',
        },
        {
          title: '支付订单数',
          key: 'order_num',
        },
        {
          title: '支付金额',
          key: 'total_price',
        },
        {
          title: '最近参与时间',
          key: 'add_time',
        },
      ],
      thead2: [
        {
          title: '订单号',
          key: 'order_id',
        },
        {
          title: '用户',
          key: 'real_name',
        },
        {
          title: '订单状态',
          key: 'status',
        },
        {
          title: '订单支付金额',
          key: 'pay_price',
        },
        {
          title: '订单商品数',
          key: 'total_num',
        },
        {
          title: '下单时间',
          key: 'add_time',
        },
        {
          title: '支付时间',
          key: 'pay_time',
        },
      ],
      cardLists: [
        {
          col: 6,
          count: 0,
          name: '下单人数（人）',
          className: 'iconxiadanrenshu',
          type: true
        },
        {
          col: 6,
          count: 0,
          name: '支付订单额（元）',
          className: 'iconzhifudingdan',
          type: true
        },
        {
          col: 6,
          count: 0,
          name: '支付人数（人）',
          className: 'iconzhifurenshu',
          type: true
        },
        {
          col: 6,
          count: 0,
          name: '剩余库存/总库存',
          className: 'iconshengyukucun',
          type: true
        },
      ],
      pagination: {
        page: 1,
        limit: 15,
        real_name: '',
        status: '',
      },
      type: 0,
    };
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 96;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
  },
  created() {
    this.id = this.$route.params.id;
    this.getStatistics(this.id);
    this.getList(this.id);
  },
  methods: {
    // 统计
    getStatistics(id) {
      getseckillStatistics(id).then((res) => {
        let arr = ['order_count', 'all_price', 'pay_count', 'pay_rate'];
        this.cardLists.map((i, index) => {
          i.count = res.data[arr[index]];
        });
      });
    },
    // 列表
    getList(id) {
      this.loading = true;
      if (this.type == 0) {
        getseckillStatisticsPeople(this.id, this.pagination).then((res) => {
          this.loading = false;
          const { count, list } = res.data;
          this.total = count;
          this.tbody = list;
        });
      } else {
        getseckillStatisticsOrder(this.id, this.pagination).then((res) => {
          this.loading = false;
          const { count, list } = res.data;
          this.total = count;
          this.tbody = list;
        });
      }
    },
    // 标签切换
    onClickTab() {
      if (this.type != this.currentTab) {
        this.pagination.real_name = '';
        this.pagination.status = '';
        this.pagination.page = 1;
      }
      this.type = this.currentTab;
      this.getList(this.id);
    },
    // 搜索
    searchList() {
      this.pagination.page = 1;
      this.getList(this.id);
    },
    // 分页
    pageChange(index) {
      this.pagination.page = index;
      this.getList(this.id);
    },
  },
};
</script>

<style scoped></style>