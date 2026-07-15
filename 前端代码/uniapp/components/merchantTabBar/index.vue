<template>
	<view class="merchant-tabbar" :style="{ paddingBottom: safeBottom + 'px' }">
		<view
			v-for="item in tabs"
			:key="item.key"
			class="merchant-tabbar__item"
			:class="{ 'is-active': current === item.key }"
			@click="onSwitch(item)"
		>
			<text class="merchant-tabbar__icon iconfont" :class="item.icon"></text>
			<text class="merchant-tabbar__text">{{ item.name }}</text>
		</view>
	</view>
</template>

<script>
const TABS = [
	{ key: 'home', name: '首页', path: '/pages/merchant/home/index', icon: 'icon-ic_home' },
	{ key: 'customer', name: '客户', path: '/pages/merchant/customer/index', icon: 'icon-ic_user' },
	{ key: 'data', name: '数仓', path: '/pages/merchant/data/index', icon: 'icon-ic_order' },
	{ key: 'target', name: '目标', path: '/pages/merchant/target/index', icon: 'icon-ic_star' },
	{ key: 'profile', name: '我的', path: '/pages/merchant/profile/index', icon: 'icon-ic_user1' },
];

export default {
	name: 'MerchantTabBar',
	props: {
		current: {
			type: String,
			default: 'home',
		},
	},
	data() {
		return {
			tabs: TABS,
			safeBottom: 0,
		};
	},
	mounted() {
		try {
			const sys = uni.getSystemInfoSync();
			this.safeBottom = sys.safeAreaInsets ? sys.safeAreaInsets.bottom : 0;
		} catch (e) {
			this.safeBottom = 0;
		}
	},
	methods: {
		onSwitch(item) {
			if (!item || item.key === this.current) return;
			uni.redirectTo({ url: item.path });
		},
	},
};
</script>

<style scoped>
.merchant-tabbar {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	z-index: 800;
	display: flex;
	align-items: stretch;
	background: #fff;
	border-top: 1rpx solid #eee;
	padding-top: 8rpx;
}
.merchant-tabbar__item {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 8rpx 0 12rpx;
	color: #666;
}
.merchant-tabbar__item.is-active {
	color: #e93323;
}
.merchant-tabbar__icon {
	font-size: 40rpx;
	line-height: 1.2;
}
.merchant-tabbar__text {
	margin-top: 4rpx;
	font-size: 22rpx;
}
</style>
