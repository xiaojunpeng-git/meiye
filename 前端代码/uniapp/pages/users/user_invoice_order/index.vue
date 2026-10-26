<template>
	<view :style="colorStyle">
		<!-- #ifdef MP || APP-PLUS -->
		<NavBar titleText="发票详情" :iconColor="iconColor" :textColor="iconColor" :isScrolling="isScrolling" showBack></NavBar>
		<!-- #endif -->
		<view class="headerBg"></view>
		<view class="status">
			<view class="name">{{invoice.is_invoice?(invoice.is_invoice==1?'已开票':'已拒绝'):'待开票'}}</view>
			<view>{{invoice.add_time}}</view>
			<image :src="imgHost+'/statics/images/invoice-top.png'" class="image"></image>
		</view>
		<view class="panel">
			<view class="panel-inner" :style="{backgroundImage:'url('+imgHost+'/statics/images/invoice-border-left.png),url('+imgHost+'/statics/images/invoice-border-right.png)'}">
				<view class="title" :style="{backgroundImage:'url('+imgHost+'/statics/images/invoice-title.png)'}"><text
						class="inner">{{invoice.header_type==1?'个人':'企业'}}{{invoice.type==1?'普通':'专用'}}发票</text></view>
				<view class="money">
					<view class="name">开票金额</view>
					<BaseMoney :money="invoice.invoice_amount == '0.00' ? invoice_detail.pay_price : invoice.invoice_amount" symbolSize="40" integerSize="56" decimalSize="56" weight></BaseMoney>
				</view>
				<view class="list">
					<view class="item acea-row">
						<view class="name">发票类型</view>
						<view class="value">电子{{invoice.type==1?'普通':'专用'}}发票</view>
					</view>
					<view class="item acea-row">
						<view class="name">抬头类型</view>
						<view class="value">{{invoice.header_type==1?'个人':'企业'}}</view>
					</view>
					<view class="item acea-row">
						<view class="name">抬头名称</view>
						<view class="value">{{invoice.name}}</view>
					</view>
					<template v-if="invoice.header_type==2">
						<view class="item acea-row">
							<view class="name">单位税号</view>
							<view class="value">{{invoice.duty_number}}</view>
						</view>
						<view v-if="invoice.type == 2" class="item acea-row">
							<view class="name">开户银行</view>
							<view class="value">{{invoice.bank}}</view>
						</view>
						<view v-if="invoice.type == 2" class="item acea-row">
							<view class="name">银行账号</view>
							<view class="value">{{invoice.card_number}}</view>
						</view>
						<view v-if="invoice.type == 2" class="item acea-row">
							<view class="name">企业地址</view>
							<view class="value">{{invoice.address}}</view>
						</view>
					</template>
					<view class="item acea-row" v-if="invoice.invoice_number">
						<view class="name">发票编号</view>
						<view class="value">{{invoice.invoice_number}}</view>
					</view>
				</view>
			</view>
		</view>
		<view class="panel">
			<view class="panel-inner" :style="{backgroundImage:'url('+imgHost+'/statics/images/invoice-border-left.png),url('+imgHost+'/statics/images/invoice-border-right.png)'}">
				<view class="list">
					<view class="item acea-row">
						<view class="name">{{invoice.header_type==1?'联系':'企业'}}电话</view>
						<view class="value">{{invoice.drawer_phone}}</view>
					</view>
					<view class="item acea-row">
						<view class="name">电子邮箱</view>
						<view class="value">{{invoice.email}}</view>
					</view>
					<view class="item acea-row">
						<view class="name">申请时间</view>
						<view class="value">{{invoice.add_time}}</view>
					</view>
				</view>
			</view>
		</view>
		<view v-if="invoice.is_invoice" class="panel">
			<view class="panel-inner" :style="{backgroundImage:'url('+imgHost+'/statics/images/invoice-border-left.png),url('+imgHost+'/statics/images/invoice-border-right.png)'}">
				<view class="list">
					<view class="item acea-row">
						<view class="name">开票结果</view>
						<view class="value">{{invoice.is_invoice?(invoice.is_invoice==1?'已开票':'已拒绝'):'待开票'}}</view>
					</view>
					<view v-if="invoice.is_invoice == 1 && invoice.remark" class="item acea-row">
						<view class="name">发票备注</view>
						<view class="value">{{invoice.remark}}</view>
					</view>
					<view v-else-if="invoice.is_invoice == -1 && invoice.remark" class="item acea-row">
						<view class="name">拒绝原因</view>
						<view class="value">{{invoice.remark}}</view>
					</view>
					<view class="item acea-row">
						<view class="name">操作时间</view>
						<view class="value">{{invoice.invoice_time}}</view>
					</view>
				</view>
			</view>
		</view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>
