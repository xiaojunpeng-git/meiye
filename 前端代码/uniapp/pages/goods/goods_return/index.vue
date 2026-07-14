<template>
	<view :style="colorStyle">
		<form @submit="subRefund">
			<view class='apply-return'>
				<view class="return">
					<view class='goodsStyle acea-row row-between' v-for="(item,index) in refundCartInfo" :key="index">
						<view class='pictrue'>
							<image class="w-136 h-136 rd-16rpx" :src="item.productInfo.image"></image>
						</view>
						<view class='text'>
							<view class="nameCon">
								<view class='name line2'>{{item.productInfo.store_name}}</view>
							</view>
							<view class="num">购买数量：{{item.cart_num}}</view>
						</view>
					</view>
					<view class="tips acea-row row-middle" v-if="refundNum">
						<text class="title">售后退款</text>
						<text>有{{refundCartInfo.length}}个商品已申请售后</text>
					</view>
				</view>
				<view class='list'>
					<view class='item acea-row row-between-wrapper'>
						<view>退款金额</view>
						<text class="text--w111-333 fs-36 Regular font-color"> -￥{{refund_price}}</text>
					</view>
					<view class='item acea-row row-between-wrapper'>
						<view>售后类型</view>
						<view v-if="status && status._type !== 1 && !productType" class="picker acea-row row-between-wrapper" @click="returnTypeTap">
							<view class='reason'>{{returnType}}</view>
							<text class='iconfont icon-ic_rightarrow'></text>
						</view>
						<view v-else class="num">仅退款(无需退货)</view>
					</view>
					<view class='item acea-row row-between-wrapper'>
						<view>退货件数</view>
						<view class='num' v-if="refundCartInfo.length !== 1 || refund_total_num == 1">
							{{refund_total_num}}
						</view>
						<picker v-else class='num' @change="returnGoodsNum" :value="refund_num_index" :range="refundNumData">
							<view class="picker acea-row row-between-wrapper">
								<view class='reason'>{{refundNumData[refund_num_index]}}</view>
								<text class='iconfont icon-ic_rightarrow'></text>
							</view>
						</picker>
					</view>
					<view class='item acea-row row-between-wrapper' v-if="refundReason.RefundArray.length">
						<view>申请原因</view>
						<view class="num" @click="reasonTap">
							<view class="picker acea-row row-between-wrapper">
								<view class='reason'>{{refundItem}}</view>
								<text class='iconfont icon-ic_rightarrow'></text>
							</view>
						</view>
					</view>
				</view>
				<view class="list">
					<view class='item textarea'>
						<view class="acea-row row-between-wrapper">
							<view>申请说明</view>
							<view class="fontNum">{{fontNum}}/100</view>
						</view>
						<textarea placeholder='请填写备注说明' placeholder-class="placeholder" maxlength=100 name="refund_reason_wap_explain" @input="sumfontnum"></textarea>
					</view>
					<view class='item acea-row row-between'>
						<view class='upload acea-row row-middle'>
							<view class='pictrue' v-for="(item,index) in refund_reason_wap_img" :key="index">
								<image :src='item' mode="aspectFill"></image>
								<view class='iconfont icon-ic_close' @tap='DelPic(index)'></view>
							</view>
							<view class='pictrue acea-row row-center-wrapper row-column' @tap='uploadpic'
								v-if="refund_reason_wap_img.length < 3">
								<image class="img" src="../static/ic_camera.png"></image>
								<view>上传凭证</view>
							</view>
						</view>
					</view>
				</view>
				<view style="height: 140rpx;"></view>
				<view class="bntCon flex-center fixed-lb pb-safe">
					<button class='returnBnt bg-color' form-type="submit">申请退款</button>
				</view>
			</view>
		</form>
		<refundPopup :refundData='refundReason' @changeClose='changeClose' @selectInfo='selectInfo'></refundPopup>
		<refundPopup :refundData='returnGoods' @changeClose='changeGoodsClose' @selectInfo='selectGoodsInfo'></refundPopup>
		<!-- #ifdef MP -->
		<authorize v-if="isShowAuth" @authColse="authColse" @onLoadFun="onLoadFun"></authorize>
		<!-- #endif -->
	</view>
