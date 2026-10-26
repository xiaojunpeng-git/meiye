<template>
	<view :style="colorStyle">
		<view class="CommissionRank">
			<!-- #ifdef MP -->
			<view
				class="accountTitle"
				:style="{ 'background-image': 'url(' + imgHost + '/statics/images/brokerage_rank_bg1.png)', height: getHeight.barTop + getHeight.barHeight + 'px' }"
			>
				<view :style="{height:getHeight.barTop+'px'}"></view>
				<view class="sysTitle acea-row row-center-wrapper" :style="{ height: getHeight.barHeight + 'px' }">
					<view>佣金排行</view>
					<text class="iconfont icon-ic_leftarrow" @click="goarrow"></text>
				</view>
			</view>
			<view :style="{ height: getHeight.barTop + getHeight.barHeight + 'px' }"></view>
			<!-- #endif -->
			<view class="header" :style="'background-image: url(' + imgHost + '/statics/images/brokerage_rank_bg2.png);'">
				<view class="acea-row row-middle">
					<view class="left">
						<view class="tips" v-if="rankInfo.position">
							您目前的排名
							<text class="num">{{ rankInfo.position }}</text>
							名
						</view>
						<view class="tips" v-else>您目前暂无排名</view>
					</view>
				</view>
			</view>
			<view class="wrapper">
				<view class="nav acea-row row-around">
					<view class="item" :class="active == index ? 'on' : ''" v-for="(item, index) in navList" :key="index" @click="switchTap(index)">
						{{ item }}
					</view>
				</view>
				<view class="list" v-if="rankList.length">
					<view class="item acea-row row-between-wrapper" v-for="(item, index) in rankList" :key="index">
						<view class="acea-row row-middle">
							<view class="num" v-if="index <= 2">
								<image :src="'../static/no' + (index + 1) + '.png'"></image>
							</view>
							<view class="num" v-else>
								{{ index + 1 }}
							</view>
							<view class="pictrue">
								<image :src="item.avatar"></image>
							</view>
							<view class="text line1">{{ item.nickname }}</view>
						</view>
						<view class="people">￥{{ item.brokerage_price }}</view>
					</view>
				</view>
				<view v-if="!rankList.length">
					<emptyPage title="暂无排行榜数据哦～"></emptyPage>
				</view>
			</view>
			<view style="height: 130rpx" v-if="rankInfo.position > 0"></view>
			<view class="footer acea-row row-between-wrapper" v-if="rankInfo.position > 0">
				<view class="acea-row row-middle">
					<view class="me">我</view>
					<view class="pictrue">
						<image :src="rankInfo.avatar"></image>
					</view>
					<view class="name line1">{{ rankInfo.nickname }}</view>
					<view class="ranking">第{{ rankInfo.position }}名</view>
				</view>
				<view class="num">￥{{ rankInfo.brokerage_price }}</view>
			</view>
		</view>
		<!-- #ifdef MP -->
		<authorize v-if="isShowAuth" @authColse="authColse" @onLoadFun="onLoadFun"></authorize>
		<!-- #endif -->
	</view>
</template>

