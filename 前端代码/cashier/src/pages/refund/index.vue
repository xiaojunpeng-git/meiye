<template>
  <div class="order">
    <div class="left">
      <div class="left-top">
        <div class="title">售后订单</div>
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
        <div class="search" v-show="!filterModal">
          <Input
            search
            enter-button="搜索"
            v-model="orderData.keyword"
            size="large"
            placeholder="搜索订单编号"
            @on-search="search"
          />
        </div>
        <orderList
          v-if="refundList.length"
          v-show="!filterModal"
          :total="count"
          @addPage="addPage"
          :orderData="refundList"
          @selectOrder="selectOrder"
        ></orderList>
        <div v-else-if="!refundList.length && !filterModal" class="no-order">
          <img src="../../assets/images/no-order.png" alt="" />
          <span class="trip">噢～目前暂无售后订单</span>
        </div>
        <filter-modal v-show="filterModal" @search="searchList"></filter-modal>
      </div>
    </div>
    <div class="order-data">
      <div v-if="selectOrderData.id" class="header">
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
      <div class="content" :class="{ radius1: sle, radius2: !selectOrderData.id }">
        <userOrder
          v-if="sle === 0 && selectOrderData.id"
          class="orders"
          :canSend="canSend"
          :selectData="selectOrderData"
          @submitFail="send"
          @init="searchList"
        ></userOrder>
        <div v-else-if="!selectOrderData.id" class="no-order">
          <img src="../../assets/images/no-record.png" alt="" />
          <span class="trip">噢～目前暂无数据</span>
        </div>
        <orderDetails
          v-if="sle === 1 && selectOrderData.id"
          class="orders"
          :orderDatalist="orderInfoData"
        >
        </orderDetails>
        <orderRecord
          v-if="sle === 2 && selectOrderData.id"
          :id="selectOrderData.store_order_id"
        ></orderRecord>
      </div>

      <div class="footer" v-if="selectOrderData.order_id">
        <div class="footer-left">
          <span class="clerk" v-if="selectOrderData.clerk_name"
            >收银员：{{ selectOrderData.clerk_name }}</span
          >
          <span class="pay">实付：</span>
          <span class="num">¥{{ selectOrderData.pay_price || 0 }}</span>
          <span class="pay">退款金额：</span>
          <span class="refund-price"
            >¥{{
              selectOrderData.refunded_price ||
                selectOrderData.refund_price ||
                0
            }}</span
          >
        </div>
        <div class="footer-right">
          <div class="btn pay" @click="remarks">售后备注</div>
          <div
            class="btn pay refund-btn"
            @click="getRefundData"
            v-if="
              (selectOrderData.refund_type == 0 && selectOrderData.apply_type == 1 || [1, 5].includes(selectOrderData.refund_type)) &&
                canSend &&
                !open_erp
            "
          >
            立即退款
          </div>
          <div
            class="btn  no-btn"
            @click="rejectRefund"
            v-if="
              [0, 1, 2, 5].includes(selectOrderData.refund_type) &&
                canSend &&
                !open_erp
            "
          >
            拒绝退款
          </div>
          <div
            class="btn  refund-btn"
            @click="getRefund"
            v-if="selectOrderData.refund_type == 0 && selectOrderData.apply_type == 2"
          >
            同意退货
          </div>
        </div>
      </div>
    </div>
    <!-- 备注 -->
    <order-remark
      ref="remarks"
      remarkType="refund"
      :orderId="selectOrderData.id"
      @submitFail="submitFail"
    >
    </order-remark>
    <Modal
      v-model="orderRefundModal"
      scrollable
      title="退款处理"
      class="order_box"
      width="600"
      class-name="vertical-center-modal"
      footer-hide
    >
      <order-refund
        v-if="orderRefundModal"
        :selectData="selectOrderData"
        @refund="refund"
        @clear="orderRefundModal = false"
      ></order-refund>
    </Modal>
  </div>
</template>

<script>
import orderList from "@/components/orderList";
import goodsList from "@/pages/hang/components/goodsList";
import userOrder from "./components/userOrder";
import orderDetails from "./components/orderDetails";
import orderRecord from "@/components/orderRecord";
import orderRemark from "@/components/orderRemark";
import filterModal from "@/components/filterModal";
import orderRefund from "@/components/orderRefund";
import {
  getOrderRefundInfo,
  getRefundList,
  getRefundOrderFrom,
  refund,
  erpConfig,
  cardBenefits,
} from "@/api/order";

