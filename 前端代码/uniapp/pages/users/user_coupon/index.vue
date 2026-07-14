<template>
	<!-- 我的有优惠券模块 -->
	<view :style="colorStyle">
		<view class="header">
			<view class="navbar acea-row">
				<view class="item acea-row row-center-wrapper" :class="{ on: navOn === 0 }" @click="onNav(0)">
					<view>待使用({{ not_used }}张)</view>
				</view>
				<view class="item acea-row row-center-wrapper" :class="{ on: navOn === 1 }" @click="onNav(1)">
					<view>已使用({{ used }}张)</view>
				</view>
				<view class="item acea-row row-center-wrapper" :class="{ on: navOn === 2 }" @click="onNav(2)">
					<view>已失效({{ expired }}张)</view>
				</view>
			</view>
			<view class="subnavbar acea-row">
				<view class="shade">
					<image v-if="shadeLeft" src="../static/shade.png" class="img1"></image>
				</view>
				<scroll-view scroll-x="true" class="scroll-view acea-row row-middle" @scrolltolower="scrolltolower" @scrolltoupper="scrolltoupper" @scroll="scroll">
					<view class="acea-row row-middle" style="flex-wrap: nowrap;height: 100%;">
						<view class="item" :class="{ on: type === '' }" @click="onSubNav('')">
							<view class="inner flex-center">全部</view>
						</view>
						<view v-if="navOn == 0" class="item" :class="{ on: type == -1 }" @click="onSubNav(-1)">
							<view class="inner flex-center">快过期</view>
						</view>
						<view class="item" :class="{ on: type === 0 }" @click="onSubNav(0)">
							<view class="inner flex-center">通用券</view>
						</view>
						<view class="item" :class="{ on: type == 1 }" @click="onSubNav(1)">
							<view class="inner flex-center">品类券</view>
						</view>
						<view class="item" :class="{ on: type == 2 }" @click="onSubNav(2)">
							<view class="inner flex-center">商品券</view>
						</view>
						<view class="item" :class="{ on: type == 3 }" @click="onSubNav(3)">
							<view class="inner flex-center">品牌券</view>
						</view>
					</view>
				</scroll-view>
				<view class="shade">
					<image v-if="shadeRight" src="../static/shade.png" class="img"></image>
				</view>
			</view>
		</view>
		<view class="header-shadow"></view>
		<view class='coupon-list'>
			<view class="item" :class="{ gray: navOn }" v-for='(item,index) in couponsList' :key="index" @tap="openRule(item)">
				<view class="item-box acea-row" :class="{svip: item.category == 2}">
					<view class="moneyCon acea-row row-center-wrapper">
						<view class='money'>
							<BaseMoney v-if="item.coupon_type==1" :money="item.coupon_price" symbolSize="28" integerSize="52" isCoupon
								:color="navOn ? '#ffffff' : item.category == 2 ? '#FACC7D' : '#ffffff'"></BaseMoney>
							<view v-else-if="item.coupon_type==2" class="SemiBold">{{ parseFloat(item.coupon_price)/10 }}<text class="fs-28 pingfang pl-4">折</text></view>
							<view class="pic-num" v-if="item.use_min_price > 0">满{{item.use_min_price}}可用</view>
							<view class="pic-num" v-else>无门槛券</view>
						</view>
					</view>
					<view class="text acea-row row-column row-center">
						<view class='condition'>
							<view class="name line1">
								{{ item.coupon_title }}
							</view>
						</view>
						<view class='data acea-row row-between-wrapper'>
							<view>{{item.add_time}}-{{item.end_time}}</view>
						</view>
						<view class="text-bottom acea-row row-middle">
							<view v-if="item.applicable_type === 0">通用优惠券</view>
							<view v-else-if="item.applicable_type === 1">品类优惠券</view>
							<view v-else-if="item.applicable_type === 3">品牌优惠券</view>
							<view v-else>商品优惠券</view>
							<view>丨</view>
							<view class="">查看用券规则<text class="iconfont icon-ic_rightarrow"></text></view>
						</view>
					</view>
					<view class="btn-box acea-row row-middle">
						<view class="btn disabled" v-if="navOn == 1">已使用</view>
						<view class="btn disabled" v-else-if="navOn == 2">已失效</view>
						<view class="btn" v-else @tap.stop="useCoupon(item)">去使用</view>
					</view>
				</view>
			</view>
		</view>
		<view class='px-20' v-if="!couponsList.length && !loading">
			<emptyPage title="暂无优惠券，去看点别的吧～" src="/statics/images/noCoupon.gif"></emptyPage>
		</view>
		<view class="pb-safe"></view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import {
		getCouponsNum,
		getUserCoupons
	} from '@/api/api.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import colors from '@/mixins/color.js';
	import emptyPage from '@/components/emptyPage.vue';
	import home from '@/components/home/index.vue';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		components: {
			emptyPage,
			home
		},
		mixins: [colors],
		data() {
			return {
				couponsList: [],
				loading: false,
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				navOn: 0,
				type: '',
				page: 1,
				limit: 15,
				finished: false,
				imgHost: HTTP_REQUEST_URL,
				expired: 0,
				not_used: 0,
				used: 0,
				shadeRight: true,
				shadeLeft: true,
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						// #ifdef H5 || APP-PLUS
						this.getUseCoupons();
						// #endif
					}
				},
				deep: true
			}
		},
		onLoad() {
			if (this.isLogin) {
				this.getCouponsNum();
				this.getUseCoupons();
			} else {
				toLogin()
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		onReachBottom() {
			this.getUseCoupons();
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			getCouponsNum() {
				getCouponsNum().then(res => {
					this.used = res.data.used;
					this.not_used = res.data.not_used;
					this.expired = res.data.expired;
				});
			},
			onNav: function(type) {
				this.navOn = type;
				this.onSubNav('')
			},
			onSubNav(type) {
				this.type = type;
				this.couponsList = [];
				this.page = 1;
				this.finished = false;
				this.getUseCoupons();
			},
			useCoupon(item) {
				let url = '';
				const useMinPrice = Number(item.use_min_price);
				let useMin = '无门槛';
				if (useMinPrice) {
					useMin = item.coupon_type == 1 ? `满${useMinPrice}减${Number(item.coupon_price)}` : `满${useMinPrice}打${Number(item.coupon_price) / 10}折`;
				}
				// 通用券
				if (item.category_id == 0 && item.product_id == '' && item.brand_id == 0) {
					url = `/pages/goods/goods_list/index?title=默认&couponId=${item.cid}&couponIssueType=${item.coupon_issue_type}&useMinPrice=${useMin}`;
				}
				// 品类券
				if (item.category_id != 0) {
					if (item.category_type == 1) {
						url = `/pages/goods/goods_list/index?cid=${item.category_id}&title=${item.category_name}&couponId=${item.cid}&couponIssueType=${item.coupon_issue_type}&useMinPrice=${useMin}`;
					} else {
						url = `/pages/goods/goods_list/index?sid=${item.category_id}&title=${item.category_name}&couponId=${item.cid}&couponIssueType=${item.coupon_issue_type}&useMinPrice=${useMin}`;
					}
				}
				//商品券
				if (item.product_id != '') {
					let arr = item.product_id.split(',');
					let num = arr.length;
					if (num == 1) {
						url = `/pages/goods_details/index?id=${item.product_id}`;
					} else {
						url = `/pages/goods/goods_list/index?title=默认&productId=${item.product_id}&couponId=${item.cid}&couponIssueType=${item.coupon_issue_type}&useMinPrice=${useMin}`;
					}
				}
				//品牌券
				if (item.brand_id != 0) {
					url = `/pages/goods/goods_list/index?title=默认&brandId=${item.brand_id}&couponId=${item.cid}&couponIssueType=${item.coupon_issue_type}&useMinPrice=${useMin}`;
				}
				uni.navigateTo({
					url
				});
			},
			/**
			 * 授权回调
			 */
			onLoadFun: function() {
				this.getUseCoupons();
				this.isShowAuth = false;
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			/**
			 * 获取领取优惠券列表
			 */
			getUseCoupons: function() {
				let that = this;
				if (that.loading || that.finished) {
					return;
				}
				that.loading = true;
				uni.showLoading({
					title: '正在加载…'
				});
				getUserCoupons(this.navOn, {
					type: this.type,
					page: this.page,
					limit: this.limit
				}).then(res => {
					that.loading = false;
					uni.hideLoading();
					res.data.forEach(item => {
						item.ruleShow = false;
					});

					that.couponsList = that.couponsList.concat(res.data);
					that.finished = res.data.length < that.limit;
					that.page += 1;
				}).catch(err => {
					that.loading = false;
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
			},
			openRule(item) {
				uni.navigateTo({
					url: `/pages/activity/coupon_details/index?from=user&id=${item.cid}&used_id=${item.id}`
				})
			},
			scrolltolower() {
				this.$nextTick(() => {
					this.shadeRight = false
				})
			},
			scrolltoupper() {
				this.$nextTick(() => {
					this.shadeLeft = false
				})
			},
			scroll(event) {
				this.$nextTick(() => {
					this.shadeRight = true
					this.shadeLeft = true
				})
			},
      onhomehide() {
				this.homeHide = false;
			}
		}
	}
</script>


<style lang="scss" scoped>
	.money {
		display: flex;
		flex-direction: column;
		justify-content: center;
	}

	.pic-num {
		color: #ffffff;
		font-size: 24rpx;
	}

	.coupon-list .item .text .condition {
		display: flex;
		align-items: center;
	}

	.coupon-list .item .text .condition .name {
		font-size: 28rpx;
		line-height: 40rpx;
		font-weight: 500;
		width: 310rpx;
	}

	.coupon-list .item .text .condition .pic {
		width: 30rpx;
		height: 30rpx;
		display: block;
		margin-right: 10rpx;
		display: inline-block;
		vertical-align: sub;
	}

	.condition .line-title {
		padding: 0 12rpx;
		height: 32rpx !important;
		/* #ifndef APP */
		line-height: 28rpx;
		/* #endif */
		/* #ifdef APP */
		line-height: 30rpx;
		/* #endif */
		text-align: center;
		box-sizing: border-box;
		background: var(--view-minorColorT);
		border: 1px solid var(--view-theme);
		opacity: 1;
		border-radius: 20rpx;
		font-size: 18rpx !important;
		color: var(--view-theme);
		margin-right: 12rpx;
		text-align: center;
		display: inline-block;
		vertical-align: middle;
	}

	.condition .title {
		vertical-align: middle;
	}

	.condition .line-title.bg-color-huic {
		border-color: #BBB !important;
		color: #bbb !important;
		background-color: #F5F5F5 !important;
	}

	.header-shadow {
		height: 194rpx;
	}

	.header {
		position: fixed;
		top: 0;
		left: 0;
		width: 100%;
		background-color: #FFFFFF;
		z-index: 9;
	}

	.subnavbar {
		height: 92rpx;

		.scroll-view {
			flex: 1;
			min-width: 0;
			white-space: nowrap;
		}

		.shade {
			position: relative;
			width: 40rpx;

			.img {
				position: absolute;
				top: 0;
				left: 0;
				width: 18rpx;
				height: 100%;
				transform: translateX(-50%);
			}

			.img1 {
				position: absolute;
				top: 0;
				right: 0;
				width: 18rpx;
				height: 100%;
				transform: translateX(50%);
			}
		}

		.item {
			flex-shrink: 0;
			width: 120rpx;
			height: 52rpx;
			border-radius: 26rpx;
			margin-left: 22rpx;
			background: #F5F5F5;
			text-align: center;
			font-size: 22rpx;
			color: #666666;

			&:first-child {
				margin-left: 0;
			}

			.inner {
				height: 52rpx;
				border: 1rpx solid transparent;
				border-radius: 26rpx;
				background: rgba(255, 255, 255, 0);
				background-clip: content-box;
				transform: rotateZ(360deg);
			}

			&.on {
				background: var(--view-theme);
				color: var(--view-theme);

				.inner {
					background-color: rgba(255, 255, 255, 0.9);
				}
			}
		}
	}

	.navbar {
		height: 82rpx;

		.item {
			flex: 1;
			position: relative;
			font-size: 26rpx;
			color: #666666;

			.number {
				height: 34rpx;
				padding: 0 16rpx;
				border-radius: 17rpx;
				margin-top: 8rpx;
				background-color: transparent;
				font-size: 24rpx;
				line-height: 34rpx;
				color: #666666;
			}

			&.on {
				font-weight: 500;
				font-size: 28rpx;
				color: var(--view-theme);

				.number {
					background-color: var(--view-theme);
					color: #FFFFFF;
				}
			}
		}
	}

	.coupon-list {
		padding: 0 20rpx;
		margin-top: 0;

		.item {
			height: auto;
			background: none;
		}

		.item-box {
			height: 170rpx;
			border-radius: 24rpx;
			background: #FFFFFF;
		}

		.moneyCon {
			border-radius: 24rpx 0 0 24rpx;
			background: linear-gradient(270deg, var(--view-gradient) 0%, var(--view-theme) 100%);
			color: #FFFFFF;
		}

		.money {
			position: relative;
			background-image: radial-gradient(circle at left, #F5F5F5 16rpx, transparent 0);
			color: #FFFFFF;
			font-size: 52rpx;
			font-family: Regular;

			&::after {
				content: "";
				position: absolute;
				top: 0;
				bottom: 0;
				right: 0;
				width: 6rpx;
				background-image: radial-gradient(circle at 6rpx, #FFFFFF 6rpx, transparent 6rpx);
				background-size: 6rpx 18rpx;
			}
		}

		.pic-num {
			margin-top: 8rpx;
			font-weight: 400;
			font-size: 22rpx;
			line-height: 28rpx;
		}

		.text-bottom {
			margin-top: 20rpx;
			font-size: 20rpx;
			line-height: 28rpx;
			color: #999999;

			.iconfont {
				margin-left: 6rpx;
				font-size: 20rpx;
			}
		}

		.btn-box {
			padding: 0 20rpx;
		}

		.btn {
			height: 52rpx;
			padding: 0 24rpx;
			border-radius: 26rpx;
			background-color: var(--view-minorColorT);
			font-weight: 500;
			font-size: 22rpx;
			line-height: 52rpx;
			color: var(--view-theme);
		}

		.disabled {
			background: #ccc !important;
			color: #fff !important;
		}

		.rule-desc {
			padding: 42rpx 24rpx 24rpx;
			border-radius: 0 0 24rpx 24rpx;
			background: linear-gradient(180deg, #F7F7F7 0%, #FFFFFF 100%);
			font-size: 20rpx;
			line-height: 28rpx;
			color: #999999;
		}

		.gray {
			.moneyCon {
				background: #CCCCCC;
			}

			.item-right {
				background-color: #CCCCCC;
				color: #FFFFFF;
			}
		}
	}

	.svip .btn {
		background: linear-gradient(90deg, #584834 0%, #32302D 100%);
		color: #FACC7D;
	}

	.svip .moneyCon {
		background: linear-gradient(90deg, #584834 0%, #32302D 100%);
		color: #FACC7D;
	}

	.svip .pic-num {
		color: #FACC7D;
	}
	.gray .pic-num {
		color: #FFFFFF;
	}
</style>