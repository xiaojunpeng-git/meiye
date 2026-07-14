<template>
	<!-- 首页 -->
	<view v-if="pageShow" class="page"
		:class="bgTabVal==2?'fullsize noRepeat':bgTabVal==1?'repeat ysize':'noRepeat ysize'"
		:style="[pageStyle]">
		<view v-if="!m854f8" :style="colorStyle">
			<!-- #ifdef MP -->
			<view class="fixed z-1000" :style="[appletStyle]" v-if="myApplet">
				<view class="m36f2e w-324 h-62 text-center rd-12rpx lh-62rpx fs-24 bg--w111-fff text-w111-303133">点击添加到我的小程序<text class="iconfont icon-ic_close2 text--w111-ccc ml-16" @click="myApplet = false"></text></view>
			</view>
			<!-- #endif -->
			<!-- 轮播搜索 -->
			<homeComb v-if="showHomeComb" :dataConfig="homeCombData" :storeInfor='storeInfor' :belongIndex='belongIndex' @bindSortId="bindSortId" :isScrolled="isScrolled" @locationTap='locationTap' @storeTap="storeTap"></homeComb>
			<!-- 顶部搜索框 -->
			<headerSerch v-if="isHeaderSerch" :dataConfig="headerSerchCombData" :storeInfor='storeInfor' :belongIndex='belongIndex' @locationTap='locationTap' @storeTap="storeTap"></headerSerch>
			<tabNav v-if="showCateNav" :dataConfig="cateNavData" @bindHeight="bindHeighta"
				@bindSortId="bindSortId" :isFixed="isFixed && !cateNavData.stickyConfig.tabVal"></tabNav>
			<view class="index">
				<!-- 自定义样式 -->
				<block v-for="(item, index) in styleConfig" :key="index">
					<storeList v-if="item.name == 'storeList'" ref="storeLists" :dataConfig="item"></storeList>
					<userInfor v-if="item.name == 'userInfor'" :dataConfig="item"
						@changeLogin="changeLogin">
					</userInfor>
					<newVip v-if="item.name == 'newVip'" :dataConfig="item"></newVip>
					<!-- 文章列表 -->
					<articleList v-if="item.name == 'articleList'" :dataConfig="item">
					</articleList>
					<bargain v-if="item.name == 'bargain'" :dataConfig="item" @changeBarg="changeBarg"
						></bargain>
					<blankPage v-if="item.name == 'blankPage'" :dataConfig="item"></blankPage>
					<combination v-if="item.name == 'combination'" :dataConfig="item">
					</combination>
					<!-- 优惠券 -->
					<coupon v-if="item.name == 'coupon'" :dataConfig="item"
						@changeLogin="changeLogin"></coupon>
					<!-- 客户服务 -->
					<customerService v-if="item.name == 'customerService'" :dataConfig="item">
					</customerService>
					<!-- 商品列表 -->
					<goodList ref="goodLists" v-if="item.name == 'goodList'" :dataConfig="item"></goodList>
					<guide v-if="item.name == 'guide'" :dataConfig="item"></guide>
					<!-- 直播模块 -->
					<!-- #ifdef  MP-WEIXIN -->
					<liveBroadcast v-if="item.name == 'liveBroadcast'" :dataConfig="item"></liveBroadcast>
					<!-- #endif -->
					<menus v-if="item.name == 'menus'" :dataConfig="item"></menus>
					<!-- 实时消息 -->
					<news v-if="item.name == 'news'" :dataConfig="item"></news>
					<!-- 图片库 -->
					<pictureCube v-if="item.name == 'pictureCube'" :dataConfig="item"></pictureCube>
					<!-- 促销列表 -->
					<promotionList ref="promotionLists" v-if="item.name == 'promotionList'" :dataConfig="item"
					:productVideoStatus='product_video_status' :positionTop="positionTop">
					</promotionList>
					<seckill v-if="item.name == 'seckill'" :dataConfig="item"></seckill>
					<!-- 轮播图-->
					<swiperBg v-if="item.name == 'swiperBg'" :dataConfig="item"></swiperBg>
					<swipers v-if="item.name == 'swipers'" :dataConfig="item"></swipers>
					<!-- 顶部选项卡 -->

					<!-- 标题 -->
					<titles v-if="item.name == 'titles'" :dataConfig="item"></titles>
					<ranking v-if="item.name == 'ranking'" :dataConfig="item"></ranking>
					<presale v-if="item.name == 'presale'" :dataConfig="item"></presale>
					<pointsMall v-if="item.name == 'pointsMall'" :dataConfig="item"></pointsMall>
					<!-- #ifndef APP -->
					<richText v-if="item.name == 'richText'" :dataConfig="item"></richText>
					<videos v-if="item.name == 'videos'" :dataConfig="item"></videos>
					<!-- #endif -->
					<signIn v-if="item.name == 'signIn'" :dataConfig="item"></signIn>
					<hotspot v-if="item.name == 'hotspot'" :dataConfig="item"></hotspot>
					<follow v-if="item.name == 'follow'" :dataConfig="item" @hideFollow="hideFollow"></follow>
					<community v-if="item.name == 'community'" :dataConfig="item"></community>
				</block>
				<!-- 分类商品模块 -->
				<!-- #ifndef  APP-PLUS -->
				<view class="sort-product px-20" v-if="!styleConfig.length">
				<!-- #endif -->
				<!-- #ifdef  APP-PLUS -->
				<!-- 商品排序 -->
				<view class="sort-product px-20" :style="{ marginTop: sortMpTop + 'px' }" v-if="!styleConfig.length">
				<!-- #endif -->
					<view class="rd-24rpx bg--w111-fff p-24 mb-24" v-if="sortList.children && sortList.children.length">
						<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-full" show-scrollbar="false">
							<view class="inline-block mr-24" v-for="(item, index) in sortList.children" :key="index">
								<view class="flex-col flex-center" @tap="changeSort(item, index)">
									<view class="picture w-90 h-90 rd-50-p111-"
										:class="{select: curSort == index}">
										<image :src="item.pic" class="w-full h-full rd-50-p111-"></image>
									</view>
									<text class="fs-24 pt-14" :class="{'font-num': curSort == index}">{{item.cate_name}}</text>
								</view>
							</view>
						</scroll-view>
					</view>
					<waterfallsFlow ref="waterfallsFlow" :wfList="goodList" :goDetail="'goDetail'" @itemTap="goDetail"></waterfallsFlow>
					<Loading :loaded="loaded" :loading="loading"></Loading>
					<view v-if="goodList.length == 0 && loaded">
						<emptyPage title="暂无商品，去看点别的吧～" ></emptyPage>
					</view>
				</view>
				<couponWindow :window="isCouponShow" @onColse="couponClose" :couponImage="couponObj.image"
					:couponList="couponObj.list"></couponWindow>
					<!-- #ifdef H5 -->
				<view v-if="site_config" class="site-config" @click="goICP">{{ site_config }}</view>
				<!-- #endif -->
				<view class="pb-safe" :style="[pdHeights]" v-if="isFooter"></view>
				<pageFooter  @newDataStatus="newDataStatus"></pageFooter>
			</view>
		</view>
		<view v-else>
			<view class="error-network">
				<image :src="imgHost + '/statics/images/error-network.gif'"></image>
				<view class="title">网络连接断开</view>
				<view class="con">
					<view class="label">请检查情况：</view>
					<view class="item">· 在设置中是否已开启网络权限</view>
					<view class="item">· 当前是否处于弱网环境</view>
					<view class="item">· 版本是否过低，升级试试吧</view>
				</view>
				<view class="btn" @click="reconnect">重新连接</view>
			</view>
		</view>
	</view>
