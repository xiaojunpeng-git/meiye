<template>
	<view :style="colorStyle">
		<view class='distribution-posters'>
			<swiper :indicator-dots="indicatorDots" :autoplay="autoplay" :circular="circular" :interval="interval"
				:duration="duration" @change="bindchange" previous-margin="40px" next-margin="40px">
				<block v-for="(item,index) in spreadData" :key="index" class="img-list">
					<swiper-item class="aaa">
						<div class="box" ref="bill" :class="swiperIndex == index ? 'active' : 'quiet'">
							<view class="user-msg">
								<view class="user-code">
									<image class="canvas" :style="{height:hg+'px'}" :src="posterImage[index]"
										v-if="posterImage[index]"></image>
									<canvas class="canvas" :style="{height:hg+'px',width:wd+'px'}" :canvas-id="'myCanvas'+ index"
										v-else></canvas>
								</view>
							</view>
						</div>
					</swiper-item>
				</block>
			</swiper>
			<!-- #ifndef H5 -->
			<view class="picList acea-row row-center-wrapper">
				<view class="item" @click='savePosterPathMp(posterImage[swiperIndex])'>
					<view class="pictrue">
						<image src="../static/haibao.png"></image>
					</view>
					<view>保存海报</view>
				</view>
				<!-- #ifdef APP-PLUS -->
				<view class="item" @click="appShare('WXSceneSession')">
					<view class="pictrue">
						<image src="../static/weixin.png"></image>
					</view>
					<view>微信好友</view>
				</view>
				<!-- #endif -->
				<!-- #ifdef MP -->
				<button class="item" open-type="share" hover-class="none">
					<view class="pictrue">
						<image src="../static/weixin.png"></image>
					</view>
					<view>微信好友</view>
				</button>
				<!-- #endif -->
			</view>
			<!-- #endif -->
		</view>
		<view class="qrimg">
			<zb-code ref="qrcode" :show="codeShow" :cid="cid" :val="val" :size="size" :unit="unit"
				:background="background" :foreground="foreground" :pdground="pdground" :icon="icon" :iconSize="iconsize"
				:onval="onval" :loadMake="loadMake" @result="qrR" />
		</view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import zbCode from '@/components/zb-code/zb-code.vue'
	import {
		getUserInfo,
		spreadBanner,
		userShare,
		routineCode,
		spreadMsg,
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import home from '@/components/home';
	import {
		TOKENNAME,
		HTTP_REQUEST_URL
	} from '@/config/app.js';
	import colors from '@/mixins/color.js';
	export default {
		components: {
			home,
			zbCode
		},
		mixins:[colors],
		data() {
			return {
				imgUrls: [],
				indicatorDots: false,
				posterImageStatus: true,
				circular: false,
				autoplay: false,
				interval: 3000,
				duration: 500,
				swiperIndex: 0,
				spreadList: [],
				userInfo: {},
				poster: '',
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				spreadData: [{}], //新海报数据
				nickName: "",
				siteName: "",
				mpUrl: "",
				canvasImageUrl: '',
				posterImage: [],
				//二维码参数
				codeShow: false,
				cid: '1',
				ifShow: true,
				val: "", // 要生成的二维码值
				size: 200, // 二维码大小
				unit: 'upx', // 单位
				background: '#FFF', // 背景色
				foreground: '#000', // 前景色
				pdground: '#000', // 角标色
				icon: '', // 二维码图标
				iconsize: 40, // 二维码图标大小
				lv: 3, // 二维码容错级别 ， 一般不用设置，默认就行
				onval: true, // val值变化时自动重新生成二维码
				loadMake: true, // 组件加载完成后自动生成二维码
				src: '', // 二维码生成后的图片地址或base64
				codeSrc: "",
				wd: 305,
				hg: 473,
				qrcode: "",
        homeHide: false
			};
		},
		computed: mapGetters({
			'isLogin': 'isLogin',
			'userData': 'userInfo',
			'uid': 'uid'
		}),
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						this.userSpreadBannerList();
					}
				},
				deep: true
			},
			userData: {
				handler: function(newV, oldV) {
					if (newV) {
						this.$set(this, 'userInfo', newV);
					}
				},
				deep: true
			}
		},
		async onReady() {
			if (this.isLogin) {
				this.val = `${HTTP_REQUEST_URL}?spid=${this.uid}`
				// #ifdef MP
				await this.spreadMsg()
				// #endif
				this.getUser();
			} else {
				this.getIsLogin();
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			this.$nextTick(() => {
				let selector = uni.createSelectorQuery().select('.aaa');
				selector.fields({
					size: true
				}, data => {
					console.log(data)
					this.wd = data.width
					// this.hg = data.height
				}).exec();
			})
		},
		/**
		 * 用户点击右上角分享
		 */
		// #ifdef MP
		onShareAppMessage() {
			return {
				title: this.userInfo.nickname + '-分销海报',
				imageUrl: this.spreadList[0],
				path: '/pages/index/index?spid=' + this.userInfo.uid,
			};
		},
		// #endif
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			getIsLogin(){
				toLogin()
			},
			onLoadFun(){
				this.spreadMsg();
				this.getUser();
				this.userSpreadBannerList();
				this.isShowAuth = false;
			},
			getUser(){
				getUserInfo().then(res=>{
					this.userInfo = res.data
				})
			},
			async qrR(res) {
				this.codeSrc = await res
				// #ifdef H5 || APP-PLUS
				this.spreadMsg()
				// #endif
			},
			//获取图片
			async spreadMsg() {
				let res = await spreadMsg();
				this.spreadData = res.data.spread;
				this.nickName = res.data.nickname;
				this.siteName = res.data.site_name;
				uni.showLoading({
					title: '海报生成中',
					mask: true
				});
				const avatar = await this.downloadFilestoreImage(res.data.avatar);
				const followCode = res.data.qrcode ? await this.downloadFilestoreImage(res.data.qrcode) : '';
				for (let i = 0; i < res.data.spread.length; i++) {
					let img = await this.downloadFilestoreImage(res.data.spread[i].pic);
					let arr = [];
					// #ifdef H5
					arr = [followCode || this.codeSrc, img, avatar];
					// #endif
					// #ifdef MP
					await this.routineCode();
					arr = [this.mpUrl, img, avatar];
					// #endif
					// #ifdef APP-PLUS
					arr = [this.codeSrc, img, avatar];
					// #endif
					this.$nextTick(() => {
						this.$util.userPosterCanvas(arr, res.data.nickname, res.data.site_name, i, this.wd, this.hg, (
							tempFilePath) => {
							this.$set(this.posterImage, i, tempFilePath);
							// #ifdef MP
							if (!this.posterImage.length) {
								return this.$util.Tips({
									title: '小程序二维码需要发布正式版后才能获取到'
								});
							}
							// #endif
						});
					});
				}
				uni.hideLoading();
			},
			// #ifdef MP
			async routineCode() {
				let res = await routineCode()
				this.mpUrl = await this.downloadFilestoreImage(res.data.url);
			},
			// #endif
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			bindchange(e) {
				let spreadList = this.spreadList;
				this.swiperIndex = e.detail.current;
			},
			// #ifdef MP
			savePosterPathMp(url) {
				let that = this;
				uni.getSetting({
					success(res) {
						if (!res.authSetting['scope.writePhotosAlbum']) {
							uni.authorize({
								scope: 'scope.writePhotosAlbum',
								success() {
									uni.saveImageToPhotosAlbum({
										filePath: url,
										success: function(res) {
											that.$util.Tips({
												title: '保存成功',
												icon: 'success'
											});
										},
										fail: function(res) {
											that.$util.Tips({
												title: '保存失败'
											});
										}
									});
								}
							});
						} else {
							uni.saveImageToPhotosAlbum({
								filePath: url,
								success: function(res) {
									that.$util.Tips({
										title: '保存成功',
										icon: 'success'
									});
								},
								fail: function(res) {
									that.$util.Tips({
										title: '保存失败'
									});
								}
							});
						}
					}
				});
			},
			// #endif
			// #ifdef APP-PLUS
			savePosterPathMp(url) {
				let that = this;
				uni.saveImageToPhotosAlbum({
					filePath: url,
					success: function(res) {
						that.$util.Tips({
							title: '保存成功',
							icon: 'success'
						});
					},
					fail: function(res) {
						that.$util.Tips({
							title: '保存失败'
						});
					}
				});
			},
			appShare(scene) {
				let that = this
				uni.share({
					provider: "weixin",
					scene: scene,
					type: 0,
					href: HTTP_REQUEST_URL+'/pages/index/index?spid=' + this.userInfo.uid,
					title: this.userInfo.nickname + '-分销海报',
					imageUrl: this.spreadList[0],
					success: function(res) {},
					fail: function(err) {
						uni.showToast({
							title: '分享失败',
							icon: 'none',
							duration: 2000
						})
					}
				});
			},
			// #endif
			//替换安全域名
			setDomain: function(url) {
				url = url ? url.toString() : '';
				//本地调试打开,生产请注销
				if (url.indexOf('https://') > -1) return url;
				else return url.replace('http://', 'https://');
			},
			//图片转符合安全域名路径
			downloadFilestoreImage(url) {
				return new Promise((resolve, reject) => {
					let that = this;
					uni.downloadFile({
						url: that.setDomain(url),
						success: function(res) {
							resolve(res.tempFilePath);
						},
						fail: function() {
							return that.$util.Tips({
								title: ''
							});
						}
					});
				})
			},
			setShareInfoStatus: function() {
				if (this.$wechat.isWeixin()) {
					if (this.isLogin) {
						getUserInfo().then(res => {
							let configAppMessage = {
								desc: '分销海报',
								title: res.data.nickname + '-分销海报',
								link: '/pages/index/index?spid=' + res.data.uid,
								imgUrl: this.spreadList[0]
							};
							this.$wechat.wechatEvevt(["updateAppMessageShareData", "updateTimelineShareData"],
								configAppMessage)
						});
					} else {
						this.getIsLogin();
					}

				}
			},
			userSpreadBannerList: function() {
				let that = this;
				uni.showLoading({
					title: '获取中',
					mask: true,
				})
				spreadBanner().then(res => {
					uni.hideLoading();
					that.$set(that, 'spreadList', res.data);
					that.$set(that, 'poster', res.data[0].poster);
					// #ifdef H5
					that.setShareInfoStatus();
					// #endif
				}).catch(err => {
					uni.hideLoading();
				});
			},
      onhomehide() {
				this.homeHide = false;
			}
		}
	}
