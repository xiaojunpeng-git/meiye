<template>
	<view class="sign">
		<!-- #ifdef MP -->
		<NavBar titleText="签到" :iconColor="iconColor" :textColor="iconColor" :isScrolling="isScrolling" showBack></NavBar>
		<!-- #endif -->
		<view class="headerBg" :style="{ backgroundImage: `url(${imgHost}/statics/images/users/sign-bg.png)` }"></view>
		<view class="sign-content">
			<view class="continue">
				<view class="top">连续签到</view>
				<view class="bottom">
					<text class="num">{{ continuousSignDays }}</text>
					天
				</view>
			</view>
			<view v-if="signRemindSwitch" class="remind">
				<view class="top">
					<switch :checked="!!signRemindStatus" color="#FAAD14" style="transform:scale(0.7)" @change="noticeChange" />
				</view>
				<view class="bottom">签到提醒</view>
			</view>
			<view class="total" :style="{ backgroundImage: `url(${imgHost}/statics/images/users/sign-total.png)` }">
				<view class="">
					<text class="num">{{ cumulativeSignDays }}</text>
					天
				</view>
				<view class="bubble">
					<template v-for="(item,index) in bubble">
						<view v-if="item.value" :key="index" class="item">
							<view class="top" :style="{ backgroundImage: `url(${imgHost}/statics/images/users/sign-bubble.png)` }">{{ item.value }}</view>
							<view class="bottom">{{ item.name }}</view>
						</view>
					</template>
				</view>
			</view>
			<view class="look" @click="toggle('bottom')">
				<view class="inner">
					<text class="txt">查看我的签到日历</text>
					<text class="txt iconfont icon-ic_rightarrow"></text>
				</view>
			</view>
			<view :class="{ disabled: userInfo.is_day_sgin }" :style="{
			backgroundImage: userInfo.is_day_sgin ? `url(${imgHost}/statics/images/users/sign-disabled-btn-bg.png)` : `url(${imgHost}/statics/images/users/sign-btn-bg.png)`,
		}" class="now">
				<view v-if="userInfo.is_day_sgin" class="btn">签到成功</view>
				<view v-if="!userInfo.is_day_sgin" class="btn" @click="goSign">立即签到</view>
				<view v-if="!userInfo.is_day_sgin" class="hand" :style="{ backgroundImage: `url(${imgHost}/statics/images/users/hand.png)` }"></view>
			</view>
			<view class="board">
				<view class="board-inner acea-row row-middle">
					<scroll-view class="scroll-view" scroll-x="true" :scroll-into-view="scrollIntoId">
						<view v-for="(item,index) in signList" :key="index" :id="`item${index}`" class="item">
							<view class="top">
								<image v-if="item.is_sign" src="../static/sign-today.png" class="image"></image>
								<image v-else-if="item.type == 1" src="../../../static/images/sign-icon-01.png" class="image"></image>
								<image v-else-if="item.type == 2" src="../../../static/images/sign-icon-02.png" class="image"></image>
							</view>
							<view class="bottom">{{ item.day }}</view>
						</view>
					</scroll-view>
				</view>
			</view>
			<view class="record">
				<view class="acea-row row-between head">
					<view class="">签到记录</view>
					<view class="link" @click="sginList">
						更多
						<text class="iconfont icon-ic_rightarrow"></text>
					</view>
				</view>
				<view class="body" v-if="signRecord.length">
					<view v-for="(item,index) in signRecord" :key="index" class="flex-y-center item">
						<image src="../static/sign-record.png" class="w-80 h-80"></image>
						<view class="flex-1 pl-16 fs-28 lh-40rpx">
							<view>{{item.title}}</view>
							<view class="pt-8 fs-24 lh-34rpx text--w111-999">{{ item.add_time }}</view>
						</view>
						<view class="flex justify-end align-center">
							<view class="flex-col flex-center num-item Regular fs-32" v-show="item.exp_num > 0">
								<image src="@/static/images/sign-icon-02.png" class="w-36 h-36"></image>
								<text class="pt-6">+{{item.exp_num}}</text>
							</view>
							<view class="flex-col flex-center num-item Regular fs-32" v-show="item.number > 0">
								<image src="@/static/images/sign-icon-01.png" class="w-36 h-36"></image>
								<text class="pt-6">+{{item.number}}</text>
							</view>
						</view>
					</view>
				</view>
				<emptyPage title="暂无签到记录~" v-else></emptyPage>
			</view>
		</view>
		<uni-popup ref="popup" background-color="rgba(255,255,255,0)" @change="change">
			<view class="popup-content">
				<view class="daynumber">
					已连续签到
					<text class="sum-count">{{ sumCount }}</text>
					天
				</view>
				<base-calendar v-if="calendarVisible" :yearMonth="targetDate" :dataSource="signData" @dateChange="getSignCalendar" @clickChange="clickSign"></base-calendar>
			</view>
		</uni-popup>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	import {
		mapGetters
	} from 'vuex';
	import {
		postSignUser,
		getSignConfig,
		setSignIntegral,
		signRemind,
		getSignList,
		getSignCalendar
	} from '@/api/user.js';
	import BaseCalendar from '../components/baseCalendar/index.vue';
	import emptyPage from '@/components/emptyPage.vue';
	import home from '@/components/home/index.vue';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import {
		toLogin
	} from '@/libs/login.js';
	// #ifdef MP
	import NavBar from '@/components/NavBar.vue';
	import {
		openSignSubscribe
	} from '@/utils/SubscribeMessage.js';
	// #endif
	export default {
		components: {
			BaseCalendar,
			emptyPage,
			home,
			// #ifdef MP
			NavBar,
			// #endif
		},
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				continuousSignDays: 0,
				cumulativeSignDays: 0,
				bubble: [],
				signList: [],
				userInfo: {},
				signRecord: [],
				signRemindStatus: 0,
				signRemindSwitch: 0,
				// 日历组件数据
				targetDate: parseInt(new Date().getFullYear()) + '-' + parseInt(new Date().getMonth() + 1), //本月
				sumCount: 0,
				signData: [],
				// #ifdef MP
				iconColor: '#333333',
				isScrolling: false,
				// #endif
				calendarVisible: false,
				homeHide: false,
				scrollIntoId: '',
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad() {
			this.getUserInfo();
			this.getSignSysteam();
			this.getSignList();
			//获取当前用户当前任务的签到状态
			this.getSignCalendar(this.targetDate);
		},
		onPageScroll(e) {
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
			sginList() {
				if (this.isLogin) {
					uni.navigateTo({
						url: `/pages/users/user_sgin_list/index`
					});
				} else {
					toLogin();
				}
			},
			// 日历签到弹窗
			toggle(type) {
				// open 方法传入参数 等同在 uni-popup 组件上绑定 type属性
				this.$refs.popup.open(type);
				this.getSignCalendar(this.targetDate);
			},
			// 弹窗状态改变
			change(e) {
				console.log('当前模式：' + e.type + ',状态：' + e.show);
				this.calendarVisible = e.show
			},
			// 日历操作
			clickSign(day) {
				this.signData.push(day);
				this.sumCount++;
			},
			//当模板的时候可以直接引入，然后触发子组件事件到父界面去控制数据
			//获取当前用户该任务的签到数组
			getData(yearAndMonth) {
				let y = yearAndMonth.split('-')[0];
				let m = yearAndMonth.split('-')[1];
				this.sumCount = 88;

				if (yearAndMonth === this.targetDate) {
					//这是我造的假数据！！！
					const num = ['1', '04', '5', '13', '14', '15'],
						newSign = [],
						today = new Date().getDate();
					for (let i = 0; i < 6; i++) {
						if (parseInt(num[i]) > today) {
							// 过滤掉今天之后的日子
							break;
						}
						newSign.push(this.targetDate + '-' + num[i]);
					}
					//-------------最后传给组件的格式看这里
					this.signData = newSign;
				} else {
					this.signData = [];
				}
			},
			getSignSysteam: function() {
				getSignConfig().then((res) => {
					const {
						continuousSignDays,
						cumulativeSignDays,
						signRemindStatus,
						signRemindSwitch,
						signData,
						signList,
						signMode,
					} = res.data;
					let weekday = ['周一', '周二', '周三', '周四', '周五', '周六', '周日'];
					const now = new Date();
					const day = now.getDay();
					weekday[day ? day - 1 : 6] = '今天';
					if (signMode == 1) {
						signList.forEach((item, index) => {
							item.day = weekday[index];
						});
					}
					this.signList = signList;
					this.$nextTick(() => {
						const date = now.getDate();
						if (date == 1 || date == signList.length) {
							this.scrollIntoId = `item${date - 1}`;
						} else {
							this.scrollIntoId = `item${date - 4}`;
						}
					});
					this.continuousSignDays = continuousSignDays;
					this.cumulativeSignDays = cumulativeSignDays;
					this.signRemindStatus = !!signRemindStatus;
					this.signRemindSwitch = signRemindSwitch;
					let name = '';
					let value = '';
					let bubble = Object.keys(signData).map((key) => {
						name = '';
						value = signData[key];
						switch (key) {
							case 'sign_point':
								name = '签到领积分';
								break;
							case 'sign_exp':
								name = '签到领经验';
								break;
						}
						return {
							name,
							value
						};
					});
					this.bubble = bubble;
				});
			},
			getUserInfo: function() {
				let that = this;
				postSignUser({
					sign: 1
				}).then((res) => {
					that.$set(that, 'userInfo', res.data);
				});
			},
			goSign: function(e) {
				let that = this,
					sum_sgin_day = that.userInfo.sum_sgin_day;
				if (that.userInfo.is_day_sgin)
					return this.$util.Tips({
						title: '您今日已签到!'
					});
				setSignIntegral()
					.then((res) => {
						this.$util.Tips({
							title: res.data.sign_exp ? `获得${res.data.sign_exp}经验` : `获得${res.data.sign_point}积分`
						});
						that.getSignSysteam();
						that.getSignList();
						this.getUserInfo();
						this.getSignCalendar(this.targetDate);
					}).catch(err => {
						return this.$util.Tips({
							title: err
						});
					});
			},
			noticeChange(e) {
				// #ifdef MP
				openSignSubscribe().then(() => {
					this.signRemind(Number(e.detail.value));
				});
				// #endif
				// #ifndef MP
				this.signRemind(Number(e.detail.value));
				// #endif
			},
			signRemind(value) {
				signRemind(value).then((res) => {
					this.$util.Tips({
						title: `签到提醒${value ? '开启' : '关闭'}`
					});
				});
			},
			getSignList: function() {
				let that = this;
				getSignList({
					page: 1,
					limit: 10
				}).then((res) => {
					that.$set(that, 'signRecord', res.data);
				});
			},
			getSignCalendar(yearAndMonth) {
				getSignCalendar({
					time: yearAndMonth
				}).then(res => {
					const {
						continuousSignDays,
						signList,
					} = res.data;
					this.sumCount = continuousSignDays;
					let signDayList = signList.filter(item => item.is_sign);
					let signDateList = signDayList.map(item => {
						return item.day.split('/')[1];
					});
					let signData = signDateList.map(item => {
						return yearAndMonth + '-' + item;
					});
					this.signData = signData;
				});
			},
		}
	};
