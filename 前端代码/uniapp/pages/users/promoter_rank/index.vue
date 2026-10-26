<template>
	<view :style="colorStyle">
		<view class="PromoterRank">
			<!-- #ifdef MP -->
			<view class="accountTitle">
				<view :style="{height:getHeight.barTop+'px'}"></view>
				<view class="sysTitle acea-row row-center-wrapper" :style="{height:getHeight.barHeight+'px'}">
					<view>推广人排行</view>
					<text class="iconfont icon-ic_leftarrow" @click="goarrow"></text>
				</view>
			</view>
			<view :style="{height:(getHeight.barTop+getHeight.barHeight)+'px'}"></view>
			<!-- #endif -->
			<view class="header">
				<view class="acea-row row-middle row-right">
					<view class="left">
						<view class="pictrue">
							<image src="../static/ranking.png"></image>
						</view>
						<view class="tips" v-if="rankInfo.rank">您目前的排名<text class="num">{{rankInfo.rank}}</text>名</view>
						<view class="tips" v-else>您目前暂无排名</view>
					</view>
					<view class="trophy">
						<image :src="imgHost+'/statics/images/users/trophy.png'"></image>
					</view>
				</view>
			</view>
			<view class="wrapper">
				<view class="nav acea-row row-around">
					<view class="item" :class="active == index ? 'on' : ''" v-for="(item,index) in navList"
						:key="index" @click="switchTap(index)">
						{{ item }}
					</view>
				</view>
				<view class="list" v-if="rankList.length">
					<view class="item acea-row row-between-wrapper" v-for="(item,index) in rankList" :key="index">
						<view class="acea-row row-middle">
							<view class="num" v-if="index <= 2">
								<image :src="'../static/no'+(index+1)+'.png'"></image>
							</view>
							<view class="num" v-else>
								{{index+1}}
							</view>
							<view class="pictrue">
								<image :src="item.avatar"></image>
							</view>
							<view class="text line1">{{item.nickname}}</view>
						</view>
						<view class="people">{{item.count}}人</view>
					</view>
				</view>
				<view v-if="!rankList.length">
					<emptyPage title="暂无排行榜数据哦～"></emptyPage>
				</view>
			</view>
			<view style="height: 130rpx;" v-if="rankInfo.position>0"></view>
			<view class="footer acea-row row-between-wrapper" v-if="rankInfo.position>0">
				<view class="acea-row row-middle">
					<view class="me">我</view>
					<view class="pictrue">
						<image :src="rankInfo.avatar"></image>
					</view>
					<view class="name line1">{{rankInfo.nickname}}</view>
					<view class="ranking">第{{rankInfo.position}}名</view>
				</view>
				<view class="num">￥{{rankInfo.brokerage_price}}</view>
			</view>
		</view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	import {
		getRankList
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import emptyPage from '@/components/emptyPage.vue'
	import {
		mapGetters
	} from "vuex";
	import home from '@/components/home';
	import colors from '@/mixins/color.js';
  import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		components: {
			home,
			emptyPage
		},
		mixins: [colors],
		data() {
			return {
                imgHost: HTTP_REQUEST_URL,
				// #ifdef MP
				getHeight: this.$util.getWXStatusHeight(),
				// #endif
				navList: ["周排行", "月排行"],
				active: 0,
				page: 1,
				limit: 20,
				type: 'week',
				loading: false,
				loadend: false,
				rankInfo:{
					position:0,
					avatar:'',
					brokerage_price:'',
					nickname:''
				},
				rankList: [],
				isShowAuth: false,
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						this.getRanklist();
					}
				},
				deep: true
			}
		},
		onLoad() {
			if (this.isLogin) {
				this.getRanklist();
			} else {
				toLogin()
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			onLoadFun() {
				this.getRanklist();
				this.isShowAuth = false;
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			goarrow(){
				uni.navigateBack()
			},
			getRanklist: function() {
				let that = this;
				if (that.loadend) return;
				if (that.loading) return;
				that.loading = true;
				getRankList({
					page: that.page,
					limit: that.limit,
					type: that.type
				}).then(res => {
					let list = res.data.list;
					that.rankInfo = res.data;
					that.rankList.push.apply(that.rankList, list);
					that.loadend = list.length < that.limit;
					that.loading = false;
					that.$set(that, 'rankList', that.rankList);
					that.page = that.page + 1;
				}).catch(err => {
					that.loading = false;
				})
			},

			switchTap: function(index) {
				if (this.active === index) return;
				this.active = index;
				this.type = index ? 'month' : 'week';
				this.page = 1;
				this.loadend = false;
				this.$set(this, 'rankList', []);
				this.getRanklist();
			},
		},
		onReachBottom: function() {
			this.getRanklist();
		}
	}
</script>

<style scoped lang="scss">
	.accountTitle{
		background: linear-gradient(180deg, #FFC48D 0%, #FCCEA4 100%);
		position: fixed;
		left:0;
		top:0;
		width: 100%;
		z-index: 99;
		.sysTitle{
			width: 100%;
			position: relative;
			font-weight: 500;
			color: #333333;
			font-size: 30rpx;
			.iconfont{
				position: absolute;
				font-size: 36rpx;
				left:11rpx;
				width: 60rpx;
			}
		}
	}
	.PromoterRank{
		padding-bottom: 30rpx;
		.footer{
			position: fixed;
			height: 128rpx;
			background-color: #fff;
			font-size: 28rpx;
			width: 100%;
			bottom: 0;
			left:0;
			border-top: 1px solid #EEEEEE;
			padding: 0 64rpx;
			
			.me{
				color: #FF7D00;
			}
			.pictrue{
				width: 80rpx;
				height: 80rpx;
				margin-left: 48rpx;
				
				image{
					width: 100%;
					height: 100%;
					border-radius: 50%;
				}
			}
			.name{
				max-width: 164rpx;
				margin-left: 24rpx;
			}
			.ranking{
				color: #FF7D00;
				margin-left: 16rpx;
			}
			.num{
				color: #FF7D00;
			}
		}
		.header{
			background: linear-gradient(180deg, #FCCEA4 50%, #F5F5F5 100%);
			height: 560rpx;
			padding-top: 26rpx;
			.left{
				.pictrue{
					width: 352rpx;
					height: 76rpx;
					image{
						width: 100%;
						height: 100%;
					}
				}
				.tips{
					font-size: 28rpx;
					font-weight: 400;
					color: #D25F00;
					margin-top: 30rpx;
					
					.num{
						font-family: 'SemiBold';
						font-size: 40rpx;
						margin: 0 8rpx;
					}
				}
			}
			.trophy{
				width: 324rpx;
				height: 260rpx;
				margin-left: 38rpx;
				image{
					width: 100%;
					height: 100%;
				}
			}
		}
		.wrapper{
			width: 710rpx;
			background-color: #fff;
			border-radius: 20rpx;
			margin: -278rpx auto 0 auto;
			
			.nav{
				font-size: 28rpx;
				font-weight: 400;
				color: #999;
				.item{
					color: #999999;
					padding: 32rpx 0 12rpx 0;
					&.on{
						color: #333;
						font-size: 32rpx;
						position: relative;
						font-weight: 600;
						&::after{
							position: absolute;
							content: '';
							width: 56rpx;
							height: 4rpx;
							background: #E93323;
							border-radius: 2rpx;
							left:50%;
							margin-left: -28rpx;
							bottom: 0;
						}
					}
				}
			}
			
			.list{
				padding: 0 44rpx 72rpx 28rpx;
				.item{
					margin-top: 48rpx;
					.num{
						font-weight: 400;
						color: #999;
						font-size: 28rpx;
						width: 64rpx;
						height: 64rpx;
						text-align: center;
						line-height: 64rpx;
						
						image{
							width: 100%;
							height: 100%;
							display: block;
						}
					}
					.pictrue{
						width: 80rpx;
						height: 80rpx;
						margin-left: 28rpx;
						image{
							width: 100%;
							height: 100%;
							border-radius: 50%;
						}
					}
					.text{
						font-weight: 400;
						color: #333333;
						font-size: 28rpx;
						margin-left: 24rpx;
						width: 180rpx;
					}
				}
			}
		}
	}
</style>
