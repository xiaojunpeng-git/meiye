<template>
	<view>
		<view class="headerBg">
			<image :src="info.background_image" mode="aspectFill" class="image"></image>
			<view class="shade"></view>
			<!-- #ifdef MP || APP -->
			<view :style="{height: getHeight.barTop+'px'}"></view>
			<view :style="{height: getHeight.barHeight+'px'}"></view>
			<!-- #endif -->
			<view style="height: 224rpx;"></view>
		</view>
		<view class="page acea-row row-column">
			<view class="search-box" :style="{height: headerHeight ? (headerHeight+'px') : 'auto'}">
				<view class="input-box" id="input-box" :class="{fixed: scrollTop > 0}">
					<!-- #ifdef MP || APP -->
					<view :style="{height: getHeight.barTop+'px'}"></view>
					<!-- #endif -->
					<!-- 顶部搜索框 -->
					<!-- #ifdef MP -->
					<view class="input-wrapper acea-row row-middle h-78 relative" :style="{height: getHeight.barHeight+'px'}">
						<view class="menu_box flex-center" @tap="moreNav">
							<text class="iconfont icon-ic_menu3"></text>
						</view>
						<!-- <navigator url="/pages/goods/goods_search/index" class="input acea-row row-middle" hover-class="none">
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
					<view class="input-wrapper acea-row row-middle h-78 relative">
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
			<!-- 门店地址详情 -->
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
					<view class="address-wrap acea-row row-bottom">
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
			<view class="category acea-row" v-if="level == 3">
				<scroll-view scroll-x="true" class="scroll-view" :scroll-into-view="navOneActiveId" scroll-with-animation>
					<view class="item" :id="`navOne${index}`" :class="{ on: navOneActive == index }" v-for="(item,index) in categoryList" :key="item.id" @click="tapNavOne(item,index)">
						<view class="img-box flex-center">
							<image :src="item.pic" class="img"></image>
						</view>
						<view class="title line1">{{item.cate_name}}</view>
					</view>
				</scroll-view>
				<view class="btn acea-row row-column row-center row-middle" @click="navOneOpen">
					<view class="">全</view>
					<view class="">部</view>
					<view class="iconfont icon-ic_more2 mt-4 fs-26"></view>
					<image src="../static/shade.png" class="img"></image>
				</view>
			</view>
			<view class="conter acea-row" id="conter-box">
				<view class="aside-box" id="aside" :style="{ height: asideHeight }">
					<scroll-view scroll-y="true" class="aside" :class="{ fixed: conterTop <= headerHeight && scrollTop }" :style="{ top: headerHeight + 'px' }">
						<view class="item" :class="{
								on: index==navActive,
								btr: index-1==navActive,
								bbr: index+1==navActive
							}" v-for="(item,index) in categoryErList" :key="index" @click="tapNav(index,item)">
							<view class="inner flex-center">
								<view class="line1">{{item.cate_name}}</view>
							</view>
						</view>
						<view class="cartIcon-placeholder"></view>
						<view class="safe-area-inset-bottom"></view>
					</scroll-view>
				</view>
				<view class="wrapper" :style="{ paddingTop: categoryThreeList.length ? 0 : '32rpx' }">
					<view class="bgcolor-box" v-if="categoryThreeList.length">
						<view class="bgcolor acea-row" :class="{ fixed: conterTop <= headerHeight && scrollTop }" :style="{ top: headerHeight + 'px' }">
							<view class="longTab acea-row row-middle">
								<scroll-view scroll-x="true" style="white-space: nowrap;" :scroll-into-view="navThreeActiveId" scroll-with-animation>
									<view class="longItem" :id="`navThree${index}`" :style='"width:"+isWidth+"px"' :class="index===tabClick?'click':''" v-for="(item,index) in categoryThreeList" :key="index" @click="longClick(index,item)">
										<view class="inner line1">{{item.cate_name}}</view>
									</view>
								</scroll-view>
							</view>
							<view class="openList">
								<view class="btn" @click="openTap">
									<text class="iconfont icon-ic_downarrow"></text>
								</view>
								<image src="../static/shade.png" class="img"></image>
							</view>
						</view>
					</view>
					<!--商品列表 -->
					<goodClass :tempArr="tempArr" :isLogin="isLogin" @gocartduo="goCartDuo" @gocartdan="goCartDan" @ChangeCartNumDan="ChangeCartNumDan" @detail="goDetail"></goodClass>
					<view class='loadingicon acea-row row-center-wrapper' v-if="tempArr.length">
						<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
					</view>
					<emptyPage v-if="page>1 && !tempArr.length" title="暂无商品，去看点别的吧～"></emptyPage>
					<view class="safe-area-inset-bottom"></view>
				</view>
			</view>
			<view class="footer">
				<view class="inner-box" :class="{ open: footerOpen }">
					<view class="inner acea-row row-between row-middle">
						<view class="items-baseline inline-flex h-60 lh-60rpx overflow" style="flex-wrap: nowrap;">
							<BaseMoney :money="totalPrice" symbolSize="32" integerSize="48" decimalSize="32" color="#FFFFFF"></BaseMoney>
							<view style="white-space: nowrap;" class="detail-btn lh-34rpx" @click="getCartLists" v-show="cartData.iScart">收起 <text class="iconfont icon-ic_downarrow fs-24"></text> </view>
							<view style="white-space: nowrap;" class="detail-btn lh-34rpx" @click="getCartLists" v-show="!cartData.iScart">查看明细 <text class="iconfont icon-ic_uparrow fs-24"></text></view>
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
		<!-- </scroll-view> -->
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
							<view class="list" id="list">
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
		<!-- 分类购物车下拉列表 -->
		<cartList :cartNums='cartNums' :cartData="cartData" isStoreCart @closeList="closeList" @ChangeCartNumDan="ChangeCartList" @ChangeSubDel="ChangeSubDel" @ChangeOneDel="ChangeOneDel"></cartList>
		<!-- 产品属性组件 -->
		<productWindow :attr="attr" :isShow='1' :iSplus='1' :iScart='1' :type="2" :storeInfo="storeInfo" @myevent="onMyEvent" @ChangeAttr="ChangeAttr" @ChangeCartNum="ChangeCartNumDuo" @attrVal="attrVal" @iptCartNum="iptCartNum" @goCat="goCatNum" id='product-window'></productWindow>
		<!-- 客服弹窗 -->
		<Kefu ref="kefu" @closeKefu="closeKefu" :customerList="customerList" :customerType="customerType"></Kefu>
		<homeList :currentPage="currentPage" :navH="getHeight.barTop+40" :openNavList="diyProduct.navList"></homeList>
		<tuiDrawer :visible="!iSlong" mode="top" backgroundColor="transparent" @close="closeTap">
			<view class="downTab acea-row row-column">
				<!-- #ifdef MP || APP -->
				<view :style="{height: getHeight.barTop+'px'}"></view>
				<view :style="{height: getHeight.barHeight+'px'}"></view>
				<!-- #endif -->
				<view class="title">{{categoryTitle}}</view>
				<scroll-view scroll-y="true" class="scroll-view">
					<view class="children">
						<view class="acea-row">
							<view class="item min-w-154" :class="index===tabClick?'click':''" v-for="(item,index) in categoryThreeList" :key="index" @click="longClick(index,item)">
								<view class="inner pr-28 pl-28">{{item.cate_name}}</view>
							</view>
						</view>
					</view>
				</scroll-view>
				<view class="btn acea-row row-center-wrapper" @click="closeTap">
					点击收起<text class="iconfont icon-ic_uparrow"></text>
				</view>
			</view>
		</tuiDrawer>
		<tuiDrawer :visible="oneVisible" mode="top" backgroundColor="transparent" @close="navOneClose">
			<view class="category-dialog acea-row row-column">
				<!-- #ifdef MP || APP -->
				<view :style="{height: getHeight.barTop+'px'}"></view>
				<view :style="{height: getHeight.barHeight+'px'}"></view>
				<!-- #endif -->
				<scroll-view scroll-y="true" class="scroll-view">
					<view class="acea-row" style="flex-wrap: wrap;">
						<view class="item" :class="{ on: navOneActive == index }" v-for="(item,index) in categoryList" :key="item.id" @tap="tapNavOne(item,index)">
							<view class="img-box">
								<image :src="item.pic" class="img"></image>
							</view>
							<view class="title">{{item.cate_name}}</view>
						</view>
					</view>
				</scroll-view>
				<view class="btn acea-row row-center-wrapper" @tap="navOneClose">
					点击收起<text class="iconfont icon-ic_uparrow"></text>
				</view>
			</view>
		</tuiDrawer>
		<couponListWindow :coupon="coupon" :openType="0" @ChangCouponsClone="ChangCouponsClone" @ChangCouponsUseState="ChangCouponsUseState" @ChangCoupons="ChangCouponsClone" @tabCouponType="tabCouponType" v-if="coupon">
		</couponListWindow>
	</view>
