<template>
	<view class="target-page">
		<merchant-target-tabs v-if="isMerchantMode" current="management" />
		<!-- #ifdef H5 -->
		<page-nav-bar v-if="!isMerchantMode" title="目标管理" theme="purple" />
		<!-- #endif -->
		<view class="filter-bar">
			<view class="filter-item" @click="pickYear">
				<text>{{ filterYear }}年</text>
				<text class="arrow">▼</text>
			</view>
			<view class="filter-item" @click="goObjectSelect">
				<text>{{ objectLabel }}</text>
				<text class="arrow">›</text>
			</view>
		</view>
		<scroll-view scroll-y class="scroll-body" @scrolltolower="loadMore">
			<view v-if="loading && !cards.length" class="loading-tip">加载中...</view>
			<view
				v-for="card in visibleCards"
				:key="card.id"
				class="target-card"
				@click="goDetail(card.id)"
			>
				<view class="card-header">
					<view>
						<view class="card-title">{{ card.name }}</view>
						<view class="card-subtitle">
							{{ displayMetrics(card).length }}项指标 · {{ card.object_name || '门店' }}
						</view>
					</view>
					<view class="card-right" @click.stop="noop">
						<text class="card-badge">{{ card.period_label }}</text>
						<view class="card-actions" v-if="canManageTarget">
							<text class="action copy" @click.stop="onCopy(card.id)">复制</text>
							<text class="action edit" @click.stop="onEdit(card.id)">编辑</text>
							<text class="action del" @click.stop="onDelete(card.id)">删除</text>
						</view>
					</view>
				</view>
				<view class="metric-list" :class="{ collapsed: !card.expanded }">
					<view
						v-for="(m, idx) in displayMetrics(card)"
						:key="m.listKey"
						class="metric-cell"
					>
						<metric-card :item="m" @click="goDetail(card.id)" />
					</view>
				</view>
				<view
					v-if="displayMetrics(card).length > 2"
					class="expand-btn"
					@click.stop="toggleExpand(card.id)"
				>
					<text>{{ card.expanded ? '收起' : '展开全部' }}</text>
					<text class="expand-count">（共{{ displayMetrics(card).length }}项）</text>
				</view>
			</view>
			<view v-if="!loading && !visibleCards.length" class="empty">{{ canManageTarget ? '暂无目标，点击右下角添加' : '暂无目标' }}</view>
		</scroll-view>
		<view class="fab" v-if="canManageTarget" @click="goSetting()">+</view>
		<bottom-nav current="management" />
	</view>
</template>

<script>
import { targetList, targetCopy, targetDelete, targetStoreOptionsTree } from '@/api/target.js';
import metricCard from '../components/metric-card.vue';
import bottomNav from '../components/bottom-nav.vue';
import pageNavBar from '../components/page-nav-bar.vue';
import merchantTargetTabs from '@/components/merchantTargetTabs/index.vue';
import {
	mergeMetrics,
	getTargetYearOptions,
	clampTargetYear,
	getApiErrorMessage,
	initTargetObjectFilter,
	toTargetInt,
	applyTargetNativeNavBar,
	buildStoreFilterApiParams,
	openTargetMerchantStoreSelect,
} from '../common/util.js';

