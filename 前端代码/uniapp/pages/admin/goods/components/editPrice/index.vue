<template>
	<base-drawer mode="bottom" :visible="visible" background-color="transparent" mask maskClosable @close="closeDrawer">
		<view class="edit-price rd-t-40rpx" v-if="goodsInfo.attr_value">
			<view class="title">{{storeNum?'修改价格/库存':goodsInfo.spec_type?'批量修改价格':'修改价格'}}
			  <view class="close acea-row row-center-wrapper" @tap="closeDrawer">
				  <text class="iconfont icon-ic_close"></text>
			  </view>
			</view>
			<view class="list">
				<view class="item acea-row row-between-wrapper">
					<view>售价</view>
					<input type="digit" :placeholder="'请填写售价'+tips" placeholder-class="placeholder" v-model="goodsInfo.attr_value.price" />
				</view>
				<view class="item acea-row row-between-wrapper">
					<view>成本价</view>
					<input type="digit" :placeholder="'请填写成本价'+tips" placeholder-class="placeholder" v-model="goodsInfo.attr_value.cost" />
				</view>
				<view class="item acea-row row-between-wrapper">
					<view>划线价</view>
					<input type="digit" :placeholder="'请填写划线价'+tips" placeholder-class="placeholder" v-model="goodsInfo.attr_value.ot_price" />
				</view>
				<view v-if="storeNum" class="item acea-row row-between-wrapper">
					<view>库存</view>
					<view v-if="goodsInfo.product_type == 1" class="pr-20 text--w111-999">暂不支持，请于管理后台修改</view>
					<input v-else type="number" :placeholder="'请填写库存'+tips" placeholder-class="placeholder" v-model="goodsInfo.attr_value.stock" />
				</view>
			</view>
			<view v-if="goodsInfo.spec_type" class="bnt acea-row row-center-wrapper" @tap="defineSpec">确定</view>
			<view v-else class="bnt acea-row row-center-wrapper" @tap="define">保存</view>
		</view>
	</base-drawer>
</template>

<script>
import {
	postUpdateAttrs,
	postStoreUpdateAttrs
} from "@/api/admin";
export default {
	props:{
		visible: {
		    type: Boolean,
		    default: false,
		},
		goodsInfo: {
			type: Object,
			default: () => {}
		},
		storeNum: {
			type: Number,
			default: 0
		}
	},
	data: function() {
	  return {
		  tips:''
	  };
	},
	mounted() {
		this.tips = this.goodsInfo.spec_type?'(可为空)':''
	},
	methods:{
		defineSpec(){
			let info = this.goodsInfo.attr_value;
			let obj = info.cost || info.price || info.ot_price;
			if((this.storeNum && (obj || info.stock)) || (!this.storeNum && obj)){
				this.$emit('successChange',info);
			}else{
				this.$util.Tips({
					title: '修改类容至少填写一项'
				});
			}
		},
		define(){
			let data = {
				attr_value:[]
			}
			if(!this.storeNum){
				delete this.goodsInfo.attr_value.stock;
				data.type = 'price';
			}
			data.attr_value.push(this.goodsInfo.attr_value);
			let funApi = '';
			if(this.storeNum){
				funApi = postUpdateAttrs;
			}else{
				funApi = postStoreUpdateAttrs;
			}
			funApi(this.goodsInfo.id,data).then(res=>{
				this.$util.Tips({
					title: res.msg
				});
				this.$emit('successChange');
			}).catch(err=>{
				this.$util.Tips({
					title: err
				});
			})
		},
		closeDrawer() {
		  this.$emit('closeDrawer');
		}
	}
}
</script>

<style lang="scss" scoped>
	.edit-price{
		background-color: #fff;
		padding-bottom: 60rpx;
		.title{
			text-align: center;
			height: 108rpx;
			line-height: 108rpx;
			font-size: 32rpx;
			font-family: PingFang SC, PingFang SC;
			font-weight: 600;
			color: #333333;
			position: relative;
			padding: 0 30rpx;
			.close{
				width: 36rpx;
				height: 36rpx;
				line-height: 36rpx;
				background: #EEEEEE;
				border-radius: 50%;
				position: absolute;
				right: 30rpx;
				top:38rpx;
				.iconfont {
					font-weight: 300;
					font-size: 20rpx;
				}
			}
		}
		.list{
			padding: 0 10rpx 0 30rpx;
			.item{
				font-size: 28rpx;
				font-family: PingFang SC, PingFang SC;
				font-weight: 400;
				color: #333333;
				height: 72rpx;
				margin-bottom: 32rpx;
				box-sizing: border-box;
				input {
					text-align: right;
					font-size: 28rpx;
					height: 100%;
					padding-right: 20rpx;
				}
				.placeholder{
					font-size: 28rpx;
					padding-right: 20rpx;
				}
			}
		}
		.bnt{
			font-size: 26rpx;
			font-family: PingFang SC, PingFang SC;
			font-weight: 500;
			color: #FFFFFF;
			width: 710rpx;
			height: 72rpx;
			background: #2A7EFB;
			border-radius: 50rpx;
			margin: 72rpx auto 0 auto;
		}
	}
</style>