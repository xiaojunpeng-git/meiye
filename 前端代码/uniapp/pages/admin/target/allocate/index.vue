<template>
	<view class="allocate-page">
		<!-- #ifdef H5 -->
		<page-nav-bar title="分配目标" theme="purple" @back="goBack">
			<view slot="right" class="h5-nav-confirm" @click="confirmAllocate">确定</view>
		</page-nav-bar>
		<!-- #endif -->
		<view class="target-summary">
			<text class="target-label">目标：{{ summaryLabel }}</text>
			<text class="target-value">{{ displayTotal }}</text>
			<text class="target-remaining" :class="remainingClass">
				还剩<text class="remaining-num">{{ displayRemaining }}</text>{{ unit }}未分配
			</text>
		</view>

		<view class="section-header">
			<text class="section-tag">分配目标</text>
		</view>

		<view class="employee-list">
			<view v-if="loading" class="loading-tip">加载中...</view>
			<view v-else-if="!staffList.length" class="loading-tip">暂无员工</view>
			<view
				v-for="(item, idx) in staffList"
				:key="item.staff_id"
				class="employee-item"
			>
				<view class="employee-avatar">
					<image v-if="item.avatar" :src="item.avatar" mode="aspectFill" />
					<uni-icons v-else type="person-filled" size="20" color="#ccc" />
				</view>
				<text class="employee-name">{{ item.staff_name }}</text>
				<input
					class="employee-input"
					type="digit"
					:value="item.input_value"
					placeholder="0"
					placeholder-class="placeholder"
					@input="onStaffInput(item, $event)"
					@blur="onStaffBlur(item)"
				/>
			</view>
		</view>

		<view class="bottom-bar">
			<button class="average-btn" @click="averageAllocate">平均分配</button>
			<button class="confirm-btn" :disabled="remaining !== 0" @click="confirmAllocate">确定</button>
		</view>
	</view>
</template>

<script>
import uniIcons from '@/uni_modules/uni-icons/components/uni-icons/uni-icons.vue';
import pageNavBar from '../components/page-nav-bar.vue';
import { targetAllocateInfo, targetAllocateSave } from '@/api/target.js';
import {
	getAllocateDraft,
	setAllocateDraft,
	toTargetInt,
	formatTargetInt,
	sanitizeTargetInputValue,
	averageAllocateInt,
	getApiErrorMessage,
	applyTargetNativeNavBar,
	targetNavigateBack,
} from '../common/util.js';

