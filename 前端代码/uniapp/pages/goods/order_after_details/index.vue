<template>
	<view  :style="colorStyle">
		<view class="px-20">
			<view class="flex justify-between mt-24">
				<view class="flex-1 mt-8">
					<view class="fs-36 fw-500 lh-50rpx">{{orderInfo._status._msg}}</view>
					<view class="w-486 fs-24 text--w111-999 lh-34rpx mt-12">{{orderInfo._status.desc}}</view>
				</view>
				<image :src="orderInfo._status.pic" class="w-172 h-172"></image>
			</view>
			<statusLine :type="orderInfo._status._type" :applyType="orderInfo.apply_type"></statusLine>
			<view class="bg--w111-fff rd-16rpx mt-20 pt-32 pl-24 pr-24 pb-32"
				v-if="[2,3].includes(orderInfo.apply_type)">
				<view class="flex-between-center">
					<text class="fs-32 fw-500">商家地址</text>
					<text class="inline-block copy_btn fs-22" @click="copyAddress">复制</text>
				</view>
				<view class="mt-28 fs-28 lh-40rpx">
					<text>{{orderInfo._status.refund_name}}</text>
					<text class="pl-20">{{orderInfo._status.refund_phone}}</text>
				</view>
				<view class="w-full fs-24 lh-34rpx text--w111-999 mt-8 line2">{{orderInfo._status.refund_address}}</view>
			</view>
			<view class="bg--w111-fff rd-16rpx mt-20 pt-32 pl-24 pr-24">
				<view class="flex justify-between order-goods" v-for="(item,index) in cartInfo" :key="index">
					<image class="w-136 h-136 rd-16rpx" :src='item.productInfo.attrInfo.image' v-if="item.productInfo.attrInfo"></image>
					<image class="w-136 h-136 rd-16rpx" :src='item.productInfo.image' v-else></image>
					<view class="flex-1 h-136 pl-20 flex-col justify-between">
						<view class="w-full fs-28 lh-40rpx line2">{{item.productInfo.store_name}}</view>
						<view class="flex-between-center fs-24 lh-34rpx">
							<text class="text--w111-999">购买数量: {{item.cart_num}}</text>
							<text>购买售价：{{item.productInfo.attrInfo ? item.productInfo.attrInfo.price : item.productInfo.price}}</text>
						</view>
					</view>
				</view>
				<!-- #ifdef H5 || APP-PLUS -->
				<view class="border-t-EEEEEE mt-32 h-88 lh-82rpx text-center" @click="goGoodCall">
					<text class="iconfont icon-ic_customerservice mr-16"></text><text>联系客服</text>
				</view>
				<!-- #endif -->
				<!-- #ifdef MP -->
				<view class="border-t-EEEEEE mt-32 h-88 flex flex-center" @click="goGoodCall" v-if='routineContact == 0'>
					<button hover-class='none'>
						<text class="iconfont icon-ic_customerservice mr-16"></text><text class="fs-28">联系客服</text>
					</button>
				</view>
				<view class="border-t-EEEEEE mt-32 h-88 flex flex-center fs-28" v-else>
					<button hover-class='none' open-type='contact'>
						<text class="iconfont icon-ic_customerservice mr-16"></text><text class="fs-28">联系客服</text>
					</button>
				</view>
				<!-- #endif -->
			</view>
			<view class="bg--w111-fff rd-16rpx mt-20 pt-32 pl-24 pr-24 pb-32">
				<view class="flex-between-center fs-28">
					<text>退款原因</text>
					<text>{{orderInfo.refund_reason}}</text>
				</view>
				<view class="pt-32 flex-between-center fs-28">
					<text>退款金额</text>
					<text class="font-color fs-36 Regular">¥{{parseFloat(orderInfo.refunded_price) || orderInfo.refund_price}}</text>
				</view>
				<view class="pt-32 flex-between-center fs-28">
					<text>申请件数</text>
					<text>{{orderInfo.refund_num}}</text>
				</view>
			</view>
			<customForm :customForm="orderInfo.custom_form" :customFormTitle='orderInfo.custom_form_title' :productType="orderInfo.product_type" :reservationTimeId="orderInfo.reservation_time_id"></customForm>
			<view class="bg--w111-fff rd-16rpx mt-20 pt-32 pl-24 pr-24 pb-32">
				<view class="cell flex-between-center">
					<text class="fs-28">订单编号</text>
					<view>
						<text class="fs-28 pr-12">{{orderInfo.store_order_sn}}</text>
						<text class="inline-block copy_btn fs-22" @click="copy(orderInfo.store_order_sn)">复制</text>
					</view>
				</view>
				<view class="cell flex-between-center fs-28">
					<text>申请时间</text>
					<text>{{orderInfo._add_time}}</text>
				</view>
				<view class="cell flex-between-center fs-28">
					<text>售后类型</text>
					<text v-if="orderInfo.apply_type == 1">仅退款</text>
					<text v-else-if="[2,3].includes(orderInfo.apply_type)">退货退款</text>
					<text v-else-if="orderInfo.apply_type == 4">商家主动退款</text>
				</view>
				<view class="cell flex-between-center">
					<text class="fs-28">售后单号</text>
					<view>
						<text class="fs-28 pr-12">{{orderInfo.order_id}}</text>
						<text class="inline-block copy_btn fs-22" @click="copy(orderInfo.order_id)">复制</text>
					</view>
				</view>
				<view class="bt mt-32">
					<view class="pt-32 flex justify-between fs-28" v-if="orderInfo.remark">
						<text>备注说明</text>
						<view class="w-462 line2 lh-40rpx text-right">{{orderInfo.remark}}</view>
					</view>
					<view class="pt-32 flex justify-between fs-28" v-if="orderInfo.refund_img && orderInfo.refund_img.length">
						<text>售后凭证</text>
						<view class="w-462 flex justify-end" v-if="orderInfo.refund_img.length < 5">
							<view class='pictrue mr-8' v-for="(item,index) in orderInfo.refund_img" :key="index">
							  <image class="w-88 h-88 rd-8rpx" :src='item' mode="aspectFill" ></image>
							</view>
						</view>
						<scroll-view scroll-x="true" scroll-with-animation
							class="white-nowrap vertical-middle w-462" show-scrollbar="false" v-else>
							<view class="inline-block mr-12" v-for="(item,index) in orderInfo.refund_img" :key="index">
								<image class="w-88 h-88 rd-8rpx" :src="item"></image>
							</view>
						</scroll-view>
					</view>
					<view class="pt-32 flex justify-between fs-28">
						<text>退款方式</text>
						<view class="w-462 line2 lh-40rpx text-right">{{orderInfo.refund_price_type}}</view>
					</view>
				</view>
			</view>
			<view class="bg--w111-fff rd-16rpx mt-20 pt-32 pl-24 pr-24 pb-32" v-if="[2,3].includes(orderInfo.apply_type) && orderInfo._status._type== 5">
				<view class="fs-28 fw-500">退货信息</view>
				<view class="bt mt-32">
					<view class="pt-32 flex justify-between fs-28">
						<text>退货方式</text>
						<view class="w-462 line2 lh-40rpx text-right">{{orderInfo.apply_type == 2 ? '快递退回' : '到店退货'}}</view>
					</view>
					<view class="pt-32 flex justify-between fs-28" v-if="orderInfo.apply_type == 2">
						<text>物流公司</text>
						<view class="w-462 line2 lh-40rpx text-right">{{orderInfo.refund_express_name}}</view>
					</view>
					<view class="pt-32 flex justify-between fs-28" v-if="orderInfo.apply_type == 2">
						<text>物流单号</text>
						<view class="w-462 line2 lh-40rpx text-right">{{orderInfo.refund_express}}</view>
					</view>
					<view class="pt-32 flex justify-between fs-28">
						<text>退货说明</text>
						<view class="w-462 line2 lh-40rpx text-right">{{orderInfo.refund_explain}}</view>
					</view>
					<view class="pt-32 flex justify-between fs-28" v-if="orderInfo.refund_goods_img.length">
						<text>售后凭证</text>
						<view class="w-462 flex justify-end" v-if="orderInfo.refund_goods_img.length < 5">
							<view class='pictrue mr-8' v-for="(item,index) in orderInfo.refund_goods_img" :key="index">
							  <image :src='item' mode="aspectFill" class="w-88 h-88 rd-8rpx"></image>
							</view>
						</view>
						<scroll-view scroll-x="true" scroll-with-animation
							class="white-nowrap vertical-middle w-462" show-scrollbar="false" v-else>
							<view class="inline-block mr-12" v-for="(item,index) in orderInfo.refund_goods_img" :key="index">
								<image class="w-88 h-88 rd-8rpx" :src="item"></image>
							</view>
						</scroll-view>
					</view>
					<view class="pt-32 flex justify-between fs-28">
						<text>联系电话</text>
						<view class="w-462 line2 lh-40rpx text-right">{{orderInfo.refund_phone}}</view>
					</view>

				</view>
			</view>
		</view>
		<view class="h-200"></view>
		<view class='fixed-lb bt w-full bg--w111-fff pb-safe'>
			<view class="h-96 px-20 flex-y-center justify-end">
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 border bg--w111-fff"
					v-if="orderInfo.refund_type == 6" @tap='showModalChange(1)'>删除订单</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 border bg--w111-fff"
					v-if="[0,1,2,4,5].includes(orderInfo.refund_type) && orderInfo.is_cancel == 0"
					@tap='showModalChange(2)'>撤销售后</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 text--w111-fff bg-color"
					v-if="orderInfo.refund_type == 4 && orderInfo.is_cancel == 0 && orderInfo.apply_type == 2"
					@tap='refundInput'>退回商品</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 text--w111-fff bg-color"
					v-if="[5,6].includes(orderInfo.refund_type) && orderInfo.apply_type == 2"
					@tap='refundLogistics'>返件信息</view>
				<view class="btn w-144 h-56 rd-30rpx flex-center fs-24 border bg--w111-fff"
					v-if="orderInfo.refund_type == 3 && orderInfo.is_cancel == 0" @click="refundAgain">再次申请</view>
			</view>
		</view>
		<!-- 确认框 -->
		<tuiModal
			:show="showModal"
			:title="modalTitle"
			:content="modalContent"
			:maskClosable="false"
			:confirmText="confirmText"
			@click="handleTap"
			@cancel="hideModal"></tuiModal>
	</view>
