<template>
	<!-- 地址下拉框 -->
	<tuiDrawer :visible="display" mode="bottom" backgroundColor="transparent" @close="close">
		<view class="address-window">
			<view class='title'>
				请选择所在地区
				<view class="btn" @click="confirm" v-if="!checkStrictly">确定</view>
			</view>
			<view class="address-count">
				<view class="acea-row row-middle address-selected-box">
					<view class="address-selected acea-row">
						<view v-for="(item,index) in selectedArr" :key="index" class="selected-list" :class="{active:index === selectedIndex}" @click="change(item.pid, index)">
							{{item.label}}
						</view>
						<view class="selected-list" :class="{active:-1 === selectedIndex}" v-if="showMore" @click="change(-1, -1)">
							请选择
						</view>
					</view>
					<view class="btn" @click="refresh" v-if="!checkStrictly">
						<text class="iconfont icon-ic_Refresh"></text>重置
					</view>
				</view>
				<scroll-view scroll-y="true" :scroll-top="scrollTop" class="address-list" @scroll="scroll">
					<view v-for="(item,index) in addressList" :key="index" class="list" :class="{active:item.id === activeId}" @click="selected(item)">
						<text class="item-name">{{item.label}}</text>
						<text v-if="item.id === activeId" class="iconfont icon-duihao2"></text>
					</view>
				</scroll-view>
			</view>
		</view>
	</tuiDrawer>
</template>

<script>
	// +----------------------------------------------------------------------
	// | MOHE [ MOHE赋能开发者，助力企业发展 ]
	// +----------------------------------------------------------------------
	// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
	// +----------------------------------------------------------------------
	// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
	// +----------------------------------------------------------------------
	// | Author: MOHE Team <admin@mohe.com>
	// +----------------------------------------------------------------------
	import {
		getCityData
	} from '@/api/api.js';
	import tuiDrawer from '@/components/tui-drawer/tui-drawer.vue';

	const CACHE_ADDRESS = {};

	export default {
		components: {
			tuiDrawer
		},
		props: {
			display: {
				type: Boolean,
				default: false
			},
			cityShow: {
				type: Number,
				default: 3
			},
			address: {
				type: Array | Object,
				default: []
			},
			checkStrictly: {
				type: Boolean,
				default: true
			},
		},
		data() {
			return {
				active: 0,
				//地址列表
				addressList: [],
				selectedArr: [],
				selectedIndex: -1,
				is_loading: false,
				old: {
					scrollTop: 0
				},
				scrollTop: 0
			};
		},
		computed: {
			activeId() {
				return this.selectedIndex == -1 ? 0 : this.selectedArr[this.selectedIndex].id
			},
			showMore() {
				return this.selectedArr.length ? (this.selectedArr[this.selectedArr.length - 1].hasOwnProperty('children') && ((this.cityShow == 1 && this.addressList.level < 2) || (this.cityShow == 2 &&
					this.addressList.level < 3) || (this.cityShow == 3 && this.addressList.level < 4))) : true
			}
		},
		watch: {
			address(n) {
				this.selectedArr = n ? [...n] : []
			},
			display(n) {
				if (!n) {
					this.addressList = [];
					this.selectedArr = this.address ? [...this.address] : [];
					this.selectedIndex = -1;
					this.is_loading = false;
				} else {
					this.loadAddress(0)
				}
			}
		},
		mounted() {
			this.loadAddress(0)
		},
		methods: {
			change(pid, index) {
				if (this.selectedIndex == index) return;
				if (pid === -1) {
					pid = this.selectedArr.length ? this.selectedArr[this.selectedArr.length - 1].id : 0;
				}
				this.selectedIndex = index;
				this.loadAddress(pid);
			},
			loadAddress(pid) {
				if (CACHE_ADDRESS[pid]) {
					this.addressList = CACHE_ADDRESS[pid];
					return;
				}
				this.is_loading = true;
				getCityData(pid).then(res => {
					this.is_loading = false;
					CACHE_ADDRESS[pid] = res.data;
					this.addressList = res.data;
				})
				this.goTop()
			},
			selected(item) {
				if (this.is_loading) return;
				if (this.selectedIndex > -1) {
					this.selectedArr.splice(this.selectedIndex + 1, 999)
					this.selectedArr[this.selectedIndex] = item;
					this.selectedIndex = -1;
				} else if (!item.pid) {
					this.selectedArr = [item];
				} else {
					this.selectedArr.push(item);
				}
				if (item.hasOwnProperty('children') && ((this.cityShow == 1 && this.addressList[0].level < 2) || (this.cityShow == 2 && this.addressList[0].level < 3) || (this.cityShow == 3 && this
						.addressList[0].level < 4))) {
					this.loadAddress(item.id);
				} else {
					this.$emit('submit', [...this.selectedArr]);
					this.$emit('changeClose');
				}

				this.goTop()
			},
			close: function() {
				this.$emit('changeClose');
			},
			scroll: function(e) {
				this.old.scrollTop = e.detail.scrollTop
			},
			goTop: function(e) {
				this.scrollTop = this.old.scrollTop
				this.$nextTick(() => {
					this.scrollTop = 0
				});
			},
			confirm() {
				this.$emit('submit', [...this.selectedArr]);
				this.$emit('changeClose');
			},
			refresh() {
				this.selectedArr = []
				this.loadAddress(0)
			}
		}
	}
