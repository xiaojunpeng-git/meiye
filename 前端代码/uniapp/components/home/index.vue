<template>
	<!-- 悬浮导航按钮 -->

	<view :style="colorStyle" v-if="is_show">
		<view style="touch-action: none">
			<view class="home" style="position: fixed" :style="{ top: top + 'px' }" id="right-nav"
				@touchmove.stop.prevent="setTouchMove">
				<view class="menus" :class="isOpen ? `menus_isOpen-${menus.length}` : ''" v-if="index == 3 ">
					<view id="fab3" style="height: 298rpx;width: 1px;"></view>
					<view class="menu menu_main" @click="changeStatus">
						<!-- <view class="menu_bag" v-if="styleType == 3"
							:class="isOpen ? ' has-bag' : opend ? 'close-bag' : ''"></view> -->
						<image :src="isOpen ? main_after_image : main_ago_image" class="image"
							style="border-radius: 50%;" />
					</view>

					<view v-for="(menu, index) in menus" :key="index" :style="{ '--color': menu.color }"
						:class="['menu menu_a', `menu_a--${index + 1}`]" @click.stop="jump(menu.url)">
						<image :src="menu.img" class="icon-img"></image>
					</view>
				</view>
				<view id="fab1" class="fab1 p-10" v-if="index == 1" @touchmove.stop.prevent="setTouchMove">
					<view v-show="isOpen" class="img-list">
						<view v-for="(item, index) in menus" :key="index" class="img-box" @click="jump(item.url)">
							<image class="img" :src="item.img" mode=""></image>
						</view>
					</view>
					<view class="btn-box" @click="changeStatus">
						<view class="img-box">
							<image class="img" :src="main_ago_image" mode=""></image>
						</view>
					</view>
				</view>
				<view id="fab2" class="fab2 flex-y-center relative p-10" :class="{ hide: isHide }" v-if="index == 2">
					<view v-if="isOpen" class="img-list">
						<view v-for="(item, index) in menus" :key="index" class="img-box" @click="jump(item.url)">
							<image class="img" :src="item.img" mode=""></image>
						</view>
					</view>
					<view v-if="!isOpen" class="btn-box" @click="changeStatus">
						<view class="img-box">
							<image class="img" :src="main_ago_image" mode=""></image>
						</view>
					</view>
					<view v-if="isOpen" class="icon" @click="changeStatus">
						<text class="iconfont icon-ic_rightarrow"></text>
					</view>
				</view>
				<view class="menus fab4" :class="isOpen ? `menus_isOpen menus_isOpen-${menus.length}` : ''"
					v-if="index == 4">
					<view id="fab4" style="height: 350rpx;width: 1px;"></view>
					<view class="menu_bag" :class="isOpen ? ' has-bag' : opend ? 'close-bag' : ''"></view>
					<view class="menu menu_main menu_main4" @click="changeStatus">
						<text v-if="isOpen" class="iconfont icon-ic_close"></text>
					</view>

					<view v-for="(menu, index) in menus" :key="index"
						:style="{ '--color': menu.color, zIndex: isOpen ? 9999 : '-1' }"
						:class="['menu menu_a', `menu_a--${index + 1}`]" @click.stop="jump(menu.url)">
						<image :src="menu.img" class="icon-img"></image>
					</view>
				</view>
			</view>
		</view>
	</view>
