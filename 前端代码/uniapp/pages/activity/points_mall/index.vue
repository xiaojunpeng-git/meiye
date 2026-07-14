<template>
	<view :style="colorStyle">
		<!-- #ifdef MP || APP-PLUS -->
		<view class="accountTitle">
			<view :style="{height:sysHeight * 2 + 'rpx'}"></view>
			<view class="sysTitle h-80 flex-center">
				<view>积分商城</view>
				<text class="iconfont icon-ic_leftarrow" @click="goarrow"></text>
			</view>
		</view>
		<view :style="{height: (sysHeight + 39) * 2 +'rpx'}"></view>
		<!-- #endif -->
		<view class="header">
			<view class="headerBg">
				<view class="pictrue">
					<image src="../static/mall03.png"></image>
				</view>
				<view class="num">当前积分{{integral}}</view>
			</view>
			<navigator hover-class='none' url='/pages/goods/order_list/index?type=4' class="record acea-row row-center-wrapper">兑换记录</navigator>
		</view>
		<view class="nav acea-row row-middle row-around">
			<navigator hover-class='none' url='/pages/users/user_integral/index' class="item">
				<view class="pictrue">
					<image src="../static/mall05.png"></image>
				</view>
				<view>我的积分</view>
			</navigator>
			<view class="line"></view>
			<navigator hover-class='none' url='/pages/users/user_sgin/index' class="item" v-if="signInStatus">
				<view class="pictrue">
					<image src="../static/mall04.png"></image>
				</view>
				<view>每日签到</view>
			</navigator>
			<view class="line"></view>
			<navigator hover-class='none' url='/pages/goods/lottery/grids/index?type=1' class="item">
				<view class="pictrue">
					<image src="../static/mall02.png"></image>
				</view>
				<view>积分抽奖</view>
			</navigator>
		</view>
		<view class="hot" v-if="goodList.length">
			<view class="title">热门推荐</view>
			<scroll-view scroll-x="true" class="scroll">
				<view class="scroll-item" v-for="(item,index) in goodList" :key="index" @click="goGoodsDetail(item)">
					<view class="pictrue">
						<image :src='item.image'></image>
					</view>
					<view class="name line1">
						<text v-if="item.brand_name" class="brand-tag">{{ item.brand_name }}</text>
						{{item.title}}
					</view>
					<view class="info">已有{{item.sales}}人兑换</view>
					<view class="price-box acea-row row-middle">
						<view class="acea-row row-middle" v-if="parseFloat(item.integral)">
							<image src="../static/mall05.png"></image>
							<text class="font-num">{{ item.integral }}</text>
						</view>
						<text v-if="parseFloat(item.integral) && parseFloat(item.price)">+</text>
						<text v-if="parseFloat(item.price)">
							<text class="font-num">{{ item.price }}</text>
							<text class="fs-22">元</text>
						</text>
					</view>
				</view>
			</scroll-view>
		</view>
		<view class="body">
			<view class="body-title">
				<scroll-view scroll-x="true" class="scroll">
					<view class="item" :class="index == current?'on':''" 
					v-for="(item, index) in navList" :key="index" 
					@click="navTap(item,index)">{{item.value}}{{index?'积分':''}}</view>
				</scroll-view>
			</view>
			<view class="product-list" v-if="integralGood.length">
				<view class="product-item" v-for="(item, index) in integralGood" @click="goGoodsDetail(item)">
					<easy-loadimage
					class="bg-w111-f9f9f9"
					mode="aspectFit" 
					:image-src="item.image"
					width="314rpx"
					height="314rpx"
					borderRadius="20rpx 20rpx 0 0"></easy-loadimage>
					<view class="info">
						<view class="title line2">
							<text v-if="item.brand_name" class="brand-tag">{{ item.brand_name }}</text>
							{{ item.title }}
						</view>
						<view class="sales">已有{{item.sales}}人兑换</view>
						<view class="price-box acea-row row-middle">
							<view class="acea-row row-middle" v-if="parseFloat(item.integral)">
								<image src="../static/mall05.png"></image>
								<text class="font-num">{{ item.integral }}</text>
							</view>
							<text v-if="parseFloat(item.integral) && parseFloat(item.price)">+</text>
							<text v-if="parseFloat(item.price)">
								<text class="font-num">{{ item.price }}</text>
								<text class="fs-22">元</text>
							</text>
						</view>
					</view>
				</view>
			</view>
			<view v-else class="no-goods">
				<emptyPage title="暂无商品，去看看别的吧～" src="/statics/images/noActivity.gif"></emptyPage>
			</view>
		</view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import {
	  toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from 'vuex';
	import {
		getStoreIntegral,
		getIntegralCategory,
		getStoreIntegralList
	} from '@/api/activity.js'
	import {
		getSignStatus
	} from '@/api/user.js';
	import {
		goShopDetail
	} from '@/libs/order.js';
	import colors from "@/mixins/color";
	import {HTTP_REQUEST_URL} from '@/config/app';
	import emptyPage from '@/components/emptyPage.vue';
	import home from '@/components/home/index.vue';
	export default {
		components: { emptyPage, home },
		mixins: [colors],
		data() {
			return {
				sysHeight,
				// #ifdef MP
				getHeight: this.$util.getWXStatusHeight(),
				// #endif
				goodList: [],
				navList: [],
				current: 0,
				where: {
					range: '',
					page: 1,
					limit: 20,
				},
				integralGood: [],
				imgHost: HTTP_REQUEST_URL,
				isShowAuth: false,
				loadend: false,
				loading: false,
				loadTitle: '加载更多',
				integral: 0,
				signInStatus:0 ,//签到是否开启
				homeHide: false
			}
		},
		computed: mapGetters(['isLogin','uid']),
		onLoad() {
			this.integralCategory();
			this.storeIntegralList();
			this.getStoreIntegral();
			this.signStatus();
			// #ifdef H5
			this.setOpenShare();
			// #endif
		},
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV == true) {
						this.getStoreIntegral();
					}
				},
				deep: true
			},
		},
		onShow(){
			uni.removeStorageSync('form_type_cart');
		},
		onPageScroll(object) {
			uni.$emit('scroll');
			this.homeHide = true;
		},
		methods: {
			signStatus(){
				getSignStatus().then(res=>{
					this.signInStatus = res.data.sign_status;
				}).catch(err=>{
					this.$util.Tips({
						title: err
					});
				})
			},
			// 授权回调
			onLoadFun() {
				this.isShowAuth = false;
			},
			// 授权关闭
			authColse: function(e) {
			  this.isShowAuth = e
			},
			integralCategory(){
				getIntegralCategory().then(res=>{
					let data = res.data;
					data.unshift({
						value:'全部'
					})
					this.navList = data;
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					});
				})
			},
			navTap(item,index){
				if (this.current === index) return;
				this.current = index;
				this.where.range = item.value;
				this.where.page = 1;
				this.loadend = false;
				this.$set(this, 'integralGood', []);
				this.storeIntegralList();
			},
			storeIntegralList(){
				if (this.loadend) return;
				if (this.loading) return;
				this.loading = true;
				getStoreIntegralList(this.where).then(res=>{
					let list = res.data;
					this.integralGood.push.apply(this.integralGood, list);
					this.loadend = list.length < this.where.limit;
					this.loading = false;
					this.loadTitle = this.loadend ? '没有更多内容啦~' : '加载更多';
					this.$set(this, 'integralGood', this.integralGood);
					this.where.page = this.where.page + 1;
				}).catch(err=>{
					this.loading = false;
					this.loadTitle = '加载更多';
					return this.$util.Tips({
						title: err
					});
				})
			},
			goarrow(){
				uni.navigateBack()
			},
			getStoreIntegral() {
				getStoreIntegral().then(res => {
					this.goodList = res.data.list;
					this.integral = res.data.integral;
				})
			},
			// 去商品详情
			goGoodsDetail(item) {
				uni.navigateTo({
					url: `/pages/activity/goods_details/index?id=${item.id}&type=4`
				});
			},
			// #ifdef H5
			// 微信分享；
			setOpenShare: function() {
				let that = this;
				let uid = this.uid ? this.uid : 0;
				if (that.$wechat.isWeixin()) {
					let data = res.data;
					let configAppMessage = {
						title: '积分商城',
						link: location.href + '?spid=' + uid
					};
					that.$wechat.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData',
							'onMenuShareAppMessage', 'onMenuShareTimeline'
						],
						configAppMessage);
				}
			},
			// #endif
		},
		onReachBottom: function() {
			this.storeIntegralList();
		},
		/**
		 * 用户点击右上角分享
		 */
		//#ifdef MP
		onShareAppMessage() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: '积分商城',
				path: '/pages/activity/points_mall/index?spid=' + uid
			};
		},
		//分享到朋友圈
		onShareTimeline: function() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: '积分商城',
				path: '/pages/activity/points_mall/index?spid=' + uid
			};
		}
		//#endif
	}
