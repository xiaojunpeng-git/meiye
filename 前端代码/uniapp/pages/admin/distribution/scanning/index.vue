<template>
	<view>
		<view class="scan">
			<view class="header">请选择要核销的订单</view>
			<view class="box">
				<view class="content" v-for="(item,index) in list" :key="index" @click="jump(item)">
					<view class="acea-row row-between">
						<view class="orange" v-if="(item._status == 4 || item._status == 12) && item.status == 5">部分核销</view>
						<view class="attributes blue" v-if="item._status == 4 && item.status == 1">未核销</view>
						<view class="attributes blue" v-if="item._status == 11">未核销</view>
						<view class="attributes blue" v-if="item._status == 5">已核销</view>
						<view class="btn" @click.stop="goRecord(item.id)">核销记录<text class="iconfont icon-ic_rightarrow"></text></view>
					</view>
					<view class="content_box acea-row">
						<image :src="item.image" mode=""></image>
						<view class="content_box_title acea-row row-column row-between">
							<p class="textbox">订单号：{{ item.order_id }}</p>
							<p class="attribute">下单时间：{{ item.add_time }}</p>
							<view class="txt">
								<p class="attribute">订单实付：¥{{ item.pay_price }}</p>
							</view>
						</view>
					</view>
				</view>
			</view>
		</view>
		<view class="mask" v-if="popupShow" @click="closePopup"></view>
		<view class="popup acea-row row-column on" v-if="popupShow">
			<view class="popup-hd">
				商品核销
				<view class="btn" @click="closePopup">
					<text class="iconfont icon-ic_close"></text>
				</view>
			</view>
			<writeOffList ref="writeOffList" class="popup-bd" :list="orderInfo" :auth="auth" :oid="id" @success="writeSuccess" @callConfirm="callConfirmHandle"></writeOffList>
		</view>
		<view v-if="box">
			<view class="boxs">
				<view class="small_box">
					<view class="content">
						<view class="font">核销成功</view>
						<view v-if="isAll" class="small_font">当前订单已完成核销</view>
						<view v-else class="small_font">该订单仍有其他待核销商品</view>
					</view>
					<view class="acea-row btn-box">
						<view v-if="!isAll" class="btn" @click="ok(1)">返回列表</view>
						<view v-if="isAll" class="btn btn_no" @click="ok(4)">{{ from == 'delivery' ? '返回配送员' : '返回工作台' }}</view>
						<view v-if="!isAll" class="btn on" @click="ok(2)">继续核销</view>
						<view v-if="isAll" class="btn on" @click="ok(3)">核销其他订单</view>
					</view>
				</view>
			</view>
		</view>
		<tuiModal
		  :show="modalShow"
		  :maskClosable="false"
		  title="确认核销"
		  content="核销后不可撤回，是否确认核销？"
		  confirmText="确认"
		  @click="confirmHandle"
		></tuiModal>
	</view>
</template>

