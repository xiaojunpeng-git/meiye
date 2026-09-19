<template>
	<view class="transfer-page">
		<view class="coupon-card">
			<view class="coupon-card__title">{{ coupon.title || '优惠券' }}</view>
			<view class="coupon-card__amount">
				<text class="coupon-card__symbol">¥</text>{{ coupon.couponPrice }}
			</view>
			<view class="coupon-card__rule">{{ couponRule }}</view>
			<view class="coupon-card__date">有效期至 {{ coupon.endTime || '--' }}</view>
		</view>

		<view class="form-card">
			<view class="form-title">查找接收会员</view>
			<view class="form-tip">仅支持通过11位手机号精确查找</view>
			<view class="phone-row">
				<input
					v-model.trim="phone"
					class="phone-input"
					type="number"
					maxlength="11"
					placeholder="请输入接收人手机号"
					@input="onPhoneInput"
				/>
				<view class="search-button" :class="{ disabled: searching }" @tap="lookupTarget">
					{{ searching ? '查找中' : '查找' }}
				</view>
			</view>

			<view v-if="target" class="target-card">
				<image class="target-card__avatar" :src="target.avatar || defaultAvatar" mode="aspectFill"></image>
				<view class="target-card__content">
					<view class="target-card__name">{{ target.nickname || '未设置昵称' }}</view>
					<view class="target-card__phone">{{ target.phone_masked }}</view>
				</view>
				<view class="target-card__status">已确认</view>
			</view>
		</view>

		<view class="notice-card">
			<view class="notice-card__title">转赠说明</view>
			<view class="notice-card__item">1. 转赠成功后不可撤回。</view>
			<view class="notice-card__item">2. 每张优惠券只能转赠一次。</view>
			<view class="notice-card__item">3. 转赠不会延长优惠券有效期。</view>
		</view>

		<view class="footer-placeholder"></view>
		<view class="footer-safe">
			<view class="submit-button" :class="{ disabled: !target || submitting }" @tap="confirmTransfer">
				{{ submitting ? '转赠中…' : '确认转赠' }}
			</view>
		</view>
	</view>
</template>

