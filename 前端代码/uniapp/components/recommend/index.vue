<template>
	<!-- 热门推荐 -->
	<view class='recommend' :style="colorStyle">
		<view class="flex-center">
			<image class="zs" src="../../static/img/recommend_zs.png"></image>
			<text class="fs-32 fw-500 text--w111-333 lh-44rpx px-6">{{ title }}</text>
			<image class="zs" src="../../static/img/recommend_zs.png"></image>
		</view>
		<view class="relative mt-24">
			<view class="jinabian"></view>
			<waterfallsFlow ref="waterfallsFlow" :wfList="hostProduct" :goDetail="'goDetail'" @itemTap="goDetail"></waterfallsFlow>
		</view>
	</view>
</template>

<script>
import {mapGetters} from "vuex";
import { goShopDetail } from '@/libs/order.js'
import colors from "@/mixins/color";
import waterfallsFlow from "@/components/WaterfallsFlow/WaterfallsFlow.vue";
export default {
	props: {
		hostProduct: {
			type: Array,
			default: function() {
				return [];
			}
		},
		title: {
			type: String,
			default: '猜你喜欢'
		}
	},
	components: {
		waterfallsFlow,
	},
	mixins: [colors],
	computed: mapGetters(['uid']),
	methods: {
		goDetail(item){
			goShopDetail(item, this.uid).catch(res => {
				uni.navigateTo({
					url: `/pages/goods_details/index?id=${item.id}`
				});
			});
		}
	},
}
</script>

<style scoped lang="scss">
	.recommend{
		padding-top:40rpx;
	}
	.zs{
		width:42rpx;
		height:36rpx;
	}
</style>
