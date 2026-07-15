<template>
	<view>
		<view class="w-full bg-top h-518 relative" :style="{backgroundImage:headerBg}">
			<view class="fixed-lt w-full z-20" :style="{'padding-top': sysHeight + 'px','background': pageScrollStatus ? '#e93323' : 'transparent'}">
				<view class="w-full px-20 pl-20 h-80 flex-between-center">
					<text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff" @click="goBack"></text>
					<text class="fs-34 fw-500 text--w111-fff">预售</text>
					<text></text>
				</view>
			</view>
		</view>
		<view class="px-20">
			<view class="bg--w111-fff rd-16rpx relative " style="top: -24rpx;">
				<view class="w-full h-112 bg-primary-light nav-bg" :style="{backgroundImage:navBg}">
					<view class="w-full flex-between-center pl-16 pr-16">
						<text class="fs-26 text--w111-666 lh-36rpx pt-22 pb-22 pl-56 pr-56"
							v-for="(item, index) in timeList" :key="index"
							:class="active == index? 'active':''"
							@tap="settimeList(item.key)">{{item.name}}</text>
					</view>
				</view>
				<view class="w-full pl-20 pr-20 pb-40" v-if="presellList.length">
					<view class="w-full flex justify-between pro-item"
						v-for="(item,index) in presellList" :key="index"
						@tap="goDetails(item)">
						<easy-loadimage
						class="bg-w111-f9f9f9"
						mode="aspectFit"
						:image-src="item.image"
						width="240rpx"
						height="240rpx"
						borderRadius="16rpx"></easy-loadimage>
						<view class="flex-1 flex-col justify-between pl-20">
							<view class="w-full">
								<view class="w-full fs-28 h-80 lh-40rpx line2">
									<text v-if="item.brand_name" class="brand-tag">{{ item.brand_name }}</text>
									{{item.store_name}}
								</view>
								<view class="w-full flex items-end flex-wrap mt-16" v-if="item.store_label.length">
									<BaseTag
										:text="label.label_name"
										:color="label.color"
										:background="label.bg_color"
										:borderColor="label.border_color"
										:circle="label.border_color ? true : false"
										:imgSrc="label.icon"
										v-for="(label, idx) in item.store_label" :key="idx"></BaseTag>
								</view>
							</view>
							<view class="flex w-full h-68 rd-8rpx btn-bg relative" :class="active == 2 ? 'opacity' : ''">
								<view class="flex-y-center pl-20 fs-22 btn-left ">
									<baseMoney
									:money="item.price"
									symbolSize="26"
									integerSize="40"
									decimalSize="26"
									weight
									preFix="预售:"
									preFixSize="22"
									textColor="#e93323"
									color="#e93323"></baseMoney>
								</view>
								<image src="../static/yushou1.png" class="btn-right" v-if="active == 0"></image>
								<image src="../static/yushou2.png" class="btn-right" v-else-if="active == 1"></image>
								<image src="../static/yushou3.png" class="btn-right" v-else="active == 2"></image>
							</view>
						</view>
					</view>
				</view>
				<view class='px-20' v-else>
					<emptyPage title="暂无预售商品，去看看其他商品吧～" src="/statics/images/noActivity.gif"></emptyPage>
				</view>
			</view>
		</view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import { getAdvancellList } from '@/api/activity';
	import dayjs from '@/plugin/dayjs/dayjs.min.js';
	import {HTTP_REQUEST_URL} from '@/config/app';
	import emptyPage from '@/components/emptyPage.vue';
	import home from '@/components/home/index.vue';
	import {mapGetters} from 'vuex';
	export default {
		name: "presell",
		filters: {
			dateFormat: function(value) {
				return dayjs(value * 1000).format('MM/DD HH:mm');
			},
		},
		components:{ emptyPage, home },
		data() {
			return {
				sysHeight:sysHeight,
				presellList: [],
				timeList: [
					{name: '未开始',key: 0},
					{name: '正在进行',key: 1},
					{name: '已结束',key: 2},
				],
				active: 1,
				page: 1,
				limit: 8,
				loading: false,
				loadend: false,
				loadTitle: '加载更多',
				picUrl: '', //头部图片
				imgHost:HTTP_REQUEST_URL,
				pageScrollStatus:false,
				homeHide: false
			}
		},
		computed:{
			headerBg(){
				return 'url('+this.imgHost+'/statics/images/product/product_presale_header.png'+')'
			},
			navBg(){
				return 'url('+this.imgHost+'/statics/images/product/presell-bg-' + this.active +'.png'+')'
			},
			...mapGetters(['uid']),
		},
		onPageScroll(object) {
			if (object.scrollTop > 130) {
				this.pageScrollStatus = true;
			} else if (object.scrollTop < 130) {
				this.pageScrollStatus = false;
			}
			uni.$emit('scroll');
      this.homeHide = true;
		},
		onLoad() {
			this.getPresellProductList();
			// #ifdef H5
			this.setOpenShare();
			// #endif
		},
		onShow(){
			uni.removeStorageSync('form_type_cart');
		},
		methods: {
			getPresellProductList: function() {
				let that = this;
				let data = {
					page: that.page,
					limit: that.limit,
					time_type: that.active + 1,
					is_list: 1,
				};
				if (that.loading) return;
				if (that.loadend) return;
				that.loading = true;
				that.loadTitle = '';
				getAdvancellList(data).then(res => {
					let list = res.data.list;
					let loadend = list.length < that.limit;
					that.presellList = that.$util.SplitArray(list, that.presellList);
					that.$set(that, 'presellList', that.presellList);
					that.loadend = loadend;
					that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					that.page = that.page + 1;
					that.loading = false;
				}).catch(err => {
					that.loading = false;
					that.loadTitle = '加载更多';
				});
			},
			settimeList(key) {
				let that = this;
				that.active = key;
				that.loadend = false;
				that.page = 1;
				that.presellList = [];
				that.getPresellProductList();
			},
			goDetails(item) {
				uni.navigateTo({
					url: `/pages/activity/goods_details/index?id=${item.id}&type=6`
				})
			},
			goBack(){
				this.$util.backPage();
			},
			// #ifdef H5
			// 微信分享；
			setOpenShare: function() {
				let that = this;
				let uid = this.uid ? this.uid : 0;
				if (that.$wechat.isWeixin()) {
					let data = res.data;
					let configAppMessage = {
						title: '预售列表',
						link: location.href + '?spid=' + uid
					};
					that.$wechat.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData',
							'onMenuShareAppMessage', 'onMenuShareTimeline'
						],
						configAppMessage);
				}
			},
			// #endif
		},
		/**
		 * 页面上拉触底事件的处理函数
		 */
		onReachBottom: function() {
			this.getPresellProductList();
		},
		/**
		 * 用户点击右上角分享
		 */
		//#ifdef MP
		onShareAppMessage() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: '预售列表',
				path: '/pages/activity/presell/index?spid=' + uid
			};
		},
		//分享到朋友圈
		onShareTimeline: function() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: '预售列表',
				path: '/pages/activity/presell/index?spid=' + uid
			};
		}
		//#endif
	}
