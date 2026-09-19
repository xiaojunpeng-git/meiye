<template>
	<view class="therapist-page" :style="colorStyle">
		<!-- #ifdef MP || APP-PLUS -->
		<NavBar titleText="服务老师" :iconColor="iconColor" :textColor="iconColor" :bagColor="bagColor" showBack />
		<!-- #endif -->
		<!-- #ifdef H5 -->
		<view class="h5-nav acea-row row-center-wrapper">
			<text class="iconfont icon-ic_leftarrow" @click="goBack"></text>
			<text class="h5-nav-title">服务老师</text>
		</view>
		<!-- #endif -->
		<view class="header">
			<view class="search-bar">
				<view class="location-btn" @click="openStorePicker">
					<text class="iconfont icon-ic_location51 location-icon"></text>
					<text class="selected-store line1">{{ storeName || '选择门店' }}</text>
					<text class="iconfont icon-ic_down2 dropdown-icon"></text>
				</view>
				<view class="search-input">
					<text class="iconfont icon-ic_search search-icon"></text>
					<input
						v-model="keyword"
						placeholder="搜索美容/芳疗师"
						confirm-type="search"
						@confirm="loadStaffList"
					/>
				</view>
			</view>
		</view>

		<view class="filter-bar">
			<view
				class="filter-item"
				:class="{ active: sortPanelVisible || sortType !== 'smart' }"
				@click="toggleSortPanel"
			>
				<text>{{ sortLabel }}</text>
				<text
					class="iconfont filter-arrow"
					:class="sortPanelVisible ? 'icon-ic_uparrow' : 'icon-ic_down2'"
				></text>
			</view>
			<view
				class="filter-item"
				:class="{ active: timePanelVisible || hasTimeFilter }"
				@click="toggleTimePanel"
			>
				<text>{{ timeFilterLabel }}</text>
				<text
					class="iconfont filter-arrow"
					:class="timePanelVisible ? 'icon-ic_uparrow' : 'icon-ic_down2'"
				></text>
			</view>
			<view
				class="filter-item"
				:class="{ active: advancedPanelVisible || hasAdvancedFilter }"
				@click="toggleAdvancedPanel"
			>
				<text>全部筛选</text>
				<text
					class="iconfont filter-arrow"
					:class="advancedPanelVisible ? 'icon-ic_uparrow' : 'icon-ic_down2'"
				></text>
			</view>
		</view>

		<view v-if="sortPanelVisible || timePanelVisible || advancedPanelVisible" class="sort-mask" @click="closeAllPanels"></view>
		<view v-if="sortPanelVisible" class="sort-panel">
			<view
				v-for="item in sortOptions"
				:key="item.value"
				class="sort-option"
				@click="selectSort(item.value)"
			>
				<text class="sort-option-label">{{ item.label }}</text>
				<view class="sort-option-check" :class="{ checked: sortType === item.value }">
					<text v-if="sortType === item.value" class="iconfont icon-a-ic_CompleteSelect"></text>
				</view>
			</view>
		</view>

		<view v-if="timePanelVisible" class="time-panel">
			<scroll-view scroll-x class="time-date-scroll">
				<view
					v-for="(item, index) in dateTabs"
					:key="item.date"
					class="time-date-item"
					:class="{ active: pendingDateIndex === index }"
					@click="selectPendingDate(index, item.date)"
				>
					<text class="time-date-title">{{ item.title }}</text>
					<text class="time-date-week">{{ item.week }}</text>
				</view>
			</scroll-view>
			<view v-if="timeSlotsLoading" class="time-slots-loading">加载时段...</view>
			<scroll-view v-else scroll-y class="time-slots-scroll">
				<view class="time-slots-grid">
					<view
						v-for="slot in visibleTimeSlots"
						:key="slot.time"
						class="time-slot"
						:class="{
							disabled: !slot.available,
							selected: pendingTime === slot.time,
						}"
						@click="selectPendingTime(slot)"
					>
						<text class="slot-time">{{ slot.time }}</text>
						<text class="slot-status">{{ slot.available ? '可预约' : '不可约' }}</text>
					</view>
				</view>
				<view v-if="!visibleTimeSlots.length" class="time-slots-empty">暂无可选时段</view>
			</scroll-view>
			<view class="time-panel-footer">
				<view class="time-footer-btn reset" @click="resetTimeFilter">重置</view>
				<view class="time-footer-btn confirm" @click="confirmTimeFilter">确定</view>
			</view>
		</view>

		<view v-if="advancedPanelVisible" class="advanced-panel">
			<scroll-view scroll-y class="advanced-scroll">
				<view class="filter-section">
					<view class="filter-section-title">服务状态</view>
					<view class="filter-options">
						<view
							v-for="item in serviceStatusOptions"
							:key="item.value"
							class="filter-chip"
							:class="{ active: pendingAdvancedFilters.serviceStatus === item.value }"
							@click="selectAdvancedOption('serviceStatus', item.value)"
						>{{ item.label }}</view>
					</view>
				</view>
				<view class="filter-section">
					<view class="filter-section-title">美容/芳疗师性别</view>
					<view class="filter-options">
						<view
							v-for="item in sexOptions"
							:key="item.value"
							class="filter-chip"
							:class="{ active: pendingAdvancedFilters.sex === item.value }"
							@click="selectAdvancedOption('sex', item.value)"
						>{{ item.label }}</view>
					</view>
				</view>
				<view class="filter-section">
					<view class="filter-section-title">美容/芳疗师年龄</view>
					<view class="filter-options">
						<view
							v-for="item in ageCateOptions"
							:key="item.value"
							class="filter-chip"
							:class="{ active: pendingAdvancedFilters.ageCate === item.value }"
							@click="selectAdvancedOption('ageCate', item.value)"
						>{{ item.label }}</view>
						<view class="filter-age-input">
							<input
								v-model="pendingAdvancedFilters.minAge"
								type="number"
								placeholder="最小年龄"
								@focus="clearAgeCate"
							/>
						</view>
						<view class="filter-age-input">
							<input
								v-model="pendingAdvancedFilters.maxAge"
								type="number"
								placeholder="最大年龄"
								@focus="clearAgeCate"
							/>
						</view>
					</view>
				</view>
			</scroll-view>
			<view class="time-panel-footer">
				<view class="time-footer-btn reset" @click="resetAdvancedFilter">重置</view>
				<view class="time-footer-btn confirm" @click="confirmAdvancedFilter">确定</view>
			</view>
		</view>

		<scroll-view scroll-y class="content" :style="contentStyle" @scrolltolower="loadStaffList">
			<view v-if="loading && !staffList.length" class="loading-tip">加载中...</view>
			<emptyPage v-else-if="!loading && !staffList.length" title="暂无老师信息" src="/statics/images/noOrder.gif" />
			<view
				v-for="item in staffList"
				:key="item.id"
				class="therapist-card"
			>
				<image
					v-if="item.avatar"
					class="therapist-avatar"
					:src="item.avatar"
					mode="aspectFill"
				/>
				<view v-else class="therapist-avatar therapist-avatar--placeholder">
					<text class="iconfont icon-ic_user1"></text>
				</view>
				<view class="therapist-content">
					<view class="therapist-header">
						<text class="therapist-name">{{ item.staff_name }}</text>
						<text v-if="item.position_label" class="therapist-type">({{ item.position_label }})</text>
					</view>
					<view class="therapist-level">
						<view class="stars">
							<text
								v-for="n in 5"
								:key="n"
								class="star"
								:class="{ off: n > (item.star_level || 4) }"
							>★</text>
						</view>
						<text class="level-text">{{ item.position_level_label || '初级' }}</text>
					</view>
					<view class="therapist-stats">
						<text class="stat-orders">接单{{ item.order_count || 0 }}</text>
						<text class="stat-followers">关注{{ item.follow_count || 0 }}</text>
					</view>
				</view>
				<view class="card-actions">
					<view class="action-btn btn-primary" @click="goBooking(item)">立即预约</view>
				</view>
			</view>
			<view class="pb-safe"></view>
		</scroll-view>
		<view :style="{ height: showBar ? `${pdHeight * 2 + 96}rpx` : '0' }"></view>
		<view class="safe-area-inset-bottom" v-if="showBar"></view>
		<pageFooter @newDataStatus="newDataStatus"></pageFooter>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue';
