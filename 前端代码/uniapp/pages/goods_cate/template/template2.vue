<template>
	<view>
		<view class="flex-col bg--w111-fff" :style="[pageHeight]">
			<view :style="{height: headerHeight ? (headerHeight + 'px') : 'auto'}">
				<view :style="{paddingTop: sysHeight + 'px'}" :class="{fixed: headerFixed}"  id="mp-header" class="text--w111-999 mp-header">
					<view class="h-80 px-32 flex-y-center">
						<!-- #ifdef MP -->
						<view class="flex-1 h-58 flex-y-center rd-30rpx bg--w111-f5f5f5 px-32" @tap="goSearch">
						<!-- #endif -->
						<!-- #ifndef MP -->
						<view class="w-full h-58 flex-y-center rd-30rpx bg--w111-f5f5f5 px-32" @tap="goSearch">
						<!-- #endif -->
							<text class="iconfont icon-ic_search fs-28"></text>
							<text class="fs-24 pl-18">请输入商品名称</text>
						</view>
						<!-- 胶囊占据的位置 -->
						<!-- #ifdef MP -->
						<view class="ml-12" :style="'width:'+getHeight.barWidth+'px'"></view>
						<!-- #endif -->
					</view>
				</view>
			</view>
				<view class="flex border_e">
					<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-678 pl-20 pt-24 pb-24"
						show-scrollbar="false" :scroll-into-view="navOneActiveId" scroll-with-animation>
						<view class="inline-block mr-8" :id="`navOne${index}`" v-for="(item,index) in categoryList" :key="index"
							@tap="tapNav(index,item)">
							<view class="flex-col flex-center">
								<view :class="index == navActive ? 'active_pic' : 'scroll_pic'">
									<image :src="item.pic" mode="aspectFill"></image>
								</view>
								<view class="w-120 h-40 flex-center fs-24 text--w111-333"
									:class="index == navActive ? 'active_cate_text' : ''">
									<view class="line1">{{item.cate_name}}</view>
								</view>
							</view>
						</view>
					</scroll-view>
					<view class="bntAll flex-1 flex-col flex-center fs-24" @tap="openCateDrawer(true)">
						<text>全</text>
						<text>部</text>
						<view class="iconfont icon-ic_more2 mt-8 fs-26"></view>
					</view>
				</view>
				<view class="scroll_box flex flex-1" id="conter-box">
					<view class="w-168 bg--w111-f5f5f5" :style="{ height: asideHeight ? (asideHeight + 'px') : 'auto'}">
						<view class="w-168 h-full bg--w111-f5f5f5" :class="{ fixed: conterFixed }" :style="{ top: headerHeight+'px' }">
							<scroll-view :scroll-top="0" scroll-y="true" class="h-full">
								<view class="w-168 h-96 flex-center fs-26 text--w111-666"
									v-for="(item,index) in categoryErList"
									:key="index"
									:class="[{'aside_active': index===tabClick},'aside_' + index]"
									@tap="longClick(index,item)">
									<view class="top-ra">
										<text></text>
									</view>
									<view class="line1">{{item.cate_name}}</view>
									<view class="bottom-ra">
										<text></text>
									</view>
								</view>
								<view class="white-box"></view>
							</scroll-view>
						</view>
					</view>
					<view class="relative flex-1 flex-col">
						<view class="flex-1 pl-24 pr-20 abs-lt bg--w111-fff z-4" v-if="threeCateList.length && level == 3" :class="{ fixed: conterFixed }" :style="{ top: scrollTop && conterTop <= headerHeight ? headerHeight+'px' : 0, left: scrollTop && conterTop <= headerHeight ? '168rpx' : 0 }">
							<view class="flex mt-24 mb-16">
								<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-464"
									show-scrollbar="false" :scroll-into-view="navThreeActiveId" scroll-with-animation>
									<view class="inline-block mr-16" :id="`navThree${index}`" v-for="(item,index) in threeCateList" :key="index"
										@tap="threeCateClick(index,item)">
										<view
											class="w-144 h-56 rd-30rpx bg--w111-f5f5f5 flex-center fs-24 text--w111-333"
											:class="index===threeClick?'cate_active':''">
											<view class="line1">{{item.cate_name}}</view>
										</view>
									</view>
								</scroll-view>
								<view class="bntCon w-56 h-56 rd-30rpx bg--w111-f5f5f5 flex-center ml-16"
									v-if="threeCateList.length && level == 3"
									@tap="openCateDrawer(false)">
									<text class="iconfont icon-ic_downarrow fs-32 text--w111-333"></text>
								</view>
							</view>
						</view>
						<view class="h-96" v-if="threeCateList.length && level == 3"></view>
						<view v-else class="mt-24"></view>
						<view class="pl-24 pr-20 flex-1 flex-col" style="min-height: 0;" :class="!threeCateList.length ? 'mt-24' : ''">
							<scroll-view class="h-full" :scroll-top="0" scroll-y="true" @scrolltolower="lower" @scroll="scroll"
								>
								<!-- 大图模板 -->
								<view class="pb-36" v-if="(showType == 3 && level == 2) || (showType == 1 && level == 3)">
									<view class="mb-24" v-for="(item,index) in tempArr" :key="index"
										@tap="goDetail(item)">
										<easy-loadimage
										    style="min-height: 216rpx;max-height: 538rpx;overflow: hidden;"
										    mode="widthFix"
											:image-src="item.recommend_image ? item.recommend_image : item.image"
											:borderSrc="item.recommend_image ? '' :item.activity_frame.image"
											width="540rpx"
											borderRadius="20rpx 20rpx 0 0"></easy-loadimage>
										<!-- :height="item.recommend_image ? '216rpx' : '540rpx'" -->
										<view class="bg--w111-fff rd-b-20rpx pt-16 pb-24">
											<view class="w-full line2 fs-28 text--w111-333 lh-40rpx">
												<text v-if="item.brand_name" class="brand-tag">{{ item.brand_name }}</text>{{item.store_name}}
											</view>
											<view class="flex-between-center mt-20 pr-24">
												<view class="flex items-baseline flex-wrap flex-1">
													<baseMoney :money="item.price" symbolSize="24" integerSize="40"
														decimalSize="24" incolor="var(--primary-theme-con)" weight>
													</baseMoney>
													<view class="inline-block h-26 lh-28rpx rd-14rpx bg--w111-F7E9CD fs-22 ml-8"
														v-if="Number(item.vip_price) > 0">
														<text class="inline-block h-26 lh-28rpx svip-rd fs-18 bg-w111-484643 text-w111-FDDAA4 px-8">SVIP</text>
														<text class="px-8 fs-22 SemiBold">¥{{item.vip_price}}</text>
													</view>
												</view>
												<view
													class="w-172 h-58 rd-30rpx bg-color flex-center fs-24 text--w111-fff relative"
													v-if="item.spec_type" @tap.stop="goCartDuo(item)">
													<text>{{item.cart_button == 0 ? '立即购买' : '加入购物车'}}</text>
													<uni-badge class="badge-style" :custom-style="{background: 'var(--view-theme)'}" v-if="item.cart_num > 0" :text="item.cart_num"></uni-badge>
												</view>
												<view v-if="!item.spec_type">
													<view class="flex-center w-48 h-48 rd-30rpx bg-color text--w111-fff"
														@tap.stop="goCartDuo(item,index)">
														<text class="iconfont icon-ic_ShoppingCart1 fs-30"></text>
													</view>
												</view>
												<!-- <view v-if="!item.spec_type && !item.cart_num">
													<view class="flex-center w-48 h-48 rd-30rpx bg-color text--w111-fff "
														@tap.stop="goCartDan(item,index)">
														<text class="iconfont icon-ic_ShoppingCart1 fs-30"></text>
													</view>
												</view> -->
												<!-- <view class="flex-y-center" v-if="!item.spec_type && item.cart_num">
													<view
														class="flex-center w-48 h-48 rd-30rpx bg--w111-f5f5f5 text--w111-333"
														@tap.stop="ChangeCartNumDan(false,index,item)">
														<text class="iconfont icon-ic_Reduce fs-32"></text>
													</view>
													<view class="fs-30 text--w111-333 px-20" v-if="item.showInput" @tap.stop="toggleInput(item,true)">
														<input v-model="item.copy_cart_num" type="text" 
															maxlength="2" :focus="true" 
															@input="inputClick($event,item)"
															@confirm="setProCartNum($event,item)"
															class="w-48 text-center" />
													</view>
													<view class="fs-30 text--w111-333 px-20" v-else @tap.stop="toggleInput(item,false)">{{item.cart_num}}</view>
													<view class="flex-center w-48 h-48 rd-30rpx bg-color text--w111-fff"
														@tap.stop="CartNumAdd(index,item)">
														<text class="iconfont icon-ic_increase fs-32"></text>
													</view>
												</view> -->
											</view>
										</view>
									</view>
								</view>
								<!-- 小图模板 -->
								<view class="pb-36" v-else-if="(showType == 2 && level == 2) || (showType == 2 && level == 3)">
									<view class="mb-24 flex justify-between" v-for="(item,index) in tempArr" :key="index"
										@tap="goDetail(item)">
										<easy-loadimage
										    class="bg-w111-f9f9f9"
										    mode="aspectFit"
											:image-src="item.image" 
											:borderSrc="item.activity_frame.image"
											width="176rpx" 
											height="176rpx"
											borderRadius="16rpx"></easy-loadimage>
										<view class="flex-1 flex-col justify-between pl-20">
											<view class="w-full">
												<view class="line1 w-346 fs-28 text-#333 lh-40rpx">
													<text v-if="item.brand_name" class="brand-tag">{{ item.brand_name }}</text>{{item.store_name}}
												</view>
												<view class="flex items-end flex-wrap mt-12 w-full">
													<BaseTag
														:text="label.label_name"
														:color="label.color"
														:background="label.bg_color"
														:borderColor="label.border_color"
														:circle="label.border_color ? true : false"
														:imgSrc="label.icon"
														v-for="(label, idx) in item.store_label" :key="idx"></BaseTag>
												</view>
											</view>
											<view class="flex-between-center flex-bottom">
												<view class="flex items-baseline flex-wrap flex-1">
													<baseMoney :money="item.price" symbolSize="24" integerSize="40"
														decimalSize="24" weight>
													</baseMoney>
													<view class="inline-block h-26 lh-28rpx rd-14rpx bg--w111-F7E9CD fs-22 ml-8"
														v-if="Number(item.vip_price) > 0">
														<text class="inline-block h-26 lh-28rpx svip-rd fs-18 bg-w111-484643 text-w111-FDDAA4 px-8">SVIP</text>
														<text class="px-8 fs-22 SemiBold">¥{{item.vip_price}}</text>
													</view>
												</view>
												<view class="flex-center w-48 h-48 rd-30rpx bg-color text--w111-fff"
													@tap.stop="goCartDuo(item)" v-if="item.spec_type">
													<text class="iconfont icon-ic_ShoppingCart1 fs-30"></text>
												</view>
												<view v-if="!item.spec_type">
													<view
														class="flex-center w-48 h-48 rd-30rpx bg-color text--w111-fff"
														@tap.stop="goCartDuo(item,index)">
														<text class="iconfont icon-ic_ShoppingCart1 fs-30"></text>
													</view>
												</view>
												<!-- <view class="flex-y-center" v-if="!item.spec_type && item.cart_num">
													<view
														class="flex-center w-48 h-48 rd-30rpx bg--w111-f5f5f5 text--w111-333"
														@tap.stop="ChangeCartNumDan(false,index,item)">
														<text class="iconfont icon-ic_Reduce fs-32"></text>
													</view>
													<view class="fs-30 text--w111-333 px-20" v-if="item.showInput" @tap.stop="toggleInput(item,true)">
														<input v-model="item.copy_cart_num" type="text" 
															maxlength="2" :focus="true" 
															@input="inputClick($event,item)"
															@confirm="setProCartNum($event,item)"
															class="w-48 text-center" />
													</view>
													<view class="fs-30 text--w111-333 px-20" v-else @tap.stop="toggleInput(item,false)">{{item.cart_num}}</view>
													<view class="flex-center w-48 h-48 rd-30rpx bg-color text--w111-fff"
														@tap.stop="CartNumAdd(index,item)">
														<text class="iconfont icon-ic_increase fs-32"></text>
													</view>
												</view> -->
											</view>
										</view>
									</view>
								</view>
								<view v-if="!tempArr.length && !loading">
									<emptyPage title="暂无商品，去看点别的吧～" ></emptyPage>
								</view>
								<view class="white-box"></view>
							</scroll-view>
						</view>
					</view>
				</view>
			</view>
			<view class="h-96 footer" :class="isFooter == 1 ? 'show-footer' : ''">
				<view class="w-96 h-96 rd-50rpx bg-gradient flex-center abs-lt" @tap="toggleCart">
					<image src="@/static/img/cart_pic.png" class="w-54 h-54"></image>
					<uni-badge class="badge-style" :custom-style="{background: 'var(--view-theme)'}" v-if="cartNum > 0" :text="cartNum"></uni-badge>
				</view>
				<view class="w-710 h-full flex-between-center overflow cart_box" :class="{ open: footerOpen }">
					<view class="flex-y-center" @tap="toggleCart">
						<view class="items-baseline ml-32 inline-flex h-60 lh-60rpx overflow">
							<baseMoney :money="totalPrice" color="#fff" symbolSize="32" integerSize="48" decimalSize="32"
								incolor="#fff" weight></baseMoney>
							<view class="fs-24 text--w111-fff lh-34rpx pl-16" v-show="cartData.iScart">收起 <text
									class="iconfont icon-ic_downarrow fs-24"></text> </view>
							<view class="fs-24 text--w111-fff lh-34rpx pl-16" v-show="!cartData.iScart">查看明细 <text
									class="iconfont icon-ic_uparrow fs-24"></text> </view>
						</view>
					</view>
					<view class="w-186 h-72 lh-72rpx text-center rd-40rpx text--w111-fff fs-26 fw-500 bg-gradient mr-12"
						@tap="subOrder">去结算({{cartNumTotal}})</view>
				</view>
			</view>
			<view class="more_box fixed-lt w-full bg--w111-fff rd-b-32rpx z-20" v-show="showCateDrawer">
				<view :style="{paddingTop: sysHeight + 'px'}">
					<view class="h-80 px-32 flex-y-center">
						<!-- #ifdef MP -->
						<view class="flex-1 h-58 flex-y-center rd-30rpx bg--w111-f5f5f5 px-32" @tap="goSearch">
						<!-- #endif -->
						<!-- #ifndef MP -->
						<view class="w-full h-58 flex-y-center rd-30rpx bg--w111-f5f5f5 px-32" @tap="goSearch">
						<!-- #endif -->
							<text class="iconfont icon-ic_search fs-28"></text>
							<text class="fs-24 text--w111-999 pl-18">请输入商品名称</text>
						</view>
						<!-- 胶囊占据的位置 -->
						<!-- #ifdef MP -->
						<view class="ml-12" :style="'width:'+getHeight.barWidth+'px'"></view>
						<!-- #endif -->
					</view>
				</view>
				<view class="pt-32 pl-30 pr-30">
					<view v-if="!topNavShow">
						<view class="fs-32 text--w111-333" v-if="categoryList.length">
							{{categoryList[navActive].cate_name}}
						</view>
						<view class="grid-column-4 grid-gap-24rpx mt-24">
							<view class="w-154 h-56 rd-30rpx flex-center fs-24 text--w111-333 bg--w111-f5f5f5"
								v-for="(item,index) in threeCateList" :key="index"
								@tap="threeCateClick(index,item)" :class="index===threeClick?'cate_active':''">
								{{item.cate_name}}
							</view>
						</view>
					</view>
					<view class="grid-column-5 grid-gap-x-22rpx grid-gap-y-32rpx" v-if="topNavShow">
						<view class="flex-col flex-center" v-for="(item,index) in categoryList" :key="index"
							@tap="tapNav(index,item)">
							<view :class="index == navActive ? 'active_pic' : 'scroll_pic'">
								<image :src="item.pic" mode="aspectFill"></image>
							</view>
							<view class="w-120 h-40 flex-center fs-24 text--w111-333"
								:class="index == navActive ? 'active_cate_text' : ''">
								<view class="line1">{{item.cate_name}}</view>
							</view>
						</view>
					</view>
					<view class="flex-center fs-24 text--w111-999 h-80 mt-32" @tap="closeCateDrawer">
						<text>点击收起 <text class="iconfont icon-ic_uparrow pl-4 fs-24"></text> </text>
					</view>
				</view>
			</view>
			<view class="mask" v-show="showCateDrawer" @tap="closeCateDrawer" @touchmove.stop.prevent="moveHandle"></view>
			<cartList ref="cartList" :cartData="cartData" :isFooter='isFooter' :storeChecked="storeChecked" @closeList="closeList"
				@ChangeCartNumDan="ChangeCartList" @ChangeSubDel="ChangeSubDel" @ChangeOneDel="ChangeOneDel" @deliveryFromChange="deliveryFromChange" @storeCheckedChange="storeCheckedChange">
			</cartList>
			<productWindow
				ref="productWindow"
				:attr="attr"
				:isShow='1'
				:iSplus='1'
				:iScart='1'
				:type="2"
				:storeInfo='storeInfo'
				@myevent="onMyEvent"
				@ChangeAttr="ChangeAttr"
				@ChangeCartNum="ChangeCartNumDuo"
				@attrVal="attrVal"
				@iptCartNum="iptCartNum"
				@goCat="goCatNum"
				@getImg="showImg"
				@deliveryFun="deliveryFun"
				@onstoreId="onstoreId"
				id='product-window' ></productWindow>
			<cusPreviewImg ref="cusPreviewImg" :list="skuArr" @changeSwitch="changeSwitch"></cusPreviewImg>
		</view>
