<template>
  <div style="width: 100%">
    <Modal
      v-model="modals"
      scrollable
      footer-hide
      closable
      title="优惠券列表"
      :mask-closable="false"
      width="1070"
      @on-cancel="cancel"
    >
      <div class="wrapper" v-if="dataList.length">
        <div
          class="item"
          v-for="(item, index) in dataList"
          :key="index"
          :class="[
            item.used.length && couponId == item.id ? 'used' : '',
            !item.can_use ? 'item-disabled' : ''
          ]"
        >
          <div class="item-left">
            <div class="itemCon acea-row row-between-wrapper">
              <div class="span">
                <div class="money" v-if="item.coupon_type === 1">
                  <span class="fu">¥</span>{{ item.coupon_price }}
                </div>
                <div class="money" v-if="item.coupon_type === 2">
                  {{ item.coupon_price / 10 }}<span class="fu">折</span>
                </div>
                <div class="info">满{{ item.use_min_price }}元可用</div>
              </div>
            </div>
            <div class="roll up-roll"></div>
            <div class="roll down-roll"></div>
            <div class="cou-msg">
              <div class="title line1">{{ item.coupon_title }}</div>
              <div class="type">
                {{
                  item.type === 0
                    ? "通用券"
                    : item.type === 1
                    ? "品类券"
                    : "商品券"
                }}
              </div>
              <div class="time" v-if="item.end_time">
                <span>{{ item.add_time | formatDate }}</span>
                ~
                <span>{{ item.end_time | formatDate }}</span>
              </div>
              <div class="time" v-else>
                <span>不限时</span>
              </div>
            </div>
          </div>
          <div
            class="use"
            :class="[
              item.used.length && couponId == item.id && item.showCoupon ? 'use-on' : '',
              !item.can_use ? 'use-disabled' : ''
            ]"
            @click="select(item)"
          >
            {{
              couponId == item.id && item.showCoupon
                ? "已选择"
                : !item.can_use
                ? (item.unuse_reason || "不可用")
                : item.used.length > item.usedCount
                ? "立即使用"
                : "领取并使用"
            }}
          </div>
        </div>
      </div>
      <div v-else class="no-cump">
        <img src="../../assets/images/no-cup.png" alt="" />
        <span class="trip">暂无优惠券，可以看看其他活动哟～</span>
      </div>
    </Modal>
  </div>
</template>

<script>
import { cashierCouponList } from "@/api/order";
import { receiveCoupon } from "@/api/coupon";
import { formatDate } from "@/utils/validate";

export default {
  name: "userList",
  filters: {
    formatDate(time) {
      if (time !== 0) {
        let date = new Date(time * 1000);
        return formatDate(date, "yyyy-MM-dd");
      }
    },
  },
  props: {
    uid: {
      type: Number,
      default: 0,
    },
    couponId: {
      type: Number,
      default: 0,
    },
    targetCartId: {
      type: Number,
      default: 0,
    },
    usedCouponIds: {
      type: Array,
      default() {
        return [];
      },
    },
    cartList: {
      type: Array,
      default() {
        return [];
      },
    },
    isPrice: {
      type: Number,
      default: 0,
    },
    changePrice: {
      type: Number,
      default: 0,
    },
    cartInfo: {
      type: Array,
      default() {
        return [];
      },
    },
  },
  data() {
    return {
      modals: false,
      loading: false,
      dataList: []
    };
  },
  methods: {
    isCouponUsedElsewhere(couponUserId) {
      if (!couponUserId) return false;
      if (this.couponId == couponUserId) return false;
      return this.usedCouponIds.includes(couponUserId);
    },
    select(item) {
      const couponUserId = item.id;
      if (this.isCouponUsedElsewhere(couponUserId)) {
        return;
      }
      // 不可用券：不允许选择（如果是当前已选则允许取消）
      if (!item.can_use && !(this.couponId == couponUserId && item.showCoupon)) {
        return;
      }
      item.showCoupon = !item.showCoupon;

      if (item.used.length == item.usedCount) {
        let data = {
          couponId: item.id,
        };
        receiveCoupon(this.uid, data)
          .then((res) => {
            const payload = Object.assign({}, res.data, {
              cart_id: this.targetCartId,
            });
            this.$emit("getCouponId", payload);
          })
          .catch((err) => {
            this.$Message.error(err.msg);
          });
      } else {
        const payload = Object.assign({}, item, {
          id: item.showCoupon ? item.id : 0,
          cart_id: this.targetCartId,
        });
        this.$emit("getCouponId", payload);
      }
    },
    //优惠券列表
    getList() {
      this.loading = true;
      let ids = [];
      if (this.targetCartId) {
        ids = [this.targetCartId];
      } else {
        this.cartList.forEach((item) => {
          item.cart.forEach((i) => {
            ids.push(i.id);
          });
        });
      }
      let data = { cart_id: ids };

      // 传递改价信息
      if (this.isPrice === 1 && this.cartInfo.length) {
        data.is_price = 1;
        data.change_price = this.changePrice;
        data.cart_info = this.cartInfo;
      }

      cashierCouponList(this.uid, data)
        .then((res) => {
          this.loading = false;
          const couponList = res.data;
          couponList.forEach((item) => {
            item.showCoupon = item.id == this.couponId;
            if (typeof item.can_use === 'undefined') item.can_use = true;
            if (typeof item.unuse_reason === 'undefined') item.unuse_reason = '';
            if (this.isCouponUsedElsewhere(item.id)) {
              item.can_use = false;
              item.unuse_reason = '已在其他商品使用';
            }
            // 已使用的数量
            item.usedCount = item.used.reduce((count, value) => {
              count += value.use_time ? 1 : 0;
              return count;
            }, 0);
          });
          this.dataList = couponList;
        })
        .catch((err) => {
          this.loading = false;
          this.$Message.error(err.msg);
        });
    },
    cancel() {
      this.currentid = "";
    },
  },
};
</script>

