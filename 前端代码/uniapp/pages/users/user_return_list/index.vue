<template>
	<view :style="colorStyle">
		<view class="top-tabs acea-row row-around row-middle">
			<view class="tabs" :class="{btborder:type === item.key}"
				v-for="(item,index) in tabsList" :key="index"
				@tap="changeTabs(item.key)">
				{{item.name}}
			</view>
		</view>
		<view class="search acea-row row-middle" v-if="orderList.length">
			<text class="iconfont icon-ic_search"></text>
			<input class="inputs" placeholder='根据商品名称/商品编号/订单编号搜索' placeholder-class='placeholder' confirm-type='search' name="search"
				v-model="search" @confirm="searchSubmit"></input>
		</view>
		<view class='return-list' v-if="orderList.length">
			<view class='goodWrapper' v-for="(item,index) in orderList" :key="index"
				@click='goOrderDetails(item.order_id)'>
				<view class="acea-row row-between-wrapper">
					<view class='orderNum'>订单号：{{item.order_id}}</view>
					<view class="label acea-row row-middle" v-if="item.apply_type==1">
						<text class="iconfont icon-ic_returnmoney"></text>仅退款
					</view>
					<view class="label acea-row row-middle" v-if="item.apply_type==2">
						<text class="iconfont icon-ic_returnofgoods"></text>退货退款
					</view>
				</view>
				<view class='item acea-row row-between-wrapper' v-for="(items,indexs) in item.cartInfo" :key="indexs">
					<view class='pictrue'>
						<image class="w-136 h-136 rd-16rpx" :src="items.productInfo.image"></image>
					</view>
					<view class='text pl-20'>
						<view class="nameCon">
							<view class='name line2'>{{items.productInfo.store_name}}</view>
						</view>
						<view class='acea-row row-between-wrapper'>
							<view class='num'>申请数量：{{items.cart_num}}</view>
							<baseMoney :money="items.productInfo.attrInfo?items.productInfo.attrInfo.price:items.productInfo.price" color='#333333' symbolSize="20" integerSize="32"
								decimalSize="20" weight></baseMoney>
						</view>
					</view>
				</view>
				<view class="state acea-row row-middle">
					<view class="stateCon line1">
						<text class="title">{{item._status.status_name}}：</text>
						<text>{{item._status.desc}}</text>
					</view>
					<text class="iconfont icon-ic_rightarrow"></text>
				</view>
				<view class="bottom acea-row row-between-wrapper">
					<baseMoney :money="item.refund_price" textColor='#333' preFix="需退款：" symbolSize="24" integerSize="36"
						decimalSize="24" preFixSize='24' weight v-if="item.refund_type!=3 && item.refund_type!=6"></baseMoney>
					<view class="flex-1 acea-row row-right row-middle">
						<view @click.stop="refundAgain(item.id)" class="bnt flex-center" v-if="item.refund_type==3">再次申请</view>
						<view @click.stop='cancelRefundOrder(item.order_id)' class="bnt flex-center" v-if="[0,1,2,4,5].indexOf(item.refund_type) != -1">撤销售后</view>
						<view @click.stop="refundLogistics(item.order_id)" class="bnt on flex-center" v-if="item.refund_type == 5">返件信息</view>
						<view @click.stop="refundInput(item.order_id)" class="bnt on flex-center" v-if="item.refund_type== 4 && item.is_cancel== 0 && item.apply_type == 2">退回商品</view>
					</view>
				</view>
			</view>
		</view>
		<view class='loadingicon flex-center' v-if="orderList.length > 0">
			<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>
			<text class="fs-26 pb-32">{{loadTitle}}</text>
		</view>
		<view class="px-20" v-if="orderList.length == 0  && !loading">
			<emptyPage title="暂无退款订单～" src="/statics/images/noRefund.gif"></emptyPage>
		</view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import home from '@/components/home';
	import emptyPage from '@/components/emptyPage';
	import {
		getNewOrderList,
		cancelRefundOrder,
		orderRefundAgain
	} from '@/api/order.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import colors from '@/mixins/color.js';
	export default {
		components: {
			home,
			emptyPage
		},
		mixins: [colors],
		data() {
			return {
				search:'',
				type: 5,
				loading: false,
				loadend: false,
				loadTitle: '加载更多', //提示语
				orderList: [], //订单数组
				orderStatus: -3, //订单状态
				page: 1,
				limit: 20,
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				tabsList: [{
					key: 5,
					name: '待处理'
				}, {
					key: 4,
					name: '已退款'
				}, {
					key: 0,
					name: '申请记录'
				}],
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						//#ifndef MP
						this.getOrderList();
						//#endif
					}
				},
				deep: true
			}
		},
		onLoad() {
			if (this.isLogin) {
				this.getOrderList();
			} else {
				toLogin()
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		/**
		 * 页面上拉触底事件的处理函数
		 */
		onReachBottom: function() {
			this.getOrderList();
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			onLoadFun() {
				this.getOrderList();
				this.isShowAuth = false;
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			// 返件信息
			refundLogistics(orderId){
				uni.navigateTo({
					url: '/pages/goods/goods_logistics/index?orderId='+ orderId + '&type=refund'
				})
			},
			// 退回商品
			refundInput(orderId) {
				uni.navigateTo({
					url: `/pages/goods/order_refund_goods/index?orderId=` + orderId
				})
			},
			// 再次申请售后
			refundAgain(orderId){
				orderRefundAgain(orderId).then(res=>{
					uni.showToast({
						title:res.msg,
						icon: 'none'
					});
					this.type = 5;
					this.searchList();
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				})
			},
			cancelRefundOrder(orderId) {
				let that = this;
				uni.showModal({
					title: '撤销售后',
					content: '您确认放弃此次申请吗?',
					success: (res) => {
						if (res.confirm) {
							cancelRefundOrder(orderId).then(res => {
								this.searchList();
							}).catch(err => {
								return that.$util.Tips({
									title: err
								});
							})
						}
					}
				})
			},
			searchSubmit(){
				this.searchList();
			},
			/**
			 * 去订单详情
			 */
			goOrderDetails: function(order_id) {
				if (!order_id) return this.$util.Tips({
					title: '缺少订单号无法查看订单详情'
				});
				uni.navigateTo({
					url: '/pages/goods/order_after_details/index?order_id=' + order_id + '&isReturen=1'
				})
			},

			searchList(){
				this.loadend = false;
				this.page = 1
				this.limit = 20
				this.orderList = []
				this.getOrderList()
			},

			changeTabs(index) {
				this.type = index;
				this.search = '';
				this.searchList();
			},
			/**
			 * 获取订单列表
			 */
			getOrderList() {
				let that = this;
				if (that.loading) return;
				if (that.loadend) return;
				that.loading = true;
				that.loadTitle = "";
				getNewOrderList({
					type: that.orderStatus,
					page: that.page,
					limit: that.limit,
					refund_type: that.type || '',
					search: that.search
				}).then(res => {
					let list = res.data || [];
					let loadend = list.length < that.limit;
					that.orderList = that.orderList.concat(list);
					that.$set(that, 'orderList', that.orderList);
					that.loadend = loadend;
					that.loading = false;
					that.loadTitle = loadend ? "没有更多内容啦~" : '加载更多';
					that.page = that.page + 1;
				}).catch(err => {
					that.loading = false;
					that.loadTitle = "加载更多";
				});
			},
      onhomehide() {
				this.homeHide = false;
			}
		}
	}
</script>

<style lang="scss" scoped>
	.return-list .goodWrapper {
		background-color: #fff;
		position: relative;
		width: 710rpx;
		margin: 0 auto 20rpx auto;
		border-radius: 24rpx;
		padding: 32rpx 24rpx;

		.label{
			font-size: 26rpx;
			font-weight: 400;
			color: #999;

			.iconfont{
				font-size: 32rpx;
				color: var(--view-theme);
				margin-right: 8rpx;
			}
		}
		.state{
			width: 662rpx;
			height: 72rpx;
			background: #F5F5F5;
			border-radius: 8px;
			margin-top: 32rpx;
			font-size: 26rpx;
			font-weight: 400;
			color: #999;
			padding: 0 20rpx;

			.stateCon{
				width: 580rpx;
			}

			.title{
				color: #333333;
				font-size: 26rpx;
				margin-right: 20rpx;
			}

			.iconfont{
				font-size: 26rpx;
				margin-left: 16rpx;
			}
		}
		.bottom{
			margin-top: 32rpx;
		}
		.bnt{
			width: 144rpx;
			height: 56rpx;
			border-radius: 30rpx;
			border: 1px solid #eee;
			font-weight: 400;
			color: #333333;
			font-size: 24rpx;

			&.on{
				background-color: var(--view-theme);
				border-color: var(--view-theme);
				margin-left: 16rpx;
				font-size: 24rpx;
				color: #FFF;
			}
		}
	}

	.return-list .goodWrapper .orderNum {
		font-size: 28rpx;
		color: #333;
		font-weight: 400;
	}

	.return-list .goodWrapper .item {
		margin-top: 32rpx;
		padding: 0;

		.pictrue{
			width: 136rpx;
			height: 136rpx;
			border-radius: 16rpx;
			image{
				border-radius: 16rpx;
			}
		}
		.text{
			width: calc(100% - 136rpx);
			font-weight: 400;
			.nameCon{
				height: 100rpx;
			}
			.name{
				color: #333333;
				width: 100%;
			}
			.num{
				font-size: 24rpx;
				color: #999;
			}
			.money{
				font-size: 24rpx;
				color: var(--view-theme);
				margin: 0;
			}
		}
	}

	.return-list .goodWrapper .totalSum {
		padding: 0 30rpx 32rpx 30rpx;
		text-align: right;
		font-size: 26rpx;
		color: #282828;
	}

	.return-list .goodWrapper .totalSum .price {
		font-size: 28rpx;
		font-weight: bold;
	}

	.top-tabs {
		height: 90rpx;
	}

	.top-tabs .tabs {
		position: relative;
		font-family: PingFang SC
	}

	.btborder {
		font-size: 30rpx;
		font-weight: 600;
		color: var(--view-theme);
		&::after {
			position: absolute;
			content: ' ';
			width: 88rpx;
			height: 6rpx;
			background-color: var(--view-theme);
			bottom: -16rpx;
			left: 50%;
			margin-left: -44rpx;
			border-radius: 200rpx;
		}
	}
	.search{
		width: 710rpx;
		height: 72rpx;
		background: #FFFFFF;
		border-radius: 200rpx;
		margin: 24rpx auto;
		padding: 0 32rpx;

		.iconfont{
			font-size: 34rpx;
			color: #999;
			margin-right: 18rpx;
		}

		.inputs{
			font-size: 28rpx;
			width: 570rpx;
		}

		.placeholder{
			color: #CCCCCC;
		}
	}
</style>
