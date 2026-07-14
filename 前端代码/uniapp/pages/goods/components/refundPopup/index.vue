<template>
	<view>
		<base-drawer mode="bottom" :visible="refundData.show" background-color="transparent" mask maskClosable @close="closeDrawer">
			<view class="w-full bg--w111-fff rd-t-40rpx py-32 relative">
				<view class="text-center fs-32 text--w111-333 fw-500">选择退款原因</view>
				<view class="close flex-center" @tap='closeDrawer'>
					<text class="iconfont icon-ic_close fs-24 text--w111-999"></text>
				</view>
				<view class="mt-48 px-24 scroll-content">
					<view class="list">
						<view class="cell flex-between-center" 
							v-for="(item,index) in refundData.RefundArray" :key='index' 
							@click="tapSelect(index)">
							<view class="fs-28">{{item}}</view>
							<text v-if="index == current" class="iconfont icon-a-ic_CompleteSelect fs-36 font-num"></text>
							<text v-else class="iconfont icon-ic_unselect fs-36 text--w111-ccc"></text>
						</view>
					</view>
					<view class="pb-safe">
						<view class="mt-52 w-full h-72 flex-center rd-36px bg-color fs-26 text--w111-fff" @tap="determine">确定</view>
					</view>
				</view>
			</view>
		</base-drawer>
	</view>
</template>

<script>
	import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
	export default {
		props: {
			refundData: {
				type: Object,
				default: function() {
					return {
						show: false,
						RefundArray: []
					};
				}
			},
		},
		components: {
			baseDrawer
		},
		data() {
			return {
				current:0
			};
		},
		methods: {
			closeDrawer: function() {
				this.$emit('changeClose');
			},
			tapSelect(index){
				this.current = index;
			},
			determine(){
				this.$emit('selectInfo',this.current);
			}
		}
	}
</script>

<style lang="scss" scoped>
	.scroll-content{
		max-height: 800rpx;
		overflow-y: auto;
	}
	.cell ~ .cell{
		margin-top: 64rpx;
	}
	.close{
		position: absolute;
		right: 32rpx;
		top: 36rpx;
		width: 36rpx;
		height: 36rpx;
		border-radius: 50%;
		background-color: #eee;
	}
</style>