<template>
	<view>
		<view class="mask" :class="{'z-1000':iScart}" @touchmove.stop.prevent="moveHandle" :hidden="attr.cartAttr === false" @click="closeAttr"></view>
		<view class="product-window"
			:class="(attr.cartAttr === true ? 'on' : '') + ' ' + (iSbnt?'join':'') + ' ' + (iScart?'joinCart':'')"
			@touchmove.stop.prevent="moveHandle">
			<view class="textpic acea-row">
				<view class="pictrue" @click="showImg()">
					<image :src="attr.productSelect.image"></image>
					<view class="icon flex-center" v-if="fangda">
						<view class="iconfont icon-ic_enlarge"></view>
					</view>
				</view>
				<view class="pl-24 pt-24 flex-col">
					<view class="flex-y-center" v-if="attr.productSelect.integral">
						<image src="../../static/img/mall05.png" class="w-32 h-32"></image>
						<text class="lh-40rpx font-num fs-40 SemiBold pl-8">{{attr.productSelect.integral}}</text>
						<text class="fs-28 lh-40rpx px-8 text--w111-666">+</text>
						<baseMoney :money="attr.productSelect.price" symbolSize="28" integerSize="40" decimalSize="28" color="var(--view-theme)" weight></baseMoney>
					</view>
					<baseMoney
					:money="attr.productSelect.price"
					symbolSize="32"
					integerSize="48"
					decimalSize="32"
					incolor="var(--primary-theme-con)"
					weight v-else></baseMoney>
					<view class="inline-block h-48 lh-48rpx text-center rd-24rpx bg-color fs-24 text--w111-fff px-20 mt-16"
						v-if="type == 0">
						预估到手 <text class="fs-28 fw-600 pl-8">¥{{attr.productSelect.pay_price || 0}}</text>
					</view>
					<view class="mt-12 fs-24 text-#999">库存:{{ attr.productSelect.stock }}</view>
				</view>
				<view class="close w-36 h-36 bg--w111-eee rd-36px acea-row row-center-wrapper" @click="closeAttr">
					<text class="iconfont icon-ic_close fs-24"></text>
				</view>
			</view>
			<view class="rollTop">
				<scroll-view scroll-y="true" :style="'max-height: '+windowHeight+'rpx'" >
					<view class="productWinList">
						<view class="item" v-for="(item, indexw) in attr.productAttr" :key="indexw">
							<view v-show="item.is_pic">
								<view class="flex-between-center">
									<view class="title">{{ item.attr_name }}</view>
									<view class="pr-32 fs-24 text--w111-666 flex-y-center" @tap="toggleGridAttr">
										<text :class="['iconfont fs-28', gridShow ? 'icon-a-ic_Imageandtextsorting' : 'icon-a-ic_Picturearrangement']"></text>
										<text class="pl-6">{{gridShow ? '列表' : '宫格'}}</text>
									</view>
								</view>
								<view class="pl-32 mt-32" v-show="gridShow">
									<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-686" show-scrollbar="false">
										<view class="inline-block mr-12" v-for="(itemn, indexn) in item.attr_value" :key="indexn" @tap="tapAttr(indexw, indexn)">
											<view :class="['grid-item-box', {'grid-active': item.index === itemn.attr}]">
												<view class="w-full h-192 relative">
													<image :src="itemn.pic" mode="aspectFill" class="w-full h-192 block"></image>
													<view class="proview-icon flex-center" @tap.stop="proviewImg(itemn.pic)">
														<text class="iconfont icon-ic_enlarge fs-24 text--w111-fff"></text>
													</view>
												</view>
												<view class="flex-1 bg--w111-f5f5f5 break_word tname line1 flex-center fs-24">{{itemn.attr}}</view>
											</view>
										</view>
									</scroll-view>
								</view>
								<view class="listn acea-row row-middle" v-show="!gridShow">
									<view :class="['cell-itemn flex-between-center', {'active': item.index === itemn.attr}]" v-for="(itemn, indexn) in item.attr_value" :key="indexn" @tap="tapAttr(indexw, indexn)">
										<image :src="itemn.pic" mode="" class="w-48 h-48 rd-50-p111-"></image>
										<text class="pl-8">{{itemn.attr}}</text>
									</view>
								</view>
							</view>
							<view v-show="!item.is_pic">
								<view class="title">{{ item.attr_name }}</view>
								<view class="listn acea-row row-middle">
									<view class="itemn" :class="item.index === itemn.attr ? 'on' : ''"
										v-for="(itemn, indexn) in item.attr_value" @click="tapAttr(indexw, indexn)"
										:key="indexn">
										{{ itemn.attr }}
									</view>
								</view>
							</view>
						</view>
					</view>
				</scroll-view>
				<view class="cart acea-row row-between-wrapper" v-if="type != 'setMeal' && type !='points' && storeInfo.product_type != 5">
					<view class="title">数量</view>
					<view class="carnum acea-row row-left">
						<view class="item reduce acea-row row-center-wrapper"
							:class="attr.productSelect.cart_num <= 1 ? 'on' : ''" v-if="attr.productSelect.cart_num <= 1">
							<text class="iconfont icon-ic_Reduce"></text>
						</view>
						<view class="item reduce acea-row row-center-wrapper"
							:class="attr.productSelect.cart_num <= 1 ? 'on' : ''" @click="CartNumDes" v-else>
							<text class="iconfont icon-ic_Reduce"></text>
						</view>
						<view class='item num acea-row row-middle'>
							<input type="number" v-model="attr.productSelect.cart_num"
								data-name="productSelect.cart_num" :always-embed="true" :adjust-position="true" cursor-spacing="30"
								@input="bindCode(attr.productSelect.cart_num)"></input>
						</view>
						<view class="item plus acea-row row-center-wrapper"
							:class="attr.productSelect.cart_num >= attr.productSelect.stock ? 'on' : ''" @click="CartNumAdd">
							<text class="iconfont icon-ic_increase"></text>
						</view>
					</view>
				</view>
			</view>
			<view v-if="isExtends">
				<view class="joinBnt bg-gray" v-if="attr.productSelect.stock <= 0">已售罄</view>
				<view class="joinBnt bg-color" v-else @click="cartConfirm">确定</view>
			</view>
			<view class="joinBnt bg-color"
				v-if="iSbnt && attr.productSelect.stock>0 &&attr.productSelect.quota>0" @click="goCat">我要参团
			</view>
			<view class="joinBnt on"
				v-else-if="(iSbnt && attr.productSelect.quota<=0)||(iSbnt &&attr.productSelect.product_stock<=0)">已售罄
			</view>
			<view class="joinBnt bg-color" v-if="iScart && attr.productSelect.stock" @click="goCat">确定</view>
			<view class="joinBnt on" v-else-if="iScart && !attr.productSelect.stock">已售罄</view>
		</view>
		<!-- 选择门店 -->
		<view class="mask on" :style="{zIndex:iScart?1000:102}" @touchmove.prevent :hidden="isStore === false" @click="closeStore"></view>
		<view class="product-window" :class="isStore?'store':''" :style="{zIndex:iScart?1000:102}">
			<view class="storeTitle">选择门店<text class="iconfont icon-guanbi5" @click="closeStore"></text></view>
			<view class="storeList">
				<view class="item" :class="active == index?'on':''" v-for="(item,index) in storeList" :key="index" @click="tapStore(index,item)">
					<view class="name line1">{{item.name}}</view>
					<view class="address acea-row row-between-wrapper">
						<!-- <view class="iconfont icon-ic_location51"></view> -->
						<view class="info">{{item.address}}{{item.detailed_address}}</view>
						<view class="acea-row items-baseline fs-24 text--w111-333 ml-20">
							<view class="iconfont icon-ic_location4"></view>
							<view>{{item.range}}km</view>
						</view>
					</view>
					<view class="time acea-row row-middle">
						<!-- <view class="iconfont icon-icon_clock"></view> -->
						<view>营业时间：{{item.day_time}}</view>
					</view>
					<view class="iconfont icon-xuanzhong6" v-if="active == index"></view>
				</view>
			</view>
		</view>
		<addressWindow ref="addressWindow" :pagesUrl="pagesUrl" :fromType="1" :address="address" :zIndex="iScart?1000:990" @changeClose="changeClose" @OnChangeAddress="OnChangeAddress">
		</addressWindow>
	</view>
