<template>
	<view class="board">
		<view v-if="canRegion && !denied" class="card picker-card">
			<merchant-store-multi-picker
				v-model="storeFilter"
				label="门店范围"
				placeholder="选择组织或门店"
				:snapshot="false"
				:realtime="true"
				@change="onRegionStoreChange"
			/>
		</view>
		<view v-else-if="showStoreSinglePicker" class="card picker-card">
			<merchant-store-single-picker
				v-model="storeFilter"
				label="门店"
				placeholder="选择门店"
				:snapshot="true"
				:disabled="switchingStore"
			/>
			<view class="scope-tip">概览与排行均按当前门店，与上方选择保持一致</view>
		</view>

		<view v-if="loading && !headerMetrics.length" class="state">加载中…</view>

		<template v-else-if="denied">
			<view class="card">
				<view class="empty">暂无经营数据权限</view>
			</view>
		</template>

		<template v-else-if="loadError">
			<view class="card">
				<view class="empty">{{ loadError }}</view>
				<view class="retry" @click="scheduleReload(true)">点击重试</view>
			</view>
		</template>

		<template v-else-if="isStaffSelf">
			<view class="card">
				<view class="card__head">
					<text class="card__title">我的业绩</text>
					<text class="card__sub">{{ dateDisplay }}</text>
				</view>
				<view class="metrics__row" :class="{ 'metrics__row--wrap': headerMetrics.length > 3 }">
					<view
						v-for="(m, i) in headerMetrics"
						:key="m.metric_code || i"
						class="metrics__item"
						:class="{ 'metrics__item--third': headerMetrics.length > 3 }"
						@click="onMetricTap(m)"
					>
						<view class="metrics__label">
							{{ m.title }}
							<text v-if="m.tooltip_api" class="tip" @click.stop="onTooltip(m)">ⓘ</text>
						</view>
						<view class="metrics__value">{{ formatMetric(m) }}</view>
					</view>
				</view>
				<view class="empty" v-if="!headerMetrics.length">暂无数据</view>
			</view>
		</template>

		<template v-else>
			<view class="card">
				<view class="card__head">
					<text class="card__title">经营概览</text>
					<text class="card__sub">{{ dateDisplay }}</text>
				</view>
				<view class="metrics__row" v-if="headerMetrics.length">
					<view
						v-for="(m, i) in headerMetrics"
						:key="m.metric_code || i"
						class="metrics__item"
						@click="onMetricTap(m)"
					>
						<view class="metrics__label">
							{{ m.title }}
							<text v-if="m.tooltip_api" class="tip" @click.stop="onTooltip(m)">ⓘ</text>
						</view>
						<view class="metrics__value">{{ formatMetric(m) }}</view>
						<view class="metrics__sub" v-if="m.developing">口径开发中</view>
						<view class="metrics__sub" v-else-if="m.detail_developing">明细开发中</view>
					</view>
				</view>
				<view class="empty" v-else-if="!loading">暂无数据</view>
			</view>

			<view class="card">
				<view class="card__head">
					<text class="card__title">门店排行</text>
					<text class="card__sub">{{ dateDisplay }}</text>
				</view>
				<scroll-view scroll-x class="rank-tabs-scroll" :show-scrollbar="false">
					<view class="rank-tabs">
						<view
							v-for="t in visibleRankingTabs"
							:key="t.type"
							class="rank-tab"
							:class="{ active: showType === t.type }"
							@click="changeType(t.type)"
						>{{ t.name }}</view>
					</view>
				</scroll-view>

				<view class="rank-head" v-if="ranking.length">
					<text class="rank-col rank-col--store">门店名称</text>
					<text v-if="isStaffRank" class="rank-col rank-col--staff">手艺人</text>
					<text class="rank-col rank-col--num">{{ rankValueLabel }}</text>
				</view>
				<view
					v-for="(item, index) in ranking"
					:key="rankRowKey(item, index)"
					class="rank-row"
				>
					<template v-if="!isStaffRank">
						<text class="rank-col rank-col--store link" @click="toStore(item.store_id)">{{ item.name || ('门店#' + item.store_id) }}</text>
						<text class="rank-col rank-col--num">{{ formatMoney(item.number) }}</text>
					</template>
					<template v-else>
						<text class="rank-col rank-col--store link" @click="toStore(item.store_id)">{{ item.store_name || ('门店#' + item.store_id) }}</text>
						<text class="rank-col rank-col--staff link" @click="toStaff(item.staff_id)">{{ item.staff_name || '-' }}</text>
						<text class="rank-col rank-col--num">{{ formatRankYeji(item.yeji) }}</text>
					</template>
				</view>
				<view class="state" v-if="rankLoading">加载中…</view>
				<view class="empty" v-else-if="!ranking.length">暂无排行</view>
				<view class="hint" v-if="rankHint">{{ rankHint }}</view>
			</view>
		</template>
	</view>
