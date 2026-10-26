<template>
	<view :style="colorStyle">
		<view class='commission-details'>
			<timeSlot @changeTime="changeTime"></timeSlot>
			<view class='sign-record'>
				<view class="top_num" v-if="recordList.length">
					未提现：¥{{price}} &nbsp;&nbsp;&nbsp; 已提现：¥{{priced}}
				</view>
				<view class="box">
					<block v-for="(item,index) in recordList" :key="index" v-if="recordList.length>0">
						<view class='list'>
							<view class='item'>
								<view class='listn'>
									<view class='itemn1 acea-row row-between'>
										<view>
											<view class='name line1'>{{item.title}}</view>
											<view class="mark" v-if="item.status == -1">原因：{{item.fail_msg}}</view>
											<view>{{item.add_time}}</view>
										</view>
										<view>
											<view class='num'>-{{item.extract_price}}</view>
											<view class="wait text-w111-FF7D00" v-if="item.status == 0">待审核</view>
											<view class="wait text--w111-e93323" v-if="item.status == -1">审核未通过</view>
											<view class="wait text-w111-FFB200" v-if="item.extract_type == 'weixin' && item.status == 1">待收款</view>
											<view class="wait text-w111-00B42A" v-if="(item.extract_type != 'weixin' && item.status == 1) || (item.extract_type == 'weixin' && item.status == 2)">已提现</view>
											<!-- #ifdef MP-WEIXIN -->
											<view class="w-144 h-56 rd-50rpx acea-row row-center-wrapper bg-color text--w111-fff fs-24 mt-16" v-if="item.wechat_state == 1" @tap="jumpPath('/pages/users/user_withdrawal/receiving?type=1&id=' + item.order_id)">立即收款</view>
											<!-- #endif -->
											<!-- #ifdef H5 -->
											<view class="w-144 h-56 rd-50rpx acea-row row-center-wrapper bg-color text--w111-fff fs-24 mt-16" v-if="item.wechat_state == 1 && $wechat.isWeixin()" @tap="jumpPath('/pages/users/user_withdrawal/receiving?type=1&id=' + item.order_id)">立即收款</view>
											<!-- #endif -->
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
		getExtractList
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
				page: 1,
				limit: 15,
				loading: false,
				loadend: false,
				loadTitle: '加载更多',
				recordList: [],
				start: 0,
				stop: 0,
				price: 0, //待提现
				priced: 0, //已提现
                homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad(options) {
			if (!this.isLogin) {
				toLogin();
			}
		},
		onShow: function() {
			uni.removeStorageSync('form_type_cart');
			this.page = 1;
			this.start = 0;
			this.stop = 0;
			this.loadend = false;
			this.recordList = [];
			this.getRecordList();
		},
        onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			jumpPath(url){
				uni.navigateTo({
					url
				})
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
				if (that.loading) return;
				if (that.loadend) return;
				that.loading = true;
				that.loadTitle = '';
				getExtractList({
					start: this.start,
					stop: this.stop,
					page: page,
					limit: limit
				}).then(res => {
					this.price = res.data.extract_statistics.price || 0;
					this.priced = res.data.extract_statistics.priced || 0;
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
	.sign-record .list .item .listn .itemn1 .wait{
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