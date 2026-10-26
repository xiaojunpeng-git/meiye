<template>
	<view>
		<view class="bg-top h-644" :style="{'background-image' : headerBg}">
			<view class="w-full bg-opacity" :style="{'padding-top': sysHeight + 'px'}">
				<view class="w-full px-20 pl-20 h-80 flex-between-center">
					<text class="iconfont icon-ic_leftarrow fs-40" @tap="goPage('',3)"></text>
					<text class="fs-34 fw-500">新人礼</text>
					<text></text>
				</view>
			</view>
			<view class="rule-btn w-72 flex-center fs-24 text--w111-fff z-999"
				:style="{top: 110 + sysHeight + 'px'}"
				@tap="goAgree">规则</view>
		</view>
		<view class="px-20">
			<view class="bg--w111-fff rd-24rpx pt-32 pr-24 pb-32 pl-24 relative top200" 
				v-if="info.register_give_coupon ? info.register_give_coupon.length : false">
				<view class="flex-center">
					<image src="../static/new_person_star.png" class="star"></image>
					<text class="fs-32 lh-44rpx fw-500 px-16">新人优惠券</text>
					<image src="../static/new_person_star.png" class="star star-right"></image>
				</view>
				<view class="grid-column-3 grid-gap-38rpx mt-36">
					<view class="coupon-item flex-col flex-between-center"
						v-for="(item,index) in showMore ? info.register_give_coupon : info.register_give_coupon.slice(0,6)" :key="index">
						<view class="flex-col flex-center">
							<baseMoney :money="item.coupon_price" symbolSize="28" integerSize="44"
								decimalSize="28" color="#e93323" weight v-if="item.coupon_type==1"></baseMoney>
							<view v-else class="font-red fs-32 flex-y-center"><text class="SemiBold fs-44">{{parseFloat(item.coupon_price)/10}}</text>折</view>
							<text class="fs-20 lh-28rpx font-red pt-6">{{item.applicable_type | typeFilter}}</text>
						</view>
						<view class="fs-24 lh-34rpx text--w111-fff" v-if="item.use_min_price > 0">满{{item.use_min_price}}可用</view>
						<view class="fs-24 lh-34rpx text--w111-fff" v-else>无门槛券</view>
					</view>
				</view>
				<view class="w-full mt-24 flex-center fs-24 text--w111-999"
					v-if="info.register_give_coupon.length > 6" @tap="showMoreChange">
					<text>{{showMore ? '点击收起' : '点击展开'}}</text>
					<text class="iconfont fs-24" :class="showMore ? 'icon-ic_uparrow' : 'icon-ic_downarrow'"></text>
				</view>
				<view class="w-full h-88 rd-44rpx flex-center bg-jianbian text--w111-fff fs-28 mt-32"
					@tap="goPage('/pages/users/user_coupon/index',1)">查看优惠券</view>
			</view>
			<view class="bg--w111-fff rd-24rpx pt-32 pr-24 pl-24 relative top200 mt-20">
				<view class="flex-center">
					<image src="../static/new_person_star.png" class="star"></image>
					<text class="fs-32 lh-44rpx fw-500 px-16">新人福利</text>
					<image src="../static/new_person_star.png" class="star star-right"></image>
				</view>
				<view class="mt-10">
					<view class="cell flex-between-center py-32"  v-if="info.register_give_integral > 0">
						<view class="flex-y-center">
							<image :src="imgHost + '/statics/images/newcomer/integral_icon.png'" class="w-72 h-72"></image>
							<view class="flex-col ml-24">
								<text class="fs-28 fw-500 lh-40rpx">新人赠送积分</text>
								<text class="fs-22 text--w111-999 lh-30rpx pt-6">新人即可获得{{info.register_give_integral}}积分</text>
							</view>
						</view>
						<view class="w-120 h-56 rd-28rpx flex-center bg-jianbian text--w111-fff fs-24"
							@tap="goPage('/pages/users/user_integral/index',1)">去查看</view>
					</view>
					<view class="cell flex-between-center py-32" v-if="info.register_give_money > 0">
						<view class="flex-y-center">
							<image :src="imgHost + '/statics/images/newcomer/money_icon.png'" class="w-72 h-72"></image>
							<view class="flex-col ml-24">
								<text class="fs-28 fw-500 lh-40rpx">新人赠送余额</text>
								<text class="fs-22 text--w111-999 lh-30rpx pt-6">新人即可获得{{info.register_give_money}}余额</text>
							</view>
						</view>
						<view class="w-120 h-56 rd-28rpx flex-center bg-jianbian text--w111-fff fs-24"
							@tap="goPage('/pages/users/user_money/index',1)">去查看</view>
					</view>
					<view class="cell flex-between-center py-32" v-if="info.first_order_status">
						<view class="flex-y-center">
							<image :src="imgHost + '/statics/images/newcomer/coupon_icon.png'" class="w-72 h-72"></image>
							<view class="flex-col ml-24">
								<text class="fs-28 fw-500 lh-40rpx">新人下单享首单优惠</text>
								<text class="fs-22 text--w111-999 lh-30rpx pt-6">首次下单享{{parseFloat(info.first_order_discount)/10 || 10}}折优惠哦～</text>
							</view>
						</view>
						<view class="w-120 h-56 rd-28rpx flex-center bg-jianbian text--w111-fff fs-24" 
							@tap="goPage('/pages/goods/goods_list/index',1)">去查看</view>
					</view>
				</view>
			</view>
			<view class="bg--w111-fff rd-24rpx pt-32 pr-24 pb-32 pl-24 relative top200 mt-20" 
				v-if="newList.length">
				<view class="flex-center">
					<image src="../static/new_person_star.png" class="star"></image>
					<text class="fs-32 lh-44rpx fw-500 px-16">新人商品专区</text>
					<image src="../static/new_person_star.png" class="star star-right"></image>
				</view>
				<view class="grid-column-3 grid-gap-20rpx mt-36">
					<view class="" v-for="(item,index) in newList" :key="index" 
						@click="goPage(`/pages/activity/goods_details/index?id=${item.id}&type=7`,1)">
						<image class="w-full h-210 rd-16rpx" :src="item.image" mode="aspectFill"></image>
						<view class="w-full line2 fs-28 lh-40rpx fw-500 mt-24 mb-14">{{item.store_name}}</view>
						<baseMoney :money="item.price" symbolSize="24" integerSize="40"
							decimalSize="24" color="#e93323" weight></baseMoney>
					</view>
					
				</view>
			</view>
		</view>
		<base-drawer mode="bottom" :visible="showDrawer" background-color="transparent" mask maskClosable @close="goAgree">
			<view class="w-full bg--w111-fff rd-t-40rpx py-32 relative">
				<view class="text-center fs-32 text--w111-333 fw-500">新人礼规则</view>
				<view class="close flex-center" @tap='goAgree'>
					<text class="iconfont icon-ic_close fs-24 text--w111-999"></text>
				</view>
				<view class="mt-48 px-32 scroll-content">
					<!-- #ifdef MP-WEIXIN -->
					<rich-text :nodes="rule"></rich-text>
					<!-- #endif -->
					<!-- #ifdef H5 || APP-PLUS -->
					<view v-html="rule"></view>
					<!-- #endif -->
				</view>
				<view class="mx-20 pb-safe">
				  <view class="mt-52 h-72 flex-center rd-36px bg-red fs-26 text--w111-fff" @click="goAgree">我知道了</view>
				</view>
			</view>
		</base-drawer>
	</view>
