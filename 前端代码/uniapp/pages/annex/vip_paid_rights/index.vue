<template>
	<scroll-view class="rights-container" scroll-y="true">
		<!-- #ifdef MP -->
		<NavBar titleText="会员权益" :iconColor="iconColor" :textColor="iconColor" :isScrolling="isScrolling" showBack></NavBar>
		<!-- #endif -->
		<view class="header acea-row">
			<view v-for="(item, index) in memberRights" :key="item.id" class="item acea-row row-column row-middle" :class="{ on: currentIndex == index }" @click="currentIndex = index">
				<view class="image-wrap acea-row row-center-wrapper">
					<image :src="item.pic" class="image"></image>
				</view>
				<view>{{ item.title }}</view>
			</view>
		</view>
		<swiper class="swiper" :current="currentIndex" :interval="3000" :duration="1000" previous-margin="58rpx" next-margin="58rpx" @change="swiperChange">
			<swiper-item v-for="(item, index) in memberRights" :key="item.id">
				<view class="swiper-item acea-row row-column" :class="{ on: currentIndex == index }">
					<view class="title">{{ item.explain }}</view>
					<scroll-view class="scroll-view" scroll-y="true">
						<view v-html="item.content || ''"></view>
					</scroll-view>
				</view>
			</swiper-item>
		</swiper>
	</scroll-view>
</template>

<script>
	import {
		memberCard,
	} from '@/api/user.js';
	import NavBar from '@/components/NavBar.vue';
	export default {
		components: {
			NavBar
		},
		data() {
			return {
				// #ifdef MP
				iconColor: '#FFFFFF',
				isScrolling: false,
				// #endif
				memberRights: [],
				currentIndex: 0,
			}
		},
		onLoad() {
			this.memberCard();
		},
		methods: {
			memberCard() {
				uni.showLoading({
					title: '正在加载…'
				});
				memberCard().then(res => {
					uni.hideLoading();
					const {
						member_rights,
					} = res.data;
					this.memberRights = member_rights;
				}).catch(err => {
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
			},
			swiperChange(e) {
				this.currentIndex = e.detail.current;
			},
		},
	}
</script>

<style lang="scss" scoped>
	.rights-container {
		position: absolute;
		top: 0;
		right: 0;
		bottom: 0;
		left: 0;
		background: linear-gradient(180deg, #312B23 0%, #19140E 100%);

		.header {
			.item {
				flex: 1;
				padding: 48rpx 0 56rpx;
				font-size: 24rpx;
				line-height: 34rpx;
				color: rgba(255, 255, 255, 0.4);

				&.on {
					color: rgba(255, 255, 255, 0.8);

					.image {
						opacity: 1;
					}
				}
			}

			.image-wrap {
				position: relative;
				width: 88rpx;
				height: 88rpx;
				border-radius: 50%;
				margin-bottom: 28rpx;
				background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 2%, rgba(255, 255, 255, 0) 100%);
			}

			.image {
				width: 100%;
				height: 100%;
				opacity: 0.3;
			}
		}

		.swiper {
			height: 1090rpx;
		}

		.swiper-item {
			height: 100%;
			transform: scale(0.9);
			transition: 0.3s;

			&.on {
				transform: scale(1);
			}

			.title {
				padding: 42rpx 0 40rpx;
				border-radius: 24rpx 24rpx 0 0;
				background-color: #FFEBC7;
				text-align: center;
				font-weight: 500;
				font-size: 30rpx;
				line-height: 42rpx;
				color: #333333;
			}
		}

		.scroll-view {
			flex: 1;
			min-height: 0;
			padding: 48rpx;
			border-radius: 0 0 24rpx 24rpx;
			background-color: #FFFFFF;
			box-sizing: border-box;
		}
	}
</style>