<template>
	<map :style="'width: '+ windowWidth +'px; height: '+ (windowHeight) +'px;'" class="map-view" :key="mapKey" :scale="9" :latitude="latitudeStore" :longitude="longitudeStore" :markers="covers" @markertap="markertap">
		<view v-if="markerIdTapped" class="cover-view">
			<view class="cover-view-item">
				<text class="cover-view-text title">{{ markertapped.name }}</text>
				<view class="store-wrap" @tap="openStore(markertapped.id)">
					<text class="store">进店选购</text>
					<text class="iconfont icon-xiangyou">&#xe679;</text>
				</view>
			</view>
			<view class="cover-view-item">
				<view class="address-wrap">
					<rich-text class="cover-view-text address" :nodes="richNodes(markertapped)"></rich-text>
				</view>
				<view class="group-button">
					<view class="button" @tap="makePhoneCall(markertapped.phone)">
						<text class="iconfont icon-dianhua">&#xe7fc;</text>
					</view>
					<view class="button" @tap="openLocation(markertapped)">
						<text class="iconfont icon-dingwei2">&#xe7fb;</text>
					</view>
				</view>
			</view>
		</view>
	</map>
</template>

<script>
	import colors from "@/mixins/color";
	import {
		getList
	} from '@/api/new_store.js';

	export default {
		mixins: [colors],
		beforeCreate() {
			const domModule = uni.requireNativePlugin('dom')
			domModule.addRule('fontFace', {
				'fontFamily': "iconfont",
				'src': "url('http://at.alicdn.com/t/c/font_993865_0rmejz9fdizr.ttf?t=1689647362545')"
			});
		},
		data() {
			return {
				latitudeStore: uni.getStorageSync('user_latitude'),
				longitudeStore: uni.getStorageSync('user_longitude'),
				content: {
					latitude: uni.getStorageSync('user_latitude'),
					longitude: uni.getStorageSync('user_longitude'),
					store_type: 1,
					keyword: "",
					province: 0,
					city: 0,
					area: 0,
				},
				storeList: [],
				markerIdTapped: 0,
				mapKey: '',
				windowWidth: 0,
				windowHeight: 0,
			}
		},
		computed: {
			covers() {
				return this.storeList.map(({
					id,
					latitude,
					longitude,
					image
				}) => ({
					id,
					latitude,
					longitude,
					iconPath: image,
					width: 30,
					height: 30
				}));
			},
			markertapped() {
				return this.storeList.find(item => item.id === this.markerIdTapped);
			}
		},
		onLoad(option) {
			this.windowWidth = uni.getSystemInfoSync().windowWidth;
			this.windowHeight = uni.getSystemInfoSync().windowHeight;
			this.content.province = option.province;
			this.content.city = option.city;
			this.content.area = option.area;
			this.getList();
		},
		methods: {
			getList() {
				getList(this.content).then(res => {
					let storeList = res.data;
					this.storeList = storeList;
				});
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
			},
			richNodes(item) {
				return [{
					children: [{
							type: 'text',
							attrs: {
								class: 'lin-msg-name'
							},
							text: item.detailed_address
						}
					]
				}]
			}
		},
	}
</script>

<style lang="scss" scoped>
	.iconfont {
		font-family:iconfont;
	}
	.map-view {
		position: fixed;
		top: 0;
		right: 0;
		bottom: 0;
		left: 0;
	}

	.cover-view {
		position: absolute;
		right: 48rpx;
		bottom: 48rpx;
		left: 48rpx;
		padding: 28rpx 28rpx 24rpx 30rpx;
		border-radius: 12rpx;
		background-color: #FFFFFF;
		box-shadow: 0 0 28rpx 0rpx rgba(0, 0, 0, 0.1);
	}

	.cover-view-item {
		flex-direction: row;
	}
	
	.cover-view-item + .cover-view-item {
		margin-top: 18rpx;
	}

	.cover-view-text {
		flex: 1;
		padding-right: 50rpx;
	}

	.title {
		font-weight: bold;
		font-size: 28rpx;
		color: #333333;
	}
	
	.store-wrap {
		flex-direction: row;
	}

	.store {
		flex-direction: row;
		font-size: 22rpx;
		line-height: 28rpx;
		color: #E93323;
	}
	
	.icon-xiangyou {
		margin-left: 10rpx;
		font-size: 22rpx;
		line-height: 28rpx;
		color: #E93323;
	}
	
	.address-wrap {
		flex: 1;
	}

	.address {
		font-size: 22rpx;
		line-height: 32rpx;
		color: #888888;
	}

	.group-button {
		flex-direction: row;

		.button {
			flex-direction: row;
			justify-content: center;
			align-items: center;
			width: 40rpx;
			height: 40rpx;
			border-radius: 50%;
			background-color: rgba(233, 51, 35, 0.1);

			+.button {
				margin-left: 20rpx;
			}
		}

		.iconfont {
			font-size: 20rpx;
			color: #E93323;
		}
	}
</style>