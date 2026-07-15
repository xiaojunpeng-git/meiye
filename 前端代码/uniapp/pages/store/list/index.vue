<template>
	<view :style="colorStyle">
		<!-- #ifdef MP || APP -->
		<view class="storeTitle">
			<view :style="{height:getHeight.barTop+'px'}"></view>
			<view class="sysTitle acea-row row-center-wrapper" :style="{height:getHeight.barHeight+'px'}">
				<view>门店列表</view>
				<text class="iconfont icon-ic_leftarrow" @click="goarrow"></text>
			</view>
		</view>
		<view :style="{height:(getHeight.barTop+getHeight.barHeight)+'px'}"></view>
		<!-- #endif -->
		<view class="header acea-row row-between-wrapper" :class="isReservationMode ? 'header-reservation' : ''">
			<template v-if="!isReservationMode">
				<view class="item" :class="content.store_type==item.type?'on':''" v-for="(item, index) in navList" :key="index" @click="navChange(item.type)">{{item.name}}</view>
			</template>
			<view class="search acea-row row-middle" :class="isReservationMode ? 'search-full' : ''">
				<view class="name" v-if="!isReservationMode" @click="btnFn">{{ addressInfo.length ? addressInfo[1].label : '全部' }}<text class="iconfont icon-ic_down2"></text></view>
				<input class="input" placeholder="请输入门店名称" placeholder-class="placeholders"  @confirm="searchSubmit" v-model="content.keyword"></input>
				<text class="iconfont icon-ic_search" @click="searchSubmit"></text>
			</view>
			<view class="pictrue" v-if="!isReservationMode" @click="goMap">
				<image src="@/static/images/map.png"></image>
			</view>
		</view>
		<view class="list" v-if="storeList.length">
			<view class="item acea-row row-between-wrapper" :class="sortIndex==item.id?'on':''" v-for="(item, index) in storeList" :key="index" @click="activeFn(item)">
				<text class="iconfont icon-a-icon_cornermark" v-if="sortIndex==item.id"></text>
				<view class="left">
					<view class="name line2">{{item.name}}</view>
					<view class="address acea-row">
						<text class="iconfont icon-ic_location51 pt-2"></text>
						<view class="flex-1">{{item.address}}{{item.detailed_address}}</view>
					</view>
					<view class="address time"><text class="iconfont icon-icon_clock"></text>营业时间：{{item.day_time}}</view>
				</view>
				<view class="right">
					<view class="gostore" @click.stop="gostore(item)">进店选购</view>
					<view class="distance" v-if="!isReservationMode && item.range">距离{{item.range}}km</view>
					<view class="telephone acea-row row-center-wrapper">
						<view class="phone acea-row row-center-wrapper" @click.stop="callPhone(item)">
							<text class="iconfont icon-ic_Phone"></text>
						</view>
						<view class="phone acea-row row-center-wrapper" @click.stop="showMaoLocation(item)">
							<text class="iconfont icon-icon_Location"></text>
						</view>
					</view>
				</view>
			</view>
		</view>
		<view class="emptyPage px-20" v-else>
			<emptyPage title="暂无门店信息~" src="/statics/images/noOrder.gif"></emptyPage>
		</view>
		<areaWindow ref="areaWindow" :display="display" :cityShow="2" :address="addressInfo" @submit="OnChangeAddress" @changeClose="changeClose"></areaWindow>
	</view>
</template>

