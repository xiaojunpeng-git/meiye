<template>
	<Modal v-model="modal" footer-hide class-name="member-modal" @on-cancel='close'>
		<div>
			<div class="bg"></div>
		</div>
		<div class="acea-row row-middle mt10" v-if="rowActive.cart_info">
			<div class="max-w-400 fs-16 text-wlll-303133 line1">{{rowActive.cart_info.productInfo.store_name}}</div>
			<div class="w-44 h-24 rd-4 fs-14 acea-row row-center-wrapper bg-w111-1890FF-8 ml-6 text-wlll-1890FF">{{rowActive.reservation_type==2?'到店':'上门'}}</div>
		</div>
		<div v-if="rowActive.cart_info" class="fs-14 text-wlll-303133 mt-12">{{rowActive.cart_info.productInfo.attrInfo.suk}}</div>
		<Form class="mt-24" ref="formValidate" :model="formValidate" :label-width="94">
			<FormItem label="联系电话：" :required="type == 1?true:false">
			  <Input v-if="type == 1" v-model="formValidate.reservation_phone" type="number" placeholder="请输入手机号" class="w-408"></Input>
			  <div v-else class="w-408 h-36 border-1-DDDDDD bg-w111-F9F9F9 text-wlll-303133 fs-14 rd-4 px-15 lh-36">{{rowActive.reservation_phone}}</div>
			</FormItem>
			<FormItem label="联系人：">
			  <Input v-if="type == 1" v-model="formValidate.reservation_name" placeholder="请输入用户昵称" class="w-408"></Input>
			  <div v-else class="w-408 h-36 border-1-DDDDDD bg-w111-F9F9F9 text-wlll-303133 fs-14 rd-4 px-15 lh-36">{{rowActive.reservation_name}}</div>
			</FormItem>
			<FormItem label="预约时间：" :required="type == 1?true:false">
			  <div v-if="type == 1" class="acea-row row-middle w-408">
			    <Input :value="editReservationTimeDisplay" readonly placeholder="请选择预约时间" class="flex-1" />
			    <Button type="primary" ghost class="ml-6" @click="openTimePicker">选择时间</Button>
			  </div>
			  <div v-else class="w-408 h-36 border-1-DDDDDD bg-w111-F9F9F9 text-wlll-303133 fs-14 rd-4 px-15 lh-36">{{ reservationTimeDisplayText || '-' }}</div>
			</FormItem>
			<FormItem label="手艺人：" :required="isServiceStartType">
              <div v-if="type == 3 && (rowActive.status == 1 || rowActive.status == 2)" class="w-408 h-36 border-1-DDDDDD bg-w111-F9F9F9 text-wlll-303133 fs-14 rd-4 px-15 lh-36">{{ formatStaffChooseText() || '-' }}</div>
              <div v-else class="w-408">
                <div class="relative fs-14 pointer text-wlll-1890FF" @click="openYeji">
                  <span v-if="!syncYeji.staffChoose.length" style="color: #1890FF;font-size: 13px">选择手艺人</span>
                  <span v-else style="color: #736a6a;font-size: 13px">{{ formatStaffChooseText() }}</span>
                </div>
              </div>
			</FormItem>
      <div v-if="staffConflictTips.length" class="staff-conflict-tips">
        <div v-for="(tip, index) in staffConflictTips" :key="index">{{ tip }}</div>
      </div>
			<FormItem label="服务房间：">
			  <div v-if="type != 1" class="w-408 h-36 border-1-DDDDDD bg-w111-F9F9F9 text-wlll-303133 fs-14 rd-4 px-15 lh-36">{{ roomDisplayText || '-' }}</div>
			  <Select v-else v-model="editTableId" clearable placeholder="可不选，由门店安排" class="w-408" transfer>
			    <Option v-for="item in tableList" :key="item.id" :value="item.id">
			      {{ item.remarks || item.table_number || ('房间' + item.id) }}
			    </Option>
			  </Select>
			</FormItem>
			<FormItem label="服务备注：">
			  <div v-if="type != 1" class="w-408 border-1-DDDDDD bg-w111-F9F9F9 text-wlll-303133 fs-14 rd-4 px-15 py-8 remark-display">{{ editMark || '-' }}</div>
			  <Input v-else v-model="editMark" type="textarea" :rows="3" placeholder="请输入备注信息（选填）" class="w-408" />
			</FormItem>
			<FormItem v-if="rowActive.reservation_type == 3" label="上门地址：" :required="type == 1?true:false">
			  <Cascader v-if="type == 1" :transfer='true' v-model="rowActive.reservation_address_city_id" :data="addresData" :load-data="loadData" @on-change="addchack" class="w-408"></Cascader>
			  <div v-else class="w-408 h-36 border-1-DDDDDD bg-w111-F9F9F9 text-wlll-303133 fs-14 rd-4 px-15 lh-36">{{regionAddress}}</div>
			</FormItem>
			<FormItem v-if="rowActive.reservation_type == 3" label="详细地址：" :required="type == 1?true:false">
			  <Input v-if="type == 1" v-model="reservationAddress" placeholder="请输入详细地址" class="w-408"></Input>
			  <div v-else class="w-408 h-36 border-1-DDDDDD bg-w111-F9F9F9 text-wlll-303133 fs-14 rd-4 px-15 lh-36">{{reservationAddress}}</div>
			</FormItem>
		</Form>
    <div class="acea-row row-center-wrapper mt-30" style="margin-left: -17px;" v-if="type == 3">
			<div v-if="rowActive.status == 1" @click="close" class="w-152 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 acea-row row-center-wrapper pointer">取消</div>
			<div v-if="rowActive.status == 0 || rowActive.status == 3" @click="cancelTap" class="w-152 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 acea-row row-center-wrapper pointer">取消预约</div>
			<div v-if="rowActive.status == 0 || rowActive.status == 3" @click="type = 1" class="w-152 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 acea-row row-center-wrapper pointer ml-20">修改预约</div>
			<div v-if="rowActive.status == 3" @click="confirmTap" class="w-152 h-46 rd-30px fs-16 bg-w111-FF7700 text-wlll-FFFFFF acea-row row-center-wrapper pointer ml-20">确认预约</div>
			<div v-if="rowActive.status == 0" @click="serviceStart" class="w-152 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF acea-row row-center-wrapper pointer ml-20">开始服务</div>
			<div v-if="rowActive.status == 1" @click="writeTap" class="w-152 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF acea-row row-center-wrapper pointer ml-20">立即消耗</div>
		</div>
    <div class="acea-row row-center-wrapper mt-30" style="margin-left: -17px;" v-else>
			<div @click="close" class="w-176 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 acea-row row-center-wrapper pointer">取消</div>
			<div v-if="type == 1" @click="confirm" class="w-176 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF acea-row row-center-wrapper pointer ml-20">确认</div>
			<div v-else @click="serviceStart" class="w-176 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF acea-row row-center-wrapper pointer ml-20">开始服务</div>
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
      :can-pick="!!rowActive.product_id"
      :staff-id="getPrimaryStaffId()"
      :staff-ids="getSelectedStaffIds()"
      :exclude-reservation-id="id || 0"
      :service-duration="detailServiceDuration"
      :value="selectedTimeRange"
      @close="timePickerVisible = false"
      @confirm="onTimePickerConfirm"
    />
	</Modal>
