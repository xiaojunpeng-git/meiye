<template>
	<!-- 添加新发票 -->
	<view :style="colorStyle" class="invoice-form">
		<!-- #ifdef MP -->
		<view v-if="!id" class="flex-y-center h-100 pr-32 pl-32 rd-24rpx mb-20 bg--w111-fff" @tap="getWxInvoiceTitle">
			<text class="iconfont icon-ic_wechat fs-48 text-w111-00C800 mr-20"></text>
			<view class="flex-1 fw-500 fs-30 text--w111-3d3d3d">获取微信发票抬头</view>
			<text class="iconfont icon-ic_rightarrow fs-32"></text>
		</view>
		<!-- #endif -->
		<form @submit="formSubmit">
			<view class="panel">
				<view class="acea-row row-middle">
					<view class="label">发票类型</view>
					<view class="button-group">
						<view v-for="item in invoiceTypeList" :key="item.value" :class="{ active: type == item.value }" class="button" @click="changeType(item)">{{ item.name }}</view>
					</view>
				</view>
				<view v-if="type == 1" class="acea-row row-middle">
					<view class="label">抬头类型</view>
					<view class="button-group">
						<view v-for="item in headerTypeList" :key="item.value" :class="{ active: header_type == item.value }" class="button" @click="changeHeaderType(item.value)">{{ item.name }}
						</view>
					</view>
				</view>
				<view class="acea-row row-middle">
					<view class="label">发票抬头</view>
					<input name="name" :value="name" placeholder="请输入发票抬头" />
				</view>
				<view v-show="header_type == 2" class="acea-row row-middle">
					<view class="label">单位税号</view>
					<input name="duty_number" :value="duty_number" placeholder="请输入单位税号" />
				</view>
				<view class="acea-row row-middle">
					<view class="label">{{header_type==2?'企业':'联系'}}电话</view>
					<input name="drawer_phone" :value="drawer_phone" :placeholder="`请输入${header_type==2?'企业':'联系'}电话`" />
				</view>
				<view class="acea-row row-middle">
					<view class="label">电子邮箱</view>
					<input name="email" :value="email" placeholder="请输入电子邮箱" />
				</view>
				<template v-if="type == 2">
					<view class="acea-row row-middle">
						<view class="label">开户银行</view>
						<input name="bank" :value="bank" placeholder="请输入开户银行" />
					</view>
					<view class="acea-row row-middle">
						<view class="label">银行账号</view>
						<input name="card_number" :value="card_number" placeholder="请输入银行账号" />
					</view>
					<view class="acea-row row-middle">
						<view class="label">企业地址</view>
						<input name="address" :value="address" placeholder="请输入企业地址" />
					</view>
				</template>
			</view>
			<view class="default acea-row row-between-wrapper">
				<view>设置为默认抬头</view>
				<view :class="{ checked: is_default }" class="switch" @click="changeDefault"></view>
			</view>
			<view class="button-section">
				<button class="button" form-type="submit">保存</button>
			</view>
		</form>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import home from '@/components/home';
	import {
		invoiceSave,
		invoiceDetail
	} from '@/api/user.js';
	import colors from '@/mixins/color.js';
	export default {
		components: {
			home
		},
		mixins: [colors],
		data() {
			return {
				invoiceTypeList: [{
						name: '普通发票',
						value: 1,
						info: '纸质发票开出后将以邮寄形式交付'
					},
					{
						name: '专用发票',
						value: 2,
						info: '纸质发票开出后将以邮寄形式交付'
					}
				],
				headerTypeList: [{
						name: '个人',
						value: 1,
					},
					{
						name: '企业',
						value: 2,
					}
				],
				id: '', // 修改时为必须参数
				header_type: 1, // 抬头类型1: 个人2： 企业
				type: 1, // 发票类型1：普通2：专用
				drawer_phone: '', // 开票人手机号
				name: '', // 名称（发票抬头）
				duty_number: '', // 税号（个人为非必需，企业是必需参数）
				tell: '', // 公司注册电话
				address: '', // 注册地址
				bank: '', // 开户行
				card_number: '', // 银行卡号
				is_default: 0, // 是否默认
				email: '', // 邮箱
				popupType: false,
				typeName: '',
				urlQuery: '',
				from: '',
				specialInvoice: true,
				order_id: '',
        homeHide: false
			};
		},
		computed: {
			backUrl() {
				switch (this.from) {
					case 'order_confirm':
						return `/pages/goods/order_confirm/index${this.urlQuery}`;
						break;
					default:
						return '/pages/users/user_invoice_list/index?from=invoice_form';
						break;

				}
			}
		},
		onLoad(options) {
			for (let key in options) {
				switch (key) {
					case 'delivery_type':
					case 'productType':
					case 'is_store':
					case 'product_id':
					case 'store_name':
					case 'store_id':
					case 'couponTitle':
					case 'new':
					case 'cartId':
					case 'pinkId':
					case 'couponId':
					case 'addressId':
						this.urlQuery += `${this.urlQuery ? '&' : '?'}${key}=${options[key]}`;
						break;
					case 'from':
						this.from = options[key];
						break;
					case 'header_type':
						this.header_type = options[key];
						break;
					case 'id':
						this.id = options[key];
						this.getInvoiceDetail();
						break;
					case 'specialInvoice':
						if (options[key] === 'false') {
							this.specialInvoice = false;
						}
						break;
				}
			}
			if (options.order_id)
				this.order_id = options.order_id
			const invoiceItem = this.invoiceTypeList.find(item => item.value === this.type);
			this.typeName = invoiceItem.name;
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			// 获取发票数据
			getInvoiceDetail() {
				uni.showLoading({
					title: '加载中'
				});
				invoiceDetail(this.id).then(res => {
					uni.hideLoading();
					this.header_type = res.data.header_type;
					this.type = res.data.type;
					const invoiceItem = this.invoiceTypeList.find(item => item.value === this.type);
					this.typeName = invoiceItem.name;
					this.name = res.data.name;
					this.drawer_phone = res.data.drawer_phone;
					this.email = res.data.email;
					this.duty_number = res.data.duty_number;
					this.bank = res.data.bank;
					this.card_number = res.data.card_number;
					this.address = res.data.address;
					this.is_default = res.data.is_default;
				}).catch(err => {
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
			},
			// 保存
			formSubmit(e) {
				const formData = e.detail.value;
				formData.type = this.type;
				formData.header_type = this.header_type;
				if (formData.header_type == 1) {
					if (!formData.name) {
						return this.$util.Tips({
							title: '请输入发票抬头（开具发票的姓名）'
						});
					}
					if (!formData.drawer_phone) {
						return this.$util.Tips({
							title: '请输入您的联系电话（手机号）'
						});
					}
					// if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(formData.drawer_phone)) {
					// 	return this.$util.Tips({
					// 		title: '请正确输入您的联系电话（手机号）'
					// 	});
					// }
					if (!formData.email) {
						return this.$util.Tips({
							title: '请输入您的电子邮箱'
						});
					}
					if (!/^\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/.test(formData.email)) {
						return this.$util.Tips({
							title: '请正确输入您的电子邮箱'
						});
					}
				}
				if (formData.header_type == 2) {
					if (formData.type == 1) {
						if (!formData.name) {
							return this.$util.Tips({
								title: '请输入发票抬头（开具发票的企业名称）'
							});
						}
						if (!formData.duty_number) {
							return this.$util.Tips({
								title: '请输入单位税号（纳税人识别号）'
							});
						}
						if (!/[0-9A-HJ-NPQRTUWXY]{2}\d{6}[0-9A-HJ-NPQRTUWXY]{10}/.test(formData.duty_number)) {
							return this.$util.Tips({
								title: '请正确输入单位税号（纳税人识别号）'
							});
						}
						if (!formData.drawer_phone) {
							return this.$util.Tips({
								title: '请输入企业电话（手机号）'
							});
						}
						// if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(formData.drawer_phone)) {
						// 	return this.$util.Tips({
						// 		title: '请正确输入企业电话（手机号）'
						// 	});
						// }
						if (!formData.email) {
							return this.$util.Tips({
								title: '请输入您的电子邮箱'
							});
						}
						if (!/^\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/.test(formData.email)) {
							return this.$util.Tips({
								title: '请正确输入您的电子邮箱'
							});
						}
					}
					if (formData.type == 2) {
						if (!formData.name) {
							return this.$util.Tips({
								title: '请输入发票抬头（开具发票的企业名称）'
							});
						}
						if (!formData.duty_number) {
							return this.$util.Tips({
								title: '请输入单位税号（纳税人识别号）'
							});
						}
						if (!/[0-9A-HJ-NPQRTUWXY]{2}\d{6}[0-9A-HJ-NPQRTUWXY]{10}/.test(formData.duty_number)) {
							return this.$util.Tips({
								title: '请正确输入单位税号（纳税人识别号）'
							});
						}
						if (!formData.drawer_phone) {
							return this.$util.Tips({
								title: '请输入联系电话（手机号）'
							});
						}
						// if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(formData.drawer_phone)) {
						// 	return this.$util.Tips({
						// 		title: '请正确输入联系电话（手机号）'
						// 	});
						// }
						if (!formData.email) {
							return this.$util.Tips({
								title: '请输入您的电子邮箱'
							});
						}
						if (!/^\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/.test(formData.email)) {
							return this.$util.Tips({
								title: '请正确输入您的电子邮箱'
							});
						}
						if (!formData.bank) {
							return this.$util.Tips({
								title: '请输入您的开户银行'
							});
						}
						if (!formData.card_number) {
							return this.$util.Tips({
								title: '请输入您的银行账号'
							});
						}
						if (!formData.address) {
							return this.$util.Tips({
								title: '请输入您所在的企业地址'
							});
						}
						formData.tell = formData.drawer_phone;
					}
				}
				formData.is_default = this.is_default;
				formData.id = this.id;
				uni.showLoading({
					title: '保存中'
				});
				invoiceSave(formData).then(res => {
					let that = this;
					uni.showToast({
						title: '保存成功',
						icon: 'success',
						success() {
							switch (that.from) {
								case 'order_confirm':
									let name = '';
									name += formData.header_type == 1 ? '个人' : '企业';
									name += formData.type == 1 ? '普通' : '专用';
									name += '发票';
									if (that.id) {
										uni.navigateTo({
											url: `/pages/goods/order_confirm/index${that.urlQuery}&invoice_id=${that.id}&invoice_name=${name}`
										})
									} else {
										uni.navigateTo({
											url: `/pages/goods/order_confirm/index${that.urlQuery}&invoice_id=${res.data.id}&invoice_name=${name}`
										})
									}
									break;
								case 'order_details':
									if (that.id) {
										uni.navigateTo({
											url: `/pages/goods/order_details/index?order_id=${that.order_id}&invoice_id=${that.id}`
										})
									} else {
										uni.navigateTo({
											url: `/pages/goods/order_details/index?order_id=${that.order_id}&invoice_id=${res.data.id}`
										})
									}
									break;
								default:
									uni.navigateTo({
										url: '/pages/users/user_invoice_list/index?from=invoice_form'
									});
									break;
							}

						}
					});
				}).catch(err => {
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
			},
			// 调起发票类型弹窗
			callType() {
				this.popupType = true;
			},
			// 选择发票类型
			changeType(item) {
				if (item.value == 2) {
					this.header_type = 2;
				}
				this.typeName = item.name;
				this.type = item.value;
			},
			// 关闭发票弹窗
			closeType() {
				this.popupType = false;
			},
			// 选择抬头类型
			changeHeaderType(value) {
				this.header_type = value;
				this.type = 1;
			},
			// 设置为默认抬头
			changeDefault() {
				this.is_default = 1 - this.is_default;
			},
      onhomehide() {
				this.homeHide = false;
			},
			// #ifdef MP
			getWxInvoiceTitle() {
				uni.chooseInvoiceTitle({
					success: (res) => {
						this.header_type = res.type == 1 ? 1 : 2;
						this.name = res.title;
						this.duty_number = res.taxNumber;
						this.drawer_phone = res.telephone;
					}
				});
			},
			// #endif
		}
	}
</script>

<style lang="scss" scoped>
	.invoice-form {
		padding: 24rpx 20rpx;

		.label {
			color: #999999;
		}
	}

	/deep/.disabled .uni-radio-input {
		background-color: #F8F8F8;
	}

	form {
		font-size: 28rpx;
		color: #282828;
	}

	form input,
	form radio-group {
		flex: 1;
		margin-left: 24rpx;
	}

	form input {
		font-size: 26rpx;
	}

	form label {
		margin-right: 50rpx;
	}

	form radio {
		margin-right: 8rpx;
	}

	form checkbox-group {
		height: 90rpx;
	}

	form checkbox {
		margin-right: 20rpx;
	}

	.panel {
		padding: 8rpx 0;
		border-radius: 24rpx;
		background-color: #FFFFFF;
	}

	.panel .acea-row {
		padding: 32rpx 24rpx;
	}

	.panel .button-group {
		margin-left: 24rpx;

		.button {
			display: inline-block;
			height: 56rpx;
			padding: 0 32rpx;
			border: 1rpx solid #F5F5F5;
			border-radius: 28rpx;
			background: #F5F5F5;
			font-weight: 500;
			font-size: 24rpx;
			line-height: 54rpx;
			color: #333333;

			+.button {
				margin-left: 16rpx;
			}

			&.active {
				border-color: var(--view-theme);
				background-color: var(--view-minorColorT);
				color: var(--view-theme);
			}
		}
	}

	.default {
		height: 128rpx;
		padding: 0 24rpx;
		border-radius: 24rpx;
		margin-top: 20rpx;
		background-color: #FFFFFF;
		font-size: 28rpx;
		line-height: 40rpx;
		color: #333333;

		.switch {
			position: relative;
			width: 79rpx;
			height: 48rpx;
			padding: 4rpx;
			border-radius: 24rpx;
			background-color: #DDDDDD;
			transition: background-color 0.3s;

			&::after {
				content: "";
				position: absolute;
				top: 4rpx;
				left: 4rpx;
				width: 40rpx;
				height: 40rpx;
				border-radius: 20rpx;
				background-color: #FFFFFF;
				box-shadow: 0rpx 3rpx 6rpx 0rpx rgba(0, 0, 0, 0.08);
				transition: transform 0.3s cubic-bezier(0.4, 0.4, 0.25, 1.35);
			}

			&.checked {
				background-color: var(--view-theme);

				&::after {
					transform: translateX(30rpx);
				}
			}
		}
	}

	.input-placeholder {
		font-size: 26rpx;
		color: #BBBBBB;
	}

	.icon-xiangyou {
		margin-left: 25rpx;
		font-size: 26rpx;
		color: #BFBFBF;
		margin-top: 2rpx;
	}

	.popup {
		position: fixed;
		bottom: 0;
		left: 0;
		z-index: 99;
		width: 100%;
		padding-bottom: 100rpx;
		border-top-left-radius: 16rpx;
		border-top-right-radius: 16rpx;
		background-color: #F5F5F5;
		overflow: hidden;
		transform: translateY(100%);

		transition: 0.3s;
	}

	.popup.on {
		transform: translateY(0);
	}

	.popup .title {
		position: relative;
		height: 137rpx;
		font-size: 32rpx;
		line-height: 137rpx;
		text-align: center;
	}

	.popup scroll-view {
		height: 466rpx;
		padding-right: 30rpx;
		padding-left: 30rpx;
		box-sizing: border-box;
	}

	.popup label {
		padding: 35rpx 30rpx;
		border-radius: 16rpx;
		margin-bottom: 20rpx;
		background-color: #FFFFFF;
	}

	.popup .text {
		flex: 1;
		min-width: 0;
		font-size: 28rpx;
		color: #282828;
	}

	.popup .info {
		margin-top: 10rpx;
		font-size: 22rpx;
		color: #909090;
	}

	.popup .icon-guanbi {
		position: absolute;
		top: 50%;
		right: 30rpx;
		z-index: 2;
		transform: translateY(-50%);
		font-size: 30rpx;
		color: #707070;
		cursor: pointer;
	}

	.popup .text .acea-row {
		display: inline-flex;
		max-width: 100%;
	}

	.popup .name {
		flex: 1;
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
		font-size: 30rpx;
	}

	.popup .label {
		width: 56rpx;
		height: 28rpx;
		border: 1rpx solid #E93323;
		margin-left: 18rpx;
		font-size: 20rpx;
		line-height: 26rpx;
		text-align: center;
		color: #E93323;
	}

	.popup .type {
		width: 124rpx;
		height: 42rpx;
		margin-top: 14rpx;
		background-color: #FCF0E0;
		font-size: 24rpx;
		line-height: 42rpx;
		text-align: center;
		color: #D67300;
	}

	.popup .type.special {
		background-color: #FDE9E7;
		color: #E93323;
	}

	.button-section {
		position: fixed;
		right: 0;
		bottom: 0;
		left: 0;
		padding: 20rpx 20rpx calc(20rpx + env(safe-area-inset-bottom));
		background-color: #FFFFFF;
	}

	.button-section .button {
		height: 80rpx;
		border-radius: 40rpx;
		background-color: var(--view-theme);
		font-weight: 500;
		font-size: 28rpx;
		line-height: 80rpx;
		color: #FFFFFF;
	}

	.button-section .navigator {
		height: 86rpx;
		border: 1rpx solid var(--view-theme);
		border-radius: 43rpx;
		margin-top: 26rpx;
		font-size: 30rpx;
		line-height: 86rpx;
		text-align: center;
		color: var(--view-theme);
	}
</style>