</script>

<style lang="scss" scoped>
	.headerBg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		height: 1056rpx;
		background-repeat: no-repeat;
		background-size: 100% 100%;

	}

	.sign-content {
		position: relative;
		padding-top: 44rpx;
	}

	.continue {
		position: absolute;
		top: 26rpx;
		left: 0;
		width: 112rpx;
		text-align: center;
		font-weight: 500;
		font-size: 20rpx;
		color: #981007;

		.top {
			height: 48rpx;
			border-radius: 24rpx 24rpx 0 0;
			background: linear-gradient(180deg, #fff4c7 0%, #f9efe6 97%);
			line-height: 48rpx;
		}

		.bottom {
			height: 58rpx;
			border-radius: 0 0 24rpx 24rpx;
			background: linear-gradient(90deg, #fbeffc 0%, #e7f9ff 100%);
			line-height: 58rpx;
		}

		.num {
			margin-right: 2rpx;
			font-size: 40rpx;
		}
	}

	.bubble {
		font-weight: 500;
		font-size: 22rpx;
		line-height: 30rpx;
		color: #4851a3;

		.item {
			position: absolute;
			white-space: nowrap;
			animation: kf 1.2s linear .8s infinite alternate;

			&:nth-child(1) {
				top: 190rpx;
				right: 378rpx;
			}

			&:nth-child(2) {
				top: 150rpx;
				left: 396rpx;

				.top {
					width: 105rpx;
					height: 108rpx;
					line-height: 105rpx;
				}
			}

			&:nth-child(3) {
				top: 46rpx;
				right: 312rpx;

				.top {
					width: 82rpx;
					height: 83rpx;
					line-height: 82rpx;
				}
			}

			&:nth-child(4) {
				top: 24rpx;
				left: 318rpx;

				.top {
					width: 80rpx;
					height: 83rpx;
					line-height: 80rpx;
				}
			}
		}

		.top {
			width: 96rpx;
			height: 99rpx;
			margin: 0 auto 4rpx;
			background-repeat: no-repeat;
			background-size: 100% 100%;
			text-align: center;
			font-size: 32rpx;
			line-height: 96rpx;
			color: #333333;
		}
	}

	.remind {
		position: absolute;
		top: 394rpx;
		right: 0;
		padding: 0 16rpx 12rpx 32rpx;
		border-radius: 46rpx 0 0 46rpx;
		background: linear-gradient(90deg, #fbeffc 0%, #e7f9ff 100%);
		box-shadow: inset 0rpx 2rpx 0rpx 0rpx #ffffff;

		.bottom {
			text-align: center;
			font-size: 22rpx;
			line-height: 30rpx;
			color: #981007;
		}
	}

	.total {
		position: relative;
		width: 378rpx;
		height: 275rpx;
		padding: 144rpx 88rpx 0;
		margin: 0 auto;
		background-repeat: no-repeat;
		background-size: 100% 100%;
		text-align: center;
		font-weight: 600;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #e93323;

		.num {
			font-size: 80rpx;
			line-height: 80rpx;
		}
	}

	.look {
		width: 296rpx;
		border: 1px solid transparent;
		border-radius: 24rpx;
		margin: 24rpx auto 0;
		background-image: linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(255, 255, 255, 0.6));
		background-clip: border-box;
		transform: rotateZ(360deg);

		.inner {
			height: 44rpx;
			border-radius: 22rpx;
			background: linear-gradient(180deg, rgba(255, 255, 255, 0.6) 0%, rgba(255, 255, 255, 0.4) 100%);
			text-align: center;
			font-size: 22rpx;
			line-height: 44rpx;
			color: #333333;
		}

		.iconfont {
			font-size: 24rpx;
		}
	}

	.now {
		position: relative;
		width: 438rpx;
		height: 160rpx;
		padding: 15rpx 0 0 18rpx;
		margin: 43rpx auto 0;
		background-position: center;
		background-repeat: no-repeat;
		background-size: 100% 100%;

		&.disabled {
			.btn {
				color: #f5f5f5;
			}
		}

		.btn {
			width: 402rpx;
			height: 124rpx;
			text-align: center;
			font-weight: 500;
			font-size: 40rpx;
			line-height: 124rpx;
			color: #ffffff;
		}

		.hand {
			position: absolute;
			top: 63rpx;
			right: 4rpx;
			width: 108rpx;
			height: 116rpx;
			background-position: left top;
			background-repeat: no-repeat;
			background-size: contain;
		}
	}

	.board {
		border-radius: 24rpx;
		margin: 30rpx 32rpx 0;
		border: 1px solid transparent;
		background: linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(255, 255, 255, 0.8));
		background-clip: border-box;
		overflow: hidden;

		.board-inner {
			background: linear-gradient(-90deg, #f1fbfd 0%, #ffe8f5 100%);
		}

		.scroll-view {
			white-space: nowrap;
			box-sizing: border-box;
			text-align: center;
		}

		.item {
			display: inline-flex;
			flex-direction: column;
			justify-content: center;
			width: 64rpx;
			height: 160rpx;
			text-align: center;
			font-weight: 500;
			font-size: 22rpx;
			line-height: 30rpx;
			color: #3d3d3d;

			+.item {
				margin-left: 30rpx;
			}

			&:last-child {
				.top::after {
					display: none;
				}

				.top {
					transform: scale(1.3);
				}
			}
		}

		.top {
			position: relative;
			z-index: 1;
			width: 48rpx;
			height: 48rpx;
			margin: 0 auto 16rpx;
			font-weight: 500;
			font-size: 22rpx;
			line-height: 30rpx;
			color: #3d3d3d;

			&::after {
				content: '';
				position: absolute;
				top: 50%;
				left: 50%;
				z-index: 1;
				width: 92rpx;
				height: 2rpx;
				border-top: 2rpx dashed #e93323;
			}

			.image {
				position: relative;
				z-index: 2;
				width: 100%;
				height: 100%;
			}
		}
	}

	.record {
		border-radius: 24rpx;
		margin: 32rpx 20rpx;
		background: #ffffff;

		.head {
			padding: 32rpx 32rpx 20rpx;
			font-weight: 500;
			font-size: 32rpx;
			line-height: 44rpx;
			color: #222222;

			.link {
				font-weight: 400;
				font-size: 24rpx;
				color: #999999;
			}

			.iconfont {
				font-size: 24rpx;
			}
		}

		.body {
			.item {
				padding: 20rpx 34rpx 22rpx 24rpx;
			}

			.num-item {
				width: 80rpx;
				height: 90rpx;
				background: rgba(250, 173, 20, 0.06);
				border-radius: 10rpx;
				color: #D15802;
			}

			.num-item~.num-item {
				margin-left: 16rpx;
			}
		}
	}

	// 日历
	.daynumber {
		color: #333333;
		padding: 34rpx 0 0 32rpx;
		font-size: 36rpx;
		font-weight: 500;

		.sum-count {
			font-size: 44rpx;
			font-family: D-DIN-PRO-SemiBold, D-DIN-PRO;
			font-weight: 600;
			color: #e93323;
			padding: 0 4rpx;
		}
	}

	.popup-content {
		position: relative;
		z-index: 2;
		background-color: #fff;
		border-radius: 30rpx 30rpx 0 0;

		&::before {
			content: "";
			position: absolute;
			top: 0;
			right: 0;
			left: 0;
			z-index: -1;
			height: 248rpx;
			background: linear-gradient(to right, #FEE3DE, #B5ECF8);
			filter: blur(92px);
		}
	}

	@keyframes kf {
		0% {
			transform: translateY(8rpx);
		}

		100% {
			transform: translateY(0);
		}
	}
</style>