</template>

<script>
import yeji from '@/components/yeji';
import chooseTimePickerModal from '@/components/chooseTime/pickerModal';
import { postOrderUpdate, postOrderService, cityApi, getStaffReservationConflicts, getBusyStaffAtTime, getReservationTableList } from '@/api/reservation';
import { resolveProjectDuration } from '@/utils/serviceDuration';
export default {
  name: 'edit',
  components: { yeji, chooseTimePickerModal },
  props: {
    rowActive: {
      type: Object,
      default: () => {}
    },
	staffList: {
	  type: Array,
	  default: () => []
	}
  },
  data() {
    return {
      modal: false,
	  formValidate:{
		reservation_name:'',
		reservation_phone:'',
		reservation_time:'',
		service_staff_id:0
	  },
	  timePickerVisible: false,
	  selectedTimeRange: null,
	  id:0,
	  type:1 ,//1：修改；2：开始服务 3：取消预约、修改预约、开始服务（看板）
	  reservationAddress:'', //上门详细地址
	  regionAddress:'', //上门地址
	  addresData:[],
      yejiVisible: false,
      disabledStaffIds: [],
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
      tableList: [],
      editTableId: null,
      editMark: '',
    }
  },
  computed: {
    roomDisplayText() {
      return this.rowActive.service_room || this.rowActive.table_name || '';
    },
    isPurchasedReservation() {
      if (typeof this.rowActive.is_guest_reservation !== 'undefined') {
        return !this.rowActive.is_guest_reservation;
      }
      return Number(this.rowActive.oid) > 0 && Number(this.rowActive.cart_info_id) > 0;
    },
    isServiceStartType() {
      return this.type === 2 || (this.type === 3 && this.rowActive.status === 0);
    },
    detailServiceDuration() {
      const minutes = Number(this.rowActive.service_duration_minutes) || 0;
      if (minutes > 0) return minutes;
      const cartInfo = this.rowActive.cart_info || {};
      const productInfo = cartInfo.productInfo || {};
      return resolveProjectDuration(productInfo.project_service_duration);
    },
    reservationStaffQuery() {
      const q = this.getConflictTimeQuery();
      return {
        service_date: q.serviceDate || '',
        reservation_start: q.reservationStart || '',
        reservation_end: q.reservationEnd || '',
        service_duration: this.detailServiceDuration || 0,
        exclude_reservation_id: this.id || 0,
      };
    },
    reservationTimeDisplayText() {
      const date = this.formatReservationDate(this.rowActive.reservation_time);
      const start = this.rowActive.reservation_start || '';
      const end = this.rowActive.reservation_end || '';
      if (date && start && end) return `${date} ${start}-${end}`;
      if (date && start) return `${date} ${start}`;
      if (date && this.rowActive.reservation_show_time) return `${date} ${this.rowActive.reservation_show_time}`;
      return date;
    },
    editReservationTimeDisplay() {
      if (!this.selectedTimeRange || !this.selectedTimeRange.begin) return '';
      const begin = this.normalizeClock(this.selectedTimeRange.begin);
      const end = this.normalizeClock(this.selectedTimeRange.end);
      const date = this.selectedTimeRange.begin.split(' ')[0] || '';
      return end ? `${date} ${begin}-${end}` : `${date} ${begin}`;
    },
  },
  watch:{
	 rowActive(val){
		this.id = val.id;
		this.formValidate = {
			reservation_name:val.reservation_name,
			reservation_phone:val.reservation_phone,
			reservation_time:val.reservation_time,
			service_staff_id:val.service_staff_id
		}
		if (val.reservation_address) {
			let address = val.reservation_address.split(" ");
			this.reservationAddress = address[address.length-1];
			address.pop();
			this.regionAddress = address.join('/');
		}
		this.initReservationTimeData(val);
		this.initYejiData(val);
		this.initEditExtraFields(val);
		this.loadTableList();
		this.loadStaffConflicts();
   }
  },
  mounted() {},
  methods: {
    loadTableList() {
      return getReservationTableList().then((res) => {
        this.tableList = res.data || [];
      }).catch(() => {
        this.tableList = [];
      });
    },
    initEditExtraFields(val) {
      const tableId = Number(val.table_id) || 0;
      this.editTableId = tableId || null;
      this.editMark = val.mark || '';
    },
    buildTablePayload() {
      const tableId = Number(this.editTableId) || 0;
      if (!tableId) {
        return { table_id: 0, table_name: '' };
      }
      const room = this.tableList.find((item) => Number(item.id) === tableId);
      return {
        table_id: tableId,
        table_name: room ? (room.remarks || String(room.table_number || '')) : (this.rowActive.table_name || ''),
      };
    },
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
    initYejiData(val) {
      const cartInfo = val.cart_info || {};
      const productInfo = cartInfo.productInfo || {};
      const productId = val.product_id || productInfo.id || 0;
      const cartId = cartInfo.cart_id || 0;
      const price = this.resolveLaborYeji(cartInfo);
      const staffChoose = this.splitStaffLaborYeji(val.staff_choose, price);
      this.syncYeji = {
        link_id: Number(val.writeoff_id) || 0,
        cart_id: cartId,
        price,
        once_price: price,
        true_price: cartInfo.yeji != null ? cartInfo.yeji : price,
        goods_id: productId,
        type: 3,
        value: 1,
        staffChoose,
      };
      this.setYeji = this.deepClone(this.syncYeji);
      this.staffIds = staffChoose.map((item) => item.staff_id).filter(Boolean);
    },
    openYeji() {
      this.setYeji = this.deepClone(this.syncYeji);
      this.staffIds = (this.syncYeji.staffChoose || []).map((item) => item.staff_id);
      const dianAttr = (this.syncYeji.staffChoose || [])
        .filter((item) => item.is_dian == 1)
        .map((item) => item.staff_id);
      this.loadBusyStaffForPicker().then(() => {
        this.yejiVisible = true;
        this.$nextTick(() => {
          if (this.$refs.yeji) {
            this.$refs.yeji.getStaff();
            this.$refs.yeji.showAdd = true;
            this.$refs.yeji.dianAttr = dianAttr;
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
        service_duration: this.detailServiceDuration,
        exclude_reservation_id: this.id,
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
    getConflictTimeQuery() {
      if (this.selectedTimeRange && this.selectedTimeRange.begin) {
        return {
          serviceDate: this.formatReservationDate(this.selectedTimeRange.begin.split(' ')[0]),
          reservationStart: this.normalizeClock(this.selectedTimeRange.begin),
          reservationEnd: this.normalizeClock(this.selectedTimeRange.end),
        };
      }
      const serviceDate = this.formatReservationDate(this.rowActive.reservation_time || this.formValidate.reservation_time);
      let reservationStart = this.normalizeClock(this.rowActive.reservation_start);
      let reservationEnd = this.normalizeClock(this.rowActive.reservation_end);
      if (!reservationStart && this.rowActive.reservation_show_time) {
        const parts = String(this.rowActive.reservation_show_time).split('-');
        reservationStart = this.normalizeClock(parts[0]);
        reservationEnd = this.normalizeClock(parts[1] || '');
      }
      return { serviceDate, reservationStart, reservationEnd };
    },
    openTimePicker() {
      this.timePickerVisible = true;
    },
    onTimePickerConfirm(range) {
      const staffIds = this.getSelectedStaffIds();
      const applyRange = () => {
        this.selectedTimeRange = range;
        this.formValidate.reservation_time = range.begin.split(' ')[0];
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
        service_duration: this.detailServiceDuration,
        exclude_reservation_id: this.id,
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
      if (!chooses.length && this.rowActive.staff_name) {
        return this.rowActive.staff_name;
      }
      return chooses.map((item) => {
        const suffix = Number(item.is_dian) === 1 ? '(点)' : '(轮)';
        return `${item.staff_name}${suffix}`;
      }).join(',');
    },
    loadStaffConflicts(options = {}) {
      const staffIds = options.staffIds || this.getSelectedStaffIds();
      if (!staffIds.length || !this.id) {
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
        service_duration: this.detailServiceDuration,
        exclude_reservation_id: this.id,
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
	  addchack(e,selectedData){
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
	  serviceStart(){
      if (this.isPurchasedReservation && !this.syncYeji.staffChoose.length) {
        return this.$Message.error('请选择手艺人');
      }
      this.loadStaffConflicts().then((list) => {
        if (list.length) {
          this.showStaffConflictMessage(list);
          return;
        }
        let data = {
          status: 1,
          service_staff_id: this.getPrimaryStaffId(),
        };
        if (this.syncYeji.staffChoose.length) {
          data.sync_all = [this.syncYeji];
        }
        postOrderService(this.id,data).then(res=>{
          this.$Message.success(res.msg);
          this.modal = false;
          this.$emit('submitSuccess')
        }).catch(err=>{
          this.$Message.error(err.msg);
        })
      });
	  },
	  confirm(){
		if(!this.formValidate.reservation_phone){
			return this.$Message.error('请输入联系电话')
		}
		if(!/^1(3|4|5|7|8|9|6)\d{9}$/.test(this.formValidate.reservation_phone)){
			return this.$Message.error('请输入正确的联系电话');
		}
		if(!this.selectedTimeRange || !this.selectedTimeRange.begin){
			return this.$Message.error('请选择预约时间')
		}
		if(this.rowActive.reservation_type==3){
			if(!this.rowActive.reservation_address_city_id.length){
				return this.$Message.error('请选择省市区')
			}
			if(!this.reservationAddress){
				return this.$Message.error('请输入上门地址')
			}
		}
		const reservationDate = this.formatReservationDate(this.formValidate.reservation_time || this.selectedTimeRange.begin.split(' ')[0]);
		const payload = {
			reservation_name: this.formValidate.reservation_name,
			reservation_phone: this.formValidate.reservation_phone,
			reservation_time: reservationDate,
			reservation_start: this.normalizeClock(this.selectedTimeRange.begin),
			reservation_end: this.normalizeClock(this.selectedTimeRange.end),
			service_duration_minutes: this.detailServiceDuration,
			mark: (this.editMark || '').trim(),
			...this.buildTablePayload(),
		};
		if (this.rowActive.reservation_type == 3) {
			payload.reservation_address = this.regionAddress + '/' + this.reservationAddress;
		}
		payload.sync_all = [this.syncYeji];
		payload.service_staff_id = this.getPrimaryStaffId();
		const submitUpdate = () => {
			postOrderUpdate(this.id, payload).then(res => {
				this.$Message.success(res.msg);
				this.modal = false;
				this.$emit('submitSuccess');
			}).catch(err => {
				this.$Message.error(err.msg);
			});
		};
		const staffIds = this.getSelectedStaffIds();
		if (!staffIds.length) {
			submitUpdate();
			return;
		}
		this.loadStaffConflicts({ staffIds }).then((list) => {
			if (list.length) {
				this.showStaffConflictMessage(list);
				return;
			}
			submitUpdate();
		});
	  },
	  close(){
      this.modal = false;
	  },
    cancelTap() {
      this.$emit('cancelTap', this.rowActive);
    },
    confirmTap() {
      let delfromData = {
        title: '确认预约',
        url: `reservation/order/confirm/${this.rowActive.id}`,
        method: 'post',
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.modal = false;
          this.$emit('submitSuccess');
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    writeTap() {
      this.$emit('writeTap', this.rowActive);
    }
  }
}
</script>

<style lang="stylus" scoped>
	.bg{
		position: absolute;
		width: 424px;
		height: 85px;
		background: linear-gradient( 270deg, rgba(24,101,255,0.54) 0%, #1890FF 100%);
		top:-46px;
		left:-46px;
		opacity: 0.2;
		filter: blur(80px);
	}
	/deep/.ivu-input{
		height: 36px !important;
		line-height: 36px !important;
		border-radius: 4px !important;
		padding-left: 15px !important;
	}
	/deep/.ivu-form-item{
		margin-bottom: 20px;
	}
	/deep/.ivu-modal{
		width: 580px !important;
		overflow: hidden
	}
	/deep/.ivu-modal-body{
		padding-left: 35px !important;
	}
	/deep/.ivu-select-selection{
		height: 36px !important;
		border-radius: 4px !important;
		padding-left: 6px !important;
	}
	/deep/.ivu-select-single .ivu-select-selection .ivu-select-placeholder,
	/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value{
		height: 36px !important;
		line-height: 36px !important;
	}
	.staff-conflict-tips {
		margin: -8px 0 12px 94px;
		padding: 8px 12px;
		background: #fff7e6;
		border: 1px solid #ffd591;
		border-radius: 4px;
		color: #d46b08;
		font-size: 13px;
		line-height: 1.6;
	}
	.remark-display {
		min-height: 36px;
		line-height: 1.6;
		word-break: break-all;
		white-space: pre-wrap;
	}
</style>
