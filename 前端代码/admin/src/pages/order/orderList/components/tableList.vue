<template>
<!-- 订单列表-表格组件 -->
  <div>
    <!-- tab切换栏 -->
    <div class="new_tab">
      <Tabs v-model="orderDataStatus" @on-click="selectChange2">
        <TabPane :label="'全部'" name=" "/>
        <TabPane :label="'待支付('+(tablists.unpaid || 0)+')'" name="0"/>
        <TabPane :label="'待发货('+(tablists.unshipped || 0)+')'" name="1"/>
        <TabPane :label="'待收货'" name="2"/>
        <TabPane :label="'待评价'" name="3"/>
        <TabPane :label="'已完成'" name="4"/>
        <TabPane :label="'已退款'" name="-2"/>
      </Tabs>
    </div>
    <div class="acea-row row-between">
      <!-- 相关操作 -->
      <div>
        <Tooltip
          content="本页至少选中一项"
          :disabled="!!checkUidList.length && isAll==0"
          v-auth="['order-batch-del_orders']"
        >
          <Button
            class="mr10"
            type="primary"
            :disabled="!checkUidList.length && isAll==0"
            @click="delAll"
            >批量删除订单</Button
          >
        </Tooltip>
        <Button
          class="mr10"
          v-auth="['order-hand-batch_delivery']"
          type="primary"
          @click="manualModal = true"
          >手动批量发货</Button
        >
        <Tooltip
          content="本页至少选中一项"
          :disabled="!!checkUidList.length && isAll==0"
          v-auth="['order-other-batch_delivery']"
        >
          <Button
            class="mr10"
            type="primary"
            :disabled="!checkUidList.length && isAll==0"
            @click="onAuto"
            >自动批量发货</Button
          >
        </Tooltip>
        <Tooltip
          content="本页至少选中一项"
          :disabled="!!checkUidList.length && isAll==0"
        >
          <Button
            class="mr10"
            type="primary"
            :disabled="!checkUidList.length && isAll==0"
            @click="printOreder"
            >打印配货单</Button
          >
        </Tooltip>
        <Tooltip
          content="请勾选要导出的订单；表头全选选「所有页」则按当前筛选条件导出"
          :disabled="!!checkUidList.length || isAll == 1"
        >
          <Dropdown
            v-auth="['export-storeOrder']"
            class="mr10"
            :disabled="!checkUidList.length && isAll == 0"
            @on-click="exports"
          >
            <Button style="width: 110px" :disabled="!checkUidList.length && isAll == 0">
              {{ exportList[exportListOn].label }}
              <Icon type="ios-arrow-down"></Icon>
            </Button>
          <DropdownMenu slot="list">
            <DropdownItem
              v-for="(item, index) in exportList"
              :key="index"
              :name="item.name"
              style="font-size: 12px !important"
              >{{ item.label }}</DropdownItem
            >
          </DropdownMenu>
        </Dropdown>
        </Tooltip>
      </div>
      <div class="caozuo">
        <Button v-auth="['queue-index']" class="mr10" @click="queuemModal"
          >批量发货记录</Button
        >
        <Button
          v-auth="['export-expressList']"
          class="mr10"
          @click="getExpressList"
          >下载物流公司对照表</Button
        >
      </div>
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
              <Col span="6">
                <span class="expand-key">商品总价：</span>
                <span class="expand-value" v-text="row.total_price"></span>
              </Col>
              <Col span="6">
                <span class="expand-key">推广人：</span>
                <span class="expand-value" v-text="row.spread_nickname?row.spread_nickname:'无'"></span>
              </Col>
              <Col span="6">
                <span class="expand-key">配送方式：</span>
                <span class="expand-value" v-text="row.delivery_name?row.delivery_name:'无'"></span>
              </Col>
              <Col span="6">
                <span class="expand-key">用户备注：</span>
                <span class="expand-value" v-text="row.mark?row.mark:'无'"></span>
              </Col>
            </Row>
            <Row class="expand-row">
              <Col span="6">
                <span class="expand-key">商家备注：</span>
                <span class="expand-value" v-text="row.remark?row.remark:'无'"></span>
              </Col>
              <Col span="6">
                <span class="expand-key">下单时间：</span>
                <span class="expand-value" v-text="row.add_time"></span>
              </Col>
              <Col span="6">
                <span class="expand-key">确认收货时间：</span>
                <span class="expand-value" v-text="row.take_time"></span>
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
                <span>全选({{isAll==1?(page.total-checkUidList.length):checkUidList.length}})</span>
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
          <div v-if="row.order_type == 1" class="tabBox">
            <span class="tabBox_tit line1">{{ row.recharge_list_label || ((row.service_object_label ? '[' + row.service_object_label + ']' : '[本人]') + '充值订单') }}</span>
          </div>
          <div v-else-if="row.order_type == 2" class="tabBox">
            <div class="tabBox_img" v-viewer v-if="row.link_img">
              <img v-lazy="row.link_img" />
            </div>
            <span class="tabBox_tit line1">{{ row.link_name }}</span>
          </div>
          <Tooltip v-else :transfer="true" theme="dark" max-width="300" :delay="600">
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
      <vxe-column field="pay_price" title="金额" align="center" min-width="90">
        <template v-slot="{ row }">
          <div>¥{{ getOrderTotal(row) }}</div>
          <a
            v-if="getOrderPendingDebt(row) > 0"
            class="order-debt-link"
            href="javascript:void(0)"
            @click.prevent="openOrderDebtDetail(row)"
          >(欠款 ¥{{ getOrderPendingDebt(row).toFixed(2) }})</a>
        </template>
      </vxe-column>
      <vxe-column field="_pay_time" title="支付时间" min-width="150"></vxe-column>
      <vxe-column field="pay_type_name" title="支付类型" min-width="100">
        <template v-slot="{ row }">
          <span>{{ row.pay_type_name }}</span>
        </template>
      </vxe-column>
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
	  <vxe-column field="mark" title="买家备注" min-width="150"></vxe-column>
	  <vxe-column field="remark" title="商家备注" min-width="150"></vxe-column>
      <vxe-column field="action" title="操作" width="140" fixed="right">
        <template v-slot="{ row }">
          <a
              :disabled="openErp"
              @click="sendOrder(row)"
              v-if="
            (row._status === 2 || row._status === 8 || row.status === 4) &&
            row.shipping_type === 1 &&
            (row.pinkStatus === null || row.pinkStatus === 2) &&
            row.delete_time == null &&
            row.store_id === 0 &&
            row.supplier_id === 0
          "
          >发送货</a
          >
          <a
              :disabled="openErp"
              @click="btnClick(row)"
              v-if="row.supplier_id!==0 && row.status_name.status_name == '未发货'"
          >提醒发货</a
          >
          <Divider
              type="vertical"
              v-if="row.supplier_id!==0 && row.status_name.status_name == '未发货'"
          />
          <!--    -->
          <Divider
              type="vertical"
              v-if="
            (row._status === 2 || row._status === 8 || row.status === 4) &&
            row.shipping_type === 1 &&
            (row.pinkStatus === null || row.pinkStatus === 2) &&
            row.delete_time == null &&
            row.store_id === 0 &&
            row.supplier_id === 0
          "
          />
          <a @click="changeMenu(row, '2')">详情</a>
        </template>
      </vxe-column>
    </vxe-table>
    <div class="acea-row row-right page">
      <Page
          :total="page.total"
          :current="page.pageNum"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="page.pageSize"
          @on-page-size-change="limitChange"
          show-sizer
      />
    </div>
    <!-- 分配 -->
    <Distribution ref="distshow"></Distribution>
    <!-- 编辑 退款 退积分 不退款-->
    <edit-from
      ref="edits"
      :FromData="FromData"
      @submitFail="submitFail"
    ></edit-from>
    <!-- 会员详情-->
    <user-details ref="userDetails" fromType="order"></user-details>
    <order-debt-detail ref="orderDebtDetail" />
    <!-- 详情 -->
    <details-from
      ref="detailss"
      :orderDatalist="orderDatalist"
      :orderId="orderId"
      :row-active="rowActive"
      :openErp="openErp"
      :formType="1"
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
      @submitFail="submitFail(1)"
    ></order-send>
    <Modal
      v-model="manualModal"
      title="手动批量发货"
      @on-cancel="manualModalCancel"
      class-name="vertical-center-modal"
    >
      <Row type="flex">
        <Col span="4">
          <div style="line-height: 32px; text-align: right">文件：</div>
        </Col>
        <Col span="20">
          <Upload
            ref="upload"
            :action="uploadAction"
            :headers="uploadHeaders"
            accept=".xlsx,.xls"
            :format="['xlsx', 'xls']"
            :disabled="!!fileList.length"
            :on-success="uploadSuccess"
            :on-remove="removeFile"
          >
            <Button icon="ios-cloud-upload-outline">上传文件</Button>
          </Upload>
        </Col>
      </Row>
	  <div slot="footer">
	    <Button @click="manualModalCancel">取消</Button>
	    <Button type="primary" @click="manualModalOk">保存</Button>
	  </div>
    </Modal>
    <!--订单核销模态框-->
    <Modal
      v-model="modals2"
      title="订单核销"
      class="paymentFooter"
      scrollable
      width="400"
      class-name="vertical-center-modal"
    >
      <Form
        ref="writeOffFrom"
        :model="writeOffFrom"
        :rules="writeOffRules"
        :label-position="labelPosition"
        class="tabform"
        @submit.native.prevent
      >
        <FormItem prop="code" label-for="code">
          <Input
            search
            enter-button="验证"
            style="width: 100%"
            type="text"
            placeholder="请输入12位核销码"
            @on-search="search('writeOffFrom')"
            v-model.number="writeOffFrom.code"
            number
          />
        </FormItem>
      </Form>
      <div slot="footer">
        <Button type="primary" @click="ok">立即核销</Button>
        <Button @click="del('writeOffFrom')">取消</Button>
      </div>
    </Modal>
    <auto-send ref="sends" :selectArr="checkUidList" :isAll="isAll"></auto-send>
    <queue-list ref="queue"></queue-list>
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
          <Table v-if="is_split_order" ref="refundTable" max-height="500" :columns="refundColumns" :data="refundProduct" @on-selection-change="refundSelectionChange">
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
import Distribution from './distribution.vue'
import expandRow from './tableExpand.vue'
import {
  orderList,
  getOrdeDatas,
  getDataInfo,
  getRefundFrom,
  getnoRefund,
  refundIntegral,
  getDistribution,
  writeUpdate,
  storeOrderApi,
  handBatchDelivery,
  putWrite,
  exportExpressList,
   remindOrder,
   putOpenRefund
} from '@/api/order'
import { erpConfig } from '@/api/erp'
import { mapState, mapMutations } from 'vuex'
import changePrice from '../handle/changePrice.vue'
import editFrom from '../../../../components/from/from'
import detailsFrom from '../handle/orderDetails'
import orderRemark from '../handle/orderRemark'
import orderRecord from '../handle/orderRecord'
import orderSend from '../handle/orderSend'
import userDetails from '@/pages/user/list/handle/userDetails'
import orderDebtDetail from '@/components/orderDebtDetail'
import autoSend from '../handle/autoSend'
import queueList from '../handle/queueList'
import Setting from '@/setting'
import util from '@/libs/util'
import exportExcel from '@/utils/newToExcel.js'
import Template from '../../../setting/devise/template.vue'
import printJS from 'print-js';

