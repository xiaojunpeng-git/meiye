<template>
	<view class="pos-order-list" ref="container">
		<!-- #ifdef MP || APP-PLUS -->
		<NavBar titleText="订单管理" bagColor="#f5f5f5" :iconColor="iconColor" :textColor="iconColor" :isScrolling="isScrolling" showBack></NavBar>
		<!-- #endif -->
		<view class="searchCon acea-row">
			<view class="search acea-row row-middle">
				<text class="iconfont icon-ic_search"></text>
				<input class="inputs" placeholder='请输入用户手机号/用户昵称/订单号/商品名称' placeholder-class='placeholder' confirm-type='search' name="search" v-model="where.keyword" @confirm="searchSubmit"></input>
			</view>
			<view class="btn" @click="filterShow = true">
				<text class="iconfont icon-ic_sort"></text>
			</view>
		</view>
		<view :style="{ height: navHeight + 'px' }" v-if="isFixed"></view>
		<view class="nav acea-row row-around row-middle" :class="{ fixed: isFixed }" :style="{ top: isFixed?popupTop:0 }" id="nav">
			<view class="item" :class="state == -1 ? 'on' : ''" @click="changeStatus(-1)">
				全部
				<image src="../static/adorn.png" v-if="state == -1"></image>
			</view>
			<view class="item" :class="state == 0 ? 'on' : ''" @click="changeStatus(0)">
				待付款
				<image src="../static/adorn.png" v-if="state == 0"></image>
			</view>
			<view class="item" :class="state == 1 ? 'on' : ''" @click="changeStatus(1)">
				待发货
				<image src="../static/adorn.png" v-if="state == 1"></image>
			</view>
			<view class="item" :class="state == 10 ? 'on' : ''" @click="changeStatus(10)">
				待收货/核销
				<image src="../static/adorn.png" v-if="state == 10"></image>
			</view>
			<view class="item" :class="state == 3 ? 'on' : ''" @click="changeStatus(3)">
				待评价
				<image src="../static/adorn.png" v-if="state == 3"></image>
			</view>
		</view>
		<view class="list" v-if="list.length">
			<view class="item" v-for="(item, index) in list" :key="index">
				<view class="order-num acea-row row-between-wrapper" @click="toDetail(item)">
					<view class="acea-row row-middle">
						<view>{{ item.order_id }}</view>
						<view class="label" v-if="item.pink_name && !storeNum">{{item.pink_name}}</view>
						<view class="label" v-if="item.plat_name && storeNum">{{item.plat_name}}</view>
					</view>
					<view class="state" :class="(item.refund_status==0 && where.status != 0 && item.refund.length)?'on':''">
						{{item.refund_status==1?'退款中':item.refund_status==2?'已退款':item.refund_status==3?'拒绝退款':item.status_name.status_name}}
						<text v-if="item.refund_status==0 && where.status != 0 && item.refund.length">{{item.is_all_refund?'，退款中':'，部分退款中'}}</text>
					</view>
				</view>
				<view class="pos-order-goods">
					<view class="goods acea-row" :class="item._info && Object.keys(item._info).length > 1?'row-middle':''" @click="toDetail(item)">
						<scroll-view class="picTxt scroll-view" scroll-x="true" v-if="item._info && Object.keys(item._info).length > 1">
							<view class="pictrue" v-for="(val, key) in item._info" :key="key">
								<image :src="val.cart_info.productInfo.attrInfo?val.cart_info.productInfo.attrInfo.image:val.cart_info.productInfo.image" />
							</view>
						</scroll-view>
						<view class="picTxt" v-else>
							<view class="acea-row" v-for="(val, key) in item._info" :key="key">
								<view class="pictrue">
									<image :src="val.cart_info.productInfo.attrInfo?val.cart_info.productInfo.attrInfo.image:val.cart_info.productInfo.image" />
								</view>
								<view class="text">
									<view class="info line2">
										{{ val.cart_info.productInfo.store_name }}
									</view>
									<view class="attr" v-if="val.cart_info.productInfo.attrInfo">
										{{ val.cart_info.productInfo.attrInfo.suk }}
									</view>
								</view>
							</view>
						</view>
						<view class="money">
							<BaseMoney :money="item.pay_price" symbolSize="20" integerSize="32" decimalSize="20"></BaseMoney>
							<view class="num">共{{ item.total_num }}件</view>
						</view>
					</view>
				</view>
				<view class="flex-y-center bg--w111-f5f5f5 rd-16rpx p-24 mb-26" v-if="item.real_name.trim() || item.user_phone.trim() || item.user_address.trim()">
					<view class="flex-1">
						<view class="fs-28 text--w111-282828">
							<text v-if="item.real_name.trim()">{{item.real_name}}</text>
							<text class="pl-30" v-if="item.user_phone.trim()">{{item.user_phone}}</text>
						</view>
						<view class="mt-6 fs-24 text--w111-999" v-if="item.user_address.trim()">{{item.user_address}}</view>
					</view>
					<view class="flex pl-24">
						<view class="flex-center w-56 h-56 rd-50-p111- mr-32 bg-w111-EDF2F9" v-if="item.user_phone.trim()" @tap="makePhone(item.user_phone.trim())">
							<text class="iconfont icon-ic_Phone fs-28 text-w111-2A7EFB"></text>
						</view>
						<view class="flex-center w-56 h-56 rd-50-p111- bg-w111-EDF2F9" @tap="showMaoLocation(item)">
							<text class="iconfont icon-icon_Location fs-28 text-w111-2A7EFB"></text>
						</view>
					</view>
				</view>
				<view class="flex-y-center mb-26 fs-28" v-if="item.store_delivery_type == 2 && [2, 4].includes(item._status)">
					<text class="iconfont icon-icon_clock mr-8 fs-32"></text>{{item.estimate_time}}
				</view>
				<view class="operation acea-row row-between-wrapper">
					<view class="more">
					</view>
					<view class="acea-row row-middle">
						<view class="bnt" v-if="$util.auth('mall-admin-order-remark')" @click="modify(item, 1)">订单备注</view>
						<view class="bnt" :class="openErp?'on':''" @click="modify(item, 0)" v-if="item._status == 1 && $util.auth('mall-admin-order-change_price')">
							一键改价
						</view>
						<view class="bnt primary" :class="openErp?'on':''" v-if="item.status == 0 && item.paid == 0 && $util.auth('mall-admin-order-offline')" @click="confirmPay(item)">
							确认付款
						</view>
						<view class="bnt" v-if="item.supplier_id!==0 && item._status == 2" @click="btnClick(item)">提醒发货</view>
						<view class="bnt primary" :class="openErp?'on':''" v-if="item._status == 2 && $util.auth('mall-admin-order-delivery') && item.store_delivery_type != 2" @click="goDelivery(item)">发送货
						</view>
						<view class="bnt" :class="openErp?'on':''" v-if="item._status == 2 && $util.auth('mall-admin-order-delivery') && item.store_delivery_type == 2 && item.status_name.is_reissue_order == 1" @click="orderReissueOrder(item)">重新发单
						</view>
						<view class="bnt primary" :class="openErp?'on':''" v-if="item._status == 2 && $util.auth('mall-admin-order-delivery') && item.store_delivery_type == 2" @click="openDeliveryDrawer(item)">派单
						</view>
						<view class="bnt" :class="openErp?'on':''" v-if="item._status == 4 && $util.auth('mall-admin-order-delivery') && item.store_delivery_type == 2" @click="openDeliveryDrawer(item)">改派
						</view>
						<view class="bnt primary" :class="openErp?'on':''" v-if="item._status == 4 && $util.auth('mall-admin-order-delivery') && item.store_delivery_type == 2" @tap="deliveryConfirm(item)">确认送达
						</view>
						<navigator class="bnt primary" :url="'/pages/admin/logistics/index?orderId='+item.order_id" v-if="item._status == 4 && item.delivery_type == 'express'">查看物流</navigator>
						<view class="bnt primary" v-if="item.shipping_type == 2 &&
                (item.status == 0 || item.status == 5) &&
                item.paid == 1 &&
                item.refund_status === 0 && $util.auth('mall-admin-order-writeoff')" @click="verify(item)">订单核销</view>
					</view>
				</view>
			</view>
		</view>
		<view v-else class="px-20 mt-20">
			<emptyPage title="暂无订单～" src="/statics/images/noOrder.gif"></emptyPage>
		</view>
		<Loading :loaded="loaded" :loading="loading"></Loading>
		<PriceChange :change="change" :orderInfo="orderInfo" :isRefund="isRefund" v-on:statusChange="statusChange($event)" v-on:closechange="changeclose($event)" v-on:savePrice="savePrice"
			:status="status"></PriceChange>
		<view class="mask" v-if="filterShow" @click="filterShow = false"></view>
		<view class="filter-popup z-1000" :class="{ on: filterShow }" :style="{paddingTop:popupTop}">
			<view class="search-box">
				<view class="search acea-row row-middle">
					<text class="iconfont icon-ic_search"></text>
					<input class="input" placeholder='请输入要查询的订单' placeholder-class='placeholder' confirm-type='search' name="search" v-model="where.keyword" @confirm="searchSubmit"></input>
				</view>
			</view>
			<view class="content">
				<view class="item">
					<view class="title">按下单时间</view>
					<view class="acea-row list">
						<view class="cell" v-for="(item, index) in dateList" :key="index" :class="{ on: item.val == dateSelected }" @click="dateChange(item.val)">{{ item.label }}</view>
					</view>
				</view>
				<view class="item">
					<view class="title">按支付方式</view>
					<view class="acea-row list">
						<view class="cell" v-for="(item, index) in payList" :key="index" :class="{ on: item.val == where.pay_type }" @click="payChange(item.val,0)">{{ item.label }}</view>
					</view>
				</view>
				<view class="item" v-if="storeNum">
					<view class="title">按订单来源</view>
					<view class="acea-row list">
						<view class="cell" v-for="(item, index) in orderList" :key="index" :class="{ on: item.val == currentOrder }" @click="payChange(item.val,1)">{{item.label}}</view>
					</view>
				</view>
				<view class="item">
					<view class="title">按订单类型</view>
					<view class="acea-row list">
						<view class="cell" v-for="(item, index) in typeList" :key="index" :class="{ on: item.val == where.type }" @click="payChange(item.val,2)">{{item.label}}</view>
					</view>
				</view>
			</view>
		</view>
		<view v-if="confirmShow || deliveryConfirmShow" class="mask"></view>
		<view v-if="confirmShow" class="confirm-popup z-1000">
			<view class="title">确认付款</view>
			<view class="info">确认该订单用户已付款</view>
			<view class="acea-row row-between btn-box">
				<view class="btn" @click="confirmShow = false">取消</view>
				<view class="btn primary" @click="offlinePay">确认</view>
			</view>
		</view>
		<view v-if="deliveryConfirmShow" class="confirm-popup z-1000">
			<view class="title">确认送达</view>
			<view class="info">送达后不可撤销，是否确认送达？</view>
			<view class="acea-row row-between btn-box">
				<view class="btn" @click="deliveryConfirmShow = false">取消</view>
				<view class="btn primary" @click="onDeliveryConfirm">确认</view>
			</view>
		</view>
		<view class="footerH"></view>
		<footerPage :isWork='storeNum'></footerPage>
		<!-- 派单弹窗 -->
		<baseDrawer :visible="deliveryDrawerVisible" :zIndex="1000" :maskZIndex="1000" mode="bottom" backgroundColor="transparent" @close="closeDeliveryDrawer">
			<view class="pb-safe rd-t-40rpx bg--w111-f5f5f5">
				<view class="">
					<view class="flex-center h-108 rd-t-40rpx fw-500 fs-32">派单</view>
					<view class="abs-rt flex-center w-100 h-108">
						<view class="flex-center w-36 h-36 rd-50-p111- bg--w111-eee" @tap="closeDeliveryDrawer">
							<text class="iconfont icon-ic_close fs-24"></text>
						</view>
					</view>
				</view>
				<view class="pr-20 pb-20 pl-20">
					<view class="relative h-72 rd-36px bg--w111-fff">
						<view class="abs-lt flex-y-center w-80 h-72 pl-32">
							<text class="iconfont icon-ic_search fs-28 text--w111-999"></text>
						</view>
						<input v-model="keyword" class="flex-1 h-72 pl-80 fs-28" placeholder-class="text--w111-ccc" type="text" placeholder="请输入配送员名称/ID/手机号" confirm-type="search" @confirm="getStoreDeliveryList" />
					</view>
				</view>
				<scroll-view scroll-y="true" style="height: 1030rpx;">
					<view class="px-20">
						<view class="flex-y-center h-160 pr-40 pl-24 rd-16rpx mb-20 bg--w111-fff" v-for="(item, index) in deliveryList" :key="index" @tap="deliverySelect(item)">
							<image :src="item.avatar" mode="" class="w-96 h-96 rd-50-p111-"></image>
							<view class="flex-1 px-24 fs-28">
								<view class="mb-16 fw-500">{{item.wx_name}}</view>
								<view class="">{{item.phone}}</view>
							</view>
							<view class="flex-center w-56 h-56 rd-50-p111- bg-w111-EDF2F9" @tap="makePhone(item.phone)">
								<text class="iconfont icon-ic_Phone fs-28 text-w111-2A7EFB"></text>
							</view>
						</view>
					</view>
				</scroll-view>
			</view>
		</baseDrawer>
	</view>
