<template>
	<view class="metric-page">
		<merchant-date-filter v-model="dateFilter" @change="onDateChange" />
		<view class="merchant-page__body">
			<view class="card summary">
				<view class="summary__title">现金业绩合计</view>
				<view class="summary__val">{{ formatMoney(headerNumber) }}</view>
				<view class="summary__sub">
					订单 {{ formatMoney(orderSum) }}
					<text v-if="Number(oldSum) > 0"> · 旧店录入 {{ formatMoney(oldSum) }}</text>
				</view>
				<view v-if="note" class="hint">{{ note }}</view>
			</view>

			<scroll-view scroll-y class="list" @scrolltolower="loadMore">
				<view
					v-for="(item, index) in list"
					:key="item.row_type + '-' + item.id + '-' + index"
					class="row"
				>
					<view class="row__main">
						<view class="row__id">{{ item.order_id || '—' }}</view>
						<view class="row__meta">{{ item.store_name || '' }} · {{ item.add_time_text || '' }}</view>
						<view class="row__meta" v-if="item.user_name || item.user_phone">
							{{ item.user_name || '客户' }} {{ item.user_phone || '' }}
						</view>
						<view class="row__tag" v-if="item.row_type === 'old_shop'">旧店录入</view>
					</view>
					<view class="row__amount">{{ formatMoney(item.amount) }}</view>
				</view>
				<view v-if="!list.length && !loading" class="empty">暂无明细</view>
				<view v-if="loading" class="empty">加载中…</view>
				<view v-else-if="!loadend && list.length" class="more" @click="loadMore">加载更多</view>
			</scroll-view>
		</view>
	</view>
</template>

<script>
import merchantGuard from '@/mixins/merchantGuard.js';
import merchantDateFilter from '@/components/merchantDateFilter/index.vue';
import { merchantMetricCashDetail } from '@/api/merchant.js';

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
			headerNumber: '0.00',
			orderSum: '0.00',
			oldSum: '0.00',
			note: '',
			list: [],
			page: 1,
			limit: 20,
			loadend: false,
			loading: false,
			loadSeq: 0,
		};
	},
	async onShow() {
		const ok = await this.ensureMerchantAccess({ fallbackMerchantHome: true });
		if (!ok) return;
		if (
			!this.hasMerchantPermission('merchant.data.store')
			&& !this.hasMerchantPermission('merchant.data.region')
		) {
			uni.showToast({ title: '暂无该功能权限', icon: 'none' });
			uni.redirectTo({ url: '/pages/merchant/home/index' });
			return;
		}
		this.reload();
	},
	onLoad(opt) {
		if (!opt) return;
		const start = String(opt.start_date || '').trim();
		const end = String(opt.end_date || '').trim();
		if (start && end) {
			this.dateFilter = {
				date_type: String(opt.date_type || 'custom'),
				start_date: start,
				end_date: end,
				display: opt.display
					? decodeURIComponent(String(opt.display))
					: `${start} ~ ${end}`,
			};
		}
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
				date_type: this.dateFilter.date_type || 'custom',
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
		onDateChange(payload) {
			this.dateFilter = payload || this.dateFilter;
			this.reload();
		},
		reload() {
			this.page = 1;
			this.loadend = false;
			this.list = [];
			this.fetchList(true);
		},
		loadMore() {
			if (this.loadend || this.loading) return;
			this.fetchList(false);
		},
		async fetchList(resetHeader) {
			const seq = ++this.loadSeq;
			this.loading = true;
			try {
				const res = await merchantMetricCashDetail({
					...this.contextParams(),
					page: this.page,
					limit: this.limit,
				});
				if (seq !== this.loadSeq) return;
				const data = (res && res.data) || {};
				if (resetHeader) {
					this.headerNumber = data.header_number != null ? String(data.header_number) : '0.00';
					this.orderSum = data.order_sum != null ? String(data.order_sum) : '0.00';
					this.oldSum = data.old_sum != null ? String(data.old_sum) : '0.00';
					this.note = data.note || '';
				}
				const rows = Array.isArray(data.list) ? data.list : [];
				// 第一页可能附带旧店行，分页 count 仅计订单
				const orderRows = rows.filter((r) => r.row_type !== 'old_shop');
				this.loadend = orderRows.length < this.limit;
				this.page += 1;
				this.list = this.list.concat(rows);
			} catch (e) {
				if (seq !== this.loadSeq) return;
				this.loadend = true;
				const msg = (e && (e.msg || e.message)) || '加载失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				if (seq === this.loadSeq) this.loading = false;
			}
		},
	},
};
</script>

<style scoped>
.metric-page {
	min-height: 100vh;
	background: #f5f6f8;
	display: flex;
	flex-direction: column;
}
.merchant-page__body {
	flex: 1;
	padding: 24rpx;
	box-sizing: border-box;
}
.card {
	background: #fff;
	border-radius: 16rpx;
	padding: 28rpx 24rpx;
	margin-bottom: 20rpx;
}
.summary__title {
	font-size: 26rpx;
	color: #888;
}
.summary__val {
	margin-top: 8rpx;
	font-size: 44rpx;
	font-weight: 600;
	color: #222;
}
.summary__sub {
	margin-top: 8rpx;
	font-size: 24rpx;
	color: #666;
}
.hint {
	margin-top: 12rpx;
	font-size: 22rpx;
	color: #999;
	line-height: 1.5;
}
.list {
	max-height: calc(100vh - 360rpx);
}
.row {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 12rpx;
}
.row__main {
	flex: 1;
	min-width: 0;
	padding-right: 16rpx;
}
.row__id {
	font-size: 28rpx;
	color: #222;
	font-weight: 500;
}
.row__meta {
	margin-top: 6rpx;
	font-size: 22rpx;
	color: #999;
}
.row__tag {
	display: inline-block;
	margin-top: 8rpx;
	font-size: 20rpx;
	color: #e93323;
	background: #fff1f0;
	padding: 2rpx 10rpx;
	border-radius: 6rpx;
}
.row__amount {
	font-size: 30rpx;
	font-weight: 600;
	color: #222;
}
.empty,
.more {
	padding: 32rpx 0;
	text-align: center;
	color: #aaa;
	font-size: 26rpx;
}
</style>
