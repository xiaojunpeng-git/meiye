<template>
  <div>
    <Card :bordered="false" dis-hover>
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
              <template v-for="field in searchFields">
                <FormItem :key="'sf-' + field.key" :label="field.name + '：'">
                  <DatePicker
                      v-if="field.input_type == 6"
                      :editable="false"
                      @on-change="(e) => onSearchDateChange(e, field)"
                      :value="getSearchDateValue(field)"
                      format="yyyy/MM/dd"
                      type="daterange"
                      placement="bottom-end"
                      placeholder="选择时间"
                      class="input-width"
                      :options="options"
                  ></DatePicker>
                  <DatePicker
                      v-else-if="field.input_type == 3"
                      :editable="false"
                      @on-change="(e) => onSearchSingleDateChange(e, field)"
                      :value="tableFrom[field.key]"
                      format="yyyy-MM-dd"
                      type="date"
                      placement="bottom-end"
                      placeholder="选择日期"
                      class="input-width"
                  ></DatePicker>
                  <Select
                      v-else-if="field.input_type == 4"
                      clearable
                      v-model="tableFrom[field.key]"
                      @on-change="search"
                      class="input-add"
                  >
                    <Option v-for="opt in field.input_info" :value="opt" :key="opt">{{ opt }}</Option>
                  </Select>
                  <Input
                      v-else-if="field.input_type == 1"
                      v-model="tableFrom[field.key]"
                      clearable
                      class="input-add"
                      :placeholder="'请输入' + field.name"
                  />
                </FormItem>
              </template>
              <FormItem>
                <Button type="primary" @click="search">查询</Button>
                <Button type="primary" :loading="exportLoading" @click="exports" style="margin-left: 12px">导出</Button>
                <span v-if="tableType === 4" style="display: inline-block; margin-left: 12px">
                  <Button type="primary" @click="doAdd">添加数据</Button>
                  <Button type="primary" @click="doEdit(1)" style="margin-left: 12px" v-if="isEdit == 0">编辑</Button>
                  <Button type="primary" @click="doEdit(0)" style="margin-left: 12px" v-else>取消编辑</Button>
                </span>
              </FormItem>
            </Col>
          </Row>
          <div style="margin-bottom: 20px;display: flex;gap: 50px">
            <div v-for="(item,index) in moneyCount" :key="index">
              {{ item.name }}:{{ item.count }}
            </div>
          </div>
        </Form>
      </div>
      <Table
          @on-sort-change="sortChanged"
          :columns="columns1"
          :data="tableList"
          :loading="loading"
          highlight-row
          no-userFrom-text="暂无数据"
          no-filtered-userFrom-text="暂无筛选结果"
      >
        <template v-for="(item,index) in columns1" slot-scope="{ row, index }" :slot="item.slot">
          <div v-if="isEdit">
            <Input
                v-if="item.input_type == 1"
                type="text"
                :placeholder="'请输入'+item.title"
                v-model="row[item.slot]"
                @input="changeGoal($event,row,item)"
            ></Input>
            <DatePicker
                v-if="item.input_type == 3"
                :editable="false"
                @on-change="changeGoal($event,row,item,true)"
                :value="row[item.slot]"
                format="yyyy-MM-dd"
                type="date"
                placement="bottom-end"
                placeholder="自定义时间"
                class="input-width"
            ></DatePicker>
            <Select
                v-if="item.input_type == 4"
                clearable
                v-model="row[item.slot]"
                @on-change="changeGoal($event,row,item)"
                class="input-add"
            >
              <Option
                  v-for="opt in item.input_info"
                  :value="opt"
                  :key="opt"
              >{{ opt }}</Option>
            </Select>
          </div>
          <span v-else>{{ row[item.slot] }}</span>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a @click="del(row, '删除数据', index)" style="margin-left: 20px">删除</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
            :total="total"
            :current="tableFrom.page"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="tableFrom.limit"
        />
      </div>
    </Card>
  </div>
</template>

<script>
import { mapState } from "vuex";
import exportExcel from "@/utils/newToExcel.js";
import { selfList, selfColumn, selfCount, selfSearch, setTableSalary, addTableSalary } from "@/api/salary_table";
import timeOptions from "@/utils/timeOptions";

