<template>
	<view :style="colorStyle">
		<view class="fixed-lt bg--w111-f5f5f5 w-full z-999 header_box" :style="{'padding-top': sysHeight + 'px'}">
			<view class="h-80 px-20 flex-y-center">
				<text class="iconfont icon-ic_leftarrow fs-40 text--w111-333" @click="goPage(3)"></text>
				<!--  #ifdef  MP-WEIXIN -->
				<view class="w-460 h-58 rd-30 bg--w111-fff flex-y-center px-32 ml-20">
				<!--  #endif -->
				<!--  #ifndef  MP-WEIXIN -->
				<view class="flex-1 h-58 rd-30 bg--w111-fff flex-y-center px-32 ml-20">
				<!--  #endif -->
					<text class="iconfont icon-ic_search fs-24 text--w111-999"></text>
					<input v-model="keyword" confirm-type="search" @confirm="inputConfirm"
					class="pl-18 flex-1 fs-24" placeholder="请输入商品名称" placeholder-class="text--w111-999" />
				</view>
			</view>
			<view class="h-100 w-full px-32 flex-between-center fs-26">
				<text v-for="(item,index) in navList" :key="index" :class="orderStatus === item.type ? 'active' : ''" @click="statusClick(item.type)">{{item.name}}</text>
			</view>
		</view>
		<view class="px-20 list-wrap" :style="{'margin-top':marTop + 'px'}">
			<view class="order_card bg--w111-fff rd-24rpx"
				v-for="(item, index) in orderList" :key="item.id || index">
				<view class="card-header">
					<view class="card-header-left">
						<view class="info-row" @tap.stop="goStore(item)">
							<text class="info-label">预约门店：</text>
							<text class="info-value line1">{{ item.name || '—' }}</text>
							<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999"></text>
						</view>
						<view class="info-row">
							<text class="info-label">预约时间：</text>
							<text class="info-value">{{ getAppointmentTime(item) }}</text>
						</view>
						<view class="info-row" v-if="item.staff_name" @tap.stop="goStaff(item)">
							<text class="info-label">预约老师：</text>
							<text class="info-value line1">{{ item.staff_name }}</text>
							<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999"></text>
						</view>
					</view>
					<view class="status-text font-num">{{ getStatusText(item.status) }}</view>
				</view>
				<view class="project-list">
					<view class="project-item" v-for="(project, pIndex) in getProjectList(item)" :key="pIndex"
						@tap="goReservationDetails(item.id)">
						<easy-loadimage
							v-if="project.image"
							:image-src="project.image"
							width="120rpx"
							height="120rpx"
							borderRadius="12rpx"></easy-loadimage>
						<view v-else class="project-img-placeholder"></view>
						<view class="project-content">
							<view class="project-title-row">
								<text class="project-name line2">{{ project.product_name }}</text>
								<text class="project-num">×{{ project.cart_num || 1 }}</text>
							</view>
							<view class="project-desc line2" v-if="project.desc">{{ project.desc }}</view>
						</view>
					</view>
				</view>
				<view class="remark-box" v-if="item.mark">
					<text class="remark-label">服务备注：</text>
					<text class="remark-text">{{ item.mark }}</text>
				</view>
				<view class="card-footer">
					<view class="action-btn outline" @tap.stop="makePhone(item.master_phone)">联系管家</view>
					<view class="action-btn outline" v-if="canCancelReservation(item)"
						@tap.stop="doCancel(item.id, index)">取消预约</view>
				</view>
		    </view>
			<block v-if="orderList.length == 0 && !loading">
				<emptyPage title="暂无信息～" :src="keyword ? '/statics/images/noSearch.gif' : '/statics/images/noOrder.gif'"></emptyPage>
			</block>
			<view class="loadingicon flex-center" v-if="orderList.length > 0">
				<text class="loading iconfont icon-ic_Refresh" :hidden="loading == false"></text>
				<text class="fs-26 pb-32">{{ loadTitle }}</text>
			</view>
			<view class="pb-safe"></view>
	    </view>
		<view :style="{ height: showBar ? `${pdHeight * 2 + 96}rpx` : '0' }"></view>
		<view class="safe-area-inset-bottom" v-if="showBar"></view>
		<pageFooter :style="colorStyle" @newDataStatus="newDataStatus"></pageFooter>
	    <home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import {
		getReservationOrderList,
		postReservationOrderCancel
	} from '@/api/order.js';
	import home from '@/components/home';
	import pageFooter from '@/components/pageFooter/index.vue';
	import { toLogin } from '@/libs/login.js';
	import { mapGetters } from 'vuex';
	import emptyPage from '@/components/emptyPage.vue';
	import colors from '@/mixins/color.js';
	import Loading from '@/components/Loading/index.vue'
	import { openYuyueSubscribe } from '@/utils/SubscribeMessage.js';
	export default {
		components: {
			Loading,
			home,
			emptyPage,
			pageFooter
		},
		mixins: [colors],
		data() {
			return {
				navList:[
					{
						name:'全部',
						type:''
					},
					{
						name:'待服务',
						type:0
					},
					{
						name:'进行中',
						type:1
					},
					{
						name:'已完成',
						type:2
					},
					{
						name:'已取消',
						type:-1
					}
				],
				sysHeight:sysHeight,
				loading: false, //是否加载中
				loadend: false, //是否加载完毕
				loadTitle: '加载更多', //提示语
				orderList: [], //订单数组
				orderStatus: '', //订单状态
				page: 1,
				limit: 20,
				keyword: '',
				marTop:0,
				orderId:0,
				bookUid: 0,
				homeHide: false,
				showBar: false,
				pdHeight: 0
			};
		},
		computed: {
			...mapGetters(['isLogin']),
			fixedTop() {
				// #ifdef MP || APP-PLUS
				return this.sysHeight + 'px'
				// #endif
				return this.data
				// #ifndef MP
				return 0
				// #endif
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			this.page = 1;
			this.loadend = false;
			this.orderList = [];
			if (this.isLogin) {
				this.getOrderList();
			} else {
				toLogin()
			}
		},
		onPageScroll(object) {
			uni.$emit('scroll');
			this.homeHide = true;
		},
		/**
		 * 生命周期函数--监听页面加载
		 */
		onLoad(options) {
			this.orderId = Number(options.orderId || options.id || options.oid || 0) || 0;
			this.bookUid = Number(options.book_uid || 0) || 0;
			this.getMarTop();
		},
		methods: {
			getMarTop(){
				let that = this;
				setTimeout(() => {
					// 获取小程序头部高度
					let info = uni.createSelectorQuery().in(this).select(".header_box");
					info.boundingClientRect(function(data) {
						that.marTop = data.height
					}).exec()
				}, 100)
			},
			goReservationDetails: function(id) {
				if (!id){
					return this.$util.Tips({
						title: '缺少预约单号无法查看预约详情'
					});
				}
				uni.navigateTo({
					url: '/pages/goods/reservation_details/index?id=' + id
				});
			},
			getStatusText(status) {
				const map = {
					3: '待确认',
					0: '待服务',
					1: '进行中',
					2: '已完成',
					4: '已退回',
					'-1': '已取消',
					[-1]: '已取消'
				};
				return map[status] || '';
			},
			getAppointmentTime(item) {
				if (item.appointment_time) return item.appointment_time;
				const time = item.reservation_time || '';
				const start = item.reservation_start || '';
				return start ? `${time} ${start}` : time;
			},
			getProjectList(item) {
				if (Array.isArray(item.project_list) && item.project_list.length) {
					return item.project_list;
				}
				const cartInfo = item.cart_info || {};
				const productInfo = cartInfo.productInfo || {};
				if (!productInfo.store_name) return [];
				return [{
					product_name: productInfo.store_name,
					image: productInfo.image || '',
					desc: (productInfo.attrInfo && productInfo.attrInfo.suk) || productInfo.store_info || '',
					cart_num: cartInfo.cart_num || 1
				}];
			},
			goStore(item) {
				if (!item.store_id) return;
				this.goPage(1, `/pages/store/home/index?id=${item.store_id}`);
			},
			goStaff(item) {
				if (!item.primary_staff_id && !item.staff_name) return;
				const query = [];
				if (item.store_id) query.push(`store_id=${item.store_id}`);
				if (item.primary_staff_id) query.push(`staff_id=${item.primary_staff_id}`);
				this.goPage(1, `/pages/activity/therapist_list/index${query.length ? '?' + query.join('&') : ''}`);
			},
			makePhone(phone) {
				if (!phone) {
					return this.$util.Tips({ title: '请联系客服' });
				}
				uni.makePhoneCall({ phoneNumber: String(phone) });
			},
			canCancelReservation(item) {
				return Number(item.status) === 3;
			},
			doCancel(id, index) {
				const that = this;
				uni.showModal({
					title: '取消预约提醒',
					content: '你确认取消该条预约吗？',
					success(res) {
						if (!res.confirm) return;
						postReservationOrderCancel(id).then(() => {
							that.orderList.splice(index, 1);
							return that.$util.Tips({ title: '成功取消预约！' });
						}).catch(err => {
							return that.$util.Tips({ title: err.msg || err || '取消失败' });
						});
					}
				});
			},
			/**
			 * 切换类型
			 */
			statusClick: function(status) {
				if (this.loading) return
				if (status === this.orderStatus) return;
				// #ifdef MP
				if (!this._yuyueSubscribed) {
					this._yuyueSubscribed = true;
					openYuyueSubscribe();
				}
				// #endif
				this.orderStatus = status;
				this.loadend = false;
				this.page = 1;
				this.$set(this, 'orderList', []);
				this.getOrderList();
			},
			inputConfirm(){
				this.loadend = false;
				this.loading = false;
				this.page = 1;
				this.orderList = [];
				this.getOrderList();
			},
			/**
			 * 获取订单列表
			 */
			getOrderList: function() {
				let that = this;
				if (that.loadend) return;
				if (that.loading) return;
				that.loading = true;
				that.loadTitle = '加载更多';
				getReservationOrderList ({
						status: that.orderStatus,
						search: this.keyword,
						oid: that.orderId || 0,
						book_uid: that.bookUid || 0,
						page: that.page,
						limit: that.limit
					})
					.then(res => {
						let list = res.data || [];
						let loadend = list.length < that.limit;
						that.orderList = that.$util.SplitArray(list, that.orderList);
						that.$set(that, 'orderList', that.orderList);
						that.loadend = loadend;
						that.loading = false;
						that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
						that.page = that.page + 1;
					})
					.catch(err => {
						that.loading = false;
						that.loadTitle = '加载更多';
					});
			},
			goPage(type, url){
				if(type == 1){
					uni.navigateTo({
						url
					})
				}else if(type == 2){
					uni.switchTab({
						url
					})
				}else if(type == 3){
					let pages = getCurrentPages();
					if (pages.length > 1) {
						uni.navigateBack();
					}else{
						uni.switchTab({
							url: '/pages/index/index'
						});
					}
				}

			},
			onhomehide() {
				this.homeHide = false;
			},
			newDataStatus(val, num) {
				this.showBar = !!val;
				this.pdHeight = num || 0;
			},
		},
		onReachBottom: function() {
			this.getOrderList();
		}
	};
</script>

<style scoped lang="scss">
	.list-wrap {
		padding-bottom: 20rpx;
	}

	.order_card {
		overflow: hidden;
		margin-bottom: 20rpx;
	}

	.card-header {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		padding: 24rpx 24rpx 20rpx;
		border-bottom: 1rpx solid #eee;
	}

	.card-header-left {
		flex: 1;
		min-width: 0;
		padding-right: 16rpx;
	}

	.info-row {
		display: flex;
		align-items: center;
		font-size: 28rpx;
		color: #282828;
		line-height: 40rpx;
		margin-bottom: 16rpx;

		&:last-child {
			margin-bottom: 0;
		}
	}

	.info-label {
		flex-shrink: 0;
	}

	.info-value {
		flex: 1;
		min-width: 0;
	}

	.status-text {
		flex-shrink: 0;
		font-size: 26rpx;
		color: var(--view-theme);
		line-height: 40rpx;
	}

	.project-list {
		padding: 0 24rpx;
	}

	.project-item {
		display: flex;
		padding: 22rpx 0;
		border-bottom: 1rpx solid #f5f5f5;

		&:last-child {
			border-bottom: none;
		}
	}

	.project-img-placeholder {
		width: 120rpx;
		height: 120rpx;
		border-radius: 12rpx;
		background: #f5f5f5;
		flex-shrink: 0;
	}

	.project-content {
		flex: 1;
		min-width: 0;
		margin-left: 20rpx;
	}

	.project-title-row {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 16rpx;
	}

	.project-name {
		flex: 1;
		font-size: 28rpx;
		color: #282828;
		line-height: 40rpx;
	}

	.project-num {
		flex-shrink: 0;
		font-size: 28rpx;
		color: #282828;
	}

	.project-desc {
		margin-top: 8rpx;
		font-size: 24rpx;
		color: #999;
		line-height: 34rpx;
	}

	.remark-box {
		padding: 0 24rpx 20rpx;
		font-size: 26rpx;
		color: #282828;
		line-height: 38rpx;
	}

	.remark-label {
		color: #282828;
	}

	.remark-text {
		word-break: break-all;
	}

	.card-footer {
		display: flex;
		justify-content: flex-end;
		align-items: center;
		padding: 20rpx 24rpx 24rpx;
		border-top: 1rpx solid #eee;
	}

	.action-btn {
		min-width: 176rpx;
		height: 60rpx;
		padding: 0 24rpx;
		border-radius: 30rpx;
		font-size: 27rpx;
		line-height: 58rpx;
		text-align: center;
		box-sizing: border-box;

		& ~ .action-btn {
			margin-left: 16rpx;
		}

		&.outline {
			border: 1rpx solid #ddd;
			color: #aaa;
			background: #fff;
		}
	}

	.active {
		color: var(--view-theme);
		font-weight: 500;
		font-size: 30rpx;
	}

	.abs-rt {
		position: absolute;
		top: 0;
		right: 0;
	}
	.pb-safe {
		padding-bottom: constant(safe-area-inset-bottom);
		padding-bottom: env(safe-area-inset-bottom);
	}
	.safe-area-inset-bottom {
		height: 0;
		height: constant(safe-area-inset-bottom);
		height: env(safe-area-inset-bottom);
	}
</style>