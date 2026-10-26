<template>
	<view class="debt-page">
		<view class="search-bar">
			<view class="search-box">
				<input
					class="search-input"
					v-model="keyword"
					confirm-type="search"
					placeholder="客户名/手机号/订单号"
					@confirm="reload"
				/>
			</view>
			<view class="search-btn" @click="reload">查询</view>
		</view>
		<scroll-view scroll-y class="list" @scrolltolower="loadMore">
			<view v-for="(item, i) in list" :key="item.debt_id || i" class="card">
				<view class="card__head">
					<view class="card__name">{{ (item.user && item.user.nickname) || '客户' }}</view>
					<view class="card__amount">¥{{ formatMoney(item.pending_debt) }}</view>
				</view>
				<view class="card__row" @click.stop="callPhone(item.user && item.user.phone)">
					手机 {{ maskPhone(item.user && item.user.phone) }}
				</view>
				<view class="card__row">订单 {{ item.order_sn || item.order_id || '-' }}</view>
				<view class="card__row">门店 {{ item.store_name || '-' }} · {{ item.add_time_label || '' }}</view>
				<view class="card__row" v-if="item.product_names && item.product_names.length">
					项目 {{ (item.product_names || []).slice(0, 3).join('、') }}
				</view>
				<view class="card__actions">
					<view
						class="btn"
						:class="{ disabled: !canViewCustomer }"
						@click="goUser(item)"
					>{{ canViewCustomer ? '客户' : '客户(无权限)' }}</view>
					<view class="btn btn--ghost" @click="onRepayDeveloping">补交（开发中）</view>
				</view>
			</view>
			<view v-if="!list.length && !loading" class="empty">暂无待还欠款</view>
			<view v-if="loading" class="empty">加载中...</view>
			<view class="list-pad"></view>
		</scroll-view>
	</view>
</template>

<script>
import merchantGuard from '@/mixins/merchantGuard.js';
import { merchantDebtList } from '@/api/merchant.js';

export default {
	mixins: [merchantGuard],
	data() {
		return {
			keyword: '',
			list: [],
			page: 1,
			limit: 20,
			loadend: false,
			loading: false,
		};
	},
	computed: {
		canViewCustomer() {
			return this.hasMerchantPermission('merchant.customer.view');
		},
	},
	async onShow() {
		const ok = await this.ensureMerchantAccess({
			permission: 'merchant.debt.view',
			fallbackMerchantHome: true,
		});
		if (!ok) return;
		this.reload();
	},
	methods: {
		contextParams() {
			return {
				active_store_id: this.$store.state.merchant.activeStoreId || 0,
				active_role: this.$store.state.merchant.activeRole || '',
			};
		},
		formatMoney(n) {
			const v = Number(n || 0);
			if (Number.isNaN(v)) return '0.00';
			return v.toFixed(2);
		},
		maskPhone(phone) {
			const p = String(phone || '');
			if (p.length < 7) return p || '-';
			return p.replace(/(\d{3})\d{4}(\d+)/, '$1****$2');
		},
		callPhone(phone) {
			if (!phone) return;
			uni.makePhoneCall({ phoneNumber: String(phone) });
		},
		reload() {
			this.page = 1;
			this.loadend = false;
			this.list = [];
			this.fetchList();
		},
		loadMore() {
			if (this.loadend || this.loading) return;
			this.page += 1;
			this.fetchList();
		},
		async fetchList() {
			if (this.loading || this.loadend) return;
			this.loading = true;
			try {
				const res = await merchantDebtList({
					...this.contextParams(),
					page: this.page,
					limit: this.limit,
					keyword: this.keyword || '',
				});
				const data = (res && res.data) || {};
				const rows = Array.isArray(data.list) ? data.list : [];
				this.list = this.list.concat(rows);
				this.loadend = rows.length < this.limit;
			} catch (e) {
				this.loadend = true;
				const msg = (e && (e.msg || e.message)) || '加载失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				this.loading = false;
			}
		},
		goUser(item) {
			if (!this.canViewCustomer) {
				uni.showToast({ title: '暂无客户查看权限', icon: 'none' });
				return;
			}
			const uid = item && item.uid;
			if (!uid) return;
			uni.navigateTo({ url: `/pages/merchant/customer/detail?uid=${uid}` });
		},
		onRepayDeveloping() {
			uni.showToast({
				title: '商家替客补交链路待确认，功能开发中',
				icon: 'none',
			});
		},
	},
};
</script>

<style scoped>
.debt-page {
	min-height: 100vh;
	background: #f5f6f8;
	display: flex;
	flex-direction: column;
}
.search-bar {
	display: flex;
	align-items: center;
	padding: 20rpx 24rpx;
	background: #fff;
}
.search-box {
	flex: 1;
	height: 68rpx;
	border-radius: 34rpx;
	background: #f5f5f5;
	display: flex;
	align-items: center;
	padding: 0 24rpx;
}
.search-input {
	flex: 1;
	font-size: 26rpx;
}
.search-btn {
	margin-left: 16rpx;
	padding: 0 20rpx;
	height: 68rpx;
	line-height: 68rpx;
	font-size: 28rpx;
	color: #e93323;
}
.list {
	flex: 1;
	height: 0;
	padding: 16rpx 24rpx;
	box-sizing: border-box;
}
.card {
	background: #fff;
	border-radius: 16rpx;
	padding: 24rpx;
	margin-bottom: 16rpx;
}
.card__head {
	display: flex;
	justify-content: space-between;
	align-items: center;
}
.card__name {
	font-size: 30rpx;
	font-weight: 600;
	color: #222;
}
.card__amount {
	font-size: 32rpx;
	font-weight: 600;
	color: #e93323;
}
.card__row {
	margin-top: 10rpx;
	font-size: 24rpx;
	color: #666;
}
.card__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 20rpx;
}
.btn {
	padding: 10rpx 28rpx;
	margin-left: 16rpx;
	border-radius: 28rpx;
	font-size: 26rpx;
	background: #f5f5f5;
	color: #666;
}
.btn.disabled {
	opacity: 0.5;
	color: #bbb;
}
.btn--ghost {
	background: #f5f5f5;
	color: #999;
}
.btn--primary {
	background: #e93323;
	color: #fff;
}
.empty {
	padding: 80rpx 0;
	text-align: center;
	color: #aaa;
	font-size: 26rpx;
}
.list-pad {
	height: 40rpx;
}
</style>
