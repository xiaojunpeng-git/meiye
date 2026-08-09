<template>
	<view class="merchant-page">
		<view class="merchant-page__body">
			<!-- 身份卡 -->
			<view class="profile-card">
				<image class="avatar" :src="avatarUrl" mode="aspectFill" />
				<view class="profile-card__body">
					<view class="profile-card__name">{{ displayName }}</view>
					<view class="profile-card__role">{{ storeTitle }}</view>
					<view class="profile-card__hint" v-if="scopeHint">{{ scopeHint }}</view>
				</view>
			</view>

			<!-- 切换门店 -->
			<view class="card mt" v-if="showStoreSwitch">
				<view class="card__sub">切换门店</view>
				<view
					v-for="store in stores"
					:key="store.id"
					class="role-item"
					:class="{ active: Number(store.id) === Number(activeStoreId), disabled: switching }"
					@click="switchStore(store.id)"
				>
					{{ store.name }}
					<text v-if="Number(store.id) === Number(activeStoreId)" class="role-item__tag">当前</text>
				</view>
			</view>

			<!-- 个人工作（按权限裁剪，不堆订单/商品等经营入口） -->
			<view class="card mt" v-if="workMenus.length">
				<view class="card__sub">个人工作</view>
				<view
					v-for="(item, i) in workMenus"
					:key="i"
					class="menu-item"
					@click="onMenu(item)"
				>
					<text>{{ item.name }}</text>
					<text class="arrow">›</text>
				</view>
			</view>

			<!-- 设置：复用买家端账号级消息/安全页（同一登录态） -->
			<view class="card mt">
				<view class="card__sub">设置</view>
				<view class="menu-item" @click="goUrl('/pages/users/message_center/index')">
					<text>消息通知</text>
					<text class="arrow">›</text>
				</view>
				<view class="menu-item" @click="goUrl('/pages/users/user_set/index')">
					<text>账号安全</text>
					<text class="arrow">›</text>
				</view>
				<view class="menu-item">
					<text>版本信息</text>
					<text class="menu-item__val">商家端续作</text>
				</view>
			</view>

			<view class="card mt actions">
				<button class="btn ghost" @click="backBuyer">返回买家端</button>
				<button class="btn danger" @click="logout">退出登录</button>
			</view>
		</view>
		<merchant-tab-bar current="profile" />
	</view>
</template>

<script>
import { mapGetters } from 'vuex';
import merchantGuard from '@/mixins/merchantGuard.js';
import merchantTabBar from '@/components/merchantTabBar/index.vue';

