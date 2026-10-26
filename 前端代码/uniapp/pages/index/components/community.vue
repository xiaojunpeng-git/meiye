<template>
	<view :style="[shortVideoWrapStyle]" v-if="videoList.length && !dataConfig.isHide">
		<view class="shortVideo" :style="[shortVideoStyle]">
			<view class="nav acea-row row-between-wrapper" :style="[navStyle]">
				<view v-if="dataConfig.titleConfig.tabVal" class="title" :style="[titleStyle]">{{ dataConfig.titleTxtConfig.value }}</view>
				<easy-loadimage v-else :image-src="dataConfig.imgConfig.url" width="134rpx" height="34rpx"></easy-loadimage>
				<view class="more" :style="[buttonStyle]" @click="more(0)">
					{{ dataConfig.rightBntConfig.value }}
					<text class="iconfont icon-ic_rightarrow" :style="[buttonStyle]"></text>
				</view>
				<image class="clover" src="/static/img/clover.png" mode=""></image>
			</view>
			<view v-if="dataConfig.styleConfig.tabVal == 0" class="list" :style="[listStyle]">
				<scroll-view scroll-x="true" class="scroll white-nowrap pl-20" show-scrollbar="false">
					<view class="item inline-block" v-for="item in videoList" :key="item.id" @click="more(item.id,item.content_type)" :style="[itemStyle]">
						<view class="relative">
							<easy-loadimage :image-src="item.image" width="296rpx" height="369rpx" :borderRadius="imageRadius"></easy-loadimage>
							<view class="abs-lb w-full text--w111-fff z-80">
								<view class="fs-24 pl-16 lh-38rpx w-full line1" v-show="item.title">{{item.title}}</view>
								<view class="pl-16 pb-14 flex-y-center" @tap.stop="toUser(item)">
									<image class="w-36 h-36 rd-50-p111-" :src="item.author_image"></image>
									<text class="pl-8 fs-20">{{item.author}}</text>
								</view>
							</view>
							<view class="copty-mask" :style="{'border-radius': imageRadius}"></view>
						</view>
					</view>
				</scroll-view>
			</view>
			<view v-else class="p-20 bg--w111-fff" :style="[listStyle]">
				<view :class="'wf-page wf-page'+type">
					<!--    left    -->
					<view>
						<view id="left" v-if="leftList.length">
							<view v-for="(item,index) in leftList" :key="index" class="wf-item" :style="[wfItemStyle]" @click="more(item.id,item.content_type)">
								<view class="wf-item-page wf-page0">
									<view class='pictrue overflow-picture relative'>
										<easy-loadimage mode="widthFix" :image-src="item.image" width="100%" :borderRadius="filletImg.borderRadius"></easy-loadimage>
										<view class="player flex-center" v-if="item.content_type == 2">
											<text class="iconfont icon-ic_right2 fs-20"></text>
										</view>
										<view class="w-60 h-36 rd-4rpx flex-center fs-22 text--w111-fff pic-number" v-if="item.content_type == 1">{{item.slider_image.length}}张</view>
										<view class="abs-lt w-full h-full flex-col flex-center text--w111-fff shenhe" v-if="[0,-1].includes(item.is_verify)">
											<text class="fs-28" v-show="item.is_verify == 0">正在审核</text>
											<text class="fs-24 pt-22" v-show="item.is_verify == 0">通过后将展示在列表</text>
											<text class="fs-28" v-show="item.is_verify == -1">审核未通过</text>
											<text class="fs-24 pt-22" v-show="item.is_verify == -1">查看未通过原因</text>
										</view>
									</view>
									<view class="info_box box-border">
										<view class="w-full lh-40rpx fs-28 line2">{{item.title}}</view>
										<view class="pt-22 fs-22 text--w111-999 flex-between-center">
											<view class="flex-y-center" @tap.stop="toUser(item)">
												<image class="w-34 h-34 rd-50-p111-" :src="item.author_image"></image>
												<text class="pl-8">{{item.author}}</text>
											</view>
											<view class="flex-y-center" :class="{'text-red': item.is_like}" @tap.stop="contentLike(item)">
												<text class="iconfont fs-22" :class="item.is_like ? 'icon-icon_Like_2' : 'icon-ic_Like'" :style="[likeSuccessColor]"></text>
												<text class="pl-10">{{item.like_num}}</text>
											</view>
										</view>
									</view>
								</view>
							</view>
						</view>
					</view>
					<!--    right    -->
					<view>
						<view id="right" v-if="rightList.length">
							<view v-for="(item,index) in rightList" :key="index" class="wf-item" :style="[wfItemStyle]" @click="more(item.id,item.content_type)">
								<view class="wf-item-page wf-page0">
									<view class='pictrue overflow-picture relative'>
										<easy-loadimage mode="widthFix" :image-src="item.image" width="100%" :borderRadius="filletImg.borderRadius"></easy-loadimage>
										<view class="player flex-center" v-if="item.content_type == 2">
											<text class="iconfont icon-ic_right2 fs-20"></text>
										</view>
										<view class="w-60 h-36 rd-4rpx flex-center fs-22 text--w111-fff pic-number" v-if="item.content_type == 1">{{item.slider_image.length}}张</view>
										<view class="abs-lt w-full h-full flex-col flex-center text--w111-fff shenhe" v-if="[0,-1].includes(item.is_verify)">
											<text class="fs-28" v-show="item.is_verify == 0">正在审核</text>
											<text class="fs-24 pt-22" v-show="item.is_verify == 0">通过后将展示在列表</text>
											<text class="fs-28" v-show="item.is_verify == -1">审核未通过</text>
											<text class="fs-24 pt-22" v-show="item.is_verify == -1">查看未通过原因</text>
										</view>
									</view>
									<view class="info_box box-border">
										<view class="w-full lh-40rpx fs-28 line2">{{item.title}}</view>
										<view class="pt-22 fs-22 text--w111-999 flex-between-center">
											<view class="flex-y-center" @tap.stop="toUser(item)">
												<image class="w-34 h-34 rd-50-p111-" :src="item.author_image"></image>
												<text class="pl-8">{{item.author}}</text>
											</view>
											<view class="flex-y-center" :class="{'text-red': item.is_like}" @tap.stop="contentLike(item)">
												<text class="iconfont fs-22" :class="item.is_like ? 'icon-icon_Like_2' : 'icon-ic_Like'" :style="[likeSuccessColor]"></text>
												<text class="pl-10">{{item.like_num}}</text>
											</view>
										</view>
									</view>
								</view>
							</view>
						</view>
					</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import {
		communityListApi,
		getCommunityConfig,
		communityLikeApi,
	} from "@/api/community.js";
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import {
		LOGIN_STATUS
	} from '@/config/cache';
	import { mapGetters } from 'vuex';
	export default {
		name: 'community',
		props: {
			dataConfig: {
				type: Object,
				default: () => ({})
			},
			isSortType: {
				type: [String, Number],
				default: 0
			}
		},
		data() {
			return {
				videoList: [],
				bgColor: '',
				infoColor: '',
				mbCongfig: 0,
				prConfig: 0, //背景边距
				numConfig: 0,
				allList: [], // 全部列表
				leftList: [], // 左边列表
				rightList: [], // 右边列表
				mark: 0, // 列表标记
				boxHeight: [], // 下标0和1分别为左列和右列高度
				type: 0,
				updateNum: 10,
			}
		},
		computed: {
			...mapGetters(['isLogin']),
			navStyle() {
				return {
					'border-radius': `${this.dataConfig.fillet.valList[0].val * 2}rpx ${this.dataConfig.fillet.valList[1].val * 2}rpx ${this.dataConfig.fillet.valList[3].val * 2}rpx ${this.dataConfig.fillet.valList[2].val * 2}rpx`,
					'background': `linear-gradient(90deg, ${this.dataConfig.headerBgColor.color[0].item} 0%, ${this.dataConfig.headerBgColor.color[1].item} 100%)`,
				};
			},
			titleStyle() {
				let fontStyle = 'normal';
				let fontWeight = 'normal';
				switch (this.dataConfig.titleText.tabVal) {
					case 0:
						fontWeight = 'bold'
						break;
					case 2:
						fontStyle = 'italic'
						fontWeight = 'bold'
						break;
				}
				return {
					'font-style': this.dataConfig.titleText.tabVal == 2 ? 'italic' : '',
					'font-size': `${this.dataConfig.titleNumber.val * 2}rpx`,
					'font-weight': this.dataConfig.titleText.tabVal == 0 ? 'bold' : '',
					color: this.dataConfig.titleColor.color[0].item
				};
			},
			buttonStyle() {
				return {
					'font-size': `${this.dataConfig.bntNumber.val * 2}rpx`,
					'color': this.dataConfig.headerBntColor.color[0].item,
				};
			},
			itemStyle() {
				let marginTop = 0;
				let marginLeft = 0;
				let marginRight = 0;
				if (this.dataConfig.styleConfig.tabVal == 0) {
					// marginLeft = `${this.dataConfig.videoSpace2.val * 2}rpx`;
					marginRight = `${this.dataConfig.videoSpace2.val * 2}rpx`;
				} else {
					marginTop = `${this.dataConfig.videoSpace.val * 2}rpx`;
				}
				return {
					'margin-top': marginTop,
					'margin-right': marginRight,
				};
			},
			itemLastStyle() {
				let marginRight = 0;
				if (this.dataConfig.styleConfig.tabVal) {
					marginRight = `${this.dataConfig.videoSpace2.val * 2}rpx`;
				}
				return {
					'margin-right': marginRight,
				};
			},
			imageRadius() {
				let borderRadius = [`${this.dataConfig.filletImg.val * 2}rpx`];
				if (this.dataConfig.filletImg.type) {
					borderRadius = [];
					for (let i = 0; i < this.dataConfig.filletImg.valList.length; i++) {
						borderRadius.push(`${this.dataConfig.filletImg.valList[i].val * 2}rpx`);
					}
				}
				return borderRadius.join(' ');
			},
			shortVideoWrapStyle() {
				return {
					padding: `${this.dataConfig.topConfig.val * 2}rpx ${this.dataConfig.prConfig.val * 2}rpx ${this.dataConfig.bottomConfig.val * 2}rpx`,
					marginTop: `${this.dataConfig.mbConfig.val * 2}rpx`,
					background: this.dataConfig.bottomBgColor.color[0].item,
				}
			},
			shortVideoStyle() {
				let borderRadius = `${this.dataConfig.fillet.val * 2}rpx`;
				if (this.dataConfig.fillet.type) {
					borderRadius =
						`${this.dataConfig.fillet.valList[0].val * 2}rpx ${this.dataConfig.fillet.valList[1].val * 2}rpx ${this.dataConfig.fillet.valList[3].val * 2}rpx ${this.dataConfig.fillet.valList[2].val * 2}rpx`;
				}
				return {
					borderRadius,
				}
			},
			listStyle() {
				return {
					'background': `linear-gradient(90deg, ${this.dataConfig.moduleColor.color[0].item} 0%, ${this.dataConfig.moduleColor.color[1].item} 100%)`,
				};
			},
			wfItemStyle() {
				let windowWidth = uni.getWindowInfo().windowWidth;
				return {
					width: (windowWidth - 30 - (this.dataConfig.prConfig.val * 2)) / 2 + 'px',
					'padding-bottom': `${this.dataConfig.videoSpace.val * 2}rpx`
				}
			},
			likeSuccessColor() {
				let styleObject = {
					color: 'var(--view-theme)'
				};
				if (this.dataConfig.toneConfig.tabVal) {
					styleObject.color = this.dataConfig.likeSuccessColor.color[0].item;
				}
				return styleObject;
			},
			filletImg() {
				let styleObject = {};
				if (this.dataConfig.filletImg.type) {
					styleObject.borderRadius = `${this.dataConfig.filletImg.valList[0].val * 2}rpx ${this.dataConfig.filletImg.valList[1].val * 2}rpx ${this.dataConfig.filletImg.valList[3].val * 2}rpx ${this.dataConfig.filletImg.valList[2].val * 2}rpx`;
				} else {
					styleObject.borderRadius = `${this.dataConfig.filletImg.val * 2}rpx`;
				}
				return styleObject;
			},
		},
		watch: {
			// 监听列表数据变化
			videoList: {
				handler(nVal, oVal) {
					// 如果数据为空或新的列表数据少于旧的列表数据（通常为下拉刷新或切换排序或使用筛选器），初始化变量

					if (!this.videoList.length ||
						(this.videoList.length === this.updateNum && this.videoList.length <= this.allList.length)) {
						this.allList = [];
						this.leftList = [];
						this.rightList = [];
						this.boxHeight = [];
						this.mark = 0;
					}

					// 如果列表有值，调用waterfall方法

					if (this.videoList.length) {
						this.allList = this.videoList;
						this.leftList = [];
						this.rightList = [];
						this.boxHeight = [];
						this.allList.forEach((v, i) => {
							if (this.allList.length < 3 || (this.allList.length <= 7 && this.allList.length - i > 1) || (this.allList.length > 7 && this.allList.length - i > 2)) {
								if (i % 2) {
									this.rightList.push(v);
								} else {
									this.leftList.push(v);
								}
							}
						});
						if (this.allList.length < 3) {
							this.mark = this.allList.length + 1;
						} else if (this.allList.length <= 7) {
							this.mark = this.allList.length - 1;
						} else {
							this.mark = this.allList.length - 2;
						}
						if (this.mark < this.allList.length) {
							this.waterFall()
						}
					}
				},
				immediate: true,
				deep: true
			},

			// 监听标记，当标记发生变化，则执行下一个item排序
			mark() {
				const len = this.allList.length;
				if (this.mark < len && this.mark !== 0 && this.boxHeight.length) {
					this.waterFall();
				}
			}
		},
		mounted() {
			getCommunityConfig().then(res => {
				if (res.data.community_status == 1) {
					this.getVideoList();
				}
			})
		},
		methods: {
			// 瀑布流排序
			waterFall() {
				const i = this.mark;
				if (i == 0) {
					// 初始化，从左边开始插入
					this.leftList.push(this.allList[i]);
					// 更新左边列表高度
					this.getViewHeight(0);
				} else if (i == 1) {
					// 第二个item插入，默认为右边插入
					this.rightList.push(this.allList[i]);
					// 更新右边列表高度
					this.getViewHeight(1);
				} else {
					// 根据左右列表高度判断下一个item应该插入哪边
					if (!this.boxHeight.length) {
						this.rightList.length < this.leftList.length ?
							this.rightList.push(this.allList[i]) :
							this.leftList.push(this.allList[i]);
					} else {
						const leftOrRight = this.boxHeight[0] > this.boxHeight[1] ? 1 : 0;
						if (leftOrRight) {
							this.rightList.push(this.allList[i])
						} else {
							this.leftList.push(this.allList[i])
						}
					}
					// 更新插入列表高度
					this.getViewHeight();
				}
			},
			// 获取列表高度
			getViewHeight() {
				// 使用nextTick，确保页面更新结束后，再请求高度
				this.$nextTick(() => {
					setTimeout(() => {
						uni.createSelectorQuery().in(this).select('#right').boundingClientRect(res => {
							res ? this.boxHeight[1] = res.height : '';
							uni.createSelectorQuery().in(this).select('#left').boundingClientRect(res => {
								res ? this.boxHeight[0] = res.height : '';
								this.mark = this.mark + 1;
							}).exec();
						}).exec();
					}, 100)
				})
			},
			// item点击
			itemTap(item) {
				if (item.content_type == 1) {
					uni.navigateTo({
						url: '/pages/discover/discoverDetails/index?id=' + item.id
					})
				} else {
					uni.navigateTo({
						url: '/pages/discover/discoverVideo/index?id=' + item.id
					})
				}

			},
			getVideoList: function() {
				let that = this;
				communityListApi({
					page: 1,
					limit: this.dataConfig.numberConfig.val,
					topic_id: this.dataConfig.styleConfig.tabVal ? this.dataConfig.selectConfig.activeValue : 0
				}).then(res => {
					that.videoList = res.data;
				});
			},
			more(id, type) {
				if (id == 0) {
					// uni.reLaunch({
					// 	url: '/pages/discoverIndex/index'
					// })
					uni.navigateTo({
						url: '/pages/discover/discoverIndex/index'
					})
				} else {
					if (type == 1) {
						uni.navigateTo({
							url: '/pages/discover/discoverDetails/index?id=' + id
						})
					} else {
						// #ifdef H5 || MP-WEIXIN
						uni.navigateTo({
							url: '/pages/discover/discoverVideo/index?id=' + id
						})
						// #endif
						// #ifdef APP-PLUS
						let href = encodeURIComponent(`${HTTP_REQUEST_URL}/pages/discover/discoverVideo/index?content_type=2&id=${id}&token=${this.$Cache.get(LOGIN_STATUS)}`)
						uni.navigateTo({
							url: `/pages/discover/discoverVideo/app?url=${href}`
						})
						// #endif
					}
				}
			},
			toUser(item) {
				uni.navigateTo({
					url: `/pages/discover/discoverUser/index?id=${item.relation_id}&is_store=${item.type}`
				})
			},
			contentLike(item) {
				if (!this.isLogin) {
					return;
				}
				let id = item.id;
				let status = item.is_like == 1 ? 0 : 1;
				let that = this;
				communityLikeApi(id,{status,is_store:item.type}).then(res=>{
					item.is_like = status;
					if(status == 1){
						item.like_num++
					}else{
						item.like_num--
					}
					return that.$util.Tips({
						title: res.msg
					});
				}).catch(err=>{
					return that.$util.Tips({
						title: err
					});
				})
			},
		}
	}
