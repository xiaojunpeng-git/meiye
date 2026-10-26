<template>
	<scroll-view class="scroll-view" scroll-y="true" @scroll="scrollView">
		<!-- #ifdef MP -->
		<NavBar titleText="会员码" :iconColor="iconColor" :textColor="iconColor" showBack :isScrolling="isScrolling">
		</NavBar>
		<!-- #endif -->
		<view class="wrapper">
			<view class="wrapper-content">
				<view class="header acea-row row-column row-center" :style="[headerStyle]" v-if="userInfo.member_func_status">
					<template v-if="levelInfo.id">
						<view class="title" :style="{color: colorToRgba(levelInfo.color||'', 0.8)}">尊敬的{{levelInfo.name}}会员</view>
						<view class="progress-wrap acea-row row-center-wrapper" :style="{color: colorToRgba(levelInfo.color||'', 0.8)}" v-if="nextLevel.id">
							<view>V{{levelInfo.grade}}</view>
							<view class="progress">
								<view class="inner" :style="{
									width: `${100 - nextLevel.lack_exp_num / nextLevel.exp_num * 100}%`,
									background: colorToRgba(levelInfo.color||'', 0.8),
								}"></view>
							</view>
							<view>V{{nextLevel.grade}}</view>
						</view>
						<view class="info acea-row row-center-wrapper" :style="{color: levelInfo.color}">
							<template v-if="nextLevel.id">
								还需
								<text class="number" :style="{color: colorToRgba(levelInfo.color||'', 0.8)}">{{nextLevel.lack_exp_num}}</text>
								经验到下一级
							</template>
							<template v-else>
								您已经是最高等级
							</template>
						</view>
					</template>
					<template v-else>
						<view class="title">尊敬的{{userInfo.nickname}}</view>
						<view v-if="userInfo.level_status" class="btn">还需{{nextLevel.lack_exp_num}}经验到下一级</view>
						<navigator v-else url="/pages/annex/vip_grade_active/index" hover-class="none" class="btn">
							免费激活等级，享更多权益<text class="iconfont icon-ic_rightarrow"></text>
						</navigator>
					</template>
				</view>
				<view class="content relative">
					<!-- #ifdef H5 || MP -->
					<view v-if="toggleButtonShow" class="flex-center pr-16 pl-16 abs-rt top-44 fs-24 lh-50rpx toggle-btn" @click="toggleQRCode">
						<text class="iconfont icon-ic_switch mr-8 fs-26"></text>
						{{ isPay ? '会员码' : '付款码' }}
					</view>
					<!-- #endif -->
					<view class="acea-row row-center-wrapper h-380" style="margin-top: 56rpx;">
						<!-- #ifdef H5 -->
						<w-qrcode v-if="!isWeixin" :options="config.qrc"></w-qrcode>
						<view v-if="isWeixin">
							<view v-if="!isPay">
								<image :src="qrc" class="qrcode"></image>
							</view>
							<view v-if="isPay">
								<w-qrcode :options="config.qrc"></w-qrcode>
							</view>
						</view>
						<!-- #endif -->
						<!-- #ifdef MP -->
						<w-qrcode v-show="isPay" :options="config.qrc"></w-qrcode>
						<image v-show="!isPay" :src="qrc" class="qrcode"></image>
						<!-- #endif -->
						<!-- #ifdef APP-PLUS -->
						<w-qrcode :options="config.qrc"></w-qrcode>
						<!-- #endif -->
					</view>
					<view class="codeNum acea-row row-center-wrapper">{{config.qrc.code}}</view>
					<view class="balance-wrapper">
						<view class="balance acea-row row-column row-center-wrapper">
							<view class="">当前余额<text class="iconfont" :class="[isEye?'icon-ic_Eyes':'icon-ic_eye']" @click="toggleEye"></text></view>
							<view class="number" v-if="isEye">{{userInfo.now_money}}</view>
							<view class="number" v-else style="font-weight: 500;font-size: 48rpx;">******</view>
						</view>
						<view class="attribute acea-row">
							<view class="flex-1 min-w-0" @click="goDetail(1)">
								<view class="iconfont icon-ic_coupon"></view>
								<view class="">{{userInfo.couponCount}}张券</view>
							</view>
							<view class="flex-1 min-w-0" @click="goDetail(2)">
								<view class="iconfont icon-ic_gold1"></view>
								<view class="">{{userInfo.integral}}积分</view>
							</view>
							<view class="flex-1 min-w-0" @click="goDetail(3)" v-if="userInfo.member_func_status">
								<view class="iconfont icon-ic_sale"></view>
								<view v-if="levelInfo.grade" class="">{{userInfo.vip_discount}}折</view>
								<view v-else>暂无折扣</view>
							</view>
						</view>
					</view>
				</view>
			</view>
		</view>
	</scroll-view>
