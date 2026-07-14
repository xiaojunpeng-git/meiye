<template>
	<view :style="colorStyle">
		<view class="w-full relative header_bg" :style="{'height': (174 + sysHeight) * 2 + 'rpx'}">
			<view class="w-full fixed-lt z-99" :style="{'padding-top': sysHeight * 2 + 'rpx'}"
				:class="pageScrollStatus ? 'bg--w111-fff' : ''">
				<view class="h-80 px-20 flex-between-center">
					<text class="iconfont icon-ic_leftarrow fs-40" :class="pageScrollStatus ? 'text--w111-333' : 'text--w111-fff'" @click="goPage(3)"></text>
					<text class="fs-34 fw-500" :class="pageScrollStatus ? 'text--w111-333' : 'text--w111-fff'">物流查询</text>
					<text></text>
				</view>
			</view>
			<view class="w-full abs-lb white_jianbian"></view>
		</view>
		<view class="relative px-20 z-20 express_box">
			<view class="h-66 rd-t-24rpx light px-24 flex-between-center fs-20">
				<text>{{orderInfo.delivery_name}} {{orderInfo.delivery_id}}</text>
				<text class="inline-block copy_btn fs-22 text--w111-333" @tap="copyOrderId">复制单号</text>
			</view>
			<view class="rd-b-24rpx bg--w111-fff flex-between-center">
				<view class="w-316 h-142 flex-col flex-center">
					<text class="fs-32 fw-500 lh-44rpx">{{orderInfo.user_name}}</text>
					<text class="fs-22 text--w111-999 lh-30rpx mt-8">{{orderInfo.user_phone}}</text>
				</view>
				<view class="flex-1 h-142 flex-center fs-28 fw-500 lh-40rpx relative city-box">
					<text>{{orderInfo.user_city}}</text>
					<text class="iconfont icon-a-jiantou11 mx-32"></text>
					<text>{{orderInfo.send_city}}</text>
				</view>
			</view>
		</view>
		<view class="px-20 mt-20">
			<view class="bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32">
				<view class="flex-between-center">
					<view class="fs-32 fw-500 text--w111-333">
						<text>物流详情</text>
					</view>
				</view>
				<view class="logisticsCon mt-50 relative" v-if="expressList.length">
					<view class='item' v-for="(item,index) in logisticList" :key="index">
						<view class='circular' :class='index === 0 ? "on text-center":""'>
							<text class="iconfont icon-ic_complete text--w111-fff fs-24" v-if="index == 0"></text>
						</view>
						<view class='text' :class='index===0 ? "on-font on":""'>
							<view>{{item.status}}</view>
							<view class='data' :class='index===0 ? "on-font on":""'>{{item.time}}</view>
						</view>
					</view>
					<view class="more-text fs-24" @tap="checkShowMore"> 
						<text>{{showMore ? '收起' : '查看更多物流信息'}}</text>
						<text class="iconfont fs-24 pl-8" :class="showMore ? 'icon-ic_uparrow' : 'icon-ic_downarrow'"></text>
					</view>
				</view>
				<emptyPage title="暂无物流信息" src="/statics/images/noExpress.gif" v-else></emptyPage>
			</view>
		</view>
		<view class="px-20" v-if="product.length">
			<view class="bg--w111-fff rd-16rpx mt-20 p-24">
				<view class="flex w-full product" v-for="(item,index) in product" :key="index">
					<image class="w-120 h-120 rd-16rpx" mode='aspectFill' :src='item.productInfo.image' ></image>
					<view class="flex-1 flex justify-between pl-20">
						<view class="w-382">
							<view class="w-full line2 fs-28 text--w111-333 lh-40rpx">{{item.productInfo.store_name}}</view>
							<view class="w-full line1 fs-24 text--w111-999 lh-34rpx mt-20"></view>
						</view>
						<view class="flex-1 flex-col items-end">
							<baseMoney :money="item.truePrice" symbolSize="20" integerSize="36" decimalSize="20" color="#333" weight></baseMoney>
							<view class="fs-24 text--w111-999 lh-40rpx mt-10">共{{item.cart_num}}件</view>
						</view>
					</view>
				</view>
			</view>
			<recommend v-if='hostProduct.length' :hostProduct='hostProduct'></recommend>
		</view>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import { express } from '@/api/order.js';
	import { getProductHot } from '@/api/store.js';
	import ClipboardJS from "@/plugin/clipboard/clipboard.js";
	import { toLogin } from '@/libs/login.js';
	import { mapGetters } from "vuex";
	import recommend from '@/components/recommend';
	import emptyPage from '@/components/emptyPage.vue';
	import colors from "@/mixins/color";
	import { HTTP_REQUEST_URL } from '@/config/app';
	export default {
		components: {
			recommend,
			emptyPage
		},
		mixins: [colors],
		data() {
			return {
				sysHeight:sysHeight,
				imgHost: HTTP_REQUEST_URL,
				orderId: '',
				type:'',
				product: [],
				orderInfo: {},
				expressList: [],
				hostProduct: [],
				isShowAuth: false,
				pageScrollStatus:false,
				showMore: false
			};
		},
		computed: {
			...mapGetters(['isLogin']),
			logisticList(){
				if(this.showMore){
					return this.expressList
				}else{
					return this.expressList.slice(0,1)
				}
			}
		},
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						//#ifndef MP
						this.getExpress();
						this.get_host_product();
						//#endif
					}
				},
				deep: true
			}
		},
		onLoad: function(options) {
			if (!options.orderId) return this.$util.Tips({
				title: '缺少订单号'
			});
			if(typeof(options.type) == 'undefined'){
				this.type = ''
			}else{
				this.type = options.type
			}
			this.orderId = options.orderId;
			if (this.isLogin) {
				this.getExpress();
				this.get_host_product();
			}
		},
		onReady: function() {
			// #ifdef H5
			this.$nextTick(function() {
				const clipboard = new ClipboardJS(".copy-data");
				clipboard.on("success", () => {
					this.$util.Tips({
						title: '复制成功'
					});
				});
			});
			// #endif
		},
		onPageScroll(object) {
			if (object.scrollTop > 100) {
				this.pageScrollStatus = true;
			} else if (object.scrollTop < 100) {
				this.pageScrollStatus = false;
			}
			uni.$emit('scroll');
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			if(!this.isLogin){
				toLogin()
			}
		},
		methods: {
			// 授权关闭
			authColse: function(e) {
			  this.isShowAuth = e
			},
			/**
			 * 授权回调
			 */
			onLoadFun: function() {
				this.getExpress();
				this.get_host_product();
				this.isShowAuth = false;
			},
			copyOrderId: function() {
				uni.setClipboardData({
					data: this.orderInfo.delivery_id
				});
			},
			getExpress: function() {
				let that = this;
				express(that.orderId,this.type).then(function(res) {
					that.$set(that, 'product', res.data.order.cartInfo || []);
					that.$set(that, 'orderInfo', res.data.order);
					that.$set(that, 'expressList', res.data.express || []);
				}).catch((error) => {
					this.$util.Tips({
						title: error
					});
				});
			},
			/**
			 * 获取我的推荐
			 */
			get_host_product: function() {
				let that = this;
				getProductHot().then(function(res) {
					that.$set(that, 'hostProduct', res.data);
				});
			},
			checkShowMore(){
				this.showMore = !this.showMore
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
		}
	}
