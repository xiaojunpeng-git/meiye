<template>
  <div class="schedule-page">
    <div class="page-card">
      <div class="page-tabs">
        <div class="tab-item" :class="{ active: activeTab === 'schedule' }" @click="activeTab = 'schedule'">排班管理</div>
        <div class="tab-item" :class="{ active: activeTab === 'shift' }" @click="switchToShift">班次管理</div>
      </div>

      <!-- 排班管理 -->
      <div v-show="activeTab === 'schedule'" class="tab-panel">
        <div class="toolbar">
          <div class="toolbar-left">
            <Button type="primary" class="btn-purple" :loading="exportLoading" @click="exportSchedule">导出数据</Button>
            <Select v-model="query.position_id" clearable placeholder="全部职位" class="tool-select" @on-change="loadCalendar">
              <Option v-for="item in positionOptions" :key="item.value" :value="item.value">{{ item.label }}</Option>
            </Select>
            <DatePicker v-model="queryMonth" type="month" format="yyyy-MM" placeholder="选择月份" class="month-picker" @on-change="onMonthChange" />
          </div>
          <div class="toolbar-right">
            <Input v-model="query.keyword" placeholder="请输入系统账号、姓名" class="search-input" @on-enter="loadCalendar" />
            <Button type="primary" @click="loadCalendar">搜索</Button>
          </div>
        </div>
        <Alert v-if="!scheduleManage" type="warning" show-icon class="mt16">
          总后台未启用「排班管理」，排班数据不会参与预约看板；请在系统配置中开启。
        </Alert>
        <div class="calendar-grid mt16">
          <div class="calendar-week-head">
            <div v-for="w in weekLabels" :key="w" class="week-cell">{{ w }}</div>
          </div>
          <div class="calendar-week-row" v-for="(week, wi) in calendarWeeks" :key="wi">
            <div
              v-for="cell in week"
              :key="cell.key"
              class="day-cell"
              :class="{ 'other-month': !cell.inMonth, 'is-today': cell.isToday, 'has-staff': cell.dayData && cell.dayData.count }"
              @click="cell.inMonth && openDayModal(cell.date)"
            >
              <div class="day-num">{{ cell.day }}</div>
              <template v-if="cell.inMonth && cell.dayData && cell.dayData.count">
                <div class="day-count">{{ cell.dayData.count }}人</div>
                <div class="day-names line2">{{ cell.dayData.staff_names_text }}</div>
              </template>
              <div v-else-if="cell.inMonth" class="day-empty">点击添加人员排班</div>
            </div>
          </div>
        </div>
      </div>

      <!-- 班次管理 -->
      <div v-show="activeTab === 'shift'" class="tab-panel">
        <div class="toolbar">
          <Button type="primary" class="btn-purple" @click="openShiftModal()">+ 添加班次</Button>
        </div>
        <Table class="mt16" :columns="shiftColumns" :data="shiftList" :loading="shiftLoading" no-data-text="暂无班次">
          <template slot-scope="{ row }" slot="time">
            {{ row.start_time }} - {{ row.end_time }}
          </template>
          <template slot-scope="{ row }" slot="usage">
            {{ row.usage_count || 0 }}人
          </template>
          <template slot-scope="{ row }" slot="action">
            <a class="link-purple" @click="openShiftModal(row)">编辑</a>
          </template>
        </Table>
      </div>
    </div>

    <!-- 添加排班员工 -->
    <Modal
      v-model="dayModal"
      title="添加排班员工"
      width="720"
      :mask-closable="false"
      class-name="schedule-day-modal"
      @on-cancel="closeDayModal"
    >
      <div class="day-modal-body">
        <div class="day-modal-date">排班日期：{{ dayModalDateText }}</div>
        <div v-if="!dayShifts.length" class="empty-shift-tip">请先在「班次管理」中添加班次</div>
        <div v-for="(block, index) in dayShifts" :key="block.shift_id" class="shift-block">
          <div class="shift-block-head">
            <span class="shift-title">{{ block.shift_name }}</span>
            <span class="shift-time">{{ block.start_time }} - {{ block.end_time }}</span>
          </div>
          <Button type="dashed" long class="btn-add-staff" @click="openStaffPicker(index)">+ 添加员工</Button>
          <div v-if="block.staff && block.staff.length" class="staff-tags">
            <span
              v-for="(st, si) in block.staff"
              :key="st.staff_id"
              class="staff-tag"
            >
              <span class="staff-tag-name">{{ st.staff_name }}</span>
              <Icon type="ios-close" class="staff-tag-close" @click="removeDayStaff(index, si)" />
            </span>
          </div>
        </div>
      </div>
      <div slot="footer" class="day-modal-footer">
        <Button class="btn-cancel" @click="closeDayModal">取消</Button>
        <Button type="primary" class="btn-purple btn-confirm" :loading="daySaving" @click="saveDaySchedule">确定</Button>
      </div>
    </Modal>

    <!-- 选择员工 -->
    <Modal v-model="staffPickerVisible" title="选择员工" width="560" @on-ok="confirmStaffPicker">
      <Input v-model="staffPickerKeyword" placeholder="搜索姓名/账号/手机号" search @on-search="loadSelectableStaff" />
      <div class="staff-picker-list">
        <div
          v-for="item in selectableStaff"
          :key="item.id"
          class="staff-picker-item"
          :class="{ active: staffPickerSelected.includes(item.id) }"
          @click="toggleStaffPick(item.id)"
        >
          <span>{{ item.staff_name }}</span>
          <span class="sub">{{ item.position_label }}</span>
        </div>
        <div v-if="!selectableStaff.length" class="picker-empty">暂无员工</div>
      </div>
    </Modal>

    <!-- 添加/编辑班次 -->
    <Modal v-model="shiftModal" :title="shiftForm.id ? '编辑班次' : '添加班次'" width="520" @on-cancel="closeShiftModal">
      <Form :label-width="90">
        <FormItem label="班次名称" required>
          <Input v-model="shiftForm.name" placeholder="请输入班次名称" />
        </FormItem>
        <FormItem label="班次时间" required>
          <div class="acea-row row-middle">
            <TimePicker v-model="shiftForm.start_time" format="HH:mm" placeholder="开始" style="width: 120px" />
            <span class="time-sep">-</span>
            <TimePicker v-model="shiftForm.end_time" format="HH:mm" placeholder="结束" style="width: 120px" />
          </div>
        </FormItem>
      </Form>
      <div slot="footer">
        <Button v-if="shiftForm.id" @click="removeShift">删除班次</Button>
        <Button @click="closeShiftModal">取消</Button>
        <Button type="primary" class="btn-purple" :loading="shiftSaving" @click="submitShift">保存</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import dayjs from 'dayjs';
