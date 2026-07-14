<template>
	<view class="container">
		<view class="headerBg">
			<image :src="info.background_image" mode="aspectFill" class="image"></image>
			<view class="shade"></view>
			<!-- #ifdef MP || APP -->
			<view :style="{height: getHeight.barTop+'px'}"></view>
			<view :style="{height: getHeight.barHeight+'px'}"></view>
			<!-- #endif -->
			<view style="height: 224rpx;"></view>
		</view>
		<view class="page">
			<view class="search-box" :style="{height: headerHeight ? (headerHeight+'px') : 'auto'}">
				<view class="input-box" id="input-box" :class="{fixed: scrollTop > 0}">
					<!-- #ifdef MP || APP -->
					<view :style="{height: getHeight.barTop+'px'}"></view>
					<!-- #endif -->
					<!-- 顶部搜索框 -->
					<!-- #ifdef MP -->
					<view class="input-wrapper acea-row row-middle" :style="{height: getHeight.barHeight+'px'}">
						<view class="menu_box flex-center" @tap="moreNav">
							<text class="iconfont icon-ic_menu3"></text>
						</view>
						<!-- <navigator url="/pages/goods/goods_search/index" class="input acea-row row-middle"
							hover-class="none">
							<text class="iconfont icon-ic_search"></text>搜索商品
						</navigator> -->
						<view class="input acea-row row-middle">
							<text class="iconfont icon-ic_search"></text>
							<input type="text" confirm-type="search" placeholder="搜索商品" placeholder-class="placeholder" @confirm="inputConfirm" class="fs-28 text--w111-fff line1 flex-1" v-model="keyword" />
						</view>
						<view v-if="info.store_splicing_switch" class="group-btn" @click="onCollage">
							<image :src="imgHost+'/statics/images/group-btn.png'" mode="aspectFit" class="img"></image>
						</view>
						<view :style="{width: getHeight.barWidth+'px'}"></view>
					</view>
					<!-- #endif -->
					<!-- #ifndef MP -->
					<view class="input-wrapper acea-row row-middle">
						<view class="menu_box flex-center" @tap="moreNav">
							<text class="iconfont icon-ic_menu3"></text>
						</view>
						<!-- <navigator url="/pages/goods/goods_search/index" class="input acea-row row-middle"
							hover-class="none">
							<text class="iconfont icon-ic_search"></text>搜索商品
						</navigator> -->
						<view class="input acea-row row-middle">
							<text class="iconfont icon-ic_search"></text>
							<input type="text" confirm-type="search" placeholder="搜索商品" placeholder-class="placeholder" @confirm="inputConfirm" class="fs-28 text--w111-fff line1 flex-1" v-model="keyword" />
						</view>
						<!-- #ifdef H5 -->
						<view v-if="info.store_splicing_switch && $wechat.isWeixin()" class="group-btn" @click="onCollage">
							<image :src="imgHost+'/statics/images/group-btn.png'" mode="aspectFit" class="img"></image>
						</view>
						<!-- #endif -->
					</view>
					<!-- #endif -->
				</view>
			</view>
			<!-- <scroll-view scroll-y="true" class="scroll-view" @scroll="goodsListScroll" @scrolltolower="getProducts"> -->
			<!-- 门店地址 -->
			<view class="store-section">
				<view class="panel">
					<view class="acea-row row-middle store-name">
						<view class="name-wrap">
							<navigator :url="'/pages/store/info/index?store_id='+info.id" hover-class="none" class="acea-row row-middle name-inner">
								<text class="iconfont icon-ic_mall"></text>
								<view class="name line1">{{info.name}}</view>
								<text class="iconfont icon-ic_rightarrow"></text>
							</navigator>
						</view>
						<view class="switch flex-center w-176 h-46 bg--w111-f5f5f5" v-if="deliveryList.length > 1">
							<view v-for="(item, index) in deliveryList" :key="item.key" :class="{ on: switchNum == item.key }" class="btn flex-center w-84 h-38 fs-22 text--w111-666" @click="swithFn(item.key)">
								{{item.name}}
							</view>
						</view>
						<view class="tips" v-else-if="deliveryList.length == 1">
							本店仅支持{{deliveryList[0].key == 1?'配送':'到店核销'}}</view>
					</view>
					<view class="acea-row row-bottom address-wrap">
						<view class="address-box">
							<view class="time">营业时间：{{info.day_time}}</view>
							<view class="address-info pr-60 mt-20 line1">
								<text class="distance">距您{{info.range}}km</text>
								{{info.address}}{{info.detailed_address}}
							</view>
						</view>
						<view class="flex">
							<view class="" @click="showMaoLocation">
								<view class="flex-center w-40 h-40 rd-9 mb-8 bg--w111-f5f5f5">
									<text class="iconfont icon-ic_map fs-28"></text>
								</view>
								<view class="fs-20">地图</view>
							</view>
							<view class="ml-36" @click="goKefu">
								<view class="flex-center w-40 h-40 rd-9 mb-8 bg--w111-f5f5f5">
									<text class="iconfont icon-a-ic_customerservice2 fs-28"></text>
								</view>
								<view class="fs-20">客服</view>
							</view>
						</view>
					</view>
					<view class="flex mt-32" v-if="couponList.length">
						<scroll-view scroll-x="true" class="flex-1 min-w-0 white-nowrap fs-0">
							<template v-for="(item, index) in couponList">
								<view class="coupon inline-flex h-36 rd-6rpx mr-16 text-w111-theme" v-if="index < 5" :style="{backgroundColor: item.used.length ? '' : 'var(--view-minorColorT)'}" :key="item.id">
									<view class="coupon-left relative flex-center pr-10 pl-8 fs-18">
										<BaseMoney :money="item.coupon_price" color="var(--view-theme)" symbolSize="16" integerSize="24" decimalSize="24" isCoupon></BaseMoney>
										<view class="pl-4" v-if="item.use_min_price != 0">满{{Number(item.use_min_price)}}可用</view>
										<view class="pl-4" v-else>无门槛</view>
									</view>
									<view class="coupon-right flex-center pr-10 pl-10 fs-18" @tap="getCoupon(item.id, index)">{{item.used.length ? '已领' : '领'}}</view>
								</view>
							</template>
						</scroll-view>
						<view class="flex-center pl-16 fs-22" @tap="couponTap">更多<text class="iconfont icon-ic_rightarrow fs-24"></text></view>
					</view>
				</view>
			</view>
			<!-- 商品排序条件 -->
			<view class="nav-box" id="nav-box">
				<view class="nav acea-row row-middle" :class="{ fixed: scrollTop && conterTop <= headerHeight }" :style="{ top: headerHeight+'px' }">
					<view class="flex-1 acea-row row-around row-middle">
						<view @click="set_where(1)" :class="{'activeColor':active == 1}">
							默认排序
						</view>
						<view @click='set_where(2)' :class="{'activeColor':active == 2}">
							价格
							<text v-if="price==2" class="iconfont icon-ic_down2"></text>
							<text v-else class="iconfont icon-ic_up2"></text>
						</view>
						<view @click='set_where(3)' :class="{'activeColor':active == 3}">
							销量
							<text v-if="stock==2" class="iconfont icon-ic_down2"></text>
							<text v-else class="iconfont icon-ic_up2"></text>
						</view>
					</view>
					<view @click="set_where(4)" class="filter-btn">
						<view class="line-left"></view>
						筛选
						<text class="iconfont icon-ic_sort"></text>
					</view>
				</view>
			</view>
			<!-- 商品列表 -->
			<view class="list waterList">
				<WaterfallsFlow ref="waterfallsFlow" :wfList="productList" @itemTap="godDetail"></WaterfallsFlow>
				<view class='loadingicon acea-row row-center-wrapper' v-if='productList.length > 0'>
					<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
				</view>
			</view>
			<!-- 商品列表 -->
			<view class="default" v-if="productList.length==0 && where.page > 1">
				<emptyPage title="暂无商品，去看点别的吧～"></emptyPage>
			</view>
			<!-- </scroll-view> -->
			<view class="footer">
				<view class="inner-box" :class="{ open: footerOpen }">
					<view class="inner acea-row row-between row-middle">
						<view class="items-baseline inline-flex h-60 lh-60rpx overflow" style="flex-wrap: nowrap;">
							<BaseMoney :money="totalPrice" symbolSize="32" integerSize="48" decimalSize="32" color="#FFFFFF"></BaseMoney>
							<view class="detail-btn lh-34rpx" style="white-space: nowrap;" @click="getCartLists" v-show="cartData.iScart">收起 <text class="iconfont icon-ic_downarrow fs-24"></text></view>
							<view class="detail-btn lh-34rpx" style="white-space: nowrap;" @click="getCartLists" v-show="!cartData.iScart">查看明细 <text class="iconfont icon-ic_uparrow fs-24"></text></view>
						</view>
						<view class="btn" @click="subOrder">去结算({{cartNums}})</view>
					</view>
				</view>
				<view class="cartIcon" @click="getCartLists">
					<uni-badge :text="cartNums" absolute="rightTop" :offset="[6, 4]" :custom-style="{background: 'var(--view-theme)'}">
						<view class="image-wrap">
							<image src="@/static/img/cart_pic.png" class="image"></image>
						</view>
					</uni-badge>
				</view>
			</view>
		</view>
		<!-- 筛选弹窗 -->
		<filterPopup ref="popup" :level="level" :storeCategory="storeCategory" :storeBrand="storeBrand" @brandChange="brandChange" @categoryChange="categoryChange" @submitFn="submitFn"></filterPopup>
		<!-- 客服弹窗 -->
		<Kefu ref="kefu" @closeKefu="closeKefu" :customerList="customerList" :customerType="customerType"></Kefu>
		<!-- #ifdef H5 || MP -->
		<tuiDrawer :visible="collageVisible" :zIndex="970" :maskZIndex="960" mode="bottom" backgroundColor="transparent" @close="onCollageClose">
			<view class="dialog">
				<view class="dialog-head acea-row row-between row-middle">
					<view>发起「一起拼」</view>
					<view class="btn" @click="onCollageClose">
						<text class="iconfont icon-ic_close"></text>
					</view>
				</view>
				<view class="dialog-body">
					<view class="navbar" v-if="deliveryList.length > 1">
						<view class="navbar-bg"></view>
						<view class="active-bg acea-row row-bottom" :class="{'row-right':collageDelivery==2}">
							<image v-if="collageDelivery==2" class="image2" src="../static/navbar-right-2.png"></image>
							<image class="image1" src="../static/navbar-left-1.png"></image>
							<image v-if="collageDelivery==1" class="image2" src="../static/navbar-left-2.png"></image>
						</view>
						<view class="inner acea-row">
							<view v-for="(item, index) in deliveryList" :key="item.key" :class="{ on: collageDelivery == item.key }" class="item acea-row row-center-wrapper" @click="collageDeliveryChange(item, index)">
								<text :class="'iconfont '+item.icon"></text>
								{{item.name}}
							</view>
						</view>
					</view>
					<view class="dialog-content" :class="{
								btl:collageIndex==deliveryList.length-1,
								btr:!collageIndex
							}">
						<scroll-view v-if="collageDelivery == 1 && addressList.length" :scroll-y="addressList.length > 3" :style="{ height: addressList.length > 3 ? '388rpx' : 'auto' }" class="address-box">
							<view class="list">
								<view v-for="(item, index) in addressList" :key="item.id" :class="{ on: item.id == addressId }" class="item acea-row row-middle" @click="onAddressChange(item)">
									<view class="text">
										<view class="info">
											{{item.province}}{{item.city}}{{item.district}}{{item.street}}{{item.detail}}
										</view>
										<view class="name">{{item.real_name}} {{item.phone}}</view>
									</view>
									<text class="iconfont icon-ic_complete fs-31 ml-10" v-if="item.id == addressId"></text>
								</view>
							</view>
						</scroll-view>
						<navigator v-if="collageDelivery == 1 && !addressList.length" class="link" url="/pages/users/user_address/index?fromType=1" hover-class="none">
							<view class="title acea-row row-between-wrapper">
								<view>添加配送地址</view>
								<text class="iconfont icon-ic_rightarrow"></text>
							</view>
							<view>您还未添加配送地址，先去添加地址再下单吧~</view>
						</navigator>
						<view v-if="collageDelivery == 2" class="store-box acea-row">
							<view class="text">
								<view class="name">{{collageStore.name}}</view>
								<view class="info acea-row">
									<text class="iconfont icon-ic_location51"></text>
									<view class="value">{{collageStore.address}}{{collageStore.detailed_address}}</view>
								</view>
								<view class="info acea-row">
									<text class="iconfont icon-icon_clock"></text>
									<view class="value">营业时间：{{collageStore.day_time}}</view>
								</view>
							</view>
							<view class="map-box">
								<image src="../static/map2.png" class="map"></image>
								<view class="img-box">
									<image :src="collageStore.image" class="img"></image>
									<view class="range">距您{{collageStore.range}}km</view>
									<view class="dot"></view>
								</view>
							</view>
						</view>
						<view class="btn-box acea-row row-center-wrapper" v-if="collageDelivery == 1 && addressList.length">
							<navigator url="/pages/users/user_address/index?fromType=1" hover-class="none">
								添加地址
								<text class="iconfont icon-ic_rightarrow"></text>
							</navigator>
						</view>
					</view>
				</view>
				<view class="dialog-foot">
					<view class="btn" @click="initCollage">发起拼单</view>
				</view>
			</view>
		</tuiDrawer>
		<!-- #endif -->
		<!-- 商品属性弹窗 -->
		<productWindow :attr="attr" :isShow="1" :iSplus="1" :iScart="1" :type="2" :storeInfo="storeInfo" @myevent="onMyEvent" @attrVal="attrVal" @ChangeAttr="ChangeAttr" @ChangeCartNum="ChangeCartNumDuo" @iptCartNum="iptCartNum" @goCat="goCatNum">
		</productWindow>
		<homeList :currentPage="currentPage" :navH="getHeight.barTop+40" :openNavList="diyProduct.navList"></homeList>
		<cartList :cartNums='cartNums' :cartData="cartData" isStoreCart @closeList="closeList" @ChangeCartNumDan="ChangeCartList" @ChangeSubDel="ChangeSubDel" @ChangeOneDel="ChangeOneDel"></cartList>
		<couponListWindow :coupon="coupon" :openType="0" @ChangCouponsClone="ChangCouponsClone" @ChangCouponsUseState="ChangCouponsUseState" @ChangCoupons="ChangCouponsClone" @tabCouponType="tabCouponType" v-if="coupon">
		</couponListWindow>
	</view>
