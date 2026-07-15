<template>
  <div class="changelog-page">
    <Card :bordered="false" dis-hover :padding="16" class="changelog-card">
      <div class="filter-bar">
        <Select v-model="formData.status" clearable placeholder="状态" class="filter-select" @on-change="handleSearch">
          <Option :value="0">草稿</Option>
          <Option :value="1">待发布</Option>
          <Option :value="2">已发布</Option>
          <Option :value="3">已下架</Option>
        </Select>
        <Select v-model="formData.platforms" clearable placeholder="展示端" class="filter-select" @on-change="handleSearch">
          <Option v-for="item in platformOptions" :key="item.value" :value="item.value">{{ item.label }}</Option>
        </Select>
        <DatePicker
          transfer
          :editable="false"
          clearable
          type="daterange"
          format="yyyy-MM-dd"
          placeholder="发布日期范围"
          class="filter-date"
          :value="dateRange"
          @on-change="onDateRangeChange"
        />
        <Input
          v-model="formData.version"
          placeholder="版本号"
          clearable
          class="filter-input-sm"
          @on-enter="handleSearch"
        />
        <Input
          v-model="formData.keyword"
          placeholder="标题/内容关键词"
          clearable
          class="filter-input"
          @on-enter="handleSearch"
        />
        <Button type="primary" @click="handleSearch">查询 <span class="enter-key">↵</span></Button>
        <Button
          v-auth="['setting-system-changelog-add']"
          type="primary"
          class="ml14"
          @click="openEdit(0)"
        >新增日志</Button>
      </div>

      <div class="table-wrap">
        <div class="table-body" ref="tableBody">
          <Table
            :columns="columns"
            :data="tableList"
            :loading="loading"
            highlight-row
            :max-height="tableBodyHeight"
            :scroll="{ x: 1400 }"
            no-data-text="暂无数据"
            no-filtered-data-text="暂无筛选结果"
          >
            <template slot-scope="{ row }" slot="publish_date">
              {{ row.publish_date || '-' }}
            </template>
            <template slot-scope="{ row }" slot="type_summary">
              {{ row.type_summary || '-' }}
            </template>
            <template slot-scope="{ row }" slot="platforms">
              {{ formatPlatforms(row.platforms_arr) }}
            </template>
            <template slot-scope="{ row }" slot="status">
              <Tag :color="statusColor(row.status)">{{ row.status_text || statusText(row.status) }}</Tag>
            </template>
            <template slot-scope="{ row }" slot="flags">
              <Tag v-if="row.is_important == 1" color="warning">重要</Tag>
              <Tag v-if="row.is_popup == 1" color="error">弹窗</Tag>
            </template>
            <template slot-scope="{ row }" slot="action">
              <a @click="openDetail(row)">查看</a>
              <template v-if="canEdit(row)">
                <Divider type="vertical" />
                <a v-auth="['setting-system-changelog-edit']" @click="openEdit(row.id)">编辑</a>
              </template>
              <template v-if="canPublish(row)">
                <Divider type="vertical" />
                <a v-auth="['setting-system-changelog-publish']" @click="handlePublish(row)">发布</a>
              </template>
              <template v-if="canOffline(row)">
                <Divider type="vertical" />
                <a v-auth="['setting-system-changelog-offline']" @click="openOffline(row)">下架</a>
              </template>
              <template v-if="canDelete(row)">
                <Divider type="vertical" />
                <a v-auth="['setting-system-changelog-delete']" @click="handleDelete(row)">删除</a>
              </template>
              <Divider type="vertical" />
              <a v-auth="['setting-system-changelog-copy']" @click="handleCopy(row)">复制</a>
            </template>
          </Table>
        </div>
        <div class="acea-row row-right page">
          <Page
            :total="total"
            :current="formData.page"
            :page-size="formData.limit"
            :page-size-opts="[10, 20, 50]"
            show-elevator
            show-total
            show-sizer
            @on-change="pageChange"
            @on-page-size-change="limitChange"
          />
        </div>
      </div>
    </Card>

    <edit-modal v-model="showEdit" :edit-id="editId" @success="getList" />

    <Modal v-model="showDetail" title="更新日志详情" width="800" footer-hide>
      <div v-if="detailInfo.id" class="detail-wrap">
        <p><b>标题：</b>{{ detailInfo.title }}</p>
        <p><b>发布日期：</b>{{ detailInfo.publish_date }}</p>
        <p><b>版本号：</b>{{ detailInfo.version || '-' }}</p>
        <p><b>展示端：</b>{{ formatPlatforms(detailInfo.platforms_arr) }}</p>
        <p><b>状态：</b>{{ detailInfo.status_text }}</p>
        <p v-if="detailInfo.summary"><b>摘要：</b>{{ detailInfo.summary }}</p>
        <div class="detail-items">
          <div v-for="(item, idx) in detailInfo.items || []" :key="idx" class="detail-item">
            <Tag>{{ changeTypeText(item.change_type) }}</Tag>
            <span v-if="item.module_name" class="module-name">{{ item.module_name }}</span>
            <span>{{ item.content }}</span>
          </div>
        </div>
      </div>
    </Modal>

    <Modal
      v-model="showOffline"
      title="下架更新日志"
      :mask-closable="false"
      @on-cancel="resetOffline"
    >
      <p class="offline-tip">下架后用户将无法查看，但历史记录仍保留。</p>
      <Input v-model="offlineReason" type="textarea" :rows="3" placeholder="请填写下架原因" />
      <div slot="footer">
        <Button @click="showOffline = false">取消</Button>
        <Button type="primary" :loading="offlineSubmitting" @click="confirmOffline">确认下架</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import {
  changelogCopyApi,
  changelogDeleteApi,
  changelogInfoApi,
  changelogListApi,
  changelogOfflineApi,
  changelogPublishApi,
} from '@/api/changelog';
import EditModal from './components/EditModal.vue';

