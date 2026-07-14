<template>
  <view class="content">
    <view class="container">
      <scroll-view class="scroll-view_H b-t b-b" scroll-x>
        <block v-for="(item,index) in dateArr" :key="index">
          <view class="flex-box" @click="selectDateEvent(index,item)" :class="{ borderb: index==dateActive}" :style="index==dateActive ? { borderBottomColor: selectedTabColor } : {}">
            <view class="date-box" :style="{color:index==dateActive?selectedTabColor:'#333'}">
              <text>{{item.title}}</text>
              <text class="fontw">{{item.week}}</text>
            </view>
          </view>
        </block>
      </scroll-view>

      <scroll-view scroll-y="true" class="scroll_view_time" v-if="!isSection || isQuantum">
        <view class="time-box">
          <template v-for="(item,_index) in timeArr">
            <view class="item" :key="_index" v-if="!item.is_hide">
              <view
                class="item-box"
                :class="{'disable':item.disable, 'active':isMultiple?item.isActive:_index==timeActive}"
                :style="getItemBoxStyle(_index, item)"
                @click="selectTimeEvent(_index,item)"
              >
                <text v-if="isQuantum">{{item.begin}}~{{item.end}}</text>
                <text v-else>{{item.time}}</text>
                <text class="all">{{item.disable?disableText:undisableText}}</text>
              </view>
            </view>
          </template>
        </view>
      </scroll-view>
    </view>
    <view class="bottom_choose" v-if="!embedded && !isWhere">
      <view class="bottom_choose_font">实际到达可能会有30分钟的浮动</view>
      <button form-type="submit" type="default" size="mini" class="submit-btn" @click="handleSubmit">确认预约</button>
    </view>
    <view class="flex" v-if="!embedded && isWhere">
      <view class="self_btn" @tap="reset">重置</view>
      <view class="self_btn self_sure" @tap="handleSubmit">确定</view>
    </view>
  </view>
</template>

<script>
import { initData, initTime, currentTime } from '@/utils/chooseTimeDate.js';

