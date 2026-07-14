<template>
<!-- 财务-储值记录 -->
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding= "0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
      <Form
        ref="formValidate"
        :model="formValidate"
        :label-width="labelWidth"
        inline
        :label-position="labelPosition"
        class="tabform"
        @submit.native.prevent
      >
            <FormItem label="时间选择：">
              <DatePicker
                :editable="false"
                @on-change="onchangeTime"
                :value="timeVal"
                format="yyyy/MM/dd HH:mm"
                type="datetimerange"
                placement="bottom-start"
                placeholder="自定义时间"
                class="input-width"
                :options="options"
              ></DatePicker>
            </FormItem>

            <FormItem label="支付类型：">
              <Select v-model="formValidate.paid" clearable class="input-add" @on-change="orderSearch">
                <Option value="1">已支付</Option>
                <Option value="0">未支付</Option>
              </Select>
            </FormItem>
            <FormItem label="搜索：">
              <Input
                @on-search="selChange"
                placeholder="请输入用户昵称、订单号"
                element-id="name"
                v-model="formValidate.nickname"
                class="mr input-add"
              />
              <Button type="primary" @click="orderSearch()" class="mr">查询</Button>
              <Button
              v-auth="['export-userRecharge']"
              @click="exports"
              >导出</Button>
            </FormItem>
      </Form>
      </div>
    </Card>

    <cards-data
      :cardLists="cardLists"
      v-if="cardLists.length >= 0"
    ></cards-data>
    <Card :bordered="false" dis-hover>
      <!-- 表格 -->
      <Table
        ref="table"
        :columns="columns"
        :data="tabList"
        class="ivu-mt"
        :loading="loading"
        no-data-text="暂无数据"
        no-filtered-data-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="recharge_type">
            <a @click="showRemark(row)" v-if="row.recharge_type == 'combination' || row.recharge_type == 'cash'">{{ row._recharge_type }}</a>
            <span v-else>{{ row._recharge_type }}</span>
        </template>
			  <template slot-scope="{ row }" slot="nickname">
					<a @click="userDetails(row)">{{ row.uid }}｜{{ row.nickname }} </a>
					<span style="color: #ed4014;" v-if="row.delete_time != null"> (已注销)</span>
			  </template>
        <template slot-scope="{ row }" slot="paid_type">
          <Tag color="green" size="large" v-if="row.paid">{{row.paid_type}}</Tag>
          <Tag color="orange" size="large" v-else>{{row.paid_type}}</Tag>
        </template>
				<template slot-scope="{ row, index }" slot="right">
				  <a
				    href="javascript:void(0);"
				    v-if="row.refund_price <= 0 && row.paid && row.delete_time == null"
				    @click="refund(row)"
				    >退款</a
				  >
          <a
              style="margin-left: 20px"
              v-if="row.paid === 1"
              href="javascript:void(0);"
              @click="doYeji(row)"
          >业绩分配</a
          >
				  <a
				    href="javascript:void(0);"
				    v-if="row.paid === 0"
				    @click="del(row, '删除此条储值记录', index)"
				    >删除</a
				  >
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
    </Card>
    <!-- 用户详情 -->
    <user-details ref="userDetails" fromType="order"></user-details>
    <!-- 退款表单-->
    <edit-from
      ref="edits"
      :FromData="FromData"
      @submitFail="submitFail"
    ></edit-from>
    <yeji :syncProduct="syncProduct" :yeji="setYeji" :staffIds="staffIds"  @closeYeji="closeYeji" :visible="yejiVisible" ref="yeji"></yeji>
    <remarkInfo ref="remarkInfo" :orderId="orderId" :remarkType="2"></remarkInfo>
  </div>
