<template>
	<view class="detail-page">
		<!-- #ifdef H5 -->
		<page-nav-bar title="周期目标详情" theme="purple" @back="goBack" />
		<!-- #endif -->
		<scroll-view v-if="ready" scroll-y class="scroll-body">
			<view class="info-card">
				<view class="info-header">
					<text class="info-title">{{ periodTitle }}</text>
					<text class="info-badge active">进行中</text>
				</view>
				<view class="info-meta">
					<view class="info-item">
						<uni-icons type="calendar" size="14" color="#8B5CF6" />
						<text>目标时间：{{ timeRangeText }}</text>
					</view>
					<view class="info-item">
						<uni-icons type="shop" size="14" color="#8B5CF6" />
						<text>目标门店：{{ storeName || '—' }}</text>
					</view>
					<view class="info-item">
						<uni-icons type="clock" size="14" color="#8B5CF6" />
						<text>周期时长：{{ periodMonthCount }}个月</text>
					</view>
				</view>
			</view>

			<view class="metrics-card">
				<view class="metrics-header">
					<view>
						<text class="metrics-title">指标完成情况</text>
						<text class="metrics-subtitle">整体达成率 {{ summaryRate }}%</text>
					</view>
				</view>
				<view class="metric-list" :class="{ collapsed: !expanded }">
					<view
						v-for="(m, idx) in displayMetrics"
						:key="m.listKey"
						class="metric-cell"
					>
						<metric-card
							:item="m"
							:active="rankMetricKey === getRankKey(m)"
							@click="onMetricSelect"
						/>
					</view>
				</view>
				<view
					v-if="displayMetrics.length > 2"
					class="expand-btn"
					:class="{ expanded }"
					@click="toggleExpanded"
				>
					<text>{{ expanded ? '收起' : '展开全部' }}</text>
					<uni-icons :type="expanded ? 'top' : 'bottom'" size="14" color="#8B5CF6" />
				</view>
			</view>

			<view class="monthly-card">
				<view class="monthly-header">
					<view class="monthly-title">
						<text class="highlight">{{ currentMetricName }}</text>
						<text>每月完成情况</text>
					</view>
					<text class="monthly-subtitle">{{ monthlySubtitle }}</text>
				</view>
				<view class="monthly-list">
					<view
						v-for="(item, idx) in monthlyList"
						:key="item.month"
						class="monthly-item"
					>
						<view class="monthly-item-header">
							<text class="monthly-item-name">{{ item.month }}</text>
							<text class="monthly-item-status" :class="item.statusClass">{{ item.statusText }}</text>
						</view>
						<view class="monthly-item-values">
							<view class="monthly-item-value">
								<text class="monthly-item-label">目标</text>
								<text class="monthly-item-number">{{ formatAnalysisNumber(item.target) }}</text>
							</view>
							<view class="monthly-item-value">
								<text class="monthly-item-label">实际目标</text>
								<text class="monthly-item-number actual">{{ formatAnalysisNumber(item.actualTarget) }}</text>
							</view>
							<view class="monthly-item-value">
								<text class="monthly-item-label">已完成</text>
								<text
									class="monthly-item-number"
									:class="{ completed: item.completedClass }"
								>{{ formatAnalysisNumber(item.completed) }}</text>
							</view>
						</view>
						<view class="monthly-item-progress">
							<view
								class="monthly-item-progress-bar"
								:class="item.progressClass"
								:style="{ width: item.progressWidth + '%' }"
							/>
						</view>
						<view v-if="item.showResult" class="monthly-item-footer">
							<text class="monthly-item-rate" :class="item.rateClass">达成率 {{ item.rate }}%</text>
							<text class="monthly-item-result" :style="{ color: item.resultColor }">{{ item.resultText }}</text>
						</view>
					</view>
					<view v-if="!monthlyList.length" class="empty-tip">当前周期内暂无已设目标的月份</view>
				</view>
			</view>
		</scroll-view>

		<view v-if="loading" class="loading-tip">加载中...</view>
	</view>
</template>

<script>
import { targetAnalysis } from '@/api/target.js';
import uniIcons from '@/uni_modules/uni-icons/components/uni-icons/uni-icons.vue';
import metricCard from '../components/metric-card.vue';
import pageNavBar from '../components/page-nav-bar.vue';
import {
	mergeMetrics,
	formatAnalysisNumber,
	formatProductMetricName,
	getApiErrorMessage,
	mapMonthlyAchieveItem,
	applyTargetNativeNavBar,
} from '../common/util.js';

