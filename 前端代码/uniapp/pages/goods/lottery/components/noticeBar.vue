<template>
	<!-- 无缝滚动效果 -->
	<view class="marquee-wrap">
		<view class="marquee-list" :class="{'animate-up': animateUp}">
			<view class="marquee-item" v-for="(item, index) in listData" :key="item.id">
				{{ prefix }}<text class="name">{{ item.user ? item.user.nickname : '****' }}</text>获得<text>{{ item.prize.name }}</text>
			</view>
		</view>
	</view>
</template>

<script>
	export default {
		name: "noticeBar",
		data() {
			return {
				animateUp: false,
				listData: JSON.parse(JSON.stringify(this.showMsg)),
				timer: null
			}
		},
		props: {
			showMsg: {
				type: Array
			},
			prefix: {
				type: String
			},
		},
		mounted() {
			this.timer = setInterval(this.scrollAnimate, 2500);
		},
		methods: {
			scrollAnimate() {
				this.animateUp = true
				setTimeout(() => {
					this.listData.push(this.listData[0])
					this.listData.shift()
					this.animateUp = false
				}, 500)
			}
		},
		destroyed() {
			clearInterval(this.timer)
		}
	};
</script>

<style lang="scss" scoped>
	.marquee-wrap {
		width: 100%;
		height: 40rpx;
		border-radius: 20px;
		margin: 0 auto;
		overflow: hidden;

		.marquee-list {
			padding: 0;

			.marquee-item {
				width: 100%;
				height: 100%;
				text-overflow: ellipsis;
				overflow: hidden;
				white-space: nowrap;
				padding: 0;
				list-style: none;
				line-height: 40rpx;
				color: #fff;
				font-size: 26rpx;
				font-weight: 400;
			}

			.name {
				color: #FFEF6C;
			}
		}

		.animate-up {
			transition: all 0.5s ease-in-out;
			margin-top: -40rpx;
		}
	}
</style>