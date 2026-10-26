<template>
	<view class="lucky-wheel">
		<view class="header" :style="{ backgroundImage: `url(${image})` }">
			<!-- #ifdef MP || APP-PLUS -->
			<view :style="{ height: `${getHeight.barTop}px` }"></view>
			<view :style="{ height: `${getHeight.barHeight}px` }"></view>
			<!-- #endif -->
			<view class="header-content">
				<view v-if="isContent" class="rules-button" @click="rulesShow = true">抽奖规则</view>
				<view v-if="userList.length" class="marquee-wrapper">
					<noticeBar :showMsg="userList" prefix="恭喜"></noticeBar>
				</view>
				<view class="wheel-container">
					<view class="wheel-panel" :style="{ transform: `rotate(${rotateAngle}deg)` }">
						<view v-for="(item, index) in prizeList" :key="item.id" :style="{ transform: `rotate(${index*360/8}deg) skewY(-45deg)` }" class="item"></view>
					</view>
					<view class="wheel-prize" :style="{ transform: `rotate(${rotateAngle}deg)` }">
						<view v-for="(item, index) in prizeList" :key="item.id" :style="{ transform: `rotate(${(2*index+1)*360/8/2}deg) translateX(-50%)` }" class="item">
							<view class="line1">{{ item.name }}</view>
							<image :src="item.image" class="image"></image>
						</view>
					</view>
					<view class="wheel-point">
						<view class="draw-button" :style="{
						backgroundImage: `url(${imgHost}/statics/images/lottery2.png)`
					}"></view>
						<image :src="`${imgHost}/statics/images/lottery3.png`" class="hand"></image>
						<view class="button" @click="getWiningIndex"></view>
					</view>
				</view>
				<view class="number-wrapper">你获得 <text class="number">{{ lotteryNum }}次</text> 抽奖机会</view>
			</view>
		</view>
		<view class="main-wrapper">
			<!-- 中奖记录 -->
			<view v-if="userList.length && isAll" class="record-wrapper">
				<view class="wrapper-header acea-row row-center-wrapper">
					<view class="title" :style="{
						backgroundImage: `url(${imgHost}/statics/images/lottery5.png),url(${imgHost}/statics/images/lottery6.png)`
					}">中奖记录</view>
				</view>
				<scroll-view scroll-y="true" class="wrapper-main" :style="{
					backgroundImage: `url(${imgHost}/statics/images/lottery7.png)`
				}">
					<view v-for="item in userList" :key="item.id" class="item">恭喜 <text class="mark">{{ item.user ? item.user.nickname : '****' }}</text> 获得 <text
							class="mark">{{ item.prize.name }}</text>
					</view>
				</scroll-view>
			</view>
			<!-- 活动奖品 -->
			<view class="prize-wrapper">
				<view class="wrapper-header">
					<view class="title-box acea-row row-middle" :style="{
						backgroundImage: `url(${imgHost}/statics/images/lottery8.png)`
					}">
						<view class="title" :style="{
							backgroundImage: `url(${imgHost}/statics/images/lottery5.png),url(${imgHost}/statics/images/lottery6.png)`
						}">活动奖品</view>
					</view>
				</view>
				<view class="wrapper-main">
					<view class="list acea-row">
						<template v-for="item in prizeList">
							<view v-if="item.type != 1" :key="item.id" class="item">
								<view class="image-wrap">
									<image :src="item.image" class="image"></image>
								</view>
								<view class="line2">{{ item.name }}</view>
							</view>
						</template>
					</view>
				</view>
			</view>
			<!-- 我的奖品 -->
			<view v-if="myList.length && isPersonal" class="prize-wrapper mine">
				<view class="wrapper-header">
					<view class="title-box acea-row row-middle" :style="{
						backgroundImage: `url(${imgHost}/statics/images/lottery9.png)`
					}">
						<view class="title" :style="{
							backgroundImage: `url(${imgHost}/statics/images/lottery5.png),url(${imgHost}/statics/images/lottery6.png)`
						}">我的奖品</view>
					</view>
				</view>
				<view class="wrapper-main">
					<view class="list">
						<view class="item th acea-row row-middle">
							<view class="mark">序号</view>
							<view class="time">获奖时间</view>
							<view class="">奖品名称</view>
						</view>
						<view v-for="(item, index) in myList" :key="item.id" class="item acea-row row-middle">
							<view class="mark">{{ index + 1 }}</view>
							<view class="time">{{ item.add_time }}</view>
							<view class="line1">{{ item.prize.name }}</view>
						</view>
					</view>
				</view>
			</view>
			<view class="safe-area-inset-bottom"></view>
		</view>
		<!-- 抽奖规则弹窗 -->
		<view v-if="rulesShow" class="mask" @touchmove.stop.prevent="moveHandle"></view>
		<view class="rules-popup" :class="{ active: rulesShow }" @touchmove.stop.prevent="moveHandle">
			<view class="popup-top">
				<view class="title">抽奖规则</view>
			</view>
			<scroll-view scroll-y="true" class="popup-center">
				<view v-html="htmlData"></view>
			</scroll-view>
			<view class="popup-bottom">
				<view class="button acea-row row-center-wrapper" @click="rulesShow = false">我知道了</view>
			</view>
		</view>
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
	import noticeBar from '../components/noticeBar.vue';
	let angleList = [];
	for (let i = 0; i < 8; i++) {
		const angle = -(i * 45 + (45 / 2));
		angleList.push(angle);
	}
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
			noticeBar,
		},
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				rulesShow: false,
				rotateAngle: 0,
				angleList: angleList,
				winingIndex: 0,
				id: 0,
				factor: 0,
				// #ifdef MP || APP-PLUS
				getHeight: this.$util.getWXStatusHeight(),
				// #endif
				// #ifdef H5
				isWeixin: this.$wechat.isWeixin(),
				// #endif
			}
		},
		methods: {
			getWiningIndex() {
				this.$emit('getWiningIndex', this.rotating);
			},
			random(min, max) {
				return parseInt(Math.random() * (max - min + 1) + min)
			},
			rotating(param) {
				const config = {
					duration: 5000,
					circle: 8,
					mode: "ease-in-out"
				}
				// 计算角度
				const angle =
					// 初始角度
					this.rotateAngle +
					// 多旋转的圈数
					config.circle * 360 +
					// 奖项的角度
					this.angleList[param.winingIndex] -
					(this.rotateAngle % 360);
				this.rotateAngle = angle;
				// 旋转结束后
				setTimeout(() => {
					// isRotating = false;
					this.$emit('luck_draw_finish');
				}, config.duration + 500);
			},
			// 抽奖完成
			luck_draw_finish(param) {
				this.aleartType = 2
				this.aleartStatus = true
			},
			moveHandle() {}
		},
	}
