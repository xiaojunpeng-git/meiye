<template>
	<view :style="colorStyle">
		<NavBar titleText="互动消息" textSize="34rpx" iconWeight="400" :isScrolling="pageScrollStatus" :showBack="pageType != 'tab'" :showHome="pageType == 'tab'"></NavBar>
		<view class="h-full h-90 bb-f5 px-120 flex-between-center fs-32 fw-500">
			<view class="h-90 tab flex-y-center" :class="{'active-tab': current == 0,}" @tap="changeTab(0)">点赞</view>
			<view class="h-90 tab flex-y-center" :class="{'active-tab': current == 1,'show-dot':isViewed.comment && current != 1}" @tap="changeTab(1)">评论</view>
			<view class="h-90 tab flex-y-center" :class="{'active-tab': current == 2,'show-dot':isViewed.follow && current != 2}" @tap="changeTab(2)">关注</view>
		</view>
		<view v-show="current == 0">
			<view class="h-180 p-30 flex-between-center" v-for="(item, index) in messageList" :key="index" @tap="toPage(item)">
				<view class="flex-y-center">
					<image class="w-120 h-120 rd-50-p111-" :src="item.relation_author_image"></image>
					<view class="ml-20 w-398">
						<view class="fs-28 lh-40rpx">{{item.relation_author || '用户已注销'}}</view>
						<view class="flex-y-center fs-26 lh-36rpx text--w111-666 pt-8">
							赞了你的{{item.comment_type == 1 ? '内容' : '评论'}} <text class="text--w111-999 fs-22 pl-8">{{item.time_text}}</text>
						</view>
					</view>
				</view>
				<image :src="item.image" class="w-108 h-108 rd-8rpx" mode="aspectFill"></image>
			</view>
		</view>
		<view v-show="current == 1">
			<view class="h-180 p-30 flex-between-center" v-for="(item, index) in messageList" :key="index" @tap="toPage(item)">
				<view class="flex-y-center">
					<image class="w-120 h-120 rd-50-p111-" :src="item.relation_author_image"></image>
					<view class="ml-20 w-398">
						<view class="fs-28 lh-40rpx">{{item.relation_author || '用户已注销'}}</view>
						<view class="fs-26 lh-36rpx text--w111-666 mt-8 w-400 line1">
							评论了你：{{item.content}}
						</view>
						<view class="text--w111-999 fs-22 mt-8">{{item.time_text}}</view>
					</view>
				</view>
				<image :src="item.image" class="w-108 h-108 rd-8rpx" mode="aspectFill"></image>
			</view>
		</view>
		<view v-show="current == 2">
			<view class="h-180 p-30 flex-between-center" v-for="(item, index) in messageList" :key="index" @tap="toAuthor(item)">
				<view class="flex-y-center">
					<image class="w-120 h-120 rd-50-p111-" :src="item.relation_author_image"></image>
					<view class="ml-20 w-398">
						<view class="fs-28 lh-40rpx">{{item.relation_author || '用户已注销'}}</view>
						<view class="flex-y-center fs-26 lh-36rpx text--w111-666 pt-8">
							关注了你 <text class="text--w111-999 fs-22 pl-8">{{item.time_text}}</text>
						</view>
					</view>
				</view>
				<!-- <view class="w-124 h-52 rd-30rpx flex-center bg-color text--w111-fff fs-24"
					@tap="followChange(item)">回关</view> -->
				<!-- <view class="w-124 h-52 rd-30rpx flex-center bg--w111-fff text--w111-999 b-d fs-24" v-show="item.is_follow == 1 && item.is_fans == 1" 
					@tap="openModal(item,index)">互相关注</view> -->
			</view>
		</view>
		<view v-show="!messageList.length">
			<emptyPage title="暂无内容～" src="/statics/images/video/no_friend.png"></emptyPage>
		</view>
		<pageFooter v-if="pageType == 'tab'" :tab="4"></pageFooter>
	</view>
</template>

<script>
	import colors from "@/mixins/color";
	import {
		mapState,
		mapGetters
	} from 'vuex';
	import {
		communityMessageApi
	} from "@/api/community.js";
	import NavBar from "@/components/NavBar.vue";
	import emptyPage from '@/components/emptyPage.vue';
	import pageFooter from '../components/pageFooter/index.vue';
	export default {
		data() {
			return {
				current: 0,
				params: {
					page: 1,
					limit: 20,
					type: 1
				},
				loading: false,
				messageList: [],
				isViewed: {
					comment: 0,
					follow: 0,
					like: 0
				},
				pageType: '',
				pageScrollStatus: false,
			};
		},
		mixins: [colors],
		components: {
			NavBar,
			emptyPage,
			pageFooter,
		},
		computed: {
			...mapGetters(['isLogin', 'uid']),
		},
		onLoad(options) {
			this.pageType = options.type || this.pageType;
			this.getList()
		},
		onReachBottom() {
			this.getList()
		},
		onPageScroll(object) {
			if (object.scrollTop > 130) {
				this.pageScrollStatus = true;
			} else if (object.scrollTop < 130) {
				this.pageScrollStatus = false;
			}
		},
		methods: {
			changeTab(val) {
				this.current = val;
				this.loading = false;
				this.params.page = 1;
				this.params.type = val + 1;
				this.messageList = [];
				this.getList();
			},
			getList() {
				if (this.loading) return;
				this.loading = true;
				communityMessageApi(this.params).then(res => {
					let list = res.data.list;
					this.isViewed = res.data.isViewed;
					let loading = list.length < this.params.limit;
					this.messageList = this.messageList.concat(list);
					this.params.page++;
					this.loading = loading;
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				})
			},
			toPage(item) {
				if (item.community_is_del || !item.status) {
					return this.$util.Tips({
						title: '内容不存在'
					});
				}
				if (item.content_type == 1) {
					uni.navigateTo({
						url: `/pages/discover/discoverDetails/index?id=${item.link_id}`
					})
				} else {
					uni.navigateTo({
						url: `/pages/discover/discoverVideo/index?id=${item.link_id}`
					})
				}

			},
			toAuthor(item) {
				uni.navigateTo({
					url: `/pages/discover/discoverUser/index?id=${item.relation_id}&is_store=${item.type}`
				})
			}
		}
	}
</script>
<style>
	page {
		background-color: #ffffff;
	}
</style>
<style lang="scss">
	.bb-f5 {
		border-bottom: 1px solid #f5f5f5;
	}

	.px-120 {
		padding: 0 120rpx;
	}

	.tab {
		border-bottom: 4rpx solid transparent;
	}

	.active-tab {
		position: relative;

		&:after {
			content: '';
			position: absolute;
			bottom: -4rpx;
			left: 0;
			width: 64rpx;
			height: 4rpx;
			background: #E93323;
		}
	}

	.show-dot {
		position: relative;

		&:after {
			content: '';
			position: absolute;
			top: 18rpx;
			right: -6rpx;
			width: 12rpx;
			height: 12rpx;
			background: #E93323;
			border-radius: 8rpx;
		}
	}
</style>