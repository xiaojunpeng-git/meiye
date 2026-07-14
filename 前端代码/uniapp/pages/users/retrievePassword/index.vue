<template>
	<view class="wrapper" :style="colorStyle">
		<view class="bag">
			<img :src="imgHost+'/statics/images/users/login-bg.jpg'" alt="" srcset="">
		</view>
		<!-- #ifdef MP -->
		<view class="title-bar" style="height: 43px;">
			<view class="icon" @click="back" v-if="!isHome">
				<image src="../static/left.png"></image>
			</view>
			<view class="icon" @click="home" v-else>
				<image src="../static/home.png"></image>
			</view>
			{{pageTitle}}
		</view>
		<!-- #endif -->
		<view class="page-msg">
			<view class="title">
				找回密码
			</view>
			<view class="tip"></view>
		</view>
		<view class="page-form">
			<view class="item">
				<input type='number' placeholder='请输入手机号码' placeholder-class='placeholder' v-model="account"
					:maxlength="11"></input>
			</view>
			<view class="item acea-row row-between-wrapper">
				<input type='number' placeholder='请输入验证码' placeholder-class='placeholder' :maxlength="6"
					class="codeIput" v-model="captcha"></input>
				<view class="line">

				</view>
				<button class="code font-num" :class="disabled === true ? 'on' : ''" :disabled='disabled' @click="code">
					{{ text }}
				</button>
			</view>
			<view class="item">
				<input type='password' placeholder='请输入密码' placeholder-class='placeholder' v-model="password"
					:maxlength="28"></input>
			</view>
			<view class="item">
				<input type='password' placeholder='请再次确认密码' placeholder-class='placeholder' v-model="passwordAgain"
					:maxlength="28"></input>
			</view>
			<view class="btn" @click="registerReset">
				确认
			</view>
			<view class="text-center fs-32 text--w111-999 mt-32" @click="back">立即登录</view>
		</view>
		<Verify @success="success" captchaType="clickWord" :imgSize="{ width: '330px', height: '155px' }"
			ref="verify"></Verify>
	</view>
</template>

<script>
	import sendVerifyCode from "@/mixins/SendVerifyCode";
	import colors from '@/mixins/color.js';
	import Verify from '../components/verify/verify.vue';
	import {
		registerVerify,
		registerReset,
		getCodeApi
	} from "@/api/user";
	import { HTTP_REQUEST_URL } from '@/config/app';
	export default {
		name: "RetrievePassword",
		components: {
			Verify
		},
		data: function() {
			return {
				account: "",
				password: "",
				passwordAgain:'',
				captcha: "",
				keyCode: "",
				codeUrl: "",
				codeVal: "",
				isShowCode: false,
				imgHost: HTTP_REQUEST_URL,
				type:'register'
			};
		},
		mixins: [sendVerifyCode,colors],
		methods: {
			back() {
				uni.navigateBack();
			},
			again() {
				this.codeUrl =
					VUE_APP_API_URL + "/captcha?" + this.keyCode + Date.parse(new Date());
			},
			async registerReset() {
				var that = this;
				if (!that.account) return that.$util.Tips({
					title: '请填写手机号码'
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account)) return that.$util.Tips({
					title: '请输入正确的手机号码'
				});
				if (!that.captcha) return that.$util.Tips({
					title: '请填写验证码'
				});	
				if (!that.password) return that.$util.Tips({
					title: '请填写新密码'
				});	
				if (that.password != that.passwordAgain) return that.$util.Tips({
					title: '两次密码不一致'
				});
				registerReset({
						account: that.account,
						captcha: that.captcha,
						password: that.password,
						code: that.codeVal
					})
					.then(res => {
						that.$util.Tips({
							title: res.msg
						}, {
							tab: 3
						})
					})
					.catch(res => {
						that.$util.Tips({
							title: res
						})
					});
			},
			success(data) {
				console.log(data,'data');
				this.$refs.verify.hide()
				getCodeApi()
					.then(res => {
						this.keyCode = res.data.key;
						this.getCode(data);
					})
					.catch(res => {
						this.$util.Tips({
							title: res
						});
					});
			},
			code(data) {
				let that = this;
				if (!that.account) return that.$util.Tips({
					title: '请填写手机号码'
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account)) return that.$util.Tips({
					title: '请输入正确的手机号码'
				});
				this.$refs.verify.show()
			},
			async getCode(data){
				console.log('data-------',data);
				let that = this;
				await registerVerify({
						phone: that.account,
						type: that.type, 
						key: that.keyCode,
						captchaType: 'clickWord',
						captchaVerification: data.captchaVerification,
					})
					.then(res => {
						that.$util.Tips({
							title: res.msg
						});
						that.sendCode();
					})
					.catch(res => {
						that.$util.Tips({
							title: res
						});
					});
			},
		}
	};
</script>

<style lang="scss" scoped>
	.wrapper {
		background-color: #fff;
		min-height: 100vh;
		position: relative;

		.bag {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			z-index: 0;
			/* #ifdef H5 */
			z-index: 0;
	
			/* #endif */
			img {
				width: 100%;
				height: 544rpx;
			}
		}

		.page-msg {
			padding-top: 160rpx;
			margin-left: 72rpx;
			z-index: 2;
			position: relative;
			.title {
				font-size: 48rpx;
				font-weight: 500;
				color: #333333;
				line-height: 68rpx;
			}

			.tip {
				font-size: 28rpx;
				font-weight: 400;
				color: #333333;
				line-height: 40rpx;
			}
		}

		.page-form {
			width: 606rpx;
			margin: 100rpx auto 0 auto;
			z-index: 2;
			position: relative;
			.item {
				width: 100%;
				height: 88rpx;
				background: #F5F5F5;
				border-radius: 45rpx;
				padding: 24rpx 48rpx;
				margin-bottom: 32rpx;

				input {
					width: 100%;
					height: 100%;
					font-size: 32rpx;
				}

				.placeholder {
					color: #BBBBBB;
					font-size: 28rpx;
				}

				input.codeIput {
					width: 300rpx;
				}

				.line {
					width: 2rpx;
					height: 28rpx;
					background: #CCCCCC;
				}

				.code {
					font-size: 28rpx;
					color: var(--view-theme);
					background-color: rgba(255, 255, 255, 0);
				}

				.code.on {
					color: #BBBBBB !important;
				}
			}

			.btn {
				width: 606rpx;
				height: 88rpx;
				background: var(--view-theme);
				border-radius: 200rpx 200rpx 200rpx 200rpx;
				display: flex;
				justify-content: center;
				align-items: center;
				font-size: 32rpx;
				font-family: PingFang SC-Regular, PingFang SC;
				font-weight: 400;
				color: #FFFFFF;
				line-height: 44rpx;
				margin-top: 48rpx;
				letter-spacing: 1px;
			}
		}
	}

	.title-bar {
		position: relative;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 34rpx;
		font-weight: 500;
		color: #333333;
		line-height: 48rpx;
	}

	.icon {
		position: absolute;
		left: 30rpx;
		top: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		width: 80rpx;
		height: 80rpx;

		image {
			width: 35rpx;
			height: 35rpx;
		}
	}
</style>
