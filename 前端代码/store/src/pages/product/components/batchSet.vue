<template>
  <div>
    <Modal v-model="batchModal" title="批量设置" width="750" class-name="batch-modal" @on-visible-change="batchVisibleChange">
      <Alert show-icon>每次只能修改一项，如需修改多项，请多次操作。</Alert>
      <Row type="flex" align="middle">
        <Col span="5">
          <Menu :active-name="menuActive" width="auto" @on-select="menuSelect">
            <MenuItem :name="9">商品展示</MenuItem>
            <MenuItem :name="1">商品分类</MenuItem>
            <MenuItem :name="3">配送方式</MenuItem>
            <MenuItem :name="8">运费设置</MenuItem>
            <MenuItem :name="7">自定义留言</MenuItem>
          </Menu>
        </Col>
        <Col span="19">
          <Form :model="batchData" :label-width="122">
            <FormItem v-if="menuActive === 9" label="商品展示：">
              <RadioGroup v-model="batchData.show_type">
                <Radio :label="0">全部</Radio>
                <Radio :label="1">移动端</Radio>
                <Radio :label="2">收银台</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-if="menuActive === 1" label="平台商品分类：">
              <el-cascader
                  v-model="batchData.cate_id"
                  :options="data1"
                  :props="props"
                  size="small"
                  filterable
                  clearable
                  :class="{ single: !batchData.cate_id.length }"
              >
              </el-cascader>
            </FormItem>
            <FormItem v-if="menuActive === 1 && product_category_status == 1" label="门店商品分类：">
              <el-cascader
                  v-model="batchData.store_cate_id"
                  :options="data2"
                  :props="props"
                  size="small"
                  filterable
                  clearable
                  :class="{ single: !batchData.store_cate_id.length }"
              >
              </el-cascader>
            </FormItem>
            <FormItem v-if="menuActive === 3" label="配送方式：">
              <CheckboxGroup v-model="batchData.delivery_type" size="small">
                <Checkbox label="1" :disabled="!deliveryType.includes('1')">快递发货</Checkbox>
                <Checkbox label="3" :disabled="!deliveryType.includes('3')" v-if="cityDeliveryStatus">同城配送</Checkbox>
                <Checkbox label="2" :disabled="!deliveryType.includes('2')">到店自提</Checkbox>
              </CheckboxGroup>
            </FormItem>
            <FormItem v-if="menuActive === 8" label="运费设置：">
              <RadioGroup v-model="batchData.freight">
                <Radio :label="1">包邮</Radio>
                <Radio :label="2">固定邮费</Radio>
                <Radio :label="3">运费模板</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-if="menuActive === 8 && batchData.freight === 2">
              <div class="input-number">
                <InputNumber v-model="batchData.postage" :min="0"></InputNumber>
                <span class="suffix">元</span>
              </div>
            </FormItem>
            <FormItem v-if="menuActive === 8 && batchData.freight === 3">
              <Select v-model="batchData.temp_id">
                <Option v-for="item in templateList" :key="item.id" :value="item.id">{{ item.name }}</Option>
              </Select>
            </FormItem>
            <FormItem v-if="menuActive === 7" label="自定义留言：">
              <i-switch v-model="customBtn" size="large" @on-change="customMessBtn">
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
              <div class="mt10" v-if="customBtn">
                <Select
                    v-model="batchData.system_form_id"
                    filterable
                    placeholder="请选择"
                    @on-change="changeForm"
                >
                  <Option
                      v-for="(item, index) in formList"
                      :value="item.id"
                      :key="item.id"
                  >{{ item.name }}</Option
                  >
                </Select>
              </div>
              <div v-if="customBtn && batchData.system_form_id">
                <Table border :columns="formColumns" :data="formTypeList" ref="table" class="customTab" width="100%" max-height="165">
                  <template slot-scope="{ row }" slot="require">
                    <span>{{row.require?'必填':'不必填'}}</span>
                  </template>
                </Table>
              </div>
            </FormItem>
          </Form>
        </Col>
      </Row>
      <div slot="footer">
        <Button @click="cancelBatch">取消</Button>
        <Button type="primary" @click="saveBatch">保存</Button>
      </div>
    </Modal>
  </div>
