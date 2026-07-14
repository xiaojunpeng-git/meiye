<script>
import { setCouponReceive } from '@/api/api.js';
import { toLogin } from '@/libs/login.js';
import { mapGetters } from 'vuex';
import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
export default {
	props: {
		visible: {
			type: Boolean,
			default: false
		},
		discountInfo: {
			type: Object,
			default: () => {}
		},
		coupon: {
			type: Object,
			default: () => {}
		},
		computedPrice: {
			type: Object,
			default: () => {}
		}
	},
	data() {
		return {
			showGift: false,
			giveProducts: [],
			giveCoupon: [],
			give_integral: 0,
		};
	},
	computed:{
		...mapGetters(['isLogin'])
	},
	filters: {
		typeFilter(val) {
			let obj = {
				0: '通用优惠券',
				1: '品类优惠券',
				2: '商品优惠券',
				3: '品牌优惠券'
			};
			return obj[val];
		}
	},
	components: {
		baseDrawer
	},
	methods: {
		closeDrawer() {
			this.$emit('closeDrawer');
		},
		getCouponUser(item, index) {
			if (item.is_use) {
				this.$emit('closeDrawer');
				return;
			}
			if(!this.isLogin){
				toLogin();
			}else{
			   setCouponReceive(item.id)
			   	.then((res) => {
			   		this.$emit('ChangCouponsUseState', index);
			   		this.$util.Tips({
			   			title: '领取成功'
			   		});
			   	})
			   	.catch((err) => {
			   		this.$util.Tips({
			   			title: err
			   		});
			   	});
			}
		},
		showGiftChange() {
			if (!this.showGift) {
				this.discountInfo.discount.forEach((item) => {
					if (item.promotions_type == 4) {
						this.giveProducts = item.giveProducts;
						this.giveCoupon = item.giveCoupon;
						this.give_integral = item.give_integral;
					}
				});
			}
			this.showGift = !this.showGift;
		},
		promotionPage(item) {
			uni.navigateTo({
				url: `/pages/goods/goods_list/index?promotions_type=${item.promotions_type}&promotions_id=${item.id}`
			});
		}
	}
};
</script>
<template>
	<view>
		<base-drawer mode="bottom" :visible="visible" background-color="transparent" mask maskClosable @close="closeDrawer">		
			<view class="w-full bg--w111-f5f5f5 rd-t-40rpx py-32">
				<view class="text-center fs-32 text--w111-333 fw-500 relative">优惠<text class="iconfont icon-ic_close abs-rt w-36 h-36 rd-50-p111- bg--w111-eee fs-28 acea-row row-center-wrapper right-32 text--w111-999" @tap="closeDrawer"></text></view>
				<view class="mt-48 px-20">
					<scroll-view scroll-y="true" style="height: 800rpx;">
						<view v-if="computedPrice.deduction && computedPrice.deduction.sum_price > computedPrice.deduction.pay_price">
							<view class="flex-center">
								<view class="inline-block p-14 rd-16rpx bg-primary-light text-center">
									<view class="text-primary-con fs-32 lh-32rpx Regular fw-600">¥{{ computedPrice.deduction.pay_price }}</view>
									<view class="fs-20 lh-28rpx text-primary-con pt-6">预估到手</view>
								</view>
							</view>
							<view class="border_bg relative mt-20 flex-center">
								<view class="w-132 h-88 rd-16rpx bg-primary-light flex-col flex-center relative z-10">
									<view class="text-primary-con fs-32 lh-32rpx fw-600 Regular">¥{{ computedPrice.deduction.sum_price }}</view>
									<view class="fs-20 lh-28rpx text-primary-con">商品售价</view>
								</view>
								<view class="reduce" v-if="computedPrice.deduction.first_order_price > 0"></view>
								<view class="w-132 h-88 rd-16rpx bg-primary-light flex-col flex-center relative z-10" v-if="computedPrice.deduction.first_order_price > 0">
									<view class="text-primary-con fs-32 lh-32rpx fw-600 Regular">¥{{ computedPrice.deduction.first_order_price }}</view>
									<view class="fs-20 lh-28rpx text-primary-con">首单优惠</view>
								</view>
								<view class="reduce" v-if="computedPrice.deduction.vip_price > 0"></view>
								<view class="w-132 h-88 rd-16rpx bg-primary-light flex-col flex-center relative z-10" v-if="computedPrice.deduction.vip_price > 0">
									<view class="text-primary-con fs-32 lh-32rpx fw-600 Regular">¥{{ computedPrice.deduction.vip_price }}</view>
									<view class="fs-20 lh-28rpx text-primary-con">会员优惠</view>
								</view>
								<view class="reduce" v-if="computedPrice.deduction.promotions_price > 0"></view>
								<view class="w-132 h-88 rd-16rpx bg-primary-light flex-col flex-center relative z-10" v-if="computedPrice.deduction.promotions_price > 0">
									<view class="text-primary-con fs-32 lh-32rpx fw-600 Regular">¥{{ computedPrice.deduction.promotions_price }}</view>
									<view class="fs-20 lh-28rpx text-primary-con">活动优惠</view>
								</view>
								<view class="reduce" v-if="computedPrice.deduction.coupon_price > 0"></view>
								<view class="w-132 h-88 rd-16rpx bg-primary-light flex-col flex-center relative z-10" v-if="computedPrice.deduction.coupon_price > 0">
									<view class="text-primary-con fs-32 lh-32rpx fw-600 Regular">¥{{ computedPrice.deduction.coupon_price }}</view>
									<view class="fs-20 lh-28rpx text-primary-con" v-if="computedPrice.deduction.coupon_use_min_price == 0">无门槛券</view>
									<view class="fs-20 lh-28rpx text-primary-con" v-else-if="computedPrice.coupon.coupon_type == 1">满{{computedPrice.deduction.coupon_use_min_price}}减{{computedPrice.deduction.coupon_price}}</view>
									<view class="fs-20 lh-28rpx text-primary-con" v-else-if="computedPrice.coupon.coupon_type == 2">满{{computedPrice.deduction.coupon_use_min_price}}享{{computedPrice.deduction.coupon_price}}折</view>
								</view>
							</view>
						</view>
						<view v-if="discountInfo.discount.length">
							<view class="text--w111-333 fs-24 mt-48 lh-34rpx">商品可参与以下优惠促销活动</view>
							<view class="bg--w111-fff rd-24rpx px-24 mt-24">
								<view class="py-28 card-cell" v-for="(item, index) in discountInfo.discount" :key="index">
									<view class="flex-between-center" v-show="item.promotions_type != 4" @tap="promotionPage(item)">
										<view class="flex">
											<view class="flex-y-center px-8 h-38 lh-36rpx rd-8rpx fs-18 con-border text-primary-con">
												{{ item.title }}
											</view>
											<view class="ml-14">
												<view class="fs-24 lh-34rpx w-512 line1">{{ item.desc }}</view>
												<view class="fs-20 lh-28rpx text--w111-666 mt-14" v-show="item.promotions_type == 1">活动时间：{{ item.start_time }} - {{ item.stop_time }}</view>
											</view>
										</view>
										<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999"></text>
									</view>
									<view class="flex-between-center" v-show="item.promotions_type == 4">
										<view class="flex flex-1">
											<view class="flex-y-center px-8 h-38 lh-36rpx rd-8rpx fs-18 con-border text-primary-con">
												{{ item.giveProducts.length + item.giveCoupon.length }}件赠品
											</view>
											<view class="ml-14 flex-1" @tap="showGiftChange">
												<view class="flex-between-center">
													<view class="fs-24 lh-34rpx">下单享赠品（赠完为止）</view>
													<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999"></text>
												</view>
												<view class="flex pt-12" v-if="item.giveProducts.length <= 4">
													<view class="w-84 h-84 rd-8rpx bg--w111-f5f5f5 flex-center mr-16" v-if="item.giveCoupon.length">
														<text class="gold iconfont icon-a-ic_discount1"></text>
													</view>
													<view class="w-84 h-84 rd-8rpx bg--w111-f5f5f5 flex-center mr-16" v-if="item.give_integral">
														<text class="gold iconfont icon-ic_badge11"></text>
													</view>
													<image :src="product.image" mode="" class="w-84 h-84 rd-8rpx block mr-16" v-for="(product, i) in item.giveProducts" :key="i"></image>
												</view>
												<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-562 pt-12" v-else>
													<view class="w-full h-full flex-y-center">
														<view class="inline-block ml-16" v-if="item.giveCoupon.length">
															<view class="w-84 h-84 rd-8rpx bg--w111-f5f5f5 flex-center mr-16">
																<text class="gold iconfont icon-a-ic_discount1"></text>
															</view>
														</view>
														<view class="inline-block ml-16" v-if="item.give_integral">
															<view class="w-84 h-84 rd-8rpx bg--w111-f5f5f5 flex-center mr-16">
																<text class="gold iconfont icon-ic_badge11"></text>
															</view>
														</view>
														<view class="inline-block ml-16" v-for="(product, i) in item.giveProducts" :key="i">
															<image :src="product.image" mode="" class="w-84 h-84 rd-8rpx"></image>
														</view>
													</view>
												</scroll-view>
											</view>
										</view>
									</view>
								</view>
							</view>
						</view>
						<view class="pb-24" v-if="coupon.list.length">
							<view class="text--w111-333 fs-24 mt-48 lh-34rpx">可领取优惠券</view>
							<view v-for="(item, index) in coupon.list" :key="index">
								<view class="mt-24 h-170 bg--w111-fff text-center rd-16rpx flex">
									<view :class="['left-bg', item.category == 1 ? 'bg-gradient' : 'left-svip-bg']">
										<view :class="['left-container w-full h-full relative flex-col flex-between-center py-32', item.category == 1 ? 'text--w111-fff' : 'text--w111-FACC7D']">
											<baseMoney 
											:money="item.coupon_price" 
											symbolSize="28" 
											integerSize="52" 
											decimalSize="28" 
											:color="item.category == 1 ? '#FFFFFF' : '#FACC7D'" 
											isCoupon
											v-if="item.coupon_type == 1"></baseMoney>
											<view v-else class="fs-44 SemiBold">{{ parseFloat(item.coupon_price) / 10 }} <text class="pingfang fs-28 pl-4">折</text></view>
											<text class="fs-24" v-if="item.use_min_price>0">满{{ item.use_min_price }}元可用</text>
											<text class="fs-24" v-else>无门槛券</text>
										</view>
									</view>
									<view class="right-box pt-24 pl-24 pr-14 pb-22 flex-1 flex bg--w111-fff text-left">
										<view class="flex-1 flex-col justify-between">
											<view class="fs-28 fw-500 text--w111-333 lh-40rpx">{{ item.coupon_title }}</view>
											<view class="fs-20 text--w111-666 lh-28rpx" v-if="item.coupon_time">领取后{{ item.coupon_time }}天内可用</view>
											<view class="fs-20 text--w111-666 lh-28rpx" v-else>{{ item.start_time ? item.start_time + '-' : '' }}{{ item.end_time }}</view>
											<view class="fs-20 text--w111-999 lh-28rpx mt-20">
												<text>{{item.type | typeFilter}}</text>
												<!-- <view>
													<text class="pl-8"> | 查看用券规则</text>
													<text class="iconfont icon-ic_rightarrow fs-20 ml-4"></text>
												</view> -->
											</view>
										</view>
										<view
										  :class="['self-center con_btn w-136 h-52 rd-28rpx fs-22 flex-center', item.category == 1 ? 'bg-primary-light text-primary-con' : 'text--w111-FACC7D']"
										  :style="[{background: item.category == 1 ? '' : 'linear-gradient(-90deg, #584834 0%, #32302D 100%)'}]" @tap="getCouponUser(item, index)">
											{{ item.is_use ? '去使用' : '立即领取' }}
										</view>
									</view>
								</view>
							</view>
						</view>
					</scroll-view>
				</view>
			</view>
		</base-drawer>
		<base-drawer mode="bottom" :visible="showGift" background-color="transparent" mask mask-closable @close="showGiftChange">
			<view class="w-full bg--w111-fff rd-40rpx py-32 relative">
				<view class="text-center fs-32 text--w111-333 fw-500">赠品</view>
				<view class="close-icon flex-center" @tap="showGiftChange">
					<text class="iconfont icon-ic_close fs-24 text--w111-999"></text>
				</view>
				<view class="mt-48 px-30">
					<scroll-view scroll-y="true" style="height: 800rpx;">
						<view class="flex-between-center gift-cell" v-for="(item, i) in giveCoupon" :key="i">
							<view class="w-160 h-160 rd-16rpx flex-center bg--w111-f5f5f5">
								<text class="fs-60 gold iconfont icon-a-ic_discount1"></text>
							</view>
							<view class="flex-1 flex-between-center pl-30">
								<view class="w-500 h-160 flex-col justify-between">
									<view class="w-full line2 fs-28 lh-40rpx">{{ item.coupon_title }}</view>
									<view class="fs-22 text--w111-999 lh-30rpx mt-12">数量x1</view>
								</view>
							</view>
						</view>
						<view class="flex-between-center gift-cell" v-if="give_integral">
							<view class="w-160 h-160 rd-16rpx flex-center bg--w111-f5f5f5">
								<text class="fs-60 gold iconfont icon-ic_badge11"></text>
							</view>
							<view class="flex-1 flex-between-center pl-30">
								<view class="w-500 h-160 flex-col justify-between">
									<view class="w-full line2 fs-28 lh-40rpx">赠送积分</view>
									<view class="fs-22 text--w111-999 lh-30rpx mt-12">数量x{{ give_integral }}</view>
								</view>
							</view>
						</view>
						<view class="flex-between-center gift-cell" v-for="(item, i) in giveProducts" :key="i">
							<image :src="item.image" mode="" class="w-160 h-160 rd-16rpx"></image>
							<view class="flex-1 flex-between-center pl-30">
								<view class="w-500 h-160 flex-col justify-between">
									<view class="w-full line2 fs-28 lh-40rpx">{{ item.store_name }}</view>
									<view class="fs-22 text--w111-999 lh-30rpx mt-12">数量x1</view>
								</view>
							</view>
						</view>
					</scroll-view>
				</view>
			</view>
		</base-drawer>
	</view>
