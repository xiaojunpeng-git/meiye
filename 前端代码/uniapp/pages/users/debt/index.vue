<template>
	<view class="debt-page">
		<scroll-view scroll-y class="debt-list" @scrolltolower="loadMore">
			<view v-for="item in list" :key="item.debt_id" class="debt-card">
				<view class="card-head">
					<text class="store-name">{{ item.store_name || '所属门店' }}</text>
					<text class="pending">待还 ¥{{ formatMoney(item.pending_debt) }}</text>
				</view>
				<view class="order-row">订单号：{{ item.order_sn || item.debt_no || '-' }}</view>
				<view v-if="item.product_names && item.product_names.length" class="order-row product-row">
					项目：{{ item.product_names.join('、') }}
				</view>
				<view class="order-row">登记时间：{{ formatTime(item.add_time_label) }}</view>
				<view v-if="item.remark" class="order-row">备注：{{ item.remark }}</view>
				<view class="card-foot">
					<text v-if="item.can_online_repay" class="repay-btn" @click="goRepay(item)">去补交</text>
					<text v-else class="offline-tip">请联系门店补交</text>
				</view>
			</view>
			<view v-if="!loading && !list.length" class="empty">暂无待还欠款</view>
			<view v-if="loading" class="loading">加载中...</view>
			<view v-if="loadend && list.length" class="loadend">没有更多了</view>
		</scroll-view>
	</view>
</template>

<script>
import { debtListApi } from '@/api/debt';

export default {
	data() {
		return {
			list: [],
			page: 1,
			limit: 20,
			loading: false,
			loadend: false,
		};
	},
	onLoad() {
		this.fetchList();
	},
	onPullDownRefresh() {
		this.page = 1;
		this.loadend = false;
		this.list = [];
		this.fetchList(true);
	},
	methods: {
		formatMoney(value) {
			return Number(value || 0).toFixed(2);
		},
		formatTime(value) {
			return String(value || '').replace(/:00$/, '');
		},
		fetchList(fromRefresh = false) {
			if (this.loading || this.loadend) {
				if (fromRefresh) uni.stopPullDownRefresh();
				return;
			}
			this.loading = true;
			debtListApi({ page: this.page, limit: this.limit }).then(res => {
				const rows = Array.isArray(res.data && res.data.list) ? res.data.list : [];
				this.list = this.list.concat(rows);
				this.loadend = rows.length < this.limit;
			}).catch(err => {
				this.loadend = true;
				uni.showToast({
					title: String((err && (err.msg || err.message)) || '欠款加载失败').slice(0, 40),
					icon: 'none',
				});
			}).finally(() => {
				this.loading = false;
				if (fromRefresh) uni.stopPullDownRefresh();
			});
		},
		loadMore() {
			if (this.loading || this.loadend) return;
			this.page += 1;
			this.fetchList();
		},
		goRepay(item) {
			const orderId = item && item.order_sn;
			if (!orderId) {
				uni.showToast({ title: '缺少欠款订单号，请联系门店', icon: 'none' });
				return;
			}
			uni.navigateTo({
				url: '/pages/goods/cashier/index?order_id=' + encodeURIComponent(orderId) + '&from_type=debt',
			});
		},
	},
};
</script>

<style scoped>
.debt-page { min-height: 100vh; background: #f5f6f8; }
.debt-list { height: 100vh; box-sizing: border-box; padding: 20rpx 24rpx 56rpx; }
.debt-card { margin-bottom: 20rpx; padding: 24rpx; background: #fff; border-radius: 16rpx; }
.card-head { display: flex; align-items: center; justify-content: space-between; }
.store-name { max-width: 430rpx; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #222; font-size: 30rpx; font-weight: 600; }
.pending { color: #ed4014; font-size: 30rpx; font-weight: 600; }
.order-row { margin-top: 14rpx; color: #777; font-size: 25rpx; line-height: 36rpx; word-break: break-all; }
.product-row { color: #555; }
.card-foot { min-height: 54rpx; margin-top: 20rpx; display: flex; justify-content: flex-end; align-items: center; border-top: 1rpx solid #f2f2f2; padding-top: 18rpx; }
.repay-btn { min-width: 132rpx; height: 54rpx; border-radius: 28rpx; background: var(--view-theme); color: #fff; font-size: 25rpx; line-height: 54rpx; text-align: center; }
.offline-tip { color: #999; font-size: 24rpx; }
.empty, .loading, .loadend { padding: 90rpx 0 40rpx; color: #999; font-size: 26rpx; text-align: center; }
.loadend { padding-top: 20rpx; }
</style>