export default {
  name: 'settingChangelog',
  components: { EditModal },
  data() {
    return {
      loading: false,
      tableBodyHeight: 400,
      showEdit: false,
      editId: 0,
      showDetail: false,
      detailInfo: {},
      showOffline: false,
      offlineReason: '',
      offlineRow: null,
      offlineSubmitting: false,
      dateRange: [],
      total: 0,
      tableList: [],
      formData: {
        status: '',
        platforms: '',
        publish_date_start: '',
        publish_date_end: '',
        version: '',
        keyword: '',
        page: 1,
        limit: 20,
      },
      platformOptions: [
        { value: 'mini', label: '小程序' },
        { value: 'admin', label: '平台后台' },
        { value: 'store', label: '门店后台' },
        { value: 'cashier', label: '收银台' },
      ],
      changeTypeMap: {
        add: '新增',
        optimize: '优化',
        adjust: '调整',
        fix: '修复',
        offline: '下线',
      },
      columns: [
        { title: 'ID', key: 'id', width: 70 },
        { title: '发布日期', slot: 'publish_date', width: 120 },
        { title: '标题', key: 'title', minWidth: 200 },
        { title: '版本号', key: 'version', width: 100 },
        { title: '变更摘要', slot: 'type_summary', minWidth: 160 },
        { title: '展示端', slot: 'platforms', minWidth: 150 },
        { title: '状态', slot: 'status', width: 90 },
        { title: '标记', slot: 'flags', width: 110 },
        { title: '排序', key: 'sort', width: 70 },
        { title: '更新时间', key: 'update_time', width: 160 },
        { title: '操作', slot: 'action', fixed: 'right', width: 260 },
      ],
    };
  },
  mounted() {
    this.getList();
    this.calcTableHeight();
    window.addEventListener('resize', this.calcTableHeight);
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.calcTableHeight);
  },
  methods: {
    calcTableHeight() {
      this.$nextTick(() => {
        const el = this.$refs.tableBody;
        if (el) {
          this.tableBodyHeight = Math.max(el.clientHeight - 8, 200);
        }
      });
    },
    statusText(status) {
      const map = { 0: '草稿', 1: '待发布', 2: '已发布', 3: '已下架' };
      return map[status] || '-';
    },
    statusColor(status) {
      const map = { 0: 'default', 1: 'blue', 2: 'success', 3: 'warning' };
      return map[status] || 'default';
    },
    changeTypeText(type) {
      return this.changeTypeMap[type] || type;
    },
    formatPlatforms(arr) {
      if (!Array.isArray(arr) || !arr.length) return '-';
      const labelMap = {};
      this.platformOptions.forEach((item) => {
        labelMap[item.value] = item.label;
      });
      return arr.map((p) => labelMap[p] || p).join('、');
    },
    canEdit(row) {
      return [0, 1, 2].includes(Number(row.status));
    },
    canPublish(row) {
      return [0, 1].includes(Number(row.status));
    },
    canOffline(row) {
      return Number(row.status) === 2;
    },
    canDelete(row) {
      return Number(row.status) === 0;
    },
    onDateRangeChange(val) {
      this.dateRange = val || [];
      this.formData.publish_date_start = val && val[0] ? val[0] : '';
      this.formData.publish_date_end = val && val[1] ? val[1] : '';
      this.handleSearch();
    },
    handleSearch() {
      this.formData.page = 1;
      this.getList();
    },
    pageChange(page) {
      this.formData.page = page;
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
      if (!params.platforms) delete params.platforms;
      changelogListApi(params)
        .then((res) => {
          this.tableList = res.data.list || [];
          this.total = res.data.count || 0;
        })
        .catch((err) => {
          this.$Message.error(err.msg || '加载失败');
        })
        .finally(() => {
          this.loading = false;
          this.calcTableHeight();
        });
    },
    openEdit(id) {
      this.editId = Number(id) || 0;
      this.showEdit = true;
    },
    openDetail(row) {
      changelogInfoApi(row.id, { with_audit: 0 })
        .then((res) => {
          this.detailInfo = res.data || {};
          this.showDetail = true;
        })
        .catch((err) => {
          this.$Message.error(err.msg || '加载失败');
        });
    },
    handlePublish(row) {
      const platforms = this.formatPlatforms(row.platforms_arr);
      this.$Modal.confirm({
        title: '确认发布',
        content: `发布后将按所选展示端（${platforms}）对用户可见，请确认日期和内容与已上线功能一致。`,
        onOk: () => changelogPublishApi(row.id)
          .then((res) => {
            this.$Message.success(res.msg || '发布成功');
            this.getList();
          })
          .catch((err) => {
            this.$Message.error(err.msg || '发布失败');
          }),
      });
    },
    openOffline(row) {
      this.offlineRow = row;
      this.offlineReason = '';
      this.showOffline = true;
    },
    resetOffline() {
      this.offlineRow = null;
      this.offlineReason = '';
    },
    confirmOffline() {
      if (!String(this.offlineReason || '').trim()) {
        this.$Message.required('下架原因未填写');
        return;
      }
      this.offlineSubmitting = true;
      changelogOfflineApi(this.offlineRow.id, { reason: this.offlineReason })
        .then((res) => {
          this.$Message.success(res.msg || '下架成功');
          this.showOffline = false;
          this.resetOffline();
          this.getList();
        })
        .catch((err) => {
          this.$Message.error(err.msg || '下架失败');
        })
        .finally(() => {
          this.offlineSubmitting = false;
        });
    },
    handleDelete(row) {
      this.$Modal.confirm({
        title: '确认删除',
        content: '仅草稿可删除，确认删除该日志？',
        onOk: () => changelogDeleteApi(row.id)
          .then((res) => {
            this.$Message.success(res.msg || '删除成功');
            this.getList();
          })
          .catch((err) => {
            this.$Message.error(err.msg || '删除失败');
          }),
      });
    },
    handleCopy(row) {
      changelogCopyApi(row.id)
        .then((res) => {
          this.$Message.success(res.msg || '复制成功');
          this.getList();
        })
        .catch((err) => {
          this.$Message.error(err.msg || '复制失败');
        });
    },
  },
};
</script>

<style scoped lang="stylus">
.changelog-page
  height calc(100vh - 140px)
  overflow hidden

.changelog-card
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

.filter-select
  width 140px

.filter-date
  width 240px

.filter-input
  width 220px

.filter-input-sm
  width 120px

.ml14
  margin-left 14px

.enter-key
  margin-left 2px
  font-weight 600

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

.page
  flex-shrink 0
  padding-top 12px

.offline-tip
  margin-bottom 12px
  color #666

.detail-wrap p
  margin-bottom 8px
  line-height 1.6

.detail-items
  margin-top 12px

.detail-item
  margin-bottom 8px
  line-height 1.6

.module-name
  margin 0 8px
  color #666
</style>
