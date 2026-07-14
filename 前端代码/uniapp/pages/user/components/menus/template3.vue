<script>
export default {
	inject: ['goMenuPage'],
	props: {
		menuData: {
			type: Array,
			default: () => []
		},
		routineContact: {
			type: Number,
			default: 0
		}
	}
};
</script>

<template>
	<view class="px-20 grid-column-3 grid-gap-24rpx mt-20">
		<view v-for="(item, index) in menuData" :key="index">
			<!-- #ifdef MP -->
			<view class="flex-col flex-center bg--w111-fff h-220 rd-24rpx" v-if="item.url!='/pages/extension/customer_list/chat' || (item.url=='/pages/extension/customer_list/chat' && routineContact == 0)"
				@tap="goMenuPage(item.url, item.name)">
				<image :src="item.pic" class="w-64 h-64 block mb-16"></image>
				<view class="fs-26 lh-36rpx">{{ item.name }}</view>
			</view>
			<button class="flex-col flex-center bg--w111-fff h-220 rd-24rpx" open-type='contact' v-if="item.url=='/pages/extension/customer_list/chat' && routineContact == 1">
			  <image :src="item.pic" class="w-64 h-64 block mb-16"></image>
			  <view class="fs-26 lh-36rpx">{{ item.name }}</view>
			</button>
			<!-- #endif -->
			<!-- #ifndef MP -->
			<view class="flex-col flex-center bg--w111-fff h-220 rd-24rpx"
				@tap="goMenuPage(item.url, item.name)">
				<image :src="item.pic" class="w-64 h-64 block mb-16"></image>
				<view class="fs-26 lh-36rpx">{{ item.name }}</view>
			</view>
			<!-- #endif -->
		</view>
	</view>
</template>
