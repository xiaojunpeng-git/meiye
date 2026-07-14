<template>
	<!-- 发票管理模块 -->
	<view :style="colorStyle">
		<view class="acea-row nav">
			<view class="acea-row row-center-wrapper" :class="{ on: nav === 1 }" @click="navTab(1)">发票记录</view>
			<view class="acea-row row-center-wrapper" :class="{ on: nav === 2 }" @click="navTab(2)">抬头管理</view>
		</view>
		<view class="nav-placeholder"></view>
		<view v-show="nav === 1" class="record-wrapper">
			<view v-for="item in orderList" :key="item.id" class="item">
				<view class="item-hd acea-row row-between-wrapper">
					<view>订单号：{{ item.order.order_id }}</view>
					<view class="status">{{ invoiceStatus(item.is_invoice) }}</view>
				</view>
				<view class="item-cart acea-row">
					<image :src="item.order.cartInfo[0].productInfo.image" class="image"></image>
					<view class="text">
						{{ item.order.cartInfo[0].productInfo.store_name + item.order.cartInfo[0].productInfo.attrInfo.suk || '' }}
					</view>
				</view>
				<view class="item-bd acea-row row-middle">
					<view class="text">
						<view class="">电子{{ item.type == 1 ? '普通' : '专用' }}发票-{{ item.header_type == 1 ? '个人' : '企业' }}
						</view>
						<view class="info">商家退款审核中，请您耐心等待。</view>
					</view>
					<BaseMoney :money="item.order.pay_price" symbolSize="20" integerSize="36" decimalSize="20"
						color="#333333" weight></BaseMoney>
				</view>
				<view class="item-ft acea-row row-right">
					<view class="link" v-if="item.is_invoice == -1" @tap="invoiceApply(item)">重新开票</view>
					<navigator class="link"
						:url="`/pages/users/user_invoice_order/index?order_id=${item.order.order_id}`">查看详情</navigator>
				</view>
			</view>
			<view class="px-20 mt-20" v-show="!orderList.length">
				<emptyPage title="暂无发票信息" src="/statics/images/noInvoice.gif"></emptyPage>
			</view>
		</view>
		<view v-show="nav === 2">
			<view v-if="invoiceList.length" class="list">
				<template v-for="item in invoiceList">
					<view v-if="item.type === 1 || item.type === 2 && specialInvoice" :key="item.id" class="item">
						<view class="item-hd">
							{{ item.type == 1 ? '普通' : '专用' }}发票抬头-{{ item.header_type == 1 ? '个人' : '企业' }}
						</view>
						<tui-swipe-action :operateWidth="60">
							<template v-slot:content>
								<view class="item-bd acea-row row-middle">
									<view class="item-text">
										<view class="name-wrap acea-row row-middle">
											<view class="name">{{ item.name }}</view>
											<view v-if="item.is_default" class="badge acea-row row-middle">默认</view>
										</view>
										<view v-if="item.header_type == 1">{{ item.drawer_phone }}</view>
										<view v-else>{{ item.duty_number }}</view>
									</view>
									<view class="edit acea-row row-center-wrapper" @click="editInvoice(item.id)">
										<text class="iconfont icon-ic_edit"></text>
									</view>
								</view>
							</template>
							<template v-slot:button>
								<view class="delete acea-row row-center-wrapper" @click="deleteInvoice(item.id)">删除
								</view>
							</template>
						</tui-swipe-action>
					</view>
				</template>
			</view>
			<view class="px-20 mt-20" v-show="!invoiceList.length">
				<emptyPage title="暂无发票信息" src="/statics/images/noInvoice.gif"></emptyPage>
			</view>
			<navigator class="add-link" :url="`/pages/users/user_invoice_form/index?specialInvoice=${specialInvoice}`">
				添加发票抬头</navigator>
		</view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
		<invoicePicker :inv-show="invShow" :inv-list="invoiceList" :is-special="specialInvoice" @inv-close="invClose"
			@inv-change="makeOnceInvoice">
		</invoicePicker>
	</view>
</template>

