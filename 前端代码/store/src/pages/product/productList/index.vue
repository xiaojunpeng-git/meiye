<template>
  <div>
    <Card :bordered="false" dis-hover>
      <Form ref="formValidate" :model="formValidate" :label-width="labelWidth" :label-position="labelPosition">
        <Row type="flex" :gutter="24">
          <Col>
            <FormItem label="平台商品分类：" label-for="cate_id">
				<el-cascader
				    placeholder="请选择平台商品分类"
				    style="width:250px"
				    size="mini"
				    v-model="formValidate.cate_id"
				    :options="cateData"
				    :props="props"
					@change="search"
				    filterable
				    clearable
				>
				</el-cascader>
            </FormItem>
          </Col>
          <Col v-if="product_category_status == 1">
            <FormItem label="门店商品分类：" label-for="store_cate_id">
				<el-cascader
				    placeholder="请选择门店商品分类"
				    style="width:250px"
				    size="mini"
				    v-model="formValidate.store_cate_id"
				    :options="storeCateData"
				    :props="props"
					@change="search"
				    filterable
				    clearable
				>
				</el-cascader>
            </FormItem>
          </Col>
		  <Col>
		    <FormItem label="商品展示：" label-for="show_type">
				<Select v-model="formValidate.show_type" style="width:250px" placeholder="请选择" clearable @on-change="search">
				  <Option value="0">全部</Option>
				  <Option value="1">移动端</Option>
				  <Option value="2">收银台</Option>
				</Select>
		    </FormItem>
		  </Col>
		  <Col>
		    <FormItem label="商品类型：" label-for="product_type">
				<Select v-model="formValidate.product_type" style="width:250px" placeholder="请选择" clearable @on-change="search">
				  <Option value="0">普通商品</Option>
				  <Option value="4">次卡商品</Option>
				  <Option value="5">卡项商品</Option>
				  <Option value="6">预约商品</Option>
				</Select>
		    </FormItem>
		  </Col>
		  <Col>
		    <FormItem label="参与库存：">
				<Select v-model="formValidate.is_inventory" style="width:250px" placeholder="请选择" clearable @on-change="search">
				  <Option :value="1">开启</Option>
				  <Option :value="0">关闭</Option>
				</Select>
		    </FormItem>
		  </Col>
		  <Col>
		    <FormItem label="允许负库存：">
				<Select v-model="formValidate.allow_negative_stock" style="width:250px" placeholder="请选择" clearable @on-change="search">
				  <Option :value="1">开启</Option>
				  <Option :value="0">关闭</Option>
				</Select>
		    </FormItem>
		  </Col>
          <Col>
            <FormItem label="商品搜索：" label-for="store_name">
              <Input enter-button style="width:250px"  placeholder="请输入商品名称,关键字,ID" v-model="formValidate.store_name" />
			  <div class="search ml-24" @click="search">搜索</div>
			  <div class="reset ml-24" @click="reset">重置</div>
            </FormItem>
          </Col>
        </Row>
      </Form>
    </Card>
    <Card :bordered="false" dis-hover class="mt15 tablebox">
      <div class="product_tabs tabbox">
        <Tabs v-model="formValidate.type" @on-click="onClickTab">
          <TabPane v-for="(item, index) in headerList" :key="index" :label="item.name + '(' + item.count + ')'"
                   :name="item.type.toString()" />
        </Tabs>
      </div>
      <div class="table mt20">
        <Button v-if="product_status==1" type="primary" class="bnt mr15" @click="addTypeShow = true">添加商品</Button>
        <Dropdown
          v-auth="['product-product-batch_operate']"
          v-show="batchOptions.length"
          class="mr15"
          @on-click="batchHandler"
        >
          <Button
            type="primary"
            :disabled="!checkUidList.length && isAll == 0"
          >
            批量操作 <Icon type="ios-arrow-down" />
          </Button>
          <DropdownMenu slot="list">
            <DropdownItem
              v-auth="'[' + item.auth + ']'"
              v-for="item in batchOptions"
              :key="item.key"
              :name="item.key"
              :disabled="!checkUidList.length && isAll == 0"
              >{{ item.label }}</DropdownItem
            >
          </DropdownMenu>
        </Dropdown>
        <Tooltip
            content="本页至少选中一项"
            :disabled="!!checkUidList.length && isAll==0"
        >
          <Button
              class="bnt mr15"
              :disabled="!checkUidList.length && isAll==0"
              @click="openBatch"
          >批量设置</Button
          >
        </Tooltip>
        <Dropdown
            v-auth="['product-product-import', 'product-product-export']"
            class="mr15"
            @on-click="goodsMove"
          >
            <Button>商品迁移 <Icon type="ios-arrow-down" /> </Button>
            <template #list>
              <DropdownMenu>
                <DropdownItem :name="1" v-auth="['product-product-import']"
                  >商品导入</DropdownItem
                >
                <DropdownItem :name="2" v-auth="['product-product-export']"
                  >商品导出</DropdownItem
                >
              </DropdownMenu>
            </template>
          </Dropdown>
        <Button class="mr15" @click="showColumnSetting = true">列设置</Button>
        <vxe-table
            ref="xTable"
            class="mt25"
            :key="'product-cols-' + visibleColumnKey"
            :loading="loading"
            row-id="id"
            :checkbox-config="{reserve: true}"
            @checkbox-all="checkboxAll"
            @checkbox-change="checkboxItem"
            :data="orderList">
          <vxe-column type="checkbox" width="100">
            <template #header>
              <div>
                <Dropdown transfer @on-click="allPages">
                  <a href="javascript:void(0)" class="acea-row row-middle">
                    <span>全选({{isAll==1?(total-checkUidList.length):checkUidList.length}})</span>
                    <Icon type="ios-arrow-down"></Icon>
                  </a>
                  <template #list>
                    <DropdownMenu>
                      <DropdownItem name="0">当前页</DropdownItem>
                      <DropdownItem name="1">所有页</DropdownItem>
                    </DropdownMenu>
                  </template>
                </Dropdown>
              </div>
            </template>
          </vxe-column>
          <vxe-column
            v-for="col in visibleDataColumns"
            :key="col.key"
            :field="col.key"
            :title="col.title"
            :width="col.width"
            :min-width="col.minWidth"
          >
            <template v-slot="{ row }">
              <viewer v-if="col.key === 'image'">
                <div class="tabBox_img">
                  <img v-lazy="row.image" />
                </div>
              </viewer>
              <div v-else-if="col.key === 'store_name'" class="product-name-cell">
                {{ row.store_name }}
              </div>
              <template v-else-if="col.key === 'product_type'">
                <span v-if="row.product_type == 0">普通商品</span>
                <span v-else-if="row.product_type == 4">次卡商品</span>
                <span v-else-if="row.product_type == 5">卡项商品</span>
                <span v-else-if="row.product_type == 6">预约商品</span>
                <span v-else>—</span>
              </template>
              <span v-else-if="col.key === 'stock'">{{ row.branch_stock != null ? row.branch_stock : row.stock }}</span>
              <span v-else-if="col.key === 'sales'">{{ row.branch_sales != null ? row.branch_sales : row.sales }}</span>
              <span v-else-if="col.key === 'spec_type'">{{ row.spec_type ? '多规格' : '单规格' }}</span>
              <span v-else-if="col.key === 'is_inventory'">
                <template v-if="row.product_type != 0">—</template>
                <template v-else>{{ row.is_inventory == 1 ? '开启' : '关闭' }}</template>
              </span>
              <span v-else-if="col.key === 'allow_negative_stock'">
                <template v-if="row.product_type != 0">—</template>
                <template v-else>{{ row.allow_negative_stock == 1 ? '开启' : '关闭' }}</template>
              </span>
              <span v-else-if="col.key === 'is_vip'">{{ row.is_vip ? '开启' : '关闭' }}</span>
              <span v-else-if="col.key === 'add_time'">{{ formatTime(row.add_time) }}</span>
              <span v-else-if="col.key === 'brand_com'">{{ formatBrand(row) }}</span>
              <span v-else-if="col.key === 'store_label_id'">{{ formatStoreLabel(row.store_label_id) }}</span>
              <template v-else-if="col.key === 'state'">
                <i-switch
                  v-model="row.is_show"
                  :value="row.is_show"
                  :true-value="1"
                  :false-value="0"
                  :disabled="row.is_verify == 1 ? false : true"
                  @on-change="changeSwitch(row)"
                  size="large"
                  v-if="formValidate.type != 7"
                >
                  <span slot="open">上架</span>
                  <span slot="close">下架</span>
                </i-switch>
                <div v-else>{{ row.is_del ? '已删除' : !row.is_show ? '已下架' : '' }}</div>
              </template>
              <span v-else>{{ row[col.key] }}</span>
            </template>
          </vxe-column>
          <vxe-column field="refusal" title="拒绝原因" min-width="150" v-if="formValidate.type == -1"></vxe-column>
          <vxe-column field="refusal" title="下架原因" min-width="150" v-if="formValidate.type == -2"></vxe-column>
          <vxe-column field="action" title="操作" align="center" width="150" fixed="right">
            <template #default="{ row, rowIndex }">
			  <a @click="detail(row.id)">详情</a>
			  <Divider type="vertical" />
              <a @click="edit(row)" v-if="row.pid == 0">编辑</a>
              <Divider type="vertical" v-if="row.pid == 0" />
			  <template>
				  <Dropdown :transfer="true" @on-click="changeMenu(row, $event, rowIndex)">
					  <a href="javascript:void(0)" class="acea-row row-middle">
					    <span>更多</span>
					    <Icon type="ios-arrow-down"></Icon>
					  </a>
					  <DropdownMenu slot="list">
						  <DropdownItem name="1">查看评论</DropdownItem>
						  <DropdownItem name="7" v-if="changePriceStatus && row.pid>0">修改售价</DropdownItem>
						  <DropdownItem name="2" v-if="!openErp && row.product_type == 0 && row.is_inventory == 1">库存管理</DropdownItem>
						  <DropdownItem name="3" v-if="row.pid == 0">{{row.is_del ? '恢复商品' : '移入回收站' }}</DropdownItem>
						  <DropdownItem name="8">商品显示</DropdownItem>
						  <DropdownItem name="9" v-if="row.product_type == 0">配送方式</DropdownItem>
						  <DropdownItem name="5" v-if="product_category_status == 1">商品分类</DropdownItem>
              <DropdownItem name="4">复制商品</DropdownItem>
						  <DropdownItem name="6" v-if="formValidate.type == 6">删除商品</DropdownItem>
					  </DropdownMenu>
				  </Dropdown>
			  </template>
            </template>
          </vxe-column>
        </vxe-table>
        <vxe-pager class="mt20" border size="medium" :page-size="formValidate.limit" :current-page="formValidate.page" :total="total"
                   :layouts="['PrevPage', 'JumpNumber', 'NextPage', 'FullJump', 'Total']" @page-change="pageChange">
        </vxe-pager>
      </div>
    </Card>
    <stockEdit ref="stock" :goodsType='goodsType' @stockChange="stockChange"></stockEdit>
    <productDetails :visible.sync="detailsVisible" :product-id="productId" @saved="getHeader"></productDetails>
    <batchSet ref="batch" :checkUidList="checkUidList" :isAll="isAll" :formValidate="formValidate" :deliveryType="deliveryType" :cityDeliveryStatus="cityDeliveryStatus"></batchSet>
    <adjustPrice ref="adjustPrice" @priceChange='priceChange'></adjustPrice>
	  <Modal v-model="modal1" :title="modal1Title">
      <Form :model="formItem" :label-width="100">
        <FormItem label="门店商品分类：" v-if="modal1Field == 'store_cate_id'">
          <el-cascader
            placeholder="请选择门店商品分类"
            v-width="'100%'"
            size="mini"
            v-model="formItem.store_cate_id"
            :options="storeCateData"
            :props="props"
            filterable
            clearable>
          </el-cascader>
        </FormItem>
        <FormItem label="商品展示：" v-if="modal1Field == 'show_type'">
          <RadioGroup v-model="formItem.show_type">
            <Radio :label="0">全部</Radio>
            <Radio :label="1">移动端</Radio>
            <Radio :label="2">收银台</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem label="配送方式：" v-if="modal1Field == 'delivery_type'">
          <CheckboxGroup v-model="formItem.delivery_type">
            <Checkbox label="1" :disabled="!deliveryType.includes('1')">快递发货</Checkbox>
            <Checkbox label="3" :disabled="!deliveryType.includes('3')" v-if="cityDeliveryStatus">同城配送</Checkbox>
            <Checkbox label="2" :disabled="!deliveryType.includes('2')">到店自提</Checkbox>
          </CheckboxGroup>
        </FormItem>
      </Form>
      <div slot="footer">
        <Button @click="onModal1Cancel">取消</Button>
        <Button type="primary" @click="onModal1Ok">确定</Button>
      </div>
    </Modal>
    <Modal
      v-model="addTypeShow"
      scrollable
      :mask-closable="false"
      title="选择商品类型"
      footer-hide
      width="548"
      class-name="acea-row row-middle row-center"
      :styles="{
        top: '0'
      }"
    >
      <div class="acea-row product-type-wrap">
        <div
            class="productType mr-12 mb-12"
            :class="product_type == item.id ? 'on' : ''"
            v-for="(item, index) in productType"
            :key="index"
            @click="productTypeTap(1, item)"
          >
            <div class="name">{{ item.name }}</div>
            <div class="title">({{ item.title }})</div>
            <div
              v-if="product_type == item.id"
              class="jiao"
            ></div>
            <div
              v-if="product_type == item.id"
              class="iconfont iconduihao"
            ></div>
          </div>
      </div>
      <div class="acea-row row-right row-middle mt-30">
        <Button @click="addTypeShow = false">取消</Button>
        <Button type="primary"  @click="productTypeMenu" class="ml-14">确认</Button>
      </div>
    </Modal>
    <Modal
      v-model="importShow"
      scrollable
      :mask-closable="false"
      title="商品导入"
      footer-hide
      width="900"
    >
      <goodsImport v-if="importShow" @close="importShow = false"></goodsImport>
    </Modal>
    <column-setting
      v-model="showColumnSetting"
      :columns-meta="columnsMeta"
      :columns="columnConfig"
      :default-columns="defaultColumnConfig"
      @save="saveColumnConfig"
    />
  </div>