</script>

<style lang="scss">
	.shortVideo {
		overflow: hidden;

		.nav {
			width: 100%;
			padding: 0 24rpx;
			height: 96rpx;
			background: linear-gradient(270deg, #FFFFFF 0%, #FFFFFF 100%);
			position: relative;

			.clover {
				position: absolute;
				right: 190rpx;
				top: 20rpx;
				width: 100rpx;
				height: 100rpx;
			}

			.more {
				font-size: 24rpx;
				color: #999999;

				.iconfont {
					font-size: 24rpx;
				}
			}
		}

		.list {
			padding: 24rpx 0;
			border-radius: 0rpx 0rpx 16rpx 16rpx;
			background: #FFFFFF;
		}
	}

	.wf-page {
		display: grid;
		grid-template-columns: 1fr 1fr;
		grid-gap: 20rpx;
	}

	.wf-item {
		padding-bottom: 20rpx;
	}

	.wf-page1 .wf-item {
		margin-top: 20rpx;
		background-color: #fff;
		border-radius: 20rpx;
		padding-bottom: 0;
	}

	.overflow-picture {
		max-height: 500rpx;
		overflow-y: hidden;
	}

	.info_box {
		padding: 24rpx 0;
		border-radius: 0 0 24rpx 24rpx;
		// background-color: #fff;
	}

	.box-border {
		// border: 1rpx solid #eee;
	}

	.text-red {
		color: #e93323;
	}

	.player {
		position: absolute;
		top: 20rpx;
		right: 20rpx;
		width: 40rpx;
		height: 40rpx;
		border-radius: 50%;
		background-color: rgba(51, 51, 51, 0.5);
		color: #fff;
	}

	.shenhe {
		background-color: rgba(0, 0, 0, 0.4);
	}

	.pic-number {
		position: absolute;
		right: 16rpx;
		bottom: 16rpx;
		background: rgba(102, 102, 102, 0.50);
	}

	.copty-mask {
		position: absolute;
		left: 0;
		top: 0;
		width: 296rpx;
		height: 369rpx;
		background: linear-gradient(180deg, rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.4) 100%);
		z-index: 20;
	}
</style>