import { adminCartAdd, adminCartDel, adminCartNum, orderAllCartApi } from "@/api/admin.js"
export default {
	data() {
		return {
			attr: {
				cartAttr: false,
				productAttr: [],
				productSelect: {},
				deliveryType: [],
			},
			productValue: [],
			id: 0,
			deliveryFrom: 1,
		};
	},
	created() {
		
	},
	methods: {
		/**
		 * 默认选中属性
		 * 
		 */
		DefaultSelect: function() {
			let productAttr = this.attr.productAttr;
			let valueobj = [];
			let value = [];
			for (var key in this.productValue) {
				if (this.productValue[key].stock > 0) {
					valueobj = this.attr.productAttr.length ? key.split(',') : [];
					break;
				}
			}
			// 处理已售罄时默认选中第一个
			if (!valueobj.length && this.attr.productAttr.length) {
				value = Object.keys(this.productValue)[0].split(',');
			} else {
				value = valueobj;
			}
			for (let i = 0; i < productAttr.length; i++) {
				this.$set(productAttr[i], 'index', value[i]);
			}
			//sort();排序函数:数字-英文-汉字；
			let productSelect = this.productValue[value.join(',')];
			this.$set(this.attr.productSelect, 'store_name', this.storeInfo.store_name);
			this.$set(this.attr.productSelect, 'spec_type', this.storeInfo.spec_type);
			if (productSelect && productAttr.length) {
				this.$set(this.attr.productSelect, 'image', productSelect.image);
				this.$set(this.attr.productSelect, 'price', productSelect.price);
				this.$set(this.attr.productSelect, 'stock', productSelect.stock);
				this.$set(this.attr.productSelect, 'unique', productSelect.unique);
				this.$set(this.attr.productSelect, 'cart_num', 1);
				this.$set(this, 'attrValue', value.join(','));
				this.$set(this.attr.productSelect, 'vip_price', productSelect.vip_price);
				this.$set(this, 'attrTxt', '已选择');
			} else if (!productSelect && productAttr.length) {
				this.$set(this.attr.productSelect, 'image', this.storeInfo.image);
				this.$set(this.attr.productSelect, 'price', this.storeInfo.price);
				this.$set(this.attr.productSelect, 'stock', 0);
				this.$set(this.attr.productSelect, 'unique', '');
				this.$set(this.attr.productSelect, 'cart_num', 0);
				this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
				this.$set(this, 'attrValue', '');
				this.$set(this, 'attrTxt', '请选择');
			} else if (!productSelect && !productAttr.length) {
				this.$set(this.attr.productSelect, 'image', this.storeInfo.image);
				this.$set(this.attr.productSelect, 'price', this.storeInfo.price);
				this.$set(this.attr.productSelect, 'stock', this.storeInfo.stock);
				this.$set(this.attr.productSelect, 'unique', this.storeInfo.unique || '');
				this.$set(this.attr.productSelect, 'cart_num', 1);
				this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
				this.$set(this, 'attrValue', '');
				this.$set(this, 'attrTxt', '请选择');
			}
		},
		/**
		 * 属性变动赋值
		 * 
		 */
		ChangeAttr: function(res) {
			let productSelect = this.productValue[res];
			this.$set(this, "selectSku", productSelect);
			if (productSelect && productSelect.stock >= 0) {
				this.$set(this.attr.productSelect, 'image', productSelect.image);
				this.$set(this.attr.productSelect, 'price', productSelect.price);
				this.$set(this.attr.productSelect, 'stock', productSelect.stock);
				this.$set(this.attr.productSelect, 'unique', productSelect.unique);
				this.$set(this.attr.productSelect, 'cart_num', 1);
				this.$set(this.attr.productSelect, 'vip_price', productSelect.vip_price);
				this.$set(this, 'attrValue', res);
				this.$set(this, 'attrTxt', '已选择');
			} else {
				this.$set(this.attr.productSelect, 'image', this.storeInfo.image);
				this.$set(this.attr.productSelect, 'price', this.storeInfo.price);
				this.$set(this.attr.productSelect, 'stock', 0);
				this.$set(this.attr.productSelect, 'unique', '');
				this.$set(this.attr.productSelect, 'cart_num', 0);
				this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
				this.$set(this, 'attrValue', '');
				this.$set(this, 'attrTxt', '请选择');
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
			if(changeValue){
				if(this.attr.productSelect.cart_num == this.attr.productSelect.stock) return this.$util.Tips({
					title: '该产品没有更多库存了'
				});
				this.attr.productSelect.cart_num++;
			}else{
				if(this.attr.productSelect.cart_num == 1) return
				this.attr.productSelect.cart_num--;
			}
		},
		// 多规格加入购物车；
		goCatNum(type) {
			this.goCartChange(true,null,type);
		},
		goCartChange(duo, productId,type){
			let data = {
				productId: duo ? this.id : productId,
				cartNum: duo ? this.attr.productSelect.cart_num : 1,
				uniqueId: duo ? this.attr.productSelect.unique : "",
				'new':type,
				tourist_uid: this.touristId,
				store_id: this.store_id,
			};
			adminCartAdd(this.userId,data).then(res=>{
				if(duo){
					this.attr.cartAttr = false;
				}
				if(type){
					uni.navigateTo({
						url: `/pages/behalf/order_confirm/index?cartId=${res.data.cartId}&uid=${this.userId}&news=1&delivery_type=${this.delivery_type}&addressId=${this.addressId}&store_id=${this.store_id}`
					});
				}
				this.$util.Tips({
					title: '加入购物车成功'
				});
				this.getCartList(1);
				this.getCartStoreList();
				
			}).catch(err => {
				return this.$util.Tips({
					title: err
				});
			});
		},
		goCartDuo(item) {
			if (!this.isLogin) {
				toLogin();
			} else {
				this.storeName = item.store_name;
				this.getAttrs(item.id);
				this.$set(this, 'id', item.id);
				this.$set(this.attr, 'isType', item.type);
				this.$set(this.attr, 'deliveryType', item.delivery_type);
				this.$set(this.attr, 'cartAttr', true);
			}
		},
		// 点击默认单属性购物车
		goCartDan(item) {
			if (!this.isLogin) {
				this.getIsLogin();
			} else {
				this.goCartChange(false, item.id,item.cart_button == 1 ? 0 : 1);
			}
		},
		closeList(e) {
			this.$set(this.cartData, 'iScart', e);
		},
		selectitem(index){
			this.cartData.cartList[index].select = !this.cartData.cartList[index].select;
			let isSelect = this.cartData.cartList.filter(el=> el.select == true);
			if(isSelect.length == this.cartData.cartList.length){
				this.$refs.cartPopup.allSelect = true;
			}else{
				this.$refs.cartPopup.allSelect = false;
			}
		},
		selectAll(val){
			this.cartData.cartList.map(item=>{
				this.$set(item,'select',val ? false : true);
			})
		},
		cartDelChange(deliveryFrom){
			let ids = [];
			if (deliveryFrom == 1) {
				this.cartData.cartList.forEach(item=>{
					if(item.select){
						ids.push(item.id);
					}
				})
			} else{
				const storeItem = this.cartData.cartStoreList.find(item => item.select);
				if (storeItem) {
					storeItem.valid.forEach(item=>{
						if(item.select){
							ids.push(item.id);
						}
					})
				}
			}
			if(!ids.length) return this.$util.Tips({
				title: '请先选择商品'
			});
			adminCartDel(this.userId,{
				ids: ids.toString(),
				tourist_uid: this.touristId,
			}).then(res=>{
				if (deliveryFrom == 1) {
					this.getCartList(1);
				} else{
					this.getCartStoreList();
				}
				this.$util.Tips({
					title: err
				});
			}).catch(err => {
				this.$util.Tips({
					title: err
				});
			});
		},
		cartNumChange(data){
			if(data.type){
				if(data.item.cart_num == data.item.productInfo.attrInfo.stock) return this.$util.Tips({
					title: '该产品没有更多库存了'
				});
			}else{
				if(data.item.cart_num == 1) return
			}
			const newNumber = data.type ? data.item.cart_num + 1 : data.item.cart_num - 1;
			adminCartNum(this.userId,{
				id: data.item.id,
				number: newNumber,
				tourist_uid: this.touristId
			}).then(res=>{
				if (data.sIndex === undefined) {
					this.cartData.cartList[data.index].cart_num = newNumber;
				} else{
					this.cartData.cartStoreList[data.sIndex].valid[data.index].cart_num = newNumber;
				}
				this.getTotalPrice();
				this.$util.Tips({
					title: res.msg
				});
			}).catch(err => {
				return this.$util.Tips({
					title: err
				});
			});
		},
		deliveryFromChange(deliveryFrom) {
			this.deliveryFrom = deliveryFrom;
			if (deliveryFrom == 1) {
				this.getCartList(1);
			} else{
				this.getCartStoreList();
			}
		},
		selectStore(storeIndex) {
			this.cartData.cartStoreList.forEach((item, index) => {
				this.$set(item, 'select', index == storeIndex ? !item.select : false);
				item.valid.forEach(validItem => {
					this.$set(validItem, 'select', item.select);
				});
			});
		},
		selectProduct(productIndex, storeIndex) {
			this.cartData.cartStoreList.forEach((item, index) => {
				if (index == storeIndex) {
					const validItem = item.valid[productIndex];
					this.$set(validItem, 'select', !validItem.select);
					this.$set(item, 'select', item.valid.some(validItem => validItem.select));
				} else{
					if (item.select) {
						this.$set(item, 'select', false);
						item.valid.forEach(validItem => {
							validItem.select = false;
						});
					}
				}
			});
		},
		toggleCart() {
			this.cartData.iScart = !this.cartData.iScart;
			if (this.cartData.iScart) {
				this.getCartList();
				this.getCartStoreList();
			}
		},
		getCartStoreList() {
			orderAllCartApi(this.userId, { tourist_uid: this.touristId }).then(res => {
				this.cartData.cartStoreList = res.data;
				if (this.cartData.cartStoreList.length) {
					this.selectStore(0);
				}
			});
		},
	}
};