export default {
	components: { metricCard, bottomNav, pageNavBar, merchantTargetTabs },
	data() {
		return {
			filterYear: clampTargetYear(new Date().getFullYear()),
			objectLabel: '',
			filterStoreId: '',
			filterStoreIds: [],
			filterManageRegionId: '',
			filterObjectType: '',
			filterOrgIds: [],
			filterExcludedStoreIds: [],
			filterRealtime: true,
			_urlObjectOverride: null,
			page: 1,
			limit: 10,
			count: 0,
			cards: [],
			loading: false,
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
		canManageTarget() {
			try {
				const perms = this.$store.state.merchant.permissions || [];
				if (this.isMerchantMode) {
					return perms.includes('merchant.target.manage');
				}
			} catch (e) {}
			return true;
		},
		visibleCards() {
			return this.cards.filter((card) => this.displayMetrics(card).length > 0);
		},
	},
	onLoad(options) {
		if (options && options.from === 'merchant') {
			try {
				this.$store.dispatch('merchant/enterMerchant');
			} catch (e) {}
		}
		if (options.store || options.store_id) {
			this._urlObjectOverride = {
				name: options.store ? decodeURIComponent(options.store) : '',
				id: options.store_id ? parseInt(options.store_id, 10) : 0,
				object_type: 1,
			};
		}
		if (options.timeRange) {
			const parts = String(options.timeRange).split('-');
			if (parts[0]) this.filterYear = clampTargetYear(parseInt(parts[0].split('.')[0], 10));
		}
	},
	async onShow() {
		applyTargetNativeNavBar(this.isMerchantMode ? '目标设置' : '目标管理');
		await this.syncObjectFilter();
		this._urlObjectOverride = null;
		this.page = 1;
		this.cards = [];
		this.fetchList();
	},
	methods: {
		async syncObjectFilter() {
			await initTargetObjectFilter({
				getOptions: () => targetStoreOptionsTree(),
				urlOverride: this._urlObjectOverride,
				applyFilter: (filter) => {
					this.objectLabel = filter.objectLabel;
					this.filterStoreId = filter.filterStoreId;
					this.filterStoreIds = filter.filterStoreIds || [];
					this.filterManageRegionId = filter.filterManageRegionId;
					this.filterObjectType = filter.filterObjectType;
					this.filterOrgIds = filter.org_ids || [];
					this.filterExcludedStoreIds = filter.excluded_store_ids || [];
					this.filterRealtime = filter.realtime !== false;
				},
			});
		},
		getCard(cardId) {
			return this.cards.find((c) => c.id === cardId) || null;
		},
		noop() {},
		targetStoreParams(card) {
			const storeId = card.store_id || card.object_id || 0;
			return storeId ? { store_id: storeId } : {};
		},
		displayMetrics(card) {
			return mergeMetrics(card).filter((m) => toTargetInt(m.target_value) > 0);
		},
		metricItemKey(m, idx) {
			if (m.is_product) {
				return `p-${m.product_id || idx}-${m.metric_key || ''}`;
			}
			return `m-${m.metric_key || idx}`;
		},
		toggleExpand(cardId) {
			const card = this.getCard(cardId);
			if (!card) return;
			this.$set(card, 'expanded', !card.expanded);
		},
		pickYear() {
			const years = getTargetYearOptions(true);
			uni.showActionSheet({
				itemList: years,
				success: (res) => {
					this.filterYear = clampTargetYear(parseInt(years[res.tapIndex], 10));
					this.reload();
				},
			});
		},
		goObjectSelect() {
			openTargetMerchantStoreSelect({ mode: 'multiple', snapshot: false, realtime: true });
		},
		reload() {
			this.page = 1;
			this.cards = [];
			this.fetchList();
		},
		loadMore() {
			if (this.cards.length >= this.count) return;
			this.page++;
			this.fetchList(true);
		},
		fetchList(append) {
			this.loading = true;
			targetList({
				year: this.filterYear,
				...buildStoreFilterApiParams({
					filterStoreId: this.filterStoreId,
					filterStoreIds: this.filterStoreIds,
					filterManageRegionId: this.filterManageRegionId,
					filterObjectType: this.filterObjectType,
					org_ids: this.filterOrgIds,
					excluded_store_ids: this.filterExcludedStoreIds,
					resolved_store_ids: this.filterStoreIds,
				}),
				page: this.page,
				limit: this.limit,
			})
				.then((res) => {
					const data = res.data || {};
					const list = (data.cards || data.list || []).map((c) => ({
						...c,
						expanded: false,
					}));
					this.count = data.count || 0;
					this.cards = append ? this.cards.concat(list) : list;
				})
				.catch((e) => {
					uni.showToast({ title: getApiErrorMessage(e, '加载失败'), icon: 'none' });
				})
				.finally(() => {
					this.loading = false;
				});
		},
		goDetail(cardId) {
			uni.navigateTo({
				url: `/pages/admin/target/detail/month?id=${cardId}`,
			});
		},
		goSetting(id) {
			const q = [];
			if (id) q.push(`id=${id}`);
			if (this.isMerchantMode) q.push('from=merchant');
			const qs = q.length ? `?${q.join('&')}` : '';
			uni.navigateTo({ url: `/pages/admin/target/setting/index${qs}` });
		},
		onEdit(cardId) {
			this.goSetting(cardId);
		},
		onCopy(cardId) {
			const card = this.getCard(cardId);
			if (!card) return;
			uni.showModal({
				title: '提示',
				content: '确定复制该目标吗？',
				success: (r) => {
					if (!r.confirm) return;
					targetCopy(cardId, this.targetStoreParams(card)).then(() => {
						uni.showToast({ title: '复制成功' });
						this.reload();
					});
				},
			});
		},
		onDelete(cardId) {
			const card = this.getCard(cardId);
			if (!card) return;
			uni.showModal({
				title: '提示',
				content: '确定删除该目标吗？',
				success: (r) => {
					if (!r.confirm) return;
					targetDelete(cardId, this.targetStoreParams(card)).then(() => {
						uni.showToast({ title: '已删除' });
						this.reload();
					});
				},
			});
		},
	},
};
</script>

<style scoped lang="scss">
@import '../common/target-form.scss';

.target-page {
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
}
.filter-item {
	display: flex;
	align-items: center;
	@include target-chip-button;
	background: #f5f5f5;
	border-radius: 32rpx;
	color: #666;
}
.arrow {
	margin-left: 8rpx;
	font-size: 22rpx;
	color: #999;
}
.scroll-body {
	height: calc(100vh - 176rpx);
	/* #ifdef H5 */
	height: calc(100vh - 264rpx);
	/* #endif */
	padding: 24rpx;
	box-sizing: border-box;
}
.target-card {
	background: #fff;
	border-radius: 24rpx;
	padding: 32rpx;
	margin-bottom: 24rpx;
	box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.06);
}
.card-header {
	display: flex;
	justify-content: space-between;
	margin-bottom: 32rpx;
}
.card-title {
	font-size: 40rpx;
	font-weight: 700;
	color: #333;
}
.card-subtitle {
	font-size: 24rpx;
	color: #999;
	margin-top: 8rpx;
}
.card-right {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
}
.card-badge {
	padding: 6rpx 16rpx;
	background: #d1fae5;
	color: #059669;
	font-size: 22rpx;
	border-radius: 20rpx;
	margin-bottom: 12rpx;
}
.card-actions {
	display: flex;
	gap: 12rpx;
}
.action {
	@include target-chip-button;
	min-height: 56rpx;
	padding: 12rpx 24rpx;
	font-size: 24rpx;
	border-radius: 8rpx;
}
.action.copy {
	background: #f0fdf4;
	color: #22c55e;
}
.action.edit {
	background: #eef2ff;
	color: #8b5cf6;
}
.action.del {
	background: #fef2f2;
	color: #ef4444;
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
	text-align: center;
	color: #8b5cf6;
	font-size: 28rpx;
	padding: 24rpx 0 0;
	border-top: 1rpx solid #f5f5f5;
	margin-top: 16rpx;
	min-height: 72rpx;
	line-height: 72rpx;
}
.expand-count {
	font-size: 24rpx;
	color: #999;
	margin-left: 8rpx;
}
.fab {
	position: fixed;
	right: 32rpx;
	bottom: 140rpx;
	width: 112rpx;
	height: 112rpx;
	background: #8b5cf6;
	color: #fff;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 56rpx;
	box-shadow: 0 8rpx 24rpx rgba(139, 92, 246, 0.4);
	z-index: 98;
}
.empty,
.loading-tip {
	text-align: center;
	color: #999;
	padding: 80rpx 0;
	font-size: 28rpx;
}
</style>
