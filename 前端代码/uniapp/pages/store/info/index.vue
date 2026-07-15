<template>
	<view class="storeInfo" :style="colorStyle">
		<view class="headerBg">
			<image :src="storeInfo.background_image" mode="aspectFill" class="image"></image>
			<view class="shade"></view>
			<!-- #ifdef MP || APP -->
			<view :style="{height: getHeight.barTop+'px'}"></view>
			<view :style="{height: getHeight.barHeight+'px'}"></view>
			<!-- #endif -->
			<view style="height: 224rpx;"></view>
		</view>
		<view class="container">
			<!-- #ifdef MP || APP -->
			<NavBar titleText="店铺信息" textColor="#FFFFFF" iconColor="#FFFFFF" showBack></NavBar>
			<!-- #endif -->
			<view class="content">
				<view class="title-box">
					<image :src="storeInfo.image" class="logo"></image>
					<view class="title">{{storeInfo.name || ''}}</view>
					<view class="address">{{(storeInfo.address+storeInfo.detailed_address) || ''}}</view>
				</view>
				<view v-if="latitude" class="map-box">
					<map name="" style="width: 100%; height: 100%;" :scale="18" :latitude="latitude" :longitude="longitude" :markers="markers" @markertap="showMaoLocation"></map>
				</view>
				<!-- #ifdef APP -->
				<tuiCollapse :index="index" :current="current" @click="qrcodeCollapse">
					<template v-slot:title>
						<tuiListCell :hover="false">店铺二维码</tuiListCell>
					</template>
					<template v-slot:content>
						<view class="acea-row row-center-wrapper qrcode-image">
							<w-qrcode :options="config.qrc"></w-qrcode>
						</view>
					</template>
				</tuiCollapse>
				<!-- #endif -->
				<!-- #ifndef APP -->
				<view class="qrcode-box acea-row row-between row-middle" @click="showQRCode">
					<view class="name">店铺二维码</view>
					<view class="value">
						<text class="iconfont icon-ic_QRcode" @click="showQRCode"></text>
					</view>
				</view>
				<!-- #endif -->
				<view class="time-box">
					<view class="item acea-row">
						<view class="name">店铺简介</view>
						<view class="value">{{storeInfo.introduction}}</view>
					</view>
					<view class="item acea-row">
						<view class="name">营业时间</view>
						<view class="value">{{storeInfo.day_start}}-{{storeInfo.day_end}}</view>
					</view>
					<view class="item acea-row">
						<view class="name">所在地区</view>
						<view class="value">{{storeInfo.address}}</view>
					</view>
					<view class="item acea-row" @tap="makePhone(storeInfo.phone)">
						<view class="name">联系电话</view>
						<view class="value">{{storeInfo.phone}}</view>
						<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999"></text>
					</view>
				</view>
			</view>
			<view class="btn" @click="goStore">查看全部商品</view>
		</view>
		<view v-if="dialogShow">
			<view class="mask"></view>
			<view class="dialog">
				<view class="title">{{storeInfo.name}}</view>
				<!-- #ifdef MP -->
				<image :src="storeInfo.qrcode.routine" class="image"></image>
				<!-- #endif -->
				<!-- #ifdef H5 -->
				<image v-if="$wechat.isWeixin()" :src="storeInfo.qrcode.wechat" class="image"></image>
				<view class="acea-row row-center-wrapper" style="margin: 36rpx 0 22rpx;" v-else>
					<w-qrcode :options="config.qrc"></w-qrcode>
				</view>
				<!-- #endif -->
				<view class="tips">长按识别二维码进入店铺</view>
				<text class="iconfont icon-ic_close1" @click="dialogShow = false"></text>
			</view>
		</view>
	</view>
</template>