export default {
	components: { uniIcons, pageNavBar },
	data() {
		return {
			loading: true,
			targetId: 0,
			storeId: 0,
			refKey: '',
			allocateType: 1,
			metricName: '',
			unit: '元',
			totalTarget: 0,
			staffList: [],
		};
	},
	computed: {
		/** 实时剩余 = 目标总额 - 已填之和 */
		remaining() {
			const total = toTargetInt(this.totalTarget);
			let allocated = 0;
			(this.staffList || []).forEach((s) => {
				allocated += toTargetInt(s.input_value);
			});
			return total - allocated;
		},
		summaryLabel() {
			const u = this.unit ? `（${this.unit}）` : '';
			return `${this.metricName}${u}`;
		},
		displayTotal() {
			return this.formatNum(this.totalTarget);
		},
		displayRemaining() {
			return this.formatNum(Math.max(0, this.remaining));
		},
		remainingClass() {
			if (this.remaining === 0) return 'ok';
			if (this.remaining < 0) return 'over';
			return '';
		},
	},
	onLoad(options) {
		this.targetId = parseInt(options.target_id || 0, 10);
		this.storeId = parseInt(options.store_id || 0, 10);
		this.refKey = options.ref_key || options.metric_key || '';
		this.allocateType = parseInt(options.allocate_type || 1, 10);
		this.metricName = decodeURIComponent(options.metric || options.metric_name || '');
		this.unit = decodeURIComponent(options.unit || '元');
		this.totalTarget = toTargetInt(options.target || options.target_value || 0);
		this.loadStaff();
	},
	onShow() {
		applyTargetNativeNavBar('分配目标');
	},
	methods: {
		formatNum(n) {
			return formatTargetInt(n);
		},
		goBack() {
			targetNavigateBack();
		},
		loadStaff() {
			this.loading = true;
			const draft = !this.targetId ? getAllocateDraft()[this.refKey] : null;
			targetAllocateInfo({
				store_id: this.storeId,
				target_id: this.targetId,
				ref_key: this.refKey,
				allocate_type: this.allocateType,
				target_value: toTargetInt(this.totalTarget),
				metric_name: this.metricName,
				unit: this.unit,
			})
				.then((res) => {
					const d = res.data || {};
					if (d.metric_name) this.metricName = d.metric_name;
					if (d.unit) this.unit = d.unit;
					if (d.target_value) this.totalTarget = toTargetInt(d.target_value);
					const list = (d.staff_list || []).map((s) => {
						let val = toTargetInt(s.allocate_value);
						if (draft && draft.length) {
							const hit = draft.find((x) => x.staff_id === s.staff_id);
							if (hit) val = toTargetInt(hit.allocate_value);
						}
						return {
							...s,
							input_value: val > 0 ? String(val) : '',
						};
					});
					this.staffList = list;
					this.clampStaffInputsToTotal();
				})
				.catch((e) => {
					uni.showToast({ title: getApiErrorMessage(e, '加载失败'), icon: 'none' });
				})
				.finally(() => {
					this.loading = false;
				});
		},
		getInputEventValue(e) {
			const v = e?.detail?.value ?? e?.target?.value ?? '';
			return String(v);
		},
		/** 当前员工最多可填 = 目标总额 - 其他员工已填之和（按 staff_id，避免 $set 后引用不一致） */
		getMaxAllowedForStaff(staffId) {
			const id = typeof staffId === 'object' ? staffId.staff_id : staffId;
			const total = toTargetInt(this.totalTarget);
			let otherAllocated = 0;
			(this.staffList || []).forEach((item) => {
				if (item.staff_id !== id) {
					otherAllocated += toTargetInt(item.input_value);
				}
			});
			return Math.max(0, total - otherAllocated);
		},
		onStaffInput(s, e) {
			const idx = this.staffList.findIndex((item) => item.staff_id === s.staff_id);
			if (idx < 0) return;

			const raw = this.getInputEventValue(e);
			const cleaned = sanitizeTargetInputValue(raw);
			const maxAllowed = this.getMaxAllowedForStaff(s.staff_id);
			const cur = this.staffList[idx].input_value;

			if (!cleaned) {
				this.commitStaffInput(idx, cur, '', cleaned);
				return;
			}

			// 其他人已分满（如 A+B+C=121），本格无额度，任意输入都应为 0
			if (maxAllowed <= 0) {
				this.commitStaffInput(idx, cur, '0', cleaned);
				return;
			}

			const typed = toTargetInt(cleaned);
			let next = '';
			if (typed > maxAllowed) {
				next = String(maxAllowed);
			} else {
				next = typed > 0 ? String(typed) : '';
			}
			this.commitStaffInput(idx, cur, next, cleaned);
		},
		onStaffBlur(s) {
			const idx = this.staffList.findIndex((item) => item.staff_id === s.staff_id);
			if (idx < 0) return;
			const maxAllowed = this.getMaxAllowedForStaff(s.staff_id);
			const cur = this.staffList[idx].input_value;
			if (maxAllowed <= 0 && toTargetInt(cur) > 0) {
				this.commitStaffInput(idx, cur, '0', cur);
			}
		},
		commitStaffInput(idx, cur, next, cleaned) {
			const curStr = cur != null ? String(cur) : '';
			const typed = toTargetInt(cleaned);
			const nextNum = toTargetInt(next);
			const displayMismatch = cleaned !== next && typed !== nextNum;
			if (curStr === next) {
				if (displayMismatch) {
					this.syncStaffInputDisplay(idx, next);
				}
				return;
			}
			this.$set(this.staffList, idx, { ...this.staffList[idx], input_value: next });
		},
		/** 同值压顶时把原生输入框显示拉回合法值 */
		syncStaffInputDisplay(idx, next) {
			this.$set(this.staffList, idx, { ...this.staffList[idx], input_value: '' });
			this.$nextTick(() => {
				if (this.staffList[idx]) {
					this.$set(this.staffList, idx, { ...this.staffList[idx], input_value: next });
				}
			});
		},
		updateStaffInput(s, value) {
			const idx = this.staffList.findIndex((item) => item.staff_id === s.staff_id);
			if (idx >= 0) {
				this.$set(this.staffList, idx, { ...this.staffList[idx], input_value: value });
			}
		},
		clampStaffInputsToTotal() {
			const total = toTargetInt(this.totalTarget);
			let allocated = 0;
			this.staffList.forEach((s) => {
				let val = toTargetInt(s.input_value);
				const max = Math.max(0, total - allocated);
				if (val > max) val = max;
				s.input_value = val > 0 ? String(val) : '';
				allocated += val;
			});
		},
		averageAllocate() {
			const count = this.staffList.length;
			if (!count) return;
			const amounts = averageAllocateInt(this.totalTarget, count);
			this.staffList.forEach((s, index) => {
				s.input_value = amounts[index] > 0 ? String(amounts[index]) : '';
			});
		},
		collectItems() {
			return this.staffList
				.map((s) => ({
					staff_id: s.staff_id,
					staff_name: s.staff_name,
					allocate_value: toTargetInt(s.input_value),
				}))
				.filter((x) => x.allocate_value > 0);
		},
		confirmAllocate() {
			if (this.remaining < 0) {
				return uni.showToast({ title: '分配总额不能超过目标值', icon: 'none' });
			}
			if (this.remaining !== 0) {
				return uni.showToast({ title: '请确保目标已完全分配', icon: 'none' });
			}
			const items = this.collectItems();
			const result = {
				ref_key: this.refKey,
				allocate_type: this.allocateType,
				items,
			};
			const done = () => {
				uni.setStorageSync('target_allocate_result', result);
				if (!this.targetId) {
					setAllocateDraft(this.refKey, items);
				}
				uni.showToast({ title: '分配成功' });
				setTimeout(() => uni.navigateBack(), 400);
			};
			if (this.targetId > 0) {
				targetAllocateSave({
					target_id: this.targetId,
					store_id: this.storeId,
					ref_key: this.refKey,
					allocate_type: this.allocateType,
					target_value: toTargetInt(this.totalTarget),
					metric_name: this.metricName,
					unit: this.unit,
					items,
				})
					.then(done)
					.catch((e) => {
						uni.showToast({ title: getApiErrorMessage(e, '保存失败'), icon: 'none' });
					});
			} else {
				done();
			}
		},
	},
};
</script>

