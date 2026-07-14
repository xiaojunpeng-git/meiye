<template>
  <div class="goodList">
    <Form
      ref="formValidate"
      :model="formValidate"
      :label-width="labelWidth"
      :label-position="labelPosition"
      inline
      class="tabform"
    >
	  <!-- <FormItem label="售后类型：">
	    <Select v-model="formValidate.apply_type" class="input-add" clearable @on-change="orderSearch">
	      <Option
	        v-for="(item, index) in applyType"
	        :value="item.type"
	        :key="item.type"
	        >{{ item.name }}</Option
	      >
	    </Select>
	  </FormItem>
	  <FormItem label="售后状态：">
	    <Select v-model="formValidate.refund_type" class="input-add" clearable @on-change="orderSearch">
	      <Option
	        v-for="(item, index) in refundType"
	        :value="index"
	        :key="index"
	        >{{ item.name }}</Option
	      >
	    </Select>
	  </FormItem> -->
      <FormItem label="售后搜索：" label-for="order_id">
        <Input
          placeholder="请输入商品信息/用户信息/订单号/售后单号"
          v-model="formValidate.order_id"
          class="input-add mr14"
        />
        <Button type="primary" @click="orderSearch">查询</Button>
		<Button class="ml10" @click="reset">重置</Button>
      </FormItem>
    </Form>
    <Table
      ref="table"
      no-data-text="暂无数据"
      no-filtered-data-text="暂无筛选结果"
      :columns="columns"
      :data="tableList"
      :loading="loading"
      class="mr-20"
      max-height="500"
    >
      <template slot-scope="{ row }" slot="order_id">
        <span v-text="row.order_id" style="display: block"></span>
        <span v-show="row.is_del === 1 && row.delete_time == null" class="span-del"
          >用户已删除</span
        >
      </template>
	  <template slot-scope="{ row }" slot="nickname">
	    <div>{{ row.nickname }}<span style="color: #ed4014;" v-if="row.delete_time != null"> (已注销)</span></div>
	  </template>
      <template slot-scope="{ row }" slot="apply_type">
        <Tag color="blue" size="medium" v-if="row.apply_type == 1">仅退款</Tag>
        <Tag color="blue" size="medium" v-if="row.apply_type == 2">退货退款(快递退回)</Tag>
        <Tag color="blue" size="medium" v-if="row.apply_type == 3">退货退款(到店退货)</Tag>
        <Tag color="blue" size="medium" v-if="row.apply_type == 4">商家主动退款</Tag>
      </template>
      <template slot-scope="{ row }" slot="refund_type">
        <Tag color="blue" size="medium" v-if="[0, 1, 2].includes(row.refund_type)">待处理</Tag>
        <Tag color="red" size="medium" v-if="row.refund_type == 3">拒绝退款</Tag>
        <Tag color="blue" size="medium" v-if="row.refund_type == 4">商品待退货</Tag>
        <Tag color="blue" size="medium" v-if="row.refund_type == 5">退货待收货</Tag>
        <Tag color="green" size="medium" v-if="row.refund_type == 6">已退款</Tag>
      </template>
      <template slot-scope="{ row }" slot="info">
        <Tooltip theme="dark" max-width="300" :delay="600">
        <div class="tabBox" v-for="(val, i) in row._info" :key="i">
          <div class="tabBox_img" v-viewer>
            <img
              v-lazy="
                val.cart_info.productInfo.attrInfo
                  ? val.cart_info.productInfo.attrInfo.image
                  : val.cart_info.productInfo.image
              "
            />
          </div>
          <span class="tabBox_tit line1">
            <span class="font-color-red" v-if="val.cart_info.is_gift">赠品</span>
            {{ val.cart_info.productInfo.store_name + " | "}}
            {{val.cart_info.productInfo.attrInfo ? val.cart_info.productInfo.attrInfo.suk: ""}}
          </span>
        </div>
        <div slot="content">
          <div v-for="(val, i) in row._info" :key="i">
            <p class="font-color-red" v-if="val.cart_info.is_gift">赠品</p>
            <p>{{ val.cart_info.productInfo.store_name }}</p>
            <p> {{val.cart_info.productInfo.attrInfo ? val.cart_info.productInfo.attrInfo.suk: ""}}</p>
            <p class="tabBox_pice">{{ "￥" + val.cart_info.truePrice + " x " + val.cart_info.cart_num }}</p>
          </div>
        </div>
      </Tooltip>
      </template>
      <template slot-scope="{ row }" slot="statusName">
        <Tooltip theme="dark" max-width="300" :delay="600">
          <div v-html="row.refund_reason" class="pt5"></div>
          <div slot="content">
            <div  class="pt5">退款原因：{{row.refund_explain}}</div>
            <div v-if="row.refund_goods_explain" class="pt5">退货原因：{{row.refund_goods_explain}}</div>
          </div>
        </Tooltip>
        <div class="pictrue-box" v-if="row.refund_img">
          <div
            v-viewer
            v-for="(item, index) in row.refund_img || []"
            :key="index"
          >
            <img class="pictrue mr10" v-lazy="item" :src="item" />
          </div>
        </div>
      </template>
    </Table>
    <div class="acea-row row-right page">
      <Page
        :total="total"
        show-elevator
        show-total
        @on-change="pageChange"
        :page-size="formValidate.limit"
      />
    </div>
  </div>
