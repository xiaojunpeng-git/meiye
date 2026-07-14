<template>
  <!-- 用户-客服管理-客服列表 -->
  <div>
    <div>
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
              <Button type="primary"  icon="md-add" @click="add">添加</Button>
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
import { agentList,editAgent } from "@/api/agent";
import editFrom from '@/components/from/from';
export default {
  name: "index",
  components:{
    editFrom
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
      isChat: true,
      formValidate3: {
        page: 1,
        limit: 15,
      },
      positionLevelModals:false,
      total3: 0,
      loading3: false,
      modals3: false,
      tableList3: [],
      formValidate5: {
        page: 1,
        limit: 15,
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
        limit: 15,
      },
      tableList2: [],
      modals: false,
      total: 0,
      tableFrom: {
        page: 1,
        limit: 15,
        date:'',
      },
      timeVal:'',
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

  },
  mounted() {
  },
  methods: {
    getDate(){
      const now = new Date();
      const y = now.getFullYear();
      const m = (now.getMonth() + 1).toString().padStart(2, '0');
      const d = now.getDate().toString().padStart(2, '0');
      this.timeVal = `${y}-${m}`;
      this.tableFrom.date = this.timeVal;
      this.getList();
    },
    cancelPosition(){
      this.positionLevelModals=false;
    },
    // 具体日期
    onchangeTime(e) {
      this.timeVal = e;
      this.tableFrom.page = 1;
      this.tableFrom.date = e;
      this.getList();
    },
    search(){
      this.tableFrom.page=1;
      this.getList();
    },
    pageChange(page) {
      this.formData.page = page;
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
    editSuccess(){
        this.getList();
        this.$emit("getList");
    },
    // 添加配送员
    add() {
      this.$modalForm(editAgent({})).then(() =>
          this.editSuccess()
      );
    },
    // 编辑
    edit(row) {
      this.$modalForm(editAgent({id:row.id})).then(() =>
          this.editSuccess()
      );
    },
    setYeji(row){

    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `report/delAgent/`+row.id,
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
    // 列表
    getList() {
      this.loading = true;
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
/deep/.ivu-table {
  height: 300px;
  overflow-x: hidden;
  overflow-y: auto;
}

/deep/.ivu-table-overflowX {
  overflow-x: hidden !important;
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
