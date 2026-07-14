<template>
	<view :style="colorStyle">
		<view class="h-120 flex-between-center px-20">
			<view class="h-72  bg--w111-f5f5f5 rd-36rpx px-32 flex-1 flex-y-center relative">
				<text class="iconfont icon-ic_search  text--w111-999"></text>
				<input type="text" confirm-type="search" placeholder="请输入要查询的内容" @confirm="inputConfirm" @input="setValue"
					class="fs-28 w-438 text--w111-333 pl-18 line1" v-model="searchValue" />
				<text class="iconfont icon-ic_close1 fs-28 text--w111-999 z-10" v-show="searchValue"
					@tap="clearSearchVal"></text>
			</view>
			<view class="fs-28  text--w111-333 ml-32" @tap='searchBut'>搜索</view>
		</view>
		<view class="px-20" v-if="history.length">
			<view class="flex-between-center mt-16 mb-24">
				<view class="fs-28 lh-40rpx fw-500  text--w111-333">历史搜索</view>
				<text class="iconfont icon-ic_delete fs-28  text--w111-999" @tap="clear"></text>
			</view>
			<view class="flex flex-wrap">
				<text class="flex-center h-56 line1 rd-28rpx bg--w111-f5f5f5 px-24 fs-24 text--w111-666 mr-24 mb-16"
					v-for="(item,index) in isShowMore ? history : history.slice(0,7)" :key="index"
					@tap='setHotSearchValue(item.keyword)' v-if="item.keyword">{{item.keyword}}</text>
				<view class="w-56 h-56 rd-28rpx bg--w111-f5f5f5 flex-center text--w111-666"
					v-if="history.length > 7" @tap="isShowMore = !isShowMore">
					<text class="iconfont fs-24" :class="isShowMore ? 'icon-ic_uparrow' : 'icon-ic_downarrow'"></text>
				</view>
			</view>
		</view>
		<view class="px-20">
			<view class="flex-between-center mt-40 mb-24">
				<view class="fs-28 lh-40rpx fw-500  text--w111-333">热门搜索</view>
				<text class="iconfont icon-ic_Refresh fs-28  text--w111-999" :class="isSpin ? 'spin' : ''" @tap="refresh"></text>
			</view>
			<view class="flex flex-wrap">
				<view class="flex-center search_item h-56 rd-28rpx px-24 fs-24  mr-24 mb-16"
					 v-for="(item,index) in hotSearchList" :key="index"
					 :style="{'color':item.color,'background-color':item.bg_color,border: item.border_color ? '1px solid ' + item.border_color : 'none'}"
					 @tap='setHotSearchValue(item.name)'>
					<view class="flex-y-center">
						<image v-if="item.icon" :src="item.icon" class="w-24 h-24 rd-4rpx mr-8"></image>
						<text>{{item.name}}</text>
					</view>
				</view>
			</view>
		</view>
		<view>
			<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-730 mt-32 pl-20" show-scrollbar="false">
				<view class="inline-block mr-20" v-if="salesRecommendList.length">
					<view class="w-454 rd-24rpx gradient_bg pt-22 pb-8">
						<view class="fs-28 font-red lh-40rpx fw-600 pl-24 mb-18 flex-y-center"
							@tap="goRank(1)">
							<image src="../static/sales_icon.png" class="w-40 h-40"></image>
							<text class="pl-8">销量</text>
						</view>
						<view class="mx-8 rd-24rpx  bg--w111-fff h-748 p-20">
							<view class="flex-y-center" v-if="salesRecommendList[0]" @tap="goDetail(salesRecommendList[0])">
								<view class="relative w-108 h-108">
									<image :src="salesRecommendList[0].image" class="w-108 h-108 rd-16rpx"></image>
									<view class="rank-count rank-count1 fs-24 text--w111-fff flex-center">1</view>
								</view>
								<view class="flex-1 h-108 flex-col flex-x-center ml-16">
									<view class="w-260 fs-26  text--w111-333 lh-40rpx line1">{{salesRecommendList[0].store_name}}</view>
									<view class="w-260 fs-22  text--w111-999 lh-30rpx mt-8 line1">{{salesRecommendList[0].store_info}}</view>
								</view>
							</view>
							<view class="flex-y-center mt-16" v-if="salesRecommendList[1]"  @tap="goDetail(salesRecommendList[1])">
								<view class="relative w-108 h-108">
									<image :src="salesRecommendList[1].image" class="w-108 h-108 rd-16rpx"></image>
									<view class="rank-count rank-count2 fs-24 text--w111-fff flex-center">2</view>
								</view>
								<view class="flex-1 h-108 flex-col flex-x-center ml-16">
									<view class="flex-between-center">
										<view class="w-260 fs-26  text--w111-333 lh-40rpx line1">{{salesRecommendList[1].store_name}}</view>
									</view>
									<view class="w-260 fs-22  text--w111-999 lh-30rpx mt-8 line1">{{salesRecommendList[1].store_info}}</view>
								</view>
							</view>
							<view class="flex-y-center mt-16 mb-34" v-if="salesRecommendList[2]" @tap="goDetail(salesRecommendList[2])">
								<view class="relative w-108 h-108">
									<image :src="salesRecommendList[2].image" class="w-108 h-108 rd-16rpx"></image>
									<view class="rank-count rank-count3 fs-24 text--w111-fff flex-center">3</view>
								</view>
								<view class="flex-1 h-108 flex-col flex-x-center ml-16">
									<view class="flex-between-center">
										<view class="w-260 fs-26  text--w111-333 lh-40rpx line1">{{salesRecommendList[2].store_name}}</view>
									</view>
									<view class="w-260 fs-22  text--w111-999 lh-30rpx mt-8 line1">{{salesRecommendList[2].store_info}}</view>
								</view>
							</view>
							<view class="flex-between-center scroll_cell" v-for="(item,index) in salesRecommendList.slice(3)" :key="index" @tap="goDetail(item)">
								<view class="flex-y-center">
									<text class="inline-block w-30 h-32 lh-32rpx text--w111-fff text-center fs-24 rank_4">{{index + 4}}</text>
									<text class="fs-26 text--w111-333 ml-20 w-360 line1">{{item.store_name}}</text>
								</view>
							</view>
						</view>
					</view>
				</view>
				<view class="inline-block mr-20" v-if="scoreRecommendList.length">
					<view class="w-454 rd-24rpx gradient_bg pt-22 pb-8">
						<view class="fs-28 font-red lh-40rpx fw-600 pl-24 mb-18 flex-y-center"
							@tap="goRank(2)">
							<image src="../static/sales_icon.png" class="w-40 h-40"></image>
							<text class="pl-8">评分</text>
						</view>
						<view class="mx-8 rd-24rpx  bg--w111-fff h-748 p-20">
							<view class="flex-y-center" v-if="scoreRecommendList[0]" @tap="goDetail(scoreRecommendList[0])">
								<view class="relative w-108 h-108">
									<image :src="scoreRecommendList[0].image" class="w-108 h-108 rd-16rpx"></image>
									<view class="rank-count rank-count1 fs-24 text--w111-fff flex-center">1</view>
								</view>
								<view class="flex-1 h-108 flex-col flex-x-center ml-16">
									<view class="flex-between-center">
										<view class="w-260 fs-26  text--w111-333 lh-40rpx line1">{{scoreRecommendList[0].store_name}}</view>
									</view>
									<view class="w-260 fs-22  text--w111-999 lh-30rpx mt-8 line1">{{scoreRecommendList[0].store_info}}</view>
								</view>
							</view>
							<view class="flex-y-center mt-16"  v-if="scoreRecommendList[1]" @tap="goDetail(scoreRecommendList[1])">
								<view class="relative w-108 h-108">
									<image :src="scoreRecommendList[1].image" class="w-108 h-108 rd-16rpx"></image>
									<view class="rank-count rank-count2 fs-24 text--w111-fff flex-center">2</view>
								</view>
								<view class="flex-1 h-108 flex-col flex-x-center ml-16">
									<view class="flex-between-center">
										<view class="w-260 fs-26  text--w111-333 lh-40rpx line1">{{scoreRecommendList[1].store_name}}</view>
									</view>
									<view class="w-260 fs-22  text--w111-999 lh-30rpx mt-8 line1">{{scoreRecommendList[1].store_info}}</view>
								</view>
							</view>
							<view class="flex-y-center mt-16 mb-34" v-if="scoreRecommendList[2]" @tap="goDetail(scoreRecommendList[2])">
								<view class="relative w-108 h-108">
									<image :src="scoreRecommendList[2].image" class="w-108 h-108 rd-16rpx"></image>
									<view class="rank-count rank-count3 fs-24 text--w111-fff flex-center">3</view>
								</view>
								<view class="flex-1 h-108 flex-col flex-x-center ml-16">
									<view class="flex-between-center">
										<view class="w-260 fs-26  text--w111-333 lh-40rpx line1">{{scoreRecommendList[2].store_name}}</view>
									</view>
									<view class="w-260 fs-22  text--w111-999 lh-30rpx mt-8 line1">{{scoreRecommendList[2].store_info}}</view>
								</view>
							</view>
							<view class="flex-between-center scroll_cell" v-for="(item,index) in scoreRecommendList.slice(3)" :key="index" @tap="goDetail(item)">
								<view class="flex-y-center">
									<text class="inline-block w-30 h-32 lh-32rpx text--w111-fff text-center fs-24 rank_4">{{index + 4}}</text>
									<text class="fs-26 text--w111-333 ml-20 w-360 line1">{{item.store_name}}</text>
								</view>
							</view>
						</view>
					</view>
				</view>
				<view class="inline-block mr-20" v-if="collectRecommendList.length">
					<view class="w-454 rd-24rpx gradient_bg pt-22 pb-8">
						<view class="fs-28 font-red lh-40rpx fw-600 pl-24 mb-18 flex-y-center"
							@tap="goRank(3)">
							<image src="../static/sales_icon.png" class="w-40 h-40"></image>
							<text class="pl-8">收藏</text>
						</view>
						<view class="mx-8 rd-24rpx  bg--w111-fff h-748 p-20">
							<view class="flex-y-center" v-if="collectRecommendList[0]" @tap="goDetail(collectRecommendList[0])">
								<view class="relative w-108 h-108">
									<image :src="collectRecommendList[0].image" class="w-108 h-108 rd-16rpx"></image>
									<view class="rank-count rank-count1 fs-24 text--w111-fff flex-center">1</view>
								</view>
								<view class="flex-1 h-108 flex-col flex-x-center ml-16">
									<view class="flex-between-center">
										<view class="w-260 fs-26  text--w111-333 lh-40rpx line1">{{collectRecommendList[0].store_name}}</view>
									</view>
									<view class="w-260 fs-22  text--w111-999 lh-30rpx mt-8 line1">{{collectRecommendList[0].store_info}}</view>
								</view>
							</view>
							<view class="flex-y-center mt-16"  v-if="collectRecommendList[1]" @tap="goDetail(collectRecommendList[1])">
								<view class="relative w-108 h-108">
									<image :src="collectRecommendList[1].image" class="w-108 h-108 rd-16rpx"></image>
									<view class="rank-count rank-count2 fs-24 text--w111-fff flex-center">2</view>
								</view>
								<view class="flex-1 h-108 flex-col flex-x-center ml-16">
									<view class="flex-between-center">
										<view class="w-260 fs-26  text--w111-333 lh-40rpx line1">{{collectRecommendList[1].store_name}}</view>
									</view>
									<view class="w-260 fs-22  text--w111-999 lh-30rpx mt-8 line1">{{collectRecommendList[1].store_info}}</view>
								</view>
							</view>
							<view class="flex-y-center mt-16 mb-34" v-if="collectRecommendList[2]" @tap="goDetail(collectRecommendList[2])">
								<view class="relative w-108 h-108">
									<image :src="collectRecommendList[2].image" class="w-108 h-108 rd-16rpx"></image>
									<view class="rank-count rank-count3 fs-24 text--w111-fff flex-center">3</view>
								</view>
								<view class="flex-1 h-108 flex-col flex-x-center ml-16">
									<view class="flex-between-center">
										<view class="w-260 fs-26  text--w111-333 lh-40rpx line1">{{collectRecommendList[2].store_name}}</view>
									</view>
									<view class="w-260 fs-22  text--w111-999 lh-30rpx mt-8 line1">{{collectRecommendList[2].store_info}}</view>
								</view>
							</view>
							<view class="flex-between-center scroll_cell" v-for="(item,index) in collectRecommendList.slice(3)" :key="index" @tap="goDetail(item)">
								<view class="flex-y-center">
									<text class="inline-block w-30 h-32 lh-32rpx text--w111-fff text-center fs-24 rank_4">{{index + 4}}</text>
									<text class="fs-26 text--w111-333 ml-20 w-360 line1">{{item.store_name}}</text>
								</view>
							</view>
						</view>
					</view>
				</view>
			</scroll-view>
		</view>
		<scroll-view scroll-y="true" class="fuzzy_modal" :style="{height:fuzzyHeight + 'px',top: 60 + 'px'}"
			v-if="newPartyList.length" @touchmove.stop.prevent="moveStop">
			<view class="cell" v-for="(item,index) in newPartyList" :key="index"
				@tap="setCommentSearch(item.id)">
				<view class="keyword line1" v-html="item.keyword"></view>
			</view>
		</scroll-view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import home from '@/components/home/index.vue'
	import {
		getSearchKeyword,
		getSearchRecommendApi,
		getHotWordApi
	} from '@/api/store.js';
	import { searchList, clearSearch } from '@/api/api.js';
	import colors from "@/mixins/color";
	import { goShopDetail } from '@/libs/order.js';
	import { HTTP_REQUEST_URL } from '@/config/app.js';
	import { Debounce } from '@/utils/validate.js'
	export default {
		components: {
			home
		},
		mixins: [colors],
		data() {
			return {
				hostProduct: [],
				searchValue: '',
				focus: true,
				bastList: [],
				hotSearchList: [],
				isSpin:false,
				first: 0,
				limit: 8,
				page: 1,
				loading: false,
				loadend: false,
				loadTitle: '加载更多',
				hotPage: 1,
				isScroll: true,
				history: [],
				imgHost: HTTP_REQUEST_URL,
				salesRecommendList:[],
				scoreRecommendList:[],
				collectRecommendList:[],
				sysHeight:sysHeight,
				newPartyList: [], //模糊搜索关键词列表
				isShowMore: false,
				homeHide: false
			};
		},
		computed: {
			fuzzyHeight() {
				let screenHeight = uni.getSystemInfoSync().screenHeight;
				return screenHeight - this.sysHeight - 56;

			}
		},
		onLoad(e) {
			this.searchValue = e.searchVal || '';
			this.getSearchHotKeywords();
			this.getSearchRecommend();
			setTimeout(()=>{
				this.newPartySearch();
			},1000)
		},
		onShow: function(e) {
			uni.removeStorageSync('form_type_cart');
			this.searchList();

		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			refresh(){
				this.isSpin = true;
				this.getSearchHotKeywords();
			},
			// 获取搜索热词
			getSearchHotKeywords(){
				getHotWordApi().then(res=>{
					this.hotSearchList = res.data;
					setTimeout(_=>{
						this.isSpin = false;
					},1000)
				})
			},
			searchList() {
				searchList({
					page: 1,
					limit: 10
				}).then(res => {
					this.history = res.data;
				});
			},
			clear() {
				let that = this;
				clearSearch().then(res => {
					uni.showToast({
						title: res.msg,
						success() {
							that.history = [];
						}
					});
				});
			},
			inputConfirm: function(event) {
				if (event.detail.value) {
					uni.hideKeyboard();
					this.setHotSearchValue(event.detail.value);
				}
			},
			setValue: Debounce(function(e){
				this.newPartySearch();
			}),
			newPartySearch: function() {
				getSearchKeyword({keyword:this.searchValue}).then(res => {
					this.newPartyList = res.data.list;
					this.newPartyList.map((item) => {
						this.$set(item,'keyword',this.brightKeyword(item.store_name,res.data.keyword));
					});
				});
			},
			brightKeyword(val,keyword) {
				const escapedVal = this.escapeHtml(val); 
				// 转义关键字中的正则特殊字符（如 . * 等）
				const escapedKeyword = keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); 
				const regex = new RegExp(`(${escapedKeyword})`, 'gi');
				// 高亮匹配项并保留转义后的安全文本
				return escapedVal.replace(regex, '<span style="color: #C9771E;">$1</span>');
			},
			escapeHtml(text) {
			  const map = {
			    '&': '&amp;',
			    '<': '&lt;',
			    '>': '&gt;',
			    '"': '&quot;',
			    "'": '&#039;'
			  };
			  return text.replace(/[&<>"']/g, (m) => map[m]);
			},
			setCommentSearch(id) {
				uni.navigateTo({
					url:'/pages/goods/goods_list/index?productId=' + id
				})
			},
			setHotSearchValue: function(event) {
				this.$set(this, 'searchValue', event);
				this.focus = false;
				this.searchBut();
			},
			searchBut: function() {
				let that = this;
				that.focus = false;
				if (that.searchValue.length > 0) {
					this.newPartyList = [];
					uni.navigateTo({
						url:'/pages/goods/goods_list/index?searchValue=' + that.searchValue
					})
				} else {
					return this.$util.Tips({
						title: '请输入要搜索的商品',
						icon: 'none',
						duration: 1000,
						mask: true,
					});
				}
			},
			getSearchRecommend(){
				getSearchRecommendApi(1).then(res=>{
					this.salesRecommendList = res.data;
				})
				getSearchRecommendApi(2).then(res=>{
					this.scoreRecommendList= res.data;
				})
				getSearchRecommendApi(3).then(res=>{
					this.collectRecommendList= res.data;
				})
			},
			// 去详情页
			goDetail(item) {
				goShopDetail(item, this.uid).catch(res => {
					uni.navigateTo({
						url: `/pages/goods_details/index?id=${item.id}`
					});
				});
			},
			goRank(type){
				uni.navigateTo({
					url:`/pages/columnGoods/rank/index?type=${type}`
				})
			},
			clearSearchVal(){
				this.searchValue = '';
				this.newPartyList = [];
			}
		}
	}
</script>
<style>
	page {
		background-color: #fff;
	}
</style>
<style lang="scss" scoped>
	.font-red{
		color: #e93323;
	}
	.gradient_bg {
	  background: linear-gradient(172deg, rgba(255, 250, 218, 0.15) 0%, #fceae9 100%), linear-gradient(112deg, #fadfdf 0%, rgba(252, 234, 233, 0) 100%);
	}
	.max-200{
		max-width: 200rpx;
	}
	.pb-8{
		padding-bottom:8rpx;
	}
	.w-40{
		width: 40rpx;
	}
	.h-40{
		height:40rpx;
	}
	.search_item{
		color: #666;
		background-color: #f5f5f5;
	}
	.rank-count{
		width:30rpx;
		height:32rpx;
		position: absolute;
		top:0;
		left:0;
		background-size: cover;
	}
	.rank-count1{
		background-image:url('@/static/img/rank1_icon.png');
	}
	.rank-count2{
		background-image:url('@/static/img/rank2_icon.png');
	}
	.rank-count3{
		background-image:url('@/static/img/rank3_icon.png');
	}
	.rank_4{
		background-image:url('../static/rank4_icon.png');
		background-size:100%;
	}
	.scroll_cell ~ .scroll_cell{
		margin-top: 32rpx;
	}
	.fuzzy_modal {
		background-color: #FFF;
		position: fixed;
		width: 100%;
		left: 0;
		box-sizing: border-box;
		.cell {
			width: 100%;
			height: 88rpx;
			border-bottom: 0.5px solid #f5f5f5;
			.keyword {
				height: 88rpx;
				line-height: 88rpx;
				font-size: 28rpx;
				font-family: PingFangSC-Regular, PingFang SC;
				font-weight: 400;
				color: #1B1B1B;
				padding-left: 32rpx;
				flex: 1;
			}
		}
	}
	.spin{
		animation-name: spin;
		animation-duration: 1s;
		animation-timing-function: linear;
		animation-iteration-count: infinite;
		transform-origin: center center;
		display: inline-block;
	}
	@keyframes spin{
		from{
			transform: rotate(0deg);
		}
		to{
			transform: rotate(360deg);
		}
	}
	.icon-ic_close1{
		position: absolute;
		right: 20rpx;
		top: 50%;
		transform: translateY(-50%);
	}
</style>
