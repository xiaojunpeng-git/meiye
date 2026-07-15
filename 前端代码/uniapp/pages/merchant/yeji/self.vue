<template>
	<view class="yeji-page">
		<merchant-date-filter v-model="dateFilter" @change="onDateChange" />
		<view class="merchant-page__body">
			<view v-if="loading && !staff.staff_name" class="state">加载中…</view>
			<template v-else>
				<view class="card">
					<view class="staff-line">姓名：{{ staff.staff_name || '—' }}</view>
					<view class="staff-line">电话：{{ staff.phone || '—' }}</view>
					<view class="metrics__row metrics__row--wrap">
						<view
							class="metrics__item metrics__item--third"
							v-for="(m, i) in metrics"
							:key="m.metric_code || i"
						>
							<view class="metrics__label">
								{{ m.title }}
								<text
									v-if="m.tooltip_api"
									class="tip"
									@click.stop="onTooltip(m)"
								>ⓘ</text>
							</view>
							<view class="metrics__value">{{ formatMetric(m) }}</view>
						</view>
					</view>
					<view v-if="note" class="hint">{{ note }}</view>
				</view>

				<view class="card">
					<view class="tabs">
						<view
							class="tab"
							:class="{ active: sumType === 1 }"
							@click="switchSumType(1)"
						>销售业绩</view>
						<view
							class="tab"
							:class="{ active: sumType === 2 }"
							@click="switchSumType(2)"
						>劳动业绩</view>
					</view>
					<view
						v-for="(item, index) in ranking"
						:key="index"
						class="row"
					>
						<view class="row__left">
							<view class="row__id" @click="copyOrder(item.wx_order_id)">{{ item.wx_order_id || '—' }}</view>
							<view class="row__muted" v-if="item.type == 3 && item.user">
								{{ item.user.real_name }}（{{ item.service_object || '本人' }}）
							</view>
							<view class="row__muted" v-else-if="item.user">{{ item.user.real_name }}</view>
							<view class="row__name">{{ (item.cart && item.cart.store_name) || '—' }}</view>
							<view v-if="item.type == 3" class="row__muted">业绩：{{ item.price }}</view>
							<view v-if="item.type == 3" class="row__muted">参与：{{ item.labor_participant_names || '—' }}</view>
						</view>
						<view class="row__right">
							<view class="row__muted">{{ item.created_time }}</view>
							<view class="row__muted">{{ (item.user && item.user.phone) || '—' }}</view>
							<view class="row__val" v-if="item.type == 3">{{ item.dian }}</view>
							<view class="row__val" v-else>{{ item.yeji }}</view>
							<view v-if="item.type == 3" class="row__muted">项目数：{{ item.project_num }}</view>
							<view v-if="item.type == 3" class="row__muted">提成：{{ item.commission }}</view>
						</view>
					</view>
					<view v-if="!ranking.length && !listLoading" class="empty">暂无员工业绩</view>
					<view v-if="listLoading" class="empty">加载中…</view>
					<view v-else-if="!loadend && ranking.length" class="more" @click="loadMore">加载更多</view>
				</view>
			</template>
		</view>
	</view>
</template>

<script>
import merchantGuard from '@/mixins/merchantGuard.js';
import merchantDateFilter from '@/components/merchantDateFilter/index.vue';
import { merchantYejiSelf, merchantYejiSelfDetail } from '@/api/merchant.js';
import request from '@/utils/request.js';

