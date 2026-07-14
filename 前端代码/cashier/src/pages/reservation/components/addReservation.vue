<template>
  <Modal
    v-model="visible"
    :width="920"
    :mask-closable="false"
    footer-hide
    class-name="add-reservation-modal"
    @on-cancel="close"
  >
    <div slot="header" class="modal-header acea-row row-middle">
      <span class="modal-title">添加预约</span>
      <span class="type-badge">{{ reservationTypeLabel }}</span>
    </div>

    <div class="modal-body acea-row">
      <div class="col-left">
        <div class="form-row acea-row row-middle">
          <label class="form-label required">选择会员</label>
          <Input :value="memberDisplay" readonly placeholder="请选择会员" class="flex-1" />
          <Button type="primary" ghost class="pick-member-btn" @click="openMemberPicker">选择会员</Button>
        </div>

        <div class="form-row acea-row row-middle mt-20">
          <label class="form-label required">项目类型</label>
          <div class="type-tabs acea-row">
            <div class="type-tab" :class="{ on: projectType === 1 }" @click="switchProjectType(1)">已购项目</div>
            <div class="type-tab ml-12" :class="{ on: projectType === 2 }" @click="switchProjectType(2)">全部项目</div>
          </div>
        </div>

        <div class="form-row acea-row row-middle mt-20">
          <label class="form-label required">预约服务</label>
          <Input :value="selectedServiceLabel" readonly placeholder="请选择预约服务" class="flex-1" />
          <Button type="primary" ghost class="pick-member-btn" :disabled="projectType === 1 && !memberInfo" @click="openServicePicker">
            选择服务
          </Button>
        </div>

        <div class="form-row acea-row row-middle mt-12" v-if="selectedService">
          <label class="form-label">服务总时长</label>
          <span class="duration-text">{{ totalServiceDuration }} 分钟</span>
          <span class="duration-detail" v-if="mainProjectDuration || addonTotalDuration">
            （预约 {{ mainProjectDuration }} 分钟<span v-if="addonTotalDuration"> + 加项 {{ addonTotalDuration }} 分钟</span>）
          </span>
        </div>

        <div class="addon-section mt-24">
          <div class="addon-title acea-row row-between-wrapper">
            <span>加项服务</span>
            <span v-if="selectedService && addonTotalDuration" class="addon-total-hint">加项合计 {{ addonTotalDuration }} 分钟</span>
          </div>
          <div class="addon-alert acea-row row-middle">
            <Icon type="ios-information-circle" class="addon-alert-icon" />
            <span>加项服务中的项目服务时长将调用增项服务时长计算</span>
          </div>
          <div v-if="!addonItems.length" class="addon-empty">暂无加项服务</div>
          <div v-else class="addon-list">
            <div v-for="(item, index) in addonItems" :key="item._key" class="addon-item acea-row row-between-wrapper">
              <span class="line1">{{ item.label }}</span>
              <span class="addon-duration">{{ resolveAddonDuration(item.addon_service_duration) }}分钟</span>
              <a class="addon-remove" @click="removeAddon(index)">移除</a>
            </div>
          </div>
          <div class="addon-add-btn pointer" @click="openAddonPicker">+ 添加项目</div>
        </div>
      </div>

      <div class="col-right">
        <div class="form-row acea-row row-middle">
          <label class="form-label required">联系人</label>
          <Input v-model="form.reservation_name" placeholder="请输入联系人姓名" class="flex-1" />
        </div>
        <div class="form-row acea-row row-middle mt-20">
          <label class="form-label required">联系人电话</label>
          <Input v-model="form.reservation_phone" type="number" placeholder="请输入联系人电话" class="flex-1" />
        </div>
        <div class="form-row acea-row row-middle mt-20">
          <label class="form-label">手艺人</label>
          <div class="flex-1 artisan-picker" :class="{ disabled: !selectedService }" @click="openYeji">
            <span v-if="!syncYeji.staffChoose.length" class="artisan-placeholder">不选由门店安排</span>
            <span v-else class="artisan-selected">
              手艺人<span v-for="(cItem, cindex) in syncYeji.staffChoose" :key="cindex">
                <span v-if="cindex === 0">:{{ cItem.staff_name }}</span>
                <span v-else>,{{ cItem.staff_name }}</span>
                <span v-if="cItem.is_dian == 1">(点)</span>
                <span v-else>(轮)</span>
              </span>
            </span>
          </div>
        </div>
        <div v-if="staffConflictTips.length" class="staff-conflict-tips">
          <div v-for="(tip, index) in staffConflictTips" :key="index">{{ tip }}</div>
        </div>
        <div class="form-row acea-row row-middle mt-20">
          <label class="form-label required">预约时间</label>
          <Input :value="reservationTimeDisplay" readonly placeholder="请选择预约时间" class="flex-1" />
          <Button type="primary" ghost class="pick-member-btn" :disabled="!selectedService" @click="openTimePicker">选择时间</Button>
        </div>
        <template v-if="orderInfo.reservation_type == 3">
          <div class="form-row acea-row row-middle mt-20">
            <label class="form-label required">上门地址</label>
            <Cascader v-model="form.reservation_address_city_id" :data="addresData" :load-data="loadData" transfer class="flex-1" @on-change="addressChange" />
          </div>
          <div class="form-row acea-row row-middle mt-20">
            <label class="form-label required">详细地址</label>
            <Input v-model="reservationAddress" placeholder="请输入详细地址" class="flex-1" />
          </div>
        </template>
        <div class="form-row acea-row row-middle mt-20">
          <label class="form-label">服务房间</label>
          <Select v-model="form.table_id" clearable placeholder="可不选，由门店安排" class="flex-1" transfer>
            <Option v-for="item in tableList" :key="item.id" :value="item.id">
              {{ item.remarks || item.table_number || ('房间' + item.id) }}
            </Option>
          </Select>
        </div>
        <div class="form-row acea-row mt-20">
          <label class="form-label form-label-top">预约备注</label>
          <Input v-model="form.mark" type="textarea" :rows="3" placeholder="请输入备注信息（选填）" class="flex-1" />
        </div>
      </div>
    </div>

    <div v-if="projectType === 1" class="purchase-tip acea-row row-middle">
      <Icon type="ios-information-circle" class="purchase-tip-icon" />
      <span>已购项目预约成功后，会直接扣掉项目的剩余次数，如果取消预约会退回</span>
    </div>

    <div class="modal-footer acea-row">
      <Button class="footer-btn cancel-btn" @click="close">取消</Button>
      <Button type="primary" class="footer-btn confirm-btn" :loading="submitLoading" @click="submit">确认添加</Button>
    </div>

    <memberSet ref="memberSet" @submitSuccess="onMemberSelect" />

    <!-- 预约服务选择弹窗 -->
    <Modal v-model="servicePickerVisible" title="选择预约服务" width="720" class-name="service-picker-modal" :mask-closable="false">
      <div class="picker-search">
        <Input v-model="servicePickerSearch" placeholder="搜索项目名称" prefix="ios-search" clearable />
      </div>
      <div v-if="serviceLoading" class="picker-loading">加载中...</div>
      <div v-else-if="!filteredServiceOptions.length" class="picker-empty">暂无可选预约服务</div>
      <div v-else class="picker-scroll">
        <div class="picker-grid">
          <div
            v-for="item in filteredServiceOptions"
            :key="item.value"
            class="picker-card"
            :class="{ selected: serviceKey === item.value }"
            @click="selectServiceOption(item)"
          >
            <div class="picker-card-title line2">{{ item.label }}</div>
            <div v-if="item.skuLabel" class="picker-card-sub">{{ item.skuLabel }}</div>
            <div v-if="item.remainText" class="picker-card-meta">{{ item.remainText }}</div>
            <div class="picker-card-meta">预约时长 {{ resolveProjectDuration(item.project_service_duration) }} 分钟</div>
          </div>
        </div>
      </div>
      <div slot="footer">
        <Button @click="servicePickerVisible = false">取消</Button>
        <Button type="primary" :disabled="!serviceKey" @click="confirmServicePicker">确定</Button>
      </div>
    </Modal>

    <!-- 加项选择弹窗 -->
    <Modal v-model="addonPickerVisible" title="选择加项项目" width="720" class-name="service-picker-modal" :mask-closable="false">
      <div class="picker-tabs acea-row">
        <div class="picker-tab" :class="{ on: addonPickerTab === 1 }" @click="switchAddonTab(1)">已购项目</div>
        <div class="picker-tab ml-12" :class="{ on: addonPickerTab === 2 }" @click="switchAddonTab(2)">全部项目</div>
      </div>
      <div class="picker-search">
        <Input v-model="addonPickerSearch" placeholder="搜索项目名称" prefix="ios-search" clearable />
      </div>
      <div v-if="addonPickerLoading" class="picker-loading">加载中...</div>
      <div v-else-if="!filteredAddonOptions.length" class="picker-empty">暂无可选项目</div>
      <div v-else class="picker-scroll">
        <div class="picker-grid">
          <div
            v-for="item in filteredAddonOptions"
            :key="item._key"
            class="picker-card"
            :class="{ selected: addonPickerSelectedKeys.includes(item._key) }"
            @click="toggleAddonSelect(item._key)"
          >
            <div class="picker-card-title line2">{{ item.label }}</div>
            <div class="picker-card-meta">{{ item.durationText }}</div>
          </div>
        </div>
      </div>
      <div slot="footer">
        <Button @click="addonPickerVisible = false">取消</Button>
        <Button type="primary" :disabled="!addonPickerSelectedKeys.length" @click="confirmAddonPicker">确定</Button>
      </div>
    </Modal>

    <yeji
      @doChoose="doChoose"
      @sureSync="doChoose"
      :isShouyi="true"
      :showApplyAll="false"
      :syncProduct="[syncYeji]"
      :yeji="setYeji"
      :staffIds="staffIds"
      :disabled-staff-ids="disabledStaffIds"
      :reservation-staff-query="staffPickerQuery"
      @closeYeji="closeYeji"
      :visible="yejiVisible"
      ref="yeji"
    />

    <choose-time-picker-modal
      :visible="timePickerVisible"
      :can-pick="!!selectedService"
      :staff-id="primaryStaffId"
      :staff-ids="selectedStaffIds"
      :service-duration="totalServiceDuration"
      :value="selectedTimeRange"
      @close="timePickerVisible = false"
      @confirm="onTimePickerConfirm"
    />
  </Modal>
