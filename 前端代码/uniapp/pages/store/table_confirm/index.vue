<template>
	<view :style="colorStyle">
		<view :style="{ backgroundPositionY: `${statusBarHeight + navBarHeight}px` }" class="confirm-order">
			<view class="nav-bar fixed">
				<view :style="{ height: `${statusBarHeight}px` }"></view>
				<view :style="{ height: `${navBarHeight}px` }" class="inner">
					<navigator class="btn" open-type="navigateBack" hover-class="none">
						<text class="iconfont icon-ic_leftarrow fs-39"></text>
					</navigator>
					<view>提交订单</view>
				</view>
			</view>
			<view class="header">
				<view class="top acea-row row-middle">
					<view class="name">{{ codeData.storeName.storeName }}</view>
					<view class="number"><text class="light">{{ codeData.category.name }}{{ codeData.table_number }}</text>号桌</view>
				</view>
				<view>{{ codeData.serial_number }}</view>
			</view>
			<view class="dining acea-row row-between row-middle">
				<view class="total">共点{{ goodsNumber }}份</view>
				<view>{{ store_number_diners_window ? codeData.number_diners : tablePartake.length }}人就餐</view>
			</view>
			<view v-for="item in tablePartake" :key="item.userInfo.uid" class="dinner">
				<view class="head acea-row row-middle">
					<image class="image" :src="item.userInfo.avatar || '/static/images/f.png'" mode="aspectFill"></image>
					<view class="name">{{ item.userInfo.nickname }}</view>
					<!-- <view class="name">已点7份</view> -->
					<view>{{ item.order_time }}下单</view>
				</view>
				<view class="body">
					<view v-for="goods in item.goods" :key="goods.id" :class="{ gray: !goods.is_true_stock }" class="item acea-row">
						<image class="image" :src="goods.productInfo.image" mode="aspectFill"></image>
						<view class="text">
							<view class="name line2">{{ goods.productInfo.store_name }}</view>
							<view class="attr">{{ goods.productInfo.attrInfo.suk }}</view>
							<!-- <view class="money">￥{{ goods.truePrice }}</view> -->
						</view>
						<view class="">
							<BaseMoney :money="goods.truePrice" symbolSize="20" integerSize="32" decimalSize="20" color="#333333"></BaseMoney>
							<view class="number">共{{ goods.cart_num }}件</view>
						</view>
					</view>
				</view>
			</view>
			<view class="footer">
				<view class="inner"></view>
			</view>
			<view class="footer fixed">
				<view class="inner acea-row row-middle">
					<view class="total">
						合计：<BaseMoney :money="total" symbolSize="28" integerSize="44" decimalSize="28"></BaseMoney>
					</view>
					<button class="btn" hover-class="none" @tap="getTableCode(onAdd)">加购</button>
					<button class="btn fill" hover-class="none" @tap="getTableCode(openDialog)">去结算</button>
				</view>
			</view>
		</view>
		<view class="mask" v-if="dialogShow"></view>
		<view class="dialog" :class="{ show: dialogShow }">
			<view class="title">是否结账</view>
			<view class="info">您是否确认结算当前账单</view>
			<view class="btn-box acea-row">
				<view class="btn" @click="dialogShow = false">取消</view>
				<view class="btn primary" @click="confirm">确认</view>
			</view>
		</view>
	</view>
</template>

