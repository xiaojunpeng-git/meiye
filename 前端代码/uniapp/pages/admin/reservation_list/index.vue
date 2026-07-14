<template>
	<view class="butler-page" :style="colorStyle">
		<view class="header-box" :style="{'padding-top': sysHeight + 'px'}">
			<view class="nav-bar acea-row row-between-wrapper">
				<text class="iconfont icon-ic_leftarrow back-icon" @click="goPage"></text>
				<text class="nav-title">门店接单</text>
				<view class="nav-placeholder"></view>
			</view>
			<view class="store-row acea-row row-between-wrapper">
				<view>
					<view class="store-label">订单信息</view>
					<view class="store-name">{{ storeName || '当前门店' }}</view>
				</view>
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
				<view class="card-head">
					<view class="info-line service-time">服务时间：{{ item.appointment_time || formatTime(item) }}</view>
					<view class="info-line" @tap.stop="callPhone(item.customer_phone)">
						客户姓名：{{ displayCustomer(item) }}
						<text class="iconfont icon-ic_phone phone-icon" v-if="item.customer_phone"></text>
					</view>
					<view class="info-line" @tap.stop="callPhone(item.staff_phone)" v-if="item.booker_name">
						预约老师：{{ item.booker_name }}
						<text class="iconfont icon-ic_phone phone-icon" v-if="item.staff_phone"></text>
					</view>
					<view class="info-line" v-if="item.service_room">服务房间：{{ item.service_room }}</view>
				</view>

				<view class="project-list">
					<view class="project-item acea-row" v-for="(project, pIndex) in getProjectList(item)" :key="pIndex">
						<image class="project-img" :src="project.image" mode="aspectFill"></image>
						<view class="project-body flex-1">
							<view class="acea-row row-between-wrapper">
								<view class="project-name line2">{{ project.product_name }}</view>
								<view class="project-tag">{{ item.item_status_tag || '待核销' }}</view>
							</view>
							<view class="project-desc line1" v-if="project.desc">{{ project.desc }}</view>
							<view class="project-num">×{{ project.cart_num || 1 }}</view>
						</view>
					</view>
				</view>

				<view class="remark-box" v-if="item.mark">
					<text class="iconfont icon-ic_message remark-icon"></text>
					<text class="remark-text">服务备注：{{ item.mark }}</text>
				</view>
				<view class="refuse-box" v-if="item.refuse_reason && item.status == 4">
					退回原因：{{ item.refuse_reason }}
				</view>

				<view class="action-row" v-if="item.store_id == store_id">
					<view
						class="action-btn outline"
						v-if="canModify(item)"
						@click.stop="goModify(item)"
					>修改</view>
					<view
						class="action-btn confirm"
						v-if="item.status == 3 && canManageReservation"
						@click.stop="confirmOrder(item)"
					>接单</view>
					<view
						class="action-btn refuse"
						v-if="item.status == 3 && canManageReservation"
						@click.stop="openRefuse(item)"
					>拒绝</view>
				</view>
			</view>

			<emptyPage
				v-if="orderList.length == 0 && !loading"
				title="暂无订单信息～"
				:src="keyword ? '/statics/images/noSearch.gif' : '/statics/images/noOrder.gif'"
			></emptyPage>
			<view class="loadingicon flex-center" v-if="orderList.length > 0">
				<text class="loading iconfont icon-ic_Refresh" :hidden="loading == false"></text>
				<text class="fs-26 pb-32">{{ loadTitle }}</text>
			</view>
		</view>

		<view class="mask" v-if="roomVisible" @click="closeRoom"></view>
		<view class="refuse-panel room-panel" v-if="roomVisible">
			<view class="room-title">选择服务房间</view>
			<picker mode="selector" :range="roomLabels" @change="onRoomChange">
				<view class="room-picker">{{ roomLabels[selectedRoomIndex] || '请选择房间' }}</view>
			</picker>
			<view class="refuse-btns acea-row row-center-wrapper">
				<view class="refuse-btn cancel" @click="closeRoom">取消</view>
				<view class="refuse-btn sure" @click="submitConfirm">确定接单</view>
			</view>
		</view>

		<view class="mask" v-if="refuseVisible" @click="closeRefuse"></view>
		<view class="refuse-panel" v-if="refuseVisible">
			<textarea
				class="refuse-input"
				v-model="refuseReason"
				placeholder="请说明拒绝原因"
				placeholder-class="refuse-placeholder"
			></textarea>
			<view class="refuse-btns acea-row row-center-wrapper">
				<view class="refuse-btn cancel" @click="closeRefuse">取消</view>
				<view class="refuse-btn sure" @click="submitRefuse">确定</view>
			</view>
		</view>

		<uni-calendar :insert="false" ref="calendar" @confirm="changeDate" />
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import {
		storeReservationList,
		storeReservationStatistics,
		storeReservationConfirm,
		storeReservationRefuse,
		storeReservationTableList
	} from '@/api/store.js';
	import { openGuanjiaSubscribe } from '@/utils/SubscribeMessage.js';
	import { userInfo } from '@/api/admin.js';
	import uniCalendar from '@/components/uni-calendar/uni-calendar.vue';
	import home from '@/components/home';
	import { toLogin } from '@/libs/login.js';
	import { mapGetters } from 'vuex';
	import emptyPage from '@/components/emptyPage.vue';
	import colors from '@/mixins/color.js';

	export default {
		components: {
			home,
			emptyPage,
			uniCalendar
		},
		mixins: [colors],
		data() {
			return {
				navList: [
					{ name: '待确认', type: 3, countKey: 'pending_confirm' },
					{ name: '待服务', type: 0, countKey: 'pending_service' },
					{ name: '待评价', type: 'evaluate', countKey: 'pending_evaluate' },
					{ name: '已完成', type: 2, countKey: 'completed' },
					{ name: '已退回', type: 4, countKey: 'returned' }
				],
				orderData: {},
				sysHeight,
				loading: false,
				loadend: false,
				loadTitle: '加载更多',
				orderList: [],
				orderStatus: 3,
				page: 1,
				limit: 20,
				keyword: '',
				oid: 0,
				filterDate: '',
				dateLabel: '筛选日期',
				storeName: '',
				refuseVisible: false,
				refuseReason: '',
				refuseItem: null,
				roomVisible: false,
				confirmItem: null,
				tableList: [],
				selectedRoomIndex: 0,
				homeHide: false
			};
		},
		computed: {
			...mapGetters(['isLogin']),
			store_id() {
				const storeStaffInfo = this.$store.state.app.storeStaffInfo || {};
				return storeStaffInfo.store_id;
			},
			isButler() {
				const info = this.$store.state.app.storeStaffInfo || {};
				return Number(info.is_butler) === 1;
			},
			canManageReservation() {
				const info = this.$store.state.app.storeStaffInfo || {};
				return Number(info.is_butler) === 1 || Number(info.is_manager) === 1;
			},
			roomLabels() {
				return this.tableList.map(item => item.remarks || item.table_number || ('房间' + item.id));
			}
		},
		onShow() {
			this.page = 1;
			this.loadend = false;
			this.orderList = [];
			if (this.isLogin) {
				this.ensureStaffInfo().then(() => {
					if (!this.isButler) {
						return this.$util.Tips({ title: '暂无权限' }, '/pages/admin/work/store');
					}
					this.loadTableList();
					this.getStatistics();
					this.getOrderList();
				});
			} else {
				toLogin();
			}
		},
		onPageScroll() {
			uni.$emit('scroll');
			this.homeHide = true;
		},
		onLoad(options) {
			if (options.orderStatus !== undefined && options.orderStatus !== '') {
				this.orderStatus = parseInt(options.orderStatus);
			}
			if (options.status !== undefined && options.status !== '') {
				this.orderStatus = parseInt(options.status);
			}
			this.oid = options.oid || 0;
		},
		onPullDownRefresh() {
			this.page = 1;
			this.loadend = false;
			this.orderList = [];
			Promise.all([this.getStatistics(), this.getOrderList()]).finally(() => {
				uni.stopPullDownRefresh();
			});
		},
		onReachBottom() {
			this.getOrderList();
		},
		methods: {
			ensureStaffInfo() {
				const info = this.$store.state.app.storeStaffInfo || {};
				if (info.store_id) {
					this.storeName = info.store_info?.name || info.store_name || this.storeName;
					return Promise.resolve();
				}
				return userInfo().then(res => {
					this.$store.commit('SET_STORE_STAFF_INFO', res.data);
					if (res.data.mall_unique_auth) {
						uni.setStorageSync('mall_unique_auth', res.data.mall_unique_auth);
					}
					this.storeName = res.data.store_info?.name || res.data.store_name || '';
				}).catch(() => Promise.resolve());
			},
			openCalendar() {
				this.$refs.calendar.open();
			},
			changeDate(e) {
				this.filterDate = e.fulldate || '';
				this.dateLabel = this.filterDate || '筛选日期';
				this.reloadList();
			},
			reloadList() {
				this.page = 1;
				this.loadend = false;
				this.orderList = [];
				this.getStatistics();
				this.getOrderList();
			},
			getStatistics() {
				return storeReservationStatistics({
					is_manager: this.canManageReservation ? 1 : 0,
					date: this.filterDate
				}).then(res => {
					this.orderData = res.data || {};
				}).catch(() => {});
			},
			displayCustomer(item) {
				const name = item.customer_name || '—';
				const sex = item.customer_sex ? `（${item.customer_sex}）` : '';
				return `${name}${sex}`;
			},
			formatTime(item) {
				if (!item.reservation_time) return '—';
				return `${item.reservation_time} ${item.reservation_start || ''}`;
			},
			getProjectList(item) {
				if (item.project_list && item.project_list.length) {
					return item.project_list;
				}
				if (item.cart_info && item.cart_info.productInfo) {
					return [{
						product_name: item.cart_info.productInfo.store_name,
						image: item.cart_info.productInfo.image,
						desc: item.cart_info.productInfo.attrInfo?.suk || '',
						cart_num: item.cart_info.cart_num || 1
					}];
				}
				return [];
			},
			canModify(item) {
				return item.status == 3;
			},
			callPhone(phone) {
				if (!phone) return;
				uni.makePhoneCall({ phoneNumber: String(phone) });
			},
			goDetail(item) {
				if (!item || !item.id) return;
				uni.navigateTo({
					url: `/pages/admin/reservation_details/index?id=${item.id}&from=butler`
				});
			},
			goModify(item) {
				if (item.product_id) {
					let url = `/pages/activity/reservation/index?id=${item.product_id}`;
					if (item.oid) url += `&orderId=${item.oid}`;
					if (item.cart_info_id) url += `&cartInfoId=${item.cart_info_id}`;
					if (item.store_id) url += `&store_id=${item.store_id}`;
					url += `&reservationId=${item.id}&butlerEdit=1`;
					// #ifdef MP
					openGuanjiaSubscribe().finally(() => {
						uni.navigateTo({ url });
					});
					// #endif
					// #ifndef MP
					uni.navigateTo({ url });
					// #endif
					return;
				}
				uni.navigateTo({
					url: '/pages/admin/reservation_details/index?id=' + item.id
				});
			},
			openRefuse(item) {
				this.refuseItem = item;
				this.refuseReason = '';
				this.refuseVisible = true;
			},
			closeRefuse() {
				this.refuseVisible = false;
				this.refuseItem = null;
				this.refuseReason = '';
			},
			submitRefuse() {
				const reason = (this.refuseReason || '').trim();
				if (!reason) {
					return this.$util.Tips({ title: '请填写拒绝原因' });
				}
				if (!this.refuseItem) return;
				// #ifdef MP
				openGuanjiaSubscribe();
				// #endif
				storeReservationRefuse(this.refuseItem.id, { refuse_reason: reason }).then(r => {
					this.$util.Tips({ title: r.msg || '已拒绝' });
					this.closeRefuse();
					this.reloadList();
				}).catch(err => {
					this.$util.Tips({ title: err.msg || '操作失败' });
				});
			},
			loadTableList() {
				storeReservationTableList().then(res => {
					this.tableList = res.data || [];
				}).catch(() => {
					this.tableList = [];
				});
			},
			closeRoom() {
				this.roomVisible = false;
				this.confirmItem = null;
				this.selectedRoomIndex = 0;
			},
			onRoomChange(e) {
				this.selectedRoomIndex = Number(e.detail.value || 0);
			},
			confirmOrder(item) {
				if (!this.tableList.length) {
					return this.$util.Tips({ title: '暂无可用房间，请先在后台配置房号' });
				}
				this.confirmItem = item;
				this.selectedRoomIndex = 0;
				this.roomVisible = true;
			},
			submitConfirm() {
				if (!this.confirmItem) return;
				const room = this.tableList[this.selectedRoomIndex];
				if (!room) {
					return this.$util.Tips({ title: '请选择服务房间' });
				}
				// #ifdef MP
				openGuanjiaSubscribe();
				// #endif
				storeReservationConfirm(this.confirmItem.id, {
					table_id: room.id,
					table_name: room.remarks || String(room.table_number || '')
				}).then(res => {
					this.$util.Tips({ title: res.msg || '接单成功' });
					this.closeRoom();
					this.reloadList();
				}).catch(err => {
					this.$util.Tips({ title: err.msg || '操作失败' });
				});
			},
			statusClick(status) {
				if (this.loading || status === this.orderStatus) return;
				// #ifdef MP
				if (!this._guanjiaSubscribed) {
					this._guanjiaSubscribed = true;
					openGuanjiaSubscribe();
				}
				// #endif
				this.orderStatus = status;
				this.reloadList();
			},
			getOrderList() {
				if (this.loadend || this.loading) return Promise.resolve();
				this.loading = true;
				this.loadTitle = '加载更多';
				let status = this.orderStatus;
				if (status === 'evaluate') {
					status = 999;
				}
				return storeReservationList({
					status: status,
					search: this.keyword,
					oid: this.oid,
					page: this.page,
					limit: this.limit,
					date: this.filterDate,
					is_manager: this.canManageReservation ? 1 : 0
				}).then(res => {
					const list = res.data || [];
					const loadend = list.length < this.limit;
					this.orderList = this.$util.SplitArray(list, this.orderList);
					this.loadend = loadend;
					this.loading = false;
					this.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					this.page += 1;
				}).catch(() => {
					this.loading = false;
					this.loadTitle = '加载更多';
				});
			},
			goPage() {
				const pages = getCurrentPages();
				if (pages.length > 1) {
					uni.navigateBack();
				} else {
					uni.switchTab({ url: '/pages/user/index' });
				}
			},
			onhomehide() {
				this.homeHide = false;
			}
		}
	};
