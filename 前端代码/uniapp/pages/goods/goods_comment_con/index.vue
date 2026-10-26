<template>
	<view :style="colorStyle">
		<view class="px-20">
			<view class="h-120 bg--w111-fff rd-24rpx mt-24 pl-24 flex-y-center">
				<text class="fs-28 fw-500 mr-32">商品评价</text>
				<view class="flex-y-center">
					<view class="flex-y-center nav-item" v-for="(item, index) in replyNav" :key="index"
						@tap="replyNavChange(item.value)">
						<image :src=" reply_score == item.value ? item.activePic : item.pic" class="w-44 h-44"></image>
						<text class="fs-32 pl-14"
							:class="reply_score == item.value ? 'font-num' : 'text--w111-999'">{{item.title}}</text>
					</view>
				</view>
			</view>
			<view class="bg--w111-fff rd-24rpx mt-20 pt-32 pr-24 pb-32 pl-24">
				<view class="flex-y-center pb-40 bb-e">
					<image :src="productInfo.attrInfo ? productInfo.attrInfo.image : productInfo.image" class="w-88 h-88 rd-16rpx"></image>
					<view class="pl-20 flex-1">
						<view class="fs-28 lh-40rpx line1 w-538">{{productInfo.store_name}}</view>
						<view class="fs-32 SemiBold mt-12">￥{{productInfo.attrInfo?productInfo.attrInfo.price:productInfo.price}}</view>
					</view>
				</view>
				<view class="pt-40">
					<textarea placeholder='商品满足你的期待么？说说你的想法，分享给想买的他们吧~(最多输入100个字)' v-model="comment" name="comment" placeholder-class='placeholder lh-40rpx' maxlength='100' class="fs-26 lh-40rpx w-full h-260 mb-40"></textarea>
					<view class="grid-column-4 grid-gap-24rpx">
						<view class="relative h-148" v-for="(item,index) in pics" :key="index">
							<image :src="item" mode="aspectFill" class="w-148 h-148 rd-16rpx"></image>
							<view class="abs-rt w-32 h-32 del-pic flex-center fs-24" @click="DelPic(index)">
								<text class="iconfont icon-ic_close fs-24 text--w111-fff"></text>
							</view>
						</view>
						<view class="h-148 flex-col flex-center upload bg--w111-f5f5f5 rd-16rpx"
							@click='uploadpic' v-if="pics.length < 8">
							<image class="w-48 h-48" src="../static/ic_camera.png"></image>
							<text class="fs-24 lh-34rpx pt-8">上传图片</text>
						</view>
					</view>
				</view>
				<view class="flex-y-center pt-26" v-if="community_status">
					<text class="iconfont fs-32" :class="is_sync ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect'"
						@tap="checkSync"></text>
					<text class="fs-24 pl-8">分享至社区</text>
				</view>
			</view>
			<view class="bg--w111-fff rd-24rpx mt-20 pt-32 pr-24 pb-32 pl-24">
				<view class="flex-y-center">
					<text class="fs-28 fw-500">商品质量</text>
					<view class="ml-40 flex">
						<text class="star iconfont text--w111-999"
							:class="item <= product_score ? 'icon-ic_star1 font-num' : 'icon-ic_star'"
							v-for="(item, index) in m888edb9" :key="index" @click="stars(0,item)"></text>
					</view>
				</view>
				<view class="flex-y-center mt-40">
					<text class="fs-28 fw-500">服务态度</text>
					<view class="ml-40 flex">
						<text class="star iconfont text--w111-999"
							:class="item <= service_score ? 'icon-ic_star1 font-num' : 'icon-ic_star'"
							v-for="(item, index) in m888edb9" :key="index" @click="stars(1,item)"></text>
					</view>
				</view>
				<!-- delivery_score -->
				<view class="flex-y-center mt-40">
					<text class="fs-28 fw-500">物流服务</text>
					<view class="ml-40 flex">
						<text class="star iconfont text--w111-999"
							:class="item <= delivery_score ? 'icon-ic_star1 font-num' : 'icon-ic_star'"
							v-for="(item, index) in m888edb9" :key="index" @click="stars(2,item)"></text>
					</view>
				</view>
			</view>
		</view>
		<view class="h-120"></view>
		<view class='fixed-lb w-full bg--w111-fff pb-safe'>
			<view class="mx-20 h-120 flex-center">
				<view class="w-full h-80 rd-40rpx flex-center text--w111-fff fs-28 bg-color"
				:class="isSelectStar ? '' : 'opacity-05'"
				 @tap="formSubmit">提交</view>
			</view>
		</view>
		<canvas canvas-id="canvas"  v-if="canvasStatus"
		:style="{width: canvasWidth + 'px', height: canvasHeight + 'px',position: 'absolute',left:'-100000px',top:'-100000px'}"></canvas>
	</view>
</template>

