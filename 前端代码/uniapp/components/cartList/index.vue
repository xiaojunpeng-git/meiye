<template>
	<view class="">
		<!-- 分类购物车下拉列表 -->
		<view class="mask" v-if="cartData.iScart" @click="closeList" @touchmove.stop.prevent="moveHandle"></view>
		<view :style="[parent.isFooter?listH:'']" class="cartList" :class="{on:cartData.iScart}" @touchmove.stop.prevent="moveHandle">
			<view class="flex h-108 px-6 fs-32" v-if="!isStoreCart && shopOperationType != 3">
				<view :class="['relative flex-center w-116', deliveryFrom == 1 ? 'fw-500 text--w111-333 deliveryOn' : 'text--w111-999']" @tap="deliveryFromChange(1)">商城</view>
				<view :class="['relative flex-center w-116', deliveryFrom == 2 ? 'fw-500 text--w111-333 deliveryOn' : 'text--w111-999']" @tap="deliveryFromChange(2)">门店</view>
			</view>
			<view class="title acea-row row-between-wrapper" :class="[isStoreCart || shopOperationType == 3 ?'pt-40 pr-32 pl-32':'px-32']">
				<view class="name fs-28">购物车 <text class="fs-24 text--w111-999 pl-8">(共{{cartNumTotal}}件商品)</text></view>
				<view v-if="cartNumTotal" class="del acea-row row-middle" @tap.stop="subDel">
					<view class="iconfont icon-ic_delete"></view>清空
				</view>
			</view>
			<view class="list">
				<!-- <scroll-view scroll-y="true" style="max-height: 800rpx;margin-bottom: 146rpx;"> -->
				<scroll-view v-if="deliveryFrom == 1" class="scroll-list pt-32 pb-20" scroll-y="true" :style="{
					'max-height': '800rpx',
					'margin-bottom': '130rpx'
				}">
					<view class="item flex px-32" v-for="(item,index) in cartData.cartList" :key="index">
						<view class="pictrue">
							<image v-if="item.productInfo.attrInfo" :src='item.productInfo.attrInfo.image'></image>
							<image v-else :src='item.productInfo.image'></image>
							<view class="mantle" v-if="!item.status || !item.attrStatus"></view>
						</view>
						<view class="flex-1 flex-col justify-between ml-20">
							<view class="w-full">
								<view class="lh-40rpx fs-28 text--w111-333 line2" :class="(item.attrStatus && item.status)?'':'on'">{{item.productInfo.store_name}}</view>
								<view class="inline-block max-w-460 h-38 lh-38rpx mt-12  bg--w111-f5f5f5  text--w111-999 rd-20rpx px-12 text-center fs-22" v-if="item.productInfo.spec_type && item.attrStatus">
									<view class="flex">
										<text class="line1">属性: {{item.productInfo.attrInfo.suk}}</text>
										<text class="iconfont icon-ic_downarrow fs-24 ml-12"></text>
									</view>
								</view>
								<view class="inline-block max-w-460 h-38 lh-38rpx mt-12  bg--w111-f5f5f5  text--w111-999 rd-20rpx px-12 text-center fs-22" v-else>
									<view class="flex">
										<text class="line1">属性: {{item.productInfo.attrInfo.suk}}</text>
										<text class="iconfont icon-ic_downarrow fs-24 ml-12"></text>
									</view>
								</view>
							</view>
							<view class="flex-between-center mt-20">
								<baseMoney :money="item.truePrice" symbolSize="24" integerSize="40" decimalSize="24" weight></baseMoney>
								<view class="flex-y-center" v-if="item.attrStatus && item.status">
									<view class="flex-center w-48 h-48 rd-30rpx bg--w111-f5f5f5 text--w111-333" @click="leaveCart(index)">
										<text class="iconfont icon-ic_Reduce fs-32"></text>
									</view>
									<view class="fs-30 text--w111-333 px-20">{{item.cart_num}}</view>
									<view class="flex-center w-48 h-48 rd-30rpx bg-color text--w111-fff" @click="joinCart(index)">
										<text class="iconfont icon-ic_increase fs-32"></text>
									</view>
								</view>
								<view class="noBnt" v-else-if="!item.attrStatus">已售罄</view>
								<view class="noBnt" v-else-if="!item.status">已下架</view>
							</view>
						</view>
					</view>
					<view class="pt-20" v-if="!cartData.cartList.length">
						<emptyPage title="暂无商品，去加点别的吧～"></emptyPage>
					</view>
				</scroll-view>
				<scroll-view v-if="deliveryFrom == 2" class="scroll-list py-32" scroll-y="true" :style="{
					'max-height': '800rpx',
					'margin-bottom': '130rpx'
				}">
					<radio-group v-if="cartData.cartStoreList.length" @change="storeCheckedChange">
						<view class="mb-40" v-for="(storeItem,storeIndex) in cartData.cartStoreList" :key="storeItem.store_id">
							<view class="flex-y-center mb-24 px-32">
								<view class="fs-0 radio-input"><radio :value="storeItem.store_id.toString()" :checked="storeChecked == storeItem.store_id" /></view>
								<view class="flex-1 min-w-0 pl-12">
									<navigator url="" hover-class="none" class="flex-y-center inline-flex max-w-full">
										<text class="fs-28 line1">{{storeItem.name}}</text>
										<text class="iconfont icon-ic_rightarrow fs-28"></text>
									</navigator>
								</view>
							</view>
							<view v-for="(validItem,validIndex) in storeItem.valid" :key="validIndex">
								<view class="item flex px-32" v-for="(item,index) in validItem.cart" :key="item.id">
									<view class="pictrue">
										<image v-if="item.productInfo.attrInfo" :src='item.productInfo.attrInfo.image'></image>
										<image v-else :src='item.productInfo.image'></image>
										<view class="mantle" v-if="!item.status || !item.attrStatus"></view>
									</view>
									<view class="flex-1 flex-col justify-between ml-20">
										<view class="w-full">
											<view class="lh-40rpx fs-28 text--w111-333 line2" :class="(item.attrStatus && item.status)?'':'on'">{{item.productInfo.store_name}}</view>
											<view class="inline-block max-w-460 h-38 lh-38rpx mt-12  bg--w111-f5f5f5  text--w111-999 rd-20rpx px-12 text-center fs-22" v-if="item.productInfo.spec_type && item.attrStatus">
												<view class="flex">
													<text class="line1">属性: {{item.productInfo.attrInfo.suk}}</text>
													<text class="iconfont icon-ic_downarrow fs-24 ml-12"></text>
												</view>
											</view>
											<view class="inline-block max-w-460 h-38 lh-38rpx mt-12  bg--w111-f5f5f5  text--w111-999 rd-20rpx px-12 text-center fs-22" v-else>
												<view class="flex">
													<text class="line1">属性: {{item.productInfo.attrInfo.suk}}</text>
													<text class="iconfont icon-ic_downarrow fs-24 ml-12"></text>
												</view>
											</view>
										</view>
										<view class="flex-between-center mt-20">
											<baseMoney :money="item.truePrice" symbolSize="24" integerSize="40" decimalSize="24" weight></baseMoney>
											<view class="flex-y-center" v-if="item.attrStatus && item.status">
												<view class="flex-center w-48 h-48 rd-30rpx bg--w111-f5f5f5 text--w111-333" @click="leaveCart(storeItem.store_id,item.id)">
													<text class="iconfont icon-ic_Reduce fs-32"></text>
												</view>
												<view class="fs-30 text--w111-333 px-20">{{item.cart_num}}</view>
												<view class="flex-center w-48 h-48 rd-30rpx bg-color text--w111-fff" @click="joinCart(storeItem.store_id,item.id)">
													<text class="iconfont icon-ic_increase fs-32"></text>
												</view>
											</view>
											<view class="noBnt" v-else-if="!item.attrStatus">已售罄</view>
											<view class="noBnt" v-else-if="!item.status">已下架</view>
										</view>
									</view>
								</view>
							</view>
						</view>
					</radio-group>
					<view class="pt-20" v-else>
						<emptyPage title="暂无商品，去加点别的吧～"></emptyPage>
					</view>
				</scroll-view>
			</view>
		</view>
	</view>
