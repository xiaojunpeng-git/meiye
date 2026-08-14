<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="business-config-header">
        <h2>来源设置</h2>
        <Button type="primary" icon="md-add" @click="openCreate">新增来源</Button>
      </div>

      <Table :columns="columns" :data="tableRows" :loading="loading" class="ivu-mt">
        <template slot-scope="{ row }" slot="name">
          <span :class="{ 'source-name--child': row.level === 2 }">{{ row.name }}</span>
        </template>
        <template slot-scope="{ row }" slot="level">
          {{ row.level === 2 ? '二级来源' : '一级来源' }}
        </template>
        <template slot-scope="{ row }" slot="attributionType">
          {{ attributionTypeLabel(row.attributionType) }}
        </template>
        <template slot-scope="{ row }" slot="requireSecondary">
          <span v-if="row.level === 2">—</span>
          <Tag v-else :color="row.requireSecondary ? 'blue' : 'default'">
            {{ row.requireSecondary ? '必须选择' : '可不选择' }}
          </Tag>
        </template>
        <template slot-scope="{ row }" slot="status">
          <i-switch
            :value="row.status"
            :true-value="1"
            :false-value="0"
            :loading="statusSavingId === row.id"
            @on-change="changeStatus(row, $event)"
          >
            <span slot="open">启用</span>
            <span slot="close">停用</span>
          </i-switch>
        </template>
        <template slot-scope="{ row }" slot="action">
          <a @click="openEdit(row)">编辑</a>
          <template v-if="row.level === 1">
            <Divider type="vertical" />
            <a @click="openCreate(row.id)">新增二级来源</a>
          </template>
        </template>
      </Table>
    </Card>

    <Modal v-model="modalVisible" :title="editingId ? '编辑来源' : '新增来源'" :mask-closable="false">
      <Form ref="sourceForm" :model="form" :rules="rules" :label-width="110">
        <FormItem label="来源名称" prop="name">
          <Input v-model.trim="form.name" maxlength="30" show-word-limit placeholder="请输入来源名称" />
        </FormItem>
        <FormItem label="上级来源" prop="parentId">
          <Select v-model="form.parentId" :disabled="Boolean(editingId)">
            <Option :value="0">无（一级来源）</Option>
            <Option v-for="item in availableParents" :key="item.id" :value="item.id">
              {{ item.name }}
            </Option>
          </Select>
        </FormItem>
        <FormItem label="来源类型" prop="attributionType">
          <Select v-model="form.attributionType">
            <Option v-for="item in attributionTypes" :key="item.value" :value="item.value">{{ item.label }}</Option>
          </Select>
        </FormItem>
        <FormItem v-if="form.parentId === 0" label="二级来源要求">
          <RadioGroup v-model="form.requireSecondary">
            <Radio :label="0">可不选择</Radio>
            <Radio :label="1" :disabled="!canRequireSecondary">必须选择</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem label="状态">
          <i-switch v-model="form.status" :true-value="1" :false-value="0">
            <span slot="open">启用</span>
            <span slot="close">停用</span>
          </i-switch>
        </FormItem>
        <FormItem label="排序" prop="sort">
          <InputNumber v-model="form.sort" :min="0" :max="9999" :precision="0" />
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
  businessSourceCreateApi,
  businessSourceListApi,
  businessSourceUpdateApi,
  createBusinessConfigIdempotencyKey
} from '@/api/productBusinessConfig';

function numberValue(value, fallback) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function normalizeSource(item, level, inheritedParentId) {
  const parentId = numberValue(item.parentId !== undefined ? item.parentId : item.parent_id, inheritedParentId || 0);
  const children = Array.isArray(item.children) ? item.children : [];
  return {
    id: numberValue(item.id, 0),
    name: String(item.name || ''),
    parentId,
    level: level || (parentId ? 2 : 1),
    status: numberValue(item.status, 0),
    sort: numberValue(item.sort, 0),
    version: numberValue(item.version, 0),
    requireSecondary: numberValue(
      item.requireSecondary !== undefined ? item.requireSecondary : item.require_secondary,
      0
    ),
    attributionType: String(item.attributionType || item.attribution_type || 'other'),
    children: children.map(child => normalizeSource(child, 2, item.id))
  };
}

function normalizeSourceTree(items) {
  const normalized = items.map(item => normalizeSource(item));
  const roots = normalized.filter(item => item.parentId === 0);
  const rootMap = roots.reduce((map, item) => {
    map[item.id] = item;
    return map;
  }, {});
  normalized.filter(item => item.parentId !== 0).forEach(item => {
    const parent = rootMap[item.parentId];
    if (parent && !parent.children.some(child => child.id === item.id)) parent.children.push(item);
  });
  return roots;
}

