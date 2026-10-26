<template>
	<view :style="colorStyle">
		<!-- #ifdef MP || APP -->
		<NavBar titleText="门店拼单" iconColor="#FFFFFF" textColor="#FFFFFF" :showBack="collage_uid == uid" :showHome="collage_uid != uid"></NavBar>
		<!-- #endif -->
		<view class="headerBg">
			<!-- #ifdef MP || APP -->
			<view :style="{ height: `${getHeight.barTop}px` }"></view>
			<view :style="{ height: `${getHeight.barHeight}px` }"></view>
			<!-- #endif -->
			<view class="inner">
				<image class="back" mode="aspectFit" :src="imgHost+'/statics/images/group-buy.png'"></image>
				<view class="text-box">
					<image :src="imgHost+'/statics/images/group-text.png'" class="text-image"></image>
					<view>一起拼单享满减活动</view>
				</view>
			</view>
		</view>
		<view class="content">
			<view class="top-placeholder"></view>
			<template v-if="status != -1">
				<view class="simple">
					<view class="seller">
						<view class="head acea-row">
							<view class="headCon">
								<text class="tag acea-row row-center-wrapper">{{delivery_type == 3 ? '配送' : '到店'}}</text>
								{{storeInfo.name || ''}}
							</view>
							<view>距您{{ storeInfo.range || 0 }}km</view>
						</view>
						<view>{{ storeInfo.address }}{{ storeInfo.detailed_address }}</view>
					</view>
					<view class="buyer acea-row row-middle">
						<view v-if="plainPartake.length" class="avatar-group">
							<template v-for="(item, index) in plainPartake">
								<image v-if="index <= 6" :key="item.userInfo.uid" class="avatar" :src="item.userInfo.avatar || '/static/images/f.png'" mode="aspectFill"></image>
							</template>
							<view v-if="plainPartake.length > 7" class="gengduo"><text class="iconfont icon-gengduo"></text></view>
						</view>
						<view>{{ plainPartake.length }}人已点单</view>
						<!-- #ifdef MP -->
						<view v-if="status != 2" class="btn acea-row row-center row-middle" @click="refresh">
							<text class="iconfont icon-ic_Refresh"></text>刷新
						</view>
						<!-- #endif -->
						<!-- #ifdef H5 -->
						<view v-if="status != 2 && $wechat.isWeixin()" class="btn acea-row row-center row-middle" @click="refresh">
							<text class="iconfont icon-ic_Refresh"></text>刷新
						</view>
						<!-- #endif -->
					</view>
					<view v-if="collage_uid == uid && status != 2" class="btn-box acea-row row-center">
						<view class="btn acea-row row-center row-middle" @click="onCancel">取消拼单</view>
						<!-- #ifdef MP -->
						<button class="btn acea-row row-center row-middle primary" hover-class="none" open-type="share">邀请好友</button>
						<!-- #endif -->
						<!-- #ifdef H5 -->
						<button v-if="$wechat.isWeixin()" class="btn acea-row row-center row-middle primary" hover-class="none" @tap="onShare">邀请好友</button>
						<!-- #endif -->
					</view>
				</view>
				<view v-if="collage_uid != uid" class="status">
					<view class="head">当前拼单进度</view>
					<view class="body">
						<view v-for="(item, index) in statusList" :key="item.value" :class="{ active: item.value == status }" class="item">
							<view class="number">{{ index + 1 }}</view>
							<view>{{ item.name }}</view>
						</view>
					</view>
				</view>
				<view v-if="collagePartake.length" class="choice">
					<view v-for="item in collagePartake" :key="item.userInfo.uid" class="wrapper">
						<view class="head acea-row row-middle">
							<view>
								<image class="avatar" :src="item.userInfo.avatar || '/static/images/f.png'" mode="aspectFill"></image>
							</view>
							<view class="name">
								<view class="acea-row row-middle inner">
									<view class="line1 nickname">{{ item.userInfo.nickname }}</view>
									<text v-if="item.userInfo.uid == uid">（我）</text>
									<text v-else-if="item.userInfo.uid == collage_uid">（发起人）</text>
									<text v-if="!item.goods.length" class="tips">尚未选购</text>
								</view>
							</view>
							<template v-if="status != 2">
								<button v-if="item.userInfo.uid == uid && item.goods.length" class="button remove" hover-class="none" @tap="onRemove">清空</button>
								<button v-if="item.userInfo.uid == uid && item.goods.length" class="button" hover-class="none" @tap="onChange">修改</button>
								<button v-if="item.userInfo.uid != uid && item.goods.length" class="button" hover-class="none" @tap="onCopy(item.userInfo.uid)">跟TA选一样</button>
								<button v-if="item.userInfo.uid == uid && !item.goods.length" class="button select" hover-class="none" @tap="onChange">立即选购</button>
							</template>
						</view>
						<view v-if="item.goods.length" class="body">
							<view v-for="goods in item.goods" :key="goods.id" :class="{ gray: !goods.is_true_stock }" class="item acea-row">
								<view>
									<image class="image" :src="goods.productInfo.attrInfo.image" mode="aspectFill"></image>
								</view>
								<view class="text">
									<view class="name line2">{{ goods.productInfo.store_name }}</view>
									<view class="attr line1">{{ goods.productInfo.attrInfo.suk }}</view>
								</view>
								<view>
									<BaseMoney :money="goods.truePrice" symbolSize="20" integerSize="32" decimalSize="20" color="#333333"></BaseMoney>
									<view class="number">共{{ goods.cart_num }}件</view>
								</view>
							</view>
						</view>
					</view>
				</view>
				<view v-if="collage_uid == uid && status != 2" class="footer">
					<view class="footer-wrap acea-row row-middle">
						<view class="total">
							最终优惠价：<BaseMoney :money="total" symbolSize="28" integerSize="44" decimalSize="28"></BaseMoney>
						</view>
						<button class="btn" hover-class="none" @tap="setting">去结算</button>
					</view>
				</view>
			</template>
			<view v-else class="empty">
				<emptyPage title="该订单已被发起人取消，返回重新选购">
					<template v-slot:bottom>
						<view class="btn" @click="goStore">返回门店</view>
					</template>
				</emptyPage>
			</view>
		</view>
		<!-- 发送给朋友图片 -->
		<view class="share-box" v-if="H5ShareBox">
			<image :src="imgHost + '/statics/images/share-info.png'" @click="H5ShareBox = false">
			</image>
		</view>
		<tuiModal :show="dialogShow" padding="40rpx" radius="32rpx" custom>
			<view class="dialog">
				<view class="title">确定要结算吗？</view>
				<view class="info">结算后，其他人无法再加入或修改商品</view>
				<view class="btn-box acea-row">
					<view class="btn" @click="dialogShow = false">取消</view>
					<view class="btn primary" @click="getCollagePartake(onSettle)">确认</view>
				</view>
			</view>
		</tuiModal>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	import home from '@/components/home';
	import NavBar from '@/components/NavBar.vue';
	import emptyPage from '@/components/emptyPage.vue';
	import tuiModal from '@/components/tui-modal/index.vue';
	import {
		getnearbyStore
	} from '@/api/new_store.js';
	import {
		getCollage,
		cancelCollage,
		settleCollage,
		getCollagePartake,
		emptyCollagePartake,
		duplicateCollagePartake
	} from '@/api/store.js'
	import colors from '@/mixins/color.js';
	import {
		mapGetters
	} from 'vuex';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		components: {
			home,
			NavBar,
			emptyPage,
			tuiModal,
		},
		mixins: [colors],
		data() {
			return {
				getHeight: this.$util.getWXStatusHeight(),
				storeInfo: {},
				delivery_type: 2,
				where: {
					latitude: 0,
					longitude: 0,
					store_id: 0
				},
				collage_uid: 0,
				status: null,
				statusBarHeight: 0,
				navBarHeight: 0,
				collagePartake: [],
				isShowAuth: false,
				imgHost: HTTP_REQUEST_URL,
				statusList: [{
						name: '完成选购',
						value: 0,
					},
					{
						name: '提交订单',
						value: 1,
					},
					{
						name: '完成拼单',
						value: 2,
					}
				],
				H5ShareBox: false,
				dialogShow: false,
				homeHide: false
			}
		},
		computed: {
			...mapGetters(['uid', 'isLogin']),
			total() {
				return this.collagePartake.reduce((total, {
					sum_price
				}) => {
					return this.$util.$h.Add(total, sum_price);
				}, 0);
			},
			plainPartake() {
				let partake = this.collagePartake.filter(item => {
					return item.goods.length;
				});
				return partake;
			},
			canCheckUser() {
				return {
					status: this.status,
					collagePartake: this.collagePartake
				}
			}
		},
		watch: {
			canCheckUser(value) {
				if (value.status == 2 || !value.collagePartake.length) {
					return;
				}
				let hasUser = false;
				for (let partakeItem of this.collagePartake) {
					hasUser = this.uid == partakeItem.userInfo.uid;
					if (hasUser) {
						break;
					}
				}
				if (!hasUser) {
					this.$util.Tips({
						title: '请去个人中心登录'
					});
				}
			}
		},
		onLoad(options) {
			this.collage_id = options.collage_id;
			// #ifdef MP
			let {
				top,
				height
			} = uni.getMenuButtonBoundingClientRect();
			uni.getSystemInfo({
				success: (res) => {
					const {
						statusBarHeight
					} = res;
					this.statusBarHeight = statusBarHeight;
					this.navBarHeight = (top - statusBarHeight) * 2 + height;
				}
			});
			// #endif
		},
		onShow() {
			if (this.isLogin) {
				this.getLocation();
			} else {
				toLogin();
			}
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			getLocation() {
				// #ifdef H5
				if (this.$wechat.isWeixin()) {
					this.$wechat.location().then(res => {
						try {
							uni.setStorageSync('user_latitude', res.latitude);
							uni.setStorageSync('user_longitude', res.longitude);
							this.getCollage();
							this.getCollagePartake();
						} catch (e) {
							//TODO handle the exception
						}
					}).catch(() => {
						this.getCollage();
						this.getCollagePartake();
					});
				}
				// #endif
				// #ifdef MP
				uni.getLocation({
					type: 'gcj02',
					success: (res) => {
						try {
							uni.setStorageSync('user_latitude', res.latitude);
							uni.setStorageSync('user_longitude', res.longitude);
						} catch (e) {
							//TODO handle the exception
						}
					},
					fail: (err) => {
						this.$util.Tips({
							title: err
						});
					},
					complete: () => {
						this.getCollage();
						this.getCollagePartake();
					}
				});
				// #endif
			},
			// 授权回调
			onLoadFun() {
				this.isShowAuth = false;
				this.getLocation();
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			getCollage() {
				getCollage({
					collage_id: this.collage_id
				}).then(res => {
					const {
						shipping_type,
						address_id,
						status,
						uid,
						store_id
					} = res.data.collage;
					this.delivery_type = shipping_type;
					this.address_id = address_id;
					this.status = status;
					this.collage_uid = uid;
					this.where.store_id = store_id;
					this.getnearbyStore();
				});
			},
			// 获取门店信息
			getnearbyStore() {
				try {
					this.where.latitude = uni.getStorageSync('user_latitude');
					this.where.longitude = uni.getStorageSync('user_longitude');
				} catch (e) {
					//TODO handle the exception
				}
				getnearbyStore(this.where).then(res => {
					this.storeInfo = res.data.info;
					// #ifdef H5
					if (this.$wechat.isWeixin()) {
						let configAppMessage = {
							desc: this.storeInfo.detailed_address,
							title: this.storeInfo.name,
							link: window.location.href,
							imgUrl: this.imgHost + '/statics/images/group-text.png',
						};
						this.$wechat.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData', 'onMenuShareAppMessage', 'onMenuShareTimeline'], configAppMessage);
					}
					// #endif
				});
			},
			// 拼单商品信息
			getCollagePartake(callback) {
				this.dialogShow = false;
				if (!callback) {
					uni.showLoading({
						title: '加载中'
					});
				}
				getCollagePartake({
					collage_id: this.collage_id
				}).then(res => {
					this.collagePartake = res.data;
					if (!callback) {
						return uni.hideLoading();
					}
					let is_true_stock = true;
					for (let partakeItem of this.collagePartake) {
						if (!is_true_stock) {
							break;
						}
						for (let goodsItem of partakeItem.goods) {
							is_true_stock = goodsItem.is_true_stock;
							if (!is_true_stock) {
								break;
							}
						}
					}
					if (!is_true_stock) {
						return this.$util.Tips({
							title: '存在库存不足的商品，请检查'
						});
					}
					callback();
				}).catch(() => {
					uni.hideLoading();
				});
			},
			// 清空
			onRemove() {
				emptyCollagePartake({
					collage_id: this.collage_id
				}).then(res => {
					this.$util.Tips({
						title: '清空成功',
						success: () => {
							this.getCollagePartake();
						}
					});
				});
			},
			// 修改、立即选购
			onChange() {
				uni.navigateTo({
					url: `/pages/store/group_cart/index?id=${this.where.store_id}&collage_id=${this.collage_id}&delivery_type=${this.delivery_type}`
				});
			},
			// 跟TA选一样
			onCopy(c_uid) {
				duplicateCollagePartake({
					collage_id: this.collage_id,
					c_uid
				}).then(res => {
					this.$util.Tips({
						title: '跟TA选一样成功',
						success: () => {
							this.getCollagePartake();
						}
					});
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				});
			},
			// 取消拼单
			onCancel() {
				cancelCollage({
					collage_id: this.collage_id
				}).then(res => {
					this.$util.Tips({
						title: '取消拼单成功'
					}, {
						tab: 3
					});
				});
			},
			// 去结算
			onSettle() {
				if (!this.plainPartake.length) {
					return this.$util.Tips({
						title: '暂无人点单'
					});
				}
				settleCollage({
					collage_id: this.collage_id
				}).then(res => {
					uni.navigateTo({
						url: `/pages/goods/order_confirm/index?new=1&collage_id=${this.collage_id}&addressId=${this.address_id}&cartId=${res.data.cartIds}&delivery_type=${this.delivery_type}&store_id=${this.where.store_id}&store_name=${this.storeInfo.name}`
					});
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				});
				// uni.showModal({
				// 	title: '确定要结算吗？',
				// 	content: '结算后，其他人无法再加入或修改商品',
				// 	success: (res) => {
				// 		if (res.confirm) {

				// 			settleCollage({
				// 				collage_id: this.collage_id
				// 			}).then(res => {
				// 				uni.navigateTo({
				// 					url: `/pages/goods/order_confirm/index?new=1&collage_id=${this.collage_id}&addressId=${this.address_id}&cartId=${res.data.cartIds}&delivery_type=${this.delivery_type}&store_id=${this.where.store_id}&store_name=${this.storeInfo.name}`
				// 				});
				// 			}).catch(err => {
				// 				this.$util.Tips({
				// 					title: err
				// 				});
				// 			});
				// 		}
				// 	}
				// });
			},
			goStore() {
				uni.reLaunch({
					url: `/pages/store/home/index?id=${this.where.store_id}`
				})
			},
			goBack() {
				let pages = getCurrentPages();
				if (pages.length > 1) {
					uni.navigateBack();
				} else {
					this.goStore();
				}
			},
			// 刷新
			refresh() {
				this.getCollage();
				this.getCollagePartake();
			},
			onShare() {
				this.H5ShareBox = true;
			},
			setting() {
				this.dialogShow = true;
			}
		},
	}
