<template>
  <div class="staff-page">
    <Card :bordered="false" dis-hover :padding="16" class="staff-card">
      <div class="filter-bar">
        <Input
          v-model="formData.keyword"
          placeholder="请输入导购名称/ID/手机号"
          clearable
          class="search-input"
          @on-enter="orderSearch"
        />
        <Select
          v-model="formData.status"
          clearable
          class="filter-select"
          placeholder="在职状态"
          @on-change="orderSearch"
        >
          <Option :value="1">在职</Option>
          <Option :value="0">离职</Option>
        </Select>
        <Button type="primary" @click="orderSearch">查询 <span class="enter-key">↵</span></Button>
        <Button v-auth="['staff-staff-create']" type="primary" class="ml14" @click="openForm(0)">新建店员</Button>
        <Button class="ml14" @click="goSchedule">排班管理</Button>
        <Button class="ml14" @click="showColumnSetting = true">列设置</Button>
      </div>

      <div class="table-wrap">
        <div class="table-body" ref="tableBody">
        <Table
          :columns="tableColumns"
          :data="data"
          :loading="loading"
          highlight-row
          :max-height="tableBodyHeight"
          :scroll="{ x: tableScrollX }"
          no-data-text="暂无数据"
          no-filtered-data-text="暂无筛选结果"
        >
          <template slot-scope="{ row }" slot="staff_name">
            <div class="staff-cell">
              <img class="staff-avatar" :src="resolveStaffAvatar(row.avatar)" alt="" />
              <span>
                {{ row.staff_name || '-' }}
                <span v-if="row.delete_time != null" class="deleted-tag">(已注销)</span>
              </span>
            </div>
          </template>
          <template slot-scope="{ row }" slot="is_manager">
            {{ row.is_manager == 1 ? '是' : '否' }}
          </template>
          <template slot-scope="{ row }" slot="can_choose">
            {{ row.can_choose == 1 ? '是' : '否' }}
          </template>
          <template slot-scope="{ row }" slot="status">
            {{ row.status == 1 ? '在职' : '离职' }}
          </template>
          <template slot-scope="{ row }" slot="has_pwd">
            {{ row.has_pwd == 1 ? '已设置' : '未设置' }}
          </template>
          <template slot-scope="{ row }" slot="is_customer">
            {{ row.is_customer == 1 ? '是' : '否' }}
          </template>
          <template slot-scope="{ row }" slot="is_reservable">
            {{ row.is_reservable == 1 ? '是' : '否' }}
          </template>
          <template slot-scope="{ row }" slot="salary_status">
            {{ row.salary_status == 1 ? '是' : '否' }}
          </template>
          <template slot-scope="{ row }" slot="birthday_type">
            {{ row.birthday_type == 1 ? '农历' : row.birthday_type == 2 ? '新历' : '-' }}
          </template>
          <template slot-scope="{ row, index }" slot="action">
            <div class="action-ops">
              <div class="action-row">
                <a
                  v-if="row.status == 1 && row.delete_time == null"
                  @click="goCashier(row)"
                >进入收银台</a>
                <Divider
                  v-if="row.status == 1 && row.delete_time == null"
                  type="vertical"
                />
                <a v-if="row.delete_time == null" @click="openForm(row.id)">编辑</a>
                <Divider v-if="row.level > 0" type="vertical" />
                <a v-if="row.level > 0" @click="del(row.id, '删除该店员', index)">删除</a>
                <Divider type="vertical" />
                <a @click="details(row)">查看详情</a>
              </div>
              <div class="action-row">
                <a @click="handleClick1(row.id)">专属客户</a>
                <Divider type="vertical" />
                <a @click="handleClick2(row.id)">业绩订单</a>
              </div>
            </div>
          </template>
        </Table>
        </div>
        <div class="acea-row row-right page">
          <Page
            :total="total"
            :current="formData.page"
            :page-size="formData.limit"
            :page-size-opts="[10, 20, 50, 100]"
            show-elevator
            show-total
            show-sizer
            @on-change="pageChange"
            @on-page-size-change="limitChange"
          />
        </div>
      </div>
    </Card>

    <Details ref="userDetails" @edit="handleEdit" />
    <form-modal
      v-model="formModal"
      :edit-id="formEditId"
      :current-store-id="currentStoreId"
      :current-store-name="currentStoreName"
      @success="getList"
    />
    <column-setting
      v-model="showColumnSetting"
      :columns-meta="columnsMeta"
      :columns="columnConfig"
      :default-columns="defaultColumnConfig"
      @save="saveColumnConfig"
    />

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
            transfer
            :editable="false"
            @on-change="onDateChange1"
            :value="timeVal1"
            format="yyyy/MM/dd"
            type="daterange"
            placement="bottom-end"
            placeholder="自定义时间"
            class="input-add"
            :options="options"
          />
        </FormItem>
        <FormItem :label-width="0">
          <Button type="primary" @click="handleSearch1">查询 <span class="enter-key">↵</span></Button>
        </FormItem>
      </Form>
      <Table
        highlight-row
        no-data-text="暂无数据"
        :columns="columns1"
        :data="tableData1"
      />
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
            transfer
            :editable="false"
            @on-change="onDateChange2"
            :value="timeVal2"
            format="yyyy/MM/dd"
            type="daterange"
            placement="bottom-end"
            placeholder="自定义时间"
            class="input-add"
            :options="options"
          />
        </FormItem>
        <FormItem label="用户信息：">
          <Input v-model="formData2.keyword" clearable class="input-add" />
        </FormItem>
        <FormItem label="订单号：">
          <Input v-model="formData2.link_id" clearable class="input-add" />
        </FormItem>
        <FormItem label="业绩金额：">
          <InputNumber v-model="performance.min" :max="9999999999" :min="0" placeholder="最小值" style="width: 109px" />
          <span class="mr10 ml-10">一</span>
          <InputNumber v-model="performance.max" :max="9999999999" :min="0" placeholder="最大值" style="width: 109px" />
        </FormItem>
        <FormItem label="订单金额：">
          <InputNumber v-model="price.min" :max="9999999999" :min="0" placeholder="最小值" style="width: 109px" />
          <span class="mr10 ml-10">一</span>
          <InputNumber v-model="price.max" :max="9999999999" :min="0" placeholder="最大值" style="width: 109px" />
        </FormItem>
        <FormItem :label-width="0">
          <Button type="primary" @click="handleSearch2">查询 <span class="enter-key">↵</span></Button>
        </FormItem>
      </Form>
      <Table
        highlight-row
        no-data-text="暂无数据"
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

    <Modal
      v-model="editModal"
      scrollable
      footer-hide
      closable
      title="修改"
      :mask-closable="false"
      width="550"
    >
      <Form :model="editForm" :label-width="80">
        <FormItem label="业绩金额：">¥{{ editRow.pay_price }}</FormItem>
        <FormItem label="业绩订单：">{{ editRow.order_id }}</FormItem>
        <FormItem label="业绩归属：">
          <Select v-model="editForm.staff_id">
            <Option :value="item.value" v-for="item in staffAll" :key="item.value">{{ item.label }}</Option>
          </Select>
        </FormItem>
      </Form>
      <div class="acea-row row-right">
        <Button class="mr10" @click="cancelEditModal">取消</Button>
        <Button type="primary" @click="saveOrderStaff">确认</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import Setting from '@/setting';
