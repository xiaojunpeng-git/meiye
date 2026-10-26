<template>
  <div>
    <Modal
      :value="visible"
      :z-index="2"
      title="自定义会员价"
      width="900"
      @on-cancel="onCancel"
      footer-hide
      ><Form :model="formData" :label-width="80">
        <FormItem label="付费会员：">
          <Switch
            v-model="formData.is_vip"
            :true-value="1"
            :false-value="0"
            size="large"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </Switch>
        </FormItem>
        <FormItem label="等级会员：">
          <RadioGroup
            v-model="formData.level_type"
            @on-change="changeLevelType"
          >
            <Radio :label="1">默认价格</Radio>
            <Radio :label="2">自定义</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem :label-width="0">
          <el-table
            size="small"
            border
            max-height="460"
            :data="attrData"
            style="width: 100%"
            :header-cell-style="{
              'background-color': '#f3f8fe',
              color: '#515a6e',
            }"
          >
            <el-table-column
              prop="suk"
              label="产品规格"
              min-width="120"
              align="center"
              fixed="left"
            >
              <template slot-scope="scope">
                <Tooltip
                  theme="dark"
                  max-width="300"
                  :delay="600"
                  :content="scope.row.suk"
                  :transfer="true"
                >
                  <div class="line2">{{ scope.row.suk }}</div>
                </Tooltip>
              </template>
            </el-table-column>
            <el-table-column
              prop="price"
              label="售价"
              min-width="120"
              align="center"
              fixed="left"
            ></el-table-column>
            <el-table-column
              min-width="140"
              align="center"
              v-if="formData.is_vip == 1"
            >
              <template slot="header" slot-scope="scope">
                <span>付费会员价</span>
                <el-popover
                  v-model="vipVisible"
                  placement="top"
                  width="254"
                  trigger="manual"
                >
                  <div class="pop-title">批量修改本列</div>
                  <div class="mt-14">
                    <RadioGroup v-model="vipSetType">
                      <Radio :label="0">指定价格</Radio>
                      <Radio :label="1">折扣</Radio>
                      <Radio :label="2">减现</Radio>
                    </RadioGroup>
                  </div>
                  <div class="mt-14 flex-between-center">
                    <Input
                      type="number"
                      class="w-85"
                      @on-change="vipPriceReplace"
                      v-model="vipSetNum"
                    >
                      <template #suffix>
                        <span
                          class="inline-block lh-32px"
                          v-show="vipSetType == 0"
                          >元</span
                        >
                        <span
                          class="inline-block lh-32px"
                          v-show="vipSetType == 1"
                          >%</span
                        >
                        <span
                          class="inline-block lh-32px"
                          v-show="vipSetType == 2"
                          >元</span
                        >
                      </template>
                    </Input>
                    <div class="flex-1 acea-row row-right row-middle">
                      <Button @click="closeVipSet">取消</Button>
                      <Button
                        type="primary"
                        class="ml-12"
                        @click="vipSetConfirm"
                        >确认</Button
                      >
                    </div>
                  </div>
                  <span
                    class="iconfont iconbianji1"
                    slot="reference"
                    @click="vipVisible = true"
                  ></span>
                </el-popover>
              </template>
              <template slot-scope="scope">
                <Input
                  type="number"
                  v-model="scope.row.vip_price"
                  @on-change="vipRowReplace(scope.row)"
                >
                  <template #suffix>
                    <span class="inline-block lh-32px">元</span>
                  </template>
                </Input>
                <div
                  class="flex-x-center red"
                  v-show="scope.row.vip_price == 0"
                >
                  会员价不可为0
                </div>
                <div
                  class="flex-x-center red"
                  v-show="Number(scope.row.vip_price) > Number(scope.row.price)"
                >
                  会员价不可大于售价
                </div>
              </template>
            </el-table-column>
            <el-table-column
              min-width="140"
              align="center"
              v-for="(item, i) in levelList"
              :key="i"
            >
              <template slot="header" slot-scope="scope">
                <span>用户等级{{ i + 1 }}</span>
                <el-popover
                  :ref="'popoverRef_' + i"
                  placement="top"
                  width="254"
                  trigger="click"
                  v-if="formData.level_type == 2"
                >
                  <div class="pop-title">批量修改本列</div>
                  <div class="mt-14">
                    <RadioGroup v-model="levelSetType">
                      <Radio :label="0">指定价格</Radio>
                      <Radio :label="1">折扣</Radio>
                      <Radio :label="2">减现</Radio>
                    </RadioGroup>
                  </div>
                  <div class="mt-14 flex-between-center">
                    <Input
                      type="number"
                      @on-change="levelPriceReplace"
                      class="w-85"
                      v-model="levelSetNum"
                    >
                      <template #suffix>
                        <span
                          class="inline-block lh-32px"
                          v-show="levelSetType == 0"
                          >元</span
                        >
                        <span
                          class="inline-block lh-32px"
                          v-show="levelSetType == 1"
                          >%</span
                        >
                        <span
                          class="inline-block lh-32px"
                          v-show="levelSetType == 2"
                          >元</span
                        >
                      </template>
                    </Input>
                    <div class="flex-1 acea-row row-right row-middle">
                      <Button @click="closeLevelSet(i)">取消</Button>
                      <Button
                        type="primary"
                        class="ml-12"
                        @click="levelSetConfirm(i)"
                        >确认</Button
                      >
                    </div>
                  </div>
                  <span
                    class="iconfont iconbianji1"
                    slot="reference"
                    @click="vipVisible = false"
                  ></span>
                </el-popover>
              </template>
              <template slot-scope="scope">
                <div v-if="formData.level_type == 2">
                  <Input
                    type="number"
                    v-model="scope.row.level_price[i].price"
                    @on-change="levelRowReplace(scope.row, i)"
                  >
                    <template #suffix>
                      <span class="inline-block lh-32px">元</span>
                    </template>
                  </Input>
                  <div
                    class="flex-x-center red"
                    v-show="scope.row.level_price[i].price == 0"
                  >
                    会员价不可为0
                  </div>
                  <div
                    class="flex-x-center red"
                    v-show="
                      Number(scope.row.level_price[i].price) >
                      Number(scope.row.price)
                    "
                  >
                    会员价不可大于售价
                  </div>
                </div>
                <div v-if="formData.level_type == 1">
                  <div class="flex-x-center">
                    {{ scope.row.level_price[i].discount }}%
                    <span class="text--w111-999 pl-4"
                      >( ¥{{ scope.row.level_price[i].price }} )</span
                    >
                  </div>
                </div>
              </template>
            </el-table-column>
          </el-table>
        </FormItem>
      </Form>
      <div class="acea-row row-right row-middle">
        <Button @click="onCancel">取消</Button>
        <Button
          type="primary"
          @click="submitForm"
          :disabled="disabled"
          class="ml-14"
          >确认</Button
        >
      </div>
    </Modal>
  </div>