export default {
	mixins: [merchantGuard],
	components: { merchantTabBar },
	data() {
		return {
			switching: false,
		};
	},
	computed: {
		...mapGetters(['userInfo']),
		stores() {
			return this.$store.state.merchant.stores || [];
		},
		activeRole() {
			return this.$store.state.merchant.activeRole || '';
		},
		activeStoreId() {
			return this.$store.state.merchant.activeStoreId || 0;
		},
		identity() {
			return this.$store.state.merchant.identity || {};
		},
		storeTitle() {
			const hit = this.stores.find((s) => Number(s.id) === Number(this.activeStoreId));
			return (hit && hit.name) || '暂无可用门店';
		},
		displayName() {
			const u = this.userInfo || {};
			return u.nickname || u.real_name || u.phone || '商家账号';
		},
		avatarUrl() {
			const u = this.userInfo || {};
			return u.avatar || '/static/images/f.png';
		},
		showStoreSwitch() {
			return !!this.$store.state.merchant.storeSwitchAllowed;
		},
		scopeHint() {
			if (this.$store.state.merchant.taskVisibility === 'SELF') {
				return '仅可查看本人任务';
			}
			if (this.$store.state.merchant.taskVisibility === 'STORE') {
				return '当前门店全部任务';
			}
			if (!this.hasMerchantPermission('merchant.home.view')) {
				return '当前身份为裁剪工作台，经营数据不可见';
			}
			if (this.hasMerchantPermission('merchant.data.self') && !this.hasMerchantPermission('merchant.data.store') && !this.hasMerchantPermission('merchant.data.region')) {
				return '当前仅可查看本人相关数据';
			}
			return '';
		},
		workMenus() {
			const role = this.activeRole;
			const menus = [];
			if (role === 'delivery') {
				menus.push({ name: '配送任务', url: '/pages/admin/distribution/index' });
				menus.push({ name: '工作台', url: '/pages/merchant/workbench/index' });
				return menus;
			}
			if (role === 'platform_service') {
				if (this.hasMerchantPermission('merchant.customer.view')) {
					menus.push({ name: '客户', url: '/pages/merchant/customer/index', redirect: true });
				}
				menus.push({ name: '工作台', url: '/pages/merchant/workbench/index' });
				return menus;
			}
			if (this.hasMerchantPermission('merchant.data.self')) {
				menus.push({ name: '个人业绩', url: '/pages/merchant/yeji/self' });
			}
			if (this.hasMerchantPermission('merchant.data.store') || this.hasMerchantPermission('merchant.data.region')) {
				menus.push({
					name: '当前门店数据',
					url: '/pages/merchant/data/index',
				});
			}
			if (role === 'store_manager' || role === 'store_staff') {
				menus.push({ name: '推广码', url: '/pages/admin/spread/index' });
				menus.push({ name: '老师中心', url: '/pages/admin/staff_center/index' });
				menus.push({ name: '排班/休息', url: '/pages/admin/staff_center/rest/index' });
			}
			if (this.hasMerchantPermission('merchant.target.view')) {
				menus.push({ name: '目标看板', url: '/pages/merchant/target/index', redirect: true });
			}
			menus.push({ name: '工作台', url: '/pages/merchant/workbench/index' });
			return menus;
		},
	},
	async onShow() {
		const ok = await this.ensureMerchantAccess({
			permission: 'merchant.profile.view',
			fallbackMerchantHome: true,
		});
		if (!ok) return;
	},
	methods: {
		developing() {
			uni.showToast({ title: '该功能正在开发中，敬请期待', icon: 'none' });
		},
		goUrl(url) {
			if (!url) return;
			uni.navigateTo({ url });
		},
		onMenu(item) {
			if (!item) return;
			if (item.action === 'developing') return this.developing();
			if (!item.url) return;
			if (item.redirect) {
				uni.redirectTo({ url: item.url });
				return;
			}
			uni.navigateTo({ url: item.url });
		},
		async switchStore(storeId) {
			if (this.switching || Number(storeId) === Number(this.activeStoreId)) return;
			this.switching = true;
			try {
				await this.$store.dispatch('merchant/switchContext', { active_store_id: storeId });
				uni.showToast({ title: '已切换门店，数据范围已更新', icon: 'none' });
			} catch (e) {
				const msg = (e && (e.msg || e.message)) || '切换失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				this.switching = false;
			}
		},
		async backBuyer() {
			await this.$store.dispatch('merchant/exitMerchant');
			uni.reLaunch({ url: '/pages/index/index' });
		},
		logout() {
			uni.showModal({
				title: '提示',
				content: '确定退出登录？',
				success: (res) => {
					if (!res.confirm) return;
					this.$store.commit('LOGOUT');
					this.$store.dispatch('merchant/resetMerchant');
					uni.reLaunch({ url: '/pages/index/index' });
				},
			});
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
.merchant-page__body {
	padding: 24rpx;
}
.profile-card {
	display: flex;
	align-items: center;
	background: linear-gradient(135deg, #2b2b2b, #4a4a4a);
	border-radius: 20rpx;
	padding: 32rpx 28rpx;
	color: #fff;
	margin-bottom: 20rpx;
}
.avatar {
	width: 112rpx;
	height: 112rpx;
	border-radius: 56rpx;
	background: #666;
	margin-right: 24rpx;
	flex-shrink: 0;
}
.profile-card__body {
	flex: 1;
	min-width: 0;
}
.profile-card__name {
	font-size: 36rpx;
	font-weight: 600;
}
.profile-card__role {
	margin-top: 10rpx;
	font-size: 26rpx;
	opacity: 0.85;
}
.profile-card__hint {
	margin-top: 8rpx;
	font-size: 22rpx;
	opacity: 0.7;
}
.card {
	background: #fff;
	border-radius: 16rpx;
	padding: 8rpx 28rpx;
}
.card.mt {
	margin-top: 20rpx;
}
.card.actions {
	padding: 8rpx 28rpx 20rpx;
}
.card__sub {
	font-size: 28rpx;
	font-weight: 500;
	color: #333;
	padding: 24rpx 0 8rpx;
}
.role-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 24rpx 0;
	font-size: 28rpx;
	color: #333;
	border-bottom: 1rpx solid #f0f0f0;
}
.role-item:last-child {
	border-bottom: none;
}
.role-item.active {
	color: #e93323;
}
.role-item.disabled {
	opacity: 0.6;
}
.role-item__tag {
	font-size: 22rpx;
	color: #e93323;
}
.menu-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 28rpx 0;
	font-size: 28rpx;
	color: #222;
	border-bottom: 1rpx solid #f0f0f0;
}
.menu-item:last-child {
	border-bottom: none;
}
.menu-item__val {
	font-size: 24rpx;
	color: #999;
}
.arrow {
	color: #ccc;
	font-size: 32rpx;
}
.btn {
	margin: 20rpx 0;
	font-size: 28rpx;
	border-radius: 12rpx;
}
.btn.ghost {
	background: #fff;
	color: #e93323;
	border: 1rpx solid #e93323;
}
.btn.danger {
	background: #e93323;
	color: #fff;
}
</style>