<style scoped lang="scss">
@import '../common/target-form.scss';

.allocate-page {
	position: relative;
	overflow: hidden;
	min-height: 100vh;
	background: #f5f5f5;
	padding-bottom: 160rpx;
}

.target-summary {
	background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
	padding: 48rpx 32rpx;
	text-align: center;
	color: #fff;
}

.target-label {
	font-size: 28rpx;
	opacity: 0.9;
	display: block;
	margin-bottom: 16rpx;
}

.target-value {
	font-size: 72rpx;
	font-weight: 700;
	display: block;
	margin-bottom: 16rpx;
}

.target-remaining {
	font-size: 26rpx;
	opacity: 0.85;
	display: block;
}

.target-remaining.ok .remaining-num {
	color: #6ee7b7;
}

.target-remaining.over .remaining-num {
	color: #fecaca;
}

.section-header {
	background: #fff;
	padding: 24rpx 32rpx;
	margin-top: 24rpx;
}

.section-tag {
	display: inline-block;
	background: #8b5cf6;
	color: #fff;
	padding: 8rpx 24rpx;
	border-radius: 24rpx;
	font-size: 26rpx;
}

.employee-list {
	background: #fff;
}

.employee-item {
	display: flex;
	align-items: center;
	padding: 32rpx;
	border-bottom: 1rpx solid #f5f5f5;
}

.employee-avatar {
	width: 80rpx;
	height: 80rpx;
	border-radius: 50%;
	background: #f0f0f0;
	margin-right: 24rpx;
	overflow: hidden;
	display: flex;
	align-items: center;
	justify-content: center;
}

.employee-avatar image {
	width: 100%;
	height: 100%;
}

.employee-name {
	flex: 1;
	font-size: 30rpx;
	color: #333;
}

.employee-input {
	@include target-text-input;
	width: 200rpx;
	padding: 0 24rpx;
	border: 1rpx solid #e0e0e0;
	border-radius: 12rpx;
	text-align: right;
}

.loading-tip {
	padding: 48rpx;
	text-align: center;
	color: #999;
	font-size: 28rpx;
}

.bottom-bar {
	position: fixed;
	bottom: 0;
	left: 0;
	right: 0;
	background: #fff;
	padding: 24rpx 32rpx calc(24rpx + env(safe-area-inset-bottom));
	border-top: 1rpx solid #f0f0f0;
	display: flex;
	gap: 24rpx;
}

.average-btn {
	flex: 1;
	height: 88rpx;
	line-height: 88rpx;
	background: #fff;
	border: 1rpx solid #8b5cf6;
	color: #8b5cf6;
	border-radius: 16rpx;
	font-size: 32rpx;
}

.confirm-btn {
	flex: 1;
	height: 88rpx;
	line-height: 88rpx;
	background: #8b5cf6;
	color: #fff;
	border-radius: 16rpx;
	font-size: 32rpx;
	border: none;
}

.confirm-btn[disabled] {
	opacity: 0.45;
}
</style>
