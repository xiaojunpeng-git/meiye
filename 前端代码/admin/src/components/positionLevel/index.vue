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
              <FormItem label="职级">
                <Input
                    v-model="tableFrom.name"
                    placeholder="请输入职级名称"
                    class="input-add"
                />
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
        <template slot-scope="{ row, index }" slot="status">
          <i-switch v-model="row.status" :value="row.status" :true-value="1" :false-value="0" @on-change="onchangeIsShow(row)" size="large">
            <span slot="open">可用</span>
            <span slot="close">禁用</span>
          </i-switch>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a @click="edit(row)">编辑</a>
          <a @click="del(row, '删除', index)" style="margin-left: 20px">删除</a>
        </template>
        <template slot-scope="{ row, index }" slot="yeji">
          <a @click="setYeji(row)">设置</a>
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
import util from "@/libs/util";
import Setting from "@/setting";
import { positionLevelList,editPositionLevel,positionLevelSetStatus } from "@/api/position";
import timeOptions from "@/utils/timeOptions";
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
      options: timeOptions,
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
        name:'',
        position_id:0
      },
      timeVal: [],
      loading: false,
      tableList: [],
      columns1: [
        {
          title: "ID",
          key: "id",
          minWidth: 120,
        },
        {
          title: "职位",
          key: "position_label",
          minWidth: 120,
        },
        {
          title: "业绩类型",
          key: "type_label",
          minWidth: 120,
        },
        {
          title: "是否包含充值",
          key: "has_recharge",
          minWidth: 120,
        },
        {
          title: "业绩取值范围",
          key: "range_type",
          minWidth: 120,
        },
        {
          title: "业绩区间",
          key: "range",
          minWidth: 120,
        },
        {
          title: "品项类",
          key: "cates",
          minWidth: 120,
        },
        {
          title: "职级",
          key: "position_level_label",
          minWidth: 120,
        },
        {
          title: '业绩提成（%）',
          key: 'commission',
          minWidth: 130
        },
        {
          title: '状态',
          slot: 'status',
          minWidth: 130
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
    cancelPosition(){
      this.positionLevelModals=false;
    },
    onchangeIsShow (row) {
      let data = {
        id: row.id,
        status: row.status
      }
      let functon;
      functon = positionLevelSetStatus(data);
      functon.then(async res => {
        this.$Message.success(res.msg);
      }).catch(res => {
        this.$Message.error(res.msg);
      })
    },
    // 具体日期
    onchangeTime(e) {
      this.timeVal = e;
      this.tableFrom.page = 1;
      this.getList();
    },
    search(){
      this.tableFrom.page=1;
      this.getList();
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
    editSuccess(){
        this.getList();
       this.$emit("getList");
    },
    // 添加配送员
    add() {
      this.$modalForm(editPositionLevel({position_id:this.tableFrom.position_id})).then(() =>
          this.editSuccess()
      );
    },
    // 编辑
    edit(row) {
      this.$modalForm(editPositionLevel({id:row.id,position_id:this.tableFrom.position_id})).then(() =>
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
        url: `position/delPositionLevel/`+row.id,
        method: "PUT",
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
      positionLevelList(this.tableFrom)
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
  }
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
</style>
