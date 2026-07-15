<template>
	<view class="date-filter" :class="{ sticky: sticky }">
		<scroll-view scroll-x class="date-filter__scroll" :show-scrollbar="false">
			<view class="date-filter__row">
				<view
					v-for="item in presets"
					:key="item.type"
					class="chip"
					:class="{ active: dateType === item.type }"
					@click="selectPreset(item.type)"
				>{{ item.label }}</view>
				<view
					class="chip"
					:class="{ active: dateType === 'custom' }"
					@click="toggleCustomPanel"
				>{{ customLabel }}</view>
			</view>
		</scroll-view>

		<view v-if="customPanelVisible" class="custom-panel">
			<view class="custom-panel__row">
				<text class="custom-panel__label">开始</text>
				<picker mode="date" :value="tempStart" @change="onTempStart">
					<view class="custom-panel__value">{{ tempStart }}</view>
				</picker>
			</view>
			<view class="custom-panel__row">
				<text class="custom-panel__label">结束</text>
				<picker mode="date" :value="tempEnd" @change="onTempEnd">
					<view class="custom-panel__value">{{ tempEnd }}</view>
				</picker>
			</view>
			<view class="custom-panel__actions">
				<view class="btn btn--ghost" @click="cancelCustom">取消</view>
				<view class="btn btn--primary" @click="confirmCustom">确定</view>
			</view>
		</view>
	</view>
</template>

<script>
/**
 * 商家端共用日期筛选
 * 输出：date_type / start_date / end_date / display / data_range
 * 边界按设备本地自然日（业务约定 Asia/Shanghai 门店时区设备）
 */
function pad(n) {
	return n < 10 ? '0' + n : '' + n;
}
function fmt(d) {
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
function startOfDay(d) {
	return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}

export default {
	name: 'MerchantDateFilter',
	props: {
		value: {
			type: Object,
			default: null,
		},
		sticky: {
			type: Boolean,
			default: true,
		},
	},
	data() {
		const today = startOfDay(new Date());
		const s = fmt(today);
		return {
			dateType: 'today',
			startDate: s,
			endDate: s,
			presets: [
				{ type: 'today', label: '今天' },
				{ type: 'yesterday', label: '昨天' },
				{ type: 'month', label: '本月' },
				{ type: 'last_month', label: '上月' },
			],
			customPanelVisible: false,
			tempStart: s,
			tempEnd: s,
			_syncing: false,
		};
	},
	computed: {
		customLabel() {
			if (this.dateType === 'custom') {
				return `${this.startDate}~${this.endDate}`;
			}
			return '自定义';
		},
		displayText() {
			const hit = this.presets.find((p) => p.type === this.dateType);
			if (hit) return hit.label;
			return `${this.startDate} 至 ${this.endDate}`;
		},
	},
	mounted() {
		if (this.value && this.value.date_type && this.value.start_date) {
			this.applyExternal(this.value);
		} else {
			this.emitChange();
		}
	},
	watch: {
		value: {
			deep: true,
			handler(v) {
				if (v) this.applyExternal(v);
			},
		},
	},
	methods: {
		applyExternal(v) {
			if (!v || this._syncing) return;
			if (
				v.date_type === this.dateType &&
				v.start_date === this.startDate &&
				v.end_date === this.endDate
			) {
				return;
			}
			this.dateType = v.date_type || 'today';
			if (v.start_date) this.startDate = v.start_date;
			if (v.end_date) this.endDate = v.end_date;
		},
		rangeByType(type) {
			const now = startOfDay(new Date());
			if (type === 'today') {
				const s = fmt(now);
				return { start: s, end: s };
			}
			if (type === 'yesterday') {
				const y = new Date(now.getTime() - 86400000);
				const s = fmt(y);
				return { start: s, end: s };
			}
			if (type === 'month') {
				const s = new Date(now.getFullYear(), now.getMonth(), 1);
				const e = new Date(now.getFullYear(), now.getMonth() + 1, 0);
				return { start: fmt(s), end: fmt(e) };
			}
			if (type === 'last_month') {
				const s = new Date(now.getFullYear(), now.getMonth() - 1, 1);
				const e = new Date(now.getFullYear(), now.getMonth(), 0);
				return { start: fmt(s), end: fmt(e) };
			}
			return { start: this.startDate, end: this.endDate };
		},
		selectPreset(type) {
			this.customPanelVisible = false;
			this.dateType = type;
			const r = this.rangeByType(type);
			this.startDate = r.start;
			this.endDate = r.end;
			this.emitChange();
		},
		toggleCustomPanel() {
			this.tempStart = this.startDate;
			this.tempEnd = this.endDate;
			this.customPanelVisible = !this.customPanelVisible;
		},
		onTempStart(e) {
			this.tempStart = e.detail.value;
			if (this.tempEnd < this.tempStart) this.tempEnd = this.tempStart;
		},
		onTempEnd(e) {
			this.tempEnd = e.detail.value;
			if (this.tempEnd < this.tempStart) this.tempEnd = this.tempStart;
		},
		cancelCustom() {
			this.customPanelVisible = false;
		},
		confirmCustom() {
			let start = this.tempStart;
			let end = this.tempEnd;
			if (end < start) end = start;
			this.dateType = 'custom';
			this.startDate = start;
			this.endDate = end;
			this.customPanelVisible = false;
			this.emitChange();
		},
		emitChange() {
			const payload = {
				date_type: this.dateType,
				start_date: this.startDate,
				end_date: this.endDate,
				display: this.displayText,
				data_range: `${this.startDate.replace(/-/g, '/')}-${this.endDate.replace(/-/g, '/')}`,
			};
			this._syncing = true;
			this.$emit('input', payload);
			this.$emit('change', payload);
			this.$nextTick(() => {
				this._syncing = false;
			});
		},
	},
};
</script>

<style scoped>
.date-filter {
	background: #fff;
	padding: 16rpx 0 8rpx;
	z-index: 20;
}
.date-filter.sticky {
	position: sticky;
	top: 0;
}
.date-filter__scroll {
	width: 100%;
	white-space: nowrap;
}
.date-filter__row {
	display: inline-flex;
	padding: 0 24rpx 8rpx;
}
.chip {
	display: inline-flex;
	align-items: center;
	padding: 12rpx 24rpx;
	margin-right: 12rpx;
	border-radius: 28rpx;
	background: #f5f5f5;
	font-size: 24rpx;
	color: #666;
}
.chip.active {
	background: #ffe9e7;
	color: #e93323;
}
.custom-panel {
	margin: 8rpx 24rpx 12rpx;
	padding: 20rpx 24rpx;
	background: #fafafa;
	border-radius: 16rpx;
}
.custom-panel__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16rpx 0;
}
.custom-panel__label {
	font-size: 26rpx;
	color: #666;
}
.custom-panel__value {
	min-width: 220rpx;
	text-align: right;
	font-size: 28rpx;
	color: #222;
	padding: 8rpx 0;
}
.custom-panel__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 12rpx;
}
.btn {
	padding: 12rpx 28rpx;
	margin-left: 16rpx;
	border-radius: 28rpx;
	font-size: 26rpx;
}
.btn--ghost {
	background: #eee;
	color: #666;
}
.btn--primary {
	background: #e93323;
	color: #fff;
}
</style>
