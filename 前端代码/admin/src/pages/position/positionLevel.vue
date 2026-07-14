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
              <FormItem label="搜索：">
                <Input
                    v-model="tableFrom.name"
                    placeholder="请输入职级名称"
                    class="input-add"
                ></Input>
                <Button class="ml-14" @click="search()" type="primary">查询</Button>
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
        <template slot-scope="{ row, index }" slot="status">
          <i-switch v-model="row.status" :value="row.status" :true-value="1" :false-value="0" @on-change="onchangeIsShow(row)" size="large">
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
        </template>
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
    </Card>
  </div>
</template>

<script>
import { mapState } from "vuex";
import util from "@/libs/util";
import Setting from "@/setting";
import { positionLevelList,editPositionLevel,positionLevelSetStatus } from "@/api/position";
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
      options: timeOptions,
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
      position_id:0,
      tableFrom: {
        page: 1,
        limit: 15,
        name:''
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
          key: "name",
          minWidth: 120,
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
  created(option) {
    this.position_id=option.position_id || 0;
    this.getList();
  },
  methods: {
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
    // 添加配送员
    add() {
      this.$modalForm(editPositionLevel({})).then(() =>
          this.getList()
      );
    },
    // 编辑
    edit(row) {
      this.$modalForm(editPositionLevel({id:row.id,position_id:this.position_id})).then(() =>
          this.getList()
      );
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
