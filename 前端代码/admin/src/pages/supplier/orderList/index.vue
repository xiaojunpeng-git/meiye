<template>
<!-- 供应商-订单管理 -->
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding="10">
      <Form
        class="mt20"
        ref="formValidate"
        :model="formValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
		inline
      >
	    <!-- 时间选择 -->
	    <FormItem label="时间选择：" >
	      <Date-picker
	        :editable="false"
	        :clearable="true"
	        @on-change="onchangeTime"
	        :value="timeVal"
	        format="yyyy/MM/dd HH:mm:ss"
	        type="datetimerange"
	        placement="bottom-start"
	        placeholder="选择时间"
	        class="input-add"
	        :options="options"
	      ></Date-picker>
	    </FormItem>
        <FormItem label="供应商：">
          <Select v-model="formValidate.supplier_id" clearable class="input-add" @on-change="searchOrder">
            <Option
              v-for="item in supplierName"
              :value="item.id"
              :key="item.id"
              >{{ item.supplier_name }}</Option
            >
          </Select>
        </FormItem>
		<FormItem label="订单类型：">
		  <Select v-model="formValidate.type" clearable class="input-add" @on-change="searchOrder"
		    >
		    <Option
		      v-for="item in typeList"
		      :value="item.val"
		      :key="item.label"
		      >{{ item.label }}</Option
		    >
		  </Select>
		</FormItem>
		<FormItem label="订单搜索：">
		     <Input
		      v-model="formValidate.real_name"
		      placeholder="请输入"
		      element-id="name"
		      clearable
		      class="input-add"
		  >
		    <Select
		        v-model="formValidate.field_key"
				clearable
		        slot="prepend"
		        style="width:70px;"
		        default-label="全部"
		    >
		      <Option value="all">全部</Option>
		      <Option value="order_id">订单号</Option>
		      <Option value="uid">用户UID</Option>
		      <Option value="real_name">用户姓名</Option>
		      <Option value="user_phone">用户电话</Option>
		      <Option value="title">商品名称</Option>
		      <Option value="total_num">商品件数</Option>
		    </Select>
		  </Input>
		</FormItem>
		<FormItem label="支付方式：" prop="real_name" label-for="real_name">
		  <Select v-model="formValidate.pay_type" clearable class="input-add" @on-change="searchOrder"
		    >
		     <Option
		      v-for="item in payList"
		      :value="item.val"
		      :key="item.val"
		      >{{ item.label }}</Option
		    >
		  </Select>
		  <Button
		    type="primary"
		    @click="searchOrder"
		    class="ml-14"
		    >查询</Button>
		  <Button class="ml-14" @click="reset">重置</Button>
		</FormItem>
      </Form>
    </Card>
    <!-- 表格 -->
    <Card :bordered="false" dis-hover class="ivu-mt" >
	  <!-- Tab栏切换 -->
	  <div class="new_tab">
		<Tabs v-model="formValidate.status" @on-click="searchOrder">
		  <TabPane :label="'全部'" name=" "/>
		  <TabPane :label="'待发货('+(orderChartType.unshipped || 0)+')'" name="1"/>
		  <TabPane :label="'待收货'" name="2"/>
		  <TabPane :label="'待评价'" name="3"/>
		  <TabPane :label="'已完成'" name="4"/>
		  <TabPane :label="'已退款'" name="-2"/>
		</Tabs>
	  </div>
	  <div>
		<Button
		  type="primary"
		  v-auth="['export-storeOrder']"
		  size="default"
		  @click="exports"
		  >导出订单</Button
		>
	  </div>
      <!-- 订单列表表格 -->
      <vxe-table
          ref="xTable"
          class="mt25"
          :loading="loading"
          row-id="id"
          :expand-config="{accordion: true}"
          :checkbox-config="{reserve: true}"
          @checkbox-all="checkboxAll"
          @checkbox-change="checkboxItem"
          :data="orderList">
        <vxe-column type="" width="0"></vxe-column>
        <vxe-column type="expand" width="35">
          <template #content="{ row }">
            <div class="tdinfo">
              <Row class="expand-row">
                <Col span="8">
                  <span class="expand-key">商品总价：</span>
                  <span class="expand-value" v-text="row.total_price"></span>
                </Col>
                <Col span="8">
                  <span class="expand-key">下单时间：</span>
                  <span class="expand-value" v-text="row.add_time"></span>
                </Col>
                <Col span="8">
                  <span class="expand-key">推广人：</span>
                  <span class="expand-value" v-text="row.spread_nickname?row.spread_nickname:'无'"></span>
                </Col>
              </Row>
              <Row class="expand-row">
                <Col span="8">
                  <span class="expand-key">用户备注：</span>
                  <span class="expand-value" v-text="row.mark?row.mark:'无'"></span>
                </Col>
                <Col span="8">
                  <span class="expand-key">商家备注：</span>
                  <span class="expand-value" v-text="row.remark?row.remark:'无'"></span>
                </Col>
              </Row>
            </div>
          </template>
        </vxe-column>
        <vxe-column type="checkbox" width="100">
          <template #header>
            <div>
              <Dropdown transfer @on-click="allPages">
                <a href="javascript:void(0)" class="acea-row row-middle">
                  <span>全选({{isAll==1?(total-checkUidList.length):checkUidList.length}})</span>
                  <Icon type="ios-arrow-down"></Icon>
                </a>
                <template #list>
                  <DropdownMenu>
                    <DropdownItem name="0">当前页</DropdownItem>
                    <DropdownItem name="1">所有页</DropdownItem>
                  </DropdownMenu>
                </template>
              </Dropdown>
            </div>
          </template>
        </vxe-column>
        <vxe-column field="order_id" title="订单号" min-width="175">
          <template v-slot="{ row }">
            <Tooltip
      	      :transfer="true"
                theme="dark"
                max-width="300"
                :delay="600"
                content="用户已删除"
                v-if="row.is_del === 1 && row.delete_time == null"
            >
              <span style="color: #ed4014; display: block">{{ row.order_id }}</span>
            </Tooltip>
            <span
                @click="changeMenu(row, '2')"
                v-else
                style="color: #2d8cf0; display: block; cursor: pointer"
            >{{ row.order_id }}</span>
          </template>
        </vxe-column>
        <vxe-column field="pink_name" title="订单类型" min-width="120"></vxe-column>
        <vxe-column field="nickname" title="用户信息" min-width="130">
          <template v-slot="{ row }">
            <a @click="showUserInfo(row)">{{ row.nickname }}</a>
            <span style="color: #ed4014" v-if="row.delete_time != null">
            (已注销)</span>
          </template>
        </vxe-column>
        <vxe-column field="info" title="商品信息" min-width="330">
          <template v-slot="{ row }">
            <Tooltip :transfer="true" theme="dark" max-width="300" :delay="600">
              <div class="tabBox" v-for="(val, i) in row._info" :key="i">
                <div class="tabBox_img" v-viewer>
                  <img v-lazy="val.cart_info.productInfo.attrInfo? val.cart_info.productInfo.attrInfo.image: val.cart_info.productInfo.image" />
                </div>
                <span class="tabBox_tit line1">
                <span class="font-color-red" v-if="val.cart_info.is_gift"
                >赠品</span>
      
                {{ val.cart_info.productInfo.store_name + ' | ' }}
                {{val.cart_info.productInfo.attrInfo?val.cart_info.productInfo.attrInfo.suk: ''}} </span>
              </div>
              <div slot="content">
                <div v-for="(val, i) in row._info" :key="i">
                  <p class="font-color-red" v-if="val.cart_info.is_gift">赠品</p>
                  <p>{{ val.cart_info.productInfo.store_name }}</p>
                  <p> {{ val.cart_info.productInfo.attrInfo? val.cart_info.productInfo.attrInfo.suk: ''}}</p>
                  <p class="tabBox_pice">{{'￥' + val.cart_info.sum_price +' x ' + val.cart_info.cart_num }} </p>
                </div>
              </div>
            </Tooltip>
          </template>
        </vxe-column>
        <vxe-column field="pay_price" title="实际支付" align="center" min-width="70"></vxe-column>
        <vxe-column field="_pay_time" title="支付时间" min-width="150"></vxe-column>
        <vxe-column field="pay_type_name" title="支付类型" min-width="100"></vxe-column>
		<vxe-column field="supplier_name" title="供应商名称" min-width="100"></vxe-column>
        <vxe-column field="statusName" title="订单状态" min-width="100">
          <template v-slot="{ row }">
            <Tag color="default" size="medium" v-show="row.status == 3">{{
                row.status_name.status_name
              }}</Tag>
            <Tag color="orange" size="medium" v-show="row.status == 4">{{
                row.status_name.status_name
              }}</Tag>
            <Tag
                color="orange"
                size="medium"
                v-show="row.status == 1 || row.status == 2 || row.status == 5"
            >{{ row.status_name.status_name }}</Tag>
            <Tag color="red" size="medium" v-show="row.status == 0">{{
                row.status_name.status_name
              }}</Tag>
            <Tag
                color="orange"
                size="medium"
                v-if="!row.is_all_refund && row.refund.length"
            >部分退款中</Tag
            >
            <Tag
                color="orange"
                size="medium"
                v-if="row.is_all_refund && row.refund.length && row.refund_type != 6"
            >退款中</Tag
            >
            <div class="pictrue-box" size="medium" v-if="row.status_name.pics">
              <div
                  v-viewer
                  v-for="(item, index) in row.status_name.pics || []"
                  :key="index"
              >
                <img class="pictrue mr10" v-lazy="item" :src="item" />
              </div>
            </div>
          </template>
        </vxe-column>
        <vxe-column field="action" title="操作" width="140" fixed="right">
          <template v-slot="{ row }">
            <a
                @click="btnClick(row)"
                v-if="row.supplier_id!==0 && row.status_name.status_name == '未发货'"
            >提醒发货</a
            >
            <Divider
                type="vertical"
                v-if="row.supplier_id!==0 && row.status_name.status_name == '未发货'"
            />
            <a @click="changeMenu(row, '2')">详情</a>
          </template>
        </vxe-column>
      </vxe-table>
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
	<!-- 用户详情-->
	<user-details ref="userDetails" fromType="order"></user-details>
	<!-- 编辑 配送信息表单数据 退款 退积分 不退款-->
	<edit-from
	  ref="edits"
	  :FromData="FromData"
	  @submitFail="submitFail"
	></edit-from>
	<!-- 订单详情 -->
	<details-from
	  ref="detailss"
	  :orderDatalist="orderDatalist"
	  :orderId="orderId"
	  :row-active="rowActive"
	  :openErp="openErp"
	  :formType="1"
	  :distHide='1'
	></details-from>
	<!-- 备注 -->
	<order-remark
	  ref="remarks"
	  :orderId="orderId"
	  @submitFail="submitFail"
	></order-remark>
	<!-- 记录 -->
	<order-record ref="record"></order-record>
	<!-- 发送货 -->
	<order-send
	  ref="send"
	  :orderId="orderId"
	  :status="status"
	  :pay_type="pay_type"
	  @submitFail="submitFail"
	>
	</order-send>
	<Modal v-model="refundModal" title="手动退款" width="960" class-name="refund-modal" @on-visible-change="visibleChange">
	  <Form :label-width="100">
	    <FormItem label="退款金额：" required>
	      <InputNumber v-model="refundMoney" class="w-408"></InputNumber>
	    </FormItem>
		<FormItem label="退款说明：">
		  <Input v-model="refund_explain" placeholder="请输入退款说明" class="w-408"/>
		</FormItem>
	    <FormItem v-if="refundProductNum > 1" label="分单退款：">
	      <i-switch v-model="is_split_order" :true-value="1" :false-value="0" size="large">
	        <span slot="open">开启</span>
	        <span slot="close">关闭</span>
	      </i-switch>
	      <div class="tips">可选择表格中的商品单独退款，退款后且不能撤回，请谨慎操作！</div>
	      <Table v-show="is_split_order" ref="refundTable" max-height="500" :columns="refundColumns" :data="refundProduct" @on-selection-change="refundSelectionChange">
	        <template slot-scope="{ row }" slot="product">
	          <div class="image-wrap" v-viewer><img :src="row.productInfo.attrInfo.image" class="image"></div>
	          <div class="title">{{ row.productInfo.store_name }}</div>
	        </template>
	        <template slot-scope="{ row }" slot="action">
	          <InputNumber v-model="row.refundNum" :max="row.cart_num - row.refund_num" :min="1" :precision="0" controls-outside @on-change="refundNumChange(row)"></InputNumber>
	        </template>
	      </Table>
	    </FormItem>
		<FormItem label="售后入库：" v-if="orderDatalist && orderDatalist.orderInfo.status>=1">
			<RadioGroup v-model="stockInType">
			  <Radio :label="0">暂不入库</Radio>
			  <Radio :label="1">入良品库</Radio>
			  <Radio :label="2">入残次品库</Radio>
			</RadioGroup>
			<div class="tips">选择售后商品是否需要执行入库操作，若需存入不同仓库，请于入库管理模块中操作退货入库。</div>
		</FormItem>
	  </Form>
	  <div slot="footer">
	    <Button @click="cancelRefundModal">取消</Button>
	    <Button type="primary" @click="putOpenRefund">提交</Button>
	  </div>
	</Modal>
	<changePrice ref="changePrice" @submitSuccess='submitSuccess'></changePrice>
  </div>
