<template>
	<!-- 顶部下拉导航 -->
	<view class="animated dialog_nav dialogIndex" 
		:style="{ top: navH + 'px' }" v-show="currentPage">
		<view class="dialog_nav_item" :class="item.after" 
			v-for="(item,index) in headerNav" :key="index" 
			@tap="linkPage(item.url)">
			<text class="iconfont" :class="item.icon"></text>
			<text class="pl-20">{{item.name}}</text>
		</view>
	</view>
</template>
<script>
	export default {
		name: "homeIdex",
		props: {
			navH: {
				type: String|Number,
				default: ""
			},
			currentPage: {
				type: Boolean,
				default: false
			},
			openNavList:{
				type: Array,
				default: ()=>[]
			}
		},
		data() {
			return {
				selectNavList:[
					{name:'首页',icon:'icon-ic_mall',url:'/pages/index/index',after:'dialog_after'},
					{name:'搜索',icon:'icon-ic_search',url:'/pages/goods/goods_search/index',after:'dialog_after'},
					{name:'购物车',icon:'icon-ic_ShoppingCart1',url:'/pages/order_addcart/order_addcart',after:'dialog_after'},
					{name:'我的收藏',icon:'icon-ic_star',url:'/pages/users/user_goods_collection/index',after:'dialog_after'},
					{name:'个人中心',icon:'icon-a-ic_user1',url:'/pages/user/index'},
				]
			};
		},
		computed:{
			headerNav(){
				let arr = [];
				this.selectNavList.forEach((item,index)=>{
					if(this.openNavList.includes(index)){
						arr.push(item);
					}
				})
				return arr
			}
		},
		methods: {
			linkPage(url){
				if (['/pages/goods_cate/goods_cate', '/pages/order_addcart/order_addcart', '/pages/user/index', '/pages/index/index','/pages/store_list/index']
					.indexOf(url) == -1) {
					uni.navigateTo({
						url: url
					})
				} else {
					uni.switchTab({
						url: url
					})
				}
			}
		},
	};
</script>

<style scoped lang="scss">
	.dialog_nav{
		position: fixed;
		left: 14rpx;
		width: 240rpx;
		background: #FFFFFF;
		box-shadow: 0px 0px 16rpx rgba(0, 0, 0, 0.08);
		z-index: 310;
		border-radius: 14rpx;
		&::before{
			content: '';
			width: 0;
			height: 0;
			position: absolute;
			left: -26rpx;
			right: 0;
			margin:auto;
			top:-9px;
			border-bottom: 10px solid #FFFFFF;
			border-left: 10px solid transparent;    /*transparent 表示透明*/
			border-right: 10px solid transparent;
		}
		&.dialogIndex{
			left: 14rpx;
			&::before{
				left: 0rpx;
			}
		}
	}
	.dialog_nav_item{
		width: 100%;
		height: 84rpx;
		line-height: 84rpx;
		padding: 0 20rpx 0;
		box-sizing: border-box;
		border-bottom: #eee;
		font-size: 28rpx;
		color: #333;
		position: relative;
		display: flex;
		.iconfont{
			font-size: 32rpx;
			margin-right: 26rpx;
		}
	}
	.dialog_after{
		::after{
			content: '';
			position: absolute;
			width:90px;
			height: 1px;
			background-color: #EEEEEE;
			bottom: 0;
			right: 0;
		}
	}
</style>
