<template>
	<!-- 商品分类 -->
	<view :style="colorStyle">
		<!-- 门店的两种样式布局 -->
		<storeCate1 v-if="home_style==1" :info="info" :customerType="customer_type" ref="refresh2">
		</storeCate1>
		<storeCate2 v-else-if="home_style==2" :cart_num="cart_num" :info="info" :customerType="customer_type"
			ref="refresh">
		</storeCate2>
	</view>
</template>

<script>
	import {
		mapState,
		mapGetters
	} from 'vuex';
	import colors from "@/mixins/color";
	import storeCate1 from './store_cate1.vue'
	import storeCate2 from './store_cate2.vue'
	import {
		getnearbyStore
	} from '@/api/new_store.js'
	import {
		colorChange,
		getEntryStore,
		belongStaff
	} from '@/api/api.js';
	import {
		getShare
	} from '@/api/public.js';
	import {
		getCartCounts
	} from '@/api/order.js';
	// #ifdef MP
	import util from '@/utils/util.js';
	// #endif
	export default {
		computed: {
			...mapState({
				nearbyStore: state => state.app.nearbyStore
			}),
			...mapGetters(['isLogin', 'uid'])
		},
		components: {
			storeCate1,
			storeCate2,
		},
		mixins: [colors],
		data() {
			return {
				info: {},
				id: 0,
				category: '',
				home_style: 0,
				customer_type: 1,
				where: {
					latitude: 0,
					longitude: 0,
					store_id: 0
				},
				cart_num: 0,
				mapFrom: 0,
			}
		},
		onLoad(options) {
			// #ifdef MP
			if (options.scene) {
				options = util.getUrlParams(decodeURIComponent(options.scene));
			}
			// #endif
			this.where.store_id = this.nearbyStore;
			if (options.mapFrom) {
				this.mapFrom = options.mapFrom;
			}
			if (options.id) {
				this.id = options.id;
				this.where.store_id = options.id;
				uni.setStorageSync('user_store_id', options.id);
			}
			// this.mapFrom = options.mapFrom || 0;
			// this.where.store_id = options.id;
			// uni.setStorageSync('user_store_id', options.id);
			try {
				this.where.latitude = uni.getStorageSync('user_latitude');
				this.where.longitude = uni.getStorageSync('user_longitude');
				if (options.mapFrom == 1) {
					this.getStore();
					//this.getCartNum(options.id);
				} else {
					this.selfLocation();
					if (!this.$util.checkOpenGPSServiceByAndroidIOS()) {
						this.getStore();
					}
				}
			} catch (e) {
				this.getStore();
			}
			if (options.staff_id) {
				this.entryStore(options.id);
			}
			if (options.staff_id || options.spid) {
				this.belongStaff({
					staff_id: options.staff_id || 0,
					spid: options.spid || 0,
				});
			}
			// #ifdef H5
			this.setOpenShare();
			// #endif
		},
		onUnload() {
			this.mapFrom = 0;
		},
		onHide() {
			this.mapFrom = 0;
		},
		onShow() {
			if (this.mapFrom == 0) {
				if (this.home_style == 1) {
					setTimeout(() => {
						this.$refs.refresh2.getNoCart();
					}, 10)
				}
			}
			if (this.home_style === 1) {
				this.$refs.refresh2.loadend = false;
				this.$refs.refresh2.page = 1;
				this.$refs.refresh2.tempArr = [];
				this.$refs.refresh2.getCartNum();
				this.$refs.refresh2.getCartList(1);
				this.$refs.refresh2.getProducts()
			} else if (this.home_style === 2) {
				this.$refs.refresh.getCartNum();
				this.$refs.refresh.getCartList(1);
			}
		},
		onPageScroll(e) {
			uni.$emit('scroll', e);
			// uni.$emit('scroll');
		},
		onReachBottom: function() {
			uni.$emit('onReachBottom');
		},
		methods: {
			entryStore(storeId) {
				getEntryStore().then(res => {
					let entryRules = res.data.user_entry_rules;
					let paramStore = {};
					entryRules.forEach(item => {
						if (item.name == 'user_param_store') {
							paramStore = item
						}
					})
					if (paramStore.is_cache) {
						this.$util.setCache('rulesStoreId', storeId, paramStore.cache_time * 60 * 60 * 1000)
					}
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				})
			},
			// otherFun(object) {
			// 	if (!!object) {
			// 		if (this.home_style == 1) {
			// 			this.$refs.refresh2.updateFun(object,1);
			// 		}else{
			// 			this.$refs.refresh.updateFun(object,1);
			// 		}
			// 	}
			// },
			selfLocation() {
				let self = this
				// #ifdef H5
				if (self.$wechat.isWeixin()) {
					self.$wechat.location().then(res => {
						// this.where.latitude = res.latitude;
						// this.where.longitude = res.longitude;
						uni.setStorageSync('user_latitude', res.latitude);
						uni.setStorageSync('user_longitude', res.longitude);
						self.getStore();
					}).catch(err => {
						self.getStore();
					})
				} else {
					// #endif
					uni.getLocation({
						type: 'gcj02',
						success: (res) => {
							try {
								// this.where.latitude = res.latitude;
								// this.where.longitude = res.longitude;
								uni.setStorageSync('user_latitude', res.latitude);
								uni.setStorageSync('user_longitude', res.longitude);
							} catch {}
							// self.getStore();
						},
						fail: (err) => {
							// return self.$util.Tips({
							// 	title: err.errMsg
							// });
						},
						complete: function() {
							self.getStore();
						}
					});
					// #ifdef H5	
				}
				// #endif
			},
			// 获取接口附近门店
			getStore() {
				// this.where.latitude = uni.getStorageSync('user_latitude');
				// this.where.longitude = uni.getStorageSync('user_longitude');
				getnearbyStore(this.where).then(res => {
					let data = res.data;
					let info = data.info;
					if (Object.prototype.toString.call(info) === '[object Array]') {
						info = {};
					}
					this.info = {
						// #ifdef H5 || MP
						store_splicing_switch: data.store_splicing_switch,
						// #endif
						store_self_mention: parseInt(data.store_self_mention),
						...info,
					};
					this.cart_num = data.cart_num;
					this.customer_type = parseInt(info.customer_type);
					this.home_style = info.home_style || 0;
					this.id = info.id || 0;
					uni.setStorageSync('user_store_id', this.id);
					if (this.home_style === 1) {
						setTimeout(() => {
							// this.$refs.refresh2.getAllCategory();
							if (this.isLogin) {
								this.$refs.refresh2.getCartList(1);
							}
						}, 10)
					} else if (this.home_style === 2) {
						setTimeout(() => {
							// this.$refs.refresh.where.store_id = this.id
							// this.$refs.refresh.loadend = false
							// this.$refs.refresh.getProducts(true)
						})
					}
				})
			},
			// #ifdef H5
			// 微信分享；
			setOpenShare: function() {
				let that = this;
				let uid = this.uid ? this.uid : 0;
				if (that.$wechat.isWeixin()) {
					let configAppMessage = {
						title: '门店首页',
						link: location.href + '?spid=' + uid
					};
					that.$wechat.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData',
							'onMenuShareAppMessage', 'onMenuShareTimeline'
						],
						configAppMessage);
				}
			},
			// #endif
			belongStaff(data) {
				belongStaff(data).then((res) => {
					
				})
			}
		},
		//#ifdef MP
		onShareAppMessage() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: '门店首页',
				path: '/pages/store/home/index?mapFrom=1&id=' + this.id + '&spid=' + uid
			};
		},
		//分享到朋友圈
		onShareTimeline: function() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: '门店首页',
				path: '/pages/store/home/index?mapFrom=1&id=' + this.id + '&spid=' + uid
			};
		}
		//#endif
	}
