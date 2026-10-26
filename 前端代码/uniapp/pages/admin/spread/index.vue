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
		<NavBar titleText="专属客服" iconColor="#FFFFFF" textColor="#FFFFFF" showBack></NavBar>
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
					{{info.store_info.name||''}}
				</view>
			</view>
			<!-- 企业微信图片 -->
			<view class="weixin-img pb-100">
				<image v-if="currentTab == 1" :show-menu-by-longpress="true" :src="info.work_member_code" alt="" class="img" @click="previewImage(info.work_member_code)">
				</image>
				<!-- #ifdef MP -->
				<image v-if="currentTab == 2" :show-menu-by-longpress="true" :src="info.routine" alt="" class="img" @click="previewImage(info.routine)">
				</image>
				<!-- #endif -->
				<!-- #ifndef MP -->
				<image v-if="currentTab == 2" :show-menu-by-longpress="true" :src="info.wechat" alt="" class="img" @click="previewImage(info.wechat)">
				</image>
				<!-- #endif -->
				<view v-if="tabs.length > 1" class="tabbar flex-y-center h-76 px-8">
					<view v-for="(tab, index) in tabs" :key="index" :class="{
						'bg--w111-fff': currentTab == tab.value,
						'text--w111-333': currentTab == tab.value,
						'text--w111-999': currentTab != tab.value,
					}" class="flex-1 h-60 rd-30rpx fw-500 fs-26 lh-60rpx text-center" @click="handleTab(tab.value)">{{tab.label}}</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import NavBar from '@/components/NavBar.vue';
	import {
		userInfo,
	} from '@/api/admin.js'
	export default {
		components: {
			NavBar
		},
		data() {
			return {
				getHeight: this.$util.getWXStatusHeight(),
				info: {
					store_info: {
						name: ''
					}
				},
				id: 0,
				currentTab: 1,
				tabs: []
			};
		},
		onShow() {
			this.getInfo()
		},
		methods: {
			getInfo() {
				userInfo().then(res => {
					const data = res.data || {};
					let tabs = [{
							value: 1,
							label: '联系店员'
						},
						{
							value: 2,
							label: '扫码进店'
						},
					];
					tabs = tabs.filter(tab => !(tab.value === 1 && !data.work_member_code));
					this.currentTab = tabs[0].value || 1;
					this.tabs = tabs;
					this.info = data;
				})
			},
			previewImage(current) {
				uni.previewImage({
					// 需要预览的图片链接列表
					urls: [current],
					// 为当前显示图片的链接/索引值
					current: 0,
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
			},
			handleTab(value) {
				this.currentTab = value;
			}
		}
	}
</script>

<style>
	page {
		background: linear-gradient(180deg, #1B1A17 0%, #141414 100%);
		overflow: hidden;
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
		margin-top: 74rpx;
		text-align: center;

		.img {
			width: 144rpx;
			height: 144rpx;
			border: 8rpx solid #FFFFFF;
			border-radius: 50%;
			box-sizing: border-box;
		}

		.name {
			margin-top: 32rpx;
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
		overflow: hidden;

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
				border-left: 2rpx dashed rgba(20, 20, 20, 0.2);
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

	.tabbar {
		width: 320rpx;
		border-radius: 38rpx;
		margin: 56rpx auto 0;
		background: rgba(51, 51, 51, 0.1);
	}
</style>