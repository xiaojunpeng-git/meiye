<template>
	<view class="agreement" :style="colorStyle">
		<view class="flex-center">
			<text class="iconfont icon-a-ic_tanhao1"></text>
		</view>
		<view class="name text-center">账号注销风险</view>
		<view class="content" v-html="agreementData.content"></view>
		<view class="footerH"></view>
		<view class="footer">
			<view class="trip">
				<checkbox-group @change="ChangeIsDefault">
					<checkbox :class="inAnimation ? 'trembling' : ''" @animationend="inAnimation = false" :checked="protocol ? true : false" />
					我已阅读并同意
					<text class="red" @click="privacy('privacy ')">《隐私协议》</text>
				</checkbox-group>
			</view>
			<view class="cancellation flex-aj-center" @click="next">下一步</view>
		</view>
		<view class="mark" v-show="isCancellation"></view>
		<tui-modal :show="isCancellation" maskClosable custom @cancel="isCancellation = false">
			<view class="tui-modal-custom">
				<view class="fs-32 fw-500 lh-44rpx text-center">是否确认注销</view>
				<view class="fs-30 text--w111-666 lh-42rpx text-center mt-22">注销后无法恢复，请谨慎操作</view>
				<view class="flex-y-center">
					<view class="w-full h-72 rd-36rpx flex-center border b-solid b--w111-ccc text-primary-con fs-26  mt-32 mr-16 clear-btn" @tap="cancelUser">注销</view>
					<view class="w-full h-72 rd-36rpx flex-center bg-red fs-26 text--w111-fff mt-32 ml-16" @tap="isCancellation = false">取消</view>
				</view>
			</view>
		</tui-modal>
	</view>
</template>

<script>
import colors from '@/mixins/color.js';
import { getUserAgreement, cancelUser, getLogout } from '@/api/user.js';
import tuiModal from '@/components/tui-modal/index.vue';
export default {
	mixins: [colors],
	components: { tuiModal },
	data() {
		return {
			isCancellation: false,
			agreementData: '',
			protocol: false,
			inAnimation: false,
		};
	},
	onLoad() {
		this.getAgreement();
	},
	onShow() {
		uni.removeStorageSync('form_type_cart');
	},
	methods: {
		ChangeIsDefault(e) {
			this.$set(this, 'protocol', !this.protocol);
		},
		getAgreement() {
			getUserAgreement('cancel').then((res) => {
				this.agreementData = res.data;
			});
		},
		next() {
			if (this.protocol) {
				this.isCancellation = true;
			} else {
				this.$util.Tips({
					title: '请先阅读隐私协议'
				});
			}
		},
		cancelUser() {
			cancelUser()
				.then((res) => {
					this.$store.commit('LOGOUT');
					uni.reLaunch({
						url: '/pages/index/index'
					});
				})
				.catch((msg) => {
					return this.$util.Tips({
						title: msg
					});
				});
		},
		privacy(type) {
			uni.navigateTo({
				url: '/pages/users/privacy/index?type=' + type
			});
		}
	}
};
</script>

<style lang="scss" scoped>
/deep/checkbox .wx-checkbox-input.wx-checkbox-input-checked {
	border: 1px solid #e93323 !important;
	background-color: #e93323 !important;
}
/deep/checkbox .uni-checkbox-input.uni-checkbox-input-checked {
	border: 1px solid #e93323 !important;
	background-color: #e93323 !important;
}
.footerH {
	height: 250rpx;
}
.agreement {
	background-color: #fff;
	padding-top: 84rpx;
	.content {
		padding: 0 30rpx;
	}
	.name {
		padding: 30rpx 0 48rpx;
		font-size: 36rpx;
		font-weight: 500;
		color: #333333;
		line-height: 50rpx;
	}
}

.top-msg {
	display: flex;
	align-items: center;
	background-color: #fff;
	padding: 40rpx 30rpx 40rpx 30rpx;
}
.icon-a-ic_tanhao1 {
	font-size: 68rpx;
	color: #e93323;
}
.footer {
	text-align: center;
	z-index: 99;
	width: 100%;
	background-color: #fafafa;
	position: fixed;
	padding: 0 30rpx 36rpx 30rpx;
	box-sizing: border-box;
	border-top: 1rpx solid #eee;
	bottom: 0rpx;
	.red {
		color: #e93323;
	}
	.trip {
		color: #999999;
		font-size: 24rpx;
		margin: 24rpx 0;
	}

	.cancellation {
		height: 45px;
		color: #fff;
		font-size: 32rpx;
		background: #e93323;
		border-radius: 23px;
	}
}

.mark {
	position: fixed;
	top: 0;
	left: 0;
	bottom: 0;
	right: 0;
	background: rgba(0, 0, 0, 0.5);
	z-index: 99;
}
.clear-btn{
	border: 1px solid #E93323;
	color: #E93323;
}
</style>
