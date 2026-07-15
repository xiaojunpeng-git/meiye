<template>
  <div class="changelog-page">
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Alert show-icon>系统更新由平台统一发布，此处仅供查看。</Alert>
      <Table
        :columns="columns"
        :data="tableList"
        :loading="loading"
        highlight-row
        no-data-text="暂无更新记录"
        class="mt16"
      >
        <template slot-scope="{ row }" slot="type_summary">
          {{ row.type_summary || '-' }}
        </template>
        <template slot-scope="{ row }" slot="action">
          <a @click="openDetail(row)">查看</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="formData.page"
          :page-size="formData.limit"
          show-total
          @on-change="pageChange"
        />
      </div>
    </Card>

    <Modal v-model="showDetail" title="更新日志" width="640" footer-hide>
      <div v-if="detailInfo.id">
        <p class="detail-title">{{ detailInfo.title }}</p>
        <p class="detail-meta">{{ detailInfo.publish_date }} <span v-if="detailInfo.version">V{{ detailInfo.version }}</span></p>
        <div v-for="(group, gIdx) in groupedItems" :key="gIdx" class="detail-group">
          <div class="group-title">{{ group.label }}</div>
          <div v-for="(item, idx) in group.items" :key="idx" class="detail-item">· {{ item.content }}</div>
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
  name: 'cashierChangelog',
  data() {
    return {
      loading: false,
      showDetail: false,
      detailInfo: {},
      total: 0,
      tableList: [],
      formData: { page: 1, limit: 20 },
      columns: [
        { title: '发布日期', key: 'publish_date', width: 120 },
        { title: '标题', key: 'title', minWidth: 200 },
        { title: '变更摘要', slot: 'type_summary', minWidth: 160 },
        { title: '操作', slot: 'action', width: 80 },
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
        .map((type) => ({ label: CHANGE_TYPE_MAP[type], items: groups[type] }));
    },
  },
  created() {
    this.getList();
  },
  methods: {
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
.mt16
  margin-top 16px

.page
  margin-top 16px

.detail-title
  font-size 16px
  font-weight 600
  margin-bottom 8px

.detail-meta
  color #999
  margin-bottom 12px

.detail-group
  margin-bottom 10px

.group-title
  font-weight 600
  margin-bottom 4px

.detail-item
  padding-left 8px
  line-height 1.6
  color #666
</style>
