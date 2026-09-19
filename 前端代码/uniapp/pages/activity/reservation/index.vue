<template>
	<view class="booking-page" :style="colorStyle">
	   <!-- #ifdef MP || APP-PLUS -->
	   <NavBar :titleText="pageTitle" :iconColor="iconColor" :textColor="iconColor" :bagColor="themeColor" :isScrolling="false" showBack></NavBar>
	   <!-- #endif -->
	   <view class="header"></view>

	   <!-- 已选项目 -->
	   <view class="booking-header" v-if="selectedItems.length">
		   <view class="booking-header-top acea-row row-between-wrapper">
			   <text class="booking-header-title">已选项目</text>
			   <text class="booking-header-count">{{ selectedItems.length }} 个项目</text>
		   </view>
		   <view class="selected-items acea-row">
			   <view class="item-tag" v-for="(item, idx) in selectedItems" :key="idx">{{ item.label }}</view>
		   </view>
		   <view class="service-duration acea-row row-between-wrapper" v-if="totalServiceDuration > 0">
			   <text>预计服务时长</text>
			   <text class="duration">{{ totalServiceDuration }} 分钟</text>
		   </view>
		   <view class="addon-add-btn" @click="openAddonPicker">+ 添加项目</view>
	   </view>

	   <!-- 预约信息 -->
	   <view class="info-card bg--w111-fff rd-16rpx w-710 m-auto">
		   <navigator :url="storeListUrl" hover-class="none" class="info-row acea-row row-between-wrapper" v-if="canSelectStore">
			   <text class="info-label">预约门店</text>
			   <view class="info-value">{{storeName}}<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999 ml-8"></text></view>
		   </navigator>
		   <view class="info-row acea-row row-between-wrapper" v-else>
			   <text class="info-label">预约门店</text>
			   <text class="info-value">{{storeName}}</text>
		   </view>
		   <view class="info-divider" v-if="orderId"></view>
		   <view class="info-row acea-row row-between-wrapper" v-if="orderId">
			   <text class="info-label">服务类型</text>
			   <text class="info-value">{{ reservationName }}</text>
		   </view>
		   <view class="info-divider"></view>
		   <view class="info-row acea-row row-between-wrapper" @click="openStaffPicker">
			   <view class="acea-row row-middle flex-1 min-w-0">
				   <text class="info-label">服务老师</text>
				   <text class="info-note">(不选由门店安排)</text>
			   </view>
			   <view class="info-value line1">{{ staffDisplayName }}<text class="iconfont icon-ic_rightarrow fs-24 text--w111-999 ml-8"></text></view>
		   </view>
	   </view>

	   <view class="bg--w111-fff pl-24 pr-24 pt-32 pb-32 rd-16rpx relative w-710 m-auto mt-20" v-if="!orderId">
		  <!-- <view class="acea-row row-between-wrapper" :class="orderId?'':'mt-32'"> -->
		  <view class="acea-row row-between-wrapper mt-32">
			  <view class="fs-28">预约人数</view>
			  <view>
				<view class="acea-row row-middle">
					<text class="iconfont icon-ic_Reduce fs-26 text--w111-333 mr-4" :class="cartNum<=1?'text--w111-ccc':''" @click.stop="addCart(0)"></text>
					<input type="number" maxlength="3" class="w-72 h-36 rd-4rpx bg--w111-f5f5f5 acea-row row-center-wrapper text-center fs-24 text--w111-333 mx-6" v-model="cartNum" />
					<text class="iconfont icon-ic_increase fs-26 text--w111-333 ml-4" :class="(cartNum >= maxCartNum && orderId)?'text--w111-ccc':''" @click.stop="addCart(1)"></text>
				</view>
			  </view>
		  </view>
		  <view v-if="attrList.length" class="mt-32">
			 <view class="acea-row row-between-wrapper">
				 <view class="fs-28">规格</view>
				 <view class="iconfont  text--w111-666 fs-28" :class="attrStyle?'icon-a-ic_Imageandtextsorting':'icon-a-ic_Picturearrangement'" v-if="attrList[0].pic" @click="attrStyleTap"></view>
			 </view>
			 <scroll-view v-if="attrStyle" scroll-x="true" show-scrollbar="false" class="mt-32 white-nowrap vertical-middle w-full overflow">
				 <view v-for="(item,index) in attrList" class="w-196 h-252 bg--w111-f5f5f5 rd-12rpx inline-block mr-12 relative border-F5F5F5-1" :class="current==index?'bntActive':''" @click="arrtTap(item,index)">
				 	<view @click="showImg(item)" class="absolute w-36 h-36 acea-row row-center-wrapper bg--w111-ddd rd-50rpx top-8 right-8 z-4">
						<text class="iconfont icon-ic_enlarge text--w111-fff fs-24"></text>
				 	</view>
				 	<view class="w-full h-194">
						<image :src="item.pic" class="w-full h-full rd-t-12rpx"></image>
				 	</view>
				 	<view class="w-full h-58 fs-24 acea-row row-center-wrapper">{{item.attr}}</view>		 				  
				 </view>
			 </scroll-view>
			 <scroll-view v-else scroll-x="true" show-scrollbar="false" class="mt-32 white-nowrap vertical-middle w-full overflow">
			 	<view v-for="(item,index) in attrList" class="inline-block mr-24 vertical-middle" @click="arrtTap(item,index)">
			 		<view class="acea-row row-middle h-56 bg--w111-f5f5f5 pl-4 pr-20 rd-50rpx border-F5F5F5-1" :class="current==index?'bntActive':''">
			 			<view class="w-48 h-48 mr-8" v-if="item.pic">
							<image :src="item.pic" class="w-full h-full rd-50-p111-"></image>
			 			</view>
			 			<view class="fs-24" :class="!item.pic?'pl-16':''">{{item.attr}}</view>
			 		</view>
			 	</view>		  
			 </scroll-view>
		  </view>
	   </view>
	   <view class="time-section bg--w111-fff rd-16rpx w-710 m-auto mt-20">
		   <view class="time-section-title fs-28 fw-500 pl-24 pt-24">预约时间</view>
		   <chooseTime
			   class="booking-time-picker"
			   :embedded="true"
			   :isQuantum="true"
			   :beginTime="timeBegin"
			   :endTime="timeEnd"
			   :timeInterval="timeIntervalHours"
			   :disableTimeSlot="disableTimeSlot"
			   :selectedQuantum="selectedTimeRange"
		   :selectedTabColor="themeColor"
		   :selectedItemColor="themeColor"
			   disableText="不可约"
			   undisableText="可预约"
			   @dateChange="onChooseDateChange"
			   @changeTime="onChooseTimeChange"
		   ></chooseTime>
	   </view>
	   <view class="remark-card bg--w111-fff rd-16rpx w-710 m-auto mt-20" v-if="orderId">
		   <view class="remark-title fs-28 fw-500">服务备注</view>
		   <textarea
			   class="remark-textarea fs-28"
			   v-model="serviceMark"
			   placeholder="请添加备注（600字以内）"
			   placeholder-class="placeholder"
			   maxlength="600"
			   :auto-height="true"
		   />
	   </view>
	   <view v-if="confirmShow">
	   	<view v-for="(x,xindex) in confirm" :key="xindex" class="bg--w111-fff pl-24 pr-24 pt-32 pb-32 rd-16rpx relative w-710 m-auto mt-20">
	   		<view class="cell flex justify-between fw-500">{{customFormTitle}}{{xindex+1}}</view>
	   		<view class="cell flex justify-between" v-for="(item,index) in x" :key="index">
	   			<text class="relative text--w111-333 fs-28 pl-16" :class="{'pt-10':item.name=='radios' || item.name=='checkboxs'}">
	   				<text class="asterisk" v-if="item.titleShow.val">*</text>
	   				{{ item.titleConfig.value }}
	   			</text>
	   			<!-- radio -->
	   			<view v-if="item.name=='radios'" class="discount">
	   				<radio-group @change="radioChange($event, index, item, xindex)" class="acea-row row-middle row-right">
	   				  <label class="radio" v-for="(j,jindex) in item.wordsConfig.list" :key="jindex">
	   					<view class="acea-row row-middle">
	   					  <!-- #ifndef MP -->
	   					  <radio :value="jindex.toString()" :checked='j.show'/>
	   					  <!-- #endif -->
	   					  <!-- #ifdef MP -->
	   					  <radio :value="jindex" :checked='j.show'/>
	   					  <!-- #endif -->
	   					  <view>{{j.val}}</view>
	   					</view>
	   				  </label>
	   				</radio-group>
	   			</view>
	   			<!-- checkbox -->
	   			<view v-if="item.name=='checkboxs'" class="discount">
	   				<checkbox-group @change="checkboxChange($event, index, item, xindex)" class="acea-row row-middle row-right w-530">
	   				  <label class="radio" v-for="(j,jindex) in item.wordsConfig.list" :key="jindex">
	   					<view class="acea-row row-middle">
	   					  <!-- #ifndef MP -->
	   					  <checkbox :value="jindex.toString()" :checked="j.show" style="transform:scale(0.9)" />
	   					  <!-- #endif -->
	   					  <!-- #ifdef MP -->
	   					  <checkbox :value="jindex" :checked="j.show" style="transform:scale(0.9)" />
	   					  <!-- #endif -->
	   					  <view>{{j.val}}</view>
	   					</view>
	   				  </label>
	   				</checkbox-group>
	   			</view>
	   			<!-- text -->
	   			<view v-if="item.name=='texts' && item.valConfig.tabVal == 0" class="discount">
	   			  <input type="text" :placeholder="item.tipConfig.value" placeholder-class="placeholder" class="fs-28" v-model="item.value" />
	   			</view>
	   			<!-- number -->
	   			<view v-if="item.name=='texts' && item.valConfig.tabVal == 4" class="discount">
	   			  <input type="number" :placeholder="item.tipConfig.value" placeholder-class="placeholder" class="fs-28" v-model="item.value" />
	   			</view>
	   			<!-- email -->
	   			<view v-if="item.name=='texts' && item.valConfig.tabVal == 3" class="discount">
	   			  <input type="text" :placeholder="item.tipConfig.value" placeholder-class="placeholder" class="fs-28" v-model="item.value" />
	   			</view>
	   			<!-- data -->
	   			<view v-if="item.name=='dates'" class="discount">
	   			  <picker mode="date" :value="item.value" @change="bindDateChange($event,index,xindex)">
	   			    <view class="acea-row row-between-wrapper">
	   			      <view v-if="item.value == ''">{{item.tipConfig.value}}</view>
	   			      <view v-else>{{item.value}}</view>
	   			      <text class='iconfont icon-jiantou'></text>
	   			    </view>
	   			  </picker>
	   			</view>
	   			<!-- dateranges -->
	   			<view v-if="item.name=='dateranges'" class="discount">
	   				<uni-datetime-picker v-model="item.value" type="daterange" @maskClick="maskClick">
	   				{{item.value.length?item.value[0]+' - '+item.value[1]:item.tipConfig.value}}
	   				<text class='iconfont icon-jiantou'></text>
	   				</uni-datetime-picker>
	   			</view>
	   			<!-- time -->
	   			<view v-if="item.name=='times'" class="discount">
	   			  <picker mode="time" :value="item.value" @change="bindTimeChange($event,index,xindex)"
	   			    :placeholder="item.tipConfig.value">
	   			    <view class="acea-row row-between-wrapper">
	   			      <view v-if="item.value == ''">{{item.tipConfig.value}}</view>
	   			      <view v-else>{{item.value}}</view>
	   			      <text class='iconfont icon-jiantou'></text>
	   			    </view>
	   			  </picker>
	   			</view>
	   			<!-- timeranges -->
	   			<view v-if="item.name=='timeranges'" class="discount acea-row row-between-wrapper" @click="getTimeranges(index,xindex)">
	   				<view v-if="item.value">{{item.value}}</view>
	   				<view v-else>{{item.tipConfig.value}}</view>
	   				<text class='iconfont icon-jiantou'></text>
	   			</view>
	   			<!-- select -->
	   			<view v-if="item.name=='selects'" class="discount">
	   				<picker :value="item.value" :range="item.wordsConfig.list" @change="bindSelectChange($event,index,item,xindex)" range-key="val">
	   				 <view class="acea-row row-between-wrapper">
	   				    <view v-if="item.value == ''">请选择</view>
	   				    <view v-else>{{item.value}}</view>
	   				    <text class='iconfont icon-jiantou'></text>
	   				  </view>
	   				</picker>
	   			</view>
	   			<!-- city -->
	   			<view v-if="item.name=='citys'" class="discount" @click="changeRegion(index,xindex)">
	   				<view class="acea-row row-middle row-right">
	   					<view class="city" v-if="item.value == ''">{{item.tipConfig.value}}</view>
	   					<view class="city" v-else>{{item.value}}</view>
	   					<text class='iconfont icon-jiantou'></text>
	   				</view>
	   			</view>
	   			<!-- id -->
	   			<view v-if="item.name=='texts' && item.valConfig.tabVal == 2" class="discount">
	   			  <input type="idcard" :placeholder="item.tipConfig.value" placeholder-class="placeholder" class="fs-28" v-model="item.value" />
	   			</view>
	   			<!-- phone -->
	   			<view v-if="item.name=='texts' && item.valConfig.tabVal == 1" class="discount">
	   			  <input type="number" :placeholder="item.tipConfig.value" placeholder-class="placeholder" class="fs-28" v-model="item.value" />
	   			</view>
	   			<!-- img -->
	   			<view v-if="item.name=='uploadPicture'" class="flex-1">
	   				<view class="flex justify-end" v-if="item.value.length < 3">
	   					<view class="relative" v-for="(items,indexs) in item.value" :key="indexs">
	   						<image class="w-128 h-128 rd-12rpx ml-16" :src="items"></image>
	   						<view class="abs-rt w-32 h-32 bg--w111-bbb clear-btn flex-center fs-24 text--w111-fff" @click="DelPic(index,indexs,xindex)">
	   							<text class="iconfont icon-ic_close"></text>
	   						</view>
	   					</view>
	   					 <view class="w-128 h-128 rd-12rpx bg--w111-f5f5f5 flex-col flex-center ml-16"
	   						v-if="item.value.length < item.numConfig.val" @tap="uploadpic(index,xindex)">
	   						 <text class='iconfont icon-ic_camera fs-40'></text>
	   						 <view class="fs-20 text--w111-333">上传图片</view>
	   					 </view>
	   				</view>
	   				<view class="flex justify-end" v-else>
	   					<scroll-view scroll-x="true" scroll-with-animation
	   						class="white-nowrap vertical-middle w-508" show-scrollbar="false">
	   						<view class="w-full h-full flex">
	   							<view class="inline-block h-128 mr-12">
	   								<view class="w-128 h-128 rd-12rpx bg--w111-f5f5f5 ml-16 flex-col flex-center"
	   									v-if="item.value.length < item.numConfig.val" @tap="uploadpic(index,xindex)">
	   									 <text class='iconfont icon-ic_camera fs-40'></text>
	   									 <view class="fs-20 text--w111-333">上传图片</view>
	   								</view>
	   							</view>
	   							<view class="inline-block mr-12 relative" v-for="(items,indexs) in item.value" :key="index">
	   								<image class="w-128 h-128 rd-12rpx" :src="items"></image>
	   								<view class="abs-rt w-32 h-32 bg--w111-bbb rd-rt-12rpx flex-center fs-24 text--w111-fff" @click="DelPic(index,indexs,xindex)">
	   									<text class="iconfont icon-ic_close"></text>
	   								</view>
	   							</view>
	   						</view>
	   					</scroll-view>
	   				</view>
	   			</view>
	   		</view>
	   	</view>
	   </view>
	   <view :class="orderId ? 'heights-order' : 'heights'"></view>
	   <view class="booking-footer" v-if="orderId">
		   <view class="booking-submit-btn" @click="butlerEdit ? reservationButlerUpdate() : reservationOrderCreate()">{{ butlerEdit ? '保存修改' : '立即预约' }}</view>
	   </view>
	   <view class="footer acea-row row-middle" v-else>
		   <view class="w-190">
			   <baseMoney
			   :money="computedPrice.deduction.pay_price"
			   color="#07CD9A"
			   symbolSize="24"
			   integerSize="36"
			   decimalSize="24"></baseMoney>
			   <view class="fs-24 text--w111-999 ml-2 mt-6" @click="openPerferentDrawer">优惠明细<text class="iconfont icon-ic_downarrow fs-24 ml-8"></text></view>
		   </view>
		   <view class="flex-1">
			   <view class="acea-row row-middle" v-if="productInfo.reservation_timing_type == 1">
				   <view @click="confirmOrder(1)" class="purchase mr-20 w-260 h-72 rd-50rpx text--w111-fff fs-26 acea-row row-center-wrapper">暂不预约，先购买</view>
				   <view @click="confirmOrder(2)" class="flex-1 h-72 booking-confirm-btn rd-50rpx text--w111-fff fs-26 acea-row row-center-wrapper">确认下单</view>
			   </view>
			   <view class="acea-row row-middle" v-else-if="productInfo.reservation_timing_type == 2">
			   	   <view @click="confirmOrder(2)" class="flex-1 h-72 booking-confirm-btn rd-50rpx text--w111-fff fs-26 acea-row row-center-wrapper">确认下单</view>
			   </view>
			   <view v-else>
				   <view @click="confirmOrder(1)" class="purchase flex-1 h-72 rd-50rpx text--w111-fff fs-26 acea-row row-center-wrapper">暂不预约，先购买</view>
			   </view>
		   </view>
	   </view>
	   <!-- 优惠弹窗 -->
	   <preferential-modal
	   	:visible="showPerferentDrawer"
	   	:discountInfo="discountInfo"
	   	:coupon="coupon"
	   	:computedPrice="computedPrice"
	   	@ChangCouponsUseState="ChangCouponsUseState"
	   	@closeDrawer="()=>{showPerferentDrawer = false}"
	   	@ruleToggle="ruleToggle"></preferential-modal>
		<cusPreviewImg ref="cusPreviewImg" :list="attrList" @changeSwitch="changeSwitch"></cusPreviewImg>
		<timeranges :isShow='isShow' :time='timeranges' @confrim="confrim" @cancel="cancels"></timeranges>
		<areaWindow ref="areaWindow" :display="display" :address='addressInfoArea' :cityShow='cityShow' @submit="OnAreaAddress" @changeClose="changeAddressClose"></areaWindow>

		<!-- 服务老师（多选） -->
		<view class="staff-mask" v-if="staffPickerVisible" @click="staffPickerVisible = false">
			<view class="staff-panel staff-picker-panel" @click.stop="">
				<view class="staff-panel-title">选择服务老师</view>
				<view class="staff-picker-tip">可多选，点「点客」标记指定老师</view>
				<scroll-view scroll-y class="staff-list">
					<view
						class="staff-item acea-row row-between-wrapper"
						v-for="item in staffList"
						:key="item.id"
						:class="{ active: isStaffSelected(item.id), disabled: isStaffBusy(item.id) }"
						@click="toggleStaffItem(item)"
					>
						<view class="acea-row row-middle flex-1 min-w-0">
							<text class="iconfont staff-check-icon" :class="isStaffSelected(item.id) ? 'icon-a-ic_CompleteSelect' : 'icon-ic_unselect'"></text>
							<view class="staff-item-info">
								<text class="staff-item-name">{{ item.staff_name }}</text>
								<text v-if="item.position_label" class="staff-item-meta">{{ item.position_label }}</text>
							</view>
						</view>
						<view
							v-if="isStaffSelected(item.id)"
							class="dian-btn"
							:class="{ on: isStaffDian(item.id) }"
							@click.stop="toggleStaffDian(item)"
						>点客</view>
						<text v-else-if="isStaffBusy(item.id)" class="staff-busy-tag">忙碌</text>
					</view>
					<view v-if="!staffList.length" class="staff-empty">暂无可选老师</view>
				</scroll-view>
				<view class="staff-picker-footer acea-row">
					<view class="staff-footer-btn reset" @click="clearStaffChoose">清空</view>
					<view class="staff-footer-btn confirm" @click="confirmStaffPicker">确定</view>
				</view>
			</view>
		</view>

		<!-- 加项选择 -->
		<view class="staff-mask" v-if="addonPickerVisible" @click="addonPickerVisible = false">
			<view class="staff-panel addon-picker-panel" @click.stop="">
				<view class="staff-panel-title">添加项目</view>
				<view class="addon-picker-tabs acea-row">
					<view
						class="addon-picker-tab"
						:class="{ active: addonPickerTab === 1 }"
						@click="switchAddonTab(1)"
					>已购项目</view>
					<view
						class="addon-picker-tab"
						:class="{ active: addonPickerTab === 2 }"
						@click="switchAddonTab(2)"
					>全部项目</view>
				</view>
				<view class="addon-search-wrap">
					<text class="iconfont icon-ic_search addon-search-icon"></text>
					<input
						v-model="addonPickerSearch"
						class="addon-search-input"
						placeholder="搜索项目名称"
						confirm-type="search"
					/>
				</view>
				<scroll-view scroll-y class="staff-list">
					<view v-if="addonPickerLoading" class="staff-empty">加载中...</view>
					<view v-else-if="!filteredAddonPickerOptions.length" class="staff-empty">暂无可选项目</view>
					<view
						v-else
						class="staff-item"
						v-for="item in filteredAddonPickerOptions"
						:key="item._key"
						@click="toggleAddonItem(item)"
					>
						<view class="acea-row row-between-wrapper">
							<view class="flex-1 min-w-0">
								<view class="line1">{{ item.label }}</view>
								<view class="addon-item-meta" v-if="item.durationText">{{ item.durationText }}</view>
							</view>
							<text class="iconfont ml-12" :class="isAddonSelected(item._key) ? 'icon-a-ic_CompleteSelect text-color' : 'icon-ic_unselect'"></text>
						</view>
					</view>
				</scroll-view>
				<view class="addon-confirm-btn" @click="confirmAddonPicker">确定</view>
			</view>
		</view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import chooseTime from '@/components/chooseTime/index.vue';
