<template>
	<view class="px-20">
		<view class="flex-between-center mt-10">
			<view class="flex-1 h-72 rd-40rpx bg--w111-fff px-32 flex-y-center relative">
				<text class="iconfont icon-ic_search fs-28"></text>
				<input v-model="params.nickname" class="pl-18 flex-1 line1 fs-28" placeholder="请输入用户昵称/手机号/用户ID"
					placeholder-class="text--w111-999" @confirm="searchSubmit" />
				<text class="iconfont icon-ic_close1 fs-28 text--w111-999 z-10" v-if="params.nickname" @tap="clearWord"></text>
			</view>
			<view class="w-72 h-72 rd-50-p111- bg--w111-fff flex-center ml-20" @tap="goPage('/pages/behalf/record/index')">
				<text class="iconfont icon-ic_order text--w111-666"></text>
			</view>
		</view>
		<view class="add-box w-full h-176 rd-24rpx mt-32 flex-col flex-center text-primary"
			@tap="goPage('/pages/behalf/goods_list/index?uid=0')">
			<text class="iconfont icon-ic_picture fs-44"></text>
			<text class="fs-24 lh-34rpx pt-12">未注册用户点此下单</text>
		</view>
		<view v-if="userList.length">
			<view class="w-full h-176 rd-24rpx item flex-between-center bg--w111-fff mt-20"
				v-for="(item,index) in userList" :key="index">
				<view class="flex-y-center">
					<easy-loadimage
					:image-src="item.avatar"
					width="96rpx"
					height="96rpx"
					borderRadius="50%"></easy-loadimage>
					<view class="w-400 ml-24">
						<view class="fs-28 fw-500 flex-y-center">
							<text>{{item.nickname}}</text>
							<view class="svip flex-center" v-if="item.isMember == 1">SVIP</view>
							<view class="vip flex-center" v-if="item.level_status == 1 && item.level_grade">
								<text class="iconfont icon-huiyuandengji"></text>
								{{item.level}}
							</view>
						</view>
						<view class="fs-24 text--w111-999 lh-34rpx pt-4">{{item.phone}}</view>
						<view class="flex-y-center fs-24 lh-34rpx">
							<view class="flex-y-center">
								<text class="text--w111-999 pr-10">积分:</text>
								<text>{{item.integral}}</text>
							</view>
							<view class="flex-y-center pl-30">
								<text class="text--w111-999 pr-10">余额:</text>
								<text>{{item.now_money}}</text>
							</view>
						</view>
					</view>
				</view>
				<view v-if="$util.auth('mall-admin-behalf-order')" class="w-144 h-56 rd-28rpx flex-center fs-24 text--w111-fff bg-primary" @tap="goPage('/pages/behalf/goods_list/index?uid=' + item.uid)">下单</view>
			</view>
		<view class="flex-center fs-26 text--w111-999 pt-24" v-if="userList.length > 0">{{loadTit}}...</view>
		</view>
		<view v-else class="mt-20">
			<emptyPage title="暂无用户信息～" src="/statics/images/empty-box.png"></emptyPage>
		</view>
	</view>
</template>
<script>
	import { adminUserList } from "@/api/admin.js";
	import emptyPage from '@/components/emptyPage.vue';
	export default {
		name:'userList',
		data(){
			return {
				params:{
					page:1,
					limit:10,
					nickname:'',
				},
				userList:[],
				status: false,
				loadTit:'正在加载'
			}
		},
		components: {
			emptyPage,
		},
		onLoad() {
			this.getList();
		},
		onPageScroll(object) {
			uni.$emit('scroll');
		},
		methods:{
			getList(){
				let that = this;
				if (that.status) return;
				that.loadTit = '加载更多';
				adminUserList(this.params).then(res => {
					let len = res.data.list.length;
					this.userList = this.userList.concat(res.data.list)
					that.params.page++;
					that.status = this.params.limit > len;
					that.params.page = that.params.page;
					that.loadTit = that.status ? '没有更多内容啦~' : '加载更多';
				}).catch(err=>{
					that.loadTit = '加载更多';
					return this.$util.Tips({
						title: err
					});
				})
			},
			searchSubmit(){
				this.params.page = 1;
				this.status = false;
				this.userList = [];
				this.getList();
			},
			clearWord(){
				this.params.nickname = ''
			},
			goPage(url){
				uni.navigateTo({
					url
				})
			}
		},
		onReachBottom(e) {
			this.getList()
		}
	}
</script>
<style lang="scss">
	.text-primary{
		color: $primary-admin;
	}
	.bg-primary{
		background: $primary-admin;
	}
	.add-box{
		border: 2rpx dashed $primary-admin;
		background: $light-primary-admin;
		color: $primary-admin;
	}
	.add-icon{
		border: 2rpx solid $primary-admin;
	}
	.item{
		padding: 30rpx 24rpx;
	}
	.icon-ic_close1{
		position: absolute;
		right: 34rpx;
		top: 50%;
		transform: translateY(-50%);
	}
	.svip {
		width: 56rpx;
		height: 26rpx;
		background: linear-gradient(270deg, #484643 0%, #1F1B17 100%);
		border-radius: 100rpx;
		font-size: 18rpx;
		font-weight: 600;
		color: #FDDAA4;
		margin-left: 10rpx;
	}
	.vip{
		min-width: 52rpx;
		padding: 0 12rpx;
		height: 26rpx;
		line-height: 26rpx;
		background: #FEF0D9;
		border: 1px solid #FACC7D;
		border-radius: 50rpx;
		font-size: 18rpx;
		font-weight: 500;
		color: #DFA541;
		margin-left: 10rpx;
		.iconfont {
			font-size: 20rpx;
			margin-right: 4rpx;
		}
	}
</style>
