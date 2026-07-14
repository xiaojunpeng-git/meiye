<template>
  <div class="order-wrapper">
    <div v-show="active == 1" class="order">
      <div class="left">
        <div class="left-top">
          <div class="title">
            <div :class="{ active: active == 1 }" class="item" @click="active = 1">
              订单列表
            </div>
            <div :class="{ active: active == 2 }" class="item" @click="active = 2">
              房间管理
            </div>
          </div>
          <div v-if="active == 1" class="sx" @click="filterModal = !filterModal">
            {{ filterModal ? '关闭' : '筛选' }}
            <Icon
              v-if="!filterModal"
              class="ios-funnel-outline"
              color="#666"
              type="ios-funnel-outline"
            />
          </div>
        </div>
        <div class="order-box">
          <div class="search" v-if="!filterModal">
            <Input
              search
              v-model="orderData.keyword"
              enter-button="搜索"
              @on-search="search"
              size="large"
              placeholder="搜索订单流水号、订单编号"
            />
          </div>
          <orderList
            v-if="orderListData.length"
            orderType="table"
            v-show="!filterModal"
            :total="count"
            @addPage="addPage"
            @selectOrder="selectOrder"
            :orderData="orderListData"
          ></orderList>
          <div
            v-else-if="!orderListData.length && !filterModal"
            class="no-order"
          >
            <img src="../../assets/images/no-order.png" alt="" />
            <span class="trip">噢～目前暂无订单</span>
          </div>
          <filter-modal
            v-show="filterModal"
            order-type="table"
            @search="searchList"
          ></filter-modal>
        </div>
      </div>
      <div class="order-data">
        <div class="header">
          <div
            class="item"
            :class="
              sle === index
                ? 'sel'
                : index === sle - 1
                ? 'neighbor-left'
                : index === sle + 1
                ? 'neighbor-right'
                : 'def'
            "
            v-for="(tab, index) in tabs"
            v-show="selectOrderData.oid || !index"
            :key="index"
            @click="tabClick(index)"
          >
            <div class="item-wrap">
              {{ tab }}
            </div>
          </div>
          <div class="box" :class="sle === 2 ? 'neighbor-right' : ''"></div>
        </div>
        <div class="content" :class="{ 'border-radius': sle }">
          <userOrder
            class="orders"
            v-if="sle === 0 && selectOrderData.id"
            :canSend="canSend"
            :selectData="selectOrderData"
            @remarks="remarks"
            @rechargeBnt="rechargeBnt"
            @userChange="userChange"
            @calculate="calculate"
            @discountCon="discountCon"
			@delGoods="delGoods"
          ></userOrder>

          <orderDetails
            class="orders"
            v-if="
              sle === 1 && selectOrderData.oid && selectOrderData.orderId.id
            "
            :orderDatalist="orderInfoData"
          >
          </orderDetails>
          <orderRecord
            v-if="
              sle === 2 && selectOrderData.oid && selectOrderData.orderId.id
            "
            :id="selectOrderData.oid"
          ></orderRecord>
          <div v-else-if="!selectOrderData.id" class="no-order">
            <img src="../../assets/images/no-record.png" alt="" />
            <span class="trip">噢～目前暂无挂单记录</span>
          </div>
        </div>
        <div v-if="selectOrderData.id && (selectOrderData.status != 2 || (selectOrderData.orderId && !selectOrderData.orderId.is_del))" class="footer">
          <Button v-if="staff_id" @click="storeTap">{{ storeInfos.staff_name }}</Button>
          <div v-if="selectOrderData.orderId && selectOrderData.orderId.paid">
            <Button class="btn grey" @click="remarks">订单备注</Button>
            <Button class="btn grey" @click="point">小票打印</Button>
            <Button
              v-if="
                !selectOrderData.orderId.refund_status &&
                selectOrderData.orderId.paid &&
                selectOrderData.orderId.pay_price > 0
              "
              class="btn blue"
              @click="getRefundData"
              >立即退款</Button
            >
          </div>
          <div v-else-if="selectOrderData.status != 3 && (!selectOrderData.orderId || !selectOrderData.orderId.paid)">
            <Button class="btn red" @click="cancelTable">取消</Button>
            <Button v-if="selectOrderData.orderId && selectOrderData.orderId.pay_type == 'offline'" class="btn blue" @click="payOffline">立即支付</Button>
            <template v-else-if="selectOrderData.orderId && !selectOrderData.orderId.paid">
              <Button
                v-if="!selectOrderData.orderId.pay_type"
                :class="['btn', integral ? 'light' : 'grey']"
                @click="integralTap"
                >积分</Button
              >
              <Button class="btn grey" v-if="!selectOrderData.orderId.pay_type" @click="changePrice">改价</Button>
              <Button class="btn grey" @click="staffPlace">打单</Button>
              <Button class="btn" @click="payPrice('cash')">现金收款</Button>
              <Button class="btn" @click="payPrice('')">微信/支付宝</Button>
              <Button class="btn blue" @click="payPrice('yue')">余额收款</Button>
            </template>
          </div>
        </div>
      </div>
      <!-- 备注 -->
      <order-remark
        ref="remarks"
        :orderId="selectOrderData.orderId ? selectOrderData.orderId.id : 0"
        @submitFail="submitFail"
      ></order-remark>
      <orderSend
        ref="send"
        :orderId="orderId"
        :status="status"
        :pay_type="pay_type"
        @submitFail="send"
      ></orderSend>
    </div>
    <div v-show="active == 2" class="table-code">
      <div class="left-top">
        <div class="title">
          <div :class="{ active: active == 1 }" class="item" @click="active = 1">
            订单列表
          </div>
          <div :class="{ active: active == 2 }" class="item" @click="active = 2">
            房间管理
          </div>
        </div>
        <div v-if="active == 1" class="sx" @click="filterModal = !filterModal">
          {{ filterModal ? '关闭' : '筛选' }}
          <Icon
            v-if="!filterModal"
            class="ios-funnel-outline"
            color="#666"
            type="ios-funnel-outline"
          />
        </div>
      </div>
      <div class="section-wrapper">
      <div v-for="item in codeList" :key="item.id" class="section">
        <div class="head">{{ item.name }}（房间分类）</div>
        <div class="body">
          <ul class="list">
            <li
              v-for="qrcode in item.tableQrcode"
              :key="qrcode.id"
              :class="{ active: qrcode.room_occupied || qrcode.is_use }"
              class="item"
            >
              <div class="code">{{ getRoomName(qrcode) }}</div>
              <div v-if="qrcode.remarks" class="room-no">{{ qrcode.table_number }}号</div>
              <template v-if="qrcode.room_occupied">
                <div>当前 {{ qrcode.room_people_count }} 人在房间</div>
              </template>
              <template v-else-if="qrcode.is_use">
                <div>
                  {{ qrcode.eat_number }}人就餐（可容纳{{ qrcode.seat_num }}人）
                </div>
                <div class="time">{{ qrcode.order_time }} 下单</div>
              </template>
              <div v-else>空闲（可容纳{{ qrcode.seat_num }}人）</div>
            </li>
          </ul>
        </div>
      </div>
      </div>
    </div>
    <!-- 备注弹窗 -->
    <Modal v-model="modal" footer-hide title="备注">
      <Input
        :rows="5"
        maxlength="200"
        placeholder="订单备注"
        show-word-limit
        style="width: 100%"
        type="textarea"
      />
      <Button class="mt20" long type="primary">提交</Button>
    </Modal>
    <!-- 店员列表弹窗 -->
    <storeList
      ref="store"
      :storeInfo="storeInfos"
      @getStoreId="getStoreId"
      @getUserInfo="getUserInfo"
    ></storeList>
    <!-- 充值弹窗 -->
    <recharge
      ref="recharge"
      :userInfo="userInfo"
      @getSuccess="getSuccess"
    ></recharge>
    <Modal
      v-model="modalPay"
      class="modalPay"
      footer-hide
      width="430px"
      @on-cancel="modalPayCancel"
    >
      <div class="payPage">
        <div class="header acea-row row-center-wrapper">
          <div class="picture"><img src="../../assets/images/gold.png" /></div>
          <div class="text">应收金额(元)</div>
        </div>
        <div class="money">
          ¥<span class="num">{{
            selectOrderData.payPrice ? selectOrderData.payPrice : 0
          }}</span>
        </div>
        <Input
          ref="focusNum"
          v-model="payNum"
          placeholder="请点击输入框聚焦扫码或输入编码号"
          size="large"
          style="margin-top: 16px"
          type="url"
          @input="inputSaoMa"
        />
        <div class="process">
          <div class="picture">
            <img src="../../assets/images/process1.png" />
          </div>
          <div class="list acea-row row-between-wrapper">
            <div class="item one">
              <div class="name">
                {{
                  createOrder.pay_type == 'yue' ? '出示付款码' : '打开付款码'
                }}
              </div>
              <div>
                {{
                  createOrder.pay_type == 'yue'
                    ? '用户打开个人中心'
                    : '微信/支付宝付款码'
                }}
              </div>
            </div>
            <div class="item two">
              <div class="name">
                {{
                  createOrder.pay_type == 'yue' ? '扫描付款码' : '贴合付款盒子'
                }}
              </div>
              <div>
                {{ createOrder.pay_type == 'yue' ? '扫码枪' : '等待完成支付' }}
              </div>
            </div>
            <div class="item three">
              <div class="name">确认收款</div>
              <div>收银台确认</div>
            </div>
          </div>
        </div>
      </div>
    </Modal>
    <Modal
      v-model="modalCash"
      class="cash"
      footer-hide
      width="770px"
      @on-cancel="cancel"
    >
      <div class="cashPage acea-row">
        <div class="left">
          <div class="picture">
            <img src="../../assets/images/gold.png" />
          </div>
          <div class="text">应收金额(元)</div>
          <div class="money">
            ¥<span class="num">{{ selectOrderData.payPrice || 0 }}</span>
          </div>
        </div>
        <div class="right">
          <div class="rightCon">
            <div class="top acea-row row-between-wrapper">
              <div>实际收款(元)</div>
              <div class="num">{{ collection }}</div>
            </div>
            <div class="center acea-row row-between-wrapper">
              <div>需找零(元)</div>
              <div
                v-if="
                  this.$computes.Sub(
                    collection,
                    selectOrderData.payPrice ? selectOrderData.payPrice : 0
                  ) > 0
                "
                class="num"
              >
                {{
                  this.$computes.Sub(
                    collection,
                    selectOrderData.payPrice ? selectOrderData.payPrice : 0
                  )
                }}
              </div>
              <div v-else class="num">0</div>
            </div>
            <div class="bottom acea-row">
              <div
                v-for="(item, index) in numList"
                :key="index"
                :class="item == '.' ? 'spot' : ''"
                class="item acea-row row-center-wrapper"
                @click="numTap(item)"
              >
                {{ item }}
              </div>
              <div class="item acea-row row-center-wrapper" @click="delNum">
                <Icon type="ios-backspace" />
              </div>
            </div>
          </div>
          <Button type="primary" @click="cashBnt">确认</Button>
        </div>
      </div>
    </Modal>
    <Modal
      v-model="discount"
      class-name="vertical-center-modal"
      footer-hide
      title="优惠明细"
      width="400"
    >
      <div class="discountCon">
        <div class="item acea-row row-between-wrapper">
          <div>订单原价</div>
          <div>￥{{ selectOrderData.sumPrice || (selectOrderData.orderId && selectOrderData.orderId.total_price) || 0 }}</div>
        </div>
        <div class="item acea-row row-between-wrapper">
          <div>会员优惠金额：</div>
          <div>￥{{ selectOrderData.vipPrice || (selectOrderData.orderId && selectOrderData.orderId.vip_true_price) || 0 }}</div>
        </div>
        <div class="item acea-row row-between-wrapper">
          <div>积分抵扣：</div>
          <div>￥{{ selectOrderData.usedIntegral || (selectOrderData.orderId && selectOrderData.orderId.deduction_price) || 0 }}</div>
        </div>
        <div
          v-for="(item, index) in selectOrderData.promotionsDetail"
          :key="index"
          class="item acea-row row-between-wrapper"
        >
          <div>{{ item.title }}：</div>
          <div>￥{{ item.promotions_price || 0 }}</div>
        </div>
      </div>
    </Modal>
    <Modal v-model="refundVisible" title="退款处理" class-name="refund-modal">
      <Form :model="refundData" :label-width="90">
        <FormItem label="退款单号：">
          <Input v-model="refundData.order_id" disabled></Input>
        </FormItem>
        <FormItem label="退款金额：" required>
          <InputNumber v-model="refundData.refund_price" :max="9999999999" @on-change="numberChange"></InputNumber>
        </FormItem>
      </Form>
      <div slot="footer">
          <Button type="primary" size="large" long @click="orderRefund">提交</Button>
      </div>
    </Modal>
	<changePrice ref="changePrice" :type='1' @submitSuccess='submitSuccess'></changePrice>
  </div>