<script>
	import NavBar from '@/components/NavBar.vue';
	import tuiCollapse from '../components/thorui/tui-collapse.vue';
	import tuiListCell from '../components/thorui/tui-list-cell.vue';
	import colors from '@/mixins/color.js';
	import {HTTP_REQUEST_URL} from '@/config/app';
	import {mapGetters} from 'vuex';
	import {
		getStoreData
	} from '@/api/store.js';

	export default {
		components: {
			NavBar,
			tuiCollapse,
			tuiListCell
		},
		mixins: [colors],
		data() {
			return {
				getHeight: this.$util.getWXStatusHeight(),
				store_id: 0,
				storeInfo: {
					latitude: 0,
					longitude: 0,
					day_start: '',
					day_end: '',
				},
				latitude: 0,
				longitude: 0,
				markers: [],
				dialogShow: false,
				config: {
					qrc: {
						code: '',
						size: 380, // 二维码大小
						level: 3, //等级 0～4
						bgColor: '#FFFFFF', //二维码背景色 默认白色
						border: {
							color: ['#eee', '#eee'], //边框颜色支持渐变色
							lineWidth: 3, //边框宽度
						},
						color: ['#333', '#333'], //边框颜色支持渐变色
					}
				},
				index: 0,
				current: -1,
			}
		},
		computed: mapGetters(['uid']),
		onLoad(options) {
			this.store_id = options.store_id;
			// #ifndef MP
			this.config.qrc.code = HTTP_REQUEST_URL + '/pages/store/home/index?id=' + options.store_id;
			// #endif
			this.getStoreData();
			// #ifdef H5
			this.setOpenShare();
			// #endif
		},
		onShow() {},
		methods: {
			goStore(){
				uni.navigateTo({
					url: '/pages/store/home/index?mapFrom=1&id=' + this.store_id
				})
			},
			qrcodeCollapse(e) {
				this.current = this.current == e.index ? -1 : e.index;
			},
			showQRCode() {
				this.dialogShow = true;
			},
			showMaoLocation() {
				let data = {
					latitude: Number(this.storeInfo.latitude),
					longitude: Number(this.storeInfo.longitude),
					name: this.storeInfo.name,
					address: `${this.storeInfo.address+this.storeInfo.detailed_address}`,
				};
				// #ifdef H5
				if (this.$wechat.isWeixin()) {
					data.scale = 13;
					return this.$wechat.seeLocation(data);
				}
				// #endif
				uni.openLocation(data);
			},
			getStoreData() {
				getStoreData({
					store_id: this.store_id
				}).then(res => {
					this.storeInfo = res.data;
					this.$nextTick(() => {
						this.latitude = res.data.latitude
						this.longitude = res.data.longitude
						this.markers = [{
							id: res.data.id,
							latitude: res.data.latitude,
							longitude: res.data.longitude,
							iconPath: '../static/marker-icon.png',
							width: 42,
							height: 42,
							callout: {
								content: res.data.name,
								bgColor: '#FFFFFF',
								padding: 6,
								borderRadius: 8,
								textAlign: 'center',
								display: 'ALWAYS'
							}
						}];
					})
				});
			},
			// #ifdef H5
			// 微信分享；
			setOpenShare: function() {
				let that = this;
				let uid = this.uid ? this.uid : 0;
				if (that.$wechat.isWeixin()) {
					let configAppMessage = {
						title: that.storeInfo.name,
						link: location.href + '?spid=' + uid
					};
					that.$wechat.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData',
							'onMenuShareAppMessage', 'onMenuShareTimeline'
						],
						configAppMessage);
				}
			},
			// #endif
			makePhone(phone) {
				uni.makePhoneCall({
					phoneNumber: phone
				});
			}
		},
		/**
		 * 用户点击右上角分享
		 */
		//#ifdef MP
		onShareAppMessage() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: this.storeInfo.name,
				path: '/pages/store/info/index?store_id='+this.store_id + '&spid=' + uid
			};
		},
		//分享到朋友圈
		onShareTimeline: function() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: this.storeInfo.name,
				path: '/pages/store/info/index?store_id='+this.store_id + '&spid=' + uid
			};
		}
		//#endif
	}
