<template>
	<view class="pay-page">
		<view class="payment-top acea-row row-column row-center-wrapper">
			<text class="name">付款金额</text>
			<view class="price">￥<text class="price-number">{{ price }}</text></view>
		</view>

		<view class="payment">
			<button v-if="status !== 1" class="pay-button bg-color" :loading="paying" :disabled="paying" @click="appPay">
				{{ paying ? '正在打开微信支付' : '立即缴费' }}
			</button>
			<button v-else class="pay-button paid-button" disabled>已支付</button>
		</view>
	</view>
</template>

<script>
	export default {
		data() {
			return {
				price: '0.00',
				status: 0,
				qrcode: '',
				appId: '',
				paying: false
			};
		},
		onLoad(options) {
			this.qrcode = options.qrcode || '';
			this.price = options.price || '0.00';
			this.status = Number(options.status || 0);
			this.appId = options.appid || '';
		},
		methods: {
			appPay() {
				if (this.paying) return;
				if (!this.qrcode || !this.appId) {
					return this.$util.Tips({ title: '支付参数不完整，请重新发起支付' });
				}
				this.paying = true;
				// #ifdef MP-WEIXIN
				wx.openEmbeddedMiniProgram({
					appId: this.appId,
					path: `/pages/qrPay/qrPay?t=${encodeURIComponent(this.qrcode)}`,
					envVersion: 'release',
					success: () => {
						this.paying = false;
					},
					fail: (error) => {
						this.paying = false;
						this.$util.Tips({
							title: (error && error.errMsg) || '未能打开富友微信支付'
						});
					}
				});
				// #endif
				// #ifndef MP-WEIXIN
				this.paying = false;
				this.$util.Tips({ title: '请在微信中打开支付链接' });
				// #endif
			}
		}
	};
</script>

<style lang="scss" scoped>
	.pay-page {
		min-height: 100vh;
		background: #fff;
	}

	.payment-top {
		height: 350rpx;
		background-color: var(--view-theme, #f73883);
		color: #fff;
	}

	.name {
		font-size: 26rpx;
		margin-bottom: 30rpx;
	}

	.price {
		font-size: 32rpx;
	}

	.price-number {
		font-size: 78rpx;
	}

	.payment {
		padding-top: 46rpx;
	}

	.pay-button {
		width: 700rpx;
		height: 86rpx;
		line-height: 86rpx;
		border-radius: 50rpx;
		color: #fff;
		font-size: 30rpx;
	}

	.bg-color {
		background-color: var(--view-theme, #f73883);
	}

	.paid-button {
		background-color: #999;
	}
</style>
