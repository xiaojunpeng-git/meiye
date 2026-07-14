<template>
	<view class="pagebox">
		<!-- #ifdef MP || APP-PLUS -->
		<NavBar titleText="工作台" :iconColor="iconColor" :textColor="iconColor" :isScrolling="isScrolling" showBack showUser></NavBar>
		<!-- #endif -->
		<view class="headerBg">
			<view :style="{ height: `${getHeight.barTop}px` }"></view>
			<view :style="{ height: `${getHeight.barHeight}px` }"></view>
			<view class="inner"></view>
		</view>
		<view class="page-content">
			<view class="header acea-row row-middle">
				<image :src="avatar" class="avatar"></image>
				<view class="text-box">
					<view class="name">{{ nickname }}</view>
					<view class="phone">{{ phone }}</view>
				</view>
			</view>
			<view class="today">
				<view class="title-box">
					<navigator class="link" url="/pages/admin/order/index" hover-class="none">
						今日销售额(元)<text class="iconfont icon-ic_rightarrow"></text>
					</navigator>
					<view class="money">{{ after_price }}</view>
				</view>
				<view class="acea-row">
					<view class="item">
						<view class="num">{{ after_number }}</view>
						<view class="">今日订单数</view>
					</view>
					<view class="item">
						<view class="num">{{ after_pay_number }}</view>
						<view class="">今日支付人数</view>
					</view>
					<view class="item">
						<view class="num">{{ today_visits }}</view>
						<view class="">今日浏览量</view>
					</view>
				</view>
			</view>
			<view class="goods acea-row" v-if="$util.auth('mall-admin-home') && $util.auth('mall-admin-order,mall-admin-refund_order-index,mall-admin-product')">
				<navigator v-if="$util.auth('mall-admin-order')" url="/pages/admin/orderList/index?types=1" hover-class="none" class="item">
					<view class="img-box">
						<view class="img">
							<view v-if="unshipped_count" class="num">{{ unshipped_count > 99 ? '99+' : unshipped_count }}</view>
							<image :src="imgHost+'/statics/images/admin-work-order1.png'" mode=""></image>
						</view>
					</view>
					<view class="">待发货</view>
				</navigator>
				<navigator v-if="$util.auth('mall-admin-refund_order-index')" url="/pages/admin/refundOrderList/index" hover-class="none" class="item">
					<view class="img-box">
						<view class="img">
							<view v-if="refunding_count" class="num">{{ refunding_count > 99 ? '99+' : refunding_count }}</view>
							<image :src="imgHost+'/statics/images/admin-work-order2.png'" mode=""></image>
						</view>
					</view>
					<view class="">待售后</view>
				</navigator>
				<navigator v-if="$util.auth('mall-admin-product')" url="/pages/admin/goods/index?type=4" hover-class="none" class="item">
					<view class="img-box">
						<view class="img">
							<view v-if="outofstock" class="num">{{ outofstock > 99 ? '99+' : outofstock }}</view>
							<image :src="imgHost+'/statics/images/admin-work-order3.png'" mode=""></image>
						</view>
					</view>
					<view class="">待补货</view>
				</navigator>
				<navigator v-if="$util.auth('mall-admin-product')" url="/pages/admin/goods/index?type=5" hover-class="none" class="item">
					<view class="img-box">
						<view class="img">
							<view v-if="policeforce" class="num">{{ policeforce > 99 ? '99+' : policeforce }}</view>
							<image :src="imgHost+'/statics/images/admin-work-order4.png'" mode=""></image>
						</view>
					</view>
					<view class="">库存预警</view>
				</navigator>
			</view>
			<view class="manage" v-if="$util.auth('mall-admin-product,mall-admin-order,mall-admin-refund_order,mall-admin-user,mall-admin-behalf')">
				<view class="title-box">店铺管理</view>
				<view class="list acea-row">
					<navigator v-if="$util.auth('mall-admin-product')" class="item" url="/pages/admin/goods/index" hover-class="none">
						<view class="icon">
							<image :src="imgHost+'/statics/images/admin-work-menu1.png'" mode=""></image>
						</view>
						<view class="">商品管理</view>
					</navigator>
					<navigator v-if="$util.auth('mall-admin-order')" class="item" url="/pages/admin/orderList/index" hover-class="none">
						<view class="icon">
							<image :src="imgHost+'/statics/images/admin-work-menu2.png'" mode=""></image>
						</view>
						<view class="">订单管理</view>
					</navigator>
					<navigator v-if="$util.auth('mall-admin-refund_order')" class="item" url="/pages/admin/refundOrderList/index" hover-class="none">
						<view class="icon">
							<image :src="imgHost+'/statics/images/admin-work-menu4.png'" mode=""></image>
						</view>
						<view class="">售后维权</view>
					</navigator>
					<navigator v-if="$util.auth('mall-admin-user')" class="item" url="/pages/admin/user/list" hover-class="none">
						<view class="icon">
							<image :src="imgHost+'/statics/images/admin-work-menu5.png'" mode=""></image>
						</view>
						<view class="">用户管理</view>
					</navigator>
					<navigator v-if="$util.auth('mall-admin-behalf')" class="item" url="/pages/behalf/user_list/index" hover-class="none">
						<view class="icon">
							<image :src="imgHost+'/statics/images/admin-work-menu6.png'" mode=""></image>
						</view>
						<view class="">代客下单</view>
					</navigator>
				</view>
			</view>
		</view>
		<footerPage :isWork='1' v-if="isFooter"></footerPage>
	</view>