</script>
<style lang="scss">
	page {
		background: #F5F5F5;
	}

	::v-deep .uni-badge--error {
		background-color: var(--view-theme) !important;
	}

	::v-deep .scroll-list {
		margin-bottom: 60rpx !important;
	}

	::v-deep .footer {
		position: fixed;
		right: 20rpx;
		left: 20rpx;
		bottom: 36rpx;
		bottom: calc(36rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		bottom: calc(36rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		z-index: 101;

		.cartIcon {
			position: absolute;
			bottom: 0;
			left: 0;
			width: 96rpx;
			height: 96rpx;
			border-radius: 48rpx;
			background: linear-gradient(-90deg, var(--view-gradient), var(--view-theme));
			box-shadow: 0rpx 0rpx 20rpx 0rpx rgba(0, 0, 0, 0.1);

			.image-wrap {
				width: 96rpx;
				height: 96rpx;
				border-radius: 48rpx;
				text-align: center;
				line-height: 96rpx;
			}

			.image {
				width: 54rpx;
				height: 54rpx;
				vertical-align: middle;
			}
		}

		.inner-box {
			position: absolute;
			bottom: 0;
			left: 0;
			width: 96rpx;
			height: 96rpx;
			border-radius: 48rpx;
			overflow: hidden;
			transition: 0.3s;

			&.open {
				width: 100%;
			}
		}

		.inner {
			flex-wrap: nowrap;
			height: 96rpx;
			padding: 0 12rpx 0 128rpx;
			border-radius: 48rpx;
			background: #333333;
		}

		.btn {
			width: 186rpx;
			height: 72rpx;
			padding: 0 32rpx;
			background: linear-gradient(-90deg, var(--view-gradient) 0%, var(--view-theme) 100%);
			border-radius: 36rpx;
			text-align: center;
			font-weight: 500;
			font-size: 26rpx;
			line-height: 72rpx;
			color: #FFFFFF;
		}

		.detail-btn {
			margin-left: 16rpx;
			font-size: 24rpx;
			color: #FFFFFF;

			.iconfont {
				font-size: 24rpx;
			}
		}
	}

	::v-deep .goodCate .uni-badge-left-margin .uni-badge--error {
		background-color: #fff !important;
		color: var(--view-theme);
		border-color: var(--view-theme);
		z-index: 8;
	}

	::v-deep .goodCate .footer .cartIcon .uni-badge-left-margin .uni-badge--error {
		right: 0 !important;
		top: 10px !important;
	}

	::v-deep .one .uni-badge-left-margin .uni-badge--error {
		background-color: var(--view-theme) !important;
		color: #fff;
		border-color: #fff;
		z-index: 8;
	}

	::v-deep .mask {
		z-index: 99;
	}

	::v-deep .good-cate {
		padding: 80rpx;
	}

	::v-deep .address-window {
		/* #ifdef H5 */
		bottom: 94rpx !important;
		bottom: calc(94rpx + constant(safe-area-inset-bottom)) !important; ///兼容 IOS<11.2/
		bottom: calc(94rpx + env(safe-area-inset-bottom)) !important; ///兼容 IOS>11.2/
		/* #endif */
		/* #ifndef H5 */
		bottom: 98rpx;
		bottom: calc(98rpx + constant(safe-area-inset-bottom)) !important; ///兼容 IOS<11.2/
		bottom: calc(98rpx + env(safe-area-inset-bottom)) !important; ///兼容 IOS>11.2/

		/* #endif */
		&.ons {
			bottom: 0 !important;
			bottom: constant(safe-area-inset-bottom) !important; ///兼容 IOS<11.2/
			bottom: env(safe-area-inset-bottom) !important; ///兼容 IOS>11.2/
		}
	}

	::v-deep .dialog_nav::before {
		margin: 0;
	}

	::v-deep .dialog_nav {
		&.dialogIndex {
			&::before {
				left: -172rpx !important;
			}
		}
	}

	::v-deep .menu_box {
		width: 40rpx;
		height: 40rpx;
		margin-right: 20rpx;

		.iconfont {
			font-size: 40rpx;
			color: #FFFFFF;
		}
	}

	::v-deep .tui-drawer-container_right {
		left: 80rpx !important;
		border-radius: 50rpx 0 0 50rpx;
	}

	::v-deep .tui-header {
		.tui-list-cell {
			padding: 24rpx 10rpx !important;
			font-size: 24rpx !important;
			color: #333333 !important;

			&::after {
				display: none;
			}
		}

		.open {
			font-size: 20rpx;
			color: #999999;
		}
	}

	.brand-box {
		::v-deep .tui-header {
			.tui-list-cell {
				padding: 24rpx 0 !important;
				font-weight: 500;
				font-size: 28rpx !important;
			}
		}
	}

	::v-deep .input-box.fixed {
		background: #FFFFFF;
		top: 0;
		right: 0;
		left: 0;
		z-index: 10;

		.input {
			background: #F5F5F5;
			color: #999999;
		}

		.menu_box .iconfont {
			color: #333333;
		}
	}

	/* #ifdef H5 || MP */
	.dialog {
		padding: 44rpx 20rpx 0;
		padding-bottom: constant(safe-area-inset-bottom);
		padding-bottom: env(safe-area-inset-bottom);
		border-radius: 40rpx 40rpx 0 0;
		background: linear-gradient(180deg, #F5F5F5 0%, #F5F5F5 0%, #FFFFFF 100%);
	}

	.dialog-head {
		padding: 0 24rpx;
		font-weight: 500;
		font-size: 32rpx;
		color: #333333;

		.btn {
			width: 40rpx;
			height: 40rpx;
			cursor: pointer;

			.iconfont {
				font-size: 40rpx;
				color: #BBBBBB;
			}
		}
	}

	.dialog-body {
		margin-top: 48rpx;

		.navbar {
			flex-shrink: 0;
			position: relative;
			height: 82rpx;

			.navbar-bg {
				position: absolute;
				top: 0;
				left: 0;
				width: 100%;
				height: 190rpx;
				border-radius: 16rpx 16rpx 0 0;
				background: #EEEEEE;
			}

			.active-bg {
				position: absolute;
				bottom: 0;
				left: 0;
				width: 100%;
				height: 96rpx;

				.image1 {
					width: 50%;
					height: 100%;
				}

				.image2 {
					width: 48rpx;
					height: 78rpx;
				}
			}

			.inner {
				position: absolute;
				bottom: 0;
				left: 0;
				width: 100%;
				height: 100%;

				.item {
					flex: 1;
					min-width: 0;
					font-size: 28rpx;
					line-height: 44rpx;
					color: #666666;

					.iconfont {
						display: none;
						margin-right: 8rpx;
						font-size: 36rpx;
					}

					&.on {
						font-weight: 500;
						font-size: 32rpx;
						color: var(--view-theme);

						.iconfont {
							display: inline-block;
						}
					}
				}
			}
		}

		.dialog-content {
			position: relative;
			border-radius: 0 0 16rpx 16rpx;
			background: #FFFFFF;

			&.btl {
				border-top-left-radius: 24rpx;
			}

			&.btr {
				border-top-right-radius: 24rpx;
			}

			.link {
				padding: 40rpx 32rpx;
				font-size: 22rpx;
				line-height: 30rpx;
				color: #999999;

				.title {
					margin-bottom: 16rpx;
					font-weight: 500;
					font-size: 28rpx;
					line-height: 40rpx;
					color: #333333;
				}

				.iconfont {
					font-size: 32rpx;
				}
			}
		}

		.address-box {
			height: 388rpx;

			.list {
				padding: 36rpx 32rpx;
			}

			.item {
				margin-top: 32rpx;
				color: #333333;

				&:first-child {
					margin-top: 0;
				}

				.text {
					width: 606rpx;
				}

				.info {
					font-weight: 500;
					font-size: 28rpx;
					line-height: 40rpx;
				}

				.name {
					margin-top: 8rpx;
					font-size: 22rpx;
					line-height: 30rpx;
					color: #999999;
				}

				.iconfont {
					font-size: 36rpx;
				}

				&.on {
					color: var(--view-theme);

					.name {
						color: var(--view-theme);
					}

					.iconfont {
						visibility: visible;
					}
				}
			}
		}

		.btn-box {
			height: 88rpx;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #666666;

			.iconfont {
				margin-left: 4rpx;
				font-size: 24rpx;
			}
		}

		.store-box {
			padding: 40rpx 28rpx 40rpx 32rpx;

			.text {
				flex: 1;
			}

			.name {
				font-weight: 500;
				font-size: 28rpx;
				line-height: 40rpx;
				color: #333333;
			}

			.info {
				margin-top: 16rpx;
				font-size: 22rpx;
				line-height: 30rpx;
				color: #999999;
			}

			.value {
				flex: 1;
			}

			.iconfont {
				margin-right: 12rpx;
				font-size: 22rpx;
			}

			.map-box {
				position: relative;
				width: 188rpx;
				height: 104rpx;
				margin-left: 32rpx;

				.map {
					width: 100%;
					height: 100%;
				}

				.img-box {
					position: absolute;
					bottom: 16rpx;
					left: 50%;
					width: 52rpx;
					height: 52rpx;
					padding: 6rpx;
					border-radius: 6rpx;
					background: #FFFFFF;
					box-shadow: 0rpx 0rpx 12rpx 0rpx rgba(0, 0, 0, 0.0784);
					transform: translateX(-50%);
				}

				.img {
					width: 100%;
					height: 100%;
					border-radius: 6rpx;
				}

				.range {
					position: absolute;
					top: -40rpx;
					left: 50%;
					height: 36rpx;
					padding: 0 16rpx;
					border-radius: 6rpx;
					background: #FFFFFF;
					box-shadow: 0rpx 0rpx 16rpx 0rpx rgba(0, 0, 0, 0.0784);
					transform: translateX(-50%);
					white-space: nowrap;
					font-size: 20rpx;
					line-height: 36rpx;
					color: #333333;

					&::after {
						content: "";
						position: absolute;
						bottom: 0;
						left: 50%;
						width: 0;
						height: 0;
						border-width: 5rpx 9rpx;
						border-style: solid;
						border-color: #FFFFFF transparent transparent;
						transform: translate(-50%, 100%);
					}
				}

				.dot {
					position: absolute;
					bottom: -10rpx;
					left: 50%;
					width: 6rpx;
					height: 6rpx;
					border-radius: 50%;
					background: var(--view-theme);
					transform: translateX(-50%);
				}
			}
		}
	}

	.dialog-foot {
		padding: 20rpx 0;

		.btn {
			height: 72rpx;
			border-radius: 36rpx;
			background: var(--view-theme);
			text-align: center;
			font-weight: 500;
			font-size: 26rpx;
			line-height: 72rpx;
			color: #FFFFFF;
		}
	}

	/* #endif */
	.placeholder {
		color: #FFFFFF;
	}
</style>