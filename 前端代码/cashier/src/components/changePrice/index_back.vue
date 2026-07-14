<template>
  <Modal
    v-model="priceModals"
    scrollable
    title="订单改价"
	width="800"
    :closable="false"
    :mask-closable="false"
  >
    <div class="acea-row row-middle mb20">
    	<div class="w-85 text-right">改价类型：</div>
    	<RadioGroup v-model="discountType" @on-change="discountChange">
    	  <Radio :label="1">折上折</Radio>
    	  <Radio :label="2">去除优惠</Radio>
    	  <Radio :label="3">整单折扣</Radio>
    	</RadioGroup>
    </div>
	<div v-if="discountType == 3">
		<div class="acea-row row-middle mb20 discountPrice">
			<div class="w-85 text-right">应付金额：</div>
			<Input v-model="priceInfo.totalPrice" type="number" disabled class="flex-1">
				<template #append>
				  <div class="fs-14 text-wlll-909399">元</div>
				</template>
			</Input>
		</div>
		<div class="acea-row row-middle mb20">
			<div class="w-85 text-right">改价：</div>
			<RadioGroup v-model="orderPriceType" @on-change='changeTap(1)'>
			  <Radio :label="1">一口价</Radio>
			  <Radio :label="2">减价</Radio>
			  <Radio :label="3">折扣</Radio>
			</RadioGroup>
		</div>
		<div class="acea-row row-middle mb20">
			<div class="w-85 text-right"></div>
			<Input v-model="discountChangePrice" type="number" class="flex-1" @on-change='changeTap(1)'>
				<template #append>
				  <div class="fs-14 text-wlll-909399">{{orderPriceType==3?'%':'元'}}</div>
				</template>
			</Input>
		</div>
		<div class="acea-row row-middle mb20 discountPrice">
			<div class="w-85 text-right">改价后金额：</div>
			<Input v-model="discountResultPayPrice" type="number" disabled class="flex-1">
				<template #append>
				  <div class="fs-14 text-wlll-909399">元</div>
				</template>
			</Input>
		</div>
	</div>
    <Table v-else :columns="columns" :data="cartInfo" border no-data-text="暂无数据"
           highlight-row no-filtered-data-text="暂无筛选结果" max-height="350">
    	<template slot-scope="{ row, index }" slot="name">
    		<div class="line1">{{row.productInfo.store_name}}</div>
    	</template>
    	<template slot-scope="{ row, index }" slot="price">
    		<div>¥{{row.sum_price}}</div>
    	</template>
    	<template slot-scope="{ row, index }" slot="true_price">
    		<div>¥{{$computes.Mul(row.displayPrice,row.cart_num)}}</div>
    	</template>
    	<template slot-scope="{ row, index }" slot="change_price">
    		<div>
    			<Input v-model="row.changePrice" type="number" @on-change='changeTap(2,row,index)'>
    				<template #prepend>
    				  <Select v-model="row.priceType" transfer class="w-75" @on-change="changeTap(2,row,index)">
    				      <Option :value="1">一口价</Option>
    				      <Option :value="2">减价</Option>
    					  <Option :value="3">折扣</Option>
    				  </Select>
    				</template>
    				<template #append>
    				  <div class="fs-14 text-wlll-909399">{{row.priceType==3?'%':'元'}}</div>
    				</template>
    			</Input>
    		</div>
    	</template>
    	<template slot-scope="{ row, index }" slot="result_price">
    		<div>¥{{row.resultPrice}}</div>
    	</template>
    </Table>
	<div slot="footer">
	  <div v-if="discountType != 3" class="acea-row row-right fs-14 text-wlll-909399 pr-24">
	     <span>应付金额：<span class="text-wlll-303133 fs-16 fw-600">¥{{payPrice}}</span></span>
	     <span class="ml41">改价后金额：<span class="text-wlll-f5222d fs-16 fw-600">¥{{resultPayPrice}}</span></span>
	  </div>
	  <div class="acea-row row-center-wrapper mt22">
		<Button class="w-176 h-46 fs-16 rd-30px bnt-F5F5F5" @click="cancel">取消</Button>
		<Button class="w-176 h-46 fs-16 rd-30px ml20" type="primary" @click="submit">确认</Button>
	  </div>
    </div>
  </Modal>
