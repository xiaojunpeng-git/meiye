<template>
	<view class="px-20 mt-24">
		<view class="bg--w111-fff rd-24rpx acea-row row-between-wrapper h-104 px-24 fs-28 text--w111-333">
			<view>订单号：{{info.order_id}}</view>
			<view @click="goRecord(info.id)" class="text--w111-999 acea-row row-middle">核销记录<text class="iconfont icon-ic_rightarrow text--w111-333 fs-24 ml-8"></text></view>
		</view>
		<view class="bg--w111-fff rd-24rpx mt-24 pl-24 pr-24" v-for="(item,index) in info.cart_info" :key="index">
			<view class="acea-row row-between-wrapper pt-32 pb-26rpx border-b-EEEEEE">
				<view class="w-136 h-136 mr-20 rd-16rpx">
					<image :src="item.cart_info.productInfo.attrInfo.image" class="w-full h-full rd-16rpx"></image>
				</view>
				<view class="flex-1">
					<view class="acea-row row-between-wrapper">
						<view class="line1 w-332">{{ item.cart_info.productInfo.store_name }}</view>
						<view class="text-w111-2A7EFB">
							<text v-if="item.writeoffed_num == 0">未核销</text>
							<text v-else-if="item.writeoffed_num < item.write_times">部分核销</text>
							<text v-else-if="item.writeoffed_num == item.write_times">已核销</text>
						</view>
					</view>
					<view class="fs-24 text--w111-999 mt-12">{{ item.cart_info.productInfo.attrInfo.suk }}</view>
					<view class="acea-row row-between-wrapper mt-16">
						<baseMoney :money="item.cart_info.productInfo.attrInfo.price" symbolSize="20" integerSize="32" decimalSize="20"
							color='#333' weight></baseMoney>
						<view class="acea-row row-center-wrapper">
							<text class="fs-24 text--w111-666">本次核销：</text>
							<view class="acea-row row-middle">
								<text class="iconfont icon-ic_Reduce fs-26 text--w111-333 mr-4" :class="item.input_num<=1?'text--w111-ccc':''" @click.stop="addCart(0,item)"></text>
								<input type="number" maxlength="3" class="w-72 h-36 rd-4rpx bg--w111-f5f5f5 acea-row row-center-wrapper text-center fs-24 text--w111-333 mx-6" @input="setValue($event,item)" @blur="blurValue($event,item)" v-model="item.input_num" />
								<text class="iconfont icon-ic_increase fs-26 text--w111-333 ml-4" :class="item.input_num>=item.surplus_num?'text--w111-ccc':''" @click.stop="addCart(1,item)"></text>
							</view>
						</view>
					</view>
				</view>
			</view>
			<view class="acea-row row-between-wrapper h-94 fs-24 text--w111-666">
				<view>已核销：<text>{{item.writeoffed_num}}</text>/{{item.write_times}}</view>
				<view>剩余数量：{{item.surplus_num}}</view>
				<navigator :url="`/pages/admin/reservation_list/index?oid=${info.id}`" hover-class="none">待处理：{{item.unservice_num}}<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999 ml-8"></text></navigator>
			</view>
		</view>
		<view class="heights"></view>
		<view class="footer acea-row row-between-wrapper">
			<navigator url='/pages/admin/work/store' hover-class="none" class="border-2A7EFB-2 w-346 h-72 acea-row row-center-wrapper fs-26 rd-30 text-w111-2A7EFB">返回工作台</navigator>
			<view @click="writeBnt" class="border-2A7EFB-2 w-346 h-72 acea-row row-center-wrapper fs-26 rd-30 bg-w111-2A7EFB">确认核销</view>
		</view>
	</view>
</template>

<script>
	import { orderCartInfo,orderWriteoff } from '@/api/admin'
	import { Debounce } from '@/utils/validate.js'
	export default {
		data() {
			return {
				id:0, //订单id
				info:{}
			}
		},
		onLoad(option){
			this.id = option.id;
			this.orderInfo();
		},
		methods:{
			writeBnt(){
				let cartIds = [];
				this.info.cart_info.forEach(item=>{
					cartIds.push({
						cart_id: item.cart_id,
						cart_num: item.input_num
					});
				})
				let data = {
					auth:4,
					oid:this.id,
					cart_ids:cartIds
				}
				orderWriteoff(data).then(res=>{
					this.$util.Tips({
						title: res.msg
					}, () => {
						this.orderInfo();
					});
				}).catch(err=>{
					this.$util.Tips({
						title: err
					});
				})
			},
			orderInfo(){
				let data = {
					oid:this.id,
					auth:4
				}
				orderCartInfo(data).then(res=>{
					res.data.cart_info.forEach(item=>{
						item.input_num = item.surplus_num ? 1 : 0;
					})
					this.info = res.data;
				}).catch(err=>{
					this.$util.Tips({
						title: err
					});
				})
			},
			addCart(type,item){
				if(type == 1){
					if(item.input_num>=item.surplus_num) return this.$util.Tips({
						title: '已到达你核销的最大数'
					});
					item.input_num ++
				}else{
					if(item.input_num <= 1) return
					item.input_num --
				}
			},
			setValue: Debounce(function(e,item){
				let num = e.detail.value;
				if (item.surplus_num > 0 && num > item.surplus_num) {
					item.input_num = item.surplus_num;
					return this.$util.Tips({
						title: '已到达你核销的最大数'
					});
				}
			}),
			blurValue(e,item){
				let num = e.detail.value;
				if(!num){
					item.input_num = 1;
				}
			},
			goRecord(id) {
				uni.navigateTo({
					url: '/pages/admin/writeRecordList/index?id=' + id+'&storeNum=0'
				})
			},
		}
	}
</script>

<style lang="scss" scoped>
	.footer{
		width: 710rpx;
		color: #fff;
		position: fixed;
		left: 50%;
		margin-left: -355rpx;
		bottom: 20rpx;
		bottom: calc(20rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		bottom: calc(20rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		z-index: 20;
	}
	.heights{
		height: calc(130rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(130rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
	}
</style>