</template>

<script>
	import {
		vcartList,
		cartDel,
		getCartCount,
	} from '@/api/order.js';
	import {
		getCategoryList,
		getAttr,
		postCartNum,
		initCollage,
		hasCollage,
		isWithin,
	} from '@/api/store.js';
	import {
		getProducts,
		getCustomerList,
		getStoreCategory,
		getStoreBrand,
		storeCouponsApi,
	} from '@/api/new_store.js';
	import {
		getAddressList,
	} from '@/api/user.js';
	import {
		getCoupons,
		setCouponReceive,
	} from '@/api/api.js';
	import productWindow from '@/components/productWindow/index';
	import goodClass from '../components/goodClass/index.vue';
	import cartList from '@/components/cartList';
	import Kefu from '../components/kefu/index.vue';
	import homeList from '@/components/homeList/index.vue';
	import tuiDrawer from '@/components/tui-drawer/tui-drawer.vue';
	import emptyPage from '@/components/emptyPage.vue';
	import couponListWindow from '@/components/couponListWindow';
	import {
		mapState,
		mapGetters
	} from 'vuex';
	import {
		goShopDetail
	} from '@/libs/order.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		computed: {
			...mapState({
				cartNum: state => state.indexData.cartNum
			}),
			...mapGetters(['isLogin', 'uid', 'cartNum', 'diyCategory', 'diyProduct']),
			level() {
				return this.diyCategory.level
			}
		},
		components: {
			productWindow,
			goodClass,
			cartList,
			Kefu,
			homeList,
			tuiDrawer,
			emptyPage,
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
		},
		provide: {
			parent: {}
		},
		data() {
			return {
				getHeight: this.$util.getWXStatusHeight(),
				imgHost: HTTP_REQUEST_URL,
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
				categoryList: [],
				navOneActive: 0,
				navActive: 0,
				categoryTitle: '',
				categoryErList: [],
				tabLeft: 0,
				isWidth: 0, //每个导航栏占位
				tabClick: 0, //导航栏被点击
				iSlong: true,
				tempArr: [],
				loading: false,
				loadend: false,
				loadTitle: '加载更多',
				page: 1,
				limit: 10,
				cid: 0, //一级分类
				sid: 0, //二级分类
				tid: 0, //三级分类
				delivery_type: 3, // 配送方式
				isAuto: false, //没有授权的不会自动授权
				attr: {
					cartAttr: false,
					productAttr: [],
					productSelect: {},
					deliveryType: [],
				},
				productValue: [],
				attrValue: '', //已选属性
				storeName: '', //多属性产品名称
				id: 0,
				cartData: {
					cartList: [],
					iScart: false
				},
				totalPrice: 0.00,
				lengthCart: 0,
				cart_num: 0,
				storeInfo: {},
				// #ifdef H5 || MP
				collageVisible: false,
				collageDelivery: 2,
				collageIndex: 0,
				collageStore: this.info,
				collageAddress: {}, // 选择的配送地址
				// #endif
				customerList: [], // 客服数据
				footerOpen: true,
				categoryTwoList: [],
				categoryThreeList: [],
				currentPage: false,
				addressList: [], // 地址列表
				oneVisible: false,
				headerHeight: 0,
				conterTop: 0,
				scrollTop: 0, //已滚动的距离
				addressId: 0, // 配送地址id
				cartNums: 0, //门店购物车总数量；
				listH: 0,
				keyword: '',
				asideHeight: 'auto',
				couponList: [],
				coupon: {
					coupon: false,
					type: -1,
					list: [],
					count: [],
					goFrom: 1
				},
				navOneActiveId: '',
				navThreeActiveId: '',
			}
		},
		watch: {
			// #ifdef H5 || MP
			collageDelivery: {
				handler(val) {
					this.collageStore = this.info;
				},
				immediate: true
			},
			// #endif
			categoryErList: {
				handler(val) {
					this.$nextTick(() => {
						const query = uni.createSelectorQuery().in(this);
						query
							.select("#aside")
							.boundingClientRect((data) => {
								this.asideHeight = data.height + 'px';
							})
							.exec();
					})
				},
				deep: true
			}
		},
		mounted() {
			let that = this;
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
			// 获取设备宽度
			uni.getSystemInfo({
				success(e) {
					that.isWidth = e.windowWidth / 5
					that.windowHeight = e.windowHeight
				}
			});
			// 监听到支付订单页，返回时的数据更新；
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
			})
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
			// 页面滚动
			uni.$on('scroll', ({
				scrollTop
			}) => {
				this.$nextTick(() => {
					let view = uni.createSelectorQuery().in(this).select("#conter-box");
					this.scrollTop = scrollTop;
					this.footerOpen = false;
					this.currentPage = false;
					view
						.boundingClientRect((data) => {
							this.conterTop = data.top;
						})
						.exec();
				})
			});
			// 页面滚动到底部
			uni.$on('onReachBottom', () => {
				this.getProducts();
			});
			uni.$on('activeAddress', (id) => {
				this.getAddressList(id);
			});
			this.switchNumFun(default_delivery);
			that.getAllCategory();
			that.getCartNum();
			that.getAddressList();
		},
		methods: {
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
			// #ifdef H5 || MP
			// 点击拼单
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
					this.getInitCollage();
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
			// 选择配送地址
			onAddressChange(data) {
				this.addressId = data.id;
			},
			onCollageClose() {
				this.collageVisible = false;
			},
			// 选择到店核销、配送
			collageDeliveryChange(item, index) {
				// if (item.key != 2 || this.info.delivery_type != 2) {
					this.collageDelivery = item.key;
					this.collageIndex = index;
				// }
			},
			// #endif
			getNoCart() {
				if (this.cartNum <= 0) {
					this.tempArr.forEach((item) => {
						item.cart_num = 0
					})
					this.totalPrice = 0;
				}
			},
			// 商品列表
			getProducts() {
				let that = this;
				if (that.loadend) return;
				if (that.loading) return;
				that.loading = true;
				getProducts({
					page: that.page,
					limit: that.limit,
					type: 1,
					cid: that.cid,
					sid: that.sid,
					tid: that.tid,
					store_id: that.info.id,
					delivery_type: that.delivery_type,
					keyword: that.keyword,
				}).then(res => {
					let list = res.data,
						loadend = list.length < that.limit;
					that.tempArr = that.$util.SplitArray(list, that.tempArr);
					that.$set(that, 'tempArr', that.tempArr);
					that.loading = false;
					that.loadend = loadend;
					that.loadTitle = loadend ? "没有更多内容啦~" : "加载更多";
					that.page = that.page + 1;
					this.$nextTick(() => {
						const query = uni.createSelectorQuery().in(this);
						query
							.select("#input-box")
							.boundingClientRect((data) => {
								this.headerHeight = data.height;
							})
							.exec();
					})
				}).catch(err => {
					that.loading = false;
					that.loadTitle = '加载更多'
				});
			},
			// 切换到店核销方式
			swithFn(num) {
				this.switchNumFun(num);
				this.page = 1;
				this.loadend = false;
				this.tempArr = []
				this.getProducts()
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
			// 跳转到门店列表
			goMap() {
				uni.navigateTo({
					// #ifdef APP
					url: `/pages/store/mapnvue/index?province=${this.info.province}&city=${this.info.city}&area=${this.info.area}`
					// #endif
					// #ifndef APP
					url: `/pages/store/map/index?province=${this.info.province}&city=${this.info.city}&area=${this.info.area}`
					// #endif
				})
			},
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
				that.$set(that, 'totalPrice', totalPrice);
			},
			ChangeSubDel: function(event) {
				let that = this,
					list = that.cartData.cartList,
					ids = [],
					storeId = uni.getStorageSync('user_store_id');
				list.forEach(item => {
					ids.push(item.id)
				});
				cartDel(ids.join(","), storeId).then(res => {
					that.$set(that.cartData, 'cartList', []);
					that.cartData.iScart = false;
					that.totalPrice = 0.00;
					that.page = 1;
					that.loadend = false;
					that.tempArr = [];
					that.getProducts();
					that.getCartNum();
				})
			},
			ChangeOneDel: function(id, index) {
				let that = this,
					list = that.cartData.cartList,
					storeId = uni.getStorageSync('user_store_id');
				cartDel(id.toString(), storeId).then(res => {
					list.splice(index, 1);
					if (!list.length) {
						that.cartData.iScart = false;
						that.page = 1;
						that.loadend = false;
						that.tempArr = [];
						that.getProducts();
					};
					that.getCartNum();
				})
			},
			getCartLists() {
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
				vcartList({
					store_id: that.info.id
				}).then(res => {
					that.$set(that.cartData, 'cartList', res.data);
					if (res.data.length) {
						that.$set(that.cartData, 'iScart', iSshow ? false : !that.cartData.iScart);
					} else {
						that.$set(that.cartData, 'iScart', false);
					}
					// that.getTotalPrice();
				})
			},
			closeList(e) {
				this.$set(this.cartData, 'iScart', e);
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
			onMyEvent: function() {
				this.$set(this.attr, 'cartAttr', false);
			},
			/**
			 * 默认选中属性
			 * 
			 */
			DefaultSelect: function() {
				let productAttr = this.attr.productAttr;
				let value = [];
				for (let key in this.productValue) {
					if (this.productValue[key].stock > 0) {
						value = this.attr.productAttr.length ? key.split(",") : [];
						break;
					}
				}
				for (let i = 0; i < productAttr.length; i++) {
					this.$set(productAttr[i], "index", value[i]);
				}
				//sort();排序函数:数字-英文-汉字；
				let productSelect = this.productValue[value.join(",")];
				if (productSelect && productAttr.length) {
					this.$set(
						this.attr.productSelect,
						"store_name",
						this.storeName
					);
					this.$set(this.attr.productSelect, "image", productSelect.image);
					this.$set(this.attr.productSelect, "price", productSelect.price);
					this.$set(this.attr.productSelect, "stock", productSelect.stock);
					this.$set(this.attr.productSelect, "unique", productSelect.unique);
					this.$set(this.attr.productSelect, "cart_num", 1);
					this.$set(this.attr.productSelect, 'vip_price', productSelect.vip_price);
					this.$set(this, "attrValue", value.join(","));
				} else if (!productSelect && productAttr.length) {
					this.$set(
						this.attr.productSelect,
						"store_name",
						this.storeName
					);
					this.$set(this.attr.productSelect, "image", this.storeInfo.image);
					this.$set(this.attr.productSelect, "price", this.storeInfo.price);
					this.$set(this.attr.productSelect, "stock", 0);
					this.$set(this.attr.productSelect, "unique", "");
					this.$set(this.attr.productSelect, "cart_num", 0);
					this.$set(this, "attrValue", "");
					this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
				} else if (!productSelect && !productAttr.length) {
					this.$set(
						this.attr.productSelect,
						"store_name",
						this.storeName
					);
					this.$set(this.attr.productSelect, "image", this.storeInfo.image);
					this.$set(this.attr.productSelect, "price", this.storeInfo.price);
					this.$set(this.attr.productSelect, "stock", this.storeInfo.stock);
					this.$set(
						this.attr.productSelect,
						"unique",
						this.storeInfo.unique || ""
					);
					this.$set(this.attr.productSelect, "cart_num", 1);
					this.$set(this, "attrValue", "");
					this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
				}
			},
			/**
			 * 属性变动赋值
			 * 
			 */
			ChangeAttr: function(res) {
				let productSelect = this.productValue[res];
				if (productSelect && productSelect.stock > 0) {
					this.$set(this.attr.productSelect, "image", productSelect.image);
					this.$set(this.attr.productSelect, "price", productSelect.price);
					this.$set(this.attr.productSelect, "stock", productSelect.stock);
					this.$set(this.attr.productSelect, "unique", productSelect.unique);
					this.$set(this.attr.productSelect, 'vip_price', productSelect.vip_price);
					this.$set(this.attr.productSelect, "cart_num", 1);
					this.$set(this, "attrValue", res);
				} else if (productSelect && productSelect.stock == 0) {
					this.$set(this.attr.productSelect, "image", productSelect.image);
					this.$set(this.attr.productSelect, "price", productSelect.price);
					this.$set(this.attr.productSelect, "stock", 0);
					this.$set(this.attr.productSelect, "unique", "");
					this.$set(this.attr.productSelect, 'vip_price', productSelect.vip_price);
					this.$set(this.attr.productSelect, "cart_num", 0);
					this.$set(this, "attrValue", "");
				} else {
					this.$set(this.attr.productSelect, "image", this.storeInfo.image);
					this.$set(this.attr.productSelect, "price", this.storeInfo.price);
					this.$set(this.attr.productSelect, "stock", 0);
					this.$set(this.attr.productSelect, "unique", "");
					this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
					this.$set(this.attr.productSelect, "cart_num", 0);
					this.$set(this, "attrValue", "");
				}
			},
			attrVal(val) {
				this.$set(this.attr.productAttr[val.indexw], 'index', this.attr.productAttr[val.indexw].attr_values[val
					.indexn]);
			},
			/**
			 * 购物车手动填写
			 * 
			 */
			iptCartNum: function(e) {
				this.$set(this.attr.productSelect, 'cart_num', e);
			},
			// 点击默认单属性购物车
			goCartDan(item, index) {
				if (!this.isLogin) {
					this.getIsLogin();
				} else {
					this.tempArr[index].cart_num = 1;
					// this.$store.commit('indexData/setCartNum', parseInt(this.cartNum) + 1)
					this.$set(this, 'tempArr', this.tempArr);
					this.goCat(0, item.id, 1);
				}
			},
			// 改变单属性购物车
			ChangeCartNumDan(changeValue, index, item) {
				let num = this.tempArr[index];
				let stock = this.tempArr[index].stock;
				this.ChangeCartNum(changeValue, num, stock, 0, item.id);
			},
			// 改变多属性购物车
			ChangeCartNumDuo(changeValue) {
				//获取当前变动属性
				let productSelect = this.productValue[this.attrValue];
				//如果没有属性,赋值给商品默认库存
				if (productSelect === undefined && !this.attr.productAttr.length)
					productSelect = this.attr.productSelect;
				//无属性值即库存为0；不存在加减；
				if (productSelect === undefined) return;
				let stock = productSelect.stock || 0;
				let num = this.attr.productSelect;
				this.ChangeCartNum(changeValue, num, stock, 1);
			},
			// 已经加入购物车时的购物加减；
			ChangeCartList(changeValue, index) {
				let list = this.cartData.cartList;
				let num = list[index];
				let stock = list[index].trueStock;
				this.ChangeCartNum(changeValue, num, stock, 0, num.product_id, index, 1);
				if (!list.length) {
					this.cartData.iScart = false;
					this.page = 1;
					this.loadend = false;
					this.tempArr = [];
					this.getProducts();
				}
			},
			// 购物车加减计算函数
			ChangeCartNum(changeValue, num, stock, isDuo, id, index, cart) {
				let cartNum = this.cartNum;
				if (changeValue) {
					num.cart_num++;
					cartNum = isDuo ? this.cartNum : parseInt(cartNum) + 1;
					if (num.cart_num > stock) {
						if (isDuo) {
							this.$set(this.attr.productSelect, "cart_num", stock ? stock : 1);
							this.$set(this, "cart_num", stock ? stock : 1);
						} else {
							cartNum = this.cartNum + stock;
							num.cart_num = stock ? stock : 0;
							this.$set(this, 'tempArr', this.tempArr);
							this.$set(this.cartData, 'cartList', this.cartData.cartList);
						}
						return this.$util.Tips({
							title: "该产品没有更多库存了"
						});
					} else {
						if (!isDuo) {
							if (cart) {
								this.goCat(0, id, 1, 1, num.product_attr_unique);
								// this.getTotalPrice();
								this.tempArr.forEach((item) => {
									if (item.id == id) {
										item.cart_num++;
									}
								})
							} else {
								this.goCat(0, id, 1);
							}
						} else {
							this.tempArr.forEach((item) => {
								if (item.id == id) {
									item.cart_num++;
								}
							})
						}
					}
				} else {
					num.cart_num--;
					cartNum = isDuo ? this.cartNum : parseInt(cartNum) - 1;
					if (num.cart_num == 0) {
						this.cartData.cartList.splice(index, 1);
						if (isDuo) {
							this.$set(this.attr.productSelect, "cart_num", 1);
							this.$set(this, "cart_num", 1);
						}
					}
					if (num.cart_num < 0) {
						if (isDuo) {
							this.$set(this.attr.productSelect, "cart_num", 1);
							this.$set(this, "cart_num", 1);
						} else {
							cartNum = this.cartNum
							num.cart_num = 0;
							this.$set(this, 'tempArr', this.tempArr);
							this.$set(this.cartData, 'cartList', this.cartData.cartList);
						}
					} else {
						if (!isDuo) {
							if (cart) {
								this.goCat(0, id, 0, 1, num.product_attr_unique);
								// this.getTotalPrice();
								this.tempArr.forEach((item) => {
									if (item.id == id) {
										item.cart_num--;
									}
								})
							} else {
								this.goCat(0, id, 0);
							}
						} else {
							this.tempArr.forEach((item) => {
								if (item.id == id) {
									item.cart_num--;
								}
							})
						}
					}
				}
				// this.$store.commit('indexData/setCartNum', cartNum)
			},
			// 多规格加入购物车；
			goCatNum() {
				this.goCat(1, this.id, 1);
			},
			/*
			 * 加入购物车
			 */
			goCat: function(duo, id, type, cart, unique) {
				let that = this;
				if (duo) {
					let productSelect = that.productValue[this.attrValue];
					//如果有属性,没有选择,提示用户选择
					if (
						that.attr.productAttr.length &&
						productSelect === undefined
					)
						return that.$util.Tips({
							title: "产品库存不足，请选择其它属性"
						});
				}
				let q = {
					product_id: id,
					num: duo ? that.attr.productSelect.cart_num : 1,
					type: type,
					unique: duo ? that.attr.productSelect.unique : cart ? unique : ""
				};
				postCartNum(q)
					.then(function(res) {
						if (duo) {
							// that.$store.commit('indexData/setCartNum', parseInt(that.cartNum) + that.attr.productSelect.cart_num)
							that.attr.cartAttr = false;
							that.$util.Tips({
								title: "添加购物车成功"
							});
							// that.page = 1;
							// that.loadend = false;
							that.tempArr.forEach((item, index) => {
								if (item.id == that.id) {
									let arrtStock = that.attr.productSelect.stock
									let objNum = parseInt(item.cart_num) + parseInt(that.attr.productSelect
										.cart_num);
									item.cart_num = objNum > arrtStock ? arrtStock : objNum
								}
							})
							// that.productslist();
						}
						that.getCartNum();
						if (!cart) {
							that.getCartList(1);
						}
					})
					.catch(err => {
						return that.$util.Tips({
							title: err
						});
					});
			},
			goCartDuo(item) {
				if (!this.isLogin) {
					this.getIsLogin();
				} else {
					// uni.showLoading({
					//   title: '加载中'
					// });
					this.storeName = item.store_name;
					this.getAttrs(item.id);
					this.$set(this, 'id', item.id);
					this.$set(this.attr, 'cartAttr', true);
				}
			},
			getIsLogin() {
				toLogin();
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
			// 去详情页
			goDetail(item) {
				goShopDetail(item, this.uid).catch(res => {
					uni.navigateTo({
						url: `/pages/goods_details/index?id=${item.id}&fromType=1`
					});
				});
			},
			openTap() {
				this.iSlong = false
			},
			closeTap() {
				this.iSlong = true
			},
			appendAllItem(allCategory) {
				return allCategory.map(item => {
					if (!Array.isArray(item.children)) {
						item.children = [];
					}
					if (item.id) {
						item.children.unshift({
							cate_name: '全部商品',
							id: 0
						});
					}
					this.appendAllItem(item.children);
					return item;
				});
			},
			// 分类数据
			getAllCategory: function() {
				let that = this;
				let params = {};
				if (this.info.product_category_status) {
					params.relation_id = this.info.id;
				}
				getStoreCategory(params).then(res => {
					let data = this.appendAllItem(res.data);
					that.categoryList = data;
					if (that.level == 2) {
						that.categoryErList = that.categoryList;
					} else {
						that.categoryErList = that.categoryList[0].children ? that.categoryList[0].children : [];
					}
					that.categoryThreeList = that.categoryErList[0].children ? that.categoryErList[0]
						.children : [];
					that.categoryTitle = that.categoryList[0].cate_name;
					that.cid = that.categoryList[0].id;
					if (that.level == 2) {
						that.sid = that.categoryThreeList[0].id;
					} else {
						that.sid = that.categoryErList[0].id;
					}
					// that.switchNumFun(that.info.default_delivery);
					that.getProducts();
				})
			},
			tapNav(index, item) {
				this.navActive = index;
				this.tabClick = 0;
				this.categoryThreeList = item.children || [];
				if (this.level == 2) {
					this.cid = item.id;
					this.sid = 0;
				} else {
					this.sid = item.id;
					this.tid = 0;
				}
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
				this.getProducts();
			},
			// 导航栏点击
			longClick(index, item) {
				// if (this.categoryThreeList.length > 3) {
				// 	this.tabLeft = (index - 1) * (this.isWidth + 6) //设置下划线位置
				// };
				if (index > 1) {
					this.navThreeActiveId = `navThree${index - 1}`;
				} else {
					this.navThreeActiveId = 'navThree0';
				}
				this.tabClick = index; //设置导航点击了哪一个
				this.iSlong = true;
				if (this.level == 2) {
					this.sid = item.id;
				} else {
					this.tid = item.id;
				}
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
				this.getProducts();
			},
			// 打开客服
			goKefu() {
				this.getCustomerList()
				this.$refs.kefu.show = true
			},
			// 获取客服列表数据
			getCustomerList() {
				getCustomerList(this.info.id).then(res => {
					this.customerList = res.data
				})
			},
			// 关闭客服
			closeKefu() {
				this.$refs.kefu.show = false
			},
			tapNavOne(item, index) {
				if (index > 2) {
					this.navOneActiveId = `navOne${index - 2}`;
				} else {
					this.navOneActiveId = 'navOne0';
				}
				this.oneVisible = false;
				this.navOneActive = index;
				this.navActive = 0;
				this.tabClick = 0;
				this.categoryErList = item.children;
				this.categoryThreeList = this.categoryErList[0].children || [];
				this.categoryTitle = item.cate_name;
				this.cid = item.id;
				this.sid = item.children[0] ? item.children[0].id : 0;
				this.tid = 0;
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
				this.getProducts();
			},
			moreNav() {
				this.currentPage = !this.currentPage;
			},
			goAddressPages() {
				uni.navigateTo({
					url: '/pages/users/user_address_list/index?isCollage=1&store_id=' + this.info.id
				});
			},
			navOneOpen() {
				this.oneVisible = true;
			},
			navOneClose() {
				this.oneVisible = false;
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
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
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
		},
	}
</script>

<style lang="scss">
	/deep/.uni-badge {
		z-index: 100;
	}

	.cartIcon-placeholder {
		height: 300rpx;
	}

	.safe-area-inset-bottom {
		height: constant(safe-area-inset-bottom);
		height: env(safe-area-inset-bottom);
	}

	.input-wrapper {
		position: relative;
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
	}

	/deep/.product-window.joinCart {
		z-index: 999;
	}

	::-webkit-scrollbar {
		width: 0;
		height: 0;
		color: transparent;
		display: none
	}

	.page {
		position: relative;
		flex-wrap: nowrap;
		min-height: 100vh;

		/deep/.mask {
			z-index: 99;
		}

		/deep/.attrProduct {
			.mask {
				z-index: 100;
			}
		}

		.header {
			/* #ifdef H5 */
			padding: 0 20rpx 20rpx;
			/* #endif */

			.pageIndex {
				width: 68rpx;
				height: 68rpx;
				border-radius: 50%;
				background-color: #F7F7F7;

				.iconfont {
					color: #666666;
					font-size: 30rpx;
				}
			}

			.search {
				flex: 1;
				height: 58rpx;
				border-radius: 29rpx;
				background: rgba(255, 255, 255, 0.2);
				font-size: 24rpx;
				padding: 0 24rpx;
				color: #FFFFFF;

				.iconfont {
					font-size: 32rpx;
					margin-right: 16rpx;
					color: #FFFFFF;
				}
			}
		}

		.conter {
			flex-wrap: nowrap;
			flex: 1;
			background: #FFFFFF;

			.aside-box {
				width: 168rpx;
			}

			.aside {
				width: 168rpx;
				height: 100%;
				background: #F5F5F5;

				.item {
					height: 100rpx;
					width: 100%;
					font-size: 26rpx;
					color: #333333;

					.inner {
						position: relative;
						height: 100%;
						padding: 0 6rpx;
					}

					&.on {
						background: #FFFFFF;
						width: 100%;
						text-align: center;
						color: var(--view-theme);
						font-weight: 500;
						position: relative;

						.inner::after {
							content: "";
							position: absolute;
							width: 6rpx;
							height: 48rpx;
							background: var(--view-theme);
							border-radius: 0 4rpx 4rpx 0;
							left: 0
						}
					}

					&.btr {
						background: #FFFFFF;

						.inner {
							border-top-right-radius: 24rpx;
							background: #F5F5F5;
						}
					}

					&.bbr {
						background: #FFFFFF;

						.inner {
							border-bottom-right-radius: 24rpx;
							background: #F5F5F5;
						}
					}
				}
			}

			.wrapper {
				flex: 1;
				flex-wrap: nowrap;
				min-width: 0;

				.bgcolor-box {
					height: 104rpx;
				}

				.bgcolor {
					flex-wrap: nowrap;
					height: 104rpx;
					background: #FFFFFF;
					top: 0;
					right: 0;
					left: 168rpx;
					z-index: 10;
				}

				.longTab {
					flex: 1;
					min-width: 0;

					.longItem {
						display: inline-block;
						height: 56rpx;
						border-radius: 28rpx;
						margin-left: 16rpx;
						background: #F5F5F5;
						vertical-align: middle;
						text-align: center;
						font-size: 24rpx;
						line-height: 54rpx;
						color: #333333;

						.inner {
							height: 56rpx;
							border: 1rpx solid transparent;
							border-radius: 28rpx;
							background-clip: content-box;
							transform: rotateZ(360deg);
						}

						&.click {
							background: var(--view-theme);
							color: var(--view-theme);

							.inner {
								background-color: rgba(255, 255, 255, 0.9);
							}
						}
					}

					.underlineBox {
						height: 3px;
						width: 20%;
						display: flex;
						align-content: center;
						justify-content: center;
						transition: .5s;

						.underline {
							width: 33rpx;
							height: 4rpx;
							background-color: #FFFFFF;
						}
					}
				}

				.openList {
					position: relative;
					width: 92rpx;
					text-align: center;
					line-height: 104rpx;

					.btn {
						display: inline-block;
						width: 56rpx;
						height: 56rpx;
						border-radius: 50%;
						background: #F5F5F5;
						vertical-align: middle;
						line-height: 56rpx;
					}

					.iconfont {
						vertical-align: middle;
						font-size: 32rpx;
						color: #333333;
					}

					.img {
						position: absolute;
						top: 0;
						left: 0;
						width: 18rpx;
						height: 100%;
						transform: translateX(-50%);
					}
				}

				.goodsList {
					margin-top: 0rpx;
					padding: 0 30rpx 0 20rpx;

					/deep/.item {
						margin-bottom: 33rpx !important;

						.text {
							font-size: 26rpx;
						}

						.bottom {
							.sales {
								.money {
									font-size: 34rpx;

									text {
										font-size: 26rpx;
									}
								}
							}

							.cart {
								.pictrue {
									width: 50rpx;
									height: 50rpx;
								}
							}
						}
					}
				}
			}
		}

		.store-address {
			width: 100%;
			margin-top: 15rpx;
			position: relative;
			padding: 12rpx 30rpx 0 30rpx;
			height: 185rpx;
			background-color: #fff;

			.address {
				font-size: 32rpx;
				font-weight: 500;
				color: #333333;

				.name {
					max-width: 400rpx;
				}

				.icon-xiangyou {
					font-size: 24rpx;
					margin-left: 10rpx;
				}
			}

			.distance {
				margin-top: 16rpx;
				font-size: 24rpx;
				font-weight: 400;
				color: #999999;
			}

			.time {
				margin-top: 16rpx;
				font-size: 24rpx;
				font-weight: 400;
				color: #333333;
			}
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
			}

			.item {
				margin-top: 32rpx;
				color: #333333;

				&:first-child {
					margin-top: 0;
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

	.headerBg {
		position: absolute;
		top: 0;
		right: 0;
		left: 0;

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

	.category {
		flex-wrap: nowrap;
		border-bottom: 1rpx solid #EEEEEE;
		background: #FFFFFF;

		.scroll-view {
			flex: 1;
			min-width: 0;
			padding-left: 20rpx;
			white-space: nowrap;
		}

		.item {
			display: inline-block;
			padding: 24rpx 0;
			margin-right: 8rpx;
			vertical-align: middle;

			&.on {
				.img-box {
					border-color: var(--view-theme);
				}

				.title {
					background: linear-gradient(90deg, var(--view-gradient) 0%, var(--view-theme) 100%);
					color: #FFFFFF;
				}
			}
		}

		.img-box {
			width: 104rpx;
			height: 104rpx;
			padding: 6rpx;
			border: 3rpx solid transparent;
			border-radius: 50%;
			margin: 0 auto;
		}

		.img {
			width: 92rpx;
			height: 92rpx;
			border-radius: 50%;
		}

		.title {
			width: 120rpx;
			height: 40rpx;
			border-radius: 20rpx;
			margin-top: 8rpx;
			text-align: center;
			font-size: 24rpx;
			line-height: 40rpx;
			color: #333333;
		}

		.btn {
			position: relative;
			flex-wrap: nowrap;
			width: 72rpx;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #333333;

			.img {
				position: absolute;
				top: 0;
				left: 0;
				width: 22rpx;
				height: 100%;
				transform: translateX(-50%);
			}
		}
	}

	.downTab {
		flex-wrap: nowrap;
		max-height: 820rpx;
		border-radius: 0rpx 0rpx 32rpx 32rpx;
		background: #FFFFFF;

		.title {
			padding: 32rpx 0 24rpx 30rpx;
			font-size: 32rpx;
			line-height: 44rpx;
			color: #333333;
		}

		.scroll-view {
			flex: 1;
			min-height: 0;
			box-sizing: border-box;
		}

		.children {
			padding: 0 0 8rpx 30rpx;
			margin: 0 -24rpx 0 0;

			.item {
				height: 56rpx;
				border-radius: 28rpx;
				margin: 0 24rpx 24rpx 0;
				background: #F5F5F5;
				text-align: center;
				font-size: 24rpx;
				line-height: 56rpx;
				color: #333333;

				.inner {
					height: 56rpx;
					border: 1rpx solid transparent;
					border-radius: 28rpx;
					background-clip: padding-box;
					transform: rotateZ(360deg);
				}

				&.click {
					background: var(--view-theme);
					color: var(--view-theme);

					.inner {
						background-color: rgba(255, 255, 255, 0.9);
					}
				}
			}
		}

		.btn {
			height: 80rpx;
			font-size: 24rpx;
			color: #999999;

			.iconfont {
				margin-left: 4rpx;
				font-size: 24rpx;
			}
		}
	}

	.category-dialog {
		border-radius: 0rpx 0rpx 32rpx 32rpx;
		background: #FFFFFF;

		.scroll-view {
			flex: 1;
			min-height: 0;
			padding-left: 32rpx;
		}

		.item {
			display: inline-block;
			margin-right: 22rpx;
			margin-bottom: 26rpx;
			vertical-align: middle;

			&.on {
				.img-box {
					border-color: var(--view-theme);
				}

				.title {
					background: linear-gradient(90deg, var(--view-gradient) 0%, var(--view-theme) 100%);
					color: #FFFFFF;
				}
			}
		}

		.img-box {
			width: 104rpx;
			height: 104rpx;
			padding: 6rpx;
			border: 3rpx solid transparent;
			border-radius: 50%;
			margin: 0 auto;
		}

		.img {
			width: 92rpx;
			height: 92rpx;
			border-radius: 50%;
		}

		.title {
			width: 120rpx;
			height: 40rpx;
			border-radius: 20rpx;
			margin-top: 8rpx;
			text-align: center;
			font-size: 24rpx;
			line-height: 40rpx;
			color: #333333;
		}

		.btn {
			position: relative;
			flex-wrap: nowrap;
			height: 80rpx;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #999999;

			.iconfont {
				margin-left: 4rpx;
				font-size: 24rpx;
			}
		}
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
		border: 1px solid var(--view-theme);
		border-top-color: transparent;
		border-radius: 50%;
		background-color: #FFFFFF;
	}

	.coupon-left::after {
		content: "";
		position: absolute;
		width: 4rpx;
		height: 4rpx;
		bottom: -2px;
		right: -2px;
		border: 1px solid var(--view-theme);
		border-bottom-color: transparent;
		border-radius: 50%;
		background-color: #FFFFFF;
	}
</style>