</template>

<script>
	import {
		mapGetters
	} from "vuex";
	import {
		getStoreOrderList,
		getAdminOrderList,
		setStoreOrderPrice,
		setAdminOrderPrice,
		setStoreOrderRemark,
		setAdminOrderRemark,
		setStoreRefundRemark,
		setAdminRefundRemark,
		setStoreOfflinePay,
		setOfflinePay,
		setStoreOrderRefund,
		setOrderRefund,
		storeOrderRefundAgree,
		orderRefundAgree,
		getDelivery,
		storeDeliveryListApi,
		setAdminOrderDelivery,
		orderReassignDeliveryApi,
		confirmDelivery,
		orderReissueOrderApi,
	} from "@/api/admin";
	import {
		erpConfig
	} from "@/api/esp.js";
	import Loading from '@/components/Loading/index'
	import PriceChange from '../components/PriceChange/index.vue'
	import footerPage from '../components/footerPage/index.vue'
	import countDown from '@/components/countDown/index.vue'
	import emptyPage from '@/components/emptyPage.vue'
	// #ifdef MP || APP-PLUS
	import NavBar from '@/components/NavBar.vue';
	// #endif
	import {
		isMoney
	} from '@/utils/validate.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		name: "AdminOrderList",
		components: {
			Loading,
			PriceChange,
			footerPage,
			countDown,
			emptyPage,
			// #ifdef MP || APP-PLUS
			NavBar,
			// #endif
		},
		computed: mapGetters(["storeNum"]),
		data() {
			return {
				getHeight: this.$util.getWXStatusHeight(),
				iconColor: '#333333',
				isScrolling: false,
				top: 0,
				navHeight: 0,
				isFixed: false,
				filterShow: false,
				openErp: false,
				current: "",
				change: false,
				types: 0,
				where: {
					page: 1,
					limit: 10,
					status: '',
					keyword: '',
					data: '', // 时间筛选
					type: '', // 订单类型
					pay_type: '', // 支付方式
				},
				list: [],
				loaded: false,
				loading: false,
				orderInfo: {},
				status: "",
				state: -1,
				isRefund: 0, //1是仅退款;0是退货退款
				imgHost: HTTP_REQUEST_URL,
				dateSelected: '',
				dateList: [{
						label: '全部',
						val: '',
					},
					{
						label: '三天内',
						val: '1',
					},
					{
						label: '一个月内',
						val: '2',
					},
					{
						label: '三个月内',
						val: '3',
					},
					{
						label: '半年内',
						val: '4',
					},
				],
				payList: [{
						label: '全部',
						val: '',
					},
					{
						label: '微信支付',
						val: '1'
					},
					{
						label: '支付宝支付',
						val: '4'
					},
					{
						label: '余额支付',
						val: '2'
					},
					{
						label: '线下支付',
						val: '3'
					},
				],
				orderList:[
					{
						label: '全部',
						val: '-1'
					},
					{
						label: '平台订单',
						val: '0'
					},
					{
						label: '供应商订单',
						val: '2'
					},
					{
						label: '门店订单',
						val: '1'
					}
				],
				typeList:[
					{
						label: '全部',
						val: ''
					},
					{
						label: '拼单订单',
						val: '9'
					},
					{
						label: '桌码订单',
						val: '10'
					},
					{
						label: '普通订单',
						val: '0'
					},
					{
						label: '秒杀订单',
						val: '1'
					},
					{
						label: '砍价订单',
						val: '2'
					},
					{
						label: '拼团订单',
						val: '3'
					},
					{
						label: '积分订单',
						val: '4'
					},
					{
						label: '套餐订单',
						val: '5'
					},
					{
						label: '预售订单',
						val: '6'
					},
					{
						label: '新人订单',
						val: '7'
					},
					{
						label: '抽奖订单',
						val: '8'
					}
				],
				confirmOrder: {},
				confirmShow: false,
				popupTop:0,
				currentOrder:0,
				deliveryDrawerVisible: false,
				deliveryList: [],
				deliveryOrder: {},
				keyword: '',
				deliveryConfirmShow: false,
			};
		},
		onLoad(option) {
			// #ifdef MP
			this.popupTop = this.getHeight.barTop+this.getHeight.barHeight+'px'
			// #endif
			let type = option.types;
			this.where.status = type || '';
			this.state = type || -1;
			this.getErpConfig();
		},
		onShow() {
			this.init();
		},
		onPageScroll(option) {
			if (this.top - (this.getHeight.barTop + this.getHeight.barHeight) >= option.scrollTop) {
				this.isFixed = false;
			} else {
				this.isFixed = true;
			}
			// #ifdef MP
			if (option.scrollTop > 50) {
				this.isScrolling = true;
			} else if (option.scrollTop < 50) {
				this.isScrolling = false;
			}
			// #endif
		},
		mounted() {
			const query = uni.createSelectorQuery().in(this);
			query.select("#nav").boundingClientRect((data) => {
				this.top = data.top;
				this.navHeight = data.height;
			}).exec();
		},
		methods: {
			btnClick(item){
				getDelivery(item.id).then(res=>{
					this.$util.Tips({
						title: res.msg
					})
				}).catch(err=>{
					this.$util.Tips({
						title: err
					})
				})
			},
			verify(item) {
				let auth = this.storeNum?1:4;
				if (item.product_type == 4) {
					uni.navigateTo({
						url: '/pages/admin/writeOffCard/index?auth='+auth+'&id=' + item.id+'&storeNum='+this.storeNum
					})
				} else if(item.product_type == 6){
					uni.navigateTo({
						url: '/pages/admin/reservation_write/index?id=' + item.id
					})
				} else if(item.type == 11) {
					uni.navigateTo({
						url: '/pages/admin/distribution/scanning/detail/card?auth='+auth+'&id=' + item.id + '&let=1&code=' + item.verify_code +'&storeNum='+this.storeNum
					})
				} else {
					uni.navigateTo({
						url: '/pages/admin/distribution/scanning/detail/index?auth='+auth+'&id=' + item.id + '&let=1&code=' + item.verify_code+'&storeNum='+this.storeNum
					})
				}
			},
			statusChange(e) {
				this.status = e;
			},
			goDelivery(item) {
				if (this.openErp) return
				uni.navigateTo({
					url: '/pages/admin/delivery/index?id=' + item.order_id + '&listId=' + item.id + '&totalNum=' + item.total_num + '&orderStatus=' + item._status + '&comeType=1&productType=' +
						item.product_type+'&storeNum='+this.storeNum+'&store_id='+item.store_id
				})
			},
			getErpConfig() {
				let funApi = '';
				erpConfig().then(res => {
					this.openErp = res.data.open_erp;
				}).catch(err => {
					this.$util.Tips({
						title: err
					})
				})
			},
			// 获取数据
			getIndex: function() {
				let that = this;
				if (that.loading || that.loaded) return;
				that.loading = true;
				let funApi = ''
				if(this.storeNum){
					that.where.plat_type = this.currentOrder;
					funApi = getAdminOrderList(that.where);
				}else{
					funApi = getStoreOrderList(that.where);
				}
				funApi.then(
					res => {
						that.loading = false;
						that.loaded = res.data.length < that.where.limit;
						that.list.push.apply(that.list, res.data);
						that.where.page = that.where.page + 1;
					},
					err => {
						that.$util.Tips({
							title: err
						})
					}
				);
			},
			// 初始化
			init: function() {
				this.list = [];
				this.where.page = 1;
				this.loaded = false;
				this.loading = false;
				this.getIndex();
				this.current = "";
			},
			searchSubmit() {
				this.init();
			},
			// 导航切换
			changeStatus(val) {
				if (this.state != val) {
					this.state = val;
					this.where.status = val == -1 ? '' : val;
					this.init();
				}
			},
			// 商品操作
			modify: function(item, status, type) {
				if (this.openErp && status != 1) return
				this.change = true;
				this.status = status.toString();
				this.orderInfo = item;
				if (status == 2) {
					this.isRefund = type
				}
			},
			changeclose: function(msg) {
				this.change = msg;
			},
			objOrderRefund(data) {
				let that = this;
				let funApi = '';
				if(this.storeNum){
					funApi = setOrderRefund;
				}else{
					funApi = setStoreOrderRefund;
				}
				funApi(data).then(
					res => {
						that.change = false;
						that.$util.Tips({
							title: res.msg
						});
						that.init();
					},
					err => {
						that.change = false;
						that.$util.Tips({
							title: err
						});
					}
				);
			},
			async savePrice(opt) {
				let that = this,
					data = {},
					price = opt.price,
					refund_price = opt.refund_price,
					refund_status = that.orderInfo.refund_status,
					remark = opt.remark;
				data.order_id = that.orderInfo.order_id;
				if (that.status == 0) {
					if (!isMoney(price)) {
						return that.$util.Tips({
							title: '请输入正确的金额'
						});
					}
					data.price = price;
					let funApi = '';
					if(this.storeNum){
						funApi = setAdminOrderPrice;
					}else{
						funApi = setStoreOrderPrice;
					}
					funApi(data).then(
						res => {
							that.change = false;
							that.$util.Tips({
								title: '改价成功',
								icon: 'success'
							})
							that.init();
						},
						err => {
							that.change = false;
							that.$util.Tips({
								title: '改价失败',
								icon: 'none'
							})
						}
					);
				} else if (that.status == 2) {
					if (this.isRefund) {
						if (!isMoney(refund_price)) {
							return that.$util.Tips({
								title: '请输入正确的金额'
							});
						}
						data.price = refund_price;
						data.type = opt.type;
						this.objOrderRefund(data);
					} else {
						if (opt.type == 1) {
							let funApi = '';
							if(this.storeNum){
								funApi = orderRefundAgree;
							}else{
								funApi = storeOrderRefundAgree;
							}
							funApi(this.orderInfo.id).then(res => {
								that.change = false;
								that.$util.Tips({
									title: res.msg
								});
								that.init();
							}).catch(err => {
								that.change = false;
								that.$util.Tips({
									title: err
								});
							})
						}
					}
				} else if (that.status == 8) {
					data.type = opt.type;
					data.refuse_reason = opt.refuse_reason;
					this.objOrderRefund(data);
				} else {
					if (!remark) {
						return this.$util.Tips({
							title: '请输入备注'
						})
					}
					data.remark = remark;
					let obj = '';
					if (that.where.status == -3) {
						let funApi = '';
						if(this.storeNum){
							funApi = setAdminRefundRemark(data);
						}else{
							funApi = setStoreRefundRemark(data);
						}
						obj = funApi;
					} else {
						let funApi = '';
						if(this.storeNum){
							funApi = setAdminOrderRemark(data);
						}else{
							funApi = setStoreOrderRemark(data);
						}
						obj = funApi;
					}
					obj.then(
						res => {
							that.change = false;
							this.$util.Tips({
								title: res.msg,
								icon: 'success'
							})
							that.init();
						},
						err => {
							that.change = false;
							that.$util.Tips({
								title: err
							});
						}
					);
				}
			},
			toDetail(item) {
				uni.navigateTo({
					url: `/pages/admin/orderDetail/index?id=${item.order_id}&types=${this.where.status}&storeNum=`+this.storeNum
				})
			},
			offlinePay: function() {
				if (this.openErp) return
				let funApi = '';
				if(this.storeNum){
					funApi = setOfflinePay;
				}else{
					funApi = setStoreOfflinePay;
				}
				funApi({
					order_id: this.confirmOrder.order_id
				}).then(
					res => {
						this.confirmShow = false;
						this.$util.Tips({
							title: res.msg,
							icon: "success"
						});
						this.init();
					},
					error => {
						this.$util.Tips(error);
					}
				);
			},
			confirmPay(item) {
				this.confirmOrder = item;
				this.confirmShow = true;
			},
			dateChange(value) {
				const date = new Date();
				const end = date.toLocaleString();
				let start = '';
				if (value == 1) {
					date.setDate(date.getDate() - 2)
					date.setHours(0, 0, 0, 0);
				} else if (value == 2) {
					date.setDate(1)
					date.setHours(0, 0, 0, 0);
				} else if (value == 3) {
					date.setMonth(date.getMonth() - 2)
					date.setDate(1)
					date.setHours(0, 0, 0, 0);
				} else if (value == 4) {
					date.setMonth(date.getMonth() - 5)
					date.setDate(1)
					date.setHours(0, 0, 0, 0);
				}
				start = date.toLocaleString();
				this.dateSelected = value;
				this.filterShow = false;
				this.where.data = start == end ? '' : `${start}-${end}`;
				this.init();
			},
			payChange(val,num) {
				this.filterShow = false;
				if(num == 0){
					this.where.pay_type = val;
				}else if(num == 1){
					this.currentOrder = val;
				}else{
					this.where.type = val;
				}
				this.init();
			},
			makePhone(phone) {
				uni.makePhoneCall({
					phoneNumber: phone
				})
			},
			openDeliveryDrawer(item) {
				this.deliveryDrawerVisible = true;
				this.deliveryOrder = item;
				this.getStoreDeliveryList();
			},
			closeDeliveryDrawer() {
				this.deliveryDrawerVisible = false;
			},
			getStoreDeliveryList() {
				storeDeliveryListApi({
					type: 1,
					relation_id: this.deliveryOrder.store_id,
					keyword: this.keyword,
				}).then(res => {
					this.deliveryList = res.data.list;
				});
			},
			setInfo(deliveryItem) {
				let that = this;
				let funApi = '';
				let params = {
					type: 2,
					delivery_type: 1,
					sh_delivery_name: deliveryItem.wx_name,
					sh_delivery_id: deliveryItem.phone,
					sh_delivery_uid: deliveryItem.uid,
				}
				funApi = setAdminOrderDelivery;
				funApi(that.deliveryOrder.id, params).then(
					res => {
						that.deliveryDrawerVisible = false;
						that.$util.Tips({
							title: res.msg,
							icon: 'success',
							mask: true
						})
						if (that.state == -1) {
							const index = that.list.findIndex(item => item.id == that.deliveryOrder.id);
							that.list[index]._status = 4;
							this.list[index].status_name.status_name = '配送中';
						} else{
							setTimeout(res => {
								const index = that.list.findIndex(item => item.id == that.deliveryOrder.id);
								if (index > -1) {
									that.list.splice(index, 1);
								}
							}, 2000)
						}
					},
					error => {
						that.$util.Tips({
							title: error
						})
					}
				);
			},
			orderReassignDelivery(deliveryItem) {
				orderReassignDeliveryApi(this.deliveryOrder.id, {
					sh_delivery_name: deliveryItem.wx_name,
					sh_delivery_id: deliveryItem.phone,
					sh_delivery_uid: deliveryItem.uid,
				}).then(res => {
					this.deliveryDrawerVisible = false;
					this.$util.Tips({
						title: res.msg,
						icon: 'success',
						mask: true
					})
				});
			},
			deliverySelect(deliveryItem) {
				if (this.deliveryOrder._status == 4) {
					this.orderReassignDelivery(deliveryItem);
				} else{
					this.setInfo(deliveryItem);
				}
			},
			deliveryConfirm(item) {
				this.deliveryOrder = item;
				this.deliveryConfirmShow = true;
			},
			onDeliveryConfirm() {
				confirmDelivery({
					order_id: this.deliveryOrder.order_id,
				}).then(res => {
					this.deliveryConfirmShow = false;
					this.$util.Tips({
						title: res.msg,
						icon: 'success',
						mask: true
					})
					if (this.state == -1) {
						const index = this.list.findIndex(item => item.id == this.deliveryOrder.id);
						this.$set(this.list[index], '_status', 5);
						this.list[index].status_name.status_name = '待评价';
					} else{
						setTimeout(res => {
							const index = this.list.findIndex(item => item.id == this.deliveryOrder.id);
							if (index > -1) {
								this.list.splice(index, 1);
							}
						}, 2000)
					}
				})
			},
			showMaoLocation(item) {
				let data = {
					latitude: Number(item.latitude),
					longitude: Number(item.longitude),
					name: item.real_name,
					address: item.user_address,
				};
				// #ifdef H5
				if (this.$wechat.isWeixin()) {
					data.scale = 13;
					return this.$wechat.seeLocation(data);
				}
				// #endif
				uni.openLocation(data);
			},
			// 重新发单
			orderReissueOrder(item) {
				orderReissueOrderApi(item.id, {
					store_id: item.store_id,
				}).then(res => {
					this.$util.Tips({
						title: res.msg,
						icon: 'success',
						mask: true,
					});
					this.init();
				}).catch(err => {
					this.$util.Tips({
						title: err,
					});
				});
			},
		},
		onReachBottom() {
			this.getIndex()
		}
	}
