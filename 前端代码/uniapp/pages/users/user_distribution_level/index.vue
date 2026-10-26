<template>
	<view :style="colorStyle">
		<!-- #ifdef MP -->
		<NavBar titleText="分销等级" iconColor="#333333" textColor="#333333" showBack></NavBar>
		<!-- #endif -->
		<view class='member-center' :style="{'--level-theme':levelInfo.color}">
			<view class="background" :style="{
				backgroundImage: `linear-gradient(180deg, ${colorToRgba(levelInfo.color, 0.1)} 0%, ${colorToRgba(levelInfo.color, 0)} 100%)`
			}"></view>
			<view class='header acea-row row-middle'>
				<view class="avatar">
					<image :src="userInfo.avatar" class="image"></image>
				</view>
				<view class="text">
					<view class="name">{{userInfo.nickname}}</view>
					<view class="badge acea-row row-center-wrapper" :style="[badgeStyle]">
						<text class="iconfont icon-huiyuandengji"></text>
						<text class="line1 max-w-230">{{ userInfo.agent_level ? `${levelInfo.name}` : '未解锁' }}</text>
					</view>
				</view>
			</view>
			<view class="skill-section" :style="{
				borderColor: colorToRgba(levelInfo.color, 0.6),
				backgroundImage: `linear-gradient(to right, ${colorToRgba(levelInfo.color, 0.2)}, ${colorToRgba(levelInfo.color, 0)},${colorToRgba(levelInfo.color, 0.2)})` 
			}">
				<image :src="levelInfo.image" class="badge"></image>
				<view class="section-hd">
					<view class="title acea-row row-middle">
						<text class="line1 max-w-312">{{levelInfo.name}}</text>
						<template v-if="userInfo.agent_level">升级中</template>
						<template v-else>未解锁<text class="iconfont icon-ic_"></text></template>
					</view>
					<view v-if="task.length" class="task acea-row row-middle">
						以下任务全部达成即可升级
						<text class="task-item task-num">
							{{ taskNum  }}
						</text>
						<text class="task-item">
							/{{ task.length}}
						</text>
					</view>
				</view>
				<view class="level-grow-wrap acea-row row-middle">
					<view class="level-info acea-row row-middle" :class="">
						<view class="level-info-title">一级分佣上浮</view>
						<view class="num">
							{{ levelInfo.one_brokerage || 0 }}<text class="percent">%</text>
						</view>
					</view>
					<view class="level-info acea-row row-middle" :class="">
						<view class="level-info-title">二级分佣上浮</view>
						<view class="num">
							{{ levelInfo.two_brokerage || 0 }}<text class="percent">%</text>
						</view>
					</view>
				</view>
				<view v-if="task.length" class="section-bd">
					<view class="item acea-row row-middle" v-for="(item,index) in task" :key='item.id'>
						<view class="item-content">
							<view class="title">{{item.name}}</view>
							<view class="acea-row row-middle">
								<view class="progress-wrap">
									<view class="progress">
										<view class="back"></view>
										<view :style="{width: `${Math.floor((item.new_number / item.number) > 1 ? 100 : item.new_number / item.number* 100)}%`}" class="inner"></view>
									</view>
									<view class="info-box acea-row row-between-wrapper">
										<view class="info">{{item.finish ? '' : item.task_type_title}}</view>
										<view class="link" hover-class="none">
											<text class="new-number">{{item.new_number}}</text>
											/{{item.number}}
										</view>
									</view>
								</view>
							</view>
						</view>
						<view v-if="item.finish" class="button">已完成</view>
						<view v-else-if="item.type==1" class="button" @click="jumbPath(0)">去邀请</view>
						<view v-else class="button" @click="jumbPath(1)">去逛逛</view>
					</view>
				</view>
			</view>
			<view v-if="hostProduct.length" class="px-20">
				<recommend :hostProduct="hostProduct"></recommend>
			</view>
			<view class='growthValue' :class='growthValue==false?"on":""'>
				<text class='iconfont icon-guanbi3' @click='growthValue = true'></text>
				<view class='conter'>{{illustrate}}</view>
			</view>
			<view class='mask' :hidden='growthValue' @click='growthValueClose'></view>
		</view>
		<!-- #ifdef MP -->
		<authorize v-if="isShowAuth" @authColse="authColse" @onLoadFun="onLoadFun"></authorize>
		<!-- #endif -->
	</view>