<script>
	import {
		getCouponTransferTarget,
		transferUserCoupon
	} from '@/api/api.js';

	export default {
		data() {
			return {
				coupon: {
					id: 0,
					title: '',
					couponPrice: '',
					useMinPrice: '',
					endTime: ''
				},
				phone: '',
				target: null,
				searching: false,
				submitting: false,
				requestId: '',
				defaultAvatar: '/static/images/f.png'
			};
		},
		computed: {
			couponRule() {
				const useMinPrice = Number(this.coupon.useMinPrice || 0);
				return useMinPrice > 0 ? `满${useMinPrice}元可用` : '无门槛券';
			}
		},
		onLoad(options) {
			this.coupon = {
				id: Number(options.coupon_user_id || 0),
				title: this.decodeOption(options.title),
				couponPrice: this.decodeOption(options.coupon_price),
				useMinPrice: this.decodeOption(options.use_min_price),
				endTime: this.decodeOption(options.end_time)
			};
			if (!this.coupon.id) {
				uni.showToast({ title: '优惠券参数错误', icon: 'none' });
			}
		},
		methods: {
			decodeOption(value) {
				try {
					return decodeURIComponent(value || '');
				} catch (e) {
					return value || '';
				}
			},
			onPhoneInput() {
				this.target = null;
				this.requestId = '';
			},
			lookupTarget() {
				if (this.searching) return;
				if (!/^1\d{10}$/.test(this.phone)) {
					uni.showToast({ title: '请输入正确的11位手机号码', icon: 'none' });
					return;
				}
				this.searching = true;
				this.target = null;
				getCouponTransferTarget({
					coupon_user_id: this.coupon.id,
					phone: this.phone
				}).then(res => {
					this.target = res.data;
					this.requestId = this.createRequestId();
				}).catch(err => {
					uni.showToast({ title: (err && err.msg) || (typeof err === 'string' ? err : '会员查找失败'), icon: 'none' });
				}).finally(() => {
					this.searching = false;
				});
			},
			createRequestId() {
				return `coupon-transfer-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
			},
			confirmTransfer() {
				if (!this.target || this.submitting || !this.coupon.id) return;
				uni.showModal({
					title: '确认转赠',
					content: `确认将“${this.coupon.title || '该优惠券'}”转赠给 ${this.target.nickname || this.target.phone_masked}？转赠后不可撤回，有效期不延长。`,
					confirmText: '确认转赠',
					confirmColor: '#e93323',
					success: result => {
						if (result.confirm) this.submitTransfer();
					}
				});
			},
			submitTransfer() {
				this.submitting = true;
				transferUserCoupon({
					coupon_user_id: this.coupon.id,
					phone: this.phone,
					request_id: this.requestId || this.createRequestId()
				}).then(() => {
					uni.showToast({ title: '转赠成功', icon: 'success' });
					setTimeout(() => uni.navigateBack(), 1200);
				}).catch(err => {
					uni.showToast({ title: (err && err.msg) || (typeof err === 'string' ? err : '转赠失败'), icon: 'none' });
				}).finally(() => {
					this.submitting = false;
				});
			}
		}
	};
</script>

<style lang="scss" scoped>
	.transfer-page {
		min-height: 100vh;
		padding: 28rpx 24rpx 0;
		box-sizing: border-box;
		background: #f5f5f5;
		color: #282828;
	}

	.coupon-card {
		padding: 34rpx;
		border-radius: 24rpx;
		background: linear-gradient(135deg, var(--view-theme, #e93323), var(--view-gradient, #ff7e9b));
		color: #fff;

		&__title {
			font-size: 32rpx;
			font-weight: 600;
		}

		&__amount {
			margin-top: 22rpx;
			font-size: 64rpx;
			font-weight: 600;
		}

		&__symbol {
			margin-right: 6rpx;
			font-size: 32rpx;
		}

		&__rule,
		&__date {
			margin-top: 10rpx;
			font-size: 24rpx;
			opacity: 0.92;
		}
	}

	.form-card,
	.notice-card {
		margin-top: 24rpx;
		padding: 30rpx 28rpx;
		border-radius: 20rpx;
		background: #fff;
	}

	.form-title,
	.notice-card__title {
		font-size: 30rpx;
		font-weight: 600;
	}

	.form-tip {
		margin-top: 10rpx;
		font-size: 23rpx;
		color: #999;
	}

	.phone-row {
		display: flex;
		align-items: center;
		margin-top: 26rpx;
	}

	.phone-input {
		flex: 1;
		min-width: 0;
		height: 84rpx;
		padding: 0 24rpx;
		border: 1rpx solid #ddd;
		border-radius: 14rpx;
		box-sizing: border-box;
		font-size: 28rpx;
		background: #fafafa;
	}

	.search-button {
		flex: 0 0 126rpx;
		width: 126rpx;
		height: 84rpx;
		margin-left: 16rpx;
		border-radius: 14rpx;
		background: var(--view-theme, #e93323);
		color: #fff;
		font-size: 26rpx;
		line-height: 84rpx;
		text-align: center;
	}

	.target-card {
		display: flex;
		align-items: center;
		margin-top: 26rpx;
		padding: 22rpx;
		border: 1rpx solid rgba(233, 51, 35, 0.28);
		border-radius: 16rpx;
		background: rgba(233, 51, 35, 0.04);

		&__avatar {
			width: 82rpx;
			height: 82rpx;
			border-radius: 50%;
			background: #eee;
		}

		&__content {
			flex: 1;
			min-width: 0;
			margin-left: 20rpx;
		}

		&__name {
			font-size: 29rpx;
			font-weight: 500;
		}

		&__phone {
			margin-top: 8rpx;
			font-size: 24rpx;
			color: #888;
		}

		&__status {
			color: var(--view-theme);
			font-size: 24rpx;
		}
	}

	.notice-card__item {
		margin-top: 18rpx;
		font-size: 25rpx;
		line-height: 38rpx;
		color: #777;
	}

	.footer-placeholder {
		height: 160rpx;
	}

	.footer-safe {
		position: fixed;
		left: 0;
		right: 0;
		bottom: 0;
		padding: 20rpx 24rpx calc(20rpx + constant(safe-area-inset-bottom));
		padding: 20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));
		background: #fff;
		box-shadow: 0 -4rpx 20rpx rgba(0, 0, 0, 0.05);
		z-index: 10;
	}

	.submit-button {
		height: 88rpx;
		border-radius: 44rpx;
		background: var(--view-theme, #e93323);
		color: #fff;
		font-size: 30rpx;
		line-height: 88rpx;
		text-align: center;
	}

	.disabled {
		opacity: 0.48;
	}
</style>