import exportExcel from '@/utils/newToExcel';
import {
  getScheduleCalendar,
  getScheduleExportList,
  getScheduleDayDetail,
  saveScheduleDay,
  getSelectableScheduleStaff,
  getShiftList,
  saveShift,
  deleteShift,
} from '@/api/schedule';

export default {
  name: 'cashierSchedule',
  data() {
    return {
      activeTab: 'schedule',
      weekLabels: ['周日', '周一', '周二', '周三', '周四', '周五', '周六'],
      queryMonth: dayjs().format('YYYY-MM'),
      query: {
        keyword: '',
        position_id: 0,
      },
      calendarDays: {},
      scheduleManage: 1,
      shiftList: [],
      shiftLoading: false,
      shiftColumns: [
        { title: '班次名称', key: 'name', minWidth: 120 },
        { title: '班次时间', slot: 'time', minWidth: 160 },
        { title: '使用人数', slot: 'usage', width: 100 },
        { title: '操作', slot: 'action', width: 80 },
      ],
      dayModal: false,
      dayModalDate: '',
      dayShifts: [],
      daySaving: false,
      staffPickerVisible: false,
      staffPickerKeyword: '',
      staffPickerIndex: -1,
      staffPickerSelected: [],
      selectableStaff: [],
      positionOptions: [],
      shiftModal: false,
      shiftSaving: false,
      exportLoading: false,
      shiftForm: {
        id: 0,
        name: '',
        start_time: '10:00',
        end_time: '22:00',
      },
    };
  },
  computed: {
    dayModalDateText() {
      if (!this.dayModalDate) return '';
      const d = dayjs(this.dayModalDate);
      return `${d.format('YYYY年MM月DD日')}`;
    },
    calendarWeeks() {
      const month = dayjs(this.queryMonth + '-01');
      const start = month.startOf('month').startOf('week');
      const weeks = [];
      let cursor = start;
      for (let w = 0; w < 6; w++) {
        const week = [];
        for (let d = 0; d < 7; d++) {
          const date = cursor.format('YYYY-MM-DD');
          week.push({
            key: date,
            date,
            day: cursor.date(),
            inMonth: cursor.month() === month.month(),
            isToday: date === dayjs().format('YYYY-MM-DD'),
            dayData: this.calendarDays[date] || null,
          });
          cursor = cursor.add(1, 'day');
        }
        weeks.push(week);
      }
      return weeks;
    },
  },
  mounted() {
    this.loadCalendar();
    this.loadShiftList();
  },
  methods: {
    switchToShift() {
      this.activeTab = 'shift';
      this.loadShiftList();
    },
    onMonthChange(val) {
      this.queryMonth = val || dayjs().format('YYYY-MM');
      this.loadCalendar();
    },
    loadCalendar() {
      getScheduleCalendar({
        month: this.queryMonth,
        keyword: this.query.keyword,
        position_id: this.query.position_id || 0,
      }).then((res) => {
        this.calendarDays = res.data.days || {};
        this.scheduleManage = res.data.schedule_manage != null ? res.data.schedule_manage : 1;
        this.buildPositionOptions();
      }).catch((err) => {
        this.$Message.error(err.msg || '加载排班失败');
      });
    },
    buildPositionOptions() {
      getSelectableScheduleStaff({ keyword: '' }).then((res) => {
        const map = {};
        (res.data || []).forEach((item) => {
          const pid = Number(item.position) || 0;
          if (pid && item.position_label) {
            map[pid] = item.position_label;
          }
        });
        this.positionOptions = Object.keys(map).map((k) => ({ value: Number(k), label: map[k] }));
      });
    },
    loadShiftList() {
      this.shiftLoading = true;
      getShiftList().then((res) => {
        this.shiftList = res.data || [];
        this.shiftLoading = false;
      }).catch((err) => {
        this.shiftLoading = false;
        this.$Message.error(err.msg || '加载班次失败');
      });
    },
    openDayModal(date) {
      this.dayModalDate = date;
      getScheduleDayDetail({ date }).then((res) => {
        this.dayShifts = (res.data.shifts || []).map((row) => ({
          shift_id: row.shift_id,
          shift_name: row.shift_name,
          start_time: row.start_time,
          end_time: row.end_time,
          staff: Array.isArray(row.staff) ? row.staff.slice() : [],
        }));
        this.dayModal = true;
      }).catch((err) => {
        this.$Message.error(err.msg || '加载失败');
      });
    },
    closeDayModal() {
      this.dayModal = false;
    },
    removeDayStaff(shiftIndex, staffIndex) {
      this.dayShifts[shiftIndex].staff.splice(staffIndex, 1);
    },
    openStaffPicker(shiftIndex) {
      this.staffPickerIndex = shiftIndex;
      this.staffPickerSelected = (this.dayShifts[shiftIndex].staff || []).map((s) => s.staff_id);
      this.staffPickerKeyword = '';
      this.loadSelectableStaff();
      this.staffPickerVisible = true;
    },
    loadSelectableStaff() {
      getSelectableScheduleStaff({
        keyword: this.staffPickerKeyword,
        position_id: this.query.position_id || 0,
      }).then((res) => {
        this.selectableStaff = res.data || [];
      });
    },
    toggleStaffPick(id) {
      const idx = this.staffPickerSelected.indexOf(id);
      if (idx > -1) {
        this.staffPickerSelected.splice(idx, 1);
      } else {
        this.staffPickerSelected.push(id);
      }
    },
    confirmStaffPicker() {
      if (this.staffPickerIndex < 0) return;
      const block = this.dayShifts[this.staffPickerIndex];
      const map = {};
      (block.staff || []).forEach((s) => { map[s.staff_id] = s; });
      this.staffPickerSelected.forEach((id) => {
        if (!map[id]) {
          const row = this.selectableStaff.find((s) => s.id === id);
          if (row) {
            map[id] = { staff_id: row.id, staff_name: row.staff_name };
          }
        }
      });
      block.staff = Object.values(map).filter((s) => this.staffPickerSelected.includes(s.staff_id));
      this.staffPickerVisible = false;
    },
    saveDaySchedule() {
      if (!this.dayModalDate) return;
      this.daySaving = true;
      const shifts = this.dayShifts.map((block) => ({
        shift_id: block.shift_id,
        staff_ids: (block.staff || []).map((s) => s.staff_id),
      }));
      saveScheduleDay({
        schedule_date: this.dayModalDate,
        shifts,
      }).then((res) => {
        this.daySaving = false;
        this.$Message.success(res.msg || '保存成功');
        this.dayModal = false;
        this.loadCalendar();
      }).catch((err) => {
        this.daySaving = false;
        this.$Message.error(err.msg || '保存失败');
      });
    },
    openShiftModal(row) {
      if (row) {
        this.shiftForm = {
          id: row.id,
          name: row.name,
          start_time: row.start_time,
          end_time: row.end_time,
        };
      } else {
        this.shiftForm = { id: 0, name: '', start_time: '10:00', end_time: '22:00' };
      }
      this.shiftModal = true;
    },
    closeShiftModal() {
      this.shiftModal = false;
    },
    submitShift() {
      if (!this.shiftForm.name) return this.$Message.warning('请输入班次名称');
      const payload = {
        ...this.shiftForm,
        start_time: this.normalizeTime(this.shiftForm.start_time),
        end_time: this.normalizeTime(this.shiftForm.end_time),
      };
      if (!payload.start_time || !payload.end_time) {
        return this.$Message.warning('请选择班次时间');
      }
      this.shiftSaving = true;
      saveShift(payload).then((res) => {
        this.shiftSaving = false;
        this.$Message.success(res.msg || '保存成功');
        this.shiftModal = false;
        this.loadShiftList();
      }).catch((err) => {
        this.shiftSaving = false;
        this.$Message.error(err.msg || '保存失败');
      });
    },
    removeShift() {
      if (!this.shiftForm.id) return;
      this.$Modal.confirm({
        title: '确认删除',
        content: '确定删除该班次吗？',
        onOk: () => deleteShift(this.shiftForm.id).then((res) => {
          this.$Message.success(res.msg || '已删除');
          this.shiftModal = false;
          this.loadShiftList();
        }).catch((err) => {
          this.$Message.error(err.msg || '删除失败');
        }),
      });
    },
    exportSchedule() {
      this.exportLoading = true;
      getScheduleExportList({
        month: this.queryMonth,
        keyword: this.query.keyword,
        position_id: this.query.position_id || 0,
      }).then((res) => {
        const list = (res.data && res.data.list) || [];
        if (!list.length) {
          this.$Message.warning('当前筛选条件下暂无排班数据');
          return;
        }
        const header = ['排班日期', '星期', '员工姓名', '手机号', '系统账号', '职位', '班次名称', '班次时间'];
        const filterVal = ['schedule_date', 'week_day', 'staff_name', 'phone', 'account', 'position_label', 'shift_name', 'shift_time'];
        const fileName = `排班数据_${this.queryMonth}`;
        exportExcel(header, filterVal, fileName, list);
        this.$Message.success('导出成功');
      }).catch((err) => {
        this.$Message.error(err.msg || '导出失败');
      }).finally(() => {
        this.exportLoading = false;
      });
    },
    normalizeTime(val) {
      if (!val) return '';
      if (typeof val === 'string') return val.length >= 5 ? val.substring(0, 5) : val;
      const d = val instanceof Date ? val : new Date(val);
      if (Number.isNaN(d.getTime())) return '';
      const h = String(d.getHours()).padStart(2, '0');
      const m = String(d.getMinutes()).padStart(2, '0');
      return `${h}:${m}`;
    },
  },
};
</script>

