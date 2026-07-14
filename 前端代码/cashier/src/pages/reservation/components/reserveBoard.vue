<template>
  <div class="h-full">
    <FullCalendar ref="fullCalendar" :options="calendarOptions">
      <template v-slot:eventContent="arg">
        <template v-if="isBoardRestEvent(arg.event)"></template>
        <Tooltip v-else max-width="200" transfer-class-name="fc-tooltip" transfer>
          <div class="reservation-name">
            <div class="reservation-type">到店</div>
            <div style="display: inline-block">
              {{ arg.event.extendedProps.reservation_name }}
            </div>
            <div style="display: inline-block">
              {{ arg.event.extendedProps.reservation_phone }}
            </div>
          </div>
          <div>
            {{ boardProductName(arg.event) }}
          </div>
          <div slot="content">
            <div class="reservation-name">
              <div class="reservation-type">到店</div>
              <div style="display: inline-block">
                {{ arg.event.extendedProps.reservation_name }}
              </div>
              <div style="display: inline-block">
                {{ arg.event.extendedProps.reservation_phone }}
              </div>
            </div>
            <div>
              {{ boardProductName(arg.event) }}
            </div>
          </div>
        </Tooltip>
      </template>
    </FullCalendar>
    <div class="float-box">
      <div class="list">
        <div class="item">
          <div class="mark mark-confirm"></div>
          待确认
        </div>
        <div class="item">
          <div class="mark"></div>
          待服务
        </div>
        <div class="item">
          <div class="mark mark2"></div>
          进行中
        </div>
        <div class="item">
          <div class="mark mark3"></div>
          已完成
        </div>
        <div class="item" v-if="scheduleManage">
          <div class="mark mark-rest"></div>
          休息
        </div>
      </div>
      <div @click="handleRefresh" class="pointer">
        <Icon type="ios-refresh-circle" color="#1890FF" size="35" />
      </div>
    </div>
    <Modal
      v-model="modal"
      footer-hide
      width="566"
      title="看板设置"
      class-name="board-modal"
      @on-cancel="close"
    >
      <Form ref="formValidate" :model="formValidate" :label-width="110">
        <FormItem label="是否显示员工：">
          <Switch
            v-model="formValidate.is_show_staff_switch"
            :true-value="1"
            :false-value="0"
            size="large"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </Switch>
        </FormItem>
        <FormItem label="时间间隔：">
          <Slider
            v-model="formValidate.chart_time_interval"
            :min="30"
            :max="180"
            :step="30"
            show-stops
            :marks="marks"
            class="w-408"
            style="white-space: nowrap"
          ></Slider>
        </FormItem>
      </Form>
      <div class="acea-row row-center-wrapper mt-60">
        <div
          @click="close"
          class="w-176 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 acea-row row-center-wrapper pointer"
        >
          取消
        </div>
        <div
          @click="setNoticeBoardConfig"
          class="w-176 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF acea-row row-center-wrapper pointer ml-20"
        >
          确认
        </div>
      </div>
    </Modal>
  </div>
</template>

<script>
import FullCalendar from '@fullcalendar/vue';
import resourceTimelinePlugin from '@fullcalendar/resource-timeline';
import dayjs from 'dayjs';
import {
  reservationNoticeBoard,
  noticeBoardConfig,
  postNoticeBoardConfig,
} from '@/api/reservation';

