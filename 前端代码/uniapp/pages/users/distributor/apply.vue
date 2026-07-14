<template>
	<view class="warp">
		<view class="w-full fixed-lt z-20" :style="{ 'padding-top': sysHeight + 'px' }">
			<view class="h-80 px-20 flex-between-center">
				<text class="iconfont icon-ic_leftarrow fs-40 text--w111-000" @tap="pageBack"></text>
			</view>
		</view>
		<view class="header-top relative">
			<image :src="headerBg" mode=""></image>
		</view>
		<view class="bg-v-gradient pl-20 pr-20 pb-24 relative">
			<view class="bg--w111-fff rd-24rpx content-box">
				<view class="fs-30 fw-500 lh-42rpx">请填写以下信息</view>
				<view class="cell flex-between-center mt-64">
					<view class="fs-28 lh-40rpx">用户昵称</view>
					<view class="fs-28 text-right">{{ form.nickname }}</view>
				</view>
				<view class="cell flex-between-center mt-64">
					<view class="fs-28 lh-40rpx">用户ID</view>
					<view class="fs-28 text-right">{{ form.uid }}</view>
				</view>
				<view class="cell flex-between-center mt-64">
					<view class="fs-28 lh-40rpx">分销员姓名</view>
					<input type="text" v-model="form.real_name" placeholder="请输入分销员姓名" placeholder-class="text--w111-ccc" class="fs-28 text-right" />
				</view>
				<view class="cell flex-between-center mt-64">
					<view class="fs-28 lh-40rpx">手机号</view>
					<input type="number" v-model="form.phone" placeholder="请输入手机号" placeholder-class="text--w111-ccc" class="fs-28 text-right" />
				</view>
				<view class="cell flex-between-center mt-64">
					<view class="fs-28 lh-40rpx">验证码</view>
					<view class="flex-y-center">
						<input type="number" v-model="form.code" placeholder="请输入验证码" placeholder-class="text--w111-ccc" class="fs-28 text-right w-300" />
						<button class="code-btn w-168 h-56 flex-center fs-24 ml-20" :disabled="disabled" @tap="code">{{ text }}</button>
					</view>
				</view>
				<view class="flex-y-center flex-x-center mt-100rpx mb-24">
					<text class="iconfont fs-30" :class="isSelect ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect'" @tap="proviceSelect"></text>
					<text class="fs-24 text--w111-999 pl-12">已阅读并同意</text>
					<text class="font-red" @tap="getAgreement">《分销说明》</text>
				</view>
				<view class="w-full h-88 rd-44rpx flex-center text--w111-fff fs-28" :class="isSelectStar ? 'bg-red' : 'bg-disabled'" @tap="submitSupply">提交申请</view>
			</view>
		</view>
		<Verify @success="success" captchaType="clickWord" :imgSize="{ width: '330px', height: '155px' }" ref="verify"></Verify>
		<tui-modal :show="showModal" maskClosable custom @cancel="hideModal">
			<view class="tui-modal-custom">
				<view class="fs-32 fw-500 lh-44rpx text-center">分销说明</view>
				<view class="fs-28 text--w111-666 lh-44rpx mt-24 desc-box">
					<!-- #ifdef MP-WEIXIN -->
					<rich-text :nodes="supplierAgreement"></rich-text>
					<!-- #endif -->
					<!-- #ifdef H5 || APP-PLUS -->
					<view v-html="supplierAgreement"></view>
					<!-- #endif -->
				</view>
				<view class="w-full h-72 rd-36rpx flex-center bg-red fs-26 text--w111-fff mt-32" @tap="hideModal">知道了</view>
			</view>
		</tui-modal>
	</view>
</template>

