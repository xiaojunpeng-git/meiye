<script>
import { HTTP_REQUEST_URL } from '@/config/app';
export default {
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
	inject: ['intoPage', 'tapQrCode', 'goMenuPage', 'goEdit', 'bindPhone', 'getPhoneNumber'],
	data() {
		return{
			imgHost: HTTP_REQUEST_URL,
		}
	},
	methods: {}
};
</script>
<template>
	<view class="header">
		<!-- 用户信息、设置 -->
		<view class="acea-row row-middle user relative">
			<image v-if="userInfo.uid" :src="userInfo.avatar" class="avatar" :class='userInfo.is_money_level?"border-F1BB0D":""' @click="goEdit"></image>
			<image v-else  src="@/static/images/f.png" class="avatar" @click="goEdit"></image>
			<image src="@/static/images/king.png" class="w-36 h-36 absolute top-f14 left-106" v-if="userInfo.is_money_level"></image>
			<view class="name-wrap">
				<view class="acea-row row-middle" v-if="userInfo.uid">
					<view class="name">{{ userInfo.nickname }}</view>
					<view v-if="userInfo.vip_name" class="flex-center min-w-52 pl-12 pr-12 h-26 bg-w111-FEF0D9 rd-50rpx fs-18 fw-500 text-w111-DFA541 ml-10">
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
					<view class="phone" v-if="userInfo.store_name">所属门店：{{ userInfo.store_name }}</view>
				</view>
			</view>
			<view class="acea-row row-middle">
				<text class="iconfont icon-a-ic_QRcode fs-40" @click="tapQrCode"><text class="tips">会员码</text></text>
				<text class="iconfont icon-a-ic_setup1 fs-40 mx-34" @click="intoPage('/pages/users/user_set/index')"></text>
				<view class="iconfont icon-ic_message3 fs-40" @click="intoPage('/pages/users/message_center/index')">
					<uni-badge v-if="userInfo.service_num" absolute="rightTop" :custom-style="{background: '#fff',color:'var(--view-theme)',top:'-56rpx'}" :text="userInfo.service_num"></uni-badge>
				</view>
			</view>
		</view>
		<!-- 余额、优惠券 -->
		<view class="acea-row balance-coupon">
			<view class="item" v-for="(item, index) in property" @click="goMenuPage(item.url)" :key="index">
				<view class="value num-fa-semi">{{ item.value || 0 }}</view>
				<view>{{ item.label }}</view>
			</view>
		</view>
		<!-- 会员中心、积分商城 -->
		<view class="acea-row member-points">
			<view class="acea-row row-middle row-center item" @click="intoPage(userInfo.level_status == 1 ? '/pages/users/user_vip/index' : '/pages/annex/vip_grade_active/index')">
				<view>
					<view>会员中心</view>
					<view class="arrow">
						查看新权益
						<text class="iconfont icon-ic_rightarrow"></text>
					</view>
				</view>
				<view class="member-card-icon"><text class="iconfont icon-huiyuandengji"></text></view>

			</view>
			<view class="acea-row row-middle row-center item" @click="intoPage('/pages/activity/points_mall/index')">
				<view>
					<view>积分商城</view>
					<view class="arrow">
						限量兑神券
						<text class="iconfont icon-ic_rightarrow"></text>
					</view>
				</view>
				<view class="member-card-icon"><text class="iconfont icon-ic_card"></text></view>
			</view>
		</view>
	</view>
</template>

<style lang="scss" scoped>
.header {
	padding: 18rpx 0 0rpx;
	border-bottom-right-radius: 50% 40rpx;
	border-bottom-left-radius: 50% 40rpx;
	margin-bottom: 18rpx;
	background: linear-gradient(135deg, var(--view-theme, #7b2941) 0%, var(--view-gradient, #a45b6d) 100%);
	box-shadow: inset 0 -26rpx 42rpx rgba(70, 25, 39, .13);

	.user {
		padding: 0 40rpx 0 30rpx;

		.iconfont {
			position: relative;
			color: #ffffff;
			font-size: 40rpx;
		}
	}

	.bind-phone {
		margin-top: 12rpx;
		background: rgba(255, 255, 255, 0.3);
		border-radius: 30px;
		width: max-content;
		text-align: center;
		font-size: 20rpx;
		font-weight: 400;
		color: #ffffff;
		line-height: 28rpx;
		padding: 6rpx 16rpx;
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
		border-top: 6rpx solid #ffd89c;
		/* 修改颜色以改变三角形颜色 */
	}

	.avatar {
		width: 112rpx;
		height: 112rpx;
		border-radius: 50%;
		border: 4rpx solid rgba(255,255,255,.62);
		box-shadow: 0 8rpx 20rpx rgba(52,14,28,.2);
	}

	.name-wrap {
		flex: 1;
		padding: 0 32rpx;
		color: #ffffff;
		.iconfont{
			position: unset;
			color: #DFA541;
			font-size: 20rpx;
		}
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
}

.balance-coupon {
	margin-top: 44rpx;

	.item {
		flex: 1;
		text-align: center;
		font-weight: 500;
		font-size: 22rpx;
		line-height: 22rpx;
		color: rgba(255, 255, 255, 0.6);
	}

	.value {
		margin-bottom: 12rpx;
		font-weight: 400;
		font-size: 32rpx;
		line-height: 32rpx;
		color: rgba(255, 255, 255, 0.85);
	}
}

.member-points {
	border: 1rpx solid rgba(123, 41, 65, .08);
	border-radius: 24rpx;
	margin: 20rpx;
	background-color: #ffffff;
	box-shadow: 0 12rpx 28rpx rgba(83,45,54,.07);

	.item {
		position: relative;
		flex: 1;
		height: 134rpx;
		padding-left: 40rpx;
		font-weight: 500;
		font-size: 28rpx;
		line-height: 34rpx;
		color: #4d3037;

		&::before {
			content: '';
			position: absolute;
			top: 50%;
			left: 0;
			height: 48rpx;
			border-left: 1rpx solid #eeeeee;
			transform: translateY(-50%);
		}

		&:first-child::before {
			display: none;
		}
		.iconfont {
			position: relative;
			font-size: 20rpx;
		}
	}

	.arrow {
		margin-top: 12rpx;
		font-weight: 400;
		font-size: 22rpx;
		line-height: 24rpx;
		color: #ff7d00;
	}

	.member-card-icon {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 76rpx;
		height: 76rpx;
		margin-left: 32rpx;
		border: 1rpx solid rgba(123,41,65,.12);
		border-radius: 24rpx;
		background: linear-gradient(145deg, #fff8f5, #f4e2e4);
		box-shadow: 0 8rpx 16rpx rgba(123,41,65,.1);
		color: var(--view-theme, #7b2941);
	}

	.member-card-icon .iconfont { margin-left: 0; font-size: 40rpx; }

	.iconfont {
		margin-left: 2rpx;
		font-size: 24rpx;
	}
}
</style>