</script>

<style lang="scss" scoped>
	.z-1000 {
		z-index: 1000 !important;
	}
	.bg-w111-EDF2F9{
		background-color: #EDF2F9;
	}
	.pl-80 {
		padding-left: 80rpx;
	}
	.pos-order-list {
		padding-bottom: calc(0rpx+ constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		padding-bottom: calc(0rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/

		/deep/.navbar {
			.content {
				background: #F5F5F5 !important;
			}
		}
	}

	.searchCon {
		padding: 10rpx 20rpx;

		.search {
			flex: 1;
			height: 72rpx;
			padding: 0 32rpx;
			border-radius: 36rpx;
			background: #FFFFFF;

			.iconfont {
				margin-right: 16rpx;
				font-size: 32rpx;
				color: #999999;
			}

			.inputs {
				flex: 1;
				font-size: 28rpx;
				color: #333;
			}

			.placeholder {
				font-size: 26rpx;
				color: #ccc;
			}
		}

		.btn {
			width: 72rpx;
			height: 72rpx;
			border-radius: 50%;
			margin-left: 20rpx;
			background: #FFFFFF;
			text-align: center;

			.iconfont {
				font-size: 32rpx;
				line-height: 72rpx;
				color: #666666;
			}
		}
	}

	.pos-order-list .nav {
		font-size: 26rpx;
		line-height: 42rpx;
		color: #333333;
		top: 0;
		left: 0;
		z-index: 99;

		&.fixed {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			background: #F5F5F5;
		}

		.item {
			position: relative;
			padding: 28rpx 0;

			image {
				width: 14rpx;
				height: 14rpx;
				display: block;
				position: absolute;
				bottom: 20rpx;
				right: -4rpx;
			}
		}
	}

	.pos-order-list .nav .item.on {
		font-weight: 500;
		font-size: 30rpx;
		color: #2A7EFB;
	}

	.pos-order-list .list {
		padding: 0 20rpx;
	}

	.pos-order-list .nothing {
		margin-top: 120upx;
		text-align: center;
		color: #cfcfcf;
	}

	.pos-order-list .list .item {
		padding: 32rpx 24rpx;
		border-radius: 24rpx;
		background-color: #fff;

		/deep/.time {
			.title {
				color: #FF7E00;
			}
		}
	}

	.pos-order-list .list .item~.item {
		margin-top: 20rpx;
	}

	.pos-order-list .list .item .order-num {
		font-size: 28rpx;
		line-height: 40rpx;
		color: #333333;
		.label{
			font-family: PingFang SC, PingFang SC;
			font-weight: 400;
			font-size: 20rpx;
			color: #2A7EFB;
			height: 32rpx;
			line-height: 30rpx;
			border-radius: 6rpx;
			border: 1px solid #2A7EFB;
			padding: 0 4rpx;
			margin-left: 16rpx;
		}
	}

	.pos-order-list .list .item .order-num .state {
		color: #FF7E00;
	}

	.pos-order-list .list .item .order-num .state.on {
		font-size: 24rpx;
		width: 180rpx;
		text-align: right;
	}

	.pos-order-list .list .item .order-num .time {
		font-size: 26upx;
		font-weight: normal;
		color: #999;
	}

	.pos-order-list .list .item .operation .more {
		position: relative;
	}

	.pos-order-list .list .item .operation .icon-gengduo {
		font-size: 50upx;
		color: #aaa;
	}

	.pos-order-list .list .item .operation .order .arrow {
		width: 0;
		height: 0;
		border-left: 11upx solid transparent;
		border-right: 11upx solid transparent;
		border-top: 20upx solid #e5e5e5;
		position: absolute;
		left: 15upx;
		bottom: -18upx;
	}

	.pos-order-list .list .item .operation .order .arrow:before {
		content: '';
		width: 0;
		height: 0;
		border-left: 7upx solid transparent;
		border-right: 7upx solid transparent;
		border-top: 20upx solid #fff;
		position: absolute;
		left: -7upx;
		bottom: 0;
	}

	.pos-order-list .list .item .operation .order {
		width: 200upx;
		background-color: #fff;
		border: 1px solid #eee;
		border-radius: 10upx;
		position: absolute;
		top: -100upx;
		z-index: 9;
	}

	.pos-order-list .list .item .operation .order .items {
		height: 77upx;
		line-height: 77upx;
		text-align: center;
	}

	.pos-order-list .list .item .operation .order .items~.items {
		border-top: 1px solid #f5f5f5;
	}

	.pos-order-list .list .item .operation .bnt {
		width: 144rpx;
		height: 56rpx;
		border-radius: 28rpx;
		border: 1rpx solid #CCCCCC;
		text-align: center;
		font-size: 24rpx;
		line-height: 54rpx;
		color: #333333;

		&.primary {
			color: #FFFFFF !important;
			background-color: #2A7EFB !important;
			border-color: #2A7EFB !important;
		}
	}

	.pos-order-list .list .item .operation .bnt~.bnt {
		margin-left: 16rpx;
	}

	.pos-order-list .list .item .operation .wait {
		margin-left: 30rpx;
	}

	.pos-order-goods .goods {
		padding: 26rpx 0;
	}

	.pos-order-goods .goods~.goods {
		border-top: 1px dashed #e5e5e5;
	}

	.pos-order-goods .goods .picTxt {
		flex: 1;
		min-width: 0;
	}

	.pos-order-goods .goods .scroll-view {
		box-sizing: border-box;
		white-space: nowrap;

		.pictrue {
			display: inline-block;

			+.pictrue {
				margin-left: 16rpx;
			}
		}
	}

	.pos-order-goods .goods .picTxt .pictrue {
		width: 136rpx;
		height: 136rpx;
	}

	.pos-order-goods .goods .picTxt .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 16rpx;
	}

	.pos-order-goods .goods .picTxt .text {
		flex: 1;
		min-width: 0;
		padding-left: 20rpx;
	}

	.pos-order-goods .goods .picTxt .text .info {
		font-size: 28rpx;
		line-height: 40rpx;
		color: #333333;
	}

	.pos-order-goods .goods .picTxt .text .info .label {
		color: #ff4c3c;
	}

	.pos-order-goods .goods .picTxt .text .attr {
		margin-top: 12rpx;
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #999999;
	}

	.pos-order-goods .goods .money {
		padding-left: 16rpx;
		text-align: right;
		font-size: 28upx;
	}

	.pos-order-goods .goods .money .info {
		margin-top: 18rpx;
		font-size: 24rpx;
	}

	.pos-order-goods .goods .money .x-money {
		color: #282828;
	}

	.pos-order-goods .goods .money .num {
		margin-top: 10rpx;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #999999;
	}

	.pos-order-goods .goods .money .y-money {
		color: #999;
		text-decoration: line-through;
	}

	.public-total {
		font-size: 28upx;
		color: #282828;
		border-top: 1px solid #eee;
		height: 92upx;
		line-height: 92upx;
		text-align: right;
		padding: 0 30upx;
		background-color: #fff;
	}

	.public-total .money {
		color: #ff4c3c;
	}

	.filter-popup {
		position: fixed;
		top: 0;
		left: 0;
		z-index: 100;
		width: 100%;
		border-radius: 0 0 32rpx 32rpx;
		background: #FFFFFF;
		transform: translateY(-100%);
		transition: transform 0.3s;

		&.on {
			transform: translateY(0);
		}

		.search-box {
			padding: 10rpx 32rpx;
		}

		.search {
			height: 58rpx;
			padding: 0 32rpx;
			border-radius: 29rpx;
			background: #F5F5F5;
		}

		.iconfont {
			font-size: 28rpx;
			color: #999999;
		}

		.input {
			flex: 1;
			height: 58rpx;
			padding-left: 16rpx;
			font-size: 24rpx;
		}

		.content {
			padding: 32rpx 30rpx 58rpx;
		}

		.item {
			margin-top: 36rpx;

			&:first-child {
				margin-top: 0;
			}
		}

		.title {
			font-size: 28rpx;
			line-height: 40rpx;
			color: #333333;
		}

		.list {
			padding: 0;
			margin-right: -24rpx;
		}

		.cell {
			width: 154rpx;
			height: 56rpx;
			border: 1rpx solid #F5F5F5;
			border-radius: 28rpx;
			margin: 24rpx 24rpx 0 0;
			background: #F5F5F5;
			text-align: center;
			font-size: 24rpx;
			line-height: 54rpx;
			color: #333333;

			&.on {
				border-color: #2A7EFB;
				background: #E9F2FE;
				color: #2A7EFB;
			}
		}
	}

	.footerH {
		height: 110rpx;
		height: calc(110rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(110rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
	}

	.mask {
		z-index: 1000;
	}

	.confirm-popup {
		position: fixed;
		top: 50%;
		right: 75rpx;
		left: 75rpx;
		z-index: 21;
		transform: translateY(-50%);
		border-radius: 32rpx;
		background: #FFFFFF;
		text-align: center;

		.title {
			padding: 40rpx 32rpx 0;
			font-weight: 500;
			font-size: 32rpx;
			line-height: 52rpx;
			color: #333333;
		}

		.info {
			padding: 24rpx 40rpx 0;
			font-size: 30rpx;
			line-height: 42rpx;
			color: #666666;
		}

		.btn-box {
			padding: 40rpx;
		}

		.btn {
			width: 244rpx;
			height: 72rpx;
			border: 1rpx solid #2A7EFB;
			border-radius: 36rpx;
			// margin-left: 32rpx;
			font-weight: 500;
			font-size: 26rpx;
			line-height: 70rpx;
			color: #2A7EFB;
			transform: rotateZ(360deg);

			&.primary {
				background: #2A7EFB;
				color: #FFFFFF;
			}
		}
	}
</style>