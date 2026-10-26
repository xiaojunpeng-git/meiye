<template>
	<view class="analysis-page">
		<merchant-target-tabs v-if="isMerchantMode" current="analysis" />
		<!-- #ifdef H5 -->
		<page-nav-bar v-if="!isMerchantMode" title="目标分析" theme="purple" />
		<!-- #endif -->
		<view class="filter-bar">
			<view class="filter-item" @click="openPeriodModal">
				<text>{{ periodFilterText }}</text>
				<text class="arrow">▼</text>
			</view>
			<view class="filter-item" @click="goObjectSelect">
				<text>{{ objectLabel }}</text>
				<text class="arrow">▼</text>
			</view>
			<view class="filter-item active" @click="openMetricModal">
				<text>指标{{ visibleMetrics.length }}个</text>
				<text class="arrow">▼</text>
			</view>
		</view>

		<scroll-view scroll-y class="scroll-body">
			<view class="container">
				<!-- 指标完成情况 -->
				<view class="metrics-section">
					<view class="section-header">
						<view class="section-title">
							<uni-icons type="bars" size="18" color="#8B5CF6" />
							<text>指标完成情况</text>
						</view>
						<view class="expand-btn" @click="toggleMetricsExpanded">
							<text>{{ metricsExpanded ? '收起' : '展开全部' }}</text>
							<uni-icons
								:type="metricsExpanded ? 'top' : 'bottom'"
								size="14"
								color="#8B5CF6"
							/>
						</view>
					</view>
					<view class="metrics-grid" :class="{ collapsed: !metricsExpanded }">
						<view
							v-for="(m, idx) in visibleMetrics"
							:key="m._key"
							class="metric-cell"
						>
							<metric-card
								:item="m"
								:active="selectedKey === getMetricSelectKey(m)"
								@click="selectMetric"
							/>
						</view>
					</view>
					<view v-if="!loading && !visibleMetrics.length" class="empty-tip">暂无指标数据</view>
				</view>

				<!-- 同比环比分析 -->
				<view class="compare-section">
					<view class="compare-header">
						<view class="compare-title">
							<uni-icons type="bars" size="18" color="#8B5CF6" />
							<text>
								<text class="metric-highlight">{{ selectedMetricName }}</text>
								趋势对比分析
							</text>
						</view>
						<view class="compare-tabs">
							<text
								class="compare-tab"
								:class="{ active: compareTab === 'yoy' }"
                @click="() => setCompareTab('yoy')"
							>同比</text>
							<text
								class="compare-tab"
								:class="{ active: compareTab === 'mom' }"
                @click="() => setCompareTab('mom')"
							>环比</text>
						</view>
					</view>

					<view class="compare-date">
						<text class="compare-date-label">对比周期：</text>
						<text class="compare-date-value">{{ compareDateCurrent }}</text>
						<uni-icons type="arrow-right" size="12" color="#8B5CF6" />
						<text class="compare-date-value">{{ compareDatePrevious }}</text>
					</view>

					<view class="compare-grid">
						<view class="compare-item">
							<text class="compare-item-label">本期完成</text>
							<text class="compare-item-value">{{ formatAnalysisNumber(compareData.current) }}</text>
							<view class="compare-item-change" :class="compareChangeClass">
								<text>{{ compareChangeText }}</text>
							</view>
						</view>
						<view class="compare-item">
							<text class="compare-item-label">{{ compareTab === 'yoy' ? '去年同期' : '上一周期' }}</text>
							<text class="compare-item-value">{{ formatAnalysisNumber(compareData.previous) }}</text>
							<view class="compare-item-change flat">
								<text>基准值</text>
							</view>
						</view>
						<view class="compare-item">
							<text class="compare-item-label">达成率</text>
							<text class="compare-item-value">{{ selectedMetricRate }}%</text>
							<view
								class="compare-item-change"
								:class="compareAchieveClass"
							>
								<text>{{ getTargetAchieveResultText(selectedMetricRate) }}</text>
							</view>
						</view>
					</view>

					<view class="chart-wrapper">
						<view class="chart-header">
							<text class="chart-title">趋势对比图</text>
							<view class="chart-legend">
								<view class="legend-item">
									<view class="legend-line current" />
									<text>本期</text>
								</view>
								<view class="legend-item">
									<view class="legend-line compare" />
									<text>对比期</text>
								</view>
							</view>
						</view>
						<view class="chart-container">
							<canvas
								canvas-id="trendChart"
								id="trendChart"
								class="chart-canvas"
								:width="chartCanvasW"
								:height="chartCanvasH"
								:style="{
									width: chartCanvasW + 'px',
									height: chartCanvasH + 'px',
								}"
							/>
						</view>
					</view>
				</view>

				<!-- 三个 Tab -->
				<view class="view-tabs-section">
					<view class="view-tabs">
						<view
							class="view-tab"
							:class="{ active: viewTab === 'monthly' }"
							@click="switchViewTab('monthly')"
						>每月完成情况</view>
						<view
							class="view-tab"
							:class="{ active: viewTab === 'store' }"
							@click="switchViewTab('store')"
						>门店排行</view>
						<view
							class="view-tab"
							:class="{ active: viewTab === 'employee' }"
							@click="switchViewTab('employee')"
						>员工排行</view>
					</view>
				</view>

				<!-- 每月完成情况 -->
				<view v-show="viewTab === 'monthly'" class="monthly-section">
					<view class="monthly-header">
						<view class="monthly-title">
							<text class="highlight">{{ selectedMetricName }}</text>
							<text>每月完成情况</text>
						</view>
						<text class="monthly-subtitle">{{ monthlySubtitle }}</text>
					</view>
					<view class="dynamic-calc-info">
						<uni-icons type="info" size="16" color="#F59E0B" />
						<text>缺口分摊：某月未达标产生的缺口，平均追加到后续（筛选范围内）已设目标的月份。仅展示已设目标的月份；未设目标的月份不参与分摊也不展示。</text>
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
						<view v-if="!monthlyList.length && !loading" class="empty-tip">
							当前筛选范围内暂无已设目标的月份
						</view>
					</view>
				</view>

				<!-- 门店/员工排行 -->
				<view v-show="viewTab !== 'monthly'" class="ranking-section">
					<view class="ranking-header">
						<view class="ranking-title">
							<uni-icons type="medal-filled" size="18" color="#8B5CF6" />
							<text>
								<text class="metric-highlight">{{ selectedMetricName }}</text>
								{{ viewTab === 'store' ? '门店排行' : '员工排行' }}
							</text>
						</view>
					</view>
					<view class="ranking-controls">
						<view class="sort-type-tabs">
							<text
								class="sort-type-tab"
								:class="{ active: sortType === 'rate' }"
								@click="setSortType('rate')"
							>达成率</text>
							<text
								class="sort-type-tab"
								:class="{ active: sortType === 'value' }"
								@click="setSortType('value')"
							>数值</text>
						</view>
						<view class="sort-btn" @click="toggleSortOrder">
							<text>{{ sortAsc ? '正序' : '倒序' }}</text>
							<uni-icons type="bars" size="14" color="#999" />
						</view>
					</view>
					<view class="ranking-list">
						<view
							v-for="(row, idx) in sortedRankingList"
							:key="idx"
							class="ranking-item"
							:class="{ 'store-item': viewTab === 'store' }"
							@click="onRankingRowClick(idx)"
						>
							<view class="ranking-number" :class="idx < 3 ? 'top3' : 'normal'">
								{{ idx + 1 }}
							</view>
							<view class="ranking-info">
								<text class="ranking-name">{{ row.name }}</text>
								<text class="ranking-target">目标: {{ formatAnalysisNumber(row.target) }}</text>
							</view>
							<view class="ranking-value">
								<text class="ranking-amount">{{ formatAnalysisNumber(row.completed) }}</text>
								<text class="ranking-rate" :class="row.rateClass">
									达成率 {{ row.rate }}%
								</text>
							</view>
						</view>
						<view v-if="!sortedRankingList.length && !rankingLoading" class="empty-tip">
							暂无排行数据
						</view>
					</view>
				</view>
			</view>
		</scroll-view>

		<bottom-nav current="analysis" />

		<!-- 时间选择弹窗 -->
		<view v-if="periodModalVisible" class="modal-mask" @click="cancelPeriod">
			<view class="modal-sheet period-sheet" @click.stop="noop">
				<view class="modal-head">
					<text class="modal-title">选择时间</text>
					<view class="modal-close" @click="cancelPeriod">
						<uni-icons type="closeempty" size="18" color="#666" />
					</view>
				</view>
				<scroll-view scroll-y class="modal-body period-modal-body">
					<text class="picker-label">开始时间</text>
					<picker-view
						class="period-picker"
						:value="startPickerValue"
						@change="onStartPickerChange"
					>
						<picker-view-column>
							<view v-for="y in yearOptions" :key="y" class="picker-item">{{ y }}年</view>
						</picker-view-column>
						<picker-view-column>
							<view
								v-for="(m, mi) in monthPickerLabels"
								:key="mi"
								class="picker-item"
							>{{ m }}</view>
						</picker-view-column>
					</picker-view>
					<text class="picker-label">结束时间</text>
					<picker-view
						class="period-picker"
						:value="endPickerValue"
						@change="onEndPickerChange"
					>
						<picker-view-column>
							<view v-for="y in yearOptions" :key="y" class="picker-item">{{ y }}年</view>
						</picker-view-column>
						<picker-view-column>
							<view
								v-for="(m, mi) in monthPickerLabels"
								:key="mi"
								class="picker-item"
							>{{ m }}</view>
						</picker-view-column>
					</picker-view>
					<view class="selected-period">
						<text class="selected-period-label">已选时间</text>
						<text class="selected-period-value">{{ selectedPeriodPreview }}</text>
					</view>
				</scroll-view>
				<view class="modal-foot period-modal-foot">
					<button class="modal-confirm period-confirm-btn" hover-class="none" @tap.stop="confirmPeriod">
						确定
					</button>
				</view>
			</view>
		</view>

		<!-- 指标选择弹窗 -->
		<view v-if="metricModalVisible" class="modal-mask" @click="closeMetricModal">
			<view class="modal-sheet" @click.stop="noop">
				<view class="modal-head">
					<text class="modal-title">选择指标</text>
					<view class="modal-close" @click="closeMetricModal">
						<uni-icons type="closeempty" size="18" color="#666" />
					</view>
				</view>
				<scroll-view scroll-y class="metric-modal-body">
					<text class="metric-group-title">核心指标</text>
					<view class="metric-tags">
						<text
							v-for="m in coreMetricOptions"
							:key="m._key"
							class="metric-tag"
							:class="{ active: draftMetricKeys.includes(m._key) }"
							@click="toggleDraftMetric(m._key)"
						>{{ m.metric_name }}</text>
					</view>
					<text class="metric-group-title">商品指标</text>
					<view class="metric-tags">
						<text
							v-for="m in productMetricOptions"
							:key="m._key"
							class="metric-tag"
							:class="{ active: draftMetricKeys.includes(m._key) }"
							@click="toggleDraftMetric(m._key)"
						>{{ m.metric_name }}</text>
					</view>
				</scroll-view>
				<view class="modal-foot split">
					<button class="modal-secondary" @click="goAddProduct">+ 添加品项</button>
					<button class="modal-confirm flex2" @click="confirmMetricConfig">
						确定 ({{ draftMetricKeys.length }})
					</button>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import { targetAnalysis, targetList, targetRanking, targetStoreOptionsTree } from '@/api/target.js';
