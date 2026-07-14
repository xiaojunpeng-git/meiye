<template>
	<!-- 选择发票信息下拉列表 -->
	<view>
		<view :class="{ mask: invShow }" @touchmove.stop.prevent @click="invClose"></view>
		<view class="popup" :class="{ on: invShow }" @touchmove.stop.prevent>
			<view class="popup-hd">抬头选择<view class="close" @click="invClose"><text class="iconfont icon-ic_close"></text></view>
			</view>
			<scroll-view class="popup-bd" scroll-y="true">
				<radio-group v-if="invList.length" name="inv" @change="invChange">
					<template v-for="(item, index) in invList">
						<label v-if="item.type === 1 || item.type === 2 && isSpecial" :key="item.id" :class="{ checked: (invChecked || invId) == item.id }" class="acea-row row-middle item">
							<text class="iconfont icon-ic_complete"></text>
							<radio class="radio" :value="item.id" :checked="item.id === invChecked" />
							<view class="text">
								<view class="acea-row row-middle type">{{ item.type === 1 ? '普通' : '专用' }}发票抬头-{{ item.header_type === 1 ? '个人' : '企业' }}</view>
								<view class="acea-row row-middle text-bottom">
									<view class="text-left">
										<view class="acea-row row-middle name-wrap">
											<view class="name">{{item.name}}</view>
											<view v-if="item.is_default" class="default">默认</view>
										</view>
										<view class="number">{{item.header_type == 1 ? item.drawer_phone : item.duty_number}}</view>
									</view>
									<navigator v-if="!isOrder" class="navigator acea-row row-center-wrapper" :url="`/pages/users/user_invoice_form/index?from=order_confirm&id=${item.id}&${urlQuery}`"
										hover-class="none"><text class="iconfont icon-ic_edit"></text></navigator>
									<navigator v-else class="navigator acea-row row-center-wrapper" :url="`/pages/users/user_invoice_form/index?from=order_details&id=${item.id}&order_id=${orderId}`"
										hover-class="none"><text class="iconfont icon-ic_edit"></text></navigator>
								</view>
							</view>
						</label>
					</template>
				</radio-group>
				<view v-else class="empty">
					<image class="image" :src="imgHost + '/statics/images/noInvoice.png'"></image>
					<view>您还没有添加发票信息哟~</view>
				</view>
			</scroll-view>
			<view class="popup-ft">
				<navigator v-if="!isOrder" class="navigator" :url="`/pages/users/user_invoice_form/index?from=order_confirm&${urlQuery}`" hover-class="none">添加新的抬头</navigator>
				<navigator v-else class="navigator" :url="`/pages/users/user_invoice_form/index?order_id=${orderId}&from=order_details`" hover-class="none">添加新的抬头</navigator>
				<button class="button" plain @click="invCancel">不开发票</button>
				<button v-if="isOrder && invList.length" class="button" plain @click="invSub">确认提交</button>
				<slot name="buttom"></slot>
			</view>
		</view>
	</view>
</template>

<script>
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		data() {
			return {
				invId: 0,
				imgHost: HTTP_REQUEST_URL
			}
		},
		props: {
			invShow: {
				type: Boolean,
				default: false
			},
			invList: {
				type: Array,
				default () {
					return [];
				}
			},
			invChecked: {
				type: String,
				default: ''
			},
			isSpecial: {
				type: Boolean,
				default: false
			},
			urlQuery: {
				type: String,
				default: ''
			},
			isOrder: {
				type: Number,
				default: 0
			},
			orderId: {
				type: String,
				default: ''
			}
		},
		methods: {
			invClose(state) {
				this.$emit('inv-close');
			},
			invChange(e) {
				if (this.isOrder) {
					this.invId = e.detail.value
				} else {
					this.$emit('inv-change', e.detail.value);
				}
			},
			invSub() {
				this.$emit('inv-change', this.invId || this.invChecked);
			},
			invCancel() {
				this.$emit('inv-cancel');
			}
		},
	}
</script>

