<template>
	<view class="wrapper" :style="colorStyle">
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
			<view class="title">{{current ? '快速登录' :'账号登录'}}</view>
			<view class="tip">首次登录会自动注册</view>
		</view>
		<view class="page-form">
			<view class="item">
				<input type='number' placeholder='请输入手机号码' placeholder-class='placeholder' v-model="account"
					:maxlength="11" :adjust-position="false"></input>
			</view>
			<view class="item acea-row row-between-wrapper" v-if="!current">
				<input type='password' placeholder='请输入密码' placeholder-class='placeholder'
					class="codeIput" v-model="password" :adjust-position="false"></input>
				<view class="line"></view>
				<navigator class="code font-num" hover-class="none" url="/pages/users/retrievePassword/index">忘记密码</navigator>
			</view>
			<view class="item acea-row row-between-wrapper" v-else>
				<input type='number' placeholder='请输入验证码' placeholder-class='placeholder' :maxlength="6"
					class="codeIput" v-model="captcha"></input>
				<view class="line">

				</view>
				<button class="code font-num" :class="disabled === true ? 'on' : ''" :disabled='disabled' @click="code">
					{{ text }}
				</button>
			</view>
			<view class="btn" @click="submitData">
				立即登录
			</view>
			<!-- #ifdef APP-PLUS -->
			<view class="appLogin" v-if="current && !appLoginStatus && !appleLoginStatus">
				<view class="hds">
					<span class="line"></span>
					<p>其他方式登录</p>
					<span class="line"></span>
				</view>
				<view class="btn-wrapper">
					<view class="btn wx" @click="wxLogin">
						<span class="iconfont icon-ic_wechat"></span>
					</view>
					<view class="btn pingguo" @click="appleLogin" v-if="appleShow">
						<view class="iconfont icon-ic_apple"></view>
					</view>
				</view>
			</view>
			<!-- #endif -->
		</view>
		<view class="text-center fs-32 text--w111-999 mt-32" @click="current = !current">{{current ? '账号登录' :'手机号登录'}}</view>
		<view class="protocol">
			<checkbox-group @click.stop='ChangeIsDefault' v-if="configData.store_user_agreement">
				<checkbox :class="inAnimation?'trembling':''" @animationend='inAnimation=false'
					:checked="protocol ? true : false" /> <text @click.stop='ChangeIsDefault'>已阅读并同意</text>
				<text class="main-color" @click.stop="privacy('user')">《用户协议》</text>
				与<text class="main-color" @click.stop="privacy('privacy')">《隐私协议》</text>
			</checkbox-group>
			<view v-else>
				登录注册即同意商城
				<text class="font-num" @tap="privacy('user')">《用户协议》</text>与<text class="font-num" @click="privacy('privacy')">《隐私协议》</text>
			</view>
		</view>
		<Verify @success="success" captchaType="clickWord" :imgSize="{ width: '330px', height: '155px' }"
			ref="verify"></Verify>
	</view>
</template>