export default {
  name: "index",
  components: {},
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
      tableType: 0,
      exportLoading: false,
      isEdit: 0,
      options: timeOptions,
      total: 0,
      tableFrom: {
        page: 1,
        limit: 10,
        store_id: "",
        date: "",
        table_ids: 0,
      },
      moneyCount: [],
      timeVal: [],
      searchFields: [],
      searchDateVals: {},
      loading: false,
      tableList: [],
      columns1: [],
    };
  },
  methods: {
    changeGoal(e, row, item, doSave = false) {
      const data = {
        id: row.id,
        key: item.slot,
        value: e,
        table_ids: this.tableFrom.table_ids,
      };
      setTableSalary(data)
        .then(() => {
          if (doSave) {
            row[item.slot] = e;
          }
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    doAdd() {
      addTableSalary({ table_ids: this.tableFrom.table_ids })
        .then(() => {
          this.isEdit = true;
          this.search();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    del(row, tit, num) {
      const delfromData = {
        title: tit,
        num: num,
        url: `report/reportTable/del/` + row.id,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.search();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    sortChanged(e) {
      this.tableList = [];
      const tableFrom = {
        page: 1,
        limit: 10,
        table_ids: this.tableFrom.table_ids,
      };
      this.searchFields.forEach((f) => {
        tableFrom[f.key] = this.tableFrom[f.key];
      });
      this.tableFrom = tableFrom;
      this.tableFrom[e.key] = e.order;
      this.getList();
    },
    async begin() {
      await this.loadSearchFields();
      this.getColumn();
      this.initSearchDates();
      this.getList();
      this.getSelfCount();
    },
    loadSearchFields() {
      return selfSearch({ table_ids: this.tableFrom.table_ids })
        .then((res) => {
          const data = res.data || {};
          this.searchFields = data.fields || [];
          this.searchFields.forEach((f) => {
            if (this.tableFrom[f.key] === undefined) {
              this.$set(this.tableFrom, f.key, "");
            }
            if (Number(f.input_type) === 4 && !f.input_info) {
              f.input_info = f.info ? String(f.info).split(",") : [];
            }
          });
        })
        .catch(() => {
          this.searchFields = [
            { key: "date", name: "时间选择", input_type: 6, input_info: [] },
          ];
        });
    },
    getSearchDateValue(field) {
      if (field.key === "date") {
        return this.timeVal;
      }
      return this.searchDateVals[field.key] || [];
    },
    onSearchDateChange(e, field) {
      const val = e && e[0] ? e.join("-") : "";
      this.$set(this.tableFrom, field.key, val);
      if (field.key === "date") {
        this.timeVal = e || [];
      } else {
        this.$set(this.searchDateVals, field.key, e || []);
      }
      this.search();
    },
    onSearchSingleDateChange(e, field) {
      this.$set(this.tableFrom, field.key, e || "");
      this.search();
    },
    initSearchDates() {
      const hasRange = this.searchFields.some((f) => Number(f.input_type) === 6);
      if (!hasRange) {
        return;
      }
      const now = new Date();
      const y = now.getFullYear();
      const m = (now.getMonth() + 1).toString().padStart(2, "0");
      const d = now.getDate().toString().padStart(2, "0");
      const range = [`${y}/${m}/01`, `${y}/${m}/${d}`];
      this.searchFields.forEach((f) => {
        if (Number(f.input_type) !== 6) {
          return;
        }
        if (f.key === "date") {
          this.timeVal = range;
          this.tableFrom.date = range.join("-");
        } else if (!this.tableFrom[f.key]) {
          this.$set(this.searchDateVals, f.key, range);
          this.$set(this.tableFrom, f.key, range.join("-"));
        }
      });
    },
    async exports() {
      this.exportLoading = true;
      try {
        let th = [];
        let filekey = [];
        let data = [];
        let fileName = "";
        const excelData = JSON.parse(JSON.stringify(this.tableFrom));
        excelData.page = 1;
        excelData.is_excel = 1;
        for (let i = 0; i < excelData.page + 1; i++) {
          const lebData = await this.getExcelData(excelData);
          if (!fileName) fileName = lebData.filename;
          if (!filekey.length) filekey = lebData.filekey;
          if (!th.length) th = lebData.header;
          if (lebData.export.length) {
            data = data.concat(lebData.export);
            excelData.page++;
          } else {
            exportExcel(th, filekey, fileName, data);
            return;
          }
        }
      } catch (err) {
        this.$message.error("导出失败：" + (err.message || "网络异常"));
      } finally {
        this.exportLoading = false;
      }
    },
    getExcelData(excelData) {
      return new Promise((resolve) => {
        selfList(excelData).then((res) => resolve(res.data));
      });
    },
    doEdit(type) {
      this.isEdit = type;
    },
    getColumn() {
      const params = { table_ids: this.tableFrom.table_ids };
      if (this.tableFrom.date) {
        params.date = this.tableFrom.date;
      }
      selfColumn(params)
        .then((res) => {
          this.columns1 = res.data;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    search() {
      this.tableList = [];
      this.tableFrom.page = 1;
      this.getList();
      this.getSelfCount();
    },
    pageChange(page) {
      this.tableFrom.page = page;
      this.getList();
    },
    getSelfCount() {
      this.moneyCount = [];
      selfCount(this.tableFrom).then((res) => {
        this.moneyCount = res.data;
      });
    },
    getList() {
      this.loading = true;
      selfList(this.tableFrom)
        .then((res) => {
          const data = res.data;
          this.tableList = data.list;
          this.total = data.count;
          this.tableType = data.table_type;
          this.loading = false;
        })
        .catch((res) => {
          this.loading = false;
          this.$Message.error(res.msg);
        });
    },
  },
};
</script>

<style scoped lang="stylus">
/deep/.ivu-table-fixed-shadow{
  height: auto !important;
}
</style>
