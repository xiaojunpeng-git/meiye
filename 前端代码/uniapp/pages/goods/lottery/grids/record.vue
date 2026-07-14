<template>
	<view :style="colorStyle">
		<view class="px-20 mt-32">
			<view class="bg--w111-fff rd-24rpx p-24 flex-between-center item"
				v-for="item in lotteryList" :key="item.id">
				<easy-loadimage
				:image-src="item.prize.image"
				width="136rpx"
				height="136rpx"
				borderRadius="16rpx"></easy-loadimage>
				<view class="flex-1 pl-24 h-136 flex-between-center">
					<view class="w-328 h-136 flex-col justify-between">
						<view class="w-full h-80 line2">
							<text class="label" :class="'label' + item.prize.type">{{item.prize.type | typeName}}</text>
							<text class="name">{{item.prize.name}}</text>
						</view>
						<view class="fs-22 text--w111-999">兑换时间:{{item.receive_time || '--'}}</view>
					</view>
					<view class="w-120 h-56 rd-30rpx flex-center fs-24 bg-gradient text--w111-fff"
						@tap="goDetail(item)" v-if="item.type == 4">{{item.type ==4 && item.wechat_state == 'WAIT_USER_CONFIRM' ? '去领取' : '已领取'}}</view>
					<view class="w-120 h-56 rd-30rpx flex-center fs-24 bg-gradient text--w111-fff"
						@tap="goDetail(item)" v-else>{{item.is_receive == 0 && item.type == 6 ? '去领取' : '去查看'}}</view>
				</view>
			</view>
		</view>
		<view class="px-20 mt-20" v-if="lotteryList.length === 0 && !loading">
			<emptyPage title="暂无中奖记录～" src="/statics/images/noOrder.gif"></emptyPage>
		</view>
	</view>
</template>

<script>
	import {getLotteryList} from '@/api/lottery.js';
	import { postCartAdd } from '@/api/store.js';
	import emptyPage from '@/components/emptyPage.vue'
	import colors from '@/mixins/color.js';
	export default {
		components: {
			emptyPage
		},
		mixins:[colors],
		data() {
			return {
				loading: false,
				where: {
					page: 1,
					limit: 20,
				},
				lotteryList: [],
				loadTitle: ''
			}
		},
		onShow() {
			this.lotteryList = [];
			this.where.page = 1;
			this.loading = false;
			this.loadend = false;
			this.getLotteryList()
		},
		filters: {
			typeName(type) {
				if (type == 2) {
					return '积分'
				} else if (type == 3) {
					return '余额'
				} else if (type == 4) {
					return '红包'
				} else if (type == 5) {
					return '优惠券'
				} else if (type == 6) {
					return '商品'
				}
			}
		},
		onPageScroll(object) {
			uni.$emit('scroll');
		},
		methods: {
			getLotteryList() {
				if (this.loadend) return;
				if (this.loading) return;
				this.loading = true;
				this.loadTitle = '';
				getLotteryList(this.where).then(res => {
					let list = res.data;
					let lotteryList = this.$util.SplitArray(list, this.lotteryList);
					let loadend = list.length < this.where.limit;
					this.loadend = loadend;
					this.loading = false;
					this.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					this.$set(this, 'lotteryList', lotteryList);
					this.$set(this.where, 'page', this.where.page + 1);
				}).catch(err => {
					that.loading = false;
					that.loadTitle = '加载更多';
				});
			},
			goDetail(item){
				let type = item.type;
				if (type == 2) {
					uni.navigateTo({
						url: '/pages/users/user_integral/index'
					})
				} else if (type == 3) {
					uni.navigateTo({
						url: '/pages/users/user_money/index'
					})
				} else if (type == 4) {
					if(item.wechat_state == 'WAIT_USER_CONFIRM'){
						uni.navigateTo({
							url: '/pages/users/user_withdrawal/receiving?type=2&id=' + item.order_id
						})
					}
				} else if (type == 5) {
					uni.navigateTo({
						url: '/pages/users/user_coupon/index'
					})
				} else if (type == 6) {
					if(item.oid > 0){
						uni.navigateTo({
							url: `/pages/goods/order_details/index?order_id=${item.oid}`
						})
					}else{
						postCartAdd({
							cartNum: 1,
							new: 1,
							is_new: 1,
							productId: item.prize.product_id,
							uniqueId: item.prize.unique,
							luckRecordId: item.id,
						}).then(res => {
							uni.navigateTo({
								url: `/pages/goods/order_confirm/index?new=1&luckRecordId=${item.id}&cartId=${res.data.cartId}`
							});
						}).catch(err => {
							this.$util.Tips({
								title: `${err},请联系客服`
							});
						});
					}
				}
			}
		},
		onReachBottom() {
			this.getLotteryList();
		}
	}
</script>

<style lang="scss" scoped>
	.item ~ .item{
		margin-top: 20rpx;
	}
	.item{
		.name {
			width: 328rpx;
			height: 80rpx;
			line-height: 40rpx;
			color: #333;
			font-weight: 500;
			font-size: 28rpx;
		}
		.label {
			padding: 0 6rpx;
			font-size: 20rpx;
			margin-right: 16rpx;
			border-radius: 6rpx;
			vertical-align: middle;
		}
		.label2, .label3, .label5{
			border: 1rpx solid #FF7D00;
			color: #FF7D00;
		}
		.label4, .label6{
			border: 1rpx solid var(--view-theme);
			color: var(--view-theme);
		}
	}
</style>
