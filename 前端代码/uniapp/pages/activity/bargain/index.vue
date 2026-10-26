<template>
	<view :style="colorStyle">
		<view class="px-20">
			<view class="order_card bg--w111-fff rd-24rpx pt-32 pb-32 pl-24 pr-24"
			v-for="(item, index) in bargain" :key="index">
				<view class="flex-between-center">
					<text class="fs-28 lh-40rpx">{{item.add_time}}</text>
					<text class="font-color fs-26" v-if="item.status === 1">活动进行中</text>
					<text class="font-color fs-26" v-else-if="item.status === 3">砍价成功</text>
					<text class="font-color fs-26" v-else>活动已结束</text>
				</view>
				<view class="flex-between-center mt-26">
					<image :src="item.image" class="w-136 h-136 rd-16rpx"></image>
					<view class="flex-1 pl-20">
						<view class="w-504 line2 fs-28 lh-40rpx">{{item.title}}</view>
						<view class="flex items-baseline mt-20">
							<text class="fs-24 text--w111-999">已砍至</text>
							<baseMoney :money="item.residue_price" symbolSize="20" integerSize="32" decimalSize="20" weight></baseMoney>
						</view>
					</view>
				</view>
				<view class="pt-24 bt mt-32 flex-between-center">
					<view class="flex-y-center fs-24" v-if="item.status === 1">
						<text class="pr-12 text--w111-999">剩余:</text>
						<count-down
						justify-left="justify-content:left"
						:is-day="true"
						tip-text=" "
						day-text=" "
						hour-text="时"
						minute-text="分"
						second-text="秒"
						dotColor="#999"
						:datatime="item.datatime"></count-down>
					</view>
					<text class="pr-12 text--w111-999 fs-24" v-if="item.status === 2">活动已结束</text>
					<text class="pr-12 text--w111-999 fs-24" v-if="item.status === 3">砍价已成功</text>
					<view class="flex">
						<view class="btn w-154 h-56 rd-30rpx flex-center fs-24 border_ccc"
							v-if="item.status === 1"
							@tap="getBargainUserCancel(item.bargain_id)"
							>取消活动</view>
						<view class="btn w-154 h-56 rd-30rpx flex-center fs-24 bg-color text--w111-fff"
							v-if="item.status === 1"
							@tap="goDetail(item.bargain_id)"
							>{{item.pay_status ? '立即付款' : '继续砍价'}}</view>
						<view class="btn w-154 h-56 rd-30rpx flex-center fs-24 bg-color text--w111-fff"
							v-if="[2,3].includes(item.status)" @tap="goPage('/pages/activity/goods_bargain/index')">重开一个</view>
					</view>
				</view>
			</view>
			<Loading :loaded="status" :loading="loadingList"></Loading>
		</view>
		<view class="px-20 mt-20" v-if="!bargain.length">
			<emptyPage title="暂无砍价记录～" src="/statics/images/noOrder.gif"></emptyPage>
		</view>
		<home :isHide="homeHide"></home>
	</view>
</template>
<script>
	import CountDown from "@/components/countDown";
	import emptyPage from '@/components/emptyPage.vue'
	import {
		getBargainUserList,
		getBargainUserCancel
	} from "@/api/activity";
	import {
		getUserInfo
	} from '@/api/user.js';
	import Loading from "@/components/Loading";
	import home from '@/components/home';
	import colors from "@/mixins/color";
	export default {
		name: "BargainRecord",
		components: {
			CountDown,
			Loading,
			emptyPage,
			home
		},
		props: {},
		mixins: [colors],
		data: function() {
			return {
				bargain: [],
				status: false, //砍价列表是否获取完成 false 未完成 true 完成
				loadingList: false, //当前接口是否请求完成 false 完成 true 未完成
				page: 1, //页码
				limit: 20, //数量
				userInfo: {},
				homeHide: false,
			};
		},
		onLoad: function() {
			this.getBargainUserList();
			this.getUserInfo();
		},
		onShow(){
			uni.removeStorageSync('form_type_cart');
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			goDetail: function(id) {
				uni.navigateTo({
					url: `/pages/activity/goods_bargain_details/index?id=${id}&spid=${this.userInfo.uid}`
				})
			},
			getBargainUserList: function() {
				var that = this;
				if (that.loadingList) return;
				if (that.status) return;
				getBargainUserList({
						page: that.page,
						limit: that.limit
					})
					.then(res => {
						that.status = res.data.length < that.limit;
						that.bargain.push.apply(that.bargain, res.data);
						that.page++;
						that.loadingList = false;
					})
					.catch(res => {
						that.$util.Tips({
							title: res
						})
					});
			},
			getBargainUserCancel: function(bargainId) {
				var that = this;
				getBargainUserCancel({
						bargainId: bargainId
					})
					.then(res => {
						that.status = false;
						that.loadingList = false;
						that.page = 1;
						that.bargain = [];
						that.getBargainUserList();
						that.$util.Tips({
							title: res.msg
						})
					})
					.catch(res => {
						that.$util.Tips({
							title: res
						})
					});
			},
			/**
			 * 获取个人用户信息
			 */
			getUserInfo: function() {
				let that = this;
				getUserInfo().then(res => {
					that.userInfo = res.data;
				});
			},
			goPage(url){
				uni.navigateTo({
					url
				})
			}
		},
		onReachBottom() {
			this.getBargainUserList();
		}
	};
</script>

<style lang="scss">
	.order_card{
		margin-top:20rpx;
	}
	.border_ccc {
		border: 1rpx solid #ccc;
	}
	.bt{
		border-top: 1rpx solid #eee;
	}
	.btn ~ .btn{
		margin-left: 16rpx;
	}
</style>
