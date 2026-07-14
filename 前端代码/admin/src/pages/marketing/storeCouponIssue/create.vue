<template>
  <div class="form-submit">
    <div class="i-layout-page-header">
      <PageHeader class="product_tabs" hidden-breadcrumb>
        <div slot="title">
          <router-link
            :to="{ path: `${roterPre}/marketing/store_coupon_issue/index` }"
          >
            <div class="font-sm after-line">
              <span class="iconfont iconfanhui"></span>
              <span class="pl10">返回</span>
            </div>
          </router-link>
          <span
            v-text="type == 1 ? '优惠券详情' : '添加优惠券'"
            class="mr20 ml16"
          ></span>
        </div>
      </PageHeader>
    </div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Form :model="formData" :label-width="150">
        <Tabs v-model="currentTab">
          <TabPane label="基础设置" name="1">
            <FormItem label="优惠类型：" props="coupon_type">
              <div
                class="productType"
                :class="formData.coupon_type == item.id ? 'on' : ''"
                v-for="(item, index) in couponType"
                :key="index"
                @click="couponTypeTap(item)"
              >
                <div
                  class="name"
                  :class="formData.coupon_type == item.id ? 'on' : ''"
                >
                  {{ item.name }}
                </div>
                <div class="title">({{ item.title }})</div>
                <div v-if="formData.coupon_type == item.id" class="jiao"></div>
                <div
                  v-if="formData.coupon_type == item.id"
                  class="iconfont iconduihao"
                ></div>
              </div>
            </FormItem>
            <FormItem label="优惠券名称：" required>
              <Input
                v-model="formData.coupon_title"
                v-width="320"
                placeholder="请输入优惠券名称"
                :disabled="[1].includes(type)"
              ></Input>
            </FormItem>
            <FormItem label="优惠券面值：" required>
              <InputNumber
                v-if="formData.coupon_type == 1"
                :min="1"
                :max="100000000"
                v-model="formData.coupon_price"
                v-width="320"
                :disabled="[1].includes(type)"
              ></InputNumber>
              <InputNumber
                v-else
                :min="0"
                :max="100"
                :precision="0"
                v-model="formData.coupon_price"
                v-width="320"
                :disabled="[1].includes(type)"
              ></InputNumber>
              <span class="ml10">{{
                formData.coupon_type == 1 ? '元' : '%'
              }}</span>
            </FormItem>
            <FormItem label="最高优惠：" v-if="formData.coupon_type == 2">
              <InputNumber
                :min="1"
                :max="100000000"
                v-model="topDiscountPrice"
                v-width="320"
                :disabled="[1].includes(type)"
              ></InputNumber>
              <span class="ml10">元</span>
            </FormItem>
            <FormItem label="优惠券种类：">
              <RadioGroup
                v-model="formData.category"
                @on-change="categoryChange"
              >
                <Radio :label="1" :disabled="[1].includes(type)">普通券</Radio>
                <Radio :label="2" :disabled="[1].includes(type)">会员券</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem label="领取方式：">
              <RadioGroup v-model="formData.receive_type" @on-change="onReceiveTypeChange">
                <Radio :label="1" :disabled="[1].includes(type)"
                  >手动领取</Radio
                >
                <Radio
                  v-if="formData.category == 1"
                  :label="3"
                  :disabled="[1].includes(type)"
                  >后台发放</Radio
                >
              </RadioGroup>
              <div class="info">
                手动领取：用户需要在移动端的领券中心领取优惠券；后台发放：后台发放用于后台发放指定用户使用，移动端不能领取；
              </div>
            </FormItem>
            <FormItem label="适用类型：">
              <RadioGroup v-model="formData.type">
                <Radio :label="0" :disabled="[1].includes(type)">通用券</Radio>
                <Radio :label="1" :disabled="[1].includes(type)">品类券</Radio>
                <Radio :label="2" :disabled="[1].includes(type)">商品券</Radio>
                <Radio :label="3" :disabled="[1].includes(type)">品牌券</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-show="formData.type === 2">
              <template>
                <div
                  class="upload-list"
                  v-for="item in productList"
                  :key="item.product_id"
                >
                  <img :src="item.image" />
                  <Icon
                    type="ios-close-circle"
                    size="16"
                    v-if="[0, 2].includes(type)"
                    @click="remove(item.product_id)"
                  />
                </div>
              </template>
              <Icon
                v-if="[0, 2].includes(type)"
                type="ios-camera-outline"
                size="26"
                @click="modals = true"
              />
              <div v-if="[0, 2].includes(type)" class="info">选择商品</div>
            </FormItem>
            <FormItem v-show="formData.type === 1">
              <Cascader
                :data="categoryList"
                placeholder="请选择商品分类"
                change-on-select
                v-model="formData.category_id"
                filterable
                style="width: 320px"
                :disabled="[1].includes(type)"
              ></Cascader>
              <div v-if="[0, 2].includes(type)" class="info">
                选择商品的品类
              </div>
            </FormItem>
            <FormItem v-show="formData.type === 3">
              <Cascader
                :data="brandList"
                placeholder="请选择商品品牌"
                change-on-select
                v-model="formData.brand_id"
                filterable
                style="width: 320px"
                :disabled="[1].includes(type)"
              ></Cascader>
              <div v-if="[0, 2].includes(type)" class="info">
                选择商品的品牌
              </div>
            </FormItem>
            <FormItem label="使用门槛：">
              <RadioGroup v-model="isMinPrice">
                <Radio :label="0" :disabled="[1].includes(type)">无门槛</Radio>
                <Radio :label="1" :disabled="[1].includes(type)">有门槛</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-show="isMinPrice">
              <InputNumber
                :min="1"
                :max="100000000"
                v-model="formData.use_min_price"
                :disabled="[1].includes(type)"
              ></InputNumber>
              <div class="info">填写优惠券的最低消费金额</div>
            </FormItem>
            <FormItem label="使用时间：">
              <RadioGroup v-model="isCouponTime">
                <Radio :label="1" :disabled="[1].includes(type)">天数</Radio>
                <Radio :label="0" :disabled="[1].includes(type)">时间段</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-show="isCouponTime" label="">
              <InputNumber
                :min="1"
                v-model="formData.coupon_time"
                :precision="0"
                :disabled="[1].includes(type)"
              ></InputNumber>
              <div class="info">领取后多少天内有效</div>
            </FormItem>
            <FormItem v-show="!isCouponTime" label="">
              <DatePicker
                :transfer="true"
                :value="datetime1"
                :editable="false"
                :disabled="[1].includes(type)"
                type="datetimerange"
                placeholder="领取后在这个时间段内可以使用"
                @on-change="dateChange"
              ></DatePicker>
            </FormItem>
            <FormItem v-if="formData.receive_type == 1" label="领取时间：">
              <RadioGroup v-model="isReceiveTime">
                <Radio :label="1" :disabled="[1].includes(type)">限时</Radio>
                <Radio :label="0" :disabled="[1].includes(type)">不限时</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-show="isReceiveTime" label="">
              <DatePicker
                :value="datetime2"
                :disabled="[1].includes(type)"
                type="datetimerange"
                placeholder="在这个时间段内可领取"
                @on-change="timeChange"
              ></DatePicker>
            </FormItem>
            <FormItem v-if="formData.receive_type == 1" label="发放数量：">
              <RadioGroup v-model="formData.is_permanent">
                <Radio :label="0" :disabled="[1].includes(type)">限量</Radio>
                <Radio :label="1" :disabled="[1].includes(type)">不限量</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-show="!formData.is_permanent" label="">
              <InputNumber
                :min="1"
                :max="100000000"
                v-model="formData.total_count"
                :precision="0"
                :disabled="[1].includes(type)"
              ></InputNumber>
              <div class="info">填写优惠券的发布数量</div>
            </FormItem>
            <FormItem label="领取数量：" v-if="formData.receive_type == 1">
              <RadioGroup v-model="formData.is_claimed">
                <Radio :label="0" :disabled="[1].includes(type)">限量</Radio>
                <Radio :label="1" :disabled="[1].includes(type)">不限量</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-show="formData.receive_type == 1 && !formData.is_claimed" label="">
              <InputNumber
                :min="1"
                :max="100000000"
                v-model="formData.quantity_count"
                :precision="0"
                :disabled="[1].includes(type)"
              ></InputNumber>
              <div class="info">每个用户可领取优惠券的最大数量</div>
            </FormItem>
            <FormItem label="使用规则：">
              <Input
                v-model="formData.rule"
                v-width="320"
                :rows="4"
                type="textarea"
                placeholder="请输入使用规则"
                :disabled="[1].includes(type)"
              ></Input>
            </FormItem>
            <FormItem label="是否开启：">
              <i-switch
                v-model="formData.status"
                :true-value="1"
                :false-value="0"
                size="large"
                :disabled="[1].includes(type)"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
            </FormItem>
          </TabPane>
          <TabPane label="适用门店" name="2" v-if="!couponIssueType">
            <Row :gutter="24" type="flex" class="mb20">
              <Col span="24">
                <RadioGroup v-model="formData.applicable_type">
                  <Radio :label="1" :disabled="[1].includes(type)"
                    >全部门店</Radio
                  >
                  <Radio :label="2" :disabled="[1].includes(type)"
                    >部分门店</Radio
                  >
                  <Radio :label="0" :disabled="[1].includes(type)"
                    >仅平台适用</Radio
                  >
                </RadioGroup>
                <div class="tips">
                  全部/部分门店：选择优惠券在哪些门店使用（门店自建商品不可使用该优惠券）；仅平台适用：购买门店商品（平台同步商品+门店自建商品）均不享受该优惠。
                </div>
              </Col>
              <Col span="24" class="mt20" v-if="formData.applicable_type == 2">
                <Button
                  :disabled="[1].includes(type)"
                  type="primary"
                  @click="addStore"
                  >添加门店</Button
                >
              </Col>
            </Row>
            <div class="vxeTable" v-if="formData.applicable_type == 2">
              <vxe-table
                border="inner"
                ref="xTree"
                row-id="id"
                :data="storesList"
              >
                <vxe-column field="id" title="ID" min-width="60"></vxe-column>
                <vxe-column
                  field="info"
                  title="门店图片"
                  tree-node
                  min-width="80"
                >
                  <template v-slot="{ row }">
                    <div class="imgPic acea-row row-middle">
                      <viewer>
                        <div class="pictrue"><img v-lazy="row.image" /></div>
                      </viewer>
                    </div>
                  </template>
                </vxe-column>
                <vxe-column
                  field="cate_name"
                  title="门店分类"
                  min-width="110"
                ></vxe-column>
                <vxe-column
                  field="name"
                  title="门店名称"
                  min-width="110"
                ></vxe-column>
                <vxe-column
                  field="phone"
                  title="联系电话"
                  min-width="110"
                ></vxe-column>
                <vxe-column
                  field="address"
                  title="门店地址"
                  min-width="150"
                ></vxe-column>
                <vxe-column
                  field="day_time"
                  title="营业时间"
                  min-width="140"
                ></vxe-column>
                <vxe-column
                  field="status_name"
                  title="营业状态"
                  min-width="80"
                ></vxe-column>
                <vxe-column
                  field="date"
                  title="操作"
                  min-width="100"
                  fixed="right"
                  align="center"
                >
                  <template v-slot="{ row }">
                    <a :disabled="[1].includes(type)" @click="delte(row)"
                      >删除</a
                    >
                  </template>
                </vxe-column>
              </vxe-table>
            </div>
          </TabPane>
        </Tabs>
      </Form>
    </Card>
    <Card
      :bordered="false"
      dis-hover
      class="fixed-card"
      :style="{ left: `${!menuCollapse ? '236px' : isMobile ? '0' : '60px'}` }"
      v-if="type != 1"
    >
      <Form>
        <FormItem>
          <Button
            v-if="currentTab !== '1'"
            @click="upTab"
            style="margin-right: 10px"
            >上一步</Button
          >
          <Button
            type="primary"
            class="submission"
            v-if="currentTab !== '2'"
            @click="downTab"
            >下一步</Button
          >
          <Button
            v-else
            type="primary"
            class="submission"
            @click="save"
            :disabled="disabled"
            >立即创建</Button
          >
        </FormItem>
      </Form>
    </Card>
    <Modal
      v-model="modals"
      title="商品列表"
      footerHide
      class="paymentFooter"
      scrollable
      width="900"
      @on-cancel="cancel"
    >
      <goods-list
        ref="goodslist"
        v-if="modals"
        :ischeckbox="true"
        @getProductId="getProductId"
      ></goods-list>
    </Modal>
    <Modal
      v-model="storeModals"
      title="门店列表"
      footerHide
      scrollable
      width="900"
      @on-cancel="cancelStore"
    >
      <store-list
        ref="storelist"
        @getStoreId="getStoreId"
        v-if="storeModals"
      ></store-list>
    </Modal>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import storeList from '@/components/storeList';
