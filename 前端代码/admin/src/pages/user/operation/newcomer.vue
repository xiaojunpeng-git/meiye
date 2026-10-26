<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Form :model="loginForm" :label-width="120">
        <Row :gutter="24" type="flex">
          <Col span="24">
            <FormItem label="注册有礼启用：">
              <Switch
                size="large"
                v-model="loginForm.newcomer_status"
                :true-value="1"
                :false-value="0"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </Switch>
              <div class="upload-text">新用户注册后，会给用户赠送礼品</div>
            </FormItem>
          </Col>
          <Col span="24" v-if="loginForm.newcomer_status === 1">
            <FormItem label="是否限时：" v-if="loginForm.newcomer_status === 1">
              <RadioGroup v-model="loginForm.newcomer_limit_status">
                <Radio label="0">
                  <span>不限时</span>
                </Radio>
                <Radio label="1">
                  <span>限时</span>
                </Radio>
              </RadioGroup>
              <div class="upload-text">新人注册活动的时间设置</div>
              <div class="mt10" v-if="loginForm.newcomer_limit_status == 1">
                <Input
                  v-model="loginForm.newcomer_limit_time"
                  placeholder="请输入限时天数"
                  class="inputw"
                ></Input>
                <span
                  class="span-text"
                  v-if="loginForm.newcomer_limit_status == 1"
                >
                  天
                </span>
              </div>
            </FormItem>
          </Col>
          <Col span="24" class="mt10">
            <FormItem label="赠送积分：" v-if="loginForm.newcomer_status === 1">
              <Switch
                size="large"
                v-model="loginForm.register_integral_status"
                :true-value="1"
                :false-value="0"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </Switch>
              <div class="upload-text">用户注册后即赠送一定数额的积分</div>
              <InputNumber v-model="loginForm.register_give_integral" :min="1" :precision="0" class="inputw mt10" v-if="loginForm.register_integral_status === 1"></InputNumber>
              <span
                class="span-text"
                v-if="loginForm.register_integral_status === 1"
              >
                积分
              </span>
            </FormItem>
          </Col>
          <Col span="24" class="mt10" v-if="loginForm.newcomer_status === 1">
            <FormItem label="赠送余额：" v-if="loginForm.newcomer_status === 1">
              <Switch
                size="large"
                v-model="loginForm.register_money_status"
                :true-value="1"
                :false-value="0"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </Switch>
              <div class="upload-text">用户注册后即赠送一定数额的储值余额</div>
              <InputNumber v-model="loginForm.register_give_money" :min="1" :precision="0" class="inputw mt10" v-if="loginForm.register_money_status === 1"></InputNumber>
              <span
                class="span-text"
                v-if="loginForm.register_money_status === 1"
              >
                元
              </span>
            </FormItem>
          </Col>
          <Col span="24" class="mt10" v-if="loginForm.newcomer_status === 1">
            <div
              class="item"
              v-for="(item, indexw) in promotionsData"
              :key="indexw"
            >
              <!-- ----赠送优惠券------ -->
              <FormItem
                label="赠送优惠券："
                v-if="loginForm.newcomer_status === 1"
              >
                <Switch
                  size="large"
                  v-model="loginForm.register_coupon_status"
                  :true-value="1"
                  :false-value="0"
                >
                  <span slot="open">开启</span>
                  <span slot="close">关闭</span>
                </Switch>
                <div class="upload-text">用户注册后即赠送优惠券</div>
                <Table
                  border
                  :columns="columns1"
                  :data="item.giveCoupon"
                  ref="table"
                  class="table mt10"
                  width="700"
                  v-if="
                    loginForm.register_coupon_status === 1 &&
                    item.giveCoupon.length > 0
                  "
                >
                  <template slot-scope="{ row }" slot="coupon_price">
                    <span v-if="row.coupon_type == 1">
                      {{ row.coupon_price }}元
                    </span>
                    <span v-if="row.coupon_type == 2">
                      {{ parseFloat(row.coupon_price) / 10 }}折（{{
                        row.coupon_price.toString().split('.')[0]
                      }}%）
                    </span>
                  </template>
                  <template slot-scope="{ row }" slot="coupon_type">
                    <span v-if="row.coupon_type === 1">满减券</span>
                    <span v-else>折扣券</span>
                  </template>
                  <template slot-scope="{ row, index }" slot="status">
                    <a @click="delCoupon(index, indexw)">删除</a>
                  </template>
                </Table>
                <div
                  class="add-coupon"
                  @click="addCoupon(indexw)"
                  v-if="loginForm.register_coupon_status === 1"
                >
                  + 添加优惠券
                </div>
              </FormItem>
            </div>
          </Col>
          <!-- ----赠送优惠券------ -->
          <Col span="24" class="mt10" v-if="loginForm.newcomer_status === 1">
            <FormItem label="首单优惠：">
              <Switch
                size="large"
                v-model="loginForm.first_order_status"
                :true-value="1"
                :false-value="0"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </Switch>
              <div class="upload-text">
                新用户下单时可享折扣，折扣仅对商品打折，运费无折扣
              </div>
            </FormItem>
          </Col>
          <Col
            span="24"
            class="mt10"
            v-if="
              loginForm.newcomer_status === 1 &&
              loginForm.first_order_status === 1
            "
          >
            <FormItem label="折扣力度：">
              <Input
                v-model="loginForm.first_order_discount"
                placeholder="请输入折扣力度"
                class="inputw"
              >
              </Input>
              <span class="span-text">%</span>
              <div class="upload-text">折扣力度为：0-100%，1折为10%</div>
            </FormItem>
          </Col>
          <Col
            span="24"
            class="mt10"
            v-if="
              loginForm.newcomer_status === 1 &&
              loginForm.first_order_status === 1
            "
          >
            <FormItem label="折扣上限：">
              <Input
                v-model="loginForm.first_order_discount_limit"
                placeholder="请输入折扣上限"
                class="inputw"
              >
              </Input>
              <span class="span-text">元</span>
              <div class="upload-text">首单优惠最高金额，单位：元</div>
            </FormItem>
          </Col>
          <Col span="24" class="mt10" v-if="loginForm.newcomer_status === 1">
            <FormItem label="新人专享价：">
              <Switch
                size="large"
                v-model="loginForm.register_price_status"
                :true-value="1"
                :false-value="0"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </Switch>
              <div class="upload-text">
                新用户可购买一件新人商品，购买后移动端不再显示新人专区
              </div>

              <!-- 添加商品 -->
              <vxe-table
                border="inner"
                ref="xTree"
                :column-config="{ resizable: true }"
                row-id="id"
                :tree-config="{ children: 'attrValue', reserve: true }"
                :data="tableData"
                @checkbox-all="selectAll"
                @checkbox-change="selectAll"
                class="goods mt10"
                :header-cell-style="{
                  background: '#F7F7F7',
                  height: '40px',
                }"
                v-if="loginForm.register_price_status === 1"
              >
                <vxe-column
                  type="checkbox"
                  title="多选"
                  width="90"
                  tree-node
                ></vxe-column>
                <vxe-column field="id" title="ID" min-width="80"></vxe-column>
                <vxe-column field="info" title="商品信息" min-width="200">
                  <template v-slot="{ row }">
                    <div class="imgPic acea-row row-middle">
                      <viewer>
                        <div class="pictrue">
                          <img v-lazy="row.image" />
                        </div>
                      </viewer>
                      <div class="info">
                        <Tooltip max-width="200" placement="bottom" transfer>
                          <span class="line2"
                            >{{ row.store_name }}{{ row.suk }}</span
                          >
                          <p slot="content">
                            {{ row.store_name }}{{ row.suk }}
                          </p>
                        </Tooltip>
                      </div>
                    </div>
                  </template>
                </vxe-column>

                <vxe-column
                  field="price"
                  title="售价"
                  min-width="80"
                ></vxe-column>

                <vxe-column
                  field="stock"
                  title="库存"
                  min-width="80"
                ></vxe-column>

                <!-- 活动价 -->
                <vxe-column field="date" title="活动价" min-width="200">
                  <template v-slot="{ row }">
                    <Input
                      v-model="row.ativity_price"
                      :border="false"
                      placeholder="请输入活动价"
                      @on-change="inputChange(row)"
                    />
                  </template>
                </vxe-column>
                <!-- 活动价 -->
                <vxe-column
                  field="date"
                  title="操作"
                  min-width="50"
                  fixed="right"
                  align="center"
                >
                  <template v-slot="{ row }">
                    <a @click="del(row)">删除</a>
                  </template>
                </vxe-column>
              </vxe-table>
              <div
                class="add-goods"
                v-if="loginForm.register_price_status === 1"
              >
                <Button @click="addGoods">添加商品</Button>
                <Button @click="activityShowFn" class="goods-btn">
                  设置活动价
                </Button>
                <Button @click="delAll">批量删除</Button>
              </div>
              <!-- ----添加商品----- -->
            </FormItem>
          </Col>
          <Col span="24" class="mt10">
            <FormItem label="规则详情：" v-if="loginForm.newcomer_status === 1">
              <WangEditor
                class="goods"
                :content="loginForm.newcomer_agreement"
                @editorContent="getEditorContent"
              ></WangEditor>
            </FormItem>
          </Col>
          <Col>
            <FormItem>
              <div
                class="subBtn"
                style="margin-top: 0px"
                @click="handleSubmit('register')"
              >
                保存
              </div>
            </FormItem>
          </Col>
        </Row>
      </Form>
    </Card>
    <!-- 添加优惠券 -->
    <coupon-list
      ref="couponTemplates"
      @getCouponList="getCouponList"
      :discount="true"
    ></coupon-list>
    <!-- 添加商品 -->
    <Modal
      v-model="modals"
      title="商品列表"
      footerHide
      class="paymentFooter"
      scrollable
      width="900"
    >
      <goods-list
        ref="goodslist"
        :ischeckbox="true"
        :isdiy="true"
        :chooseType="7"
        @getProductId="getProductId"
        v-if="modals"
      ></goods-list>
    </Modal>
    <!-- 设置活动价 -->
    <Modal
      v-model="activityShow"
      title="设置"
      class="paymentFooter"
      width="600"
      :closable="false"
      :mask-closable="false"
      footer-hide
    >
      <Form
        :model="formActive"
        :rules="ruleActive"
        ref="activityShow"
        :label-width="100"
      >
        <FormItem label="设置活动价：" prop="activeInput">
          <InputNumber
            v-model="formActive.activeInput"
            placeholder="请输入活动价格"
            class="inputw"
            :min="0"
          >
          </InputNumber>
        </FormItem>
        <div class="acea-row row-right">
          <Button @click="cancel('activityShow')">取消</Button>
          <Button class="ml15 mr5" type="primary" @click="ok('activityShow')"
            >提交</Button
          >
        </div>
      </Form>
    </Modal>
  </div>