<style lang="stylus" scoped>
.schedule-page
  padding 20px
  min-height 100%
  background #f5f5f5
.page-card
  background #fff
  border-radius 12px
  padding 24px
.page-tabs
  display flex
  border-bottom 1px solid #eee
  margin-bottom 20px
.tab-item
  padding 12px 24px
  cursor pointer
  font-size 16px
  color #666
  margin-bottom -1px
  &.active
    color #7c3aed
    font-weight 600
    border-bottom 2px solid #7c3aed
.btn-purple
  background #7c3aed !important
  border-color #7c3aed !important
.link-purple
  color #7c3aed
.toolbar
  display flex
  align-items center
  justify-content space-between
  flex-wrap wrap
  gap 16px 32px
.toolbar-left,
.toolbar-right
  display flex
  align-items center
  flex-wrap wrap
.toolbar-left
  gap 16px
.toolbar-right
  gap 12px
  margin-left auto
.tool-select
  width 160px
.month-picker
  width 160px
.search-input
  width 240px
.calendar-week-head, .calendar-week-row
  display grid
  grid-template-columns repeat(7, 1fr)
.week-cell
  text-align center
  padding 10px
  background #fafafa
  font-weight 600
  color #666
.day-cell
  min-height 100px
  border 1px solid #eee
  padding 8px
  cursor pointer
  transition background .2s
  &:hover
    background #faf5ff
  &.other-month
    background #fafafa
    color #ccc
    cursor default
  &.is-today .day-num
    color #7c3aed
    font-weight 700
  &.has-staff
    background #f9f5ff
