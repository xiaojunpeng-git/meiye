<template>
	<!-- 凑单弹窗 -->
	<view class="">
		<baseDrawer :visible="visible" :zIndex="zIndex" :maskZIndex="zIndex" mode="bottom" backgroundColor="transparent" @close="onClose">
			<view class="rd-t-40rpx bg--w111-fff">
				<view class="">
					<view class="flex-y-center h-108 pl-24 rd-t-40rpx">
						<view class="flex flex-bottom">
							<view class="fw-500 fs-32">凑单商品</view>
							<view class="ml-12 fs-26">还差<text class="text-w111-2A7EFB">{{Number(poorDeliveryPrice)}}元</text>起送</view>
						</view>
					</view>
					<view class="abs-rt flex-center w-100 h-108">
						<view class="flex-center w-36 h-36 rd-50-p111- bg--w111-eee" @tap="onClose">
							<text class="iconfont icon-ic_close fs-24"></text>
						</view>
					</view>
				</view>
				<scroll-view scroll-x="true" class="white-nowrap">
					<view v-for="(item, index) in groupTabs" :key="index" :class="{
						'border-2A7EFB bg-w111-E9F2FE text-w111-2A7EFB': groupTabChecked == index,
						'ml-24': index == 0,
						'mr-24': index == groupTabs.length - 1,
					}" class="flex-y-center inline-flex h-54 px-20 border-F5F5F5-1 rd-8rpx mr-20 bg--w111-f5f5f5 fs-24" @tap="groupTabChange(index)">{{item.title}}</view>
				</scroll-view>
				<scroll-view scroll-y="true" class="pt-24" style="height: 990rpx;">
					<view class="px-24 mb-24 flex justify-between" v-for="(item,index) in groupProducts" :key="index">
						<easy-loadimage class="bg-w111-f9f9f9" mode="aspectFit" :image-src="item.image" width="176rpx" height="176rpx" borderRadius="16rpx"></easy-loadimage>
						<view class="flex-1 flex-col justify-between pl-20">
							<view class="w-full">
								<view class="line1 w-346 fs-28 text-#333 lh-40rpx">
									<text v-if="item.brand_name" class="brand-tag">{{ item.brand_name }}</text>{{item.store_name}}
								</view>
								<view class="flex items-end flex-wrap mt-12 w-full">
									<BaseTag :text="label.label_name" :color="label.color" :background="label.bg_color" :borderColor="label.border_color" :circle="label.border_color ? true : false" :imgSrc="label.icon" v-for="(label, idx) in item.store_label" :key="idx"></BaseTag>
								</view>
							</view>
							<view class="flex-between-center flex-bottom">
								<view class="flex items-baseline flex-wrap flex-1">
									<baseMoney :money="item.price" symbolSize="24" integerSize="40" decimalSize="24" weight>
									</baseMoney>
									<view class="inline-block h-26 lh-28rpx rd-14rpx bg--w111-F7E9CD fs-22 ml-8" v-if="Number(item.vip_price) > 0">
										<text class="inline-block h-26 lh-28rpx svip-rd fs-18 bg-w111-484643 text-w111-FDDAA4 px-8">SVIP</text>
										<text class="px-8 fs-22 SemiBold">¥{{item.vip_price}}</text>
									</view>
								</view>
								<view class="flex-center w-48 h-48 rd-30rpx bg-w111-2A7EFB text--w111-fff relative" @tap.stop="goCartDuo(item)" v-if="item.spec_type">
									<text class="iconfont icon-ic_ShoppingCart1 fs-30"></text>
									<uni-badge class="badge-style" :custom-style="{background: '#2A7EFB'}" v-if="item.cart_num" :text="item.cart_num"></uni-badge>
								</view>
								<view v-if="!item.spec_type && !item.cart_num">
									<view class="flex-center w-48 h-48 rd-30rpx bg-w111-2A7EFB text--w111-fff" @tap.stop="goCartDuo(item,1)">
										<text class="iconfont icon-ic_ShoppingCart1 fs-30"></text>
									</view>
								</view>
								<view class="flex-y-center" v-if="!item.spec_type && item.cart_num">
									<view class="flex-center w-48 h-48 rd-30rpx bg--w111-f5f5f5 text--w111-333" @tap.stop="CartNumDes(index,item,-1)">
										<text class="iconfont icon-ic_Reduce fs-32"></text>
									</view>
									<view class="fs-30 text--w111-333 px-20">{{item.cart_num}}</view>
									<view class="flex-center w-48 h-48 rd-30rpx bg-w111-2A7EFB text--w111-fff" @tap.stop="CartNumAdd(index,item,0)">
										<text class="iconfont icon-ic_increase fs-32"></text>
									</view>
								</view>
							</view>
						</view>
					</view>
				</scroll-view>
				<view class="h-96"></view>
				<view class="pb-safe"></view>
			</view>
		</baseDrawer>
		<productWindow ref="productWindow" :attr="attr" :zIndex="21" okButton @myevent="onMyEvent" @ChangeAttr="ChangeAttr" @ChangeCartNum="ChangeCartNumDuo" @attrVal="attrVal" @iptCartNum="iptCartNum" @goCat="goCatNum" id='product-window'></productWindow>
	</view>