import uniIcons from '@/uni_modules/uni-icons/components/uni-icons/uni-icons.vue';
import metricCard from '../components/metric-card.vue';
import bottomNav from '../components/bottom-nav.vue';
import pageNavBar from '../components/page-nav-bar.vue';
import merchantTargetTabs from '@/components/merchantTargetTabs/index.vue';
import {
	getTargetYearRange,
	clampTargetYear,
	MONTH_PICKER_LABELS,
	formatAnalysisNumber,
	analysisRateTier,
	buildTrendChartPaths,
	drawTrendChartCanvas,
	getApiErrorMessage,
	initTargetObjectFilter,
	formatProductMetricName,
	parseRankingResponse,
	mapMonthlyAchieveItem,
	getTargetAchieveResultText,
	isTargetAchieved,
	applyTargetNativeNavBar,
	buildStoreFilterApiParams,
	openTargetMerchantStoreSelect,
} from '../common/util.js';

const METRIC_CONFIG_KEY = 'target_analysis_metric_keys';
const PERIOD_CONFIG_KEY = 'target_analysis_period';

export default {
	components: { uniIcons, metricCard, bottomNav, pageNavBar, merchantTargetTabs },
	data() {
		const { years, current } = getTargetYearRange();
		const yi = years.indexOf(current) >= 0 ? years.indexOf(current) : 0;
		return {
			loading: false,
			rankingLoading: false,
			objectLabel: '',
			filterStoreId: '',
			filterStoreIds: [],
			filterManageRegionId: '',
			filterObjectType: '',
			filterOrgIds: [],
			filterExcludedStoreIds: [],
			isLastLevelStore: false,
			_urlObjectOverride: null,
			primaryTargetId: 0,
			allMetrics: [],
			compareList: [],
			storeRanking: [],
			employeeRanking: [],
			monthlyFromApi: [],
			trendFromApi: null,
			selectedKey: 'revenue',
			selectedMetricKeys: [],
			draftMetricKeys: [],
			metricsExpanded: false,
			compareTab: 'yoy',
			viewTab: 'monthly',
			sortType: 'rate',
			sortAsc: true,
			periodModalVisible: false,
			metricModalVisible: false,
			startYear: clampTargetYear(current),
			endYear: clampTargetYear(current),
			startMonth: 1,
			endMonth: 12,
			pendingStartYear: clampTargetYear(current),
			pendingEndYear: clampTargetYear(current),
			pendingStartMonth: 1,
			pendingEndMonth: 12,
			yearOptions: years,
			monthPickerLabels: MONTH_PICKER_LABELS,
			startPickerValue: [yi, 0],
			endPickerValue: [yi, 11],
			chartCanvasW: 360,
			chartCanvasH: 200,
		};
	},
	computed: {
		isMerchantMode() {
			try {
				return this.$store.state.merchant.mode === 'merchant';
			} catch (e) {
				return false;
			}
		},
		periodFilterText() {
			if (this.startYear === this.endYear) {
				return `${this.startYear}年${this.startMonth}-${this.endMonth}月`;
			}
			return `${this.startYear}年${this.startMonth}月-${this.endYear}年${this.endMonth}月`;
		},
		selectedPeriodPreview() {
			return `${this.pendingStartYear}年${this.pendingStartMonth}月-${this.pendingEndYear}年${this.pendingEndMonth}月`;
		},
		visibleMetrics() {
			if (!this.selectedMetricKeys.length) return this.allMetrics;
			return this.allMetrics.filter((m) =>
				this.selectedMetricKeys.includes(this.getMetricSelectKey(m))
			);
		},
		coreMetricOptions() {
			return this.allMetrics.filter((m) => !m.is_product);
		},
		productMetricOptions() {
			return this.allMetrics.filter((m) => m.is_product);
		},
		selectedMetric() {
			return (
				this.allMetrics.find((m) => this.getMetricSelectKey(m) === this.selectedKey) ||
				this.visibleMetrics[0] ||
				null
			);
		},
		selectedMetricName() {
			return this.selectedMetric ? this.selectedMetric.metric_name : '指标';
		},
		selectedMetricRate() {
			const m = this.selectedMetric;
			if (!m) return 0;
			return m.rate != null
				? m.rate
				: m.target_value > 0
					? Math.round((m.completed_value / m.target_value) * 1000) / 10
					: 0;
		},
		compareChangeText() {
			if (!this.compareData.hasCompare) {
				return '无可比';
			}
			return this.changeText(this.compareData.change);
		},
		compareData() {
			const m = this.selectedMetric;
			if (!m) {
				return { current: 0, previous: 0, change: 0, hasCompare: false };
			}
			const metricKey = this.getAnalysisMetricKey(m);
			const hit = this.compareList.find((c) => c.metric_key === metricKey);
			const current = Number(m.completed_value ?? hit?.current ?? 0);
			const isYoy = this.compareTab === 'yoy';
			const previous = Number(isYoy ? (hit?.yoy ?? hit?.previous ?? 0) : (hit?.mom ?? 0));
			const change = Number(isYoy ? (hit?.yoy_rate ?? 0) : (hit?.mom_rate ?? 0));
			return {
				current,
				previous,
				change: previous > 0 ? change : 0,
				hasCompare: previous > 0,
			};
		},
		compareDateCurrent() {
			return `${this.startYear}.${this.startMonth}-${this.endYear}.${this.endMonth}`;
		},
		compareDatePrevious() {
			if (this.compareTab === 'yoy') {
				return `${this.startYear - 1}.${this.startMonth}-${this.endYear - 1}.${this.endMonth}`;
			}
			const months = this.endMonth - this.startMonth + 1 + (this.endYear - this.startYear) * 12;
			let pm = this.startMonth - months;
			let py = this.startYear;
			while (pm <= 0) {
				pm += 12;
				py -= 1;
			}
			let em = this.endMonth - months;
			let ey = this.endYear;
			while (em <= 0) {
				em += 12;
				ey -= 1;
			}
			return `${py}.${pm}-${ey}.${em}`;
		},
		compareChangeClass() {
			const change = this.compareData.change;
			if (change > 0) return 'up';
			if (change < 0) return 'down';
			return 'flat';
		},
		compareAchieveClass() {
			return Number(this.selectedMetricRate || 0) >= 100 ? 'up' : 'flat';
		},
		monthlySubtitle() {
			const n = this.monthlyList.length;
			return n ? `${n}个月 · 缺口动态分摊` : '暂无已设目标月份';
		},
		monthlyList() {
			return (this.monthlyFromApi || []).map(mapMonthlyAchieveItem);
		},
		sortedRankingList() {
			const source =
				this.viewTab === 'employee' ? this.employeeRanking : this.storeRanking;
			const list = source.map((r) => {
				const rate = Number(r.rate || 0);
				return {
					name: r.staff_name || r.store_name || r.name,
					target: Number(r.target_value || r.target || 0),
					completed: Number(r.completed_value || r.completed || r.value || 0),
					rate,
					rateClass: rate >= 100 ? 'high' : 'low',
					store_id: r.store_id,
				};
			});
			list.sort((a, b) => {
				const va = this.sortType === 'rate' ? a.rate : a.completed;
				const vb = this.sortType === 'rate' ? b.rate : b.completed;
				// 正序：数值/达成率越大越靠前；倒序：越小越靠前
				const diff = vb - va;
				return this.sortAsc ? diff : -diff;
			});
			return list;
		},
	},
	watch: {
		filterStoreId() {
			this.loadAnalysis();
		},
		filterStoreIds: {
			handler() {
				this.loadAnalysis();
			},
			deep: true,
		},
		selectedKey() {
			this.loadEmployeeRanking();
			this.fetchAnalysisExtras();
		},
		compareTab() {
			this.fetchAnalysisExtras();
			this.scheduleDrawTrendChart();
		},
		trendFromApi() {
			this.scheduleDrawTrendChart();
		},
		viewTab(tab) {
			if (tab === 'employee') this.loadEmployeeRanking();
		},
	},
	onLoad(options) {
		if (options && options.from === 'merchant') {
			try {
				this.$store.dispatch('merchant/enterMerchant');
			} catch (e) {}
		}
		this.restorePeriodConfig();
	},
	onReady() {
		this.scheduleDrawTrendChart();
	},
	async onShow() {
		applyTargetNativeNavBar(this.isMerchantMode ? '目标看板' : '目标分析');
		await this.syncObjectFilter();
		this._urlObjectOverride = null;
		this.loadAnalysis();
	},
	methods: {
		formatAnalysisNumber,
		analysisRateTier,
		isTargetAchieved,
		getTargetAchieveResultText,
		async syncObjectFilter() {
			await initTargetObjectFilter({
				getOptions: () => targetStoreOptionsTree(),
				urlOverride: this._urlObjectOverride,
				applyFilter: (filter) => {
					this.objectLabel = filter.objectLabel;
					this.filterStoreId = filter.filterStoreId;
					this.filterStoreIds = filter.filterStoreIds || [];
					this.filterManageRegionId = filter.filterManageRegionId || '';
					this.filterObjectType = filter.filterObjectType;
					this.filterOrgIds = filter.org_ids || [];
					this.filterExcludedStoreIds = filter.excluded_store_ids || [];
					this.isLastLevelStore = filter.isLastLevelStore;
				},
			});
		},
		noop() {},
		toggleMetricsExpanded() {
			this.metricsExpanded = !this.metricsExpanded;
		},
		setCompareTab(tab) {
			if (this.compareTab === tab) return;
			this.compareTab = tab;
		},
		setSortType(type) {
			if (this.sortType === type) return;
			this.sortType = type;
		},
		closeMetricModal() {
			this.metricModalVisible = false;
		},
		onRankingRowClick(idx) {
			if (this.viewTab !== 'store') return;
			const row = this.sortedRankingList[idx];
			if (row) this.goStoreTarget(row);
		},
		goObjectSelect() {
			openTargetMerchantStoreSelect({ mode: 'multiple', snapshot: false, realtime: true });
		},
		goAddProduct() {
			this.metricModalVisible = false;
			uni.navigateTo({ url: '/pages/admin/target/select/product' });
		},
		goStoreTarget(row) {
			let storeId = row.store_id;
			if (!storeId && row.name) {
				const matched = (this.storeRanking || []).find(
					(r) => (r.store_name || r.name) === row.name && r.store_id
				);
				storeId = matched?.store_id;
			}
			if (!storeId) {
				uni.showToast({ title: '门店信息不完整', icon: 'none' });
				return;
			}
			const q = [
				`store_id=${storeId}`,
				`store=${encodeURIComponent(row.name || row.store_name || '')}`,
				`startYear=${this.startYear}`,
				`startMonth=${this.startMonth}`,
				`endYear=${this.endYear}`,
				`endMonth=${this.endMonth}`,
				`timeRange=${encodeURIComponent(this.periodFilterText)}`,
				'from=analysis',
			].join('&');
			uni.navigateTo({
				url: `/pages/admin/target/detail/period?${q}`,
			});
		},
		getMetricSelectKey(m) {
			if (m.is_product) {
				return `product_${m.product_id || 0}_${m.metric_key || 'revenue'}`;
			}
			return m.metric_key || '';
		},
		metricItemKey(m, idx) {
			return `${this.getMetricSelectKey(m)}_${idx}`;
		},
		selectMetric(m) {
			this.selectedKey = this.getMetricSelectKey(m);
		},
		switchViewTab(tab) {
			this.viewTab = tab;
			if (tab === 'employee') {
				this.loadEmployeeRanking();
			}
		},
		toggleSortOrder() {
			this.sortAsc = !this.sortAsc;
		},
		changeClass(change) {
			if (change > 0) return 'up';
			if (change < 0) return 'down';
			return 'flat';
		},
		changeText(change) {
			if (change > 0) return `+${change}%`;
			if (change < 0) return `${change}%`;
			return '0%';
		},
		formatMonthParam(year, month) {
			const y = parseInt(year, 10);
			const m = parseInt(month, 10);
			if (!y || !m) return '';
			return `${y}-${String(m).padStart(2, '0')}`;
		},
		getAnalysisMetricKey(m) {
			if (!m) return 'revenue';
			if (m.is_product) {
				return (
					m.allocate_ref_key ||
					`product_${m.product_id || 0}_${m.metric_key || 'revenue'}`
				);
			}
			return m.metric_key || 'revenue';
		},
		buildAnalysisParams() {
			const m = this.selectedMetric;
			return {
				start_month: this.formatMonthParam(this.startYear, this.startMonth),
				end_month: this.formatMonthParam(this.endYear, this.endMonth),
				year: String(this.startYear),
				...buildStoreFilterApiParams({
					filterStoreId: this.filterStoreId,
					filterStoreIds: this.filterStoreIds,
					filterManageRegionId: this.filterManageRegionId,
					filterObjectType: this.filterObjectType,
					org_ids: this.filterOrgIds,
					excluded_store_ids: this.filterExcludedStoreIds,
					resolved_store_ids: this.filterStoreIds,
				}),
				metric_key: this.getAnalysisMetricKey(m),
			};
		},
		fetchAnalysisExtras() {
			return targetAnalysis(this.buildAnalysisParams())
				.then((res) => {
					const d = res.data || {};
					this.monthlyFromApi = d.monthly || [];
					this.trendFromApi = d.trend || null;
					if (d.compare && d.compare.length) {
						this.compareList = d.compare;
					}
					if (d.ranking && d.ranking.length) {
						this.storeRanking = d.ranking.map((r) => ({
							store_name: r.store_name || r.name,
							store_id: r.store_id,
							target_value: r.target_value || r.target || 0,
							completed_value: r.completed_value || r.completed || 0,
							rate: r.rate || 0,
						}));
					}
					this.scheduleDrawTrendChart();
				})
				.catch(() => {
					this.monthlyFromApi = [];
					this.trendFromApi = null;
					this.scheduleDrawTrendChart();
				});
		},
		scheduleDrawTrendChart() {
			this.$nextTick(() => {
				this.drawTrendChart();
			});
		},
		initChartCanvasSize() {
			return new Promise((resolve) => {
				uni.createSelectorQuery()
					.in(this)
					.select('.chart-container')
					.boundingClientRect((rect) => {
						if (rect && rect.width > 0) {
							this.chartCanvasW = Math.floor(rect.width);
							this.chartCanvasH = Math.max(
								160,
								Math.floor((rect.width * 200) / 360)
							);
						}
						resolve();
					})
					.exec();
			});
		},
		async drawTrendChart() {
			await this.initChartCanvasSize();
			const trend = this.trendFromApi || {};
			const current = trend.current || [];
			const compare =
				this.compareTab === 'yoy' ? trend.yoy || [] : trend.mom || [];
			const chartData = current.length
				? buildTrendChartPaths(
						current,
						compare,
						this.chartCanvasW,
						this.chartCanvasH
				  )
				: buildTrendChartPaths(
						[0],
						[0],
						this.chartCanvasW,
						this.chartCanvasH
				  );
			const ctx = uni.createCanvasContext('trendChart', this);
			drawTrendChartCanvas(ctx, chartData, trend.labels || []);
			ctx.draw();
		},
		restorePeriodConfig() {
			try {
				const saved = uni.getStorageSync(PERIOD_CONFIG_KEY);
				if (!saved || typeof saved !== 'object') return;
				const { startYear, endYear, startMonth, endMonth } = saved;
				if (startYear) this.startYear = clampTargetYear(startYear);
				if (endYear) this.endYear = clampTargetYear(endYear);
				if (startMonth) this.startMonth = Math.max(1, Math.min(12, parseInt(startMonth, 10)));
				if (endMonth) this.endMonth = Math.max(1, Math.min(12, parseInt(endMonth, 10)));
			} catch (e) {
				// ignore
			}
		},
		savePeriodConfig() {
			try {
				uni.setStorageSync(PERIOD_CONFIG_KEY, {
					startYear: this.startYear,
					endYear: this.endYear,
					startMonth: this.startMonth,
					endMonth: this.endMonth,
				});
			} catch (e) {
				// ignore
			}
		},
		openPeriodModal() {
			this.pendingStartYear = this.startYear;
			this.pendingEndYear = this.endYear;
			this.pendingStartMonth = this.startMonth;
			this.pendingEndMonth = this.endMonth;
			this.syncPickerValues();
			this.periodModalVisible = true;
		},
		cancelPeriod() {
			this.pendingStartYear = this.startYear;
			this.pendingEndYear = this.endYear;
			this.pendingStartMonth = this.startMonth;
			this.pendingEndMonth = this.endMonth;
			this.syncPickerValues();
			this.periodModalVisible = false;
		},
		syncPickerValues() {
			const yi = this.yearOptions.indexOf(this.pendingStartYear);
			const ye = this.yearOptions.indexOf(this.pendingEndYear);
			this.startPickerValue = [yi >= 0 ? yi : 0, this.pendingStartMonth - 1];
			this.endPickerValue = [ye >= 0 ? ye : 0, this.pendingEndMonth - 1];
		},
		applyPendingFromPickers() {
			const [syi, smi] = this.startPickerValue || [0, 0];
			const [eyi, emi] = this.endPickerValue || [0, 0];
			this.pendingStartYear = this.yearOptions[syi] ?? this.pendingStartYear;
			this.pendingStartMonth = smi + 1;
			this.pendingEndYear = this.yearOptions[eyi] ?? this.pendingEndYear;
			this.pendingEndMonth = emi + 1;
			this.ensurePeriodOrder();
		},
		onStartPickerChange(e) {
			const [yi, mi] = e.detail.value;
			this.startPickerValue = [yi, mi];
			this.pendingStartYear = this.yearOptions[yi] || this.pendingStartYear;
			this.pendingStartMonth = mi + 1;
			this.ensurePeriodOrder();
		},
		onEndPickerChange(e) {
			const [yi, mi] = e.detail.value;
			this.endPickerValue = [yi, mi];
			this.pendingEndYear = this.yearOptions[yi] || this.pendingEndYear;
			this.pendingEndMonth = mi + 1;
			this.ensurePeriodOrder();
		},
		ensurePeriodOrder() {
			const start = this.pendingStartYear * 100 + this.pendingStartMonth;
			const end = this.pendingEndYear * 100 + this.pendingEndMonth;
			if (end < start) {
				this.pendingEndYear = this.pendingStartYear;
				this.pendingEndMonth = this.pendingStartMonth;
				this.syncPickerValues();
			}
		},
		confirmPeriod() {
			this.applyPendingFromPickers();
			this.startYear = this.pendingStartYear;
			this.endYear = this.pendingEndYear;
			this.startMonth = this.pendingStartMonth;
			this.endMonth = this.pendingEndMonth;
			this.savePeriodConfig();
			this.periodModalVisible = false;
			this.loadAnalysis();
		},
		openMetricModal() {
			this.draftMetricKeys = [...this.selectedMetricKeys];
			if (!this.draftMetricKeys.length) {
				this.draftMetricKeys = this.allMetrics.map((m) => this.getMetricSelectKey(m));
			}
			this.metricModalVisible = true;
		},
		toggleDraftMetric(key) {
			const idx = this.draftMetricKeys.indexOf(key);
			if (idx >= 0) {
				if (this.draftMetricKeys.length <= 1) {
					return uni.showToast({ title: '至少保留一个指标', icon: 'none' });
				}
				this.draftMetricKeys.splice(idx, 1);
			} else {
				this.draftMetricKeys.push(key);
			}
		},
		confirmMetricConfig() {
			this.selectedMetricKeys = [...this.draftMetricKeys];
			uni.setStorageSync(METRIC_CONFIG_KEY, this.selectedMetricKeys);
			if (
				!this.visibleMetrics.find((m) => this.getMetricSelectKey(m) === this.selectedKey)
			) {
				this.selectedKey = this.getMetricSelectKey(this.visibleMetrics[0] || {});
			}
			this.metricModalVisible = false;
		},
		normalizeMetricRow(m, isProduct = false) {
			const rate =
				m.target_value > 0
					? Math.round((m.completed_value / m.target_value) * 1000) / 10
					: 0;
			const row = { ...m, rate, is_product: isProduct };
			row._key = this.getMetricSelectKey(row);
			return row;
		},
		mergeProductsFromCards(cards) {
			const map = {};
			(cards || []).forEach((card) => {
				(card.products || []).forEach((p) => {
					const key = `product_${p.product_id || 0}_${p.metric_key || 'revenue'}`;
					if (!map[key]) {
						map[key] = this.normalizeMetricRow(
							{
								...p,
								metric_name: formatProductMetricName(p),
								completed_value: p.completed_value || 0,
								target_value: p.target_value || 0,
							},
							true
						);
					} else {
						map[key].target_value =
							Number(map[key].target_value) + Number(p.target_value || 0);
						map[key].completed_value =
							Number(map[key].completed_value) + Number(p.completed_value || 0);
						map[key].rate =
							map[key].target_value > 0
								? Math.round(
										(map[key].completed_value / map[key].target_value) * 1000
								  ) / 10
								: 0;
					}
				});
			});
			return Object.values(map);
		},
		loadAnalysis() {
			this.loading = true;
			const params = this.buildAnalysisParams();
			const listParams = {
				year: this.startYear,
				...buildStoreFilterApiParams({
					filterStoreId: this.filterStoreId,
					filterStoreIds: this.filterStoreIds,
					filterManageRegionId: this.filterManageRegionId,
					filterObjectType: this.filterObjectType,
					org_ids: this.filterOrgIds,
					excluded_store_ids: this.filterExcludedStoreIds,
					resolved_store_ids: this.filterStoreIds,
				}),
				page: 1,
				limit: 100,
			};
			Promise.all([
				targetAnalysis(params),
				targetList(listParams),
			])
				.then(([analysisRes, listRes]) => {
					const d = analysisRes.data || {};
					const cards = (listRes.data || {}).cards || (listRes.data || {}).list || [];
					this.primaryTargetId = d.primary_target_id || cards[0]?.id || 0;
					this.compareList = d.compare || [];

					const core = (d.metrics || []).map((m) => this.normalizeMetricRow(m, false));
					let products = [];
					if ((d.products || []).length) {
						products = (d.products || []).map((p) =>
							this.normalizeMetricRow(
								{
									...p,
									metric_name: formatProductMetricName(p),
								},
								true
							)
						);
					} else {
						products = this.mergeProductsFromCards(cards);
					}
					this.allMetrics = [...core, ...products];

					const savedKeys = uni.getStorageSync(METRIC_CONFIG_KEY);
					if (savedKeys && savedKeys.length) {
						this.selectedMetricKeys = savedKeys.filter((k) =>
							this.allMetrics.some((m) => this.getMetricSelectKey(m) === k)
						);
					}
					if (!this.selectedMetricKeys.length) {
						this.selectedMetricKeys = this.allMetrics.map((m) =>
							this.getMetricSelectKey(m)
						);
					}
					if (
						!this.allMetrics.find((m) => this.getMetricSelectKey(m) === this.selectedKey)
					) {
						this.selectedKey = this.getMetricSelectKey(this.allMetrics[0] || {});
					}

					this.storeRanking = (d.ranking || []).map((r) => ({
						store_name: r.store_name || r.name,
						store_id: r.store_id,
						target_value: r.target_value || r.target || 0,
						completed_value: r.completed_value || r.completed || 0,
						rate: r.rate || 0,
					}));
					if (!this.storeRanking.length && cards.length > 1) {
						this.storeRanking = cards.slice(0, 8).map((c) => ({
							store_id: c.store_id,
							store_name: c.object_name || c.name,
							target_value: c.summary?.target_total || 0,
							completed_value: c.summary?.completed_total || 0,
							rate: c.summary?.rate || 0,
						}));
					}
					this.monthlyFromApi = d.monthly || [];
					this.trendFromApi = d.trend || null;
					this.scheduleDrawTrendChart();
					this.loadEmployeeRanking();
				})
				.catch((e) => {
					uni.showToast({ title: getApiErrorMessage(e, '加载失败'), icon: 'none' });
				})
				.finally(() => {
					this.loading = false;
				});
		},
		loadEmployeeRanking() {
			const metricKey = this.getAnalysisMetricKey(this.selectedMetric);
			if (!metricKey) return;
			this.rankingLoading = true;
			const params = {
				...this.buildAnalysisParams(),
				rank_type: 'employee',
				metric_key: metricKey,
				sort_type: this.sortType,
				asc: 0,
				page: 1,
				limit: 50,
			};
			targetRanking(params)
				.then((res) => {
					const parsed = parseRankingResponse(res);
					this.employeeRanking = parsed.list.map((r) => ({
						staff_name: r.staff_name || r.name,
						name: r.staff_name || r.name,
						target_value: r.target_value || r.target || 0,
						completed_value: r.value || r.completed_value || r.completed || 0,
						rate: r.rate || 0,
					}));
				})
				.catch(() => {
					this.employeeRanking = [];
				})
				.finally(() => {
					this.rankingLoading = false;
				});
		},
	},
};
</script>

