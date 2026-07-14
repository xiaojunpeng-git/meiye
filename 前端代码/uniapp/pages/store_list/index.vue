<template>
	<view :style="colorStyle" class="container">
		<view class="headerBg">
			<!-- #ifdef MP || APP -->
			<view :style="{height: getHeight.barTop+'px'}"></view>
			<view :style="{height: getHeight.barHeight+'px'}"></view>
			<!-- #endif -->
			<view style="height: 240rpx;"></view>
		</view>
		<view class="page acea-row row-column">
			<!-- #ifdef MP || APP -->
			<NavBar titleText="门店列表" textColor="#FFFFFF"></NavBar>
			<!-- #endif -->
			<view class="navBox acea-row row-between">
				<view :class="{ on: content.store_type === 1 }" class="item acea-row row-center row-middle" @tap="navChange(1)">附近</view>
				<view :class="{ on: content.store_type === 2 }" class="item acea-row row-center row-middle" @tap="navChange(2)">常用</view>
				<view class="header_search acea-row">
					<view class="name acea-row row-middle row-center" @click="btnFn(2)">{{area}}<text class="iconfont icon-ic_down2"></text></view>
					<view class="box acea-row row-middle">
						<input type="text" confirm-type="search" placeholder="请输入门店名称" class="input" @input="setValue" v-model="content.keyword" />
						<text class="iconfont icon-ic_search"></text>
					</view>
				</view>
				<view class="mapbtn" @click="goMap">
					<image src="@/static/images/map.png" class="image"></image>
				</view>
			</view>
			<scroll-view scroll-y="true" class="scroll-view">
				<!-- 门店列表 -->
				<view class="content" v-if="info.length>0">
					<view class="list acea-row row-middle" v-for="(item,index) in info" :key="item.id" @click="activeFn(item)">
						<image class="image" :src="item.image"></image>
						<view class="left">
							<view class="name line2">
								{{item.name}}
							</view>
							<view class="adress line1">
								{{item.address}}{{item.detailed_address}}
							</view>
							<view class="distance">
								距您 <text class="range">{{item.range}}</text> km
							</view>
						</view>
						<view class="right">
							<view class="gostore acea-row row-center row-middle">进店</view>
						</view>
					</view>
				</view>
				<!-- 缺省页 -->
				<view class="px-20" v-if="info.length==0">
					<emptyPage title="暂无门店信息~" src="/statics/images/noOrder.gif"></emptyPage>
				</view>
			</scroll-view>
			<view :style="{height: pdHeight*2+96+'rpx'}" v-if="showBar"></view>
			<view class="safe-area-inset-bottom" v-if="showBar"></view>
		</view>
		<areaWindow ref="areaWindow" :display="display" :checkStrictly="false" :cityShow="2" :address="addressInfo" @submit="OnChangeAddress" @changeClose="changeClose"></areaWindow>
		<pageFooter @newDataStatus="newDataStatus"></pageFooter>
	</view>
</template>