</template>

<script>
	const app = getApp();
	import colors from "@/mixins/color";
	import couponWindow from '@/components/couponWindow/index';
	import permision from "@/js_sdk/wa-permission/permission.js";
	import {
		getCouponV2,
		getCouponNewUser,
		copyRight,
		colorChange
	} from '@/api/api.js';
	import {
		getShare
	} from '@/api/public.js';
	// #ifdef H5
	import {
		silenceAuth
	} from '@/api/public.js';
	// #endif
	import userInfor from './components/userInfor';
	import homeComb from './components/homeComb';
	import newVip from './components/newVip';
	import community from './components/community';
	import storeList from './components/storeList';
	import headerSerch from './components/headerSerch';
	import swipers from './components/swipers';
	import coupon from './components/coupon';
	import articleList from './components/articleList';
	import bargain from './components/bargain';
	import blankPage from './components/blankPage';
	import combination from './components/combination';
	import customerService from './components/customerService';
	import goodList from './components/goodList';
	import guide from './components/guide';
	import liveBroadcast from './components/liveBroadcast';
	import menus from './components/menus';
	import news from './components/news';
	import pictureCube from './components/pictureCube';
	import promotionList from './components/promotionList';
	import seckill from './components/seckill';
	import swiperBg from './components/swiperBg';
	import tabNav from './components/tabNav';
	import titles from './components/titles';
	import ranking from './components/ranking';
	import presale from './components/presale'
	import pointsMall from './components/pointsMall';
	// #ifndef APP
	import richText from './components/richText';
	import videos from './components/videos';
	// #endif
	import signIn from './components/signIn';
	import hotspot from './components/hotspot';
	import follow from './components/follow';
	import waterfallsFlow from "@/components/WaterfallsFlow/WaterfallsFlow.vue";
	import emptyPage from '@/components/emptyPage.vue';
	// #ifdef MP
	import {getTemlIds} from '@/api/api.js';
	import {
		SUBSCRIBE_MESSAGE,
		TIPS_KEY
	} from '@/config/cache';
	// #endif
	import {mapGetters,mapMutations} from 'vuex';
	import {getDiy, getDiyVersion, getEntryStore} from '@/api/api.js';
	import {getCartCounts} from '@/api/order.js';
	import {
		getCategoryList,
		getProductslist,
		getProductHot,
		diyProductApi
	} from '@/api/store.js';
	import { getnearbyStore } from '@/api/new_store.js';
	import { goShopDetail } from '@/libs/order.js';
	import { toLogin } from '@/libs/login.js';
	import { HTTP_REQUEST_URL } from '@/config/app';
	import pageFooter from '@/components/pageFooter/index.vue';
	import Loading from '@/components/Loading/index.vue';
	import { silenceBindingSpread } from '@/utils/index.js';
	export default {
		computed: {
			// #ifdef MP
			appletStyle(){
				return {
					top:this.getHeight.menuButtonInfo.bottom+8+'px',
					right:'10px'
				}
			},
			// #endif
			pageStyle(){
				return {
					backgroundColor: this.bgColor,
					backgroundImage: this.bgPic ? `url(${this.bgPic})` : '',
					minHeight: this.windowHeight + 'px'
				}
			},
			pdHeights(){
				let H = `${this.pdHeight*2 + 100}rpx`
				return{
					height: this.isFooter?H:'100rpx'
				}
			},
			...mapGetters(['isLogin', 'uid', 'cartNum']),
		},
		mixins: [colors],
		components: {
			Loading,
			pageFooter,
			couponWindow,
			homeComb,
			newVip,
			userInfor,
			community,
			storeList,
			headerSerch,
			swipers,
			coupon,
			articleList,
			bargain,
			blankPage,
			combination,
			customerService,
			goodList,
			guide,
			liveBroadcast,
			menus,
			pictureCube,
			news,
			promotionList,
			seckill,
			swiperBg,
			tabNav,
			titles,
			ranking,
			presale,
			pointsMall,
			// #ifndef APP
			richText,
			videos,
			// #endif
			signIn,
			hotspot,
			follow,
			waterfallsFlow,
			emptyPage
		},
		data() {
			return {
				styleConfig: [],
				loading: false,
				loadend: false,
				loadTitle: '下拉加载更多', //提示语
				page: 1,
				limit: this.$config.LIMIT,
				numConfig: 0,
				code: '',
				isCouponShow: false,
				couponObj: {},
				couponObjs: {
					show: false
				},
				shareInfo: {},
				sortList: '',
				sortAll: [],
				goodPage: 1,
				goodList: [],
				sid: 0,
				curSort: 0,
				sortMpTop: 0,
				loaded: false,
				loading: false,
				domOffsetTop: 50,
				// #ifdef APP-PLUS || MP
				isFixed: true,
				// #endif
				// #ifdef H5
				isFixed: false,
				// #endif
				site_config: '',
				m854f8: false, // 是否断网
				isHeaderSerch: false,
				showHomeComb: false,
				showCateNav: false,
				homeCombData:{},
				headerSerchCombData:{},
				cateNavData:{},
				bgColor: '',
				bgPic: '',
				bgTabVal: '',
				pageShow: true,
				windowHeight: 0,
				imgHost: HTTP_REQUEST_URL,
				isShowAuth: false,
				isScrolled: false,
				product_video_status: false,
				confirm_video_status: false,
				positionTop: 0,
				isFooter: false,
				pdHeight:0, //自定义底部导航上下边距和
				entryData:{
					store_id:'',
					latitude:'',
					longitude:'',
					select_store_id:''
				},
				storeInfor:{
					storeId:0,
					storeName:''
				},
				goodsIndex: [],
				promotionIndex: [],
				storeIndex: [],
				belongIndex:0, // 进店规则归属门店排序位置；
				isBelongStore: false, //判断是否为归属门店；
				getHeight: this.$util.getWXStatusHeight(),
				myApplet: true
			};
		},
		onLoad(options) {
			let that = this;
			uni.hideTabBar();
			that.getOptions(options);
			this.$nextTick(function() {
				uni.getSystemInfo({
					success: function(res) {
						that.windowHeight = res.windowHeight;
					}
				});
			})
			const {state, scope} = options;
			this.diyData();
			// #ifdef H5
			this.setOpenShare();
			// #endif
			// #ifdef MP
			this.getTemlIds();
			// #endif
			getShare().then(res => {
				this.shareInfo = res.data;
			});
			this.getCopyRight();
			this.$eventHub.$on('confirm_video_status', () => {
				if (this.confirm_video_status) {
					return;
				}
				this.confirm_video_status = true;
				let flag = true;
				// #ifdef H5
				flag = window.self == window.top;
				// #endif
				if (!flag) {
					return;
				}
				uni.showModal({
					content: '当前使用移动网络，是否继续播放视频？',
					success: (res) => {
						if (res.confirm) {
							// 监听
							this.SET_AUTOPLAY(true);
							this.$eventHub.$emit('product_video_observe');
						}
					}
				});
			});
		},
		onUnload(){
			// 清除监听
			uni.$off('activeFn');
		},
		watch: {
			isLogin: {
				deep: true, //深度监听设置为 true
				handler: function(newV, oldV) {
					// 优惠券弹窗
					var newDates = new Date().toLocaleDateString();
					if (newV) {
						try {
							var oldDate = uni.getStorageSync('oldDate') || '';
						} catch {}
						if (oldDate != newDates) {
							this.getCoupon();
						}
					}
				}
			}
		},
		onShow() {
			const enterOptions = uni.getEnterOptionsSync();
			const query = enterOptions.query;
			let spid = 0;
			if (query.spid) {
				spid = query.spid;
			}
			// #ifdef MP
			if (query.scene) {
				const scene = decodeURIComponent(query.scene);
				const params = this.$util.getUrlParams(scene);
				if (params.spid) {
					spid = params.spid;
				}
			}
			// #endif
			if (spid) {
				this.$Cache.set('spid', spid);
				app.globalData.spid = spid;
				silenceBindingSpread(app);
			}
			uni.removeStorageSync('form_type_cart');
			// 优惠券弹窗
			if (this.isLogin) {
				this.getCoupon();
				this.getCartNum();
			}
			// #ifdef MP
			if (wx.canIUse('checkIsAddedToMyMiniProgram')) {
			    this.checkMyApplet();
			} else {
				this.myApplet = true;
			}
			// #endif
		},
		onReady() {},
		onPullDownRefresh() {
			this.diyData();
			uni.stopPullDownRefresh()
		},
		methods: {
			...mapMutations(['SET_AUTOPLAY','SET_NEARBY']),
			checkMyApplet(){
				wx.checkIsAddedToMyMiniProgram({
				    success: (res) => {
				        if (res.added) {
				            this.myApplet = false;
				        } else {
				            this.myApplet = true;
				        }
				    },
				    fail: () => {
				       this.myApplet = true;
				    }
				});
			},
			getCartNum: function() {
				getCartCounts().then(res => {
					this.$store.commit('indexData/setCartNum', res.data.resultAll.count + '')
					let cartNum = res.data.resultAll.count;
					if (cartNum > 0) {
						uni.setTabBarBadge({
							index: 3,
							text: cartNum > 99 ? '99+' : cartNum + ''
						})
					} else {
						uni.hideTabBarRedDot({
							index: 3
						})
					}
				}).catch(err=>{
					return this.$util.Tips({
						title: err.msg
					});
				})
			},
			locationTap(val){
				this.entryData.latitude = val.latitude;
				this.entryData.longitude = val.longitude;
				this.entryStore(1);
			},
			storeTap(id){
				this.entryData.select_store_id = id;
				this.entryData.store_id = '';
				uni.removeStorageSync('rulesStoreId');
				this.entryStore(1);
			},
			getCopyRight() {
				copyRight().then(res => {
					let data = res.data;
					uni.setStorageSync('wechatStatus', data.wechat_status)
					// #ifndef APP-PLUS
					this.site_config = data.record_No;
					// #endif
					if (!data.copyrightContext && !data.copyrightImage) {
						data.copyrightImage = ''
					}
					uni.setStorageSync('copyNameInfo', data.copyrightContext);
					uni.setStorageSync('copyImageInfo', data.copyrightImage);
					// #ifdef MP
					uni.setStorageSync('MPSiteData', JSON.stringify({
						site_logo: data.site_logo,
						site_name: data.site_name
					}));
					// #endif
				}).catch(err => {
					return this.$util.Tips({
						title: err.msg
					});
				});
			},
			getOptions(options) {
				let that = this;
				// #ifdef MP
				if (options.scene) {
					let value = that.$util.getUrlParams(decodeURIComponent(options.scene));
					//记录推广人uid
					if (value.spid) app.globalData.spid = value.spid;
				}
				// #endif
				if (options.spid) app.globalData.spid = options.spid;
			},
			// 重新链接
			reconnect() {
				this.diyData();
				this.getcolorChange();
				getShare().then(res => {
					this.shareInfo = res.data;
				});
			},
			getcolorChange(){
				let green =
					'--view-theme: #42CA4D;--view-priceColor:#FF7600;--view-minorColor:rgba(108, 198, 94, 0.5);--view-minorColorT:rgba(66, 202, 77, 0.1);--view-bntColor:#FE960F;--view-gradient:#4DEA4D'
				let red =
					'--view-theme: #e93323;--view-priceColor:#e93323;--view-minorColor:rgba(233, 51, 35, 0.5);--view-minorColorT:rgba(233, 51, 35, 0.1);--view-bntColor:#FE960F;--view-gradient:#FF7931'
				let blue =
					'--view-theme: #1DB0FC;--view-priceColor:#FD502F;--view-minorColor:rgba(58, 139, 236, 0.5);--view-minorColorT:rgba(9, 139, 243, 0.1);--view-bntColor:#22CAFD;--view-gradient:#5ACBFF'
				let pink =
					'--view-theme: #FF448F;--view-priceColor:#FF448F;--view-minorColor:rgba(255, 68, 143, 0.5);--view-minorColorT:rgba(255, 68, 143, 0.1);--view-bntColor:#282828;--view-gradient:#FF67AD'
				let orange =
					'--view-theme: #FE5C2D;--view-priceColor:#FE5C2D;--view-minorColor:rgba(254, 92, 45, 0.5);--view-minorColorT:rgba(254, 92, 45, 0.1);--view-bntColor:#FDB000;--view-gradient:#FF9451'
				let gold =
				    '--view-theme: #E0A558;--view-priceColor:#DA8C18;--view-minorColor:rgba(224, 165, 88, 0.5);--view-minorColorT:rgba(224, 165, 88, 0.1);--view-bntColor:#1A1A1A;--view-gradient:#FFCD8C'
				colorChange('color_change').then(res => {
					let navigation = res.data.navigation; //判断悬浮导航是否显示
					let statusColor = res.data.status; //判断显示啥颜色
					uni.setStorageSync('navigation', navigation);
					uni.$emit('navOk', navigation);
					uni.setStorageSync('statusColor', statusColor);
					uni.$emit('colorOk', statusColor);
					switch (res.data.status) {
						case 1:
							uni.setStorageSync('viewColor', blue)
							uni.$emit('ok', blue)
							break;
						case 2:
							uni.setStorageSync('viewColor', green)
							uni.$emit('ok', green)
							break;
						case 3:
							uni.setStorageSync('viewColor', red)
							uni.$emit('ok', red)
							break;
						case 4:
							uni.setStorageSync('viewColor', pink)
							uni.$emit('ok', pink)
							break;
						case 5:
							uni.setStorageSync('viewColor', orange)
							uni.$emit('ok', orange)
							break;
						case 6:
							uni.setStorageSync('viewColor', gold)
							uni.$emit('ok', gold)
							break;
						default:
							uni.setStorageSync('viewColor', red)
							uni.$emit('ok', red)
							break
					}
				});
			},
			goICP() {
				// #ifdef H5
				window.open('http://beian.miit.gov.cn/');
				// #endif
				// #ifdef MP
				uni.navigateTo({
					url: `/pages/annex/web_view/index?url=https://beian.miit.gov.cn/`
				});
				// #endif
			},
			bindHeighta(data) {
				// #ifdef APP-PLUS
				this.sortMpTop = data.top + data.height;
				// #endif
			},
			bindHeight(data) {
				uni.hideLoading();
				this.domOffsetTop = data.top;
			},
			// 去商品详情
			goGoodsDetail(item) {
				goShopDetail(item, this.uid).catch(res => {
					uni.navigateTo({
						url: `/pages/goods_details/index?id=${item.id}`
					});
				});
			},
			// 分类点击
			changeSort(item, index) {
				if (this.curSort == index) return;
				this.curSort = index;
				this.sid = item.id;
				this.goodList = [];
				this.goodPage = 1;
				this.loaded = false;
				this.getGoodsList();
			},
			/**
			 * @param data {
				classPage: 0 分类id
				microPage: 0 微页面id
				type: 1   0 微页面 1 商品分类
			 }*/
			bindSortId(data) {
				this.styleConfig = [];
				if (data.type == 1) {
					this.getProductList(data.classPage);
				}else{
					this.sortList = [];
					this.getMicroPage(data.microPage, true);
				}
			},
			/**
			 * 获取DIY
			 * @param {number} id
			 * @param {boolean} type 区分是否是微页面
			 */
			getMicroPage(id, type) {
				let that = this;
				that.styleConfig = []
				uni.showLoading({
					title: '加载中...'
				});
				getDiy(id).then(res => {
					uni.hideLoading();
					let data = res.data;
					let diyArr = that.objToArr(res.data.value);
					diyArr = diyArr.filter(item => !item.isHide);
					diyArr.forEach((item,index) => {
					  if(['headerSerch','homeComb'].includes(item.name)){
						  diyArr.splice(index, 1);
						}
					});
					this.styleConfig = diyArr;
				}).catch(err => {
					return that.$util.Tips({
						title: err
					});
					uni.hideLoading();
				});
			},
			getProductList(data) {
				let tempObj = '';
				this.curSort = 0;
				this.loaded = false;
				if (this.sortAll.length > 0) {
					this.sortAll.forEach((el, index) => {
						if (el.id == data) {
							this.$set(this, 'sortList', el);
							this.sid = el.children.length ? el.children[0].id : '';
						}
					});
					this.goodList = [];
					this.goodPage = 1;
					this.$nextTick(() => {
						if (this.sortList != '') this.getGoodsList();
					});
				} else {
					getCategoryList().then(res => {
						this.sortAll = res.data;
						res.data.forEach((el, index) => {
							if (el.id == data) {
								this.sortList = el;
								this.sid = el.children.length ? el.children[0].id : '';
							}
						});
						this.goodList = [];
						this.goodPage = 1;

						this.$nextTick(() => {
							if (this.sortList != '') this.getGoodsList();
						});
					});
				}
			},
			// 商品列表
			getGoodsList() {
				if (this.loading || this.loaded) return;
				this.loading = true;
				getProductslist({
					sid: this.sid,
					keyword: '',
					priceOrder: '',
					salesOrder: '',
					news: 0,
					page: this.goodPage,
					limit: 10,
					cid: this.sortList.id
				}).then(res => {
					this.loading = false;
					this.loaded = res.data.length < 10;
					this.goodPage++;
					this.goodList = this.goodList.concat(res.data);
				});
			},
			// 新用户优惠券
			getNewCoupon() {
				const oldUser = uni.getStorageSync('oldUser') || 0;
				if (!oldUser) {
					getCouponNewUser().then(res => {
						const {
							data
						} = res;
						if (data.show) {
							if (data.list.length) {
								this.isCouponShow = true;
								this.couponObj = data;
								uni.setStorageSync('oldUser', 1);
							}
						} else {
							uni.setStorageSync('oldUser', 1);
						}
					});
				}
			},
			// 优惠券弹窗
			getCoupon() {
				const tagDate = uni.getStorageSync('tagDate') || '',
					nowDate = new Date().toLocaleDateString();
				if (tagDate === nowDate) {
					this.getNewCoupon();
				} else {
					getCouponV2().then(res => {
						const {
							data
						} = res;
						if (data.list.length) {
							this.isCouponShow = true;
							this.couponObj = data;
							uni.setStorageSync('tagDate', new Date().toLocaleDateString());
						} else {
							this.getNewCoupon();
						}
					});
				}
			},
			// 优惠券弹窗关闭
			couponClose() {
				this.isCouponShow = false;
				if (!uni.getStorageSync('oldUser')) {
					this.getNewCoupon();
				}
			},
			onLoadFun() {
				this.isShowAuth = false
			},
			// #ifdef H5
			// 获取url后面的参数
			getQueryString(name) {
				var reg = new RegExp('(^|&)' + name + '=([^&]*)(&|$)', 'i');
				var reg_rewrite = new RegExp('(^|/)' + name + '/([^/]*)(/|$)', 'i');
				var r = window.location.search.substr(1).match(reg);
				var q = window.location.pathname.substr(1).match(reg_rewrite);
				if (r != null) {
					return unescape(r[2]);
				} else if (q != null) {
					return unescape(q[2]);
				} else {
					return null;
				}
			},
			// #endif

			// #ifdef MP
			getTemlIds() {
				let messageTmplIds = wx.getStorageSync(SUBSCRIBE_MESSAGE);
				if (!messageTmplIds) {
					getTemlIds().then(res => {
						if (res.data) wx.setStorageSync(SUBSCRIBE_MESSAGE, JSON.stringify(res.data));
					});
				}
			},
			// #endif
			// 对象转数组
			objToArr(data) {
				let obj = Object.keys(data).sort();
				let m = obj.map(key => data[key]);
				return m;
			},
			setDiyData(data) {
				this.m854f8 = false
				if (data.is_bg_color) {
					this.bgColor = data.color_picker
				}
				if (data.is_bg_pic) {
					this.bgPic = data.bg_pic
					this.bgTabVal = data.bg_tab_val
				}
				this.pageShow = data.is_show
				uni.setNavigationBarTitle({
					title: data.title
				});
				let temp = [];
				let goodsIndex = [];
				let promotionIndex = [];
				let storeIndex = [];
				let lastArr = this.objToArr(data.value);
				lastArr.forEach((item, index, arr) => {
					if (item.name === 'homeComb' && !item.isHide) {
						this.showHomeComb = true;
						this.homeCombData = item;
						if (item.searchConfig.tabVal) {
							this.positionTop = uni.getSystemInfoSync().statusBarHeight+43
						}
					}
					if (item.name == 'headerSerch' && !item.isHide) {
						this.isHeaderSerch = true;
						this.headerSerchCombData = item;
					}
					if (item.name == 'tabNav' && !item.isHide) {
						this.showCateNav = true;
						this.cateNavData = item;
					}
					if(item.name == 'goodList' && !item.isHide){
						goodsIndex.push(index)
					}
					if(item.name == 'promotionList' && !item.isHide){
						promotionIndex.push(index)
					}
					if(item.name == 'storeList' && !item.isHide){
						storeIndex.push(index)
					}
				    if (!item.isHide) {
					  temp.push(item);
				    }
				});

				function sortNumber(a, b) {
					return a.timestamp - b.timestamp;
				}
				temp.sort(sortNumber);
				this.styleConfig = temp;
				this.goodsIndex = goodsIndex;
				this.promotionIndex = promotionIndex;
				this.storeIndex = storeIndex;
				this.entryStore();
			},
			getDiyData() {
				getDiy(0).then(res => {
					uni.setStorageSync('diyData', JSON.stringify(res.data));
					this.setDiyData(res.data);
				}).catch(error => {
					// #ifdef APP-PLUS
					if (error.status) {
						uni.hideLoading()
						if (this.m854f8) {
							uni.showToast({
								title: '请开启网络连接',
								icon: 'none',
								duration: 2000
							})
						}
						this.m854f8 = true;
					}
					// #endif
				});
			},
			diyData() {
				let diyData = uni.getStorageSync('diyData');
				if (diyData) {
					getDiyVersion(0).then(res => {
						let diyVersion = uni.getStorageSync('diyVersion');
						if ((res.data.version + '0') === diyVersion) {
							this.setDiyData(JSON.parse(diyData));
						} else {
							uni.setStorageSync('diyVersion', (res.data.version + '0'));
							this.getDiyData();
						}
					});
				} else {
					this.getDiyData();
				}
			},
		    entryStore(num){
				// num 更新门店或是位置时需要重新获取门店商品数据（针对得是单店模式）
				this.entryData.store_id = this.$util.getCache('rulesStoreId');
				getEntryStore(this.entryData).then(res=>{
					if(res.data.user_entry_name == 'user_belong_store'){
						this.isBelongStore = true;
					}
					let storeId= res.data.store_id;
					uni.setStorageSync('user_store_id', storeId);
					// 网店模式：1：多店+平台； 2：单店+平台；3：单店
					uni.setStorageSync('shop_operation_type', res.data.shop_operation_type);
					this.SET_NEARBY(storeId);
					let data = {
						store_id:storeId
					}
					let changeStore = true; //归属门店在第二位时，是否允许开启切换门店；
					let entryRules = res.data.user_entry_rules;
					entryRules.forEach((item,index)=>{
						if(item.name == 'user_belong_store'){
							this.belongIndex = index;
							changeStore = item.is_change_store;
						}
					})
					this.getnearbyStore(data,changeStore);
					if(res.data.shop_operation_type !=1 && num){
						this.goodsIndex.forEach((item,index)=>{
							this.$refs.goodLists[index].productslist();
						})
						this.promotionIndex.forEach((item,index)=>{
							this.$refs.promotionLists[index].$refs.goodLists.productslist();
						})
						this.storeIndex.forEach((item,index)=>{
							this.$refs.storeLists[index].storeList();
						})
					}
				}).catch(err=>{
				   this.$util.Tips({
					   title: err
				   });
				})
			},
			getnearbyStore(data,changeStore) {
				getnearbyStore(data).then(res => {
					this.storeInfor.storeId = res.data.info.id;
					this.storeInfor.storeName = res.data.info.name;
					let isAlone = res.data.info.is_alone; // 判断门店是否为隔离门店
					if(isAlone || (this.isBelongStore && (!this.belongIndex || (this.belongIndex==1 && !changeStore)))){
						this.belongIndex = 0;
					}else{
						this.belongIndex = 1;
					}
					uni.setStorageSync('belongShow', this.belongIndex);
					if(this.isLogin){
						this.getCartNum();
					}
				});
			},
			changeLogin() {
				this.getIsLogin();
			},
			getIsLogin() {
				toLogin()
			},
			changeBarg(item) {
				uni.navigateTo({
					url: `/pages/activity/goods_bargain_details/index?id=${item.id}&spid=${this.$store.state.app.uid}`
				});
			},
			goDetail(item) {
				goShopDetail(item, this.$store.state.app.uid).catch(res => {
					uni.navigateTo({
						url: `/pages/goods_details/index?id=${item.id}`
					});
				});
			},
			newDataStatus(val,num) {
				this.isFooter = val ? true : false;
				this.pdHeight = num;
			},
			// #ifdef H5
			// 微信分享；
			setOpenShare: function() {
				let that = this;
				let uid = this.uid ? this.uid : 0;
				if (that.$wechat.isWeixin()) {
					getShare().then(res => {
						let data = res.data;
						let configAppMessage = {
							desc: data.synopsis,
							title: data.title,
							link: location.href + '?spid=' + uid,
							imgUrl: data.img
						};
						that.$wechat.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData',
								'onMenuShareAppMessage', 'onMenuShareTimeline'
							],
							configAppMessage);
					});
				}
			},
			// #endif
			hideFollow() {
				for (let i = 0; i < this.styleConfig.length; i++) {
					if (this.styleConfig[i].name == 'follow') {
						this.styleConfig.splice(i, 1);
						break;
					}
				}
			}
		},
		onReachBottom() {
			if (this.goodList.length) {
				this.getGoodsList();
			}
		},
		onPageScroll(e) {
			if(e.scrollTop>20){
				this.myApplet = false;
			}
			// #ifdef H5
			if (this.isHeaderSerch) {
				if (e.scrollTop > this.domOffsetTop) {
					this.isFixed = true;
				}
				if (e.scrollTop < this.domOffsetTop) {
					this.$nextTick(() => {
						this.isFixed = false;
					});
				}
			} else {
				this.isFixed = false
			}
			// #endif
			if (e.scrollTop > 10) {
				this.isScrolled = true;
			} else {
				this.isScrolled = false;
			}
			uni.$emit('scroll');
			uni.$emit('onPageScroll', e.scrollTop);
		},
		//#ifdef MP
		onShareAppMessage() {
			let uid = this.uid ? this.uid : 0;
			if(this.shareInfo.img){
				return {
					title: this.shareInfo.title,
					path: '/pages/index/index?spid=' + uid,
					imageUrl: this.shareInfo.img,
					desc: this.shareInfo.synopsis
				};
			}else{
				return {
					title: this.shareInfo.title,
					path: '/pages/index/index?spid=' + uid
					// imageUrl: this.shareInfo.img,
					// desc: this.shareInfo.synopsis
				};
			}

		},
		//分享到朋友圈
		onShareTimeline: function() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: this.shareInfo.title,
				path: '/pages/index/index?spid=' + uid,
				imageUrl: this.shareInfo.img,
				desc: this.shareInfo.synopsis
			};
		}
		//#endif
	};