</script>

<style lang="scss" scoped>
	.lucky-wheel {
		.header {
			background-position: left bottom;
			background-repeat: no-repeat;
			background-size: 100%;
		}

		.header-content {
			position: relative;
			height: 1160rpx;
		}

		.rules-button {
			position: absolute;
			bottom: 980rpx;
			right: 0;
			width: 52rpx;
			padding: 10rpx 15rpx;
			border-radius: 16rpx 0 0 16rpx;
			background: linear-gradient(180deg, #FCF2DC 0%, #F5D494 100%);
			font-weight: 500;
			font-size: 22rpx;
			line-height: 30rpx;
			color: #E93323;
		}

		.marquee-wrapper {
			position: absolute;
			bottom: 888rpx;
			left: 50%;
			transform: translateX(-50%);
			width: 488rpx;
			height: 40rpx;
			background: linear-gradient(270deg, rgba(255, 255, 255, 0) 0%, rgba(255, 255, 255, 0.85) 49%, rgba(255, 255, 255, 0) 100%);
			overflow: hidden;
		}

		.wheel-container {
			position: absolute;
			left: 50%;
			bottom: 302rpx;
			width: 504rpx;
			height: 504rpx;
			border-radius: 50%;
			overflow: hidden;
			transform: translateX(-50%);
		}

		.wheel-prize {
			position: absolute;
			width: 100%;
			height: 100%;
			transition: transform 5000ms ease-in-out;

			.item {
				position: absolute;
				top: 0;
				left: 50%;
				width: 25%;
				height: 50%;
				padding-top: 24rpx;
				transform-origin: 0% 100%;
				text-align: center;
				font-weight: 500;
				font-size: 24rpx;
				line-height: 34rpx;
				color: #E93323;

				.image {
					display: block;
					width: 48rpx;
					height: 48rpx;
					margin: 24rpx auto 0;
				}
			}
		}

		.wheel-panel {
			position: absolute;
			width: 100%;
			height: 100%;
			transition: transform 5000ms ease-in-out;
			border-radius: 50%;
			overflow: hidden;

			.item {
				position: absolute;
				top: 0;
				left: 50%;
				width: 50%;
				height: 50%;
				background: linear-gradient(242deg, #FFF9D7 0%, #FFF7BC 100%);
				transform-origin: 0% 100%;

				&:nth-child(even) {
					background: linear-gradient(239deg, #FFCE9B 0%, #FFB764 100%);
				}
			}
		}

		.wheel-point {
			position: absolute;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);

			.draw-button {
				width: 212rpx;
				height: 276rpx;
				margin-top: -64rpx;
				background-repeat: no-repeat;
				background-size: 100% 100%;
			}

			.hand {
				position: absolute;
				top: 40rpx;
				left: 70rpx;
				width: 220rpx;
				height: 242rpx;
			}

			.button {
				position: absolute;
				top: 0;
				left: 50%;
				width: 180rpx;
				height: 180rpx;
				transform: translateX(-50%);
			}
		}

		.number-wrapper {
			position: absolute;
			bottom: 24rpx;
			left: 50%;
			height: 56rpx;
			transform: translateX(-50%);
			font-weight: 600;
			font-size: 32rpx;
			line-height: 56rpx;
			color: rgba(255, 255, 255, 0.9);
		}

		.number {
			color: #FFD68E;
		}
	}

	.main-wrapper {
		padding: 38rpx 20rpx;
		background-color: #FF3433;
		background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAu4AAAAmCAYAAACbOR46AAAAAXNSR0IArs4c6QAAAyRJREFUeF7t3LGKU2EQhuH9TWKChYWFhYWFYGFhIbLKKqusoqJefy4jRQghIQkcGSH38ME8uYJ3npNiOAxnTNP0/saPAAECBAgQIECAAIFogXE6nW6jC8URIECAAAECBAgQaC6wWCymMU3Th+YOxidAgAABAgQIECAQL1CL+8f4SoEECBAgQIAAAQIEmguM8/l819zA+AQIECBAgAABAgSiBebz+f9TmU/RleIIECBAgAABAgQIELipxf0zBwIECBAgQIAAAQIEsgXqVOY+O1EdAQIECBAgQIAAgd4C11OZL70ZTE+AAAECBAgQIEAgX6BOZb7mZyokQIAAAQIECBAg0FugTmUeehOYngABAgQIECBAgEC2wPVU5lt2pjoCBAgQIECAAAECBOpU5jsGAgQIECBAgAABAgSyBepU5kd2ojoCBAgQIECAAAECvQWupzI/ezOYngABAgQIECBAgEC+QJ3K/MrPVEiAAAECBAgQIECgt8C4XC6/exOYngABAgQIECBAgEC2wGw2m+qN+5/sTHUECBAgQIAAAQIECNTi/hcDAQIECBAgQIAAAQLZArW4O5XJfkbqCBAgQIAAAQIECNzU4u5zkP4IBAgQIECAAAECBMIFanF/CG+UR4AAAQIECBAgQKC9QC3u9+0VABAgQIAAAQIECBAIF6jF/S68UR4BAgQIECBAgACB9gK1uN+2VwBAgAABAgQIECBAIFygFvd34Y3yCBAgQIAAAQIECLQXqMX9bXsFAAQIECBAgAABAgTCBWpxfxPeKI8AAQIECBAgQIBAe4Fa3F+3VwBAgAABAgQIECBAIFxgHI/HV+GN8ggQIECAAAECBAi0Flgul9M4HA4vWysYngABAgQIECBAgEC4wGq1msZ+v38R3imPAAECBAgQIECAQHuBsdvtnrdXAECAAAECBAgQIEAgXGBst9tn4Y3yCBAgQIAAAQIECLQXGJvN5ml7BQAECBAgQIAAAQIEwgXqc5BPwhvlESBAgAABAgQIEGgvMNbr9eP2CgAIECBAgAABAgQIhAvUG/dH4Y3yCBAgQIAAAQIECLQX+Adrd4AI6Xst4wAAAABJRU5ErkJggg==);
		background-repeat: no-repeat;
		background-size: 100%;
	}

	.record-wrapper {
		.wrapper-header {
			padding: 42rpx 0 40rpx;

			.title {
				padding: 0 74rpx;
				background-position: left center, right center;
				background-repeat: no-repeat;
				background-size: 54rpx 24rpx;
				font-weight: 700;
				font-size: 36rpx;
				line-height: 36rpx;
				color: rgba(255, 254, 239, 0.90);
			}
		}

		.wrapper-main {
			height: 352rpx;
			padding: 44rpx 60rpx 0;
			background-repeat: no-repeat;
			background-size: 100% 100%;
			box-sizing: border-box;

			.item {
				padding-left: 42rpx;
				margin-bottom: 34rpx;
				background-image: url(data:image/png;base64,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);
				background-position: left center;
				background-repeat: no-repeat;
				background-size: 32rpx 29rpx;
				font-size: 24rpx;
				line-height: 34rpx;
				color: #333333;
			}

			.mark {
				margin: 0 20rpx;
				color: #E93323;
			}
		}
	}

	.prize-wrapper {
		margin-top: 60rpx;

		&.mine .wrapper-main {
			.list {
				margin-right: 0;
			}

			.item {
				flex-wrap: nowrap;
				width: auto;
				margin: 0 0 32rpx;
				text-align: left;
				font-size: 24rpx;
				line-height: 34rpx;

				&.th {
					font-weight: 500;
					font-size: 28rpx;
					line-height: 40rpx;
				}
			}

			.mark {
				width: 152rpx;
				padding-left: 8rpx;
			}

			.time {
				width: 266rpx;
			}
		}

		.wrapper-header {
			padding-right: 32rpx;
			background-image: url(data:image/png;base64,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);
			background-position: left 8rpx;
			background-repeat: no-repeat;
			background-size: 100% 100%;

			.title-box {
				padding: 48rpx 0 54rpx 32rpx;
				background-position: right top;
				background-repeat: no-repeat;
				background-size: 114rpx 114rpx;
			}

			.title {
				padding: 0 74rpx;
				background-position: left center, right center;
				background-repeat: no-repeat;
				background-size: 54rpx 24rpx;
				font-weight: 700;
				font-size: 36rpx;
				line-height: 36rpx;
				color: #E93323;
			}
		}

		.wrapper-main {
			padding: 6rpx 32rpx 8rpx;
			border-radius: 0 0 32rpx 32rpx;
			background-color: #FFF3E2;

			.list {
				margin-right: -35rpx;
			}

			.item {
				width: 192rpx;
				margin: 0 35rpx 30rpx 0;
				text-align: center;
				font-size: 26rpx;
				line-height: 36rpx;
				color: #333333;
			}

			.image-wrap {
				width: 192rpx;
				height: 192rpx;
				border: 4rpx dotted #FFB87E;
				border-radius: 24rpx;
				margin-bottom: 20rpx;
				background: #FFFFFF;
			}

			.image {
				width: 100%;
				height: 100%;
				border-radius: 24rpx;
				object-fit: contain;
			}
		}
	}

	.rules-popup {
		position: fixed;
		top: 50%;
		left: 50%;
		z-index: 1001;
		display: none;
		width: 620rpx;
		padding: 94rpx 38rpx 0;
		border: 10rpx solid #FED59C;
		border-radius: 48rpx;
		background-color: #FFFFFF;
		transform: translate(-50%, -50%);

		&.active {
			display: block;
		}

		.popup-top {
			position: absolute;
			top: -24rpx;
			left: 50%;
			width: 396rpx;
			height: 87rpx;
			padding-top: 14rpx;
			background-image: url(data:image/png;base64,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);
			background-repeat: no-repeat;
			background-size: 100% 100%;
			transform: translateX(-50%);
			text-align: center;

			.title {
				display: inline-block;
				padding: 0 48rpx;
				background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACUAAAANCAYAAAAuYadYAAAAAXNSR0IArs4c6QAAAbFJREFUSEvNlc9LG0EUx79vk9ltjPWijaLRg5hDL0HJIXg1PZX+B/5xIv4Dnr2KBUHFU2tNUERbBcU0mmhmJrNPdidL0mDMHkLqwvLefN+beZ+dnR+E//Aw4Pxd+Tx/96hvcpWK7EWgUTOVi0sTH1v+DoGKDFyIJH2d3K/86OYYKdRNPp8m0fgOIN8FcZ1AYm3q8PQk0kYG9adQGEugugfQ8iu/60qT/2Xu4PxXECPe2PiAdNrCVavWPjwQGg3rPz1Z+/xMuL8HlCJobbXA1mqAMf/2931CvQ5E1jXeXTaz7Ytkod9yYeDSMVzKHJ+Vibe21o2UXkIpF9GrtRf6zWagWd+2PbRaIvSl7OgDfNYqrVLCqS1OD1rCp5ka5UMoaG0LvgYVxKKiUrphbg8US+lR1LcbsJ3HWo+rVJJiQIEYOeLNzW/trxcIihqTDGdISgGtBZpNqysVzJDVgphtd3QpXVZKUCdmc6R0DZsFNSZmHuen4s3UoKxhxHk1m7pV3i4D8dbUMIrGGeOt3QfGb8fxS5+i3RdnwGHl9DunDDul2aPyz5GfU1HBd3eiR2CD7r4X9K4sHA2QNjgAAAAASUVORK5CYII=),
					url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACUAAAANCAYAAAAuYadYAAAAAXNSR0IArs4c6QAAAaBJREFUSEvNlcEvA0EUxr+3szOzVVpEoogItgQXESIhzq7+WW4949CQXhyIgzZFcRFtbad2ZHa0tEqbCLrJ5L15882837zdmSV0eM59X44N8dTwyVmegLCT5jdj1L74w6a/rOr6gIAZAEePLu2mjy8efxOife0WqOJaeolRmAEw8UGY0yq+lcrlyn8F1oS6W59dDEMnA8LU5+T69AXP25PZYuUvwCKo0upcOmSUIWD6q6SOqmfHCqU91FiAwUHAcXTTmkkjIzqay5hGMglwbvvGCqExOgrEYjY2MGBtPK6RSFjfzC8UIkva92UpqXMAFr6rQvLyFqKiQhKiDCFqkDKIbLsvhI1L+a5xXQXPe9cbjefZuZw34y9C1JiUAZXW5n1NOO/2WiKoal0T508tQL1AcW4hG1rTN1Cu27o5A855redKDeXvIcrqhjns6i2BghBR01IqMr5NrqJmq2h9zq3W86zGWjtm4BirN9d0XdXzN6WB7LgIduiwUO1W1Z+Ot54+7ZjroD9OX2NnfXdPNcCuN/wVCvV+39zoDbD//ve9AhTN0SA4F/9jAAAAAElFTkSuQmCC);
				background-position: left center, right center;
				background-repeat: no-repeat;
				background-size: 37rpx 13rpx;
				font-weight: 500;
				font-size: 36rpx;
				line-height: 50rpx;
				color: #E93323;
			}
		}

		.popup-center {
			height: 572rpx;
			font-size: 28rpx;
			line-height: 44rpx;
			color: #333333;
		}

		.popup-bottom {
			padding: 24rpx 0;

			.button {
				height: 80rpx;
				border-radius: 40rpx;
				background-color: #E93323;
				font-weight: 500;
				font-size: 28rpx;
				color: #FFFFFF;
			}
		}

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
</style>