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
	<view class="">
		<view class="service">
			<view class="acea-row row-middle item" v-for="(item, index) in menuData" :key="index" @click="goMenuPage(item.url, item.name)">
				<image :src="item.pic" class="image"></image>
				<view class="name">{{ item.name }}</view>
				<text class="iconfont icon-ic_rightarrow"></text>
			</view>
		</view>
	</view>
</template>

<style lang="scss" scoped>
.service {
	padding: 20rpx 0;
	border-radius: 16rpx;
	margin: 20rpx;
	background-color: #ffffff;

	.item {
		padding: 28rpx 20rpx 28rpx 32rpx;
	}

	.image {
		width: 40rpx;
		height: 40rpx;
		margin-right: 24rpx;
	}

	.name {
		flex: 1;
		font-size: 28rpx;
		color: #333333;
	}

	.iconfont {
		font-size: 28rpx;
		color: #999999;
	}
}
</style>