<style scoped lang="scss">
@import '../common/target-form.scss';

.analysis-page {
	position: relative;
	overflow: hidden;
	min-height: 100vh;
	background: #f5f5f5;
	padding-bottom: 160rpx;
}

.filter-bar {
	background: #fff;
	padding: 24rpx 32rpx;
	display: flex;
	gap: 24rpx;
	overflow-x: auto;
	border-bottom: 1rpx solid #f0f0f0;
}

.filter-item {
	display: flex;
	align-items: center;
	gap: 12rpx;
	padding: 12rpx 24rpx;
	background: #f5f5f5;
	border-radius: 32rpx;
	font-size: 26rpx;
	color: #666;
	white-space: nowrap;
	flex-shrink: 0;
}

.filter-item.active {
	background: #8b5cf6;
	color: #fff;
}

.filter-item.active .arrow {
	color: #fff;
}

.arrow {
	font-size: 22rpx;
	color: #999;
}

.scroll-body {
	height: calc(100vh - 200rpx);
	/* #ifdef H5 */
	height: calc(100vh - 288rpx);
	/* #endif */
}

.container {
	padding: 24rpx;
}

.metrics-section,
.compare-section,
.monthly-section,
.ranking-section,
.view-tabs-section {
	background: #fff;
	border-radius: 24rpx;
	padding: 32rpx;
	margin-bottom: 24rpx;
	box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.06);
}

