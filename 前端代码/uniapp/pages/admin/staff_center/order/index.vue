<template>
	<view class="teacher-order-page" :style="colorStyle">
		<view class="header-box" :style="{ paddingTop: sysHeight + 'px' }">
			<view class="nav-bar acea-row row-between-wrapper">
				<text class="iconfont icon-ic_leftarrow back-icon" @click="goBack"></text>
				<text class="nav-title">老师订单</text>
				<view class="date-btn" @click="openCalendar">{{ dateLabel }}</view>
			</view>
		</view>

		<view class="status-nav acea-row row-around">
			<view
				v-for="item in navList"
				:key="item.type"
				class="status-item"
				:class="{ on: orderStatus === item.type }"
				@click="statusClick(item.type)"
			>
				<view>{{ item.name }}</view>
				<view class="num">{{ orderData[item.countKey] || 0 }}</view>
			</view>
		</view>

		<view class="list-wrap">
			<view class="order-card" v-for="(item, index) in orderList" :key="item.id || index" @click="goDetail(item)">
				<view class="service-time">服务时间：{{ item.appointment_time || formatTime(item) }}</view>
				<view class="info-line store-line acea-row row-between-wrapper" v-if="item.storeName || storeName">
					<view>服务地点：{{ item.storeName || storeName }}</view>
					<view class="nav-link" v-if="getStoreInfo(item).latitude" @click.stop="openNavigation(item)">
						导航 <text class="iconfont icon-ic_rightarrow"></text>
					</view>
				</view>
				<view class="info-line">客户姓名：{{ displayCustomer(item) }}</view>
				<view class="info-line" v-if="item.service_room">服务房间：{{ item.service_room }}</view>
				<view class="info-line" v-if="item.staff_name">服务老师：{{ item.staff_name }}</view>
				<view class="info-line" v-if="item.service_duration_text">服务时长：{{ item.service_duration_text }}</view>
				<view class="info-line tag-line acea-row row-middle" v-if="item.work_status == 1">
					<text>标签：</text>
					<view class="action-btn outline primary tag-set-btn" @click.stop="openTagPicker(item)">设置标签</view>
				</view>

				<view class="project-list">
					<view class="project-item" v-for="(project, pIndex) in getProjectList(item)" :key="pIndex">
						<image class="project-img" :src="project.image" mode="aspectFill"></image>
						<view class="project-body flex-1">
							<view class="acea-row row-between-wrapper">
								<view class="project-name line2">
									<text class="addon-tag" v-if="project.is_addon">[增项]</text>{{ project.product_name }}
								</view>
								<view class="project-tag">{{ project.item_tag || item.item_status_tag || '待核销' }}</view>
							</view>
							<view class="project-desc line1" v-if="project.desc">{{ project.desc }}</view>
							<view class="project-num">×{{ project.cart_num || 1 }}</view>
						</view>
					</view>
				</view>

				<view class="remark-box" v-if="item.mark">服务备注：{{ item.mark }}</view>

				<view class="action-row">
					<view
						class="action-btn outline primary"
						v-if="item.status == 0 && item.work_status == 0"
						@click.stop="startWork(item, index)"
					>开始服务</view>
					<view class="countdown-box" v-if="item.status == 1 && countdownList[index]">
						距离结束：
						<text class="countdown-text">
							{{ padTime(countdownList[index].h) }}时:{{ padTime(countdownList[index].m) }}分:{{ padTime(countdownList[index].s) }}秒
						</text>
					</view>
					<view class="countdown-box" v-if="item.work_status == 2 && item.status == 2">已结束操作</view>
					<view class="action-btn refuse" v-if="item.master_phone" @click.stop="callPhone(item.master_phone)">拨打店长</view>
				</view>
			</view>
			<emptyPage v-if="!orderList.length && !loading" title="暂无订单"></emptyPage>
		</view>

		<view class="tag-mask" v-if="tagVisible" @click="closeTagPicker"></view>
		<view class="tag-panel" v-if="tagVisible">
			<view class="tag-panel-title">设置订单标签</view>
			<view class="tag-options">
				<view
					v-for="tag in tagList"
					:key="tag.id"
					class="tag-option"
					:class="{ on: tagForm.tagList.includes(tag.label_name) }"
					@click="toggleTag(tag.label_name)"
				>{{ tag.label_name }}</view>
			</view>
			<view class="tag-panel-actions acea-row row-center-wrapper">
				<view class="tag-btn confirm" @click="submitTags">确定</view>
				<view class="tag-btn cancel" @click="closeTagPicker">取消</view>
			</view>
		</view>

		<uni-calendar :insert="false" ref="calendar" @confirm="changeDate" />
		<view class="footer-space"></view>
		<staffCenterFooter />
	</view>
