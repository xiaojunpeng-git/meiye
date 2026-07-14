<template>
  <div>
    <FormItem label="等级会员价：">
      <RadioGroup v-model="formData.level_type">
        <Radio :label="1">默认价格</Radio>
        <Radio :label="2">自定义价格</Radio>
      </RadioGroup>
    </FormItem>
    <FormItem label="付费会员价：">
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
    <FormItem label="是否参与返佣：">
      <Switch
        v-model="formData.is_brokerage"
        :true-value="1"
        :false-value="0"
        size="large"
        @on-change="onIsBrokerageChange"
      >
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </Switch>
    </FormItem>
    <FormItem label="返佣比例：" v-if="formData.is_brokerage">
      <RadioGroup v-model="formData.is_sub" @on-change="changeSubType">
        <Radio :label="0">默认比例</Radio>
        <Radio :label="1">自定义佣金</Radio>
      </RadioGroup>
    </FormItem>
    <FormItem label="商品属性：">
      <el-table
        size="small"
        border=""
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
              transfer
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
          prop="cost"
          label="成本价"
          min-width="120"
          align="center"
          fixed="left"
        ></el-table-column>
        <el-table-column
          min-width="140"
          align="center"
          v-if="formData.is_vip == 1"
          key="is_vip"
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
                    <span class="inline-block lh-32px" v-show="vipSetType == 0"
                      >元</span
                    >
                    <span class="inline-block lh-32px" v-show="vipSetType == 1"
                      >%</span
                    >
                    <span class="inline-block lh-32px" v-show="vipSetType == 2"
                      >元</span
                    >
                  </template>
                </Input>
                <div class="flex-1 acea-row row-right row-middle">
                  <Button @click="closeVipSet">取消</Button>
                  <Button type="primary" class="ml-12" @click="vipSetConfirm"
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
            <div class="flex-x-center red" v-show="scope.row.vip_price == 0">
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
          min-width="120"
          align="center"
          v-if="formData.is_brokerage"
        >
          <template slot="header" slot-scope="scope">
            <span>一级返佣</span>
            <el-popover
              placement="top"
              width="254"
              trigger="click"
              v-if="formData.is_sub == 1"
            >
              <div class="pop-title">批量设置一级返佣</div>
              <div class="mt-14">
                <RadioGroup v-model="brokerageSetType">
                  <Radio :label="0">指定价格</Radio>
                  <Radio :label="1">折扣</Radio>
                </RadioGroup>
              </div>
              <div class="mt-14 flex-between-center">
                <Input
                  type="number"
                  @on-change="brokerageReplace"
                  class="w-85"
                  v-model="brokerage"
                >
                  <template #suffix>
                    <span class="inline-block lh-32px">{{
                      brokerageSetType ? '%' : '元'
                    }}</span>
                  </template>
                </Input>
                <div class="flex-1 acea-row row-right row-middle">
                  <Button @click="closePop">取消</Button>
                  <Button
                    type="primary"
                    class="ml-12"
                    @click="brokerageOneSetUp"
                    >确认</Button
                  >
                </div>
              </div>
              <span class="iconfont iconbianji1" slot="reference"></span>
            </el-popover>
          </template>
          <template slot-scope="scope">
            <div v-show="formData.is_sub == 1">
              <Input
                v-model="scope.row.brokerage"
                @on-change="brokerageRowReplace(scope.row)"
              >
                <template #suffix>
                  <span class="inline-block lh-32px">元</span>
                </template>
              </Input>
              <div
                class="flex-x-center red"
                v-show="Number(scope.row.brokerage) > Number(scope.row.price)"
              >
                佣金需不大于售价
              </div>
            </div>
            <div v-show="formData.is_sub == 0">
              <div class="flex-x-center">{{ storeBrokerageRatio * 100 }}%</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column
          min-width="120"
          align="center"
          v-if="formData.is_brokerage"
        >
          <template slot="header" slot-scope="scope">
            <span>二级返佣</span>
            <el-popover
              placement="top"
              width="254"
              trigger="click"
              v-if="formData.is_sub == 1"
            >
              <div class="pop-title">批量设置二级返佣</div>
              <div class="mt-14">
                <RadioGroup v-model="brokerageSetType">
                  <Radio :label="0">指定价格</Radio>
                  <Radio :label="1">折扣</Radio>
                </RadioGroup>
              </div>
              <div class="mt-14 flex-between-center">
                <Input
                  type="number"
                  @on-change="brokerageTwoReplace"
                  class="w-85"
                  v-model="brokerage_two"
                >
                  <template #suffix>
                    <span class="inline-block lh-32px">{{
                      brokerageSetType ? '%' : '元'
                    }}</span>
                  </template>
                </Input>
                <div class="flex-1 acea-row row-right row-middle">
                  <Button @click="closePop">取消</Button>
                  <Button
                    type="primary"
                    class="ml-12"
                    @click="brokerageTwoSetUp"
                    >确认</Button
                  >
                </div>
              </div>
              <span class="iconfont iconbianji1" slot="reference"></span>
            </el-popover>
          </template>
          <template slot-scope="scope">
            <div v-show="formData.is_sub == 1">
              <Input
                v-model="scope.row.brokerage_two"
                @on-change="brokerageTwoRowReplace(scope.row)"
              >
                <template #suffix>
                  <span class="inline-block lh-32px">元</span>
                </template>
              </Input>
              <div
                class="flex-x-center red"
                v-show="
                  Number(scope.row.brokerage_two) > Number(scope.row.price)
                "
              >
                佣金需不大于售价
              </div>
            </div>
            <div v-show="formData.is_sub == 0">
              <div class="flex-x-center">{{ storeBrokerageTwo * 100 }}%</div>
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
                v-model="scope.row.level_price[i].inputPrice"
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
            <div v-show="formData.level_type == 1">
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
  </div>