</template>
<script>
import { mapState, mapMutations } from 'vuex'
import timeOptions from '@/utils/timeOptions'
import { getSupplierList, getList, orderChart, deliverRemind } from '@/api/supplier'
import userDetails from "@/pages/user/list/handle/userDetails";
import editFrom from "@/components/from/from";
import orderSend from "@/pages/order/orderList/handle/orderSend";
import detailsFrom from "@/pages/order/orderList/handle/orderDetails";
import orderRecord from "@/pages/order/orderList/handle/orderRecord";
import orderRemark from "@/pages/order/orderList/handle/orderRemark";
import changePrice from '@/pages/order/orderList/handle/changePrice.vue'
import exportExcel from '@/utils/newToExcel.js'
import printJS from 'print-js';
import Setting from '@/setting'
import {
  storeOrderApi,
  putWrite,
  putOpenRefund,
  getDistribution,
  writeUpdate,
  getDataInfo
} from "@/api/order";
export default {
  name: 'index',
  components: {
    userDetails,
    editFrom,
    detailsFrom,
    orderRecord,
    orderRemark,
    orderSend,
  	changePrice
  },
  props: {},
  data() {
    return {
	  roterPre: Setting.roterPre,
      options: timeOptions,
      formValidate: {
        status: '', // 订单状态
        pay_type: '', // 支付方式
        data: '', // 时间
        field_key: '', //订单搜索
        real_name: '', // 订单搜索内容
        type: '', // 订单类型
        supplier_id: '', // 供应商id
		page: 1,
		limit: 10
      },
	  total: 0, // 总条数
      grid: {
        xl: 7,
        lg: 12,
        md: 24,
        sm: 24,
        xs: 24,
      },
      timeVal: [],
      supplierName: [],
      typeList: [
        { label: '全部订单', val: '' },
        { label: '普通订单', val: '0' },
        { label: '秒杀订单', val: '1' },
        { label: '拼团订单', val: '3' },
        { label: '砍价订单', val: '2' },
        { label: '预售商品', val: '8' },
      ],
      payList: [
        { label: '全部', val: '' },
        { label: '微信支付', val: '1' },
        { label: '支付宝支付', val: '4' },
        { label: '余额支付', val: '2' },
        { label: '线下支付', val: '3' },
      ],
      orderList: [],
	  orderChartType:{},
	  loading:false,
	  isAll: 0,
	  checkUidList: [],
	  isCheckBox:false,
	  FromData: null,
	  orderDatalist: null,
	  orderId: 0,
	  rowActive: {},
	  openErp:false,
	  status: 0, //发货状态判断
	  pay_type: "",
	  refundModal: false,
	  refundColumns: [
	    {
	      type: 'selection',
	      width: 60,
	      align: 'center'
	    },
	    {
	      title: '商品信息',
	      width: 210,
	      slot: 'product'
	    },
	    {
	      title: '规格',
	      render: (h, params) => {
	        return h('div', params.row.productInfo.attrInfo.suk);
	      }
	    },
	    {
	      title: '售价',
	      render: (h, params) => {
	        return h('div', params.row.productInfo.attrInfo.price);
	      }
	    },
	    {
	      title: '实付金额',
	      key: 'pay_price'
	    },
	    {
	      title: '总数',
	      key: 'cart_num'
	    },
	    {
	      title: '退款数量',
	      slot: 'action',
	      width: 160,
	    }
	  ],
	  refundProduct: [],
	  refundSelection: [],
	  refundMoney: 0,
	  is_split_order: 0,
	  refund_explain:'',
	  delfromData:{},
	  orderConNum: 0,
	  orderConId: 0,
	  stockInType: 0
    }
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 96
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right'
    },
	refundProductNum() {
	  return this.refundProduct.reduce((total, { refundNum }) => (total + refundNum), 0);
	}
  },
  watch:{
  	refundSelection: {
  	  handler(value) {
  	    this.refundMoney = value.reduce((total, { refundPrice, refundNum }) => {
  	      return this.$computes.Add(total, this.$computes.Mul(refundPrice, refundNum));
  	    }, 0);
  	  },
  	  deep: true
  	},
  	is_split_order(value) {
  	  this.$nextTick(() => {
  	    this.$refs.refundTable.selectAll(!!value);
  	  });
  	},
  	refundMoney(value) {
  	  this.$nextTick(() => {
  	    if (typeof value != 'number') {
  	      return;
  	    }
  	    if (parseFloat(value) == parseInt(value)) {
  	      return;
  	    }
  	    if (value.toString().length - (value.toString().indexOf('.') + 1) > 2) {
  	      this.refundMoney = Number(value.toFixed(2));
  	    }
  	  });
  	},
  },
  created() {
    this.getSupplierList()
    this.getList()
  },
  mounted() {},
  methods: {
	// 提醒发货
	btnClick(row) {
	  let data = {
	    supplier_id: row.supplier_id,
	    id: row.id,
	  }
	  deliverRemind(data)
	    .then(async (res) => {
	      this.$Message.success(res.msg)
		  this.getList()
	    })
	    .catch((res) => {
	      this.$Message.error(res.msg)
	      if (res.status == '400') {
	        row.remind = 2
	      }
	    })
	},
	// 订单改价
	edit(row) {
	  this.$refs.changePrice.id = row.id;
	  this.$refs.changePrice.ordeUpdateInfo(row.id);
	  this.$refs.changePrice.priceModals = true;
	},
	submitSuccess(res){
	   if (res.data.status === false) {
	     return this.$authLapse(res.data)
	   }
	   this.$authLapse(res.data)
	   this.FromData = res.data
	   this.$refs.edits.modals = true
	},
	visibleChange(visible) {
	  this.is_split_order = 0;
	  this.stockInType = 0;
	  if (!visible) {
	    this.refundSelection = [];
	  }
	},
	cancelRefundModal() {
	  this.refundModal = false;
	},
	changeMenu(row, name, num) {
	  this.orderId = row.id
	  this.orderConId = row.pid > 0 ? row.pid : row.id
	  this.orderConNum = num
	  switch (name) {
	    case '1':
	      this.delfromData = {
	        title: '确认收款',
	        url: `/order/pay_offline/${row.id}`,
	        method: 'post',
	        ids: '',
	      }
	      this.$modalSure(this.delfromData)
	        .then((res) => {
	          this.$Message.success(res.msg)
	          this.getData(row.id, 1)
	          this.getList()
	        })
	        .catch((res) => {
	          this.$Message.error(res.msg)
	        })
	      break
		case '2':
		  this.rowActive = row
		  this.getData(row.id)
		  break
		case '3':
		  this.$refs.record.modals = true
		  this.$refs.record.getList(row.id)
		  break
	    case '4':
	      this.$refs.remarks.formValidate.remark = row.remark
	      this.$refs.remarks.modals = true
	      break
	    case '5':
	      this.getOnlyrefundData(row.id, row.refund_type, row)
	      break
	    case '8':
		  if (row.refund.length){
			  return this.$Message.error("该订单有售后处理中，请先处理售后申请");
		  }
	      this.delfromData = {
	        title: '修改确认收货',
	        url: `/order/take/${row.id}`,
	        method: 'put',
	        ids: '',
	      }
	      this.$modalSure(this.delfromData)
	        .then((res) => {
	          this.$Message.success(res.msg)
	          this.getList()
	          if (num) {
	            this.$refs.detailss.getSplitOrder(row.pid)
	          } else {
	            this.getData(row.id, 1)
	          }
	        })
	        .catch((res) => {
	          this.$Message.error(res.msg)
	        })
	      break
	    case '10':
	      this.delfromData = {
	        title: '立即打印订单',
	        info: '您确认打印此订单吗?',
	        url: `/order/print/${row.id}`,
	        method: 'get',
	        ids: '',
	      }
	      this.$modalSure(this.delfromData)
	        .then((res) => {
	          this.$Message.success(res.msg)
	          this.getList()
	        })
	        .catch((res) => {
	          this.$Message.error(res.msg)
	        })
	      break
	    case '11':
	      this.delfromData = {
	        title: '立即打印电子面单',
	        info: '您确认打印此电子面单吗?',
	        url: `/order/order_dump/${row.id}`,
	        method: 'get',
	        ids: '',
	      }
	      this.$modalSure(this.delfromData)
	        .then((res) => {
	          this.$Message.success(res.msg)
	          this.getList()
	        })
	        .catch((res) => {
	          this.$Message.error(res.msg)
	        })
	      break
		case '12':
		  let pathInfo = this.$router.resolve({
		    path: this.roterPre + '/supplier/order/distribution',
		    query: {
		      id: row.id,
		      status: 2,
		    },
		  })
		  window.open(pathInfo.href, '_blank')
		  break
		case '13':
		  this.printImg(row.kuaidi_label);
		  break;  
	    default:
	      this.delfromData = {
	        title: '删除订单',
	        url: `/order/del/${row.id}`,
	        method: 'DELETE',
	        ids: '',
	      }
	      this.delOrder(row, this.delfromData)
	  }
	},
	// 删除单条订单
	delOrder(row, data) {
	  if (row.is_del === 1) {
	    this.$modalSure(data)
	      .then((res) => {
	        this.$Message.success(res.msg)
	        this.getList()
	        this.$refs.detailss.modals = false
	      })
	      .catch((res) => {
	        this.$Message.error(res.msg)
	      })
	  } else {
	    const title = '错误！'
	    const content =
	      '<p>您选择的的订单存在用户未删除的订单，无法删除用户未删除的订单！</p>'
	    this.$Modal.error({
	      title: title,
	      content: content,
	    })
	  }
	},
	// 仅退款
	getOnlyrefundData(id, refund_type, rowActive) {
    const cartInfo = rowActive.cart_info;
    cartInfo.forEach((value) => {
      value.refundPrice = this.$computes.Div(value.refund_price, value.cart_num);
      value.refundNum = value.cart_num - value.refund_num;
      value._disabled = !value.refundNum;
    });
    this.refundProduct = cartInfo;
    this.refundSelection = cartInfo;
	  this.refundModal = true;
	},
	putOpenRefund() {
	  let data = {
	    id: this.orderId,
	    refund_price: this.refundMoney,
	    type: 1,
	    is_split_order: this.is_split_order,
		refund_explain: this.refund_explain,
		stock_in_type: this.stockInType
	  };
	  if (this.is_split_order) {
	    if (!this.refundSelection.length) {
	      return this.$Message.error('请选择需要退款的商品');
	    }
	    data.cart_ids = this.refundSelection.map(({ id, refundNum }) => ({
	      cart_id: id,
	      cart_num: refundNum
	    }));
	  }
	  putOpenRefund(data).then(res => {
	    this.$Message.success(res.msg);
	    this.refundModal = false;
		this.getList();
	    this.getData(this.orderDatalist.orderInfo.id);
	  }).catch(err => {
	    this.$Message.error(err.msg);
	  });
	},
	refundSelectionChange(selection) {
	  this.refundSelection = selection;
	},
	refundNumChange({ id, refundNum }) {
	  let result = this.refundSelection.find(item => item.id === id);
	  if (result) {
	    result.refundNum = refundNum;
	  }
	},
	// 获取详情表单数据
	getData(id, type) {
	  getDataInfo(id)
	    .then(async (res) => {
			console.log('44444');
	      if (!type) {
	        this.$refs.detailss.modals = true;
	      }
	      this.$refs.detailss.activeName = "detail";
	      this.orderDatalist = res.data;
	      if (this.orderDatalist.orderInfo.refund_reason_wap_img) {
	        try {
	          this.orderDatalist.orderInfo.refund_reason_wap_img = JSON.parse(
	            this.orderDatalist.orderInfo.refund_reason_wap_img
	          );
	        } catch (e) {
	          this.orderDatalist.orderInfo.refund_reason_wap_img = [];
	        }
	      }
	    })
	    .catch((res) => {
	      this.$Message.error(res.msg);
	    });
	},
	// 修改成功(编辑只有未支付时出现)
	submitFail() {
	  this.status = 0
	  this.getList()
	  if (this.orderConNum != 1) {
	    this.getData(this.orderId, 1)
	  } else {
	    this.$refs.detailss.getSplitOrder(this.orderConId)
	  }
	},
	// 发送货
	sendOrder(row, num) {
	  this.orderConId = row.pid
	  this.orderConNum = num
	  this.$store.commit("admin/order/setSplitOrder", row.total_num);
	  this.$refs.send.modals = true;
	  this.$refs.send.activeRow = row;
	  this.orderId = row.id;
	  this.status = row._status;
	  this.pay_type = row.pay_type;
	  this.$refs.send.getList();
	  this.$refs.send.getDeliveryList();
	  this.$nextTick((e) => {
	    this.$refs.send.getCartInfo(row._status, row.id);
	  });
	},
	// 配送信息表单数据
	delivery(row, num) {
	  getDistribution(row.id)
	    .then(async (res) => {
	      this.orderConNum = num
	      this.orderConId = row.pid
	      this.FromData = res.data
	      this.$refs.edits.modals = true
	      if (num != 1) {
	        this.getData(this.orderId, 1)
	      }
	    })
	    .catch((res) => {
	      this.$Message.error(res.msg)
	    })
	},
	// 详情
	showUserInfo(row) {
	  this.$refs.userDetails.modals = true;
	  this.$refs.userDetails.activeName = "info";
	  this.$refs.userDetails.getDetails(row.uid);
	},
	//修改增加打印方法
	printImg(url) {
	  printJS({
	    printable: url,
	    type: 'image',
	    documentTitle: '快递信息',
	    style: `img{
	      width: 100%;
	      height: 476px;
	    }`,
	  });
	},
	pageChange(index) {
	  this.formValidate.page = index;
	  this.getList();
	},
	checkboxAll(){
	  // 获取选中当前值
	  let obj2 = this.$refs.xTable.getCheckboxRecords(true);
	  // 获取之前选中值
	  let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
	  if(this.isAll == 0 && this.checkUidList.length <= obj.length && !this.isCheckBox){
	  	 obj = [];
	  }
	  obj = obj.concat(obj2);
	  let ids = [];
	  obj.forEach((item)=>{
	    ids.push(parseInt(item.id))
	  })
	  this.checkUidList = ids;
	  if(!obj2.length){
	    this.isCheckBox = false;
	  }
	},
	checkboxItem(e){
	  let id = parseInt(e.rowid);
	  let index = this.checkUidList.indexOf(id);
	  if(index !== -1){
	    this.checkUidList = this.checkUidList.filter((item)=> item !== id);
	  }else{
	    this.checkUidList.push(id);
	  }
	},
	allPages(e){
	  this.isAll = e;
	  if(e==0){
	    this.$refs.xTable.toggleAllCheckboxRow();
	  }else{
	    if(!this.isCheckBox){
	      this.$refs.xTable.setAllCheckboxRow(true);
	      this.isCheckBox = true;
	      this.isAll = 1;
	    }else{
	      this.$refs.xTable.setAllCheckboxRow(false);
	      this.isCheckBox = false;
	      this.isAll = 0;
	    }
	    this.checkUidList = []
	  }
	},
	// 订单头部数据
	getChart() {
	  orderChart(this.formValidate)
	    .then((res) => {
	      this.orderChartType = res.data;
	    })
	    .catch((err) => {
	      this.$Message.error(err.msg);
	    });
	},
    searchOrder(){
	  this.formValidate.page = 1;
	  this.isAll = 0;
	  this.isCheckBox = false;
	  this.$refs.xTable.setAllCheckboxRow(false);
	  this.checkUidList = [];
	  this.getList();
	},
    // 选择时间
    onchangeTime(e) {
      if (e[1].slice(-8) === '00:00:00') {
        e[1] = e[1].slice(0, -8) + '23:59:59'
        this.timeVal = e
      } else {
        this.timeVal = e
      }
      this.formValidate.data = this.timeVal[0] ? this.timeVal.join('-') : ''
	  this.searchOrder();
    },
	
    // 获取供应商内容
    getSupplierList() {
      getSupplierList()
        .then(async (res) => {
          this.supplierName = res.data
        })
        .catch((res) => {
          this.$Message.error(res.msg)
        })
    },

    // 获取订单列表
    getList() {
	  this.loading = true;
      getList(this.formValidate)
        .then(async (res) => {
		  let data = res.data;
		  data.data.forEach((item) => {
		    if (item.id == this.orderId) {
		      this.rowActive = item;
		    }
		  });
          this.orderList = data.data
          this.total = res.data.count
		  this.loading = false;
		  this.getChart();
		  this.$nextTick(function(){
		    if (this.isAll == 1) {
		      if(this.isCheckBox){
				let flag = false;
				data.data.forEach(item=>{
					this.checkUidList.forEach(j=>{
						if(item.id == j){
							flag = true;
						}
					})
				})
				if(!flag){
					this.$refs.xTable.setAllCheckboxRow(true);
				}
		      }else{
		        this.$refs.xTable.setAllCheckboxRow(false);
		      }
		    }else{
			  let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
			  if(!this.checkUidList.length || this.checkUidList.length <= obj.length){
			    this.$refs.xTable.setAllCheckboxRow(false);
			  }
			}
		  })
        })
        .catch((res) => {
		  this.loading = false;
          this.$Message.error(res.msg)
        })
    },
    // 重置
    reset() {
      this.formValidate = {}
      this.formValidate.data = ''
      this.timeVal = []
      this.formValidate.page = 1;
      this.getList()
    },
	async exports(value) {
	  let [th, filekey, data, fileName] = [[], [], [], '']
	  let excelData = {
	    ...this.formValidate,
      page: 1,
	    export_type: 0,
	    ids: this.checkUidList.join(),
	    plat_type: 2,
	  }
	  for (let i = 0; i < excelData.page; i++) {
	    let lebData = await this.downOrderData(excelData)
	    if (!lebData.export.length) {
	      break;
	    }
	    if (!fileName) {
	      fileName = lebData.filename
	    }
	    if (!filekey.length) {
	      filekey = lebData.filekey
	    }
	    if (!th.length) {
	      th = lebData.header
	    }
	    data = data.concat(lebData.export)
	    excelData.page++
	  }
	  let sheetData = []
	  for (let j = 0; j < data.length; j++) {
	    let goodsList = data[j].goods_name.split('\n')
	    for (let k = 0; k < goodsList.length; k++) {
	      let row = {...data[j]}
	      row.goods_name = goodsList[k]
	      if (k) {
	        for (const key in row) {
	          if (Object.hasOwnProperty.call(row, key)) {
	            if (key !== 'goods_name') {
	              row[key] = null
	            }
	          }
	        }
	      }
	      sheetData.push(row)
	    }
	  }
	  exportExcel(th, filekey, fileName, sheetData)
	},
	downOrderData(excelData) {
	  return new Promise((resolve, reject) => {
	    storeOrderApi(excelData).then((res) => {
	      return resolve(res.data)
	    })
	  })
	}
  },
}
</script>
<style scoped lang="stylus">
/deep/.ivu-dropdown-item{
  font-size: 12px!important;
}
/deep/.vxe-table--render-default .vxe-cell{
  font-size: 12px;
}
.tdinfo{
  margin-left: 75px;
  margin-top: 16px;
}
.expand-row{
  margin-bottom: 16px;
  font-size: 12px;
}
.ivu-tag-orange{
  color #fa8c16;
}
img {
  height: 36px;
  display: block;
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
    width: 290px;
    height: 30px;
    line-height: 30px;
    font-size: 12px !important;
    margin: 0 2px 0 10px;
    letter-spacing: 1px;
    box-sizing: border-box;
  }
}

