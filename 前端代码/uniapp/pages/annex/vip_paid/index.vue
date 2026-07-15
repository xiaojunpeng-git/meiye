<template>
	<!-- svip会员模块 -->
	<view class="vipPaid">
		<!-- #ifdef MP -->
		<NavBar titleText="SVIP会员" :iconColor="iconColor" :textColor="iconColor" :isScrolling="isScrolling" :showBack='showBack' :showHome='showHome'></NavBar>
		<!-- #endif -->
		<view class="headerBg"></view>
		<view class="card-section">
			<image src="../static/vip-paid.png" class="vip-paid"></image>
			<view class="wrapper">
				<view class="avatar-wrap acea-row">
					<image class="avatar" :src="userInfo.avatar"></image>
					<view class="text">
						<view class="name line1" v-if="isLogin">{{userInfo.nickname}}</view>
						<view class="name" v-else @click="goLogin">点击登录</view>
						<view class="tips" v-if="userInfo.is_ever_level">永久SVIP会员</view>
						<view class="tips" v-else-if="userInfo.is_money_level">{{userInfo.overdue_time | dateFormat}} 到期</view>
						<view class="arrow" @click="goRightDetail">权益介绍<text class="iconfont icon-ic_rightarrow"></text></view>
					</view>
				</view>
				<view class="right-section">
					<view class="section-bd acea-row row-between-wrapper">
						<view v-for="item in memberRights" :key="item.id" class="item">
							<view class="image-wrap acea-row row-center row-middle">
								<view class="image-wrap-inner">
									<image class="image" :src="item.pic"></image>
								</view>
							</view>
							<view class="text">
								<view class="name">{{item.title}}</view>
								<view>{{item.explain}}</view>
							</view>
						</view>
					</view>
				</view>
			</view>
		</view>
		<!-- 选择卡种 -->
		<view class="other-section">
			<view class="type-section" id="card" v-if="memberType.length && !userInfo.is_ever_level">
				<view class="type-section-inner">
					<view class="title acea-row row-between-wrapper">
						<view class="">选择卡种</view>
						<view class="cash" @click="drawMemberCard">点击兑换卡密</view>
					</view>
					<scroll-view class="scroll" scroll-x="true">
						<view v-for="item in memberType" :key="item.id" class="item" :class="{on: item.id === svip.id}" @click="checkType(item)">
							<view v-if="item.is_label" class="badge">限时特惠</view>
							<view class="name">{{item.title}}</view>
							<view class="new">￥<text class="num">{{item.pre_price | moneyFormat}}</text></view>
							<view class="old">￥{{item.price | moneyFormat}}</view>
							<view class="button">立省{{ $util.$h.Sub(item.price, item.pre_price) }}元</view>
						</view>
					</scroll-view>
				</view>
			</view>
			<view v-if="memberCoupons.length" class="coupon-section">
				<view class="section-hd acea-row row-between-wrapper">
					<view class="">会员优惠券</view>
					<navigator class="link" url="/pages/annex/vip_coupon/index">查看更多<text class="iconfont icon-ic_rightarrow"></text></navigator>
				</view>
				<scroll-view class="section-bd" scroll-x="true">
					<view v-for="item in memberCoupons" :key="item.id" class="item acea-row row-center-wrapper" :class="{gray: item.is_fail || item.status === '已使用'}">
						<view class="inner">
							<view class="type">{{ item.type_name }}</view>
							<view class="money">
								<text v-if="item.coupon_type==1">￥</text>
								<text class="num">{{item.coupon_type == 1?item.coupon_price:parseFloat(item.coupon_price)/10}}</text>
								<text v-if="item.coupon_type==2">折</text>
							</view>
						</view>
						<view class="text">
							<view v-if="item.use_min_price>0" class="mark">满{{item.use_min_price | moneyFormat}}可用</view>
							<view v-else class="mark">无门槛券</view>
						</view>
					</view>
				</scroll-view>
			</view>
			<view class="px-20" v-if="goodsList.length">
				<recommend class="recommend" title="SVIP商品推荐" :hostProduct="goodsList" saleShow></recommend>
			</view>
		</view>
		<view class="footer" v-if="!userInfo.is_ever_level">
			<view class="acea-row">
				<view class="text acea-row row-middle">
					<view><text class="symbol">¥</text><text class="number">{{ totalPrice }}</text><text class="original">¥{{ parseFloat(svip.price) }}</text></view>
				</view>
				<view class="buy acea-row row-center-wrapper" @tap="pay">{{ type == 'free' ? '立即试用' : '立即支付' }}</view>
			</view>
			<view class="flex-center mt-22 fs-24 text--w111-999">
				<text class="mr-10 fs-32 iconfont"
					:class="[isAgreement ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect']"
					@click="toggleAgreement"></text>
				开通前请确认
				<navigator class="link" url="/pages/annex/vip_agreement/index" hover-class="none">《会员服务协议》</navigator>
			</view>
		</view>
		<view class="footer-placeholder"></view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	import colors from '@/mixins/color.js';
	import {
		mapGetters
	} from "vuex";
	import {
		memberCard,
		memberCardDraw,
		memberCardCreate,
		groomList,
		memberOverdueTime
	} from '@/api/user.js';
	import {
		orderOfflinePayType
	} from '@/api/order.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		openPaySubscribe
	} from '@/utils/SubscribeMessage.js';
	import dayjs from '@/plugin/dayjs/dayjs.min.js';
	import recommend from '@/components/recommend/index.vue';
	import home from '@/components/home/index.vue';
	// #ifdef MP
	import NavBar from '@/components/NavBar';
	// #endif

	export default {
		components: {
			recommend,
			home,
			// #ifdef MP
			NavBar,
			// #endif
		},
		mixins: [colors],
		filters: {
			dateFormat: function(value) {
				return dayjs(value * 1000).format('YYYY-MM-DD');
			},
			moneyFormat: function(value) {
				return parseFloat(value);
			}
		},
		data() {
			return {
				memberType: [],
				userInfo: {},
				memberRights: [],
				memberExplain: [],
				memberCoupons: [],
				isGetFree: null,
				account: '',
				password: '',
				goodsList: [],
				pay_order_id: '',
				totalPrice: '0',
				page: 1,
				limit: 15,
				finished: false,
				memberEndTime: '',
				// #ifdef H5
				isWeixin: this.$wechat.isWeixin(),
				// #endif
				type: '',
				svip: {},
				isShowAuth: false,
				// #ifdef MP
				iconColor: '#FFFFFF',
				isScrolling: false,
				// #endif
				showBack: true,
				showHome: false,
				isAgreement: false,
				homeHide: false
			}
		},
		computed: mapGetters(['isLogin']),
		onLoad() {
			//订阅消息进入返回首页
			let pages = getCurrentPages();
			if(pages.length>1){
				this.showBack = true
				this.showHome = false
			}else{
				this.showBack = false
				this.showHome = true
			}
			this.onLunch();
		},
		onReachBottom() {
			this.groomList();
		},
		onPageScroll(e) {
			uni.$emit('scroll');
			// #ifdef MP
			if (e.scrollTop > 50) {
				this.isScrolling = true;
				this.iconColor = '#333333';
			} else if (e.scrollTop < 50) {
				this.isScrolling = false;
				this.iconColor = '#FFFFFF';
			}
			// #endif
			this.homeHide = true;
		},
		methods: {
			goLogin(){
				toLogin();
			},
			onLunch() {
				this.memberCard();
				this.groomList();
			},
			pay() {
				if (this.isAgreement) {
					this.createMemberCard('weixin');
				} else {
					this.$util.Tips({
						title: '请先阅读会员服务协议'
					});
				}
			},
			scrollToCard() {
				const query = uni.createSelectorQuery().in(this);
				query.select('#card').boundingClientRect(data => {
					uni.pageScrollTo({
						scrollTop: data.top
					});
				}).exec();
			},
			goDetail(id) {
				uni.navigateTo({
					url: `/pages/goods_details/index?id=${id}`
				});
			},
			// 付费会员数据
			memberCard() {
				uni.showLoading({
					title: '正在加载…'
				});
				memberCard().then(res => {
					uni.hideLoading();
					const {
						is_get_free,
						member_coupons,
						member_explain,
						member_rights,
						member_type
					} = res.data;
					this.isGetFree = is_get_free;
					this.userInfo = is_get_free.user_info;
					this.memberRights = member_rights;
					this.memberType = member_type;
					this.memberCoupons = member_coupons;
					this.memberExplain = member_explain;
					if (is_get_free.is_record) {
						this.memberType = this.memberType.filter(item => item.type !== 'free');
					};
					this.totalPrice = parseFloat(this.memberType[0].pre_price);
					this.type = this.memberType[0].type;
					this.totalPrice = parseFloat(this.memberType[0].pre_price);
					this.svip = this.memberType[0];
					memberOverdueTime({
						member_type: this.svip.type,
						vip_day: this.svip.vip_day
					}).then(res => {
						this.memberEndTime = res.data.data;
					});
				}).catch(err => {
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
			},
			groomList() {
				if (this.finished) {
					return;
				}
				uni.showLoading({
					title: '加载中'
				});
				groomList(5, {
					page: this.page,
					limit: this.limit
				}).then(res => {
					uni.hideLoading();
					this.goodsList = this.goodsList.concat(res.data.list);
					this.finished = res.data.list.length < this.limit;
					this.page += 1;
				}).catch(err => {
					uni.hideLoading();
				});
			},
			checkType(svip) {
				this.type = svip.type;
				this.svip = svip;
				this.totalPrice = parseFloat(svip.pre_price)
			},
			getMemberCard() {
				memberCard().then(res => {
					let data = res.data;
					this.memberType = data.member_type;
					this.memberRights = data.member_rights;
					this.isGetFree = data.is_get_free;
				}).catch(err => {

				});
			},
			memberSelect(index) {
				this.memberSelected = index;
			},
			// 卡密兑换
			drawMemberCard() {
				if(this.isLogin){
					uni.navigateTo({
						url: '/pages/annex/vip_paid_active/index'
					})
				}else{
					toLogin();
				}
			},
			// 立即购买
			createMemberCard(type) {
				uni.showLoading({
					title: '正在加载…'
				});
				let query = {
					pay_type: type || this.payMode[0].value,
					type: '1',
					// #ifdef H5
					from: this.isWeixin ? 'weixin' : 'weixinh5',
					// #endif
					// #ifdef MP
					from: 'routine',
					// #endif
					// #ifdef APP-PLUS
					from: 'weixin',
					// #endif
					member_type: this.svip.id,
					price: this.svip.pre_price,
					money: this.svip.price,
					// #ifdef H5
					quitUrl: location.port ? location.protocol + '//' + location.hostname + ':' + location.port +
						'/pages/annex/vip_paid/index' : location.protocol + '//' + location.hostname +
						'/pages/annex/vip_paid/index'
					// #endif
				};
				// #ifdef MP
				openPaySubscribe().then(() => {
					memberCardCreate(query).then(res => {
						uni.hideLoading()
						uni.redirectTo({
							url: `/pages/goods/cashier/index?order_id=${res.data.order_id}&from_type=vip`
						})
					}).catch(err => {
						uni.showToast({
							title: err
						});
					});
				});
				// #endif
				// #ifndef MP
				memberCardCreate(query).then(res => {
					uni.hideLoading()
					uni.redirectTo({
						url: `/pages/goods/cashier/index?order_id=${res.data.order_id}&from_type=vip`
					})
				}).catch(err => {
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
				// #endif
			},
			goRightDetail() {
				uni.navigateTo({
					url: `/pages/annex/vip_paid_rights/index`
				});
			},
			// 同意会员服务协议
			toggleAgreement() {
				this.isAgreement = !this.isAgreement;
			}
		}
	}
</script>

<style lang="scss" scoped>
	.vipPaid {
		padding-bottom: 172rpx;
	}

	.headerBg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		height: 820rpx;
		background: #121212;
		background-image: url("data:image/png;base64,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");
		background-position: right top;
		background-repeat: no-repeat;
		background-size: 566rpx 334rpx;
	}

	.card-section {
		position: relative;
		height: 528rpx;
		padding-top: 48rpx;
		border-bottom-right-radius: 48rpx;

		.vip-paid {
			position: absolute;
			top: 40rpx;
			right: 52rpx;
			width: 229rpx;
			height: 329rpx;
		}

		.expect {
			padding: 32rpx 0 58rpx 36rpx;

			.image {
				display: block;
				width: 414rpx;
				height: 76rpx;
			}

			.mark {
				display: inline-flex;
				align-items: center;
				height: 48rpx;
				padding: 0 24rpx;
				border-radius: 24rpx;
				margin-top: 12rpx;
				background: linear-gradient(-270deg, #E7B667 0%, #FFEAB5 100%);
				font-size: 28rpx;
				line-height: 48rpx;
				color: #7E4B06;

				.number {
					margin: 0 8rpx;
					font-family: SemiBold;
					font-size: 36rpx;
					color: var(--view-theme);
				}
			}
		}

		.wrapper {
			min-height: 450rpx;
			border-radius: 24rpx;
			margin: 0 20rpx;
			background: rgba(255, 255, 255, 0.05);
		}

		.avatar-wrap {
			min-height: 236rpx;
			padding: 62rpx 0 0 40rpx;

			.arrow {
				margin-top: 12rpx;
				font-size: 22rpx;
				line-height: 30rpx;
				color: rgba(254, 227, 174, 0.6);

				.iconfont {
					font-size: 24rpx;
				}
			}

			.text {
				flex: 1;
				margin-left: 15rpx;
				font-size: 22rpx;
				line-height: 30rpx;
				color: #89735B;

				.shopName {
					max-width: 164rpx;
				}

				.name {
					display: inline-block;
					vertical-align: middle;
					font-weight: 500;
					font-size: 32rpx;
					line-height: 44rpx;
					background: linear-gradient(-270deg, #FACB7A 0%, #FFF3D5 100%);
					-webkit-background-clip: text;
					-webkit-text-fill-color: transparent;
				}
			}
		}

		.avatar {
			width: 88rpx;
			height: 88rpx;
			border-radius: 50%;
			margin-right: 20rpx;
		}

		.tips {
			margin-top: 6rpx;
			font-size: 22rpx;
			line-height: 30rpx;
			color: rgba(254, 227, 174, 0.8);
		}

		.info {
			margin-top: 74rpx;
			font-size: 24rpx;
			color: #AE5A2A;
		}

		.num {
			font-size: 36rpx;
		}

		.btn {
			width: 146rpx;
			height: 44rpx;
			border-radius: 22rpx;
			background-color: #FFFFFF;
			line-height: 44rpx;
			text-align: center;
		}
	}

	.right-section {
		.section-hd {
			padding: 32rpx 32rpx 36rpx;
			font-weight: 500;
			font-size: 30rpx;
			line-height: 44rpx;
			color: rgba(250, 204, 125, 0.8);

			.arrow {
				font-weight: 400;
				font-size: 24rpx;
				line-height: 34rpx;
				color: rgba(255, 255, 255, 0.6);
			}

			.iconfont {
				font-size: 24rpx;
			}
		}

		.section-bd {
			padding: 0 0 32rpx;

			.item {
				flex: 1;
				min-width: 0;
			}

			.image-wrap {
				position: relative;
				width: 88rpx;
				height: 88rpx;
				border-radius: 50%;
				margin: 0 auto;

				.image-wrap-inner {
					display: flex;
					justify-content: center;
					align-items: center;
					width: 100%;
					height: 100%;
					border-radius: 50%;
				}
			}

			.image-inner {
				height: 100%;
				border-radius: 50%;
			}

			.image {
				width: 100%;
				height: 100%;
			}

			.text {
				margin-top: 28rpx;
				text-align: center;
				font-size: 20rpx;
				line-height: 28rpx;
				color: rgba(255, 255, 255, 0.4);
			}

			.name {
				margin-bottom: 8rpx;
				font-size: 26rpx;
				line-height: 37rpx;
				color: rgba(255, 255, 255, 0.7);
			}
		}
	}

	.other-section {
		position: relative;
		border-top-left-radius: 48rpx;
		border-top-right-radius: 48rpx;
		background-color: #F5F5F5;
	}

	.type-section {
		padding: 20rpx;

		.type-section-inner {
			border-radius: 24rpx;
			background-color: #FFFFFF;
		}

		.title {
			padding: 32rpx 32rpx 36rpx;
			border-radius: 24rpx 24rpx 0 0;
			background: linear-gradient(180deg, #FFEFCE 0%, #FFFFFF 100%);
			font-weight: bold;
			font-size: 32rpx;
			line-height: 44rpx;
			color: #333333;

			.bold {
				display: inline-block;
				margin-right: 14rpx;
				font-weight: bold;
				font-size: 32rpx;
				line-height: 45rpx;
				color: #333333;

				+view {
					display: inline-block;
				}
			}

			.time {
				margin-left: 14rpx;
				font-size: 24rpx;
				color: #AE5A2A;
			}

			.cash {
				font-weight: 400;
				font-size: 26rpx;
				line-height: 36rpx;
				color: rgba(217, 126, 29, 0.8);
			}
		}

		.scroll {
			white-space: nowrap;
		}

		.item {
			position: relative;
			display: inline-block;
			width: 220rpx;
			padding: 36rpx 0 32rpx;
			border: 1rpx solid #DDDDDD;
			border-radius: 24rpx;
			margin: 16rpx 24rpx 32rpx 0;

			&:first-child {
				margin: 16rpx 24rpx 32rpx 32rpx;
			}

			&:last-child {
				margin: 16rpx 32rpx 32rpx 0;
			}

			&.on {
				border: 2rpx solid #D97E1D;
				background: linear-gradient(180deg, #FFFFFF 0%, #FEF5E6 100%);
			}

			.badge {
				position: absolute;
				top: 0;
				left: 0;
				height: 44rpx;
				padding: 0 12rpx;
				border-radius: 22rpx 22rpx 22rpx 0;
				margin-top: -18rpx;
				background: linear-gradient(90deg, #FF7931 0%, #E93323 100%);
				font-size: 24rpx;
				line-height: 44rpx;
				color: #FFFFFF;
			}

			.name {
				text-align: center;
				font-weight: 500;
				font-size: 28rpx;
				line-height: 40rpx;
				color: #7E4B06;
			}

			.button {
				width: 142rpx;
				height: 52rpx;
				border-radius: 22rpx;
				margin: 18rpx auto 0;
				background-color: #FEE8BC;
				text-align: center;
				font-size: 22rpx;
				line-height: 52rpx;
				color: #333333;
			}
		}

		.new {
			margin-top: 16rpx;
			text-align: center;
			font-family: SemiBold;
			font-size: 36rpx;
			line-height: 62rpx;
			color: #333333;

			.num {
				font-size: 52rpx;
			}
		}

		.old {
			margin-top: 4rpx;
			text-decoration: line-through;
			text-align: center;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #999999;
		}

		.info {
			margin-top: 4rpx;
			text-align: center;
			font-size: 24rpx;
			line-height: 34rpx;
			color: #999999;
		}

		.agree {
			font-size: 22rpx;
			text-align: center;
			color: #797979;

			.link {
				display: inline-block;
			}

			.mark {
				color: #AE5A2A;
			}
		}
	}

	.footer-placeholder {
		height: 152rpx;
		height: calc(152rpx + constant(safe-area-inset-bottom));
		height: calc(152rpx + env(safe-area-inset-bottom));
	}

	.footer {
		position: fixed;
		bottom: 0;
		left: 0;
		width: 100%;
		padding: 22rpx 20rpx;
		padding-bottom: calc(22rpx + constant(safe-area-inset-bottom));
		padding-bottom: calc(22rpx + env(safe-area-inset-bottom));
		background-color: #FFFFFF;

		.text {
			flex: 1;
			height: 108rpx;
			padding-left: 48rpx;
			border-radius: 54rpx 0 0 54rpx;
			background-color: #222222;
			font-size: 28rpx;
			color: #FACC7D;
		}

		.symbol {
			font-weight: 600;
			font-size: 40rpx;
		}

		.number {
			font-family: SemiBold;
			font-size: 56rpx;
		}

		.original {
			margin-left: 16rpx;
			text-decoration: line-through;
			font-size: 28rpx;
			color: rgba(255, 255, 255, 0.4);
		}

		.buy {
			position: relative;
			width: 266rpx;
			height: 108rpx;
			border-radius: 0 54rpx 54rpx 0;
			background: linear-gradient(to right, #FFDD9C, #EEA82F);
			font-weight: 500;
			font-size: 36rpx;
			color: #7E4B06;

			&::before {
				content: "";
				position: absolute;
				top: 0;
				left: 0;
				border-width: 54rpx 20rpx;
				border-style: solid;
				border-color: #222222 transparent transparent #222222;
			}
		}

		.icon-a-ic_CompleteSelect,
		.link {
			color: #D88D0E;
		}
	}

	.coupon-section {
		border-radius: 24rpx;
		margin: 0 20rpx;
		background-color: #FFFFFF;

		.section-hd {
			padding: 32rpx 32rpx 24rpx;
			font-weight: bold;
			font-size: 32rpx;
			line-height: 44rpx;
			color: #333333;

			.link {
				font-weight: 400;
				font-size: 26rpx;
				line-height: 36rpx;
				color: #666666;

				.iconfont {
					font-size: 24rpx;
				}
			}
		}

		.section-bd {
			padding-bottom: 24rpx;
			white-space: nowrap;

			.item {
				position: relative;
				display: inline-block;
				width: 151rpx;
				padding: 0 6rpx;
				border-radius: 24rpx;
				margin: 20rpx 14rpx 0 0;
				background-color: #E0A558;

				&:first-child {
					margin-left: 32rpx;
				}

				.inner {
					padding-bottom: 76rpx;
					border: 2rpx solid #FFEDCA;
					border-radius: 12rpx;
					margin-top: -20rpx;
					background-color: #FFFFFF;

					.type {
						width: 96rpx;
						height: 38rpx;
						border-radius: 0 0 12rpx 12rpx;
						background-color: rgba(224, 165, 88, 0.2);
						margin: 0 auto;
						text-align: center;
						font-size: 20rpx;
						line-height: 38rpx;
						color: #333333;
					}
				}

				&.gray {
					background-image: url("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAWgAAACUCAMAAACJHEMxAAAAeFBMVEUAAAC/v7/f39/V1dXf39/c3Nzh4eHc3Nze3t7a2tre3t7f39/e3t7d3d3e3t7d3d3f39/e3t7e3t7e3t7d3d3e3t7e3t7d3d3d3d3e3t7d3d3e3t7f39/h4eHi4uLn5+fo6Ojp6enq6urs7Ozu7u7v7+/w8PDx8fEswD7yAAAAG3RSTlMABAgMECQrLC8we4+Tl6uvr7/Dz9PT5+vz9/vtB+63AAADZElEQVR42u3Zy3baMBSFYbmmsaFuIRcKxdixi5He/w0LGJtLljrA1mby7xFZyehbyjkS25hD0uzjT06CZP2RpabNt59wBM0mi47O0RsUofN6lM5wCJ/MmOkGBsH0SDjQqiO9BEGRd5NEcTJbIREuv2dJHCXtDW+yuPlVUTfWkYdim6q4wfw1Mde5lq5QHmZdXWEuzG0ml+mxc7Yut/zLP5Zt+de63WVuTO6gzaz7Ve32BV5DUuxdf6Zn985m2v2VszgPlba9YfIFOu4PdIXU0NSuPn+KvdCN40APTukaL3Q3OqzDaXic9Y6Obhk6oMeAdr5l2F/vgB4VenV/vZvnQIeAzue353meAx0GOp+fz3Qaxen1l0pAjwydr2ZpHKXmf39CxoBuAzTQQBOggQZ6etcZAj029KkznH7pDIEeGdrXGQI9LvSCL5Uk0P7OEOhRof2dIdCjQvs7Q6BHhY6BfhY0o0M0OliGmmXI9S4MNJ2h6sFCZ6h6gtMZiqDpDFXQbYAGGmgCNNBA0xmGhqYz1EDTGWqg6Qw10HSGImg6QxE0naEIms7wadCMDtHoYBlqliHXuzDQdIaqBwudoeoJTmcogqYzVEG3ARpooAnQQANNZxgams5QA01nqIGmM9RA0xmKoOkMRdB0hiJoOsOnQTM6RKODZahZhlzvwkDTGaoeLHSGqic4naEIms5QBd0GaKCBJkADDTSdYWhoOkMNNJ2hBprOUANNZyiCpjMUQdMZiqDpDJ8GzegQjQ6WoWYZcr0LA01nqHqw0BmqnuB0hiJoOkMVdBuggQaaAA000HSGoaHpDDXQdIYaaDpDDTSdoQiazlAETWcogqYzfBo0o0M0OliGmmXI9S4MNJ2h6sFCZ6h6gtMZiqDpDFXQbYAGGmgCNNBA0xmGhqYz1EDTGWqg6Qw10HSGImh/Z2iBHgPanj/4O8PGlTgNTema8yd/Z1i7CqihqXpEf2dYOFsgNSyFdYV3dHTL8HCk90gPc967Ovctw8nlcbhz9rPc4vVYtuWndbv+R39neJww1pEBsddbztsZns5+3WD9qHJT3U7erjN8uesMydg5dYYvZomEIu8mA0GRzEw3KITP5nCf5khLDrQx0RsOofMaHe8dEWc68NzIou57jmy5xiNM1ssf34/G/wD2nqj2ECBXfgAAAABJRU5ErkJggg==");

					.btn {
						color: #9F9F9F;
					}

					.money {
						color: #fff;

						.num {
							color: #fff;
						}
					}

					.name {
						color: #fff;
					}

					.text {
						color: #9F9F9F;
					}

					.mark {
						color: #A8A8A8;
					}
				}

				.btn {
					width: 52rpx;
					padding-right: 14rpx;
					padding-left: 14rpx;
					white-space: pre-wrap;
					font-size: 24rpx;
					color: #5D3324;
				}

				.text {
					position: absolute;
					bottom: 0;
					left: 0;
					width: 100%;
					height: 76rpx;
					padding-top: 30rpx;
					background-image: url("data:image/png;base64,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");
					background-repeat: no-repeat;
					background-size: 100% 100%;
				}

				.name {
					overflow: hidden;
					white-space: nowrap;
					text-overflow: ellipsis;
					font-weight: bold;
					font-size: 28rpx;
					line-height: 40rpx;
					color: #5D3324;
				}

				.mark {
					text-align: center;
					font-weight: 500;
					font-size: 22rpx;
					line-height: 30rpx;
					color: rgba(250, 204, 125, 0.8);
				}

				.money {
					margin-top: 16rpx;
					text-align: center;
					font-weight: 500;
					font-size: 28rpx;
					line-height: 44rpx;
					color: #E0A558;
				}

				.num {
					font-family: SemiBold;
					font-size: 44rpx;
				}
			}
		}
	}

	.recommend ::v-deep  .fw-500 {
		font-weight: bold;
	}
</style>