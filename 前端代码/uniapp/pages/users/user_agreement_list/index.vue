<template>
	<!-- 个人资料 -->
	<view>
		<form @submit="formSubmit">
			<view class="personal-data" :style="colorStyle">
				<view class="list rd-24rpx mx-20">
					<navigator url="/pages/users/privacy/index?type=user" hover-class="none" class="item acea-row row-between-wrapper">
						<view>用户协议</view>
						<text class="iconfont icon-ic_rightarrow"></text>
					</navigator>
					<navigator url="/pages/users/privacy/index?type=privacy" hover-class="none" class="item acea-row row-between-wrapper">
						<view>隐私协议</view>
						<text class="iconfont icon-ic_rightarrow"></text>
					</navigator>
					<navigator url="/pages/users/privacy/index?type=cancel" hover-class="none" class="item acea-row row-between-wrapper">
						<view>注销协议</view>
						<text class="iconfont icon-ic_rightarrow"></text>
					</navigator>
				</view>
			</view>
		</form>
	</view>
</template>

<script>
import colors from '@/mixins/color.js';
export default {
	components: {},
	mixins: [colors],
	data() {
		return {};
	},
	watch: {
		isLogin: {
			handler: function (newV, oldV) {
				if (newV) {
					// #ifndef MP
					this.getUserInfo();
					// #endif
				}
			},
			deep: true
		}
	},
	onLoad() {},
	onShow() {},
	methods: {}
};
</script>

<style scoped lang="scss">
.personal-data {
	padding-bottom: 50rpx;
}
.dater {
	width: 400rpx;
}
.grab {
	color: #ccc !important;
}

.personal-data .wrapper {
	margin: 10rpx 0;
	background-color: #fff;
	padding: 36rpx 30rpx 13rpx 30rpx;
}

.personal-data .wrapper .title {
	margin-bottom: 30rpx;
	font-size: 32rpx;
	color: #282828;
}

.personal-data .list {
	margin-top: 15rpx;
	background-color: #fff;
}

.personal-data .list .item {
	padding: 32rpx 20rpx 24rpx 0;
	margin-left: 30rpx;
	font-size: 30rpx;
	color: #282828;
	.label {
		margin-right: 30rpx;
	}
}

.personal-data .list .item .input .id {
	width: 414rpx;
}

.personal-data .list .item .iconfont {
	font-size: 35rpx;
	color: #868686;
}
</style>
