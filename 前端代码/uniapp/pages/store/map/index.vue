<template>
	<view :style="colorStyle">
		<map class="map-view" :key="mapKey" :scale="9" :latitude="latitudeStore" :longitude="longitudeStore" :markers="covers" @markertap="markertap">
			<view v-if="markerIdTapped" class="cover-view">
				<view class="cover-view-item">
					<view class="cover-view-text title">{{ markertapped.name }}</view>
					<view class="store" @tap="openStore(markertapped.id)">进店选购<view class="iconfont icon-xiangyou"></view>
					</view>
				</view>
				<view class="cover-view-item">
					<view class="cover-view-text address">{{ markertapped.detailed_address }}</view>
					<view class="group-button">
						<view class="button" @tap="makePhoneCall(markertapped.phone)">
							<view class="iconfont icon-ic_phone"></view>
						</view>
						<view class="button" @tap="openLocation(markertapped)">
							<view class="iconfont icon-ic_location"></view>
						</view>
					</view>
				</view>
			</view>
		</map>
	</view>
</template>

<script>
	import colors from "@/mixins/color";
	import {
		getList
	} from '@/api/new_store.js';

	export default {
		mixins: [colors],
		data() {
			return {
				latitudeStore: uni.getStorageSync('user_latitude'),
				longitudeStore: uni.getStorageSync('user_longitude'),
				content: {
					latitude: uni.getStorageSync('user_latitude'),
					longitude: uni.getStorageSync('user_longitude'),
					store_type: 1,
				},
				storeList: [],
				markerIdTapped: 0,
				mapKey: ''
			}
		},
		computed: {
			covers() {
				return this.storeList.map(({
					id,
					latitude,
					longitude,
					image: iconPath
				}) => ({
					id,
					latitude,
					longitude,
					iconPath,
					width: 30,
					height: 30
				}));
			},
			markertapped() {
				return this.storeList.find(item => item.id === this.markerIdTapped);
			}
		},
		onLoad() {
			this.getList();
		},
		methods: {
			getList() {
				getList(this.content).then(res => {
					let storeList = res.data;
					this.storeList = storeList;
					// for (let i = 0; i < this.storeList.length; i++) {
					// 	this.downloadImage(i);
					// }
				});
			},
			downloadImage(index) {
				uni.request({
					url: this.storeList[index].image,
					responseType:'arraybuffer',
					success: (res) => {
						const base64 = uni.arrayBufferToBase64(res.data);
						this.$set(this.storeList[index], 'path', 'data:image/png;base64,' + base64);
						this.mapKey = index;
					}
				})
			},
			markertap(e) {
				this.markerIdTapped = e.detail.markerId;
			},
			openStore(id) {
				uni.navigateTo({
					url: '/pages/store/home/index?mapFrom=1&id=' + id
				})
			},
			makePhoneCall(phoneNumber) {
				uni.makePhoneCall({
					phoneNumber
				});
			},
			openLocation({
				latitude,
				longitude,
				name,
				address,
				detailed_address
			}) {
				uni.openLocation({
					latitude: Number(latitude),
					longitude: Number(longitude),
					name,
					address: `${address}-${detailed_address}`
				});
			}
		},
	}
</script>

<style lang="scss" scoped>
	.map-view {
		position: fixed;
		top: 0;
		right: 0;
		width: 100%;
		height: 100%;
	}

	.cover-view {
		position: absolute;
		right: 48rpx;
		bottom: 48rpx;
		bottom: calc(48rpx + constant(safe-area-inset-bottom));
		bottom: calc(48rpx + env(safe-area-inset-bottom));
		left: 48rpx;
		padding: 28rpx 28rpx 24rpx 30rpx;
		border-radius: 12rpx;
		background-color: #FFFFFF;
		box-shadow: 0 0 28rpx 0rpx rgba(0, 0, 0, 0.1);
	}

	.cover-view-item {
		display: flex;

		+.cover-view-item {
			margin-top: 18rpx;
		}
	}

	.cover-view-text {
		flex: 1;
		min-width: 0;
		padding-right: 50rpx;
	}

	.title {
		font-weight: bold;
		font-size: 28rpx;
		color: #333333;
	}

	.store {
		display: flex;
		font-size: 22rpx;
		line-height: 28rpx;
		color: var(--view-theme);

		.iconfont {
			margin-left: 10rpx;
			font-size: 22rpx;
		}
	}

	.address {
		white-space: normal;
		font-size: 22rpx;
		line-height: 32rpx;
		color: #888888;
	}

	.group-button {
		align-self: center;
		display: flex;

		.button {
			display: flex;
			justify-content: center;
			align-items: center;
			width: 40rpx;
			height: 40rpx;
			border-radius: 50%;
			background-color: var(--view-minorColorT);

			+.button {
				margin-left: 20rpx;
			}
		}

		.iconfont {
			font-size: 20rpx;
			color: var(--view-theme);
		}
	}
</style>