</script>

<style lang="scss">
	// page {
	// 	padding-bottom: 50px;
	// }
	.m36f2e{
		position: relative;
		&::after{
			position: absolute;
			right: 55px;
			top:-5px;
			content: '';
			width: 0;
			height: 0;
			border-left: 7px solid transparent;
			border-right: 7px solid transparent;
			border-bottom: 7px solid #fff;
		}
	}
	.pictrue_log_class {
		background-color: var(--view-theme);
	}

	.ysize {
		background-size: 100%;
	}

	.fullsize {
		background-size: 100% 100%;
	}

	.repeat {
		background-repeat: repeat;
	}

	.noRepeat {
		background-repeat: no-repeat;
	}

	.error-network {
		position: fixed;
		left: 0;
		top: 0;
		display: flex;
		flex-direction: column;
		align-items: center;
		width: 100%;
		height: 100%;
		padding-top: 40rpx;
		background: #fff;

		image {
			width: 414rpx;
			height: 336rpx;
		}

		.title {
			position: relative;
			top: -40rpx;
			font-size: 32rpx;
			color: #666;
		}

		.con {
			font-size: 24rpx;
			color: #999;

			.label {
				margin-bottom: 20rpx;
			}

			.item {
				margin-bottom: 20rpx;
			}
		}

		.btn {
			display: flex;
			align-items: center;
			justify-content: center;
			width: 508rpx;
			height: 86rpx;
			margin-top: 100rpx;
			border: 1px solid #D74432;
			color: #E93323;
			font-size: 30rpx;
			border-radius: 120rpx;
		}
	}

	.sort-scroll {
		background-color: #fff;
	}

	.sort-product {
		margin-top: 20rpx;
	}

	.site-config {
		margin: 40rpx 0;
		font-size: 24rpx;
		text-align: center;
		color: #666;

		&.fixed {
			position: fixed;
			bottom: 69px;
			left: 0;
			width: 100%;
		}
	}
	.select{
		border: 1px solid var(--view-theme);
	}
</style>
