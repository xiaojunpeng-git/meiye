<template>
  <Modal
    v-model="priceModals"
    scrollable
    title="订单改价"
    width="800"
    :closable="false"
  >
    <Form
      ref="formValidate"
      :model="formValidate"
      :label-width="80"
      @submit.native.prevent
    >
      <FormItem :label-width="0">
        <Table
          :columns="columns"
          :data="cartInfo"
          border
          no-data-text="暂无数据"
          highlight-row
          no-filtered-data-text="暂无筛选结果"
          max-height="350"
        >
          <template slot-scope="{ row, index }" slot="name">
            <div class="line1">{{ row.productInfo.store_name }}</div>
          </template>
          <template slot-scope="{ row, index }" slot="price">
            <div>¥{{ row.sum_price }}</div>
          </template>
          <template slot-scope="{ row, index }" slot="true_price">
            <div>¥{{ $computes.Mul(row.truePrice, row.cart_num) }}</div>
          </template>
          <template slot-scope="{ row, index }" slot="change_price">
            <div>
              <Input
                v-model="row.changePrice"
                type="number"
                @on-change="changeTap(row, index)"
              >
                <template #prepend>
                  <div class="w-75">
                    <Select
                      v-model="row.priceType"
                      transfer
                      @on-change="changeTap(row, index)"
                    >
                      <Option :value="1">一口价</Option>
                      <Option :value="2">减价</Option>
                      <Option :value="3">折扣</Option>
                    </Select>
                  </div>
                </template>
                <template #append>
                  <div class="fs-12 text-wlll-909399">
                    {{ row.priceType == 3 ? '%' : '元' }}
                  </div>
                </template>
              </Input>
            </div>
          </template>
          <template slot-scope="{ row, index }" slot="result_price">
            <div>¥{{ row.resultPrice }}</div>
          </template>
        </Table>
      </FormItem>
      <FormItem label="赠送积分：">
        <div class="acea-row row-between">
          <div>
            <InputNumber
              :precision="0"
              :min="0"
              :max="9999999999"
              v-model="formValidate.gain_integral"
            />
            <span class="ml-20">免邮：</span>
            <i-switch
              v-model="formValidate.is_postage"
              :true-value="1"
              :false-value="0"
              size="large"
            >
              <span slot="open">开启</span>
              <span slot="close">关闭</span>
            </i-switch>
          </div>
          <div>
            <div class="text-right">
              <span>应付邮费：¥{{ payPostage }}</span>
              <span class="ml-23"
                >实际支付邮费：<span class="text-wlll-f5222d"
                  >¥{{ resultPayPostage }}</span
                ></span
              >
            </div>
            <div class="text-right">
              <span
                >总价：¥{{ $computes.Add(payPrice, resultPayPostage) }}</span
              >
              <span class="ml-23"
                >修改后总价：<span class="text-wlll-f5222d"
                  >¥{{ $computes.Add(resultPayPrice, resultPayPostage) }}</span
                ></span
              >
            </div>
          </div>
        </div>
      </FormItem>
    </Form>
    <div slot="footer">
      <Button @click="cancel">取消</Button>
      <Button type="primary" @click="submit">确认</Button>
    </div>
  </Modal>
</template>