</template>

<script>
	import productWindow from './productWindow.vue'
	import {
		orderGroupProductApi,
	} from '@/api/order.js';
	import {
		getAttr,
		postCartNum,
	} from '@/api/store.js';
	import {
		adminCartAdd,
	} from '@/api/admin.js';

	export default {
		components: {
			productWindow,
		},
		props: {
			visible: {
				type: Boolean,
				default: false,
			},
			zIndex: {
				type: [String, Number],
			},
			storeId: {
				type: [String, Number],
				default: 0,
			},
			poorDeliveryPrice: {
				type: [String, Number],
				default: 0,
			},
			userId: {
				type: [String, Number],
				default: 0,
			},
			news: {
				type: [String, Number],
				default: 0,
			},
			cartInfo: {
				type: Array,
				default: () => ([]),
			},
			cartId: {
				type: [String, Number],
				default: 0,
			},
		},
		data() {
			return {
				groupTabs: [{
						title: '推荐商品',
						value: [0, 0],
					},
					{
						title: '20元以下',
						value: [0, 20],
					},
					{
						title: '20-40元',
						value: [20, 40],
					},
					{
						title: '40-80元',
						value: [40, 80],
					},
					{
						title: '80-100元',
						value: [80, 100],
					},
					{
						title: '100元以上',
						value: [100, 0],
					},
				], // 凑单弹窗显示状态
				groupTabChecked: 0,
				groupProducts: [],
				attr: {
					cartAttr: false,
					productAttr: [],
					deliveryType: [],
					productSelect: {}
				},
				storeInfo: {},
				productValue: [],
				attrValue: '', //已选属性
				selectSku: {},
				skuArr: [],
			}
		},
		watch: {
			visible(value) {
				if (value) {
					this.getProduct();
				}
			},
			cartInfo: {
				handler() {
					const cartInfo = this.cartInfo.filter(item => item.id != this.cartId);
					this.groupProducts.forEach(tempArrItem => {
						if (tempArrItem.cart_num !== undefined) {
							tempArrItem.cart_num = 0;
						}
					});
					for (let item of cartInfo) {
						const index = this.groupProducts.findIndex(tempArrItem => tempArrItem.id == item.product_id);
						if (index > -1) {
							if (this.groupProducts[index].cart_num === undefined) {
								this.$set(this.groupProducts[index], 'cart_num', item.cart_num);
							} else{
								this.groupProducts[index].cart_num += item.cart_num;
							}
						}
					}
				},
				deep: true,
			},
		},
		methods: {
			getProduct() {
				let is_recommend = this.groupTabChecked ? 0 : 1;
				let min_price = 0;
				let max_price = 0;
				if (this.groupTabChecked) {
					min_price = this.groupTabs[this.groupTabChecked].value[0];
					max_price = this.groupTabs[this.groupTabChecked].value[1];
				}
				orderGroupProductApi({
					store_id: this.storeId,
					shipping_type: 3,
					is_store_delivery_type: 2,
					poorDeliveryPrice: this.poorDeliveryPrice,
					is_recommend,
					min_price,
					max_price,
				}).then(res => {
					this.groupProducts = res.data;
				});
			},
			onClose() {
				this.$emit('close');
			},
			groupTabChange(index) {
				this.groupTabChecked = index;
				this.getProduct();
			},
			// 凑单-加购商品
			goCartDuo(item, type) {
				if (item.spec_type) {
					this.attr.cartAttr = true;
					this.attr.productSelect = item;
					this.getAttrs(item.id);
					return;
				}
				// 单规格
				adminCartAdd(this.userId, {
					cartId: item.id,
					productId: item.id,
					cartNum: 1,
					uniqueId: "",
					'new': this.news,
					tourist_uid: this.userId == 0 ? this.$Cache.get('touristId') : '',
					store_id: this.storeId,
					is_set: type, // 1:重置 0:加 -1:减
				}).then(res => {
					this.$util.Tips({
						title: res.msg
					});
					// 更新本地商品数量
					if (item.cart_num === undefined || item.cart_num < 1) {
						this.$set(item, 'cart_num', 1);
					} else {
						if (type == 0) {
							item.cart_num++;
						} else {
							item.cart_num--;
						}
					}
					this.$emit('cartId', `${item.id}`, res.data.cartId);
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				});
			},
			onMyEvent: function() {
				this.$set(this.attr, 'cartAttr', false);
			},
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
			attrVal(val) {
				this.$set(this.attr.productAttr[val.indexw], 'index', this.attr.productAttr[val.indexw].attr_values[val
					.indexn]);
			},
			iptCartNum: function(e) {
				this.$set(this.attr.productSelect, 'cart_num', e);
			},
			// 多规格加入购物车；
			goCatNum() {
				adminCartAdd(this.userId,{
					cartId: this.attr.productSelect.id,
					productId: this.attr.productSelect.id,
					cartNum: this.attr.productSelect.cart_num,
					uniqueId: this.productValue[this.attrValue].unique,
					'new': this.news,
					tourist_uid: this.userId == 0 ? this.$Cache.get('touristId') : '',
					store_id: this.storeId,
					is_set: 1, // 1:重置 0:加 -1:减
				}).then(res => {
					this.$util.Tips({
						title: res.msg
					});
					this.attr.cartAttr = false;
					this.$emit('cartId', `${this.attr.productSelect.id},${this.productValue[this.attrValue].unique}`, res.data.cartId);
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				});
			},
			getAttrs(id) {
				let that = this;
				getAttr(id, 0).then(res => {
					// res.data.storeInfo.delivery_type.sort((x, y) => x - y);
					that.$set(that.attr, 'productAttr', res.data.productAttr);
					that.$set(that, 'productValue', res.data.productValue);
					that.$set(that, 'storeInfo', res.data.storeInfo);
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
					that.DefaultSelect();
				})
			},
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
				// this.$set(this.attr.productSelect, "store_name", this.storeName);
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
					this.$set(this.attr.productSelect, "unique", this.storeInfo.unique || "");
					this.$set(this.attr.productSelect, "cart_num", 1);
					this.$set(this, "attrValue", "");
					this.$set(this.attr.productSelect, 'vip_price', this.storeInfo.vip_price);
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
							this.$set(this, 'groupProducts', this.groupProducts);
							// this.$set(this.cartData, 'cartList', this.cartData.cartList);
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
						// this.cartData.cartList.splice(index, 1);
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
							this.$set(this, 'groupProducts', this.groupProducts);
							// this.$set(this.cartData, 'cartList', this.cartData.cartList);
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
				this.groupProducts.forEach((item) => {
					if (item.id == id) {
						item.cart_num = num.cart_num;
					}
				})
			},
			CartNumAdd(index, item, type) {
				this.goCartDuo(item, type);
			},
			CartNumDes(index, item, type) {
				this.goCartDuo(item, type);
			},
		},
	}
</script>

<style lang="scss" scoped>
	.bg-w111-E9F2FE {
		background-color: #E9F2FE;
	}

	.border-2A7EFB {
		border: 1px solid #2A7EFB;
	}
	.badge-style {
		position: absolute;
		top: -10rpx;
		right: 0;
		transform: translateX(50%);
		// margin-right: 10rpx;
	}
</style>