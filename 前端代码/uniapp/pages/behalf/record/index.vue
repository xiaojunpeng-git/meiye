<template>
	<view>
		<view class="px-20">
			<view class="w-full h-72 rd-36rpx bg--w111-fff flex-y-center mt-10 mb-32 px-32">
				<text class="iconfont icon-ic_search text--w111-999"></text>
				<input v-model="params.keyword" class="pl-18 flex-1 line1 fs-28" placeholder="请输入要查询的订单"
					placeholder-class="text--w111-999" @confirm="searchSubmit" />
			</view>
			<view class="order_card bg--w111-fff rd-24rpx pt-32 pb-32 pl-24 pr-24"
			v-for="(item, index) in orderList" :key="index" @click="goOrderDetails(item.order_id)">
				<view class="flex-between-center">
					<view class="flex-y-center">
						<text class="fs-28 lh-40rpx text--w111-333 pl-16">订单号：{{item.order_id}}</text>
					</view>
					<view class="fs-26">
						<view class="text-num">
							<text class="text--w111-999" v-if="item.status == 3">已完成</text>
							<text v-else>{{item._status._title}}</text>
							<text v-if="item.refund.length">{{item.is_all_refund?'，退款中':'，部分退款中'}}</text>
						</view>
				</view>
			</view>
			<view class="mt-26" v-if="item.cartInfo.length && item.cartInfo.length == 1">
				<view class="flex justify-between" v-for="(items, i) in item.cartInfo" :key="i">
					<view class="flex">
						<easy-loadimage
						:image-src="items.productInfo.image"
						width="136rpx"
						height="136rpx"
						borderRadius="16rpx"></easy-loadimage>
						<view class="ml-20">
							<view class="w-346 fs-28 text--w111-333 lh-40rpx line2"> {{items.productInfo.store_name }}
							</view>
							<view class="w-346 fs-24 text--w111-999 lh-34rpx line1 mt-12">{{items.productInfo.attrInfo.suk}}</view>
						</view>
					</view>
					<view>
						<baseMoney v-if="items.productInfo.attrInfo" :money="items.productInfo.attrInfo.price"
							symbolSize="20" integerSize="32" decimalSize="20" color="#333333" weight></baseMoney>
						<baseMoney v-else :money="items.productInfo.price" symbolSize="20" integerSize="32"
							decimalSize="20" incolor="#333333" weight></baseMoney>
						<view class="fs-24 text--w111-999 lh-34rpx text-right">共{{item.total_num}}件</view>
					</view>
				</view>
			</view>
			<view class="mt-26 relative" v-if="item.cartInfo.length && item.cartInfo.length > 1">
				<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-560" show-scrollbar="false">
					<view class="inline-block mr-16" v-for="(items,i) in item.cartInfo" :key="i">
						<easy-loadimage
						:image-src="items.productInfo.image"
						width="136rpx"
						height="136rpx"
						borderRadius="16rpx"></easy-loadimage>
					</view>
				</scroll-view>
				<view class="abs-rt h-136 flex-col flex-center items-end">
					<baseMoney :money="item.pay_price" symbolSize="20" integerSize="32" decimalSize="20"
						color="#333333" weight></baseMoney>
					<view class="fs-24 text--w111-999 lh-34rpx text-right">共{{item.total_num}}件</view>
				</view>
			</view>
		</view>
		<block v-if="orderList.length == 0">
			<emptyPage title="暂无订单信息～" src="/statics/images/empty-box.png"></emptyPage>
		</block>
	</view>
	</view>
</template>

<script>
	import { adminOrderRecordList } from "@/api/admin.js";
	import emptyPage from '@/components/emptyPage.vue';
	export default {
		data() {
			return {
				params:{
					page:1,
					limit:10,
					keyword:'',
				},
				orderList:[],
				status: false
			}
		},
		components: {
			emptyPage,
		},
		onLoad() {
			this.getList();
		},
		onPageScroll(object) {
			uni.$emit('scroll');
		},
		methods: {
			goOrderDetails(id){
				uni.navigateTo({
					url:'/pages/admin/orderDetail/index?id=' + id
				})
			},
			searchSubmit(){
				this.params.page = 1;
				this.status = false;
				this.orderList = [];
				this.getList();
			},
			getList(){
				let that = this;
				if (that.status) return;
				adminOrderRecordList(this.params).then(res => {
					let len = res.data.length;
					this.orderList = this.orderList.concat(res.data)
					that.params.page++;
					that.status = this.params.limit > len;
					that.params.page = that.params.page;
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					});
				})
			},
			
		},
		onReachBottom(e) {
			this.getList()
		}
	}
</script>

<style lang="scss">
.border_con {
	border: 1px solid $primary-admin;
}
.text-num{
	color: #FF7E00;
}
.abs-rt {
	position: absolute;
	top: 0;
	right: 0;
}
.order_card ~ .order_card{
	margin-top:20rpx;

}
</style>
