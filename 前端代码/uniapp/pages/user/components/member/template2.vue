<script>
import { HTTP_REQUEST_URL } from '@/config/app';
export default {
	inject: ['intoPage', 'tapQrCode', 'goMenuPage', 'goEdit', 'bindPhone', 'getPhoneNumber'],
	props: {
		userInfo: {
			type: Object,
			default: () => {}
		},
		property: {
			type: Array,
			default: () => []
		},
		// perShowType 0 手机号 1 ID
		perShowType: {
			type: Number,
			default: 0
		}
	},
	data() {
		return{
			imgHost: HTTP_REQUEST_URL,
		}
	},
};
</script>

<template>
	<view class="">
		<view class="acea-row row-middle user-wrapper relative">
			<!-- 头像 -->
			<image v-if="userInfo.uid" class="avatar" :class='userInfo.is_money_level?"border-F1BB0D":""' :src="userInfo.avatar" @click="goEdit"></image>
			<image v-else  src="@/static/images/f.png" class="avatar" @click="goEdit"></image>
			<image src="@/static/images/king.png" class="w-36 h-36 absolute top-18 left-106" v-if="userInfo.is_money_level"></image>
			<view class="name-wrap">
				<view class="acea-row row-middle" v-if="userInfo.uid">
					<view class="name">{{ userInfo.nickname }}</view>
					<view v-if="userInfo.vip_name" class="flex-center min-w-52 pl-12 pr-12 h-26 bg-w111-FEF0D9 rd-50rpx fs-18 fw-500 text-w111-DFA541 ml-10 border-facc7d">
						<text class="iconfont icon-huiyuandengji fs-20 mr-4"></text>
						{{userInfo.vip_name}}
					</view>
				</view>
				<view class="name display-add" v-else @click="goEdit">请点击授权</view>
				<view v-if="userInfo.uid">
					<!-- #ifdef MP -->
					<button class="bind-phone" v-if="!userInfo.phone" open-type="getPhoneNumber" @getphonenumber="getPhoneNumber">绑定手机号</button>
					<!-- #endif -->
					<!-- #ifndef MP -->
					<view class="bind-phone" v-if="!userInfo.phone" @tap="bindPhone">绑定手机号</view>
					<!-- #endif -->
					<view class="phone" v-if="userInfo.phone">{{ perShowType ? 'ID：' + userInfo.uid : userInfo.phone }}</view>
				</view>
			</view>
			<text class="iconfont icon-a-ic_QRcode fs-40" @click="tapQrCode"><text class="tips">会员码</text></text>
			<text class="iconfont icon-a-ic_setup1 fs-40 mx-34" @click="intoPage('/pages/users/user_set/index')"></text>
			<view class="iconfont icon-ic_message3 fs-40" @click="intoPage('/pages/users/message_center/index')">
				<uni-badge v-if="userInfo.service_num" absolute="rightTop" :custom-style="{background: 'var(--view-theme)',top:'-56rpx'}" :text="userInfo.service_num"></uni-badge>
			</view>
		</view>
		<view class="acea-row row-between promotion-wrapper">
			<view class="acea-row row-middle" v-for="(item, index) in property" @click="goMenuPage(item.url)" :key="index">
				<text>{{ item.label }}</text>
				<text class="value num-fa-semi">{{ item.value || 0 }}</text>
			</view>
		</view>
		<!-- 会员 -->
		<view class="member-wrapper" v-if="userInfo.vip" @click="intoPage(userInfo.level_status == 1 ? '/pages/users/user_vip/index' : '/pages/annex/vip_grade_active/index')">
			<view class="card">
				<view class="acea-row row-middle top">
					<view class="name-wrap">
						<view class="name">
							<text class="iconfont icon-ic_crown fs-31"></text>
							{{ userInfo.vip_name }}
						</view>
						<view class="acea-row row-middle">
							商城购物可享
							<text class="number num-fa-semi">{{ userInfo.vip_discount }}</text>
							折
						</view>
					</view>
					<view class="icon-wrap">
						<view class="icon">
							<text class="iconfont icon-a-ic_discount1 fs-20"></text>
						</view>
						<view>购物折扣</view>
					</view>
					<view class="icon-wrap">
						<view class="icon">
							<text class="iconfont icon-ic_badge fs-20"></text>
						</view>
						<view>专属徽章</view>
					</view>
					<text class="iconfont icon-ic_rightarrow fs-24 ml-20"></text>
				</view>
				<view class="acea-row row-middle row-between bottom">
					<view class="acea-row">
						<view class="icon-list">
							<image class="img" :src="`${imgHost}/statics/images/users/vip-icon-row.png`" mode=""></image>
						</view>
						<view class="text">掌握更多快速升级技巧</view>
					</view>
					<view class="button">去获取</view>
				</view>
			</view>
			<!--  公告模块 -->
			<!-- 	<view class="acea-row row-middle grow">
				<view class="iconfont icon-ic_horn1 fs-28"></view>
				<view class="text">查看会员成长值，获得更优惠购物折扣～</view>
				<view class="iconfont icon-ic_rightarrow fs-24"></view>
			</view> -->
		</view>
	</view>