<script>
	import {mapGetters} from 'vuex';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import colors from "@/mixins/color";
	import {
		getList
	} from '@/api/new_store.js'
	import {
		getGeocoder,
		getCityList
	} from '@/api/user.js';
	import {getCartCounts} from '@/api/order.js';
	import NavBar from '@/components/NavBar.vue';
	import emptyPage from '@/components/emptyPage.vue';
	import areaWindow from '@/components/areaWindow/index.vue';
	import pageFooter from '@/components/pageFooter/index.vue';
	export default {
		components: {
			NavBar,
			emptyPage,
			areaWindow,
			pageFooter,
		},
		mixins: [colors],
		data() {
			return {
				getHeight: this.$util.getWXStatusHeight(),
				imgHost: HTTP_REQUEST_URL,
				sortIndex: 0,
				active: 1,
				content: {
					// 自己的位置
					latitude: uni.getStorageSync('user_latitude'),
					longitude: uni.getStorageSync('user_longitude'),
					store_type: 1,
					keyword: "",
					province: 0,
					city: 0,
					area: 0,
				},
				info: [],
				map: 1,
				header: 1,
				comeType: 0,
				// 门店位置
				latitudeStore: uni.getStorageSync('user_latitude'),
				longitudeStore: uni.getStorageSync('user_longitude'),
				covers: [],
				display: false,
				addressInfo: [],
				showBar: 0,
				pdHeight: 0,
			};
		},
		computed: {
			area() {
				let area = '全部';
				for (let i = 0; i < this.addressInfo.length; i++) {
					if (this.addressInfo[i].value) {
						area = this.addressInfo[i].label
					}
				}
				return area;
			},
			...mapGetters(['cartNum','isLogin'])
		},
		onShow() {
			// 这里是 单店 调用；（onShow和onLoad分开写是因为平台模式不需要每次都调用）
			let shopOperationType = uni.getStorageSync('shop_operation_type');
			if(shopOperationType == 3){
				this.getlist()
			}
			if (this.isLogin) {
				this.getCartNum();
			}
		},
		onLoad(options) {
			// 这里是 多店+平台、单店+平台 调用；
			let shopOperationType = uni.getStorageSync('shop_operation_type');
			if(shopOperationType != 3){
				this.getlist()
			}
		},
		methods: {
			getCartNum: function() {
				getCartCounts().then(res => {
					this.$store.commit('indexData/setCartNum', res.data.resultAll.count + '')
					let cartNum = res.data.resultAll.count;
					if (cartNum > 0) {
						uni.setTabBarBadge({
							index: 3,
							text: cartNum > 99 ? '99+' : cartNum + ''
						})
					} else {
						uni.hideTabBarRedDot({
							index: 3
						})
					}
				}).catch(err=>{
					return this.$util.Tips({
						title: err.msg
					});
				})
			},
			goMap() {
				uni.navigateTo({
					// #ifdef APP
					url: `/pages/store/mapnvue/index?province=${this.content.province}&city=${this.content.city}&area=${this.content.area}`
					// #endif
					// #ifndef APP
					url: `/pages/store/map/index?province=${this.content.province}&city=${this.content.city}&area=${this.content.area}`
					// #endif
				})
			},
			navChange(type) {
				this.content.store_type = type;
				this.getlist();
			},
			getCityList({
				province,
				city,
				district
			}) {
				getCityList(`${province}/${city}/${district}`).then(({
					data
				}) => {
					this.addressInfo = data;
					this.content.province = data[0].value;
					this.content.city = data[1].value;
					this.content.area = data[2].value;
					this.getlist();
				});
			},
			getGeocoder(location) {
				getGeocoder(location).then(res => {
					const {
						province,
						city,
						district
					} = res.data.address_component;
					this.getCityList({
						province,
						city,
						district
					});
				});
			},
			selfLocation() {
				let self = this
				// #ifdef H5
				if (self.$wechat.isWeixin()) {
					self.$wechat.location().then(res => {
						this.content.latitude = res.latitude;
						this.content.longitude = res.longitude;
						uni.setStorageSync('user_latitude', res.latitude);
						uni.setStorageSync('user_longitude', res.longitude);
						self.getlist();
					}).catch(err => {
						self.getlist();
					})
				} else {
					// #endif	
					uni.getLocation({
						type: 'wgs84',
						success: ({
							latitude,
							longitude
						}) => {
							try {
								this.getGeocoder({
									lat: latitude,
									long: longitude
								});
							} catch {}
						},
					});
					// #ifdef H5	
				}
				// #endif
			},
			// 门店列表
			getlist() {
				getList(this.content).then(res => {
					this.info = res.data
					res.data.forEach(item => {
						if (this.sortIndex == item.id) {
							this.latitudeStore = item.latitude;
							this.longitudeStore = item.longitude;
							this.covers.push({
								title: item.name,
								latitude: item.latitude,
								longitude: item.longitude,
								iconPath: item.image,
								width: 30,
								height: 30
							})
						}
					})
				})
			},
			// 输入关键字搜索门店
			setValue(e) {
				this.getlist()
			},
			// 点击高亮
			activeFn(row) {
				uni.navigateTo({
					url: '/pages/store/home/index?mapFrom=1&id=' + row.id
				})
			},
			// 搜索门店
			searchMap() {
				this.header = 0
			},
			// 收起地图
			putMap() {
				if (this.map == 1) {
					this.map = 0
				} else if (this.map == 0) {
					this.map = 1
				}
			},
			// 打电话
			callPhone(row) {
				uni.makePhoneCall({
					phoneNumber: row.phone //仅为示例
				});
			},
			btnFn(num) {
				this.display = true;
			},
			showMaoLocation(e) {
				let self = this;
				// #ifdef H5
				if (self.$wechat.isWeixin()) {
					return self.$wechat.seeLocation({
						latitude: Number(e.latitude),
						longitude: Number(e.longitude),
						name: e.name,
						scale: 13,
						address: `${e.address}-${e.detailed_address}`,
					}).then(res => {})
				}
				// #endif
				uni.openLocation({
					latitude: Number(e.latitude),
					longitude: Number(e.longitude),
					name: e.name,
					address: `${e.address}-${e.detailed_address}`,
					success: function() {
						Number
					}
				});
			},
			changeClose() {
				this.display = false;
			},
			OnChangeAddress(address) {
				this.addressInfo = address;
				this.content.province = address[0] ? address[0].value : 0;
				this.content.city = address[1] ? address[1].value : 0;
				this.content.area = address[2] ? address[2].value : 0;
				this.getlist();
			},
			newDataStatus(val, num) {
				this.showBar = val;
				this.pdHeight = num;
			},
		}
	}
</script>

