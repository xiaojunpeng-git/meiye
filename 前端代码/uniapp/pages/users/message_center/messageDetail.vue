<template>
	<view class="msg">
		<view class="pt-32 pl-20 pr-20">
			<view class="item" v-for="(item,index) in messageList" :key="index">
				<view class="flex-x-center fs-22 lh-30rpx text--w111-999">{{ item.add_time }}</view>
				<view class="w-full bg--w111-fff rd-24rpx mt-24 p-32">
					<view class="fs-32 lh-44rpx">{{item.title}}</view>
					<view class="fs-28 lh-44rpx mt-24">{{item.content}}</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import { messageSystem } from '@/api/user.js';
	export default {
		data() {
			return {
				page: 1,
				limit: 20,
				loading: false,
				finished: false,
				messageList: []
			}
		},
		onLoad() {
			this.getMessageList()
		},
		onReachBottom() {
			this.getMessageList()
		},
		methods: {
			getMessageList() {
				if (this.loading || this.finished) return
				this.loading = true;
				uni.showLoading({
					title: '加载中'
				});
				messageSystem({
						page: this.page,
						limit: this.limit
					})
					.then(res => {
						let data = res.data.list;
						uni.hideLoading();
						this.loading = false;
						this.messageList = this.messageList.concat(data);
						this.finished = data.length < this.limit;
						this.page += 1;
					})
					.catch(err => {
						uni.showToast({
							title: err.msg,
							icon: 'none'
						})
					})
			},
		}
	}
</script>

<style scoped lang="scss">
.left-pic{
	background: linear-gradient(180deg, #FFC657 0%, #FE653B 100%);
}
.dot{
	width: 20rpx;
	height: 20rpx;
	border-radius: 50%;
	background-color: #e93323;
}
.item ~ .item{
	margin-top: 40rpx;
}
</style>