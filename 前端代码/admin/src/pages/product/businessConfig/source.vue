<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="business-config-header">
        <h2>来源设置</h2>
      </div>

      <vxe-table
        :data="sources"
        row-id="id"
        class="vxeTable ivu-mt"
        highlight-hover-row
        :loading="loading"
        header-row-class-name="false"
        :tree-config="{ children: 'children', reserve: true }"
      >
        <vxe-table-column field="name" tree-node title="来源名称" min-width="300" />
        <vxe-table-column title="当前层级" width="120">
          <template v-slot="{ row }">
            {{ row.level === 2 ? '二级来源' : '一级来源' }}
          </template>
        </vxe-table-column>
        <vxe-table-column title="固定来源" width="120">
          <template v-slot="{ row }">
            <i-switch
              :value="row.isFixed"
              :true-value="1"
              :false-value="0"
              :loading="fixedSavingId === row.id"
              @on-change="changeFixed(row, $event)"
            >
              <span slot="open">启用</span>
              <span slot="close">停用</span>
            </i-switch>
          </template>
        </vxe-table-column>
        <vxe-table-column title="状态" width="120">
          <template v-slot="{ row }">
            <i-switch
              v-if="row.level === 2"
              :value="row.status"
              :true-value="1"
              :false-value="0"
              :loading="statusSavingId === row.id"
              @on-change="changeStatus(row, $event)"
            >
              <span slot="open">启用</span>
              <span slot="close">停用</span>
            </i-switch>
            <span v-else>{{ row.status === 1 ? '启用' : '停用' }}</span>
          </template>
        </vxe-table-column>
        <vxe-table-column title="操作" width="130">
          <template v-slot="{ row }">
            <a v-if="row.level === 1" @click="openCreate(row)">新增二级来源</a>
            <a v-else @click="openEdit(row)">编辑</a>
          </template>
        </vxe-table-column>
      </vxe-table>
    </Card>

    <Modal v-model="modalVisible" :title="editingId ? '编辑二级来源' : '新增二级来源'" :mask-closable="false">
      <Form ref="sourceForm" :model="form" :rules="rules" :label-width="90">
        <FormItem v-if="!editingId" label="一级来源">
          <Input :value="form.parentName" readonly />
        </FormItem>
        <FormItem label="来源名称" prop="name">
          <Input v-model.trim="form.name" maxlength="64" show-word-limit placeholder="请输入来源名称" />
        </FormItem>
        <FormItem v-if="editingId" label="状态">
          <i-switch v-model="form.status" :true-value="1" :false-value="0">
            <span slot="open">启用</span>
            <span slot="close">停用</span>
          </i-switch>
        </FormItem>
      </Form>
      <div slot="footer">
        <Button @click="modalVisible = false">取消</Button>
        <Button type="primary" :loading="saving" @click="submit">保存</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import {
  businessSecondarySourceCreateApi,
  businessSourceListApi,
  businessSourceUpdateApi
} from '@/api/productBusinessConfig';

function normalizeSource(item, level, parentName) {
  return {
    id: Number(item.id) || 0,
    name: String(item.name || ''),
    status: Number(item.status) === 1 ? 1 : 0,
    isFixed: Number(item.isFixed !== undefined ? item.isFixed : item.is_fixed) === 1 ? 1 : 0,
    level: level || 1,
    primaryName: level === 2 ? String(parentName || '') : String(item.name || ''),
    children: Array.isArray(item.children)
      ? item.children.map(child => normalizeSource(child, 2, item.name))
      : []
  };
}

export default {
  name: 'ProductBusinessSourceSetting',
  data() {
    return {
      loading: false,
      saving: false,
      statusSavingId: 0,
      fixedSavingId: 0,
      modalVisible: false,
      editingId: 0,
      sources: [],
      form: this.emptyForm(),
      rules: {
        name: [{ required: true, message: '请输入来源名称', trigger: 'blur' }]
      }
    };
  },
  created() {
    this.loadSources();
  },
  methods: {
    emptyForm() {
      return { name: '', status: 1, parentId: 0, parentName: '' };
    },
    extractList(res) {
      const payload = res && res.data !== undefined ? res.data : res;
      if (Array.isArray(payload)) return payload;
      if (payload && Array.isArray(payload.list)) return payload.list;
      if (payload && Array.isArray(payload.data)) return payload.data;
      return [];
    },
    loadSources() {
      this.loading = true;
      businessSourceListApi()
        .then(res => {
          this.sources = this.extractList(res).map(item => normalizeSource(item, 1));
        })
        .catch(err => this.$Message.error((err && err.msg) || '来源设置加载失败'))
        .finally(() => {
          this.loading = false;
        });
    },
    openEdit(row) {
      this.editingId = row.id;
      this.form = { name: row.name, status: row.status, parentId: 0, parentName: row.primaryName };
      this.modalVisible = true;
    },
    openCreate(parent) {
      this.editingId = 0;
      this.form = { name: '', status: 1, parentId: parent.id, parentName: parent.name };
      this.modalVisible = true;
      this.$nextTick(() => this.$refs.sourceForm && this.$refs.sourceForm.resetFields());
    },
    sourcePayload(name, status) {
      return { name, status };
    },
    fixedPayload(isFixed) {
      return { isFixed };
    },
    submit() {
      this.$refs.sourceForm.validate(valid => {
        if (!valid) return;
        this.saving = true;
        const request = this.editingId
          ? businessSourceUpdateApi(this.editingId, this.sourcePayload(this.form.name, this.form.status))
          : businessSecondarySourceCreateApi({ parentId: this.form.parentId, name: this.form.name });
        request
          .then(res => {
            this.$Message.success(res.msg || '保存成功');
            this.modalVisible = false;
            this.loadSources();
          })
          .catch(err => this.$Message.error((err && err.msg) || '保存失败'))
          .finally(() => {
            this.saving = false;
          });
      });
    },
    changeStatus(row, status) {
      this.statusSavingId = row.id;
      businessSourceUpdateApi(row.id, this.sourcePayload(row.name, status))
        .then(res => {
          this.$Message.success(res.msg || '状态已更新');
          this.loadSources();
        })
        .catch(err => {
          this.$Message.error((err && err.msg) || '状态更新失败');
          this.loadSources();
        })
        .finally(() => {
          this.statusSavingId = 0;
        });
    },
    changeFixed(row, isFixed) {
      this.fixedSavingId = row.id;
      businessSourceUpdateApi(row.id, this.fixedPayload(isFixed))
        .then(res => {
          this.$Message.success(res.msg || '固定来源状态已更新');
          this.loadSources();
        })
        .catch(err => {
          this.$Message.error((err && err.msg) || '固定来源状态更新失败');
          this.loadSources();
        })
        .finally(() => {
          this.fixedSavingId = 0;
        });
    }
  }
};
</script>

<style scoped lang="stylus">
.business-config-header h2
  margin 0
  font-size 18px
</style>
