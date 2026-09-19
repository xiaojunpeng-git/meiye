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
					<input v-model="keyword" confirm-type="search" @focus="searchFocus" @confirm="inputConfirm"
					class="pl-18 flex-1 fs-24" placeholder="请输入商品名称" placeholder-class="text--w111-999" />
				</view>
			</view>
			<view class="h-100 w-full px-32 flex-between-center fs-26" v-show="!focus">
				<text :class="orderStatus === '' ? 'm36f2e' : ''" @click="statusClick('')">全部</text>
				<text :class="orderStatus === 0 ? 'm36f2e' : ''" @click="statusClick(0)">待付款</text>
				<text :class="orderStatus === 1 ? 'm36f2e' : ''" @click="statusClick(1)">待发货</text>
				<view class="relative">
					<text :class="orderStatus === 'debt' ? 'm36f2e' : ''" @click="statusClick('debt')">欠款</text>
					<uni-badge class="uni-badge-left-margin" v-if="orderData.debt_order_count > 0" :text="orderData.debt_order_count"></uni-badge>
				</view>
				<view class="relative">
					<text :class="orderStatus === 10 ? 'm36f2e' : ''" @click="statusClick(10)">待收货/核销</text>
					<uni-badge class="uni-badge-left-margin" v-if="orderData.received_count > 0" :text="orderData.received_count"></uni-badge>
				</view>
				<text :class="orderStatus === 3 ? 'm36f2e' : ''" @click="statusClick(3)">待评价</text>
			</view>
		</view>
		<view class="pt-32 pl-20 pr-20" :style="{'margin-top':marTop - 50 + 'px'}" v-show="focus">
			<view class="fs-28 text--w111-333 fw-500 lh-40rpx mb-24 acea-row row-between-wrapper">
				<view>历史搜索</view>
				<view class="iconfont icon-ic_delete text--w111-999 fs-28" @click="delHistory"></view>
			</view>
			<view class="flex flex-wrap">
				<text class="inline-block max-200 h-56 line1 lh-56rpx rd-28rpx  bg--w111-fff px-24 fs-24  text--w111-666 text-center mr-24 mb-16"
					v-for="(item,index) in historyList" :key="index" @click="goSearch(item)">{{item}}</text>
				<text
			</view>
		</view>
		<view class="px-20" :style="{'margin-top':marTop + 'px'}" v-show="!focus">
			<view class="order_card bg--w111-fff rd-24rpx pt-32 pb-32 pl-24 pr-24"
			v-for="(item, index) in orderList" :key="index" @click="goOrderDetails(item.order_id)">
				<view class="flex-between-center">
					<view class="flex-y-center">
						<text v-if="item.pink_name"
							class="h-32 bg--w111-fff px-6 rd-6rpx fs-20 border_con font-num acea-row row-middle mr-16">
							{{item.pink_name}}</text>
						<!-- <text class="fs-28 lh-40rpx text--w111-333 pl-16">{{item._add_time}}</text> -->
						<view class="flex-y-center max-w-436" @tap.stop="goPage(item.store_id ? 1 : 2, item.store_id ? `/pages/store/home/index?id=${item.store_id}` : '/pages/index/index')">
							<text class="fs-28 line1">{{item.plate_name}}</text>
							<text class="iconfont icon-ic_rightarrow ml-8 fs-24 text--w111-666"></text>
						</view>
					</view>
					<view class="fs-26">
						<view class="font-num">
							<text v-show="item.is_del == 0">{{item._status._title}}</text>
							<text v-show="item.is_del == 1">交易取消</text>
							<!-- <text v-if="item.refund.length">{{item.is_all_refund?'，退款中':'，部分退款中'}}</text> -->
						</view>
				</view>
			</view>
			<view class="mt-26" v-if="item.cartInfo.length && item.cartInfo.length == 1">
				<view class="flex justify-between" v-for="(items, i) in item.cartInfo" :key="i">
					<view class="flex">
						<easy-loadimage
						:image-src="items.productInfo.image"
						width="136rpx"
						height="136rpx"
						borderRadius="16rpx"></easy-loadimage>
						<view class="ml-20">
							<view class="w-346 fs-28 text--w111-333 lh-40rpx line2"> {{items.productInfo.store_name }}
							</view>
							<view class="w-346 fs-24 text--w111-999 lh-34rpx line1 mt-12">{{items.productInfo.attrInfo.suk}}</view>
						</view>
					</view>
					<view>
							<baseMoney :money="item.pay_price" symbolSize="20" integerSize="32" decimalSize="20"
								incolor="#333333" weight></baseMoney>
						<view class="fs-24 text--w111-999 lh-34rpx text-right">共{{item.total_num}}件</view>
						<view v-if="item.pending_debt_amount > 0" class="fs-22 text-red text-right">欠款 ¥{{ item.pending_debt_amount }}</view>
					</view>
				</view>
			</view>
			<view class="mt-26 relative" v-if="item.cartInfo.length && item.cartInfo.length > 1">
				<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-560" show-scrollbar="false">
					<view class="inline-block mr-16" v-for="(items,i) in item.cartInfo" :key="i">
						<easy-loadimage
						:image-src="items.productInfo.image"
						width="136rpx"
						height="136rpx"
						borderRadius="16rpx"></easy-loadimage>
					</view>
				</scroll-view>
				<view class="abs-rt h-136 flex-col flex-center">
					<baseMoney :money="item.pay_price" symbolSize="20" integerSize="32" decimalSize="20"
						incolor="#333333" weight></baseMoney>
					<view class="fs-24 text--w111-999 lh-34rpx text-right">共{{item.total_num}}件</view>
				</view>
			</view>
			<view class="h-72 rd-8rpx bg--w111-f5f5f5 mt-26 px-20 flex-between-center">
				<view v-show="item.is_del == 0" @click.stop="goDetails(item)">
					<text class="text--w111-333 fs-26">{{item._status._title}}</text>
					<text class="text--w111-999 fs-26 pl-20">{{item._status._msg}}</text>
				</view>
				<view v-show="item.is_del == 1">
					<text class="text--w111-333 fs-26">已取消</text>
					<text class="text--w111-999 fs-26 pl-20">本次交易已取消，感谢您的支持</text>
				</view>
				<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999"></text>
			</view>
		</view>
		<block v-if="orderList.length == 0 && !loading">
			<emptyPage title="暂无订单信息～" :src="keyword ? '/statics/images/noSearch.gif' : '/statics/images/noOrder.gif'"></emptyPage>
		</block>
		<view class="loadingicon flex-center" v-if="orderList.length > 0">
			<text class="loading iconfont icon-ic_Refresh" :hidden="loading == false"></text>
			<text class="fs-26 pb-32">{{ loadTitle }}</text>
		</view>
	</view>
	<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import {
		getOrderList,
		orderData,
	} from '@/api/order.js';
	import { openOrderSubscribe } from '@/utils/SubscribeMessage.js';
	import home from '@/components/home';
	import { toLogin } from '@/libs/login.js';
	import { mapGetters } from 'vuex';
	import emptyPage from '@/components/emptyPage.vue';
	import colors from '@/mixins/color.js';
	import Loading from '@/components/Loading/index.vue'
	export default {
		components: {
			Loading,
			home,
			emptyPage,
		},
		mixins: [colors],
		data() {
			return {
				sysHeight:sysHeight,
				loaded: false,
				loading: false, //是否加载中
				loadend: false, //是否加载完毕
				loadTitle: '加载更多', //提示语
				orderList: [], //订单数组
				orderData: {}, //订单详细统计
				orderStatus: '', //订单状态
				m888edb9: '', //订单类型
				page: 1,
				limit: 20,
				pay_close: false,
				pay_order_id: '',
				totalPrice: '0',
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				keyword: '',
				marTop:0,
				focus:false,
				// 搜索历史
				historyList: [],
				// 临时搜索列表
				tempStorage: [],
				homeHide: false
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
				this.getFun();
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
			if (options.status === 'debt') {
				uni.redirectTo({ url: '/pages/users/debt/index' });
				return;
			}
			if (options.status !== undefined && options.status !== '') {
				this.orderStatus = parseInt(options.status);
			}
			if (options.type) this.m888edb9 = (options.type == undefined && options.type != 0) ? '' : parseInt(
					options.type);
			this.getMarTop();
		},
		methods: {
			goDetails(item){
				if(item._status._type == 3){
					if(item.cartInfo.length == 1){
						uni.navigateTo({
							url: "/pages/goods/goods_comment_con/index?unique=" + item.cartInfo[0].unique + "&uni=" + item.order_id
						})
					}else{
						this.goOrderDetails(item.order_id)
					}
				}else if(item._status._type == -2){
					if (!item.refund[0].order_id) return this.$util.Tips({
						title: '缺少退款订单号无法查看订单详情'
					});
					uni.navigateTo({
						url: '/pages/goods/order_after_details/index?order_id=' + item.refund[0].order_id + '&isReturen=1'
					})
				}else{
					this.goOrderDetails(item.order_id)
				}
			},
			delHistory(){
				this.tempStorage = []
				this.historyList = []
				uni.setStorageSync('orderHistoryList', this.tempStorage);
			},
			getFun() {
				this.getOrderData();
				this.getOrderList();
			},
			onLoadFun() {
				this.getFun();
				this.isShowAuth = false;
			},
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
			/**
			 * 获取订单统计数据
			 *
			 */
			getOrderData: function() {
				let that = this;
				orderData({type: this.m888edb9,search: this.keyword}).then(res => {
					that.$set(that, 'orderData', res.data);
				});
			},
			goOrderDetails: function(order_id) {
				if (!order_id)
					return this.$util.Tips({
						title: '缺少订单号无法查看订单详情'
					});
				// #ifdef MP
				uni.showLoading({
					title: '正在加载'
				});
				openOrderSubscribe()
					.then(() => {
						uni.hideLoading();
						uni.navigateTo({
							url: '/pages/goods/order_details/index?order_id=' + order_id
						})
					})
					.catch(err => {
						uni.hideLoading();
					});
				// #endif
				// #ifndef MP
				uni.navigateTo({
					url: '/pages/goods/order_details/index?order_id=' + order_id
				});
				// #endif
			},
			/**
			 * 切换类型
			 */
			statusClick: function(status) {
				if (this.loading) return
				if (status === 'debt') {
					uni.navigateTo({ url: '/pages/users/debt/index' });
					return;
				}
				if (status === this.orderStatus) return;
				this.orderStatus = status;
				this.loadend = false;
				this.page = 1;
				this.$set(this, 'orderList', []);
				this.getOrderList();
			},
			searchFocus(){
				try {
					this.historyList = []
					this.tempStorage = []
					let arr = uni.getStorageSync('orderHistoryList')
					if (arr.length > 0) {
						this.historyList = Array.from(new Set(arr))
					} else {
						this.historyList = []
					}

					this.tempStorage = this.historyList
				} catch (e) {}
				this.focus = true;
			},
			goSearch(keyword){
				this.loadend = false;
				this.loading = false;
				this.keyword = keyword;
				this.page = 1;
				this.orderList = [];
				this.getOrderList();
			},
			inputConfirm(){
				this.loadend = false;
				this.loading = false;
				this.page = 1;
				this.orderList = [];
				this.getOrderData();
				this.getOrderList();
				setTimeout(e => {
					let arr = this.tempStorage;
					if(this.keyword !== ''){
						arr.unshift(this.keyword);
						this.tempStorage = [...Array.from(new Set(arr))];
						uni.setStorageSync('orderHistoryList', this.tempStorage);
					}
				}, 1000)
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
				getOrderList({
						status: that.orderStatus,
						type: that.m888edb9,
						page: that.page,
						limit: that.limit,
						search: this.keyword
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
						that.focus = false;
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
		},
		onReachBottom: function() {
			this.getOrderList();
		}
	};
</script>

<style scoped lang="scss">
	.border_con {
		border: 1px solid var(--view-theme);
	}

	.border_ccc {
		border: 1rpx solid #ccc;
	}

	.m36f2e {
		color: var(--view-theme);
		font-weight: 500;
		font-size: 30rpx;
	}

	.abs-rt {
		position: absolute;
		top: 0;
		right: 0;
	}
	.order_card ~ .order_card{
		margin-top:20rpx;

	}
	.btn ~ .btn{
		margin-left: 16rpx;
	}
	.uni-badge-left-margin {
		position: absolute;
		top: -16rpx;
		right: -30rpx;
		::v-deep  .uni-badge--error {
			background-color: var(--view-theme) !important;
		}
		.uni-badge {
			color: var(--view-theme);
			border: 1px solid var(--view-theme);
			z-index: 29;
		}
	}
</style>