</template>

<script>
import staffCenterFooter from '../../components/staffCenterFooter/index.vue';
import uniCalendar from '@/components/uni-calendar/uni-calendar.vue';
import emptyPage from '@/components/emptyPage.vue';
import { storeReservationList, storeReservationStatistics, reservationServiceSet, storeReservationServiceTagList, storeReservationSetServiceTag } from '@/api/store.js';
import { staffTeacherCenter } from '@/api/store.js';
import { openYuyueSubscribe } from '@/utils/SubscribeMessage.js';
import { toLogin } from '@/libs/login.js';
import { mapGetters } from 'vuex';
import colors from '@/mixins/color.js';

const FIVE_MIN_AUDIO = 'https://myqiniu.cc3798.com/video/five_work.wav';
const END_AUDIO = 'https://myqiniu.cc3798.com/video/down_work.wav';

export default {
	components: { staffCenterFooter, uniCalendar, emptyPage },
	mixins: [colors],
	data() {
		return {
			sysHeight: uni.getSystemInfoSync().statusBarHeight,
			navList: [
				{ name: '待服务', type: 0, countKey: 'pending_service' },
				{ name: '待评价', type: 1, countKey: 'pending_evaluate' },
				{ name: '已完成', type: 2, countKey: 'completed' }
			],
			orderData: {},
			orderList: [],
			orderStatus: 0,
			page: 1,
			limit: 20,
			loading: false,
			loadend: false,
			filterDate: '',
			dateLabel: '筛选日期',
			storeName: '',
			countdownList: [],
			countdownTimers: [],
			audioContext: null,
			fiveMinPlayed: {},
			tagVisible: false,
			tagList: [],
			tagForm: {
				id: 0,
				tagList: [],
				other_tag: ''
			}
		};
	},
	computed: {
		...mapGetters(['isLogin'])
	},
	onShow() {
		if (!this.isLogin) return toLogin();
		this.reload();
		this.loadTagOptions();
		staffTeacherCenter().then(res => {
			this.storeName = res.data?.store_name || '';
		});
	},
	onLoad(options) {
		if (options.status !== undefined && options.status !== '') {
			this.orderStatus = Number(options.status) || 0;
		}
	},
	onHide() {
		this.clearCountdownTimers();
	},
	onUnload() {
		this.clearCountdownTimers();
		if (this.audioContext) {
			this.audioContext.destroy();
			this.audioContext = null;
		}
	},
	onReachBottom() {
		this.getOrderList();
	},
	onPullDownRefresh() {
		this.reload().finally(() => uni.stopPullDownRefresh());
	},
	methods: {
		reload() {
			this.clearCountdownTimers();
			this.page = 1;
			this.loadend = false;
			this.orderList = [];
			this.countdownList = [];
			this.fiveMinPlayed = {};
			return Promise.all([this.getStatistics(), this.getOrderList()]);
		},
		getStatistics() {
			return storeReservationStatistics({ date: this.filterDate, teacher_mode: 1 }).then(res => {
				this.orderData = res.data || {};
			}).catch(() => {});
		},
		getOrderList() {
			if (this.loadend || this.loading) return Promise.resolve();
			this.loading = true;
			return storeReservationList({
				status: this.orderStatus,
				page: this.page,
				limit: this.limit,
				date: this.filterDate,
				teacher_mode: 1
			}).then(res => {
				const list = res.data || [];
				this.orderList = this.$util.SplitArray(list, this.orderList);
				this.loadend = list.length < this.limit;
				this.page += 1;
				this.loading = false;
				this.initCountdowns();
			}).catch(() => {
				this.loading = false;
			});
		},
		initCountdowns() {
			this.clearCountdownTimers();
			this.orderList.forEach((item, index) => {
				if (item.status == 1 && item.service_end_date) {
					this.startCountdown(index, item.service_end_date);
				}
			});
		},
		startCountdown(index, endDate) {
			const tick = () => {
				const remain = this.calcRemain(endDate);
				this.$set(this.countdownList, index, remain);
				if (remain.h === 0 && remain.m === 5 && remain.s === 0 && !this.fiveMinPlayed[index]) {
					this.fiveMinPlayed[index] = true;
					this.playAudio(FIVE_MIN_AUDIO);
				}
				if (remain.h === 0 && remain.m === 0 && remain.s === 0) {
					this.playAudio(END_AUDIO);
					if (this.orderList[index]) {
						this.$set(this.orderList[index], 'work_status', 2);
					}
					this.clearTimer(index);
				}
			};
			tick();
			this.countdownTimers[index] = setInterval(tick, 1000);
		},
		calcRemain(endDate) {
			let end = new Date(endDate);
			if (String(end) === 'Invalid Date') {
				end = new Date(String(endDate).replace(/-/g, '/'));
			}
			let diff = Math.max(0, end.getTime() - Date.now());
			const h = Math.floor(diff / 3600000);
			diff -= h * 3600000;
			const m = Math.floor(diff / 60000);
			diff -= m * 60000;
			const s = Math.floor(diff / 1000);
			return { h, m, s };
		},
		padTime(val) {
			return val < 10 ? '0' + val : String(val);
		},
		clearTimer(index) {
			if (this.countdownTimers[index]) {
				clearInterval(this.countdownTimers[index]);
				delete this.countdownTimers[index];
			}
		},
		clearCountdownTimers() {
			Object.keys(this.countdownTimers).forEach(key => {
				clearInterval(this.countdownTimers[key]);
			});
			this.countdownTimers = [];
		},
		playAudio(src) {
			if (!this.audioContext) {
				this.audioContext = uni.createInnerAudioContext();
			}
			this.audioContext.src = src;
			this.audioContext.play();
		},
		statusClick(status) {
			if (this.loading || status === this.orderStatus) return;
			// #ifdef MP
			if (!this._yuyueSubscribed) {
				this._yuyueSubscribed = true;
				openYuyueSubscribe();
			}
			// #endif
			this.orderStatus = status;
			this.reload();
		},
		openCalendar() {
			this.$refs.calendar.open();
		},
		changeDate(e) {
			this.filterDate = e.fulldate || '';
			this.dateLabel = this.filterDate || '筛选日期';
			this.reload();
		},
		displayCustomer(item) {
			const name = item.customer_name || item.reservation_name || '—';
			const sex = item.customer_sex ? `（${item.customer_sex}）` : '';
			return `${name}${sex}`;
		},
		displayTags(item) {
			const tags = (item.tags_attr && item.tags_attr.length) ? item.tags_attr.join(',') : (item.tags || '');
			const other = item.other_tag || '';
			if (tags && other) return `${tags},${other}`;
			return tags || other || '';
		},
		loadTagOptions() {
			storeReservationServiceTagList().then(res => {
				this.tagList = res.data || [];
			}).catch(() => {
				this.tagList = [];
			});
		},
		openTagPicker(item) {
			this.tagForm = {
				id: item.id,
				tagList: [...(item.tags_attr || [])],
				other_tag: item.other_tag || ''
			};
			this.tagVisible = true;
		},
		closeTagPicker() {
			this.tagVisible = false;
		},
		toggleTag(name) {
			const idx = this.tagForm.tagList.indexOf(name);
			if (idx > -1) {
				this.tagForm.tagList.splice(idx, 1);
			} else {
				this.tagForm.tagList.push(name);
			}
		},
		submitTags() {
			storeReservationSetServiceTag(this.tagForm.id, {
				tagList: this.tagForm.tagList,
				other_tag: this.tagForm.other_tag
			}).then(() => {
				this.$util.Tips({ title: '设置成功' });
				this.tagVisible = false;
				this.reload();
			}).catch(err => {
				this.$util.Tips({ title: err.msg || err || '设置失败' });
			});
		},
		goDetail(item) {
			uni.navigateTo({
				url: `/pages/admin/reservation_details/index?id=${item.id}&from=teacher`
			});
		},
		formatTime(item) {
			if (!item.reservation_time) return '—';
			return `${item.reservation_time} ${item.reservation_start || ''}`;
		},
		getProjectList(item) {
			if (item.project_list && item.project_list.length) return item.project_list;
			if (item.cart_info && item.cart_info.productInfo) {
				return [{
					product_name: item.cart_info.productInfo.store_name,
					image: item.cart_info.productInfo.image,
					cart_num: item.cart_info.cart_num || 1
				}];
			}
			return [];
		},
		getStoreInfo(item) {
			return item.store_info || {};
		},
		openNavigation(item) {
			const store = this.getStoreInfo(item);
			if (!store.latitude || !store.longitude) return;
			uni.openLocation({
				latitude: Number(store.latitude),
				longitude: Number(store.longitude),
				name: store.name || item.storeName || '',
				address: store.address || ''
			});
		},
		callPhone(phone) {
			if (!phone) return;
			uni.makePhoneCall({ phoneNumber: String(phone) });
		},
		startWork(item, index) {
			uni.showModal({
				title: '提示',
				content: '确认开始服务后代表服务开始进行，是否确认？',
				confirmColor: '#07CD9A',
				success: (res) => {
					if (!res.confirm) return;
					// #ifdef MP
					openYuyueSubscribe();
					// #endif
					reservationServiceSet(item.id, { status: 1 }).then(result => {
						this.$util.Tips({ title: '操作成功！' });
						const endDate = result.data?.endDate || item.service_end_date;
						this.$set(this.orderList[index], 'status', 1);
						this.$set(this.orderList[index], 'work_status', 1);
						this.$set(this.orderList[index], 'item_status_tag', '服务中');
						if (endDate) {
							this.$set(this.orderList[index], 'service_end_date', endDate);
							this.startCountdown(index, endDate);
						}
						this.getStatistics();
					}).catch(err => {
						this.$util.Tips({ title: err.msg || err || '操作失败' });
					});
				}
			});
		},
		goBack() {
			const pages = getCurrentPages();
			if (pages.length > 1) uni.navigateBack();
			else uni.redirectTo({ url: '/pages/admin/staff_center/index' });
		}
	}
};
</script>