.section-header,
.compare-header,
.monthly-header,
.ranking-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 32rpx;
}

.section-title,
.compare-title,
.ranking-title {
	display: flex;
	align-items: center;
	gap: 16rpx;
	font-size: 32rpx;
	font-weight: 600;
	color: #333;
}

.expand-btn {
	display: flex;
	align-items: center;
	gap: 8rpx;
	font-size: 26rpx;
	color: #8b5cf6;
}

.metrics-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 24rpx;
}

.metric-cell {
	width: calc((100% - 24rpx) / 2);
	box-sizing: border-box;
}

.metrics-grid.collapsed .metric-cell:nth-child(n + 3) {
	display: none;
}

.compare-tabs {
	display: flex;
	gap: 16rpx;
	background: #f5f5f5;
	padding: 8rpx;
	border-radius: 40rpx;
}

.compare-tab {
	padding: 12rpx 32rpx;
	border-radius: 32rpx;
	font-size: 26rpx;
	color: #666;
}

.compare-tab.active {
	background: #8b5cf6;
	color: #fff;
}

.metric-highlight,
.highlight {
	color: #8b5cf6;
	font-weight: 700;
}

.compare-date {
	background: #f0f7ff;
	border-radius: 16rpx;
	padding: 24rpx 32rpx;
	margin-bottom: 32rpx;
	display: flex;
	align-items: center;
	gap: 16rpx;
	flex-wrap: wrap;
}

