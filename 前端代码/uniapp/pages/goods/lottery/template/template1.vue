<template>
	<view class="lottery">
		<view class="grids" :style="[gridsImage]">
			<!-- #ifdef MP || APP-PLUS -->
			<view :style="{ height: `${getHeight.barTop}px` }"></view>
			<view :style="{ height: `${getHeight.barHeight}px` }"></view>
			<!-- #endif -->
			<view class="grids-content">
				<view v-if="isContent" class="rules-button" @click="rulesShow = true">抽奖规则</view>
				<view class="marquee-wrapper acea-row row-middle" v-if="userList.length">
					<text class="iconfont icon-ic_horn1"></text>
					<view class="marquee-wrapper-content">
						<noticeBar :showMsg="userList"></noticeBar>
					</view>
				</view>
				<view class="grids-box">
					<gridsLottery :isRotating="isRotating" :lotteryNum="lotteryNum" :winingIndex="lottery_draw_param.winingIndex" :prizeData="prizeList" @get_winingIndex='getWiningIndexs'
						@luck_draw_finish='luck_draw_finish'>
					</gridsLottery>
				</view>
				<view class="draw-button" :style="{
				backgroundImage: `url(${imgHost}/statics/images/lottery1.png)`
			}" @click="getWiningIndex">
					<image :src="`${imgHost}/statics/images/lottery3.png`" class="hand"></image>
				</view>
			</view>
		</view>
		<view class="show-box-wrapper">
			<view class="show-box-list">
				<view class="showBox" v-if="userList.length && isAll">
					<showBox :showMsg="{type: 'user',data: userList}"></showBox>
				</view>
				<view class="showBox" v-if="prizeList.length">
					<showBox :showMsg="{type: 'prize',data: prizeList}"></showBox>
				</view>
				<view class="showBox" v-if="myList.length && isPersonal">
					<showBox :showMsg="{type: 'me',data: myList}"></showBox>
				</view>
			</view>
			<view class="safe-area-inset-bottom"></view>
		</view>
		<!-- 抽奖规则弹窗 -->
		<view v-if="rulesShow" class="mask" @touchmove.stop.prevent="moveHandle"></view>
		<view class="rules-popup" :class="{ active: rulesShow }" @touchmove.stop.prevent="moveHandle">
			<view class="popup-top acea-row row-center-wrapper">
				<view class="title">抽奖规则</view>
				<text class="iconfont icon-ic_close1" @click="rulesShow = false"></text>
			</view>
			<scroll-view scroll-y="true" class="popup-center">
				<view v-html="htmlData"></view>
			</scroll-view>
			<view class="popup-bottom">
				<view class="button acea-row row-center-wrapper" @click="rulesShow = false">我知道了</view>
			</view>
			<view class="safe-area-inset-bottom"></view>
		</view>
		<!-- #ifdef H5 -->
		<view class="followCode" v-if="followCode">
			<view class="pictrue">
				<view class="code-bg"><img class="imgs" :src="codeSrc" /></view>
			</view>
			<view class="mask" @click="closeFollowCode"></view>
		</view>
		<zb-code ref="qrcode" v-show="false" :show="codeShow" :cid="cid" :val="val" :onval="onval" :loadMake="loadMake" @result="qrR" />
		<!-- #endif -->
		<!-- #ifdef H5 -->
		<template v-if="isWeixin">
			<view class="invite-people-wrap">
				<view class="invite-people-inner">
					<view class="invite-people">
						<view class="">邀请好友<text></text></view>
						<view class="invite" @click="$emit('H5Share')">
							去邀请
						</view>
					</view>
					<view class="safe-area-inset-bottom"></view>
				</view>
			</view>
			<view class="invite-people-placeholder"></view>
			<view class="safe-area-inset-bottom"></view>
		</template>
		<!-- #endif -->
	</view>
</template>