<script>
	import {
		mapGetters
	} from "vuex";
	import {
		storeOrderVerific,
		orderVerific,
		orderCartInfo,
	} from '@/api/admin'
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import {
		nextTick
	} from "vue";
	import writeOffList from '../../components/writeOffList/index.vue';
	import tuiModal from '@/components/tui-modal/index.vue';
	export default {
		name: 'scanning',
		components: {
			writeOffList,
			tuiModal,
		},
		computed: mapGetters(["storeNum"]),
		data() {
			return {
				from: '',
				auth: 1,
				verify_code: '',
				list: [],
				imgHost: HTTP_REQUEST_URL,
				popupShow: false,
				orderInfo: {},
				id: 0,
				box: false,
				isAll: 1,
				modalShow: false,
			}
		},
		onLoad: function(option) {
			this.from = option.from || '';
			this.auth = option.auth || 1;
			this.verify_code = option.code;
		},
		onShow(options) {
			this.getList()
		},
		methods: {
			goRecord(id) {
				uni.navigateTo({
					url: '/pages/admin/writeRecordList/index?id=' + id+'&storeNum='+this.storeNum
				})
			},
			getList: function() {
				let funApi = orderVerific;
				funApi(this.verify_code, this.auth)
					.then(res => {
						this.list = res.data.data;
					})
					.catch(res => {
						return this.$util.Tips({
							title: res
						});
					});
			},
			jump: function(item) {
				if (item.type == 11) {
					uni.navigateTo({
						url: '/pages/admin/distribution/scanning/detail/card?auth=' + this.auth + '&id=' + item.id + '&let=1' +
							'&code=' +
							this
							.verify_code + '&storeNum=' + this.storeNum
					});
				} else {
					this.id = item.id;
					this.getCartList(item.id)
				}
			},
			getCartList: function(id) {
				orderCartInfo({
					oid: id,
					auth: this.auth,
				}).then(res => {
					if (res.data.product_type == 4) {
						uni.navigateTo({
							url: '/pages/admin/writeOffCard/index?id=' + id+'&storeNum='+this.storeNum
						})
					} else {
						let orderInfo = res.data;
						for (let i = 0; i < orderInfo.cart_info.length; i++) {
							orderInfo.cart_info[i].surplus_num_input = orderInfo.cart_info[i].write_surplus_times ? 1 : 0;
							orderInfo.cart_info[i].checked = orderInfo.cart_info[i].write_surplus_times > 0;
						}
						this.orderInfo = orderInfo
						this.popupShow = true;
					}
				}).catch(res => {
					this.$util.Tips({
						title: res
					});
				});
			},
			closePopup() {
				this.popupShow = false;
			},
			writeSuccess(val) {
				this.isAll = val;
				this.box = true;
			},
			ok(val) {
				if (val == 4) {
					if (this.from == 'delivery') {
						uni.navigateBack();
					} else {
						uni.navigateTo({
							url: this.storeNum ? '/pages/admin/work/index' : '/pages/admin/work/store'
						});
					}
					return;
				}
				this.box = false;
				this.popupShow = false;
				this.getList();
			},
			confirmHandle(e) {
				this.modalShow = false;
				if (e.index) {
					this.$refs.writeOffList.writeoff();
				}
			},
			callConfirmHandle() {
				this.modalShow = true;
			},
		}
	}
</script>