.day-num
  font-size 14px
  font-weight 600
.day-count
  font-size 12px
  color #7c3aed
  margin-top 4px
.day-names
  font-size 12px
  color #666
  margin-top 4px
  line-height 1.4
.day-empty
  font-size 12px
  color #7c3aed
  margin-top 12px
.day-modal-date
  margin-bottom 20px
  color #333
  font-size 14px
.day-modal-body
  padding 0 4px
.shift-block
  margin-bottom 24px
  &:last-child
    margin-bottom 0
.shift-block-head
  display flex
  align-items center
  padding 10px 16px
  background #f5f5f5
  border-radius 4px
  margin-bottom 12px
.shift-title
  font-weight 600
  font-size 14px
  color #333
.shift-time
  margin-left 8px
  color #666
  font-size 14px
.btn-add-staff
  height 36px
  border-color #dcdfe6 !important
  color #666 !important
  font-size 14px
  &:hover
    border-color #7c3aed !important
    color #7c3aed !important
.staff-tags
  display flex
  flex-wrap wrap
  gap 10px
  margin-top 12px
.staff-tag
  display inline-flex
  align-items center
  height 32px
  padding 0 10px 0 14px
  background #f0f0f0
  border-radius 4px
  font-size 14px
  color #333
.staff-tag-name
  line-height 1