</template>
<script>
import {
  cascaderList,
  productGetTemplateApi,
  allSystemForm,
  batchProcess
} from '@/api/product';
import { systemFormInfo } from '@/api/setting'
export default {
  props:{
      deliveryType: {
          type: Array,
          default: function () {
              return [];
          }
      },
      cityDeliveryStatus: {
          type: Number,
          default: 0,
      },
  },
  data(){
    return {
      props: { emitPath: false, multiple: true, checkStrictly: true },
      batchModal:false,
      menuActive: 1,
      data1: [],
      data2: [],
      templateList: [],
      customBtn: false,
      formList:[],
      formTypeList: [],
      formColumns: [
        {
          title: '表单标题',
          key: 'title',
          // align:'center',
          minWidth: 100
        },
        {
          title: '表单类型',
          key: 'name',
          // align:'center',
          minWidth: 100
        },
        {
          title: '是否必填',
          slot: 'require',
          // align:'center',
          minWidth: 100
        }
      ],
      batchData: {
        show_type:0,
        system_form_id:0,//自定义表单id
        cate_id: [],
        store_cate_id: [],
        delivery_type: [],
        freight: 1,
        postage: 0,
        temp_id: 0
      },
	  checkUidList:[],
	  isAll:0,
	  formValidate:{},
    product_category_status: 0
    }
  },
  created(){
    this.allFormList();
  },
  mounted(){
    this.product_category_status = localStorage.getItem('product_category_status') || 0;
    this.goodsCategory(0);
    if (this.product_category_status == 1) {
      this.goodsCategory(1);
    }
    this.productGetTemplate();
  },
  methods: {
	cancelBatch() {
	  this.batchModal = false;
	},
	saveBatch() {
	  if(this.customBtn && this.batchData.system_form_id == 0){
	    return this.$Message.warning('请选择自定义表单模板');
	  }
	  let data = {
	    type: this.menuActive,
	    ids: this.checkUidList,
	    all: this.isAll,
	    where: this.formValidate,
	    data: this.batchData,
	  };
	  batchProcess(data).then(res => {
	    this.$Message.success(res.msg);
	    this.batchModal = false;
	  }).catch(res => {
	    this.$Message.error(res.msg);
	  });
	},
    changeForm(e){
      this.getSystemFormInfo(e,{type:1});
    },
    getSystemFormInfo(e,data){
      systemFormInfo(e,data).then(res=>{
        this.formTypeList = res.data.info;
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    allFormList(){
      allSystemForm().then(res=>{
        this.formList = res.data;
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    // 获取运费模板；
    productGetTemplate() {
      productGetTemplateApi().then((res) => {
        this.templateList = res.data;
      });
    },
    // 商品分类；
    goodsCategory(type) {
      cascaderList(type)
          .then((res) => {
            if (type) {
              this.data2 = res.data;
            } else {
              this.data1 = res.data;
            }
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
    },
    customMessBtn(e) {
      if (!e) {
        this.batchData.system_form_id = 0;
      }
    },
    menuSelect(name) {
      this.menuActive = name;
    },
    batchVisibleChange() {
      this.batchData = {
        show_type:0,
        cate_id: [],
        store_cate_id: [],
        delivery_type: [],
        freight: 1,
        postage: 0,
        temp_id: 0,
        system_form_id: 0
      };
      this.storeDataLabel = [];
      this.couponName = [];
      this.dataLabel = [];
      this.menuActive = 9;
    }
  }
}
</script>
<style scoped lang="stylus">
.ivu-menu{
	margin: 20px 0;
}
.customTab{
  margin-top: 20px;
  /deep/.ivu-table-header thead tr th{
    padding: 0 10px !important
    .ivu-table-cell{
      padding: 5px 0 !important
    }
  }
  /deep/.ivu-table td{
    height: 30px !important;
    padding: 0 10px !important;
    .ivu-table-cell{
      padding: 2px 0 !important;
    }
  }
}
</style>