</template>

<script>
import MerchantStoreMultiPicker from '@/components/merchantStoreMultiPicker/index.vue';
import MerchantStoreSinglePicker from '@/components/merchantStoreSinglePicker/index.vue';
import { consumePickerResult } from '@/components/merchantStorePicker/utils.js';
import { getAgentHeader, getAgentStore } from '@/api/admin.js';
import { agentYejiRanking, agentProjectRanking, agentDianke, yejiRanking, dianke } from '@/api/yeji.js';
import { merchantDataBusiness } from '@/api/merchant.js';
import request from '@/utils/request.js';

function buildStoreFilterApiParams(filter = {}) {
	const params = {};
	const storeIds = (filter.resolved_store_ids || filter.filterStoreIds || [])
		.map((id) => Number(id))
		.filter((id) => id > 0);
	if (storeIds.length > 1) {
		params.store_ids = storeIds.join(',');
	} else if (storeIds.length === 1) {
		params.store_id = storeIds[0];
		params.store_ids = String(storeIds[0]);
	} else if (filter.filterStoreId) {
		params.store_id = filter.filterStoreId;
	}
	if (filter.filterManageRegionId) {
		params.manage_region_id = filter.filterManageRegionId;
	}
	if (filter.filterObjectType) {
		params.object_type = filter.filterObjectType;
	}
	const orgIds = (filter.org_ids || []).map((id) => Number(id)).filter((id) => id > 0);
	if (orgIds.length) {
		params.org_ids = orgIds.join(',');
	}
	const excluded = (filter.excluded_store_ids || [])
		.map((id) => Number(id))
		.filter((id) => id > 0);
	if (excluded.length) {
		params.excluded_store_ids = excluded.join(',');
	}
	return params;
}

const RANK_TABS = [
	{ type: 1, name: '现金业绩' },
	{ type: 7, name: '实际业绩' },
	{ type: 2, name: '客户消耗金额' },
	{ type: 3, name: '员工现金业绩' },
	{ type: 4, name: '员工劳动业绩' },
	{ type: 5, name: '员工点客' },
	{ type: 6, name: '项目数排行' },
];

const METRIC_DETAIL = {
	cash_performance: '/pages/merchant/metric/cash',
	actual_performance: '/pages/merchant/metric/actual',
	consume_amount: '/pages/merchant/metric/consume',
};

