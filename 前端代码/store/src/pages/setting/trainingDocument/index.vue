<template>
  <div class="training-page">
    <Alert show-icon class="read-only-tip">培训资料由平台统一发布，门店仅可查看与下载已授权内容</Alert>
    <Card :bordered="false" dis-hover :padding="16" class="training-card">
      <div class="filter-bar">
        <Input
          v-model="formData.keyword"
          placeholder="资料名称/摘要"
          clearable
          class="filter-input"
          @on-enter="handleSearch"
        />
        <Input
          v-model="formData.category"
          placeholder="分类"
          clearable
          class="filter-input filter-input--sm"
          @on-enter="handleSearch"
        />
        <Button type="primary" class="ml14" @click="handleSearch">查询 <span class="enter-key">↵</span></Button>
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
            no-data-text="暂无可查看的培训资料"
            no-filtered-data-text="暂无筛选结果"
          >
            <template slot-scope="{ row }" slot="required">
              <Tag v-if="Number(row.is_required) === 1" color="red">必读</Tag>
              <span v-else>-</span>
            </template>
            <template slot-scope="{ row }" slot="action">
              <a
                v-if="Number(row.allow_download) === 1"
                :class="{ disabled: downloadingId === row.id }"
                @click="onDownload(row)"
              >{{ downloadingId === row.id ? '下载中…' : '下载' }}</a>
              <span v-else class="muted">仅在线查看</span>
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
  </div>
</template>

<script>
import { trainingDocumentListApi, trainingDocumentDownloadApi } from '@/api/trainingDocument';

export default {
  name: 'storeTrainingDocument',
  data() {
    return {
      loading: false,
      downloadingId: 0,
      tableBodyHeight: 400,
      total: 0,
      tableList: [],
      formData: {
        keyword: '',
        category: '',
        page: 1,
        limit: 20,
      },
      columns: [
        { title: '资料名称', key: 'title', minWidth: 200 },
        { title: '分类', key: 'category', width: 120 },
        { title: '版本', key: 'version', width: 90 },
        { title: '文件', key: 'file_name', minWidth: 180 },
        { title: '必读', slot: 'required', width: 90 },
        { title: '操作', slot: 'action', fixed: 'right', width: 120 },
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
      trainingDocumentListApi(this.formData)
        .then((res) => {
          this.tableList = (res.data && res.data.list) || [];
          this.total = (res.data && res.data.count) || 0;
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '加载失败');
        })
        .finally(() => {
          this.loading = false;
          this.calcTableHeight();
        });
    },
    onDownload(row) {
      if (!row || !row.id || this.downloadingId) return;
      if (Number(row.allow_download) !== 1) {
        this.$Message.warning('该资料仅允许在线查看');
        return;
      }
      this.downloadingId = row.id;
      trainingDocumentDownloadApi(row.id, row.file_name)
        .then(({ blob, fileName }) => {
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = fileName || row.file_name || `training-${row.id}`;
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          window.URL.revokeObjectURL(url);
          this.$Message.success('已开始下载');
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '下载失败');
        })
        .finally(() => {
          this.downloadingId = 0;
        });
    },
  },
};
</script>

<style scoped lang="stylus">
.training-page
  height calc(100vh - 140px)
  overflow hidden
  display flex
  flex-direction column

.read-only-tip
  flex-shrink 0
  margin-bottom 12px

.training-card
  flex 1
  min-height 0

  >>> .ivu-card-body
    height 100%
    display flex
    flex-direction column
    overflow hidden
    box-sizing border-box

.filter-bar
  flex-shrink 0
  display flex
  align-items center
  flex-wrap wrap
  margin-bottom 12px

.filter-input
  width 220px
  margin-right 12px

.filter-input--sm
  width 140px

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

.table-body
  flex 1
  min-height 0
  overflow hidden

.page
  flex-shrink 0
  margin-top 12px

.muted
  color #999

a.disabled
  pointer-events none
  color #bbb
</style>
