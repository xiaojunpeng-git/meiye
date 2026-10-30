<template>
	<div class="goodList">
		<Form ref="formValidate" :model="formValidate" :label-width="labelWidth" :label-position="labelPosition"
			class="tabform">
			<Row type="flex" :gutter="24">
				<Col v-bind="grid">
				<FormItem label="商品分类：" label-for="pid">
					<Cascader :data="treeSelect" placeholder="请选择商品分类" change-on-select filterable v-width="'200px'"
						@on-change="treeSearchs"></Cascader>
				</FormItem>
        <!-- 问卷赠送和已上线业务选品共用控件，但各自只展示允许的商品类型。 -->
        <FormItem v-if="giftPicker || businessTypePicker" label="商品类型：">
          <Select v-model="formValidate.product_type" :disabled="!giftPicker && Number(chooseType) === 97"
            :clearable="giftPicker || Number(chooseType) !== 97" @on-change="userSearchs">
            <Option v-for="item in (giftPicker ? giftTypeOptions : businessTypeOptions)" :key="item.id" :value="item.id">{{ item.label }}</Option>
          </Select>
        </FormItem>
        <FormItem v-else label="商品标签：" label-for="pid">
					<Select v-model="formValidate.store_label_id" clearable
						@on-change="userSearchs">
						<Option v-for="item in labelSelect" :value="item.id" :key="item.id">{{ item.label_name }}
						</Option>
					</Select>
				</FormItem>
				</Col>
				<Col v-bind="grid">
        <FormItem v-if="isCard && !lockProductType && !giftPicker && !businessTypePicker" label="商品类型：">
					<Select v-model="formValidate.product_type" clearable
						@on-change="userSearchs">
						<Option v-for="item in productTypeSelect" :value="item.id" :key="item.id">{{ item.label_name }}
						</Option>
					</Select>
				</FormItem>
				<FormItem label="商品搜索：" label-for="store_name">
					<Input search enter-button placeholder="请输入商品名称,关键字,编号" v-model="formValidate.store_name"
						style="width: 240px" @on-search="userSearchs" />
				</FormItem>
        <!-- 赠送和业务选品均按销售状态筛选。 -->
        <FormItem v-if="giftPicker || businessTypePicker" label="商品状态：">
					<Select v-model="formValidate.type" @on-change="userSearchs">
						<Option :value="1">销售中</Option>
						<Option :value="2">仓库中</Option>
					</Select>
				</FormItem>
				</Col>
			</Row>
		</Form>

		<div class="vxeTable">
			<vxe-table border="inner" ref="xTree" :column-config="{resizable: true}" row-id="id"
				:tree-config="flatSelect ? null : {children: 'attrValue',reserve:true}" :data="tableList" :loading="loading" max-height="400" :checkbox-config="{reserve: true}">
				<vxe-column type="checkbox" title="多选" width="90" :tree-node="!flatSelect"></vxe-column>
				<vxe-column field="id" title="商品ID" width="80"></vxe-column>
				<vxe-column field="image" title="图片" min-width="90">
					<template v-slot="{ row }">
						<viewer>
							<div class="tabBox_img">
								<img v-lazy="row.image" />
							</div>
						</viewer>
					</template>
				</vxe-column>
				<vxe-column field="store_name" title="商品名称" min-width="190">
					<template v-slot="{ row }">
						<Tooltip max-width="500" placement="bottom" transfer>
							<span class="line2">{{row.store_name}}</span>
							<p slot="content">{{row.store_name}}</p>
						</Tooltip>
					</template>
				</vxe-column>
				<vxe-column field="stock" title="良品库存" min-width="100" v-if="chooseType == 94"></vxe-column>
				<vxe-column field="defective_stock" title="残次品数量" min-width="100" v-if="chooseType == 94"></vxe-column>
				<vxe-column field="product_type" title="商品类型" min-width="100">
					<template v-slot="{ row }">
            <span v-if="giftPicker || businessTypePicker">{{ businessProductTypeLabel(row.product_type) }}</span>
            <template v-else>
              <span v-if="row.product_type==0">产品</span>
						<span v-if="row.product_type==1">卡密商品</span>
						<span v-if="row.product_type==3">虚拟商品</span>
            <span v-if="row.product_type==4">次卡商品</span>
            <span v-if="row.product_type==5">卡项</span>
            <span v-if="row.product_type==6">预约商品</span>
            </template>
					</template>
				</vxe-column>
				<vxe-column field="cate_name" title="商品分类" min-width="150">
					<template v-slot="{ row }">
						<Tooltip max-width="200" placement="bottom" transfer>
							<span class="line2">{{row.cate_name}}</span>
							<p slot="content">{{row.cate_name}}</p>
						</Tooltip>
					</template>
				</vxe-column>
				<vxe-column field="store_label" title="商品标签" min-width="150">
					<template v-slot="{ row }">
						<Tooltip max-width="500" placement="bottom" transfer>
							<span class="line2">{{row.store_label}}</span>
							<p slot="content">{{row.store_label}}</p>
						</Tooltip>
					</template>
				</vxe-column>
			</vxe-table>
      <div class="acea-row row-right page">
        <Page
            :total="total"
            :current="formValidate.page"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="formValidate.limit"
            @on-page-size-change="pageSizeChange"
            show-sizer
        />
      </div>
		</div>
		<div class="footer" slot="footer">
			<Button type="primary" size="large" long :disabled="loading" @click="ok">提交</Button>
		</div>
	</div>