export default {
	name: 'MerchantBusinessBoard',
	components: { MerchantStoreMultiPicker, MerchantStoreSinglePicker },
	props: {
		dateFilter: { type: Object, default: null },
		active: { type: Boolean, default: true },
	},
	data() {
		return {
			storeFilter: null,
			headerMetrics: [],
			ranking: [],
			showType: 1,
			sumType: 1,
			page: 1,
			limit: 20,
			loadend: false,
			loading: false,
			rankLoading: false,
			switchingStore: false,
			managerSwitchInflight: 0,
			lastPickerToken: '',
			loadError: '',
			metricsMode: 'store',
			reloadTimer: null,
			reqSeq: 0,
			rankSeq: 0,
			rankHint: '',
			rankingTabs: RANK_TABS,
			booted: false,
		};
	},
	computed: {
		perms() {
			return (this.$store.state.merchant && this.$store.state.merchant.permissions) || [];
		},
		activeRole() {
			return (this.$store.state.merchant && this.$store.state.merchant.activeRole) || '';
		},
		activeStoreId() {
			return Number((this.$store.state.merchant && this.$store.state.merchant.activeStoreId) || 0);
		},
		canRegion() {
			return false;
		},
		canStore() {
			return this.perms.indexOf('merchant.data.store') !== -1
				|| this.perms.indexOf('merchant.data.region') !== -1
				|| this.activeRole === 'store_manager';
		},
		canSelf() {
			return this.perms.indexOf('merchant.data.self') !== -1;
		},
		denied() {
			return !this.canRegion && !this.canStore && !this.canSelf;
		},
		isStaffSelf() {
			return this.metricsMode === 'staff_self' || (!this.canRegion && !this.canStore && this.canSelf);
		},
		showStoreSinglePicker() {
			return !this.denied && !this.isStaffSelf && this.canStore && !this.canRegion;
		},
		visibleRankingTabs() {
			if (this.canRegion) return this.rankingTabs;
			return this.rankingTabs.filter((t) => t.type !== 6);
		},
		isStaffRank() {
			return this.showType >= 3 && this.showType !== 7;
		},
		rankValueLabel() {
			if (this.showType === 5) return '数量';
			if (this.showType === 6) return '项目数';
			if (this.isStaffRank) return '业绩';
			return '金额';
		},
		dateDisplay() {
			return (this.dateFilter && this.dateFilter.display) || '';
		},
		dataRange() {
			const s = (this.dateFilter && this.dateFilter.start_date) || '';
			const e = (this.dateFilter && this.dateFilter.end_date) || '';
			if (!s || !e) return '';
			return `${s.replace(/-/g, '/')}-${e.replace(/-/g, '/')}`;
		},
		dateTap() {
			const map = { today: 0, yesterday: 1, last_month: 2, month: 3, custom: 4 };
			const t = (this.dateFilter && this.dateFilter.date_type) || 'today';
			return map[t] !== undefined ? map[t] : 4;
		},
	},
	watch: {
		dateFilter: {
			deep: true,
			handler() {
				if (!this.booted || !this.active) return;
				this.scheduleReload();
			},
		},
		activeStoreId(val) {
			if (!this.showStoreSinglePicker) return;
			this.syncManagerStoreFilter(val);
		},
	},
	mounted() {
		if (this.showStoreSinglePicker) {
			this.syncManagerStoreFilter(this.activeStoreId);
		}
	},
	beforeDestroy() {
		if (this.reloadTimer) clearTimeout(this.reloadTimer);
	},
	methods: {
		isStale(myReq, myRank) {
			return myReq !== this.reqSeq || myRank !== this.rankSeq;
		},
		pickerResultToken(payload) {
			if (!payload) return '';
			const sid = Number(
				payload.store_id || (payload.resolved_store_ids && payload.resolved_store_ids[0]) || 0
			);
			if (payload.mode === 'single' || (sid > 0 && !payload.org_ids)) {
				return `s:${sid}`;
			}
			const resolved = (payload.resolved_store_ids || [])
				.map((id) => Number(id))
				.filter((id) => id > 0)
				.sort((a, b) => a - b)
				.join(',');
			const orgs = (payload.org_ids || [])
				.map((id) => Number(id))
				.filter((id) => id > 0)
				.sort((a, b) => a - b)
				.join(',');
			const excl = (payload.excluded_store_ids || [])
				.map((id) => Number(id))
				.filter((id) => id > 0)
				.sort((a, b) => a - b)
				.join(',');
			return `m:${orgs}|${resolved}|${excl}`;
		},
		markPickerToken(payload) {
			const token = this.pickerResultToken(payload);
			if (token) this.lastPickerToken = token;
			return token;
		},
		syncManagerStoreFilter(storeId) {
			const sid = Number(storeId || 0);
			if (!sid) {
				this.storeFilter = null;
				return;
			}
			this.storeFilter = {
				mode: 'single',
				store_id: sid,
				resolved_store_ids: [sid],
				summary: `门店${sid}`,
			};
		},
		scheduleReload(immediate) {
			if (!this.active) return;
			if (this.reloadTimer) clearTimeout(this.reloadTimer);
			if (immediate) {
				this.reloadAll();
				return;
			}
			this.reloadTimer = setTimeout(() => {
				this.reloadAll();
			}, 280);
		},
		/**
		 * 页面 onShow 唯一消费门店选择结果（storage）。
		 * 店长：只在此路径 switchContext，与 picker 的 $emit 解耦。
		 * 区域：若 @change 已处理同一 token，则跳过二次刷新。
		 */
		onHostShow() {
			const result = consumePickerResult();
			if (!result) return false;
			const token = this.pickerResultToken(result);
			if (token && token === this.lastPickerToken) {
				return true;
			}
			if (this.canRegion) {
				this.markPickerToken(result);
				this.storeFilter = result;
				this.scheduleReload(true);
				return true;
			}
			if (this.showStoreSinglePicker) {
				this.applyManagerStoreSelection(result);
				return true;
			}
			return false;
		},
		onRegionStoreChange(payload) {
			const token = this.pickerResultToken(payload);
			if (token && token === this.lastPickerToken) return;
			this.markPickerToken(payload);
			this.storeFilter = payload || null;
			this.scheduleReload();
		},
		extractStoreId(payload) {
			return Number(
				(payload && payload.store_id)
				|| (payload && payload.resolved_store_ids && payload.resolved_store_ids[0])
				|| 0
			);
		},
		async applyManagerStoreSelection(payload) {
			const sid = this.extractStoreId(payload);
			if (!sid) return false;
			this.markPickerToken(payload);
			if (this.switchingStore || this.managerSwitchInflight === sid) {
				return false;
			}
			if (sid === this.activeStoreId) {
				this.syncManagerStoreFilter(sid);
				return false;
			}
			this.switchingStore = true;
			this.managerSwitchInflight = sid;
			try {
				await this.$store.dispatch('merchant/switchContext', { active_store_id: sid });
				this.syncManagerStoreFilter(sid);
				this.scheduleReload(true);
				return true;
			} catch (e) {
				this.syncManagerStoreFilter(this.activeStoreId);
				const msg = (e && (e.msg || e.message)) || '切换门店失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
				return false;
			} finally {
				this.switchingStore = false;
				this.managerSwitchInflight = 0;
			}
		},
		getStoreFilterParams() {
			if (!this.canRegion) {
				if (this.activeStoreId > 0) {
					return {
						store_id: this.activeStoreId,
						store_ids: String(this.activeStoreId),
					};
				}
				return {};
			}
			const f = this.storeFilter || {};
			return buildStoreFilterApiParams({
				resolved_store_ids: f.resolved_store_ids || [],
				org_ids: f.org_ids || [],
				excluded_store_ids: f.excluded_store_ids || [],
				filterStoreId: f.store_id || 0,
				filterManageRegionId: f.manage_region_id || f.filterManageRegionId || 0,
				filterObjectType: f.object_type || f.filterObjectType || '',
			});
		},
		merchantContextParams() {
			return {
				date_type: (this.dateFilter && this.dateFilter.date_type) || 'today',
				start_date: (this.dateFilter && this.dateFilter.start_date) || '',
				end_date: (this.dateFilter && this.dateFilter.end_date) || '',
				active_store_id: this.activeStoreId,
				active_role: this.activeRole,
			};
		},
		formatMoney(n) {
			if (n === null || n === undefined || n === '') return '-';
			const v = Number(n);
			if (Number.isNaN(v)) return String(n);
			return v.toLocaleString('zh-CN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
		},
		formatRankYeji(n) {
			if (this.showType === 5 || this.showType === 6) {
				if (n === null || n === undefined || n === '') return '-';
				return String(n);
			}
			return this.formatMoney(n);
		},
		formatMetric(m) {
			if (!m) return '-';
			if (m.developing || m.number === null || m.number === undefined) return '开发中';
			return this.formatMoney(m.number);
		},
		rankRowKey(item, index) {
			return `${this.showType}-${item.store_id || 0}-${item.staff_id || 0}-${index}`;
		},
		normalizeAgentHeader(list) {
			const arr = Array.isArray(list) ? list : [];
			return arr.slice(0, 3).map((item) => {
				const title = item.title || '';
				let code = item.metric_code || '';
				if (!code) {
					if (title.indexOf('现金') >= 0) code = 'cash_performance';
					else if (title.indexOf('实际') >= 0 || title.indexOf('实收') >= 0) code = 'actual_performance';
					else if (title.indexOf('消耗') >= 0) code = 'consume_amount';
				}
				return {
					title: title === '消耗业绩' ? '客户消耗金额' : title,
					number: item.number,
					metric_code: code,
					tooltip_api: code ? `metric/dictionary/${code}` : null,
					developing: !!item.developing,
					detail_developing: !!item.detail_developing,
				};
			});
		},
		async reloadAll() {
			if (this.denied) {
				this.headerMetrics = [];
				this.ranking = [];
				this.loadError = '';
				this.loading = false;
				this.booted = true;
				return;
			}
			if (!this.canRegion && this.showType === 6) {
				this.showType = 1;
			}
			const seq = ++this.reqSeq;
			this.loading = true;
			this.loadError = '';
			this.page = 1;
			this.ranking = [];
			this.loadend = false;
			this.rankHint = '';
			try {
				if (this.canRegion) {
					await this.loadRegionHeader(seq);
					if (seq !== this.reqSeq) return;
					await this.loadRanking(true, seq);
				} else {
					await this.loadMerchantHeader(seq);
					if (seq !== this.reqSeq) return;
					if (!this.isStaffSelf) {
						await this.loadRanking(true, seq);
					}
				}
			} catch (e) {
				if (seq !== this.reqSeq) return;
				const msg = (e && (e.msg || e.message)) || '加载失败';
				this.loadError = String(msg).slice(0, 80);
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				if (seq === this.reqSeq) {
					this.loading = false;
					this.booted = true;
				}
			}
		},
		async loadRegionHeader(seq) {
			const res = await getAgentHeader({
				...this.getStoreFilterParams(),
				data: this.dataRange,
			});
			if (seq !== this.reqSeq) return;
			this.metricsMode = 'region';
			this.headerMetrics = this.normalizeAgentHeader((res && res.data) || []);
		},
		async loadMerchantHeader(seq) {
			const res = await merchantDataBusiness(this.merchantContextParams());
			if (seq !== this.reqSeq) return;
			const data = (res && res.data) || {};
			this.metricsMode = data.metrics_mode === 'staff_self' ? 'staff_self' : 'store';
			const primary = Array.isArray(data.primary) ? data.primary : [];
			this.headerMetrics = primary;
			this._merchantStoreRanking = data.store_ranking || {};
		},
		changeType(type) {
			if (this.showType === type) return;
			if (!this.canRegion && type === 6) return;
			this.showType = type;
			if (type === 3) this.sumType = 1;
			if (type === 4) this.sumType = 2;
			this.page = 1;
			this.ranking = [];
			this.loadend = false;
			this.rankHint = '';
			this.loadRanking(true);
		},
		loadMore() {
			if (!this.active || this.isStaffSelf || this.denied) return;
			if (!this.isStaffRank) return;
			if (this.loadend || this.rankLoading || this.loading) return;
			this.loadRanking(false);
		},
		async loadRanking(reset, seq) {
			const myReq = seq || this.reqSeq;
			const myRank = ++this.rankSeq;
			if (reset) {
				this.page = 1;
				this.ranking = [];
				this.loadend = false;
			}
			if (this.loadend && !reset) return;
			this.rankLoading = true;
			try {
				if (this.canRegion) {
					await this.loadRegionRanking(reset, myReq, myRank);
				} else {
					await this.loadStoreRanking(reset, myReq, myRank);
				}
			} catch (e) {
				if (this.isStale(myReq, myRank)) return;
				const msg = (e && (e.msg || e.message)) || '排行加载失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				if (myRank === this.rankSeq) this.rankLoading = false;
			}
		},
		async loadRegionRanking(reset, myReq, myRank) {
			const base = {
				...this.getStoreFilterParams(),
				data: this.dataRange,
			};
			if (this.showType === 3 || this.showType === 4) {
				const res = await agentYejiRanking({
					...base,
					sum_type: this.sumType,
					page: this.page,
					limit: this.limit,
				});
				if (this.isStale(myReq, myRank)) return;
				const list = Array.isArray(res.data) ? res.data : [];
				this.applyStaffPage(list, reset, myReq, myRank);
				return;
			}
			if (this.showType === 5) {
				const res = await agentDianke({
					...base,
					page: this.page,
					limit: this.limit,
				});
				if (this.isStale(myReq, myRank)) return;
				const list = Array.isArray(res.data) ? res.data : [];
				this.applyStaffPage(list, reset, myReq, myRank);
				return;
			}
			if (this.showType === 6) {
				const res = await agentProjectRanking({
					...base,
					page: this.page,
					limit: this.limit,
				});
				if (this.isStale(myReq, myRank)) return;
				const list = Array.isArray(res.data) ? res.data : [];
				this.applyStaffPage(list, reset, myReq, myRank);
				return;
			}
			const res = await getAgentStore({
				...base,
				show_type: this.showType,
				orderby: '',
			});
			if (this.isStale(myReq, myRank)) return;
			const data = (res && res.data) || {};
			this.ranking = Array.isArray(data.ranking) ? data.ranking : [];
			this.loadend = true;
		},
		async loadStoreRanking(reset, myReq, myRank) {
			if (this.showType === 1 || this.showType === 2 || this.showType === 7) {
				if (this.isStale(myReq, myRank)) return;
				const key = this.showType === 1 ? 'cash' : this.showType === 7 ? 'actual' : 'consume';
				const rank = this._merchantStoreRanking || {};
				const list = Array.isArray(rank[key]) ? rank[key] : [];
				this.ranking = list.map((item) => ({
					name: item.name,
					store_id: item.store_id,
					number: item.number,
				}));
				this.loadend = true;
				if (!rank.show) {
					this.rankHint = '当前范围暂无门店排行';
				}
				return;
			}
			if (this.showType === 6) {
				if (this.isStale(myReq, myRank)) return;
				this.ranking = [];
				this.loadend = true;
				this.rankHint = '';
				this.showType = 1;
				return;
			}
			const storeId = this.activeStoreId > 0 ? String(this.activeStoreId) : '';
			if (!storeId) {
				if (this.isStale(myReq, myRank)) return;
				this.ranking = [];
				this.loadend = true;
				this.rankHint = '请先在「我的」切换当前门店';
				return;
			}
			const base = {
				store_id: storeId,
				data: this.dataRange,
				page: this.page,
				limit: this.limit,
			};
			if (this.showType === 3 || this.showType === 4) {
				const res = await yejiRanking({
					...base,
					sum_type: this.sumType,
				});
				if (this.isStale(myReq, myRank)) return;
				const list = Array.isArray(res.data) ? res.data : (res.data && res.data.list) || [];
				this.applyStaffPage(Array.isArray(list) ? list : [], reset, myReq, myRank);
				return;
			}
			if (this.showType === 5) {
				const res = await dianke(base);
				if (this.isStale(myReq, myRank)) return;
				const list = Array.isArray(res.data) ? res.data : (res.data && res.data.list) || [];
				this.applyStaffPage(Array.isArray(list) ? list : [], reset, myReq, myRank);
			}
		},
		applyStaffPage(list, reset, myReq, myRank) {
			if (this.isStale(myReq, myRank)) return;
			const rows = (list || []).map((item) => ({
				...item,
				store_name: item.store_name || item.name || '',
			}));
			const loadend = rows.length < this.limit;
			this.ranking = reset ? rows : this.ranking.concat(rows);
			this.loadend = loadend;
			if (!loadend) this.page += 1;
			else if (reset && rows.length) this.page = 2;
		},
		onMetricTap(m) {
			if (!m || m.developing || m.number === null || m.number === undefined) {
				uni.showToast({ title: '该功能正在开发中，敬请期待', icon: 'none' });
				return;
			}
			if (m.detail_developing) {
				uni.showToast({ title: '指标明细开发中', icon: 'none' });
				return;
			}
			const code = m.metric_code || '';
			const path = METRIC_DETAIL[code];
			if (!path) {
				uni.showToast({ title: '指标明细开发中', icon: 'none' });
				return;
			}
			const q = [
				`start_date=${(this.dateFilter && this.dateFilter.start_date) || ''}`,
				`end_date=${(this.dateFilter && this.dateFilter.end_date) || ''}`,
				`date_type=${(this.dateFilter && this.dateFilter.date_type) || 'custom'}`,
			].join('&');
			uni.navigateTo({ url: `${path}?${q}` });
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
		toStore(id) {
			const sid = Number(id);
			if (!sid) return;
			uni.navigateTo({
				url: `/pages/admin/yeji/store?store_id=${sid}&date_tap=${this.dateTap}&dataRange=${this.dataRange}`,
			});
		},
		toStaff(id) {
			const sid = Number(id);
			if (!sid) return;
			uni.navigateTo({
				url: `/pages/admin/yeji/staff?staff_id=${sid}&date_tap=${this.dateTap}&dataRange=${this.dataRange}`,
			});
		},
	},
};
</script>

<style scoped>
.board {
	padding-bottom: 8rpx;
}
.picker-card {
	padding: 8rpx 8rpx 4rpx;
}
.scope-tip {
	margin: 4rpx 20rpx 12rpx;
	font-size: 22rpx;
	color: #999;
	line-height: 1.4;
}
.retry {
	padding: 8rpx 0 24rpx;
	text-align: center;
	font-size: 26rpx;
	color: #e93323;
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
.rank-tabs-scroll {
	width: 100%;
	margin: 4rpx 0 16rpx;
	white-space: nowrap;
}
.rank-tabs {
	display: inline-flex;
	gap: 12rpx;
	padding-bottom: 4rpx;
}
.rank-tab {
	display: inline-block;
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
.rank-head,
.rank-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
}
.rank-head {
	font-size: 22rpx;
	color: #999;
	padding: 8rpx 0 12rpx;
}
.rank-row {
	padding: 20rpx 0;
	border-bottom: 1rpx solid #f3f3f3;
	font-size: 26rpx;
	color: #222;
}
.rank-row:last-child {
	border-bottom: none;
}
.rank-col--store {
	flex: 1.2;
	min-width: 0;
	padding-right: 8rpx;
}
.rank-col--staff {
	flex: 1;
	min-width: 0;
	padding-right: 8rpx;
	text-align: center;
}
.rank-col--num {
	flex: 0 0 160rpx;
	text-align: right;
	font-weight: 600;
}
.link {
	color: #2a7efb;
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