</template>

<script>
	import {
		getRefundOrderDetail,
		orderAgain,
		orderTake,
		cancelRefundOrder,
		orderRefundAgain,
		refundOrderDel
	} from '@/api/order.js';
	import { openOrderRefundSubscribe } from '@/utils/SubscribeMessage.js';
	import { getUserInfo } from '@/api/user.js';
	import ClipboardJS from "@/plugin/clipboard/clipboard.js";
	import {toLogin} from '@/libs/login.js';
	import {mapGetters} from "vuex";
	import colors from "@/mixins/color";
	import statusLine from '../components/statusLine/index'
	import customForm from "@/components/customForm";
	import tuiModal from "@/components/tui-modal/index.vue"
	import { HTTP_REQUEST_URL } from '@/config/app';
	export default {
		components: {
			statusLine,
			customForm,
			tuiModal
		},
		mixins: [colors],
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				giveData: {
					give_integral: 0,
					give_coupon: []
				},
				giveCartInfo: [],
				config: {
					qrc: {
						code: "",
						size: 360, // 二维码大小
						level: 4, //等级 0～4
						bgColor: '#FFFFFF', //二维码背景色 默认白色
						color: ['#333', '#333'], //边框颜色支持渐变色
					}
				},
				order_id: '',
				evaluate: 0,
				cartInfo: [], //购物车产品
				pid: 0, //上级订单ID
				split: [], //分单商品
				orderInfo: {
					system_store: {},
					_status: {}
				}, //订单详情
				system_store: {},
				isGoodsReturn: false, //是否为退款订单
				status: {}, //订单底部按钮状态
				refund_close: false,
				isClose: false,
				pay_close: false,
				pay_order_id: '',
				totalPrice: '0',
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				routineContact: 0,
				express_num: '',
				invoice_func: false,
				invoiceData: {},
				invoice_id: 0,
				invChecked: '',
				moreBtn: false,
				invShow: false,
				aleartStatus: false, //发票弹窗
				special_invoice: false,
				invList: [],
				userInfo: {},
				isReturen: '',
				showQrcode:false,
				showModal:false,
				modalTitle:'',
				modalContent:'',
				confirmText: '',
				modalType:0,
			};
		},
		computed: {
			isExpress(){
				if((this.orderInfo.shipping_type === 1 || this.orderInfo.shipping_type === 3) && this.orderInfo.product_type==0){
					return true
				}else{
					return false
				}
			},
			...mapGetters(['isLogin']),
		},
		onLoad: function(options) {
			if (options.order_id) {
				this.$set(this, 'order_id', options.order_id);
				this.isReturen = options.isReturen;
			}
			if (options.invoice_id) {
				this.invoice_id = options.invoice_id
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			if (this.isLogin) {
				this.getOrderInfo();
				this.getUserInfo();
			} else {
				toLogin()
			}
		},
		methods: {
			onLoadFun() {
				this.getOrderInfo();
				this.getUserInfo();
				this.isShowAuth = false
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			getpreviewImage: function(index, num) {
				uni.previewImage({
					urls: num ? this.orderInfo.refund_img : this.orderInfo.refund_goods_img,
					current: num ? this.orderInfo.refund_img[index] : this.orderInfo.refund_goods_img[index]
				});
			},
			showModalChange(type){
				this.modalType = type;
				if(type == 1){
					this.modalTitle = '删除订单';
					this.modalContent = '确定删除该订单?';
					this.confirmText = '确定';
				}else if(type == 2){
					this.modalTitle = '取消申请';
					this.modalContent = '您确定放弃此次申请吗?';
					this.confirmText = '确定';
				}
				this.showModal = true;
			},
			handleTap(e){
				let that = this;
				let index = e.index;
				if(index == 1){
					if(this.modalType == 1){
						refundOrderDel(that.order_id).then(res => {
							this.showModal = false;
							return that.$util.Tips({
								title: '操作成功',
								icon: 'success'
							}, {
								tab: 4,
								url: '/pages/users/user_return_list/index'
							});
						}).catch(err => {
							return that.$util.Tips({
								title: err
							});
						})
					}else if(this.modalType == 2){
						cancelRefundOrder(that.order_id).then(res => {
							this.showModal = false;
							return that.$util.Tips({
								title: '操作成功',
								icon: 'success'
							}, {
								tab: 4,
								url: '/pages/users/user_return_list/index'
							});
						}).catch(err => {
							return that.$util.Tips({
								title: err
							});
						})
					}
				}else{
					this.showModal = false;
				}
			},
			refundInput() {
				uni.navigateTo({
					url: `/pages/goods/order_refund_goods/index?orderId=` + this.order_id
				})
			},
			goGoodCall() {
				let url = `/pages/extension/customer_list/chat?orderId=${this.order_id}&isReturen=${this.isReturen}`
				let obj = {
					store_name: this.orderInfo.order_id,
					path: `/pages/goods/order_details/index?order_id=${this.orderInfo.order_id}`,
					image: ''
				}
				this.$util.getCustomer(this.userInfo,url,obj,1)
			},
			/**
			 * 事件回调
			 *
			 */
			onChangeFun: function(e) {
				let opt = e;
				let action = opt.action || null;
				let value = opt.value != undefined ? opt.value : null;
				(action && this[action]) && this[action](value);
			},
			/**
			 * 拨打电话
			 */
			makePhone: function() {
				uni.makePhoneCall({
					phoneNumber: this.system_store.phone
				})
			},
			/**
			 * 打开地图
			 *
			 */
			showMaoLocation: function() {
				if (!this.system_store.latitude || !this.system_store.longitude) return this.$util.Tips({
					title: '缺少经纬度信息无法查看地图！'
				});
				uni.openLocation({
					latitude: parseFloat(this.system_store.latitude),
					longitude: parseFloat(this.system_store.longitude),
					scale: 8,
					name: this.system_store.name,
					address: this.system_store.address + this.system_store.detailed_address,
					success: function() {

					},
				});
			},
			/**
			 * 获取用户信息
			 *
			 */
			getUserInfo: function() {
				let that = this;
				getUserInfo().then(res => {
					that.userInfo = res.data;
				})
			},
			/**
			 * 获取订单详细信息
			 *
			 */
			getOrderInfo: function() {
				let that = this;
				uni.showLoading({
					title: "正在加载中"
				});
				getRefundOrderDetail(this.order_id).then(res => {
					let _type = res.data._status._type;
					uni.hideLoading();
					that.$set(that, 'orderInfo', res.data);
					that.$set(that, 'routineContact', Number(res.data.routine_contact_type));
					let cartInfo = res.data.cartInfo;
					let cartObj = [],giftObj = [];
					cartInfo.forEach(item => {
						if (item.is_gift == 1) {
							giftObj.push(item)
						} else {
							cartObj.push(item)
						}
					})
					that.$set(that, 'cartInfo', cartObj);
					that.$set(that, 'giveCartInfo', giftObj);
				}).catch(err => {
					uni.hideLoading();
					if (err.status == 403) {
						uni.navigateTo({
							url: '/pages/goods/order_list/index'
						})
					} else {
						that.$util.Tips({
							title: err
						}, '/pages/goods/order_list/index');
					}
				});
			},
			copy: function(code) {
				let that = this;
				uni.setClipboardData({
					data: code
				});
			},
			copyAddress() {
				uni.setClipboardData({
					data: this.orderInfo._status.refund_name + this.orderInfo._status.refund_phone + this.orderInfo._status.refund_address
				});
			},
			/**
			 * 打电话
			 */
			goTel: function() {
				uni.makePhoneCall({
					phoneNumber: this.orderInfo.delivery_id
				})
			},
			goPage(type, url){
				if(type == 1){
					uni.navigateTo({
						url
					})
				}else if(type == 2){
					uni.switchTab({
						url
					})
				}else if(type == 3){
					uni.navigateBack();
				}

			},
			// 返件信息
			refundLogistics(){
				uni.navigateTo({
					url: '/pages/goods/goods_logistics/index?orderId='+ this.order_id + '&type=refund'
				})
			},
			// 再次申请售后
			refundAgain(){
				orderRefundAgain(this.orderInfo.id).then(res=>{
					this.$util.Tips({
						title: res.msg
					}, '/pages/users/user_return_list/index');
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				})
			},
		}
	}
</script>

<style lang="scss">
.order-goods ~ .order-goods{
	margin-top: 32rpx;
}
.Regular {
  font-family: Regular;
}
.copy_btn{
	width: 68rpx;
	height: 36rpx;
	background: #F5F5F5;
	border-radius: 20rpx;
	text-align:center;
	line-height:36rpx;
}
.btn ~ .btn{
	margin-left: 16rpx;
}
.bt{
	border-top:1rpx solid #eee;
}
.border{
	border: 1rpx solid #ccc;
}
.border_bt{
	border-bottom: 1rpx solid #eee;
}
.cell ~ .cell{
	margin-top: 32rpx;
}
</style>