export default {
  components: {
    FullCalendar,
  },
  props: {
    reservation_time: {
      // type: [String, Number],
      default: '',
    },
    reservation_phone: {
      type: [String, Number],
      default: '',
    },
    service_staff_id: {
      type: [String, Number],
      default: '',
    },
  },
  data() {
    return {
      marks: {
        30: '30分钟',
        60: '1小时',
        120: '2小时',
        180: '3小时',
      },
      modal: false,
      formValidate: {
        is_show_staff_switch: 1,
        chart_time_interval: 30,
      },
      calendarOptions: {
        height: '100%',
        slotMinWidth: 90,
        eventMaxStack: 5,
        moreLinkContent: {
          html: '<a>全部预约<span class="fc-icon fc-icon-chevron-right"></span></a>',
        },
        moreLinkClick: this.handleMoreLinkClick,
        locale: 'zh-cn',
        plugins: [resourceTimelinePlugin],
        initialView: 'resourceTimelineDay',
        aspectRatio: 3,
        headerToolbar: {
          left: 'prev,title,next,volume,number',
          right: 'setup',
        },
        customButtons: {
          setup: {
            text: '看板设置',
            click: () => {
              this.modal = true;
            },
          },
          volume: {
            text: '当日预约量',
          },
          number: {
            text: '0',
          },
        },
        slotLabelFormat: {
          hour: '2-digit', // 两位数小时（如 10）
          minute: '2-digit', // 两位数分钟（如 00）
          hour12: false, // 24小时制
          omitZeroMinute: false, // 不省略分钟（强制显示:00）
          locale: 'zh-cn', // 本地化（需加载中文语言包）
        },
        nowIndicator: true,
        editable: true,
        resourceAreaWidth: '18%',
        scrollTime: `${dayjs().hour()}:00:00`,
        resourceAreaHeaderContent: '',
        resources: [],
        events: [],
        slotDuration: '00:30:00',
        slotLabelInterval: '00:30', // 标签间隔2小时
        datesSet: this.handleDatesSet,
        eventClick: this.handleEventClick,
        resourceLabelContent: this.renderResourceLabelContent,
        schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
      },
      reservationTime: '',
      scheduleManage: 0,
      boardLoading: false,
      boardPendingArg: undefined,
      boardRequestTimer: null,
    };
  },
  watch: {
    reservation_time(value) {
      const dateStr = this.normalizeBoardDate(value);
      if (!dateStr) return;
      this.syncBoardDate(dateStr);
    },
  },
  mounted() {
    this.getNoticeBoardConfig();
  },
  beforeDestroy() {
    if (this.boardRequestTimer) {
      clearTimeout(this.boardRequestTimer);
      this.boardRequestTimer = null;
    }
  },
  methods: {
    normalizeBoardDate(value) {
      if (!value) return '';
      if (Object.prototype.toString.call(value) === '[object Date]') {
        return dayjs(value).format('YYYY-MM-DD');
      }
      if (typeof value === 'string') {
        const part = value.split(' ')[0].replace(/\//g, '-');
        return dayjs(part).isValid() ? dayjs(part).format('YYYY-MM-DD') : '';
      }
      return '';
    },
    syncBoardDate(dateStr) {
      if (!dateStr) return;
      this.reservationTime = dateStr;
      this.$nextTick(() => {
        const calendarApi = this.$refs.fullCalendar && this.$refs.fullCalendar.getApi();
        if (calendarApi) {
          calendarApi.gotoDate(dateStr);
        }
        this.getReservationNoticeBoard({
          reservation_time: dateStr,
          phone: this.reservation_phone,
          service_staff_id: this.service_staff_id,
        });
      });
    },
    isBoardRestEvent(event) {
      return event && event.display === 'background';
    },
    boardProductName(event) {
      const cartInfo = event && event.extendedProps ? event.extendedProps.cart_info : null;
      return cartInfo && cartInfo.productInfo ? cartInfo.productInfo.store_name : '';
    },
    buildStaffBoardCounts(list) {
      const items = list || [];
      let pending = 0;
      let done = 0;
      items.forEach((item) => {
        const status = Number(item.status);
        if (status === 2) {
          done += 1;
        } else if (status !== -1) {
          pending += 1;
        }
      });
      return { pending, done };
    },
    buildStaffResource(dataItem) {
      const name = dataItem.id == 0 ? '未分配' : (dataItem.staff_name || '');
      const { pending, done } = this.buildStaffBoardCounts(dataItem.list);
      return {
        id: String(dataItem.id),
        title: name,
        extendedProps: {
          staffName: name,
          pendingCount: pending,
          doneCount: done,
        },
      };
    },
    escapeHtml(text) {
      return String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    },
    renderResourceLabelContent(arg) {
      const resource = arg.resource || {};
      const props = resource.extendedProps || {};
      const name = this.escapeHtml(props.staffName || resource.title || '');
      const pending = Number(props.pendingCount) || 0;
      const done = Number(props.doneCount) || 0;
      return {
        html: `<div class="board-staff-label"><span class="board-staff-name">${name}</span><span class="board-staff-stats"><span class="stat-pending">未做${pending}</span><span class="stat-done">已做${done}</span></span></div>`,
      };
    },
    buildBoardReservationEvent(item, dataItem, color) {
      const start = `${item.reservation_time} ${item.reservation_start}`;
      const end = `${item.reservation_time} ${item.reservation_end}`;
      return {
        id: String(item.id),
        resourceId: String(dataItem.id),
        start,
        end,
        color,
        extendedProps: {
          ...item,
          staff_name: dataItem.staff_name,
        },
      };
    },
    getReservationNoticeBoard(arg) {
      if (this.boardRequestTimer) {
        clearTimeout(this.boardRequestTimer);
      }
      this.boardRequestTimer = setTimeout(() => {
        this.fetchReservationNoticeBoard(arg);
      }, 120);
    },
    fetchReservationNoticeBoard(arg) {
      if (this.boardLoading) {
        this.boardPendingArg = arg;
        return;
      }
      let data = {
        reservation_time: this.reservationTime,
        reservation_phone: this.reservation_phone,
        service_staff_id: this.service_staff_id,
      };
      if (Object.prototype.toString.call(arg) == '[object Object]') {
        if (arg.reservation_time !== undefined && arg.reservation_time !== null && arg.reservation_time !== '') {
          data.reservation_time = this.normalizeBoardDate(arg.reservation_time);
        }
        if (arg.phone !== undefined) {
          data.reservation_phone = arg.phone;
        }
        if (arg.service_staff_id !== undefined) {
          data.service_staff_id = arg.service_staff_id;
        }
      }
      if (!data.reservation_time) {
        return;
      }
      this.reservationTime = data.reservation_time;
      this.boardLoading = true;
      reservationNoticeBoard(data)
        .then((res) => {
          const boardData = res.data.data || [];
          const staff = [];
          const events = [];
          boardData.forEach((dataItem) => {
            staff.push(this.buildStaffResource(dataItem));
            (dataItem.list || []).forEach((item) => {
              let color = '#377DFF';
              if (item.status == 1) {
                color = '#FF8D30';
              } else if (item.status == 2 || item.status == -1) {
                color = '#23C471';
              } else if (item.status == 3) {
                color = '#9254DE';
              }
              events.push(this.buildBoardReservationEvent(item, dataItem, color));
            });
          });
          (res.data.rest_events || []).forEach((item, index) => {
            events.push({
              id: `rest-${item.staff_id || item.resourceId || 0}-${index}`,
              resourceId: String(item.resourceId || item.staff_id || 0),
              start: item.start,
              end: item.end,
              display: 'background',
              color: item.color || '#E8E8E8',
              editable: false,
            });
          });
          this.scheduleManage = res.data.schedule_manage || 0;
          this.applyBoardData(staff, events, res.data.count || 0);
          if (this.isRefresh) {
            this.isRefresh = false;
            this.$Message.success('刷新完成');
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        })
        .finally(() => {
          this.boardLoading = false;
          if (this.boardPendingArg !== undefined) {
            const pendingArg = this.boardPendingArg;
            this.boardPendingArg = undefined;
            this.fetchReservationNoticeBoard(pendingArg);
          }
        });
    },
    applyBoardData(staff, events, count) {
      const calendarApi = this.$refs.fullCalendar && this.$refs.fullCalendar.getApi();
      if (!calendarApi) {
        this.calendarOptions.resources = staff;
        this.calendarOptions.events = events;
        this.calendarOptions.customButtons.number.text = `${count}`;
        return;
      }
      calendarApi.batchRendering(() => {
        calendarApi.getResources().forEach((resource) => {
          resource.remove();
        });
        staff.forEach((resource) => {
          calendarApi.addResource(resource);
        });
        calendarApi.getEvents().forEach((event) => {
          event.remove();
        });
        events.forEach((event) => {
          calendarApi.addEvent(event);
        });
      });
      this.calendarOptions.customButtons.number.text = `${count}`;
    },
    handleDatesSet(arg) {
      this.reservationTime = dayjs(arg.start).format('YYYY-MM-DD');
      this.getReservationNoticeBoard();
    },
    handleEventClick({ event }) {
      if (this.isBoardRestEvent(event)) {
        return;
      }
      if (event.extendedProps.status == -1) {
        return;
      }
      this.$emit('serviceTap', [{ ...event.extendedProps, id: event.id }]);
    },
    // 获取看板配置
    getNoticeBoardConfig() {
      noticeBoardConfig('cashier_notice_board').then((res) => {
        if (Array.isArray(res.data)) {
          return;
        }
        this.formValidate = res.data;
        this.calendarOptions.resourceAreaWidth = this.formValidate
          .is_show_staff_switch
          ? '18%'
          : 0;
        this.calendarOptions.slotDuration = dayjs()
          .hour(0)
          .minute(this.formValidate.chart_time_interval)
          .second(0)
          .format('HH:mm:ss');
        this.calendarOptions.slotLabelInterval = dayjs()
          .hour(0)
          .minute(this.formValidate.chart_time_interval)
          .format('HH:mm');
      });
    },
    // 保存看板配置
    setNoticeBoardConfig() {
      postNoticeBoardConfig('cashier_notice_board', this.formValidate)
        .then((res) => {
          this.$Message.success(res.msg);
          this.modal = false;
          this.getNoticeBoardConfig();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    close() {
      this.modal = false;
    },
    handleRefresh() {
      this.isRefresh = true;
      this.getReservationNoticeBoard();
    },
    handleMoreLinkClick(info) {
      info.jsEvent.preventDefault();
      const currentEvent = info.hiddenSegs[0].event;
      const resources = currentEvent.getResources();
      const rawEvents = resources[0].getEvents();
      const overlapEvents = rawEvents.filter(({ start, end, display }) => {
        if (display === 'background') {
          return false;
        }
        return currentEvent.start <= end && start < currentEvent.end;
      });
      overlapEvents.sort((a, b) => {
        return a.start - b.start;
      });
      let eventList = overlapEvents.map(({ extendedProps, id }) => {
        return {
          ...extendedProps,
          id,
        };
      });
      this.$emit('serviceTap', eventList);
    },
  },
};
</script>

<style lang="less" scoped>
/deep/.fc {
  .fc-popover {
    display: none;
  }
  .fc-timeline-more-link {
    background: transparent;
    .fc-icon {
      vertical-align: -1px;
    }
  }
  .fc-datagrid-cell-frame {
    display: flex;
    align-items: center;
  }
  .fc-datagrid-header {
    background-color: #fcfcfc;
  }
  .fc-datagrid-body {
    background-color: #fcfcfc;
  }
  .fc-timeline-slot-minor {
    border-style: none;
  }
  .fc-bg-event {
    opacity: 1;
    z-index: 1;
  }
  .fc-event:not(.fc-bg-event) {
    position: relative;
    z-index: 3;
    padding: 5px 6px;
    border-radius: 4px;
    white-space: nowrap;
    font-size: 12px;
    overflow: hidden;
  }
  .reservation-name {
    display: flex;
    align-items: center;
  }
  .reservation-type {
    flex-shrink: 0;
    display: flex;
    justify-content: center;
    align-items: center;
    width: 32px;
    height: 19px;
    border: 1px solid rgba(255, 255, 255, 0.5);
    border-radius: 3px;
    margin-right: 4px;
  }
  .fc-prev-button {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    width: 30px;
    height: 30px;
    border: 1px solid #dddddd;
    border-radius: 50% !important;
    background: none;
    font-size: 16px;
    color: #303133;
    vertical-align: middle;
  }
  .fc-prev-button:not(:disabled):active {
    border: 1px solid #dddddd;
    background: none;
    color: #303133;
  }
  .fc-prev-button:focus {
    box-shadow: none;
  }
  .fc-prev-button:not(:disabled):active:focus {
    box-shadow: none;
  }
  .fc-next-button {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    width: 30px;
    height: 30px;
    border: 1px solid #dddddd;
    border-radius: 50% !important;
    background: none;
    font-size: 16px;
    color: #303133;
    vertical-align: middle;
  }
  .fc-next-button:not(:disabled):active {
    border: 1px solid #dddddd;
    background: none;
    color: #303133;
  }
  .fc-next-button:focus {
    box-shadow: none;
  }
  .fc-next-button:not(:disabled):active:focus {
    box-shadow: none;
  }
  .fc-toolbar-title {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    width: 130px;
    font-weight: 500;
    font-size: 16px;
    color: #303133;
    vertical-align: middle;
  }
  .fc-volume-button {
    border: 0;
    background: none;
    font-size: 16px;
    color: #303133;
    cursor: auto;
  }
  .fc-volume-button:not(:disabled):active {
    border: 0;
    background: none;
    color: #303133;
  }
  .fc-volume-button:focus {
    box-shadow: none;
  }
  .fc-volume-button:not(:disabled):active:focus {
    box-shadow: none;
  }
  .fc-number-button {
    padding: 0;
    border: 0;
    background: none;
    font-weight: 500;
    font-size: 16px;
    color: #377dff;
    cursor: auto;
  }
  .fc-number-button:not(:disabled):active {
    border: 0;
    background: none;
    color: #303133;
  }
  .fc-number-button:focus {
    box-shadow: none;
  }
  .fc-number-button:not(:disabled):active:focus {
    box-shadow: none;
  }
  .fc-setup-button {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    width: 100px;
    height: 40px;
    border: 1px solid #1890ff;
    border-radius: 20px;
    background: #1890ff;
    font-size: 16px;
    vertical-align: middle;
  }
  .fc-setup-button:not(:disabled):active {
    border: 1px solid #1890ff;
    background: #1890ff;
  }
  .fc-setup-button:focus {
    box-shadow: none;
  }
  .fc-setup-button:not(:disabled):active:focus {
    box-shadow: none;
  }
  .fc-timeline-slot-cushion {
    font-weight: 400;
    font-size: 14px;
    color: #303133;
  }
  .fc-datagrid-cell-cushion {
    font-size: 14px;
    color: #303133;
  }
  .board-staff-label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    min-width: 0;
    gap: 8px;
  }
  .board-staff-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .board-staff-stats {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    white-space: nowrap;
  }
  .stat-pending {
    color: #377dff;
  }
  .stat-done {
    color: #23c471;
  }
  .fc-timeline-now-indicator-container {
    z-index: auto;
  }
  .fc-timeline-now-indicator-arrow {
    border-top-color: #377dff;
  }
  .fc-timeline-now-indicator-line {
    border-width: 0px 0px 0px 2px;
    border-color: #377dff;
  }
  td {
    border-color: #ededed;
  }
  .fc-scrollgrid-section-header > th:last-child {
    border-color: transparent;
  }
  .fc-scrollgrid-section-body > td:last-child {
    border-color: transparent;
  }
  th {
    border-color: #ededed;
  }
  .fc-scrollgrid {
    border-color: transparent;
  }
  .ivu-tooltip {
    width: 100%;
  }
}
/deep/.ivu-slider-marks-item {
  font-size: 13px;
  color: #303133;
}
.float-box {
  position: fixed;
  right: 44px;
  bottom: 40px;
  z-index: 9;
  display: flex;
  align-items: center;
  height: 61px;
  padding: 0 13px 0 26px;
  border-radius: 84px;
  background: #ffffff;
  box-shadow: 0px 4px 16px 0px rgba(0, 0, 0, 0.08);
  .list {
    display: flex;
    align-items: center;
    font-size: 14px;
    color: #303133;
  }
  .item {
    display: flex;
    align-items: center;
    margin-right: 18px;
  }
  .mark {
    width: 14px;
    height: 14px;
    border-radius: 2px;
    margin-right: 6px;
    background: #377dff;
    &.mark2 {
      background: #ff8d30;
    }
    &.mark3 {
      background: #23c471;
    }
    &.mark4 {
      background: #f95e45;
    }
    &.mark-confirm {
      background: #9254de;
    }
    &.mark-rest {
      background: #e8e8e8;
    }
  }
}
/deep/.board-modal {
  .ivu-modal-body {
    padding-top: 28px;
    padding-bottom: 30px;
  }
}
</style>