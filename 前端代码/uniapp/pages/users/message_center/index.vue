<template>
  <!-- 消息中心 -->
	<view class="main">
		<view class="px-20">
			<view class="w-full bg--w111-fff rd-24rpx mt-24 p-32 flex-between-center" 
				v-if="system.id" @tap="goDetail(0)">
				<view class="left-pic w-88 h-88 rd-50-p111- flex-center">
					<text class="iconfont icon-ic_message1 fs-48 text--w111-fff"></text>
				</view>
				<view class="flex-1 pl-24">
					<view class="flex-between-center">
						<view class="fs-32 lh-44rpx w-200 line1">{{system.title}}</view>
						<text class="fs-22 text--w111-999 lh-30rpx">{{ system.add_time }}</text>
					</view>
					<view class="flex-between-center mt-8">
						<view class="fs-24 text--w111-999 lh-34rpx w-430 line1">{{system.content}}</view>
						<view class="dot" v-if="system.message_num > 0"></view>
					</view>
				</view>
			</view>
			<view class="w-full bg--w111-fff rd-24rpx mt-24 p-32 flex-between-center" @tap="goDetail(1)" v-if="community_status && community.id">
				<image src="../static/discover_msg.png" class="w-88 h-88 rd-50-p111- block"></image>
				<view class="flex-1 pl-24">
					<view class="flex-between-center">
						<view class="fs-32 lh-44rpx w-200 line1">互动消息</view>
						<text class="fs-22 text--w111-999 lh-30rpx"></text>
					</view>
					<view class="flex-between-center mt-8">
						<view class="fs-24 text--w111-999 lh-34rpx w-430 line1">用户{{ community.relation_author }}{{ community.record | typeFilter }}了你</view>
						<view class="dot" v-if="community.is_viewed == 0"></view>
					</view>
				</view>
			</view>
			<view class="w-full bg--w111-fff rd-24rpx mt-24 p-32 flex-between-center" 
				v-for="(item, index) in list" :key="index" @tap="goChat(item.to_uid)">
				<image :src="item.avatar" class="w-88 h-88 rd-50-p111-"></image>
				<view class="flex-1 pl-24">
					<view class="flex-between-center">
						<view class="fs-32 lh-44rpx w-200 line1">{{ item.nickname }}</view>
						<view class="fs-22 text--w111-999 lh-30rpx">{{ item._update_time }}</view>
					</view>
					<view class="flex-between-center mt-8">
						<view class="fs-24 text--w111-999 lh-34rpx w-430 line1"
							v-if="[1,2].includes(item.message_type)"
							v-html="item.message"></view>
						<view v-else>{{messageType | filterType}}</view>
						<text class="badge" v-if="item.mssage_num > 0">{{ item.mssage_num > 99 ? '99+' : item.mssage_num}}</text>
					</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import {serviceRecord} from '@/api/user.js';
	import {getCommunityConfig} from '@/api/community.js';
	import colors from '@/mixins/color.js';
	import {HTTP_REQUEST_URL} from '@/config/app';
	export default {
		data() {
			return {
				list: [], //客服消息
				system:{}, //站内信
				community: {}, //社区消息
				page: 1,
				type: 0,
				limit: 20,
				loading: false,
				finished: false,
				imgHost:HTTP_REQUEST_URL,
				community_status: 0, //社区功能的开启与关闭
			};
		},
		filters:{
			messageType(val){
				let obj = {
					3: '[图片]',
					4: '[语音]',
					5: '[商品]',
					6: '[订单]',
				};
				return obj[val]
			},
			typeFilter(val) {
				let obj = {
					1: '点赞',
					2: '评论',
					3: '关注'
				};
				return obj[val];
			}
		},
		onShow() {
			this.page = 1
			this.list = [];
			this.finished = false;
			this.getCommunityConfig();
			this.getList();
		},
		onReachBottom() {
			this.getList()
		},
		onPullDownRefresh() {
			this.page = 1
			this.list = [];
			this.finished = false;
			this.getList();
		},
		methods: {
			// 站内信
			getList() {
				if (this.loading || this.finished) {
					return;
				}
				this.loading = true;
				uni.showLoading({
					title: '加载中'
				});
				serviceRecord({
						page: this.page,
						limit: this.limit
					}).then(res => {
						uni.hideLoading();
						uni.stopPullDownRefresh();
						let data = res.data.service;
						this.system = res.data.system;
						this.community = res.data.community;
						this.loading = false;
						data.forEach(item => {
							if ([1,2].includes(item.message_type)) {
								item.message = this.replace_em(item.message);
							}
						});
						this.list = this.list.concat(data);
						this.finished = data.length < this.limit;
						this.page += 1;
					})
					.catch(err => {
						uni.showToast({
							title: err.msg,
							icon: 'none'
						})
					})
			},
			replace_em(str) {
				str = str.replace(/\[em-([a-z_]*)\]/g, "<span class='em em-$1'/></span>");
				return str;
			},
			goChat(id) {
				uni.navigateTo({
					url: '/pages/extension/customer_list/chat?to_uid=' + id + '&type=1'
				})
			},
			goDetail(type) {
				if (type == 0) {
					uni.navigateTo({
						url: '/pages/users/message_center/messageDetail'
					});
				} else{
					uni.navigateTo({
						url: '/pages/discover/discoverMessage/index'
					});
				}
			},
			getCommunityConfig() {
				getCommunityConfig().then(res => {
					this.community_status = res.data.community_status;
				});
			},
		},
	}
</script>

<style lang="scss" scoped>
	.left-pic{
		background: linear-gradient(180deg, #FFC657 0%, #FE653B 100%);
	}
	.dot{
		width: 20rpx;
		height: 20rpx;
		border-radius: 50%;
		background-color: #e93323;
	}
	.badge{
		min-width: 32rpx;
		max-width: 56rpx;
		height: 32rpx;
		font-size: 22rpx;
		line-height: 32rpx;
		text-align: center;
		padding: 0 8rpx;
		border-radius: 20rpx;
		color: #fff;
		background-color: #e93323;
	}
</style>
