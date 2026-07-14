<template>
  <!-- 营销-用户领取记录 -->
  <div>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
        <Form
          ref="tableFrom"
          :model="tableFrom"
          inline
          :label-width="labelWidth"
          :label-position="labelPosition"
          @submit.native.prevent
        >
          <FormItem label="使用状态：">
            <Select
              placeholder="请选择"
              clearable
              v-model="tableFrom.status"
              @on-change="userSearch"
              class="input-add"
            >
              <Option value="1">已使用</Option>
              <Option value="0">未使用</Option>
              <Option value="2">已过期</Option>
            </Select>
          </FormItem>
          <FormItem label="领取人：">
            <Input
              placeholder="请输入领取人昵称/UID/手机号"
              v-model="tableFrom.nickname"
              clearable
              class="input-add"
            />
          </FormItem>
          <FormItem label="获取方式：">
            <Select
              placeholder="请选择"
              clearable
              v-model="tableFrom.type"
              @on-change="userSearch"
              class="input-add"
            >
              <Option value="send">后台发放</Option>
              <Option value="get">手动领取</Option>
            </Select>
          </FormItem>
          <FormItem label="优惠券信息：">
            <Input
              placeholder="请输入优惠券名称"
              v-model="tableFrom.coupon_title"
              @on-search="userSearch"
              class="input-add"
            />
          </FormItem>
          <FormItem label="领取时间：">
            <DatePicker
              :editable="false"
              @on-change="onTimeChange"
              :value="addTimeVal"
              format="yyyy/MM/dd"
              type="daterange"
              placement="bottom-start"
              placeholder="请选择"
              :options="options"
              class="input-add"
            ></DatePicker>
          </FormItem>
          <FormItem label="使用时间：">
            <DatePicker
              :editable="false"
              @on-change="onUseTimeChange"
              :value="useTimeVal"
              format="yyyy/MM/dd"
              type="daterange"
              placement="bottom-start"
              placeholder="请选择"
              :options="options"
              class="input-add"
            ></DatePicker>
          </FormItem>
          <FormItem label="到期时间：">
            <DatePicker
              :editable="false"
              @on-change="onEndTimeChange"
              :value="endTimeVal"
              format="yyyy/MM/dd"
              type="daterange"
              placement="bottom-start"
              placeholder="请选择"
              :options="options"
              class="input-add"
            ></DatePicker>
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
        <template slot-scope="{ row }" slot="user">
          <a @click="showUserInfo(row)" v-if="row.uid">{{ row.nickname }}</a>
          <span style="color: #ed4014" v-if="!row.uid"> (已注销)</span>
        </template>
        <template slot-scope="{ row }" slot="coupon_price">
          <span v-if="row.coupon_type == 1">{{ row.coupon_price }}元</span>
          <span v-if="row.coupon_type == 2"
            >{{ parseFloat(row.coupon_price) / 10 }}折（{{
              row.coupon_price.toString().split('.')[0]
            }}%）</span
          >
        </template>
        <template slot-scope="{ row, index }" slot="add_time">
          <span>{{ row.add_time | formatDate }}</span>
        </template>
        <template slot-scope="{ row, index }" slot="use_time">
          <span v-if="row.use_time">{{ row.use_time | formatDate }}</span>
          <span v-else>—</span>
        </template>
        <template slot-scope="{ row, index }" slot="end_time">
          <span>{{ row.end_time | formatDate }}</span>
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
    <!-- 用户信息 -->
    <user-details ref="userDetails" fromType="order"></user-details>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import { userListApi } from '@/api/marketing';
import { formatDate } from '@/utils/validate';
import timeOptions from '@/utils/timeOptions';
import userDetails from '@/pages/user/components/userDetails2';
export default {
  name: 'storeCouponUser',
  components: {
    userDetails,
  },
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
      options: timeOptions,
      columns1: [
        {
          title: 'ID',
          key: 'id',
          width: 80,
        },
        {
          title: '优惠券名称',
          key: 'coupon_title',
          minWidth: 150,
        },
        {
          title: '领取人',
          slot: 'user',
          minWidth: 130,
        },
        {
          title: '面值',
          slot: 'coupon_price',
          minWidth: 100,
        },
        {
          title: '最低消费额',
          key: 'use_min_price',
          minWidth: 120,
        },
        {
          title: '领取时间',
          slot: 'add_time',
          minWidth: 120,
        },
        {
          title: '到期时间',
          slot: 'end_time',
          minWidth: 150,
        },
        {
          title: '使用时间',
          slot: 'use_time',
          minWidth: 150,
        },
        {
          title: '获取方式',
          key: 'type',
          minWidth: 150,
        },
        {
          title: '使用状态',
          key: 'status',
          minWidth: 170,
        },
      ],
      tableList: [],
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      tableFrom: {
        status: '',
        nickname: '',
        type: '',
        add_time: '',
        use_time: '',
        end_time: '',
        coupon_title: '',
        page: 1,
        limit: 15,
      },
      total: 0,
      addTimeVal: [],
      useTimeVal: [],
      endTimeVal: [],
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
    this.getList();
  },
  methods: {
    // 列表
    getList() {
      this.loading = true;
      this.tableFrom.status = this.tableFrom.status || '';
      userListApi(this.tableFrom)
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
    // 重置
    reset() {
      this.tableFrom = {
        status: '',
        nickname: '',
        type: '',
        data: '',
        use_time: '',
        end_time: '',
        coupon_title: '',
        page: 1,
        limit: 15,
      };
      this.addTimeVal = [];
      this.useTimeVal = [];
      this.endTimeVal = [];
      this.getList();
    },
    // 搜索
    userSearch() {
      this.tableFrom.page = 1;
      this.getList();
    },
    onTimeChange(date) {
      this.addTimeVal = date;
      this.tableFrom.add_time = date.some((item) => !item)
        ? ''
        : date.join('-');
      this.userSearch();
    },
    onUseTimeChange(date) {
      this.useTimeVal = date;
      this.tableFrom.use_time = date.some((item) => !item)
        ? ''
        : date.join('-');
      this.userSearch();
    },
    onEndTimeChange(date) {
      this.endTimeVal = date;
      this.tableFrom.end_time = date.some((item) => !item)
        ? ''
        : date.join('-');
      this.userSearch();
    },
    showUserInfo(row) {
      this.$refs.userDetails.modals = true;
      this.$refs.userDetails.getDetails(row.uid);
    },
  },
};
</script>