.compare-date-label {
	font-size: 24rpx;
	color: #666;
}

.compare-date-value {
	font-size: 28rpx;
	font-weight: 600;
	color: #333;
}

.compare-grid {
	display: flex;
	gap: 24rpx;
	margin-bottom: 40rpx;
}

.compare-item {
	flex: 1;
	text-align: center;
	padding: 32rpx 16rpx;
	background: #f8f9fa;
	border-radius: 24rpx;
}

.compare-item-label {
	display: block;
	font-size: 24rpx;
	color: #999;
	margin-bottom: 16rpx;
}

.compare-item-value {
	display: block;
	font-size: 40rpx;
	font-weight: 700;
	color: #333;
	margin-bottom: 12rpx;
}

.compare-item-change {
	font-size: 24rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8rpx;
}

.compare-item-change.up {
	color: #10b981;
}

.compare-item-change.down {
	color: #ef4444;
}

.compare-item-change.flat {
	color: #999;
}

.chart-wrapper {
	background: #fafafa;
	border-radius: 24rpx;
	padding: 32rpx;
}

.chart-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 32rpx;
}

.chart-title {
	font-size: 28rpx;
	font-weight: 600;
	color: #333;
}

.chart-legend {
	display: flex;
	gap: 32rpx;
}

.legend-item {
	display: flex;
	align-items: center;
	gap: 12rpx;
	font-size: 24rpx;
	color: #666;
}

