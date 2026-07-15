<template>
  <!-- 咨询模块 -->
	<view :style="colorStyle">
		<view class='newsList'>
			<view class="w-full h-392 relative" v-if="imgUrls.length > 0">
				<swiper
					indicator-dots="true"
					autoplay
					circular
					:interval="3000"
					:duration="500"
					indicator-color="rgba(255,255,255,0.1)"
					indicator-active-color="#fff"
					class="w-full h-392 relative">
					<block v-for="(item,index) in imgUrls" :key="index">
						<swiper-item>
							<navigator :url="'/pages/extension/news_details/index?id='+item.id">
								<image :src="item.image_input[0]" class="w-full h-392" mode="aspectFill" />
							</navigator>
						</swiper-item>
					</block>
				</swiper>
			</view>
			<view class="bg--w111-f5f5f5 relative rd-t-32rpx pt-12 content">
				<view class="nab-box w-full flex-between-center">
					<scroll-view scroll-x="true"
					 scroll-with-animation :scroll-left="scrollLeft"
					class="white-nowrap vertical-middle w-662 pl-32"
					show-scrollbar="false">
					  <view class="inline-block fs-30 h-88 lh-88rpx mr-40 "
						v-for="(item, index) in navList" :key="index"
						:class="active == item.id ? 'active' : 'text--w111-999'"
						@tap="tabSelect(item.id,index)">{{item.title}}</view>
					</scroll-view>
					<view class="h-88 w-88 flex-center" @tap="checkGrid">
						<text class="iconfont icon-a-ic_Imageandtextsorting fs-32 text--w111-999"></text>
					</view>
				</view>
				<view class="mt-24 grid-column-2 grid-gap-18rpx px-24" v-show="grid">
					<view v-for="(item,index) in articleList" :key="index" @tap="getDetail(item.id)">
						<easy-loadimage
						:image-src="item.image_input[0]"
						width="100%"
						height="216rpx"
						borderRadius="16rpx 16rpx 0 0"></easy-loadimage>
						<view class="bg--w111-fff rd-b-16rpx p-20">
							<view class="w-full fs-28 h-80 lh-40rpx line2">{{item.title}}</view>
							<view class="flex-between-center mt-12 fs-24 text--w111-999">
								<text>{{item.add_time}}</text>
								<view class="flex-y-center">
									<text class="iconfont icon-ic_Eyes fs-28"></text>
									<text class="pl-8">{{item.visit}}</text>
								</view>
							</view>
						</view>
					</view>
				</view>
				<view class="mt-24 px-24" v-show="!grid">
					<view class="flex justify-between mb-40" v-for="(item,index) in articleList" :key="index"
						@tap="getDetail(item.id)">
						<easy-loadimage
						:image-src="item.image_input[0]"
						width="240rpx"
						height="152rpx"
						borderRadius="16rpx"></easy-loadimage>
						<view class="flex-1 pl-24 h-152 flex-col justify-between">
							<view class="w-full line2 fs-30 lh-42rpx h-84">{{item.title}}</view>
							<view class="flex-between-center mt-12 fs-24 text--w111-999">
								<text>{{item.add_time}}</text>
								<view class="flex-y-center">
									<text class="iconfont icon-ic_Eyes fs-28"></text>
									<text class="pl-8">{{item.visit}}</text>
								</view>
							</view>
						</view>
					</view>
				</view>
				<view class='px-20' v-if="!articleList.length">
					<emptyPage title="暂无新闻信息～" src="/statics/images/noNews.gif"></emptyPage>
				</view>
			</view>
		</view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	import {
		getArticleCategoryList,
		getArticleList,
		getArticleHotList,
		getArticleBannerList
	} from '@/api/api.js';
	import colors from "@/mixins/color";
	import {HTTP_REQUEST_URL} from '@/config/app';
	import emptyPage from '@/components/emptyPage.vue';
	import home from '@/components/home/index.vue';
	export default {
		components: {
			emptyPage,
			home
		},
		mixins: [colors],
		data() {
			return {
				imgUrls: [],
				articleList: [],
				navList: [],
				active: 0,
				page: 1,
				limit: 8,
				status: false,
				scrollLeft: 0,
				imgHost:HTTP_REQUEST_URL,
				grid:true,
				homeHide: false
			};
		},
		onLoad(){
			this.getArticleHot();
			this.getArticleBanner();
			this.getArticleCate();
		},
		/**
		 * 页面上拉触底事件的处理函数
		 */
		onReachBottom: function () {
		  this.getCidArticle();
		},
		onPageScroll(object) {
			uni.$emit('scroll');
      this.homeHide = true;
		},
		methods: {
			getDetail(id){
				uni.navigateTo({
					url:'/pages/extension/news_details/index?id=' + id
				})
			},
			checkGrid(){
				this.grid = !this.grid;
			},
			getArticleHot: function() {
				let that = this;
				getArticleHotList().then(res => {
					that.$set(that, 'articleList', res.data);
				});
			},
			getArticleBanner: function() {
				let that = this;
				getArticleBannerList().then(res => {
					that.imgUrls = res.data;
				});
			},
			getCidArticle: function() {
        let that = this;
        if (that.active == 0) return;
        let articleList = that.articleList;
        if (that.status) return;
        getArticleList(that.active, {
          page: that.page,
          limit: that.limit
        }).then(res => {
          let len = res.data.length;
          that.articleList = that.articleList.concat(res.data);
          that.page++;
          that.status = that.limit > len;
        });
			},
			getArticleCate: function() {
				let that = this;
				getArticleCategoryList().then(res => {
					that.$set(that, 'navList', res.data);
				});
			},
			tabSelect(active,e) {
				this.active = active;
				this.scrollLeft =  e * 60;
				if (this.active == 0) this.getArticleHot();
				else {
					this.$set(this, 'articleList', []);
					this.page = 1;
					this.status = false;
					this.getCidArticle();
				}
			}
		}
	}
</script>

<style lang="scss">
	.h-392{
		height: 392rpx;
	}
	.content{
		left: 0;
		top: -32rpx;
	}
	.active{
		color: #333;
		font-size: 32rpx;
		font-weight: 500;
		position: relative;
		&::after{
			position: absolute;
			content: '';
			bottom: 8rpx;
			width: 50rpx;
			height: 64rpx;
			border: 4rpx solid transparent;
			border-radius: 50%;
			transform: translateX(-50%);
			border-bottom-color: var(--view-theme);
			left:50%
		}
	}
	// #ifdef APP-PLUS || H5
	.newsList ::v-deep uni-swiper .uni-swiper-dots-horizontal{
		bottom: 50rpx;
	}
	.newsList ::v-deep uni-swiper .uni-swiper-dots-horizontal .uni-swiper-dot{
		width: 10rpx;
		height: 10rpx;
		border-radius: 50%;
	}
	.newsList ::v-deep uni-swiper .uni-swiper-dots-horizontal .uni-swiper-dot-active{
		width: 18rpx;
		height: 10rpx;
		border-radius: 6rpx;
		background-color: #fff;
	}

	.newsList .swiper ::v-deep .uni-swiper-dot ~ .uni-swiper-dot {
		margin-left: 5rpx;
	}
	// #endif
	// #ifdef MP
	.newsList ::v-deep wx-swiper .wx-swiper-dots-horizontal{
		bottom: 50rpx;
	}
	.newsList .swiper ::v-deep .wx-swiper-dot~.wx-swiper-dot {
		width: 10rpx;
		height: 10rpx;
		border-radius: 50%;
		margin-left: 5rpx;
	}
	// #endif

</style>
