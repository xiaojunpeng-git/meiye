<template>
  <div>
    <Form
      ref="formValidate"
      :model="formValidate"
      :rules="ruleValidate"
      :label-width="100"
    >
      <FormItem label="奖品" prop="type">
        <RadioGroup v-model="formValidate.type">
		  <Radio :label="1">未中奖</Radio>
          <Radio :label="5">优惠券</Radio>
          <Radio :label="2">积分</Radio>
          <Radio :label="6">商品</Radio>
          <Radio :label="4">红包</Radio>
          <Radio :label="3">余额</Radio>
        </RadioGroup>
      </FormItem>
      <FormItem label="赠送优惠券：" required v-show="formValidate.type == 5">
        <div v-if="couponName.length" class="mb20">
          <Tag
            closable
            v-for="(item, index) in couponName"
            :key="index"
            @on-close="handleClose(item)"
            >{{ item.title }}</Tag
          >
        </div>
        <Button type="primary" @click="addCoupon" v-if="!couponName.length">添加优惠券</Button>
      </FormItem>
      <FormItem
        :label="[3, 4].includes(formValidate.type) ? '金额信息' : '积分数量'"
        prop="num"
        v-if="[2, 3, 4].includes(formValidate.type)"
      >
        <InputNumber
				  v-if="[3, 4].includes(formValidate.type)"
          v-model="formValidate.num"
          placeholder="请输入金额"
          :max="formValidate.type == 4 ? 999 : 99999"
          :min="formValidate.type == 4 ? 0 : 1"
          class="width30"
        ></InputNumber>
				<InputNumber
				  v-else
				  v-model="formValidate.num"
				  placeholder="请输入积分数量"
				  :max="formValidate.type == 4 ? 999 : 99999"
				  :min="1"
					:precision="0"
				  class="width30"
				></InputNumber>
        <div class="ml100 grey">
          {{
            formValidate.type == 3
              ? "用户领取余额后会自动到账余额账户"
              : formValidate.type == 4
              ? "用户领取红包后会自动到账微信零钱，添加此奖品需开通微信支付,并且账户中金额不能小于0.01元"
              : ""
          }}
        </div>
      </FormItem>
      <FormItem v-if="formValidate.type == 6" label="商品" prop="goods_image">
        <template v-if="formValidate.goods_image">
          <div class="upload-list">
            <img :src="formValidate.goods_image" />
            <Icon type="ios-close-circle" size="16" @click="removeGoods()" />
          </div>
        </template>
		<div v-else class="upLoad pictrueTab acea-row row-center-wrapper">
			<Icon
			  type="ios-camera-outline"
			  size="26"
			  @click="modals = true"
			/>
		</div>
      </FormItem>
      <FormItem v-if="formValidate.type == 6 && formValidate.goods_image" label="商品规格" required>
        <template v-if="formValidate.unique">
          <div class="upload-list">
            <img :src="attrImage" />
            <Icon type="ios-close-circle" size="16" @click="removeAttr" />
          </div>
        </template>
        <div v-else class="upLoad pictrueTab acea-row row-center-wrapper">
          <Icon
            type="ios-camera-outline"
            size="26"
            @click="callAttr"
          />
        </div>
      </FormItem>
      <FormItem label="奖品名称" prop="name">
        <Input
          v-model="formValidate.name"
          :maxlength="10"
          placeholder="请输入奖品名称"
         class="width30"
        ></Input>
      </FormItem>
      <FormItem label="奖品图片" prop="image">
        <template v-if="formValidate.image">
          <div class="upload-list">
            <img :src="formValidate.image" />
            <Icon type="ios-close-circle" size="16" @click="remove()" />
          </div>
        </template>
        <div v-else class="upLoad pictrueTab acea-row row-center-wrapper">
          <Icon type="ios-camera-outline" size="26" @click="modalPic = true" />
        </div>
      </FormItem>
      <FormItem label="奖品数量" prop="total">
        <InputNumber
          v-model="formValidate.total"
          placeholder="请输入奖品数量"
          :max="99999"
          :min="0"
          :precision="0"
          class="width30"
        ></InputNumber>
      </FormItem>
      <FormItem label="中奖概率(%)" prop="chance">
        <InputNumber
          v-model="formValidate.chance"
          placeholder="请输入中奖概率"
          :max="100"
          :min="0"
          :precision="0"
        class="width30"
        ></InputNumber>
      </FormItem>
      <FormItem label="提示语" prop="prompt">
        <Input
          v-model="formValidate.prompt"
          :maxlength="15"
          placeholder="请输入提示语"
         class="width30"
        ></Input>
      </FormItem>
      <FormItem>
        <Button type="primary" @click="handleSubmit('formValidate')"
          >提交</Button
        >
      </FormItem>
    </Form>
    <!-- 上传图片-->
    <Modal
      v-model="modalPic"
      width="960px"
      scrollable
      footer-hide
      closable
      title="上传图片"
      :mask-closable="false"
      :z-index="110"
    >
      <uploadPictures
        :isChoice="isChoice"
        @getPic="getPic"
        v-if="modalPic"
      ></uploadPictures>
    </Modal>
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
        :chooseType="8"
        @getProductId="getProductId"
      ></goods-list>
    </Modal>
    <coupon-list
       ref="couponTemplates"
       :luckDraw="true"
       @getCouponId="getCouponId"
    ></coupon-list>
    <Modal v-model="attrModal" title="选择商品规格" width="960" scrollable footer-hide>
      <Table :columns="attrColumns" :data="attrData" height="500">
        <template slot-scope="{ row, index }" slot="image">
          <div class="product-data">
            <img class="image" :src="row.image" />
          </div>
        </template>
      </Table>
    </Modal>
  </div>