</template>
<script>
	import {
		mapGetters
	} from 'vuex';
	import colors from '@/mixins/color.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import Vue from 'vue';
	import {
		getSuspended
	} from '@/api/api.js';

	export default {
		name: 'Home',
		props: {
			// 风格样式
			styleType: {
				type: Number,
				default: 2
			},
			isHide: {
				type: Boolean,
				default: false
			}
		},
		mixins: [colors],
		data: function() {
			return {
				top: '545',
				imgHost: HTTP_REQUEST_URL,
				isOpen: false, //菜单是否展开
				opend: false, //是否打开过
				menus: [],
				main_image: '',
				shifting: 10,
				index: 4,
				is_show: 1,
				main_after_image: '',
				main_ago_image: '',
				windowHeight: 0,
				fabHeight: 0,
				isHides: false,
			};
		},
		watch: {
			isHide(newValue, oldValue) {
				this.isHides = newValue
			}
		},
		mounted() {
			uni.$on('scroll', this.scrollFn);
			this.getSuspended();
			uni.getSystemInfo({
				success: (res) => {
					this.windowHeight = res.windowHeight;
				}
			});
		},
		methods: {
			changeStatus() {
				this.isOpen = !this.isOpen;
				this.opend = true;
				this.$emit('homehide', false)
			},
			setTouchMove(e) {
				let that = this;
				let clientY = e.touches[0].clientY;
				if (clientY > (that.fabHeight / 2) && clientY < (that.windowHeight - that.fabHeight / 2)) {
					that.top = clientY;
				}
			},
			scrollFn() {
				this.isOpen = false;
			},
			jump(url) {
				this.$util.JumpPath(url);
			},
			// 获取配置信息
			getSuspended() {
				getSuspended().then(res => {
					const data = res.data;
					this.menus = data.button;
					this.index = data.index;
					this.is_show = data.is_show;
					this.main_image = data.main_image;
					this.main_after_image = data.main_after_image || '';
					this.main_ago_image = data.main_ago_image || '';
					this.shifting = data.shifting;
					this.$nextTick(() => {
						const query = uni.createSelectorQuery().in(this);
						query
							.select(`#fab${this.index}`)
							.boundingClientRect((data) => {
								this.fabHeight = data.height;
								if (this.shifting == 100) {
									this.top = this.windowHeight - data.height / 2
								} else if (this.shifting == 0) {
									this.top = data.height / 2
								} else {
									this.top = this.shifting / 100 * this.windowHeight
								}
							})
							.exec();
					});
				});
			},
		}
	};
</script>

