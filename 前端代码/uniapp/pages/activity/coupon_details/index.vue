<template>
	<view :style="colorStyle" :class="{'vip-coupon': couponData.category == 2}">
        <!-- 旧分享链接仅负责交接凭据；首页承载授权、领取与成功弹层。 -->
        <view v-if="shareToken" class="gift-redirect" role="status">正在进入商城首页…</view>
		<view v-if="!shareToken" :style="[headerStyle]" class="relative">
			<image :src="`${imgHost}/statics/images/coupon_details.png`" mode="aspectFill" class="abs-lt w-full h-full"></image>
			<view class="abs-lb w-full h-120 shadow-bar"></view>
			<!-- #ifndef H5 -->
			<NavBar :iconColor="iconColor" :textColor="iconColor" :isScrolling="isScrolling" showBack></NavBar>
			<!-- #endif -->
			<view class="relative flex h-394">
				<view class="w-480 pt-42 pr-32 pl-52">
					<view class="fw-500 fs-28">{{couponData.coupon_title}}</view>
					<view class="mt-20">
						<BaseMoney v-if="couponData.coupon_type == 1" :money="couponData.coupon_price" color="#333333" symbolSize="28" integerSize="64" decimalSize="64" isCoupon></BaseMoney>
						<view v-else-if="couponData.coupon_type==2" class="SemiBold fs-64">{{Number(couponData.coupon_price) / 10}}<text class="fs-28">折</text></view>
					</view>
					<view :class="['inline-flex flex-y-center h-44 px-12 rd-6rpx mt-12 fs-22', couponData.category == 1 ? 'text-w111-theme' : 'text-w111-vip']" :style="[timeStyle]">
						<text v-if="couponData.unused">有效期至：{{couponData.endTime}}</text>
						<text v-else-if="couponData.coupon_time">领取后{{couponData.coupon_time}}天内有效</text>
						<text v-else>有效期至：{{couponData.end_use_time}}</text>
					</view>
				</view>
				<view class="pt-40 pl-10">
					<view class="ticket w-194 rd-16rpx fs-24 text--w111-fff">
						<view class="ticket-top flex-center h-118 relative z-4">
							<BaseMoney v-if="couponData.coupon_type == 1" :money="couponData.coupon_price" :color="couponData.category == 2 ? '#FACC7D' : '#FFFFFF'" symbolSize="28" integerSize="64" decimalSize="64" isCoupon></BaseMoney>
							<view v-else-if="couponData.coupon_type == 2" class="SemiBold fs-64">{{Number(couponData.coupon_price) / 10}}<text class="fs-28">折</text></view>
						</view>
						<view class="flex-center h-68 relative z-4" v-if="couponData.use_min_price != 0">满{{Number(couponData.use_min_price)}}可用</view>
						<view class="flex-center h-68 relative z-4" v-else>无门槛</view>
					</view>
					<view class="w-146 h-4 m-auto mt-20 filter-5" :style="{backgroundColor: couponData.category == 2 ? '#8F5805' : 'var(--view-theme)'}"></view>
				</view>
			</view>
		</view>
		<view v-if="!shareToken" class="relative px-20 mt-f120">
			<view class="p-32 rd-16rpx bg--w111-fff">
				<view class="fw-500">优惠券说明</view>
				<view class="flex-y-center mt-32 fs-28">
					<view class="w-144 text--w111-999">适用范围</view>
					<view v-if="!couponData.coupon_issue_type && !couponData.applicable_type" class="flex-y-center">仅平台适用</view>
					<view v-else class="flex-y-center">指定门店<view :class="['pl-8', couponData.category == 1 ? 'text-w111-theme' : 'text-w111-vip']" @tap="openStore">查看<text class="iconfont icon-ic_rightarrow fs-28"></text></view>
					</view>
				</view>
				<view class="flex-y-center mt-32 fs-28">
					<view class="w-144 text--w111-999">适用商品</view>
					<view class="flex-y-center">部分商品<view :class="['pl-8', couponData.category == 1 ? 'text-w111-theme' : 'text-w111-vip']" @tap="useCoupon(couponData)">查看<text class="iconfont icon-ic_rightarrow fs-28"></text></view>
					</view>
				</view>
				<view class="flex-y-center mt-32 fs-28">
					<view class="w-144 text--w111-999">领取时间</view>
					<view v-if="couponData.start_time" class="flex-1">{{couponData.start_time}}-{{couponData.end_time}}</view>
					<view v-else class="flex-1">不限时</view>
				</view>
				<view class="flex-y-center mt-32 fs-28">
					<view class="w-144 text--w111-999">使用时间</view>
					<view v-if="couponData.coupon_time" class="flex-1">领取后{{couponData.coupon_time}}天内有效</view>
					<view v-else class="flex-1">在{{couponData.start_use_time}}-{{couponData.end_use_time}}内可以使用</view>
				</view>
			</view>
            <view v-if="transferInfo" class="p-32 rd-16rpx mt-20 bg--w111-fff">
                <view class="fw-500">赠送给好友</view>
                <!-- 转赠按钮复用“去使用”的高度、圆角和主题渐变，保留各端原有入口。 -->
                <view class="gift-actions">
                    <view role="button" tabindex="0" class="flex-1 flex-center h-88 rd-44rpx fw-500 fs-28 text--w111-fff btn" @tap="phoneTransfer">手机号转赠</view>
                    <!-- #ifdef MP-WEIXIN -->
                    <button open-type="share" :disabled="preparingShare || !outgoingToken" class="flex-1 flex-center h-88 rd-44rpx fw-500 fs-28 text--w111-fff btn gift-share-button">{{ preparingShare ? '准备中…' : '转赠微信好友' }}</button>
                    <!-- #endif -->
                </view>
                <view class="mt-20 fs-24 text--w111-999">每张券仅可转赠一次，有效期不延长。好友完成手机号授权后领取。</view>
                <!-- #ifdef MP-WEIXIN -->
                <view v-if="sharePrepareError" class="mt-20 fs-24 text--w111-999" role="status" @tap="prepareShare">{{ sharePrepareError }}，点此重试</view>
                <!-- #endif -->
            </view>
			<view class="p-32 rd-16rpx mt-20 bg--w111-fff">
				<view class="fw-500">使用说明</view>
				<view class="mt-32">
					<text>{{couponData.rule}}</text>
				</view>
			</view>
		</view>
		<view class="h-128" v-if="!shareToken && (couponData.is_claimed || couponData.quantity_count - couponData.used.length || couponData.unused)"></view>
		<view class="pb-safe"></view>
		<view class="fixed-lb w-full bg--w111-fff" v-if="!shareToken && (couponData.is_claimed || couponData.quantity_count - couponData.used.length || couponData.unused)">
			<view class="flex-y-center h-128 px-20">
				<view v-if="couponData.unused" class="flex-1 flex-center h-88 rd-44rpx fw-500 fs-28 text--w111-fff btn" @tap="useCoupon(couponData)">去使用</view>
				<view v-else-if="!from && (couponData.is_claimed || couponData.quantity_count - couponData.used.length)" class="flex-1 flex-center h-88 rd-44rpx fw-500 fs-28 text--w111-fff btn" @tap="getCoupon">立即领取</view>
			</view>
			<view class="pb-safe"></view>
		</view>
		<base-drawer mode="bottom" :visible="isStore" :zIndex="22" :maskZIndex="21" backgroundColor="transparent" @close="closeStore">
			<view class="product-window store">
				<view class="relative flex-center h-108 fw-500 fs-32">查看门店<text class="iconfont icon-ic_close2 text--w111-eee fs-36" @tap="closeStore"></text></view>
				<scroll-view scroll-y="true" class="scroll-view">
					<view class="storeList px-20">
						<view class="item flex-y-center h-200 pr-32 pl-24 rd-24rpx mb-20 bg--w111-fff" v-for="(item,index) in storeList" :key="item.id" @tap="tapStore(index,item)">
							<view class="flex-1 min-w-0">
								<view class="fw-500 fs-28 line1">{{item.name}}</view>
								<view class="mt-20 fs-24 text--w111-999 line1">{{item.address}}</view>
								<view class="mt-16 fs-24 text--w111-999">营业时间：{{item.day_time}}</view>
							</view>
							<view class="fs-24"><text class="iconfont icon-ic_location4 fs-26 mr-4"></text>{{item.range}}km</view>
						</view>
					</view>
				</scroll-view>
			</view>
		</base-drawer>
	</view>
