<template>
  <div>
    <Modal
      :value="visible"
      :z-index="2"
      title="自定义佣金"
      width="900"
      @on-cancel="onCancel"
      footer-hide
      ><Form :model="formData" :label-width="80">
        <FormItem label="是否参与：">
          <RadioGroup v-model="formData.is_brokerage">
            <Radio :label="0">不参与返佣</Radio>
            <Radio :label="1">参与返佣</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem label="返佣设置：" v-show="formData.is_brokerage">
          <RadioGroup v-model="formData.is_sub" @on-change="changeSubType">
            <Radio :label="0">默认比例</Radio>
            <Radio :label="1">自定义佣金</Radio>
          </RadioGroup>
          <div class="fs-12 text--w111-999" v-show="formData.is_sub">切换到默认比例时，表格中编辑的返佣金额会被清空，请谨慎操作</div>
        </FormItem>
        <FormItem :label-width="0" v-show="formData.is_brokerage">
          <el-table
            size="small"
            border
            max-height="460"
            :data="attrData"
            style="width: 100%"
            :header-cell-style="{
              'background-color': '#f3f8fe',
              'color': '#515a6e',
            }"
          >
            <el-table-column
              prop="suk"
              label="产品规格"
              min-width="120"
              align="center"
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
            ></el-table-column>
            <el-table-column min-width="120" align="center">
              <template slot="header" slot-scope="scope">
                <span>一级返佣</span>
                <el-popover
                  ref="popoverRef_one"
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
                    <Input type="number" @on-change="brokerageReplace" class="w-85" v-model="brokerage">
                      <template #suffix>
                        <span class="inline-block lh-32px">{{ brokerageSetType ? '%' : '元' }}</span>
                      </template>
                    </Input>
                    <div class="flex-1 acea-row row-right row-middle">
                      <Button @click="closePop">取消</Button>
                      <Button type="primary" class="ml-12"
                        @click="brokerageOneSetUp">确认</Button
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
                  <div class="flex-x-center red" v-show=" Number(scope.row.brokerage) > Number(scope.row.price)">佣金不可大于售价</div>
                </div>
                <div v-show="formData.is_sub == 0">
                  <div class="flex-x-center">{{store_brokerage_ratio * 100}}% </div>
                </div>
              </template>
            </el-table-column>
            <el-table-column min-width="120" align="center">
              <template slot="header" slot-scope="scope">
                <span>二级返佣</span>
                <el-popover
                  ref="popoverRef_two"
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
                    <Input type="number" @on-change="brokerageTwoReplace" class="w-85" v-model="brokerage_two">
                      <template #suffix>
                        <span class="inline-block lh-32px">{{ brokerageSetType ? '%' : '元' }}</span>
                      </template>
                    </Input>
                    <div class="flex-1 acea-row row-right row-middle">
                      <Button @click="closePop">取消</Button>
                      <Button type="primary" class="ml-12"
                        @click="brokerageTwoSetUp">确认</Button
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
                  <div class="flex-x-center red" v-show=" Number(scope.row.brokerage_two) > Number(scope.row.price)">佣金不可大于售价</div>
                </div>
                <div v-show="formData.is_sub == 0">
                  <div class="flex-x-center">{{store_brokerage_two * 100}}% </div>
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
  name: 'brokerageSet',
  data() {
    return {
      formData: {
        is_brokerage: 0,
        is_sub: 0,
      },
      brokerage: '',
      brokerage_two: '',
      loading: false,
      attrData: [],
      disabled: false,
      brokerageSetType: 0,
      store_brokerage_ratio: '',
      store_brokerage_two: '',
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
    brokerageReplace(event){
      this.brokerage = this.cleanPrice(event.target.value);
    },
    brokerageTwoReplace(event){
      this.brokerage_two = this.cleanPrice(event.target.value);
    },
    submitForm() {
      let step = true;
      if(this.formData.is_brokerage && this.formData.is_sub){
        this.attrData.forEach(item=>{
          if(Number(item.brokerage) > Number(item.price) || Number(item.brokerage_two) > Number(item.price)){
            step = false;
          }
        })
      }
      if(!step) return this.$Message.error("佣金不可为大于售价");
      this.disabled = true;
      let data = {
        ...this.formData,
        attr_value: this.attrData,
      };
      productBrokerageUpdate(this.productId, 1, data)
        .then((res) => {
          this.disabled = false;
          this.$Message.success(res.msg);
          this.$emit('close');
        })
        .catch((err) => {
          this.$Message.error(err.msg);
          this.disabled = false;
        });
    },
    brokerageOneSetUp() {
      if(this.brokerageSetType == 1 && this.brokerage > 100) return this.$Message.error("折扣不可超过100");
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
      if(this.brokerageSetType == 1 && this.brokerage_two > 100) return this.$Message.error("折扣不可超过100");
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
    brokerageRowReplace(item){
      item.brokerage = this.cleanPrice(item.brokerage);
    },
    brokerageTwoRowReplace(item){
       item.brokerage_two = this.cleanPrice(item.brokerage_two);
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
    closePop() {
      this.$refs.popoverRef_one.doClose();
      this.$refs.popoverRef_two.doClose();
      this.brokerage_two = '';
      this.brokerage = '';
    },
    changeSubType(val) {
      this.attrData.map((item) => {
        item.brokerage = val == 0 ? this.store_brokerage_ratio : (item.price * this.store_brokerage_ratio).toFixed(2);
        item.brokerage_two = val == 0 ? this.store_brokerage_two : (item.price * this.store_brokerage_two).toFixed(2);
      });
    },
    onCancel() {
      this.$emit('close');
    },
    getData() {
      //获取产品属性
      productBrokerage(this.productId, 1).then((res) => {
        this.attrData = Object.values(res.data.attrValue);
        if (res.data.storeInfo.is_sub == 0) {
          this.attrData.map((item) => {
            item.brokerage = res.data.store_brokerage_ratio * 100;
            item.brokerage_two = res.data.store_brokerage_two * 100;
          });
        }
        this.formData.is_brokerage = res.data.storeInfo.is_brokerage;
        this.formData.is_sub = res.data.storeInfo.is_sub;
        this.store_brokerage_ratio = res.data.store_brokerage_ratio;
        this.store_brokerage_two = res.data.store_brokerage_two;
      });
    },
  },
};
</script>
<style scoped lang="less">
/deep/.ivu-input-group-append {
  background: #fff;
}
/deep/.ivu-input-group .ivu-input {
  border-right: 0;
}
.iconbianji1 {
  font-size: 12px;
  padding-left: 4px;
  cursor: pointer;
}
.w-85{
  width: 85px;
}
.pop-title{
  font-size: 14px;
  font-weight: bold;
  margin-bottom: 14px;
}
.red{
  color: #e93323;
}
</style>
