<template>
	<view class="msp-entry" @tap="openPicker">
		<view class="msp-label" v-if="label">{{ label }}</view>
		<view class="msp-value-row">
			<text class="msp-value" :class="{ placeholder: !hasValue }">{{ displayText }}</text>
			<text class="msp-arrow">›</text>
		</view>
		<view v-if="tip" class="msp-tip">{{ tip }}</view>
	</view>
</template>

<script>
import {
	openStoreSelectPage,
	consumePickerResult,
	formatSelectionSummary,
} from '@/components/merchantStorePicker/utils.js';

/**
 * 门店选择入口字段（调起全屏选择页）
 * props.mode: single | multiple
 * props.snapshot: 创建/分配固化快照；分析筛选传 false
 */
export default {
	name: 'MerchantStorePicker',
	props: {
		value: { type: Object, default: null },
		mode: { type: String, default: 'multiple' },
		label: { type: String, default: '选择门店' },
		placeholder: { type: String, default: '请选择门店' },
		/** true=创建/分配固化；false=分析实时 */
		snapshot: { type: Boolean, default: false },
		realtime: { type: Boolean, default: true },
		disabled: { type: Boolean, default: false },
	},
	data() {
		return {
			innerValue: null,
			listening: false,
		};
	},
	computed: {
		current() {
			return this.value != null ? this.value : this.innerValue;
		},
		hasValue() {
			const cur = this.current;
			if (!cur) return false;
			if (cur.mode === 'single') return !!Number(cur.store_id);
			return Array.isArray(cur.resolved_store_ids) && cur.resolved_store_ids.length > 0;
		},
		displayText() {
			if (!this.hasValue) return this.placeholder;
			return this.current.summary || formatSelectionSummary(this.current);
		},
		tip() {
			if (!this.hasValue) return '';
			if (this.mode === 'single') return '';
			return this.snapshot || this.current.snapshot
				? '已固化门店快照'
				: '分析筛选：将实时跟随组织变化';
		},
	},
	watch: {
		value(val) {
			this.innerValue = val;
		},
	},
	mounted() {
		this.innerValue = this.value;
		this.bindEvents();
	},
	beforeDestroy() {
		this.unbindEvents();
	},
	// #ifdef VUE3
	beforeUnmount() {
		this.unbindEvents();
	},
	// #endif
	methods: {
		bindEvents() {
			if (this.listening) return;
			this.listening = true;
			uni.$on('merchant-store-picker-confirm', this.onConfirmEvent);
		},
		unbindEvents() {
			if (!this.listening) return;
			this.listening = false;
			uni.$off('merchant-store-picker-confirm', this.onConfirmEvent);
		},
		onConfirmEvent(payload) {
			this.applyResult(payload);
		},
		onPageShow() {
			const result = consumePickerResult();
			if (result) this.applyResult(result);
		},
		applyResult(payload) {
			if (!payload) return;
			this.innerValue = payload;
			this.$emit('input', payload);
			this.$emit('change', payload);
		},
		openPicker() {
			if (this.disabled) return;
			const cur = this.current || {};
			openStoreSelectPage({
				mode: this.mode === 'single' ? 'single' : 'multiple',
				snapshot: this.snapshot,
				realtime: this.realtime && !this.snapshot,
				org_ids: cur.org_ids || [],
				store_ids: cur.store_ids || [],
				excluded_store_ids: cur.excluded_store_ids || [],
				resolved_store_ids: cur.resolved_store_ids || [],
				store_id: cur.store_id || 0,
			});
		},
	},
};
</script>

<style scoped lang="scss">
.msp-entry {
	background: #fff;
	padding: 24rpx 28rpx;
}
.msp-label {
	font-size: 26rpx;
	color: #666;
	margin-bottom: 12rpx;
}
.msp-value-row {
	display: flex;
	align-items: center;
}
.msp-value {
	flex: 1;
	font-size: 30rpx;
	color: #222;
	min-width: 0;
}
.msp-value.placeholder {
	color: #bbb;
}
.msp-arrow {
	color: #c0c4cc;
	font-size: 36rpx;
	margin-left: 12rpx;
}
.msp-tip {
	margin-top: 10rpx;
	font-size: 22rpx;
	color: #8b5cf6;
}
</style>
