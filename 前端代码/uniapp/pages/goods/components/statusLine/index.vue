<template>
	<view class="bg--w111-fff rd-16rpx pt-32 pl-24 pr-24 pb-32 flex-between-center">
		<view v-for="(item,index) in list" :key="index" class="item">
			<view class="h-32 flex-x-center item relative" >
				<view v-if="index <= indexActive" class="iconfont fs-32 text--w111-999 icon-a-ic_CompleteSelect"></view>
				<view v-else class="w-32 h-32 borderRadius15 bg--w111-f5f5f5 acea-row row-center-wrapper">
					<view class="iconfont icon-ic_complete fs-22 text--w111-999"></view>
				</view>
				<text class="line"
					:style="{'width': lineWidth + 'rpx','right': lineRight}"
					:class="index < indexActive ? 'bg-color' : ''"
					></text>
			</view>
			<view class="fs-22 text--w111-999 lh-30rpx mt-16" :class="{active:index <= indexActive}">{{item}}</view>
		</view>
	</view>
</template>
<script>
	export default {
		props: {
			type: {
				type: Number,
				default: 0
			},
			applyType: {
				type: Number,
				default: 0
			}
		},
		data() {
			return {}
		},
		computed: {
			list() {
				// 步骤条全部选中
				if(this.type == -1){
					return ['提交申请', '用户已撤销', '退款完成']
				}else if(this.type == 3){
					return ['提交申请', '商家已拒绝', '退款失败']
				} else if(this.type == 6 && this.applyType == 4){
					return ['提交申请', '平台强制退款', '退款完成']
				}else if([0,6].includes(this.type) && this.applyType == 1){
					return ['提交申请', '商家审核', '退款完成']
				}else if([0,4,5,6].includes(this.type) && this.applyType == 2){
					return ['提交申请', '商家审核','商品寄回', '退款完成']
				}else if([0,4,5,6].includes(this.type) && this.applyType == 3){
					return ['提交申请', '商家审核','商家收货', '退款完成']
				}else {
					return ['提交申请', '商家审核', '退款完成']
				}
			},
			indexActive(){
				if(this.type == -1){
					return 2
				}else if(this.type == 3){
					return 2
				} else if(this.type == 6 && this.applyType == 4){
					return 2
				}else if(this.applyType == 1){
					if([0,1].includes(this.type)){
						return 0
					}else{
						return 2
					}
				}else if([2,3].includes(this.applyType)){
					if(this.type == 0){
						return 0
					}else if(this.type == 6){
						return 3
					}else{
						return 1
					}
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
<style lang="scss">
	.line{
		height:4rpx;
		background: #f5f5f5;
		position: absolute;
		top:16rpx;
	}
	.item:last-child .line{
		display: none;
	}
	.icon-a-ic_CompleteSelect{
		color: var(--view-theme);
	}
	.active{
		color: #333;
	}
</style>
