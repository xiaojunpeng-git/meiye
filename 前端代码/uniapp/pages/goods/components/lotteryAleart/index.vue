<template>
	<!-- 抽奖结果弹窗 -->
	<view class="aleart flex-col flex-center" v-if="aleartStatus" @touchmove.stop.prevent>
		<image :src="alData.image" class="w-180 h-180"></image>
		<view class="fs-34 font-red fw-500 lh-48rpx mt-24"
			:class="theme ? 'font-num' : 'font-red'"
			>{{alData.type > 1 ? '恭喜你获得' : alData.name}}</view>
		<view class="fs-26 text--w111-333 lh-36rpx mt-24">{{aleartData.msg}}</view>
		<view class="btn flex-center fs-28 text--w111-fff mt-32" @tap="posterImageClose"
			:class="theme ? 'bg-gradient1' : 'bg-red-g'"
			>{{alData.type > 1 ? '立即领取' : '我知道了'}}</view>
		<text class="close iconfont icon-ic_close1" @click="posterImageClose"></text>
		<slot name="bottom"></slot>
	</view>
</template>

<script>
	import {
		openReceivedSubscribe
	} from '@/utils/SubscribeMessage.js';
	export default {
		data() {
			return {
				aleartData: {}
			}
		},
		props: {
			aleartType: {
				type: Number,
				default:0
			},
			alData: {
				type: Object,
				default:()=>{}
			},
			aleartStatus: {
				type: Boolean,
				default: false
			},
			theme:{
				type: Boolean,
				default: false
			}
		},
		watch: {
			aleartType(type) {
				if (type === 1) {
					this.aleartData = {
						title: '暂无抽奖资格',
						msg: `1、您未关注公众号
2、您未获得VIP权限，获取VIP途径：
（1）购买过打通版的用户可在会员群联系官方客服开通
（2）官方小程序商城购买MOHE打通版、企业版后自动开通`,
						btn: '我知道了'
					}
				} else if (type === 2) {
					this.aleartData = {
						title: '抽奖结果',
						img: this.alData.image,
						msg: this.alData.prompt,
						btn: '好的',
						type: this.alData.type
					}
				}
			},
			aleartStatus(status) {
				if (!status) {
					this.aleartData = {}
				}
			}
		},
		methods: {
			//隐藏弹窗
			posterImageClose(type) {
				if(this.alData.type == 4){
					// #ifdef MP-WEIXIN
					uni.showLoading({
						title: '正在加载'
					});
					openReceivedSubscribe().then(res=>{
						uni.hideLoading();
						uni.navigateTo({
							url: '/pages/goods/lottery/grids/record'
						})
					}).catch(err=>{
						uni.hideLoading();
						uni.navigateTo({
							url: '/pages/goods/lottery/grids/record'
						})
					})
					// #endif
					// #ifdef H5 || APP-PLUS
					uni.navigateTo({
						url: '/pages/goods/lottery/grids/record'
					})
					// #endif
				}
				this.$emit("close", false)
			},
		}

	}
</script>

<style lang="scss" scoped>
	.aleart {
		width: 480rpx;
		height: 544rpx;
		position: fixed;
		left: 50%;
		top: 50%;
		transform: translate(-50%,-50%);
		z-index: 9999;
		background-color: #fff;
		border-radius: 48rpx;
		background-image:url('../../static/alert_modal_bg.png');
		background-size:100%;
		background-repeat: no-repeat;
	}
	.aleart .close{
		position: absolute;
		left:50%;
		bottom:-100rpx;
		transform: translateX(-50%);
		color: #fff;
		font-size:50rpx;

	}
	.font-red{
		color: #e93323;
	}
	.btn{
		width: 280rpx;
		height: 80rpx;
		border-radius: 50rpx;
		color: #fff;
	}
	.bg-red-g{
		background: linear-gradient(90deg, #FF7931 0%, #E93323 100%);
	}
</style>
