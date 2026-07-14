<template>
	<view class="group-con" :style="colorStyle">
		<view class="w-full header-gradient relative z-99" :style="{'padding-top': sysHeight + 'px'}">
			<view class="w-full px-20 pl-20 h-80 flex-between-center">
				<text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff" @tap="backPage()"></text>
				<text class="fs-34 fw-500 text--w111-fff">{{userBool === 1 && isOk == 0 && pinkBool === 0?'开团':pinkBool === 1?'拼团成功':pinkBool === -1?'拼团失败':'参团'}}</text>
				<text></text>
			</view>
			<view class="p-20">
				<view class="w-full bg--w111-fff rd-24rpx p-20 flex relative">
					<easy-loadimage
					:image-src="storeCombination.image"
					width="240rpx"
					height="240rpx"
					borderRadius="20rpx"></easy-loadimage>
					<view class="flex-1 h-240 pl-20 flex-col justify-between">
						<view class="w-full">
							<view class="w-full fs-28 lh-40rpx line2">{{storeCombination.title}}</view>
							<view class="flex fs-20 mt-14">
								<view class="tuan-num text--w111-fff flex-center">{{storeCombination.people}}人团</view>
								<view class="complete font-red flex-center">已拼{{storeCombination.pink_count}}份</view>
							</view>
						</view>
						<view class="w-full flex-between-center">
							<view>
								<view class="flex items-baseline">
									<text class="fs-22 lh-30rpx font-red fw-500">拼团价:</text>
									<baseMoney :money="storeCombination.price" symbolSize="24" integerSize="40" decimalSize="24" color="#e93323" weight></baseMoney>
								</view>
								<view class="text-line text--w111-999 fs-22 lh-30rpx mt-12">¥{{storeCombination.product_price}}</view>
							</view>
						</view>
					</view>
					<view class="abs-badge">
						<image class="w-full h-full" :src="imgHost + '/statics/images/product/pink-success.png'" v-if="pinkBool === 1"></image>
						<image class="w-full h-full" :src="imgHost + '/statics/images/product/pink-error.png'" v-else-if="pinkBool === -1"></image>
					</view>
				</view>
			</view>
		</view>
		<view class="px-20">
			<view class="wrapper">
				<view class="tips" v-if="pinkBool === 1">恭喜您拼团成功</view>
				<view class="tips" v-else-if="pinkBool === -1">还差<span class="font-num pl-10 pr-10">{{ count }}人</span>，拼团失败</view>
				<view class="tips" v-else-if="pinkBool === 0">拼团中，还差<span class="font-num pl-10 pr-10">{{ count }}人</span>拼团成功</view>
				<view class="title acea-row row-center-wrapper" v-if="pinkBool === 0">
					<view class="name acea-row row-center-wrapper">
						剩余
						<CountDown
						:is-day="false"
						tip-text=" "
						day-text=" "
						hour-text=" : "
						minute-text=" : "
						second-text=" "
						bgColor="rgba(233, 51, 35, 0.20)"
						dotColor="#e93323"
						:datatime="pinkT.stop_time"></CountDown>结束
					</view>
				</view>
				<view class="list acea-row row-middle" :class="[pinkBool === 1 || pinkBool === -1 ? 'result' : '', iShidden ? 'on' : '']">
					<view class="pictrue">
						<img :src="pinkT.avatar" />
						<view class="label">团长</view>
					</view>
					<view class="pictrue" v-if="pinkAll.length > 0" v-for="(item, index) in pinkAll" :key="index"><img :src="item.avatar" /></view>
					<view class="pictrue" v-for="index in count" :key="index">
						<image class="img-none" src="../static/vacancy.png"> </image>
					</view>
				</view>
				<view v-if="(pinkBool === 1 || pinkBool === -1) && pinkAll.length > 9" class="lookAll acea-row row-center-wrapper" @click="lookAll">
					{{ iShidden ? '收起' : '查看全部' }}
					<span class="iconfont" :class="iShidden ? 'icon-ic_uparrow' : 'icon-ic_downarrow'"></span>
				</view>
				<view class="teamBnt" v-if="!isLogin" @click="getIsLogin">立即登录</view>
				<view v-if="userBool === 1 && isOk == 0 && pinkBool === 0">
					<view class="teamBnt" @click="listenerActionSheet">邀请好友参团</view>
				</view>
				<view class="teamBnt" v-else-if="userBool === 0 && pinkBool === 0 && count > 0">
					<view v-if="endTime" @click="goDetail(storeCombination.id)">我要开团</view>
					<view v-else @click="pay">我要参团</view>
				</view>
				<view class="teamBnt" v-if="pinkBool === 1 || pinkBool === -1" @click="goDetail(storeCombination.id)">再次开团</view>
				<view class="cancel" @click="getCombinationRemove" v-if="pinkBool === 0 && userBool === 1 && pinkT.uid == userInfo.uid">
					取消开团
				</view>
				<view class="lookOrder" v-if="pinkBool === 1" @click="goOrder">
					查看订单信息
					<span class="iconfont icon-ic_rightarrow"></span>
				</view>
			</view>
			<view class="play-wrapper">
				<view class="w-full flex-center">
					<image class="zs" src="@/static/img/recommend_zs.png"></image>
					<text class="fs-32 fw-500 text--w111-333 lh-44rpx px-6">拼团玩法</text>
					<image class="zs" src="@/static/img/recommend_zs.png"></image>
				</view>
				<view class="wrapper-main acea-row row-center-wrapper">
					<view class="progress-box">
						<view class="progress">
							<view class="inner bg-red"></view>
						</view>
						<view class="steps acea-row">
							<view class="item">
								<view class="head bg-red">1</view>
								<view class="main">开团/参团享团购价</view>
							</view>
							<view class="item">
								<view class="head bg-red">2</view>
								<view class="main">邀请好友参团优惠多</view>
							</view>
							<view class="item">
								<view class="head" :class="pinkBool === 1 ? 'bg-red' : ''">3</view>
								<view class="main">人满发货不满退款</view>
							</view>
						</view>
					</view>
				</view>
			</view>
			<view class="mt-40">
				<view class="w-full flex-center">
					<image class="zs" src="@/static/img/recommend_zs.png"></image>
					<text class="fs-32 fw-500 text--w111-333 lh-44rpx px-6">大家都在拼</text>
					<image class="zs" src="@/static/img/recommend_zs.png"></image>
				</view>
				<view class="card w-full bg--w111-fff rd-24rpx p-20 flex mt-24"
					v-for="(item,index) in storeCombinationHost" :key="index"
					@tap="goDetail(item.id)">
					<easy-loadimage
					:image-src="item.image"
					width="240rpx"
					height="240rpx"
					borderRadius="20rpx"></easy-loadimage>
					<view class="flex-1 h-240 pl-20 flex-col justify-between">
						<view class="w-full">
							<view class="w-full fs-28 lh-40rpx line2">{{item.title}}</view>
							<view class="flex fs-20 mt-14">
								<view class="tuan-num text--w111-fff flex-center">{{item.people}}人团</view>
								<view class="complete font-red flex-center">已拼{{item.pink_count}}份</view>
							</view>
						</view>
						<view class="w-full flex-between-center">
							<view>
								<view class="flex items-baseline">
									<text class="fs-22 lh-30rpx font-red fw-500">拼团价:</text>
									<baseMoney :money="item.price" symbolSize="24" integerSize="40" decimalSize="24" color="#e93323" weight></baseMoney>
								</view>
								<view class="text-line text--w111-999 fs-22 lh-30rpx mt-12">¥{{item.product_price}}</view>
							</view>
							<view class="w-144 h-56 rd-30rpx flex-center fs-24 bg-red-gradient text--w111-fff" v-if="item.stock > 0 && item.quota > 0">参与拼团</view>
							<view class="w-144 h-56 rd-30rpx flex-center fs-24 bg-gray text--w111-fff" v-else>参与拼团</view>
						</view>
					</view>
				</view>
			</view>
		</view>
		<product-window
		:attr="attr"
		:limitNum="1"
		:iSbnt="1"
		:type="2"
		:productId="storeCombination.product_id"
		:storeInfo='storeCombination'
		@myevent="onMyEvent"
		@ChangeAttr="ChangeAttr"
		@ChangeCartNum="ChangeCartNum" 
		@iptCartNum="iptCartNum"
		@getImg="showImg"
		@attrVal="attrVal"
		@deliveryFun="deliveryFun" 
		@onAddressId="onAddressId"
		@onstoreId="onstoreId"
		@goCat="goPay"></product-window>
		<cusPreviewImg ref="cusPreviewImg" :list="skuArr" @changeSwitch="changeSwitch"></cusPreviewImg>
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
		<zb-code ref="qrcode" :show="codeShow" :cid="cid" :val="val" :size="size" :unit="unit"
			:background="background" :foreground="foreground" :pdground="pdground" :icon="icon" :iconSize="iconsize"
			:onval="onval" :loadMake="loadMake" @result="qrR" />
		<!-- #endif -->
		<home :isHide="homeHide"></home>
	</view>
