<template>
	<view>
		<view class="fixed-lt pl-20 z-4000 h-80 flex-y-center" :style="{top: sysHeight + 'px'}" v-if="fullVideo" @tap="backPage">
			<text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff"></text>
		</view>
		<view>
			<swiper class="video-swiper" vertical :duration="300" :current="swiperCurrent" @change="videoChange">
				<swiper-item v-for="(item, index) in swiperData" :key="item.id">
					<view class="swiper-video-item h-full">
						<view class="h-full relative" v-if="item.content_type == 2">
							<video :id="`video${index}`" :autoplay="index === swiperCurrent" loop :controls="false" :show-fullscreen-btn="false" :show-loading="false" :show-center-play-btn="false" :enable-progress-gesture="false" :src="item.video_url" @ended="ended" @click="tapVideoHover($event)" class="w-full h-full"></video>
							<!-- #ifdef H5 -->
							<view class="abs-center w-full h-full flex-center" @tap="playVideo" v-if="!isPlay && Auth.isWeixin()">
								<image src="@/static/images/stop.png" mode="" class="w-160 h-160"></image>
							</view>
							<!-- #endif -->
						</view>
						<view class="flex-y-center h-full" v-else>
							<swiper class="w-full h-full" circular :autoplay="index === swiperCurrent" :current="imgCurrent" :interval="4000" @change="photoChange">
								<swiper-item v-for="(photo, i) in item.slider_image" :key="i">
									<view class="h-full flex-y-center">
										<image :src="photo" mode="widthFix" class="w-full"></image>
									</view>
								</swiper-item>
							</swiper>
						</view>
					</view>
				</swiper-item>
			</swiper>
		</view>
		<view class="right-action text--w111-fff flex-col flex-y-center z-800" :class="{'action-box':showFooter,'full-action': fullVideo}">
			<view class="relative">
				<view class="w-88 h-88 rd-50-p111- avatar-box" v-if="currentData && currentData.author_image">
					<image :src="currentData.author_image" class="w-full h-full rd-50-p111-" @tap="toUser"></image>
				</view>
				<view class="w-88 h-88 rd-50-p111- avatar-box" v-else></view>
				<view class="follow-icon flex-center" v-if="!currentData.is_follow && !currentData.is_self" @tap="followFun">
					<text class="iconfont icon-ic_increase fs-20 text--w111-fff"></text>
				</view>
			</view>
			<!-- 2.点赞 -->
			<view class="mt-50 flex-col flex-y-center" @tap="contentLike">
				<text class="iconfont fs-72 icon-ic_love_2 shadow" :class="{'like-heart': currentData.is_like}"></text>
				<text class="fs-22 pt-10 text-center shadow">{{currentData.like_num > 0 ? currentData.like_num : '点赞'}}</text>
			</view>
			<!-- 3.评论 -->
			<view class="mt-36 flex-col flex-y-center" @tap="toggleReplyDrawer" v-if="replyStatus">
				<text class="iconfont icon-icon_comment fs-64 shadow"></text>
				<text class="fs-22 pt-16 text-center shadow">{{currentData.comment_num > 0 ? currentData.comment_num : '评论'}}</text>
			</view>
			<!-- 3.分享 -->
			<!-- #ifdef H5 || APP-PLUS -->
			<view class="mt-36 flex-col flex-y-center" @tap="openShare">
				<text class="iconfont icon-icon_transmit fs-66 shadow"></text>
				<text class="fs-22 pt-10 shadow">分享</text>
			</view>
			<!-- #endif -->
			<!-- #ifdef MP-WEIXIN -->
			<button class="mt-36 flex-col flex-y-center bg-transparent text--w111-fff" open-type="share" hover-class="none" @tap="openShare">
				<text class="iconfont icon-icon_transmit fs-66 shadow"></text>
				<text class="fs-22 pt-10 shadow">分享</text>
			</button>
			<!-- #endif -->
			<view class="mt-36 flex-col flex-y-center relative" v-if="showMore">
				<text class="iconfont icon-ic_more fs-60 shadow" @tap="()=>{showBubble = !showBubble}"></text>
				<view class="bubble_box">
					<view class="bubble text--w111-333 flex-col justify-between" v-if="showBubble">
						<view class="flex-y-center pl-16" @tap="editTo">
							<text class="iconfont icon-ic_edit"></text>
							<text class="fs-26 pl-18">编辑</text>
						</view>
						<view class="x-line"></view>
						<view class="flex-y-center pl-16" @tap="delContent">
							<text class="iconfont icon-ic_delete"></text>
							<text class="fs-26 pl-18">删除</text>
						</view>
					</view>
				</view>
			</view>
			<view class="mt-36 flex-col flex-y-center" v-if="currentData && currentData.product && currentData.product.length" @tap="getProList">
				<image src="../../static/discover_cart.png" class="w-66 h-66"></image>
			</view>
			<view v-if="showMore && !currentData.product.length" class="h-24"></view>
			<view v-if="'/pages/discover/discoverIndex/index'.includes(route)" class="h-100"></view>
			<view v-if="'/pages/discover/discoverIndex/index'.includes(route)" class="pb-safe"></view>
		</view>
		<view class="fixed-desc w-full text--w111-fff z-80" :class="{'hide-tab':showFooter,'full-tab':fullVideo}">
			<navigator v-if="currentData.type == 1 && currentData.relation_id" :url="`/pages/store/home/index?id=${currentData.relation_id}`" hover-class="none" class="flex-y-center w-500 h-118 px-20 rd-10rpx mb-24 bg-w111-000-s111-40">
				<text class="iconfont icon-ic_location1 fs-40"></text>
				<view class="flex-1 min-w-0 pl-16">
					<view class="fw-500 fs-28 line1">{{currentData.author}}</view>
					<view class="mt-4 fs-24 line1">{{currentData.address}}</view>
				</view>
			</navigator>
			<view class="box">
				<view class="w-560 lh-42rpx fs-28 fw-500">@{{currentData.author || '用户已注销'}}</view>
				<view class="w-560 content-box lh-42rpx fs-28 mt-20">
					<BaseTextMore :content="currentData.title + currentData.content" fontColor="rgba(255,255,255,0.9)" actionFontColor="#fff" :font-size="28" :rows="2" expand-text="展开" collapse-text="收起"></BaseTextMore>
				</view>
				<view class="w-560 content-box lh-42rpx fs-28 mt-10 text--w111-fff">
					<text class="pr-10" v-for="(topic,i) in currentData.topic" :key="i" @tap="authTo('/pages/discover/discoverTopic/index?id=' + topic.id + '&name=' + topic.name)">
						#{{topic.name}}</text>
				</view>
				<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-584 mt-24" show-scrollbar="false" v-if="currentData && currentData.product && currentData.product.length">
					<view class="inline-block mr-30" v-for="(pro,k) in currentData.product" :key="k">
						<view class="w-444 bg--w111-fff rd-16rpx p-16 border-EEEEEE flex-between-center" @tap="goDetail(pro.id)">
							<image :src="pro.image" class="w-104 h-104 rd-8rpx"></image>
							<view class="flex-1 pl-14 min-w-0">
								<view class="w-282 line1 fs-26 pb-20 text--w111-333">{{pro.store_name}}</view>
								<view class="flex-between-center">
									<baseMoney :money="pro.price" symbolSize="22" integerSize="32" decimalSize="24" color="#333" weight></baseMoney>
									<view class="w-92 h-40 rd-20rpx bg-color flex-center text--w111-fff fs-22">购买</view>
								</view>
							</view>
						</view>
					</view>
				</scroll-view>
			</view>
			<view class="w-full flex-center mt-24 relative z-80" style="left: -20rpx;" v-if="imgList.length && imgList.length > 1">
				<block v-for="(_, index) in imgList" :key="index">
					<view class="dot_item h-6 rd-2rpx" :style="{ 'background-color': imgCurrent >= index ? '#fff' : 'rgba(255,255,255,0.4)', width: dotWidth + 'rpx' }"></view>
				</block>
			</view>
			<view v-else class="h-30"></view>
			<view v-if="'/pages/discover/discoverIndex/index'.includes(route)" class="h-100"></view>
			<view v-if="'/pages/discover/discoverIndex/index'.includes(route)" class="pb-safe"></view>
		</view>
		<base-drawer mode="bottom" :visible="showProDrawer" zIndex="999" background-color="transparent" mask maskClosable @close="closeDrawer">
			<view class="w-full bg--w111-fff text--w111-333 rd-t-40rpx relative">
				<view class="fs-30 pt-48 pb-44 pl-32">TA提到的宝贝</view>
				<view class="close-btn flex-center" @tap="closeDrawer()">
					<text class="iconfont icon-ic_close text--w111-666 fs-24"></text>
				</view>
				<view class="px-30">
					<scroll-view scroll-y="true" style="max-height: 722rpx;">
						<view class="flex-between-center pro-item mb-20" v-for="(item,index) in productList" :key="index" @tap="goDetail(item.id)">
							<view class="flex-1 flex">
								<image class="w-200 h-200 rd-16rpx" :src='item.image' mode="aspectFill"></image>
								<view class="h-200 flex-1 flex-col justify-between pl-30">
									<view>
										<view class="lh-34rpx fs-30 line2">{{item.store_name}}</view>
										<view class="w-full flex items-end flex-wrap mt-16">
											<BaseTag :text="label.label_name" :color="label.color" :background="label.bg_color" :borderColor="label.border_color" :circle="label.border_color ? true : false" :imgSrc="label.icon" v-for="(label, idx) in item.store_label" :key="idx"></BaseTag>
										</view>
									</view>
									<view class="flex-between-center">
										<view class="flex-y-center">
											<baseMoney :money="item.price" symbolSize="26" integerSize="40" decimalSize="26" weight></baseMoney>
											<view class="text--w111-999 fs-26 pl-12 text-line">{{item.ot_price}}</view>
										</view>
										<view class="w-136 h-52 rd-30rpx flex-center text--w111-fff fs-22 bg-color">立即购买</view>
									</view>
								</view>
							</view>
						</view>
						<view v-if="!productList.length">
							<emptyPage title="暂无商品，去看点别的吧～"></emptyPage>
						</view>
					</scroll-view>
				</view>
				<view v-if="'/pages/discover/discoverIndex/index'.includes(route)" class="h-140"></view>
				<view v-else class="h-40"></view>
				<view class="pb-safe"></view>
			</view>
		</base-drawer>
		<replyList :visible="showReply" :community_id="community_id" :comment_num="comment_num" :addReply="addReply" :showFooter="showFooter" @closeDrawer="closeDrawer" @commentAdd="commentAdd"></replyList>
		<tuiModal :show="showDelete" title="确认删除该内容" :maskClosable="false" @click="handleDelete"></tuiModal>
		<view class="notice-modal" :style="{'top': sysHeight + 52 + 'px'}" v-if="fullVideo">
			<view class="w-full h-full container text--w111-fff" v-if="currentData.is_verify == -2">
				<view class="flex-y-center fs-28 fw-500">
					<text class="iconfont icon-icon_clock1 fs-30"></text>
					<text class="pl-20">平台强制下架，内容仅自己可见</text>
				</view>
				<view class="fs-22 pt-14 pl-48">发布的内容涉及政治敏感词汇，请修改后重新发布！</view>
			</view>
			<view class="w-full h-full container text--w111-fff" v-if="currentData.is_verify == -1">
				<view class="flex-y-center fs-28 fw-500">
					<text class="iconfont icon-icon_clock1 fs-30"></text>
					<text class="pl-20">审核未通过，内容仅自己可见</text>
				</view>
				<view class="fs-22 pt-14 pl-48">{{currentData.refusal}}</view>
			</view>
			<view class="w-full h-full container text--w111-fff" v-if="currentData.is_verify == 0">
				<view class="flex-y-center fs-28 fw-500">
					<text class="iconfont icon-icon_clock1 fs-30"></text>
					<text class="pl-20">正在审核，内容仅自己可见</text>
				</view>
				<view class="fs-22 pt-14 pl-48">发布的内容审核通过后，将在展示首页展示！</view>
			</view>
		</view>
	</view>
