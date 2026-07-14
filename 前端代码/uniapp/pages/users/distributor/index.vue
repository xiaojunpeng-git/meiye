<template>
	<view :style="[topCardStyle]">
		<!-- #ifdef MP -->
		<NavBar textSize="34rpx" :isScrolling="isScrolling" iconColor="#333333" textColor="#333333" showBack></NavBar>
		<!-- #endif -->
		<view class="w-full cover relative top-card">
			<text class="full-text fs-26 fw-500">商城消费满{{brokeragePrice}}元，即可成为分销员～</text>
		</view>
		<view class="w-full cover content-box" :style="[contentBg]">
			<view class="cover fuli-card relative px-24" :style="[fuliBg]">
				<view class="desc flex-y-center text--w111-fff fs-24 lh-40rpx" @tap="lookInfo">
					<text class="iconfont icon-icon_tip fs-24"></text>
					<text class="pl-8">分销说明</text>
				</view>
				<view class="grid-column-4 grid-gap-30rpx">
					<view class="w-full h-180 rd-16rpx grid-item flex-col flex-center" 
						v-for="(item,index) in menus" :key="index">
						<view class="w-80 h-80 rd-24rpx bg--w111-fff flex-center">
							<image :src="item.pic" class="w-58 h-58"></image>
						</view>
						<text class="pt-24 fs-24 fw-500 lh-36rpx">{{item.label}}</text>
					</view>
				</view>
			</view>
			<view class="relative man-card rd-32rpx bg--w111-fff">
				<view class="w-full cover h-152 flex-x-center pt-42" :style="[colorBox]">
					<image class="text-pic" :src="imgHost + '/statics/images/promoter/promoter_text.png'"  mode="aspectFill"></image>
				</view>
				<view class="w-full relative px-24 pro-box">
					<view class="w-full grid-column-3 grid-gap-24rpx" v-if="hostProduct.length">
						<view class="w-full" v-for="item in hostProduct" :key="item.id"
							@tap="goDetail(item)">
							<easy-loadimage 
							:image-src="item.image" 
							width="100%" 
							height="202rpx" 
							borderRadius="16rpx"></easy-loadimage>
							<view class="w-200 line1 mt-16 mb-10">{{item.store_name}}</view>
							<BaseMoney :money="item.price" symbolSize="20" integerSize="36" decimalSize="24" color="#e93323"></BaseMoney>
						</view>
					</view>
					<view v-else>
						<emptyPage title="暂无数据哦～"></emptyPage>
					</view>
				</view>
			</view>
			<view class="h-200"></view>
		</view>
		<view class="pb-safe w-full fixed-lb flex-center">
			<view class="home-btn cover flex-center text--w111-fff fs-28 fw-500" :style="[btnBgStyle]"
				@tap="goHomePage">
				<text>进入商城，挑选更多商品</text>
				<text class="iconfont icon-ic_rightarrow"></text>
			</view>
		</view>
	</view>
</template>
<script>
import { getProductHot } from '@/api/store.js';
import {HTTP_REQUEST_URL} from '@/config/app';
import { mapGetters } from 'vuex';
import { goShopDetail } from '@/libs/order.js'
import NavBar from "@/components/NavBar.vue";
import home from '@/components/home/index.vue';
import emptyPage from '@/components/emptyPage.vue'
let sysHeight = uni.getWindowInfo().statusBarHeight;
export default {
	data(){
		return {
			imgHost: HTTP_REQUEST_URL,
			isScrolling: false,
			menus:[
				{label: '零成本', pic: HTTP_REQUEST_URL + '/statics/images/promoter/promoter_group3.png'},
				{label: '高佣金', pic: HTTP_REQUEST_URL + '/statics/images/promoter/promoter_group6.png'},
				{label: '持续收入', pic: HTTP_REQUEST_URL + '/statics/images/promoter/promoter_group1.png'},
				{label: '佣金提现', pic: HTTP_REQUEST_URL + '/statics/images/promoter/promoter_group2.png'},
			],
			hotPage:1,
			hotLimit:10,
			hotScroll: false,
			hostProduct: []
		}
	},
	components: { home, NavBar, emptyPage },
	computed:{
		...mapGetters(['isLogin']),
		brokeragePrice(){
			return this.$store.state.app.storeBrokeragePrice
		},
		topCardStyle(){
			return {
				backgroundImage: 'url('+this.imgHost+'/statics/images/promoter/promoter_group5.png'+')',
				backgroundSize: '100% 646rpx',
			}
		},
		contentBg(){
			return {
				height: '1474rpx',
				backgroundImage: 'url('+this.imgHost+'/statics/images/promoter/promoter_group4.png'+')'
			}
		},
		fuliBg(){
			return {
				backgroundImage: 'url('+this.imgHost+'/statics/images/promoter/promoter_card.png'+')'
			}
		},
		colorBox(){
			return {
				backgroundImage: 'url('+this.imgHost+'/statics/images/promoter/promoter_color1.png'+')'
			}
		},
		btnBgStyle(){
			return {
				backgroundImage: 'url('+this.imgHost+'/statics/images/promoter/promoter_button.png'+')'
			}
		}
	},
	onPageScroll(option) {
		uni.$emit('scroll');
		if (option.scrollTop > 50) {
			this.isScrolling = true;
		} else if (option.scrollTop < 50) {
			this.isScrolling = false;
		}
	},
	onLoad() {
		this.getRecommendList();
	},
	methods:{
		getRecommendList(){
			let that = this;
			if (that.hotScroll) return
			getProductHot(
				that.hotPage,
				that.hotLimit,
			).then(res => {
				that.hotPage++
				that.hotScroll = res.data.length < that.hotLimit
				that.hostProduct = that.hostProduct.concat(res.data)
			});
		},
		goDetail(item){
			goShopDetail(item, this.uid).catch(res => {
				uni.navigateTo({
					url: `/pages/goods_details/index?id=${item.id}`
				});
			});
		},
		lookInfo(){
			uni.navigateTo({
				url: '/pages/users/user_distribution_info/index'
			})
		},
		goHomePage(){
			uni.switchTab({
				url: '/pages/goods_cate/goods_cate'
			})
		}
	},
	onReachBottom() {
		this.getRecommendList();
	}
}
</script>
<style>
	page{
		background: #FBE7D8;
	}
</style>
<style scoped lang="scss">
	.top-card {
		/* #ifdef MP */
		height: 478rpx;
		/* #endif */
		/* #ifndef MP */
		height: 646rpx;
		/* #endif */
	}
	.cover{
		background-size: cover;
	}
	.full-text{
		position: absolute;
		bottom: 220rpx;
		left: 44rpx;
		color: #FF6270;
	}
	.content-box{
		position: relative;
		margin-top: -204rpx;
		padding-top: 78rpx;
	}
	.fuli-card{
		width: 702rpx;
		height: 316rpx;
		margin: auto;
		padding-top: 105rpx;
		.desc{
			position: absolute;
			top: 24rpx;
			right: 40rpx;
		}
	}
	.man-card{
		width: 702rpx;
		margin: 20rpx auto;
	}
	.text-pic{
		width: 256rpx;
		height: 40rpx;
	}
	.grid-item{
		background: #FFF6EF;
		border: 2rpx solid #FFF2DE;
		color: #6A251F;
	}
	.pro-box{
		top: -38rpx;
	}
	.home-btn{
		width: 480rpx;
		height: 80rpx;
		margin-bottom: 24rpx;
	}
</style>