<template>
	<Drawer v-model="modals" :scrollable="false">
		<div class="drawer-wrap">
		<div class="title acea-row row-middle">
			<div class="fs-16 text-wlll-303133 fw-500">预约详情</div>
			<div class="label ml-6 w-48 h-23 rd-2 text-wlll-2A7EFB acea-row row-center-wrapper">{{info.status_name}}</div>
		</div>
		<div class="conter">
			<div class="acea-row row-middle">
				<div class="w-3 h-15 bg-w111-1890FF"></div>
				<div class="fs-14 text-wlll-303133 ml10">预约信息</div>
			</div>
			<div class="acea-row row-middle fs-14" v-if="info.cart_info && info.cart_info.productInfo">
				<div class="text-wlll-606266 w-84 text-right">预约服务：</div>
				<div class="flex-1 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">{{info.cart_info.productInfo.store_name}}</div>
			</div>
			<div class="acea-row mt-24 fs-14" v-if="addonProjectList.length">
				<div class="text-wlll-606266 w-84 text-right pt-5">增项服务：</div>
				<div class="flex-1 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 py-12 text-wlll-303133">
					<div v-for="(project, pIndex) in addonProjectList" :key="pIndex" class="project-detail-row" :class="{ 'mt-12': pIndex > 0 }">
						<div class="acea-row row-between-wrapper">
							<div class="line1 flex-1 pr-12">{{ project.product_name }}</div>
							<div class="text-wlll-999">×{{ project.cart_num || 1 }}</div>
						</div>
						<div class="text-wlll-999 fs-12 mt-4" v-if="project.desc">{{ project.desc }}</div>
					</div>
				</div>
			</div>
			<div class="acea-row row-between-wrapper mt-24">
				<div class="acea-row row-middle fs-14">
					<div class="text-wlll-606266 w-84 text-right">预约类型：</div>
					<div class="w-250 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">{{info.reservation_type==2?'到店服务':'上门服务'}}</div>
				</div>
				<div class="acea-row row-middle fs-14" v-if="info.cart_info && info.cart_info.productInfo">
					<div class="text-wlll-606266 w-84 text-right">服务规格：</div>
					<div class="w-250 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">{{ (info.cart_info.productInfo.attrInfo && info.cart_info.productInfo.attrInfo.suk) || '-' }}</div>
				</div>
			</div>
			<div class="acea-row row-between-wrapper mt-24">
				<div class="acea-row row-middle fs-14">
					<div class="text-wlll-606266 w-84 text-right">联系人：</div>
					<Input v-model="info.reservation_name" :disabled='disabled' placeholder="请输入联系人" class="w-250 ml-6"></Input>
				</div>
				<div class="acea-row row-middle fs-14">
					<div class="text-wlll-606266 w-84 text-right">联系电话：</div>
					<Input v-model="info.reservation_phone" :disabled='disabled' type="number" placeholder="请输入联系电话" class="w-250 ml-6"></Input>
				</div>
			</div>
			<div class="acea-row row-between-wrapper mt-24">
				<div class="acea-row row-middle fs-14 flex-1">
					<div class="text-wlll-606266 w-84 text-right">预约时间：</div>
					<div v-if="disabled" class="flex-1 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">
						{{ reservationTimeDisplayText || '-' }}
					</div>
					<div v-else class="acea-row row-middle flex-1 ml-6">
						<Input :value="editReservationTimeDisplay" readonly placeholder="请选择预约时间" class="flex-1" />
						<Button type="primary" ghost class="ml-6" @click="openTimePicker">选择时间</Button>
					</div>
				</div>
			</div>
			<div class="acea-row row-between-wrapper mt-24" v-if="info.reservation_type == 3">
				<div class="acea-row row-middle fs-14 flex-1">
					<div class="text-wlll-606266 w-84 text-right">上门地址：</div>
					<Cascader :transfer='true' v-model="info.reservation_address_city_id" :disabled='disabled' :data="addresData" :load-data="loadData" @on-change="addchack" class="flex-1 ml-6"></Cascader>
				</div>
			</div>
			<div class="acea-row row-between-wrapper mt-24" v-if="info.reservation_type == 3">
				<div class="acea-row row-middle fs-14 flex-1">
					<div class="text-wlll-606266 w-84 text-right">详细地址：</div>
					<Input v-model="reservationAddress" :disabled='disabled' placeholder="请输入详细地址" class="flex-1 ml-6"></Input>
				</div>
			</div>
			<div class="acea-row mt-24 fs-14 staff-row">
				<div class="text-wlll-606266 w-84 text-right staff-label">手艺人：</div>
				<div v-if="canEditStaff" class="staff-value ml-6">
					<div class="staff-display staff-display--editable pointer text-wlll-1890FF" @click="openYeji">
						<span v-if="!syncYeji.staffChoose.length" style="color: #1890FF;font-size: 13px">选择手艺人</span>
						<span v-else style="color: #736a6a;font-size: 13px">{{ formatStaffChooseText() }}</span>
					</div>
				</div>
				<div v-else class="staff-value ml-6 staff-display staff-display--readonly text-wlll-303133">
					{{ formatStaffChooseText() || '-' }}
				</div>
			</div>
			<div v-if="staffConflictTips.length" class="staff-conflict-tips mt-12">
				<div v-for="(tip, index) in staffConflictTips" :key="index">{{ tip }}</div>
			</div>
			<div class="acea-row row-between-wrapper mt-24">
				<div class="acea-row row-middle fs-14 flex-1">
					<div class="text-wlll-606266 w-84 text-right">服务房间：</div>
					<div v-if="disabled" class="flex-1 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">{{ roomDisplayText || '-' }}</div>
					<Select v-else v-model="editTableId" clearable placeholder="可不选，由门店安排" class="flex-1 ml-6" transfer>
						<Option v-for="item in tableList" :key="item.id" :value="item.id">
							{{ item.remarks || item.table_number || ('房间' + item.id) }}
						</Option>
					</Select>
				</div>
			</div>
			<div class="acea-row mt-24 fs-14">
				<div class="text-wlll-606266 w-84 text-right pt-5">服务备注：</div>
				<div v-if="disabled" class="flex-1 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 py-8 text-wlll-303133 remark-display">{{ info.mark || '-' }}</div>
				<Input v-else v-model="editMark" type="textarea" :rows="3" placeholder="请输入备注信息（选填）" class="flex-1 ml-6" />
			</div>
			<div class="acea-row mt-28 fs-14" v-if="isShow && customFormItems.length">
				<div class="text-wlll-606266 w-84 text-right pt-5">{{info.custom_form_title}}信息：</div>
				<div class="acea-row flex-1 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 pb-24">
					<div v-for="(item,index) in customFormItems" :key="index">
						<div class="mr-48 mt-24" v-if="item.name === 'dateranges'">
							{{ item.titleConfig.value }}：{{ item.value[0]+'/'+item.value[1] }}
						</div>
						<div class="acea-row mt-24" v-else-if="item.name === 'uploadPicture'">
							<div>图片：</div>
							<div class="acea-row flex-1" v-viewer>
								<div class="w-58 h-58 mr-8 mb5" v-for="(img, i) in item.value" :key="i">
									<img class="w-full h-full rd-4" :src="img"/>
								</div>
							</div>
						</div>
						<div class="mr-48 mt-24" v-else>
							{{ item.titleConfig.value }}：{{ item.value || "-" }}
						</div>
					</div>
				</div>
			</div>
			<div class="acea-row mt-28 fs-14" v-if="info.service_describe || (info.service_images && info.service_images.length)">
				<div class="text-wlll-606266 w-84 text-right pt-5">服务凭证：</div>
				<div class="flex-1 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 pb-24">
					<div class="mt-24">{{info.service_describe}}</div>
					<div class="acea-row mt-24">
						<div>图片：</div>
						<div class="acea-row flex-1" v-viewer>
							<div class="w-58 h-58 mr-8 mb5" v-for="(img, i) in info.service_images" :key="i">
								<img class="w-full h-full rd-4" :src="img"/>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="acea-row row-middle mt-30 pt-30 border-dashed-top-1-EEEEEE">
				<div class="w-3 h-15 bg-w111-1890FF"></div>
				<div class="fs-14 text-wlll-303133 ml10">预约单信息</div>
			</div>
			<div class="acea-row row-between-wrapper mt-24">
				<div class="acea-row row-middle fs-14">
					<div class="text-wlll-606266 w-84 text-right">预约单号：</div>
					<div class="w-250 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">{{info.order_id}}</div>
				</div>
				<div class="acea-row row-middle fs-14">
					<div class="text-wlll-606266 w-84 text-right">订单号：</div>
					<div class="w-250 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">{{info.store_order_id}}</div>
				</div>
			</div>
			<div class="acea-row row-between-wrapper mt-24" v-if="info.service_time || info.service_end_time">
				<div class="acea-row row-middle fs-14" v-if="info.service_time">
					<div class="text-wlll-606266 w-84 text-right">开始时间：</div>
					<div class="w-250 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">{{info.service_time}}</div>
				</div>
				<div class="acea-row row-middle fs-14" v-if="info.service_end_time">
					<div class="text-wlll-606266 w-84 text-right">结束时间：</div>
					<div class="w-250 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">{{info.service_end_time}}</div>
				</div>
			</div>
			<div class="acea-row row-between-wrapper mt-24" v-if="info.cart_info">
				<div class="acea-row row-middle fs-14">
					<div class="text-wlll-606266 w-84 text-right">实付款：</div>
					<div class="w-250 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">{{info.cart_info.truePrice}}</div>
				</div>
			</div>
		</div>
		<div class="footer" v-if="info.status != -1 && info.status != 2">
			<div v-if="disabled" class="footer-actions">
				<template v-if="info.status == 0">
					<div class="footer-btn footer-btn--default" @click="cancelTap">取消预约</div>
					<div class="footer-btn footer-btn--default" @click="enterEditMode">修改预约</div>
					<div class="footer-btn footer-btn--primary" @click="serviceStart">开始服务</div>
				</template>
				<template v-else-if="info.status == 3">
					<div class="footer-btn footer-btn--default" @click="cancelTap">取消预约</div>
					<div class="footer-btn footer-btn--default" @click="enterEditMode">修改预约</div>
					<div class="footer-btn footer-btn--success" @click="confirmTap">接单</div>
					<div class="footer-btn footer-btn--danger" @click="refuseTap">拒绝</div>
				</template>
				<template v-else-if="info.status == 1">
					<div class="footer-btn footer-btn--primary footer-btn--single" @click="writeTap">立即消耗</div>
				</template>
			</div>
			<div class="footer-actions" v-else>
				<div class="footer-btn footer-btn--default" @click="cancelEdit">取消</div>
				<div class="footer-btn footer-btn--primary" @click="editTap">确定</div>
			</div>
		</div>
		</div>
		<yeji
			@doChoose="doChoose"
			@sureSync="doChoose"
			:isShouyi="true"
			:showApplyAll="false"
			:syncProduct="[syncYeji]"
			:yeji="setYeji"
			:staffIds="staffIds"
			:disabled-staff-ids="disabledStaffIds"
			:reservation-staff-query="reservationStaffQuery"
			@closeYeji="closeYeji"
			:visible="yejiVisible"
			ref="yeji"
		></yeji>
		<choose-time-picker-modal
			:visible="timePickerVisible"
			:can-pick="!!info.product_id"
			:staff-id="getPrimaryStaffId()"
			:staff-ids="getSelectedStaffIds()"
			:exclude-reservation-id="info.id || 0"
			:service-duration="detailServiceDuration"
			:value="selectedTimeRange"
			@close="timePickerVisible = false"
			@confirm="onTimePickerConfirm"
		/>
	</Drawer>
