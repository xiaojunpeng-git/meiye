<template>
	<!-- 无数据时显示 -->
	<view class="empty-page w-full flex-col flex-center pt-82 bg--w111-fff rd-24rpx">
		<image class="empty-img" :src="imgSrc"></image>
		<view v-if="!isLogin" class="fs-28 fw-500 text--w111-282828">暂未登录</view>
		<view class="fs-26 text--w111-999 lh-36rpx pt-16">{{title}}</view>
		<view class="w-360 h-72 rd-36rpx flex-center font-num fw-500 fs-26 theme-border mt-48"
			v-if="!isLogin" @tap="goLog">立即登录</view>
		<slot name="bottom"></slot>
	</view>
</template>

<script>
	import {HTTP_REQUEST_URL} from '@/config/app';
	import { toLogin } from '@/libs/login.js';
	export default{
		props: {
			title: {
				type: String,
				default: '暂无记录',
			},
			src:{
				type: String,
				default: '/statics/images/empty-box.gif',
			},
			isLogin:{
				type:Boolean,
				default: true
			}
		},
		data(){
			return{
				imgHost:HTTP_REQUEST_URL
			}
		},
		computed:{
			imgSrc(){
				return HTTP_REQUEST_URL + this.src
			}
		},
		methods: {
			goLog(){
				toLogin()
			}
		}
	}

</script>

<style lang="scss">
	.pt-82{
		padding: 82rpx 0 160rpx;
	}
	.empty-img{
		width: 440rpx;
		height: 360rpx;
	}
	.theme-border{
		border: 1px solid var(--view-theme);
	}
</style>
