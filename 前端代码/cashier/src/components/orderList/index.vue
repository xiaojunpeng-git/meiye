<template>
  <div
    class="order-list"
    :class="{ 'order-list--verify': orderType === 'verify' }"
    @scroll="addPage"
  >
    <div
      class="item"
      :class="{ sel: sel === index }"
      v-for="(item, index) in orderData"
      :key="index"
      @click="selectOrder(item, index)"
    >
      <div class="top">
        <div class="order-sty">
          <span
            class="type-sty"
            :style="'color: ' + item.color + ';border-color:' + item.color"
            v-if="item.pink_name && orderType !== 'verify'"
            >
               {{ item.pink_name === "预约"? '项目':item.pink_name }}
          </span>
          <span v-if="orderType == 'table'" class="order">{{ item.serial_number || item.order_id }}</span>
          <span v-else class="order">单号:{{ item.order_id }}</span>
        </div>
        <div class="time">
          <template v-if="orderType === 'verify'">销售日：{{ formatDateOnly(item.sale_day || item._pay_time || item.add_time) }}</template>
          <template v-else>{{ item.add_time }}</template>
        </div>
      </div>
      <div v-if="orderType === 'verify'" class="order-meta">
        <div class="order-meta-row">
          <span>{{ item.nickname }}/{{ item.user_phone }}</span>
          <span v-if="item.validity_text" class="validity-text">有效期：{{ item.validity_text }}</span>
        </div>
      </div>
      <div class="mid acea-row row-between">
        <div class="acea-row" v-if="item.type == 10 || item._infoData.length > 1">
          <div class="pic" v-show="i < 5" v-for="(val, i) in item._infoData" :key="i">
            <img :src="val.cart_info.productInfo.image"/>
          </div>
        </div>
        <div v-else class="acea-row row-middle">
           <div class="pic" v-for="(val, i) in item._infoData" :key="i">
            <img :src=" val.cart_info.productInfo.image"/>
          </div>
          <div v-if="item._infoData.length" class="store_name line2">
          {{item._infoData[0].cart_info.productInfo.store_name}}
          </div>
        </div>
        <div>
          <p><span class="num">¥{{ (item.orderId && item.orderId.pay_price) || item.pay_price }}</span></p>
          <p class="total_num" v-if="orderType != 'verify'">共{{ item.total_num }}件商品</p>
        </div>

      </div>
      <div v-if="orderType != 'verify'" class="footer">
        <div v-if="orderType == 'table'" class="text-333">
          <span v-if="item.status == 1">未支付</span>
          <span v-else-if="item.status == 2">{{ item.orderId && item.orderId.is_del ? '已删除' : (item.orderId && item.orderId.refund_status ? '已退款' : (item.orderId && item.orderId.paid ? '已结算' : '待付款')) }}</span>
          <span v-else-if="item.status == 3">已完成</span>
          <span v-else>加购中</span>
        </div>
        <div v-else class="text-333">
          {{ item.status_name.status_name }}
          <span class="trip" v-if="!item.is_all_refund && item.refund.length">
            (部分退款中)
          </span>
          <span
            class="trip"
            v-if="
              item.is_all_refund && item.refund.length && item.refund_type != 6
            "
          >
            (退款中)
          </span>
        </div>
        <div>{{ item.nickname}}/{{ item.user_phone}}</div>
        <div class="shouyin" v-if="item.clerk_name">
          收银员：{{item.clerk_name}}
        </div>
        <div v-if="item.orderId" class="shouyin">订单编号：{{item.orderId.order_id}}</div>
      </div>
      <div
        class="imgBox"
        v-if="(item.is_yx == 0 || Number(item.card_upgrade_use_oid || 0) > 0) && showSx == 1"
      >
        <img
          :src="
            Number(item.card_upgrade_use_oid || 0) > 0
              ? 'https://qiniu007.cc3798.com/uploads/shengji.png'
              : 'https://qiniu007.cc3798.com/uploads/shixiao.png'
          "
        />
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: "orderList",
  props: {
    showSx: {
      type: String,
    },
    orderData: {
      type: Array,
    },
    total: {
      type: Number,
    },
    orderType: {
      type: String,
      default: "order",
    },
  },
  data() {
    return {
      sel: 0,
    };
  },
  methods: {
    formatDateOnly(val) {
      if (!val) return '';
      const str = String(val);
      if (str.includes('-')) return str.slice(0, 10);
      const d = new Date(str);
      if (Number.isNaN(d.getTime())) return str;
      const y = d.getFullYear();
      const m = `${d.getMonth() + 1}`.padStart(2, '0');
      const day = `${d.getDate()}`.padStart(2, '0');
      return `${y}-${m}-${day}`;
    },
    selectOrder(item, index) {
      this.sel = index;
      this.$emit("selectOrder", item);
    },
    addPage(e) {
      clearTimeout(this.scrollTimeout);
      this.scrollTimeout = setTimeout(() => {
        if (
          !e ||
          (e.target.scrollHeight - e.target.scrollTop - e.target.clientHeight <=
            10 &&
            this.orderData.length < this.total)
        ) {
          console.log(
            e
              ? e.target.scrollHeight - e.target.scrollTop - e.target.clientHeight
              : ""
          );
          this.$emit("addPage");
        } else {
          return;
        }
      }, 100);
    },
  },
};
</script>

