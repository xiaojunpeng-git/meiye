<script>
import countDown from '@/components/countDown';
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
			<view class="flex-between-center mt-30" :class="{ theme: orderStyle == 2 }">
				<view class="w-128 flex-col flex-center item" v-for="item in orderMenu" :key="item.title" @click="intoPage(item.url)">
					<text :class="item.icon" class="iconfont fs-48"></text>
					<text class="fs-26 lh-36rpx text--w111-282828 pt-22">{{ item.title }}</text>
					<text v-if="item.title === '欠款' && item.debt_amount_text" class="fs-22 text-red pt-6">{{ item.debt_amount_text }}</text>
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
	.uni-badge-left-margin {
		position: absolute;
		top: -12rpx;
		right: 24rpx;
		.uni-badge--error {
			background-color: #fff !important;
		}
		/deep/ .uni-badge--error {
			background-color: var(--view-theme) !important;
		}
		.uni-badge {
			color: var(--view-theme);
			border: 1px solid var(--view-theme);
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
	.theme .iconfont {
		color: var(--view-theme);
	}
	.item {
		position: relative;
	}
}
</style>