export default {
	mixins: [merchantGuard],
	components: { merchantDateFilter },
	data() {
		const s = this.todayStr();
		return {
			dateFilter: {
				date_type: 'today',
				start_date: s,
				end_date: s,
				display: '今天',
			},
			staff: {},
			metrics: [],
			note: '',
			sumType: 1,
			ranking: [],
			page: 1,
			limit: 20,
			loadend: false,
			loading: false,
			listLoading: false,
			loadSeq: 0,
		};
	},
	async onShow() {
		const ok = await this.ensureMerchantAccess({
			permission: 'merchant.data.self',
			fallbackMerchantHome: true,
		});
		if (!ok) return;
		this.reloadAll();
	},
	methods: {
		todayStr() {
			const d = new Date();
			const p = (n) => (n < 10 ? '0' + n : '' + n);
			return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
		},
		contextParams() {
			return {
				active_store_id: this.$store.state.merchant.activeStoreId || 0,
				active_role: this.$store.state.merchant.activeRole || '',
				date_type: this.dateFilter.date_type || 'today',
				start_date: this.dateFilter.start_date,
				end_date: this.dateFilter.end_date,
			};
		},
		formatMoney(n) {
			if (n === null || n === undefined || n === '') return '-';
			const v = Number(n);
			if (Number.isNaN(v)) return String(n);
			return v.toLocaleString('zh-CN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
		},
		formatMetric(m) {
			if (!m || m.developing || m.number === null || m.number === undefined) return '-';
			return this.formatMoney(m.number);
		},
		onDateChange(payload) {
			this.dateFilter = payload || this.dateFilter;
			this.reloadAll();
		},
		switchSumType(t) {
			if (this.sumType === t) return;
			this.sumType = t;
			this.reloadList();
		},
		reloadAll() {
			this.loadOverview();
			this.reloadList();
		},
		reloadList() {
			this.page = 1;
			this.loadend = false;
			this.ranking = [];
			this.loadList();
		},
		loadMore() {
			if (this.loadend || this.listLoading) return;
			this.loadList();
		},
		async loadOverview() {
			const seq = ++this.loadSeq;
			this.loading = true;
			try {
				const res = await merchantYejiSelf(this.contextParams());
				if (seq !== this.loadSeq) return;
				const data = (res && res.data) || {};
				this.staff = data.staff || {};
				this.metrics = Array.isArray(data.metrics) ? data.metrics : [];
				this.note = data.note || '';
			} catch (e) {
				if (seq !== this.loadSeq) return;
				this.staff = {};
				this.metrics = [];
				const msg = (e && (e.msg || e.message)) || '加载失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				if (seq === this.loadSeq) this.loading = false;
			}
		},
		async loadList() {
			this.listLoading = true;
			try {
				const res = await merchantYejiSelfDetail({
					...this.contextParams(),
					sum_type: this.sumType,
					page: this.page,
					limit: this.limit,
				});
				const data = (res && res.data) || {};
				const rows = Array.isArray(data.list) ? data.list : [];
				this.loadend = rows.length < this.limit;
				this.page += 1;
				this.ranking = this.ranking.concat(rows);
			} catch (e) {
				const msg = (e && (e.msg || e.message)) || '明细加载失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				this.listLoading = false;
			}
		},
		copyOrder(id) {
			if (!id) return;
			uni.setClipboardData({
				data: String(id),
				success: () => uni.showToast({ title: '已复制', icon: 'none' }),
			});
		},
		async onTooltip(m) {
			if (!m || !m.tooltip_api) return;
			try {
				const res = await request.get(m.tooltip_api);
				const d = (res && res.data) || {};
				const lines = [
					d.name || m.title || '',
					d.formula ? `公式：${d.formula}` : '',
					d.include ? `包含：${d.include}` : '',
					d.exclude ? `排除：${d.exclude}` : '',
					d.source ? `来源：${d.source}` : '',
					d.time_field ? `时间：${d.time_field}` : '',
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
	},
};
</script>

<style scoped>
.yeji-page {
	min-height: 100vh;
	background: #f5f6f8;
	padding-bottom: 40rpx;
}
.merchant-page__body {
	padding: 20rpx 24rpx;
}
.state,
.empty,
.more,
.hint {
	font-size: 24rpx;
	color: #999;
	text-align: center;
	padding: 24rpx 0;
}
.hint {
	text-align: left;
	padding-top: 16rpx;
}
.more {
	color: #2a7efb;
}
.card {
	background: #fff;
	border-radius: 16rpx;
	padding: 28rpx 24rpx;
	margin-bottom: 20rpx;
}
.staff-line {
	font-size: 28rpx;
	color: #333;
	margin-bottom: 12rpx;
}
.metrics__row {
	display: flex;
	margin-top: 16rpx;
}
.metrics__row--wrap {
	flex-wrap: wrap;
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
.tip {
	margin-left: 6rpx;
	color: #e93323;
	font-size: 22rpx;
}
.tabs {
	display: flex;
	margin-bottom: 12rpx;
}
.tab {
	flex: 1;
	text-align: center;
	padding: 20rpx 0;
	font-size: 28rpx;
	color: #666;
	border-bottom: 4rpx solid transparent;
}
.tab.active {
	color: #2a7efb;
	font-weight: 600;
	border-bottom-color: #2a7efb;
}
.row {
	display: flex;
	justify-content: space-between;
	padding: 24rpx 0;
	border-bottom: 1rpx solid #f3f3f3;
}
.row__left,
.row__right {
	flex: 1;
}
.row__right {
	text-align: right;
}
.row__id {
	font-size: 22rpx;
	color: #2a7efb;
	margin-bottom: 8rpx;
}
.row__name {
	font-size: 28rpx;
	color: #222;
	margin: 8rpx 0;
}
.row__muted {
	font-size: 22rpx;
	color: #999;
	margin-bottom: 6rpx;
}
.row__val {
	font-size: 28rpx;
	color: #222;
	margin: 8rpx 0;
}
</style>
