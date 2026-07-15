<template>
	<view class="merchant-page">
		<view class="search-bar">
			<view class="search-box">
				<text class="iconfont icon-ic_search"></text>
				<input
					class="search-input"
					v-model="keyword"
					confirm-type="search"
					placeholder="姓名/手机号"
					@confirm="onSearch"
				/>
			</view>
			<view class="search-btn" @click="onSearch">查询</view>
		</view>
		<view class="tabs">
			<view
				v-for="t in tabs"
				:key="t.key"
				class="tab"
				:class="{ active: tab === t.key }"
				@click="switchTab(t.key)"
			>{{ t.name }}</view>
		</view>

		<!-- 重点客户：客群卡片 -->
		<scroll-view v-if="tab === 'focus' && focusMode === 'segments'" scroll-y class="list">
			<view
				v-for="(s, i) in segments"
				:key="s.key || i"
				class="seg-card"
				@click="onSegment(s)"
			>
				<view class="seg-card__main">
					<view class="seg-card__name">{{ s.name }}</view>
					<view class="seg-card__desc">{{ s.desc || s.action || '' }}</view>
				</view>
				<view class="seg-card__right">
					<view class="seg-card__count">{{ formatSegCount(s) }}</view>
					<text class="seg-card__arrow">›</text>
				</view>
			</view>
			<view v-if="!segments.length && !segLoading" class="empty">暂无客群</view>
			<view v-if="segLoading" class="empty">加载中...</view>
			<view class="list-pad"></view>
		</scroll-view>

		<!-- 重点客户：下钻列表 -->
		<template v-else-if="tab === 'focus' && focusMode === 'list'">
			<view class="sub-bar">
				<text class="sub-bar__back" @click="backToSegments">‹ 返回客群</text>
				<text class="sub-bar__title">{{ focusListTitle }}</text>
			</view>
			<scroll-view scroll-y class="list" @scrolltolower="loadMore">
				<view
					v-for="(item, index) in userLists"
					:key="'f-' + index"
					class="user-card"
					@click="goDetail(item)"
				>
					<image class="avatar" :src="item.avatar || '/static/images/f.png'" mode="aspectFill" />
					<view class="user-body">
						<view class="user-name">{{ item.nickname || '客户' }}</view>
						<view class="user-phone" @click.stop="callPhone(item.phone)">{{ maskPhone(item.phone) }}</view>
						<view class="user-meta">余额 {{ item.now_money || 0 }} · 积分 {{ item.integral || 0 }}</view>
					</view>
				</view>
				<view v-if="!userLists.length && !loading" class="empty">暂无客户</view>
				<view v-if="loading" class="empty">加载中...</view>
				<view class="list-pad"></view>
			</scroll-view>
		</template>

		<!-- 我的客户 / 全部客户 -->
		<template v-else>
			<view class="summary" v-if="tab === 'mine' && mineSummary">
				<view class="summary__item" @click="onMineFilter('all')">
					<view class="summary__val">{{ formatNum(mineSummary.total) }}</view>
					<view class="summary__label">客户总数</view>
				</view>
				<view class="summary__item" @click="onMineFilter('new_month')">
					<view class="summary__val">{{ formatMineNew(mineSummary) }}</view>
					<view class="summary__label">本月新增</view>
				</view>
				<view class="summary__item" @click="onMineFilter('birthday_today')">
					<view class="summary__val">{{ formatNum(mineSummary.birthday_today) }}</view>
					<view class="summary__label">今日生日</view>
				</view>
			</view>
			<scroll-view scroll-y class="list" @scrolltolower="loadMore">
				<view
					v-for="(item, index) in userLists"
					:key="index"
					class="user-card"
					@click="goDetail(item)"
				>
					<image class="avatar" :src="item.avatar || '/static/images/f.png'" mode="aspectFill" />
					<view class="user-body">
						<view class="user-name">{{ item.nickname || '客户' }}</view>
						<view class="user-phone" @click.stop="callPhone(item.phone)">{{ maskPhone(item.phone) }}</view>
						<view class="user-meta">余额 {{ item.now_money || 0 }} · 积分 {{ item.integral || 0 }}</view>
						<view class="user-meta">手艺人 {{ item.shouyi || '-' }} · {{ item.belong_store || '' }}</view>
						<view class="user-meta" v-if="item.order_time">上次服务 {{ item.order_time }}</view>
					</view>
				</view>
				<view v-if="!userLists.length && !loading" class="empty">暂无客户</view>
				<view v-if="loading" class="empty">加载中...</view>
				<view class="list-pad"></view>
			</scroll-view>
		</template>

		<view class="fab" @click="openCreate" v-if="canCreate">
			<text class="fab__plus">＋</text>
		</view>

		<!-- 新增客户 -->
		<view class="mask" v-if="createVisible" @click="closeCreate">
			<view class="sheet" @click.stop>
				<view class="sheet__title">新增客户</view>
				<view class="form-row">
					<text class="form-label">手机号</text>
					<input class="form-input" type="number" maxlength="11" v-model="createForm.phone" placeholder="请输入手机号" />
				</view>
				<view class="form-row">
					<text class="form-label">昵称</text>
					<input class="form-input" v-model="createForm.nickname" placeholder="选填，默认脱敏手机号" />
				</view>
				<view class="form-row">
					<text class="form-label">备注</text>
					<input class="form-input" v-model="createForm.mark" placeholder="选填" />
				</view>
				<view class="sheet__actions">
					<view class="btn btn--ghost" @click="closeCreate">取消</view>
					<view class="btn btn--primary" :class="{ disabled: creating }" @click="submitCreate">{{ creating ? '提交中…' : '确定' }}</view>
				</view>
			</view>
		</view>

		<merchant-tab-bar current="customer" />
	</view>
