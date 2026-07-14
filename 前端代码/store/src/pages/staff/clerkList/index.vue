<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt box">
      <Form
        ref="formValidate"
        :model="formValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
      >
        <Row type="flex" :gutter="24">
          <Col>
            <FormItem label="店员搜索：" :labelWidth="80">
              <Input
                v-model="formValidate.keyword"
                placeholder="请输入ID/手机号"
                clearable
              >
                <Select
                  v-model="formValidate.field_key"
                  slot="prepend"
                  style="width: 80px"
                >
                  <Option value="all">全部</Option>
                  <Option value="id">ID</Option>
                  <Option value="phone">手机号</Option>
                </Select>
              </Input>
            </FormItem>
          </Col>
          <Col>
            <div class="search" @click="search">搜索</div>
          </Col>
          <Col>
            <div class="reset" @click="reset">重置</div>
          </Col>
        </Row>
      </Form>
    </Card>

    <Card :bordered="false" dis-hover class="ive-mt tablebox">
      <div class="btnbox">
        <Button v-auth="['staff-staff-create']" type="primary" @click="add"
          >添加店员</Button
        >
        <Button class="ml10" @click="goSchedule">排班管理</Button>
      </div>
      <div class="table">
        <Table
          :columns="columns"
          :data="orderList"
          ref="table"
          class="mt25"
          :loading="loading"
          highlight-row
          no-userFrom-text="暂无数据"
          no-filtered-userFrom-text="暂无筛选结果"
        >
          <template slot-scope="{ row, index }" slot="avatars">
            <viewer>
              <div class="tabBox_img">
                <img v-lazy="row.avatar" />
              </div>
            </viewer>
          </template>
					<template slot-scope="{ row, index }" slot="staff_name">
					  <div>{{row.staff_name}}<span style="color: #ed4014;" v-if="row.delete_time != null"> (已注销)</span></div>
					</template>
          <template slot-scope="{ row, index }" slot="label">
            <div>{{row.workMember ? row.workMember.name : ''}}</div>
          </template>
          <template slot-scope="{ row, index }" slot="status">
            <i-switch
              v-model="row.status"
              :value="row.status"
              :true-value="1"
              :false-value="0"
              @on-change="changeSwitch(row)"
              size="large"
            >
              <span slot="open">开启</span>
              <span slot="close">关闭</span>
            </i-switch>
          </template>
          <template slot-scope="{ row, index }" slot="action">
            <a @click="goCashier(row)" v-if="row.status == 1 && row.delete_time == null">进入收银台</a>
            <Divider type="vertical" v-if="row.status == 1 && row.delete_time == null" />
            <a @click="edit(row.id)" v-if="row.delete_time == null">编辑</a>
            <Divider type="vertical" v-if="row.delete_time == null" />
            <a @click="del(row.id, '删除该店员', index)" v-if="row.level > 0">删除</a>
            <Divider type="vertical" v-if="row.level > 0" />
            <a @click="details(row)">查看详情</a>
          </template>
        </Table>
        <div class="acea-row row-right page">
          <Page
            :total="total"
            :current="formValidate.page"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="formValidate.limit"
          />
        </div>
      </div>
    </Card>
    <Details ref="userDetails" @edit="handleEdit"></Details>
    <!-- 修改业绩归属店员弹窗 -->
    <Modal
      v-model="editModal"
      scrollable
      footer-hide
      closable
      title="修改"
      :mask-closable="false"
      width="550"
    >
      <Form :model="editForm" :label-width="80">
        <FormItem label="业绩金额：">¥{{editRow.pay_price}}</FormItem>
        <FormItem label="业绩订单：">{{editRow.order_id}}</FormItem>
        <FormItem label="业绩归属：">
          <Select v-model="editForm.staff_id">
            <Option :value="item.value" v-for="item in staffAll" :key="item.value">{{item.label}}</Option>
          </Select>
        </FormItem>
      </Form>
      <div class="acea-row row-right">
        <Button class="mr10" @click="cancelEditModal">取消</Button>
        <Button type="primary" @click="saveOrderStaff">确认</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import { mapState } from "vuex";