</template>

<script>
	import {
		mapGetters
	} from 'vuex';
	import colors from '@/mixins/color.js';
	import NavBar from '@/components/NavBar';
	import baseDrawer from '@/components/tui-drawer/tui-drawer.vue'
	import {
		couponsDetailApi,
		couponsApplicableApi,
		setCouponReceive, getCouponTransferInfo, createCouponShare,
	} from '@/api/api.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';

	export default {
		components: {
			NavBar,
			baseDrawer,
		},
		mixins: [colors],
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				iconColor: '#000000',
				isScrolling: false,
				id: 0,
				colorObj: {},
				couponData: {
					category: 1,
					used: [],
				},
				isStore: false,
				storeList: [],
				from: '',
				usedId: 0, transferInfo: null, outgoingToken: '', preparingShare: false, preparedCouponId: 0, sharePrepareError: '',
                shareToken: '',
			}
		},
		computed: {
			...mapGetters(['isLogin']),
			headerStyle() {
				if (!Object.keys(this.colorObj).length) {
					return {};
				}
				if (this.couponData.category == 1) {
					return {
						'background': `linear-gradient(-90deg, ${this.hexToRgba(this.colorObj['--view-gradient'], 0.4)} 0%, ${this.hexToRgba(this.colorObj['--view-theme'], 0.4)} 100%)`
					};
				}
				return {
					'background': 'linear-gradient(135deg, rgba(224, 165, 88, 0.4) 0%, rgba(255, 195, 117, 0.4) 100%)'
				};
			},
			timeStyle() {
				if (!Object.keys(this.colorObj).length) {
					return {};
				}
				if (this.couponData.category == 1) {
					return {
						'background': this.hexToRgba(this.colorObj['--view-theme'], 0.06)
					};
				}
				return {
					'background': 'rgba(155, 97, 22, 0.06)'
				};
			}
		},
		onLoad(option) {
            this.shareToken = option.share_token || '';
            if (this.shareToken) {
                // switchTab 不携带查询参数，短暂保存随机凭据；不保存手机号或会员资料。
                uni.setStorageSync('pendingCouponGiftToken', this.shareToken);
                uni.switchTab({ url: '/pages/index/index' });
                return;
            }
			this.id = option.id;
			this.usedId = option.used_id;
			this.from = option.from || this.from;
			this.getCouponsDetail();
		},
        onShow() {
            if (!this.shareToken && this.usedId && this.id) this.loadTransferInfo();
        },
        // 只分享服务端签发的随机凭据，不把手机号、UID或金额放进分享地址。
        onShareAppMessage(event) {
            if (!event || event.from !== 'button' || !this.outgoingToken) return { title: this.couponData.coupon_title || '优惠券', path: '/pages/activity/coupon_details/index?id=' + this.id };
            return { title: '送你一张' + (this.transferInfo ? this.transferInfo.title : '优惠券'),
                path: '/pages/activity/coupon_details/index?share_token=' + encodeURIComponent(this.outgoingToken) };
        },
		onReady() {
			let colorObj = {};
			const colorList = this.colorStyle.split(';');
			colorList.forEach((item) => {
				const colorCell = item.split(':');
				colorObj[colorCell[0].trim()] = colorCell[1].trim();
			});
			this.colorObj = colorObj;
		},
		methods: {
            /** 只展示这张已领券的真实转赠资格，不能以同类券替代已使用的实例。 */
            async loadTransferInfo() {
                this.transferInfo = null;
                if (!this.isLogin || !this.usedId || this.from !== 'user') return;
                try {
                    const res = await getCouponTransferInfo(this.usedId); this.transferInfo = res.data;
                    // 原生分享必须由本次用户点击直接触发；先备好凭据，不增加第二个发送按钮。
                    // #ifdef MP-WEIXIN
                    await this.prepareShare();
                    // #endif
                } catch (error) { this.outgoingToken = ''; /* 不可转赠的券只保留详情。 */ }
            },
            phoneTransfer() {
                if (!this.transferInfo) return;
                const c = this.transferInfo;
                uni.navigateTo({ url: '/pages/users/coupon_transfer/index?coupon_user_id=' + c.coupon_user_id
                    + '&title=' + encodeURIComponent(c.title) + '&coupon_price=' + encodeURIComponent(c.coupon_price)
                    + '&use_min_price=' + encodeURIComponent(c.use_min_price) + '&end_time=' + encodeURIComponent(c.end_time) });
            },
            async prepareShare() {
                if (!this.transferInfo || this.preparingShare) return;
                const couponId = Number(this.usedId);
                if (this.outgoingToken && this.preparedCouponId === couponId) return;
                this.outgoingToken = ''; this.preparingShare = true; this.sharePrepareError = '';
                try {
                    const res = await createCouponShare({ coupon_user_id: couponId });
                    if (Number(this.usedId) !== couponId) return;
                    this.outgoingToken = res.data.token; this.preparedCouponId = couponId;
                } catch (error) { this.sharePrepareError = error.msg || '暂时无法准备转赠'; }
                finally { this.preparingShare = false; }
            },
			getCouponsDetail() {
				couponsDetailApi(this.id).then(res => {
					const data = res.data;
					data.unused = data.used.some((value) => value.status == '未使用');
					const unusedList = data.used.filter((value) => value.status == '未使用');
					if (unusedList.length) {
						const currentUnused = unusedList.find((value) => value.id == this.usedId);
						data.endTime = currentUnused ? currentUnused.end_time : unusedList[0].end_time;
					}
					this.couponData = data;
                    this.loadTransferInfo();
				});
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
					url = `/pages/goods/goods_list/index?title=默认&couponId=${item.id}&couponIssueType=${item.coupon_issue_type}&useMinPrice=${useMin}`;
				}
				// 品类券
				if (item.category_id != 0) {
					if (item.category_type == 1) {
						url = `/pages/goods/goods_list/index?cid=${item.category_id}&title=${item.coupon_title}&couponId=${item.id}&couponIssueType=${item.coupon_issue_type}&useMinPrice=${useMin}`;
					} else {
						url = `/pages/goods/goods_list/index?sid=${item.category_id}&title=${item.coupon_title}&couponId=${item.id}&couponIssueType=${item.coupon_issue_type}&useMinPrice=${useMin}`;
					}
				}
				//商品券
				if (item.product_id != '') {
					let arr = item.product_id.split(',');
					let num = arr.length;
					if (num == 1) {
						url = `/pages/goods_details/index?id=${item.product_id}`;
					} else {
						url = `/pages/goods/goods_list/index?title=默认&productId=${item.product_id}&couponId=${item.id}&couponIssueType=${item.coupon_issue_type}&useMinPrice=${useMin}`;
					}
				}
				//品牌券
				if (item.brand_id != 0) {
					url = `/pages/goods/goods_list/index?title=默认&brandId=${item.brand_id}&couponId=${item.id}&couponIssueType=${item.coupon_issue_type}&useMinPrice=${useMin}`;
				}
				uni.navigateTo({
					url
				});
			},
			closeStore() {
				this.isStore = false;
			},
			tapStore(index, item) {
				this.isStore = false;
				uni.navigateTo({
					url: `/pages/store/home/index?id=${item.id}`
				})
			},
			// 指定门店
			openStore() {
				let store_ids = '';
				// 门店优惠券
				if (this.couponData.coupon_issue_type) {
					store_ids = this.couponData.relation_id;
				} else if (this.couponData.applicable_type == 2) {
					// 适用部分门店
					store_ids = this.couponData.applicable_store_id.join();
				}
				// #ifdef H5
				if (this.$wechat.isWeixin()) {
					this.$wechat.location().then(res => {
						this.getStoreList({
							store_ids,
							latitude: res.latitude,
							longitude: res.longitude,
						});
					});
				} else {
					uni.getLocation({
						success: (res) => {
							this.getStoreList({
								store_ids,
								latitude: res.latitude,
								longitude: res.longitude,
							});
						}
					});
				}
				// #endif
				// #ifdef MP
				uni.authorize({
					scope: 'scope.userLocation',
					success: () => {
						uni.getLocation({
							success: (res) => {
								this.getStoreList({
									store_ids,
									latitude: res.latitude,
									longitude: res.longitude,
								});
							}
						});
					},
					fail: () => {
						this.getStoreList({
							store_ids,
							latitude: 0,
							longitude: 0,
						});
					}
				});
				// #endif
				// #ifdef APP-PLUS
				uni.getLocation({
					success: (res) => {
						this.getStoreList({
							store_ids,
							latitude: res.latitude,
							longitude: res.longitude,
						});
					}
				});
				// #endif
			},
			getStoreList(data) {
				couponsApplicableApi(data).then(res => {
					this.storeList = res.data.list;
					this.isStore = true;
				})
			},
			// 立即领取
			getCoupon() {
				if (!this.isLogin) {
					toLogin();
					return;
				}
				// 领取优惠券
				setCouponReceive(this.id).then(res => {
					uni.$emit('updateCoupon', {
						id: this.id,
					});
					this.$util.Tips({
						title: '领取成功'
					});
					this.getCouponsDetail();
				}).catch(error => {
					this.$util.Tips({
						title: error
					});
				});
			},
			hexToRgba(hex, alpha = 1) {
				hex = hex.replace(/^#/, '');
				if (hex.length === 3) {
					hex = hex.split('').map(char => char + char).join('');
				}
				let r = parseInt(hex.slice(0, 2), 16),
					g = parseInt(hex.slice(2, 4), 16),
					b = parseInt(hex.slice(4, 6), 16);

				// 返回rgba字符串
				return `rgba(${r}, ${g}, ${b}, ${alpha})`;
			}
		},
	}
