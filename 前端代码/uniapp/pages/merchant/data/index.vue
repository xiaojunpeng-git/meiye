<template>
	<view class="merchant-page">
		<merchant-date-filter v-model="dateFilter" @change="onDateChange" />
		<view class="tabs">
			<view
				v-for="t in tabs"
				:key="t.key"
				class="tab"
				:class="{ active: tab === t.key }"
				@click="switchTab(t.key)"
			>{{ t.name }}</view>
		</view>

		<view class="merchant-page__body">
			<template v-if="tab === 'business'">
				<business-board
					ref="businessBoard"
					:date-filter="dateFilter"
					:active="tab === 'business'"
				/>
				<view class="card" v-if="detailMenus.length">
					<view class="card__title">明细入口</view>
					<view class="menu">
						<view
							v-for="(item, i) in detailMenus"
							:key="i"
							class="menu__item"
							@click="onMenu(item)"
						>
							<view>
								<view class="menu__name">{{ item.name }}</view>
								<view class="menu__desc">{{ item.desc }}</view>
							</view>
							<text class="menu__arrow">›</text>
						</view>
					</view>
				</view>
			</template>

			<view v-else-if="loading" class="state">加载中…</view>


			<template v-else-if="tab === 'customer'">
				<view class="card" v-if="!canCustomerView">
					<view class="empty">暂无客户分析权限</view>
				</view>
				<view class="card" v-else>
					<view class="card__head">
						<text class="card__title">客户概览</text>
						<text class="card__sub">{{ dateFilter.display }}</text>
					</view>
					<view class="grid">
						<view
							v-for="(m, i) in (customer.metrics || [])"
							:key="m.metric_code || i"
							class="grid__item"
							@click="onMetricTap(m)"
						>
							<view class="grid__val">{{ formatMetric(m) }}</view>
							<view class="grid__label">
								{{ m.title }}
								<text v-if="m.tooltip_api" class="tip" @click.stop="onTooltip(m)">ⓘ</text>
							</view>
						</view>
					</view>
					<view class="hint" v-if="customer.note">{{ customer.note }}</view>
				</view>
			</template>


			<template v-else-if="tab === 'staff_perf'">
				<view class="card">
					<view class="card__title">员工业绩</view>
					<view class="menu" v-if="staffPerfMenus.length">
						<view
							v-for="(item, i) in staffPerfMenus"
							:key="i"
							class="menu__item"
							@click="onMenu(item)"
						>
							<view>
								<view class="menu__name">{{ item.name }}</view>
								<view class="menu__desc">{{ item.desc }}</view>
							</view>
							<text class="menu__arrow">›</text>
						</view>
					</view>
					<view class="empty" v-else>暂无可查看的员工业绩入口</view>
				</view>
			</template>


			<template v-else-if="tab === 'staff_stats'">
				<view class="card" v-if="staffStatsDenied">
					<view class="empty">暂无员工统计权限</view>
				</view>
				<view class="card" v-else>
					<view class="card__title">员工统计</view>
					<view
						v-for="(m, i) in staffStatMetrics"
						:key="i"
						class="menu__item"
						@click="onMetricTap(m)"
					>
						<view>
							<view class="menu__name">
								{{ m.title }}
								<text
									v-if="m.tooltip_api && !m.developing"
									class="tip"
									@click.stop="onTooltip(m)"
								>ⓘ</text>
							</view>
							<view class="menu__desc">{{ m.developing !== false ? '开发中' : formatNum(m.number) }}</view>
							<view class="menu__sub" v-if="!m.developing && m.detail_developing">明细开发中</view>
						</view>
						<text class="menu__arrow">›</text>
					</view>
					<view class="hint" v-if="staffStats.note">{{ staffStats.note }}</view>
					<view class="empty" v-if="!staffStatMetrics.length">暂无数据</view>
				</view>
			</template>


			<template v-else>
				<view class="card">
					<view class="empty">品项分析聚合层尚未统一，功能开发中</view>
				</view>
			</template>
		</view>
		<merchant-switch side="merchant" />
		<merchant-tab-bar current="home" />
	</view>
