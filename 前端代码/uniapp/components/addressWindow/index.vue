<template>
	<view>
		<base-drawer mode="bottom" :visible="address.address" :zIndex="zIndex" :maskZIndex="zIndex" background-color="transparent" mask maskClosable @close="close">
			<view class="w-full bg--w111-fff rd-t-40rpx py-32">
				<view class="text-center fs-32 text--w111-333 fw-500">选择地址</view>
				<view class="mt-64 px-32">
					<view class="mb-38 flex-between-center" 
						v-for="(item,index) in addressList" :key="index"
						:class="{'font-num': active==index}"
						@tap='tapAddress(index,item.id,item)'>
						<text class='iconfont icon-ic_location5 fs-36'></text>
						<view class="flex-1 pl-40">
							<view class="fs-28 fw-500">{{item.real_name}}<text class='phone'>{{item.phone}}</text></view>
							<view class="w-560 line1 mt-4">{{item.province}}{{item.city}}{{item.district}}{{item.street}}{{item.detail}}</view>
						</view>
					</view>
					<view v-if="!is_loading && !addressList.length">
						<emptyPage title="暂无地址信息～" src="/statics/images/noAddress.png"></emptyPage>
					</view>
				</view>
				<view class="mx-20 pb-safe">
					<view class="mt-52 h-72 flex-center rd-36px bg-color fs-26 text--w111-fff" @tap='goAddressPages'>选择其它地址</view>
				</view>
			</view>
		</base-drawer>
	</view>
</template>

<script>
	import {getAddressList} from '@/api/user.js';
	import {adminUserAddressList} from '@/api/admin.js';
	import {HTTP_REQUEST_URL} from '@/config/app';
	import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
	import emptyPage from '@/components/emptyPage.vue';
	export default {
		props: {
			fromType: {
				type: Number,
				default: 0,
			},
			pagesUrl: {
				type: String,
				default: '',
			},
			address: {
				type: Object,
				default: function() {
					return {
						address: true,
						addressId: 0,
					};
				}
			},
			isLog: {
				type: Boolean,
				default: false,
			},
			userId: {
				type: [String, Number],
				default: 0,
			},
			zIndex: {
				type: [String, Number],
				default: 980,
			},
		},
		components: {
			baseDrawer,
			emptyPage
		},
		data() {
			return {
				active: 0,
				//地址列表
				addressList: [],
				is_loading: true,
				imgHost: HTTP_REQUEST_URL
			};
		},
		methods: {
			tapAddress: function(e, addressid, row) {
				this.active = e;
				this.$emit('OnChangeAddress', addressid, row);
			},
			close: function() {
				this.$emit('changeClose');
				this.$emit('changeTextareaStatus');
			},
			goAddressPages: function() {
				this.$emit('changeClose');
				this.$emit('changeTextareaStatus');
				uni.navigateTo({
					url: this.pagesUrl
				});
			},
			getAddressList: function() {
				let that = this;
				if (that.userId) {
					adminUserAddressList(that.userId, {
						page: 1,
						limit: 100
					}).then(res => {
						let addressList = res.data;
						//处理默认选中项
						for (let i = 0, leng = addressList.length; i < leng; i++) {
							if (addressList[i].id == that.address.addressId) {
								that.active = i;
							}
						}
						that.$set(that, 'addressList', addressList);
						this.$emit('OnChangeAddress', addressList[that.active].id, addressList[that.active]);
						that.is_loading = false;
					})
					return;
				}
				getAddressList({
					page: 1,
					limit: 5
				}).then(res => {
					let addressList = res.data;
					//处理默认选中项
					for (let i = 0, leng = addressList.length; i < leng; i++) {
						if (addressList[i].id == that.address.addressId) {
							that.active = i;
						}
					}
					that.$set(that, 'addressList', addressList);
					this.$emit('addressList', addressList);
					that.is_loading = false;
				})
			}
		}
	}
</script>