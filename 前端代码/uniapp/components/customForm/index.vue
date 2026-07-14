<template>
	<!-- 自定义组件 -->
	<view v-if="customForm && customForm.length && isShow && ((productType == 6 && reservationTimeId>0) || productType != 6)">
		<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-for="(j,jindex) in customForm" :key="jindex">
			<view v-if="productType == 6" class="cell flex justify-between fs-28 fw-600">{{customFormTitle}}{{jindex+1}}</view>
			<view class="cell flex justify-between" v-for="(item,index) in j" :key="index">
				<text class="fs-28">{{item.titleConfig.value}}</text>
				<view v-if="item.name == 'uploadPicture' && item.value.length < 5"  class="w-462 flex justify-end">
					<view class='pictrue mr-8' v-for="(items,indexs) in item.value" :key="indexs">
					  <image class="w-88 h-88 rd-8rpx" :src='items' mode="aspectFill" @click.stop='getCustomForm(jindex,index,indexs)'></image>
					</view>
				</view>
				<scroll-view scroll-x="true" scroll-with-animation
					class="white-nowrap vertical-middle w-462" show-scrollbar="false"
						v-else-if="item.name == 'uploadPicture' && item.value.length >= 5">
					<view class="inline-block mr-12" v-for="(items,indexs) in item.value" :key="indexs">
						<image class="w-88 h-88 rd-8rpx" :src="items" @click='getCustomForm(jindex,index,indexs)'></image>
					</view>
				</scroll-view>
				<view v-else-if="item.name == 'dateranges'" class="  fs-28">
				   <text v-if="item.value.length">{{item.value[0]+'/'+item.value[1]}}</text>
				</view>
				<text v-else class="fs-28">{{item.value}}</text>
			</view>
		</view>
	</view>
</template>

<script>
	export default {
		name: 'customForm',
		props: {
			customForm:{
				type: Array,
				default: () => []
			},
			productType:{
				type: Number,
				default: () => 0
			},
			reservationTimeId:{
				type: Number,
				default: () => 0
			},
			customFormTitle:{
				type: String,
				default: () => '预约人'
			}
		},
		data() {
			return {
				isShow:0
			};
		},
		watch: {
			customForm (value) {
				if(value && value.length){
					value.forEach((item)=>{
						if(item && item.length){
							item.forEach(j=>{
								if(j.value){
									return this.isShow = 1
								}
							})
						}
					})
				}
			}
		},
		created() {},
		mounted() {},
		methods: {
			getCustomForm: function(jindex,index,indexs) {
				uni.previewImage({
					urls: this.customForm[jindex][index].value,
					current: this.customForm[jindex][index].value[index]
				});
			},
		}
	};
</script>

<style lang="scss">
	.cell ~ .cell{
		margin-top: 40rpx;
	}
</style>
