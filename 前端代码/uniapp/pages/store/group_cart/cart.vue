<template>
	<!-- 商品分类第三种布局样式 -->
	<view class="goodCate acea-row row-column">
		<view v-show="scrollTop" :style="{ height: headerHeight+'px' }"></view>
		<view :class="{ fixed: scrollTop }" class="mp-header" id="mp-header">
			<view class="sys-head" :style="{ height: sysHeight+'px' }"></view>
			<view class="serch-box" style="height: 43px;">
				<view class="serch-wrapper acea-row row-middle">
					<view class="menu_box flex-center opac">
						<view class="iconfont icon-ic_left fs-34 text-w111-000" @tap="backTap"></view>
						<text class="menu_line"></text>
						<view class="iconfont icon-ic_menu3 fs-32 text-w111-000" @tap="moreNav"></view>
					</view>
					<navigator open-type="navigateBack" hover-class="none">
						<text class="iconfont icon-fanhui2"></text>
					</navigator>
					<navigator url="/pages/goods/goods_search/index" class="input acea-row row-middle" hover-class="none">
						<text class="iconfont icon-ic_search"></text>
						请输入商品名称
					</navigator>
				</view>
			</view>
		</view>
		<!-- 门店地址详情 -->
		<view class="store-address">
			<view class="address acea-row row-middle">
				<view class="name line1">{{info.name || '暂无门店'}}</view>
				<view class="tips">本单{{info.delivery_type == 2 ? '到店核销' : '门店配送'}}</view>
			</view>
			<view class="distance acea-row">
				<text class="iconfont icon-ic_location51"></text>
				<view class="text">门店距您{{info.range||0}}km｜{{info.address}}{{info.detailed_address}}</view>
			</view>
			<view class="time acea-row">
				<text class="iconfont icon-icon_clock"></text>
				<view class="text">营业时间：{{info.day_time || '-'}}</view>
			</view>
		</view>
		<view class="category acea-row" v-if="diyCategory.level == 3">
			<scroll-view scroll-x="true" class="scroll-view" :scroll-into-view="navOneActiveId" scroll-with-animation>
				<view class="item" :id="`navOne${index}`" :class="{ on: navOneActive == index }" v-for="(item,index) in categoryList" :key="item.id" @click="tapNavOne(item,index)">
					<view class="img-box">
						<image :src="item.pic" class="img"></image>
					</view>
					<view class="title">{{item.cate_name}}</view>
				</view>
			</scroll-view>
			<view class="btn acea-row row-column row-center row-middle" @click="oneDrawerOpen">
				<view class="">全</view>
				<view class="">部</view>
				<view class="iconfont icon-ic_more2 mt-4 fs-26"></view>
				<image src="../static/shade.png" class="img"></image>
			</view>
		</view>
		<view class="conter acea-row" id="conter">
			<view class="aside-box">
				<scroll-view scroll-y="true" class="aside" :class="{ fixed: scrollTop && (conterTop - scrollTop <= headerHeight) }" :style="{ top: headerHeight+'px' }">
					<view class="item flex-center" :class='index==navActive?"on":""' v-for="(item,index) in categoryErList" :key="index" @click="tapNav(item,index)">
						<text class="line1">{{item.cate_name}}</text>
					</view>
				</scroll-view>
			</view>
			<view class="wrapper">
				<view class="bgcolor-box">
					<view class="bgcolor acea-row" :class="{ fixed: scrollTop && (conterTop - scrollTop <= headerHeight) }" :style="{ top: headerHeight+'px' }">
						<view class="longTab acea-row row-middle">
							<scroll-view scroll-x="true" style="white-space: nowrap;" :scroll-into-view="navThreeActiveId" scroll-with-animation>
								<view class="longItem" :id="`navThree${index}`" :style='"width:"+isWidth+"px"' :class="index===tabClick?'click':''" v-for="(item,index) in categoryThreeList" :key="index" @click="longClick(index)">
									<view class="inner line1">{{item.cate_name}}</view>
								</view>
							</scroll-view>
						</view>
						<view class="openList">
							<view class="btn" @click="openTap">
								<text class="iconfont icon-ic_downarrow"></text>
							</view>
						</view>
					</view>
				</view>
				<!--商品列表 -->
				<goodClass :tempArr="tempArr" :isLogin="isLogin" @gocartduo="goCartDuo" @gocartdan="goCartDan" @ChangeCartNumDan="ChangeCartNumDan" @detail="goDetail"></goodClass>
				<view class='loadingicon acea-row row-center-wrapper'>
					<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
				</view>
				<view class="safe-area-inset-bottom"></view>
			</view>
		</view>
		<view class="footer">
			<view class="inner-box" :class="{ open: footerOpen }">
				<view class="inner acea-row row-between row-middle">
					<BaseMoney :money="totalPrice" symbolSize="32" integerSize="48" decimalSize="32" color="#FFFFFF"></BaseMoney>
					<view class="btn" @click="subOrder">选好了</view>
				</view>
			</view>
			<view class="cartIcon" @click="getCartLists">
				<uni-badge :text="cartNum" absolute="rightTop" :offset="[6, 4]" :custom-style="{background: 'var(--view-theme)'}">
					<view class="image-wrap">
						<image src="@/static/img/cart_pic.png" class="image"></image>
					</view>
				</uni-badge>
			</view>
		</view>
		<!-- 分类购物车下拉列表 -->
		<cartList :cartData="cartData" :isFooter='isFooter' :cartNums='cartNum' isStoreCart @closeList="closeList" @ChangeCartNumDan="ChangeCartList" @ChangeSubDel="ChangeSubDel" @ChangeOneDel="ChangeOneDel"></cartList>
		<!-- 产品属性组件 -->
		<productWindow :attr="attr" :isShow='1' :iSplus='1' :iScart='1' :type="2" :storeInfo="storeInfo" @myevent="onMyEvent" @ChangeAttr="ChangeAttr" @ChangeCartNum="ChangeCartNumDuo" @attrVal="attrVal" @iptCartNum="iptCartNum" @goCat="goCatNum" id='product-window'></productWindow>
		<homeList :navH="46 + sysHeight" :currentPage="currentPage" :sysHeight="sysHeight" :openNavList="diyProduct.navList"></homeList>
		<tuiDrawer :visible="!iSlong" mode="top" backgroundColor="transparent" @close="closeTap">
			<view class="downTab acea-row row-column">
				<!-- #ifdef MP -->
				<view :style="{height: getHeight.barTop+'px'}"></view>
				<view :style="{height: getHeight.barHeight+'px'}"></view>
				<!-- #endif -->
				<view class="title acea-row row-between-wrapper">
					<view>{{categoryTitle}}</view>
				</view>
				<scroll-view scroll-y="true" class="scroll-view">
					<view class="children">
						<view class="acea-row row-middle">
							<view class="item line1" :class="index===tabClick?'click':''" v-for="(item,index) in categoryThreeList" :key="index" @click="longClick(index,item)">
								<view class="inner">{{item.cate_name}}</view>
							</view>
						</view>
					</view>
				</scroll-view>
				<view class="btn acea-row row-center-wrapper" @click="closeTap">点击收起<text class="iconfont icon-ic_uparrow"></text></view>
			</view>
		</tuiDrawer>
		<tuiDrawer :visible="oneVisible" mode="top" backgroundColor="transparent" @close="oneDrawerClose">
			<view class="category-dialog">
				<!-- #ifdef MP -->
				<view :style="{height: getHeight.barTop+'px'}"></view>
				<view :style="{height: getHeight.barHeight+'px'}"></view>
				<!-- #endif -->
				<scroll-view scroll-y="true" class="scroll-view">
					<view class="acea-row">
						<view class="item" :class="{ on: navOneActive == index }" v-for="(item,index) in categoryList" :key="item.id" @click="tapNavOne(item,index)">
							<view class="img-box">
								<image :src="item.pic" class="img"></image>
							</view>
							<view class="title">{{item.cate_name}}</view>
						</view>
					</view>
				</scroll-view>
				<view class="btn acea-row row-center-wrapper" @click="oneDrawerClose">
					点击收起<text class="iconfont icon-ic_uparrow"></text>
				</view>
			</view>
		</tuiDrawer>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;

	import {
		vcartList,
		getCartCounts,
		cartDel
	} from '@/api/order.js';
	import {
		getCategoryList,
		getAttr,
		postCartNum,
		getCollageCart,
		addCollageCart,
		getCollageCount,
		emptyCollagePartake,
	} from '@/api/store.js';
	import {
		getProducts,
		getCustomerList,
		getStoreCategory,
		getStoreBrand
	} from '@/api/new_store.js';
	import productWindow from '@/components/productWindow/index';
	import goodClass from '../components/goodClass/index.vue';
	import cartList from '@/components/cartList';
	import homeList from '@/components/homeList/index.vue';
	import tuiDrawer from '@/components/tui-drawer/tui-drawer.vue';
	import {
		mapGetters
	} from 'vuex';
	import {
		goShopDetail
	} from '@/libs/order.js';
	import {
		toLogin
	} from '@/libs/login.js';
	export default {
		computed: {
			...mapGetters(['isLogin', 'uid', 'diyProduct', 'diyCategory'])
		},
		components: {
			productWindow,
			goodClass,
			cartList,
			homeList,
			tuiDrawer,
		},
		props: {
			isFooter: {
				type: Boolean,
				default: false
			},
			info: {
				type: Object,
				default: {}
			},
		},
		provide: {
			parent: {}
		},
		data() {
			return {
				getHeight: this.$util.getWXStatusHeight(),
				deliveryList: [{
						name: '到店',
						key: 2
					},
					{
						name: '配送',
						key: 1
					},
				],
				switchNum: 1,
				sysHeight: sysHeight,
				categoryList: [],
				navOneActive: 0,
				navActive: 0,
				categoryTitle: '',
				categoryErList: [],
				categoryThreeList: [],
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
					iScart: false
				},
				totalPrice: 0.00,
				lengthCart: 0,
				cart_num: 0,
				storeInfo: {},
				cartNum: 0,
				footerOpen: false,
				currentPage: false,
				oneVisible: false,
				headerHeight: 0,
				conterTop: 0,
				scrollTop: 0,
				navOneActiveId: '',
				navThreeActiveId: '',
			}
		},
		watch: {
			info(newValue, oldValue) {
				this.switchNum = newValue.delivery_type == 3 ? 1 : newValue.delivery_type
				this.$nextTick(() => {
					const query = uni.createSelectorQuery().in(this);
					query
						.select("#mp-header")
						.boundingClientRect((data) => {
							this.headerHeight = data.height;
						})
						.exec();
					query
						.select("#conter")
						.boundingClientRect((data) => {
							this.conterTop = data.top;
						})
						.exec();
				});
			},
		},
		mounted() {
			let that = this;
			// 获取设备宽度
			uni.getSystemInfo({
				success(e) {
					that.isWidth = e.windowWidth / 5
				}
			});
			// 页面滚动
			uni.$on('onPageScroll', (scrollTop) => {
				this.scrollTop = scrollTop;
				this.footerOpen = false;
			});
			// 页面滚动到底部
			uni.$on('onReachBottom', () => {
				this.getProducts();
			});
		},
		methods: {
			// 授权回调
			onLoadFun() {
				setTimeout(function() {
					this.isShowAuth = false;
				}, 10)
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			updateFun(e) {
				if (e.cartNum) {
					this.tempArr.forEach((item) => {
						if (item.id == e.id) {
							item.cart_num = e.cartNum
						}
					})
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
					delivery_type: that.info.delivery_type,
					collate_code_id: that.info.collage_id
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
			// 切换到店核销方式
			swithFn(num) {
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
				this.page = 1;
				this.loadend = false;
				this.tempArr = []
				this.getProducts()
			},
			// 生成订单；
			subOrder: function() {
				uni.navigateBack();
			},
			// 计算总价；
			getTotalPrice: function() {
				let that = this,
					list = that.cartData.cartList,
					totalPrice = 0.00;
				list.forEach(item => {
					totalPrice = that.$util.$h.Add(totalPrice, that.$util.$h.Mul(item.cart_num, item.truePrice));
				})
				that.$set(that, 'totalPrice', totalPrice);
			},
			ChangeSubDel: function(event) {
				emptyCollagePartake({
					collage_id: this.info.collage_id
				}).then(res => {
					this.$util.Tips({
						title: '清空成功',
						success: () => {
							this.cartData.iScart = false
							this.getCartNum();
							for (let i = 0; i < this.tempArr.length; i++) {
								if (!this.tempArr[i].cart_num) {
									continue;
								}
								this.tempArr[i].cart_num = 0;
							}
						}
					});
				});
				//    let that = this,
				//      list = that.cartData.cartList,
				//      ids = [],
				// storeId = uni.getStorageSync('user_store_id');
				//    list.forEach(item => {
				//      ids.push(item.id)
				//    });
				//    cartDel(ids.join(","),storeId).then(res => {
				//      that.$set(that.cartData, 'cartList', []);
				//      that.cartData.iScart = false;
				//      that.totalPrice = 0.00;
				//      that.page = 1;
				//      that.loadend = false;
				//      that.tempArr = [];
				//      that.getProducts();
				//      that.getCartNum();
				//    })
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
			// 获取购物车商品列表
			getCartList(iSshow) {
				let that = this;
				let data = {
					store_id: that.info.id,
					collage_id: that.info.collage_id,
				};
				getCollageCart(data).then(res => {
					that.$set(that.cartData, 'cartList', res.data);
					if (res.data.length) {
						that.$set(that.cartData, 'iScart', iSshow ? false : !that.cartData.iScart);
					} else {
						that.$set(that.cartData, 'iScart', false);
					}
					that.getTotalPrice();
				});
			},
			// 关闭购物车弹窗
			closeList(e) {
				this.$set(this.cartData, 'iScart', e);
			},
			getCartNum: function() {
				getCollageCount({
					store_id: this.info.id,
					collage_id: this.info.collage_id
				}).then(res => {
					this.cartNum = res.data.count;
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
				this.ChangeCartNum(changeValue, num, stock, 1, this.id);
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
					productId: id,
					cartNum: duo ? that.attr.productSelect.cart_num : 1,
					collageId: this.info.collage_id,
					storeId: this.info.id,
					uniqueId: duo ? that.attr.productSelect.unique : cart ? unique : "",
					isAdd: type
				};
				addCollageCart(q).then(res => {
					if (duo) {
						that.attr.cartAttr = false;
						that.$util.Tips({
							title: "添加购物车成功"
						});
						that.tempArr.forEach((item, index) => {
							if (item.id == that.id) {
								let arrtStock = that.attr.productSelect.stock
								let objNum = parseInt(item.cart_num) + parseInt(that.attr.productSelect.cart_num);
								item.cart_num = objNum > arrtStock ? arrtStock : objNum
							}
						})
					}
					that.getCartNum();
					if (!cart) {
						that.getCartList(1);
					}
				}).catch(err => {
					that.$util.Tips({
						title: err
					});
				});;
				// postCartNum(q)
				//   .then(function(res) {
				//     if (duo) {
				//       that.attr.cartAttr = false;
				//       that.$util.Tips({
				//         title: "添加购物车成功"
				//       });
				//       // that.page = 1;
				//       // that.loadend = false;
				//       that.tempArr.forEach((item, index) => {
				//         if (item.id == that.id) {
				//           let arrtStock = that.attr.productSelect.stock
				//           let objNum = parseInt(item.cart_num) + parseInt(that.attr.productSelect.cart_num);
				//           item.cart_num = objNum > arrtStock ? arrtStock : objNum
				//         }
				//       })
				//       // that.productslist();
				//     }
				//     that.getCartNum();
				//     if (!cart) {
				//       that.getCartList(1);
				//     }
				//   })
				//   .catch(err => {
				//     return that.$util.Tips({
				//       title: err
				//     });
				//   });
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
				goShopDetail(item, this.uid).then(res => {
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
			// 分类数据
			getAllCategory: function() {
				let that = this;
				let params = {};
				if (this.info.product_category_status) {
					params.relation_id = this.info.id;
				}
				getStoreCategory(params).then(res => {
					if (!res.data.length) return
					res.data.map(item=>{
						if(item.children && item.children.length){
							item.children.unshift({
								id:item.id,
								cate_name: '全部商品'
							})
						}
					})
					let data = res.data;
					that.categoryTitle = data[0].cate_name;
					that.cid = data[0].id;
					that.sid = 0;
					that.navActive = 0;
					that.tabClick = 0;
					that.categoryList = data;
					if (this.diyCategory.level == 2) {
						that.categoryErList = that.categoryList;
					} else {
						that.categoryErList = that.categoryList[0].children || [];
					}
					that.categoryThreeList = that.categoryErList[0].children || [];
					// that.categoryErList = res.data[0].children ? res.data[0].children : [];
					that.page = 1;
					that.loadend = false;
					that.tempArr = [];
					that.getProducts();
				})
			},
			tapNav(item, index) {
				uni.pageScrollTo({
					duration: 0,
					scrollTop: 0
				})
				// let list = this.categoryList[index];
				let list = this.categoryErList[index];
				this.navActive = index;
				this.categoryTitle = list.cate_name;
				// this.categoryErList = item.children ? item.children : [];
				this.categoryThreeList = item.children || [];
				this.tabClick = 0;
				this.tabLeft = 0;
				if (this.diyCategory.level == 2) {
					this.cid = list.id;
					this.sid = 0;
				} else {
					this.sid = list.id;
				}
				if (!this.categoryThreeList[0] || this.categoryThreeList[0].id) {
					this.categoryThreeList.unshift({
						cate_name: '全部商品',
						id: 0
					});
				}
				this.tid = 0;
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
				this.getProducts();
			},
			// 导航栏点击
			longClick(index) {
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
				// this.sid = this.categoryThreeList[index].id;
				if (this.diyCategory.level == 2) {
					this.sid = this.categoryThreeList[index].id;
					this.tid = 0;
				} else {
					this.tid = this.categoryThreeList[index].id;
				}
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
				this.getProducts();
			},
			goodsListScroll() {
				this.footerOpen = false;
			},
			moreNav() {
				this.currentPage = !this.currentPage
			},
			backTap() {
				let pages = getCurrentPages(); // 获取当前打开过的页面路由数，
				if (pages.length > 1) {
					uni.navigateBack()
				} else {
					uni.switchTab({
						url: '/pages/index/index'
					});
				}
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
				this.categoryThreeList = this.categoryErList[0] && this.categoryErList[0].children ? this.categoryErList[0].children : [];
				if (!this.categoryThreeList[0] || this.categoryThreeList[0].id) {
					this.categoryThreeList.unshift({
						cate_name: '全部商品',
						id: 0
					});
				}
				this.categoryTitle = item.cate_name;
				this.cid = item.id;
				this.sid = item.children[0] ? item.children[0].id : 0;
				this.tid = 0;
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
				this.getProducts();
			},
			oneDrawerOpen() {
				this.oneVisible = true;
			},
			oneDrawerClose() {
				this.oneVisible = false;
			},
		},
	}
</script>

<style lang="scss">
	.safe-area-inset-bottom {
		height: constant(safe-area-inset-bottom);
		height: env(safe-area-inset-bottom);
	}

	/* #ifdef MP || APP-PLUS */
	.mp-header {
		background: #FFFFFF;
		top: 0;
		right: 0;
		left: 0;
		z-index: 10;

		.serch-wrapper {
			height: 100%;
			/* #ifdef MP */
			padding: 0 220rpx 0 30rpx;
			/* #endif */
			/* #ifdef APP-PLUS */
			padding: 0 50rpx 0 40rpx;

			/* #endif */
			.input {
				flex: 1;
				min-width: 0;
				height: 58rpx;
				padding-left: 32rpx;
				margin: 0 0 0 18rpx;
				background: #F5F5F5;
				color: #999999;
				font-size: 24rpx;
				/* #ifdef MP */
				width: 70%;
				/* #endif */
				/* #ifdef APP-PLUS */
				width: 100%;
				/* #endif */
				border-radius: 29rpx;

				.iconfont {
					font-size: 24rpx;
					margin-right: 18rpx;
				}
			}
		}
	}

	/* #endif */
	page {
		background-color: #fff;
	}

	::v-deep .product-window.joinCart {
		z-index: 999;
	}

	::-webkit-scrollbar {
		width: 0;
		height: 0;
		color: transparent;
		display: none;
	}

	.goodCate {
		flex-wrap: nowrap;
		min-height: 100vh;

		::v-deep .mask {
			z-index: 99;
		}

		::v-deep .attrProduct {
			.mask {
				z-index: 100;
			}
		}

		.header {
			position: fixed;
			background-color: #fff;
			top: 0;
			left: 0;
			width: 100%;
			z-index: 99;
			border-bottom: 1px solid #F0F0F0;
			/* #ifdef H5 */
			padding-top: 20rpx;
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

				// image{
				// 	width: 29rpx;
				// 	height: 30rpx;
				// }
			}

			.search {
				width: 690rpx;
				height: 68rpx;
				border-radius: 36rpx;
				background-color: #F7F7F7;
				font-size: 26rpx;
				color: #cccccc;
				padding: 0 30rpx;
				box-sizing: border-box;

				.iconfont {
					font-size: 30rpx;
					margin-right: 18rpx;
					color: #666666;
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
				top: 0;
				bottom: 0;
				left: 0;
				z-index: 10;
				width: 168rpx;
				height: 100%;
				background: #F5F5F5;

				.item {
					height: 100rpx;
					padding: 0 6rpx;
					font-size: 26rpx;
					color: #666666;

					&.on {
						background: #FFFFFF;
						color: var(--view-theme);
						font-weight: 500;
						font-size: 28rpx;
						position: relative;

						&::after {
							content: "";
							position: absolute;
							width: 6rpx;
							height: 48rpx;
							background: var(--view-theme);
							border-radius: 0 4rpx 4rpx 0;
							left: 0
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

				.mask {
					z-index: 9;
				}

				.longTab {
					flex: 1;
					min-width: 0;

					.longItem {
						display: inline-block;
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
							transform: rotateZ(360deg);
						}

						&.click {
							background: var(--view-theme);
							color: var(--view-theme);

							.inner {
								background: rgba(255, 255, 255, 0.9);
								background-clip: content-box;
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
					width: 92rpx;
					text-align: center;
					line-height: 104rpx;

					.btn {
						display: inline-block;
						width: 56rpx;
						height: 56rpx;
						border-radius: 50%;
						background: #F5F5F5;
						line-height: 56rpx;
					}

					.iconfont {
						font-size: 32rpx;
						color: #333333;
					}
				}

				.goodsList {
					margin-top: 0rpx;
					padding: 0 30rpx 0 20rpx;

					::v-deep .item {
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

				.goodsListWrap {}
			}
		}

		.store-address {
			position: relative;
			padding: 20rpx 20rpx 30rpx 32rpx;

			.address {
				font-size: 32rpx;
				font-weight: 500;
				color: #333333;

				.name {
					flex: 1;
					min-width: 0;
					font-weight: 500;
					font-size: 30rpx;
					color: #333333;
				}

				.tips {
					font-size: 24rpx;
					color: var(--view-theme);
				}
			}

			.distance {
				margin-top: 20rpx;
				font-size: 22rpx;
				line-height: 30rpx;
				color: #666666;

				.iconfont {
					margin-right: 8rpx;
					font-size: 22rpx;
				}
			}

			.time {
				margin-top: 14rpx;
				font-size: 22rpx;
				line-height: 30rpx;
				color: #666666;

				.iconfont {
					margin-right: 8rpx;
					font-size: 22rpx;
				}
			}

			.text {
				flex: 1;
				min-width: 0;
			}
		}

		.footer {
			position: fixed;
			right: 20rpx;
			left: 20rpx;
			bottom: 36rpx;
			bottom: calc(36rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
			bottom: calc(36rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
			z-index: 101;

			.cartIcon {
				position: absolute;
				bottom: 0;
				left: 0;
				width: 96rpx;
				height: 96rpx;
				border-radius: 48rpx;
				background: linear-gradient(-90deg, var(--view-gradient), var(--view-theme));
				box-shadow: 0rpx 0rpx 20rpx 0rpx rgba(0, 0, 0, 0.1);

				.image-wrap {
					width: 96rpx;
					height: 96rpx;
					border-radius: 48rpx;
					text-align: center;
					line-height: 96rpx;
				}

				.image {
					width: 54rpx;
					height: 54rpx;
					vertical-align: middle;
				}
			}

			.inner-box {
				position: absolute;
				bottom: 0;
				left: 0;
				width: 96rpx;
				height: 96rpx;
				border-radius: 48rpx;
				overflow: hidden;
				transition: 0.3s;

				&.open {
					width: 100%;
				}
			}

			.inner {
				flex-wrap: nowrap;
				height: 96rpx;
				padding: 0 12rpx 0 128rpx;
				border-radius: 48rpx;
				background: #333333;
			}

			.btn {
				width: 186rpx;
				height: 72rpx;
				padding: 0 32rpx;
				background: linear-gradient(-90deg, var(--view-gradient) 0%, var(--view-theme) 100%);
				border-radius: 36rpx;
				text-align: center;
				font-weight: 500;
				font-size: 26rpx;
				line-height: 72rpx;
				color: #FFFFFF;
			}
		}
	}

	.group-btn {
		display: flex;
		justify-content: center;
		align-items: center;
		width: 140rpx;
		height: 64rpx;
		border: 1rpx solid #DDDDDD;
		border-radius: 32rpx;
		font-weight: 500;
		font-size: 26rpx;
		color: #333333;
	}

	.dialog {
		position: fixed;
		right: 0;
		bottom: 0;
		left: 0;
		z-index: 101;
		padding: 36rpx 40rpx 40rpx;
		border-radius: 12rpx 12rpx 0 0;
		background-color: #FFFFFF;
		transform: translateY(100%);
		transition: 0.3s;

		&.active {
			transform: translateY(0);
		}
	}

	.dialog-head {
		display: flex;

		.button {
			flex: 1;
			display: flex;
			justify-content: center;
			align-items: center;
			height: 138rpx;
			border: 4rpx solid #DDDDDD;
			border-radius: 12rpx;
			font-weight: 500;
			font-size: 32rpx;
			color: #333333;
			cursor: pointer;

			+.button {
				margin-left: 46rpx;
			}

			&.active {
				border: 4rpx solid var(--view-theme);
			}
		}
	}

	.dialog-body {
		margin-top: 60rpx;
		font-size: 24rpx;
		color: #999999;

		.dialog-body-main {
			display: flex;
			align-items: center;
			margin-top: 32rpx;
		}

		.dialog-body-left {
			flex: 1;
			min-width: 0;
			margin-right: 20rpx;
		}

		.dialog-body-right {
			display: flex;
			justify-content: center;
			align-items: center;
			width: 140rpx;
			height: 46rpx;
			border: 2rpx solid #DDDDDD;
			border-radius: 23rpx;
			font-size: 22rpx;
			color: #666666;
			cursor: pointer;
		}

		.dialog-body-name {
			font-weight: 500;
			font-size: 24rpx;
			line-height: 1;
			color: #333333;

			.tag {
				margin-left: 14rpx;
				font-size: 24rpx;
				color: #888888;
			}
		}

		.dialog-body-info {
			margin-top: 28rpx;

			.item {
				display: flex;
				font-size: 22rpx;
				line-height: 1.5;
				color: #888888;

				+.item {
					margin-top: 20rpx;
				}
			}
		}
	}

	.dialog-foot {
		display: flex;
		margin-top: 78rpx;

		.button {
			display: flex;
			justify-content: center;
			align-items: center;
			flex: 1;
			min-width: 0;
			height: 92rpx;
			border-radius: 46rpx;
			background-color: #EEEEEE;
			font-size: 30rpx;
			color: #666666;
			cursor: pointer;

			+.button {
				margin-left: 46rpx;
			}

			&.primary {
				background-color: var(--view-theme);
				color: #FFFFFF;
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
				width: 154rpx;
				height: 56rpx;
				border-radius: 28rpx;
				margin: 0 24rpx 24rpx 0;
				background: #F5F5F5;
				text-align: center;
				font-size: 24rpx;
				line-height: 56rpx;
				color: #333333;

				.inner {
					width: 154rpx;
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

	.menu_box {
		width: 154rpx;
		height: 58rpx;
		border-radius: 29rpx;
		z-index: 970;
	}

	.opac {
		background-color: rgba(255, 255, 255, 0.6);
		border: 1rpx solid rgba(0, 0, 0, 0.1);
		justify-content: space-evenly;
	}

	.menu_line {
		width: 1px;
		height: 30rpx;
		background: #B3B3B3;
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

	.category-dialog {
		border-radius: 0rpx 0rpx 32rpx 32rpx;
		background: #FFFFFF;

		.scroll-view {
			max-height: 724rpx;
			padding-left: 32rpx;
		}

		.item {
			margin: 0 22rpx 26rpx 0;

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
			flex-wrap: nowrap;
			height: 80rpx;
			font-size: 24rpx;
			color: #999999;

			.iconfont {
				margin-left: 4rpx;
				font-size: 24rpx;
			}
		}
	}
</style>