<script>
import countDown from '@/components/countDown';
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
	methods: {
		goUserSpread() {
			uni.navigateTo({
				url: '/pages/users/user_spread_user/index'
			});
		}
	}
};
</script>

<template>
	<view class="">
		<view class="pr-24 pl-24 bg--w111-fff rd-16rpx mt-20 ml-20 mr-20">
			<view class="acea-row row-middle row-between section-header">
				<view>订单中心</view>
				<view class="arrow" @click="intoPage('/pages/goods/order_list/index')">
					查看全部
					<text class="iconfont icon-ic_rightarrow"></text>
				</view>
			</view>
			<view class="acea-row section-content">
				<view v-for="item in orderMenu" class="item" @click="intoPage(item.url)">
					<view class="icon"><text :class="item.icon" class="iconfont"></text></view>
					<view class="">{{ item.title }}</view>
					<view v-if="item.title === '欠款' && item.debt_amount_text" class="debt-amount-text">{{ item.debt_amount_text }}</view>
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
.section-content {
	padding: 48rpx 0 36rpx;

	.item {
		position: relative;
		flex: 1;
		text-align: center;
		font-size: 26rpx;
		line-height: 36rpx;
		color: #333333;
		.uni-badge-left-margin {
			position: absolute;
			top: -20rpx;
			right: 26rpx;
			/deep/ .uni-badge--error {
				background-color: var(--view-theme) !important;
			}
			.uni-badge {
				color: var(--view-theme);
				border: 1px solid var(--view-theme);
				z-index: 29;
			}
		}
	}

	.icon {
		margin-bottom: 18rpx;
	}

	.iconfont {
		font-size: 48rpx;
	}
	.debt-amount-text {
		font-size: 22rpx;
		color: #ed4014;
		margin-top: 6rpx;
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
	color: #333333;
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
