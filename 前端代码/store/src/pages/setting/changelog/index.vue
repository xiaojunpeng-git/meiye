<template>
  <div class="changelog-page">
    <Alert show-icon class="read-only-tip">系统更新由平台统一发布</Alert>
    <Card :bordered="false" dis-hover :padding="16" class="changelog-card">
      <div class="filter-bar">
        <Input
          v-model="formData.keyword"
          placeholder="标题/内容关键词"
          clearable
          class="filter-input"
          @on-enter="handleSearch"
        />
        <Button type="primary" @click="handleSearch">查询 <span class="enter-key">↵</span></Button>
      </div>

      <div class="table-wrap">
        <div class="table-body" ref="tableBody">
          <Table
            :columns="columns"
            :data="tableList"
            :loading="loading"
            highlight-row
            :max-height="tableBodyHeight"
            :scroll="{ x: 1100 }"
            no-data-text="暂无数据"
            no-filtered-data-text="暂无筛选结果"
          >
            <template slot-scope="{ row }" slot="type_summary">
              {{ row.type_summary || '-' }}
            </template>
            <template slot-scope="{ row }" slot="action">
              <a @click="openDetail(row)">查看详情</a>
            </template>
          </Table>
        </div>
        <div class="acea-row row-right page">
          <Page
            :total="total"
            :current="formData.page"
            :page-size="formData.limit"
            show-elevator
            show-total
            @on-change="pageChange"
          />
        </div>
      </div>
    </Card>

    <Modal v-model="showDetail" title="更新日志详情" width="720" footer-hide>
      <div v-if="detailInfo.id" class="detail-wrap">
        <p class="detail-title">{{ detailInfo.title }}</p>
        <p class="detail-meta">
          <span>{{ detailInfo.publish_date }}</span>
          <span v-if="detailInfo.version">V{{ detailInfo.version }}</span>
        </p>
        <p v-if="detailInfo.summary" class="detail-summary">{{ detailInfo.summary }}</p>
        <div class="detail-items">
          <div v-for="(group, gIdx) in groupedItems" :key="gIdx" class="detail-group">
            <div class="group-title">{{ group.label }}</div>
            <div v-for="(item, idx) in group.items" :key="idx" class="detail-item">
              <span v-if="item.module_name" class="module-name">{{ item.module_name }}：</span>
              <span>{{ item.content }}</span>
            </div>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>

<script>
import { changelogInfoApi, changelogListApi } from '@/api/changelog';

const CHANGE_TYPE_ORDER = ['add', 'optimize', 'adjust', 'fix', 'offline'];
const CHANGE_TYPE_MAP = {
  add: '新增',
  optimize: '优化',
  adjust: '调整',
  fix: '修复',
  offline: '下线',
};

export default {
  name: 'storeChangelog',
  data() {
    return {
      loading: false,
      tableBodyHeight: 400,
      showDetail: false,
      detailInfo: {},
      total: 0,
      tableList: [],
      formData: {
        keyword: '',
        page: 1,
        limit: 20,
      },
      columns: [
        { title: '发布日期', key: 'publish_date', width: 120 },
        { title: '标题', key: 'title', minWidth: 220 },
        { title: '版本号', key: 'version', width: 100 },
        { title: '变更摘要', slot: 'type_summary', minWidth: 180 },
        { title: '操作', slot: 'action', fixed: 'right', width: 100 },
      ],
    };
  },
  computed: {
    groupedItems() {
      const items = this.detailInfo.items || [];
      const groups = {};
      items.forEach((item) => {
        const type = item.change_type || 'add';
        if (!groups[type]) groups[type] = [];
        groups[type].push(item);
      });
      return CHANGE_TYPE_ORDER
        .filter((type) => groups[type] && groups[type].length)
        .map((type) => ({
          label: CHANGE_TYPE_MAP[type],
          items: groups[type],
        }));
    },
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
    handleSearch() {
      this.formData.page = 1;
      this.getList();
    },
    pageChange(page) {
      this.formData.page = page;
      this.getList();
    },
    getList() {
      this.loading = true;
      changelogListApi(this.formData)
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
    openDetail(row) {
      changelogInfoApi(row.id)
        .then((res) => {
          this.detailInfo = res.data || {};
          this.showDetail = true;
        })
        .catch((err) => {
          this.$Message.error(err.msg || '加载失败');
        });
    },
  },
};
</script>

<style scoped lang="stylus">
.changelog-page
  height calc(100vh - 140px)
  overflow hidden
  display flex
  flex-direction column

.read-only-tip
  flex-shrink 0
  margin-bottom 12px

.changelog-card
  flex 1
  min-height 0

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

.filter-input
  width 260px

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

.detail-title
  font-size 16px
  font-weight 600
  margin-bottom 8px

.detail-meta
  color #999
  margin-bottom 12px

  span + span
    margin-left 12px

.detail-summary
  margin-bottom 12px
  color #666

.detail-group
  margin-bottom 12px

.group-title
  font-weight 600
  margin-bottom 6px

.detail-item
  padding-left 12px
  margin-bottom 4px
  line-height 1.6

.module-name
  color #666
</style>
