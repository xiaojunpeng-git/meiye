<template>
	<view class="">
		<image src="../static/vip-paid-active.png" class="w-full h-710"></image>
		<form class="form" @submit="checkForm">
			<input v-model="account" class="input" name="account" type="text" placeholder="请输入卡号" placeholder-style="color:#CCCCCC" />
			<input v-model="password" :cursor-spacing="85" class="input" name="password" type="text" placeholder="请输入卡密" placeholder-style="color:#CCCCCC" password />
			<button class="button" form-type="submit">确认激活</button>
			<view class="look"><text class="link" @click="goPaidRights">查看会员权益</text></view>
		</form>
		<view class="activate" v-if="showActive">
			<view class="zs-bg"></view>
			<view class="active-card flex-center relative">
				<view class="yuan flex-center">
					<text class="iconfont icon-ic_complete fs-60 text--w111-fff"></text>
				</view>
				<view class="w-430 fs-34 flex-x-center">亲爱的 {{userInfo.nickname}}</view>
			</view>
			<view class="w-full active-success text-center fs-44 fw-500">激活成功</view>
			<view class="active-btn h-88 rd-44rpx flex-center fs-28 fw-500" @tap="activeConfirm">开始使用</view>
		</view>
	</view>
</template>

<script>
	import { memberCardDraw } from '@/api/user.js';
	import {USER_INFO} from '@/config/cache';
	import Cache from '@/utils/cache';
	export default {
		data() {
			return {
				account: '',
				password: '',
				showActive: false,
				userInfo: JSON.parse(Cache.get(USER_INFO))
			}
		},
		methods: {
			checkForm(e) {
				let formData = e.detail.value,
					data = {
						member_card_code: '',
						member_card_pwd: '',
						from: 'H5 '
					};
				if (!formData.account) {
					return this.$util.Tips({
						title: '请输入卡号'
					})
				}
				if (!formData.password) {
					return this.$util.Tips({
						title: '请输入卡密'
					})
				}
				data.member_card_code = formData.account;
				data.member_card_pwd = formData.password;
				// #ifdef H5
				let ua = navigator.userAgent.toLowerCase();
				if (ua.match(/MicroMessenger/i) == 'micromessenger') {
					data.from = 'weixin';
				}
				// #endif
				// #ifdef MP
				data.from = 'routine';
				// #endif
				uni.showLoading({
					title: '激活中'
				});
				memberCardDraw(data).then(res => {
					let that = this;
					uni.showToast({
						title: res.msg,
						icon: 'none'
					});
					this.showActive = true;
				}).catch(err => {
					return this.$util.Tips({
						title:err,
					})
				});
			},
			goPaidRights() {
				uni.navigateTo({
					url: '/pages/annex/vip_paid_rights/index'
				})
			},
			activeConfirm(){
				uni.reLaunch({
					url: '/pages/annex/vip_paid/index'
				})
			}
		},
	}
</script>

<style lang="scss" scoped>
	.form {
		.input {
			height: 88rpx;
			padding: 0 24rpx;
			border: 1rpx solid rgba(126, 75, 6, 0.5);
			border-radius: 16rpx;
			margin: 32rpx 40rpx;
			background: rgba(255, 255, 255, 0.6);
			font-size: 30rpx;
			color: #333333;

			&:first-child {
				margin-top: 8rpx;
			}
		}

		.button {
			height: 88rpx;
			border-radius: 44rpx;
			margin: 56rpx 40rpx 40rpx;
			background: #333333;
			font-weight: 500;
			font-size: 28rpx;
			line-height: 88rpx;
			color: #FACC7D;
		}

		.look {
			text-align: center;
			line-height: 42rpx;
		}

		.link {
			font-size: 26rpx;
			color: #999999;
		}
	}
	.activate{
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background: linear-gradient(180deg, #312B23 0%, #19140E 100%);
		z-index: 5;
	}
	.zs-bg{
		position: absolute;
		top: 276rpx;
		left: 50%;
		transform: translateX(-50%);
		width: 443rpx;
		height: 392rpx;
		background-image: url('../static/active-zs.png');
		background-size: cover;
	}
	.active-card{
		width: 516rpx;
		height: 298rpx;
		background: linear-gradient(270deg, #ECD8C8 0%, #DBBEA2 100%);
		border-radius: 24rpx;
		margin: 314rpx auto 0;
	}
	.yuan{
		width: 120rpx;
		height: 120rpx;
		border-radius: 50%;
		border: 6rpx solid #fff;
		background-color: #F0CA86;
		position: absolute;
		top: -60rpx;
		left: 50%;
		transform: translateX(-50%);
	}
	.active-success{
		margin: 140rpx auto 48rpx;
		color: #FACC7D;
	}
	.active-btn{
		width: 516rpx;
		background-color: #FACC7D;
		margin: auto;
	}
</style>
<style>
	page{
		background: #F3ECE4;
	}
</style>
