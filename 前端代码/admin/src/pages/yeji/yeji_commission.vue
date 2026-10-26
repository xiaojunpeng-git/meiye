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
              <FormItem label="项目名称：">
                <Input
                    v-model="tableFrom.keyword"
                    placeholder="请输入项目名称"
                    class="input-add"
                ></Input>
                <Button type="primary" @click="search" style="margin-left: 20px">查询</Button>
              </FormItem>
            </Col>
          </Row>
        </Form>
      </div>
      <!-- 添加客服 -->
      <Row type="flex" class="mb20">
        <Col span="24">
          <Button
              type="primary"
              @click="add"
              class="mr10"
          >添加项目</Button>
        </Col>
      </Row>
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
      <div class="acea-row row-right page">
        <Page
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
import {yejiCommission,setCommission,yejiColumn} from "@/api/yeji";
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
      isChat: true,
      formValidate3: {
        page: 1,
        limit: 15,
      },
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
        keyword:''
      },
      timeVal: [],
      loading: false,
      tableList: [],
      columns1: [
        {
          title: "ID",
          key: "id",
          width: 80,
        },
        {
          title: "项目名称",
          key: "store_name",
          minWidth: 120,
        },
        {
          title: "产品编号",
          key: "product_id",
          minWidth: 100,
        },
        {
          title: "耗卡业绩",
          key: "yeji",
          minWidth: 100,
        },
        {
          title: "操作",
          slot: "action",
          fixed: "right",
          width: 200,
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
    this.getYejiColumn();
    this.getList();
  },
  methods: {
    search(){
        this.tableFrom.page=1;
        this.getList();
    },
    getYejiColumn(){
          let  that=this;
         yejiColumn(this.tableFrom).then(async (res) => {
            res.data.forEach(function (item,index){
                   that.columns1.push({
                     title: item,
                     key: item,
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
      yejiCommission(this.tableFrom)
          .then(async (res) => {
            let data = res.data;
            this.tableList = data.list;
            this.total = res.data.count;
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
