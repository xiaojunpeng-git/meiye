<template>
	<view :style="colorStyle">
		<view class="w-full fixed-lt z-99 bg-gradient" :style="{'padding-top': sysHeight + 'px'}">
			<!-- #ifndef APP-PLUS -->
			<view class="w-full px-20 pl-20 h-80 flex-between-center">
				<text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff"></text @tap="goIndex">
				<text class="fs-34 fw-500 text--w111-fff">评价抽奖</text>
				<text></text>
			</view>
			<!-- #endif -->
			<view class="flex-col flex-center mt-50">
				<view class="flex-y-center">
					<text class="iconfont icon-ic-complete1 fs-52 text--w111-fff"></text>
					<text class="fs-40 fw-500 text--w111-fff pl-16">评价完成</text>
				</view>
				<view class="flex-center mt-30">
					<view class="w-192 h-64 rd-40rpx flex-center fs-24 text--w111-fff white-border" @tap="goBack">返回订单</view>
					<view class="w-192 h-64 rd-40rpx flex-center fs-24 text--w111-fff white-border ml-32" @tap="goIndex">返回首页</view>
				</view>
			</view>
			<view class="h-216"></view>
		</view>
		<view class="relative content bg--w111-fff w-full pt-32 pl-20 pr-20 z-100" :style="{'top': 164 + sysHeight + 'px'}">
			<view class="card bg--w111-fff rd-24rpx h-544 pt-32 pl-20 pr-20"
				v-show="lotteryShow">
				<gridsLottery 
					:prizeData="prize"
					:lotteryNum="lottery_num" 
					:lotteryType='1' 
					:datatime="datatime"
					@get_winingIndex='getWiningIndex'
					@luck_draw_finish='luck_draw_finish' >
				</gridsLottery>
			</view>
		</view>
		<lotteryAleart 
			:aleartStatus="aleartStatus" 
			theme
			:alData="alData" 
			:aleartType="aleartType"
			@close="closeLottery" >
		    </lotteryAleart>
		<view class="mask" v-if="aleartStatus" @tap="lotteryAleartClose"></view>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import countDown from '@/components/countDown';
	import gridsLottery from '../components/lottery/payLottery.vue'
	import lotteryAleart from '../components/lotteryAleart/index.vue'
	import colors from "@/mixins/color";
	import {
		openOrderSubscribe
	} from '@/utils/SubscribeMessage.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		lotteryUseApi,
		getLotteryData,
		startLottery,
		receiveLottery
	} from '@/api/lottery.js'
	import { postCartAdd } from '@/api/store.js';
	import {
		mapGetters
	} from "vuex";
	import {HTTP_REQUEST_URL} from '@/config/app';
	export default {
		components: {
			gridsLottery,
			lotteryAleart,
			countDown
		},
		mixins: [colors],
		computed: mapGetters(['isLogin']),
		data() {
			return {
				sysHeight:sysHeight,
				lotteryShow: false,
				addressModel: false,
				lottery_num: 0,
				aleartType: 0,
				aleartStatus: false,
				lottery_draw_param: {
					startIndex: 3, //开始抽奖位置，从0开始
					totalCount: 3, //一共要转的圈数
					winingIndex: 1, //中奖的位置，从0开始
					speed: 100 //抽奖动画的速度 [数字越大越慢,默认100]
				},
				alData: {},
				type: '',
				prize: [],
				orderId: '',
				order_pay_info: {
					paid: 1,
					_status: {}
				},
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				couponsHidden: true,
				couponList: [],
				datatime:0,
				imgHost:HTTP_REQUEST_URL
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad(options) {
			this.orderId = options.order_id;
			this.type = options.type;
			this.getLotteryUse();
			// #ifdef H5
			document.addEventListener('visibilitychange', (e) => {
				let state = document.visibilityState
			});
			// #endif
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			if(!this.isLogin){
				toLogin()
			}
		},
		methods: {
			getWiningIndex(callback) {
				this.aleartType = 0
				startLottery({
					id: this.id
				}).then(res => {
					this.prize.forEach((item, index) => {
						if (res.data.id === item.id) {
							this.alData = res.data
							this.lottery_draw_param.winingIndex = index;
							callback(this.lottery_draw_param);
						}
					})
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				})
			},
			/**
			 * 去首页关闭当前所有页面
			 */
			goIndex: function(e) {
				uni.switchTab({
					url: '/pages/index/index'
				});
			},
			goBack(){
				uni.navigateBack()
			},
			getLotteryData(type, id) {
				getLotteryData(type, id).then(res => {
					this.lotteryShow = true
					this.factor_num = res.data.lottery.factor_num
					this.id = res.data.lottery.id
					this.prize = res.data.lottery.prize
					this.lottery_num = res.data.lottery_num
					this.prize.push({
						a: 1
					})
					this.datatime = parseInt(res.data.cache_time);
				}).catch(err => {
					uni.redirectTo({
						url: '/pages/goods/order_details/index?order_id=' + this.orderId
					})
				})
			},
			closeLottery(status) {
				this.aleartStatus = false
				this.getLotteryData(this.type)
				if (this.alData.type === 6) {
				  this.addressModel = true
				  postCartAdd({
				    cartNum: 1,
				    new: 1,
				    is_new: 1,
				    productId: this.alData.product_id,
				    uniqueId: this.alData.unique,
				    luckRecordId: this.alData.lottery_record_id,
				  }).then(res => {
				    uni.navigateTo({
				      url: `/pages/goods/order_confirm/index?new=1&luckRecordId=${this.alData.lottery_record_id}&cartId=${res.data.cartId}&product_id=${this.alData.product_id}`
				    });
				  }).catch(err => {
				    this.$util.Tips({
				      title: `${err},请联系客服`
				    });
				  });
				}
			},
			getWiningIndex(callback) {
				this.aleartType = 0
				startLottery({
					id: this.id
				}).then(res => {
					this.prize.forEach((item, index) => {
						if (res.data.id === item.id) {
							this.alData = res.data
							this.lottery_draw_param.winingIndex = index;
							callback(this.lottery_draw_param);
						}
					})
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				})
				// //props修改在小程序和APP端不成功，所以在这里使用回调函数传参，
			},
			// 抽奖完成
			luck_draw_finish(param) {
				this.aleartType = 2
				this.aleartStatus = true
			},
			getLotteryUse() {
				lotteryUseApi().then(res => {
					const { order_evaluate_lottery } = res.data;
					if (order_evaluate_lottery != 0 && this.isLogin) {
						this.getLotteryData(this.type, order_evaluate_lottery);
					}
				});
			}
		}
	}
</script>

<style lang="scss" scoped>
	.fs-52{
		font-size:52rpx;
	}
	.white-border{
		border: 1px solid #fff;
	}
	.ml-48{
		margin-left: 48rpx;
	}
	.h-216{
		height:216rpx;
	}
	.h-544{
		height:544rpx;
	}
	.content{
		background: #f5f5f5;
		border-radius: 40rpx 40rpx 0 0;
		left:0;
		min-height:500rpx;
	}
	.card ~ .card{
		margin-top: 20rpx;
	}
	.h-76{
		height:76rpx;
	}
	.cell ~ .cell {
		border-top: 1px solid #eee;
	}
	.card_btn{
		width: 114rpx;
		height: 52rpx;
		border-radius: 26rpx;
		border: 1px solid #E93323;
	}
</style>