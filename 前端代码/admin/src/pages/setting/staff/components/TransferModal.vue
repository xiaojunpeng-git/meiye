<template>
  <Modal
    :value="value"
    title="店员调店"
    width="720"
    :mask-closable="false"
    @on-cancel="handleClose"
  >
    <Form
      v-if="value"
      ref="transferForm"
      :model="formData"
      :rules="rules"
      :label-width="110"
      @submit.native.prevent
    >
      <FormItem label="当前门店：">
        <Input :value="currentStoreName" readonly />
      </FormItem>
      <FormItem label="目标门店：" prop="target_store_id">
        <Select
          v-model="formData.target_store_id"
          clearable
          filterable
          transfer
          placeholder="请选择目标门店"
          @on-change="onTargetStoreChange"
        >
          <Option
            v-for="item in targetStoreList"
            :value="Number(item.id)"
            :key="item.id"
          >{{ item.name }}</Option>
        </Select>
      </FormItem>
      <FormItem label="店员身份：" prop="roles">
        <Select
          v-model="formData.roles"
          multiple
          clearable
          filterable
          transfer
          placeholder="请先选择目标门店"
          :disabled="!formData.target_store_id"
        >
          <Option
            v-for="item in roleList"
            :value="String(item.value)"
            :key="item.value"
          >{{ item.label }}</Option>
        </Select>
      </FormItem>
      <FormItem label="调店原因：">
        <Input
          v-model="formData.reason"
          type="textarea"
          :rows="3"
          placeholder="请输入调店原因（选填）"
        />
      </FormItem>
    </Form>
    <div slot="footer">
      <Button @click="handleClose">取消</Button>
      <Button type="primary" class="ml14" :loading="submitting" @click="handleSubmit">确认调店</Button>
    </div>
  </Modal>
</template>

<script>
import { merchantStoreListApi } from '@/api/setting';
import { staffTransfer, systemRoleList } from '@/api/staff';
import { findFirstRequiredError } from '@/utils/requiredCheck';

const REQUIRED_CHECK_ORDER = [
  {
    key: 'target_store_id',
    message: '目标门店未选择',
    isEmpty: (v) => !v || Number(v) <= 0,
  },
  {
    key: 'roles',
    message: '店员身份未选择',
    isEmpty: (v) => !Array.isArray(v) || v.length === 0,
  },
];

export default {
  name: 'StaffTransferModal',
  props: {
    value: {
      type: Boolean,
      default: false,
    },
    staffRow: {
      type: Object,
      default: () => ({}),
    },
  },
  data() {
    return {
      submitting: false,
      storeList: [],
      roleList: [],
      formData: {
        target_store_id: null,
        roles: [],
        reason: '',
      },
      rules: {
        target_store_id: [
          {
            required: true,
            type: 'number',
            min: 1,
            message: '请选择目标门店',
            trigger: 'change',
          },
        ],
        roles: [
          { required: true, type: 'array', min: 1, message: '请选择店员身份', trigger: 'change' },
        ],
      },
    };
  },
  computed: {
    currentStoreName() {
      return this.staffRow.store_name || this.staffRow.name || '-';
    },
    targetStoreList() {
      const currentId = Number(this.staffRow.store_id) || 0;
      return (this.storeList || []).filter((item) => Number(item.id) !== currentId);
    },
  },
  watch: {
    value(val) {
      if (val) {
        this.resetForm();
        this.loadStores();
      }
    },
  },
  methods: {
    resetForm() {
      this.formData = {
        target_store_id: null,
        roles: [],
        reason: '',
      };
      this.roleList = [];
      this.$nextTick(() => {
        this.$refs.transferForm && this.$refs.transferForm.resetFields();
      });
    },
    loadStores() {
      if (this.storeList.length) return;
      merchantStoreListApi()
        .then((res) => {
          this.storeList = (res.data || []).map((item) => ({
            ...item,
            id: Number(item.id),
          }));
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    onTargetStoreChange(storeId) {
      const id = Number(storeId) || 0;
      this.formData.target_store_id = id || null;
      this.formData.roles = [];
      this.$nextTick(() => {
        this.$refs.transferForm && this.$refs.transferForm.validateField('target_store_id');
      });
      if (!id) {
        this.roleList = [];
        return;
      }
      systemRoleList(id)
        .then((res) => {
          this.roleList = res.data || [];
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    handleSubmit() {
      const first = findFirstRequiredError(REQUIRED_CHECK_ORDER, this.formData);
      if (first) {
        this.$Message.required(first.message);
        this.$nextTick(() => {
          this.$refs.transferForm && this.$refs.transferForm.validateField(first.key);
        });
        return;
      }
      const staffId = Number(this.staffRow.id);
      const targetStoreId = Number(this.formData.target_store_id);
      if (!staffId) {
        this.$Message.error('缺少店员信息');
        return;
      }
      this.submitting = true;
      staffTransfer(staffId, {
        target_store_id: targetStoreId,
        roles: this.formData.roles,
        reason: this.formData.reason,
        immediate: 1,
      })
        .then((res) => {
          this.$Message.success(res.msg || '调店成功');
          this.$emit('success');
          this.handleClose();
        })
        .catch((err) => {
          const msg = (err && err.msg) || '调店失败';
          if (/未填写|未选择|请选择|请填写|请输入|必填/.test(msg)) {
            this.$Message.required(msg);
          } else {
            this.$Message.error(msg);
          }
        })
        .finally(() => {
          this.submitting = false;
        });
    },
    handleClose() {
      this.$emit('input', false);
    },
  },
};
</script>

<style scoped lang="stylus">
.ml14
  margin-left 14px
</style>
