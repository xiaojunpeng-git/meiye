<template>
	<view class="detail-page">
		<!-- #ifdef H5 -->
		<page-nav-bar title="目标详情" theme="purple" @back="goBack" />
		<!-- #endif -->
		<scroll-view
			scroll-y
			class="scroll-body"
			:scroll-into-view="scrollIntoView"
			scroll-with-animation
			v-if="detail.id"
		>
			<!-- 目标基本信息 -->
			<view class="info-card">
				<view class="info-header">
					<text class="info-title">{{ detail.name }}</text>
					<text class="info-badge" :class="statusInfo.badgeClass">{{ statusInfo.text }}</text>
				</view>
				<view class="info-meta">
					<view class="info-item">
						<uni-icons type="calendar" size="14" color="#8B5CF6" />
						<text>目标时间：{{ timeRangeText }}</text>
					</view>
					<view class="info-item">
						<uni-icons type="shop" size="14" color="#8B5CF6" />
						<text>目标门店：{{ detail.object_name || '—' }}</text>
					</view>
				</view>
			</view>

			<!-- 指标完成情况 -->
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
							:active="rankMetricKey === m.rankKey"
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

			<!-- 员工排行榜 -->
			<view class="ranking-card" id="ranking-section">
				<view class="ranking-header">
					<text class="ranking-title">员工排行榜</text>
					<view class="ranking-sort">
						<text
							class="sort-tag"
							:class="{ active: sortType === 'value' }"
							@click="setSortType('value')"
						>完成值</text>
						<text
							class="sort-tag"
							:class="{ active: sortType === 'rate' }"
							@click="setSortType('rate')"
						>达成率</text>
					</view>
				</view>
				<view class="ranking-filter-top">
					<text
						v-for="(m, idx) in rankMetricTabs"
						:key="m.rankKey"
						class="ranking-tag"
						:class="{ active: rankMetricKey === m.rankKey }"
						@click="loadRanking(m.rankKey)"
					>{{ m.metric_name }}</text>
				</view>
				<view class="employee-list">
					<view
						v-for="(e, idx) in topRanking"
						:key="e.staff_id"
						class="employee-item"
					>
						<view class="rank-section">
							<uni-icons
								v-if="idx < 3"
								type="medal-filled"
								size="20"
								:color="trophyColor(idx)"
								class="rank-trophy"
							/>
							<text v-else class="rank-number">{{ idx + 1 }}</text>
							<text class="rank-change" :class="e.changeCls">{{ e.changeText }}</text>
						</view>
						<image
							class="employee-avatar"
							:src="e.avatar || '/static/images/f.png'"
							mode="aspectFill"
						/>
						<view class="employee-info">
							<text class="employee-name">{{ e.staff_name }}</text>
							<view class="employee-progress-wrap">
								<view class="progress-bar-employee">
									<view
										class="progress-fill-employee"
										:class="e.rateTier"
										:style="{ width: e.progressWidth }"
									/>
								</view>
								<text class="employee-rate" :class="e.rateTier">{{ e.rate }}%</text>
							</view>
							<view class="employee-target">
								已完成:
								<text>{{ formatEmpValue(e) }}</text>
								/ 目标:
								<text>{{ formatEmpTarget(e) }}</text>
							</view>
						</view>
					</view>
					<view v-if="!rankingLoading && !topRanking.length" class="empty-rank">
						暂无排行数据
					</view>
				</view>
			</view>
		</scroll-view>

		<view v-if="loading" class="loading-tip">加载中...</view>
	</view>
</template>

<script>
import { targetDetail, targetRanking } from '@/api/target.js';
import uniIcons from '@/uni_modules/uni-icons/components/uni-icons/uni-icons.vue';
import metricCard from '../components/metric-card.vue';
import pageNavBar from '../components/page-nav-bar.vue';
import {
	mergeMetrics,
	formatMetricValue,
	getTargetProgressStatus,
	formatTargetTimeRange,
	employeeRateTier,
	getRankChangeText,
	getApiErrorMessage,
	parseRankingResponse,
	applyTargetNativeNavBar,
} from '../common/util.js';

const RANK_TAB_KEYS = ['revenue', 'consume', 'service', 'point', 'book', 'new_customer', 'old_customer'];

