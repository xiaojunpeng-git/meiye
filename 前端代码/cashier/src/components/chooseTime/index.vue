<template>
  <div class="choose-time">
    <div class="date-scroll">
      <div
        v-for="(item, index) in dateArr"
        :key="index"
        class="date-item"
        :class="{ active: index === dateActive }"
        @click="selectDateEvent(index, item)"
      >
        <div class="date-box">
          <span>{{ item.title }}</span>
          <span class="fontw">{{ item.week }}</span>
        </div>
      </div>
    </div>
    <div class="time-scroll">
      <div class="time-box">
        <template v-for="(item, _index) in timeArr">
          <div v-if="!item.is_hide" :key="_index" class="time-item">
            <div
              class="time-card"
              :class="{ disable: item.disable, active: _index === timeActive }"
              @click="selectTimeEvent(_index, item)"
            >
              <span>{{ item.begin }}~{{ item.end }}</span>
              <span class="time-status">{{ item.disable ? disableText : undisableText }}</span>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script>
import { initData, initTime, currentTime } from '@/utils/chooseTimeDate';

export default {
  name: 'ChooseTime',
  props: {
    disableText: { type: String, default: '不可约' },
    undisableText: { type: String, default: '可预约' },
    timeInterval: { type: Number, default: 1 },
    beginTime: { type: String, default: '09:00:00' },
    endTime: { type: String, default: '22:00:00' },
    disableTimeSlot: { type: Array, default: () => [] },
  },
  data() {
    return {
      dateArr: [],
      timeArr: [],
      nowDate: '',
      dateActive: 0,
      timeActive: -1,
      selectDate: '',
      orderDateTime: null,
    };
  },
  watch: {
    disableTimeSlot: { handler() { this.initOnload(); }, deep: true },
    beginTime() { this.initOnload(); },
    endTime() { this.initOnload(); },
    timeInterval() { this.initOnload(); },
  },
  created() {
    this.selectDate = this.nowDate = currentTime().date;
    this.initOnload();
  },
  methods: {
    isSlotOverlap(curBegin, curEnd, busyBegin, busyEnd) {
      return busyBegin < curEnd && busyEnd > curBegin;
    },
    initOnload() {
      this.dateArr = initData();
      this.timeArr = initTime(this.beginTime, this.endTime, this.timeInterval, true);
      this.timeArr.forEach((item) => {
        item.disable = false;
        item.is_hide = false;
        const curBegin = `${this.selectDate} ${item.begin}:00`;
        const curEnd = `${this.selectDate} ${item.end}:00`;
        this.disableTimeSlot.forEach((time) => {
          const [begin_time = '', end_time = ''] = time;
          if (begin_time && end_time && this.isSlotOverlap(curBegin, curEnd, begin_time, end_time)) {
            item.disable = true;
          }
        });
        if (this.selectDate === this.nowDate && currentTime().time > `${item.begin}:00`) {
          item.disable = true;
          item.is_hide = true;
        }
      });
      this.timeActive = -1;
      this.orderDateTime = null;
    },
    selectDateEvent(index, item) {
      this.dateActive = index;
      this.selectDate = item.date;
      this.initOnload();
      this.$emit('dateChange', item.date);
    },
    selectTimeEvent(index, item) {
      if (item.disable) return;
      this.timeActive = index;
      this.orderDateTime = {
        begin: `${this.selectDate} ${item.begin}:00`,
        end: `${this.selectDate} ${item.end}:00`,
      };
      this.$emit('changeTime', this.orderDateTime);
    },
    getSelected() {
      return this.orderDateTime;
    },
    reset() {
      this.timeActive = -1;
      this.orderDateTime = null;
      this.$emit('changeTime', null);
    },
    setSelectedDate(date) {
      if (!date) return;
      const idx = this.dateArr.findIndex((item) => item.date === date);
      if (idx >= 0) {
        this.dateActive = idx;
        this.selectDate = date;
        this.initOnload();
      }
    },
  },
};
</script>

<style scoped lang="less">
.choose-time {
  .date-scroll {
    display: flex;
    overflow-x: auto;
    padding: 10px 0 0;
    border-bottom: 1px solid #e5e5e5;
    &::-webkit-scrollbar { height: 4px; }
  }
  .date-item {
    flex: 0 0 72px;
    margin: 0 6px;
    cursor: pointer;
    &.active .date-box { border-bottom: 2px solid #1890ff; color: #1890ff; }
  }
  .date-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 50px;
    font-size: 13px;
    color: #818181;
    .fontw { font-weight: 600; }
  }
  .time-scroll {
    max-height: 320px;
    overflow-y: auto;
    margin-top: 8px;
  }
  .time-box {
    display: flex;
    flex-wrap: wrap;
    padding: 8px 0;
  }
  .time-item {
    width: 33.33%;
    padding: 6px;
    box-sizing: border-box;
  }
  .time-card {
    min-height: 64px;
    padding: 8px 10px;
    border: 1px solid #eee;
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: #333;
    cursor: pointer;
    &.disable {
      background: #f1f3f6;
      color: #999;
      cursor: not-allowed;
    }
    &.active {
      border-color: #1890ff;
      color: #1890ff;
      font-weight: 600;
    }
    .time-status {
      font-size: 11px;
      margin-top: 4px;
      color: inherit;
    }
  }
}
</style>
