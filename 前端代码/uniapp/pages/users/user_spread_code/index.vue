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
	import { resolveServerAssetUrl } from '@/utils/memberAssetUrl.js';
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
				userInfo: {},
				poster: '',
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				spreadData: [], // Only configured templates create canvas nodes.
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
						if (this._pageReady) this.spreadMsg();
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
		onReady() {
			this._pageReady = true;
			if (this.isLogin) this.onLoadFun();
			else this.getIsLogin();
		},
		onUnload() {
			// Reject pending generation and invalidate late native callbacks.
			this._pageReady = false;
			if (this._cancelSpread) this._cancelSpread(new Error('海报生成已取消'));
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		/**
		 * 用户点击右上角分享
		 */
		// #ifdef MP
		onShareAppMessage() {
			return {
				title: this.userInfo.nickname + '-分销海报',
				imageUrl: (this.spreadData[this.swiperIndex] || {}).pic,
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
			onLoadFun() {
				this.val = `${HTTP_REQUEST_URL}?spid=${this.uid}`;
				this.getUser();
				// Native mini-program QR is authoritative; H5/app wait for qrR.
				// #ifdef MP
				this.spreadMsg();
				// #endif
				this.isShowAuth = false;
			},
			getUser() {
				getUserInfo().then(res => { this.userInfo = res.data; }).catch(() => {});
			},
			qrR(res) {
				this.codeSrc = res;
				// #ifdef H5 || APP-PLUS
				if (this.isLogin) this.spreadMsg();
				// #endif
			},
			// One generation owns its templates, loading indicator and native draw.
			// Failure or timeout always settles; leaving the page forbids late writes.
			spreadMsg() {
				if (this._spreadPromise) return this._spreadPromise;
				let timer;
				const ensureActive = () => {
					if (this._cancelSpread !== cancel) throw new Error('海报生成已取消');
				};
				let cancel;
				const deadline = new Promise((resolve, reject) => {
					cancel = reject;
					timer = setTimeout(() => reject(new Error('海报生成超时，请重试')), 20000);
				});
				this._cancelSpread = cancel;
				uni.showLoading({ title: '海报生成中', mask: false });
				const generate = async () => {
					const { data } = await spreadMsg();
					ensureActive();
					if (!data || !Array.isArray(data.spread) || !data.spread.length) throw new Error('暂未配置分享海报');
					this.spreadData = data.spread;
					this.posterImage = [];
					this.swiperIndex = Math.min(this.swiperIndex, data.spread.length - 1);
					this.nickName = data.nickname;
					this.siteName = data.site_name;
					// #ifdef H5
					this.setShareInfoStatus();
					// #endif
					await new Promise(resolve => this.$nextTick(resolve));
					// Measure after the asynchronous template list creates swiper items.
					await new Promise(resolve => uni.createSelectorQuery().in(this).select('.aaa').fields({ size: true }, rect => {
						if (rect && rect.width > 0) this.wd = rect.width;
						resolve();
					}).exec());
					ensureActive();
					const avatar = await this.downloadFilestoreImage(data.avatar || '/static/images/def_avatar.png');
					let code = data.qrcode || this.codeSrc;
					// Fetch mini-program QR once per generation, never a web QR substitute.
					// #ifdef MP
					const response = await routineCode();
					code = response.data && response.data.url;
					// #endif
					if (!code) throw new Error('海报二维码暂不可用，请稍后重试');
					const qr = await this.downloadFilestoreImage(code);
					for (let i = 0; i < data.spread.length; i++) {
						ensureActive();
						const image = await this.downloadFilestoreImage(data.spread[i].pic);
						ensureActive();
						const path = await new Promise((resolve, reject) => this.$util.userPosterCanvas(
							[qr, image, avatar], data.nickname, data.site_name, i, this.wd, this.hg, resolve, reject, this));
						ensureActive();
						this.$set(this.posterImage, i, path);
					}
					return this.posterImage;
				};
				this._spreadPromise = Promise.race([generate(), deadline]).catch(error => {
					if (this._pageReady) this.$util.Tips({ title: error.message || '海报生成失败，请重试' });
					return [];
				}).finally(() => {
					clearTimeout(timer);
					this._cancelSpread = null;
					this._spreadPromise = null;
					uni.hideLoading();
				});
				return this._spreadPromise;
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			bindchange(e) {
				this.swiperIndex = e.detail.current;
			},
			// Save waits for the same generation; no empty-path native call or
			// silent authorization failure may leave the action without feedback.
			savePosterPathMp: async function() {
				if (!this.posterImage[this.swiperIndex]) await this.spreadMsg();
				const path = this.posterImage[this.swiperIndex];
				if (!this._pageReady || !path) return;
				uni.saveImageToPhotosAlbum({
					filePath: path,
					success: () => this.$util.Tips({ title: '保存成功', icon: 'success' }),
					fail: error => {
						if (/auth|denied|deny/i.test(error.errMsg || '')) {
							uni.showModal({ title: '需要相册权限', content: '请在设置中允许保存到相册。', confirmText: '去设置',
								success: result => { if (result.confirm) uni.openSetting({}); } });
						} else this.$util.Tips({ title: '保存失败，请重试' });
					}
				});
			},
			// #ifdef APP-PLUS
			appShare(scene) {
				let that = this
				uni.share({
					provider: "weixin",
					scene: scene,
					type: 0,
					href: HTTP_REQUEST_URL+'/pages/index/index?spid=' + this.userInfo.uid,
					title: this.userInfo.nickname + '-分销海报',
					imageUrl: (this.spreadData[this.swiperIndex] || {}).pic,
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
			// H5 retains WeChat's home referral metadata independently of canvas.
			// #ifdef H5
			setShareInfoStatus() {
				if (this.$wechat.isWeixin() && this.isLogin) {
					this.$wechat.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData'], {
						desc: '分销海报', title: this.nickName + '-分销海报',
						link: '/pages/index/index?spid=' + this.uid,
						imgUrl: (this.spreadData[0] || {}).pic
					});
				}
			},
			// #endif
			// Imported server media, bundled assets and native temp paths retain
			// their distinct origins; image failures must reject generation.
			setDomain(url) {
				url = resolveServerAssetUrl(url ? String(url) : '', HTTP_REQUEST_URL);
				if (/^http:\/\/(?:127\.0\.0\.1|localhost)(?::\d+)?(?:\/|$)/i.test(url)) return url;
				return url.replace(/^http:\/\//, 'https://');
			},
			//图片转符合安全域名路径
			downloadFilestoreImage(url) {
				// getImageInfo accepts both packaged fallback avatars and HTTPS assets;
				// every failure must reject so the loading state cannot hang forever.
				if (!url) return Promise.reject(new Error('海报图片地址为空'));
				return new Promise((resolve, reject) => uni.getImageInfo({
					src: this.setDomain(url),
					success: result => {
						if (!result.path) return reject(new Error('海报图片下载失败'));
						// WeChat strips the leading slash from packaged image paths.
						// The canvas performs another image read from this nested page,
						// so restore app-root paths while preserving native temp files.
						const path = result.path;
						resolve(/^(?:pages|static)\//.test(path) ? '/' + path : path);
					},
					fail: () => reject(new Error('海报图片加载失败，请稍后重试'))
				}));
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

	.distribution-posters ::v-deep .active {
		transform: none;
		transition: all 0.2s ease-in 0s;
	}

	.distribution-posters ::v-deep  .quiet {
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
