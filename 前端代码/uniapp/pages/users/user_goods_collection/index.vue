<template>
	<view class="collection" :style="colorStyle">
		<view class="nav acea-row row-center">
			<view class="item acea-row row-middle" :class="active == index?'on':''" v-for="(item,index) in navList" :key="index" @click="navTap(index)">{{item.name}}</view>
		</view>
		<view class="manage acea-row row-between-wrapper" v-if="collectProductList.length">
			<view>共 <text class="num">{{count}}</text> {{!active ? '件商品':'条视频'}}</view>
			<view class="close" @click="manageTap" v-if="administer">完成</view>
			<view @click="manageTap" v-else>管理</view>
		</view>
		<view class="collectList" v-if="collectProductList.length && !active">
			<view class="collectList-content">
				<checkbox-group @change="checkboxChange">
					<view class="item acea-row" v-for="(item,index) in collectProductList" :key="index" @click="goGoods(item)">
						<!-- #ifndef MP -->
						<checkbox class="checkbox" v-if="administer" :value="(item.id).toString()" :checked="item.checked" />
						<!-- #endif -->
						<!-- #ifdef MP -->
						<checkbox class="checkbox" v-if="administer" :value="item.id" :checked="item.checked" />
						<!-- #endif -->
						<easy-loadimage :image-src="item.image" :borderSrc="item.activity_frame.image" width="200rpx" height="200rpx" borderRadius="16rpx"></easy-loadimage>
						<view class="text acea-row row-column" :class="!item.is_show?'on':''">
							<view class="top">
								<view class="name line2">{{item.store_name}}</view>
								<view class="flex items-end flex-wrap mt-12 w-full" v-if="item.store_label && item.store_label.length && item.is_show">
									<BaseTag
										:text="label.label_name"
										:color="label.color"
										:background="label.bg_color"
										:borderColor="label.border_color"
										:circle="label.border_color ? true : false"
										:imgSrc="label.icon"
										v-for="(label, idx) in item.store_label" :key="idx"></BaseTag>
								</view>
								<view class="text-w111-ccc mt-16" v-if="!item.is_show">此商品已失效</view>
							</view>
							<view class="money acea-row row-bottom row-between">
								<view class="acea-row row-bottom">
									<BaseMoney :money="item.price" symbolSize="24" integerSize="40" decimalSize="24"></BaseMoney>
									<view class="svip acea-row" v-if="item.price_type == 'member' && item.vip_price > 0 && item.is_show">
										<view class="labelCon acea-row row-middle">SVIP</view>
										<view class="acea-row row-middle">
											<BaseMoney :color="'#333'" :money="item.vip_price" symbolSize="22" integerSize="22" decimalSize="22"></BaseMoney>
										</view>
									</view>
								</view>
								<view class="icon acea-row row-center-wrapper">
									<text class="iconfont icon-ic_ShoppingCart1"></text>
								</view>
							</view>
						</view>
					</view>
				</checkbox-group>
			</view>
			<view class='loadingicon acea-row row-center-wrapper'>
				<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
			</view>
			<view class="footer acea-row row-between-wrapper" v-if="administer">
				<checkbox-group @change="checkboxAllChange">
					<checkbox value="all" :checked="isAllSelect" />
					<text class='checkAll'>全选</text>
				</checkbox-group>
				<view class="acea-row row-middle">
					<view class="bnt acea-row row-center-wrapper" @click="del('product')">取消收藏({{getIds().length}})</view>
				</view>
			</view>
			<view class="footer-placeholder" v-if="administer"></view>
			<view class="safe-placeholder"></view>
		</view>
		<view class="videoList" v-if="collectProductList.length && active">
			<view class='videoList-content'>
				<checkbox-group @change="checkboxChange">
					<view class="acea-row row-middle">
						<view class="item" v-for="(item,index) in collectProductList" :key="index" @click="goVideo(item.video_id)">
							<!-- #ifndef MP -->
							<checkbox class="checkbox" v-if="administer" :value="(item.id).toString()" :checked="item.checked" />
							<!-- #endif -->
							<!-- #ifdef MP -->
							<checkbox class="checkbox" v-if="administer" :value="item.id" :checked="item.checked" />
							<!-- #endif -->
							<image :src="item.image" mode="aspectFill"></image>
							<view class="like acea-row row-bottom">
								<text class="iconfont icon-ic_love"></text>{{item.like_num}}
							</view>
						</view>
					</view>
				</checkbox-group>
			</view>
			<view class='loadingicon acea-row row-center-wrapper'>
				<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
			</view>
			<view class="footer acea-row row-between-wrapper" v-if="administer">
				<checkbox-group @change="checkboxAllChange">
					<checkbox value="all" :checked="isAllSelect" />
					<text class='checkAll'>全选</text>
				</checkbox-group>
				<view class="acea-row row-middle">
					<view class="bnt acea-row row-center-wrapper" @click="del('video')">取消收藏({{getIds().length}})</view>
				</view>
			</view>
			<view class="footer-placeholder" v-if="administer"></view>
			<view class="safe-placeholder"></view>
		</view>
		<view class='px-20 mt-20' v-else-if="!collectProductList.length && page > 1">
			<emptyPage title="暂无收藏，去看点别的吧～" src="/statics/images/noCollection.gif"></emptyPage>
			<recommend v-if='hostProduct.length' :hostProduct="hostProduct"></recommend>
		</view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import colors from '@/mixins/color.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import {
		getCollectUserList,
		getProductHot,
		collectDel
	} from '@/api/store.js';
	import {
		mapGetters
	} from "vuex";
	import {
		toLogin
	} from '@/libs/login.js';
	import recommend from '@/components/recommend';
	import home from '@/components/home';
	import emptyPage from '@/components/emptyPage.vue';
	import WaterfallsFlow from '@/components/WaterfallsFlow/WaterfallsFlow.vue';
	export default {
		mixins: [colors],
		computed: mapGetters(['isLogin']),
		components: {
			recommend,
			home,
			emptyPage,
			WaterfallsFlow,
		},
		data() {
			return {
				navList: [{
						name: '商品'
					},
					{
						name: '视频'
					}
				],
				active: 0,
				hostProduct: [],
				loadTitle: '加载更多',
				loading: false,
				loadend: false,
				collectProductList: [],
				limit: 20,
				page: 1,
				hotScroll: false,
				hotPage: 1,
				hotLimit: 10,
				imgHost: HTTP_REQUEST_URL,
				administer: 0,
				isAllSelect: false,
				count: 0,
				isShowAuth: false,
        homeHide: false
			}
		},
		onLoad(options) {
			this.active = options.active || 0
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			this.loadend = false;
			this.page = 1;
			this.collectProductList = [];
			this.get_host_product();
			if (this.isLogin) {
				this.get_user_collect_product(this.active ? 'video' : 'product');
			} else {
				toLogin()
			}
		},
		methods: {
			onLoadFun() {
				this.get_user_collect_product('product');
				this.isShowAuth = false;
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			goGoods(item) {
				if (this.administer || !item.is_show) return false
				uni.navigateTo({
					url: `/pages/goods_details/index?id=${item.product_id}`
				});
			},
			goVideo(id) {
				if (this.administer) return false
				uni.navigateTo({
					//#ifdef APP
					url: '/pages/short_video/appSwiper/index?id=' + id,
					//#endif
					//#ifndef APP
					url: '/pages/short_video/nvueSwiper/index?id=' + id,
					//#endif
				})
			},
			//批量获取id集合
			getIds(){
				let ids = []
				this.collectProductList.forEach(item=>{
					if(item.checked){
						ids.push(item.id)
					}
				})
				return ids
			},
			del(type) {
				if (!this.getIds().length) {
					return this.$util.Tips({
						title: '请选择收藏商品或视频'
					});
				}
				collectDel(this.getIds(), type).then(res => {
					this.loadend = false;
					this.page = 1;
					this.$set(this, 'collectProductList', []);
					this.get_user_collect_product(type);
					return this.$util.Tips({
						title: res.msg
					});
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				})
			},
			checkboxChange(event) {
				let idList = event.detail.value;
				this.collectProductList.forEach((item) => {
					if (idList.indexOf(item.id + '') !== -1) {
						item.checked = true;
					} else {
						item.checked = false;
					}
				})
				if (idList.length == this.collectProductList.length) {
					this.isAllSelect = true;
				} else {
					this.isAllSelect = false;
				}
			},
			forGoods(val) {
				let that = this;
				if (!that.collectProductList.length) return
				that.collectProductList.forEach((item) => {
					if (val) {
						item.checked = true;
					} else {
						item.checked = false;
					}
				})
			},
			checkboxAllChange(event) {
				let value = event.detail.value;
				if (value.length) {
					this.isAllSelect = true;
					this.forGoods(1)
				} else {
					this.isAllSelect = false;
					this.forGoods(0)
				}
			},
			manageTap() {
				this.administer = !this.administer;
			},
			navTap(index) {
				this.active = index;
				let type = 'product'
				if (index) {
					type = 'video'
				} else {
					type = 'product'
				}
				this.isAllSelect = false;
				this.forGoods(0);
				this.loadend = false;
				this.page = 1;
				this.$set(this, 'collectProductList', []);
				this.get_user_collect_product(type);
			},
			/**
			 * 获取收藏产品
			 */
			get_user_collect_product: function(type) {
				let that = this;
				if (this.loading) return;
				if (this.loadend) return;
				that.loading = true;
				that.loadTitle = "";
				getCollectUserList({
					page: that.page,
					limit: that.limit,
					category: type
				}).then(res => {
					let collectProductList = res.data.list;
					collectProductList.forEach(item => {
						item.checked = false;
					})
					this.count = res.data.count;
					let loadend = collectProductList.length < that.limit;
					that.collectProductList = that.$util.SplitArray(collectProductList, that.collectProductList);
					that.$set(that, 'collectProductList', that.collectProductList);
					that.loadend = loadend;
					that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					that.page = that.page + 1;
					that.loading = false;
				}).catch(err => {
					that.loading = false;
					that.loadTitle = "加载更多";
				});
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
      onhomehide() {
				this.homeHide = false;
			}
		},
		onPageScroll(e) {
			uni.$emit('scroll');
      this.homeHide = true;
		},
		onReachBottom() {
			if (this.collectProductList.length) {
				this.get_user_collect_product('product');
			}
		}
	}
</script>

<style lang="scss">
	.collection {
		.nav {
			background-color: #fff;
			height: 80rpx;

			.item {
				margin: 0 101rpx;
				font-weight: 400;
				color: #333333;
				font-size: 28rpx;
				position: relative;

				&.on {
					font-weight: 500;
					font-size: 30rpx;
					color: var(--view-theme);

					&:before {
						content: '';
						position: absolute;
						width: 64rpx;
						height: 6rpx;
						background: var(--view-theme);
						bottom: 0;
					}
				}
			}
		}

		.manage {
			margin-top: 20rpx;
			padding: 32rpx 24rpx 0;
			border-radius: 24rpx 24rpx 0 0;
			font-weight: 400;
			color: #333333;
			font-size: 28rpx;
			line-height: 40rpx;
			background-color: #fff;

			.close {
				color: #999999;
			}

			.num {
				color: var(--view-theme);
				margin: 0 5rpx;
			}
		}

		.collectList {
			.collectList-content {
				padding: 8rpx 0;
				border-radius: 0 0 24rpx 24rpx;
				background-color: #FFFFFF;
			}

			.item {
				position: relative;
				padding: 24rpx;

				.checkbox {
					align-self: center;
					margin-right: 20rpx;
				}

				::v-deep checkbox .uni-checkbox-input {
					margin-right: 0;
				}

				.text {
					flex: 1;
					padding-left: 20rpx;
					
					&.on{
						.name{
							color: #CCCCCC;
						}
						.base-money{
							color: #CCCCCC !important;
						}
						.money{
							.iconfont{
								color: #999;
							}
						}
					}

					.top {
						flex: 1;
					}

					.name {
						font-weight: 400;
						color: #333333;
						font-size: 28rpx;
						line-height: 40rpx;
					}

					.label {
						margin-top: 16rpx;

						.labelCon {
							height: 26rpx;
							border: 1px solid var(--view-theme);
							padding: 0 5rpx;
							color: var(--view-theme);
							font-weight: 400;
							font-size: 18rpx;
							line-height: 1;
							border-radius: 4rpx;
						}
					}

					.money {
						font-size: 24rpx;
						font-weight: 600;
						color: var(--view-theme);

						.num {
							font-size: 30rpx;
						}

						.svip {
							height: 26rpx;
							padding-right: 6rpx;
							border-radius: 13rpx;
							margin: 0 0 6rpx 8rpx;
							background-color: #FFF0D1;
							line-height: 1;
						}

						.labelCon {
							padding: 0 6rpx;
							border-radius: 13rpx 0 13rpx 13rpx;
							margin-right: 2rpx;
							background: linear-gradient(90deg, #484643 0%, #1F1B17 100%);
							font-weight: 600;
							font-size: 18rpx;
							color: #FDDAA4;
						}

						.icon {
							width: 48rpx;
							height: 48rpx;
							border-radius: 24rpx;
							background-color: #F5F5F5;
						}

						.iconfont {
							font-size: 30rpx;
							color: #333333;
						}
					}
				}
			}
		}

		.videoList {
			.videoList-content {
				padding: 8rpx 0;
				border-radius: 0 0 24rpx 24rpx;
				background-color: #FFFFFF;
			}

			.item {
				width: 226rpx;
				height: 300rpx;
				border-radius: 8rpx;
				position: relative;
				margin-left: 16rpx;
				margin-top: 20rpx;
				position: relative;
				overflow: hidden;

				image {
					width: 100%;
					height: 100%;
				}

				.checkbox {
					position: absolute;
					top: 10rpx;
					left: 10rpx;
					z-index: 9;
				}

				::v-deep checkbox .uni-checkbox-input {
					margin-right: 0;
					background-color: rgba(0, 0, 0, 0.16);
				}

				::v-deep checkbox .wx-checkbox-input {
					background-color: rgba(0, 0, 0, 0.16);
				}

				.like {
					position: absolute;
					color: #fff;
					bottom: 0;
					font-weight: 400;
					font-size: 20rpx;
					left: 0;
					width: 226rpx;
					height: 100rpx;
					background: linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, rgba(0, 0, 0, 0.25) 100%);
					border-radius: 0 0 8rpx 8rpx;
					padding: 0 0 14rpx 14rpx;

					.iconfont {
						font-size: 24rpx;
						margin-right: 6rpx;
					}
				}
			}
		}

		.safe-placeholder {
			height: constant(safe-area-inset-bottom);
			height: env(safe-area-inset-bottom);
		}

		.footer-placeholder {
			height: 96rpx;
		}

		.footer {
			box-sizing: border-box;
			padding: 0 32rpx;
			width: 100%;
			height: 96rpx;
			background-color: #fff;
			position: fixed;
			bottom: 0;
			z-index: 30;
			height: calc(96rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
			height: calc(96rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
			padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
			padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/
			width: 100%;
			left: 0;

			.bnt {
				width: 186rpx;
				height: 64rpx;
				border-radius: 32rpx;
				color: #fff;
				background-color: var(--view-theme);
				font-weight: 500;
				font-size: 24rpx;
			}
		}
	}
</style>