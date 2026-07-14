<template>
	<view class="bargainCon" :style="colorStyle">
		<skeleton :show="showSkeleton" :isNodes="isNodes" ref="skeleton" loading="chiaroscuro" selector="skeleton"
			bgcolor="#FFF"></skeleton>
		<view class="bargain skeleton" :style="{visibility: showSkeleton ? 'hidden' : 'visible'}">
			<view :style="'background-image: url('+(bargainUid != userInfo.uid ?supportBg:bargaBg)+');'"
				class="header">
				<!-- #ifndef MP -->
				<view class="iconfont icon-ic_leftarrow" @click="goBack"></view>
				<!-- #endif -->
				<!-- #ifdef MP -->
				<view class="bargainTitle">
					<view :style="{height:getHeight.barTop+'px'}"></view>
					<view class="sysTitle acea-row row-center-wrapper" :style="{height:getHeight.barHeight+'px'}">
						<text class="iconfont icon-ic_leftarrow" @click="goBack"></text>
					</view>
				</view>
				<!-- #endif -->
				<view class="rule-btn w-72 flex-center fs-24 text--w111-fff" :style="{top: 81 + sysHeight + 'px'}"
					@tap="showDrawer = true">规则</view>
				<view class="rule-btn w-124 flex-center fs-24 text--w111-fff" :style="{top: 110 + sysHeight + 'px'}"
					@tap="goRecord">砍价记录</view>
			</view>
			<view class="wrapperCon">
				<view class="timeCon" :style="'background-image: url('+ countdownBg +');'">
					<view v-if="bargainUid == userInfo.uid">
						<countDown 
						:tipText="'倒计时：'" 
						inDayText="天" 
						dayText=":" 
						hourText=":" 
						minuteText=":" 
						secondText=" "
						:datatime="datatime" 
						bgColor="#E93323"
						colors="#FFFFFF"
						dotColor="#E93323"
						:isDay="true" 
						@endTime='endTime' 
						v-if='endTimes == 0'></countDown>
						<view class="endTimes" v-else>此商品砍价已结束</view>
					</view>
					<view v-if="bargainUid != userInfo.uid" class="pictxt acea-row row-center-wrapper">
						<view class="pictrue">
							<image :src="bargainUserInfo.avatar"></image>
						</view>
						<view class="text">
							<text class="name">{{ bargainUserInfo.nickname || '' }}</text>
							邀请您帮忙砍价
						</view>
					</view>
				</view>
				<view class="wrapper">
					<view class="pictxt acea-row row-between-wrapper" @tap="goProduct">
						<view class="pictrue skeleton-rect">
							<easy-loadimage
							:image-src="bargainInfo.image"
							width="240rpx"
							height="240rpx"
							borderRadius="16rpx"></easy-loadimage>
						</view>
						<view class="text">
							<view class="top">
								<view class="name line2 skeleton-rect">
								  {{ bargainInfo.title }}
								</view>
								<view class="tips">已有<text class="num">{{payCount}}人</text>砍价成功</view>
							</view>
							<baseMoney :money="bargainInfo.price" color='#E93323' :preFix="'当前'" symbolSize="32" integerSize="48"
								decimalSize="32" preFixSize='22' weight></baseMoney>
						</view>
					</view>
					<!-- 进度条 -->
					<block v-if="bargainUserHelpInfo.price > 0">
						<view class="cu-progress acea-row row-middle round margin-top" :class="endTimes?'on':''">
							<view class="acea-row row-middle bg-red"
								:style="'width:' + bargainUserHelpInfo.pricePercent + '%;'">
								<view>
									<image class="img" :class="bargainUserHelpInfo.alreadyPrice<=0?'on':''" :src="knife"></image>
									<view class="triangle" v-if="bargainUserHelpInfo.alreadyPrice>0"></view>
									<view class="hacked white-nowrap" v-if="bargainUserHelpInfo.alreadyPrice>0">
										已砍{{ bargainUserHelpInfo.alreadyPrice }}元
									</view>
								</view>
							</view>
						</view>
						<view class="money acea-row row-right">
							<view>还剩<text class="num">{{ bargainUserHelpInfo.price }}元</text></view>
						</view>
					</block>
					<view class="skeleton-rect" v-if="!isLogin" @click="getIsLogin">
						<view class="bargainBnt">立即登录</view>
					</view>
					<view class="skeleton-rect" v-if="endTimes">
						<view class="bargainBnt grey">活动已结束</view>
						<view class="seeGood" @click="goIndex">再去逛逛<text class="iconfont icon-ic_rightarrow"></text></view>
					</view>
					<view v-else>
						<!-- 自己砍价 -->
						<view class="skeleton-rect"
							v-if="bargainUid == userInfo.uid && (!userBargainStatus || userBargainStatus == bargainSumCount) && bargainUserHelpInfo.price > 0">
							<view class="bargainBnt" @tap="userBargain" v-if="productStock > 0 && quota > 0">立即参与砍价</view>
							<view class="bargainBnt grey" v-if="productStock <= 0 || quota <= 0">商品暂无库存</view>
						</view>
						<!-- 帮助砍价、帮砍成功： -->
						<view class="skeleton-rect"
							v-if="bargainUid == userInfo.uid && bargainUserHelpInfo.price > 0 && userBargainStatus != bargainSumCount">
							<view class="bargainBnt" @tap="getBargainUserBargainPricePoster">邀请好友帮忙砍价</view>
							<view class="tip">
								已有
								<text class="num">{{ bargainUserHelpInfo.count }}</text>
								位好友成功帮您砍价
							</view>
						</view>
										
						<view v-if="bargainUid != userInfo.uid && userBargainStatusHelp && bargainUserHelpInfo.price > 0">
							<view class="bargainBnt skeleton-rect" @tap="setBargainHelp">帮好友砍一刀</view>
						</view>
						<view v-if="bargainUid != userInfo.uid && userBargainStatusHelp && bargainUserHelpInfo.price == 0">
							<view class="bargainSuccess skeleton-rect">
								<text class="iconfont icon-a-ic_CompleteSelect"></text>
								好友已砍价成功
							</view>
							<view class="bargainBnt grey skeleton-rect" v-if="productStock <= 0 || quota <= 0">商品暂无库存</view>
							<view v-else>
								<view class="bargainBnt grey skeleton-rect" v-if="bargainInfo.is_bargain">我也要参与</view>
								<view class="bargainBnt skeleton-rect" @tap="currentBargainUser" v-else>我也要参与</view>
							</view>
						</view>
						<view v-if="bargainUid != userInfo.uid && !userBargainStatusHelp">
							<view class="bargainSuccess skeleton-rect" v-if="productStock <= 0 || quota <= 0">
								<text class="iconfont icon-a-ic_CompleteSelect"></text>
								已成功帮助好友砍价
							</view>
							<view class="bargainBnt grey" v-if="productStock <= 0 || quota <= 0">商品暂无库存</view>
							<view v-else>
								<view class="bargainBnt grey skeleton-rect" v-if="bargainInfo.is_bargain">我也要参与</view>
								<view class="bargainBnt skeleton-rect" @tap="currentBargainUser" v-else>我也要参与</view>
							</view>
						</view>
						<view v-if="bargainUserHelpInfo.price == 0 && bargainUid == userInfo.uid && statusPay != 3">
							<view class="bargainSuccess on skeleton-rect">
								<text class="iconfont icon-a-ic_CompleteSelect"></text>
								恭喜您砍价成功，快去支付吧～
							</view>
							<view class="bargainBnt grey" v-if="productStock <= 0 || quota <= 0">商品暂无库存</view>
							<view class="bargainBnt skeleton-rect" v-else @tap="goPay">立即支付</view>
							<view class="bargainBnt on skeleton-rect" @tap="goBargainList">抢更多商品</view>
						</view>
					</view>
				</view>
			</view>
			<view class="bargainGang skeleton-rect" v-if="bargainUserHelpList.length">
				<view class="title acea-row row-center-wrapper" :style="'background-image: url('+ bargainTitle +');'">
					<view class="pictrue"></view>
					<view class="titleCon">砍价帮</view>
					<view class="pictrue on"></view>
				</view>
				<view class="tips">共有<text class="num">{{bargainUserHelpList.length}}</text>位好友成功帮您砍价</view>
				<view class="list">
					<block v-for="(item, index) in bargainUserHelpList" :key="index" v-if="index < 3 || !couponsHidden">
						<view class="item acea-row row-between-wrapper">
							<view class="pictxt acea-row row-between-wrapper">
								<view class="pictrue">
									<image :src="item.avatar"></image>
								</view>
								<view class="text">
									<view class="name line1">{{ item.nickname }}</view>
									<view class="line1">{{ item.add_time }}</view>
								</view>
							</view>
							<view class="money">
								砍掉<text class="label">¥</text><text class="num">{{ item.price }}</text>
							</view>
						</view>
					</block>
					<view class="open acea-row row-center-wrapper" @click="openTap"
						v-if="bargainUserHelpList.length > 3">
						{{ couponsHidden ? '展开全部好友' : '关闭全部好友' }}
						<text class="iconfont"
							:class="couponsHidden == true ? 'icon-ic_downarrow' : 'icon-ic_uparrow'"></text>
					</view>
				</view>
				<view class="load" v-if="!limitStatus" @tap="getBargainUser">点击加载更多</view>
			</view>
			<view class="goodsDetails skeleton-rect">
				<view class="title acea-row row-center-wrapper" :style="'background-image: url('+ bargainTitle +');'">
					<view class="pictrue"></view>
					<view class="titleCon">商品详情</view>
					<view class="pictrue on"></view>
				</view>
				<view class="conter">
					<!-- #ifndef APP-PLUS -->
					<jyf-parser :html="bargainInfo.description" ref="article" :tag-style="tagStyle"></jyf-parser>
					<!-- #endif -->
					<!-- #ifdef APP-PLUS -->
					<view v-html="bargainInfo.description"></view>
					<!-- #endif -->
				</view>
			</view>
			<view class="bargainTip" :class="active == true ? 'on' : ''">
				<view class="pictrue">
					<image :src="bargainPop"></image>
				</view>
				<view v-if="bargainUid == userInfo.uid">
					<view class="cutOff">
						<view class="title">您已砍掉<text class="num">{{ bargainUserBargainPrice.price }}元</text></view>
						<view>听说分享次数越多，</view>
						<view>砍价成功的几率越高哦！</view>
					</view>
					<!-- #ifdef MP -->
					<button class="tipBnt" @tap="getBargainUserBargainPricePoster"><text class="iconfont icon-ic_kanjia"></text>邀请好友帮砍价</button>
					<!-- #endif -->
					<!-- #ifdef H5 -->
					<view class="tipBnt" @tap="getBargainUserBargainPricePoster"><text class="iconfont icon-ic_kanjia"></text>邀请好友帮砍价</view>
					<!-- #endif -->
				</view>
				<view v-else>
					<view class="cutOff">
						<view class="title">成功帮砍<text class="num">{{ bargainUserBargainPrice.price }}元</text></view>
						<view>您也可以砍价低价拿哦，</view>
						<view>快去挑选心仪的商品吧~</view>
					</view>
					<view class="tipBnt grey" v-if="bargainInfo.is_bargain">我也要参与</view>
					<view @tap="currentBargainUser" class="tipBnt" v-else>我也要参与</view>
				</view>
			</view>
			<view class="mask" catchtouchmove="true" v-show="active == true" @tap="close"></view>
		</view>
		<!-- 分享按钮 -->
		<view class="generate-posters pb-safe" :class="posters ? 'on' : ''">
			<view class="generateCon acea-row row-middle">
				<!-- #ifdef H5 -->
				<button class="item" hover-class="none" v-if="weixinStatus === true"
					@click="H5ShareBox = true">
					<view class="pictrue">
						<image src="../../../static/images/weixin.png"></image>
					</view>
					<view class="">分享给好友</view>
				</button>
				<!-- #endif -->
				<!-- #ifdef MP -->
				<button class="item" open-type="share" hover-class="none">
					<view class="pictrue">
						<image src="../../../static/images/weixin.png"></image>
					</view>
					<view class="">分享给好友</view>
				</button>
				<!-- #endif -->
				<!-- #ifdef APP-PLUS -->
				<view class="item" @tap="appShare('WXSceneSession')">
					<view class="pictrue">
						<image src="../../../static/images/weixin.png"></image>
					</view>
					<view class="">分享给好友</view>
				</view>
				<view class="item" @tap="appShare('WXSceneTimeline')">
					<view class="pictrue">
						<image src="../static/weixinCircle.png"></image>
					</view>
					<view class="">分享朋友圈</view>
				</view>
				<!-- #endif -->
				<view class="item" @tap="getpreviewImage">
					<view class="pictrue">
						<image src="../../../static/images/changan.png"></image>
					</view>
					<view class="">预览发图</view>
				</view>
				<!-- #ifndef H5  -->
				<button class="item" hover-class="none" @tap="savePosterPath">
					<view class="pictrue">
						<image src="../../../static/images/haibao.png"></image>
					</view>
					<view class="">保存海报</view>
				</button>
				<!-- #endif -->
			</view>
			<view class="generateClose flex-center" @tap="posterImageClose">取消</view>
		</view>
		<div class="fixed-center" v-if="posters && !posterImageStatus">
			<image class="poster-loading" :src="imgHost + '/statics/images/poster_loading.png'" mode="widthFix" ></image>
		</div>
		<!-- 海报展示 -->
		<view class="poster-mask" v-if="posters"></view>
		<view class="poster-pop" v-if="posterImageStatus">
			<image :src="posterImage"></image>
		</view>
		<canvas class="canvas" canvas-id="myCanvas" v-if="canvasStatus"></canvas>
		<view class="mask" v-if="posters" @click="listenerActionClose"></view>
		<!-- 发送给朋友图片 -->
		<view class="share-box" v-if="H5ShareBox">
			<image :src="imgHost + '/statics/images/share-info.png'" @click="H5ShareBox = false"></image>
		</view>
		<!-- #ifdef H5 || APP-PLUS -->
		<view class="followCode" v-if="followCode">
			<view class="pictrue">
				<view class="code-bg"><img class="imgs" :src="codeSrc" /></view>
			</view>
			<view class="mask" @click="closeFollowCode"></view>
		</view>
		<zb-code ref="qrcode" :show="codeShow" :cid="cid" :val="val" :size="size" :unit="unit"
			:background="background" :foreground="foreground" :pdground="pdground" :icon="icon" :iconSize="iconsize"
			:onval="onval" :loadMake="loadMake" @result="qrR" />
		<!-- #endif -->
		<base-drawer mode="bottom" :visible="showDrawer" background-color="transparent" mask maskClosable @close="closeDrawer">
			<view class="w-full bg--w111-fff rd-t-40rpx py-32 relative">
				<view class="text-center fs-32 text--w111-333 fw-500">砍价规则</view>
				<view class="close flex-center" @tap='closeDrawer'>
					<text class="iconfont icon-ic_close fs-24 text--w111-999"></text>
				</view>
				<view class="mt-48 px-32 scroll-content">
					<jyf-parser :html="bargainInfo.rule" ref="article" :tag-style="tagStyle"></jyf-parser>
				</view>
				<view class="mx-20 pb-safe">
				  <view class="mt-52 h-72 flex-center rd-36px bg-red fs-26 text--w111-fff" @click="closeDrawer">我知道了</view>
				</view>
			</view>
		</base-drawer>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import zbCode from '@/components/zb-code/zb-code.vue';
	import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
	import {
		getBargainDetail,
		postBargainStartUser,
		postBargainStart,
		postBargainHelpPrice,
		postBargainHelpCount,
		postBargainHelp,
		postBargainHelpList,
		postBargainShare,
		getBargainPosterData,
	} from '@/api/activity.js';
	import {
		postCartAdd
	} from '@/api/store.js';
	import util from '@/utils/util.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from 'vuex';
	import countDown from '@/components/countDown';
	import home from '@/components/home';
	import parser from '@/components/jyf-parser/jyf-parser';
	import {
		silenceBindingSpread
	} from '@/utils';
	import {
		TOKENNAME,
		HTTP_REQUEST_URL
	} from '@/config/app.js';
	const app = getApp();
	import colors from "@/mixins/color";
	export default {
		components: {
			baseDrawer,
			countDown,
			home,
			'jyf-parser': parser
		},
		/**
		 * 页面的初始数据
		 */
		mixins: [colors],
		data() {
			return {
				// #ifdef MP
				getHeight: this.$util.getWXStatusHeight(),
				// #endif
				sysHeight: sysHeight,
				showSkeleton: true, //骨架屏显示隐藏
				isNodes: 0, //控制什么时候开始抓取元素节点,只要数值改变就重新抓取
				countDownDay: '00',
				countDownHour: '00',
				countDownMinute: '00',
				countDownSecond: '00',
				active: false,
				id: 0, //砍价产品编号
				userInfo: {}, //当前用户信息
				bargainUid: 0, //开启砍价用户
				bargainUserInfo: {}, //开启砍价用户信息
				bargainUserId: 0, //开启砍价编号
				bargainInfo: {
					brand_name:''
				}, //砍价产品
				offset: 0,
				limit: 20,
				limitStatus: false,
				bargainUserHelpList: [],
				bargainUserHelpInfo: [],
				bargainUserBargainPrice: 0,
				status: '', // 0 开启砍价   1  朋友帮忙砍价  2 朋友帮忙砍价成功 3 完成砍价  4 砍价失败 5已创建订单
				bargainCount: [], //分享人数  浏览人数 参与人数
				retunTop: true,
				bargainPartake: 0,
				isHelp: false,
				interval: null,
				userBargainStatus: 0, //判断自己是否砍价
				bargainSumCount: 0, // 购买次数
				productStock: 0, //判断是否售罄；
				quota: 0, //判断是否已限量；
				userBargainStatusHelp: true,
				navH: '',
				statusPay: '',
				bargainPrice: 0,
				datatime: 0,
				offest: '',
				tagStyle: {
					img: 'width:100%;display:block;',
					table: 'width:100%',
					video: 'width:100%'
				},
				H5ShareBox: false, //公众号分享图片
				systemH: 100,
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				// pages: '',
				posters: false,
				weixinStatus: false,
				couponsHidden: true,
				followCode: false,
				//二维码参数
				codeShow: false,
				cid: '1',
				ifShow: true,
				val: "", // 要生成的二维码值
				size: 200, // 二维码大小
				unit: 'upx', // 单位
				background: '#FFF', // 背景色
				foreground: '#000', // 前景色
				pdground: '#000', // 角标色
				icon: '', // 二维码图标
				iconsize: 40, // 二维码图标大小
				lv: 3, // 二维码容错级别 ， 一般不用设置，默认就行
				onval: true, // val值变化时自动重新生成二维码
				loadMake: true, // 组件加载完成后自动生成二维码
				src: '', // 二维码生成后的图片地址或base64
				codeSrc: "",
				picUrl: {},
				payCount: 0, //砍价成功人数；
				endTimes:0,
				imgHost:HTTP_REQUEST_URL,
				supportBg: HTTP_REQUEST_URL+'/statics/images/bargain/bargain-support.png',
				bargaBg: HTTP_REQUEST_URL+'/statics/images/bargain/bargain-con.png',
				countdownBg: HTTP_REQUEST_URL+'/statics/images/bargain/bargain-countdown-bg.png',
				knife: HTTP_REQUEST_URL+'/statics/images/bargain/bargain-knife.png',
				bargainTitle: HTTP_REQUEST_URL+'/statics/images/bargain/bargain-title-bg.png',
				bargainPop: HTTP_REQUEST_URL+'/statics/images/bargain/bargain-pop.jpg',
				showDrawer: false,
				posterImageStatus: false,
				posterImage: '', //海报路径
				canvasStatus: false, //海报绘图标签
				homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						// #ifndef MP
						this.addShareBargain();
						// #endif
					}
				},
				deep: true
			}
		},
		/**
		 * 生命周期函数--监听页面加载
		 */
		onLoad: function(options) {
			this.colorData();
			var that = this;
			// #ifdef MP
			uni.getSystemInfo({
				success: function(res) {
					that.systemH = res.statusBarHeight;
					that.navH = that.systemH + 10;
				}
			});
			// #endif
			var pages = getCurrentPages();
			if (pages.length <= 1) {
				that.retunTop = false;
			}
			//扫码携带参数处理
			// #ifdef MP
			if (options.scene) {
				var value = util.getUrlParams(decodeURIComponent(options.scene));
				if (typeof value === 'object') {
					if (value.id) options.id = value.id;
					if (value.spid) options.spid = value.spid;
				} else {
					app.globalData.spid = value;
				}
			}
			// #endif
			
			if (options.hasOwnProperty('id')) {
				that.id = options.id;
				that.bargainUid = options.spid || 0;
				//记录推广人uid
				if (options.spid) app.globalData.spid = options.spid;
			}
			this.getBargainDetails();
			if (this.isLogin) {
				if (that.bargainUid == 'undefined' || !that.bargainUid) {
					that.bargainUid = that.$store.state.app.uid;
				}
				this.addShareBargain();
				// app.globalData.openPages = '/pages/activity/goods_bargain_details/index?id=' + this.id + '&bargain=' + this.bargainUid +
				// 	'&spid=' + e.detail.uid;
				// this.$set(that, 'bargainPartake', e.detail.uid);
			} else {
				this.$Cache.set('login_back_url',
					`/pages/activity/goods_bargain_details/index?id=${options.id}&spid=${this.bargainUid}`);
				// this.getIsLogin();
			}

			this.isLogin && silenceBindingSpread();
			uni.setNavigationBarTitle({
				title: '砍价详情'
			});
			// #ifdef H5
			this.val = window.location.origin + '/pages/activity/goods_bargain_details/index?id=' + this.id +
				'&spid=' +
				this.$store.state.app.uid
			// #endif
			// #ifdef APP-PLUS
			this.val = HTTP_REQUEST_URL + '/pages/activity/goods_bargain_details/index?id=' + this.id +
				'&spid=' +
				this.$store.state.app.uid
			// #endif
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			endTime(e){
				this.endTimes = e;
				
			},
			getIsLogin(){
				toLogin()
			},
			// app分享
			// #ifdef APP-PLUS
			appShare(scene) {
				let that = this
				let routes = getCurrentPages(); // 获取当前打开过的页面路由数组
				let curRoute = routes[routes.length - 1].$page.fullPath // 获取当前页面路由，也就是最后一个打开的页面路由

				uni.share({
					provider: "weixin",
					scene: scene,
					type: 0,
					href: `${HTTP_REQUEST_URL}${curRoute}`,
					title: that.bargainInfo.title,
					imageUrl: that.bargainInfo.small_image,
					success: function(res) {
						uni.showToast({
							title: '分享成功',
							icon: 'success'
						})
						that.posters = false;
					},
					fail: function(err) {
						uni.showToast({
							title: '分享失败',
							icon: 'none',
							duration: 2000
						})
						that.posters = false;
					}
				});
			},
			// #endif
			qrR(res) {
				this.codeSrc = res
			},
			/**
			 * 分享打开
			 * 
			 */
			listenerActionSheet: function() {
				if (this.isLogin == false) {
					this.getIsLogin();
				} else {
					// #ifdef H5
					if (this.$wechat.isWeixin() === true) {
						this.weixinStatus = true;
					}
					// #endif
					this.posters = true;
					this.getPosterInfo();
				}
			},
			getBargainUserBargainPricePoster() {
				this.active = false;
				this.listenerActionSheet();
			},
			// 分享关闭
			listenerActionClose: function() {
				this.posters = false;
			},
			// 小程序关闭分享弹窗；
			goFriend: function() {
				this.posters = false;
			},
			openTap() {
				this.$set(this, 'couponsHidden', !this.couponsHidden);
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e;
			},
			// 去商品页
			goProduct() {
				uni.navigateTo({
					url: `/pages/goods_details/index?id=${this.bargainInfo.product_id}`
				});
			},
			// 自己砍价；
			userBargain: function() {
				let that = this;
				if (that.userInfo.uid == that.bargainUid) {
					if (that.userBargainStatus == that.bargainInfo.num) {
						return that.$util.Tips({
							title: `该商品每人限购${that.bargainInfo.num}${that.bargainInfo.unit_name}`
						});
					} else {
						that.setBargain();
					}
				}
			},
			goBack: function() {
				this.$util.backPage();
			},
			gobargainUserInfo: function() {
				//获取开启砍价用户信息
				var that = this;
				var data = {
					userId: that.bargainUid
				};
				postBargainStartUser({
					bargainId: that.id,
					bargainUserUid: that.bargainUid
				}).then(res => {
					that.$set(that, 'bargainUserInfo', res.data);
				});
			},
			goPay: function() {
				//立即支付
				var that = this;
				var data = {
					productId: that.bargainInfo.product_id,
					bargainId: that.id,
					cartNum: 1,
					uniqueId: '',
					combinationId: 0,
					secKillId: 0,
					new: 1
				};
				postCartAdd(data)
					.then(res => {
						uni.navigateTo({
							url: '/pages/goods/order_confirm/index?new=1&cartId=' + res.data.cartId
						});
					})
					.catch(err => {
						return that.$util.Tips({
							title: err
						});
					});
			},
			getBargainDetails: function() {
				//获取砍价产品详情
				var that = this;
				var id = that.id;
				getBargainDetail(id)
					.then(function(res) {
						that.payCount = res.data.payCount;
						that.bargainInfo = res.data.bargain;
						if(that.bargainInfo.description){
						   that.bargainInfo.description = that.bargainInfo.description.replace(
						   	/<img/gi,
						   	'<img style="max-width:100%;height:auto;float:left;display:block" '
						   );
						   that.bargainInfo.description = that.bargainInfo.description.replace(
						   	/<video/gi,
						   	'<video style="width:100%;height:auto;display:block" '
						   );
						}
						that.bargainPrice = res.data.bargain.price;
						that.userInfo = res.data.userInfo;
						that.bargainSumCount = res.data.bargainSumCount;
						that.userBargainStatus = res.data.userBargainStatus;
						that.productStock = res.data.bargain.attr.product_stock;
						that.quota = res.data.bargain.attr.quota;
						that.datatime = res.data.bargain.stop_time;
						uni.setNavigationBarTitle({
							title: res.data.bargain.title.substring(0, 13) + '...'
						});
						that.bargainUserHelpList = [];
						if(that.isLogin){
							that.getBargainHelpCount();
							that.getBargainUser();
							that.gobargainUserInfo();
						}
						//#ifdef H5
						that.setOpenShare();
						//#endif
						that.showSkeleton = false
					})
					.catch(function(err) {
						that.$util.Tips({
							title: err
						}, {
							tab: 2,
							url: '/pages/activity/goods_bargain/index'
						});
					});
			},
			getBargainHelpCount: function() {
				//获取砍价帮总人数、剩余金额、进度条、已经砍掉的价格
				var that = this;
				var data = {
					bargainId: that.id,
					bargainUserUid: that.bargainUid
				};
				postBargainHelpCount(data).then(res => {
					var price = util.$h.Sub(that.bargainPrice, res.data.alreadyPrice);
					that.bargainUserHelpInfo = res.data;
					that.bargainInfo.price = parseFloat(price) <= 0 ? 0 : price;
					that.userBargainStatusHelp = res.data.userBargainStatus;
					that.statusPay = res.data.status;
				});
			},
			currentBargainUser: function() {
				//当前用户砍价
				this.$set(this, 'bargainUid', this.userInfo.uid);
				this.setBargain();
			},
			setBargain: function() {
				//参与砍价
				var that = this;
				postBargainStart(that.id).then(res=>{
					if (res.data.code === 'subscribe') {
						that.$set(that, 'followCode', true);
						this.codeSrc = res.data.url
						return;
					}
					that.$set(that, 'bargainUserId', res.data);
					that.getBargainUserBargainPrice();
					that.setBargainHelp();
					that.getBargainHelpCount();
					that.userBargainStatus = 1;
				}).catch(err=>{
					that.$util.Tips({
						title: err
					});
				})
			},
			setBargainHelp: function() {
				//帮好友砍价
				var that = this;
				var data = {
					bargainId: that.id,
					bargainUserUid: that.bargainUid
				};
				postBargainHelp(data)
					.then(res => {
						if (res.data.code === 'subscribe') {
							that.$set(that, 'followCode', true);
							this.codeSrc = res.data.url
							return;
						}
						that.$set(that, 'bargainUserHelpList', []);
						that.$set(that, 'isHelp', true);
						that.getBargainUser();
						that.getBargainUserBargainPrice();
						that.getBargainHelpCount();

					})
					.catch(err => {
						that.$util.Tips({
							title: err
						});
						that.$set(that, 'bargainUserHelpList', []);
						that.getBargainUser();
					});
			},
			getBargainUser: function() {
				//获取砍价帮
				var that = this;
				var data = {
					bargainId: that.id,
					bargainUserUid: that.bargainUid,
					offset: that.offset,
					limit: that.limit
				};
				postBargainHelpList(data).then(res => {
					var bargainUserHelpListNew = [];
					var bargainUserHelpList = that.bargainUserHelpList;
					var len = res.data.length;

					bargainUserHelpListNew = bargainUserHelpList.concat(res.data);

					that.$set(that, 'bargainUserHelpList', res.data);
					that.$set(that, 'limitStatus', data.limit > len);
					that.$set(that, 'offest', Number(data.offset) + Number(data.limit));
				});
			},
			getBargainUserBargainPrice: function() {
				//获取帮忙砍价砍掉多少金额
				var that = this;
				var data = {
					bargainId: that.id,
					bargainUserUid: that.bargainUid
				};
				postBargainHelpPrice(data)
					.then(res => {
						that.$set(that, 'bargainUserBargainPrice', res.data);
						that.$set(that, 'active', true);
					})
					.catch(err => {
						that.$set(that, 'active', false);
					});
			},
			goBargainList: function() {
				uni.navigateTo({
					url: '/pages/activity/goods_bargain/index'
				});
			},
			goIndex(){
				uni.switchTab({
					url: '/pages/index/index'
				});
			},
			close: function() {
				this.$set(this, 'active', false);
			},
			onLoadFun: function(e) {
				let that = this;
				if (that.bargainUid == 'undefined' || !that.bargainUid) {
					that.bargainUid = that.$store.state.app.uid;
				}
				this.getBargainDetails();
				this.addShareBargain();
				this.isShowAuth = false;
			},
			addShareBargain: function() {
				//添加分享次数 获取人数
				var that = this;
				postBargainShare(this.id).then(res => {
					that.$set(that, 'bargainCount', res.data);
				});
			},
			//#ifdef H5
			setOpenShare() {
				let that = this;
				let configTimeline = {
					title: '您的好友' + that.userInfo.nickname + '邀请您砍价' + that.bargainInfo.title,
					desc: that.bargainInfo.info,
					link: window.location.protocol + '//' + window.location.host +
						'/pages/activity/goods_bargain_details/index?id=' +
						that.id + '&spid=' + that.bargainUid,
					imgUrl: that.bargainInfo.image
				};
				if (this.$wechat.isWeixin()) {
					this.$wechat
						.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData', 'onMenuShareAppMessage',
							'onMenuShareTimeline'
						], configTimeline)
						.then(res => {})
						.catch(res => {
							if (res.is_ready) {
								res.wx.updateAppMessageShareData(configTimeline);
								res.wx.updateTimelineShareData(configTimeline);
								res.wx.onMenuShareAppMessage(configTimeline);
								res.wx.onMenuShareTimeline(configTimeline);
							}
						});
				}
			},
			closeFollowCode: function() {
				this.$set(this, 'followCode', false);
			},

			//#endif
			closeDrawer(){
				this.showDrawer = false;
			},
			goRecord(){
				uni.navigateTo({
					url: '/pages/activity/bargain/index'
				})
			},
			//替换安全域名
			setDomain: function(url) {
				url = url ? url.toString() : '';
				//本地调试打开,生产请注销
				if (url.indexOf('https://') > -1) return url;
				else return url.replace('http://', 'https://');
			},
			//图片转符合安全域名路径
			downloadFilestoreImage(url) {
				url = this.setDomain(url)
				return new Promise((resolve, reject) => {
					let that = this;
					uni.downloadFile({
						url: url,
						success: function(res) {
							resolve(res.tempFilePath);
						},
						fail: function() {
							return that.$util.Tips({
								title: ''
							});
						}
					});
				})
			},
			async getPosterInfo() {
				// #ifdef H5 || APP-PLUS
				this.from = 'wechat'
				// #endif
				// #ifdef MP
				this.from = 'routine'
				// #endif
				let userData = this.$store.state.app.userInfo;
				if (typeof userData === 'string') {
					userData = JSON.parse(userData);
				}
				this.canvasStatus = true;
				
				getBargainPosterData(this.id).then(async ({ data }) => {
					const resData = data;
					const posterBag = await this.downloadFilestoreImage(`${this.imgHost}/statics/images/activity/posterBag2.png`);
					const posterBagInner = await this.downloadFilestoreImage(`${this.imgHost}/statics/images/activity/posterBagInner.png`);
					const postertag = await this.downloadFilestoreImage(`${this.imgHost}/statics/images/activity/postertag2.png`);
					const rmbtag = await this.downloadFilestoreImage(`${this.imgHost}/statics/images/activity/rmb.png`);
					const goods_img = await this.downloadFilestoreImage(resData.image);
					const avatar = await this.downloadFilestoreImage(userData.avatar);
					
					let mp_code;
					// #ifdef H5 || APP-PLUS
					// 公众号会返回url,h5环境没有,微信公众号跟h5的二维码需要区分
					mp_code = resData.url ? resData.url : this.codeSrc;
					// #endif
					// #ifdef MP
					mp_code = await this.downloadFilestoreImage(resData.url);
					// #endif
					const arr = [posterBag, goods_img, mp_code, posterBagInner, postertag, avatar, rmbtag];
					
					this.$nextTick(() => {
						this.$util.bargainPosterCanvas(arr, resData.title, resData.label, resData.msg, resData.price, userData, '砍价',
							(tempFilePath) => {
								this.posterImage = tempFilePath;
								this.posterImageStatus = true;
								this.canvasStatus = false;
							});
					});
				}).catch(err => {
					this.$util.Tips({
						title: err
					},'/pages/activity/goods_bargain/index');
				})
			},
			// 图片预览；
			getpreviewImage() {
				if (this.posterImage) {
					let photoList = [];
					photoList.push(this.posterImage)
					uni.previewImage({
						urls: photoList,
						current: this.posterImage
					});
				} else {
					this.$util.Tips({
						title: '您的海报尚未生成'
					});
				}
			},
			//隐藏海报
			posterImageClose() {
				this.posterImageStatus = false;
				this.posters = false;
			},
			// #ifndef H5
			savePosterPath() {
				let that = this;
				uni.getSetting({
					success(res) {
						if (!res.authSetting['scope.writePhotosAlbum']) {
							uni.authorize({
								scope: 'scope.writePhotosAlbum',
								success() {
									uni.saveImageToPhotosAlbum({
										filePath: that.posterImage,
										success: function(res) {
											that.posterImageClose();
											that.$util.Tips({
												title: '保存成功',
												icon: 'success'
											});
										},
										fail: function(res) {
											that.$util.Tips({
												title: '保存失败'
											});
										}
									});
								}
							});
						} else {
							uni.saveImageToPhotosAlbum({
								filePath: that.posterImage,
								success: function(res) {
									that.posterImageClose();
									that.$util.Tips({
										title: '保存成功',
										icon: 'success'
									});
								},
								fail: function(res) {
									that.$util.Tips({
										title: '保存失败'
									});
								}
							});
						}
					}
				});
			},
			// #endif
		},

		/**
		 * 生命周期函数--监听页面初次渲染完成
		 */
		onReady: function() {
			this.isNodes++;
		},
		/**
		 * 生命周期函数--监听页面显示
		 */
		onShow(){
			uni.removeStorageSync('form_type_cart');
		},
		/**
		 * 生命周期函数--监听页面隐藏
		 */
		onHide: function() {
			if (this.interval !== null) clearInterval(this.interval);
		},

		/**
		 * 生命周期函数--监听页面卸载
		 */
		onUnload: function() {
			if (this.interval !== null) clearInterval(this.interval);
		},

		//#ifdef MP
		/**
		 * 用户点击右上角分享
		 */
		onShareAppMessage: function() {
			let that = this,
				share = {
					title: '您的好友' + that.userInfo.nickname + '邀请您帮他砍' + that.bargainInfo.title + ' 快去帮忙吧！',
					path: '/pages/activity/goods_bargain_details/index?id=' + this.id + '&spid=' + this.bargainUid,
					imageUrl: that.bargainInfo.image
				};
			that.close();
			that.addShareBargain();
			return share;
		}
		//#endif
	};
