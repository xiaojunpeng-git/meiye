<template>
  <view :style="colorStyle" class="px-20 mt-24">
	<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32">
		<view class="acea-row">
			<view class="w-136 h-136 rd-16">
				<easy-loadimage 
				:image-src="productInfo.image"
				width="136rpx"
				height="136rpx"
				borderRadius="16rpx"></easy-loadimage>
			</view>
			<view class="w-502 ml-24">
				<view class="line1 fs-28 text--w111-333">{{productInfo.store_name }}</view>
				<view class="mt-8 text--w111-999 fs-24 line1">{{attrInfo.suk}}</view>
				<view class="acea-row row-between-wrapper">
					<view class="flex items-end flex-wrap mt-12 w-420">
						<BaseTag
							:text="label.label_name"
							:color="label.color"
							:background="label.bg_color"
							:borderColor="label.border_color"
							:circle="label.border_color ? true : false"
							:imgSrc="label.icon"
							v-for="(label, idx) in productInfo.store_label" :key="idx"></BaseTag>
					</view>
					<view class="fs-24 text--w111-999">共{{cartInfo.cart_num}}{{productInfo.unit_name || '件'}}</view>
				</view>
			</view>
		</view>
		<view class="cell acea-row row-between mt-26">
			<text class="fs-28 w-200">预约日期</text>
			<text class="fs-28 flex-1 pl-10 flex-1 text-right">{{info.reservation_time}}</text>
		</view>
		<view class="cell acea-row row-between">
			<text class="fs-28 w-200">预约时段</text>
			<text class="fs-28 flex-1 pl-10 flex-1 text-right">{{info.reservation_show_time}}</text>
		</view>
		<view class="cell acea-row row-between" v-if="info.reservation_name">
			<text class="fs-28 w-200">预约人姓名</text>
			<text class="fs-28 flex-1 pl-10 flex-1 text-right">{{info.reservation_name}}</text>
		</view>
		<view class="cell acea-row row-between" v-if="info.reservation_phone">
			<text class="fs-28 w-200">预约人手机号</text>
			<text class="fs-28 flex-1 pl-10 flex-1 text-right">{{info.reservation_phone}}</text>
		</view>
		<view class="cell acea-row row-between" v-if="info.reservation_phone && info.reservation_type == 3">
			<text class="fs-28 w-200">预约人地址</text>
			<text class="fs-28 flex-1 pl-10 flex-1 text-right">{{info.reservation_address}}</text>
		</view>
		<view class="cell acea-row row-between" v-if="info.mark">
			<text class="fs-28 w-200">留言</text>
			<text class="fs-28 flex-1 pl-10 flex-1 text-right">{{info.mark}}</text>
		</view>
		<view class="cell acea-row row-between" v-if="info.refuse_reason">
			<text class="fs-28 w-200">退回原因</text>
			<text class="fs-28 flex-1 pl-10 flex-1 text-right">{{info.refuse_reason}}</text>
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
	<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if="info.reservation_type == 3 || info.service_time || info.service_end_time">
		<view class="cell acea-row row-between" v-if="info.reservation_type == 3">
			<text class="fs-28 w-200">服务人员名称</text>
			<text class="fs-28 flex-1 pl-10 flex-1 text-right">{{info.service_staff_name || '暂无'}}</text>
		</view>
		<view class="cell acea-row row-between" v-if="info.reservation_type == 3">
			<text class="fs-28 w-200">服务人员电话</text>
			<text class="fs-28 flex-1 pl-10 flex-1 text-right">{{info.service_staff_phone || '暂无'}}</text>
		</view>
		<view class="cell acea-row row-between" v-if="info.service_time && !fromButler">
			<text class="fs-28 w-200">服务开始时间</text>
			<text class="fs-28 flex-1 pl-10 flex-1 text-right">{{info.service_time}}</text>
		</view>
		<view class="cell acea-row row-between" v-if="info.service_end_time && !fromButler">
			<text class="fs-28 w-200">服务结束时间</text>
			<text class="fs-28 flex-1 pl-10 flex-1 text-right">{{info.service_end_time}}</text>
		</view>
	</view>
	<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if="info.status==1 && !fromButler && canEndService">
		<view class='fs-28 text--w111-333'>
			<view class="acea-row row-between-wrapper">
				<view>服务凭证</view>
				<view class="fs-24 text--w111-999">{{fontNum}}/100</view>
			</view>
			<textarea class="mt-20 text--w111-333 fs-28" maxlength="100" :value="service_describe" @input="sumfontnum" placeholder-class="placeholder" placeholder="请填写服务凭证" name="service_describe" v-model="service_describe"></textarea>
			<view class="acea-row row-middle mt-20">
				<view class='pictrue mr-8' v-for="(item,index) in service_img" :key="index">
					<image class="w-136 h-136 rd-16rpx" :src='item' mode="aspectFill"></image>
					<text class='iconfont icon-ic_close' @click='DelPic(index)'></text>
				</view>
				<view class='pictrue acea-row row-center-wrapper row-column' @click='uploadpic' v-if="service_img.length < 8">
					<text class='iconfont icon-icon_picture fs-50 text--w111-ccc'></text>
					<view class="fs-24 text--w111-999 mt-8">上传凭证</view>
				</view>
			</view>
		</view>
	</view>
	<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if="info.custom_form && info.custom_form.length">
		<view class="cell fw-600">补充信息</view>
		<view class="cell acea-row row-between" v-for="(item,index) in info.custom_form" :key="index">
			<text class="fs-28 w-200">{{item.title}}</text>
			<view class='pictrue mr-8' v-if="item.name == 'uploadPicture' && item.value.length < 5">
				<view class='pictrue mr-8' v-for="(items,indexs) in item.value" :key="indexs">
				  <image class="w-88 h-88 rd-8rpx" :src='items' mode="aspectFill"></image>
				</view>
			</view>
			<scroll-view scroll-x="true" scroll-with-animation
				class="white-nowrap vertical-middle w-462" show-scrollbar="false"
					v-else-if="item.name == 'uploadPicture' && item.value.length >= 5">
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
	<view class="heights"></view>
	<view class="footer acea-row row-center-wrapper" v-if="info.status==3 && canManageReservation">
		<view class="footer-btn refuse" @click="refuseOrder">拒绝</view>
		<view class="footer-btn confirm ml-20" @click="openRoomConfirm">接单</view>
	</view>
	<view class="footer bg-w111-2A7EFB acea-row row-center-wrapper" v-if="info.status==0 && canStartService && !fromButler" @click="showModalChange(1)">开始服务</view>
	<view v-if="info.status==1 && canEndService && !fromButler">
		<view class="footer bg-w111-2A7EFB acea-row row-center-wrapper" @click="showModalChange(2)">
			<view v-if="stopTime" class="footer bg-w111-FF7E00 acea-row row-center-wrapper">结束服务</view>
			<countDown
			:is-day="false"
			tip-text="结束服务"
			day-text=" "
			hour-text=":"
			minute-text=":"
			second-text=" "
			dotColor="#fff"
			colors="#fff"
			@endTime='endTime'
			v-else
			:datatime="info.service_stop_time"
			></countDown>
		</view>
	</view>
	<view class="mask" v-if="roomVisible" @click="closeRoom"></view>
	<view class="refuse-panel room-panel" v-if="roomVisible">
		<view class="room-title">选择服务房间</view>
		<picker mode="selector" :range="roomLabels" @change="onRoomChange">
			<view class="room-picker">{{ roomLabels[selectedRoomIndex] || '请选择房间' }}</view>
		</picker>
		<view class="refuse-btns acea-row row-center-wrapper">
			<view class="refuse-btn cancel" @click="closeRoom">取消</view>
			<view class="refuse-btn sure" @click="submitConfirm">确定接单</view>
		</view>
	</view>
	<tuiModal
		:show="showModal"
		:title="modalTitle"
		:content="modalContent"
		:maskClosable="false"
		:confirmText="confirmText"
		@click="handleTap"></tuiModal>
  </view>
