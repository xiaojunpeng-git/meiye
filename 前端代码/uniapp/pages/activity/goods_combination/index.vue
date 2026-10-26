<template>
	<view>
		<view class="w-full bg-top relative" :style="{backgroundImage:headerBg}">
			<view class="fixed-lt w-full z-20" :style="{'padding-top': sysHeight + 'px','background': pageScrollStatus ? '#e93323' : 'transparent'}">
				<view class="w-full px-20 pl-20 h-80 flex-between-center">
					<text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff" @tap="backPage"></text>
					<text class="fs-34 fw-500 text--w111-fff">拼团活动</text>
					<text></text>
				</view>
			</view>
		</view>
		<view class="relative rd-t-40rpx bg--w111-f5f5f5 w-full content">
			<view class="" v-if="combinationList.length">
				<view class="pt-32 pl-20 pr-20">
					<view class="card w-full bg--w111-fff rd-24rpx p-20 flex" 
						v-for="(item,index) in combinationList" :key="index"
						@tap="openSubcribe(item)">
						<easy-loadimage
						class="bg-w111-f9f9f9"
						mode="aspectFit" 
						:image-src="item.image"
						width="240rpx"
						height="240rpx"
						borderRadius="20rpx"></easy-loadimage>
						<view class="flex-1 h-240 pl-20 flex-col justify-between">
							<view class="w-full">
								<view class="w-full fs-28 lh-40rpx line2">
									<text v-if="item.brand_name" class="brand-tag">{{ item.brand_name }}</text>
									{{item.title}}
								</view>
								<view class="flex fs-20 mt-14">
									<view class="tuan-num text--w111-fff flex-center">{{item.people}}人团</view>
									<view class="complete font-red flex-center">已拼{{item.pink_count}}份</view>
								</view>
							</view>
							<view class="w-full flex-between-center">
								<view>
									<view class="flex items-baseline">
										<text class="fs-22 lh-30rpx font-red fw-500">拼团价:</text>
										<baseMoney :money="item.price" symbolSize="24" integerSize="40" decimalSize="24" color="#e93323" weight></baseMoney>
									</view>
									<view class="text-line text--w111-999 fs-22 lh-30rpx mt-12">¥{{item.product_price}}</view>
								</view>
								<view class="w-144 h-56 rd-30rpx flex-center fs-24 bg-gradient text--w111-fff" v-if="item.stock > 0 && item.quota > 0">参与拼团</view>
								<view class="w-144 h-56 rd-30rpx flex-center fs-24 bg-gray text--w111-fff" v-else>参与拼团</view>
							</view>
						</view>
					</view>
				</view>
			</view>
			<view class="p-20" v-if="!combinationList.length">
				<emptyPage title="暂无拼团商品，去看看其他商品吧～" src="/statics/images/noActivity.gif"></emptyPage>
			</view>
		</view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import {
		getCombinationList,
		getCombinationBannerList,
		getPink
	} from '@/api/activity.js';
	import { openPinkSubscribe } from '@/utils/SubscribeMessage.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import emptyPage from '@/components/emptyPage.vue';
	import home from '@/components/home/index.vue';
	import {mapGetters} from 'vuex';
	let app = getApp();
	export default {
		data() {
			return {
				sysHeight:sysHeight,
				pinkPeople: [],
				pinkCount: 0,
				bannerList: [],
				circular: true,
				autoplay: true,
				interval: 3000,
				duration: 500,
				combinationList: [],
				limit: 10,
				page: 1,
				loading: false,
				loadend: false,
				isBanner: false,
				pageScrollStatus:false,
				homeHide: false
			}
		},
		components:{ emptyPage, home },
		computed:{
			headerBg(){
				return 'url('+ HTTP_REQUEST_URL +'/statics/images/product/combination_header.png'+')'
			},
			...mapGetters(['uid']),
		},
		onPageScroll(object) {
			if (object.scrollTop > 130) {
				this.pageScrollStatus = true;
			} else if (object.scrollTop < 130) {
				this.pageScrollStatus = false;
			}
			uni.$emit('scroll');
      this.homeHide = true;
		},
		onLoad() {
			uni.setNavigationBarTitle({
				title: "拼团列表"
			})
			this.getCombinationList();
			this.getBannerList();
			this.getPink();
			// #ifdef H5
			this.setOpenShare();
			// #endif
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		methods: {
			getPink: function() {
				getPink({
					type: 2
				}).then(res => {
					this.pinkPeople = res.data.avatars;
					this.pinkCount = res.data.pink_count;
				})
			},
			getBannerList: function() {
				getCombinationBannerList().then(res => {
					this.bannerList = res.data;
					this.isBanner = true;
				})
			},
			goDetail(item) {
				let url = item.link;
				this.$util.JumpPath(url);
			},
			openSubcribe: function(item) {
				let page = item;
				// #ifndef MP
				uni.navigateTo({
					url: `/pages/activity/goods_details/index?id=${item.id}&type=3`
				});
				// #endif
				// #ifdef MP
				uni.showLoading({
					title: '正在加载',
				})
				openPinkSubscribe().then(res => {
					uni.hideLoading();
					uni.navigateTo({
						url: `/pages/activity/goods_details/index?id=${item.id}&type=3`
					});
				}).catch(() => {
					uni.hideLoading();
				});
				// #endif
			},
			getCombinationList: function() {
				var that = this;
				if (that.loadend) return;
				if (that.loading) return;
				var data = {
					page: that.page,
					limit: that.limit
				};
				this.loading = true
				getCombinationList(data).then(function(res) {
					var combinationList = that.combinationList;
					var limit = that.limit;
					that.page++;
					that.loadend = limit > res.data.length;
					that.combinationList = combinationList.concat(res.data);
					that.page = that.data.page;
					that.loading = false;
				}).catch(() => {
					that.loading = false
				})
			},
			backPage(){
				this.$util.backPage();
			},
			// #ifdef H5
			// 微信分享；
			setOpenShare: function() {
				let that = this;
				let uid = this.uid ? this.uid : 0;
				if (that.$wechat.isWeixin()) {
					let data = res.data;
					let configAppMessage = {
						title: '拼团活动',
						link: location.href + '?spid=' + uid
					};
					that.$wechat.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData',
							'onMenuShareAppMessage', 'onMenuShareTimeline'
						],
						configAppMessage);
				}
			},
			// #endif
		},
		onReachBottom: function() {
			this.getCombinationList();
		},
		/**
		 * 用户点击右上角分享
		 */
		//#ifdef MP
		onShareAppMessage() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: '拼团活动',
				path: '/pages/activity/goods_combination/index?spid=' + uid
			};
		},
		//分享到朋友圈
		onShareTimeline: function() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: '拼团活动',
				path: '/pages/activity/goods_combination/index?spid=' + uid
			};
		}
		//#endif
	}
