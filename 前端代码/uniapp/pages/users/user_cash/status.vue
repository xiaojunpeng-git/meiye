<template>
	<view :style="colorStyle">
		<view class="px-20 mt-40">
			<view class="bg--w111-fff rd-24rpx flex-col flex-center box">
				<text class="iconfont icon-a-ic_CompleteSelect"></text>
				<view class="fs-32 lh-44rpx mt-64">提现申请已提交，等待人工审核</view>
				<view class="fs-26 lh-36rpx text--w111-999 mt-8">{{new Date().getTime() | dateFormat}}</view>
				<view class="w-498 h-88 rd-44rpx flex-center fs-28 text--w111-fff bg-color mt-30" @tap="goPage">好的</view>
			</view>
		</view>
	</view>
</template>

<script>
	import colors from '@/mixins/color.js';
	import dayjs from '@/plugin/dayjs/dayjs.min.js';
	export default {
		name: 'status',
		filters: {
			dateFormat(value) {
				return dayjs(value).format('YYYY/MM/DD hh:mm:ss');
			}
		},
		mixins:[colors],
		methods:{
			goPage(){
				uni.switchTab({
					url: '/pages/user/index'
				})
			}
		}
	}
</script>

<style scoped>
	.box{
		padding: 88rpx 0 64rpx;
	}
	.icon-a-ic_CompleteSelect{
		font-size: 112rpx;
		color: #00B42A;
	}
</style>
