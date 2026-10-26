<template>
  <Modal
    :value="value"
    :title="editId > 0 ? '编辑更新日志' : '新增更新日志'"
    width="1274"
    :mask-closable="false"
    :styles="{ top: '30px' }"
    @on-cancel="handleClose"
  >
    <Spin v-if="loading" fix />
    <Form
      v-if="value"
      ref="formRef"
      :model="form"
      :label-width="110"
      :label-position="labelPosition"
      @submit.native.prevent
    >
      <Row :gutter="24">
        <Col :span="12">
          <FormItem label="发布日期：">
            <DatePicker
              transfer
              :editable="false"
              clearable
              type="date"
              format="yyyy-MM-dd"
              placeholder="请选择发布日期"
              style="width: 100%"
              :value="form.publish_date"
              @on-change="(val) => setField('publish_date', val)"
            />
          </FormItem>
        </Col>
        <Col :span="12">
          <FormItem label="标题：">
            <Input v-model="form.title" placeholder="请输入标题" />
          </FormItem>
        </Col>
      </Row>
      <Row :gutter="24">
        <Col :span="12">
          <FormItem label="版本号：">
            <Input v-model="form.version" placeholder="选填" />
          </FormItem>
        </Col>
        <Col :span="12">
          <FormItem label="排序：">
            <InputNumber v-model="form.sort" :min="0" style="width: 100%" />
          </FormItem>
        </Col>
      </Row>
      <FormItem label="摘要：">
        <Input v-model="form.summary" type="textarea" :rows="2" placeholder="选填，用于列表预览" />
      </FormItem>
      <FormItem label="展示端：">
        <CheckboxGroup v-model="form.platforms">
          <Checkbox v-for="item in platformOptions" :key="item.value" :label="item.value">
            {{ item.label }}
          </Checkbox>
        </CheckboxGroup>
      </FormItem>
      <Row :gutter="24">
        <Col :span="8">
          <FormItem label="重要更新：">
            <i-switch v-model="form.is_important" :true-value="1" :false-value="0" @on-change="onImportantChange" />
          </FormItem>
        </Col>
        <Col :span="8">
          <FormItem label="首页提示：">
            <i-switch
              v-model="form.is_popup"
              :true-value="1"
              :false-value="0"
              :disabled="form.is_important !== 1"
            />
            <div class="form-tip">仅用于影响使用的重要更新，普通更新请使用红点提醒。</div>
          </FormItem>
        </Col>
        <Col :span="8">
          <FormItem label="状态：">
            <RadioGroup v-model="form.status">
              <Radio :label="0">草稿</Radio>
              <Radio :label="1">待发布</Radio>
            </RadioGroup>
          </FormItem>
        </Col>
      </Row>
      <Row :gutter="24">
        <Col :span="12">
          <FormItem label="生效时间：">
            <DatePicker
              transfer
              :editable="false"
              clearable
              type="datetime"
              format="yyyy-MM-dd HH:mm"
              placeholder="默认立即生效"
              style="width: 100%"
              :value="publishTimeStr"
              @on-change="onPublishTimeChange"
            />
            <div class="form-tip">未到生效时间不会展示；功能验证通过后再发布。</div>
          </FormItem>
        </Col>
        <Col :span="12">
          <FormItem label="release_key：">
            <Input v-model="form.release_key" placeholder="选填，部署批次幂等键" />
          </FormItem>
        </Col>
      </Row>
      <FormItem label="内部备注：">
        <Input v-model="form.internal_note" type="textarea" :rows="2" placeholder="仅后台可见" />
      </FormItem>

      <div class="items-header">
        <span class="items-title">变更明细</span>
        <Button type="dashed" size="small" icon="md-add" @click="addItem">添加明细</Button>
      </div>
      <Table :columns="itemColumns" :data="form.items" border size="small" class="items-table">
        <template slot-scope="{ row, index }" slot="change_type">
          <Select v-model="form.items[index].change_type" transfer style="width: 100%">
            <Option v-for="(label, key) in changeTypeMap" :key="key" :value="key">{{ label }}</Option>
          </Select>
        </template>
        <template slot-scope="{ row, index }" slot="module_name">
          <Input v-model="form.items[index].module_name" placeholder="如库存管理" />
        </template>
        <template slot-scope="{ row, index }" slot="content">
          <Input v-model="form.items[index].content" type="textarea" :rows="2" placeholder="变更内容" />
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a v-if="form.items.length > 1" @click="removeItem(index)">删除</a>
          <span v-else class="text-muted">—</span>
        </template>
      </Table>
    </Form>
    <div slot="footer">
      <Button @click="handleClose">取消</Button>
      <Button type="primary" :loading="submitting" @click="handleSubmit">保存</Button>
    </div>
  </Modal>