</script>

<style lang="scss">
	.brand-tag {
		background: #E93323 !important;
	}
	.h-470{
		height: 470rpx;;
	}
	.w-624{
		width: 624rpx;
	}
	.bg-gradient {
		background: linear-gradient( 270deg, #FF7931 0%, #E93323 100%);
	}
	.active-tab{
		width: 132rpx;
		height: 52rpx;
		background: #FFF8E4;
		color: #F85517;
		border-radius: 30rpx;
		font-weight: 500;
		display: flex;
		justify-content: center;
		align-items: center;
	}
	.bg-top{
		height: 547rpx;
		background-size: 100% 100%;
		background-repeat: no-repeat;
	}
	.content{
		top: -80rpx;
	}
	._box{
		padding: 40rpx 20rpx 32rpx;
		background: #f5f5f5;
		position: sticky;
		z-index: 99;
	}
	.font-red{
		color: #e93323;
	}
	.bg-red{
		background-color: #e93323;
	}
	.bg-gray{
		background-color: #ccc;
	}
	.con_border{
		border: 1px solid #e93323;
	}
	.card ~ .card{
		margin-top: 20rpx;
	}
	.tuan-num{
		min-width: 70rpx;
		padding: 0 8rpx;
		height: 32rpx;
		background: #E93323;
		border-radius: 8rpx 0 0 8rpx;
	}
	.complete{
		width: 110rpx;
		height: 32rpx;
		background: rgba(233, 51, 35, 0.1);
		border-radius: 0 8rpx 8rpx 0;
	}
</style>