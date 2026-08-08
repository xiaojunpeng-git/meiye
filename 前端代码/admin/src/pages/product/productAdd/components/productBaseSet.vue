<template>
  <div>
    <!-- 商品信息-->
    <FormItem label="商品名称：" prop="store_name" :rules="ruleValidate.store_name">
      <Input
        v-model="formValidate.store_name"
        placeholder="请输入商品名称"
        v-width="'50%'"
      />
    </FormItem>
    <FormItem label="商品分类：" prop="cate_id" :rules="ruleValidate.cate_id">
      <el-cascader
        placeholder="请选择商品分类"
        v-width="'50%'"
        size="mini"
        v-model="formValidate.cate_id"
        :options="treeSelect"
        :props="props"
        filterable
        clearable
      >
      </el-cascader>
      <span class="addClass" @click="addClass">新增分类</span>
    </FormItem>
    <FormItem label="商品品牌：" prop="">
      <div class="flex">
        <Cascader
          :data="brandData"
          placeholder="请选择商品品牌"
          change-on-select
          v-model="formValidate.brand_id"
          filterable
          v-width="'50%'"
        ></Cascader>
        <span class="addClass" @click="addBrand">新增品牌</span>
      </div>
    </FormItem>
    <FormItem v-if="![5, 6].includes(Number(baseInfo.product_type))" label="销售/包装单位：" prop="unit_name" :rules="ruleValidate.unit_name">
      <Select
        v-model="formValidate.unit_name"
        clearable
        filterable
        v-width="'50%'"
        placeholder="请输入销售/包装单位"
        @on-change="unitChange"
      >
        <Option
          v-for="(item, index) in unitNameList"
          :value="item.name"
          :key="index"
          >{{ item.name }}</Option
        >
      </Select>
      <span class="addClass" @click="addUnit">新增单位</span>
      <div class="tips">对外销售或包装计量单位，如盒、瓶；库存按基本单位记账时与换算数配合使用</div>
    </FormItem>
    <FormItem label="商品编码：" prop="" v-if="baseInfo.product_type !=6 && baseInfo.product_type !=5">
      <Input
        v-model="formValidate.code"
        placeholder="请输入商品编码"
        v-width="'50%'"
      />
    </FormItem>
    <FormItem label="商品轮播图：" prop="slider_image" :rules="ruleValidate.slider_image">
      <div class="acea-row">
        <div
          class="pictrue"
          v-for="(item, index) in baseInfo.slider_image"
          :key="index"
          draggable="true"
          @dragstart="handleDragStart($event, item)"
          @dragover.prevent="handleDragOver($event, item)"
          @dragenter="handleDragEnter($event, item)"
          @dragend="handleDragEnd($event, item)"
        >
          <img v-lazy="item" />
          <Button
            shape="circle"
            icon="md-close"
            @click.native="handleRemove(index)"
            class="btndel"
          ></Button>
        </div>
        <div
          v-if="baseInfo.slider_image.length < 10"
          class="upLoad acea-row row-center-wrapper"
          @click="modalPicTap('duo')"
        >
          <Icon type="ios-camera-outline" size="26" />
        </div>
        <Input
          v-model="baseInfo.slider_image[0]"
          class="input-display"
        ></Input>
      </div>
      <div class="tips">
        建议尺寸：800 *
        800px，可拖拽改变图片顺序，默认首张图为主图，最多上传10张
      </div>
    </FormItem>
    <FormItem label="商品标签：" class="labelClass">
      <div class="acea-row row-middle">
        <div
          class="labelInput acea-row row-between-wrapper"
          @click="openStoreLabel"
        >
          <div style="width: 90%">
            <div v-if="formValidate.store_label_id.length">
              <Tag
                closable
                v-for="(item, index) in formValidate.store_label_id"
                :key="index"
                @on-close="closeStoreLabel(item)"
                >{{ item.label_name }}</Tag
              >
            </div>
            <span class="span" v-else>选择商品标签</span>
          </div>
          <div class="iconfont iconxiayi"></div>
        </div>
        <span class="addClass" @click="addStoreLabel">新增标签</span>
      </div>
    </FormItem>
    <FormItem label="添加视频：">
      <i-switch v-model="formValidate.video_open" size="large">
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </i-switch>
    </FormItem>
    <FormItem
      label="上传视频："
      prop="video_link"
      v-if="formValidate.video_open"
    >
      <div class="flex">
        <Input v-model="formValidate.video_link" v-width="'50%'" />
        <span class="addClass" @click="addVideo">选择视频</span>
      </div>
      <div class="iview-video-style" v-if="formValidate.video_link">
        <video
          class="video-style"
          :src="formValidate.video_link"
          controls="controls"
        ></video>
        <div class="mark"></div>
        <Icon type="ios-trash-outline" class="iconv" @click="delVideo" />
      </div>
    </FormItem>
    <FormItem label="上架时间：">
      <RadioGroup v-model="formValidate.is_show" @on-change="goodsOn">
        <Radio :label="1">
          <Icon type="social-apple"></Icon>
          <span>立即上架</span>
        </Radio>
        <Radio :label="2">
          <Icon type="social-android"></Icon>
          <span>定时上架</span>
        </Radio>
        <Radio :label="0">
          <Icon type="social-windows"></Icon>
          <span>放入仓库</span>
        </Radio>
      </RadioGroup>
    </FormItem>
    <FormItem label="" v-if="formValidate.is_show == 2">
      <DatePicker
        type="datetime"
        @on-change="onchangeShow"
        :options="startPickOptions"
        :value="formValidate.auto_on_time"
        v-model="formValidate.auto_on_time"
        placeholder="请选择上架时间"
        format="yyyy-MM-dd HH:mm"
        style="width: 250px"
      ></DatePicker>
    </FormItem>
    <FormItem label="定时下架：">
      <Switch
        v-model="formValidate.off_show"
        :true-value="1"
        :false-value="0"
        size="large"
        @on-change="goodsOff"
      >
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </Switch>
    </FormItem>
    <FormItem label="" v-if="formValidate.off_show == 1" prop="auto_off_time" :rules="ruleValidate.auto_off_time">
      <DatePicker
        type="datetime"
        @on-change="onchangeOff"
        :options="endPickOptions"
        :value="formValidate.auto_off_time"
        v-model="formValidate.auto_off_time"
        placeholder="请选择下架时间"
        format="yyyy-MM-dd HH:mm"
        style="width: 260px"
      ></DatePicker>
      <div class="tips">
        开启定时下架后，系统会在设置时间下架该商品。下架时间需晚于开售时间，商品才能定时开售。
      </div>
    </FormItem>
    <!-- 商品标签 -->
    <Modal
      v-model="storeLabelShow"
      scrollable
      title="选择商品标签"
      :closable="true"
      width="540"
      :footer-hide="true"
      :mask-closable="false"
    >
      <storeLabelList
        ref="storeLabel"
        @activeData="activeStoreData"
        @close="storeLabelClose"
      ></storeLabelList>
    </Modal>
    <menus-from
      :formValidate="formBrand"
      :fromName="1"
      ref="menusFrom"
    ></menus-from>
  </div>
