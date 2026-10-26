<template>
  <!-- 用户-客服管理-客服列表 -->
  <div>
    <Card :bordered="false" dis-hover>
      <div>
        <!-- 筛选条件 -->
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
                  <Select
                      v-if="field.input_type == 5"
                      clearable
                      v-model="tableFrom[field.key]"
                      @on-change="search"
                      class="input-add"
                  >
                    <Option v-for="item in storeList" :value="item.id" :key="item.id">{{ item.name }}</Option>
                  </Select>
                  <DatePicker
                      v-else-if="field.input_type == 6"
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
                      v-else
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
                <Button v-if="tableType === 5" @click="openSearchConfig" style="margin-left: 12px">搜索配置</Button>
                <span v-if="tableType === 4" style="display: inline-block; margin-left: 12px">
                  <Button type="primary" @click="doAdd">添加数据</Button>
                  <Button type="primary" @click="doEdit(1)" style="margin-left: 12px" v-if="isEdit == 0">编辑</Button>
                  <Button type="primary" @click="doEdit(0)" style="margin-left: 12px" v-else>取消编辑</Button>
                </span>
              </FormItem>
            </Col>
          </Row>
          <div style="margin-bottom: 20px;display: flex;gap: 50px">
                <div v-for="(item,index) in moneyCount">
                    {{ item.name }}:{{ item.count }}
                </div>
          </div>
        </Form>
      </div>
      <!-- 添加客服 -->
      <!-- 客服列表表格 -->
      <Table
          @on-sort-change="sortChanged"
          :columns="columns1"
          :data="tableList"
          :loading="loading"
          highlight-row
          no-userFrom-text="暂无数据"
          no-filtered-userFrom-text="暂无筛选结果"
      >
        <template v-for="(item,index) in columns1" slot-scope="{ row, index }"  :slot="item.slot">
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
                  v-for="item in item.input_info"
                  :value="item"
                  :key="item"
              >{{ item }}</Option
              >
            </Select>
          </div>
          <span v-else>
               {{ row[item.slot] }}
          </span>
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
    <Modal v-model="searchConfigModal" title="额外搜索项（门店与时间固定显示）" width="720" @on-visible-change="onSearchConfigVisible">
      <Button type="primary" size="small" @click="addSearchFieldRow" style="margin-bottom: 12px">新增搜索项</Button>
      <Table :key="searchConfigTableKey" :columns="searchConfigColumns" :data="searchConfigList" border size="small">
        <template slot-scope="{ row, index }" slot="action">
          <a @click="editSearchField(row)">编辑</a>
          <Divider type="vertical" />
          <a style="color:#ed4014" @click="removeSearchField(row, index)">删除</a>
        </template>
      </Table>
      <div slot="footer">
        <Button @click="searchConfigModal = false">关闭</Button>
      </div>
    </Modal>
    <Modal v-model="searchFieldFormModal" :title="searchFieldForm.id ? '编辑搜索项' : '新增搜索项'" width="480">
      <Form :label-width="100">
        <FormItem label="参数名 key">
          <Input v-model="searchFieldForm.key" placeholder="与SQL占位符一致" />
        </FormItem>
        <FormItem label="显示名称">
          <Input v-model="searchFieldForm.name" />
        </FormItem>
        <FormItem label="控件类型">
          <Select v-model="searchFieldForm.input_type">
            <Option :value="1">文本</Option>
            <Option :value="3">单日</Option>
            <Option :value="4">下拉</Option>
          </Select>
          <div style="color:#999;font-size:12px;margin-top:6px">门店、时间区间已固定显示，无需在此配置</div>
        </FormItem>
        <FormItem label="下拉选项" v-if="searchFieldForm.input_type == 4">
          <Input v-model="searchFieldForm.info" placeholder="逗号分隔" />
        </FormItem>
        <FormItem label="排序">
          <InputNumber v-model="searchFieldForm.sort" :min="0" style="width: 100%" />
        </FormItem>
      </Form>
      <div slot="footer">
        <Button @click="searchFieldFormModal = false">取消</Button>
        <Button type="primary" @click="saveSearchFieldForm">保存</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import { mapState } from "vuex";