<script>
	import emptyPage from '@/components/emptyPage.vue';
	import {
		mapGetters
	} from "vuex";
	import {
		invoiceList,
		invoiceDelete,
		getUserInfo,
		makeOnceInvoice
	} from '@/api/user.js';
	import {
		orderInvoiceList
	} from '@/api/order.js';
	import colors from '@/mixins/color.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import tuiSwipeAction from '@/components/tui-swipe-action/index.vue';
	import home from '@/components/home/index.vue';
	import invoicePicker from '../components/invoicePicker/index.vue';
	export default {
		components: {
			tuiSwipeAction,
			emptyPage,
			home,
			invoicePicker
		},
		mixins: [colors],
		data() {
			return {
				orderList: [],
				invoiceList: [],
				nav: 1, // 1：发票记录 2：抬头管理
				page: 1,
				limit: 30,
				loading: false,
				finished: false,
				specialInvoice: true, // 专用发票功能开启|关闭
				imgHost: HTTP_REQUEST_URL,
				homeHide: false,
				invShow: false,
				invList: []
			};
		},
		watch: {
			nav: {
				immediate: true,
				handler(value) {
					this.page = 1;
					switch (value) {
						case 1:
							this.orderList = [];
							this.getOrderList();
							break;
						case 2:
							this.invoiceList = [];
							this.getInvoiceList();
							break;
					}
				}
			}
		},
		computed: mapGetters(['isLogin']),
		onLoad(option) {
			if (option.from === 'invoice_form') {
				this.nav = 2;
			}
			this.getUserInfo();
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			getUserInfo() {
				getUserInfo().then(res => {
					const {
						special_invoice
					} = res.data;
					this.specialInvoice = special_invoice
				});
			},
			// 菜单切换
			navTab(nav) {
				if (this.nav !== nav) {
					this.nav = nav;
				}
			},
			// 记录列表
			getOrderList() {
				uni.showLoading({
					title: '加载中'
				});
				orderInvoiceList({
					page: this.page,
					limit: this.limit
				}).then(res => {
					const {
						data
					} = res;
					uni.hideLoading();
					this.orderList = this.orderList.concat(data);
					this.finished = data.length < this.limit;
					this.page += 1;
				}).catch(err => {
					uni.showToast({
						title: err.msg,
						icon: 'none'
					});
				});
			},
			// 发票列表
			getInvoiceList() {
				uni.showLoading({
					title: '加载中'
				});
				invoiceList().then(res => {
					uni.hideLoading();
					this.invoiceList = res.data.map(item => {
						item.id = String(item.id);
						return item;
					});
				}).catch(err => {
					uni.hideLoading();
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
			},
			// 编辑发票
			editInvoice(id) {
				uni.navigateTo({
					url: `/pages/users/user_invoice_form/index?id=${id}`
				});
			},
			// 删除发票
			deleteInvoice(id) {
				let that = this;
				uni.showModal({
					content: '删除该发票？',
					confirmColor: '#E93323',
					success(res) {
						if (res.confirm) {
							invoiceDelete(id).then(() => {
								that.$util.Tips({
									title: '删除成功',
									icon: 'success'
								}, () => {
									let index = that.invoiceList.findIndex(value => {
										return value.id == id;
									});
									that.invoiceList.splice(index, 1);
								});
							}).catch(err => {
								return that.$util.Tips({
									title: err
								});
							});
						}
					}
				});
			},
			onhomehide() {
				this.homeHide = false;
			},
			invoiceStatus(isInvoice) {
				return isInvoice ? (isInvoice == 1 ? '已开票' : '已拒绝') : '未开票';
			},
			makeOnceInvoice(invoice_id) {
				makeOnceInvoice({
					id: this.currentItemId,
					invoice_id
				}).then(res => {
					uni.showToast({
						title: '申请成功',
						icon: 'success',
						success: () => {
							this.invShow = false;
							const index = this.orderList.findIndex(item => item.id == this.currentItemId);
							if (-1 < index) {
								this.orderList[index].is_invoice = 0;
							}
						}
					});
				}).catch(err => {
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
			},
			// 重新开票
			invoiceApply(item) {
				this.invShow = true;
				this.currentItemId = item.id;
				this.getInvoiceList();
			},
			invClose() {
				this.invShow = false;
			}
		}
	}
</script>

<style lang="scss" scoped>
	.nav {
		position: fixed;
		top: 0;
		left: 0;
		z-index: 9;
		width: 100%;
		height: 90rpx;
		background-color: #F5F5F5;
	}

	.nav .acea-row {
		position: relative;
		flex: 1;
		font-size: 28rpx;
		color: #333333;

		&::after {
			content: "";
			position: absolute;
			bottom: 8rpx;
			left: 50%;
			width: 116rpx;
			height: 6rpx;
			border-radius: 6rpx;
			background-color: transparent;
			transform: translateX(-50%);
		}
	}

	.nav .on {
		font-weight: 500;
		font-size: 30rpx;
		color: var(--view-theme);

		&::after {
			background-color: var(--view-theme);
		}
	}

	.nav-placeholder {
		height: 90rpx;
	}

	.list {
		padding: 14rpx 32rpx;
		margin-bottom: 140rpx;
	}

	.list .item {
		border-radius: 24rpx;
		background-color: #FFFFFF;
		overflow: hidden;
	}

	.list .item~.item {
		margin-top: 14rpx;
	}

	.list .item-hd {
		position: relative;
		padding: 24rpx;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #333333;

		&::after {
			content: "";
			position: absolute;
			right: 24rpx;
			bottom: 0;
			left: 24rpx;
			height: 1px;
			background-color: #F5F5F5;
		}
	}

	.list .item-hd .acea-row {
		flex: 1;
		min-width: 0;
	}

	.list .item-bd {
		padding: 32rpx 24rpx;
	}

	.list .item-text {
		flex: 1;
		min-width: 0;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #999999;
	}

	.list .name-wrap {
		display: inline-flex;
		max-width: 100%;
		margin-bottom: 12rpx;
	}

	.list .name {
		flex: 1;
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		font-weight: 500;
		font-size: 28rpx;
		line-height: 40rpx;
		color: #333333;
	}

	.list .badge {
		height: 34rpx;
		padding: 0 8rpx;
		border-radius: 8rpx;
		margin-left: 8rpx;
		background-color: var(--view-minorColorT);
		font-size: 22rpx;
		color: var(--view-theme);
	}

	.list .edit {
		width: 56rpx;
		height: 56rpx;
		border-radius: 50%;
		background-color: #F5F5F5;

		.iconfont {
			font-size: 32rpx;
			color: #333333;
		}
	}

	.list .delete {
		width: 120rpx;
		height: 100%;
		background-color: var(--view-theme);
		font-size: 24rpx;
		color: #FFFFFF;
	}

	.list .type.special {
		background-color: #FDE9E7;
		color: #E93323;
	}

	.list .cell {
		font-size: 26rpx;
		color: #666666;
	}

	.list .cell~.cell {
		margin-top: 12rpx;
	}

	.list .item-ft {
		margin-top: 11rpx;
	}

	.list .btn {
		font-size: 26rpx;
		color: #282828;
		cursor: pointer;
	}

	.list .btn~.btn {
		margin-left: 35rpx;
	}

	.list .btn .iconfont {
		margin-right: 10rpx;
		font-size: 24rpx;
		color: #000000;
	}

	.add-link {
		position: fixed;
		right: 30rpx;
		bottom: calc(20rpx + env(safe-area-inset-bottom));
		left: 30rpx;
		height: 86rpx;
		border-radius: 43rpx;
		background-color: var(--view-theme);
		font-size: 30rpx;
		line-height: 86rpx;
		text-align: center;
		color: #FFFFFF;

		.iconfont {
			margin-right: 14rpx;
			font-size: 28rpx;
		}
	}

	.record-wrapper {
		.item {
			padding: 32rpx 24rpx;
			border-radius: 24rpx;
			margin: 20rpx;
			background-color: #FFFFFF;

			.item-hd {
				font-size: 28rpx;
				line-height: 40rpx;
				color: #333333;

				.status {
					font-weight: 500;
				}
			}

			.item-cart {
				margin-top: 32rpx;

				.image {
					width: 104rpx;
					height: 104rpx;
					border-radius: 16rpx;
				}

				.text {
					flex: 1;
					padding-left: 20rpx;
					font-size: 28rpx;
					line-height: 40rpx;
					color: #333333;
				}
			}

			.item-bd {
				padding: 24rpx;
				border-radius: 16rpx;
				margin-top: 32rpx;
				background-color: #F5F5F5;
				font-size: 26rpx;
				line-height: 36rpx;
				color: #333333;

				.text {
					flex: 1;
				}

				.info {
					margin-top: 8rpx;
					font-size: 24rpx;
					line-height: 34rpx;
					color: #999999;
				}
			}

			.item-ft {
				margin-top: 32rpx;

				.link {
					height: 56rpx;
					padding: 0 24rpx;
					border: 1rpx solid #CCCCCC;
					border-radius: 56rpx;
					margin-left: 24rpx;
					font-size: 24rpx;
					line-height: 54rpx;
					color: #333333;

					&:first-child {
						margin-left: 0;
					}
				}
			}
		}
	}
</style>