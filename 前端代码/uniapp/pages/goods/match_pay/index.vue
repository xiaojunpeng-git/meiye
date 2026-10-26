<template>
	<view :style="colorStyle">
		<view>
			<view class="fixed-lt w-full h-212 bg_mask z-900" :style="{ top: statusBarHeight + 'px' }">
				<view class="px-20 h-80 flex mt-12">
					<view class="w-56 h-56 rd-50-p111- bg-black flex-center fs-42 text--w111-fff" @tap="backPage()">
						<text class="iconfont icon-ic_leftarrow"></text>
					</view>
				</view>
			</view>
			<!-- #ifdef MP || APP-PLUS -->
			<view :style="{'height': statusBarHeight + 'px'}"></view>
			<view class="px-20 p-b-20">
			<!-- #endif -->
				<!-- #ifndef MP -->
				<view class="p-20">
				<!-- #endif -->
					<view class="rd-24rpx pro_card" v-for="(item, index) in discountsData" :key="index">
						<view class="w-full h-710 rd-t-24rpx relative">
							<image class="w-full h-full rd-t-24rpx block" mode="aspectFill" :src="item.image"></image>
							<view class="match-box rd-24rpx p-24 text--w111-fff">
								<view class="fs-26 lh-36rpx w-full line1">{{item.title}}</view>
								<view class="fs-22 lh-30rpx w-full line1 mt-12rpx">{{item.products[0].title}}</view>
							</view>
						</view>
						<view class="rd-b-24rpx bg--w111-fff pt-24 pb-32">
							<scroll-view scroll-x="true" scroll-with-animation
								class="white-nowrap vertical-middle w-686 ml-24" show-scrollbar="false">
								<view class="inline-block mr-24" v-for="(pro,indexn) in item.products" :key="indexn">
									<view class="w-222 rd-24rpx con-border relative">
										<image class="w-full h-222 rd-t-24rpx block" :src="pro.image"
											@tap="checkPro(pro, indexn, item)"></image>
										<view class="bg--w111-fff py-16 mx-16">
											<view class="fs-24 lh-34rpx w-full line1">{{ pro.title }}</view>
											<view
												class="w-full px-16 mt-8 h-36 bg--w111-f5f5f5 rd-20rpx px-12 flex-between-center fs-20"
												@tap="selecAttr(pro,index,indexn)">
												<view class="w-138 line1">{{pro.suk}}</view>
												<text class="iconfont icon-ic_downarrow text--w111-999 fs-20"></text>
											</view>
											<view class="mt-16 pl-16 SemiBold font-num fs-30">¥{{pro.price}}</view>
										</view>
										<text class="iconfont fs-38 set-icon"
											:class="pro.select ? 'icon-a-ic_CompleteSelect font-color' : 'icon-ic_unselect text--w111-fff'"
											@tap="checkPro(pro, indexn, item)"></text>
										<div class="bgIcon" v-if="pro.select"></div>
									</view>
								</view>
							</scroll-view>
							<view class="mt-32 flex-between-center px-24">
								<view class="flex items-baseline">
									<text class="fs-24 lh-34rpx text--w111-999">共{{item.totalNum}}件,合计:</text>
									<baseMoney :money="item.totalPrice" symbolSize="28" integerSize="44"
										decimalSize="28" weight></baseMoney>
								</view>
								<view class="w-186 h-64 rd-36rpx flex-center fs-24 text--w111-fff bg-gradient1"
									@tap="subData(item)">购买此套餐</view>
							</view>
						</view>
					</view>
				</view>
			</view>
			<base-drawer mode="bottom" :visible="visible" background-color="transparent" mask maskClosable
				@close="closeDrawer">
				<scroll-view scroll-y="true" style="max-height: 800rpx;">
					<view class="bg--w111-fff">
						<view class="w-full pt-32">
							<view class="px-32 flex">
								<image class="w-180 h-180 rd-16rpx" :src="attr.productSelect.image"></image>
								<view class="pl-24">
									<baseMoney :money="attr.productSelect.price" symbolSize="32" integerSize="48"
										decimalSize="32" incolor="var(--primary-theme-con)" weight></baseMoney>
									<view class="mt-20 fs-24 text--w111-999">库存:{{ attr.productSelect.stock }}</view>
								</view>
							</view>
						</view>
						<view class="px-32">
							<view class="item mt-32" v-for="(item, indexw) in attr.productAttr" :key="indexw">
								<view class="fs-28">{{ item.attr_name }}</view>
								<view class="flex-y-center flex-wrap">
									<view class="sku-item" :class="item.index === itemn.attr ? 'active' : ''"
										v-for="(itemn, indexn) in item.attr_value" @click="tapAttr(indexw, indexn)"
										:key="indexn">
										{{ itemn.attr }}
									</view>
								</view>
							</view>
						</view>
						<view class="mx-20 pb-box">
							<view class="mt-52 h-72 flex-center rd-36px bg-color fs-26 text--w111-fff" @click="closeDrawer">
								确定</view>
						</view>
					</view>
				</scroll-view>
			</base-drawer>
		</view>
	</view>
