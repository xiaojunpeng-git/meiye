<template>
	<base-drawer mode="bottom" :visible="visible" background-color="transparent" mask maskClosable @close="closeDrawer">
		<view class="bg--w111-f5f5f5 rd-t-40rpx py-32">
			<view class="text-center fs-32 text--w111-333 fw-500">赠品</view>
			<scroll-view scroll-y="true" class="mt-48 px-20 scroll-content">
					<view class="text--w111-333 fs-28 lh-40rpx">本单可获得以下赠品</view>
					<view class="gift-card mt-24 bg--w111-fff rd-16rpx p-16 flex-y-center"
						v-for="(item,index) in giveCartInfo" :key="item.id" @tap="goPage(1,'/pages/goods_details/index?id=' + item.productInfo.id)">
						<image class="w-116 h-116 rd-16rpx" :src="item.productInfo.attrInfo.image" v-if="item.productInfo.attrInfo"></image>
						<image class="w-116 h-116 rd-16rpx" :src="item.productInfo.image" v-else></image>
						<view class="flex-1 flex-between-center pl-16">
							<view class="w-460">
								<view class="w-full line1 fs-28 lh-40rpx">{{item.productInfo.store_name}}</view>
								<view class="fs-22 text--w111-999 lh-30rpx mt-12">数量x{{item.cart_num}}</view>
							</view>
							<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999"></text>
						</view>
					</view>
					<view class="gift-card mt-24 bg--w111-fff rd-16rpx p-16 flex-y-center"
						v-for="(item,index) in giveData.give_coupon" :key="index" @tap="goPage(1,'/pages/users/user_coupon/index')">
						<view class="w-116 h-116 rd-16rpx flex-center bg--w111-f5f5f5">
							<text class="fs-48 gold iconfont icon-a-ic_discount1"></text>
						</view>
						<view class="flex-1 flex-between-center pl-16">
							<view class="w-460">
								<view class="w-full line1 fs-28 lh-40rpx">{{item.coupon_title}}</view>
								<view class="fs-22 text--w111-999 lh-30rpx mt-12">数量x1</view>
							</view>
							<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999"></text>
						</view>
					</view>
					<view class="gift-card mt-24 bg--w111-fff rd-16rpx p-16 flex-y-center"
						v-if="giveData.give_integral>0"
						 @tap="goPage(1,'/pages/users/user_integral/index')">
						<view class="w-116 h-116 rd-16rpx flex-center bg--w111-f5f5f5">
							<text class="fs-48 gold iconfont icon-ic_badge11"></text>
						</view>
						<view class="flex-1 flex-between-center pl-16">
							<view class="w-460">
								<view class="w-full line1 fs-28 lh-40rpx">{{giveData.give_integral}}积分</view>
								<view class="fs-22 text--w111-999 lh-30rpx mt-12">数量x1</view>
							</view>
							<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999"></text>
						</view>
					</view>
			</scroll-view>
			<view class="mx-20">
			  <view class="mt-52 h-72 flex-center rd-36px bg-w111-2A7EFB fs-26 text--w111-fff" @tap="closeDrawer">确定</view>
			</view>
		</view>
	</base-drawer>
</template>

<script>
import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
export default {
	props:{
		visible: {
			type: Boolean,
			default: false,
		},
		giveCartInfo:{
			type: Array,
			default: ()=>[]
		},
		giveData:{
			type: Object,
			default: ()=>{}
		},
	},
	components: {
		baseDrawer
	},
	methods:{
		closeDrawer() {
			this.$emit('closeDrawer');
		},
		goPage(type,url){
			uni.navigateTo({
				url
			})
		}
	}
}
</script>

<style>
.gold{
	color: #DCA658;
}
.scroll-content{
	box-sizing: border-box;
	height: 800rpx;
}
</style>
