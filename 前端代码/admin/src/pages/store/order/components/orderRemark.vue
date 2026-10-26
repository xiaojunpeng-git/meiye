<template>
  <Modal
    v-model="modals"
    scrollable
    :title="currentTab == 5 ? '撤销原因' : '订单备注'"
    class="order_box"
    :closable="false"
  >
    <Form
      ref="formValidate"
      :model="formValidate"
      :rules="ruleValidate"
      :label-width="100"
      @submit.native.prevent
    >
      <FormItem label="备注：" prop="remark" v-if="currentTab != 5">
        <Input
          v-model="formValidate.remark"
          maxlength="200"
          show-word-limit
          type="textarea"
          placeholder="订单备注"
          style="width: 100%"
        />
      </FormItem>
      <FormItem label="撤销原因：" prop="remark" v-else>
        <Input
          v-model="formValidate.remark"
          maxlength="200"
          show-word-limit
          type="textarea"
          placeholder="撤销原因"
          style="width: 100%"
        />
      </FormItem>
    </Form>
    <div slot="footer">
      <Button @click="cancel('formValidate')">取消</Button>
      <Button type="primary" @click="putRemark('formValidate')">提交</Button>
    </div>
  </Modal>
</template>

<script>
import {
  putRemarkData,
  putRechargeRemarkData,
  putVipRemarkData,
  postChexiao,
} from '@/api/store';
export default {
  name: 'orderMark',
  props: {
    orderId: Number,
    currentTab: {
      type: [String, Number],
      default: '',
    },
    tab: Number,
  },
  data() {
    return {
      tabs: '',
      formValidate: {
        remark: '',
      },
      modals: false,
      ruleValidate: {
        remark: [{
          required: true,
          message: '请输入备注信息',
          trigger: 'blur',
        }],
      },
    };
  },
  methods: {
    currentTabMethod(tabs, remarks) {
      this.tabs = tabs;
      this.formValidate.remark = remarks;
    },
    cancel(name) {
      this.modals = false;
      this.$refs[name].resetFields();
    },
    putRemark(name) {
      const data = {
        id: this.orderId,
        remark: this.formValidate,
      };
      this.$refs[name].validate((valid) => {
        if (!valid) {
          this.$Message.warning('请填写备注信息');
          return;
        }
        let request;
        if (this.currentTab == 5) {
          request = postChexiao(data.id, { remarks: this.formValidate.remark });
        } else if (this.tabs == 3 || this.tab == 3) {
          request = putRechargeRemarkData(data);
        } else if (this.tabs == 4 || this.tab == 4) {
          request = putVipRemarkData(data);
        } else {
          request = putRemarkData(data);
        }
        request.then((res) => {
          this.$Message.success(res.msg);
          this.modals = false;
          this.$refs[name].resetFields();
          this.$emit('submitFail');
        }).catch((res) => {
          this.$Message.error(res.msg);
        });
      });
    },
  },
};
</script>

<style scoped lang="stylus">
</style>