<script>
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import colors from "@/mixins/color";
	import {
		getList
	} from '@/api/new_store.js'
	import areaWindow from '@/components/areaWindow';
	import emptyPage from '@/components/emptyPage.vue';
	export default {
		components: {
			areaWindow,
			emptyPage
		},
		mixins:[colors],
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				getHeight: this.$util.getWXStatusHeight(),
				navList:[
					{name:'附近',type:1},
					{name:'常用',type:2}
				],
				storeList:[],
				content: {
					// 自己的位置
					latitude: uni.getStorageSync('user_latitude'),
					longitude: uni.getStorageSync('user_longitude'),
					store_type: 1,
					keyword: "",
					province: 0,
					city: 0,
					area: 0,
					is_select: 0,
					card_product_id: 0,
					is_all: 0,
				},
				display: false,
				addressInfo: [],
				sortIndex: 0,
				comeType: 0,
				isCollage:0,
				isSelect:0
			};
		},
		computed: {
			isReservationMode() {
				return Number(this.isCollage) === 3;
			}
		},
		onLoad(options) {
			this.comeType = options.type
			this.sortIndex = options.storeId
			this.isCollage = options.isCollage
			this.content.product_id = options.product_id || 0
			this.content.is_select = options.is_select || 0
			this.content.card_product_id = options.card_product_id || 0
			if (this.isReservationMode) {
				this.content.is_all = 1
				this.content.latitude = ''
				this.content.longitude = ''
			}
			this.getlist()
		},
		onShow() {
		},
		methods: {
			goarrow(){
				uni.navigateBack()
			},
			navChange(type) {
				this.content.store_type = type;
				this.getlist();
			},
			// 输入关键字搜索门店
			searchSubmit() {
				this.getlist()
			},
			// 门店列表
			getlist() {
				getList(this.content).then(res => {
					this.storeList = res.data
				})
			},
			btnFn() {
				this.display = true;
			},
			changeClose() {
				this.display = false;
			},
			OnChangeAddress(address) {
				this.addressInfo = address;
				this.content.province = address[0].value;
				this.content.city = address[1].value;
				this.content.area = address[2].value;
				this.getlist();
			},
			goMap() {
				uni.navigateTo({
					// #ifdef APP
					url: `/pages/store/mapnvue/index?province=${this.content.province}&city=${this.content.city}&area=${this.content.area}`
					// #endif
					// #ifndef APP
					url: `/pages/store/map/index?province=${this.content.province}&city=${this.content.city}&area=${this.content.area}`
					// #endif
				})
			},
			// 点击高亮
			activeFn(row) {
				this.sortIndex = row.id
				if (this.comeType) {
					if (this.isCollage == 1) {
						uni.$emit('activeFn', row);
						uni.navigateBack();
						return;
					}else if(this.isCollage == 2){
						uni.$emit('activeHome', row);
						uni.navigateBack();
						return;
					}else if(this.isCollage == 3){
						uni.$emit('activeReservation', row);
						uni.navigateBack();
						return;
					}
					uni.reLaunch({
						url: `/pages/store_cate/store_cate?mapFrom=1&id=` + row.id
					});
				}
			},
			gostore(row) {
				uni.navigateTo({
					url: '/pages/store/home/index?mapFrom=1&id=' + row.id
				})
			},
			// 打电话
			callPhone(row) {
				uni.makePhoneCall({
					phoneNumber: row.phone
				});
			},
			showMaoLocation(e) {
				let self = this;
				// #ifdef H5
				if (self.$wechat.isWeixin()) {
					return self.$wechat.seeLocation({
						latitude: Number(e.latitude),
						longitude: Number(e.longitude),
						name: e.name,
						scale: 13,
						address: `${e.address}-${e.detailed_address}`,
					}).then(res => {})
				}
				// #endif
				uni.openLocation({
					latitude: Number(e.latitude),
					longitude: Number(e.longitude),
					name: e.name,
					address: `${e.address}-${e.detailed_address}`,
					success: function() {
						Number
					}
				});
			}
		}
	}
</script>

