<template>
  <div class="training-page">
    <Card :bordered="false" dis-hover :padding="16" class="training-card">
      <div class="filter-bar">
        <Input
          v-model="query.keyword"
          placeholder="资料名称/文件名"
          clearable
          class="filter-input"
          @on-enter="handleSearch"
        />
        <Button type="primary" class="ml14" @click="handleSearch">查询 <span class="enter-key">↵</span></Button>
        <Button type="primary" class="ml14" @click="open()">新增培训资料</Button>
      </div>

      <div class="table-wrap">
        <div class="table-body" ref="tableBody">
          <Table
            :columns="columns"
            :data="list"
            :loading="loading"
            highlight-row
            :max-height="tableBodyHeight"
            :scroll="{ x: 1200 }"
            no-data-text="暂无培训资料"
          >
            <template slot-scope="{ row }" slot="status">
              <span>{{ statusText(row.status) }}</span>
            </template>
            <template slot-scope="{ row }" slot="action">
              <a @click="open(row)">编辑</a>
              <Divider type="vertical" />
              <a @click="changeStatus(row, row.status === 1 ? 2 : 1)">{{ row.status === 1 ? '下架' : '发布' }}</a>
              <Divider type="vertical" />
              <a
                v-if="Number(row.allow_download) === 1 && row.file_path"
                :class="{ disabled: downloadingId === row.id }"
                @click="onDownload(row)"
              >{{ downloadingId === row.id ? '下载中…' : '下载' }}</a>
              <span v-else class="muted">不可下载</span>
            </template>
          </Table>
        </div>
        <div class="acea-row row-right page">
          <Page
            :total="total"
            :current="query.page"
            :page-size="query.limit"
            show-elevator
            show-total
            @on-change="pageChange"
          />
        </div>
      </div>
    </Card>

    <Modal
      v-model="visible"
      :title="form.id ? '编辑培训资料' : '新增培训资料'"
      width="1274"
      :mask-closable="false"
      :closable="!saving"
      @on-cancel="onCancel"
    >
      <Form :label-width="110">
        <FormItem label="资料名称" required>
          <Input v-model="form.title" placeholder="必填" />
        </FormItem>
        <FormItem label="分类">
          <Input v-model="form.category" placeholder="如：库存管理、门店收银" />
        </FormItem>
        <FormItem label="版本">
          <Input v-model="form.version" />
        </FormItem>
        <FormItem label="资料文件" required>
          <Upload
            :action="uploadUrl"
            :headers="header"
            :show-upload-list="false"
            :on-success="uploaded"
            accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
          >
            <Button icon="ios-cloud-upload-outline">上传文件</Button>
          </Upload>
          <span v-if="form.file_name" class="ml10">{{ form.file_name }}</span>
        </FormItem>
        <FormItem label="适用端">
          <CheckboxGroup v-model="clients">
            <Checkbox label="admin">总后台</Checkbox>
            <Checkbox label="store">门店后台</Checkbox>
            <Checkbox label="mobile">手机端</Checkbox>
          </CheckboxGroup>
        </FormItem>
        <FormItem label="必读">
          <i-switch v-model="form.is_required" :true-value="1" :false-value="0" />
        </FormItem>
        <FormItem label="允许下载">
          <i-switch v-model="form.allow_download" :true-value="1" :false-value="0" />
        </FormItem>
        <FormItem label="简介">
          <Input v-model="form.summary" type="textarea" :rows="3" />
        </FormItem>
      </Form>
      <div slot="footer">
        <Button :disabled="saving" @click="onCancel">取消</Button>
        <Button type="primary" class="ml14" :loading="saving" @click="save">确定</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import Setting from '@/setting';
import util from '@/libs/util';
import {
  trainingDocumentList,
  trainingDocumentSave,
  trainingDocumentStatus,
  trainingDocumentDownload,
} from '@/api/trainingDocument';