</template>

<script>
	/*
	 * 这是一段很重要的注释,方便您以后理解
	 * 以前的逻辑较为混乱,用一个对象去管理多个套餐多个商品的sku属性
	 * 这次修改以后,没有复用sku弹窗组件,主要是这里的逻辑可以独立处理
	 * 初始化请求到套餐列表用下标为所有商品赋值sku名称和unique码
	 * 在点击商品打开sku弹窗以后,拿到选中的sku以后,将suk和unique赋值到商品中,
	 * 同时更新页面视图,在选择套餐时就省事多了,直接穿参操作即可
	 * */
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import {
		matchPayListApi,
		postCartAdd
	} from "@/api/store.js"
	import colors from '@/mixins/color.js';
	import productWindow from '@/components/productWindow';
	import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
	export default {
		data() {
			return {
				statusBarHeight: sysHeight,
				id: 0,
				params: {
					page: 1,
					limit: 10
				},
				discountsData: [],
				selectValue: [],
				selectAttr: [],
				loading: false,
				attr: {
					cartAttr: false,
					productAttr: [],
					productSelect: {},
				},
				productValue: [], //系统属性
				index: 0,
				indexn: 0,
				visible: false
			}
		},
		components: {
			baseDrawer
		},
		mixins: [colors],
		onLoad(e) {
			this.id = e.id ? e.id : 0;
			this.getList()
		},
		methods: {
			getList() {
				if (this.loading) return;
				this.loading = true;
				matchPayListApi(this.id, this.params).then(res => {
					this.loading = false;
					if (!res.data.length) {
						return uni.navigateBack({
							delta: 1
						});
					}
					res.data.map((item, index) => {
						this.$set(item, 'totalPrice', 0);
						this.$set(item, 'totalNum', 0);
						item.products.map((item1, i) => {
							let skuData = this.DefaultSelect(item1);
							Object.assign(item1, skuData);
							if(!item.type){
								this.$set(item1, 'select', true);
								item.totalPrice += Number(item1.price);
								item.totalNum += 1;
							}else{
								this.$set(item1, 'select', i == 0 ? true : false);
								this.$set(item, 'totalPrice', Number(item.products[0].price));
								item.totalNum = 1;
							}
						})
					})
					this.discountsData = this.discountsData.concat(res.data);
					this.params.page = this.params.page + 1;
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				})
			},
			backPage() {
				uni.navigateBack()
			},
			checkPro(pro, indexn, item) {
				if(!item.type) return this.$util.Tips({
					title: '本套餐为固定套餐,不可更改'
				})
				if(item.type && indexn == 0) return this.$util.Tips({
					title: '套餐主商品不可取消'
				})
				pro.select = !pro.select;
				if (pro.select) {
					item.totalPrice = (Number(item.totalPrice) + Number(pro.price)).toFixed(2);
					item.totalNum = item.totalNum+1;
				} else {
					item.totalPrice = (Number(item.totalPrice) - Number(pro.price)).toFixed(2);
					item.totalNum = item.totalNum-1;
					let i = this.selectValue.findIndex(v => v == pro.id);
				}
			},
			tapAttr: function(indexw, indexn) {
				let that = this;
				this.$set(this.attr.productAttr[indexw], 'index', this.attr.productAttr[indexw].attr_values[indexn]);
				let value = that.getCheckedValue().join(",");
				let skuData = {
					price: this.productValue[value].price,
					unique: this.productValue[value].unique || '',
					suk: this.productValue[value].suk || '默认',
				};
				this.attr.productSelect = {
					price: this.productValue[value].price,
					image: this.productValue[value].image,
					stock: this.productValue[value].stock
				};
				this.$nextTick(() => {
					Object.assign(this.discountsData[this.index].products[this.indexn], skuData);
					let priceNum = 0;
					for (let i = 0; i < this.discountsData[this.index].products.length; i++) {
						priceNum += parseFloat(this.discountsData[this.index].products[i].price)
					}
					this.$set(this.discountsData[this.index],'totalPrice',priceNum);
					
				})

			},
			//获取被选中属性；
			getCheckedValue: function() {
				let productAttr = this.attr.productAttr;
				let value = [];
				for (let i = 0; i < productAttr.length; i++) {
					for (let j = 0; j < productAttr[i].attr_values.length; j++) {
						if (productAttr[i].index === productAttr[i].attr_values[j]) {
							value.push(productAttr[i].attr_values[j]);
						}
					}
				}
				return value;
			},
			DefaultSelect(item) {
				let productAttr = item.productAttr;
				let value = [];
				let arrPrice = []
				for (var key in item.productValue) {
					arrPrice.push(item.productValue[key].price)
				}
				let min = Math.min.apply(null, arrPrice);
				for (var key in item.productValue) {
					if (item.productValue[key].product_stock > 0 && item.productValue[key].price == min) {
						value = item.productAttr.length ? key.split(',') : [];
						break;
					}
				}
				for (let i = 0; i < item.productAttr.length; i++) {
					this.$set(item.productAttr[i], 'index', value[i]);
				}
				let productSelect = item.productValue[value.join(',')];
				let skuData = {
					price: productSelect.price,
					unique: productSelect.unique || '',
					suk: productSelect.suk || '默认',
					stock: productSelect.stock
				};
				return skuData
			},
			closeDrawer() {
				this.visible = false;
			},
			selecAttr(item, index, indexn) {
				this.index = index;
				this.indexn = indexn;
				this.productValue = item.productValue;
				this.attr = {
					productAttr: item.productAttr,
					productSelect: {
						price: item.price,
						image: item.image,
						stock: item.stock
					},
				};
				this.visible = true;
			},
			subData(item) {
				let reqData = {
					new: 1,
					discountId: item.id,
					discountInfos: []
				};
				let count = 0;
				item.products.forEach((pro, i) => {
					if (pro.select) {
						count++;
						reqData.discountInfos.push({
							id: pro.id,
							unique: pro.unique,
							product_id: pro.product_id
						})
					}
				})
				if (count < 2) {
					return this.$util.Tips({
						title: '请先选择套餐商品'
					});
				}
				postCartAdd(reqData).then(res => {
					uni.navigateTo({
						url: '/pages/goods/order_confirm/index?new=1&noCoupon=1&cartId=' + res.data.cartId
							.join(',')
					});
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			},
		},
	}
</script>
<style>
	page{
		background-color: #000;
	}
	.pro_card~.pro_card {
		margin-top: 20rpx;
	}

	.z-900 {
		z-index: 900;
	}

	.con-border {
		border: 1rpx solid var(--view-theme);
	}

	.SemiBold {
		font-family: SemiBold;
	}

	.jianbian {
		background: linear-gradient(90deg, #FF7931 0%, #E93323 100%);
	}

	.bg-black {
		background-color: rgba(0, 0, 0, 0.4);
	}

	.set-icon {
		position: absolute;
		right: 8rpx;
		top: 8rpx;
		z-index: 9;
	}
	.bgIcon{
		position: absolute;
		right: 12rpx;
		top: 12rpx;
		width: 30rpx;
		height: 30rpx;
		background-color: #fff;
		border-radius: 50%;
	}

	.match-box {
		width: 424rpx;
		background-color: rgba(0, 0, 0, 0.3);
		position: absolute;
		left: 24rpx;
		bottom: 24rpx;
	}

	.sku-item {
		height: 56rpx;
		line-height: 56rpx;
		border: 1px solid #F2F2F2;
		font-size: 24rpx;
		color: #333;
		padding: 0 44rpx;
		border-radius: 28rpx;
		margin: 24rpx 0 0 16rpx;
		background-color: #F2F2F2;
		word-break: break-all;
	}

	.active {
		color: var(--view-theme);
		background: var(--view-minorColorT);
		border-color: var(--view-theme);
	}
	
	.pb-box{
		padding-bottom: calc(20rpx + env(safe-area-inset-bottom));
	}
	
	.tui-drawer-container {
		border-radius: 40rpx 40rpx 0 0;
	}
</style>