<template>
	<!-- 商品列表 -->
	<view :style="colorStyle">
		<view class="header_box fixed-lt w-full z-200 bg--w111-fff" :style="{'padding-top': sysHeight + 'px'}">
			<view class="h-80 px-20 flex-y-center">
				<text class="iconfont icon-ic_leftarrow fs-40 mr-16" @tap="goBack"></text>
				<view class="flex-1 h-58 rd-30 bg--w111-f5f5f5 flex-y-center px-32 relative">
					<text class="iconfont icon-ic_search fs-24"></text>
					<input :value='where.keyword' class="pl-18 w-460 line1 fs-24" placeholder="请输入商品名称" @confirm="searchSubmit" />
					<text class="iconfont icon-ic_close1 fs-28 text--w111-999 z-10" v-if="where.keyword" @tap="clearWord"></text>
				</view>
				<!-- 胶囊占据的位置 -->
				<!-- #ifdef MP -->
				<view class="ml-12" :style="'width:'+getHeight.barWidth+'px'"></view>
				<!-- #endif -->
			</view>
			<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-full pl-32"
			show-scrollbar="false" v-if="cateSearch">
			  <view class="inline-block fs-30 h-80 lh-80rpx mr-40"
				v-for="item in filterCateList" :key="item.id"
				:class="item.id == where.sid ? 'text-primary-con fw-500 fs-32' : ''"
				@tap="cateCheck(item)">{{item.cate_name}}</view>
			</scroll-view>
			<view class="h-88 px-32 flex-between-center relative">
				<view class="text--w111-333 fw-500 select_cate relative" v-if="!cateSearch && title">{{title}}</view>
				<view class="text--w111-333 fs-26 fw-500 flex-y-center" v-else @tap="toggleSortable">
					<text>{{sortableShowTab[where.defaultOrder].title}}</text>
					<text class="iconfont icon-ic_down2 fs-14 ml-6"></text>
				</view>
				<view class="text--w111-666 flex-y-center fs-26" @tap='set_where(3)'>
					<text :class="[1,2].includes(stock) ? 'text-primary-con' : ''">销量</text>
					<text v-show="stock==2" class="iconfont icon-ic_down2 fs-14 ml-6 text-primary-con"></text>
					<text v-show="stock==1" class="iconfont icon-ic_up2 fs-14 ml-6 text-primary-con"></text>
					<text v-show="stock==0" class="iconfont icon-ic_down2 fs-14 ml-6"></text>
				</view>
				<view class="text--w111-666 flex-y-center fs-26" @tap='set_where(2)'>
					<text :class="[1,2].includes(price) ? 'text-primary-con' : ''">价格</text>
					<text v-show="price==2" class="iconfont icon-ic_down2 fs-14 ml-6 text-primary-con"></text>
					<text v-show="price==1" class="iconfont icon-ic_up2 fs-14 ml-6 text-primary-con"></text>
					<text v-show="price==0" class="iconfont icon-ic_down2 fs-14 ml-6"></text>
				</view>
				<view class="text--w111-666 flex-y-center fs-26">
					<text class="iconfont" :class="is_switch ? 'icon-a-ic_Imageandtextsorting' : 'icon-a-ic_QRcode'" @tap='Changswitch'></text>
					<text class="menu_line"></text>
					<view @tap="showFilterDrawer = true">筛选</view>
					<text class="iconfont icon-ic_sort pl-8" @tap="showFilterDrawer = true"></text>
				</view>
				<view class="sortable-box w-full bg--w111-fff rd-b-24rpx z-999" v-if="showSortAbleBox">
					<view class="flex-between-center pb-40"
						v-for="(item, index) in sortableShowTab" :key="index" @tap="checkSortable(item.value)">
						<text class="fs-26" :class="item.value == where.defaultOrder ? 'text-primary-con' : 'text--w111-333'">{{item.title}}</text>
						<view class="fs-26 iconfont icon-duihao2 text-primary-con" v-show="item.value == where.defaultOrder"></view>
					</view>
				</view>
			</view>
			<view class="pt-12 pb-16 flex-y-center bg--w111-fff" v-if="(promotionList.length || where.promotions_id) && !cateSearch">
				<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-full" show-scrollbar="false">
					<view
						class="inline-block op-border ml-24 h-48 lh-48rpx bg--w111-f5f5f5 px-24 rd-24 text-center fs-22 text--w111-333"
						v-for="item in promotionList" :key="item.id"
						:class="where.promotions_id == item.id ? 'active' : ''"
						@tap="promotionTap(item)">
							<text class="w-full line1 text-center">{{item.desc}}</text>
						 </view>
				</scroll-view>
			</view>
			<view class="relative h-72" v-if="useMinPrice">
				<view class="h-full bg-color opacity-08"></view>
				<view class="abs-lt flex-y-center w-full h-full pl-20 fs-28 text-w111-theme">
					<text class="iconfont icon-ic_coupon mr-8 fs-32"></text>
					{{useMinPrice}}
				</view>
			</view>
		</view>
		<view :style="{ height: `${getHeight.barTop}px` }"></view>
		<view class="h-80"></view>
		<view class="h-80" v-if="cateSearch"></view>
		<view class="h-88"></view>
		<view class="h-76" v-if="(promotionList.length || where.promotions_id) && !cateSearch"></view>
		<view class="h-72" v-if="useMinPrice"></view>
		<!-- <view class="pt-32 pl-20 pr-20 bg--w111-fff pb-10" :style="{'margin-top':marTop + 'rpx'}" v-if="is_switch==false"> -->
		<view class="pt-32 pl-20 pr-20 bg--w111-fff pb-10" v-if="is_switch==false">
			<view class="flex mb-32 pro_item" v-for="(item,index) in productList" :key="index" @tap="goDetail(item)">
				<view class="list-pic w-240 h-240 relative">
					<easy-loadimage
					:image-src="item.image"
					:borderSrc="item.activity_frame.image"
					width="240rpx"
					height="240rpx"
					borderRadius="20rpx"></easy-loadimage>
					<view class="abs-lt flex-center w-full h-full" v-if="!item.stock">
						<view class="flex-center w-104 h-104 rd-50-p111- bg-w111-333333-60 fs-24 text--w111-fff">售罄</view>
					</view>
				</view>
				<view class="flex-1 pl-20 flex-col justify-between">
					<view class="w-full">
						<view class="w-full line2 fs-28 text--w111-333 lh-40rpx h-82">
							<text v-if="item.brand_name" class="brand-tag">{{ item.brand_name }}</text>{{item.store_name}}
						</view>
						<view class="w-full flex items-end flex-wrap mt-16">
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
					<view class="flex-y-center">
						<baseMoney :money="item.price" symbolSize="24" integerSize="40" decimalSize="24" weight></baseMoney>
						<view class="inline-block h-26 lh-28rpx rd-14rpx bg--w111-F7E9CD fs-22 ml-8" v-if="Number(item.vip_price) > 0">
							<text class="inline-block h-26 lh-28rpx svip_rd fs-18 bg--w111-484643 text--w111-FDDAA4 px-8">SVIP</text>
							<text class="px-8 fs-22">¥{{item.vip_price}}</text>
						</view>
					</view>
					<view class="text--w111-999 fs-22">
						<text>已售{{item.sales}}件</text>
						<text class="pl-16">评分 {{item.star}}</text>
					</view>
				</view>
			</view>
		</view>
		<!-- <view class="pl-20 pr-20 pb-20 pt-32 relative" :style="{'margin-top':marTop + 'rpx'}" v-else> -->
		<view class="pl-20 pr-20 pb-20 pt-32 relative" v-else>
			<view class="jinabian"></view>
			<waterfallsFlow ref="waterfallsFlow" :wfList="productList" @itemTap="goDetail"></waterfallsFlow>
		</view>
		<view class='loadingicon acea-row row-center-wrapper' v-if='productList.length > 0'>
			<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
		</view>
		<view class='px-20' v-if="productList.length==0 && where.page > 1">
			<emptyPage :title="where.keyword ? '无搜索结果,换个词试试吧' : '暂无商品，去看点别的吧～'" 
				:src="where.keyword ? '/statics/images/noSearch.gif' : '/statics/images/empty-box.gif'"></emptyPage>
			<recommend v-if='hostProduct.length' :hostProduct="hostProduct"></recommend>
		</view>
		<productWindow
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
			@onstoreId="onstoreId"
			id='product-window' 
			:fangda='false'></productWindow>
		<filterDrawer
			:visible="showFilterDrawer"
			:promotionId="where.promotions_id"
			:brandList="brandList"
			:labelList="labelList"
			:promotionList="promotionList"
			@closeDrawer="()=>{showFilterDrawer = false}"
			@filterChange="filterConfirm"></filterDrawer>
		<view class="mask" v-if="showSortAbleBox" @tap="showSortAbleBox = false"></view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import homeList from '@/components/homeList';
	import productWindow from '@/components/productWindow';
	import waterfallsFlow from "@/components/WaterfallsFlow/WaterfallsFlow.vue";
	import {
		getProductslist,
		getProductHot,
		searchFilterApi,
		levelCategoryApi,
		getAttr,
		postCartAdd,
		postCartNum
	} from '@/api/store.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import recommend from '@/components/recommend';
	import filterDrawer from '../components/filterDrwer/index.vue';
	import emptyPage from '@/components/emptyPage.vue';
	import home from '@/components/home/index.vue';
	import { mapGetters } from "vuex";
	import { goShopDetail } from '@/libs/order.js';
	import { getCartList, getCartCounts } from '@/api/order.js';
	import { getShare } from '@/api/public.js';
	import colors from '@/mixins/color.js';
	import { HTTP_REQUEST_URL } from '@/config/app';
	export default {
		computed: {
			...mapGetters(['uid', 'isLogin', 'cartNum'])
		},
		components: {
			recommend,
			homeList,
			waterfallsFlow,
			productWindow,
			filterDrawer,
			emptyPage,
			home
		},
		mixins: [colors],
		data() {
			return {
				id: 0,
				productValue: [], //系统属性
				attr: {
					cartAttr: false,
					productAttr: [],
					productSelect: {}
				},
				attrValue: '', //已选属性
				sysHeight: sysHeight,
				goodList: true,
				currentPage: false,
				productList: [],
				is_switch: true,
				where: {
					sid: 0,
					keyword: '',
					priceOrder: '',
					salesOrder: '',
					news: 0,
					page: 1,
					limit: 8,
					cid: 0,
					tid: 0,
					brand_id: '',
					promotions_id: 0,
					promotions_type:0,
					defaultOrder:0,
					coupon_id:0,
					coupon_issue_type:0,
				},
				price: 0,
				stock: 0,
				nows: false,
				loadend: false,
				loading: false,
				loadTitle: '加载更多',
				title: '',
				hostProduct: [],
				hotPage: 1,
				hotLimit: 10,
				hotScroll: false,
				brandList: [],
				promotionList:[],
				labelList:[],
				filterCateList:[],
				storeInfo: {},
				totalPrice: 0,
				promotionsInfo: {},
				totalNum: 0,
				imgHost: HTTP_REQUEST_URL,
				isShowAuth: false,
				marTop:'',
				showFilterDrawer:false,
				cateSearch:false,
				showSortAbleBox:false,
				sortableShowTab:[
					{title:'综合排序',value:0},
					{title:'好评优先',value:1},
					{title:'新品优先',value:2},
				],
				getHeight: this.$util.getWXStatusHeight(),
				homeHide: false,
				shareInfo: {},
				useMinPrice: '',
				store_id: 0,
			};
		},
		onLoad: function(options) {
			this.where.cid = options.cid || 0;
			this.$set(this.where, 'sid', options.sid || 0);
			this.$set(this.where, 'tid', options.tid || 0);
			this.title = options.title || '';
			this.$set(this.where, 'keyword', options.searchValue || '');
			this.$set(this.where, 'productId', options.productId || '');
			this.$set(this.where, 'brand_id', options.brandId || 0);
			this.$set(this.where, 'coupon_id', options.couponId || 0);
			this.$set(this.where, 'coupon_issue_type', options.couponIssueType || 0);
			this.$set(this, 'useMinPrice', options.useMinPrice || '');
			if (options.promotions_type) {
				this.where.promotions_type = options.promotions_type;
				this.where.promotions_id = options.promotions_id;
				this.is_switch = false
			}
			if(parseInt(options.sid)){
				//用于判断顶部分类选择内容是否展示
				this.cateSearch = true;
				this.getProCate();
			}
			this.getProductList();
			this.getSearchFilter();
			this.marTop = (115 + sysHeight) * 2;
			getShare().then(res => {
				this.shareInfo = res.data;
			});
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			if (this.isLogin && !this.is_switch) {
				this.getCartList();
				this.getCartNum();
			}
		},
		created() {
			this.$eventHub.$off('onCartAddChange');
			this.$eventHub.$on('onCartAddChange',(data)=>{
				this.goCartDuo(data);
				// if(data.spec_type){
				// 	this.goCartDuo(data);
				// }else{
				// 	this.goCartDan(data);
				// }
			});
		},
		methods: {
			getCartNum() {
				getCartCounts().then(res => {
					this.totalNum = res.data.count;
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				})
			},
			getCartList() {
				let truePrice = 0;
				getCartList().then(res => {
					let data = res.data,
						valid = res.data.valid;
					valid.forEach(item => {
						item.promotions.forEach(z => {
							if (this.where.promotions_id == z.id) {
								this.promotionsInfo = z;
							}
						})
						item.cart.forEach(j => {
							truePrice = this.$util.$h.Add(truePrice, this.$util.$h.Mul(j.truePrice,
								j.cart_num));
						})
					})
					this.totalPrice = this.$util.$h.Sub(truePrice, data.deduction.coupon_price)
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				})
			},
			goCart() {
				if (this.where.promotions_type) {
					uni.switchTab({
						url: '/pages/order_addcart/order_addcart'
					})
				} else {
					uni.switchTab({
						url: '/pages/goods_cate/goods_cate'
					})
				}
			},
			// 商品详情接口；
			getAttrs(id) {
				let that = this;
				getAttr(id, 0).then(res => {
					uni.hideLoading();
					res.data.storeInfo.delivery_type.sort((x, y) => x - y);
					that.$set(that.attr, 'productAttr', res.data.productAttr);
					that.$set(that, 'productValue', res.data.productValue);
					that.$set(that, 'storeInfo', res.data.storeInfo);
					that.$set(that.attr, 'deliveryType', res.data.storeInfo.delivery_type);
					that.DefaultSelect();
				})
			},
			getProCate(){
				levelCategoryApi({id:this.where.sid}).then(res=>{
					this.filterCateList = res.data;
				})
			},
			// 筛选
			getSearchFilter() {
				let data = {
					keyword: this.where.keyword,
					cid: this.where.cid,
					sid: this.where.sid,
					tid: this.where.tid,
					productId: this.where.productId,
					promotions_type: this.where.promotions_type,
				};
				searchFilterApi(data).then(res => {
					this.brandList = res.data.brand;
					this.labelList = res.data.store_label;
					this.promotionList = res.data.promotions;
					this.getMarTop();
				}).catch(err => {
					return this.$util.Tips({
						title: err.msg
					});
				})
			},
			toggleSortable(){
				this.showSortAbleBox = !this.showSortAbleBox;
			},
			checkSortable(val){
				this.where.defaultOrder = val;
				this.showSortAbleBox = false;
				this.loadend = false;
				this.$set(this.where, 'page', 1);
				this.getProductList(true);
			},
			promotionTap(item){
				if(this.where.promotions_id == item.id){
					this.where.promotions_id = 0;
				}else{
					this.where.promotions_id = item.id;
				}
				this.loadend = false;
				this.$set(this.where, 'page', 1);
				this.getProductList(true);
			},
			clearWord(){
				this.where.keyword = ''
			},
			cateCheck(item){
				this.where.sid = item.id;
				this.loadend = false;
				this.$set(this.where, 'page', 1);
				this.getProductList(true);
			},
			filterConfirm(data){
				this.showFilterDrawer = false;
				Object.assign(this.where,data);
				this.loadend = false;
				this.$set(this.where, 'page', 1);
				this.getProductList(true);
				this.getMarTop();
			},
			// 去详情页
			goDetail(item) {
				this.currentPage = false;
				goShopDetail(item, this.uid).catch(res => {
					uni.navigateTo({
						url: `/pages/goods_details/index?id=${item.id}`
					});
				});
			},
			Changswitch: function() {
				let that = this;
				this.currentPage = false
				that.is_switch = !that.is_switch
			},
			searchSubmit: function(e) {
				let that = this;
				this.currentPage = false
				that.$set(that.where, 'keyword', e.detail.value);
				that.loadend = false;
				that.$set(that.where, 'page', 1)
				this.getProductList(true);
				this.getSearchFilter();
			},
			/**
			 * 获取我的推荐
			 */
			get_host_product: function() {
				let that = this;
				if (that.hotScroll) return
				getProductHot(
					that.hotPage,
					that.hotLimit,
				).then(res => {
					that.hotPage++
					that.hotScroll = res.data.length < that.hotLimit
					that.hostProduct = that.hostProduct.concat(res.data)
				});
			},
			//点击事件处理
			set_where: function(e) {
				this.currentPage = false
				switch (e) {
					case 1:
						// #ifdef H5
						return history.back();
						// #endif
						// #ifndef H5
						return uni.navigateBack({
							delta: 1,
						})
						// #endif
						break;
					case 2:
						if (this.price == 0) this.price = 1;
						else if (this.price == 1) this.price = 2;
						else if (this.price == 2) this.price = 0;
						this.stock = 0;
						break;
					case 3:
						if (this.stock == 0) this.stock = 1;
						else if (this.stock == 1) this.stock = 2;
						else if (this.stock == 2) this.stock = 0;
						this.price = 0
						break;
					case 4:
						this.nows = !this.nows;
						break;

				}
				this.loadend = false;
				this.$set(this.where, 'page', 1);
				this.getProductList(true);
			},
			//设置where条件
			setWhere: function() {
				if (this.price == 0) this.where.priceOrder = '';
				else if (this.price == 1) this.where.priceOrder = 'asc';
				else if (this.price == 2) this.where.priceOrder = 'desc';
				if (this.stock == 0) this.where.salesOrder = '';
				else if (this.stock == 1) this.where.salesOrder = 'asc';
				else if (this.stock == 2) this.where.salesOrder = 'desc';
				this.where.news = this.nows ? 1 : 0;
			},
			//查找产品
			getProductList: function(isPage) {
				let that = this;
				that.setWhere();
				if (that.loadend) return;
				if (that.loading) return;
				if (isPage === true) {
					that.$set(that, 'productList', []);
				}
				that.loading = true;
				that.loadTitle = '';
				getProductslist(that.where).then(res => {
					let list = res.data;
					let productList = that.$util.SplitArray(list, that.productList);
					let loadend = list.length < that.where.limit;
					that.loadend = loadend;
					that.loading = false;
					that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					that.$set(that, 'productList', productList);
					that.$set(that.where, 'page', that.where.page + 1);
					if (!that.productList.length) this.get_host_product();
				}).catch(err => {
					that.loading = false;
					that.loadTitle = '加载更多';
				});
			},
			getMarTop(){
				if((this.promotionList.length && !this.cateSearch) || this.where.promotions_id || this.filterCateList.length){
					this.marTop = (115 + sysHeight) * 2;
				}else{
					this.marTop = (75 + sysHeight) * 2
				}
			},
			goCartDuo(item) {
				if (!this.isLogin) {
					toLogin();
				} else {
					if(item.cart_button == 0){
						if(item.is_presale_product){
							uni.navigateTo({
								url: `/pages/activity/goods_details/index?id=${item.id}&type=6`
							})
						}else{
							uni.navigateTo({
								url: `/pages/goods_details/index?id=${item.id}`
							})
						}
					}else{
						this.storeName = item.store_name;
						this.getAttrs(item.id);
						this.$set(this, 'id', item.id);
						this.$set(this.attr, 'cartAttr', true);
					}
				}
			},
			// 多规格加入购物车；
			goCatNum() {
				this.goCat(1, this.id, 1);
			},
			// 点击默认单属性购物车
			goCartDan(item) {
				if (!this.isLogin) {
					toLogin();
				} else {
					if(item.cart_button == 0){
						if(item.is_presale_product){
							uni.navigateTo({
								url: `/pages/activity/goods_details/index?id=${item.id}&type=6`
							})
						}else{
							uni.navigateTo({
								url: `/pages/goods_details/index?id=${item.id}`
							})
						}
					}else{
						this.goCat(0, item.id, 1);
					}
				}
			},
			DefaultSelect: function() {
				let productAttr = this.attr.productAttr;
				let valueobj = [];
				let value = [];
				let productValue = Object.values(this.productValue).filter((item) => {
					return item.stock;
				});
				// 考虑有库存的默认选中
				let productValueDefault = productValue.find((item) => {
					return item.is_default_select;
				});
				// 不考虑有无库存的默认选中
				let productDefault = [],isDefault = true;
				if(Object.keys(this.productValue).length){
				    let obj = Object.values(this.productValue).find((item) => {
						return item.is_default_select;
					});
					productDefault = obj?obj:[],
					isDefault =  obj?true:false
				}
				if (productValueDefault) {
					value = productValueDefault.suk.split(',');
				} else if (productValue.length) {
					value = productValue[0].suk.split(',');
				}else{
					if(productDefault.length){
						productDefault.suk.split(',')
					}else{
						if(isDefault){
							value = []
						}else{
							value = Object.values(this.productValue)[0].suk.split(',');
						}
					}
				}
				// for (let key in this.productValue) {
				// 	if (this.productValue[key].stock > 0) {
				// 		value = this.attr.productAttr.length ? key.split(",") : [];
				// 		break;
				// 	}
				// }
				for (let i = 0; i < productAttr.length; i++) {
					this.$set(productAttr[i], "index", value[i]);
				}
				//sort();排序函数:数字-英文-汉字；
				let productSelect = this.productValue[value.join(",")];
				this.$set(this.attr.productSelect,"store_name",this.storeName);
				if (productSelect && productAttr.length) {
					this.$set(this.attr.productSelect, "image", productSelect.image);
					this.$set(this.attr.productSelect, "price", productSelect.price);
					this.$set(this.attr.productSelect, "stock", productSelect.stock);
					this.$set(this.attr.productSelect, "unique", productSelect.unique);
					this.$set(this.attr.productSelect, "cart_num", 1);
					this.$set(this.attr.productSelect, 'vip_price', productSelect.vip_price);
					this.$set(this, "attrValue", value.join(","));
				} else if (!productSelect && productAttr.length) {
					this.$set(this.attr.productSelect, "image", this.storeInfo.image);
					this.$set(this.attr.productSelect, "price", this.storeInfo.price);
					this.$set(this.attr.productSelect, "stock", 0);
					this.$set(this.attr.productSelect, "unique", "");
					this.$set(this.attr.productSelect, "cart_num", 0);
					this.$set(this, "attrValue", "");
					this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
				} else if (!productSelect && !productAttr.length) {
					this.$set(this.attr.productSelect, "image", this.storeInfo.image);
					this.$set(this.attr.productSelect, "price", this.storeInfo.price);
					this.$set(this.attr.productSelect, "stock", this.storeInfo.stock);
					this.$set(this.attr.productSelect,"unique",this.storeInfo.unique || "");
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
				if (productSelect && productSelect.stock >= 0) {
					this.$set(this.attr.productSelect, "image", productSelect.image);
					this.$set(this.attr.productSelect, "price", productSelect.price);
					this.$set(this.attr.productSelect, "stock", productSelect.stock);
					this.$set(this.attr.productSelect, "unique", productSelect.unique);
					this.$set(this.attr.productSelect, 'vip_price', productSelect.vip_price);
					this.$set(this.attr.productSelect, "cart_num", 1);
					this.$set(this, "attrValue", res);
				} else {
					this.$set(this.attr.productSelect, 'image', this.storeInfo.image);
					this.$set(this.attr.productSelect, 'price', this.storeInfo.price);
					this.$set(this.attr.productSelect, 'stock', 0);
					this.$set(this.attr.productSelect, 'unique', '');
					this.$set(this.attr.productSelect, 'cart_num', 0);
					this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
					this.$set(this, 'attrValue', '');
				}
			},
			attrVal(val) {
				this.$set(this.attr.productAttr[val.indexw], 'index', this.attr.productAttr[val.indexw].attr_values[val
					.indexn]);
			},
			iptCartNum: function(e) {
				this.$set(this.attr.productSelect, 'cart_num', e);
			},
			onMyEvent: function() {
				this.$set(this.attr, 'cartAttr', false);
			},
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
			// 购物车加减计算函数
			ChangeCartNum(changeValue, num, stock, isDuo, id, index, cart) {
				if (changeValue) {
					num.cart_num++;
					if (num.cart_num > stock) {
						if (isDuo) {
							this.$set(this.attr.productSelect, "cart_num", stock ? stock : 1);
							this.$set(this, "cart_num", stock ? stock : 1);
						} else {
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
								this.getTotalPrice();
							} else {
								this.goCat(0, id, 1);
							}
						}
					}
				} else {
					num.cart_num--;
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
							num.cart_num = 0;
							this.$set(this, 'tempArr', this.tempArr);
							this.$set(this.cartData, 'cartList', this.cartData.cartList);
						}
					} else {
						if (!isDuo) {
							if (cart) {
								this.goCat(0, id, 0, 1, num.product_attr_unique);
								this.getTotalPrice();
							} else {
								this.goCat(0, id, 0);
							}
						}
					}
				}
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
				let cartNum = duo ? that.attr.productSelect.cart_num : 1;
				let q = {
					product_id: id,
					num: cartNum,
					type: type,
					unique: duo ? that.attr.productSelect.unique : cart ? unique : "",
				};
				if (this.attr.cartAttr) {
					q.store_id = this.store_id;
				}
				postCartNum(q).then(function(res) {
					that.attr.cartAttr = false;
					that.$store.commit('indexData/setCartNum', parseInt(that.cartNum) + cartNum);
					if(!duo){
						return that.$util.Tips({
							title: '加入购物车成功'
						});
					}else{
						return that.$util.Tips({
							title: res.msg
						});
					}
				})
				.catch(err => {
					return that.$util.Tips({
						title: err
					});
				});
			},
			goBack(){
				this.$util.backPage();
			},
			// 获取到店核销地址id
			onstoreId(row,flag) {
				if(flag == 2 || flag == 3){
				   this.store_id = row.id;
				}else{
				   this.store_id = 0;
				}
			},
		},
		onPageScroll(e) {
			this.currentPage = false;
			uni.$emit('scroll');
      this.homeHide = true;
		},
		onReachBottom() {
			if (this.productList.length > 0) {
				this.getProductList();
			} else {
				this.get_host_product();
			}

		},
		onPullDownRefresh() {
			this.loadend = false;
			this.where.page = 1;
			this.getProductList(true);
			this.getSearchFilter();
			uni.stopPullDownRefresh();
		},
		// #ifdef MP
		onShareAppMessage() {
			return {
				title: this.shareInfo.title,
				path: '/pages/goods/goods_list/index?searchValue='+this.where.keyword+'&spid=' + this.uid
			}
		},
		onShareTimeline() {
			return {
				title: this.shareInfo.title,
				path: '/pages/goods/goods_list/index?searchValue='+this.where.keyword+'&spid=' + this.uid
			}
		}
		// #endif
	}
