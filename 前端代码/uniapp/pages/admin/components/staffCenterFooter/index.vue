<template>
	<view class="staff-footer">
		<view
			class="foot-item"
			:class="{ active: activePath === '/pages/admin/staff_center/order/index' }"
			@click="go('/pages/admin/staff_center/order/index')"
		>
			<text class="iconfont icon-dingdanguanli"></text>
			<view class="txt">老师订单</view>
		</view>
		<view
			class="foot-item"
			:class="{ active: activePath === '/pages/admin/staff_center/index' }"
			@click="go('/pages/admin/staff_center/index')"
		>
			<text class="iconfont icon-ic_user"></text>
			<view class="txt">老师中心</view>
		</view>
	</view>
</template>

<script>
import { goWithYuyueSubscribe } from '@/utils/SubscribeMessage.js';

export default {
	name: 'staffCenterFooter',
	data() {
		return {
			activePath: ''
		};
	},
	created() {
		const pages = getCurrentPages();
		const cur = pages[pages.length - 1];
		this.activePath = cur ? '/' + cur.route : '';
	},
	methods: {
		go(url) {
			if (this.activePath === url) return;
			if (url === '/pages/admin/staff_center/order/index') {
				goWithYuyueSubscribe(url, 'redirectTo');
				return;
			}
			uni.redirectTo({ url });
		}
	}
};
</script>

<style scoped lang="scss">
.staff-footer {
	position: fixed;
	left: 0;
	bottom: 0;
	z-index: 20;
	display: flex;
	width: 100%;
	height: calc(100rpx + env(safe-area-inset-bottom));
	padding-bottom: env(safe-area-inset-bottom);
	background: #fff;
	border-top: 1rpx solid #eee;
	box-sizing: border-box;
}
.foot-item {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	color: #999;
	font-size: 22rpx;
	.iconfont {
		font-size: 40rpx;
		margin-bottom: 6rpx;
	}
	&.active {
		color: #07cd9a;
	}
}
</style>