<script>
	import dayjs from "@/plugin/dayjs/dayjs.min.js";
	import sendVerifyCode from "@/mixins/SendVerifyCode";
	import {
		loginH5,
		loginMobile,
		registerVerify,
		register,
		getCodeApi,
		getUserInfo,
		appleLogin
	} from "@/api/user";
	import attrs, {
		required,
		alpha_num,
		chs_phone
	} from "@/utils/validate";
	import {
		getLogo
	} from "@/api/public";
	import {
		VUE_APP_API_URL
	} from "@/utils";
	// #ifdef APP-PLUS
	import {
		wechatAppAuth
	} from '@/api/api.js'
	// #endif
	const BACK_URL = "login_back_url";
	import colors from '@/mixins/color.js';
	import Verify from '../components/verify/verify.vue';
	import { HTTP_REQUEST_URL } from '@/config/app';
	export default {
		name: "Login",
		components: {
			Verify
		},
		mixins: [sendVerifyCode, colors],
		data: function() {
			return {
				inAnimation: false,
				protocol: false,
				imgHost: HTTP_REQUEST_URL,
				navList: ["快速登录", "账号登录"],
				current: true,
				account: "",
				password: "",
				captcha: "",
				formItem: 1,
				type: "login",
				logoUrl: "",
				keyCode: "",
				codeUrl: "",
				codeVal: "",
				isShowCode: false,
				appLoginStatus: false, // 微信登录强制绑定手机号码状态
				appUserInfo: null, // 微信登录保存的用户信息
				appleLoginStatus: false, // 苹果登录强制绑定手机号码状态
				appleUserInfo: null,
				appleShow: false, // 苹果登录版本必须要求ios13以上的
				keyLock: true,
				copyrightContext:'',
				configData: this.$Cache.get('BASIC_CONFIG'), 
			};
		},
		watch: {
			formItem: function(nval, oVal) {
				if (nval == 1) {
					this.type = 'login'
				} else {
					this.type = 'register'
				}
			}
		},
		onLoad() {
			let self = this
			uni.getSystemInfo({
				success: (res) => {
					if (res.platform.toLowerCase() == 'ios' && this.getSystem(res.system)) {
						self.appleShow = true
					}
				}
			});
		},
		mounted: function() {
			this.getLogoImage();
		},
		methods: {
			domainTap(url){
				// #ifdef H5
				location.href = url
				// #endif
				// #ifdef MP || APP-PLUS
				uni.navigateTo({
					url: `/pages/annex/web_view/index?url=${url}`
				});
				// #endif
			},
			changeMsg() {
				this.inAnimation = true;
			},
			ChangeIsDefault(e) {
				this.$set(this, 'protocol', !this.protocol);
			},
			// IOS 版本号判断
			getSystem(system) {
				let str
				system.toLowerCase().indexOf('ios') === -1 ? str = system : str = system.split(' ')[1]
				if (str.indexOf('.'))
					return str.split('.')[0] >= 13
				return str >= 13
			},
			// 苹果登录
			appleLogin() {
				let self = this
				this.account = ''
				this.captcha = ''
				if (!self.protocol && self.configData.store_user_agreement) {
					this.inAnimation = true
					return self.$util.Tips({
						title: '请先阅读并同意协议'
					});
				}
				uni.showLoading({
					title: '登录中'
				})
				uni.login({
					provider: 'apple',
					timeout: 10000,
					success(loginRes) {
						uni.getUserInfo({
							provider: 'apple',
							success: function(infoRes) {
								self.appleUserInfo = infoRes.userInfo
								self.appleLoginApi()
							},
							fail() {
								uni.showToast({
									title: '获取用户信息失败',
									icon: 'none',
									duration: 2000
								})
							},
							complete() {
								uni.hideLoading()
							}
						});
					},
					fail(error) {
						console.log(error)
					}
				})
			},
			// 苹果登录Api
			appleLoginApi() {
				let self = this
				appleLogin({
					openId: self.appleUserInfo.openId,
					email: self.appleUserInfo.email || '',
					phone: this.account,
					captcha: this.captcha
				}).then(({
					data
				}) => {
					if (data.isbind) {
						uni.showModal({
							title: '提示',
							content: '请绑定手机号后，继续操作',
							showCancel: false,
							success: function(res) {
								if (res.confirm) {
									self.current = true
									self.appleLoginStatus = true
								}
							}
						});
					} else {
						self.$store.commit("LOGIN", {
							'token': data.token,
							'time': data.expires_time - self.$Cache.time()
						});
						let backUrl = self.$Cache.get(BACK_URL) || "/pages/index/index";
						self.$Cache.clear(BACK_URL);
						self.$store.commit("SETUID", data.userInfo.uid);
						self.$store.commit("UPDATE_USERINFO", data.userInfo);
						uni.reLaunch({
							url: backUrl
						});
					}
				}).catch(error => {
					uni.showModal({
						title: '提示',
						content: `错误信息${error}`,
						success: function(res) {
							if (res.confirm) {
								console.log('用户点击确定');
							} else if (res.cancel) {
								console.log('用户点击取消');
							}
						}
					});
				})
			},
			// App微信登录
			wxLogin() {
				if (!this.protocol && this.configData.store_user_agreement) {
					this.inAnimation = true
					return this.$util.Tips({
						title: '请先阅读并同意协议'
					});
				}
				let self = this
				this.account = ''
				this.captcha = ''
				uni.showLoading({
					title: '登录中'
				})
				uni.login({
					provider: 'weixin',
					success: function(loginRes) {
						// 获取用户信息
						uni.getUserInfo({
							provider: 'weixin',
							success: function(infoRes) {
								self.appUserInfo = infoRes.userInfo
								self.wxLoginApi()
							},
							fail() {
								uni.showToast({
									title: '获取用户信息失败',
									icon: 'none',
									duration: 2000
								})
							},
							complete() {
								uni.hideLoading()
							}
						});
					},
					fail() {
						uni.showToast({
							title: '登录失败',
							icon: 'none',
							duration: 2000
						})
					}
				});
			},
			submitData(){
				if(this.current){
					this.loginMobile()
				}else{
					this.submit()
				}
			},
			wxLoginApi() {
				let self = this
				wechatAppAuth({
					userInfo: self.appUserInfo,
					phone: this.account,
					code: this.captcha
				}).then(({
					data
				}) => {
					if (data.isbind) {
						uni.showModal({
							title: '提示',
							content: '请绑定手机号后，继续操作',
							showCancel: false,
							success: function(res) {
								if (res.confirm) {
									self.current = true
									self.appLoginStatus = true
								}
							}
						});
					} else {
						self.$store.commit("LOGIN", {
							'token': data.token,
							'time': data.expires_time - self.$Cache.time()
						});
						let backUrl = self.$Cache.get(BACK_URL) || "/pages/index/index";
						self.$Cache.clear(BACK_URL);
						self.$store.commit("SETUID", data.userInfo.uid);
						self.$store.commit("UPDATE_USERINFO", data.userInfo);
						uni.reLaunch({
							url: backUrl
						});
					}
				}).catch(error => {
					uni.showModal({
						title: '提示',
						content: `错误信息${error}`,
						success: function(res) {
							if (res.confirm) {
								console.log('用户点击确定');
							} else if (res.cancel) {
								console.log('用户点击取消');
							}
						}
					});
				})
			},
			again() {
				this.codeUrl =
					VUE_APP_API_URL +
					"/sms_captcha?" +
					"key=" +
					this.keyCode +
					Date.parse(new Date());
			},
			success(data) {
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
			code() {
				let that = this
				if (!that.protocol) {
					this.inAnimation = true
					return that.$util.Tips({
						title: '请先阅读并同意协议'
					});
				}
				if (!that.account) return that.$util.Tips({
					title: '请填写手机号码'
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account)) return that.$util.Tips({
					title: '请输入正确的手机号码'
				});
				this.$refs.verify.show()
			},
			async getLogoImage() {
				let that = this;
				getLogo(2).then(res => {
					that.logoUrl = res.data.logo_url;
					that.copyrightContext = res.data.copyrightContext;
				});
			},
			async loginMobile() {
				let that = this;
				if (!that.protocol && that.configData.store_user_agreement) {
					this.inAnimation = true
					return that.$util.Tips({
						title: '请先阅读并同意协议'
					});
				}
				if (!that.account) return that.$util.Tips({
					title: '请填写手机号码'
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account)) return that.$util.Tips({
					title: '请输入正确的手机号码'
				});
				if (!that.captcha) return that.$util.Tips({
					title: '请填写验证码'
				});
				if (!/^[\w\d]+$/i.test(that.captcha)) return that.$util.Tips({
					title: '请输入正确的验证码'
				});
				if (that.appLoginStatus) {
					that.wxLoginApi()
				} else if (that.appleLoginStatus) {
					that.appleLoginApi()
				} else {
					if (this.keyLock) {
						this.keyLock = !this.keyLock
					} else {
						return that.$util.Tips({
							title: '请勿重复点击'
						});
					}
					loginMobile({
							phone: that.account,
							captcha: that.captcha,
							spread_spid: that.$Cache.get("spid")
						})
						.then(res => {
							let data = res.data;
							that.$store.commit("LOGIN", {
								'token': data.token,
								'time': data.expires_time - this.$Cache.time()
							});
							let backUrl = that.$Cache.get(BACK_URL) || "/pages/index/index";
							that.$Cache.clear(BACK_URL);
							getUserInfo().then(res => {
								this.keyLock = true
								that.$store.commit("SETUID", res.data.uid);
								that.$store.commit("UPDATE_USERINFO", res.data);
								if (backUrl.indexOf('/pages/users/login/index') !== -1) {
									backUrl = '/pages/index/index';
								}
								uni.reLaunch({
									url: backUrl
								});
							})
						})
							.catch(res => {
								this.keyLock = true
								that.$util.Tips({
									title: (res && res.msg) || res
								});
						});
				}
	
			},
			async register() {
				let that = this;
				if (!that.account) return that.$util.Tips({
					title: '请填写手机号码'
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account)) return that.$util.Tips({
					title: '请输入正确的手机号码'
				});
				if (!that.captcha) return that.$util.Tips({
					title: '请填写验证码'
				});
				if (!/^[\w\d]+$/i.test(that.captcha)) return that.$util.Tips({
					title: '请输入正确的验证码'
				});
				if (!that.password) return that.$util.Tips({
					title: '请填写密码'
				});
				if (/^([0-9]|[a-z]|[A-Z]){0,6}$/i.test(that.password)) return that.$util.Tips({
					title: '您输入的密码过于简单'
				});
				register({
						account: that.account,
						captcha: that.captcha,
						password: that.password,
						spread_spid: that.$Cache.get("spid")
					})
					.then(res => {
						that.$util.Tips({
							title: res
						});
						that.formItem = 1;
					})
					.catch(res => {
						that.$util.Tips({
							title: res
						});
					});
			},
			async getCode(data){
				console.log('data-------',data);
				let that = this;
				if (!that.account) return that.$util.Tips({
					title: '请填写手机号码'
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account)) return that.$util.Tips({
					title: '请输入正确的手机号码'
				});
				if (that.formItem == 2) that.type = "register";
	
				await registerVerify({
						phone: that.account,
						type: that.type,
						key: that.keyCode,
						captchaType: 'clickWord',
						captchaVerification: data.captchaVerification
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
			async submit() {
				let that = this;
				if (!that.protocol && that.configData.store_user_agreement) {
					this.inAnimation = true
					return that.$util.Tips({ title: '请先阅读并同意协议' });
				}
				if (!that.account) return that.$util.Tips({
					title: '请填写账号'
				});
				if (!/^[\w\d]{5,16}$/i.test(that.account)) return that.$util.Tips({
					title: '请输入正确的账号'
				});
				if (!that.password) return that.$util.Tips({
					title: '请填写密码'
				});
				if (this.keyLock) {
					this.keyLock = !this.keyLock
				} else {
					return that.$util.Tips({
						title: '请勿重复点击'
					});
				}
				loginH5({ account: that.account, password: that.password, spread_spid: that.$Cache.get('spid') })
					.then(({ data }) => {
						that.$store.commit('LOGIN', { token: data.token, time: data.expires_time - this.$Cache.time() });
						let backUrl = that.$Cache.get(BACK_URL) || '/pages/index/index';
						that.$Cache.clear(BACK_URL);
						getUserInfo().then(res => {
							this.keyLock = true;
							that.$store.commit('SETUID', res.data.uid);
							that.$store.commit('UPDATE_USERINFO', res.data);
							uni.reLaunch({ url: backUrl });
						}).catch(() => { this.keyLock = true; });
					})
					.catch(e => {
						this.keyLock = true;
						that.$util.Tips({ title: (e && e.msg) || e });
					});
			},
			privacy(type) {
				uni.navigateTo({
					url: "/pages/users/privacy/index?type=" + type
				})
			}
		}
	};
</script>

<style lang="scss" scoped>
	.wrapper {
		background-color: #f5f7f8;
		min-height: 100vh;
		position: relative;
		box-sizing: border-box;
		padding: 72rpx 40rpx 96rpx;

		.page-msg {
			padding: 38rpx 8rpx 44rpx;

			.title {
				font-size: 52rpx;
				font-weight: 700;
				color: #162128;
				line-height: 1.2;
			}

			.tip {
				margin-top: 14rpx;
				font-size: 28rpx;
				font-weight: 400;
				color: #66747d;
				line-height: 42rpx;
			}
		}

		.page-form {
			width: 100%;
			box-sizing: border-box;
			padding: 40rpx;
			background: #ffffff;
			border: 1rpx solid #e1e6e9;
			border-radius: 8rpx;
			box-shadow: 0 10rpx 26rpx rgba(31, 46, 56, 0.06);
			.item {
				width: 100%;
				height: 104rpx;
				box-sizing: border-box;
				background: #ffffff;
				border: 1rpx solid #cbd4d9;
				border-radius: 6rpx;
				padding: 0 28rpx;
				margin-bottom: 24rpx;
				display: flex;
				align-items: center;

				&:focus-within {
					border-color: #e3317a;
					box-shadow: 0 0 0 4rpx rgba(227, 49, 122, 0.12);
				}

				input {
					width: 100%;
					height: 44rpx;
					box-sizing: border-box;
					border: 0;
					outline: 0;
					background: transparent;
					-webkit-appearance: none;
					font-size: 28rpx;
					line-height: 44rpx;
					color: #162128;
				}

				.placeholder {
					color: #9aa6ad;
					font-size: 28rpx;
				}

				input.codeIput {
					width: 100%;
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
				width: 100%;
				height: 96rpx;
				background: var(--view-theme);
				border-radius: 6rpx;
				display: flex;
				justify-content: center;
				align-items: center;
				font-size: 30rpx;
				font-family: PingFang SC-Regular, PingFang SC;
				font-weight: 400;
				color: #FFFFFF;
				line-height: 44rpx;
				margin-top: 12rpx;
				letter-spacing: 0;
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

	.protocol {
		position: fixed;
		bottom: 52rpx;
		left: 0;
		width: 100%;
		margin: 0 auto;
		color: #999999;
		font-size: 24rpx;
		line-height: 22rpx;
		text-align: center;
		bottom: calc(52rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		bottom: calc(52rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/

		.main-color {
			color: var(--view-theme);
		}

		.trembling {
			animation: shake 0.6s;
		}
	}

	::v-deep  uni-checkbox .uni-checkbox-input {
		width: 28rpx;
		height: 28rpx;
	}

	::v-deep  uni-checkbox .uni-checkbox-input.uni-checkbox-input-checked::before {
		font-size: 24rpx;
	}

	::v-deep  uni-checkbox .uni-checkbox-wrapper {
		margin-bottom: 1px;
	}

	/*checkbox 选项框大小  */
	::v-deep  checkbox .wx-checkbox-input {
		width: 28rpx;
		height: 28rpx;
	}

	/*checkbox选中后样式  */
	::v-deep  checkbox .wx-checkbox-input.wx-checkbox-input-checked {
		background: white;
	}

	/*checkbox选中后图标样式  */
	::v-deep  checkbox .wx-checkbox-input.wx-checkbox-input-checked::before {
		width: 28rpx;
		height: 28rpx;
		line-height: 28rpx;
		text-align: center;
		font-size: 22rpx;
		background: transparent;
		transform: translate(-50%, -50%) scale(1);
		-webkit-transform: translate(-50%, -50%) scale(1);
	}
	.appLogin {
		margin-top: 60rpx;
	
		.hds {
			display: flex;
			justify-content: center;
			align-items: center;
			font-size: 24rpx;
			color: #B4B4B4;
	
			.line {
				width: 68rpx;
				height: 1rpx;
				background: #CCCCCC;
			}
	
			p {
				margin: 0 20rpx;
			}
		}
	
		.btn-wrapper {
			display: flex;
			align-items: center;
			justify-content: center;
			margin-top: 30rpx;
	
			.btn {
				display: flex;
				align-items: center;
				justify-content: center;
				width: 68rpx;
				height: 68rpx;
				border-radius: 50%;
			}
	
			.apple-btn {
				display: flex;
				align-items: center;
				justify-content: center;
				width: 246rpx;
				height: 66rpx;
				margin-left: 30rpx;
				background: #EAEAEA;
				border-radius: 34rpx;
				font-size: 24rpx;
	
				.icon-s-pingguo {
					color: #333;
					margin-right: 10rpx;
					font-size: 34rpx;
				}
			}
	
			.iconfont {
				font-size: 40rpx;
				color: #fff;
			}
	
			.wx {
				background-color: #61C64F;
			}
	
			.mima {
				background-color: #28B3E9;
			}
	
			.yanzheng {
				background-color: #F89C23;
			}
	
			.pingguo {
				margin-left: 60rpx;
				background-color: #000;
			}
	
		}
	}
</style>
