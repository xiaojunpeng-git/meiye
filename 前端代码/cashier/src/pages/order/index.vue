<template>
  <div class="order">
    <div class="left">
      <div class="left-top">
        <div class="title">订单列表</div>
        <div class="sx" @click="filterModal = !filterModal">
          {{ filterModal ? "关闭" : "筛选" }}
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
            placeholder="搜索订单编号/姓名/手机号"
          />
        </div>
        <orderList
          v-if="orderListData.length"
          class="order-list"
          v-show="!filterModal"
          :total="count"
          @addPage="addPage"
          @selectOrder="selectOrder"
          :orderData="orderListData"
        ></orderList>
        <div v-else-if="!orderListData.length && !filterModal" class="no-order">
          <img src="../../assets/images/no-order.png" alt="" />
          <span class="trip">噢～目前暂无订单</span>
        </div>
        <filter-modal v-show="filterModal" @search="searchList"></filter-modal>
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
        ></userOrder>

        <orderDetails
          class="orders"
          v-if="sle === 1 && selectOrderData.id"
          :orderDatalist="orderInfoData"
        >
        </orderDetails>
        <orderRecord
          v-if="sle === 2 && selectOrderData.id"
          :id="selectOrderData.id"
        ></orderRecord>
        <div v-else-if="!selectOrderData.id" class="no-order">
          <img src="../../assets/images/no-record.png" alt="" />
          <span class="trip">噢～目前暂无挂单记录</span>
        </div>
      </div>
      <div class="footer" v-if="selectOrderData.order_id">
        <div class="footer-left">
          <span class="clerk" v-if="selectOrderData.clerk_name"
            >收银员：{{ selectOrderData.clerk_name }}</span
          >
          <span class="pay">实付：</span>
          <span class="num">¥{{ selectOrderData.pay_price || 0 }}</span>
        </div>
        <div class="footer-right">
          <div class="btn" @click="remarks" v-if="!open_erp">订单备注</div>
          <div class="btn" @click="point" v-if="!open_erp">小票打印</div>
          <div
            class="btn pay"
            v-if="
              (selectOrderData._status === 2 ||
                selectOrderData._status === 8 ||
                selectOrderData.status === 4) &&
                selectOrderData.shipping_type === 3 &&
                (selectOrderData.pinkStatus === null ||
                  selectOrderData.pinkStatus === 2)
            "
            @click="orderSend"
          >
            发送货
          </div>
          <div
            class="btn pay"
            @click="getRefundData"
            v-if="
              [0, 1, 2, 5].includes(selectOrderData.refund_type) &&
                (parseFloat(selectOrderData.pay_price) >
                  parseFloat(selectOrderData.refund_price) ||
                  selectOrderData.pay_price == 0) &&
                !selectOrderData.refund.length &&
                selectOrderData.paid == 1
            "
          >
            {{ selectOrderData.refund_type == 2 ? "同意退货" : "立即退款" }}
          </div>
          <div
            class="btn pay"
            v-if="!selectOrderData.is_del && !selectOrderData.paid && selectOrderData.pay_type == 'offline'"
            @click="openConfirm"
          >
            确认支付
          </div>
        </div>
      </div>
    </div>
    <!-- 备注 -->
    <order-remark
      ref="remarks"
      :orderId="selectOrderData.id"
      @submitFail="submitFail"
    ></order-remark>
    <orderSend
      ref="send"
      :orderId="orderId"
      :status="status"
      :pay_type="pay_type"
      @submitFail="send"
    ></orderSend>
    <Modal
        v-model="confirmVisible"
        title="确认支付"
        width="450"
        class-name="confirm-modal"
        @on-ok="payOffline">
        <Icon type="ios-alert" />确认该笔订单要使用线下支付吗？
    </Modal>
    <Modal v-model="refundModal" title="手动退款" width="960" class-name="refund-modal" @on-visible-change="visibleChange">
      <Form :label-width="100">
        <FormItem label="退款金额：" required>
          <InputNumber v-model="refundMoney" style="width: 408px;"></InputNumber>
        </FormItem>
        <FormItem v-if="refundProductNum > 1" label="分单退款：">
          <i-switch v-model="isSplitOrder" :true-value="1" :false-value="0" size="large">
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
          <div class="tips">可选择表格中的商品单独退款，退款后且不能撤回，请谨慎操作！</div>
          <Table v-show="isSplitOrder" ref="refundTable" max-height="500" :columns="refundColumns" :data="refundProduct" @on-selection-change="selectionChange">
            <template slot-scope="{ row }" slot="product">
              <div class="image-wrap" v-viewer="{navbar:false,toolbar:false}"><img :src="row.productInfo.attrInfo.image" class="image"></div>
              <div class="title">{{ row.productInfo.store_name }}</div>
            </template>
            <template slot-scope="{ row, index }" slot="action">
              <InputNumber v-model="refundProduct[index].refundNum" :max="row.cart_num - row.refund_num" :min="1" :precision="0" controls-outside @on-change="numChange"></InputNumber>
            </template>
          </Table>
        </FormItem>
		<FormItem label="售后入库：" v-if="selectOrderData.status>=1">
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
        <Button type="primary" @click="submitRefund">提交</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import orderList from "@/components/orderList";
