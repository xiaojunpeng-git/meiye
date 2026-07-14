<template>
	<view>
		<base-drawer mode="bottom" :visible="coupon.coupon" background-color="transparent" mask maskClosable @close="close">
			<view class="w-full bg--w111-f5f5f5 rd-t-40rpx">
				<view class="flex-around-center fs-30 nav" v-if="coupon.count">
					<view v-if="coupon.count[0]" class="h-100 flex-y-center" :class="coupon.type === 0 ? 'active' : ''" @tap="setType(0)">通用券</view>
					<view v-if="coupon.count[1]" class="h-100 flex-y-center" :class="coupon.type === 1 ? 'active' : ''" @tap="setType(1)">品类券</view>
					<view v-if="coupon.count[2]" class="h-100 flex-y-center" :class="coupon.type === 2 ? 'active' : ''" @tap="setType(2)">商品券</view>
					<view v-if="coupon.count[3]" class="h-100 flex-y-center" :class="coupon.type === 3 ? 'active' : ''" @tap="setType(3)">品牌券</view>
				</view>
				<view class="text-center fs-32 text--w111-333 fw-500 pt-32 relative" v-else>
					优惠券
					<view class="w-36 h-36 rd-50-p111- flex-center close-btn" @tap="close">
						<text class="iconfont icon-ic_close fs-24 text--w111-666"></text>
					</view>
				</view>
				<view class="px-20 scroll-content">
					<scroll-view scroll-y="true" style="max-height: 800rpx">
						<view class="coupon-list" v-if="coupon.list.length">
							<view v-for="(item, index) in coupon.list" :key="index" @tap="goCouponDetails(item)">
								<view class="mt-24 h-170 bg--w111-fff text-center rd-16rpx flex">
									<view class="left-bg" :class="[item.category == 2 ? 'bg-coupon-vip text--w111-FACC7D' : 'bg-gradient text--w111-fff']">
										<view class="left-container w-full h-full relative flex-col flex-between-center py-32">
											<baseMoney :money="item.coupon_price" symbolSize="28" integerSize="52" decimalSize="28" :color="item.category == 2 ? '#FACC7D' : '#FFFFFF'" isCoupon v-if="item.coupon_type == 1"></baseMoney>
											<view v-else class="fs-52">
												{{ parseFloat(item.coupon_price) / 10 }}
												<text class="fs-28">折</text>
											</view>
											<text class="fs-24" v-if="item.use_min_price>0">满{{ item.use_min_price }}元可用</text>
											<text class="fs-24" v-else>无门槛券</text>
										</view>
									</view>
									<view class="right-box pt-24 pl-24 pr-14 pb-22 flex-1 flex-col justify-between bg--w111-fff">
										<view class="flex-y-center">
											<view class="fs-28 fw-500 text--w111-333 lh-40rpx line1 w-360 text-left">{{ item.title }}</view>
										</view>
										<view class="flex-between-center">
											<view class="fs-20 text--w111-666 lh-28rpx" v-if="item.coupon_time">领取后{{ item.coupon_time }}天内可用</view>
											<view class="fs-20 text--w111-666 lh-28rpx" v-else>{{ item.start_time ? item.start_time + '-' : '' }}{{ item.end_time }}</view>
											<view class="con_btn bg-primary-light text-primary-con w-136 h-52 rd-28rpx fs-22 flex-center" v-if="coupon.count" @tap.stop="getCouponUser(index, item.id)">
												{{ item.unused ? '去使用' : '立即领取' }}
											</view>
											<view class="iconfont fs-36 pr-14" :class="" @tap.stop="getCouponUser(index, item.id)" v-else>
												<view class="iconfont icon-a-ic_CompleteSelect font-color" :class="item.receive_type === 4 ? 'svip' : 'font-num'" v-if="item.is_use"></view>
												<view class="iconfont icon-ic_unselect text--w111-ccc" v-else></view>
											</view>
										</view>
										<view class="flex-y-center fs-20 text--w111-999 lh-28rpx mt-20">
											<text>{{ item.type | typeFilter }}</text>
											<view>
												<text class="pl-8 fs-20">| 查看用券规则</text>
												<text class="iconfont icon-ic_rightarrow fs-20 ml-4"></text>
											</view>
										</view>
									</view>
								</view>
							</view>
							<view class="h-200"></view>
						</view>
						<view class="mt-28" v-else>
							<emptyPage title="暂无优惠券" src="/statics/images/noCoupon.gif"></emptyPage>
						</view>
					</scroll-view>
				</view>
			</view>
		</base-drawer>
	</view>