</template>
<script>
	import {
		ordeRefundReason,
		getOrderDetail,
		returnGoodsSubmit,
		postRefundGoods
	} from '@/api/order.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import refundPopup from '../components/refundPopup/index.vue'
	import colors from '@/mixins/color.js';
	export default {
		components: {
			refundPopup
		},
		mixins: [colors],
		data() {
			return {
				id:0,
				cartIds:[],
				refund_reason_wap_img: [],
				status: {},
				refundCartInfo: [],
				refund_total_num: 0,
				orderId: 0,
				refundNumData: [],
				refund_num_index: 0,
				productType:0,
				isShowAuth: false,
				refund_price:0,
				fontNum:0,
				refundNum:0,
				refundItem:'',
				refundReason:{
				   RefundArray: [],
				   show:false
				},
				returnType:'',
				returnGoods:{
					RefundArray: ['仅退款(无需退货)', '退货退款(快递退回)','退货退款(到店退货)'],
					show:false
				},
				returnGoodsIndex:0,
				delivery_type:0
			};
		},
		computed: {
			...mapGetters(['isLogin']),
			inputWid() {
				return function(value) {
					if (value == '') {
						return '300rpx';
					} else {
						console.log(String(value).length)
						return String(value).length * 16 + 'rpx';
					}
				};
			}
		},
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						//#ifndef MP
						this.refundGoodsInfo();
						this.getRefundReason();
						//#endif
					}
				},
				deep: true
			}
		},
		onLoad(options) {
			this.returnType = this.returnGoods.RefundArray[0];
			this.orderId = options.orderId;
			this.id = options.id;
			this.store_id = options.store_id;
			this.productType = parseInt(options.productType) || 0;
			if(options.cartIds){
				this.cartIds = JSON.parse(options.cartIds) || []
			}
			if (options.store_id == 0) {
				this.returnGoods.RefundArray.pop()
			}
			if (this.isLogin) {
				this.refundGoodsInfo();
				this.getRefundReason();
			} else {
				toLogin()
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		methods: {
			onLoadFun(){
				this.refundGoodsInfo();
				this.getRefundReason();
				this.isShowAuth = false
			},
			// 授权关闭
			authColse: function(e) {
			  this.isShowAuth = e
			},
			reasonTap(){
				this.refundReason.show = true;
			},
			changeClose(){
				this.refundReason.show = false;
			},
			selectInfo(e){
				this.refundItem = this.refundReason.RefundArray[e];
				this.changeClose();
			},
			returnTypeTap(){
				this.returnGoods.show = true;
			},
			changeGoodsClose(){
				this.returnGoods.show = false;
			},
			selectGoodsInfo(e){
				this.returnGoodsIndex = e;
				this.returnType = this.returnGoods.RefundArray[e];
				this.changeGoodsClose();
			},
			// 限制文本框字数
			sumfontnum(e) {
				this.fontNum = e.detail.value.length
			},
			refundGoodsInfo(){
				postRefundGoods({id:this.id,cart_ids:this.cartIds}).then(res=>{
					let data = res.data;
					this.status = data._status;
					this.refundCartInfo = data.cartInfo;
					this.refundNum = data.refund_num;
					let refundPrice = 0
					this.refundCartInfo.forEach(item=>{
						this.refund_total_num = this.$util.$h.Add(this.refund_total_num, item.cart_num);
						let price = this.$util.$h.Add(refundPrice, this.$util.$h.Mul(item.cart_num,item.truePrice));
						refundPrice = price
					})
					
					if(this.refundCartInfo.length !== 1 || this.refund_total_num == 1){
						this.refund_price = refundPrice;
					}else{
						let truePrice = this.refundCartInfo[0].truePrice;
						this.refund_price = truePrice;
					}
					this.refundNumData = Array(this.refund_total_num).fill(0).map((e, i) => i + 1)
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					});
				})
			},
			/**
			 * 获取退款理由
			 */
			getRefundReason: function() {
				let that = this;
				ordeRefundReason().then(res => {
					that.$set(that.refundReason, 'RefundArray', res.data);
					this.refundItem = res.data[0];
				})
			},

			/**
			 * 删除图片
			 * 
			 */
			DelPic: function(e) {
				let index = e,
					that = this,
					pic = this.refund_reason_wap_img[index];
				that.refund_reason_wap_img.splice(index, 1);
				that.$set(that, 'refund_reason_wap_img', that.refund_reason_wap_img);
			},

			/**
			 * 上传文件
			 * 
			 */
			uploadpic: function() {
				let that = this;
				this.$util.uploadImageOne('upload/image', function(res) {
					that.refund_reason_wap_img.push(res.data.url);
					that.$set(that, 'refund_reason_wap_img', that.refund_reason_wap_img);
				});
			},

			/**
			 * 申请退货
			 */
			subRefund: function(e) {
				let that = this,
					value = e.detail.value;
				let cartInfo = this.refundCartInfo;
				if(cartInfo.length === 1){
					this.cartIds = [
						{
							cart_id:cartInfo[0].id,
							cart_num: this.refund_num_index + 1
						}
					]
				}
				returnGoodsSubmit(this.id, {
					text: that.refundItem || '',
					refund_reason_wap_explain: value.refund_reason_wap_explain,
					refund_reason_wap_img: that.refund_reason_wap_img.join(','),
					refund_type: parseInt(this.returnGoodsIndex)+1,
					uni: that.orderId,
					cart_ids: this.cartIds,
					refund_price: this.refund_price
				}).then(res => {
					return this.$util.Tips({
						title: '申请成功',
						icon: 'success'
					}, {
						tab: 5,
						url: '/pages/users/user_return_list/index?isT=1'
					});
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				})
			},
			returnGoodsNum(e) {
				let num = Number(e.detail.value);
				this.$set(this, 'refund_num_index', num);
				let price = this.$util.$h.Mul(this.refundNumData[num],this.refundCartInfo[0].truePrice);
				this.refund_price = price;
			}
		}
	}