</template>

<style lang="scss" scoped>
.user-wrapper {
	padding: 40rpx 40rpx 40rpx 32rpx;

	.avatar {
		width: 136rpx;
		height: 136rpx;
		border-radius: 50%;
	}

	.name-wrap {
		flex: 1;
		padding: 0 24rpx;
		color: #333333;
		.iconfont{
			position: unset;
			margin-top: 0;
		}
	}
	.bind-phone {
		margin-top: 12rpx;
		background: #fff;
		border-radius: 30px;
		width: max-content;
		text-align: center;
		font-size: 20rpx;
		font-weight: 400;
		color: #333333;
		line-height: 28rpx;
		padding: 6rpx 16rpx;
	}
	.name {
		font-weight: 500;
		font-size: 32rpx;
		line-height: 44rpx;
	}

	.phone {
		margin-top: 10rpx;
		font-size: 24rpx;
		line-height: 34rpx;
	}
	.tips::before {
		content: '';
		position: absolute;
		bottom: -6rpx;
		left: calc(50% - 6rpx);
		width: 0;
		height: 0;
		border-left: 6rpx solid transparent;
		border-right: 6rpx solid transparent;
		border-top: 6rpx solid #ffd89c; /* 修改颜色以改变三角形颜色 */
	}
	.tips {
		position: absolute;
		bottom: 100%;
		left: 50%;
		height: 28rpx;
		padding: 0 14rpx;
		border-radius: 14rpx;
		margin-bottom: 4rpx;
		background-color: #ffd89c;
		transform: translateX(-50%);
		white-space: nowrap;
		font-size: 16rpx;
		line-height: 28rpx;
		color: #9e5e1a;
	}

	.number {
		position: absolute;
		top: -8rpx;
		right: 0;
		min-width: 10rpx;
		height: 24rpx;
		padding: 0 6rpx;
		border: 2rpx solid var(--view-theme);
		border-radius: 12rpx;
		background-color: #ffffff;
		transform: translateX(50%);
		font-weight: 500;
		font-size: 18rpx;
		line-height: 24rpx;
		color: var(--view-theme);
	}
	.iconfont {
		position: relative;
		align-self: flex-start;
		margin-top: 22rpx;
	}
}
.promotion-wrapper {
	padding: 0 48rpx;
	font-size: 26rpx;
	line-height: 36rpx;
	color: #999999;

	.value {
		margin-left: 8rpx;
		font-family: SemiBold;
		font-size: 28rpx;
		color: #333333;
	}
}
.member-wrapper {
	margin: 28rpx 20rpx 20rpx;
	color: #7e4b06;

	.card {
		position: relative;
		border-radius: 32rpx;
		// margin-bottom: -28rpx; // 公告模块
		background: linear-gradient(-270deg, #f4dfaf 0%, #d0a15b 100%);

		.top {
			padding: 25rpx 35rpx;
		}

		.name-wrap {
			flex: 1;
			font-size: 19rpx;
			line-height: 26rpx;
		}

		.name {
			margin-bottom: 4rpx;
			font-weight: 700;
			font-size: 34rpx;
			line-height: 48rpx;

			.iconfont {
				margin-right: 12rpx;
				font-size: 36rpx;
			}
		}

		.icon-wrap {
			font-size: 18rpx;
			line-height: 26rpx;

			+ .icon-wrap {
				margin-left: 32rpx;
			}
		}

		.icon {
			width: 40rpx;
			height: 40rpx;
			border-radius: 50%;
			margin: 0 auto 6rpx;
			background-color: #eccd8b;
			text-align: center;
			line-height: 40rpx;

			.iconfont {
				font-size: 24rpx;
			}
		}

		.bottom {
			position: relative;
			padding: 17rpx 35rpx;
			font-size: 24rpx;
			.icon-list {
				display: flex;
				align-items: center;
				justify-content: center;
				margin-right: 22rpx;
				.img {
					width: 86rpx;
					height: 32rpx;
				}
			}
			&::before {
				content: '';
				position: absolute;
				top: 0;
				right: 35rpx;
				left: 35rpx;
				height: 1rpx;
				background-color: rgba(0, 0, 0, 0.08);
			}
		}

		.text {
		}

		.button {
			height: 48rpx;
			padding: 0 24rpx;
			border-radius: 24rpx;
			background-color: #eccd8b;
			font-weight: 500;
			line-height: 48rpx;
		}
	}

	.grow {
		padding: 50rpx 40rpx 20rpx;
		border-radius: 32rpx;
		background: linear-gradient(180deg, #faeed9 0%, #ffffff 100%);

		.text {
			flex: 1;
			padding-left: 16rpx;
		}
	}
}
</style>
