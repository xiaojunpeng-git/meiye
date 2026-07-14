<template>
  <div>
    <FormItem label="商品关键字：">
      <Input
        v-model="formValidate.keyword"
        placeholder="请输入商品关键字"
        v-width="'50%'"
      />
      <div class="tips">通过命中关键字搜索对应商品，方便用户查找</div>
    </FormItem>
    <FormItem label="商品简介：" prop="">
      <Input
        v-model="formValidate.store_info"
        type="textarea"
        :rows="3"
        placeholder="请输入商品简介"
        v-width="'50%'"
		maxlength="200"
		show-word-limit
      />
      <div class="tips">
        微信分享商品时，分享的卡片上会显示商品简介
        <Poptip
          placement="bottom"
          trigger="hover"
          width="256"
          transfer
          padding="8px"
        >
          <a>查看示例</a>
          <div class="exampleImg" slot="content">
            <img :src="`${baseURL}/statics/system/productDesc.png`" alt="" />
          </div>
        </Poptip>
      </div>
    </FormItem>
    <FormItem label="商品口令：">
      <Input
        v-model="formValidate.command_word"
        type="textarea"
        :rows="3"
        placeholder="请输入商品口令"
        v-width="'50%'"
      />
      <div class="tips">
        可将淘宝、抖音等平台的口令复制在此处，用户点击商城商品详情后会自动复制口令，随后打开淘宝等平台会自动弹出口令弹窗
        <Poptip
          placement="bottom"
          trigger="hover"
          width="256"
          transfer
          padding="8px"
        >
          <a>查看示例</a>
          <div class="exampleImg" slot="content">
            <div
              style="
                margin-bottom: 10px;
                font-size: 12px;
                line-height: 18px;
                color: #666666;
              "
            >
              商品口令需在淘宝、天猫、京东、苏<br />宁、1688上有同款商品，复制口令后<br />可在相关应用中打开(下图为淘宝展示)
            </div>
            <img
              :src="`${baseURL}/statics/system/productCommandWord.png`"
              alt=""
            />
          </div>
        </Poptip>
      </div>
    </FormItem>
    <FormItem label="商品推荐图：">
      <div class="acea-row">
        <div v-if="formValidate.recommend_image" class="pictrue">
          <img v-lazy="formValidate.recommend_image" />
          <Button
            shape="circle"
            icon="md-close"
            @click.native="formValidate.recommend_image = ''"
            class="btndel"
          ></Button>
        </div>
        <div
          v-else
          class="upLoad acea-row row-center-wrapper"
          @click="modalPicTap()"
        >
          <Icon type="ios-camera-outline" size="26" />
        </div>
      </div>
      <div class="tips">
        在特殊的商品分类样式中显示(建议图片比例5:2)
        <Poptip
          placement="bottom"
          trigger="hover"
          width="256"
          transfer
          padding="8px"
        >
          <a>查看示例</a>
          <div class="exampleImg" slot="content">
            <img
              :src="`${baseURL}/statics/system/productRecommendImage.png`"
              alt=""
            />
          </div>
        </Poptip>
      </div>
    </FormItem>
    <FormItem label="商品参数：" prop="">
      <Select
        v-model="formValidate.specs_id"
        clearable
        filterable
        v-width="'50%'"
        placeholder="请输入商品参数"
        @on-change="specsInfo"
      >
        <Option
          v-for="(item, index) in specsData"
          :value="item.id"
          :key="index"
          >{{ item.name }}</Option
        >
      </Select>
    </FormItem>
    <FormItem>
      <Table
        border
        :columns="specsColumns"
        :data="formValidate.specs"
        ref="table"
        class="specsList"
        width="700"
      >
        <template slot-scope="{ row, index }" slot="action">
          <a @click="delSpecs(index)">删除</a>
        </template>
      </Table>
      <Button class="mt20" @click="addSpecs">添加参数</Button>
    </FormItem>
    <FormItem label="支持退款：" v-if="formValidate.product_type">
      <i-switch
        v-model="formValidate.is_support_refund"
        :true-value="1"
        :false-value="0"
        size="large"
      >
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </i-switch>
    </FormItem>
    <FormItem label="自定义留言：">
      <i-switch v-model="customBtn" @on-change="customMessBtn" size="large">
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </i-switch>
	  <div class="tips">请先与店铺 > 系统表单中维护表单模板</div>
      <div class="mt10" v-if="customBtn">
        <Select
          v-model="formValidate.system_form_id"
          filterable
          v-width="'50%'"
          placeholder="请选择"
        >
          <Option
            v-for="(item, index) in formList"
            :value="item.id"
            :key="index"
            >{{ item.name }}</Option
          >
        </Select>
      </div>
    </FormItem>
	<FormItem label="表单信息：" v-if="customBtn">
	  <RadioGroup v-model="formValidate.system_form_type">
	    <Radio :label="1">按照商品填写</Radio>
	    <Radio :label="2">按照订单填写</Radio>
	  </RadioGroup>
	  <div class="tips">按照商品：一个订单买2个商品，需填写2份表单；按照订单：一个订单买两个商品，需填写1份表单</div>
	</FormItem>
  </div>
