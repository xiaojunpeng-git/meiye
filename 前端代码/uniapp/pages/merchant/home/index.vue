<template>
	<view class="merchant-page">
		<view class="merchant-page__body">
			
			<view class="identity" @click="openContextSheet">
				<view class="identity__main">
					<view class="identity__name">
						{{ storeTitle }}
						<text v-if="canSwitchContext" class="iconfont icon-ic_downarrow identity__arrow"></text>
					</view>
				</view>
				<view class="identity__msg" @click.stop="goUrl('/pages/users/message_center/index')">
					<text class="iconfont icon-ic_message"></text>
				</view>
			</view>

			
			<view v-if="todoList.length || todoNote" class="card">
				<view class="card__head">
					<text class="card__title">待办提醒</text>
					<text class="card__link" @click="goAllTodos">全部 ›</text>
				</view>
				<view v-if="todoPreview.length" class="todo-row">
					<view
						v-for="(t, i) in todoPreview"
						:key="i"
						class="todo-item"
						@click="goUrl(t.url)"
					>
						<text class="todo-item__num">{{ t.developing ? '-' : (t.count > 99 ? '99+' : t.count) }}</text>
						<text class="todo-item__name">{{ t.name }}</text>
					</view>
				</view>
				<view v-if="todoNote" class="metrics__extra">{{ todoNote }}</view>
			</view>

			
			<view class="card">
				<view class="card__head">
					<text class="card__title">高频操作</text>
				</view>
				<view class="shortcut-grid">
					<view
						v-for="(s, i) in shortcuts"
						:key="i"
						class="shortcut"
						@click="onShortcut(s)"
					>
						<view class="shortcut__icon">
							<text class="iconfont" :class="s.icon"></text>
							<view v-if="s.badge" class="shortcut__badge">{{ s.badge > 99 ? '99+' : s.badge }}</view>
						</view>
						<text class="shortcut__name">{{ s.name }}</text>
					</view>
				</view>
			</view>

			
			<view v-if="showReservation" class="card">
				<view class="card__head">
					<text class="card__title">今日预约</text>
					<text class="card__link" @click="goUrl('/pages/admin/reservation_list/index?merchant=1')">全部 ›</text>
				</view>
				<view v-if="reservationsDeveloping" class="empty-tip">预约列表口径开发中</view>
				<template v-else-if="reservations.length">
					<view
						v-for="(r, i) in reservations"
						:key="r.id || i"
						class="rsv-item"
						@click="goReservationDetail(r)"
					>
						<view class="rsv-item__time">{{ r.timeText }}</view>
						<view class="rsv-item__body">
							<view class="rsv-item__name">{{ r.userName }}</view>
							<view class="rsv-item__desc">{{ r.serviceName }}</view>
						</view>
						<view class="rsv-item__status">{{ r.statusText }}</view>
					</view>
				</template>
				<view v-else class="empty-tip">今日暂无预约</view>
			</view>

			
			<view v-if="isDeliveryOnly" class="card">
				<view class="card__title">配送工作</view>
				<view class="empty-tip">请从工作台进入配送任务</view>
				<button class="btn-primary" @click="goUrl('/pages/admin/distribution/index')">配送任务</button>
			</view>
		</view>
		<merchant-switch side="merchant" />
		<merchant-tab-bar current="workbench" />
	</view>
</template>

<script>
import merchantGuard from '@/mixins/merchantGuard.js';
import merchantSwitch from '@/components/merchantSwitch/index.vue';
import merchantTabBar from '@/components/merchantTabBar/index.vue';
import { merchantHome } from '@/api/merchant.js';
import request from '@/utils/request.js';