</template>

<script>
	import {
		mapState
	} from 'vuex';
	import emptyPage from '@/components/emptyPage.vue';
	export default {
		components: {
			emptyPage,
		},
		props: {
			cartData: {
				type: Object,
				default: () => ({
					cartList: [],
					iScart: false,
				})
			},
			isFooter: {
				type: Boolean,
				default: false
			},
			cartNums: {
				type: Number,
				default: 0
			},
			storeChecked: {
				type: Number,
				default: 0
			},
			isStoreCart: {
				type: Boolean,
				default: false
			},
		},
		inject: ['parent'],
		computed: {
			listH() {
				let H = `calc(${this.parent.pdHeight*2+100}rpx + env(safe-area-inset-bottom))`
				return {
					paddingBottom: H
				}
			},
			...mapState({
				cartNum: state => state.indexData.cartNum
			}),
			cartNumTotal() {
				if (this.isStoreCart || this.deliveryFrom == 1) {
					return this.cartData.cartList.reduce((total, item) => {
						return total + item.cart_num;
					}, 0);
				} else{
					return this.cartData.cartStoreList.reduce((storeTotal, storeItem) => {
						return storeTotal + storeItem.valid.reduce((validTotal, validItem) => {
							return validTotal + validItem.cart.reduce((cartTotal, cartItem) => {
								return cartTotal + cartItem.cart_num;
							}, 0);
						}, 0);
					}, 0);
				}
			},
		},
		data() {
			return {
				deliveryFrom: 1,
				shopOperationType: 0,
			};
		},
		watch: {
			'cartData.iScart'(newValue, oldValue) {
				let shopOperationType = uni.getStorageSync('shop_operation_type');
				this.shopOperationType = shopOperationType;
				if (shopOperationType == 3) {
					if (this.isStoreCart) {
						this.deliveryFrom = 1;
					} else{
						this.deliveryFrom = 2;
					}
				} else{
					this.deliveryFrom = 1;
				}
			},
		},
		mounted() {},
		methods: {
			moveHandle() {
				return false
			},
			closeList() {
				this.$emit('closeList', false);
			},
			leaveCart(index, cartId) {
				if (cartId) {
					this.$emit('storeCheckedChange', index);
				}
				this.$emit('ChangeCartNumDan', false, index, cartId);
			},
			joinCart(index, cartId) {
				if (cartId) {
					this.$emit('storeCheckedChange', index);
				}
				this.$emit('ChangeCartNumDan', true, index, cartId);
			},
			subDel() {
				this.$emit('ChangeSubDel');
			},
			oneDel(id, index) {
				this.$emit('ChangeOneDel', id, index);
			},
			deliveryFromChange(value) {
				this.deliveryFrom = value;
				this.$emit('deliveryFromChange', value);
			},
			storeCheckedChange(event) {
				this.$emit('storeCheckedChange', Number(event.detail.value));
			},
		}
	}