.legend-line {
	width: 40rpx;
	height: 6rpx;
	border-radius: 4rpx;
}

.legend-line.current {
	background: #8b5cf6;
}

.legend-line.compare {
	background: #10b981;
}

.chart-container {
	height: 400rpx;
}

.chart-canvas {
	display: block;
	width: 100%;
	height: 100%;
}

.view-tabs {
	display: flex;
	gap: 16rpx;
}

.view-tab {
	flex: 1;
	padding: 24rpx 16rpx;
	text-align: center;
	border-radius: 16rpx;
	font-size: 28rpx;
	font-weight: 500;
	background: #f5f5f5;
	color: #666;
}

.view-tab.active {
	background: #8b5cf6;
	color: #fff;
}

.monthly-title {
	font-size: 32rpx;
	font-weight: 600;
	color: #333;
}

.monthly-subtitle {
	font-size: 26rpx;
	color: #999;
}

.dynamic-calc-info {
	background: #fff8e7;
	border-radius: 16rpx;
	padding: 24rpx 32rpx;
	margin: 24rpx 0 32rpx;
	display: flex;
	align-items: flex-start;
	gap: 20rpx;
	font-size: 26rpx;
	color: #666;
	line-height: 1.6;
}

.monthly-list {
	display: flex;
	flex-direction: column;
	gap: 24rpx;
}