</script>

<style lang="scss" scoped>
	.headerBg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;

		.image {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
		}

		.shade {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: linear-gradient(180deg, rgba(0, 0, 0, 0.4047) 0%, #F5F5F5 100%);
		}
	}

	.storeInfo {
		.container {
			position: relative;
			padding: 108rpx 20rpx 0;
		}

		.content {
			padding: 0 32rpx;
			border-radius: 16rpx;
			background: #FFFFFF;
		}

		.title-box {
			position: relative;
			// min-height: 176rpx;
			padding-top: 104rpx;
			padding-bottom: 32rpx;
			text-align: center;

			.logo {
				position: absolute;
				top: -68rpx;
				left: 50%;
				width: 132rpx;
				height: 132rpx;
				border-radius: 12rpx;
				transform: translateX(-50%);
			}

			.title {
				font-weight: 500;
				font-size: 32rpx;
				line-height: 44rpx;
				color: #333333;
			}

			.address {
				margin-top: 20rpx;
				font-size: 26rpx;
				line-height: 40rpx;
				color: #666666;
			}
		}

		.time-box {
			padding: 32rpx 0;

			.item {
				margin-top: 40rpx;
				font-size: 28rpx;
				line-height: 40rpx;

				&:first-child {
					margin-top: 0;
				}
			}

			.name {
				font-weight: 500;
				color: #333333;
			}

			.value {
				flex: 1;
				min-width: 0;
				padding-left: 32rpx;
				color: #666666;
			}
		}

		.qrcode-box {
			padding: 40rpx 0 32rpx;
			border-bottom: 2rpx solid #F8F8F8;
			line-height: 46rpx;

			.name {
				font-weight: 500;
				font-size: 28rpx;
				color: #333333;
			}

			.iconfont {
				font-size: 38rpx;
				color: #333333;
			}
		}

		.map-box {
			height: 352rpx;
			border-radius: 16rpx;
			background: #DFDFDF;
			overflow: hidden;
		}

		.btn {
			height: 88rpx;
			border-radius: 16rpx;
			margin-top: 20rpx;
			background: #FFFFFF;
			text-align: center;
			font-size: 28rpx;
			line-height: 88rpx;
			color: var(--view-theme);
		}

		.dialog {
			position: fixed;
			top: 50%;
			right: 65rpx;
			left: 65rpx;
			z-index: 6;
			max-width: 620rpx;
			padding: 60rpx 0 42rpx;
			border-radius: 24rpx;
			background: #FFFFFF;
			transform: translateY(-50%);

			.title {
				text-align: center;
				font-weight: 500;
				font-size: 32rpx;
				line-height: 44rpx;
				color: #333333;
			}

			.image {
				display: block;
				width: 420rpx;
				height: 420rpx;
				margin: 36rpx auto 22rpx;
			}

			.tips {
				text-align: center;
				font-size: 26rpx;
				line-height: 36rpx;
				color: #999999;
			}

			.iconfont {
				position: absolute;
				bottom: -108rpx;
				left: 50%;
				transform: translateX(-50%);
				font-size: 60rpx;
				color: #CCCCCC;
			}
		}
	}

	::v-deep .tui-list-cell {
		padding: 40rpx 0 !important;

	}

	::v-deep .open {
		top: 50% !important;
		right: 20rpx !important;
		transform: translateY(-50%);
		font-size: 24rpx;
		color: #666666;
	}

	::v-deep .tui-icon-arrow {
		right: 0 !important;
		margin-top: -5px !important;
	}

	.qrcode-image {
		padding: 8rpx 0 40rpx;
	}

	::v-deep .tui-collapse {
		border-bottom: 2rpx solid #F8F8F8;
	}

	::v-deep .tui-list-cell::after {
		display: none;
	}
</style>