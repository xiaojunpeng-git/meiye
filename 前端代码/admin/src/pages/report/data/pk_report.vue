<template>
  <!-- 用户-客服管理-客服列表 -->
  <div>
    <Modal v-model="tip" title="注解" width="560px">
      <div class="area-set">
        本月目标：输入<br>
        本月底标：前6个月现金业绩的平均值(扣掉合作方等老师)<br>
        现金业绩：客户实际支付并已到账的金额<br>
        业绩分成款：销售业绩分配合作方等老师<br>
        实际业绩：现金业绩 − 分成款<br>
        增长业绩：实际业绩 − 本月底标<br>
        完成率：实际业绩 / 本月目标<br>
      </div>
      <span slot="footer" class="dialog-footer">
             <Button @click.stop="tip = false">确定</Button>
        </span>
    </Modal>
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
                <Button @click="showTip" style="margin-left: 20px">报表注解</Button>
                <Button type="primary" @click="doEdit(1)" style="margin-left: 20px" v-if="isEdit == 0">编辑</Button>
                <Button type="primary" @click="doEdit(0)" style="margin-left: 20px" v-else>取消编辑</Button>
              </FormItem>
            </Col>
          </Row>
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
        <template slot-scope="{ row, index }" slot="goal">
          <Input
              v-if="isEdit"
              type="text"
              placeholder="请输入本月目标"
              v-model="row.goal"
              @input="changeGoal($event,row)"
          ></Input>
          <span v-else>
               {{ row.goal }}
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
  </div>
</template>

<script>
import { mapState } from "vuex";
import util from "@/libs/util";
import Setting from "@/setting";
import { pkInfo,savePk } from "@/api/report";
import timeOptions from "@/utils/timeOptions";
import exportExcel from "@/utils/newToExcel.js";
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
      isEdit:0,
      tip:false,
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
        limit: 11,
        date:''
      },
      timeVal: '',
      loading: false,
      tableList: [],
      columns1: [
        {
          title: "门店",
          key: "name",
          minWidth: 100,
        },
        {
          title: "本月目标",
          slot: "goal",
          minWidth: 100,
        },
        {
          title: "本月底标",
          key: "bottom",
          minWidth: 100,
        },
        {
          title: "现金业绩",
          key: "month",
          minWidth: 100,
        },
        {
          title: "业绩分成款",
          key: "month_fencheng",
          minWidth: 100,
        },
        {
          title: "实际业绩",
          key: "complete",
          minWidth: 100,
        },
        {
          title: "本月增长业绩",
          key: "add_yeji",
          minWidth: 100,
        },
        {
          title: "完成率",
          key: "goal_per",
          minWidth: 100
        },
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
    this.getDate();
  },
  methods: {
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
        pkInfo(excelData).then((res) => {
          return resolve(res.data)
        })
      })
    },
    doEdit(type){
      this.isEdit=type;
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
    },
    getDate(){
      const now = new Date();
      const y = now.getFullYear();
      const m = (now.getMonth() + 1).toString().padStart(2, '0');
      const d = now.getDate().toString().padStart(2, '0');
      this.timeVal = `${y}-${m}`;
      this.tableFrom.date = this.timeVal;
      this.getList();
    },
    search(){
        this.tableFrom.page=1;
        this.getList();
    },
    changeGoal(e,row){
         //修改值
      let per=((row.complete/e)*100).toFixed(2);
      row.goal_per=per+"%";
      let data={
        store_id:row.store_id,
        date:row.date,
        goal:e
      }
      savePk(data)
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
    // 列表
    getList() {
      this.loading = true;
      pkInfo(this.tableFrom)
          .then(async (res) => {
            let data = res.data;
            this.tableList = data.result;
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