</script>

<style lang="scss" scoped>
	page{
		padding-bottom: 40rpx;
	}
	.accountTitle{
		background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%);
		position: fixed;
		left:0;
		top:0;
		width: 100%;
		z-index: 99;
		.sysTitle{
			width: 100%;
			position: relative;
			font-weight: 500;
			color: #fff;
			font-size: 30rpx;
			.iconfont{
				position: absolute;
				font-size: 36rpx;
				left:11rpx;
				width: 60rpx;
			}
		}
	}

	.header{
		width: 100%;
		height: 400rpx;
		background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%);
		position: relative;

		.record{
			width: 124rpx;
			height: 48rpx;
			background-color: rgba(51, 51, 51, 0.2);
			border-radius: 50rpx 0 0 50rpx;
			font-weight: 400;
			color: #FFFFFF;
			font-size: 24rpx;
			position: absolute;
			right: 0;
			top:36rpx;
		}

		&::after{
			position: absolute;
			content: '';
			width: 100%;
			height: 105rpx;
			background: linear-gradient(180deg, rgba(233,51,35,0) 0%, var(--view-minorColorT) 50%, #F5F5F5 100%);
		}

		.headerBg{
			background-image: url('../static/mall01.png');
			background-repeat: no-repeat;
			background-size: 100% 100%;
			width: 689rpx;
			height: 298rpx;
			margin: 0 auto;
			padding-top: 88rpx;
			.pictrue{
				width: 345rpx;
				height: 76rpx;
				image{
					width: 100%;
					height: 100%;
				}
			}
			.num{
				font-size: 26rpx;
				font-weight: 400;
				color: rgba(255,255,255,0.6);
				margin: 4rpx 0 0 12rpx;
			}
		}
	}

	.hot{
		background-color: #fff;
		width: 710rpx;
		border-radius: 24rpx;
		margin: 20rpx auto 0 auto;
		padding: 32rpx 0 20rpx 32rpx;
		.title{
			font-weight: 600;
			color: #333333;
			font-size: 32rpx;
		}

		.scroll{
			white-space: nowrap;
			margin-top: 26rpx;
			.scroll-item{
				display: inline-block;
				width: 224rpx;
				margin-right: 20rpx;
				vertical-align: top;
				.pictrue{
					width: 100%;
					height: 224rpx;
					image{
						width: 100%;
						height: 100%;
						border-radius: 20rpx;
					}
				}
				.name{
					font-weight: 400;
					color: #333333;
					font-size: 26rpx;
					margin-top: 16rpx;
				}
				.info{
					color: #999999;
					font-size: 22rpx;
					margin-top: 8rpx;
				}
				.price-box{
					font-size: 24rpx;
					font-weight: 500;
					margin-top: 10rpx;
					color: #666;
					image{
						width: 31rpx;
						height: 31rpx;
						margin-right: 8rpx;
					}
				}
			}
		}
	}

	.nav{
		width: 710rpx;
		height: 184rpx;
		background-color: #fff;
		border-radius: 30rpx;
		margin: -150rpx auto 0 auto;
		position: relative;

		.item{
			font-size: 26rpx;
			font-weight: 400;
			color: #333333;
			text-align: center;
			width: 30%;
			.pictrue{
				width: 61rpx;
				height: 61rpx;
				margin: 0 auto 16rpx auto;
				image{
					width: 100%;
					height: 100%;
				}
			}
		}
		.line{
			width: 1px;
			height: 70rpx;
			background-color: #F3F3F3;
		}
	}

	.body {
		background-color: #fff;
		width: 710rpx;
		margin: 20rpx auto 0 auto;
		border-radius: 24rpx;
		padding-bottom: 20rpx;

		.body-title {
			padding-left: 32rpx;
			.scroll{
				white-space: nowrap;
				.item{
					display: inline-block;
					margin-right: 60rpx;
					padding: 34rpx 0 38rpx 0;
					font-size: 28rpx;
					font-weight: 400;
					&.on{
						font-weight: 500;
						color: var(--view-theme);
						position: relative;
						font-size: 30rpx;
						&::after{
							position: absolute;
							content: '';
							width: 36rpx;
							height: 30rpx;
							border: 2px solid var(--view-theme);
							border-left: 2px solid transparent !important;
							border-top: 2px solid transparent !important;
							border-right: 2px solid transparent !important;
							border-radius: 50%;
							left:50%;
							bottom: 22rpx;
							margin-left: -24rpx;
						}
					}
				}
			}
		}

		.product-list {
			display: flex;
			flex-wrap: wrap;
			justify-content: space-between;
			padding: 0 32rpx;

			.product-item {
				position: relative;
				width: 314rpx;
				background: #fff;
				border-radius: 10rpx;
				margin-bottom: 20rpx;

				.info {
					margin-top: 20rpx;

					.title {
						font-size: 28rpx;
					}

					.price-box{
						font-size: 24rpx;
						font-weight: 500;
						margin-top: 10rpx;
						color: #666;
						image{
							width: 31rpx;
							height: 31rpx;
							margin-right: 8rpx;
						}
					}

					.sales {
						font-size: 24rpx;
						color: #999999;
						margin-top: 8rpx;
					}
				}
			}
		}
	}
</style>