import Cookies from 'js-cookie';
import {
  staffListInfo,
  staffallInfo,
  cashierLogin,
  orderStaff,
  getStaffColumnSetting,
  saveStaffColumnSetting,
  staffCustomerList,
  staffPerformanceList,
} from '@/api/staff.js';
import { storeGetInfoApi } from '@/api/setting';
import timeOptions from '@/utils/timeOptions';
import Details from '../components/details';
import FormModal from './add';
import ColumnSetting from './components/ColumnSetting';

function resolveApiOrigin() {
  return String(Setting.apiBaseURL || '')
    .replace(/\/adminapi\/?$/i, '')
    .replace(/\/storeapi\/?$/i, '')
    .replace(/\/+$/, '');
}

function resolveStaffAvatar(url) {
  if (!url) return '';
  const origin = resolveApiOrigin();
  const raw = String(url);
  const m = raw.match(/\/static\/images\/staff\/avatar_(male|female)\.(svg|png)/i);
  if (m) {
    return `${origin}/static/images/staff/avatar_${m[1].toLowerCase()}.png`;
  }
  if (raw.startsWith('/')) {
    return origin + raw;
  }
  if (/^https?:\/\/127\.0\.0\.1\/static\//i.test(raw)) {
    return origin + raw.replace(/^https?:\/\/127\.0\.0\.1/i, '');
  }
  return raw;
}

