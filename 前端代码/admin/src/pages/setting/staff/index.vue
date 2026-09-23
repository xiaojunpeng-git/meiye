<template>
  <div class="staff-page">
    <Card :bordered="false" dis-hover :padding="16" class="staff-card">
      <div class="staff-mgmt">
        <div class="staff-list-panel">
          <div class="filter-bar">
            <Input
              v-model="formData.keyword"
              placeholder="请输入导购名称/ID/手机号"
              clearable
              class="search-input"
              @on-enter="orderSearch"
            />
            <OrganizationStoreScopePicker
              v-model="scopeStoreIds"
              :load-scope="loadStaffScope"
              @change="onScopeChange"
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
              <template slot-scope="{ row }" slot="store_name">
                {{ row.is_organization_direct == 1 ? '无店直属' : (row.store_name || '-') }}
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
              <template slot-scope="{ row }" slot="craftsman_performance_type">
                {{ { commission: '消耗业绩', labor: '手工费', commission_labor: '消耗业绩+手工费' }[row.craftsman_performance_type] || '消耗业绩' }}
              </template>
              <template slot-scope="{ row }" slot="employment_type_code">
                {{ { internal: '内部员工', partner: '合作方', outsourced: '外包' }[row.employment_type_code] || '内部员工' }}
              </template>
              <template slot-scope="{ row }" slot="mobile_enabled">
                {{ row.mobile_enabled == 1 ? '开启' : '关闭' }}
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
                    <a @click="openForm(resolveEditId(row))">编辑</a>
                    <Divider type="vertical" />
                    <a @click="openTransfer(row)">调店</a>
                    <Divider type="vertical" />
                    <a @click="openTransferLog(row.id)">调店记录</a>
                  </div>
                  <div class="action-row">
                    <a @click="handleClick1(row.id)">专属客户</a>
                    <Divider
                      v-if="Number(row.is_organization_direct || 0) !== 1 && Number(row.id || 0) > 0"
                      type="vertical"
                    />
                    <a
                      v-if="Number(row.is_organization_direct || 0) !== 1 && Number(row.id || 0) > 0"
                      @click="openPermissionEditor(row)"
                    >权限编辑</a>
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
    <staff-feature-permission-modal
      v-model="permissionModal"
      :staff="permissionStaff"
      @success="getList"
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

  </div>
</template>

<script>
import { mapState } from 'vuex';
import Setting from '@/setting';
import {
  merchantStaffList,
  merchantStaffCustomer,
  exportStaffListExport,
} from '@/api/setting';
import { getOrganizationTree, getOrganizationResourceSelector } from '@/api/store';
import { getStaffColumnSetting, saveStaffColumnSetting } from '@/api/staff';
import exportExcel from '@/utils/newToExcel.js';
import timeOptions from '@/utils/timeOptions';
import FormModal from './add';
import ColumnSetting from './components/ColumnSetting';
import TransferModal from './components/TransferModal';
import TransferLogModal from './components/TransferLogModal';
import OrganizationStoreScopePicker from '@/components/organization/OrganizationStoreScopePicker.vue';
import StaffFeaturePermissionModal from './components/StaffFeaturePermissionModal.vue';

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
  { key: 'organization_name', title: '所属组织', minWidth: 180 },
  { key: 'store_name', title: '所属门店', minWidth: 120, slot: 'store_name' },
  { key: 'staff_name', title: '员工姓名', minWidth: 150, slot: 'staff_name' },
  { key: 'nickname', title: '昵称', minWidth: 100 },
  { key: 'phone', title: '员工手机', minWidth: 110 },
  { key: 'roles', title: '店员身份', minWidth: 120 },
  { key: 'position_label', title: '岗位', minWidth: 100 },
  { key: 'position_level_label', title: '职级', minWidth: 100 },
  { key: 'is_manager', title: '店长', minWidth: 80, slot: 'is_manager' },
  { key: 'cashier_salesperson_enabled', title: '可作为销售人', minWidth: 110, slot: 'cashier_salesperson_enabled' },
  { key: 'cashier_craftsman_enabled', title: '可作为手艺人', minWidth: 110, slot: 'cashier_craftsman_enabled' },
  { key: 'craftsman_performance_type', title: '手艺人服务业绩类型', minWidth: 150, slot: 'craftsman_performance_type' },
  { key: 'employment_type_code', title: '人员类型', minWidth: 100, slot: 'employment_type_code' },
  { key: 'status', title: '在职状态', minWidth: 80, slot: 'status' },
  { key: 'mobile_enabled', title: '手机端', minWidth: 80, slot: 'mobile_enabled' },
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
  { key: 'phone', show: true },
  { key: 'position_label', show: true },
  { key: 'cashier_salesperson_enabled', show: true },
  { key: 'cashier_craftsman_enabled', show: true },
  { key: 'craftsman_performance_type', show: true },
  { key: 'employment_type_code', show: true },
  { key: 'status', show: true },
  { key: 'mobile_enabled', show: true },
  { key: 'action', show: true },
];

const LEGACY_DEFAULT_COLUMN_KEYS = [
  'organization_name', 'store_name', 'staff_name', 'nickname', 'phone', 'roles',
  'position_label', 'position_level_label', 'is_manager', 'cashier_salesperson_enabled',
  'cashier_craftsman_enabled', 'status', 'is_fencheng', 'action',
];