export default {
  name: 'table_list',
  components: {
    expandRow,
    editFrom,
    detailsFrom,
    orderRemark,
    orderRecord,
    orderSend,
    userDetails,
    orderDebtDetail,
    Distribution,
    autoSend,
    queueList,
    Template,
	changePrice
  },
  props: ['where', 'currentTab'],
  data() {
    const codeNum = (rule, value, callback) => {
      if (!value) {
        return callback(new Error('请填写核销码'))
      }
      // 模拟异步验证效果
      if (!Number.isInteger(value)) {
        callback(new Error('请填写12位数字'))
      } else {
        // const reg = /[0-9]{12}/;
        const reg = /\b\d{12}\b/
        if (!reg.test(value)) {
          callback(new Error('请填写12位数字'))
        } else {
          callback()
        }
      }
    }
    return {
      roterPre: Setting.roterPre,
      openErp: false,
      // currentTab: '-1',
            distshow: false, //分配的弹窗
      delfromData: {},
      modal: false,
      orderList: [],
      pay_type: '',
      orderCards: [],
      loading: false,
      orderId: 0,
      page: {
        total: 0, // 总条数
        pageNum: 1, // 当前页
        pageSize: 10, // 每页显示条数
      },
      data: [],
      FromData: null,
      orderDatalist: null,
      modalTitleSs: '',
      isDelIdList: [],
      checkBox: false,
      formSelection: [],
      display: 'none',
      autoDisabled: false,
      status: 0, //发货状态判断
      isAll: 0,
      rowActive: {},
      tablists: {},
      selectArr: [],
      exportList: [
        {
          name: '1',
          label: '导出发货单',
        },
        {
          name: '0',
          label: '导出订单',
        },
      ],
      exportListOn: 0,
      manualModal: false,
      uploadAction: `${Setting.apiBaseURL}/file/upload/1`,
      uploadHeaders: {},
      autoModal: false,
      isShow: false,
      recordModal: false,
      sendOutValue: '',
      fileList: [],
      file: '',
      modals2: false,
      writeOffRules: {
        code: [{ validator: codeNum, trigger: 'blur', required: true }],
      },
      writeOffFrom: {
        code: '',
        confirm: 0,
      },
      orderConNum: 0,
      orderConId: 0,
      checkUidList: [],
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
      orderDataStatus: '',
	  stockInType: 0
    }
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    ...mapState('admin/order', [
      'orderPayType',
      'orderStatus',
      'orderTime',
      'orderNum',
      'orderSearchProduct',
      'orderSearchUser',
      'fieldKey',
      'orderType',
      'orderChartType',
      'supplier_id',
      'store_id',
      'type_id',
      'delivery_type',
      'take_time',
      'pay_time',
      'interval_price_min',
      'interval_price_max',
    ]),
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
  mounted() {},
  created() {
	this.orderDataStatus = this.$route.query.status || ''
	this.getOrderStatus(this.orderDataStatus)
    this.getList()
    this.getToken()
    this.getErpConfig()
  },
  watch: {
    currentTab() {
      this.onClickTab()
    },
    orderType: function () {
      this.page.pageNum = 1
      this.getList()
    },
    orderList: {
      deep: true,
      handler(value) {
        value.forEach((item) => {
          this.formSelection.forEach((itm) => {
            if (itm.id === item.id) {
              item.checkBox = true
            }
          })
        })
        const arr = this.orderList.filter((item) => item.checkBox)
        if (this.orderList.length) {
          this.checkBox = this.orderList.length === arr.length
        } else {
          this.checkBox = false
        }
      },
    },
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
  methods: {
    ...mapMutations('admin/order', [
	  'getOrderStatus',
      'getIsDel',
      'getisDelIdListl',
      'onChangeTabs',
      'getStore_id',
      'getSupplier_id',
          ]),
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
    checkboxItem(e){
      let id = parseInt(e.rowid);
      let index = this.checkUidList.indexOf(id);
      if(index !== -1){
        this.checkUidList = this.checkUidList.filter((item)=> item !== id);
      }else{
        this.checkUidList.push(id);
      }
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
    allPages(e){
      this.isAll = e;
      if(e==0){
        this.$refs.xTable.toggleAllCheckboxRow();
        // this.checkboxAll();
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
    //erp配置
    getErpConfig() {
      erpConfig()
        .then((res) => {
          this.openErp = res.data.open_erp
        })
        .catch((err) => {
          this.$Message.error(err.msg)
        })
    },
    printOreder() {
      if (this.checkUidList.length > 10 || (this.isAll==1 && this.page.total>10)) {
        return this.$Message.error('最多批量打印10个订单')
      }
      let ids = []
      if (this.isAll==1 && this.page.total<=10){
        this.orderList.forEach(item=>{
          ids.push(parseInt(item.id))
        })
      }
      let pathInfo = this.$router.resolve({
        path: this.roterPre + '/supplier/order/distribution',
        query: {
          id: this.isAll==1?ids.join(','):this.checkUidList.join(','),
          status: 2,
        },
      })
      window.open(pathInfo.href, '_blank')
    },

    delAll() {
      if (this.checkUidList.length === 0 && this.isAll==0) {
        return this.$Message.error('请先选择删除的订单！')
      }
      let idss = {
        all: this.isAll,
        ids: this.checkUidList,
      }
      let delfromData = {
        title: '删除订单',
        url: `/order/dels`,
        method: 'post',
        ids: idss,
      }
      this.$modalSure(delfromData)
          .then((res) => {
            this.$Message.success(res.msg)
            this.checkUidList = []
            this.getList()
          })
          .catch((res) => {
            this.$Message.error(res.msg)
          })
    },

    onAuto() {
      this.$refs.sends.modals = true;
      this.$refs.sends.getList();
      this.$refs.sends.getDeliveryList();
    },
    // 提醒发货
       btnClick(row) {
      let data = {
        supplier_id: row.supplier_id,
        id: row.id,
      }
      remindOrder(data)
        .then(async (res) => {
          this.$Message.success(res.msg)
        })
        .catch((res) => {
          this.$Message.error(res.msg)
        })
    },

    queuemModal() {
      this.$refs.queue.modal = true
    },

    // 下载物流公司对照表
    async getExpressList() {
      let [th, filekey, data, fileName] = [[], [], [], '']
      let lebData = await this.getExcelData()
      if (!fileName) fileName = lebData.filename
      if (!filekey.length) {
        filekey = lebData.filekey
      }
      if (!th.length) th = lebData.header
      data = lebData.export
      exportExcel(th, filekey, fileName, data)
    },

    getExcelData() {
      return new Promise((resolve, reject) => {
        exportExpressList().then((res) => {
          return resolve(res.data)
        })
      })
    },

    // 订单核销
    writeOff() {
      this.modals2 = true
    },

    // 验证
    search(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          this.writeOffFrom.confirm = 0
          putWrite(this.writeOffFrom)
            .then(async (res) => {
              if (res.status === 200) {
                // this.orderInfo = res.data;
                this.$Message.success(res.msg)
              } else {
                this.$Message.error(res.msg)
              }
            })
            .catch((res) => {
              this.$Message.error(res.msg)
            })
        } else {
          this.$Message.error('请填写正确的核销码')
        }
      })
    },

    // 订单核销
    ok() {
      if (!this.writeOffFrom.code) {
        this.$Message.warning('请先验证订单！')
      } else {
        this.writeOffFrom.confirm = 1
        putWrite(this.writeOffFrom)
          .then(async (res) => {
            if (res.status === 200) {
              this.$Message.success(res.msg)
              this.modals2 = false
              this.$refs[name].resetFields()
              this.$emit('getList')
            } else {
              this.$Message.error(res.msg)
            }
          })
          .catch((res) => {
            this.$Message.error(res.msg)
          })
      }
    },

    del(name) {
      // this.orderInfo = ''
      this.modals2 = false
      this.writeOffFrom.confirm = 0
      this.$refs[name].resetFields()
    },

    // 上传头部token
    getToken() {
      this.uploadHeaders['Authori-zation'] =
        'Bearer ' + util.cookies.get('token')
    },

    // 上传成功
    uploadSuccess(res, file, fileList) {
      if (res.status === 200) {
        this.$Message.success(res.msg)
        this.file = res.data.src
        this.fileList = fileList
      } else {
        this.$Message.error(res.msg)
      }
    },

    //移除文件
    removeFile(file, fileList) {
      this.file = ''
      this.fileList = fileList
    },

    // 手动批量发货-确定
    manualModalOk() {
      this.$refs.upload.clearFiles()
      handBatchDelivery({ file: this.file })
        .then((res) => {
          this.$Message.success(res.msg)
          this.fileList = []
		  this.manualModal = false;
        })
        .catch((err) => {
          this.$Message.error(err.msg)
          this.fileList = []
        })
    },

    // 手动批量发货-取消
    manualModalCancel() {
	  this.manualModal = false;
      this.fileList = []
      this.$refs.upload.clearFiles()
    },

    getTabs() {
      this.spinShow = true
      this.$store
        .dispatch('admin/order/getOrderTabs', {
          status: this.orderStatus,
          pay_type: this.orderPayType,
          data: this.orderTime,
          real_name: '',
          search_order_id: this.orderNum,
          search_product: this.orderSearchProduct,
          search_user: this.orderSearchUser,
          field_key: this.fieldKey,
          type: this.type_id,
          plat_type: this.currentTab,
          store_id: this.store_id,
          supplier_id: this.supplier_id,
          deliveryType: this.delivery_type,
        take_time: this.take_time,
        pay_time: this.pay_time,
        interval_price_min: this.interval_price_min,
        interval_price_max: this.interval_price_max,
        })
        .then((res) => {
          this.tablists = res.data
          // this.onChangeChart(this.tablists)
          this.spinShow = false
        })
        .catch((res) => {
          this.spinShow = false
          this.$Message.error(res.msg)
        })
    },
    onClickTab() {
      this.onChangeTabs(this.currentTab)
            this.isAll = 0;
      this.isCheckBox = false;
      this.$refs.xTable.setAllCheckboxRow(false);
      this.checkUidList = [];
      if (this.currentTab == 1) {
              this.getSupplier_id('')
      }
      if (this.currentTab == 2) {
        this.getStore_id('')
      }
      this.getList()
      this.$store.dispatch('admin/order/getOrderTabs', {
      type: this.currentTab,
      })
    },
    closeDetail() {
      this.$refs.detailss.modals = false
    },
    distribution(row) {
      this.$refs.distshow.modals = true
      this.$refs.distshow.formValidate.keywords = ''
      this.$refs.distshow.getList(row.id)
    },
    showUserInfo(row) {
      this.$refs.userDetails.modals = true
      this.$refs.userDetails.activeName = 'info'
      this.$refs.userDetails.getDetails(row.uid)
    },
    getOrderPendingDebt(row) {
      const pending = row.pending_debt_amount != null
        ? Number(row.pending_debt_amount)
        : Number(row.debt_amount || 0) - Number(row.repaid_debt_amount || 0)
      return pending > 0 ? pending : 0
    },
    getOrderTotal(row) {
      if (row.paid <= 0) return 0
      const total = row.total_price != null && row.total_price !== '' ? row.total_price : row.pay_price
      const raw = String(total || '0')
      return raw.split(',')[0]
    },
    openOrderDebtDetail(row) {
      if (this.$refs.orderDebtDetail) {
        this.$refs.orderDebtDetail.open(row)
      }
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
    // 操作
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
              this.$emit('changeGetTabs')
              this.getData(row.id, 1)
              this.getList()
            })
            .catch((res) => {
              this.$Message.error(res.msg)
            })
          // this.modalTitleSs = '修改立即支付';
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
        case '55':
          this.getrefundData(row.id, row.refund_type)
          break
        case '6':
          this.getRefundIntegral(row.id)
          break
        case '7':
          this.getNorefundData(row.id)
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
              this.$emit('changeGetTabs')
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
          // this.modalTitleSs = '修改确认收货';
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
              this.$emit('changeGetTabs')
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
          // this.modalTitleSs = '删除订单';
          this.delOrder(row, this.delfromData)
      }
    },

    // 立即支付 /确认收货//删除单条订单
    submitModel() {
      this.getList()
    },
    pageChange(index) {
      this.page.pageNum = index
      this.getList()
    },
    limitChange(limit) {
      this.page.pageSize = limit
      this.getList()
    },

    // 订单列表
    getList(res) {
      if(res==1){
        this.isAll = 0;
        this.$refs.xTable.setAllCheckboxRow(false);
        this.checkUidList = [];
      }
      this.page.pageNum = res === 1 ? 1 : this.page.pageNum
      this.loading = true
      orderList({
        page: this.page.pageNum,
        limit: this.page.pageSize,
        status: this.orderStatus,
        pay_type: this.orderPayType,
        data: this.orderTime,
        real_name: '',
        search_order_id: this.orderNum,
        search_product: this.orderSearchProduct,
        search_user: this.orderSearchUser,
        field_key: this.fieldKey,
        type: this.type_id,
        plat_type: this.currentTab,
                store_id: this.store_id,
        supplier_id: this.supplier_id,
        deliveryType: this.delivery_type,
        take_time: this.take_time,
        pay_time: this.pay_time,
        interval_price_min: this.interval_price_min,
        interval_price_max: this.interval_price_max,
      })
        .then(async (res) => {
          let data = res.data
          data.data.forEach((item) => {
            item.checkBox = this.isAll == 1;
            if (item.id == this.orderId) {
              this.rowActive = item
            }
          })
          // this.orderList = data.data;
          this.$set(this, 'orderList', data.data)
          this.orderCards = data.stat
          this.page.total = data.count
          this.$emit('on-changeCards', data.stat)
          this.loading = false
          this.getTabs()
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
          this.loading = false
          this.$Message.error(res.msg)
        })
    },
    // 订单改价
    edit(row) {
	  this.$refs.changePrice.id = row.id;
	  this.$refs.changePrice.ordeUpdateInfo(row.id);
	  this.$refs.changePrice.priceModals = true;
    },
	submitSuccess(res){
	   // if (res.data.status === false) {
	   //   return this.$authLapse(res.data)
	   // }
	   // this.$authLapse(res.data)
	   // this.FromData = res.data
	   // this.$refs.edits.modals = true
	   this.getList()
	   if (this.$refs.detailss.modals) {
	     this.getData(this.orderId, 1)
	   }
	},
	// 获取编辑表单数据
	// getOrderData(id) {
	//   getOrdeDatas(id)
	//     .then(async (res) => {
	//       if (res.data.status === false) {
	//         return this.$authLapse(res.data)
	//       }
	//       this.$authLapse(res.data)
	//       this.FromData = res.data
	//       this.$refs.edits.modals = true
	//     })
	//     .catch((res) => {
	//       this.$Message.error(res.msg)
	//     })
	// },
    splitOrderDetail(row) {
      this.$router.push({
        path: this.roterPre + 'split_list',
        query: {
          id: row.id,
          orderChartType: this.orderStatus,
        },
      })
    },

    // 删除单条订单
    delOrder(row, data) {
      if (row.is_del === 1) {
        this.$modalSure(data)
          .then((res) => {
            this.$Message.success(res.msg)
            this.getList()
            this.$refs.detailss.modals = false
            this.$emit('changeGetTabs')
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
    // 获取详情表单数据
    getData(id, type) {
      // this.$refs.detailss.modals = true;
      getDataInfo(id)
        .then(async (res) => {
          if (!type) {
            this.$refs.detailss.modals = true
			this.$refs.detailss.isShow = 0
          }
          // this.$refs.detailss.activeName = 'detail'
          this.orderDatalist = res.data
          if (this.orderDatalist.orderInfo.refund_reason_wap_img) {
            try {
              this.orderDatalist.orderInfo.refund_reason_wap_img = JSON.parse(
                this.orderDatalist.orderInfo.refund_reason_wap_img
              )
            } catch (e) {
              this.orderDatalist.orderInfo.refund_reason_wap_img = []
            }
          }
        })
        .catch((res) => {
          this.$Message.error(res.msg)
        })
    },

    // 修改成功
    submitFail(type) {
      this.status = 0
      this.getList()
      if (this.orderConNum != 1) {
        this.getData(this.orderId, 1)
      } else {
        this.$refs.detailss.getSplitOrder(this.orderConId)
      }
      if (type) {
        this.$emit('changeGetTabs')
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

    // 退货退款
    getrefundData(id, refund_type) {
      this.delfromData = {
        title: '是否立即退货退款',
        url: `/refund/agree/${id}`,
        method: 'get',
      }
      this.$modalSure(this.delfromData)
        .then((res) => {
          this.$Message.success(res.msg)
          this.getList()
          this.$emit('changeGetTabs')
        })
        .catch((res) => {
          this.$Message.error(res.msg)
        })
    },

    // 获取退积分表单数据
    // getRefundIntegral(id) {
    //   refundIntegral(id)
    //     .then(async (res) => {
    //       this.FromData = res.data
    //       this.$refs.edits.modals = true
    //     })
    //     .catch((res) => {
    //       this.$Message.error(res.msg)
    //     })
    // },

    // 不退款表单数据
    getNorefundData(id) {
      this.$modalForm(getnoRefund(id)).then(() => {
        this.getList()
        this.$emit('changeGetTabs')
      })
    },

    // 发送货
    sendOrder(row, num) {
      this.orderConId = row.pid
      this.orderConNum = num
      this.$store.commit('admin/order/setSplitOrder', row.total_num)
      this.$refs.send.modals = true
	  this.$refs.send.activeRow = row
      this.orderId = row.id
      this.status = row._status
      this.pay_type = row.pay_type
      this.$refs.send.getList()
      this.$refs.send.getDeliveryList()
      this.$nextTick((e) => {
        this.$refs.send.getCartInfo(row._status, row.id)
      })
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

    // 订单导出
    change(status) {},
    async exports(value) {
      if (!this.checkUidList.length && this.isAll == 0) {
        return this.$Message.warning('请勾选要导出的订单')
      }
      this.exportListOn = this.exportList.findIndex(
          (item) => item.name === value
      )
      let [th, filekey, data, fileName] = [[], [], [], '']
      let excelData = {
        ...this.where,
        page: 1,
        export_type: value,
        ids: this.isAll == 1 ? '' : this.checkUidList.join(),
        plat_type: this.currentTab,
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
        let attrList = data[j].attr ? data[j].attr.split('\n') : [];
        let goodsList = data[j].goods_name ? data[j].goods_name.split('\n') : [];
        let productIdList = data[j].product_id ? data[j].product_id.split('\n') : [];
        let cartNumList = data[j].cart_num ? data[j].cart_num.split('\n') : [];
        for (let k = 0; k < goodsList.length; k++) {
          let row = {...data[j]}
          if (row.goods_name) {
            row.goods_name = goodsList[k]
          }
          if (row.attr) {
            row.attr = attrList[k]
          }
          if (row.product_id) {
            row.product_id = productIdList[k]
          }
          if (row.cart_num) {
            row.cart_num = cartNumList[k]
          }
          if (k) {
            for (const key in row) {
              if (Object.hasOwnProperty.call(row, key)) {
                if (key !== 'goods_name' && key !== 'attr' && key !== 'product_id' && key !== 'cart_num') {
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
    },

    // 核销订单
    bindWrite(row) {
      let self = this
      this.$Modal.confirm({
        title: '提示',
        content: '确定要核销该订单吗？',
        cancelText: '取消',
        closable: true,
        maskClosable: true,
        onOk: function () {
          writeUpdate(row.order_id).then((res) => {
            self.$Message.success(res.msg)
            self.getList()
          }).catch(err=>{
			  self.$Message.error(err.msg)
		  })
        },
        onCancel: () => {},
      })
    },
    selectChange2() {
      this.$emit('selectChange2', this.orderDataStatus)
    },
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
.order-debt-link {
  display: block;
  color: #ff4d4f;
  font-size: 12px;
  margin-top: 2px;
}
</style>
