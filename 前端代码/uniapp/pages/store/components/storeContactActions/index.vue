<template>
	<view class="store-contact-actions">
		<button class="store-contact-actions__button" @tap="$emit('navigate')">导航</button>
		<button class="store-contact-actions__button" @tap="callStore">联系门店</button>
	</view>
</template>

<script>
	// 门店两种首页布局共用联系操作；导航沿用父页面逻辑，拨号只取当前门店资料中的 phone。
	export default {
		props: {
			phone: {
				type: [String, Number],
				default: ''
			}
		},
		methods: {
			// 不回退到平台客服或员工号码；切换门店后每次点击均读取新的门店手机号。
			callStore() {
				const phoneNumber = String(this.phone == null ? '' : this.phone).trim();
				if (!phoneNumber) {
					uni.showToast({ title: '门店暂未配置联系电话', icon: 'none' });
					return;
				}
				uni.makePhoneCall({ phoneNumber });
			}
		}
	};
</script>

<style scoped lang="scss">
	/* 独立于门店资料卡，两个等宽按钮在小屏保持一左一右，不挤占地址空间。 */
	.store-contact-actions {
		display: flex;
		padding: 0 20rpx 20rpx;
	}
	.store-contact-actions__button {
		flex: 1;
		min-width: 0;
		margin: 0;
		padding: 0 16rpx;
		border: 1rpx solid var(--view-theme, #333333);
		border-radius: 12rpx;
		background: #FFFFFF;
		color: var(--view-theme, #333333);
		font-size: 28rpx;
		line-height: 80rpx;
	}
	.store-contact-actions__button + .store-contact-actions__button {
		margin-left: 20rpx;
	}
	.store-contact-actions__button::after {
		border: 0;
	}
</style>
