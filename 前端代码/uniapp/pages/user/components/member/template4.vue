<script>
import { HTTP_REQUEST_URL } from '@/config/app';
export default {
	props: {
		userInfo: {
			type: Object,
			default: () => {}
		},
		commission: {
			type: Object,
			default: () => {}
		},
		// perShowType 0 手机号 1 ID
		perShowType: {
			type: Number,
			default: 0
		}
	},
	inject: ['intoPage', 'tapQrCode', 'goEdit', 'bindPhone', 'getPhoneNumber'],
	computed: {
		headerBg() {
			return `url(${HTTP_REQUEST_URL}/statics/images/users/template4_bg.png)`;
		},
		withdrawBg() {
			return{
				backgroundImage: `url(${HTTP_REQUEST_URL}/statics/images/users/black_user_bg.png)`
			}
		}
	}
};
</script>

<template>
	<view class="">
		<view class="acea-row row-middle user">
			<image v-if="userInfo.uid" class="avatar" :class='userInfo.is_money_level?"border-F1BB0D":""' :src="userInfo.avatar" @click="goEdit"></image>
			<image v-else  src="@/static/images/f.png" class="avatar" @click="goEdit"></image>
			<image src="@/static/images/king.png" class="w-36 h-36 absolute top-44 left-106" v-if="userInfo.is_money_level"></image>
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
		<view class="distribution">
			<view class="acea-row row-middle row-between withdraw" :style="[withdrawBg]">
				<view>
					<view>可提现(元)</view>
					<view class="value num-fa-semi">{{ userInfo.commissionCount || 0 }}</view>
				</view>
				<view class="button" @click="intoPage('/pages/users/user_cash/index')">立即提现</view>
			</view>
			<view class="acea-row">
				<view class="item">
					<view class="inner">
						<view>累计佣金</view>
						<view class="value num-fa-semi">{{ commission.brokerage_price || 0 }}</view>
					</view>
				</view>
				<view class="item">
					<view class="inner">
						<view>推荐人数</view>
						<view class="value num-fa-semi">{{ commission.number || 0 }}</view>
					</view>
				</view>
				<view class="item">
					<view class="inner">
						<view>推荐单数</view>
						<view class="value num-fa-semi">{{ commission.order_num || 0 }}</view>
					</view>
				</view>
			</view>
		</view>
	</view>
</template>

<style scoped lang="scss">
.user {
	padding: 60rpx 42rpx 56rpx 30rpx;
	background-position: bottom;
	background-size: 100%;
	background-color: #f5f5f5;
	.avatar {
		width: 112rpx;
		height: 112rpx;
		border-radius: 50%;
	}

	.name-wrap {
		flex: 1;
		padding: 0 32rpx;
		color: #333333;
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
	.iconfont {
		position: relative;
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
}
.distribution {
	border-radius: 24rpx;
	margin: 0 20rpx 20rpx;
	background-color: #ffffff;

	.withdraw {
		height: 176rpx;
		padding: 0 56rpx 0 48rpx;
		background-position: center;
		background-repeat: no-repeat;
		background-size: cover;
		font-size: 26rpx;
		line-height: 36rpx;
		color: rgba(255, 231, 198, 0.8);

		.value {
			margin-top: 16rpx;
			font-family: SemiBold;
			font-size: 56rpx;
			line-height: 56rpx;
			color: #ffe7c6;
		}

		.button {
			height: 56rpx;
			padding: 0 24rpx;
			border-radius: 28rpx;
			background-color: #fce6c8;
			font-weight: 500;
			font-size: 24rpx;
			line-height: 56rpx;
			color: #9c5a00;
		}
	}

	.item {
		flex: 1;
		padding: 40rpx 0;
		text-align: center;
		font-size: 26rpx;
		line-height: 30rpx;
		color: #999999;

		.inner {
			display: inline-block;
		}

		.value {
			margin-top: 20rpx;
			text-align: left;
			font-family: SemiBold;
			font-size: 36rpx;
			line-height: 36rpx;
			color: #333333;
		}
	}
}
</style>