import preferentialModal from '@/components/preferentialModal/index.vue';
import cusPreviewImg from '@/components/cusPreviewImg';
import timeranges from '@/components/timeranges';
import areaWindow from '@/components/areaWindow';
import colors from "@/mixins/color";
import confirm from "@/mixins/confirm";
import { Debounce } from '@/utils/validate.js'
import {getReservationInfo,getReservationDate,getReservationTimes,postReservationCompute,postReservationSwitch,getReservationStaffList,getStaffAvailableTime,getBusyStaffAtTime,getStaffReservationConflicts} from '@/api/activity.js';
import {postCartAdd, getProductslist, storeReservationDetail, storeReservationUpdate} from '@/api/store.js';
import { merchantReservationDetail, merchantReservationUpdate } from '@/api/merchant.js';
import { setCouponReceive } from '@/api/api.js';
import {getReservationOrderInfo,postReservationOrderCreate,getUserPurchasedRemainItems} from '@/api/order.js';
import { isMobileReservationOpen } from '@/utils/mobileReservation.js';
import { openYuyueSubscribe, openGuanjiaSubscribe } from '@/utils/SubscribeMessage.js';
import { resolveProjectDuration, resolveAddonDuration } from '@/utils/serviceDuration.js';

const CUSTOM_CARD_PRODUCT_ID = 8154;
export default {
	name: 'reservation',
	components: {
		NavBar,
		chooseTime,
		preferentialModal,
		cusPreviewImg,
		timeranges,
		areaWindow
	},
	mixins: [colors,confirm],
	data() {
		return {
			// 日历组件数据
			targetDate: 0, //本月
			iconColor:'#fff',
			bagColor:'',
			timeArrow:false,
			productId:0,
			storeId:0,
			unique:'',
			attrList:[],
			storeName:'',
			attrStyle:false,
			cartNum:1,
			reservationTimeData:[],
			current:0,
			productInfo:{},
			projectServiceDuration:0,
			addonServiceDuration:0,
			mainItemLabel:'',
			mainCartId:0,
			staffChoose:[],
			pendingStaffChoose:[],
			disabledStaffIds:[],
			staffList:[],
			staffPickerVisible:false,
			selectedTimeRange:null,
			timeBegin:'09:00:00',
			timeEnd:'22:00:00',
			disableTimeSlot:[],
			addonItems:[],
			addonPickerVisible:false,
			addonPickerSelected:[],
			addonPickerTab: 1,
			addonPickerSearch: '',
			addonPickerLoading: false,
			addonPickerOptions: [],
			serviceData:[
				{id:3,name:'上门服务',status:1},
				{id:2,name:'到店服务',status:1}
			],
			serviceList:[], //服务类型
			currentService:0,
			userDate:[],
			targetDay:0,//选中天数
			showPerferentDrawer:false,
			discountInfo: {
				discount: []
			},
			coupon: {
				list: []
			},
			computedPrice:{
				deduction:{
					pay_price:0,
					sum_price:0,
					vip_price:0
				}
			},
			orderId:'',
			bookUid: 0,
			butlerEdit: false,
			fromMerchant: false,
			reservationId: 0,
			reservationName:'',
			maxCartNum:0,
			cartInfoId:0,
			customFormTitle:'',
			reservationDefaultDate:'',
			pid:0,
			cardProductId:0,
			preselectedStaffId: 0,
			preselectedStaffName: '',
			preselectedStaffStoreId: 0,
			preselectedStaffApplied: false,
			preselectedStoreId: 0,
			preselectedStoreName: '',
			preselectedStoreApplied: false,
			configData: this.$Cache.get('BASIC_CONFIG'),
			serviceMark: '',
		}
	},
	computed: {
		confirmShow(){
			let obj = this.orderId && this.confirm.length && this.confirm[0].length;
			return obj;
		},
		pageTitle() {
			return this.butlerEdit ? '修改预约' : '立即预约';
		},
		staffDisplayName() {
			const list = this.staffChoose || [];
			if (!list.length) return '暂不选择';
			return list.map((item) => {
				const name = item.staff_name || '';
				return Number(item.is_dian) === 1 ? `${name}(点)` : name;
			}).join('、');
		},
		staffPickerQuery() {
			if (!this.selectedTimeRange || !this.selectedTimeRange.begin) return {};
			return {
				service_date: this.selectedTimeRange.begin.split(' ')[0],
				reservation_start: this.normalizeClock(this.selectedTimeRange.begin),
				reservation_end: this.normalizeClock(this.selectedTimeRange.end),
				service_duration: this.totalServiceDuration,
		};
	},
		themeColor() {
			const match = String(this.colorStyle || '').match(/--view-theme:\s*([^;]+)/);
			return match ? match[1].trim() : '#7B2941';
		},
		selectedStaffIds() {
			return (this.staffChoose || []).map((item) => Number(item.staff_id)).filter(Boolean);
		},
		totalServiceDuration() {
			let total = resolveProjectDuration(this.projectServiceDuration);
			this.addonItems.forEach(item => {
				total += resolveAddonDuration(item.addon_service_duration);
			});
			return total;
		},
		timeIntervalHours() {
			return Math.max(0.25, this.totalServiceDuration / 60);
		},
		selectedItems() {
			const items = [];
			if (this.mainItemLabel) {
				const dur = resolveProjectDuration(this.projectServiceDuration);
				items.push({
					label: `${this.mainItemLabel} (${dur}分钟)`,
				});
			}
			this.addonItems.forEach(item => {
				const dur = resolveAddonDuration(item.addon_service_duration);
				items.push({
					label: `${item.product_name} (${dur}分钟)`,
				});
			});
			return items;
		},
		validTimeStarts() {
			return (this.reservationTimeData || [])
				.filter(item => item.is_valid)
				.map(item => (item.start || '').substring(0, 5))
				.filter(Boolean);
		},
		selectedTimeLabel() {
			if (!this.selectedTimeRange) return '';
			const begin = (this.selectedTimeRange.begin || '').split(' ')[1] || '';
			const end = (this.selectedTimeRange.end || '').split(' ')[1] || '';
			if (begin && end) return `${begin.substring(0, 5)}~${end.substring(0, 5)}`;
			return begin ? begin.substring(0, 5) : '';
		},
		filteredAddonPickerOptions() {
			const kw = (this.addonPickerSearch || '').trim().toLowerCase();
			const selectedKeys = this.addonItems.map(item => item._key);
			let list = (this.addonPickerOptions || []).filter(item => !selectedKeys.includes(item._key) || this.addonPickerSelected.includes(item._key));
			if (kw) {
				list = list.filter(item => (item.label || '').toLowerCase().includes(kw));
			}
			return list;
		},
		canSelectStore() {
			if (!this.orderId) return true;
			return Number(this.configData?.cross_store_verification) === 1;
		},
		storeListUrl() {
			return `/pages/store/list/index?type=1&isCollage=3&storeId=${this.storeId || ''}&product_id=${this.pid || this.productId}&card_product_id=${this.cardProductId || ''}`;
		},
	},
	onLoad(options){
		this.fromMerchant = options.merchant === '1' || options.from === 'merchant'
			|| (this.$store && this.$store.state.merchant && this.$store.state.merchant.mode === 'merchant');
		this.butlerEdit = options.butlerEdit === '1' || options.butlerEdit === 1;
		this.reservationId = Number(options.reservationId || 0);
		// 商家端店长改预约不依赖买家端「预约功能开关」
		if (!this.fromMerchant && !isMobileReservationOpen(this.configData)) {
			return this.$util.Tips({ title: '预约功能未开启' }, () => {
				uni.navigateBack();
			});
		}
		let m = (parseInt(new Date().getMonth() + 1) < 10 ? '0' : '') + parseInt(new Date().getMonth() + 1);
		let d = (parseInt(new Date().getDate()) < 10 ? '0' : '') + parseInt(new Date().getDate());
		this.targetDate = parseInt(new Date().getFullYear()) + '-' + m;
		this.targetDay = parseInt(new Date().getFullYear()) + '-' + m + '-'+ d;
		this.productId = options.id;
		this.storeId = options.store_id;
		this.unique = options.unique;
		this.orderId = options.orderId;
		this.cartInfoId = options.cartInfoId;
		this.bookUid = Number(options.book_uid || 0);
		this.initPreselectedContext(options);
		if (!this.storeId && this.preselectedStoreId) {
			this.storeId = this.preselectedStoreId;
			this.storeName = this.preselectedStoreName;
		}
	},
	mounted(){
		if (this.butlerEdit && this.reservationId) {
			this.loadButlerEditReservation();
		} else if(this.orderId){
			this.reservationOrderInfo();
		}else{
			this.reservationInfo();
		}
		uni.$on('activeReservation', this.storeChange);
	},
	methods: {
		buildAddonPayload() {
			return this.addonItems.map(item => {
				const payload = {
					product_id: item.product_id,
					unique: item.unique || '',
					product_name: item.product_name || item.label,
					addon_service_duration: resolveAddonDuration(item.addon_service_duration),
					cart_info_id: Number(item.cart_info_id) || 0,
				};
				if (item.oid) {
					payload.oid = Number(item.oid);
				}
				return payload;
			});
		},
		normalizeClock(value) {
			if (!value) return '';
			const part = value.indexOf(' ') >= 0 ? value.split(' ')[1] : value;
			return part.substring(0, 5);
		},
		buildBookUidParams(extra = {}) {
			if (this.bookUid > 0) {
				return { ...extra, book_uid: this.bookUid };
			}
			return extra;
		},
		reservationOrderCreate(){
			if(!this.selectedTimeRange || !this.selectedTimeRange.begin){
				return this.$util.Tips({ title: '请选择时间段' });
			}
			if(!this.verify()){
				return
			}
			let data = {
				cart_num:this.cartNum,
				reservation_time:this.targetDay,
				reservation_start: this.normalizeClock(this.selectedTimeRange.begin),
				reservation_end: this.normalizeClock(this.selectedTimeRange.end),
				custom_form:this.confirm,
				cart_info_id:this.cartInfoId,
				store_id:this.storeId,
				service_duration_minutes: this.totalServiceDuration,
				addon_items: this.buildAddonPayload(),
				mark: (this.serviceMark || '').trim(),
				...this.buildStaffPayload(),
			};
			if (this.bookUid > 0) {
				data.book_uid = this.bookUid;
			}
			// #ifdef MP
			openYuyueSubscribe();
			// #endif
			postReservationOrderCreate(this.orderId,data).then(res=>{
				let url = `/pages/goods/reservation_status/index?orderId=${this.orderId}`;
				const reservationId = Number(res.data && (res.data.reservationId || res.data.id) || 0);
				if (reservationId > 0) {
					url += `&reservationId=${reservationId}`;
				}
				if (this.bookUid > 0) {
					url += `&book_uid=${this.bookUid}`;
				}
				uni.navigateTo({ url });
			}).catch(err=>{
				return this.$util.Tips({ title: err });
			})
		},
		merchantContextParams() {
			return {
				active_store_id: (this.$store && this.$store.state.merchant && this.$store.state.merchant.activeStoreId) || 0,
				active_role: (this.$store && this.$store.state.merchant && this.$store.state.merchant.activeRole) || '',
			};
		},
		reservationButlerUpdate() {
			if (!this.reservationId) return;
			if (!this.selectedTimeRange || !this.selectedTimeRange.begin) {
				return this.$util.Tips({ title: '请选择时间段' });
			}
			if (!this.verify()) return;
			const data = {
				reservation_time: this.targetDay,
				reservation_start: this.normalizeClock(this.selectedTimeRange.begin),
				reservation_end: this.normalizeClock(this.selectedTimeRange.end),
				service_duration_minutes: this.totalServiceDuration,
				addon_items: this.buildAddonPayload(),
				custom_form: this.confirm[0] || [],
				mark: (this.serviceMark || '').trim(),
				...this.buildStaffPayload(),
			};
			// #ifdef MP
			openGuanjiaSubscribe();
			// #endif
			const req = this.fromMerchant
				? merchantReservationUpdate(this.reservationId, { ...data, ...this.merchantContextParams() })
				: storeReservationUpdate(this.reservationId, data);
			req.then(res => {
				this.$util.Tips({ title: res.msg || '修改成功' }, () => {
					uni.navigateBack();
				});
			}).catch(err => {
				this.$util.Tips({ title: err.msg || err || '修改失败' });
			});
		},
		loadButlerEditReservation() {
			const req = this.fromMerchant
				? merchantReservationDetail(this.reservationId, this.merchantContextParams())
				: storeReservationDetail(this.reservationId);
			return req.then(res => {
				const detail = res.data || {};
				this.orderId = detail.oid || this.orderId;
				this.cartInfoId = detail.cart_info_id || this.cartInfoId;
				this.productId = detail.product_id || this.productId;
				this.storeId = detail.store_id || this.storeId;
				this.storeName = detail.storeInfo?.name || detail.store_name || this.storeName;
				this.unique = detail.sku_unique || this.unique;
				this.applyReservationAddonItems(detail);
				this.applyReservationTime(detail);
				this.staffChoose = JSON.parse(JSON.stringify(this.decodeStaffChooseField(detail.staff_choose)));
				if (!this.staffChoose.length && detail.service_staff_id) {
					this.staffChoose = [{
						staff_id: Number(detail.service_staff_id),
						staff_name: detail.staff_name || '',
						is_dian: 0,
					}];
				}
				return getReservationOrderInfo(this.orderId, this.buildBookUidParams({
					cart_info_id: this.cartInfoId,
				})).then(orderRes => {
					this.applyOrderReservationData(orderRes.data || {}, detail);
				});
			}).catch(err => {
				return this.$util.Tips({ title: err.msg || err || '加载预约信息失败' }, () => {
					uni.navigateBack();
				});
			});
		},
		applyOrderReservationData(data, editDetail = null) {
			this.serviceMark = String(editDetail?.mark ?? data.mark ?? '').trim();
			this.customFormTitle = data.custom_form_title;
			this.maxCartNum = data.cart_num;
			this.storeId = data.store_id || this.storeId;
			this.productId = data.product_id || this.productId;
			this.unique = data.unique || this.unique;
			this.productInfo = this.productInfo || {};
			this.productInfo.is_show_stock = data.is_show_stock;
			this.projectServiceDuration = resolveProjectDuration(data.project_service_duration);
			this.addonServiceDuration = resolveAddonDuration(data.addon_service_duration);
			this.mainItemLabel = data.sku_name ? `${data.product_name} ${data.sku_name}` : (data.product_name || '');
			this.mainCartId = Number(data.cart_id) || 0;
			this.reservationName = data.reservation_type == 3 ? '上门服务' : '到店服务';
			this.storeName = data.store_name || this.storeName;
			this.pid = data.pid;
			this.cardProductId = data.card_product_id;
			const savedForm = editDetail?.reservation_info;
			if (savedForm && savedForm.length) {
				this.applySavedReservationForm(savedForm);
			} else if (data.custom_form) {
				this.confirmInfo(data.custom_form, this.cartNum || 1);
			}
			return this.applyPreselectedStore().finally(() => {
				this.loadStaffList();
			});
		},
		applySavedReservationForm(savedInfo) {
			const formRows = Array.isArray(savedInfo) ? savedInfo : [];
			if (!formRows.length) return;
			const cloned = JSON.parse(JSON.stringify(formRows));
			this.confirm = [cloned];
			this.confirmDefault = JSON.parse(JSON.stringify(formRows));
			this.cartNum = 1;
		},
		decodeAddonItemsField(value) {
			if (Array.isArray(value)) return value;
			if (typeof value === 'string' && value) {
				try {
					const parsed = JSON.parse(value);
					return Array.isArray(parsed) ? parsed : [];
				} catch (e) {
					return [];
				}
			}
			return [];
		},
		applyReservationAddonItems(detail) {
			const addonList = this.decodeAddonItemsField(detail.addon_items);
			this.addonItems = addonList.map((addon, idx) => {
				const addonDur = resolveAddonDuration(addon.addon_service_duration);
				const cartInfoId = Number(addon.cart_info_id) || 0;
				const oid = Number(addon.oid || detail.oid) || 0;
				const productId = Number(addon.product_id) || 0;
				const productName = addon.product_name || addon.label || '';
				return {
					_key: cartInfoId ? `addon_${oid}_${cartInfoId}` : `addon_edit_${idx}_${productId}`,
					label: productName,
					product_id: productId,
					unique: addon.unique || '',
					oid,
					cart_info_id: cartInfoId,
					product_name: productName,
					addon_service_duration: addonDur,
					durationText: `${addonDur}分钟`,
					sourceType: cartInfoId ? 1 : 2,
				};
			});
		},
		applyReservationTime(detail) {
			const date = detail.reservation_time || '';
			const start = (detail.reservation_start || '').substring(0, 5);
			const end = (detail.reservation_end || '').substring(0, 5);
			if (!date || !start) return;
			this.targetDay = date;
			this.selectedTimeRange = {
				begin: `${date} ${start}:00`,
				end: end ? `${date} ${end}:00` : `${date} ${start}:00`,
			};
		},
		getExcludeReservationId() {
			return this.butlerEdit && this.reservationId ? Number(this.reservationId) : 0;
		},
		reservationOrderInfo(){
			getReservationOrderInfo(this.orderId, this.buildBookUidParams({
				cart_info_id:this.cartInfoId,
			})).then(res=>{
				this.applyOrderReservationData(res.data || {});
			}).catch(err=>{
				return this.$util.Tips({ title: err });
			})
		},
		isCustomCardProduct(item) {
			if (!item) return false;
			const id = Number(item.id || item.product_id || 0);
			const pid = Number(item.pid || 0);
			return id === CUSTOM_CARD_PRODUCT_ID || pid === CUSTOM_CARD_PRODUCT_ID;
		},
		filterAllReservationProducts(list) {
			return (list || []).filter(item => Number(item.product_type) === 6 && !this.isCustomCardProduct(item));
		},
		buildServiceLabel(name, cardName, sku, remain) {
			let label = cardName ? `${name}（${cardName}）` : name;
			if (sku) label += ` / ${sku}`;
			if (remain != null) label += `（余${remain}次）`;
			return label;
		},
		parseCartAddonRows(order, rows, cardName) {
			const options = [];
			(rows || []).forEach((item) => {
				if (Number(item.product_type) !== 6) return;
				if (Number(item.id) === Number(this.cartInfoId)) return;
				const canBook = Number(item.write_surplus_times) > 0 && Number(item.is_writeoff) !== 1;
				if (!canBook) return;
				const pinfo = item.cart_info?.productInfo || {};
				const attrInfo = pinfo.attrInfo || {};
				const sku = attrInfo.suk || attrInfo.suk || '';
				let storeName = pinfo.store_name || '';
				if (Number(item.is_gift) === 1) {
					storeName = `赠送${storeName}`;
				}
				let label = item.display_name || this.buildServiceLabel(storeName, cardName, sku, item.write_surplus_times);
				if (item.display_name) {
					if (sku) label += ` / ${sku}`;
					if (item.write_surplus_times != null) label += `（余${item.write_surplus_times}次）`;
				}
				const addonDur = resolveAddonDuration(pinfo.addon_service_duration || item.addon_service_duration || this.addonServiceDuration);
				options.push({
					_key: `addon_${order.id}_${item.id}`,
					label,
					product_id: item.product_id,
					unique: item.sku_unique || attrInfo.unique || '',
					oid: order.id,
					cart_info_id: item.id,
					product_name: storeName || label,
					addon_service_duration: addonDur,
					durationText: `${addonDur}分钟`,
					sourceType: 1,
				});
			});
			return options;
		},
		parsePurchasedAddonOptions(list) {
			const options = [];
			(list || []).forEach(({ order, cart_info: rows }) => {
				const isCardOrder = Number(order.type) === 11 || Number(order.product_type) === 5;
				let cardName = '';
				if (isCardOrder && rows && rows.length) {
					const mainRow = rows.find(row => Number(row.cart_type) === 0);
					cardName = mainRow?.cart_info?.productInfo?.store_name || order.store_name || '';
				}
				options.push(...this.parseCartAddonRows(order, rows, cardName));
			});
			return options;
		},
		loadAddonPickerOptions() {
			this.addonPickerLoading = true;
			if (this.addonPickerTab === 2) {
				getProductslist({
					store_name: (this.addonPickerSearch || '').trim(),
					page: 1,
					limit: 100,
				}).then(res => {
					const list = this.filterAllReservationProducts(res.data?.list || res.data || []);
					this.addonPickerOptions = list.map(item => {
						const addonDur = resolveAddonDuration(item.addon_service_duration);
						return {
							_key: `addon_all_${item.id}`,
							label: item.store_name,
							product_id: item.id,
							unique: '',
							cart_info_id: 0,
							product_name: item.store_name,
							addon_service_duration: addonDur,
							durationText: `${addonDur}分钟`,
							sourceType: 2,
						};
					});
				}).catch(() => {
					this.addonPickerOptions = [];
				}).finally(() => {
					this.addonPickerLoading = false;
				});
				return;
			}
			getUserPurchasedRemainItems(this.buildBookUidParams({ store_id: this.storeId || 0 })).then(res => {
				this.addonPickerOptions = this.parsePurchasedAddonOptions(res.data?.list || []);
			}).catch(() => {
				this.addonPickerOptions = [];
			}).finally(() => {
				this.addonPickerLoading = false;
			});
		},
		openAddonPicker() {
			this.addonPickerTab = 1;
			this.addonPickerSearch = '';
			this.addonPickerSelected = this.addonItems.map(item => item._key);
			this.addonPickerVisible = true;
			this.loadAddonPickerOptions();
		},
		switchAddonTab(tab) {
			this.addonPickerTab = tab;
			this.addonPickerSelected = this.addonItems.map(item => item._key);
			this.loadAddonPickerOptions();
		},
		isAddonSelected(key) {
			return this.addonPickerSelected.includes(key);
		},
		toggleAddonItem(item) {
			const idx = this.addonPickerSelected.indexOf(item._key);
			if (idx >= 0) {
				this.addonPickerSelected.splice(idx, 1);
			} else {
				this.addonPickerSelected.push(item._key);
			}
		},
		confirmAddonPicker() {
			const merged = [...this.addonItems];
			this.addonPickerOptions.forEach(item => {
				if (this.addonPickerSelected.includes(item._key) && !merged.find(row => row._key === item._key)) {
					merged.push(item);
				}
			});
			this.addonItems = merged.filter(item => this.addonPickerSelected.includes(item._key));
			this.addonPickerVisible = false;
			this.selectedTimeRange = null;
			this.loadStaffList();
		},
		openStaffPicker() {
			if (!this.selectedTimeRange || !this.selectedTimeRange.begin) {
				return this.$util.Tips({ title: '请先选择预约时间' });
			}
			this.loadStaffList().finally(() => {
				this.pendingStaffChoose = JSON.parse(JSON.stringify(this.staffChoose || [])).filter((item) => {
					const staff = (this.staffList || []).find((row) => Number(row.id) === Number(item.staff_id));
					return staff && this.isReservationStaffSelectable(staff);
				});
				this.loadBusyStaffForPicker().finally(() => {
					this.staffPickerVisible = true;
				});
			});
		},
		isStaffSelected(staffId) {
			return (this.pendingStaffChoose || []).some((item) => Number(item.staff_id) === Number(staffId));
		},
		isStaffDian(staffId) {
			const row = (this.pendingStaffChoose || []).find((item) => Number(item.staff_id) === Number(staffId));
			return row ? Number(row.is_dian) === 1 : false;
		},
		isStaffBusy(staffId) {
			const busyIds = (this.disabledStaffIds || []).map((id) => Number(id));
			return busyIds.includes(Number(staffId)) && !this.isStaffSelected(staffId);
		},
		toggleStaffItem(staff) {
			if (!staff || !staff.id) return;
			if (!this.isReservationStaffSelectable(staff)) return;
			if (this.isStaffBusy(staff.id)) return;
			const idx = (this.pendingStaffChoose || []).findIndex((item) => Number(item.staff_id) === Number(staff.id));
			if (idx >= 0) {
				this.pendingStaffChoose.splice(idx, 1);
				return;
			}
			this.pendingStaffChoose.push({
				staff_id: staff.id,
				staff_name: staff.staff_name,
				position_label: staff.position_label || '',
				position: staff.position || 0,
				position_level: staff.position_level || 0,
				position_level_label: staff.position_level_label || '',
				yeji: 0,
				is_dian: 0,
			});
		},
		toggleStaffDian(staff) {
			if (!staff || !staff.id) return;
			const row = (this.pendingStaffChoose || []).find((item) => Number(item.staff_id) === Number(staff.id));
			if (!row) return;
			row.is_dian = Number(row.is_dian) === 1 ? 0 : 1;
		},
		clearStaffChoose() {
			this.pendingStaffChoose = [];
			this.staffChoose = [];
			this.staffPickerVisible = false;
			this.loadAvailableTime();
		},
		confirmStaffPicker() {
			const staffIds = (this.pendingStaffChoose || []).map((item) => item.staff_id).filter(Boolean);
			if (!staffIds.length) {
				this.staffChoose = [];
				this.staffPickerVisible = false;
				this.loadAvailableTime();
				return;
			}
			const query = this.staffPickerQuery;
			if (!query.service_date || !query.reservation_start) {
				this.staffChoose = [...this.pendingStaffChoose];
				this.staffPickerVisible = false;
				this.loadAvailableTime();
				return;
			}
			getStaffReservationConflicts({
				store_id: this.storeId,
				staff_ids: staffIds.join(','),
				service_date: query.service_date,
				reservation_start: query.reservation_start,
				reservation_end: query.reservation_end || '',
				service_duration: query.service_duration,
				exclude_reservation_id: this.getExcludeReservationId(),
			}).then((res) => {
				const list = (res.data && res.data.list) || [];
				if (list.length) {
					const msg = list.map((item) => `${item.staff_name}在${item.reservation_date} ${item.reservation_start}-${item.reservation_end}已有预约`).join('；');
					return this.$util.Tips({ title: msg });
				}
				this.staffChoose = [...this.pendingStaffChoose];
				this.staffPickerVisible = false;
				this.loadAvailableTime();
			}).catch((err) => {
				this.$util.Tips({ title: err || '时段校验失败' });
			});
		},
		getPrimaryStaffId() {
			const chooses = this.staffChoose || [];
			if (!chooses.length) return 0;
			const dian = chooses.find((item) => Number(item.is_dian) === 1);
			return Number((dian || chooses[0]).staff_id) || 0;
		},
		buildStaffPayload() {
			const payload = {};
			const primaryId = this.getPrimaryStaffId();
			if (primaryId) payload.service_staff_id = primaryId;
			const chooses = this.staffChoose || [];
			if (!chooses.length) return payload;
			payload.sync_all = [{
				cart_id: this.mainCartId || 0,
				goods_id: this.productId || 0,
				order_id: Number(this.orderId) || 0,
				type: 3,
				value: 1,
				staffChoose: chooses,
			}];
			return payload;
		},
		loadBusyStaffForPicker() {
			const query = this.staffPickerQuery;
			if (!this.storeId || !query.service_date || !query.reservation_start) {
				this.disabledStaffIds = [];
				return Promise.resolve([]);
			}
			return getBusyStaffAtTime({
				store_id: this.storeId,
				service_date: query.service_date,
				reservation_start: query.reservation_start,
				reservation_end: query.reservation_end || '',
				service_duration: query.service_duration,
				exclude_reservation_id: this.getExcludeReservationId(),
			}).then((res) => {
				this.disabledStaffIds = (res.data && res.data.staff_ids) || [];
				return this.disabledStaffIds;
			}).catch(() => {
				this.disabledStaffIds = [];
				return [];
			});
		},
		initPreselectedContext(options = {}) {
			const staffId = Number(options.service_staff_id || 0);
			if (staffId) {
				this.preselectedStaffId = staffId;
				this.preselectedStaffName = options.service_staff_name ? decodeURIComponent(options.service_staff_name) : '';
			}
			const storeId = Number(options.preselected_store_id || 0);
			if (storeId) {
				this.preselectedStoreId = storeId;
				this.preselectedStoreName = options.preselected_store_name ? decodeURIComponent(options.preselected_store_name) : '';
			}
			try {
				const teacher = uni.getStorageSync('selected_teacher');
				if (!teacher) return;
				if (!this.preselectedStaffId && teacher.id) {
					this.preselectedStaffId = Number(teacher.id);
					this.preselectedStaffName = teacher.staff_name || '';
				}
				if (!this.preselectedStoreId && teacher.store_id) {
					this.preselectedStoreId = Number(teacher.store_id);
					this.preselectedStoreName = teacher.store_name || '';
				}
				if (teacher.store_id) {
					this.preselectedStaffStoreId = Number(teacher.store_id);
				}
			} catch (e) {}
		},
		clearPreselectedContextStorage() {
			try {
				uni.removeStorageSync('selected_teacher');
			} catch (e) {}
		},
		applyPreselectedStore() {
			if (this.preselectedStoreApplied || !this.preselectedStoreId) {
				return Promise.resolve();
			}
			const targetId = Number(this.preselectedStoreId);
			const currentId = Number(this.storeId || 0);
			if (currentId === targetId) {
				if (this.preselectedStoreName) this.storeName = this.preselectedStoreName;
				this.preselectedStoreApplied = true;
				this.preselectedStaffStoreId = targetId;
				return Promise.resolve();
			}
			if (this.orderId && Number(this.configData?.cross_store_verification) !== 1) {
				this.preselectedStoreApplied = true;
				return Promise.resolve();
			}
			this.storeId = targetId;
			if (this.preselectedStoreName) this.storeName = this.preselectedStoreName;
			this.preselectedStoreApplied = true;
			this.preselectedStaffStoreId = targetId;
			if (this.pid) {
				return this.reservationSwitch().catch((err) => {
					this.$util.Tips({ title: err || '切换门店失败' });
				});
			}
			return Promise.resolve();
		},
		clearPreselectedStaffStorage() {
			this.clearPreselectedContextStorage();
		},
		buildStaffChooseRow(staff, isDian = 1) {
			return {
				staff_id: staff.id,
				staff_name: staff.staff_name,
				position_label: staff.position_label || '',
				position: staff.position || 0,
				position_level: staff.position_level || 0,
				position_level_label: staff.position_level_label || '',
				yeji: 0,
				is_dian: isDian,
			};
		},
		applyPreselectedStaff() {
			if (this.butlerEdit) return;
			if (this.preselectedStaffApplied || !this.preselectedStaffId || !this.storeId) return;
			if (this.preselectedStaffStoreId && Number(this.preselectedStaffStoreId) !== Number(this.storeId)) return;
			const staffId = Number(this.preselectedStaffId);
			const exists = (this.staffChoose || []).some((item) => Number(item.staff_id) === staffId);
			if (exists) {
				this.preselectedStaffApplied = true;
				this.clearPreselectedStaffStorage();
				return;
			}
			const matched = (this.staffList || []).find((item) => Number(item.id) === staffId);
			if (!matched || !this.isReservationStaffSelectable(matched)) {
				this.preselectedStaffApplied = true;
				this.clearPreselectedStaffStorage();
				return;
			}
			this.staffChoose = [this.buildStaffChooseRow(matched, 1)];
			this.preselectedStaffApplied = true;
			this.clearPreselectedStaffStorage();
		},
		isReservationStaffSelectable(item) {
			if (!item || !Number(item.id)) return false;
			if (!String(item.staff_name || '').trim()) return false;
			const query = this.staffPickerQuery;
			if (query.service_date && query.reservation_start && Number(item.service_available) === 0) {
				return false;
			}
			return true;
		},
		filterReservationStaffList(list) {
			return (list || []).filter((item) => this.isReservationStaffSelectable(item));
		},
		decodeStaffChooseField(value) {
			let list = value;
			if (typeof list === 'string' && list) {
				try {
					list = JSON.parse(list);
				} catch (e) {
					list = [];
				}
			}
			return Array.isArray(list) ? list : [];
		},
		sanitizeStaffChooseAfterReload() {
			const selectableIds = new Set((this.staffList || []).map((item) => Number(item.id)));
			if (this.butlerEdit) {
				this.staffChoose = (this.staffChoose || []).filter((item) => {
					const staffId = Number(item.staff_id);
					return staffId && (selectableIds.has(staffId) || String(item.staff_name || '').trim());
				});
				this.mergeSavedStaffIntoStaffList();
				return;
			}
			this.staffChoose = (this.staffChoose || []).filter((item) => selectableIds.has(Number(item.staff_id)));
		},
		mergeSavedStaffIntoStaffList() {
			if (!this.butlerEdit) return;
			const list = [...(this.staffList || [])];
			const ids = new Set(list.map((item) => Number(item.id)));
			(this.staffChoose || []).forEach((item) => {
				const staffId = Number(item.staff_id);
				if (!staffId || ids.has(staffId)) return;
				list.unshift({
					id: staffId,
					staff_name: item.staff_name || '',
					position_label: item.position_label || '',
					position: item.position || 0,
					position_level: item.position_level || 0,
					position_level_label: item.position_level_label || '',
					service_available: 1,
				});
				ids.add(staffId);
			});
			this.staffList = list;
		},
		loadStaffList() {
			if (!this.storeId) return Promise.resolve();
			const params = {
				store_id: this.storeId,
				service_date: this.targetDay,
				service_duration: this.totalServiceDuration,
			};
			const query = this.staffPickerQuery;
			if (query.service_date && query.reservation_start) {
				params.service_date = query.service_date;
				params.service_time = query.reservation_start;
			}
			return getReservationStaffList(params).then(res => {
				this.staffList = this.filterReservationStaffList(res.data?.list || res.data || []);
				this.sanitizeStaffChooseAfterReload();
				this.applyPreselectedStaff();
				this.loadAvailableTime();
			}).catch(() => {
				this.staffList = [];
				this.sanitizeStaffChooseAfterReload();
				this.applyPreselectedStaff();
				this.loadAvailableTime();
			});
		},
		loadAvailableTime() {
			if (!this.storeId || !this.targetDay) return;
			const staffIds = this.selectedStaffIds;
			getStaffAvailableTime({
				store_id: this.storeId,
				staff_id: staffIds[0] || 0,
				staff_ids: staffIds.join(','),
				service_date: this.targetDay,
				service_duration: this.totalServiceDuration,
				exclude_reservation_id: this.getExcludeReservationId(),
			}).then(res => {
				const data = res.data || {};
				this.timeBegin = data.begin_time || '09:00:00';
				this.timeEnd = data.end_time || '22:00:00';
				this.disableTimeSlot = data.disable_time_slot || [];
				this.validateSelectedTimeRange();
			}).catch(() => {
				this.disableTimeSlot = [];
				this.validateSelectedTimeRange();
			});
		},
		isSlotOverlap(curBegin, curEnd, busyBegin, busyEnd) {
			return busyBegin < curEnd && busyEnd > curBegin;
		},
		validateSelectedTimeRange() {
			if (!this.selectedTimeRange || !this.selectedTimeRange.begin) return;
			const curBegin = this.selectedTimeRange.begin;
			const curEnd = this.selectedTimeRange.end || curBegin;
			const disabled = (this.disableTimeSlot || []).some((slot) => {
				const [begin_time = '', end_time = ''] = slot;
				return begin_time && end_time && this.isSlotOverlap(curBegin, curEnd, begin_time, end_time);
			});
			if (disabled && !this.butlerEdit) {
				this.selectedTimeRange = null;
				this.$util.Tips({ title: '所选时段已不可预约，请重新选择' });
			}
		},
		onChooseDateChange(date) {
			this.targetDay = date;
			this.selectedTimeRange = null;
			this.loadStaffList();
		},
		onChooseTimeChange(val) {
			if (!val || typeof val !== 'object' || !val.begin) {
				this.selectedTimeRange = null;
				this.staffChoose = [];
				return;
			}
			this.selectedTimeRange = val;
			this.targetDay = val.begin.split(' ')[0];
			this.loadStaffList();
		},
		// 放大图
		showImg(item){
			this.$refs.cusPreviewImg.open(item.attr)
		},
		changeSwitch(e){
			this.current = e;
			this.unique = this.attrList[e].unique;
			this.reservationCompute();
			this.loadStaffList();
		},
		confirmOrder(num){
			// num：1：暂不预约，先购买
			// num：2：确认下单
			if(num==2){
				if(!this.selectedTimeRange || !this.selectedTimeRange.begin){
					return this.$util.Tips({ title: '请选择时间段' });
				}
			}
			let reservationType = 2;
			let data = {
				cartNum: this.cartNum,
				new: 1,
				uniqueId: this.unique,
				store_id: this.storeId,
				productId: this.productId,
				reservation_type: reservationType
			}
			if(num==2){
				data.reservation_time = this.targetDay
				data.reservation_start = this.normalizeClock(this.selectedTimeRange.begin)
				data.reservation_end = this.normalizeClock(this.selectedTimeRange.end)
				data.service_duration_minutes = this.totalServiceDuration
				data.addon_items = this.buildAddonPayload()
				Object.assign(data, this.buildStaffPayload())
			}
			postCartAdd(data).then(res=>{
				let url = `/pages/goods/order_confirm/index?new=1&cartId=${res.data.cartId}&store_id=${this.storeId}&store_name=${this.storeName}&product_id=${this.productId}&is_store=${this.storeId ? 1 : 0}&productType=6`
				//领券下单
				if(this.computedPrice.coupon && this.computedPrice.coupon.id){
					if(!this.computedPrice.coupon.used){
						setCouponReceive(this.computedPrice.coupon.id).then(resp => {
							uni.navigateTo({
								url: url + '&couponId=' + resp.data.id + '&couponTitle=' + this.computedPrice.coupon.coupon_title
							});
						}).catch(err => {
							return this.$util.Tips({
								title: err
							});
						})
					}else{
						uni.navigateTo({
							url: url + '&couponId=' + this.computedPrice.coupon.id + '&couponTitle=' + this.computedPrice.coupon.coupon_title
						});
					}
				}else{
					uni.navigateTo({
						url: url
					});
				}
			}).catch(err=>{
				return this.$util.Tips({
					title: err
				});
			})
		},
		reservationCompute(){
			let data = {
				unique:this.unique,
				product_id:this.productId,
				cart_num:this.cartNum,
				store_id:this.storeId
			}
			postReservationCompute(data).then(res=>{
				let data = res.data;
				this.coupon.list = data.coupons;
				this.discountInfo.discount = data.promotions;
				this.computedPrice = data.computed;
			}).catch(err=>{
				return this.$util.Tips({
					title: err
				});
			})
		},
		openPerferentDrawer(){
			this.showPerferentDrawer = true;
		},
		reservationTimes(){
			let data = {
				unique:this.unique,
				date:this.targetDay
			}
			getReservationTimes(this.productId,data).then(res=>{
				this.reservationTimeData = res.data || [];
			}).catch(err=>{
				return this.$util.Tips({ title: err });
			})
		},
		reservationDate(){
			let data = {
				store_id:this.storeId,
				month:this.targetDate
			}
			getReservationDate(this.productId,data).then(res=>{
				this.userDate = res.data;
				if (res.data && res.data[0]) {
					this.targetDay = res.data[0].date;
				}
			}).catch(err=>{
				return this.$util.Tips({ title: err });
			})
		},
		serviceTap(index){
			this.currentService = index;
		},
		// 点击属性
		arrtTap(item,index){
			this.current = index;
			this.unique = item.unique;
			this.reservationCompute();
			this.loadStaffList();
		},
		// 切换属性样式
		attrStyleTap(){
			this.attrStyle = !this.attrStyle;
		},
		// 获取预约信息
		reservationInfo(){
			let data = {
				unique:this.unique,
				store_id:this.storeId
			}
			getReservationInfo(this.productId,data).then(res=>{
				let data = res.data;
				this.storeName = data.storeInfo.name;
				this.reservationTimeData = data.reservationTimeData;
				this.reservationDefaultDate = data.reservationDefaultDate;
				this.targetDay = data.reservationDefaultDate;
				this.productInfo = data.productInfo;
				this.productId = data.productInfo.id;
				this.pid = data.productInfo.pid || data.productInfo.id;
				this.projectServiceDuration = resolveProjectDuration(data.productInfo.project_service_duration);
				this.addonServiceDuration = resolveAddonDuration(data.productInfo.addon_service_duration);
				const sku = data.selectAttrValue?.suk || '';
				this.mainItemLabel = sku ? `${data.productInfo.store_name} ${sku}` : data.productInfo.store_name;
				this.unique = data.selectAttrValue.unique
				if(data.productAttr.length){
					this.attrList = data.productAttr[0].attr_value;
					this.current = data.productAttr[0].attr_values.indexOf(data.selectAttrValue.suk);
					this.attrList.forEach(item=>{
						item.unique = data.productValue[item.attr].unique,
						item.suk = item.attr,
						item.image = item.pic
					})
				}
				this.serviceList = [{ id: 2, name: '到店服务', status: 1 }];
				this.currentService = 0;
				if (data.storeInfo && data.storeInfo.id && !this.storeId) {
					this.storeId = data.storeInfo.id;
				}
				this.reservationDate();
				this.reservationCompute();
				this.applyPreselectedStore().finally(() => {
					this.loadStaffList();
				});
			}).catch(err=>{
				return this.$util.Tips({
					title: err
				});
			})
		},
		addCart(type){
			if(type == 1){
				if(this.cartNum>=this.maxCartNum && this.orderId) return this.$util.Tips({
					title: '已到达你购买的最大人数'
				});
				this.cartNum ++
				if(this.orderId){
					this.confirm.push(this.confirmDefault)
				}
			}else{
				if(this.cartNum <= 1) return
				this.cartNum --
				if(this.orderId){
					this.confirm.pop()
				}
			}
			if(!this.orderId){
				this.reservationCompute();
			}
		},
		setValue: Debounce(function(e){
			this.cartNum = e.detail.value;
		}),
		storeChange({ id, name }) {
			this.storeId = id;
			this.storeName = name;
			this.preselectedStoreApplied = true;
			if (this.preselectedStaffStoreId && Number(this.preselectedStaffStoreId) !== Number(id)) {
				this.staffChoose = [];
				this.preselectedStaffApplied = true;
			}
			this.addonItems = [];
			this.reservationSwitch().then(() => {
				this.reservationDate();
				this.reservationCompute();
				this.loadStaffList();
			});
		},
		reservationSwitch() {
			const switchPid = this.pid || this.productId;
			if (!this.storeId || !switchPid) return Promise.resolve();
			return postReservationSwitch({
				store_id: this.storeId,
				pid: this.pid || this.productId,
				unique: this.unique,
			}).then((res) => {
				const { product_id, unique } = res.data;
				this.productId = product_id;
				this.unique = unique;
				this.reservationDate();
			});
		},
	}
};
</script>

