<template>
	<view :style="colorStyle">
		<view class='cash-withdrawal'>
			<!-- #ifdef MP -->
			<view class="accountTitle">
				<view :style="{height:getHeight.barTop+'px'}"></view>
				<view class="sysTitle acea-row row-center-wrapper" :style="{height:getHeight.barHeight+'px'}">
					<view>提现</view>
					<text class="iconfont icon-ic_leftarrow" @click="goarrow"></text>
				</view>
			</view>
			<view :style="{height:(getHeight.barTop+getHeight.barHeight)+'px'}"></view>
			<!-- #endif -->
			<view class="header">
				<view class="headerCon">
					<view class="money">{{userInfo.commissionCount}}</view>
					<view>可提现金额</view>
				</view>
			</view>
			<view class='wrapper'>
				<view class="nav acea-row" v-if="navList.length == 4">
					<view class="item" :class="[currentIndex==0?'on6':currentIndex==1?'on7':currentIndex==2?'on8':'on9',currentIndex==index?'items':'']"
					v-for="(item,index) in navList" :key="index" @click="swichNav(item.id,index)">
					  {{item.name}}
					</view>
					<view class="navBg" :class="currentIndex==0?'on6':currentIndex==1?'on7':currentIndex==2?'on8':'on9'"></view>
				</view>
				<view class="nav acea-row" v-if="navList.length == 3">
					<view class="item" :class="[currentIndex==0?'on1':currentIndex==1?'on2':'on3',currentIndex==index?'items':'']"
					v-for="(item,index) in navList" :key="index" @click="swichNav(item.id,index)">
					  {{item.name}}
					</view>
					<view class="navBg" :class="currentIndex==0?'on1':currentIndex==1?'on2':'on3'"></view>
				</view>
				<view class="nav acea-row" v-if="navList.length == 2">
					<view class="item on" :class="currentTab == item.id ? 'ons':''" 
					v-for="(item,index) in navList" :key="index" @click="swichNav(item.id,index)">
					  {{item.name}}
					</view>
					<view class="navBg" :class="currentIndex==0?'on4':'on5'"></view>
				</view>
				<view class="nav on" v-if="navList.length == 1">
					<view class="item" v-for="(item,index) in navList" :key="index">
					  {{item.id==0?'提现至银行卡':item.id==1?'提现至余额':item.id==2?'提现至微信':'提现至支付宝'}}
					</view>
				</view>
				<view :hidden='currentTab != 0'>
					<form @submit="subCash">
						<view class='list'>
							<view class="itemCon">
								<view class='item acea-row row-between-wrapper'>
									<view class='name'>持卡人</view>
									<view class='input'><input v-model="cardholder" placeholder='请输入持卡人姓名' placeholder-class='placeholder'
											name="name" onKeypress="javascript:if(event.keyCode == 32)event.returnValue = false;"></input></view>
								</view>
								<view class='item acea-row row-between-wrapper'>
									<view class='name'>卡号</view>
									<view class='input'><input v-model="bank_code" type='number' placeholder='请输入卡号' placeholder-class='placeholder'
											name="cardnum"></input></view>
								</view>
								<view class='item acea-row row-between-wrapper'>
									<view class='name'>银行</view>
									<view class='input'>
										<picker @change="bindPickerChange" :value="index" :range="array">
											<view class="acea-row row-between-wrapper">
												<text class='Bank'>{{array[index]}}</text>
												<text class='iconfont icon-ic_rightarrow'></text>
											</view>
										</picker>
									</view>
								</view>
								<view class='item acea-row row-between-wrapper'>
									<view class='name'>提现</view>
									<view class='input acea-row row-between-wrapper'>
									    <input @input='inputNum' :value='cashVal' :maxlength="moneyMaxLeng" :placeholder='"最低提现金额：¥"+minPrice' placeholder-class='placeholder'
											name="money" type='digit'></input>
										<view class="all" @click="allCash">全部提现</view>
									</view>
								</view>
							</view>
							<view class='tip'>
								当前可提现金额: <text
									class="price">￥{{userInfo.commissionCount}}</text>,冻结佣金：￥{{userInfo.broken_commission}}
							</view>
							<view class='tip'>
								提现手续费: <text class="price">{{withdraw_fee}}%</text>,实际到账:<text class="price">￥{{true_money}}</text>
							</view>
							<view class='tip'>
								说明: <text class="num">每笔佣金的冻结期为{{userInfo.broken_day}}天，到期后可提现</text>
							</view>
						</view>
						<!-- bg-gray -->
						<button formType="submit" class='bnt bg-color'>立即提现</button>
					</form>
				</view>
				<view :hidden='currentTab != 1'>
					<form @submit="subCash">
						<view class="list">
							<view class="itemCon">
								<view class='item acea-row row-between-wrapper'>
									<view class='name'>提现</view>
									<view class='input acea-row row-between-wrapper'>
									    <input @input='inputNum' :value='cashVal' :maxlength="moneyMaxLeng" :placeholder='"最低提现金额：¥"+minPrice' placeholder-class='placeholder'
											name="money" type='digit'></input>
										<view class="all" @click="allCash">全部提现</view>
									</view>
								</view>
							</view>
							<view class='tip'>
								当前可提现金额: <text
									class="price">￥{{userInfo.commissionCount}}</text>,冻结佣金：￥{{userInfo.broken_commission}}
							</view>
							<view class='tip'>
								说明: <text class="num">每笔佣金的冻结期为{{userInfo.broken_day}}天，到期后可提现</text>
							</view>
						</view>
						<button formType="submit" class='bnt bg-color'>立即提现</button>
					</form>
				</view>
				<view :hidden='currentTab != 2'>
					<form @submit="subCash">
						<view class='list'>
							<view class="itemCon">
								<view class='item acea-row row-between-wrapper' v-if="extract_wechat_type == 0">
									<view class='name'>账号</view>
									<view class='input'><input v-model="wechat_account" placeholder='请输入您的微信账号' placeholder-class='placeholder'
											name="weixin" onKeypress="javascript:if(event.keyCode == 32)event.returnValue = false;"></input></view>
								</view>
								<view class='item acea-row row-between-wrapper'>
									<view class='name'>提现</view>
									<view class='input acea-row row-between-wrapper'>
										<input @input='inputNum' :value='cashVal' :maxlength="moneyMaxLeng" :placeholder='"最低提现金额：¥"+minPrice' placeholder-class='placeholder'
											name="money" type='digit'></input>
										<view class="all" @click="allCash">全部提现</view>
									</view>
								</view>
								<view class='item acea-row row-top row-between' v-if="extract_wechat_type == 0">
									<view class='name'>收款码</view>
									<view class="input acea-row">
										<view class="picEwm" v-if="qrcodeUrlW">
											<image :src="qrcodeUrlW"></image>
											<text class='iconfont icon-ic_close fontcolor' @click='DelPicW'></text>
										</view>
										<view class='pictrue acea-row row-center-wrapper row-column' @click='uploadpic("W")'
											v-else>
											<text class='iconfont icon-ic_camera'></text>
											<view>上传图片</view>
										</view>
									</view>
								</view>
							</view>
							<view class='tip'>
								当前可提现金额: <text
									class="price">￥{{userInfo.commissionCount}}</text>,冻结佣金：￥{{userInfo.broken_commission}}
							</view>
							<view class='tip'>
								提现手续费: <text class="price">{{withdraw_fee}}%</text>,实际到账:<text class="price">￥{{true_money}}</text>
							</view>
							<view class='tip'>
								说明: <text class="num">每笔佣金的冻结期为{{userInfo.broken_day}}天，到期后可提现</text>
							</view>
						</view>
						<button formType="submit" class='bnt bg-color'>立即提现</button>
					</form>
				</view>
				<view :hidden='currentTab != 3'>
					<form @submit="subCash">
						<view class='list lei'>
							<view class="itemCon">
								<view class='item acea-row row-between-wrapper'>
									<view class='name'>姓名</view>
									<view class='input'><input v-model="alipay_name" placeholder='请输入姓名' placeholder-class='placeholder'
											name="name" onKeypress="javascript:if(event.keyCode == 32)event.returnValue = false;"></input></view>
								</view>
								<view class='item acea-row row-between-wrapper'>
									<view class='name'>账号</view>
									<view class='input'><input v-model="alipay_account" placeholder='请输入您的支付宝账号' placeholder-class='placeholder'
											name="alipay_code" onKeypress="javascript:if(event.keyCode == 32)event.returnValue = false;"></input></view>
								</view>
								<view class='item acea-row row-between-wrapper'>
									<view class='name'>提现</view>
									<view class='input acea-row row-between-wrapper'>
										<input @input='inputNum' :value='cashVal' :maxlength="moneyMaxLeng" :placeholder='"最低提现金额：¥"+minPrice' placeholder-class='placeholder'
											name="money" type='digit'></input>
										<view class="all" @click="allCash">全部提现</view>
									</view>
								</view>
								<view class='item acea-row row-top row-between'>
									<view class='name'>收款码</view>
									<view class="input acea-row">
										<view class="picEwm" v-if="qrcodeUrlZ">
											<image :src="qrcodeUrlZ"></image>
											<text class='iconfont icon-ic_close fontcolor' @click='DelPicZ'></text>
										</view>
										<view class='pictrue acea-row row-center-wrapper row-column' @click='uploadpic("Z")'
											v-else>
											<text class='iconfont icon-ic_camera'></text>
											<view>上传图片</view>
										</view>
									</view>
								</view>
							</view>
							<view class='tip'>
								当前可提现金额: <text
									class="price">￥{{userInfo.commissionCount}}</text>,冻结佣金：￥{{userInfo.broken_commission}}
							</view>
							<view class='tip'>
								提现手续费: <text class="price">{{withdraw_fee}}%</text>,实际到账:<text class="price">￥{{true_money}}</text>
							</view>
							<view class='tip'>
								说明: <text class="num">每笔佣金的冻结期为{{userInfo.broken_day}}天，到期后可提现</text>
							</view>
						</view>
						<button formType="submit" class='bnt bg-color'>立即提现</button>
					</form>
				</view>
			</view>
		</view>
		<!-- #ifdef MP -->
		<authorize v-if="isShowAuth" @authColse="authColse" @onLoadFun="onLoadFun"></authorize>
		<!-- #endif -->
	</view>
