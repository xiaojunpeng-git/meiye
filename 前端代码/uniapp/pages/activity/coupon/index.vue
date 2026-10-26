<template>
	<view :style="colorStyle">
		<view class="w-full h-400 relative z-10" :style="{'height': (186 + sysHeight) * 2 + 'rpx', 'background' : headerBg}">
			<view class="fixed-lt w-full z-20" :style="{'padding-top': sysHeight + 'px','background': pageScrollStatus ? 'var(--view-theme)' : 'transparent'}">
				<view class="w-full px-20 flex-between-center" :style="{'height': buttonHeight +'px'}">
					<text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff" @tap="backPage"></text>
					<text class="fs-34 fw-500 text--w111-fff">领券中心</text>
					<text></text>
				</view>
			</view>
			<view class="abs-box flex-col">
				<image src="../static/get_coupon_text.png" class="w-184 h-46"></image>
				<text class="fs-24 lh-34rpx text--w111-fff pt-20">天天来领券，优惠看的见</text>
			</view>
			<image class="w-530 h-226 block abs-rb bottom-t20" :src="imgHost+'/statics/images/get_coupon_bg.png'"></image>
		</view>
		<view class="relative rd-t-40rpx bg--w111-f5f5f5 w-full content">
			<view class="_box flex-between-center text--w111-666 fs-30 lh-42rpx"
				:class="pageScrollStatus ? '' : 'rd-t-40rpx'"
				:style="{'top': (buttonHeight + sysHeight) + 'px'}">
				<text :class="current == 0 ? 'font-num fw-500' : ''" @tap="changeTab(0)">默认排序</text>
				<view class="flex-y-center" @tap="changeTab(1)">
					<text :class="current == 1 ? 'font-num fw-500' : ''">最新</text>
					<text v-show="params.timeOrder == 'desc'" class="iconfont icon-ic_down2 fs-14 ml-6 font-num"></text>
					<text v-show="params.timeOrder == 'asc'" class="iconfont icon-ic_up2 fs-14 ml-6 font-num"></text>
					<text v-show="params.timeOrder == ''" class="iconfont icon-ic_down2 fs-14 ml-6"></text>
					<!-- timeOrder -->
				</view>
				<view class="flex-y-center" @tap="changeTab(2)">
					<text :class="current == 2 ? 'font-num fw-500' : ''">价值</text>
					<text v-show="params.priceOrder == 'desc'" class="iconfont icon-ic_down2 fs-14 ml-6 font-num"></text>
					<text v-show="params.priceOrder == 'asc'" class="iconfont icon-ic_up2 fs-14 ml-6 font-num"></text>
					<text v-show="params.priceOrder == ''" class="iconfont icon-ic_down2 fs-14 ml-6"></text>
				</view>
				<view class="flex-y-center" @tap="showDrop = !showDrop">
					<text class="menu_line"></text>
					<text>筛选</text>
					<text class="iconfont icon-ic_sort pl-8"></text>
				</view>
				<view class="drop-box bg--w111-f5f5f5 flex justify-between" v-if="showDrop">
					<view class="inline-block h-52 rd-28rpx px-28 lh-52rpx text-center fs-24 bg--w111-fff"
						v-for="(item, index) in navList" :key="index"
						:class="params.type === item.type ? 'active-tab' : ''"
						@tap="setType(item.type)">{{item.name}}</view>
				</view>
			</view>
			<view class="coupon-list" v-if="couponsList.length">
				<view class="item" v-for="(item,index) in couponsList" :key="item.id" @tap="goDetails(item)">
					<view class="item-box acea-row" :class="{svip: item.category == 2}">
						<view class="moneyCon acea-row row-center-wrapper">
							<view class='money'>
								<BaseMoney v-if="item.coupon_type==1" :money="item.coupon_price" :color="item.category == 2 ? '#FACC7D' : '#FFFFFF'" symbolSize="28" integerSize="52" isCoupon></BaseMoney>
								<view v-else-if="item.coupon_type==2" :style="[{color:item.category == 2 ? '#FACC7D' : '#FFFFFF'}]" class="SemiBold">{{ parseFloat(item.coupon_price)/10 }}<text class="fs-28 pingfang pl-4">折</text></view>
								<view class="pic-num" v-if="item.use_min_price > 0">满{{item.use_min_price}}可用</view>
								<view class="pic-num" v-else>无门槛券</view>
							</view>
						</view>
						<view class="text acea-row row-column row-center min-w-0">
							<view class='condition w-full'>
								<view class="name line1">
									{{ item.coupon_title }}
								</view>
							</view>
							<view class='data acea-row row-between-wrapper'>
                <view v-if="item.coupon_time">领取后{{item.coupon_time}}天内可用</view>
                <view v-else >{{ item.start_time ? item.start_time + '-' : '' }}{{ item.end_time }}</view>
							</view>
							<view class="text-bottom acea-row row-middle">
								<view v-if="item.type === 0">通用优惠券</view>
								<view v-else-if="item.type === 1">品类优惠券</view>
								<view v-else-if="item.type === 3">品牌优惠券</view>
								<view v-else>商品优惠券</view>
								<view>丨</view>
								<view class="">查看用券规则<text class="iconfont icon-ic_rightarrow"></text></view>
							</view>
						</view>
						<view class="btn-box acea-row row-middle">
							<view class="w-128 h-48 rd-30rpx flex-center fs-20 bg-gradient text--w111-fff mt-16" v-if="item.unused" @tap.stop="useCoupon(item)">去使用</view>
							<view class="w-128 h-48 rd-30rpx flex-center fs-20 bg-hui text--w111-fff mt-16" v-else-if="item.is_use == true">已领取</view>
							<view class="w-128 h-48 rd-30rpx flex-center fs-20 bg-hui text--w111-fff mt-16" v-else-if="item.is_use == 2">已领完</view>
							<view class="w-128 h-48 rd-30rpx flex-center fs-20 bg-gradient text--w111-fff mt-16" v-else @tap.stop="getCoupon(item.id, index)">立即领取</view>
						</view>
					</view>
				</view>
			</view>
			<view class='loadingicon flex-center' v-if="couponsList.length">
				<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
			</view>
			<view class="px-20" v-if="!couponsList.length">
				<emptyPage title="暂无优惠券，去看点别的吧～" src="/statics/images/noCoupon.gif"></emptyPage>
			</view>
		</view>
		<view class="mask" v-if="showDrop" @tap="showDrop = false"></view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	// #ifdef MP
	let menuButtonInfo = wx.getMenuButtonBoundingClientRect();
	let menuHeight = wx.getMenuButtonBoundingClientRect().height;
	let menuTop = wx.getMenuButtonBoundingClientRect().top;
	let buttonHeight = menuHeight+(menuTop-sysHeight)*2;
	// #endif
	// #ifndef MP
	let buttonHeight = 40
	// #endif
	import { getCoupons, setCouponReceive } from '@/api/api.js';
	import emptyPage from '@/components/emptyPage.vue';
	import home from '@/components/home/index.vue';
	import { toLogin } from '@/libs/login.js';
	import { mapGetters } from "vuex";
	import colors from '@/mixins/color.js';
	import {HTTP_REQUEST_URL} from '@/config/app';
	export default {
		mixins:[colors],
		components:{ emptyPage, home },
		data() {
			return {
				sysHeight:sysHeight,
				buttonHeight:buttonHeight,
				couponsList: [],
				loading: false,
				loadend: false,
				loadTitle: '加载更多', //提示语
				params:{
					page: 1,
					limit: 20,
					type: '',
					defaultOrder: 1,
					timeOrder: '',
					priceOrder: ''
				},
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				navList: [
					{name: '快过期',type: -1},
					{name: '通用券',type: 0},
					{name: '品类券',type: 1},
					{name: '商品券',type: 2},
					{name: '品牌券',type: 3},
				],
				count: 0,
				imgHost:HTTP_REQUEST_URL,
				pageScrollStatus:false,
				showDrop: false,
				current:0,
				price: 0,
				homeHide: false
			};
		},
		computed:{
			...mapGetters(['isLogin']),
			headerBg(){
				return 'linear-gradient(90deg, var(--view-gradient) 0%, var(--view-theme) 100%)'
			}
		},
		filters:{
			typeFilter(val){
				let obj = {
					0: '通用券',
					1: '品类券',
					2: '商品券',
					3: '品牌券',
				};
				return obj[val]
			}
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
			uni.$on('updateCoupon', (data) => {
				const couponValue = this.couponsList.find((value) => value.id == data.id);
				if (couponValue) {
					couponValue.unused = true;
				}
			});
			this.getUseCoupons();
		},
		/**
		 * 页面上拉触底事件的处理函数
		 */
		onReachBottom() {
			this.getUseCoupons();
		},
		methods: {
			changeTab(val){
				this.current = val;
				if(val == 0){
					this.params.defaultOrder = 1;
					this.params.timeOrder = '';
					this.params.priceOrder = '';
				}else if(val == 1){
					if(this.params.timeOrder == '') {
						this.params.timeOrder = 'asc'
					}else if (this.params.timeOrder == 'asc'){
						this.params.timeOrder = 'desc'
					}else if(this.params.timeOrder == 'desc'){
						this.params.timeOrder = ''
					}
					this.params.defaultOrder = '';
					this.params.priceOrder = '';
				} else if(val == 2){
					if(this.params.priceOrder == '') {
						this.params.priceOrder = 'asc'
					}else if (this.params.priceOrder == 'asc'){
						this.params.priceOrder = 'desc'
					}else if(this.params.priceOrder == 'desc'){
						this.params.priceOrder = ''
					}
					this.params.defaultOrder = '';
					this.params.timeOrder = '';
				}
				this.params.page = 1;
				this.couponsList = [];
				this.loadend = false;
				this.loading = false;
				this.getUseCoupons();
			},
			backPage(){
				this.$util.backPage();
			},
			goDetails(item){
				uni.navigateTo({
					url: `/pages/activity/coupon_details/index?id=${item.id}`
				})
			},
			getCoupon(id, index) {
				let that = this;
				let list = that.couponsList;
				if (!this.isLogin) {
					toLogin()
				} else {
				  //领取优惠券
				  setCouponReceive(id).then(function(res) {
					if (!list[index].is_claimed) {
						list[index].quantity_count--;
						list[index].is_use = !list[index].quantity_count;
					}
					list[index].unused = true;
				  	that.$set(that, 'couponsList', list);
				  	that.$util.Tips({
				  		title: '领取成功'
				  	});
				  }).catch(error => {
				  	return that.$util.Tips({
				  		title: error
				  	});
				  })
				}
			},
			/**
			 * 获取领取优惠券列表
			 */
			getUseCoupons: function() {
				let that = this
				if (this.loadend) return false;
				if (this.loading) return false;
				that.loading = true;
				that.loadTitle = '加载更多';
				getCoupons(this.params).then(res => {
					let list = res.data.list,
						loadend = list.length < that.params.limit,
						countIndex = [];
					list.forEach((item) => {
						item.unused = item.used.some((value) => value.status == '未使用');
					});
					let couponsList = that.$util.SplitArray(list, that.couponsList);
					that.$set(that, 'couponsList', couponsList);
					that.loadend = loadend;
					that.loading = false;
					that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					that.params.page++;
				}).catch(err => {
					that.loading = false;
					that.loadTitle = '加载更多';
				});
			},
			setType(type) {
				if (this.params.type !== type) {
					this.params.type = type;
				} else {
					this.params.type = '';
				}
				this.couponsList = [];
				this.params.page = 1;
				this.loadend = false;
				this.showDrop = false;
				this.getUseCoupons();
			},
			useCoupon(item) {
				let url = '';
				// 通用券
				if (item.category_id == 0 && item.product_id == '' && item.brand_id == 0) {
					url = `/pages/goods/goods_list/index?title=默认&couponId=${item.id}&couponIssueType=${item.coupon_issue_type}`
				}
				// 品类券
				if (item.category_id != 0) {
					if (item.category_type == 1) {
						url = '/pages/goods/goods_list/index?cid=' + item.category_id + '&title=' + item.coupon_title + '&couponId=' + item.id + '&couponIssueType=' + item.coupon_issue_type
					} else {
						url = '/pages/goods/goods_list/index?sid=' + item.category_id + '&title=' + item.coupon_title + '&couponId=' + item.id + '&couponIssueType=' + item.coupon_issue_type
					}
				}
				//商品券
				if (item.product_id != '') {
					let arr = item.product_id.split(',');
					let num = arr.length;
					if (num == 1) {
						url = '/pages/goods_details/index?id=' + item.product_id
					} else {
						url = '/pages/goods/goods_list/index?productId=' + item.product_id + '&title=默认' + '&couponId=' + item.id + '&couponIssueType=' + item.coupon_issue_type
					}
				}
				//品牌券
				if (item.brand_id != 0) {
					url = '/pages/goods/goods_list/index?brandId=' + item.brand_id + '&title=默认' + '&couponId=' + item.id + '&couponIssueType=' + item.coupon_issue_type
				}
				uni.navigateTo({
					url: url
				});
			},
		}
	};
