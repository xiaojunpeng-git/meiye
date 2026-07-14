<template>
  <div class="order-user">
    <div class="sel-user-group">
      <template v-for="item in selectData.userList">
        <div
          v-if="!selectData.oid || (selectData.orderId && selectData.orderId.uid == item.uid)"
          :key="item.id"
          class="sel-user"
          @click="userChange(item)"
        >
          <i
            v-if="!selectData.oid && selectData.userList.length > 1"
            :class="[
              'iconfont',
              item.uid == selectData.uid ? 'iconxuanzhong6' : 'icondayuanjiao',
            ]"
          ></i>
          <div class="avatar">
            <img :src="item.userInfo.avatar || defaultAvatar" alt="头像" />
          </div>
          <div class="item-right">
            <div class="user">
              <div>{{ item.userInfo.nickname || '-' }}</div>
            </div>
            <div class="money">
              <div>
                <span class="pr20" v-if="item.userInfo.phone">{{
                  item.userInfo.phone
                }}</span
                >余额
                <span class="num"
                  >{{ item.userInfo.now_money || 0
                  }}<a class="btn" @click="rechargeBnt(item.userInfo)"
                    >充值</a
                  ></span
                >
              </div>
              <div>
                积分 <span class="num">{{ item.userInfo.integral || 0 }}</span>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>
    <div class="cart-num">
      <div class="cart-num-left">
        <span>共</span>
        <span class="num">{{ total_num }}</span>
        <span>件商品</span>
      </div>
      <div class="reduce">
        优惠：{{
          $computes.Sub(selectData.sumPrice || (selectData.orderId && selectData.orderId.total_price) || 0, selectData.payPrice || (selectData.orderId && selectData.orderId.pay_price) || 0) ||
          0
        }}<a class="btn" @click="discountCon">明细</a>
      </div>
      <div class="actual">
        实付：<span class="money">¥{{ selectData.payPrice || (selectData.orderId && selectData.orderId.pay_price) || 0 }}</span>
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
              {{ row.productInfo.store_name }}
              <span v-if="row.productInfo.attrInfo">
                |
                {{
                  row.productInfo.attrInfo ? row.productInfo.attrInfo.suk : ''
                }}
              </span>
            </span>
          </div>
        </template>
        <template slot-scope="{ row }" slot="sum_true_price">
          <div class="tabBox">{{ row.sum_price * row.cart_num || 0 }}</div>
        </template>
        <template slot-scope="{ row }" slot="action">
          <Button
            :disabled="row.cart_num == 1"
            class="sub"
            shape="circle"
            @click="calculate(row, 0)"
          >
            <Icon custom="iconfont iconjian" size="12" />
          </Button>
          <InputNumber v-model="row.cart_num" :min="1" :editable="false"></InputNumber>
          <Button
            :disabled="row.cart_num == row.trueStock"
            class="add"
            shape="circle"
            @click="calculate(row, 1)"
          >
            <Icon custom="iconfont iconjia" size="12" />
          </Button>
		  <a class="delete ml30" @click="delte(row, '删除商品', index)">删除</a>
        </template>
      </Table>
    </div>
  </div>
</template>