</template>
<script>
import {
  cascaderListApi,
  productCreateApi,
  brandList,
  productAllUnit,
  productUnitCreate,
  productLabelAdd,
} from '@/api/product';
import {
  getSupplierList,
} from '@/api/supplier';
import storeLabelList from '@/components/storeLabelList';
import menusFrom from '../../productBrand/components/menusFrom.vue';
import vuedraggable from 'vuedraggable';
import EventBus from '@/utils/bus';
export default {
  name: 'productBaseSet',
  props: {
    baseInfo: {
      type: Object,
      default: () => ({}),
    },
    successData: {
      type: Boolean,
      default: false,
    },
    currentTab: {
      type: String,
      default: '',
    },
  },
  data() {
    return {
      formValidate: {
        id: 0,
        brand_id: [],
        code: '',
        store_name: '',
        cate_id: [],
        store_label_id: [],
        unit_name: '',
        video_link: '',
        video_open: false,
        is_show: 0,
        auto_on_time: '',
        auto_off_time: '',
        off_show: 0,
        supplier_id: 0,
      },
      props: { emitPath: false, multiple: true, checkStrictly: true },
      //商品分类树形数据
      treeSelect: [],
      // 品牌数据
      brandData: [],
      // 单位数据
      unitNameList: [],
      storeLabelShow: false,
      formBrand: {},
      ruleValidate: {
        store_name: [
          {
            validator: (rule, value, callback) => {
              if (this.currentTab == '1' && this.formValidate.store_name === '') {
                callback(new Error('请输入商品名称'));
              } else {
                callback();
              }
            },
            required: true,
            trigger: 'blur'
          },
        ],
        cate_id: [
          {
            validator: (rule, value, callback) => {
              if (this.currentTab != '1' || (Array.isArray(this.formValidate.cate_id) && this.formValidate.cate_id.length)) {
                callback();
              } else {
                callback(new Error('请选择商品分类'));
              }
            },
            required: true,
            type: 'array',
          },
        ],
        unit_name: [
          {
            validator: (rule, value, callback) => {
              if (this.currentTab == '1' && !this.formValidate.unit_name) {
                callback(new Error('请输入销售/包装单位'));
              } else {
                callback();
              }
            },
            required: true,
            trigger: 'change',
          },
        ],
        slider_image: [
          {
            required: true,
            validator: (rule, value, callback) => {
              if (this.currentTab == '1' && !this.baseInfo.slider_image.length) {
                callback(new Error('请上传商品轮播图'));
              } else {
                callback();
              }
            },
            type: 'array',
          },
        ],
        auto_off_time: [
          {
            validator: (rule, value, callback) => {
              console.log(typeof this.formValidate.auto_off_time)
              if (this.currentTab == '1' && this.formValidate.auto_off_time === '') {
                callback(new Error('请选择下架时间'));
              } else {
                callback();
              }
            },
            required: true,
          },
        ],
      },
      goodsSource: 1,
      supplierList: [],
    };
  },
  components: {
    storeLabelList,
    menusFrom,
    draggable: vuedraggable,
  },
  computed: {
    startPickOptions() {
      const that = this;
      return {
        disabledDate(time) {
          if (that.formValidate.auto_off_time) {
            return (
              time.getTime() >
              new Date(that.formValidate.auto_off_time).getTime()
            );
          }
          return '';
        },
      };
    },
    endPickOptions() {
      const that = this;
      return {
        disabledDate(time) {
          if (that.formValidate.is_show == '1') {
            return time.getTime() < Date.now();
          }
          if (that.formValidate.auto_on_time) {
            return (
              time.getTime() <
              new Date(that.formValidate.auto_on_time).getTime()
            );
          }
          return '';
        },
      };
    },
  },
  watch: {
    successData: {
      handler(val) {
        if (val) {
          let keys = Object.keys(this.formValidate);
          keys.map((i) => {
            this.formValidate[i] = this.baseInfo[i];
          });
        }
      },
      immediate: true,
      deep: true,
    },
    'baseInfo.slider_image': {
      handler(val) {
      },
      deep: true,
    },
    'baseInfo.video_link'(val) {
      this.formValidate.video_link = val;
    },
    'baseInfo.supplier_id'(val) {
      if (val) {
        this.goodsSource = 2;
      }
    },
    'formValidate.cate_id'() {
      this.$parent.validateField('cate_id');
    }
  },
  created() {
    this.goodsCategory();
    this.getBrandList();
    this.getAllUnit();
    this.getSupplierList();
  },
  methods: {
    // 品牌列表
    getBrandList() {
      brandList()
        .then((res) => {
          //initBran()函数作用iview中规定value必须是字符串，后台返回成了数字，用于处理这个，给了个递归；
          this.initBran(res.data);
          this.brandData = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    initBran(data) {
      data.map((item) => {
        item.value = item.value.toString();
        if (item.children && item.children.length) {
          this.initBran(item.children);
        }
      });
    },
    addBrand() {
      this.$refs.menusFrom.modals = true;
      this.$refs.menusFrom.titleFrom = '添加品牌分类';
      this.formBrand = {
        sort: 0,
        is_show: 1,
      };
      this.formBrand.fid = [0];
      this.$refs.menusFrom.type = 1;
    },
    // 商品分类；
    goodsCategory() {
      cascaderListApi({
        type: 0,
        relation_id: 0,
      })
        .then((res) => {
          this.treeSelect = res.data;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    addClass() {
      this.$modalForm(productCreateApi()).then(() => this.goodsCategory());
    },
    getAllUnit() {
      productAllUnit()
        .then((res) => {
          this.unitNameList = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    addUnit() {
      this.$modalForm(productUnitCreate()).then(() => this.getAllUnit());
    },
    addStoreLabel() {
      this.$modalForm(productLabelAdd()).then(() => {});
    },
    activeStoreData(storeDataLabel) {
      this.storeLabelShow = false;
      this.formValidate.store_label_id = storeDataLabel;
    },
    openStoreLabel(row) {
      this.storeLabelShow = true;
      this.$refs.storeLabel.storeLabel(
        JSON.parse(JSON.stringify(this.formValidate.store_label_id))
      );
    },
    // 标签弹窗关闭
    storeLabelClose() {
      this.storeLabelShow = false;
    },
    goodsOn(e) {
      if (e == 0 || e == 1) {
        this.formValidate.auto_on_time = '';
      }
    },
    goodsOff(e) {
      if (!e) {
        this.formValidate.auto_off_time = '';
      }
    },
    //定时上架
    onchangeShow(e) {
      this.formValidate.auto_on_time = e;
    },
    //定时下架
    onchangeOff(e) {
      this.formValidate.auto_off_time = e;
    },
    // 删除视频；
    delVideo() {
      this.$set(this.formValidate, 'video_link', '');
    },
    // 移动
    handleDragStart(e, item) {
      this.dragging = item;
    },
    handleDragEnd(e, item) {
      this.dragging = null;
    },
    handleDragOver(e) {
      e.dataTransfer.dropEffect = 'move';
    },
    handleDragEnter(e, item) {
      e.dataTransfer.effectAllowed = 'move';
      if (item === this.dragging) {
        return;
      }
      const newItems = [...this.baseInfo.slider_image];
      const src = newItems.indexOf(this.dragging);
      const dst = newItems.indexOf(item);
      newItems.splice(dst, 0, ...newItems.splice(src, 1));
      this.baseInfo.slider_image = newItems;
    },
    handleRemove(i) {
      this.baseInfo.slider_image.splice(i, 1);
    },
    modalPicTap(e) {
      this.$emit('modalPicTap', 'duo');
    },
    addVideo() {
      this.$emit('modalPicTap', 'dan', 'video');
    },
    unitChange(val) {
      EventBus.$emit('unitChanged', val);
    },
    //获取供应商列表；
    getSupplierList() {
      getSupplierList()
      .then(async (res) => {
        this.supplierList = res.data;
      })
      .catch((res) => {
        this.$Message.error(res.msg);
      });
    },
    sourceChange(value) {
	  this.formValidate.supplier_id = 0;
      this.$emit('sourceChange', value);
    }
  },
};
</script>
<style scoped lang="less">
.addClass {
  color: #1890ff;
  margin-left: 14px;
  cursor: pointer;
}
.input-display {
  display: none;
}
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
.iview-video-style {
  width: 40%;
  height: 180px;
  border-radius: 10px;
  background-color: #707070;
  margin-top: 10px;
  position: relative;
  overflow: hidden;
}

.iview-video-style .iconv {
  color: #fff;
  line-height: 180px;
  width: 50px;
  height: 50px;
  display: inherit;
  font-size: 26px;
  position: absolute;
  top: -74px;
  left: 50%;
  margin-left: -25px;
  cursor: pointer;
}

.iview-video-style .mark {
  position: absolute;
  width: 100%;
  height: 30px;
  top: 0;
  background-color: rgba(0, 0, 0, 0.5);
  text-align: center;
}
.video-style {
  width: 100%;
  height: 100% !important;
  border-radius: 10px;
}
.tips {
  display: inline-bolck;
  font-size: 12px;
  font-weight: 400;
  color: #999999;
  margin-top: 6px;
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
