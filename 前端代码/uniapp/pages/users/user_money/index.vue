<template>
  <!-- 我的余额模块 -->
	<view :style="colorStyle">
		<view class='my-account'>
			<!-- #ifdef MP -->
			<view class="accountTitle">
				<view :style="{height:getHeight.barTop+'px'}"></view>
				<view class="sysTitle acea-row row-center-wrapper" :style="{height:getHeight.barHeight+'px'}">
					<view>我的账户</view>
					<text class="iconfont icon-ic_leftarrow" @click="goarrow"></text>
				</view>
			</view>
			<view :style="{height:(getHeight.barTop+getHeight.barHeight)+'px'}"></view>
			<!-- #endif -->
			<view class='wrapper'>
				<view class='header'>
					<view class='headerCon'>
						<view class='account acea-row row-top row-between'>
							<view class='assets'>
								<view>总资产(元)</view>
								<view class='money'>{{userInfo.now_money || 0}}</view>
							</view>
							<!-- #ifdef APP-PLUS || H5 -->
							<navigator url="/pages/users/user_payment/index" hover-class="none"
								class='recharge'>充值</navigator>
							<!-- #endif -->
							<!-- #ifdef MP -->
							<view v-if="recharge_switch" @click="openSubscribe('/pages/users/user_payment/index')"
								class='recharge'>充值</view>
							<!-- #endif -->
						</view>
						<view class='cumulative acea-row row-middle'>
							<!-- #ifdef APP-PLUS || H5 -->
							<view class='item'>
								<view>累计充值(元)</view>
								<view class='money'>{{userInfo.recharge || 0}}</view>
							</view>
							<!-- #endif -->
							<!-- #ifdef MP -->
							<view class='item' v-if="recharge_switch">
								<view>累计充值(元)</view>
								<view class='money'>{{userInfo.recharge || 0}}</view>
							</view>
							<!-- #endif -->
							<view class='item'>
								<view>累计消费(元)</view>
								<view class='money'>{{userInfo.orderStatusSum || 0}}</view>
							</view>
						</view>
						<view class="pictrue">
							<image :src="imgHost+'/statics/images/users/pig.png'"></image>
						</view>
					</view>
				</view>
				<view class="nav acea-row row-between-wrapper">
					<navigator class='item acea-row row-between-wrapper' hover-class='none' url='/pages/users/user_bill/index?type=1'>
						<view class="left">
							<view class="name">消费记录</view>
							<view>赚积分抵现金</view>
						</view>
						<view class="pictrue">
							<image src="../static/xiaofeijilu.png"></image>
						</view>
					</navigator>
					<navigator class='item acea-row row-between-wrapper' hover-class='none' url='/pages/users/user_bill/index?type=2' v-if="recharge_switch">
						<view class="left">
							<view class="name">充值记录</view>
							<view>满减享优惠</view>
						</view>
						<view class="pictrue">
							<image src="../static/chongzhijilu.png"></image>
						</view>
					</navigator>
				</view>
			</view>
			<view class="advert">
				<view class="title">热门活动</view>
				<view class="list acea-row row-middle row-around">
					<navigator hover-class='none' url='/pages/activity/goods_combination/index' class="item">
						<view class="pictrue">
							<image src="../static/pintuan.png"></image>
						</view>
						<view class="name">拼团活动</view>
						<view>优惠商品拼团</view>
					</navigator>
					<view class="line"></view>
					<navigator hover-class='none' url='/pages/activity/goods_seckill/index' class="item">
						<view class="pictrue">
							<image src="../static/miaosha.png"></image>
						</view>
						<view class="name">限时秒杀</view>
						<view>商品秒杀进行中</view>
					</navigator>
					<view class="line"></view>
					<navigator hover-class='none' url='/pages/activity/goods_bargain/index' class="item">
						<view class="pictrue">
							<image src="../static/kanjia.png"></image>
						</view>
						<view class="name">砍价活动</view>
						<view>呼朋唤友来砍价</view>
					</navigator>
				</view>
			</view>
			<recommend v-if="hostProduct.length" :hostProduct="hostProduct"></recommend>
		</view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import {
		getProductHot
	} from '@/api/store.js';
	import {
		openRechargeSubscribe
	} from '@/utils/SubscribeMessage.js';
	import {
		getUserInfo,
		userActivity
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import recommend from '@/components/recommend/index';
	import home from '@/components/home';
	import colors from "@/mixins/color";
  import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		components: {
			recommend,
			home
		},
		mixins: [colors],
		data() {
			return {
                imgHost: HTTP_REQUEST_URL,
				// #ifdef MP
				getHeight: this.$util.getWXStatusHeight(),
				// #endif
				userInfo: {},
				hostProduct: [],
				isClose: false,
				recharge_switch: 0,
				activity: {},
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				hotScroll: false,
				hotPage: 1,
				hotLimit: 10,
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						this.getUserInfo();
						this.get_activity();
					}
				},
				deep: true
			}
		},
		onLoad() {
			this.get_host_product();
			if (this.isLogin) {
				this.getUserInfo();
				this.get_activity();
			} else {
				toLogin()
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		methods: {
			goarrow(){
				uni.navigateBack()
			},
			// #ifdef MP
			openSubscribe: function(page) {
				uni.showLoading({
					title: '正在加载',
				})
				openRechargeSubscribe().then(res => {
					uni.hideLoading();
					uni.navigateTo({
						url: page,
					});
				}).catch(() => {
					uni.hideLoading();
				});
			},
			// #endif
			/**
			 * 获取用户详情
			 */
			getUserInfo: function() {
				let that = this;
				getUserInfo().then(res => {
					that.$set(that, 'userInfo', res.data);
					that.recharge_switch = res.data.recharge_switch;
				});
			},
			/**
			 * 获取活动可参与否
			 */
			get_activity: function() {
				let that = this;
				userActivity().then(res => {
					that.$set(that, "activity", res.data);
				})
			},
			/**
			 * 获取我的推荐
			 */
			get_host_product: function() {
				let that = this;
				if (that.hotScroll) return
				getProductHot(
					that.hotPage,
					that.hotLimit,
				).then(res => {
					that.hotPage++
					that.hotScroll = res.data.length < that.hotLimit
					that.hostProduct = that.hostProduct.concat(res.data)
				});
			},
      onhomehide() {
				this.homeHide = false;
			}
		},
		onPageScroll(e) {
			uni.$emit('scroll');
      this.homeHide = true;
		},
		onReachBottom() {
			this.get_host_product();
		}
	}
</script>

<style scoped lang="scss">
	/deep/.recommend{
		padding: 40rpx 20rpx 0 20rpx;
	}
	.my-account{
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
		.advert{
			width: 710rpx;
			height: 332rpx;
			background: #FFFFFF;
			border-radius: 24rpx;
			margin: 20rpx auto 0 auto;
			padding: 0 32rpx;
			.title{
				font-size: 32rpx;
				font-weight: 500;
				color: #333333;
				height: 108rpx;
				line-height: 108rpx;
				border-bottom: 1px solid #eee;
			}
			.list{
				margin-top: 34rpx;
				.line{
					height: 126rpx;
					width: 1rpx;
					background-color: #EEEEEE;
				}
				.item{
					text-align: center;
					font-weight: 400;
					color: #999999;
					font-size: 22rpx;
					.pictrue{
						width: 66rpx;
						height: 66rpx;
						margin:  0 auto;
						image{
							width: 100%;
							height: 100%;
						}
					}
					.name{
						font-weight: 500;
						color: #333333;
						font-size: 28rpx;
						margin: 20rpx 0 8rpx 0;
					}
				}
			}
		}
	}
	.my-account .wrapper {
		padding-top: 32rpx;
		background: linear-gradient(180deg, var(--view-minorColorT) 0%, #f5f5f5 100%);
	}

	.my-account .wrapper .header {
		width: 710rpx;
		height: 362rpx;
		background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%);
		border-radius: 32rpx;
		margin: 0 auto;
		box-sizing: border-box;
		color: rgba(255, 255, 255, 0.8);
		font-size: 24rpx;
		position: relative;
	}
	
	.my-account .wrapper .header .headerCon{
		padding-top: 36rpx;
	}

	.my-account .wrapper .header .headerCon .pictrue {
		height: 284rpx;
		width: 290rpx;
		position: absolute;
		right: 0;
		bottom: 0;
		image{
			width: 100%;
			height: 100%;
		}
	}

	.my-account .wrapper .header .headerCon .account {
		padding: 0 32rpx;
	}

	.my-account .wrapper .header .headerCon .account .assets .money {
		font-size: 64rpx;
		color: #fff;
		font-family: 'SemiBold';
		margin-top: 12rpx;
	}

	.my-account .wrapper .header .headerCon .account .recharge {
		font-size: 24rpx;
		width: 112rpx;
		height: 56rpx;
		border-radius: 50rpx;
		background-color: rgba(255,255,255,0.8);
		text-align: center;
		line-height: 56rpx;
		color: var(--view-theme);
		font-weight: 500;
	}

	.my-account .wrapper .header .headerCon .cumulative {
		width: 100%;
		height: 142rpx;
		background: rgba(255,255,255,0.1);
		margin-top: 62rpx;
		padding-left: 32rpx;
		position: absolute;
		left: 0;
		bottom: 0;
	}

	.my-account .wrapper .header .headerCon .cumulative .item {
		width: 250rpx;
		position: static;
		z-index: 9;
	}

	.my-account .wrapper .header .headerCon .cumulative .item .money {
		font-size: 40rpx;
		font-family: 'SemiBold';
		color: #fff;
		margin-top: 12rpx;
	}
	
	.my-account .wrapper .nav {
		margin: 20rpx;
	}
	
	.my-account .wrapper .nav .item {
		font-size: 24rpx;
		color: #999;
		width: 348rpx;
		height: 152rpx;
		background: linear-gradient(180deg, #FFF7F0 0%, #FFFFFF 100%);
		border-radius: 24rpx;
		border: 4rpx solid #fff;
		padding: 0 31rpx;
		box-sizing: border-box;
		.name{
			font-size: 28rpx;
			color: #333;
			font-weight: 500;
			margin-bottom: 8rpx;
		}
	}
	
	.my-account .wrapper .nav .item .pictrue {
		width: 96rpx;
		height: 96rpx;
		image{
			width: 100%;
			height: 100%;
		}
	}

	.my-account .wrapper .list {
		padding: 0 30rpx;
	}

	.my-account .wrapper .list .item {
		margin-top: 44rpx;
	}

	.my-account .wrapper .list .item .picTxt .iconfont {
		width: 82rpx;
		height: 82rpx;
		border-radius: 50%;
		background-image: linear-gradient(to right, #ff9389 0%, #f9776b 100%);
		text-align: center;
		line-height: 82rpx;
		color: #fff;
		font-size: 40rpx;
	}

	.my-account .wrapper .list .item .picTxt .iconfont.yellow {
		background-image: linear-gradient(to right, #ffccaa 0%, #fea060 100%);
	}

	.my-account .wrapper .list .item .picTxt .iconfont.green {
		background-image: linear-gradient(to right, #a1d67c 0%, #9dd074 100%);
	}

	.my-account .wrapper .list .item .picTxt {
		width: 428rpx;
		font-size: 30rpx;
		color: #282828;
	}

	.my-account .wrapper .list .item .picTxt .text {
		width: 317rpx;
	}

	.my-account .wrapper .list .item .picTxt .text .infor {
		font-size: 24rpx;
		color: #999;
		margin-top: 5rpx;
	}

	.my-account .wrapper .list .item .bnt {
		font-size: 26rpx;
		color: #282828;
		width: 156rpx;
		height: 52rpx;
		border: 1px solid #ddd;
		border-radius: 26rpx;
		text-align: center;
	}

	.my-account .wrapper .list .item .bnt.end {
		font-size: 26rpx;
		color: #aaa;
		background-color: #f2f2f2;
		border-color: #f2f2f2;
	}
</style>