</template>

<script>
import merchantGuard from '@/mixins/merchantGuard.js';
import merchantTabBar from '@/components/merchantTabBar/index.vue';
import {
	merchantCustomerSegments,
	merchantCustomerMineSummary,
	merchantCustomerCreate,
	merchantCustomerList,
} from '@/api/merchant.js';

export default {
	mixins: [merchantGuard],
	components: { merchantTabBar },
	data() {
		return {
			tab: 'focus',
			tabs: [
				{ key: 'focus', name: '重点客户' },
				{ key: 'mine', name: '我的客户' },
				{ key: 'all', name: '全部客户' },
			],
			focusMode: 'segments',
			focusListTitle: '',
			birthdayType: 0,
			listSegment: '',
			listStartDate: '',
			listEndDate: '',
			pendingDrill: null,
			segments: [],
			segLoading: false,
			mineSummary: null,
			keyword: '',
			userLists: [],
			page: 1,
			limit: 20,
			loadend: false,
			loading: false,
			createVisible: false,
			creating: false,
			createForm: {
				phone: '',
				nickname: '',
				mark: '',
			},
			pendingAdd: false,
		};
	},
	computed: {
		canCreate() {
			return this.hasMerchantPermission('merchant.customer.create');
		},
	},
	async onShow() {
		const ok = await this.ensureMerchantAccess({
			permission: 'merchant.customer.view',
			fallbackMerchantHome: true,
		});
		if (!ok) return;
		if (this.pendingAdd) {
			this.pendingAdd = false;
			this.openCreate();
		}
		if (this.pendingDrill) {
			const d = this.pendingDrill;
			this.pendingDrill = null;
			this.openFocusList(d.title || '客户列表', d);
			return;
		}
		this.refreshCurrent();
	},
	onLoad(opt) {
		if (opt && opt.action === 'add') {
			this.pendingAdd = true;
		}
		if (opt && opt.tab) {
			this.tab = opt.tab;
		}
		if (opt && (opt.segment === 'new_month' || opt.segment === 'new_customer' || opt.birthday_type)) {
			this.pendingDrill = {
				title: opt.title ? decodeURIComponent(String(opt.title)) : '',
				segment: opt.segment || '',
				birthday_type: Number(opt.birthday_type || 0),
				start_date: opt.start_date || '',
				end_date: opt.end_date || '',
			};
			if (!this.pendingDrill.title) {
				if (opt.segment === 'new_month') this.pendingDrill.title = '本月新增';
				else if (opt.segment === 'new_customer') this.pendingDrill.title = '新增客户';
				else if (Number(opt.birthday_type) === 1) this.pendingDrill.title = '今日生日';
			}
		}
	},
	methods: {
		contextParams() {
			return {
				active_store_id: this.$store.state.merchant.activeStoreId || 0,
				active_role: this.$store.state.merchant.activeRole || '',
			};
		},
		maskPhone(phone) {
			const p = String(phone || '');
			if (p.length < 7) return p || '-';
			return p.replace(/(\d{3})\d{4}(\d+)/, '$1****$2');
		},
		formatNum(n) {
			if (n === null || n === undefined) return '-';
			return String(n);
		},
		formatSegCount(s) {
			if (!s) return '-';
			if (s.developing || s.count === null || s.count === undefined) return '开发中';
			return String(s.count);
		},
		formatMineNew(sum) {
			if (!sum) return '-';
			if (sum.new_month_developing) return '开发中';
			return this.formatNum(sum.new_month);
		},
		callPhone(phone) {
			if (!phone) return;
			uni.makePhoneCall({ phoneNumber: String(phone) });
		},
		switchTab(key) {
			if (this.tab === key) return;
			this.tab = key;
			this.focusMode = 'segments';
			this.birthdayType = 0;
			this.listSegment = '';
			this.listStartDate = '';
			this.listEndDate = '';
			this.refreshCurrent();
		},
		refreshCurrent() {
			if (this.tab === 'focus' && this.focusMode === 'segments') {
				this.loadSegments();
				return;
			}
			if (this.tab === 'mine') {
				this.loadMineSummary();
			}
			this.reload();
		},
		onSearch() {
			if (this.tab === 'focus' && this.focusMode === 'segments') {
				this.tab = 'all';
			}
			this.reload();
		},
		async loadSegments() {
			this.segLoading = true;
			try {
				const res = await merchantCustomerSegments(this.contextParams());
				const list = (res && res.data && (res.data.list || res.data)) || [];
				this.segments = Array.isArray(list) ? list : [];
			} catch (e) {
				this.segments = [];
				const msg = (e && (e.msg || e.message)) || '客群加载失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				this.segLoading = false;
			}
		},
		async loadMineSummary() {
			try {
				const res = await merchantCustomerMineSummary(this.contextParams());
				this.mineSummary = (res && res.data) || null;
			} catch (e) {
				this.mineSummary = null;
			}
		},
		onMineFilter(type) {
			if (type === 'all') {
				this.tab = 'all';
				this.focusMode = 'segments';
				this.birthdayType = 0;
				this.listSegment = '';
				this.listStartDate = '';
				this.listEndDate = '';
				this.refreshCurrent();
				return;
			}
			if (type === 'new_month') {
				if (this.mineSummary && this.mineSummary.new_month_developing) {
					uni.showToast({ title: '本月新增口径开发中', icon: 'none' });
					return;
				}
				this.openFocusList('本月新增', { segment: 'new_month' });
				return;
			}
			if (type === 'birthday_today') {
				this.openFocusList('今日生日', { birthday_type: 1 });
			}
		},
		openFocusList(title, filter) {
			this.tab = 'focus';
			this.focusMode = 'list';
			this.focusListTitle = title || '客户列表';
			this.birthdayType = Number((filter && filter.birthday_type) || 0);
			this.listSegment = String((filter && filter.segment) || '');
			this.listStartDate = String((filter && filter.start_date) || '');
			this.listEndDate = String((filter && filter.end_date) || '');
			this.reload();
		},
		onSegment(s) {
			if (!s) return;
			if (s.developing || s.count === null || s.count === undefined) {
				uni.showToast({ title: '该客群规则开发中', icon: 'none' });
				return;
			}
			if (s.key === 'debt') {
				if (!this.hasMerchantPermission('merchant.debt.view')) {
					uni.showToast({ title: '暂无欠款查看权限', icon: 'none' });
					return;
				}
				uni.navigateTo({ url: '/pages/merchant/debt/index' });
				return;
			}
			const bt = s.filter && s.filter.birthday_type;
			if (bt) {
				this.openFocusList(s.name || '客户列表', { birthday_type: Number(bt) || 0 });
				return;
			}
			if (s.key === 'new_month' || (s.filter && s.filter.segment === 'new_month')) {
				this.openFocusList(s.name || '本月新增', { segment: 'new_month' });
				return;
			}
			uni.showToast({ title: s.action || '请到全部客户查看', icon: 'none' });
		},
		backToSegments() {
			this.focusMode = 'segments';
			this.birthdayType = 0;
			this.listSegment = '';
			this.listStartDate = '';
			this.listEndDate = '';
			this.userLists = [];
			this.loadSegments();
		},
		reload() {
			this.page = 1;
			this.loadend = false;
			this.userLists = [];
			this.fetchList();
		},
		loadMore() {
			if (this.loadend || this.loading) return;
			this.page += 1;
			this.fetchList();
		},
		fetchList() {
			if (this.loading || this.loadend) return;
			this.loading = true;
			const inFocusList = this.tab === 'focus' && this.focusMode === 'list';
			const data = {
				page: this.page,
				limit: this.limit,
				keyword: this.keyword || '',
				birthday_type: inFocusList ? this.birthdayType : 0,
				segment: inFocusList ? (this.listSegment || '') : '',
				start_date: inFocusList ? (this.listStartDate || '') : '',
				end_date: inFocusList ? (this.listEndDate || '') : '',
				...this.contextParams(),
			};
			if (this.tab === 'mine') {
				data.field_key = 'mine';
			}
			merchantCustomerList(data)
				.then((res) => {
					const list = (res && res.data && (res.data.list || res.data)) || [];
					const rows = Array.isArray(list) ? list : [];
					this.userLists = this.userLists.concat(rows);
					this.loadend = rows.length < this.limit;
				})
				.catch((e) => {
					this.loadend = true;
					const msg = (e && (e.msg || e.message)) || '加载失败';
					uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
				})
				.finally(() => {
					this.loading = false;
				});
		},
		async goDetail(item) {
			if (!item || !item.uid) return;
			uni.navigateTo({ url: `/pages/merchant/customer/detail?uid=${item.uid}` });
		},
		openCreate() {
			if (!this.canCreate) {
				uni.showToast({ title: '暂无新增客户权限', icon: 'none' });
				return;
			}
			this.createForm = { phone: '', nickname: '', mark: '' };
			this.createVisible = true;
		},
		closeCreate() {
			if (this.creating) return;
			this.createVisible = false;
		},
		async submitCreate() {
			if (this.creating) return;
			const phone = String(this.createForm.phone || '').trim();
			if (!/^1\d{10}$/.test(phone)) {
				uni.showToast({ title: '请输入正确手机号', icon: 'none' });
				return;
			}
			this.creating = true;
			try {
				await merchantCustomerCreate({
					...this.contextParams(),
					phone,
					nickname: String(this.createForm.nickname || '').trim(),
					mark: String(this.createForm.mark || '').trim(),
				});
				uni.showToast({ title: '创建成功', icon: 'success' });
				this.createVisible = false;
				this.refreshCurrent();
			} catch (e) {
				const msg = (e && (e.msg || e.message)) || '创建失败';
				uni.showToast({ title: String(msg).slice(0, 40), icon: 'none' });
			} finally {
				this.creating = false;
			}
		},
	},
};
</script>

