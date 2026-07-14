<template>
  <div>
  <Card :bordered="false" dis-hover :padding="16">
  <div class="region-mgmt">
    <!-- 左侧区域树 -->
    <div class="region-tree-panel">
      <div class="panel-header">
        <h3 class="panel-title">区域架构</h3>
        <div class="panel-actions">
          <Tooltip content="展开全部" transfer>
            <span class="btn-icon" @click="expandAllTree"><Icon type="md-expand" /></span>
          </Tooltip>
          <Tooltip content="收起全部" transfer>
            <span class="btn-icon" @click="collapseAllTree"><Icon type="md-contract" /></span>
          </Tooltip>
          <Tooltip content="添加区域" transfer>
            <span class="btn-icon" @click="openAddRegionModal" v-auth="['admin-store-region_create']">
              <Icon type="md-add" />
            </span>
          </Tooltip>
        </div>
      </div>
      <div class="tree-wrap">
        <Spin v-if="treeLoading" size="large" class="tree-spin"></Spin>
        <div v-show="!treeLoading && regionTree.length" class="tree-container">
          <region-tree-node
            v-for="node in regionTree"
            :key="node.id"
            :node="node"
            :selected-id="selectedRegionId"
            :count-field="treeCountField"
            @select="onTreeSelectNode"
            @toggle="toggleTreeNode"
            @edit="openEditRegionModal"
            @delete="confirmDeleteRegion"
          />
        </div>
        <div v-if="!treeLoading && !regionTree.length" class="tree-empty">暂无区域数据</div>
      </div>
    </div>

    <!-- 右侧内容 -->
    <div class="store-list-panel">
      <div class="panel-header panel-header-right">
        <div class="panel-header-left">
          <div class="tab-bar">
            <span
              class="tab-item"
              :class="{ active: activeTab === 'store' }"
              @click="switchTab('store')"
            >门店列表</span>
            <span
              class="tab-item"
              :class="{ active: activeTab === 'manager' }"
              @click="switchTab('manager')"
            >管理人员</span>
          </div>
        </div>
        <Button
          type="primary"
          v-auth="[activeTab === 'store' ? 'admin-store-add_store' : 'admin-store-region_create']"
          @click="addStoreOrManager"
        >
          <Icon type="md-add" /> {{ activeTab === 'store' ? '添加门店' : '添加管理人员' }}
        </Button>
      </div>

      <!-- 门店筛选 -->
      <div class="filter-bar" v-show="activeTab === 'store'">
        <div class="search-input">
          <Icon type="ios-search" />
          <Input
            v-model="storeForm.keywords"
            placeholder="搜索门店名称/编号"
            clearable
            @on-enter="searchStore"
          />
        </div>
        <Select
          v-model="storeForm.type"
          placeholder="门店类型"
          clearable
          class="filter-select"
          @on-change="searchStore"
        >
          <Option value="all">全部类型</Option>
          <Option value="1">自营店</Option>
          <Option value="2">加盟店</Option>
        </Select>
        <Select
          v-model="storeForm.status"
          placeholder="营业状态"
          clearable
          class="filter-select"
          @on-change="searchStore"
        >
          <Option value="1">营业中</Option>
          <Option value="-1">已停业</Option>
        </Select>
        <Button class="btn-secondary" @click="resetStore">重置</Button>
        <Button type="primary" @click="searchStore">查询</Button>
      </div>

      <!-- 管理人员筛选 -->
      <div class="filter-bar" v-show="activeTab === 'manager'">
        <div class="search-input">
          <Icon type="ios-search" />
          <Input
            v-model="regionFrom.keyword"
            placeholder="搜索区域名称/ID"
            clearable
            @on-enter="searchManager"
          />
        </div>
        <Input
          v-model="regionFrom.agent_admin"
          placeholder="搜索管理员姓名/手机号"
          clearable
          class="filter-select-wide"
          @on-enter="searchManager"
        />
        <Select
          v-model="regionFrom.is_alone"
          placeholder="区域隔离"
          clearable
          class="filter-select"
          @on-change="searchManager"
        >
          <Option value="1">已开启</Option>
          <Option value="0">未开启</Option>
        </Select>
        <Button class="btn-secondary" @click="resetManager">重置</Button>
        <Button type="primary" @click="searchManager">查询</Button>
      </div>

      <!-- 门店表格 -->
      <div v-show="activeTab === 'store'" class="table-wrap">
        <Table
          :columns="storeColumns"
          :data="storeList"
          :loading="storeLoading"
          highlight-row
          no-data-text="暂无门店数据"
        >
          <template slot-scope="{ row }" slot="storeInfo">
            <div class="store-info">
              <div class="store-avatar">
                <img v-if="row.image" :src="row.image" />
                <Icon v-else type="md-home" size="20" />
              </div>
              <div class="store-details">
                <div class="store-name">{{ row.name }}</div>
                <div class="store-address line1">{{ row.address }}</div>
              </div>
            </div>
          </template>
          <template slot-scope="{ row }" slot="status">
            <span class="status-badge" :class="row.is_show == 1 ? 'active' : 'inactive'">
              <Icon type="md-circle" size="12" />
              {{ row.status_name || (row.is_show == 1 ? '营业中' : '已停业') }}
            </span>
          </template>
          <template slot-scope="{ row }" slot="manager">
            <div class="manager-info" v-if="row.region_manager_name">
              <span class="manager-avatar">{{ (row.region_manager_name || '-').charAt(0) }}</span>
              <span>{{ row.region_manager_name }}</span>
            </div>
            <span v-else>-</span>
          </template>
          <template slot-scope="{ row, index }" slot="storeAction">
            <div class="action-btns">
              <button class="action-btn edit" @click="gostore(row)">进入门店</button>
              <button class="action-btn more" @click="operation(row)">
                {{ row.is_show == 0 ? '开业' : '停业' }}
              </button>
              <Dropdown @on-click="changeStoreMenu(row, $event, index)" transfer>
                <button class="action-btn more">
                  更多 <Icon type="ios-arrow-down" size="12" />
                </button>
                <DropdownMenu slot="list">
                  <DropdownItem name="edit" v-auth="['admin-store-edit_store']">编辑</DropdownItem>
                  <DropdownItem name="del" v-auth="['admin-store-delete_store']">删除</DropdownItem>
                </DropdownMenu>
              </Dropdown>
            </div>
          </template>
        </Table>
        <div class="pagination">
          <span class="page-info">共 {{ storeTotal }} 条</span>
          <Page
            :total="storeTotal"
            :current="storeForm.page"
            show-elevator
            @on-change="storePageChange"
            :page-size="storeForm.limit"
          />
        </div>
      </div>

      <!-- 管理人员表格 -->
      <div v-show="activeTab === 'manager'" class="table-wrap">
        <Table
          :columns="managerColumns"
          :data="managerList"
          :loading="managerLoading"
          highlight-row
          no-data-text="暂无管理人员数据"
        >
          <template slot-scope="{ row }" slot="admin">
            <div class="manager-info">
              <span class="manager-avatar">{{ (row.admin_name || '-').charAt(0) }}</span>
              <span>{{ row.admin_name || '-' }}</span>
            </div>
          </template>
          <template slot-scope="{ row }" slot="alone">
            <i-switch
              v-model="row.is_alone"
              :true-value="1"
              :false-value="0"
              @on-change="onchangeIsAlone(row)"
              size="large"
            >
              <span slot="open">开启</span>
              <span slot="close">关闭</span>
            </i-switch>
          </template>
          <template slot-scope="{ row, index }" slot="managerAction">
            <div class="action-links">
              <span class="action-link" @click="goAgent(row)">授权</span>
              <span class="action-link" @click="edit(row.id)" v-auth="['admin-store-region_edit']">编辑</span>
              <Dropdown @on-click="changeMenu(row, $event, index)" transfer>
                <span class="action-link">更多 <Icon type="ios-arrow-down" size="12" /></span>
                <DropdownMenu slot="list">
                  <DropdownItem name="1">管理门店</DropdownItem>
                  <DropdownItem name="2" v-auth="['admin-store-region_delete']">删除</DropdownItem>
                </DropdownMenu>
              </Dropdown>
            </div>
          </template>
        </Table>
        <div class="pagination">
          <span class="page-info">共 {{ managerTotal }} 条</span>
          <Page
            :total="managerTotal"
            :current="regionFrom.page"
            show-elevator
            @on-change="managerPageChange"
            :page-size="regionFrom.limit"
          />
        </div>
      </div>
    </div>
  </div>
  </Card>

    <!-- 快捷添加/编辑区域 -->
    <Modal
      v-model="addRegionModal"
      :title="editRegionId ? '编辑区域' : '添加区域'"
      footer-hide
      scrollable
      width="480"
      @on-cancel="closeRegionModal"
    >
      <div class="modal-body">
        <div class="form-item">
          <label class="form-label">
            <span class="required">*</span>
            上级区域：
          </label>
          <Select
            v-model="regionForm.pid"
            class="form-select"
            placeholder="请选择上级区域"
          >
            <Option :value="0">根区域</Option>
            <Option v-for="item in parentRegionOptions" :key="item.id" :value="item.id">
              {{ item.label }}
            </Option>
          </Select>
        </div>
        <div class="form-item">
          <label class="form-label">
            <span class="required">*</span>
            区域名称：
          </label>
          <div class="form-input-wrapper">
            <Input
              v-model="regionForm.name"
              class="form-input"
              placeholder="请输入区域名称"
              :maxlength="20"
            />
            <span class="char-count">{{ (regionForm.name || '').length }}/20</span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <Button class="btn-default" @click="closeRegionModal">取消</Button>
        <Button type="primary" @click="confirmAddRegion">确定</Button>
      </div>
    </Modal>

    <!-- 管理门店 -->
    <Modal
      v-model="manageStoreModal"
      title="管理门店"
      scrollable
      width="720"
      @on-cancel="manageStoreModal = false"
    >
      <Spin v-if="manageStoreLoading" size="large" fix></Spin>
      <div v-if="!manageStoreLoading" class="manage-store-modal">
        <div class="manage-store-section">
          <div class="section-title">添加门店</div>
          <Button type="primary" icon="md-add" @click="openStorePickerModal">选择门店</Button>
        </div>
        <div class="manage-store-section">
          <div class="section-title">已管理门店（{{ managedStoreList.length }}）</div>
          <div v-if="managedStoreList.length" class="managed-store-chips">
            <div
              v-for="store in managedStoreList"
              :key="store.id"
              class="store-chip"
            >
              <span class="chip-name" :title="store.name">{{ store.name }}</span>
              <span class="chip-close" @click="removeManagedStore(store.id)">
                <Icon type="md-close" />
              </span>
            </div>
          </div>
          <div v-else class="empty-tip">暂未分配门店，请点击上方「选择门店」添加</div>
        </div>
      </div>
      <div slot="footer">
        <Button @click="manageStoreModal = false">取消</Button>
        <Button type="primary" :loading="manageStoreSaving" @click="saveManagedStores">保存</Button>
      </div>
    </Modal>

    <!-- 门店选择弹窗 -->
    <Modal
      v-model="storePickerModal"
      title="选择门店"
      scrollable
      width="800"
      class-name="store-picker-modal"
      @on-cancel="closeStorePickerModal"
    >
      <div class="store-picker-toolbar">
        <Input
          v-model="storePickerForm.keywords"
          placeholder="搜索门店名称"
          clearable
          class="store-picker-search"
          @on-enter="searchStorePicker"
        >
          <Icon type="ios-search" slot="prefix" />
        </Input>
        <Button type="primary" @click="searchStorePicker">搜索</Button>
      </div>
      <Table
        ref="storePickerTable"
        :columns="storePickerColumns"
        :data="storePickerList"
        :loading="storePickerLoading"
        max-height="400"
        @on-selection-change="onStorePickerSelectionChange"
        no-data-text="暂无可选门店"
      />
      <div class="store-picker-pagination">
        <span class="page-info">共 {{ storePickerTotal }} 条</span>
        <Page
          :total="storePickerTotal"
          :current="storePickerForm.page"
          :page-size="storePickerForm.limit"
          show-elevator
          show-total
          @on-change="storePickerPageChange"
        />
      </div>
      <div slot="footer">
        <span class="picker-selected-tip">已选 {{ pickerSelectedCount }} 个门店</span>
        <Button @click="closeStorePickerModal">取消</Button>
        <Button type="primary" :disabled="pickerSelectedCount === 0" @click="confirmStorePicker">确定</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import Setting from "@/setting";