const COLUMNS_META = [
  { key: 'id', title: 'ID', minWidth: 60 },
  { key: 'staff_name', title: '店员名称', minWidth: 150, slot: 'staff_name' },
  { key: 'nickname', title: '昵称', minWidth: 100 },
  { key: 'phone', title: '手机号', minWidth: 110 },
  { key: 'roles', title: '店员身份', minWidth: 120 },
  { key: 'position_label', title: '职位', minWidth: 100 },
  { key: 'position_level_label', title: '职级', minWidth: 100 },
  { key: 'is_manager', title: '店长', minWidth: 80, slot: 'is_manager' },
  { key: 'can_choose', title: '销售/手艺人', minWidth: 100, slot: 'can_choose' },
  { key: 'status', title: '在职状态', minWidth: 80, slot: 'status' },
  { key: 'employee_number', title: '工号', minWidth: 100 },
  { key: 'join_date', title: '入职日期', minWidth: 110 },
  { key: 'id_card', title: '身份证号码', minWidth: 140 },
  { key: 'birthday_date', title: '生日日期', minWidth: 110 },
  { key: 'age', title: '年龄', minWidth: 70 },
  { key: 'join_area', title: '劳动关系所在地', minWidth: 130 },
  { key: 'birthday_area', title: '籍贯', minWidth: 100 },
  { key: 'now_area', title: '现居地', minWidth: 100 },
  { key: 'contract_begin', title: '合同起始日', minWidth: 110 },
  { key: 'contract_end', title: '合同终止日', minWidth: 110 },
  { key: 'uid', title: '商城用户ID', minWidth: 100 },
  { key: 'account', title: '账号', minWidth: 100 },
  { key: 'has_pwd', title: '密码', minWidth: 80, slot: 'has_pwd' },
  { key: 'is_customer', title: '客服', minWidth: 80, slot: 'is_customer' },
  { key: 'is_reservable', title: '可被预约', minWidth: 90, slot: 'is_reservable' },
  { key: 'customer_num', title: '专属客户数', minWidth: 100 },
  { key: 'department', title: '部门', minWidth: 100 },
  { key: 'salary_status', title: '工资状态', minWidth: 90, slot: 'salary_status' },
  { key: 'birthday_type', title: '生日类型', minWidth: 90, slot: 'birthday_type' },
  { key: 'action', title: '操作', minWidth: 260, slot: 'action', fixed: 'right', fixedColumn: true },
];

const DEFAULT_COLUMN_CONFIG = [
  { key: 'staff_name', show: true },
  { key: 'nickname', show: true },
  { key: 'phone', show: true },
  { key: 'roles', show: true },
  { key: 'position_label', show: true },
  { key: 'position_level_label', show: true },
  { key: 'is_manager', show: true },
  { key: 'can_choose', show: true },
  { key: 'status', show: true },
  { key: 'action', show: true },
];

