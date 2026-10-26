<template>
	<view :style="colorStyle">
		<view class='bill-details'>
			<view class='nav acea-row'>
				<view class='item' :class='type==0 ? "on":""' @click='changeType(0)'>全部</view>
				<view class='item' :class='type==1 ? "on":""' @click='changeType(1)'>消费</view>
				<view class='item' :class='type==2 ? "on":""' @click='changeType(2)'>充值</view>
			</view>
			<view class='sign-record'>
				<view class='list' v-for="(item,index) in userBillList" :key="index">
					<view class='item'>
						<view class='data'>{{item.time}}</view>
						<view class='listn'>
							<view class='itemn acea-row row-between' v-for="(vo,indexn) in item.child" :key="indexn">
								<view class="flex-1">
									<view class='name'>{{vo.mark}}</view>
									<view>{{vo.add_time}}</view>
								</view>
								<view class='num' v-if="vo.pm">+{{vo.number}}</view>
								<view class='num' v-else>-{{vo.number}}</view>
							</view>
						</view>
					</view>
				</view>
				<view class='loadingicon acea-row row-center-wrapper' v-if="userBillList.length>0">
					<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
				</view>
				<view class="px-20 mt-20" v-if="userBillList.length == 0">
					<emptyPage title="暂无记录～" src="/statics/images/noOrder.gif"></emptyPage>
				</view>
			</view>
		</view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import {
		getCommissionInfo,
		moneyList
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import emptyPage from '@/components/emptyPage.vue';
	import colors from "@/mixins/color";
	import home from '@/components/home/index.vue';
	export default {
		components: {
			emptyPage,
			home
		},
		mixins: [colors],
		data() {
			return {
        uid:0,
				loadTitle: '加载更多',
				loading: false,
				loadend: false,
				page: 1,
				limit: 15,
				type: 0,
				userBillList: [],
				times:[],
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		onShow() {
			uni.removeStorageSync('form_type_cart');
			if (this.isLogin) {
				this.getUserBillList();
			} else {
				toLogin()
			}
		},
		/**
		 * 生命周期函数--监听页面加载
		 */
		onLoad: function(options) {
			this.type = options.type || 0;
      this.uid=options.uid || 0
		},
		/**
		 * 页面上拉触底事件的处理函数
		 */
		onReachBottom: function() {
			this.getUserBillList();
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			/**
			 * 授权回调
			 */
			onLoadFun: function() {
				this.getUserBillList();
				this.isShowAuth = false;
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			/**
			 * 获取账户明细
			 */
			getUserBillList: function() {
				let that = this;
				let page = that.page;
				let limit = that.limit;
				if (that.loading) return;
				if (that.loadend) return;
				that.loading = true;
				that.loadTitle = '';
				moneyList({
          uid:this.uid,
					page: page,
					limit: limit
				},that.type).then(res => {
					for (let i = 0; i < res.data.time.length; i++) {

						if (!this.times.includes(res.data.time[i])) {
							this.times.push(res.data.time[i])
							this.userBillList.push({
								time: res.data.time[i],
								child: []
							})
						}
					}

					for (let x = 0; x < this.times.length; x++) {
						for (let j = 0; j < res.data.list.length; j++) {
							if (this.times[x] === res.data.list[j].time_key) {
								this.userBillList[x].child.push(res.data.list[j])
							}
						}
					}
					let loadend = res.data.list.length < that.limit;
					that.loadend = loadend;
					that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					that.page += 1;
					that.loading = false;
				}).catch(err=>{
					that.loading = false;
					that.loadTitle = '加载更多';
				})
			},
			/**
			 * 切换导航
			 */
			changeType: function(type) {
				this.type = type;
				this.loadend = false;
				this.page = 1;
				this.times = [];
				this.$set(this, 'userBillList', []);
				this.getUserBillList();
			},
      onhomehide() {
				this.homeHide = false;
			}
		}
	}
</script>

<style scoped lang='scss'>
	.sign-record .list .item .data{
		color: #999;
	}
	.sign-record .list .item .listn{
		width: 710rpx;
		margin: 0 auto;
		border-radius: 24rpx;
	}
	.sign-record .list .item .listn .itemn{
		padding: 32rpx 0;
		margin: 0 24rpx;
		height: unset;

	}
	.sign-record .list .item .listn .itemn:nth-last-child(1){
		border-bottom: 0;
	}
	.sign-record .list .item .listn .itemn .name{
		color: #333;
		width: unset;
	}
	.sign-record .list .item .listn .itemn .num{
		color: #333333;
		font-family: 'Regular';
		min-width: 124rpx;
		text-align: right;
	}
	.bill-details .nav {
		background-color: #fff;
		height: 80rpx;
		width: 100%;
		line-height: 80rpx;
	}

	.bill-details .nav .item {
		flex: 1;
		text-align: center;
		font-size: 28rpx;
		color: #333;
	}

	.bill-details .nav .item.on {
		color: var(--view-theme);
		font-size: 30rpx;
		position: relative;
	}
	.bill-details .nav .item.on::after{
		position: absolute;
		width: 64rpx;
		height: 6rpx;
		border-radius: 20px;
		content: ' ';
		background: var(--view-theme);
		bottom: 0;
		left:50%;
		margin-left: -32rpx;
	}
</style>