<script>
let columns = [
  {
    title: '商品信息',
    slot: 'info',
    minWidth: 200,
  },
  {
    title: '单价',
    key: 'sum_price',
    width: 150,
  },
  {
    title: '数量',
    key: 'cart_num',
    width: 150,
  },
  {
    title: '总金额',
    slot: 'sum_true_price',
    width: 150,
  },
  {
    title: '操作',
    slot: 'action',
    width: 180,
  },
];
export default {
  name: 'userOrder',
  props: ['selectData'],
  data() {
    return {
      columns: [],
      tableList: [],
      loading: false,
      defaultAvatar: require('@/assets/images/tourist.png'),
      give_integral_img: require('@/assets/images/give_integral.png'),
      give_coupon_img: require('@/assets/images/give_coupon.png'),
    };
  },
  computed: {
    total_num() {
      return this.tableList.reduce((total, { cart_num }) => total + cart_num, 0);
    }
  },
  watch: {
    'selectData.orderId': {
      handler(val) {
        let slot = 'action';
        if (val) {
          if (val.id) {
            slot = 'sum_true_price';
          }
          if (val._info) {
            this.tableList = [];
            if (Array.isArray(val._info)) {
              val._info.forEach(value => {
                this.tableList.push(value.cart[0]);
              });
            } else {
              Object.values(val._info).forEach(value => {
                this.tableList.push(value.cart_info);
              });
            } 
          }
        } else {
          this.tableList = [];
        }
        this.columns = columns.filter(item => !item.slot || item.slot == 'info' || item.slot == slot);
      },
      immediate: true
    }
  },
  methods: {
	delte(row, tit, num) {
	  let delfromData = {
	    title: tit,
	    num: num,
	    url: `del/table/cart`,
	    method: "DELETE",
	    ids: {
			productId:row.product_id,
			uniqueId:row.product_attr_unique,
			tableId:row.collate_code_id
		},
	  };
	  this.$modalSure(delfromData)
	    .then((res) => {
	      this.$Message.success(res.msg);
		  this.$emit('delGoods');
	    })
	    .catch((res) => {
	      this.$Message.error(res.msg);
	    });
	},
    rechargeBnt(userInfo) {
      this.$emit('rechargeBnt', userInfo);
    },
    //立即退款
    getRefundData() {
      this.$emit('getRefundData');
    },
    // 订单备注
    remarks() {
      this.$emit('remarks');
    },
    point() {
      this.delfromData = {
        title: '立即打印订单',
        info: '您确认打印此订单吗?',
        url: `/order/print/${this.selectData.id}`,
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
    orderSend() {
      this.$emit('orderSend');
    },
    userChange(user) {
      this.$emit('userChange', user.uid);
    },
    calculate(row, isAdd) {
      this.$emit('calculate', {
        ...row,
        isAdd,
      });
    },
    discountCon() {
      this.$emit('discountCon');
    },
  },
};
</script>

<style lang="stylus" scoped>
.delete{
	display: inline-block;
	vertical-align: text-top;
}

.order-user {
  height: 100%;
}

.sel-user-group {
  display: flex;
  flex-wrap: wrap;
}

.sel-user {
  position: relative;
  width: calc(100% / 2 - 10px);
  display: flex;
  align-items: center;
  background-color: rgba(255, 119, 0, 0.05);
  padding: 18px;
  border-radius: 10px;
  margin: 0 10px 10px 0;

  &:only-child {
    width: 100%;
  }

  .iconfont {
    position: absolute;
    top: 14px;
    right: 14px;
    font-size: 22px;
    color: #FF7700;
  }

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

        .btn {
          padding: 3px 5px;
          border: 1px solid #1890FF;
          border-radius: 4px;
          margin-left: 6px;
          text-align: center;
          font-weight: 400;
          font-size: 12px;
          line-height: 12px;
          color: #1890FF;
        }
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
    flex: 1;
    color: #303133;
    font-size: 16px;

    .num {
      color: #FF7700;
    }
  }

  .money {
    // color: #F5222D;
    // font-size: 24px;
    // font-weight: bold;
  }

  .reduce {
    margin-right: 18px;
    font-weight: 500;
    font-size: 16px;
    color: #000000;

    .btn {
      margin-left: 7px;
    }
  }

  .actual {
    font-weight: 500;
    font-size: 16px;
    color: #000000;

    .money {
      font-size: 20px;
      color: #F5222D;
    }
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
        font-size: 12px;
        border: 1px solid #f5222d;
        background: #f5222d;
        color: #fff;
        padding: 0px 4px;
        border-radius: 3px;
      }
    }
  }

  .ivu-btn {
    width: 28px;
    height: 28px;
    padding: 0;
    border: 0;

    &:focus {
      box-shadow: none;
    }

    &.sub {
      background-color: #F5F5F5;
      color: #000000;
    }

    &.add {
      background-color: #1890FF;
      color: #FFFFFF;
    }
  }

  .ivu-input-number {
    width: 50px;
    border: 0;
    background-color: transparent;

    &:focus {
      box-shadow: none;
    }
  }

  .ivu-input-number-focused {
    box-shadow: none;
  }

  /deep/.ivu-input-number-handler-wrap {
    display: none;
  }

  /deep/.ivu-input-number-input {
    background-color: transparent;
    text-align: center;
  }

  .page {
    margin-top: 60px;
  }
}
</style>