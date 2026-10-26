<template>
  <div>
    <FormItem label="已售数量：">
      <InputNumber
        v-width="'50%'"
        :min="0"
        :max="999999"
        v-model="formValidate.ficti"
        placeholder="请输入已售数量"
      />
    </FormItem>
    <FormItem label="排序：">
      <InputNumber
        :min="0"
        :max="999999"
        v-width="'50%'"
        v-model="formValidate.sort"
        placeholder="请输入排序"
      />
    </FormItem>
    <FormItem label="赠送积分：" prop="give_integral">
      <InputNumber
        v-width="'50%'"
        v-model="formValidate.give_integral"
        :min="0"
        :max="999999"
        placeholder="请输入积分"
      />
    </FormItem>
    <FormItem label="赠送配置：">
      <Button
        type="primary"
        :disabled="!productId"
        @click="openGiftConfig"
      >赠送配置</Button>
      <div v-if="!productId" class="fs-12 text--w111-999 mt10">
        请先保存商品后再配置赠送内容
      </div>
      <div v-else-if="giftConfigSummary" class="fs-12 text--w111-999 mt10">
        已配置：品项 {{ giftConfigSummary.product }} 项，优惠券 {{ giftConfigSummary.coupon }} 项
      </div>
    </FormItem>
    <FormItem label="赠送优惠券：" v-if="false">
      <div v-if="formValidate.couponName.length" class="mb20">
        <Tag
          type="border"
          closable
          v-for="(item, index) in formValidate.couponName"
          :key="index"
          @on-close="handleClose(item)"
          >{{ item.title }}</Tag
        >
      </div>
      <Button type="primary" @click="addCoupon">添加优惠券</Button>
    </FormItem>
    <FormItem label="关联用户标签：" class="labelClass">
      <div class="labelInput acea-row row-between-wrapper" @click="openLabel">
        <div style="width: 90%">
          <div v-if="formValidate.label_id.length">
            <Tag
              closable
              v-for="(item, index) in formValidate.label_id"
              :key="index"
              @on-close="closeLabel(item)"
              >{{ item.label_name }}</Tag
            >
          </div>
          <span class="span" v-else>选择用户关联标签</span>
        </div>
        <div class="iconfont iconxiayi"></div>
      </div>
      <div class="fs-12 text--w111-999 mt10">
        用户购买该商品后，自动为用户关联所选标签
      </div>
    </FormItem>
    <FormItem label="服务保障：">
      <CheckboxGroup v-model="formValidate.ensure_id" class="checkAlls">
        <Checkbox
          :label="item.id"
          v-for="(item, index) in ensureData"
          :key="index"
          >{{ item.name }}</Checkbox
        >
      </CheckboxGroup>
      <div class="tips">
        <Poptip
          placement="bottom"
          trigger="hover"
          width="256"
          transfer
          padding="8px"
        >
          <a>查看示例</a>
          <div class="exampleImg" slot="content">
            <img :src="`${baseURL}/statics/system/productEnsure.png`" alt="" />
          </div>
        </Poptip>
      </div>
    </FormItem>
    <FormItem label="仅会员可见：">
      <i-switch
        v-model="formValidate.is_vip_product"
        :true-value="1"
        :false-value="0"
        size="large"
      >
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </i-switch>
      <div class="fs-12 text--w111-999">
        开启后仅付费会员可以看见并购买此商品
      </div>
    </FormItem>
    <FormItem label="是否限购：">
      <i-switch
        v-model="formValidate.is_limit"
        :true-value="1"
        :false-value="0"
        size="large"
      >
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </i-switch>
    </FormItem>
    <FormItem label="限购类型：" v-if="formValidate.is_limit">
      <RadioGroup v-model="formValidate.limit_type">
        <Radio :label="1">单次限购</Radio>
        <Radio :label="2">单人限购</Radio>
      </RadioGroup>
      <div class="tips">
        单次限购是限制每次下单最多购买的数量，长期限购是限制一个用户总共可以购买的数量
      </div>
    </FormItem>
    <FormItem label="限购数量：" v-if="formValidate.is_limit">
      <InputNumber
        :min="1"
        v-model="formValidate.limit_num"
        placeholder="请输入限购数量"
        class="perW20 maxW"
      />
    </FormItem>
    <FormItem label="预售商品：" v-if="baseInfo.product_type == 0">
      <i-switch
        v-model="formValidate.is_presale_product"
        :true-value="1"
        :false-value="0"
        size="large"
      >
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </i-switch>
    </FormItem>
    <div
      v-if="baseInfo.product_type == 0 && formValidate.is_presale_product"
    >
      <FormItem label="预售活动时间：" prop="presale_time">
        <div class="acea-row row-middle">
          <DatePicker
            :editable="false"
            :options="datePickerOptions"
            type="datetimerange"
            format="yyyy-MM-dd HH:mm"
            placeholder="请选择活动时间"
            @on-change="onchangeTime"
            :value="formValidate.presale_time"
            v-model="formValidate.presale_time"
            v-width="'50%'"
          ></DatePicker>
        </div>
        <div class="tips">
          设置活动开启结束时间，用户可以在设置时间内发起参与预售
        </div>
      </FormItem>
      <FormItem label="预计发货时间：">
        <div class="acea-row row-middle">
          <span class="mr10">预售活动结束后</span>
          <InputNumber
            placeholder="请输入发货时间"
            :precision="0"
            :min="1"
            style="width: 100px"
            v-model="formValidate.presale_day"
          />
          <span class="ml10">天之内</span>
        </div>
      </FormItem>
      <FormItem label="活动结束后状态：">
        <RadioGroup v-model="formValidate.presale_status">
          <Radio :label="1">上架</Radio>
          <Radio :label="0">下架</Radio>
        </RadioGroup>
        <div class="tips">
          选择上架时，预售活动结束后该商品作为产品继续售卖
        </div>
      </FormItem>
    </div>
    <FormItem label="优品推荐：">
      <i-switch
        v-model="formValidate.is_good"
        :true-value="1"
        :false-value="0"
        size="large"
      >
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </i-switch>
    </FormItem>
    <FormItem label="选择优品推荐商品：">
      <div class="acea-row">
        <div
          class="pictrue"
          v-for="(item, index) in formValidate.recommend_list"
          :key="index"
        >
          <img v-lazy="item.image" />
          <Button
            shape="circle"
            icon="md-close"
            @click.native="bindDelete(index)"
            class="btndel"
          ></Button>
        </div>
        <div
          v-if="formValidate.recommend_list.length < 12"
          class="upLoad acea-row row-center-wrapper"
          @click="goodsTap"
        >
          <Icon type="ios-camera-outline" size="26" />
        </div>
      </div>
    </FormItem>
    <coupon-list
      ref="couponTemplates"
      @nameId="nameId"
      :couponids="formValidate.coupon_ids"
      :updateIds="formValidate.coupon_ids"
      :updateName="formValidate.couponName"
    ></coupon-list>
    <!-- 用户标签 -->
    <Modal
      v-model="labelShow"
      scrollable
      title="选择用户标签"
      :closable="true"
      width="540"
      :footer-hide="true"
      :mask-closable="false"
    >
      <userLabel
        ref="userLabel"
        @activeData="activeData"
        @close="labelClose"
      ></userLabel>
    </Modal>
    <!-- 商品列表 -->
    <Modal
      v-model="goodsModals"
      title="商品列表"
      footerHide
      scrollable
      width="900"
      @on-cancel="goodCancel"
    >
      <goods-list
        v-if="goodsModals"
        ref="goodslist"
        @getProductId="getProductId"
        :ischeckbox="true"
      ></goods-list>
    </Modal>
    <recharge-gift-config
      :visible="giftConfigVisible"
      :product-id="productId"
      @close="giftConfigVisible = false"
      @saved="onGiftConfigSaved"
    />
  </div>