</script>

<style scoped lang="scss">
	.header_bg{
		height: 369rpx;
		background: linear-gradient(90deg, #FF7931 0%, #E93323 100%);
	}
	.white_jianbian{
		height:180rpx;
		background: linear-gradient(0deg, #F5F5F5 0%, rgba(245,245,245,0) 100%);
	}
	.light{
		background: rgba(255,255,255,0.9);
	}
	.express_box{
		margin-top: -240rpx;
	}
	.copy_btn{
		width: 112rpx;
		height: 34rpx;
		background: #F5F5F5;
		border-radius: 20rpx;
		text-align:center;
		line-height:34rpx;
	}
	.city-box{
		&:before{
			content: '';
			width: 1px;
			height: 64rpx;
			background-color: #eee;
			position: absolute;
			top: 40rpx;
			left: 0;
		}
	}
	.more-text{
		position: absolute;
		left: 40rpx;
		bottom: -12rpx;
		&:before{
			content: '';
			width: 14rpx;
			height: 14rpx;
			border-radius: 50%;
			background-color: #ddd;
			position: absolute;
			left: -26rpx;
			top: 8rpx;
		}
	}
	.logisticsCon .item {
		padding: 0 20rpx;
		position: relative;
	}

	.logisticsCon .item .circular {
		width: 14rpx;
		height: 14rpx;
		border-radius: 50%;
		position: absolute;
		left: 14rpx;
		background-color: #ddd;
	}

	.logisticsCon .item .circular.on {
		width: 40rpx;
		height: 40rpx;
		background-color: var(--view-theme);
		left: 0;
	}

	.logisticsCon .item .text.on-font {
		color: var(--view-theme);
	}

	 .logisticsCon .item .text .data.on-font {
		color: var(--view-theme);
	}

	.logisticsCon .item .text {
		font-size: 26rpx;
		color: #666;
		width: 615rpx;
		border-left: 1rpx solid #e6e6e6;
		padding: 0 0 40rpx 38rpx;
	}

	.logisticsCon .item .text .data {
		font-size: 24rpx;
		color: #999;
		margin-top: 10rpx;
	}

	.logisticsCon .item .text .data .time {
		margin-left: 15rpx;
	}
	.z-99{
		z-index:99;
	}
	.product ~ .product{
		margin-top: 20rpx;
	}
</style>
