<template>
  <!-- 店员列表 -->
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
      <!-- 查询条件 -->
      <Form
        ref="form"
        class="pt-20 pr-20 pl-20"
        :model="formData"
        :label-width="labelWidth"
        :label-position="labelPosition"
        inline
        @submit.native.prevent
      >
        <FormItem label="所属门店：">
          <Select
            v-model="formData.store_id"
            clearable
            filterable
            class="input-add"
            @on-change="orderSearch"
          >
            <Option v-for="item in storeList" :value="item.id" :key="item.id"
              >{{ item.name }}
            </Option>
          </Select>
        </FormItem>
        <FormItem label="店员信息：" label-for="nickname">
          <Input
            placeholder="请输入导购名称/ID/手机号"
            v-model="formData.keyword"
            class="input-add"
          />
          <Button class="ml-14" type="primary" @click="orderSearch">查询</Button>
          <Button
            v-auth="['export-userCommission']"
            class="ml-14"
            @click="exports"
            >导出</Button
          >
        </FormItem>
      </Form>
    </Card>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt">
      <Table
        :columns="columns"
        :data="data"
        ref="table"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="info">
          <div class="tabBox">
            <div class="tabBox_img" v-viewer>
              <img v-lazy="row.avatar" />
            </div>
            <div class="pl-10 fs-12">
              <div v-if="row.staff_name">{{ row.staff_name }}</div>
              <div>ID:{{ row.uid }}</div>
            </div>
          </div>
        </template>
        <template slot-scope="{ row }" slot="action">
          <a @click="edit(row.id)">编辑</a>
          <Divider type="vertical" />
          <a @click="handleClick1(row.id)">专属客户</a>
          <Divider type="vertical" />
          <a @click="handleClick2(row.id)">业绩订单</a>
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
          @on-page-size-change="limitChange"
          show-sizer
        />
      </div>
    </Card>
    <!-- 查看专属客户 -->
    <Modal
      v-model="modal1"
      :mask-closable="false"
      title="查看专属客户"
      footer-hide
      width="1000"
      @on-cancel="onModal1Cancel"
    >
      <Form
        inline
        ref="form1"
        :model="formData1"
        :label-width="labelWidth"
        :label-position="labelPosition"
        @submit.native.prevent
      >
        <FormItem label="客户查询：">
          <Input
            placeholder="请输入客户名称/ID/手机号"
            v-model="formData1.keyword"
            class="input-add"
          />
        </FormItem>
        <FormItem label="绑定时间：">
          <DatePicker
            :editable="false"
            @on-change="onDateChange1"
            :value="timeVal1"
            format="yyyy/MM/dd"
            type="daterange"
            placement="bottom-end"
            placeholder="自定义时间"
            class="input-add"
            :options="options"
          ></DatePicker>
        </FormItem>
        <FormItem :label-width="0">
          <Button type="primary" @click="handleSearch1">查询</Button>
        </FormItem>
      </Form>
      <Table
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
        ref="selection"
        :columns="columns1"
        :data="tableData1"
      >
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total1"
          show-elevator
          show-total
          :current="formData1.page"
          @on-change="onPageChange1"
          :page-size="formData1.limit"
        />
      </div>
    </Modal>
    <!-- 业绩订单 -->
    <Modal
      v-model="modal2"
      :mask-closable="false"
      title="业绩订单"
      footer-hide
      width="1000"
      @on-cancel="onModal2Cancel"
    >
      <Form
        inline
        ref="form2"
        :model="formData2"
        :label-width="labelWidth"
        :label-position="labelPosition"
        @submit.native.prevent
      >
        <FormItem label="时间选择：">
          <DatePicker
            :editable="false"
            @on-change="onDateChange2"
            :value="timeVal2"
            format="yyyy/MM/dd"
            type="daterange"
            placement="bottom-end"
            placeholder="自定义时间"
            class="input-add"
            :options="options"
          ></DatePicker>
        </FormItem>
        <FormItem label="用户信息：">
          <Input v-model="formData2.keyword" clearable class="input-add" />
        </FormItem>
        <FormItem label="订单号：">
          <Input v-model="formData2.link_id" clearable class="input-add" />
        </FormItem>
        <FormItem label="业绩金额：">
          <InputNumber
            v-model="performance.min"
            :max="9999999999"
            :min="0"
            placeholder="最小值"
            style="width: 109px"
          ></InputNumber>
          <span class="mr10 ml-10">一</span>
          <InputNumber
            v-model="performance.max"
            :max="9999999999"
            :min="0"
            placeholder="最大值"
            style="width: 109px"
          ></InputNumber>
        </FormItem>
        <FormItem label="订单金额：">
          <InputNumber
            v-model="price.min"
            :max="9999999999"
            :min="0"
            placeholder="最小值"
            style="width: 109px"
          ></InputNumber>
          <span class="mr10 ml-10">一</span>
          <InputNumber
            v-model="price.max"
            :max="9999999999"
            :min="0"
            placeholder="最大值"
            style="width: 109px"
          ></InputNumber>
        </FormItem>
        <FormItem :label-width="0">
          <Button type="primary" @click="handleSearch2">查询</Button>
        </FormItem>
      </Form>
      <Table
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
        ref="selection"
        :columns="columns2"
        :data="tableData2"
      >
        <template slot-scope="{ row }" slot="user">
          <div>{{ row.user_nickname }}|{{ row.phone }}|ID:{{ row.uid }}</div>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total2"
          show-elevator
          show-total
          :current="formData2.page"
          @on-change="onPageChange2"
          :page-size="formData2.limit"
        />
      </div>
    </Modal>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import Setting from '@/setting';
