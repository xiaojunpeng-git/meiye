<template>
	<!-- 签到 -->
	<view>
		<view class='sign-record'>
			<view class='list' v-for="(item,index) in signList" :key="index">
				<view class='item'>
					<view class='data'>{{item.month}}</view>
					<view class='listn'>
						<view class='itemn acea-row row-between-wrapper' v-for="(itemn,indexn) in item.list" :key="indexn">
							<view>
								<view class='name line1'>{{itemn.title}}</view>
								<view>{{itemn.add_time}}</view>
							</view>
							<view class="flex justify-end align-center">
								<view class="flex-col flex-center num-item Regular fs-32" v-show="itemn.exp_num > 0">
									<image src="@/static/images/sign-icon-02.png" class="w-36 h-36"></image>
									<text class="pt-6">+{{itemn.exp_num}}</text>
								</view>
								<view class="flex-col flex-center num-item Regular fs-32" v-show="itemn.number > 0">
									<image src="@/static/images/sign-icon-01.png" class="w-36 h-36"></image>
									<text class="pt-6">+{{itemn.number}}</text>
								</view>
							</view>

						</view>
					</view>
				</view>
			</view>
			<view class='loadingicon acea-row row-center-wrapper' v-if="signList.length > 0">
				<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadtitle}}
			</view>
			<view class="mt-20" v-if="!signList.length">
				<emptyPage title="暂无签到记录~" src="/statics/images/noOrder.gif"></emptyPage>
			</view>
		</view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import {
		getSignMonthList
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import emptyPage from '@/components/emptyPage';
	import home from '@/components/home/index.vue';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		components: {
			emptyPage,
			home
		},
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				loading: false,
				loadend: false,
				loadtitle: '加载更多',
				page: 1,
				limit: 8,
				signList: [],
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						// #ifdef H5 || APP-PLUS
						this.getSignMoneList();
						// #endif
					}
				},
				deep: true
			}
		},
		onLoad() {
			if (this.isLogin) {
				this.getSignMoneList();
			} else {
				toLogin()
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		onReachBottom: function() {
			this.getSignMoneList();
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			/**
			 * 
			 * 授权回调
			 */
			onLoadFun: function() {
				this.getSignMoneList();
				this.isShowAuth = false;
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			/**
			 * 获取签到记录列表
			 */
			getSignMoneList: function() {
				let that = this;
				if (that.loading) return;
				if (that.loadend) return;
				that.loading = true;
				that.loadtitle = "";
				getSignMonthList({
					page: that.page,
					limit: that.limit
				}).then(res => {
					let list = res.data;
					let loadend = list.length < that.limit;
					that.signList = that.$util.SplitArray(list, that.signList);
					that.$set(that, 'signList', that.signList);
					that.loadend = loadend;
					that.loading = false;
					that.loadtitle = loadend ? "没有更多内容啦~" : "加载更多"
				}).catch(err => {
					that.loading = false;
					that.loadtitle = '加载更多';
				});
			},
      onhomehide() {
				this.homeHide = false;
			}
		}
	}
</script>

<style lang="scss" scoped>
	.sign-record {
		padding: 0 20rpx;
	}

	.sign-record .list .item .data {
		height: 90rpx;
		padding: 0;
	}

	.sign-record .list .item .listn {
		border-radius: 24rpx;
		line-height: 34rpx;
	}

	.sign-record .list .item .listn .itemn {
		height: auto;
		padding: 32rpx 24rpx;
	}

	.sign-record .list .item .listn .itemn .name {
		margin-bottom: 12rpx;
		line-height: 40rpx;
		color: #333333;
	}
	.num-item{
		width: 80rpx;
		height: 90rpx;
		background: rgba(250,173,20,0.06);
		border-radius: 10rpx;
		color: #D15802;
	}
	.num-item ~ .num-item{
		margin-left: 16rpx;
	}
</style>