<script>
	import colors from '@/mixins/color.js';
	import {
		getCodeData,
		getTableCode,
		getTablePartake,
		settleTable,
	} from '@/api/store.js';

	export default {
		mixins: [colors],
		data() {
			return {
				statusBarHeight: 0,
				navBarHeight: 0,
				tablePartake: [],
				codeData: {},
				dialogShow: false,
				store_number_diners_window: 0,
			}
		},
		computed: {
			goodsNumber() {
				let goodsNumber = 0;
				this.tablePartake.forEach(item => {
					goodsNumber = item.goods.reduce((total, cell) => {
						return total + cell.cart_num;
					}, goodsNumber);
				})
				return goodsNumber;
			},
			total() {
				let total = 0;
				this.tablePartake.forEach(item => {
					total = item.goods.reduce((total, cell) => {
						return this.$util.$h.Add(total, this.$util.$h.Mul(cell.truePrice, cell.cart_num));
					}, total);
				});
				return total;
			}
		},
		onLoad(option) {
			const {
				tableId,
				store_number_diners_window,
			} = option;
			let {
				height,
				top
			} = uni.getMenuButtonBoundingClientRect();
			uni.getSystemInfo({
				success: ({
					statusBarHeight
				}) => {
					this.statusBarHeight = statusBarHeight;
					this.navBarHeight = 2 * (top - statusBarHeight) + height;
				}
			});
			this.tableId = tableId;
			this.store_number_diners_window = parseInt(store_number_diners_window);
			this.getCodeData();
			this.getTablePartake();
		},
		onPullDownRefresh() {
			this.getTablePartake();
		},
		methods: {
			// 获取桌码信息
			getCodeData() {
				getCodeData({
					tableId: this.tableId
				}).then(res => {
					this.codeData = res.data;
				});
			},
			getTablePartake(callback) {
				getTablePartake({
					tableId: this.tableId
				}).then(({
					data
				}) => {
					let tablePartake = [];
					Object.keys(data).forEach(key => {
						tablePartake.push(data[key]);
					});
					this.tablePartake = tablePartake;
					uni.stopPullDownRefresh();
					if (callback) {
						let is_true_stock = true;
						for (let partakeItem of this.tablePartake) {
							if (!is_true_stock) {
								break;
							}
							for (let goodsItem of partakeItem.goods) {
								is_true_stock = goodsItem.is_true_stock;
								if (!is_true_stock) {
									break;
								}
							}
						}
						if (!is_true_stock) {
							return this.$util.Tips({
								title: '存在库存不足的商品，请检查'
							});
						}
						callback();
					}
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				});
			},
			settleTable() {
				settleTable({
					tableId: this.tableId
				}).then(res => {
					uni.navigateTo({
						url: `/pages/goods/order_confirm/index?new=1&delivery_type=2&cartId=${res.data.cartIds}&store_id=${this.codeData.store_id}&tableId=${this.tableId}`
					});
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				});
			},
			// 去结账
			openDialog() {
				this.dialogShow = true;
			},
			confirm() {
				this.dialogShow = false;
				this.getTablePartake(this.settleTable);
			},
			// 加购
			onAdd() {
				uni.navigateTo({
					url: `/pages/store/table_code/index?store_id=${this.codeData.store_id}&qrcode_id=${this.codeData.id}`
				});
			},
			getTableCode(callback) {
				getTableCode({
					tableId: this.tableId
				}).then(res => {
					const status = res.data.table.status;
					switch (status) {
						case -1:
						case 2:
						case 3:
							this.$util.Tips({
								title: status == -1 ? '本单已取消' : '本单已结账'
							});
							break;
						default:
							callback();
							break;
					}
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				});
			}
		},
	}
</script>

<style>
	page {
		background-color: #F5F5F5;
	}
</style>

