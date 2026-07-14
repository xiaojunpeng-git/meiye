<template>
	<view class="bottom-nav">
		<view
			class="nav-item"
			:class="{ active: current === 'analysis' }"
			@click="go('analysis')"
		>
			<text class="nav-icon">📊</text>
			<text>目标分析</text>
		</view>
		<view
			class="nav-item"
			:class="{ active: current === 'management' }"
			@click="go('management')"
		>
			<text class="nav-icon">🎯</text>
			<text>目标管理</text>
		</view>
	</view>
</template>

<script>
export default {
	props: {
		current: { type: String, default: 'management' },
	},
	methods: {
		go(tab) {
			if (tab === this.current) return;
			const url =
				tab === 'analysis'
					? '/pages/admin/target/analysis/index'
					: '/pages/admin/target/management/index';
			uni.redirectTo({ url });
		},
	},
};
</script>

<style scoped lang="scss">
.bottom-nav {
	position: fixed;
	bottom: 0;
	left: 0;
	right: 0;
	background: #fff;
	display: flex;
	padding: 16rpx 0 calc(16rpx + env(safe-area-inset-bottom));
	border-top: 1rpx solid #eee;
	z-index: 99;
}
.nav-item {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	font-size: 24rpx;
	color: #999;
}
.nav-item.active {
	color: #8b5cf6;
}
.nav-icon {
	font-size: 40rpx;
	margin-bottom: 8rpx;
}
</style>