function isLegacyDefaultColumnConfig(columns) {
  const keys = (columns || []).map((column) => column.key);
  return LEGACY_DEFAULT_COLUMN_KEYS.every((key) => keys.includes(key))
    && !['craftsman_performance_type', 'employment_type_code', 'mobile_enabled'].some((key) => keys.includes(key));
}

export default {
  name: 'setting_staff_index',
  components: {
    FormModal,
    ColumnSetting,
    TransferModal,
    TransferLogModal,
    OrganizationStoreScopePicker,
    StaffFeaturePermissionModal,
  },
  data() {
    return {
      options: timeOptions,
      formModal: false,
      formEditId: 0,
      showColumnSetting: false,
      showTransferModal: false,
      showTransferLogModal: false,
      transferStaffRow: {},
      transferLogStaffId: 0,
      permissionModal: false,
      permissionStaff: {},
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
      listRequestSeq: 0,
      data: [],
      scopeStoreIds: [],
      rootOrganizationId: 0,
      rootOrganizationLoaded: false,
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
    this.loadColumnSetting();
    this.loadRootOrganization();
  },
  mounted() {
    this.updateTableHeight();
    window.addEventListener('resize', this.updateTableHeight);
  },
  activated() {
    // 首次进入由组织根节点加载完成后发起查询；缓存页再次激活才主动刷新，避免首屏重复全范围请求。
    if (this.rootOrganizationLoaded) this.getList();
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
    loadRootOrganization() {
      // 组织树只移除页面展示；默认仍以集团根组织查询，保留组织直属员工。
      getOrganizationTree()
        .then((res) => {
          const root = (res.data || [])[0];
          this.rootOrganizationId = root ? Number(root.id) || 0 : 0;
          this.formData.org_id = this.rootOrganizationId;
          this.rootOrganizationLoaded = true;
          this.getList();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
          this.rootOrganizationLoaded = true;
          this.getList();
        });
    },
    loadColumnSetting() {
      getStaffColumnSetting({ table_key: 'staff_list_admin' })
        .then((res) => {
          const columns = res.data && res.data.columns;
          if (Array.isArray(columns) && columns.length) {
            if (isLegacyDefaultColumnConfig(columns)) {
              this.columnConfig = [...DEFAULT_COLUMN_CONFIG];
              return;
            }
            // 旧用户保存的列配置没有“所属组织”，补到 ID 后面，
            // 避免新字段因历史配置而永远不可见。
            const nextColumns = columns.map((column) => ({ ...column }));
            if (!nextColumns.some((column) => column.key === 'organization_name')) {
              const idIndex = nextColumns.findIndex((column) => column.key === 'id');
              nextColumns.splice(idIndex >= 0 ? idIndex + 1 : 0, 0, {
                key: 'organization_name',
                show: true,
              });
            }
            this.columnConfig = nextColumns;
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
    /**
     * 组织直属人员在列表中使用负 employee_id 作为只读占位 ID，
     * 但完整人员编辑接口的路径主键必须是 employee_id。门店任职
     * 仍沿用真实 staff_id，避免负占位值把“编辑”误开成新建表单。
     */
    resolveEditId(row = {}) {
      const staffId = Number(row.id || 0);
      if (Number(row.is_organization_direct || 0) === 1 || staffId <= 0) {
        return Number(row.employee_id || 0);
      }
      return staffId;
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
    loadStaffScope() {
      return getOrganizationResourceSelector({
        resource: 'org_store_tree',
        page: 1,
        limit: 50,
      });
    },
    onScopeChange(scope = {}) {
      this.formData.page = 1;
      if (scope.nodeType === 'org' && Number(scope.orgId) > 0) {
        this.formData.org_id = Number(scope.orgId);
        this.formData.store_id = '';
      } else if (scope.nodeType === 'store' && Number(scope.storeId) > 0) {
        this.formData.org_id = this.rootOrganizationId;
        this.formData.store_id = Number(scope.storeId);
      } else {
        this.formData.org_id = this.rootOrganizationId;
        this.formData.store_id = '';
      }
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
      const requestSeq = ++this.listRequestSeq;
      this.loading = true;
      // 每次只请求当前页，门店筛选和后端数据权限仍由统一列表接口处理。
      const params = { ...this.formData };
      if (!params.store_id) delete params.store_id;
      if (params.status === '') delete params.status;
      merchantStaffList(params)
        .then((res) => {
          // 快速翻页时旧请求可能晚返回；只允许最后一次请求更新清单。
          if (requestSeq !== this.listRequestSeq) return;
          this.data = res.data.list || [];
          this.total = res.data.count || 0;
          this.updateTableHeight();
        })
        .catch((err) => {
          if (requestSeq !== this.listRequestSeq) return;
          this.$Message.error(err.msg);
        })
        .finally(() => {
          if (requestSeq === this.listRequestSeq) this.loading = false;
        });
    },
    getCustomerList() {
      merchantStaffCustomer(this.currentId, this.formData1).then((res) => {
        this.tableData1 = res.data.list || [];
        this.total1 = res.data.count || 0;
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
    openPermissionEditor(row) {
      if (!row || Number(row.is_organization_direct || 0) === 1 || Number(row.id || 0) <= 0) return;
      this.permissionStaff = row;
      this.permissionModal = true;
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
