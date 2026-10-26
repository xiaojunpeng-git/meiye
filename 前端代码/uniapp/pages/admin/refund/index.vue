<template>
	<view class="refund">
		<view class="money-section">
			<view class="acea-row row-middle item tip">
				只能整笔退款一次，不能分商品退款。
			</view>
			<view class="acea-row row-middle item">
				<view>订单编号</view>
				<view class="value">{{ orderNo || '-' }}</view>
			</view>
			<view class="acea-row row-middle item">
				<view>实付金额</view>
				<view class="value price">￥{{ payPrice }}</view>
			</view>
			<view class="acea-row row-middle item">
				<view>退款日期</view>
				<picker mode="date" :value="refundDate" :start="payDate" :end="today" @change="onDateChange">
					<view class="value">{{ refundDate }}</view>
				</picker>
			</view>
			<view class="acea-row row-middle item">
				<view>退款金额</view>
				<input v-model="refundMoney" class="input" type="digit" />
			</view>
			<template v-if="showBalance">
				<view class="acea-row row-middle item">
					<view>退本金</view>
					<input v-model="refundBen" class="input" type="digit" />
				</view>
				<view class="acea-row row-middle item">
					<view>退赠金</view>
					<input v-model="refundGive" class="input" type="digit" />
				</view>
				<view class="acea-row row-middle item">
					<view>总余额</view>
					<view class="value">￥{{ balanceSum }}</view>
				</view>
			</template>
			<template v-if="showBookkeeping">
				<view class="acea-row row-middle item" @click="bookkeepingConfirmed = !bookkeepingConfirmed">
					<text class="iconfont" :class="bookkeepingConfirmed ? 'icon-ic_Selected' : 'icon-ic_unselect'"></text>
					<text class="confirm-text">我已确认线下退回记账款项</text>
				</view>
				<view class="item remark-box">
					<view class="mb8">记账退款备注</view>
					<textarea v-model="bookkeepingRemark" class="textarea" placeholder="请填写线下退回说明（必填）" />
				</view>
			</template>
		</view>
		<view class="footer acea-row row-middle">
			<view class="btn-box">
				<view class="btn" @click="submitRefund">确认整单退款</view>
			</view>
		</view>
	</view>
</template>

