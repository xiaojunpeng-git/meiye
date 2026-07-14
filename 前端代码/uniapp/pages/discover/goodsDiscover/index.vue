<template>
	<view :style="colorStyle">
		<!-- 商品详情 -->
		<view class="m-20 pro-box rd-20rpx bg--w111-fff flex-between-center">
			<image class="w-108 h-108 rd-16rpx" :src="storeInfo.image"></image>
			<view class="flex-1 pl-28 min-w-0">
				<view class="line1 fs-30 fw-500">{{storeInfo.store_name}}</view>
				<view class="pt-30 fs-24">
					<text class="font-num fs-30 fw-500 pr-8">{{count}}</text>条种草秀
				</view>
			</view>
			<view class="flex-y-center h-58 pr-22 pl-22 border-DDDDDD rd-28rpx fw-500 fs-24 text--w111-666" @tap="bubbleTo(`/pages/discover/discoverCreate/index?product_id=${params.product_id}`)">
				<text class="iconfont icon-ic_edit fs-26 mr-8"></text>去发布
			</view>
		</view>
		<view class="px-20" v-if="contentList.length">
			<waterfallsFlow :wfList="contentList" @onFlowLike="flowLike"></waterfallsFlow>
		</view>
		<view class="px-20 mt-20" v-else>
			<emptyPage title="暂无内容～"></emptyPage>
		</view>
		<!-- <home></home> -->
	</view>
</template>

<script>
	import colors from "@/mixins/color";
	import {
		communityElegantListApi
	} from "@/api/community.js";
	import {
		getProductDetail
	} from "@/api/store.js"
	import waterfallsFlow from "../components/discoverWaterfall/WaterfallsFlow.vue";
	import emptyPage from '@/components/emptyPage.vue';
	import {
		mapGetters
	} from 'vuex';
	import {
		toLogin
	} from '@/libs/login.js';
	export default {
		data() {
			return {
				contentList: [],
				loading: false,
				params: {
					page: 1,
					limit: 20,
					product_id: ''
				},
				storeInfo: {},
				count: 0
			};
		},
		components: {
			waterfallsFlow,
			emptyPage
		},
		mixins: [colors],
		computed: mapGetters(['isLogin']),
		provide() {
			return {
				flowLike: this.flowLike
			}
		},
		onPageScroll(object) {
			uni.$emit('scroll');
		},
		onLoad(options) {
			this.params.product_id = options.id || '';
			this.getProductInfo(options.id);
			this.getList();
		},
		methods: {
			getProductInfo(id) {
				getProductDetail(id, {
					promotions_type: 0
				}).then(res => {
					this.storeInfo = res.data.storeInfo;
				})
			},
			getList() {
				if (this.loading) return;
				this.loading = true;
				communityElegantListApi(this.params).then(res => {
					let list = res.data.list;
					this.count = res.data.count;
					let loading = list.length < this.params.limit;
					this.contentList = this.contentList.concat(list);
					this.params.page++;
					this.loading = loading;
				})
			},
			flowLike(data) {
				let index = this.contentList.findIndex(item => data.id == item.id);
				this.contentList[index].is_like = data.status;
				if (data.status == 1) {
					this.contentList[index].like_num++;
				} else {
					this.contentList[index].like_num--;
				}
			},
			bubbleTo(url) {
				if (this.isLogin) {
					this.$util.JumpPath(url);
				} else {
					toLogin();
				}
			},
		},
		onReachBottom() {
			this.getList()
		}
	}
</script>

<style lang="scss">
	.w-510 {
		width: 510rpx;
	}

	.pro-box {
		padding: 20rpx 30rpx;
	}
</style>