</template>

<script>
	import yeji from '@/components/yeji';
	import chooseTimePickerModal from '@/components/chooseTime/pickerModal';
	import { getOrderDetail, cityApi, postOrderUpdate, postOrderService, getStaffReservationConflicts, getBusyStaffAtTime, postOrderRefuse, getReservationTableList } from "@/api/reservation";
	import { resolveProjectDuration } from '@/utils/serviceDuration';
	export default {
		name: 'orderDetails',
		components: { yeji, chooseTimePickerModal },
		props: {
			staffList: {
			  type: Array,
			  default: () => []
			}
		},
		data(){
			return{
				modals:false,
				info:{},
				isShow: 0,
				disabled:true,
				timePickerVisible: false,
				selectedTimeRange: null,
				addresData:[],
				reservationAddress:'', //上门详细地址
				regionAddress:'', //上门地址
				yejiVisible: false,
				staffIds: [],
				syncYeji: {
					link_id: 0,
					cart_id: 0,
					price: 0,
					goods_id: 0,
					type: 3,
					staffChoose: [],
				},
				setYeji: {
					link_id: 0,
					cart_id: 0,
					price: 0,
					goods_id: 0,
					type: 3,
					staffChoose: [],
				},
				staffConflictTips: [],
				disabledStaffIds: [],
				editSnapshot: null,
				refuseReason: '',
				tableList: [],
				editTableId: null,
				editMark: '',
			}
		},
		computed: {
			roomDisplayText() {
				return this.info.service_room || this.info.table_name || '';
			},
			isPurchasedReservation() {
				if (typeof this.info.is_guest_reservation !== 'undefined') {
					return !this.info.is_guest_reservation;
				}
				return Number(this.info.oid) > 0 && Number(this.info.cart_info_id) > 0;
			},
			detailServiceDuration() {
				const minutes = Number(this.info.service_duration_minutes) || 0;
				if (minutes > 0) return minutes;
				const cartInfo = this.info.cart_info || {};
				const productInfo = cartInfo.productInfo || {};
				return resolveProjectDuration(productInfo.project_service_duration);
			},
			reservationStaffQuery() {
				const q = this.getConflictTimeQuery();
				return {
					service_date: q.serviceDate || '',
					reservation_start: q.reservationStart || '',
					reservation_end: q.reservationEnd || '',
					service_duration: Number(this.info.service_duration_minutes) || this.detailServiceDuration || 0,
					exclude_reservation_id: this.info.id || 0,
				};
			},
			reservationTimeDisplayText() {
				const date = this.formatReservationDate(this.info.reservation_time);
				const start = this.info.reservation_start || '';
				const end = this.info.reservation_end || '';
				if (date && start && end) return `${date} ${start}-${end}`;
				if (date && start) return `${date} ${start}`;
				if (date && this.info.reservation_show_time) return `${date} ${this.info.reservation_show_time}`;
				return date;
			},
			editReservationTimeDisplay() {
				if (!this.selectedTimeRange || !this.selectedTimeRange.begin) return '';
				const begin = this.normalizeClock(this.selectedTimeRange.begin);
				const end = this.normalizeClock(this.selectedTimeRange.end);
				const date = this.selectedTimeRange.begin.split(' ')[0] || '';
				return end ? `${date} ${begin}-${end}` : `${date} ${begin}`;
			},
			canEditStaff() {
				return !this.disabled && [0, 3].includes(Number(this.info.status));
			},
			customFormItems() {
				const info = this.info.reservation_info;
				return Array.isArray(info) ? info : [];
			},
			projectList() {
				if (Array.isArray(this.info.project_list) && this.info.project_list.length) {
					return this.info.project_list;
				}
				const cartInfo = this.info.cart_info || {};
				const productInfo = cartInfo.productInfo || {};
				if (!productInfo.store_name) return [];
				return [{
					product_name: productInfo.store_name,
					desc: (productInfo.attrInfo && productInfo.attrInfo.suk) || productInfo.store_info || '',
					cart_num: cartInfo.cart_num || 1,
				}];
			},
			addonProjectList() {
				return this.projectList.length > 1 ? this.projectList.slice(1) : [];
			},
		},
		watch: {
			disabled(val) {
				if (!val && this.info && this.info.id) {
					this.initReservationTimeData(this.info);
				}
			},
		},
		mounted(){},
		methods:{
			deepClone(obj) {
				return JSON.parse(JSON.stringify(obj));
			},
			resolveLaborYeji(cartInfo) {
				const yeji = Number(cartInfo.yeji);
				if (yeji > 0) return yeji;
				return Number(cartInfo.truePrice || cartInfo.pay_price || 0);
			},
			splitStaffLaborYeji(staffChoose, totalPrice) {
				const list = Array.isArray(staffChoose) ? this.deepClone(staffChoose) : [];
				const len = list.length;
				if (!len || !(totalPrice > 0)) return list;
				const per = Number((totalPrice / len).toFixed(2));
				let assigned = 0;
				return list.map((item, idx) => {
					if (idx === len - 1) {
						item.yeji = Number((totalPrice - assigned).toFixed(2));
					} else {
						item.yeji = per;
						assigned += per;
					}
					return item;
				});
			},
			initYejiData(info) {
				const cartInfo = info.cart_info || {};
				const productInfo = cartInfo.productInfo || {};
				const price = this.resolveLaborYeji(cartInfo);
				const staffChoose = this.splitStaffLaborYeji(info.staff_choose, price);
				this.syncYeji = {
					link_id: Number(info.writeoff_id) || 0,
					cart_id: cartInfo.cart_id || 0,
					price,
					once_price: price,
					true_price: cartInfo.yeji != null ? cartInfo.yeji : price,
					goods_id: info.product_id || productInfo.id || 0,
					type: 3,
					value: 1,
					staffChoose,
				};
				this.setYeji = this.deepClone(this.syncYeji);
				this.staffIds = staffChoose.map((item) => item.staff_id).filter(Boolean);
			},
			openYeji() {
				if (!this.canEditStaff) return;
				this.setYeji = this.deepClone(this.syncYeji);
				this.staffIds = (this.syncYeji.staffChoose || []).map((item) => item.staff_id);
				this.loadBusyStaffForPicker().then(() => {
					this.yejiVisible = true;
					this.$nextTick(() => {
						if (this.$refs.yeji) {
							this.$refs.yeji.getStaff();
							this.$refs.yeji.showAdd = true;
							this.$refs.yeji.dianAttr = (this.syncYeji.staffChoose || [])
								.filter((item) => item.is_dian == 1)
								.map((item) => item.staff_id);
						}
					});
				});
			},
			loadBusyStaffForPicker() {
				const { serviceDate, reservationStart, reservationEnd } = this.getConflictTimeQuery();
				if (!serviceDate || !reservationStart) {
					this.disabledStaffIds = [];
					return Promise.resolve([]);
				}
				return getBusyStaffAtTime({
					service_date: serviceDate,
					reservation_start: reservationStart,
					reservation_end: reservationEnd || '',
					service_duration: Number(this.info.service_duration_minutes) || this.detailServiceDuration || 0,
					exclude_reservation_id: this.info.id,
				}).then((res) => {
					this.disabledStaffIds = (res.data && res.data.staff_ids) || [];
					return this.disabledStaffIds;
				}).catch(() => {
					this.disabledStaffIds = [];
					return [];
				});
			},
			doChoose(yeji) {
				const staffIds = (yeji.staffChoose || []).map((item) => item.staff_id).filter(Boolean);
				if (!staffIds.length) {
					this.syncYeji = yeji;
					this.staffConflictTips = [];
					this.closeYeji();
					return;
				}
				this.loadStaffConflicts({ staffIds }).then((list) => {
					if (list.length) {
						this.showStaffConflictMessage(list);
						this.yejiVisible = true;
						return;
					}
					this.syncYeji = yeji;
					this.staffConflictTips = [];
					this.closeYeji();
				});
			},
			loadTableList() {
				return getReservationTableList().then((res) => {
					this.tableList = res.data || [];
				}).catch(() => {
					this.tableList = [];
				});
			},
			initEditExtraFields(info = this.info) {
				const tableId = Number(info.table_id) || 0;
				this.editTableId = tableId || null;
				this.editMark = info.mark || '';
			},
			buildTablePayload() {
				const tableId = Number(this.editTableId) || 0;
				if (!tableId) {
					return { table_id: 0, table_name: '' };
				}
				const room = this.tableList.find((item) => Number(item.id) === tableId);
				return {
					table_id: tableId,
					table_name: room ? (room.remarks || String(room.table_number || '')) : (this.info.table_name || ''),
				};
			},
			enterEditMode() {
				this.editSnapshot = {
					info: this.deepClone(this.info),
					syncYeji: this.deepClone(this.syncYeji),
					selectedTimeRange: this.selectedTimeRange ? this.deepClone(this.selectedTimeRange) : null,
					reservationAddress: this.reservationAddress,
					regionAddress: this.regionAddress,
					editTableId: this.editTableId,
					editMark: this.editMark,
				};
				this.disabled = false;
			},
			cancelEdit() {
				if (this.editSnapshot) {
					this.info = this.deepClone(this.editSnapshot.info);
					this.syncYeji = this.deepClone(this.editSnapshot.syncYeji);
					this.setYeji = this.deepClone(this.editSnapshot.syncYeji);
					this.selectedTimeRange = this.editSnapshot.selectedTimeRange
						? this.deepClone(this.editSnapshot.selectedTimeRange)
						: null;
					this.reservationAddress = this.editSnapshot.reservationAddress;
					this.regionAddress = this.editSnapshot.regionAddress;
					this.editTableId = this.editSnapshot.editTableId;
					this.editMark = this.editSnapshot.editMark;
					this.staffIds = (this.syncYeji.staffChoose || []).map((item) => item.staff_id);
					this.editSnapshot = null;
				}
				this.staffConflictTips = [];
				this.disabled = true;
			},
			closeYeji() {
				this.yejiVisible = false;
			},
			formatReservationDate(value) {
				if (!value) return '';
				if (typeof value === 'string') return value.split(' ')[0].substring(0, 10).replace(/\//g, '-');
				const date = new Date(value);
				if (Number.isNaN(date.getTime())) return '';
				const y = date.getFullYear();
				const m = `${date.getMonth() + 1}`.padStart(2, '0');
				const d = `${date.getDate()}`.padStart(2, '0');
				return `${y}-${m}-${d}`;
			},
			normalizeClock(value) {
				if (!value) return '';
				const part = value.indexOf(' ') >= 0 ? value.split(' ')[1] : value;
				return part.substring(0, 5);
			},
			initReservationTimeData(info) {
				const date = this.formatReservationDate(info.reservation_time);
				const start = (info.reservation_start || '').substring(0, 5);
				const end = (info.reservation_end || '').substring(0, 5);
				if (date && start) {
					this.selectedTimeRange = {
						begin: `${date} ${start}:00`,
						end: end ? `${date} ${end}:00` : '',
					};
					return;
				}
				this.selectedTimeRange = null;
			},
			openTimePicker() {
				this.timePickerVisible = true;
			},
			onTimePickerConfirm(range) {
				const staffIds = this.getSelectedStaffIds();
				const applyRange = () => {
					this.selectedTimeRange = range;
					this.info.reservation_time = range.begin.split(' ')[0];
					this.timePickerVisible = false;
					this.loadBusyStaffForPicker();
					this.loadStaffConflicts();
				};
				if (!staffIds.length) {
					applyRange();
					return;
				}
				const serviceDate = range.begin.split(' ')[0];
				const reservationStart = this.normalizeClock(range.begin);
				const reservationEnd = this.normalizeClock(range.end);
				getStaffReservationConflicts({
					staff_ids: staffIds.join(','),
					service_date: serviceDate,
					reservation_start: reservationStart,
					reservation_end: reservationEnd || '',
					service_duration: Number(this.info.service_duration_minutes) || this.detailServiceDuration || 0,
					exclude_reservation_id: this.info.id,
				}).then((res) => {
					const list = (res.data && res.data.list) || [];
					if (list.length) {
						this.showStaffConflictMessage(list);
						return;
					}
					applyRange();
				}).catch(() => {
					this.$Message.error('时段校验失败，请重试');
				});
			},
			getSelectedStaffIds() {
				return (this.syncYeji.staffChoose || []).map((item) => item.staff_id).filter(Boolean);
			},
			getPrimaryStaffId() {
				const chooses = this.syncYeji.staffChoose || [];
				if (!chooses.length) return 0;
				const dian = chooses.find((item) => item.is_dian == 1);
				return Number((dian || chooses[0]).staff_id) || 0;
			},
			formatStaffChooseText() {
				const chooses = this.syncYeji.staffChoose || [];
				if (!chooses.length && this.info.staff_name) {
					return this.info.staff_name;
				}
				return chooses.map((item) => {
					const suffix = Number(item.is_dian) === 1 ? '(点)' : '(轮)';
					return `${item.staff_name}${suffix}`;
				}).join(',');
			},
			getConflictTimeQuery() {
				if (this.selectedTimeRange && this.selectedTimeRange.begin) {
					return {
						serviceDate: this.formatReservationDate(this.selectedTimeRange.begin.split(' ')[0]),
						reservationStart: this.normalizeClock(this.selectedTimeRange.begin),
						reservationEnd: this.normalizeClock(this.selectedTimeRange.end),
					};
				}
				const serviceDate = this.formatReservationDate(this.info.reservation_time);
				let reservationStart = this.normalizeClock(this.info.reservation_start);
				let reservationEnd = this.normalizeClock(this.info.reservation_end);
				if (!reservationStart && this.info.reservation_show_time) {
					const parts = String(this.info.reservation_show_time).split('-');
					reservationStart = this.normalizeClock(parts[0]);
					reservationEnd = this.normalizeClock(parts[1] || '');
				}
				return { serviceDate, reservationStart, reservationEnd };
			},
			loadStaffConflicts(options = {}) {
				const staffIds = options.staffIds || this.getSelectedStaffIds();
				if (!staffIds.length || !this.info.id) {
					this.staffConflictTips = [];
					return Promise.resolve([]);
				}
				const { serviceDate, reservationStart, reservationEnd } = this.getConflictTimeQuery();
				if (!serviceDate || !reservationStart) {
					this.staffConflictTips = [];
					return Promise.resolve([]);
				}
				return getStaffReservationConflicts({
					staff_ids: staffIds.join(','),
					service_date: serviceDate,
					reservation_start: reservationStart,
					reservation_end: reservationEnd || '',
					service_duration: Number(this.info.service_duration_minutes) || this.detailServiceDuration || 0,
					exclude_reservation_id: this.info.id,
				}).then((res) => {
					const list = (res.data && res.data.list) || [];
					this.staffConflictTips = list.map((item) => `${item.staff_name}在${item.reservation_date} ${item.reservation_start}-${item.reservation_end}已有预约`);
					return list;
				}).catch(() => {
					this.staffConflictTips = [];
					return [];
				});
			},
			showStaffConflictMessage(list) {
				if (!list || !list.length) return;
				const msg = list.map((item) => `${item.staff_name}在${item.reservation_date} ${item.reservation_start}-${item.reservation_end}已有预约`).join('；');
				this.$Message.error(msg);
			},
			writeTap(){
				let data = {
					status: 2,
					service_staff_id: this.getPrimaryStaffId(),
				};
				if (this.syncYeji.staffChoose.length) {
					data.sync_all = [this.syncYeji];
				}
				this.$modalSure({
					title: '立即消耗',
					url: `reservation/order/service/set/${this.info.id}`,
					method: 'post',
					ids: data,
				}).then((res) => {
					this.$Message.success(res.msg);
					this.orderDetail(this.info.id);
					this.$emit('submitSuccess');
				}).catch((err) => {
					this.$Message.error(err.msg);
				});
			},
			serviceStart(){
				if (this.isPurchasedReservation && !this.syncYeji.staffChoose.length) {
					return this.$Message.error('请选择手艺人');
				}
				this.loadStaffConflicts().then((list) => {
					if (list.length) {
						this.showStaffConflictMessage(list);
					}
					let data = {
						status:1,
						service_staff_id:this.getPrimaryStaffId()
					}
					if (this.syncYeji.staffChoose.length) {
						data.sync_all = [this.syncYeji];
					}
					postOrderService(this.info.id,data).then(res=>{
						this.$Message.success(res.msg);
						this.orderDetail(this.info.id);
						this.$emit('submitSuccess');
					}).catch(err=>{
						this.$Message.error(err.msg);
					})
				});
			},
			cancelTap() {
			  this.$emit('cancelTap', {id:this.info.id});
			},
			confirmTap() {
			  let delfromData = {
			    title: '接单',
			    url: `reservation/order/confirm/${this.info.id}`,
			    method: "post",
			  };
			  this.$modalSure(delfromData)
			    .then((res) => {
				  this.$Message.success(res.msg);
				  this.orderDetail(this.info.id);
				  this.$emit('submitSuccess');
				})
			    .catch((err) => {
			      this.$Message.error(err.msg);
			    });
			},
			refuseTap() {
			  this.$Modal.confirm({
			    title: '拒绝预约',
			    render: (h) => {
			      return h('Input', {
			        props: {
			          type: 'textarea',
			          rows: 3,
			          placeholder: '请填写拒绝原因',
			          value: this.refuseReason,
			        },
			        on: {
			          input: (val) => {
			            this.refuseReason = val;
			          },
			        },
			      });
			    },
			    onOk: () => {
			      if (!this.refuseReason || !this.refuseReason.trim()) {
			        this.$Message.error('请填写拒绝原因');
			        return Promise.reject();
			      }
			      return postOrderRefuse(this.info.id, { refuse_reason: this.refuseReason.trim() })
			        .then((res) => {
			          this.$Message.success(res.msg);
			          this.refuseReason = '';
			          this.orderDetail(this.info.id);
			          this.$emit('submitSuccess');
			        })
			        .catch((err) => {
			          this.$Message.error(err.msg);
			          return Promise.reject();
			        });
			    },
			    onCancel: () => {
			      this.refuseReason = '';
			    },
			  });
			},
			addchack(e,selectedData){
				this.info.reservation_address_city_id = e;
				this.regionAddress = (selectedData.map(o => o.label)).join("/");
			},
			// 省市区数据
			cityInfo(data){
			    cityApi(data).then(res=>{
			        this.addresData = res.data
			    })
			},
			loadData(item, callback) {
			    item.loading = true;
			    cityApi({pid:item.value}).then(res=>{
			        item.children = res.data;
			        item.loading = false;
			        callback();
			    });
			},
			editTap(){
				if(!this.info.reservation_phone){
					return this.$Message.error('请输入联系电话')
				}
				if(!/^1(3|4|5|7|8|9|6)\d{9}$/.test(this.info.reservation_phone)){
					return this.$Message.error('请输入正确的联系电话');
				}
				if(!this.selectedTimeRange || !this.selectedTimeRange.begin){
					return this.$Message.error('请选择预约时间')
				}
				if(this.info.reservation_type==3){
					if(!this.info.reservation_address_city_id.length){
						return this.$Message.error('请选择省市区')
					}
					if(!this.reservationAddress){
						return this.$Message.error('请输入上门地址')
					}
				}
				const reservationDate = this.formatReservationDate(this.info.reservation_time || this.selectedTimeRange.begin.split(' ')[0]);
				let data = {
					reservation_name:this.info.reservation_name,
					reservation_phone:this.info.reservation_phone,
					reservation_time: reservationDate,
					reservation_start: this.normalizeClock(this.selectedTimeRange.begin),
					reservation_end: this.normalizeClock(this.selectedTimeRange.end),
					service_duration_minutes: this.detailServiceDuration,
					mark: (this.editMark || '').trim(),
					...this.buildTablePayload(),
				}
				data.sync_all = [this.syncYeji];
				data.service_staff_id = this.getPrimaryStaffId();
				if (this.info.reservation_type == 3) {
					data.reservation_address = this.regionAddress + '/' + this.reservationAddress;
				}
				this.loadStaffConflicts().then((list) => {
					if (list.length) {
						this.showStaffConflictMessage(list);
						return;
					}
					postOrderUpdate(this.info.id, data).then(res => {
						this.$Message.success(res.msg);
						this.editSnapshot = null;
						this.disabled = true;
						this.orderDetail(this.info.id);
						this.$emit('submitSuccess');
					}).catch(err => {
						this.$Message.error(err.msg);
					});
				});
			},
			orderDetail(id){
				this.disabled = true;
				this.editSnapshot = null;
				this.cityInfo({ pid: 0 });
				this.loadTableList();
				getOrderDetail(id).then(res=>{
					this.info = res.data;
					this.initEditExtraFields(this.info);
					this.initYejiData(this.info);
					this.initReservationTimeData(this.info);
					if (res.data.reservation_address) {
						let address = res.data.reservation_address.split(" ");
						this.reservationAddress = address[address.length-1];
						address.pop();
						this.regionAddress = address.join('/');
					}
					let reservationInfo = this.info.reservation_info || [];
					if (!Array.isArray(reservationInfo)) {
						reservationInfo = [];
					}
					if (reservationInfo.length) {
					  reservationInfo.forEach((item) => {
						  if (item.value) {
						    return (this.isShow = 1)
						  }
					  })
					}
					this.$nextTick(function(){
						this.modals = true;
						this.loadStaffConflicts();
					})
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			}
		}
	}
</script>

<style scoped lang="less">
	/deep/.ivu-drawer-body{
		padding: 0;
		height: 100%;
		overflow: hidden;
	}
	/deep/.ivu-drawer{
		width: 753px !important;
	}
	.drawer-wrap {
		display: flex;
		flex-direction: column;
		height: 100%;
		min-height: 0;
	}
	.title {
		margin-top: 12px;
		padding:  0 0 11px 25px;
		border-bottom: 1px solid #eee;
		.label{
			background: rgba(42,126,251,0.08);
		}
	}
	.conter{
		flex: 1;
		min-height: 0;
		overflow-y: auto;
		padding: 24px 24px 24px 28px;
	}
	/deep/.ivu-input{
		border-radius: 4px !important;
		height: 36px !important;
		line-height: 36px !important;
		padding-top: 0;
		padding-bottom: 0;
	}
	/deep/.ivu-select-selection{
		height: 36px !important;
		border-radius: 4px !important;
	}
	/deep/.ivu-select-single .ivu-select-selection .ivu-select-placeholder,
	/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value{
		height: 36px !important;
		line-height: 36px !important;
	}
	/deep/.ivu-select-disabled .ivu-select-selection{
		background-color: #F9F9F9;
		color: #303133;
	}
	/deep/.ivu-input[disabled], /deep/fieldset[disabled] .ivu-input{
		background-color: #F9F9F9;
		color: #303133;
	}
	.footer{
		flex-shrink: 0;
		display: flex;
		justify-content: center;
		align-items: center;
		padding: 16px 20px;
		background: #FFFFFF;
		box-shadow: 0 -1px 11px 0 rgba(0,0,0,0.06);
		box-sizing: border-box;
	}
	.footer-actions {
		display: flex;
		flex-wrap: nowrap;
		align-items: center;
		justify-content: center;
		width: 100%;
		gap: 12px;
	}
	.footer-btn {
		flex: 1;
		min-width: 0;
		height: 46px;
		line-height: 46px;
		border-radius: 23px;
		font-size: 15px;
		text-align: center;
		cursor: pointer;
		user-select: none;
		white-space: nowrap;
		padding: 0 8px;
		box-sizing: border-box;
	}
	.footer-btn--single {
		flex: 0 0 176px;
	}
	.footer-btn--default {
		background: #F5F5F5;
		color: #606266;
	}
	.footer-btn--primary {
		background: #1890FF;
		color: #FFFFFF;
	}
	.footer-btn--success {
		background: #23C471;
		color: #FFFFFF;
	}
	.footer-btn--danger {
		background: #F5222D;
		color: #FFFFFF;
	}
	.staff-conflict-tips {
		margin-left: 90px;
		padding: 8px 12px;
		background: #fff7e6;
		border: 1px solid #ffd591;
		border-radius: 4px;
		color: #d46b08;
		font-size: 13px;
		line-height: 1.6;
	}
	.staff-row {
		align-items: flex-start;
	}
	.staff-label {
		flex-shrink: 0;
		line-height: 36px;
	}
	.staff-value {
		flex: 1;
		min-width: 0;
	}
	.staff-display {
		min-height: 36px;
		padding: 8px 12px;
		border-radius: 4px;
		font-size: 14px;
		line-height: 1.6;
		word-break: break-all;
		white-space: normal;
	}
	.staff-display--readonly {
		background: #F9F9F9;
		border: 1px solid #DDDDDD;
	}
	.staff-display--editable {
		background: #F9F9F9;
		border: 1px solid #DDDDDD;
	}
	.remark-display {
		min-height: 36px;
		line-height: 1.6;
		word-break: break-all;
		white-space: pre-wrap;
	}
</style>