export default {
	components: { uniIcons, metricCard, pageNavBar },
	data() {
		return {
			id: 0,
			loading: true,
			rankingLoading: false,
			detail: {},
			expanded: false,
			ranking: [],
			rankMetricKey: 'revenue',
			sortType: 'value',
			scrollIntoView: '',
		};
	},
	computed: {
		statusInfo() {
			return getTargetProgressStatus(this.detail);
		},
		timeRangeText() {
			return formatTargetTimeRange(this.detail) || this.detail.period_label || '—';
		},
		summaryRate() {
			return this.detail.summary?.rate ?? 0;
		},
		displayMetrics() {
			return mergeMetrics(this.detail).filter((m) => Number(m.target_value || 0) > 0);
		},
		rankMetricTabs() {
			const list = this.displayMetrics;
			const ordered = [];
			RANK_TAB_KEYS.forEach((key) => {
				const hit = list.find((m) => !m.is_product && m.metric_key === key);
				if (hit) ordered.push(hit);
			});
			list.forEach((m) => {
				const rk = m.rankKey;
				if (rk && !ordered.find((x) => x.rankKey === rk)) {
					ordered.push(m);
				}
			});
			return ordered;
		},
		currentRankMetric() {
			return (
				this.rankMetricTabs.find((x) => x.rankKey === this.rankMetricKey) ||
				this.displayMetrics.find((x) => x.rankKey === this.rankMetricKey) ||
				null
			);
		},
		currentRankUnit() {
			return this.currentRankMetric?.unit || '';
		},
		topRanking() {
			return (this.ranking || []).slice(0, 5).map((e, idx) => {
				const rate = Number(e.rate || 0);
				const change = getRankChangeText(e.staff_id, this.id, idx + 1);
				return {
					...e,
					rate: rate,
					rateTier: employeeRateTier(rate),
					progressWidth: Math.min(rate, 100) + '%',
					changeText: change.text,
					changeCls: change.cls,
				};
			});
		},
	},
	onLoad(options) {
		this.id = parseInt(options.id || 0, 10);
		if (this.id) {
			this.loadDetail();
		}
	},
	onShow() {
		applyTargetNativeNavBar('目标详情');
	},
	methods: {
		toggleExpanded() {
			this.expanded = !this.expanded;
		},
		goBack() {
			uni.redirectTo({ url: '/pages/admin/target/management/index' });
		},
		metricItemKey(m, idx) {
			if (m.is_product) {
				return `p-${m.product_id || idx}-${m.metric_key || ''}`;
			}
			return `m-${m.metric_key || idx}`;
		},
		getRankKey(m) {
			if (!m) return '';
			if (m.is_product) {
				return (
					m.allocate_ref_key ||
					`product_${m.product_id || 0}_${m.metric_key || 'revenue'}`
				);
			}
			return m.metric_key || '';
		},
		onMetricSelect(m) {
			const key = this.getRankKey(m);
			if (!key) return;
			this.loadRanking(key, true);
		},
		setSortType(type) {
			if (this.sortType === type) return;
			this.sortType = type;
			if (this.rankMetricKey) {
				this.loadRanking(this.rankMetricKey);
			}
		},
		trophyColor(idx) {
			if (idx === 0) return '#FFD700';
			if (idx === 1) return '#C0C0C0';
			return '#CD7F32';
		},
		formatEmpValue(e) {
			const val = e.completed_value ?? e.value ?? 0;
			return formatMetricValue(val, this.currentRankUnit);
		},
		formatEmpTarget(e) {
			return formatMetricValue(e.target_value || 0, this.currentRankUnit);
		},
		loadDetail() {
			this.loading = true;
			targetDetail(this.id)
				.then((res) => {
					this.detail = res.data || {};
					this.$nextTick(() => {
						const tabs = this.rankMetricTabs;
						if (tabs.length) {
							this.loadRanking(tabs[0].rankKey);
						}
					});
				})
				.catch((e) => {
					uni.showToast({ title: getApiErrorMessage(e, '加载失败'), icon: 'none' });
				})
				.finally(() => {
					this.loading = false;
				});
		},
		loadRanking(metricKey, scrollToRank = false) {
			if (!metricKey) return;
			this.rankMetricKey = metricKey;
			this.rankingLoading = true;
			targetRanking({
				target_id: this.id,
				metric_key: metricKey,
				sort_type: this.sortType,
				asc: 0,
			})
				.then((res) => {
					this.ranking = parseRankingResponse(res).list;
				})
				.catch((e) => {
					this.ranking = [];
					uni.showToast({ title: getApiErrorMessage(e, '排行加载失败'), icon: 'none' });
				})
				.finally(() => {
					this.rankingLoading = false;
					if (scrollToRank) {
						this.scrollIntoView = 'ranking-section';
						setTimeout(() => {
							this.scrollIntoView = '';
						}, 400);
					}
				});
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
.ranking-card {
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

.info-badge.pending {
	background: #fef3c7;
	color: #d97706;
}

.info-badge.done {
	background: #e5e7eb;
	color: #6b7280;
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

.ranking-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 32rpx;
	gap: 16rpx;
}

.ranking-title {
	font-size: 32rpx;
	font-weight: 600;
	color: #333;
	flex-shrink: 0;
}

.ranking-sort {
	display: flex;
	gap: 8rpx;
	background: #f3f4f6;
	padding: 6rpx;
	border-radius: 32rpx;
	flex-shrink: 0;
}

.sort-tag {
	padding: 8rpx 20rpx;
	border-radius: 24rpx;
	font-size: 22rpx;
	color: #666;
}

.sort-tag.active {
	background: #fff;
	color: #8b5cf6;
	box-shadow: 0 2rpx 6rpx rgba(0, 0, 0, 0.06);
}

.ranking-filter-top {
	display: flex;
	flex-wrap: wrap;
	gap: 16rpx;
	margin-bottom: 32rpx;
}

.ranking-tag {
	padding: 12rpx 24rpx;
	border-radius: 32rpx;
	font-size: 24rpx;
	background: #f3f4f6;
	color: #666;
}

.ranking-tag.active {
	background: #8b5cf6;
	color: #fff;
}

.employee-list {
	display: flex;
	flex-direction: column;
	gap: 24rpx;
}

.employee-item {
	display: flex;
	align-items: center;
	gap: 24rpx;
	padding: 24rpx;
	background: #f8f9fa;
	border-radius: 24rpx;
}

.rank-section {
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 72rpx;
	flex-shrink: 0;
}

.rank-trophy {
	margin-bottom: 4rpx;
}

.rank-number {
	font-size: 32rpx;
	font-weight: 700;
	color: #999;
}

.rank-change {
	font-size: 20rpx;
	color: #999;
	margin-top: 4rpx;
}

.rank-change.up {
	color: #22c55e;
}

.rank-change.down {
	color: #ef4444;
}

.employee-avatar {
	width: 80rpx;
	height: 80rpx;
	border-radius: 50%;
	background: #e5e7eb;
	flex-shrink: 0;
}

.employee-info {
	flex: 1;
	min-width: 0;
}

.employee-name {
	display: block;
	font-size: 30rpx;
	font-weight: 500;
	color: #333;
	margin-bottom: 12rpx;
}

.employee-progress-wrap {
	display: flex;
	align-items: center;
	gap: 20rpx;
}

.progress-bar-employee {
	flex: 1;
	height: 12rpx;
	background: #e5e7eb;
	border-radius: 6rpx;
	overflow: hidden;
	min-width: 160rpx;
}

.progress-fill-employee {
	height: 100%;
	border-radius: 6rpx;
}

.progress-fill-employee.excellent {
	background: linear-gradient(90deg, #8b5cf6 0%, #a78bfa 100%);
}

.progress-fill-employee.good {
	background: linear-gradient(90deg, #3b82f6 0%, #60a5fa 100%);
}

.progress-fill-employee.warning {
	background: linear-gradient(90deg, #f59e0b 0%, #fbbf24 100%);
}

.employee-rate {
	font-size: 26rpx;
	font-weight: 700;
	min-width: 90rpx;
	text-align: right;
}

.employee-rate.excellent {
	color: #8b5cf6;
}

.employee-rate.good {
	color: #3b82f6;
}

.employee-rate.warning {
	color: #f59e0b;
}

.employee-target {
	font-size: 22rpx;
	color: #999;
	margin-top: 8rpx;
}

.employee-target text {
	color: #666;
}

.empty-rank,
.loading-tip {
	text-align: center;
	color: #999;
	padding: 48rpx 0;
	font-size: 28rpx;
}
</style>
