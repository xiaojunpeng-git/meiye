<template>
	<view class="merchant-page">
		<view class="merchant-page__body">
			<view v-for="(group, gi) in visibleGroups" :key="gi" class="card">
				<view class="card__title">{{ group.title }}</view>
				<view class="grid">
					<view
						v-for="(item, ii) in group.items"
						:key="ii"
						class="grid__item"
						@click="onItem(item)"
					>
						<view class="grid__icon">
							<text class="iconfont" :class="item.icon"></text>
						</view>
						<text class="grid__name">{{ item.name }}</text>
					</view>
				</view>
			</view>
			<view v-if="!visibleGroups.length" class="card">
				<view class="empty">当前身份暂无可用功能</view>
			</view>
		</view>
	</view>
</template>

<script>
import merchantGuard from '@/mixins/merchantGuard.js';

/**
 * 工作台入口裁剪：对齐首页 shortcutVisible +「我的」workMenus
 * - 配送员：仅扫码核销、配送任务
 * - 店员无店级数据权：隐藏订单/售后/商品
 * - 平台客服：保留订单类，隐藏商品/预约/老师/推广
 */
export default {
	mixins: [merchantGuard],
	data() {
		return {
			groups: [
				{
					title: '店务经营',
					items: [
						{ name: '扫码核销', icon: 'icon-ic_Scan', url: '/pages/admin/order_cancellation/index?auth=4', need: 'cancel' },
						{ name: '订单管理', icon: 'icon-ic_order', url: '/pages/admin/orderList/index', need: 'order' },
						{ name: '售后维权', icon: 'icon-ic_order1', url: '/pages/admin/refundOrderList/index', need: 'order' },
						{ name: '商品管理', icon: 'icon-ic_shop1', url: '/pages/admin/goods/index', need: 'goods' },
						{ name: '预约管理', icon: 'icon-ic_clock', url: '/pages/admin/reservation_list/index', need: 'rsv' },
						{ name: '老师中心', icon: 'icon-ic_user1', url: '/pages/admin/staff_center/index', need: 'staff_center' },
					],
				},
				{
					title: '客户运营',
					perm: 'merchant.customer.view',
					items: [
						{ name: '查找客户', icon: 'icon-ic_search', url: '/pages/merchant/customer/index' },
						{ name: '添加客户', icon: 'icon-ic_user2', url: '/pages/merchant/customer/index?action=add', perm: 'merchant.customer.create' },
						{ name: '补交欠款', icon: 'icon-ic_money', url: '/pages/merchant/debt/index', perm: 'merchant.debt.view' },
						{ name: '客户回访', icon: 'icon-ic_message', action: 'developing', need: 'customer_follow' },
					],
				},
				{
					title: '数据与业绩',
					items: [
						{ name: '数仓', icon: 'icon-ic_order', url: '/pages/merchant/data/index', redirect: true, need: 'data_hub' },
						{ name: '门店业绩', icon: 'icon-ic_star', url: '/pages/admin/yeji/store', perm: 'merchant.data.store' },
						{ name: '个人业绩', icon: 'icon-ic_star1', url: '/pages/merchant/yeji/self', perm: 'merchant.data.self' },
						{ name: '区域统计', icon: 'icon-ic_home', url: '/pages/admin/agent/index', perm: 'merchant.data.region' },
						{ name: '目标看板', icon: 'icon-ic_star', url: '/pages/merchant/target/index', redirect: true, perm: 'merchant.target.view' },
					],
				},
				{
					title: '配送与客服',
					items: [
						{ name: '配送任务', icon: 'icon-ic_Scan', url: '/pages/admin/distribution/index', need: 'delivery' },
						{ name: '推广码', icon: 'icon-ic_QRcode', url: '/pages/admin/spread/index', need: 'spread' },
					],
				},
			],
		};
	},
	computed: {
		activeRole() {
			return this.$store.state.merchant.activeRole || '';
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
		isDeliveryOnly() {
			return this.activeRole === 'delivery' && !this.canStoreData && !this.canSelfData;
		},
		showReservation() {
			return ['store_manager', 'store_staff', 'region_agent'].includes(this.activeRole);
		},
		visibleGroups() {
			return this.groups
				.map((g) => {
					if (g.perm && !this.hasMerchantPermission(g.perm)) return null;
					const items = (g.items || []).filter((it) => this.itemVisible(it));
					if (!items.length) return null;
					return { ...g, items };
				})
				.filter(Boolean);
		},
	},
	async onShow() {
		await this.ensureMerchantAccess();
	},
	methods: {
		itemVisible(it) {
			if (!it) return false;
			if (this.isDeliveryOnly) {
				return ['扫码核销', '配送任务'].includes(it.name);
			}
			if (it.perm && !this.hasMerchantPermission(it.perm)) return false;
			const need = it.need || '';
			if (need === 'cancel') return true;
			if (need === 'order') {
				return this.canStoreData || this.activeRole === 'platform_service' || this.activeRole === 'store_manager';
			}
			if (need === 'goods') {
				return this.canStoreData || this.activeRole === 'store_manager';
			}
			if (need === 'rsv') return this.showReservation;
			if (need === 'staff_center') {
				return this.activeRole === 'store_manager' || this.activeRole === 'store_staff';
			}
			if (need === 'delivery') {
				return ['delivery', 'store_manager', 'region_agent'].includes(this.activeRole);
			}
			if (need === 'spread') {
				return this.activeRole === 'store_manager' || this.activeRole === 'store_staff' || this.activeRole === 'region_agent';
			}
			if (need === 'data_hub') {
				return (
					this.hasMerchantPermission('merchant.home.view') &&
					(this.canStoreData || this.canSelfData)
				);
			}
			if (need === 'customer_follow') {
				return this.hasMerchantPermission('merchant.customer.view');
			}
			return true;
		},
		onItem(item) {
			if (!item) return;
			if (item.action === 'developing') {
				uni.showToast({ title: '该功能正在开发中，敬请期待', icon: 'none' });
				return;
			}
			if (!item.url) return;
			if (item.redirect) {
				uni.redirectTo({ url: item.url });
				return;
			}
			uni.navigateTo({ url: item.url });
		},
	},
};
</script>

<style scoped>
.merchant-page {
	min-height: 100vh;
	background: #f5f6f8;
	padding-bottom: 40rpx;
}
.merchant-page__body {
	padding: 24rpx;
}
.card {
	background: #fff;
	border-radius: 20rpx;
	padding: 28rpx 24rpx 12rpx;
	margin-bottom: 20rpx;
}
.card__title {
	font-size: 30rpx;
	font-weight: 600;
	color: #222;
	margin-bottom: 8rpx;
}
.grid {
	display: flex;
	flex-wrap: wrap;
}
.grid__item {
	width: 25%;
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 24rpx 0;
}
.grid__icon {
	width: 72rpx;
	height: 72rpx;
	border-radius: 20rpx;
	background: #f7f7f8;
	display: flex;
	align-items: center;
	justify-content: center;
}
.grid__icon .iconfont {
	font-size: 36rpx;
	color: #333;
}
.grid__name {
	margin-top: 10rpx;
	font-size: 22rpx;
	color: #444;
}
.empty {
	padding: 40rpx 0;
	text-align: center;
	color: #aaa;
	font-size: 26rpx;
}
</style>