export default {
  name: 'adminTrainingDocument',
  data() {
    return {
      loading: false,
      saving: false,
      downloadingId: 0,
      tableBodyHeight: 400,
      total: 0,
      list: [],
      visible: false,
      query: { page: 1, limit: 20, keyword: '' },
      clients: ['admin', 'store', 'mobile'],
      form: this.empty(),
      uploadUrl: `${Setting.apiBaseURL}/training/document/upload`,
      header: { 'Authori-zation': `Bearer ${util.cookies.get('token')}` },
      columns: [
        { title: '资料名称', key: 'title', minWidth: 180 },
        { title: '分类', key: 'category', width: 120 },
        { title: '版本', key: 'version', width: 90 },
        { title: '文件', key: 'file_name', minWidth: 180 },
        { title: '下载次数', key: 'download_count', width: 100 },
        { title: '状态', slot: 'status', width: 90 },
        { title: '操作', slot: 'action', fixed: 'right', width: 220 },
      ],
    };
  },
  mounted() {
    this.load();
    this.calcTableHeight();
    window.addEventListener('resize', this.calcTableHeight);
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.calcTableHeight);
  },
  methods: {
    empty() {
      return {
        id: 0,
        title: '',
        category: '',
        version: 'V1.0',
        file_name: '',
        file_path: '',
        file_ext: '',
        file_size: 0,
        summary: '',
        is_required: 0,
        allow_download: 1,
        status: 0,
        client_types: 'all',
      };
    },
    statusText(status) {
      if (status === 1) return '已发布';
      if (status === 2) return '已下架';
      return '草稿';
    },
    calcTableHeight() {
      this.$nextTick(() => {
        const el = this.$refs.tableBody;
        if (el) {
          this.tableBodyHeight = Math.max(el.clientHeight - 8, 200);
        }
      });
    },
    handleSearch() {
      this.query.page = 1;
      this.load();
    },
    pageChange(page) {
      this.query.page = page;
      this.load();
    },
    load() {
      this.loading = true;
      trainingDocumentList(this.query)
        .then((r) => {
          this.list = (r.data && r.data.list) || [];
          this.total = (r.data && r.data.count) || 0;
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '加载失败');
        })
        .finally(() => {
          this.loading = false;
          this.calcTableHeight();
        });
    },
    open(row) {
      this.form = Object.assign(this.empty(), row || {});
      this.clients =
        this.form.client_types && this.form.client_types !== 'all'
          ? String(this.form.client_types).split(',')
          : ['admin', 'store', 'mobile'];
      this.saving = false;
      this.visible = true;
    },
    onCancel() {
      if (this.saving) return;
      this.visible = false;
    },
    uploaded(r) {
      if (r && r.data) {
        Object.assign(this.form, r.data);
      }
      this.$Message.success('文件上传成功');
    },
    save() {
      if (this.saving) return;
      if (!String(this.form.title || '').trim()) {
        if (this.$Message.required) {
          this.$Message.required('资料名称未填写');
        } else {
          this.$Message.warning('请填写资料名称');
        }
        this.visible = true;
        return;
      }
      if (!this.form.file_path || !this.form.file_name) {
        if (this.$Message.required) {
          this.$Message.required('资料文件未上传');
        } else {
          this.$Message.warning('请上传资料文件');
        }
        this.visible = true;
        return;
      }
      this.form.client_types = this.clients && this.clients.length ? this.clients.join(',') : 'all';
      this.saving = true;
      trainingDocumentSave(this.form)
        .then(() => {
          this.$Message.success('保存成功');
          this.visible = false;
          this.load();
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '保存失败');
          this.visible = true;
        })
        .finally(() => {
          this.saving = false;
        });
    },
    changeStatus(row, status) {
      trainingDocumentStatus(row.id, status)
        .then(() => {
          this.$Message.success('状态已更新');
          this.load();
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '操作失败');
        });
    },
    onDownload(row) {
      if (!row || !row.id || this.downloadingId) return;
      if (Number(row.allow_download) !== 1) {
        this.$Message.warning('该资料不允许下载');
        return;
      }
      this.downloadingId = row.id;
      trainingDocumentDownload(row.id, row.file_name)
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
          this.load();
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
  width 240px

.ml14
  margin-left 14px

.ml10
  margin-left 10px

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