export default {
  name: 'clerkList',
  components: {
    Details,
    FormModal,
    ColumnSetting,
  },
  data() {
    return {
      options: timeOptions,
      routePre: Setting.routePre,
      currentStoreId: 0,
      currentStoreName: '',
      formModal: false,
      formEditId: 0,
      showColumnSetting: false,
      columnsMeta: COLUMNS_META,
      columnConfig: [...DEFAULT_COLUMN_CONFIG],
      defaultColumnConfig: DEFAULT_COLUMN_CONFIG,
      formData: {
        keyword: '',
        status: '',
        page: 1,
        limit: 10,
      },
      loading: false,
      data: [],
      total: 0,
      staffRow: {},
      editForm: {
        order_id: '',
        staff_id: 0,
      },
      editRow: {},
      editModal: false,
      staffAll: [],
      currentId: 0,
      modal1: false,
      formData1: {
        keyword: '',
        data: '',
        page: 1,
        limit: 20,
      },
      timeVal1: [],
      columns1: [
        { title: 'ID', key: 'uid' },
        { title: '客户昵称', key: 'nickname' },
        { title: '客户手机号', key: 'phone' },
        { title: '专属业绩', key: 'performance_price' },
        { title: '绑定时间', key: 'salesman_time' },
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
      performance: { min: null, max: null },
      price: { min: null, max: null },
      columns2: [
        { title: '订单号', key: 'link_id' },
        { title: '用户信息', slot: 'user' },
        { title: '订单金额', key: 'number' },
        { title: '业绩金额', key: 'number' },
        { title: '下单时间', key: 'add_time' },
      ],
      total2: 0,
      tableData2: [],
      tableBodyHeight: 420,
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
    tableColumns() {
      const cols = [];
      this.columnConfig.forEach((item) => {
        if (item.show === false || item.key === 'action') return;
        const meta = COLUMNS_META.find((m) => m.key === item.key);
        if (meta) cols.push(this.buildTableColumn(meta));
      });
      const actionItem = this.columnConfig.find((c) => c.key === 'action');
      if (!actionItem || actionItem.show !== false) {
        const actionMeta = COLUMNS_META.find((m) => m.key === 'action');
        if (actionMeta) cols.push(this.buildTableColumn(actionMeta));
      }
      return cols;
    },
    tableScrollX() {
      return this.tableColumns.reduce((sum, col) => sum + (col.minWidth || 100), 0);
    },
  },
  created() {
    this.loadCurrentStore();
    this.loadColumnSetting();
    this.getList();
  },
  mounted() {
    this.updateTableHeight();
    window.addEventListener('resize', this.updateTableHeight);
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.updateTableHeight);
  },
  methods: {
    resolveStaffAvatar,
    updateTableHeight() {
      this.$nextTick(() => {
        const el = this.$refs.tableBody;
        if (!el) return;
        this.tableBodyHeight = Math.max(240, el.clientHeight || 420);
      });
    },
    buildTableColumn(meta) {
      const col = {
        title: meta.title,
        minWidth: meta.minWidth || 100,
      };
      if (meta.slot) {
        col.slot = meta.slot;
      } else {
        col.key = meta.key;
      }
      if (meta.fixed) {
        col.fixed = meta.fixed;
      }
      return col;
    },
    loadCurrentStore() {
      storeGetInfoApi()
        .then((res) => {
          const info = res.data || {};
          this.currentStoreId = info.id || 0;
          this.currentStoreName = info.name || '';
        })
        .catch(() => {});
    },
    loadColumnSetting() {
      getStaffColumnSetting({ table_key: 'staff_list_store' })
        .then((res) => {
          const columns = res.data && res.data.columns;
          if (Array.isArray(columns) && columns.length) {
            this.columnConfig = columns;
          }
        })
        .catch(() => {});
    },
    saveColumnConfig(columns) {
      saveStaffColumnSetting({
        table_key: 'staff_list_store',
        columns,
      })
        .then((res) => {
          this.$Message.success(res.msg || '保存成功');
          this.columnConfig = columns;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    goSchedule() {
      this.$router.push({ path: `${this.routePre}/staff/schedule` });
    },
    goCashier(item) {
      cashierLogin(item.id)
        .then((res) => {
          Cookies.set('cashierData', JSON.stringify(res));
          window.open(
            `${window.location.protocol}//${window.location.host}/${res.data.prefix}/login`
          );
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    openForm(id = 0) {
      this.formEditId = Number(id) || 0;
      this.formModal = true;
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
      this.formData.page = 1;
      this.getList();
    },
    getList() {
      this.loading = true;
      const params = { ...this.formData };
      if (params.status === '') delete params.status;
      staffListInfo(params)
        .then((res) => {
          this.data = res.data.list || [];
          this.total = res.data.count || 0;
          this.updateTableHeight();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    del(id, tit, num) {
      const delfromData = {
        title: tit,
        num,
        url: `/staff/staff/${id}`,
        method: 'DELETE',
        ids: '',
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.data.splice(num, 1);
          if (!this.data.length) {
            this.formData.page = this.formData.page === 1 ? 1 : this.formData.page - 1;
          }
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    details(row) {
      this.staffRow = row;
      this.$refs.userDetails.modals = true;
      this.$refs.userDetails.getDetails(row.id);
    },
    handleEdit(row) {
      this.editRow = row;
      this.editForm.order_id = row.order_id;
      this.editForm.staff_id = 0;
      this.editModal = true;
      this.getStaffAll();
    },
    getStaffAll() {
      staffallInfo()
        .then((res) => {
          this.staffAll = (res.data || []).filter((item) => item.value !== this.staffRow.id);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    saveOrderStaff() {
      if (!this.editForm.staff_id) {
        return this.$Message.warning('请选择业绩归属店员');
      }
      orderStaff(this.editForm)
        .then((res) => {
          this.$Message.success(res.msg);
          this.editModal = false;
          this.$refs.userDetails.refreshOrder();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    cancelEditModal() {
      this.editModal = false;
    },
    getCustomerList() {
      staffCustomerList(this.currentId, this.formData1)
        .then((res) => {
          this.tableData1 = res.data.list || [];
          this.total1 = res.data.count || 0;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    getPerformanceList() {
      staffPerformanceList(this.currentId, this.formData2)
        .then((res) => {
          this.tableData2 = res.data.list || [];
          this.total2 = res.data.count || 0;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    handleClick1(id) {
      this.modal1 = true;
      this.currentId = id;
      this.getCustomerList();
    },
    handleSearch1() {
      this.formData1.page = 1;
      this.getCustomerList();
    },
    onDateChange1(date) {
      this.formData1.data = date[0] ? date.join('-') : '';
    },
    onPageChange1(page) {
      this.formData1.page = page;
      this.getCustomerList();
    },
    onModal1Cancel() {
      this.formData1 = { keyword: '', data: '', page: 1, limit: 20 };
      this.timeVal1 = [];
    },
    handleClick2(id) {
      this.modal2 = true;
      this.currentId = id;
      this.getPerformanceList();
    },
    handleSearch2() {
      this.formData2.page = 1;
      this.formData2.performance = this.performance.min ? `${this.performance.min}-${this.performance.max}` : '';
      this.formData2.price = this.price.min ? `${this.price.min}-${this.price.max}` : '';
      this.getPerformanceList();
    },
    onDateChange2(date) {
      this.formData2.data = date[0] ? date.join('-') : '';
    },
    onPageChange2(page) {
      this.formData2.page = page;
      this.getPerformanceList();
    },
    onModal2Cancel() {
      this.formData2 = {
        data: '',
        keyword: '',
        link_id: '',
        price: '',
        performance: '',
        page: 1,
        limit: 20,
      };
      this.timeVal2 = [];
      this.performance = { min: null, max: null };
      this.price = { min: null, max: null };
    },
  },
};
</script>

<style lang="stylus" scoped>
.staff-page
  height calc(100vh - 140px)
  overflow hidden

.staff-card
  height 100%

  >>> .ivu-card-body
    height 100%
    display flex
    flex-direction column
    overflow hidden
    box-sizing border-box

.filter-bar
  display flex
  flex-wrap wrap
  align-items center
  gap 10px
  margin-bottom 12px
  flex-shrink 0

.search-input
  width 220px

.filter-select
  width 160px

.table-wrap
  flex 1
  min-height 0
  display flex
  flex-direction column
  overflow hidden

.table-body
  flex 1
  min-height 0
  overflow hidden

.staff-cell
  display flex
  align-items center
  gap 10px

.staff-avatar
  width 50px
  height 50px
  object-fit cover
  border-radius 4px
  flex-shrink 0

.deleted-tag
  color #ed4014

.action-ops
  display flex
  flex-direction column
  gap 4px
  line-height 1.4
  padding 4px 0

.action-row
  white-space nowrap

.page
  margin-top 12px
  flex-shrink 0

.ml14
  margin-left 14px

.mr10
  margin-right 10px

.ml-10
  margin-left 10px

.input-add
  width 200px

.enter-key
  margin-left 2px
  font-weight 600
</style>
