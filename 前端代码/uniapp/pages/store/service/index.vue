<template>
	<view class="body">
		<view class="headerBg">
			<image src="../static/card.png" class="image"></image>
			<!-- #ifdef MP -->
			<view :style="{height: getHeight.barTop+'px'}"></view>
			<view :style="{height: getHeight.barHeight+'px'}"></view>
			<!-- #endif -->
			<view style="height: 236rpx;"></view>
		</view>
		<!-- #ifdef MP -->
		<NavBar titleText="联系客服" iconColor="#FFFFFF" textColor="#FFFFFF" showBack></NavBar>
		<!-- #endif -->
		<view class="card">
			<view class="hole">
				<image src="../static/sling.png" class="image"></image>
			</view>
			<!-- 头像 -->
			<view class="head-portrait">
				<img :src="info.avatar" alt="" class="img">
				<view class="name">
					{{info.staff_name||''}}
				</view>
			</view>
			<!-- 具体介绍 -->
			<view class="content">
				<view class="title">
					{{info.store_name||''}}
				</view>
				<!-- <view class="address">
					<text class="iconfont icon-dizhi1" />
					{{info.detailed_address}}
				</view>
				<view class="address">
					<text class="iconfont icon-shijian1"></text> 营业时间：{{info.day_start}}-{{info.day_end}}
				</view> -->
			</view>
			<!-- 分割线 -->
			<!-- <view class="line" /> -->
			<!-- 企业微信图片 -->
			<view class="weixin-img">
				<!-- <view class="img"></view> -->
				<image :show-menu-by-longpress="true" :src="info.customer_url" alt="" class="img" @click="previewImage"></image>
				<view class="text-box">
					<view class="text-wrap">
						<view class="text">
							<text class="circle">1</text>
							使用微信扫描或长按识别二维码
						</view>
						<view class="text">
							<text class="circle">2</text>
							加我为好友，享受一对一服务
						</view>
					</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import NavBar from '@/components/NavBar.vue';
	import {
		getCustomerInfo
	} from '@/api/new_store.js'
	export default {
		components: {
			NavBar
		},
		data() {
			return {
				getHeight: this.$util.getWXStatusHeight(),
				info: {},
				id: 0,
			};
		},
		onShow() {
			this.getInfo()
		},
		onLoad(options) {
			this.id = options.id
		},
		methods: {
			getInfo() {
				getCustomerInfo(this.id).then(res => {
					this.info = res.data
				})
			},
			previewImage() {
				uni.previewImage({
					// 需要预览的图片链接列表
					urls: [],
					// 为当前显示图片的链接/索引值
					current: this.info.customer_url,
					// 图片指示器样式	
					indicator: 'default',
					// 是否可循环预览
					loop: false,
					// 长按图片显示操作菜单，如不填默认为保存相册
					// longPressActions:{
					// 	itemList:[this.l('发送给朋友'),this.l]
					// },
					success: res => {},
					fail: err => {}
				});
			}
		}
	}
</script>

<style>
	page {
		background: linear-gradient(180deg, #1B1A17 0%, #141414 100%);
		/* overflow: hidden; */
	}
</style>

<style lang="scss" scoped>
	.headerBg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;

		.image {
			position: absolute;
			right: 0;
			bottom: 0;
			width: 566rpx;
			height: 396rpx;
		}
	}

	.head-portrait {
		margin-top: 62rpx;
		text-align: center;

		.img {
			width: 144rpx;
			height: 144rpx;
			border: 8rpx solid #FFFFFF;
			border-radius: 50%;
			box-sizing: border-box;
		}

		.name {
			margin-top: 12rpx;
			font-weight: 500;
			font-size: 32rpx;
			line-height: 44rpx;
			color: #3D3D3D;
		}
	}

	.content {
		margin-top: 18rpx;
		text-align: center;

		.title {
			font-size: 28rpx;
			line-height: 40rpx;
			color: #666666;
		}

		.address {
			margin-top: 16rpx;
			font-size: 26rpx;
			font-weight: 400;
			color: #888888;

			.icon-dizhi1 {
				font-size: 26rpx;
				margin-right: 12rpx;
			}

			.icon-shijian1 {
				font-size: 26rpx;
				margin-right: 12rpx;
			}
		}
	}

	.body {
		padding: 0 30rpx;
	}

	.line {
		margin-top: 56rpx;
		width: 100%;
		height: 1px;
		background-color: #eee;
		margin-top: #eee;
	}

	.weixin-img {
		margin-top: 62rpx;

		.img {
			display: block;
			width: 392rpx;
			height: 392rpx;
			margin: 0 auto;
		}

		.text-box {
			padding: 44rpx 0;
			border-radius: 0 0 40rpx 40rpx;
			margin-top: 62rpx;
			background: #F9F9F9;
			text-align: center;
		}

		.text-wrap {
			display: inline-block;
			vertical-align: middle;
		}

		.text {
			position: relative;
			padding-bottom: 16rpx;
			text-align: left;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #666666;

			&:last-child {
				padding-bottom: 0;
			}
			
			&::after {
				content: "";
				position: absolute;
				top: 0;
				bottom: 0;
				left: 15rpx;
				width: 2rpx;
				border-left: 2rpx dashed rgba(20,20,20,0.2);
			}
		}

		.circle {
			position: relative;
			display: inline-block;
			width: 30rpx;
			height: 30rpx;
			border-radius: 50%;
			margin-right: 16rpx;
			background: #141414;
			text-align: center;
			font-size: 24rpx;
			line-height: 30rpx;
			color: #FFFFFF;
		}
	}

	.card {
		position: relative;
		width: 650rpx;
		padding-top: 40rpx;
		border-radius: 40rpx;
		margin: 182rpx auto 0;
		background: #FFFFFF;

		.hole {
			position: relative;
			width: 108rpx;
			height: 24rpx;
			border-radius: 12rpx;
			margin: 0 auto;
			background: #141414;

			.image {
				position: absolute;
				top: 50%;
				left: 50%;
				width: 172rpx;
				height: 196rpx;
				transform: translate(-50%, -100%);
			}
		}
	}
</style>