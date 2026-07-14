<template>
	<!-- 地址管理 -->
	<view :style="colorStyle">
		<view class='address-management' :class='addressList.length < 1 && page > 1 ? "fff":""'>
			<view class="swipe-action-wrapper">
				<tui-swipe-action v-for="(item, index) in addressList" :key="item.id" :operateWidth="128" class="item">
					<template v-slot:content>
						<view class="content acea-row row-middle">
							<view class="address" @click='goOrder(item.id)'>
								<view class="consignee acea-row row-middle">{{item.real_name}}<text class='phone'>{{item.phone}}</text><text v-if="item.is_default" class="badge">默认</text></view>
								<view>{{item.province}}{{item.city}}{{item.district}}{{item.street}}{{item.detail}}</view>
							</view>
							<view class="ml-10" @click="editAddress(item.id)"><text class="iconfont icon-ic_edit"></text></view>
						</view>
					</template>
					<template v-slot:button>
						<view class="button acea-row row-center-wrapper" @click="radioChange(index)">设为默认</view>
						<view class="button delete acea-row row-center-wrapper" @click="delAddress(index)">删除</view>
					</template>
				</tui-swipe-action>
			</view>
			<view class='loadingicon acea-row row-center-wrapper' v-if="addressList.length">
				<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
			</view>
			<view v-if="!addressList.length">
				<emptyPage title="暂无地址信息～" src="/statics/images/noAddress.gif"></emptyPage>
			</view>
			<view class="height-add"></view>
			<view class='footer acea-row row-between-wrapper'>
				<view class='addressBnt on' @click='addAddress'>新增收货地址</view>
				<view class=""></view>
			</view>
		</view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	import {
		getAddressList,
		setAddressDefault,
		delAddress,
		editAddress,
		postAddress
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import home from '@/components/home';
	import colors from '@/mixins/color.js';
	import {HTTP_REQUEST_URL} from '@/config/app';
	import tuiSwipeAction from '@/components/tui-swipe-action/index.vue';
	import emptyPage from '@/components/emptyPage.vue';
	export default {
		components: {
			tuiSwipeAction,
			emptyPage,
			home
		},
		mixins: [colors],
		data() {
			return {
				addressList: [],
				cartId: '',
				pinkId: 0,
				couponId: 0,
				loading: false,
				loadend: false,
				loadTitle: '加载更多',
				page: 1,
				limit: 20,
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				news: '',
				noCoupon: 0,
				imgHost: HTTP_REQUEST_URL,
				deliveryType: 1, //配送方式
				store_name: '', //门店名称
				storeId: 0, //门店id
				product_id: 0, //商品id
				isStore:0,
				productType:0,
                homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad(options) {
			this.cartId = options.cartId || '';
			this.noCoupon = options.noCoupon || 0;
			this.pinkId = options.pinkId || 0;
			this.couponId = options.couponId || 0;
			this.news = options.news || 0;
			this.deliveryType = options.delivery_type || 1;
			this.store_name = options.store_name;
			this.storeId = options.store_id;
			this.product_id = options.product_id;
			this.isStore = options.is_store;
			this.productType = options.productType;
			if (this.isLogin) {
				this.getAddressList(true);
			} else {
				toLogin()
			}
		},
		onShow: function() {
			uni.removeStorageSync('form_type_cart');
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			onLoadFun() {
				this.getAddressList(true);
				this.isShowAuth = false
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			/*
			 * 导入微信地址（小程序）
			 */
			getWxAddress: function() {
				let that = this;
				uni.authorize({
					scope: 'scope.address',
					success: function(res) {
						uni.chooseAddress({
							success: function(res) {
								let addressP = {};
								addressP.province = res.provinceName;
								addressP.city = res.cityName;
								addressP.district = res.countyName;
								editAddress({
									address: addressP,
									is_default: 1,
									real_name: res.userName,
									post_code: res.postalCode,
									phone: res.telNumber,
									detail: res.detailInfo,
									id: 0,
									type: 1
								}).then(res => {
									that.$util.Tips({
										title: "添加成功",
										icon: 'success'
									}, function() {
										that.getAddressList(true);
									});
								}).catch(err => {
									return that.$util.Tips({
										title: err
									});
								});
							},
							fail: function(res) {
								if (res.errMsg == 'chooseAddress:cancel') return that.$util
									.Tips({
										title: '取消选择'
									});
							},
						})
					},
					fail: function(res) {
						uni.showModal({
							title: '您已拒绝导入微信地址权限',
							content: '是否进入权限管理，调整授权？',
							success(res) {
								if (res.confirm) {
									uni.openSetting({
										success: function(res) {}
									});
								} else if (res.cancel) {
									return that.$util.Tips({
										title: '已取消！'
									});
								}
							}
						})
					}
				})
			},
			/*
			 * 导入微信地址（公众号）
			 */
			getAddress() {
				let that = this;
				that.$wechat.openAddress().then(userInfo => {
					editAddress({
							real_name: userInfo.userName,
							phone: userInfo.telNumber,
							address: {
								province: userInfo.provinceName,
								city: userInfo.cityName,
								district: userInfo.countryName
							},
							detail: userInfo.detailInfo,
							post_code: userInfo.postalCode,
							is_default: 1,
							type: 1
						})
						.then(() => {
							that.$util.Tips({
								title: "添加成功",
								icon: 'success'
							}, function() {
								that.getAddressList(true);
							});
						})
						.catch(err => {
							return that.$util.Tips({
								title: err || "添加失败"
							});
						});
				});
			},
			/**
			 * 获取地址列表
			 * 
			 */
			getAddressList: function(isPage) {
				let that = this;
				if (isPage) {
					that.loadend = false;
					that.page = 1;
					that.$set(that, 'addressList', []);
				};
				if (that.loading) return;
				if (that.loadend) return;
				that.loading = true;
				that.loadTitle = '';
				getAddressList({
					page: that.page,
					limit: that.limit
				}).then(res => {
					let list = res.data;
					let loadend = list.length < that.limit;
					that.addressList = that.$util.SplitArray(list, that.addressList);
					that.$set(that, 'addressList', that.addressList);
					that.loadend = loadend;
					that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					that.page = that.page + 1;
					that.loading = false;
				}).catch(err => {
					that.loading = false;
					that.loadTitle = '加载更多';
				});
			},
			/**
			 * 设置默认地址
			 */
			radioChange: function(index) {
				let that = this;
				let address = this.addressList[index];
				if (address == undefined) return that.$util.Tips({
					title: '您设置的默认地址不存在!'
				});
				setAddressDefault(address.id).then(res => {
					for (let i = 0, len = that.addressList.length; i < len; i++) {
						if (i == index) that.addressList[i].is_default = true;
						else that.addressList[i].is_default = false;
					}
					that.$util.Tips({
						title: '设置成功',
						icon: 'success'
					}, function() {
						that.$set(that, 'addressList', that.addressList);
					});
				}).catch(err => {
					return that.$util.Tips({
						title: err
					});
				});
			},
			/**
			 * 编辑地址
			 */
			editAddress: function(id) {
				let cartId = this.cartId,
					pinkId = this.pinkId,
					couponId = this.couponId;
				this.cartId = '';
				this.pinkId = '';
				this.couponId = '';
				uni.navigateTo({
					url: '/pages/users/user_address/index?id=' + id + '&cartId=' + cartId + '&pinkId=' +
						pinkId + '&couponId=' +
						couponId + '&new=' + this.news + '&delivery_type=' + this.deliveryType + '&store_id=' + this.storeId + '&store_name=' + this.store_name + '&product_id=' + this
						.product_id + '&is_store='+this.isStore+'&productType='+this.productType
				})
			},
			/**
			 * 删除地址
			 */
			delAddress: function(index) {
				let that = this,
					address = this.addressList[index];
				if (address == undefined) return that.$util.Tips({
					title: '您删除的地址不存在!'
				});
				delAddress(address.id).then(res => {
					that.$util.Tips({
						title: '删除成功',
						icon: 'success'
					}, function() {
						that.addressList.splice(index, 1);
						that.$set(that, 'addressList', that.addressList);
					});
				}).catch(err => {
					return that.$util.Tips({
						title: err
					});
				});
			},
			/**
			 * 新增地址
			 */
			addAddress: function() {
				let cartId = this.cartId,
					pinkId = this.pinkId,
					couponId = this.couponId;
				this.cartId = '';
				this.pinkId = '';
				this.couponId = '';
				uni.navigateTo({
					url: '/pages/users/user_address/index?cartId=' + cartId + '&pinkId=' + pinkId +
						'&couponId=' + couponId + '&new=' + this.news + '&delivery_type=' + this.deliveryType + '&store_id=' + this.storeId + '&store_name=' + this.store_name +
						'&product_id=' + this.product_id + '&is_store='+this.isStore+'&productType='+this.productType
				})
			},
			goOrder: function(id) {
				let cartId = '';
				let pinkId = '';
				let couponId = '';
				if (this.cartId && id) {
					cartId = this.cartId;
					pinkId = this.pinkId;
					couponId = this.couponId;
					this.cartId = '';
					this.pinkId = '';
					this.couponId = '';
					uni.redirectTo({
						url: '/pages/goods/order_confirm/index?is_address=1&new=' + this.news + '&cartId=' +
							cartId + '&addressId=' + id + '&pinkId=' +
							pinkId + '&couponId=' + couponId +
							'&noCoupon=' + this.noCoupon + '&delivery_type=' + this.deliveryType + '&store_id=' + this.storeId + '&store_name=' + this.store_name + '&product_id=' + this
							.product_id + '&is_store='+this.isStore+'&productType='+this.productType
					})
				}
			}
		},
		onReachBottom: function() {
			this.getAddressList();
		}
	}
</script>

<style lang="scss" scoped>
	.height-add {
		height: 120rpx;
	}

	.noCommodity {
		height: calc(100vh - 123rpx);
		background-color: #fff;
	}

	.address-management {
		padding: 24rpx 20rpx;
		padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
		padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/
	}

	.address-management.fff {
		background-color: #fff;
		height: 1300rpx
	}

	.address-management .wechat {
		height: 100rpx;
		padding: 0 32rpx;
		border-radius: 24rpx;
		background-color: #FFFFFF;
		font-weight: 500;
		font-size: 30rpx;
		color: #3D3D3D;
	}

	.address-management .line {
		width: 100%;
		height: 3rpx;
	}

	.address-management .line image {
		width: 100%;
		height: 100%;
		display: block;
	}

	.address-management .item::before {
		content: "";
		position: absolute;
		top: 0;
		right: 24rpx;
		left: 24rpx;
		height: 1rpx;
		background-color: #F5F5F5;
	}

	.address-management .item:first-child::before {
		display: none;
	}

	.address-management .item .content {
		padding: 32rpx 32rpx 24rpx;
	}

	.address-management .item .address {
		flex: 1;
		min-width: 0;
		white-space: normal;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #999999;
	}

	.address-management .item .address .consignee {
		font-size: 30rpx;
		line-height: 42rpx;
		font-weight: bold;
		margin-bottom: 12rpx;
		color: #333333;
	}

	.address-management .item .address .consignee .phone {
		font-weight: normal;
		margin-left: 22rpx;
	}

	.address-management .item .address .consignee .badge {
		height: 34rpx;
		padding: 0 8rpx;
		border-radius: 8rpx;
		margin-left: 20rpx;
		background-color: var(--view-minorColorT);
		font-weight: normal;
		font-size: 22rpx;
		line-height: 34rpx;
		color: var(--view-theme);
	}

	.address-management .item .operation {
		height: 83rpx;
		font-size: 28rpx;
		color: #282828;
	}

	.address-management .item .operation .radio text {
		margin-left: 13rpx;
	}

	.address-management .item .iconfont {
		font-size: 32rpx;
		color: #333333;
	}

	.address-management .item .button {
		position: absolute;
		top: 0;
		left: 0;
		bottom: 0;
		display: inline-flex;
		width: 64px;
		background: var(--view-bntColor);
		font-size: 24rpx;
		color: #fff;
	}

	.address-management .item .delete {
		left: 64px;
		background-color: var(--view-theme);
		color: #FFFFFF;
	}

	.address-management .footer {
		position: fixed;
		width: 100%;
		bottom: 0;
		left: 0;
		height: 120rpx;
		padding: 0 20rpx;
		box-sizing: border-box;
		height: calc(106rpx+ constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(106rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
		padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/
	}

	.address-management .footer .addressBnt {
		width: 330rpx;
		height: 80rpx;
		border-radius: 40rpx;
		text-align: center;
		line-height: 80rpx;
		font-weight: 500;
		font-size: 28rpx;
		color: #fff;
		background-color: var(--view-theme);
	}

	.address-management .footer .addressBnt.on {
		width: 710rpx;
		margin: 0 auto;
	}

	.address-management .footer .addressBnt .iconfont {
		font-size: 35rpx;
		margin-right: 8rpx;
		vertical-align: -1rpx;
	}

	.address-management .footer .addressBnt.wxbnt {
		background-color: #FE960F;
	}

	.wechat+.swipe-action-wrapper {
		margin-top: 20rpx;
	}

	.swipe-action-wrapper {
		border-radius: 24rpx;
		background-color: #FFFFFF;
		overflow: hidden;
	}
</style>