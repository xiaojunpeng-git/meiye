<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="business-config-header">
        <h2>记账设置</h2>
        <Button icon="md-refresh" :loading="restoring" @click="restoreDefaults">恢复默认名称</Button>
      </div>

      <h3 class="business-config-section-title">记账收款方式</h3>
      <Table :columns="columns" :data="collectionMethods" :loading="loading" class="ivu-mt">
        <template slot-scope="{ row }" slot="status">
          <Tag :color="row.status ? 'green' : 'default'">{{ row.status ? '启用' : '停用' }}</Tag>
        </template>
        <template slot-scope="{ row }" slot="action">
          <a @click="openEdit(row)">编辑</a>
        </template>
      </Table>

      <section v-if="legacyEntryMethods.length" class="business-config-section">
        <h3 class="business-config-section-title">独立录入方式</h3>
        <Table :columns="legacyColumns" :data="legacyEntryMethods" :loading="loading" class="ivu-mt">
          <template slot-scope="{ row }" slot="status">
            <Tag :color="row.status ? 'green' : 'default'">{{ row.status ? '启用' : '停用' }}</Tag>
          </template>
          <template slot="rule">
            <span>现金业绩 0，不可组合收款</span>
          </template>
          <template slot-scope="{ row }" slot="action">
            <a @click="openEdit(row)">编辑</a>
          </template>
        </Table>
      </section>
    </Card>

    <Modal v-model="modalVisible" title="编辑记账方式" :mask-closable="false">
      <Form ref="accountingForm" :model="form" :rules="rules" :label-width="100">
        <FormItem label="系统代码">
          <Input :value="form.code" disabled />
        </FormItem>
        <FormItem label="默认名称">
          <Input :value="form.defaultName" disabled />
        </FormItem>
        <FormItem label="显示名称" prop="displayName">
          <Input v-model.trim="form.displayName" maxlength="20" show-word-limit placeholder="请输入显示名称" />
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
  accountingMethodListApi,
  accountingMethodRestoreApi,
  accountingMethodUpdateApi,
  createBusinessConfigIdempotencyKey
} from '@/api/productBusinessConfig';

function numberValue(value, fallback) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function normalizeMethod(item) {
  return {
    code: String(item.code || ''),
    defaultName: String(item.defaultName !== undefined ? item.defaultName : item.default_name || ''),
    displayName: String(item.displayName !== undefined ? item.displayName : item.display_name || ''),
    status: numberValue(item.status, 0),
    sort: numberValue(item.sort, 0),
    version: numberValue(item.version, 0)
  };
}

export default {
  name: 'ProductAccountingSetting',
  data() {
    return {
      loading: false,
      saving: false,
      restoring: false,
      modalVisible: false,
      methods: [],
      form: this.emptyForm(),
      rules: {
        displayName: [{ required: true, message: '请输入显示名称', trigger: 'blur' }],
        sort: [{ required: true, type: 'number', message: '请输入排序', trigger: 'change' }]
      },
      columns: [
        { title: '系统代码', key: 'code', minWidth: 160 },
        { title: '默认名称', key: 'defaultName', minWidth: 140 },
        { title: '显示名称', key: 'displayName', minWidth: 160 },
        { title: '排序', key: 'sort', width: 100 },
        { title: '状态', slot: 'status', width: 100 },
        { title: '操作', slot: 'action', width: 100 }
      ],
      legacyColumns: [
        { title: '系统代码', key: 'code', minWidth: 160 },
        { title: '默认名称', key: 'defaultName', minWidth: 140 },
        { title: '显示名称', key: 'displayName', minWidth: 160 },
        { title: '业务规则', slot: 'rule', minWidth: 220 },
        { title: '排序', key: 'sort', width: 100 },
        { title: '状态', slot: 'status', width: 100 },
        { title: '操作', slot: 'action', width: 100 }
      ]
    };
  },
  computed: {
    collectionMethods() {
      return this.methods.filter(item => item.code !== 'old_card_entry');
    },
    legacyEntryMethods() {
      return this.methods.filter(item => item.code === 'old_card_entry');
    }
  },
  created() {
    this.loadMethods();
  },
  methods: {
    emptyForm() {
      return { code: '', defaultName: '', displayName: '', status: 1, sort: 0, version: 0 };
    },
    extractList(res) {
      if (Array.isArray(res.data)) return res.data;
      return res.data && Array.isArray(res.data.list) ? res.data.list : [];
    },
    loadMethods() {
      this.loading = true;
      accountingMethodListApi()
        .then(res => {
          this.methods = this.extractList(res).map(normalizeMethod);
        })
        .catch(err => this.$Message.error((err && err.msg) || '记账设置加载失败'))
        .finally(() => {
          this.loading = false;
        });
    },
    openEdit(row) {
      this.form = Object.assign(this.emptyForm(), row);
      this.modalVisible = true;
    },
    submit() {
      this.$refs.accountingForm.validate(valid => {
        if (!valid) return;
        this.saving = true;
        accountingMethodUpdateApi(this.form.code, {
          displayName: this.form.displayName,
          status: this.form.status,
          sort: this.form.sort,
          expectedVersion: this.form.version,
          idempotencyKey: createBusinessConfigIdempotencyKey('ACCOUNTING-UPDATE')
        })
          .then(res => {
            this.$Message.success(res.msg || '保存成功');
            this.modalVisible = false;
            this.loadMethods();
          })
          .catch(err => this.$Message.error((err && err.msg) || '保存失败'))
          .finally(() => {
            this.saving = false;
          });
      });
    },
    restoreDefaults() {
      this.$Modal.confirm({
        title: '恢复默认名称',
        content: '将恢复所有记账方式的默认显示名称，启用状态和排序保持不变。',
        onOk: () => {
          this.restoring = true;
          return accountingMethodRestoreApi({
            idempotencyKey: createBusinessConfigIdempotencyKey('ACCOUNTING-RESTORE')
          })
            .then(res => {
              this.$Message.success(res.msg || '默认名称已恢复');
              this.loadMethods();
            })
            .catch(err => this.$Message.error((err && err.msg) || '恢复失败'))
            .finally(() => {
              this.restoring = false;
            });
        }
      });
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

.business-config-section
  margin-top 28px

.business-config-section-title
  margin 24px 0 0
  font-size 15px

</style>