<script>
	import {
		getUserInfo
	} from '@/api/user.js';
	import zbCode from '@/components/zb-code/zb-code.vue'
	import gridsLottery from '../../components/lottery/index.vue'
	import showBox from '../components/showbox.vue'
	import noticeBar from '../components/noticeBar.vue'
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		props: {
			type: {
				type: String,
				default: ''
			},
			prizeList: {
				type: Array,
				default () {
					return [];
				}
			},
			userList: {
				type: Array,
				default () {
					return [];
				}
			},
			myList: {
				type: Array,
				default () {
					return [];
				}
			},
			htmlData: {
				type: String,
				default: ''
			},
			isPersonal: {
				type: Number,
				default: 0
			},
			isAll: {
				type: Number,
				default: 0
			},
			isContent: {
				type: Number,
				default: 0
			},
			image: {
				type: String,
				default: ''
			},
			lotteryNum: {
				type: Number,
				default: 0
			},
		},
		components: {
			gridsLottery,
			showBox,
			noticeBar,
			zbCode,
		},
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				H5ShareBox: false,
				// #ifdef H5
				isWeixin: this.$wechat.isWeixin(),
				// #endif
				lottery_num: 0,
				lottery_draw_param: {
					startIndex: 3, //开始抽奖位置，从0开始
					totalCount: 3, //一共要转的圈数
					winingIndex: 1, //中奖的位置，从0开始
					speed: 100 //抽奖动画的速度 [数字越大越慢,默认100]
				},
				factor_num: 0,
				id: 0,
				followCode: false,
				//二维码参数
				codeShow: false,
				cid: '1',
				ifShow: true,
				val: "", // 要生成的二维码值
				lv: 3, // 二维码容错级别 ， 一般不用设置，默认就行
				onval: true, // val值变化时自动重新生成二维码
				loadMake: true, // 组件加载完成后自动生成二维码
				src: '', // 二维码生成后的图片地址或base64
				codeSrc: "",
				factor: 0,
				rulesShow: false,
				isRotating: false,
				// #ifdef MP || APP-PLUS
				getHeight: this.$util.getWXStatusHeight(),
				// #endif
			}
		},
		computed: {
			gridsImage() {
				return {
					backgroundImage: `url(${this.image})`
				};
			}
		},
		methods: {
			//#ifdef H5
			ShareInfo(data) {
				let href = location.href;
				if (this.$wechat.isWeixin()) {
					getUserInfo().then(res => {
						href = href.indexOf('?') === -1 ? href + '?spid=' + res.data.uid : href + '&spid=' +
							res.data.uid;
						let configAppMessage = {
							desc: data.name,
							title: data.name,
							link: href,
							imgUrl: data.image
						};
						this.$wechat
							.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData',
								'onMenuShareAppMessage',
								'onMenuShareTimeline'
							], configAppMessage)
							.then(res => {})
							.catch(err => {});
					});
				}
			},
			//#endif
			getWiningIndexs(param) {
				this.lottery_draw_param.winingIndex = param.winingIndex;
				this.isRotating = true
			},
			getWiningIndex() {
				this.$emit('getWiningIndex', this.getWiningIndexs);
			},
			// 抽奖完成
			luck_draw_finish(param) {
				this.isRotating = false;
				this.$emit('luck_draw_finish');
			},
			qrR(res) {
				this.codeSrc = res
			},
			moveHandle() {}
		}
	}
</script>

