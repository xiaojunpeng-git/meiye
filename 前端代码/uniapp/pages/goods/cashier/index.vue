<template>
	<view :style="colorStyle">
		<view class="flex-col flex-center py-78">
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
				dotColor="var(--view-theme)"
				colors='var(--view-theme)'
				:datatime="invalidTime"></countDown>
			</view>
		</view>
		<view class="px-20">
			<view class="bg--w111-fff rd-24rpx pay_card">
				<view class="flex-between-center pay_item"
					v-for="(item, index) in payTypeList" :key="index"
					 @tap="payType(item.value, index)">
					<view class="flex-y-center">
						<image class="w-52 h-52" :src="item.icon"></image>
						<view class="pl-20">
							<view class="text--w111-333 fs-28 lh-38rpx">{{item.name}}</view>
							<view class="fs-22 text--w111-999 lh-30rpx mt-8"
								v-if="item.value == 'yue'">可用余额¥{{now_money}}</view>
							<view class="fs-22 text--w111-999 lh-30rpx mt-8" v-else>{{item.title}}</view>
						</view>
					</view>
					<text class="iconfont fs-40 text--w111-999" :class="active==index ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect'"></text>
				</view>
			</view>
		</view>
		<view class="w-full h-128 fixed-lb pb-safe flex-center">
			<view class="w-710 h-80 flex-center rd-40rpx text--w111-fff bg-color fs-28" @click="goPay()">{{ paying ? '正在发起支付' : '确认付款' }}</view>
		</view>
		<view v-show="false" v-html="formContent"></view>
	</view>
</template>

