<template>
	<view class="select-page">
		<!-- #ifdef H5 -->
		<page-nav-bar title="选择指标" theme="purple" :show-back="false">
			<view slot="left" class="h5-nav-cancel" @click="goBack">取消</view>
			<view slot="right" class="h5-nav-confirm" @click="confirm">确定</view>
		</page-nav-bar>
		<!-- #endif -->
		<scroll-view scroll-y class="list-body">
			<view
				v-for="m in options"
				:key="m.key"
				class="option-row"
				@click="toggle(m.key)"
			>
				<text class="option-name">{{ m.name }}（{{ m.unit }}）</text>
				<text class="checkbox" :class="{ checked: selectedKeys.includes(m.key) }">✓</text>
			</view>
		</scroll-view>
		<view class="bottom-confirm-bar">
			<button class="confirm-btn" @click="confirm">确定</button>
		</view>
	</view>
</template>

<script>
import { targetMetricOptions } from '@/api/target.js';
import { applyTargetNativeNavBar, targetNavigateBack } from '../common/util.js';
import pageNavBar from '../components/page-nav-bar.vue';

export default {
	components: { pageNavBar },
	data() {
		return {
			options: [],
			selectedKeys: [],
			selectedMap: {},
		};
	},
	onLoad() {
		const tmp = uni.getStorageSync('target_metric_select_tmp') || [];
		this.selectedKeys = tmp.map((m) => m.metric_key);
		targetMetricOptions().then((res) => {
			this.options = res.data.core_metrics || [];
			(tmp || []).forEach((m) => {
				this.selectedMap[m.metric_key] = m;
			});
		});
	},
	onShow() {
		applyTargetNativeNavBar('选择指标');
	},
	methods: {
		goBack() {
			targetNavigateBack();
		},
		toggle(key) {
			const m = this.options.find((o) => o.key === key);
			if (!m) return;
			const i = this.selectedKeys.indexOf(key);
			if (i >= 0) {
				this.selectedKeys.splice(i, 1);
				delete this.selectedMap[m.key];
			} else {
				this.selectedKeys.push(m.key);
				this.selectedMap[m.key] = {
					metric_key: m.key,
					metric_name: m.name,
					target_value: '',
					unit: m.unit,
					metric_type: 1,
				};
			}
		},
		confirm() {
			const list = this.selectedKeys.map((k) => {
				if (this.selectedMap[k]) return this.selectedMap[k];
				const def = this.options.find((o) => o.key === k);
				return {
					metric_key: k,
					metric_name: def ? def.name : k,
					target_value: '',
					unit: def ? def.unit : '',
					metric_type: 1,
				};
			});
			uni.setStorageSync('target_metric_select', list);
			uni.removeStorageSync('target_metric_select_tmp');
			uni.navigateBack();
		},
	},
};
</script>

<style scoped lang="scss">
@import '../common/target-form.scss';

.select-page {
	position: relative;
	overflow: hidden;
	min-height: 100vh;
	background: #fff;
}

.list-body {
	height: calc(100vh - 140rpx);
	padding-bottom: calc(120rpx + env(safe-area-inset-bottom));
	box-sizing: border-box;
	/* #ifdef H5 */
	height: calc(100vh - 228rpx);
	/* #endif */
}

.option-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 32rpx;
	border-bottom: 1rpx solid #f5f5f5;
}
.option-name {
	font-size: 30rpx;
}
.checkbox {
	width: 44rpx;
	height: 44rpx;
	border: 4rpx solid #ddd;
	border-radius: 50%;
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 26rpx;
	line-height: 1;
	color: transparent;
}
.checkbox.checked {
	background: #8b5cf6;
	border-color: #8b5cf6;
	color: #fff;
}

.bottom-confirm-bar {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	padding: 24rpx 32rpx calc(24rpx + env(safe-area-inset-bottom));
	background: #fff;
	border-top: 1rpx solid #f0f0f0;
	z-index: 10;
}

.confirm-btn {
	@include target-primary-button;
	background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
	color: #fff;
}
</style>
