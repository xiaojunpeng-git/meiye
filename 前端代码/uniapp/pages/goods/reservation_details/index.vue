<template>
	<view :style="colorStyle">
		<view class="w-full relative z-99" :style="{'padding-top': sysHeight + 'px'}">
			<view class="w-full px-20 pl-20 h-80 flex-between-center relative z-80">
				<text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff" @tap="goPage"></text>
				<text class="fs-34 fw-500 text--w111-fff">预约详情</text>
				<text></text>
			</view>
			<view class="px-20 relative z-80">
				<view class="flex-between-center h-160 mt-12">
					<view class="flex-1 text--w111-fff pl-12">
						<view class="fs-36 fw-500 lh-50rpx">{{info.status_name}}</view>
						<view class="fs-26 lh-36rpx mt-8">{{info.status_msg}}</view>
					</view>
					<image :src="info.status_pic" class="w-140 h-140"></image>
				</view>
			</view>
			<view class="w-full bg-gradient abs-lt" :style="{height: 213 + sysHeight + 'px'}">
				<view class="w-full abs-lb white_jianbian z-20"></view>
			</view>
		</view>
		<view class="px-20 relative z-99">
			<view class="bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32 acea-row">
				<view class="w-136 h-136 rd-16">
					<easy-loadimage :image-src="productInfo.image" width="136rpx" height="136rpx"
						borderRadius="16rpx"></easy-loadimage>
				</view>
				<view class="w-502 ml-24">
					<view class="line1 fs-28 text--w111-333">{{productInfo.store_name }}</view>
					<view class="mt-8 text--w111-999 fs-24 line1">{{attrInfo.suk}}</view>
					<view class="acea-row row-between-wrapper">
						<view class="flex items-end flex-wrap mt-12 w-420">
							<BaseTag :text="label.label_name" :color="label.color" :background="label.bg_color"
								:borderColor="label.border_color" :circle="label.border_color ? true : false"
								:imgSrc="label.icon" v-for="(label, idx) in productInfo.store_label" :key="idx">
							</BaseTag>
						</view>
						<view class="fs-24 text--w111-999">共{{cartInfo.cart_num}}{{productInfo.unit_name || '件'}}</view>
					</view>
				</view>
			</view>
			<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if="addonProjectList.length">
				<view class="cell fw-600">增项服务</view>
				<view class="project-item acea-row" v-for="(project, pIndex) in addonProjectList" :key="pIndex"
					:class="{ 'mt-24': pIndex > 0 }">
					<view class="w-120 h-120 rd-12" v-if="project.image">
						<easy-loadimage :image-src="project.image" width="120rpx" height="120rpx"
							borderRadius="12rpx"></easy-loadimage>
					</view>
					<view v-else class="w-120 h-120 rd-12 project-img-placeholder"></view>
					<view class="flex-1 ml-20">
						<view class="acea-row row-between-wrapper">
							<view class="fs-28 text--w111-333 line2 flex-1 pr-12">{{ project.product_name }}</view>
							<view class="fs-24 text--w111-999">×{{ project.cart_num || 1 }}</view>
						</view>
						<view class="fs-24 text--w111-999 mt-8 line2" v-if="project.desc">{{ project.desc }}</view>
					</view>
				</view>
			</view>
			<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32 text-center"
				v-if="[-1,2].indexOf(info.status) ==-1">
				<view class="fs-28 text--w111-333">请提醒商家核销预约单</view>
				<view class="w-320 h-320 m-auto mt-40">
					<image :src="codeSrc" class="w-full h-full"></image>
				</view>
				<view class="mt-32 fs-28 font-num">{{info.verify_code}}</view>
			</view>
			<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if="info.reservation_type == 3">
				<view class="cell acea-row row-between">
					<text class="fs-28">服务人员名称</text>
					<text class="fs-28">{{info.service_staff_name || '待分配'}}</text>
				</view>
				<view class="cell acea-row row-between">
					<text class="fs-28">服务人员电话</text>
					<text class="fs-28">{{info.service_staff_phone || '待分配'}}</text>
				</view>
			</view>
			<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32">
				<navigator class="cell acea-row row-between" :url="'/pages/store/info/index?store_id='+storeInfo.id"
					hover-class="none">
					<text class="fs-28">预约门店</text>
					<text class="fs-28">{{storeInfo.name}}<text
							class="iconfont icon-ic_rightarrow fs-24 text--w111-999 ml-4"></text></text>
				</navigator>
				<view class="cell acea-row row-between">
					<text class="fs-28">预约日期</text>
					<text class="fs-28">{{info.reservation_time}}</text>
				</view>
				<view class="cell acea-row row-between">
					<text class="fs-28">预约时段</text>
					<text class="fs-28">{{info.reservation_show_time}}</text>
				</view>
				<view class="cell acea-row row-between">
					<text class="fs-28">预约人姓名</text>
					<text class="fs-28">{{info.reservation_name}}</text>
				</view>
				<view class="cell acea-row row-between">
					<text class="fs-28">预约人手机号</text>
					<text class="fs-28">{{info.reservation_phone}}</text>
				</view>
				<view class="cell acea-row row-between">
					<text class="fs-28">预约人地址</text>
					<text class="fs-28 w-400 text-right">{{info.reservation_address}}</text>
				</view>
				<view class="cell acea-row row-between" v-if="info.mark">
					<text class="fs-28">留言</text>
					<text class="fs-28 line1 w-542 text-right">{{info.mark}}</text>
				</view>
			</view>
			<view v-if="info.reservation_info && info.reservation_info.length"
				class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32">
				<view class="cell fw-600">{{info.custom_form_title}}信息</view>
				<view class="cell flex justify-between" v-for="(item,index) in info.reservation_info" :key="index">
					<text class="fs-28">{{item.titleConfig.value}}</text>
					<view v-if="item.name == 'uploadPicture' && item.value.length < 5" class="w-462 flex justify-end">
						<view class='pictrue mr-8' v-for="(items,indexs) in item.value" :key="indexs">
							<image class="w-88 h-88 rd-8rpx" :src='items' mode="aspectFill"></image>
						</view>
					</view>
					<scroll-view scroll-x="true" scroll-with-animation class="white-nowrap vertical-middle w-462"
						show-scrollbar="false" v-else-if="item.name == 'uploadPicture' && item.value.length >= 5">
						<view class="inline-block mr-12" v-for="(items,indexs) in item.value" :key="index">
							<image class="w-88 h-88 rd-8rpx" :src="items"></image>
						</view>
					</scroll-view>
					<view v-else-if="item.name == 'dateranges'" class="  fs-28">
						<text v-if="item.value.length">{{item.value[0]+'/'+item.value[1]}}</text>
					</view>
					<text v-else class="fs-28">{{item.value}}</text>
				</view>
			</view>
			<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32">
				<view class="cell acea-row row-between">
					<text class="fs-28">预约单号</text>
					<view class="fs-28">
						<text class="fs-28 pr-12">{{info.order_id}}</text>
						<text class="inline-block copy_btn fs-22" @tap="copy(info.order_id)">复制</text>
					</view>
				</view>
				<view class="cell acea-row row-between">
					<text class="fs-28">订单编号</text>
					<view class="fs-28">
						<text class="fs-28 pr-12">{{info.store_order_id}}</text>
						<text class="inline-block copy_btn fs-22" @tap="copy(info.store_order_id)">复制</text>
					</view>
				</view>
				<view class="cell acea-row row-between">
					<text class="fs-28">预约时间</text>
					<text class="fs-28">{{info.reservation_create_time}}</text>
				</view>
				<view class="cell acea-row row-between" v-if="info.service_time">
					<text class="fs-28">服务开始时间</text>
					<text class="fs-28">{{info.service_time}}</text>
				</view>
				<view class="cell acea-row row-between" v-if="info.service_end_time">
					<text class="fs-28">服务结束时间</text>
					<text class="fs-28">{{info.service_end_time}}</text>
				</view>
			</view>
			<view v-if="info.service_describe || info.service_images.length" class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32 text--w111-333 fs-28">
				<view>服务凭证</view>
				<view class="mt-24 mb-8">{{info.service_describe}}</view>
				<view class="acea-row row-middle">
					<view class="serviceImg w-100 h-100 rd-9 mt-12 mr-12" v-for="(item, index) in info.service_images" :key="index">
						<image class="w-full h-full rd-9" :src="item" mode="aspectFill"></image>
					</view>
				</view>
			</view>
			<view class="heights"></view>
			<view class="footer acea-row row-middle row-right" v-if="info.status == 3 || info.status == -1">
				<view v-if="info.status == 3" class="border-CCCCCC w-144 h-56 rd-30rpx flex-center fs-24"
					@click="showModalChange(0)">取消预约</view>
				<view v-else-if="info.status == -1" class="acea-row row-middle">
					<view class="border-CCCCCC w-168 h-56 rd-30rpx flex-center fs-24" @click="showModalChange(1)">删除预约单
					</view>
					<navigator v-if="info.is_reservation && mobileReservationOpen" hover-class='none'
						:url="'/pages/activity/reservation/index?orderId='+info.oid"
						class="border-CCCCCC w-144 h-56 rd-30rpx flex-center fs-24 ml-16">再次预约</navigator>
				</view>
			</view>
			<zb-code ref="qrcode" :show="codeShow" :cid="cid" :val="info.verify_code" :size="size" :unit="unit"
				:background="background" :foreground="foreground" :pdground="pdground" :icon="icon" :iconSize="iconsize"
				:onval="onval" :loadMake="loadMake" @result="qrR" />
			<tuiModal :show="showModal" :title="modalTitle" :content="modalContent" :maskClosable="false"
				:confirmText="confirmText" @click="handleTap"></tuiModal>
		</view>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import colors from "@/mixins/color";
	import {
		getReservationOrderDetail,
		postReservationOrderCancel,
		delReservationOrder
	} from '@/api/order.js';
	import zbCode from '@/components/zb-code/zb-code.vue';
	import tuiModal from "@/components/tui-modal/index.vue"
	import { isMobileReservationOpen } from '@/utils/mobileReservation.js';
	export default {
		components: {
			zbCode,
			tuiModal
		},
		mixins: [colors],
		data() {
			return {
				sysHeight: sysHeight,
				//二维码参数开始
				codeShow: false,
				cid: '1',
				size: 200, // 二维码大小
				unit: 'upx', // 单位
				background: '#FFF', // 背景色
				foreground: '#000', // 前景色
				pdground: '#000', // 角标色
				icon: '', // 二维码图标
				iconsize: 40, // 二维码图标大小
				onval: true, // val值变化时自动重新生成二维码
				loadMake: true, // 组件加载完成后自动生成二维码
				//二维码参数结束
				id: 0,
				info: {},
				cartInfo: {},
				productInfo: {},
				attrInfo: {},
				storeInfo: {},
				codeSrc: '',
				showModal: false,
				modalContent: '',
				confirmText: '',
				modalTitle: '',
				modalType: 0,
			}
		},
		computed: {
			mobileReservationOpen() {
				return isMobileReservationOpen();
			},
			projectList() {
				if (Array.isArray(this.info.project_list) && this.info.project_list.length) {
					return this.info.project_list;
				}
				if (!this.productInfo.store_name) return [];
				return [{
					product_name: this.productInfo.store_name,
					image: this.productInfo.image || '',
					desc: (this.attrInfo && this.attrInfo.suk) || this.productInfo.store_info || '',
					cart_num: this.cartInfo.cart_num || 1,
				}];
			},
			addonProjectList() {
				return this.projectList.length > 1 ? this.projectList.slice(1) : [];
			},
		},
		onLoad(options) {
			this.id = options.id
			this.reservationOrderDetail();
		},
		methods: {
			goPage() {
				let pages = getCurrentPages();
				if (pages.length > 1) {
					uni.navigateBack();
				} else {
					uni.switchTab({
						url: '/pages/index/index'
					})
				}
			},
			showModalChange(type) {
				this.modalType = type;
				if (type == 0) {
					this.modalTitle = '取消预约';
					this.modalContent = '取消后可重新预约，是否取消预约？';
					this.confirmText = '确认';
				} else if (type == 1) {
					this.modalTitle = '删除预约单';
					this.modalContent = '确定删除该预约单?';
					this.confirmText = '确认';
				}
				this.showModal = true;
			},
			handleTap(e) {
				if (e.index) {
					if (this.modalType == 0) {
						postReservationOrderCancel(this.id).then(res => {
							this.reservationOrderDetail();
							this.showModal = false;
						}).catch(err => {
							this.showModal = false;
							this.$util.Tips({
								title: err
							})
						})
					} else {
						delReservationOrder(this.id).then(res => {
							this.showModal = false;
							uni.navigateTo({
								url: '/pages/goods/reservation_list/index?orderId=' + this.info.oid
							})
						}).catch(err => {
							this.showModal = false;
							this.$util.Tips({
								title: err
							})
						})
					}
				} else {
					this.showModal = false;
				}
			},
			// 预约详情
			reservationOrderDetail() {
				getReservationOrderDetail(this.id).then(res => {
					let data = res.data;
					this.info = data;
					this.cartInfo = data.cart_info;
					this.productInfo = data.cart_info.productInfo;
					this.attrInfo = data.cart_info.productInfo.attrInfo;
					this.storeInfo = data.storeInfo;
				}).catch(err => {
					this.$util.Tips({
						title: err
					})
				})
			},
			qrR(img) {
				this.codeSrc = img;
			},
			copy(code) {
				uni.setClipboardData({
					data: code
				});
			}
		}
	}
</script>

<style lang="scss" scoped>
	.serviceImg:nth-of-type(6n){
		margin-right: 0;
	}
	
	.white_jianbian{
		height:120rpx;
		background: linear-gradient(0deg, #F5F5F5 0%, rgba(245,245,245,0) 100%);
	}
	
	.cell~.cell {
		margin-top: 26rpx;
	}

	.copy_btn {
		width: 68rpx;
		height: 36rpx;
		background: #F5F5F5;
		border-radius: 20rpx;
		text-align: center;
		line-height: 36rpx;
	}

	.footer {
		width: 100%;
		background-color: #fff;
		padding: 0 20rpx;
		position: fixed;
		bottom: 0;
		left: 0;
		z-index: 20;
		height: calc(96rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(96rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
		padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/
		box-sizing: border-box;
	}

	.heights {
		height: calc(116rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(116rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
	}

	.project-img-placeholder {
		background: #f5f5f5;
	}

	.project-item {
		align-items: flex-start;
	}
</style>