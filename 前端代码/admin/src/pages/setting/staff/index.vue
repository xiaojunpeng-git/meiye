<template>
  <div class="staff-page">
    <Card :bordered="false" dis-hover :padding="16" class="staff-card">
      <div class="staff-mgmt">
        <div class="org-tree-panel">
          <div class="panel-header">
            <h3 class="panel-title">组织</h3>
            <div class="panel-actions">
              <Tooltip content="展开全部" transfer>
                <span class="btn-icon" @click="expandAllTree"><Icon type="md-expand" /></span>
              </Tooltip>
              <Tooltip content="收起全部" transfer>
                <span class="btn-icon" @click="collapseAllTree"><Icon type="md-contract" /></span>
              </Tooltip>
            </div>
          </div>
          <div class="tree-wrap">
            <Spin v-if="treeLoading" size="large" class="tree-spin"></Spin>
            <div v-show="!treeLoading && orgTree.length" class="tree-container">
              <org-tree-node
                v-for="node in orgTree"
                :key="node.id"
                :node="node"
                :selected-id="selectedOrgId"
                @select="onTreeSelectNode"
                @toggle="toggleTreeNode"
              />
            </div>
            <div v-if="!treeLoading && !orgTree.length" class="tree-empty">暂无组织数据</div>
          </div>
        </div>

        <div class="staff-list-panel">
          <div class="filter-bar">
            <Input
              v-model="formData.keyword"
              placeholder="请输入导购名称/ID/手机号"
              clearable
              class="search-input"
              @on-enter="orderSearch"
            />
            <Select
              v-model="formData.store_id"
              clearable
              filterable
              class="filter-select"
              placeholder="所属门店"
              @on-change="orderSearch"
            >
              <Option v-for="item in storeList" :value="item.id" :key="item.id">{{ item.name }}</Option>
            </Select>
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
            <Button type="primary" class="ml14" @click="openForm(0)">新建店员</Button>
            <Button class="ml14" @click="showColumnSetting = true">列设置</Button>
            <Button
              v-auth="['export-userCommission']"
              class="ml14"
              @click="exports"
            >导出</Button>
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
                  <span>{{ row.staff_name || '-' }}</span>
                </div>
              </template>
              <template slot-scope="{ row }" slot="is_manager">
                {{ row.is_manager == 1 ? '是' : '否' }}
              </template>
              <template slot-scope="{ row }" slot="cashier_salesperson_enabled">
                {{ row.cashier_salesperson_enabled == 1 ? '是' : '否' }}
              </template>
              <template slot-scope="{ row }" slot="cashier_craftsman_enabled">
                {{ row.cashier_craftsman_enabled == 1 ? '是' : '否' }}
              </template>
              <template slot-scope="{ row }" slot="status">
                {{ row.status == 1 ? '在职' : '离职' }}
              </template>
              <template slot-scope="{ row }" slot="is_fencheng">
                {{ row.is_fencheng == 1 ? '参与' : '不参与' }}
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
              <template slot-scope="{ row }" slot="action">
                <div class="action-ops">
                  <div class="action-row">
                    <a @click="openForm(row.id)">编辑</a>
                    <Divider type="vertical" />
                    <a @click="openTransfer(row)">调店</a>
                    <Divider type="vertical" />
                    <a @click="openTransferLog(row.id)">调店记录</a>
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
        </div>
      </div>
    </Card>

    <form-modal v-model="formModal" :edit-id="formEditId" @success="getList" />
    <column-setting
      v-model="showColumnSetting"
      :columns-meta="columnsMeta"
      :columns="columnConfig"
      :default-columns="defaultColumnConfig"
      @save="saveColumnConfig"
    />
    <transfer-modal v-model="showTransferModal" :staff-row="transferStaffRow" @success="getList" />
    <transfer-log-modal v-model="showTransferLogModal" :staff-id="transferLogStaffId" />

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
import { getOrganizationTree } from '@/api/store';
import { getStaffColumnSetting, saveStaffColumnSetting } from '@/api/staff';
import exportExcel from '@/utils/newToExcel.js';
import timeOptions from '@/utils/timeOptions';
import FormModal from './add';
import OrgTreeNode from './components/OrgTreeNode';
import ColumnSetting from './components/ColumnSetting';
import TransferModal from './components/TransferModal';
import TransferLogModal from './components/TransferLogModal';

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
  { key: 'store_name', title: '所属门店', minWidth: 120 },
  { key: 'staff_name', title: '店员名称', minWidth: 150, slot: 'staff_name' },
  { key: 'nickname', title: '昵称', minWidth: 100 },
  { key: 'phone', title: '手机号', minWidth: 110 },
  { key: 'roles', title: '店员身份', minWidth: 120 },
  { key: 'position_label', title: '职位', minWidth: 100 },
  { key: 'position_level_label', title: '职级', minWidth: 100 },
  { key: 'is_manager', title: '店长', minWidth: 80, slot: 'is_manager' },
  { key: 'cashier_salesperson_enabled', title: '可作为销售人', minWidth: 110, slot: 'cashier_salesperson_enabled' },
  { key: 'cashier_craftsman_enabled', title: '可作为手艺人', minWidth: 110, slot: 'cashier_craftsman_enabled' },
  { key: 'status', title: '在职状态', minWidth: 80, slot: 'status' },
  { key: 'is_fencheng', title: '参与分成', minWidth: 90, slot: 'is_fencheng' },
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
  { key: 'action', title: '操作', minWidth: 220, slot: 'action', fixed: 'right', fixedColumn: true },
];