<style lang="scss" scoped>
	.emptyPage{
		position: relative;
		
	}
	.storeTitle{
		background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%);
		position: fixed;
		left:0;
		top:0;
		width: 100%;
		z-index: 99;
		.sysTitle{
			width: 100%;
			position: relative;
			font-weight: 500;
			color: #fff;
			font-size: 30rpx;
			.iconfont{
				position: absolute;
				font-size: 36rpx;
				left:11rpx;
				width: 60rpx;
			}
		}
	}
	.header{
		background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%);
		padding: 40rpx 20rpx 42rpx 32rpx;
		position: relative;
		&.header-reservation{
			padding-left: 20rpx;
		}
		&::before{
			position: absolute;
			content: ' ';
			width: 50%;
			height: 90rpx;
			background: linear-gradient(180deg, var(--view-gradient) 0%, #F5F5F5 100%);
			right: 0;
			bottom: -90rpx;
		}
		&::after{
			position: absolute;
			content: ' ';
			width: 50%;
			height: 90rpx;
			background: linear-gradient(180deg, var(--view-theme) 0%, #F5F5F5 100%);
			left: 0;
			bottom: -90rpx;
		}
		.item{
			font-family: PingFang SC, PingFang SC;
			font-weight: 400;
			font-size: 28rpx;
			color: rgba(255,255,255,0.6);
			margin-right: 22rpx;
			&.on{
				font-weight: 500;
				color: #FFFFFF;
				position: relative;
				font-size: 30rpx;
				&::after{
					position: absolute;
					content: '';
					width: 36rpx;
					height: 30rpx;
					border: 2px solid #FFFFFF;
					border-left: 2px solid transparent !important;
					border-top: 2px solid transparent !important;
					border-right: 2px solid transparent !important;
					border-radius: 50%;
					left:50%;
					bottom: -12rpx;
					margin-left: -24rpx;
				}
			}
		}
		.search{
			width: 416rpx;
			height: 58rpx;
			background: rgba(255,255,255,0.3);
			border-radius: 50rpx;
			font-size: 24rpx;
			padding: 0 24rpx;
			&.search-full{
				width: 100%;
				flex: 1;
			}
			.icon-ic_search{
				font-size: 32rpx;
				color: rgba(255,255,255,0.6);
				padding-left: 20rpx;
			}
			.name{
				color: #FFFFFF;
				position: relative;
				.iconfont{
					font-size: 16rpx;
					margin-left: 6rpx;
				}
				&:before{
					position: absolute;
					content: '';
					width: 1px;
					height: 24rpx;
					background-color: rgba(255,255,255,0.4);
					right: 0;
					top: 50%;
					margin-top: -12rpx;
				}
				padding-right: 20rpx;
			}
			.input{
				font-size: 24rpx;
				color: #FFFFFF;
				flex: 1;
				margin-left: 20rpx;
			}
			::v-deep .placeholders{
				color: rgba(255,255,255,0.6);
				font-size: 24rpx;
			}
		}
		.pictrue{
			width: 58rpx;
			height: 58rpx;
			image {
				width: 100%;
				height: 100%;
				border-radius: 50%;
			}
		}
	}
	.list{
		position: relative;
		padding-bottom: 1rpx;
		.item{
			background-color: #fff;
			width: 710rpx;
			margin: 0 auto 20rpx auto;
			border-radius: 16rpx;
			padding: 30rpx 0 30rpx 24rpx;
			font-family: PingFang SC, PingFang SC;
			position: relative;
			overflow: hidden;
			&.on{
				border: 1px solid var(--view-theme);
			}
			.icon-a-icon_cornermark{
				position: absolute;
				right: -2rpx;
				top: -4rpx;
				color:var(--view-theme);
				font-size: 50rpx;
			}
			.left{
				flex: 1;
				position: relative;
				border-right: 1px solid #eee;
				padding-right: 20rpx;
				.name{
					font-weight: 500;
					font-size: 28rpx;
					color: #333333;
				}
				.address{
					font-weight: 400;
					font-size: 22rpx;
					color: #999999;
					margin-top: 16rpx;
					.iconfont{
						color: #666;
						font-size: 24rpx;
						margin-right: 14rpx;
					}
					.icon-icon_clock{
						font-size: 22rpx;
					}
				}
				.time{
					margin-top: 10rpx;
				}
			}
			.right{
				width: 182rpx;
				text-align: center;
				.gostore{
					font-weight: 400;
					font-size: 22rpx;
					color: var(--view-theme);
				}
				.distance{
					font-weight: 400;
					font-size: 20rpx;
					color: #999999;
					margin-top: 14rpx;
				}
				.telephone{
					.phone{
						width: 40rpx;
						height: 40rpx;
						border-radius: 50%;
						background: var(--view-minorColorT);
						margin: 20rpx 10rpx 0 10rpx;
						.iconfont{
							font-size: 20rpx;
							color: var(--view-theme);
						}
					}
				}
			}
		}
	}
</style>