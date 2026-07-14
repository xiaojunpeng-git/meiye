<script>
import { HTTP_REQUEST_URL } from '@/config/app';
export default {
	props: {
		staticData: {
			type: Object,
			default: () => {}
		}
	},
	inject: ['goMenuPage'],
	computed: {
		moneyBg() {
			return{
				backgroundImage: `url(${HTTP_REQUEST_URL}/statics/images/users/group1_pic.png)`
			}
		},
		numBg() {
			return{
				backgroundImage: `url(${HTTP_REQUEST_URL}/statics/images/users/group2_pic.png)`
			}
		},
		orderBg() {
			return{
				backgroundImage: `url(${HTTP_REQUEST_URL}/statics/images/users/group3_pic.png)`
			}
		}
	},
	methods:{
		goPage(){
			this.$store.commit('SET_STORE', 1);
			uni.navigateTo({
				url: '/pages/admin/orderList/index?types=1'
			})
		}
	}
};
</script>


<template>
	<view class="pt-32 pr-24 pb-32 pl-24 bg--w111-fff rd-16rpx mt-20 ml-20 mr-20">
		<view class="fs-30 fw-500 lh-42rpx text--w111-333">运营统计</view>
		<view class="flex-between-center mt-14">
			<view class="w-208 rd-16rpx pt-24 pl-24 pb-20 flex-col bg_1" :style="[moneyBg]" @click="goMenuPage('/pages/admin/order/index')">
				<text class="fs-24 text--w111-999 lh-34rpx">
					支付金额
					<text class="iconfont icon-ic_rightarrow fs-24"></text>
				</text>
				<text class="fs-32 text--w111-333 fw-bold lh-52rpx num-fa-semi">{{ staticData.price }}</text>
			</view>
			<view class="w-208 rd-16rpx pt-24 pl-24 pb-20 flex-col bg_2" :style="[numBg]" @click="goMenuPage('/pages/admin/order/index')">
				<text class="fs-24 text--w111-999 lh-34rpx">
					支付订单数
					<text class="iconfont icon-ic_rightarrow fs-24"></text>
				</text>
				<text class="fs-32 text--w111-333 fw-bold lh-52rpx num-fa-semi">{{ staticData.num }}</text>
			</view>
			<view class="w-208 rd-16rpx pt-24 pl-24 pb-20 flex-col bg_3" :style="[orderBg]" @click="goPage">
				<text class="fs-24 text--w111-999 lh-34rpx">
					待发货订单
					<text class="iconfont icon-ic_rightarrow fs-24"></text>
				</text>
				<text class="fs-32 text--w111-333 fw-bold lh-52rpx num-fa-semi">{{ staticData.consignment }}</text>
			</view>
		</view>
	</view>
</template>

<style lang="scss" scoped>
.bg_1 {
	background-size: 100%;
}

.bg_2 {
	background-size: 100%;
}

.bg_3 {
	background-size: 100%;
}
</style>
