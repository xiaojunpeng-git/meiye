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
			<textarea class="h-166 mt-24 fs-26" v-model="service_describe" placeholder='请填写服务说明，非必填' placeholder-class="placeholder" maxlength=100 @input="sumfontnum"></textarea>
		</view>
		<view class='acea-row row-between fs-28 text--w111-333 mt-32'>
			<view class='acea-row row-middle'>
				<view class='pictrue mt-22 mr-23 w-148 h-148 relative fs-24 text--w111-bbb rd-16' v-for="(item,index) in service_img" :key="index">
					<image class="w-full h-full rd-16rpx" :src='item' mode="aspectFill"></image>
					<view class='iconfont icon-ic_close abs-rt fs-24 w-32 h-32 bg-w111-999 rd-rt-16rpx rd-lb-16rpx text-center lh-32rpx text--w111-fff' @tap='DelPic(index)'></view>
				</view>
				<view class='acea-row row-center-wrapper row-column mt-22 w-148 h-148 relative fs-24 text--w111-333 rd-16rpx bg--w111-f5f5f5 border-CCCCCC' @tap='uploadpic'
					v-if="service_img.length < 8">
					<image class="w-48 h-48 mb-8" src="../static/ic_camera.png"></image>
					<view>上传凭证</view>
				</view>
			</view>
		</view>
	</view>
	<view class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32" v-if="fromButler && serviceStarted">
		<view class="cell acea-row row-between" v-if="info.service_time">
			<text class="fs-28 w-200">服务开始时间</text>
			<text class="fs-28 flex-1 pl-10 text-right">{{ info.service_time }}</text>
		</view>
		<view class="cell acea-row row-between" v-if="info.service_end_time">
			<text class="fs-28 w-200">服务结束时间</text>
			<text class="fs-28 flex-1 pl-10 text-right">{{ info.service_end_time }}</text>
		</view>
		<view class="fs-28 text--w111-333 fw-600" :class="{ 'mt-26': info.service_time || info.service_end_time }">服务凭证</view>
		<view class="mt-24 fs-26 text--w111-666" v-if="service_describe">{{ service_describe }}</view>
		<view class="acea-row row-middle flex-wrap mt-24" v-if="service_img.length">
			<view class="w-148 h-148 mr-16 mb-16 rd-16" v-for="(item, index) in service_img" :key="index">
				<image class="w-full h-full rd-16rpx" :src="item" mode="aspectFill"></image>
			</view>
		</view>
		<view class="mt-24 fs-26 text--w111-999" v-if="!service_describe && !service_img.length">暂无服务凭证</view>
	</view>
	<view v-if="info.reservation_info && info.reservation_info.length" class="mt-20 bg--w111-fff rd-16rpx pt-32 pr-24 pl-24 pb-32">
		<view class="cell fw-600">{{info.custom_form_title}}信息</view>
		<view class="cell flex justify-between" v-for="(item,index) in info.reservation_info" :key="index">
			<text class="fs-28">{{item.titleConfig.value}}</text>
			<view v-if="item.name == 'uploadPicture' && item.value.length < 5"  class="w-462 flex justify-end">
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
		<view class="footer-btn confirm ml-20" @click="confirmOrder">接单</view>
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
import { storeReservationDetail,reservationServiceSet,storeReservationConfirm,storeReservationRefuse } from '@/api/store.js';
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
			service_describe:''
		}
	},
	computed: {
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
			const info = this.$store.state.app.storeStaffInfo || {};
			return Number(info.is_manager) === 1 || Number(info.is_butler) === 1;
		},
		canStartService() {
			if (this.fromButler) return false;
			if (this.fromTeacher) return true;
			return this.$util.auth('mall-admin-reservation-start');
		},
		canEndService() {
			if (this.fromButler) return false;
			if (this.fromTeacher) return true;
			return this.$util.auth('mall-admin-reservation-end');
		},
		serviceStarted() {
			const status = Number(this.info.status);
			return status === 1 || status === 2;
		},
	},
	onLoad(options){
		this.id = options.id
		this.fromTeacher = options.from === 'teacher';
		this.fromButler = options.from === 'butler';
		this.reservationOrderDetail();
	},
	methods:{
		// 限制文本框字数
		sumfontnum(e) {
			this.fontNum = e.detail.value.length
		},
		/**
		 * 删除图片
		 * 
		 */
		DelPic: function(e) {
			let index = e,
				that = this,
				pic = this.service_img[index];
			that.service_img.splice(index, 1);
			that.$set(that, 'service_img', that.service_img);
		},
		
		/**
		 * 上传文件
		 * 
		 */
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
				reservationServiceSet(this.id,data).then(res=>{
					if(status==1){
						this.showModal = false;
						this.reservationOrderDetail();
					}else{
						const redirectUrl = this.fromTeacher
							? '/pages/admin/staff_center/order/index?status=1'
							: '/pages/admin/reservation_list/index';
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
		// 预约详情
		reservationOrderDetail(){
			storeReservationDetail(this.id).then(res=>{
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
		confirmOrder() {
			// #ifdef MP
			openGuanjiaSubscribe();
			// #endif
			storeReservationConfirm(this.id).then(res => {
				this.$util.Tips({ title: res.msg || '接单成功' });
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
					storeReservationRefuse(this.id, { refuse_reason: reason }).then(r => {
						this.$util.Tips({ title: r.msg || '已拒绝' }, '/pages/admin/reservation_list/index');
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
		bottom: calc(20rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		bottom: calc(20rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
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
		height: calc(140rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(140rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
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
</style>