</template>

<script>
import { setCouponReceive } from '@/api/api.js';
import { HTTP_REQUEST_URL } from '@/config/app';
import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
import dayjs from '@/plugin/dayjs/dayjs.min.js';
import emptyPage from '@/components/emptyPage.vue';
export default {
	filters: {
		dateFormat: function (value) {
			return dayjs(value * 1000).format('YYYY/MM/DD');
		},
		typeFilter(val) {
			let obj = {
				0: '通用券',
				1: '品类券',
				2: '商品券',
				3: '品牌券'
			};
			return obj[val];
		}
	},
	props: {
		//打开状态 0=领取优惠券,1=使用优惠券
		openType: {
			type: Number,
			default: 0
		},
		coupon: {
			type: Object,
			default: function () {
				return {};
			}
		}
	},
	components: {
		baseDrawer,
		emptyPage
	},
	data() {
		return {
			type: 0,
			imgHost: HTTP_REQUEST_URL
		};
	},
	methods: {
		close: function () {
			this.$emit('ChangCouponsClone');
			this.type = 0;
		},
		getCouponUser: function (index, id) {
			let that = this;
			let list = that.coupon.list;
			if (list[index].unused) {
				that.$emit('ChangCoupons', 0);
				return;
			}
			if (list[index].is_use == true && this.openType == 0) {
				that.$emit('ChangCoupons', 0);
				return;
			}
			switch (this.openType) {
				case 0:
					//领取优惠券
					setCouponReceive(id)
						.then((res) => {
							that.$emit('ChangCouponsUseState', index);
							that.$util.Tips({
								title: '领取成功'
							});
						})
						.catch((err) => {
							uni.showToast({
								title: err,
								icon: 'none'
							});
						});
					break;
				case 1:
					that.$emit('ChangCoupons', index);
					break;
			}
		},
		setType: function (type) {
			this.type = type;
			this.$emit('tabCouponType', type);
		},
		goCouponDetails(item){
			uni.navigateTo({
				url: `/pages/activity/coupon_details/index?id=${item.cid || item.id}`
			})
		},
	}
};
</script>

<style scoped lang="scss">
.nav {
	height: 100rpx;
	border-bottom: 1px solid #eee;
}
.active {
	border-bottom: 1px solid var(--view-theme);
	color: var(--view-theme);
}
.left-bg {
	width: 190rpx;
	height: 170rpx;
	background-size: 100%;
	background-repeat: no-repeat;
	border-radius: 24rpx 0 0 24rpx;
}
.right-box {
	border-radius: 0 24rpx 24rpx 0;
}
.left-container {
	background: radial-gradient(circle at 0px 84rpx, #f5f5f5 12rpx, transparent 0px) top;
	&:after {
		content: '';
		position: absolute;
		top: 0;
		right: 0px;
		width: 6rpx;
		height: 100%;
		background-image: radial-gradient(circle at 6rpx 12rpx, #ffffff 6rpx, transparent 6rpx);
		background-size: 6rpx 18rpx;
	}
}
.text-primary-con {
	color: var(--view-theme);
}
.bg-primary-light {
	background: var(--view-minorColorT);
}
.con-border {
	border: 1px solid var(--view-theme);
}
.scroll-content {
	height: 800rpx;
	.coupon-list{
		margin-bottom: 120rpx;
	}
}
.close-btn {
	position: absolute;
	right: 32rpx;
	top: 36rpx;
	background-color: #eee;
}
</style>