<script>
	import {
		orderInvoiceDetail
	} from '@/api/order.js';
	import home from '@/components/home';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import colors from "@/mixins/color";
	import NavBar from '@/components/NavBar.vue';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		components: {
			home,
			NavBar,
		},
		mixins: [colors],
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				// #ifdef MP || APP-PLUS
				getHeight: this.$util.getWXStatusHeight(),
				iconColor: '#FFFFFF',
				isScrolling: false,
				// #endif
				order_id: '',
				evaluate: 0,
				cartInfo: [], //购物车产品
				orderInfo: {
					system_store: {},
					_status: {}
				}, //订单详情
				system_store: {},
				isGoodsReturn: false, //是否为退款订单
				status: {}, //订单底部按钮状态
				isClose: false,
				pay_close: false,
				pay_order_id: '',
				totalPrice: '0',
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				invoice: {}, //是否隐藏授权
				invoice_detail: {},
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad: function(options) {
			if (options.order_id) {
				this.$set(this, 'order_id', options.order_id);
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			if (this.isLogin) {
				this.getOrderInfo();
			} else {
				toLogin();
			}
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			getOrderInfo: function() {
				let that = this;
				uni.showLoading({
					title: "正在加载中"
				});
				orderInvoiceDetail(this.order_id).then(res => {
					uni.hideLoading();
					that.invoice = res.data.invoice;
					that.invoice_detail = res.data;
				}).catch(err => {
					uni.hideLoading();
					that.$util.Tips({
						title: err
					});
				});
			},
      onhomehide() {
				this.homeHide = false;
			}
		}
	}
</script>

<style lang="scss" scoped>
	.invoiceTitle {
		background: linear-gradient(270deg, #FF7931 0%, #E93323 100%);
		position: fixed;
		left: 0;
		top: 0;
		width: 100%;
		z-index: 99;

		.sysTitle {
			width: 100%;
			position: relative;
			font-weight: 500;
			color: #fff;
			font-size: 30rpx;

			.iconfont {
				position: absolute;
				font-size: 36rpx;
				left: 11rpx;
				width: 60rpx;
			}
		}
	}

	.headerBg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		height: 506rpx;
		background: linear-gradient(270deg, var(--view-gradient) 0%, var(--view-theme) 100%);

		&::after {
			content: "";
			position: absolute;
			bottom: 0;
			left: 0;
			width: 100%;
			height: 120rpx;
			background: linear-gradient(360deg, #F5F5F5 0%, rgba(255, 255, 255, 0) 100%);
		}

		.image {
			position: absolute;
			top: 12rpx;
			right: 32rpx;
			width: 160rpx;
			height: 160rpx;
		}
	}

	.status {
		position: relative;
		height: 172rpx;
		padding: 46rpx 0 0 34rpx;
		font-size: 26rpx;
		line-height: 36rpx;
		color: #FFFFFF;

		.name {
			margin-bottom: 8rpx;
			font-size: 36rpx;
			line-height: 50rpx;
		}

		.image {
			position: absolute;
			right: 32rpx;
			bottom: 0;
			width: 160rpx;
			height: 160rpx;
		}
	}

	.panel {
		position: relative;
		padding: 0 10rpx;
		border-radius: 16rpx;
		margin: 0 32rpx 20rpx;
		background-color: #FFFFFF;

		.panel-inner {
			padding: 32rpx 28rpx;
			background-position: left top, right top;
			background-repeat: repeat-y;
			background-size: 28rpx;
		}

		.title {
			height: 80rpx;
			background-position: center;
			background-repeat: no-repeat;
			background-size: contain;
			text-align: center;
			line-height: 80rpx;

			.inner {
				display: inline-block;
				background-color: #FFFFFF;
				vertical-align: middle;
				font-weight: 500;
				font-size: 30rpx;
				line-height: 36rpx;
				color: #A3442C;
			}
		}

		.money {
			padding: 60rpx 0 38rpx;
			text-align: center;

			.name {
				margin-bottom: 12rpx;
				font-size: 26rpx;
				line-height: 36rpx;
				color: #999999;
			}
		}

		.item {
			padding: 16rpx 50rpx;
			font-size: 28rpx;
			line-height: 40rpx;
			color: #999999;
		}

		.value {
			flex: 1;
			min-width: 0;
			padding-left: 24rpx;
			word-break: break-all;
			color: #333333;
		}
	}
</style>