</template>
<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	// #ifdef H5
	import zbCode from '@/components/zb-code/zb-code.vue';
	// #endif
	import CountDown from '@/components/countDown';
	import ProductWindow from '@/components/productWindow';
	import cusPreviewImg from '@/components/cusPreviewImg';
	import util from '../../../utils/util.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from 'vuex';
	import {
		getCombinationPink,
		postCombinationRemove,
		getCombinationPosterData,
	} from '@/api/activity';
	import {
		postCartAdd
	} from '@/api/store';
	import home from '@/components/home';
	const NAME = 'GroupRule';
	import {
		TOKENNAME,
		HTTP_REQUEST_URL
	} from '@/config/app.js';
	const app = getApp();
	export default {
		name: NAME,
		components: {
			CountDown,
			ProductWindow,
			home,
			cusPreviewImg,
			// #ifdef H5
			zbCode,
			// #endif
		},
		data: function() {
			return {
				colorStyle:'--view-theme: #e93323;--view-minorColorT:rgba(233, 51, 35, 0.1);--view-bntColor:#FE960F;',
				sysHeight:sysHeight,
				currentPinkOrder: '', //当前拼团订单
				isOk: 0, //判断拼团是否完成
				pinkBool: 0, //判断拼团是否成功|0=失败,1=成功
				userBool: 0, //判断当前用户是否在团内|0=未在,1=在
				pinkAll: [], //团员
				pinkT: [], //团长信息
				storeCombination: [], //拼团产品
				storeCombinationHost: [], //拼团推荐
				pinkId: 0,
				count: 0, //拼团剩余人数
				iShidden: false,
				isOpen: false, //是否打开属性组件
				attr: {
					cartAttr: false,
					productSelect: {
						image: '',
						store_name: '',
						price: '',
						quota: 0,
						unique: '',
						cart_num: 1,
						quota_show: 0,
						stock: 0,
						num: 0
					},
					productAttr: [],
					deliveryType:[],
					isType:0,
					relation_id:0,
					store_self_mention:0
				},
				cart_num: '',
				userInfo: {},
				posters: false,
				weixinStatus: false,
				H5ShareBox: false, //公众号分享图片
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				attrTxt: '请选择', //属性页面提示
				attrValue: '', //已选属性
				selectSku:{},
				imgHost: HTTP_REQUEST_URL,
				addressId:'',
				store_id :'',
				delivery_type:0,
				store_name:'',
				endTime: false,
				skuArr:[],
				posterImageStatus: false,
				posterImage: '', //海报路径
				canvasStatus: false, //海报绘图标签
				codeShow: false,
				cid: '1',
				val: "", // 要生成的二维码值
				size: 200, // 二维码大小
				unit: 'upx', // 单位
				background: '#FFF', // 背景色
				foreground: '#000', // 前景色
				pdground: '#000', // 角标色
				icon: '', // 二维码图标
				iconsize: 40, // 二维码图标大小
				onval: true, // val值变化时自动重新生成二维码
				loadMake: true, // 组件加载完成后自动生成二维码
				codeSrc: "",
				homeHide: false
			};
		},
		computed: mapGetters({
			'isLogin': 'isLogin',
			'userData': 'userInfo'
		}),
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						this.getCombinationPink();
					} else {
						this.getIsLogin();
					}
				},
				deep: true
			},
			userData: {
				handler: function(newV, oldV) {
					if (newV) {
						this.userInfo = newV;
						app.globalData.openPages = '/pages/activity/goods_combination_status/index?id=' + this.pinkId+'&spid=' + this.userInfo.uid;
					}
				},
				deep: true
			}
		},
		onLoad(options) {
			var that = this;
			// #ifdef MP
			if (options.scene) {
				var value = util.getUrlParams(decodeURIComponent(options.scene));
				if (typeof value === 'object') {
					if (value.id) options.id = value.id;
					//记录推广人uid
					if (value.spid) app.globalData.spid = value.spid;
				}
			}
			// #endif
			if (options.id) {
				that.pinkId = options.id;
			}
			// 记录推广人uid；
			if (options.spid) app.globalData.spid = options.spid;
			if (that.isLogin == false) {
				this.$Cache.set('login_back_url', `/pages/activity/goods_combination_status/index?id=${options.id}`);
				this.getIsLogin();
			} else {
				this.getCombinationPink();
			}
			// #ifdef H5
			this.val = window.location.origin + '/pages/activity/goods_combination_status/index?id=' + this.pinkId +
				'&spid=' +
				this.$store.state.app.uid
			// #endif
			// #ifdef APP-PLUS
			this.val = HTTP_REQUEST_URL + '/pages/activity/goods_combination_status/index?id=' + this.pinkId +
				'&spid=' +
				this.$store.state.app.uid
			// #endif
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
		onPageScroll(object) {
			uni.$emit('scroll');
      this.homeHide = true;
		},
		//#ifdef MP
		/**
		 * 用户点击右上角分享
		 */
		onShareAppMessage: function() {
			let that = this;
			return {
				title: '您的好友' + that.userInfo.nickname + '邀请您参团' + that.storeCombination.title,
				path: app.globalData.openPages,
				imageUrl: that.storeCombination.image
			};
		},
		//#endif
		methods: {
			onLoadFun() {
				this.getCombinationPink();
				this.isShowAuth = false;
			},
			getIsLogin() {
				toLogin()
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			// 获取配送方式
			deliveryFun(e){
				this.delivery_type = e;
			},
			// 获取配送地址id
			onAddressId(row){
				this.addressId=row.id
			},
			// 获取到店核销地址id
			onstoreId(row) {
			    this.store_id = row.id;
				this.store_name = row.name;
			},
			//点击sku图片打开轮播图
			showImg(index){
				this.$refs.cusPreviewImg.open(this.selectSku.suk)
			},
			//滑动轮播图选择商品
			changeSwitch(e){
				let productSelect = this.skuArr[e];
				this.$set(this,'selectSku',productSelect);
				var skuList = productSelect.suk.split(',');
				skuList.forEach((i,index)=>{
					this.$set(this.attr.productAttr[index],'index',skuList[index]);
				})
				if (productSelect) {
					this.$set(this.attr.productSelect, "image", productSelect.image);
					this.$set(this.attr.productSelect, "price", productSelect.price);
					this.$set(this.attr.productSelect, "stock", productSelect.stock);
					this.$set(this.attr.productSelect, "unique", productSelect.unique);
					this.$set(this.attr.productSelect, "cart_num", 1);
					this.$set(this.attr.productSelect, "quota", productSelect.quota);
					this.$set(this.attr.productSelect, "quota_show", productSelect.quota_show);
					this.$set(this, "attrValue", productSelect.suk);
					this.attrTxt = "已选择"
				}
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
					title: '您的好友' + that.userInfo.nickname + '邀请您参团' + that.storeCombination.title,
					imageUrl: that.storeCombination.small_image,
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
			// 分享关闭
			listenerActionClose: function() {
				this.posters = false;
			},
			// 小程序关闭分享弹窗；
			goFriend: function() {
				this.posters = false;
			},
			/**
			 * 购物车手动填写
			 *
			 */
			iptCartNum: function(e) {
				this.$set(this.attr.productSelect, 'cart_num', e);
				this.$set(this, 'cart_num', e);
			},
			attrVal(val) {
				this.attr.productAttr[val.indexw].index = this.attr.productAttr[val.indexw].attr_values[val.indexn];
			},
			onMyEvent: function() {
				this.$set(this.attr, 'cartAttr', false);
				this.$set(this, 'isOpen', false);
			},
			//选择属性；
			ChangeAttr: function(res) {
				this.$set(this, 'cart_num', 1);
				let productSelect = this.productValue[res];
				this.$set(this, "selectSku", productSelect);
				if (productSelect) {
					this.$set(this.attr.productSelect, 'image', productSelect.image);
					this.$set(this.attr.productSelect, 'price', productSelect.price);
					this.$set(this.attr.productSelect, 'quota', productSelect.quota);
					this.$set(this.attr.productSelect, 'unique', productSelect.unique);
					this.$set(this.attr.productSelect, 'cart_num', 1);
					this.$set(this.attr.productSelect, 'stock', productSelect.stock);
					this.$set(this.attr.productSelect, 'quota_show', productSelect.quota_show);
					this.$set(this, 'attrValue', res);
					this.$set(this, 'attrTxt', '已选择');
				} else {
					this.$set(this.attr.productSelect, 'image', this.storeCombination.image);
					this.$set(this.attr.productSelect, 'price', this.storeCombination.price);
					this.$set(this.attr.productSelect, 'quota', 0);
					this.$set(this.attr.productSelect, 'unique', '');
					this.$set(this.attr.productSelect, 'cart_num', 0);
					this.$set(this.attr.productSelect, 'quota_show', 0);
					this.$set(this.attr.productSelect, 'stock', 0);
					this.$set(this, 'attrValue', '');
					this.$set(this, 'attrTxt', '请选择');
				}
			},
			ChangeCartNum: function(res) {
				//changeValue:是否 加|减
				//获取当前变动属性
				let productSelect = this.productValue[this.attrValue];
				if (this.cart_num) {
					productSelect.cart_num = this.cart_num;
					this.attr.productSelect.cart_num = this.cart_num;
				}
				//如果没有属性,赋值给商品默认库存
				if (productSelect === undefined && !this.attr.productAttr.length) productSelect = this.attr
					.productSelect;
				if (productSelect === undefined) return;
				let stock = productSelect.stock || 0;
				let quotaShow = productSelect.quota_show || 0;
				let quota = productSelect.quota || 0;
				let productStock = productSelect.stock || 0;
				let num = this.attr.productSelect;
				let nums = this.storeCombination.num || 0;
				//设置默认数据
				if (productSelect.cart_num == undefined) productSelect.cart_num = 1;
				if (res) {
					num.cart_num++;
					let arrMin = [];
					arrMin.push(nums);
					arrMin.push(quota);
					arrMin.push(productStock);
					let minN = Math.min.apply(null, arrMin);
					if (num.cart_num >= minN) {
						this.$set(this.attr.productSelect, 'cart_num', minN ? minN : 1);
						this.$set(this, 'cart_num', minN ? minN : 1);
					}
					this.$set(this, 'cart_num', num.cart_num);
					this.$set(this.attr.productSelect, 'cart_num', num.cart_num);
				} else {
					num.cart_num--;
					if (num.cart_num < 1) {
						this.$set(this.attr.productSelect, 'cart_num', 1);
						this.$set(this, 'cart_num', 1);
					}
					this.$set(this, 'cart_num', num.cart_num);
					this.$set(this.attr.productSelect, 'cart_num', num.cart_num);
				}
			},
			//默认选中属性；
			DefaultSelect() {
				let productAttr = this.attr.productAttr,
					value = [];
				for (var key in this.productValue) {
					if (this.productValue[key].quota > 0) {
						value = this.attr.productAttr.length ? key.split(',') : [];
						break;
					}
				}
				for (let i = 0; i < productAttr.length; i++) {
					this.$set(productAttr[i], 'index', value[i]);
				}
				//sort();排序函数:数字-英文-汉字；
				let productSelect = this.productValue[value.join(',')];
				if (productSelect && productAttr.length) {
					this.$set(this.attr.productSelect, 'store_name', this.storeCombination.title);
					this.$set(this.attr.productSelect, 'image', productSelect.image);
					this.$set(this.attr.productSelect, 'price', productSelect.price);
					this.$set(this.attr.productSelect, 'quota', productSelect.quota);
					this.$set(this.attr.productSelect, 'unique', productSelect.unique);
					this.$set(this.attr.productSelect, 'cart_num', 1);
					this.$set(this.attr.productSelect, 'stock', productSelect.stock);
					this.$set(this.attr.productSelect, 'quota_show', productSelect.quota_show);
					this.$set(this, 'attrValue', value.join(','));
					this.attrValue = value.join(',');
					this.$set(this, 'attrTxt', '已选择');
				} else if (!productSelect && productAttr.length) {
					this.$set(this.attr.productSelect, 'store_name', this.storeCombination.title);
					this.$set(this.attr.productSelect, 'image', this.storeCombination.image);
					this.$set(this.attr.productSelect, 'price', this.storeCombination.price);
					this.$set(this.attr.productSelect, 'quota', 0);
					this.$set(this.attr.productSelect, 'unique', '');
					this.$set(this.attr.productSelect, 'cart_num', 0);
					this.$set(this.attr.productSelect, 'stock', 0);
					this.$set(this.attr.productSelect, 'quota_show', 0);
					this.$set(this, 'attrValue', '');
					this.$set(this, 'attrTxt', '请选择');
				} else if (!productSelect && !productAttr.length) {
					this.$set(this.attr.productSelect, 'store_name', this.storeCombination.title);
					this.$set(this.attr.productSelect, 'image', this.storeCombination.image);
					this.$set(this.attr.productSelect, 'price', this.storeCombination.price);
					this.$set(this.attr.productSelect, 'quota', 0);
					this.$set(this.attr.productSelect, 'unique', this.storeCombination.unique || '');
					this.$set(this.attr.productSelect, 'cart_num', 1);
					this.$set(this.attr.productSelect, 'quota_show', 0);
					this.$set(this.attr.productSelect, 'stock', 0);
					this.$set(this, 'attrValue', '');
					this.$set(this, 'attrTxt', '请选择');
				}
			},
			setProductSelect: function() {
				var that = this;
				var attr = that.attr;
				attr.productSelect.image = that.storeCombination.image;
				attr.productSelect.store_name = that.storeCombination.title;
				attr.productSelect.price = that.storeCombination.price;
				attr.productSelect.quota = 0;
				attr.productSelect.quota_show = 0;
				attr.productSelect.stock = 0;
				attr.cartAttr = false;
				that.$set(that, 'attr', attr);
			},
			pay: function() {
				var that = this;
				that.attr.cartAttr = true;
				that.isOpen = true;
			},
			goPay() {
				var that = this;
				var data = {};
				data.productId = that.storeCombination.product_id;
				data.cartNum = that.attr.productSelect.cart_num;
				data.uniqueId = that.attr.productSelect.unique;
				data.combinationId = that.storeCombination.id;
				data.new = 1;
				data.store_id = this.store_id;
				data.addressId = this.addressId;
				data.delivery_type = this.delivery_type;
				postCartAdd(data)
					.then(res => {
						uni.navigateTo({
							url: '/pages/goods/order_confirm/index?new=1&cartId=' + res.data.cartId + '&pinkId=' + that.pinkId+'&delivery_type='+that.delivery_type+'&addressId='+that.addressId+'&store_id='+that.store_id+'&store_name='+that.store_name+'&product_id=' + that.storeCombination.product_id
						});
					})
					.catch(res => {
						that.$util.Tips({
							title: res
						});
					});
			},
			goOrder: function() {
				var that = this;
				uni.navigateTo({
					url: '/pages/goods/order_details/index?order_id=' + that.currentPinkOrder
				});
			},
			//拼团列表
			goList: function() {
				uni.navigateTo({
					url: '/pages/activity/goods_combination/index'
				});
			},
			//拼团详情
			goDetail: function(id) {
				this.pinkId = id;
				uni.navigateTo({
					url: '/pages/activity/goods_details/index?id=' + id + '&type=3'
				});
			},
			//拼团信息
			getCombinationPink: function() {
				var that = this;
				getCombinationPink(that.pinkId)
					.then(res => {
						that.$set(that.attr, 'isType', res.data.store_combination.type);
						that.$set(that.attr, 'relation_id', res.data.store_combination.relation_id);
						that.$set(that.attr, 'store_self_mention', res.data.store_self_mention);
						that.$set(that, 'storeCombinationHost', res.data.store_combination_host);
						res.data.pinkT.stop_time = parseInt(res.data.pinkT.stop_time);
						let timestamp = Date.parse(new Date()) / 1000;
						that.endTime = parseInt(timestamp) > res.data.pinkT.stop_time;
						that.$set(that, 'storeCombination', res.data.store_combination);
						that.$set(that.attr.productSelect, 'num', res.data.store_combination.num);
						that.$set(that, 'pinkT', res.data.pinkT);
						that.$set(that, 'pinkAll', res.data.pinkAll);
						that.$set(that, 'count', res.data.count);
						that.$set(that, 'userBool', res.data.userBool);
						that.$set(that, 'pinkBool', res.data.pinkBool);
						that.$set(that, 'isOk', res.data.is_ok);
						that.$set(that, 'currentPinkOrder', res.data.current_pink_order);
						that.$set(that, 'userInfo', res.data.userInfo);
						for(let key in res.data.store_combination.productValue){
							let obj = res.data.store_combination.productValue[key];
							that.skuArr.push(obj)
						}
						
						that.selectSku = that.skuArr[0];
						let uid = that.userInfo.uid?that.userInfo.uid:0
						app.globalData.openPages = '/pages/activity/goods_combination_status/index?id=' + this.pinkId + '&spid='+uid;
						res.data.store_combination.delivery_type.sort((x,y)=>x - y);
						that.$set(that.attr, 'deliveryType', res.data.store_combination.delivery_type);
						that.attr.productAttr = res.data.store_combination.productAttr;
						that.productValue = res.data.store_combination.productValue;
						//#ifdef H5
						that.setOpenShare();
						//#endif
						that.setProductSelect();
						if (that.attr.productAttr != 0) that.DefaultSelect();
						if (res.data.is_ok == 1 && res.data.userBool == 0) {
							return this.$util.Tips({
								title: '你不是该团的成员',
							}, () => {
								uni.navigateTo({
									url: '/pages/activity/goods_combination/index'
								})
							});
						}
					})
					.catch(err => {
						return this.$util.Tips({
							title: err,
						}, () => {
							uni.navigateBack()
						});
					});
			},
			//#ifdef H5
			setOpenShare() {
				let that = this;
				let configTimeline = {
					title: '您的好友' + that.userInfo.nickname + '邀请您参团' + that.storeCombination.title,
					desc: that.storeCombination.title,
					link: window.location.protocol + '//' + window.location.host +
						'/pages/activity/goods_combination_status/index?id=' + that.pinkId + '&spid=' + that.userInfo.uid,
					imgUrl: that.storeCombination.image
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
			//#endif
			//拼团取消
			getCombinationRemove: function() {
				var that = this;
				postCombinationRemove({
						id: that.pinkId,
						cid: that.storeCombination.id
					})
					.then(res => {
						that.$util.Tips({
							title: res.msg
						}, {
							tab: 3
						});
					})
					.catch(res => {
						that.$util.Tips({
							title: res
						});
					});
			},
			lookAll: function() {
				this.iShidden = !this.iShidden;
			},
			backPage(){
				let pages = getCurrentPages(); // 获取当前打开过的页面路由数，
				if (pages.length > 1) {
					uni.navigateBack()
				} else {
					uni.switchTab({
						url: '/pages/index/index'
					});
				}
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
				getCombinationPosterData(this.pinkId).then(async ({ data }) => {
					const resData = data;
					const posterBag = await this.downloadFilestoreImage(`${this.imgHost}/statics/images/activity/posterBag.png`);
					const posterBagInner = await this.downloadFilestoreImage(`${this.imgHost}/statics/images/activity/posterBagInner.png`);
					const postertag = await this.downloadFilestoreImage(`${this.imgHost}/statics/images/activity/postertag.png`);
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
						this.$util.bargainPosterCanvas(arr, resData.title, resData.label, resData.msg, resData.price, userData, '拼团',
							(tempFilePath) => {
								this.posterImage = tempFilePath;
								this.posterImageStatus = true;
								this.canvasStatus = false;
							});
					})
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
			// #ifdef MP
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
			// #ifdef H5
			qrR(res) {
				this.codeSrc = res
			},
			// #endif
		}
	};
</script>
<style lang="scss" scoped>
	.header-gradient{
		background: linear-gradient(180deg, #E93323 0%, #E93323 52%, #F5F5F5 100%);
	}
	.tuan-num{
		min-width: 70rpx;
		height: 32rpx;
		padding: 0 8rpx;
		background: #E93323;
		border-radius: 8rpx 0 0 8rpx;
	}
	.complete{
		width: 110rpx;
		height: 32rpx;
		background: rgba(233, 51, 35, 0.1);
		border-radius: 0 8rpx 8rpx 0;
	}
	.font-red{
		color: #e93323;
	}
	.bg-red{
		background-color: #e93323 !important;
	}
	.notice-box{
		width: 348rpx;
		background: linear-gradient(270deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 99%);
	}
	.zs{
		width:42rpx;
		height:36rpx;
	}
	.abs-badge{
		width: 102rpx;
		height: 102rpx;
		position: absolute;
		right: 20rpx;
		bottom: 20rpx;
		border-radius: 50%;
	}

	.group-con .wrapper {
		background-color: #fff;
		padding: 48rpx 0;
		border-radius: 24rpx;
	}

	.group-con .wrapper .title {
		margin-top: 48rpx;
	}

	.group-con .wrapper .title .line {
		width: 136rpx;
		height: 1px;
		background-color: #ddd;
	}

	.group-con .wrapper .title .name {
		font-size: 24rpx;
		line-height: 36rpx;
		color: #3D3D3D;
	}

	.group-con .wrapper .title .name .time {
		margin: 0 12rpx;
	}

	.group-con .wrapper .title .name .timeTxt {
		color: #fc4141;
	}

	.group-con .wrapper .title .name /deep/.time .styleAll {
		text-align: center;
		border-radius: 8rpx;
		font-size: 24rpx;
		font-weight: bold;
		display: inline-block;
		vertical-align: middle;
		width: 36rpx;
		height: 36rpx;
		line-height: 36rpx;
	}

	.group-con .wrapper .tips {
		font-size: 32rpx;
		font-weight: bold;
		text-align: center;
		line-height: 42rpx;
		color: #333333;
	}

	.group-con .wrapper .list {
		padding-left: 44rpx;
		margin-top: 40rpx;
	}

	.group-con .wrapper .list.result {
		max-height: 240rpx;
		overflow: hidden;
		padding-top: 20rpx;
	}

	.group-con .wrapper .list.result.on {
		max-height: 2000rpx;
	}

	.group-con .wrapper .list .pictrue {
		width: 94rpx;
		height: 94rpx;
		margin: 0 38rpx 30rpx 0;
		position: relative;
	}

	.group-con .wrapper .list .pictrue .label {
		position: absolute;
		bottom: -8rpx;
		left: 50%;
		width: 72rpx;
		height: 30rpx;
		margin-left: -36rpx;
		color: #fff;
		font-size: 20rpx;
		background-color: var(--view-theme);
		text-align: center;
		line-height: 30rpx;
		border-radius: 15rpx;
	}

	.group-con .wrapper .list .pictrue img,
	.group-con .wrapper .list .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 50%;
		border: 2rpx solid var(--view-theme);
	}

	.group-con .wrapper .list .pictrue image.img-none {
		border: none;
	}

	.group-con .wrapper .lookAll {
		font-size: 24rpx;
		color: #282828;
		padding-top: 10rpx;
	}

	.group-con .wrapper .lookAll .iconfont {
		font-size: 25rpx;
		margin: 2rpx 0 0 10rpx;
	}

	.group-con .wrapper .teamBnt {
		font-size: 28rpx;
		width: 646rpx;
		height: 88rpx;
		border-radius: 44rpx;
		text-align: center;
		line-height: 88rpx;
		color: #fff;
		margin: 32rpx auto 0;
		background-color: #e93323;
	}

	.group-con .wrapper .cancel,
	.group-con .wrapper .lookOrder {
		text-align: center;
		font-size: 24rpx;
		color: #333333;
		padding-top: 30rpx;
	}

	.group-con .wrapper .cancel .iconfont {
		font-size: 35rpx;
		color: #2c2c2c;
		vertical-align: -4rpx;
		margin-right: 9rpx;
	}

	.group-con .wrapper .lookOrder .iconfont {
		font-size: 25rpx;
		color: #2c2c2c;
		margin-left: 10rpx;
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

	.play-wrapper {
		padding: 32rpx 0 132rpx;
		border-radius: 24rpx;
		margin-top: 20rpx;
		background-color: #FFFFFF;
	}

	.play-wrapper .wrapper-header {
		text-align: center;
	}

	.play-wrapper .wrapper-header .title {
		display: inline-block;
		height: 40rpx;
		padding: 0 48rpx;
		font-weight: 500;
		font-size: 28rpx;
		line-height: 40rpx;
		color: #333333;
	}

	.play-wrapper .wrapper-main {
		margin-top: 42rpx;
	}

	.play-wrapper .wrapper-main .item {
		position: relative;
		margin-right: 210rpx;
	}

	.play-wrapper .wrapper-main .item:last-child {
		margin-right: 0;
	}

	.play-wrapper .wrapper-main .item .head {
		position: relative;
		width: 36rpx;
		height: 36rpx;
		border-radius: 18rpx;
		background-color: #DDDDDD;
		text-align: center;
		font-weight: 600;
		font-size: 24rpx;
		line-height: 36rpx;
		color: #FFFFFF;
	}

	.play-wrapper .wrapper-main .item .main {
		position: absolute;
		top: 0;
		left: 50%;
		width: 120rpx;
		margin: 68rpx 0 0 -60rpx;
		text-align: center;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #333333;
	}

	.play-wrapper .wrapper-main .progress-box {
		position: relative;
	}

	.play-wrapper .wrapper-main .progress {
		position: absolute;
		top: 50%;
		left: 50%;
		width: 494rpx;
		height: 12rpx;
		background-color: #EEEEEE;
		transform: translate(-50%, -50%);
	}

	.play-wrapper .wrapper-main .progress .inner {
		width: 70%;
		height: 12rpx;
		border-radius: 6rpx;
		background: linear-gradient(270deg, #FF7931 0%, var(--view-theme) 100%);
	}
	.bg-red-gradient{
		background: linear-gradient(90deg, #E93323 0%, #FF7931 100%);
	}
	.poster-mask{
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background-color: rgba(0,0,0,0.8);
		z-index: 387;
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
		z-index: 388;
	}
	.poster-loading{
		position: relative;
		width: 100%;
		z-index: 31;
	}
</style>