</script>

<style scoped lang="scss">
	.apply-return{
		.return{
			width: 710rpx;
			background: #FFFFFF;
			border-radius: 24rpx;
			margin: 20rpx auto 0 auto;
			padding-bottom: 32rpx;
			.tips{
				width: 662rpx;
				height: 72rpx;
				background: #F5F5F5;
				border-radius: 8rpx;
				margin: 32rpx auto 0 auto;
				padding: 0 20rpx;
				color: #999999;
				font-size: 26rpx;
				.title{
					color: #333333;
					margin-right: 20rpx;
				}
			}
		}
		.goodsStyle{
			padding: 32rpx 24rpx 0 24rpx;
			border-radius: 24rpx;
			.pictrue{
				width: 136rpx;
				height: 136rpx;
				border-radius: 16rpx;
				image{
					border-radius: 16rpx;
				}
			}
			.text{
				width: 504rpx;
				font-size: 24rpx;
				font-weight: 400;
				.nameCon{
					height: 104rpx;
					.name{
						font-size: 28rpx;
						color: #333;
					}
				}
				.num{
					color: #999999;
				}
			}
		}
	}
	.apply-return .list {
		background-color: #fff;
		width: 710rpx;
		border-radius: 16rpx;
		margin: 20rpx auto 0 auto;
		padding: 1rpx 24rpx 32rpx 24rpx;
	}

	.apply-return .list .item {
		font-size: 28rpx;
		color: #333;
		margin-top: 32rpx;
		.fontNum{
			font-size: 24rpx;
			color: #999;
		}
		.placeholder{
			font-size: 26rpx;
			color: #CCCCCC;
		}
	}

	.apply-return .list .item .num {
		color: #282828;
		width: 427rpx;
		text-align: right;
		
		.iconfont{
			color: #999;
			font-size: 30rpx;
			margin-left: 12rpx;
		}
		
		.label{
			color: var(--view-theme);
			font-size: 32rpx;
		}
	}

	.apply-return .list .item .num .picker .reason {
		width: 385rpx;
	}

	.apply-return .list .item .num .picker .iconfont {
		color: #999;
		font-size: 30rpx;
		margin-top: 2rpx;
	}

	.apply-return .list .item textarea {
		height: 166rpx;
		margin-top: 24rpx;
		font-size: 26rpx;
	}

	.apply-return .list .item .placeholder {
		color: #bbb;
	}

	.apply-return .list .item .title {
		height: 95rpx;
		width: 100%;
	}

	.apply-return .list .item .title .tip {
		font-size: 30rpx;
		color: #bbb;
	}

	.apply-return .list .item .upload .pictrue {
		margin: 22rpx 23rpx 0 0;
		width: 156rpx;
		height: 156rpx;
		position: relative;
		font-size: 24rpx;
		color: #bbb;
		border-radius: 16rpx;
		.img{
			width: 48rpx;
			height: 48rpx;
			margin-bottom: 8rpx;
		}
	}

	.apply-return .list .item .upload .pictrue:nth-of-type(4n) {
		margin-right: 0;
	}

	.apply-return .list .item .upload .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 16rpx;
	}

	.apply-return .list .item .upload .pictrue .icon-ic_close {
		position: absolute;
		font-size: 24rpx;
		top: 0;
		right: 0;
		width: 32rpx;
		height: 32rpx;
		background: #999999;
		border-radius: 0 16rpx 0 16rpx;
		text-align: center;
		line-height: 32rpx;
		color: #fff;
	}

	.apply-return .list .item .upload .pictrue .icon-icon25201 {
		color: #bfbfbf;
		font-size: 50rpx;
	}

	.apply-return .list .item .upload .pictrue:nth-last-child(1) {
		width: 148rpx;
		height: 148rpx;
		background: #F5F5F5;
		font-size: 24rpx;
		color: #333;
	}
	
	.apply-return .bntCon{
		width: 100%;
		height: 120rpx;
		background: #FFFFFF;
	}

	.apply-return .returnBnt {
		font-size: 28rpx;
		color: #fff;
		width: 710rpx;
		height: 80rpx;
		border-radius: 50rpx;
		text-align: center;
		line-height: 80rpx;
	}

	.goodsStyle .text .name {
		align-self: flex-start;
	}

	.list /deep/ .uni-input-input {
		text-align: right;
		color: var(--view-theme);
		font-weight: 400;
		font-size: 36rpx;
		max-width: 300rpx;
		font-family: 'Regular';
	}
	.Regular{
		font-family: 'Regular';
	}
</style>