</template>
<script>
	let sysHeight = uni.getWindowInfo().statusBarHeight;
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import emptyPage from '@/components/emptyPage.vue';
	import replyList from "./replyList.vue"
	import tuiModal from "@/components/tui-modal/index.vue";
	import BaseTextMore from "../BaseTextMore.vue"
	import {
		getProductslist
	} from "@/api/store.js";
	import {
		communityLikeApi,
		communitySetInsterestApi,
		communityDeleteApi,
		communityShareApi,
		communityBrowseApi,
		communityProductListApi,
	} from "@/api/community.js"
	import {
		mapGetters
	} from 'vuex';
	import {
		toLogin
	} from "@/libs/login.js"
	// #ifdef H5
	import Auth from '@/libs/wechat.js';
	// #endif
	export default {
		name: 'discoverSwiperVideo',
		props: {
			statusBarHeight: {
				type: String,
				default: ''
			},
			swiperData: {
				type: Array,
				default: () => ([])
			},
			showFooter: {
				type: Boolean,
				default: false
			},
			fullVideo: {
				type: Boolean,
				default: false
			},
			replyStatus: {
				type: Number,
				default: 1
			},
			addReply: {
				type: Number,
				default: 1
			}
		},
		data() {
			return {
				sysHeight,
				swiperCurrent: 0,
				imgList: [],
				imgCurrent: 0,
				isPlay: false,
				showProDrawer: false,
				productList: [],
				showBubble: false,
				showReply: false,
				community_id: '',
				comment_num: '',
				currentData: {
					author: '',
					author_image: '',
					title: '',
					content: '',
					product: [],
					slider_image: [],
					is_follow: 0,
					is_self: 0,
					content_type: 0,
				},
				showDelete: false,
				isExpanded: false,
				startY: 0,
				moveY: 0,
				threshold: 50, // 滑动阈值
				// #ifdef H5
				Auth,
				// #endif
				route: '',
			};
		},
		components: {
			emptyPage,
			replyList,
			tuiModal,
			BaseTextMore
		},
		watch: {
			swiperData: {
				handler(nVal) {
					if (!this.swiperData.length) {
						return;
					}
					this.currentData = this.swiperData[this.swiperCurrent];
					if (this.currentData.content_type == 2) {
						uni.createVideoContext(`video${this.swiperCurrent}`, this).play();
					}
					this.imgList = this.currentData.slider_image;
					this.lookVideo();
					this.$emit('onSwiper', this.swiperCurrent);
					// #ifdef MP-WEIXIN
					this.$emit('onShare', this.currentData);
					// #endif
				},
				immediate: true,
				deep: true,
			},
			statusBarHeight: {
				handler(nVal) {
					if (nVal != '' && nVal != 0) {
						this.sysHeight = nVal;
					}
				},
				immediate: true
			},
		},
		computed: {
			...mapGetters(['isLogin', 'uid']),
			dotWidth() {
				let windowWidth = uni.getWindowInfo().windowWidth;
				return (windowWidth * 2 - (20 + (this.imgList.length) * 12)) / this.imgList.length;
			},
			showMore() {
				return this.isLogin && this.currentData.type == 2 && this.currentData.relation_id == this.uid;
			}
		},
		created() {
			const pages = getCurrentPages();
			const page = pages[pages.length - 1];
			const route = page.route;
			this.route = route;
		},
		methods: {
			handleTouchStart(e) {
				this.startY = e.touches[0].pageY;
			},
			handleTouchMove(e) {
				this.moveY = e.touches[0].pageY;
			},
			handleTouchEnd() {
				const diffY = this.moveY - this.startY;
				if (diffY > this.threshold) {
					if (this.swiperCurrent > 0) {
						this.videoChange({
							detail: {
								current: this.swiperCurrent - 1
							}
						});
					}
				} else if (diffY < -this.threshold) {
					this.videoChange({
						detail: {
							current: this.swiperCurrent + 1
						}
					});
				}
			},
			backPage() {
				// #ifdef MP
				let pages = getCurrentPages(); // 获取当前打开过的页面路由数，
				if (pages.length > 1) {
					uni.navigateBack()
				} else {
					uni.switchTab({
						url: '/pages/index/index'
					});
				}
				// #endif
				// #ifndef MP
				if (window.plus) {
					plus.webview.getTopWebview().evalJS(`
					uni.navigateBack()
					`);
				} else {
					let pages = getCurrentPages(); // 获取当前打开过的页面路由数，
					if (pages.length > 1) {
						uni.navigateBack()
					} else {
						uni.switchTab({
							url: '/pages/index/index'
						});
					}
				}
				// #endif
			},
			playVideo() {
				uni.createVideoContext(this.swiperData[this.swiperCurrent].id + 'k' + this.swiperCurrent, this).play();
				this.isPlay = true;
			},
			tapVideoHover(e) {
				if (this.isPlay) {
					uni.createVideoContext(this.swiperData[this.swiperCurrent].id + 'k' + this.swiperCurrent, this).pause();
					this.isPlay = false;
				} else {
					uni.createVideoContext(this.swiperData[this.swiperCurrent].id + 'k' + this.swiperCurrent, this).play();
					this.isPlay = true;
				}

			},
			ended() {},
			videoChange(event) {
				const current = event.detail.current;
				if (current == this.swiperCurrent) {
					return;
				}
				if (this.currentData.content_type == 2) {
					uni.createVideoContext(`video${this.swiperCurrent}`, this).pause();
				}
				this.swiperCurrent = current;
				this.currentData = this.swiperData[this.swiperCurrent];
				if (this.currentData.content_type == 2) {
					uni.createVideoContext(`video${this.swiperCurrent}`, this).play();
				}
				this.showBubble = false;
				this.imgList = this.currentData.slider_image;
				this.imgCurrent = 0;
				this.lookVideo();
				this.$emit('onSwiper', this.swiperCurrent);
				// #ifdef MP-WEIXIN
				this.$emit('onShare', this.currentData);
				// #endif
			},
			lookVideo() {
				if (this.isLogin) {
					communityBrowseApi(this.currentData.id).catch(err => {
						console.error(err);
					})
				}
			},
			photoChange(e) {
				this.imgCurrent = e.detail.current;
			},
			goDetail(id) {
				let url = `/pages/goods_details/index?id=${id}`
				this.$util.JumpPath(url);
			},
			editTo() {
				uni.navigateTo({
					url: '/pages/discover/discoverCreate/index?id=' + this.currentData.id
				})
			},
			delContent() {
				this.showDelete = true;
				this.showBubble = false;
			},
			handleDelete(e) {
				let index = e.index;
				let that = this;
				if (index == 1) {
					communityDeleteApi(this.currentData.id).then(res => {
						this.showDelete = false;
						return this.$util.Tips({
							title: res.msg
						}, {
							tab: 4,
							url: '/pages/discover/discoverIndex/index'
						});
					}).catch(err => {
						this.showDelete = false;
						return this.$util.Tips({
							title: err
						});
					});
				} else {
					this.showDelete = false;
				}
			},
			getProList() {
				let data = {
					ids: this.currentData.product_id.toString()
				};
				communityProductListApi(data).then(res => {
					this.productList = res.data;
					this.showProDrawer = true;
				})
			},
			toUser() {
				if (!this.isLogin) {
					toLogin();
					return;
				}
				uni.navigateTo({
					url: `/pages/discover/discoverUser/index?id=${this.currentData.relation_id}&is_store=${this.currentData.type}`
				})
			},
			authTo(url) {
				uni.navigateTo({
					url: url
				});
			},
			toggleReplyDrawer() {
				this.community_id = this.currentData.id;
				this.comment_num = this.currentData.comment_num;
				if (this.isLogin) {
					this.showReply = true;
				} else {
					toLogin();
				}

			},
			contentLike() {
				if (!this.isLogin) {
					toLogin();
					return;
				}
				let id = this.currentData.id;
				let status = this.currentData.is_like == 1 ? 0 : 1;
				let that = this;
				communityLikeApi(id, {
					status,
					is_store: this.currentData.type
				}).then(res => {
					that.$emit('onLike', {
						index: that.swiperCurrent,
						status: status
					});
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				})
			},
			closeDrawer() {
				this.showReply = false;
				this.showProDrawer = false;
			},
			followFun() {
				if (!this.isLogin) {
					toLogin();
					return;
				}
				communitySetInsterestApi(this.currentData.relation_id, {
					status: 1,
					is_store: this.currentData.type
				}).then(res => {
					this.$emit('followChange', {
						index: this.swiperCurrent
					});
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			},
			commentAdd(data) {
				if (data.type == 1) {
					this.comment_num++;
				} else {
					this.comment_num = this.comment_num - data.num;
				}
			},
			openShare() {
				let that = this;
				if (that.isLogin) {
					communityShareApi(that.currentData.id).catch(err => {
						console.error(err);
					})
					// #ifdef H5
					uni.setClipboardData({
						data: `${HTTP_REQUEST_URL}/pages/discover/discoverVideo/index?id=${that.currentData.id}&spid=${that.uid}`,
						success: () => {
							uni.showToast({
								title: '链接已复制'
							})
						}
					})
					// #endif
					// #ifdef MP-WEIXIN
					this.$emit('onShare', that.currentData);
					// #endif
				} else {
					toLogin();
				}

			},
		},
	};
</script>
<style lang="scss" scoped>
	.video-swiper {
		height: 100vh;
	}

	.z-4000 {
		z-index: 4000;
	}

	.avatar-box {
		border: 3rpx solid #FFFFFF;
	}

	.bg-transparent {
		background-color: transparent;
	}

	.text-line {
		text-decoration: line-through;
	}

	.hide-footer {
		padding-bottom: 30rpx;
	}

	.show-footer {
		padding-bottom: calc(30rpx + env(safe-area-inset-bottom));
	}

	.right-action {
		position: fixed;
		right: 20rpx;
		bottom: 0;
		/* #ifdef H5 */
		// bottom: 162rpx;
		/* #endif */
		/* #ifdef MP-WEIXIN */
		// bottom: 62rpx;
		/* #endif */
		width: 88rpx;
		padding-bottom: 76rpx;
	}

	.action-box {
		/* #ifdef MP-WEIXIN */
		bottom: calc(162rpx + env(safe-area-inset-bottom));
		/* #endif */
	}

	.full-action {
		/* #ifdef H5 */
		// bottom: 64rpx !important;
		/* #endif */
		/* #ifdef MP-WEIXIN */
		// bottom: calc(64rpx + env(safe-area-inset-bottom)) !important;
		/* #endif */
	}

	.shadow {
		text-shadow: 0px 2rpx 4rpx rgba(0, 0, 0, 0.2);
	}

	.fixed-desc {
		position: fixed;
		left: 0;
		bottom: 0;
		padding-left: 20rpx;
		padding-bottom: 32rpx;
		z-index: 10;
		/* #ifdef H5 */
		// bottom: 100rpx;
		/* #endif */
		/* #ifdef MP-WEIXIN */
		// bottom: 0;
		/* #endif */
		background: linear-gradient(180deg, rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.3) 100%);

		.box {
			width: 584rpx;
		}

		.w-584 {
			width: 584rpx;
		}
	}

	.content-box {
		max-height: 400rpx;
		overflow-y: auto;
	}

	.hide-tab {
		/* #ifdef MP-WEIXIN */
		bottom: calc(100rpx + env(safe-area-inset-bottom));
		/* #endif */
	}

	.full-tab {
		/* #ifdef H5 */
		// bottom: 0 !important;
		/* #endif */
		/* #ifdef MP-WEIXIN */
		// bottom: env(safe-area-inset-bottom) !important;
		/* #endif */
	}

	.dot_item~.dot_item {
		margin-left: 12rpx;
	}

	.follow-icon {
		position: absolute;
		bottom: -21rpx;
		left: 24rpx;
		width: 42rpx;
		height: 42rpx;
		border-radius: 50%;
		background-color: var(--view-theme);

	}

	.like-heart {
		color: #e93323;
	}

	.bubble_box {
		position: absolute;
		top: -64rpx;
		left: -208rpx;
		width: 184rpx;
		z-index: 999;

		.bubble {
			width: 184rpx;
			height: 198rpx;
			background: #FFFFFF;
			box-shadow: 0rpx 2rpx 15rpx 0rpx rgba(0, 0, 0, 0.102);
			border-radius: 16rpx;
			padding: 30rpx 16rpx 30rpx 16rpx;
			position: relative;

			&:after {
				content: '';
				position: absolute;
				right: -10px;
				top: 74rpx;
				width: 0;
				height: 0;
				border-top: 10px solid transparent;
				border-bottom: 10px solid transparent;
				border-left: 10px solid #fff;
			}
		}

		.x-line {
			border-bottom: 1rpx solid rgba(0, 0, 0, 0.10);
		}
	}

	.notice-modal {
		position: fixed;
		left: 50%;
		transform: translateX(-50%);
		width: 690rpx;
		background: rgba(51, 51, 51, 0.7);
		border-radius: 10rpx;

		.container {
			padding: 26rpx 30rpx;

			.icon-icon_clock1 {
				color: rgba(252, 131, 39, 1);
			}

			.icon-a-ic_tanhao1 {
				color: rgba(233, 51, 35, 1);
			}
		}
	}

	.close-btn {
		position: absolute;
		right: 28rpx;
		top: 28rpx;
		width: 36rpx;
		height: 36rpx;
		border-radius: 50%;
		background-color: #eee;
	}
</style>