<script>
import { getOrdeUpdateInfo, putOrdeUpdate } from '@/api/order';
export default {
  name: 'changePrice',
  data() {
    return {
      id: 0, //订单id；
      priceModals: false,
      columns: [
        {
          title: '商品',
          slot: 'name',
          align: 'left',
          minWidth: 150,
        },
        {
          title: '单价',
          slot: 'price',
          align: 'left',
          minWidth: 80,
        },
        {
          title: '数量',
          key: 'cart_num',
          align: 'left',
          minWidth: 70,
        },
        {
          title: '应收金额',
          slot: 'true_price',
          align: 'left',
          minWidth: 80,
        },
        {
          title: '改价',
          slot: 'change_price',
          align: 'left',
          minWidth: 200,
        },
        {
          title: '改价后金额',
          slot: 'result_price',
          align: 'left',
          minWidth: 80,
        },
      ],
      cartInfo: [],
      payPostage: 0, //应付邮费；
      payPrice: 0, //总价（不含邮费）
      resultPayPrice: 0, //修改后总价（不含邮费）
      formValidate: {
        is_postage: 0,
        gain_integral: 0,
        cart_info: [],
      },
      timeoutId: null, //定时器
    };
  },
  computed: {
    //实际支付邮费；
    resultPayPostage() {
      return this.formValidate.is_postage ? 0 : this.payPostage;
    },
  },
  methods: {
    ordeUpdateInfo(id) {
      getOrdeUpdateInfo(id)
        .then((res) => {
          let orderInfo = res.data.orderInfo;
          this.formValidate.gain_integral = parseFloat(orderInfo.gain_integral);
          this.payPostage = parseFloat(orderInfo.pay_postage);
          this.payPrice = this.$computes.Sub(
            parseFloat(orderInfo.pay_price),
            this.payPostage
          );
          let resultPayPrice = 0;
          let cartInfo = [];
          res.data.cartInfo.forEach((item) => {
            if (!item.is_gift) {
              item.priceType = 1;
              item.truePrice = parseFloat(item.truePrice);
              item.changePrice = this.$computes.Mul(
                item.truePrice,
                item.cart_num
              );
              item.resultPrice = item.changePrice;
              resultPayPrice = this.$computes.Add(
                resultPayPrice,
                item.resultPrice
              );
              cartInfo.push(item);
            }
          });
          this.cartInfo = cartInfo;
          this.resultPayPrice = resultPayPrice;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 计算改价
    changeTap(item, index) {
      let that = this;
      if (item.changePrice < 0) {
        clearTimeout(that.timeoutId);
        that.timeoutId = setTimeout(function () {
          item.changePrice = 0;
        });
      }
      if (item.priceType == 1) {
        let money = this.$computes.Mul(item.changePrice, 1); //乘一为了保留2为小数生效；
        item.resultPrice = money >= 0 ? money : 0;
      } else if (item.priceType == 2) {
        let money = that.$computes.Sub(
          that.$computes.Mul(item.truePrice, item.cart_num),
          item.changePrice
        );
        item.resultPrice = money > 0 ? money : 0;
      } else {
        if (item.changePrice >= 0) {
          clearTimeout(that.timeoutId);
          that.timeoutId = null;
          if (item.changePrice >= 100) {
            setTimeout(function () {
              item.changePrice = 100;
            });
          }
        }
        setTimeout(function () {
          let money = that.$computes.Mul(
            that.$computes.Mul(item.truePrice, item.cart_num),
            that.$computes.Div(item.changePrice, 100)
          );
          item.resultPrice = money >= 0 ? money : item.truePrice;
        });
      }
      setTimeout(function () {
        that.cartInfo[index] = item;
        let resultPayPrice = 0;
        that.cartInfo.forEach((item) => {
          resultPayPrice = that.$computes.Add(resultPayPrice, item.resultPrice);
        });
        that.resultPayPrice = resultPayPrice;
      });
    },
    cancel() {
      this.priceModals = false;
    },
    ordeUpdate() {
      putOrdeUpdate(this.id, this.formValidate)
        .then(async (res) => {
          this.$Message.success(res.msg);
          this.priceModals = false;
          this.$emit('submitSuccess', res);
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    submit() {
      let data = [],
        nameInfo = '';
      this.cartInfo.forEach((item, index) => {
        if (
          item.resultPrice < this.$computes.Mul(item.costPrice, item.cart_num)
        ) {
          nameInfo =
            nameInfo +
            '商品' +
            (index + 1) +
            '：' +
            item.productInfo.store_name +
            '；';
        }
        data.push({
          id: item.id,
          true_price: item.resultPrice,
        });
      });
      this.formValidate.cart_info = data;
      if (nameInfo) {
        this.$Modal.confirm({
          title: '订单改价',
          content: nameInfo + '改价后金额低于成本价，是否继续改价?',
          onOk: () => {
            this.ordeUpdate();
          },
        });
      } else {
        this.ordeUpdate();
      }
    },
  },
};
</script>

<style scoped lang="stylus">
/deep/.ivu-input-group {
  width: 96%;
}

/deep/.ivu-table-column-left {
  padding-left: 16px !important;
}

/deep/.ivu-table .ivu-table-header tr th {
  background-color: #F3F8FE !important;
  padding-left: 16px !important;
}

/deep/.ivu-table-header thead tr th {
  padding: 0 !important;
}

/deep/.ivu-modal-body {
  padding: 16px 20px;
}

/deep/.ivu-input-group-append {
  background: #fff;
}

/deep/.ivu-input-group .ivu-input {
  border-right: 0;
}
</style>