</template>

<script>
import { mapState } from "vuex";
import { orderRefundList } from "@/api/order";
export default {
  name: "index",
  data() {
    return {
		grid: {
		  xl: 10,
		  lg: 10,
		  md: 12,
		  sm: 24,
		  xs: 24,
		},
		applyType:[
			{name:'仅退款',type:1},
			{name:'退货退款(快递退回)',type:2},
			{name:'退货退款(到店退货)',type:3},
			{name:'平台退款',type:4}
		],
		columns: [
		  {
		    title: "选择",
		    width: 70,
		    align: "center",
		    render: (h, params) => {
		      let id = params.row.id;
		      let flag = false;
		      if (this.currentid === id) {
		        flag = true;
		      } else {
		        flag = false;
		      }
		      let self = this;
		      return h("div", [
		        h("Radio", {
		          props: {
		            value: flag,
		          },
		          on: {
		            "on-change": () => {
		              self.currentid = id;
		              this.productRow = params.row;
		              this.$emit("getOrderId", this.productRow);
		              if (this.productRow.id) {
		                if (this.$route.query.fodder === "image") {
		                  /* eslint-disable */
		                  let imageObject = {
		                    image: this.productRow.image,
		                    product_id: this.productRow.id,
		                    name: this.productRow.name,
		                  };
		                  form_create_helper.set("image", imageObject);
		                  form_create_helper.close("image");
		                }
		              } else {
		                this.$Message.warning("请先选择商品");
		              }
		            },
		          },
		        }),
		      ]);
		    },
		  },
		  {
		    title: "售后订单",
		    slot: "order_id",
		    minWidth: 150,
		  },
		  {
		    title: "用户信息",
		    slot: "nickname",
		    minWidth: 130,
		  },
		  {
		    title: "商品信息",
		    slot: "info",
		    minWidth: 300,
		  },
		  {
		    title: "实际支付",
		    key: "pay_price",
		    minWidth: 70,
		  },
		  {
		    title: "售后类型",
		    slot: "apply_type",
		    minWidth: 160,
		  },
		  {
		    title: "售后状态",
		    slot: "refund_type",
		    minWidth: 120,
		  },
		  {
		    title: "退款信息",
		    slot: "statusName",
		    minWidth: 100,
		  }
		],
		formValidate: {
		  page: 1,
		  limit: 10,
		  apply_type: '',
		  refund_type: '',
		  order_id: '',
		  is_stock_order:1
		},
		total: 0,
		loading: false,
		tableList: [],
		refundType:[],
		currentid: 0,
		productRow: {}
    };
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 90;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
  },
  created() {},
  mounted() {
    this.getOrderList();
  },
  methods: {
	  // 订单列表
	  getOrderList() {
	    this.loading = true;
	    orderRefundList(this.formValidate)
	      .then((res) => {
	        this.loading = false;
	        const { count, list, num } = res.data;
	        this.total = count;
	        this.tableList = list;
	        this.refundType = num;
	      })
	      .catch((err) => {
	        this.loading = false;
	        this.$Message.error(err.msg);
	      });
	  },
	  pageChange(index) {
	    this.formValidate.page = index;
	    this.getOrderList();
	  },
	  // 表格搜索
	  orderSearch() {
	    this.formValidate.page = 1;
	    this.getOrderList();
	  },
	  reset(){
		  this.formValidate = {
		    page: 1,
		    limit: 10,
		    apply_type: '',
		    refund_type: '',
		    order_id: '',
			is_stock_order:1
		  }
		  this.getOrderList();
	  }
  },
};
</script>

<style scoped lang="stylus">
.ivu-table td:nth-of-type(1){
	padding-left: 0 !important
}
/deep/.ivu-table-header thead tr th{
  padding: 8px 5px;
}
/deep/.ivu-radio-wrapper{
  margin-right: 0 !important;
}
.footer {
  margin: 15px 0;
}

.tabBox {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;

  .tabBox_img {
    width: 30px;
    height: 30px;

    img {
      width: 100%;
      height: 100%;
    }
  }

  .tabBox_tit {
    width:245px;
    height:30px;
    line-height:30px;
    font-size: 12px !important;
    margin: 0 2px 0 10px;
    letter-spacing: 1px;
    box-sizing: border-box;
  }
}

.tabBox +.tabBox{
  margin-top:5px;
}

.tabform {
  >>> .ivu-form-item {
    margin-bottom: 16px !important;
  }
}

.btn {
  margin-top: 20px;
  float: right;
}

.mr-20{
  margin-right:10px;
}
</style>