</script>

<style lang="scss">
	page {
		background-color: #F5F5F5 !important;
	}

	.canvas {
		width: 100%;
	}
	
	.picList{
		margin-top: 30rpx;
		.item{
			font-size: 26rpx;
			color: #666666;
			margin: 0 71rpx;
			background-color: #f5f5f5;
			.pictrue{
				width: 96rpx;
				height: 96rpx;
				margin-bottom: 24rpx;
				image {
					width: 100%;
					height: 100%;
				}
			}
		}
	}

	.box {
		width: 100%;
		height: 100%;
		position: relative;
		border-radius: 32rpx;
		overflow: hidden;

		.user-msg {
			position: absolute;
			width: 100%;
			height: 100%;
			display: flex;
			align-items: center;
			justify-content: center;

			.user-code {
				width: 100%;
				display: flex;
				align-items: center;
				justify-content: center;
				justify-content: space-between;

				image {
					width: 100%;
					border-radius: 16px;
				}
			}
		}
	}

	.img-list {
		margin-right: 40px;
	}

	.distribution-posters swiper {
		width: 100%;
		height: 1000rpx;
		position: relative;
		margin-top: 40rpx;
	}

	.distribution-posters .slide-image {
		width: 100%;
		height: 100%;
		margin: 0 auto;
		border-radius: 15rpx;
	}

	.distribution-posters /deep/.active {
		transform: none;
		transition: all 0.2s ease-in 0s;
	}

	.distribution-posters /deep/ .quiet {
		transform: scale(0.89);
		transition: all 0.2s ease-in 0s;
	}

	.distribution-posters .keep {
		font-size: 30rpx;
		color: #fff;
		width: 600rpx;
		height: 80rpx;
		border-radius: 50rpx;
		text-align: center;
		line-height: 80rpx;
		margin: 38rpx auto;
	}

	.distribution-posters .preserve {
		color: #fff;
		text-align: center;
		margin-top: 38rpx;
	}

	.distribution-posters .preserve .line {
		width: 100rpx;
		height: 1px;
		background-color: #fff;
	}

	.distribution-posters .preserve .tip {
		margin: 0 30rpx;
	}
</style>
