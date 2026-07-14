<template>
	<!-- 分类三商品列表 -->
	<view class="goodsList">
		<view class="item acea-row" v-for="(item,index) in tempArr" :key="item.id" @click="goDetail(item)">
			<view class="pictrue">
				<image :src="item.image" mode="aspectFit"></image>
			</view>
			<view class="pictxt acea-row row-column">
				<view class="text line1">{{item.store_name}}</view>
				<view class="acea-row label-wrap">
					<BaseTag v-for="label in item.store_label" :key="label.id" :text="label.label_name" :color="label.color" :borderColor="label.border_color" :background="label.bg_color"
						:circle="!!label.border_color" :imgSrc="label.icon"></BaseTag>
				</view>
				<view class="bottom acea-row row-between row-bottom">
					<view class="acea-row items-baseline flex-1">
						<BaseMoney :money="item.price" symbolSize="28" integerSize="44" decimalSize="28"></BaseMoney>
						<view class="inline-block h-26 lh-28rpx rd-14rpx bg--w111-F7E9CD fs-22 ml-8"
							v-if="Number(item.vip_price) > 0">
							<text class="inline-block h-26 lh-28rpx svip-rd fs-18 bg-w111-484643 text-w111-FDDAA4 px-8">SVIP</text>
							<text class="px-8 fs-22 SemiBold">¥{{item.vip_price}}</text>
						</view>
					</view>
					<template v-if="item.stock>0">
						<view class="btn-box"
							v-if="(item.activity && (item.activity.type === '1' || item.activity.type === '2' || item.activity.type === '3')) || item.product_type!=0 || item.custom_form.length || item.is_presale_product">
							<text class="iconfont icon-ic_ShoppingCart1"></text>
						</view>
						<template v-else>
							<!-- 多规格 -->
							<uni-badge v-if="item.spec_type" class="uni-badge-left-margin" :text="item.cart_num" absolute="rightTop">
								<view class="btn-box" @click.stop="goCartDuo(item)">
									<text class="iconfont icon-ic_ShoppingCart1"></text>
								</view>
							</uni-badge>
							<!-- 单规格 -->
							<template v-else>
								<view v-if="item.cart_num" class="cart acea-row">
									<view class="btn acea-row row-center-wrapper" @click.stop="CartNumDes(index,item)">
										<text class="iconfont icon-ic_Reduce"></text>
									</view>
									<view class="num">{{item.cart_num}}</view>
									<view class="btn acea-row row-center-wrapper" @click.stop="CartNumAdd(index,item)">
										<text class="iconfont icon-ic_increase"></text>
									</view>
								</view>
								<view v-else>
									<view v-if="item.cart_button">
										<view class="bnt acea-row row-center-wrapper end" v-if="item.is_presale_product && (item.presale_pay_status == 1 || item.presale_pay_status == 3)">
											{{item.presale_pay_status === 1?'未开始':'已结束'}}
										</view>
										<view class="btn-box acea-row row-center-wrapper" v-else @click.stop="goCartDan(item,index)">
											<text class="iconfont icon-ic_ShoppingCart1"></text>
										</view>
									</view>
									<view v-else class="bnt acea-row row-center-wrapper">立即购买</view>
								</view>
							</template>
						</template>
					</template>
					<view class="bnt acea-row row-center-wrapper end" v-else>已售罄</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	export default {
		name: 'd_goodList',
		props: {
			dataConfig: {
				type: Object,
				default: () => {}
			},
			tempArr: {
				type: Array,
				default: () => []
			},
			isLogin: {
				type: Boolean,
				default: false
			}
		},
		data() {
			return {};
		},
		methods: {
			goDetail(item) {
				this.$emit('detail', item);
			},
			goCartDuo(item) {
				this.$emit('gocartduo', item);
			},
			goCartDan(item, index) {
				this.$emit('gocartdan', item, index);
			},
			CartNumDes(index, item) {
				this.$emit('ChangeCartNumDan', false, index, item);
			},
			CartNumAdd(index, item) {
				if (item.is_limit && item.cart_num >= item.limit_num) {
					this.$util.Tips({
						title: "购买最多不能超过" + item.limit_num
					});
				} else {
					this.$emit('ChangeCartNumDan', true, index, item);
				}
			}
		}
	};
</script>

<style lang="scss">
	.goodsList {
		padding: 0 30rpx;

		.item {
			flex-wrap: nowrap;
			margin-bottom: 24rpx;

			.pictrue {
				width: 176rpx;
				height: 176rpx;
				position: relative;

				image {
					width: 100%;
					height: 100%;
					border-radius: 16rpx;
				}
			}

			.pictxt {
				flex: 1;
				flex-wrap: nowrap;
				min-width: 0;
				padding-left: 20rpx;

				.text {
					font-size: 28rpx;
					line-height: 40rpx;
					color: #333333;
				}

				.label-wrap {
					flex: 1;
					min-height: 0;
					padding-top: 12rpx;
				}

				.bottom {
					.cart {
						.btn {
							width: 48rpx;
							height: 48rpx;
							border-radius: 50%;
							background: var(--view-theme);
							text-align: center;
							line-height: 48rpx;

							.iconfont {
								font-size: 28rpx;
								color: #FFFFFF;
							}
						}

						.num {
							width: 60rpx;
							height: 48rpx;
							text-align: center;
							font-weight: 500;
							font-size: 30rpx;
							line-height: 48rpx;
							color: #333333;
						}
					}

					.btn-box {
						width: 48rpx;
						height: 48rpx;
						border-radius: 50%;
						background-color: var(--view-theme);
						text-align: center;
						line-height: 48rpx;
					}

					.icon-ic_ShoppingCart1 {
						color: #fff;
						font-size: 30rpx;
					}

					.bnt {
						padding: 0 20rpx;
						height: 45rpx;
						background: var(--view-theme);
						border-radius: 23rpx;
						font-size: 22rpx;
						color: #fff;
						position: relative;

						&.end {
							background: #cccccc;
						}

						.num {
							min-width: 14rpx;
							height: 24rpx;
							text-align: center;
							line-height: 24rpx;
							background-color: #fff;
							color: var(--view-theme);
							border-radius: 15px;
							position: absolute;
							right: -13rpx;
							top: -11rpx;
							font-size: 16rpx;
							padding: 0 5rpx;
							border: 1px solid var(--view-theme);
						}
					}
				}
			}
		}
	}
</style>