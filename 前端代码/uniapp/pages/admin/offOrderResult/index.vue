<template>
	<view class="container">
		<view class="panel">
			<view class="img-box">
				<image v-if="id" src="../static/result2.png" class="image"></image>
				<image v-else src="../static/result1.png" class="image"></image>
				<view v-if="id">订单已全部核销</view>
				<view v-else>暂无待核销订单</view>
			</view>
			<view class="btn-box">
				<navigator v-if="id" class="btn" :url="'/pages/admin/orderDetail/index?id='+id+'&storeNum='+storeNum" hover-class="none">订单详情</navigator>
				<navigator class="btn" :url="storeNum?'/pages/admin/work/index':'/pages/admin/work/store'" hover-class="none">返回工作台</navigator>
			</view>
		</view>
	</view>
</template>

<script>
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';

	export default {
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				id: 0,
				storeNum:1
			}
		},
		onLoad(option) {
			this.storeNum = parseInt(option.storeNum);
			this.id = option.id || 0;
		},
	}
</script>

<style lang="scss" scoped>
	.container {
		padding: 40rpx 20rpx;

		.panel {
			padding: 64rpx 105rpx 98rpx;
			border-radius: 24rpx;
			background: #FFFFFF;
		}

		.img-box {
			text-align: center;
			font-size: 36rpx;
			line-height: 50rpx;
			color: #333333;
		}

		.image {
			width: 173rpx;
			height: 163rpx;
			margin-bottom: 34rpx;
		}

		.btn-box {
			margin-top: 60rpx;
		}

		.btn {
			height: 88rpx;
			border: 1rpx solid #2A7EFB;
			border-radius: 44rpx;
			text-align: center;
			font-weight: 500;
			line-height: 86rpx;
			color: #2A7EFB;
			transform: rotateZ(360deg);

			+.btn {
				margin-top: 24rpx;
			}

			&:first-child {
				background: #2A7EFB;
				color: #FFFFFF;
			}
		}
	}
</style>