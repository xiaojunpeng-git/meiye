<template>
	<view class="navbar">
		<view class="content" :style="{ background: isScrolling ? '#fff' : bagColor }">
			<view :style="{ height: `${getHeight.barTop}px` }"></view>
			<view class="acea-row row-center-wrapper bar" :style="{ height: `${getHeight.barHeight}px` }">
				<view class="back-icon acea-row row-center-wrapper">
					<view
						v-show="showBack"
						@tap="back"
						class="iconfont icon-ic_leftarrow back-icon"
						:style="{ color: `${iconColor}`, fontSize: `${iconSize}`, fontWeight: `${iconWeight}` }"
					>
					</view>
					<view
						v-show="showHome"
						@tap="home"
						class="iconfont icon-ic_home back-icon"
						:style="{ color: `${iconColor}`, fontSize: `${iconSize}`, fontWeight: `${iconWeight}` }"
					>
					</view>
				</view>
				<view class="title" :style="{ color: `${textColor}`, fontSize: `${textSize}`, fontWeight: `${textWeight}` }">{{ titleText }}</view>
				<view class="right-icon acea-row row-center-wrapper">
					<view v-show="showRight" class="right-icon"></view>
				</view>
			</view>
		</view>
		<view class="placeholder" v-if="placeholder">
			<view :style="{ height: `${getHeight.barTop}px` }"></view>
			<view :style="{ height: `${getHeight.barHeight}px` }"></view>
		</view>
	</view>
</template>

<script>
export default {
	name: 'navbar',
	props: {
		// 滚动至下部
		isScrolling: {
			type: Boolean,
			default: false
		},
		// 是否显示返回icon
		showBack: {
			type: Boolean,
			default: false
		},
		showUser: {
			type: Boolean,
			default: false
		},
		showHome: {
			type: Boolean,
			default: false
		},
		// Title
		titleText: {
			type: String,
			default: ''
		},
		// icon 颜色
		iconColor: {
			type: String,
			default: '#000000'
		},
		// icon 字号
		iconSize: {
			type: String,
			default: '40rpx'
		},
		// icon 字重
		iconWeight: {
			type: String,
			default: 'bold'
		},
		// Title 颜色
		textColor: {
			type: String,
			default: '#333'
		},
		// Title 字号
		textSize: {
			type: String,
			default: '34rpx'
		},
		// Title 字重
		textWeight: {
			type: String,
			default: '500'
		},
		// 背景色
		bagColor: {
			type: String,
			default: 'transparent'
		},
		showRight: {
			type: Boolean,
			default: false
		},
		placeholder: {
			type: Boolean,
			default: true
		},
	},
	data() {
		return {
			getHeight: this.$util.getWXStatusHeight()
		};
	},
	methods: {
		back() {
			if(this.showUser){
				uni.switchTab({
					url:'/pages/user/index'
				})
			}else{
				uni.navigateBack();
			}
		},
		home() {
			uni.switchTab({
				url:'/pages/index/index'
			})
		}
	}
};
</script>

<style lang="scss">
.navbar {
	position: relative;
	color: #333;
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
		.back-icon,
		.right-icon {
			width: 40rpx;
			height: 40rpx;
		}
		.bar {
			padding: 0 30rpx;
		}
		.title {
			flex: 1;
			text-align: center;
		}
	}
}
</style>
