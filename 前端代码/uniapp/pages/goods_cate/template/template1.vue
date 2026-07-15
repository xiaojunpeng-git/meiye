<template>
  <!-- 商品分类第一种布局样式 -->
	<view class='productSort copy-data' :style="{height:pageHeight}">
		<view :style="{paddingTop: sysHeight + 'px'}" class="text--w111-999">
			<view class="h-80 px-32 flex-y-center">
				<!-- #ifdef MP -->
				<view class="flex-1 h-58 flex-y-center rd-30rpx bg--w111-fff px-32" @tap="goSearch">
				<!-- #endif -->
				<!-- #ifndef MP -->
				<view class="w-full h-58 flex-y-center rd-30rpx bg--w111-fff px-32" @tap="goSearch">
				<!-- #endif -->
					<text class="iconfont icon-ic_search fs-28"></text>
					<text class="fs-24 pl-18">请输入商品名称</text>
				</view>
				<!-- 胶囊占据的位置 -->
				<!-- #ifdef MP -->
				<view class="ml-12" :style="'width:'+getHeight.barWidth+'px'"></view>
				<!-- #endif -->
			</view>
		</view>
		<view class="scroll-box">
			<view class='aside'>
				<scroll-view scroll-y="true" scroll-with-animation='true' :style="[asideHeights]">
					<view class='item flex-center' :class='index==navActive?"on":""'
						v-for="(item,index) in productList" :key="index" @click='tap(index,"b"+index)'>
						<view class="top-ra">
							<text></text>
						</view>
						<view class="line1">{{item.cate_name}}</view>
						<view class="bottom-ra">
							<text></text>
						</view>
					</view>
					<!-- #ifdef APP-PLUS -->
					<view class="item" v-if="isFooter"></view>
					<!-- #endif -->
				</scroll-view>
			</view>
			<view class='conter' v-if="level == 2">
				<scroll-view scroll-y="true" :scroll-into-view="toView" @scroll="scroll" scroll-with-animation='true'
				 class="conterScroll height-add">
					<block v-for="(item,index) in productList" :key="index">
						<view class='listw' :id="'b'+index">
							<view class="card mt-20">
								<view class="title" @tap="goPage(1,`/pages/goods/goods_list/index?cid=${item.id}&title=${item.cate_name}`)">{{item.cate_name}} <text class="iconfont icon-ic_rightarrow"></text> </view>
								<view class="grid_box">
									<block v-for="(itemn,indexn) in item.children" :key="indexn">
										<navigator hover-class='none'
											:url='"/pages/goods/goods_list/index?sid="+itemn.id+"&title="+itemn.cate_name'
											class='item acea-row row-column row-middle'>
											<easy-loadimage
											:image-src="itemn.pic"
											width="130rpx"
											height="130rpx"
											borderRadius="12rpx"></easy-loadimage>
											<view class='cate_name line1 w-144'>{{itemn.cate_name}}</view>
										</navigator>
									</block>
								</view>
							</view>
						</view>
					</block>
					<view :style='"height:"+(height-heightDiv)+"rpx;"'></view>
				</scroll-view>
			</view>
			<view class="conter relative" v-else-if="level == 3">
				<view class="flex-1 pl-24 pr-20 abs-lt" v-if="productList.length && productList[navActive].children.length">
					<view class="flex mt-24 mb-16">
						<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-464"
							show-scrollbar="false" :scroll-into-view="navThreeActiveId" scroll-with-animation>
							<view class="inline-block mr-16"
								:id="`navThree${index}`"
								v-for="(item,index) in productList[navActive].children" :key="index">
								<view class="w-144 zl h-56 rd-30rpx bg--w111-fff flex-center fs-24 text--w111-333"
									:class="index == tabClick ? 'cate_active':''"
									@tap="longClick(index,item)">
									<view class="line1">{{item.cate_name}}</view>
								</view>
							</view>
						</scroll-view>
						<view class="bntCon w-56 h-56 rd-30rpx bg--w111-fff flex-center ml-16"
							@tap="openCateDrawer()">
							<text class="iconfont icon-ic_downarrow fs-32 text--w111-333"></text>
						</view>
					</view>
				</view>
				<view class="h-96" v-if="productList.length && productList[navActive].children.length"></view>
				<view v-if="imageShow">
					<emptyPage title="暂无商品，去看点别的吧～" ></emptyPage>
				</view>
				<scroll-view scroll-y="true" scroll-with-animation='true'
					class="conterScroll" :style="[conterHeights]" v-if="productList.length">
					<block>
						<view class='listw' v-for="(item,index) in productList[navActive].children" v-if="tabClick == index">
							<view class="card mb-20" v-if="item.children && item.children.length">
								<view class="title" @tap="goPage(1,`/pages/goods/goods_list/index?sid=${item.id}&title=${item.cate_name}`)">
									<text>{{item.cate_name}} </text>
									<text class="iconfont icon-ic_rightarrow"></text>
								</view>
								<view class="grid_box">
									<block v-for="(itemn,indexn) in item.children" :key="indexn">
										<navigator hover-class='none'
											:url='"/pages/goods/goods_list/index?tid="+itemn.id+"&title="+itemn.cate_name'
											class='item acea-row row-column row-middle'>
											<easy-loadimage
											:image-src="itemn.pic"
											width="130rpx"
											height="130rpx"
											borderRadius="12rpx"></easy-loadimage>
											<view class='cate_name line1 w-144'>{{itemn.cate_name}}</view>
										</navigator>
									</block>
								</view>
							</view>
						</view>
					</block>
					<view :style='"height:"+(height-heightDiv)+"rpx;"'></view>
				</scroll-view>
			</view>
		</view>
		<view class="more_box abs-lt w-full bg--w111-fff rd-b-32rpx z-20" v-if="showCateDrawer && level == 3">
			<view :style="{paddingTop: sysHeight + 'px'}">
				<view class="h-80 px-32 flex-y-center">
					<!-- #ifdef MP -->
					<view class="flex-1 h-58 flex-y-center rd-30rpx bg--w111-f5f5f5 px-32">
					<!-- #endif -->
					<!-- #ifndef MP -->
					<view class="w-full h-58 flex-y-center rd-30rpx bg--w111-f5f5f5 px-32">
					<!-- #endif -->
						<text class="iconfont icon-ic_search fs-28"></text>
						<text class="fs-24 text--w111-999 pl-18">请输入商品名称</text>
					</view>
					<!-- 胶囊占据的位置 -->
					<!-- #ifdef MP -->
					<view class="ml-12" :style="'width:'+getHeight.barWidth+'px'"></view>
					<!-- #endif -->
				</view>
			</view>
			<view class="pt-32 pl-30 pr-30">
				<view>
					<view class="fs-32 text--w111-333" v-if="productList[navActive].children.length">
						{{productList[navActive].children[tabClick].cate_name}}
					</view>
					<view class="flex flex-wrap mt-24">
						<view class="min-w-154 pr-28 pl-28 mr-24 mb-24 h-56 rd-30rpx flex-center fs-24 text--w111-333 bg--w111-f5f5f5"
							v-for="(item,index) in productList[navActive].children" :key="index"
							@tap="longClick(index,item)"
							:class="index===tabClick?'cate_active':''">
							{{item.cate_name}}
						</view>
					</view>
				</view>
				<view class="flex-center fs-24 text--w111-999 h-80 mt-32" @tap="closeCateDrawer">
					<text>点击收起 <text class="iconfont icon-ic_uparrow pl-4"></text> </text>
				</view>
			</view>
		</view>
		<view class="mask" v-show="showCateDrawer" @tap="closeCateDrawer"></view>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	let windowBottom = uni.getSystemInfoSync().windowBottom;
	import {getCategoryList} from '@/api/store.js';
	import { getCategoryVersion } from '@/api/api.js';
	import {mapState,mapGetters} from "vuex"
	import emptyPage from '@/components/emptyPage.vue';
	const app = getApp();
	export default {
		props: {
			level:{
				type:Number,
				default:2
			},
			isFooter:{
				type:Boolean,
				default:false
			},
			pdHeight:{
				type:Number,
				default:0
			}
		},
		components: {
			emptyPage
		},
		data() {
			return {
				navlist: [],
				productList: [],
				navActive: 0,
				number: "",
				height: 0,
				heightDiv: 0,
				hightArr: [],
				toView: "",
				tabbarH: 0,
				footH: 0,
				windowHeight: 0,
				pageHeight: '100%',
				sysHeight: sysHeight,
				// #ifdef APP-PLUS
				pageHeight: app.globalData.windowHeight,
				// #endif
				footerStatus: false,
				lock: false,
				tabClick:0,
				showCateDrawer: false,
				getHeight: this.$util.getWXStatusHeight(),
				navThreeActiveId: '',
			}
		},
		computed: {
			...mapState({
				cartNum: state => state.indexData.cartNum
			}),
			asideHeights(){
				let H = `calc(100% - ${this.pdHeight*2 + 96}rpx - env(safe-area-inset-bottom))`
				return{
					height: this.isFooter?H:'100%'
				}
			},
			conterHeights(){
				let H = `calc(100% - ${this.pdHeight*2 + 192}rpx - env(safe-area-inset-bottom))`
				let H2 = `calc(100% - ${this.pdHeight*2 + 192}rpx - env(safe-area-inset-bottom))`
				let HZ = `calc(100% - 96rpx - env(safe-area-inset-bottom))`
				return{
					//#ifdef H5
					height: this.isFooter?H:HZ
					//#endif
					// #ifdef MP || APP-PLUS
					height: this.isFooter?H2:HZ
					//#endif
				}
			},
			imageShow(){
				let obj = this.productList.length && this.productList[this.navActive].children.length && this.productList[this.navActive].children[this.tabClick] && 
				this.productList[this.navActive].children[this.tabClick].hasOwnProperty("children")
				return !obj
			}
		},
		mounted() {
			let that = this
			// 这里是 多店+平台、单店+平台 调用；
			let shopOperationType = uni.getStorageSync('shop_operation_type');
			if(shopOperationType !=3){
				this.getAllCategory();
			}
			// #ifdef H5
			uni.getSystemInfo({
				success: function(res) {
					that.pageHeight = res.windowHeight + 'px'
				}
			});
			// #endif
		},
		methods: {
			footHeight(data) {
				this.footH = data
			},
			closeCateDrawer() {
				this.showCateDrawer = false;
			},
			openCateDrawer() {
				this.showCateDrawer = true;
			},
			// 导航栏点击
			longClick(index, item) {
				if (index > 1) {
					this.navThreeActiveId = `navThree${index - 1}`;
				} else {
					this.navThreeActiveId = 'navThree0';
				}
				this.tabClick = index; //设置导航点击了哪一个
				this.showCateDrawer = false;
			},
			infoScroll: function() {
				let that = this;
				let len = that.productList.length;
				this.number = that.productList[len - 1].children.length;
				let height = 0;
				let hightArr = [];
				//设置商品列表高度
				let query = uni.createSelectorQuery().in(this);
				query.select(".conter").boundingClientRect();
				query.exec(function(res){
					height = res[0].height;
				})
				for (let i = 0; i < len; i++) {
					//获取元素所在位置
					let query = uni.createSelectorQuery().in(this);
					let idView = "#b" + i;
					query.select(idView).boundingClientRect();
					query.exec(function(res) {
						let top = res[0].top;
						that.hightArr.push(top);
						if(len == that.hightArr.length){
							//设置转化比例
							uni.getSystemInfo({
								success: function(res) {
									let per = (750 / res.windowWidth);
									that.height = height * per;
									that.heightDiv = (that.hightArr[that.hightArr.length-1] - that.hightArr[that.hightArr.length-2])*per;
								},
							});
						}
					});
				};
			},
			tap: function(index, id) {
				this.toView = id;
				this.navActive = index;
				this.tabClick = 0;
				this.$set(this, 'lock', true);
			},
			getAllCategory() {
				getCategoryList().then(res => {
					this.productList = res.data;
					if(this.level == 2){
						this.$nextTick(res => {
							this.infoScroll();
						})
					}
				})
			},
			scroll: function(e) {
				let scrollTop = e.detail.scrollTop;
				let scrollArr = this.hightArr;
				if (this.lock) {
					this.$set(this, 'lock', false);
					return;
				}
				for (let i = 0; i < scrollArr.length; i++) {
					if (scrollTop >= 0 && scrollTop < scrollArr[1] - scrollArr[0]) {
						this.navActive = 0
					} else if (scrollTop >= scrollArr[i] - scrollArr[0] && scrollTop < scrollArr[i + 1] - scrollArr[
							0]) {
						this.navActive = i
					} else if (scrollTop >= scrollArr[scrollArr.length - 1] - scrollArr[0]) {
						this.navActive = scrollArr.length - 1
					}
				}
				uni.$emit('scroll');
			},
			searchSubmitValue: function(e) {
				if (this.$util.trim(e.detail.value).length > 0)
					uni.navigateTo({
						url: '/pages/goods/goods_list/index?searchValue=' + e.detail.value
					})
				else
					return this.$util.Tips({
						title: '请填写要搜索的产品信息'
					});
			},
			goSearch() {
				uni.navigateTo({
					url: '/pages/goods/goods_search/index'
				})
			},
			goPage(type, url){
				if(type == 1){
					uni.navigateTo({
						url
					})
				}else if(type == 2){
					uni.switchTab({
						url
					})
				}else if(type == 3){
					uni.navigateBack();
				}
			
			},
		},
	}