</template>
<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import countDown from '@/components/countDown';
	import {
		newcomerInfo,
		newcomerList
	} from '@/api/activity.js';
	import { getUserAgreement } from "@/api/user.js"
	import {HTTP_REQUEST_URL} from '@/config/app';
	import {
		mapGetters
	} from "vuex";
	import {
		toLogin
	} from '@/libs/login.js';
	export default {
		components: {
			countDown,
		},
		data() {
			return {
				sysHeight:sysHeight,
				imgHost:HTTP_REQUEST_URL,
				info: {},
				newList:[],
				loading: false,
				loadend: false,
				loadTitle: '加载更多',
				page: 1,
				limit: 9,
				showMore: false,
				rule:'',
				showDrawer:false
			};
		},
		filters:{
			typeFilter(val){
				let obj = {
					0: '通用券',
					1: '品类券',
					2: '商品券',
					3: '品牌券',
				}; 
				return obj[val]
			}
		},
		computed:{
			...mapGetters(['isLogin']),
			headerBg(){
				return 'url('+this.imgHost+'/statics/images/newcomer/header.png'+')'
			}
		},
		onLoad() {
			this.getNewcomerInfo();
			this.productList();
			this.getAgreeContent();
		},
		methods: {
			goAgree(){
				this.showDrawer = !this.showDrawer;
			},
			getAgreeContent(){
				getUserAgreement('newcomer_agreement').then((res) => {
					this.rule = res.data.content;
				}).catch((err) => {
					this.$util.Tips({
						title: err.msg
					});
				});
			},
			getNewcomerInfo() {
				newcomerInfo().then(res => {
					res.data.last_time = parseInt(res.data.last_time);
					this.info = res.data;
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				})
			},
			productList() {
				let that = this;
				if (that.loading) return;
				if (that.loadend) return;
				that.loading = true;
				that.loadTitle = '';
				newcomerList({
					page: that.page,
					limit: that.limit
				}).then(res=>{
					let list = res.data;
					let loadend = list.length < that.limit;
					that.newList = that.$util.SplitArray(list, that.newList);
					that.$set(that, 'newList', that.newList);
					that.loadend = loadend;
					that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					that.page = that.page + 1;
					that.loading = false;
				}).catch(err=>{
					that.loading = false;
					that.loadTitle = '加载更多';
					this.$util.Tips({
						title: err
					});
				})
			},
			goPage(url, type){
				if(type == 1){
					if(this.isLogin){
						uni.navigateTo({
							url
						})
					}else{
						toLogin();
					}
				}else if(type == 3){
					this.$util.backPage();
				}
			},
			showMoreChange(){
				this.showMore = !this.showMore;
			}
		},
		onReachBottom() {
			this.productList();
		}
	}
</script>

<style lang="scss">
	.rule-btn{
		height: 48rpx;
		background: rgba(0,0,0,0.3);
		border-radius: 24rpx 0 0 24rpx;
		position: fixed;
		right: 0;
		z-index: 20;
	}
	.close{
		position: absolute;
		right: 32rpx;
		top: 36rpx;
		width: 36rpx;
		height: 36rpx;
		border-radius: 50%;
		background-color: #eee;
	}
	.SemiBold {
		font-family: 'SemiBold';
	}
	.bg-top{
		background-size: cover;
	}
	.bg-opacity{
		background: transparent;
	}
	.top200{
		top: -200rpx;
	}
	.star{
		width: 54rpx;
		height: 24rpx;
	}
	.star-right{
		display: block;
		transform: scaleX(-1);
	}
	.coupon-item{
		height: 186rpx;
		padding: 20rpx 0 18rpx;
		background-image: url('../static/coupon_item_bg.png');
		background-size: cover;
	}
	.font-red{
		color: #E93323;
	}
	.bg-jianbian{
		background: linear-gradient(90deg, #FF7931 0%, #E93323 100%);
	}
	.cell ~ .cell{
		border-top: 1px solid #eee;
	}
</style>