</template>

<script>
	// #ifdef MP
	import NavBar from '@/components/NavBar.vue'
	// #endif
	import {
		getUserInfo,
		getlevelInfo,
	} from '@/api/user.js';
	import {
		activityCodeApi
	} from '@/api/activity.js';
	export default {
		components: {
			// #ifdef MP
			NavBar,
			// #endif
		},
		data() {
			return {
				iconColor: '#FFFFFF',
				isScrolling: false,
				userInfo: {},
				levelList: [],
				nextLevel: {},
				levelInfo: {},
				isEye: true,
				config: {
					qrc: {
						code: "",
						size: 360, // 二维码大小
						level: 4, //等级 0～4
						bgColor: '#FFFFFF', //二维码背景色 默认白色
						color: ['#333', '#333'], //边框颜色支持渐变色
					}
				},
				qrc: '',
				isPay: false,
				isWeixin: false,
				toggleButtonShow: false,
			}
		},
		computed: {
			headerStyle() {
				let styleObject = {};
				if (this.levelInfo.id) {
					if (this.nextLevel.id) {
						styleObject['background'] =
							`linear-gradient(91deg, ${this.colorToRgba(this.levelInfo.color||'', 0.05)} 0%, ${this.colorToRgba(this.levelInfo.color||'', 0.4)} 100%)`;
					} else {
						styleObject['background'] = 'linear-gradient(91deg, rgba(26,25,23,0.9) 0%, #1A1917 100%)';
					}
				}
				return styleObject;
			},
		},
		onLoad() {
			// #ifdef H5
			this.isWeixin = this.$wechat.isWeixin();
			this.toggleButtonShow = this.isWeixin;
			// #endif
			// #ifdef MP
			this.toggleButtonShow = true;
			// #endif
		},
		onShow() {
			this.getUserInfo();
			this.activityCodeApi();
		},
		methods: {
			activityCodeApi() {
				activityCodeApi(90, 0).then(res => {
					const {
						routineUrl,
						wechatUrl
					} = res.data;
					// #ifdef MP
					this.qrc = routineUrl;
					// #endif
					// #ifdef H5
					if (this.isWeixin) {
						this.qrc = wechatUrl;
					}
					// #endif
				});
			},
			goDetail(val) {
				if (val == 1) {
					uni.navigateTo({
						url: '/pages/users/user_coupon/index'
					})
				} else if (val == 2) {
					uni.navigateTo({
						url: '/pages/users/user_integral/index'
					})
				} else if (val == 3) {
					if (this.levelInfo.grade) {
						uni.navigateTo({
							url: '/pages/users/user_vip/index'
						})
					} else {
						uni.navigateTo({
							url: '/pages/annex/vip_grade_active/index'
						})
					}
				}
			},
			scrollView(e) {
				// #ifdef MP
				if (e.detail.scrollTop > 50) {
					this.isScrolling = true;
					this.iconColor = '#333333';
				} else if (e.detail.scrollTop < 50) {
					this.isScrolling = false;
					this.iconColor = '#FFFFFF';
				}
				// #endif
			},
			getUserInfo() {
				let that = this;
				getUserInfo().then(res => {
					that.userInfo = res.data
					that.config.qrc.code = that.userInfo.bar_code;
					// 商城用户等级功能开启
					if (that.userInfo.member_func_status) {
						this.getlevelInfo();
					}
				});
			},
			getlevelInfo() {
				getlevelInfo().then(res => {
					const {
						level_info,
						level_list,
					} = res.data;
					this.levelList = level_list.map((item) => {
						return {
							...item,
							exp_num: Number(item.exp_num),
							lack_exp_num: Number(item.lack_exp_num),
						};
					});
					const index = this.levelList.findIndex((item) => item.id == level_info.level_id);
					// 当前等级
					if (index > -1) {
						this.levelInfo = this.levelList[index];
					}
					// 下个等级
					if (index < this.levelList.length - 1) {
						this.nextLevel = this.levelList[index + 1];
					}
				});
			},
			toggleEye() {
				this.isEye = !this.isEye;
			},
			colorToRgba(str, n) {
				// 十六进制颜色值的正则表达式
				const reg = /^#([0-9a-fA-f]{3}|[0-9a-fA-f]{6})$/;
				let sColor = str.toLowerCase();
				// 十六进制颜色转换为RGB格式
				if (sColor && reg.test(sColor)) {
					if (sColor.length === 4) {
						let sColorNew = '#';
						for (let i = 1; i < 4; i += 1) {
							sColorNew += sColor.slice(i, i + 1).concat(sColor.slice(i, i + 1));
						}
						sColor = sColorNew;
					}
					// 处理六位颜色值
					const sColorChange = [];
					for (let k = 1; k < 7; k += 2) {
						sColorChange.push(parseInt(`0x${sColor.slice(k, k + 2)}`, 16));
					}
					return `rgba(${sColorChange.join(',')}, ${n})`;
				}
				return sColor;
			},
			toggleQRCode() {
				this.isPay = !this.isPay;
			}
		},
	}
