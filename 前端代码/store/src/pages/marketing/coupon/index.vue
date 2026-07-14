<template>
  <!-- 营销-优惠券列表 -->
  <div>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
        <Form
          ref="tableFrom"
          inline
          :model="tableFrom"
          :label-width="labelWidth"
          :label-position="labelPosition"
          @submit.native.prevent
        >
          <FormItem label="优惠券类型：">
            <Select
              v-model="tableFrom.coupon_type"
              placeholder="请选择"
              clearable
              @on-change="userSearchs"
              class="input-add"
            >
              <Option value="1">满减券</Option>
              <Option value="2">折扣券</Option>
            </Select>
          </FormItem>
          <FormItem label="适用类型：">
            <Select
              v-model="tableFrom.type"
              placeholder="请选择"
              clearable
              @on-change="userSearchs"
              class="input-add"
            >
              <Option value="0">通用券</Option>
              <Option value="1">品类券</Option>
              <Option value="2">商品券</Option>
              <Option value="3">品牌券</Option>
            </Select>
          </FormItem>
          <FormItem label="领取方式：">
            <Select
              v-model="tableFrom.receive_type"
              placeholder="请选择"
              clearable
              @on-change="userSearchs"
              class="input-add"
            >
              <Option value="1">手动领取</Option>
              <Option value="3">后台发放</Option>
            </Select>
          </FormItem>
          <FormItem label="是否开启：">
            <Select
              v-model="tableFrom.status"
              placeholder="请选择"
              clearable
              @on-change="userSearchs"
              class="input-add"
            >
              <Option value="1">开启</Option>
              <Option value="0">关闭</Option>
            </Select>
          </FormItem>
          <FormItem label="优惠券信息：">
            <Input
              v-model="tableFrom.coupon_title"
              placeholder="请输入优惠券名称/ID"
              @on-search="userSearchs"
              class="input-add"
            />
            <Button class="ml-14" type="primary" @click="userSearchs"
              >查询</Button
            >
            <Button class="ml-14" @click="reset">重置</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="new_tab">
        <!-- Tab栏切换 -->
        <Tabs @on-click="onTabsClick">
          <TabPane
            v-for="item in couponHeader"
            :key="item.is_status"
            :label="`${item.name}(${item.count})`"
            :name="`${item.is_status}`"
          />
        </Tabs>
      </div>
      <!-- 操作 -->
      <Button
        v-auth="['store-marketing-coupon-create']"
        type="primary"
        @click="add"
        >添加优惠券</Button
      >
      <!-- 优惠券列表-表格 -->
      <Table
        :columns="columns1"
        :data="tableList"
        ref="table"
        class="ivu-mt"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="coupon_price">
          <span v-if="row.coupon_type == 1">{{ row.coupon_price }}元</span>
          <span v-if="row.coupon_type == 2"
            >{{ parseFloat(row.coupon_price) / 10 }}折（{{
              row.coupon_price.toString().split('.')[0]
            }}%）</span
          >
        </template>
        <template slot-scope="{ row }" slot="count">
          <span v-if="row.is_permanent">不限量</span>
          <div v-else>
            <span class="fa">发布：{{ row.total_count }}</span>
            <span class="sheng">剩余：{{ row.remain_count }}</span>
          </div>
        </template>
        <template slot-scope="{ row }" slot="coupon_type">
          <span v-if="row.coupon_type === 1">满减券</span>
          <span v-else>折扣券</span>
        </template>
        <template slot-scope="{ row }" slot="type">
          <span v-if="row.type === 1">品类券</span>
          <span v-else-if="row.type === 2">商品券</span>
          <span v-else-if="row.type === 3">品牌券</span>
          <span v-else>通用券</span>
        </template>
        <template slot-scope="{ row }" slot="coupon_title">
          <Tooltip max-width="200" placement="bottom">
            <span class="line2">{{ row.coupon_title }}</span>
            <p slot="content">{{ row.coupon_title }}</p>
          </Tooltip>
        </template>
        <template slot-scope="{ row }" slot="receive_type">
          <span v-if="row.receive_type === 1">手动领取</span>
          <span v-else-if="row.receive_type === 3">后台发放</span>
        </template>
        <template slot-scope="{ row }" slot="start_time">
          <div v-if="row.start_time">
            <div>{{ row.start_time | formatDate }} -</div>
            <div>{{ row.end_time | formatDate }}</div>
          </div>
          <span v-else>不限时</span>
        </template>
        <template slot-scope="{ row }" slot="start_use_time">
          <div v-if="row.start_use_time">
            <div>{{ row.start_use_time | formatDate }} -</div>
            <div>{{ row.end_use_time | formatDate }}</div>
          </div>
          <div v-else>{{ row.coupon_time }}天</div>
        </template>
        <template slot-scope="{ row }" slot="status">
          <i-switch
            v-model="row.status"
            :value="row.status"
            :true-value="1"
            :false-value="0"
            size="large"
            @on-change="openChange(row)"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <template v-if="tableFrom.is_status == 1">
            <a @click="details(row)">详情</a>
            <Divider type="vertical" />
            <a @click="receive(row)">领取记录</a>
            <Divider type="vertical" />
            <Dropdown @on-click="changeMenu(row, $event, index)">
              <a href="javascript:void(0)">
                更多
                <Icon type="ios-arrow-down"></Icon>
              </a>
              <DropdownMenu slot="list">
                <DropdownItem name="1">复制</DropdownItem>
                <DropdownItem name="2">删除</DropdownItem>
              </DropdownMenu>
            </Dropdown>
          </template>
          <template v-else>
            <a @click="edit(row)">编辑</a>
            <Divider type="vertical" />
            <a @click="couponDel(row, '删除优惠券', index)">删除</a>
          </template>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="tableFrom.page"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="tableFrom.limit"
        />
      </div>
    </Card>
    <!-- 领取记录 -->
    <Modal
      v-model="modals2"
      scrollable
      footer-hide
      closable
      title="领取记录"
      :mask-closable="false"
      width="700"
    >
      <Table
        :columns="columns2"
        :data="receiveList"
        ref="table"
        :loading="loading2"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
        :max-height="600"
      >
        <template slot-scope="{ row, index }" slot="avatar">
          <viewer>
            <div class="tabBox_img">
              <img v-lazy="row.avatar" />
            </div>
          </viewer>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total2"
          show-elevator
          show-total
          @on-change="receivePageChange"
          :page-size="receiveFrom.limit"
        />
      </div>
    </Modal>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import {
  releasedListApi,
  releasedissueLogApi,
  couponStatusApi,
  couponHeaderApi,
} from '@/api/marketing';
import { formatDate } from '@/utils/validate';
import Setting from '@/setting';
const columns1 = [
  {
    title: 'ID',
    key: 'id',
    width: 80,
  },
  {
    title: '优惠券名称',
    slot: 'coupon_title',
    minWidth: 150,
  },
  {
    title: '优惠券类型',
    slot: 'coupon_type',
    minWidth: 70,
  },
  {
    title: '适用类型',
    slot: 'type',
    minWidth: 70,
  },
  {
    title: '面值',
    slot: 'coupon_price',
    minWidth: 100,
  },
  {
    title: '领取方式',
    slot: 'receive_type',
    minWidth: 70,
  },
  {
    title: '领取时间',
    slot: 'start_time',
    minWidth: 130,
  },
  {
    title: '使用时间',
    slot: 'start_use_time',
    minWidth: 130,
  },
  {
    title: '发布数量',
    slot: 'count',
    minWidth: 70,
  },
  {
    title: '可领取数量（每人）',
    minWidth: 110,
    render: (h, params) => {
      return h(
        'div',
        params.row.receive_type == 3 || params.row.is_claimed == 1
          ? '不限量'
          : params.row.quantity_count
      );
    },
  },
  {
    title: '是否开启',
    slot: 'status',
    minWidth: 90,
  },
  {
    title: '拒绝原因',
    key: 'refusal',
    minWidth: 90,
  },
  {
    title: '强制下架原因',
    key: 'refusal',
    minWidth: 90,
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    minWidth: 180,
  },
];
export default {
  name: 'storeCouponIssue',
  filters: {
    formatDate(time) {
      if (time !== 0) {
        let date = new Date(time * 1000);
        return formatDate(date, 'yyyy-MM-dd hh:mm');
      }
    },
  },
  data() {
    return {
      routePre: Setting.routePre,
      modals2: false,
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      loading: false,
      columns1: [],
      tableFrom: {
        is_status: '',
        receive_type: '',
        coupon_type: '',
        type: '',
        status: '',
        coupon_title: '',
        page: 1,
        limit: 15,
      },
      tableList: [],
      total: 0,
      receiveList: [],
      loading2: false,
      columns2: [
        {
          title: 'ID',
          key: 'uid',
          minWidth: 80,
        },
        {
          title: '用户名',
          key: 'nickname',
          minWidth: 150,
        },
        {
          title: '用户头像',
          slot: 'avatar',
          minWidth: 100,
        },
        {
          title: '领取时间',
          key: 'add_time',
          minWidth: 140,
        },
      ],
      total2: 0,
      receiveFrom: {
        page: 1,
        limit: 15,
      },
      rows: {},
      couponHeader: [],
    };
  },
  created() {
    this.getCouponHeader();
  },
  computed: {
    ...mapState('store/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 96;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  watch: {
    'tableFrom.is_status'(value) {
      const filterMap = {
        1: (item) => item.key !== 'refusal',
        0: (item) => item.key !== 'refusal' && item.slot !== 'status',
        '-1': (item) => item.title !== '强制下架原因' && item.slot !== 'status',
        '-2': (item) => item.title !== '拒绝原因' && item.slot !== 'status',
      };
      const filterFn = filterMap[value];
      this.columns1 = filterFn ? columns1.filter(filterFn) : columns1;
    },
  },
  methods: {
    // 失效
    couponInvalid(row, tit, num) {
      this.delfromData = {
        title: tit,
        num: num,
        url: `marketing/coupon/status/${row.id}`,
        method: 'PUT',
        ids: '',
      };
      this.$refs.modelSure.modals = true;
    },
    // 领取记录
    receive(row) {
      this.modals2 = true;
      this.rows = row;
      this.getReceivelist(row);
    },
    getReceivelist(row) {
      this.loading2 = true;
      releasedissueLogApi(row.id, this.receiveFrom)
        .then(async (res) => {
          let data = res.data;
          this.receiveList = data.list;
          this.total2 = res.data.count;
          this.loading2 = false;
        })
        .catch((res) => {
          this.loading2 = false;
          this.$Message.error(res.msg);
        });
    },
    // 领取记录改变分页
    receivePageChange(index) {
      this.receiveFrom.page = index;
      this.getReceivelist(this.rows);
    },
    // 删除
    couponDel(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `marketing/coupon/released/${row.id}`,
        method: 'DELETE',
        ids: '',
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.tableList.splice(num, 1);
          if (!this.tableList.length) {
            this.tableFrom.page =
              this.tableFrom.page == 1 ? 1 : this.tableFrom.page - 1;
          }
          this.getCouponHeader();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 列表
    getList() {
      this.loading = true;
      this.tableFrom.status = this.tableFrom.status || '';
      releasedListApi(this.tableFrom)
        .then(async (res) => {
          let data = res.data;
          this.tableList = data.list;
          this.total = res.data.count;
          this.loading = false;
        })
        .catch((res) => {
          this.loading = false;
          this.$Message.error(res.msg);
        });
    },
    pageChange(index) {
      this.tableFrom.page = index;
      this.getList();
    },
    // 搜索
    userSearchs() {
      this.tableFrom.page = 1;
      this.getCouponHeader();
    },
    // 重置
    reset() {
      const is_status = this.tableFrom.is_status;
      this.tableFrom = {
        is_status,
        receive_type: '',
        coupon_type: '',
        type: '',
        status: '',
        coupon_title: '',
        page: 1,
        limit: 15,
      };
      this.getCouponHeader();
    },
    // 添加优惠券
    add() {
      this.$router.push({
        path: `${this.routePre}/marketing/coupon/create`,
      });
    },
    // 复制
    copy(row) {
      this.$router.push({
        path: `${this.routePre}/marketing/coupon/create/${row.id}/2`,
      });
    },
    // 编辑
    edit(row) {
      this.$router.push({
        path: `${this.routePre}/marketing/coupon/create/${row.id}/3`,
      });
    },
    // 详情
    details(row) {
      this.$router.push({
        path: `${this.routePre}/marketing/coupon/create/${row.id}/1`,
      });
    },
    // 是否开启
    openChange(row) {
      couponStatusApi(row).then(() => this.getCouponHeader());
    },
    // 更多操作
    changeMenu(row, name, index) {
      switch (name) {
        case '1':
          this.copy(row);
          break;
        case '2':
          this.couponDel(row, '删除发布的优惠券', index);
          break;
      }
    },
    // 标签页数据
    getCouponHeader() {
      couponHeaderApi(this.tableFrom).then((res) => {
        this.couponHeader = res.data.list;
        if (this.tableFrom.is_status == '') {
          this.tableFrom.is_status = this.couponHeader[0].is_status;
        }
        this.getList();
      });
    },
    // 标签页切换
    onTabsClick(name) {
      this.tableFrom.is_status = name;
      this.tableFrom.page = 1;
      this.getCouponHeader();
    },
  },
};
</script>

<style scoped lang="stylus">
.fa {
  color: #0a6aa1;
  display: block;
}

.sheng {
  color: #ff0000;
  display: block;
}

.tabBox_img {
  width: 36px;
  height: 36px;
  border-radius: 4px;
  cursor: pointer;

  img {
    width: 100%;
    height: 100%;
  }
}

.new_tab {
  >>>.ivu-tabs-nav .ivu-tabs-tab {
    padding: 4px 16px 20px !important;
    font-weight: 500;
  }
}
</style>