</template>

<script>
	// #ifdef MP || APP-PLUS
	import NavBar from '@/components/NavBar.vue';
	// #endif
	import footerPage from '../components/footerPage/index.vue';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';

	import {
		deliveryInfo,
		getOrderStaging,
		getOrderTime,
		getServiceInfo
	} from '@/api/admin.js';
	import {
		getUserInfo,
	} from '@/api/user.js';

	export default {
		components: {
			// #ifdef MP || APP-PLUS
			NavBar,
			// #endif
			footerPage,
		},
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				// #ifdef MP || APP-PLUS
				iconColor: '#FFFFFF',
				isScrolling: false,
				// #endif
				avatar: '',
				nickname: '',
				phone: '',
				outofstock: 0, // 待补货
				policeforce: 0, // 库存预警
				unshipped_count: 0, // 待发货
				refunding_count: 0, // 待售后
				after_price: 0, // 今日销售额
				after_number: 0, // 今日订单数
				after_pay_number: 0, // 今日支付人数
				today_visits: 0, // 今日浏览量
				getHeight: this.$util.getWXStatusHeight(),
				isFooter:0
			}
		},
		onShow() {
			this.getUserInfo();
			this.getOrderTime();
			this.getOrderStaging();
			this.serviceInfo();
			this.$store.commit('SET_STORE', 1);
		},
		methods: {
			serviceInfo(){
				getServiceInfo().then(res=>{
					uni.setStorageSync('mall_unique_auth', res.data.mall_unique_auth);
					this.isFooter = 1
				}).catch(err=>{
					this.$util.Tips({
						title: err
					});
				})
			},
			getUserInfo() {
				getUserInfo().then(res => {
					const {
						avatar,
						nickname,
						phone,
					} = res.data;
					this.avatar = avatar;
					this.nickname = nickname;
					this.phone = phone;
				});
			},
			getOrderTime() {
				getOrderTime({
					type: 1,
				}).then(res => {
					const {
						after_number,
						after_pay_number,
						after_price,
						today_visits,
					} = res.data;
					this.after_number = after_number;
					this.after_pay_number = after_pay_number;
					this.after_price = after_price;
					this.today_visits = today_visits;
				});
			},
			getOrderStaging() {
				getOrderStaging().then(res => {
					const {
						outofstock,
						policeforce,
						unshipped_count,
						refunding_count,
					} = res.data;
					this.outofstock = outofstock;
					this.policeforce = policeforce;
					this.unshipped_count = Number(unshipped_count);
					this.refunding_count = Number(refunding_count);
				});
			},
		},
	}
