<template>
	<view class="pt-24 pr-20 pb-24 pl-20">
		<view class="pt-8 pb-8 rd-24rpx bg--w111-fff">
			<view class="flex p-24" v-for="item in cartInfo" :key="item.id">
				<image :src="item.productInfo.image" class="w-136 h-136 rd-16rpx"></image>
				<view class="flex-1 flex-col justify-between px-20">
					<view class="line2 fs-28 lh-40rpx">{{ item.productInfo.store_name }}</view>
					<view class="fs-24 text--w111-999">{{ item.productInfo.attrInfo.suk }}</view>
				</view>
				<view class="flex-col justify-between items-end">
					<baseMoney :money="item.productInfo.attrInfo.price" symbolSize="20" integerSize="32" decimalSize="20"></baseMoney>
					<view class="fs-24 SemiBold">x{{ item.cart_num }}</view>
				</view>
			</view>
		</view>
		<view class="pt-32 pr-24 pb-32 pl-24 rd-16rpx mt-20 bg--w111-fff">
			<view class="flex fs-28 lh-40rpx">
				<view class="w-184">用户昵称</view>
				<view class="flex-1 text-right">{{ realName }}</view>
			</view>
			<view class="flex mt-26 fs-28 lh-40rpx">
				<view class="w-184">用户手机号</view>
				<view class="flex-1 text-right">{{ userPhone }}</view>
			</view>
			<view class="flex mt-26 fs-28 lh-40rpx">
				<view class="w-184">用户地址</view>
				<view class="flex-1 text-right">{{ userAddress }}</view>
			</view>
		</view>
		<view class="pt-32 pr-24 pb-32 pl-24 rd-16rpx mt-20 bg--w111-fff">
			<view class="">
				<view class="flex-between-center">
					<view class="fs-28">送达凭证</view>
					<view class="fs-24 text--w111-999">{{ delivery_voucher.length }}/100</view>
				</view>
				<view class="mt-24">
					<textarea v-model="delivery_voucher" placeholder="请填写送达文字说明或上传图片凭证" class="fs-26" placeholder-class="fs-26 text-w111-ccc" :maxlength="100" />
				</view>
			</view>
			<view class="flex flex-wrap mr-f18 mb-f20">
				<view class="w-148 h-148 rd-16rpx mr-18 mb-20 bg--w111-f5f5f5 relative" v-for="(item, index) in delivery_voucher_img" :key="index">
					<image :src="item" class="w-full h-full rd-16rpx"></image>
					<view class="abs-rt flex-center w-32 h-32 rd-rt-16rpx rd-lb-16rpx bg-w111-999" @tap="deleteImage(index)">
						<text class="iconfont icon-ic_close fs-24 text--w111-fff"></text>
					</view>
				</view>
				<view class="flex-col flex-center w-148 h-148 rd-16rpx mb-20 bg--w111-f5f5f5 upload" v-if="delivery_voucher_img.length < 8" @tap="uploadImage">
					<view class="">
						<text class="iconfont icon-ic_camera fs-48"></text>
					</view>
					<view class="mt-8">上传凭证</view>
				</view>
			</view>
		</view>
		<view class="pb-safe">
			<view class="p-20">
				<view class="h-80"></view>
			</view>
		</view>
		<view class="fixed-lb w-full pb-safe bg--w111-fff">
			<view class="p-20">
				<view class="flex-center h-80 rd-40rpx bg-w111-2A7EFB fw-500 fs-28 text--w111-fff" @tap="submitDelivery">提交</view>
			</view>
		</view>
		<canvas canvas-id="canvas" style="position: fixed;top: -1000px;left: -1000px;"></canvas>
	</view>
</template>

<script>
	import {
		getAdminOrderDetail,
		confirmDelivery,
	} from '@/api/admin.js';

	export default {
		data() {
			return {
				order_id: '',
				cartInfo: [],
				realName: '',
				userPhone: '',
				userAddress: '',
				delivery_id: '',
				delivery_voucher: '',
				delivery_voucher_img: [],
				delta: 1,
			}
		},
		onLoad(option) {
			this.order_id = option.order_id;
			this.delivery_id = option.delivery_id;
			this.getOrderDetail();
		},
		onShow() {
			let pages = getCurrentPages();
			let index = pages.findIndex(page => page.route == 'pages/admin/distribution/index');
			if (index != -1) {
				this.delta = pages.length - 1 - index;
			}
		},
		methods: {
			getOrderDetail() {
				getAdminOrderDetail(this.order_id).then(res => {
					const {
						cartInfo,
						real_name,
						user_phone,
						user_address,
					} = res.data;
					this.cartInfo = cartInfo;
					this.realName = real_name;
					this.userPhone = user_phone;
					this.userAddress = user_address;
				});
			},
			uploadImage() {
				this.$util.uploadImageChange({
					count: 8,
					url: 'upload/image'
				}, res => {
					this.delivery_voucher_img.push(res.data.url);
				});
			},
			deleteImage(index) {
				this.delivery_voucher_img.splice(index, 1);
			},
			submitDelivery() {
				if (!this.delivery_voucher.trim() && !this.delivery_voucher_img.length) {
					this.$util.Tips({
						title: '请上传送达凭证'
					});
					return;
				}
				confirmDelivery({
					order_id: this.order_id,
					delivery_id: this.delivery_id,
					delivery_voucher: this.delivery_voucher,
					delivery_voucher_img: this.delivery_voucher_img,
				}).then(res => {
					this.$util.Tips({
						title: res.msg
					}, () => {
						uni.navigateBack({
							delta: this.delta
						});
					});
				});
			},
		},
	}
</script>

<style lang="scss" scoped>
	.upload {
		border: 1rpx dashed #CCCCCC;
	}
</style>