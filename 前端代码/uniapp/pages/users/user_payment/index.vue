<template>
	<view :style="colorStyle">
		<!-- #ifdef MP -->
		<view class="accountTitle">
			<view :style="{height:getHeight.barTop+'px'}"></view>
			<view class="sysTitle acea-row row-center-wrapper" :style="{height:getHeight.barHeight+'px'}">
				<view>账户充值</view>
				<text class="iconfont icon-ic_leftarrow" @click="goarrow"></text>
			</view>
		</view>
		<view :style="{height:(getHeight.barTop+getHeight.barHeight)+'px'}"></view>
		<!-- #endif -->
		<form @submit="submitSub">
			<view class="paymentCon">
				<view class="payment-top">
					<span class="name">我的余额</span>
					<view class="money">{{ userinfo.now_money || 0 }}</view>
					<view class="pictrue">
						<image src="../static/chongzhi.png"></image>
					</view>
				</view>
			</view>
			<view class="payment">
				<view class="nav acea-row row-between-wrapper" v-if="balanceStatus">
					<view class="item" :class="(active==index && index==0)?'on on1':(active==index && index==1)?'on on2':index!=1?'on3':''" v-for="(item,index) in navRecharge" :key="index" @click="navRecharges(index)">
					   {{item}}
					</view>
				</view>
				<view class='tip picList' v-if='!active' >
					<view class="pic-box pic-box-color acea-row row-center-wrapper row-column" :class="activePic == index ? 'pic-box-color-active' : ''"
					 v-for="(item, index) in picList" :key="index" @click="picCharge(index, item)" v-if="item.price">
					   <view>
						   <view class="pic-number-pic">¥<text class="money">{{ item.price }}</text></view>
						   <view class="pic-number" v-show="item.give_money > 0">赠送{{ item.give_money }}元</view>
						   <view class="label" v-if="parseInt(item.is_welfare)">充值福利</view>
					   </view>
					</view>
					<view class="pic-box pic-box-color acea-row row-center-wrapper" :class="activePic == picList.length ? 'pic-box-color-active' : ''"
					 @click="picCharge(picList.length)">
						<input type="digit" :placeholder="activePic == picList.length?'':'自定义'" v-model="money" class="pic-box-money" placeholder-class="placeholders" />
					</view>
				</view>
				<view class="tip" v-else>
					<view class="title">转入佣金</view>
					<view class='input acea-row row-middle'><text>￥</text><input @input='inputNum' :maxlength="moneyMaxLeng" placeholder="请输入转入佣金金额" type='digit' placeholder-class='placeholder' v-model="number" name="number"></input></view>
					<view class="tips-title acea-row row-between-wrapper">
						<view>当前可转入佣金<text class='font-num'>{{userinfo.commissionCount || 0}}</text>元,冻结佣金<text class='font-num'>{{userinfo.broken_commission}}</text>元</view>
						<view class="font-num" @click="allChange">全部转入</view>
					</view>
				</view>
				<view class="tips-box">
					<view class="tips">充值说明</view>
					<view class="tips-samll acea-row row-top row-between" v-for="item in rechargeAttention" :key="item">
						<view class="drop"></view>
						<view class="info">{{ item }}</view>
					</view>
				</view>
				<button class='but bg-color' formType="submit" > {{active ? '立即转入': '立即充值' }}</button>
			</view>
		</form>
		<!-- 确认框 -->
		<tuiModal
			:show="showModal"
			title="温馨提示"
			content="转入余额后无法再次转出，确认是否转入余额"
			:maskClosable="false"
			@click="handleClick"></tuiModal>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import {
		rechargeAPi,
		getRechargeApi,
		memberCardCreate
	} from '@/api/user.js';
	import tuiModal from "@/components/tui-modal/index.vue"
	import {toLogin} from '@/libs/login.js';
	import {orderOfflinePayType} from '@/api/order.js';
	import {mapGetters} from "vuex";
	import colors from "@/mixins/color";
	import {openPaySubscribe} from '@/utils/SubscribeMessage.js';
	import home from '@/components/home/index.vue';
	export default {
		components: {
			tuiModal,
			home
		},
		mixins:[colors],
		data() {
			let that = this;
			return {
				// #ifdef MP
				getHeight: this.$util.getWXStatusHeight(),
				// #endif
				now_money: 0,
				navRecharge: ['账户充值', '佣金转入'],
				active: 0,
				number: '',
				userinfo: {},
				placeholder: "0.00",
				from: '',
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				picList: [],
				activePic: 0,
				money: "",
				numberPic:'',
				rechar_id:0,
				password: '',
				goodsList: [],
				pay_order_id: '',
				payType: '',
				totalPrice: '0',
				formContent: '',
				// #ifdef H5
				isWeixin: this.$wechat.isWeixin(),
				// #endif
				type: '',
				rechargeAttention:[],
				moneyMaxLeng:8,
				showModal: false,
				balanceStatus:0,
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad(options) {
			this.getRecharge();
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			allChange(){
				this.number = this.userinfo.commissionCount;
			},
			goarrow(){
				let pages = getCurrentPages();
				let prevPage = pages[pages.length - 2];
				if(prevPage){
					uni.navigateBack()
				}else{
					uni.reLaunch({
						url: '/pages/index/index'
					})
				}
			},
			inputNum: function(e) {
				let val = e.detail.value;
				let dot = val.indexOf('.');
				if(dot>-1){
					this.moneyMaxLeng = dot+3;
				}else{
					this.moneyMaxLeng = 8
				}
			},
			/**
			 * 选择金额
			 */
			picCharge(idx, item) {
				this.activePic = idx;
				if (item === undefined) {
					this.rechar_id = 0;
					this.numberPic = "";
				} else {
					this.money = "";
					this.rechar_id = item.id;
					this.numberPic = item.price;
				}
			},

			/**
			 * 充值额度选择
			 */
			getRecharge() {
				getRechargeApi().then(res => {
					let data = res.data;
					this.userinfo.now_money = data.user_now_money;
					this.userinfo.broken_commission = data.broken_commission;
					this.userinfo.commissionCount = data.commissionCount;
					this.picList = data.recharge_quota;
					if (this.picList[0]) {
						this.rechar_id = this.picList[0].id;
						this.numberPic = this.picList[0].price;
					}
					this.rechargeAttention = data.recharge_attention || [];
					this.balanceStatus = data.user_extract_balance_status;
				}).catch(res => {
					this.$util.Tips({
						title: res
					})
				});
			},
			navRecharges: function(index) {
				this.active = index;
			},
			handleClick(e){
				let index = e.index;
				if(index == 1){
					let data = {
						price: this.number,
						type: 1,
						// #ifdef H5
						from: this.$wechat.isWeixin() ? "weixin" : "weixinh5",
						// #endif
						// #ifdef MP
						from: 'routine',
						// #endif
					};
					rechargeAPi(data).then(res=>{
						this.$util.Tips({
							title: res.msg
						}, {
							url: "/pages/users/user_money/index"
						})
					}).catch(err => {
						return this.$util.Tips({
							title: err
						})
					});
				}
				this.showModal = false;
			},
			submitSub: function(e) {
				if (!this.isLogin) {
					return toLogin()
				}
				let that = this
				let value = e.detail.value.number;
				// 转入余额
				if (that.active) {
					if (parseFloat(value) < 0 || parseFloat(value) == NaN || value == undefined || value == "") {
						return that.$util.Tips({
							title: '请输入金额'
						});
					}
					this.showModal = true;
				} else {
					let data = {
						price: this.numberPic ? this.numberPic : this.money,
						rechar_id: this.rechar_id,
						type: 0,
						// #ifdef H5
						from: this.$wechat.isWeixin() ? "weixin" : "weixinh5",
						// #endif
						// #ifdef MP
						from: 'routine',
						// #endif
					};
					rechargeAPi(data).then(res=>{
						uni.reLaunch({
							url: `/pages/goods/cashier/index?order_id=${res.data.order_id}&from_type=recharge`
						})
					}).catch(err => {
						return this.$util.Tips({
							title: err
						})
					});
				}
			},
      onhomehide() {
				this.homeHide = false;
			}
		}
	}
</script>

<style lang="scss">
	.bgcolor{
		background-color: var(--view-theme)
	}
	.accountTitle{
		background-color: var(--view-minorColorT);
		position: fixed;
		left:0;
		top:0;
		width: 100%;
		z-index: 99;
		.sysTitle{
			width: 100%;
			position: relative;
			font-weight: 500;
			color: #333333;
			font-size: 30rpx;
			.iconfont{
				position: absolute;
				font-size: 36rpx;
				left:11rpx;
				width: 60rpx;
			}
		}
	}
	.payment {
		position: relative;
		width: 710rpx;
		background-color: #fff;
		border-radius: 32rpx;
		margin: -176rpx auto 0 auto;
		padding-bottom: 64rpx;
	}

	.payment .nav {
		height: 96rpx;
		line-height: 96rpx;
		background-color: #f5f5f5;
		border-radius: 32rpx 32rpx 0 0;
	}

	.payment .nav .item {
		font-size: 30rpx;
		color: #333;
		width: 280rpx;
		text-align: center;
		padding-right: 40rpx;
		position: relative;
		&.on1{
			padding-right: 62rpx;
			background-image: url('../static/titleLeft.png');
			&::after{
				margin-left: 150rpx;
			}
		}
		&.on2{
			padding-left: 62rpx;
			background-image: url('../static/titleRight.png');
			&::after{
				margin-left: 192rpx;
			}
		}
		&.on3{
			padding-left: 62rpx;
		}
	}

	.payment .nav .item.on {
		font-weight: bold;
		width: 407rpx;
		height: 96rpx;
		background-repeat: no-repeat;
		background-size: 100% 100%;
		text-align: center;
		&::after{
			width: 38rpx;
			height: 30rpx;
			border: 2px solid var(--view-theme);
			border-left: 2px solid transparent !important;
			border-top: 2px solid transparent !important;
			border-right: 2px solid transparent !important;
			border-radius: 50%;
			position: absolute;
			content: ' ';
			left: 0;
			bottom: 10rpx;
		}
	}

	.payment .input {
		margin: 32rpx auto 0 auto;
		font-size: 56rpx;
		color: #333333;
		background: #F5F5F5;
		border-radius: 16rpx;
		height: 114rpx;
	}

	.payment .input text {
		padding-left: 26rpx;
		font-weight: 600;
	}

	.payment .input input {
		width: 520rpx;
		height: 94rpx;
		font-size: 60rpx;
		margin-left: 24rpx;
		font-family: 'SemiBold';
	}

	.payment .input .placeholder {
		font-weight: 400;
		color: #DDDDDD;
		height: 100%;
		line-height: 94rpx;
		font-size: 32rpx;
	}

	.payment .tip {
		font-size: 26rpx;
		color: #888888;
		padding: 30rpx 33rpx 0 33rpx;
		.title{
			font-weight: 400;
			color: #333333;
			font-size: 28rpx;
		}
	}

	.payment .but {
		color: #fff;
		font-size: 28rpx;
		width: 646rpx;
		height: 88rpx;
		border-radius: 50rpx;
		margin: 150rpx auto 0 auto;
		line-height: 88rpx;
		font-weight: 500;
		background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%);
	}

	.paymentCon{
		background: linear-gradient(180deg, var(--view-minorColorT) 0%, #f5f5f5 100%);
		padding-top: 32rpx;
	}

	.payment-top {
		width: 710rpx;
		height: 396rpx;
		background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%);
		margin: 0 auto;
		border-radius: 32rpx 32rpx 0 0;
		padding: 56rpx 60rpx;
		box-sizing: border-box;
		position: relative;

		.name {
			font-size: 28rpx;
			color: rgba(255, 255, 255, 0.8);
		}

		.money {
			font-size: 68rpx;
			color: #fff;
			font-family: 'SemiBold';
			margin-top: 16rpx;
		}
		.pictrue{
			width: 186rpx;
			height: 186rpx;
			position: absolute;
			right: 60rpx;
			top: 66rpx;

			image{
				width: 100%;
				height: 100%;
			}
		}
	}

	.picList {
		display: flex;
		flex-wrap: wrap;

		.pic-box {
			width: 204rpx;
			height: 144rpx;
			border-radius: 20rpx;
			padding: 28rpx 0 26rpx 0;
			margin: 16rpx 16rpx 0 0;
			box-sizing: border-box;
			position: relative;

			&:nth-child(3n) {
				margin-right: 0;
			}

			.label{
				position: absolute;
				left:-2rpx;
				top:-18rpx;
				width: 118rpx;
				height: 40rpx;
				background: linear-gradient(270deg, #FAAD14 0%, #FF7D00 100%);
				border-radius: 16rpx 0 16rpx 0;
				color: #fff;
				font-size: 22rpx;
				text-align: center;
				line-height: 40rpx;
			}
		}

		.pic-box-color {
			background-color: #F5F5F5;
			color: #333;
			border: 1px solid #F5F5F5;
		}

		.pic-number {
			font-size: 24rpx;
			font-weight: 400;
			color: #999999;
			margin-top: 6rpx;
		}

		.pic-number-pic {
			font-size: 36rpx;
			font-weight: 500;
			width: 100%;
			text-align: center;

			.money{
				margin-left: 10rpx;
			}
		}

		.pic-box-money{
			height: 70rpx;
			font-size: 32rpx;
			text-align: center;
			font-weight: 500;
			line-height: 70rpx;
		}

		.placeholders{
			color: #333333;
		}

		.pic-box-color-active {
			background-color: var(--view-minorColorT) !important;
			color: var(--view-theme) !important;
			border: 1px solid var(--view-theme);
			.pic-number,.placeholders{
				color: var(--view-theme) !important;
			}
		}
	}
	.tips-box{
		margin-top: 48rpx;
		width: 100%;
		padding: 0 32rpx;
		box-sizing: border-box;
		.tips {
		  font-size: 32rpx;
		  color: #333333;
		  font-weight: 600;
		  margin-bottom: 24rpx;
		}
		.tips-samll {
		  font-size: 24rpx;
		  color: #999;
		  margin-bottom: 20rpx;

		  .drop{
			 width: 10rpx;
			 height: 10rpx;
			 background-color: var(--view-theme);
			 border-radius: 50%;
			 margin-top: 12rpx;
		  }

		  .info{
			  width: 620rpx;
		  }
		}
		.tip-box {
		  margin-top: 30rpx;
		}
	}
	.tips-title{
		margin-top: 32rpx;
		font-size: 26rpx;
		color: #333;
	}
</style>
