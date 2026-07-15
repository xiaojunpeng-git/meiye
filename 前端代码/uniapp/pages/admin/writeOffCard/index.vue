<template>
	<view class="container">
		<view class="goods-box" v-if="list.cart_info">
			<view class="header">订单号：{{ list.order_id }}</view>
			<view class='item acea-row' v-for="(item,index) in list.cart_info">
				<view class='pictrue'>
					<image :src="item.cart_info.productInfo.attrInfo.image" mode=""></image>
				</view>
				<view class='text acea-row row-column row-between'>
					<view class="title line2">{{ item.cart_info.productInfo.store_name }}</view>
					<view class='money acea-row row-between row-middle'>
						<view class="orangcol">
							已核销：<text>{{item.write_times - item.surplus_num}}</text>/{{item.write_times}}
						</view>
						<view v-if="item.is_writeoff == 1" class="txt acea-row row-middle">
							核销已完成
						</view>
						<view v-else class="txt acea-row row-middle">
							本次核销：
							<view class='carnum acea-row row-center-wrapper'>
								<view v-if="item.surplus_num_input == 1" class="reduce bggary">
									<text class="iconfont icon-ic_Reduce"></text>
								</view>
								<view v-else class="reduce" @click.stop='subCart(item,index)'>
									<text class="iconfont icon-ic_Reduce"></text>
								</view>
								<input v-model="item.surplus_num_input" class='nums' type="number" @input="numInput" @blur="numBlur" />
								<view v-if="item.surplus_num_input == item.surplus_num" class="plus bggary">
									<text class="iconfont icon-ic_increase"></text>
								</view>
								<view v-else class="plus" @click.stop='addCart(item,index)'>
									<text class="iconfont icon-ic_increase"></text>
								</view>
							</view>
						</view>
					</view>
				</view>
			</view>
		</view>
		<view class="record-box" v-if="recordList.length">
			<view class="header">核销记录</view>
			<view class="list">
				<view class="item acea-row row-middle row-between" v-for="(item, index) in recordList" :key="index">
					<view class="left">{{ item.add_time }}</view>
					<view class="right">核销{{item.writeoff_num}}次</view>
				</view>
			</view>
		</view>
		<view class="footer acea-row row-middle row-between">
			<navigator class="btn" :url="storeNum?'/pages/admin/work/index':'/pages/admin/work/store'" hover-class="none">返回工作台</navigator>
			<view v-if="list.cart_info && list.cart_info[0].surplus_num" class="btn" @click="callConfirmHandle">确认核销</view>
		</view>
		<view class="pb-safe">
			<view class="h-112"></view>
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
		orderCartInfo,
		orderWriteoff,
		storeWirteOffRecords,
		orderWirteOffRecords,
	} from '@/api/admin';
	import tuiModal from '@/components/tui-modal/index.vue';

	export default {
		components: {
			tuiModal,
		},
		data() {
			return {
				list: {},
				recordList: [],
				lists: {},
				checkModel: [],
				nums: [],
				auth: 1,
				id: 0,
				storeNum:1, //1:平台；2：门店
				modalShow:false,
			}
		},
		watch: {
			checkModel() {
				if (this.lengt == this.checkModel.length) {
					this.checked = true;
				} else {
					this.checked = false;
				}
			}
		},
		onLoad(option) {
			this.auth = option.auth || 1;
			this.id = option.id;
			this.storeNum = parseInt(option.storeNum);
			this.getCartList()
			this.orderWirteOffRecords()
		},
		methods: {
			numInput(event) {
				let value = event.detail.value;
				if (value) {
					value = Number(value);
					this.$nextTick(() => {
						if (value > this.list.cart_info[0].surplus_num) {
							this.list.cart_info[0].surplus_num_input = this.list.cart_info[0].surplus_num;
						} else if (value < 1) {
							this.list.cart_info[0].surplus_num_input = 1;
						}
					});
				}
			},
			numBlur(event) {
				let value = event.detail.value;
				if (!value) {
					this.$nextTick(() => {
						this.list.cart_info[0].surplus_num_input = 1;
					});
				}
			},
			orderWirteOffRecords() {
				let funApi = '';
				
				if(this.storeNum){
					funApi = orderWirteOffRecords;
				}else{
					funApi = storeWirteOffRecords;
				}
				funApi(this.id, {
					product_type: 4
				}).then(res => {
					this.recordList = res.data.list;
				});
			},
			num() {
				for (let index = 0; index < this.lists.cart_info.length; index++) {
					this.nums.push({
						num: 0
					});
				}
			},
			getCartList: function() {
				orderCartInfo({
					oid: this.id,
					auth: this.auth,
				}).then(res => {
					let list = res.data;
					for (let i = 0; i < list.cart_info.length; i++) {
						list.cart_info[i].surplus_num_input = 1
					}
					this.list = res.data
					this.lists = JSON.parse(JSON.stringify(res.data))
					this.listlet = res.data.cart_info.length
					this.$set(this.attr, 'id', this.list.id);
					this.num()
				}).catch(res => {
					this.$util.Tips({
						title: res
					});
				});
			},
			subCart(item, index) {
				this.list.cart_info[index].surplus_num_input--;
			},
			addCart(item, index) {
				this.list.cart_info[index].surplus_num_input++;
			},
			verification() {
				let that = this;
				let cart_ids = [];
				for (let i = 0; i < this.list.cart_info.length; i++) {
					cart_ids.push({
						cart_id: this.list.cart_info[i].cart_id,
						cart_num: this.list.cart_info[i].surplus_num_input,
					});
				}
				orderWriteoff({
					auth: this.auth,
					oid: this.id,
					cart_ids: cart_ids
				}).then(res => {
					this.$util.Tips({
						title: res.msg
					}, () => {
						this.orderWirteOffRecords();
					});
					this.list.cart_info[0].surplus_num = this.list.cart_info[0].surplus_num - this.list.cart_info[0].surplus_num_input;
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				});
			},
			confirmHandle(e) {
				this.modalShow = false;
				if (e.index) {
					this.verification();
				}
			},
			callConfirmHandle() {
				this.modalShow = true;
			},
		},
	}