</script>
<style>
	page {
		padding-bottom: 100rpx;
		padding-bottom: calc(100rpx + constant(safe-area-inset-bottom));
		padding-bottom: calc(100rpx + env(safe-area-inset-bottom));
		background-color: #F5F5F5;
	}
</style>
<style scoped lang="scss">
	.nav-bar {
		position: fixed;
		top: 0;
		right: 0;
		left: 0;
		z-index: 9;
		background-color: var(--view-theme);

		.content {
			position: relative;
			display: flex;
			justify-content: center;
			align-items: center;
			height: 100rpx;
			font-size: 30rpx;
			color: #FFFFFF;
		}

		.button {
			position: absolute;
			top: 0;
			bottom: 0;
			left: 0;
			display: flex;
			align-items: center;
			padding: 0 30rpx;
			background-color: transparent;
			font-size: 24rpx;
			color: #FFFFFF;
		}
	}

	.headerBg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		background: linear-gradient(360deg, #F5F5F5 0%, rgba(245, 245, 245, 0) 100%), linear-gradient(-90deg, var(--view-gradient) 37%, var(--view-theme) 100%);
		background-position: left bottom;
		background-size: 100% 120rpx, 100% 100%;
		background-repeat: no-repeat;

		.inner {
			position: relative;
			height: 346rpx;
		}

		.back {
			position: absolute;
			top: 22rpx;
			right: 36rpx;
			width: 240rpx;
			height: 172rpx;
		}

		.text-box {
			position: absolute;
			bottom: 200rpx;
			left: 48rpx;
			font-size: 24rpx;
			color: rgba(255, 255, 255, 0.8);
		}

		.text-image {
			width: 322rpx;
			height: 46rpx;
			margin-bottom: 20rpx;
		}
	}

	.simple {
		position: relative;
		padding: 32rpx 24rpx;
		border-radius: 24rpx;
		margin: 0 20rpx;
		background: #FFFFFF;

		.seller {
			font-size: 22rpx;
			line-height: 30rpx;
			color: #999999;

			.head {
				margin-bottom: 12rpx;
				color: #666666;

				.headCon {
					flex: 1;
					min-width: 0;
					font-weight: 500;
					font-size: 28rpx;
					line-height: 30rpx;
					color: #333333;
				}
			}

			.tag {
				display: inline-flex;
				flex-wrap: nowrap;
				padding: 0 8rpx;
				border: 1rpx solid var(--view-theme);
				border-radius: 4rpx;
				margin-right: 10rpx;
				font-size: 20rpx;
				height: 30rpx;
				color: var(--view-theme);
				transform: rotateZ(360deg);
				vertical-align: middle;
			}
		}

		.buyer {
			height: 40rpx;
			margin-top: 32rpx;
			font-size: 24rpx;
			color: #888888;

			.avatar-group {
				margin-right: 10rpx;
				font-size: 0;

				.gengduo {
					display: inline-flex;
					justify-content: center;
					align-items: center;
					width: 40rpx;
					height: 40rpx;
					border-radius: 50%;
					margin-left: -12rpx;
					background-color: #EEEEEE;
					vertical-align: middle;

					.iconfont {
						font-size: 26rpx;
						color: #8E8E8E;
					}
				}
			}

			.avatar {
				width: 40rpx;
				height: 40rpx;
				border-radius: 50%;
				vertical-align: middle;

				+.avatar {
					margin-left: -12rpx;
				}
			}

			.btn {
				width: 88rpx;
				height: 32rpx;
				border: 1rpx solid #CCCCCC;
				border-radius: 16rpx;
				margin-left: 20rpx;
				font-size: 20rpx;
				color: #666666;

				.iconfont {
					margin-right: 8rpx;
					font-size: 16rpx;
					color: #666666;
				}
			}
		}

		.btn-box {
			margin-top: 32rpx;

			.btn {
				width: 200rpx;
				height: 72rpx;
				border-radius: 36rpx;
				background: #EEEEEE;
				font-size: 26rpx;
				color: #666666;

				+.btn {
					margin-left: 24rpx;
				}

				&.primary {
					background-color: var(--view-theme);
					color: #FFFFFF;
				}
			}
		}
	}

	.choice {
		.wrapper {
			border-radius: 24rpx;
			margin: 20rpx;
			background-color: #FFFFFF;
		}

		.head {
			height: 108rpx;
			padding: 0 24rpx;

			.name {
				flex: 1;
				min-width: 0;
				padding: 0 20rpx;
				font-size: 26rpx;
				color: #333333;

				.inner {
					display: inline-flex;
					max-width: 100%;
				}

				.nickname {
					flex: 1;
					min-width: 0;
				}

				.tips {
					font-size: 22rpx;
					color: #888888;
				}
			}
		}

		.avatar {
			display: block;
			width: 40rpx;
			height: 40rpx;
			border-radius: 50%;
		}

		.button {
			transform: rotateZ(360deg);
			height: 56rpx;
			padding: 0 24rpx;
			border: 1rpx solid #CCCCCC;
			border-radius: 28rpx;
			font-size: 24rpx;
			line-height: 56rpx;
			color: #333333;

			+.button {
				margin-left: 20rpx;
			}

			&.remove {
				color: #999999;
			}

			&.select {
				border-color: var(--view-theme);
				background-color: var(--view-minorColorT);
				color: var(--view-theme);
			}

			&.primary {
				border-color: var(--view-theme);
				background-color: var(--view-minorColorT);
				color: var(--view-theme);
			}
		}

		.body {
			position: relative;
			padding: 32rpx 24rpx;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #333333;

			&::before {
				content: "";
				position: absolute;
				top: 0;
				right: 24rpx;
				left: 24rpx;
				border-top: 1rpx solid #F5F5F5;
			}

			.text {
				flex: 1;
				min-width: 0;
				padding: 0 20rpx;

				.name {
					font-size: 28rpx;
					color: #333333;
				}

				.attr {
					margin-top: 12rpx;
					font-size: 24rpx;
					color: #999999;
				}
			}
		}

		.item {
			margin-top: 32rpx;

			&:first-child {
				margin-top: 0;
			}

			&.gray {
				background-color: #EEEEEE;
			}
		}

		.image {
			width: 136rpx;
			height: 136rpx;
			border-radius: 16rpx;
		}

		.number {
			margin-top: 10rpx;
			text-align: right;
			color: #999999;
		}

		.total {
			display: flex;
			justify-content: flex-end;
			align-items: center;
			padding: 0 26rpx;
			margin-top: 30rpx;
		}

		.money {
			font-weight: 600;
			color: var(--view-theme);
		}
	}

	.footer {
		position: fixed;
		right: 0;
		bottom: 0;
		left: 0;
		z-index: 9;
		padding-bottom: constant(safe-area-inset-bottom);
		padding-bottom: env(safe-area-inset-bottom);
		background-color: #FFFFFF;

		.footer-wrap {
			padding: 12rpx 32rpx;
		}

		.total {
			flex: 1;
			min-width: 0;
			font-weight: 500;
			font-size: 28rpx;
			color: #333333;
		}

		.money {
			color: var(--view-theme);
		}

		.btn {
			width: 142rpx;
			height: 72rpx;
			border-radius: 36rpx;
			background: linear-gradient(-90deg, var(--view-gradient) 0%, var(--view-theme) 100%);
			font-weight: 500;
			font-size: 26rpx;
			line-height: 72rpx;
			color: #FFFFFF;
		}
	}

	.status {
		padding: 34rpx 0 32rpx;
		border-radius: 14rpx;
		margin: 20rpx 30rpx;
		background-color: #FFFFFF;

		.head {
			display: flex;
			justify-content: center;
			align-items: center;
			font-size: 28rpx;
			line-height: 28rpx;
			color: #333333;
		}

		.body {
			display: flex;

			.item {
				flex: 1;
				min-width: 0;
				margin-top: 40rpx;
				text-align: center;
				font-size: 24rpx;
				line-height: 24rpx;
				color: #888888;

				&:first-child .number::before {
					display: none;
				}

				&.active {
					color: #333333;

					.number {
						border-color: var(--view-theme);
						background-color: var(--view-theme);
						color: #FFFFFF;
					}
				}
			}

			.number {
				position: relative;
				display: inline-flex;
				justify-content: center;
				align-items: center;
				width: 36rpx;
				height: 36rpx;
				border: 1rpx solid #CCCCCC;
				border-radius: 50%;
				margin-bottom: 20rpx;

				&::before {
					content: "";
					position: absolute;
					top: 50%;
					right: 100%;
					width: 160rpx;
					height: 1rpx;
					border-top: 1rpx dashed #D8D8D8;
					margin-right: 18rpx;
				}
			}
		}
	}

	.empty {
		position: relative;
		border-radius: 24rpx;
		margin: 0 20rpx;
		background: #FFFFFF;

		.btn {
			transform: rotateZ(360deg);
			width: 500rpx;
			height: 88rpx;
			border: 1rpx solid var(--view-theme);
			border-radius: 44rpx;
			margin: 108rpx auto 0;
			text-align: center;
			font-weight: 500;
			font-size: 28rpx;
			line-height: 88rpx;
			color: var(--view-theme);
		}
	}

	.share-box {
		z-index: 1000;
		position: fixed;
		left: 0;
		top: 0;
		width: 100%;
		height: 100%;

		image {
			width: 100%;
			height: 100%;
		}
	}

	.dialog {
		.title {
			text-align: center;
			font-weight: 500;
			font-size: 32rpx;
			line-height: 52rpx;
			color: #333333;
		}

		.info {
			margin-top: 24rpx;
			text-align: center;
			font-size: 30rpx;
			line-height: 42rpx;
			color: #666666;
		}

		.btn-box {
			margin-top: 40rpx;
		}

		.btn {
			flex: 1;
			height: 72rpx;
			border-radius: 36rpx;
			border: 1rpx solid var(--view-theme);
			margin-left: 32rpx;
			text-align: center;
			font-weight: 500;
			font-size: 26rpx;
			line-height: 72rpx;
			color: var(--view-theme);
			transform: rotateZ(360deg);

			&:first-child {
				margin-left: 0;
			}

			&.primary {
				background: var(--view-theme);
				color: #FFFFFF;
			}
		}
	}

	.content {
		.top-placeholder {
			height: 194rpx;
		}
	}
</style>