export default {
  name: 'ChooseTime',
  props: {
    embedded: { type: Boolean, default: false },
    isWhere: { type: Boolean, default: false },
    isQuantum: { type: Boolean, default: true },
    isMultiple: { type: Boolean, default: false },
    isSection: { type: Boolean, default: false },
    disableText: { type: String, default: '不可约' },
    undisableText: { type: String, default: '可预约' },
    timeInterval: { type: Number, default: 1 },
    selectedTabColor: { type: String, default: '#2A7EFB' },
    selectedItemColor: { type: String, default: '#2A7EFB' },
    beginTime: { type: String, default: '09:00:00' },
    endTime: { type: String, default: '22:00:00' },
    appointTime: { type: Array, default: () => [] },
    disableTimeSlot: { type: Array, default: () => [] },
    validTimeStarts: { type: Array, default: () => [] },
    selectedQuantum: { type: Object, default: null },
  },
  watch: {
    appointTime: { handler() { this.initOnload(); }, deep: true },
    disableTimeSlot: { handler() { this.initOnload(); }, deep: true },
    validTimeStarts: { handler() { this.initOnload(); }, deep: true },
    selectedQuantum: { handler() { this.applySelectedQuantum(); }, deep: true },
    beginTime() { this.initOnload(); },
    endTime() { this.initOnload(); },
    timeInterval() { this.initOnload(); },
  },
  data() {
    return {
      orderDateTime: '暂无选择',
      orderTimeArr: {},
      dateArr: [],
      timeArr: [],
      nowDate: '',
      dateActive: 0,
      timeActive: -1,
      selectDate: '',
    };
  },
  created() {
    this.selectDate = this.nowDate = currentTime().date;
    this.initOnload();
  },
  methods: {
    getItemBoxStyle(index, item) {
      const active = this.isMultiple ? item.isActive : index === this.timeActive;
      if (item.disable) {
        return { color: '#999' };
      }
      if (active) {
        return {
          color: this.selectedItemColor,
          border: `1px solid ${this.selectedItemColor}`,
          background: '#f0fdf9',
          fontWeight: 'bold',
        };
      }
      return {
        color: '#333',
        border: '1px solid #EEEEEE',
      };
    },
    reset() {
      this.$emit('changeTime', '');
    },
    isSlotOverlap(curBegin, curEnd, busyBegin, busyEnd) {
      return busyBegin < curEnd && busyEnd > curBegin;
    },
    initOnload(fromDateSwitch = false) {
      this.dateArr = initData();
      this.timeArr = initTime(this.beginTime, this.endTime, this.timeInterval, this.isQuantum);
      let isFullTime = true;
      this.timeArr.forEach((item) => {
        item.disable = false;
        item.is_hide = false;
        if (this.isQuantum) {
          const curBegin = `${this.selectDate} ${item.begin}:00`;
          const curEnd = `${this.selectDate} ${item.end}:00`;
          for (const time of this.disableTimeSlot) {
            const [begin_time = '', end_time = ''] = time;
            if (begin_time && end_time && this.isSlotOverlap(curBegin, curEnd, begin_time, end_time)) {
              item.disable = true;
            }
          }
          if (this.validTimeStarts.length && !this.validTimeStarts.includes(item.begin)) {
            item.disable = true;
          }
          if (this.selectDate == this.nowDate && currentTime().time > `${item.begin}:00`) {
            item.disable = true;
            item.is_hide = true;
          }
        } else {
          if (this.selectDate == this.nowDate && currentTime().time > item.time) {
            item.disable = true;
            item.is_hide = true;
          }
          this.appointTime.forEach(t => {
            const [date, slotTime] = t.split(' ');
            if (date == this.selectDate && item.time == slotTime) {
              item.disable = true;
            }
          });
          const curTime = `${this.selectDate} ${item.time}`;
          for (const time of this.disableTimeSlot) {
            const [begin_time = '', end_time = ''] = time;
            if (begin_time && end_time && begin_time <= curTime && curTime <= end_time) {
              item.disable = true;
            }
          }
          if (!item.disable) isFullTime = false;
        }
      });
      this.orderDateTime = isFullTime ? '暂无选择' : this.selectDate;
      this.timeActive = -1;
      this.applySelectedQuantum(fromDateSwitch);
    },
    applySelectedQuantum(fromDateSwitch = false) {
      const sel = this.selectedQuantum;
      if (!this.isQuantum || !sel || !sel.begin) return;
      const datePart = sel.begin.split(' ')[0];
      const beginTime = (sel.begin.split(' ')[1] || '').substring(0, 5);
      const endTime = sel.end ? (sel.end.split(' ')[1] || '').substring(0, 5) : '';
      if (datePart !== this.selectDate) {
        if (fromDateSwitch) return;
        const dateIdx = this.dateArr.findIndex((d) => d.date === datePart);
        if (dateIdx >= 0) {
          this.dateActive = dateIdx;
          this.selectDate = datePart;
          this.initOnload(true);
          return;
        }
      }
      let idx = this.timeArr.findIndex((t) => {
        if (t.begin !== beginTime) return false;
        if (endTime && t.end !== endTime) return false;
        return !t.disable;
      });
      if (idx < 0 && beginTime) {
        idx = this.timeArr.findIndex((t) => t.begin === beginTime && (!endTime || t.end === endTime));
      }
      this.timeActive = idx >= 0 ? idx : -1;
    },
    selectDateEvent(index, item) {
      this.dateActive = index;
      this.selectDate = item.date;
      this.initOnload(true);
      this.$emit('dateChange', item.date);
    },
    selectTimeEvent(index, item) {
      if (this.isQuantum) {
        return this.handleSelectQuantum(index, item);
      }
      if (item.disable) return;
      this.timeActive = index;
      this.orderDateTime = `${this.selectDate} ${item.time}`;
      if (this.embedded) {
        this.$emit('changeTime', this.orderDateTime);
      }
    },
    handleSelectQuantum(index, item) {
      if (item.disable) return;
      this.timeActive = index;
      this.orderDateTime = {
        begin: `${this.selectDate} ${item.begin}:00`,
        end: `${this.selectDate} ${item.end}:00`,
      };
      if (this.embedded) {
        this.$emit('changeTime', this.orderDateTime);
      }
    },
    handleSubmit() {
      if (this.isMultiple) {
        this.$emit('changeTime', this.orderTimeArr);
        return;
      }
      this.$emit('changeTime', this.orderDateTime);
    },
  },
};
</script>

<style lang="scss" scoped>
@import './pretty-times.scss';

.flex{
  display: flex;
  justify-content: space-between;
  padding: 30rpx 20rpx 0rpx 20rpx;
}
.self_btn{
  background-color: #dfdfdf;
  color: #625e5e;
  font-size: 30rpx;
  height: 80rpx;
  line-height: 80rpx;
  width: 46%;
  border-radius: 10rpx;
}
.self_sure{
  background: var(--view-theme, #2A7EFB);
  color: #ffffff;
}
.content {
  text-align: center;
}
.scroll_view_time{
  height: 540rpx !important;
}
.bottom_choose {
  text-align: center;
  width: 100%;
  background-color: #fff;
}
.bottom_choose_font{
  color:#939AA3;
  font-size: 24rpx;
  text-align: center;
  margin-top: 16rpx;
  margin-bottom: 16rpx;
}
.submit-btn {
  width: 90%;
  height: 90rpx;
  line-height: 90rpx;
  background: var(--view-theme, #2A7EFB);
  color: #ffffff;
  border-radius: 8rpx;
  font-size: 30rpx;
}
.fontw {
  font-weight: bold;
}
.borderb {
  border-bottom: 2px solid var(--view-theme, #2A7EFB);
}
</style>