<style lang="scss" scoped>
	page {
		background-color: #E74435;
	}

	.lottery {
		background-color: #E74435;
		min-height: 100vh;
		padding: 0 0 20rpx 0;

		.mask {
			z-index: 1000;
		}

		.rules-popup {
			position: fixed;
			right: 0;
			bottom: 0;
			left: 0;
			z-index: 1001;
			border-radius: 32rpx 32rpx 0 0;
			background-color: #FFFFFF;
			transform: translateY(100%);
			transition: 0.3s;

			&.active {
				transform: translateY(0);
			}

			&::before {
				content: "";
				position: absolute;
				width: 100%;
				height: 280rpx;
				background: linear-gradient(rgba(233, 51, 35, 0.20), rgba(255, 255, 255, 0));
			}

			.popup-top {
				position: relative;
				height: 108rpx;

				.title {
					font-weight: 500;
					font-size: 32rpx;
					color: #333333;
				}

				.iconfont {
					position: absolute;
					top: 38rpx;
					right: 34rpx;
					font-size: 34rpx;
					color: #BBBBBB;
				}
			}

			.popup-center {
				height: 972rpx;
				padding: 0 32rpx;
				box-sizing: border-box;
			}

			.popup-bottom {
				padding: 20rpx;

				.button {
					height: 72rpx;
					border-radius: 36rpx;
					background-color: #E93323;
					font-weight: 500;
					font-size: 26rpx;
					color: #FFFFFF;
				}
			}
		}

		.rules-button {
			position: absolute;
			bottom: 1048rpx;
			right: 0;
			width: 52rpx;
			padding: 10rpx 15rpx;
			border-radius: 16rpx 0 0 16rpx;
			background: linear-gradient(90deg, #FF8D8D 0%, #FF3F3F 100%);
			font-weight: 500;
			font-size: 22rpx;
			line-height: 30rpx;
			color: #FFFFFF;
		}

		.lottery-header {
			width: 100%;
			height: 580rpx;
			margin: 0;
		}

		.grids {
			background-position: left bottom;
			background-repeat: no-repeat;
			background-size: 100%;

			.grids-content {
				position: relative;
				height: 1212rpx;
			}

			.grids-box {
				position: absolute;
				bottom: 278rpx;
				left: 50%;
				width: 540rpx;
				height: 540rpx;
				transform: translateX(-50%);
			}

			.grids-top {
				display: flex;

				image {
					width: 40rpx;
					height: 40rpx;
				}

				.grids-title {
					display: flex;
					justify-content: center;
					width: 100%;
					font-size: 20px;
					color: #fff;
					z-index: 999;
					padding: 0 14rpx;

					.grids-frequency {
						color: #FFD68E;
					}
				}
			}

			.marquee-wrapper {
				position: absolute;
				bottom: 876rpx;
				left: 50%;
				transform: translateX(-50%);
        width: 540rpx;
				height: 62rpx;
				padding: 0 32rpx;

				.iconfont {
					margin-right: 16rpx;
					font-size: 32rpx;
					color: #FFEF6C;
				}

				.marquee-wrapper-content {
					flex: 1;
					min-width: 0;
				}
			}
		}

		.invite-people-placeholder {
			height: 120rpx;
		}

		.invite-people-wrap {
			position: fixed;
			right: 0;
			bottom: 0;
			left: 0;
			z-index: 2;
			background-color: #FFFFFF;
		}

		.invite-people-inner {
			background-color: rgba(233, 51, 35, 0.1);
		}

		.invite-people {
			padding: 28rpx 32rpx 28rpx 150rpx;
			display: flex;
			align-items: center;
			justify-content: space-between;
			background-image: url(data:image/png;base64,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);
			background-position: 32rpx center;
			background-repeat: no-repeat;
			background-size: 102rpx;

			.invite {
				display: flex;
				justify-content: center;
				align-items: center;
				width: 136rpx;
				height: 64rpx;
				font-size: 24rpx;
				font-weight: 500;
				color: #FFFFFF;
				background: linear-gradient(90deg, #FF7931 0%, #E93323 100%);
				box-shadow: 0rpx 4rpx 10rpx 0rpx rgba(138, 0, 0, 0.2726);
				border-radius: 32rpx;
			}
		}
	}

	.mask {
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background-color: rgba(0, 0, 0, 0.8);
		z-index: 9;
	}

	.share-box {
		z-index: 1300;
		position: fixed;
		left: 0;
		top: 0;
		width: 100%;
		height: 100%;

		image {
			width: 100%;
			height: 100%;
		}
	}

	.followCode {
		.pictrue {
			width: 500rpx;
			height: 530rpx;
			border-radius: 12px;
			left: 50%;
			top: 50%;
			margin-left: -250rpx;
			margin-top: -360rpx;
			position: fixed;
			z-index: 10000;

			.code-bg {
				display: flex;
				justify-content: center;
				width: 100%;
				height: 100%;
				background-image: url('~@/static/images/code-bg.png');
				background-size: 100% 100%;
			}

			.imgs {
				width: 310rpx;
				height: 310rpx;
				margin-top: 92rpx;
			}
		}

		.mask {
			z-index: 9999;
		}
	}

	.no-lottery {
		display: flex;
		justify-content: center;
		flex-direction: column;
		align-items: center;
		font-size: 28rpx;
		color: #ccc;
	}

	[v-cloak] {
		display: none;
	}

	.draw-button {
		position: absolute;
		bottom: 52rpx;
		left: 50%;
		transform: translateX(-50%);
		width: 470rpx;
		height: 141rpx;
		background-size: 100% 100%;

		.hand {
			position: absolute;
			top: -20rpx;
			right: -20rpx;
			width: 220rpx;
			height: 242rpx;
		}
	}

	.show-box-wrapper {
		padding: 20rpx;
		background-color: #EC4545;
	}

	.show-box-list {
		padding: 30rpx 20rpx;
		border-radius: 24rpx;
		background: #E23B1F;
		box-shadow: inset 0rpx 1rpx 3rpx 0rpx rgba(0, 0, 0, 0.5);

		.showBox+.showBox {
			margin-top: 60rpx;
		}
	}

	.table .table-head .nickname {
		width: 20%;
	}
</style>