</template>
<script>
import { productBrokerage, productBrokerageUpdate } from '@/api/product';
export default {
  name: 'vipPriceSet',
  data() {
    return {
      formData: {
        is_vip: 0,
        level_type: 1,
      },
      brokerage: '',
      brokerage_two: '',
      loading: false,
      attrData: [],
      disabled: false,
      levelList: [],
      vipSetType: 0,
      vipSetNum: '',
      levelSetType: 0,
      levelSetNum: '',
      vipVisible: false,
    };
  },
  props: {
    visible: {
      type: Boolean,
      default: false,
    },
    productId: {
      type: Number,
      default: 0,
    },
  },
  watch: {
    visible(val) {
      if (val) {
        this.getData();
      }
    },
  },
  methods: {
    vipPriceReplace(event) {
      this.vipSetNum = this.cleanPrice(event.target.value);
    },
    levelPriceReplace(event) {
      this.levelSetNum = this.cleanPrice(event.target.value);
    },
    submitForm() {
      if (this.formData.is_vip) {
        for (let i = 0; i < this.attrData.length; i++) {
          if (
            Number(this.attrData[i].price) < Number(this.attrData[i].vip_price)
          ) {
            return this.$Message.error('付费会员价不可大于售价');
          }
        }
      }
      if (this.formData.level_type == 2) {
        for (let i = 0; i < this.attrData.length; i++) {
          for (let j = 0; j < this.attrData[i].level_price.length; j++) {
            if (
              Number(this.attrData[i].price) <
              Number(this.attrData[i].level_price[j].price)
            ) {
              return this.$Message.error('等级会员不可大于售价');
            }
          }
        }
      }
      this.disabled = true;
      let data = {
        ...this.formData,
        attr_value: this.attrData,
      };
      productBrokerageUpdate(this.productId, 2, data)
        .then((res) => {
          this.$Message.success(res.msg);
          this.disabled = false;
          this.$emit('close');
        })
        .catch((err) => {
          this.$Message.error(err.msg);
          this.disabled = false;
        });
    },
    closeVipSet() {
      this.vipVisible = false;
      this.vipSetType = 0;
      this.vipSetNum = '';
    },
    closeLevelSet(i) {
      this.$refs['popoverRef_' + i][0].doClose(); //关闭的
      this.levelSetType = 0;
      this.levelSetNum = '';
    },
    vipSetConfirm() {
      if (this.vipSetNum == 0) return this.$Message.error('会员价不可为0');
      if (this.vipSetType == 1 && this.vipSetNum > 100)
        return this.$Message.error('折扣不可超过100');
      this.attrData.map((item) => {
        if (this.vipSetType == 0) {
          item.vip_price = this.vipSetNum;
        } else if (this.vipSetType == 1) {
          item.vip_price = ((this.vipSetNum / 100) * item.price).toFixed(2);
        } else {
          item.vip_price = item.price - this.vipSetNum;
        }
      });
      this.closeVipSet();
    },
    levelSetConfirm(index) {
      if (this.levelSetNum == 0)
        return this.$Message.error('等级会员价不可为0');
      if (this.levelSetType == 1 && this.levelSetNum > 100)
        return this.$Message.error('折扣不可超过100');
      this.attrData.map((item) => {
        if (this.levelSetType == 0) {
          item.level_price[index].price = this.levelSetNum;
        } else if (this.levelSetType == 1) {
          item.level_price[index].price = (
            (this.levelSetNum / 100) *
            item.price
          ).toFixed(2);
        } else {
          item.level_price[index].price = item.price - this.levelSetNum;
        }
      });
      this.closeLevelSet(index);
    },
    onCancel() {
      this.closeVipSet();
      this.levelSetType = 0;
      this.levelSetNum = '';
      this.$emit('close');
    },
    changeLevelType(type) {
      this.attrData.map((item) => {
        this.$set(
          item,
          'level_price',
          this.levelList.map((val) => {
            return {
              ...val,
              discount: this.$computes.Mul(val.discount, 100),
              price: Math.floor(this.$computes.Mul(item.price, val.discount) * 100) / 100,
            };
            // return {
            //   id: val.id,
            //   // price: type == 1 ? val.discount * 100 : item.price
            //   // 下面注释的这行可以在选择自定义方式时读取等级的比例乘以售价，相较于直接读取售价可以读出等级的折扣比例
            //   price:
            //     type == 1
            //       ? val.discount * 100
            //       : (item.price * val.discount).toFixed(2),
            // };
          })
        );
      });
    },
    cleanPrice(value) {
      // 移除非数字和非小数点的字符
      let cleanedValue = value.replace(/[^\d.]/g, '');
      // 确保只有一个小数点
      let parts = cleanedValue.split('.');
      if (parts.length > 2) {
        cleanedValue = parts[0] + '.' + parts.slice(1).join('');
      }
      // 确保小数点后最多有两位数字
      if (cleanedValue.includes('.')) {
        let [integerPart, decimalPart] = cleanedValue.split('.');
        cleanedValue = integerPart + '.' + decimalPart.slice(0, 2);
      }
      return cleanedValue;
    },
    vipRowReplace(row) {
      row.vip_price = this.cleanPrice(row.vip_price);
    },
    levelRowReplace(row, i) {
      row.level_price[i].price = this.cleanPrice(row.level_price[i].price);
    },
    getData() {
      //获取产品属性
      productBrokerage(this.productId, 2).then((res) => {
        this.attrData = Object.values(res.data.attrValue);
        this.levelList = res.data.level_list;
        if (this.levelList.length) {
          this.attrData.forEach((item) => {
            if (Array.isArray(item.level_price)) {
              this.$set(
                item,
                'level_price',
                this.levelList.map((val) => {
                  const levelValue = item.level_price.find((level) => {
                    return level.id == val.id;
                  });
                  if (levelValue) {
                    if (levelValue.discount == undefined) {
                      levelValue.discount = this.$computes.Mul(
                        val.discount,
                        100
                      );
                      levelValue.price = this.$computes.Mul(
                        val.discount,
                        item.price
                      );
                    }
                    return {
                      ...levelValue,
                    };
                  } else {
                    return {
                      ...val,
                      discount: this.$computes.Mul(val.discount, 100),
                      price:
                        res.data.storeInfo.level_type == 1
                          ? this.$computes.Mul(val.discount, item.price)
                          : 0,
                    };
                  }
                })
              );
            }
          });
        }
        this.formData.is_vip = res.data.storeInfo.is_vip;
        this.formData.level_type = res.data.storeInfo.level_type;
      });
    },
  },
};
</script>
<style scoped lang="less">
/deep/.ivu-input-group-append {
  background: #fff;
  font-size: 12px;
}
/deep/.ivu-input-group .ivu-input {
  border-right: 0;
}
.iconbianji1 {
  font-size: 12px;
  padding-left: 4px;
  cursor: pointer;
}
.w-250 {
  width: 250px;
}
.pop-title {
  font-size: 14px;
  font-weight: bold;
  margin-bottom: 14px;
}
.red {
  color: #e93323;
}
.h-23 {
  height: 23px;
}
.w-85 {
  width: 85px;
}
</style>
