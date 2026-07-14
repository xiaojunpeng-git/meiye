<template>
	<view class="wf-item-page wf-page0">
		<view class='pictrue relative'>
			<easy-loadimage
			mode="widthFix"
			:image-src="item.image"
			width="100%"
			borderRadius="16rpx 16rpx 0 0"></easy-loadimage>
			<view class="player flex-center" v-if="item.content_type == 2">
				<text class="iconfont icon-ic_right2 fs-20"></text>
			</view>  
			<view class="w-60 h-36 rd-4rpx flex-center fs-22 text--w111-fff pic-number" 
				v-if="item.content_type == 1">{{item.slider_image.length}}图</view>
			<view class="abs-lt w-full h-full flex-col flex-center text--w111-fff shenhe" 
				v-if="[0,-1,-2].includes(item.is_verify)">
				<text class="fs-28" v-show="item.is_verify == 0">正在审核</text>
				<text class="fs-24 pt-22" v-show="item.is_verify == 0">通过后将展示在列表</text>
				<text class="fs-28" v-show="item.is_verify == -1">审核未通过</text>
				<text class="fs-24 pt-22" v-show="item.is_verify == -1">查看未通过原因</text>
				<text class="fs-28" v-show="item.is_verify == -2">强制下架</text>
				<text class="fs-24 pt-22" v-show="item.is_verify == -2">查看下架原因</text>
			</view>
		</view>
		<view class="info_box" :class="{'box-border': border}">
			<view class="w-full lh-40rpx fs-28 line2">{{item.title}}</view>
			<view class="pt-22 fs-22 text--w111-999 flex-between-center">
				<view class="flex-y-center" @tap.stop="toUser">
					<image class="w-34 h-34 rd-50-p111-" :src="item.author_image || '/static/images/f.png'"></image>
					<text class="pl-8 w-180 line1">{{item.author || '用户已注销'}}</text>
				</view>
				<view class="flex-y-center" :class="{'text-red': item.is_like}" @tap.stop="contentLike">
					<text class="iconfont fs-24" :class="item.is_like ? 'icon-icon_Like_2' : 'icon-ic_Like'"></text>
					<text class="pl-10">{{item.like_num}}</text>
				</view>
			</view>
		</view>
	</view>
</template>
<script>
	import easyLoadimage from '@/components/easy-loadimage/easy-loadimage.vue';
	import { communityLikeApi } from "@/api/community.js";
	import { mapGetters } from "vuex";
	import { HTTP_REQUEST_URL } from '@/config/app';
	export default {
		components: {
			easyLoadimage
		},
		props: {
			item: {
				type: Object,
				require: true
			},
			border:{
				type:Boolean,
				default: false
			}
		},
		inject: ['flowLike'],
		data() {
			return {
				domain: HTTP_REQUEST_URL,
			}
		},
		computed:{
			...mapGetters(['isLogin','uid']),
		},
		methods: {
			toUser(){
				uni.navigateTo({
					url: `/pages/discover/discoverUser/index?id=${this.item.relation_id}&is_store=${this.item.type}`
				})
			},
			contentLike(){
				if(!this.isLogin) return this.$util.Tips({
					title: '请登录'
				});
				let status = this.item.is_like == 1 ? 0 : 1;
				let that = this;
				communityLikeApi(this.item.id,{status,is_store:this.item.type}).then(res=>{
					that.flowLike({id:that.item.id,status:status})
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					});
				})
			}
		}
	}
</script>
<style lang="scss" scoped>
	.wf-item-page {
		background: #fff;
		overflow: hidden;
		border-radius: 20rpx;
	}
	.pictrue{
		max-height: 500rpx;
		overflow-y: hidden;
	}
	.info_box{
		padding: 24rpx;
		border-radius: 0 0 24rpx 24rpx;
		background-color: #fff;
	}
	.box-border{
		border: 1rpx solid #eee;
	}
	.text-red{
		color: #e93323;
	}
	.player{
		position: absolute;
		top: 20rpx;
		right: 20rpx;
		width: 40rpx;
		height: 40rpx;
		border-radius: 50%;
		background-color: rgba(51, 51, 51, 0.5);
		color: #fff;
	}
	.shenhe{
		background-color: rgba(0,0,0,0.4);
	}
	.pic-number{
		position: absolute;
		right: 16rpx;
		bottom: 16rpx;
		background: rgba(102, 102, 102, 0.50);
	}
</style>
