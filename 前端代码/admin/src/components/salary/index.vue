<template>
  <div>
    <div>
      <Form
          ref="specsFrom"
          inline
          :label-width="labelWidth"
          :label-position="labelPosition"
          @submit.native.prevent
      >
        <Row :gutter="24" type="flex" justify="end">
          <Col span="24">
            <FormItem label="时间选择：">
              <DatePicker
                  :editable="false"
                  @on-change="onchangeTime"
                  :value="timeVal"
                  format="yyyy-MM"
                  type="month"
                  placement="bottom-end"
                  placeholder="自定义时间"
                  class="input-width"
              ></DatePicker>
              <Button class="ml-14" @click="search()" type="primary">查询</Button>
            </FormItem>
            <Button type="primary" icon="md-add" @click="add">添加</Button>
          </Col>
        </Row>
      </Form>

      <Card v-if="agentFormVisible" dis-hover class="agent-form-card">
        <p slot="title">{{ agentForm.id ? '编辑代班记录' : '新增代班记录' }}</p>
        <Form :label-width="100">
          <FormItem label="选择员工">
            <Select
                v-model="agentForm.staff_id"
                filterable
                clearable
                :loading="staffOptionsLoading"
                placeholder="请选择员工"
                style="width: 100%"
            >
              <Option
                  v-for="item in staffOptions"
                  :value="item.id"
                  :key="item.id"
              >{{ item.staff_name }}</Option>
            </Select>
          </FormItem>
          <FormItem label="代班日期">
            <DatePicker
                v-model="agentForm.date"
                type="date"
                multiple
                format="yyyy-MM-dd"
                placeholder="选择代班日期"
                style="width: 100%"
            ></DatePicker>
          </FormItem>
        </Form>
        <div class="agent-form-actions">
          <Button @click="cancelAgentForm">取消</Button>
          <Button type="primary" :loading="saveLoading" @click="saveAgentForm">保存</Button>
        </div>
      </Card>

      <Table
          :columns="columns1"
          :data="tableList"
          :loading="loading"
          highlight-row
          no-userFrom-text="暂无数据"
          no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row, index }" slot="action">
          <a @click="edit(row)">编辑</a>
          <a @click="del(row, '删除', index)" style="margin-left: 20px">删除</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
            :total="total"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="tableFrom.limit"
        />
      </div>
    </div>
  </div>
</template>

<script>
import { mapState } from "vuex";
import { agentList, saveAgent } from "@/api/salary";
import { merchantStaffList } from "@/api/setting";