</template>

<script>
import {
  mapState
} from 'vuex';
import {
  cascaderListApi,
  changeListApi,
  allLabelApi
} from '@/api/product';
export default {
  name: 'index',
  props: {
    goodsType: {
      type: Number,
      default: 0
    },
    /**
       * 选择商品列表使用场景1：秒杀、2:砍价、3:拼团、4:积分、5:套餐、7:新人礼、8:抽奖、
	   * 90:卡项关联商品、91：添加门店同步商品、92优惠活动参与商品、93优惠活动赠送商品、
	   * 94：库存管理选品（仅产品）、95：院装耗材（仅产品）、96：院装项目（仅预约）、
	   * 97：平台卡项选项目（仅平台项目主数据）、98：优惠券关联项目/卡项/产品
       */
    chooseType: {
      type: Number,
      default: 0
    },
    is_new: {
      type: String,
      default: ''
    },
    diy: {
      type: Boolean,
      default: false
    },
    isdiy: {
      type: Boolean,
      default: false
    },
    ischeckbox: {
      type: Boolean,
      default: false
    },
    datas: {
      type: Object,
      default: function() {
        return {};
      }
    },
    isIntegral: {
      type: Boolean,
      default: false
    },
    isCard: {
      type: Boolean,
      default: false
    },
    // 平铺选择商品，不展开规格（赠送配置等场景）
    flatSelect: {
      type: Boolean,
      default: false
    },
    // 储值和问卷共用商品目录选择控件；实际可赠类型由调用方明确缩小。
    giftPickerTypes: { type: Array, default: null },
    // 问卷使用发放门店的可赠目录，不让平台商品列表替代门店归属校验。
    giftListProvider: { type: Function, default: null }
  },
  data() {
    return {
      labelSelect: [],
      cateIds: [],
      treeSelect: [],
      formValidate: {
        page: 1,
        limit: this.giftPickerTypes || [97, 98].includes(Number(this.chooseType)) ? 20 : 10,
        cate_id: '',
        store_name: '',
        is_new: this.is_new,
        store_label_id: '',
        product_type: '',
        choose_type: this.chooseType,
        // 卡项项目选择默认只读销售中商品，2 与商品列表“仓库中”使用同一后端状态口径。
        type: this.giftPickerTypes || [97, 98].includes(Number(this.chooseType)) ? 1 : ''
      },
      total: 0,
      loading: false,
      requestSequence: 0,
      grid: {
        xl: 10,
        lg: 10,
        md: 12,
        sm: 24,
        xs: 24
      },
      tableList: [],
      currentid: 0,
      productRow: {},
      images: [],
      productTypeSelect: [
        {
          id: 0,
          label_name: '产品'
        },
        {
          id: 6,
          label_name: '预约商品'
        }
      ]
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 120;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
    giftPicker() { return this.giftPickerTypes !== null; },
    giftTypeOptions() {
      return [{ id: 0, label: '产品' }, { id: 5, label: '卡项' }, { id: 6, label: '项目' }]
        .filter(item => this.giftPickerTypes.includes(item.id));
    },
    // 业务入口按类型约束，兼容问卷和储值赠送的独立可选范围。
    businessTypePicker() { return [97, 98].includes(Number(this.chooseType)); },
    businessTypeOptions() {
      return Number(this.chooseType) === 97 ? [{ id: 6, label: '项目' }]
        : [{ id: 5, label: '卡项' }, { id: 6, label: '项目' }, { id: 0, label: '产品' }];
    },
    // 库存选品/院装耗材只锁产品；院装项目和卡项选项目只锁项目。
    lockProductType() {
      return [94, 95, 96, 97].includes(Number(this.chooseType));
    },
  },
  created() {
    this.applyInventoryProductTypeLock();
  },
  mounted() {
    this.goodsCategory();
    this.getList();
    if (!this.giftPicker && !this.businessTypePicker) this.getAllLabelApi();
  },
  methods: {
    /** 卡项包含两种已存储卡类型，选择器只统一显示业务名称，不改写原类型。 */
    businessProductTypeLabel(type) {
      return ({ 0: '产品', 4: '卡项', 5: '卡项', 6: '项目' })[Number(type)] || '未知类型';
    },
    applyInventoryProductTypeLock() {
      const ct = Number(this.chooseType);
      if (ct === 94 || ct === 95) {
        this.formValidate.product_type = 0;
      } else if (ct === 96 || ct === 97) {
        this.formValidate.product_type = 6;
      }
      this.formValidate.choose_type = ct || this.formValidate.choose_type;
    },
    getAllLabelApi() {
      allLabelApi().then(res => {
        this.labelSelect = res.data;
      }).catch(err => {
        this.$Message.error(err.msg);
      });
    },
    // 商品分类；
    goodsCategory() {
      cascaderListApi({
        type: 0,
        relation_id: 0
      })
        .then((res) => {
          this.treeSelect = res.data;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    pageChange(index) {
      this.formValidate.page = index;
      this.getList();
    },
    pageSizeChange(limit) {
      this.formValidate.limit = limit;
      this.formValidate.page = 1;
      this.getList();
    },
    // 列表
    getList() {
      // 选品弹窗切换门店、类型或页码时，只允许最后一次查询改写可选目录。
      const sequence = ++this.requestSequence;
      this.loading = true;
      this.applyInventoryProductTypeLock();
      if (this.goodsType) {
        this.formValidate.is_presale_product = 0;
        this.formValidate.is_vip_product = 0;
      }
      this.formValidate.cate_id = this.cateIds[this.cateIds.length - 1];
      if (this.isIntegral) {
        this.formValidate.is_integral = 1;
      }
      if (this.isCard && Number(this.chooseType) !== 97) {
        this.formValidate.is_card = 1;
      } else if (Number(this.chooseType) === 97) {
        this.formValidate.is_card = 0;
      }
      const request = this.giftListProvider
        ? this.giftListProvider({ ...this.formValidate })
        : changeListApi(this.giftPicker
          ? { ...this.formValidate, catalog_types: JSON.stringify(this.formValidate.product_type === '' ? this.giftPickerTypes : [Number(this.formValidate.product_type)]) }
          : this.formValidate);
      request
        .then(async(res) => {
          if (sequence !== this.requestSequence) return;
          const list = res.data.list;
          list.forEach(item => {
            (item.attrValue || []).forEach(j => {
              j.store_name = j.suk;
              j.store_names = item.store_name;
              j.cate_name = item.cate_name;
              j.store_label = item.store_label;
              j.product_type = item.product_type;
            });
          });
          this.tableList = list;
          this.total = res.data.count;
          this.loading = false;
        })
        .catch((res) => {
          if (sequence !== this.requestSequence) return;
          this.loading = false;
          this.$Message.error(res.msg);
        });
    },
    ok() {
      if (this.loading) return;
      const selectRecords = this.$refs.xTree.getCheckboxRecords();
      const selectRecords2 = this.$refs.xTree.getCheckboxReserveRecords();
      const selected = [...selectRecords, ...selectRecords2];
      if (this.flatSelect) {
        const goodsattr = [];
        const ids = [];
        selected.forEach(item => {
          if (item.product_id) return;
          // 优惠券绑定商品主键，不把规格主键传给发券规则。
          if (Number(this.chooseType) === 98 && ![0, 4, 5, 6].includes(Number(item.product_type))) return;
          // 问卷奖励只能选择项目和实物产品，保持与服务端奖励目录的类型边界一致。
          if (this.giftPicker && !this.giftPickerTypes.includes(Number(item.product_type))) return;
          if (!ids.includes(item.id)) {
            goodsattr.push(item);
            ids.push(item.id);
          }
        });
        if (goodsattr.length > 0) {
          this.$emit('getProductId', goodsattr);
        } else {
          this.$Message.warning('请先选择商品');
        }
        return;
      }
      const obj = [];
      const ids = [];
      selected.forEach(item => {
        if (item.attrValue) {
          item.attrValue.forEach(j => {
            if (!ids.includes(j.id)) {
              obj.push(j);
              ids.push(j.id);
            }
          });
        } else {
          if (!ids.includes(item.id)) {
            obj.push(item);
            ids.push(item.id);
          }
        }
      });
      const goodsattr = [];
      const ct = Number(this.chooseType);
      obj.forEach((item) => {
        if (!item.hasOwnProperty('product_id')) return;
        // 库存选品/院装耗材：提交前再挡非产品
        if ((ct === 94 || ct === 95) && item.product_type != null && Number(item.product_type) !== 0) return;
        if ((ct === 96 || ct === 97) && Number(item.product_type) !== 6) return;
        goodsattr.push(item);
      });
      if (goodsattr.length > 0) {
        this.$emit('getProductId', goodsattr);
      } else {
        this.$Message.warning('请先选择商品');
      }
    },
    treeSearchs(value) {
      this.cateIds = value;
      this.formValidate.page = 1;
      this.getList();
    },
    // 表格搜索
    userSearchs() {
      this.formValidate.page = 1;
      this.getList();
    },
    clear() {
      this.productRow.id = '';
      this.currentid = '';
    }
  }
};
</script>

<style scoped lang="stylus">
	.vxeTable {
		/deep/.vxe-header--row th{
			background #F3F8FE;
		}
	}

	.footer {
		margin: 15px 0;
	}

	.tabBox_img {
		width: 36px;
		height: 36px;
		border-radius: 4px;
		cursor: pointer;

		img {
			width: 100%;
			height: 100%;
		}
	}

	.tabform {
		>>>.ivu-form-item {
			margin-bottom: 16px !important;
		}
	}

	.btn {
		margin-top: 20px;
		float: right;
	}

	.goodList {
		>>>table {
			width: 100% !important;
		}
	}
</style>
