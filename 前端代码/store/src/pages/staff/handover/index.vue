<template>
  <div>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="new_card_pd">
        <Form
          ref="tableFrom"
          :model="tableFrom"
          inline
          :label-width="labelWidth"
          :label-position="labelPosition"
          @submit.native.prevent
        >
          <FormItem label="时间：">
            <DatePicker
              :editable="false"
              @on-change="onTimeChange"
              :value="shiftTimeVal"
              format="yyyy/MM/dd"
              type="daterange"
              placement="bottom-start"
              placeholder="请选择"
              :options="options"
              class="input-add"
            ></DatePicker>
          </FormItem>
          <FormItem label="收银员：">
            <Select
              placeholder="请选择"
              clearable
              v-model="tableFrom.staff_id"
              @on-change="userSearch"
              class="input-add"
            >
              <Option
                v-for="item in staffData"
                :key="item.value"
                :value="item.value"
                >{{ item.label }}</Option
              >
            </Select>
            <Button class="ml-14" type="primary" @click="userSearch"
              >查询</Button
            >
            <Button class="ml-14" @click="reset">重置</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <!-- 用户领取记录-表格 -->
      <Table :columns="columns1" :data="tableList">
        <template slot-scope="{ row, index }" slot="staff_name">
          <div>{{ row.staff_name }}（{{ row.account }}）</div>
        </template>
        <template slot-scope="{ row, index }" slot="shift_start_time">
          <div>{{ row.shift_start_time | formatDate }}</div>
          <div>至</div>
          <div>{{ row.shift_end_time | formatDate }}</div>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a @click="details(row)">详情</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="tableFrom.page"
          show-elevator
          show-total
          @on-change="onPageChange"
          :page-size="tableFrom.limit"
        />
      </div>
    </Card>
    <Modal
      v-model="modal1"
      title="交接班"
      width="900"
      class-name="handover-modal"
      footer-hide
    >
      <div class="acea-row">
        <div>收银员：{{ rowActive.staff_name }}（{{ rowActive.account }}）</div>
        <div class="ml-24">
          班次：{{ rowActive.shift_start_time | formatDate }}  ~
          {{ rowActive.shift_end_time | formatDate }}
        </div>
      </div>
      <div class="grid-box mt-20 text-wlll-606266">
        <div
          class="acea-row row-column row-center-wrapper h-76 rd-4px bg-w111-F9F9F9"
        >
          <div>应收金额</div>
          <div class="mt-6 fs-16 text-wlll-303133">
            ¥{{ Number(handoverData.sumPrice) }}
          </div>
        </div>
        <div
          class="acea-row row-column row-center-wrapper h-76 rd-4px bg-w111-F9F9F9"
        >
          <div>订单销售额</div>
          <div class="mt-6 fs-16 text-wlll-303133">
            ¥{{ handoverData.order_price }}
          </div>
        </div>
        <div
          class="acea-row row-column row-center-wrapper h-76 rd-4px bg-w111-F9F9F9"
        >
          <div>充值金额</div>
          <div class="mt-6 fs-16 text-wlll-303133">
            ¥{{ handoverData.recharge_price }}
          </div>
        </div>
        <div
          class="acea-row row-column row-center-wrapper h-76 rd-4px bg-w111-F9F9F9"
        >
          <div>付费会员金额</div>
          <div class="mt-6 fs-16 text-wlll-303133">
            ¥{{ handoverData.other_price }}
          </div>
        </div>
        <div
          class="acea-row row-column row-center-wrapper h-76 rd-4px bg-w111-F9F9F9"
        >
          <div>退款金额</div>
          <div class="mt-6 fs-16 text-wlll-303133">
            ¥{{ handoverData.refund_price }}
          </div>
        </div>
        <div
          class="acea-row row-column row-center-wrapper h-76 rd-4px bg-w111-F9F9F9"
        >
          <div>现金金额</div>
          <div class="mt-6 fs-16 text-wlll-303133">
            ¥{{ Number(handoverData.cash_price) }}
          </div>
        </div>
      </div>
      <div class="mt-20">
        <Table :columns="columns2" :data="data1"></Table>
      </div>
    </Modal>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import timeOptions from '@/utils/timeOptions';