</script>

<style scoped lang="scss">
	.butler-page {
		min-height: 100vh;
		background: #f5f5f5;
		padding-bottom: 40rpx;
	}

	.header-box {
		background: linear-gradient(180deg, #07cd9a 0%, #06b888 100%);
		padding: 0 30rpx 80rpx;
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
	}

	.nav-placeholder {
		width: 60rpx;
	}

	.store-row {
		margin-top: 10rpx;
		position: relative;
	}

	.store-label {
		font-size: 26rpx;
		color: rgba(255, 255, 255, 0.85);
		margin-bottom: 8rpx;
	}

	.store-name {
		font-size: 34rpx;
		font-weight: 600;
		color: #31373e;
	}

	.date-btn {
		padding: 0 24rpx;
		height: 55rpx;
		line-height: 55rpx;
		border-radius: 55rpx;
		background: #07cd9a;
		color: #fff;
		font-size: 24rpx;
		border: 1rpx solid rgba(255, 255, 255, 0.35);
	}

	.status-nav {
		width: 690rpx;
		height: 140rpx;
		margin: -60rpx auto 0;
		background: #fff;
		border-radius: 12rpx;
		box-shadow: 0 8rpx 24rpx rgba(0, 0, 0, 0.04);
	}

	.status-item {
		text-align: center;
		font-size: 26rpx;
		color: #282828;
		padding: 24rpx 0;
		border-bottom: 5rpx solid transparent;
	}

	.status-item.on {
		font-weight: 600;
		border-color: #07cd9a;
	}

	.status-item .num {
		margin-top: 12rpx;
	}

	.list-wrap {
		width: 690rpx;
		margin: 20rpx auto 0;
	}

	.order-card {
		background: #fff;
		border-radius: 12rpx;
		margin-bottom: 20rpx;
		overflow: hidden;
	}

	.card-head {
		padding: 24rpx 30rpx;
		border-bottom: 1rpx solid #eee;
	}

	.info-line {
		font-size: 28rpx;
		color: #282828;
		margin-bottom: 16rpx;
	}

	.info-line:last-child {
		margin-bottom: 0;
	}

	.service-time {
		color: #e93323;
		font-weight: 500;
	}

	.phone-icon {
		margin-left: 16rpx;
		color: #07cd9a;
		font-size: 30rpx;
		vertical-align: middle;
	}

	.project-list {
		padding: 0 30rpx;
	}

	.project-item {
		padding: 22rpx 0;
		border-bottom: 1rpx solid #f5f5f5;
	}

	.project-item:last-child {
		border-bottom: none;
	}

	.project-img {
		width: 120rpx;
		height: 120rpx;
		border-radius: 8rpx;
		margin-right: 20rpx;
		flex-shrink: 0;
		background: #f5f5f5;
	}

	.project-name {
		flex: 1;
		font-size: 28rpx;
		color: #282828;
		margin-right: 16rpx;
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
		margin: 0 30rpx 20rpx;
		padding: 16rpx 20rpx;
		background: #fff8e6;
		border-radius: 8rpx;
		font-size: 26rpx;
		color: #666;
		display: flex;
		align-items: flex-start;
	}

	.remark-icon {
		margin-right: 10rpx;
		color: #ff9900;
	}

	.refuse-box {
		padding: 0 30rpx 20rpx;
		font-size: 26rpx;
		color: #999;
	}

	.action-row {
		display: flex;
		flex-direction: row;
		flex-wrap: nowrap;
		justify-content: flex-end;
		align-items: center;
		padding: 0 24rpx 24rpx;
		gap: 12rpx;
		overflow-x: auto;
	}

	.action-btn {
		flex-shrink: 0;
		min-width: 120rpx;
		height: 55rpx;
		line-height: 55rpx;
		text-align: center;
		border-radius: 55rpx;
		font-size: 24rpx;
		padding: 0 20rpx;
		white-space: nowrap;
	}

	.action-btn.outline {
		border: 1rpx solid #87939d;
		color: #87939d;
		background: #fff;
	}

	.action-btn.confirm {
		border: 1rpx solid #07cd9a;
		background: #07cd9a;
		color: #fff;
	}

	.action-btn.refuse {
		border: 1rpx solid #ed1313;
		color: #ed1313;
		background: #fff;
	}

	.mask {
		position: fixed;
		left: 0;
		top: 0;
		width: 100%;
		height: 100%;
		background: rgba(0, 0, 0, 0.45);
		z-index: 99;
	}

	.refuse-panel {
		position: fixed;
		left: 0;
		bottom: 0;
		width: 100%;
		background: #fff;
		border-radius: 20rpx 20rpx 0 0;
		padding: 30rpx 20rpx calc(30rpx + env(safe-area-inset-bottom));
		z-index: 100;
	}

	.refuse-input {
		width: 100%;
		height: 320rpx;
		padding: 24rpx;
		box-sizing: border-box;
		border: 1rpx solid #c1c6cd;
		border-radius: 12rpx;
		font-size: 28rpx;
	}

	.refuse-placeholder {
		color: #c1c6cd;
	}

	.refuse-btns {
		margin-top: 30rpx;
	}

	.refuse-btn {
		width: 300rpx;
		height: 88rpx;
		line-height: 88rpx;
		text-align: center;
		border-radius: 8rpx;
		font-size: 30rpx;
	}

	.refuse-btn.cancel {
		border: 1rpx solid #c1c6cd;
		color: #c1c6cd;
		margin-right: 30rpx;
	}

	.refuse-btn.sure {
		background: #07cd9a;
		color: #fff;
	}

	.room-title {
		font-size: 30rpx;
		font-weight: 600;
		margin-bottom: 24rpx;
		text-align: center;
	}

	.room-picker {
		height: 88rpx;
		line-height: 88rpx;
		padding: 0 24rpx;
		border: 1rpx solid #c1c6cd;
		border-radius: 12rpx;
		font-size: 28rpx;
		color: #333;
	}

	.loadingicon {
		padding: 20rpx 0 40rpx;
		color: #999;
	}
</style>