<style lang="scss" scoped>
.booking-page {
	min-height: 100vh;
	background: #fbf7f4;
	padding-bottom: 20rpx;
}
	::v-deep uni-checkbox .uni-checkbox-input{
		border-radius: 3px;
	}
	.cell input{
		width: 450rpx;
		text-align:right;
	}
	.cell .radio {
		margin: 0 22rpx;
		padding: 10rpx 0;
	}
	.cell ~ .cell{
		margin-top: 40rpx;
	}
	.placeholder {
		color: #ccc;
	}
	.asterisk{
		position: absolute;
		color:red;
		left:0;
		padding-top: 2px;
	}
	.bntHui{
		color: #CCCCCC;
		background: #F9F9F9;
		border-color: #F9F9F9 !important;
	}
	.bntActive{
		color: var(--view-theme, #7B2941);
		background: var(--view-minorColorT, #f9e9ed);
		border-color: var(--view-theme, #7B2941) !important;
	}
	.purchase{
		background-color: var(--view-bntColor);
	}
	.booking-confirm-btn{
		background: linear-gradient(135deg, var(--view-gradient, #a45b6d) 0%, var(--view-theme, #7B2941) 100%);
	}
	.footer{
		width: 100%;
		background-color: #fff;
		padding: 0 20rpx;
		position: fixed;
		bottom: 0;
		left:0;
		z-index: 20;
		height: calc(108rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		height: calc(108rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
		padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/
		box-sizing: border-box;
	}
	.heights{
		height: calc(128rpx + env(safe-area-inset-bottom));
	}
	.heights-order{
		height: calc(160rpx + env(safe-area-inset-bottom));
	}
	.time:nth-of-type(3n){
		margin-right: 0;
	}
	.list{
		max-height: 490rpx;
		transition: all 0.3s;
		&.on{
			max-height:unset;
			height: max-content;
		}
	}
	.header{
		background: linear-gradient(135deg, #5c2035 0%, #8d4055 100%);
		padding: 0 20rpx 30rpx 24rpx;
		position: relative;
		&::before{
			position: absolute;
			content: '';
			width: 100%;
			height: 100rpx;
			background: linear-gradient(135deg, #5c2035 0%, #8d4055 100%);
			left: 0;
			bottom: -50rpx;
			border-radius: 0 0 50% 50%;
		}
	}
	.booking-header{
		background: linear-gradient(135deg, #6d2138 0%, #9c5265 100%);
		margin: -20rpx 20rpx 0;
		padding: 32rpx 24rpx;
		border-radius: 24rpx;
		box-shadow: 0 14rpx 28rpx rgba(94,32,53,.2);
		color: #fff;
		position: relative;
		z-index: 2;
	}
	.booking-header-title{
		font-size: 32rpx;
		font-weight: 600;
	}
	.booking-header-count{
		font-size: 24rpx;
		opacity: 0.9;
	}
	.selected-items{
		flex-wrap: wrap;
		margin-top: 16rpx;
		gap: 12rpx;
	}
	.item-tag{
		background: rgba(255,255,255,0.25);
		padding: 12rpx 24rpx;
		border-radius: 30rpx;
		font-size: 24rpx;
		margin-right: 12rpx;
		margin-bottom: 12rpx;
	}
	.service-duration{
		margin-top: 20rpx;
		padding-top: 20rpx;
		border-top: 1rpx solid rgba(255,255,255,0.2);
		font-size: 28rpx;
	}
	.duration{
		font-size: 32rpx;
		font-weight: 600;
	}
	.addon-add-btn{
		margin-top: 20rpx;
		font-size: 26rpx;
		text-align: center;
		padding: 16rpx;
		border: 1rpx dashed rgba(255,255,255,0.6);
		border-radius: 12rpx;
	}
	.info-card{
		margin-top: 20rpx;
		padding: 0 24rpx;
		border: 1rpx solid rgba(123,41,65,.08);
		border-radius: 24rpx;
		box-shadow: 0 10rpx 26rpx rgba(83,45,54,.06);
	}
	.info-row{
		padding: 28rpx 0;
	}
	.info-label{
		font-size: 28rpx;
		color: #4d3037;
		font-weight: 500;
	}
	.info-note{
		font-size: 22rpx;
		color: #999;
		margin-left: 8rpx;
	}
	.info-value{
		font-size: 28rpx;
		color: #80666d;
	}
	.info-divider{
		height: 1rpx;
		background: #f0f0f0;
	}
	.time-section-title{
		color: #4d3037;
	}
	.time-section{
		border: 1rpx solid rgba(123,41,65,.08);
		border-radius: 24rpx;
		box-shadow: 0 10rpx 26rpx rgba(83,45,54,.06);
		padding-bottom: 8rpx;
	}
	.booking-footer{
		position: fixed;
		left: 0;
		right: 0;
		bottom: 0;
		padding: 20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));
		background: #fffdfb;
		box-shadow: 0 -4rpx 20rpx rgba(0, 0, 0, 0.06);
		z-index: 20;
	}
	.booking-submit-btn{
		height: 88rpx;
		line-height: 88rpx;
		text-align: center;
		border-radius: 44rpx;
		background: linear-gradient(135deg, #8e4056 0%, #6d2138 100%);
		color: #fff;
		font-size: 32rpx;
		font-weight: 600;
	}
	.staff-mask{
		position: fixed;
		left: 0;
		top: 0;
		right: 0;
		bottom: 0;
		background: rgba(0,0,0,0.45);
		z-index: 999;
		display: flex;
		align-items: flex-end;
	}
	.staff-panel{
		width: 100%;
		background: #fff;
		border-radius: 24rpx 24rpx 0 0;
		padding: 32rpx 24rpx;
		max-height: 70vh;
	}
	.staff-panel-title{
		font-size: 30rpx;
		font-weight: 600;
		text-align: center;
		margin-bottom: 24rpx;
	}
	.staff-list{
		max-height: 50vh;
	}
	.staff-item{
		padding: 28rpx 16rpx;
		font-size: 28rpx;
		border-bottom: 1rpx solid #f5f5f5;
		&.active{
			color: var(--view-theme, #7B2941);
		}
		&.disabled{
			opacity: 0.55;
		}
	}
	.staff-picker-panel{
		max-height: 78vh;
	}
	.staff-picker-tip{
		text-align: center;
		font-size: 24rpx;
		color: #999;
		margin: -12rpx 0 16rpx;
	}
	.staff-check-icon{
		font-size: 36rpx;
		margin-right: 16rpx;
		color: #ccc;
	}
	.staff-item.active .staff-check-icon{
		color: var(--view-theme, #7B2941);
	}
	.staff-item-info{
		display: flex;
		flex-direction: column;
		min-width: 0;
	}
	.staff-item-name{
		font-size: 28rpx;
		color: #333;
	}
	.staff-item-meta{
		font-size: 22rpx;
		color: #999;
		margin-top: 4rpx;
	}
	.dian-btn{
		padding: 0 20rpx;
		height: 52rpx;
		line-height: 52rpx;
		border-radius: 26rpx;
		font-size: 24rpx;
		color: #666;
		background: #f5f5f5;
		flex-shrink: 0;
		&.on{
			color: var(--view-theme, #7B2941);
			background: var(--view-minorColorT, #f9e9ed);
		}
	}
	.staff-busy-tag{
		font-size: 24rpx;
		color: #999;
		flex-shrink: 0;
	}
	.staff-picker-footer{
		gap: 20rpx;
		margin-top: 24rpx;
	}
	.staff-footer-btn{
		flex: 1;
		height: 80rpx;
		line-height: 80rpx;
		text-align: center;
		border-radius: 40rpx;
		font-size: 28rpx;
	}
	.staff-footer-btn.reset{
		background: #f0f0f0;
		color: #666;
	}
	.staff-footer-btn.confirm{
		background: #7B2941;
		color: #fff;
	}
	.staff-empty{
		text-align: center;
		color: #999;
		padding: 40rpx;
		font-size: 26rpx;
	}
	.addon-confirm-btn{
		margin-top: 24rpx;
		height: 80rpx;
		line-height: 80rpx;
		text-align: center;
		background: #7B2941;
		color: #fff;
		border-radius: 40rpx;
		font-size: 28rpx;
	}
	.addon-picker-panel{
		max-height: 78vh;
	}
	.addon-picker-tabs{
		margin-bottom: 20rpx;
		gap: 16rpx;
	}
	.addon-picker-tab{
		flex: 1;
		text-align: center;
		padding: 16rpx 0;
		font-size: 26rpx;
		color: #666;
		background: #f5f5f5;
		border-radius: 12rpx;
		&.active{
			color: #07CD9A;
			background: #e3fdf7;
			font-weight: 600;
		}
	}
	.addon-search-wrap{
		display: flex;
		align-items: center;
		background: #f5f5f5;
		border-radius: 40rpx;
		padding: 0 24rpx;
		margin-bottom: 20rpx;
		height: 72rpx;
	}
	.addon-search-icon{
		font-size: 28rpx;
		color: #999;
		margin-right: 12rpx;
	}
	.addon-search-input{
		flex: 1;
		font-size: 26rpx;
		height: 72rpx;
	}
	.remark-card{
		padding: 24rpx;
	}
	.remark-title{
		color: #333;
		margin-bottom: 16rpx;
	}
	.remark-textarea{
		width: 100%;
		min-height: 160rpx;
		padding: 20rpx;
		box-sizing: border-box;
		background: #f5f5f5;
		border-radius: 12rpx;
		color: #333;
		line-height: 1.5;
	}
	.addon-item-meta{
		font-size: 22rpx;
		color: #999;
		margin-top: 6rpx;
	}
</style>

<style lang="scss">
.booking-time-picker {
	.item-box.active {
		border-color: #07CD9A !important;
		background: #f0fdf9 !important;
		color: #07CD9A !important;
	}
	.borderb {
		border-bottom-color: #07CD9A !important;
	}
	.all {
		color: #07CD9A;
	}
}
</style>