</template>

<script>
import merchantGuard from '@/mixins/merchantGuard.js';
import merchantTabBar from '@/components/merchantTabBar/index.vue';
import merchantDateFilter from '@/components/merchantDateFilter/index.vue';
import merchantSwitch from '@/components/merchantSwitch/index.vue';
import BusinessBoard from './components/BusinessBoard.vue';
import {
	merchantDataCustomer,
	merchantDataStaffStats,
} from '@/api/merchant.js';
import request from '@/utils/request.js';

export default {
	mixins: [merchantGuard],
	components: { merchantTabBar, merchantDateFilter, merchantSwitch, BusinessBoard },
	data() {
		const today = new Date();
		const pad = (n) => (n < 10 ? '0' + n : '' + n);
		const s = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
		return {
			tab: 'business',
			allTabs: [
				{ key: 'business', name: '经营数据' },
				{ key: 'customer', name: '客户分析' },
				{ key: 'staff_perf', name: '员工业绩' },
				{ key: 'staff_stats', name: '员工统计', needStoreAgg: true },
				{ key: 'item', name: '品项分析' },
			],
			dateFilter: {
				date_type: 'today',
				start_date: s,
				end_date: s,
				display: '今天',
			},
			customer: {},
			staffStats: {},
			loading: false,
			loadSeq: 0,
		};
	},
	computed: {
		activeRole() {
			return this.$store.state.merchant.activeRole || '';
		},
		perms() {
			return this.$store.state.merchant.permissions || [];
		},
		canDataAny() {
			return this.perms.some((p) =>
				['merchant.data.self', 'merchant.data.store', 'merchant.data.region'].includes(p)
			);
		},

		canStaffStats() {
			return this.perms.includes('merchant.data.store')
				|| this.perms.includes('merchant.data.region');
		},
		canCustomerView() {
			return this.perms.includes('merchant.customer.view');
		},
		staffStatsDenied() {
			return !this.canStaffStats;
		},
		tabs() {
			return (this.allTabs || []).filter((t) => !t.needStoreAgg || this.canStaffStats);
		},
		detailMenus() {
			const list = [];
			if (this.perms.includes('merchant.data.store') || this.activeRole === 'store_manager') {
				list.push({ name: '员工业绩', desc: '员工业绩排行（非三指标明细）', url: '/pages/admin/yeji/store' });
			}
			if (this.perms.includes('merchant.data.self')) {
				list.push({ name: '个人业绩', desc: '本人业绩明细', url: '/pages/merchant/yeji/self' });
			}
			return list;
		},
		staffPerfMenus() {
			return this.detailMenus;
		},
		staffStatMetrics() {
			const data = this.staffStats || {};
			const list = data.metrics || data.list || [];
			return Array.isArray(list) ? list : [];
		},
	},
	async onShow() {
		const ok = await this.ensureMerchantAccess();
		if (!ok) return;

		if (!this.canDataAny && !this.canCustomerView) {
			uni.showToast({ title: '暂无首页数据权限', icon: 'none' });
			uni.redirectTo({ url: '/pages/merchant/home/index' });
			return;
		}

		if (this.tab === 'staff_stats' && this.staffStatsDenied) {
			this.tab = this.canDataAny ? 'business' : (this.canCustomerView ? 'customer' : 'business');
		}
		if (this.tab === 'business') {
			this.$nextTick(() => {
				const board = this.$refs.businessBoard;
				if (!board) return;
				if (typeof board.onHostShow === 'function' && board.onHostShow()) return;
				if (board.switchingStore) return;
				if (board.scheduleReload) board.scheduleReload(true);
			});
			return;
		}
		this.reload();
	},
	onReachBottom() {
		const board = this.$refs.businessBoard;
		if (this.tab === 'business' && board && board.loadMore) {
			board.loadMore();
		}
	},
	methods: {
		formatMoney(n) {
			if (n === null || n === undefined || n === '') return '-';
			const v = Number(n);
			if (Number.isNaN(v)) return String(n);
			return v.toLocaleString('zh-CN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
		},
		formatNum(n) {
			if (n === null || n === undefined) return '-';
			return String(n);
		},
		formatMetric(m) {
			if (!m) return '-';
			if (m.developing || m.number === null || m.number === undefined) return '开发中';
			if (typeof m.number === 'number' || /^\d+(\.\d+)?$/.test(String(m.number))) {
				return this.formatMoney(m.number);
			}
			return this.formatNum(m.number);
		},
		filterParams() {
			return {
				date_type: this.dateFilter.date_type || 'today',
				start_date: this.dateFilter.start_date,
				end_date: this.dateFilter.end_date,
				active_store_id: this.$store.state.merchant.activeStoreId || 0,
				active_role: this.$store.state.merchant.activeRole || '',
			};
		},
		switchTab(key) {
			if (this.tab === key) return;
			this.tab = key;
			this.reload();
		},
		onDateChange(payload) {
			this.dateFilter = payload || this.dateFilter;
			if (this.tab === 'business') return;
			this.reload();
		},
		reload() {
			if (this.tab === 'business') {
				this.loading = false;
				this.$nextTick(() => {
					const board = this.$refs.businessBoard;
					if (board && board.scheduleReload) board.scheduleReload(true);
				});
				return;
			}
			if (this.tab === 'customer') this.loadCustomer();
			else if (this.tab === 'staff_stats') this.loadStaffStats();
			else this.loading = false;
		},
		async loadCustomer() {
			if (!this.canCustomerView) {
				this.customer = {};
				this.loading = false;
				return;
			}
			const seq = ++this.loadSeq;
			this.loading = true;
			try {
				const res = await merchantDataCustomer(this.filterParams());
				if (seq !== this.loadSeq) return;
				this.customer = (res && res.data) || {};
			} catch (e) {
				if (seq !== this.loadSeq) return;
				this.customer = {};
				const msg = (e && (e.msg || e.message)) || '加载失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				if (seq === this.loadSeq) this.loading = false;
			}
		},
		async loadStaffStats() {
			if (this.staffStatsDenied) {
				this.staffStats = {};
				this.loading = false;
				return;
			}
			const seq = ++this.loadSeq;
			this.loading = true;
			try {
				const res = await merchantDataStaffStats(this.filterParams());
				if (seq !== this.loadSeq) return;
				this.staffStats = (res && res.data) || {};
			} catch (e) {
				if (seq !== this.loadSeq) return;
				this.staffStats = {};
			} finally {
				if (seq === this.loadSeq) this.loading = false;
			}
		},
		onDeveloping(m) {
			if (m && (m.developing || m.detail_developing)) {
				uni.showToast({ title: '该功能正在开发中，敬请期待', icon: 'none' });
			}
		},
		onMetricTap(m) {
			if (m && (m.developing || m.number === null || m.number === undefined)) {
				this.onDeveloping(m);
				return;
			}
			if (m && m.detail_developing) {
				uni.showToast({ title: '指标明细开发中', icon: 'none' });
				return;
			}
			const code = m.metric_code || m.code || '';
			const detail = String(m.detail_api || '');
			const customerSegments = {
				new_customer: 'new_customer',
				card_recharge_customer: 'card_recharge',
				visit_customer: 'visit',
				repurchase_customer: 'repurchase',
			};
			if (customerSegments[code] || detail.indexOf('/pages/merchant/customer') === 0) {
				const segment = customerSegments[code] || 'new_customer';
				const title = encodeURIComponent(m.title || '客户');
				const q = [
					'tab=focus',
					`segment=${segment}`,
					`start_date=${this.dateFilter.start_date || ''}`,
					`end_date=${this.dateFilter.end_date || ''}`,
					`title=${title}`,
				].join('&');
				uni.navigateTo({ url: `/pages/merchant/customer/index?${q}` });
				return;
			}
			if (
				code === 'reservation_customer'
				|| code === 'reservation_order'
				|| detail.indexOf('/pages/admin/reservation_list') === 0
			) {
				const q = [
					'merchant=1',
					`start_date=${this.dateFilter.start_date || ''}`,
					`end_date=${this.dateFilter.end_date || ''}`,
				].join('&');
				uni.navigateTo({
					url: `/pages/admin/reservation_list/index?${q}`,
				});
				return;
			}
			if (detail.indexOf('/pages/merchant/yeji/self') === 0 || String(code).indexOf('staff_') === 0) {
				let sumType = 1;
				const matched = /(?:\?|&)sum_type=(\d+)/.exec(detail);
				if (matched) {
					sumType = Number(matched[1]) === 2 ? 2 : 1;
				} else if (code !== 'staff_sales_yeji') {
					sumType = 2;
				}
				const q = [
					`sum_type=${sumType}`,
					`start_date=${this.dateFilter.start_date || ''}`,
					`end_date=${this.dateFilter.end_date || ''}`,
					`date_type=${this.dateFilter.date_type || 'custom'}`,
				].join('&');
				uni.navigateTo({ url: `/pages/merchant/yeji/self?${q}` });
				return;
			}
			if (code === 'cash_performance' || detail.indexOf('/pages/merchant/metric/cash') === 0) {
				const q = [
					`start_date=${this.dateFilter.start_date || ''}`,
					`end_date=${this.dateFilter.end_date || ''}`,
					`date_type=${this.dateFilter.date_type || 'custom'}`,
				].join('&');
				uni.navigateTo({ url: `/pages/merchant/metric/cash?${q}` });
				return;
			}
			if (code === 'actual_performance' || detail.indexOf('/pages/merchant/metric/actual') === 0) {
				const q = [
					`start_date=${this.dateFilter.start_date || ''}`,
					`end_date=${this.dateFilter.end_date || ''}`,
					`date_type=${this.dateFilter.date_type || 'custom'}`,
				].join('&');
				uni.navigateTo({ url: `/pages/merchant/metric/actual?${q}` });
				return;
			}
			if (code === 'consume_amount' || detail.indexOf('/pages/merchant/metric/consume') === 0) {
				const q = [
					`start_date=${this.dateFilter.start_date || ''}`,
					`end_date=${this.dateFilter.end_date || ''}`,
					`date_type=${this.dateFilter.date_type || 'custom'}`,
				].join('&');
				uni.navigateTo({ url: `/pages/merchant/metric/consume?${q}` });
				return;
			}
			uni.showToast({ title: '指标明细开发中', icon: 'none' });
		},
		async onTooltip(m) {
			if (!m || !m.tooltip_api) return;
			try {
				const res = await request.get(m.tooltip_api);
				const d = (res && res.data) || {};
				const lines = [
					d.name || m.title || '',
					d.summary || '',
					d.include || '',
					d.exclude || '',
					d.timing || '',
					d.note || '',
				].filter(Boolean);
				uni.showModal({
					title: '指标口径',
					content: lines.join('\n') || '暂无说明',
					showCancel: false,
				});
			} catch (e) {
				uni.showToast({ title: '口径说明加载失败', icon: 'none' });
			}
		},
		onMenu(item) {
			if (!item || !item.url) return;
			uni.navigateTo({ url: item.url });
		},
	},
};
</script>

<style scoped>
.merchant-page {
	min-height: 100vh;
	background: #f5f6f8;
	padding-bottom: 140rpx;
}
.tabs {
	display: flex;
	background: #fff;
	padding: 0 8rpx 12rpx;
	overflow-x: auto;
	white-space: nowrap;
	position: sticky;
	top: 88rpx;
	z-index: 19;
}
.tab {
	display: inline-block;
	padding: 16rpx 20rpx;
	font-size: 26rpx;
	color: #666;
	position: relative;
}
.tab.active {
	color: #e93323;
	font-weight: 600;
}
.tab.active::after {
	content: '';
	position: absolute;
	left: 50%;
	bottom: 0;
	width: 40rpx;
	height: 4rpx;
	margin-left: -20rpx;
	background: #e93323;
	border-radius: 2rpx;
}
.merchant-page__body {
	padding: 24rpx;
}
.scope-bar {
	margin-bottom: 12rpx;
	font-size: 22rpx;
	color: #999;
}
.card {
	background: #fff;
	border-radius: 20rpx;
	padding: 28rpx 24rpx;
	margin-bottom: 20rpx;
}
.card__head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20rpx;
}
.card__title {
	font-size: 30rpx;
	font-weight: 600;
	color: #222;
}
.card__sub {
	font-size: 22rpx;
	color: #999;
}
.metrics__row {
	display: flex;
}
.metrics__row--wrap {
	flex-wrap: wrap;
}
.metrics__item {
	flex: 1;
	padding-right: 8rpx;
}
.metrics__item--third {
	flex: 0 0 33.33%;
	width: 33.33%;
	box-sizing: border-box;
	padding: 8rpx 8rpx 16rpx 0;
}
.metrics__label {
	font-size: 22rpx;
	color: #999;
}
.metrics__value {
	margin-top: 10rpx;
	font-size: 34rpx;
	font-weight: 600;
	color: #1a1a1a;
}
.metrics__sub {
	margin-top: 6rpx;
	font-size: 20rpx;
	color: #bbb;
}
.tip {
	margin-left: 6rpx;
	color: #e93323;
	font-size: 22rpx;
}
.menu__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 24rpx 0;
	border-bottom: 1rpx solid #f3f3f3;
}
.menu__item:last-child {
	border-bottom: none;
}
.menu__name {
	font-size: 28rpx;
	color: #222;
}
.menu__desc {
	margin-top: 6rpx;
	font-size: 22rpx;
	color: #999;
}
.menu__arrow {
	font-size: 32rpx;
	color: #ccc;
}
.rank-tabs {
	display: flex;
	margin: 8rpx 0 16rpx;
	gap: 12rpx;
}
.rank-tab {
	padding: 10rpx 20rpx;
	font-size: 24rpx;
	color: #666;
	background: #f5f6f8;
	border-radius: 8rpx;
}
.rank-tab.active {
	color: #e93323;
	background: #fff1f0;
	font-weight: 600;
}
.rank-head {
	display: flex;
	justify-content: space-between;
	font-size: 22rpx;
	color: #999;
	padding: 8rpx 0 12rpx;
}
.rank-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 20rpx 0;
	border-bottom: 1rpx solid #f3f3f3;
}
.rank-row:last-child {
	border-bottom: none;
}
.rank-row__name {
	flex: 1;
	min-width: 0;
	font-size: 28rpx;
	color: #222;
	padding-right: 16rpx;
}
.rank-row__idx {
	display: inline-block;
	width: 36rpx;
	color: #999;
	font-size: 24rpx;
}
.rank-row__num {
	font-size: 28rpx;
	font-weight: 600;
	color: #222;
}
.grid {
	display: flex;
	flex-wrap: wrap;
}
.grid__item {
	width: 50%;
	padding: 20rpx 0;
	box-sizing: border-box;
}
.grid__val {
	font-size: 34rpx;
	font-weight: 600;
	color: #222;
}
.grid__label {
	margin-top: 8rpx;
	font-size: 22rpx;
	color: #999;
}
.hint {
	margin-top: 16rpx;
	font-size: 22rpx;
	color: #aaa;
	line-height: 1.5;
}
.empty,
.state {
	padding: 40rpx 0;
	text-align: center;
	color: #aaa;
	font-size: 26rpx;
}
</style>