<script>
	import {orderProduct,orderComment} from '@/api/order.js';
	import {getCommunityConfig} from '@/api/community.js';
	import {toLogin} from '@/libs/login.js';
	import {mapGetters} from "vuex";
	import colors from "@/mixins/color";
	import { HTTP_REQUEST_URL } from '@/config/app';
	export default {
		components: {},
		mixins: [colors],
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				pics: [],
				replyNav:[
					{
						title:'好评',
						pic: HTTP_REQUEST_URL + '/statics/images/product/product_reply_icon1_0.png',
						activePic:HTTP_REQUEST_URL + '/statics/images/product/product_reply_icon1.png',
						value:3,
					},
					{
						title:'中评',
						pic: HTTP_REQUEST_URL + '/statics/images/product/product_reply_icon2_0.png',
						activePic: HTTP_REQUEST_URL + '/statics/images/product/product_reply_icon2.png',
						value:2,
					},
					{
						title:'差评',
						pic: HTTP_REQUEST_URL + '/statics/images/product/product_reply_icon3_0.png',
						activePic: HTTP_REQUEST_URL + '/statics/images/product/product_reply_icon3.png',
						value:1,
					}
				],
				orderId: '',
				unique: '',
				productInfo: {},
				m871f7: 0,
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				canvasWidth: "",
				canvasHeight: "",
				canvasStatus: false,
				product_score:-1,
				service_score:-1,
				delivery_score: -1,
				reply_score:3,
				comment:"",
				m888edb9:[1,2,3,4,5],
				community_status:1,
				is_sync:0,
			};
		},
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						// #ifndef MP
						this.getOrderProduct();
						// #endif
					}
				},
				deep: true
			}
		},
		computed:{
			...mapGetters(['isLogin']),
			isSelectStar(){
				if(this.product_score >= 0 && this.service_score >= 0 && this.delivery_score >= 0 && this.comment ){
					return true
				}
			}
		},
		onLoad(options) {
			if (!options.unique || !options.uni) return this.$util.Tips({
				title: '缺少参数'
			}, {
				tab: 3,
				url: 1
			});
			this.unique = options.unique;
			this.orderId = options.uni;
			if (this.isLogin) {
				this.getOrderProduct();
				this.getConfig();
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		methods: {
			/**
			 * 获取某个产品详情
			 *
			 */
			getOrderProduct: function() {
				let that = this;
				orderProduct(that.unique).then(res => {
					that.$set(that, 'productInfo', res.data.productInfo);
					that.m871f7 = res.data.cart_num;
				});
			},
			stars: function(type, star) {
				if(type == 0){
					this.product_score = star;
				}else if(type == 1){
					this.service_score = star;
				}else {
					this.delivery_score = star;
				}
			},
			replyNavChange(value){
				this.reply_score = value;
			},
			/**
			 * 删除图片
			 *
			 */
			DelPic: function(index) {
				let that = this,
					pic = this.pics[index];
				that.pics.splice(index, 1);
				that.$set(that, 'pics', that.pics);
			},
			/**
			 * 上传文件
			 *
			 */
			uploadpic: function() {
				let that = this;
				this.canvasStatus = true
				that.$util.uploadImageChange({count:8,url:'upload/image'}, function(res) {
					that.pics.push(res.data.url);
				}, (res) => {
					this.canvasStatus = false
				}, (res) => {
					this.canvasWidth = res.w
					this.canvasHeight = res.h
				});
			},

			/**
			 * 立即评价
			 */
			formSubmit: function() {
				let that = this;
				if (!this.comment) return that.$util.Tips({
					title: '请填写你对宝贝的心得！'
				});
				if(!this.isSelectStar) return that.$util.Tips({
					title: '请完成打分！'
				});
				if(this.community_status && this.is_sync && !this.pics.length) return that.$util.Tips({
					title: '请上传要分享的图片！'
				});
				let data = {
					reply_score: this.reply_score,
					product_score: this.product_score,
					service_score: this.service_score,
					delivery_score: this.delivery_score,
					pics: this.pics,
					unique: this.unique,
					comment: this.comment,
					is_sync: this.is_sync
				};
				uni.showLoading({
					title: "正在发布评论……"
				});
				orderComment(data).then(res => {
					uni.hideLoading();
					let jumpPath = res.data.to_lottery ?
						'/pages/goods/goods_comment_con/lottery_comment?type=4&order_id=' + that
						.orderId : '/pages/goods/order_details/index?order_id=' + that.orderId
					let obj = {
						tab: 3,
						url: 1
					};
					let pages = getCurrentPages();
					if (pages[pages.length - 3]) {
						if (pages[pages.length - 3].route == 'pages/goods/order_pay_status/index') {
							obj.tab = 5;
							obj.url = '/pages/goods/order_list/index';
						}
					}
					if (res.data.to_lottery) {
						obj.tab = 5;
						obj.url = '/pages/goods/goods_comment_con/lottery_comment?type=4&order_id=' + that.orderId
					}
					that.$util.Tips({
						title: '感谢您的评价!',
						icon: 'success'
					}, obj);
				}).catch(err => {
					uni.hideLoading();
					return that.$util.Tips({
						title: err
					});
				});
			},
			getConfig(){
				getCommunityConfig().then(res=>{
					this.community_status = res.data.community_status;
				})
			},
			checkSync(){
				if(this.is_sync){
					this.is_sync = 0;
				}else{
					this.is_sync = 1;
				}
			},
		}
	}
</script>

<style lang="scss" scoped>
	.SemiBold {
		font-family: SemiBold;
	}
	.good-pic{
		width: 54rpx;
		height: 59rpx;
	}
	.nav-item ~ .nav-item{
		margin-left: 38rpx;
	}
	.bb-e{
		border-bottom: 1px solid #eee;
	}
	.placeholder{
		color: #ccc;
		font-size: 26rpx;
		line-height: 40rpx;
	}
	.upload{
		border: 1px dashed #ccc;
	}
	.del-pic{
		background-color: #999;
		border-radius: 0 16rpx 0 16rpx;
	}
	.star ~ .star{
		margin-left: 24rpx;
	}
	.bg-hui{
		background-color: #ccc;
	}
	.icon-a-ic_CompleteSelect{
		color: var(--view-theme);
	}
</style>