</template>
<style lang="scss">
.border_bg {
	width: 100%;
	height: 130rpx;
	border: 1rpx solid var(--view-theme);
	border-radius: 16rpx;
	background-size: 100%;
	&:before {
		content: '';
		position: absolute;
		top: -1rpx;
		left: 50%;
		width: 18rpx;
		height: 18rpx;
		border: 1rpx solid var(--view-theme);
		background-color: #f5f5f5;
		box-sizing: border-box;
		transform: rotate(45deg) translate(-50%);
	}
}
.border_bg:after {
	content: '';
	position: absolute;
	top: 0;
	left: 50%;
	width: 36rpx;
	height: 36rpx;
	background-color: #f5f5f5;
	transform: translateX(-50%);
	// border-bottom-color: #f5f5f5;
	// top: -9px;
}
.left-bg {
	width: 190rpx;
	height: 170rpx;
	background-size: 100%;
	background-repeat: no-repeat;
	border-radius: 24rpx 0 0 24rpx;
}
.left-svip-bg {
	background: linear-gradient(-90deg, #584834 0%, #32302D 100%);
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
	border: 1rpx solid var(--view-theme);
}
.scroll-content {
	
}
.reduce {
	width: 18rpx;
	height: 4rpx;
	background-color: var(--view-theme);
	margin: 0 10rpx;
}
.card-cell ~ .card-cell {
	border-top: 1px solid #eee;
}
.Regular {
	font-family: 'Regular';
}
.gold{
	color: #DCA658;
}
.gift-cell ~ .gift-cell{
	margin-top: 30rpx;
}
.close-icon{
	position: absolute;
	right: 32rpx;
	top: 36rpx;
	width: 36rpx;
	height: 36rpx;
	border-radius: 50%;
	background-color: #eee;
}
</style>