</template>

<script>
import changePrice from '@/components/changePrice';
import recharge from '@/components/recharge';
import storeList from '@/components/storeList';
import orderList from '@/components/orderList';
import goodsList from '@/pages/hang/components/goodsList';
import userOrder from '@/pages/table/components/userOrder';
import orderDetails from '@/pages/table/components/orderDetails';
import orderRecord from '@/components/orderRecord';
import orderRemark from '@/components/orderRemark';
import filterModal from '@/components/filterModal';
import orderSend from '@/pages/order/components/orderSend';
import "../../assets/js/core.js";
import {
  getOrderInfo,
  getRefundOrderFrom,
  erpConfig,
  getCodeList,
  getTableList,
  getTableUidAll,
  getCartList,
  cashierCartList,
  cashierCompute,
  cashierCode,
  cancelTable,
  editTableCart,
  staffPlace,
  swithUser,
  cashierCreate,
  getTableOrderInfo,
  payOffline,
  cashierPay,
  orderRefund,
} from '@/api/order';

export default {
  components: {
	changePrice,
    recharge,
    storeList,
    orderList,
    goodsList,
    userOrder,
    orderDetails,
    orderRemark,
    orderRecord,
    filterModal,
    orderSend,
  },
  data() {
    return {
      orderId: 0,
      orderListData: [],
      tabs: ['商品信息', '订单详情', '订单记录'],
      sle: 0,
      filterModal: false,
      userFrom: {
        keyword: '',
        page: 1,
        limit: 9,
      },
      orderData: {
        type: '10',
        status: '',
        time: '',
        staff_id: '',
        serial_number: '',
        page: 1,
        limit: 10,
      },
      selectOrderData: {},
      orderInfoData: {},
      count: 0,
      status: 0,
      pay_type: '',
      canSend: true,
      tengxun_map_key: '',
      open_erp: null,
      active: 1,
      modal: false,
      userInfo: {},
      storeInfos: {}, //门店店员信息
      storeList: [], //门店列表
      goodFrom: {
        store_name: '',
        field_key: 'all',
        cate_id: '',
        page: 1,
        limit: 12,
        uid: 0,
        staff_id: 0,
      },
      codeList: [],
      uid: 0,
      staff_id: 0,
      cart_ids: '',
      table_id: 0,
      payNum: '',
      createOrder: {
        remarks: '',
        change_price: 0,
        cart_id: [], // 购物车id
        userCode: '',
        is_price: 0,
        auth_code: '',
      },
      modalPay: false,
      priceInfo: {},
      orderSystem: {
        loadingMsg: null,
        timer: null,
      },
      modalCash: false,
      collectionArray: [],
      collection: 0,
      numList: ['7', '8', '9', '4', '5', '6', '1', '2', '3', '0', '.'],
      integral: false, //是否使用积分
      discount: false,
      isOrderCreate: 0,
      codeNum: "",
      refundVisible: false,
      refundData: {
        order_id: 0,
        refund_price: 0,
      }
    };
  },
  computed: {
    isCashierCartList: function () {
      let { uid, staff_id, cart_ids } = this;
      let { oid } = this.selectOrderData;
      return {
        uid,
        staff_id,
        cart_ids,
        oid,
      };
    },
  },
  watch: {
    count(value) {
      if (value) {
        this.table_id = this.orderListData[0].id;
      } else {
        this.selectOrderData = {};
      }
    },
    table_id(value) {
      let result = this.orderListData.find(({ id }) => id == value);
      this.selectOrderData = {};
      this.uid = 0;
      if (result) {
        this.selectOrderData = { ...result };
        this.getTableUidAll();
      }
    },
    uid(value) {
      if (value && this.selectOrderData.status == 1) {
        this.getCartList();
      } else {
        this.cart_ids = '';
      }
    },
    cart_ids(value) {
      if (value) {
        this.cashierCompute();
      } else {
		this.unchangedPrice = 0;
	  }
    },
    integral() {
      this.cashierCompute();
    },
    isCashierCartList(newVal, oldVal) {
      const { uid, staff_id, cart_ids, oid } = newVal;
      let flag = true;
      for (const key in newVal) {
        if (Object.hasOwnProperty.call(newVal, key)) {
          if (newVal[key] != oldVal[key]) {
            flag = false;
            break;
          }
        }
      }
      if (!flag) {
        if (uid && staff_id && cart_ids && !oid) {
          this.cashierCartList();
        }
      }
    },
    'selectOrderData.oid'(value) {
      if (value) {
        this.getTableOrderInfo();
      }
    },
    active(value) {
      if (value == 2) {
        this.getCodeList();
      }
      if (this.timer) {
        clearTimeout(this.timer);
      }
    }
  },
  created() {
    this.getOrderList();
    this.getErpConfig();
  },
  methods: {
    numberChange(value) {
      this.$nextTick(() => {
        let string = Number(value).toString();
        let index = string.indexOf('.');
        if (-1 === index || 2 >= string.length - index - 1) {
          this.refundData.refund_price = Number(string);
        } else {
          this.refundData.refund_price = Number(Number(string).toFixed(2));
        }
      });
    },
    jsToJava(){
      try {
        window.Jsbridge.invoke('openCacheBox',JSON.stringify({'p1-key':'p1-value'}), this.myFunction() );
      }catch (e) {

      }
    },
    myFunction(){
      
    },
    // 线下确定结算
    payOffline() {
      payOffline(this.selectOrderData.oid).then(() => {
        this.$Message.success('支付成功');
        this.selectOrderData.orderId.paid = 1;
        for (const selectOrderData of this.orderListData) {
          if (selectOrderData.id == this.selectOrderData.id) {
            selectOrderData.orderId.paid = 1;
            break;
          }
        }
      }).catch(err => {
        this.$Message.error(err.msg);
      });
    },
    // 获取订单信息
    getTableOrderInfo() {
      getTableOrderInfo(this.selectOrderData.oid).then(res => {
        this.selectOrderData = { ...this.selectOrderData, orderId: res.data, is_cashier_yue_pay_verify: res.data.is_cashier_yue_pay_verify };
        this.refundData.order_id = res.data.order_id;
        this.refundData.refund_price = +res.data.pay_price;
      });
    },
    // 线上支付和余额支付
    confirm() {
      if (this.payType == "yue") {
        if (!this.createOrder.userCode && this.selectOrderData.is_cashier_yue_pay_verify) {
          return this.$Message.error("请扫描个人中心二维码");
        }
        if (this.isOrderCreate) {
          this.getCashierPay("yue");
        } else {
          this.orderCreate();
        }
      } else if (this.payType == "") {
        if (!this.createOrder.auth_code) {
          return this.$Message.error("请扫描您的付款码");
        }
        if (this.isOrderCreate) {
          this.getCashierPay("");
        } else {
          this.orderCreate();
        }
      }
    },
    getCashierPay(payType) {
      let data = {
        payType: payType,
        userCode: this.payNum,
        auth_code: this.payNum,
      };
      if (payType == "cash") {
        if (parseFloat(this.selectOrderData.payPrice) > parseFloat(this.collection)) {
          return this.$Message.error("您付款金额不足");
        }
      }
      cashierPay(this.selectOrderData.orderId.order_id, data)
        .then((res) => {
          this.payNum = "";
          if (res.data.status == "SUCCESS") {
            this.isOrderCreate = 0;
            this.$Message.success("支付成功");
            this.modalCash = false;
            this.modalPay = false;
            this.selectOrderData.orderId.paid = 1;
            for (const selectOrderData of this.orderListData) {
              if (selectOrderData.id == this.selectOrderData.id) {
                selectOrderData.orderId.paid = 1;
                break;
              }
            }
            //现金收款打开钱箱
            if (payType == "cash") {
              this.jsToJava();
            }
          } else if (res.data.status == "PAY_ING") {
            let msg = this.$Message.loading({
              content: "等待支付中...",
              duration: 0,
            });
            this.orderSystem.loadingMsg = msg;
            this.orderId = res.data.order_id;
            this.checkOrderTime(msg);
          } else {
            this.isOrderCreate = 1;
            this.orderId = res.data.order_id;
            this.$Message.error(res.data.message);
          }
        })
        .catch((err) => {
          this.payNum = "";
          this.$Message.error(err.msg);
        });
    },
    // 创建订单
    orderCreate() {
      if (this.payType == "cash") {
        if (parseFloat(this.selectOrderData.payPrice) > parseFloat(this.collection)) {
          return this.$Message.error("您付款金额不足");
        }
      }
      this.createOrder.new = 1;
      this.createOrder.collate_code_id = this.table_id;
      this.createOrder.cart_id = this.cart_ids.split(',');
      cashierCreate(this.uid, this.createOrder)
        .then((res) => {
          let storage = window.localStorage;
          this.payNum = "";
          if (this.payType == "yue") {
            this.modalPay = false;
            this.payNum = "";
            this.createOrder.userCode = "";
            if (res.data.status == "ORDER_CREATE") {
              this.isOrderCreate = 1;
              this.orderId = res.data.order_id;
              this.$Message.success(res.data.message);
            } else if (res.data.status == "SUCCESS") {
              this.isOrderCreate = 0;
              this.$Message.success("支付成功");
              this.payTypeModal = false;
              this.modalPay = false;
              this.selectOrderData = { ...this.selectOrderData, oid: res.data.oid };
              for (const orderData of this.orderListData) {
                if (orderData.id == this.selectOrderData.id) {
                  orderData.status = 2;
                  orderData.oid = res.data.oid;
                  if (Object.prototype.toString.call(orderData.orderId) != '[object Object]') {
                    orderData.orderId = {};
                  }
                  orderData.orderId = { ...orderData.orderId, paid: 1 };
                  break;
                }
              }
            } else {
              this.isOrderCreate = 1;
              this.orderId = res.data.order_id;
              this.$Message.error(res.data.message);
            }
          }
          if (this.payType == "cash") {
            if (res.data.status == "SUCCESS") {
              this.$Message.success("支付成功");
              this.selectOrderData = { ...this.selectOrderData, oid: res.data.oid };
              for (const orderData of this.orderListData) {
                if (orderData.id == this.selectOrderData.id) {
                  orderData.status = 2;
                  orderData.oid = res.data.oid;
                  if (Object.prototype.toString.call(orderData.orderId) != '[object Object]') {
                    orderData.orderId = {};
                  }
                  orderData.orderId = { ...orderData.orderId, paid: 1 };
                  break;
                }
              }
              this.modalCash = false;
              this.payTypeModal = false;
              // this.clear();
              this.jsToJava();
            }
          }
          if (this.payType == "") {
            this.payNum = "";
            this.createOrder.auth_code = "";
            if (res.data.status == "ORDER_CREATE") {
              this.isOrderCreate = 1;
              this.orderId = res.data.order_id;
              this.$Message.success(res.data.message);
            } else if (res.data.status == "PAY_ING") {
              let msg = this.$Message.loading({
                content: "等待支付中...",
                duration: 0,
              });
              this.orderId = res.data.order_id;
              this.checkOrderTime(msg);
            } else if (res.data.status == "SUCCESS") {
              this.$Message.success("支付成功");
              this.selectOrderData = { ...this.selectOrderData, oid: res.data.oid };
              for (const orderData of this.orderListData) {
                if (orderData.id == this.selectOrderData.id) {
                  orderData.status = 2;
                  orderData.oid = res.data.oid;
                  if (Object.prototype.toString.call(orderData.orderId) != '[object Object]') {
                    orderData.orderId = {};
                  }
                  orderData.orderId = { ...orderData.orderId, paid: 1 };
                  break;
                }
              }
              this.modalPay = false;
              this.clear();
            } else {
              this.isOrderCreate = 1;
              this.orderId = res.data.order_id;
              this.$Message.error(res.data.message);
            }
          }
        })
        .catch((err) => {
          this.payNum = "";
          this.$Message.error(err.msg);
        });
    },
    //输入实际收款金额
    numTap(item) {
      if (this.defaultcalc === false) {
        this.collection = "";
        this.defaultcalc = true;
      }
      let x = String(this.collection).indexOf(".") + 1;
      let y = String(this.collection).length - x;
      if (x === 0 || y < 2) {
        if (this.collectionArray.join("") <= 9999999) {
          this.collectionArray.push(item);
        }
        this.collection =
          this.collectionArray.join("") > 99999999
            ? 99999999
            : this.collectionArray.join("");
      }
    },
    getSwithUser(data){
      swithUser(data).then(res=>{}).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    //点击出现优惠明细
    discountCon() {
      this.discount = true;
    },
    // 打单
    staffPlace() {
      staffPlace({
        tableId: this.table_id,
      })
        .then((res) => {})
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    calculate(data) {
      editTableCart({
        productId: data.product_id,
        cartNum: 1,
        uniqueId: data.product_attr_unique || 0,
        tableId: this.table_id,
        isAdd: data.isAdd,
      })
        .then((res) => {
          this.getCartList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
	// 删除商品
	delGoods(){
		this.getCartList();
	},
    // 取消桌码订单
    cancelTable() {
      this.$Modal.confirm({
        title: '取消该桌码订单',
        content:
          '<p>确定要取消该桌码订单吗？</p><p>取消该桌码订单后将无法恢复，请谨慎操作！</p>',
        onOk: () => {
          cancelTable({
            tableId: this.selectOrderData.id,
            qrcode_id: this.selectOrderData.qrcode_id,
          }).then((res) => {
            this.$Message.success('取消该桌码订单成功');
            for (let i = 0; i < this.orderListData.length; i++) {
              if (this.orderListData[i].id == this.selectOrderData.id) {
                if (this.orderListData[i + 1]) {
                  this.table_id = this.orderListData[i + 1].id;
                } else if (this.orderListData[i - 1]) {
                  this.table_id = this.orderListData[i - 1].id;
                } else {
                  this.table_id = 0;
                }
                this.orderListData.splice(i, 1);
                break;
              }
            }
          });
        },
      });
    },
    cancelPrice() {
    },
    // 点击改价
    changePrice() {
	   let data = {
		   table_id: this.table_id,
		   uid:  this.uid
	   }
	   this.selectOrderData.payPrice = Number(this.selectOrderData.pay_price);
	   this.$refs.changePrice.ordeTableUpdateInfo(data);
	   this.$refs.changePrice.priceModals = true;
	   this.$refs.changePrice.tableInfo = data;
    },
	submitSuccess(data){
		this.selectOrderData.payPrice = data;
		this.priceInfo.payPrice = data;
		this.createOrder.is_price = 1;
		this.createOrder.change_price = data;
		this.getSwithUser({ change_price: data });
		this.cancelPrice();
	},
    // 计算金额
    cartCompute() {
      if (!this.cartList.length) {
        this.priceInfo = {};
        return;
      }
      let ids = [];
      this.cartList.forEach((item) => {
        item.cart.forEach((good) => {
          ids.push(good.id);
        });
      });
      this.createOrder.cart_id = ids;
      let data = {
        integral: this.integral,
        coupon: this.coupon,
        coupon_id: this.couponId,
        cart_id: ids,
      };
      cashierCompute(this.userInfo.uid, data)
        .then((res) => {
          this.priceInfo = res.data;
          this.unchangedPrice = this.priceInfo.payPrice || 0;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
          this.coupon = false;
        });
    },
    // 是否使用积分
    integralTap() {
      if (!this.selectOrderData.uid) {
        this.$Message.warning('请先选择用户再使用积分');
        return;
      }
      this.integral = !this.integral;
      if (this.integral) this.createOrder.is_price = 0;
    },
    //清除计算机输入的数字
    delNum() {
      this.collectionArray.pop();
      this.collection = this.collectionArray.length
        ? this.collectionArray.join('')
        : 0;
    },
    //现金收款创建订单并支付
    cashBnt() {
      if (this.cashBntLoading) return;
      this.cashBntLoading = true;
      if (this.isOrderCreate) {
        this.getCashierPay('cash');
      } else {
        this.orderCreate();
      }
      setTimeout(() => {
        this.cashBntLoading = false;
      }, 1000);
    },
    cancel() {
      this.collection = 0;
      this.collectionArray = [];
    },
    // 监听键盘函数
    keyboard() {
      let that = this;

      function delNums(item) {
        that.collectionArray.pop();
        that.collection = that.collectionArray.length
          ? that.collectionArray.join('')
          : 0;
      }

      function numTaps(item) {
        if (that.defaultcalc === false) {
          that.collection = '';
          that.defaultcalc = true;
        }
        let x = String(that.collection).indexOf('.') + 1;
        let y = String(that.collection).length - x;
        if (x === 0 || y < 2) {
          if (that.collectionArray.join('') <= 9999999) {
            that.collectionArray.push(item);
          }
          that.collection =
            that.collectionArray.join('') > 99999999
              ? 99999999
              : that.collectionArray.join('');
        }
      }

      document.onkeydown = function (event) {
        let e = event || window.event;
        let key = e.keyCode;
        if (that.modalCash) {
          event.stopPropagation(); // 阻止事件冒泡传递
          event.preventDefault(); //阻止默认事件原有功能
        }
        switch (key) {
          case 96:
          case 48:
            numTaps(0);
            break;
          case 97:
          case 49:
            numTaps(1);
            break;
          case 98:
          case 50:
            numTaps(2);
            break;
          case 99:
          case 51:
            numTaps(3);
            break;
          case 100:
          case 52:
            numTaps(4);
            break;
          case 101:
          case 53:
            numTaps(5);
            break;
          case 102:
          case 54:
            numTaps(6);
            break;
          case 103:
          case 55:
            numTaps(7);
            break;
          case 104:
          case 56:
            numTaps(8);
            break;
          case 105:
          case 57:
            numTaps(9);
            break;
          case 110:
            numTaps('.');
            break;
          case 190:
            numTaps('.');
            break;
          case 8:
            delNums();
            break;
        }
      };
    },
    //扫码枪扫码，针对带有字母的
    inputSaoMa(e) {
      // setTimeout定时器的作用是，等待扫码枪输入完，拿到完整的二维码信息，再调接口（扫码枪输入速度大概8~20毫秒，手动输速度大概是80毫秒），否则拿不到完整的二维信息。
      let val = e;
      if (val === '') return false;
      clearTimeout(this.endTimeout);
      this.endTimeout = null;
      this.endTimeout = setTimeout(() => {
        if (this.codeNum === val) {
          clearTimeout(this.endTimeout);
          if (val) {
            this.codeInfo({
              bar_code: val,
            });
          }
        }
      }, 500);
    },
    modalPayCancel() {
      this.$Message.destroy();
      if (this.orderSystem.timer) {
        clearInterval(this.orderSystem.timer);
        this.orderSystem.timer = null;
      }
    },
    codeInfo(data) {
      data.uid = this.uid || 0;
      data.staff_id = this.storeInfos.id || 0;
      cashierCode(data)
        .then((res) => {
          this.codeNum = '';
          let data = res.data;
          if (data.hasOwnProperty('userInfo')) {
            // 用户 Object.keys(this.userInfo).length
            if (this.userInfo) {
              this.$Modal.confirm({
                title: '切换用户',
                content: '<p>确定要切换用户吗？</p>',
                onOk: () => {
                  this.userInfo = res.data.userInfo;
                  let storage = window.localStorage;
                  storage.setItem(
                    'cashierUser',
                    JSON.stringify(res.data.userInfo)
                  );
                  this.getCartList();
                },
                onCancel: () => {},
              });
            } else {
              this.userInfo = res.data.userInfo;
              let storage = window.localStorage;
              storage.setItem('cashierUser', JSON.stringify(res.data.userInfo));
            }
          }
          this.goodList();
          this.getCartList();
        })
        .catch((err) => {
          this.codeNum = '';
          this.$Message.error(err.msg);
        });
    },
    payPrice(payType) {
      this.payType = payType;
      if (this.selectOrderData.oid) {
          this.isOrderCreate = true;
          this.selectOrderData.payPrice = Number(this.selectOrderData.pay_price) || 0;
        }
      if (payType == '' || payType == 'yue') {
        this.createOrder.userCode = '';
        this.createOrder.auth_code = '';
        this.payNum = '';
        if (payType == '' || this.selectOrderData.is_cashier_yue_pay_verify) {
          this.modalPay = true;
          let that = this;
          this.$nextTick(() => {
            this.$refs.focusNum.focus();
            document.onkeydown = function (e) {
              if (e.which == 13) {
                if (that.payNum) {
                  that.createOrder.userCode = that.payNum;
                  that.createOrder.auth_code = that.payNum;
                  that.confirm();
                }
                if (that.codeNum) {
                  that.codeInfo({
                    bar_code: that.codeNum,
                  });
                }
              }
            };
          });
        } else {
          this.confirm();
        }
      } else if (payType == 'cash') {
        this.modalCash = true;
        if (this.selectOrderData.oid) {
          this.isOrderCreate = true;
          this.selectOrderData.payPrice = Number(this.selectOrderData.pay_price) || 0;
        }
        this.collection = this.selectOrderData.payPrice || 0;
        this.keyboard();
      }
      this.createOrder.integral = this.integral;
      this.createOrder.coupon = this.coupon;
      this.createOrder.coupon_id = this.couponId;
      if (this.coupon && !this.couponId)
        return this.$Message.error('请选择有效优惠券');
      this.createOrder.pay_type = payType;
      this.createOrder.staff_id = this.storeInfos.id;
    },
    // 选择用户
    userChange(uid) {
      this.uid = uid;
      this.selectOrderData = {
        ...this.selectOrderData,
        uid,
      };
    },
    cashierCompute() {
      cashierCompute(this.uid, {
        new: 1,
        coupon_id: 0,
        coupon: false,
        integral: this.integral,
        cart_id: this.cart_ids.split(','),
      }).then((res) => {
        this.selectOrderData = {
          ...this.selectOrderData,
          ...res.data,
        };
		this.unchangedPrice = this.selectOrderData.payPrice;
      });
    },
    // 获取购物车商品
    cashierCartList() {
      cashierCartList(this.uid, this.staff_id, {
        new: 1,
        cart_ids: this.cart_ids,
      }).then((res) => {
        let { valid } = res.data;
        let _info = [];
        if (valid.length) {
          _info = valid;
        }
        this.selectOrderData = {
          ...this.selectOrderData,
          orderId: { _info },
        };
      });
    },
    // 获取桌码订单cartid
    getCartList() {
      getCartList({
        table_id: this.table_id,
        uid: this.uid,
      }).then((res) => {
        this.cart_ids = res.msg;
      });
    },
    // 获取桌码订单用户
    getTableUidAll() {
      getTableUidAll({
        table_id: this.table_id,
      }).then((res) => {
        const data = res.data;
        if (data.length) {
          this.uid = data[0].uid;
        }
        this.selectOrderData = {
          ...this.selectOrderData,
          userList: data,
          uid: this.uid
        };
      });
    },
    // 房间列表
    getCodeList() {
      getCodeList().then((res) => {
        this.codeList = res.data.filter((item) => {
          return item.tableQrcode.length;
        });
        if (this.active === 2) {
          this.timer = setTimeout(this.getCodeList, 10000);
        }
      });
    },
    getRoomName(qrcode) {
      return qrcode.remarks || qrcode.table_number || `房间${qrcode.id}`;
    },
    // 充值
    rechargeBnt(userInfo) {
      this.userInfo = userInfo;
      this.$refs.recharge.modal = true;
    },
    getSuccess(e) {
      let money = this.$computes.Add(this.userInfo.now_money, e);
      this.userInfo.now_money = money;
      let storage = window.localStorage;
      storage.setItem('cashierUser', JSON.stringify(this.userInfo));
    },
    // 选择店员
    storeTap() {
      this.$refs.store.modals = true;
      this.$refs.store.cancel();
    },
    // 门店店员信息以及门店店员列表
    getUserInfo(e) {
      this.staff_id = e.users.id;
      this.storeInfos = e.users;
      this.storeList = e.storeList;
      this.goodFrom.staff_id = e.users.id;
      sessionStorage.setItem('staffInfo', JSON.stringify(e.users));
    },
    // 当前选中门店店员信息
    getStoreId(e) {
      this.storeList.forEach((i) => {
        if (i.id == e.id) {
          this.storeInfos = i;
          this.staff_id = i.id;
        }
      });
    },
    orderSend() {
      this.$store.commit(
        'store/order/setSplitOrder',
        this.selectOrderData.total_num
      );
      this.$refs.send.modals = true;
      this.orderId = this.selectOrderData.id;
      this.status = this.selectOrderData._status;
      this.pay_type = this.selectOrderData.pay_type;
      this.$refs.send.getList();
      this.$refs.send.getDeliveryList();
      this.$nextTick((e) => {
        this.$refs.send.getCartInfo(
          this.selectOrderData._status,
          this.selectOrderData.id
        );
      });
    },
    addPage() {
      if (this.orderListData.length < this.count) this.orderData.page++;
      this.getOrderList();
    },
    searchList(data) {
      let status = '';
      this.filterModal = false;
      switch (data.status) {
        case 0:
          status = 1;
          break;
        case 3:
          status = 2;
          break;
        case 4:
          status = 3;
          break;
      }
      this.orderData = { ...this.orderData, ...data, status };
      this.orderData.type = '';
      this.sle = 0;
      this.search();
    },
    point() {
      this.delfromData = {
        title: '立即打印订单',
        info: '您确认打印此订单吗?',
        url: `/order/print/${this.selectOrderData.oid}`,
        method: 'get',
        ids: '',
      };
      this.$modalSure(this.delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    search() {
      this.orderData.page = 1;
      this.orderListData = [];
      this.getOrderList();
    },
    // 设置备注
    remarks() {
      this.$refs.remarks.modals = true;
      this.$refs.remarks.formValidate.remark =
        this.selectOrderData.orderId.remark;
    },
    send() {
      this.canSend = false;
      this.search();
    },
    // 备注修改成功
    submitFail() {
    },
    // 获取退款表单数据
    getRefundData() {
      if (this.selectOrderData.refund_type === 2) {
        this.delfromData = {
          title: '是否立即退货',
          url: `/refund/agree/${this.selectOrderData.id}`,
          method: 'get',
        };
        this.$modalSure(this.delfromData)
          .then((res) => {
            this.$Message.success(res.msg);
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
      } else {
        this.refundVisible = true
        return
        this.$modalForm(
          getRefundOrderFrom(this.selectOrderData.orderId.id)
        ).then(() => {
          this.selectOrderData.orderId.refund_status = 2;
          this.$emit('changeGetTabs');
        });
      }
    },
    // 订单详情
    getOrderInfo(id) {
      if (id)
        getOrderInfo(id)
          .then((res) => {
            this.orderInfoData = res.data;
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
    },
    selectOrder(data) {
      this.sle = 0;
      this.table_id = data.id;
    },
    tabClick(index) {
      switch (index) {
        case 1:
          this.getOrderInfo(this.selectOrderData.oid);
      }
      this.sle = index;
    },
    // 获取订单列表
    getOrderList() {
      getTableList(this.orderData)
        .then((res) => {
          this.count = res.data.count;
          let orderListData = res.data.data.map((item) => {
            const { category, table_number } = item.qrcode;
            let _infoData = [];
            if (!Array.isArray(item.cartList)) {
              Object.keys(item.cartList).forEach((key) => {
                _infoData.push({ cart_info: item.cartList[key] });
              });
            }
            item.color = '#f5222d';
            item.pink_name = `${category.name}${table_number}`;
            item._infoData = _infoData;
            item.pay_price = item.sum_price;
            item.total_num = item.cart_num;
            return item;
          });
          this.orderListData = [
            ...this.orderListData,
            ...orderListData
          ];
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    getErpConfig() {
      erpConfig()
        .then((res) => {
          this.open_erp = res.data.open_erp;
          this.tengxun_map_key = res.data.tengxun_map_key;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    orderRefund() {
      orderRefund(this.selectOrderData.oid, this.refundData).then(res => {
        this.refundVisible = false;
        this.$Message.success(res.msg);
        let index = this.orderListData.findIndex((item) => {
          return item.id == this.table_id;
        });
        this.orderListData[index].orderId.refund_status = 2;
        this.selectOrderData = this.orderListData[index];
      }).catch(err => {
        this.$Message.error(err.msg);
      });
    }
  },
};
</script>
<style scoped lang="stylus">
::-webkit-scrollbar-thumb {
  -webkit-box-shadow: inset 0 0 6px #ccc;
}

::-webkit-scrollbar {
  width: 0px !important;
  /* 对垂直流动条有效 */
}

.order-wrapper {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  padding: 20px;
  background: #F5F5F5;
}

.left-top {
  &.absolute {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 9;
  }
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 24px;

  .title {
    color: rgba(0, 0, 0, 0.85);
    font-size: 20px;

    .item {
      display: inline-block;
      cursor: pointer;

      +.item {
        margin-left: 20px;
      }

      &.active {
        font-weight: 600;
      }
    }
  }

  .sx {
    color: #666666;
    cursor: pointer;
    font-size: 14px;

    .ios-funnel-outline {
      font-weight: bold;
      font-size: 12px;
    }
  }
}

.order {
  flex: 1;
  display: flex;

  .left {
    display: flex;
    flex-direction: column;
    width: 460px;
    border-radius: 20px;
    background: #FFFFFF;

    .order-box {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 0;
      font-size: 18px;

      .search {
        padding: 0 24px 24px;

        /deep/.ivu-input {
          padding-left: 14px;
          border-color: #DDDDDD;
          border-radius: 20px 0 0 20px;
        }

        /deep/.ivu-input-search {
          border-radius: 0px 20px 20px 0px;
          background: #1890FF !important;
          font-size: 14px;
          line-height: normal;
        }
      }

      .order-list {
        flex: 1;
      }

      .content {
        flex: 1;
        min-height: 0;
      }
    }
  }

  .order-data {
    flex: 1;
    display: flex;
    flex-direction: column;
    border-radius: 20px;
    margin-left: 20px;

    .content {
      flex: 1;
      padding: 24px;
      border-radius: 0 20px 0 0;
      background: #FFFFFF;
      overflow-x: hidden;
    }

    .border-radius {
      border-radius: 20px 20px 0 0;
    }

    .header {
      display: flex;
      background: #FFFFFF;
      font-size: 18px;

      .box {
        flex: 1;
        background: #F5F5F5;
      }

      .item {
        cursor: pointer;
        background-color: #F5F5F5;
        transition: all 0.1s;
      }

      .item-wrap {
        padding: 16px 29px;
        border-radius: 20px 20px 0px 0px;
      }

      .sel {
        color: rgba(0, 0, 0, 0.85);
        font-weight: 500;

        .item-wrap {
          background: #FFFFFF;
        }
      }

      .neighbor-left {
        border-bottom-right-radius: 20px;
      }

      .neighbor-right {
        border-bottom-left-radius: 20px;
      }
    }

    .orders {
      flex: 1;
      display: flex;
      flex-direction: column;
      max-height: calc(100% - 53px);
    }
  }
}

.footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 22px 24px;
  border-radius: 0 0 20px 20px;
  background: #FFFFFF;
  box-shadow: 0 -1px 11px 0 rgba(0,0,0,0.06);

  .btn {
    width: 120px;
    height: 44px;
    border: 1px solid #1890FF;
    border-radius: 22px;
    margin-left: 15px;
    font-size: 18px !important;
    color: #1890FF;

    &:focus {
      box-shadow: none;
    }

    &.red {
      border-color: #FF0000;
      color: #FF0000;
    }

    &.grey {
      border-color: #F2F3F5;
      background-color: #F2F3F5;
      color: #000000;
    }

    &.blue {
      background-color: #1890FF;
      color: #FFFFFF;
    }

    &.light {
      border-color: rgba(24, 144, 255, 0.1);
      background-color: rgba(24, 144, 255, 0.1);
    }
  }
}

.table-code {
  flex: 1;
  display: flex;
  flex-direction: column;
  border-radius: 20px;
  background: #FFFFFF;

  .section-wrapper {
    flex: 1;
    overflow-x: hidden;
  }

  .section {
    padding: 0 20px;

    .head {
      font-weight: 600;
      font-size: 16px;
      line-height: 18px;
      color: #999999;
    }

    .body {
      margin-top: 15px;
    }

    .list {
      display: flex;
      flex-wrap: wrap;
      list-style: none;
    }

    .item {
      width: 140px;
      height: 140px;
      padding: 25px 0 0 20px;
      border: 1px solid #CCCCCC;
      border-radius: 6px;
      margin: 0 20px 20px 0;
      background: #F5F5F5;
      font-size: 12px;
      line-height: 12px;
      color: #909399;

      &.active {
        border-color: #FF7700;
        background: #FF7700;
        color: #FFFFFF;
      }

      .code {
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 30px;
        line-height: 30px;
      }

      .room-no {
        margin-bottom: 10px;
        font-size: 12px;
        line-height: 12px;
        opacity: 0.85;
      }

      .time {
        margin-top: 6px;
      }
    }
  }
}

.payPage {
  text-align: center;
  padding: 16px;

  /deep/ .ivu-input {
    width: 394px !important;
    text-align: center;
  }

  .header {
    margin: 35px 0 3px 0;
  }

  .process {
    width: 394px;
    height: 158px;
    border: 1px dashed #D8D8D8;
    border-top: 1px dashed #fff;
    margin: -1px auto 43px;

    &.on {
      border-top: 1px dashed #D8D8D8;
      margin-top: 20px;

      .list {
        padding-left: 14px !important;
      }
    }

    .list {
      padding: 6px 10px 0 3px;

      .item {
        font-size: 12px;
        color: #666;

        .name {
          color: #333;
          font-size: 13px;
          font-weight: bold;
        }
      }
    }

    .picture {
      width: 362px;
      height: 68px;
      margin: 24px auto 0 auto;

      img {
        width: 100%;
        height: 100%;
      }
    }
  }

  .picture {
    width: 18px;
    height: 18px;

    img {
      width: 100%;
      height: 100%;
    }

    margin-right: 7px;
  }

  .text {
    color: rgba(0, 0, 0, 0.45);
    font-size: 14px;
  }

  .money {
    font-size: 18px;
    color: rgba(0, 0, 0, 0.85);

    .num {
      font-size: 32px;
      margin-left: 5px;
    }
  }

  .tip {
    width: 310px;
    height: 26px;
    background: rgba(255, 126, 0, 0.1);
    border-radius: 13px;
    font-size: 13px;
    color: #FF7E00;
    margin: 10px auto 0 auto;

    .icon {
      font-size: 16px;
      margin-right: 5px;
    }
  }

  .bnt {
    width: 394px;
    height: 38px;
    margin: 28px 0 15px 0;
  }
}

.modalPay {
  /deep/ .ivu-modal-body {
    padding: 0;
  }
}

.cash {
  /deep/ .ivu-modal-body {
    padding: 0 !important;
  }
}

.cashPage {
  text-align: center;

  .right {
    width: 488px;
    background: #F5F5F5;
    padding: 16px 16px 16px 0;
    border-radius: 0 6px 6px 0;

    /deep/ .ivu-btn-primary {
      width: 100px;
    }

    .rightCon {
      width: 388px;
      height: 506px;
      margin: 35px auto 20px auto;
      background-color: #fff;
      border-radius: 14px;

      .top {
        height: 80px;
        color: rgba(0, 0, 0, 0.65);
        font-size: 13px;
        padding: 0 20px;

        .num {
          font-size: 42px;
          color: rgba(0, 0, 0, 0.85);
        }
      }

      .center {
        width: 100%;
        height: 46px;
        background-color: #1890FF;
        font-size: 13px;
        color: #fff;
        padding: 0 20px;

        .num {
          font-size: 27px;
        }
      }

      .bottom {
        padding: 10px 0 0 8px;

        .item {
          width: 108px;
          height: 62px;
          background: #FAFAFA;
          border-radius: 9px;
          border: 1px solid rgba(0, 0, 0, 0.15);
          color: #1890FF;
          font-size: 32px;
          margin-left: 12px;
          margin-top: 12px;
          cursor: pointer;

          &.on {
            background: #1890FF;
            color: #FFFFFF;
            font-size: 20px;
          }

          &.spot {
            padding-bottom: 15px;
          }
        }
      }
    }
  }

  .left {
    width: 282px;
    padding: 16px 0 16px 16px;

    .picture {
      width: 110px;
      height: 110px;
      margin: 180px auto 0 auto;

      img {
        width: 100%;
        height: 100%;
      }
    }

    .text {
      color: rgba(0, 0, 0, 0.45);
      font-size: 14px;
      margin-top: 14px;
    }

    .money {
      color: rgba(0, 0, 0, 0.85);
      font-size: 18px;

      .num {
        font-size: 32px;
        margin-left: 5px;
      }
    }
  }
}

.discountCon {
  .item {
    font-size: 15px;
    margin-bottom: 10px;
  }
}

/deep/.refund-modal {
  .ivu-modal-content {
    border-radius: 10px;
  }

  .ivu-modal-body {
    padding: 30px 25px 28px;
  }

  .ivu-form-item:last-child {
    margin-bottom: 0;
  }

  .ivu-form-item-label {
    font-size: 13px !important;
    color: #606266;
  }

  .ivu-input,
  .ivu-input-number,
  .ivu-input-number-input-wrap,
  .ivu-input-number-input {
    height: 36px;
  }

  .ivu-input-number-handler-wrap {
    display: none;
  }

  .ivu-input-number {
    width: 100%;
  }

  .ivu-modal-footer {
    padding: 17px 25px;
    border-top: 0;
  }

  .ivu-btn {
    height: 46px;
    border-radius: 23px;
    font-size: 16px !important;
  }
}
</style>
