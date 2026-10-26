<template>
	<view>
		<view class='shoppingCart copy-data'>
			<view class='nav acea-row row-between-wrapper'>
				<view>订单号：<text class='num'>{{ list.order_id }}</text></view>
				<navigator class="btn" :url="'/pages/admin/writeRecordList/index?id='+id+'&storeNum='+storeNum" hover-class="none">
					核销记录<text class="iconfont icon-ic_rightarrow"></text>
				</navigator>
			</view>
			<view class="content">
				<view class="list">
					<view class="item pt-32 pb-32 pr-24 pl-24 rd-24rpx mb-24 bg--w111-fff" v-for="(item, index) in cartInfo" :key="item.id" :style="{ opacity: item.is_writeoff ? 0.5: 1 }">
						<view class="flex-y-center">
							<div class="w-40 h-40 mr-16" @click="dan(item, index)">
								<text :class="['iconfont fs-36', item.checked ? 'icon-a-ic_CompleteSelect text-w111-2A7EFB' : 'icon-ic_unselect text--w111-ccc']"></text>
							</div>
							<view class="flex-1 min-w-0">
								<view class="flex">
									<image :src="item.cart_info.productInfo.attrInfo ? item.cart_info.productInfo.attrInfo.image : item.cart_info.productInfo.image" mode="" class="w-136 h-136 rd-16rpx"></image>
									<view class="flex-1 flex-col min-w-0 pl-20">
										<view class="flex-1 flex fs-28">
											<view class="flex-1 min-w-0 line1" :class="item.attrStatus? '' : 'reColor'">
												{{item.cart_info.productInfo.store_name}}
											</view>
											<view v-if="item.is_writeoff == 1">已核销</view>
											<view v-else-if="item.write_times == item.surplus_num" class="bluecol">未核销</view>
											<view v-else class="orangcol">部分核销</view>
										</view>
										<view class="flex">
											<view class="flex-1 min-w-0 line1 fs-22 text--w111-999">{{item.cart_info.productInfo.attrInfo.suk}}</view>
											<view class="flex" v-if="!item.is_writeoff">
												<view class="flex-center w-32 h-36" @click="subCart(item, index)">
													<text :class="['iconfont icon-ic_Reduce fs-24', {'text--w111-ccc': item.value == 1}]"></text>
												</view>
												<input v-model="item.value" :disabled="item.surplus_num <= 1" class="w-72 h-36 bg--w111-f5f5f5 lh-36rpx fs-24 text-center" style="min-height: 0;" type="number" @input="handleInput(item, $event)" @blur="handleBlur(item, $event)" />
												<view class="flex-center w-32 h-36" @click="addCart(item, index)">
													<text :class="['iconfont icon-ic_increase fs-24', {'text--w111-ccc': item.value == item.surplus_num}]"></text>
												</view>
											</view>
										</view>
									</view>
								</view>
								<view class="border-t-EEEEEE mt-26"></view>
							</view>
						</view>
						<view class="flex pt-26">
							<view class="flex-1 text-center fs-24"><text class="text--w111-666">已核销：</text><text class="text-w111-2A7EFB">{{item.writeoffed_num}}</text>/{{item.write_times}}</view>
							<view class="flex-1 text-center fs-24"><text class="text--w111-666">剩余数量：</text>{{item.write_surplus_times}}</view>
							<navigator v-if="item.product_type == 6" :url="`/pages/admin/reservation_list/index?oid=${list.id}`" class="flex-1 text-center fs-24" hover-class="none">
								待服务：</text>{{item.unservice_num}}
								<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999 ml-8"></text>
							</navigator>
						</view>
					</view>
				</view>
			</view>
			<view class="footer flex-between-center">
				<view class="flex-center fs-26" @click="checkAll">
					<div class="xuan w-40 h-40 mr-12">
						<view class="iconfont fs-38" :class="[checked ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect']"></view>
					</div>
					全选
				</view>
				<button class="money" type="primary" @click="callConfirmHandle">{{checked ? '一键' : '确认'}}核销({{numChecked}})</button>
			</view>
		</view>
		<view class="pb-safe">
			<view class="h-96">
			</view>
		</view>
		<view v-if="box">
			<view class="box">
				<view class="small_box">
					<view class="content">
						<view class="font">核销成功</view>
						<view v-if="list.total_num == parseInt(list.writeoff_count)+writeOffNum" class="small_font">当前订单已完成核销</view>
						<view v-else class="small_font">该订单仍有其他待核销商品</view>
					</view>
					<view class="acea-row btn-box">
						<view v-if="lets == 1 && list.total_num == parseInt(list.writeoff_count)+writeOffNum" class="btn primary" @click="ok(1)">知道了</view>
						<navigator v-if="lets > 1 && list.total_num != parseInt(list.writeoff_count)+writeOffNum" :url='"/pages/admin/distribution/scanning/index?code="+attr.code' hover-class='none' open-type="redirect" class="btn btn_no">返回列表</navigator>
						<navigator v-if="(lets > 1 && list.total_num == parseInt(list.writeoff_count)+writeOffNum)||(lets==1&&list.total_num != parseInt(list.writeoff_count)+writeOffNum)" url="/pages/admin/work/store" hover-class='none' open-type="redirect" class="btn btn_no">返回首页</navigator>
						<view v-if="list.total_num != parseInt(list.writeoff_count)+writeOffNum" class="btn on" @click="ok(0)">继续核销</view>
						<navigator v-if="lets > 1 && list.total_num == parseInt(list.writeoff_count)+writeOffNum" :url='"/pages/admin/distribution/scanning/index?code="+attr.code' open-type="redirect" hover-class='none' class="btn">核销其他订单</navigator>
					</view>
				</view>
			</view>
		</view>
		<writeOffSwitching ref="writeOff" :attr="attr" :isShow='1' :iSplus='1' :iScart='1' @dataId="onDataId" @myevent="onMyEvent" id='product-window'></writeOffSwitching>
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
	import writeOffSwitching from '../../../components/writeOffSwitching/index.vue';
	import tuiModal from '@/components/tui-modal/index.vue';
	import {
		orderCartInfo,
		orderWriteoff
	} from '@/api/admin'
	import {
		nextTick
	} from 'process';
	export default {
		components: {
			writeOffSwitching,
			tuiModal,
		},
		props: {
			idss: {
				type: Number,
				default: 0
			},
			code: {
				type: String,
				default: ''
			},
			let: {
				type: Number,
				default: 0
			},
		},
		data() {
			return {
				nums: [],
				newList: [],
				// reduce_show: -1,
				// plus_show: -1,
				ids: [], //选定需要核销的id
				lets: 0, //判断订单的数量
				listlet: 0, //判断订单商品的数量
				attr: { //切换组件传值
					cartAttr: false,
					id: [],
					code: '',
					type: 0
				},
				id: 0, //订单ID
				list: [],
				lists: [],
				lengt: 0,
				box: false,
				checked: false,
				checkModel: [],
				isAllSelect: false,
				data: [{
					id: '1',
					value: 'aaa'
				}, {
					id: '2',
					value: 'bbb'
				}, {
					id: '3',
					value: 'ccc'
				}],
				writeOffNum: 0, //每次核销商品数量
				auth: 1,
				storeNum: 1,
				cartInfo: [],
				modalShow:false,
			};
		},
		computed: {
			numChecked() {
				return this.cartInfo.filter((item) => {
					return item.checked && !item.is_writeoff;
				}).reduce((total, item) => {
					return total + item.value;
				}, 0);
			}
		},
		onLoad: function(options) {
			this.storeNum = parseInt(options.storeNum)
			this.id = options.id
			this.attr.code = options.code
			this.lets = options.let
			this.auth = options.auth
			this.getList(this.id)
		},
		methods: {
			handleInput(item, event) {
				const value = event.target.value;
				if (value === '') {
					return;
				}
				const numberValue = parseFloat(value);
				this.$nextTick(() => {
					if (numberValue < item.surplus_num) {
						item.value = numberValue;
					} else {
						item.value = item.surplus_num;
					}
				});
			},
			handleBlur(item, event) {
				const value = event.target.value;
				if (value === '') {
					this.$nextTick(() => {
						item.value = item.surplus_num;
					});
				}
			},
			//处理每一条数据的最大值
			num() {
				for (let index = 0; index < this.lists.cart_info.length; index++) {
					this.nums.push({
						num: 0
					});
				}
			},
			// 减
			subCart(item) {
				if (item.value > 1) {
					item.value--;
				}
			},
			// 加
			addCart(item) {
				if (item.value < item.surplus_num) {
					item.value++;
				}
			},
			// 全选
			checkAll() {
				this.checked = !this.checked;
				this.cartInfo.filter((item) => {
					return !item.is_writeoff;
				}).forEach((item) => {
					item.checked = this.checked;
				});
			},
			// 单个选择
			dan(item) {
				if (item.is_writeoff) {
					return;
				}
				item.checked = !item.checked;
				this.checked = this.cartInfo.filter((item) => {
					return !item.is_writeoff;
				}).every((item) => {
					return item.checked;
				});
			},
			getList(id) {
				orderCartInfo({
					oid: id,
					auth: this.auth,
				}).then(res => {
					let cartInfo = res.data.cart_info;
					this.cartInfo = cartInfo.map((item) => {
						return {
							...item,
							checked: false,
							value: item.surplus_num ? 1 : 0,
						};
					});
					this.list = res.data;
				})
			},
			onDataId: function(id) {
				this.nums.forEach((item) => {
					item.num = 0
				})
				this.id = id;
				this.getList(id)
			},
			switchs() {
				this.attr.cartAttr = true;
				this.$refs.writeOff.getList(2)
			},
			onMyEvent() {
				this.attr.cartAttr = false;
			},
			verification() {
				this.newList = this.cartInfo.filter((item) => {
					return item.checked && !item.is_writeoff;
				}).map((item) => {
					return {
						cart_id: item.cart_id,
						cart_num: item.value,
					};
				});
				if (!this.newList.length) {
					this.$util.Tips({
						title: '请选择商品'
					});
				} else {
					uni.showLoading({
						title: '加载中',
					});
					this.writeOffNum = this.newList.reduce((total, item) => {
						return total + item.cart_num;
					}, 0);
					setTimeout(() => {
						orderWriteoff({
							auth: this.auth,
							oid: this.id,
							cart_ids: this.newList
						}).then(res => {
							uni.hideLoading();
							this.box = true
							this.checked = false
						}).catch(err => {
							this.$util.Tips({
								title: err
							});
							uni.hideLoading();
						})
					}, 1000);
				}
			},
			// 所有订单核销完成
			ok(type) {
				this.box = false
				this.nums.forEach((item) => {
					item.num = 0
				})
				this.getList(this.id)
				if (type) {
					uni.redirectTo({
						url: '/pages/admin/orderDetail/index?id=' + this.list.order_id + '&types=3&storeNum=' + this.storeNum
					})
				}
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
		}
	}
</script>

<style scoped lang="scss">
	::v-deep  .tui-modal-btn-cancel {
		border:1px solid #2A7EFB !important;
		color: #2A7EFB !important;
	}
	::v-deep  .tui-modal-btn-confirm {
		background-color: #2A7EFB !important;
	}
	.bluecol {
		color: #1890FF;
	}

	.orangcol {
		color: #FF7E00;
	}

	.graycol {
		color: #CCCCCC;
	}

	::v-deep checkbox .uni-checkbox-input.uni-checkbox-input-checked {
		border: 1px solid #007aff !important;
		background-color: #007aff !important;
	}

	.page-footer {
		position: fixed;
		bottom: 0;
		z-index: 30;
		display: flex;
		align-items: center;
		justify-content: space-around;
		width: 100%;
		height: calc(98upx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(98upx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		box-sizing: border-box;
		border-top: solid 1upx #F3F3F3;
		background-color: #fff;
		box-shadow: 0px 0px 17upx 1upx rgba(206, 206, 206, 0.32);
		padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
		padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/

		.foot-item {
			display: flex;
			width: max-content;
			align-items: center;
			justify-content: center;
			flex-direction: column;
			position: relative;

			.count-num {
				position: absolute;
				display: flex;
				justify-content: center;
				align-items: center;
				width: 40upx;
				height: 40upx;
				top: 0upx;
				right: -15upx;
				color: #fff;
				font-size: 20upx;
				background-color: #FD502F;
				border-radius: 50%;
				padding: 4upx;
			}
		}

		.foot-item image {
			height: 50upx;
			width: 50upx;
			text-align: center;
			margin: 0 auto;
		}

		.foot-item .txt {
			font-size: 24upx;
		}
	}

	.shoppingCart {
		padding: 24rpx 20rpx;
	}

	.shoppingCart .labelNav {
		height: 76upx;
		padding: 0 30upx;
		font-size: 22upx;
		color: #8c8c8c;
		position: fixed;
		left: 0;
		width: 100%;
		box-sizing: border-box;
		background-color: #f5f5f5;
		z-index: 5;
		top: 0;
	}

	.shoppingCart .labelNav .item .iconfont {
		font-size: 25upx;
		margin-right: 10upx;
	}

	.shoppingCart .nav {
		background-color: #fff;
		height: 100rpx;
		padding: 0 20rpx 0 24rpx;
		font-size: 28rpx;
		color: #333333;
		z-index: 5;
		border-radius: 24rpx;
	}

	.shoppingCart .nav .num {
		margin-left: 12upx;
	}

	.shoppingCart .nav .btn {
		font-size: 28rpx;
		color: #999999;

		.iconfont {
			margin-left: 4rpx;
			font-size: 32rpx;
			color: #333333;
		}
	}

	.shoppingCart .nav .administrate {
		font-size: 26upx;
		color: #282828;
		width: 110upx;
		height: 46upx;
		border-radius: 24upx;
		border: 1px solid #CCCCCC;
	}

	.shoppingCart .noCart {
		margin-top: 171upx;
		background-color: #fff;
		padding-top: 0.1upx;
	}

	.shoppingCart .noCart .pictrue {
		width: 414upx;
		height: 336upx;
		margin: 78upx auto 56upx auto;
	}

	.shoppingCart .noCart .pictrue image {
		width: 100%;
		height: 100%;
	}

	.shoppingCart .list .item .xuan .iconfont {
		font-size: 40rpx;
		color: #CCCCCC;
	}

	.shoppingCart .list .item .xuan .icon-a-ic_CompleteSelect {
		color: #2A7EFB;
	}

	.shoppingCart .list .item .picTxt {
		position: relative;
		flex: 1;
		min-width: 0;
		padding-left: 16rpx;
	}

	.shoppingCart .list .item .picTxt .pictrue {
		width: 136rpx;
		height: 136rpx;
	}

	.shoppingCart .list .item .picTxt .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 16rpx;
	}

	.shoppingCart .list .item .picTxt .text {
		flex: 1;
		min-width: 0;
		padding-left: 20rpx;
		font-size: 28upx;
		color: #282828;
	}

	.shoppingCart .list .item .picTxt .text .reColor {
		color: #333333;
		width: 60%;
	}

	.shoppingCart .list .item .picTxt .text .title {
		display: flex;
		justify-content: space-between;
		font-size: 28rpx;
		line-height: 40rpx;
		color: #333333;
	}

	.shoppingCart .list .item .picTxt .text .title .top {
		width: 70%;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-size: 28upx;
		font-weight: 400;
	}

	.shoppingCart .list .item .picTxt .text .reElection {
		margin-top: 20upx;
	}

	.shoppingCart .list .item .picTxt .text .reElection .title {
		font-size: 24upx;
	}

	.shoppingCart .list .item .picTxt .text .reElection .reBnt {
		width: 120upx;
		height: 46upx;
		border-radius: 23upx;
		font-size: 26upx;
	}

	.shoppingCart .list .item .picTxt .text .infor {
		font-size: 22rpx;
		line-height: 30rpx;
		color: #999999;
		margin-top: 12rpx;
	}

	.shoppingCart .list .item .picTxt .text .money {
		font-size: 36rpx;
		line-height: 36rpx;
		color: #333333;
		margin-top: 18rpx;
		font-family: Regular;
	}

	.shoppingCart .list .item .picTxt .carnum {
		height: 36rpx;
	}

	.shoppingCart .list .item .picTxt .carnum view {
		width: 32rpx;
		text-align: center;
		height: 100%;
		line-height: 36rpx;
		font-size: 24rpx;
		color: #333333;
	}

	.shoppingCart .list .item .picTxt .carnum .reduce {
		border-right: 0;
		border-radius: 30upx 0 0 30upx;
		padding-right: 8rpx;
	}

	.shoppingCart .list .item .picTxt .carnum .reduce.on {
		border-color: #e3e3e3;
		color: #dedede;
	}

	.shoppingCart .list .item .picTxt .carnum .plus {
		border-left: 0;
		border-radius: 0 30upx 30upx 0;
		padding-left: 8rpx;
	}

	.shoppingCart .list .item .picTxt .carnum .iconfont {
		font-size: 24rpx;
	}

	.shoppingCart .list .item .picTxt .carnum .bggary .iconfont {
		color: #CCCCCC;
	}

	.shoppingCart .list .item .picTxt .carnum .num {
		color: #282828;
	}

	.shoppingCart .list .item .picTxt .carnum .num0 {
		color: #a4a4a4;
	}

	.shoppingCart .list .item .picTxt .carnum .nums {
		width: 72rpx;
		border-radius: 4rpx;
		background: #F5F5F5;
		text-align: center;
		font-family: SemiBold;
		font-size: 24rpx;
		color: #333333;
	}

	.shoppingCart .invalidGoods {
		background-color: #fff;
	}

	.shoppingCart .invalidGoods .goodsNav {
		width: 100%;
		height: 66upx;
		padding: 0 30upx;
		box-sizing: border-box;
		font-size: 28upx;
		color: #282828;
	}

	.shoppingCart .invalidGoods .goodsNav .iconfont {
		color: #424242;
		font-size: 28upx;
		margin-right: 17upx;
	}

	.shoppingCart .invalidGoods .goodsNav .del {
		font-size: 26upx;
		color: #999;
	}

	.shoppingCart .invalidGoods .goodsNav .del .icon-shanchu1 {
		color: #999;
		font-size: 33upx;
		vertical-align: -2upx;
		margin-right: 8upx;
	}

	.shoppingCart .invalidGoods .goodsList .item {
		padding: 20upx 30upx;
		border-top: 1upx solid #f5f5f5;
	}

	.shoppingCart .invalidGoods .goodsList .item .invalid {
		font-size: 22upx;
		color: #fff;
		width: 70upx;
		height: 36upx;
		background-color: #aaa;
		border-radius: 3upx;
		text-align: center;
		line-height: 36upx;
	}

	.shoppingCart .invalidGoods .goodsList .item .pictrue {
		width: 140upx;
		height: 140upx;
	}

	.shoppingCart .invalidGoods .goodsList .item .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 6upx;
	}

	.shoppingCart .invalidGoods .goodsList .item .text {
		width: 433upx;
		font-size: 28upx;
		color: #999;
		height: 140upx;
	}

	.shoppingCart .invalidGoods .goodsList .item .text .name {
		width: 100%;
	}

	.shoppingCart .invalidGoods .goodsList .item .text .infor {
		font-size: 24upx;
	}

	.shoppingCart .invalidGoods .goodsList .item .text .end {
		font-size: 26upx;
		color: #bbb;
	}

	.shoppingCart .footer {
		width: 100%;
		background: #FFFFFF;
		position: fixed;
		padding: 0 20rpx 0 32rpx;
		box-sizing: border-box;
		bottom: 0;
		left: 0;
		height: 96rpx;
		height: calc(96rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(96rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
		padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/
		z-index: 2;
	}

	.shoppingCart .footer.on {
		// #ifndef H5
		bottom: 0upx;
		// #endif
	}

	.shoppingCart .footer .iconfont {
		font-size: 40rpx;
		color: #CCCCCC;
	}

	.shoppingCart .footer .icon-a-ic_CompleteSelect {
		color: #2A7EFB;
	}

	.shoppingCart .footer .checkAll {
		font-size: 28upx;
		color: #282828;
		margin-left: 16upx;
	}

	.shoppingCart .footer .money {
		font-size: 24rpx;
		height: 64rpx;
		padding: 0 32rpx;
		background: #2A7EFB;
		border-radius: 32rpx;
		font-weight: 400;
		color: #FFFFFF;
		line-height: 64rpx;
	}

	.shoppingCart .footer .placeOrder {
		color: #fff;
		font-size: 30upx;
		width: 226upx;
		height: 70upx;
		border-radius: 50upx;
		text-align: center;
		line-height: 70upx;
		margin-left: 22upx;
	}

	.shoppingCart .footer .button .bnt {
		font-size: 28upx;
		color: #999;
		border-radius: 50upx;
		border: 1px solid #999;
		width: 160upx;
		height: 60upx;
		text-align: center;
		line-height: 60upx;
	}

	.shoppingCart .footer .button form~form {
		margin-left: 17upx;
	}

	.uni-p-b-96 {
		height: 96upx;
	}
</style>
<style scoped lang="scss">
	.writeoff {
		opacity: 0.6;
	}

	.he {
		display: flex;
		justify-content: space-between;

		.txt {
			font-size: 22upx;

		}
	}

	.noxuan {
		cursor: not-allowed;
	}

	.q {
		background-color: #4276F6;
		line-height: 40upx;
		color: #FFF;
		font-size: 24upx;
		text-align: center;
	}

	.dui {
		line-height: 40upx;
		color: #FFF;
		font-size: 24upx;
		text-align: center;
	}

	.box {
		position: fixed;
		top: 0;
		bottom: 0;
		width: 100%;
		height: 100%;
		z-index: 10;
		background-color: rgba(0, 0, 0, 0.3);

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

				&.primary {
					background: #2A7EFB;
					color: #FFFFFF;
				}
			}
		}
	}

	.shoppingCart .content {
		margin-top: 20rpx;

		.list_top {
			background-color: #FFFFFF;
			font-size: 28upx;
			color: #333333;
			height: 90upx;
			line-height: 90upx;
			padding-left: 24upx;
			border-bottom: 2upx solid #EEEEEE;

			.bluefont {
				color: #1890FF;
				display: inline-block;
				margin: 0upx 10upx;
			}

			.garyfont {
				color: #999999;
			}
		}
	}
</style>