<template>
	<!-- 旧管理端底部导航：有商家入口权限时停用（保留组件一个版本周期） -->
	<view v-if="footerEnabled" class="page-footer">
		<view v-if="$util.auth(item.auth)" class="foot-item" :class="item.pagePath == activeRouter?'active':''" v-for="(item,index) in footerList" :key="index" @click="goRouter(item)">
			<block v-if="item.pagePath == activeRouter">
				<image :src="item.selectedIconPath"></image>
				<view class="txt">{{item.text}}</view>
			</block>
			<block v-else>
				<image :src="item.iconPath"></image>
				<view class="txt">{{item.text}}</view>
			</block>
		</view>
	</view>
</template>

<script>
	import {
		mapGetters
	} from "vuex";
	export default {
		name: 'footers',
		props:{
			isWork: {
				type: Number,
				default: 0
			}
		},
		computed: mapGetters(["storeNum"]),
		created() {
			let routes = getCurrentPages(); //获取当前打开过的页面路由数组
			let curRoute = routes[routes.length - 1].route //获取当前页面路由
			this.activeRouter = '/' + curRoute
			this.resolveFooterEnabled();
		},
		mounted() {},
		data() {
			return {
				activeRouter:'',
				footerEnabled: true,
				footerList:[
					{
						pagePath: (this.storeNum || this.isWork)?"/pages/admin/work/index":"/pages/admin/work/store",
						iconPath: require("../../static/footer1-1.png"),
						selectedIconPath: require("../../static/footer1-2.png"),
						text: "工作台"
					},
					{
						pagePath: "/pages/admin/goods/index",
						iconPath: require("../../static/footer2-1.png"),
						selectedIconPath: require("../../static/footer2-2.png"),
						text: "商品",
						auth:'mall-admin-product'
					},
					{
						pagePath: "/pages/admin/orderList/index",
						iconPath: require("../../static/footer3-1.png"),
						selectedIconPath: require("../../static/footer3-2.png"),
						text: "订单",
						auth:'mall-admin-order'
					},
					{
						pagePath: "/pages/admin/user/list",
						iconPath: require("../../static/footer4-1.png"),
						selectedIconPath: require("../../static/footer4-2.png"),
						text: "用户",
						auth:'mall-admin-user'
					}
				]
			}
		},
		methods: {
			async resolveFooterEnabled() {
				if (!this.$store || !this.$store.dispatch) {
					return;
				}
				try {
					await this.$store.dispatch('merchant/fetchAccess', true);
					if (this.$store.state.merchant.canEnter) {
						// 统一商家端启用后停用旧 4 页 footer，商品/订单请从商家工作台进入
						this.footerEnabled = false;
					}
				} catch (e) {
					// 权限校验失败时不拦旧 footer，避免误伤
				}
			},
			goRouter(item) {
				var pages = getCurrentPages();
				var page = (pages[pages.length - 1]).$page.fullPath;
				if (item.pagePath == page) return

				uni.redirectTo({
				  url: item.pagePath,
				  animationType: 'none' // 关闭默认的滑动效果
				});
			}
		}
	}
</script>

<style scoped lang="scss">
	.page-footer {
		position: fixed;
		bottom: 0;
		left:0;
		z-index: 20;
		display: flex;
		align-items: center;
		justify-content: space-around;
		width: 100%;
		height: calc(100rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(100rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		box-sizing: border-box;
		border-top: solid 1rpx #F3F3F3;
		background-color: #fff;
		padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
		padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/

		.foot-item {
			display: flex;
			width: max-content;
			align-items: center;
			justify-content: center;
			flex-direction: column;
			position: relative;
			padding: 0 20rpx;
			&.active {
				color: #2A7EFB
			}
		}

		.foot-item image {
			height: 40rpx;
			width: 40rpx;
			text-align: center;
			margin: 0 auto;
		}

		.foot-item .txt {
			font-size: 20rpx;
			margin-top: 6rpx;
		}
	}
</style>