</template>
<script>
import Setting from '@/setting';
import couponList from '@/components/couponList';
import userLabel from '@/components/labelList';
import goodsList from '@/components/goodsList/index';
import rechargeGiftConfig from '@/components/rechargeGiftConfig';
import { productAllEnsure, getProductGiftConfigApi } from '@/api/product';
import EventBus from '@/utils/bus';
export default {
  name: 'marketingSet',
  props: {
    baseInfo: {
      type: Object,
      default: () => ({}),
    },
    successData: {
      type: Boolean,
      default: false,
    },
    productId: {
      type: [Number, String],
      default: 0,
    },
  },
  data() {
    return {
      baseURL: Setting.apiBaseURL.replace(/adminapi/, ''),
      formValidate: {
        ficti: 0, //已售数量
        sort: 0, // 排序
        give_integral: 0, //赠送积分
        couponName: [], //赠送优惠券
        coupon_ids: [],
        store_label_id: [], //关联用户标签
        is_presale_product: 0, //预售商品开关
        is_limit: 0, //是否限购开关
        limit_type: 1, //1单次限购，2长期限购
        limit_num: 1, //限购数量
        presale_time: [],
        presale_day: 1, //预售发货时间-结束
        is_good: 0,
        is_vip_product: 0,
        label_id: [],
        ensure_id: [],
        unit_name: '',
        presale_status: 1,
        recommend_list: [],
      },
      ensureData: [],
      labelShow: false,
      goodsModals: false,
      giftConfigVisible: false,
      giftConfigSummary: null,
      datePickerOptions: {
        disabledDate(date) {
          return date && date.valueOf() < Date.now() - 86400000;
        },
      },
    };
  },
  components: { couponList, userLabel, goodsList, rechargeGiftConfig },
  watch: {
    successData: {
      handler(val) {
        if (val) {
          let keys = Object.keys(this.formValidate);
          keys.map((i) => {
            this.formValidate[i] = this.baseInfo[i];
          });
          this.loadGiftConfigSummary();
        }
      },
      immediate: true,
      deep: true,
    },
    productId(val) {
      if (val) {
        this.loadGiftConfigSummary();
      }
    },
  },
  created() {
    this.getProductAllEnsure();
    EventBus.$on('unitChanged', (value) => {
      this.formValidate.unit_name = value;
    });
  },
  methods: {
    openGiftConfig() {
      if (!this.productId) {
        this.$Message.warning('请先保存商品后再配置赠送内容');
        return;
      }
      this.giftConfigVisible = true;
    },
    onGiftConfigSaved() {
      this.loadGiftConfigSummary();
    },
    loadGiftConfigSummary() {
      if (!this.productId) {
        this.giftConfigSummary = null;
        return;
      }
      getProductGiftConfigApi(this.productId).then(res => {
        const data = res.data || {};
        const productCount = (data.product || []).length;
        const couponCount = (data.coupon || []).length;
        this.giftConfigSummary = productCount || couponCount
          ? { product: productCount, coupon: couponCount }
          : null;
      }).catch(() => {});
    },
    // 添加优惠券
    addCoupon() {
      this.$refs.couponTemplates.isTemplate = true;
      this.$refs.couponTemplates.tableList();
    },
    //对象数组去重；
    unique(arr) {
      const res = new Map();
      return arr.filter((arr) => !res.has(arr.id) && res.set(arr.id, 1));
    },
    nameId(id, names) {
      this.formValidate.coupon_ids = id;
      this.formValidate.couponName = this.unique(names);
    },
    handleClose(name) {
      let index = this.formValidate.couponName.indexOf(name);
      this.formValidate.couponName.splice(index, 1);
      this.formValidate.coupon_ids.splice(index, 1);
    },
    openLabel(row) {
      this.labelShow = true;
      this.$refs.userLabel.userLabel(
        JSON.parse(JSON.stringify(this.formValidate.label_id))
      );
    },
    activeData(dataLabel) {
      this.labelShow = false;
      this.formValidate.label_id = dataLabel;
    },
    // 标签弹窗关闭
    labelClose() {
      this.labelShow = false;
    },
    // 预售具体日期
    onchangeTime(e) {
      this.formValidate.presale_time = e;
    },
    goodCancel() {
      this.goodsModals = false;
    },
    goodsTap() {
      this.goodsModals = true;
      this.$refs.goodslist.handleSelectAll();
    },
    bindDelete(index) {
      this.formValidate.recommend_list.splice(index, 1);
    },
    getProductId(e) {
      this.goodsModals = false;
      let nArr = this.formValidate.recommend_list
        .concat(e)
        .filter((element, index, self) => {
          return (
            self.findIndex((x) => x.product_id == element.product_id) == index
          );
        });
      this.formValidate.recommend_list = nArr.slice(0, 12);
    },
    getProductAllEnsure() {
      productAllEnsure()
        .then((res) => {
          this.ensureData = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
  },
};
</script>
<style lang="less" scoped>
.labelInput {
  border: 1px solid #dcdee2;
  width: 50%;
  padding: 0 5px;
  border-radius: 5px;
  min-height: 30px;
  cursor: pointer;
  .span {
    color: #c5c8ce;
  }
  .iconxiayi {
    font-size: 12px;
  }
}
.labelClass {
  /deep/.ivu-form-item-content {
    line-height: unset;
  }
}
.pictrue {
  width: 60px;
  height: 60px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  margin-right: 15px;
  margin-bottom: 10px;
  display: inline-block;
  position: relative;
  cursor: pointer;
  img {
    width: 100%;
    height: 100%;
  }
  .btndel {
    position: absolute;
    z-index: 1;
    width: 20px !important;
    height: 20px !important;
    left: 46px;
    top: -4px;
  }
}
.upLoad {
  width: 58px;
  height: 58px;
  line-height: 58px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  border-radius: 4px;
  background: rgba(0, 0, 0, 0.02);
  cursor: pointer;
}
</style>