<style scoped lang="scss">
.teacher-order-page {
	min-height: 100vh;
	background: #f5f5f5;
}
.header-box {
	background: linear-gradient(180deg, #07cd9a 0%, #06b888 100%);
	padding: 0 30rpx 40rpx;
}
.nav-bar {
	height: 88rpx;
	color: #fff;
}
.back-icon {
	font-size: 40rpx;
	width: 60rpx;
}
.nav-title {
	font-size: 34rpx;
	font-weight: 600;
	flex: 1;
	text-align: center;
}
.date-btn {
	padding: 0 20rpx;
	height: 52rpx;
	line-height: 52rpx;
	border-radius: 26rpx;
	background: rgba(255, 255, 255, 0.2);
	font-size: 24rpx;
}
.status-nav {
	width: 690rpx;
	margin: -20rpx auto 0;
	background: #fff;
	border-radius: 12rpx;
	box-shadow: 0 8rpx 24rpx rgba(0, 0, 0, 0.04);
}
.status-item {
	text-align: center;
	font-size: 26rpx;
	padding: 24rpx 0;
	border-bottom: 5rpx solid transparent;
	flex: 1;
}
.status-item.on {
	font-weight: 600;
	border-color: #07cd9a;
}
.status-item .num {
	margin-top: 8rpx;
}
.list-wrap {
	width: 690rpx;
	margin: 20rpx auto 0;
}
.order-card {
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 20rpx;
}
.service-time {
	color: #333;
	font-size: 28rpx;
	font-weight: 500;
	margin-bottom: 12rpx;
}
.info-line {
	font-size: 26rpx;
	color: #666;
	margin-bottom: 8rpx;
}
.store-line {
	align-items: center;
}
.nav-link {
	color: #07cd9a;
	font-size: 24rpx;
}
.project-item {
	display: flex;
	padding: 16rpx 0;
	border-top: 1rpx solid #f5f5f5;
}
.project-img {
	width: 100rpx;
	height: 100rpx;
	border-radius: 8rpx;
	margin-right: 16rpx;
	background: #f5f5f5;
	flex-shrink: 0;
}
.project-name {
	font-size: 28rpx;
	flex: 1;
	margin-right: 12rpx;
}
.addon-tag {
	color: #07cd9a;
	margin-right: 6rpx;
}
.project-tag {
	font-size: 22rpx;
	color: #07cd9a;
	flex-shrink: 0;
}
.project-desc {
	font-size: 24rpx;
	color: #999;
	margin-top: 8rpx;
}
.project-num {
	font-size: 24rpx;
	color: #999;
	text-align: right;
	margin-top: 8rpx;
}
.remark-box {
	margin-top: 12rpx;
	padding: 12rpx 16rpx;
	background: #fff8e6;
	border-radius: 8rpx;
	font-size: 24rpx;
	color: #666;
}
.action-row {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	flex-wrap: wrap;
	gap: 16rpx;
	margin-top: 16rpx;
}
.action-btn {
	padding: 0 28rpx;
	height: 56rpx;
	line-height: 56rpx;
	border-radius: 28rpx;
	font-size: 24rpx;
	border: 1rpx solid #ddd;
	background: #fff;
	color: #333;
}
.action-btn.outline.primary {
	border-color: #07cd9a;
	color: #07cd9a;
}
.tag-line {
	gap: 12rpx;
}
.tag-set-btn {
	height: 48rpx;
	line-height: 48rpx;
	padding: 0 24rpx;
	font-size: 22rpx;
}
.tag-mask {
	position: fixed;
	left: 0;
	top: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.45);
	z-index: 100;
}
.tag-panel {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	background: #fff;
	border-radius: 24rpx 24rpx 0 0;
	padding: 32rpx 24rpx calc(32rpx + env(safe-area-inset-bottom));
	z-index: 101;
}
.tag-panel-title {
	text-align: center;
	font-size: 30rpx;
	font-weight: 600;
	margin-bottom: 32rpx;
}
.tag-options {
	display: flex;
	flex-wrap: wrap;
	gap: 16rpx;
	min-height: 160rpx;
}
.tag-option {
	padding: 0 28rpx;
	height: 56rpx;
	line-height: 56rpx;
	border-radius: 28rpx;
	border: 1rpx solid #87939d;
	color: #87939d;
	font-size: 24rpx;
}
.tag-option.on {
	border-color: #07cd9a;
	background: #07cd9a;
	color: #fff;
}
.tag-panel-actions {
	margin-top: 40rpx;
	gap: 24rpx;
}
.tag-btn {
	width: 300rpx;
	height: 88rpx;
	line-height: 88rpx;
	text-align: center;
	border-radius: 8rpx;
	font-size: 28rpx;
}
.tag-btn.confirm {
	background: #07cd9a;
	color: #fff;
}
.tag-btn.cancel {
	border: 1rpx solid #c1c6cd;
	color: #c1c6cd;
}
.action-btn.refuse {
	border-color: #e93323;
	color: #e93323;
}
.countdown-box {
	font-size: 24rpx;
	color: #666;
}
.countdown-text {
	color: #e93323;
	margin-left: 8rpx;
}
.footer-space {
	height: calc(120rpx + env(safe-area-inset-bottom));
}
</style>
