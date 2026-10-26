<template>
	<view class="modal_container" @touchmove.stop.prevent>
		<view class="modal_box relative">
			<view class="h-172 flex-col flex-center">
				<view class="fs-32 lh-44rpx fw-500 relative w-full text-center">
					核销信息
					<!-- #ifdef H5 || MP -->
					<view v-if="isWeixin || isMP" class="flex-center pr-16 pl-16 abs-rt fs-24 lh-50rpx toggle-btn" @click="toggleQRCode">
						<text class="iconfont icon-ic_switch mr-8 fs-26"></text>
						{{ isPay ? (isWeixin ? '公众号码' : '小程序码') : '收银台码' }}
					</view>
					<!-- #endif -->
				</view>
				<view class="fs-26 lh-36rpx mt-28" v-if="productType == 4">已核销 {{writeOff}} / 需要核销 {{writeTimes}}</view>
			</view>
			<view class="mt-36 flex-col flex-center">
				<view class="mb-26 text--w111-999 fs-24" v-if="writeDay">核销时间：{{writeDay}}</view>
				<view class="qrcode flex-center">
					<view class="qrcode_content flex-center">
						<!-- #ifdef MP -->
						<w-qrcode v-if="isPay" :options="qrcode"></w-qrcode>
						<image v-else :src="qrc" class="image"></image>
						<!-- #endif -->
						<!-- #ifdef H5 -->
						<image v-if="$wechat.isWeixin() && !isPay" :src="qrc" class="image"></image>
						<w-qrcode v-else :options="qrcode"></w-qrcode>
						<!-- #endif -->
						<!-- #ifdef APP-PLUS -->
						<w-qrcode :options="qrcode"></w-qrcode>
						<!-- #endif -->
					</view>
				</view>
				<view class="bg-primary-light qrocode-num rd-16rpx flex-center fs-32 fw-500 font-num mt-48">{{verifyCode}}</view>
				<!-- #ifdef H5 || MP -->
				<view v-if="isWeixin || isMP" class="mt-20 text--w111-999 fs-24">收银台核销时，请切换至收银台码</view>
				<!-- #endif -->
			</view>
			<text class="iconfont icon-ic_close1 close fs-48 text--w111-fff"></text>
		</view>
		<view class="mask z-90" @click="closeModal"></view>
	</view>
</template>

<script>
	export default {
		props: {
			qrcode: {
				type: Object,
				default: () => ({})
			},
			verifyCode: {
				type: String,
				default: ""
			},
			writeDay: {
				type: String,
				default: ""
			},
			writeOff: {
				type: [String, Number],
				default: 0
			},
			writeTimes: {
				type: [String, Number],
				default: 0
			},
			productType: {
				type: Number,
				default: 0
			},
			qrc: {
				type: String,
				default: ""
			},
		},
		data() {
			return {
				isPay: false,
				isWeixin: false,
				isMP: false,
			}
		},
		created() {
			// #ifdef MP
			this.isMP = true;
			// #endif
			// #ifdef H5
			this.isWeixin = this.$wechat.isWeixin();
			// #endif
		},
		methods: {
			moveHandle() {
				return false
			},
			closeModal() {
				this.$emit('closeModal');
			},
			toggleQRCode() {
				this.isPay = !this.isPay;
			}
		}
	}
</script>

<style>
	.modal_box {
		position: fixed;
		left: 50%;
		top: 50%;
		transform: translate(-50%, -50%);
		z-index: 99;
		width: 600rpx;
		min-height: 906rpx;
		padding-bottom: 44rpx;
		background: #fff;
		border-radius: 32rpx;
		-webkit-mask: radial-gradient(circle at 14rpx 172rpx, transparent 14rpx, red 0) -14rpx;
	}

	.h-172 {
		height: 172rpx;
		border-bottom: 1px dashed #ccc;
	}

	.z-90 {
		z-index: 90;
	}

	.qrcode {
		width: 440rpx;
		height: 440rpx;
		background-size: 100%;
		background-repeat: no-repeat;
	}

	.qrcode_content {
		width: 360rpx;
		height: 360rpx;
	}

	.bg-primary-light {
		background: var(--view-minorColorT);
	}

	.qrocode-num {
		width: 440rpx;
		height: 80rpx;
	}

	.close {
		position: absolute;
		left: 50%;
		bottom: -96rpx;
		transform: translateX(-50%);
	}

	.qrcode_content .image {
		width: 100%;
		height: 100%;
	}

	.toggle-btn {
		top: 50%;
		transform: translateY(-50%);
		border-radius: 25rpx 0 0 25rpx;
		background: #FFF3DC;
		color: #996526;
	}
</style>