<style lang="scss" scoped>
	.scan {
		padding-bottom: 160upx;

		.header {
			height: 346rpx;
			padding: 48rpx 0 0 32rpx;
			background: linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%);
			font-weight: 500;
			font-size: 36rpx;
			line-height: 50rpx;
			color: #FFFFFF;
		}

		.box {
			padding: 0 20rpx;
			margin: -216rpx 0 0;
		}

		.content {
			padding: 32rpx 24rpx;
			border-radius: 24rpx;
			margin-bottom: 20rpx;
			background: #FFFFFF;

			.attributes {
				font-size: 28rpx;
				line-height: 40rpx;
				color: #1890FF;
			}

			.orange {
				color: #FF7E00;
			}

			.pad {
				padding: 20upx 20upx 22upx;
			}

			.btn {
				font-size: 28rpx;
				line-height: 40rpx;
				color: #999999;

				.iconfont {
					margin-left: 4rpx;
					font-size: 32rpx;
				}
			}

			.content_box {
				margin-top: 26rpx;

				image {
					width: 136rpx;
					height: 136rpx;
					border-radius: 16rpx;
				}

				.content_box_title {
					flex: 1;
					padding-left: 20rpx;
					font-size: 24rpx;
					line-height: 34rpx;
					color: #666666;

					.textbox {
						font-weight: 500;
						font-size: 28rpx;
						line-height: 40rpx;
						color: #333333;
					}

					.mar {
						margin: 16upx 0upx;
					}

					.attribute {
						color: #999999;
					}

					.txt {
						display: flex;
						justify-content: space-between;
						font-size: 24upx;

						.orange {
							color: #FF7E00;
						}

						.blue {
							color: #1890FF;
						}
					}
				}
			}

			.content_bottom {
				display: flex;
				justify-content: space-between;
				font-size: 22upx;
				padding: 0upx 20upx;
				color: #666666;

				.money {
					font-size: 26upx;
					color: #F5222D;
				}
			}
		}
	}

	.popup {
		position: fixed;
		right: 0;
		bottom: 0;
		left: 0;
		z-index: 6;
		height: 800rpx;
		padding-bottom: 0;
		padding-bottom: constant(safe-area-inset-bottom);
		padding-bottom: env(safe-area-inset-bottom);
		border-radius: 40rpx 40rpx 0 0;
		background: #FFFFFF;
		overflow: hidden;
		flex-wrap: nowrap;
		transform: translateY(100%);
		transition: transform 0.3s;

		&.on {
			transform: translateY(0);
		}

		.popup-hd {
			position: relative;
			height: 108rpx;
			text-align: center;
			font-weight: 500;
			font-size: 32rpx;
			line-height: 108rpx;
			color: #333333;

			.btn {
				position: absolute;
				top: 50%;
				right: 32rpx;
				width: 36rpx;
				height: 36rpx;
				border-radius: 50%;
				background: #EEEEEE;
				transform: translateY(-50%);
				text-align: center;
				line-height: 36rpx;
			}

			.iconfont {
				vertical-align: middle;
				font-size: 24rpx;
				color: #333333;
			}
		}

		.popup-bd {
			position: relative;
			flex: 1;
			min-height: 0;
			box-sizing: border-box;

			/deep/.shoppingCart {
				position: absolute;
				top: 0;
				right: 0;
				bottom: 0;
				left: 0;
				display: flex;
				flex-direction: column;
				padding: 0;

				.content {
					flex: 1;
					min-height: 0;
					overflow-y: auto;
					margin-top: 0;
					padding: 36rpx 32rpx;
					border-radius: 0;

					.item {
						margin-bottom: 48rpx;
					}
				}

				.footer {
					position: static;
					height: 96rpx;
					padding-bottom: 0;
				}
			}
		}

		.popup-ft {
			padding: 16rpx 20rpx 16rpx 32rpx;

			.btn {
				height: 64rpx;
				padding: 0 32rpx;
				border-radius: 32rpx;
				background: #2A7EFB;
				font-weight: 500;
				font-size: 24rpx;
				line-height: 64rpx;
				color: #FFFFFF;
			}
		}
	}

	.boxs {
		position: fixed;
		top: 0;
		bottom: 0;
		width: 100%;
		height: 100%;
		z-index: 10;
		background-color: #78797a;

		.small_box {
			position: fixed;
			top: 50%;
			right: 75rpx;
			left: 75rpx;
			padding: 40rpx;
			border-radius: 32rpx;
			background: #FFFFFF;
			transform: translateY(-50%);

			image {
				width: 100%;
				height: 228upx;
			}

			.content {
				text-align: center;

				.font {
					font-weight: 500;
					font-size: 32rpx;
					line-height: 52rpx;
					color: #333333;
				}

				.small_font {
					margin-top: 24rpx;
					font-size: 30rpx;
					line-height: 42rpx;
					color: #666666;
				}
			}

			.btn-box {
				margin-top: 40rpx;
			}

			.btn {
				flex: 1;
				height: 72rpx;
				border: 1rpx solid #2A7EFB;
				border-radius: 36rpx;
				margin-left: 32rpx;
				transform: rotateZ(360deg);
				text-align: center;
				font-weight: 500;
				font-size: 26rpx;
				line-height: 70rpx;
				color: #2A7EFB;

				&:first-child {
					margin-left: 0;
				}

				&.on {
					background: #2A7EFB;
					color: #FFFFFF;
				}
			}
		}
	}
	/deep/ .tui-modal-btn-cancel {
		border:1px solid #2A7EFB !important;
		color: #2A7EFB !important;
	}
	/deep/ .tui-modal-btn-confirm {
		background-color: #2A7EFB !important;
	}
</style>