<script>
let sysHeight = uni.getSystemInfoSync().statusBarHeight;
import { toLogin } from '@/libs/login.js';
import { mapGetters } from 'vuex';
import { promoterCreateApi, getCodeApi, registerVerify, userPromoterInfo } from '@/api/user.js';
import colors from '@/mixins/color';
import sendVerifyCode from '@/mixins/SendVerifyCode';
import { HTTP_REQUEST_URL } from '@/config/app';
import Verify from '../components/verify/verify.vue';
import tuiModal from '@/components/tui-modal/index.vue';
export default {
	data() {
		return {
			sysHeight: sysHeight,
			form: {
				nickname: '',
				uid: '',
				phone: '',
				code: '',
				real_name: ''
			},
			isSelect: false,
			keyCode: '',
			id: 0,
			showModal: false,
			supplierAgreement: ''
		};
	},
	components: {
		Verify,
		tuiModal
	},
	mixins: [sendVerifyCode, colors],
	computed: {
		...mapGetters(['isLogin']),
		isSelectStar() {
			if (this.form.nickname && this.form.uid && this.form.phone && this.form.code && this.form.real_name) return true;
		},
		headerBg() {
			return HTTP_REQUEST_URL + '/statics/images/promoter_apply.png';
		}
	},
	onLoad(options) {
		this.getInfo();
	},
	methods: {
		code() {
			if (!this.form.phone)
				return that.$util.Tips({
					title: '请填写手机号码'
				});
			if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(this.form.phone))
				return this.$util.Tips({
					title: '请输入正确的手机号码'
				});
			this.$refs.verify.show();
		},
		success(data) {
			this.$refs.verify.hide();
			getCodeApi()
				.then((res) => {
					this.keyCode = res.data.key;
					this.getCode(data);
				})
				.catch((res) => {
					this.$util.Tips({
						title: res
					});
				});
		},
		async getCode(data) {
			let that = this;
			if (!this.form.phone)
				return that.$util.Tips({
					title: '请填写手机号码'
				});
			if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(this.form.phone))
				return this.$util.Tips({
					title: '请输入正确的手机号码'
				});

			await registerVerify({
				phone: that.form.phone,
				type: 'agent',
				key: that.keyCode,
				captchaType: 'clickWord',
				captchaVerification: data.captchaVerification
			})
				.then((res) => {
					that.$util.Tips({
						title: res.msg
					});
					that.sendCode();
				})
				.catch((res) => {
					that.$util.Tips({
						title: res
					});
				});
		},
		proviceSelect() {
			this.isSelect = !this.isSelect;
		},
		getInfo() {
			userPromoterInfo()
				.then((res) => {
					let data = res.data.user;
					this.id = data.id || 0;
					this.form.nickname = data.nickname || '';
					this.form.uid = data.uid || '';
					this.form.phone = data.phone || '';
					this.form.real_name = data.real_name || '';
					this.supplierAgreement = res.data.agreement.content
				})
				.catch((err) => {
					return this.$util.Tips({
						title: err
					});
				});
		},
		submitSupply() {
			if (!this.isSelectStar)
				return this.$util.Tips({
					title: '请完整填写表单信息'
				});
			if (!this.isSelect)
				return this.$util.Tips({
					title: '请阅读并同意协议'
				});
			promoterCreateApi(this.id, this.form)
				.then((res) => {
					uni.navigateTo({
						url: '/pages/users/agent/state?id=' + res.data.id
					});
				})
				.catch((err) => {
					return this.$util.Tips({
						title: err
					});
				});
		},
		pageBack() {
			uni.navigateBack();
		},
		getAgreement() {
			this.showModal = true;
		},
		hideModal() {
			this.showModal = false;
		}
	}
};
</script>

<style lang="scss" scoped>
.warp {
	background: #ffe5d5;
	min-height: 100vh;
	.bg-v-gradient{
		margin-top: -150rpx;
		z-index: 11;
	}
}
.header-top {
	width: 100%;
	height: 552rpx;
	image{
		width: 100%;
		height: 100%;
	}
}
.rule-btn {
	height: 48rpx;
	background: rgba(0, 0, 0, 0.15);
	border-radius: 24rpx 0 0 24rpx;
	position: absolute;
	right: 0;
}
.content-box {
	padding: 48rpx 32rpx 40rpx;
}
.code-btn {
	border: 1px solid #e93323;
	color: #e93323;
	border-radius: 28rpx;
}
.upload {
	border: 1rpx dashed #ccc;
}
.del-pic {
	background-color: #999;
	border-radius: 0 16rpx 0 16rpx;
}
.icon-a-ic_CompleteSelect,
.font-red {
	color: #e93323;
}
.icon-ic_unselect {
	color: #ccc;
}
.bg-red {
	background-color: #e93323;
}
.bg-disabled {
	background-color: rgba(233, 51, 35, 0.5);
}
.desc-box {
	max-height: 700rpx;
	overflow-y: auto;
}
.modal-bottom {
	height: 136rpx;
	border-radius: 0 0 32rpx 32rpx;
	background-color: #fff;
}
</style>