</template>

<script>
	import {
		agentLevelList,
		agentLevelTaskList
	} from '@/api/user.js';
	import {
		getProductHot
	} from '@/api/store.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import recommend from '@/components/recommend';
	import colors from '@/mixins/color.js';
	import NavBar from '@/components/NavBar.vue'
	export default {
		components: {
			recommend,
			NavBar
		},
		mixins: [colors],
		data() {
			return {
				reach_count: 0,
				distributionLevel: [],
				swiperIndex: 0,
				growthValue: true,
				task: [], //任务列表
				illustrate: '', //任务说明
				level_id: 0, //任务id,
				hostProduct: [],
				grade: 0,
				hotScroll: false,
				hotPage: 1,
				hotLimit: 10,
				level_title: '',
				level_discount: '',
				levelInfo: {},
				userInfo: {},
				taskInfo: {},
				taskNum: 0,
				isShowAuth: false
			};
		},
		computed: {
			...mapGetters(['isLogin']),
			badgeStyle() {
				let styleObject = {};
				if (this.levelInfo.color) {
					styleObject['background'] = `linear-gradient(90deg, #FFFFFF 0%, rgba(255, 255, 255, 0.3) 51%, ${this.colorToRgba(this.levelInfo.color, 0.1)} 100%)`;
					styleObject['color'] = this.levelInfo.color;
				}
				return styleObject;
			},
		},
		watch: {
			distributionLevel: function() {
				let that = this;
				if (that.distributionLevel.length > 0) {
					that.distributionLevel.forEach(function(item, index) {
						if (item.is_clear === false) {
							that.activeIndex = index;
							that.grade = item.grade;
						}
					});
				}
			},
		},
		onLoad() {
			this.get_host_product();
			if (this.isLogin) {
				this.agentLevelList();
			} else {
				toLogin()
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		methods: {
			onLoadFun() {
				this.agentLevelList();
				this.isShowAuth = false
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			agentLevelList: function() {
				agentLevelList().then(res => {
					const {
						level_info,
						level_list,
						task,
						user
					} = res.data;
					this.levelInfo = level_info;
					this.distributionLevel = level_list;
					this.userInfo = user;
					this.taskInfo = task;
					this.levelInfo.exp = parseFloat(this.levelInfo.exp);
					this.levelInfo.rate = Math.floor(this.levelInfo.exp / this.levelInfo.exp_num * 100);
					if (this.levelInfo.rate > 100) {
						this.levelInfo.rate = 100;
					}
					const index = level_list.findIndex((
							grade, v
						) =>
						grade.id === user.agent_level
					);
					if (index !== -1) {
						this.swiperIndex = index === -1 ? 0 : (index + 1);
					}
					if (index === -1) {
						this.levelInfo = {
							...this.levelInfo,
							...this.distributionLevel[0]
						};
					}
					this.level_id = this.distributionLevel[index === -1 ? 0 : (index + 1)].id || 0;
					this.getTask();
				});
			},
			/**
			 * 获取我的推荐
			 */
			get_host_product: function() {
				let that = this;
				if (that.hotScroll) return
				getProductHot(
					that.hotPage,
					that.hotLimit,
				).then(res => {
					that.hotPage++
					that.hotScroll = res.data.length < that.hotLimit
					that.hostProduct = that.hostProduct.concat(res.data)
				});
			},
			/**
			 * 会员切换
			 * 
			 */
			swiperChange(e) {
				let index = e.detail.current;
				this.swiperIndex = index;
				this.level_id = this.distributionLevel[index].id || 0;
				this.level_title = this.distributionLevel[index].name || '';
				this.level_discount = this.distributionLevel[index].discount || '';
				this.getTask();
			},
			/**
			 * 关闭说明
			 */
			growthValueClose: function() {
				this.growthValue = true;
			},
			/**
			 * 打开说明
			 */
			opHelp: function(index) {
				this.growthValue = false;
				this.illustrate = this.task[index].desc;
			},
			/**
			 * 获取任务要求
			 */
			getTask: function() {
				let that = this;
				that.taskNum = 0
				agentLevelTaskList(that.level_id).then(res => {
					that.task = res.data.list
					for (let i = 0; i < that.task.length; i++) {
						if (that.task[i].finish) {
							that.taskNum += 1
						}
					}
				});
			},
			//跳转
			jumbPath(type) {
				let path = [
					'/pages/extension/invite_friend/index',
					'/pages/goods/goods_list/index',
				]

				uni.navigateTo({
					url: path[type]
				})
			},
			colorToRgba(str, n) {
				if (!str) {
					return
				}
				// 十六进制颜色值的正则表达式
				const reg = /^#([0-9a-fA-f]{3}|[0-9a-fA-f]{6})$/;
				let sColor = str.toLowerCase();
				// 十六进制颜色转换为RGB格式
				if (sColor && reg.test(sColor)) {
					if (sColor.length === 4) {
						let sColorNew = '#';
						for (let i = 1; i < 4; i += 1) {
							sColorNew += sColor.slice(i, i + 1).concat(sColor.slice(i, i + 1));
						}
						sColor = sColorNew;
					}
					// 处理六位颜色值
					const sColorChange = [];
					for (let k = 1; k < 7; k += 2) {
						sColorChange.push(parseInt(`0x${sColor.slice(k, k + 2)}`, 16));
					}
					return `rgba(${sColorChange.join(',')}, ${n})`;
				}
				return sColor;
			},
		},
		onPageScroll(e) {
			uni.$emit('scroll');
		},
		onReachBottom() {
			if (!this.hotScroll) {
				this.get_host_product();
			}
		}
	}
</script>

<style lang="scss" scoped>
	.swiper {
		.swiper-item {
			height: 100%;
			border-radius: 6rpx;
			background: center/100% 100% no-repeat;
			transform: scale(0.9);
			transition: all 0.2s ease-in 0s;
			line-height: 1.1;

			&.on {
				transform: none;
			}
		}

		.user-wrap {
			padding-top: 20rpx;
			padding-left: 22rpx;
			line-height: 1.1;

			.image {
				width: 90rpx;
				height: 90rpx;
				border-radius: 50%;
			}

			.user-msg {
				margin-left: 14rpx;

				.text {
					flex: 1;
					display: flex;
					align-items: center;
					min-width: 0;
					font-size: 22rpx;
					color: #666666;

					.num {
						margin-right: 10rpx;
						margin-left: 10rpx;
						font-size: 30rpx;
						font-style: italic;
					}
				}
			}

			.name {
				flex: 1;
				overflow: hidden;
				white-space: nowrap;
				text-overflow: ellipsis;
				font-weight: bold;
				font-size: 28rpx;
				color: #fff;
				margin-right: 8rpx;
			}

			.state {
				position: absolute;
				top: 0rpx;
				right: 0;
				width: 70rpx;
				height: 70rpx;

				.lock {
					width: 100%;
					height: 100%;
				}
			}
		}

		.grow-wrap {
			padding-left: 34rpx;
			margin-top: 70rpx;
			font-size: 20rpx;
			color: #474747;
			display: flex;

			.num {
				margin-right: 8rpx;
				margin-left: 8rpx;
				font-size: 26rpx;
			}
		}

		.level {
			font-size: 24rpx;
			color: #fff;
			border-radius: 4rpx;
			border: 1px solid #fff;
			padding: 3rpx 8rpx;
		}
	}


	.skill-section {
		position: relative;
		padding: 32rpx 24rpx;
		border: 2rpx solid transparent;
		border-radius: 32rpx;
		margin: 0 20rpx;
		background-repeat: no-repeat;
		background-size: 100% 100%;

		.badge {
			position: absolute;
			top: -104rpx;
			right: 38rpx;
			width: 238rpx;
			height: 192rpx;
		}

		.section-hd {
			.title {
				font-weight: 500;
				font-size: 28rpx;
				line-height: 40rpx;
				color: #333333;

				.iconfont {
					margin-left: 4rpx;
					font-size: 28rpx;
				}
			}

			.task {
				margin-top: 8rpx;
				color: #999999;
				font-size: 24rpx;
			}

			.task-item {
				font-family: Regular;
				font-size: 28rpx;
			}

			.task-num {
				margin-left: 8rpx;
				color: var(--level-theme);
			}
		}

		.section-bd {
			padding: 32rpx;
			border-radius: 24rpx;
			background-color: #FFFFFF;

			.item {
				+.item {
					margin-top: 32rpx;
				}
			}

			.item-content {
				flex: 1;
			}

			.title {
				font-weight: 500;
				font-size: 28rpx;
				line-height: 40rpx;
				color: #333333;
			}

			.progress-wrap {
				flex: 1;
				padding-right: 32rpx;
			}

			.progress {
				position: relative;

				.back {
					height: 8rpx;
					border-radius: 4rpx;
					background-color: var(--level-theme);
					opacity: 0.1;
				}

				.inner {
					position: absolute;
					top: 0;
					left: 0;
					height: 8rpx;
					border-radius: 4rpx;
					background-color: var(--level-theme);
				}
			}

			.info-box {
				margin-top: 12rpx;
			}

			.info {
				font-size: 22rpx;
				line-height: 30rpx;
				color: #999999;
			}

			.link {
				font-family: Regular;
				font-size: 28rpx;
				line-height: 28rpx;
				color: #999999;

				.new-number {
					color: #333333;
				}
			}

			.button {
				height: 64rpx;
				padding: 0 32rpx;
				border-radius: 32rpx;
				background-color: var(--level-theme);
				font-weight: 500;
				font-size: 24rpx;
				line-height: 64rpx;
				color: #FFFFFF;
			}
		}
	}

	.background {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		height: 664rpx;
	}

	.member-center .header {
		padding: 40rpx 296rpx 36rpx 32rpx;

		.avatar {
			width: 88rpx;
			height: 88rpx;

			.image {
				width: 100%;
				height: 100%;
				border: 4rpx solid #FFFFFF;
				border-radius: 50%;
			}
		}

		.text {
			flex: 1;
			padding-left: 20rpx;
		}

		.name {
			font-size: 32rpx;
			line-height: 44rpx;
			color: #333333;
		}

		.badge {
			display: inline-flex;
			min-width: 84rpx;
			height: 32rpx;
			padding: 0 10rpx;
			border-radius: 16rpx;
			margin-top: 8rpx;
			background: linear-gradient(90deg, #FFFFFF 0%, rgba(255, 255, 255, 0.3) 51%, rgba(217, 126, 29, 0.1) 100%);
			vertical-align: middle;
			font-weight: 500;
			font-size: 22rpx;
			line-height: 32rpx;
			color: var(--level-theme);

			.image {
				width: 32rpx;
				height: 32rpx;
				margin-right: 4rpx;
			}

			.iconfont {
				margin-right: 4rpx;
				font-size: 32rpx;
			}
		}
	}

	.member-center .header swiper {
		position: relative;
	}

	.member-center .growthValue {
		background-color: #fff;
		border-radius: 16rpx;
		position: fixed;
		top: 266rpx;
		left: 50%;
		width: 560rpx;
		min-height: 440rpx;
		margin-left: -280rpx;
		z-index: 99;
		transform: translate3d(0, -200%, 0);
		transition: all .3s cubic-bezier(.25, .5, .5, .9);
	}

	.member-center .growthValue.on {
		transform: translate3d(0, 0, 0);
	}

	.member-center .growthValue .pictrue {
		width: 100%;
		height: 257rpx;
		position: relative;
	}

	.member-center .growthValue .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 16rpx 16rpx 0 0;
	}

	.member-center .growthValue .conter {
		padding: 0 35rpx;
		font-size: 30rpx;
		color: #333;
		margin-top: 58rpx;
		line-height: 1.5;
		height: 350rpx;
		overflow: auto;
	}

	.member-center .growthValue .iconfont {
		position: absolute;
		font-size: 65rpx;
		color: #fff;
		bottom: -90rpx;
		left: 50%;
		transform: translateX(-50%);
	}

	.level-grow-wrap {
		height: 92rpx;

		.level-info {
			margin-right: 72rpx;

			.level-info-title {
				font-size: 24rpx;
				color: #999999;
			}

			.num {
				font-family: Regular;
				margin-left: 20rpx;
				font-weight: 600;
				font-size: 36rpx;
				color: var(--level-theme);

				.percent {
					font-weight: 500;
					font-size: 32rpx;
				}
			}
		}

		.lock-sty {
			opacity: 0.7;
		}

	}

	.swiper .level-sty {
		opacity: 0.7;
	}
</style>