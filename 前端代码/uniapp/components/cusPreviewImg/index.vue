<template>
	<!-- 属性规格放大图轮播 -->
	<view class="previewImg" v-if="showBox" @touchmove.stop.prevent>
		<view class="mask" @click="close">
			<swiper @change="changeSwiper" class="mask-swiper" :current="currentIndex" :circular="circular" :duration="duration">
				<swiper-item v-for="(src, i) in list" :key="i" class="flex flex-column justify-center align-center">
					<image class="mask-swiper-img" :src="src.image" mode="widthFix" />
					<view class="mask_sku">
					   <text class="sku_name">{{src.suk}}</text>
					   <text class="sku_price">
						   <text v-if="src.integral">{{src.integral}}<text class="fs-26">积分</text>+</text>
						   <text class="label" v-if="src.price">￥</text>
						   {{src.price}}
					   </text>
					</view>
				</swiper-item>
			</swiper>
		</view>
		<view class="pagebox" v-if="list.length>0">{{ Number(currentIndex) + 1 }} / {{ list.length }}</view>
	</view>
</template>

<script>
	export default {
		name: 'cus-previewImg',
		props: {
			list: {
				type: Array,
				required: true,
				default: () => {
					return [];
				}
			},
			circular: {
				type: Boolean,
				default: true
			},
			duration: {
				type: Number,
				default: 500
			}
		},
		data() {
			return {
				currentIndex: 0,
				showBox: false
			};
		},
		watch: {
			list(val) {
			}
		},
		methods: {
			// 左右切换
			changeSwiper(e) {
				this.currentIndex = e.target.current;
				this.$emit('changeSwitch',e.target.current)
			},
			open(current) {
				if (!current || !this.list.length) return;
				this.currentIndex = this.list.map((item)=>item.suk).indexOf(current);
				this.showBox = true;
			},
			close() {
				this.showBox = false;
			}
			// shareFriend(){
			// 	this.$emit('shareFriend')
			// }
		}
	}
</script>

<style lang="scss" scoped>
@mixin full {
	width: 100%;
	height: 100%;
}

.previewImg {
	position: fixed;
	top: 0;
	left: 0;
	z-index: 3000;
	@include full;
	.mask {
		display: flex;
		justify-content: center;
		align-items: center;
		background-color: #000;
		opacity: 1;
		z-index: 8;
		@include full;
		&-swiper {
			@include full;
			&-img {
				width: 100%;
			}
		}
	}
	.pagebox{
		position: absolute;
		width: 100%;
		top: 80rpx;
		z-index: 300;
		color: #fff;
		text-align: center;
	}
}
.mask_sku{
	color: #fff;
	max-width: 80%;
	z-index: 300;
	text-align: center;
	display: flex;
	flex-direction: column;
	align-items: center;
	margin-top: 34rpx;
	.sku_name{
		font-size: 22rpx;
		border: 1px solid #fff;
		padding: 10rpx 30rpx 10rpx;
		border-radius: 80rpx;
		box-sizing: border-box;
	}
	.sku_price{
		padding-top: 24rpx;
		font-size: 36rpx;
		font-family: 'Regular';
		.label{
			font-size: 20rpx;
		}
	}
}
.font12{
	font-size: 24rpx;
}
.share_btn{
	position: absolute;
	top:70rpx;
	right:50rpx;
	font-size: 40rpx;
	color:#fff;
	z-index: 300;
}
.flex-column{flex-direction: column;}
.justify-center {justify-content: center;}
.align-center {align-items: center;}
</style>