export default {
  name: "salaryAgent",
  props: {
    storeId: {
      type: [Number, String],
      default: ''
    }
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? "top" : "left";
    },
  },
  data() {
    return {
      agentFormVisible: false,
      saveLoading: false,
      staffOptions: [],
      staffOptionsLoading: false,
      staffOptionsStoreId: '',
      agentForm: {
        id: 0,
        staff_id: '',
        date: []
      },
      total: 0,
      tableFrom: {
        page: 1,
        limit: 15,
        date: '',
        store_id: ''
      },
      timeVal: '',
      loading: false,
      tableList: [],
      columns1: [
        {
          title: "ID",
          key: "id",
          minWidth: 120,
        },
        {
          title: "员工",
          key: "staff_name",
          minWidth: 120,
        },
        {
          title: '代班日期',
          key: 'date',
          minWidth: 200
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          minWidth: 120
        }
      ],
    };
  },
  watch: {
    storeId(val) {
      this.tableFrom.store_id = val || '';
      if (String(val || '') !== this.staffOptionsStoreId) {
        this.staffOptions = [];
        this.staffOptionsStoreId = '';
      }
    }
  },
  methods: {
    getDate() {
      const now = new Date();
      const y = now.getFullYear();
      const m = (now.getMonth() + 1).toString().padStart(2, '0');
      this.timeVal = `${y}-${m}`;
      this.tableFrom.date = this.timeVal;
      this.tableFrom.store_id = this.storeId || '';
      this.getList();
      this.loadStaffOptions();
    },
    onchangeTime(e) {
      this.timeVal = e;
      this.tableFrom.page = 1;
      this.tableFrom.date = e;
      this.getList();
    },
    search() {
      this.tableFrom.page = 1;
      this.getList();
    },
    pageChange(page) {
      this.tableFrom.page = page;
      this.getList();
    },
    editSuccess() {
      this.getList();
      this.$emit("getList");
    },
    loadStaffOptions(force = false) {
      if (!this.storeId) {
        this.staffOptions = [];
        this.staffOptionsStoreId = '';
        return Promise.resolve();
      }
      const storeKey = String(this.storeId);
      if (!force && storeKey === this.staffOptionsStoreId && this.staffOptions.length) {
        return Promise.resolve();
      }
      this.staffOptionsLoading = true;
      return merchantStaffList({ store_id: this.storeId, limit: 1000 }).then(res => {
        const data = res.data || {};
        this.staffOptions = data.list || [];
        this.staffOptionsStoreId = storeKey;
      }).catch(err => {
        this.staffOptions = [];
        this.staffOptionsStoreId = '';
        this.$Message.error(err.msg || '加载员工列表失败');
      }).finally(() => {
        this.staffOptionsLoading = false;
      });
    },
    resetAgentForm() {
      this.agentForm = {
        id: 0,
        staff_id: '',
        date: []
      };
    },
    cancelAgentForm() {
      this.agentFormVisible = false;
      this.resetAgentForm();
    },
    add() {
      if (!this.storeId) {
        return this.$Message.warning('请先选择门店');
      }
      this.resetAgentForm();
      this.agentFormVisible = true;
      this.loadStaffOptions(true);
    },
    edit(row) {
      this.agentForm = {
        id: row.id,
        staff_id: row.staff_id ? Number(row.staff_id) : '',
        date: row.date ? String(row.date).split(',').filter(Boolean) : []
      };
      this.agentFormVisible = true;
      this.loadStaffOptions(true);
    },
    normalizeAgentDates(value) {
      if (!value) {
        return [];
      }
      if (Array.isArray(value)) {
        return value.filter(Boolean);
      }
      if (typeof value === 'string') {
        return value.split(',').map(item => item.trim()).filter(Boolean);
      }
      return [value];
    },
    formatAgentDate(value) {
      if (!value) {
        return '';
      }
      if (typeof value === 'string') {
        return value.slice(0, 10);
      }
      const date = value instanceof Date ? value : new Date(value);
      if (Number.isNaN(date.getTime())) {
        return '';
      }
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      return `${y}-${m}-${d}`;
    },
    saveAgentForm() {
      if (!this.storeId) {
        return this.$Message.warning('请先选择门店');
      }
      if (!this.agentForm.staff_id) {
        return this.$Message.warning('请选择员工');
      }
      const dateList = this.normalizeAgentDates(this.agentForm.date)
          .map(item => this.formatAgentDate(item))
          .filter(Boolean);
      if (!dateList.length) {
        return this.$Message.warning('请选择代班日期');
      }
      const id = this.agentForm.id || 0;
      this.saveLoading = true;
      saveAgent(id, {
        staff_id: this.agentForm.staff_id,
        date: dateList
      }).then(res => {
        this.$Message.success(res.msg || '保存成功');
        this.cancelAgentForm();
        this.editSuccess();
      }).catch(err => {
        this.$Message.error(err.msg || '保存失败');
      }).finally(() => {
        this.saveLoading = false;
      });
    },
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `report/delAgent/` + row.id,
        method: "PUT",
        ids: "",
      };
      this.$modalSure(delfromData)
          .then((res) => {
            this.$Message.success(res.msg);
            this.tableList.splice(num, 1);
            if (!this.tableList.length) {
              this.tableFrom.page = this.tableFrom.page == 1 ? 1 : this.tableFrom.page - 1;
            }
            this.getList();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
    },
    getList() {
      this.loading = true;
      this.tableFrom.store_id = this.storeId || '';
      agentList(this.tableFrom)
          .then(async (res) => {
            let data = res.data;
            this.tableList = data.list;
            this.total = data.count;
            this.loading = false;
          }).catch((res) => {
        this.loading = false;
        this.$Message.error(res.msg);
      });
    }
  },
};
</script>

<style scoped lang="stylus">
.input-add {
  width: 250px;
  margin-right:14px;
}
.agent-form-card {
  margin-bottom: 16px;
}
.agent-form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}
/deep/.ivu-table {
  height: 300px;
  overflow-x: hidden;
  overflow-y: auto;
}

/deep/.ivu-table-overflowX {
  overflow-x: hidden !important;
}
</style>
