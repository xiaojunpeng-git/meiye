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
              <FormItem label="时间选择：">
                <DatePicker
                    :editable="false"
                    @on-change="onchangeTime"
                    :value="timeVal"
                    format="yyyy/MM/dd"
                    type="daterange"
                    placement="bottom-end"
                    placeholder="自定义时间"
                    class="input-width"
                    :options="options"
                ></DatePicker>
                <Button type="primary" @click="search" style="margin-left: 20px">查询</Button>
                <Button type="primary" @click="exports" style="margin-left: 20px">导出</Button>
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
        <template slot-scope="{ row, index }" slot="action">
          <a @click="edit(row)">编辑</a>
          <a @click="del(row, '删除项目', index)" style="margin-left: 20px">删除</a>
        </template>
      </Table>
    </Card>
  </div>
</template>

<script>
import { mapState } from "vuex";
import util from "@/libs/util";
import Setting from "@/setting";
import { reportSale,fenxiList } from "@/api/report";
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
        date:''
      },
      timeVal: [],
      loading: false,
      tableList: [],
      columns1: [
        {
          title: "门店",
          key: "work_name",
          minWidth: 120,
        }
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
    this.getReportSale();
    this.getDate();
  },
  methods: {
    async exports() {
      let [th, filekey, data, fileName] = [[], [], [], '']
      // let fileName = "";
      let excelData = JSON.parse(JSON.stringify(this.tableFrom));
      excelData.page = 1
      excelData.is_excel=1;
      let lebData = await this.getExcelData(excelData)
      if (!fileName) fileName = lebData.filename
      if (!filekey.length) {
        filekey = lebData.filekey
      }
      if (!th.length) th = lebData.header
      data = data.concat(lebData.export)
      exportExcel(th, filekey, fileName, data)
    },
    getExcelData(excelData) {
      return new Promise((resolve, reject) => {
        fenxiList(excelData).then((res) => {
          return resolve(res.data)
        })
      })
    },
    // 具体日期
    onchangeTime(e) {
      this.timeVal = e;
      this.tableFrom.date = this.timeVal[0] ? this.timeVal.join("-") : "";
      this.tableFrom.page = 1;
      this.getList();
    },
    getDate(){
      const now = new Date();
      const y = now.getFullYear();
      const m = (now.getMonth() + 1).toString().padStart(2, '0');
      const d = now.getDate().toString().padStart(2, '0');
      const h = now.getHours().toString().padStart(2, '0');
      const mi = now.getMinutes().toString().padStart(2, '0');
      const s = now.getSeconds().toString().padStart(2, '0');
      this.timeVal = [`${y}/${m}/01`, `${y}/${m}/${d}`];
      this.tableFrom.date = this.timeVal[0] ? this.timeVal.join("-") : "";
      this.getList();
    },
    search(){
        this.tableFrom.page=1;
        this.getList();
    },
    getReportSale(){
          let  that=this;
         reportSale({}).then(async (res) => {
            res.data.forEach(function (item,index){
                   that.columns1.push({
                     title: item.name,
                     key: item.key,
                     minWidth: 100,
                   })
            });
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
    // 添加配送员
    add() {
      this.$modalForm(setCommission({})).then(() =>
          this.getList()
      );
    },
    // 编辑
    edit(row) {
      this.$modalForm(setCommission({id:row.id})).then(() =>
          this.getList()
      );
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `order/yejiCommission/del/`+row.id,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData)
          .then((res) => {
            this.$Message.success(res.msg);
            this.tableList.splice(num, 1);
            if (!this.tableList.length) {
              this.tableFrom.page =
                  this.tableFrom.page == 1 ? 1 : this.tableFrom.page - 1;
            }
            this.getList();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
    },
    // 列表
    getList() {
      this.loading = true;
      fenxiList(this.tableFrom)
          .then(async (res) => {
            let data = res.data;
            this.tableList = data;
            this.total = data.length;
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
