<script>
export default {
	inject: ['goMenuPage'],
	props: {
		menuData: {
			type: Object,
			default: () => {}
		}
	}
};
</script>

<template>
	<view class="">
		<view class="pt-34 pb-36 bg--w111-fff rd-16rpx mt-20 service ml-20 mr-20">
			<view class="fs-30 fw-500 lh-42rpx text--w111-333 pl-32 ">{{ menuData.title }}</view>
			<view class="grid-column-4 grid-gap-x-40rpx grid-gap-y-54rpx mt-38 pl-14 pr-14">
				<view class="flex-col flex-center" v-for="(item, index) in menuData.list" :key="index" @click="goMenuPage(item.url, item.name)">
					<image :src="item.pic" class="image"></image>
					<text class="fs-26 lh-36rpx text--w111-282828 pt-22">{{ item.name }}</text>
				</view>
			</view>
		</view>
	</view>
</template>

<style scoped lang="scss">
.service {
	.image {
		width: 48rpx;
		height: 48rpx;
	}
}
</style>
