// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import {
	getCategoryList,
	getProductslist,
	getAttr,
	postCartNum
} from '@/api/store.js';
import {cartDel,getCartStoreListApi} from "@/api/order.js";
import {toLogin} from '@/libs/login.js';
export default {
	data() {
		return {
			attr: {
				cartAttr: false,
				productAttr: [],
				productSelect: {}
			},
			productValue: [],
			delivery_type: 0,
			store_id: 0, // 规格弹窗中选择的门店
			storeChecked: 0, // 购物车弹窗中选择的门店
			cartNumChecked: 0,
			deliveryFrom: 1,
			isStoreCart: false,
		};
	},
	mounted() {
		let shopOperationType = uni.getStorageSync('shop_operation_type');
		this.isStoreCart = shopOperationType == 3;
	},
	methods: {
		updateFun(e,num){
			if(e.cartNum){
				this.tempArr.forEach((item)=>{
					if(item.id == e.id){
						item.cart_num = e.cartNum
					}
				})
				// 只有门店页面过来时调用
				if(num){
					this.getCartNum();
				}
			}
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
		/**
		 * 购物车手动填写
		 *
		 */
		iptCartNum: function(e) {
			this.$set(this.attr.productSelect, 'cart_num', e);
		},
		onMyEvent: function() {
			this.$set(this.attr, 'cartAttr', false);
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
		// 改变单属性购物车
		ChangeCartNumDan(changeValue, index, item) {
			let num = this.tempArr[index];
			let stock = this.tempArr[index].stock;
			this.ChangeCartNum(changeValue, num, stock, 0, item.id);
		},
		ChangeSubDel: function(event) {
			let that = this,
				list = that.cartData.cartList,
				ids = [];
			if (that.deliveryFrom == 1) {
				list.forEach(item => {
					ids.push(item.id)
				});
			}
			cartDel(ids.join(","), that.deliveryFrom == 1 ? 0 : -1).then(res => {
				that.totalPrice = 0.00;
				that.page = 1;
				that.loadend = false;
				that.tempArr = [];
				that.productslist();
				that.getCartNum();
				that.getCartList();
				this.getCartStoreList();
			})
		},
		ChangeOneDel: function(id, index) {
			let that = this,
				list = that.cartData.cartList;
			cartDel(id.toString()).then(res => {
				list.splice(index, 1);
				if (!list.length) {
					that.cartData.iScart = false;
					that.page = 1;
					that.loadend = false;
					that.tempArr = [];
					that.productslist();
				};
				that.getCartNum();
			})
		},
		// 多规格加入购物车；
		goCatNum() {
			this.goCat(1, this.id, 1);
		},
		closeList(e) {
			this.$set(this.cartData, 'iScart', e);
		},
		// 已经加入购物车时的购物加减；
		ChangeCartList(changeValue, index, cartId) {
			let list = null;
			let num = null;
			let stock = null;
			if (cartId) {
				// 门店加减
				// index是门店store_id
				const storeIndex = this.cartData.cartStoreList.findIndex(item => item.store_id == index);
				const storeItem = this.cartData.cartStoreList[storeIndex];
				for (let i = 0; i < storeItem.valid.length; i++) {
					if (num) {
						break;
					}
					for (let j = 0; j < storeItem.valid[i].cart.length; j++) {
						if (storeItem.valid[i].cart[j].id == cartId) {
							num = storeItem.valid[i].cart[j];
							stock = storeItem.valid[i].cart[j].trueStock;
							break;
						}
					}
				}
			} else{
				// 商城加减
				list = this.cartData.cartList;
				num = list[index];
				stock = list[index].trueStock;
			}
			this.ChangeCartNum(changeValue, num, stock, 0, num.product_id, index, 1);
			// if (!list.length) {
			// 	this.cartData.iScart = false;
			// 	this.page = 1;
			// 	this.loadend = false;
			// 	this.tempArr = [];
			// 	this.productslist();
			// }
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
							// this.getTotalPrice();
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
							// 购物车中加减操作
							this.goCat(0, id, 0, 1, num.product_attr_unique);
							// this.getTotalPrice();
						} else {
							this.goCat(0, id, 0);
						}
					}
				}
			}
			this.tempArr.forEach((item)=>{
				if(item.id == id){
					item.cart_num = num.cart_num;
				}
			})
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
				) {
					return that.$util.Tips({
						title: "产品库存不足，请选择其它属性"
					});
				}
			}
			let q = {
				product_id: id,
				num: duo ? that.attr.productSelect.cart_num : 1,
				type: type,
				unique: duo ? that.attr.productSelect.unique : cart ? unique : "",
			};
			if (this.attr.cartAttr) {
				q.store_id = this.store_id;
			} else if (this.cartData.iScart) {
				q.store_id = this.deliveryFrom == 1 ? 0 : this.storeChecked;
			}
			postCartNum(q)
				.then(function(res) {
					if (duo) {
						that.attr.cartAttr = false;
						that.tempArr.forEach((item, index) => {
							if (item.id == that.id) {
								let arrtStock = that.attr.productSelect.stock
								let objNum = parseInt(item.cart_num) + parseInt(that.attr.productSelect.cart_num);
								item.cart_num = objNum > arrtStock?arrtStock:objNum
							}
						})
						// that.productslist();
					}
					
					that.$util.Tips({
						title: res.msg
					});
					
					that.getCartNum();
					// if (!cart) {
					// 	that.getCartList(1);
					// }
					// if (that.cartData.iScart) {
						if (that.deliveryFrom == 1) {
							that.getCartList();
						} else{
							that.getCartStoreList();
						}
					// }
				})
				.catch(err => {
					return that.$util.Tips({
						title: err
					});
				});
		},
		goCartDuo(item,num) {
			if (!this.isLogin) {
				toLogin();
			} else {
				if(item.cart_button == 0){
					if(item.is_presale_product){
						uni.navigateTo({
							url: `/pages/activity/goods_details/index?id=${item.id}&type=6`
						})
					}else{
						//num:表示从首页点击进来的
						let page1 = `/pages/goods_details/index?id=${item.id}&fromType=1`;
						let page2 = `/pages/goods_details/index?id=${item.id}`;
						uni.navigateTo({
							url: num?page2:page1
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
		// 点击默认单属性购物车
		goCartDan(item, index, num) {
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
							url: `/pages/goods_details/index?id=${item.id}&fromType=1`
						})
					}
				}else{
					this.tempArr[index].cart_num = 1;
					// num表示从门店过来的；
					// if(num){
					// 	this.$store.commit('indexData/setCartNum', parseInt(this.cartNum)+1)
					// }
					this.$set(this, 'tempArr', this.tempArr);
					this.goCat(0, item.id, 1);
				}
			}
		},
		// 获取配送方式
		deliveryFun(e) {
			this.delivery_type = e;
		},
		// 获取到店核销地址id
		onstoreId(row,flag) {
			// this.storeList = row;
			if(flag == 2 || flag == 3){
			   this.store_id = row.id;
			   // this.storeId = row.id;
			   this.store_name = row.name;
			   // this.getStoreDetail(row.id);
			}else{
			   this.store_id = 0;
			   this.store_name = '';
			   // this.getStoreDetail(0,this.storeInfo.pid);
			}
		},
		toggleCart(){
			if(this.footerOpen){
				this.cartData.iScart = !this.cartData.iScart;
				this.getCartNum();
				if (this.deliveryFrom == 1) {
					this.getCartList(0)
				} else{
					this.getCartStoreList();
				}
			}else{
				this.footerOpen = true
			}
		},
		// 购物车门店选择
		storeCheckedChange(storeId) {
			this.storeChecked = storeId;
			const storeItem = this.cartData.cartStoreList.find(item => item.store_id == this.storeChecked);
			this.totalPrice = storeItem.pay_price;
			this.cartNumChecked = storeItem.count;
		},
		// 购物车商城/门店切换
		deliveryFromChange(deliveryFrom) {
			this.deliveryFrom = deliveryFrom;
			if (deliveryFrom == 1) {
				// 商城
				this.storeChecked = 0;
				this.getCartNum();
				this.getCartList();
			} else{
				// 门店
				this.getCartStoreList();
			}
		},
		// 获取购物车门店数据
		getCartStoreList() {
			getCartStoreListApi({
				status: 1,
			}).then(res => {
				this.cartData.cartStoreList = res.data;
				if (res.data.length) {
					if (this.deliveryFrom == 2) {
						// 默认选中第一个门店
						if (!this.storeChecked) {
							this.storeChecked = res.data[0].store_id;
						}
						const storeItem = this.cartData.cartStoreList.find(item => item.store_id == this.storeChecked);
						// 购物车结算金额
						this.totalPrice = storeItem.pay_price;
						
						// 购物车结算商品数量
						// this.cartNumChecked = storeItem.count;
					}
				}
			}).catch(err => {});
		},
	}
};
