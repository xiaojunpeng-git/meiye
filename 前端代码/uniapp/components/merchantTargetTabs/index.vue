<template>
	<view class="target-tabs">
		<view
			class="tab"
			:class="{ active: current === 'analysis' }"
			@click="go('analysis')"
		>目标看板</view>
		<view
			class="tab"
			:class="{ active: current === 'management' }"
			@click="go('management')"
		>目标设置</view>
	</view>
</template>

<script>
export default {
	name: 'MerchantTargetTabs',
	props: {
		current: { type: String, default: 'analysis' },
	},
	methods: {
		go(tab) {
			if (tab === this.current) return;
			const url =
				tab === 'analysis'
					? '/pages/admin/target/analysis/index?from=merchant'
					: '/pages/admin/target/management/index?from=merchant';
			uni.redirectTo({ url });
		},
	},
};
</script>

<style scoped>
.target-tabs {
	display: flex;
	background: #fff;
	border-bottom: 1rpx solid #eee;
}
.tab {
	flex: 1;
	text-align: center;
	padding: 24rpx 0;
	font-size: 28rpx;
	color: #666;
	position: relative;
}
.tab.active {
	color: #e93323;
	font-weight: 600;
}
.tab.active::after {
	content: '';
	position: absolute;
	left: 50%;
	bottom: 0;
	width: 64rpx;
	height: 4rpx;
	margin-left: -32rpx;
	background: #e93323;
	border-radius: 2rpx;
}
</style>