const DEFAULT_COLUMN_CONFIG = [
  { key: 'store_name', show: true },
  { key: 'staff_name', show: true },
  { key: 'nickname', show: true },
  { key: 'phone', show: true },
  { key: 'roles', show: true },
  { key: 'position_label', show: true },
  { key: 'position_level_label', show: true },
  { key: 'is_manager', show: true },
  { key: 'cashier_salesperson_enabled', show: true },
  { key: 'cashier_craftsman_enabled', show: true },
  { key: 'status', show: true },
  { key: 'is_fencheng', show: true },
  { key: 'action', show: true },
];

export default {
  name: 'setting_staff_index',
  components: {
    FormModal,
    OrgTreeNode,
    ColumnSetting,
    TransferModal,
    TransferLogModal,
  },
  data() {
    return {
      options: timeOptions,
      treeLoading: false,
      orgTree: [],
      selectedOrgId: 0,
      formModal: false,
      formEditId: 0,
      showColumnSetting: false,
      showTransferModal: false,
      showTransferLogModal: false,
      transferStaffRow: {},
      transferLogStaffId: 0,
      columnsMeta: COLUMNS_META,
      columnConfig: [...DEFAULT_COLUMN_CONFIG],
      defaultColumnConfig: DEFAULT_COLUMN_CONFIG,
      formData: {
        org_id: 0,
        store_id: '',
        keyword: '',
        status: '',
        page: 1,
        limit: 10,
      },
      loading: false,
      data: [],
      storeList: [],
      total: 0,
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
      performance: { min: '', max: '' },
      price: { min: '', max: '' },
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
    ...mapState('admin/layout', ['isMobile']),
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
    this.getStoreList();
    this.loadColumnSetting();
    this.loadOrgTree();
  },
  mounted() {
    this.updateTableHeight();
    window.addEventListener('resize', this.updateTableHeight);
  },
  activated() {
    if (this.selectedOrgId) {
      this.getList();
    }
    this.$nextTick(this.updateTableHeight);
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
        const h = el.clientHeight;
        this.tableBodyHeight = Math.max(240, h || 420);
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
    initTreeNodes(list, level = 0) {
      return (list || []).map((node) => ({
        ...node,
        expanded: level === 0,
        children: node.children ? this.initTreeNodes(node.children, level + 1) : [],
      }));
    },
    loadOrgTree() {
      this.treeLoading = true;
      getOrganizationTree()
        .then((res) => {
          const list = res.data || [];
          const firstRoot = list.length ? list[0] : null;
          if (firstRoot && !this.selectedOrgId) {
            this.selectedOrgId = firstRoot.id;
          }
          this.orgTree = this.initTreeNodes(list);
          if (this.selectedOrgId) {
            this.formData.org_id = this.selectedOrgId;
            this.getList();
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        })
        .finally(() => {
          this.treeLoading = false;
        });
    },
    onTreeSelectNode(node) {
      if (!node) return;
      this.selectedOrgId = node.id;
      this.formData.org_id = node.id;
      this.formData.store_id = '';
      this.formData.page = 1;
      this.getList();
    },
    toggleTreeNode(node) {
      this.$set(node, 'expanded', !node.expanded);
    },
    expandAllTree() {
      this.setTreeExpand(this.orgTree, true);
    },
    collapseAllTree() {
      this.setTreeExpand(this.orgTree, false);
    },
    setTreeExpand(nodes, expand) {
      (nodes || []).forEach((n) => {
        if (n.children && n.children.length) {
          this.$set(n, 'expanded', expand);
          this.setTreeExpand(n.children, expand);
        }
      });
    },
    loadColumnSetting() {
      getStaffColumnSetting({ table_key: 'staff_list_admin' })
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
        table_key: 'staff_list_admin',
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
    openForm(id = 0) {
      this.formEditId = Number(id) || 0;
      this.formModal = true;
    },
    openTransfer(row) {
      this.transferStaffRow = {
        id: row.id,
        store_id: row.store_id,
        staff_name: row.staff_name,
        store_name: row.store_name || row.name,
      };
      this.showTransferModal = true;
    },
    openTransferLog(id) {
      this.transferLogStaffId = Number(id) || 0;
      this.showTransferLogModal = true;
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
    getStoreList() {
      merchantStoreListApi()
        .then((res) => {
          this.storeList = res.data || [];
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    getList() {
      if (!this.formData.org_id) return;
      this.loading = true;
      const params = { ...this.formData };
      if (!params.store_id) delete params.store_id;
      if (params.status === '') delete params.status;
      merchantStaffList(params)
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
    getCustomerList() {
      merchantStaffCustomer(this.currentId, this.formData1).then((res) => {
        this.tableData1 = res.data.list || [];
        this.total1 = res.data.count || 0;
      });
    },
    getPerformanceList() {
      merchantStaffPerformance(this.currentId, this.formData2).then((res) => {
        this.tableData2 = res.data.list || [];
        this.total2 = res.data.count || 0;
      });
    },
    getExcelData(excelData) {
      return new Promise((resolve) => {
        exportStaffListExport(excelData).then((res) => resolve(res.data));
      });
    },
    async exports() {
      let [th, filekey, data, fileName] = [[], [], [], ''];
      const excelData = JSON.parse(JSON.stringify(this.formData));
      if (!excelData.store_id) delete excelData.store_id;
      if (excelData.status === '') delete excelData.status;
      for (let i = 0; i < excelData.page + 1; i++) {
        const lebData = await this.getExcelData(excelData);
        if (!fileName) fileName = lebData.filename;
        if (!filekey.length) filekey = lebData.filekey;
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
      this.performance = { min: '', max: '' };
      this.price = { min: '', max: '' };
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

.staff-mgmt
  display flex
  gap 16px
  flex 1
  min-height 0
  width 100%
  align-items stretch
  overflow hidden

.org-tree-panel
  width 250px
  flex-shrink 0
  background #fff
  border-radius 8px
  box-shadow 0 2px 8px rgba(0, 0, 0, 0.06)
  display flex
  flex-direction column
  min-height 0
  overflow hidden

.panel-header
  display flex
  align-items center
  justify-content space-between
  padding 14px 16px
  border-bottom 1px solid #f0f0f0
  flex-shrink 0

.panel-title
  margin 0
  font-size 15px
  font-weight 600

.panel-actions
  display flex
  gap 8px

.btn-icon
  width 28px
  height 28px
  display flex
  align-items center
  justify-content center
  border-radius 4px
  cursor pointer
  color #666

  &:hover
    background #ecf5ff
    color #2d8cf0

.tree-wrap
  flex 1
  overflow auto
  padding 8px
  position relative
  min-height 0

.tree-spin
  position absolute
  top 50%
  left 50%
  transform translate(-50%, -50%)

.tree-empty
  text-align center
  color #999
  padding 40px 0

.staff-list-panel
  flex 1
  min-width 0
  min-height 0
  display flex
  flex-direction column
  overflow hidden

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

.enter-key
  margin-left 2px
  font-weight 600
</style>