</template>

<script>
	import {
		extractCash,
		extractBank,
		getUserInfo
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import colors from '@/mixins/color.js';
	import { openExtrctSubscribe } from '@/utils/SubscribeMessage.js';
	export default {
		components: {},
		mixins:[colors],
		data() {
			return {
				// #ifdef MP
				getHeight: this.$util.getWXStatusHeight(),
				// #endif
				navList: [],
				currentTab: '', //当前支付方式编号；
				currentIndex:0, //索引值；
				index: 0,
				array: [], //提现银行
				minPrice: 0.00, //最低提现金额
				userInfo: [],
				isClone: false,
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				qrcodeUrlW: "",
				qrcodeUrlZ: "",
				prevent: true, //避免重复提交成功多次
				moneyMaxLeng: 8,
				withdraw_fee: '0',
				true_money: 0,
				extract_wechat_type:0,
				cashVal: '',
				cardholder: '',
				bank_code: '',
				wechat_account: '',
				alipay_name: '',
				alipay_account: '',
				platform: ''
			};
		},
		computed: {
			...mapGetters(['isLogin']),
			disabled(){
				return true
			}
		},
		onLoad() {
			if (this.isLogin) {
				const app = getApp();
				if (app.globalData.isUploading) return;
				this.navList = [];
				this.currentIndex = 0;
				this.prevent = true;
				this.cashVal = 0;
				this.$nextTick(()=>{
					this.cashVal = '';
				})
				this.getUserInfo();
				this.getUserExtractBank();
			} else {
				toLogin()
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			// #ifdef H5
			if(this.$wechat.isWeixin()){
				this.platform = 'wechat'
			}
			// #endif
			// #ifdef APP-PLUS
			this.platform = 'app'
			// #endif
			// #ifdef MP-WEIXIN
			this.platform = 'routine'
			// #endif
		},
		methods: {
			goarrow(){
				uni.navigateBack()
			},
			allCash(){
				this.cashVal = '';
				this.$nextTick(()=>{
					this.cashVal = this.userInfo.commissionCount;
					this.inputNum();
				})
			},
			inputNum: function(e) {
				let val = e?e.detail.value:this.cashVal;
				let dot = val.indexOf('.');
				if(dot>-1){
					this.moneyMaxLeng = dot+3;
				}else{
					this.moneyMaxLeng = 8
				}
				this.true_money = Math.floor((this.$util.$h.Mul(val,this.$util.$h.Div(this.$util.$h.Sub(100,this.withdraw_fee),100)))*100)/100 || 0;
			},
			/**
			 * 上传文件
			 *
			 */
			uploadpic: function(type) {
				let that = this;
				that.$util.uploadImageOne('upload/image', function(res) {
					if (type === 'W') {
						that.qrcodeUrlW = res.data.url;
					} else {
						that.qrcodeUrlZ = res.data.url;
					}
				});
			},
			/**
			 * 删除图片
			 *
			 */
			DelPicW: function() {
				this.qrcodeUrlW = "";
			},
			DelPicZ: function() {
				this.qrcodeUrlZ = "";
			},
			onLoadFun: function() {
				this.getUserInfo();
				this.getUserExtractBank();
				this.isShowAuth = false;
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			getUserExtractBank: function() {
				let that = this;
				extractBank().then(res => {
					let array = res.data.extractBank;
					array.unshift("请选择银行");
					that.$set(that, 'array', array);
					that.minPrice = res.data.minPrice;
					that.withdraw_fee = res.data.withdraw_fee;
					that.extract_wechat_type = res.data.extract_wechat_type;
					that.cardholder = res.data.cardholder;
					that.bank_code = res.data.bank_code;
					that.wechat_account = res.data.wechat_account;
					that.alipay_name = res.data.alipay_name;
					that.alipay_account = res.data.alipay_account;
				});
			},
			/**
			 * 获取个人用户信息
			 */
			getUserInfo: function() {
				let that = this;
				getUserInfo().then(res => {
					that.userInfo = res.data;
					if(res.data.user_extract_bank_status){
						this.navList.push(
							  {
								'name': '银行卡',
								'id': 0
							  }
						)
					}
					if(res.data.user_extract_balance_status){
						this.navList.push(
							{
								'name': '余额',
								'id': 1
							}
						)
					}
					if(res.data.user_extract_wechat_status){
						this.navList.push(
							{
								'name': '微信',
								'id': 2
							}
						)
					}
					if(res.data.user_extract_alipay_status){
						this.navList.push(
							{
								'name': '支付宝',
								'id': 3
							}
						)
					}
					this.currentTab = this.navList[0].id;
				})
			},
			swichNav: function(current,index) {
				this.currentTab = current;
				this.currentIndex = index;
			},
			bindPickerChange: function(e) {
				this.index = e.detail.value;
			},
			subCash: function(e) {
				let that = this,
					value = e.detail.value;
				if (that.currentTab == 0) { //银行卡
					if (value.name.length == 0) return this.$util.Tips({
						title: '请填写持卡人姓名'
					});
					if (value.cardnum.length == 0) return this.$util.Tips({
						title: '请填写卡号'
					});
					if (that.index == 0) return this.$util.Tips({
						title: "请选择银行"
					});
					value.extract_type = 'bank';
					value.bankname = that.array[that.index];
				} else if(that.currentTab == 1){//余额
				    value.extract_type = 'balance';
				}else if (that.currentTab == 2) { //微信
					value.extract_type = 'weixin';
					if(that.extract_wechat_type == 0){
						if (value.weixin.length == 0) return this.$util.Tips({
							title: '请填写微信号'
						});
						if (that.qrcodeUrlW == '') return this.$util.Tips({
							title: '请上传图片'
						});
						value.qrcode_url = that.qrcodeUrlW;
					}
				} else if (that.currentTab == 3) { //支付宝
					value.extract_type = 'alipay';
					if (value.name.length == 0) return this.$util.Tips({
						title: '请填写姓名'
					});
					if (value.alipay_code.length == 0) return this.$util.Tips({
						title: '请填写账号'
					});
					if (that.qrcodeUrlZ == '') return this.$util.Tips({
						title: '请上传图片'
					});
					value.qrcode_url = that.qrcodeUrlZ;
				}
				if (value.money.length == 0) return this.$util.Tips({
					title: '请填写提现金额'
				});
				if (Number(value.money) < Number(that.minPrice)) return this.$util.Tips({
					title: '提现金额不能低于：¥' + that.minPrice
				});
				if (this.prevent) {
					this.prevent = false
				} else {
					return
				}
				that.$set(value,'channel_type',this.platform);
				// #ifdef MP-WEIXIN
				uni.showLoading({
					title: '正在加载'
				});
				openExtrctSubscribe().then((res) => {
					uni.hideLoading();
					extractCash(value).then(res => {
						if(that.currentTab == 1){
							return this.$util.Tips({
								title: res.msg,
								icon: 'success'
							}, {
								url: '/pages/users/user_money/index',
								tab: 2
							});
						}else{
							return this.$util.Tips({
								title: res.msg,
								icon: 'success'
							}, {
								url: '/pages/users/user_cash/status',
								tab: 2
							});
						}
					}).catch(err => {
						setTimeout(e => {
							this.prevent = true
						}, 1500)
						return this.$util.Tips({
							title: err
						});
					});
				}).catch(() => {
					uni.hideLoading();
				});
				// #endif
				// #ifdef H5 || APP-PLUS
				extractCash(value).then(res => {
					if(that.currentTab == 1){
						return this.$util.Tips({
							title: res.msg,
							icon: 'success'
						}, {
							url: '/pages/users/user_money/index',
							tab: 2
						});
					}else{
						return this.$util.Tips({
							title: res.msg,
							icon: 'success'
						}, {
							url: '/pages/users/user_cash/status',
							tab: 2
						});
					}
				}).catch(err => {
					setTimeout(e => {
						this.prevent = true
					}, 1500)
					return this.$util.Tips({
						title: err
					});
				});
				// #endif
			}
		}
	}
</script>

<style lang="scss">
	.cash-withdrawal{
		.accountTitle{
			background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%);
			position: fixed;
			left:0;
			top:0;
			width: 100%;
			z-index: 99;
			.sysTitle{
				width: 100%;
				position: relative;
				font-weight: 500;
				color: #fff;
				font-size: 30rpx;
				.iconfont{
					position: absolute;
					font-size: 36rpx;
					left:11rpx;
					width: 60rpx;
				}
			}
		}
		.header{
			width: 100%;
			height: 380rpx;
			background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%);
			font-size: 30rpx;
			font-weight: 400;
			color: #F5F5F5;
			text-align: center;
			padding-top: 88rpx;
			position: relative;

			&::after{
				position: absolute;
				content: ' ';
				width: 50%;
				height: 160rpx;
				background: linear-gradient(180deg, var(--view-theme) 0%, #F5F5F5 100%);
				left: 0;
			}

			&:before{
				position: absolute;
				content: ' ';
				width: 50%;
				height: 160rpx;
				background: linear-gradient(180deg, var(--view-gradient) 0%, #F5F5F5 100%);
				right: 0;
				bottom: -146rpx;
			}

			.headerCon{
				background-image: url('../static/cashBg.png');
				background-repeat: no-repeat;
				background-size: 100% 100%;
				width: 100%;
				height: 278rpx;
			}

			.money{
				font-size: 76rpx;
				font-weight: 600;
				color: #FFFFFF;
				font-family: 'Regular';
				margin-bottom: 16rpx;
			}
		}
	}

	.cash-withdrawal .wrapper{
		width: 710rpx;
		margin: -100rpx auto 0 auto;
		position: relative;
		z-index: 9;

		.nav{
			width: 100%;
			height: 110rpx;
			background-color: rgba(255, 255, 255, 0.9);
			border-radius: 24rpx 24rpx 0 0;
			position: relative;
			z-index: 9;

			&.on{
				height: unset;
				padding: 18rpx 0 8rpx 0;
				background-color: #fff;

				.item{
					padding-top: 0;
					padding-left: 30rpx;
					font-weight: 500;
					color: #333;
				}
			}

			.item{
				width: 33.33%;
				height: 84rpx;
				line-height: 84rpx;
				color: #666666;
				font-size: 26rpx;
				position: relative;
				z-index: 9;
				
				&.items{
					font-size: 28rpx;
					color: var(--view-theme);
					font-weight: 500 !important;
				}

				&.on{
					width: 50%;
					padding-left: 0;
					text-align: center;
				}

				&.ons{
					color: var(--view-theme);
					font-weight: 500;
					font-size: 28rpx;
				}

				&.on1,&.on2,&.on3{
					font-weight: 400;
					padding-left: 0;
					text-align: center;
				}
				&.on6,&.on7,&.on8,&.on9{
					width: 25%;
					font-weight: 400;
					padding-left: 0;
					text-align: center;
				}
			}
			.navBg{
				position: absolute;
				background-repeat: no-repeat;
				background-size: 100% 100%;
				width: 710rpx;
				height: 122rpx;
				left:0;
				bottom: 0;
				box-sizing: border-box;

				&.on1{
					background-image: url('../static/tixian01.png');
				}

				&.on2{
					background-image: url('../static/tixian02.png');
				}

				&.on3{
					background-image: url('../static/tixian03.png');
				}

				&.on4{
					background-image: url('../static/tixian04.png');
				}

				&.on5{
					background-image: url('../static/tixian05.png');
				}
				
				&.on6{
					background-image: url('../static/tixian06.png');
				}
				
				&.on7{
					background-image: url('../static/tixian07.png');
				}
				
				&.on8{
					background-image: url('../static/tixian08.png');
				}
				
				&.on9{
					background-image: url('../static/tixian09.png');
				}
			}
		}

		.bnt {
			font-size: 28rpx;
			color: #fff;
			width: 710rpx;
			height: 88rpx;
			text-align: center;
			border-radius: 50rpx;
			line-height: 88rpx;
			margin: 48rpx auto;
		}
	}

	.cash-withdrawal .wrapper .list {
		padding: 0 30rpx 48rpx 30rpx;
		background-color: #fff;
		border-radius: 0 0 24rpx 24rpx;

		.itemCon{
			border-bottom: 1px solid #EEEEEE;
			padding-bottom: 15rpx;
			margin-bottom: 42rpx;
		}
	}

	.cash-withdrawal .wrapper .list .item {
		font-size: 28rpx;
		color: #333;
		padding: 28rpx 0;
	}

	.cash-withdrawal .wrapper .list .item .name {
		width: 130rpx;
	}

	.cash-withdrawal .wrapper .list .item .input {
		width: 505rpx;

		input{
			font-size: 28rpx;
		}

		.iconfont{
			color: #ccc;
		}

		.icon-ic_camera{
			font-size: 38rpx;
			margin-bottom: 6rpx;
		}

		.all{
			font-size: 26rpx;
			color: var(--view-theme);
		}
	}

	.cash-withdrawal .wrapper .list .item .input .placeholder {
		color: #ccc;
	}

	.cash-withdrawal .wrapper .list .item .picEwm,
	.cash-withdrawal .wrapper .list .item .pictrue {
		width: 128rpx;
		height: 128rpx;
		border-radius: 14rpx;
		position: relative;
		margin-right: 23rpx;
		background: #F5F5F5;
	}

	.cash-withdrawal .wrapper .list .item .picEwm image {
		width: 100%;
		height: 100%;
		border-radius: 3rpx;
	}

	.cash-withdrawal .wrapper .list .item .picEwm .icon-ic_close {
		position: absolute;
		right: 0;
		top: 0;
		font-size: 24rpx;
		width: 32rpx;
		height: 32rpx;
		background: #999999;
		border-radius: 0 16rpx 0 16rpx;
		color: #fff;
		text-align: center;
		line-height: 32rpx;
	}

	.cash-withdrawal .wrapper .list .item .pictrue {
		font-size: 22rpx;
		color: #BBBBBB;
	}

	.cash-withdrawal .wrapper .list .item .pictrue .icon-icon25201 {
		font-size: 47rpx;
		color: #DDDDDD;
		margin-bottom: 3px;
	}

	.cash-withdrawal .wrapper .list .tip {
		font-size: 24rpx;
		color: #999;
		margin-top: 16rpx;

		.price{
			color: var(--view-theme);
			margin: 0 16rpx;
		}

		.num{
			margin-left: 16rpx;
		}
	}

	.cash-withdrawal .wrapper .list .tip2 {
		font-size: 26rpx;
		color: #999;
		text-align: center;
		margin: 44rpx 0 20rpx 0;
	}

	.cash-withdrawal .wrapper .list .value {
		height: 135rpx;
		line-height: 135rpx;
		border-bottom: 1rpx solid #eee;
		width: 690rpx;
		margin: 0 auto;
	}

	.cash-withdrawal .wrapper .list .value input {
		font-size: 80rpx;
		color: #282828;
		height: 135rpx;
		text-align: center;
	}

	.cash-withdrawal .wrapper .list .value .placeholder2 {
		color: #bbb;
	}
	.bg-gray{
		background-color: var(--view-theme);
		opacity: 0.4;
	}
</style>
