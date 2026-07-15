<template>
	<view>
		<base-drawer mode="bottom" :visible="attr.cartAttr" :zIndex="zIndex" :maskZIndex="zIndex" background-color="transparent" mask maskClosable
			@close="closeAttr">
			<view class="scroll-content bg--w111-fff rd-t-40rpx">
				<view class="w-full pt-32">
					<view class="px-32 flex">
						<image class="w-180 h-180 rd-16rpx" :src="attr.productSelect.image"></image>
						<view class="pl-24">
							<baseMoney :money="attr.productSelect.price" symbolSize="32" integerSize="48"
								decimalSize="32" color="#FF7E00" weight></baseMoney>
							<view class="mt-20 fs-24 text--w111-999">库存:{{ attr.productSelect.stock }}</view>
						</view>
					</view>
				</view>
				<view class="px-32">
					<scroll-view scroll-y="true" :style="'max-height: '+windowHeight+'rpx'" >
						<view class="item mt-32" v-if="attr.deliveryType.length">
							<view class="fs-28 fw-500">配送方式</view>
							<view class="flex-y-center flex-wrap">
								<view v-if="attr.deliveryType.includes('1')" :class="{ active: flag == 1 }" class="sku-item" @click="tapDelivery('1')">商城配送</view>
								<view v-if="attr.deliveryType.includes('2')" :class="{ active: flag == 2 }" class="sku-item" @click="tapDelivery('2')">到店核销</view>
								<view v-if="attr.deliveryType.includes('3')" :class="{ active: flag == 3 }" class="sku-item" @click="tapDelivery('3')">门店配送</view>
							</view>
						</view>
						<view class="address on1" v-if="flag == 1" @click="openAddress">
							<view class="acea-row row-middle">
								<view class="max-w-596 line1">{{addressInfo || '请添加地址'}}</view>
								<view class="iconfont icon-ic_rightarrow ml-8 fs-28"></view>
							</view>
							<view class="info">{{nameInfo}}</view>
						</view>
						<view class="address on2" v-if="flag == 3" @click="openStore">
							<view class="acea-row row-middle">
								<view class="max-w-596 line1">{{deliveryName}}</view>
								<view class="iconfont icon-ic_rightarrow ml-8 fs-28" v-if="attr.isType != 1"></view>
							</view>
							<view class="info">
								<text class="iconfont icon-ic_location51 fs-24 mr-10"></text>{{deliveryAddress}}
							</view>
						</view>
						<view class="address on3" v-if="flag == 2"  @click="openStore">
							<view class="acea-row row-middle">
								<view class="max-w-596 line1">{{deliveryName}}</view>
								<view class="iconfont icon-ic_rightarrow ml-8 fs-28" v-if="attr.isType != 1"></view>
							</view>
							<view class="info">
								<text class="iconfont icon-ic_location51 fs-24 mr-10"></text>{{deliveryAddress}}
							</view>
						</view>
						<template v-if="attr.productSelect.spec_type">
							<view class="item mt-32" v-for="(item, indexw) in attr.productAttr" :key="indexw">
								<view class="fs-28 fw-500">{{ item.attr_name }}</view>
								<view class="flex-y-center flex-wrap">
									<view class="sku-item" :class="item.index === itemn.attr ? 'active' : ''"
										v-for="(itemn, indexn) in item.attr_value" @click="tapAttr(indexw, indexn)"
										:key="indexn">
										{{ itemn.attr }}
									</view>
								</view>
							</view>
						</template>
					</scroll-view>
				</view>
				<view class="flex-between-center mt-40 px-32">
					<view class="fs-28 fw-500">数量</view>
					<view class="flex-1 flex justify-end items-center">
						<text class="fs-22 text-primary">（最多可购买{{attr.productSelect.stock}}件）</text>
						<view class="flex-y-center pl-24">
							<text class="iconfont icon-ic_Reduce fs-24"
								:class="attr.productSelect.cart_num <= 1 ? 'text--w111-f5f5f5' : ''"
								@tap="CartNumDes"></text>
							<input type="number" v-model="attr.productSelect.cart_num"
								data-name="productSelect.cart_num" :always-embed="true" :adjust-position="true" cursor-spacing="30"
								@input="bindCode(attr.productSelect.cart_num)" class="w-88 h-44 rd-4rpx bg--w111-f5f5f5 fs-24 text-center mx-10"></input>
							<text class="iconfont icon-ic_increase fs-24" @tap="CartNumAdd"></text>
						</view>
					</view>
				</view>
				<view class="btn-box" v-show="attr.productSelect.stock == 0">
					<view class="w-full h-72 rd-40rpx flex-center fs-26 bg--w111-ccc text--w111-fff">已售罄</view>
				</view>
				<view class="btn-box flex-between-center" v-show="attr.productSelect.stock > 0 && !okButton">
					<view class="w-346 h-72 rd-40rpx flex-center fs-26 con_border text-primary"
					:class="{'disabled': cartButton == 0}"
					 @tap="goCart(0)">加入购物车</view>
					<view class="w-346 h-72 rd-40rpx flex-center fs-26 bg-primary text--w111-fff" @tap="goCart(1)">立即下单</view>
				</view>
				<view class="btn-box" v-show="attr.productSelect.stock > 0 && okButton">
					<view class="w-full h-72 rd-40rpx flex-center fs-26 bg-primary text--w111-fff" @tap="goCart(0)">确定</view>
				</view>
			</view>
		</base-drawer>
		<base-drawer mode="bottom" :visible="isStore" :zIndex="zIndex + 1" :maskZIndex="zIndex + 1" backgroundColor="transparent" @close="closeStore">
			<view class="product-window store">
				<view class="storeTitle">选择门店<text class="iconfont icon-guanbi5" @click="closeStore"></text></view>
				<scroll-view scroll-y="true" class="scroll-view">
					<view class="storeList">
						<view class="item" :class="active == index?'on':''" v-for="(item,index) in storeList" :key="index" @click="tapStore(index,item)">
							<view class="name line1">{{item.name}}</view>
							<view class="address acea-row row-between">
								<view class="iconfont icon-ic_location51"></view>
								<view class="info">{{item.address}}</view>
							</view>
							<view class="time acea-row row-middle">
								<view class="iconfont icon-icon_clock"></view>
								<view>营业时间：{{item.day_time}}</view>
							</view>
							<view class="iconfont icon-xuanzhong6" v-if="active == index"></view>
						</view>
					</view>
				</scroll-view>
			</view>
		</base-drawer>
		<addressWindow ref="addressWindow" :userId="userId" :pagesUrl="pagesUrl" :fromType="1" :address="address" @changeClose="changeClose" @OnChangeAddress="OnChangeAddress"></addressWindow>
	</view>
