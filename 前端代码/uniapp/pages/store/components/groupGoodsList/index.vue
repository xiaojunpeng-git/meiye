<template>
	<view v-if="goodsList.length" class="goods-list">
		<view v-for="item in goodsList" :key="item.userInfo.uid" class="item">
			<view class="head acea-row row-middle">
				<image class="avatar" :src="item.userInfo.avatar || '/static/images/f.png'" mode="aspectFill"></image>
				<view class="name-wrap">
					<view class="name-inner">
						<view class="name line1">{{ item.userInfo.nickname }}</view>
						<view v-if="item.userInfo.uid == uid">（我）</view>
					</view>
				</view>
			</view>
			<view class="body">
				<view v-for="cell in item.goods" :key="cell.id" class="cell acea-row">
					<image class="image" :src="cell.productInfo.attrInfo.image" mode="aspectFill"></image>
					<view class="name-wrap">
						<view class="name line2">{{ cell.productInfo.store_name }}</view>
						<view class="attr line1">{{ cell.productInfo.attrInfo.suk }}</view>
					</view>
					<view class="money-wrap">
						<BaseMoney :money="cell.truePrice" symbolSize="20" integerSize="32" decimalSize="20" color="#333333"></BaseMoney>
						<view class="num">共{{ cell.cart_num }}件</view>
					</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import {
		mapGetters
	} from 'vuex';

	export default {
		props: {
			goodsList: {
				type: Array,
				default: () => {
					return [];
				}
			},
		},
		computed: mapGetters(['uid']),
	};
</script>

<style lang="scss" scoped>
	.goods-list {
		.item {
			padding: 0 24rpx;
			border-radius: 24rpx;
			margin-top: 20rpx;
			background: #FFFFFF;

			&:first-child {
				margin-top: 0;
			}

			.head {
				height: 108rpx;
				border-bottom: 1rpx solid #F5F5F5;
				transform: rotateZ(360deg);
				font-size: 26rpx;
				color: #333333;

				.name-wrap {
					flex: 1;
					min-width: 0;
					padding-left: 20rpx;
				}

				.name-inner {
					display: inline-flex;
					align-items: center;
					max-width: 100%;
				}

				.name {
					flex: 1;
					min-width: 0;
				}
			}

			.avatar {
				width: 40rpx;
				height: 40rpx;
				border-radius: 50%;
			}

			.body {
				padding: 32rpx 0;

				.cell {
					margin-top: 32rpx;

					&:first-child {
						margin-top: 0;
					}

					.name-wrap {
						flex: 1;
						min-width: 0;
						padding: 0 20rpx;
					}

					.name {
						font-size: 28rpx;
						line-height: 40rpx;
						color: #333333;
					}

					.attr {
						margin-top: 12rpx;
						font-size: 24rpx;
						line-height: 34rpx;
						color: #999999;
					}
				}

				.image {
					width: 136rpx;
					height: 136rpx;
					border-radius: 16rpx;
				}

				.money-wrap {
					text-align: right;
				}

				.num {
					margin-top: 10rpx;
					font-size: 24rpx;
					line-height: 34rpx;
					color: #999999;
				}
			}
		}
	}
</style>