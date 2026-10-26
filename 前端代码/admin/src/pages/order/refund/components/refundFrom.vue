<template>
  <Modal
    v-model="modals"
	width="800"
    scrollable
    title="退款处理"
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
	  <FormItem label="卡项情况：" v-if="benefitsInfo && benefitsInfo.product_type == 5">
		  <Card dis-hover>
		    <div slot="title" class="flex-y-center">
		      <div class="flex-1">{{ benefitsInfo.card_name }}</div>
		      <div v-if="benefitsInfo.write_valid == 1">永久有效</div>
		      <div v-else-if="benefitsInfo.write_valid == 2">购买后{{ benefitsInfo.write_days }}天有效</div>
		      <div v-else-if="benefitsInfo.write_valid == 3">{{ benefitsInfo.write_start | timeFormat }} - {{ benefitsInfo.write_end | timeFormat }}</div>
		    </div>
		    <div class="flex flex-wrap">
		      <div class="flex-33">购卡实付金额：￥{{ benefitsInfo.pay_price }}</div>
		      <div class="flex-33">数量：1</div>
		      <div class="flex-33">剩余金额：￥{{ benefitsInfo.remaining_price }}</div>
		      <div class="flex-33">已核销：{{ benefitsInfo.write_times - benefitsInfo.write_surplus_times }}/{{ benefitsInfo.write_times }}</div>
		      <div class="flex-33">
		        卡项权益：
		        <Poptip placement="bottom" width="300">
		          <div class="cup text-wlll-1890FF">查看</div>
		          <div slot="content">
		            <div v-for="item in benefitsInfo.cart_info" :key="item.id" class="flex-y-center pt-4 pb-4 fs-12">
		              <div class="flex-1 min-w-0 pr-8 white-space-normal line2">{{ item.cart_info.productInfo.store_name }}{{item.cart_info.productInfo.attrInfo.suk }}</div>
		              <div>{{ item.write_times }}次（已使用{{ item.write_times - item.write_surplus_times }}次）</div>
		            </div>
		          </div>
		        </Poptip>
		      </div>
		    </div>
		  </Card>
	  </FormItem>
	  <FormItem label="基础信息：" v-if="benefitsInfo && benefitsInfo.product_type == 4">
		  <Card dis-hover>
		    <div slot="title" class="flex-y-center">
		      <div class="flex-1">{{ benefitsInfo.card_name }}</div>
		      <div v-if="benefitsInfo.write_valid == 1">永久有效</div>
		      <div v-else-if="benefitsInfo.write_valid == 2">购买后{{ benefitsInfo.write_days }}天有效</div>
		      <div v-else-if="benefitsInfo.write_valid == 3">{{ benefitsInfo.write_start | timeFormat }} - {{ benefitsInfo.write_end | timeFormat }}</div>
		    </div>
		    <div class="flex flex-wrap">
		      <div class="flex-33">购卡实付金额：￥{{ benefitsInfo.pay_price }}</div>
		      <div class="flex-33">总次数：{{ benefitsInfo.write_times }}</div>
		    <div class="flex-33">已核销次数：{{ benefitsInfo.write_times - benefitsInfo.write_surplus_times }}</div>
		      <div class="flex-33">剩余次数：{{ benefitsInfo.write_surplus_times }}</div>
		    <div class="flex-33">剩余金额：￥{{ benefitsInfo.remaining_price }}</div>
		    </div>
		  </Card>
	  </FormItem>
	  <FormItem label="退款单号：" prop="order_id">
	    <Input
	      v-model="formValidate.order_id"
	      placeholder="退款单号"
	      style="width: 30%"
		  disabled
	    />
	  </FormItem>
	  <FormItem label="退款金额：" prop="refund_price">
	    <InputNumber
	      v-model="formValidate.refund_price"
	    />
		<span class="red">（包含邮费：{{formValidate.pay_postage || 0}}）</span>
	  </FormItem>
	  <FormItem label="售后入库：">
	  	<RadioGroup v-model="formValidate.stock_in_type">
	  	  <Radio :label="0">暂不入库</Radio>
	  	  <Radio :label="1">入良品库</Radio>
	  	  <Radio :label="2">入残次品库</Radio>
	  	</RadioGroup>
	  	<div class="tips">选择售后商品是否需要执行入库操作，若需存入不同仓库，请于入库管理模块中操作退货入库。</div>
	  </FormItem>
    </Form>
    <div slot="footer">
      <Button @click="cancel('formValidate')">取消</Button>
	  <Button type="primary" @click="putRemark('formValidate')">提交</Button>
    </div>
  </Modal>
</template>

<script>
import dayjs from "dayjs";
import { putRefundOrderFrom } from "@/api/order";
export default {
  name: "refundFrom",
  props: {
  	benefitsInfo:{
  		type: Object,
  		default: {},
  	}
  },
  filters: {
    timeFormat: (value) => dayjs(value * 1000).format("YYYY-MM-DD HH:mm"),
  },
  data() {
    return {
      formValidate: {
        order_id: '',
		refund_price:0,
		pay_postage:'',
		id:0,
		stock_in_type:0
      },
      modals: false,
      ruleValidate: {
        refund_price: [
          { required: true, type: 'number', message: "请输入退款金额", trigger: "blur" }
        ],
      },
    };
  },
  methods: {
    cancel(name) {
      this.modals = false;
      this.$refs[name].resetFields();
    },
    putRemark(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          putRefundOrderFrom(this.formValidate)
            .then(async (res) => {
              this.$Message.success(res.msg);
              this.modals = false;
              this.$refs[name].resetFields();
              this.$emit("submitSuccess");
            })
            .catch((res) => {
              this.$Message.error(res.msg);
            });
        } else {
          this.$Message.warning("请输入退款金额");
        }
      });
    },
  },
};
</script>

<style scoped>
	.tips{
		padding: 5px 0 23px;
		font-size: 12px;
		line-height: 18px;
		color: #999;
	}
</style>