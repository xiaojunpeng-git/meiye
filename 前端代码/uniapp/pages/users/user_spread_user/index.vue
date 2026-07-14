<template>
	<view :style="[colorStyle]">
		<!-- #ifdef MP || APP-PLUS -->
		<NavBar titleText="分销中心" :iconColor="iconColor" :textColor="iconColor" :isScrolling="isScrolling" showBack></NavBar>
		<!-- #endif -->
		<view class="headerBg"></view>
		<view class='my-promotion'>
			<view class="price-wrapper">
				<view class="navigator acea-row row-middle row-right">
					<view class="inner acea-row row-middle" @click="jumbPath(10)">
						<text class="iconfont icon-huiyuandengji"></text>
						{{levelInfo.name||'等级未解锁'}}
						<text class="iconfont icon-ic_rightarrow"></text>
					</view>
				</view>
				<view class="price-box" :class="!headerStatus ? 'header-height':''" :style="{backgroundImage:'url('+imgHost+'/statics/images/spread_bg.png)'}">
					<view class="box-top acea-row row-center">
						<view class="" @click="jumbPath(0)">可提现</view>
					</view>
					<view class="com-count acea-row row-center" @click="jumbPath(6)">
						{{userInfo.commissionCount || '0.00'}}
					</view>
					<view class="box-btn">
						<view class="item acea-row row-column row-middle" @click="jumbPath(6)">
							<view class="text">
								累计佣金(元)
							</view>
							<view class="num">
								{{userInfo.accumulate || '0.00'}}
								<text class="iconfont icon-ic_rightarrow fs-28"></text>
							</view>
						</view>
						<view class="item acea-row row-column row-middle">
							<view class="text">
								冻结佣金(元)
							</view>
							<view class="num">
								{{userInfo.broken_commission || '0.00'}}
							</view>
						</view>
					</view>
					<view class="btn-wrap">
						<view class="btn acea-row row-center-wrapper" @click="jumbPath(0)">
							立即提现
						</view>
					</view>
				</view>
			</view>
			<view class="statistics acea-row">
				<view class="item mb acea-row row-column row-center-wrapper" @click="jumbPath(8)">
					<view class="img">
						<image :src="imgHost+'/statics/images/spread_dd.png'" class="image"></image>
					</view>
					<view class="item-r">
						<view class="text">分销订单</view>
					</view>
				</view>
				<view class="item mb acea-row row-column row-center-wrapper" @click="jumbPath(9)">
					<view class="img">
						<image :src="imgHost+'/statics/images/spread_td.png'" class="image"></image>
					</view>
					<view class="item-r">
						<view class="text">我的团队</view>
					</view>
				</view>
				<view class="item mb acea-row row-column row-center-wrapper" @click="jumbPath(2)">
					<view class="img">
						<image :src="imgHost+'/statics/images/spread_ph.png'" class="image"></image>
					</view>
					<view class="item-r">
						<view class="text">佣金排行</view>
					</view>
				</view>
				<view class="item mb acea-row row-column row-center-wrapper" @click="jumbPath(12)">
					<view class="img">
						<image :src="imgHost+'/statics/images/spread_yj.png'" class="image"></image>
						<uni-badge class="uni-badge-left-margin" v-if="userInfo.un_receive_count>0" :text="userInfo.un_receive_count"></uni-badge>
					</view>
					<view class="item-r">
						<view class="text">提现记录</view>
					</view>
				</view>
				<view class="item mb acea-row row-column row-center-wrapper" @click="jumbPath(1)">
					<view class="img">
						<image :src="imgHost+'/statics/images/spread_tgr.png'" class="image"></image>
					</view>
					<view class="item-r">
						<view class="text">推广人排行</view>
					</view>
				</view>
				<view class="item mb acea-row row-column row-center-wrapper" @click="jumbPath(5)">
					<view class="img">
						<image :src="imgHost+'/statics/images/spread_yq.png'" class="image"></image>
					</view>
					<view class="item-r">
						<view class="text">邀请好友</view>
					</view>
				</view>
				<view class="item acea-row row-column row-center-wrapper" @click="jumbPath(10)" v-if="headerStatus">
					<view class="img">
						<image :src="imgHost+'/statics/images/spread_dj.png'" class="image"></image>
					</view>
					<view class="item-r">
						<view class="text">分销等级</view>
					</view>
				</view>
				<view class="item acea-row row-column row-center-wrapper" @click="jumbPath(11)">
					<view class="img">
						<image :src="imgHost+'/statics/images/spread_sm.png'" class="image"></image>
					</view>
					<view class="item-r">
						<view class="text">分销说明</view>
					</view>
				</view>
			</view>
		</view>
		<task :inv-show="taskShow" :task="task" @inv-close="()=>{taskShow= false}"></task>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import {
		getUserInfo,
		agentLevelList,
		agentLevelTaskList,
		moneyList,
		spreadOrder,
		spreadPeople
	} from '@/api/user.js';
	import {
		openExtrctSubscribe
	} from '@/utils/SubscribeMessage.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import task from './components/task.vue'
	import {
		mapGetters
	} from "vuex";
	import home from '@/components/home';
	import NavBar from '@/components/NavBar.vue';
	import colors from '@/mixins/color.js'
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		components: {
			home,
			task,
			NavBar,
		},
		mixins: [colors],
		data() {
			return {
				// #ifdef MP || APP-PLUS
				getHeight: this.$util.getWXStatusHeight(),
				iconColor: '#000000',
				isScrolling: false,
				// #endif
				imgHost: HTTP_REQUEST_URL,
				userInfo: {},
				taskShow: false,
				yesterdayPrice: 0.00,
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				distributionLevel: [],
				levelList: [],
				task: [],
				levelInfo: {},
				tabs: [{
					name: '佣金',
				}, {
					name: '订单'
				}, {
					name: '推广人'
				}],
				listData: [],
				sel: 0,
				speedAll: 0,
				headerStatus: false,
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad() {
			if (this.isLogin) {
				this.agentLevelList()
				this.clickTab(0);
			} else {
				toLogin()
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		methods: {
			agentLevelList() {
				agentLevelList().then(res => {
					const {
						level_info,
						level_list,
						task,
						user
					} = res.data;
					this.levelInfo = level_info;
					this.userInfo = user;
					this.levelList = level_list;
					this.headerStatus = level_list.length ? true : false;
					this.level_id = level_info.id || 0;
					const index = level_list.findIndex((
							grade, v
						) =>
						grade.id === user.agent_level
					);
					if (index !== -1) {
						this.swiperIndex = index === -1 ? 0 : (index + 1);
					}
					let info = this.levelList[index === -1 ? 0 : (index + 1)];
					this.levelInfo.icon = info.icon;
					this.level_id = (info !== undefined ? info.id : 0) || 0;
					this.getTask()
				});
			},
			/**
			 * 获取任务要求
			 */
			getTask() {
				let that = this;
				that.taskNum = 0
				agentLevelTaskList(that.level_id).then(res => {
					that.task = res.data.list
					that.speedAll = res.data.speedAll
				});
			},
			onLoadFun() {
				this.agentLevelList()
				this.clickTab(0);
				this.isShowAuth = false;
			},
			//跳转
			jumbPath(type) {
				let path = [
					'/pages/users/user_cash/index',
					'/pages/users/promoter_rank/index',
					'/pages/users/commission_rank/index',
					'/pages/users/user_spread_code/index',
					'/pages/users/user_vip/index',
					'/pages/users/user_spread_code/index',
					'/pages/users/user_spread_money/index?type=1',
					'/pages/users/user_spread_money/index?type=4',
					'/pages/users/promoter-order/index',
					'/pages/users/promoter-list/index',
					'/pages/users/user_distribution_level/index',
					'/pages/users/user_distribution_info/index',
					'/pages/users/user_withdrawal/index',
				]
				if(type==10 && !this.headerStatus){
					return false
				}
				uni.navigateTo({
					url: path[type]
				})
			},
			// 授权关闭
			authColse(e) {
				this.isShowAuth = e
			},
			openSubscribe(page) {
				uni.showLoading({
					title: '正在加载',
				})
				openExtrctSubscribe().then(res => {
					uni.hideLoading();
					uni.navigateTo({
						url: page,
					});
				}).catch(() => {
					uni.hideLoading();
				});
			},
			/**
			 * 获取个人用户信息
			 */
			getUserInfo() {
				let that = this;
				getUserInfo().then(res => {
					that.$set(that, 'userInfo', res.data);
					if (!res.data.spread_status) {
						that.$util.Tips({
							title: "您目前暂无推广权限"
						}, {
							tab: 4,
							url: '/pages/index/index'
						});
					}
				});
			},
			clickTab(index) {
				this.sel = index
				let mets = [moneyList, spreadOrder, spreadPeople]
				let data = {
					keyword: "",
					start: 0,
					stop: 0,
					page: 1,
					limit: 10
				}
				if (index == 2) {
					data = {
						...data,
						grade: 0,
						sort: '',
					}
				}
				mets[index](data, 3).then(res => {
					this.listData = res.data.list
				})
			},
      onhomehide() {
				this.homeHide = false;
			}
		},
		// #ifdef MP
		onPageScroll(e) {
			if (e.scrollTop > 50) {
				this.isScrolling = true;
				this.iconColor = '#000000';
			} else if (e.scrollTop < 50) {
				this.isScrolling = false;
				this.iconColor = '#FFFFFF';
			}
      this.homeHide = true;
		},
		// #endif
	}
</script>

<style scoped lang="scss">
	.headerBg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		height: 428rpx;
		background: linear-gradient(360deg, rgba(233, 51, 35, 0) 0%, rgba(233, 51, 35, 0.2) 100%);
	}

	.my-promotion {
		padding: 24rpx 20rpx;

		.header {
			background: #212230 url('../static/fxbg.png') no-repeat;
			background-size: 100% 100%;
			padding: 48rpx 30rpx;
			color: #fff;
			position: relative;
			height: 328rpx;

			.user-msg {
				display: flex;
				align-items: flex-start;
				width: 100%;

				.acator {
					width: 90rpx;
					height: 90rpx;
					margin-right: 20rpx;

					image {
						width: 100%;
						height: 100%;
						border-radius: 50%;
					}
				}

				.msg {
					display: flex;
					flex-direction: column;

					.name {
						font-size: 30rpx;
						font-weight: bold;
					}

					.process {
						width: 380rpx;
						height: 6rpx;
						border-radius: 6rpx;
						background: #4D4E59;
						margin: 20rpx 0;

						.fill {
							height: 100%;
							border-radius: 6rpx;
							background-color: #fff;
						}

					}

					.level-info {
						font-size: 20rpx;

						.mr20 {
							margin-right: 40rpx;
						}
					}
				}

				.invite {
					display: flex;
					align-items: center;
					position: absolute;
					right: 0rpx;
					background: rgba(255, 255, 255, 0.14);
					border-radius: 32px 0px 0px 32px;
					color: #FFFFFF;
					padding: 10rpx 16rpx 10rpx 8rpx;

					.poster-in {
						width: 20rpx;
						height: 20rpx;
						display: flex;
						align-items: center;
						margin-right: 8rpx;

						image {
							width: 100%;
							height: 100%;
						}
					}

					.text {
						font-size: 20rpx;
					}
				}
			}

			.tesk {
				position: absolute;
				bottom: 0;
				width: 690rpx;
				height: 128rpx;
				background: linear-gradient(135deg, #FEE8C7 0%, #FFBD6B 100%);
				border-radius: 6px 6px 0px 0px;
				padding: 24rpx 30rpx;
				display: flex;
				justify-content: space-between;
				align-items: center;

				.line {
					width: 1px;
					height: 74rpx;
					background: linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, #9B5900 52%, rgba(255, 255, 255, 0) 100%);
					opacity: 0.2;
					margin: 0 26rpx;
				}

				.tesk-l {
					display: flex;
					align-items: center;

					.fx-leavel {
						display: flex;
						align-items: center;
						color: #9B5900;
						font-size: 30rpx;
						font-weight: bold;

						.point {
							margin-left: 20rpx;
							width: 0;
							height: 0;
							border-top: 8rpx solid transparent;
							border-left: 8rpx solid #9B5900;
							border-bottom: 8rpx solid transparent;
						}
					}

					.fx-trip {
						color: #9B5900;
						font-size: 24rpx;
						margin-top: 14rpx;
					}

					.upgrade {
						width: 68rpx;
						height: 68rpx;

						image {
							width: 100%;
							height: 100%;
						}
					}
				}

				.tesk-r {
					padding: 10rpx 20rpx;
					color: #9B5900;
					font-size: 24rpx;
					font-weight: 400;
					text-align: center;
					background: #FFFFFF;
					border-radius: 40rpx;
				}
			}
		}

		.price-wrapper {
			position: relative;

			.navigator {
				position: absolute;
				top: 8rpx;
				right: 6rpx;
				width: 246rpx;
				height: 68rpx;
				padding: 0 16rpx 18rpx 0;
				border-top-right-radius: 32rpx;
				background: linear-gradient(270deg, #F6C363 0%, #FEF8E2 100%, rgba(216, 216, 216, 0) 100%);
				font-size: 18rpx;
				color: #B33F1B;

				.inner {
					position: relative;
					z-index: 1;
				}

				.image {
					width: 32rpx;
					height: 32rpx;
					margin-right: 8rpx;
				}

				.icon-huiyuandengji {
					font-size: 32rpx;
					margin-right: 8rpx;
				}

				.icon-ic_rightarrow {
					font-size: 20rpx;
				}
			}
		}

		.price-box.header-height {
			position: relative;
		}

		.price-box {
			position: relative;
			height: 370rpx;
			padding: 70rpx 0 0;
			background-repeat: no-repeat;
			background-size: 100% 100%;

			.box-top {
				font-size: 30rpx;
				line-height: 42rpx;
				color: rgba(255, 255, 255, 0.6);
			}

			.com-count {
				margin-top: 16rpx;
				color: #fff;
				font-size: 76rpx;
				font-family: 'Regular';
				font-weight: 600;
			}

			.box-btn {
				display: flex;
				justify-content: space-between;
				margin-top: 52rpx;

				.in {
					display: flex;
					justify-content: center;
					align-items: center;
					flex-direction: column;
				}

				.item {
					width: 210rpx;

					.text {
						margin-bottom: 8rpx;
						font-size: 24rpx;
						line-height: 34rpx;
						color: rgba(255, 255, 255, 0.6);
					}

					.num {
						font-family: SemiBold;
						font-size: 34rpx;
						line-height: 34rpx;
						color: #FFFFFF;
					}
				}
			}

			.btn-wrap {
				position: absolute;
				top: 100%;
				left: 50%;
				padding: 16rpx;
				border-radius: 52rpx;
				background: #F5F5F5;
				transform: translate(-50%, -50%);
			}

			.btn {
				width: 256rpx;
				height: 72rpx;
				border-radius: 44rpx;
				background-color: #E93323;
				font-size: 30rpx;
				color: #FFFFFF;
			}
		}

		.statistics {
			margin: 66rpx -20rpx 0 0;
			border-radius: 12px;

			.mb {
				margin-bottom: 20rpx;
			}

			.item {
				width: 345rpx;
				height: 224rpx;
				border-radius: 24rpx;
				margin-right: 20rpx;
				background-color: #FFFFFF;

				.img {
					width: 80rpx;
					height: 80rpx;
					position: relative;

					.image {
						width: 100%;
						height: 100%;
					}
					
					.uni-badge-left-margin {
						position: absolute;
						top: -10rpx;
						right: -12rpx;
						/deep/ .uni-badge--error {
							background-color: #E93323 !important;
						}
						.uni-badge {
							color: #E93323;
							border: 1px solid #E93323;
							z-index: 29;
						}
					}

				}

				.item-r {
					.text {
						margin-top: 20rpx;
						font-size: 26rpx;
						line-height: 36rpx;
						color: #333333;
					}

					.trip {
						color: #999999;
						font-size: 22rpx;
					}
				}
			}
		}

		// ---------------------------------------
		.data {
			margin: 28rpx 30rpx;
			background-color: #fff;
			width: 690rpx;
			border-radius: 12rpx;

			.data-num {
				height: 168rpx;
				background-size: 100% 100%;
				display: flex;
				align-items: center;
				justify-content: space-around;
				color: #fff;
				font-size: 24rpx;

				.num-color {
					margin-top: 20rpx;
					font-weight: bold;
					font-size: 36rpx;
				}
			}

			.data-money {
				display: flex;
				justify-content: space-between;
				color: #333;
				padding: 16rpx 30rpx;
				font-size: 24rpx;

				.money {
					display: flex;
					align-items: center;
					color: #333333;
				}

				.money-num {
					color: #E93323;
					font-size: 28rpx;
					font-weight: bold;
					padding-left: 20rpx;

				}

				.btn {
					width: 160rpx;
					background: linear-gradient(135deg, #FEA21F 0%, #FE7A18 100%);
					border-radius: 38rpx;
					color: #fff;
					text-align: center;
					padding: 16rpx 0;
					font-size: 26rpx;
				}
			}
		}

		.invites {
			width: 690rpx;
			margin: 28rpx 30rpx;
			background-color: #fff;
			border-radius: 12rpx;
			font-size: 26rpx;
			color: #333;
			padding: 40rpx 46rpx;

			.invite-list {
				display: flex;

				.item {
					margin-right: 48rpx;
					display: flex;
					flex-direction: column;
					align-items: center;

					.img {
						width: 60rpx;
						height: 60rpx;
						margin-bottom: 24rpx;

						image {
							width: 100%;
							height: 100%;
							border-radius: 50%;
						}
					}
				}
			}
		}

		.list {
			width: 690rpx;
			margin: 28rpx 30rpx;
			background-color: #fff;
			border-radius: 12rpx;
			font-size: 28rpx;

			.tab-list {
				display: flex;
				justify-content: space-between;
				padding: 32rpx 30rpx 0 30rpx;
				color: #999999;

				.tab {
					display: flex;

					.item {
						margin-right: 48rpx;
						transition: all 0.3s;

						.line {
							width: 54rpx;
							height: 4rpx;
							margin: 12rpx auto 0 auto;
							border-radius: 4px;
						}

						.line.on {
							background-color: #E93323;
						}
					}

					.item .on {
						font-size: 32rpx;
						font-weight: bold;
						color: #E93323;
					}
				}
			}

			.more {
				display: flex;
				align-items: center;
				font-size: 26rpx;

				.icon-xiangyou {
					font-size: 24rpx;
				}
			}
		}
	}
</style>