<style lang="less" scoped>
/deep/ .ivu-modal-body {
  height: 590px;
  padding: 20px 25px;
  border-radius: 0 0 10px 10px;
  background-color: #f5f5f5;
  overflow-x: hidden;
}
.wrapper::-webkit-scrollbar {
  width: 0 !important;
}

.wrapper {
  -ms-overflow-style: none;
}

.wrapper {
  display: grid;
  grid-template-columns: auto auto auto;
  column-gap: 20px;
  row-gap: 20px;
}
.wrapper::after {
  content: "";
  width: 33%;
}
.item {
  display: flex;
  height: 110px;
  border-radius: 8px;
  background-color: #ffebda;

  .item-left {
    flex: 1;
    position: relative;
    display: flex;
    border-radius: 8px;
    background-color: #fff;

    flex-shrink: 0;
  }

  .use {
    width: 13%;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    writing-mode: vertical-lr;
    color: #ff7700;
    font-size: 14px;
    font-weight: 400;
    cursor: pointer;
  }
  .use-on {
    color: #fff;
  }

  .roll {
    position: absolute;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #f5f5f5;

    &.up-roll {
      left: 30%;
      top: -10px;
    }

    &.down-roll {
      left: 30%;
      bottom: -10px;
    }
  }

  .itemCon {
    width: 106px;
    height: 100%;

    .span {
      width: 100%;
      height: 100%;
      border-right: 1px dashed #eeeeee;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;

      .money {
        color: #e93323;
        font-size: 34px;
        font-weight: bold;

        .fu {
          font-size: 18px;
        }
      }

      .info {
        color: #e93323;
        font-size: 13px;
      }
    }
  }

  .cou-msg {
    display: flex;
    justify-content: center;
    flex-direction: column;
    padding: 0 17px;
    width: 70%;

    .title {
      color: rgba(0, 0, 0, 0.85);
      font-size: 14px;
      font-weight: bold;
      width: 140px;
    }

    .type {
      border: 1px solid #ff6053;
      width: max-content;
      color: #ff6053;
      font-size: 12px;
      padding: 0 5px;
      border-radius: 20px;
      margin: 5px 0 10px 0;
    }

    .time {
      color: #999999;
      font-size: 13px;
    }
  }
}
.item-disabled{
  background-color: #f2f3f5 !important;
}
.use-disabled{
  color: #c5c8ce !important;
  cursor: not-allowed !important;
}

.no-cump {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  width: 100%;
  height: 350px;
  padding: 50px 0;

  img {
    width: 180px;
    height: 140px;
    margin-bottom: 20px;
  }

  .trip {
    color: #999999;
    font-size: 14px;
  }
}
.used {
  background-color: #ff7700 !important;
}
/deep/.ivu-modal-content {
  border-radius: 10px;
}
</style>
