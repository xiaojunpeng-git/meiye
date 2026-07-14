<template>
  <div>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
        <Form
          ref="formData"
          :model="formData"
          :label-width="labelWidth"
          :label-position="labelPosition"
          class="tabform"
          inline
          @submit.native.prevent
        >
          <FormItem label="订单编号：">
            <Input
              placeholder="订单编号"
              v-model="formData.order_id"
              class="input-add"
            />
          </FormItem>
          <!-- <FormItem label="核销门店：">
            <Select
              v-model="formData.write_off_store_id"
              class="input-add"
              clearable
              placeholder="请选择"
              @on-change="searchHandle"
            >
              <Option v-for="item in storeList" :value="item.id" :key="item.id"
                >{{ item.name }}
              </Option>
            </Select>
          </FormItem> -->
          <FormItem label="下单门店：">
            <Select
              v-model="formData.ordering_store_id"
              clearable
              filterable
              @on-change="searchHandle"
              class="input-add"
            >
              <Option v-for="item in storeList" :value="item.id" :key="item.id"
                >{{ item.name }}
              </Option>
            </Select>
          </FormItem>
          <FormItem label="核销人员：">
            <Input
              placeholder="请输入人员昵称"
              v-model="formData.staff"
              class="input-add"
            />
          </FormItem>
          <FormItem label="商品名称：">
            <Input
              placeholder="商品名称"
              v-model="formData.product_name"
              class="input-add"
              clearable
            />
          </FormItem>
          <FormItem label="手艺人：">
            <Input
              placeholder="手艺人姓名"
              v-model="formData.yeji_staff"
              class="input-add"
              clearable
            />
          </FormItem>
          <FormItem label="核销时间：">
            <DatePicker
              :editable="false"
              @on-change="dateChange"
              :value="timeVal"
              format="yyyy/MM/dd"
              type="daterange"
              placement="bottom-start"
              placeholder="自定义时间"
              class="input-add"
              :options="options"
            ></DatePicker>
            <Button type="primary" @click="searchHandle" class="ml-14"
              >查询</Button
            >
            <Button @click="reset" class="ml-14">重置</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Table
        :columns="columns"
        :data="tableData"
        ref="table"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="user">
          <a @click="showUserInfo(row)" v-if="row.user">{{
            row.user.nickname
          }}</a>
          <span
            style="color: #ed4014"
            v-if="!row.user || row.user.delete_time != null"
          >
            (已注销)</span
          >
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="formData.page"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="formData.limit"
        />
      </div>
    </Card>
    <!-- 用户信息 -->
    <user-details ref="userDetails" fromType="order"></user-details>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import timeOptions from '@/utils/timeOptions';
import { storeListApi } from '@/api/system';
import { writeoffRecordsList } from '@/api/order';
import userDetails from '@/pages/user/components/userDetails2';

export default {
  components: {
    userDetails,
  },
  data() {
    return {
      options: timeOptions,
      formData: {
        page: 1,
        limit: 10,
        order_id: '',
        ordering_store_id: '',
        // write_off_store_id: '',
        staff: '',
        product_name: '',
        yeji_staff: '',
        data: '',
      },
      storeList: [],
      timeVal: [],
      columns: [
        {
          title: '订单号',
          key: 'order_id',
          minWidth: 150,
        },
        {
          title: '用户信息',
          slot: 'user',
          minWidth: 150,
        },
        {
          title: '下单门店',
          key: 'ordering_store',
          minWidth: 150,
        },
        {
          title: '核销门店',
          key: 'write_off_store',
          minWidth: 150,
        },
        {
          title: '商品名称',
          key: 'product_name',
          minWidth: 160,
          tooltip: true,
        },
        {
          title: '手艺人',
          key: 'yeji_staff',
          minWidth: 160,
          className: 'writeoff-yeji-staff-col',
        },
        {
          title: '核销数量',
          key: 'writeoff_num',
          minWidth: 100,
        },
        {
          title: '核销人员',
          key: 'staff_name',
          minWidth: 150,
        },
        {
          title: '核销金额',
          key: 'writeoff_price',
          minWidth: 150,
        },
        {
          title: '核销时间',
          key: 'add_time',
          minWidth: 150,
        },
      ],
      tableData: [],
      loading: false,
      total: 0,
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 96;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  created() {
    this.getStoreList();
    this.getList();
  },
  methods: {
    getStoreList() {
      storeListApi().then((res) => {
        this.storeList = res.data;
      });
    },
    getList() {
      writeoffRecordsList(this.formData).then((res) => {
        const { count, list } = res.data;
        this.total = count;
        this.tableData = list;
      });
    },
    dateChange(date) {
      this.timeVal = date;
      this.formData.data = date.join('-');
    },
    pageChange(page) {
      this.formData.page = page;
      this.getList();
    },
    searchHandle() {
      this.formData.page = 1;
      this.getList();
    },
    reset() {
      this.formData.page = 1;
      this.formData.order_id = '';
      this.formData.ordering_store_id = '';
      // this.formData.write_off_store_id = '';
      this.formData.staff = '';
      this.formData.product_name = '';
      this.formData.yeji_staff = '';
      this.formData.data = '';
      this.timeVal = [];
      this.getList();
    },
    showUserInfo(row) {
      this.$refs.userDetails.modals = true;
      this.$refs.userDetails.getDetails(row.uid);
    },
  },
};
</script>

<style lang="stylus" scoped>
/deep/ .writeoff-yeji-staff-col
  height auto !important
  .ivu-table-cell
    white-space normal
    word-break break-all
    line-height 1.5
    height auto !important
    padding-top 8px
    padding-bottom 8px
</style>