</template>

<script>
import colors from "@/mixins/color";
import { storeReservationDetail,reservationServiceSet,storeReservationConfirm,storeReservationRefuse,storeReservationTableList } from '@/api/store.js';
import {
	merchantReservationDetail,
	merchantReservationConfirm,
	merchantReservationRefuse,
	merchantReservationServiceSet,
	merchantReservationTables
} from '@/api/merchant.js';
import { openGuanjiaSubscribe, openYuyueSubscribe } from '@/utils/SubscribeMessage.js';
import tuiModal from "@/components/tui-modal/index.vue";
import countDown from "@/components/countDown";
export default{
	components: {
		tuiModal,
		countDown
	},
	mixins: [colors],
	data() {
		return {
			id:0,
			fromTeacher: false,
			fromButler: false,
			fromMerchant: false,
			info:{},
			cartInfo:{},
			productInfo:{},
			attrInfo:{},
			showModal:false,
			modalContent:'',
			confirmText:'',
			modalTitle:'',
			stopTime:0,
			fontNum:0,
			service_img: [],
			service_describe:'',
			roomVisible: false,
			tableList: [],
			selectedRoomIndex: 0,
		}
	},
	computed: {
		isMerchantContext() {
			return this.fromMerchant || this.$store.state.merchant.mode === 'merchant';
		},
		merchantContextParams() {
			return {
				active_store_id: this.$store.state.merchant.activeStoreId || 0,
				active_role: this.$store.state.merchant.activeRole || '',
			};
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
		canManageReservation() {
			if (this.isMerchantContext) {
				return !!(this.info && this.info.can_manage);
			}
			const info = this.$store.state.app.storeStaffInfo || {};
			return Number(info.is_manager) === 1 || Number(info.is_butler) === 1;
		},
		canStartService() {
			if (this.fromButler) return false;
			if (this.isMerchantContext) {
				// 详情已过商家 Guard；勿依赖旧 mall_unique_auth 缓存
				return !!(this.info && this.info.merchant_guard);
			}
			if (this.fromTeacher) return true;
			return this.$util.auth('mall-admin-reservation-start');
		},
		canEndService() {
			if (this.fromButler) return false;
			if (this.isMerchantContext) {
				return !!(this.info && this.info.merchant_guard);
			}
			if (this.fromTeacher) return true;
			return this.$util.auth('mall-admin-reservation-end');
		},
		serviceStarted() {
			const status = Number(this.info.status);
			return status === 1 || status === 2;
		},
		roomLabels() {
			return this.tableList.map(item => item.remarks || item.table_number || ('房间' + item.id));
		},
	},
	onLoad(options){
		this.id = options.id
		this.fromTeacher = options.from === 'teacher';
		this.fromButler = options.from === 'butler';
		this.fromMerchant = options.merchant === '1' || options.from === 'merchant';
		this.reservationOrderDetail();
	},
	methods:{
		sumfontnum(e) {
			this.fontNum = e.detail.value.length
		},
		DelPic: function(e) {
			let index = e,
				that = this,
				pic = this.service_img[index];
			that.service_img.splice(index, 1);
			that.$set(that, 'service_img', that.service_img);
		},
		uploadpic: function() {
			let that = this;
			this.$util.uploadImageOne('upload/image', function(res) {
				that.service_img.push(res.data.url);
				that.$set(that, 'service_img', that.service_img);
			});
		},
		endTime(e){
			this.stopTime = e
		},
		showModalChange(num){
			if(num==1){
				this.modalTitle = '开始服务';
				this.modalContent = '确认开始服务后代表服务开始进行，是否确认？';
				this.confirmText = '确认';
				if(this.fromTeacher || this.info.service_staff_id == 0 || this.info.now_staff_id == this.info.service_staff_id){
					this.serviceSet(1)
				}else{
					this.modalContent = '你与指定服务人员不一致，是否确认？';
					this.showModal = true;
				}
			}else{
				this.serviceSet(2)
			}
		},
		serviceSet(status){
			let data = {
				status: status,
				service_describe: this.service_describe,
				service_images: this.service_img
			}
			const run = () => {
				const req = this.isMerchantContext
					? merchantReservationServiceSet(this.id, { ...this.merchantContextParams, ...data })
					: reservationServiceSet(this.id, data);
				req.then(res=>{
					if(status==1){
						this.showModal = false;
						this.reservationOrderDetail();
					}else{
						const redirectUrl = this.fromTeacher
							? '/pages/admin/staff_center/order/index?status=1'
							: (this.isMerchantContext
								? '/pages/admin/reservation_list/index?merchant=1'
								: '/pages/admin/reservation_list/index');
						this.$util.Tips({
							title: res.msg
						}, redirectUrl);
					}
				}).catch(err=>{
					this.$util.Tips({
						title: err
					})
				});
			};
			if (status === 1) {
				// #ifdef MP
				openYuyueSubscribe().finally(run);
				// #endif
				// #ifndef MP
				run();
				// #endif
			} else {
				run();
			}
		},
		handleTap(e){
			if(e.index){
				this.serviceSet(1)
			}else{
				this.showModal = false;
			}
		},
		reservationOrderDetail(){
			const req = this.isMerchantContext
				? merchantReservationDetail(this.id, this.merchantContextParams)
				: storeReservationDetail(this.id);
			req.then(res=>{
				let data = res.data;
				this.info = data;
				this.cartInfo = data.cart_info;
				this.productInfo = data.cart_info.productInfo;
				this.attrInfo = data.cart_info.productInfo.attrInfo;
				this.service_describe = data.service_describe || '';
				this.service_img = Array.isArray(data.service_images) ? [...data.service_images] : [];
				this.fontNum = this.service_describe.length;
			}).catch(err=>{
				this.$util.Tips({
					title: err
				})
			})
		},
		loadTableList() {
			const req = this.isMerchantContext
				? merchantReservationTables(this.merchantContextParams)
				: storeReservationTableList();
			return req.then(res => {
				this.tableList = res.data || [];
				return this.tableList;
			}).catch(() => {
				this.tableList = [];
				return [];
			});
		},
		openRoomConfirm() {
			// #ifdef MP
			openGuanjiaSubscribe();
			// #endif
			this.loadTableList().then((list) => {
				if (!list.length) {
					return this.$util.Tips({ title: '暂无可用房间，请先在后台配置房号' });
				}
				this.selectedRoomIndex = 0;
				this.roomVisible = true;
			});
		},
		closeRoom() {
			this.roomVisible = false;
			this.selectedRoomIndex = 0;
		},
		onRoomChange(e) {
			this.selectedRoomIndex = Number(e.detail.value || 0);
		},
		submitConfirm() {
			const room = this.tableList[this.selectedRoomIndex];
			if (!room) {
				return this.$util.Tips({ title: '请选择服务房间' });
			}
			const payload = {
				table_id: room.id,
				table_name: room.remarks || String(room.table_number || '')
			};
			const req = this.isMerchantContext
				? merchantReservationConfirm(this.id, {
					...this.merchantContextParams,
					...payload
				})
				: storeReservationConfirm(this.id, payload);
			req.then(res => {
				this.$util.Tips({ title: res.msg || '接单成功' });
				this.closeRoom();
				this.reservationOrderDetail();
			}).catch(err => {
				this.$util.Tips({ title: err.msg || '操作失败' });
			});
		},
		refuseOrder() {
			uni.showModal({
				title: '拒绝预约',
				editable: true,
				placeholderText: '请填写拒绝原因',
				success: (res) => {
					if (!res.confirm) return;
					const reason = (res.content || '').trim();
					if (!reason) {
						return this.$util.Tips({ title: '请填写拒绝原因' });
					}
					// #ifdef MP
					openGuanjiaSubscribe();
					// #endif
					const listUrl = this.isMerchantContext
						? '/pages/admin/reservation_list/index?merchant=1'
						: '/pages/admin/reservation_list/index';
					const req = this.isMerchantContext
						? merchantReservationRefuse(this.id, {
							...this.merchantContextParams,
							refuse_reason: reason
						})
						: storeReservationRefuse(this.id, { refuse_reason: reason });
					req.then(r => {
						this.$util.Tips({ title: r.msg || '已拒绝' }, listUrl);
					}).catch(err => {
						this.$util.Tips({ title: err.msg || '操作失败' });
					});
				}
			});
		},
	}
}
</script>

<style lang="scss" scoped>
	.pictrue:nth-of-type(4n){
		margin-right: 0;
	}
	.placeholder{
		font-size: 26rpx;
		color: #ccc;
	}
	.cell ~ .cell{
		margin-top: 26rpx;
	}
	.footer{
		width: 710rpx;
		height: 80rpx;
		color: #fff;
		font-size: 28rpx;
		border-radius: 50rpx;
		position: fixed;
		left: 50%;
		margin-left: -355rpx;
		bottom: 20rpx;
		bottom: calc(20rpx + constant(safe-area-inset-bottom));
		bottom: calc(20rpx + env(safe-area-inset-bottom));
		z-index: 20;
	}
	.footer-btn{
		width: 340rpx;
		height: 80rpx;
		border-radius: 50rpx;
		font-size: 28rpx;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.footer-btn.confirm{
		background: #2A7EFB;
		color: #fff;
	}
	.footer-btn.refuse{
		background: #fff;
		color: #FF7700;
		border: 1rpx solid #FF7700;
	}
	.heights{
		height: calc(140rpx + constant(safe-area-inset-bottom));
		height: calc(140rpx + env(safe-area-inset-bottom));
	}
	::v-deep .tui-modal-btn-cancel{
		border:1px solid #2A7EFB;
		color: #2A7EFB;
	}
	::v-deep .tui-modal-btn-confirm{
		background-color: #2A7EFB;
	}
	.project-img-placeholder {
		background: #f5f5f5;
	}
	.project-item {
		align-items: flex-start;
	}
	.mask {
		position: fixed;
		left: 0;
		right: 0;
		top: 0;
		bottom: 0;
		background: rgba(0, 0, 0, 0.45);
		z-index: 90;
	}
	.refuse-panel {
		position: fixed;
		left: 0;
		right: 0;
		bottom: 0;
		background: #fff;
		border-radius: 24rpx 24rpx 0 0;
		padding: 32rpx 30rpx calc(32rpx + env(safe-area-inset-bottom));
		z-index: 100;
	}
	.room-title {
		font-size: 30rpx;
		font-weight: 600;
		color: #333;
		margin-bottom: 24rpx;
		text-align: center;
	}
	.room-picker {
		height: 80rpx;
		line-height: 80rpx;
		padding: 0 24rpx;
		background: #f5f5f5;
		border-radius: 12rpx;
		font-size: 28rpx;
		color: #333;
	}
	.refuse-btns {
		margin-top: 32rpx;
	}
	.refuse-btn {
		flex: 1;
		height: 80rpx;
		line-height: 80rpx;
		text-align: center;
		border-radius: 40rpx;
		font-size: 28rpx;
	}
	.refuse-btn.cancel {
		background: #f5f5f5;
		color: #666;
		margin-right: 20rpx;
	}
	.refuse-btn.sure {
		background: #2A7EFB;
		color: #fff;
	}
</style>
