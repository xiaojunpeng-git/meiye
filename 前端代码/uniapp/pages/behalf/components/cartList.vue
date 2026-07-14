<template>
	<base-drawer mode="bottom" :visible="cartData.iScart" :zIndex="zIndex" :maskZIndex="zIndex" background-color="transparent" @close="closeList">
		<!-- <view class="bg--w111-fff rd-t-40rpx p-32"> -->
		<view class="bg--w111-fff rd-t-40rpx">
			<!-- <view class="text-center fs-32 text--w111-333 fw-500">待购清单</view> -->
			<view class="">
				<view class="flex h-108 px-6 fs-32 text--w111-999">
					<view :class="['relative flex-center w-116',{'fw-500 text--w111-333 deliveryOn':deliveryFrom==1}]" @tap="deliveryFromChange(1)">商城</view>
					<view :class="['relative flex-center w-116',{'fw-500 text--w111-333 deliveryOn':deliveryFrom==2}]" @tap="deliveryFromChange(2)">门店</view>
				</view>
				<view class="abs-rt flex-center w-100 h-108">
					<view class="flex-center w-36 h-36 rd-50-p111- bg--w111-eee" @tap="closeList">
						<text class="iconfont icon-ic_close fs-24"></text>
					</view>
				</view>
			</view>
			<scroll-view scroll-y="true" :style="{'max-height': '1044rpx'}" v-if="deliveryFrom == 1">
				<view class="flex-y-center h-176 px-32 mb-8" v-for="(item,index) in cartData.cartList" :key="index">
					<view class="w-56">
						<text class="iconfont fs-36" :class="item.select ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect'" @tap="selectItem(item,index)"></text>
					</view>
					<view class="flex-1 flex">
						<image v-if="item.productInfo && item.productInfo.attrInfo" :src="item.productInfo.attrInfo.image" class="w-136 h-136 rd-16rpx block"></image>
						<image v-else-if="item.productInfo && item.productInfo.image" :src="item.productInfo.image" class="w-136 h-136 rd-16rpx block"></image>
						<view class='flex-1 h-136 pl-20'>
							<view class="w-340 line1 fs-28 lh-40rpx fw-500" v-if="item.productInfo">{{item.productInfo.store_name}}</view>
							<view class="w-324 line1 fs-22 lh-30rpx text--w111-999 mt-12" v-if="item.productInfo">{{item.productInfo.attrInfo.suk}}</view>
							<view class="flex-1 flex-between-center mt-18">
								<view class="fs-36 Regular">¥{{item.truePrice}}</view>
								<view class="flex-y-center">
									<text class="iconfont icon-ic_Reduce fs-24" :class="item.cart_num <= 1 ? 'text--w111-f5f5f5' : ''" @tap="cartNumAdd(false,item,index)"></text>
									<input type="number" v-model="item.cart_num" always-embed adjust-position cursor-spacing="30" class="w-88 h-44 rd-4rpx bg--w111-f5f5f5 fs-24 text-center mx-10"></input>
									<text class="iconfont icon-ic_increase fs-24" @tap="cartNumAdd(true,item,index)"></text>
								</view>
							</view>
						</view>
					</view>
				</view>
				<view class="pt-20" v-if="!cartData.cartList.length">
					<emptyPage title="暂无商品，去加点别的吧～"></emptyPage>
				</view>
			</scroll-view>
			<scroll-view scroll-y="true" :style="{'max-height': '1044rpx'}" v-if="deliveryFrom == 2">
				<view class="" v-for="(storeItem,storeIndex) in cartData.cartStoreList" :key="storeIndex">
					<view class="flex-y-center px-32 mb-12">
						<view class="w-56">
							<text class="iconfont fs-36" :class="storeItem.select ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect'" @tap="selectStore(storeItem,storeIndex)"></text>
						</view>
						<view class="flex-1 fs-28">
							{{storeItem.name}}
						</view>
					</view>
					<view class="flex-y-center h-176 px-32 mb-8" v-for="(item,index) in storeItem.valid" :key="index">
						<view class="w-56">
							<text class="iconfont fs-36" :class="item.select ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect'" @tap="selectProduct(item,index,storeIndex)"></text>
						</view>
						<view class="flex-1 flex">
							<image v-if="item.productInfo.attrInfo" :src="item.productInfo.attrInfo.image" class="w-136 h-136 rd-16rpx block"></image>
							<image v-else :src="item.productInfo.image" class="w-136 h-136 rd-16rpx block"></image>
							<view class='flex-1 h-136 pl-20'>
								<view class="w-340 line1 fs-28 lh-40rpx fw-500">{{item.productInfo.store_name}}</view>
								<view class="w-324 line1 fs-22 lh-30rpx text--w111-999 mt-12">{{item.productInfo.attrInfo.suk}}</view>
								<view class="flex-1 flex-between-center mt-18">
									<view class="fs-36 Regular">¥{{item.truePrice}}</view>
									<view class="flex-y-center">
										<text class="iconfont icon-ic_Reduce fs-24" :class="item.cart_num <= 1 ? 'text--w111-f5f5f5' : ''" @tap="cartNumAdd(false,item,index,storeIndex)"></text>
										<input type="number" v-model="item.cart_num" always-embed adjust-position cursor-spacing="30" class="w-88 h-44 rd-4rpx bg--w111-f5f5f5 fs-24 text-center mx-10"></input>
										<text class="iconfont icon-ic_increase fs-24" @tap="cartNumAdd(true,item,index,storeIndex)"></text>
									</view>
								</view>
							</view>
						</view>
					</view>
				</view>
				<view class="pt-20" v-if="!cartData.cartStoreList.length">
					<emptyPage title="暂无商品，去加点别的吧～"></emptyPage>
				</view>
			</scroll-view>
			<view class="pb-safe">
				<view class="h-96"></view>
			</view>
			<view class="w-full fixed-lb pb-safe bg--w111-fff">
				<view class="h-96 pl-32 pr-20 flex-between-center">
					<view class="">
						<view class="flex-y-center" v-if="deliveryFrom == 1 && cartData.cartList.length" @tap="selectAll">
							<text class="iconfont fs-36" :class="allSelect ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect'"></text>
							<text class="fs-26 pl-12">全选({{selectCartNum}})</text>
						</view>
					</view>
					<view class="flex-y-center" v-if="cartNumTotal">
						<view class="w-160 h-64 rd-40rpx flex-center fs-24 con_border text-primary" @tap="cartDel">删除</view>
						<view class="w-160 h-64 rd-40rpx flex-center fs-24 bg-primary text--w111-fff ml-16" @tap="cartConfirm">下单</view>
					</view>
				</view>
			</view>
		</view>
	</base-drawer>