<style lang="scss" scoped>
	/deep/uni-radio .uni-radio-input {
		margin-right: 0;
	}

	.popup {
		position: fixed;
		bottom: 0;
		left: 0;
		z-index: 999;
		width: 100%;
		border-top-left-radius: 32rpx;
		border-top-right-radius: 32rpx;
		background-color: #F5F5F5;
		transform: translateY(100%);
		transition: 0.3s;
	}

	.popup.on {
		transform: translateY(0);
	}

	.popup-hd {
		position: relative;
		height: 108rpx;
		font-size: 32rpx;
		line-height: 108rpx;
		text-align: center;
		color: #333333;

		.close {
			position: absolute;
			top: 50%;
			right: 32rpx;
			width: 36rpx;
			height: 36rpx;
			border-radius: 18rpx;
			margin-top: -16rpx;
			background-color: #EEEEEE;
			text-align: center;
			line-height: 36rpx;
		}

		.iconfont {
			font-size: 24rpx;
			color: #999999;
		}
	}

	.popup-bd {
		height: 792rpx;
		padding: 24rpx 20rpx 8rpx;
		box-sizing: border-box;

		.item {
			position: relative;
			border: 1rpx solid #FFFFFF;
			border-radius: 24rpx;
			margin-bottom: 20rpx;
			background-color: #FFFFFF;
			overflow: hidden;

			&::after {
				content: "";
				position: absolute;
				top: 0;
				right: 0;
				border: 36rpx solid #FFFFFF;
			}

			.icon-ic_complete {
				position: absolute;
				top: 8rpx;
				right: 8rpx;
				z-index: 2;
				font-size: 32rpx;
				color: #FFFFFF;
			}

			&.checked {
				border-color: var(--view-theme);

				&::after {
					border-color: var(--view-theme);
					border-bottom-color: transparent;
					border-left-color: transparent;
				}
			}
		}

		.radio {
			display: none;
		}

		.text {
			flex: 1;
			min-width: 0;
		}

		.text-bottom {
			position: relative;
			padding: 32rpx 24rpx;

			&::before {
				content: "";
				position: absolute;
				top: 0;
				right: 24rpx;
				left: 24rpx;
				border-top: 1rpx solid #eee;
			}
		}

		.name-wrap {
			display: inline-flex;
			max-width: 100%;
		}

		.name {
			flex: 1;
			overflow: hidden;
			white-space: nowrap;
			text-overflow: ellipsis;
			font-weight: 500;
			font-size: 28rpx;
			line-height: 40rpx;
			color: #333333;
		}

		.default {
			height: 34rpx;
			padding: 0 8rpx;
			border-radius: 8rpx;
			margin-left: 8rpx;
			background-color: var(--view-minorColorT);
			font-size: 22rpx;
			line-height: 34rpx;
			color: var(--view-theme);
		}

		.email {
			margin-top: 16rpx;
			font-size: 24rpx;
			color: #666666;
		}

		.number {
			margin-top: 12rpx;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #999999;
		}

		.text-left {
			flex: 1;
			min-width: 0;
		}

		.type {
			padding: 24rpx;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #333333;
		}

		.navigator {
			width: 56rpx;
			height: 56rpx;
			border-radius: 50%;
			margin-left: 24rpx;

			.iconfont {
				font-size: 32rpx;
				color: #333333;
			}
		}
	}

	.popup-ft {
		padding: 20rpx 20rpx calc(20rpx + env(safe-area-inset-bottom));

		.navigator {
			height: 80rpx;
			border-radius: 40rpx;
			background-color: var(--view-theme);
			font-size: 28rpx;
			line-height: 80rpx;
			text-align: center;
			color: #FFFFFF;

			.iconfont {
				margin-right: 14rpx;
				font-size: 30rpx;
			}
		}

		.button {
			height: 80rpx;
			border: 1rpx solid var(--view-theme);
			border-radius: 40rpx;
			margin-top: 24rpx;
			font-size: 28rpx;
			line-height: 80rpx;
			color: var(--view-theme);
		}
	}

	.empty {
		padding-top: 58rpx;
		font-size: 26rpx;
		text-align: center;
		color: #999999;

		.image {
			width: 400rpx;
			height: 260rpx;
			margin-bottom: 20rpx;
		}
	}

	.mask {
		z-index: 990;
	}
</style>