export default {
	mixins: [merchantGuard],
	components: { merchantSwitch, merchantTabBar },
	data() {
		return {
			hideAmount: false,
			metricList: [],
			metricsExtra: '',
			metricsMode: 'store',
			todoCounts: {
				reservation: null,
				reservation_developing: true,
				refunding: 0,
				unshipped: 0,
				policeforce: 0,
				debt: null,
				debt_developing: true,
			},
			todoNote: '',
			reservations: [],
			reservationsDeveloping: true,
			storeNameFromApi: '',
			loading: false,
		};
	},
	computed: {
		activeRole() {
			return this.$store.state.merchant.activeRole || '';
		},
		stores() {
			return this.$store.state.merchant.stores || [];
		},
		activeStoreId() {
			return this.$store.state.merchant.activeStoreId || 0;
		},
		storeTitle() {
			const hit = this.stores.find((s) => Number(s.id) === Number(this.activeStoreId));
			return (hit && hit.name) || this.storeNameFromApi || '暂无可用门店';
		},
		canSwitchContext() {
			return !!this.$store.state.merchant.storeSwitchAllowed;
		},
		perms() {
			return this.$store.state.merchant.permissions || [];
		},
		canStoreData() {
			return this.perms.some((p) =>
				['merchant.data.store', 'merchant.data.region', 'merchant.warehouse.view'].includes(p)
			);
		},
		canSelfData() {
			return this.perms.includes('merchant.data.self');
		},
		showMetrics() {
			if (this.activeRole === 'delivery') return false;
			if (!this.hasMerchantPermission('merchant.home.view')) return false;
			if (this.activeRole === 'platform_service' && !this.canStoreData && !this.canSelfData) return false;
			return this.canStoreData || this.canSelfData || this.metricList.length > 0;
		},
		metricsTitle() {
			if (this.metricsMode === 'staff_self') return '我的今日业绩';
			if (this.canStoreData) return '今日经营';
			return '我的今日业绩';
		},
		metricsLinkText() {
			return this.metricsMode === 'staff_self' ? '个人业绩 ›' : '数仓 ›';
		},
		isDeliveryOnly() {
			return this.activeRole === 'delivery' && !this.canStoreData && !this.canSelfData;
		},
		showReservation() {
			return ['store_manager', 'store_staff', 'region_agent'].includes(this.activeRole);
		},
		todoList() {
			const list = [];
			const t = this.todoCounts;
			
			if (this.showReservation) {
				if (t.reservation_developing) {
					list.push({
						name: '今日预约待办',
						count: 0,
						developing: true,
						url: '/pages/admin/reservation_list/index?merchant=1',
					});
				} else if (Number(t.reservation) > 0) {
					list.push({
						name: '今日预约待办',
						count: Number(t.reservation),
						url: '/pages/admin/reservation_list/index?merchant=1',
					});
				}
			}
			
			const refunding = Number(t.refunding) || 0;
			const unshipped = Number(t.unshipped) || 0;
			const orderPending = refunding + unshipped;
			if (orderPending > 0) {
				list.push({
					name: '订单待处理',
					count: orderPending,
					url: refunding > 0
						? '/pages/admin/refundOrderList/index'
						: '/pages/admin/orderList/index?types=1',
					meta: { refunding, unshipped },
				});
			}
			
			if (Number(t.policeforce) > 0) {
				list.push({
					name: '库存预警',
					count: Number(t.policeforce),
					url: '/pages/admin/goods/index?type=5',
				});
			}
			
			if (this.hasMerchantPermission('merchant.debt.view')) {
				if (t.debt_developing) {
					list.push({
						name: '待还欠款',
						count: 0,
						developing: true,
						url: '/pages/merchant/debt/index',
					});
				} else if (Number(t.debt) > 0) {
					list.push({
						name: '待还欠款',
						count: Number(t.debt),
						url: '/pages/merchant/debt/index',
					});
				}
			}
			return list;
		},
		
		todoPreview() {
			return this.todoList.slice(0, 3);
		},
		shortcuts() {
			const badgeMap = {
				workbench: Number(this.todoCounts.refunding || 0) + Number(this.todoCounts.policeforce || 0),
			};
			const all = [
				{ name: '扫码核销', icon: 'icon-ic_Scan', action: 'developing', need: 'cancel' },
				{ name: '开单收银', icon: 'icon-ic_order', action: 'developing', need: '' },
				{ name: '开卡充值', icon: 'icon-ic_user1', action: 'developing', need: '' },
				{ name: '预约', icon: 'icon-ic_clock', action: 'developing', need: 'rsv' },
				{ name: '添加客户', icon: 'icon-ic_user', action: 'developing', need: 'customer_create' },
				{ name: '订单管理', icon: 'icon-ic_order1', action: 'url', url: '/pages/admin/orderList/index', need: 'order' },
				{ name: '补交欠款', icon: 'icon-ic_money', action: 'url', url: '/pages/merchant/debt/index', need: 'debt' },
				{ name: '全部功能', icon: 'icon-ic_home', action: 'url', url: '/pages/merchant/workbench/index', need: '', badge: badgeMap.workbench },
			];
			return all.filter((s) => this.shortcutVisible(s)).slice(0, 8);
		},
	},
	async onShow() {
		const ok = await this.ensureMerchantAccess({
			permission: 'merchant.enter',
			fallbackMerchantHome: false,
		});
		if (!ok) return;
		this.loadHome();
	},
	methods: {
		contextParams() {
			return {
				active_store_id: this.$store.state.merchant.activeStoreId || 0,
				active_role: this.$store.state.merchant.activeRole || '',
			};
		},
		shortcutVisible(s) {
			if (this.isDeliveryOnly) {
				return ['扫码核销', '工作台'].includes(s.name);
			}
			if (s.need === 'customer') return this.hasMerchantPermission('merchant.customer.view');
			if (s.need === 'customer_create') return this.hasMerchantPermission('merchant.customer.create');
			if (s.need === 'debt') return this.hasMerchantPermission('merchant.debt.view');
			if (s.need === 'rsv') return this.showReservation;
			if (s.need === 'order') return this.canStoreData || this.activeRole === 'platform_service' || this.activeRole === 'store_manager';
			if (s.need === 'cancel') return true;
			return true;
		},
		formatMoney(n) {
			const v = Number(n || 0);
			if (Number.isNaN(v)) return n || '0';
			return v.toLocaleString('zh-CN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
		},
		formatMetricValue(m) {
			if (this.hideAmount) return '****';
			if (!m) return '-';
			if (m.developing || m.number === null || m.number === undefined) return '-';
			return this.formatMoney(m.number);
		},
		developing() {
			uni.showToast({ title: '该功能正在开发中，敬请期待', icon: 'none' });
		},
		goUrl(url) {
			if (!url) return;
			uni.navigateTo({ url });
		},
		goReservationDetail(r) {
			const id = r && Number(r.id);
			if (id > 0) {
				uni.navigateTo({ url: `/pages/admin/reservation_details/index?id=${id}&merchant=1` });
				return;
			}
			uni.navigateTo({ url: '/pages/admin/reservation_list/index?merchant=1' });
		},
		goData() {
			uni.redirectTo({ url: '/pages/merchant/data/index' });
		},
		goMetricsDetail() {
			if (this.metricsMode === 'staff_self') {
				uni.navigateTo({ url: '/pages/merchant/yeji/self' });
				return;
			}
			this.goData();
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
		goWorkbench() {
			uni.navigateTo({ url: '/pages/merchant/workbench/index' });
		},
		
		goAllTodos() {
			const list = this.todoList || [];
			if (!list.length) {
				this.goWorkbench();
				return;
			}
			uni.showActionSheet({
				itemList: list.map((t) => {
					const n = t.developing ? '-' : (t.count > 99 ? '99+' : String(t.count));
					return `${t.name}（${n}）`;
				}),
				success: (res) => {
					const item = list[res.tapIndex];
					if (item && item.url) this.goUrl(item.url);
				},
			});
		},
		openContextSheet() {
			if (!this.canSwitchContext) return;
			uni.redirectTo({ url: '/pages/merchant/profile/index' });
		},
		onShortcut(s) {
			if (!s) return;
			if (s.action === 'developing') return this.developing();
			if (s.action === 'scan') return this.goUrl('/pages/admin/order_cancellation/index?auth=4');
			if (s.action === 'url') return this.goUrl(s.url);
		},
		async loadHome() {
			if (this.loading) return;
			this.loading = true;
			try {
				const res = await merchantHome(this.contextParams());
				const data = (res && res.data) || {};
				this.applyHomePayload(data);
			} catch (e) {
				this.metricList = [];
				this.todoCounts = {
					reservation: null,
					reservation_developing: true,
					refunding: 0,
					unshipped: 0,
					policeforce: 0,
					debt: null,
					debt_developing: true,
				};
				this.reservations = [];
				this.reservationsDeveloping = true;
				const msg = (e && (e.msg || e.message)) || '首页加载失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				this.loading = false;
			}
		},
		applyHomePayload(data) {
			const metrics = Array.isArray(data.metrics) ? data.metrics : [];
			this.metricsMode = data.metrics_mode === 'staff_self' ? 'staff_self' : 'store';
			
			this.metricList = metrics.map((m) => ({
				...m,
				developing: !!(m.developing || data.metrics_developing),
			}));
			const extras = [];
			if (data.store_name) extras.push(data.store_name);
			if (data.metrics_note) extras.push(data.metrics_note);
			if (data.updated_at) extras.push(`更新于 ${data.updated_at}`);
			this.metricsExtra = extras.join(' · ');
			this.storeNameFromApi = data.store_name || '';

			const todos = data.todos || {};
			this.todoCounts = {
				reservation: todos.reservation,
				reservation_developing: !!todos.reservation_developing,
				refunding: Number(todos.refunding || 0),
				unshipped: Number(todos.unshipped || 0),
				policeforce: Number(todos.policeforce || 0),
				debt: todos.debt,
				debt_developing: !!todos.debt_developing,
			};
			this.todoNote = data.todo_note || '';

			this.reservationsDeveloping = !!data.reservations_developing;
			const rows = Array.isArray(data.reservations) ? data.reservations : [];
			this.reservations = rows.slice(0, 5).map((item) => ({
				id: Number(item.id || 0),
				timeText: item.timeText || item.reservation_time || item.time || item.start_time || '--:--',
				userName: item.userName || item.nickname || item.real_name || item.user_name || '客户',
				serviceName: item.serviceName || item.product_name || item.service_name || item.title || '服务项目',
				statusText: item.statusText || item.status_name || item.status_text || item.status || '',
			}));
		},
	},
};
</script>

<style scoped>
.merchant-page {
	min-height: 100vh;
	background: #f5f6f8;
	padding-bottom: 160rpx;
	box-sizing: border-box;
}
.merchant-page__body {
	padding: 24rpx;
}
.identity {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8rpx 8rpx 24rpx;
}
.identity__name {
	font-size: 36rpx;
	font-weight: 600;
	color: #1a1a1a;
}
.identity__arrow {
	margin-left: 8rpx;
	font-size: 24rpx;
	color: #888;
	font-size: 22rpx;
	margin-left: 4rpx;
}
.identity__msg {
	width: 64rpx;
	height: 64rpx;
	border-radius: 32rpx;
	background: #fff;
	display: flex;
	align-items: center;
	justify-content: center;
	color: #333;
}
.card {
	background: #fff;
	border-radius: 20rpx;
	padding: 28rpx 24rpx;
	margin-bottom: 20rpx;
}
.card__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 20rpx;
}
.card__title {
	font-size: 30rpx;
	font-weight: 600;
	color: #222;
}
.card__link {
	font-size: 24rpx;
	color: #999;
}
.metrics__actions {
	display: flex;
	align-items: center;
	gap: 20rpx;
}
.metrics__eye {
	font-size: 24rpx;
	color: #666;
}
.metrics__row {
	display: flex;
}
.metrics__row--wrap {
	flex-wrap: wrap;
}
.metrics__item {
	flex: 1;
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
.tip {
	margin-left: 6rpx;
	color: #e93323;
	font-size: 22rpx;
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
.metrics__extra {
	margin-top: 16rpx;
	font-size: 22rpx;
	color: #aaa;
}
.todo-row {
	display: flex;
}
.todo-item {
	flex: 1;
	text-align: center;
}
.todo-item__num {
	display: block;
	font-size: 36rpx;
	font-weight: 600;
	color: #e93323;
}
.todo-item__name {
	display: block;
	margin-top: 8rpx;
	font-size: 22rpx;
	color: #666;
}
.shortcut-grid {
	display: flex;
	flex-wrap: wrap;
}
.shortcut {
	width: 25%;
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 16rpx 0;
	box-sizing: border-box;
}
.shortcut__icon {
	position: relative;
	width: 80rpx;
	height: 80rpx;
	border-radius: 24rpx;
	background: #f7f7f7;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 40rpx;
	color: #333;
}
.shortcut__badge {
	position: absolute;
	top: -8rpx;
	right: -8rpx;
	min-width: 28rpx;
	padding: 0 8rpx;
	height: 28rpx;
	line-height: 28rpx;
	border-radius: 14rpx;
	background: #e93323;
	color: #fff;
	font-size: 18rpx;
	text-align: center;
}
.shortcut__name {
	margin-top: 10rpx;
	font-size: 22rpx;
	color: #444;
}
.rsv-item {
	display: flex;
	align-items: center;
	padding: 16rpx 0;
	border-top: 1rpx solid #f2f2f2;
}
.rsv-item:first-of-type {
	border-top: none;
}
.rsv-item__time {
	width: 100rpx;
	font-size: 26rpx;
	font-weight: 600;
	color: #222;
}
.rsv-item__body {
	flex: 1;
	min-width: 0;
}
.rsv-item__name {
	font-size: 28rpx;
	color: #222;
}
.rsv-item__desc {
	margin-top: 4rpx;
	font-size: 22rpx;
	color: #999;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.rsv-item__status {
	font-size: 22rpx;
	color: #e93323;
}
.empty-tip {
	padding: 24rpx 0;
	text-align: center;
	font-size: 24rpx;
	color: #aaa;
}
.btn-primary {
	margin-top: 20rpx;
	background: #e93323;
	color: #fff;
	font-size: 28rpx;
	border-radius: 40rpx;
}
</style>
