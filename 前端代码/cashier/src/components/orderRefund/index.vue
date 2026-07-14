<template>
  <div>
    <div>
      <Form :model="formItem" :label-width="100">
		<FormItem label="卡项情况：" v-if="benefitsInfo && benefitsInfo.product_type == 5">
			<Card dis-hover>
			  <div slot="title" class="acea-row row-between-wrapper">
			    <div class="flex-1">{{ benefitsInfo.card_name }}</div>
			    <div v-if="benefitsInfo.write_valid == 1">永久有效</div>
			    <div v-else-if="benefitsInfo.write_valid == 2">购买后{{ benefitsInfo.write_days }}天有效</div>
			    <div v-else-if="benefitsInfo.write_valid == 3">{{ benefitsInfo.write_start | timeFormat }} - {{ benefitsInfo.write_end | timeFormat }}</div>
			  </div>
			  <div class="flex flex-wrap">
			    <div class="flex-33">购卡实付金额：￥{{ benefitsInfo.pay_price }}</div>
			    <div class="flex-33">数量：1</div>
			    <div class="flex-33">剩余金额：￥{{ benefitsInfo.remaining_price }}</div>
			    <div class="flex-33">已消耗：{{ benefitsInfo.write_times - benefitsInfo.write_surplus_times }}/{{ benefitsInfo.write_times }}</div>
			    <div class="flex-33">
			      卡项权益：
			      <Poptip placement="bottom" width="300">
			        <div class="pointer text-wlll-1890FF">查看</div>
			        <div slot="content">
			          <div v-for="item in benefitsInfo.cart_info" :key="item.id" class="acea-row row-between-wrapper pt-4 pb-4 fs-12">
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
			  <div slot="title" class="acea-row row-between-wrapper">
			    <div class="flex-1">{{ benefitsInfo.card_name }}</div>
			    <div v-if="benefitsInfo.write_valid == 1">永久有效</div>
			    <div v-else-if="benefitsInfo.write_valid == 2">购买后{{ benefitsInfo.write_days }}天有效</div>
			    <div v-else-if="benefitsInfo.write_valid == 3">{{ benefitsInfo.write_start | timeFormat }} - {{ benefitsInfo.write_end | timeFormat }}</div>
			  </div>
			  <div class="flex flex-wrap">
			    <div class="flex-33">购卡实付金额：￥{{ benefitsInfo.pay_price }}</div>
			    <div class="flex-33">总次数：{{ benefitsInfo.write_times }}</div>
			  <div class="flex-33">已消耗次数：{{ benefitsInfo.write_times - benefitsInfo.write_surplus_times }}</div>
			    <div class="flex-33">剩余次数：{{ benefitsInfo.write_surplus_times }}</div>
			  <div class="flex-33">剩余金额：￥{{ benefitsInfo.remaining_price }}</div>
			  </div>
			</Card>
		</FormItem>
        <FormItem label="退款单号：">
          <Input v-model="formItem.order_id" size="large" disabled placeholder="" style="width: 100%"></Input>
        </FormItem>
        <FormItem label="退款金额：">
          <InputNumber v-model="formItem.refund_price" size="large"  :min="0" placeholder="请输入退款金额" style="width: 100%"></InputNumber>
        </FormItem>
		<FormItem label="售后入库：">
			<RadioGroup v-model="formItem.stock_in_type">
			  <Radio :label="0">暂不入库</Radio>
			  <Radio :label="1">入良品库</Radio>
			  <Radio :label="2">入残次品库</Radio>
			</RadioGroup>
			<div class="tips">选择售后商品是否需要执行入库操作，若需存入不同仓库，请于入库管理模块中操作退货入库。</div>
		</FormItem>
      </Form>
      <div class="footer">
      <Button @click="sub" type="primary" size="large" long>提交</Button>
      </div>
    </div>
  </div>
</template>

<script>
import dayjs from "dayjs";
export default {
  name: "orderRefund",
  props: {
	selectData:{
		type: Object,
		default: {},
	},
  	benefitsInfo:{
  		type: Object,
  		default: {},
  	}
  },
  filters: {
    timeFormat: (value) => dayjs(value * 1000).format("YYYY-MM-DD HH:mm"),
  },
  data(){
    return{
      formItem:{
        order_id:'',
        refund_price:'',
        type:1,
		stock_in_type:0
      }
    }
  },
  created() {
    console.log(this.selectData.refund_price)
    this.formItem.order_id = this.selectData.order_id
    this.formItem.refund_price = Number(this.selectData.refund_price) || 0
  },
  methods:{
    sub(){
      this.$emit('refund',this.formItem)
    },
    clear(){
      this.$emit('clear')
    },
  }
}
</script>

<style lang="stylus" scoped>
.tips{
	padding: 5px 0 23px;
	font-size: 12px;
	line-height: 18px;
	color: #999;
}
.footer{
  display: flex;
  justify-content center
  margin-top: 40px;
  .btn{
    padding: 14px 68px;
    font-size: 20px;
    margin: 0 20px;
    border-radius: 6px;
    cursor pointer
  }
  .clear{
    background-color #F5F5F5
    color: #666;
  }
  .sub{
    color: #fff;
    background: #1890FF;
  }
}
</style>
