<script>
import { resolveMemberMenuIcon } from '@/utils/memberMenuIcon';

export default {
	inject: ['goMenuPage'],
	props: {
		menuData: {
			type: Array,
			default: () => []
		},
		routineContact: {
			type: Number,
			default: 0
		}
	},
	methods: {
		menuIcon(item) {
			return resolveMemberMenuIcon(item);
		}
	}
};
</script>

<template>
	<view class="">
		<view class="service">
			<view v-for="(item, index) in menuData" :key="index">
				<!-- #ifdef MP -->
				<view class="acea-row row-middle item" v-if="item.url!='/pages/extension/customer_list/chat' || (item.url=='/pages/extension/customer_list/chat' && routineContact == 0)" @click="goMenuPage(item.url, item.name)">
					<view class="menu-icon"><text class="iconfont" :class="menuIcon(item)"></text></view>
					<view class="name">{{ item.name }}</view>
					<text class="iconfont icon-ic_rightarrow"></text>
				</view>
				<button class="acea-row row-middle item" open-type='contact' v-if="item.url=='/pages/extension/customer_list/chat' && routineContact == 1">
				  <view class="menu-icon"><text class="iconfont" :class="menuIcon(item)"></text></view>
				  <view class="name">{{ item.name }}</view>
				  <text class="iconfont icon-ic_rightarrow"></text>
				</button>
				<!-- #endif -->
				<!-- #ifndef MP -->
				<view class="acea-row row-middle item" @click="goMenuPage(item.url, item.name)">
					<view class="menu-icon"><text class="iconfont" :class="menuIcon(item)"></text></view>
					<view class="name">{{ item.name }}</view>
					<text class="iconfont icon-ic_rightarrow"></text>
				</view>
				<!-- #endif -->
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

	.menu-icon {
		width: 52rpx;
		height: 52rpx;
		margin-right: 24rpx;
		border-radius: 16rpx;
		background: #f8eded;
		display: flex;
		align-items: center;
		justify-content: center;

		.iconfont {
			font-size: 30rpx;
			line-height: 1;
			color: var(--view-theme, #7b2941);
		}
	}

	.name {
		flex: 1;
		font-size: 28rpx;
		color: #333333;
		text-align: left;
	}

	.iconfont {
		font-size: 28rpx;
		color: #999999;
	}
}
</style>
