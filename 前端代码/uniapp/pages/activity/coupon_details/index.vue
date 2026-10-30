<template>
	<view :style="colorStyle" :class="{'vip-coupon': couponData.category == 2}">
        <!-- 分享领取先完成标准手机号授权；成功卡片展示真实券用途和有效期。 -->
        <view v-if="shareToken" class="gift-page">
            <view class="gift-card">
                <view class="gift-seal">礼</view>
                <view class="gift-heading">{{ received ? '好友的心意，已送达' : '好友送你一张优惠券' }}</view>
                <view v-if="received" class="gift-title">获得一张{{ received.title }}</view>
                <view v-if="received" class="gift-purpose">{{ received.description }}</view>
                <view v-if="received" class="gift-rule">{{ received.rule || received.scope_notice }}</view>
                <view v-if="received" class="gift-expiry">有效期至 {{ received.end_time }}</view>
                <view v-if="giftError" class="gift-rule" role="status">{{ giftError }}</view>
                <view v-if="claiming" class="gift-rule">正在接收好友的心意…</view>
                <button v-if="received" class="gift-button" @tap="viewReceived">查看优惠券</button>
                <button v-else-if="!claiming" class="gift-button" @tap="receiveGift">{{ isLogin ? '重新领取' : '授权手机号并领取' }}</button>
            </view>
        </view>
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
                <view class="gift-actions"><button @tap="phoneTransfer">手机号转赠</button>
                    <!-- #ifdef MP-WEIXIN -->
                    <button :disabled="preparingShare" @tap="prepareShare">分享转赠</button>
                    <!-- #endif -->
                </view>
                <view class="mt-20 fs-24 text--w111-999">每张券仅可转赠一次，有效期不延长。好友完成手机号授权后领取。</view>
                <view v-if="outgoingToken" class="mt-20">
                    <button open-type="share" class="gift-button">发送给微信好友</button>
                    <view class="mt-12 fs-24 text--w111-999">第一个领取成功的好友获得此券。</view>
                </view>
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
		setCouponReceive, getCouponTransferInfo, createCouponShare, claimCouponShare,
	} from '@/api/api.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
    import { getUserInfo } from '@/api/user.js';

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
				usedId: 0, transferInfo: null, outgoingToken: '', preparingShare: false,
                shareToken: '', received: null, giftError: '', claiming: false,
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
            if (this.shareToken) { this.receiveGift(); return; }
			this.id = option.id;
			this.usedId = option.used_id;
			this.from = option.from || this.from;
			this.getCouponsDetail();
		},
        onShow() {
            if (this.shareToken && this.isLogin && !this.received) this.receiveGift();
            else if (this.usedId && this.id) this.loadTransferInfo();
        },
        // 只分享服务端签发的随机凭据，不把手机号、UID或金额放进分享地址。
        onShareAppMessage() {
            if (!this.outgoingToken) return { title: this.couponData.coupon_title || '优惠券', path: '/pages/activity/coupon_details/index?id=' + this.id };
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
                this.transferInfo = null; this.outgoingToken = '';
                if (!this.isLogin || !this.usedId || this.from !== 'user') return;
                try { const res = await getCouponTransferInfo(this.usedId); this.transferInfo = res.data; }
                catch (error) { /* 已使用或不可转赠的券保留详情，不显示赠送入口。 */ }
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
                this.preparingShare = true;
                try { const res = await createCouponShare({ coupon_user_id: this.usedId }); this.outgoingToken = res.data.token; }
                catch (error) { uni.showToast({title: error.msg || '暂时无法分享',icon:'none'}); }
                finally { this.preparingShare = false; }
            },
            /** 返回深链贯穿手机号授权；领取由服务端事务保证只发生一次。 */
            async receiveGift() {
                if (this.claiming || this.received) return;
                if (!this.isLogin) { toLogin(false, undefined, '/pages/activity/coupon_details/index?share_token=' + this.shareToken); return; }
                this.claiming = true; this.giftError = '';
                try {
                    const profile = await getUserInfo();
                    if (!profile.data.phone) { toLogin(false, undefined, '/pages/activity/coupon_details/index?share_token=' + this.shareToken); return; }
                    const res = await claimCouponShare(this.shareToken); this.received = res.data;
                }
                catch (error) { this.giftError = error.msg || '优惠券领取失败，请稍后重试'; }
                finally { this.claiming = false; }
            },
            viewReceived() {
                this.id = this.received.coupon_id; this.usedId = this.received.coupon_user_id;
                this.from = 'user'; this.shareToken = ''; this.getCouponsDetail(); this.loadTransferInfo();
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
.gift-page{min-height:100vh;padding:100rpx 36rpx;background:linear-gradient(160deg,#fff7ee,#f4e5ec);box-sizing:border-box}
.gift-card{padding:64rpx 36rpx;background:#fff;border-radius:32rpx;text-align:center;box-shadow:0 18rpx 60rpx #7d33451a}
.gift-seal{width:100rpx;height:100rpx;line-height:100rpx;margin:0 auto 32rpx;border-radius:50%;background:#f8e5c7;color:#8b6432;font-size:48rpx}
.gift-heading{color:#8c6a57;font-size:26rpx}.gift-title{font-size:38rpx;font-weight:600;margin:28rpx 0;color:#782c43}
.gift-purpose{font-size:30rpx;color:#782c43;line-height:1.7}.gift-rule{font-size:26rpx;color:#777;margin-top:24rpx;line-height:1.8;white-space:pre-wrap}.gift-expiry{font-size:23rpx;color:#999;margin-top:28rpx}
.gift-button{margin-top:36rpx;background:#7e3048;color:#fff;border-radius:48rpx;font-size:28rpx}.gift-actions{display:flex;gap:20rpx;margin-top:24rpx}.gift-actions button{flex:1;font-size:26rpx;color:#7e3048;background:#fbf0f3;border-radius:36rpx}
</style>