import util from "@/libs/util";
import Cookies from "js-cookie";
import Setting from "@/setting";
import {
  staffListInfo,
  staffcreate,
  staffEditApi,
  staffshowApi,
  cashierLogin,
  staffallInfo,
  orderStaff,
} from "@/api/staff.js";
import Details from "../components/details";
export default {
  name: "clerkList",
  components: {
    Details,
  },
  data() {
    return {
	  routePre:Setting.routePre,
      total: 0,
      a: 12,
      loading: false,
      columns: [
        {
          title: "ID",
          key: "id",
          width: 60,
        },
        {
          title: "头像",
          slot: "avatars",
          minWidth: 80,
        },
        {
          title: "昵称",
          slot: "staff_name",
          minWidth: 120,
        },
        {
          title: "店员身份",
          key: "roles",
          minWidth: 120,
        },
        {
          title: "职位",
          key: "position_label",
          minWidth: 120,
        },
        {
          title: "职级",
          key: "position_level_label",
          minWidth: 120,
        },
        {
          title: "工号",
          key: "employee_number",
          minWidth: 120,
        },
        {
          title: "入职日期",
          key: "join_date",
          minWidth: 120,
        },
        {
          title: "身份证号码",
          key: "id_card",
          minWidth: 120,
        },
        {
          title: "生日日期",
          key: "birthday_date",
          minWidth: 120,
        },
        {
          title: "年龄",
          key: "age",
          minWidth: 120,
        },
        {
          title: "劳动关系所在地",
          key: "join_area",
          minWidth: 120,
        },
        {
          title: "籍贯",
          key: "birthday_area",
          minWidth: 120,
        },
        {
          title: "现居地",
          key: "now_area",
          minWidth: 120,
        },
        {
          title: "合同起始日",
          key: "contract_begin",
          minWidth: 120,
        },
        {
          title: "合同终止日",
          key: "contract_end",
          minWidth: 120,
        },
		{
		  title: "企微员工",
		  slot: "label",
		  minWidth: 120,
		},
        {
          title: "手机号",
          key: "phone",
          minWidth: 150,
        },
        {
          title: "专属客户",
          key: "customer_num",
          minWidth: 150,
        },
        {
          title: "账号状态",
          slot: "status",
          minWidth: 80,
        },
        {
          title: "操作",
          slot: "action",
          fixed: "right",
          minWidth: 250,
        },
      ],
      orderList: [],
      formValidate: {
        field_key: "all",
        keyword: "",
        page: 1,
        limit: 15,
      },
      staffRow: {},
      editForm: {
        order_id: '',
        staff_id: 0,
      },
      editRow: {},
      editModal: false,
      staffAll: [],
    };
  },
  computed: {
    ...mapState("store/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? "top" : "left";
    },
  },
  mounted() {
    this.getList();
  },
  methods: {
    goSchedule() {
      this.$router.push({ path: `${this.routePre}/staff/schedule` });
    },
    goCashier(item) {
      cashierLogin(item.id)
        .then((res) => {
          Cookies.set("cashierData", JSON.stringify(res));
          window.open(
            window.location.protocol +
              "//" +
              window.location.host +
              "/" +
              res.data.prefix +
              "/login"
          );
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    //列表
    getList() {
      this.loading = true;
      staffListInfo(this.formValidate)
        .then((res) => {
          this.total = res.data.count;
          this.orderList = res.data.list;
          this.loading = false;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
          this.loading = false;
        });
    },
    //添加
    add() {
		this.$router.push({ path: this.routePre + "/staff/clerkList/add/" + 0 });
      // this.$modalForm(staffcreate()).then(() => this.getList());
    },
    //编辑
    edit(id) {
		this.$router.push({ path: this.routePre + "/staff/clerkList/add/" + id });
      // this.$modalForm(staffEditApi(id)).then(() => this.getList());
    },
    //删除
    del(id, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `/staff/staff/${id}`,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.orderList.splice(num, 1);
          if (!this.orderList.length) {
            this.formValidate.page =
                this.formValidate.page == 1 ? 1 : this.formValidate.page - 1;
          }
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    //搜索
    search() {
      this.getList();
    },
    //重置
    reset() {
      this.formValidate.field_key = "all";
      this.formValidate.keyword = "";
      this.getList();
    },
    //状态
    changeSwitch(row) {
      staffshowApi(row.id, row.status)
        .then((res) => {
          this.$Message.success(res.msg);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    //分页
    pageChange(status) {
      this.formValidate.page = status;
      this.getList();
    },
    //详情
    details(row) {
      this.staffRow = row;
      this.$refs.userDetails.modals = true;
      this.$refs.userDetails.getDetails(row.id);
    },
    // 打开修改业绩归属店员弹窗
    handleEdit(row) {
      this.editRow = row;
      this.editForm.order_id = row.order_id;
      this.editForm.staff_id = 0;
      this.editModal = true;
      this.getStaffAll();
    },
    // 获取全部店员
    getStaffAll() {
      staffallInfo().then((res) => {
        this.staffAll = res.data.filter((item) => {
          return item.value != this.staffRow.id;
        });
      }).catch((err) => {
        this.$Message.error(err.msg);
      });
    },
    // 保存修改的业绩归属店员
    saveOrderStaff() {
      if (!this.editForm.staff_id) {
        return this.$Message.warning('请选择业绩归属店员');
      }
      orderStaff(this.editForm).then((res) => {
        this.$Message.success(res.msg);
        this.editModal = false;
        this.$refs.userDetails.refreshOrder();
      }).catch((err) => {
        this.$Message.error(err.msg);
      });
    },
    // 关闭修改业绩归属店员弹窗
    cancelEditModal() {
      this.editModal = false;
    },
  },
};
</script>

<style scoped lang="less">
/deep/.ivu-form-label-left .ivu-form-item-label {
  text-align: right;
}
/deep/.ivu-page-header,
/deep/.ivu-tabs-bar {
  border-bottom: 1px solid #ffffff;
}
/deep/.ivu-card-body {
  padding: 0;
}
	/deep/.ivu-select-selected-value {
	font-size: 12px !important;}
/deep/.ivu-tabs-nav {
  height: 45px;
}
.box {
  padding: 20px;
  padding-bottom: 1px;
}
.tablebox {
  margin-top: 15px;
}
.btnbox {
  padding: 20px 0px 0px 30px;
  .btns {
    width: 99px;
    height: 32px;
    background: #1890ff;
    border-radius: 4px;
    text-align: center;
    line-height: 32px;
    color: #ffffff;
    cursor: pointer;
  }
}
.table {
  padding: 0px 30px 15px 30px;
}
.search {
  width: 86px;
  height: 32px;
  background: #1890ff;
  border-radius: 4px;
  text-align: center;
  line-height: 32px;
  font-size: 13px;
  font-family: PingFangSC-Regular, PingFang SC;
  font-weight: 400;
  color: #ffffff;
  cursor: pointer;
}
.reset {
  width: 86px;
  height: 32px;
  border-radius: 4px;
  border: 1px solid rgba(151, 151, 151, 0.36);
  text-align: center;
  line-height: 32px;
  font-size: 13px;
  font-family: PingFangSC-Regular, PingFang SC;
  font-weight: 400;
  color: rgba(0, 0, 0, 0.85);
  cursor: pointer;
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
</style>