.monthly-item {
	background: #f8f9fa;
	border-radius: 24rpx;
	padding: 32rpx;
}

.monthly-item-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 24rpx;
}

.monthly-item-name {
	font-size: 30rpx;
	font-weight: 600;
	color: #333;
}

.monthly-item-status {
	font-size: 24rpx;
	padding: 8rpx 20rpx;
	border-radius: 24rpx;
	font-weight: 500;
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
	gap: 32rpx;
	margin-bottom: 24rpx;
}

.monthly-item-value {
	flex: 1;
}

.monthly-item-label {
	display: block;
	font-size: 24rpx;
	color: #999;
	margin-bottom: 8rpx;
}

.monthly-item-number {
	display: block;
	font-size: 32rpx;
	font-weight: 700;
	color: #333;
}

.monthly-item-number.completed {
	color: #10b981;
}

.monthly-item-number.actual {
	color: #8b5cf6;
}

.monthly-item-progress {
	height: 16rpx;
	background: #e5e7eb;
	border-radius: 8rpx;
	overflow: hidden;
	margin-bottom: 16rpx;
}

.monthly-item-progress-bar {
	height: 100%;
	border-radius: 8rpx;
}

.bar-high {
	background: linear-gradient(90deg, #10b981 0%, #059669 100%);
}

.bar-medium {
	background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);
}

