<template>
	<view :style="colorStyle">
		<!-- 导航区 -->
		<view class="w-full fixed-lt bg--w111-fff z-10 rd-b-24rpx">
			<view class="pl-32 pr-32 pt-24 pb-32">
				<view class="flex-between-center">
					<view>
						<text class="text--w111-333 fs-30 fw-500">评价</text>
						<text class="fs-24 text--w111-666 pl-8">({{tabList[0].count}})</text>
					</view>
					<view class="flex-y-center">
						<text class="fs-28 text-primary-con Regular">{{replyData.reply_chance}}%</text>
						<text class="fs-24 text--w111-999 pl-4 pr-12">好评率</text>
					</view>
				</view>
				<view class="flex-y-center mt-34">
					<view
						class="tab_item inline-block h-56 rd-28rpx lh-56rpx text-center px-24 fs-24 bg--w111-f5f5f5 text--w111-333"
						v-for="(item, index) in tabList" :key="index"
						:class="index == type ? 'active' : ''"
						@click='changeType(index)'>{{ item.title }}({{ item.count }})</view>
				</view>
			</view>
		</view>
		<view class="h-186"></view>
		<!-- 评论卡片 -->
		<view v-if="reply.length">
			<view class="mt-20 rd-24rpx bg--w111-fff p-32"
				v-for="item in reply" :key="item.id" @click="goPage(item.id)">
				<view class="flex-between-center">
					<view class="flex-y-center relative">
						<image :src="item.avatar" class="w-56 h-56 rd-28rpx" :class='item.is_money_level?"border-F1BB0D":"border-EEEEEE"'></image>
						<image src="@/static/images/king.png" class="w-18 h-18 absolute top-f10 left-36" v-if="item.is_money_level"></image>
						<text class="fs-28 text--w111-333 pl-12">{{item.nickname}}</text>
						<view v-if="item.level_name" class="flex-center min-w-52 pl-12 pr-12 h-26 bg-w111-FEF0D9 border-facc7d borde rd-50rpx fs-18 fw-500 text-w111-DFA541 ml-10">
						    <text class="iconfont icon-huiyuandengji fs-20 mr-4"></text>
						    {{item.level_name}}
						</view>
					</view>
					<view class="fs-26 text--w111-999 Regular">{{item.add_time}}</view>
				</view>
				<view class="flex-between-center mt-32">
					<view class="flex-y-center">
						<view class="flex pr-20">
							<text class="iconfont icon-ic_star1 fs-18 text-primary-con" v-for="star in Number(item.star)"
								:key="star"></text>
						</view>
						<text class="w-400 line1" v-show="item.suk">
							<text class="text--w111-ccc fs-18">|</text>
							<text class="pl-20 fs-22 text--w111-999">{{item.suk}}</text>
						</text>
					</view>
					<image src="../static/hp_icon.png" class="w-78 h-30" mode="aspectFit" v-show="item.reply_score == 3"></image>
					<image src="../static/zp_icon.png" class="w-78 h-30" mode="aspectFit" v-show="item.reply_score == 2"></image>
					<image src="../static/cp_icon.png" class="w-78 h-30" mode="aspectFit" v-show="item.reply_score == 1"></image>
				</view>
				<view class="mt-20 fs-28 text--w111-333 lh-44rpx">{{item.comment}}</view>
				<!-- 1张照片 -->
				<image :src="item.pics[0]" class="w-400 h-400 rd-16rpx mt-20" mode="aspectFill"
					v-if="item.pics.length == 1"></image>
				<!-- 2张照片 -->
				<view class="mt-20 grid-column-2 grid-gap-x-10rpx grid-gap-y-20rpx" v-if="item.pics.length == 2">
					<image class="w-full h-338 rd-16rpx" :src="itemp" mode="aspectFill" v-for="(itemp,ip) in item.pics"
						:key="ip"></image>
				</view>
				<!-- 多于2张照片 -->
				<view class="mt-20 grid-column-3 grid-gap-10rpx" v-if="item.pics.length > 2">
					<image class="w-full h-222 rd-16rpx" :src="itemp" mode="aspectFill" v-for="(itemp,ip) in item.pics"
						:key="ip"></image>
				</view>
				<!-- 浏览次数 点赞 -->
				<view class="flex-between-center mt-24">
					<text class="fs-22 text--w111-999">浏览{{item.views_num}}次</text>
					<view class="flex text--w111-333">
						<view class="flex-y-center"  :class="item.is_praise ? 'text-primary-con':''">
							<text class="iconfont icon-ic_Like fs-32"
								@tap.stop="tapPraise(item)"></text>
							<text class="fs-22 pl-8">{{item.praise}}</text>
						</view>
						<view class="ml-32 flex-y-center">
							<text class="iconfont icon-ic_message fs-32"></text>
							<text class="fs-22 pl-8">{{item.replyComment ? item.replyComment.sum : 0}}</text>
						</view>
					</view>
				</view>
			</view>
		</view>
		<view class="px-20 mt-20" v-else>
			<emptyPage title="暂无评论，去看点别的吧～" src="/statics/images/noMessage.gif"></emptyPage>
		</view>
	</view>