import emptyPage from '@/components/emptyPage.vue';
import pageFooter from '@/components/pageFooter/index.vue';
import colors from '@/mixins/color';
import { HTTP_REQUEST_URL } from '@/config/app.js';
import { getReservationStaffList, getStoreServiceTimeSlots } from '@/api/activity.js';
import { getList } from '@/api/new_store.js';
import { initData } from '@/utils/chooseTimeDate.js';

export default {
	name: 'TherapistList',
	components: { NavBar, emptyPage, pageFooter },
	mixins: [colors],
	data() {
		return {
			iconColor: '#333',
			bagColor: '#fff',
			storeId: 0,
			storeName: '',
			keyword: '',
			positionIds: [],
			staffList: [],
			staffListRaw: [],
			sortType: 'smart',
			sortPanelVisible: false,
			sortOptions: [
				{ label: '智能排序', value: 'smart' },
				{ label: '评价最高', value: 'rating' },
				{ label: '按单量优先', value: 'orders' },
			],
			timePanelVisible: false,
			timeSlotsLoading: false,
			dateTabs: [],
			pendingDateIndex: 0,
			pendingDate: '',
			pendingTime: '',
			serviceDate: '',
			serviceTime: '',
			serviceDuration: 120,
			timeSlots: [],
			advancedPanelVisible: false,
			advancedFilters: {
				serviceStatus: '',
				sex: '',
				ageCate: '',
				minAge: '',
				maxAge: '',
			},
			pendingAdvancedFilters: {
				serviceStatus: '',
				sex: '',
				ageCate: '',
				minAge: '',
				maxAge: '',
			},
			serviceStatusOptions: [
				{ label: '可服务', value: '1' },
				{ label: '忙碌中', value: '0' },
			],
			sexOptions: [
				{ label: '保密', value: '0' },
				{ label: '男美容/芳疗师', value: '1' },
				{ label: '女美容/芳疗师', value: '2' },
			],
			ageCateOptions: [
				{ label: '80后', value: '80' },
				{ label: '90后', value: '90' },
			],
			loading: false,
			defaultAvatar: `${HTTP_REQUEST_URL}/statics/images/f.png`,
			showBar: false,
			pdHeight: 0,
		};
	},
	computed: {
		contentStyle() {
			const footer = this.showBar ? this.pdHeight * 2 + 96 : 0;
			return {
				height: `calc(100vh - 280rpx - ${footer}rpx)`,
			};
		},
		sortLabel() {
			const current = this.sortOptions.find((item) => item.value === this.sortType);
			return current ? current.label : '智能排序';
		},
		hasTimeFilter() {
			return !!(this.serviceDate && this.serviceTime);
		},
		timeFilterLabel() {
			if (!this.hasTimeFilter) return '服务时段';
			return this.formatClock(this.serviceTime);
		},
		visibleTimeSlots() {
			return (this.timeSlots || []).filter((slot) => !slot.is_past);
		},
		hasAdvancedFilter() {
			const f = this.advancedFilters;
			return !!(
				f.serviceStatus !== '' ||
				f.sex !== '' ||
				f.ageCate !== '' ||
				f.minAge !== '' ||
				f.maxAge !== ''
			);
		},
	},
	onLoad(options) {
		this.dateTabs = initData();
		this.pendingDate = this.dateTabs[0]?.date || this.formatToday();
		this.storeId = Number(options.store_id || uni.getStorageSync('user_store_id') || 0);
		this.positionIds = String(options.position_ids || '')
			.split(',')
			.map(Number)
			.filter((id, index, ids) => id > 0 && ids.indexOf(id) === index);
		this.initStoreInfo();
		this.loadStaffList();
		uni.$on('activeReservation', this.onStoreSelected);
	},
	onUnload() {
		uni.$off('activeReservation', this.onStoreSelected);
	},
	methods: {
		initStoreInfo() {
			if (!this.storeId) {
				this.loadDefaultStore();
				return;
			}
			getList({
				latitude: uni.getStorageSync('user_latitude') || '',
				longitude: uni.getStorageSync('user_longitude') || '',
				store_type: 1,
				keyword: '',
				province: 0,
				city: 0,
				area: 0,
			}).then((res) => {
				const list = res.data || [];
				const current = list.find((item) => Number(item.id) === Number(this.storeId));
				if (current) {
					this.storeName = current.name;
				}
			}).catch(() => {});
		},
		loadDefaultStore() {
			getList({
				latitude: uni.getStorageSync('user_latitude') || '',
				longitude: uni.getStorageSync('user_longitude') || '',
				store_type: 1,
				keyword: '',
				province: 0,
				city: 0,
				area: 0,
			}).then((res) => {
				const list = res.data || [];
				if (!list.length) return;
				this.storeId = Number(list[0].id);
				this.storeName = list[0].name;
				this.loadStaffList();
			}).catch(() => {});
		},
		loadStaffList() {
			if (!this.storeId) return;
			this.loading = true;
			const params = {
				store_id: this.storeId,
				keyword: this.keyword,
				position_ids: this.positionIds.join(','),
				service_date: this.serviceDate || this.formatToday(),
				service_duration: this.serviceDuration,
			};
			if (this.serviceTime) {
				params.service_time = this.formatClock(this.serviceTime);
			}
			getReservationStaffList(params).then((res) => {
				this.staffListRaw = res.data?.list || res.data || [];
				this.applyFiltersToList();
			}).catch((err) => {
				this.staffListRaw = [];
				this.staffList = [];
				this.$util.Tips({ title: err || '加载失败' });
			}).finally(() => {
				this.loading = false;
			});
		},
		toggleSortPanel() {
			if (this.timePanelVisible) {
				this.timePanelVisible = false;
			}
			if (this.advancedPanelVisible) {
				this.advancedPanelVisible = false;
			}
			this.sortPanelVisible = !this.sortPanelVisible;
		},
		toggleTimePanel() {
			if (this.sortPanelVisible) {
				this.sortPanelVisible = false;
			}
			if (this.advancedPanelVisible) {
				this.advancedPanelVisible = false;
			}
			if (!this.timePanelVisible) {
				this.openTimePanel();
				return;
			}
			this.timePanelVisible = false;
		},
		toggleAdvancedPanel() {
			if (this.sortPanelVisible) {
				this.sortPanelVisible = false;
			}
			if (this.timePanelVisible) {
				this.timePanelVisible = false;
			}
			if (!this.advancedPanelVisible) {
				this.pendingAdvancedFilters = { ...this.advancedFilters };
				this.advancedPanelVisible = true;
				return;
			}
			this.advancedPanelVisible = false;
		},
		openTimePanel() {
			if (!this.storeId) {
				return this.$util.Tips({ title: '请先选择门店' });
			}
			this.pendingDate = this.serviceDate || this.dateTabs[0]?.date || this.formatToday();
			this.pendingTime = this.serviceTime || '';
			this.pendingDateIndex = Math.max(0, this.dateTabs.findIndex((item) => item.date === this.pendingDate));
			this.timePanelVisible = true;
			this.loadTimeSlots();
		},
		closeAllPanels() {
			this.sortPanelVisible = false;
			this.timePanelVisible = false;
			this.advancedPanelVisible = false;
		},
		closeSortPanel() {
			this.sortPanelVisible = false;
		},
		selectPendingDate(index, date) {
			this.pendingDateIndex = index;
			this.pendingDate = date;
			this.pendingTime = '';
			this.loadTimeSlots();
		},
		loadTimeSlots() {
			if (!this.storeId || !this.pendingDate) return;
			this.timeSlotsLoading = true;
			getStoreServiceTimeSlots({
				store_id: this.storeId,
				service_date: this.pendingDate,
				service_duration: this.serviceDuration,
			}).then((res) => {
				const data = res.data || {};
				this.timeSlots = data.time_slots || [];
			}).catch(() => {
				this.timeSlots = [];
			}).finally(() => {
				this.timeSlotsLoading = false;
			});
		},
		selectPendingTime(slot) {
			if (!slot || !slot.available) return;
			this.pendingTime = slot.time;
		},
		resetTimeFilter() {
			this.pendingTime = '';
			this.serviceDate = '';
			this.serviceTime = '';
			this.timePanelVisible = false;
			this.loadStaffList();
		},
		confirmTimeFilter() {
			if (!this.pendingTime) {
				return this.$util.Tips({ title: '请选择可预约时段' });
			}
			this.serviceDate = this.pendingDate;
			this.serviceTime = this.pendingTime;
			this.timePanelVisible = false;
			this.loadStaffList();
		},
		formatClock(value) {
			if (!value) return '';
			const part = String(value).includes(' ') ? String(value).split(' ')[1] : String(value);
			return part.substring(0, 5);
		},
		selectSort(value) {
			this.sortType = value;
			this.sortPanelVisible = false;
			this.applyFiltersToList();
		},
		selectAdvancedOption(field, value) {
			if (this.pendingAdvancedFilters[field] === value) {
				this.pendingAdvancedFilters[field] = '';
				return;
			}
			this.pendingAdvancedFilters[field] = value;
			if (field === 'ageCate') {
				this.pendingAdvancedFilters.minAge = '';
				this.pendingAdvancedFilters.maxAge = '';
			}
		},
		clearAgeCate() {
			this.pendingAdvancedFilters.ageCate = '';
		},
		resetAdvancedFilter() {
			this.pendingAdvancedFilters = {
				serviceStatus: '',
				sex: '',
				ageCate: '',
				minAge: '',
				maxAge: '',
			};
			this.advancedFilters = { ...this.pendingAdvancedFilters };
			this.advancedPanelVisible = false;
			this.applyFiltersToList();
		},
		confirmAdvancedFilter() {
			this.advancedFilters = { ...this.pendingAdvancedFilters };
			this.advancedPanelVisible = false;
			this.applyFiltersToList();
		},
		getStaffBirthYear(staff) {
			if (staff.birthday_date) {
				const year = parseInt(String(staff.birthday_date).substring(0, 4), 10);
				if (year) return year;
			}
			if (staff.age) {
				return new Date().getFullYear() - Number(staff.age);
			}
			return 0;
		},
		getStaffAge(staff) {
			if (staff.age) return Number(staff.age);
			const year = this.getStaffBirthYear(staff);
			return year ? new Date().getFullYear() - year : 0;
		},
		matchAgeCate(staff, cate) {
			const year = this.getStaffBirthYear(staff);
			if (!year) return false;
			if (cate === '80') return year >= 1980 && year <= 1989;
			if (cate === '90') return year >= 1990 && year <= 1999;
			return true;
		},
		matchAgeRange(staff, minAge, maxAge) {
			const age = this.getStaffAge(staff);
			if (!age) return false;
			if (minAge !== '' && age < Number(minAge)) return false;
			if (maxAge !== '' && age > Number(maxAge)) return false;
			return true;
		},
		applyFiltersToList() {
			let list = [...(this.staffListRaw || [])];
			list = list.filter((item) => this.isSelectableStaff(item));
			const f = this.advancedFilters;
			if (f.serviceStatus !== '') {
				list = list.filter((item) => Number(item.service_available) === Number(f.serviceStatus));
			}
			if (f.sex !== '') {
				list = list.filter((item) => Number(item.sex ?? 0) === Number(f.sex));
			}
			if (f.ageCate) {
				list = list.filter((item) => this.matchAgeCate(item, f.ageCate));
			} else if (f.minAge !== '' || f.maxAge !== '') {
				list = list.filter((item) => this.matchAgeRange(item, f.minAge, f.maxAge));
			}
			if (this.sortType === 'rating') {
				list.sort((a, b) => Number(b.star_level || 0) - Number(a.star_level || 0));
			} else if (this.sortType === 'orders') {
				list.sort((a, b) => Number(b.order_count || 0) - Number(a.order_count || 0));
			}
			this.staffList = list;
		},
		isSelectableStaff(item) {
			if (!item || !Number(item.id)) return false;
			if (!String(item.staff_name || '').trim()) return false;
			if (this.serviceTime && Number(item.service_available) === 0) return false;
			return true;
		},
		formatToday() {
			const d = new Date();
			const y = d.getFullYear();
			const m = String(d.getMonth() + 1).padStart(2, '0');
			const day = String(d.getDate()).padStart(2, '0');
			return `${y}-${m}-${day}`;
		},
		openStorePicker() {
			uni.navigateTo({
				url: `/pages/store/list/index?type=1&isCollage=3&storeId=${this.storeId || 0}`,
			});
		},
		onStoreSelected(row) {
			if (!row || !row.id) return;
			this.storeId = Number(row.id);
			this.storeName = row.name || '';
			uni.setStorageSync('user_store_id', this.storeId);
			this.serviceDate = '';
			this.serviceTime = '';
			this.pendingTime = '';
			this.advancedFilters = {
				serviceStatus: '',
				sex: '',
				ageCate: '',
				minAge: '',
				maxAge: '',
			};
			this.pendingAdvancedFilters = { ...this.advancedFilters };
			this.closeAllPanels();
			this.loadStaffList();
		},
		saveSelectedTeacher(staff) {
			if (!staff || !staff.id) return;
			uni.setStorageSync('selected_teacher', {
				id: staff.id,
				staff_name: staff.staff_name,
				position_label: staff.position_label || '',
				position: staff.position || 0,
				position_level: staff.position_level || 0,
				position_level_label: staff.position_level_label || '',
				store_id: this.storeId,
				store_name: this.storeName,
			});
		},
		goBooking(staff) {
			this.saveSelectedTeacher(staff);
			uni.navigateTo({
				url: '/pages/users/user_card_list/index',
			});
		},
		goBack() {
			uni.navigateBack({ delta: 1 });
		},
		newDataStatus(val, num) {
			this.showBar = !!val;
			this.pdHeight = num || 0;
		},
	},
};
</script>

