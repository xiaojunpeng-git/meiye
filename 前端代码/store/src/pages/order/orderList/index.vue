<template>
  <div>
    <Card :bordered="false" dis-hover class="mt15">
      <Form
          ref="orderData"
          :model="orderData"
          :label-width="labelWidth"
          :label-position="labelPosition"
          class="tabform"
          inline
          @submit.native.prevent
      >
        <FormItem label="订单号：" label-for="search_order_id">
          <Input
              placeholder="订单号（含补交单关联）"
              v-model="orderData.search_order_id"
              class="input-add"
          />
        </FormItem>
        <FormItem label="核销订单号：" label-for="search_verify_code">
          <Input
              placeholder="核销子单订单号（含自动核销）"
              v-model="orderData.search_verify_code"
              class="input-add"
          />
        </FormItem>
        <FormItem label="商品：" label-for="search_product">
          <Input
              placeholder="商品名称关键词"
              v-model="orderData.search_product"
              class="input-add"
          />
        </FormItem>
        <FormItem label="用户：" label-for="search_user">
          <Input
              placeholder="昵称、手机号、UID、收货人"
              v-model="orderData.search_user"
              class="input-add"
          />
        </FormItem>
        <FormItem label="下单时间：">
          <DatePicker
              :editable="false"
              @on-change="onchangeTime"
              :value="timeVal"
              format="yyyy/MM/dd"
              type="daterange"
              placement="bottom-start"
              placeholder="自定义时间"
              class="input-add"
              :options="options"
          ></DatePicker>
        </FormItem>
        <FormItem label="订单类型：">
          <Select v-model="orderData.link_type"
                  multiple
                  class="input-add"
                  clearable
                  @on-change="userSearchs"
          >
            <Option value="0">普通订单</Option>
            <Option value="1">充值订单</Option>
            <Option value="2">核销订单</Option>
            <Option value="card_upgrade_old">旧卡升级</Option>
          </Select>
        </FormItem>
        <FormItem label="来源：">
          <Select v-model="orderData.source"
                  multiple
                  class="input-add"
                  clearable
                  @on-change="userSearchs"
          >
            <Option :value="item.id" v-for="(item,index) in source">{{ item.name }}</Option>
          </Select>
        </FormItem>
        <FormItem label="付款方式：">
          <Select v-model="orderData.pay_type"
                  multiple
                  class="input-add"
                  clearable
                  @on-change="userSearchs"
          >
            <Option :value="item.id" v-for="(item,index) in pay_type_select">{{ item.name }}</Option>
          </Select>
        </FormItem>
        <FormItem label="组合明细：" v-if="showCombinationActivePay">
          <Select
              v-model="orderData.combination_detail"
              class="input-add"
              clearable
              placeholder="全部"
              @on-change="userSearchs"
          >
            <Option value="1">微信</Option>
            <Option
                v-for="item in cash_type"
                :key="'comb-cash-' + item.id"
                :value="'2|' + item.id"
            >{{ item.name }}</Option>
            <Option value="3|balance">余额支付</Option>
            <Option value="3|card_upgrade">旧卡升级</Option>
          </Select>
        </FormItem>
        <FormItem label="现金类型：">
          <Select v-model="orderData.cash_choose"
                  multiple
                  class="input-add"
                  clearable
                  @on-change="userSearchs"
          >
            <Option :value="item.id" v-for="(item,index) in cash_type">{{ item.name }}</Option>
          </Select>
        </FormItem>
        <FormItem
            label="销售人员："
            label-for="yeji_staff"
        >
          <Input
              placeholder="销售人员"
              v-model="orderData.yeji_staff"
              class="input-add"
          />
        </FormItem>
        <FormItem
            label="手艺人员："
            label-for="yeji_shouyi"
        >
          <Input
              placeholder="手艺人员(模糊)"
              v-model="orderData.yeji_shouyi"
              class="input-add"
          />
        </FormItem>
        <FormItem label="商品类型：">
          <Select
              v-model="orderData.product_type"
              class="input-add"
              clearable
              placeholder="请选择"
              @on-change="userSearchs"
          >
            <Option value="0">商品订单</Option>
            <Option value="6">项目订单</Option>
            <Option value="5">卡项订单</Option>
          </Select>
        </FormItem>
        <FormItem label="服务对象：">
          <Select
            v-model="orderData.service_object"
            class="input-add"
            clearable
            placeholder="全部"
            @on-change="userSearchs"
          >
            <Option value="本人">本人</Option>
            <Option value="朋友">朋友</Option>
          </Select>
        </FormItem>
        <Button type="primary" @click="userSearchs()" class="ml-14">查询</Button>
        <Button @click="onReset()" class="ml-14">重置</Button>
      </Form>
    </Card>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt">
      <div class="order-list-toolbar">
        <div class="new_tab order-list-tabs">
          <Tabs v-model="orderData.status" @on-click="userSearchs">
            <TabPane label="全部" name=" "/>
            <TabPane :label="'待支付(' + (orderChartType.unpaid || 0) + ')'" name="0"/>
            <TabPane :label="'待发货(' + (orderChartType.unshipped || 0) + ')'" name="1"/>
            <TabPane :label="'待核销(' + (orderChartType.write_off || 0) + ')'" name="5"/>
            <TabPane label="待收货" name="2"/>
            <TabPane label="待评价" name="3"/>
            <TabPane label="已核销" name="6"/>
            <TabPane label="已完成" name="4"/>
            <TabPane label="已撤销" name="-2"/>
          </Tabs>
        </div>
        <Poptip placement="bottom-end" width="420" transfer trigger="click" class="field-toggle-wrap">
          <a class="field-toggle-link">隐藏字段</a>
          <div slot="content" class="field-toggle-panel">
            <CheckboxGroup v-model="columnVisibleKeys" @on-change="saveColumnVisible">
              <Checkbox
                v-for="item in columnOptions"
                :key="item.key"
                :label="item.key"
                class="field-toggle-item"
              >{{ item.label }}</Checkbox>
            </CheckboxGroup>
          </div>
        </Poptip>
      </div>
      <Row type="flex" class="mb12">
        <Col v-if="orderData.order_type == 106">
          <Button
              v-auth="['order-cashier-cashier_scan']"
              type="primary"
              class="mr"
              @click="qrcodeShow"
          >下载收银码
          </Button>
        </Col>
        <Col>
          <Tooltip
              content="请勾选要导出的订单；表头全选选「所有页」则按当前筛选条件导出"
              :disabled="!!checkUidList.length || isAll == 1"
          >
            <Button
                v-auth="['order-export']"
                icon="ios-share-outline"
                :disabled="!checkUidList.length && isAll == 0"
                @click="exports"
            >导出
            </Button>
          </Tooltip>
        </Col>
        <Col>
          <Tooltip
              content="本页至少选中一项"
              :disabled="!!checkUidList.length && isAll==0"
          >
            <Button
                class="ml15"
                type="primary"
                :disabled="!checkUidList.length && isAll==0"
                @click="printOreder"
            >打印配货单
            </Button>
          </Tooltip>
        </Col>
      </Row>
      <store-order-card-list
        :list="tableList"
        :loading="loading"
        :col-visible="colVisible"
        :check-uid-list="checkUidList"
        :is-all="isAll"
        :total="total"
        @all-pages="allPages"
        @check-item="checkboxItem"
        @order-detail="(row) => changeMenu(row, '2')"
        @user-info="showUserInfo"
        @yeji="doYeji"
        @remark="showRemark"
        @gendan="showGendan"
        :editable-source="true"
        @source="showSource"
      >
        <template #action="{ row }">
          <a
              @click="sendOrder(row)"
              v-if="
                (row._status === 2 || row._status === 8 || row.status === 4) &&
                (row.pinkStatus === null || row.pinkStatus === 2) &&
                row.delete_time == null &&
                row.store_delivery_type !== 2
              "
          >发送货</a>
          <a
              @click="openModal3(row)"
              v-if="
                (row._status === 2 || row._status === 4) &&
                (row.pinkStatus === null || row.pinkStatus === 2) &&
                row.delete_time == null &&
                row.store_delivery_type === 2
              "
          >{{ row._status === 2 ? '派单' : '改派' }}</a>
          <Divider
              type="vertical"
              v-if="
                (row._status === 2 || row._status === 4) &&
                (row.pinkStatus === null || row.pinkStatus === 2) &&
                row.delete_time == null &&
                row.store_delivery_type === 2"
          />
          <a
              @click="orderReissueOrder(row)"
              v-if="row.store_delivery_type == 2 && row._status == 2 && row.status_name.is_reissue_order == 1"
          >重新发单</a>
          <a v-if="row.order_type != 2" @click="changeMenu(row, '2')">详情</a>
          <a v-if="canOrderRepay(row)" class="action-link-gap" @click="openOrderRepay(row)">还款</a>
          <a
            v-if="row.order_type == 2 && row.refund_status == 0 && Number(row.terminal_action || 0) === 0"
            v-auth="['store-order-writeoff-cancel']"
            class="action-link-gap"
            @click="changeMenu(row, '14')"
          >撤销本次核销</a>
          <a
            v-if="canShowTerminalRefund(row)"
            v-auth="['store-order-terminal-refund']"
            class="action-link-gap"
            @click="openTerminalRefund(row)"
          >退款</a>
          <a
            v-if="canShowTerminalVoid(row)"
            v-auth="['store-order-terminal-void']"
            class="action-link-gap"
            @click="openTerminalVoid(row)"
          >作废</a>
          <a
            v-if="canShowReopen(row)"
            v-auth="['store-order-reopen']"
            class="action-link-gap"
            @click="doReopen(row)"
          >重新开单</a>
          <a
            v-if="row.order_type == 1 && row.refund_status == 0 && Number(row.terminal_action || 0) === 0"
            v-auth="['store-order-terminal-refund']"
            class="action-link-gap"
            @click="changeMenu(row, '555')"
          >退款</a>
          <span
            v-if="Number(row.terminal_action || 0) === 2 && !row.can_reopen && row.reopen_deny_reason"
            class="action-link-gap"
            style="color:#999;font-size:12px;"
          >{{ row.reopen_deny_reason }}</span>
        </template>
      </store-order-card-list>
      <div class="acea-row row-right page">
        <Page
            :total="total"
            :current="orderData.page"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="orderData.limit"
        />
      </div>
    </Card>
    <orderWriteOff
        ref="writeOff"
        :orderNumId="orderNumId"
        @submitSuccess="submitSuccess"
    ></orderWriteOff>
    <!-- 用户详情-->
    <user-details ref="userDetails" :group-list="groupList" @cardHolderOpen="cardHolderOpen"></user-details>
    <Modal v-model="cardHolderShow" scrollable title="卡项详情" closable footer-hide width="900">
      <cardHolder :cardHolder="cardHolderData"></cardHolder>
    </Modal>
    <!-- 编辑 配送信息表单数据 退款 退积分 不退款-->
    <edit-from
        ref="edits"
        :FromData="FromData"
        @submitFail="submitFail"
    ></edit-from>
    <!-- 详情 -->
    <details-from
        ref="detailss"
        :orderDatalist="orderDatalist"
        :orderId="orderId"
        :row-active="rowActive"
        :formType="1"
        @submitFail="submitFail"
        @orderReissueOrder="orderReissueOrder"
    ></details-from>
    <!-- 备注 -->
    <order-remark
        ref="remarks_bak"
        :orderId="orderId"
        @submitFail="submitFail"
        currentTab="5"
    ></order-remark>
    <order-remark
        ref="remarks"
        :orderId="orderId"
        @submitFail="submitFail"
        :currentTab="orderData.order_type"
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
    <Modal v-model="modalCode" title="收款码" footer-hide>
      <div>
        <div v-viewer class="acea-row row-around code">
          <Spin fix v-if="spin"></Spin>
          <div class="acea-row row-column-around row-between-wrapper">
            <div class="QRpic">
              <img v-lazy="qrcode && qrcode.wechat"/>
            </div>
            <span class="mt10">公众号收款码</span>
          </div>
          <div class="acea-row row-column-around row-between-wrapper">
            <div class="QRpic">
              <img v-lazy="qrcode && qrcode.routine"/>
            </div>
            <span class="mt10">小程序收款码</span>
          </div>
        </div>
      </div>
    </Modal>
    <!-- 阶段5：旧「手动退款/分单退款」弹窗已移除，统一走 TerminalOrderModals -->
    <changePrice ref="changePrice" @submitSuccess="submitSuccessHandle"></changePrice>
    <!-- 派单-配送员弹窗 -->
    <Modal v-model="modal3" :mask-closable="false" title="选择配送员" width="657" ok-text="确认" class-name="delivery-modal"
           @on-ok="onDeliveryOk">
      <Form
          ref="formItem"
          :model="formItem"
          @submit.native.prevent
      >
        <FormItem label="配送员信息：">
          <Input v-model="formItem.keyword" placeholder="请输入配送员ID/名称/手机号" v-width="202"></Input>
          <Button type="primary" class="ml-20" @click="getDeliveryList">查询</Button>
          <Button class="ml-10" @click="onReset">重置</Button>
        </FormItem>
      </Form>
      <Table ref="selection" :columns="columns3" :data="deliveryList"></Table>
    </Modal>
    <yeji :syncProduct="syncProduct" :yeji="setYeji" :staffIds="staffIds" @closeYeji="closeYeji" :visible="yejiVisible"
          ref="yeji"></yeji>
    <remarkInfo ref="remarkInfo" :orderId="orderId" :remarkType="remarkType"></remarkInfo>
    <changeSource ref="changeSource" :orderId="orderId"></changeSource>
    <changeGendan ref="changeGendan"></changeGendan>
    <debt-repay-flow ref="debtRepayFlow" @success="getList" />
    <terminal-order-modals ref="terminalModals" @success="onTerminalSuccess" />
  </div>