<style lang="scss" scoped>
	.home {
		position: fixed;
		color: white;
		text-align: center;
		z-index: 9999;
		display: flex;
		right: 0;
		transform: translateY(-50%);
	}

	.menus {
		width: 70rpx;
		height: 70rpx;
		line-height: 70rpx;
		position: relative;
		right: 20rpx;
		font-size: 24rpx;
		color: #ffffff;
		margin: auto;
	}

	.menus .menu {
		display: block;
		text-align: center;
		border-radius: 50%;
		position: absolute;
		top: 50%;
		left: 50%;
		transform: translate(-50%, -50%);
		transition-timing-function: cubic-bezier(0.25, 1.2, 0.45, 1);
		transition-duration: 1s;
	}

	.menu_bag {
		width: 70rpx;
		height: 70rpx;
		// border-radius: 90rpx 0 0 90rpx;
		border-radius: 70rpx;
		background: rgba(51, 51, 51, 0.1);
		box-shadow: 0rpx 0rpx 17rpx 0rpx rgba(0, 0, 0, 0.1);
		position: absolute;
	}

	.close-bag {
		animation: close 0.5s ease forwards;
	}

	.has-bag {
		border-radius: 50%;
		animation: show 0.3s ease forwards;
	}

	@keyframes show {
		0% {
			width: 70rpx;
			height: 70rpx;
		}

		100% {
			width: 350rpx;
			height: 350rpx;
		}
	}

	@keyframes close {
		0% {
			width: 350rpx;
			height: 350rpx;
		}

		100% {
			width: 70rpx;
			height: 70rpx;
		}
	}

	.menus .menu_main {
		z-index: 2;
		width: 70rpx;
		height: 70rpx;
		line-height: 70rpx;
		font-size: 36px;
		// background-color: #607d88;
		box-shadow: 0px 0px 8px 0px rgba(0, 0, 0, 0.1);
		display: flex;
		align-items: center;
		justify-content: center;

		image {
			width: 100%;
			height: 100%;
		}
	}

	.menus .menu_a {
		width: 70rpx;
		height: 70rpx;
		line-height: 70rpx;
		color: inherit;
		background-color: var(--color);
		// box-shadow: 0 0 6rpx var(--color);
		box-shadow: 0rpx 0rpx 16rpx 0rpx rgba(0, 0, 0, 0.1);
		// transform: translate(-50%, -50%) rotate(180deg);
		transform: translate(-50%, -50%);
	}

	/* 点击悬浮球主菜单按钮后样式 */
	.menus_isOpen .menu_main {
		width: 70rpx;
		height: 70rpx;
		line-height: 70rpx;
		font-size: 28rpx;
		color: #607d88;
		background-color: #ffffff;
	}

	.menus_isOpen-3 {
		.menu_a--1 {
			transform: translate(-100%, calc(-50% - 93rpx));
		}

		.menu_a--2 {
			transform: translate(calc(-50% - 100rpx), calc(-50%));
		}

		.menu_a--3 {
			transform: translate(-100%, calc(-50% + 93rpx));
		}
	}

	.menus_isOpen-4 {
		.menu_a--1 {
			transform: translate(-50%, calc(-50% - 100rpx));
		}

		.menu_a--2 {
			transform: translate(calc(-50% - 90rpx), calc(-50% - 50rpx));
		}

		.menu_a--3 {
			transform: translate(calc(-50% - 90rpx), calc(-50% + 50rpx));
		}

		.menu_a--4 {
			transform: translate(-50%, calc(-50% + 100rpx));
		}

		.menu_a--5 {
			transform: translate(calc(-50% + 100rpx), calc(-50% + 50rpx));
		}

		.menu_a--6 {
			transform: translate(calc(-50% + 100rpx), calc(-50% - 50rpx));
		}
	}

	.menus_isOpen-5 {
		.menu_a--1 {
			transform: translate(-50%, calc(-50% - 110rpx));
		}

		.menu_a--2 {
			transform: translate(calc(-50% - 76rpx), calc(-50% - 76rpx));
		}

		.menu_a--3 {
			transform: translate(calc(-50% - 106rpx), -50%);
		}

		.menu_a--4 {
			transform: translate(calc(-50% - 76rpx), calc(-50% + 76rpx));
		}

		.menu_a--5 {
			transform: translate(-50%, calc(-50% + 110rpx));
		}
	}

	.icon-img {
		width: 70rpx;
		height: 70rpx;
		border-radius: 50%;
	}

	.fab1 {
		position: absolute;
		right: 20rpx;
		bottom: 0;
		border-radius: 45rpx;
		background: rgba(255, 255, 255, 0.5);
		backdrop-filter: blur(10rpx);


		.img-box {
			width: 70rpx;
			height: 70rpx;
			border-radius: 50%;
			background: #FFFFFF;

			.img {
				width: 100%;
				height: 100%;
				border-radius: 50%;
			}
		}

		.img-list {
			position: relative;
			z-index: 2;

			.img-box {
				margin: 0 0 16rpx 0;
				box-shadow: 0rpx 0rpx 16rpx 0rpx rgba(0, 0, 0, 0.1);
			}
		}

		.btn-box {
			position: relative;
			z-index: 2;

			.img-box {}
		}
	}

	.fab2 {
		border-radius: 45rpx 0 0 45rpx;
		background: rgba(255, 255, 255, 0.5);
		backdrop-filter: blur(10rpx);

		&.hide {
			transform: translateX(42rpx);
			opacity: 0.5;
		}

		.img-box {
			position: relative;
			z-index: 2;
			width: 70rpx;
			height: 70rpx;
			border-radius: 50%;
			background: #FFFFFF;

			.img {
				width: 100%;
				height: 100%;
				border-radius: 50%;
			}
		}

		.img-list {
			position: relative;
			z-index: 2;
			display: flex;

			.img-box {
				box-shadow: 0rpx 0rpx 16rpx 0rpx rgba(0, 0, 0, 0.1);

				+.img-box {
					margin: 0 0 0 16rpx;
				}
			}
		}

		.icon {
			position: relative;
			z-index: 2;
			display: flex;
			justify-content: center;
			align-items: center;
			width: 28rpx;
			height: 28rpx;
			margin-left: 16rpx;
		}

		.iconfont {
			font-size: 28rpx;
			color: #333333;
		}
	}

	.menu_main4 {
		background: #FFFFFF;

		.icon-ic_close {
			font-size: 34rpx;
			color: #333333;
		}
	}

	.fab4 {
		.menu_bag {
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			width: 70rpx;
			height: 70rpx;
			border-radius: 50%;
			background: rgba(51, 51, 51, 0.1);
			backdrop-filter: blur(7.78rpx);
			box-shadow: 0rpx 0rpx 13rpx 0rpx rgba(0, 0, 0, 0.1);
		}

		.close-bag {
			background: rgba(51, 51, 51, 0.1);
			backdrop-filter: blur(7.78rpx);
		}

		.has-bag {
			background: rgba(255, 255, 255, 0.5);
		}

		.menu_main {
			width: 48rpx;
			height: 48rpx;
		}

		&.menus_isOpen {
			.menu_main {
				width: 70rpx;
				height: 70rpx;
			}
		}
	}
</style>