</template>

<script>
	let windowHeight = (uni.getSystemInfoSync().windowHeight*(3/4)-238)*2;
	import addressWindow from '@/components/addressWindow';
	import {getAddressDefault} from '@/api/user.js'
	import {storeListApi} from '@/api/store.js'
	import {
		mapGetters,
		mapState
	} from 'vuex';
	export default {
		computed: {
			...mapState({
				nearbyStore: state => state.app.nearbyStore
			}),
			...mapGetters(['isLogin']),
			selfPickup(){
				let deliveryType = this.attr.deliveryType;
				let obj = deliveryType && deliveryType.includes('2') && this.selfStoreList.length
				return obj
			},
			deliveryType(){
				let deliveryType = this.attr.deliveryType;
				let on = ''
				if(deliveryType && deliveryType.includes('1') && this.selfPickup){
					on = 'on2'
				}else if((deliveryType && !deliveryType.includes('1') && this.selfPickup) || (deliveryType && deliveryType.includes('1') && !this.selfPickup)){
					on = 'on3'
				}else{
					on = 'on1'
				}
				return on
			}
		},
		components:{
		  addressWindow
		},
		props: {
			isStoreBuy: {
				type: Number,
				value: 0
			},
			// 商品id
			productId: {
				type: [Number, String],
				value: 0
			},
			attr: {
				type: Object,
				default: () => ({})
			},
			storeInfo: {
				type: Object,
				default: () => ({})
			},
			limitNum: {
				type: Number,
				value: 0
			},
			isShow: {
				type: Number,
				value: 0
			},
			iSbnt: {
				type: Number,
				value: 0
			},
			iSplus: {
				type: Number,
				value: 0
			},
			iScart: {
				type: Number,
				value: 0
			},
			type: {
				type: [Number, String],
				default: 0
			},
			fangda: {
				type: Boolean,
				default: true
			},
			isExtends:{
				type: Boolean,
				default: false
			},
			storeId:{
				type: [Number, String],
				value: 0
			}
		},
		data() {
			return {
				flag:1,
				nameInfo:'', //地址用户信息
				addressInfo:'', // 商城快递
				deliveryName:'', // 门店配送
				distance:'',
				deliveryAddress:'',
				address: {
					address: false
				},
				pagesUrl:'',
				user_latitude: 0,
				user_longitude: 0,
				isDelivery: false,
				storeList: [],
				deliveryStoreList: [],
				selfStoreList: [],
				active:0,
				isStore: false,
				windowHeight:windowHeight,
				isAddress:false,
				shopType: uni.getStorageSync('shop_operation_type'), //1：多门店+平台；2：单门店+平台；3、单门店
				belongShow: uni.getStorageSync('belongShow'),
				gridShow: true,
			};
		},
		watch: {
			'attr.deliveryType'(newValue, oldValue) {
				if (JSON.stringify(newValue) != JSON.stringify(oldValue)) {
					if (newValue.length) {
						if(this.active<1){
							this.getList();
						}
						let num = 1;
						if(newValue[0] == 1 && this.isStoreBuy){
							num = newValue[1];
						}else{
							num = newValue[0];
						}
						this.flag = num
						this.$emit('deliveryFun',num);
					}
				}
			},
			'storeId':{
				handler(){
					this.defaultStore();
					this.$emit('onstoreId',this.storeList[this.active],this.flag);
				},
				deep: true
			}
		},
		mounted() {
			try {
				this.user_latitude = uni.getStorageSync('user_latitude');
				this.user_longitude = uni.getStorageSync('user_longitude');
			} catch (e) {}
			uni.$off('activeAddress');
			uni.$on('activeAddress', data => {
				this.getAddressDefault();
			})
		},
		methods: {
			closeStore(){
				this.isStore = false;
			},
			openStore(){
				if(this.attr.isType != 1 && this.shopType == 1 && this.belongShow){
					this.isStore = true;
				}
			},
			// 切换门店
			tapStore(index,item){
				this.active = index;
				this.deliveryName = item.name;
				this.deliveryAddress = item.detailed_address+'\xa0';
				this.distance = '距您'+item.range+'km';
				this.isStore = false;
				this.$emit('onstoreId',item,this.flag);
			},
			// 切换地址
			OnChangeAddress: function(e,row) {
			  this.address.address = false;
			    this.nameInfo = row.real_name+'\xa0\xa0'+row.phone
				this.addressInfo = row.province+'省'+row.city+row.district+row.street+row.detail
				this.$emit('onAddressId',row)
			},
			// 打开地址弹窗
			openAddress(){
				let pagesUrl = '/pages/users/user_address/index?fromType=1';
				if(!this.isAddress){
					this.$refs.addressWindow.getAddressList();
					this.address.address = true;
					this.pagesUrl = pagesUrl
				}else{
					uni.navigateTo({
						url: pagesUrl
					});
				}
			},
			// 关闭地址弹窗；
			changeClose: function() {
			  this.$set(this.address, 'address', false);
			},
			// 配送地址
			getAddressDefault() {
				getAddressDefault().then(res=>{
					let data = res.data;
					this.isAddress = Array.isArray(data);
					if(!Array.isArray(data)){
						this.nameInfo = data.real_name+'\xa0\xa0'+data.phone
						this.addressInfo = data.province+'省'+data.city+data.district+data.street+data.detail
						this.$emit('onAddressId',data)
					}else{
						this.addressInfo = '点击添加地址'
					}
				}).catch(err=>{
				   this.$util.Tips({
					   title: err
				   })
				})
			},
			selfLocation() {
				let self = this
				// #ifdef H5
				if (self.$wechat.isWeixin()) {
					self.$wechat.location().then(res => {
						this.user_latitude = res.latitude;
						this.user_longitude = res.longitude;
						uni.setStorageSync('user_latitude', res.latitude);
						uni.setStorageSync('user_longitude', res.longitude);
						self.getList();
					})
				} else {
					// #endif
					uni.getLocation({
						type: 'wgs84',
						success: (res) => {
							try {
								this.user_latitude = res.latitude;
								this.user_longitude = res.longitude;
								uni.setStorageSync('user_latitude', res.latitude);
								uni.setStorageSync('user_longitude', res.longitude);
							} catch {}
							self.getList();
						},
						fail:(res)=>{
							console.log(res)
							// #ifdef MP
							uni.getSetting({
								success: res=>{
									if(typeof(res.authSetting['scope.userLocation']) != 'undefined' && !res.authSetting['scope.userLocation']){
									  uni.setStorageSync('refuseLocation', true);
									}
								}
							})
							// #endif
						}
						// complete: function() {
						// 	self.getList();
						// }
					});
					// #ifdef H5
				}
				// #endif
			},
			getList: function(id) {
				let data = {
					latitude: this.user_latitude || "", //纬度
					longitude: this.user_longitude || "", //经度
					page: 1,
					limit: 100,
					product_id: this.productId,
					is_store:'',   //查找所有门店列表
					type: this.type == 1?1:0,
					// store_id: id?id:this.nearbyStore
				};
				storeListApi(data)
					.then(res => {
						let list = res.data.list.list;
						// if(!id && list.length && this.attr && (this.attr.deliveryType.indexOf('2') != -1 || this.attr.deliveryType.indexOf('3') != -1)){
						// 	this.isDelivery = true;
						// 	if(this.$store.getters.isLogin){
						// 		this.getAddressDefault()
						// 	}
						// }
						if(!id && list.length){
							this.isDelivery = true;
							if(this.$store.getters.isLogin){
								this.getAddressDefault()
							}
						}
						// selfStoreList拆分核销门店列表和deliveryStoreList配送门店列表
						let selfStoreList = [],deliveryStoreList = [];
						list.forEach(function(item,index){
							// 门店配置里面 3是同城配送 2是到店自提（后端定义）1是快递发货
							// if(item.delivery_type != 2) selfStoreList.push(item);
							// if(item.delivery_type != 3) deliveryStoreList.push(item);
							if (item.delivery_type.includes('2')) {
								selfStoreList.push(item);
							}
							if (item.delivery_type.includes('1') || item.delivery_type.includes('3')) {
								deliveryStoreList.push(item);
							}
						});
						// 商品里面 3是门店配送 2是到店核销（后端定义）
						this.storeList = this.flag==2?selfStoreList:this.flag==3?deliveryStoreList:list;
						this.selfStoreList = selfStoreList;//到店核销
						this.deliveryStoreList = deliveryStoreList;//门店配送
						this.defaultStore();
						this.$emit('onstoreId',this.storeList[this.active],this.flag);
						this.$emit('storeCount',res.data.list.count);
					})
					.catch(err => {
						this.$util.Tips({
							title: err
						})
					});
			},
			defaultStore(){
				let storeId = this.storeId?this.storeId:this.nearbyStore
				let storeIndex = 0
				this.storeList.forEach((item,index)=>{
					if(item.id == storeId){
						storeIndex = index
					}
				})
				this.active = storeIndex;
				this.deliveryName = this.storeList[storeIndex].name;
				this.deliveryAddress = this.storeList[storeIndex].detailed_address+'\xa0';
				this.distance = '距您'+this.storeList[storeIndex].range+'km';
			},
			// 选择配送方式
			getstoreInfo(index) {
				// if(index == 1 && this.isStoreBuy){
				// 	return false
				// }
				if(index === '2' || index === '3'){
					if ((!this.user_latitude || !this.user_longitude) && !uni.getStorageSync('refuseLocation')) {
						this.selfLocation();
					}
				}
				if(index === '2') this.storeList = this.selfStoreList; //到店核销
				if(index === '3') this.storeList = this.deliveryStoreList; //门店配送
				this.flag = index;
				this.defaultStore();
				this.$emit('deliveryFun',index);
				this.$emit('onstoreId',this.storeList[this.active],index);
			},
			showLocation(){
				if ((!this.user_latitude || !this.user_longitude) && !uni.getStorageSync('refuseLocation')) {
					this.selfLocation();
				}
			},
			moveHandle(){},
			goCat: function() {
				this.$emit('goCat');
			},
			/**
			 * 购物车手动输入数量
			 *
			 */
			bindCode: function(e) {
				this.$emit('iptCartNum', this.attr.productSelect.cart_num);
			},
			closeAttr: function() {
				this.$emit('myevent');
			},
			CartNumDes: function() {
				this.$emit('ChangeCartNum', false);
			},
			CartNumAdd: function() {
				this.$emit('ChangeCartNum', true);
			},
			tapAttr: function(indexw, indexn) {
				let that = this;
				that.$emit("attrVal", {
					indexw: indexw,
					indexn: indexn
				});
				this.$set(this.attr.productAttr[indexw], 'index', this.attr.productAttr[indexw].attr_values[indexn]);
				let value = that
					.getCheckedValue()
					.join(",");
				that.$emit("ChangeAttr", value);

			},
			//获取被选中属性；
			getCheckedValue: function() {
				let productAttr = this.attr.productAttr;
				let value = [];
				for (let i = 0; i < productAttr.length; i++) {
					for (let j = 0; j < productAttr[i].attr_values.length; j++) {
						if (productAttr[i].index === productAttr[i].attr_values[j]) {
							value.push(productAttr[i].attr_values[j]);
						}
					}
				}
				return value;
			},
			showImg() {
				this.$emit('getImg');
			},
			cartConfirm(){
				this.$emit('onConfirm');
			},
			toggleGridAttr(){
				this.gridShow = !this.gridShow;
			},
			proviewImg(img){
				uni.previewImage({
					current: 0,
					urls: [img]
				});
			}
		}
	}