<style scoped lang="scss">
.therapist-page {
	min-height: 100vh;
	background: #fbf7f4;
	--page-theme: var(--view-theme, #7b2941);
	--page-theme-light: var(--view-minorColorT, #f9e9ed);
	--page-theme-bg: var(--view-minorColorT, rgba(123, 41, 65, 0.07));
}

.header {
	background: rgba(255,253,251,.98);
	padding: 12rpx 24rpx 24rpx;
	border-bottom: 1rpx solid rgba(123,41,65,.08);
}

.h5-nav {
	position: relative;
	height: 88rpx;
	background: rgba(255,253,251,.98);
}

.h5-nav .icon-ic_leftarrow {
	position: absolute;
	left: 24rpx;
	font-size: 36rpx;
	color: #4d3037;
}

.h5-nav-title {
	font-size: 34rpx;
	font-weight: 500;
	color: #4d3037;
}

.header-title {
	text-align: center;
	font-size: 34rpx;
	font-weight: 500;
	color: #333;
	margin-bottom: 24rpx;
}

.search-bar {
	display: flex;
	align-items: center;
	gap: 16rpx;
}

.location-btn {
	display: flex;
	align-items: center;
	max-width: 220rpx;
	font-size: 28rpx;
	color: #333;
}

.location-icon {
	font-size: 32rpx;
	color: var(--page-theme);
	margin-right: 6rpx;
	flex-shrink: 0;
}

.selected-store {
	color: var(--page-theme);
	font-weight: 600;
	max-width: 140rpx;
}

.dropdown-icon {
	font-size: 22rpx;
	color: #999;
	margin-left: 4rpx;
	flex-shrink: 0;
}

.search-input {
	flex: 1;
	display: flex;
	align-items: center;
	background: #f4ece9;
	border: 1rpx solid rgba(123,41,65,.08);
	border-radius: 40rpx;
	padding: 12rpx 24rpx;
}

.search-icon {
	font-size: 28rpx;
	color: #999;
	margin-right: 12rpx;
}

.search-input input {
	flex: 1;
	font-size: 28rpx;
	color: #4d3037;
}

.filter-bar {
	display: flex;
	justify-content: space-around;
	padding: 24rpx 0;
	background: rgba(255,253,251,.98);
	border-bottom: 1rpx solid rgba(123,41,65,.08);
}

.filter-item {
	display: flex;
	align-items: center;
	gap: 6rpx;
	font-size: 28rpx;
	color: #666;
}

.filter-item.active {
	color: var(--page-theme);
	font-weight: 500;
}

.filter-arrow {
	font-size: 20rpx;
}

.sort-mask {
	position: fixed;
	left: 0;
	right: 0;
	top: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.35);
	z-index: 20;
}

.sort-panel {
	position: relative;
	z-index: 21;
	background: #fff;
	padding: 8rpx 0 16rpx;
}

.sort-option {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 28rpx 32rpx;
}

.sort-option-label {
	font-size: 30rpx;
	color: #333;
}

.sort-option-check {
	width: 40rpx;
	height: 40rpx;
	border-radius: 50%;
	border: 2rpx solid #ddd;
	display: flex;
	align-items: center;
	justify-content: center;
}

.sort-option-check.checked {
	border-color: var(--page-theme);
	background: var(--page-theme);
}

.sort-option-check .iconfont {
	font-size: 22rpx;
	color: #fff;
	line-height: 1;
}

.time-panel {
	position: relative;
	z-index: 21;
	background: #fff;
}

.time-date-scroll {
	white-space: nowrap;
	padding: 16rpx 0 0;
	border-bottom: 1rpx solid #f0f0f0;
}

.time-date-item {
	display: inline-flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	min-width: 160rpx;
	padding: 16rpx 24rpx 20rpx;
	color: #666;
}

.time-date-item.active {
	color: var(--page-theme);
	border-bottom: 4rpx solid var(--page-theme);
}

.time-date-title {
	font-size: 28rpx;
	font-weight: 600;
}

.time-date-week {
	font-size: 24rpx;
	margin-top: 6rpx;
}

.time-slots-loading,
.time-slots-empty {
	text-align: center;
	padding: 60rpx 0;
	color: #999;
	font-size: 28rpx;
}

.time-slots-scroll {
	max-height: 520rpx;
}

.time-slots-grid {
	display: flex;
	flex-wrap: wrap;
	padding: 24rpx 16rpx 8rpx;
}

.time-slots-grid .time-slot {
	width: 25%;
	padding: 8rpx;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	min-height: 120rpx;
	border: 1rpx solid #eee;
	border-radius: 12rpx;
	background: #fff;
}

.time-slot.disabled {
	background: #f9f9f9;
	border-color: #eee;
}

.time-slot.selected {
	border-color: var(--page-theme);
	background: var(--page-theme-bg);
}

.time-slot.selected .slot-time,
.time-slot.selected .slot-status {
	color: var(--page-theme);
}

.slot-time {
	font-size: 26rpx;
	font-weight: 600;
	color: #333;
	margin-bottom: 8rpx;
}

.time-slot.disabled .slot-time {
	color: #999;
}

.slot-status {
	font-size: 22rpx;
	color: var(--page-theme);
}

.time-slot.disabled .slot-status {
	color: #bbb;
}

.time-panel-footer {
	display: flex;
	gap: 24rpx;
	padding: 24rpx;
	border-top: 1rpx solid #f0f0f0;
}

.time-footer-btn {
	flex: 1;
	height: 80rpx;
	line-height: 80rpx;
	text-align: center;
	border-radius: 12rpx;
	font-size: 30rpx;
}

.time-footer-btn.reset {
	background: #f0f0f0;
	color: #666;
}

.time-footer-btn.confirm {
	background: var(--page-theme);
	color: #fff;
}

.advanced-panel {
	position: relative;
	z-index: 21;
	background: #fff;
}

.advanced-scroll {
	max-height: 640rpx;
}

.filter-section {
	padding: 32rpx 32rpx 8rpx;
}

.filter-section-title {
	font-size: 30rpx;
	font-weight: 600;
	color: #333;
	margin-bottom: 24rpx;
}

.filter-options {
	display: flex;
	flex-wrap: wrap;
	gap: 20rpx 24rpx;
}

.filter-chip {
	min-width: 200rpx;
	padding: 0 28rpx;
	height: 60rpx;
	line-height: 60rpx;
	text-align: center;
	border-radius: 60rpx;
	background: #f5f5f5;
	color: #666;
	font-size: 26rpx;
}

.filter-chip.active {
	background: var(--page-theme-light);
	color: var(--page-theme);
}

.filter-age-input {
	width: 300rpx;
	height: 60rpx;
	line-height: 60rpx;
	border-radius: 60rpx;
	background: #f5f5f5;
	text-align: center;
}

.filter-age-input input {
	width: 100%;
	height: 60rpx;
	line-height: 60rpx;
	font-size: 26rpx;
	color: #333;
}

.content {
	padding: 0 24rpx;
}

.safe-area-inset-bottom {
	height: constant(safe-area-inset-bottom);
	height: env(safe-area-inset-bottom);
}

.loading-tip {
	text-align: center;
	padding: 80rpx 0;
	color: #999;
	font-size: 28rpx;
}

.therapist-card {
	display: flex;
	background: #fffdfb;
	margin-top: 24rpx;
	padding: 24rpx;
	border: 1rpx solid rgba(123,41,65,.08);
	border-radius: 28rpx;
	box-shadow: 0 12rpx 28rpx rgba(83,45,54,.07);
}

.therapist-avatar {
	width: 140rpx;
	height: 140rpx;
	border-radius: 16rpx;
	margin-right: 20rpx;
	background: #f2e7e4;
	flex-shrink: 0;
}

.therapist-avatar--placeholder {
	display: flex;
	align-items: center;
	justify-content: center;
	color: #9c5869;
}

.therapist-avatar--placeholder .iconfont {
	font-size: 58rpx;
}

.therapist-content {
	flex: 1;
	min-width: 0;
}

.therapist-header {
	display: flex;
	align-items: center;
	gap: 8rpx;
	margin-bottom: 8rpx;
}

.therapist-name {
	font-size: 32rpx;
	font-weight: 600;
	color: #4d3037;
}

.therapist-type {
	font-size: 24rpx;
	color: #9a7b82;
}

.therapist-level {
	display: flex;
	align-items: center;
	gap: 8rpx;
	margin-bottom: 12rpx;
}

.stars {
	display: flex;
	gap: 2rpx;
}

.star {
	color: #ffb800;
	font-size: 24rpx;
}

.star.off {
	color: #ddd;
}

.level-text {
	font-size: 24rpx;
	color: #81666d;
}

.therapist-stats {
	display: flex;
	align-items: center;
	gap: 20rpx;
	font-size: 24rpx;
}

.stat-orders {
	color: var(--page-theme);
}

.stat-followers {
	color: #666;
	position: relative;
	padding-left: 20rpx;
}

.stat-followers::before {
	content: '';
	position: absolute;
	left: 0;
	top: 50%;
	transform: translateY(-50%);
	width: 1rpx;
	height: 20rpx;
	background: #ddd;
}

.card-actions {
	display: flex;
	flex-direction: column;
	justify-content: center;
	gap: 12rpx;
	margin-left: 12rpx;
	flex-shrink: 0;
}

.action-btn {
	border-radius: 20rpx;
	font-size: 26rpx;
	font-weight: 600;
	text-align: center;
}

.btn-primary {
	background: linear-gradient(135deg, var(--view-gradient, #a45b6d) 0%, var(--view-theme, #7b2941) 100%);
	color: #fff;
	box-shadow: 0 8rpx 20rpx rgba(123,41,65,0.25);
	width: 220rpx;
	padding: 16rpx 0;
}

.pb-safe {
	height: calc(40rpx + env(safe-area-inset-bottom));
}
</style>