</template>
<script>
	import baseDrawer from '@/components/tui-drawer/tui-drawer.vue'
	import addressWindow from '@/components/addressWindow/index.vue'
	import { storeListApi } from '@/api/store.js'
	import { adminUserAddressList } from '@/api/admin.js'
	let windowHeight = (uni.getSystemInfoSync().windowHeight*(3/4)-238)*2;
	export default {
		name:'skuSelect',
		props:{
			userId:{
				type: [Number, String],
				default: 0
			},
			// 商品id
			productId: {
				type: Number,
				value: 0
			},
			attr: {
				type: Object,
				default: () => ({})
			},
			cartButton:{
				type: Number,
				default: 1
			},
			zIndex:{
				type: Number,
				default: 11
			},
			okButton:{
				type: Boolean,
				default: false
			},
		},
		data(){
			return {
				windowHeight:windowHeight,
				flag: 0,
				nameInfo: '', //地址用户信息
				addressInfo: '', // 商城快递
				deliveryName:'', // 门店配送
				deliveryAddress:'',
				pagesUrl:'',
				address: {
					address: false
				},
				active:0,
				storeList: [],
				isStore: false
			}
		},
		watch: {
			'attr.deliveryType'(newValue, oldValue) {
				if (JSON.stringify(newValue) != JSON.stringify(oldValue)) {
					if (newValue.length) {
						if(this.active<1){
							this.getList();
						}
						let num = 1;
						if (newValue.includes('1')) {
							num = '1';
						} else if (newValue.includes('2')) {
							num = '2';
						} else {
							num = '3';
						}
						this.flag = num
						this.$emit('deliveryFun',num);
					}
				}
			},
			flag(val) {
				if (val == 1) {
					this.$refs.addressWindow.getAddressList();
				}
			},
		},
		components:{
			baseDrawer,
			addressWindow
		},
		mounted() {
			uni.$on('refresh', (data) => {
				this.nameInfo = data.real_name+'\xa0\xa0'+data.phone
				this.addressInfo = data.address.province+'省'+data.address.city+data.address.district+data.address.street+data.detail
				this.$emit('onAddressId',data)
			});
		},
		methods:{
			goCart(type) {
				if(type == 0 && this.cartButton == 0) return
				if(this.attr.productSelect.stock == 0) return
				this.$emit('goCat',type);
			},
			bindCode: function(e) {
				this.$emit('iptCartNum', this.attr.productSelect.cart_num);
			},
			closeAttr: function() {
				this.active = 0;
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
			tapDelivery(value) {
				this.flag = value;
				this.active = 0;
				const storeItem = this.storeList[0];
				this.deliveryName = storeItem.name;
				this.deliveryAddress = storeItem.detailed_address+'\xa0';
				this.$emit('deliveryFun',value);
				this.$emit('onstoreId',value == 1 ? 0 : storeItem.id,value);
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
				this.address.address = true;
				this.pagesUrl = '/pages/users/user_address/addClient'
			},
			// 关闭地址弹窗；
			changeClose: function() {
			  this.$set(this.address, 'address', false);
			},
			getList: function(id) {
				let data = {
					page: 1,
					limit: 100,
					product_id: this.productId,
					is_store:'',   //查找所有门店列表
				};
				storeListApi(data)
					.then(res => {
						let list = res.data.list.list;
						// 拆分到店核销门店列表和配送门店列表
						let selfStoreList = [],deliveryStoreList = [];
						list.forEach(function(item,index){
							if(item.delivery_type != 2) selfStoreList.push(item);
							if(item.delivery_type != 3) deliveryStoreList.push(item);
						});
						this.storeList = this.flag==2?selfStoreList:this.flag==3?deliveryStoreList:list;
						this.selfStoreList = selfStoreList;//到店核销
						this.deliveryStoreList = deliveryStoreList;//门店配送
						this.active = 0;
						const storeItem = this.storeList[0];
						this.deliveryName = storeItem.name;
						this.deliveryAddress = storeItem.detailed_address+'\xa0';
						this.$emit('onstoreId',this.flag == 1 ? 0 : storeItem.id,this.flag);
					})
					.catch(err => {
						this.$util.Tips({
							title: err
						})
					});
			},
			// 配送地址
			getAddressList() {
			  adminUserAddressList(this.userId, { page: 1, limit: 10 }).then(res=>{
				  let data = res.data[0];
				  if(res.data.length){
					 this.nameInfo = data.real_name+'\xa0\xa0'+data.phone
				  	 this.addressInfo = data.province+'省'+data.city+data.district+data.street+data.detail
				  	 this.$emit('onAddressId',data)
				  }else{
				  	this.addressInfo = '点击添加地址'
				  }
			  })
			},
			closeStore(){
				this.isStore = false;
			},
			openStore(){
				if(this.attr.isType != 1){
					this.isStore = true;
				}
			},
			// 切换门店
			tapStore(index,item){
				this.active = index;
				this.deliveryName = item.name;
				this.deliveryAddress = item.detailed_address+'\xa0';
				this.isStore = false;
				this.$emit('onstoreId',item.id,this.flag);
			},
		}
	}
</script>


<style lang="scss" scoped>
	.sku-item {
		height: 56rpx;
		line-height: 56rpx;
		border: 1rpx solid #F2F2F2;
		font-size: 24rpx;
		color: #333;
		padding: 0 44rpx;
		border-radius: 28rpx;
		margin: 24rpx 0 0 16rpx;
		background-color: #F2F2F2;
		word-break: break-all;
	}

	.active {
		color: $primary-admin;
		background: $light-primary-admin;
		border-color: $primary-admin;
	}

	.text-primary {
		color: $primary-admin;
	}
	.bg-primary{
		background: $primary-admin;
	}
	.con_border {
		border: 1rpx solid $primary-admin;
	}
	.btn-box{
		padding:0 20rpx calc(20rpx + env(safe-area-inset-bottom));
		margin: 78rpx auto 0;
	}
	.disabled{
		background-color: #ccc;
		color: #fff;
		border: 1rpx solid #ccc;
	}
	.scroll-content .address{
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
			top:-12rpx;
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
	.product-window {
		padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
		padding-bottom: env(safe-area-inset-bottom);///兼容 IOS>11.2/
		
		&.store{
			background-color: #F5F5F5;
			border-radius: 40rpx 40rpx 0 0;
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
		.scroll-view{
			max-height: 690rpx;
		}
		.storeList{
			.item{
				width: 690rpx;
				background: #FFFFFF;
				border-radius: 12rpx;
				margin: 0 auto 20rpx auto;
				padding: 28rpx 30rpx;
				border: 1px solid #fff;
				position: relative;
				&.on{
					border-color: #2A7EFB;
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
					color: #888888;
					font-size: 22rpx;
					margin-top: 15rpx;
					.iconfont{
						font-size: 20rpx;
						margin-right: 8rpx;
					}
				}
				.address{
					font-weight: 400;
					color: #888888;
					font-size: 22rpx;
					margin-top: 13rpx;
					.iconfont{
						font-size: 20rpx;
						margin-right: 8rpx;
						margin-top: 6rpx;
					}
					.info{
						width: 590rpx;
						line-height: 1.5;
					}
				}
			}
		}
	}
	::v-deep .font-num{
		color: #2A7EFB !important;
	}
	::v-deep .bg-color{
		background: #2A7EFB !important;
	}
</style>