<style lang="scss">
	.safe-area-inset-bottom {
		height: constant(safe-area-inset-bottom);
		height: env(safe-area-inset-bottom);
	}

	page {
		background: #F5F5F5;
	}

	.container {
		position: absolute;
		top: 0;
		right: 0;
		bottom: 0;
		left: 0;
		overflow: hidden;
		flex-wrap: nowrap;
	}

	.headerBg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		background: linear-gradient(360deg, #F8F8F8 0%, rgba(248, 248, 248, 0) 100%),
			linear-gradient(-90deg, var(--view-gradient) 37%, var(--view-theme) 100%);
		background-position: left bottom, left bottom;
		background-repeat: no-repeat;
		background-size: 100% 114rpx, 100% 100%;
	}

	.page {
		position: relative;
		flex-wrap: nowrap;
		height: 100%;
	}

	.nearby .active,
	.content .active {
		position: relative;
		border: 1px solid var(--view-theme) !important;

		.icon-xuanzhong6 {
			font-size: 46rpx;
			position: absolute;
			bottom: -8rpx;
			right: -6rpx;
			color: var(--view-theme);
		}
	}

	.activeColor {
		background-color: var(--view-theme) !important;
		color: #fff !important;
	}

	.scroll-view {
		flex: 1;
		min-height: 0;
	}

	.content {
		padding: 0 20rpx;
		overflow: hidden;

		.list {
			padding: 32rpx;
			border-radius: 16rpx;
			margin-bottom: 20rpx;
			background: #FFFFFF;

			.image {
				width: 140rpx;
				height: 140rpx;
				border-radius: 12rpx;
			}

			.left {
				flex: 1;
				min-width: 0;
				padding: 0 32rpx 0 24rpx;

				.name {
					font-weight: 500;
					font-size: 28rpx;
					line-height: 40rpx;
					color: #333333;
				}

				.adress {
					margin-top: 10rpx;
					font-size: 22rpx;
					line-height: 30rpx;
					color: #999999;
				}

				.distance {
					margin-top: 10rpx;
					font-size: 20rpx;
					line-height: 20rpx;
					color: #999999;

					.range {
						color: var(--view-theme);
					}
				}
			}

			.right {
				.gostore {
					flex-wrap: nowrap;
					width: 108rpx;
					height: 52rpx;
					border-radius: 26rpx;
					background: linear-gradient(-90deg, var(--view-gradient) 37%, var(--view-theme) 100%);
					font-weight: 500;
					font-size: 22rpx;
					color: #FFFFFF;
				}
			}
		}
	}

	.lyric-enter,
	.lyric-leave-to {
		opacity: 0;
		transform: translateY(60px);
	}

	.lyric-enter-to,
	.lyric-leave {
		opacity: 1;
	}

	.lyric-enter-active,
	.lyric-leave-active {
		transition: all 0.3s;
	}

	.header_search {
		width: 416rpx;
		height: 58rpx;
		border-radius: 29rpx;
		padding: 0 0 0 24rpx;
		background: rgba(255, 255, 255, 0.3);

		.name {
			color: #FFFFFF;
			position: relative;
			font-size: 24rpx;

			.iconfont {
				font-size: 16rpx;
				margin-left: 6rpx;
			}

			&:before {
				position: absolute;
				content: '';
				width: 1px;
				height: 24rpx;
				background-color: rgba(255, 255, 255, 0.4);
				right: 0;
				top: 50%;
				margin-top: -12rpx;
			}

			padding-right: 20rpx;
		}

		.box {
			flex: 1;
			min-width: 0;
			height: 58rpx;
			padding-right: 18rpx;

			.input {
				flex: 1;
				min-width: 0;
				height: 58rpx;
				padding-left: 20rpx;
				font-size: 24rpx;
				color: rgba(255, 255, 255, 1);
			}

			.input-placeholder {
				color: rgba(255, 255, 255, 0.6);
			}

			.iconfont {
				font-size: 32rpx;
				color: rgba(255, 255, 255, 0.6);
			}
		}
	}

	.navBox {
		flex-wrap: nowrap;
		padding: 40rpx 20rpx 42rpx 32rpx;

		.item {
			position: relative;
			font-size: 28rpx;
			color: rgba(255, 255, 255, 0.6);
		}

		.on {
			font-weight: 500;
			font-size: 30rpx;
			color: #FFFFFF;

			&::after {
				position: absolute;
				content: '';
				width: 36rpx;
				height: 30rpx;
				border: 2px solid #FFFFFF;
				border-left: 2px solid transparent !important;
				border-top: 2px solid transparent !important;
				border-right: 2px solid transparent !important;
				border-radius: 50%;
				left: 50%;
				bottom: -12rpx;
				margin-left: -24rpx;
			}
		}

		.mapbtn {
			width: 54rpx;
			height: 54rpx;

			.image {
				width: 100%;
				height: 100%;
			}
		}
	}
</style>