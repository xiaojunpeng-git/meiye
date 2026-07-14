<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Form inline @submit.native.prevent>
        <FormItem label="月份：">
          <DatePicker
            v-model="queryMonth"
            type="month"
            format="yyyy-MM"
            placeholder="选择月份"
            style="width: 160px"
            @on-change="onMonthChange"
          />
        </FormItem>
        <FormItem label="员工：">
          <Select v-model="queryStaffId" clearable placeholder="全部员工" style="width: 180px" @on-change="loadList">
            <Option v-for="item in staffList" :key="item.value" :value="item.value">{{ item.label }}</Option>
          </Select>
        </FormItem>
        <FormItem>
          <Button type="primary" @click="openModal()">添加排班/休息</Button>
        </FormItem>
      </Form>
      <Alert v-if="!scheduleManage" type="warning" show-icon class="mb16">
        总后台未启用「排班管理」，当前排班数据不会生效；预约看板按员工「可被预约」开关过滤。
      </Alert>
      <Table :columns="columns" :data="tableData" :loading="loading" no-data-text="暂无排班数据">
        <template slot-scope="{ row }" slot="schedule_type">
          <Tag :color="row.schedule_type == 1 ? 'blue' : 'default'">{{ row.schedule_type == 1 ? '上班' : '休息' }}</Tag>
        </template>
        <template slot-scope="{ row }" slot="time">
          <span v-if="row.start_time || row.end_time">{{ row.start_time || '00:00' }} - {{ row.end_time || '23:59' }}</span>
          <span v-else>{{ row.schedule_type == 0 ? '全天' : '全天' }}</span>
        </template>
        <template slot-scope="{ row }" slot="action">
          <a @click="openModal(row)">编辑</a>
          <Divider type="vertical" />
          <a @click="removeRow(row)">删除</a>
        </template>
      </Table>
    </Card>

    <Modal v-model="modal" :title="form.id ? '编辑排班' : '添加排班'" @on-cancel="closeModal">
      <Form :model="form" :label-width="90">
        <FormItem label="员工" required>
          <Select v-model="form.staff_id" placeholder="请选择员工">
            <Option v-for="item in staffList" :key="item.value" :value="item.value">{{ item.label }}</Option>
          </Select>
        </FormItem>
        <FormItem label="日期" required>
          <DatePicker v-model="form.schedule_date" type="date" format="yyyy-MM-dd" placeholder="选择日期" style="width: 100%" />
        </FormItem>
        <FormItem label="类型" required>
          <RadioGroup v-model="form.schedule_type">
            <Radio :label="1">上班</Radio>
            <Radio :label="0">休息</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem label="时段">
          <TimePicker
            v-model="timeRange"
            type="timerange"
            format="HH:mm"
            placeholder="不选则为全天"
            style="width: 100%"
            transfer
          />
          <div class="tips">休息可设置部分时段；不选时段时休息为全天</div>
        </FormItem>
        <FormItem label="备注">
          <Input v-model="form.mark" type="textarea" placeholder="选填" />
        </FormItem>
      </Form>
      <div slot="footer">
        <Button @click="closeModal">取消</Button>
        <Button type="primary" :loading="submitLoading" @click="submit">保存</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import dayjs from 'dayjs';
import { staffallInfo } from '@/api/staff';
import { getStaffScheduleList, saveStaffSchedule, deleteStaffSchedule } from '@/api/staff';

export default {
  name: 'staffSchedule',
  data() {
    return {
      loading: false,
      submitLoading: false,
      scheduleManage: 0,
      queryMonth: dayjs().format('YYYY-MM'),
      queryStaffId: 0,
      staffList: [],
      tableData: [],
      modal: false,
      timeRange: [],
      form: {
        id: 0,
        staff_id: 0,
        schedule_date: '',
        schedule_type: 1,
        start_time: '',
        end_time: '',
        mark: '',
      },
      columns: [
        { title: '日期', key: 'schedule_date', minWidth: 110 },
        { title: '员工', key: 'staff_name', minWidth: 100 },
        { title: '类型', slot: 'schedule_type', width: 90 },
        { title: '时段', slot: 'time', minWidth: 140 },
        { title: '备注', key: 'mark', minWidth: 120 },
        { title: '操作', slot: 'action', width: 120 },
      ],
    };
  },
  mounted() {
    this.loadStaff();
    this.loadList();
  },
  methods: {
    loadStaff() {
      staffallInfo().then((res) => {
        this.staffList = res.data || [];
      });
    },
    onMonthChange(val) {
      this.queryMonth = val || dayjs().format('YYYY-MM');
      this.loadList();
    },
    loadList() {
      this.loading = true;
      getStaffScheduleList({
        month: this.queryMonth,
        staff_id: this.queryStaffId || 0,
      })
        .then((res) => {
          const staffMap = {};
          this.staffList.forEach((s) => {
            staffMap[s.value] = s.label;
          });
          this.tableData = (res.data.list || []).map((row) => ({
            ...row,
            staff_name: staffMap[row.staff_id] || row.staff_id,
          }));
          this.scheduleManage = res.data.schedule_manage || 0;
          this.loading = false;
        })
        .catch((err) => {
          this.loading = false;
          this.$Message.error(err.msg);
        });
    },
    openModal(row) {
      if (row) {
        this.form = {
          id: row.id,
          staff_id: row.staff_id,
          schedule_date: row.schedule_date,
          schedule_type: row.schedule_type,
          start_time: row.start_time,
          end_time: row.end_time,
          mark: row.mark,
        };
        this.timeRange = row.start_time && row.end_time ? [row.start_time, row.end_time] : [];
      } else {
        this.form = {
          id: 0,
          staff_id: this.queryStaffId || 0,
          schedule_date: dayjs().format('YYYY-MM-DD'),
          schedule_type: 1,
          start_time: '',
          end_time: '',
          mark: '',
        };
        this.timeRange = [];
      }
      this.modal = true;
    },
    closeModal() {
      this.modal = false;
    },
    submit() {
      if (!this.form.staff_id) return this.$Message.error('请选择员工');
      if (!this.form.schedule_date) return this.$Message.error('请选择日期');
      const date = typeof this.form.schedule_date === 'string'
        ? this.form.schedule_date.split(' ')[0]
        : dayjs(this.form.schedule_date).format('YYYY-MM-DD');
      if (this.timeRange && this.timeRange.length === 2) {
        this.form.start_time = this.timeRange[0];
        this.form.end_time = this.timeRange[1];
      } else {
        this.form.start_time = '';
        this.form.end_time = '';
      }
      this.submitLoading = true;
      const payload = { ...this.form, schedule_date: date };
      delete payload.id;
      const req = this.form.id
        ? deleteStaffSchedule(this.form.id).then(() => saveStaffSchedule(payload))
        : saveStaffSchedule(payload);
      req
        .then((res) => {
          this.submitLoading = false;
          this.$Message.success(res.msg || '保存成功');
          this.modal = false;
          this.loadList();
        })
        .catch((err) => {
          this.submitLoading = false;
          this.$Message.error(err.msg);
        });
    },
    removeRow(row) {
      this.$Modal.confirm({
        title: '确认删除',
        content: '确定删除该排班记录吗？',
        onOk: () => {
          deleteStaffSchedule(row.id)
            .then((res) => {
              this.$Message.success(res.msg);
              this.loadList();
            })
            .catch((err) => {
              this.$Message.error(err.msg);
            });
        },
      });
    },
  },
};
</script>

<style scoped>
.tips {
  margin-top: 6px;
  font-size: 12px;
  color: #909399;
}
.mb16 {
  margin-bottom: 16px;
}
</style>