<style scoped>
.merchant-page {
	min-height: 100vh;
	background: #f5f6f8;
	display: flex;
	flex-direction: column;
	padding-bottom: 140rpx;
	box-sizing: border-box;
}
.search-bar {
	display: flex;
	align-items: center;
	padding: 20rpx 24rpx 8rpx;
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
.search-box .iconfont {
	color: #999;
	margin-right: 12rpx;
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
.tabs {
	display: flex;
	background: #fff;
	padding: 0 12rpx 12rpx;
}
.tab {
	flex: 1;
	text-align: center;
	font-size: 28rpx;
	color: #666;
	padding: 16rpx 0;
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
	width: 48rpx;
	height: 4rpx;
	margin-left: -24rpx;
	background: #e93323;
	border-radius: 2rpx;
}
.summary {
	display: flex;
	background: #fff;
	margin: 16rpx 24rpx 0;
	border-radius: 16rpx;
	padding: 24rpx 0;
}
.summary__item {
	flex: 1;
	text-align: center;
}
.summary__val {
	font-size: 34rpx;
	font-weight: 600;
	color: #222;
}
.summary__label {
	margin-top: 8rpx;
	font-size: 22rpx;
	color: #999;
}
.sub-bar {
	display: flex;
	align-items: center;
	padding: 16rpx 24rpx;
	background: #fff;
	border-top: 1rpx solid #f3f3f3;
}
.sub-bar__back {
	font-size: 28rpx;
	color: #e93323;
	margin-right: 20rpx;
}
.sub-bar__title {
	font-size: 28rpx;
	color: #222;
	font-weight: 600;
}
.list {
	flex: 1;
	height: 0;
	padding: 16rpx 24rpx;
	box-sizing: border-box;
}
.seg-card {
	display: flex;
	align-items: center;
	background: #fff;
	border-radius: 16rpx;
	padding: 28rpx 24rpx;
	margin-bottom: 16rpx;
}
.seg-card__main {
	flex: 1;
	min-width: 0;
}
.seg-card__name {
	font-size: 30rpx;
	font-weight: 600;
	color: #222;
}
.seg-card__desc {
	margin-top: 8rpx;
	font-size: 22rpx;
	color: #999;
}
.seg-card__right {
	display: flex;
	align-items: center;
}
.seg-card__count {
	font-size: 36rpx;
	font-weight: 600;
	color: #e93323;
	margin-right: 8rpx;
}
.seg-card__arrow {
	font-size: 32rpx;
	color: #ccc;
}
.user-card {
	display: flex;
	background: #fff;
	border-radius: 16rpx;
	padding: 24rpx;
	margin-bottom: 16rpx;
}
.avatar {
	width: 88rpx;
	height: 88rpx;
	border-radius: 44rpx;
	background: #eee;
	margin-right: 20rpx;
	flex-shrink: 0;
}
.user-body {
	flex: 1;
	min-width: 0;
}
.user-name {
	font-size: 30rpx;
	font-weight: 600;
	color: #222;
}
.user-phone {
	margin-top: 6rpx;
	font-size: 24rpx;
	color: #666;
}
.user-meta {
	margin-top: 6rpx;
	font-size: 22rpx;
	color: #999;
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
.fab {
	position: fixed;
	right: 32rpx;
	bottom: calc(160rpx + env(safe-area-inset-bottom));
	width: 96rpx;
	height: 96rpx;
	border-radius: 48rpx;
	background: #e93323;
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 8rpx 24rpx rgba(233, 51, 35, 0.35);
	z-index: 50;
}
.fab__plus {
	color: #fff;
	font-size: 48rpx;
	line-height: 1;
}
.mask {
	position: fixed;
	left: 0;
	right: 0;
	top: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.45);
	z-index: 100;
	display: flex;
	align-items: flex-end;
}
.sheet {
	width: 100%;
	background: #fff;
	border-radius: 24rpx 24rpx 0 0;
	padding: 32rpx 32rpx calc(32rpx + env(safe-area-inset-bottom));
	box-sizing: border-box;
}
.sheet__title {
	font-size: 32rpx;
	font-weight: 600;
	color: #222;
	margin-bottom: 24rpx;
}
.form-row {
	display: flex;
	align-items: center;
	padding: 20rpx 0;
	border-bottom: 1rpx solid #f3f3f3;
}
.form-label {
	width: 120rpx;
	font-size: 28rpx;
	color: #666;
}
.form-input {
	flex: 1;
	font-size: 28rpx;
}
.sheet__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 32rpx;
}
.btn {
	padding: 16rpx 36rpx;
	margin-left: 16rpx;
	border-radius: 36rpx;
	font-size: 28rpx;
}
.btn--ghost {
	background: #f5f5f5;
	color: #666;
}
.btn--primary {
	background: #e93323;
	color: #fff;
}
.btn.disabled {
	opacity: 0.6;
}
</style>