</script>
<style scoped lang="scss">
	.loadingicon{
		height: 100rpx;
		height: calc(100rpx + constant(safe-area-inset-bottom);
		height: calc(100rpx + env(safe-area-inset-bottom));
		margin-top: 0;
	}
	input{
		width: 100% !important;
	}
	.z-200{
		z-index: 200;
	}
	.pro_item ~ .pro_item{
		margin-top: 32rpx;
	}
	.border-picture {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		height: 100%;
		border-radius: 16rpx 16rpx 0 0;
		background: center/cover no-repeat;
	}
	.menu_line {
		width: 1px;
		height: 30rpx;
		background: #B3B3B3;
		margin: 0 20rpx;
	}
	.icon-ic_close1{
		position: absolute;
		right: 34rpx;
		top: 50%;
		transform: translateY(-50%);
	}
	.select_cate{
		&:after{
			content: '';
			position: absolute;
			top: 42rpx;
			left:50%;
			transform: translateX(-50%);
			width:36rpx;
			height:14rpx;
			background-image: url('../static/select_zs.png');
			background-size: 100%;
			background-repeat: no-repeat;
		}
	}
	.jinabian{
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		height: 330rpx;
		background: linear-gradient(180deg, #FFFFFF 0%, rgba(255,255,255,0) 100%);
	}
	.info_box{
		padding: 16rpx 20rpx;
		border-radius: 0 0 20rpx 20rpx;
		background-color: #fff;
	}
	.text-primary-con{
		color: var(--view-theme);
	}
	.bg-primary-light{
		background: var(--view-minorColorT);
	}
	.bg--w111-484643{
		background: linear-gradient(90deg, #484643 0%, #1F1B17 100%);
	}
	.text--w111-FDDAA4{
		color: #FDDAA4;
	}
	.svip_rd{
		border-radius: 14rpx 0 8rpx 14rpx;
	}
	.op-border{
		border: 1px solid #f5f5f5;
	}
	.active{
		border: 1px solid var(--view-theme);
		color: var(--view-theme);
		background: var(--view-minorColorT);
	}
	.sortable-box{
		padding: 32rpx 32rpx 0;
		position: absolute;
		top: 88rpx;
		left: 0;
	}
</style>
