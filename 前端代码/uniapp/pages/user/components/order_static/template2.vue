<script>
export default {
	props: {
		staticData: {
			type: Object,
			default: () => {}
		}
	},
	inject: ['goMenuPage'],
};
</script>

<template>
	<view class="">
		<view class="mt-20 rd-16rpx bg--w111-fff pt-36 pr-40 pb-36 pl-40 ml-20 mr-20 flex-between-center">
			<view class="flex-col flex-center text--w111-333" @click="goMenuPage('/pages/goods/order_list/index')">
				<text class="fs-36 fw-600 lh-36rpx num-fa-semi">{{ staticData.price }}</text>
				<text class="fs-24 lh-34rpx pt-16">
					支付订单金额
					<text class="iconfont icon-ic_rightarrow fs-24"></text>
				</text>
			</view>
			<view class="v-Line"></view>
			<view class="flex-col flex-center text--w111-333" @click="goMenuPage('/pages/goods/order_list/index')">
				<text class="fs-36 fw-600 lh-36rpx num-fa-semi">{{ staticData.num }}</text>
				<text class="fs-24 lh-34rpx pt-16">
					支付订单数
					<text class="iconfont icon-ic_rightarrow fs-24"></text>
				</text>
			</view>
			<view class="v-Line"></view>
			<view class="flex-col flex-center text--w111-333" @click="goMenuPage('/pages/goods/order_list/index')">
				<text class="fs-36 fw-600 lh-36rpx num-fa-semi">{{ staticData.consignment }}</text>
				<text class="fs-24 lh-34rpx pt-16">
					待发货订单数
					<text class="iconfont icon-ic_rightarrow fs-24"></text>
				</text>
			</view>
		</view>
	</view>
</template>

<style lang="scss" scoped>
.v-Line {
	width: 1px;
	height: 40rpx;
	background: #eee;
}
</style>