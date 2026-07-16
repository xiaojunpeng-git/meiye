<template>
	<view class="merchant-page">
		<!-- 筛选/新增：整页切换，彻底避开 H5 scroll-view 盖住 fixed 弹层 -->
		<view v-if="filterVisible" class="overlay-page">
			<view class="overlay-page__bar">
				<text class="overlay-page__link" @click="closeFilter">取消</text>
				<text class="overlay-page__title">筛选客户</text>
				<text class="overlay-page__link on" @click="applyFilter">确定</text>
			</view>
			<scroll-view scroll-y class="overlay-page__body">
				<view class="filter-label">生日</view>
				<view class="chip-row">
					<view
						v-for="b in birthdayOptions"
						:key="b.value"
						class="chip"
						:class="{ on: draftBirthdayType === b.value }"
						@click="draftBirthdayType = b.value"
					>{{ b.label }}</view>
				</view>
				<view class="filter-label">性别</view>
				<view class="chip-row">
					<view
						v-for="s in sexOptions"
						:key="'sex-' + s.value"
						class="chip"
						:class="{ on: draftSex === s.value }"
						@click="draftSex = s.value"
					>{{ s.label }}</view>
				</view>
				<view class="filter-label">余额区间</view>
				<view class="money-row">
					<input
						class="money-input"
						type="digit"
						v-model="draftMoneyMin"
						placeholder="最低"
					/>
					<text class="money-sep">—</text>
					<input
						class="money-input"
						type="digit"
						v-model="draftMoneyMax"
						placeholder="最高"
					/>
				</view>
				<view class="overlay-page__reset" @click="resetFilterDraft">重置条件</view>
			</scroll-view>
		</view>

		<view v-else-if="createVisible" class="overlay-page">
			<view class="overlay-page__bar">
				<text class="overlay-page__link" @click="closeCreate">取消</text>
				<text class="overlay-page__title">新增客户</text>
				<text
					class="overlay-page__link on"
					:class="{ disabled: creating }"
					@click="submitCreate"
				>{{ creating ? '提交中…' : '确定' }}</text>
			</view>
			<view class="overlay-page__body">
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
			</view>
		</view>

		<template v-else>
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
				<view
					v-if="showFilterEntry"
					class="filter-btn"
					:class="{ on: filterActiveCount > 0 }"
					@click="openFilter"
				>
					筛选{{ filterActiveCount > 0 ? `(${filterActiveCount})` : '' }}
				</view>
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
					<text
						v-if="listSegment === 'debt'"
						class="sub-bar__link"
						@click="goDebtOrders"
					>欠款单</text>
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

			<merchant-tab-bar current="customer" />
		</template>
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
			filterVisible: false,
			filterBirthdayType: 0,
			filterSex: '',
			filterMoneyMin: '',
			filterMoneyMax: '',
			draftBirthdayType: 0,
			draftSex: '',
			draftMoneyMin: '',
			draftMoneyMax: '',
			sexOptions: [
				{ value: '', label: '不限' },
				{ value: 1, label: '男' },
				{ value: 2, label: '女' },
				{ value: 0, label: '其他' },
			],
			birthdayOptions: [
				{ value: 0, label: '全部' },
				{ value: 1, label: '今天' },
				{ value: 2, label: '明天' },
				{ value: 3, label: '本月' },
			],
		};
	},
	computed: {
		canCreate() {
			return this.hasMerchantPermission('merchant.customer.create');
		},
		showFilterEntry() {
			return this.tab === 'mine' || this.tab === 'all';
		},
		filterActiveCount() {
			let n = 0;
			if (Number(this.filterBirthdayType) > 0) n += 1;
			if (this.filterSex !== '' && this.filterSex !== null && this.filterSex !== undefined) n += 1;
			if (String(this.filterMoneyMin || '').trim() !== '' || String(this.filterMoneyMax || '').trim() !== '') n += 1;
			return n;
		},
		nowMoneyPeiceParam() {
			const min = String(this.filterMoneyMin || '').trim();
			const max = String(this.filterMoneyMax || '').trim();
			if (min === '' && max === '') return '';
			return `${min}-${max}`;
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
		const drillSegments = ['new_month', 'new_customer', 'debt', 'card_recharge', 'visit', 'repurchase'];
		if (opt && (drillSegments.indexOf(opt.segment) >= 0 || opt.birthday_type)) {
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
				else if (opt.segment === 'debt') this.pendingDrill.title = '欠款客户';
				else if (opt.segment === 'card_recharge') this.pendingDrill.title = '开卡充值客户';
				else if (opt.segment === 'visit') this.pendingDrill.title = '到店客户';
				else if (opt.segment === 'repurchase') this.pendingDrill.title = '复购客户';
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
			if (s.no_permission) return '暂无权限';
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
			if (s.no_permission) {
				uni.showToast({ title: '暂无欠款查看权限', icon: 'none' });
				return;
			}
			if (s.developing || s.count === null || s.count === undefined) {
				uni.showToast({ title: '该客群规则开发中', icon: 'none' });
				return;
			}
			if (s.key === 'debt' || (s.filter && s.filter.segment === 'debt')) {
				if (!this.hasMerchantPermission('merchant.debt.view')) {
					uni.showToast({ title: '暂无欠款查看权限', icon: 'none' });
					return;
				}
				this.openFocusList(s.name || '欠款客户', { segment: 'debt' });
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
		goDebtOrders() {
			if (!this.hasMerchantPermission('merchant.debt.view')) {
				uni.showToast({ title: '暂无欠款查看权限', icon: 'none' });
				return;
			}
			uni.navigateTo({ url: '/pages/merchant/debt/index' });
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
		openFilter() {
			if (!this.showFilterEntry) return;
			this.draftBirthdayType = Number(this.filterBirthdayType) || 0;
			this.draftSex = this.filterSex === '' || this.filterSex === null || this.filterSex === undefined
				? ''
				: Number(this.filterSex);
			this.draftMoneyMin = this.filterMoneyMin;
			this.draftMoneyMax = this.filterMoneyMax;
			this.filterVisible = true;
		},
		closeFilter() {
			this.filterVisible = false;
		},
		resetFilterDraft() {
			this.draftBirthdayType = 0;
			this.draftSex = '';
			this.draftMoneyMin = '';
			this.draftMoneyMax = '';
		},
		applyFilter() {
			this.filterBirthdayType = Number(this.draftBirthdayType) || 0;
			this.filterSex = this.draftSex === '' || this.draftSex === null || this.draftSex === undefined
				? ''
				: Number(this.draftSex);
			this.filterMoneyMin = String(this.draftMoneyMin || '').trim();
			this.filterMoneyMax = String(this.draftMoneyMax || '').trim();
			this.filterVisible = false;
			this.reload();
		},
		fetchList() {
			if (this.loading || this.loadend) return;
			this.loading = true;
			const inFocusList = this.tab === 'focus' && this.focusMode === 'list';
			const data = {
				page: this.page,
				limit: this.limit,
				keyword: this.keyword || '',
				birthday_type: inFocusList ? this.birthdayType : (this.filterBirthdayType || 0),
				segment: inFocusList ? (this.listSegment || '') : '',
				start_date: inFocusList ? (this.listStartDate || '') : '',
				end_date: inFocusList ? (this.listEndDate || '') : '',
				...this.contextParams(),
			};
			if (!inFocusList && this.nowMoneyPeiceParam) {
				data.now_money_peice = this.nowMoneyPeiceParam;
			}
			if (!inFocusList && this.filterSex !== '' && this.filterSex !== null && this.filterSex !== undefined) {
				data.sex = Number(this.filterSex);
			}
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
.filter-btn {
	margin-left: 8rpx;
	padding: 0 16rpx;
	height: 68rpx;
	line-height: 68rpx;
	font-size: 26rpx;
	color: #666;
	white-space: nowrap;
}
.filter-btn.on {
	color: #e93323;
	font-weight: 600;
}
.filter-label {
	font-size: 26rpx;
	color: #666;
	margin: 8rpx 0 16rpx;
}
.chip-row {
	display: flex;
	flex-wrap: wrap;
	margin-bottom: 16rpx;
}
.chip {
	padding: 12rpx 28rpx;
	margin: 0 16rpx 16rpx 0;
	background: #f5f5f5;
	border-radius: 28rpx;
	font-size: 26rpx;
	color: #333;
}
.chip.on {
	background: rgba(233, 51, 35, 0.1);
	color: #e93323;
}
.money-row {
	display: flex;
	align-items: center;
	margin-bottom: 12rpx;
}
.money-input {
	flex: 1;
	height: 72rpx;
	background: #f5f5f5;
	border-radius: 12rpx;
	padding: 0 20rpx;
	font-size: 28rpx;
}
.money-sep {
	margin: 0 16rpx;
	color: #999;
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
	flex: 1;
}
.sub-bar__link {
	font-size: 26rpx;
	color: #e93323;
	padding-left: 16rpx;
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
.overlay-page {
	flex: 1;
	min-height: 100vh;
	background: #fff;
	display: flex;
	flex-direction: column;
	box-sizing: border-box;
	padding-bottom: env(safe-area-inset-bottom);
}
.overlay-page__bar {
	display: flex;
	align-items: center;
	padding: 24rpx 28rpx;
	border-bottom: 1rpx solid #f0f0f0;
	background: #fff;
}
.overlay-page__title {
	flex: 1;
	text-align: center;
	font-size: 32rpx;
	font-weight: 600;
	color: #222;
}
.overlay-page__link {
	min-width: 80rpx;
	font-size: 28rpx;
	color: #666;
}
.overlay-page__link.on {
	color: #e93323;
	font-weight: 600;
	text-align: right;
}
.overlay-page__link.disabled {
	opacity: 0.55;
}
.overlay-page__body {
	flex: 1;
	height: 0;
	padding: 28rpx 32rpx;
	box-sizing: border-box;
	background: #fff;
}
.overlay-page__reset {
	margin-top: 40rpx;
	text-align: center;
	font-size: 28rpx;
	color: #999;
	padding: 20rpx;
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
</style>