</script>
<style>
	page{
		background: linear-gradient(270deg, #6D4ADC 0%, #7033D7 98%);
	}
</style>
<style lang="scss">
	.brand-tag {
		background: #E93323 !important;
	}
	.bg-top{
		background-size: 100% 100%;
		background-repeat: no-repeat;
	}
	.bg-primary-light{
		background-color: rgba(109, 74, 220, 0.1);
	}
	.nav-bg{
		background-size: 100% 100%;
		background-repeat: no-repeat;
	}
	.active{
		font-size: 32rpx;
		font-weight: 500;
		line-height: 44rpx;
		color: #7033D7;
	}
	.btn-bg{
		background: rgba(233, 51, 35, 0.1);
	}
	.btn-left{
		flex: 1;
		border-radius: 8rpx 0 0 8rpx;
	}
	.btn-right{
		width: 180rpx;
		height: 68rpx;
	}
	.shandian{
		width: 42rpx;
		height: 68rpx;
		position: absolute;
		transform: scale(1.1);
		left: 56%;
		top: 1px;
	}
	.pro-item ~ .pro-item{
		margin-top: 32rpx;
	}
	.border-label {
		display: inline-flex;
		display: flex;
		align-items: center;
		flex-wrap: nowrap;
		height: 26rpx;
		padding: 0 6rpx;
		border: 1rpx solid #e93323;
		border-radius: 6rpx;
		font-size: 18rpx;
		color: #e93323;
	}
	::v-deep  .empty-box{
		width: 100%;
		height: 280px;
	}
	.opacity{
		opacity: 0.5;
	}
</style>
