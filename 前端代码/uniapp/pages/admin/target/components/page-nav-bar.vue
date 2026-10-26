<template>
	<view class="target-navbar">
		<!-- #ifdef MP || APP-PLUS -->
		<view class="target-navbar-content" :class="themeClass">
			<view :style="{ height: `${getHeight.barTop}px` }" />
			<view class="target-navbar-bar" :style="barRowStyle">
				<view class="target-navbar-side target-navbar-side--left">
					<slot name="left" />
				</view>
				<view class="target-navbar-title-wrap">
					<text class="target-navbar-title">{{ title }}</text>
				</view>
				<view class="target-navbar-side target-navbar-side--right">
					<slot name="right">
						<view class="target-navbar-side-placeholder" />
					</slot>
				</view>
			</view>
		</view>
		<view class="target-navbar-placeholder">
			<view :style="{ height: `${getHeight.barTop}px` }" />
			<view :style="{ height: `${getHeight.barHeight}px` }" />
		</view>
		<!-- #endif -->
		<!-- #ifdef H5 -->
		<view class="target-navbar-content target-navbar-content--h5" :class="themeClass">
			<view class="target-navbar-bar target-navbar-bar--h5">
				<view class="target-navbar-side target-navbar-side--left">
					<slot name="left">
						<view v-if="showBack" class="target-navbar-back" @click="handleBack">
							<text>{{ backText }}</text>
						</view>
						<view v-else class="target-navbar-side-placeholder" />
					</slot>
				</view>
				<view class="target-navbar-title-wrap">
					<text class="target-navbar-title">{{ title }}</text>
				</view>
				<view class="target-navbar-side target-navbar-side--right">
					<slot name="right">
						<view class="target-navbar-side-placeholder" />
					</slot>
				</view>
			</view>
		</view>
		<view class="target-navbar-placeholder target-navbar-placeholder--h5" />
		<!-- #endif -->
	</view>
</template>

<script>
import { targetNavigateBack } from '../common/util.js';

export default {
	props: {
		title: { type: String, default: '' },
		theme: { type: String, default: 'purple' },
		showBack: { type: Boolean, default: true },
		backText: { type: String, default: '‹ 返回' },
		autoBack: { type: Boolean, default: true },
	},
	data() {
		return {
			getHeight: this.$util.getWXStatusHeight(),
		};
	},
	computed: {
		themeClass() {
			return this.theme === 'white'
				? 'target-navbar-content--white'
				: 'target-navbar-content--purple';
		},
		barRowStyle() {
			const style = {
				height: `${this.getHeight.barHeight}px`,
			};
			// #ifdef MP
			const menu = this.getHeight.menuButtonInfo;
			if (menu && menu.left) {
				const win = uni.getWindowInfo();
				if (win && win.windowWidth) {
					style.paddingRight = `${win.windowWidth - menu.left + 8}px`;
				}
			}
			// #endif
			return style;
		},
	},
	mounted() {
		this.refreshNavHeight();
	},
	methods: {
		refreshNavHeight() {
			this.getHeight = this.$util.getWXStatusHeight();
		},
		handleBack() {
			if (this.$listeners.back) {
				this.$emit('back');
				return;
			}
			if (this.autoBack === false) {
				this.$emit('back');
				return;
			}
			this.navigateBackDefault();
		},
		navigateBackDefault() {
			targetNavigateBack();
		},
	},
};
</script>

<style scoped lang="scss">
.target-navbar {
	position: relative;
}

.target-navbar-content {
	position: fixed;
	top: 0;
	right: 0;
	left: 0;
	z-index: 998;
}

.target-navbar-content--purple {
	background-color: #8b5cf6;
	background-image: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
}

.target-navbar-content--white {
	background-color: #fff;
	border-bottom: 1rpx solid #f0f0f0;
}

.target-navbar-content--h5 {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	z-index: 998;
}

.target-navbar-placeholder--h5 {
	height: 88rpx;
}

.target-navbar-bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0 30rpx;
	box-sizing: border-box;
	width: 100%;
}

.target-navbar-bar--h5 {
	padding: 24rpx 32rpx;
	height: auto;
	min-height: 88rpx;
}

.target-navbar-title-wrap {
	flex: 1;
	height: 100%;
	min-width: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	box-sizing: border-box;
	/* #ifdef MP */
	padding-top: 2px;
	/* #endif */
}

.target-navbar-title {
	max-width: 100%;
	font-size: 34rpx;
	font-weight: 500;
	line-height: 34rpx;
	text-align: center;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.target-navbar-content--purple .target-navbar-title {
	color: #fff;
}

.target-navbar-content--white .target-navbar-title {
	color: #333;
}

.target-navbar-side {
	flex-shrink: 0;
	min-width: 80rpx;
	height: 100%;
	display: flex;
	align-items: center;
}

.target-navbar-side--left {
	justify-content: flex-start;
}

.target-navbar-side--right {
	justify-content: flex-end;
}

.target-navbar-side-placeholder {
	width: 80rpx;
	height: 1px;
}

.target-navbar-back {
	display: flex;
	align-items: center;
	min-width: 120rpx;
	font-size: 30rpx;
	color: #fff;
}
</style>
