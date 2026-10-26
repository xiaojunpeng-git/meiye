<template>
	<view :style="colorStyle">
		<view class='commission-details'>
			<view class='search acea-row row-between-wrapper' v-if="recordType != 1 && recordType != 4">
				<view class='input'>
					<text class="iconfont icon-ic_search"></text>
					<input placeholder='搜索用户名称' placeholder-class='placeholder' v-model="keyword" @confirm="submitForm"
						confirm-type='search' name="search"></input>
				</view>
			</view>
			<timeSlot @changeTime="changeTime"></timeSlot>
			<view class='sign-record'>
				<view class="top_num" v-if="recordType != 4 && recordList.length">
					支出：¥{{expend || 0}} &nbsp;&nbsp;&nbsp; 收入：¥{{income || 0}}
				</view>
				<view class="box">
					<block v-for="(item,index) in recordList" :key="index" v-if="recordList.length>0">
						<view class='list'>
							<view class='item'>
								<view class='listn'>
									<view class='itemn1 acea-row row-between-wrapper'>
										<view>
											<view class='name line1'>
												{{item.title}}
												<text class="status_badge default" v-if="recordType == 4 && item.status == 0">待审核</text>
												<text class="status_badge error" v-if="recordType == 4 && item.status == 2">审核未通过</text>
												<!-- 提现记录： 0 待审核 1 通过 2 未通过 -->
											 </view>
											<view class="mark" v-if="item.extract_status == -1">原因：{{item.extract_msg}}</view>
											<view>{{item.add_time}}</view>
										</view>
										<view>
											<view class='num' :class="recordType == 4 && item.status == 0?'on':''" v-if="item.pm == 1">+{{item.number}}</view>
											<view class='num' v-else>-{{item.number}}</view>
											<view class="fail" v-if="item.extract_status == -1">审核未通过</view>
											<view class="wait" v-if="item.extract_status == 0">待审核</view>
										</view>
									</view>
								</view>
							</view>
						</view>
					</block>
				</view>

				<view class='loadingicon acea-row row-center-wrapper' v-if="recordList.length">
					<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
				</view>
				<view class="empty" v-if="!recordList.length">
					<emptyPage title='暂无数据~' src="/statics/images/noOrder.gif"></emptyPage>
				</view>
			</view>
		</view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import {
		moneyList,
		getSpreadInfo
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import emptyPage from '@/components/emptyPage.vue'
	import home from '@/components/home';
	import colors from '@/mixins/color.js';
	import timeSlot from '@/components/timeSlot/index.vue'
	export default {
		components: {
			emptyPage,
			home,
			timeSlot
		},
		mixins: [colors],
		data() {
			return {
				name: '',
				keyword: '',
				type: 0,
				page: 1,
				limit: 15,
				loading: false,
				loadend: false,
				loadTitle: '加载更多',
				recordList: [],
				recordType: 0,
				recordCount: 0,
				extractCount: 0,
				times: [],
				start: 0,
				stop: 0,
				income: '',
				expend: '',
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad(options) {
			if (this.isLogin) {
				this.type = options.type;
			} else {
				toLogin();
			}
		},
		onShow: function() {
			uni.removeStorageSync('form_type_cart');
			let type = this.type;
			if (type == 1) {
				uni.setNavigationBarTitle({
					title: "佣金记录"
				});
				this.name = '提现总额';
				this.recordType = 3;
				this.getRecordList();
			} else if (type == 2) {
				uni.setNavigationBarTitle({
					title: "佣金记录"
				});
				this.name = '佣金明细';
				this.recordType = 3;
				this.getRecordList();
			} else if (type == 4) {
				uni.setNavigationBarTitle({
					title: "提现记录"
				});
				this.name = '提现明细';
				this.recordType = 4;
				this.getRecordList();
			} else {
				uni.showToast({
					title: '参数错误',
					icon: 'none',
					duration: 1000,
					mask: true,
					success: function(res) {
						setTimeout(function() {
							// #ifndef H5
							uni.navigateBack({
								delta: 1,
							});
							// #endif
							// #ifdef H5
							history.back();
							// #endif

						}, 1200)
					},
				});
			}
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			submitForm() {
				this.page = 1;
				this.limit = 20;
				this.loadend = false;
				this.status = false;
				this.$set(this, 'recordList', []);
				this.$set(this, 'times', []);
				this.getRecordList();
			},
			changeTime(time) {
				this.start = time.start
				this.stop = time.stop
				this.page = 1;
				this.loadend = false;
				this.$set(this, 'recordList', []);
				this.getRecordList();
			},
			getRecordList: function() {
				let that = this;
				let page = that.page;
				let limit = that.limit;
				let recordType = that.recordType;
				if (that.loading) return;
				if (that.loadend) return;
				that.loading = true;
				that.loadTitle = '';
				moneyList({
					keyword: this.keyword,
					start: this.start,
					stop: this.stop,
					page: page,
					limit: limit
				}, recordType).then(res => {
					this.expend = res.data.expend;
					this.income = res.data.income;
					this.recordList = this.recordList.concat(res.data.list)
					let loadend = res.data.list.length < that.limit;
					that.loadend = loadend;
					that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					that.page += 1;
					that.loading = false;
				}).catch(err => {
					that.loading = false;
					that.loadTitle = '加载更多';
				})
			},
			getRecordListCount: function() {
				let that = this;
				getSpreadInfo().then(res => {
					that.recordCount = res.data.commissionCount;
					that.extractCount = res.data.extractCount;
				});
			},
      onhomehide() {
				this.homeHide = false;
			}
		},
		onReachBottom: function() {
			this.getRecordList();
		}
	}
</script>

<style scoped lang="scss">
	.empty{
		margin: 0 20rpx 20rpx 20rpx;
	}
	.commission-details .search {
		width: 100%;
		background-color: #fff;
		padding: 24rpx 20rpx;
		box-sizing: border-box;
	}

	.commission-details .search .input {
		width: 100%;
		height: 72rpx;
		border-radius: 50rpx;
		background-color: #f5f5f5;
		position: relative;
	}

	.commission-details .search .input input {
		height: 100%;
		font-size: 26rpx;
		padding-left: 70rpx;
	}

	.box {
		border-radius: 24rpx;
		margin: 0 20rpx;
		overflow: hidden;
	}

	.commission-details .search .input .placeholder {
		color: #bbb;
	}

	.commission-details .search .input .iconfont {
		position: absolute;
		left: 28rpx;
		color: #999;
		font-size: 28rpx;
		top: 50%;
		transform: translateY(-50%);
	}

	.sign-record {
		margin-top: 20rpx;
	}

	.commission-details .promoterHeader .headerCon .money {
		font-size: 36rpx;
	}

	.top_num {
		padding: 10rpx 30rpx 30rpx 30rpx;
		font-size: 24rpx;
		color: #999;
	}

	.radius15 {
		border-radius: 14rpx 14rpx 0 0;
	}
	.sign-record .list .item .listn .itemn1{border-bottom:1rpx solid #eee;padding:22rpx 24rpx;}
	.sign-record .list .item .listn .itemn1 .name{width:390rpx;font-size:28rpx;color:#333;margin-bottom:12rpx;}
	.sign-record .list .item .listn .itemn1 .num{font-size:36rpx;color:#333333;font-family:'Regular';text-align: right;}
	.sign-record .list .item .listn .itemn1 .num.font-color{color:#e93323!important;}
	.sign-record .list .item .listn .itemn1 .fail{
		color: #E93323;
		margin-top: 14rpx;
		text-align: right;
	}
	.sign-record .list .item .listn .itemn1 .wait{
		color: #FF7D00;
		margin-top: 14rpx;
		text-align: right;
	}
	.mark{
		margin-bottom: 10rpx;
	}
	.status_badge{
		display: inline-block;
		height: 40rpx;
		border-radius: 8rpx;
		font-size: 24rpx;
		line-height: 40rpx;
		font-family: PingFangSC-Regular, PingFang SC;
		font-weight: 400;
		margin-left:16rpx;
		padding:0 12rpx 0;
	}
	.success{
		background: rgba(24, 144, 255, .1);
		color: #1890FF;
	}
	.default{
		background: #FFF1E5;
		color: #FF7D00;
	}
	.error{
		background: #FDEBEB;
		color: #F53F3F;
	}
</style>