<style scoped lang="stylus">
.imgBox{
  position: absolute;
  z-index: 10;
  width: 100px;
  top:10px
}
.imgBox img{
  width: 100%;
}
.order-list::-webkit-scrollbar {
  width: 0 !important;
}

.order-list {
  -ms-overflow-style: none;
}

.order-list {
  overflow: -moz-scrollbars-none;
}

.order-list {
  overflow: hidden;
  overflow-y: scroll;

  &.order-list--verify {
    padding: 0 16px 16px;

    .item {
      margin-bottom: 12px;
      padding: 16px 18px;
      border: 1px solid #EBEEF5;
      border-bottom: 1px solid #EBEEF5;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);

      &:last-child {
        margin-bottom: 0;
      }
    }

    .item.sel {
      border-color: #1890ff;
      box-shadow: 0 2px 12px rgba(24, 144, 255, 0.2);
      background-color: #f3f9ff;
    }

    .order-meta {
      margin-top: 10px;
      border-top: 1px solid #f0f0f0;
      padding-top: 10px;
      font-size: 13px;
      line-height: 1.6;

      .order-meta-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        flex-wrap: wrap;
      }

      .validity-text {
        color: #666666;
        text-align: right;
        flex-shrink: 0;
      }

      .text-333 {
        color: #333333;
        margin-bottom: 4px;
      }

      .shouyin {
        color: #666666;
        margin-top: 2px;
      }

      .trip {
        color: #f5222d;
      }
    }

    .footer--verify {
      justify-content: flex-start;
      margin-top: 12px;
      padding-top: 12px;
      border-top: 1px solid #f0f0f0;
      font-size: 14px;
      color: #333333;
    }

    /* 已失效：水平居中，落在中间商品区，不挡单号与右侧价格 */
    .imgBox {
      top: 72px;
      left: 50%;
      right: auto;
      transform: translateX(-50%);
      pointer-events: none;
    }
  }

  .item {
    position: relative;
    padding: 18px 20px;
    border-bottom: 1px solid #EEEEEE;
    cursor: pointer;

    .top {
      display: flex;
      justify-content: space-between;
      align-items: center;

      .order-sty {
        font-size: 14px;
        white-space: nowrap;

        .type-sty {
          font-size: 12px;
          color: #E93323;
          padding: 2px;
          border-radius: 2px;
          border: 1px solid #E93323;
          margin-right: 6px;
        }

        .order {
          color: rgba(0, 0, 0, 0.85);
          font-size: 14px;
          font-weight: 600;
        }
      }

      .time {
        font-size: 14px;
        white-space: nowrap;
        color: #999999;
      }
    }

    .mid {
      margin: 14px 0;
      max-width: 100%;
      overflow: hidden;

      .pic {
        width: 56px;
        height: 56px;
        margin-right: 12px;

        img {
          width: 100%;
          height: 100%;
          border-radius: 4px;
        }
      }
    }
    .store_name{
      width: 224px;
      height: 36px;
      font-size: 14px;
      font-family: PingFangSC-Regular, PingFang SC;
      font-weight: 400;
      color: #666666;
      line-height: 18px;
    }

    .footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 14px;
      .trip{
        color: #F5222D;
      }
      .num {
        color: #F5222D;
        font-size: 16px;
        font-weight: bold;
      }
      .text-333{
        color:#333333;
      }
      .shouyin{
        color:#666;
      }
    }
  }

  .sel {
    background-color: #F3F9FF;
  }
}
.num {
  color: #F5222D;
  font-size: 16px;
  font-weight: bold;
  text-align right
}
.total_num{
  color:#999;
  font-size:12px;
  margin-top:4px;
  text-align: right ;
}
</style>