</template>

<script>
	let windowHeight = uni.getSystemInfoSync().screenHeight;
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import {
		getCategoryList,
		getProductslist,
		getAttr,
		postCartNum
	} from '@/api/store.js';
	import {
		vcartList,
		getCartCounts,
		cartDel,
		changeCartNum,
	} from '@/api/order.js';
	import productWindow from '@/components/productWindow';
	import cusPreviewImg from '@/components/cusPreviewImg';
	import cartList from '@/components/cartList';
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
	import skuSelect from '@/mixins/skuSelect.js'
	import emptyPage from '@/components/emptyPage.vue';
	function debounce(func, wait) {
	  let timeout;
	  return function(...args) {
	    clearTimeout(timeout);
	    timeout = setTimeout(() => func.apply(this, args), wait);
	  };
	}

	export default {
		props: {
			level: {
				type: Number,
				default: 2
			},
			showType:{
				type: Number,
				default: 2
			},
			isFooter:{
				type: Boolean,
				default: false
			}
		},
		data() {
			return {
				windowHeight: this.isFooter?windowHeight:windowHeight-50,
				showCateDrawer: false,
				sysHeight: sysHeight,
				categoryList: [],
				navActive: 0,
				categoryTitle: '',
				categoryErList: [],
				tabLeft: 0,
				isWidth: 0, //每个导航栏占位
				tabClick: 0, //导航栏被点击
				iSlong: false,
				tempArr: [],
				loading: false,
				loadend: false,
				loadTitle: '加载更多',
				page: 1,
				limit: 10,
				cid: 0, //一级分类
				sid: 0, //二级分类
				tid: 0, //三级分类
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				attr: {
					cartAttr: false,
					productAttr: [],
					productSelect: {}
				},
				productValue: [],
				attrValue: '', //已选属性
				storeName: '', //多属性产品名称
				id: 0,
				cartData: {
					cartList: [],
					cartStoreList: [],
					iScart: false
				},
				totalPrice: 0.00,
				lengthCart: 0,
				cart_num: 0,
				storeInfo: {},
				scrollHeight: 0,
				threeCateList: [],
				threeClick: 0,
				topNavShow: true,
				selectSku: {},
				skuArr: [],
				getHeight: this.$util.getWXStatusHeight(),
				footerOpen:true,
				headerHeight: 0,
				conterTop: 0,
				scrollTop: 0,
				asideHeight: 0,
				navOneActiveId: '',
				navThreeActiveId: '',
			}
		},
		components: {
			productWindow,
			cartList,
			emptyPage,
			cusPreviewImg
		},
		mixins: [skuSelect],
		computed: {
			...mapState({
				// cartNum: state => state.indexData.cartNum
			}),
			...mapGetters(['isLogin', 'uid', 'cartNum', 'diyCategory']),
			headerFixed() {
				let isFixed = false;
				if (this.diyCategory.level == 3 && (this.diyCategory.index == 1 || this.diyCategory.index == 2)) {
					isFixed = this.scrollTop > 0;
				}
				return isFixed;
			},
			conterFixed() {
				let isFixed = false;
				if (this.diyCategory.level == 3 && (this.diyCategory.index == 1 || this.diyCategory.index == 2)) {
					if (this.scrollTop) {
						if (this.conterTop <= this.headerHeight) {
							return true
						}
					} else {
						return false
					}
				}
				return isFixed;
			},
			pageHeight() {
				let styleObject = {};
				if (this.diyCategory.level == 2 && (this.diyCategory.index == 2 || this.diyCategory.index == 3)) {
					styleObject['height'] = this.windowHeight + 'px';
				}
				if (this.diyCategory.level == 3 && (this.diyCategory.index == 1 || this.diyCategory.index == 2)) {
					styleObject['min-height'] = this.windowHeight + 'px';
				}
				return styleObject;
			},
			cartNumTotal() {
				let num = 0;
				if (this.deliveryFrom == 1) {
					num = this.cartData.cartList.reduce((total, item) => {
						return total + item.cart_num;
					}, 0);
				} else{
					const storeItem = this.cartData.cartStoreList.find(item => item.store_id == this.storeChecked);
					if (!storeItem) {
						return num;
					}
					num = storeItem.valid.reduce((total, validItem) => {
						return total + validItem.cart.reduce((cartTotal, cartItem) => {
							return cartTotal + cartItem.cart_num;
						}, 0);
					}, 0);
				}
				return num;
			},
		},
		mounted() {
			// 这里是 多店+平台、单店+平台 调用；
			let shopOperationType = uni.getStorageSync('shop_operation_type');
			if(shopOperationType !=3){
				this.getAllCategory();
			}
			setTimeout(() => {
				this.getScrollHeight();
			}, 500);
			if(this.isLogin){
				this.deliveryFrom = shopOperationType == 3 ? 2 : 1;
				this.deliveryFromChange(this.deliveryFrom);
			}
			uni.$off('newAttrNum');
			uni.$on('newAttrNum', (data) => {
				if (data.length) {
					this.tempArr.forEach((item) => {
						data.forEach(j => {
							if (item.id == j.id) {
								item.cart_num = j.num
							}
						})
					})
				}
			})
			// 关闭通讯
			uni.$off('newCartNum');
			// 与创建订单页面进行通讯
			uni.$on('newCartNum', (data) => {
				if (data.length) {
					this.tempArr.forEach((item) => {
						data.forEach(j => {
							if (item.id == j.product_id) {
								item.cart_num = 0
							}
						})
					})
					this.cartData.cartList.forEach((item)=>{
						data.forEach(j=>{
							if (item.id == j.id) {
								item.cart_num = 0
							}
						})
					})
				}
			})
			// 三级分类样式2、样式3页面滚动和滚动到底部
			if (this.diyCategory.level == 3 && (this.diyCategory.index == 1 || this.diyCategory.index == 2)) {
				const query = uni.createSelectorQuery().in(this);
				query
				  .select("#mp-header")
				  .boundingClientRect((data) => {
					this.headerHeight = data.height;
				  })
				  .exec();
				uni.$on('scroll', (scrollTop) => {
					this.scrollTop = scrollTop;
					this.footerOpen = false;
					query
					  .select("#conter-box")
					  .boundingClientRect((data) => {
						this.conterTop = data.top;
					  })
					  .exec();
				})
				uni.$on('onReachBottom', () => {
					this.productslist();
				})
			}
		},
		methods: {
			getScrollHeight() {
				let sysH = uni.getSystemInfoSync().statusBarHeight;
				this.scrollHeight = windowHeight - 138 - sysH;
			},
			getAllCategory() {
				let that = this;
				getCategoryList().then(res => {
					if (!res.data.length) return
					let data = res.data;
					that.categoryTitle = data[0].cate_name;
					that.cid = data[0].id;
					that.sid = 0;
					that.tid = 0;
					that.navActive = 0;
					that.tabClick = 0;
					that.categoryList = data;
					that.categoryErList = res.data[0].children ? res.data[0].children : [];
					that.categoryErList.unshift({
						cate_name: '全部商品',
						id: 0
					})
					if (that.categoryErList.length) {
						that.$nextTick(() => {
							const query = uni.createSelectorQuery().in(this);
							query
							  .select("#conter-box")
							  .boundingClientRect((data) => {
								  that.asideHeight = data.height;
							  })
							  .exec();
						})
					}
					that.threeCateList = res.data[0].children[0].children ? res.data[0].children[0].children : [];
					that.page = 1;
					that.loadend = false;
					that.tempArr = [];
					that.productslist();
				})
			},
			// 产品列表
			productslist() {
				let that = this;
				if (that.loadend) return;
				if (that.loading) return;
				that.loading = true;
				that.loadTitle = '';
				getProductslist({
					page: that.page,
					limit: that.limit,
					type: 1,
					cid: that.cid,
					sid: that.sid,
					tid: that.tid
				}).then(res => {
					let list = res.data,
						loadend = list.length < that.limit;
					that.tempArr = that.$util.SplitArray(list, that.tempArr);
					that.$set(that, 'tempArr', that.tempArr);
					that.loading = false;
					that.loadend = loadend;
					that.loadTitle = loadend ? "没有更多内容啦~" : "加载更多";
					that.page = that.page + 1;
				}).catch(err => {
					that.loading = false;
					that.loadTitle = '加载更多'
				});
			},
			tapNav(index, item) {
				if (index > 2) {
					this.navOneActiveId = `navOne${index - 2}`;
				} else {
					this.navOneActiveId = 'navOne0';
				}
				uni.pageScrollTo({
					duration: 0,
					scrollTop: 0
				})
				let list = this.categoryList[index];
				this.navActive = index;
				this.categoryTitle = list.cate_name;
				this.categoryErList = item.children ? item.children : [];
				if(!this.haveAll(this.categoryErList)){
					this.categoryErList.unshift({
						cate_name: '全部商品',
						id: 0
					})
				}
				this.threeCateList = [];
				if (this.categoryErList.length) {
					this.threeCateList = item.children[0].children ? item.children[0].children : [];
					this.$nextTick(() => {
						const query = uni.createSelectorQuery().in(this);
						query
						  .select("#conter-box")
						  .boundingClientRect((data) => {
							  this.asideHeight = data.height;
						  })
						  .exec();
					})
				}
				this.tabClick = 0;
				this.tabLeft = 0;
				this.cid = list.id;
				this.sid = list.children[0] ? list.children[0].id : 0;
				this.tid = 0;
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
				this.productslist();
				this.showCateDrawer = false;
			},
			// 导航栏点击
			longClick(index, item) {
				this.tabClick = index; //设置导航点击了哪一个
				this.threeClick = 0;
				this.sid = this.categoryErList[index].id;
				this.tid = 0;
				this.threeCateList = item.children && item.children.length ? item.children : [];

				if (this.sid && !this.haveAll(this.threeCateList)) {
					this.threeCateList.unshift({
						cate_name: '全部商品',
						id: 0
					})
				}
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
				this.productslist();
			},
			threeCateClick(index, item) {
				if (index > 1) {
					this.navThreeActiveId = `navThree${index - 1}`;
				} else {
					this.navThreeActiveId = 'navThree0';
				}
				this.showCateDrawer = false;
				this.threeClick = index;
				this.tid = item.id;
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
				this.productslist();
			},
			deepClone(obj) {
				let newObj = Array.isArray(obj) ? [] : {}
				if (obj && typeof obj === "object") {
					for (let key in obj) {
						if (obj.hasOwnProperty(key)) {
							newObj[key] = (obj && typeof obj[key] === 'object') ? this.deepClone(obj[key]) : obj[key];
						}
					}
				}
				return newObj
			},
			haveAll(arr) {
				let cloneMap = this.deepClone(arr),
					list = [];
				cloneMap.forEach(item => {
					list.push(item.id);
				})
				return list.includes(0) ? true : false
			},
			// 生成订单；
			subOrder() {
				let that = this,
					list = [],
					ids = [];
				if (that.deliveryFrom == 1) {
					list = that.cartData.cartList;
				} else {
					list = that.cartData.cartStoreList;
				}
				if (list.length) {
					// 未展开购物车或展开购物车且在商城时
					if (that.deliveryFrom == 1) {
						list.forEach(item => {
							if (item.attrStatus && item.status) {
								ids.push(item.id)
							}
						});
					} else{
						list = that.cartData.cartStoreList;
						const storeItem = list.find(item => item.store_id == that.storeChecked);
						for (let validItem of storeItem.valid) {
							for (let cartItem of validItem.cart) {
								ids.push(cartItem.id)
							}
						}
					}
					uni.navigateTo({
						url: '/pages/goods/order_confirm/index?cartId=' + ids.join(',') + '&store_id=' + that.storeChecked
					});
					that.cartData.iScart = false;
				} else {
					return that.$util.Tips({
						title: '请选择产品'
					});
				}
			},
			// 计算总价；
			getTotalPrice() {
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
			getCartList(iSshow) {
				let that = this;
				vcartList().then(res => {
					that.$set(that.cartData, 'cartList', res.data);
					if (!res.data.length) {
						this.$store.commit('indexData/setCartNum', 0);
						this.tempArr.forEach((item) => {
							item.cart_num = 0
						})
					}
				})
			},
			getCartNum() {
				getCartCounts().then(res => {
					this.$store.commit('indexData/setCartNum', res.data.resultAll.count);
					// 购物车-商城结算金额
					this.totalPrice = res.data.result.sum_price;
					// 购物车-商城商品总数量
					this.cartNumChecked = res.data.result.count;
					// 删除购物车回到分类页确保数据更新
					if(res.data.resultAll.count<=0){
						this.tempArr.forEach((item)=>{
							item.cart_num = 0
						})
					}
				});
			},
			CartNumAdd(index, item) {
				if (item.is_limit && item.cart_num >= item.limit_num) {
					this.$util.Tips({
						title: "购买最多不能超过" + item.limit_num
					});
				} else {
					this.ChangeCartNumDan(true, index, item);
				}
			},
			// 商品详情接口；
			getAttrs(id) {
				let that = this;
				getAttr(id, 0).then(res => {
					res.data.storeInfo.delivery_type.sort((x, y) => x - y);
					that.$set(that.attr, 'productAttr', res.data.productAttr);
					that.$set(that, 'productValue', res.data.productValue);
					that.$set(that, 'storeInfo', res.data.storeInfo);
					that.$set(that.attr, 'deliveryType', res.data.storeInfo.delivery_type);
					that.skuArr = [];
					for (let key in res.data.productValue) {
						let obj = res.data.productValue[key];
						that.skuArr.push(obj)
					}
					if (!that.skuArr.length) {
						that.skuArr = [{
							image: this.storeInfo.image,
							suk: this.storeInfo.store_name,
							price: this.storeInfo.price
						}];
					}
					this.$set(this, "selectSku", that.skuArr[0]);
					if((this.storeInfo.delivery_type[0] == 2 || this.storeInfo.delivery_type[0] == 3) && !uni.getStorageSync('refuseLocation')){
					   this.$refs.productWindow.showLocation();
					}
					that.DefaultSelect();
				})
			},
			//点击sku图片打开轮播图
			showImg(index) {
				this.$refs.cusPreviewImg.open(this.selectSku.suk)
			},
			//滑动轮播图选择商品
			changeSwitch(e) {
				let productSelect = this.skuArr[e];
				this.$set(this, 'selectSku', productSelect);
				var skuList = productSelect.suk.split(',');
				skuList.forEach((i, index) => {
					this.$set(this.attr.productAttr[index], 'index', skuList[index]);
				})
				if (productSelect) {
					this.$set(this.attr.productSelect, 'image', productSelect.image);
					this.$set(this.attr.productSelect, 'price', productSelect.price);
					this.$set(this.attr.productSelect, 'stock', productSelect.stock);
					this.$set(this.attr.productSelect, 'unique', productSelect.unique);
					this.$set(this.attr.productSelect, 'cart_num', 1);
					this.$set(this.attr.productSelect, 'vip_price', productSelect.vip_price);
					this.$set(this, 'attrValue', productSelect.suk);
					this.$set(this, 'attrTxt', '已选择');
				}
			},
			// 去详情页
			goDetail(item) {
				goShopDetail(item, this.uid).catch(res => {
					uni.navigateTo({
						url: `/pages/goods_details/index?id=${item.id}&fromType=1`
					});
				});
			},
			closeCateDrawer() {
				this.showCateDrawer = false;
			},
			openCateDrawer(type) {
				this.topNavShow = type;
				this.showCateDrawer = true;
			},
			lower(e) {
				this.productslist();
			},
			scroll(e) {
				this.footerOpen = false;
				uni.$emit('scroll');
			},
			goSearch() {
				uni.navigateTo({
					url: '/pages/goods/goods_search/index'
				})
			},
			moveHandle() {},
			toggleInput(item,type){
				if(type) return
				this.$set(item,'showInput',true);
			},
			inputClick(e,item) {
				const o = e.detail.value
				const inputRule = /[^\d]/g
				this.$nextTick(() => {
					item.copy_cart_num = o.replace(inputRule, '');
				})
			},
			setProCartNum(e,item){
				if (item.is_limit && item.copy_cart_num >= item.limit_num) {
					this.$util.Tips({
						title: "购买最多不能超过" + item.limit_num
					});
				} else {
					changeCartNum(item.product_id,item.copy_cart_num,2).then((res)=> {
						this.$util.Tips({
							title: res.msg
						});
						item.cart_num = item.copy_cart_num;
						item.showInput =false;
						this.getCartNum();
						this.getCartList(1);
					})
					.catch(err => {
						item.copy_cart_num = item.cart_num;
						return this.$util.Tips({
							title: err
						});
					});
				}
			},
		}
	}
</script>

<style lang="scss" scoped>
	::v-deep .base-tag{
		background-color: #f5f5f5;
	}
	.bntCon{
		position: relative;
		&:before{
			position: absolute;
			content: '';
			width: 6rpx;
			height: 80rpx;
			background: radial-gradient( rgba(0,0,0,0.082) 0%, rgba(0,0,0,0) 100%);
			left:-22rpx;
		}
	}
	
	.bntAll{
		position: relative;
		&:before{
			position: absolute;
			content: '';
			width: 6rpx;
			height: 136rpx;
			background: radial-gradient( rgba(0,0,0,0.082) 0%, rgba(0,0,0,0) 100%);
			left:-6rpx;
		}
	}
	
	.scroll_box {
		overflow: hidden;
	}

	.aside_active {
		background-color: #fff;
		width: 100%;
		text-align: center;
		color: var(--view-theme);
		font-weight: 500;
		position: relative;

		&::after {
			content: '';
			width: 6rpx;
			height: 48rpx;
			background-color: var(--view-theme);
			position: absolute;
			left: 0;
			top: 50%;
			transform: translateY(-50%);
			border-radius: 0 10rpx 10rpx 0;
		}
		.top-ra{
			width: 32rpx;
			height: 32rpx;
			background-color: #fff;
			position: absolute;
			right: 0;
			top:-32rpx;
			text{
				position: absolute;
				top:0;
				right: 0;
				width: 100%;
				height: 100%;
				background-color: #f5f5f5;
				display: inline-block;
				border-radius: 0 0 24rpx 0;
			}
		}
		.bottom-ra{
			width: 32rpx;
			height: 32rpx;
			background-color: #fff;
			position: absolute;
			right: 0;
			bottom:-32rpx;
			text{
				position: absolute;
				top:0;
				right: 0;
				width: 100%;
				height: 100%;
				background-color: #f5f5f5;
				display: inline-block;
				border-radius: 0 24rpx 0 0;
			}
		}
	}

	.cate_active {
		color: var(--view-theme);
		background: var(--view-minorColorT);
		border: 1rpx solid var(--view-theme);
	}
	
	.footer{
		position: fixed;
		/* #ifdef H5 */
		bottom: 140rpx;
		/* #endif */
		/* #ifdef MP || APP-PLUS */
		bottom: 20px;
		/* #endif */
		left: 20rpx;
		z-index: 101;
	}

	.cart_box {
		width: 0;
		height: 96rpx;
		border-radius: 48rpx;
		background-color: rgba(0, 0, 0, 0.83);
		padding-left: 94rpx;
		transition: 0.3s;
		&.open{
			width: 710rpx;
		}
	}
	.show-footer{
		/* #ifdef MP || APP-PLUS */
		bottom: calc(140rpx + env(safe-area-inset-bottom));
		/* #endif */
	}

	.text-primary-con {
		color: var(--view-theme);
	}

	.bg-primary-light {
		background: var(--view-minorColorT);
	}

	.pl-30 {
		padding-left: 30rpx;
	}

	.border_e {
		border-bottom: 1px solid #eee;
	}

	.active_pic {
		width: 104rpx;
		height: 104rpx;
		background-color: #fff;
		padding: 3rpx;
		border-radius: 50%;
		border: 3rpx solid var(--view-theme);

		image {
			width: 100%;
			height: 100%;
			border-radius: 50%;
		}
	}

	.scroll_pic {
		image {
			width: 92rpx;
			height: 92rpx;
			border-radius: 50%;
		}
	}

	.active_cate_text {
		background: linear-gradient(90deg, var(--view-gradient) 0, var(--view-theme) 100%);
		color: #fff;
		border-radius: 20rpx;
		margin-top: 8rpx;
	}
	.badge-style {
		position: absolute;
		top: -4rpx;
		right: 0;
		transform: translateX(50%);
		margin-right: 10rpx;
		::v-deep  .uni-badge--error {
			background-color: var(--view-theme) !important;
		}
		::v-deep .uni-badge {
			// height: 32rpx;
			// min-width: 32rpx;
			// color: var(--view-theme);
			// border: 1px solid var(--view-theme);
			// z-index: 29;
			// font-size: 22rpx;
			// line-height: 28rpx;
			height: 32rpx;
			min-width: 32rpx;
			font-size: 22rpx;
			line-height: 28rpx;
		}
	}
	.white-box{
		height: calc(300rpx + env(safe-area-inset-bottom));
	}
	.mp-header.fixed{
		top: 0;
		right: 0;
		left: 0;
		z-index: 10;
		background: #FFFFFF;
	}
</style>