<script>
	import { mapGetters } from "vuex";
	import countDown from '@/components/countDown';
	import colors from "@/mixins/color";
	import { getCashierOrder, orderPay } from "@/api/order";
	import { getDebtCashier, debtRepayPay } from "@/api/debt";
	import { rechargePayAPi, memberCardPayApi } from "@/api/user.js"
	import { HTTP_REQUEST_URL } from '@/config/app';
	export default {
		data() {
			return {
				invalidTime: 0,
				checked:false,
        is_bak:0,
				orderId: 0,
				fromType: '',
				active: -1,
				payPrice: 0,
				payPriceShow: 0,
				now_money:'',
				payPostage: 0,
				offlinePostage: false,
				initIn: false,
				jumpData: {
					orderId: '',
					msg: ''
				},
				formContent: '',
				cartArr: [
					{
						"name": "微信支付",
						"icon": HTTP_REQUEST_URL + "/statics/images/order/wx_pay.png",
						value: 'weixin',
						title: '使用微信快捷支付',
						payStatus: 1,
					},
					{
						"name": "支付宝支付",
						"icon": HTTP_REQUEST_URL + "/statics/images/order/alipay.png",
						value: 'alipay',
						title: '使用线上支付宝支付',
						payStatus: 1,
					},
					{
						"name": "余额支付",
						"icon": HTTP_REQUEST_URL + "/statics/images/order/yue_pay.png",
						value: 'yue',
						title: '可用余额:',
						payStatus: 1,
					},
					{
						"name": "线下支付",
						"icon": HTTP_REQUEST_URL + "/statics/images/order/xianxia_pay.png",
						value: 'offline',
						title: '选择线下付款方式',
						payStatus: 2,
					}
				],
				paytype:'',
				paying: false,
				payTypeList:[]
			}
		},
		mixins: [colors],
		components: {
			countDown,
		},
		computed:{
			...mapGetters(['cartNum'])
		},
		onLoad(options) {
      if(options.is_bak){
        this.is_bak=options.is_bak;
      }
      // if(options.qrcode){
      //   this.appPay(options.qrcode);
      // }
			if (options.order_id) this.orderId = options.order_id;
			if (options.from_type) this.fromType = options.from_type;
			this.getCashierOrder();
		},
		methods: {
      // appPay(qrcode){
      //   // #ifdef MP
      //   //调用富友支付插件
      //   uni.setStorageSync('fy_pay_type', 'recharge');
      //   wx.openEmbeddedMiniProgram({
      //     appId: '',
      //     path: `/pages/qrPay/qrPay?t=`+qrcode, // token从接口获取
      //     envVersion: 'release', // 打开环境
      //   })
      //   // #endif
      // },
			payList(){
				let list = [];
				this.cartArr.forEach(item=>{
					if(item.payStatus == 1){
						list.push(item);
					}
				})
				list.length && this.payType(list[0].value, 0)
				this.payTypeList = list;
			},
			payType(paytype, index) {
				this.active = index;
				this.paytype = paytype;
				if (this.offlinePostage) {
					if (paytype == 'offline') {
						this.payPriceShow = this.$util.$h.Sub(this.payPrice, this.payPostage);
					} else {
						this.payPriceShow = this.payPrice;
					}
				}
			},
			getCashierOrder() {
				const loadApi = this.fromType === 'debt'
					? getDebtCashier(this.orderId)
					: getCashierOrder(this.orderId, this.fromType);
				loadApi.then(res => {
					if(this.fromType == 'order'){
						uni.$emit('newCartNum',res.data.cart_info);
						let totalNum = 0;
						res.data.cart_info.forEach(item=>{
							totalNum = totalNum + item.cart_num
						})
						let colNum = this.cartNum - totalNum;
						this.$store.commit('indexData/setCartNum', colNum +'');
					}
					//微信支付是否开启
					this.cartArr[0].payStatus = res.data.pay_weixin_open || 0
					//支付宝是否开启
					// #ifdef MP-WEIXIN
					/*微信小程序环境中不允许支付宝支付*/
					this.cartArr[1].payStatus = 0;
					// #endif
					// #ifdef H5
					/*微信公众号环境中不允许支付宝支付*/
					this.cartArr[1].payStatus = this.$wechat.isWeixin() ? 0 : res.data.ali_pay_status;
					// #endif
					// #ifdef APP-PLUS
					this.cartArr[1].payStatus = res.data.ali_pay_status || 0;
					// #endif
					//余额支付是否开启
					this.cartArr[2].payStatus = res.data.yue_pay_status;
					this.now_money = res.data.now_money;
					//线下支付是否开启
					if (res.data.offline_pay_status == 1) {
						this.cartArr[3].payStatus = 1
					} else {
						this.cartArr[3].payStatus = 0
					}
					// 订单价格
					this.payPrice = this.payPriceShow = res.data.pay_price
					//剩余时间
					this.invalidTime = res.data.invalid_time;
					// 邮费
					this.payPostage = res.data.pay_postage;
					this.getShowPay();
					this.payList()
				}).catch(err => {
					uni.hideLoading();
					return this.$util.Tips({
						title: err
					})
				})
			},
			getShowPay(){
				//付费会员购买和余额充值不允许使用线下支付和余额支付，未开启线上支付支付的话给出提示并且返回上一页
				//检查支付类型列表数组的payStatus是不是都是0或者2
				const isAllPayStatusZero = this.cartArr.every(item => item.payStatus == 0 || item.payStatus == 2);
				if(isAllPayStatusZero && ['vip','recharge'].includes(this.fromType)){
					return this.$util.Tips({
						title: '未开启线上支付，请联系管理员'
					}, {
						tab: 3,
					});
				}
			},
			goPay(){
				let that = this;
				if (that.paying) return;
				if(that.active == -1) return that.$util.Tips({
					title: '请选择付款方式'
				});
				if (!that.orderId) return that.$util.Tips({
					title: '请选择要支付的订单'
				});
				if (that.paytype == 'yue' && parseFloat(this.now_money) < parseFloat(that.payPriceShow)) return that.$util.Tips({
					title: '余额不足'
				});

				uni.showLoading({
					title: '支付中'
				});
				that.paying = true;

				let funApi = '';
				if(this.fromType == 'order'){
					funApi = orderPay({
						uni: that.orderId,
						paytype: that.paytype,
						// #ifdef MP
						'from': 'routine',
						// #endif
						// #ifdef H5
						'from': this.$wechat.isWeixin() ? 'weixin' : 'weixinh5',
						// #endif
						// #ifdef H5
						quitUrl: location.port ? location.protocol + '//' + location.hostname + ':' + location.port + '/pages/goods/order_pay_status/index?order_id=' + this.orderId : location.protocol + '//' + location.hostname +'/pages/goods/order_pay_status/index?order_id=' + this.orderId
						// #endif
						// #ifdef APP-PLUS
						quitUrl: '/pages/goods/order_pay_status/index?order_id=' + this.orderId
						// #endif
					})
				}else if(this.fromType == 'recharge'){
					funApi = rechargePayAPi({
						uni: this.orderId,
						paytype: that.paytype,
						// #ifdef MP
						'from': 'routine',
						// #endif
						// #ifdef H5
						'from': this.$wechat.isWeixin() ? 'weixin' : 'weixinh5',
						// #endif
						// #ifdef H5
						quitUrl: location.port ? location.protocol + '//' + location.hostname + ':' + location.port + '/pages/users/user_payment/index' : location.protocol + '//' + location.hostname +'/pages/users/user_payment/index'
						// #endif
						// #ifdef APP-PLUS
						quitUrl: '/pages/users/user_payment/index'
						// #endif
					})
				}else if(this.fromType == 'debt'){
					funApi = debtRepayPay({
						order_id: that.orderId,
						paytype: that.paytype,
						repay_amount: that.payPriceShow,
						// #ifdef MP
						'from': 'routine',
						// #endif
						// #ifdef H5
						'from': this.$wechat.isWeixin() ? 'weixin' : 'weixinh5',
						// #endif
						// #ifdef H5
						quitUrl: location.port ? location.protocol + '//' + location.hostname + ':' + location.port + '/pages/goods/order_details/index?order_id=' + this.orderId : location.protocol + '//' + location.hostname + '/pages/goods/order_details/index?order_id=' + this.orderId
						// #endif
						// #ifdef APP-PLUS
						quitUrl: '/pages/goods/order_details/index?order_id=' + this.orderId
						// #endif
					})
				}else if(this.fromType == 'vip'){
					funApi = memberCardPayApi({
						uni: this.orderId,
						paytype: this.paytype,
						// #ifdef MP
						'from': 'routine',
						// #endif
						// #ifdef H5
						'from': this.$wechat.isWeixin() ? 'weixin' : 'weixinh5',
						quitUrl: '/pages/annex/vip_paid/index',
						// #endif
						// #ifdef APP-PLUS
						quitUrl: '/pages/annex/vip_paid/index',
						// #endif
					})
				}

				funApi.then(res=>{
					const result = res.data.result || {};
					let status = res.data.status,
					orderId = result.order_id || '',
					jsConfig = result.jsConfig || {};
          let qr_code=jsConfig.qr_code || '';
          let urlLink=jsConfig.url_link || '';
          if(qr_code != ''){
            // #ifdef MP
            uni.hideLoading();
            uni.setStorageSync('fy_pay_type', that.is_bak == 1 ? 'isBack' : that.fromType);
            wx.openEmbeddedMiniProgram({
              appId: jsConfig.app_id,
              path: `/pages/qrPay/qrPay?t=${encodeURIComponent(qr_code)}`,
              envVersion: 'release',
              fail: (error) => {
				that.$util.Tips({
                  title: (error && error.errMsg) || '未能打开富友支付'
				});
				that.paying = false;
              }
            })
            // #endif
            return false;
          }
          if(urlLink != ''){
			uni.hideLoading();
			that.paying = false;
            // #ifdef APP-PLUS
            uni.navigateTo({
              url: `/pages/annex/web_view/index?url=${encodeURIComponent(urlLink)}`
            });
            // #endif
            // #ifdef H5
            window.location.href = urlLink;
            // #endif
            return false;
          }
					//页面回调地址
					let PageObj = {
						'order': '/pages/goods/order_pay_status/index?order_id=' + (orderId || this.orderId) + '&msg=' +res.msg +'&type=3' + '&totalPrice=' + this.payPriceShow,
						'recharge': '/pages/users/user_payment/index',
						'vip': '/pages/annex/vip_paid/index',
						'debt': '/pages/goods/order_details/index?order_id=' + (orderId || this.orderId),
					};
					let backUrl = PageObj[this.fromType];
				switch (status) {
					case 'ORDER_EXIST':
					case 'EXTEND_ORDER':
					case 'PAY_ERROR':
						this.pageReject(res.msg,backUrl);
						break;
					case 'SUCCESS':
						this.pageReject(res.msg,backUrl);
						break;
					case 'WECHAT_PAY':
						this.wechatPayFun(jsConfig,backUrl);
						break;
					case 'PAY_DEFICIENCY':
						uni.hideLoading();
						this.pageReject(res.msg,backUrl);
						break;
					case "WECHAT_H5_PAY":
						uni.hideLoading();
						setTimeout(() => {
							location.href = res.data.result.jsConfig.mweb_url + '&redirect_url=' + window.location.protocol + '//' + window.location.host + backUrl;
						}, 500);
						break;

					case 'ALIPAY_PAY':
						//#ifdef H5
						uni.hideLoading();
						that.formContent = res.data.result.jsConfig;
						that.$nextTick(() => {
							document.getElementById('alipaysubmit').submit();
						})
						//#endif
						// #ifdef APP-PLUS
						uni.requestPayment({
							provider: 'alipay',
							orderInfo: jsConfig,
							success: (e) => {
								that.pageReject('支付成功',backUrl);
							},
							fail: (e) => {
								that.pageReject('支付失败',backUrl);
							},
							complete: () => {
								uni.hideLoading();
							},
						});
						// #endif
						break;
					}
				}).catch(err=>{
					uni.hideLoading();
					that.paying = false;
					return that.$util.Tips({
						title: (err && err.msg) || String(err || '支付发起失败')
					});
				})
			},
			wechatPayFun(jsConfig, backUrl){
				let that = this;
				// #ifdef MP
				uni.requestPayment({
					timeStamp: jsConfig.timestamp,
					nonceStr: jsConfig.nonceStr,
					package: jsConfig.package,
					signType: jsConfig.signType,
					paySign: jsConfig.paySign,
					success: function(res) {
						console.log("success");
						that.pageReject('支付成功',backUrl);
					},
					fail: function(e) {
						console.log("fail");
						that.pageReject('支付失败',backUrl);
					},
				})
				// #endif
				// #ifdef H5
				this.$wechat.pay(jsConfig).then(res => {
					this.pageReject('支付成功',backUrl);
				}).catch(res => {
					if (!this.$wechat.isWeixin()) {
						this.pageReject('支付失败',backUrl);
					}
					if (res.errMsg == 'chooseWXPay:cancel') {
						this.pageReject('取消支付',backUrl);
					}
				})
				// #endif
				// #ifdef APP-PLUS
				uni.requestPayment({
					provider: 'wxpay',
					orderInfo: jsConfig,
					success: (e) => {
						that.pageReject('支付成功',backUrl);
					},
					fail: (e) => {
						that.pageReject('支付失败',backUrl);
					},
				});
				// #endif
			},
			pageReject(msg,backUrl){
				uni.hideLoading();
				this.paying = false;
				return this.$util.Tips({
					title: msg
				}, {
					tab: 5,
					url: backUrl
				});
			}
		}
	}
</script>

<style lang="scss">
::v-deep  .styleAll{
	padding: 0 6rpx;
	border: 1rpx solid #DDDDDD;
	border-radius: 8rpx;
}
.pay_card{
	padding: 40rpx 32rpx;
}
.pay_item ~ .pay_item{
	margin-top: 56rpx;
}
.icon-ic_unselect{
	color: #ccc;
}
.icon-a-ic_CompleteSelect{
	color: var(--view-theme)
}
</style>
