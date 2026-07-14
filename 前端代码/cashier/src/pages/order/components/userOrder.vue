<template>
  <div class="order-user">
    <div class="sel-user">
      <div class="avatar">
        <img :src="selectData.avatar || require('@/assets/images/tourist.png')" alt="头像" />
      </div>
      <div class="item-right">
        <div class="user">
          <div>{{ selectData.uid ? selectData.nickname : '游客' }}</div>
        </div>
        <div class="money" v-if="selectData.uid">
          <div>
            <span class="pr20" v-if="selectData.phone">{{ selectData.phone }}</span
            >余额 <span class="num">{{ selectData.now_money || 0 }}</span>
          </div>
          <div>
            积分 <span class="num">{{ selectData.integral || 0 }}</span>
          </div>
        </div>
      </div>
    </div>
    <div class="cart-num">
      <div class="cart-num-left">
        <span>共</span>
        <span class="num">{{ total_num }}</span>
        <span>件商品</span>
      </div>
    </div>
    <div class="goods-list">
      <Table
        :columns="columns"
        :data="tableList"
        :loading="loading"
        no-data-text="暂无数据"
        highlight-row
        no-filtered-data-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="info">
          <div class="tabBox">
            <div class="tabBox_img" v-viewer>
              <img
                v-lazy="
                  row.productInfo.attrInfo
                    ? row.productInfo.attrInfo.image
                    : row.productInfo.image
                "
              />
            </div>
            <span class="tabBox_tit line2">
              <span class="is-gift" v-if="row.is_gift">赠品</span>
                {{ row.productInfo.store_name}}
              <span v-if="row.productInfo.attrInfo"> | 
                {{row.productInfo.attrInfo ? row.productInfo.attrInfo.suk : ""}}
              </span>
            </span>
          </div>
        </template>
        <template slot-scope="{ row }" slot="sum_true_price">
          <div class="tabBox" v-if="row.is_gift">-</div>
          <div class="tabBox" v-else>{{ selectData.type == 11 ? row.price : (row.sum_price * row.cart_num  || 0)}}</div>
        </template>
      </Table>
    </div>
  </div>
</template>

<script>
import goodsList from "@/pages/hang/components/goodsList";
import {
  cardBenefits,
} from "@/api/order";

export default {
  name: "userOrder",
  components: { goodsList },
  props: ["selectData"],
  data() {
    return {
      columns: [
        {
          title: "商品信息",
          slot: "info",
          minWidth: 200,
        },
        {
          title: "单价",
          render: (h, params) => {
            let price = '-';
            if (!params.row.is_gift) {
              price = this.selectData.type == 11 ? params.row.price : params.row.sum_price
            }
            return h('div', price);
          }
        },
        {
          title: "数量",
          render: (h, params) => {
            let cart_num = '-';
            if (!params.row.is_gift) {
              cart_num = this.selectData.type == 11 ? 1 : params.row.cart_num
            }
            return h('div', cart_num);
          }
        },
        {
          title: "总金额",
          slot: "sum_true_price",
          width: 150,
        },
      ],
      tableList: [],
      loading: false,
      give_integral_img:require("@/assets/images/give_integral.png"),
      give_coupon_img:require("@/assets/images/give_coupon.png"),
      total_num: 0,
    };
  },
  async created() {
    let _info;
    let cart_info;
    if (this.selectData.type == 11) {
      const { data } = await cardBenefits(this.selectData.id);
      _info = data || [];
      cart_info = _info.map((row) => {
        const ci = row.cart_info || row;
        let payPrice = row.payPrice;
        if (payPrice == null || payPrice === '') payPrice = row.pay_price;
        if (payPrice == null || payPrice === '') payPrice = ci.pay_price;
        if (payPrice == null || payPrice === '') payPrice = 0;
        return {
          ...ci,
          price: payPrice,
          payPrice,
        };
      });
    } else {
      _info = Object.values(this.selectData._info);
      cart_info = _info.map(({ cart_info }) => cart_info);
    }

    this.total_num = cart_info.length;
    
    const createCartInfoItem = (image, store_name) => {
      return {
        is_gift: 1,
        productInfo: {
          image,
          store_name,
        },
      };
    };
    if (this.selectData.give_integral) {
      cart_info.push(createCartInfoItem(this.give_integral_img, `赠送${this.selectData.give_integral}积分`));
    }
    this.selectData.give_coupon.forEach((item) => {
      cart_info.push(createCartInfoItem(this.give_coupon_img, item.coupon_title));
    });
    this.tableList = cart_info;
  },
  methods: {
    //立即退款
    getRefundData() {
      this.$emit("getRefundData");
    },
    // 订单备注
    remarks() {
      this.$emit("remarks");
    },
    point() {
      this.delfromData = {
        title: "立即打印订单",
        info: "您确认打印此订单吗?",
        url: `/order/print/${this.selectData.id}`,
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
    orderSend() {
      this.$emit("orderSend");
    },
  },
};
</script>

<style lang="stylus" scoped>
.order-user {
  height: 100%;
}

.sel-user {
  display: flex;
  align-items: center;
  background-color: rgba(255, 119, 0, 0.05);
  padding: 18px;
  border-radius: 10px;

  .avatar {
    width: 50px;
    height: 50px;
    margin-right: 10px;

    img {
      width: 100%;
      height: 100%;
      border-radius: 50%;
    }
  }

  .item-right {
    flex: 1;

    .user {
      font-size: 18px;
      font-weight: 600;
      color: rgba(0, 0, 0, 0.85);
    }

    .money {
      display: flex;
      align-items: flex-end;
      font-weight: 400;
      color: rgba(51, 51, 51, 0.85);
      font-size: 12px;

      .num {
        font-weight: 600;
        color: #F5222D;
        font-size: 17px;
        padding-right: 20px;
      }

      .pr20 {
        padding-right: 20px;
      }
    }
  }
}

.cart-num {
  display: flex;
  justify-content: space-between;
  font-weight: 500;
  align-items: flex-end;
  padding: 27px 10px 20px;

  .cart-num-left {
    color: #303133;
    font-size: 16px;

    .num {
      color: #FF7700;
    }
  }

  .money {
    color: #F5222D;
    font-size: 24px;
    font-weight: bold;
  }
}

.goods-list {
  /deep/ table {
    width: 100% !important;
  }

  /deep/ .ivu-table {
    border: 1px solid #f2f2f2;
    border-bottom: none;
  }

  // overflow-y: scroll;

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
      width: 60%;
      font-size: 14px !important;
      margin: 0 2px 0 10px;
      letter-spacing: 1px;
      box-sizing: border-box;

      .is-gift {
        font-size: 12px
        border: 1px solid #f5222d;
        background:  #f5222d
        color #fff
        padding: 0px 4px;
        border-radius: 3px;
      }
    }
  }
}
</style>