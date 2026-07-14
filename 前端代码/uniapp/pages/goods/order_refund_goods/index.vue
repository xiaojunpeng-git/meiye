<template>
	<view :style="colorStyle">
		<form @submit="subRefund">
			<view class='apply-return'>
				<view class="goodsList">
					<view class='goodsStyle acea-row row-between' v-for="(item,index) in orderInfo.cart_info" :key="index">
						<view class='pictrue'>
							<image class="w-136 h-136 rd-16rpx" :src="item.productInfo.image"></image>
						</view>
						<view class='text'>
							<view class="textCon acea-row row-between">
								<view class='w-346 fs-28 h-80 lh-40rpx line2'>{{item.productInfo.store_name}}</view>
								<view class='money'>
									<baseMoney :money="item.truePrice" color='#333333' symbolSize="20" integerSize="32"
										decimalSize="24" weight></baseMoney>
									<view class='num'>共{{item.cart_num}}件</view>
								</view>
							</view>
							<view class="info line1">{{item.productInfo.attrInfo?item.productInfo.attrInfo.suk:item.productInfo.suk}}</view>
						</view>
					</view>
				</view>
				<view class="list">
					<view class="title acea-row row-between-wrapper">
						<view>{{orderInfo.apply_type == 2?'快递退回信息':'到店退货信息'}}</view>
						<view class="location" v-if="orderInfo.apply_type == 3" @click="showMaoLocation">查看位置<text class="iconfont icon-ic_rightarrow"></text></view>
					</view>
					<view class="textInfo">
						<view class="acea-row row-middle">
							<text class="name">{{orderInfo._status.refund_name}}</text>
							<text class="phone">{{orderInfo._status.refund_phone}}</text>
							<text class="iconfont icon-ic_phone font-num" @click="goTel"></text>
						</view>
						<view class="address">地址：{{orderInfo._status.refund_address}}</view>
					</view>
				</view>
				<view class='list' v-if="orderInfo.apply_type == 2">
					<view class='item acea-row row-between-wrapper' v-if="expressList.length">
						<view>物流公司</view>
						<picker class='num' @change="bindPickerChange" :value="seIndex" :range="expressList" range-key="name">
							<view class="picker acea-row row-between-wrapper">
								<view class='reason'>{{expressList[seIndex].name}}</view>
								<text class='iconfont icon-ic_rightarrow'></text>
							</view>
						</picker>
					</view>
					<view class='item acea-row row-between-wrapper'>
						<view>物流单号</view>
						<input class="inputs" type="text" placeholder="请输入退货的物流单号" placeholder-class='placeholder' v-model="refundInfo.refund_express" />
					</view>
				</view>
				<view class="list">
					<view class='item textarea'>
						<view class="acea-row row-between-wrapper">
							<view>退货说明</view>
							<view class="fontNum">{{fontNum}}/100</view>
						</view>
						<textarea placeholder='选填，请您详细填写备注说明' v-model="refundInfo.refund_explain" placeholder-class="placeholder" maxlength=100 @input="sumfontnum"></textarea>
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
				<view class="bntCon acea-row row-center-wrapper">
					<button class='returnBnt bg-color' form-type="submit">提交</button>
				</view>
			</view>
		</form>
		<!-- #ifdef MP -->
		<authorize v-if="isShowAuth" @authColse="authColse" @onLoadFun="onLoadFun"></authorize>
		<!-- #endif -->
	</view>