.bar-low {
	background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%);
}

.monthly-item-footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.monthly-item-rate {
	font-size: 26rpx;
	font-weight: 600;
	color: #666;
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
	font-size: 24rpx;
	font-weight: 500;
}

.ranking-controls {
	display: flex;
	gap: 24rpx;
	flex-wrap: wrap;
	margin-bottom: 32rpx;
	padding-bottom: 24rpx;
	border-bottom: 1rpx solid #f0f0f0;
	align-items: center;
}

.sort-type-tabs {
	display: flex;
	gap: 4rpx;
	background: #f0f0f0;
	padding: 6rpx;
	border-radius: 16rpx;
}

.sort-type-tab {
	padding: 12rpx 28rpx;
	border-radius: 12rpx;
	font-size: 26rpx;
	color: #666;
	font-weight: 500;
}

.sort-type-tab.active {
	background: #fff;
	color: #8b5cf6;
	box-shadow: 0 2rpx 6rpx rgba(0, 0, 0, 0.1);
}

.sort-btn {
	display: flex;
	align-items: center;
	gap: 8rpx;
	padding: 12rpx 28rpx;
	background: #f0f0f0;
	border-radius: 16rpx;
	font-size: 26rpx;
	color: #666;
	font-weight: 500;
}

.ranking-list {
	display: flex;
	flex-direction: column;
	gap: 24rpx;
}

.ranking-item {
	display: flex;
	align-items: center;
	gap: 24rpx;
	padding: 24rpx;
	background: #f8f9fa;
	border-radius: 24rpx;
}

.ranking-item.store-item:active {
	background: #e8e8e8;
}

.ranking-number {
	width: 56rpx;
	height: 56rpx;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 28rpx;
	font-weight: 600;
	flex-shrink: 0;
}

.ranking-number.top3 {
	background: linear-gradient(135deg, #ffd700 0%, #ffa500 100%);
	color: #fff;
}

.ranking-number.normal {
	background: #e5e7eb;
	color: #666;
}

.ranking-info {
	flex: 1;
	min-width: 0;
}

.ranking-name {
	display: block;
	font-size: 30rpx;
	font-weight: 500;
	color: #333;
	margin-bottom: 8rpx;
}

.ranking-target {
	display: block;
	font-size: 24rpx;
	color: #999;
}

.ranking-value {
	text-align: right;
}

.ranking-amount {
	display: block;
	font-size: 36rpx;
	font-weight: 700;
	color: #333;
}

.ranking-rate {
	display: block;
	font-size: 24rpx;
	font-weight: 600;
	margin-top: 4rpx;
}

.ranking-rate.high {
	color: #10b981;
}

.ranking-rate.medium {
	color: #f59e0b;
}

.ranking-rate.low {
	color: #ef4444;
}

.empty-tip {
	text-align: center;
	color: #999;
	padding: 48rpx 0;
	font-size: 28rpx;
}

.modal-mask {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	z-index: 200;
}

.modal-sheet {
	position: absolute;
	bottom: 0;
	left: 0;
	right: 0;
	background: #fff;
	border-radius: 40rpx 40rpx 0 0;
	max-height: 80vh;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.period-sheet {
	max-height: 85vh;
}

.modal-head {
	flex-shrink: 0;
	padding: 40rpx;
	border-bottom: 1rpx solid #f0f0f0;
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.modal-title {
	font-size: 34rpx;
	font-weight: 600;
}

.modal-close {
	width: 64rpx;
	height: 64rpx;
	background: #f5f5f5;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
}

.modal-body {
	padding: 32rpx 40rpx;
	box-sizing: border-box;
}

.period-modal-body {
	flex: 1;
	min-height: 0;
	max-height: calc(85vh - 280rpx);
}

.metric-modal-body {
	padding: 32rpx 40rpx;
	max-height: 50vh;
}

.picker-label {
	display: block;
	font-size: 28rpx;
	font-weight: 600;
	color: #333;
	margin-bottom: 24rpx;
}

.period-picker {
	height: 320rpx;
	border: 1rpx solid #e0e0e0;
	border-radius: 16rpx;
	margin-bottom: 24rpx;
}

.picker-item {
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 30rpx;
	color: #333;
}

.selected-period {
	background: #f8f9fa;
	border-radius: 16rpx;
	padding: 24rpx 32rpx;
	margin-bottom: 16rpx;
}

.selected-period-label {
	display: block;
	font-size: 24rpx;
	color: #999;
	margin-bottom: 8rpx;
}

.selected-period-value {
	font-size: 30rpx;
	font-weight: 600;
	color: #333;
}

.metric-group-title {
	display: block;
	font-size: 28rpx;
	font-weight: 600;
	color: #333;
	margin-bottom: 24rpx;
}

.metric-tags {
	display: flex;
	flex-wrap: wrap;
	gap: 20rpx;
	margin-bottom: 32rpx;
}

.metric-tag {
	padding: 20rpx 32rpx;
	border-radius: 40rpx;
	font-size: 26rpx;
	background: #f5f5f5;
	color: #666;
}

.metric-tag.active {
	background: #8b5cf6;
	color: #fff;
}

.modal-foot {
	flex-shrink: 0;
	padding: 32rpx 40rpx calc(32rpx + env(safe-area-inset-bottom));
	border-top: 1rpx solid #f0f0f0;
	background: #fff;
}

.period-modal-foot {
	padding-top: 24rpx;
}

.period-confirm-btn {
	width: 100%;
	height: 88rpx;
	line-height: 88rpx;
	border: none;
	border-radius: 16rpx;
	font-size: 30rpx;
	font-weight: 500;
	padding: 0;
	margin: 0;
}

.period-confirm-btn::after {
	border: none;
}

.modal-foot.split {
	display: flex;
	gap: 24rpx;
}

.modal-confirm {
	@include target-primary-button;
	background: #8b5cf6;
	color: #fff;
}

.modal-confirm.flex2 {
	flex: 2;
}

.modal-secondary {
	flex: 1;
	height: 88rpx;
	line-height: 88rpx;
	border: 1rpx solid #8b5cf6;
	background: #fff;
	color: #8b5cf6;
	border-radius: 16rpx;
	font-size: 30rpx;
}
</style>