</script>

<style lang="scss" scoped>
	.scroll-view {
		position: absolute;
		top: 0;
		right: 0;
		bottom: 0;
		left: 0;
		background: linear-gradient(180deg, #1B1A17 0%, #141414 100%);

		.wrapper {
			padding: 94rpx 32rpx;
		}

		.wrapper-content {
			border-radius: 32rpx;
			background: #FFFFFF;
			overflow: hidden;
		}

		.header {
			height: 234rpx;
			border-radius: 32rpx 32rpx 0 0;
			background: linear-gradient(91deg, rgba(255, 162, 0, 0.05) 0%, rgba(255, 162, 0, 0.4) 100%);

			.title {
				text-align: center;
				font-weight: 600;
				font-size: 44rpx;
				line-height: 62rpx;
				color: rgba(128, 68, 0, 0.8);
			}

			.progress-wrap {
				margin-top: 14rpx;
				font-size: 22rpx;
				line-height: 30rpx;
				color: rgba(126, 75, 6, 0.8);
			}

			.progress {
				width: 360rpx;
				height: 8rpx;
				border-radius: 4rpx;
				margin: 0 16rpx;
				background: rgba(255, 255, 255, 0.4);
			}

			.inner {
				height: 8rpx;
				border-radius: 4rpx;
				background: rgba(126, 75, 6, 0.8);
			}

			.info {
				margin-top: 24rpx;
				font-size: 24rpx;
				line-height: 34rpx;
				color: #7E4B06;
			}

			.number {
				margin: 0 4rpx;
				font-family: SemiBold;
				font-size: 28rpx;
				color: rgba(126, 75, 6, 0.8);
			}

			.btn {
				margin-top: 18rpx;
				text-align: center;
				font-size: 30rpx;
				line-height: 42rpx;
				color: rgba(153, 101, 39, 0.7);

				.iconfont {
					margin-left: 4rpx;
					font-size: 32rpx;
				}
			}
		}

		.content {
			padding: 28rpx 0 0;
		}

		.codeNum {
			margin-top: 12rpx;
			letter-spacing: 3px;
			font-weight: 500;
			font-size: 28rpx;
			line-height: 40rpx;
			color: #333333;
		}

		.balance-wrapper {
			margin-top: 40rpx;
			background: #F9F9F9;
		}

		.balance {
			padding: 54rpx 0 76rpx;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #999999;

			.iconfont {
				margin-left: 8rpx;
				font-size: 28rpx;
			}

			.number {
				margin-top: 20rpx;
				font-family: SemiBold;
				font-size: 56rpx;
				line-height: 56rpx;
				color: #333333;
			}
		}

		.attribute {
			padding: 0 20rpx 48rpx;
			text-align: center;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #666666;

			.iconfont {
				margin-bottom: 16rpx;
				font-size: 48rpx;
				color: #444444;
			}
		}
	}

	.qrcode {
		width: 380rpx;
		height: 380rpx;
	}

	.toggle-btn {
		border-radius: 25rpx 0 0 25rpx;
		background: #FFF3DC;
		color: #996526;
	}
</style>