</template>

<script>
import { mapState } from 'vuex';
import { findFirstRequiredError } from '@/utils/requiredCheck';
import { changelogCreateApi, changelogInfoApi, changelogUpdateApi } from '@/api/changelog';

const EMPTY_ITEM = () => ({
  change_type: 'add',
  module_name: '',
  content: '',
  sort: 0,
});

const REQUIRED_CHECK_ORDER = [
  { key: 'publish_date', message: '发布日期未选择', isEmpty: (v) => !String(v || '').trim() },
  { key: 'title', message: '标题未填写', isEmpty: (v) => !String(v || '').trim() },
  {
    key: 'platforms',
    message: '展示端未选择',
    isEmpty: (v) => !Array.isArray(v) || !v.length,
  },
  {
    key: 'items',
    message: '变更明细未填写',
    isEmpty: (v) => !Array.isArray(v) || !v.some((item) => String(item.content || '').trim()),
  },
];

export default {
  name: 'ChangelogEditModal',
  props: {
    value: { type: Boolean, default: false },
    editId: { type: Number, default: 0 },
  },
  data() {
    return {
      loading: false,
      submitting: false,
      publishTimeStr: '',
      changeTypeMap: {
        add: '新增',
        optimize: '优化',
        adjust: '调整',
        fix: '修复',
        offline: '下线',
      },
      platformOptions: [
        { value: 'mini', label: '小程序' },
        { value: 'admin', label: '平台后台' },
        { value: 'store', label: '门店后台' },
        { value: 'cashier', label: '收银台' },
      ],
      form: this.getDefaultForm(),
      itemColumns: [
        { title: '类型', slot: 'change_type', width: 120 },
        { title: '所属模块', slot: 'module_name', width: 160 },
        { title: '内容', slot: 'content', minWidth: 320 },
        { title: '操作', slot: 'action', width: 70, align: 'center' },
      ],
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  watch: {
    value(val) {
      if (val) {
        this.initForm();
      }
    },
  },
  methods: {
    getDefaultForm() {
      const today = this.formatDate(new Date());
      return {
        title: this.formatTitleDate(today),
        version: '',
        summary: '',
        publish_date: today,
        platforms: [],
        is_important: 0,
        is_popup: 0,
        sort: 0,
        internal_note: '',
        release_key: '',
        status: 0,
        publish_time: 0,
        items: [EMPTY_ITEM()],
      };
    },
    formatDate(date) {
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      return `${y}-${m}-${d}`;
    },
    formatTitleDate(dateStr) {
      if (!dateStr) return '';
      const parts = dateStr.split('-');
      if (parts.length !== 3) return dateStr;
      return `${parts[0]}年${Number(parts[1])}月${Number(parts[2])}日系统更新`;
    },
    initForm() {
      if (this.editId > 0) {
        this.loadDetail();
        return;
      }
      this.form = this.getDefaultForm();
      this.publishTimeStr = '';
    },
    loadDetail() {
      this.loading = true;
      changelogInfoApi(this.editId, { with_audit: 0 })
        .then((res) => {
          const data = res.data || {};
          this.form = {
            title: data.title || '',
            version: data.version || '',
            summary: data.summary || '',
            publish_date: data.publish_date || '',
            platforms: Array.isArray(data.platforms_arr) ? [...data.platforms_arr] : [],
            is_important: Number(data.is_important) === 1 ? 1 : 0,
            is_popup: Number(data.is_popup) === 1 ? 1 : 0,
            sort: Number(data.sort) || 0,
            internal_note: data.internal_note || '',
            release_key: data.release_key || '',
            status: [0, 1].includes(Number(data.status)) ? Number(data.status) : 0,
            publish_time: Number(data.publish_time) || 0,
            items: (data.items && data.items.length)
              ? data.items.map((item) => ({
                change_type: item.change_type || 'add',
                module_name: item.module_name || '',
                content: item.content || '',
                sort: Number(item.sort) || 0,
              }))
              : [EMPTY_ITEM()],
          };
          this.publishTimeStr = this.form.publish_time
            ? this.timestampToStr(this.form.publish_time)
            : '';
        })
        .catch((err) => {
          this.$Message.error(err.msg || '加载失败');
          this.handleClose();
        })
        .finally(() => {
          this.loading = false;
        });
    },
    timestampToStr(ts) {
      const date = new Date(ts * 1000);
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      const hh = String(date.getHours()).padStart(2, '0');
      const mm = String(date.getMinutes()).padStart(2, '0');
      return `${y}-${m}-${d} ${hh}:${mm}`;
    },
    strToTimestamp(str) {
      if (!str) return 0;
      const normalized = str.replace(/-/g, '/');
      const ts = Math.floor(new Date(normalized).getTime() / 1000);
      return Number.isNaN(ts) ? 0 : ts;
    },
    setField(key, val) {
      this.form[key] = val || '';
      if (key === 'publish_date' && val && !this.editId) {
        this.form.title = this.formatTitleDate(val);
      }
    },
    onPublishTimeChange(val) {
      this.publishTimeStr = val || '';
      this.form.publish_time = this.strToTimestamp(val);
    },
    onImportantChange(val) {
      if (Number(val) !== 1) {
        this.form.is_popup = 0;
      }
    },
    addItem() {
      this.form.items.push(EMPTY_ITEM());
    },
    removeItem(index) {
      if (this.form.items.length <= 1) return;
      this.form.items.splice(index, 1);
    },
    handleClose() {
      this.$emit('input', false);
      this.form = this.getDefaultForm();
      this.publishTimeStr = '';
    },
    handleSubmit() {
      const first = findFirstRequiredError(REQUIRED_CHECK_ORDER, this.form);
      if (first) {
        this.$Message.required(first.message);
        return;
      }
      if (this.form.is_popup === 1 && this.form.is_important !== 1) {
        this.$Message.required('首页提示仅重要更新可开启');
        return;
      }
      const payload = {
        ...this.form,
        platforms: this.form.platforms.join(','),
        items: this.form.items
          .filter((item) => String(item.content || '').trim())
          .map((item, index) => ({
            change_type: item.change_type,
            module_name: item.module_name,
            content: item.content,
            sort: index,
          })),
      };
      this.submitting = true;
      const req = this.editId > 0
        ? changelogUpdateApi(this.editId, payload)
        : changelogCreateApi(payload);
      req
        .then((res) => {
          this.$Message.success(res.msg || '保存成功');
          this.$emit('success');
          this.handleClose();
        })
        .catch((err) => {
          this.$Message.error(err.msg || '保存失败');
        })
        .finally(() => {
          this.submitting = false;
        });
    },
  },
};
</script>

<style scoped lang="stylus">
.form-tip
  margin-top 4px
  font-size 12px
  color #999
  line-height 1.5

.items-header
  display flex
  align-items center
  justify-content space-between
  margin 8px 0 12px

.items-title
  font-size 14px
  font-weight 600

.items-table
  margin-bottom 8px

.text-muted
  color #ccc
</style>
