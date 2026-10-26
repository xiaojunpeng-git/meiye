<template>
	<view
		class="metric-item"
		:class="{ active: active, 'product-metric': item.is_product }"
		@tap.stop="$emit('click', item)"
	>
		<view class="metric-item-header">
			<view class="metric-icon" :style="{ background: icon.bg }">
				<text class="metric-icon-text">{{ iconText }}</text>
			</view>
			<text class="metric-name line1">{{ item.metric_name }}</text>
		</view>
		<view class="metric-values">
			<view class="metric-target">目标: <text>{{ formatTarget }}</text></view>
			<view class="metric-completed">已完成: <text>{{ formatCompleted }}</text></view>
		</view>
		<view class="metric-progress-bar">
			<view class="progress-fill" :style="{ width: progressWidth, background: icon.bg }"></view>
		</view>
		<view class="metric-rate" :class="rateCls">达成率 {{ item.rate || 0 }}%</view>
	</view>
</template>

<script>
import { METRIC_ICON, formatMetricValue, rateClass } from '../common/util.js';

const ICON_TEXT = {
	revenue: '¥',
	consume: '耗',
	new_customer: '新',
	old_customer: '老',
	service: '劳',
	point: '点',
	book: '约',
	count: '量',
};

export default {
	props: {
		item: { type: Object, default: () => ({}) },
		active: { type: Boolean, default: false },
	},
	computed: {
		icon() {
			return METRIC_ICON[this.item.metric_key] || METRIC_ICON.revenue;
		},
		iconText() {
			return ICON_TEXT[this.item.metric_key] || '标';
		},
		formatTarget() {
			return formatMetricValue(this.item.target_value, this.item.unit);
		},
		formatCompleted() {
			return formatMetricValue(this.item.completed_value, this.item.unit);
		},
		progressWidth() {
			const r = Math.min(100, Number(this.item.rate || 0));
			return r + '%';
		},
		rateCls() {
			return rateClass(this.item.rate);
		},
	},
};
</script>

<style scoped lang="scss">
.metric-item {
	width: 100%;
	min-width: 0;
	background: #f8f9fa;
	border-radius: 24rpx;
	padding: 32rpx;
	box-sizing: border-box;
	cursor: pointer;
}
.metric-item.active {
	background: #eef2ff;
	border: 4rpx solid #8b5cf6;
}
.metric-item-header {
	display: flex;
	align-items: center;
	margin-bottom: 24rpx;
}
.metric-icon {
	width: 64rpx;
	height: 64rpx;
	border-radius: 16rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	margin-right: 16rpx;
	flex-shrink: 0;
}
.metric-icon-text {
	color: #fff;
	font-size: 24rpx;
	font-weight: 600;
}
.metric-name {
	font-size: 26rpx;
	color: #333;
	font-weight: 500;
	flex: 1;
}
.metric-values {
	font-size: 24rpx;
	color: #666;
	margin-bottom: 24rpx;
}
.metric-values text {
	color: #333;
	font-weight: 600;
}
.metric-completed text {
	color: #10b981;
}
.metric-progress-bar {
	height: 12rpx;
	background: #e5e7eb;
	border-radius: 6rpx;
	overflow: hidden;
	margin-bottom: 16rpx;
}
.progress-fill {
	height: 100%;
	border-radius: 6rpx;
}
.metric-rate {
	font-size: 22rpx;
	color: #999;
	text-align: right;
}
.metric-rate.high {
	color: #10b981;
}
.metric-rate.low {
	color: #ef4444;
}
</style>