</template>
<script>
	import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
	import emptyPage from '@/components/emptyPage.vue';
	export default {
		name: 'cartList',
		components: {
			baseDrawer,
			emptyPage,
		},
		props: {
			cartData: {
				type: Object,
				default: () => ({})
			},
			zIndex:{
				type: Number,
				default: 980
			},
		},
		data() {
			return {
				allSelect: false,
				deliveryFrom: 1,
			}
		},
		computed: {
			cartNumTotal() {
				if (this.deliveryFrom == 1) {
					return this.cartData.cartList.reduce((total, item) => {
						return total + item.cart_num;
					}, 0);
				} else{
					return this.cartData.cartStoreList.reduce((storeTotal, storeItem) => {
						return storeTotal + storeItem.valid.reduce((validTotal, validItem) => {
							return validTotal + validItem.cart_num;
						}, 0);
					}, 0);
				}
			},
			selectCartNum() {
				return this.cartData.cartList.reduce((total, item) => {
					return total + (item.select ? item.cart_num : 0);
				}, 0);
			},
		},
		watch: {
			'cartData.iScart'(newValue, oldValue) {
				this.deliveryFrom = 1;
			},
			selectCartNum() {
				this.allSelect = this.selectCartNum == this.cartNumTotal;
			},
		},
		methods: {
			closeList() {
				this.deliveryFrom = 1;
				this.$emit('closeList', false);
			},
			selectItem(item, index) {
				this.$emit('onSelect', index);
			},
			selectAll() {
				this.$emit('onSelectAll', this.allSelect);
				this.allSelect = !this.allSelect;
			},
			cartDel() {
				this.$emit('onDelCart', this.deliveryFrom);
			},
			cartConfirm() {
				this.$emit('onCartConfirm');
			},
			cartNumAdd(type, item, index, sIndex) {
				this.$emit('onCartNum', {
					type,
					item,
					index,
					sIndex,
				});
			},
			deliveryFromChange(value) {
				this.deliveryFrom = value;
				this.$emit('deliveryFromChange', this.deliveryFrom);
			},
			selectStore(item, index) {
				this.$emit('selectStore', index);
			},
			selectProduct(item, index, sIndex) {
				this.$emit('selectProduct', index, sIndex);
			},
		}
	}
</script>
<style lang="scss" scoped>
	.icon-ic_unselect {
		color: #ccc;
	}

	.icon-a-ic_CompleteSelect {
		color: $primary-admin;
	}

	.text-primary {
		color: $primary-admin;
	}

	.bg-primary {
		background: $primary-admin;
	}

	.con_border {
		border: 1px solid $primary-admin;
	}
	.deliveryOn::after {
		content: "";
		position: absolute;
		left: 50%;
		bottom: 18rpx;
		width: 60rpx;
		height: 5rpx;
		border-radius: 3rpx;
		background-color: $primary-admin;
		transform: translateX(-50%);
	}
</style>