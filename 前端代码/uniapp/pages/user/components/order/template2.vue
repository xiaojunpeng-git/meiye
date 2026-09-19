<script>
import countDown from '@/components/countDown';
import { resolveMemberOrderIcon } from '@/utils/memberMenuIcon';
export default {
	components: { countDown },
	inject: ['intoPage', 'goMenuPage'],
	props: {
		orderMenu: {
			type: Array,
			default: () => []
		},
		orderStyle: {
			type: Number | String,
			default: 0
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
		orderIcon(item) {
			return resolveMemberOrderIcon(item);
		}
	}
};
</script>

<template>
	<view class="">
		<view class="pt-34 pr-24 pb-32 pl-24 bg--w111-fff rd-16rpx mt-20 order-wrapper ml-20 mr-20">
			<view class="flex-between-center">
				<text class="fs-30 fw-500 lh-42rpx text--w111-333">订单中心</text>
				<view class="flex-y-center text--w111-999" @click="intoPage('/pages/goods/order_list/index')">
					<text class="fs-26 lh-26rpx">查看全部</text>
					<text class="iconfont icon-ic_rightarrow fs-24"></text>
				</view>
			</view>
			<view class="order-status-list flex-between-center mt-30" :class="{ theme: orderStyle == 2, 'is-four': visibleOrderMenu.length === 4 }">
				<view class="w-128 flex-col flex-center item" v-for="item in visibleOrderMenu" :key="item.title" @click="intoPage(item.url)">
					<view class="order-icon"><text :class="orderIcon(item)" class="iconfont"></text></view>
					<text class="fs-26 lh-36rpx text--w111-282828 pt-22">{{ item.title }}</text>
					<uni-badge class="uni-badge-left-margin" v-if="item.num > 0" :text="item.num"></uni-badge>
				</view>
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
</template>

<style lang="scss" scoped>
.order-wrapper {
	border: 1rpx solid rgba(123, 41, 65, .08);
	border-radius: 24rpx;
	box-shadow: 0 12rpx 28rpx rgba(83,45,54,.06);
	.uni-badge-left-margin {
		position: absolute;
		top: -12rpx;
		right: 24rpx;
		.uni-badge--error {
			background-color: #fff !important;
		}
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
	.con_border {
		color: var(--view-theme);
		border: 1px solid var(--view-theme);
	}
	.image {
		width: 48rpx;
		height: 48rpx;
	}
	.item {
		position: relative;
	}
	.order-status-list.is-four .item {
		width: auto;
		flex: 1;
	}
	.order-icon {
		width: 68rpx;
		height: 68rpx;
		margin-bottom: 18rpx;
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
}
</style>