</template>

<script>
import memberSet from '@/pages/cashier/components/memberSet';
import yeji from '@/components/yeji';
import chooseTimePickerModal from '@/components/chooseTime/pickerModal';
import { cashierProduct, cashierDetail } from '@/api/order';
import { getUserPurchasedRemainItems, getOrderReservationInfo, postReservationCreate, postGuestReservationCreate, cityApi, getStaffReservationConflicts, getBusyStaffAtTime, getReservationTableList } from '@/api/reservation';
import { resolveProjectDuration, resolveAddonDuration } from '@/utils/serviceDuration';

const CUSTOM_CARD_PRODUCT_ID = 8154;

export default {
  name: 'addReservation',
  components: { memberSet, yeji, chooseTimePickerModal },
  data() {
    return {
      visible: false,
      projectType: 1,
      memberInfo: null,
      serviceKey: '',
      serviceOptions: [],
      serviceLoading: false,
      servicePickerVisible: false,
      servicePickerSearch: '',
      selectedService: null,
      selectOrder: {},
      cartInfo: {},
      orderInfo: {},
      addresData: [],
      regionAddress: '',
      reservationAddress: '',
      submitLoading: false,
      mainProjectDuration: 0,
      addonItems: [],
      addonPickerVisible: false,
      addonPickerTab: 1,
      addonPickerSearch: '',
      addonPickerLoading: false,
      addonPickerOptions: [],
      addonPickerSelectedKeys: [],
      yejiVisible: false,
      disabledStaffIds: [],
      staffIds: [],
      staffConflictTips: [],
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
      timePickerVisible: false,
      selectedTimeRange: null,
      tableList: [],
      form: {
        reservation_name: '',
        reservation_phone: '',
        reservation_time: '',
        reservation_address_city_id: [],
        mark: '',
        table_id: null,
      },
    };
  },
  computed: {
    memberDisplay() {
      if (!this.memberInfo) return '';
      const name = this.memberInfo.real_name || this.memberInfo.nickname || '会员';
      return `${name} ${this.memberInfo.phone || ''}`.trim();
    },
    reservationTypeLabel() {
      if (this.orderInfo.reservation_type == 3) return '上门';
      return '到店';
    },
    primaryStaffId() {
      const chooses = this.syncYeji.staffChoose || [];
      if (!chooses.length) return 0;
      const dian = chooses.find((item) => item.is_dian == 1);
      return Number((dian || chooses[0]).staff_id) || 0;
    },
    selectedStaffIds() {
      return (this.syncYeji.staffChoose || []).map((item) => item.staff_id).filter(Boolean);
    },
    reservationTimeDisplay() {
      if (!this.selectedTimeRange || !this.selectedTimeRange.begin) return '';
      const begin = this.normalizeClock(this.selectedTimeRange.begin);
      const end = this.normalizeClock(this.selectedTimeRange.end);
      const date = this.selectedTimeRange.begin.split(' ')[0] || '';
      return end ? `${date} ${begin}-${end}` : `${date} ${begin}`;
    },
    staffPickerQuery() {
      let date = this.form.reservation_time || '';
      if (!date && this.selectedTimeRange && this.selectedTimeRange.begin) {
        date = this.selectedTimeRange.begin.split(' ')[0];
      }
      let reservationStart = '';
      let reservationEnd = '';
      if (this.selectedTimeRange && this.selectedTimeRange.begin) {
        reservationStart = this.normalizeClock(this.selectedTimeRange.begin);
        reservationEnd = this.normalizeClock(this.selectedTimeRange.end);
      }
      return {
        service_date: date,
        reservation_start: reservationStart,
        reservation_end: reservationEnd,
        service_duration: this.totalServiceDuration || 0,
      };
    },
    selectedServiceLabel() {
      return this.selectedService ? this.selectedService.label : '';
    },
    filteredServiceOptions() {
      const kw = (this.servicePickerSearch || '').trim().toLowerCase();
      let list = this.serviceOptions;
      // 已购项目：不可预约项直接隐藏
      if (this.projectType === 1) {
        list = list.filter((item) => !item.disabled);
      }
      if (!kw) return list;
      return list.filter((item) => (item.label || '').toLowerCase().includes(kw));
    },
    addonTotalDuration() {
      let total = 0;
      this.addonItems.forEach((item) => {
        total += resolveAddonDuration(item.addon_service_duration);
      });
      return total;
    },
    filteredAddonOptions() {
      const kw = (this.addonPickerSearch || '').trim().toLowerCase();
      let list = this.addonPickerOptions;
      const existKeys = this.addonItems.map((i) => i._key);
      list = list.filter((item) => !existKeys.includes(item._key));
      if (!kw) return list;
      return list.filter((item) => (item.label || '').toLowerCase().includes(kw));
    },
    totalServiceDuration() {
      let total = resolveProjectDuration(this.mainProjectDuration);
      this.addonItems.forEach((item) => {
        total += resolveAddonDuration(item.addon_service_duration);
      });
      return total;
    },
  },
  methods: {
    resolveProjectDuration,
    resolveAddonDuration,
    deepClone(obj) {
      return JSON.parse(JSON.stringify(obj));
    },
    open() {
      this.reset();
      this.visible = true;
      this.cityInfo({ pid: 0 });
      this.loadTableList();
    },
    openWithMember(user) {
      this.reset();
      this.visible = true;
      this.cityInfo({ pid: 0 });
      this.loadTableList();
      if (user && user.uid) {
        this.onMemberSelect(user);
      }
    },
    close() {
      this.visible = false;
    },
    reset() {
      this.projectType = 1;
      this.memberInfo = null;
      this.serviceKey = '';
      this.serviceOptions = [];
      this.selectedService = null;
      this.selectOrder = {};
      this.cartInfo = {};
      this.orderInfo = {};
      this.mainProjectDuration = 0;
      this.addonItems = [];
      this.servicePickerVisible = false;
      this.servicePickerSearch = '';
      this.addonPickerVisible = false;
      this.addonPickerSearch = '';
      this.addonPickerOptions = [];
      this.addonPickerSelectedKeys = [];
      this.yejiVisible = false;
      this.staffIds = [];
      this.staffConflictTips = [];
      this.disabledStaffIds = [];
      this.syncYeji = {
        link_id: 0,
        cart_id: 0,
        price: 0,
        goods_id: 0,
        type: 3,
        staffChoose: [],
      };
      this.setYeji = this.deepClone(this.syncYeji);
      this.timePickerVisible = false;
      this.selectedTimeRange = null;
      this.regionAddress = '';
      this.reservationAddress = '';
      this.submitLoading = false;
      this.form = {
        reservation_name: '',
        reservation_phone: '',
        reservation_time: '',
        reservation_address_city_id: [],
        mark: '',
        table_id: null,
      };
    },
    loadTableList() {
      getReservationTableList()
        .then((res) => {
          this.tableList = res.data || [];
        })
        .catch(() => {
          this.tableList = [];
        });
    },
    buildTablePayload() {
      const tableId = Number(this.form.table_id) || 0;
      if (!tableId) {
        return { table_id: 0, table_name: '' };
      }
      const room = this.tableList.find((item) => Number(item.id) === tableId);
      return {
        table_id: tableId,
        table_name: room ? (room.remarks || String(room.table_number || '')) : '',
      };
    },
    resolveLaborYeji(cartInfo, fallback = 0) {
      const yeji = Number(cartInfo.yeji);
      if (yeji > 0) return yeji;
      return Number(cartInfo.truePrice || cartInfo.pay_price || fallback || 0);
    },
    initYejiData(extra = {}) {
      const productId = Number(this.cartInfo.product_id || extra.product_id || 0);
      let cartId = 0;
      let price = 0;
      let truePrice = 0;
      if (this.projectType === 1 && this.selectedService && this.selectedService.cartItem) {
        const cartItem = this.selectedService.cartItem;
        const cartInfo = cartItem.cart_info || {};
        cartId = cartItem.cart_id || 0;
        price = this.resolveLaborYeji(cartInfo);
        truePrice = cartInfo.yeji != null ? cartInfo.yeji : price;
      } else {
        const selectedProduct = this.selectedService && this.selectedService.product;
        price = Number(extra.yeji || extra.price || (selectedProduct && selectedProduct.price) || 0);
        truePrice = price;
      }
      this.syncYeji = {
        link_id: 0,
        cart_id: cartId,
        price,
        once_price: price,
        true_price: truePrice,
        goods_id: productId,
        type: 3,
        value: 1,
        staffChoose: [],
      };
      this.setYeji = this.deepClone(this.syncYeji);
      this.staffIds = [];
    },
    openYeji() {
      if (!this.selectedService) return this.$Message.warning('请先选择预约服务');
      if (!this.cartInfo.product_id) return this.$Message.warning('请先选择预约服务');
      if (!this.selectedTimeRange || !this.selectedTimeRange.begin) {
        return this.$Message.warning('请先选择预约时间后再选手艺人');
      }
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
      const query = this.staffPickerQuery;
      if (!query.service_date || !query.reservation_start) {
        this.disabledStaffIds = [];
        return Promise.resolve([]);
      }
      return getBusyStaffAtTime({
        service_date: query.service_date,
        reservation_start: query.reservation_start,
        reservation_end: query.reservation_end || '',
        service_duration: query.service_duration,
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
      const applyStaff = () => {
        this.syncYeji = yeji;
        this.staffConflictTips = [];
        this.closeYeji();
      };
      if (this.selectedTimeRange && this.selectedTimeRange.begin) {
        this.loadStaffConflicts({ staffIds }).then((list) => {
          if (list.length) {
            this.showStaffConflictMessage(list);
            this.yejiVisible = true;
            return;
          }
          applyStaff();
        });
        return;
      }
      applyStaff();
    },
    showStaffConflictMessage(list) {
      if (!list || !list.length) return;
      const msg = list.map((item) => `${item.staff_name}在${item.reservation_date} ${item.reservation_start}-${item.reservation_end}已有预约`).join('；');
      this.$Message.error(msg);
    },
    closeYeji() {
      this.yejiVisible = false;
    },
    getSelectedStaffIds() {
      return (this.syncYeji.staffChoose || []).map((item) => item.staff_id).filter(Boolean);
    },
    loadStaffConflicts() {
      const staffIds = this.getSelectedStaffIds();
      const query = this.staffPickerQuery;
      if (!staffIds.length || !query.service_date || !query.reservation_start) {
        this.staffConflictTips = [];
        return Promise.resolve([]);
      }
      return getStaffReservationConflicts({
        staff_ids: staffIds.join(','),
        service_date: query.service_date,
        reservation_start: query.reservation_start,
        reservation_end: query.reservation_end || '',
        service_duration: query.service_duration,
      }).then((res) => {
        const list = (res.data && res.data.list) || [];
        this.staffConflictTips = list.map((item) => `${item.staff_name}在${item.reservation_date} ${item.reservation_start}-${item.reservation_end}已有预约`);
        return list;
      }).catch(() => {
        this.staffConflictTips = [];
        return [];
      });
    },
    getPrimaryStaffId() {
      const chooses = this.syncYeji.staffChoose || [];
      if (!chooses.length) return 0;
      const dian = chooses.find((item) => item.is_dian == 1);
      return Number((dian || chooses[0]).staff_id) || 0;
    },
    buildStaffPayload() {
      const staffPayload = {};
      const serviceStaffId = this.getPrimaryStaffId();
      if (serviceStaffId) {
        staffPayload.service_staff_id = serviceStaffId;
      }
      const chooses = this.syncYeji.staffChoose || [];
      if (!chooses.length) {
        return staffPayload;
      }
      const syncRow = Object.assign({}, this.syncYeji, {
        cart_id: this.syncYeji.cart_id || this.cartInfo.cart_id || 0,
        goods_id: this.syncYeji.goods_id || this.cartInfo.product_id || 0,
        order_id: (this.selectOrder && this.selectOrder.id) || 0,
        type: 3,
        value: 1,
        staffChoose: chooses,
      });
      staffPayload.sync_all = [syncRow];
      return staffPayload;
    },
    normalizeClock(value) {
      if (!value) return '';
      const part = value.indexOf(' ') >= 0 ? value.split(' ')[1] : value;
      return part.substring(0, 5);
    },
    isCustomCardProduct(item) {
      if (!item) return false;
      const id = Number(item.id || item.product_id || 0);
      const pid = Number(item.pid || 0);
      return id === CUSTOM_CARD_PRODUCT_ID || pid === CUSTOM_CARD_PRODUCT_ID;
    },
    filterAllReservationProducts(list) {
      return (list || []).filter((item) => Number(item.product_type) === 6 && !this.isCustomCardProduct(item));
    },
    formatReservationDate(val) {
      if (!val) return '';
      if (typeof val === 'string') return val.split(' ')[0].replace(/\//g, '-');
      const d = new Date(val);
      if (Number.isNaN(d.getTime())) return '';
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      return `${y}-${m}-${day}`;
    },
    openMemberPicker() {
      this.$refs.memberSet.modal4 = true;
      this.$refs.memberSet.searchUser();
    },
    onMemberSelect(user) {
      this.memberInfo = user;
      this.form.reservation_name = user.real_name || user.nickname || '';
      this.form.reservation_phone = user.phone || '';
      this.clearServiceSelection();
      if (this.projectType === 1) this.loadPurchasedServices();
    },
    switchProjectType(type) {
      this.projectType = type;
      this.clearServiceSelection();
      if (type === 1 && this.memberInfo) {
        this.loadPurchasedServices();
      } else if (type === 2) {
        this.loadAllServices();
      }
    },
    clearServiceSelection() {
      this.serviceKey = '';
      this.selectedService = null;
      this.cartInfo = {};
      this.orderInfo = {};
      this.mainProjectDuration = 0;
      this.addonItems = [];
      this.clearReservationTime();
      this.clearStaffSelection();
    },
    clearStaffSelection() {
      if (this.syncYeji) {
        this.syncYeji.staffChoose = [];
        this.setYeji = this.deepClone(this.syncYeji);
      }
      this.staffIds = [];
      this.staffConflictTips = [];
    },
    openTimePicker() {
      if (!this.selectedService) return this.$Message.warning('请先选择预约服务');
      this.timePickerVisible = true;
    },
    clearReservationTime() {
      this.selectedTimeRange = null;
      this.form.reservation_time = '';
    },
    onTimePickerConfirm(range) {
      this.selectedTimeRange = range;
      this.form.reservation_time = range.begin.split(' ')[0];
      this.timePickerVisible = false;
      this.loadBusyStaffForPicker();
      this.loadStaffConflicts();
    },
    buildServiceLabel(name, cardName, sku, remain) {
      let label = cardName ? `${name}（${cardName}）` : name;
      if (sku) label += ` / ${sku}`;
      if (remain != null) label += `（余${remain}次）`;
      return label;
    },
    isCardPackageOrder(order) {
      return Number(order.type) === 11 || Number(order.product_type) === 5;
    },
    parseCartRows(order, rows) {
      const options = [];
      const isCardOrder = this.isCardPackageOrder(order);
      let cardName = '';
      if (isCardOrder) {
        const header = rows.find((row) => Number(row.cart_type) === 0);
        cardName = (header && header.cart_info && header.cart_info.productInfo && header.cart_info.productInfo.store_name) || order.store_name || '';
      }
      (rows || []).forEach((item) => {
        const pinfo = (item.cart_info && item.cart_info.productInfo) || {};
        const attrInfo = pinfo.attrInfo || {};
        const sku = attrInfo.suk || '';
        const storeName = Number(item.is_gift) === 1 ? `赠送${pinfo.store_name || ''}` : (pinfo.store_name || '');
        let label = item.display_name || this.buildServiceLabel(storeName, cardName, sku, item.write_surplus_times);
        if (item.display_name) {
          if (sku) label += ` / ${sku}`;
          if (item.write_surplus_times != null) label += `（余${item.write_surplus_times}次）`;
        }
        const canBook = Number(item.write_surplus_times) > 0 && Number(item.is_writeoff) !== 1;
        options.push({
          label,
          value: `${order.id}_${item.id}`,
          _key: `${order.id}_${item.id}`,
          order,
          cartItem: item,
          skuLabel: sku,
          remainText: `剩余 ${item.write_surplus_times} 次`,
          disabled: !canBook,
          project_service_duration: resolveProjectDuration(pinfo.project_service_duration || item.project_service_duration),
          addon_service_duration: resolveAddonDuration(pinfo.addon_service_duration || item.addon_service_duration),
          sourceType: 1,
        });
      });
      return options;
    },
    loadAllServices() {
      this.serviceLoading = true;
      cashierProduct({ product_type: 6, store_name: '', cate_id: 0 })
        .then((res) => {
          const list = this.filterAllReservationProducts(res.data.list || []);
          this.serviceOptions = list.map((item) => ({
            label: item.store_name,
            value: String(item.id),
            _key: `all_${item.id}`,
            product: item,
            project_service_duration: resolveProjectDuration(item.project_service_duration),
            addon_service_duration: resolveAddonDuration(item.addon_service_duration),
            sourceType: 2,
          }));
          this.serviceLoading = false;
          if (!this.serviceOptions.length) this.$Message.warning('暂无可预约的服务项目');
        })
        .catch((err) => {
          this.serviceLoading = false;
          this.$Message.error(err.msg);
        });
    },
    parsePurchasedItems(list) {
      const options = [];
      (list || []).forEach(({ order, cart_info: rows }) => {
        options.push(...this.parseCartRows(order, rows || []));
      });
      return options;
    },
    loadPurchasedServices() {
      if (!this.memberInfo || this.projectType !== 1) return;
      this.serviceLoading = true;
      getUserPurchasedRemainItems(this.memberInfo.uid)
        .then((res) => {
          const options = this.parsePurchasedItems(res.data.list || []);
          this.serviceOptions = options;
          this.serviceLoading = false;
          if (!options.length) this.$Message.warning('该会员暂无可预约的已购项目');
        })
        .catch((err) => {
          this.serviceLoading = false;
          this.$Message.error(err.msg);
        });
    },
    openServicePicker() {
      if (this.projectType === 1 && !this.memberInfo) {
        return this.$Message.warning('请先选择会员');
      }
      if (this.projectType === 1) {
        if (!this.serviceOptions.length) this.loadPurchasedServices();
      } else if (!this.serviceOptions.length) {
        this.loadAllServices();
      }
      this.servicePickerSearch = '';
      this.servicePickerVisible = true;
    },
    selectServiceOption(item) {
      this.serviceKey = item.value;
    },
    confirmServicePicker() {
      if (!this.serviceKey) return;
      const option = this.serviceOptions.find((item) => item.value === this.serviceKey);
      if (!option) return;
      this.selectedService = option;
      this.servicePickerVisible = false;
      this.addonItems = [];
      this.onServiceChange(option);
    },
    onServiceChange(option) {
      this.clearReservationTime();
      if (!option) {
        this.cartInfo = {};
        this.orderInfo = {};
        this.mainProjectDuration = 0;
        return;
      }
      if (option.sourceType === 1) {
        this.onPurchasedServiceChange(option);
      } else {
        this.onGuestServiceChange(option);
      }
    },
    onPurchasedServiceChange(option) {
      const { order, cartItem } = option;
      this.selectOrder = order;
      const attrInfo = cartItem.cart_info.productInfo.attrInfo || {};
      const pinfo = cartItem.cart_info.productInfo || {};
      this.mainProjectDuration = resolveProjectDuration(pinfo.project_service_duration || option.project_service_duration);
      this.cartInfo = {
        cart_id: cartItem.cart_id,
        cart_info_id: cartItem.id,
        product_name: pinfo.store_name,
        product_id: cartItem.product_id,
        unique: cartItem.sku_unique || attrInfo.unique || '',
      };
      this.initYejiData();
      getOrderReservationInfo(order.id, { cart_info_id: cartItem.id })
        .then((res) => {
          const data = res.data || {};
          this.orderInfo = data;
          this.mainProjectDuration = resolveProjectDuration(data.project_service_duration);
        })
        .catch((err) => this.$Message.error(err.msg));
    },
    onGuestServiceChange(option) {
      this.selectOrder = {};
      const uid = this.memberInfo ? this.memberInfo.uid : 0;
      cashierDetail(option.product.id, uid)
        .then((res) => {
          const data = res.data || {};
          const productInfo = data.storeInfo || option.product;
          const productValue = data.productValue || {};
          let unique = '';
          if (productInfo.spec_type) {
            const values = Object.values(productValue);
            const defaultSku = values.find((v) => v.is_default_select) || values[0];
            unique = defaultSku ? defaultSku.unique : '';
          } else {
            const firstValue = Object.values(productValue)[0];
            unique = firstValue ? firstValue.unique : '';
          }
          this.mainProjectDuration = resolveProjectDuration(productInfo.project_service_duration || option.project_service_duration);
          this.cartInfo = {
            product_id: productInfo.id,
            product_name: productInfo.store_name,
            unique,
          };
          this.orderInfo = { reservation_type: productInfo.reservation_type || 2 };
          this.initYejiData({ product_id: productInfo.id, price: productInfo.price });
        })
        .catch((err) => this.$Message.error(err.msg));
    },
    openAddonPicker() {
      if (!this.serviceKey) return this.$Message.warning('请先选择预约服务');
      this.addonPickerTab = 1;
      this.addonPickerSearch = '';
      this.addonPickerSelectedKeys = [];
      this.addonPickerVisible = true;
      this.loadAddonPickerOptions();
    },
    switchAddonTab(tab) {
      this.addonPickerTab = tab;
      this.addonPickerSelectedKeys = [];
      this.loadAddonPickerOptions();
    },
    loadAddonPickerOptions() {
      this.addonPickerLoading = true;
      if (this.addonPickerTab === 2) {
        cashierProduct({ product_type: 6, store_name: '', cate_id: 0 })
          .then((res) => {
            const list = this.filterAllReservationProducts(res.data.list || []);
            this.addonPickerOptions = list.map((item) => ({
              _key: `addon_all_${item.id}`,
              label: item.store_name,
              product_id: item.id,
              unique: '',
              product: item,
              addon_service_duration: resolveAddonDuration(item.addon_service_duration),
              durationText: `${resolveAddonDuration(item.addon_service_duration)}分钟`,
              sourceType: 2,
            }));
            this.addonPickerLoading = false;
          })
          .catch((err) => {
            this.addonPickerLoading = false;
            this.$Message.error(err.msg);
          });
        return;
      }
      if (!this.memberInfo) {
        this.addonPickerOptions = [];
        this.addonPickerLoading = false;
        return;
      }
      getUserPurchasedRemainItems(this.memberInfo.uid)
        .then((res) => {
          this.addonPickerOptions = this.parsePurchasedItems(res.data.list || [])
            .filter((item) => !item.disabled)
            .map((item) => ({
            _key: `addon_${item.order.id}_${item.cartItem.id}`,
            label: item.label,
            product_id: item.cartItem.product_id,
            unique: item.cartItem.sku_unique || (item.cartItem.cart_info.productInfo.attrInfo && item.cartItem.cart_info.productInfo.attrInfo.unique) || '',
            oid: item.order.id,
            cart_info_id: item.cartItem.id,
            addon_service_duration: resolveAddonDuration(item.addon_service_duration),
            durationText: `${resolveAddonDuration(item.addon_service_duration)}分钟`,
            sourceType: 1,
          }));
          this.addonPickerLoading = false;
        })
        .catch((err) => {
          this.addonPickerLoading = false;
          this.$Message.error(err.msg);
        });
    },
    toggleAddonSelect(key) {
      const idx = this.addonPickerSelectedKeys.indexOf(key);
      if (idx > -1) this.addonPickerSelectedKeys.splice(idx, 1);
      else this.addonPickerSelectedKeys.push(key);
    },
    confirmAddonPicker() {
      const selected = this.addonPickerOptions.filter((item) => this.addonPickerSelectedKeys.includes(item._key));
      const tasks = selected.map((item) => {
        if (item.unique || item.sourceType === 1) {
          return Promise.resolve(item);
        }
        const uid = this.memberInfo ? this.memberInfo.uid : 0;
        return cashierDetail(item.product_id, uid).then((res) => {
          const data = res.data || {};
          const productInfo = data.storeInfo || item.product;
          const productValue = data.productValue || {};
          let unique = '';
          if (productInfo.spec_type) {
            const values = Object.values(productValue);
            const defaultSku = values.find((v) => v.is_default_select) || values[0];
            unique = defaultSku ? defaultSku.unique : '';
          } else {
            const firstValue = Object.values(productValue)[0];
            unique = firstValue ? firstValue.unique : '';
          }
          return { ...item, unique, addon_service_duration: resolveAddonDuration(productInfo.addon_service_duration || item.addon_service_duration) };
        });
      });
      Promise.all(tasks).then((rows) => {
        rows.forEach((item) => {
          if (!this.addonItems.find((a) => a._key === item._key)) {
            this.addonItems.push({ ...item, durationText: `${resolveAddonDuration(item.addon_service_duration)}分钟` });
          }
        });
        this.addonPickerVisible = false;
        this.clearReservationTime();
      }).catch((err) => this.$Message.error(err.msg || '加载加项失败'));
    },
    removeAddon(index) {
      this.addonItems.splice(index, 1);
      this.clearReservationTime();
    },
    cityInfo(data) {
      cityApi(data).then((res) => { this.addresData = res.data; });
    },
    loadData(item, callback) {
      item.loading = true;
      cityApi({ pid: item.value }).then((res) => {
        item.children = res.data;
        item.loading = false;
        callback();
      });
    },
    addressChange(e, selectedData) {
      this.form.reservation_address_city_id = e;
      this.regionAddress = selectedData.map((o) => o.label).join('/');
    },
    buildAddonPayload() {
      return this.addonItems.map((item) => {
        const payload = {
          product_id: item.product_id,
          unique: item.unique || '',
          product_name: item.label,
          addon_service_duration: resolveAddonDuration(item.addon_service_duration),
        };
        if (item.cart_info_id) {
          payload.cart_info_id = Number(item.cart_info_id);
        }
        if (item.oid) {
          payload.oid = Number(item.oid);
        }
        return payload;
      });
    },
    submit() {
      if (!this.memberInfo) return this.$Message.error('请选择会员');
      if (!this.serviceKey || !this.cartInfo.product_id) return this.$Message.error('请选择预约服务');
      if (!this.form.reservation_name) return this.$Message.error('请输入联系人');
      if (!/^1(3|4|5|7|8|9|6)\d{9}$/.test(this.form.reservation_phone)) return this.$Message.error('请输入正确的联系人电话');
      const reservationDate = this.formatReservationDate(this.form.reservation_time);
      if (!reservationDate) return this.$Message.error('请选择预约时间');
      if (!this.selectedTimeRange || !this.selectedTimeRange.begin) return this.$Message.error('请选择预约时间');
      const baseData = {
        reservation_name: this.form.reservation_name,
        reservation_phone: this.form.reservation_phone,
        reservation_time: reservationDate,
        reservation_start: this.normalizeClock(this.selectedTimeRange.begin),
        reservation_end: this.normalizeClock(this.selectedTimeRange.end),
        mark: this.form.mark,
        service_duration_minutes: this.totalServiceDuration,
        addon_items: this.buildAddonPayload(),
        ...this.buildTablePayload(),
      };
      if (this.orderInfo.reservation_type == 3) {
        if (!this.form.reservation_address_city_id.length) return this.$Message.error('请选择上门地址');
        if (!this.reservationAddress) return this.$Message.error('请输入详细地址');
        baseData.reservation_address = `${this.regionAddress}/${this.reservationAddress}`;
      }
      const staffIds = this.getSelectedStaffIds();
      const doSubmit = () => {
        this.submitLoading = true;
        const staffPayload = this.buildStaffPayload();
        if (this.projectType === 1) {
          if (!this.cartInfo.cart_info_id) {
            this.submitLoading = false;
            return this.$Message.error('请选择已购项目');
          }
          postReservationCreate(this.selectOrder.id, {
            ...baseData,
            ...staffPayload,
            cart_num: 1,
            cart_info_id: this.cartInfo.cart_info_id,
            custom_form: [],
          })
            .then((res) => {
              this.submitLoading = false;
              this.$Message.success(res.msg);
              this.visible = false;
              this.$emit('submitSuccess');
            })
            .catch((err) => {
              this.submitLoading = false;
              this.$Message.error(err.msg);
            });
        } else {
          postGuestReservationCreate({
            ...baseData,
            ...staffPayload,
            uid: this.memberInfo.uid,
            product_id: this.cartInfo.product_id,
            unique: this.cartInfo.unique,
          })
            .then((res) => {
              this.submitLoading = false;
              this.$Message.success(res.msg);
              this.visible = false;
              this.$emit('submitSuccess');
            })
            .catch((err) => {
              this.submitLoading = false;
              this.$Message.error(err.msg);
            });
        }
      };
      if (!staffIds.length) {
        doSubmit();
        return;
      }
      this.loadStaffConflicts({ staffIds }).then((list) => {
        if (list.length) {
          this.showStaffConflictMessage(list);
          return;
        }
        doSubmit();
      });
    },
  },
};
</script>

<style scoped lang="less">
.modal-header {
  .modal-title { font-size: 16px; font-weight: 500; color: #303133; }
  .type-badge {
    margin-left: 8px; padding: 0 8px; height: 22px; line-height: 22px;
    font-size: 12px; color: #23c471; background: rgba(35, 196, 113, 0.1); border-radius: 2px;
  }
}
.modal-body { padding: 8px 0 0; min-height: 420px; }
.col-left { width: 50%; padding-right: 28px; border-right: 1px solid #eee; box-sizing: border-box; }
.col-right { width: 50%; padding-left: 28px; box-sizing: border-box; }
.form-row { width: 100%; }
.form-label {
  width: 88px; flex-shrink: 0; text-align: right; font-size: 14px; color: #606266;
  margin-right: 8px; line-height: 36px;
  &.form-label-top { line-height: 20px; padding-top: 8px; }
}
.required::before { content: '*'; color: #f5222d; margin-right: 4px; }
.pick-member-btn { margin-left: 12px; height: 36px; min-width: 88px; padding: 0 16px; }
.artisan-picker {
  min-height: 36px;
  line-height: 36px;
  padding: 0 12px;
  border: 1px solid #dcdee0;
  border-radius: 4px;
  cursor: pointer;
  &.disabled {
    cursor: not-allowed;
    background: #f5f5f5;
    color: #c5c8ce;
  }
}
.artisan-placeholder { color: #c5c8ce; font-size: 14px; }
.artisan-selected { color: #1890ff; font-size: 13px; }
.staff-conflict-tips {
  margin: 8px 0 0 96px;
  padding: 8px 12px;
  background: #fff7e6;
  border: 1px solid #ffd591;
  border-radius: 4px;
  color: #d46b08;
  font-size: 13px;
  line-height: 1.6;
}
.type-tabs { flex: 1; }
.type-tab {
  min-width: 88px; height: 32px; padding: 0 16px; border: 1px solid #dcdfe6; border-radius: 4px;
  line-height: 30px; text-align: center; color: #606266; font-size: 14px; cursor: pointer;
  &.on { color: #303133; border-color: #303133; background: #fff; }
}
.duration-text { font-size: 14px; color: #1890ff; font-weight: 500; }
.duration-detail { font-size: 12px; color: #909399; margin-left: 6px; }
.addon-section { border: 1px solid #ebeef5; border-radius: 4px; padding: 16px; background: #fafafa; }
.addon-title { font-size: 14px; color: #303133; font-weight: 500; margin-bottom: 12px; }
.addon-total-hint { font-size: 12px; color: #1890ff; font-weight: normal; }
.addon-alert {
  padding: 8px 12px; background: #fff1f0; border: 1px solid #ffccc7; border-radius: 4px;
  color: #f5222d; font-size: 12px; line-height: 18px;
  .addon-alert-icon { font-size: 14px; margin-right: 6px; flex-shrink: 0; }
}
.addon-empty { margin-top: 16px; padding: 20px 0; text-align: center; color: #909399; font-size: 14px; background: #fff; border-radius: 4px; }
.addon-list { margin-top: 12px; }
.addon-item {
  padding: 10px 12px; background: #fff; border-radius: 4px; margin-bottom: 8px; font-size: 13px;
  .addon-duration { color: #909399; margin: 0 8px; flex-shrink: 0; }
  .addon-remove { color: #1890ff; flex-shrink: 0; }
}
.addon-add-btn {
  margin-top: 12px; height: 36px; line-height: 34px; text-align: center; color: #1890ff;
  font-size: 14px; border: 1px dashed #1890ff; border-radius: 4px; background: #fff;
}
.purchase-tip {
  margin-top: 16px; padding: 8px 12px; background: #fff1f0; border: 1px solid #ffccc7;
  border-radius: 4px; color: #f5222d; font-size: 12px; line-height: 18px;
  .purchase-tip-icon { font-size: 14px; margin-right: 6px; flex-shrink: 0; }
}
.modal-footer {
  margin-top: 28px; padding-top: 20px; border-top: 1px solid #eee;
  .footer-btn { flex: 1; height: 44px; font-size: 16px; border-radius: 4px; }
  .cancel-btn { margin-right: 16px; color: #606266; background: #fff; border: 1px solid #dcdfe6; }
  .confirm-btn { background: #1890ff; border-color: #1890ff; }
}
.picker-search { margin-bottom: 16px; }
.picker-tabs { margin-bottom: 12px; }
.picker-tab {
  min-width: 88px; height: 32px; padding: 0 16px; border: 1px solid #dcdfe6; border-radius: 4px;
  line-height: 30px; text-align: center; color: #606266; font-size: 14px; cursor: pointer;
  &.on { color: #1890ff; border-color: #1890ff; background: rgba(24, 144, 255, 0.06); }
}
.picker-loading, .picker-empty { padding: 40px 0; text-align: center; color: #909399; }
.picker-scroll { max-height: 400px; overflow-y: auto; }
.picker-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.picker-card {
  border: 1px solid #ebeef5; border-radius: 8px; padding: 14px; cursor: pointer; background: #fff;
  &.selected { border-color: #1890ff; background: rgba(24, 144, 255, 0.06); }
}
.picker-card-title { font-size: 14px; color: #303133; font-weight: 500; min-height: 40px; }
.picker-card-sub, .picker-card-meta { font-size: 12px; color: #909399; margin-top: 6px; }
/deep/.ivu-modal-header { padding: 16px 24px; border-bottom: 1px solid #eee; }
/deep/.ivu-modal-body { padding: 20px 24px 24px; }
/deep/.ivu-input { border-radius: 4px !important; height: 36px !important; line-height: 36px !important; }
/deep/textarea.ivu-input { height: auto !important; line-height: 1.5 !important; min-height: 80px; }
/deep/.ivu-select-selection { height: 36px !important; border-radius: 4px !important; }
/deep/.ivu-select-single .ivu-select-selection .ivu-select-placeholder,
/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value { height: 36px !important; line-height: 36px !important; }
</style>

<style lang="less">
.add-reservation-modal .ivu-modal { top: 40px; }
.service-picker-modal .ivu-modal-body { padding: 20px 24px; }
</style>