</template>

<script>
import Setting from "@/setting";
import { mapState, mapMutations } from "vuex";
import goodsDetail from "../components/goods_detail.vue";
import stockEdit from "../components/stockEdit.vue";
import productDetails from '../components/productDetails.vue';
import batchSet from '../components/batchSet.vue';
import adjustPrice from '../components/adjustPrice.vue';
import goodsImport from '../components/goodsImport.vue';
import ColumnSetting from "./components/ColumnSetting.vue";
import {
  productListInfo,
  productHeaderInfo,
  cascaderList,
  setShowApi,
  synchStocks,
  productShowApi,
  productUnshowApi,
  productCategory,
  setProductCate,
  productExportApi,
  batchProcess,
  productObtainDataApi,
  productModifyDataApi,
} from "@/api/product.js";
import { getStaffColumnSetting, saveStaffColumnSetting } from "@/api/staff";
import { erpConfig } from "@/api/erp";
import { deliveryConfigApi, storeGetInfoApi } from '@/api/setting';
import exportExcel from '@/utils/newToExcel.js';

const PRODUCT_COLUMNS_META = [
  { key: "id", title: "商品ID", width: 70 },
  { key: "image", title: "商品图", width: 70 },
  { key: "store_name", title: "商品名称", minWidth: 200 },
  { key: "product_type", title: "商品类型", minWidth: 100 },
  { key: "price", title: "商品售价", minWidth: 90 },
  { key: "cost", title: "成本价", minWidth: 90 },
  { key: "is_inventory", title: "参与库存管理", minWidth: 110 },
  { key: "allow_negative_stock", title: "允许负库存", minWidth: 100 },
  { key: "sort", title: "排序", minWidth: 70 },
  { key: "state", title: "状态", width: 120 },
  { key: "plate_name", title: "商品来源", minWidth: 150 },
  { key: "sales", title: "销量", minWidth: 90 },
  { key: "spec_type", title: "商品规格", minWidth: 90 },
  { key: "cate_name", title: "商品分类", minWidth: 140 },
  { key: "ot_price", title: "市场价格", minWidth: 90 },
  { key: "collect", title: "收藏", minWidth: 80 },
  { key: "ficti", title: "虚拟销量", minWidth: 90 },
  { key: "is_vip", title: "付费会员", minWidth: 90 },
  { key: "add_time", title: "创建时间", minWidth: 140 },
  { key: "brand_com", title: "商品品牌", minWidth: 120 },
  { key: "store_label_id", title: "商品标签", minWidth: 140 },
  { key: "action", title: "操作", width: 150, fixed: true },
];