</template>
<script>
import {
  getTableInfo,
  postTableUpdate
} from '@/api/order';
export default {
  name: "changePrice",
  props: {
	type:{
		type: Number,
		default:0
	}
  },
  data() {
    return {
	  tableInfo:{
		table_id:0,
		uid:0
	  },
	  priceModals: false,
	  columns: [
		 {
		 	title: "商品名称",
		 	slot: "name",
		 	minWidth: 150
		 },
		 {
		 	title: "数量",
		 	key: "cart_num",
		 	minWidth: 70
		 },
		 {
		 	title: "应付金额",
		 	slot: "true_price",
		 	minWidth: 80
		 },
		 {
		 	title: "改价",
		 	slot: "change_price",
		 	minWidth: 200
		 },
		 {
		 	title: "改价后金额",
		 	slot: "result_price",
			align: "right",
		 	minWidth: 90
		 }
	  ],
	  cartInfo:[],
	  payPrice:0, //总价
	  resultPayPrice:0, //修改后总价
	  timeoutId: null, //定时器
	  discountType: 1,
	  priceInfo: {},
	  orderPriceType: 1, //整单折扣类型
	  discountChangePrice: 0, //整单改价
	  discountResultPayPrice: 0, //整单改价后的价格
	  orderInfo:{}, //桌码改价订单详情
	  isTable:0
    };
  },
  computed:{},
  mounted(){},
  methods: {
	discountChange(e){
		if(e==2){
			this.columns[2].title = '售价'
		}else{
			this.columns[2].title = '应付金额'
		}
		this.discountType = e;
		if(this.isTable){
			this.payPrice = this.discountType == 1?this.orderInfo.pay_price:this.orderInfo.total_price;
		}else{
			this.ordeUpdateInfo();
		}
	},
	// 用户未提交订单时获取的方法；（在父级拿去）
	ordeUpdateInfo(){
		this.isTable = 0;
		let resultPayPrice = 0;
		let cartInfo = [];
		this.cartInfo.forEach(item=>{
			if(!item.is_gift){
				item.priceType = 1;
				let price = this.discountType == 1?item.truePrice:item.sum_price;
				item.displayPrice = parseFloat(price);
				item.changePrice = this.$computes.Mul(price,item.cart_num);
				item.resultPrice = item.changePrice;
				resultPayPrice = this.$computes.Add(resultPayPrice,item.resultPrice);
				cartInfo.push(item);
			}
		})
		this.cartInfo = cartInfo;
		this.resultPayPrice = resultPayPrice;
		this.payPrice = this.discountType == 1?this.priceInfo.totalPrice:this.priceInfo.sumPrice;
		this.discountChangePrice = this.priceInfo.totalPrice;
		this.discountResultPayPrice = this.priceInfo.totalPrice;
	},
	// 已经提交订单后获取的信息（桌码页面）
	ordeTableUpdateInfo(data){
		getTableInfo(data).then(res=>{
			this.isTable = 1;
			let resultPayPrice = 0;
			let cartInfo = [];
			res.data.cartInfo.forEach(item=>{
				if(!item.is_gift){
					item.priceType = 1;
					let price = this.discountType == 1?item.truePrice:item.total_price;
					item.displayPrice = parseFloat(price);
					// item.truePrice = parseFloat(item.truePrice);
					item.changePrice = this.$computes.Mul(price,item.cart_num);
					item.resultPrice = item.changePrice;
					resultPayPrice = this.$computes.Add(resultPayPrice,item.resultPrice);
					cartInfo.push(item);
				}
			})
			this.cartInfo = cartInfo;
			let orderInfo = res.data.orderInfo;
			this.orderInfo = orderInfo;
			this.payPrice = this.discountType == 1?orderInfo.pay_price:orderInfo.total_price;
			this.resultPayPrice = resultPayPrice;
			this.priceInfo.totalPrice = orderInfo.pay_price;
			this.discountChangePrice = orderInfo.pay_price;
			this.discountResultPayPrice = orderInfo.pay_price;
		})
	},
	// 计算改价
	changeTap(num,row,index){
		let that = this;
		let item = row;
		if(num==1){
			item = {
				changePrice: this.discountChangePrice,
				priceType: this.orderPriceType,
				resultPrice: this.discountResultPayPrice,
				displayPrice: this.priceInfo.totalPrice
			}
		}else{
			item = row;
		}
		if(item.changePrice < 0){
			clearTimeout(that.timeoutId)
			that.timeoutId = setTimeout(function(){
				item.changePrice = 0;
			})
		}
		if(item.priceType == 1){
			let money = this.$computes.Mul(item.changePrice,1); //乘一为了保留2为小数生效；
			item.resultPrice = money>=0?money:0;
		}else if(item.priceType == 2){
			let money = 0;
			if(this.discountType == 3){
				money= that.$computes.Sub(item.displayPrice,item.changePrice);
			}else{
				money= that.$computes.Sub(that.$computes.Mul(item.displayPrice,item.cart_num),item.changePrice);
			}
			item.resultPrice = money>0?money:0;
		}else{
			if(item.changePrice >= 0){
				clearTimeout(that.timeoutId)
				that.timeoutId = null;
				if (item.changePrice >= 100) {
					setTimeout(function(){
						item.changePrice = 100;
					})
				}
			}
			setTimeout(function(){
				let money = 0;
				if(that.discountType == 3){
					money = that.$computes.Mul(item.displayPrice,that.$computes.Div(item.changePrice,100));
				}else{
					money = that.$computes.Mul(that.$computes.Mul(item.displayPrice,item.cart_num),that.$computes.Div(item.changePrice,100));
				}
				item.resultPrice = money>=0?money:item.displayPrice;
			})
		}
		if(num==1){
			setTimeout(function(){
				that.discountChangePrice = item.changePrice;
				that.discountResultPayPrice = item.resultPrice;
			})
		}else{
			setTimeout(function(){
				that.cartInfo[index] = item;
				let resultPayPrice = 0;
				that.cartInfo.forEach(item=>{
					resultPayPrice = that.$computes.Add(resultPayPrice,item.resultPrice);
				})
				that.resultPayPrice = resultPayPrice;
			})
		}
	},
    cancel() {
      this.priceModals = false;
    },
	tableUpdate(data){
		let info = this.tableInfo;
		if(this.discountType == 3){
			info.change_price = this.discountResultPayPrice
		}else{
			info.cartInfo = data;
		}
		postTableUpdate(info).then(res=>{
			this.$Message.success(res.msg);
			this.priceModals = false;
			this.$emit("submitSuccess",this.discountType == 3?this.discountResultPayPrice:this.resultPayPrice);
		}).catch(err=>{
		   this.$Message.error(res.msg);
		})
	},
    submit() {
	  let data = [],nameInfo = '';
	  this.cartInfo.forEach((item,index)=>{
		  if(item.resultPrice<this.$computes.Mul(item.costPrice,item.cart_num)){
			 nameInfo = nameInfo+'商品'+(index+1)+'：'+item.productInfo.store_name+'；'
		  }
		  data.push({
			 id:item.id,
			 true_price:item.resultPrice
		  })
	  })
	  if(this.type){
		if(nameInfo){
			this.$Modal.confirm({
				title: '订单改价',
				content: nameInfo+'改价后金额低于成本价，是否继续改价?',
				onOk: () => {
					this.tableUpdate(data);
				}
			});
		}else{
			this.tableUpdate(data);
		}
	  }else{
		  let info= {}
		  if(this.discountType == 3){
			  info= {
			  	cartInfo:[],
			  	resultPayPrice:this.discountResultPayPrice,
				payPrice:this.priceInfo.totalPrice
			  }
		  }else{
			info= {
				cartInfo:data,
				resultPayPrice:this.resultPayPrice,
				payPrice:this.payPrice
			}
		  }
		  if(nameInfo){
			this.$Modal.confirm({
				title: '订单改价',
				content: nameInfo+'改价后金额低于成本价，是否继续改价?',
				onOk: () => {
					this.priceModals = false;
					this.$emit("submitSuccess",info);
				}
			});
		  }else{
			this.priceModals = false;
			this.$emit("submitSuccess",info);
		  }
	  }
    }
  },
};
</script>