</script>

<style scoped lang="scss">
	.mask{
		&.on{
			z-index: 100;
		}
	}
	.vip-money {
		color: #282828;
		font-size: 28rpx;
		font-weight: 700;
		margin-left: 6rpx;
	}

	.vipImg {
		width: 56rpx;
		height: 20rpx;
		margin-left: 6rpx;

		image {
			width: 100%;
			height: 100%;
			display: block;
		}
	}

	.product-window {
		position: fixed;
		bottom: 0;
		width: 100%;
		left: 0;
		background-color: #fff;
		z-index: 100;
		border-radius: 40rpx 40rpx 0 0;
		transform: translate3d(0, 100%, 0);
		transition: all .3s cubic-bezier(.25, .5, .5, .9);
		padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
		padding-bottom: env(safe-area-inset-bottom);///兼容 IOS>11.2/

		&.store{
			background-color: #F5F5F5;
			border-radius: 40rpx 40rpx 0 0;
			transform: translate3d(0, 0, 0);
			z-index: 102;
			margin: 0;
		}
		.storeTitle{
			text-align: center;
			height: 100rpx;
			line-height: 100rpx;
			font-size: 32rpx;
			font-weight: 500;
			color: #282828;
			position: relative;
			.iconfont{
				position: absolute;
				font-size: 35rpx;
				right: 30rpx;
			}
		}
		.storeList{
			height: 860rpx;
			overflow: auto;
			.item{
				width: 690rpx;
				background: #FFFFFF;
				border-radius: 12rpx;
				margin: 0 auto 20rpx auto;
				padding: 28rpx 30rpx;
				border: 1px solid #fff;
				position: relative;
				&.on{
					border-color: var(--view-theme);
				}
				.icon-xuanzhong6{
					position: absolute;
					right: -6rpx;
					bottom: -6rpx;
					color: var(--view-theme);
					font-size: 54rpx;
				}
				.name{
					font-weight: 500;
					color: #333333;
					font-size: 28rpx;
				}
				.time{
					font-weight: 400;
					color: #999;
					font-size: 24rpx;
					margin-top: 16rpx;
					.iconfont{
						font-size: 20rpx;
						margin-right: 8rpx;
					}
				}
				.address{
					font-weight: 400;
					color: #999;
					font-size: 24rpx;
					margin-top: 20rpx;
					.iconfont{
						font-size: 26rpx;
						margin-right: 8rpx;
					}
					.info{
						flex: 1;
						line-height: 1.5;
					}
				}
			}
		}
	}

	.product-window.on {
		transform: translate3d(0, 0, 0);
	}

	.product-window.join {
		padding-bottom: 30rpx;
	}

	.product-window.joinCart {
		padding-bottom: 30rpx;
		z-index: 1000;
	}

	.product-window .textpic {
		padding: 0 32rpx;
		margin-top: 48rpx;
		position: relative;
		.close{
			position: absolute;
			right: 38rpx;
			top: -14rpx;
		}
	}

	.product-window .textpic .pictrue {
		width: 180rpx;
		height: 180rpx;
		position: relative;
		.icon{
			width: 30rpx;
			height: 30rpx;
			background-color: rgba(0,0,0,0.4);
			border-radius: 4rpx;
			position: absolute;
			bottom: 8rpx;
			right: 8rpx;
			text-align: center;
			line-height: 23rpx;
			.iconfont{
				color: #fff;
				font-size: 20rpx;
			}
		}
	}

	.product-window .textpic .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 16rpx;
	}

	.product-window .textpic .text {
		width: 410rpx;
		font-size: 32rpx;
		color: #202020;
	}

	.product-window .textpic .text .money {
		font-size: 24rpx;
		margin-top: 40rpx;
		.icon{
			display: inline-block;
			font-size: 16rpx;
			font-weight: normal;
			background: #FF9500;
      color: #fff;
			border-radius: 18rpx;
			padding: 2rpx 6rpx;
			margin-left: 10rpx;
			.iconfont{
				font-size: 16rpx;
				margin-right: 4rpx;
				color: #fff;
			}
		}
	}

	.product-window .textpic .text .money .num {
		font-size: 36rpx;
	}

	.product-window .textpic .text .money .stock {
		color: #999;
		margin-left: 6rpx;
	}

	.product-window .textpic .icon-guanbi {
		position: absolute;
		right: 30rpx;
		top: -5rpx;
		font-size: 35rpx;
		color: #8a8a8a;
	}

	.product-window .rollTop {
		margin-top: 36rpx;
	}
	.product-window .productWinList{
		.address{
			width: 690rpx;
			background: #F5F5F5;
			padding: 24rpx 38rpx 24rpx 20rpx;
			margin: 28rpx auto;
			font-weight: 400;
			color: #333333;
			font-size: 28rpx;
			border-radius: 8rpx;
			position: relative;
			&::before{
				position: absolute;
				content: '';
				top:-10rpx;
				width: 0;
				height: 0;
				border-left: 14rpx solid transparent;
				border-right: 14rpx solid transparent;
				border-bottom: 12rpx solid #F5F5F5;
			}
			&.on1{
				&::before{
					left:78rpx;
				}
			}
			&.on2{
				&::before{
					left: 480rpx;
				}
			}
			&.on3{
				&::before{
					left:280rpx;
				}
			}
			.info{
				font-weight: 400;
				color: #999999;
				font-size: 24rpx;
				margin-top: 12rpx;
				.con{
					width: 416rpx;
				}
			}
		}
	}
	.product-window .productWinList .item~.item {
		margin-top: 36rpx;
	}

	.product-window .productWinList .item .title {
		font-size: 28rpx;
		font-weight: 500;
		color: #333;
		padding: 0 32rpx;
	}

	.product-window .productWinList .item .listn {
		padding: 0 32rpx 0 16rpx;
	}

	.product-window .productWinList .item .listn .itemn {
		// height: 56rpx;
		// line-height: 56rpx;
		border: 1rpx solid #F2F2F2;
		font-size: 24rpx;
		color: #333;
		padding: 10rpx 44rpx;
		border-radius: 28rpx;
		margin: 24rpx 0 0 16rpx;
		background-color: #F2F2F2;
		word-break: break-all;
	}

	.product-window .productWinList .item .listn .itemn.on {
		color: var(--view-theme);
		background: var(--view-minorColorT);
		border-color: var(--view-theme);
	}

	.product-window .productWinList .item .listn .itemn.limit {
		color: #999;
		text-decoration: line-through;
	}

	.product-window .cart {
		padding: 0 30rpx;
	}

	.product-window .cart .title {
		font-size: 28rpx;
		color: #333;
	}

	.product-window .cart .carnum {
		height: 54rpx;
		margin-top: 24rpx;
	}

	.product-window .cart .carnum .iconfont {
		font-size: 25rpx;
	}

	.product-window .cart .carnum view {
		// border: 1px solid #a4a4a4;
		width: 84rpx;
		text-align: center;
		height: 100%;
		line-height: 54rpx;
		color: #282828;
		font-size: 45rpx;
	}

	.product-window .cart .carnum .reduce {
		border-right: 0;
		border-radius: 6rpx 0 0 6rpx;
		line-height: 48rpx;
		font-size: 60rpx;
	}

	.product-window .cart .carnum .reduce.on {
		// border-color: #e3e3e3;
		color: #DEDEDE;
	}

	.product-window .cart .carnum .plus {
		border-left: 0;
		border-radius: 0 6rpx 6rpx 0;
		line-height: 46rpx;
	}

	.product-window .cart .carnum .plus.on {
		// border-color: #e3e3e3;
		color: #dedede;
	}

	.product-window .cart .carnum .num {
		background: rgba(242, 242, 242, 1);
		color: #282828;
		font-size: 28rpx;
		border-radius: 4rpx;
	}

	.product-window .joinBnt {
		font-size: 28rpx;
		width: 710rpx;
		height: 80rpx;
		border-radius: 40rpx;
		text-align: center;
		line-height: 80rpx;
		color: #fff;
		margin: 74rpx auto 12rpx;
	}

	.product-window .joinBnt.on {
		background-color: #bbb;
		color: #fff;
	}
	.mt-74{
		margin-top: 74rpx;
	}
	.join_cart{
		background-color: var(--view-bntColor);
	}
	.bg-gray{
		background-color: #CCCCCC;
	}
	.product-window .productWinList .item .active {
		color: var(--view-theme) !important;
		background: var(--view-minorColorT) !important;
		border-color: var(--view-theme) !important;
	}
	.grid-item-box{
		width: 196rpx;
		border: 2rpx solid #F5F5F5;
		border-radius: 8rpx;
		image{
			border-radius: 8rpx 8rpx 0 0;
		}
		.tname{
			width: 196rpx;
			height: 58rpx;
			border-radius: 0 0 8rpx 8rpx ;
			padding: 0 12rpx;
		}
	}
	.grid-active{
		border: 2rpx solid var(--view-theme);
		.tname{
			color: var(--view-theme);
			background: var(--view-minorColorT);
		}
	}
	.cell-itemn{
		line-height: 56rpx;
		border: 1rpx solid #F2F2F2;
		font-size: 24rpx;
		color: #333;
		padding: 0 20rpx 0 4rpx;
		border-radius: 30rpx;
		margin: 24rpx 0 0 16rpx;
		background-color: #F2F2F2;
		word-break: break-all;
	}
	.proview-icon{
		position: absolute;
		top: 8rpx;
		right: 8rpx;
		width: 36rpx;
		height: 36rpx;
		background: #DDDDDD;
		border-radius: 50%;
	}
</style>
