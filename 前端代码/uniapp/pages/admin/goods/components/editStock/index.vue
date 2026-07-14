<template>
	<base-drawer mode="bottom" :visible="visible" background-color="transparent" mask maskClosable @close="closeDrawer">
		<view class="edit-price rd-t-40rpx" v-if="goodsInfo.attr_value">
			<view class="title">{{goodsInfo.spec_type?'批量修改库存':'修改库存'}}
			  <view class="close acea-row row-center-wrapper" @tap="closeDrawer">
				  <text class="iconfont icon-ic_close"></text>
			  </view>
			</view>
			<view class="list">
				<view class="item acea-row row-between-wrapper" v-if="!goodsInfo.spec_type">
					<view>剩余库存</view>
					<view class="stock">{{goodsInfo.attr_value.stock}}</view>
				</view>
				<view class="item acea-row row-between-wrapper pr-20">
					<view>修改库存</view>
					<view class="acea-row row-middle">
						<view class="itemn acea-row row-middle" :class="current == index?'on':''" v-for="(item, index) in navList" :key="index" @click="navTap(index)">
							<text class="iconfont" :class="current == index?'icon-ic_Selected':'icon-ic_unselect'"></text>
							<text>{{item}}</text>
						</view>
					</view>
				</view>
				<view class="item acea-row row-between-wrapper">
					<view>库存</view>
					<input type="number" placeholder="请填写库存" placeholder-class="placeholder" v-model="currentStock" @input="stockTap"/>
				</view>
			</view>
			<view v-if="goodsInfo.spec_type" class="bnt acea-row row-center-wrapper" @tap="defineSpec">确定</view>
			<view v-else class="bnt acea-row row-center-wrapper" @tap="define">保存</view>
		</view>
	</base-drawer>
</template>

<script>
import {
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
		}
	},
	data: function() {
	  return {
		  navList:['入库','出库'],
		  current:0,
		  currentStock:0
	  };
	},
	mounted() {
	},
	methods:{
		navTap(index){
			this.current = index
		},
		stockTap(e){
			this.currentStock = parseInt(e.detail.value);
		},
		defineSpec(){
			if(this.currentStock<=0){
				this.$util.Tips({
					title: '请填写库存'
				});
				return
			}
			let data = {
				currentStock:this.currentStock,
				current: this.current
			}
			this.$emit('successChange',data);
		},
		define(){
			if(this.currentStock<=0){
				this.$util.Tips({
					title: '请填写库存'
				});
				return
			}
			let data = {
				attr_value:[]
			}
			let obj = {
				product_id:this.goodsInfo.attr_value.product_id,
				unique:this.goodsInfo.attr_value.unique,
				stock: this.current? parseInt(this.goodsInfo.attr_value.stock)-this.currentStock : this.currentStock +  parseInt(this.goodsInfo.attr_value.stock)
			}
			data.type = 'stock';
			data.attr_value.push(obj)
			postStoreUpdateAttrs(this.goodsInfo.id,data).then(res=>{
				this.$util.Tips({
					title: res.msg
				});
				this.current = 0;
				this.currentStock = 0;
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
				.stock{
					color: #999;
					padding-right: 20rpx;
				}
				.itemn{
					margin-left: 50rpx;
					color: #999999;
					.iconfont {
						margin-top: 4rpx;
						color: #CCCCCC;
						margin-right: 14rpx;
					}
					&.on {
						color: #333333;
						.iconfont {
							color: #2A7EFB;
						}
					}
				}
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