<script>
import emptyPage from '@/components/emptyPage.vue';
import { getBrokerageRank } from '@/api/user.js';
import { toLogin } from '@/libs/login.js';
import { mapGetters } from 'vuex';
import colors from '@/mixins/color';
import { HTTP_REQUEST_URL } from '@/config/app';
export default {
	components: {
		emptyPage
	},
	mixins: [colors],
	data() {
		return {
			imgHost: HTTP_REQUEST_URL,
			// #ifdef MP
			getHeight: this.$util.getWXStatusHeight(),
			// #endif
			navList: ['周排行', '月排行'],
			active: 0,
			rankInfo: {
				position: 0,
				avatar: '',
				brokerage_price: '',
				nickname: ''
			},
			rankList: [],
			page: 1,
			limit: 20,
			loadend: false,
			loading: false,
			loadTitle: '加载更多',
			type: 'week',
			isAuto: false, //没有授权的不会自动授权
			isShowAuth: false //是否隐藏授权
		};
	},
	computed: mapGetters(['isLogin']),
	watch: {
		isLogin: {
			handler: function (newV, oldV) {
				if (newV) {
					//#ifndef MP
					this.getBrokerageRankList();
					//#endif
				}
			},
			deep: true
		}
	},
	onLoad() {
		if (this.isLogin) {
			this.getBrokerageRankList();
		} else {
			toLogin();
		}
	},
	onShow() {
		uni.removeStorageSync('form_type_cart');
	},
	methods: {
		onLoadFun: function () {
			this.getBrokerageRankList();
			this.isShowAuth = false;
		},
		// 授权关闭
		authColse: function (e) {
			this.isShowAuth = e;
		},
		goarrow(){
			uni.navigateBack()
		},
		switchTap: function (index) {
			this.active = index;
			this.type = index ? 'month' : 'week';
			this.page = 1;
			this.loadend = false;
			this.$set(this, 'rankList', []);
			this.getBrokerageRankList();
		},
		getBrokerageRankList: function () {
			if (this.loadend) return;
			if (this.loading) return;
			this.loading = true;
			this.loadTitle = '';
			getBrokerageRank({
				page: this.page,
				limit: this.limit,
				type: this.type
			})
				.then((res) => {
					let list = res.data.rank;
					let loadend = list.length < this.limit;
					this.rankInfo.avatar = res.data.avatar;
					this.rankInfo.brokerage_price = res.data.brokerage_price;
					this.rankInfo.nickname = res.data.nickname;
					this.rankInfo.position = res.data.position;
					this.rankList.push.apply(this.rankList, list);
					this.loading = false;
					this.loadend = loadend;
					this.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					this.$set(this, 'rankList', this.rankList);
					this.page += 1;
				})
				.catch((err) => {
					this.loading = false;
					this.loadTitle = '加载更多';
				});
		}
	},
	onReachBottom() {
		this.getBrokerageRankList();
	}
};
</script>

<style scoped lang="scss">
.accountTitle {
	background-repeat: no-repeat;
	background-size: 100% 100%;
	position: fixed;
	left: 0;
	top: 0;
	width: 100%;
	z-index: 99;
	.sysTitle {
		width: 100%;
		position: relative;
		font-weight: 500;
		color: #fff;
		font-size: 30rpx;
		.iconfont {
			position: absolute;
			font-size: 36rpx;
			left: 11rpx;
			width: 60rpx;
		}
	}
}
.CommissionRank {
	padding-bottom: 30rpx;
	.footer {
		position: fixed;
		height: 128rpx;
		background-color: #fff;
		font-size: 28rpx;
		width: 100%;
		bottom: 0;
		left: 0;
		border-top: 1px solid #eeeeee;
		padding: 0 64rpx;

		.me {
			color: #ff7d00;
		}
		.pictrue {
			width: 80rpx;
			height: 80rpx;
			margin-left: 48rpx;

			image {
				width: 100%;
				height: 100%;
				border-radius: 50%;
			}
		}
		.name {
			max-width: 164rpx;
			margin-left: 24rpx;
		}
		.ranking {
			color: #ff7d00;
			margin-left: 16rpx;
		}
		.num{
			color: #ff7d00;
		}
	}
	.header {
		height: 434rpx;
		padding-top: 26rpx;
		background-repeat: no-repeat;
		background-size: 100% 100%;
		width: 100%;
		.left {
			.tips {
				font-size: 28rpx;
				font-weight: 400;
				color: #ffcb99;
				margin: 164rpx 0 0 46rpx;

				.num {
					font-family: 'SemiBold';
					font-size: 40rpx;
					margin: 0 8rpx;
				}
			}
		}
	}
	.wrapper {
		width: 710rpx;
		background-color: #fff;
		border-radius: 20rpx;
		margin: -154rpx auto 0 auto;

		.nav {
			font-size: 28rpx;
			font-weight: 400;
			color: #999;
			.item {
				color: #999999;
				padding: 32rpx 0 12rpx 0;
				&.on {
					color: #333;
					font-size: 32rpx;
					position: relative;
					font-weight: 600;
					&::after {
						position: absolute;
						content: '';
						width: 56rpx;
						height: 4rpx;
						background: #e93323;
						border-radius: 2rpx;
						left: 50%;
						margin-left: -28rpx;
						bottom: 0;
					}
				}
			}
		}

		.list {
			padding: 0 44rpx 72rpx 28rpx;
			.item {
				margin-top: 48rpx;
				.num {
					font-weight: 400;
					color: #999;
					font-size: 28rpx;
					width: 64rpx;
					height: 64rpx;
					text-align: center;
					line-height: 64rpx;

					image {
						width: 100%;
						height: 100%;
						display: block;
					}
				}
				.pictrue {
					width: 80rpx;
					height: 80rpx;
					margin-left: 28rpx;
					image {
						width: 100%;
						height: 100%;
						border-radius: 50%;
					}
				}
				.text {
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