</script>

<style scoped lang="scss">
	.address-window {
		background: #FFFFFF;
		border-radius: 30rpx 30rpx 0 0;
	}

	.address-window .title {
		font-size: 32rpx;
		font-weight: bold;
		text-align: center;
		height: 108rpx;
		line-height: 108rpx;
		position: relative;
	}

	.address-window .title .iconfont {
		position: absolute;
		right: 30rpx;
		color: #8a8a8a;
		font-size: 35rpx;
	}

	.address-window .title .btn {
		position: absolute;
		top: 50%;
		right: 40rpx;
		font-weight: 400;
		font-size: 28rpx;
		color: #333333;
		transform: translateY(-50%);
	}

	.address-count {
		.address-selected-box {
			padding: 0 32rpx 0 0;
			border-bottom: 2rpx solid #F5F5F5;

			.btn {
				width: 96rpx;
				height: 36rpx;
				border: 1rpx solid #CCCCCC;
				border-radius: 18rpx;
				transform: rotateZ(360deg);
				text-align: center;
				font-size: 20rpx;
				line-height: 36rpx;
				color: #666666;

				.iconfont {
					margin-right: 4rpx;
					font-size: 20rpx;
				}
			}
		}

		.address-selected {
			flex: 1;
			padding: 0 40rpx;
			position: relative;
		}

		.selected-list {
			font-size: 26rpx;
			color: #282828;
			line-height: 50rpx;
			// padding-bottom: 16rpx;
			padding: 16rpx 0;
			margin-left: 40rpx;
			position: relative;

			&.active::after {
				content: "";
				position: absolute;
				bottom: 0;
				left: 50%;
				width: 32rpx;
				height: 6rpx;
				border-radius: 2rpx;
				background: var(--view-theme);
				transform: translateX(-50%);
				// color: var(--view-theme);
			}

			// &:before,&:after{
			// 	content: '';
			// 	display: block;
			// 	position: absolute;
			// }
			// &:before{
			// 	width: 4rpx;
			// 	height: 100%;
			// 	background-color: var(--view-theme);
			// 	top: 0;
			// 	left: 10rpx;
			// }
			// &:after{
			// 	width: 12rpx;
			// 	height: 12rpx;
			// 	background: var(--view-theme);
			// 	border-radius: 100%;
			// 	left: 6rpx;
			// 	top: 50%;
			// 	margin-top: -8rpx;
			// }
			// &:first-child,&:last-child{
			// 	&:before{
			// 		height: 50%;
			// 	}
			// }
			// &:first-child{
			// 	&:before{
			// 		top: auto;
			// 		bottom: 0;
			// 	}
			// }
			&:first-child {
				margin-left: 0;
			}

			.iconfont {
				font-size: 20rpx;
				float: right;
				color: #dddddd;
			}
		}

		scroll-view {
			height: 550rpx;
		}

		.address-list {
			padding: 0 30rpx;
			margin-top: 20rpx;
			box-sizing: border-box;

			.list {
				.iconfont {
					float: right;
					color: #ddd;
					font-size: 22rpx;
				}

				.item-name {
					display: inline-block;
					line-height: 50rpx;
					margin-bottom: 20rpx;
					font-size: 26rpx;
				}

				&.active {
					color: var(--view-theme);

					.iconfont {
						color: var(--view-theme);
					}
				}
			}
		}
	}
</style>
