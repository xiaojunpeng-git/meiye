<script>
import countDown from '@/components/countDown';
import { resolveMemberOrderIcon } from '@/utils/memberMenuIcon';
export default {
	components: { countDown },
	inject: ['intoPage', 'goMenuPage', 'getMenuData'],
	props: {
		orderMenu: {
			type: Array,
			default: () => []
		},
		notPayOrder: {
			type: Object,
			default: () => {}
		}
	},
	computed: {
		visibleOrderMenu() {
			return this.orderMenu.filter((item) => item.is_show !== 0);
		}
	},
	methods: {
		goUserSpread() {
			uni.navigateTo({
				url: '/pages/users/user_spread_user/index'
			});
		},
		orderIcon(item) {
			return resolveMemberOrderIcon(item);
		}
	}
};
</script>

<template>
	<view class="">
		<view class="order-card pr-24 pl-24 bg--w111-fff rd-16rpx mt-20 ml-20 mr-20">
			<view class="acea-row row-middle row-between section-header">
				<view>订单中心</view>
				<view class="arrow" @click="intoPage('/pages/goods/order_list/index')">
					查看全部
					<text class="iconfont icon-ic_rightarrow"></text>
				</view>
			</view>
			<view class="acea-row section-content">
				<view v-for="item in visibleOrderMenu" class="item" @click="intoPage(item.url)">
					<view class="order-icon"><text :class="orderIcon(item)" class="iconfont"></text></view>
					<view class="">{{ item.title }}</view>
					<uni-badge class="uni-badge-left-margin" v-if="item.num > 0" :text="item.num"></uni-badge>
				</view>
				<view class="w-full h-120 rd-16rpx bg--w111-f5f5f5 mt-32 p-10 flex-between-center" v-if="notPayOrder" @click="intoPage('/pages/goods/order_list/index?status=0')">
					<view class="flex-y-center">
						<image :src="notPayOrder.img" class="w-100 h-100 rd-12rpx"></image>
						<view class="ml-16">
							<view class="fs-24 lh-34rpx text--w111-333 fw-500">等待付款</view>
							<view class="fs-22 lh-30rpx text--w111-333 mt-12 flex-y-center">
								还剩
								<!-- <text class="text-primary-con SemiBold">23:57:16</text> -->
								<countDown
									:is-day="false"
									tip-text=" "
									day-text=" "
									hour-text=":"
									minute-text=":"
									second-text=" "
									:datatime="notPayOrder.stop_time"
									bgColor="#F5F5F5"
									colors="var(--view-theme)"
									dotColor="var(--view-theme)"
									@endTime="getMenuData"
								></countDown>
								订单自动关闭
							</view>
						</view>
					</view>
					<view class="w-136 h-56 rd-30rpx flex-center fs-24 fw-500 text-primary-con con_border mr-14">去支付</view>
				</view>
			</view>
		</view>
	</view>
</template>

<style lang="scss" scoped>
.order-card {
	border: 1rpx solid rgba(123, 41, 65, .08);
	border-radius: 24rpx;
	box-shadow: 0 12rpx 28rpx rgba(83,45,54,.06);
}
.section-content {
	padding: 48rpx 0 36rpx;

	.item {
		position: relative;
		flex: 1;
		text-align: center;
		font-size: 26rpx;
		line-height: 36rpx;
		color: #4d3037;
		.uni-badge-left-margin {
			position: absolute;
			top: -20rpx;
			right: 26rpx;
			::v-deep  .uni-badge--error {
				background-color: var(--view-theme, #7b2941) !important;
			}
			.uni-badge {
				color: #ffffff;
				border: 2rpx solid #ffffff;
				box-shadow: 0 3rpx 8rpx rgba(123, 41, 65, .18);
				z-index: 29;
			}
		}
	}

	.order-icon {
		width: 68rpx;
		height: 68rpx;
		margin: 0 auto 18rpx;
		border-radius: 20rpx;
		background: linear-gradient(145deg, #fbf3f2, #f2e5e8);
		border: 1rpx solid rgba(123, 41, 65, .08);
		box-shadow: 0 8rpx 16rpx rgba(83, 45, 54, .08);
		display: flex;
		align-items: center;
		justify-content: center;

		.iconfont {
			font-size: 38rpx;
			line-height: 1;
			color: var(--view-theme, #7b2941);
		}
	}
	.con_border {
		color: var(--view-theme);
		border: 1px solid var(--view-theme);
	}
}
.section-header {
	padding: 32rpx 6rpx 0;
	font-weight: 500;
	font-size: 30rpx;
	line-height: 42rpx;
	color: #4d3037;
	.arrow {
		font-weight: 400;
		font-size: 26rpx;
		line-height: 36rpx;
		color: #999999;
	}

	.iconfont {
		font-size: 24rpx;
	}
}

.top {
	position: relative;
	height: 84rpx;
	display: flex;
	justify-content: space-between;
	&::after {
		content: '';
		position: absolute;
		right: 8rpx;
		bottom: 0;
		left: 8rpx;
		height: 1rpx;
		background-color: #eeeeee;
	}

	.item {
		// flex: 1;
		padding: 0 8rpx;
		font-size: 26rpx;
		color: #999999;
	}

	.value {
		margin-left: 8rpx;
		font-size: 28rpx;
		color: #333333;
	}
}
</style>