import goodsList from "@/pages/hang/components/goodsList";
import userOrder from "@/pages/order/components/userOrder";
import orderDetails from "@/pages/order/components/orderDetails";
import orderRecord from "@/components/orderRecord";
import orderRemark from "@/components/orderRemark";
import filterModal from "@/components/filterModal";
import orderSend from "@/pages/order/components/orderSend";
import {
  getOrderList,
  getOrderInfo,
  getRefundOrderFrom,
  erpConfig,
  payOffline,
  openRefund,
} from "@/api/order";

export default {
  components: {
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
      tabs: ["商品信息", "订单详情", "订单记录"],
      sle: 0,
      filterModal: false,
      userFrom: {
        keyword: "",
        page: 1,
        limit: 9,
      },
      orderData: {
        type: "",
        status: "",
        time: "",
        staff_id: "",
        real_name: "",
        page: 1,
        limit: 7,
      },
      selectOrderData: {},
      orderInfoData: {},
      count: 0,
      status: 0,
      pay_type: "",
      canSend: true,
      tengxun_map_key: "",
      open_erp: null,
      confirmVisible: false,
      refundModal: false,
      refundMoney: 0,
      isSplitOrder: 0,
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
        }
      ],
      refundProduct: [],
      refundSelection: [],
	  stockInType:0
    };
  },
  computed: {
    refundProductNum() {
      return this.refundProduct.reduce((total, { refundNum }) => this.$computes.Add(total, refundNum), 0);
    },
    // 退款商品选择
    productSelection() {
      // 使用Map优化性能
      const productMap = new Map(this.refundProduct.map(item => [item.id, item]));
      return this.refundSelection.filter(item => productMap.has(item.id)).map(item => productMap.get(item.id));
    },
  },
  watch: {
    refundSelection: {
      handler(value) {
        this.refundMoney = value.reduce((total, { refundPrice, refundNum }) => {
          return this.$computes.Add(total, this.$computes.Mul(refundPrice, refundNum));
        }, 0);
      },
      deep: true
    },
    isSplitOrder(value) {
      this.$nextTick(() => {
        this.$refs.refundTable.selectAll(!!value);
      });
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
          this.refundProduct.refund_price = Number(string);
        } else {
          this.refundProduct.refund_price = Number(Number(string).toFixed(2));
        }
      });
    },
    openConfirm() {
      this.confirmVisible = true;
    },
    // 线下确定结算
    payOffline() {
      payOffline(this.selectOrderData.id).then(() => {
        this.$Message.success('支付成功');
        this.reloading = true;
        this.limitTemp = this.orderData.limit;
        this.pageTemp = this.orderData.page;
        this.orderData.limit *= this.orderData.page;
        this.orderData.page = 1;
        this.getOrderList();
      }).catch(err => {
        this.$Message.error(err.msg);
      });
    },
    orderSend() {
      this.$store.commit(
        "store/order/setSplitOrder",
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
      this.filterModal = false;
      this.orderData.page = 1;
      this.orderData = { ...this.orderData, ...data };
      this.orderListData = [];
      this.sle = 0;
      this.search();
    },
    point() {
      this.delfromData = {
        title: "立即打印订单",
        info: "您确认打印此订单吗?",
        url: `/order/print/${this.selectOrderData.id}`,
        method: "get",
        ids: "",
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
      this.selectOrderData = {};
      this.getOrderList();
    },
    // 设置备注
    remarks() {
      this.$refs.remarks.modals = true;
      this.$refs.remarks.formValidate.remark = this.selectOrderData.remark;
    },
    send() {
      this.canSend = false;
      this.search();
    },
    // 备注修改成功
    submitFail(remark) {
      this.selectOrderData.remark=remark;
    },
    // 获取退款表单数据
    getRefundData() {
      if (this.selectOrderData.refund_type === 2) {
        this.delfromData = {
          title: "是否立即退货",
          url: `/refund/agree/${this.selectOrderData.id}`,
          method: "get",
        };
        this.$modalSure(this.delfromData)
          .then((res) => {
            this.$Message.success(res.msg);
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
      } else {
        const cartInfo = Object.values(this.selectOrderData._info).map(({ cart_info }) => {
          const refundNum = cart_info.cart_num - cart_info.refund_num;
          return {
            ...cart_info,
            refundPrice: this.$computes.Div(cart_info.refund_price, cart_info.cart_num),
            refundNum,
            _disabled: !refundNum,
          };
        });
        this.refundProduct = cartInfo;
        if (this.refundProductNum === 1) {
          this.refundSelection = cartInfo;
        }
        this.refundModal = true;
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
    selectOrder(orderData) {
      this.sle = 0;
      this.selectOrderData = {};
      this.$nextTick(() => {
        this.selectOrderData = orderData;
      });
    },
    tabClick(index) {
      switch (index) {
        case 1:
          this.getOrderInfo(this.selectOrderData.id);
      }
      this.sle = index;
    },
    getOrderList() {
      getOrderList(this.orderData)
        .then((res) => {
          res.data.list = res.data.list.map((item) => {
            let infoArr = [];
            for (let key in item._info) {
              let obj = item._info[key];
              infoArr.push(obj);
            }
            this.$set(item, "_infoData", infoArr);
            return item;
          });
          this.count = res.data.count;
          this.orderListData = this.orderListData.concat(res.data.list);
          // if (this.orderData.page == 1) {
          //   this.selectOrderData = this.orderListData[0] || {};
          // }
          Object.keys(this.selectOrderData).length
          if (this.orderListData.length && !Object.keys(this.selectOrderData).length) {
            this.selectOrderData = this.orderListData[0] || {};
          }
          if (this.orderListData.length && Object.keys(this.selectOrderData).length) {
            for (let i = 0; i < this.orderListData.length; i++) {
              if (this.orderListData[i].id == this.selectOrderData.id) {
                this.selectOrderData = this.orderListData[i];
                break;
              }
            }
          }
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
    visibleChange(visible) {
      this.isSplitOrder = 0;
	  this.stockInType = 0;
      if (!visible) {
        this.refundSelection = [];
      }
    },
    cancelRefundModal() {
      this.refundModal = false;
    },
    submitRefund() {
      let data = {
        refund_price: this.refundMoney,
        is_split_order: this.isSplitOrder,
		stock_in_type: this.stockInType
      };
      if (this.isSplitOrder) {
        if (!this.refundSelection.length) {
          return this.$Message.warning('请选择需要退款的商品');
        }
        // 组装退款商品
        data.cart_ids = this.productSelection.map(({ id, refundNum }) => {
          return { cart_id: id, cart_num: refundNum };
        });
      }
      openRefund(this.selectOrderData.id, data).then(res => {
        this.$Message.success(res.msg);
        this.refundModal = false;
        this.reloading = true;
        this.limitTemp = this.orderData.limit;
        this.pageTemp = this.orderData.page;
        this.orderData.limit *= this.orderData.page;
        this.orderData.page = 1;
        this.orderListData = [];
        this.getOrderList();
      }).catch(res => {
        this.$Message.error(res.msg);
      });
    },
    // 选择商品
    selectionChange(selection) {
      this.refundSelection = selection;
      this.refundMoneyCompute();
    },
    // 改变商品数量
    numChange() {
      this.refundMoneyCompute();
    },
    // 计算退款金额
    refundMoneyCompute() {
      let refundMoney = 0;
      for (const item of this.productSelection) {
        try {
          // 使用trycatch处理计算退款金额可能发生的错误
          refundMoney = this.$computes.Add(refundMoney, this.$computes.Mul(item.refundNum, item.refundPrice));
        } catch (error) {
          console.error('计算退款金额发生错误', error);
          break;
        }
      }
      this.refundMoney = refundMoney;
    },
  },
};
</script>
<style scoped lang="stylus">
::-webkit-scrollbar {
  display: none;
}

.order {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  padding: 20px;
  background-color: #F5F5F5;

  .left {
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    width: 460px;
    border-radius: 20px;
    background: #FFFFFF;

    .left-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 24px;

      .title {
        color: rgba(0, 0, 0, 0.85);
        font-weight: 600;
        font-size: 20px;
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

      /deep/.content {
        flex: 1;
        min-height: 0;

        .search-btn {
          border-radius: 0 0 20px 20px;
        }
      }
    }
  }

  .order-data {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    border-radius: 20px;
    margin-left: 20px;

    .content {
      flex: 1;
      padding: 18px 24px;
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
        color: rgba(0,0,0,0.85);
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

  .footer-left {
    font-size: 15px;

    .clerk {
      color: #000;
      padding-right: 40px;
      font-weight: bold;
    }

    .pay {
      color: #333333;
    }

    .num {
      font-size: 20px;
      color: #F5222D;
      font-weight: bold;
    }
  }

  .footer-right {
    color: rgba(0, 0, 0, 0.85);
    font-size: 17px;
    display: flex;

    .btn {
      display flex;
      justify-content: center;
      align-items: center;
      width: 120px;
      height: 44px;
      border-radius: 22px;
      margin-left: 15px;
      background-color: #F2F3F5;
      cursor: pointer;
      font-size: 18px;
    }

    .pay {
      color: #FFFFFF;
      background: #FF7700;
    }
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
/deep/.confirm-modal {
  .ivu-modal-content {
    border-radius: 10px;
  }

  .ivu-modal-body {
    padding: 20px 25px 30px;
    font-size: 15px;
    color: #606266;

    .ivu-icon {
      margin-right: 10px;
      font-size: 20px;
      color: #FFB200;
    }
  }

  .ivu-modal-footer {
    padding: 0 25px 20px;
    border-top: 0;
  }

  .ivu-btn {
    width: 80px;
    height: 36px;
    border-radius: 18px;
    background: #F5F5F5;
    font-size: 14px !important;
    color: #606266;

    &:focus {
      box-shadow: none;
    }
  }

  .ivu-btn-primary {
    border-color: #1890FF;
    background: #1890FF;
    color: #FFFFFF;
  }
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
