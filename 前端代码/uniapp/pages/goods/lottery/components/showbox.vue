<template>
	<view class="show-box">
		<view class="table-title acea-row row-between-wrapper" :style="{
			backgroundImage: `url(${imgHost}/statics/images/lottery4.png)`
		}">
			<view class="text" v-if="showMsg.type === 'user'">
				中奖记录
			</view>
			<view class="text" v-else-if="showMsg.type === 'me'">
				我的奖品
			</view>
			<view class="text" v-else-if="showMsg.type === 'prize'">
				活动奖品
			</view>
		</view>
		<view class="table" v-if="['me','user','prize'].includes(showMsg.type)">
			<view v-if="showMsg.type=='me'" class="table-head">
				<view class="nickname" style="width: 20%;">序号</view>
				<view class="table-name time" style="width: 45%;">获奖时间</view>
				<view class="table-name" style="width: 35%;">奖品名称</view>
			</view>
			<view v-if="showMsg.type == 'prize'">
				<view class="prize-list acea-row">
					<template v-for="(item,index) in showMsg.data">
						<view v-if="item.type&&item.type!=1" class="prize-item" :key="item.id">
							<view class="prize-item-inner">
								<image :src="item.image" class="image"></image>
							</view>
							<view class="name line1">{{item.name}}</view>
						</view>
					</template>
				</view>
			</view>
			<view v-else-if="showMsg.type=='user'" class="table-d">
				<view class="table-body" v-for="(item,index) in showMsg.data" :key="index">
					<view class="table-name time">
						{{item.add_time}}
					</view>
					<view class="nickname">
						{{showMsg.type === 'user' ? (item.user?item.user.nickname:'') : index + 1}}
					</view>
					<view class="table-name">
						{{item.prize.name}}
					</view>
				</view>
			</view>
			<view v-else class="table-d me">
				<view class="table-body acea-row row-middle" v-for="(item,index) in showMsg.data" :key="index">
					<view class="nickname" style="width: 20%;">
						<view class="inner acea-row row-center-wrapper">{{showMsg.type === 'user' ? (item.user?item.user.nickname:'') : index + 1}}</view>
					</view>
					<view class="table-name time" style="width: 45%;">
						{{item.add_time}}
					</view>
					<view class="table-name" style="width: 35%;">
						{{item.prize.name}}
					</view>
				</view>
			</view>
		</view>
		<view class="content" v-else v-html="showMsg.data"></view>
	</view>
</template>

<script>
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
			}
		},
		props: {
			showMsg: {
				type: Object
			},
		},
	}
</script>

<style lang="scss" scoped>
	.show-box {
		position: relative;
		display: flex;
		flex-direction: column;
		align-items: center;
		background: linear-gradient(90deg, #FFFEFD 0%, #FFE3E3 100%);
		padding: 112rpx 0 48rpx;
		border-radius: 24rpx;

		+.show-box {
			margin-top: 60rpx;
		}
	}

	.table-title {
		position: absolute;
		top: -24rpx;
		height: 108rpx;
		padding: 12rpx 104rpx 26rpx;
		background-size: 100% 100%;

		.text {
			color: #FFFFFF;
			font-size: 32rpx;
			font-weight: 500;
		}

		image {
			width: 50rpx;
			height: 16rpx;
		}
	}

	.table-d {
		max-height: 200rpx;
		overflow-y: scroll;

		&.me {
			.table-body {
				height: 72rpx;
				margin: 0 20rpx 32rpx;
				background: linear-gradient(90deg, #FEFDFB 0%, #FFD8D8 47%, rgba(255, 228, 228, 0) 100%, rgba(255, 204, 204, 0) 100%, rgba(255, 204, 204, 0) 100%);

				.nickname {
					width: 30%;

					.inner {
						width: 40rpx;
						height: 40rpx;
						border-radius: 20rpx;
						margin-left: 12rpx;
						background: rgba(255, 71, 71, 0.2);
						font-size: 28rpx;
					}
				}
			}
		}
	}

	.table {
		width: 100%;

		.table-head,
		.table-body {
			display: flex;
			justify-content: space-around;
		}

		.table-head {
			margin: 0 20rpx 32rpx;
			font-weight: 500;
			font-size: 28rpx;
			line-height: 40rpx;
			color: #333333;

			.nickname {
				width: 30%;
				padding: 10rpx 20rpx;
			}

			.table-name {
				width: 30%;
				text-align: left;
				padding: 10rpx 20rpx;
			}

			.time {
				width: 40%;
			}
		}



		.table-body {
			color: #333333;

			+.table-body {
				margin-top: 32rpx;
			}

			.nickname {
				width: 30%;
				font-size: 24rpx;
				line-height: 34rpx;
				padding: 0 20rpx;
			}

			.table-name {
				width: 30%;
				text-align: left;
				text-overflow: ellipsis;
				white-space: nowrap;
				overflow: hidden;
				font-size: 24rpx;
				line-height: 34rpx;
				padding: 0 20rpx;
			}

			.time {
				width: 40%;
			}
		}

		.prize-list {
			padding: 0 24rpx;
			margin-right: -23rpx;
		}

		.prize-item {
			position: relative;
			padding: 8rpx;
			border-radius: 16rpx;
			margin: 0 23rpx 32rpx 0;
			background: linear-gradient(180deg, #FFB7B7 3%, #FF5E5E 100%);

			.name {
				position: absolute;
				right: 0;
				bottom: 0;
				left: 0;
				height: 50rpx;
				padding-top: 14rpx;
				border-radius: 0 0 16rpx 16rpx;
				background-image: url(data:image/png;base64,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);
				background-repeat: no-repeat;
				background-size: 100% 100%;
				text-align: center;
				font-weight: 500;
				font-size: 20rpx;
				line-height: 28rpx;
				color: #FFFFFF;
			}
		}

		.prize-item-inner {
			width: 176rpx;
			height: 176rpx;
			border-radius: 12rpx;
			background-color: #FFFFFF;

			.image {
				display: block;
				width: 128rpx;
				height: 128rpx;
				margin: 0 auto;
				object-fit: contain;
			}
		}
	}

	.content {
		width: 100%;
		padding: 0 20rpx;
	}
</style>