</template>

<script>
import {mapState} from 'vuex'
import Setting from "@/setting";
import expandRow from './components/tableExpand.vue'
import storeOrderCardList from './components/storeOrderCardList'
import userDetails from '@/pages/user/components/userDetails2'
import cardHolder from '@/pages/user/components/cardHolder'
import editFrom from '@/components/from/from'
import orderSend from './components/orderSend'
import detailsFrom from './components/orderDetails'
import orderRecord from './components/orderRecord'
import orderWriteOff from './components/orderWriteOff'
import exportExcel from '@/utils/newToExcel.js'
import timeOptions from '@/utils/timeOptions'
import orderRemark from './components/orderRemark'
import changePrice from './components/changePrice'
import remarkInfo from '@/components/yeji/orderRemarkInfo';
import changeSource from '@/components/yeji/changeSource';
import changeGendan from '@/components/yeji/changeGendan';
import yeji from '@/components/yeji';
import debtRepayFlow from '@/components/debtRepayFlow';
import TerminalOrderModals from './components/TerminalOrderModals'
import { debtOrderDetailApi } from '@/api/debt';
import {
  orderList,
  orderChart,
  orderHeader,
  getDistribution,
  writeUpdate,
  getDataInfo,
  orderExport,
  cashierScan,
  getRefundFrom,
  refundRecharge,
  orderWriteForm,
  putOpenRefund,
  postOrderReopen,
  orderBenefits,
  deliveryReassignApi,
  putDelivery,
  orderDeliveryList,
  orderReissueOrderApi,
  getCash
} from '@/api/order'
import {staffallInfo} from '@/api/staff'
import printJS from 'print-js';
import dayjs from "dayjs";
import {getYeji, getRemak} from '@/api/yeji';
import {userGroupApi} from '@/api/user';

