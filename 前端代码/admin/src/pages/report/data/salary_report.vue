<template>
  <!-- 用户-客服管理-客服列表 -->
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="new_card_pd">
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
              <FormItem label="选择门店：">
                <Select
                    clearable
                    v-model="tableFrom.store_id"
                    @on-change="search"
                    class="input-add"
                >
                  <Option
                      v-for="item in storeList"
                      :value="item.id"
                      :key="item.id"
                  >{{ item.name }}</Option
                  >
                </Select>
              </FormItem>
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
                <Button type="primary" @click="search" style="margin-left: 20px">查询</Button>
                <Button type="primary" @click="exports" style="margin-left: 20px">导出</Button>
                <Button type="primary" @click="doEdit(1)" style="margin-left: 20px" v-if="isEdit == 0">编辑</Button>
                <Button type="primary" @click="doEdit(0)" style="margin-left: 20px" v-else>取消编辑</Button>
                <Button type="primary" @click="doFreeze(2)" style="margin-left: 20px;background-color: #e74949;border-color: #e74949" v-if="freeze == 1">取消冻结</Button>
                <Button type="primary"  @click="doFreeze(1)" style="margin-left: 20px" v-else>冻结</Button>
                <Button type="primary" @click="daiLi" style="margin-left: 20px">代班录入</Button>
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
          :columns="columns1"
          :data="tableList"
          :loading="loading"
          highlight-row
          no-userFrom-text="暂无数据"
          no-filtered-userFrom-text="暂无筛选结果"
      >
        <template v-for="(item,index) in columns1" slot-scope="{ row, index }"  :slot="item.slot">
          <Input
              v-if="isEdit"
              type="text"
              :placeholder="'请输入'+item.title"
              v-model="row[item.slot]"
              @input="changeGoal($event,row,item)"
          ></Input>
          <span v-else>
               {{ row[item.slot] }}
          </span>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
            :current="tableFrom.page"
            :total="total"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="tableFrom.limit"
        />
      </div>
    </Card>
    <Modal v-model="agentModals" title="代班录入" footerHide scrollable width="1200" @on-cancel="cancelPosition">
      <salary ref="salary" :store-id="tableFrom.store_id"></salary>
    </Modal>
  </div>
</template>

<script>
import { mapState } from "vuex";
import util from "@/libs/util";
import Setting from "@/setting";
import exportExcel from "@/utils/newToExcel.js";
import { salaryList,setSalary,salaryColumn,makeFreeze,getFreeze,salaryCount } from "@/api/salary";
import { staffListInfo } from '@/api/store';
import timeOptions from "@/utils/timeOptions";
import salary from '@/components/salary';
export default {
  name: "index",
  components:{
    salary
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
      freeze:0,
      isEdit:0,
      tip:false,
      storeList:[],
      agentModals:false,
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
      },
      moneyCount:[],
      timeVal: '',
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
    };
  },
  created() {
    this.getColumn();
    this.getDate();
    this.allStore();
  },
  methods: {
    doFreeze(freeze){
      this.freeze=freeze;
      makeFreeze({date:this.tableFrom.date,is_freeze:freeze}).then(res=>{
           this.$message("操作成功！");
       })
     },
    getFreezeInfo(){
      let date=this.tableFrom.date;
      getFreeze({date:date}).then((res) => {
          this.freeze=res.data.is_freeze
      })
    },
    async exports() {
      let [th, filekey, data, fileName] = [[], [], [], '']
      // let fileName = "";
      let excelData = JSON.parse(JSON.stringify(this.tableFrom));
      excelData.page = 1
      excelData.is_excel=1;
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
    },
    getExcelData(excelData) {
      return new Promise((resolve, reject) => {
        salaryList(excelData).then((res) => {
          return resolve(res.data)
        })
      })
    },
    cancelPosition(){
      this.agentModals=false;
    },
    daiLi(){
      if (!this.tableFrom.store_id) {
        return this.$Message.warning('请先选择门店');
      }
      this.$refs.salary.getDate();
      this.agentModals=true;
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
      salaryColumn({}).then(async (res) => {
         that.columns1=res.data;
      }).catch((res) => {
        this.$Message.error(res.msg);
      });
    },
    showTip(){
       this.tip=true;
    },
    // 具体日期
    onchangeTime(e) {
      this.timeVal = e;
      this.tableFrom.date = e;
      this.tableFrom.page = 1;
      this.getList();
      this.getSalaryCount();
    },
    getDate(){
      const now = new Date();
      const y = now.getFullYear();
      const m = (now.getMonth() + 1).toString().padStart(2, '0');
      const d = now.getDate().toString().padStart(2, '0');
      this.timeVal = `${y}-${m}`;
      this.tableFrom.date = this.timeVal;
      this.getList();
      this.getSalaryCount();
    },
    search(){
        this.tableFrom.page=1;
        this.getList();
        this.getSalaryCount();
    },
    changeGoal(e,row,item){
         //修改值
      let data={
        id:row.id,
        key:item.slot,
        value:e
      }
      setSalary(data)
          .then((res) => {

          }).catch((res) => {
               this.$Message.error(res.msg);
          });
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
      this.getList();
    },
    getSalaryCount(){
      salaryCount(this.tableFrom).then(res=>{
              this.moneyCount=res.data;
      })
    },
    // 列表
    getList() {
      this.loading = true;
      salaryList(this.tableFrom)
          .then(async (res) => {
            let data = res.data;
            this.tableList = data.list;
            this.total = data.count;
            this.loading = false;
            this.getFreezeInfo();
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
