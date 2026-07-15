<template>
	<!-- 商家端仅保留底部 TabBar；顶栏由目标页自行挂载 -->
	<merchant-tab-bar v-if="isMerchant" current="target" />
	<view v-else class="bottom-nav">
		<view
			class="nav-item"
			:class="{ active: current === 'analysis' }"
			@click="go('analysis')"
		>
			<text class="nav-icon iconfont icon-ic_order"></text>
			<text>目标分析</text>
		</view>
		<view
			class="nav-item"
			:class="{ active: current === 'management' }"
			@click="go('management')"
		>
			<text class="nav-icon iconfont icon-ic_star"></text>
			<text>目标管理</text>
		</view>
	</view>
</template>

<script>
import merchantTabBar from '@/components/merchantTabBar/index.vue';

export default {
	components: { merchantTabBar },
	props: {
		current: { type: String, default: 'management' },
	},
	computed: {
		isMerchant() {
			try {
				return this.$store.state.merchant.mode === 'merchant';
			} catch (e) {
				return false;
			}
		},
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
	color: #e93323;
}
.nav-icon {
	font-size: 40rpx;
	margin-bottom: 8rpx;
}
</style>
