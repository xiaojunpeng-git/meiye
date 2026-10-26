<template>
	<view class="bg--w111-fff rd-24rpx pt-32 pl-50 pr-50 pb-32 mb-20 flex-between-center">
		<view v-for="(item,index) in list" :key="index" class="item">
			<view class="fs-26 text--w111-333 lh-30rpx" :class="{active:index <= indexActive}">{{item}}</view>
			<view class="h-32 flex-x-center relative mt-16" >
				<view v-if="index <= indexActive" class="w-28 h-28 borderRadius15 bg--w111-fff acea-row row-center-wrapper" :class="index == indexActive?'minorColorT':''">
					<view class="fs-24 font-num iconfont icon-ic_jindu2"></view>
				</view>
				<view v-else class="w-28 h-28 borderRadius15 bg--w111-fff acea-row row-center-wrapper">
					<view class="iconfont icon-ic_jindu2 fs-24 text-w111-ddd"></view>
				</view>
				<text class="line"
					:style="{'width': lineWidth + 'rpx','right': lineRight}"
					:class="index < indexActive ? 'bg-color' : ''"
					></text>
			</view>
		</view>
	</view>
</template>
<script>
	export default {
		props: {
			type: {
				type: Number,
				default: 0
			}
		},
		data() {
			return {
				list:['待预约','待服务','进行中','已完成']
			}
		},
		computed: {
			indexActive(){
				if(this.type == -1){
					return 0
				}else if(this.type == 0){
					return 1
				} else if(this.type == 1){
					return 2
				}else{
					return 3
				}
			},
			lineWidth(){
				if(this.list.length == 4){
					return 132
				}else{
					return 230
				}
			},
			lineRight(){
				return '-' + (this.lineWidth - 14) + 'rpx'
			}
		},
	}
</script>
<style lang="scss" scoped>
	.minorColorT{
		background-color: var(--view-minorColorT);
	}
	.line{
		height:2rpx;
		background: #ddd;
		position: absolute;
		top:14rpx;
	}
	.item:last-child .line{
		display: none;
	}
	.icon-a-ic_CompleteSelect{
		color: var(--view-theme);
	}
	.active{
		color: var(--view-theme);
	}
</style>