import {
  merchantStoreListApi,
  merchantStaffList,
  merchantStaffCustomer,
  merchantStaffPerformance,
  exportStaffListExport,
} from '@/api/setting';
import exportExcel from '@/utils/newToExcel.js';
import timeOptions from '@/utils/timeOptions';
export default {
  name: 'setting_staff_index',
  data() {
    return {
      options: timeOptions,
      formData: {
        keyword: '',
        store_id: 0,
        page: 1,
        limit: 20,
      },
      loading: false,
      columns: [
        {
          title: 'ID',
          key: 'id',
          width: 60,
        },
        {
          title: '店员信息',
          slot: 'info',
          minWidth: 150,
        },
        {
          title: '手机号',
          key: 'phone',
          minWidth: 90,
        },
        {
          title: '所属门店',
          key: 'name',
          minWidth: 110,
        },
        {
          title: "店员身份",
          key: "roles",
          minWidth: 120,
        },
        {
          title: "工号",
          key: "employee_number",
          minWidth: 120,
        },
        {
          title: "入职日期",
          key: "join_date",
          minWidth: 120,
        },
        {
          title: "身份证号码",
          key: "id_card",
          minWidth: 120,
        },
        {
          title: "生日日期",
          key: "birthday_date",
          minWidth: 120,
        },
        {
          title: "年龄",
          key: "age",
          minWidth: 120,
        },
        {
          title: "劳动关系所在地",
          key: "join_area",
          minWidth: 120,
        },
        {
          title: "籍贯",
          key: "birthday_area",
          minWidth: 120,
        },
        {
          title: "现居地",
          key: "now_area",
          minWidth: 120,
        },
        {
          title: "合同起始日",
          key: "contract_begin",
          minWidth: 120,
        },
        {
          title: "合同终止日",
          key: "contract_end",
          minWidth: 120,
        },
        // {
        //   title: '专属客户数量',
        //   key: 'customer_num',
        //   minWidth: 90,
        // },
        // {
        //   title: '订单数量',
        //   key: 'order_num',
        //   minWidth: 90,
        // },
        // {
        //   title: '订单金额',
        //   key: 'order_price',
        //   minWidth: 110,
        // },
        // {
        //   title: '业绩',
        //   key: 'performance_price',
        //   minWidth: 110,
        // },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          minWidth: 200,
        },
      ],
      data: [],
      storeList: [],
      total: 0,
      modal1: false,
      formData1: {
        keyword: '',
        data: '',
        page: 1,
        limit: 20,
      },
      timeVal1: [],
      columns1: [
        {
          title: 'ID',
          key: 'uid',
        },
        {
          title: '客户昵称',
          key: 'nickname',
        },
        {
          title: '客户手机号',
          key: 'phone',
        },
        {
          title: '专属业绩',
          key: 'performance_price',
        },
        {
          title: '绑定时间',
          key: 'salesman_time',
        },
      ],
      total1: 0,
      tableData1: [],
      modal2: false,
      formData2: {
        data: '',
        keyword: '',
        link_id: '',
        price: '',
        performance: '',
        page: 1,
        limit: 20,
      },
      timeVal2: [],
      performance: {
        min: '',
        max: '',
      },
      price: {
        min: '',
        max: '',
      },
      columns2: [
        {
          title: '订单号',
          key: 'link_id',
        },
        {
          title: '用户信息',
          slot: 'user',
        },
        {
          title: '订单金额',
          key: 'number',
        },
        {
          title: '业绩金额',
          key: 'number',
        },
        {
          title: '下单时间',
          key: 'add_time',
        },
      ],
      total2: 0,
      tableData2: [],
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
  },
  activated() {
    this.getList();
  },
  methods: {
    edit(id) {
      this.$router.push({ path: Setting.roterPre + '/setting/staff/add/' + id });
    },
    orderSearch() {
      this.formData.page = 1;
      this.getList();
    },
    pageChange(index) {
      this.formData.page = index;
      this.getList();
    },
    limitChange(limit) {
      this.formData.limit = limit;
      this.getList();
    },
    // 门店列表
    getStoreList() {
      merchantStoreListApi()
        .then((res) => {
          this.storeList = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    getList() {
      this.loading = true;
      merchantStaffList(this.formData)
        .then((res) => {
          this.loading = false;
          this.data = res.data.list;
          this.total = res.data.count;
        })
        .catch((err) => {
          this.loading = false;
          this.$Message.error(err.msg);
        });
    },
    // 专属客户列表
    getCustomerList() {
      merchantStaffCustomer(this.currentId, this.formData1).then((res) => {
        this.tableData1 = res.data.list;
        this.total1 = res.data.count;
      });
    },
    // 业绩订单列表
    getPerformanceList() {
      merchantStaffPerformance(this.currentId, this.formData2).then((res) => {
        this.tableData2 = res.data.list;
        this.total2 = res.data.count;
      });
    },
    getExcelData(excelData) {
      return new Promise((resolve, reject) => {
        exportStaffListExport(excelData).then((res) => {
          return resolve(res.data);
        });
      });
    },
    // 导出
    async exports() {
      let [th, filekey, data, fileName] = [[], [], [], ''];
      let excelData = JSON.parse(JSON.stringify(this.formData));
      for (let i = 0; i < excelData.page + 1; i++) {
        let lebData = await this.getExcelData(excelData);
        if (!fileName) fileName = lebData.filename;
        if (!filekey.length) {
          filekey = lebData.filekey;
        }
        if (!th.length) th = lebData.header;
        if (lebData.export.length) {
          data = data.concat(lebData.export);
          excelData.page++;
        } else {
          exportExcel(th, filekey, fileName, data);
          return;
        }
      }
    },
    // 专属客户
    handleClick1(id) {
      this.modal1 = true;
      this.currentId = id;
      this.getCustomerList();
    },
    // 专属客户查询
    handleSearch1() {
      this.formData1.page = 1;
      this.getCustomerList();
    },
    // 专属客户时间
    onDateChange1(date) {
      this.formData1.data = date[0] ? date.join('-') : '';
    },
    // 专属客户分页
    onPageChange1(page) {
      this.formData1.page = page;
      this.getCustomerList();
    },
    // 专属客户关闭
    onModal1Cancel() {
      this.formData1 = {
        keyword: '',
        data: '',
        page: 1,
        limit: 20,
      };
      this.timeVal1 = [];
    },
    // 业绩订单
    handleClick2(id) {
      this.modal2 = true;
      this.currentId = id;
      this.getPerformanceList();
    },
    // 业绩订单查询
    handleSearch2() {
      this.formData2.page = 1;
      this.formData2.performance = this.performance.min
        ? `${this.performance.min}-${this.performance.max}`
        : '';
      this.formData2.price = this.price.min
        ? `${this.price.min}-${this.price.max}`
        : '';
      this.getPerformanceList();
    },
    // 业绩订单时间
    onDateChange2(date) {
      this.formData2.data = date[0] ? date.join('-') : '';
    },
    // 业绩订单分页
    onPageChange2(page) {
      this.formData2.page = page;
      this.getPerformanceList();
    },
    // 业绩订单关闭
    onModal2Cancel() {
      this.formData2 = {
        data: '',
        keyword: '',
        link_id: '',
        price: '',
        performance: '',
      };
      this.timeVal2 = [];
      this.performance = {
        min: '',
        max: '',
      };
      this.price = {
        min: '',
        max: '',
      };
    },
  },
};
</script>

<style lang="stylus" scoped>
.tabBox {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;

  .tabBox_img {
    width: 30px;
    height: 30px;

    img {
      width: 100%;
      height: 100%;
    }
  }
}
</style>
