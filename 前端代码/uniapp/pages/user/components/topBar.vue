<template>
	<view class="navbar">
		<view class="content" :class="[`style-${styleType}`]" :style="{ background: isScrolling && [2, 3, 4, 5].includes(styleType) ? '#fff' : 'transparent' }">
			<view :style="{ height: `${getHeight.barTop}px` }"></view>
			<view class="acea-row row-center-wrapper" :style="{ height: `${getHeight.barHeight }px` }">
				<view class="title">个人中心</view>
			</view>
		</view>
		<view class="placeholder">
			<view :style="{ height: `${getHeight.barTop}px` }"></view>
			<view :style="{ height: `${getHeight.barHeight}px` }"></view>
		</view>
	</view>
</template>

<script>
export default {
	props: {
		styleType: {
			type: Number,
			default: 0
		},
		isScrolling: {
			type: Boolean,
			default: false
		}
	},
	data() {
		return {
			getHeight: this.$util.getWXStatusHeight()
		};
	}
};
</script>

<style lang="scss">
.navbar {
	position: relative;
	.content.style-1 {
		background-color: var(--view-theme) !important;
	}
	.content.style-2 {
		background-color: #f5f5f5;
		.title {
			color: #333;
		}
	}
	.content.style-3 {
		background-color: rgba(0, 0, 0, 0);
		.title {
			color: #333;
		}
	}
	.content.style-4 {
		background-color: #f5f5f5;
		.title {
			color: #333;
		}
	}
	.content.style-5 {
		// background-color: rgba(0, 0, 0, 0);
		// background: #ffffff linear-gradient(180deg, #ffffff 0%, #f5f5f5 100%);
		// background-image: url('https://test-pro30.mohe.net/statics/images/users/header_bg4.jpg');
		.title {
			color: #333;
		}
	}
	.content {
		position: fixed;
		top: 0;
		right: 0;
		left: 0;
		z-index: 998;
		background-color: var(--view-theme);
		font-weight: 500;
		font-size: 34rpx;
		color: #ffffff;
	}
}
</style>