</template>

<script>
import couponList from "@/components/couponList";
import uploadPictures from "@/components/uploadPictures";
import goodsList from "@/components/goodsList/index";
import freightTemplate from "@/components/freightTemplate";
import { changeListApi } from "@/api/product";
export default {
  components: { uploadPictures, goodsList, freightTemplate, couponList },
  data() {
    return {
      modalPic: false,
      modals: false,
      isChoice: "单选",
      updateIds: [],
      updateName: [],
      goodsData: {
        pic: "",
        product_id: "",
        img: "",
        coverImg: "",
      },
      formValidate: {
        type: 5, //类型 1：未中奖2：积分  3:余额  4：红包 5:优惠券 6：站内商品
        name: "", //活动名称
        num: 1, //金额信息、积分数量
        image: "", //奖品图片
        chance: 0, //中奖权重
        product_id: 0, //商品id
        coupon_id: 0, //优惠券id
        total: 0, //奖品数量
        prompt: "", //提示语
        goods_image: "", //自用商品图
        unique: "", //商品规格
        coupon_title:'' //优惠券名称
      },
      ruleValidate: {
        name: [
          {
            required: true,
            message: "奖品名称",
            trigger: "blur",
          },
        ],
        goods_image: [
          {
            required: true,
            message: "请添加商品",
            trigger: "blur",
          },
        ],
        num: [
          {
            required: true,
            type: "number",
            message: "请输入金额数量",
            trigger: "blur",
          },
        ],
        chance: [
          {
            required: true,
            type: "number",
            message: "请输入奖品权重",
            trigger: "blur",
          },
        ],
        image: [
          {
            required: true,
            message: "请选择奖品图片",
            trigger: "blur",
          },
        ],
        prompt: [
          {
            required: true,
            message: "请输入提示语",
            trigger: "blur",
          },
        ],
      },
      couponName: [],
      attrColumns: [
        {
          width: 60,
          align: 'center',
          render: (h, { row }) => {
            return h('Radio', {
              props: {
                value: row.unique === this.formValidate.unique
              },
              on: {
                'on-change': () => {
                  this.attrModal = false;
                  this.attrImage = row.image;
                  this.formValidate.unique = row.unique;
                }
              }
            });
          }
        },
        {
          title: "图片",
          slot: "image",
          width: 120,
          align: "center",
        },
        {
          title: "规格",
          key: "suk",
          align: "center",
          minWidth: 120,
        },
      ],
      attrData: [],
      attrModal: false,
      attrImage: "", //自用商品规格图
    };
  },
  props: {
    editData: {
      type: Object,
      default: () => {},
    },
  },
  watch: {
    editData(data) {
    },
  },
  mounted() {
    let keys = Object.keys(this.editData);
    keys.forEach((item) => {
      this.formValidate[item] = this.editData[item];
      if( item === 'coupon_title' && this.editData[item]){
        this.couponName.push({title:this.editData[item],id:this.editData.coupon_id})
      }
    });
  },
  methods: {
    getCouponId(e){
      this.formValidate.coupon_id = e.id;
      this.formValidate.coupon_title = e.coupon_title;
      let couponName = []
      couponName.push(e)
      this.couponName = couponName
    },
    handleSubmit(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
		  if(this.formValidate.type == 5 && !this.couponName.length){
			return this.$Message.error("请添加优惠券");
		  }
		  if(this.formValidate.type == 4 && this.formValidate.num<0.01){
			return this.$Message.error("金额信息不能小于0.01");
		  }
          this.$emit("addGoodsData", this.formValidate);
          this.$Message.success("添加成功");
        } else {
          this.$Message.warning("请完善数据");
        }
      });
    },
    // 获取单张图片信息
    getPic(pc) {
      this.formValidate.image = pc.att_dir;
      this.modalPic = false;
    },
    // 点击商品图
    modalPicTap() {
      this.modalPic = true;
    },
    cancel() {
      this.modals = false;
    },
    // 选择的商品
    getProductId(productList) {
      this.formValidate.product_id = productList.id;
      this.formValidate.goods_image = productList.image;
      this.modals = false;
      this.attrData = productList.attrValue;
    },
    removeGoods() {
      this.formValidate.product_id = "";
      this.formValidate.goods_image = "";
      this.removeAttr();
    },
    remove() {
      this.formValidate.image = "";
    },
    // 添加优惠券
    addCoupon() {
      this.$refs.couponTemplates.isTemplate = true;
      this.$refs.couponTemplates.tableList();
    },
    handleClose(name) {
      this.couponName.splice(0, 1);
      this.formValidate.coupon_id = 0;
    },
    //对象数组去重；
    unique(arr) {
      const res = new Map();
      return arr.filter((arr) => !res.has(arr.id) && res.set(arr.id, 1));
    },
    removeAttr() {
      this.attrImage = '';
      this.formValidate.unique = '';
    },
    callAttr() {
      this.attrModal = true;
    },
    getList() {
      changeListApi().then(({ data }) => {
        const { list } = data;
        for (let i = 0; i < list.length; i++) {
          if (list[i].id === this.formValidate.product_id) {
            this.attrData = list[i].attrValue;
            for (let j = 0; j < this.attrData.length; j++) {
              if (this.attrData[j].unique === this.formValidate.unique) {
                this.attrImage = this.attrData[j].image;
                break;
              }
            }
            break;
          }
        }
      });
    }
  },
};
</script>

<style scoped lang="stylus">
.pictrueBox {
  display: inline-block;
}

.pictrue {
  width: 60px;
  height: 60px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  margin-right: 15px;
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

.upload-list {
  width: 58px;
  height: 58px;
  line-height: 58px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  border-radius: 4px;
  background: rgba(0, 0, 0, 0.02);
  cursor: pointer;
  position: relative;
}

.upload-list img {
  display: block;
  width: 100%;
  height: 100%;
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

.ivu-icon-ios-close-circle {
  position: absolute;
  top: 0;
  right: 0;
  transform: translate(50%, -50%);
}

.grey {
  color: #999;
}

.product-data {
  display: flex;
  align-items: center;

  .image {
    width: 50px !important;
    height: 50px !important;
    margin-right: 10px;
  }
}
</style>
