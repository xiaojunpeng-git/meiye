<template>
	<view>
		<view class="flex-col flex-center py-80">
			<baseMoney :money="payPriceShow" symbolSize="48" integerSize="64" decimalSize="48" color="#333" weight></baseMoney>
			<view class="flex-y-center mt-20">
				<text class="fs-24 text--w111-333 lh-36rpx pr-10">支付剩余时间</text>
				<countDown
				:is-day="false"
				tip-text=" "
				day-text=" "
				hour-text=":"
				minute-text=":"
				second-text=" "
				bgColor="#FFFFFF"
				colors="#FF7E00"
				dotColor="#FF7E00"
				:datatime="invalidTime"></countDown>
			</view>
		</view>
		<view class="px-20">
			<view class="bg--w111-fff rd-24rpx px-24">
				<view class="pt-60 flex-center fs-30 lh-42rpx fw-500">
					<text>方式一：</text>
					<view class="flex-y-center pl-30" @tap="checkType('weixin')">
						<text class="iconfont" :class="paytype == 'weixin' ? 'icon-ic_Selected' : 'icon-ic_unselect'"></text>
						<text class="pl-10">微信扫码</text>
					</view>
					<view class="flex-y-center ml-50" @tap="checkType('alipay')">
						<text class="iconfont" :class="paytype == 'alipay' ? 'icon-ic_Selected' : 'icon-ic_unselect'"></text>
						<text class="pl-10">支付宝扫码</text>
					</view>
				</view>
				<view class="flex-center mt-40 pb-50 border-b" v-show="config.code">
					<w-qrcode :options="config"></w-qrcode>
				</view>
				<view class="pt-50 flex-center fs-30 lh-42rpx fw-500">方式二：用户进入商城支付订单</view>
				<view class="py-60 flex-center">
					<image class="guide" :src="imgHost + '/statics/images/pay_guide.png'"></image>
				</view>
			</view>
		</view>
		<view class="h-200"></view>
		<view class="fixed-lb w-full pb-safe">
			<view class="w-full h-128 flex-center">
				<view class="w-710 h-80 flex-center rd-40rpx fs-28 bg-primary text--w111-fff" @tap="backPage">返回</view>
			</view>
		</view>
	</view>
</template>

<script>
	import countDown from '@/components/countDown';
	import { getCashierApi, getPayStatusApi } from "@/api/admin.js";
	import {HTTP_REQUEST_URL} from '@/config/app';
	export default {
		data() {
			return {
				imgHost:HTTP_REQUEST_URL,
				payPriceShow:0,
				invalidTime:0,
				orderId: '',
				paytype:'weixin',
				userId:0,
				qrcode:'',
				config: {
					code: '',
					size: 280, // 二维码大小
					level: 3, //等级 0～4
					bgColor: '#FFFFFF',
					color: ['#333', '#333'], //边框颜色支持渐变色
				},
				timer: null
			}
		},
		components: {
			countDown,
		},
		onLoad(options) {
			this.userId = options.uid || 0;
			if (options.order_id) {
				this.orderId = options.order_id;
				this.getCashierOrder();
			}
			
		},
		onUnload() {
			this.stopSetInterval();
		},
		methods: {
			getCashierOrder(){
				let data = {
					uni: this.orderId,
					paytype: this.paytype,
					quitUrl: '/pages/behalf/cashier/index'
				};
				getCashierApi(this.userId,data).then(res=>{
					if(res.data.status == 'SUCCESS'){
						return this.$util.Tips({
							title: '支付成功'
						}, {
							tab: 5,
							url: '/pages/behalf/record/index'
						});
					}
					this.payPriceShow = res.data.result.pay_price;
					this.invalidTime = res.data.result.jsConfig.invalid;
					this.config.code = this.paytype == 'weixin' ? res.data.result.jsConfig.code_url : res.data.result.jsConfig.qrCode;
					this.createSetInterval(res.data.result.order_id);
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					})
				})
			},
			checkType(val){
				this.paytype = val;
				this.getCashierOrder();
			},
			createSetInterval(order_id) {
			    this.stopSetInterval();
			    this.timer = setInterval(() => {
			        this.getOrderResult(order_id);
			    }, 2000);
			},
			stopSetInterval() {
			    if (this.timer) {
			        clearInterval(this.timer);
			        this.timer = null;
			    }
			},
			getOrderResult(id){
			    //接口响应成功以后销毁定时器
				getPayStatusApi({
					order_id:id,
					end_time:this.invalidTime
				}).then(res=>{
					if(res.data.time == 0){
						this.stopSetInterval();
						this.getCashierOrder();
					}
					if(res.data.status || res.data.time == 0){
						this.stopSetInterval();
						uni.reLaunch({
							url:'/pages/behalf/record/index'
						})
					}
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					})
				})
			},
			backPage(){
				uni.reLaunch({
					url:'/pages/admin/work/index'
				})
			}
		}
	}
</script>

<style lang="scss">
/deep/ .styleAll{
	padding: 0 6rpx;
	border: 1rpx solid #DDDDDD;
	border-radius: 8rpx;
	font-family: Regular;
	line-height: 40rpx;
}
.border-b{
	border-bottom: 1px dashed #ccc;
}
.icon-ic_Selected{
	color: $primary-admin;
}
.bg-primary{
	background-color: $primary-admin;
}
.guide{
	width: 570rpx;
	height: 214rpx;
}
</style>
