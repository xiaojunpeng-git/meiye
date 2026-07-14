<template>
	<view :style="colorStyle">
		<view v-if="integralList.length" class="month-list">
			<view class="month-item" v-for="(integralItem, index) in integralList" :key="index">
				<view class="month-top">{{ integralItem.time }}</view>
				<view class="month-bottom">
					<view class="item acea-row row-between" v-for="item in integralItem.list" :key="item.id">
						<view class="item-left">
							<view>{{ item.mark }}</view>
							<view class="time">{{ item.add_time }}</view>
						</view>
						<view class="item-right">
							<view class="brand-tag" v-if="item.is_frozen">冻结中</view>
							<view class="inline-block">{{ item.pm ? '+' : '-' }}{{ item.number }}</view>
						</view>
					</view>
				</view>
			</view>
		</view>
		<view class="px-20 mt-20" v-else>
			<emptyPage title='暂无积分记录~' src="/statics/images/noOrder.gif"></emptyPage>
		</view>
		<view class="loadingicon acea-row row-center-wrapper" v-if="integralList.length">{{ loadTitle }}</view>
	</view>
</template>

<script>
	import {
		getIntegralList
	} from '@/api/user.js';
	import emptyPage from '@/components/emptyPage.vue'
	import colors from '@/mixins/color';
	export default {
		data() {
			return {
				page: 1,
				limit: 10,
				integralList: [],
				list: [],
				times: [],
				loadend: false,
				loading: false,
				loadTitle: '加载更多',
			}
		},
		components:{ emptyPage },
		mixins: [colors],
		onLoad() {
			this.getIntegralList();
		},
		onReachBottom() {
			this.getIntegralList();
		},
		methods: {
			getIntegralList() {
				let that = this;
				if (that.loading) return;
				if (that.loadend) return;
				that.loading = true;
				that.loadTitle = '';
				getIntegralList({
					page: that.page,
					limit: that.limit
				}).then(function(res) {
					let data = res.data;
					let list = data.list;
					let times = data.times;
					let integralList = [];
					for (let i = 0; i < times.length; i++) {
						if (!that.times.includes(times[i])) {
							that.times.push(times[i]);
							that.integralList.push({
								time: times[i],
								list: [],
							});
						}
					}
					for (let i = 0; i < that.integralList.length; i++) {
						for (let j = 0; j < list.length; j++) {
							if (times[i] == list[j].time_key) {
								that.integralList[i].list.push(list[j]);
							}
						}
					}
					let loadend = list.length < that.limit;
					that.page = that.page + 1;
					that.loading = false;
					that.loadend = loadend;
					that.loadTitle = loadend ? '没有更多内容啦~' : "加载更多";
				}, function(res) {
					this.loading = false;
					that.loadTitle = '加载更多';
				});
			},
		},
	}
</script>

<style lang="scss" scoped>
	.month-list {
		padding: 0 20rpx;

		.month-top {
			padding: 32rpx 0 24rpx;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #999999;
		}

		.month-bottom {
			padding: 0 24rpx;
			border-radius: 24rpx;
			background: #FFFFFF;
		}

		.item {
			padding: 32rpx 0;

			+.item {
				border-top: 1rpx solid #EEEEEE;
			}
		}

		.item-left {
			flex: 1;
			font-size: 28rpx;
			line-height: 40rpx;
			color: #333333;
		}

		.time {
			margin-top: 12rpx;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #999999;
		}

		.item-right {
			font-family: Regular;
			font-size: 36rpx;
			line-height: 40rpx;
			color: #333333;
			min-width: 124rpx;
			text-align: right;
		}
	}

	.loadingicon {
		padding: 32rpx 0;
		font-size: 26rpx;
		line-height: 36rpx;
		color: #CCCCCC;
	}
</style>