</script>

<style lang="scss" scoped>
	::v-deep  .base-money .symbol {
		font-weight: 600;
	}

	.shadow-bar {
		background: linear-gradient(360deg, #F5F5F5 0%, rgba(245, 245, 245, 0) 100%);
	}

	.ticket {
		position: relative;
		border-radius: 16rpx;
		overflow: hidden;
	}

	.ticket::before {
		content: "";
		position: absolute;
		top: 0;
		left: 0;
		width: 182rpx;
		height: 186rpx;
		background: radial-gradient(circle at left 118rpx, transparent 12rpx, var(--view-theme) 12rpx)
	}

	.ticket::after {
		content: "";
		position: absolute;
		top: 0;
		right: 0;
		width: 182rpx;
		height: 186rpx;
		// background: radial-gradient(circle at right 118rpx, transparent 12rpx, var(--view-theme) 12rpx)
		background: radial-gradient(circle at right 118rpx, transparent 12rpx, var(--view-gradient) 12rpx, var(--view-theme) 70%)
	}

	.ticket-top::after {
		content: "";
		position: absolute;
		right: 12rpx;
		bottom: 0;
		left: 12rpx;
		z-index: 2;
		border-bottom: 1px dashed rgba(255, 255, 255, 0.2);
	}

	.btn {
		background: linear-gradient(-90deg, var(--view-gradient) 0%, var(--view-theme) 100%);
	}

	.product-window {
		padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
		padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/

		&.store {
			background-color: #F5F5F5;
			border-radius: 40rpx 40rpx 0 0;
		}

		.icon-ic_close2 {
			position: absolute;
			right: 32rpx;
		}

		.scroll-view {
			max-height: 690rpx;
		}

		.storeList {
			.item {
				border: 1px solid #fff;
				position: relative;

				&.on {
					border-color: var(--view-theme);
				}
			}
		}
	}

	.vip-coupon {
		.text-w111-vip {
			color: #9B6116;
		}

		.ticket {
			// background: linear-gradient(-90deg, #705838 0%, #32302D 100%);
			color: #FACC7D;
		}

		.ticket::before {
			content: "";
			position: absolute;
			top: 0;
			left: 0;
			width: 182rpx;
			height: 186rpx;
			background: radial-gradient(circle at left 118rpx, transparent 12rpx, #32302D 12rpx)
		}

		.ticket::after {
			content: "";
			position: absolute;
			top: 0;
			right: 0;
			width: 182rpx;
			height: 186rpx;
			// background: radial-gradient(circle at right 118rpx, transparent 12rpx, var(--view-theme) 12rpx)
			background: radial-gradient(circle at right 118rpx, transparent 12rpx, #705838 12rpx, #32302D 70%)
		}

		.ticket-top::after {
			content: "";
			position: absolute;
			right: 12rpx;
			bottom: 0;
			left: 12rpx;
			z-index: 2;
			border-bottom: 1px dashed rgba(250, 204, 125, 0.2);
		}

		.btn {
			background: linear-gradient(-90deg, #665136 0%, #32302D 100%);
			color: #FACC7D;
		}
	}
</style>
<style scoped>
.gift-redirect{padding:64rpx 32rpx;text-align:center;color:#777}
.gift-actions{display:flex;gap:20rpx;margin-top:24rpx}.gift-actions > view{min-width:0;white-space:nowrap;}
</style>

<style scoped>
/* 原生 share 按钮消除框架默认边框和行高，外观与同排“去使用”口径保持一致。 */
.gift-share-button{margin:0;padding:0;height:88rpx;line-height:normal;border:0;min-width:0;white-space:nowrap;font-size:28rpx;}
.gift-share-button::after{border:0;}
</style>