.staff-tag-close
  margin-left 6px
  font-size 18px
  color #999
  cursor pointer
  &:hover
    color #666
.staff-picker-list
  max-height 360px
  overflow-y auto
  margin-top 12px
.staff-picker-item
  padding 10px 12px
  border 1px solid #eee
  border-radius 6px
  margin-bottom 8px
  cursor pointer
  display flex
  justify-content space-between
  &.active
    border-color #7c3aed
    background #faf5ff
  .sub
    color #999
    font-size 12px
.picker-empty, .empty-shift-tip
  text-align center
  color #999
  padding 24px
.time-sep
  margin 0 8px
</style>

<style lang="stylus">
.schedule-day-modal
  .ivu-modal-header
    padding 16px 24px
    border-bottom 1px solid #eee
  .ivu-modal-header-inner
    font-size 16px
    font-weight 600
    color #333
  .ivu-modal-body
    padding 20px 24px 8px
  .ivu-modal-footer
    padding 16px 24px
    border-top 1px solid #eee
  .day-modal-footer
    display flex
    justify-content flex-end
    gap 12px
  .btn-cancel
    min-width 88px
    height 36px
    background #fff !important
    border-color #dcdfe6 !important
    color #333 !important
  .btn-confirm
    min-width 88px
    height 36px
</style>