import util from "@/libs/util";
import RegionTreeNode from "./components/RegionTreeNode";
import {
  getRegionList,
  getRegionManageTree,
  getRegionManageCounts,
  getRegionManageAll,
  getRegionManageInfo,
  postRegionManage,
  deleteRegionManage,
  putRegionSetAlone,
  getAgentLogin,
  getAgentManageStores,
  saveAgentManageStores,
  storeListApi,
  storeLogin,
  storeSetShowApi,
} from "@/api/store";

export default {
  name: "regionList",
  components: { RegionTreeNode },
  data() {
    return {
      roterPre: Setting.roterPre,
      activeTab: "store",
      treeLoading: false,
      regionTree: [],
      selectedRegionId: 0,
      selectedRegionName: "",
      addRegionModal: false,
      editRegionId: 0,
      regionForm: {
        pid: 0,
        name: "",
      },
      parentRegionOptions: [],
      manageStoreModal: false,
      manageStoreLoading: false,
      manageStoreSaving: false,
      currentAgentId: 0,
      currentManageRegionId: 0,
      managedStoreList: [],
      managedStoreIds: [],
      storePickerModal: false,
      storePickerLoading: false,
      storePickerList: [],
      storePickerTotal: 0,
      storePickerForm: {
        keywords: "",
        page: 1,
        limit: 10,
      },
      pickerSelectedMap: {},
      storePickerColumns: [
        { type: "selection", width: 55, align: "center" },
        { title: "ID", key: "id", width: 70 },
        { title: "门店名称", key: "name", minWidth: 160 },
        { title: "联系电话", key: "phone", minWidth: 120 },
        { title: "门店地址", key: "address", ellipsis: true, minWidth: 180 },
      ],
      // 门店
      storeLoading: false,
      storeList: [],
      storeTotal: 0,
      storeForm: {
        page: 1,
        limit: 15,
        keywords: "",
        status: "",
        type: "all",
        manage_region_id: "",
      },
      storeColumns: [
        { title: "ID", key: "id", width: 70 },
        { title: "门店信息", slot: "storeInfo", minWidth: 220 },
        { title: "门店类型", key: "type_name", minWidth: 90 },
        { title: "联系电话", key: "phone", minWidth: 120 },
        { title: "门店地址", key: "address", ellipsis: true, minWidth: 160 },
        { title: "营业时间", key: "day_time", minWidth: 120 },
        { title: "营业状态", slot: "status", minWidth: 100 },
        { title: "所属区域", key: "manage_region_name", minWidth: 100 },
        { title: "管理人员", slot: "manager", minWidth: 120 },
        { title: "操作", slot: "storeAction", fixed: "right", minWidth: 200 },
      ],
      // 管理人员（区域代理）
      managerLoading: false,
      managerList: [],
      managerTotal: 0,
      regionFrom: {
        page: 1,
        limit: 15,
        is_alone: "",
        keyword: "",
        agent_admin: "",
        manage_region_id: 0,
      },
      managerColumns: [
        { title: "ID", key: "id", width: 70 },
        { title: "区域名称", key: "manage_region_name", minWidth: 120 },
        { title: "联系人名称", key: "name", minWidth: 120 },
        { title: "管理员", slot: "admin", minWidth: 140 },
        { title: "联系方式", key: "phone", minWidth: 120 },
        { title: "门店数量", key: "store_count", minWidth: 90 },
        { title: "排序", key: "sort", width: 80 },
        { title: "区域隔离", slot: "alone", minWidth: 110 },
        { title: "操作", slot: "managerAction", fixed: "right", width: 200 },
      ],
    };
  },
  computed: {
    pickerSelectedCount() {
      return Object.keys(this.pickerSelectedMap || {}).length;
    },
    treeCountField() {
      return this.activeTab === "manager" ? "agent_count" : "store_count";
    },
  },
  created() {
    if (this.$route.query.tab === "manager") {
      this.activeTab = "manager";
    }
    this.loadRegionTree();
  },
  activated() {
    if (this.regionTree && this.regionTree.length) {
      this.refreshRegionTreeCounts();
      this.applyRegionFilter();
    } else {
      this.loadRegionTree();
    }
  },
  watch: {
    "$route.fullPath"() {
      if ((this.$route.path || "").includes("/store/region/list")) {
        if (this.$route.query.tab === "manager") {
          this.activeTab = "manager";
        } else if (this.$route.query.tab === "store") {
          this.activeTab = "store";
        }
        if (this.regionTree && this.regionTree.length) {
          this.refreshRegionTreeCounts();
          this.applyRegionFilter();
        } else {
          this.loadRegionTree({ skipFilter: !!this.selectedRegionId });
        }
      }
    },
  },
  methods: {
    collectExpandedIds(nodes, ids = []) {
      (nodes || []).forEach((node) => {
        if (node.expanded) ids.push(node.id);
        if (node.children && node.children.length) {
          this.collectExpandedIds(node.children, ids);
        }
      });
      return ids;
    },
    restoreExpandedState(nodes, expandedIds) {
      (nodes || []).forEach((node) => {
        if (expandedIds.includes(node.id)) {
          this.$set(node, "expanded", true);
        }
        if (node.children && node.children.length) {
          this.restoreExpandedState(node.children, expandedIds);
        }
      });
    },
    syncSelectedRegionCount(field, total) {
      if (!this.selectedRegionId) return;
      this.updateTreeNodeField(this.regionTree, this.selectedRegionId, field, total);
    },
    updateTreeNodeField(nodes, id, field, value) {
      for (let i = 0; i < (nodes || []).length; i++) {
        const node = nodes[i];
        if (Number(node.id) === Number(id)) {
          this.$set(node, field, value);
          return true;
        }
        if (node.children && node.children.length) {
          if (this.updateTreeNodeField(node.children, id, field, value)) {
            return true;
          }
        }
      }
      return false;
    },
    refreshRegionTreeCounts() {
      if (!this.regionTree || !this.regionTree.length) return;
      if (this._treeCountTimer) clearTimeout(this._treeCountTimer);
      this._treeCountTimer = setTimeout(() => {
        getRegionManageCounts()
          .then((res) => {
            this.patchTreeCounts(this.regionTree, res.data || {});
          })
          .catch(() => {});
      }, 80);
    },
    patchTreeCounts(nodes, countsMap) {
      (nodes || []).forEach((node) => {
        const counts = countsMap[node.id];
        if (counts) {
          if (counts.store_count !== undefined) {
            this.$set(node, "store_count", counts.store_count);
          }
          if (counts.agent_count !== undefined) {
            this.$set(node, "agent_count", counts.agent_count);
          }
        }
        if (node.children && node.children.length) {
          this.patchTreeCounts(node.children, countsMap);
        }
      });
    },
    initTreeNodes(list, level = 0) {
      return (list || []).map((item) => {
        const children = item.children && item.children.length
          ? this.initTreeNodes(item.children, level + 1)
          : [];
        return {
          ...item,
          children,
          expanded: level < 1,
        };
      });
    },
    loadRegionTree(options = {}) {
      const { skipFilter = false, preserveExpand = false } = options;
      const expandedIds = preserveExpand ? this.collectExpandedIds(this.regionTree) : [];
      this.treeLoading = true;
      getRegionManageTree()
        .then((res) => {
          const list = res.data || [];
          const firstNode = this.findFirstNode(list);
          if (firstNode && !this.selectedRegionId) {
            this.selectedRegionId = firstNode.id;
            this.selectedRegionName = firstNode.name;
          }
          this.regionTree = this.initTreeNodes(list);
          if (preserveExpand && expandedIds.length) {
            this.restoreExpandedState(this.regionTree, expandedIds);
          }
          if (this.selectedRegionId) {
            this.markTreeSelected(this.regionTree, this.selectedRegionId);
            if (!skipFilter) {
              this.applyRegionFilter();
            }
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        })
        .finally(() => {
          this.treeLoading = false;
        });
    },
    findFirstNode(list) {
      if (!list || !list.length) return null;
      const node = list[0];
      if (node.children && node.children.length) {
        return this.findFirstNode(node.children) || node;
      }
      return node;
    },
    markTreeSelected(nodes, id) {
      (nodes || []).forEach((n) => {
        if (n.id === id) {
          this.selectedRegionName = n.name;
        }
        if (n.children && n.children.length) {
          this.markTreeSelected(n.children, id);
        }
      });
    },
    onTreeSelectNode(node) {
      if (!node) return;
      this.selectedRegionId = node.id;
      this.selectedRegionName = node.name;
      this.applyRegionFilter();
    },
    toggleTreeNode(node) {
      this.$set(node, "expanded", !node.expanded);
    },
    applyRegionFilter() {
      this.regionFrom.manage_region_id = this.selectedRegionId || 0;
      this.regionFrom.page = 1;
      this.storeForm.manage_region_id = this.selectedRegionId > 0 ? this.selectedRegionId : "";
      this.storeForm.page = 1;
      if (this.activeTab === "store") {
        this.getStoreList();
      } else {
        this.getManagerList();
      }
    },
    expandAllTree() {
      this.setTreeExpand(this.regionTree, true);
    },
    collapseAllTree() {
      this.setTreeExpand(this.regionTree, false);
    },
    setTreeExpand(nodes, expand) {
      (nodes || []).forEach((n) => {
        if (n.children && n.children.length) {
          this.$set(n, "expanded", expand);
          this.setTreeExpand(n.children, expand);
        }
      });
    },
    switchTab(tab) {
      this.activeTab = tab;
      this.refreshRegionTreeCounts();
      if (tab === "store") {
        this.getStoreList();
      } else {
        this.getManagerList();
      }
    },
    addStoreOrManager() {
      if (this.activeTab === "store") {
        this.addStore();
      } else {
        this.addRegion();
      }
    },
    // ---------- 门店 ----------
    getStoreList() {
      this.storeLoading = true;
      const params = { ...this.storeForm };
      if (!params.manage_region_id) delete params.manage_region_id;
      storeListApi(params)
        .then((res) => {
          this.storeList = res.data.list || [];
          this.storeTotal = res.data.count || 0;
          this.refreshRegionTreeCounts();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        })
        .finally(() => {
          this.storeLoading = false;
        });
    },
    searchStore() {
      this.storeForm.page = 1;
      this.getStoreList();
    },
    resetStore() {
      this.storeForm.keywords = "";
      this.storeForm.status = "";
      this.storeForm.type = "all";
      this.storeForm.page = 1;
      this.getStoreList();
    },
    storePageChange(page) {
      this.storeForm.page = page;
      this.getStoreList();
    },
    addStore() {
      const query = { from: "region" };
      if (this.selectedRegionId) {
        query.manage_region_id = this.selectedRegionId;
      }
      this.$router.push({ path: `${this.roterPre}/store/add_store`, query });
    },
    gostore(item) {
      storeLogin(item.id)
        .then((res) => {
          const data = res.data;
          const expires = data.expires_time;
          util.cookies.setStore("token", data.token, { expires });
          util.cookies.setStore("uuid", data.user_info.id, { expires });
          util.cookies.setStore("expires_time", expires, { expires });
          util.cookies.setStore("pageTitle", item.name);
          util.makeMenu(`/${data.prefix}`, data.menus);
          const storage = window.localStorage;
          storage.setItem("menuListStore", JSON.stringify(data.menus));
          storage.setItem("uniqueAuthStore", JSON.stringify(data.unique_auth));
          storage.setItem(
            "userInfoStore",
            JSON.stringify({
              account: data.user_info.account,
              head_pic: data.user_info.avatar,
              logo: data.logo,
              logoSmall: data.logo_square,
              version: data.version,
            })
          );
          const baseURL = Setting.apiBaseURL.replace(/adminapi/, `${item.prefix}/home/`);
          window.open(baseURL);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    operation(row) {
      const a = row.is_show == 1 ? 0 : 1;
      storeSetShowApi(row.id, a)
        .then((res) => {
          this.$Message.success(res.msg);
          this.getStoreList();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    changeStoreMenu(row, name, index) {
      if (name === "edit") {
        this.$router.push({
          path: `${this.roterPre}/store/add_store/${row.id}`,
          query: { from: "region" },
        });
      } else if (name === "del") {
        this.delStore(row, index);
      }
    },
    delStore(row, index) {
      const delfromData = {
        title: "删除门店（同步删除商品）",
        num: index,
        url: `store/store/del/${row.id}`,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.getStoreList();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // ---------- 管理人员 ----------
    getManagerList() {
      this.managerLoading = true;
      const params = { ...this.regionFrom };
      if (!this.selectedRegionId) {
        delete params.manage_region_id;
      }
      getRegionList(params)
        .then((res) => {
          const list = res.data.list || [];
          this.managerList = list;
          this.managerTotal = res.data.count || 0;
          this.refreshRegionTreeCounts();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        })
        .finally(() => {
          this.managerLoading = false;
        });
    },
    searchManager() {
      this.regionFrom.page = 1;
      this.getManagerList();
    },
    resetManager() {
      this.regionFrom.is_alone = "";
      this.regionFrom.keyword = "";
      this.regionFrom.agent_admin = "";
      this.regionFrom.page = 1;
      this.getManagerList();
    },
    managerPageChange(page) {
      this.regionFrom.page = page;
      this.getManagerList();
    },
    getChilden(data) {
      if (data.length && data[0].children) {
        return this.getChilden(data[0].children);
      }
      return data[0].path;
    },
    goAgent(row) {
      getAgentLogin(row.id)
        .then(async (res) => {
          localStorage.setItem(
            `adminType_${res.data.user_info.admin_type}`,
            res.data.user_info.admin_type
          );
          const expires = res.data.expires_time;
          util.cookies.set("agent_uuid", res.data.user_info.id, { expires });
          util.cookies.set(`agent_token`, res.data.token, { expires });
          util.cookies.set("expires_time", res.data.expires_time, { expires });
          const db = await this.$store.dispatch("admin/db/database", {
            user: true,
            isAgent: true,
          });
          localStorage.setItem("agent_unique_auth", res.data.unique_auth);
          db.set("agent_user_info", res.data.user_info).write();
          util.makeMenu(Setting.routePreAgent, res.data.menus);
          const menuSider = res.data.menus;
          this.$store.commit("admin/menus/getAgentMenusNav", menuSider);
          const toPath = this.getChilden(res.data.menus);
          this.$store.commit("admin/menus/setIndexPath", toPath);
          this.$store.dispatch("admin/user/setAgent", {
            name: res.data.user_info.account,
            avatar: res.data.user_info.head_pic,
            access: res.data.unique_auth,
            logo: res.data.logo,
            logoSmall: res.data.logo_square,
            version: res.data.version,
            newOrderAudioLink: res.data.newOrderAudioLink,
          });
          this.$nextTick(() => {
            window.open(location.origin + "/agent/home/", "_blank");
          });
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    onchangeIsAlone(row) {
      putRegionSetAlone(row.id, row.is_alone)
        .then((res) => {
          this.$Message.success(res.msg);
        })
        .catch((err) => {
          row.is_alone = row.is_alone === 1 ? 0 : 1;
          this.$Message.error(err.msg);
        });
    },
    changeMenu(row, name) {
      if (name === "1") {
        this.openManageStoreModal(row);
      } else if (name === "2") {
        this.del(row, "删除该区域", name);
      }
    },
    openManageStoreModal(row) {
      if (!this.selectedRegionId) {
        this.$Message.warning("请先在左侧选择区域架构");
        return;
      }
      const agentId = typeof row === "object" ? row.id : row;
      this.currentAgentId = agentId;
      this.currentManageRegionId = this.selectedRegionId;
      this.manageStoreModal = true;
      this.manageStoreLoading = true;
      getAgentManageStores(agentId, { manage_region_id: this.selectedRegionId })
        .then((res) => {
          const data = res.data || {};
          const storeMap = {};
          (data.available_stores || []).forEach((item) => {
            storeMap[item.id] = item;
          });
          const ids = [...(data.managed_store_ids || [])];
          this.managedStoreIds = ids;
          this.managedStoreList = ids.map(
            (id) => storeMap[id] || { id, name: `门店#${id}`, phone: "-" }
          );
        })
        .catch((err) => {
          this.$Message.error(err.msg);
          this.manageStoreModal = false;
        })
        .finally(() => {
          this.manageStoreLoading = false;
        });
    },
    openStorePickerModal() {
      this.pickerSelectedMap = {};
      this.storePickerForm = {
        keywords: "",
        page: 1,
        limit: 10,
      };
      this.storePickerModal = true;
      this.loadStorePickerList();
    },
    closeStorePickerModal() {
      this.storePickerModal = false;
      this.pickerSelectedMap = {};
    },
    searchStorePicker() {
      this.storePickerForm.page = 1;
      this.loadStorePickerList();
    },
    storePickerPageChange(page) {
      this.storePickerForm.page = page;
      this.loadStorePickerList();
    },
    loadStorePickerList() {
      if (!this.currentManageRegionId) {
        this.$Message.warning("请先选择区域架构");
        return;
      }
      this.storePickerLoading = true;
      const managedSet = new Set(this.managedStoreIds || []);
      storeListApi({
        page: this.storePickerForm.page,
        limit: this.storePickerForm.limit,
        keywords: this.storePickerForm.keywords,
        manage_region_id: this.currentManageRegionId,
        type: "all",
      })
        .then((res) => {
          const list = (res.data.list || []).filter((item) => !managedSet.has(item.id));
          list.forEach((item) => {
            if (this.pickerSelectedMap[item.id]) {
              item._checked = true;
            }
          });
          this.storePickerList = list;
          this.storePickerTotal = res.data.count || 0;
        })
        .catch((err) => {
          this.$Message.error(err.msg || "加载门店列表失败");
        })
        .finally(() => {
          this.storePickerLoading = false;
        });
    },
    onStorePickerSelectionChange(selection) {
      const pageIds = (this.storePickerList || []).map((item) => item.id);
      const selectedIds = new Set((selection || []).map((item) => item.id));
      pageIds.forEach((id) => {
        if (!selectedIds.has(id)) {
          this.$delete(this.pickerSelectedMap, id);
        }
      });
      (selection || []).forEach((row) => {
        this.$set(this.pickerSelectedMap, row.id, {
          id: row.id,
          name: row.name,
          phone: row.phone || "-",
        });
      });
    },
    confirmStorePicker() {
      const selected = Object.values(this.pickerSelectedMap || {});
      if (!selected.length) {
        this.$Message.warning("请至少选择一个门店");
        return;
      }
      const existIds = new Set(this.managedStoreIds || []);
      selected.forEach((store) => {
        if (!existIds.has(store.id)) {
          this.managedStoreList.push(store);
          this.managedStoreIds.push(store.id);
          existIds.add(store.id);
        }
      });
      this.closeStorePickerModal();
    },
    removeManagedStore(storeId) {
      this.managedStoreIds = this.managedStoreIds.filter((id) => id !== storeId);
      this.managedStoreList = this.managedStoreList.filter((item) => item.id !== storeId);
    },
    saveManagedStores() {
      this.manageStoreSaving = true;
      saveAgentManageStores(this.currentAgentId, {
        store_ids: this.managedStoreIds,
        manage_region_id: this.currentManageRegionId || this.selectedRegionId,
      })
        .then((res) => {
          this.$Message.success(res.msg);
          this.manageStoreModal = false;
          this.getManagerList();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        })
        .finally(() => {
          this.manageStoreSaving = false;
        });
    },
    openEditRegionModal(node) {
      this.editRegionId = node.id;
      getRegionManageInfo(node.id)
        .then((res) => {
          const data = res.data || {};
          this.regionForm = {
            pid: data.pid || 0,
            name: data.name || "",
          };
          this.loadParentRegionOptions();
          this.addRegionModal = true;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    confirmDeleteRegion(node) {
      this.$Modal.confirm({
        title: "删除区域",
        content: `确定删除区域「${node.name}」吗？请先删除下级区域及关联数据。`,
        onOk: () => {
          return deleteRegionManage(node.id)
            .then((res) => {
              this.$Message.success(res.msg);
              if (this.selectedRegionId === node.id) {
                this.selectedRegionId = 0;
                this.selectedRegionName = "";
              }
              this.loadRegionTree();
              this.applyRegionFilter();
            })
            .catch((err) => {
              this.$Message.error(err.msg);
            });
        },
      });
    },
    closeRegionModal() {
      this.addRegionModal = false;
      this.editRegionId = 0;
      this.regionForm = { pid: 0, name: "" };
    },
    addRegion() {
      const query = { tab: "manager" };
      if (this.selectedRegionId) {
        query.manage_region_id = this.selectedRegionId;
      }
      this.$router.push({ path: `${this.roterPre}/store/region/create`, query });
    },
    openAddRegionModal() {
      this.editRegionId = 0;
      this.regionForm = {
        pid: this.selectedRegionId || 0,
        name: "",
      };
      this.loadParentRegionOptions();
      this.addRegionModal = true;
    },
    loadParentRegionOptions() {
      getRegionManageAll()
        .then((res) => {
          let options = this.buildFlatRegionOptions(res.data || []);
          if (this.editRegionId) {
            options = options.filter((item) => item.id !== this.editRegionId);
          }
          this.parentRegionOptions = options;
        })
        .catch(() => {});
    },
    buildFlatRegionOptions(list, pid = 0, prefix = "") {
      let result = [];
      (list || [])
        .filter((item) => item.pid === pid)
        .forEach((item) => {
          result.push({ id: item.id, label: prefix + item.name });
          result = result.concat(this.buildFlatRegionOptions(list, item.id, `${prefix}　`));
        });
      return result;
    },
    confirmAddRegion() {
      if (!this.regionForm.name.trim()) {
        this.$Message.error("请输入区域名称");
        return;
      }
      postRegionManage(this.regionForm, this.editRegionId || 0)
        .then((res) => {
          this.$Message.success(res.msg);
          this.closeRegionModal();
          this.loadRegionTree();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    edit(id) {
      this.$router.push({
        path: `${this.roterPre}/store/region/create/${id}`,
        query: { tab: "manager" },
      });
    },
    del(row, tit) {
      const delfromData = {
        title: tit,
        url: `region/agent/${row.id}`,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.loadRegionTree();
          this.getManagerList();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
  },
};
</script>

<style scoped lang="stylus">
$theme-color = #2d8cf0
$theme-light = #ecf5ff

.region-mgmt
  display flex
  gap 16px
  min-height 520px
  width 100%
  align-items stretch

.region-tree-panel
  width 280px
  flex-shrink 0
  background #fff
  border-radius 8px
  box-shadow 0 2px 8px rgba(0, 0, 0, 0.06)
  padding 20px
  display flex
  flex-direction column

.tree-wrap
  flex 1
  min-height 280px
  position relative
  overflow auto

.tree-spin
  display block
  padding 40px 0
  text-align center

/deep/ .ivu-tree-title-selected,
/deep/ .ivu-tree-title-selected:hover
  background-color $theme-light !important
  color $theme-color !important

.filter-select-wide
  width 200px

.store-list-panel
  flex 1
  min-width 0
  background #fff
  border-radius 8px
  box-shadow 0 2px 8px rgba(0, 0, 0, 0.06)
  padding 20px
  display flex
  flex-direction column

.panel-header
  display flex
  justify-content space-between
  align-items center
  margin-bottom 16px

.panel-header-right
  flex-wrap wrap
  gap 12px

.panel-header-left
  display flex
  align-items center
  gap 24px

.panel-title
  font-size 16px
  font-weight 600
  color #333
  margin 0

.panel-actions
  display flex
  gap 8px

.btn-icon
  width 28px
  height 28px
  border-radius 4px
  display inline-flex
  align-items center
  justify-content center
  cursor pointer
  color #666
  transition all 0.3s
  &:hover
    background #f5f5f5
    color $theme-color

/* 树状图样式 */
.tree-container
  font-size 14px
  margin-top 8px

.tree-node
  position relative

.tree-node-content
  display flex
  align-items center
  gap 8px
  padding 10px 12px
  cursor pointer
  border-radius 6px
  transition all 0.3s
  position relative
  &:hover
    background $theme-light
  &.active
    background $theme-light
    color $theme-color

.tree-toggle
  width 16px
  height 16px
  display flex
  align-items center
  justify-content center
  cursor pointer
  color #999
  font-size 10px
  transition transform 0.3s
  &.expanded
    transform rotate(90deg)
  &.leaf
    visibility hidden

.tree-icon
  width 20px
  height 20px
  display flex
  align-items center
  justify-content center
  color $theme-color

.tree-label
  flex 1
  overflow hidden
  text-overflow ellipsis
  white-space nowrap

.tree-count
  font-size 12px
  color #999
  background #f5f5f5
  padding 2px 8px
  border-radius 10px

.tree-children
  margin-left 24px
  position relative
  &.collapsed
    display none
  &::before
    content ''
    position absolute
    left -16px
    top 0
    bottom 16px
    width 1px
    background #e8e8e8

.tree-children .tree-node-content::before
  content ''
  position absolute
  left -16px
  top 50%
  width 12px
  height 1px
  background #e8e8e8

.tree-children.level-2
  margin-left 48px

.tree-empty
  text-align center
  color #999
  padding 40px 0
  font-size 14px

/* Tab切换 */
.tab-bar
  display flex
  gap 8px
  margin-bottom 20px
  border-bottom 1px solid #e8e8e8

.tab-item
  padding 12px 24px
  font-size 14px
  color #666
  cursor pointer
  position relative
  transition all 0.3s
  &:hover
    color $theme-color
  &.active
    color $theme-color
    font-weight 500
    &::after
      content ''
      position absolute
      bottom -1px
      left 0
      right 0
      height 2px
      background $theme-color

/* 搜索筛选栏 */
.filter-bar
  display flex
  gap 12px
  margin-bottom 20px
  flex-wrap wrap
  align-items center

.search-input
  flex 1
  min-width 200px
  max-width 320px
  position relative
  display flex
  align-items center
  i
    position absolute
    left 14px
    top 50%
    transform translateY(-50%)
    color #999
  /deep/ .ivu-input
    padding-left 40px

.filter-select
  width 140px

.btn-secondary
  padding 10px 20px
  background #fff
  color #666
  border 1px solid #e8e8e8
  border-radius 6px
  font-size 14px
  cursor pointer
  transition all 0.3s
  &:hover
    border-color $theme-color
    color $theme-color

/* 数据表格 */
.table-wrap
  flex 1
  min-height 0

/deep/ .ivu-table
  border-collapse collapse

/deep/ .ivu-table th
  background #fafafa
  padding 14px 16px
  text-align left
  font-size 13px
  font-weight 500
  color #666
  border-bottom 1px solid #e8e8e8

/deep/ .ivu-table td
  padding 16px
  font-size 14px
  color #333
  border-bottom 1px solid #f0f0f0

/deep/ .ivu-table tr:hover
  background #fafafa

.store-info
  display flex
  align-items center
  gap 12px

.store-avatar
  width 40px
  height 40px
  border-radius 8px
  background $theme-color
  display flex
  align-items center
  justify-content center
  color #fff
  font-size 16px
  overflow hidden
  flex-shrink 0
  img
    width 100%
    height 100%
    object-fit cover

.store-details
  flex 1
  min-width 0

.store-name
  font-weight 500
  color #333
  margin-bottom 4px

.store-address
  font-size 12px
  color #999

.status-badge
  display inline-flex
  align-items center
  gap 4px
  padding 4px 12px
  border-radius 12px
  font-size 12px
  &.active
    background $theme-light
    color $theme-color
  &.inactive
    background #f5f5f5
    color #999

.action-btns
  display flex
  gap 8px

.action-btn
  padding 6px 14px
  border-radius 4px
  font-size 13px
  cursor pointer
  transition all 0.3s
  border none
  display flex
  align-items center
  gap 4px
  &.edit
    background $theme-light
    color $theme-color
    &:hover
      background $theme-color
      color #fff
  &.more
    background #f5f5f5
    color #666
    &:hover
      background #e8e8e8

.action-links
  display flex
  align-items center
  gap 8px

.action-link
  color $theme-color
  cursor pointer
  font-size 13px
  margin-right 0
  &:hover
    text-decoration underline

.manager-info
  display flex
  align-items center
  gap 8px

.manager-avatar
  width 32px
  height 32px
  border-radius 50%
  background $theme-color
  color #fff
  font-size 12px
  display flex
  align-items center
  justify-content center
  flex-shrink 0

/* 开关样式 */
.switch
  position relative
  display inline-block
  width 44px
  height 22px
  input
    opacity 0
    width 0
    height 0

.slider
  position absolute
  cursor pointer
  top 0
  left 0
  right 0
  bottom 0
  background-color #ccc
  transition .4s
  border-radius 22px
  &::before
    position absolute
    content ""
    height 18px
    width 18px
    left 2px
    bottom 2px
    background-color white
    transition .4s
    border-radius 50%

input:checked + .slider
  background-color $theme-color

input:checked + .slider::before
  transform translateX(22px)

/* 分页：无填充背景，仅边框与文字为主题蓝 */
.pagination
  display flex
  justify-content flex-end
  align-items center
  gap 8px
  margin-top 20px
  padding-top 20px
  border-top 1px solid #f0f0f0

  /deep/ .ivu-page-item
    background #fff !important
    border 1px solid #dcdee2
    a
      color #515a6e
    &:hover
      border-color $theme-color
      a
        color $theme-color

  /deep/ .ivu-page-item-active
    background #fff !important
    border-color $theme-color !important
    a
      color $theme-color !important

  /deep/ .ivu-page-item-active:hover
    background #fff !important
    border-color $theme-color !important
    a
      color $theme-color !important

  /deep/ .ivu-page-prev,
  /deep/ .ivu-page-next
    background #fff !important
    border 1px solid #dcdee2
    a
      color #515a6e
    &:hover
      border-color $theme-color
      a
        color $theme-color

  /deep/ .ivu-page-disabled
    a
      color #c5c8ce !important

.page-info
  font-size 13px
  color #999
  margin 0 12px

/* 弹窗样式 */
.modal-body
  padding 24px

.form-item
  margin-bottom 20px
  &:last-child
    margin-bottom 0

.form-label
  display flex
  align-items center
  gap 4px
  font-size 14px
  color #333
  margin-bottom 8px

.required
  color #ff4d4f

.form-select
  width 100%
  padding 10px 14px
  border 1px solid #d9d9d9
  border-radius 4px
  font-size 14px
  color #333
  background #fff
  cursor pointer
  transition all 0.3s
  appearance none
  background-image url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23999' d='M6 8L1 3h10z'/%3E%3C/svg%3E")
  background-repeat no-repeat
  background-position right 14px center
  &:focus
    outline none
    border-color $theme-color

.form-input-wrapper
  position relative

.form-input
  width 100%
  padding 10px 14px
  border 1px solid #d9d9d9
  border-radius 4px
  font-size 14px
  color #333
  transition all 0.3s
  &:focus
    outline none
    border-color $theme-color

.char-count
  position absolute
  right 14px
  top 50%
  transform translateY(-50%)
  font-size 13px
  color #999

.modal-footer
  padding 16px 24px
  border-top 1px solid #e8e8e8
  display flex
  justify-content flex-end
  gap 12px

.btn-default
  padding 8px 20px
  background #fff
  color #666
  border 1px solid #d9d9d9
  border-radius 4px
  font-size 14px
  cursor pointer
  transition all 0.3s
  &:hover
    border-color $theme-color
    color $theme-color

.manage-store-modal
  .manage-store-section
    margin-bottom 20px
  .section-title
    font-weight 500
    margin-bottom 10px
    color #333
  .empty-tip
    color #999
    font-size 13px
    padding 12px 0
  .managed-store-chips
    display flex
    flex-wrap wrap
    gap 10px
    min-height 40px
  .store-chip
    position relative
    display inline-flex
    align-items center
    max-width 200px
    padding 8px 32px 8px 14px
    background #f5f7fa
    border 1px solid #e8eaec
    border-radius 6px
    font-size 13px
    color #333
    line-height 1.4
  .chip-name
    overflow hidden
    text-overflow ellipsis
    white-space nowrap
  .chip-close
    position absolute
    top 4px
    right 4px
    width 20px
    height 20px
    display flex
    align-items center
    justify-content center
    border-radius 50%
    color #999
    cursor pointer
    transition all 0.2s
    &:hover
      background rgba(0, 0, 0, 0.06)
      color #ed4014
  .action-link.danger
    color #ed4014
    cursor pointer

.store-picker-toolbar
  display flex
  gap 12px
  margin-bottom 16px
  align-items center
  .store-picker-search
    flex 1
    max-width 360px

.store-picker-pagination
  display flex
  justify-content flex-end
  align-items center
  gap 12px
  margin-top 16px
  padding-top 12px
  border-top 1px solid #f0f0f0

/deep/ .store-picker-modal .ivu-modal-footer
  display flex
  align-items center
  justify-content flex-end
  gap 12px

.picker-selected-tip
  margin-right auto
  font-size 13px
  color #666

</style>