</script>

<style lang="scss">
	.h-122 {
		height: 122rpx;
	}

	.mask {
		z-index: 99;
	}

	.cartList {
		position: fixed;
		left:0;
		bottom: 0;
		width: 100%;
		background-color: #fff;
		z-index:100;
		// padding: 40rpx 32rpx 0;
		padding-bottom: calc(100rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		padding-bottom: calc(100rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/

		box-sizing: border-box;
		border-radius:40rpx 40rpx 0 0;
		transform: translate3d(0, 100%, 0);
		transition: all .3s cubic-bezier(.25, .5, .5, .9);
		&.on {
			transform: translate3d(0, 0, 0);
		}

		&.ons {
			// #ifndef H5
			padding-bottom: 0;
			padding-bottom: calc(0 + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
			padding-bottom: calc(0 + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
			// #endif
		}

		.title {
			margin-bottom: 32rpx;

			.name {
				// font-size:32rpx;
				// color: #333;
				// font-weight:500;
			}

			.del {
				font-size: 24rpx;
				color: #666;

				.iconfont {
					margin-right: 8rpx;
					font-size: 28rpx;
				}
			}
		}

		.list {
			max-height: 1000rpx;

			.item {
				margin-bottom: 32rpx;

				.pictrue {
					width: 200rpx;
					height: 200rpx;
					border-radius: 16rpx;
					position: relative;

					image {
						width: 100%;
						height: 100%;
						border-radius: 16rpx;
					}

					.mantle {
						position: absolute;
						top: 0;
						left: 0;
						width: 100%;
						height: 100%;
						background: rgba(255, 255, 255, 0.65);
						border-radius: 16rpx;
					}
				}
			}
		}
	}

	.noBnt {
		width: 126rpx;
		height: 44rpx;
		background: #f5f5f5;
		border-radius: 22rpx;
		text-align: center;
		line-height: 44rpx;
		font-size: 24rpx;
		color: #333;
	}

	.max-w-460 {
		max-width: 460rpx;
	}
	.radio-input ::v-deep  uni-radio .uni-radio-input {
		width: 40rpx;
		height: 40rpx;
		margin-right: 0;
	}
	.deliveryOn::after {
		content: "";
		position: absolute;
		left: 50%;
		bottom: 24rpx;
		width: 60rpx;
		height: 5rpx;
		border-radius: 3rpx;
		background-color: var(--view-theme);
		transform: translateX(-50%);
	}
</style>