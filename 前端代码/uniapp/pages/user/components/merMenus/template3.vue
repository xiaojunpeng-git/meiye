<script>
export default {
	inject: ['goMenuPage'],
	props: {
		menuData: {
			type: Array,
			default: () => []
		}
	}
};
</script>

<template>
	<view class="acea-row service">
		<view class="acea-row row-column row-middle row-center item" v-for="(item, index) in menuData" :key="index" @click="goMenuPage(item.url, item.name)">
			<image :src="item.pic" class="image"></image>
			<view>{{ item.name }}</view>
		</view>
	</view>
</template>

<style lang="scss" scoped>
.service {
	margin: 0 8rpx 8rpx 8rpx;

	.item {
		flex: 0 0 220rpx;
		height: 220rpx;
		border-radius: 24rpx;
		background-color: #ffffff;
		margin: 13rpx 13rpx 13rpx 13rpx;
		font-size: 26rpx;
		line-height: 36rpx;
		color: #333333;
	}

	.image {
		width: 64rpx;
		height: 64rpx;
		margin-bottom: 32rpx;
	}
}

.SemiBold {
	font-family: SemiBold;
}
</style>