.tabBox +.tabBox {
  margin-top: 5px;
}

.vertical-center-modal {
  display: flex;
  align-items: center;
  justify-content: center;
}

/deep/.select-item:hover {
  background-color: #f3f3f3;
}

/deep/.select-on {
  display: block;
}

/deep/.select-item.on {
  /* background: #f3f3f3; */
}

.pictrue-box {
  display: flex;
  align-item: center;
}

.pictrue {
  width: 25px;
  height: 25px;
}

.trip {
  color: orange;
}

.new_tab {
  >>>.ivu-tabs-nav .ivu-tabs-tab {
    padding: 4px 16px 20px !important;
    font-weight: 500;
  }
}
>>> .ivu-table-fixed-body {
  background-color: #f8f8f9;
}
>>>.ivu-table th {
  overflow: visible;
}

>>>.refund-modal {
  .ivu-input-number-controls-outside {
    width: 105px;
    height: 28px;
    border: 0;
    border-radius: 0;
    background-color: transparent;
    font-size: 16px;
    line-height: 28px;
    box-shadow: none;
  }

  .ivu-input-number-controls-outside:focus {
    box-shadow: none;
  }

  .ivu-input-number-controls-outside-btn {
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 50%;
    background-color: #1890FF;
    line-height: 28px;
    color: #FFFFFF;
  }

  .ivu-input-number-input-wrap {
    height: 28px;
  }

  .ivu-input-number-controls-outside .ivu-input-number-input {
    height: 28px;
    background-color: transparent;
    text-align: center;
    line-height: 28px;
  }

  .ivu-input-number-controls-outside-btn i {
    font-weight: bold;
  }

  .ivu-input-number-controls-outside-btn:hover i {
    color: inherit;
  }

  .ivu-input-number-controls-outside-btn-disabled, .ivu-input-number-controls-outside-btn-disabled:hover {
    background-color: #F5F5F5;
  }

  .ivu-input-number-controls-outside-btn-disabled i, .ivu-input-number-controls-outside-btn-disabled:hover i {
    color: rgba(0, 0, 0, 0.85);
  }

  .tips {
    padding: 12px 0 23px;
    font-size: 12px;
    line-height: 14px;
    color: #999999;
  }

  .ivu-modal-footer {
    padding-bottom: 30px;
    border: 0;
    text-align: center;
  }

  .ivu-modal-footer button + button {
    margin-left: 20px;
  }

  .ivu-btn {
    height: 46px;
    padding: 0 71px;
    border-color: #F5F5F5;
    border-radius: 23px;
    background-color: #F5F5F5;
    font-size: 16px !important;
    color: #666666;
  }

  .ivu-btn:focus {
    box-shadow: none;
  }

  .ivu-btn-primary {
    border-color: #1890FF;
    background-color: #1890FF;
    color: #FFFFFF;
  }

  .ivu-form .ivu-form-item-label {
    font-size: 13px !important;
  }

  .ivu-table {
    font-size: 14px !important;
    line-height: 20px;
  }

  .image-wrap {
    float: left;
  }

  .image {
    width: 46px;
    height: 46px;
  }

  .title {
    margin-left: 52px;
  }
}
</style>