export default {
  components: {
    orderList,
    goodsList,
    userOrder,
    orderDetails,
    orderRecord,
    orderRemark,
    filterModal,
    orderRefund,
  },
  data() {
    return {
      refundList: [],
      tabs: ["商品信息", "订单详情", "订单记录"],
      sle: 0,
      orderId: 0,
      filterModal: false,
      orderRefundModal: false,
      orderInfoData: {},
      selectOrderData: {},
      orderData: {
        type: "",
        status: "",
        time: "",
        staff_id: "",
        real_name: "",
        page: 1,
        limit: 10,
      },
      count: 0,
      canSend: true,
      tengxun_map_key: "",
      open_erp: null,
    };
  },
  created() {
    this.getRefundList();
    this.getErpConfig();
  },
  methods: {
    addPage() {
      if (this.refundList.length < this.count) this.orderData.page++;
      this.getRefundList();
    },
    // 获取退款表单数据
    getRefund() {
      this.delfromData = {
        title: "退货退款",
        url: `/order/refund/agree/${this.selectOrderData.id}`,
        method: "get",
      };
      this.$modalSure(this.delfromData)
        .then((res) => {
          this.getRefundList();
          this.$Message.success(res.msg);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    //拒绝退款
    rejectRefund() {
      this.delfromData = {
        title: "拒绝退款",
        url: `/order/order_refund/${this.selectOrderData.id}?type=2`,
        method: "put",
      };
      this.$modalSure(this.delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.searchList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    //搜索
    searchList(data = {}) {
      this.filterModal = false;
      this.orderData.page = 1;
      this.selectOrderData = {};
      this.orderData = { ...this.orderData, ...data };
      this.refundList = [];
      this.getRefundList();
    },
    tabClick(index) {
      switch (index) {
        case 1:
          this.getOrderRefundInfo(this.selectOrderData.id);
        default:
          this.sle = index;
      }
    },
    send() {
      this.canSend = false;
    },
    getOrderRefundInfo(id) {
      getOrderRefundInfo(id).then((res) => {
        this.orderInfoData = res.data;
        this.sle = 1;
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    // 备注修改成功
    submitFail() {
    },
    // 选中的订单
    selectOrder(data) {
      if (data.type == 11) {
        this.getCardBenefits(data);
        return;
      }
      this.selectOrderData = data;
      this.$nextTick((e) => {
        this.sle = 0;
      });
    },
    selectFilter(item, index) {
      this.searchData[item.type] = index;
    },
    search() {
      this.refundList = [];
      this.orderData.page = 1;
      this.selectOrderData = {};
      this.getRefundList();
    },
    // 获取退款表单数据
    getRefundData() {
      this.orderRefundModal = true;
    },
    getRefundList() {
      getRefundList(this.orderData)
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
          if (res.data.list.length && this.orderData.page == 1) {
            if (res.data.list[0].type == 11) {
              this.getCardBenefits(res.data.list[0]);
            }
          }
          this.refundList = this.refundList.concat(res.data.list);
          this.count = res.data.count;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
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
    remarks() {
      this.$refs.remarks.modals = true;
      this.$refs.remarks.formValidate.remark = this.selectOrderData.remark;
    },
    refund(data) {
      refund(this.selectOrderData.id, data)
        .then((res) => {
          this.orderRefundModal = false;
          this.$Message.success("退款成功");
          this.search();
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
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    getCardBenefits(data) {
      cardBenefits(data.store_order_id).then((res) => {
        data.tableList = res.data;
        this.selectOrderData = data;
        this.$nextTick((e) => {
          this.sle = 0;
        });
      })
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

.order {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  padding: 20px;
  background: #F5F5F5;

  .left {
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

        /deep/.item {
          border-bottom-style: dashed;
        }
      }

      .content {
        flex: 1;
      }
    }
  }

  .order-data {
    flex: 1;
    display: flex;
    flex-direction: column;
    margin-left: 20px;

    .content {
      flex: 1;
      padding: 24px;
      border-radius: 0 20px 0 0;
      background: #FFFFFF;
      overflow-x: hidden;
    }

    .radius1 {
      border-radius: 20px 20px 0 0;
    }

    .radius2  {
      border-radius: 20px;
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
    display: flex;

    .btn {
      display flex;
      justify-content: center;
      align-items: center;
      width: 120px;
      height: 44px;
      border-radius: 22px;
      background: #F2F3F5;
      cursor: pointer;
      font-size: 18px;
      color: rgba(0,0,0,0.85);

      +.btn {
        margin-left: 15px;
      }
    }

    .pay {
      color: #FFFFFF;
      background: #FF7700;
    }
  }
}
</style>
