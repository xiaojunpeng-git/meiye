<template>
	<view class="evaluateWtapper" :style="colorStyle">
		<!-- 商品详情 -->
		<view class="pt-24 pb-24 pl-32 pr-32 flex-between-center" v-if="replyCon.product"
			@click="details(replyCon.product)">
			<image class="w-104 h-104 rd-16rpx" :src="replyCon.product.image"></image>
			<view class="ml-24 w-462 line2 text--w111-333 fs-28 lh-40rpx">{{replyCon.product.store_name}}</view>
			<view class="flex-center w-56 h-56 rd-28rpx cart_border ml-40">
				<text class="iconfont icon-ic_ShoppingCart1 text-primary-con"></text>
			</view>
		</view>
		<!-- 评价内容卡片 -->
		<view class="bg--w111-fff rd-24rpx pt-32 pb-32 pl-56 pr-56 mt-24" v-if="replyCon.reply">
			<view class="flex-between-center">
				<view class="flex-y-center relative">
					<image :src="replyCon.reply.avatar" class="w-56 h-56 rd-28rpx" :class='replyCon.user.is_money_level?"border-F1BB0D":"border-EEEEEE"'></image>
					<image src="../../../static/images/king.png" class="w-18 h-18 absolute top-f10 left-36" v-if="replyCon.user.is_money_level"></image>
					<text class="fs-28 text--w111-333 pl-12">{{replyCon.reply.nickname}}</text>
					<view v-if="replyCon.user.level_name" class="flex-center min-w-52 pl-12 pr-12 h-26 bg-w111-FEF0D9 border-facc7d borde rd-50rpx fs-18 fw-500 text-w111-DFA541 ml-10">
					    <text class="iconfont icon-huiyuandengji fs-20 mr-4"></text>
					    {{replyCon.user.level_name}}
					</view>
				</view>
				<view class="fs-26 text--w111-999">{{replyCon.reply.add_time}}</view>
			</view>
			<view class="mt-32">
				<view class="flex-y-center">
					<view class="flex pr-40">
						<text class="iconfont icon-ic_star1 fs-18 text-primary-con"
							v-for="item in Number(replyCon.star)" :key="item"></text>
					</view>
					<text class="fs-22 text--w111-999 pro_name relative" v-if="replyCon.reply.suk">{{replyCon.reply.suk }}</text>
				</view>
			</view>
			<view class="mt-24 fs-28 text--w111-333 lh-44rpx">{{replyCon.reply.comment}}</view>
			<image :src="pic" v-for="(pic,i) in replyCon.reply.pics" :key="i" class="w-full mt-20 rd-16rpx"
				mode="widthFix"></image>
			<view class="mt-24">
				<text class="fs-22 text--w111-999">浏览{{replyCon.reply.views_num}}次</text>
			</view>
		</view>
		<view class="bg--w111-fff rd-24rpx pt-32 pb-32 pl-56 pr-56 mt-24" v-if="replyList.length">
			<view class="text--w111-333 fs-28 fw-500">{{replyNum}}条回复</view>
			<view class="reply_box pb-24" v-for="(item,index) in replyList" :key="index">
				<view class="flex justify-between items-baseline">
					<view class="flex-y-center mt-32 relative">
						<image class="w-64 h-64 rd-50rpx" :class='item.user.is_money_level?"border-F1BB0D":"border-EEEEEE"' :src="item.user.avatar" v-if="item.uid && item.user"></image>
						<image src="@/static/images/king.png" class="w-18 h-18 absolute top-f10 left-36" v-if="item.uid && item.user && item.user.is_money_level"></image>
						<image class="w-64 h-64 rd-50rpx" src="../static/store.png" v-if="!item.uid"></image>
						<image class="w-64 h-64 rd-50rpx" src="@/static/images/f.png" v-if="!item.user"></image>
						<view class="flex-col pl-16">
							<view class="flex-y-center">
								<text class="text--w111-333 fs-24"
									:class="!item.uid ? 'text_score':''">{{item.user?item.user.nickname:'用户'}}</text>
								<view class="store_badge flex-center fs-18" v-if="!item.uid">商家</view>
								<view v-if="item.user && item.user.level_name" class="flex-center min-w-52 pl-12 pr-12 h-26 bg-w111-FEF0D9 border-facc7d borde rd-50rpx fs-18 fw-500 text-w111-DFA541 ml-10">
								    <text class="iconfont icon-huiyuandengji fs-20 mr-4"></text>
								    {{item.user.level_name}}
								</view>
							</view>
							<view class="fs-26 text--w111-999">{{item.create_time}}</view>
						</view>
					</view>
					<view class="flex-y-center" :class="item.is_praise ? 'font-num':''">
						<text class="iconfont icon-ic_Like fs-32" @click="praise(item)"></text>
						<text class="fs-22 pl-8">{{item.praise}}</text>
					</view>
				</view>
				<view class="mt-24 fs-26 text--w111-333 lh-44rpx pb-24">{{item.content}}</view>
			</view>
		</view>
		<view class="h-120 pb-safe"></view>
		<view class="page_footer fixed-lb w-full bg--w111-fff pr-20 pb-safe">
			<view class="pl-32 pr-24 h-96 flex-between-center">
				<view class="w-498 h-64 rd-32rpx bg--w111-f5f5f5 pl-24 flex-y-center text--w111-999 px-20"
					@tap="showInput = true">
					<text class="iconfont icon-ic_edit"></text>
					<text class="fs-26 pl-16">说说你的看法吧～</text>
				</view>
				<view class="flex-y-center pl-28">
					<view :class="replyCon.is_praise ? 'text-primary-con':''">
						<text class="iconfont icon-ic_Like" 
							@click="tapPraise"></text>
						<text class="fs-22 pl-8">{{replyCon.reply?replyCon.reply.praise:0}}</text>
					</view>
					<view class="pl-32">
						<text class="iconfont icon-ic_message"></text>
						<text class="fs-22 pl-8">{{replyNum}}</text>
					</view>
				</view>
			</view>
		</view>
		<tui-drawer mode="bottom" :visible="showInput" background-color="transparent" mask maskClosable @close="closeDrawer">
			<view class="w-full bg--w111-fff drawer-text pt-32 pl-32 pr-32">
				<view class="bg--w111-f5f5f5 rd-32rpx pt-16 pr-14 pb-16 pl-24 relative">
					<textarea class="w-528 fs-28 h-auto"
						:auto-height="true"
						wrap-style="wrap"
						max-height="100px"
						placeholder-class='placeholder'
						placeholder="说说你的看法吧"
						:always-embed="true"
						:adjust-position="true"
						cursor-spacing="85rpx"
						v-model="con"
						:maxlength="150"
						name="mark">
					</textarea>
					<view class="reply-confirm-btn w-96 h-48 rd-24rpx flex-center bg-color text--w111-fff fs-24 ml-24"
						@tap="sendText">发送</view>
				</view>
			</view>
		</tui-drawer>
		<home :isHide="homeHide"></home>
	</view>
