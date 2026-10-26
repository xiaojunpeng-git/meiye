<template>
  <div>
    <Modal v-model="modals" width="700" scrollable footer-hide closable title="加盟门店申请审核" :z-index="2"
      @on-cancel="handleReset" class-name="vertical-center-modal">
      <Form ref="formValidate" :model="formValidate" :label-width="110" @submit.native.prevent>
        <FormItem label="审核状态：">
          <RadioGroup
              v-model="formValidate.status"
          >
            <Radio :label="1">通过</Radio>
            <Radio :label="2">拒绝</Radio>
          </RadioGroup>
        </FormItem>
		<FormItem label="拒绝原因：" prop="fail_msg" v-if="formValidate.status==2">
		  <Input v-model="formValidate.fail_msg" placeholder="请填写拒绝原因" class="w-420"></Input>
		</FormItem>
		<div v-if="formValidate.status==1">
			<FormItem label="同步商品：">
			  <RadioGroup
			      v-model="formValidate.applicable_type"
			  >
			    <Radio :label="1">全部同步</Radio>
			    <Radio :label="2">指定商品</Radio>
				<Radio :label="3">暂不同步</Radio>
			  </RadioGroup>
			</FormItem>
			<FormItem label="选择商品：" label-for="product_id" prop="" v-if="formValidate.applicable_type==2">
				<div class="box">
				  <div class="box-item" v-for="(item,index) in goodsList" :key="index">
					<img :src="item.image" alt="">
					<Icon class="icon" type="ios-close-circle" size="20" @click="bindDelete(index)" />
				  </div>
				  <div class="upload-box" @click="goodsModals = true"><Icon type="ios-camera-outline" size="36" /></div>
				</div>
			</FormItem>
			<FormItem label="自主添加商品：">
			  <Switch v-model="formValidate.product_status" size="large" :true-value="1" :false-value="0">
			    <span slot="open">开启</span>
			    <span slot="close">关闭</span>
			  </Switch>
			</FormItem>
			<FormItem label="商品免审：">
			  <Switch v-model="formValidate.product_verify_status" size="large" :true-value="1" :false-value="0">
			    <span slot="open">开启</span>
			    <span slot="close">关闭</span>
			  </Switch>
			</FormItem>
			<FormItem label="使用平台余额：">
			  <Switch v-model="formValidate.use_system_money" size="large" :true-value="1" :false-value="0">
			    <span slot="open">开启</span>
			    <span slot="close">关闭</span>
			  </Switch>
			</FormItem>
			<FormItem label="门店调价：">
			  <Switch v-model="formValidate.product_change_price_status" size="large" :true-value="1" :false-value="0">
			    <span slot="open">开启</span>
			    <span slot="close">关闭</span>
			  </Switch>
			  <div class="tips">开启门店调价功能，支持在指定价格区间内，调整商品售价</div>
			</FormItem>
			<FormItem label="门店隔离：">
			  <Switch v-model="formValidate.is_alone" size="large" :true-value="1" :false-value="0">
			    <span slot="open">开启</span>
			    <span slot="close">关闭</span>
			  </Switch>
			  <div class="tips">开启门店隔离，用户无法从该门店切换至其他门店；商城所有门店列表不显示该门店。请谨慎开启</div>
			</FormItem>
			<FormItem label="自建商品分类：">
			  <Switch v-model="formValidate.product_category_status" size="large" :true-value="1" :false-value="0">
			    <span slot="open">开启</span>
			    <span slot="close">关闭</span>
			  </Switch>
			  <div class="tips">开启后，门店可自建商品分类</div>
			</FormItem>
		</div>
        <div class="acea-row row-right">
		  <Button class="mr14" type="default" @click="cancle">取消</Button>
		  <Button type="primary" @click="handleSubmit('formValidate')">确认</Button>
        </div>
      </Form>
    </Modal>
	<Modal v-model="goodsModals" title="商品列表"  class="paymentFooter" scrollable width="900" :footer-hide="true">
	  <goods-list :chooseType="91" ref="goodslist"  @getProductId="getGoodsId" v-if="goodsModals" :ischeckbox="true" :isLive="true" :storeType="1"></goods-list>
	</Modal>
  </div>
</template>

<script>
import { productBrand, productBrandrev } from "@/api/product";
import { postApplyVerify } from "@/api/store";
import goodsList from '@/components/goodsList'
export default {
  name: "applyFrom",
  components: { goodsList },
  data() {
    return {
	  modals: false,
	  goodsModals:false,
	  goodsList:[],
	  id:0,
      grid: {
        xl: 24,
        lg: 24,
        md: 12,
        sm: 24,
        xs: 24,
      },
	  formValidate: {
		  status: 1,
		  fail_msg: '',
		  applicable_type: 1,
		  product_status: 0,
		  product_verify_status:0,
		  use_system_money:1,
		  product_change_price_status:0,
		  is_alone:0,
		  product_category_status:0,
		  product_id:[]
	  }
    }
  },
  mounted() {},
  methods: {
	//对象数组去重；
	unique(arr) {
	  const res = new Map();
	  return arr.filter((arr) => !res.has(arr.product_id) && res.set(arr.product_id, 1))
	},
	getGoodsId (data) {
	  let list = this.goodsList.concat(data);
	  let uni = this.unique(list);
	  this.goodsList = uni;
	  this.$nextTick(res=>{
	    setTimeout(()=>{
		  this.goodsModals = false
	    },300)
	  })
	},
	bindDelete (index) {
	  this.goodsList.splice(index, 1)
	},
	handleSubmit(name) {
	  this.$refs[name].validate((valid) => {
	    if (valid) {
		  let product_id = []
		  this.goodsList.forEach(item=>{
		    product_id.push(item.product_id)
		  })
		  this.formValidate.product_id = product_id;
		  postApplyVerify(this.id, this.formValidate).then(res => {
		    this.$Message.success(res.msg)
		    this.$parent.getList()
		    this.modals = false
		  }).catch(err => {
		    this.$Message.error(err.msg)
		  })
		} else {
	      this.$Message.error('请输入品牌名称');
	    }
	  })
	
	},
	cancle() {
	  this.modals = false
	},
	
	
	
    
    handleReset() {
      this.modals = false;
      this.$parent.getData()
    }
  }
};
</script>

<style scoped lang="stylus">
	.tips {
	  display: inline-bolck;
	  font-size: 12px;
	  font-weight: 400;
	  color: #999;
	}
	.box{
	  display: flex
	  flex-wrap: wrap
	  .box-item{
	    position: relative
	    margin-right: 20px
	    width: 60px
	    height: 60px
	    margin-bottom: 10px
		
	    img {
	      width: 100%
	      height: 100%
	    }
	    .icon{
	      position: absolute;
	      top:-10px;
	      right: -10px;
	    }
	  }
	  .upload-box{
	    width: 60px
	    height: 60px
	    margin-bottom: 10px
	    display: flex
	    align-items: center
	    justify-content: center
	    background: #ccc
	  }
	}
</style>