</script>

<style lang="scss" scoped>
	.pagebox {
		position: relative;
		overflow: hidden;
	}

	.headerBg {
		position: absolute;
		top: 0;
		left: -25%;
		width: 150%;
		border-bottom-right-radius: 100%;
		border-bottom-left-radius: 100%;
		background: linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%);

		.inner {
			height: 356rpx;
		}
	}

	.page-content {
		position: relative;
		padding: 0 20rpx;

		.header {
			padding: 22rpx 20rpx 32rpx 12rpx;

			.avatar {
				width: 80rpx;
				height: 80rpx;
				border-radius: 50%;
			}

			.text-box {
				flex: 1;
				padding-left: 16rpx;
				color: #FFFFFF;
			}

			.name {
				font-weight: 500;
				font-size: 32rpx;
				line-height: 44rpx;
			}

			.phone {
				margin-top: 4rpx;
				font-size: 22rpx;
				line-height: 30rpx;
			}

			.iconfont {
				font-size: 40rpx;
				color: #FFFFFF;
			}
		}

		.today {
			border-radius: 24rpx;
			background: #FFFFFF;

			.title-box {
				padding: 40rpx 0 0 40rpx;
			}

			.link {
				display: inline-block;
				vertical-align: middle;
				font-size: 24rpx;
				line-height: 34rpx;
				color: #999999;
			}

			.iconfont {
				margin-left: 4rpx;
				font-size: 24rpx;
			}

			.money {
				margin-top: 16rpx;
				font-family: SemiBold;
				font-size: 56rpx;
				line-height: 56rpx;
				color: #FF7E00;
			}

			.item {
				flex: 1;
				padding: 54rpx 0 38rpx;
				text-align: center;
				font-size: 24rpx;
				line-height: 34rpx;
				color: #999999;
			}

			.num {
				margin-bottom: 12rpx;
				font-family: SemiBold;
				font-size: 36rpx;
				line-height: 36rpx;
				color: #333333;
			}
		}

		.goods {
			border-radius: 24rpx;
			margin-top: 20rpx;
			background: #FFFFFF;

			.item {
				flex: 1;
				padding: 30rpx 0 26rpx;
				text-align: center;
				font-size: 26rpx;
				line-height: 36rpx;
				color: #333333;
			}

			.img-box {
				position: relative;
				width: 76rpx;
				height: 76rpx;
				border-radius: 50%;
				margin: 0 auto 12rpx;
			}

			.img {
				width: 100%;
				height: 100%;
			}

			.num {
				position: absolute;
				top: -6rpx;
				left: 58rpx;
				z-index: 2;
				min-width: 24rpx;
				height: 24rpx;
				padding: 0 8rpx;
				border-radius: 12rpx;
				background: #FF7E00;
				font-weight: 500;
				font-size: 18rpx;
				line-height: 24rpx;
				color: #FFFFFF;
			}

			image {
				width: 100%;
				height: 100%;
			}
		}

		.manage {
			padding-bottom: 41rpx;
			border-radius: 24rpx;
			margin-top: 20rpx;
			background: #FFFFFF;

			.title-box {
				padding: 34rpx 0 11rpx 28rpx;
				font-weight: 500;
				font-size: 30rpx;
				line-height: 42rpx;
				color: #333333;
			}

			.item {
				flex: 0 0 25%;
				padding: 27rpx 0;
				text-align: center;
				font-size: 26rpx;
				line-height: 26rpx;
				color: #333333;

				.icon {
					width: 48rpx;
					height: 48rpx;
					margin: 0 auto 20rpx;
				}
			}

			image {
				width: 48rpx;
				height: 48rpx;
			}
		}
	}
</style>