</script>

<style lang="scss" scoped>
	.container {
		padding: 22rpx 20rpx;
	}

	.goods-box {
		padding: 32rpx 24rpx;
		border-radius: 24rpx;
		background: #FFFFFF;

		.header {
			margin-bottom: 26rpx;
			font-size: 28rpx;
			line-height: 40rpx;
			color: #333333;
		}

		.pictrue {
			width: 136rpx;
			height: 136rpx;
			border-radius: 16rpx;
		}

		image {
			width: 100%;
			height: 100%;
			border-radius: 16rpx;
		}

		.text {
			flex: 1;
			padding-left: 20rpx;
		}

		.title {
			font-size: 28rpx;
			line-height: 40rpx;
			color: #333333;
		}

		.orangcol {
			font-size: 24rpx;
			line-height: 34rpx;
			color: #666666;

			text {
				color: #2A7EFB;
			}
		}

		.txt {
			font-size: 24rpx;
			line-height: 34rpx;
			color: #666666;
		}

		.reduce {
			width: 32rpx;
			height: 36rpx;
			text-align: left;
			line-height: 36rpx;

			.iconfont {
				font-size: 24rpx;
				color: #333333;
			}

			&.bggary {
				.iconfont {
					color: #CCCCCC;
				}
			}
		}

		.plus {
			width: 32rpx;
			height: 36rpx;
			text-align: right;
			line-height: 36rpx;

			.iconfont {
				font-size: 24rpx;
				color: #333333;
			}

			&.bggary {
				.iconfont {
					color: #CCCCCC;
				}
			}
		}

		.nums {
			width: 72rpx;
			height: 36rpx;
			border-radius: 4rpx;
			background: #F5F5F5;
			text-align: center;
			font-family: SemiBold;
			font-weight: 600;
			font-size: 24rpx;
			line-height: 36rpx;
			color: #333333;
		}

		.iconfont {
			font-size: 24rpx;
		}
	}

	.record-box {
		padding-bottom: 30rpx;
		border-radius: 24rpx;
		margin-top: 20rpx;
		background: #FFFFFF;

		.header {
			padding: 32rpx 0 16rpx 24rpx;
			font-weight: 500;
			font-size: 30rpx;
			line-height: 42rpx;
			color: #333333;
		}

		.list {
			padding: 0 40rpx;
		}

		.item {
			height: 88rpx;
			border-bottom: 1rpx solid #F1F1F1;
			font-size: 28rpx;
			color: #333333;

			.right {
				color: #666666;
			}
		}
	}

	.footer {
		position: fixed;
		bottom: 0;
		left: 0;
		width: 100%;
		height: 112rpx;
		height: calc(112rpx + constant(safe-area-inset-bottom));
		height: calc(112rpx + env(safe-area-inset-bottom));
		padding: 0 20rpx;
		padding-bottom: constant(safe-area-inset-bottom);
		padding-bottom: env(safe-area-inset-bottom);
		background: #FFFFFF;

		.btn {
			flex: 1;
			height: 72rpx;
			border: 2rpx solid #2A7EFB;
			border-radius: 36rpx;
			margin-right: 16rpx;
			text-align: center;
			font-weight: 500;
			font-size: 26rpx;
			line-height: 68rpx;
			color: #2A7EFB;

			&:last-child {
				margin-right: 0;
				background: #2A7EFB;
				color: #FFFFFF;
			}
		}
	}
	.item ~ .item{
		margin-top: 40rpx;
	}
	::v-deep  .tui-modal-btn-cancel {
		border:1px solid #2A7EFB !important;
		color: #2A7EFB !important;
	}
	::v-deep  .tui-modal-btn-confirm {
		background-color: #2A7EFB !important;
	}
</style>