</template>

<script>
	import filterPopup from "../components/filterPopup/index.vue";
	import Kefu from "../components/kefu/index.vue";
	import WaterfallsFlow from "@/components/WaterfallsFlow/WaterfallsFlow.vue";
	import productWindow from "@/components/productWindow/index.vue";
	import emptyPage from "@/components/emptyPage.vue";
	import homeList from "@/components/homeList/index.vue";
	import cartList from "@/components/cartList/index.vue";
	import tuiDrawer from "@/components/tui-drawer/tui-drawer.vue";
	import couponListWindow from '@/components/couponListWindow';
	import {
		mapState,
		mapGetters
	} from 'vuex';
	import {
		vcartList,
		cartDel,
		getCartCount,
	} from '@/api/order.js';
	import {
		getProducts,
		getCustomerList,
		getStoreCategory,
		getStoreBrand,
		storeCouponsApi,
	} from '@/api/new_store.js';
	import {
		getCoupons,
		setCouponReceive,
	} from '@/api/api.js';
	import {
		goShopDetail
	} from '@/libs/order.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import {
		// #ifdef MP || H5
		hasCollage,
		// #endif
		getAttr,
		initCollage,
		isWithin,
	} from '@/api/store.js';
	import {
		getAddressList,
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import skuSelect from '@/mixins/skuSelect.js';
	export default {
		components: {
			filterPopup,
			Kefu,
			WaterfallsFlow,
			productWindow,
			emptyPage,
			homeList,
			cartList,
			tuiDrawer,
			couponListWindow,
		},
		props: {
			info: {
				type: Object,
				default: () => ({})
			},
			customerType: {
				type: Number,
				default: 1
			},
			cart_num: {
				type: Number,
				default: 0
			},
		},
		mixins: [skuSelect],
		computed: {
			...mapGetters(['isLogin', 'uid', 'cartNum', 'diyProduct', 'diyCategory']),
			level() {
				return this.diyCategory.level
			},
		},
		provide: {
			parent: {}
		},
		data() {
			return {
				id: 0,
				getHeight: this.$util.getWXStatusHeight(),
				imgHost: HTTP_REQUEST_URL,
				price: 0,
				stock: 0,
				productList: [], // 商品数据
				customerList: [], // 客服数据
				storeCategory: [], // 分类数据
				storeBrand: [], // 品牌数据
				show: false,
				active: 1,
				where: {
					cid: 0,
					sid: 0,
					tid: 0,
					keyword: '',
					priceOrder: '',
					salesOrder: '',
					news: 0,
					page: 1,
					limit: 5,
					store_id: 0,
					brand_id: '',
				},
				loading: false,
				loadend: false,
				loadTitle: '加载更多',
				deliveryList: [{
						name: '到店',
						key: 2,
						icon: 'icon-ic_shop1'
					},
					{
						name: '配送',
						key: 1,
						icon: 'icon-ic_electromobile'
					},
				],
				switchNum: 1,
				// #ifdef H5 || MP
				collageVisible: false,
				collageDelivery: 2,
				collageIndex: 0,
				collageStore: {},
				// #endif
				drawervisible: true,
				footerOpen: true,
				totalPrice: 0,
				cartData: {
					cartList: [],
					iScart: false
				},
				attr: {
					cartAttr: false,
					productAttr: [],
					productSelect: {},
					deliveryType: [],
				},
				storeInfo: {},
				attrValue: '', //已选属性
				currentPage: false,
				tempArr: [],
				headerHeight: 0,
				conterTop: 0,
				scrollTop: 0, //已滚动的距离
				addressId: 0, // 配送地址id
				addressList: [], // 配送地址列表
				cartNums: 0,
				keyword: '',
				couponList: [],
				coupon: {
					coupon: false,
					type: -1,
					list: [],
					count: [],
					goFrom: 1
				},
			}
		},
		mounted() {
			let default_delivery = this.info.default_delivery;
			const deliveryType = this.info.delivery_type.map(Number).sort();
			this.deliveryList = [];
			if (deliveryType.includes(1) || deliveryType.includes(3)) {
				this.deliveryList.push({
					name: '配送',
					key: 1,
					icon: 'icon-ic_electromobile',
				});
			}
			if (deliveryType.includes(2)) {
				this.deliveryList.push({
					name: '到店',
					key: 2,
					icon: 'icon-ic_shop1',
				});
			}
			// for (let i = 0; i < this.deliveryList.length; i++) {
			// 	if (this.deliveryList[i].key == 2 && (!this.info.store_self_mention || this.info.delivery_type == 2)) {
			// 		this.deliveryList.splice(i, 1);
			// 		i--;
			// 	} else if (this.deliveryList[i].key == 1 && this.info.delivery_type == 3) {
			// 		this.deliveryList.splice(i, 1);
			// 		i--;
			// 	}
			// }
			if (this.deliveryList.length == 1) {
				default_delivery = this.deliveryList[0].key;
			}
			this.collageDelivery = default_delivery;
			this.collageStore = this.info;
			this.where.store_id = this.info.id;
			this.switchNumFun(default_delivery);
			uni.$off('newCartNum');
			uni.$on('newCartNum', (data) => {
				if (data.length) {
					this.tempArr.forEach((item) => {
						data.forEach(j => {
							if (item.id == j.product_id) {
								item.cart_num = 0
							}
						})
					})
					this.cartNums = 0;
					this.totalPrice = 0;
				}
			});
			// 监听到商品详情页，返回时的数据更新；
			uni.$off('goodsCartNum');
			uni.$on('goodsCartNum', (e) => {
				if (e.cartNum) {
					this.tempArr.forEach((item) => {
						if (item.id == e.id) {
							item.cart_num = e.cartNum
						}
					})
					this.getCartNum()
				}
			});
			this.$eventHub.$off('onCartAddChange');
			this.$eventHub.$on('onCartAddChange', (data) => {
				let index = -1;
				for (let i = 0; i < this.productList.length; i++) {
					if (this.productList[i].id == data.id) {
						index = i;
						break;
					}
				}
				if (data.spec_type) {
					this.goCartDuo(data);
				} else {
					this.goCartDan(data, index, 1);
				}
			});
			let view = uni.createSelectorQuery().in(this).select("#nav-box");
			// 页面滚动
			uni.$on('onPageScroll', ({
				scrollTop
			}) => {
				this.scrollTop = scrollTop;
				this.footerOpen = false;
				this.currentPage = false;
				view
					.boundingClientRect((data) => {
						this.conterTop = data.top;
					})
					.exec();
			});
			// 页面滚动到底部
			uni.$on('onReachBottom', () => {
				this.getProducts();
			});
			uni.$on('activeAddress', (id) => {
				this.getAddressList(id);
			});
			this.getProducts(true);
			this.getCartNum();
			this.getCartList(1);
			this.getAddressList();
		},
		methods: {
			productslist() {
				this.getProducts(true);
			},
			showMaoLocation() {
				let data = {
					latitude: Number(this.info.latitude),
					longitude: Number(this.info.longitude),
					name: this.info.name,
					address: `${this.info.address}${this.info.detailed_address}`,
				};
				// #ifdef H5
				if (this.$wechat.isWeixin()) {
					data.scale = 13;
					return this.$wechat.seeLocation(data);
				}
				// #endif
				uni.openLocation(data);
			},
			// 选择品牌
			brandChange(val) {
				this.where.brand_id = val
			},
			// 选择分类
			categoryChange(val) {
				this.where.cid = val.cid
				this.where.sid = val.sid
				this.where.tid = val.tid
			},
			// 确认提交
			submitFn(val) {
				if (val == 1) {
					this.getProducts(true)
					this.$refs.popup.visible = false
				} else if (val == 2) {
					this.where.brand_id = ""
					this.where.cid = ""
					this.where.sid = ""
					this.where.tid = ""
					this.getProducts(true)
					this.$refs.popup.visible = false
				}
			},
			// 打开附近门店
			goMap(val = 0) {
				uni.navigateTo({
					url: `/pages/store/store_list/index?storeFrom=1&type=1&storeId=${val ? this.collageStore.id : this.info.id}&isCollage=${val}`
				});
			},
			// 打开客服
			goKefu() {
				this.getCustomerList()
				this.$refs.kefu.show = true
			},
			// 购物车
			shoppCart() {
				uni.switchTab({
					url: "/pages/order_addcart/order_addcart"
				})
			},
			// 关闭客服
			closeKefu() {
				this.$refs.kefu.show = false
			},
			// 获取客服列表数据
			getCustomerList() {
				getCustomerList(this.where.store_id).then(res => {
					this.customerList = res.data
				})
			},
			// 去详情页
			godDetail(item) {
				this.currentPage = false
				goShopDetail(item, this.uid).catch(res => {
					uni.navigateTo({
						url: `/pages/goods_details/index?id=${item.id}&fromType=1`
					})
				})
			},
			// 操作
			set_where: function(e) {
				switch (e) {
					case 1:
						this.active = 1
						this.where.priceOrder = ""
						this.where.salesOrder = ""
						this.where.brand_id = ""
						this.price = 0
						break;
					case 2:
						if (this.price == 0) this.price = 1;
						else if (this.price == 1) this.price = 2;
						else if (this.price == 2) this.price = 0;
						if (this.price == 1) {
							this.where.priceOrder = "asc"
						} else if (this.price == 2) {
							this.where.priceOrder = "desc"
						} else {
							this.where.priceOrder = ""
						}
						this.active = 2
						this.stock = 0;

						break;
					case 3:
						if (this.stock == 0) this.stock = 1;
						else if (this.stock == 1) this.stock = 2;
						else if (this.stock == 2) this.stock = 0;

						if (this.stock == 1) {
							this.where.priceOrder = ""
							this.where.salesOrder = "asc"
						} else if (this.stock == 2) {
							this.where.priceOrder = ""
							this.where.salesOrder = "desc"
						} else {
							this.where.salesOrder = ""
						}
						this.active = 3
						this.price = 0
						break;
					case 4:
						if (!this.where.cid) {
							this.getStoreCategory()
							this.getStoreBrand()
						}
						this.$refs.popup.visible = true
						this.loadend = false;
						this.loading = false;
						break;
				}
				if (e < 4) {
					this.loadend = false;
					this.$set(this.where, 'page', 1);
					this.getProducts(true)
				}
			},
			// 商品列表
			getProducts(isPage) {
				let that = this;
				if (that.loadend) return;
				if (that.loading) return;
				if (isPage === true) {
					// that.$refs.waterfallsFlow.refresh();
					that.where.page = 1;
					that.$set(that, 'productList', []);
				}
				that.loading = true;
				that.loadTitle = '';
				that.where.delivery_type = that.delivery_type;
				that.where.keyword = that.keyword;
				getProducts(this.where).then(res => {
					let list = res.data;
					let productList = that.$util.SplitArray(list, that.productList);
					let loadend = list.length < that.where.limit;
					that.loadend = loadend;
					that.loading = false;
					that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					that.$set(that, 'productList', productList);
					that.$set(that.where, 'page', that.where.page + 1);
					that.$set(that, 'tempArr', productList);
					this.$nextTick(() => {
						const query = uni.createSelectorQuery().in(this);
						query
							.select("#input-box")
							.boundingClientRect((data) => {
								this.headerHeight = data.height;
							})
							.exec();
						// query
						// 	.select("#nav-box")
						// 	.boundingClientRect((data) => {
						// 		this.conterTop = data.top;
						// 	})
						// 	.exec();
					})

				}).catch(err => {
					that.loading = false;
					that.loadTitle = '加载更多'
				});
			},
			// 切换到店核销方式
			swithFn(num) {
				this.switchNumFun(num);
				this.where.page = 1;
				this.loadend = false;
				this.productList = []
				this.getProducts(true)
			},
			switchNumFun(num) {
				switch (num) {
					case 1:
						this.switchNum = 1
						this.delivery_type = '3'
						break;
					case 2:
						this.switchNum = 2
						this.delivery_type = '2'
						break;
				}
			},
			// 筛选-分类数据
			getStoreCategory() {
				let data = {
					pid: 0
				}
				if (this.info.product_category_status) {
					data.relation_id = this.info.id;
				}
				getStoreCategory(data).then(res => {
					res.data.map((item, index) => {
						// this.$set(item, 'disabled', false)
						this.$set(item, 'current', 0)
						// if (item.id==this.where.cid) {
						// 	this.$set(item, 'current', index)
						// }
						// item.children.unshift({
						// 	'id': 0,
						// 	'cate_name': '全部'
						// })
						if (!Array.isArray(item.children)) {
							item.children = [];
						}
						item.children.unshift({
							cate_name: '全部分类',
							id: 0,
							pid: item.id,
						});
						item.children.map((items, indexs) => {
							// this.$set(items, 'disabled', false)
							this.$set(items, 'current', 0)
							if (items.id == this.where.sid) {
								this.$set(items, 'current', indexs)
							}
							if (!Array.isArray(items.children)) {
								items.children = [];
							}
							items.children.unshift({
								cate_name: '全部商品',
								id: 0,
								pid: items.id,
							});
							// item.children.unshift({
							// 	'id': 0,
							// 	'cate_name': '全部'
							// })
						})
					})

					this.storeCategory = res.data
				})
			},
			// 筛选-品牌数据
			getStoreBrand() {
				getStoreBrand(this.where).then(res => {
					this.storeBrand = res.data
				})
			},
			// #ifdef H5 || MP
			onCollage() {
				if (!this.isLogin) {
					return toLogin();
				}
				hasCollage().then(res => {
					const collageId = res.data.collageId;
					if (collageId) {
						uni.navigateTo({
							url: `/pages/store/group_buy/index?collage_id=${collageId}`
						});
					} else {
						this.collageVisible = true;
					}
				});
			},
			onCollageClose() {
				this.collageVisible = false;
			},
			// 发起拼单
			initCollage() {
				if (this.collageDelivery == 2) {
					this.getInitCollage();
					return;
				}
				isWithin({
					store_id: this.collageStore.id,
					address_id: this.addressId
				}).then(() => {
					this.getInitCollage()
				}).catch(() => {
					this.$util.Tips({
						title: '您选择的地址超出门店配送范围，请重新选择'
					});
				});
			},
			getInitCollage() {
				let data = {
					store_id: this.collageStore.id,
					address_id: this.addressId || 0,
					shipping_type: this.collageDelivery == 1 ? 3 : 2
				};
				initCollage(data).then(res => {
					this.collageVisible = false;
					uni.navigateTo({
						url: `/pages/store/group_buy/index?collage_id=${res.data.collageId}`
					});
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				});
			},
			collageDeliveryChange(item, index) {
				// if (item.key != 2 || this.info.delivery_type != 2) {
					this.collageDelivery = item.key;
					this.collageIndex = index;
				// }
			},
			onAddressChange(data) {
				this.addressId = data.id;
			},
			// #endif
			// 生成订单；
			subOrder: function() {
				let that = this,
					list = that.cartData.cartList,
					ids = [];
				if (list.length) {
					list.forEach(item => {
						if (item.attrStatus && item.status) {
							ids.push(item.id)
						}
					});
					uni.navigateTo({
						url: '/pages/goods/order_confirm/index?is_store=1&cartId=' + ids.join(',') +
							'&delivery_type=' + that.delivery_type + '&store_id=' + that.info.id
					});
					that.cartData.iScart = false;
				} else {
					return that.$util.Tips({
						title: '请选择产品'
					});
				}
			},
			// 计算总价；
			getTotalPrice: function() {
				let that = this,
					list = that.cartData.cartList,
					totalPrice = 0.00;
				list.forEach(item => {
					if (item.attrStatus && item.status) {
						totalPrice = that.$util.$h.Add(totalPrice, that.$util.$h.Mul(item.cart_num, item
							.truePrice));
					}
				})
				// if(num){
				// 	data.forEach(item => {
				// 		totalPrice = that.$util.$h.Sub(totalPrice, that.$util.$h.Mul(item.cart_num, item.sum_price));
				// 	})
				// }
				that.$set(that, 'totalPrice', totalPrice);
			},
			getCartLists(iSshow) {
				if (this.footerOpen) {
					this.getCartList();
				} else {
					this.footerOpen = true;
				}
			},
			getCartList(iSshow) {
				if (!this.isLogin) {
					return false
				}
				let that = this;
				let data = {
					store_id: that.info.id
				};
				vcartList(data).then(res => {
					that.$set(that.cartData, 'cartList', res.data);
					if (res.data.length) {
						that.$set(that.cartData, 'iScart', iSshow ? false : !that.cartData.iScart);
					} else {
						that.$set(that.cartData, 'iScart', false);
					}
					// that.getTotalPrice();
				})
			},
			// 商品详情接口；
			getAttrs(id) {
				let that = this;
				getAttr(id, 0).then(res => {
					// uni.hideLoading();
					that.$set(that.attr, 'productAttr', res.data.productAttr);
					that.$set(that, 'productValue', res.data.productValue);
					that.$set(that, 'storeInfo', res.data.storeInfo);
					that.DefaultSelect();
				})
			},
			moreNav() {
				this.currentPage = !this.currentPage;
			},
			goodsListScroll() {
				this.footerOpen = false;
				this.currentPage = false;
			},
			getCartNum() {
				if (!this.isLogin) {
					return false
				}
				getCartCount({
					numType: 0,
					store_id: this.info.id,
				}).then(res => {
					this.cartNums = res.data.count;
					this.totalPrice = res.data.sum_price;
					this.getStoreCoupons();
				});
			},
			closeList(e) {
				this.$set(this.cartData, 'iScart', e);
			},
			getAddressList(id) {
				if (!this.isLogin) {
					return false
				}
				getAddressList({
					page: 1,
					limit: 100
				}).then(res => {
					let addressList = res.data;
					this.addressList = addressList;
					this.addressId = id ? id : addressList[0].id;
				})
			},
			inputConfirm() {
				if (!this.keyword) {
					return
				}
				this.where.page = 1;
				this.loadend = false;
				this.productList = [];
				this.getProducts();
			},
			getStoreCoupons() {
				storeCouponsApi({
					relation_id: this.info.id
				}).then(res => {
					this.couponList = res.data;
				});
			},
			getCouponList(type) {
				let that = this,
					obj = {
						page: 1,
						limit: 20,
						product_id: that.id ? that.id : '',
						type: type,
						relation_id: that.info.id,
					};
				getCoupons(obj).then(res => {
					let count = res.data.count;
					that.$set(that.coupon, 'count', count);
					if (type === undefined || type === null) {
						let indexs = 0,
							list = [];
						count.forEach((item, index) => {
							if (item != 0) {
								return that.indexs = index;
							}
						})
						that.$set(that.coupon, 'type', indexs);
						res.data.list.forEach(item => {
							item.unused = item.used.some((value) => value.status == '未使用');
							if (item.type == indexs) {
								list.push(item)
							}
						})
						that.$set(that.coupon, 'list', list);
					} else {
						that.$set(that.coupon, 'list', res.data.list);
					}
				});
			},
			couponTap() {
				this.getCouponList();
				this.coupon.coupon = true;
			},
			ChangCouponsClone() {
				this.coupon.coupon = false;
			},
			ChangCouponsUseState(index) {
				this.coupon.list[index].is_use = true;
				this.coupon.list[index].unused = true;
				// this.coupon.coupon = false;
			},
			getCoupon(id) {
				if (!this.isLogin) {
					toLogin();
				} else {
					//领取优惠券
					setCouponReceive(id).then(res => {
						this.$util.Tips({
							title: '领取成功'
						});
						this.getStoreCoupons();
					}).catch(error => {
						this.$util.Tips({
							title: error
						});
					});
				}
			},
			tabCouponType: function(type) {
				this.$set(this.coupon, 'type', type);
				this.getCouponList(type);
			},
		}
	}
</script>

<style lang="scss">
	.container {}

	.activeColor {
		color: var(--view-theme) !important;
	}

	.headerBg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;

		.image {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
		}

		.shade {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: linear-gradient(180deg, rgba(0, 0, 0, 0.4047) 0%, #F5F5F5 100%);
		}
	}

	.nav-box {
		height: 98rpx;
	}

	.nav {
		top: 0;
		right: 0;
		left: 0;
		z-index: 10;
		height: 98rpx;
		font-size: 30rpx;
		color: #666666;
		background: #F5F5F5;

		&.fixed {
			background: #FFFFFF;
		}

		.iconfont {
			margin-left: 6rpx;
			vertical-align: middle;
			font-size: 14rpx;
		}

		.icon-ic_sort {
			margin-left: 8rpx;
			font-size: 28rpx;
		}
	}

	.list {
		padding: 0 20rpx;
		background: #F5F5F5;
	}

	.list .waterList {
		margin-bottom: 60rpx;
	}

	.default {
		padding: 0 20rpx;
	}

	.input-wrapper {
		position: relative;
		height: 78rpx;
		padding: 0 24rpx;

		.input {
			flex: 1;
			min-width: 0;
			height: 58rpx;
			padding-left: 24rpx;
			border-radius: 29rpx;
			background: rgba(255, 255, 255, 0.2);
			font-size: 24rpx;
			color: #FFFFFF;

			.iconfont {
				margin-right: 16rpx;
				font-size: 32rpx;
			}

			.placeholder {
				color: #FFFFFF;
			}
		}

		.group-btn {
			width: 80rpx;
			height: 64rpx;
			border-radius: 50%;
			margin-left: 16rpx;

			.img {
				width: 100%;
				height: 100%;
			}
		}
	}

	/* #ifdef H5 || MP */
	.dialog {
		padding: 44rpx 20rpx 0;
		padding-bottom: constant(safe-area-inset-bottom);
		padding-bottom: env(safe-area-inset-bottom);
		border-radius: 40rpx 40rpx 0 0;
		background: linear-gradient(180deg, #F5F5F5 0%, #F5F5F5 0%, #FFFFFF 100%);
	}

	.dialog-head {
		padding: 0 24rpx;
		font-weight: 500;
		font-size: 32rpx;
		color: #333333;

		.btn {
			width: 40rpx;
			height: 40rpx;
			cursor: pointer;

			.iconfont {
				font-size: 40rpx;
				color: #BBBBBB;
			}
		}
	}

	.dialog-body {
		margin-top: 48rpx;

		.navbar {
			flex-shrink: 0;
			position: relative;
			height: 82rpx;

			.navbar-bg {
				position: absolute;
				top: 0;
				left: 0;
				width: 100%;
				height: 190rpx;
				border-radius: 16rpx 16rpx 0 0;
				background: #EEEEEE;
			}

			.active-bg {
				position: absolute;
				bottom: 0;
				left: 0;
				width: 100%;
				height: 96rpx;

				.image1 {
					width: 50%;
					height: 100%;
				}

				.image2 {
					width: 48rpx;
					height: 78rpx;
				}
			}

			.inner {
				position: absolute;
				bottom: 0;
				left: 0;
				width: 100%;
				height: 100%;

				.item {
					flex: 1;
					min-width: 0;
					font-size: 28rpx;
					line-height: 44rpx;
					color: #666666;

					.iconfont {
						display: none;
						margin-right: 8rpx;
						font-size: 36rpx;
					}

					&.on {
						font-weight: 500;
						font-size: 32rpx;
						color: var(--view-theme);

						.iconfont {
							display: inline-block;
						}
					}
				}
			}
		}

		.dialog-content {
			position: relative;
			border-radius: 0 0 16rpx 16rpx;
			background: #FFFFFF;

			&.btl {
				border-top-left-radius: 24rpx;
			}

			&.btr {
				border-top-right-radius: 24rpx;
			}

			.link {
				padding: 40rpx 32rpx;
				font-size: 22rpx;
				line-height: 30rpx;
				color: #999999;

				.title {
					margin-bottom: 16rpx;
					font-weight: 500;
					font-size: 28rpx;
					line-height: 40rpx;
					color: #333333;
				}

				.iconfont {
					font-size: 32rpx;
				}
			}
		}

		.address-box {
			.list {
				padding: 36rpx 32rpx;
				background-color: transparent;
			}

			.item {
				margin-top: 32rpx;
				color: #333333;

				&:first-child {
					margin-top: 0;
				}

				.text {
					flex: 1;
				}

				.info {
					font-weight: 500;
					font-size: 28rpx;
					line-height: 40rpx;
				}

				.name {
					margin-top: 8rpx;
					font-size: 22rpx;
					line-height: 30rpx;
					color: #999999;
				}

				.iconfont {
					visibility: hidden;
					font-size: 36rpx;
				}

				&.on {
					color: var(--view-theme);

					.name {
						color: var(--view-theme);
					}

					.iconfont {
						visibility: visible;
					}
				}
			}
		}

		.btn-box {
			height: 88rpx;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #666666;

			.iconfont {
				margin-left: 4rpx;
				font-size: 24rpx;
			}
		}

		.store-box {
			padding: 40rpx 28rpx 40rpx 32rpx;

			.text {
				flex: 1;
			}

			.name {
				font-weight: 500;
				font-size: 28rpx;
				line-height: 40rpx;
				color: #333333;
			}

			.info {
				margin-top: 16rpx;
				font-size: 22rpx;
				line-height: 30rpx;
				color: #999999;
			}

			.value {
				flex: 1;
			}

			.iconfont {
				margin-right: 12rpx;
				font-size: 22rpx;
			}

			.map-box {
				position: relative;
				width: 188rpx;
				height: 104rpx;
				margin-left: 32rpx;

				.map {
					width: 100%;
					height: 100%;
				}

				.img-box {
					position: absolute;
					bottom: 16rpx;
					left: 50%;
					width: 52rpx;
					height: 52rpx;
					padding: 6rpx;
					border-radius: 6rpx;
					background: #FFFFFF;
					box-shadow: 0rpx 0rpx 12rpx 0rpx rgba(0, 0, 0, 0.0784);
					transform: translateX(-50%);
				}

				.img {
					width: 100%;
					height: 100%;
					border-radius: 6rpx;
				}

				.range {
					position: absolute;
					top: -40rpx;
					left: 50%;
					height: 36rpx;
					padding: 0 16rpx;
					border-radius: 6rpx;
					background: #FFFFFF;
					box-shadow: 0rpx 0rpx 16rpx 0rpx rgba(0, 0, 0, 0.0784);
					transform: translateX(-50%);
					white-space: nowrap;
					font-size: 20rpx;
					line-height: 36rpx;
					color: #333333;

					&::after {
						content: "";
						position: absolute;
						bottom: 0;
						left: 50%;
						width: 0;
						height: 0;
						border-width: 5rpx 9rpx;
						border-style: solid;
						border-color: #FFFFFF transparent transparent;
						transform: translate(-50%, 100%);
					}
				}

				.dot {
					position: absolute;
					bottom: -10rpx;
					left: 50%;
					width: 6rpx;
					height: 6rpx;
					border-radius: 50%;
					background: var(--view-theme);
					transform: translateX(-50%);
				}
			}
		}
	}

	.dialog-foot {
		padding: 20rpx 0;

		.btn {
			height: 72rpx;
			border-radius: 36rpx;
			background: var(--view-theme);
			text-align: center;
			font-weight: 500;
			font-size: 26rpx;
			line-height: 72rpx;
			color: #FFFFFF;
		}
	}

	/* #endif */
	.page {
		position: relative;
	}

	.store-section {
		padding: 20rpx;

		.panel {
			padding: 0 32rpx 32rpx;
			border-radius: 16rpx;
			background: #FFFFFF;
		}

		.store-name {
			height: 92rpx;

			.name-wrap {
				flex: 1;
				min-width: 0;
			}

			.name-inner {
				display: inline-flex;
				max-width: 100%;
			}

			.name {
				flex: 1;
				min-width: 0;
				margin: 0 8rpx 0 10rpx;
				font-weight: 500;
				font-size: 30rpx;
				color: #333333;
			}

			.iconfont {
				font-size: 32rpx;
				color: #333333;
			}

			.tips {
				font-size: 24rpx;
				color: var(--view-theme);
			}
		}

		.address-wrap {
			.address-box {
				flex: 1;
				min-width: 0;
				font-size: 22rpx;
				color: #666666;
			}

			.address-info {
				line-height: 34rpx;
			}

			.distance {
				position: relative;
				padding-right: 12rpx;
				margin-right: 12rpx;

				&::after {
					content: "";
					position: absolute;
					top: 50%;
					right: 0;
					width: 1rpx;
					height: 18rpx;
					background: #CCCCCC;
					transform: translateY(-50%);
				}
			}
		}

		.switch {
			border-radius: 23rpx;

			.btn {
				border-radius: 19rpx;

				&.on {
					background: var(--view-theme);
					color: #FFFFFF;
				}
			}
		}
	}

	.line-left {
		position: absolute;
		top: 50%;
		left: 0;
		width: 1rpx;
		height: 26rpx;
		background: #CCCCCC;
		transform: translateY(-50%);
	}

	.filter-btn {
		position: relative;
		padding: 0 32rpx;
	}

	.coupon {
		border: 1px solid var(--view-theme);
	}

	.coupon-left {
		border-right: 1px dashed var(--view-theme);
	}

	.coupon-left::before {
		content: "";
		position: absolute;
		width: 4rpx;
		height: 4rpx;
		top: -2px;
		right: -2px;
		border-radius: 50%;
		border-width: 1px;
		border-style: solid;
		border-color: transparent var(--view-theme) var(--view-theme) var(--view-theme);
		background-color: #FFFFFF;
	}

	.coupon-left::after {
		content: "";
		position: absolute;
		width: 4rpx;
		height: 4rpx;
		bottom: -2px;
		right: -2px;
		border-radius: 50%;
		border-width: 1px;
		border-style: solid;
		border-color: var(--view-theme) var(--view-theme) transparent var(--view-theme);
		background-color: #FFFFFF;
	}
</style>