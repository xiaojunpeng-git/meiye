<script>
import MemberMenuMedia from '@/components/memberMenuMedia';

export default {
	components: { MemberMenuMedia },
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
	},
};
</script>

<template>
	<view class="px-20 grid-column-3 grid-gap-24rpx mt-20">
		<view v-for="(item, index) in menuData" :key="index">
			<!-- #ifdef MP -->
			<view class="flex-col flex-center bg--w111-fff h-220 rd-24rpx" v-if="item.url!='/pages/extension/customer_list/chat' || (item.url=='/pages/extension/customer_list/chat' && routineContact == 0)"
				@tap="goMenuPage(item.url, item.name)">
				<view class="menu-icon mb-16"><MemberMenuMedia :item="item" :iconSize="36" /></view>
				<view class="fs-26 lh-36rpx">{{ item.name }}</view>
			</view>
			<button class="flex-col flex-center bg--w111-fff h-220 rd-24rpx" open-type='contact' v-if="item.url=='/pages/extension/customer_list/chat' && routineContact == 1">
			  <view class="menu-icon mb-16"><MemberMenuMedia :item="item" :iconSize="36" /></view>
			  <view class="fs-26 lh-36rpx">{{ item.name }}</view>
			</button>
			<!-- #endif -->
			<!-- #ifndef MP -->
			<view class="flex-col flex-center bg--w111-fff h-220 rd-24rpx"
				@tap="goMenuPage(item.url, item.name)">
				<view class="menu-icon mb-16"><MemberMenuMedia :item="item" :iconSize="36" /></view>
				<view class="fs-26 lh-36rpx">{{ item.name }}</view>
			</view>
			<!-- #endif -->
		</view>
	</view>
</template>

<style scoped lang="scss">
.menu-icon {
	width: 64rpx;
	height: 64rpx;
	border-radius: 20rpx;
	background: #f8eded;
	display: flex;
	align-items: center;
	justify-content: center;

}
</style>
