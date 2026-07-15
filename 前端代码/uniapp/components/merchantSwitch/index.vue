<template>
	<view
		v-if="visible"
		class="merchant-switch"
		:style="{ top: topPx + 'px' }"
		@click="onTap"
	>
		<text class="merchant-switch__text">{{ label }}</text>
	</view>
</template>

<script>
import { mapGetters } from 'vuex';

export default {
	name: 'MerchantSwitch',
	props: {
		/** buyer | merchant */
		side: {
			type: String,
			default: 'buyer',
		},
	},
	data() {
		return {
			topPx: 0,
			checking: false,
		};
	},
	computed: {
		...mapGetters(['isLogin']),
		canEnter() {
			return this.$store.state.merchant.canEnter;
		},
		label() {
			return this.side === 'merchant' ? '买家入口' : '商家入口';
		},
		visible() {
			if (!this.isLogin) return false;
			if (this.side === 'merchant') return true;
			return this.canEnter;
		},
	},
	mounted() {
		this.calcTop();
		if (this.side === 'buyer' && this.isLogin) {
			this.refreshAccess();
		}
	},
	watch: {
		isLogin(val) {
			if (val && this.side === 'buyer') {
				this.refreshAccess();
			} else if (!val) {
				this.$store.dispatch('merchant/resetMerchant');
			}
		},
	},
	methods: {
		calcTop() {
			try {
				const sys = uni.getSystemInfoSync();
				const h = sys.windowHeight || 667;
				this.topPx = Math.round(h * 0.42);
			} catch (e) {
				this.topPx = 280;
			}
		},
		async refreshAccess() {
			try {
				await this.$store.dispatch('merchant/fetchAccess', true);
			} catch (e) {}
		},
		async onTap() {
			if (this.checking) return;
			this.checking = true;
			try {
				if (this.side === 'merchant') {
					await this.$store.dispatch('merchant/exitMerchant');
					uni.reLaunch({ url: '/pages/index/index' });
					return;
				}
				const data = await this.$store.dispatch('merchant/fetchAccess', true);
				if (!data || !data.can_enter_merchant) {
					uni.showToast({ title: '当前账号暂无商家权限', icon: 'none' });
					return;
				}
				await this.$store.dispatch('merchant/enterMerchant');
				uni.reLaunch({ url: '/pages/merchant/home/index' });
			} catch (e) {
				uni.showToast({ title: '切换失败，请稍后重试', icon: 'none' });
			} finally {
				this.checking = false;
			}
		},
	},
};
</script>

<style scoped>
.merchant-switch {
	position: fixed;
	right: 0;
	z-index: 900;
	padding: 20rpx 12rpx 20rpx 18rpx;
	background: rgba(28, 28, 30, 0.88);
	border-radius: 24rpx 0 0 24rpx;
	box-shadow: -4rpx 4rpx 16rpx rgba(0, 0, 0, 0.12);
}
.merchant-switch__text {
	writing-mode: vertical-rl;
	letter-spacing: 4rpx;
	font-size: 24rpx;
	color: #fff;
	line-height: 1.2;
}
</style>