<style lang="scss" scoped>
	.mask {
		z-index: 10;
	}

	.confirm-order {
		background: linear-gradient(180deg, var(--view-theme) 0%, #F5F5F5 100%);
		background-size: 100% 292rpx;
		background-repeat: no-repeat;
	}

	.nav-bar {
		background-color: var(--view-theme);

		&.fixed {
			position: sticky;
			top: 0;
			right: 0;
			left: 0;
			z-index: 9;
		}

		.inner {
			position: relative;
			display: flex;
			justify-content: center;
			align-items: center;
			font-size: 30rpx;
			color: #FFFFFF;
		}

		.btn {
			position: absolute;
			top: 0;
			bottom: 0;
			left: 0;
			display: flex;
			align-items: center;
			padding: 0 30rpx;
			font-size: 24rpx;
			color: #FFFFFF;
		}
	}

	.header {
		padding: 32rpx;
		border-radius: 24rpx;
		margin: 20rpx;
		background: #FFFFFF;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #999999;

		.top {
			margin-bottom: 12rpx;
		}

		.name {
			flex: 1;
			min-width: 0;
			font-weight: 500;
			font-size: 30rpx;
			line-height: 42rpx;
			color: #333333;
		}

		.number {
			font-size: 22rpx;
			line-height: 42rpx;
			color: #333333;
		}

		.light {
			font-weight: 500;
			font-size: 30rpx;
			color: var(--view-theme);
		}
	}

	.dining {
		height: 100rpx;
		padding: 0 24rpx 0 32rpx;
		border-radius: 24rpx;
		margin: 20rpx;
		background: #FFFFFF;
		font-size: 24rpx;
		color: #333333;

		.total {
			font-weight: 500;
			font-size: 30rpx;
			color: #3D3D3D;
		}
	}

	.dinner {
		border-radius: 24rpx;
		margin: 20rpx;
		background: #FFFFFF;

		.head {
			padding: 35rpx 24rpx;
			font-size: 28rpx;
			color: #333333;

			.image {
				display: block;
				width: 36rpx;
				height: 36rpx;
				border-radius: 50%;
			}

			.name {
				flex: 1;
				min-width: 0;
				padding: 0 10rpx;
			}
		}

		.body {
			padding: 0 24rpx 32rpx;
		}

		.item {
			position: relative;
			margin-top: 32rpx;

			&:first-child {
				margin-top: 0;
			}

			&.gray {
				background-color: #EEEEEE;
			}

			.image {
				display: block;
				width: 136rpx;
				height: 136rpx;
				border-radius: 16rpx;
			}

			.text {
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

			.number {
				margin-top: 10rpx;
				text-align: right;
				font-size: 24rpx;
				line-height: 34rpx;
				color: #999999;
			}
		}
	}

	.footer {
		padding-bottom: constant(safe-area-inset-bottom);
		padding-bottom: env(safe-area-inset-bottom);

		&.fixed {
			position: fixed;
			right: 0;
			bottom: 0;
			left: 0;
			z-index: 9;
			background: #FFFFFF;
		}

		.inner {
			height: 96rpx;
			padding: 0 32rpx;
		}

		.total {
			flex: 1;
			min-width: 0;
			font-weight: 500;
			font-size: 28rpx;
			color: #333333;
		}

		.btn {
			transform: rotateZ(360deg);
			width: 142rpx;
			height: 72rpx;
			border: 1rpx solid var(--view-theme);
			border-radius: 36rpx;
			font-weight: 500;
			font-size: 26rpx;
			line-height: 70rpx;
			color: var(--view-theme);

			+.btn {
				margin-left: 16rpx;
			}

			&.fill {
				border: 0;
				background: linear-gradient(90deg, var(--view-gradient) 0%, var(--view-theme) 100%);
				line-height: 72rpx;
				color: #FFFFFF;
			}
		}
	}

	.dialog {
		position: fixed;
		top: 50%;
		right: 75rpx;
		left: 75rpx;
		z-index: 11;
		display: none;
		border-radius: 32rpx;
		background: #FFFFFF;
		overflow: hidden;
		transform: translateY(-50%);
		text-align: center;

		&.show {
			display: block;
		}

		.title {
			padding: 40rpx 32rpx 24rpx;
			font-weight: 500;
			font-size: 32rpx;
			line-height: 52rpx;
			color: #333333;
		}

		.info {
			padding: 0 40rpx;
			font-size: 30rpx;
			line-height: 42rpx;
			color: #666666;
		}

		.btn-box {
			padding: 40rpx;
		}

		.btn {
			flex: 1;
			height: 72rpx;
			border: 1rpx solid var(--view-theme);
			border-radius: 36rpx;
			font-weight: 500;
			font-size: 26rpx;
			line-height: 70rpx;
			color: var(--view-theme);
			transform: rotateZ(360deg);

			+.btn {
				margin-left: 32rpx;
			}

			&.primary {
				background: var(--view-theme);
				color: #FFFFFF;
			}
		}
	}
</style>