export default {
  name: 'ProductBusinessSourceSetting',
  data() {
    return {
      loading: false,
      saving: false,
      statusSavingId: 0,
      modalVisible: false,
      editingId: 0,
      sources: [],
      attributionTypes: [
        { value: 'guide', label: '导购' }, { value: 'beautician', label: '美容师' },
        { value: 'coach', label: '拓客教练' }, { value: 'external', label: '外接地推' },
        { value: 'online', label: '线上' }, { value: 'referral', label: '老客转介绍' }, { value: 'other', label: '其他' }
      ],
      form: this.emptyForm(),
      rules: {
        name: [{ required: true, message: '请输入来源名称', trigger: 'blur' }],
        parentId: [{ required: true, type: 'number', message: '请选择上级来源', trigger: 'change' }],
        sort: [{ required: true, type: 'number', message: '请输入排序', trigger: 'change' }]
      },
      columns: [
        { title: '来源名称', slot: 'name', minWidth: 220 },
        { title: '层级', slot: 'level', width: 120 },
        { title: '来源类型', slot: 'attributionType', minWidth: 120 },
        { title: '二级来源要求', slot: 'requireSecondary', minWidth: 150 },
        { title: '排序', key: 'sort', width: 100 },
        { title: '状态', slot: 'status', width: 120 },
        { title: '操作', slot: 'action', minWidth: 190 }
      ]
    };
  },
  computed: {
    tableRows() {
      return this.sources.reduce((rows, source) => rows.concat(source, source.children || []), []);
    },
    availableParents() {
      return this.sources.filter(item => item.parentId === 0 && item.id !== this.editingId);
    },
    canRequireSecondary() {
      if (!this.editingId || this.form.parentId !== 0) return false;
      const source = this.sources.find(item => item.id === this.editingId);
      return Boolean(source && source.children.some(child => child.status === 1));
    }
  },
  created() {
    this.loadSources();
  },
  methods: {
    emptyForm(parentId) {
      return {
        name: '',
        parentId: Number(parentId) || 0,
        status: 1,
        sort: 0,
        version: 0,
        requireSecondary: 0,
        attributionType: 'other'
      };
    },
    extractList(res) {
      if (Array.isArray(res.data)) return res.data;
      return res.data && Array.isArray(res.data.list) ? res.data.list : [];
    },
    loadSources() {
      this.loading = true;
      businessSourceListApi()
        .then(res => {
          this.sources = normalizeSourceTree(this.extractList(res));
        })
        .catch(err => this.$Message.error((err && err.msg) || '来源设置加载失败'))
        .finally(() => {
          this.loading = false;
        });
    },
    openCreate(parentId) {
      this.editingId = 0;
      const selectedParentId = Number(parentId) || 0;
      this.form = this.emptyForm();
      this.modalVisible = true;
      this.$nextTick(() => {
        if (this.$refs.sourceForm) this.$refs.sourceForm.resetFields();
        this.form.parentId = selectedParentId;
      });
    },
    openEdit(row) {
      this.editingId = row.id;
      this.form = {
        name: row.name,
        parentId: row.parentId,
        status: row.status,
        sort: row.sort,
        version: row.version,
        requireSecondary: row.level === 1 ? row.requireSecondary : 0,
        attributionType: row.attributionType
      };
      this.modalVisible = true;
    },
    payload(isUpdate) {
      const payload = {
        name: this.form.name,
        parentId: this.form.parentId,
        status: this.form.status,
        sort: this.form.sort,
        requireSecondary: this.form.parentId === 0 ? this.form.requireSecondary : 0,
        attributionType: this.form.attributionType,
        idempotencyKey: createBusinessConfigIdempotencyKey(this.editingId ? 'SOURCE-UPDATE' : 'SOURCE-CREATE')
      };
      if (isUpdate) payload.expectedVersion = this.form.version;
      return payload;
    },
    submit() {
      this.$refs.sourceForm.validate(valid => {
        if (!valid) return;
        this.saving = true;
        const request = this.editingId
          ? businessSourceUpdateApi(this.editingId, this.payload(true))
          : businessSourceCreateApi(this.payload(false));
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
      businessSourceUpdateApi(row.id, {
        name: row.name,
        parentId: row.parentId,
        status,
        sort: row.sort,
        requireSecondary: row.level === 1 ? row.requireSecondary : 0,
        attributionType: row.attributionType,
        expectedVersion: row.version,
        idempotencyKey: createBusinessConfigIdempotencyKey('SOURCE-STATUS')
      })
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
    attributionTypeLabel(value) {
      const item = this.attributionTypes.find(type => type.value === value)
      return item ? item.label : '其他'
    }
  }
};
</script>

<style scoped lang="stylus">
.business-config-header
  display flex
  align-items center
  justify-content space-between

.business-config-header h2
  margin 0
  font-size 18px

.source-name--child
  display inline-block
  padding-left 28px
  position relative

.source-name--child:before
  content '└'
  color #c5c8ce
  left 8px
  position absolute
</style>