import { formatDate } from '@/utils/validate';
import { staffallInfo, shiftListApi, shiftHandoverApi } from '@/api/staff.js';

export default {
  filters: {
    formatDate(time) {
      if (time !== 0) {
        let date = new Date(time * 1000);
        return formatDate(date, 'yyyy-MM-dd hh:mm:ss');
      }
    },
  },
  data() {
    return {
      options: timeOptions,
      tableFrom: {
        shift_time: '',
        staff_id: '',
        page: 1,
        limit: 15,
      },
      shiftTimeVal: [],
      staffData: [],
      columns1: [
        {
          title: '员工',
          slot: 'staff_name',
          minWidth: 130,
        },
        {
          title: '班次',
          slot: 'shift_start_time',
          minWidth: 130,
        },
        {
          title: '订单数',
          key: 'sum',
          minWidth: 100,
        },
        {
          title: '应收金额',
          key: 'sum_price',
          minWidth: 100,
        },
        {
          title: '订单销售额',
          key: 'order_price',
          minWidth: 100,
        },
        {
          title: '充值金额',
          key: 'recharge_price',
          minWidth: 100,
        },
        {
          title: '付费会员金额',
          key: 'other_price',
          minWidth: 100,
        },
        {
          title: '退款',
          key: 'refund_price',
          minWidth: 100,
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          minWidth: 100,
        },
      ],
      tableList: [],
      total: 0,
      modal1: false,
      columns2: [
        {
          title: '支付方式',
          key: 'pay_type',
        },
        {
          title: '订单销售额',
          key: 'order_price',
          render: (h, params) => {
            return h('div', `¥${params.row.order_price}`);
          },
        },
        {
          title: '充值金额',
          key: 'recharge_price',
          render: (h, params) => {
            return h('div', `¥${params.row.recharge_price}`);
          },
        },
        {
          title: '付费会员金额',
          key: 'other_price',
          render: (h, params) => {
            return h('div', `¥${params.row.other_price}`);
          },
        },
        {
          title: '退款金额',
          key: 'refund_price',
          render: (h, params) => {
            return h('div', `¥${params.row.refund_price}`);
          },
        },
        {
          title: '应收金额',
          key: 'sum_price',
          render: (h, params) => {
            return h('div', `¥${Number(params.row.sum_price)}`);
          },
        },
      ],
      data1: [],
      rowActive: {},
      handoverData: {},
    };
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
  created() {
    this.staffList();
    this.getList();
  },
  methods: {
    // 店员列表
    staffList() {
      staffallInfo()
        .then((res) => {
          this.staffData = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 列表
    getList() {
      shiftListApi(this.tableFrom)
        .then((res) => {
          const { list, count } = res.data;
          this.tableList = list;
          this.total = count;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 搜索
    userSearch() {
      this.tableFrom.page = 1;
      this.getList();
    },
    // 重置
    reset() {
      this.tableFrom = {
        shift_time: '',
        staff_id: '',
        page: 1,
        limit: 15,
      };
      this.shiftTimeVal = [];
      this.getList();
    },
    // 分页
    onPageChange(index) {
      this.tableFrom.page = index;
      this.getList();
    },
    // 时间
    onTimeChange(date) {
      this.shiftTimeVal = date;
      this.tableFrom.shift_time = date.some((item) => !item)
        ? ''
        : date.join('-');
      this.getList();
    },
    getHandover() {
      shiftHandoverApi(this.rowActive.id).then((res) => {
        this.handoverData = res.data;
        this.data1 = res.data.details;
      });
    },
    // 详情
    details(row) {
      this.modal1 = true;
      this.rowActive = row;
      this.getHandover();
    },
  },
};
</script>

<style lang="stylus" scoped>
.grid-box {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 1fr 1fr 1fr;
  grid-column-gap: 19px;
}

/deep/ .handover-modal .ivu-modal-body {
  padding: 20px 24px 24px;
}

/deep/.ivu-modal-header {
  border-radius: 6px 6px 0 0;
  background-color: #ffffff;
}
</style>