export default {
	components: { uniIcons, metricCard, pageNavBar },
	data() {
		return {
			from: '',
			storeId: 0,
			storeName: '',
			startYear: new Date().getFullYear(),
			startMonth: 1,
			endYear: new Date().getFullYear(),
			endMonth: 12,
			timeRange: '',
			loading: true,
			ready: false,
			detail: {},
			monthlyFromApi: [],
			expanded: false,
			rankMetricKey: 'revenue',
		};
	},
	computed: {
		periodTitle() {
			return `${this.timeRangeText}目标`;
		},
		timeRangeText() {
			if (this.timeRange) return this.timeRange;
			if (this.startYear === this.endYear) {
				return `${this.startYear}年${this.startMonth}-${this.endMonth}月`;
			}
			return `${this.startYear}年${this.startMonth}月-${this.endYear}年${this.endMonth}月`;
		},
		periodMonthCount() {
			return (
				this.endMonth -
				this.startMonth +
				1 +
				(this.endYear - this.startYear) * 12
			);
		},
		summaryRate() {
			const metrics = mergeMetrics(this.detail).filter((m) => Number(m.target_value || 0) > 0);
			let target = 0;
			let completed = 0;
			metrics.forEach((m) => {
				target += Number(m.target_value || 0);
				completed += Number(m.completed_value || 0);
			});
			return target > 0 ? Math.round((completed / target) * 1000) / 10 : 0;
		},
		displayMetrics() {
			return mergeMetrics(this.detail).filter((m) => Number(m.target_value || 0) > 0);
		},
		currentMetricName() {
			const m = this.currentRankMetric;
			return m?.metric_name || '指标';
		},
		currentRankMetric() {
			return (
				this.displayMetrics.find((x) => this.getRankKey(x) === this.rankMetricKey) ||
				null
			);
		},
		monthlySubtitle() {
			const n = this.monthlyList.length;
			return n ? `${n}个月 · 缺口动态分摊` : '暂无已设目标月份';
		},
		monthlyList() {
			return (this.monthlyFromApi || []).map(mapMonthlyAchieveItem);
		},
	},
	onLoad(options) {
		this.from = options.from || '';
		this.storeId = parseInt(options.store_id || 0, 10);
		this.storeName = decodeURIComponent(options.store || options.store_name || '');
		this.startYear = parseInt(options.startYear || options.start_year || new Date().getFullYear(), 10);
		this.endYear = parseInt(options.endYear || options.end_year || this.startYear, 10);
		this.startMonth = Math.max(1, Math.min(12, parseInt(options.startMonth || options.start_month || 1, 10)));
		this.endMonth = Math.max(1, Math.min(12, parseInt(options.endMonth || options.end_month || 12, 10)));
		this.timeRange = decodeURIComponent(options.timeRange || options.time_range || '');
		if (this.storeId) {
			this.loadData();
		} else {
			this.loading = false;
			uni.showToast({ title: '缺少门店信息', icon: 'none' });
		}
	},
	onShow() {
		applyTargetNativeNavBar('周期目标详情');
	},
	methods: {
		toggleExpanded() {
			this.expanded = !this.expanded;
		},
		formatAnalysisNumber,
		goBack() {
			if (this.from === 'analysis') {
				uni.navigateBack({ delta: 1 });
				return;
			}
			uni.redirectTo({ url: '/pages/admin/target/management/index' });
		},
		formatMonthParam(year, month) {
			return `${year}-${String(month).padStart(2, '0')}`;
		},
		getRankKey(m) {
			if (!m) return '';
			if (m.is_product) {
				return m.allocate_ref_key || `product_${m.product_id || 0}_${m.metric_key || 'revenue'}`;
			}
			return m.metric_key || '';
		},
		metricItemKey(m, idx) {
			return m.is_product ? `p-${m.product_id || idx}-${m.metric_key || ''}` : `m-${m.metric_key || idx}`;
		},
		normalizeMetricRow(m, isProduct = false) {
			const rate =
				m.target_value > 0
					? Math.round((m.completed_value / m.target_value) * 1000) / 10
					: 0;
			return { ...m, rate, is_product: isProduct };
		},
		buildParams(metricKey = 'revenue') {
			return {
				start_month: this.formatMonthParam(this.startYear, this.startMonth),
				end_month: this.formatMonthParam(this.endYear, this.endMonth),
				year: String(this.startYear),
				store_id: this.storeId,
				object_type: 1,
				metric_key: metricKey,
			};
		},
		loadData(metricKey) {
			const key = metricKey || this.rankMetricKey || 'revenue';
			this.loading = !this.ready;
			targetAnalysis(this.buildParams(key))
				.then((res) => {
					const d = res.data || {};
					const core = (d.metrics || []).map((m) => this.normalizeMetricRow(m, false));
					const products = (d.products || []).map((p) =>
						this.normalizeMetricRow(
							{ ...p, metric_name: formatProductMetricName(p) },
							true
						)
					);
					this.detail = {
						metrics: core,
						products,
						object_name: this.storeName,
						period_label: this.timeRangeText,
					};
					this.monthlyFromApi = d.monthly || [];
					this.ready = true;
					if (metricKey) {
						this.rankMetricKey = key;
					} else {
						this.$nextTick(() => {
							const first = this.displayMetrics[0];
							if (first) this.rankMetricKey = this.getRankKey(first);
						});
					}
				})
				.catch((e) => {
					uni.showToast({ title: getApiErrorMessage(e, '加载失败'), icon: 'none' });
				})
				.finally(() => {
					this.loading = false;
				});
		},
		onMetricSelect(m) {
			const key = this.getRankKey(m);
			if (!key) return;
			this.rankMetricKey = key;
			this.loadData(key);
		},
	},
};
</script>