</template>
<script>
import Setting from '@/setting';
import { productAllSpecs, allSystemForm } from '@/api/product';
export default {
  name: 'otherSet',
  props: {
    baseInfo: {
      type: Object,
      default: () => ({}),
    },
    successData: {
      type: Boolean,
      default: false,
    },
  },
  data() {
    return {
      baseURL: Setting.apiBaseURL.replace(/adminapi/, ''),
      formValidate: {
        keyword: '',
        store_info: '',
        command_word: '',
        recommend_image: '',
        specs_id: '',
        is_support_refund: 0,
        system_form_id: '',
        specs: [],
        product_type: '',
		system_form_type:1
      },
      specsData: [],
      customBtn: false,
      specsColumns: [
        {
          title: '参数名称',
          key: 'name',
          align: 'center',
          width: 150,
          render: (h, params) => {
            return h('div', [
              h('Input', {
                props: {
                  value: params.row.name,
                  placeholder: '请输入参数名称',
                },
                on: {
                  'on-change': (e) => {
                    params.row.name = e.target.value;
                    this.formValidate.specs[params.index].name = e.target.value;
                  },
                },
              }),
            ]);
          },
        },
        {
          title: '参数值',
          key: 'value',
          align: 'center',
          width: 300,
          render: (h, params) => {
            return h('div', [
              h('Input', {
                props: {
                  value: params.row.value,
                  placeholder: '请输入参数值',
                },
                on: {
                  'on-change': (e) => {
                    params.row.value = e.target.value;
                    this.formValidate.specs[params.index].value =
                      e.target.value;
                  },
                },
              }),
            ]);
          },
        },
        {
          title: '排序',
          key: 'sort',
          align: 'center',
          width: 100,
          render: (h, params) => {
            return h('div', [
              h('InputNumber', {
                props: {
                  value: parseInt(params.row.sort) || 0,
                  placeholder: '排序',
                  precision: 0,
                },
                on: {
                  'on-change': (e) => {
                    params.row.sort = e;
                    this.formValidate.specs[params.index].sort = e;
                  },
                },
              }),
            ]);
          },
        },
        {
          title: '操作',
          slot: 'action',
          align: 'center',
          minWidth: 120,
        },
      ],
    };
  },
  watch: {
    successData: {
      handler(val) {
        if (val) {
          let keys = Object.keys(this.formValidate);
          keys.map((i) => {
            this.formValidate[i] = this.baseInfo[i];
          });
          this.customBtn = !!this.formValidate.system_form_id;
        }
      },
      immediate: true,
      deep: true,
    },
    'baseInfo.recommend_image'(val) {
      this.formValidate.recommend_image = val;
    },
    'baseInfo.product_type'(val) {
      this.formValidate.product_type = val;
    },
  },
  created() {
    this.getProductAllSpecs();
    this.allFormList();
  },
  methods: {
    specsInfo(e) {
      const result = this.specsData.find(item => item.id == e);
      this.formValidate.specs = result ? result.specs : [];
    },
    delSpecs(index) {
      this.formValidate.specs.splice(index, 1);
      if (!this.formValidate.specs.length) {
        this.formValidate.specs_id = '';
      }
    },
    addSpecs() {
      let obj = { name: '', value: '', sort: 0 };
      this.formValidate.specs.push(obj);
    },
    customMessBtn(e) {
      if (!e) {
        this.formValidate.system_form_id = 0;
      }
    },
    getProductAllSpecs() {
      productAllSpecs()
        .then((res) => {
          this.specsData = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    modalPicTap() {
      this.$emit('modalPicTap', 'dan', 'recommend_image');
    },
    allFormList() {
      allSystemForm()
        .then((res) => {
          this.formList = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
  },
};
</script>
<style lang="less" scoped>
.tips {
  display: inline-bolck;
  font-size: 12px;
  font-weight: 400;
  color: #999999;
  margin-top: 6px;
}
.checkAlls /deep/.ivu-checkbox-inner {
  width: 14px;
  height: 14px;
}
.checkAlls /deep/.ivu-checkbox-wrapper {
  font-size: 12px;
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
</style>