</template>
<script>
	import { getRefundOrderDetail, refundExpress } from '@/api/order.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import colors from '@/mixins/color.js';
	export default {
		mixins: [colors],
		data() {
			return {
				expressList:[],
				orderInfo:{},
				seIndex: 0,
				refund_reason_wap_img: [],
				refundInfo:{
					refund_express:'',
					refund_explain:'',
					id:'',
					refund_express_name:'',
					refund_img:''
				},
				isShowAuth: false,
				fontNum:0,
			};
		},
		computed: mapGetters(['isLogin']),
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						this.getOrderInfo();
					}
				},
				deep: true
			}
		},
		onLoad(options) {
			this.orderId = options.orderId;
			if (this.isLogin) {
				this.getOrderInfo();
			} else {
				toLogin()
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		methods: {
			onLoadFun(){
				this.getOrderInfo();
				this.isShowAuth = false;
			},
			// 授权关闭
			authColse: function(e) {
			  this.isShowAuth = e
			},
			// 限制文本框字数
			sumfontnum(e) {
			  this.fontNum = e.detail.value.length
			},
			/**
			 * 打电话
			 */
			goTel: function() {
				uni.makePhoneCall({
					phoneNumber: this.orderInfo._status.refund_phone
				})
			},
			/**
			 * 打开地图
			 * 
			 */
			showMaoLocation: function() {
				let latitude = this.orderInfo._status.latitude;
				let longitude = this.orderInfo._status.longitude;
				if (!latitude || !longitude) return this.$util.Tips({
					title: '缺少经纬度信息无法查看地图！'
				});
				uni.openLocation({
					latitude: parseFloat(latitude),
					longitude: parseFloat(longitude),
					scale: 8,
					name: this.orderInfo._status.refund_name,
					address: this.orderInfo._status.refund_address,
					success: function() {
			
					},
				});
			},
			/**
			 * 申请退货
			 */
			subRefund: function(e) {
				let that = this
				if (!that.refundInfo.refund_express && that.orderInfo.apply_type == 2) return this.$util.Tips({
					title: '请输入快递单号'
				});
				that.refundInfo.refund_express_name = that.expressList[that.seIndex].name;
				that.refundInfo.refund_img = that.refund_reason_wap_img.join(',');
				refundExpress(that.refundInfo).then(res => {
					return this.$util.Tips({
						title: res.msg,
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
			 * 获取订单详情
			 * 
			 */
			getOrderInfo: function() {
				let that = this;
				getRefundOrderDetail(that.orderId).then(res => {
					that.$set(that, 'orderInfo', res.data);
					that.expressList = res.data.express_list;
					that.refundInfo.id = res.data.id;
				});
			},
			bindPickerChange(e) {
				this.$set(this, 'seIndex', e.detail.value);
			}
		}
	}
</script>

<style scoped lang="scss">
	.apply-return .list {
		background-color: #fff;
		width: 710rpx;
		border-radius: 24rpx;
		margin: 20rpx auto 0 auto;
		padding: 32rpx 24rpx;
		.title{
			width: 100%;
			font-weight: 500;
			color: #333333;
			font-size: 28rpx;
			.location{
				font-weight: 400;
				.iconfont{
					font-size: 24rpx;
					margin-left: 6rpx;
				}
			}
		}
		.textInfo{
			font-weight: 400;
			color: #333333;
			font-size: 28rpx;
			margin-top: 32rpx;
			.iconfont{
				font-size: 26rpx;
				margin-left: 10rpx;
			}
			.phone{
				margin-left: 18rpx;
			}
			.address{
				color: #999999;
				font-size: 24rpx;
				margin-top: 8rpx;
			}
		}
	}

	.apply-return .list .item {
		font-size: 28rpx;
		color: #333;
		font-weight: 400;
		&~.item{
			margin-top: 32rpx;
		}
		.fontNum{
			font-size: 24rpx;
			color: #999;
		}
		.placeholder{
			font-size: 26rpx;
			color: #CCCCCC;
		}
	}
	
	.apply-return .list .item .inputs{
		text-align: right;
		font-size: 28rpx;
	}

	.apply-return .list .item .num {
		color: #282828;
		width: 427rpx;
		text-align: right;
	}

	.apply-return .list .item .num .picker .reason {
		width: 398rpx;
	}

	.apply-return .list .item .num .picker .iconfont {
		color: #333;
		font-size: 24rpx;
		margin-top: 1rpx;
	}

	.apply-return .list .item textarea {
		height: 166rpx;
		font-size: 26rpx;
		margin-top: 24rpx;
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
		border-radius: 3rpx;
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
		position: fixed;
		left:0;
		bottom: 0;
		padding-bottom: calc(0rpx+ constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		padding-bottom: calc(0rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
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
	
	.goodsList{
		width: 710rpx;
		padding: 32rpx 24rpx;
		border-radius: 24rpx;
		background-color: #fff;
		margin: 20rpx auto 0 auto;
	}
	
	.goodsStyle{
		margin: 0;
		padding: 0;
		
		&~.goodsStyle{
			margin-top: 32rpx;
		}
		
		.pictrue{
			width: 136rpx;
			height: 136rpx;
			border-radius: 16rpx;
			image{
				border-radius: 16rpx;
			}
		}
		.text{
			color: #333;
			width: 506rpx;
			font-weight: 400;
			.textCon{
				height: 100rpx;
			}
			.info{
				color: #999999;
				font-size: 24rpx;
				width: 360rpx;
			}
			.money{
				.num{
					font-size: 24rpx;
					color: #999;
					margin-top: 8rpx;
				}
			}
		}
	}

	.goodsStyle .text .name {
		align-self: flex-start;
	}

	.list /deep/ .uni-input-input {
		text-align: right;
	}
</style>