import goodsList from '@/components/goodsList/index';
import {
  couponCategoryApi,
  couponSaveApi,
  couponDetailApi,
} from '@/api/marketing';
import { brandList } from '@/api/product';
import Setting from '@/setting';
export default {
  name: 'storeCouponCreate',
  components: {
    goodsList,
    storeList,
  },
  data() {
    return {
      roterPre: Setting.roterPre,
      couponType: [
        { name: '满减券', title: '满N元减N元', id: 1 },
        { name: '折扣券', title: '满N元打N折', id: 2 },
      ],
      disabled: false,
      storesList: [],
      formData: {
        coupon_title: '',
        coupon_price: 0,
        top_discount_price: 0,
        type: 0,
        use_min_price: 0,
        coupon_time: 1,
        start_use_time: 0,
        end_use_time: 0,
        start_time: 0,
        end_time: 0,
        receive_type: 1,
        is_permanent: 1,
        total_count: 1,
        is_claimed: 0,
        quantity_count: 1,
        sort: 0,
        status: 1,
        product_id: '',
        category_id: [],
        brand_id: [],
        coupon_type: 1,
        applicable_type: 1,
        applicable_store_id: [],
        rule: '',
        category: 1,
      },
      categoryList: [],
      brandList: [],
      productList: [],
      isMinPrice: 0,
      isCouponTime: 1,
      isReceiveTime: 0,
      modals: false,
      datetime1: [],
      datetime2: [],
      storeModals: false,
      currentTab: '1',
      topDiscountPrice: null,
      id: 0,
      type: 0, // 0：添加 1：详情 2：复制
      couponIssueType: 0, // 优惠券来源
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile', 'menuCollapse']),
  },
  created() {
    const params = this.$route.params;
    this.id = Number(params.id) || 0;
    this.type = Number(params.type) || 0;
    this.getBrandList();
    this.getCategoryList();
    if (this.id) {
      this.getCouponDetail();
    }
  },
  methods: {
    //删除门店
    delte(row) {
      if ([1].includes(this.type)) {
        return;
      }
      this.storesList.forEach((item, index) => {
        if (row.id == item.id) {
          this.storesList.splice(index, 1);
        }
      });
    },
    //添加门店
    addStore() {
      this.storeModals = true;
    },
    //关闭门店弹窗
    cancelStore() {
      this.storeModals = false;
    },
    uniqueId(arr) {
      const res = new Map();
      return arr.filter((arr) => !res.has(arr.id) && res.set(arr.id, 1));
    },
    getStoreId(data) {
      this.storeModals = false;
      let list = this.storesList.concat(data);
      let uni = this.uniqueId(list);
      this.storesList = uni;
    },
    couponTypeTap(item) {
      if ([1].includes(this.type)) {
        return;
      }
      this.formData.coupon_price = 0;
      this.formData.coupon_type = item.id;
    },
    // 品类
    getCategoryList() {
      couponCategoryApi(0).then(async (res) => {
        res.data.forEach((val) => {
          val.cate_name = `${val.html}${val.cate_name}`;
        });
        this.categoryList = res.data;
      });
    },
    //品牌
    getBrandList() {
      brandList().then((res) => {
        this.brandList = res.data;
      });
    },
    // 优惠券
    getCouponDetail() {
      couponDetailApi(this.id)
        .then((res) => {
          let data = res.data;
          this.formData.coupon_title = data.coupon_title;
          this.formData.coupon_type = data.coupon_type;
          this.formData.type = data.type;
          this.formData.category_id = Array.isArray(data.category_id)
            ? data.category_id
            : [];
          this.formData.brand_id = Array.isArray(data.brand_id)
            ? data.brand_id
            : [];
          this.formData.category = data.category;
          this.formData.rule = data.rule;
          this.formData.coupon_price = parseFloat(data.coupon_price);
          this.formData.use_min_price = parseFloat(data.use_min_price);
          this.formData.coupon_time = data.coupon_time;
          this.formData.receive_type = data.receive_type;
          this.formData.is_permanent = data.is_permanent;
          this.formData.status = data.status;
          this.formData.product_id = data.product_id;
          this.formData.start_time = data.start_time;
          this.formData.end_time = data.end_time;
          this.formData.total_count = data.total_count;
          this.formData.sort = data.sort;
          this.topDiscountPrice = Number(data.top_discount_price) || null;
          this.formData.top_discount_price = this.topDiscountPrice || 0;
          this.formData.is_claimed = data.is_claimed;
          this.formData.quantity_count = data.quantity_count;
          this.formData.applicable_type = data.applicable_type;
          this.couponIssueType = data.coupon_issue_type;
          this.storesList = data.stores || [];
          if ('productInfo' in data) {
            this.productList = data.productInfo;
          }
          if (!data.coupon_time) {
            this.isCouponTime = 0;
            this.datetime1 = [
              data.start_use_time * 1000,
              data.end_use_time * 1000,
            ];
            this.formData.start_use_time = this.makeDate(
              data.start_use_time * 1000
            );
            this.formData.end_use_time = this.makeDate(
              data.end_use_time * 1000
            );
          }
          if (data.start_time) {
            this.isReceiveTime = 1;
            this.datetime2 = [data.start_time * 1000, data.end_time * 1000];
            this.formData.start_time = this.makeDate(data.start_time * 1000);
            this.formData.end_time = this.makeDate(data.end_time * 1000);
          }
          if (data.use_min_price !== '0.00') {
            this.isMinPrice = 1;
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    makeDate(data) {
      let date = new Date(data);
      let YY = date.getFullYear() + '-';
      let MM =
        (date.getMonth() + 1 < 10
          ? '0' + (date.getMonth() + 1)
          : date.getMonth() + 1) + '-';
      let DD = date.getDate() < 10 ? '0' + date.getDate() : date.getDate();
      let hh =
        (date.getHours() < 10 ? '0' + date.getHours() : date.getHours()) + ':';
      let mm =
        (date.getMinutes() < 10 ? '0' + date.getMinutes() : date.getMinutes()) +
        ':';
      let ss =
        date.getSeconds() < 10 ? '0' + date.getSeconds() : date.getSeconds();
      return YY + MM + DD + ' ' + hh + mm + ss;
    },
    // 上一页：
    upTab() {
      if (this.currentTab == '2') {
        this.currentTab = (Number(this.currentTab) - 1).toString();
      }
    },
    downTab() {
      let valid = this.validate();
      if (typeof valid === 'boolean' && valid) {
        this.currentTab = '2';
      }
    },
    // 创建
    save() {
      let valid = this.validate();
      if (typeof valid !== 'boolean' || !valid) {
        return;
      }
      this.disabled = false;
      let storeId = [];
      this.storesList.forEach((item) => {
        storeId.push(item.id);
      });
      if (this.formData.applicable_type == 2 && !storeId.length) {
        return this.$Message.warning('请添加适用门店');
      }
      this.formData.applicable_store_id = storeId;
      // 最高优惠
      if (this.formData.coupon_type == 2 && this.topDiscountPrice !== null) {
        this.formData.top_discount_price = this.topDiscountPrice;
      } else {
        this.formData.top_discount_price = 0;
      }
      // 用户领取数量
      if (this.formData.receive_type != 1) {
        this.formData.is_claimed = 0;
        this.formData.quantity_count = 1;
      }
      couponSaveApi(0, this.formData)
        .then((res) => {
          this.disabled = true;
          this.$Message.success(res.msg);
          setTimeout(() => {
            this.$router.push({
              path: this.roterPre + '/marketing/store_coupon_issue/index',
            });
          }, 1000);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 使用有效期--时间段
    dateChange(time) {
      this.formData.start_use_time = time[0];
      this.formData.end_use_time = time[1];
    },
    // 限时
    timeChange(time) {
      this.formData.start_time = time[0];
      this.formData.end_time = time[1];
    },
    //对象数组去重；
    unique(arr) {
      const res = new Map();
      return arr.filter(
        (arr) => !res.has(arr.product_id) && res.set(arr.product_id, 1)
      );
    },
    // 选择的商品
    getProductId(productList) {
      this.modals = false;
      this.productList = this.unique(this.productList.concat(productList));
      this.formData.product_id = '';
      this.productList.forEach((value) => {
        if (this.formData.product_id) {
          this.formData.product_id += `,${value.product_id}`;
        } else {
          this.formData.product_id += `${value.product_id}`;
        }
      });
    },
    cancel() {
      this.modals = false;
    },
    // 删除商品
    remove(productId) {
      for (let index = 0; index < this.productList.length; index++) {
        if (this.productList[index].product_id == productId) {
          this.productList.splice(index, 1);
        }
      }
      this.formData.product_id = '';
      this.productList.forEach((value) => {
        if (this.formData.product_id) {
          this.formData.product_id += `,${value.product_id}`;
        } else {
          this.formData.product_id += `${value.product_id}`;
        }
      });
    },
    // 优惠券种类改变
    categoryChange(value) {
      if (value == 2) {
        this.formData.receive_type = 1;
      }
    },
    // 表单验证
    validate() {
      if (!this.formData.coupon_title) {
        return this.$Message.error('请输入优惠券名称');
      }
      if (this.formData.type === 2) {
        if (!this.formData.product_id) {
          return this.$Message.error('请选择商品');
        }
      }
      if (this.formData.type === 1) {
        if (!this.formData.category_id.length) {
          return this.$Message.error('请选择品类');
        }
      }
      if (this.formData.type === 3) {
        if (!this.formData.brand_id.length) {
          return this.$Message.error('请选择品牌');
        }
      }
      if (this.formData.coupon_price <= 0) {
        return this.$Message.error('优惠券面值不能小于0');
      }
      if (!this.isMinPrice) {
        this.formData.use_min_price = 0;
      } else {
        if (this.formData.use_min_price < 1) {
          return this.$Message.error('优惠券最低消费不能小于0');
        }
      }
      if (this.isCouponTime) {
        this.formData.start_use_time = 0;
        this.formData.end_use_time = 0;
        if (this.formData.coupon_time < 1) {
          return this.$Message.error('使用有效期限不能小于1天');
        }
      } else {
        this.formData.coupon_time = 0;
        if (!this.formData.start_use_time) {
          return this.$Message.error('请选择使用有效期限');
        }
      }
      if (this.isReceiveTime) {
        if (!this.formData.start_time) {
          return this.$Message.error('请选择领取时间');
        }
      } else {
        this.formData.start_time = 0;
        this.formData.end_time = 0;
      }
      if (this.formData.is_permanent) {
        this.formData.total_count = 0;
      } else {
        if (this.formData.total_count < 1) {
          return this.$Message.error('发布数量不能小于1');
        }
      }
      return true;
    },
    // 领取方式改变
    onReceiveTypeChange() {
      this.isReceiveTime = 0;
      this.formData.start_time = '';
      this.formData.end_time = '';
      this.formData.is_permanent = 1;
      this.formData.total_count = 1;
    }
  },
};
</script>

<style scoped lang="stylus">
.tips {
  display: inline-bolck;
  font-size: 12px;
  font-weight: 400;
  color: #999999;
  margin-top: 10px;
}

.imgPic {
  .info {
    width: 60%;
    margin-left: 10px;
  }

  .pictrue {
    height: 36px;
    margin: 7px 3px 0 3px;

    img {
      height: 100%;
      display: block;
    }
  }
}

.productType {
  width: 120px;
  height: 60px;
  background: #FFFFFF;
  border-radius: 3px;
  border: 1px solid #E7E7E7;
  float: left;
  text-align: center;
  padding-top: 8px;
  position: relative;
  cursor: pointer;
  line-height: 23px;
  margin-right: 12px;

  &.on {
    border-color: #1890FF;
  }

  .name {
    font-size: 14px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.85);

    &.on {
      color: #1890FF;
    }
  }

  .title {
    font-size: 12px;
    font-weight: 400;
    color: #999999;
  }

  .jiao {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 0;
    height: 0;
    border-bottom: 26px solid #1890FF;
    border-left: 26px solid transparent;
  }

  .iconfont {
    position: absolute;
    bottom: -3px;
    right: 1px;
    color: #FFFFFF;
    font-size: 12px;
  }
}

.info {
  color: #888;
  font-size: 12px;
}

.ivu-input-wrapper {
  width: 320px;
}

.ivu-input-number {
  width: 160px;
}

.ivu-date-picker {
  width: 320px;
}

.ivu-icon-ios-camera-outline {
  width: 58px;
  height: 58px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  border-radius: 4px;
  background-color: rgba(0, 0, 0, 0.02);
  line-height: 58px;
  cursor: pointer;
  vertical-align: middle;
}

.upload-list {
  width: 58px;
  height: 58px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  border-radius: 4px;
  margin-right: 15px;
  display: inline-block;
  position: relative;
  cursor: pointer;
  vertical-align: middle;
}

.upload-list img {
  display: block;
  width: 100%;
  height: 100%;
}

.ivu-icon-ios-close-circle {
  position: absolute;
  top: 0;
  right: 0;
  transform: translate(50%, -50%);
}

.form-submit {
  /deep/.ivu-card {
    border-radius: 0;
  }

  margin-bottom: 79px;

  .fixed-card {
    position: fixed;
    right: 0;
    bottom: 0;
    left: 200px;
    z-index: 99;
    box-shadow: 0 -1px 2px rgb(240, 240, 240);

    /deep/ .ivu-card-body {
      padding: 15px 16px 14px;
    }

    .ivu-form-item {
      margin-bottom: 0;
    }

    /deep/ .ivu-form-item-content {
      margin-right: 124px;
      text-align: center;
    }

    .ivu-btn {
      height: 36px;
      padding: 0 20px;
    }
  }
}

/deep/.vxe-tree-cell {
  padding-left: 0 !important;
}
</style>