</template>

<script>
export default {
  props: {
    attrs: {
      type: [Array, Object],
      default() {
        return [];
      },
    },
    attrValue: {
      type: Array,
      default() {
        return [];
      },
    },
    levelList: {
      type: Array,
      default() {
        return [];
      },
    },
    levelType: {
      type: Number,
      default: 1,
    },
    isVip: {
      type: Number,
      default: 0,
    },
    isBrokerage: {
      type: Number,
      default: 0,
    },
    isSub: {
      type: Number,
      default: 0,
    },
    storeBrokerageRatio: {
      type: Number,
      default: 0,
    },
    storeBrokerageTwo: {
      type: Number,
      default: 0,
    },
    successData: {
      type: Boolean,
      default: false,
    },
  },
  data() {
    return {
      formData: {
        level_type: 1,
        is_vip: 0,
        is_brokerage: 0,
        is_sub: 0,
      },
      attrData: [],
      vipVisible: false,
      vipSetType: 0,
      vipSetNum: '',
      brokerage_two: '',
      brokerage: '',
      brokerageSetType: 0,
      levelSetType: 0,
      levelSetNum: '',
    };
  },
  watch: {
    attrs: {
      handler() {
        let attrData = [];
        let attrs = [];
        if (Array.isArray(this.attrs)) {
          attrs = this.attrs.slice(1);
        } else {
          attrs = [{ ...this.attrs, attr_arr: ['默认'] }];
        }
        for (const item of attrs) {
          const suk = this.attrData.find((value) => {
            return value.attr_arr.join() == item.attr_arr.join();
          });
          if (suk) {
            suk.level_price = suk.level_price.map((value) => {
              const price = Math.floor(this.$computes.Div(
                this.$computes.Mul(item.price, value.discount),
                100
              ) * 100) /100;
              const inputPrice =
                this.formData.level_type == 1 ? price : value.inputPrice;
              return {
                ...value,
                price,
                inputPrice,
              };
            });
            attrData.push({
              ...suk,
              price: item.price,
              cost: item.cost,
            });
          } else {
            item.level_price = this.levelList.map((value) => {
              const price = Math.floor(this.$computes.Div(
                this.$computes.Mul(item.price, value.discount),
                100
              ) * 100) / 100;
              return {
                ...value,
                price,
                inputPrice: price,
              };
            });
            attrData.push({
              ...item,
              suk: item.attr_arr.join(),
            });
          }
        }
        this.attrData = attrData;
      },
      deep: true,
    },
    'formData.level_type'() {
      this.attrData.forEach((item) => {
        item.level_price.forEach((value) => {
          value.inputPrice = value.price;
        });
      });
    },
    levelType: {
      handler() {
        this.formData.level_type = this.levelType;
      },
      immediate: true,
    },
    isVip: {
      handler() {
        this.formData.is_vip = this.isVip;
      },
      immediate: true,
    },
    isBrokerage: {
      handler() {
        this.formData.is_brokerage = this.isBrokerage;
      },
      immediate: true,
    },
    isSub: {
      handler() {
        this.formData.is_sub = this.isSub;
      },
      immediate: true,
    },
  },
  methods: {
    closeVipSet() {
      this.vipVisible = false;
      this.vipSetType = 0;
      this.vipSetNum = '';
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
    closePop() {
      document.body.click();
      this.brokerage_two = '';
      this.brokerage = '';
    },
    brokerageOneSetUp() {
      if (this.brokerageSetType == 1 && this.brokerage > 100)
        return this.$Message.error('折扣不可超过100');
      this.attrData.map((item) => {
        if (this.brokerageSetType == 0) {
          item.brokerage = this.brokerage;
        } else {
          item.brokerage = ((this.brokerage / 100) * item.price).toFixed(2);
        }
      });
      this.closePop();
    },
    brokerageTwoSetUp() {
      if (this.brokerageSetType == 1 && this.brokerage_two > 100)
        return this.$Message.error('折扣不可超过100');
      this.attrData.map((item) => {
        if (this.brokerageSetType == 0) {
          item.brokerage_two = this.brokerage_two;
        } else {
          item.brokerage_two = (
            (this.brokerage_two / 100) *
            item.price
          ).toFixed(2);
        }
      });
      this.closePop();
    },
    closeLevelSet(i) {
      console.log(this.$refs)
      this.$refs['popoverRef_' + i][0].doClose(); //关闭的
      this.levelSetType = 0;
      this.levelSetNum = '';
    },
    levelSetConfirm(index) {
      if (this.levelSetNum == 0)
        return this.$Message.error('等级会员价不可为0');
      if (this.levelSetType == 1 && this.levelSetNum > 100)
        return this.$Message.error('折扣不可超过100');
      this.attrData.forEach((item) => {
        switch (this.levelSetType) {
          case 0:
            item.level_price[index].inputPrice = this.levelSetNum;
            break;
          case 1:
            item.level_price[index].inputPrice = Math.floor(this.$computes.Mul(
              this.$computes.Div(this.levelSetNum, 100),
              item.price
            ) * 100) / 100;
            break;
          case 2:
            item.level_price[index].inputPrice = this.$computes.Sub(
              item.price,
              this.levelSetNum
            );
            break;
        }
      });
      this.closeLevelSet(index);
    },
    vipPriceReplace(event) {
      this.vipSetNum = this.cleanPrice(event.target.value);
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
    levelPriceReplace(event) {
      this.levelSetNum = this.cleanPrice(event.target.value);
    },
    brokerageTwoReplace(event) {
      this.brokerage_two = this.cleanPrice(event.target.value);
    },
    brokerageReplace(event) {
      this.brokerage = this.cleanPrice(event.target.value);
    },
    changeSubType(val) {
      this.attrData.map((item) => {
        item.brokerage =
          val == 0
            ? this.storeBrokerageRatio
            : (item.price * this.storeBrokerageRatio).toFixed(2);
        item.brokerage_two =
          val == 0
            ? this.storeBrokerageTwo
            : (item.price * this.storeBrokerageTwo).toFixed(2);
      });
    },
    levelRowReplace(row, i) {
      row.level_price[i].inputPrice = this.cleanPrice(
        row.level_price[i].inputPrice
      );
    },
    validateForm() {
      // 付费会员价
      if (this.formData.is_vip) {
        for (let i = 0; i < this.attrData.length; i++) {
          if (Number(this.attrData[i].vip_price) <= 0) {
            this.$Message.error('付费会员价需大于0');
            return false;
          }
          if (
            Number(this.attrData[i].price) < Number(this.attrData[i].vip_price)
          ) {
            this.$Message.error('付费会员价需不大于售价');
            return false;
          }
        }
      }
      // 自定义等级会员价
      if (this.formData.level_type == 2) {
        for (const item of this.attrData) {
          const result = item.level_price.some(
            (value) => Number(value.inputPrice) > Number(item.price)
          );
          if (result) {
            this.$Message.error('等级会员价需不大于售价');
            return false;
          }
        }
      }
      let step = true;
      if (this.formData.is_brokerage && this.formData.is_sub) {
        this.attrData.forEach((item) => {
          if (
            Number(item.brokerage) > Number(item.price) ||
            Number(item.brokerage_two) > Number(item.price)
          ) {
            step = false;
          }
        });
      }
      if (!step) {
        this.$Message.error('佣金需不大于售价');
        return false;
      }
      return true;
    },
    getFormData() {
      const formData = JSON.parse(JSON.stringify(this.formData));
      const attrData = JSON.parse(JSON.stringify(this.attrData));
      if (formData.level_type == 2) {
        attrData.forEach((item) => {
          item.level_price.forEach((value) => {
            value.price = value.inputPrice;
          });
        });
      }
      return {
        ...formData,
        attrData,
      };
    },
    onIsBrokerageChange() {
      this.formData.is_sub = 0;
    },
  },
};
</script>

<style lang="less" scoped>
.iconbianji1 {
  font-size: 12px;
  padding-left: 4px;
  cursor: pointer;
}
/deep/.el-table th > .cell {
  justify-content: center;
}
</style>