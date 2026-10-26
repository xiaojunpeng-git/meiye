<template>
	<!-- 商品分类第三种布局样式 -->
	<view class="goodCate acea-row row-column">
		<view class="mp-header">
			<view class="sys-head" :style="{ height: sysHeight }"></view>
			<view class="serch-box" style="height: 43px;">
				<view class="serch-wrapper acea-row row-middle">
					<text class="iconfont icon-ic_home mr-20 fs-36" @click="goHome"></text>
					<navigator url="/pages/goods/goods_search/index" class="input acea-row row-middle" hover-class="none">
						<text class="iconfont icon-ic_search"></text>
						搜索商品信息
					</navigator>
				</view>
			</view>
		</view>
		<view class="conter acea-row">
			<scroll-view scroll-y="true" class="aside">
				<view class='item acea-row row-center-wrapper' :class='index==navActive?"on":""' v-for="(item,index) in categoryList" :key="index" @click="tapNav(index,item)">
					<text>{{item.cate_name}}</text>
				</view>
				<view class="safe-area-inset-bottom"></view>
			</scroll-view>
			<view class="wrapper acea-row row-column">
				<view class="bgcolor acea-row">
					<view class="longTab acea-row row-middle">
						<scroll-view scroll-x="true" style="white-space: nowrap;" :scroll-into-view="navThreeActiveId" scroll-with-animation show-scrollbar="true">
							<view class="longItem" :id="`navThree${index}`" :style='"width:"+isWidth+"px"' :class="index===tabClick?'click':''" v-for="(item,index) in categoryErList" :key="index" @click="longClick(index)">
								<view class="inner">{{item.cate_name}}</view>
							</view>
						</scroll-view>
					</view>
					<view class="openList">
						<view class="btn" @click="openTap">
							<text class="iconfont icon-ic_downarrow"></text>
						</view>
					</view>
				</view>
				<scroll-view scroll-y="true" class="goodsListWrap" @scroll="goodsListScroll" @scrolltolower="getProducts">
					<!--商品列表 -->
					<goodClass :tempArr="tempArr" :isLogin="isLogin" @gocartduo="goCartDuo" @gocartdan="goCartDan" @ChangeCartNumDan="ChangeCartNumDan"></goodClass>
					<view class='loadingicon acea-row row-center-wrapper'>
						<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
					</view>
					<view class="safe-area-inset-bottom"></view>
				</scroll-view>
			</view>
		</view>
		<view class="footer">
			<view class="inner-box" :class="{ open: footerOpen }">
				<view class="inner acea-row row-between row-middle">
					<BaseMoney :money="totalPrice" symbolSize="32" integerSize="48" decimalSize="32" color="#FFFFFF"></BaseMoney>
					<view class="btn" @click="subOrder">去下单({{cartNum}})</view>
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
		<cartList :cartNums="cartNum" :cartData="cartData" @closeList="closeList" @ChangeCartNumDan="ChangeCartList" @ChangeSubDel="ChangeSubDel" @ChangeOneDel="ChangeOneDel"></cartList>
		<!-- 产品属性组件 -->
		<productWindow :attr="attr" :isShow='1' :iSplus='1' :iScart='1' :type="2" :storeInfo="storeInfo" @myevent="onMyEvent" @ChangeAttr="ChangeAttr" @ChangeCartNum="ChangeCartNumDuo" @attrVal="attrVal"
			@iptCartNum="iptCartNum" @goCat="goCatNum" id='product-window'></productWindow>
		<!-- 筛选 -->
		<view v-if="!iSlong">
			<view class="downTab acea-row row-column">
				<view :style="{height:getHeight.barTop+'px'}"></view>
				<view :style="{height:getHeight.barHeight+'px'}"></view>
				<view class="title">{{categoryTitle}}</view>
				<scroll-view scroll-y="true" class="scroll-view">
					<view class="children">
						<view class="item line1" :class="index===tabClick?'click':''" v-for="(item,index) in categoryErList" :key="index" @click="longClick(index)">
							<view class="inner">{{item.cate_name}}</view>
						</view>
					</view>
				</scroll-view>
				<view class="closeList" @click="closeTap">
					点击收起<text class="iconfont icon-ic_uparrow"></text>
				</view>
			</view>
			<view class="mask" @click="closeTap"></view>
		</view>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight + 'px';

	import {
		cartDel
	} from '@/api/order.js';
	import {
		getCategoryList,
		getAttr,
		postCartNum,
		getTableCartCount,
		getCateList,
		addTableCate,
		placeOrder,
		emptyTableData,
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
	export default {
		computed: {
			...mapGetters(['isLogin', 'uid'])
		},
		components: {
			productWindow,
			goodClass,
			cartList
		},
		props: {
			info: {
				type: Object,
				default: {}
			},
			pageVisible: {
				type: Boolean,
				default: true
			}
		},
		data() {
			return {
				getHeight: this.$util.getWXStatusHeight(),
				switchNum: 1,
				sysHeight: sysHeight,
				categoryList: [],
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
				navThreeActiveId: '',
			}
		},
		watch: {
			pageVisible(value) {
				if (value) {
					return;
				}
				if (!this.timer || this.timer.constructor.name != 'Object') {
					return;
				}
				Object.values(this.timer).forEach(this.clearTimer);
			},
			info: {
				handler(value) {
					if (!value.store_id || !value.tableId) {
						return;
					}
					this.getCartNum();
					this.getCartList(1);
				},
				deep: true
			}
		},
		mounted() {
			let that = this;
			// 获取设备宽度
			uni.getSystemInfo({
				success(e) {
					that.isWidth = e.windowWidth / 5
				}
			});
		},
		methods: {
			goHome(){
				uni.switchTab({
					url:'/pages/index/index'
				})
			},
			// 取消定时器
			clearTimer(timerID) {
				if (timerID) {
					clearTimeout(timerID);
				}
			},
			// 设置定时器
			setTimer(timerID, callback) {
				if (typeof callback != 'function') {
					return;
				}
				if (!this.timer || this.timer.constructor.name != 'Object') {
					this.timer = {};
				}
				this.clearTimer(this.timer[timerID]);
				this.timer[timerID] = setTimeout(callback, 5000);
			},
			// 重载商品数据
			reloadProducts() {
				getProducts({
					page: 1,
					limit: this.tempArr.length,
					type: 1,
					cid: this.cid,
					sid: this.sid,
					store_id: this.info.id,
					delivery_type: '',
					collate_code_id: this.info.tableId,
				}).then(res => {
					this.$set(this, 'tempArr', res.data);
					this.setTimer('reloadProducts', this.reloadProducts);
				});
			},
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
					store_id: that.info.id,
					delivery_type: '',
					collate_code_id: that.info.tableId,
				}).then(res => {
					let list = res.data,
						loadend = list.length < that.limit;
					that.tempArr = that.$util.SplitArray(list, that.tempArr);
					that.$set(that, 'tempArr', that.tempArr);
					that.loading = false;
					that.loadend = loadend;
					that.loadTitle = loadend ? "没有更多内容啦~" : "加载更多";
					that.page = that.page + 1;
					this.setTimer('reloadProducts', this.reloadProducts);
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
			// 跳转到门店列表
			goMap() {
				uni.navigateTo({
					url: "/pages/store/store_list/index?storeFrom=1&type=1&storeId=" + this.info.id,
					success(res) {
						console.log('成功啦', res);
					},
					fail(err) {
						console.log('失败啦', err);
					}
				})
			},
			// 生成订单；
			subOrder: function() {
				placeOrder({
					tableId: this.info.tableId,
					storeId: this.info.store_id,
				}).then(() => {
					uni.navigateTo({
						url: `/pages/store/table_confirm/index?tableId=${this.info.tableId}&store_number_diners_window=${this.info.store_number_diners_window}`
					});
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				});
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
				let result = true
				for (let i = 0; i < this.cartData.cartList.length; i++) {
					if (this.cartData.cartList[i].is_settle) {
						result = false;
						break;
					}
				}
				if(!result) {
					return
				}
				uni.showModal({
					title: '确定要清空吗？',
					content: '清空后，已点的商品会被删除',
					success: (res) => {
						if (res.confirm) {
							emptyTableData({
								tableId: this.info.tableId
							}).then(res => {
								this.$util.Tips({
									title: '清空成功'
								});
								this.getCartNum();
								this.getCartList(1);
							});
						}
					}
				});
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
					if (!cart) {
						that.getCartList(1);
					}
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
				let that = this;
				let {
					store_id,
					tableId
				} = this.info;
				getCateList({
					store_id,
					tableId
				}).then(res => {
					that.$set(that.cartData, 'cartList', res.data);
					if (res.data.length) {
						that.$set(that.cartData, 'iScart', iSshow ? false : !that.cartData.iScart);
					} else {
						that.$set(that.cartData, 'iScart', false);
					}
					that.getTotalPrice();
				});
			},
			closeList(e) {
				this.$set(this.cartData, 'iScart', e);
			},
			// 获取购物车商品数量
			getCartNum: function() {
				let {
					store_id,
					tableId
				} = this.info;
				getTableCartCount({
					store_id,
					tableId
				}).then(res => {
					this.cartNum = res.data.count;
					this.setTimer('getCartNum', this.getCartNum);
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
				if (!changeValue && num.is_settle) {
					return
				}
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
				if (!changeValue && num.is_settle) {
					return
				}
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
					isAdd: type,
					uniqueId: duo ? that.attr.productSelect.unique : cart ? unique : "",
					tableId: this.info.tableId,
					storeId: this.info.id,
				};
				addTableCate(q).then(res => {
					if (duo) {
						that.attr.cartAttr = false;
						that.$util.Tips({
							title: "添加购物车成功"
						});
						// that.page = 1;
						// that.loadend = false;
						that.tempArr.forEach((item, index) => {
							if (item.id == that.id) {
								let arrtStock = that.attr.productSelect.stock
								let objNum = parseInt(item.cart_num) + parseInt(that.attr.productSelect.cart_num);
								item.cart_num = objNum > arrtStock ? arrtStock : objNum
							}
						})
						// that.productslist();
					}
					that.getCartNum();
					if (!cart) {
						that.getCartList(1);
					}
				}).catch(err => {
					that.$util.Tips({
						title: err
					});
				});
			},
			goCartDuo(item) {
				if (!this.isLogin) {
					this.getIsLogin();
				} else {
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
					that.$set(that.attr, 'productAttr', res.data.productAttr);
					that.$set(that, 'productValue', res.data.productValue);
					that.$set(that, 'storeInfo', res.data.storeInfo);
					that.DefaultSelect();
				})
			},
			openTap() {
				this.iSlong = false
			},
			closeTap() {
				this.iSlong = true
			},
			// 分类数据
			getAllCategory: function(product_category_status, id) {
				let that = this;
				let params = {};
				if (product_category_status) {
					params.relation_id = id;
				}
				getStoreCategory(params).then(res => {
					let data = res.data;
					data.forEach(item => {
						item.children.unshift({
							'id': 0,
							'cate_name': '全部商品'
						})
					})
					that.categoryTitle = data[0].cate_name;
					that.cid = data[0].id;
					that.sid = 0;
					that.navActive = 0;
					that.tabClick = 0;
					that.categoryList = data;
					that.categoryErList = res.data[0].children ? res.data[0].children : [];
					that.page = 1;
					that.loadend = false;
					that.tempArr = [];
					that.getProducts();
				})
			},
			tapNav(index, item) {
				uni.pageScrollTo({
					duration: 0,
					scrollTop: 0
				})
				let list = this.categoryList[index];
				this.navActive = index;
				this.categoryTitle = list.cate_name;
				this.categoryErList = item.children ? item.children : [];
				this.tabClick = 0;
				this.tabLeft = 0;
				this.cid = list.id;
				this.sid = 0;
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
				this.getProducts();
			},
			// 导航栏点击
			longClick(index) {
				// if (this.categoryErList.length > 3) {
				// 	this.tabLeft = (index - 1) * (this.isWidth + 6) //设置下划线位置
				// };
				if (index > 1) {
					this.navThreeActiveId = `navThree${index - 1}`;
				} else {
					this.navThreeActiveId = 'navThree0';
				}
				this.tabClick = index; //设置导航点击了哪一个
				this.iSlong = true;
				this.sid = this.categoryErList[index].id;
				this.page = 1;
				this.loadend = false;
				this.tempArr = [];
				this.getProducts();
			},
			goodsListScroll() {
				this.footerOpen = false;
			},
		},
	}
</script>

<style lang="scss">
	.safe-area-inset-bottom {
		height: constant(safe-area-inset-bottom);
		height: env(safe-area-inset-bottom);
	}

	.mp-header {
		.serch-wrapper {
			height: 100%;
			/* #ifdef MP */
			padding: 0 210rpx 0 32rpx;

			/* #endif */
			.input {
				flex: 1;
				min-width: 0;
				height: 58rpx;
				padding: 0 0 0 32rpx;
				border-radius: 29rpx;
				background: #F5F5F5;
				color: #999999;
				font-size: 24rpx;

				.iconfont {
					margin-right: 18rpx;
					font-size: 28rpx;
				}
			}
		}
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
		height: 100vh;
		background: #FFFFFF;

		::v-deep .mask {
			z-index: 99;
		}

		::v-deep .attrProduct {
			.mask {
				z-index: 100;
			}
		}

		.conter {
			flex: 1;
			flex-wrap: nowrap;
			min-height: 0;

			.aside {
				width: 168rpx;
				background: #F5F5F5;

				.item {
					height: 100rpx;
					width: 100%;
					font-size: 26rpx;
					color: #333333;

					&.on {
						background-color: #FFFFFF;
						width: 100%;
						text-align: center;
						color: var(--view-theme);
						font-weight: 500;
						position: relative;

						&::after {
							content: "";
							position: absolute;
							width: 6rpx;
							height: 46rpx;
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

				.bgcolor {
					flex-wrap: nowrap;
				}

				.mask {
					z-index: 9;
				}

				.longTab {
					flex: 1;
					min-width: 0;
					height: 104rpx;

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
					height: 104rpx;
					text-align: center;
					line-height: 104rpx;

					.btn {
						display: inline-block;
						width: 56rpx;
						height: 56rpx;
						border-radius: 28rpx;
						background: #F5F5F5;
						line-height: 56rpx;
					}

					.iconfont {
						font-size: 32rpx;
						color: #333333;
					}
				}

				.goodsListWrap {
					flex: 1;
					min-height: 0;
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
			}
		}

		.footer {
			position: fixed;
			right: 20rpx;
			left: 20rpx;
			bottom: 40rpx;
			bottom: calc(40rpx + constant(safe-area-inset-bottom));
			bottom: calc(40rpx + env(safe-area-inset-bottom));
			z-index: 151;

			.cartIcon {
				position: absolute;
				bottom: 0;
				left: 0;
				width: 96rpx;
				height: 96rpx;
				border-radius: 48rpx;
				background: linear-gradient(90deg, var(--view-gradient) 0%, var(--view-theme) 100%);
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
				background: linear-gradient(90deg, var(--view-gradient) 0%, var(--view-theme) 100%);
				border-radius: 36rpx;
				text-align: center;
				font-weight: 500;
				font-size: 26rpx;
				line-height: 72rpx;
				color: #FFFFFF;
			}
		}
	}

	.downTab {
		position: fixed;
		top: 0;
		left: 0;
		z-index: 102;
		flex-wrap: nowrap;
		width: 100%;
		max-height: 500rpx;
		border-radius: 0rpx 0rpx 32rpx 32rpx;
		background: #FFFFFF;
		overflow: hidden;

		.title {
			padding: 32rpx 0 24rpx 30rpx;
			font-size: 32rpx;
			line-height: 44rpx;
			color: #333333;

			.closeList {
				width: 90rpx;
				height: 100%;
				line-height: 100rpx;
				padding-left: 30rpx;
				transform: rotate(180deg);

				.iconfont {
					font-size: 22rpx;
					color: #666666;
				}
			}
		}

		.scroll-view {
			flex: 1;
			min-height: 0;
			overflow: auto;
		}

		.children {
			padding: 0 0 0 30rpx;

			.item {
				display: inline-block;
				width: 154rpx;
				height: 56rpx;
				border-radius: 28rpx;
				margin: 0 24rpx 24rpx 0;
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
		}

		.closeList {
			height: 80rpx;
			border-top: 1rpx solid #EEEEEE;
			transform: rotateZ(360deg);
			text-align: center;
			font-size: 24rpx;
			line-height: 80rpx;
			color: #999999;

			.iconfont {
				margin-left: 4rpx;
				font-size: 24rpx;
			}
		}
	}
</style>