</template>

<script>
import couponList from '@/components/couponList';
import WangEditor from '@/components/wangEditor/index.vue';
import goodsList from '@/components/goodsList';
import { settingUser, setUser } from '@/api/user.js';

export default {
  components: {
    couponList,
    WangEditor,
    goodsList,
  },
  data() {
    const validatorActive = (rule, value, callback) => {
      if (value === '' || value == null || value < 0) {
        callback(new Error('活动价不能为空'));
      } else {
        callback();
      }
    };
    return {
      tabVal: 'register',
      // 登录注册表单
      loginForm: {
        store_user_agreement: 0, //自动登录
        newcomer_status: '1',
        store_user_mobile: '', // 手机号强制开启
        newcomer_limit_status: '', // 是否限时
        newcomer_limit_time: '', // 限时时间
        register_integral_status: '', // 赠送积分开启或者关闭 1开启0关闭
        register_give_integral: 1, // 赠送积分数量
        register_money_status: '', // 赠送余额开启
        register_give_money: '', // 赠送余额数量
        register_coupon_status: '', // 赠送优惠券开启
        register_give_coupon: [], // 赠送优惠券数量
        first_order_status: '', // 首单优惠开启
        first_order_discount: '', // 首单优惠折扣
        first_order_discount_limit: '', // 首单优惠折扣上限
        register_price_status: '', // 新人专享价开启
        product: [],
        newcomer_agreement: '',
        register_notice: '',
      },
      promotionsData: [
        {
          threshold: 0,
          give_integral: 0,
          checkIntegral: false,
          checkCoupon: false,
          checkGoods: false,
          giveProducts: [],
          giveCoupon: [],
        },
      ],
      indexCoupon: 0,
      modals: false, // 添加商品弹窗
      activityShow: false,
      formActive: {
        activeInput: 0,
      },
      ruleActive: {
        activeInput: [
          {
            required: true,
            validator: validatorActive,
            trigger: 'blur',
          },
        ],
      },
      selectArr: [],
      columns1: [
        {
          title: '优惠券名称',
          key: 'title',
          minWidth: 150,
        },
        {
          title: '类型',
          slot: 'coupon_type',
          minWidth: 80,
        },
        {
          title: '面值',
          slot: 'coupon_price',
          minWidth: 100,
        },
        {
          title: '最低消费额',
          key: 'use_min_price',
          minWidth: 100,
        },
        {
          title: '操作',
          slot: 'status',
          align: 'center',
          minWidth: 80,
        },
      ],
      tableData: [],
    };
  },
  created() {
    this.settingUser();
  },
  methods: {
    // 获取用户配置
    settingUser() {
      settingUser(this.tabVal).then((res) => {
        this.loginForm = res.data;
        this.promotionsData[0].giveCoupon = res.data.register_give_coupon;

        // 添加活动价格
        const addKey = (uni) =>
          uni.map((item) => ({
            ...item,
            ativity_price: item.price,
            id: item.product_id,
            attrValue: item.attrValue ? addKey(item.attrValue) : [],
          }));
        this.tableData = addKey(res.data.product);
      });
    },
    // 删除优惠券
    delCoupon(index, indexw) {
      this.promotionsData[indexw].giveCoupon.splice(index, 1);
    },
    // 添加优惠券
    addCoupon(index) {
      this.indexCoupon = index;
      this.$refs.couponTemplates.isTemplate = true;
      this.$refs.couponTemplates.tableList();
    },
    // 优惠卷表格
    getCouponList(data) {
      let indexCoupon = this.indexCoupon;
      this.$refs.couponTemplates.isTemplate = false;
      data.forEach((j) => {
        j.limit_num = 0;
        j.indexCoupon = indexCoupon;
      });
      let list = this.promotionsData[indexCoupon].giveCoupon.concat(data);
      let uni = this.unique(list);
      this.promotionsData[indexCoupon].giveCoupon = uni;
    },
    // 对象数组去重
    unique(arr) {
      const res = new Map();
      return arr.filter((arr) => !res.has(arr.id) && res.set(arr.id, 1));
    },
    //全选
    selectAll(row) {
      this.selectArr = row.records;
    },
    // 设置活动价格
    inputChange(row) {
      if (row.attrValue.length > 0) {
        row.attrValue.forEach((item) => {
          item.ativity_price = row.ativity_price;
        });
      }
    },
    // 删除商品
    del(row) {
      this.tableData.forEach((i, index) => {
        if (row.id == i.id) {
          return this.tableData.splice(index, 1);
        } else {
          i.attrValue.forEach((j, indexn) => {
            if (row.id == j.id) {
              if (i.attrValue.length == 1) {
                return this.tableData.splice(index, 1);
              } else {
                return i.attrValue.splice(indexn, 1);
              }
            }
          });
        }
      });
    },
    // 添加商品
    addGoods(index) {
      this.modals = true;
    },
    // 批量设置活动价
    activityShowFn() {
      if (this.selectArr.length === 0) {
        this.$Message.error('请先选择设置活动价的商品！');
      } else {
        this.activityShow = true;
      }
    },
    // 批量删除商品
    delAll() {
      if (this.selectArr.length === 0) {
        this.$Message.error('请先选择删除的商品！');
      } else {
        this.$Modal.confirm({
          title: '删除确认',
          content: '您确认要删除这些商品？',
          onOk: () => {
            this.selectArr.forEach((row) => {
              this.tableData.forEach((i, index) => {
                if (row.id == i.id) {
                  this.tableData.splice(index, 1);
                } else {
                  i.attrValue.forEach((j, indexn) => {
                    if (row.id == j.id) {
                      if (i.attrValue.length == 1) {
                        this.tableData.splice(index, 1);
                      } else {
                        i.attrValue.splice(indexn, 1);
                      }
                    }
                  });
                }
              });
            });
          },
        });
      }
    },
    // 规则详情
    getEditorContent(data) {
      this.newcomer_agreement = data;
    },
    // 表单提交
    handleSubmit(val) {
      this.product_list = [];
      this.tableData.forEach((item) => {
        let obj = {
          product_id: item.id,
          price: item.ativity_price,
          attr: [],
        };

        if (item.attrValue.length) {
          item.attrValue.forEach((j) => {
            let newAttr = { unique: j.unique, price: j.ativity_price };
            obj.attr.push(newAttr);
          });
        }
        this.product_list.push(obj);
      });
      let ids = this.promotionsData[0].giveCoupon.map((item) => item.id);
      this.loginForm.register_give_coupon = Array.from(new Set(ids));
      this.loginForm.product = this.product_list;
      this.loginForm.newcomer_agreement = this.newcomer_agreement;
      setUser(val, this.loginForm)
        .then((res) => {
          this.$Message.success(res.msg);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 添加商品
    getProductId(data) {
      this.modals = false;
      let list = this.tableData.concat(data);
      let uni = this.unique(list);
      uni.forEach((i) => {
        i.attrValue.forEach((j) => {
          j.cate_name = i.cate_name;
          j.store_label = i.store_label;
        });
      });

      // 添加活动价格
      const addKey = (uni) =>
        uni.map((item) => ({
          ...item,
          attrValue: item.attrValue ? addKey(item.attrValue) : [], // 这里要判断原数据有没有子级如果没有判断会报错
        }));
      this.tableData = addKey(uni);
    },
    cancel(name) {
      this.activityShow = false;
      this.$refs[name].resetFields();
    },
    ok(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          this.selectArr.forEach((item) => {
            item.ativity_price = this.formActive.activeInput;
          });
          this.activityShow = false;
          this.$refs[name].resetFields();
        }
      });
    },
  },
};
</script>

<style lang="less" scoped>
.inputw {
  width: 460px;
}
.span-text {
  margin-left: 8px;
  font-size: 12px;
}
.add-goods {
  margin-top: 20px;
  display: flex;
  .goods-btn {
    margin: 0 20px;
  }
  .paging {
    margin-left: 170px;
  }
}
.goods /deep/.ivu-table-cell {
  line-height: 21px !important;
}
.goods {
  .icondrag {
    color: #ccc;
  }
}
.goods {
  width: 780px !important;
}
.imgPic {
  .info {
    width: 60%;
    margin-left: 10px;
  }

  .pictrue {
    width: 36px;
    height: 36px;
    margin: 7px 3px 0 3px;

    img {
      width: 100%;
      height: 100%;
      display: block;
    }
  }
}
.subBtn {
  width: 54px;
  height: 32px;
  background: #2d8cf0;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
  color: #ffffff;
  text-align: center;
  line-height: 32px;
  cursor: pointer;
}
.add-coupon {
  font-size: 12px;
  font-weight: 400;
  color: #1890ff;
  cursor: pointer;
}
/deep/ .vxe-table--render-default .vxe-table--header {
  font-size: 12px;
}
</style>