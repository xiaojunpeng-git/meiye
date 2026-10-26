<script>
	import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
	export default {
		props: {
			visible: {
				type: Boolean,
				default: false,
			},
			promotionId:{
				type: [String, Number],
				default: ''
			},
			brandList:{
				type:Array,
				default: () => []
			},
			promotionList:{
				type:Array,
				default: () => []
			},
			labelList:{
				type:Array,
				default: () => []
			},
		},
		data(){
			return {
				promotions_id: 0,
				brand_id: [],
				store_label_id: [],
			}
		},
		watch:{
			promotionId:{
				handler(val){
					this.promotions_id = val;
				},
				immediate:true
			},
		},
		computed: {
			fixedTop() {
				// #ifdef MP || APP-PLUS
				return uni.getSystemInfoSync().statusBarHeight
				// #endif
				// #ifdef H5
				return 20
				// #endif
			},
			listH(){
				let H = `calc(100% - (${this.fixedTop + 96}px + env(safe-area-inset-bottom)))`
				return{
					height: H
				}
			}
		},
		components: {
			baseDrawer
		},
		methods: {
			closeDrawer() {
				this.$emit('closeDrawer');
			},
			checkPromotion(item){
				if(this.promotions_id == item.id){
				    this.promotions_id = 0
				}else{
					this.promotions_id = item.id;
				}
			},
			checkLabel(item){
				if(this.store_label_id.includes(item.id)){
					this.store_label_id = this.store_label_id.filter(function (ele){return ele != item.id;});
				}else{
					this.store_label_id.push(item.id);
				}
			},
			checkBrand(item){
				if(this.brand_id.includes(item.id)){
					this.brand_id = this.brand_id.filter(function (ele){return ele != item.id;});
				}else{
					this.brand_id.push(item.id);
				}
			},
			confirmFilter(){
				let data = {
					promotions_id: this.promotions_id,
					brand_id: this.brand_id.join(','),
					store_label_id: this.store_label_id.join(',')
				};
				this.$emit('filterChange',data);
			},
			resetFilter(){
				this.promotions_id = 0;
				this.brand_id = [];
				this.store_label_id = [];
			}
		}
	}
</script>
<template>
	<view>
		<base-drawer mode="right" :visible="visible" background-color="transparent" mask maskClosable
			@close="closeDrawer">
			<view class="drawer_box bg--w111-fff px-32 h-full">
				<view :style="{height:fixedTop + 'px'}"></view>
				<view class="h-80 flex-center fs-34 fw-500 text--w111-333">筛选</view>
				<view class="list activity_box py-24" :style="[listH]">
					<scroll-view scroll-y="true" style="height: 100%">
						<view v-if="promotionList.length">
							<view class="fs-28 text--w111-333 fw-500">商品活动</view>
							<view class="grid-column-3 box_gap mt-24">
								<view class="h-56 rd-28rpx bg--w111-f5f5f5 flex-center fs-24 text--w111-333" 
								v-for="item in promotionList" :key="item.id"
								:class="{active: promotions_id==item.id}"
								@tap="checkPromotion(item)">
									<text class="inline-block w-full line1 px-12 text-center">{{item.desc}}</text>
								</view>
							</view>
						</view>
						<view v-if="brandList.length">
							<view class="fs-28 text--w111-333 fw-500 mt-24">品牌</view>
							<view class="grid-column-3 box_gap mt-24">
								<view class="h-56 rd-28rpx bg--w111-f5f5f5 flex-center fs-24 text--w111-333" 
								v-for="item in brandList" :key="item.id"
								:class="{active: brand_id.includes(item.id)}"
								@tap="checkBrand(item)">
									<text class="inline-block w-full line1 px-12 text-center">{{item.brand_name}}</text>
								</view>
							</view>
						</view>
						<view v-if="labelList.length">
						<view class="fs-28 text--w111-333 fw-500 mt-24">商品标签</view>
						<view class="grid-column-3 box_gap mt-24">
							<view class="h-56 rd-28rpx bg--w111-f5f5f5 flex-center fs-24 text--w111-333" 
							v-for="item in labelList" :key="item.id"
							:class="{active: store_label_id.includes(item.id)}"
							@tap="checkLabel(item)">
								<text class="inline-block w-full line1 px-12 text-center">{{item.label_name}}</text>
							</view>
						</view>
					    </view>
				    </scroll-view>
				</view>
				<view class="fixed-lb pb-safe bg--w111-fff w-full rd-b-40">
					<view class="px-32 flex-between-center h-112">
						<view class="w-296 h-72 rd-40rpx flex-center font-num con_border bg--w111-fff"
							@tap="resetFilter()">重置</view>
						<view class="w-296 h-72 rd-40rpx flex-center text--w111-fff bg-color" @tap="confirmFilter()">确定</view>
					</view>
				</view>
			</view>
		</base-drawer>
	</view>
</template>
<style>
	.list{
		overflow: auto;
	}
	.drawer_box {
		width: 668rpx;
		border-radius: 40rpx 0 0 40rpx; 
		overflow: auto;
	}
	.box_gap {
		grid-row-gap: 24rpx;
		grid-column-gap: 26rpx;
	}
	.con_border{
		border: 1rpx solid var(--view-theme);
	}
	.active{
		border: 1px solid var(--view-theme);
		color: var(--view-theme);
		background: var(--view-minorColorT);
	}
</style>