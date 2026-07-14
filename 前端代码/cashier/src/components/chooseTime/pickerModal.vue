<template>
  <Modal
    :value="visible"
    title="选择预约时间"
    width="640"
    class-name="choose-time-modal"
    :mask-closable="false"
    @on-cancel="close"
  >
    <div v-if="!canPick" class="picker-tip">请先选择预约服务</div>
    <div v-else-if="loading" class="picker-tip">加载可预约时段...</div>
    <choose-time
      v-else
      ref="chooseTime"
      :begin-time="timeBegin"
      :end-time="timeEnd"
      :time-interval="timeIntervalHours"
      :disable-time-slot="disableTimeSlot"
      @dateChange="onDateChange"
      @changeTime="onTimeChange"
    />
    <div slot="footer">
      <div class="picker-footer">
        <Button class="picker-footer-btn" @click="clearTime">清除</Button>
        <Button class="picker-footer-btn" @click="close">取消</Button>
        <Button type="primary" class="picker-footer-btn" :disabled="!pendingRange" :loading="confirmLoading" @click="confirm">确认</Button>
      </div>
    </div>
  </Modal>
</template>

<script>
import ChooseTime from './index.vue';
import { getStaffAvailableTime, getStaffReservationConflicts } from '@/api/reservation';
import { currentTime } from '@/utils/chooseTimeDate';

export default {
  name: 'ChooseTimePickerModal',
  components: { ChooseTime },
  props: {
    visible: { type: Boolean, default: false },
    canPick: { type: Boolean, default: false },
    staffId: { type: Number, default: 0 },
    staffIds: { type: Array, default: () => [] },
    serviceDuration: { type: Number, default: 0 },
    excludeReservationId: { type: Number, default: 0 },
    value: { type: Object, default: null },
  },
  data() {
    return {
      loading: false,
      confirmLoading: false,
      timeBegin: '09:00:00',
      timeEnd: '22:00:00',
      disableTimeSlot: [],
      currentDate: '',
      pendingRange: null,
    };
  },
  computed: {
    timeIntervalHours() {
      const minutes = Number(this.serviceDuration) || 120;
      return Math.max(0.25, minutes / 60);
    },
    effectiveStaffIds() {
      const ids = (this.staffIds || []).map((id) => Number(id)).filter(Boolean);
      if (ids.length) return ids;
      if (this.staffId) return [Number(this.staffId)];
      return [];
    },
  },
  watch: {
    visible(val) {
      if (val && this.canPick) {
        this.pendingRange = this.value ? { ...this.value } : null;
        let date = '';
        if (this.pendingRange && this.pendingRange.begin) {
          date = this.pendingRange.begin.split(' ')[0];
        } else {
          date = currentTime().date;
        }
        this.currentDate = date;
        this.loadAvailableTime(date);
      }
    },
    effectiveStaffIds: {
      handler() {
        if (this.visible && this.canPick && this.currentDate) {
          this.loadAvailableTime(this.currentDate);
        }
      },
      deep: true,
    },
    serviceDuration() {
      if (this.visible && this.canPick && this.currentDate) {
        this.loadAvailableTime(this.currentDate);
      }
    },
  },
  methods: {
    normalizeClock(value) {
      if (!value) return '';
      const part = value.indexOf(' ') >= 0 ? value.split(' ')[1] : value;
      return part.substring(0, 5);
    },
    onDateChange(date) {
      this.currentDate = date;
      this.pendingRange = null;
      this.loadAvailableTime(date);
    },
    onTimeChange(val) {
      this.pendingRange = val && val.begin ? val : null;
    },
    loadAvailableTime(date) {
      if (!date) return;
      this.loading = true;
      const staffIds = this.effectiveStaffIds;
      getStaffAvailableTime({
        staff_id: staffIds[0] || 0,
        staff_ids: staffIds.join(','),
        service_date: date,
        service_duration: this.serviceDuration || 0,
        exclude_reservation_id: this.excludeReservationId || 0,
      })
        .then((res) => {
          const data = res.data || {};
          this.timeBegin = data.begin_time || '09:00:00';
          this.timeEnd = data.end_time || '22:00:00';
          this.disableTimeSlot = data.disable_time_slot || [];
          this.loading = false;
          this.$nextTick(() => {
            if (this.$refs.chooseTime) {
              this.$refs.chooseTime.setSelectedDate(date);
            }
          });
        })
        .catch(() => {
          this.disableTimeSlot = [];
          this.loading = false;
        });
    },
    clearTime() {
      this.pendingRange = null;
      if (this.$refs.chooseTime) this.$refs.chooseTime.reset();
    },
    confirm() {
      if (!this.pendingRange || !this.pendingRange.begin) {
        return this.$Message.warning('请选择可预约时段');
      }
      const staffIds = this.effectiveStaffIds;
      if (!staffIds.length) {
        this.$emit('confirm', { ...this.pendingRange });
        this.close();
        return;
      }
      const serviceDate = this.pendingRange.begin.split(' ')[0];
      const reservationStart = this.normalizeClock(this.pendingRange.begin);
      const reservationEnd = this.normalizeClock(this.pendingRange.end);
      this.confirmLoading = true;
      getStaffReservationConflicts({
        staff_ids: staffIds.join(','),
        service_date: serviceDate,
        reservation_start: reservationStart,
        reservation_end: reservationEnd || '',
        service_duration: this.serviceDuration || 0,
        exclude_reservation_id: this.excludeReservationId || 0,
      }).then((res) => {
        const list = (res.data && res.data.list) || [];
        if (list.length) {
          const msg = list.map((item) => `${item.staff_name}在${item.reservation_date} ${item.reservation_start}-${item.reservation_end}已有预约`).join('；');
          this.$Message.error(msg);
          return;
        }
        this.$emit('confirm', { ...this.pendingRange });
        this.close();
      }).catch(() => {
        this.$Message.error('时段校验失败，请重试');
      }).finally(() => {
        this.confirmLoading = false;
      });
    },
    close() {
      this.$emit('close');
    },
  },
};
</script>

<style scoped lang="less">
.picker-tip {
  padding: 48px 0;
  text-align: center;
  color: #909399;
  font-size: 14px;
}
.picker-footer {
  display: flex;
  justify-content: center;
  gap: 12px;
  .picker-footer-btn {
    min-width: 100px;
    height: 40px;
    border-radius: 20px;
  }
}
</style>

<style lang="less">
.choose-time-modal .ivu-modal-body {
  padding: 16px 20px 8px;
}
</style>
