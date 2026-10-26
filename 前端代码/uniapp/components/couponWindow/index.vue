<template>
	<view>
		<!-- 优惠券弹窗 -->
		<view class="coupon-window relative" :class="{ on: window}">
			<view class="box-1 cover relative" :style="[couponImage4]">
				<view class="box-2 bg--w111-fff relative px-32">
					<view class="box-ht cover abs-lt" :style="[couponImage1]"></view>
					<view class="flex-center mt-26 fs-36 lh-60rpx fw-500 relative z-20">
						恭喜获得<text class="red">{{couponList.length}}张</text>优惠券
					</view>
					<view class="box-item mt-16 flex-between-center relative z-20" :style="[couponImage2]"
						v-for="(item,index) in couponList" :key="index">
						<baseMoney
						:money="item.coupon_price"
						symbolSize="32"
						integerSize="52"
						decimalSize="32"
						color="#e93323"
						isCoupon
						v-if="item.coupon_type==1"></baseMoney>
						<view v-else class="font-color SemiBold fs-42 SemiBold">{{parseFloat(item.coupon_price)/10}}折</view>
						<view>
							<view class="fs-28 lh-40rpx red w-234 line1">{{item.coupon_title}}</view>
							<view class="fs-20 lh-28rpx text--w111-666 mt-8" v-if="item.coupon_time">领取后{{item.coupon_time}}天内可用</view>
							<view class="fs-20 lh-28rpx text--w111-666 mt-8" v-else>{{item.start_time ? item.start_time+'-' : ''}}{{item.end_time === 0 ? '不限时': item.end_time}}</view>
						</view>
					</view>
				</view>
				<view class="box-3 cover abs-lb" :style="[couponImage3]">
					<view class="btn-box flex-center fs-34 fw-500" @tap="goPage">立即领取</view>
				</view>
			</view>
			<text class="iconfont icon-ic_close1 fs-60 text--w111-fff" @tap="close"></text>
		</view>
		<view class='mask' catchtouchmove="true" :hidden="window==false"  @touchmove.stop.prevent></view>
	</view>
</template>

<script>
	import {HTTP_REQUEST_URL} from '@/config/app';
	export default {
		props: {
			window: {
				type: Boolean | String | Number,
				default: false,
			},
			couponList: {
				type: Array,
				default: function() {
					return []
				},
			},
			couponImage: {
				type: String,
				default: '',
			},
		},
		data() {
			return {
				imgHost:HTTP_REQUEST_URL
			};
		},
		computed:{
			couponImage1(){
				return{
					backgroundImage: `url('${this.imgHost}/statics/images/activity/new_coupon_bg1.png')`
				}
			},
			couponImage2(){
				return{
					backgroundImage: `url('${this.imgHost}/statics/images/activity/new_coupon_bg2.png')`
				}
			},
			couponImage3(){
				return{
					backgroundImage: `url('${this.imgHost}/statics/images/activity/new_coupon_bg3.png')`
				}
			},
			couponImage4(){
				return{
					backgroundImage: `url('${this.imgHost}/statics/images/activity/new_coupon_bg4.png')`
				}
			}
		},
		methods: {
			goPage(){
				this.$emit('onColse');
				uni.navigateTo({
					url: '/pages/activity/coupon/index'
				})
			},
			close:function(){
			  this.$emit('onColse');
			}
		}
	}
</script>


<style scoped lang="scss">
	.mask {
		z-index: 9999;
	}

	.coupon-window {
		width: 574rpx;
		height: 860rpx;
		position: fixed;
		top: 20%;
		z-index: 10000;
		left: 50%;
		margin-left: -286rpx;
		transform: translate3d(0, -200%, 0);
		transition: all .3s cubic-bezier(.25, .5, .5, .9);
		border-radius: 30rpx 30rpx 0 0;
		overflow-x: hidden;
	}
	.coupon-window.on {
		transform: translate3d(0, 0, 0);
	}
	.box-1{
		width: 100%;
		height: 462rpx;
		position: absolute;
		left: 0;
		bottom: 108rpx;
	}
	.box-2{
		width: 524rpx;
		max-height: 508rpx;
		position: absolute;
		left: 50%;
		transform: translateX(-50%);
		bottom: 188rpx;
		border-radius: 40rpx 40rpx 0 0;
		overflow-y: auto;
	}
	.box-3{
		width: 100%;
		height: 242rpx;
	}
	.box-ht{
		width: 524rpx;
		height: 136rpx;
	}
	.box-item{
		width: 100%;
		height: 140rpx;
		background-size: 100%;
		padding: 0 40rpx 0 20rpx;
	}
	.btn-box{
		width: 460rpx;
		height: 88rpx;
		background: linear-gradient(90deg, #FFD10C 0%, #FEEF4C 100%);
		border-radius: 44rpx;
		position: absolute;
		left: 50%;
		transform: translateX(-50%);
		bottom: 48rpx;
	}
	.cover{
		background-size: cover;
	}
	.icon-ic_close1{
		position: absolute;
		left: 50%;
		transform: translateX(-50%);
		bottom: 0;
	}
	.red{
		color: #e93323;
	}
	.SemiBold{
		font-family:'SemiBold';
	}
</style>