</script>

<style scoped lang="scss">
	.h-40px{
		height: 40px;
	}
	.abs-box{
		width: 264rpx;
		position: absolute;
		left: 48rpx;
		bottom: 80rpx;
	}
	.content{
		top: -44rpx;
	}
	.menu_line {
		width: 1rpx;
		height: 26rpx;
		background: #ccc;
		margin: 0 32rpx 0 20rpx;
	}
	._box{
		padding: 28rpx 32rpx;
		background: #f5f5f5;
		position: sticky;
		z-index: 99;
	}
	.drop-box{
		width: 100%;
		height: 102rpx;
		position: absolute;
		left: 0;
		top: 92rpx;
		border-radius: 0 0 40rpx 40rpx;
		z-index: 199;
		padding: 16rpx 32rpx 0;
	}
	.active-tab{
		background: var(--view-minorColorT);
		color: var(--view-theme);
		border: 1px solid var(--view-theme);
	}
	.svip .font-num{
		color: #333333 !important;
	}
	.svip .bg-gradient{
		background: linear-gradient(-90deg, #584834 0%, #32302D 100%);
		color: #FACC7D;
	}
	.bg-hui{
		color: #ffffff !important;
		background: #cccccc !important;
	}
	.indent{
		text-indent: 60rpx;
	}
	.card{
		background-image:
		radial-gradient(circle at 500rpx top, #f5f5f5, #f5f5f5 10rpx, transparent 11rpx),
		radial-gradient(circle at 500rpx bottom, #f5f5f5, #f5f5f5 10rpx, transparent 11rpx);
		position: relative;
		&:before{
			content: '';
			position: absolute;
			left: 500rpx;
			top: 16rpx;
			height: 168rpx;
			border-left: 2px dotted #ddd;
		}
		.name {
			width: 286rpx;
			height: 80rpx;
			line-height: 40rpx;
			color: #333;
			font-weight: 500;
			font-size: 28rpx;
		}
		.type-tag{
			display: inline-block;
			background: var(--view-minorColorT);
			color: var(--view-theme);
			border-radius: 14rpx;
			padding: 2rpx 10rpx;
			font-size: 20rpx;
			margin-right: 8rpx;
			vertical-align: middle;
		}
	}
	.card-item ~ .card-item{
		margin-top: 20rpx;
	}
	.rule-desc{
		margin-top: -16rpx;
		padding: 40rpx 24rpx 24rpx;
		white-space: pre-wrap;
		font-size: 20rpx;
		line-height: 28rpx;
		background: linear-gradient(180deg, #F7F7F7 0%, #FFFFFF 100%);
		border-radius: 0 0 24rpx 24rpx;
		color: #999;
	}
	.SemiBold{
		font-family: 'SemiBold'
	}
	.pingfang{
		font-family: PingFang SC, PingFang SC;
	}
	.coupon-list {
		padding: 0 20rpx;
		margin-top: 0;

		.item {
			height: auto;
			background: none;
		}

		.item-box {
			height: 170rpx;
			border-radius: 24rpx;
			background: #FFFFFF;
		}

		.moneyCon {
			border-radius: 24rpx 0 0 24rpx;
			background: linear-gradient(270deg, var(--view-gradient) 0%, var(--view-theme) 100%);
			color: #FFFFFF;
		}

		.money {
			position: relative;
			background-image: radial-gradient(circle at left, #F5F5F5 16rpx, transparent 0);
			color: #FFFFFF;
			font-size: 52rpx;
			font-family: Regular;

			&::after {
				content: "";
				position: absolute;
				top: 0;
				bottom: 0;
				right: 0;
				width: 6rpx;
				background-image: radial-gradient(circle at 6rpx, #FFFFFF 6rpx, transparent 6rpx);
				background-size: 6rpx 18rpx;
			}
		}

		.pic-num {
			margin-top: 8rpx;
			font-weight: 400;
			font-size: 22rpx;
			line-height: 28rpx;
		}

		.text-bottom {
			margin-top: 20rpx;
			font-size: 20rpx;
			line-height: 28rpx;
			color: #999999;

			.iconfont {
				margin-left: 6rpx;
				font-size: 20rpx;
			}
		}

		.btn-box {
			padding: 0 20rpx;
		}

		.btn {
			height: 52rpx;
			padding: 0 24rpx;
			border-radius: 26rpx;
			background-color: var(--view-minorColorT);
			font-weight: 500;
			font-size: 22rpx;
			line-height: 52rpx;
			color: var(--view-theme);
		}

		.disabled {
			background-color: #ccc;
			color: #fff;
		}

		.rule-desc {
			padding: 42rpx 24rpx 24rpx;
			border-radius: 0 0 24rpx 24rpx;
			background: linear-gradient(180deg, #F7F7F7 0%, #FFFFFF 100%);
			font-size: 20rpx;
			line-height: 28rpx;
			color: #999999;
		}

		.gray {
			.moneyCon {
				background: #CCCCCC;
			}

			.item-right {
				background-color: #CCCCCC;
				color: #FFFFFF;
			}
		}
	}
	.svip .btn {
		background: linear-gradient(-90deg, #584834 0%, #32302D 100%);
		color: #FACC7D;
	}

	.svip .moneyCon {
		background: linear-gradient(-90deg, #584834 0%, #32302D 100%);
		color: #FACC7D;
	}

	.svip .pic-num {
		color: #FACC7D;
	}
</style>