</template>
<script>
import cardsData from "@/components/cards/cards";
import { getYeji } from '@/api/yeji';
import searchFrom from "@/components/publicSearchFrom";
import userDetails from "@/pages/user/list/handle/userDetails";
import { mapState } from "vuex";
import exportExcel from "@/utils/newToExcel.js";
import {
  rechargelistApi,
  userRechargeApi,
  refundEditApi,
  exportUserRechargeApi,
} from "@/api/finance";
import editFrom from "@/components/from/from";
import timeOptions from "@/utils/timeOptions";
import yeji from '@/components/yeji';
import remarkInfo from '@/components/yeji/orderRemarkInfo';
export default {
  name: "recharge",
  components: { cardsData, searchFrom, editFrom, userDetails,yeji,remarkInfo },
  data() {
    return {
      staffIds:[],
      orderId: 0,
      setYeji:{
        link_id:0,
        price:0,
        goods_id:0,
        type:2,
        staffChoose:[]
      },
      syncProduct:[],
      yejiVisible: false,
      FromData: null,
      formValidate: {
        data: "",
        paid: "",
        nickname: "",
        excel: 0,
        page: 1,
        limit: 20,
      },
      formValidate2: {
        data: "",
        paid: "",
        nickname: "",
      },
      total: 0,
      cardLists: [],
      loading: false,
      columns: [
        {
          title: "ID",
          key: "id",
          sortable: true,
          width: 80,
        },
        {
          title: "头像",
          key: "avatar",
          minWidth: 80,
          render: (h, params) => {
            return h("viewer", [
              h(
                "div",
                {
                  style: {
                    width: "36px",
                    height: "36px",
                    borderRadius: "4px",
                    cursor: "pointer",
                  },
                },
                [
                  h("img", {
                    attrs: {
                      src: params.row.avatar
                        ? params.row.avatar
                        : require("../../../../assets/images/moren.jpg"),
                    },
                    style: {
                      width: "100%",
                      height: "100%",
                    },
                  }),
                ]
              ),
            ]);
          },
        },
        {
          title: "用户信息",
          slot: "nickname",
          minWidth: 120,
        },
        {
          title: "手机号",
          key: "phone",
          minWidth: 90,
        },
        {
          title: "订单号",
          key: "order_id",
          minWidth: 150,
        },
        {
          title: "储值金额",
          key: "price",
          minWidth: 100,
        },
        {
          title: "赠送金额",
          key: "give_price",
          minWidth: 100,
        },
        {
          title: "欠款金额",
          key: "debt_amount",
          minWidth: 100,
          render: (h, params) => {
            const debt = Number(params.row.debt_amount || 0);
            if (debt <= 0) {
              return h('span', '0');
            }
            const pending = Number(params.row.pending_debt_amount || 0);
            if (pending > 0 && pending < debt) {
              return h('span', [
                h('span', debt),
                h('span', { style: { color: '#ed4014', marginLeft: '4px' } }, `(待还${pending})`),
              ]);
            }
            return h('span', debt);
          },
        },
        {
          title: "是否支付",
          slot: "paid_type",
          minWidth: 100,
        },
        {
          title: "储值类型",
          slot: "recharge_type",
          minWidth: 150,
        },
        {
          title: "来源",
          key: "source_name",
          minWidth: 100,
        },
        {
          title: "支付时间",
          key: "_pay_time",
          minWidth: 120,
        },
        {
          title: "操作",
          slot: "right",
          minWidth: 100,
        },
      ],
      tabList: [],
      options: timeOptions,
      timeVal: [],
    };
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
  },
  mounted() {
    this.getList();
    this.getUserRecharge();
  },
  methods: {
    showRemark(row){
      this.orderId = row.id
      this.$refs.remarkInfo.modals=true;
      this.$refs.remarkInfo.getRemark(row.id);
    },
    closeYeji(){
      this.yejiVisible=false;
    },
    doYeji(row){
      this.setYeji.staffChoose=[];
      this.staffIds=[];
      let that=this;
      getYeji({link_id:row.id,type:1,goods_id:0,price:row.price}).then((res)=>{
        if(res.data) {
          that.setYeji = res.data;
          res.data.staffChoose.forEach(function (item){
            that.staffIds.push(item.staff_id);
          })
        }
        that.$refs.yeji.staffForm.store_id=row.store_id
        that.$refs.yeji.getStaff();
        that.yejiVisible=true;
      })
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `finance/recharge/${row.id}`,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.tabList.splice(num, 1);
          if (!this.tabList.length) {
            this.formValidate.page =
                this.formValidate.page == 1 ? 1 : this.formValidate.page - 1;
          }
					this.getUserRecharge();
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 退款
    refund(row) {
      refundEditApi(row.id)
        .then(async (res) => {
          if (res.data.status === false) {
            return this.$authLapse(res.data);
          }
          this.FromData = res.data;
          this.$refs.edits.modals = true;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 编辑提交成功
    submitFail() {
      this.getList();
      this.getUserRecharge();
    },
    // 具体日期
    onchangeTime(e) {
      this.timeVal = e;
      this.formValidate.data = this.timeVal[0] ? this.timeVal.join("-") : "";
      this.formValidate.page = 1;
      this.getList();
      this.getUserRecharge();
    },
    // 选择时间
    selectChange(tab) {
      this.formValidate.data = tab;
      this.timeVal = [];
      this.formValidate.page = 1;
      this.getList();
      this.getUserRecharge();
    },
    // 选择
    selChange() {
      this.formValidate.page = 1;
      this.getList();
      this.getUserRecharge();
    },
    // 列表
    getList() {
      this.loading = true;
      rechargelistApi(this.formValidate)
        .then(async (res) => {
          let data = res.data;
          this.tabList = data.list;
          this.total = data.count;
          this.loading = false;
        })
        .catch((res) => {
          this.loading = false;
          this.$Message.error(res.msg);
        });
    },
    // 搜索
    orderSearch() {
      this.formValidate.page = 1;
      this.getList();
    },
    pageChange(index) {
      this.formValidate.page = index;
      this.getList();
    },
    // 小方块
    getUserRecharge() {
      userRechargeApi({
        data: this.formValidate.data,
        paid: this.formValidate.paid,
        nickname: this.formValidate.nickname,
      })
        .then(async (res) => {
          let data = res.data;
          this.cardLists = data;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 数据导出；
    async exports() {
      let [th, filekey, data, fileName] = [[], [], [], ""];
      let excelData = JSON.parse(JSON.stringify(this.formValidate));
      excelData.page = 1;
      for (let i = 0; i < excelData.page + 1; i++) {
        let lebData = await this.getExcelData(excelData);
        if (!fileName) fileName = lebData.filename;
        if (!filekey.length) {
          filekey = lebData.filekey;
        }
        if (!th.length) th = lebData.header;
        if (lebData.export.length) {
          data = data.concat(lebData.export);
          excelData.page++;
        } else {
          exportExcel(th, filekey, fileName, data);
          return;
        }
      }
    },
    getExcelData(excelData) {
      return new Promise((resolve, reject) => {
        exportUserRechargeApi(excelData).then((res) => {
          return resolve(res.data);
        });
      });
    },
    // 查看用户详情
    userDetails(row) {
      this.$refs.userDetails.modals = true;
      this.$refs.userDetails.activeName = "info";
      this.$refs.userDetails.getDetails(row.uid);
    },
  },
};
</script>
<style scoped lang="stylus">
.input-add {
width: 250px;
margin-right: 14px;
display: inline-table;
}
.ivu-mt .type .item {
  margin: 3px 0;
}

.tabform {
  margin-bottom: 10px;
}

.Refresh {
  font-size: 12px;
  color: #1890FF;
  cursor: pointer;
}

.ivu-form-item {
  margin-bottom: 10px;
}

.status >>> .item~.item {
  margin-left: 6px;
}

.status >>> .statusVal {
  margin-bottom: 7px;
}

/* .ivu-mt >>> .ivu-table-header */
/* border-top:1px dashed #ddd!important */
</style>
