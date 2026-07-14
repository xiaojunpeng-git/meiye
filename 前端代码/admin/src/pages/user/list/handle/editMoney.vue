<template>
  <Modal :value="visible" title="修改余额" width="680" @on-cancel="clear">
    <div class="infoData" v-if="userInfo && userInfo.uid">
      <div class="pictrue">
        <img :src="userInfo.avatar" />
      </div>
      <div class="info">
        <div class="attr">
          <div v-if="userInfo.phone" class="item phone">{{ userInfo.phone }}</div>
          <div class="item">余额<span class="num">{{ userInfo.now_money }}</span></div>
          <div class="item">本金<span class="num">{{ userInfo.ben_money }}</span></div>
          <div class="item">赠金<span class="num">{{ userInfo.give_money }}</span></div>
        </div>
      </div>
    </div>
    <Form :label-width="90">
      <FormItem label="修改类型：">
        <RadioGroup v-model="changeForm.type">
          <Radio :label="1">增加</Radio>
          <Radio :label="2">减少</Radio>
        </RadioGroup>
      </FormItem>
      <FormItem label="修改本金：">
        <InputNumber v-model="changeForm.ben_money" :min="0" :max="9999999" placeholder="0.00" style="width: 100%" />
      </FormItem>
      <FormItem label="修改赠金：">
        <InputNumber v-model="changeForm.give_money" :min="0" :max="9999999" placeholder="0.00" style="width: 100%" />
      </FormItem>
      <FormItem label="备注：">
        <Input v-model="changeForm.mark" placeholder="请输入备注内容" />
      </FormItem>
    </Form>
    <div slot="footer">
      <Button @click="clear">取消</Button>
      <Button type="primary" @click="save">提交</Button>
    </div>
  </Modal>
</template>

<script>
import { changeMoneyApi } from '@/api/user';

export default {
  name: 'editMoney',
  props: {
    visible: {
      type: Boolean,
      default: false,
    },
    userInfo: {
      type: Object,
      default: () => ({}),
    },
  },
  data() {
    return {
      changeForm: {
        ben_money: 0,
        give_money: 0,
        type: 1,
        uid: 0,
        mark: '',
      },
    };
  },
  methods: {
    save() {
      this.changeForm.uid = this.userInfo.uid;
      changeMoneyApi(this.changeForm)
        .then((res) => {
          this.$Message.success(res.msg || '修改成功');
          this.$emit('changeSuccess', this.userInfo.uid);
          this.clear();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    clear() {
      this.changeForm = {
        ben_money: 0,
        give_money: 0,
        type: 1,
        uid: 0,
        mark: '',
      };
      this.$emit('close', false);
    },
  },
};
</script>

<style lang="less" scoped>
.infoData {
  display: flex;
  align-items: center;
  margin-bottom: 24px;

  .pictrue {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    overflow: hidden;

    img {
      width: 100%;
      height: 100%;
      border-radius: 50%;
    }
  }

  .info {
    flex: 1;
    margin-left: 14px;
  }

  .attr {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    font-size: 13px;
    color: #303133;
  }

  .item {
    margin-right: 20px;
    margin-bottom: 6px;
  }

  .num {
    margin-left: 4px;
    font-weight: 600;
    font-size: 16px;
    color: #f5222d;
  }
}
</style>