<style scoped lang="scss">
@import '../common/target-form.scss';

.detail-page {
	position: relative;
	overflow: hidden;
	min-height: 100vh;
	background: #f5f5f5;
	padding-bottom: 40rpx;
}

.scroll-body {
	height: calc(100vh - 40rpx);
	/* #ifdef H5 */
	height: calc(100vh - 128rpx);
	/* #endif */
	padding: 24rpx;
	box-sizing: border-box;
}

.info-card,
.metrics-card,
.monthly-card {
	background: #fff;
	border-radius: 24rpx;
	padding: 32rpx;
	margin-bottom: 24rpx;
}

.info-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 24rpx;
}

.info-title {
	font-size: 40rpx;
	font-weight: 700;
	color: #333;
	flex: 1;
	margin-right: 16rpx;
}

.info-badge {
	padding: 6rpx 20rpx;
	border-radius: 20rpx;
	font-size: 22rpx;
	font-weight: 500;
	flex-shrink: 0;
}

.info-badge.active {
	background: #d1fae5;
	color: #059669;
}

.info-meta {
	display: flex;
	flex-direction: column;
	gap: 12rpx;
}

.info-item {
	display: flex;
	align-items: center;
	gap: 16rpx;
	font-size: 26rpx;
	color: #666;
}

.metrics-header {
	margin-bottom: 32rpx;
}

.metrics-title {
	display: block;
	font-size: 32rpx;
	font-weight: 600;
	color: #333;
}

.metrics-subtitle {
	display: block;
	font-size: 24rpx;
	color: #999;
	margin-top: 8rpx;
}

.metric-list {
	display: flex;
	flex-wrap: wrap;
	gap: 24rpx;
}

.metric-cell {
	width: calc((100% - 24rpx) / 2);
	box-sizing: border-box;
}

.metric-list.collapsed .metric-cell:nth-child(n + 3) {
	display: none;
}

.expand-btn {
	width: 100%;
	padding: 24rpx 0 0;
	margin-top: 24rpx;
	border-top: 1rpx solid #f5f5f5;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12rpx;
	color: #8b5cf6;
	font-size: 28rpx;
}

.monthly-header {
	margin-bottom: 24rpx;
}

.monthly-title {
	font-size: 32rpx;
	font-weight: 600;
	color: #333;
}

.monthly-title .highlight {
	color: #8b5cf6;
	margin-right: 8rpx;
}

.monthly-subtitle {
	display: block;
	font-size: 24rpx;
	color: #999;
	margin-top: 8rpx;
}

.monthly-list {
	display: flex;
	flex-direction: column;
	gap: 24rpx;
}

.monthly-item {
	background: #f8f9fa;
	border-radius: 24rpx;
	padding: 24rpx;
}

.monthly-item-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16rpx;
}

.monthly-item-name {
	font-size: 30rpx;
	font-weight: 600;
	color: #333;
}

.monthly-item-status {
	font-size: 22rpx;
	padding: 6rpx 16rpx;
	border-radius: 20rpx;
}

.monthly-item-status.completed {
	background: #d1fae5;
	color: #059669;
}

.monthly-item-status.pending {
	background: #fef3c7;
	color: #d97706;
}

.monthly-item-status.not-started {
	background: #e5e7eb;
	color: #6b7280;
}

.monthly-item-values {
	display: flex;
	gap: 16rpx;
	margin-bottom: 16rpx;
}

.monthly-item-value {
	flex: 1;
}

.monthly-item-label {
	display: block;
	font-size: 22rpx;
	color: #999;
	margin-bottom: 4rpx;
}

.monthly-item-number {
	font-size: 28rpx;
	font-weight: 700;
	color: #333;
}

.monthly-item-number.actual {
	color: #8b5cf6;
}

.monthly-item-number.completed {
	color: #10b981;
}

.monthly-item-progress {
	height: 12rpx;
	background: #e5e7eb;
	border-radius: 6rpx;
	overflow: hidden;
	margin-bottom: 12rpx;
}

.monthly-item-progress-bar {
	height: 100%;
	border-radius: 6rpx;
}

.monthly-item-progress-bar.bar-high {
	background: #10b981;
}

.monthly-item-progress-bar.bar-medium {
	background: #f59e0b;
}

.monthly-item-progress-bar.bar-low {
	background: #ef4444;
}

.monthly-item-footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.monthly-item-rate {
	font-size: 24rpx;
	font-weight: 600;
}

.monthly-item-rate.high {
	color: #10b981;
}

.monthly-item-rate.medium {
	color: #f59e0b;
}

.monthly-item-rate.low {
	color: #ef4444;
}

.monthly-item-result {
	font-size: 22rpx;
}

.empty-tip,
.loading-tip {
	text-align: center;
	color: #999;
	padding: 48rpx 0;
	font-size: 28rpx;
}
</style>
