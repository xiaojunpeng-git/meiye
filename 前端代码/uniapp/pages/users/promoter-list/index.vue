<template>
	<view :style="colorStyle">
		<view class="promoter-list">
			<view class="header">
				<view class='search acea-row row-middle'>
					<text class="iconfont icon-ic_search"></text>
					<input placeholder='搜索用户名称' placeholder-class='placeholder' v-model="keyword" @confirm="submitForm" confirm-type='search' name="search"></input>
				</view>
				<view class='nav acea-row row-around' v-if="brokerageLevel == 2">
					<view :class="grade == 0 ? 'item on' : 'item'" @click='setType(0)'>一级({{total}})</view>
					<view :class="grade == 1 ? 'item on' : 'item'" @click='setType(1)'>二级({{totalLevel}})</view>
				</view>
				<timeSlot @changeTime="changeTime"></timeSlot>
			</view>
			<view class='list' v-if="recordList.length">
				<view class="top_num">
					共 <text class="main_color">{{count}}</text> 位推广人，获得佣金<text class="main_color">¥{{price}}</text>
				</view>
				<view class="itemCon">
					<view class='item acea-row row-between-wrapper' v-for="(item,index) in recordList" :key="index">
						<view class="picTxt acea-row row-between-wrapper">
							<view class='pictrue'>
								<image :src='item.avatar'></image>
							</view>
							<view class='text'>
								<view class='name line1'>{{item.nickname}}</view>
								<view>加入时间：{{item.time}}</view>
							</view>
						</view>
						<view class="right">
							<view><text class='num font-num'>{{item.childCount ? item.childCount : 0}}</text>人</view>
							<view><text class="num">{{item.orderCount ? item.orderCount : 0}}</text>单</view>
							<view><text class="num">{{item.numberCount ? item.numberCount : 0}}</text>元</view>
						</view>
					</view>
				</view>
			</view>
			<view class="empty" v-if="recordList.length == 0">
				<emptyPage title="暂无数据～"></emptyPage>
			</view>
		</view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	import {
		spreadPeople
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import emptyPage from '@/components/emptyPage.vue'
	import home from '@/components/home';
	import timeSlot from '@/components/timeSlot/index.vue'
	import colors from '@/mixins/color.js';
	export default {
		components: {
			home,
			timeSlot,
			emptyPage
		},
		mixins: [colors],
		data() {
			return {
				total: 0,
				totalLevel: 0,
				teamCount: 0,
				page: 1,
				limit: 20,
				keyword: '',
				sort: '',
				grade: 0,
				status: false,
				recordList: [],
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				start: 0,
				stop: 0,
				count: 0,
				price: 0,
				brokerageLevel: 0,
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad() {
			if (this.isLogin) {
				this.userSpreadNewList();
			} else {
				toLogin()
			}
		},
		onShow: function() {
			uni.removeStorageSync('form_type_cart');
			if (this.is_show) this.userSpreadNewList();
		},
		onHide: function() {
			this.is_show = true;
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			changeTime(time) {
				console.log(time)
				this.start = time.start
				this.stop = time.stop
				this.submitForm()
			},
			onLoadFun(e) {
				this.userSpreadNewList();
				this.isShowAuth = false;
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			setSort(sort) {
				let that = this;
				that.sort = sort;
				that.page = 1;
				that.limit = 20;
				that.status = false;
				that.$set(that, 'recordList', []);
				that.userSpreadNewList();
			},
			submitForm: function() {
				this.page = 1;
				this.limit = 20;
				this.status = false;
				this.$set(this, 'recordList', []);
				this.userSpreadNewList();
			},

			setType: function(grade) {
				if (this.grade != grade) {
					this.grade = grade;
					this.page = 1;
					this.limit = 20;
					this.keyword = '';
					this.sort = '';
					this.status = false;
					this.$set(this, 'recordList', []);
					this.userSpreadNewList();
				}
			},
			userSpreadNewList: function() {
				let that = this;
				let page = that.page;
				let limit = that.limit;
				let status = that.status;
				let keyword = that.keyword;
				let sort = that.sort;
				let grade = that.grade;
				let recordList = that.recordList;
				let recordListNew = [];
				if (status == true) return;
				spreadPeople({
					start: this.start,
					stop: this.stop,
					page: page,
					limit: limit,
					keyword: keyword,
					grade: grade,
					sort: sort,
				}).then(res => {
					let len = res.data.list.length;
					let recordListData = res.data.list;
					recordListNew = recordList.concat(recordListData);
					that.total = res.data.total;
					that.totalLevel = res.data.totalLevel;
					that.teamCount = res.data.count;
					that.brokerageLevel = res.data.brokerage_level;
					that.status = limit > len;
					that.page = page + 1;
					that.$set(that, 'recordList', recordListNew);
					that.count = res.data.count;
					that.price = res.data.price;
				});
			}
		},
		onReachBottom: function() {
			this.userSpreadNewList();
		}
	}
</script>

<style scoped lang="scss">
	.empty{
		margin: 20rpx;
	}
	.promoter-list .nav {
		background-color: #fff;
		height: 92rpx;
		line-height: 86rpx;
		font-size: 28rpx;
		color: #666;
		font-family: PingFang SC;
	}

	.promoter-list .nav .item.on {
		color: var(--view-theme);
		position: relative;
		font-size: 32rpx;

		&::after {
			position: absolute;
			content: '';
			width: 38rpx;
			height: 30rpx;
			border: 2px solid var(--view-theme);
			border-left: 2px solid transparent !important;
			border-top: 2px solid transparent !important;
			border-right: 2px solid transparent !important;
			border-radius: 50%;
			left: 50%;
			margin-left: -24rpx;
			bottom: 16rpx;
		}
	}

	.promoter-list .header {
		background-color: #fff;
		padding-top: 24rpx;
	}

	.promoter-list .search {
		width: 710rpx;
		height: 72rpx;
		padding: 0 32rpx;
		box-sizing: border-box;
		background-color: #F5F5F5;
		border-radius: 50rpx;
		margin: 0 auto 10rpx auto;

		.placeholder {
			color: #ccc;
			font-size: 28rpx;
		}
	}

	.promoter-list .search .iconfont {
		font-size: 32rpx;
		color: #999;
		margin-right: 18rpx;
	}

	.promoter-list .list {
		margin-top: 12rpx;
	}

	.promoter-list .list .sortNav {
		background-color: #fff;
		height: 76rpx;
		border-bottom: 1rpx solid #eee;
		color: #333;
		font-size: 28rpx;
	}

	.promoter-list .list .sortNav .sortItem {
		text-align: center;
		flex: 1;
	}

	.promoter-list .list .sortNav .sortItem image {
		width: 24rpx;
		height: 24rpx;
		margin-left: 6rpx;
		vertical-align: -3rpx;
	}

	.promoter-list .list .itemCon {
		background-color: #fff;
		margin: 0 20rpx;
		border-radius: 24rpx;
	}

	.promoter-list .list .item {
		height: 176rpx;
		font-size: 24rpx;
		color: #666;
		margin: 0 24rpx;
		border-bottom: 1px solid #EEEEEE;
	}

	.promoter-list .list .item .picTxt {
		width: 440rpx;
	}

	.promoter-list .list .item .picTxt .pictrue {
		width: 112rpx;
		height: 112rpx;
		border-radius: 50%;
	}

	.promoter-list .list .item .picTxt .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 50%;
	}

	.promoter-list .list .item .picTxt .text {
		width: 304rpx;
		font-size: 24rpx;
		color: #999;
	}

	.promoter-list .list .item .picTxt .text .name {
		font-size: 28rpx;
		color: #333;
		margin-bottom: 13rpx;
	}

	.promoter-list .list .item .right {
		width: 190rpx;
		text-align: right;
		font-size: 24rpx;
		color: #333;
	}

	.promoter-list .list .item .right .num {
		margin-right: 7rpx;
	}

	.top_num {
		padding: 14rpx 20rpx 28rpx 20rpx;
		font-size: 24rpx;
		color: #666;
	}

	.main_color {
		color: #E93323;
		margin: 0 6rpx;
	}
</style>