const PRODUCT_DEFAULT_COLUMN_CONFIG = [
  { key: "id", show: true },
  { key: "image", show: true },
  { key: "store_name", show: true },
  { key: "product_type", show: true },
  { key: "price", show: true },
  { key: "cost", show: true },
  { key: "is_inventory", show: true },
  { key: "allow_negative_stock", show: true },
  { key: "sort", show: true },
  { key: "state", show: true },
  { key: "plate_name", show: false },
  { key: "sales", show: false },
  { key: "spec_type", show: false },
  { key: "cate_name", show: false },
  { key: "ot_price", show: false },
  { key: "collect", show: false },
  { key: "ficti", show: false },
  { key: "is_vip", show: false },
  { key: "add_time", show: false },
  { key: "brand_com", show: false },
  { key: "store_label_id", show: false },
  { key: "action", show: true },
];

const allOptions = [
  {
    label: "批量上架",
    value: ["2"],
    key: "1",
  },
  {
    label: "批量下架",
    value: ["1"],
    key: "2",
  },
  {
    label: "移入回收站",
    value: ["1", "2", "4", "5", "-2"],
    key: "4",
  },
  {
    label: "恢复商品",
    value: ["6"],
    key: "5",
  },
  {
    label: "删除商品",
    value: ["6"],
    key: "6",
  },
];
export default {
  name: "index",
  components: {
    goodsDetail,
    stockEdit,
    productDetails,
    batchSet,
    adjustPrice,
    goodsImport,
    ColumnSetting,
  },
  data() {
    return {
      showColumnSetting: false,
      columnsMeta: PRODUCT_COLUMNS_META,
      columnConfig: PRODUCT_DEFAULT_COLUMN_CONFIG.map((item) => ({ ...item })),
      defaultColumnConfig: PRODUCT_DEFAULT_COLUMN_CONFIG,
      routePre:Setting.routePre,
	  props: { emitPath: false, multiple: true, checkStrictly: true },
      openErp:false,
      goodsId: "",
      headerList: [],
      total: 0,
	  changePriceStatus: 0,
      loading: false,
      orderList: [],
      formValidate: {
		show_type: '',
		product_type:'',
        store_name: "",
        cate_id: "",
        type: "1",
        page: 1,
        limit: 15,
        store_cate_id: "",
        is_inventory: "",
        allow_negative_stock: "",
      },
      product_status:1,
      detailsVisible: false,
      productId: 0,
      isAll: 0,
      isCheckBox: false,
      checkUidList:[],
      isLabel:0,
      cateData: [],
      storeCateData: [],
      modal1: false,
      modal1Title: '',
      modal1Field: '',
      formItem: {
        store_cate_id: [],
        show_type: 0,
        delivery_type: [],
      },
      props: { emitPath: false, multiple: true, checkStrictly: true },
      currentProduct: {},
      product_category_status: 0,
      addTypeShow: false,
	  goodsType:0, //点击当前商品列表获取商品类型
      // 商品类型
      productType:[
        {
          name: '普通商品',
          id:0,
          title:'物流发货'
        },
        {
          name: '次卡商品',
          id:4,
          title:'到店核销'
        },
        {
          name: '卡项商品',
          id:5,
          title:'到店核销'
        },
        {
          name: '预约商品',
          id:6,
          title:'到店+上门'
        },
      ],
      product_type: 0,
      importShow: false,
      modifyData: {},
      deliveryType: [],
      cityDeliveryStatus: 0, // 商城同城配送开启状态
    };
  },
  computed: {
    ...mapState("store/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 120;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
    visibleDataColumns() {
      const cols = [];
      this.columnConfig.forEach((item) => {
        if (item.show === false || item.key === "action" || item.key === "stock") return;
        const meta = PRODUCT_COLUMNS_META.find((m) => m.key === item.key);
        if (meta) cols.push(meta);
      });
      return cols;
    },
    visibleColumnKey() {
      return this.visibleDataColumns.map((c) => c.key).join(",");
    },
    batchOptions() {
      return allOptions.filter((item) => {
        return item.value.includes(this.formValidate.type);
      });
    },
  },
  mounted() {
	this.formValidate.type = this.$route.query.type || '1'
    this.product_category_status = localStorage.getItem('product_category_status') || 0;
    this.goodsCategory(0);
    this.goodsCategory(1);
    this.getHeader();
    this.getErpConfig();
    this.getDeliveryConfig();
    this.getStoreInfo();
    this.loadColumnSetting();
  },
  methods: {
    ...mapMutations('store/user', ['setStoreCateList']),
    formatTime(value) {
      if (!value) return "";
      const num = Number(value);
      if (!num) return value;
      const ms = num > 1e12 ? num : num * 1000;
      const d = new Date(ms);
      if (Number.isNaN(d.getTime())) return "";
      const pad = (n) => (n < 10 ? "0" + n : "" + n);
      return (
        d.getFullYear() +
        "-" +
        pad(d.getMonth() + 1) +
        "-" +
        pad(d.getDate()) +
        " " +
        pad(d.getHours()) +
        ":" +
        pad(d.getMinutes())
      );
    },
    formatBrand(row) {
      if (Array.isArray(row.brand_name) && row.brand_name.length) {
        return row.brand_name.map((b) => b.brand_name || b.label || b.name).filter(Boolean).join(",");
      }
      return row.brand_com || "";
    },
    formatStoreLabel(storeLabelId) {
      if (!Array.isArray(storeLabelId)) return "";
      return storeLabelId.map((item) => item.label_name).filter(Boolean).join(",");
    },
    loadColumnSetting() {
      getStaffColumnSetting({ table_key: "product_list_store" })
        .then((res) => {
          const columns = res.data && res.data.columns;
          if (Array.isArray(columns) && columns.length) {
            this.columnConfig = this.mergeColumnConfig(columns);
          }
        })
        .catch(() => {});
    },
    mergeColumnConfig(saved) {
      const keySet = new Set(saved.map((c) => c.key));
      const merged = saved
        .filter((item) => item.key !== "stock" && PRODUCT_COLUMNS_META.some((m) => m.key === item.key))
        .map((item) => {
          const meta = PRODUCT_COLUMNS_META.find((m) => m.key === item.key) || {};
          return {
            key: item.key,
            show: meta.fixed ? true : item.show !== false,
          };
        });
      PRODUCT_DEFAULT_COLUMN_CONFIG.forEach((item) => {
        if (item.key === "stock") return;
        if (!keySet.has(item.key)) {
          merged.push({ ...item });
        }
      });
      return merged;
    },
    saveColumnConfig(columns) {
      saveStaffColumnSetting({
        table_key: "product_list_store",
        columns,
      })
        .then((res) => {
          this.$Message.success(res.msg || "保存成功");
          this.columnConfig = columns;
          this.$nextTick(() => {
            if (this.$refs.xTable && this.$refs.xTable.refreshColumn) {
              this.$refs.xTable.refreshColumn();
            }
          });
        })
        .catch((err) => {
          this.$Message.error(err.msg || "保存失败");
        });
    },
	changeMenu(row, name, rowIndex){
		switch (name) {
		  case "1":
		    this.reply(row.id);
		    break;
		  case "2":
		    this.stockControl(row);
		    break;
		  case "3":
		    this.del(row,rowIndex);
		    break;
		  case "4":
		    this.copy(row);
		    break;
		  case "5":
        this.modal1Title = '门店商品分类';
		    this.modal1Field = 'store_cate_id';
		    this.openModal1(row);
		    break;
		  case "6":
		    this.trueDel(row, '删除商品', rowIndex);
		    break;
		  case "7":
		    this.goodsId = row.id;
		    this.$refs.adjustPrice.modal = true;
			this.$refs.adjustPrice.productAttrs(row);
		    break;
      case "8":
		    this.modal1Title = '显示设置';
		    this.modal1Field = 'show_type';
        this.productObtainData(row);
        this.openModal1(row);
		    break;
      case "9":
		    this.modal1Title = '配送方式';
		    this.modal1Field = 'delivery_type';
        this.productObtainData(row);
        this.openModal1(row);
		    break;
		}
	},
    //批量设置
    openBatch() {
      this.isLabel = 0;
	  this.$refs.batch.checkUidList = this.checkUidList;
	  this.$refs.batch.isAll = this.isAll;
	  this.$refs.batch.formValidate = this.formValidate;
      this.$refs.batch.batchModal = true;
    },
    checkboxItem(e){
      let id = parseInt(e.rowid);
      let index = this.checkUidList.indexOf(id);
      if(index !== -1){
        this.checkUidList = this.checkUidList.filter((item)=> item !== id);
      }else{
        this.checkUidList.push(id);
      }
    },
    checkboxAll(){
      // 获取选中当前值
      let obj2 = this.$refs.xTable.getCheckboxRecords(true);
      // 获取之前选中值
      let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
	  if(this.isAll == 0 && this.checkUidList.length <= obj.length && !this.isCheckBox){
		  obj = [];
	  }
      obj = obj.concat(obj2);
      let ids = [];
      obj.forEach((item)=>{
        ids.push(parseInt(item.id))
      })
      this.checkUidList = ids;
      if(!obj2.length){
        this.isCheckBox = false;
      }
    },
    allPages(e){
      this.isAll = e;
      if(e==0){
        this.$refs.xTable.toggleAllCheckboxRow();
        // this.checkboxAll();
      }else{
        if(!this.isCheckBox){
          this.$refs.xTable.setAllCheckboxRow(true);
          this.isCheckBox = true;
          this.isAll = 1;
        }else{
          this.$refs.xTable.setAllCheckboxRow(false);
          this.isCheckBox = false;
          this.isAll = 0;
        }
        this.checkUidList = []
      }
    },
	allReset(){
		this.isAll = 0;
		this.isCheckBox = false;
		this.$refs.xTable.setAllCheckboxRow(false);
		this.checkUidList = [];
	},
    // 批量上架
    onShelves() {
      if (this.isAll != 1 && this.checkUidList.length === 0) {
        this.$Message.warning("请选择要上架的商品");
      } else {
        let data = {
          all: this.isAll,
          ids: this.checkUidList
        };
        if (this.isAll == 1) {
          data.where = this.formValidate;
        }
        productShowApi(data)
            .then((res) => {
              this.$Message.success(res.msg);
              this.getHeader();
			  this.allReset();
            })
            .catch((res) => {
              this.$Message.error(res.msg);
            });
      }
    },
    // 批量下架
    onDismount() {
      if (this.isAll != 1 && this.checkUidList.length === 0) {
        this.$Message.warning("请选择要下架的商品");
      } else {
        let data = {
          all: this.isAll,
          ids:this.checkUidList
        };
        if (this.isAll == 1) {
          data.where = this.formValidate;
        }
        productUnshowApi(data)
            .then((res) => {
              this.$Message.success(res.msg);
              this.getHeader();
			  this.allReset();
            })
            .catch((res) => {
              this.$Message.error(res.msg);
            });
      }
    },
    //erp配置
    getErpConfig(){
      erpConfig().then(res=>{
        this.openErp = res.data.open_erp;
        this.product_status = res.data.product_status;
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
	priceChange(price){
		this.orderList.forEach((item) => {
		  if (this.goodsId == item.id) {
		    item.price = price;
		  }
		});
	},
    stockChange(stock) {
      this.orderList.forEach((item) => {
        if (this.goodsId == item.id) {
          item.branch_stock = stock;
        }
      });
    },
    // 库存管理
    stockControl(row) {
      this.goodsId = row.id;
	  this.goodsType = row.product_type;
      this.$refs.stock.modals = true;
      this.$refs.stock.productAttrs(row);
    },
    // 上下架
    changeSwitch(row) {
      setShowApi(row.id, row.is_show)
          .then((res) => {
            this.$Message.success(res.msg);
            this.getHeader();
			this.allReset();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
    },
    //获取列表
    getList() {
      this.loading = true;
      productListInfo(this.formValidate).then((res) => {
        this.orderList = res.data.list;
        this.total = res.data.count;
		this.changePriceStatus = res.data.product_change_price_status;
        this.loading = false;
        this.$nextTick(function(){
          if (this.isAll == 1) {
            if(this.isCheckBox){
              this.$refs.xTable.setAllCheckboxRow(true);
            }else{
              this.$refs.xTable.setAllCheckboxRow(false);
            }
          }else{
			let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
            if(!this.checkUidList.length || this.checkUidList.length <= obj.length){
              this.$refs.xTable.setAllCheckboxRow(false);
            }
          }
        })
      }).catch((err)=>{
        this.loading = false;
        this.$Message.error(err.msg);
      })
    },
    //头部列表
    getHeader() {
      this.loading = true;
      productHeaderInfo(this.formValidate).then((res) => {
        this.headerList = res.data.list;
        this.getList();
      });
    },
    // 商品分类；
    goodsCategory(type) {
      cascaderList(type)
          .then((res) => {
            if (type) {
              this.storeCateData = res.data;
              this.setStoreCateList(this.storeCateData);
            } else {
              this.cateData = res.data;
            }
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
    },
    //详情
    detail(id) {
      this.detailsVisible = true;
      this.productId = id;
    },
    // 编辑
    edit(row) {
      this.$router.push({ path: Setting.routePre+"/product/edit_product/" + row.id });
    },
    // 编辑
    reply(id) {
      this.$router.push({ path: Setting.routePre+'/product/product_reply?id=' + id });
    },
    // 删除
    del(row,num) {
      let delfromData = {
        title: row.is_del ? '恢复商品' : '移入回收站',
        num: num,
        url: `product/product/${row.id}`,
        method: "DELETE",
        ids: "",
        tips:  row.is_del ? '确定恢复商品吗' : '确定移入回收站吗',
      };
      this.$modalSure(delfromData)
          .then((res) => {
            this.$Message.success(res.msg);
            this.orderList.splice(num, 1);
            this.getHeader();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
    },
    // 复制
    copy(row) {
      this.$router.push({ path: `${Setting.routePre}/product/edit_product/`, query: { copy: row.id } });
    },
    //搜索
    search() {
      this.allReset();
      this.formValidate.page = 1;
      this.getHeader();
    },
    //重置
    reset() {
      this.formValidate.page = 1;
	  this.formValidate.show_type = '';
      this.formValidate.store_name = "";
      this.formValidate.cate_id = "";
      this.formValidate.store_cate_id = "";
	  this.formValidate.product_type = "";
	  this.formValidate.is_inventory = "";
	  this.formValidate.allow_negative_stock = "";
	  this.formValidate.type = "1";
      this.getHeader();
    },
    //切换头部列表
    onClickTab(e) {
      this.allReset();
      this.formValidate.type = e;
      this.formValidate.page = 1;
	  this.getHeader();
    },
    //分页
    pageChange(page) {
      this.formValidate.page = page.currentPage;
      this.getHeader();
    },
    // 真删除商品
    trueDel(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num, 
        url: `product/product/del/${row.id}`,
        method: "DELETE",
        ids: "",
        tips: `确定要删除商品吗？`,
      };
      this.$modalSure(delfromData)
          .then((res) => {
            this.$Message.success(res.msg);
            this.orderList.splice(num, 1);
            this.getHeader();
            this.allReset();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
    },
    // 打开弹窗
    openModal1(row) {
      this.currentProduct = row;
      if (this.modal1Field == 'store_cate_id') {
        this.formItem.store_cate_id = row.store_cate_id ? row.store_cate_id.split(',') : [];
      }
      this.modal1 = true;
    },
    // 保存修改的分类
    submitProductCate() {
      setProductCate(this.currentProduct.id, this.formItem).then(res => {
        this.$Message.success(res.msg);
        this.currentProduct = {};
        this.modal1 = false;
        this.getHeader();
      });
    },
    productTypeMenu() {
      this.addTypeShow = false;
      this.$router.push({
        path: `${this.routePre}/product/edit_product`,
        query: { productType: this.product_type },
      });
      this.product_type = 0;
    },
    productTypeTap(num, item) {
      this.product_type = item.id;
    },
    // 商品迁移
    goodsMove(val) {
      if (val === 1) {
        this.importShow = true;
      } else {
        this.exports(1);
      }
    },
    // 数据导出；
    async exports() {
      if (!this.checkUidList.length && !this.isAll)
        return this.$Message.error("本页至少选中一项");
      let [th, filekey, data, fileName] = [[], [], [], ""];
      let excelData = {
        store_name: this.formValidate.store_name,
        cate_id: this.formValidate.cate_id,
        type: this.formValidate.type,
        ids: this.checkUidList.join(),
      };
      if (this.isAll == 1) {
        Object.assign(excelData, this.formValidate);
        excelData.all = 1;
        delete excelData.limit;
      }
      excelData.page = 1;
      for (let i = 0; i < excelData.page; i++) {
        let lebData = await this.getExportData(excelData);
        if (!fileName) {
          fileName = lebData.filename;
        }
        if (!filekey.length) {
          filekey = lebData.filekey;
        }
        if (!th.length) {
          th = lebData.header;
        }
        if (lebData.export.length) {
          data = data.concat(lebData.export);
          excelData.page++;
        }
      }
      exportExcel(th, filekey, fileName, data);
    },
    getExportData(excelData) {
      return new Promise((resolve, reject) => {
        productExportApi(excelData).then((res) => {
          return resolve(res.data);
        });
      });
    },
    batchHandler(type) {
      if (type == 1) {
        // 批量上架
        this.onShelves();
      } else if (type == 2) {
        // 批量下架
        this.onDismount();
      } else if (type == 4) {
        // 移入回收站
        let data = {
          data: {
            is_del: 1,
          },
          type: 11,
          ids: this.checkUidList,
          all: this.isAll,
        };
        if (this.isAll == 1) {
          data.where = this.formValidate;
        }
        batchProcess(data)
          .then((res) => {
            this.$Message.success(res.msg);
            this.getDataList();
            this.allReset();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
      } else if (type == 5) {
        // 恢复商品
        let data = {
          data: {
            is_del: 0,
          },
          type: 11,
          ids: this.checkUidList,
          all: this.isAll,
        };
        if (this.isAll == 1) {
          data.where = this.formValidate;
        }
        batchProcess(data)
          .then((res) => {
            this.$Message.success(res.msg);
            this.getDataList();
            this.allReset();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
      } else if (type == 6) {
        // 删除商品
        let data = {
          type: 12,
          ids: this.checkUidList,
          all: this.isAll,
        };
        if (this.isAll == 1) {
          data.where = this.formValidate;
        }
        batchProcess(data)
          .then((res) => {
            this.$Message.success(res.msg);
            this.getDataList();
            this.allReset();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
      }
    },
    productObtainData(row) {
      productObtainDataApi(row.id).then((res) => {
        this.modifyData = res.data;
        if (this.modal1Field == 'show_type') {
          this.formItem[this.modal1Field] = this.modifyData[this.modal1Field];
        } else {
          this.formItem.delivery_type = [];
          const deliveryType = this.modifyData.delivery_type;
          const storeDeliveryType = this.modifyData.store_delivery_type;
          if (deliveryType.includes('3') && storeDeliveryType.includes('1') && this.deliveryType.includes('1')) {
            this.formItem.delivery_type.push('1');
          }
          if (deliveryType.includes('2') && this.deliveryType.includes('2')) {
            this.formItem.delivery_type.push('2');
          }
          if (deliveryType.includes('3') && storeDeliveryType.includes('2') && this.deliveryType.includes('3')) {
            this.formItem.delivery_type.push('3');
          }
        }
      });
    },
    // 修改商品展示、配送方式
    productModifyData() {
      const data = {
        field: this.modal1Field,
        product_type: this.currentProduct.product_type,
      };
      data[this.modal1Field] = this.formItem[this.modal1Field];
      if (this.modal1Field == 'delivery_type') {
        data.show_type = this.formItem.show_type;
        if (!data.delivery_type.length) {
          return this.$Message.warning('请选择配送方式');
        }
      } else {
        data.delivery_type = this.formItem.delivery_type;
      }
      productModifyDataApi(this.currentProduct.id, data).then((res) => {
        this.$Message.success(res.msg);
        this.currentProduct = {};
        this.modifyData = {};
        this.modal1Title = '';
        this.modal1Field = '';
        this.modal1 = false;
      }).catch((res) => {
        this.$Message.error(res.msg);
      });
    },
    onModal1Ok() {
      switch (this.modal1Field) {
        case 'store_cate_id':
          this.submitProductCate();
          break;
        case 'show_type':
        case 'delivery_type':
          this.productModifyData();
          break;
      }
    },
    // 关闭弹窗
    onModal1Cancel() {
      this.currentProduct = {};
      this.modal1 = false;
    },
    getDeliveryConfig() {
      deliveryConfigApi().then((res) => {
        this.cityDeliveryStatus = Number(res.data.city_delivery_status);
      });
    },
    getStoreInfo() {
      storeGetInfoApi()
        .then((res) => {
          this.deliveryType = res.data.delivery_type;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
  },
};
</script>

<style scoped lang="less">
/deep/.el-cascader {
	.el-input__inner {
	  font-size: 12px;
	  min-height: 32px !important;
	}
	.el-input{
		.el-icon-arrow-down{
			font-size: 12px;
			color: #000;
		}
	}
	.el-cascader__search-input{
		margin-left: 7px !important;
	}
}
/deep/.ivu-page-header,
/deep/.ivu-tabs-bar {
  margin-bottom: 0px !important;
  border-bottom: 1px solid #E9E9E9;
}

/deep/.ivu-card-body {
  // padding: 14px 20px 0 20px !important;
}


/deep/.ivu-tabs-nav {
  height: 45px;
}

.bg {
  z-index: 100;
  position: fixed;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
}

.box {
  padding: 20px;
  padding-bottom: 0px;
}

.tablebox {
  margin-top: 15px;
}

.btnbox {
  padding: 20px 0px 0px 30px;

  .btns {
    width: 99px;
    height: 32px;
    background: #1890ff;
    border-radius: 4px;
    text-align: center;
    line-height: 32px;
    color: #ffffff;
    cursor: pointer;
  }
}
.ivu-table {
  background-color: #182328;
  color: #fff;
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

.product-name-cell {
  white-space: normal;
  word-break: break-all;
  line-height: 1.4;
  padding: 4px 0;
}

.search {
  width: 86px;
  height: 32px;
  background: #1890ff;
  border-radius: 4px;
  text-align: center;
  line-height: 32px;
  font-size: 13px;
  font-family: PingFangSC-Regular, PingFang SC;
  font-weight: 400;
  color: #ffffff;
  cursor: pointer;
  display: inline-block;
  vertical-align: middle;
}

.reset {
  width: 86px;
  height: 32px;
  border-radius: 4px;
  border: 1px solid rgba(151, 151, 151, 0.36);
  text-align: center;
  line-height: 32px;
  font-size: 13px;
  font-family: PingFangSC-Regular, PingFang SC;
  font-weight: 400;
  font-weight: 500;
  color: #515a6e;
  display: inline-block;
  cursor: pointer;
  vertical-align: middle;
}

.productType {
  width: 120px;
  height: 60px;
  background: #FFFFFF;
  border-radius: 3px;
  border: 1px solid #E7E7E7;
  text-align: center;
  padding-top: 8px;
  position: relative;
  cursor: pointer;
  line-height: 23px;

  &.on{
	  border-color: #1890FF;
  }

  .name {
    font-size: 14px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.85);
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
.product-type-wrap {
  margin: 0 -12px -12px 0;
}
.mb-12{
  margin-bottom: 12px;
}
.mr-12{
  margin-right: 12px;
}
.mt-30{
  margin-top: 30px;
}
.ml-14{
  margin-left: 14px;
}
</style>