</template>

<script>
	import {
		getReplyList,
		getReplyConfig,
		getReplyPraise,
		getUnReplyPraise
	} from '@/api/store.js';
	import colors from '@/mixins/color.js';
	import {toLogin} from '@/libs/login.js';
	import {mapGetters} from "vuex";
	import {HTTP_REQUEST_URL} from '@/config/app';
	import emptyPage from '@/components/emptyPage.vue';
	export default {
		mixins: [colors],
		computed: mapGetters(['isLogin']),
		data() {
			return {
				tabList: [{
						title: '全部',
						count: ''
					},
					{
						title: '好评',
						count: ''
					},
					{
						title: '中评',
						count: ''
					},
					{
						title: '差评',
						count: ''
					},
				],
				replyData: {},
				product_id: 0,
				reply: [],
				type: 0,
				loading: false,
				loadend: false,
				loadTitle: '加载更多',
				page: 1,
				limit: 20,
				imgHost: HTTP_REQUEST_URL,
				isShowAuth: false,
			};
		},
		components:{ emptyPage },
		/**
		 * 生命周期函数--监听页面加载
		 */
		onLoad: function(options) {
			let that = this;
			if (!options.product_id) return that.$util.Tips({
				title: '缺少参数'
			}, {
				tab: 3,
				url: 1
			});
			that.product_id = options.product_id;
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			this.getProductReplyCount();
			this.loadend = false;
			this.page = 1;
			this.reply = [];
			this.getProductReplyList();
		},
		methods: {
			replyFun(e) {
				this.reply = e;
			},
			changeLogin() {
				toLogin()
			},
			onLoadFun() {
				this.isShowAuth = false
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			/**
			 * 获取评论统计数据
			 *
			 */
			getProductReplyCount: function() {
				let that = this;
				getReplyConfig(that.product_id).then(res => {
					that.$set(that, 'replyData', res.data);
					this.tabList[0].count = res.data.sum_count;
					this.tabList[1].count = res.data.good_count;
					this.tabList[2].count = res.data.in_count;
					this.tabList[3].count = res.data.poor_count;
				});
			},
			/**
			 * 分页获取评论
			 */
			getProductReplyList: function() {
				let that = this;
				if (that.loadend) return;
				if (that.loading) return;
				that.loading = true;
				that.loadTitle = '';
				getReplyList(that.product_id, {
					page: that.page,
					limit: that.limit,
					type: that.type,
				}).then(res => {
					let list = res.data,
						loadend = list.length < that.limit;
					that.reply = that.$util.SplitArray(list, that.reply);
					that.$set(that, 'reply', that.reply);
					that.loading = false;
					that.loadend = loadend;
					that.loadTitle = loadend ? "没有更多内容啦~" : "加载更多";
					that.page = that.page + 1;
				}).catch(err => {
					that.loading = false,
						that.loadTitle = '加载更多'
				});
			},
			/*
			 * 点击事件切换
			 * */
			changeType: function(e) {
				let type = parseInt(e);
				if (type == this.type) return;
				this.type = type;
				this.page = 1;
				this.loadend = false;
				this.$set(this, 'reply', []);
				this.getProductReplyList();
			},
			goPage(id) {
				uni.navigateTo({
					url: '/pages/goods/goods_comment_con/comment_con?id=' + id
				})
			},
			tapPraise(item) {
				if (item.is_praise) {
					getUnReplyPraise(item.id).then(res => {
						item.is_praise = !item.is_praise;
						item.praise = item.praise - 1;
						return this.$util.Tips({
							title: res.msg
						});
					});
				} else {
					getReplyPraise(item.id).then(res => {
						item.is_praise = !item.is_praise;
						item.praise = item.praise + 1;
						return this.$util.Tips({
							title: res.msg
						});
					});
				}
			},
		},
		/**
		 * 页面上拉触底事件的处理函数
		 */
		onReachBottom: function() {
			this.getProductReplyList();
		},
	}
</script>

<style lang="scss">
	.emptyBox {
		text-align: center;
		margin-top: 40rpx;

		.tips {
			color: #aaa;
			font-size: 26rpx;
		}

		image {
			width: 414rpx;
			height: 336rpx;
		}
	}

	.active {
		background: var(--view-minorColorT);
		border: 1rpx solid var(--view-theme);
		color: var(--view-theme);
	}

	.text-primary-con {
		color: var(--view-theme);
	}

	.tab_item~.tab_item {
		margin-left: 16rpx;
	}
</style>