</template>
<script>
	import {
		getReplyInfo,
		getReplyComment,
		postReplyPraise,
		replyComment,
		postUnReplyPraise,
		getReplyPraise,
		getUnReplyPraise
	} from '@/api/store.js';
	import tuiDrawer from "@/components/tui-drawer/tui-drawer.vue"
	import colors from '@/mixins/color.js';
	import home from '@/components/home/index.vue';
	export default {
		components: {
			tuiDrawer,
			home
		},
		mixins: [colors],
		data: function() {
			return {
				id: 0,
				page: 1,
				limit: 200,
				replyCon: {},
				replyList: [],
				con: '',
				scrollTop: 0,
				replyNum: 0,
				showInput:false,
				homeHide: false
			};
		},
		onLoad(options) {
			this.id = options.id
			this.getInfo();
			this.getList();
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			tapPraise() {
				if (this.replyCon.is_praise) {
					getUnReplyPraise(this.id).then(res => {
						this.replyCon.is_praise = !this.replyCon.is_praise
						this.replyCon.reply.praise = this.replyCon.reply.praise - 1
						return this.$util.Tips({
							title: res.msg
						});
					});
				} else {
					getReplyPraise(this.id).then(res => {
						this.replyCon.is_praise = !this.replyCon.is_praise
						this.replyCon.reply.praise = this.replyCon.reply.praise + 1
						return this.$util.Tips({
							title: res.msg
						});
					});
				}
			},
			// 设置页面滚动位置
			setPageScrollTo() {
				let view = uni
					.createSelectorQuery()
					.in(this)
					.select('#tops');
				view.boundingClientRect(res => {
					this.scrollTop = parseFloat(res.height);
				}).exec();
			},
			sendText() {
				if (!this.con.trim()) {
					return this.$util.Tips({
						title: '说点什么呗'
					});
				}
				replyComment(this.id, {
					content: this.con
				}).then(res => {
					let that = this;
					this.con = '';
					that.showInput = false;
					this.replyNum = this.replyNum + 1;
					this.getList();
					this.$util.Tips({
						title: res.msg
					});
				})
			},
			details(info) {
				if(info.is_presale_product){
					uni.navigateTo({
						url: `/pages/activity/goods_details/index?id=${info.id}&type=6`
					})
				}else{
					uni.navigateTo({
						url: `/pages/goods_details/index?id=${info.id}`
					})
				}
			},
			getInfo() {
				getReplyInfo(this.id).then(res => {
					this.replyCon = res.data;
					this.replyNum = this.replyCon.reply.comment_sum;
				})
			},
			getList() {
				getReplyComment(this.id, {
					page: this.page,
					limit: this.limit
				}).then(res => {
					this.replyList = res.data
				}).catch(err => {
					return this.$util.Tips({
						title: err.msg
					});
				})
			},
			getpreviewImage: function(index) {
				uni.previewImage({
					urls: this.replyCon.reply.pics,
					current: this.replyCon.reply.pics[index]
				});
			},
			praise(item) {
				if (item.is_praise) {
					postUnReplyPraise(item.id).then(res => {
						item.is_praise = !item.is_praise
						item.praise = item.praise - 1
						return this.$util.Tips({
							title: res.msg
						});
					});
				} else {
					postReplyPraise(item.id).then(res => {
						item.is_praise = !item.is_praise
						item.praise = item.praise + 1
						return this.$util.Tips({
							title: res.msg
						});
					});
				}
			},
			closeDrawer(){
				this.showInput = false;
			}
		}
	}
</script>
<style>
	.cart_border {
		border: 1px solid var(--view-theme);
	}

	.text-primary-con {
		color: var(--view-theme);
	}

	.text_score {
		color: #FAAD14;
	}

	.store_badge {
		width: 48rpx;
		height: 26rpx;
		background: #FAAD14;
		border-radius: 4rpx;
		color: #fff;
		margin-left: 8rpx;

	}
	.drawer-text{
		padding-bottom: calc(32rpx + env(safe-area-inset-bottom));
	}

	.pro_name::before {
		content: '';
		width: 1px;
		height: 18rpx;
		background-color: #ccc;
		position: absolute;
		left: -20rpx;
		top: 8rpx;
	}

	.reply_box~.reply_box {
		border-top: 1px solid #ddd;
	}
	.reply-confirm-btn{
		position: absolute;
		bottom: 10rpx;
		right: 14rpx;
	}
</style>
