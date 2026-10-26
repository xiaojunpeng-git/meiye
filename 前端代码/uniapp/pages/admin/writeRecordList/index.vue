<template>
	<view class="px-20 mt-24">
		<view class="mb-8" v-for="panel in records">
			<view class="h-84 fs-24 flex-y-center text--w111-666">{{panel.time}}</view>
			<view class="pt-32 pb-32 pr-24 pl-24 rd-24rpx bg--w111-fff">
				<view class="flex-between-center fs-28">
					<navigator :open-type="panel.list[0].service_type ? 'switchTab' : 'navigate'" :url="panel.list[0].service_type ? '/pages/index/index' : `/pages/store/home/index?id=${panel.list[0].relation_id}`" hover-class="none" class="flex-y-center max-w-436">
						<text class="line1">{{panel.list[0].storeInfo.name}}</text>
						<text class="iconfont icon-ic_rightarrow ml-8 fs-24 text--w111-666"></text>
					</navigator>
					<view class="">{{panel.list[0].storeInfo.staff}}</view>
				</view>
				<view class="flex mt-24" v-for="item in panel.list" :key="item.id">
					<image :src="item.cartInfo.productInfo.attrInfo?item.cartInfo.productInfo.attrInfo.image:item.cartInfo.productInfo.image" class="w-136 h-136 rd-16rpx"></image>
					<view class="flex-1 flex-col flex-between-center pl-20">
						<view class="w-full fs-28 lh-40rpx">
							<view class="flex-1 line2">{{ item.cartInfo.productInfo.store_name }}</view>
						</view>
						<view class="w-full flex-between-center">
							<BaseMoney :money="item.cartInfo.productInfo.price" symbolSize="20" integerSize="32" decimalSize="20"></BaseMoney>
							<view class="fs-24 text--w111-999">核销{{ item.writeoff_num }}{{item.cartInfo.productInfo.unit_name || '件'}}</view>
						</view>
					</view>
				</view>
			</view>
		</view>
		<emptyPage v-if="!records.length && page" title="暂无核销记录～" src="/statics/images/noOrder.gif"></emptyPage>
	</view>
</template>

<script>
	import {
		orderWirteOffRecords,
		storeWirteOffRecords
	} from '@/api/admin'
	import emptyPage from '@/components/emptyPage.vue'
	export default {
		components: {
			emptyPage
		},
		data() {
			return {
				id: '',
				page: 1,
				limit: 10,
				list: [],
				records: [],
				loadend: false,
				loading: false,
				storeNum: 1
			}
		},
		onLoad(option) {
			this.storeNum = parseInt(option.storeNum);
			this.id = option.id;
			this.orderWirteOffRecords();
		},
		methods: {
			orderWirteOffRecords() {
				if (this.loadend || this.loading) {
					return;
				}
				this.loading = true;
				let funApi = '';
				if (this.storeNum) {
					funApi = orderWirteOffRecords;
				} else {
					funApi = storeWirteOffRecords;
				}
				funApi(this.id, {
					product_type: 0,
					page: this.page,
					limit: this.limit,
				}).then(res => {
					const list = res.data.list;
					let records = [];
					this.list = this.list.concat(list);
					for (let i = 0; i < this.list.length; i++) {
						records.push({
							time: this.list[i].time,
							list: [this.list[i]],
						});
						for (let j = i + 1; j < this.list.length; j++) {
							if (this.list[i].time == this.list[j].time) {
								records[records.length - 1].list.push(this.list[j]);
								this.list.splice(j, 1);
								j--;
							}
						}
					}
					this.records = records;
					this.loadend = list.length < this.limit;
					this.page = this.page + 1;
					this.loading = false;
				}).catch(err => {
					this.loading = false;
				});
			},
		},
	}
</script>

<style lang="scss" scoped>
	.b-border {
		border-bottom: 1rpx solid #EEEEEE;
	}
</style>