<script>
	import {
		getAdminOrderDetail,
		terminalOrderRefund,
	} from '@/api/admin.js';

	function ymd(d) {
		const date = d instanceof Date ? d : new Date(d);
		if (Number.isNaN(date.getTime())) return '';
		const y = date.getFullYear();
		const m = `${date.getMonth() + 1}`.padStart(2, '0');
		const day = `${date.getDate()}`.padStart(2, '0');
		return `${y}-${m}-${day}`;
	}

	function hasBookkeeping(info) {
		if (!info) return false;
		const payType = String(info.pay_type || '');
		const payPrice = Number(info.pay_price || 0);
		const cashPay = Number(info.cash_pay_price || 0);
		if (payType === 'cash' && payPrice > 0) return true;
		if (cashPay > 0) return true;
		if (Number(info.cash_choose) === 7 && (payType === 'cash' || cashPay > 0 || payPrice > 0)) return true;
		return false;
	}

	export default {
		data() {
			return {
				orderId: 0,
				listId: 0,
				orderNo: '',
				payPrice: '0.00',
				refundMoney: '0',
				refundBen: '0',
				refundGive: '0',
				refundDate: ymd(new Date()),
				payDate: ymd(new Date()),
				today: ymd(new Date()),
				showBalance: false,
				showBookkeeping: false,
				bookkeepingConfirmed: false,
				bookkeepingRemark: '',
				requestToken: '',
				orderInfo: {},
			};
		},
		computed: {
			balanceSum() {
				const ben = parseFloat(this.refundBen) || 0;
				const give = parseFloat(this.refundGive) || 0;
				return (ben + give).toFixed(2);
			},
		},
		onLoad(option) {
			this.orderId = option.id;
			this.listId = option.listId;
			this.requestToken = `admin_refund_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
			this.getIndex();
		},
		methods: {
			onDateChange(e) {
				this.refundDate = e.detail.value;
			},
			getIndex() {
				getAdminOrderDetail(this.orderId).then(res => {
					const info = (res.data && (res.data.orderInfo || res.data)) || {};
					this.orderInfo = info;
					this.orderNo = info.order_id || '';
					this.payPrice = Number(info.pay_price || 0).toFixed(2);
					this.refundMoney = this.payPrice;
					const payTs = Number(info.pay_time || info._pay_time || 0);
					this.payDate = payTs > 0 ? ymd(payTs < 1e12 ? payTs * 1000 : payTs) : this.today;
					this.showBalance = info.pay_type === 'yue' || info.pay_type === 'combination' || Number(info.yue_pay_price || 0) > 0;
					if (this.showBalance) {
						this.refundBen = String(info.paid_ben_amount != null ? info.paid_ben_amount : (info.yue_pay_price || 0));
						this.refundGive = String(info.paid_give_amount != null ? info.paid_give_amount : 0);
					}
					this.showBookkeeping = hasBookkeeping(info);
					this.bookkeepingConfirmed = false;
					this.bookkeepingRemark = '';
				}).catch(err => {
					this.$util.Tips({ title: err.msg || err });
				});
			},
			submitRefund() {
				if (!this.refundDate) {
					return this.$util.Tips({ title: '请选择退款日期' });
				}
				if (this.refundDate > this.today) {
					return this.$util.Tips({ title: '退款日期不能晚于今天' });
				}
				if (this.refundDate < this.payDate) {
					return this.$util.Tips({ title: '退款日期不能早于支付日期' });
				}
				if (this.showBalance && (this.refundBen === '' || this.refundGive === '')) {
					return this.$util.Tips({ title: '请填写本金和赠金' });
				}
				if (this.showBookkeeping) {
					if (!this.bookkeepingConfirmed) {
						return this.$util.Tips({ title: '请勾选：我已确认线下退回记账款项' });
					}
					if (!String(this.bookkeepingRemark || '').trim()) {
						return this.$util.Tips({ title: '请填写记账退款备注' });
					}
				}
				const data = {
					refund_price: this.refundMoney,
					refund_amount: this.refundMoney,
					refund_ben: this.showBalance ? this.refundBen : '0',
					refund_give: this.showBalance ? this.refundGive : '0',
					refund_business_date: this.refundDate,
					request_token: this.requestToken,
					bookkeeping_confirmed: this.showBookkeeping && this.bookkeepingConfirmed ? 1 : 0,
					bookkeeping_remark: this.showBookkeeping ? String(this.bookkeepingRemark || '').trim() : '',
					type: 1,
				};
				terminalOrderRefund(this.listId, data).then(res => {
					this.$util.Tips({
						title: res.msg || '退款成功',
						icon: 'success'
					}, {
						tab: 3,
						url: 1,
					});
				}).catch(err => {
					this.$util.Tips({ title: err.msg || err });
				});
			},
		},
	}
</script>

<style lang="scss" scoped>
	.refund {
		padding: 22rpx 20rpx 160rpx;
	}

	.money-section {
		padding: 12rpx 0;
		border-radius: 24rpx;
		background: #FFFFFF;

		.item {
			min-height: 80rpx;
			padding: 0 24rpx;
			font-size: 28rpx;
			color: #333333;
		}

		.tip {
			color: #FF7E00;
			font-size: 24rpx;
		}

		.value {
			flex: 1;
			text-align: right;
		}

		.price {
			color: #FF7E00;
			font-family: Regular;
			font-size: 36rpx;
		}

		.input {
			flex: 1;
			height: 80rpx;
			text-align: right;
			font-family: Regular;
			font-size: 36rpx;
			color: #FF7E00;
		}

		.confirm-text {
			margin-left: 12rpx;
		}

		.iconfont {
			font-size: 32rpx;
			color: #2A7EFB;
		}

		.remark-box {
			display: block;
			padding-bottom: 20rpx;
		}

		.textarea {
			width: 100%;
			min-height: 140rpx;
			margin-top: 12rpx;
			padding: 16rpx;
			border-radius: 12rpx;
			background: #F7F7F7;
			font-size: 26rpx;
		}

		.mb8 {
			margin-bottom: 8rpx;
		}
	}

	.footer {
		position: fixed;
		bottom: 0;
		left: 0;
		width: 100%;
		padding: 16rpx 20rpx;
		padding-bottom: calc(16rpx + constant(safe-area-inset-bottom));
		padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
		background: #FFFFFF;

		.btn-box {
			flex: 1;
		}

		.btn {
			height: 64rpx;
			border-radius: 32rpx;
			background: #2A7EFB;
			font-weight: 500;
			font-size: 26rpx;
			line-height: 64rpx;
			color: #FFFFFF;
			text-align: center;
		}
	}
</style>