export default {
  name: 'index',
  components: {
    userDetails,
    cardHolder,
    editFrom,
    detailsFrom,
    orderRecord,
    orderWriteOff,
    orderSend,
    storeOrderCardList,
    expandRow,
    orderRemark,
    changePrice,
    remarkInfo,
    changeSource,
    changeGendan,
    yeji,
    debtRepayFlow,
    TerminalOrderModals,
  },
  filters: {
    timeFormat: (value) => dayjs(value * 1000).format("YYYY-MM-DD HH:mm"),
  },
  data() {
    return {
      staffIds: [],
      remarkType: 1,
      setYeji: {
        link_id: 0,
        price: 0,
        goods_id: 0,
        type: 2,
        staffChoose: []
      },
      syncProduct: [],
      yejiVisible: false,
      isCheckBox: false,
      checkUidList: [],
      isAll: 0,
      qrcode: {},
      spin: false,
      modalCode: false,
      delfromData: {},
      pay_type: '',
      status: 0, //发货状态判断
      FromData: null,
      orderDatalist: null,
      orderId: 0,
      cash_type: [],
      source: [],
      pay_type_select: [],
      orderNumId: '',
      columnOptions: [
        { key: 'product', label: '商品' },
        { key: 'price', label: '单价' },
        { key: 'qty', label: '数量' },
        { key: 'coupon_deduct', label: '优惠券抵扣' },
        { key: 'craft_staff', label: '手艺人' },
        { key: 'sales_staff', label: '销售人' },
        { key: 'customer', label: '客户' },
        { key: 'amount', label: '金额' },
        { key: 'source', label: '来源' },
        { key: 'store', label: '下单门店' },
        { key: 'status', label: '状态' },
        { key: 'action', label: '操作' },
      ],
      columnVisibleKeys: ['product', 'price', 'qty', 'coupon_deduct', 'craft_staff', 'sales_staff', 'customer', 'amount', 'source', 'store', 'status', 'action'],
      groupList: [],
      cardHolderShow: false,
      cardHolderData: {},
      options: timeOptions,
      payList: [
        {label: '微信支付', val: '1'},
        {label: '支付宝支付', val: '4'},
        {label: '余额支付', val: '2'},
        {label: '线下支付', val: '3'},
      ],
      statusList: [
        {
          // 订单状态
          value: '',
          label: '全部',
        },
        {
          value: '0',
          label: '未支付',
        },
        {
          value: '1',
          label: '待配送',
        },
        {
          value: '2',
          label: '配送中',
        },
        {
          value: '5',
          label: '待核销',
        },
        {
          value: '6',
          label: '已核销',
        },
        {
          value: '3',
          label: '待评价',
        },
        {
          value: '4',
          label: '已完成',
        },
        {
          value: '-4',
          label: '已删除',
        },
      ],
      staffData: [],
      tablists: {},
      orderChartType: {},
      timeVal: [],
      // 订单列表
      orderData: {
        page: 1,
        limit: 10,
        type: '',
        status: "",
        date_range: "",
        yeji_staff: "",
        yeji_shouyi: "",
        real_name: "",
        search_order_id: "",
        search_verify_code: "",
        search_product: "",
        search_user: "",
        store_id: "",
        order_type: "",
        cash_choose: '',
        combination_detail: '',
        active_pay: '',
        pay_sub_type: '',
        combination_cash_choose: '',
        link_type: "",
        product_type: '',
        service_object: ''
      },
      tableList: [],
      total: 0,
      loading: false,
      columns: [
        {
          type: 'expand',
          width: 30,
          render: (h, params) => {
            return h(expandRow, {
              props: {
                row: params.row,
              },
            })
          },
        },
        {
          type: "selection",
          width: 60,
          align: "center"
        },
        {
          title: '订单号',
          slot: 'order_id',
          minWidth: 160,
        },
        {
          title: '用户信息',
          slot: 'userInfo',
          minWidth: 250,
        },
        {
          title: '商品信息',
          slot: 'info',
          minWidth: 350,
        },
        {
          title: '实际支付',
          slot: 'pay_price',
          minWidth: 120,
        },
        {
          title: '支付方式',
          key: 'pay_type_name',
          minWidth: 120,
        },
        {
          title: '收银店员',
          key: 'clerk_name',
          minWidth: 120,
        },
        {
          title: '下单时间',
          key: 'add_time',
          minWidth: 130,
        },
        {
          title: '订单状态',
          slot: 'statusName',
          minWidth: 120,
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          minWidth: 200,
          align: 'center',
        },
      ],
      rowActive: {},
      refundModal: false,
      activeName: 'product',
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
            const raw = params.row.productInfo && params.row.productInfo.attrInfo ? params.row.productInfo.attrInfo.suk : '';
            const suk = raw === undefined || raw === null ? '' : String(raw).replace(/^\s+|\s+$/g, '').replace(/^　+|　+$/g, '');
            return h('div', (suk && suk !== '默认' && suk !== '默认规格') ? suk : '');
          }
        },
        {
          title: '售价',
          render: (h, params) => {
            return h('div', params.row.productInfo.attrInfo.price);
          }
        },
        {
          title: '优惠价',
          key: 'refundPrice'
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
      formValidate: {
        refundBen: "",
        refundGive: '',
      },
      is_split_order: 0,
      refund_explain: '',
      writeSurplusTimes: 0,
      writeTimes: 0,
      cardBenefits: [],
      remainingPrice: 0,
      stockInType: 0,
      /** 手动退款：是否退回用户订单使用的优惠券（1 退回，0 不退回） */
      returnCoupon: 1,
      benefitsInfo: {},
      modal3: false,
      formItem: {
        field_key: 'all',
        keyword: '',
      },
      columns3: [
        {
          title: ' ',
          width: 60,
          align: 'center',
          render: (h, params) => {
            return h('Radio', {
              props: {
                value: params.row.id == this.deliveryId,
                disabled: params.row.disabled,
              },
              on: {
                'on-change': () => {
                  this.deliveryId = params.row.id;
                },
              }
            });
          },
        },
        {
          title: 'ID',
          key: 'id'
        },
        {
          title: '配送员名称',
          key: 'wx_name'
        },
        {
          title: '手机号码',
          key: 'phone'
        },
        {
          title: '待配送数量',
          key: 'unsend',
        },
      ],
      deliveryList: [],
      deliveryId: 0,
    }
  },
  watch: {
    refundSelection: {
      handler(value) {
        this.refundMoney = value.reduce((total, {refundPrice, refundNum}) => {
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
    'orderData.pay_type': {
      handler() {
        if (!this.showCombinationActivePay) {
          this.orderData.combination_detail = '';
          this.syncCombinationFilter();
        }
      },
      deep: true
    }
  },
  computed: {
    ...mapState('store/layout', ['isMobile']),
    showCombinationActivePay() {
      const v = this.orderData.pay_type;
      if (!v) return false;
      if (Array.isArray(v)) return v.includes('combination');
      return String(v) === 'combination';
    },
    labelWidth() {
      return this.isMobile ? undefined : 80
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right'
    },
    refundProductNum() {
      return this.refundProduct.reduce((total, value) => {
        return total + value.refundNum;
      }, 0);
    },
    showRefundBalanceInputs() {
      // 普通订单任意支付方式均展示退本金/赠金
      return Number(this.rowActive.order_type || 0) === 0
    },
    showReturnCouponOption() {
      const r = this.rowActive || {}
      if (Number(r.coupon_id || 0) > 0 || Number(r.coupon_price || 0) > 0) {
        return true
      }
      const cartItems = (this.refundProduct && this.refundProduct.length)
        ? this.refundProduct
        : Object.values(r._info || {}).map((v) => v.cart_info || v)
      return cartItems.some((item) => {
        if (!item || item.is_gift) return false
        return Number(item.coupon_id || 0) > 0 || Number(item.coupon_price || 0) > 0
      })
    },
    refundFormRules() {
      if (!this.showRefundBalanceInputs) {
        return {}
      }
      return {
        refundBen: [
          {
            required: true,
            message: '请输入本金',
            trigger: 'blur',
          },
        ],
        refundGive: [
          {
            required: true,
            message: '请输入赠金',
            trigger: 'blur',
          },
        ],
      }
    },
    colVisible() {
      const visible = {};
      this.columnOptions.forEach(({ key }) => {
        visible[key] = this.columnVisibleKeys.includes(key);
      });
      return visible;
    },
  },
  created() {
    this.loadColumnVisible();
    this.loadUserGroupList();
    this.orderData.date_range = this.$route.query.dateRange || '';
    if (this.orderData.date_range != '') {
      this.timeVal = this.orderData.date_range.split("-");
    }
    this.getCash()
    this.orderData.status = this.$route.query.status || ''
    this.staffList()
    this.getList()
  },
  mounted() {
  },
  methods: {
    loadColumnVisible() {
      try {
        const saved = localStorage.getItem('store_order_field_visible');
        if (saved) {
          const keys = JSON.parse(saved);
          if (Array.isArray(keys) && keys.length) {
            this.columnVisibleKeys = this.normalizeColumnVisibleKeys(keys);
          }
        }
      } catch (e) {}
    },
    normalizeColumnVisibleKeys(keys) {
      if (!Array.isArray(keys) || !keys.length) {
        return this.columnVisibleKeys;
      }
      if (keys.includes('staff') && !keys.includes('craft_staff')) {
        keys = keys.filter((key) => key !== 'staff');
        keys.splice(Math.min(3, keys.length), 0, 'craft_staff', 'sales_staff');
      }
      const allowed = this.columnOptions.map((item) => item.key);
      let result = allowed.filter((key) => keys.includes(key));
      if (!result.includes('source')) {
        const storeIdx = result.indexOf('store');
        if (storeIdx >= 0) {
          result.splice(storeIdx, 0, 'source');
        } else {
          result.push('source');
        }
      }
      return result;
    },
    saveColumnVisible(keys) {
      this.columnVisibleKeys = keys;
      try {
        localStorage.setItem('store_order_field_visible', JSON.stringify(keys));
      } catch (e) {}
    },
    loadUserGroupList() {
      userGroupApi({ page: 1, limit: '' }).then((res) => {
        this.groupList = res.data.list || [];
      }).catch(() => {});
    },
    cardHolderOpen(row) {
      this.cardHolderData = {};
      this.$nextTick(() => {
        this.cardHolderData = row;
        this.cardHolderShow = true;
      });
    },
    /** 订单列表商品行：有效规格文案（空/默认/纯空白不传，避免出现「 | | 」） */
    orderListSpecSuk(val) {
      try {
        const a = val && val.cart_info && val.cart_info.productInfo && val.cart_info.productInfo.attrInfo
        if (!a || a.suk === undefined || a.suk === null) return ''
        const t = String(a.suk).replace(/^\s+|\s+$/g, '').replace(/^　+|　+$/g, '')
        if (!t || t === '默认' || t === '默认规格') return ''
        return t
      } catch (e) {
        return ''
      }
    },
    syncCombinationFilter() {
      this.orderData.active_pay = '';
      this.orderData.pay_sub_type = '';
      this.orderData.combination_cash_choose = '';
      const detail = this.orderData.combination_detail;
      if (!detail || !this.showCombinationActivePay) return;
      const parts = String(detail).split('|');
      this.orderData.active_pay = parts[0];
      if (parts[0] === '3' && parts[1]) {
        this.orderData.pay_sub_type = parts[1];
      } else if (parts[0] === '2' && parts[1]) {
        this.orderData.combination_cash_choose = parts[1];
      }
    },
    getCash() {
      getCash().then(res => {
        this.cash_type = res.data.cash_type;
        this.source = res.data.source;
        this.pay_type_select = res.data.pay_type;
        let payType = this.$route.query.payType || '';
        if (payType != '') {
          this.orderData.pay_type = ['cash', 'combination'];
          this.cash_type.forEach((item, index) => {
            if (payType == item.name) {
              this.orderData.cash_choose = [item.id]
            }
          })
        }
      })
    },
    canShowTerminalRefund(row) {
      return Number(row.order_type) === 0
        && Number(row.paid) === 1
        && Number(row.refund_status) === 0
        && Number(row.terminal_action || 0) === 0
        && !row.is_debt_repay;
    },
    canShowTerminalVoid(row) {
      return Number(row.order_type) === 0
        && Number(row.paid) === 1
        && Number(row.refund_status) === 0
        && Number(row.terminal_action || 0) === 0
        && !row.is_debt_repay;
    },
    canShowReopen(row) {
      return Number(row.terminal_action || 0) === 2 && !!row.can_reopen;
    },
    openTerminalRefund(row) {
      this.rowActive = row;
      this.orderId = row.id;
      let refundBen = '0';
      if (row.pay_type === 'combination') {
        try {
          getRemak({ order_id: row.id, type: this.remarkType }).then((res) => {
            const list = (res && res.data && Array.isArray(res.data.list)) ? res.data.list : [];
            let yueSum = 0;
            list.forEach((it) => {
              const activePay = Number(it.activePay || it.active_pay || 0);
              const subType = it.pay_sub_type || it.paySubType || '';
              if (activePay === 3 && subType !== 'card_upgrade') {
                const p = Number(it.price || 0);
                if (!isNaN(p)) yueSum += p;
              }
            });
            this.$refs.terminalModals.openRefund(row, {
              refundBen: String(Number(yueSum.toFixed(2))),
              showReturnCoupon: this.showReturnCouponOption,
            });
          }).catch(() => {
            this.$refs.terminalModals.openRefund(row, { showReturnCoupon: this.showReturnCouponOption });
          });
          return;
        } catch (e) { /* fallthrough */ }
      }
      this.$refs.terminalModals.openRefund(row, {
        refundBen,
        showReturnCoupon: this.showReturnCouponOption,
      });
    },
    openTerminalVoid(row) {
      this.rowActive = row;
      this.orderId = row.id;
      this.$refs.terminalModals.openVoid(row, { showReturnCoupon: this.showReturnCouponOption });
    },
    openRechargeTerminalRefund(row) {
      const linkId = Number(row && row.link_id);
      if (!linkId) {
        return this.$Message.error('充值单不存在，请刷新后重试');
      }
      refundRecharge(linkId)
        .then((res) => {
          const rules = ((res && res.data) || {}).rules || [];
          let refundBen = '0';
          let refundGive = '0';
          rules.forEach((rule) => {
            if (rule.field === 'price') refundBen = String(rule.value != null ? rule.value : '0');
            if (rule.field === 'give_price') refundGive = String(rule.value != null ? rule.value : '0');
          });
          this.$refs.terminalModals.openRechargeRefund(row, {
            rechargeId: linkId,
            refundBen,
            refundGive,
          });
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '无法打开充值退款');
        });
    },
    onTerminalSuccess(payload) {
      this.getList();
      if (this.orderId) this.getData(this.orderId);
      if (payload && payload.action === 'void' && payload.can_reopen && payload.row) {
        this.$Modal.confirm({
          title: '作废成功',
          content: '订单已作废。是否立即重新开单？',
          okText: '重新开单',
          cancelText: '稍后',
          onOk: () => this.doReopen(payload.row),
        });
      }
    },
    doReopen(row) {
      postOrderReopen(row.id)
        .then((res) => {
          const data = (res && res.data) || {};
          const token = data.draft_token || '';
          if (!token) {
            return this.$Message.error(res.msg || '重新开单未成功');
          }
          const payload = {
            draft_token: token,
            uid: data.uid || row.uid,
            source_order_id: data.source_order_id || row.id,
            pending_pay: !!data.pending_pay,
            pending_pay_order_id: data.pending_pay_order_id || 0,
            messages: data.messages || [],
          };
          try {
            window.localStorage.setItem('mohe_reopen_draft', JSON.stringify(payload));
          } catch (e) { /* ignore */ }
          const tips = (payload.messages || []).filter(Boolean).join('；');
          const content = payload.pending_pay
            ? `已有待支付重开订单（编号 ${payload.pending_pay_order_id}），请到收银台继续支付。${tips ? ' ' + tips : ''}`
            : `重开草稿已准备好，请打开收银台继续结账。${tips ? ' ' + tips : ''}`;
          this.$Modal.confirm({
            title: '重新开单',
            content,
            okText: '打开收银台',
            cancelText: '我知道了',
            onOk: () => this.openCashierForReopen(token),
          });
          this.getList();
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '重新开单未成功，请核对后重试');
        });
    },
    openCashierForReopen(token) {
      const host = window.location.hostname;
      const port = window.location.port;
      let url = '';
      if (port === '18082' || port === '8082') {
        url = `${window.location.protocol}//${host}:18083/?reopen_token=${encodeURIComponent(token)}`;
      } else if (port === '18081') {
        url = `${window.location.protocol}//${host}:18083/?reopen_token=${encodeURIComponent(token)}`;
      } else {
        url = `${window.location.protocol}//${window.location.host}/cashier/?reopen_token=${encodeURIComponent(token)}`;
      }
      window.open(url, '_blank');
    },
    showRemark(row) {
      this.orderId = row.id
      this.$refs.remarkInfo.modals = true;
      this.$refs.remarkInfo.isEdit = false;
      this.$refs.remarkInfo.getRemark(row.id);
    },
    showSource(row) {
      this.orderId = row.id
      this.$refs.changeSource.modals = true;
      this.$refs.changeSource.showSource(row);
    },
    showGendan(row) {
      this.$refs.changeGendan.modals = true;
      this.$refs.changeGendan.showGendan(row);
    },
    visibleChange(visible) {
      this.is_split_order = 0;
      this.stockInType = 0;
      if (!visible) {
        this.refundSelection = [];
        this.returnCoupon = 1;
      }
    },
    cancelRefundModal() {
      this.refundModal = false;
    },
    putOpenRefund() {
      this.$Message.error('旧退款入口已停用，请使用操作列「退款」办理整单退款');
      this.refundModal = false;
    },
    doOpen() {
      this.putOpenRefund();
    },

        refundSelectionChange(selection)
        {
          this.refundSelection = selection;
        }
      ,
        refundNumChange({id, refundNum})
        {
          let result = this.refundSelection.find(item => item.id === id);
          if (result) {
            result.refundNum = refundNum;
          }
        }
      ,
        allReset()
        {
          this.isAll = 0;
          this.isCheckBox = false;
          this.checkUidList = [];
        }
      ,
        allPages(e)
        {
          this.isAll = parseInt(e);
          if (this.isAll === 0) {
            this.tableList.forEach((item) => {
              const id = parseInt(item.id);
              if (!this.checkUidList.includes(id)) {
                this.checkUidList.push(id);
              }
            });
          } else if (!this.isCheckBox) {
            this.isCheckBox = true;
            this.isAll = 1;
            this.checkUidList = [];
          } else {
            this.isCheckBox = false;
            this.isAll = 0;
            this.checkUidList = [];
          }
        }
      ,
        checkboxItem(e)
        {
          const id = parseInt(e.rowid);
          const checked = e.checked;
          const index = this.checkUidList.indexOf(id);
          if (this.isAll === 1) {
            if (checked) {
              if (index !== -1) {
                this.checkUidList = this.checkUidList.filter((item) => item !== id);
              }
            } else if (index === -1) {
              this.checkUidList.push(id);
            }
          } else if (checked) {
            if (index === -1) {
              this.checkUidList.push(id);
            }
          } else if (index !== -1) {
            this.checkUidList = this.checkUidList.filter((item) => item !== id);
          }
        }
      ,
        printOreder()
        {
          if (this.checkUidList.length > 10 || (this.isAll == 1 && this.total > 10)) {
            return this.$Message.error('最多批量打印10个订单')
          }
          let ids = []
          if (this.isAll == 1 && this.total <= 10) {
            this.tableList.forEach(item => {
              ids.push(parseInt(item.id))
            })
          }
          let pathInfo = this.$router.resolve({
            path: `${Setting.routePre}/order/distribution`,
            query: {
              id: this.isAll == 1 ? ids.join(',') : this.checkUidList.join(','),
            }
          });
          window.open(pathInfo.href, '_blank');
        }
      ,
        // 收银台跳移动端支付列表二维码
        qrcodeShow()
        {
          this.spin = true
          this.modalCode = true
          cashierScan()
              .then((res) => {
                this.spin = false
                this.qrcode = res.data
              })
              .catch((err) => {
                this.spin = false
                this.$Message.error(err.msg)
              })
        }
      ,
        // 查看子订单
        splitOrderDetail(row)
        {
          this.$router.push({
            path: 'split_list',
            query: {
              id: row.id,
              orderChartType: this.orderData.status,
            },
          })
        }
      ,
        // 操作更多
        changeMenu(row, name)
        {
          this.orderId = row.id
          // 退款弹窗：本金/赠金输入框固定显示，默认先给 0
          this.formValidate = {refundBen: "0",refundGive: '0'};
          switch (name) {
            case '1':
              this.delfromData = {
                title: '修改立即支付',
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
              this.$refs.remarks.modals = true
              this.$refs.remarks.formValidate.remark = row.remark
              break
            case '5':
              // 阶段5：详情「立即退款」改为整单退款入口
              this.openTerminalRefund(row);
              break
            case 'void':
              this.openTerminalVoid(row);
              break
            case 'reopen':
              this.doReopen(row);
              break
            case '55':
              this.getRefundData(row.id, row.refund_type)
              break;
            case '555':
              this.openRechargeTerminalRefund(row);
              break
              // case "6":
              // 	this.getRefundIntegral(row.id);
              // 	break;
              // case "7":
              // 	this.getNoRefundData(row.id);
              // 	break;
            case '8':
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
                    this.getData(row.id, 1)
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
            case "12":
              let pathInfo = this.$router.resolve({
                path: `${Setting.routePre}/order/distribution`,
                query: {
                  id: row.id
                }
              });
              window.open(pathInfo.href, '_blank');
              break;
            case '13':
              this.printImg(row.kuaidi_label);
              break;
            case '14':
              this.$refs.remarks_bak.modals = true
              this.$refs.remarks_bak.formValidate.remark = row.back_reason
              break
            default:
              this.delfromData = {
                title: '删除订单',
                url: `/order/del/${row.id}`,
                method: 'DELETE',
                ids: '',
              }
              this.delOrder(row, this.delfromData)
          }
        }
      ,
        // 仅退款（阶段5：禁止旧弹窗，改走整单终态）
        getOnlyRefundData(id, refund_type)
        {
          const row = this.rowActive && this.rowActive.id === id
            ? this.rowActive
            : { id, refund_type, paid: 1, refund_status: 0, order_type: 0, terminal_action: 0 };
          this.openTerminalRefund(row);
        }
      ,
        // 获取退款表单数据
        getRefundData(id, refund_type)
        {
          let orderChartType = this.orderData.status
          this.delfromData = {
            title: '是否立即退货',
            url: `/refund/agree/${id}`,
            method: 'get',
          }
          this.$modalSure(this.delfromData)
              .then((res) => {
                this.$Message.success(res.msg)
                this.getList()
              })
              .catch((res) => {
                this.$Message.error(res.msg)
              })
        }
      ,
        // 删除单条订单
        delOrder(row, data)
        {
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
        }
      ,
        // 获取详情表单数据
        getData(id, type)
        {
          getDataInfo(id)
              .then(async (res) => {
                if (!type) {
                  this.$refs.detailss.modals = true
                  this.$refs.detailss.activeName = this.activeName;
                } else if (this.$refs.detailss && this.$refs.detailss.modals) {
                  this.activeName = this.$refs.detailss.activeName;
                } else {
                  this.$refs.detailss.activeName = this.activeName;
                }
                if (res.data.orderInfo.cartInfo.length == 0) {
                  res.data.orderInfo.cartInfo = [
                    {product_type: 6},
                  ]
                }
                if (res.data.orderInfo.type == 11) {
                  res.data.orderInfo.cartInfo[0]._loading = false;
                  res.data.orderInfo.cartInfo[0].children = [];
                }
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
        }
      ,
        submitSuccess()
        {
          this.getList()
          this.$refs.detailss.modals = false;
          if (this.$refs.detailss.modals) {
            this.getData(this.orderId, 1)
          }
        }
      ,
        // 单个核销
        singleWrite(row)
        {
          let self = this
          this.$Modal.confirm({
            title: '提示',
            content: '确定要核销该订单吗？',
            cancelText: '取消',
            closable: true,
            maskClosable: true,
            onOk: function () {
              writeUpdate(row.order_id)
                  .then((res) => {
                    self.$Message.success(res.msg)
                    self.getList()
                    self.$refs.detailss.modals = false;
                    if (self.$refs.detailss.modals) {
                      self.getData(self.orderId, 1)
                    }
                  })
                  .catch((err) => {
                    self.$Message.error(err.msg)
                  })
            },
            onCancel: () => {
            },
          })
        }
      ,
        // 立即核销
        bindWrite(row)
        {
          if (row.total_num > 1 || row.product_type == 5 || row.product_type == 4) {
            this.orderNumId = row.order_id
            this.$refs.writeOff.modals = true
            this.$refs.writeOff.getWriteOff({oid: row.id})
          } else {
            if (row.product_type == 4) {
              this.$modalForm(orderWriteForm(row.id)).then((res) => {
                this.$Message.success(res.msg)
                this.getList()
                this.$refs.detailss.modals = false;
                if (this.$refs.detailss.modals) {
                  this.getData(this.orderId, 1)
                }
              });
            } else {
              this.singleWrite(row)
            }
          }
        }
      ,
        // 配送信息表单数据
        delivery(row)
        {
          getDistribution(row.id)
              .then(async (res) => {
                this.FromData = res.data
                this.$refs.edits.modals = true
                this.getData(this.orderId, 1)
              })
              .catch((res) => {
                this.$Message.error(res.msg)
              })
        }
      ,
        // 编辑
        edit(row)
        {
          this.$refs.changePrice.id = row.id;
          this.$refs.changePrice.ordeUpdateInfo(row.id);
          this.$refs.changePrice.priceModals = true;
        }
      ,
        // 修改成功(编辑只有未支付时出现)
        submitFail(label)
        {
          this.status = 0
          this.getList()
          this.getData(this.orderId, 1)
          if (label) {
            this.printImg(label);
          }
        }
      ,
        // 发送货
        sendOrder(row)
        {
          this.$store.commit('store/order/setSplitOrder', row.total_num)
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
        }
      ,
        canOrderRepay(row) {
          if (!row || row.is_debt_repay) return false;
          if (Number(row.refund_status) !== 0) return false;
          const pending = row.pending_debt_amount != null
            ? Number(row.pending_debt_amount)
            : Math.max(0, Number(row.debt_amount || 0) - Number(row.repaid_debt_amount || 0));
          return pending > 0;
        },
        openOrderRepay(row) {
          debtOrderDetailApi(row.id).then((res) => {
            const detail = res.data;
            if (!detail || Number(detail.status) !== 0 || Number(detail.pending_debt || 0) <= 0) {
              this.$Message.warning('当前订单无待还欠款');
              return;
            }
            this.$refs.debtRepayFlow.open({
              ...detail,
              uid: row.uid,
              original_source: Number(detail.original_source || row.source || 0),
            });
          }).catch((err) => {
            this.$Message.error(err.msg || '加载欠款失败');
          });
        },
        // 详情
        showUserInfo(row)
        {
          if (!row || !row.uid) return;
          this.$refs.userDetails.modals = true;
          this.$refs.userDetails.getDetails(row.uid);
          this.$refs.userDetails.changeType('card_holder');
        }
      ,
        //修改增加打印方法
        printImg(url)
        {
          printJS({
            printable: url,
            type: 'image',
            documentTitle: '快递信息',
            style: `img{
	      width: 100%;
	      height: 476px;
	    }`,
          });
        }
      ,
        // 店员列表
        staffList()
        {
          let data = {
            page: 0,
            limit: 0,
          }
          staffallInfo()
              .then((res) => {
                this.staffData = res.data
              })
              .catch((err) => {
                this.$Message.error(err.msg)
              })
        }
      ,
        // 订单头部数据
        getChart()
        {
          orderChart(this.orderData)
              .then((res) => {
                this.tablists = res.data
                this.orderChartType = res.data
              })
              .catch((err) => {
                this.$Message.error(err.msg)
              })
        }
      ,
        // 搜索
        userSearchs()
        {
          this.allReset();
          this.orderData.page = 1
          this.getList()
        }
      ,
        // 一般订单
        getList()
        {
          this.syncCombinationFilter();
          this.getChart()
          this.loading = true
          orderList(this.orderData)
              .then((res) => {
                let data = res.data
                data.data.forEach((item) => {
                  if (item.id == this.orderId) {
                    this.rowActive = item
                  }
                })
                this.tableList = data.data
                this.total = data.count
                this.loading = false
              })
              .catch((err) => {
                this.loading = false
                this.$Message.error(err.msg)
              })
        }
      ,
        doYeji(row, staffKind)
        {
          this.setYeji.staffChoose = [];
          this.staffIds = [];
          let that = this;
          //获取业绩 '类型 1充值 2购卡 3消耗
          var type = '';
          if (row.order_type == 1) {
            type = 1;
          }
          if (row.order_type == 2) {
            type = 3;
          }
          if (row.order_type == 0) {
            type = 2;
          }
          if (type == 2) {
            let activeTab = 'product';
            if (staffKind === 'craft') {
              activeTab = Number(row.shipping_type) === 2 ? 'writeOff' : 'product';
            }
            this.activeName = activeTab;
            this.changeMenu(row, '2')
            return;
          }
          getYeji({link_id: row.link_id, type: type, goods_id: 0, price: row.pay_price}).then((res) => {
            if (res.data) {
              that.setYeji = res.data;
              res.data.staffChoose.forEach(function (item) {
                that.staffIds.push(item.staff_id);
              })
            }
            that.$refs.yeji.staffForm.store_id = row.store_id
            that.$refs.yeji.getStaff();
            that.yejiVisible = true;
          })
        }
      ,
        pageChange(index)
        {
          this.orderData.page = index
          this.getList()
        }
      ,
        // 具体日期
        onchangeTime(e)
        {
          this.timeVal = e
          this.orderData.date_range = this.timeVal.join('-')
          this.orderData.page = 1
          if (!e[0]) {
            this.orderData.date_range = ''
          }
          this.allReset();
          this.getList()
        }
      ,
        // 选择时间
        selectChange()
        {
          this.orderData.page = 1
          this.timeVal = []
          this.getList()
        },
        async exports()
        {
          if (!this.checkUidList.length && this.isAll == 0) {
            return this.$Message.warning('请勾选要导出的订单')
          }
          this.syncCombinationFilter();
          let orderData = {
            ...this.orderData,
            ids: this.isAll == 1 ? '' : this.checkUidList.join(',')
          }
          let [th, filekey, data, fileName] = [[], [], [], '']
          // let fileName = "";
          let excelData = JSON.parse(JSON.stringify(orderData))
          excelData.page = 1
          for (let i = 0; i < excelData.page + 1; i++) {
            let lebData = await this.getExcelData(excelData)
            if (!fileName) fileName = lebData.filename
            if (!filekey.length) {
              filekey = lebData.filekey
            }
            if (!th.length) th = lebData.header
            if (lebData.export.length) {
              data = data.concat(lebData.export)
              excelData.page++
            } else {
              exportExcel(th, filekey, fileName, data)
              return
            }
          }
        }
      ,
        getExcelData(excelData)
        {
          return new Promise((resolve, reject) => {
            orderExport(excelData, 1).then((res) => {
              return resolve(res.data)
            })
          })
        }
      ,
        getOrderBenefits()
        {
          orderBenefits(this.orderId).then((res) => {
            this.benefitsInfo = res.data;
            this.writeSurplusTimes = res.data.write_surplus_times;
            this.writeTimes = res.data.write_times;
            this.remainingPrice = res.data.remaining_price;
            this.refundMoney = res.data.remaining_price;
            this.cardBenefits = res.data.cart_info;
          });
        }
      ,
        submitSuccessHandle(res)
        {
          if (res.data && res.data.status === false) {
            return this.$authLapse(res.data)
          }
          this.$authLapse(res.data);
          this.FromData = res.data;
          this.$refs.edits.modals = true;
        }
      ,
        // 派单-确认
        async onDeliveryOk()
        {
          if (!this.deliveryId) {
            return this.$Message.error('请选择配送员');
          }
          let response;
          const datas = {};
          const deliveryItem = this.deliveryList.find((item) => {
            return item.id === this.deliveryId;
          });
          datas.sh_delivery_name = deliveryItem.wx_name;
          datas.sh_delivery_id = deliveryItem.phone;
          datas.sh_delivery_uid = deliveryItem.uid;
          // 改派
          if (this.deliveryOrderData._status === 4) {
            response = await deliveryReassignApi(this.orderId, datas);
          } else {
            datas.type = 2;
            datas.delivery_type = 1;
            datas.sh_delivery = this.deliveryId;
            response = await putDelivery({id: this.orderId, datas});
          }
          if (response.status == 200) {
            this.modal3 = false;
            this.$Message.success(this.deliveryOrderData._status === 4 ? '改派成功' : '派单成功');
            this.submitFail(!response.data.dump || Array.isArray(response.data.dump) ? '' : response.data.dump.label);
          } else {
            this.$Message.error(response.msg);
          }
        }
      ,
        // 获取配送员
        getDeliveryList(delivery_uid = 0)
        {
          orderDeliveryList(this.formItem).then((res) => {
            this.deliveryList = res.data.list.map((item) => {
              return {
                ...item,
                disabled: item.uid === delivery_uid,
              };
            });
          });
        }
      ,
        // 重置
        onReset()
        {
          this.deliveryId = 0;
          this.formItem.keyword = '';
          this.getDeliveryList();
        }
      ,
        closeYeji()
        {
          this.yejiVisible = false;
          this.getList();
        }
      ,
        // 派单-打开配送员弹窗
        openModal3(row)
        {
          this.modal3 = true;
          this.deliveryId = 0;
          this.orderId = row.id;
          this.deliveryOrderData = row;
          this.formItem.keyword = '';
          this.getDeliveryList(row.delivery_uid);
        }
      ,
        // 重新发单
        orderReissueOrder(row)
        {
          orderReissueOrderApi(row.id, {
            store_id: row.store_id,
          }).then((res) => {
            this.$Message.success(res.msg);
            this.getList();
            this.getData(row.id, 1);
          }).catch((err) => {
            this.$Message.error(err.msg)
          });
        }
      ,
      }
    ,
    }
</script>

<style lang="stylus" scoped>
.tdinfo {
  margin-left: 75px;
  margin-top: 16px;
}

.expand-row {
  margin-bottom: 16px;
  font-size: 12px;
}

.new_tab {
  >>>.ivu-tabs-nav .ivu-tabs-tab {
    padding: 4px 16px 20px !important;
    font-weight: 500;
  }
}
.order-list-toolbar
  display flex
  align-items flex-start
  justify-content space-between
  .order-list-tabs
    flex 1
    min-width 0
  .field-toggle-wrap
    flex-shrink 0
    padding-top 8px

.action-link-gap
  margin-left 10px
    margin-left 16px
  .field-toggle-link
    color #7c6bae
    font-size 13px
    cursor pointer
    white-space nowrap
.field-toggle-panel
  padding 4px 0
  >>> .ivu-checkbox-group
    display flex
    flex-wrap wrap
  .field-toggle-item
    width 33.33%
    margin-right 0
    margin-bottom 12px
    font-size 13px
.mb12
  margin-bottom 12px

.QRpic {
  width: 180px;
  height: 259px;

img {
  width: 100%;
  height: 100%;
}

}
.cardCon {

/deep/ .ivu-card-body {
  padding: 14px 20px 0 20px !important;
}

/deep/ .ivu-tabs-bar {
  margin-bottom: 0px !important;
  border-bottom: 1px solid #E9E9E9 !important;
}

}

/deep/ .ivu-tabs-nav {
  height: 45px;
}

.pictrue-box {
  display: flex;
  align-item: center;
}

.search {
  width: 86px;
  height: 32px;
}

.pictrue {
  width: 25px;
  height: 25px;
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
  width: 36px;
  height: 36px;

img {
  width: 100%;
  height: 100%;
}

}

.tabBox_tit {
  max-width: 60%;
  font-size: 12px !important;
  margin: 0 2px 0 10px;
  letter-spacing: 1px;
  padding: 5px 0;
  box-sizing: border-box;
}

}

.trip {
  color: orange;
}

>>> .refund-modal {

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