</script>

<style lang="scss">
	.bargainTitle{
		position: fixed;
		left:0;
		top:0;
		width: 100%;
		z-index: 99;
		.sysTitle{
			width: 100%;
			position: relative;
			.iconfont{
				position: absolute;
				font-size: 36rpx;
				left:11rpx;
				width: 80rpx;
				color:#fff;
			}
		}
	}
	.bargainCon{
		// background-color: var(--view-theme);
		padding-bottom: 50rpx;
		min-height: 100vh;
	}
	.rule-btn{
		height: 48rpx;
		background: rgba(0,0,0,0.3);
		border-radius: 24rpx 0 0 24rpx;
		position: fixed;
		right: 0;
		z-index: 9;
	}

	.bargain .bargainGang .open {
		font-size: 24rpx;
		color: #999;
		margin-top: 30rpx;
	}

	.bargain .bargainGang .open .iconfont {
		font-size: 25rpx;
		margin: 5rpx 0 0 10rpx;
	}

	.bargain .header {
		background-repeat: no-repeat;
		background-size: 100% 100%;
		width: 100%;
		height: 1000rpx;
		margin: 0 auto;
		padding-top: 55rpx;
		box-sizing: border-box;
		
		.iconfont{
			color: #fff;
			font-size: 40rpx;
			margin-left: 34rpx;
		}
	}

	.bargain .header .people {
		text-align: center;
		color: #fff;
		font-size: 20rpx;
		position: absolute;
		width: 100%;
		/* #ifdef MP || APP-PLUS */
		height: 44px;
		line-height: 44px;
		top: 40rpx;
		/* #endif */
		/* #ifdef H5 */
		top: 58rpx;
		/* #endif */
	}
	
	.bargain .wrapperCon{
		width: 710rpx;
		margin: -624rpx auto 0 auto;
	} 

	.bargain .wrapper,
	.bargain .bargainGang,
	.bargain .goodsDetails {
		width: 100%;
		background-color: #fff;
		border-radius: 0 0 24rpx 24rpx;
		padding: 17rpx 20rpx 0 20rpx;
		padding-bottom: 50rpx;
	}
	
	.bargain .bargainGang .tips{
		font-size: 24rpx;
		font-weight: 400;
		color: #333333;
		text-align: center;
		margin-top: 30rpx;
		.num{
			color: #E93323;
			font-weight: 500;
			padding: 0 8rpx;
		}
	}
	
	.bargain .wrapperCon .timeCon{
		background-repeat: no-repeat;
		background-size: 100% 100%;
		width: 100%;
		height: 116rpx;
		/deep/.time{
			padding-top: 14px;
			.styleAll{
				background-color: #E93323;
				color: #fff;
				display: inline-block;
				min-width: 34rpx;
				height: 36rpx;
				border-radius: 8rpx;
				text-align: center;
				line-height: 36rpx;
				font-size: 28rpx;
				font-family:'SemiBold';
				padding: 0 2rpx;
				.dayTxt{
					font-size: 12px;
				}
			}
			.title{
				color: #333 !important;
			}
			.red{
				color: #E93323;
				padding: 0 6rpx;
			}
		}
		.pictxt{
			height: 96rpx;
			.pictrue{
				width: 60rpx;
				height: 60rpx;
				border-radius: 50%;
				
				image{
					width: 100%;
					height: 100%;
					border-radius: 50%;
				}
			}
			.text{
				font-size: 28rpx;
				color: #333;
				font-weight: 400;
				margin-left: 18rpx;
				.name{
					font-weight: 600;
					color: #E93323;
					margin-right: 10rpx;
				}
			}
		}
		.endTimes{
			font-size: 28rpx;
			color: #333;
			font-weight: 400;
			text-align: center;
			height: 96rpx;
			line-height: 96rpx;
		}
	}

	.bargain .wrapper .pictxt {
		// margin: 26rpx 0 37rpx 0;
	}

	.bargain .wrapper .pictxt .pictrue {
		width: 240rpx;
		height: 240rpx;
		position: relative;
	}

	.bargain .wrapper .pictxt .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 16rpx;
	}

	.bargain .wrapper .pictxt .text {
		width: 400rpx;
		font-size: 28rpx;
		color: #333;
		.tips{
			font-weight: 400;
			color: #999999;
			font-size: 22rpx;
			margin-top: 12rpx;
			.num{
				color: #FE960F;
				margin: 0 6rpx;
			}
		}
		.top{
			height: 188rpx;
		}
	}
	
	.bargain .wrapper .pictxt .text .name{
		width: 100%;
		height: 74rpx;
		visibility: visible;
	}
	
	.bargain .wrapper .pictxt .text .labelN{
		font-size: 20rpx;
		color: var(--view-theme);
		font-weight: 500;
		border:1px solid var(--view-theme);
		border-radius: 6rpx;
		padding: 2rpx 4rpx;
		margin-right: 10rpx;
		display: inline-block;
		vertical-align: text-top;
	}

	.bargain .wrapper .pictxt .text .successNum {
		font-size: 22rpx;
		color: #999;
	}

	.bargain .wrapper .cu-progress {
		// overflow: hidden;
		height: 20rpx;
		background-color: rgba(233, 51, 35, 0.08);
		width: 100%;
		border-radius: 20rpx;
		margin-top: 60rpx;
		&.on{
			background-color:#F5F5F5;
		}
	}

	.bargain .wrapper .cu-progress .bg-red {
		width: 0;
		height: 100%;
		transition: width 0.6s ease;
		border-radius: 20rpx;
		background-color: #E93323;
		position: relative;
		.img{
			width: 88rpx;
			height: 88rpx;
			position: absolute;
			right: -30rpx;
			top:50%;
			margin-top: -38rpx;
			&.on{
				right: -62rpx;
			}
		}
		.triangle{
			width: 0;
			height: 0;
			border-left: 15rpx solid transparent;  
			border-right: 15rpx solid transparent;  
			border-bottom: 15rpx solid #E93323;
			position: absolute;
			right: 0;
			top: 30rpx;
		}
		.hacked{
			position: absolute;
			height: 41rpx;
			line-height: 43rpx;
			padding: 0 6rpx;
			background: #E93323;
			color: #fff;
			font-size: 22rpx;
			right: -50rpx;
			top:42rpx;
			border-radius: 10rpx;
			font-weight: 400;
		}
	}

	.bargain .wrapper .money {
		font-size: 22rpx;
		color: #999;
		margin-top: 16rpx;
		.num{
			font-weight: 400;
			color: #E93323;
			margin-left: 4rpx;
		}
	}

	.bargain .wrapper .bargainSuccess {
		font-size: 32rpx;
		color: #333;
		text-align: center;
		margin-top: 52rpx;
		&.on{
			color: #E93323;
		}
	}

	.bargain .wrapper .bargainSuccess .iconfont {
		font-size: 34rpx;
		color: #00b42a;
		padding-right: 8rpx;
		vertical-align: -3rpx;
	}
	
	.bargain .wrapper .seeGood{
		font-weight: 400;
		color: #E93323;
		font-size: 30rpx;
		text-align: center;
		margin-top: 32rpx;
	}

	.bargain .wrapper .bargainBnt {
		font-size: 28rpx;
		font-weight: 500;
		color: #fff;
		width: 670rpx;
		height: 88rpx;
		border-radius: 50rpx;
		background: linear-gradient(90deg, #E93323 0%, #FF7931 100%);
		text-align: center;
		line-height: 88rpx;
		margin-top: 42rpx;
	}

	.bargain .wrapper .bargainBnt.on {
		color: #E93323;
		background: linear-gradient(90deg, #FCEAE9 0%, #FCEAE9 100%);
	}

	.bargain .wrapper .bargainBnt.grey {
		color: #fff;
		background-image: linear-gradient(to right, #ccc 0%, #ccc 100%);
	}

	.bargain .wrapper .tip {
		font-size: 22rpx;
		color: #999;
		text-align: center;
		margin-top: 22rpx;
		font-weight: 400;
	}

	.bargain .wrapper .tip .num {
		color: #E93323;
		margin: 0 8rpx;
	}

	.bargain .bargainGang,.bargain .goodsDetails {
		margin: 20rpx auto 0 auto;
		width: 710rpx;
		padding: 0 0 34rpx 0;
		border-radius: 22rpx;
	}

	.bargain .bargainGang .title,
	.bargain .goodsDetails .title {
		font-size: 32rpx;
		font-weight: bold;
		height: 80rpx;
		background-repeat: no-repeat;
		background-size: 100% 100%;
		width: 100%;
		height: 86rpx;
		color: #E93323;
		font-size: 36rpx;
	}

	.bargain .bargainGang .title .pictrue,
	.bargain .goodsDetails .title .pictrue {
		width: 60rpx;
		height: 4rpx;
		background: linear-gradient(270deg, #E93323 0%, rgba(255,255,255,0) 100%);
	}

	.bargain .bargainGang .title .pictrue.on,
	.bargain .goodsDetails .title .pictrue.on {
		transform: rotate(180deg);
	}

	.bargain .bargainGang .title .titleCon,
	.bargain .goodsDetails .title .titleCon {
		margin: 0 28rpx;
	}
	
	.bargain .bargainGang .list{
		margin-top: 10rpx;
		padding: 0 20rpx;
	}

	.bargain .bargainGang .list .item {
		height: 140rpx;
	}

	.bargain .bargainGang .list .item .pictxt {
		width: 316rpx;
	}

	.bargain .bargainGang .list .item .pictxt .pictrue {
		width: 80rpx;
		height: 80rpx;
	}

	.bargain .bargainGang .list .item .pictxt .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 50%;
	}

	.bargain .bargainGang .list .item .pictxt .text {
		width: 225rpx;
		font-size: 22rpx;
		color: #999;
	}

	.bargain .bargainGang .list .item .pictxt .text .name {
		font-size: 30rpx;
		color: #333;
		margin-bottom: 4rpx;
		font-weight: 400;
	}

	.bargain .bargainGang .list .item .money {
		font-size: 22rpx;
		color: #999;
		
		.label{
			font-size: 28rpx;
			color: #E93323;
			font-weight: 600;
			margin-left: 10rpx;
		}
		
		.num{
			font-size: 44rpx;
			color: #E93323;
			font-family: 'SemiBold';
		}
	}

	.bargain .bargainGang .load {
		font-size: 24rpx;
		text-align: center;
		line-height: 80rpx;
		height: 80rpx;
		color: var(--view-theme);
	}

	.bargain .goodsDetails .conter {
		overflow: hidden;
		word-break: break-all;
	}

	.bargain .goodsDetails .conter image {
		width: 100% !important;
		display: block !important;
	}

	.bargain .bargainTip {
		position: fixed;
		top: 50%;
		left: 50%;
		width: 480rpx;
		margin-left: -240rpx;
		z-index: 111;
		border-radius: 48rpx;
		background-color: #fff;
		transition: all 0.3s ease-in-out 0s;
		opacity: 0;
		transform: scale(0);
		padding-bottom: 60rpx;
		margin-top: -330rpx;
	}

	.bargain .bargainTip.on {
		opacity: 1;
		transform: scale(1);
	}

	.bargain .bargainTip .pictrue {
		width: 100%;
		height: 266rpx;
	}

	.bargain .bargainTip .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 20rpx 20rpx 0 0;
	}

	.bargain .bargainTip .cutOff {
		font-size: 24rpx;
		color: #333;
		padding: 0 29rpx;
		text-align: center;
		margin-top: 30rpx;
		
		.title{
			font-weight: 500;
			font-size: 32rpx;
			margin-bottom: 12rpx;
			.num{
				color: #FF7D00;
			}
		}
	}

	.bargain .bargainTip .tipBnt {
		font-size: 26rpx;
		color: #fff;
		width: 360rpx;
		height: 72rpx;
		border-radius: 36rpx;
		background: linear-gradient(90deg, #E93323 0%, #FF7931 100%);
		box-shadow: 0px 6px 16px 0px rgba(216,7,7,0.5);
		text-align: center;
		line-height: 72rpx;
		margin: 40rpx auto 0 auto;
		
		.iconfont{
			margin-right: 10rpx;
		}
		
		&.grey{
			background: linear-gradient(90deg, #ccc 0%, #ccc 100%);
			box-shadow: unset;
		}
	}

	.bargain_view {
		width: 100%;
		height: 48rpx;
		background: rgba(0, 0, 0, 0.5);
		opacity: 1;
		border-radius: 0 0 6rpx 6rpx;
		position: absolute;
		bottom: 0;
		font-size: 22rpx;
		color: #fff;
		text-align: center;
		line-height: 48rpx;
	}

	.iconfonts {
		font-size: 22rpx !important;
	}

	.wxParse-div {
		width: auto !important;
		height: auto !important;
	}

	.bargain .mask {
		z-index: 100;
	}

	.share-box {
		z-index: 1000;
		position: fixed;
		left: 0;
		top: 0;
		width: 100%;
		height: 100%;

		image {
			width: 100%;
			height: 100%;
		}
	}

	.followCode {
		.pictrue {
			width: 500rpx;
			height: 530rpx;
			border-radius: 12px;
			left: 50%;
			top: 50%;
			margin-left: -250rpx;
			margin-top: -360rpx;
			position: fixed;
			z-index: 10000;

			.code-bg {
				display: flex;
				justify-content: center;
				width: 100%;
				height: 100%;
				background-image: url('~@/static/images/code-bg.png');
				background-size: 100% 100%;
			}

			.imgs {
				width: 310rpx;
				height: 310rpx;
				margin-top: 92rpx;
			}
		}

		.mask {
			z-index: 9999;
		}
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
	.scroll-content{
		max-height: 800rpx;
		overflow-y: auto;
	}
	.bg-red{
		background-color: #E93323;
	}
	.poster-mask{
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background-color: rgba(0,0,0,0.8);
		z-index: 30;
		backdrop-filter: blur(5px);
	}
	.poster-pop {
		width: 560rpx;
		height: 850rpx;
		position: fixed;
		left: 50%;
		transform: translateX(-50%);
		z-index: 399;
		top: 50%;
		margin-top: -559rpx;
	}
	.poster-pop image {
		width: 100%;
		height: 100%;
		display: block;
		border-radius: 18rpx;
	}
	
	.poster-pop .close {
		width: 46rpx;
		height: 75rpx;
		position: fixed;
		right: 0;
		top: -73rpx;
		display: block;
	}
	
	.poster-pop .save-poster {
		background-color: #df2d0a;
		font-size: ：22rpx;
		color: #fff;
		text-align: center;
		height: 76rpx;
		line-height: 76rpx;
		width: 100%;
	}
	
	.poster-pop .keep {
		color: #fff;
		text-align: center;
		font-size: 25rpx;
		margin-top: 10rpx;
	}
	.canvas {
		z-index: 300;
		width: 750px;
		height: 1140px;
		position: relative;
		bottom: -10000rpx;
	}
	.generate-posters {
		width: 100%;
		background-color: #fff;
		position: fixed;
		left: 0;
		bottom: 0;
		z-index: 388;
		transform: translate3d(0, 100%, 0);
		transition: all 0.3s cubic-bezier(0.25, 0.5, 0.5, 0.9);
		border-top: 1rpx solid #eee;
		border-radius: 40rpx 40rpx 0 0;
	
		.generateCon {
			height: 220rpx;
		}
	
		.generateClose {
			width: 654rpx;
			height: 72rpx;
			background: #F5F5F5;
			border-radius: 36rpx;
			font-size: 26rpx;
			font-weight: 500;
			margin: 0 auto 40rpx;
		}
	
		.item {
			.pictrue {
				width: 86rpx;
				height: 86rpx;
				border-radius: 50%;
				margin: 0 auto 12rpx auto;
	
				image {
					width: 100%;
					height: 100%;
					border-radius: 50%;
				}
			}
		}
	}
	.generate-posters.on {
		transform: translate3d(0, 0, 0);
	}
	
	.generate-posters .item {
		flex: 1;
		text-align: center;
		font-size: 24rpx;
	}
	.fixed-center{
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		z-index: 40;
	}
	.poster-loading{
		position: relative;
		width: 100%;
		z-index: 31;
	}
</style>