import util from "@/libs/util";
import Setting from "@/setting";
import exportExcel from "@/utils/newToExcel.js";
import { selfList, selfColumn, selfCount, selfSearch, searchFieldList, searchFieldSave, searchFieldDelete, setTableSalary, addTableSalary } from "@/api/salary_table";
import { staffListInfo } from '@/api/store';
import timeOptions from "@/utils/timeOptions";
export default {
  name: "index",
  components:{

  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    ...mapState("admin/userLevel", ["categoryId"]),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? "top" : "left";
    },
  },
  data() {
    return {
      exportLoading:false,
      freeze:0,
      isEdit:0,
      tip:false,
      storeList:[],
      options: timeOptions,
      isChat: true,
      formValidate3: {
        page: 1,
        limit: 10,
      },
      total3: 0,
      loading3: false,
      modals3: false,
      tableList3: [],
      formValidate5: {
        page: 1,
        limit: 10,
        uid: 0,
        to_uid: 0,
        id: 0,
      },
      total5: 0,
      loading5: false,
      tableList5: [],
      FromData: null,
      formValidate: {
        page: 1,
        limit: 10,
      },
      tableList2: [],
      modals: false,
      total: 0,
      tableFrom: {
        page: 1,
        limit: 10,
        store_id:'',
        date:'',
        table_ids:0
      },
      moneyCount:[],
      timeVal: [],
      loading: false,
      tableList: [],
      columns1: [

      ],
      loading2: false,
      total2: 0,
      addFrom: {
        uids: [],
      },
      selections: [],
      rows: {},
      rowRecord: {},
      tableType: 0,
      useDefaultSearch: true,
      searchFields: [],
      searchDateVals: {},
      searchConfigModal: false,
      searchFieldFormModal: false,
      searchConfigList: [],
      searchConfigTableKey: 0,
      searchFieldForm: {
        id: 0,
        key: '',
        name: '',
        input_type: 1,
        info: '',
        sort: 0,
        is_show: 1,
        table_ids: ''
      },
      searchConfigColumns: [
        {
          title: '参数名',
          minWidth: 100,
          render: (h, params) => h('span', params.row.key || '')
        },
        { title: '名称', key: 'name', minWidth: 100 },
        {
          title: '类型',
          minWidth: 90,
          render: (h, params) => {
            const map = { 1: '文本', 3: '单日', 4: '下拉', 5: '门店', 6: '日期区间' };
            return h('span', map[params.row.input_type] || params.row.input_type);
          }
        },
        { title: '排序', key: 'sort', width: 70 },
        { title: '操作', slot: 'action', width: 120 }
      ]
    };
  },
  created() {

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
      if (this.needStoreList() && !this.tableFrom.store_id) {
        return this.$Message.warning('请先选择门店');
      }
      addTableSalary({
        table_ids: this.tableFrom.table_ids,
        store_id: this.tableFrom.store_id || 0,
      })
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
        method: 'DELETE',
        ids: '',
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
        table_ids: this.tableFrom.table_ids
      };
      this.searchFields.forEach(f => {
        tableFrom[f.key] = this.tableFrom[f.key];
      });
      this.tableFrom = tableFrom;
      this.tableFrom[e.key] = e.order;
      this.getList();
    },
    async begin(){
      await this.loadSearchFields();
      this.getColumn();
      if (this.needStoreList()) {
        this.allStore();
      }
      this.initSearchDates();
      this.getList();
      this.getSelfCount();
    },
    needStoreList() {
      return this.searchFields.some(f => Number(f.input_type) === 5);
    },
    hasDateRangeField() {
      return this.searchFields.some(f => Number(f.input_type) === 6);
    },
    loadSearchFields() {
      return selfSearch({ table_ids: this.tableFrom.table_ids }).then(res => {
        const data = res.data || {};
        this.useDefaultSearch = !!data.use_default;
        this.searchFields = data.fields || [];
        this.searchFields.forEach(f => {
          if (this.tableFrom[f.key] === undefined) {
            this.$set(this.tableFrom, f.key, f.key === 'store_id' ? '' : '');
          }
          if (Number(f.input_type) === 4 && !f.input_info) {
            f.input_info = f.info ? String(f.info).split(',') : [];
          }
        });
      }).catch(() => {
        this.searchFields = [
          { key: 'store_id', name: '选择门店', input_type: 5, input_info: [] },
          { key: 'date', name: '时间选择', input_type: 6, input_info: [] }
        ];
      });
    },
    getSearchDateValue(field) {
      if (field.key === 'date') {
        return this.timeVal;
      }
      return this.searchDateVals[field.key] || [];
    },
    onSearchDateChange(e, field) {
      const val = e && e[0] ? e.join('-') : '';
      this.$set(this.tableFrom, field.key, val);
      if (field.key === 'date') {
        this.timeVal = e || [];
      } else {
        this.$set(this.searchDateVals, field.key, e || []);
      }
      this.search();
    },
    onSearchSingleDateChange(e, field) {
      this.$set(this.tableFrom, field.key, e || '');
      this.search();
    },
    initSearchDates() {
      if (!this.hasDateRangeField()) {
        return;
      }
      const now = new Date();
      const y = now.getFullYear();
      const m = (now.getMonth() + 1).toString().padStart(2, '0');
      const d = now.getDate().toString().padStart(2, '0');
      const range = [`${y}/${m}/01`, `${y}/${m}/${d}`];
      this.searchFields.forEach(f => {
        if (Number(f.input_type) !== 6) {
          return;
        }
        if (f.key === 'date') {
          this.timeVal = range;
          this.tableFrom.date = range.join('-');
        } else if (!this.tableFrom[f.key]) {
          this.$set(this.searchDateVals, f.key, range);
          this.$set(this.tableFrom, f.key, range.join('-'));
        }
      });
    },
    openSearchConfig() {
      this.searchConfigModal = true;
      this.$nextTick(() => {
        this.loadSearchConfigList();
      });
    },
    onSearchConfigVisible(visible) {
      if (visible) {
        this.loadSearchConfigList();
      }
    },
    loadSearchConfigList() {
      const tableIds = this.tableFrom.table_ids;
      searchFieldList({ table_ids: tableIds }).then(res => {
        let list = res.data;
        if (list && !Array.isArray(list)) {
          list = list.data || Object.values(list);
        }
        this.searchConfigList = Array.isArray(list) ? list : [];
        this.searchConfigTableKey = Date.now();
      }).catch(err => {
        this.searchConfigList = [];
        this.$Message.error(err.msg || '加载搜索配置失败');
      });
    },
    addSearchFieldRow() {
      this.searchFieldForm = {
        id: 0,
        key: '',
        name: '',
        input_type: 1,
        info: '',
        sort: this.searchConfigList.length + 1,
        is_show: 1,
        table_ids: String(this.tableFrom.table_ids)
      };
      this.searchFieldFormModal = true;
    },
    editSearchField(row) {
      this.searchFieldForm = Object.assign({}, row, { table_ids: String(this.tableFrom.table_ids) });
      this.searchFieldFormModal = true;
    },
    saveSearchFieldForm() {
      searchFieldSave(this.searchFieldForm).then(() => {
        this.$Message.success('保存成功');
        this.searchFieldFormModal = false;
        this.loadSearchConfigList();
        this.loadSearchFields();
      }).catch(err => {
        this.$Message.error(err.msg || '保存失败');
      });
    },
    removeSearchField(row) {
      if (!row.id) {
        return;
      }
      this.$Modal.confirm({
        title: '确认删除',
        content: '确定删除该搜索项？',
        onOk: () => {
          searchFieldDelete(row.id).then(() => {
            this.$Message.success('已删除');
            this.loadSearchConfigList();
            this.loadSearchFields();
          });
        }
      });
    },
    async exports() {
      this.exportLoading = true
      try {
        let [th, filekey, data, fileName] = [[], [], [], '']
        // let fileName = "";
        let excelData = JSON.parse(JSON.stringify(this.tableFrom));
        excelData.page = 1
        excelData.is_excel = 1;
        for (let i = 0; i < excelData.page + 1; i++) {
          let lebData = await this.getExcelData(excelData)
          if (!fileName) fileName = lebData.filename
          if (!filekey.length) {
            filekey = lebData.filekey
          }
          if (!th.length) th = lebData.header
          if (lebData.export.length) {
            data = data.concat(lebData.export)
            excelData.page++
          } else {
            exportExcel(th, filekey, fileName, data)
            return
          }
        }
      } catch (err) {
        this.exportLoading = false
        this.$message.error('导出失败：' + (err.message || '网络异常'))
      } finally {
        this.exportLoading = false
      }
    },
    getExcelData(excelData) {
      return new Promise((resolve, reject) => {
        selfList(excelData).then((res) => {
          return resolve(res.data)
        })
      })
    },
    doEdit(type){
       this.isEdit=type;
    },
    allStore(){
      staffListInfo().then(res=>{
        this.storeList = res.data;
      }).catch(err=>{
        this.$Message.error(res.msg);
      })
    },
    getColumn(){
      let  that=this;
      const params = { table_ids: this.tableFrom.table_ids };
      if (this.tableFrom.date) {
        params.date = this.tableFrom.date;
      }
      if (this.tableFrom.store_id) {
        params.store_id = this.tableFrom.store_id;
      }
      selfColumn(params).then(async (res) => {
         that.columns1=res.data;
      }).catch((res) => {
        this.$Message.error(res.msg);
      });
    },
    showTip(){
       this.tip=true;
    },
    search(){
        this.tableList=[];
        this.tableFrom.page=1;
        this.getList();
        this.getSelfCount();
    },
    pageChange(page) {
      this.tableFrom.page = page;
      this.getList();
    },
    cancel() {
      this.formValidate = {
        page: 1,
        limit: 10
      };
    },
    // 修改成功
    submitFail() {
      this.tableList=[];
      this.getList();
    },
    getSelfCount(){
      this.moneyCount=[];
      selfCount(this.tableFrom).then(res=>{
              this.moneyCount=res.data;
      })
    },
    // 列表
    getList() {
      this.loading = true;
      selfList(this.tableFrom)
          .then(async (res) => {
            let data = res.data;
            this.tableList = data.list;
            this.total = data.count;
            this.tableType = data.table_type;
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
/deep/.ivu-table-fixed-shadow{
  height: auto !important;
}
.tabBox_img {
  width: 36px;
  height: 36px;
  border-radius: 4px;
  cursor: pointer;

img {
  width: 100%;
  height: 100%;
}
}

.modelBox {
>>>, .ivu-table-header {
  width: 100% !important;
}
}

.trees-coadd {
  width: 100%;
  height: 385px;

.scollhide {
  width: 100%;
  height: 100%;
  overflow-x: hidden;
  overflow-y: scroll;
}
}

// margin-left: 18px;
.scollhide::-webkit-scrollbar {
  display: none;
}
</style>