</script>
<style>
page {
	background: #f5f5f5;
}
</style>
<style scoped lang="scss">
	.bntCon{
		position: relative;
		&:before{
			position: absolute;
			content: '';
			width: 6rpx;
			height: 80rpx;
			background: radial-gradient( rgba(0,0,0,0.082) 0%, rgba(0,0,0,0) 100%);
			left:-22rpx;
		}
	}
	
	::v-deep uni-scroll-view{
		padding-bottom: 0!important;
	}
	.height-add {
	  height: 100%;
	}
	.sys-title {
		z-index: 10;
		position: relative;
		height: 40px;
		line-height: 40px;
		font-size: 34rpx;
		color: #333;
		text-align: center;
	}
	.productSort {
		display: flex;
		flex-direction: column;
		//#ifdef MP
		height: calc(100vh - var(--window-top)) !important;
		//#endif
		//#ifndef MP
		height: 100vh
		//#endif
	}

	.productSort .header {
		width: 100%;
		height: 96rpx;
		background-color: #f5f5f5;
	}

	.productSort .header .input {
		width: 700rpx;
		height: 60rpx;
		background-color: #fff;
		border-radius: 50rpx;
		box-sizing: border-box;
		padding: 0 25rpx;
	}

	.productSort .header .input .iconfont {
		font-size: 35rpx;
		color: #555;
	}

	.productSort .header .input .placeholder {
		color: #999;
	}

	.productSort .header .input input {
		font-size: 26rpx;
		height: 100%;
		width: 597rpx;
	}

	.productSort .scroll-box {
		flex: 1;
		overflow: hidden;
		display: flex;
	}

	// #ifndef MP
	uni-scroll-view {
		padding-bottom: 100rpx;
	}

	// #endif

	.productSort .aside {
		width: 168rpx;
		height: 100%;
		overflow: hidden;
		background-color: #fff;
	}

	.productSort .aside .item {
		height: 96rpx;
		width: 100%;
		font-size: 28rpx;
		color: #666;
		text-align: center;
	}

	.productSort .aside .item.on {
		background-color: #f5f5f5;
		width: 100%;
		color: var(--view-theme);
		font-weight: 500;
		position: relative;
		&:before{
			content:'';
			width:6rpx;
			height: 48rpx;
			background-color: var(--view-theme);
			position: absolute;
			left: 0;
			top: 50%;
			transform: translateY(-50%);
			border-radius: 0 10rpx 10rpx 0;
		}
		.top-ra{
			width: 32rpx;
			height: 32rpx;
			background-color: #f5f5f5;
			position: absolute;
			right: 0;
			top:-32rpx;
			text{
				position: absolute;
				top:0;
				right: 0;
				width: 100%;
				height: 100%;
				background-color: #fff;
				display: inline-block;
				border-radius: 0 0 24rpx 0;
			}
		}
		.bottom-ra{
			width: 32rpx;
			height: 32rpx;
			background-color: #f5f5f5;
			position: absolute;
			right: 0;
			bottom:-32rpx;
			text{
				position: absolute;
				top:0;
				right: 0;
				width: 100%;
				height: 100%;
				background-color: #fff;
				display: inline-block;
				border-radius: 0 24rpx 0 0;
			}
		}
	}

	.productSort .conter {
		flex: 1;
		height: 100%;
		overflow: hidden;
		padding: 0 20rpx;
		position: relative;
		background-color: #f5f5f5;
	}
	.productSort .conter .banner{
		width: 100%;
		height: 160rpx;
		border-radius: 16rpx;
		margin-top: 20rpx;
	}
	.productSort .conter .card{
		width: 100%;
		background-color: #fff;
		border-radius: 16rpx;
		padding: 32rpx 24rpx;
	}
	.productSort .conter .card .title{
		font-size: 28rpx;
		font-weight: 500;
		color: #333;
		line-height: 40rpx;
	}
	.productSort .conter .card .iconfont{
		font-size: 24rpx;
	}
	.productSort .conter .card .grid_box{
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		grid-template-rows: auto;
		grid-row-gap: 32rpx;
		margin-top: 20rpx;
		.cate_name{
			font-size: 24rpx;
			color: #333;
			line-height: 34rpx;
			margin-top: 12rpx;
			text-align: center;
		}
	}
	.cate_active {
		color: var(--view-theme);
		background: var(--view-minorColorT);
		border: 1px solid var(--view-theme);
	}
	.pl-30 {
		padding-left: 30rpx;
	}
</style>
