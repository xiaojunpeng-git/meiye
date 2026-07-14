<script>
export default {
	props: {
		posterData: {
			type: Object,
			default: () => {}
		}
	},
	data() {
		return {
			autoplay: true,
			circular: true,
			interval: 3000,
			duration: 500
		};
	},
	inject: ['goMenuPage'],
	methods: {}
};
</script>


<template>
	<!-- 轮播 -->
	<view class="slider-wrapper" v-if="posterData.list.length && parseInt(posterData.is_show)">
		<swiper
			:indicator-dots="posterData.list.length > 1 ? true : false"
			:autoplay="autoplay"
			:circular="circular"
			:interval="interval"
			:duration="duration"
			indicator-color="rgba(255,255,255,0.6)"
			indicator-active-color="#fff"
		>
			<block v-for="(item, index) in posterData.list" :key="index">
				<swiper-item>
					<view @click="goMenuPage(item.url)" class="slide-navigator acea-row row-between-wrapper" hover-class="none">
						<image :src="item.pic" class="slide-image" mode="aspectFill"></image>
					</view>
				</swiper-item>
			</block>
		</swiper>
	</view>
</template>

<style lang="scss" scoped>
.slider-wrapper {
	margin: 20rpx 20rpx;
	height: 188rpx;

	swiper,
	swiper-item {
		height: 100%;
	}

	image {
		width: 100%;
		height: 188rpx;
		border-radius: 16rpx;
	}
}
</style>