<style scoped>
	.ml20 {
		margin-left: 20px;
	}
	.discountPrice /deep/.ivu-input-group-append{
		background-color: #f3f3f3;
	}
	/deep/.ivu-modal-footer{
		box-shadow: 0px -1px 11px 0px rgba(0,0,0,0.06);
		padding-top: 22px;
		padding-bottom: 22px;
	}
	/deep/.ivu-table{
		border-top-left-radius:4px !important;
		border-top-right-radius:4px !important;
	}
	/deep/.ivu-table-wrapper-with-border{
		border: 0.006472rem solid #DDDDDD;
		border-right:0;
		border-radius: 4px;
	}
	/deep/.ivu-table-header thead tr th{
		padding: 5px 25px !important;
		background-color: #f5f5f5 !important;
		color: #606266 !important;
		font-size: 14px;
		border-bottom: 0;
	}
	/deep/.ivu-modal-body{
		padding: 16px 20px;
	}
	/deep/.ivu-table-border th, /deep/.ivu-table-border td{
		border-right: 0;
	}
	/deep/.ivu-table td{
		border-top:1px solid rgba(216, 216, 216, 0.3) !important;
		border-bottom: 0;
		padding: 8px 25px !important;
	}
	/deep/.ivu-input-group-append{
		background: #fff;
	}
	/deep/.ivu-input-group .ivu-input{
		border-right: 0;
	}
</style>
